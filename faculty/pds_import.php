<?php
/**
 * Import an uploaded PDS file into the digital PDS -- review, then save.
 *
 * Opened right after a PDS is uploaded (faculty/submit_document.php), or
 * later from the PDS page / 201 file for any uploaded PDS document. Shows,
 * section by section, what was read from the file (includes/pds_import.php)
 * next to what the digital PDS holds now:
 *   - empty fields in the PDS are ticked to be filled in;
 *   - fields that already hold a different value are highlighted, and keep
 *     the current value unless "Use new" is chosen -- nothing is overwritten
 *     silently;
 *   - table rows already in the PDS (same title / school / position and
 *     dates) are shown as "Already in PDS" and left unticked;
 *   - values the reader wasn't sure of are marked "Check";
 *   - every value can be edited and every row unticked.
 * Only sections found in the file appear, and saving only fills in or adds
 * -- sections that weren't in the file are never touched.
 *
 * Faculty (and Program Chairs / Deans) import into their own PDS only; the
 * Admin can import any faculty member's uploaded PDS. POST + CSRF token,
 * plus a one-time code per review so a resubmitted page can't add rows twice.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/pds_import.php';
require_role(['faculty', 'program_chair', 'dean', 'admin']);

$me = current_user();
$is_admin = $me['role'] === 'admin';
$document_id = (int)($_POST['document_id'] ?? $_GET['document'] ?? 0);

$stmt = $pdo->prepare(
    "SELECT d.document_id, d.faculty_id, d.document_type, d.file_path, d.filed_at, d.status, d.ocr_extracted_text, u.full_name, u.role AS owner_role
     FROM documents d JOIN users u ON u.user_id = d.faculty_id
     WHERE d.document_id = ? AND d.status IN ('active', 'archived')"
);
$stmt->execute([$document_id]);
$doc = $stmt->fetch();
if (!$doc || $doc['document_type'] !== 'PDS' || !in_array($doc['owner_role'], ['faculty', 'program_chair', 'dean'], true)
    || (!$is_admin && (int)$doc['faculty_id'] !== (int)$me['user_id'])) {
    $_SESSION['flash_error'] = 'You can only import a PDS file from your own 201 file.';
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}
$faculty_id = (int)$doc['faculty_id'];
$is_own = $faculty_id === (int)$me['user_id'];
$back = $is_own ? 'faculty/pds.php' : 'admin/faculty_documents.php?id=' . $faculty_id . '&type=PDS';
$self = 'pds_import.php?document=' . $document_id;

/** Read the stored document (the database copy when the disk copy is gone). */
function pds_import_read_document(PDO $pdo, array $doc): array {
    $path = ROOT_PATH . '/' . $doc['file_path'];
    if (is_file($path)) {
        return pds_import_extract_file($path, $doc['ocr_extracted_text']);
    }
    $stmt = $pdo->prepare("SELECT data FROM document_files WHERE document_id = ?");
    $stmt->execute([$doc['document_id']]);
    $bytes = $stmt->fetchColumn();
    if ($bytes === false) { return pds_import_empty('text'); }
    if (!is_dir(TEMP_SCAN_PATH)) { @mkdir(TEMP_SCAN_PATH, 0775, true); }
    $tmp = TEMP_SCAN_PATH . '/pdsimport_' . bin2hex(random_bytes(6)) . '.' . strtolower(pathinfo($doc['file_path'], PATHINFO_EXTENSION));
    file_put_contents($tmp, $bytes);
    try {
        return pds_import_extract_file($tmp, $doc['ocr_extracted_text']);
    } finally {
        @unlink($tmp);
    }
}

// The reading (slow for scans) is kept for this review, with a one-time code
$cache = $_SESSION['pds_import'][$document_id] ?? null;
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && ($cache === null || isset($_GET['refresh']))) {
    $cache = ['ex' => pds_import_read_document($pdo, $doc), 'nonce' => bin2hex(random_bytes(12))];
    $_SESSION['pds_import'][$document_id] = $cache;
    if (isset($_GET['refresh'])) { header('Location: ' . BASE_URL . '/faculty/' . $self); exit; }
}

