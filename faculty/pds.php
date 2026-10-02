<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/pds.php';
require_role(['faculty', 'program_chair', 'dean']);   // own 201 file only

$page_title = 'My Personal Data Sheet';
$me = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    // form_complete is the form's last field. PHP silently drops fields past
    // max_input_vars, so if it's missing the submission was cut off -- saving
    // it would blank out everything after the cut.
    if (empty($_POST['form_complete'])) {
        $_SESSION['flash_error'] = 'Your PDS is too large to save in one go on this server (PHP max_input_vars). Nothing was changed -- please ask the administrator to raise max_input_vars.';
        header('Location: ' . BASE_URL . '/faculty/pds.php');
        exit;
    }
    $errors = pds_validate((array)($_POST['data'] ?? []), (array)($_POST['ld'] ?? []));
    if ($errors) {
        $_SESSION['flash_error'] = 'Your PDS was not saved. Please complete the required fields: ' . implode(' ', array_slice($errors, 0, 8))
            . (count($errors) > 8 ? ' (and ' . (count($errors) - 8) . ' more)' : '');
        header('Location: ' . BASE_URL . '/faculty/pds.php');
        exit;
    }
    pds_save($pdo, $me['user_id'], (array)($_POST['data'] ?? []), (array)($_POST['ld'] ?? []));
    log_my_activity($pdo, 'PDS_UPDATE', 'Edited and saved their digital PDS (previous version kept in the version history).');
    $_SESSION['flash_success'] = 'Your PDS has been saved. The previous version was kept in the version history.';
    header('Location: ' . BASE_URL . '/faculty/pds.php');
    exit;
}

$pds = pds_load($pdo, $me['user_id']);
$data = $pds['data'];
if (!$pds['exists'] && empty($data['email'])) { $data['email'] = $me['email']; }
$status = pds_status($pdo, $me['user_id']);
$snapshots = pds_snapshots($pdo, $me['user_id']);

$stmt = $pdo->prepare("SELECT document_id, file_path, period_year, filed_at FROM documents WHERE faculty_id = ? ORDER BY filed_at DESC");
$stmt->execute([$me['user_id']]);
$doc_rows = $stmt->fetchAll();
$doc_paths = array_column($doc_rows, 'file_path', 'document_id');
$stmt = $pdo->prepare("SELECT * FROM documents WHERE faculty_id = ? AND document_type = 'PDS' ORDER BY COALESCE(period_year, YEAR(filed_at)) DESC, filed_at DESC");
$stmt->execute([$me['user_id']]);
$pds_files = $stmt->fetchAll();

$ld_first = PDS_LD_ROWS_PAGE3;
$ld_cont = PDS_LD_ROWS_CONTINUATION;

/**
 * Render one form control. $rule_key looks up its pds_rules() entry; a
 * rule with `alt` gets an "N/A" (or "Present") tick box, the only way to
 * give that answer.
 */
function pds_control(string $name, array $def, $value, string $extra_class = '', ?string $rule_key = null): string {
    [$label, $type, $options] = pds_field_def($def);
    $value = (string)($value ?? '');
    $rule = $rule_key !== null ? (pds_rules()[$rule_key] ?? null) : null;
    $attr = ' data-label="' . h($label) . '"' . ($rule ? ' data-rule="' . h(json_encode($rule)) . '"' : '');
    $cls = 'form-control form-control-sm ' . $extra_class;
    if ($type === 'select' || $type === 'yesno') {
        $options = $type === 'yesno' ? ['Yes', 'No'] : $options;
        $html = '<select name="' . h($name) . '" class="form-select form-select-sm ' . $extra_class . '"' . $attr . '><option value=""></option>';
        foreach ($options as $opt) {
            $html .= '<option' . ($value === $opt ? ' selected' : '') . '>' . h($opt) . '</option>';
        }
        return $html . '</select>';
    }
    $input_type = $type === 'date' ? 'date' : ($type === 'number' ? 'number' : 'text');
    $step = $type === 'number' ? ' step="0.5" min="0"' : '';
    $alt = $rule['alt'] ?? null;
    $on = $alt !== null && (strcasecmp($value, $alt) === 0 || ($alt === 'N/A' && pds_is_na_text($value)));
    $input = '<input type="' . ($on ? 'text' : $input_type) . '" data-type="' . $input_type . '"' . $step . ' name="' . h($name) . '" value="'
           . h($on ? $alt : $value) . '" class="' . $cls . '"' . $attr . ($on ? ' readonly' : '') . '>';
    if ($alt === null) { return $input; }
    return '<div class="input-group input-group-sm flex-nowrap">' . $input
         . '<label class="input-group-text pds-alt" title="Tick if not applicable"><input type="checkbox" class="form-check-input mt-0 me-1 alt-toggle"'
         . ($on ? ' checked' : '') . '>' . h($alt) . '</label></div>';
}

