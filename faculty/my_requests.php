<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_role(['faculty', 'program_chair', 'dean']);   // own 201 file only

$page_title = 'Upload History';   // the Upload history tab of Public Docs
$me = current_user();
$categories = document_categories();

$stmt = $pdo->prepare(
    "SELECT sr.*, d.document_id, d.file_path, d.title, d.document_type, d.document_subtype, d.status AS document_status FROM submission_requests sr
     LEFT JOIN documents d ON d.request_id = sr.request_id
     WHERE sr.faculty_id=? ORDER BY sr.submitted_at DESC"
);
$stmt->execute([$me['user_id']]);
$requests = $stmt->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
  <h3 class="fw-bold mb-0">Public Docs</h3>
  <a href="<?= BASE_URL ?>/faculty/submit_document.php" class="btn btn-brand btn-sm">
    <i class="fa-solid fa-file-circle-plus"></i> Upload Document
  </a>
</div>

<?= my_docs_tabs($pdo, 'history') ?>
<p class="text-muted mb-4">Every document you've uploaded, newest first, with where it is now: For Review, Posted, Archived or Deleted.</p>

<div class="card stat-card">
  <div class="card-body p-0 table-responsive">
    <table class="table mb-0 align-middle">
      <thead class="table-light">
        <tr><th>Document</th><th>Document Type</th><th>Status</th><th>Uploaded</th><th></th></tr>
      </thead>
      <tbody>
        <?php if (!$requests): ?>
          <tr><td colspan="5" class="text-center text-muted py-4">You haven't uploaded any documents yet.</td></tr>
        <?php endif; ?>
        <?php foreach ($requests as $r): [$label, $badge] = upload_status_badge($r); ?>
        <tr>
          <td class="text-break small fw-semibold"><?= $r['document_id'] ? h(document_title($r)) : '<span class="text-muted">—</span>' ?></td>
          <td><?= h(document_type_label($r['document_type'] ?? $r['document_type_hint'], $r['document_subtype'])) ?></td>
          <td>
            <span class="badge <?= $badge ?>"><?= h($label) ?></span>
            <?php if ($r['status'] === 'rejected'): ?>
              <div class="small text-danger mt-1"><?= h($r['rejection_reason']) ?></div>
            <?php endif; ?>
          </td>
          <td><?= date('M j, Y g:ia', strtotime($r['submitted_at'])) ?></td>
          <td class="text-nowrap">
            <?php if ($r['file_path'] && in_array($r['document_status'], ['active', 'for_review', 'archived'], true)): ?>
              <a href="<?= h(document_url((int)$r['document_id'])) ?>" target="_blank" class="btn btn-sm btn-outline-brand"
                 data-app-modal="<?= h(BASE_URL . '/api/modal.php?view=document&id=' . (int)$r['document_id']) ?>"><i class="fa-solid fa-eye"></i> View</a>
            <?php elseif ($r['document_status'] === 'deleted'): ?>
              <a href="<?= BASE_URL ?>/recently_deleted.php" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-trash-can-arrow-up"></i> Recently Deleted</a>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
