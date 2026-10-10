<?php
/**
 * Seminar & Training Report -- Admin, Program Chairs and Deans.
 *
 * One row per seminar / training / certificate (Certificates category)
 * with its title, dates, venue, organizer, type / level and hours -- text
 * details only, never the certificate itself. Summary counts per faculty,
 * per organization and per year on top; rows grouped by faculty when "All
 * faculty" is selected. Print / Save as PDF (with a report header) and CSV
 * export of the same rows. Every generation is written to the activity log.
 *
 * Whose certificates: profile_scope_sql() -- the Admin everyone with a
 * 201 file, a Dean their college's faculty and Program Chairs, a Program
 * Chair their program's faculty (each also their own).
 * Archived documents are left out unless "Include archived" is ticked.
 * Certificates without details show "Not specified"; their owner or the
 * Admin can fill them in (document_details.php).
 *
 * Generate AI Summary (includes/ai_report.php): a written overview, key
 * findings, faculty needing attention and recommendations from Gemini,
 * shown above the table and printed with it -- never in the CSV. Saved per
 * viewer and filters; Regenerate asks Gemini again.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/ai_report.php';
require_role(['admin', 'program_chair', 'dean']);

$me = current_user();
$page_title = 'Seminar & Training Report';
$subtypes = document_categories()['Certificate']['subtypes'];
const TYPE_NOT_SET = '__none';

// Faculty this viewer may report on
[$scope, $scope_params] = profile_scope_sql($pdo, $me);
$stmt = $pdo->prepare(
    "SELECT u.user_id, u.full_name, u.role, u.employment_type, u.program, u.college, u.is_active
     FROM users u WHERE $scope AND u.role IN ('faculty', 'program_chair', 'dean') ORDER BY u.full_name"
);
$stmt->execute($scope_params);
$faculty_list = array_column($stmt->fetchAll(), null, 'user_id');

// Program filter: the Admin any program, a Dean their college's programs
// (a Program Chair already sees one program only)
$program_options = [];
if ($me['role'] !== 'program_chair') {
    $viewer_college = $me['role'] === 'dean' ? (user_row($pdo, (int)$me['user_id'])['college'] ?? '') : null;
    foreach (PROGRAMS as $key => $p) {
        if ($viewer_college === null || $p['college'] === $viewer_college) { $program_options[$key] = $p['label']; }
    }
}

// Filters (all optional). Anything invalid is ignored rather than erroring.
$valid_date = function (string $d): string {
    $dt = DateTime::createFromFormat('!Y-m-d', $d);
    return ($dt && $dt->format('Y-m-d') === $d) ? $d : '';
};
$faculty_id = (int)($_GET['faculty'] ?? 0);
$from       = $valid_date(trim($_GET['from'] ?? ''));
$to         = $valid_date(trim($_GET['to'] ?? ''));
$org        = mb_substr(trim($_GET['org'] ?? ''), 0, 100);
$type       = $_GET['type'] ?? '';
$category   = $_GET['category'] ?? '';
$include_archived = !empty($_GET['include_archived']);
$program    = $_GET['program'] ?? '';
if (!isset($program_options[$program])) { $program = ''; }
if (!isset($faculty_list[$faculty_id])) { $faculty_id = 0; }
if ($type !== TYPE_NOT_SET && !in_array($type, TRAINING_TYPES, true)) { $type = ''; }
if (!isset($subtypes[$category])) { $category = ''; }
if ($from !== '' && $to !== '' && $from > $to) { [$from, $to] = [$to, $from]; }

// ---------------------------------------------------------------------
// The rows: every value bound, the scope from profile_scope_sql()
// ---------------------------------------------------------------------
$where = ["d.document_type = 'Certificate'", $include_archived ? "d.status IN ('active', 'archived')" : "d.status = 'active'",
          $scope, "u.role IN ('faculty', 'program_chair', 'dean')"];
$params = $scope_params;
if ($faculty_id)       { $where[] = "d.faculty_id = ?"; $params[] = $faculty_id; }
if ($program !== '')   { $where[] = "u.program = ?"; $params[] = $program; }
if ($from !== '')      { $where[] = "COALESCE(d.date_end, d.date_start) >= ?"; $params[] = $from; }
if ($to !== '')        { $where[] = "d.date_start <= ?"; $params[] = $to; }
if ($org !== '')       { $where[] = "d.conducted_by LIKE ?"; $params[] = '%' . $org . '%'; }
if ($type === TYPE_NOT_SET) { $where[] = "d.training_type IS NULL"; }
elseif ($type !== '')  { $where[] = "d.training_type = ?"; $params[] = $type; }
if ($category !== '')  { $where[] = "d.document_subtype = ?"; $params[] = $category; }

$stmt = $pdo->prepare(
    "SELECT d.document_id, d.faculty_id, d.document_subtype, d.file_path, d.status, d.filed_at,
            d.title, d.date_start, d.date_end, d.venue, d.conducted_by, d.training_type, d.training_level, d.hours,
            u.full_name, u.role, u.employment_type, u.program, u.college
     FROM documents d JOIN users u ON u.user_id = d.faculty_id
     WHERE " . implode(' AND ', $where) . "
     ORDER BY u.full_name, u.user_id, d.date_start IS NULL, d.date_start DESC, d.document_id DESC"
);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Organizations already recorded in scope, for the filter's suggestions
$stmt = $pdo->prepare(
    "SELECT DISTINCT d.conducted_by FROM documents d JOIN users u ON u.user_id = d.faculty_id
     WHERE d.document_type = 'Certificate' AND d.status IN ('active', 'archived') AND d.conducted_by IS NOT NULL AND $scope
     ORDER BY d.conducted_by LIMIT 200"
);
$stmt->execute($scope_params);
$known_orgs = $stmt->fetchAll(PDO::FETCH_COLUMN);

// ---------------------------------------------------------------------
// Summary counts, from the same rows so they always match the list
// ---------------------------------------------------------------------
$NS = 'Not specified';
$groups = [];
$by_org = [];
$org_names = [];   // lowercase key => the first spelling seen
$by_year = [];
$total_hours = 0.0;
$missing = 0;
foreach ($rows as $r) {
    $fid = (int)$r['faculty_id'];
    $groups[$fid] ??= ['name' => $r['full_name'], 'user' => $r, 'rows' => [], 'hours' => 0.0];
    $groups[$fid]['rows'][] = $r;
    $groups[$fid]['hours'] += (float)$r['hours'];
    $total_hours += (float)$r['hours'];

    $org_key = $r['conducted_by'] !== null ? mb_strtolower(trim($r['conducted_by'])) : '';
    $org_names[$org_key] ??= $r['conducted_by'] ?? $NS;
    $by_org[$org_key] = ($by_org[$org_key] ?? 0) + 1;

    $year = $r['date_start'] ? substr($r['date_start'], 0, 4) : $NS;
    $by_year[$year] = ($by_year[$year] ?? 0) + 1;

    if (trim((string)$r['title']) === '' || !$r['date_start'] || trim((string)$r['conducted_by']) === '') { $missing++; }
}
arsort($by_org);
uksort($by_year, fn($a, $b) => $a === $NS ? 1 : ($b === $NS ? -1 : strcmp($b, $a)));   // newest year first, "Not specified" last

// The filters in force, in words: report header, CSV name and activity log
$applied = [];
$applied[] = 'Faculty: ' . ($faculty_id ? $faculty_list[$faculty_id]['full_name'] : 'All faculty');
if ($program !== '')  { $applied[] = 'Program: ' . $program_options[$program]; }
if ($from !== '' || $to !== '') {
    $applied[] = 'Seminar date: ' . ($from !== '' ? date('M j, Y', strtotime($from)) : 'any') . ' to ' . ($to !== '' ? date('M j, Y', strtotime($to)) : 'any');
}
if ($org !== '')      { $applied[] = 'Conducted by: contains "' . $org . '"'; }
if ($type !== '')     { $applied[] = 'Type: ' . ($type === TYPE_NOT_SET ? $NS : $type); }
if ($category !== '') { $applied[] = 'Category: Certificates > ' . $subtypes[$category]; }
$applied[] = $include_archived ? 'Archived documents included' : 'Archived documents excluded';

$filter_query = array_filter(['faculty' => $faculty_id ?: '', 'program' => $program, 'from' => $from, 'to' => $to, 'org' => $org, 'type' => $type,
                              'category' => $category, 'include_archived' => $include_archived ? '1' : ''], fn($v) => $v !== '');

$log_report = function (string $format) use ($pdo, $applied, $rows, $groups) {
    log_my_activity($pdo, 'GENERATE_REPORT', "Seminar & Training report ({$format}) -- " . implode('; ', $applied)
        . ' (' . count($rows) . ' seminar' . (count($rows) === 1 ? '' : 's') . '/training' . (count($rows) === 1 ? '' : 's')
        . ', ' . count($groups) . ' faculty).');
};
// Printing happens in the browser; the page posts here when the print dialog opens
if (($_POST['action'] ?? '') === 'log_print') {
    if (csrf_valid()) { $log_report('printed / saved as PDF'); }
    http_response_code(204);
    exit;
}

// ---------------------------------------------------------------------
// AI summary: Generate / Regenerate (POST + CSRF), then back to this report
// ---------------------------------------------------------------------
$ai_hash = ai_report_hash((int)$me['user_id'], $filter_query);
if (($_POST['action'] ?? '') === 'ai_summary') {
    if (!csrf_valid()) {
        $_SESSION['flash_error'] = 'Your session expired before the form was sent. Please try again.';
    } else {
        // Everyone the report covers: the faculty chosen, else everyone in scope (and program); inactive accounts only when chosen
        $covered = array_filter($faculty_list, fn($f) => $faculty_id ? (int)$f['user_id'] === $faculty_id
            : $f['is_active'] && ($program === '' || $f['program'] === $program));
        session_write_close();   // Gemini can take a few seconds; don't hold up the user's other tabs
        set_time_limit(120);
        $summary = ai_enabled() ? ai_report_generate($pdo, (int)$me['user_id'], ai_report_data($pdo, $covered, $rows, $applied, $from, $to, $include_archived)) : null;
        session_start();
        if ($summary) {
            ai_report_save($pdo, (int)$me['user_id'], ['filters' => $filter_query, 'applied' => $applied], $ai_hash, $summary);
            log_my_activity($pdo, 'AI_SUMMARY', 'AI summary of the Seminar & Training report -- ' . implode('; ', $applied) . '.');
        } else {
            $_SESSION['ai_summary_failed'] = true;
        }
    }
    header('Location: ' . BASE_URL . '/training_report.php?' . http_build_query($filter_query) . '#ai-summary');
    exit;
}
$ai_saved = ai_report_latest($pdo, $ai_hash);
$ai_failed = !empty($_SESSION['ai_summary_failed']);
unset($_SESSION['ai_summary_failed']);
$ai_on = ai_enabled();

$hours_label = fn($h): string => $h === null || $h === '' ? '' : (string)(float)$h;
$position_of = fn(array $r): string => user_position_label($r) . (isset(PROGRAMS[$r['program'] ?? '']) ? ' · ' . $r['program'] : '');

// ---------------------------------------------------------------------
// CSV export: the same rows and columns
// ---------------------------------------------------------------------
if (($_GET['export'] ?? '') === 'csv') {
    $log_report('exported to CSV');
    $cell = fn(string $v): string => preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v;   // no formulas in Excel
    $or_ns = fn($v): string => trim((string)$v) !== '' ? (string)$v : 'Not specified';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="seminar_training_report_' . date('Y-m-d') . '.csv"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");   // BOM so Excel reads UTF-8 names correctly
    fputcsv($out, ['Faculty Name', 'Position', 'Seminar/Training Title', 'Date From', 'Date To', 'Venue', 'Conducted/Organized By',
                   'Type', 'Level', 'Hours', 'Category', 'Status']);
    foreach ($rows as $r) {
        fputcsv($out, array_map($cell, [
            $r['full_name'], $position_of($r), $or_ns($r['title']), $or_ns($r['date_start']), $or_ns($r['date_end']),
            $or_ns($r['venue']), $or_ns($r['conducted_by']), $or_ns($r['training_type']), $or_ns($r['training_level']),
            $or_ns($hours_label($r['hours'])), document_type_label('Certificate', $r['document_subtype']), ucfirst($r['status']),
        ]));
    }
    fclose($out);
    exit;
}
$generated = isset($_GET['generate']);
if ($generated) {   // the Generate button (opening the page alone isn't logged)
    $log_report('on screen');
}

$ns_html = '<span class="text-muted fst-italic">Not specified</span>';
$show = fn($v): string => trim((string)$v) !== '' ? h((string)$v) : $ns_html;
$all_faculty = !$faculty_id;
$viewer = user_row($pdo, (int)$me['user_id']);

include __DIR__ . '/includes/header.php';
?>

<!-- Shown only when printing / saving as PDF -->
<div class="report-print-header d-none d-print-block">
  <div class="fw-bold">CCS Faculty 201-File Repository</div>
  <div class="small">College of Computing Studies, Universidad de Manila</div>
  <h4 class="fw-bold mt-2 mb-1">Faculty Seminar &amp; Training Report</h4>
  <div class="small">Filters: <?= h(implode('; ', $applied)) ?></div>
  <div class="small">Generated: <?= h(date('F j, Y g:i A')) ?> by <?= h($me['full_name']) ?> (<?= h(user_position_label($viewer)) ?>)</div>
</div>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-4 no-print">
  <div>
    <h3 class="fw-bold mb-1">Seminar &amp; Training Report</h3>
    <p class="text-muted mb-0">Every seminar, training and certificate in the faculty 201 files, with its details. Print it, save it as PDF or export it to CSV.
      <?php if ($me['role'] === 'program_chair'): ?>You see the faculty of your program.<?php elseif ($me['role'] === 'dean'): ?>You see the faculty and Program Chairs of your college.<?php endif; ?></p>
  </div>
  <div class="d-flex gap-2">
    <button type="button" class="btn btn-outline-brand" onclick="window.print()"><i class="fa-solid fa-print"></i> Print / Save as PDF</button>
    <a href="training_report.php?<?= h(http_build_query($filter_query + ['export' => 'csv'])) ?>" class="btn btn-brand"><i class="fa-solid fa-file-csv"></i> Export CSV</a>
  </div>
</div>

<div class="card stat-card mb-4 no-print">
  <div class="card-body">
    <form method="GET" class="row g-2 align-items-end" id="reportForm">
      <input type="hidden" name="generate" value="1">
      <div class="col-md-6 col-xl-4">
        <label for="fFaculty" class="form-label small fw-semibold">Faculty</label>
        <input type="search" id="fFacultySearch" class="form-control form-control-sm mb-1" placeholder="Type to search names..." aria-label="Search faculty names" autocomplete="off">
        <select name="faculty" id="fFaculty" class="form-select">
          <option value="">All faculty (<?= count($faculty_list) ?>)</option>
          <?php foreach ($faculty_list as $f): ?>
            <option value="<?= (int)$f['user_id'] ?>" <?= $faculty_id === (int)$f['user_id'] ? 'selected' : '' ?>>
              <?= h($f['full_name']) ?><?= $f['role'] !== 'faculty' ? ' (' . h(user_position_label($f)) . ')' : '' ?><?= $f['is_active'] ? '' : ' (inactive)' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php if ($program_options): ?>
      <div class="col-md-6 col-xl-2">
        <label for="fProgram" class="form-label small fw-semibold">Program</label>
        <select name="program" id="fProgram" class="form-select">
          <option value="">All programs</option>
          <?php foreach ($program_options as $key => $label): ?>
            <option value="<?= h($key) ?>" <?= $program === $key ? 'selected' : '' ?>><?= h($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <div class="col-6 col-md-3 col-xl-2">
        <label for="fFrom" class="form-label small fw-semibold">Seminar Date From</label>
        <input type="date" name="from" id="fFrom" value="<?= h($from) ?>" class="form-control">
      </div>
      <div class="col-6 col-md-3 col-xl-2">
        <label for="fTo" class="form-label small fw-semibold">Seminar Date To</label>
        <input type="date" name="to" id="fTo" value="<?= h($to) ?>" class="form-control">
      </div>
      <div class="col-md-6 col-xl-4">
        <label for="fOrg" class="form-label small fw-semibold">Conducted / Organized By</label>
        <input type="search" name="org" id="fOrg" value="<?= h($org) ?>" class="form-control" list="orgList" placeholder="e.g. DICT, CHED, UDM">
        <datalist id="orgList"><?php foreach ($known_orgs as $o): ?><option value="<?= h($o) ?>"><?php endforeach; ?></datalist>
      </div>
      <div class="col-6 col-md-3 col-xl-2">
        <label for="fType" class="form-label small fw-semibold">Type</label>
        <select name="type" id="fType" class="form-select">
          <option value="">All types</option>
          <?php foreach (TRAINING_TYPES as $t): ?>
            <option <?= $type === $t ? 'selected' : '' ?>><?= h($t) ?></option>
          <?php endforeach; ?>
          <option value="<?= TYPE_NOT_SET ?>" <?= $type === TYPE_NOT_SET ? 'selected' : '' ?>>Not specified</option>
        </select>
      </div>
      <div class="col-6 col-md-3 col-xl-2">
        <label for="fCategory" class="form-label small fw-semibold">Category</label>
        <select name="category" id="fCategory" class="form-select">
          <option value="">All certificates</option>
          <?php foreach ($subtypes as $key => $label): ?>
            <option value="<?= h($key) ?>" <?= $category === $key ? 'selected' : '' ?>><?= h($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-6 col-xl-4 d-flex align-items-center">
        <div class="form-check mt-md-4">
          <input type="checkbox" name="include_archived" value="1" id="fArchived" class="form-check-input" <?= $include_archived ? 'checked' : '' ?>>
          <label for="fArchived" class="form-check-label small">Include archived (more than <?= (int)ARCHIVE_AFTER_YEARS ?> years old)</label>
        </div>
      </div>
      <div class="col-md-6 col-xl-4 d-flex gap-2">
        <button class="btn btn-brand flex-fill"><i class="fa-solid fa-file-lines"></i> Generate</button>
        <a href="training_report.php" class="btn btn-outline-secondary flex-fill">Reset</a>
      </div>
      <?php if ($from !== '' || $to !== ''): ?>
        <div class="col-12 small text-muted">Seminars without a date can't match a date range, so they are left out while one is set.</div>
      <?php endif; ?>
    </form>
  </div>
</div>

<?php if ($ai_saved || $ai_on || $ai_failed): ?>
<div class="card stat-card mb-4 ai-summary-card <?= $ai_saved ? '' : 'no-print' ?>" id="ai-summary">
  <div class="card-header bg-white fw-semibold d-flex flex-wrap justify-content-between align-items-center gap-2">
    <span><i class="fa-solid fa-wand-magic-sparkles text-brand"></i> AI Summary</span>
    <?php if ($ai_on): ?>
      <form method="POST" action="training_report.php?<?= h(http_build_query($filter_query)) ?>" class="m-0 no-print ai-summary-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="ai_summary">
        <button class="btn btn-sm <?= $ai_saved ? 'btn-outline-brand' : 'btn-brand' ?>">
          <i class="fa-solid <?= $ai_saved ? 'fa-rotate' : 'fa-wand-magic-sparkles' ?>"></i> <?= $ai_saved ? 'Regenerate' : 'Generate AI Summary' ?>
        </button>
      </form>
    <?php endif; ?>
  </div>
  <div class="card-body">
    <?php if ($ai_failed): ?>
      <div class="alert alert-warning py-2 mb-3 no-print"><i class="fa-solid fa-triangle-exclamation"></i> AI summary is unavailable right now. The report below is complete; please try again later.</div>
    <?php endif; ?>
    <?php if ($ai_saved): ?>
      <?= ai_report_html($ai_saved['summary']) ?>
      <div class="small text-muted mt-3">Generated <?= h(date('F j, Y g:i A', strtotime($ai_saved['created_at']))) ?> from the report data at that time<?= $ai_on ? '<span class="no-print"> -- press Regenerate after records change</span>' : '' ?>.</div>
      <?= ai_disclaimer_html('mt-1') ?>
    <?php elseif (!$ai_failed): ?>
      <p class="text-muted small mb-0">Get a written overview of this report -- key findings, faculty needing attention and recommendations -- from the numbers below, for the filters selected.
        Only names, counts, dates, seminar titles and organizers are sent to the AI; never ID numbers, contact details or files.</p>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<div class="row g-3 mb-3">
  <div class="col-6 col-md-3">
    <div class="card stat-card p-3 h-100"><div class="text-muted small">Seminars / Trainings</div><div class="stat-number text-brand"><?= count($rows) ?></div></div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card stat-card p-3 h-100"><div class="text-muted small">Faculty</div><div class="stat-number text-accent-teal"><?= count($groups) ?></div></div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card stat-card p-3 h-100"><div class="text-muted small">Total Hours (where given)</div><div class="stat-number text-accent-gold"><?= h($hours_label($total_hours)) ?></div></div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card stat-card p-3 h-100"><div class="text-muted small">Missing Details</div><div class="stat-number <?= $missing ? 'text-danger' : 'text-accent-teal' ?>"><?= $missing ?></div></div>
  </div>
</div>
<?php if ($missing): ?>
  <p class="small text-muted mb-3 no-print"><i class="fa-solid fa-circle-info"></i> <?= $missing ?> certificate<?= $missing === 1 ? ' is' : 's are' ?> missing a title, date or organizer and show<?= $missing === 1 ? 's' : '' ?> "Not specified".
    The faculty member<?= $me['role'] === 'admin' ? ' or you' : ' or the Admin' ?> can fill them in with <i class="fa-solid fa-pen-to-square"></i> Fill in.</p>
<?php endif; ?>

<?php if ($rows): ?>
<div class="row g-3 mb-4">
  <div class="col-lg-4">
    <div class="card stat-card h-100 report-category-counts">
      <div class="card-header bg-white fw-semibold">Per Faculty</div>
      <div class="card-body p-0 table-responsive">
        <table class="table table-sm mb-0 align-middle">
          <thead class="table-light"><tr><th class="ps-3">Faculty</th><th class="text-end">Count</th><th class="text-end pe-3">Hours</th></tr></thead>
          <tbody>
            <?php foreach ($groups as $g): ?>
              <tr><td class="ps-3"><?= h($g['name']) ?></td><td class="text-end fw-semibold"><?= count($g['rows']) ?></td><td class="text-end pe-3"><?= h($hours_label($g['hours'])) ?></td></tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="card stat-card h-100 report-category-counts">
      <div class="card-header bg-white fw-semibold">Per Organization (Conducted By)</div>
      <div class="card-body p-0 table-responsive">
        <table class="table table-sm mb-0 align-middle">
          <tbody>
            <?php foreach ($by_org as $key => $c): ?>
              <tr><td class="ps-3 <?= $key === '' ? 'text-muted fst-italic' : '' ?>"><?= h($org_names[$key]) ?></td><td class="text-end pe-3 fw-semibold"><?= (int)$c ?></td></tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div class="col-lg-3">
    <div class="card stat-card h-100 report-category-counts">
      <div class="card-header bg-white fw-semibold">Per Year</div>
      <div class="card-body p-0 table-responsive">
        <table class="table table-sm mb-0 align-middle">
          <tbody>
            <?php foreach ($by_year as $y => $c): ?>
              <tr><td class="ps-3 <?= $y === $NS ? 'text-muted fst-italic' : '' ?>"><?= h((string)$y) ?></td><td class="text-end pe-3 fw-semibold"><?= (int)$c ?></td></tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="card stat-card">
  <div class="card-header bg-white fw-semibold">
    <?= $all_faculty ? 'All faculty' : h($faculty_list[$faculty_id]['full_name']) . ' <span class="text-muted fw-normal small">· ' . h(user_position_line($faculty_list[$faculty_id])) . '</span>' ?>
    — <?= count($rows) ?> seminar<?= count($rows) === 1 ? '' : 's' ?> / training<?= count($rows) === 1 ? '' : 's' ?>
  </div>
  <div class="card-body p-0 table-responsive">
    <table class="table mb-0 align-middle report-table">
      <thead class="table-light">
        <tr><th>#</th><th>Seminar / Training Title</th><th>Date(s)</th><th>Venue</th><th>Conducted / Organized By</th><th>Type / Level</th><th class="text-end">Hours</th><th class="no-print"></th></tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="8" class="text-center text-muted py-4">
            <?= $faculty_list ? 'No seminars or trainings found for the selected filters.' : 'There are no faculty for you to report on yet' . ($me['role'] !== 'admin' ? ' -- ask the Admin to set your ' . ($me['role'] === 'dean' ? 'college' : 'program') . '.' : '.') ?>
          </td></tr>
        <?php endif; ?>
        <?php foreach ($groups as $fid => $g): ?>
          <?php if ($all_faculty): ?>
            <tr class="report-group-row">
              <th colspan="8" class="bg-light">
                <?= h($g['name']) ?> <span class="fw-normal text-muted small">· <?= h($position_of($g['user'])) ?> · <?= count($g['rows']) ?> seminar<?= count($g['rows']) === 1 ? '' : 's' ?>/training<?= count($g['rows']) === 1 ? '' : 's' ?><?= $g['hours'] ? ' · ' . h($hours_label($g['hours'])) . ' hours' : '' ?></span>
              </th>
            </tr>
          <?php endif; ?>
          <?php foreach ($g['rows'] as $i => $r): ?>
          <tr>
            <td class="text-muted small"><?= $i + 1 ?></td>
            <td class="text-break">
              <?= $show($r['title']) ?>
              <?php if (trim((string)$r['title']) === ''): ?><div class="small text-muted no-print"><?= h(document_display_name($r['file_path'])) ?></div><?php endif; ?>
              <?php if ($r['status'] === 'archived'): ?><span class="badge bg-light text-dark border ms-1">Archived</span><?php endif; ?>
            </td>
            <td class="text-nowrap small"><?= $r['date_start'] ? h(document_date_range_label($r['date_start'], $r['date_end'])) : $ns_html ?></td>
            <td class="small"><?= $show($r['venue']) ?></td>
            <td class="small"><?= $show($r['conducted_by']) ?></td>
            <td class="small"><?= training_type_label($r) !== '' ? h(training_type_label($r)) : $ns_html ?></td>
            <td class="text-end small"><?= $r['hours'] !== null ? h($hours_label($r['hours'])) : $ns_html ?></td>
            <td class="no-print text-nowrap">
              <?php if (can_edit_document_details($me, $r)): ?>
                <a href="<?= BASE_URL ?>/document_details.php?id=<?= (int)$r['document_id'] ?>&amp;return=<?= h(urlencode('training_report.php?' . http_build_query($filter_query + ['generate' => 1]))) ?>"
                   class="btn btn-sm btn-outline-brand" title="Edit the details of this certificate">
                  <i class="fa-solid fa-pen-to-square"></i> <?= trim((string)$r['title']) === '' || !$r['date_start'] ? 'Fill in' : 'Edit' ?>
                </a>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
// Searchable faculty dropdown: typing narrows the list (the select still works without it)
(function () {
  var search = document.getElementById('fFacultySearch'), select = document.getElementById('fFaculty');
  search.addEventListener('input', function () {
    var terms = search.value.toLowerCase().split(/\s+/).filter(Boolean), first = null;
    Array.prototype.forEach.call(select.options, function (o) {
      if (!o.value) return;   // "All faculty" always stays
      var ok = terms.every(function (t) { return o.text.toLowerCase().indexOf(t) !== -1; });
      o.hidden = !ok;
      if (ok && !first) first = o;
    });
    var sel = select.selectedOptions[0];   // pick the first match unless a matching name is already chosen
    if (terms.length && first && (!sel || !sel.value || sel.hidden)) { select.value = first.value; }
  });
  // Keep "From" no later than "To" while picking dates (the server checks it too)
  var from = document.getElementById('fFrom'), to = document.getElementById('fTo');
  function sync() { to.min = from.value; from.max = to.value; }
  from.addEventListener('change', sync); to.addEventListener('change', sync); sync();
})();
// AI summary: show progress while Gemini writes it (a few seconds), and stop double submits
document.querySelectorAll('.ai-summary-form').forEach(function (f) {
  f.addEventListener('submit', function () {
    var b = f.querySelector('button');
    b.disabled = true;
    b.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Generating...';
  });
});
// Activity log: record that the report was printed / saved as PDF (button or Ctrl+P), once per page view
(function () {
  var logged = false;
  window.addEventListener('beforeprint', function () {
    if (logged) return;
    logged = true;
    var body = new FormData();
    body.append('action', 'log_print');
    body.append('csrf_token', <?= json_encode(csrf_token()) ?>);
    var url = 'training_report.php?' + <?= json_encode(http_build_query($filter_query)) ?>;
    if (navigator.sendBeacon) { navigator.sendBeacon(url, body); }
    else { fetch(url, { method: 'POST', body: body, credentials: 'same-origin', keepalive: true }); }
  });
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
