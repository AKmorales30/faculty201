<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_role('admin');

$page_title = 'Data Analytics';

// Everything here counts active documents only -- archived and deleted ones
// are left out, as everywhere else outside Archived Documents. Dates are
// Asia/Manila: PHP via config.php, the database session via db.php.
$today = new DateTimeImmutable('today');

/** Run a prepared query and return [first column => second column] rows. */
function analytics_pairs(PDO $pdo, string $sql, array $params = []): array {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
}

// 1. Documents uploaded per month, last 12 months (months with no uploads show 0)
$first_month = $today->modify('first day of this month')->modify('-11 months');
$per_month = analytics_pairs($pdo,
    "SELECT DATE_FORMAT(filed_at, '%Y-%m') ym, COUNT(*) FROM documents
     WHERE status = 'active' AND filed_at >= ? GROUP BY ym",
    [$first_month->format('Y-m-d 00:00:00')]);
$monthly = ['labels' => [], 'values' => []];
for ($m = $first_month; $m <= $today; $m = $m->modify('+1 month')) {
    $monthly['labels'][] = $m->format('M Y');
    $monthly['values'][] = (int)($per_month[$m->format('Y-m')] ?? 0);
}

// 2. Documents per category, in the order of document_categories()
$per_type = analytics_pairs($pdo, "SELECT document_type, COUNT(*) FROM documents WHERE status = 'active' GROUP BY document_type");
$by_category = ['labels' => [], 'values' => []];
foreach (document_categories() as $key => $cat) {
    $by_category['labels'][] = $cat['short'];
    $by_category['values'][] = (int)($per_type[$key] ?? 0);
}

