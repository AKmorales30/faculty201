<?php
/**
 * Delete / archive / restore controls for document lists. Include once
 * (include_once) before the list: it defines document_action_buttons()
 * and outputs the confirmation dialog the buttons open. The form posts
 * to document_action.php, which makes every permission check again.
 *
 * Uses current_user() for the viewer's role.
 */

/** Where document_action.php sends the user back to: this page (path from the app root), with its filters. */
$doc_action_return = ltrim(str_replace('\\', '/', substr((string)realpath($_SERVER['SCRIPT_FILENAME']), strlen((string)realpath(ROOT_PATH)))), '/')
                   . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '');

/**
 * Buttons for one document row: for the Admin, archive / delete / restore
 * according to its status; for its owner, Delete while the delete window
 * is open, otherwise a note to contact the Admin. $doc needs document_id,
 * faculty_id, status, file_path, document_type, document_subtype and, for
 * the owner, delete_seconds_left (delete_window_sql()).
 */
function document_action_buttons(array $doc, array $me): string {
    $name = document_display_name($doc['file_path']) . ' (' . document_type_label($doc['document_type'], $doc['document_subtype']) . ')'
          . (!empty($doc['full_name']) && $me['role'] === 'admin' ? ' of ' . $doc['full_name'] : '');
    $button = fn(string $action, string $class, string $icon, string $label) =>
        '<button type="button" class="btn btn-sm ' . $class . ' doc-action-btn text-nowrap" data-bs-toggle="modal" data-bs-target="#docActionModal"'
        . ' data-id="' . (int)$doc['document_id'] . '" data-action="' . $action . '" data-name="' . h($name) . '" title="' . h($label) . '">'
        . '<i class="fa-solid ' . $icon . '"></i> ' . h($label) . '</button>';

    if ($me['role'] === 'admin') {
        $out = [];
        if ($doc['status'] !== 'active')  { $out[] = $button('restore', 'btn-outline-success', 'fa-rotate-left', 'Restore'); }
        if ($doc['status'] === 'active')  { $out[] = $button('archive', 'btn-outline-secondary', 'fa-box-archive', 'Archive'); }
        if ($doc['status'] !== 'deleted') { $out[] = $button('delete', 'btn-outline-danger', 'fa-trash-can', 'Delete'); }
        return implode(' ', $out);
    }
    if ((int)$doc['faculty_id'] !== (int)$me['user_id']) {
        return '';
    }
    if (faculty_can_delete($me, $doc)) {
        return $button('delete', 'btn-outline-danger', 'fa-trash-can', 'Delete')
             . '<div class="small text-muted mt-1">Until ' . h(delete_deadline_label((int)$doc['delete_seconds_left'])) . '</div>';
    }
    return '<div class="small text-muted" style="max-width:11rem">Contact the Admin to remove this document.</div>';
}
?>
<div class="modal fade" id="docActionModal" tabindex="-1" aria-labelledby="docActionTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form method="POST" action="<?= BASE_URL ?>/document_action.php" class="modal-content" id="docActionForm">
      <?= csrf_field() ?>
      <input type="hidden" name="document_id" id="docActionId">
      <input type="hidden" name="action" id="docActionAction">
      <input type="hidden" name="return" value="<?= h($doc_action_return) ?>">
      <div class="modal-header">
        <h5 class="modal-title" id="docActionTitle">Confirm</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p class="fw-semibold mb-1 text-break" id="docActionName"></p>
        <p class="small text-muted" id="docActionText"></p>
        <div id="docActionReason">
          <label for="docActionReasonSel" class="form-label small fw-semibold">Reason <span id="docActionReasonHint" class="text-muted fw-normal"></span></label>
          <select name="reason" id="docActionReasonSel" class="form-select mb-2" aria-label="Reason">
            <option value="">-- Select a reason --</option>
            <?php foreach (document_removal_reasons() as $r): ?>
              <option value="<?= h($r) ?>"><?= h($r) ?></option>
            <?php endforeach; ?>
          </select>
          <textarea name="reason_note" id="docActionNote" class="form-control form-control-sm" rows="2" maxlength="200" placeholder="Details (optional)" aria-label="Details"></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn" id="docActionSubmit">Confirm</button>
      </div>
    </form>
  </div>
</div>
<script>
(function () {
  var IS_ADMIN = <?= current_user()['role'] === 'admin' ? 'true' : 'false' ?>;
  var TEXT = {
    delete:  IS_ADMIN
      ? ['Delete document', 'It will be removed from the faculty member\'s 201 file and from searches, reports and alerts. The file is kept, so it can still be restored from Archived Documents.', 'btn-danger', 'Delete']
      : ['Delete document', 'It will be removed from your 201 file. This can\'t be undone by you -- the Admin would have to restore it.', 'btn-danger', 'Delete'],
    archive: ['Archive document', 'It will move to the faculty member\'s archive (they can still view it there) and no longer appear in their active 201 file, searches, reports or expiration alerts. You can restore it later.', 'btn-secondary', 'Archive'],
    restore: ['Restore document', 'It will appear in the faculty member\'s active 201 file again. If it is still more than <?= (int)ARCHIVE_AFTER_YEARS ?> years old, it is kept out of the automatic archive from now on.', 'btn-success', 'Restore']
  };
  var modal = document.getElementById('docActionModal');
  modal.addEventListener('show.bs.modal', function (e) {
    var btn = e.relatedTarget, action = btn.dataset.action, t = TEXT[action];
    var reasonRequired = IS_ADMIN && action !== 'restore';
    var noteRequired = IS_ADMIN && action === 'restore';   // restoring: a written reason instead of the list
    document.getElementById('docActionId').value = btn.dataset.id;
    document.getElementById('docActionAction').value = action;
    document.getElementById('docActionTitle').textContent = t[0];
    document.getElementById('docActionName').textContent = btn.dataset.name;
    document.getElementById('docActionText').textContent = t[1];
    document.getElementById('docActionReason').hidden = action === 'restore' && !IS_ADMIN;
    var sel = document.getElementById('docActionReasonSel'), note = document.getElementById('docActionNote');
    sel.hidden = action === 'restore';
    sel.required = reasonRequired;
    sel.value = '';
    note.value = '';
    note.required = noteRequired;
    note.placeholder = noteRequired ? 'Why is this document being restored?' : 'Details (optional)';
    document.getElementById('docActionReasonHint').textContent = reasonRequired || noteRequired ? '(required)' : '(optional)';
    var submit = document.getElementById('docActionSubmit');
    submit.className = 'btn ' + t[2];
    submit.textContent = t[3];
    submit.disabled = false;
  });
  document.getElementById('docActionForm').addEventListener('submit', function () {
    document.getElementById('docActionSubmit').disabled = true;   // no double submit
  });
})();
</script>
