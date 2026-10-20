<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_role('admin');

$page_title = 'Document Status';   // Admin > Report > Document Status (tabs: this report, Expiring documents, Data analytics)
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
                 WHEN d.expiration_date < CURRENT_DATE THEN 'expired'
                 WHEN d.expiration_date <= CURRENT_DATE + INTERVAL '60' DAY THEN 'expiring'
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
    $sql .= " AND d.filed_at < CAST(? AS DATE) + INTERVAL '1' DAY";
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
  <div class="fw-bold"><?= h(SYSTEM_NAME) ?></div>
  <div class="small">College of Computing Studies, Universidad de Manila</div>
  <h4 class="fw-bold mt-2 mb-1">Faculty 201-File Documents Report</h4>
  <div class="small">Filters: <?= $applied ? h(implode('; ', $applied)) : 'None (all documents)' ?></div>
  <div class="small">Generated: <?= h(date('F j, Y g:i A')) ?> by <?= h(current_user()['full_name']) ?></div>
</div>

<div class="no-print">
<?= admin_intro('Status of every posted document, with expiration dates. Filter the list, then print it, save it as PDF or export it to CSV. For each seminar and training, use the <a href="' . BASE_URL . '/training_report.php">Training Summary</a>.',
    '<button type="button" class="btn btn-outline-brand" onclick="window.print()"><i class="fa-solid fa-print" aria-hidden="true"></i> Print / Save as PDF</button>'
  . '<a href="reports.php?' . h(http_build_query($filter_query + ['export' => 'csv'])) . '" class="btn btn-brand"><i class="fa-solid fa-file-csv" aria-hidden="true"></i> Export CSV</a>') ?>
<?= report_tabs('status') ?>
</div>

<?php $cat_max = max(1, ...array_values($by_category)); ?>
<div class="ui-grid-2">
  <section class="ui-card report-category-counts" aria-labelledby="catCountsTitle" data-animate>
    <div class="ui-card-head"><h2 class="ui-card-title" id="catCountsTitle">Documents per category</h2><span class="ui-card-meta"><?= count($documents) ?> total</span></div>
    <div class="card-body d-flex flex-column gap-2">
      <?php foreach ($by_category as $key => $c): ?>
        <div class="ui-catbar">
          <span class="ui-catbar-label"><?= h($categories[$key]['label'] ?? $key) ?></span>
          <span class="ui-bar" aria-hidden="true"><span style="--w: <?= round($c / $cat_max * 100) ?>%"></span></span>
          <span class="ui-catbar-n <?= $c ? '' : 'text-muted' ?>"><?= (int)$c ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  </section>
  <div class="ui-tiles mb-0" style="align-content:start">
    <?php foreach (['active' => ['Active', 'var(--ad-teal)'], 'expiring' => ['Expiring within 60 days', 'var(--ad-gold)'], 'expired' => ['Expired', 'var(--ad-red)'], 'none' => ['No expiration date', 'var(--ad-gray)']] as $key => [$label, $dot]): ?>
      <a class="ui-tile ui-tile-stack" href="reports.php?<?= h(http_build_query(array_merge($filter_query, ['status' => $key]))) ?>" style="--dot: <?= $dot ?>">
        <span class="ui-tile-dot"><?= h($label) ?></span>
        <span class="ui-tile-n" data-count="<?= (int)$by_status[$key] ?>"><?= (int)$by_status[$key] ?></span>
      </a>
    <?php endforeach; ?>
  </div>
</div>

<section class="ui-card" aria-label="Documents">
  <form method="GET" class="ui-toolbar no-print" data-autosubmit>
    <label for="fCategory" class="visually-hidden">Category</label>
    <select name="category" id="fCategory" class="form-select">
      <option value="">All categories</option>
      <?php foreach ($categories as $key => $meta): ?>
        <option value="<?= h($key) ?>" <?= $category === $key ? 'selected' : '' ?>><?= h($meta['label']) ?></option>
      <?php endforeach; ?>
    </select>
    <label for="fStatus" class="visually-hidden">Expiration status</label>
    <select name="status" id="fStatus" class="form-select">
      <option value="">All statuses</option>
      <?php foreach ($status_options as $key => $label): ?>
        <option value="<?= h($key) ?>" <?= $status === $key ? 'selected' : '' ?>><?= h($label) ?></option>
      <?php endforeach; ?>
    </select>
    <label for="fFaculty" class="visually-hidden">Faculty member</label>
    <select name="faculty" id="fFaculty" class="form-select">
      <option value="">All faculty</option>
      <?php foreach ($faculty_list as $f): ?>
        <option value="<?= (int)$f['user_id'] ?>" <?= $faculty_id === (int)$f['user_id'] ? 'selected' : '' ?>>
          <?= h($f['full_name']) ?><?= $f['role'] !== 'faculty' ? ' (' . h(ucwords(str_replace('_', ' ', $f['role']))) . ')' : '' ?><?= $f['is_active'] ? '' : ' (inactive)' ?>
        </option>
      <?php endforeach; ?>
    </select>
    <label for="fFrom" class="visually-hidden">Uploaded from</label>
    <input type="date" name="from" id="fFrom" value="<?= h($from) ?>" class="form-control" title="Uploaded from">
    <label for="fTo" class="visually-hidden">Uploaded to</label>
    <input type="date" name="to" id="fTo" value="<?= h($to) ?>" class="form-control" title="Uploaded to">
    <button class="btn btn-sm btn-brand ui-apply"><i class="fa-solid fa-file-lines" aria-hidden="true"></i> Generate</button>
    <?php if ($applied): ?><a href="reports.php" class="btn btn-sm btn-outline-secondary">Clear filters</a><?php endif; ?>
    <span class="ui-count"><?= count($documents) ?> document<?= count($documents) === 1 ? '' : 's' ?></span>
  </form>
  <div class="table-responsive">
    <table class="table align-middle report-table">
      <thead>
        <tr><th>Faculty name</th><th>Document</th><th>Category</th><th>Upload date</th><th>Expiration date</th><th>Status</th></tr>
      </thead>
      <tbody>
        <?php if (!$documents): ?>
          <?= admin_empty_row(6, 'No documents match', $applied ? 'Try different filters, or clear them to see everything.' : 'Posted documents appear here.', $applied ? 'reports.php' : null) ?>
        <?php endif; ?>
        <?php foreach ($documents as $d): $view_url = BASE_URL . '/api/modal.php?view=document&id=' . (int)$d['document_id']; ?>
        <tr class="row-clickable" tabindex="0" role="button" data-app-modal="<?= h($view_url) ?>" aria-label="Open <?= h(basename($d['file_path'])) ?>">
          <td class="fw-semibold"><?= h($d['full_name']) ?></td>
          <td class="text-break">
            <a href="<?= h(document_url((int)$d['document_id'])) ?>" target="_blank" class="text-body" data-app-modal="<?= h($view_url) ?>"><?= h(basename($d['file_path'])) ?></a>
            <?php if ($p = document_period_label($d)): ?><div class="ui-cell-sub"><?= h($p) ?></div><?php endif; ?>
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
</section>

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
