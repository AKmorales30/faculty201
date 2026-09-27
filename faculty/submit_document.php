<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/pds.php';
require_once __DIR__ . '/../ocr/OcrProcessor.php';
require_role('faculty');

$page_title = 'Upload Document';
$me = current_user();
$employment_type = faculty_employment_type($pdo, $me['user_id']);
$categories = categories_for_faculty($employment_type);
$period_now = current_academic_period();

$action = $_POST['action'] ?? '';

// ---------------------------------------------------------------------
// Step 2a: faculty confirmed the previewed scan -> file it immediately,
// update the PDS where applicable, then notify the Program Chair / Dean.
// (No approval step -- the upload is accepted once it's stored.)
// ---------------------------------------------------------------------
if ($action === 'confirm' && isset($_SESSION['pending_scan'])) {
    $scan = $_SESSION['pending_scan'];
    $type = $_POST['document_type'] ?? '';
    if (!array_key_exists($type, $categories)) {
        $_SESSION['flash_error'] = 'Please choose a valid document type before confirming.';
        header('Location: ' . BASE_URL . '/faculty/submit_document.php');
        exit;
    }
    $cat = $categories[$type];

    $subtype = $_POST['document_subtype'] ?? '';
    $subtype = isset($cat['subtypes'][$subtype]) ? $subtype : ($cat['default_subtype'] ?? null);

    $meta = [
        'subtype'    => $subtype,
        'expiration' => ($_POST['expiration_date'] ?? '') ?: null,
    ];
    if ($cat['frequency'] === 'semester') {
        $ay  = $_POST['academic_year'] ?? '';
        $sem = (int)($_POST['semester'] ?? 0);
        $meta['academic_year'] = preg_match('/^\d{4}-\d{4}$/', $ay) ? $ay : $period_now['academic_year'];
        $meta['semester'] = isset(semester_options()[$sem]) ? $sem : $period_now['semester'];
    } elseif ($cat['frequency'] === 'yearly') {
        $year = (int)($_POST['period_year'] ?? 0);
        $meta['period_year'] = ($year >= 1990 && $year <= (int)date('Y') + 1) ? $year : (int)date('Y');
    }

    $stored = store_faculty_upload($pdo, $me, $type, $meta, $scan['relative_path'], $scan['result']);
    if ($stored === null) {
        unset($_SESSION['pending_scan']);
        $_SESSION['flash_error'] = 'The uploaded file could not be stored. Please upload it again.';
        header('Location: ' . BASE_URL . '/faculty/submit_document.php');
        exit;
    }
    [$request_id, $document_id] = $stored;

    // Relevant information -> PDS
    $extra_lines = [];
    $pds_note = '';
    if ($type === 'Certificate' && in_array($subtype, ['Seminar', 'Training'], true) && !empty($_POST['add_to_pds'])) {
        $page = pds_add_training($pdo, $me['user_id'], (array)($_POST['ld'] ?? []), $document_id);
        if ($page !== null) {
            $extra_lines[] = 'PDS Section VI (Learning and Development) was updated with this ' . strtolower($subtype) . '.';
            $pds_note = " It was also added to Section VI (Learning and Development) of your PDS ({$page}).";
        }
    }
    if ($type === 'PDS') {
        $filled = pds_import_upload($pdo, $me['user_id'], $scan['result']['pds_fields'] ?? [], $document_id);
        $pds_note = $filled
            ? " {$filled} empty field" . ($filled === 1 ? ' was' : 's were') . ' filled in on your digital PDS from this file -- please review it.'
            : ' Please review your digital PDS and update it if anything changed.';
    }

    notify_new_upload($pdo, $me['full_name'], $type, $subtype, $request_id, $extra_lines);

    unset($_SESSION['pending_scan']);
    $_SESSION['flash_success'] = 'Your ' . document_type_label($type, $subtype) . ' has been uploaded to your 201 file.'
        . $pds_note . ' The Program Chair and Dean have been notified.';
    header('Location: ' . BASE_URL . ($type === 'PDS' ? '/faculty/pds.php' : '/faculty/my_documents.php?type=' . urlencode($type)));
    exit;
}

// ---------------------------------------------------------------------
// Step 2b: faculty cancelled the previewed scan -> discard temp file
// ---------------------------------------------------------------------
if ($action === 'cancel' && isset($_SESSION['pending_scan'])) {
    $abs = ROOT_PATH . '/' . $_SESSION['pending_scan']['relative_path'];
    if (is_file($abs)) { @unlink($abs); }
    unset($_SESSION['pending_scan']);
    header('Location: ' . BASE_URL . '/faculty/submit_document.php');
    exit;
}