// ---------------------------------------------------------------------
// Save what was confirmed
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fail = function (string $message) use ($self) {
        $_SESSION['flash_error'] = $message;
        header('Location: ' . BASE_URL . '/faculty/' . $self);
        exit;
    };
    if (!csrf_valid()) { $fail('Your session expired before the form was sent. Please try again.'); }
    if (!$cache || !hash_equals($cache['nonce'], (string)($_POST['nonce'] ?? ''))) {
        $_SESSION['flash_error'] = 'This review was already saved (or has expired). Nothing was added twice.';
        header('Location: ' . BASE_URL . '/' . $back);
        exit;
    }
    // PHP drops fields past max_input_vars: a cut-off form would import only part of what was ticked
    if (empty($_POST['form_complete'])) { $fail('The review is too large to save in one go on this server (PHP max_input_vars). Untick some rows and try again.'); }

    $result = pds_import_apply($pdo, $faculty_id, $document_id, $_POST);
    unset($_SESSION['pds_import'][$document_id]);
    $notes = [];
    if ($result['invalid']) { $notes[] = $result['invalid'] . ' value' . ($result['invalid'] === 1 ? ' was' : 's were') . ' not valid (e.g. a date) and left out.'; }
    if ($result['over']) { $notes[] = 'The form has room for 3 references, so ' . $result['over'] . ' more were left out.'; }
    if (!$result['sections']) {
        $_SESSION['flash_success'] = 'Nothing was changed in the PDS.' . ($notes ? ' ' . implode(' ', $notes) : '');
        header('Location: ' . BASE_URL . '/' . $back);
        exit;
    }
    $summary = pds_import_summary($result['sections']);
    log_my_activity($pdo, 'PDS_IMPORT', "Imported uploaded PDS document #{$document_id} \"" . basename($doc['file_path']) . '" into the digital PDS of '
        . $doc['full_name'] . " -- {$summary}." . ($notes ? ' ' . implode(' ', $notes) : ''));
    if (!$is_own) {
        notify($pdo, $faculty_id, "The Admin updated your digital PDS from your uploaded PDS file: {$summary}.");
    }
    $_SESSION['flash_success'] = "Updated: {$summary}." . ($notes ? ' ' . implode(' ', $notes) : '')
        . ($is_own ? ' Review your PDS and save it to complete any missing parts.' : '');
    header('Location: ' . BASE_URL . '/' . $back);
    exit;
}

// ---------------------------------------------------------------------
// Review page
// ---------------------------------------------------------------------
$ex = $cache['ex'];
$pds = pds_load($pdo, $faculty_id);
$current = $pds['data'];
$existing = pds_import_existing_keys($pds);
$defs = pds_import_field_defs();
$sections = array_intersect_key(pds_import_sections(), $ex['sections']);
$same = fn($a, $b) => mb_strtolower(trim((string)$a)) === mb_strtolower(trim((string)$b));
$source_label = [
    'xlsx' => ['Read from the Excel workbook, cell by cell.', 'alert-success'],
    'text' => ['Read from the text of the file. Check the highlighted values.', 'alert-info'],
    'ocr'  => ['Read with OCR from a scan or photo. OCR makes mistakes -- please check every value marked "Check" against the document.', 'alert-warning'],
][$ex['source']];

/** An input for an extracted value: a list for choices, a date picker for dates, text otherwise. */
function pds_import_input(string $name, array $def, string $value, bool $unsure, string $aria): string {
    [, $type, $options] = $def;
    $cls = $unsure ? ' border-warning border-2' : '';
    $attr = ' name="' . h($name) . '" aria-label="' . h($aria) . '"';
    if ($type === 'select' || $type === 'yesno') {
        $options = $type === 'yesno' ? ['Yes', 'No'] : $options;
        $html = '<select class="form-select form-select-sm' . $cls . '"' . $attr . '><option value=""></option>';
        foreach ($options as $o) { $html .= '<option' . ($o === $value ? ' selected' : '') . '>' . h($o) . '</option>'; }
        return $html . '</select>';
    }
    $input_type = $type === 'date' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? 'date' : 'text';
    return '<input type="' . $input_type . '" class="form-control form-control-sm' . $cls . '"' . $attr . ' value="' . h($value) . '" maxlength="500">';
}
$check_badge = '<span class="badge bg-warning ms-1" title="The reader wasn\'t sure of this value -- compare it with the document">Check</span>';

$page_title = 'Import PDS File';
include __DIR__ . '/../includes/header.php';
?>

<a href="<?= h(BASE_URL . '/' . $back) ?>" class="small text-muted d-inline-block mb-3"><i class="fa-solid fa-arrow-left"></i> Back</a>
<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-1">
  <h3 class="fw-bold mb-0">Import PDS File<?= $is_own ? '' : ' — ' . h($doc['full_name']) ?></h3>
  <div class="d-flex gap-2">
    <a href="<?= h(document_url($document_id)) ?>" target="_blank" class="btn btn-sm btn-outline-brand"><i class="fa-solid fa-eye"></i> View the file</a>
    <a href="<?= h($self) ?>&amp;refresh=1" class="btn btn-sm btn-outline-secondary" title="Read the file again"><i class="fa-solid fa-rotate"></i> Read again</a>
  </div>