// 3. Documents per program (the uploader's program; PROGRAMS in config.php)
$per_program = analytics_pairs($pdo,
    "SELECT COALESCE(u.program, '') program, COUNT(*) FROM documents d
     JOIN users u ON u.user_id = d.faculty_id
     WHERE d.status = 'active' GROUP BY program");
$by_program = ['labels' => [], 'values' => []];
foreach (PROGRAMS as $key => $program) {
    $by_program['labels'][] = $key;
    $by_program['values'][] = (int)($per_program[$key] ?? 0);
    unset($per_program[$key]);
}
$unassigned = array_sum($per_program);   // no program set, or one no longer in PROGRAMS
if ($unassigned) {
    $by_program['labels'][] = 'No program';
    $by_program['values'][] = (int)$unassigned;
}

// 4. Expiration overview: valid (more than 30 days left) / expiring within 30 days / expired
$stmt = $pdo->prepare(
    "SELECT COALESCE(SUM(expiration_date > ?), 0)               valid,
            COALESCE(SUM(expiration_date BETWEEN ? AND ?), 0)   expiring,
            COALESCE(SUM(expiration_date < ?), 0)               expired,
            COALESCE(SUM(expiration_date IS NULL), 0)           no_date
     FROM documents WHERE status = 'active'");
$in_30 = $today->modify('+30 days')->format('Y-m-d');
$now   = $today->format('Y-m-d');
$stmt->execute([$in_30, $now, $in_30, $now]);
$exp = array_map('intval', $stmt->fetch());
$expiration = [
    'labels' => ['Valid (over 30 days)', 'Expiring within 30 days', 'Expired'],
    'values' => [$exp['valid'], $exp['expiring'], $exp['expired']],
];

// 5. Active faculty by employment type
$per_employment = analytics_pairs($pdo,
    "SELECT COALESCE(employment_type, '') employment_type, COUNT(*) FROM users
     WHERE role = 'faculty' AND is_active = 1 GROUP BY employment_type");
$employment = [
    'labels' => ['Full-Time', 'Part-Time'],
    'values' => [(int)($per_employment['full_time'] ?? 0), (int)($per_employment['part_time'] ?? 0)],
];
if (!empty($per_employment[''])) {
    $employment['labels'][] = 'Not set';
    $employment['values'][] = (int)$per_employment[''];
}

// 6. Classification confidence (CONFIDENCE_THRESHOLD in config.php)
$stmt = $pdo->prepare(
    "SELECT COALESCE(SUM(is_low_confidence = 0 AND confidence_score IS NOT NULL), 0) normal,
            COALESCE(SUM(is_low_confidence = 1), 0)                                   low,
            COALESCE(SUM(is_low_confidence = 0 AND confidence_score IS NULL), 0)     unscored,
            COALESCE(SUM(is_low_confidence = 1 AND reviewed_at IS NULL), 0)          awaiting_review
     FROM documents WHERE status = 'active'");
$stmt->execute();
$conf = array_map('intval', $stmt->fetch());
$confidence = [
    'labels' => ['Normal', 'Low confidence', 'Not scored'],
    'values' => [$conf['normal'], $conf['low'], $conf['unscored']],
];

// Faculty with the most uploaded documents
$top = analytics_pairs($pdo,
    "SELECT u.full_name, COUNT(*) c FROM documents d
     JOIN users u ON u.user_id = d.faculty_id
     WHERE d.status = 'active'
     GROUP BY d.faculty_id, u.full_name ORDER BY c DESC LIMIT 8");
$uploaders = ['labels' => array_map('strval', array_keys($top)), 'values' => array_map('intval', array_values($top))];

$chart_data = compact('monthly', 'by_category', 'by_program', 'expiration', 'employment', 'confidence', 'uploaders');

$expiring = documents_expiring_soon($pdo, 60);

include __DIR__ . '/../includes/header.php';
?>

<h3 class="fw-bold mb-4">Data Analytics</h3>

<div class="row g-3 mb-3">
  <div class="col-lg-8">
    <div class="card stat-card p-3 h-100">
      <div class="text-muted small mb-2">Documents Uploaded per Month — Last 12 Months</div>
      <div class="chart-box" data-chart="monthly"><canvas role="img" aria-label="Documents uploaded per month"></canvas></div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card stat-card p-3 h-100">
      <div class="text-muted small mb-2">Expiration Overview</div>
      <div class="chart-box" data-chart="expiration"><canvas role="img" aria-label="Documents by expiration status"></canvas></div>
      <?php if ($exp['no_date']): ?>
        <div class="text-muted small mt-2"><?= number_format($exp['no_date']) ?> document<?= $exp['no_date'] === 1 ? '' : 's' ?> without an expiration date not shown.</div>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-lg-7">
    <div class="card stat-card p-3 h-100">
      <div class="text-muted small mb-2">Documents per Category</div>
      <div class="chart-box chart-box-tall" data-chart="by_category"><canvas role="img" aria-label="Documents per category"></canvas></div>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="card stat-card p-3 h-100">
      <div class="text-muted small mb-2">Documents per Program</div>
      <div class="chart-box chart-box-tall" data-chart="by_program"><canvas role="img" aria-label="Documents per program"></canvas></div>
    </div>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-lg-4">
    <div class="card stat-card p-3 h-100">
      <div class="text-muted small mb-2">Faculty by Employment Type</div>
      <div class="chart-box" data-chart="employment"><canvas role="img" aria-label="Faculty by employment type"></canvas></div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card stat-card p-3 h-100">
      <div class="text-muted small mb-2">Classification Confidence</div>
      <div class="chart-box" data-chart="confidence"><canvas role="img" aria-label="Uploads by classification confidence"></canvas></div>
      <?php if ($conf['awaiting_review']): ?>
        <a class="small mt-2" href="<?= BASE_URL ?>/admin/classification_review.php"><?= number_format($conf['awaiting_review']) ?> low-confidence upload<?= $conf['awaiting_review'] === 1 ? '' : 's' ?> awaiting review</a>
      <?php endif; ?>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card stat-card p-3 h-100">
      <div class="text-muted small mb-2">Top Uploaders</div>
      <div class="chart-box" data-chart="uploaders"><canvas role="img" aria-label="Faculty with the most uploaded documents"></canvas></div>
    </div>
  </div>
</div>

<div class="card stat-card mb-4">
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

<script type="application/json" id="analytics-data"><?= json_encode($chart_data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="<?= BASE_URL ?>/assets/js/chart.umd.min.js?v=4.5.1"></script>
<script src="<?= BASE_URL ?>/assets/js/analytics.js?v=<?= filemtime(ROOT_PATH . '/assets/js/analytics.js') ?>"></script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