// ---------------------------------------------------------------------
// Step 1: faculty uploaded a scan -> run OCR/AI extraction + preview
// ---------------------------------------------------------------------
if ($action === 'scan') {
    if (empty($_FILES['scan_file']) || $_FILES['scan_file']['error'] !== UPLOAD_ERR_OK) {
        $_SESSION['flash_error'] = 'Please choose a file to upload.';
        header('Location: ' . BASE_URL . '/faculty/submit_document.php');
        exit;
    }
    $file = $_FILES['scan_file'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    if (!in_array($ext, ALLOWED_EXTENSIONS, true) || !in_array($file['type'], ALLOWED_MIME_TYPES, true)) {
        $_SESSION['flash_error'] = 'Unsupported file type. Allowed: JPG, PNG, WEBP, PDF.';
        header('Location: ' . BASE_URL . '/faculty/submit_document.php');
        exit;
    }
    if ($file['size'] > MAX_UPLOAD_BYTES) {
        $_SESSION['flash_error'] = 'File is too large. Maximum size is 10MB.';
        header('Location: ' . BASE_URL . '/faculty/submit_document.php');
        exit;
    }

    if (!is_dir(TEMP_SCAN_PATH)) { @mkdir(TEMP_SCAN_PATH, 0775, true); }
    $filename = safe_filename($file['name']);
    $destAbs = TEMP_SCAN_PATH . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $destAbs)) {
        $_SESSION['flash_error'] = 'Could not save the uploaded file. Please try again.';
        header('Location: ' . BASE_URL . '/faculty/submit_document.php');
        exit;
    }

    $result = OcrProcessor::process($destAbs, $me['full_name']);

    $_SESSION['pending_scan'] = [
        'relative_path' => 'temp_scans/' . $filename,
        'original_name' => $file['name'],
        'result'         => $result,
    ];

    header('Location: ' . BASE_URL . '/faculty/submit_document.php');
    exit;
}

$pending = $_SESSION['pending_scan'] ?? null;

include __DIR__ . '/../includes/header.php';
?>

<h3 class="fw-bold mb-1">Upload Document</h3>
<p class="text-muted mb-4">Upload any document for your 201 file -- PDS, certificates, diploma, TOR, FTA, IPCR<?= $employment_type === 'part_time' ? ', contract of service, affidavit of undertaking' : '' ?>, or other documents. The system reads it, identifies the document type, and files it in the right folder. Seminar and training certificates are also added to your PDS automatically.</p>

<?php if (!$pending): ?>

  <div class="card stat-card mx-auto" style="max-width:640px;">
    <div class="card-header bg-white fw-semibold">
      <i class="fa-solid fa-file-arrow-up text-brand"></i> Document Upload &amp; OCR/AI Extraction Portal
    </div>
    <div class="card-body">
      <form action="submit_document.php" method="POST" enctype="multipart/form-data">
        <input type="hidden" name="action" value="scan">

        <div class="mb-3">
          <label class="form-label small fw-semibold">Faculty Name</label>
          <input type="text" class="form-control" value="<?= h($me['full_name']) ?>" disabled>
        </div>

        <div class="mb-3">
          <label class="form-label small fw-semibold">Upload / Scan File</label>
          <input type="file" name="scan_file" class="form-control" accept=".jpg,.jpeg,.png,.webp,.pdf" required>
          <div class="form-text">JPG, PNG, WEBP, or PDF -- max 10MB. The document type is detected automatically in the next step.</div>
        </div>

        <button type="submit" class="btn btn-brand w-100">
          <i class="fa-solid fa-magnifying-glass"></i> Scan &amp; Preview
        </button>
      </form>
    </div>
  </div>

<?php else:
  $result = $pending['result'];
  $suggested = $result['detected_type'] ?? null;
  $suggested_sub = $result['detected_subtype'] ?? null;
  $not_applicable = $suggested && !isset($categories[$suggested]);
  if ($not_applicable) { $suggested = null; }
  $period = $result['period'] ?? [];
  $training = $result['training'] ?? [];
  $ld_types = pds_ld_columns()['ld_type'][2];