</div>
<p class="text-muted mb-3"><?= h(document_display_name($doc['file_path'])) ?> · uploaded <?= h(date('M j, Y', strtotime($doc['filed_at']))) ?>.
  Review what was read from the file before it goes into <?= $is_own ? 'your' : 'this faculty member\'s' ?> digital PDS. Only the parts found in the file are shown, and nothing else in the PDS is changed.</p>

<?php if (!$sections): ?>
  <div class="alert alert-warning">
    <div class="fw-semibold mb-1"><i class="fa-solid fa-triangle-exclamation"></i> No PDS details could be read from this file.</div>
    <?= $ex['source'] === 'ocr' ? 'The scan or photo may be too blurry, tilted or small to read. ' : '' ?>
    The file is kept in the 201 file. <?= $is_own ? 'Please update your digital PDS by hand' : 'The faculty member can update their digital PDS by hand' ?>,
    or upload a clearer copy -- the official Excel (.xlsx) soft copy gives the best result.
  </div>
  <a href="<?= h(BASE_URL . '/' . $back) ?>" class="btn btn-brand"><?= $is_own ? 'Go to My PDS' : 'Back' ?></a>
<?php else: ?>

<div class="alert <?= $source_label[1] ?> small"><i class="fa-solid fa-circle-info"></i> <?= h($source_label[0]) ?>
  Found: <?= h(implode(', ', array_map(function ($skey) use ($ex, $sections) {
      $n = count(array_intersect_key($ex['fields'], array_flip($sections[$skey]['fields'])));
      foreach ($sections[$skey]['tables'] as $t) { $n += count($ex['tables'][$t] ?? []); }
      return $sections[$skey]['label'] . " ($n)";
  }, array_keys($sections)))) ?>.</div>
<div class="small mb-3 d-flex flex-wrap gap-3">
  <span><span class="badge bg-success">New</span> empty in the PDS -- will be filled in</span>
  <span><span class="badge bg-warning">Different</span> the PDS has another value -- kept unless you choose "Use new"</span>
  <span><span class="badge bg-light text-dark border">Already in PDS</span> skipped</span>
  <span><?= $check_badge ?> please check against the file</span>
</div>

