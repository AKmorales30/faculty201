<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_role('admin');

$page_title = 'Admin Dashboard';

// Anything left waiting under the old approval workflow gets filed now.
file_outstanding_requests($pdo);

$total_faculty = $pdo->query("SELECT COUNT(*) FROM users WHERE role='faculty' AND is_active=1")->fetchColumn();
$full_time     = $pdo->query("SELECT COUNT(*) FROM users WHERE role='faculty' AND employment_type='full_time' AND is_active=1")->fetchColumn();
$part_time     = $pdo->query("SELECT COUNT(*) FROM users WHERE role='faculty' AND employment_type='part_time' AND is_active=1")->fetchColumn();
$month_count   = $pdo->query("SELECT COUNT(*) FROM documents WHERE filed_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')")->fetchColumn();
$filed_count   = $pdo->query("SELECT COUNT(*) FROM documents")->fetchColumn();
$alert_count   = unresolved_security_alert_count($pdo);

$recent = $pdo->query(
    "SELECT sr.request_id, sr.document_type_hint, sr.status, sr.submitted_at, u.full_name, d.document_subtype
     FROM submission_requests sr
     JOIN users u ON u.user_id = sr.faculty_id
     LEFT JOIN documents d ON d.request_id = sr.request_id
     ORDER BY sr.submitted_at DESC LIMIT 8"
)->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
  <h3 class="fw-bold mb-0">Welcome, <?= h(current_user()['full_name']) ?></h3>
  <div class="d-flex flex-wrap gap-2">
    <a href="<?= BASE_URL ?>/admin/activity_logs.php" class="btn btn-outline-brand"><i class="fa-solid fa-clock-rotate-left"></i> View Activity / Login Logs</a>
    <a href="<?= BASE_URL ?>/admin/reports.php" class="btn btn-brand"><i class="fa-solid fa-file-lines"></i> Generate Report</a>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-md-3 col-6">
    <div class="card stat-card p-3">
      <div class="text-muted small">Total Faculty</div>
      <div class="stat-number text-brand"><?= (int)$total_faculty ?></div>
    </div>
  </div>
  <div class="col-md-3 col-6">
    <div class="card stat-card p-3">
      <div class="text-muted small">Full-Time / Part-Time</div>
      <div class="stat-number" style="font-size:1.4rem;"><?= (int)$full_time ?> / <?= (int)$part_time ?></div>
    </div>
  </div>
  <div class="col-md-3 col-6">
    <div class="card stat-card p-3">
      <div class="text-muted small">Uploads This Month</div>
      <div class="stat-number text-accent-gold"><?= (int)$month_count ?></div>
    </div>
  </div>
  <div class="col-md-3 col-6">
    <div class="card stat-card p-3">
      <div class="text-muted small">Documents Filed</div>
      <div class="stat-number text-accent-teal"><?= (int)$filed_count ?></div>
    </div>
  </div>
</div>

<?php if ($alert_count > 0): ?>
<a href="<?= BASE_URL ?>/admin/security_alerts.php" class="alert alert-danger d-flex align-items-center gap-2 mb-4 text-decoration-none">
  <i class="fa-solid fa-shield-halved fa-lg"></i>
  <div><strong><?= (int)$alert_count ?></strong> security alert<?= $alert_count === 1 ? '' : 's' ?> need<?= $alert_count === 1 ? 's' : '' ?> your review.</div>
</a>
<?php endif; ?>

<div class="card stat-card">
  <div class="card-header bg-white fw-semibold">Recent Faculty Uploads</div>
  <div class="card-body p-0 table-responsive">
    <table class="table mb-0 align-middle">
      <thead class="table-light">
        <tr><th>Faculty</th><th>Document Type</th><th>Status</th><th>Uploaded</th></tr>
      </thead>
      <tbody>
        <?php if (!$recent): ?>
          <tr><td colspan="4" class="text-center text-muted py-4">No uploads yet.</td></tr>
        <?php endif; ?>
        <?php foreach ($recent as $r): [$label, $badge] = status_badge($r['status']); ?>
        <tr>
          <td><?= h($r['full_name']) ?></td>
          <td><?= h(document_type_label($r['document_type_hint'], $r['document_subtype'])) ?></td>
          <td><span class="badge <?= $badge ?>"><?= h($label) ?></span></td>
          <td><?= date('M j, Y g:ia', strtotime($r['submitted_at'])) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
