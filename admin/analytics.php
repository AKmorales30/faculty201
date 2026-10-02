<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_role('admin');

$page_title = 'Data Analytics';

// Documents filed by category
$by_type = $pdo->query("SELECT document_type, COUNT(*) c FROM documents WHERE status = 'active' GROUP BY document_type")->fetchAll(PDO::FETCH_KEY_PAIR);
$by_type = array_merge(array_fill_keys(array_keys(document_categories()), 0), $by_type);
$type_labels = array_map(fn($k) => document_categories()[$k]['short'] ?? $k, array_keys($by_type));

// Faculty with the most uploaded documents
$top_uploaders = $pdo->query(
    "SELECT u.full_name, COUNT(*) c FROM documents d
     JOIN users u ON u.user_id = d.faculty_id
     WHERE d.status = 'active'
     GROUP BY d.faculty_id, u.full_name ORDER BY c DESC LIMIT 8"
)->fetchAll(PDO::FETCH_KEY_PAIR);

// Full-time vs part-time faculty
$by_employment = $pdo->query("SELECT employment_type, COUNT(*) c FROM users WHERE role='faculty' AND is_active=1 GROUP BY employment_type")->fetchAll(PDO::FETCH_KEY_PAIR);

// Uploads over the last 6 months
$monthly = $pdo->query(
    "SELECT DATE_FORMAT(submitted_at, '%Y-%m') ym, COUNT(*) c
     FROM submission_requests
     WHERE submitted_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
     GROUP BY ym ORDER BY ym"
)->fetchAll(PDO::FETCH_KEY_PAIR);

$expiring = documents_expiring_soon($pdo, 60);

include __DIR__ . '/../includes/header.php';
?>

<h3 class="fw-bold mb-4">Data Analytics</h3>

<div class="row g-3 mb-4">
  <div class="col-lg-4">
    <div class="card stat-card p-3">
      <div class="text-muted small mb-2">Documents Filed by Category</div>
      <canvas id="chartType" height="220"></canvas>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card stat-card p-3">
      <div class="text-muted small mb-2">Full-Time vs Part-Time Faculty</div>
      <canvas id="chartEmployment" height="220"></canvas>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card stat-card p-3">
      <div class="text-muted small mb-2">Top Uploaders</div>
      <canvas id="chartStatus" height="220"></canvas>
    </div>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-lg-7">
    <div class="card stat-card p-3">
      <div class="text-muted small mb-2">Uploads — Last 6 Months</div>
      <canvas id="chartMonthly" height="140"></canvas>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="card stat-card">
      <div class="card-header bg-white fw-semibold"><i class="fa-solid fa-triangle-exclamation text-accent-gold"></i> Documents Expiring Within 60 Days</div>
      <div class="card-body p-0 table-responsive" style="max-height:300px; overflow-y:auto;">
        <table class="table mb-0 small align-middle">
          <tbody>
            <?php if (!$expiring): ?>
              <tr><td class="text-center text-muted py-4">Nothing expiring soon.</td></tr>
            <?php endif; ?>
            <?php foreach ($expiring as $d): ?>
              <tr>
                <td><?= h($d['full_name']) ?></td>
                <td><?= h($d['document_type']) ?></td>
                <td class="text-danger fw-semibold"><?= date('M j, Y', strtotime($d['expiration_date'])) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.4/chart.umd.min.js"></script>
<script>
const brand = '#1b4b93', teal = '#157975', gold = '#e0aa28', blue = '#0f89b8', charcoal='#38383c';

new Chart(document.getElementById('chartType'), {
  type: 'doughnut',
  data: {
    labels: <?= json_encode($type_labels) ?>,
    datasets: [{ data: <?= json_encode(array_values($by_type)) ?>, backgroundColor: [brand, gold, blue, teal, '#6f42c1', '#20c997', '#fd7e14', '#6c757d', charcoal] }]
  },
  options: { plugins: { legend: { position: 'bottom' } } }
});

new Chart(document.getElementById('chartEmployment'), {
  type: 'pie',
  data: {
    labels: ['Full-Time', 'Part-Time'],
    datasets: [{ data: [<?= (int)($by_employment['full_time'] ?? 0) ?>, <?= (int)($by_employment['part_time'] ?? 0) ?>], backgroundColor: [blue, gold] }]
  },
  options: { plugins: { legend: { position: 'bottom' } } }
});

new Chart(document.getElementById('chartStatus'), {
  type: 'bar',
  data: {
    labels: <?= json_encode(array_keys($top_uploaders)) ?>,
    datasets: [{ data: <?= json_encode(array_values($top_uploaders)) ?>, backgroundColor: brand }]
  },
  options: { indexAxis: 'y', plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true, ticks: { precision: 0 } } } }
});

new Chart(document.getElementById('chartMonthly'), {
  type: 'line',
  data: {
    labels: <?= json_encode(array_keys($monthly)) ?>,
    datasets: [{ label: 'Uploads', data: <?= json_encode(array_values($monthly)) ?>, borderColor: teal, backgroundColor: 'rgba(21,121,117,.15)', fill: true, tension: .3 }]
  },
  options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
