<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_role('admin');

$page_title = 'Reports';
$categories = document_categories();

// Expiration status buckets. They don't overlap, so the summary counts
// add up to the total: "Active" is valid for more than 60 days,
// "Expiring soon" is within the next 60 days (same window as
// documents_expiring_soon()), "Expired" is before today.
$status_options = [
    'active'   => 'Active (more than 60 days left)',
    'expiring' => 'Expiring soon (within 60 days)',
    'expired'  => 'Expired',
    'none'     => 'No expiration date',
];
$status_labels = ['active' => 'Active', 'expiring' => 'Expiring Soon', 'expired' => 'Expired', 'none' => 'No Expiration Date'];
$status_badges = ['active' => 'bg-success', 'expiring' => 'bg-warning', 'expired' => 'bg-danger', 'none' => 'bg-light text-muted border'];

// Filters (all optional). Anything invalid is ignored rather than erroring.
$valid_date = function (string $d): string {
    $dt = DateTime::createFromFormat('!Y-m-d', $d);
    return ($dt && $dt->format('Y-m-d') === $d) ? $d : '';
};
$from       = $valid_date(trim($_GET['from'] ?? ''));
$to         = $valid_date(trim($_GET['to'] ?? ''));
$category   = $_GET['category'] ?? '';
$faculty_id = (int)($_GET['faculty'] ?? 0);
$status     = $_GET['status'] ?? '';
if (!array_key_exists($category, $categories)) { $category = ''; }
if (!array_key_exists($status, $status_options)) { $status = ''; }
if ($from !== '' && $to !== '' && $from > $to) { [$from, $to] = [$to, $from]; }

// Faculty members who keep a 201 file (Chairs and Deans have one too)
$faculty_list = $pdo->query(
    "SELECT user_id, full_name, role, is_active FROM users
     WHERE role IN ('faculty', 'program_chair', 'dean') ORDER BY full_name"
)->fetchAll();
$faculty_names = array_column($faculty_list, 'full_name', 'user_id');
if (!isset($faculty_names[$faculty_id])) { $faculty_id = 0; }

$status_sql = "CASE
                 WHEN d.expiration_date IS NULL THEN 'none'
                 WHEN d.expiration_date < CURDATE() THEN 'expired'
                 WHEN d.expiration_date <= DATE_ADD(CURDATE(), INTERVAL 60 DAY) THEN 'expiring'
                 ELSE 'active'
               END";

$sql = "SELECT d.document_id, d.faculty_id, d.document_type, d.document_subtype, d.academic_year, d.semester,
               d.period_year, d.file_path, d.expiration_date, d.filed_at, u.full_name,
               $status_sql AS expiration_status
        FROM documents d
        JOIN users u ON u.user_id = d.faculty_id
        WHERE d.status = 'active'";
$params = [];

if ($from !== '') {
    $sql .= " AND d.filed_at >= ?";
    $params[] = $from . ' 00:00:00';
}
if ($to !== '') {
    $sql .= " AND d.filed_at < DATE_ADD(?, INTERVAL 1 DAY)";
    $params[] = $to;
}
if ($category !== '') {
    $sql .= " AND d.document_type = ?";
    $params[] = $category;
}
if ($faculty_id) {
    $sql .= " AND d.faculty_id = ?";
    $params[] = $faculty_id;
}
if ($status !== '') {
    $sql .= " AND $status_sql = ?";
    $params[] = $status;
}
$sql .= " ORDER BY d.filed_at DESC, d.document_id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$documents = $stmt->fetchAll();

// Summary counts, from the same filtered rows so they always match the list
$by_category = array_fill_keys(array_keys($categories), 0);
$by_status   = array_fill_keys(array_keys($status_options), 0);
foreach ($documents as $d) {
    $by_category[$d['document_type']] = ($by_category[$d['document_type']] ?? 0) + 1;
    $by_status[$d['expiration_status']]++;
}

// Human-readable list of the filters applied, for the report header
$applied = [];
if ($from !== '' || $to !== '') {
    $applied[] = 'Upload date: ' . ($from !== '' ? date('M j, Y', strtotime($from)) : 'any')
               . ' to ' . ($to !== '' ? date('M j, Y', strtotime($to)) : 'any');
}
if ($category !== '')  { $applied[] = 'Category: ' . $categories[$category]['label']; }
if ($faculty_id)       { $applied[] = 'Faculty: ' . $faculty_names[$faculty_id]; }
if ($status !== '')    { $applied[] = 'Expiration status: ' . $status_options[$status]; }

$filter_query = array_filter(['from' => $from, 'to' => $to, 'category' => $category,
                              'faculty' => $faculty_id ?: '', 'status' => $status], fn($v) => $v !== '');

