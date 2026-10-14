<?php
/**
 * Public Docs -- a faculty member's (or Program Chair's / Dean's) own 201
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
$page_title = 'Public Docs';
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

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
  <h3 class="fw-bold mb-0">Public Docs</h3>
  <div class="d-flex gap-2">
    <a href="<?= BASE_URL ?>/archive.php" class="btn btn-outline-brand btn-sm"><i class="fa-solid fa-box-archive"></i> Archived Docs</a>
    <a href="<?= BASE_URL ?>/faculty/submit_document.php" class="btn btn-brand btn-sm"><i class="fa-solid fa-file-circle-plus"></i> Upload Document</a>
  </div>
</div>

<?= my_docs_tabs($pdo, $tab) ?>

<?php if ($tab === 'posted'): ?>
  <p class="text-muted mb-4">Your documents that the Admin reviewed and posted, organized by category. The latest version of each is shown first; older versions are kept as history.
    New uploads appear here once posted -- until then they're under <a href="?tab=pending">Pending review</a>.
    Uploaded the wrong file or a duplicate? You can delete it yourself within <?= (int)FACULTY_DELETE_WINDOW_HOURS ?> hours of uploading; after that, contact the Admin.
    Documents nobody has opened in <?= h(archive_period_label()) ?> move to <a href="<?= BASE_URL ?>/archive.php">Archived Docs</a>.</p>

  <?php include __DIR__ . '/../includes/document_browser.php'; ?>

<?php else: include_once __DIR__ . '/../includes/document_actions.php'; ?>
  <p class="text-muted mb-4">Uploads waiting for the Admin's classification review: the Admin checks the detected document type and details, corrects them if needed, and posts them to your Public Docs.
    You're notified when each one is posted. You can still edit the details or delete a wrong upload within <?= (int)FACULTY_DELETE_WINDOW_HOURS ?> hours.</p>

  <div class="card stat-card">
    <div class="card-body p-0 table-responsive">
      <table class="table table-hover mb-0 align-middle">
        <thead class="table-light"><tr><th>Document</th><th>Type</th><th>Uploaded</th><th>Status</th><th></th></tr></thead>
        <tbody>
          <?php if (!$pending): ?>
            <tr><td colspan="5" class="text-center text-muted py-4">Nothing is waiting for review -- all your uploads have been posted.</td></tr>
          <?php endif; ?>
          <?php foreach ($pending as $d): ?>
            <tr>
              <td class="text-break small">
                <a href="<?= h(document_url((int)$d['document_id'])) ?>" target="_blank" class="fw-semibold"
                   data-app-modal="<?= h(BASE_URL . '/api/modal.php?view=document&id=' . (int)$d['document_id']) ?>"><?= h(document_title($d)) ?></a>
                <?php if (trim((string)$d['title']) !== ''): ?><div class="text-muted"><?= h(document_display_name($d['file_path'])) ?></div><?php endif; ?>
              </td>
              <td class="small"><?= h(document_type_label($d['document_type'], $d['document_subtype'])) ?><?php if ($p = document_period_label($d)): ?><div class="text-muted"><?= h($p) ?></div><?php endif; ?></td>
              <td class="small text-nowrap"><?= h(date('M j, Y g:ia', strtotime($d['filed_at']))) ?></td>
              <td><span class="badge bg-review">For Review</span></td>
              <td>
                <div class="d-flex flex-wrap gap-1 align-items-start">
                  <a href="<?= h(document_url((int)$d['document_id'])) ?>" target="_blank" class="btn btn-sm btn-outline-brand text-nowrap"
                     data-app-modal="<?= h(BASE_URL . '/api/modal.php?view=document&id=' . (int)$d['document_id']) ?>"><i class="fa-solid fa-eye"></i> View</a>
                  <a href="<?= BASE_URL ?>/document_details.php?id=<?= (int)$d['document_id'] ?>&amp;return=<?= h(urlencode('faculty/my_documents.php?tab=pending')) ?>" class="btn btn-sm btn-outline-brand text-nowrap"><i class="fa-solid fa-pen-to-square"></i> Details</a>
                  <?= document_action_buttons($d, $me) ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
