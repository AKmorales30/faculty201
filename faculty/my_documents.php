<?php
/**
 * 201-Files -- a faculty member's (or Program Chair's / Dean's) own 201
 * file, in three tabs:
 *   Posted          every document in their 201 file, by category
 *                   (includes/document_browser.php) -- uploads are posted
 *                   right away
 *   Flagged         their uploads the system flagged for the Admin to check
 *                   (low confidence, no text read, entered manually). They
 *                   are in Posted too; a flag never hides a document.
 *   Upload history  every upload (faculty/my_requests.php)
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_role(['faculty', 'program_chair', 'dean']);   // own 201 file only

$me = current_user();
$tab = in_array($_GET['tab'] ?? '', ['flagged', 'pending'], true) ? 'flagged' : 'posted';   // 'pending': old links
$page_title = '201-Files';
$stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
$stmt->execute([$me['user_id']]);
$faculty = $stmt->fetch();
$link_base = '?';

if ($tab === 'flagged') {
    $stmt = $pdo->prepare(
        "SELECT d.*, " . delete_window_sql('d') . " AS delete_seconds_left, ? AS full_name
         FROM documents d WHERE d.faculty_id = ? AND " . document_flagged_sql('d') . " ORDER BY d.filed_at DESC"
    );
    $stmt->execute([$faculty['full_name'], $me['user_id']]);
    $flagged = $stmt->fetchAll();
}

include __DIR__ . '/../includes/header.php';
?>

<?= admin_intro($tab === 'posted'
      ? 'Every document in your own 201 file, by category. Uploads are saved here right away. You can delete a wrong upload yourself within ' . (int)FACULTY_DELETE_WINDOW_HOURS . ' hours; documents nobody opens in ' . h(archive_period_label()) . ' move to Archived Docs.'
      : 'Uploads the system flagged for the Admin to check — the type was detected with low confidence, no text could be read, or the details were entered manually. They are already in your 201-Files; the Admin may correct the type or details. You can still edit the details or delete a wrong upload within ' . (int)FACULTY_DELETE_WINDOW_HOURS . ' hours.',
    '<a href="' . BASE_URL . '/archive.php" class="btn btn-outline-brand"><i class="fa-solid fa-box-archive" aria-hidden="true"></i> Archived Docs</a>'
  . '<a href="' . BASE_URL . '/faculty/submit_document.php" class="btn btn-brand"><i class="fa-solid fa-file-circle-plus" aria-hidden="true"></i> Upload Document</a>') ?>

<?= my_docs_tabs($pdo, $tab) ?>

<?php if ($tab === 'posted'): ?>

  <?php include __DIR__ . '/../includes/document_browser.php'; ?>

<?php else: include_once __DIR__ . '/../includes/document_actions.php'; ?>
  <section class="ui-card" aria-label="Flagged for checking">
    <div class="table-responsive">
      <table class="table align-middle">
        <thead><tr><th>Document</th><th>Type</th><th>Uploaded</th><th>Status</th><th class="text-end"><span class="visually-hidden">Actions</span></th></tr></thead>
        <tbody>
          <?php if (!$flagged): ?>
            <?= admin_empty_row(5, 'Nothing is flagged', 'None of your uploads needs checking.', null, 'fa-circle-check') ?>
          <?php endif; ?>
          <?php foreach ($flagged as $d): $view_url = BASE_URL . '/api/modal.php?view=document&id=' . (int)$d['document_id']; ?>
            <tr class="row-clickable" tabindex="0" role="button" data-app-modal="<?= h($view_url) ?>" aria-label="Open <?= h(document_title($d)) ?>">
              <td class="text-break">
                <div class="fw-semibold"><?= h(document_title($d)) ?></div>
                <?php if (trim((string)$d['title']) !== ''): ?><div class="ui-cell-sub"><?= h(document_display_name($d['file_path'])) ?></div><?php endif; ?>
              </td>
              <td><?= h(document_type_label($d['document_type'], $d['document_subtype'])) ?><?php if ($p = document_period_label($d)): ?><div class="ui-cell-sub"><?= h($p) ?></div><?php endif; ?></td>
              <td class="text-nowrap"><?= h(date('M j, Y', strtotime($d['filed_at']))) ?><div class="ui-cell-sub"><?= h(date('g:i A', strtotime($d['filed_at']))) ?></div></td>
              <td><span class="badge bg-success">Posted</span> <span class="badge bg-review" title="<?= h(ucfirst(document_flag_text($d['flag_reason']))) ?>">Flagged</span><div class="ui-cell-sub"><?= h(implode(' · ', document_flag_labels($d['flag_reason']))) ?></div></td>
              <td>
                <div class="ui-actions">
                  <?= admin_icon_link(document_url((int)$d['document_id']), 'fa-eye', 'View file', 'target="_blank" data-app-modal="' . h($view_url) . '"') ?>
                  <?= admin_icon_link(BASE_URL . '/document_details.php?id=' . (int)$d['document_id'] . '&return=' . urlencode('faculty/my_documents.php?tab=flagged'), 'fa-pen-to-square', 'Edit details') ?>
                  <?= document_action_buttons($d, $me) ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
