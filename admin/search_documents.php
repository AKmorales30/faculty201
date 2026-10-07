<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_role('admin');   // the only role that searches across faculty; others see only their own 201 file

$page_title = 'Search Documents';
$categories = document_categories();
const SEARCH_PER_PAGE = 25;
const NOT_SET = '__none';   // filter value for "program / employment type not set"

// Filter options from the values actually stored for people with a 201 file
$owner_roles = "u.role IN ('faculty', 'program_chair', 'dean')";
$program_options = [];
foreach ($pdo->query("SELECT DISTINCT u.program FROM users u WHERE $owner_roles ORDER BY u.program")->fetchAll(PDO::FETCH_COLUMN) as $p) {
    $program_options[$p ?? NOT_SET] = $p === null ? 'Not set' : (PROGRAMS[$p]['label'] ?? $p);
}
$employment_options = [];
foreach ($pdo->query("SELECT DISTINCT u.employment_type FROM users u WHERE $owner_roles ORDER BY u.employment_type")->fetchAll(PDO::FETCH_COLUMN) as $e) {
    $employment_options[$e ?? NOT_SET] = $e === null ? 'Not set' : (employment_type_label($e) ?: ucwords(str_replace('_', ' ', $e)));
}
$sort_options = [
    'newest'  => ['Newest uploads first', 'd.filed_at DESC, d.document_id DESC'],
    'oldest'  => ['Oldest uploads first', 'd.filed_at ASC, d.document_id ASC'],
    'faculty' => ['Faculty name (A-Z)', 'u.full_name ASC, d.filed_at DESC'],
];

// Filters (all optional, all combinable). Anything invalid is ignored rather than erroring.
$valid_date = function (string $d): string {
    $dt = DateTime::createFromFormat('!Y-m-d', $d);
    return ($dt && $dt->format('Y-m-d') === $d) ? $d : '';
};
$q             = trim($_GET['q'] ?? '');
$type          = $_GET['type'] ?? '';
$expiring_only = isset($_GET['expiring_only']);
$include_archived = isset($_GET['include_archived']);
$from          = $valid_date(trim($_GET['from'] ?? ''));
$to            = $valid_date(trim($_GET['to'] ?? ''));
$program       = $_GET['program'] ?? '';
$employment    = $_GET['employment'] ?? '';
$sort          = $_GET['sort'] ?? 'newest';
$page          = max(1, (int)($_GET['page'] ?? 1));
if (!array_key_exists($type, $categories)) { $type = ''; }
if (!array_key_exists($program, $program_options)) { $program = ''; }
if (!array_key_exists($employment, $employment_options)) { $employment = ''; }
if (!array_key_exists($sort, $sort_options)) { $sort = 'newest'; }
$date_error = null;
if ($from !== '' && $to !== '' && $from > $to) {
    $date_error = 'The "From" date is later than the "To" date, so the date range was not applied. Please correct it.';
}
$use_dates = $date_error === null;

// ---------------------------------------------------------------------
// WHERE clause: fixed SQL fragments with ? placeholders; every value is bound
// ---------------------------------------------------------------------
$where = [$include_archived ? "d.status IN ('active', 'archived')" : "d.status = 'active'"];
$params = [];
if ($q !== '') {
    $where[] = "(u.full_name LIKE ? OR d.ocr_extracted_text LIKE ? OR d.ocr_matched_name LIKE ? OR d.file_path LIKE ?
                 OR d.title LIKE ? OR d.conducted_by LIKE ? OR d.venue LIKE ?)";
    array_push($params, "%$q%", "%$q%", "%$q%", "%$q%", "%$q%", "%$q%", "%$q%");
}
if ($type !== '') {
    $where[] = "d.document_type = ?";
    $params[] = $type;
}
if ($expiring_only) {
    $where[] = "d.expiration_date IS NOT NULL AND d.expiration_date <= DATE_ADD(CURDATE(), INTERVAL 60 DAY)";
}
if ($use_dates && $from !== '') {
    $where[] = "d.filed_at >= ?";
    $params[] = $from . ' 00:00:00';
}
if ($use_dates && $to !== '') {
    $where[] = "d.filed_at < DATE_ADD(?, INTERVAL 1 DAY)";
    $params[] = $to;
}
if ($program !== '') {
    if ($program === NOT_SET) { $where[] = "u.program IS NULL"; }
    else { $where[] = "u.program = ?"; $params[] = $program; }
}
if ($employment !== '') {
    if ($employment === NOT_SET) { $where[] = "u.employment_type IS NULL"; }
    else { $where[] = "u.employment_type = ?"; $params[] = $employment; }
}
$from_where = "FROM documents d JOIN users u ON u.user_id = d.faculty_id WHERE " . implode(' AND ', $where);

