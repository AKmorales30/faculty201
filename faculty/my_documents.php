<?php
/**
 * 201-Files -- a faculty member's (or Program Chair's / Dean's) own 201
 * file, in three tabs:
 *   Posted          documents the Admin reviewed and posted, by category
 *                   (includes/document_browser.php)
 *   Pending review  their uploads still For Review in Manage Document
 *   Upload history  every upload (faculty/my_requests.php)
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_role(['faculty', 'program_chair', 'dean']);   // own 201 file only

$me = current_user();
$tab = ($_GET['tab'] ?? '') === 'pending' ? 'pending' : 'posted';
$page_title = '201-Files';
$stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
$stmt->execute([$me['user_id']]);
$faculty = $stmt->fetch();
$link_base = '?';

if ($tab === 'pending') {
    $stmt = $pdo->prepare(
        "SELECT d.*, " . delete_window_sql('d') . " AS delete_seconds_left, ? AS full_name
         FROM documents d WHERE d.faculty_id = ? AND d.status = 'for_review' ORDER BY d.filed_at DESC"
    );
    $stmt->execute([$faculty['full_name'], $me['user_id']]);
    $pending = $stmt->fetchAll();
}

include __DIR__ . '/../includes/header.php';
?>

<?= admin_intro($tab === 'posted'
      ? 'Documents in your own 201 file that the Admin reviewed and posted, by category. New uploads wait under <a href="?tab=pending">Pending review</a> until the Admin posts them. You can delete a wrong upload yourself within ' . (int)FACULTY_DELETE_WINDOW_HOURS . ' hours; documents nobody opens in ' . h(archive_period_label()) . ' move to Archived Docs.'
      : 'Uploads waiting for the Admin\'s classification review. You\'re notified when each one is posted, and can still edit the details or delete a wrong upload within ' . (int)FACULTY_DELETE_WINDOW_HOURS . ' hours.',
    '<a href="' . BASE_URL . '/archive.php" class="btn btn-outline-brand"><i class="fa-solid fa-box-archive" aria-hidden="true"></i> Archived Docs</a>'
  . '<a href="' . BASE_URL . '/faculty/submit_document.php" class="btn btn-brand"><i class="fa-solid fa-file-circle-plus" aria-hidden="true"></i> Upload Document</a>') ?>

<?= my_docs_tabs($pdo, $tab) ?>

<?php if ($tab === 'posted'): ?>

  <?php include __DIR__ . '/../includes/document_browser.php'; ?>

<?php else: include_once __DIR__ . '/../includes/document_actions.php'; ?>
  <section class="ui-card" aria-label="Pending review">
    <div class="table-responsive">
      <table class="table align-middle">
        <thead><tr><th>Document</th><th>Type</th><th>Uploaded</th><th>Status</th><th class="text-end"><span class="visually-hidden">Actions</span></th></tr></thead>
        <tbody>
          <?php if (!$pending): ?>
            <?= admin_empty_row(5, 'Nothing is waiting for review', 'All your uploads have been posted.', null, 'fa-circle-check') ?>
          <?php endif; ?>
          <?php foreach ($pending as $d): $view_url = BASE_URL . '/api/modal.php?view=document&id=' . (int)$d['document_id']; ?>
            <tr class="row-clickable" tabindex="0" role="button" data-app-modal="<?= h($view_url) ?>" aria-label="Open <?= h(document_title($d)) ?>">
              <td class="text-break">
                <div class="fw-semibold"><?= h(document_title($d)) ?></div>
                <?php if (trim((string)$d['title']) !== ''): ?><div class="ui-cell-sub"><?= h(document_display_name($d['file_path'])) ?></div><?php endif; ?>
              </td>
              <td><?= h(document_type_label($d['document_type'], $d['document_subtype'])) ?><?php if ($p = document_period_label($d)): ?><div class="ui-cell-sub"><?= h($p) ?></div><?php endif; ?></td>
              <td class="text-nowrap"><?= h(date('M j, Y', strtotime($d['filed_at']))) ?><div class="ui-cell-sub"><?= h(date('g:i A', strtotime($d['filed_at']))) ?></div></td>
              <td><span class="badge bg-review">For Review</span><div class="ui-cell-sub">Waiting for the Admin</div></td>
              <td>
                <div class="ui-actions">
                  <?= admin_icon_link(document_url((int)$d['document_id']), 'fa-eye', 'View file', 'target="_blank" data-app-modal="' . h($view_url) . '"') ?>
                  <?= admin_icon_link(BASE_URL . '/document_details.php?id=' . (int)$d['document_id'] . '&return=' . urlencode('faculty/my_documents.php?tab=pending'), 'fa-pen-to-square', 'Edit details') ?>
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
