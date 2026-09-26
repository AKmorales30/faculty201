<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_role(['program_chair', 'dean']);

$page_title = 'Pending Requests';
$me = current_user();

$requests = $pdo->query(
    "SELECT sr.*, u.full_name, u.employment_type
     FROM submission_requests sr
     JOIN users u ON u.user_id = sr.faculty_id
     WHERE sr.status NOT IN ('uploaded','rejected')
     ORDER BY sr.submitted_at ASC"
)->fetchAll();

$my_col = $me['role'] === 'program_chair' ? 'chair_id' : 'dean_id';
$my_confirmed_count = $pdo->prepare("SELECT COUNT(*) FROM submission_requests WHERE $my_col = ?");
$my_confirmed_count->execute([$me['user_id']]);
$my_confirmed_count = (int)$my_confirmed_count->fetchColumn();

$waiting_on_me = 0;
foreach ($requests as $r) {
    $already = ($me['role'] === 'program_chair' && $r['chair_id'] == $me['user_id'])
            || ($me['role'] === 'dean' && $r['dean_id'] == $me['user_id']);
    if (!$already) { $waiting_on_me++; }
}

$filed_total = (int)$pdo->query("SELECT COUNT(*) FROM documents")->fetchColumn();

include __DIR__ . '/../includes/header.php';
?>
<h3 class="fw-bold mb-4">Welcome, <?= h($me['full_name']) ?></h3>

<div class="row g-3 mb-4">
  <div class="col-md-4 col-6">
    <div class="card stat-card p-3">
      <div class="text-muted small">Awaiting Your Confirmation</div>
      <div class="stat-number text-accent-gold"><?= $waiting_on_me ?></div>
    </div>
  </div>
  <div class="col-md-4 col-6">
    <div class="card stat-card p-3">
      <div class="text-muted small">Total You've Confirmed</div>
      <div class="stat-number text-brand"><?= $my_confirmed_count ?></div>
    </div>
  </div>
  <div class="col-md-4 col-6">
    <div class="card stat-card p-3">
      <div class="text-muted small">Documents Filed System-Wide</div>
      <div class="stat-number text-accent-teal"><?= $filed_total ?></div>
    </div>
  </div>
</div>

<h5 class="fw-bold mb-2">Pending Submission Requests</h5>
<p class="text-muted">A document is only filed once <strong>both</strong> the Program Chair and the Dean confirm it.</p>

<div class="card stat-card">
  <div class="card-body p-0">
    <table class="table mb-0 align-middle">
      <thead class="table-light">
        <tr><th>Faculty</th><th>Type</th><th>Employment</th><th>Status</th><th>Submitted</th><th></th></tr>
      </thead>
      <tbody>
        <?php if (!$requests): ?>
          <tr><td colspan="6" class="text-center text-muted py-4">No pending requests right now.</td></tr>
        <?php endif; ?>
        <?php foreach ($requests as $r): [$label,$badge] = status_badge($r['status']); ?>
        <tr>
          <td><?= h($r['full_name']) ?></td>
          <td><?= h($r['document_type_hint']) ?></td>
          <td class="text-capitalize"><?= h(str_replace('_',' ',$r['employment_type'] ?? '')) ?></td>
          <td><span class="badge <?= $badge ?>"><?= h($label) ?></span></td>
          <td><?= date('M j, Y g:ia', strtotime($r['submitted_at'])) ?></td>
          <td><a href="request_detail.php?id=<?= (int)$r['request_id'] ?>" class="btn btn-sm btn-outline-dark">Review</a></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
