<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_role('faculty');

$page_title = 'Upload History';
$me = current_user();
$categories = document_categories();

$stmt = $pdo->prepare(
    "SELECT sr.*, d.file_path FROM submission_requests sr
     LEFT JOIN documents d ON d.request_id = sr.request_id
     WHERE sr.faculty_id=? ORDER BY sr.submitted_at DESC"
);
$stmt->execute([$me['user_id']]);
$requests = $stmt->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
  <h3 class="fw-bold mb-0">Upload History</h3>
  <a href="<?= BASE_URL ?>/faculty/submit_document.php" class="btn btn-brand btn-sm">
    <i class="fa-solid fa-file-arrow-up"></i> Upload a New Document
  </a>
</div>

<div class="card stat-card">
  <div class="card-body p-0 table-responsive">
    <table class="table mb-0 align-middle">
      <thead class="table-light">
        <tr><th>Document Type</th><th>Status</th><th>Uploaded</th><th></th></tr>
      </thead>
      <tbody>
        <?php if (!$requests): ?>
          <tr><td colspan="4" class="text-center text-muted py-4">You haven't uploaded any documents yet.</td></tr>
        <?php endif; ?>
        <?php foreach ($requests as $r): [$label, $badge] = status_badge($r['status']); ?>
        <tr>
          <td><?= h($categories[$r['document_type_hint']]['label'] ?? $r['document_type_hint']) ?></td>
          <td>
            <span class="badge <?= $badge ?>"><?= h($label) ?></span>
            <?php if ($r['status'] === 'rejected'): ?>
              <div class="small text-danger mt-1"><?= h($r['rejection_reason']) ?></div>
            <?php endif; ?>
          </td>
          <td><?= date('M j, Y g:ia', strtotime($r['submitted_at'])) ?></td>
          <td class="text-nowrap">
            <?php if ($r['file_path']): ?>
              <a href="<?= BASE_URL . '/' . h($r['file_path']) ?>" target="_blank" class="btn btn-sm btn-outline-brand"><i class="fa-solid fa-eye"></i> View</a>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
