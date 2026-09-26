<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_role('faculty');

$page_title = 'My Requests';
$me = current_user();

$stmt = $pdo->prepare("SELECT * FROM submission_requests WHERE faculty_id=? ORDER BY submitted_at DESC");
$stmt->execute([$me['user_id']]);
$requests = $stmt->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <h3 class="fw-bold mb-0">My Requests</h3>
  <a href="<?= BASE_URL ?>/faculty/submit_document.php" class="btn btn-brand btn-sm">
    <i class="fa-solid fa-file-arrow-up"></i> Submit a New Document
  </a>
</div>

<div class="card stat-card">
  <div class="card-body p-0">
    <table class="table mb-0 align-middle">
      <thead class="table-light">
        <tr><th>Document Type</th><th>Status</th><th>Program Chair</th><th>Dean</th><th>Submitted</th></tr>
      </thead>
      <tbody>
        <?php if (!$requests): ?>
          <tr><td colspan="5" class="text-center text-muted py-4">You haven't submitted any documents yet.</td></tr>
        <?php endif; ?>
        <?php foreach ($requests as $r): [$label, $badge] = status_badge($r['status']); ?>
        <tr>
          <td><?= h($r['document_type_hint']) ?></td>
          <td>
            <span class="badge <?= $badge ?>"><?= h($label) ?></span>
            <?php if ($r['status'] === 'rejected'): ?>
              <div class="small text-danger mt-1"><?= h($r['rejection_reason']) ?></div>
            <?php endif; ?>
          </td>
          <td><?= $r['chair_confirmed_at'] ? '<i class="fa-solid fa-circle-check text-success"></i>' : '<span class="text-muted small">pending</span>' ?></td>
          <td><?= $r['dean_confirmed_at'] ? '<i class="fa-solid fa-circle-check text-success"></i>' : '<span class="text-muted small">pending</span>' ?></td>
          <td><?= date('M j, Y g:ia', strtotime($r['submitted_at'])) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