/** Label text with a "*" for required fields (hidden until a condition applies for `if` rules). */
function pds_label(string $label, ?string $rule_key): string {
    $rule = $rule_key !== null ? (pds_rules()[$rule_key] ?? null) : null;
    if (!$rule) { return h($label); }
    $always = !empty($rule['req']) || isset($rule['alt']);
    if (!$always && !isset($rule['if'])) { return h($label); }
    return h($label) . ' <span class="text-danger pds-star' . ($always ? '' : ' d-none') . '">*</span>';
}

/** The "N/A" box for a repeating table that may have no entries. */
function pds_table_na(string $tkey, array $data): string {
    if (empty(pds_table_rules()[$tkey]['na'])) { return ''; }
    return '<div class="form-check form-check-inline small ms-2 mb-0"><input type="checkbox" class="form-check-input table-na" id="na_' . h($tkey)
         . '" name="data[' . h($tkey) . '_na]" value="1"' . (!empty($data[$tkey . '_na']) ? ' checked' : '') . '>'
         . '<label class="form-check-label" for="na_' . h($tkey) . '">N/A &ndash; none to declare</label></div>';
}

/** Attributes that tell the validator how many rows a table needs. */
function pds_table_attr(string $tkey, string $label): string {
    $tr = pds_table_rules()[$tkey] ?? [];
    return ' data-label="' . h($label) . '" data-min="' . (int)($tr['min'] ?? 0) . '" data-na="' . (!empty($tr['na']) ? 1 : 0) . '"';
}

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-1">
  <h3 class="fw-bold mb-0">My Personal Data Sheet</h3>
  <div class="d-flex gap-2 flex-wrap">
    <a href="<?= BASE_URL ?>/faculty/pds_print.php" target="_blank" class="btn btn-outline-brand btn-sm"><i class="fa-solid fa-print"></i> Print / Save as PDF</a>
    <a href="<?= BASE_URL ?>/faculty/submit_document.php" class="btn btn-outline-brand btn-sm"><i class="fa-solid fa-file-arrow-up"></i> Upload PDS / Certificate</a>
  </div>
</div>
<p class="text-muted mb-3">CS Form No. 212. Edit any part below and save. Seminar and training certificates you upload are added to Section VI (Learning and Development) automatically.
  Fields marked <span class="text-danger">*</span> are required -- tick <b>N/A</b> where it doesn't apply to you. Complete each part to move on to the next.</p>

<?php if ($status['current']): ?>
  <div class="alert alert-success small py-2"><i class="fa-solid fa-circle-check"></i> Your PDS is up to date for <?= $status['year'] ?><?= $status['updated_at'] ? ' -- last updated ' . date('M j, Y g:ia', strtotime($status['updated_at'])) : '' ?>.</div>
<?php else: ?>
  <div class="alert alert-warning small py-2"><i class="fa-solid fa-triangle-exclamation"></i> Your PDS needs its annual update for <?= $status['year'] ?>. Review each part and save, or upload this year's PDS.</div>
<?php endif; ?>

