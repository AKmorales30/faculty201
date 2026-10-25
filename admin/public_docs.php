<?php
/**
 * Files > 201-Files (Admin): every faculty member's posted documents --
 * uploads that passed the classification review in Manage Document -- with
 * search, filters, sorting and paging. Archived documents can be included.
 * The "By faculty" tab is Faculty Records (admin/view_records.php).
 * Formerly Search Documents (admin/search_documents.php redirects here).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_role('admin');   // the only role that searches across faculty; others see only their own 201 file

$page_title = '201-Files';
$categories = document_categories();
const SEARCH_PER_PAGE = 25;
const NOT_SET = '__none';   // filter value for "program / employment type not set"

// Filter options from the values actually stored for people with a 201 file
$owner_roles = "u.role IN ('faculty', 'program_chair', 'dean')";
$program_options = [];
if (only_program() === null) {   // one program: the filter would change nothing, so it isn't offered
    foreach ($pdo->query("SELECT DISTINCT u.program FROM users u WHERE $owner_roles ORDER BY u.program")->fetchAll(PDO::FETCH_COLUMN) as $p) {
        $program_options[$p ?? NOT_SET] = $p === null ? 'Not set' : program_label($p);
    }
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
    $like = sql_like();
    $where[] = "(u.full_name $like ? OR d.ocr_extracted_text $like ? OR d.ocr_matched_name $like ? OR d.file_path $like ?
                 OR d.title $like ? OR d.conducted_by $like ? OR d.venue $like ?)";
    array_push($params, "%$q%", "%$q%", "%$q%", "%$q%", "%$q%", "%$q%", "%$q%");
}
if ($type !== '') {
    $where[] = "d.document_type = ?";
    $params[] = $type;
}
if ($expiring_only) {
    $where[] = "d.expiration_date IS NOT NULL AND d.expiration_date <= CURRENT_DATE + INTERVAL '60' DAY";
}
if ($use_dates && $from !== '') {
    $where[] = "d.filed_at >= ?";
    $params[] = $from . ' 00:00:00';
}
if ($use_dates && $to !== '') {
    $where[] = "d.filed_at < CAST(? AS DATE) + INTERVAL '1' DAY";
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
if ($active && $page === 1 && !live_search_request()) {
    log_my_activity($pdo, 'SEARCH', '201-Files -- ' . describe_filters([
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

<?php $flagged_n = documents_flagged_count($pdo); ?>
<?= admin_intro('Every faculty member\'s documents — uploads are posted here right away. Documents marked <span class="badge bg-review">Flagged</span> are also listed in Manage Document for checking. Open a row to preview the file and its details, or to correct its type.',
    '<a href="' . BASE_URL . '/admin/manage_documents.php" class="btn btn-outline-brand"><i class="fa-solid fa-flag" aria-hidden="true"></i> Manage Document' . ($flagged_n ? ' (' . $flagged_n . ' flagged)' : '') . '</a>') ?>
<?= admin_docs_tabs('list') ?>

<?php if ($date_error): ?>
  <div class="alert alert-warning"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i> <?= h($date_error) ?></div>
<?php endif; ?>

<section class="ui-card" aria-label="201-Files">
  <form method="GET" id="searchForm" class="ui-toolbar" data-autosubmit data-live-search role="search">
    <label class="ui-search" for="fQ">
      <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
      <span class="visually-hidden">Search title, faculty, organizer or extracted text</span>
      <input type="search" name="q" id="fQ" value="<?= h($q) ?>" class="ui-search-input" placeholder="Search title, faculty or text">
    </label>
    <label for="fType" class="visually-hidden">Document type</label>
    <select name="type" id="fType" class="form-select">
      <option value="">All types</option>
      <?php foreach ($categories as $key => $meta): ?>
        <option value="<?= h($key) ?>" <?= $type === $key ? 'selected' : '' ?>><?= h($meta['label']) ?></option>
      <?php endforeach; ?>
    </select>
    <?php if ($program_options): ?>
    <label for="fProgram" class="visually-hidden">Program</label>
    <select name="program" id="fProgram" class="form-select">
      <option value="">All programs</option>
      <?php foreach ($program_options as $key => $label): ?>
        <option value="<?= h($key) ?>" <?= $program === (string)$key ? 'selected' : '' ?>><?= h($label) ?></option>
      <?php endforeach; ?>
    </select>
    <?php endif; ?>
    <button class="btn btn-sm btn-outline-brand" type="button" data-bs-toggle="collapse" data-bs-target="#advancedFilters"
            aria-expanded="<?= $advanced_open ? 'true' : 'false' ?>" aria-controls="advancedFilters">
      <i class="fa-solid fa-sliders" aria-hidden="true"></i> Advanced<?= $advanced_count ? ' (' . $advanced_count . ')' : '' ?>
    </button>
    <button class="btn btn-sm btn-brand ui-apply" type="submit"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i> Search</button>
    <?php if ($active): ?><a href="public_docs.php" class="btn btn-sm btn-outline-secondary">Clear filters</a><?php endif; ?>
    <span class="ui-count"><?= $total ?> result<?= $total === 1 ? '' : 's' ?></span>

    <div class="collapse w-100 <?= $advanced_open ? 'show' : '' ?>" id="advancedFilters">
      <div class="ui-toolbar-row pt-2">
        <div>
          <label for="fFrom" class="form-label">Uploaded from</label>
          <input type="date" name="from" id="fFrom" value="<?= h($from) ?>" max="<?= h($to) ?>" class="form-control <?= $date_error ? 'is-invalid' : '' ?>">
        </div>
        <div>
          <label for="fTo" class="form-label">Uploaded to</label>
          <input type="date" name="to" id="fTo" value="<?= h($to) ?>" min="<?= h($from) ?>" class="form-control <?= $date_error ? 'is-invalid' : '' ?>">
        </div>
        <div>
          <label for="fEmployment" class="form-label">Employment type</label>
          <select name="employment" id="fEmployment" class="form-select">
            <option value="">All</option>
            <?php foreach ($employment_options as $key => $label): ?>
              <option value="<?= h($key) ?>" <?= $employment === (string)$key ? 'selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label for="fSort" class="form-label">Sort by</label>
          <select name="sort" id="fSort" class="form-select">
            <?php foreach ($sort_options as $key => [$label]): ?>
              <option value="<?= h($key) ?>" <?= $sort === $key ? 'selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="d-flex flex-column gap-1 pb-1">
          <div class="form-check">
            <input type="checkbox" name="expiring_only" id="expiringOnly" class="form-check-input" value="1" <?= $expiring_only ? 'checked' : '' ?>>
            <label for="expiringOnly" class="form-check-label">Expiring within 60 days</label>
          </div>
          <div class="form-check">
            <input type="checkbox" name="include_archived" id="includeArchived" class="form-check-input" value="1" <?= $include_archived ? 'checked' : '' ?>>
            <label for="includeArchived" class="form-check-label">Include archived documents</label>
          </div>
        </div>
      </div>
    </div>
    <?php if ($active): ?>
      <div class="ui-filter-note">
        <?= implode(' &middot; ', array_map(fn($label, $value) => h($label) . ': <strong>' . h($value) . '</strong>', array_keys($active), $active)) ?>
      </div>
    <?php endif; ?>
  </form>

  <div class="table-responsive">
    <table class="table align-middle">
      <thead><tr><th>File</th><th>Faculty</th><th>Type</th><th>Expiration</th><th>Uploaded</th><th class="text-end"><span class="visually-hidden">Actions</span></th></tr></thead>
      <tbody>
        <?php if (!$documents): ?>
          <?= admin_empty_row(6, $active ? 'No documents match' : 'No posted documents yet',
                $active ? 'Try a different search, or clear the filters to see everything.' : 'Documents appear here as soon as they are uploaded.',
                $active ? 'public_docs.php' : null) ?>
        <?php endif; ?>
        <?php foreach ($documents as $d):
          $expiring = $d['expiration_date'] && strtotime($d['expiration_date']) <= strtotime('+' . (int)EXPIRING_SOON_DAYS . ' days');
          $view_url = BASE_URL . '/api/modal.php?view=document&id=' . (int)$d['document_id'];
        ?>
        <tr class="row-clickable" tabindex="0" role="button" data-app-modal="<?= h($view_url) ?>" aria-label="Open <?= h(document_title($d)) ?>">
          <td class="doc-file-cell">
            <div class="fw-semibold"><?= h(document_title($d)) ?></div>
            <?php if (trim((string)$d['title']) !== ''): ?><div class="ui-cell-sub doc-file-name" title="<?= h(basename($d['file_path'])) ?>"><?= h(document_display_name($d['file_path'])) ?></div><?php endif; ?>
            <?php if ($d['ocr_matched_name']): ?><div class="ui-cell-sub">Name read: <?= h($d['ocr_matched_name']) ?></div><?php endif; ?>
            <?php if ($d['status'] === 'archived'): ?><div class="mt-1"><span class="badge bg-light">Archived</span></div><?php endif; ?>
            <?php if (document_is_flagged($d)): ?><div class="mt-1"><span class="badge bg-review" title="<?= h(ucfirst(document_flag_text($d['flag_reason']))) ?>"><i class="fa-solid fa-flag" aria-hidden="true"></i> Flagged for checking</span></div><?php endif; ?>
          </td>
          <td>
            <a href="faculty_documents.php?id=<?= (int)$d['faculty_id'] ?>" class="text-body fw-semibold"><?= h($d['full_name']) ?></a>
            <div class="ui-cell-sub"><?= h(implode(' · ', array_filter([program_label($d['program']), employment_type_label($d['employment_type'])]))) ?: '—' ?></div>
          </td>
          <td><?= h(document_type_label($d['document_type'], $d['document_subtype'])) ?><?php if ($p = document_period_label($d)): ?><div class="ui-cell-sub"><?= h($p) ?></div><?php endif; ?></td>
          <td class="text-nowrap">
            <?php if ($d['expiration_date']): ?>
              <span class="<?= $expiring ? 'text-danger fw-semibold' : '' ?>"><?= date('M j, Y', strtotime($d['expiration_date'])) ?></span>
            <?php else: ?><span class="text-muted">None</span><?php endif; ?>
          </td>
          <td class="text-nowrap"><?= date('M j, Y', strtotime($d['filed_at'])) ?></td>
          <td>
            <div class="ui-actions">
              <?= admin_icon_link(document_url((int)$d['document_id']), 'fa-eye', 'View details', 'target="_blank" data-app-modal="' . h($view_url) . '"') ?>
              <?= admin_icon_link(document_url((int)$d['document_id']), 'fa-download', 'Open file in a new tab', 'target="_blank" rel="noopener"') ?>
              <?= document_action_buttons($d, current_user()) ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="ui-card-foot">
    <span><?= h(admin_range_label($page, SEARCH_PER_PAGE, count($documents), $total)) ?></span>
    <?php
    $page_url = fn(int $p): string => 'public_docs.php?' . http_build_query($filter_query + ['page' => $p]);
    $pagination_labels = ['<i class="fa-solid fa-chevron-left" aria-hidden="true"></i><span class="visually-hidden">Previous page</span>', '<i class="fa-solid fa-chevron-right" aria-hidden="true"></i><span class="visually-hidden">Next page</span>'];
    include __DIR__ . '/../includes/pagination.php';
    ?>
  </div>
</section>

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
