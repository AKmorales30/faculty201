<?php
/**
 * PDS version history pop-up (api/modal.php?view=pds_versions&user=N):
 * every version of one person's PDS, newest first -- number, date and time
 * (Asia/Manila), the sections it changed, how (source) and by whom. "View"
 * opens what the PDS looked like then (faculty/pds_print.php?version=ID,
 * read-only) and the uploaded file behind it can be previewed -- both only
 * for the owner and the Admin (pds_can_view_version_content(),
 * can_access_document()). Program Chairs / Deans see the list for people in
 * their scope. Access is checked by api/modal.php.
 *
 * Expects $pdo, $me and $owner (users row).
 */
$versions = pds_versions($pdo, (int)$owner['user_id']);
$can_open = pds_can_view_version_content($me, (int)$owner['user_id']);
?>
<div data-modal-title="PDS version history — <?= h($owner['full_name']) ?>"></div>
<?php if (!$versions): ?>
  <?= admin_empty('No versions yet', 'Each change to the PDS is saved as a new version here.', null, 'fa-clock-rotate-left') ?>
<?php else: ?>
<p class="ui-cell-sub mb-3">Every change to the PDS is kept as a version. Undoing an update adds a new version; none is deleted.<?= $can_open ? '' : ' The contents of each version can be opened by its owner and the Admin.' ?></p>
<div class="table-responsive">
  <table class="table align-middle">
    <thead><tr><th>Version</th><th>Date and time</th><th>Changed sections</th><th>How</th><th>Updated by</th></tr></thead>
    <tbody>
      <?php foreach ($versions as $i => $v): ?>
      <tr>
        <td class="text-nowrap">
          <div class="fw-semibold">Version <?= (int)$v['version_number'] ?><?php if ($i === 0): ?> <span class="badge bg-success">Current</span><?php endif; ?><?php if ($v['undone_at']): ?> <span class="badge bg-secondary">Undone</span><?php endif; ?></div>
          <?php if ($can_open): ?>
          <div class="ui-actions justify-content-start mt-1">
            <?= admin_icon_link(BASE_URL . '/faculty/pds_print.php?version=' . (int)$v['version_id'], 'fa-eye', 'View this version (read-only)', 'target="_blank"') ?>
            <?php if ($v['source_document_id'] && in_array($v['source_document_status'], ['active', 'archived', 'deleted'], true)
                      && can_access_document($me, ['faculty_id' => $v['user_id'], 'status' => $v['source_document_status']])): ?>
              <?= admin_icon_link(document_url((int)$v['source_document_id']), 'fa-file-lines', 'Preview the uploaded file', 'target="_blank"') ?>
            <?php endif; ?>
          </div>
          <?php endif; ?>
        </td>
        <td class="text-nowrap"><?= h(pds_version_date($v['created_at'])) ?></td>
        <td style="min-width:10rem"><?= $v['source'] === 'initial' ? '<span class="text-muted">Whole PDS</span>' : h(pds_sections_text($v['changed_sections'])) ?></td>
        <td><?= h(pds_version_source_label($v)) ?></td>
        <td><?= $v['changed_by_name'] ? h($v['changed_by_name']) : '<span class="text-muted">—</span>' ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>