// Activity log: on-screen (Generate pressed), printed / saved as PDF, or exported to CSV
$log_report = function (string $format) use ($pdo, $applied, $documents) {
    log_my_activity($pdo, 'GENERATE_REPORT', "Documents report ({$format}) -- " . ($applied ? implode('; ', $applied) : 'no filters')
        . ' (' . count($documents) . ' document' . (count($documents) === 1 ? '' : 's') . ').');
};
// Printing happens in the browser; the page posts here when the print dialog opens
if (($_POST['action'] ?? '') === 'log_print') {
    $log_report('printed / saved as PDF');
    http_response_code(204);
    exit;
}

// ---------------------------------------------------------------------
// CSV export: the currently filtered list, same columns as the table
// ---------------------------------------------------------------------
if (($_GET['export'] ?? '') === 'csv') {
    $log_report('exported to CSV');
    // A cell starting with = + - @ would run as a formula in Excel
    $cell = fn(string $v): string => preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v;

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="faculty201_report_' . date('Y-m-d') . '.csv"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");   // BOM so Excel reads UTF-8 names correctly
    fputcsv($out, ['Faculty Name', 'Document', 'Category', 'Upload Date', 'Expiration Date', 'Expiration Status']);
    foreach ($documents as $d) {
        fputcsv($out, array_map($cell, [
            $d['full_name'],
            basename($d['file_path']),
            document_type_label($d['document_type'], $d['document_subtype']),
            date('Y-m-d', strtotime($d['filed_at'])),
            $d['expiration_date'] ? date('Y-m-d', strtotime($d['expiration_date'])) : '',
            $status_labels[$d['expiration_status']],
        ]));
    }
    fclose($out);
    exit;
}
if (isset($_GET['from'])) {   // the filter form was submitted (opening the page alone isn't logged)
    $log_report('on screen');
}

include __DIR__ . '/../includes/header.php';
?>

<!-- Shown only when printing / saving as PDF -->
<div class="report-print-header d-none d-print-block">
  <div class="fw-bold">CCS Faculty 201-File Repository</div>
  <div class="small">College of Computing Studies, Universidad de Manila</div>
  <h4 class="fw-bold mt-2 mb-1">Faculty 201-File Documents Report</h4>
  <div class="small">Filters: <?= $applied ? h(implode('; ', $applied)) : 'None (all documents)' ?></div>
  <div class="small">Generated: <?= h(date('F j, Y g:i A')) ?> by <?= h(current_user()['full_name']) ?></div>
</div>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-4 no-print">
  <div>
    <h3 class="fw-bold mb-1">Reports</h3>
    <p class="text-muted mb-0">Generate a report of filed 201-file documents, then print it, save it as PDF or export it to CSV.
      For each seminar and training with its title, dates, venue and organizer, use the <a href="<?= BASE_URL ?>/training_report.php">Seminar &amp; Training Report</a>.</p>
  </div>
  <div class="d-flex gap-2">
    <button type="button" class="btn btn-outline-brand" onclick="window.print()"><i class="fa-solid fa-print"></i> Print / Save as PDF</button>
    <a href="reports.php?<?= h(http_build_query($filter_query + ['export' => 'csv'])) ?>" class="btn btn-brand"><i class="fa-solid fa-file-csv"></i> Export CSV</a>
  </div>
</div>