/** Prepare + execute with integer params bound as integers (needed for LIMIT / OFFSET). */
$run = function (string $sql, array $params) use ($pdo): PDOStatement {
    $stmt = $pdo->prepare($sql);
    foreach (array_values($params) as $i => $v) {
        $stmt->bindValue($i + 1, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->execute();
    return $stmt;
};
$total = (int)$run("SELECT COUNT(*) $from_where", $params)->fetchColumn();
$pages = max(1, (int)ceil($total / SEARCH_PER_PAGE));
$page = min($page, $pages);
$documents = $run(
    "SELECT d.*, u.full_name, u.employment_type, u.program $from_where ORDER BY {$sort_options[$sort][1]} LIMIT ? OFFSET ?",
    array_merge($params, [SEARCH_PER_PAGE, ($page - 1) * SEARCH_PER_PAGE])
)->fetchAll();

// ---------------------------------------------------------------------
// The filters in force, in words (shown above the results and logged)
// ---------------------------------------------------------------------
$fmt = fn(string $d, bool $year = true) => date($year ? 'M j, Y' : 'M j', strtotime($d));
$date_label = '';
if ($use_dates && $from !== '' && $to !== '') {
    $date_label = substr($from, 0, 4) === substr($to, 0, 4) ? $fmt($from, false) . '–' . $fmt($to) : $fmt($from) . '–' . $fmt($to);
} elseif ($use_dates && $from !== '') {
    $date_label = 'From ' . $fmt($from);
} elseif ($use_dates && $to !== '') {
    $date_label = 'Until ' . $fmt($to);
}
$active = array_filter([
    'Keywords'   => $q !== '' ? '"' . $q . '"' : '',
    'Type'       => $type !== '' ? $categories[$type]['label'] : '',
    'Program'    => $program !== '' ? ($program === NOT_SET ? 'Not set' : $program) : '',
    'Employment' => $employment !== '' ? $employment_options[$employment] : '',
    'Uploaded'   => $date_label,
    'Expiring'   => $expiring_only ? 'within 60 days' : '',
    'Archived'   => $include_archived ? 'included' : '',
], fn($v) => $v !== '');

$filter_query = array_filter([
    'q' => $q, 'type' => $type, 'expiring_only' => $expiring_only ? '1' : '', 'from' => $from, 'to' => $to,
    'program' => $program, 'employment' => $employment, 'sort' => $sort !== 'newest' ? $sort : '',
    'include_archived' => $include_archived ? '1' : '',
], fn($v) => $v !== '');

// Activity log: a search was run (not just the page opened), once -- not again for each results page
if ($active && $page === 1) {
    log_my_activity($pdo, 'SEARCH', 'Search Documents -- ' . describe_filters([
        'Keywords'   => $q,
        'Type'       => $type !== '' ? $categories[$type]['label'] : '',
        'Program'    => $program !== '' ? $program_options[$program] : '',
        'Employment' => $employment !== '' ? $employment_options[$employment] : '',
        'Uploaded from' => $use_dates ? $from : '',
        'Uploaded to'   => $use_dates ? $to : '',
        'Expiring only' => $expiring_only,
        'Include archived' => $include_archived,
    ]) . " ({$total} result" . ($total === 1 ? '' : 's') . ').');
}

$advanced_open = $from !== '' || $to !== '' || $program !== '' || $employment !== '' || $expiring_only || $include_archived || $sort !== 'newest';
$advanced_count = count(array_filter([$from !== '' || $to !== '', $program !== '', $employment !== '', $expiring_only, $include_archived]));

include __DIR__ . '/../includes/header.php';
include_once __DIR__ . '/../includes/document_actions.php';   // Archive / Delete buttons + confirmation dialog
?>

<h3 class="fw-bold mb-1">Search Documents</h3>
<p class="text-muted mb-4">Search and filter across every faculty member's filed 201-file documents.</p>

<div class="card stat-card mb-4">
  <div class="card-body">
    <form method="GET" id="searchForm">
      <div class="row g-2 align-items-end">
        <div class="col-md-6">
          <label for="fQ" class="form-label small fw-semibold">Search / Filter Documents</label>
          <input type="search" name="q" id="fQ" value="<?= h($q) ?>" class="form-control" placeholder="Faculty name, title, organizer, extracted text, filename...">
        </div>
        <div class="col-md-3">
          <label for="fType" class="form-label small fw-semibold">Document Type</label>
          <select name="type" id="fType" class="form-select">
            <option value="">All Types</option>
            <?php foreach ($categories as $key => $meta): ?>
              <option value="<?= h($key) ?>" <?= $type === $key ? 'selected' : '' ?>><?= h($meta['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3 d-flex gap-2">
          <button class="btn btn-brand flex-fill"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
          <a href="search_documents.php" class="btn btn-outline-secondary flex-fill" title="Clear all filters">Clear filters</a>
        </div>
      </div>

      <button class="btn btn-link btn-sm px-0 mt-2 text-decoration-none" type="button" data-bs-toggle="collapse" data-bs-target="#advancedFilters"
              aria-expanded="<?= $advanced_open ? 'true' : 'false' ?>" aria-controls="advancedFilters">
        <i class="fa-solid fa-sliders"></i> Advanced Search<?= $advanced_count ? ' (' . $advanced_count . ' active)' : '' ?>
      </button>

      <div class="collapse <?= $advanced_open ? 'show' : '' ?>" id="advancedFilters">
        <div class="row g-2 align-items-end pt-2 border-top mt-1">
          <div class="col-6 col-md-3 col-xl-2">
            <label for="fFrom" class="form-label small fw-semibold">Uploaded From</label>
            <input type="date" name="from" id="fFrom" value="<?= h($from) ?>" max="<?= h($to) ?>" class="form-control <?= $date_error ? 'is-invalid' : '' ?>">
          </div>
          <div class="col-6 col-md-3 col-xl-2">
            <label for="fTo" class="form-label small fw-semibold">Uploaded To</label>
            <input type="date" name="to" id="fTo" value="<?= h($to) ?>" min="<?= h($from) ?>" class="form-control <?= $date_error ? 'is-invalid' : '' ?>">
          </div>
          <div class="col-md-6 col-xl-3">
            <label for="fProgram" class="form-label small fw-semibold">Program</label>
            <select name="program" id="fProgram" class="form-select">
              <option value="">All programs</option>
              <?php foreach ($program_options as $key => $label): ?>
                <option value="<?= h($key) ?>" <?= $program === (string)$key ? 'selected' : '' ?>><?= h($key === NOT_SET ? $label : "$key -- $label") ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6 col-xl-2">
            <label for="fEmployment" class="form-label small fw-semibold">Employment Type</label>
            <select name="employment" id="fEmployment" class="form-select">
              <option value="">All</option>
              <?php foreach ($employment_options as $key => $label): ?>
                <option value="<?= h($key) ?>" <?= $employment === (string)$key ? 'selected' : '' ?>><?= h($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6 col-xl-3">
            <label for="fSort" class="form-label small fw-semibold">Sort By</label>
            <select name="sort" id="fSort" class="form-select">
              <?php foreach ($sort_options as $key => [$label]): ?>
                <option value="<?= h($key) ?>" <?= $sort === $key ? 'selected' : '' ?>><?= h($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12">
            <div class="form-check">
              <input type="checkbox" name="expiring_only" id="expiringOnly" class="form-check-input" value="1" <?= $expiring_only ? 'checked' : '' ?>>
              <label for="expiringOnly" class="form-check-label small">Expiring within 60 days</label>
            </div>
            <div class="form-check">
              <input type="checkbox" name="include_archived" id="includeArchived" class="form-check-input" value="1" <?= $include_archived ? 'checked' : '' ?>>
              <label for="includeArchived" class="form-check-label small">Include archived documents (more than <?= (int)ARCHIVE_AFTER_YEARS ?> years old, or archived by the Admin)</label>
            </div>
          </div>
        </div>
      </div>
    </form>
  </div>
</div>

<?php if ($date_error): ?>
  <div class="alert alert-warning small"><i class="fa-solid fa-triangle-exclamation"></i> <?= h($date_error) ?></div>
<?php endif; ?>

<div class="card stat-card">
  <div class="card-header bg-white">
    <span class="fw-semibold"><?= $total ?> result<?= $total === 1 ? '' : 's' ?></span>
    <?php if ($total > SEARCH_PER_PAGE): ?>
      <span class="text-muted small">-- showing <?= ($page - 1) * SEARCH_PER_PAGE + 1 ?>-<?= ($page - 1) * SEARCH_PER_PAGE + count($documents) ?></span>
    <?php endif; ?>
    <?php if ($active): ?>
      <div class="small text-muted mt-1">
        <?= implode(' &middot; ', array_map(fn($label, $value) => h($label) . ': <span class="text-body">' . h($value) . '</span>', array_keys($active), $active)) ?>
      </div>
    <?php endif; ?>
  </div>
  <div class="card-body p-0 table-responsive">
    <table class="table mb-0 align-middle">
      <thead class="table-light"><tr><th>Faculty</th><th>Program</th><th>Employment</th><th>Type</th><th>File</th><th>Matched Name</th><th>Expiration</th><th>Uploaded</th><th></th></tr></thead>
      <tbody>
        <?php if (!$documents): ?>
          <tr><td colspan="9" class="text-center text-muted py-4">No documents found.</td></tr>
        <?php endif; ?>
        <?php foreach ($documents as $d):
          $expiring = $d['expiration_date'] && strtotime($d['expiration_date']) <= strtotime('+60 days');
        ?>
        <tr>
          <td><a href="faculty_documents.php?id=<?= (int)$d['faculty_id'] ?>"><?= h($d['full_name']) ?></a></td>
          <td class="small"><?= $d['program'] ? '<span title="' . h(PROGRAMS[$d['program']]['label'] ?? $d['program']) . '">' . h($d['program']) . '</span>' : '<span class="text-muted">—</span>' ?></td>
          <td class="small text-nowrap"><?= h(employment_type_label($d['employment_type'])) ?: '<span class="text-muted">—</span>' ?></td>
          <td><?= h(document_type_label($d['document_type'], $d['document_subtype'])) ?><?php if ($p = document_period_label($d)): ?><div class="small text-muted"><?= h($p) ?></div><?php endif; ?></td>
          <td class="small text-break">
            <?php if (trim((string)$d['title']) !== ''): ?><div class="fw-semibold"><?= h($d['title']) ?></div><?php endif; ?>
            <?= h(basename($d['file_path'])) ?>
            <?php if ($d['status'] === 'archived'): ?><div><span class="badge bg-light text-dark border">Archived</span></div><?php endif; ?>
          </td>
          <td><?= $d['ocr_matched_name'] ? h($d['ocr_matched_name']) : '<span class="text-muted">—</span>' ?></td>
          <td>
            <?php if ($d['expiration_date']): ?>
              <span class="<?= $expiring ? 'text-danger fw-semibold' : '' ?>"><?= date('M j, Y', strtotime($d['expiration_date'])) ?></span>
            <?php else: ?><span class="text-muted">—</span><?php endif; ?>
          </td>
          <td class="text-nowrap"><?= date('M j, Y', strtotime($d['filed_at'])) ?></td>
          <td>
            <div class="d-flex flex-wrap gap-1">
              <a href="<?= h(document_url((int)$d['document_id'])) ?>" target="_blank" class="btn btn-sm btn-outline-brand" title="View"><i class="fa-solid fa-eye"></i></a>
              <?= document_action_buttons($d, current_user()) ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php
$page_url = fn(int $p): string => 'search_documents.php?' . http_build_query($filter_query + ['page' => $p]);
include __DIR__ . '/../includes/pagination.php';
?>

<script>
// Keep "From" no later than "To" while picking dates (the server checks it too)
(function () {
  var from = document.getElementById('fFrom'), to = document.getElementById('fTo');
  function sync() { to.min = from.value; from.max = to.value; }
  from.addEventListener('change', sync);
  to.addEventListener('change', sync);
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