?>

  <div class="card stat-card mx-auto" style="max-width:820px;">
    <div class="card-header bg-white fw-semibold">
      <i class="fa-solid fa-magnifying-glass-chart text-brand"></i> Extracted Data Preview
    </div>
    <div class="card-body">

      <div class="alert <?= $suggested ? 'alert-info' : 'alert-warning' ?> small">
        <i class="fa-solid fa-robot"></i> <?= h($result['confidence_note']) ?>
        <?php if ($not_applicable): ?>
          <div class="mt-1">The detected type (<?= h(document_type_label($result['detected_type'])) ?>) doesn't apply to <?= h(str_replace('_', '-', $employment_type)) ?> faculty -- please choose the correct type.</div>
        <?php endif; ?>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-md-6">
          <label class="form-label small fw-semibold">Faculty Name</label>
          <input type="text" class="form-control" value="<?= h($me['full_name']) ?>" disabled>
        </div>
        <div class="col-md-6">
          <label class="form-label small fw-semibold">File</label>
          <input type="text" class="form-control text-truncate" value="<?= h($pending['original_name']) ?>" disabled>
        </div>
      </div>

      <form action="submit_document.php" method="POST" id="uploadForm">

        <div class="row g-3 mb-3">
          <div class="col-md-6">
            <label class="form-label small fw-semibold">Document Type <span class="text-danger">*</span></label>
            <select name="document_type" id="docType" class="form-select" required>
              <option value="">-- Select type --</option>
              <?php foreach ($categories as $key => $meta): ?>
                <option value="<?= h($key) ?>" <?= $suggested === $key ? 'selected' : '' ?>>
                  <?= h($meta['label']) ?><?= $suggested === $key ? ' (auto-detected)' : '' ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <?php foreach ($categories as $key => $meta): if (empty($meta['subtypes'])) continue; ?>
          <div class="col-md-6 type-field" data-types="<?= h($key) ?>">
            <label class="form-label small fw-semibold"><?= h($meta['short']) ?> Type</label>
            <select name="document_subtype" class="form-select" disabled>
              <?php foreach ($meta['subtypes'] as $skey => $slabel):
                $sel = $suggested === $key ? $suggested_sub === $skey : ($meta['default_subtype'] ?? '') === $skey; ?>
                <option value="<?= h($skey) ?>" <?= $sel ? 'selected' : '' ?>><?= h($slabel) ?><?= ($suggested === $key && $suggested_sub === $skey) ? ' (auto-detected)' : '' ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <?php endforeach; ?>

          <div class="col-md-3 col-6 type-field" data-types="FTA IPCR">
            <label class="form-label small fw-semibold">Semester</label>
            <select name="semester" class="form-select" disabled>
              <?php $sem_sel = $period['semester'] ?? $period_now['semester'];
              foreach (semester_options() as $num => $label): ?>
                <option value="<?= $num ?>" <?= $sem_sel === $num ? 'selected' : '' ?>><?= h($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-3 col-6 type-field" data-types="FTA IPCR">
            <label class="form-label small fw-semibold">Academic Year</label>
            <select name="academic_year" class="form-select" disabled>
              <?php $ay_sel = $period['academic_year'] ?? $period_now['academic_year'];
              $ay_list = academic_year_options();
              if (!in_array($ay_sel, $ay_list, true)) { array_unshift($ay_list, $ay_sel); }
              foreach ($ay_list as $ay): ?>
                <option value="<?= h($ay) ?>" <?= $ay_sel === $ay ? 'selected' : '' ?>><?= h($ay) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-3 col-6 type-field" data-types="PDS">
            <label class="form-label small fw-semibold">PDS Year</label>
            <input type="number" name="period_year" class="form-control" value="<?= (int)date('Y') ?>" min="1990" max="<?= (int)date('Y') + 1 ?>" disabled>
          </div>

          <div class="col-md-6">
            <label class="form-label small fw-semibold">Expiration Date (if any)</label>
            <input type="date" name="expiration_date" class="form-control">
            <div class="form-text">Leave blank if this document does not expire.</div>
          </div>
        </div>

        <!-- Seminar / training certificate -> PDS Section VI (L&D) -->
        <div class="card border mb-3 type-field" data-types="Certificate" data-subtypes="Seminar Training" id="pdsBlock">
          <div class="card-header bg-light small fw-semibold d-flex align-items-center gap-2">
            <input type="checkbox" class="form-check-input m-0" name="add_to_pds" value="1" id="addToPds" checked disabled>
            <label for="addToPds" class="mb-0">Add to my PDS -- Section VI: Learning and Development (L&amp;D)</label>
          </div>
          <div class="card-body">
            <p class="small text-muted mb-3">Extracted from the certificate -- check and correct these before saving.</p>
            <div class="row g-2">
              <div class="col-12">
                <label class="form-label small">Title of Training / Seminar</label>
                <input type="text" name="ld[title]" class="form-control form-control-sm" value="<?= h($training['title'] ?? '') ?>" maxlength="255" disabled>
              </div>
              <div class="col-md-3 col-6">
                <label class="form-label small">From</label>
                <input type="date" name="ld[date_from]" class="form-control form-control-sm" value="<?= h($training['date_from'] ?? '') ?>" disabled>
              </div>
              <div class="col-md-3 col-6">
                <label class="form-label small">To</label>
                <input type="date" name="ld[date_to]" class="form-control form-control-sm" value="<?= h($training['date_to'] ?? '') ?>" disabled>
              </div>
              <div class="col-md-3 col-6">
                <label class="form-label small">Number of Hours</label>
                <input type="number" step="0.5" min="0" name="ld[hours]" class="form-control form-control-sm" value="<?= h($training['hours'] ?? '') ?>" disabled>
              </div>
              <div class="col-md-3 col-6">
                <label class="form-label small">Type of L&amp;D</label>
                <select name="ld[ld_type]" class="form-select form-select-sm" disabled>
                  <?php foreach ($ld_types as $t): ?>
                    <option <?= ($training['ld_type'] ?? 'Technical') === $t ? 'selected' : '' ?>><?= h($t) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-12">
                <label class="form-label small">Conducted / Sponsored By</label>
                <input type="text" name="ld[conducted_by]" class="form-control form-control-sm" value="<?= h($training['conducted_by'] ?? '') ?>" maxlength="255" disabled>
              </div>
            </div>
          </div>
        </div>

        <div class="alert alert-light border small type-field" data-types="PDS">
          <i class="fa-solid fa-id-card text-brand"></i> This file will be kept as your original PDS record for the year. Any details the system can read from it are copied into the empty fields of your
          <a href="<?= BASE_URL ?>/faculty/pds.php">digital PDS</a> -- nothing you've already entered is overwritten.
        </div>

        <div class="mb-3">
          <label class="form-label small fw-semibold">Extracted Text Preview</label>
          <textarea class="form-control" rows="6" disabled><?= h($result['text'] !== '' ? $result['text'] : '(No text extracted -- please confirm the details manually.)') ?></textarea>
        </div>

        <div class="d-flex gap-2">
          <button type="submit" name="action" value="confirm" class="btn btn-brand flex-grow-1">
            <i class="fa-solid fa-check"></i> Upload to My 201 File
          </button>
          <button type="submit" name="action" value="cancel" formnovalidate class="btn btn-outline-secondary">
            <i class="fa-solid fa-xmark"></i> Cancel
          </button>
        </div>
      </form>

    </div>
  </div>

<script>
// Show only the fields that apply to the chosen document type / subtype.
// Hidden fields are disabled so they aren't submitted.
(function () {
  var form = document.getElementById('uploadForm');
  var typeSel = document.getElementById('docType');
  function currentSubtype() {
    var s = form.querySelector('.type-field:not(.d-none) select[name="document_subtype"]');
    return s ? s.value : '';
  }
  function refresh() {
    var type = typeSel.value;
    // Pass 1: type-level fields (so the subtype select is known)
    form.querySelectorAll('.type-field:not([data-subtypes])').forEach(function (el) {
      var show = el.dataset.types.split(' ').indexOf(type) !== -1;
      el.classList.toggle('d-none', !show);
      el.querySelectorAll('input, select').forEach(function (i) { i.disabled = !show; });
    });
    // Pass 2: subtype-level fields
    var sub = currentSubtype();
    form.querySelectorAll('.type-field[data-subtypes]').forEach(function (el) {
      var show = el.dataset.types.split(' ').indexOf(type) !== -1 && el.dataset.subtypes.split(' ').indexOf(sub) !== -1;
      el.classList.toggle('d-none', !show);
      el.querySelectorAll('input, select').forEach(function (i) { i.disabled = !show; });
    });
  }
  form.addEventListener('change', function (e) {
    if (e.target === typeSel || e.target.name === 'document_subtype') { refresh(); }
  });
  refresh();
})();
</script>

<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