<div class="card stat-card mb-4 no-print">
  <div class="card-body">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-6 col-md-3 col-xl-2">
        <label for="fFrom" class="form-label small fw-semibold">Uploaded From</label>
        <input type="date" name="from" id="fFrom" value="<?= h($from) ?>" class="form-control">
      </div>
      <div class="col-6 col-md-3 col-xl-2">
        <label for="fTo" class="form-label small fw-semibold">Uploaded To</label>
        <input type="date" name="to" id="fTo" value="<?= h($to) ?>" class="form-control">
      </div>
      <div class="col-md-6 col-xl-2">
        <label for="fCategory" class="form-label small fw-semibold">Category</label>
        <select name="category" id="fCategory" class="form-select">
          <option value="">All Categories</option>
          <?php foreach ($categories as $key => $meta): ?>
            <option value="<?= h($key) ?>" <?= $category === $key ? 'selected' : '' ?>><?= h($meta['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-6 col-xl-2">
        <label for="fFaculty" class="form-label small fw-semibold">Faculty Member</label>
        <select name="faculty" id="fFaculty" class="form-select">
          <option value="">All Faculty</option>
          <?php foreach ($faculty_list as $f): ?>
            <option value="<?= (int)$f['user_id'] ?>" <?= $faculty_id === (int)$f['user_id'] ? 'selected' : '' ?>>
              <?= h($f['full_name']) ?><?= $f['role'] !== 'faculty' ? ' (' . h(ucwords(str_replace('_', ' ', $f['role']))) . ')' : '' ?><?= $f['is_active'] ? '' : ' (inactive)' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-6 col-xl-2">
        <label for="fStatus" class="form-label small fw-semibold">Expiration Status</label>
        <select name="status" id="fStatus" class="form-select">
          <option value="">All</option>
          <?php foreach ($status_options as $key => $label): ?>
            <option value="<?= h($key) ?>" <?= $status === $key ? 'selected' : '' ?>><?= h($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-6 col-xl-2 d-flex gap-2">
        <button class="btn btn-brand flex-fill"><i class="fa-solid fa-file-lines"></i> Generate</button>
        <a href="reports.php" class="btn btn-outline-secondary flex-fill">Reset</a>
      </div>
    </form>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-6 col-md-3">
    <div class="card stat-card p-3">
      <div class="text-muted small">Total Documents</div>
      <div class="stat-number text-brand"><?= count($documents) ?></div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card stat-card p-3">
      <div class="text-muted small">Active</div>
      <div class="stat-number text-accent-teal"><?= $by_status['active'] ?></div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card stat-card p-3">
      <div class="text-muted small">Expiring Soon (60 days)</div>
      <div class="stat-number text-accent-gold"><?= $by_status['expiring'] ?></div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card stat-card p-3">
      <div class="text-muted small">Expired</div>
      <div class="stat-number text-danger"><?= $by_status['expired'] ?></div>
    </div>
  </div>
</div>
<p class="small text-muted mb-3"><?= $by_status['none'] ?> document<?= $by_status['none'] === 1 ? ' has' : 's have' ?> no expiration date.</p>

<div class="card stat-card mb-4 report-category-counts">
  <div class="card-header bg-white fw-semibold">Documents per Category</div>
  <div class="card-body p-0 table-responsive">
    <table class="table table-sm mb-0 align-middle">
      <tbody>
        <?php foreach ($by_category as $key => $c): ?>
          <tr>
            <td class="ps-3"><?= h($categories[$key]['label'] ?? $key) ?></td>
            <td class="text-end pe-3 fw-semibold <?= $c ? '' : 'text-muted' ?>"><?= (int)$c ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card stat-card">
  <div class="card-header bg-white fw-semibold"><?= count($documents) ?> document<?= count($documents) === 1 ? '' : 's' ?></div>
  <div class="card-body p-0 table-responsive">
    <table class="table mb-0 align-middle report-table">
      <thead class="table-light">
        <tr><th>Faculty Name</th><th>Document</th><th>Category</th><th>Upload Date</th><th>Expiration Date</th><th>Status</th></tr>
      </thead>
      <tbody>
        <?php if (!$documents): ?>
          <tr><td colspan="6" class="text-center text-muted py-4">No documents found<?= $applied ? ' for the selected filters' : '' ?>.</td></tr>
        <?php endif; ?>
        <?php foreach ($documents as $d): ?>
        <tr>
          <td><?= h($d['full_name']) ?></td>
          <td class="text-break">
            <a href="<?= h(document_url((int)$d['document_id'])) ?>" target="_blank"><?= h(basename($d['file_path'])) ?></a>
            <?php if ($p = document_period_label($d)): ?><div class="small text-muted"><?= h($p) ?></div><?php endif; ?>
          </td>
          <td><?= h(document_type_label($d['document_type'], $d['document_subtype'])) ?></td>
          <td class="text-nowrap"><?= h(date('M j, Y', strtotime($d['filed_at']))) ?></td>
          <td class="text-nowrap"><?= $d['expiration_date'] ? h(date('M j, Y', strtotime($d['expiration_date']))) : '<span class="text-muted">—</span>' ?></td>
          <td><span class="badge <?= $status_badges[$d['expiration_status']] ?>"><?= h($status_labels[$d['expiration_status']]) ?></span></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
// Activity log: record that the report was printed / saved as PDF (button or Ctrl+P), once per page view
(function () {
  var logged = false;
  window.addEventListener('beforeprint', function () {
    if (logged) return;
    logged = true;
    var body = new FormData();
    body.append('action', 'log_print');
    var url = 'reports.php?' + <?= json_encode(http_build_query($filter_query)) ?>;
    if (navigator.sendBeacon) { navigator.sendBeacon(url, body); }
    else { fetch(url, { method: 'POST', body: body, credentials: 'same-origin', keepalive: true }); }
  });
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
