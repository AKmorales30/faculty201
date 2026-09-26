<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_role(['program_chair', 'dean']);

$page_title = 'Confirmation History';
$me = current_user();
$col = $me['role'] === 'program_chair' ? 'chair_id' : 'dean_id';

$stmt = $pdo->prepare(
    "SELECT sr.*, u.full_name
     FROM submission_requests sr
     JOIN users u ON u.user_id = sr.faculty_id
     WHERE sr.status IN ('uploaded','rejected') AND (sr.$col = ? OR sr.rejected_by = ?)
     ORDER BY sr.submitted_at DESC"
);
$stmt->execute([$me['user_id'], $me['user_id']]);
$requests = $stmt->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<h3 class="fw-bold mb-4">Confirmation History</h3>
<p class="text-muted">Requests you've already confirmed or rejected.</p>

<div class="card stat-card">
  <div class="card-body p-0">
    <table class="table mb-0 align-middle">
      <thead class="table-light">
        <tr><th>Faculty</th><th>Type</th><th>Outcome</th><th>Resolved</th><th></th></tr>
      </thead>
      <tbody>
        <?php if (!$requests): ?>
          <tr><td colspan="5" class="text-center text-muted py-4">Nothing here yet.</td></tr>
        <?php endif; ?>
        <?php foreach ($requests as $r): [$label, $badge] = status_badge($r['status']); ?>
        <tr>
          <td><?= h($r['full_name']) ?></td>
          <td><?= h($r['document_type_hint']) ?></td>
          <td><span class="badge <?= $badge ?>"><?= h($label) ?></span></td>
          <td><?= date('M j, Y', strtotime($r['submitted_at'])) ?></td>
          <td><a href="request_detail.php?id=<?= (int)$r['request_id'] ?>" class="btn btn-sm btn-outline-dark">View</a></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