<form method="POST" id="pdsForm">
  <input type="hidden" name="action" value="save">

  <?php
  // Back / Next at the foot of every part; the script hides Back on the first part and Next on the last
  $nav = '<div class="pds-errors alert alert-danger small py-2 mt-3 mb-0" role="alert" hidden></div>'
       . '<div class="d-flex justify-content-between mt-3"><button type="button" class="btn btn-sm btn-outline-secondary pds-back"><i class="fa-solid fa-arrow-left"></i> Back</button>'
       . '<button type="button" class="btn btn-sm btn-brand pds-next ms-auto">Next <i class="fa-solid fa-arrow-right"></i></button></div>';
  ?>
  <div class="accordion mb-3" id="pdsParts">
  <?php $first = true; foreach (pds_schema() as $part_key => $part):
    $pid = 'part' . $part_key; ?>

    <?php if ($part_key === 'VII'): /* Section VI (L&D) comes before VII (Voluntary Work), as on the 2026 form */ ?>
    <div class="accordion-item">
      <h2 class="accordion-header">
        <button class="accordion-button collapsed fw-semibold" type="button" data-bs-toggle="collapse" data-bs-target="#partLD">
          VI. Learning and Development (L&amp;D) Interventions / Training Programs Attended
          <span class="badge bg-light text-dark border ms-2" id="ldCounter"></span>
        </button>
      </h2>
      <div id="partLD" class="accordion-collapse collapse pds-section" data-bs-parent="#pdsParts">
        <div class="accordion-body">
          <p class="small text-muted">Up to <?= $ld_first ?> entries fit on page 3 of the form. When it's full, new entries continue on continuation sheet C5 (<?= $ld_cont ?> per sheet) automatically -- existing entries are never moved or overwritten.</p>
          <div class="table-responsive">
            <table class="table table-sm align-middle pds-table" id="ldTable" data-table="ld"<?= pds_table_attr('ld', 'Learning and Development') ?>>
              <thead class="table-light"><tr>
                <th>#</th>
                <?php foreach (pds_ld_columns() as $ckey => $cdef): ?><th><?= pds_label($cdef[0], "ld.$ckey") ?></th><?php endforeach; ?>
                <th></th>
              </tr></thead>
              <tbody>
                <?php foreach ($pds['ld'] as $i => $row): ?>
                <tr class="ld-row">
                  <td class="ld-num small text-muted"></td>
                  <?php foreach (pds_ld_columns() as $ckey => $cdef): ?>
                    <td><?= pds_control("ld[$i][$ckey]", $cdef, $row[$ckey] === null ? '' : ($ckey === 'hours' ? rtrim(rtrim((string)$row[$ckey], '0'), '.') : $row[$ckey]), '', "ld.$ckey") ?></td>
                  <?php endforeach; ?>
                  <td class="text-nowrap">
                    <input type="hidden" name="ld[<?= $i ?>][ld_id]" value="<?= (int)$row['ld_id'] ?>">
                    <input type="hidden" name="ld[<?= $i ?>][_delete]" value="" class="ld-delete">
                    <?php if ($row['source_document_id'] && isset($doc_paths[$row['source_document_id']])): ?>
                      <a href="<?= h(document_url((int)$row['source_document_id'])) ?>" target="_blank" class="badge bg-info text-decoration-none" title="Added automatically from an uploaded certificate"><i class="fa-solid fa-certificate"></i></a>
                    <?php endif; ?>
                    <button type="button" class="btn btn-sm btn-link text-danger p-0 ms-1 remove-ld" title="Remove"><i class="fa-solid fa-trash"></i></button>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <template id="ldTemplate">
            <tr class="ld-row">
              <td class="ld-num small text-muted"></td>
              <?php foreach (pds_ld_columns() as $ckey => $cdef): ?>
                <td><?= pds_control("ld[__I__][$ckey]", $cdef, $ckey === 'ld_type' ? 'Technical' : '', '', "ld.$ckey") ?></td>
              <?php endforeach; ?>
              <td><button type="button" class="btn btn-sm btn-link text-danger p-0 remove-row" title="Remove"><i class="fa-solid fa-trash"></i></button></td>
            </tr>
          </template>
          <button type="button" class="btn btn-sm btn-outline-brand" id="addLd"><i class="fa-solid fa-plus"></i> Add training / seminar</button><?= pds_table_na('ld', $data) ?>
          <?= $nav ?>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <div class="accordion-item">
      <h2 class="accordion-header">
        <button class="accordion-button <?= $first ? '' : 'collapsed' ?> fw-semibold" type="button" data-bs-toggle="collapse" data-bs-target="#<?= $pid ?>">
          <?= in_array($part_key, ['Q', 'R', 'ID'], true) ? '' : h($part_key) . '. ' ?><?= h($part['title']) ?>
        </button>
      </h2>
      <div id="<?= $pid ?>" class="accordion-collapse collapse pds-section <?= $first ? 'show' : '' ?>" data-bs-parent="#pdsParts">
        <div class="accordion-body">

          <?php if (!empty($part['fields'])): ?>
          <div class="row g-2 mb-2">
            <?php foreach ($part['fields'] as $fkey => $fdef):
              // Questions: the yes/no question spans the row, its detail fields sit below it
              $is_question = $part_key === 'Q' && !str_contains($fkey, '_');
              $col = $is_question ? 'col-12' : ($part_key === 'Q' ? 'col-md-6' : (str_contains($fkey, 'address') ? 'col-md-8' : 'col-md-4')); ?>
              <div class="<?= $col ?>">
                <label class="form-label small mb-1"><?= pds_label($fdef[0], $fkey) ?></label>
                <?php if ($is_question): ?>
                  <div style="max-width:140px"><?= pds_control("data[$fkey]", $fdef, $data[$fkey] ?? '', '', $fkey) ?></div>
                <?php else: ?>
                  <?= pds_control("data[$fkey]", $fdef, $data[$fkey] ?? '', '', $fkey) ?>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>

          <?php foreach ($part['tables'] ?? [] as $tkey => $table):
            $rows = $data[$tkey] ?? []; ?>
            <div class="fw-semibold small mt-3 mb-1"><?= h($table['label']) ?></div>
            <div class="table-responsive">
              <table class="table table-sm align-middle pds-table" data-table="<?= h($tkey) ?>" data-max="<?= (int)($table['max'] ?? 0) ?>"<?= pds_table_attr($tkey, $table['label']) ?>>
                <thead class="table-light"><tr>
                  <?php foreach ($table['columns'] as $ckey => $cdef): ?><th><?= pds_label($cdef[0], "$tkey.$ckey") ?></th><?php endforeach; ?><th></th>
                </tr></thead>
                <tbody>
                  <?php foreach ($rows as $i => $row): ?>
                  <tr>
                    <?php foreach ($table['columns'] as $ckey => $cdef): ?>
                      <td><?= pds_control("data[$tkey][$i][$ckey]", $cdef, $row[$ckey] ?? '', '', "$tkey.$ckey") ?></td>
                    <?php endforeach; ?>
                    <td><button type="button" class="btn btn-sm btn-link text-danger p-0 remove-row" title="Remove"><i class="fa-solid fa-trash"></i></button></td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <template data-template-for="<?= h($tkey) ?>">
              <tr>
                <?php foreach ($table['columns'] as $ckey => $cdef): ?>
                  <td><?= pds_control("data[$tkey][__I__][$ckey]", $cdef, '', '', "$tkey.$ckey") ?></td>
                <?php endforeach; ?>
                <td><button type="button" class="btn btn-sm btn-link text-danger p-0 remove-row" title="Remove"><i class="fa-solid fa-trash"></i></button></td>
              </tr>
            </template>
            <button type="button" class="btn btn-sm btn-outline-brand add-row" data-table="<?= h($tkey) ?>"><i class="fa-solid fa-plus"></i> Add row</button><?= pds_table_na($tkey, $data) ?>
          <?php endforeach; ?>
          <?= $nav ?>

        </div>
      </div>
    </div>
  <?php $first = false; endforeach; ?>
  </div>

  <div class="pds-save-bar">
    <input type="hidden" name="form_complete" value="1"><!-- must stay the last field; see top of file -->
    <button type="submit" class="btn btn-brand"><i class="fa-solid fa-floppy-disk"></i> Save PDS</button>
    <span class="small text-muted ms-2 d-none d-sm-inline">Saving keeps a copy of the previous version.</span>
  </div>
