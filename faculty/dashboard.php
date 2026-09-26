<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_role('faculty');

$page_title = 'My Dashboard';
$me = current_user();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM submission_requests WHERE faculty_id=? AND status NOT IN ('uploaded','rejected')");
$stmt->execute([$me['user_id']]);
$pending = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM documents WHERE faculty_id=?");
$stmt->execute([$me['user_id']]);
$filed = $stmt->fetchColumn();

$stmt = $pdo->prepare(
    "SELECT * FROM submission_requests WHERE faculty_id=? ORDER BY submitted_at DESC LIMIT 5"
);
$stmt->execute([$me['user_id']]);
$recent = $stmt->fetchAll();

include __DIR__ . '/../includes/header.php';
?>
<h3 class="fw-bold mb-4">Welcome, <?= h($me['full_name']) ?></h3>

<div class="row g-3 mb-4">
  <div class="col-md-4">
    <div class="card stat-card p-3">
      <div class="text-muted small">Pending Requests</div>
      <div class="stat-number text-accent-gold"><?= (int)$pending ?></div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="card stat-card p-3">
      <div class="text-muted small">Documents in My 201 File</div>
      <div class="stat-number text-accent-teal"><?= (int)$filed ?></div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="card stat-card p-3 d-flex justify-content-center">
      <a href="<?= BASE_URL ?>/faculty/submit_document.php" class="btn btn-brand">
        <i class="fa-solid fa-file-arrow-up"></i> Submit a New Document
      </a>
    </div>
  </div>
</div>

<div class="card stat-card">
  <div class="card-header bg-white fw-semibold">My Recent Requests</div>
  <div class="card-body p-0">
    <table class="table mb-0 align-middle">
      <thead class="table-light"><tr><th>Document Type</th><th>Status</th><th>Submitted</th></tr></thead>
      <tbody>
        <?php if (!$recent): ?>
          <tr><td colspan="3" class="text-center text-muted py-4">You haven't submitted any documents yet.</td></tr>
        <?php endif; ?>
        <?php foreach ($recent as $r): [$label,$badge] = status_badge($r['status']); ?>
        <tr>
          <td><?= h($r['document_type_hint']) ?></td>
          <td><span class="badge <?= $badge ?>"><?= h($label) ?></span></td>
          <td><?= date('M j, Y g:ia', strtotime($r['submitted_at'])) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
