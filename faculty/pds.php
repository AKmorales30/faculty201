<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/pds.php';
require_role('faculty');

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
    pds_save($pdo, $me['user_id'], (array)($_POST['data'] ?? []), (array)($_POST['ld'] ?? []));
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

/** Render one form control. */
function pds_control(string $name, array $def, $value, string $extra_class = ''): string {
    [$label, $type, $options] = pds_field_def($def);
    $value = (string)($value ?? '');
    $cls = 'form-control form-control-sm ' . $extra_class;
    if ($type === 'select' || $type === 'yesno') {
        $options = $type === 'yesno' ? ['Yes', 'No'] : $options;
        $html = '<select name="' . h($name) . '" class="form-select form-select-sm ' . $extra_class . '"><option value=""></option>';
        foreach ($options as $opt) {
            $html .= '<option' . ($value === $opt ? ' selected' : '') . '>' . h($opt) . '</option>';
        }
        return $html . '</select>';
    }
    $input_type = $type === 'date' ? 'date' : ($type === 'number' ? 'number' : 'text');
    $step = $type === 'number' ? ' step="0.5" min="0"' : '';
    return '<input type="' . $input_type . '"' . $step . ' name="' . h($name) . '" value="' . h($value) . '" class="' . $cls . '">';
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
<p class="text-muted mb-3">CS Form No. 212. Edit any part below and save. Seminar and training certificates you upload are added to Section VI (Learning and Development) automatically.</p>

<?php if ($status['current']): ?>
  <div class="alert alert-success small py-2"><i class="fa-solid fa-circle-check"></i> Your PDS is up to date for <?= $status['year'] ?><?= $status['updated_at'] ? ' -- last updated ' . date('M j, Y g:ia', strtotime($status['updated_at'])) : '' ?>.</div>
<?php else: ?>
  <div class="alert alert-warning small py-2"><i class="fa-solid fa-triangle-exclamation"></i> Your PDS needs its annual update for <?= $status['year'] ?>. Review each part and save, or upload this year's PDS.</div>
<?php endif; ?>

<form method="POST" id="pdsForm">
  <input type="hidden" name="action" value="save">

  <div class="accordion mb-3" id="pdsParts">
  <?php $first = true; foreach (pds_schema() as $part_key => $part):
    $pid = 'part' . $part_key; ?>

    <?php if ($part_key === 'VII'): /* Section VI (L&D) comes before VII (Voluntary Work), as on the 2026 form */ ?>
    <div class="accordion-item">
      <h2 class="accordion-header">
        <button class="accordion-button collapsed fw-semibold" type="button" data-bs-toggle="collapse" data-bs-target="#partVII">
          VI. Learning and Development (L&amp;D) Interventions / Training Programs Attended
          <span class="badge bg-light text-dark border ms-2" id="ldCounter"></span>
        </button>
      </h2>
      <div id="partVII" class="accordion-collapse collapse" data-bs-parent="#pdsParts">
        <div class="accordion-body">
          <p class="small text-muted">Up to <?= $ld_first ?> entries fit on page 3 of the form. When it's full, new entries continue on continuation sheet C5 (<?= $ld_cont ?> per sheet) automatically -- existing entries are never moved or overwritten.</p>
          <div class="table-responsive">
            <table class="table table-sm align-middle pds-table" id="ldTable">
              <thead class="table-light"><tr>
                <th>#</th>
                <?php foreach (pds_ld_columns() as $ckey => $cdef): ?><th><?= h($cdef[0]) ?></th><?php endforeach; ?>
                <th></th>
              </tr></thead>
              <tbody>
                <?php foreach ($pds['ld'] as $i => $row): ?>
                <tr class="ld-row">
                  <td class="ld-num small text-muted"></td>
                  <?php foreach (pds_ld_columns() as $ckey => $cdef): ?>
                    <td><?= pds_control("ld[$i][$ckey]", $cdef, $row[$ckey] === null ? '' : ($ckey === 'hours' ? rtrim(rtrim((string)$row[$ckey], '0'), '.') : $row[$ckey])) ?></td>
                  <?php endforeach; ?>
                  <td class="text-nowrap">
                    <input type="hidden" name="ld[<?= $i ?>][ld_id]" value="<?= (int)$row['ld_id'] ?>">
                    <input type="hidden" name="ld[<?= $i ?>][_delete]" value="" class="ld-delete">
                    <?php if ($row['source_document_id'] && isset($doc_paths[$row['source_document_id']])): ?>
                      <a href="<?= BASE_URL . '/' . h($doc_paths[$row['source_document_id']]) ?>" target="_blank" class="badge bg-info text-decoration-none" title="Added automatically from an uploaded certificate"><i class="fa-solid fa-certificate"></i></a>
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
                <td><?= pds_control("ld[__I__][$ckey]", $cdef, $ckey === 'ld_type' ? 'Technical' : '') ?></td>
              <?php endforeach; ?>
              <td><button type="button" class="btn btn-sm btn-link text-danger p-0 remove-row" title="Remove"><i class="fa-solid fa-trash"></i></button></td>
            </tr>
          </template>
          <button type="button" class="btn btn-sm btn-outline-brand" id="addLd"><i class="fa-solid fa-plus"></i> Add training / seminar</button>
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
      <div id="<?= $pid ?>" class="accordion-collapse collapse <?= $first ? 'show' : '' ?>" data-bs-parent="#pdsParts">
        <div class="accordion-body">

          <?php if (!empty($part['fields'])): ?>
          <div class="row g-2 mb-2">
            <?php foreach ($part['fields'] as $fkey => $fdef):
              // Questions: the yes/no question spans the row, its detail fields sit below it
              $is_question = $part_key === 'Q' && !str_contains($fkey, '_');
              $col = $is_question ? 'col-12' : ($part_key === 'Q' ? 'col-md-6' : (str_contains($fkey, 'address') ? 'col-md-8' : 'col-md-4')); ?>
              <div class="<?= $col ?>">
                <label class="form-label small mb-1"><?= h($fdef[0]) ?></label>
                <?php if ($is_question): ?>
                  <div style="max-width:140px"><?= pds_control("data[$fkey]", $fdef, $data[$fkey] ?? '') ?></div>
                <?php else: ?>
                  <?= pds_control("data[$fkey]", $fdef, $data[$fkey] ?? '') ?>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>

          <?php foreach ($part['tables'] ?? [] as $tkey => $table):
            $rows = $data[$tkey] ?? []; ?>
            <div class="fw-semibold small mt-3 mb-1"><?= h($table['label']) ?></div>
            <div class="table-responsive">
              <table class="table table-sm align-middle pds-table" data-table="<?= h($tkey) ?>" data-max="<?= (int)($table['max'] ?? 0) ?>">
                <thead class="table-light"><tr>
                  <?php foreach ($table['columns'] as $cdef): ?><th><?= h($cdef[0]) ?></th><?php endforeach; ?><th></th>
                </tr></thead>
                <tbody>
                  <?php foreach ($rows as $i => $row): ?>
                  <tr>
                    <?php foreach ($table['columns'] as $ckey => $cdef): ?>
                      <td><?= pds_control("data[$tkey][$i][$ckey]", $cdef, $row[$ckey] ?? '') ?></td>
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
                  <td><?= pds_control("data[$tkey][__I__][$ckey]", $cdef, '') ?></td>
                <?php endforeach; ?>
                <td><button type="button" class="btn btn-sm btn-link text-danger p-0 remove-row" title="Remove"><i class="fa-solid fa-trash"></i></button></td>
              </tr>
            </template>
            <button type="button" class="btn btn-sm btn-outline-brand add-row" data-table="<?= h($tkey) ?>"><i class="fa-solid fa-plus"></i> Add row</button>
          <?php endforeach; ?>

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
          <a href="<?= BASE_URL . '/' . h($f['file_path']) ?>" target="_blank" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center small">
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
      var first = row.querySelector('input, select');
      if (first) first.focus();
      return;
    }
    var rm = e.target.closest('.remove-row');
    if (rm) { rm.closest('tr').remove(); renumberLd(); return; }
    var rmLd = e.target.closest('.remove-ld');
    if (rmLd && confirm('Remove this L&D entry? It stays in your version history.')) {
      var tr = rmLd.closest('tr');
      tr.querySelector('.ld-delete').value = '1';
      tr.classList.add('d-none');
      renumberLd();
    }
  });

  renumberLd();
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