</form>

<div class="row g-3 mt-2">
  <div class="col-lg-6">
    <div class="card stat-card h-100">
      <div class="card-header bg-white fw-semibold">Uploaded PDS Files</div>
      <div class="list-group list-group-flush">
        <?php if (!$pds_files): ?><div class="list-group-item small text-muted">No PDS file uploaded yet.</div><?php endif; ?>
        <?php foreach ($pds_files as $i => $f): ?>
          <a href="<?= h(document_url((int)$f['document_id'])) ?>" target="_blank" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center small">
            <span><i class="fa-solid fa-file text-brand"></i> PDS <?= h((string)($f['period_year'] ?: date('Y', strtotime($f['filed_at'])))) ?>
              <?= $i === 0 ? '<span class="badge bg-success ms-1">Latest</span>' : '' ?></span>
            <span class="text-muted">uploaded <?= date('M j, Y', strtotime($f['filed_at'])) ?></span>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="card stat-card h-100">
      <div class="card-header bg-white fw-semibold">Version History</div>
      <div class="list-group list-group-flush">
        <?php if (!$snapshots): ?><div class="list-group-item small text-muted">Earlier versions will appear here after you make changes.</div><?php endif; ?>
        <?php foreach ($snapshots as $s): ?>
          <a href="<?= BASE_URL ?>/faculty/pds_print.php?snapshot=<?= (int)$s['snapshot_id'] ?>" target="_blank" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center small">
            <span><i class="fa-solid fa-clock-rotate-left text-muted"></i> <?= h($s['reason']) ?></span>
            <span class="text-muted text-nowrap ms-2">View</span>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
  var FIRST = <?= (int)$ld_first ?>, CONT = <?= (int)$ld_cont ?>;
  var counter = 100000;
  var form = document.getElementById('pdsForm');
  var ldBody = document.querySelector('#ldTable tbody');

  // Number L&D rows and insert a divider where each printed sheet starts
  // (page 3 holds FIRST entries, each continuation sheet C5 holds CONT).
  function renumberLd() {
    ldBody.querySelectorAll('.ld-page-row').forEach(function (r) { r.remove(); });
    var rows = Array.prototype.filter.call(ldBody.querySelectorAll('.ld-row'), function (r) { return !r.classList.contains('d-none'); });
    rows.forEach(function (row, i) {
      if (i === 0 || (i >= FIRST && (i - FIRST) % CONT === 0)) {
        var sheet = i === 0 ? 0 : 1 + (i - FIRST) / CONT;
        var tr = document.createElement('tr');
        tr.className = 'ld-page-row';
        var cols = row.children.length;
        tr.innerHTML = '<td colspan="' + cols + '" class="table-secondary small fw-semibold">' +
          (sheet === 0 ? 'Section VI &ndash; page 3' : 'Continuation sheet C5' + (sheet > 1 ? ' (' + sheet + ')' : '')) + '</td>';
        row.parentNode.insertBefore(tr, row);
      }
      row.querySelector('.ld-num').textContent = i + 1;
    });
    var pages = rows.length <= FIRST ? 1 : 1 + Math.ceil((rows.length - FIRST) / CONT);
    document.getElementById('ldCounter').textContent = rows.length + ' entr' + (rows.length === 1 ? 'y' : 'ies') + ' · ' + pages + ' sheet' + (pages === 1 ? '' : 's');
  }

  function fromTemplate(tpl) {
    var html = tpl.innerHTML.replace(/__I__/g, 'n' + (counter++));
    var tbody = document.createElement('tbody');
    tbody.innerHTML = html.trim();
    return tbody.firstElementChild;
  }

  document.getElementById('addLd').addEventListener('click', function () {
    var row = fromTemplate(document.getElementById('ldTemplate'));
    ldBody.appendChild(row);
    renumberLd();
    syncTableNa();
    row.querySelector('input').focus();
  });

  form.addEventListener('click', function (e) {
    var add = e.target.closest('.add-row');
    if (add) {
      var key = add.dataset.table;
      var table = form.querySelector('table[data-table="' + key + '"]');
      var max = parseInt(table.dataset.max, 10);
      if (max && table.tBodies[0].rows.length >= max) { alert('This part allows up to ' + max + ' entries.'); return; }
      var row = fromTemplate(form.querySelector('template[data-template-for="' + key + '"]'));
      table.tBodies[0].appendChild(row);
      syncTableNa();
      var first = row.querySelector('input, select');
      if (first) first.focus();
      return;
    }
    var rm = e.target.closest('.remove-row');
    if (rm) { rm.closest('tr').remove(); renumberLd(); syncTableNa(); revalidate(form); return; }
    var rmLd = e.target.closest('.remove-ld');
    if (rmLd && confirm('Remove this L&D entry? It stays in your version history.')) {
      var tr = rmLd.closest('tr');
      tr.querySelector('.ld-delete').value = '1';
      tr.classList.add('d-none');
      renumberLd();
      syncTableNa();
      revalidate(form);
    }
  });

  // ------------------------------------------------------------------
  // Validation. Rules come from pds_rules() / pds_table_rules() (data-rule,
  // data-min, data-na); checkValue() mirrors pds_check_value() in
  // includes/pds.php, which re-checks everything on save.
  var FORMATS = <?= json_encode(pds_formats()) ?>;
  var sections = Array.prototype.slice.call(document.querySelectorAll('#pdsParts .pds-section'));
  var cur = Math.max(0, sections.findIndex(function (s) { return s.classList.contains('show'); }));
  var now = new Date();
  var TODAY = now.getFullYear() + '-' + ('0' + (now.getMonth() + 1)).slice(-2) + '-' + ('0' + now.getDate()).slice(-2);

  function ruleOf(el) {
    if (el._rule === undefined) { el._rule = el.dataset.rule ? JSON.parse(el.dataset.rule) : null; }
    return el._rule;
  }
  function isNA(v) { return /^\s*(n\s*[\/\\.]?\s*a\.?|not\s+applicable)\s*$/i.test(v); }
  function fieldVal(key) { var el = form.elements['data[' + key + ']']; return el ? el.value.trim() : ''; }
  function isRequired(r) {
    return !!r.req || r.alt != null || (!!r.if && r.if[1].indexOf(fieldVal(r.if[0])) >= 0);
  }

  // Error message (without the label) or null
  function checkValue(el) {
    var r = ruleOf(el);
    if (!r) return null;
    var v = el.value.trim(), type = el.dataset.type;
    if (r.alt != null && v.toLowerCase() === r.alt.toLowerCase()) return null;
    if (v === '') {
      if (el.validity && el.validity.badInput) return type === 'date' ? 'must be a complete, valid date' : 'must be a number';
      return isRequired(r) ? 'is required' + (r.alt != null ? ' (or tick ' + r.alt + ')' : '') : null;
    }
    if (isNA(v)) return r.alt === 'N/A' ? null : 'cannot be N/A \u2013 please enter the actual information';
    if (r.fmt) {
      var f = FORMATS[r.fmt];
      var s = f.strip ? v.replace(new RegExp(f.strip, 'g'), '') : v;
      var ok = new RegExp(f.re, f.flags || '').test(s) &&
               (f.min == null || parseFloat(s) >= f.min) && (f.max == null || parseFloat(s) <= f.max);
      if (!ok) return f.msg;
    }
    if (type === 'date') {
      if (!/^\d{4}-\d{2}-\d{2}$/.test(v)) return 'must be a valid date';
      if (r.past && v > TODAY) return 'cannot be a future date';
    }
    if (r.after) {
      var other = el.closest('tr').querySelector('[name$="[' + r.after + ']"]');
      var o = other ? other.value.trim() : '';
      if (o !== '' && o.length === v.length && v < o) return 'cannot be earlier than ' + other.dataset.label;
    }
    return null;
  }

  function visibleRows(table) {
    return Array.prototype.filter.call(table.tBodies[0].rows, function (tr) {
      return !tr.classList.contains('d-none') && !tr.classList.contains('ld-page-row');
    });
  }
  function naBox(table) { return document.getElementById('na_' + table.dataset.table); }
  function addBtn(table) {
    return table.dataset.table === 'ld' ? document.getElementById('addLd')
         : form.querySelector('.add-row[data-table="' + table.dataset.table + '"]');
  }

  // A table's N/A box can only be ticked while it has no entries, and
  // entries can't be added while it is ticked.
  function syncTableNa() {
    form.querySelectorAll('table[data-min]').forEach(function (t) {
      var box = naBox(t);
      if (!box) return;
      var has = visibleRows(t).length > 0;
      if (has) box.checked = false;
      box.disabled = has;
      box.parentNode.title = has ? 'Remove the entries first to mark this part N/A' : '';
      addBtn(t).disabled = box.checked;
    });
  }

  // Inline message under a field (table cells get a tooltip instead)
  function mark(el, msg) {
    el.classList.toggle('is-invalid', !!msg);
    el.title = msg ? el.dataset.label + ' ' + msg : '';
    if (el.closest('td')) return;
    var host = el.closest('.input-group') || el;
    var fb = host.nextElementSibling;
    if (!fb || !fb.classList.contains('pds-feedback')) {
      if (!msg) return;
      fb = document.createElement('div');
      fb.className = 'invalid-feedback d-block pds-feedback';
      host.parentNode.insertBefore(fb, host.nextSibling);
    }
    fb.textContent = msg ? el.dataset.label.replace(/^If (YES|Other\/s|dual citizenship),?\s*/i, '') + ' ' + msg + '.' : '';
    fb.hidden = !msg;
  }

  function validateSection(k, show) {
    var sec = sections[k], errs = [];
    sec.querySelectorAll('[data-rule]').forEach(function (el) {
      if (el.closest('.d-none')) return;   // removed L&D entries
      var msg = checkValue(el), label = el.dataset.label;
      var r = ruleOf(el), parent = r.if && form.elements['data[' + r.if[0] + ']'];
      var num = parent && /^\d+[a-c]?\./.exec(parent.dataset.label);
      if (num) label = num[0] + ' ' + label;   // "36. If YES, give details"
      var tr = el.closest('tr');
      if (tr) {
        var table = tr.closest('table');
        label = table.dataset.label + ', row ' + (visibleRows(table).indexOf(tr) + 1) + ': ' + label;
      }
      if (show) mark(el, msg);
      if (msg) errs.push({ el: el, text: label + ' ' + msg });
    });
    sec.querySelectorAll('table[data-min]').forEach(function (t) {
      var min = parseInt(t.dataset.min, 10), box = naBox(t);
      if ((box && box.checked) || visibleRows(t).length >= min) return;
      errs.push({ el: box && !box.disabled ? box : addBtn(t),
                  text: t.dataset.label + ': add at least ' + min + ' entr' + (min === 1 ? 'y' : 'ies') + (box ? ' or tick N/A' : '') });
    });
    if (show) {
      sec.dataset.checked = '1';
      var box = sec.querySelector('.pds-errors');
      box.hidden = !errs.length;
      box.innerHTML = '';
      if (errs.length) {
        var p = document.createElement('div');
        p.className = 'fw-semibold';
        p.textContent = 'Please complete the following before continuing (' + errs.length + '):';
        var ul = document.createElement('ul');
        ul.className = 'mb-0 mt-1 ps-3';
        errs.forEach(function (e) {
          var li = document.createElement('li'), a = document.createElement('a');
          a.href = '#';
          a.className = 'link-danger';
          a.textContent = e.text;
          a.addEventListener('click', function (ev) { ev.preventDefault(); e.el.focus(); });
          li.appendChild(a);
          ul.appendChild(li);
        });
        box.appendChild(p);
        box.appendChild(ul);
      }
    }
    return errs.length === 0;
  }

  // Once a part has been checked, keep its messages up to date while typing
  function revalidate(el) {
    var sec = el.closest ? el.closest('.pds-section') : null;
    if (sec && sec.dataset.checked) validateSection(sections.indexOf(sec), true);
    else if (el === form) sections.forEach(function (s, k) { if (s.dataset.checked) validateSection(k, true); });
  }

  function focusFirstError(k) {
    var bad = sections[k].querySelector('.is-invalid');
    var box = sections[k].querySelector('.pds-errors');
    (box.hidden ? sections[k] : box).scrollIntoView({ behavior: 'smooth', block: 'center' });
    if (bad) bad.focus({ preventScroll: true });
  }

  function goTo(k) {
    if (k !== cur) bootstrap.Collapse.getOrCreateInstance(sections[k], { toggle: false }).show();
  }
  sections.forEach(function (sec, k) {
    sec.addEventListener('shown.bs.collapse', function () {
      cur = k;
      sec.parentNode.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
  });

  // Forward only through parts that are complete; back at any time
  function goForward(target) {
    for (var k = cur; k < target; k++) {
      if (!validateSection(k, true)) {
        if (k !== cur) { goTo(k); sections[k].addEventListener('shown.bs.collapse', function () { focusFirstError(k); }, { once: true }); }
        else focusFirstError(k);
        return;
      }
    }
    goTo(target);
  }

  // Section headers: intercepted before Bootstrap's own toggle handler
  document.getElementById('pdsParts').addEventListener('click', function (e) {
    var head = e.target.closest('.accordion-button');
    if (!head) return;
    e.preventDefault();
    e.stopPropagation();
    var k = sections.indexOf(document.querySelector(head.dataset.bsTarget));
    if (k < cur) goTo(k); else if (k > cur) goForward(k);
  }, true);

  sections.forEach(function (sec, k) {
    var back = sec.querySelector('.pds-back'), next = sec.querySelector('.pds-next');
    if (k === 0) back.hidden = true;
    back.addEventListener('click', function () { goTo(k - 1); });
    if (k === sections.length - 1) {
      next.type = 'submit';
      next.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Save PDS';
    } else {
      next.addEventListener('click', function () { goForward(k + 1); });
    }
  });

  // Saving needs every part complete; open the first one that isn't
  form.addEventListener('submit', function (e) {
    var firstBad = -1;
    sections.forEach(function (s, k) { if (!validateSection(k, true) && firstBad < 0) firstBad = k; });
    if (firstBad < 0) return;
    e.preventDefault();
    if (firstBad === cur) { focusFirstError(firstBad); return; }
    goTo(firstBad);
    sections[firstBad].addEventListener('shown.bs.collapse', function () { focusFirstError(firstBad); }, { once: true });
  });

  // N/A / Present boxes: the only way to give that answer
  function setAlt(box) {
    var input = box.closest('.input-group').querySelector('input[data-rule]');
    var alt = ruleOf(input).alt;
    if (box.checked) {
      if (input.value.trim().toLowerCase() !== alt.toLowerCase()) input.dataset.prev = input.value;
      input.type = 'text';
      input.value = alt;
      input.readOnly = true;
    } else {
      input.type = input.dataset.type;
      input.readOnly = false;
      input.value = input.dataset.prev || '';
      input.focus();
    }
  }

  // "*" on "If ..." fields only while their condition applies
  function syncConditional() {
    form.querySelectorAll('[data-rule]').forEach(function (el) {
      var r = ruleOf(el);
      if (!r.if) return;
      var star = el.closest('[class*="col-"]').querySelector('.pds-star');
      if (star) star.classList.toggle('d-none', !isRequired(r));
    });
  }

  form.addEventListener('change', function (e) {
    var el = e.target;
    if (el.classList.contains('alt-toggle')) setAlt(el);
    else if (el.classList.contains('table-na')) syncTableNa();
    else if (el.dataset.rule) {
      // A typed "N/A" (or "present") where that's allowed ticks the box instead
      var r = ruleOf(el), box = el.closest('.input-group') && el.closest('.input-group').querySelector('.alt-toggle');
      var v = el.value.trim();
      if (box && !box.checked && (v.toLowerCase() === r.alt.toLowerCase() || (r.alt === 'N/A' && isNA(v)))) {
        box.checked = true;
        setAlt(box);
      }
      syncConditional();
    }
    revalidate(el);
  });
  form.addEventListener('input', function (e) { if (e.target.dataset.rule) revalidate(e.target); });

  renumberLd();
  syncTableNa();
  syncConditional();
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