<form method="POST" id="importForm">
  <?= csrf_field() ?>
  <input type="hidden" name="document_id" value="<?= $document_id ?>">
  <input type="hidden" name="nonce" value="<?= h($cache['nonce']) ?>">

  <?php foreach ($sections as $skey => $section):
    $fields = array_intersect_key($ex['fields'], array_flip($section['fields'])); ?>
  <div class="card stat-card mb-4">
    <div class="card-header bg-white fw-semibold"><i class="fa-solid fa-id-card text-brand"></i> <?= h($section['label']) ?></div>
    <div class="card-body">

      <?php if (!$fields && !array_filter(array_map(fn($t) => $ex['tables'][$t] ?? [], $section['tables']))): ?>
        <p class="small text-muted mb-0">This part of the form was found, but nothing could be read from it.</p>
      <?php endif; ?>

      <?php if ($fields): ?>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-3">
          <thead class="table-light"><tr><th style="width:24%">Field</th><th style="width:28%">In the PDS now</th><th>From the file</th><th style="width:16%">Use</th></tr></thead>
          <tbody>
            <?php foreach ($fields as $key => $f):
              $now = trim((string)($current[$key] ?? ''));
              $state = $now === '' ? 'new' : ($same($now, $f['value']) ? 'same' : 'conflict'); ?>
            <tr class="<?= $state === 'conflict' ? 'table-warning' : ($state === 'same' ? 'text-muted' : '') ?>">
              <td class="small"><?= h($defs[$key][0]) ?></td>
              <td class="small text-break"><?= $now !== '' ? h($now) : '<span class="text-muted fst-italic">empty</span>' ?></td>
              <td>
                <?= pds_import_input("val[$key]", $defs[$key], $f['value'], !$f['sure'], $defs[$key][0]) ?>
                <?= !$f['sure'] && $state !== 'same' ? $check_badge : '' ?>
              </td>
              <td class="small text-nowrap">
                <?php if ($state === 'same'): ?>
                  <span class="badge bg-light text-dark border">Same</span>
                <?php elseif ($state === 'new'): ?>
                  <label class="d-inline-flex align-items-center gap-1"><input type="checkbox" class="form-check-input mt-0" name="use[<?= h($key) ?>]" value="new" checked> <span class="badge bg-success">New</span> Fill in</label>
                <?php else: ?>
                  <div class="form-check mb-0"><input class="form-check-input" type="radio" name="use[<?= h($key) ?>]" value="keep" id="k_<?= h($key) ?>" checked><label class="form-check-label" for="k_<?= h($key) ?>">Keep current</label></div>
                  <div class="form-check mb-0"><input class="form-check-input use-new" type="radio" name="use[<?= h($key) ?>]" value="new" id="n_<?= h($key) ?>"><label class="form-check-label" for="n_<?= h($key) ?>">Use new</label></div>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>

      <?php foreach ($section['tables'] as $tkey):
        $rows = $ex['tables'][$tkey] ?? [];
        if (!$rows) { continue; }
        $cols = pds_import_columns($tkey);
        $have = count($tkey === 'ld' ? $pds['ld'] : (array)($current[$tkey] ?? [])); ?>
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-1">
          <div class="fw-semibold small"><?= h(pds_import_table_label($tkey)) ?> -- <?= count($rows) ?> row<?= count($rows) === 1 ? '' : 's' ?> in the file
            <span class="text-muted fw-normal">(<?= $have ?> already in the PDS; new rows are added after them)</span></div>
          <div class="small"><button type="button" class="btn btn-link btn-sm p-0 tick-all" data-table="<?= h($tkey) ?>" data-on="1">Tick all</button> ·
            <button type="button" class="btn btn-link btn-sm p-0 tick-all" data-table="<?= h($tkey) ?>" data-on="0">Untick all</button></div>
        </div>
        <div class="table-responsive mb-3">
          <table class="table table-sm align-middle pds-table" data-import-table="<?= h($tkey) ?>">
            <thead class="table-light"><tr><th style="width:2rem">Add</th><?php foreach ($cols as $c): ?><th class="small"><?= h($c[0]) ?></th><?php endforeach; ?><th></th></tr></thead>
            <tbody>
              <?php foreach ($rows as $i => $row):
                $dup = isset($existing[pds_import_row_key($tkey, $row['cells'])]); ?>
              <tr class="<?= $dup ? 'text-muted bg-light' : '' ?>">
                <td><input type="checkbox" class="form-check-input row-inc" name="rows[<?= h($tkey) ?>][<?= $i ?>][inc]" value="1" <?= $dup ? '' : 'checked' ?> aria-label="Add this row"></td>
                <?php foreach ($cols as $ckey => $cdef): ?>
                  <td><?= pds_import_input("rows[$tkey][$i][cells][$ckey]", $cdef, (string)$row['cells'][$ckey], !$row['sure'] && !$dup, $cdef[0]) ?></td>
                <?php endforeach; ?>
                <td class="text-nowrap small">
                  <?php if ($dup): ?><span class="badge bg-light text-dark border">Already in PDS</span>
                  <?php else: ?><span class="badge bg-success">New</span><?= !$row['sure'] ? $check_badge : '' ?><?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endforeach; ?>

  <div class="pds-save-bar">
    <input type="hidden" name="form_complete" value="1"><!-- must stay the last field: see the max_input_vars check above -->
    <button type="submit" class="btn btn-brand"><i class="fa-solid fa-floppy-disk"></i> Save to <?= $is_own ? 'my' : 'the' ?> PDS</button>
    <a href="<?= h(BASE_URL . '/' . $back) ?>" class="btn btn-outline-secondary">Cancel</a>
    <span class="small text-muted ms-2 d-none d-sm-inline">A copy of the current PDS is kept in its version history first.</span>
  </div>
</form>

<script>
(function () {
  var form = document.getElementById('importForm');
  // Editing a new value for a field that already has one means "use it"
  form.addEventListener('input', function (e) {
    var m = /^val\[(.+)\]$/.exec(e.target.name || '');
    if (!m) return;
    var radio = form.querySelector('input.use-new[name="use[' + m[1] + ']"]');
    if (radio) radio.checked = true;
  });
  form.querySelectorAll('.tick-all').forEach(function (btn) {
    btn.addEventListener('click', function () {
      form.querySelectorAll('[data-import-table="' + btn.dataset.table + '"] .row-inc').forEach(function (c) { c.checked = btn.dataset.on === '1'; });
    });
  });
  form.addEventListener('submit', function () {
    var b = form.querySelector('button[type="submit"]');
    setTimeout(function () { b.disabled = true; }, 0);
  });
})();
</script>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
