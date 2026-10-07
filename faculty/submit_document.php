<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/pds.php';
require_once __DIR__ . '/../includes/pds_import.php';
require_once __DIR__ . '/../ocr/OcrProcessor.php';
require_once __DIR__ . '/../ocr/DocScanner.php';
require_role(['faculty', 'program_chair', 'dean']);   // Program Chairs and Deans keep their own 201 file too

$page_title = 'Upload Document';
$me = current_user();
$employment_type = faculty_employment_type($pdo, $me['user_id']);
$categories = categories_for_faculty($employment_type);
$period_now = current_academic_period();

$action = $_POST['action'] ?? '';

/** Temp files of a pending scan other than the one being filed (photo, page preview, photo preview). */
function pending_extra_files(array $pending): array {
    return array_values(array_filter([$pending['raw_path'] ?? null, $pending['page_image'] ?? null, $pending['orig_preview'] ?? null]));
}
function discard_files(array $rel_paths): void {
    foreach ($rel_paths as $rel) {
        $abs = ROOT_PATH . '/' . $rel;
        if (is_file($abs)) { @unlink($abs); }
    }
}
/** OCR + categorization of a scan; uses the cleaned-up page when there is one. */
function ocr_pending(array $pending, string $name): array {
    if (!empty($pending['page_image'])) {
        return OcrProcessor::process(ROOT_PATH . '/' . $pending['page_image'], $name, true);
    }
    return OcrProcessor::process(ROOT_PATH . '/' . $pending['relative_path'], $name);
}

// Preview images of the pending scan (temp_scans/ is not web-accessible)
if (isset($_GET['preview'])) {
    $rel = ($_SESSION['pending_scan'] ?? [])[$_GET['preview'] === 'orig' ? 'orig_preview' : 'page_image'] ?? null;
    if ($rel && is_file(ROOT_PATH . '/' . $rel)) {
        header('Content-Type: image/jpeg');
        header('Cache-Control: private, no-store');
        readfile(ROOT_PATH . '/' . $rel);
    } else {
        http_response_code(404);
    }
    exit;
}

// A request bigger than post_max_size arrives with $_POST and $_FILES
// empty, so it would otherwise just fall through to the blank upload form.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$_POST && !$_FILES && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    $_SESSION['flash_error'] = 'That file is too large to upload. The maximum size is ' . (MAX_UPLOAD_BYTES / 1024 / 1024) . 'MB.';
    header('Location: ' . BASE_URL . '/faculty/submit_document.php');
    exit;
}
// Every step (scan, re-crop, confirm, cancel) is a POST with the CSRF token
if ($action !== '' && !csrf_valid()) {
    $_SESSION['flash_error'] = 'Your session expired before the form was sent. Please try again.';
    header('Location: ' . BASE_URL . '/faculty/submit_document.php');
    exit;
}

// ---------------------------------------------------------------------
// Step 2a: faculty confirmed the previewed scan -> file it immediately,
// update the PDS where applicable, then notify the Program Chair / Dean.
// (No approval step -- the upload is accepted once it's stored.)
// ---------------------------------------------------------------------
if ($action === 'confirm' && isset($_SESSION['pending_scan'])) {
    $scan = $_SESSION['pending_scan'];
    $check = classification_check($scan['result'], $categories);
    $type = $_POST['document_type'] ?? '';
    // Below the confidence threshold nothing is pre-selected, so an empty type
    // here means the faculty member didn't pick one -- the upload can't go through.
    if (!array_key_exists($type, $categories)) {
        $_SESSION['flash_error'] = $check['low']
            ? "The system is not confident about this document's type. Please select the correct category before uploading."
            : 'Please choose a valid document type before confirming.';
        header('Location: ' . BASE_URL . '/faculty/submit_document.php');
        exit;
    }
    $cat = $categories[$type];

    $subtype = $_POST['document_subtype'] ?? '';
    $subtype = isset($cat['subtypes'][$subtype]) ? $subtype : ($cat['default_subtype'] ?? null);

    // Seminar / training details (certificates) or the date issued (anything else), as reviewed on the preview
    $details = document_details_clean((array)($_POST['details'] ?? []));
    if ($type !== 'Certificate') {
        $details = array_merge(array_fill_keys(document_detail_columns(), null), ['date_issued' => $details['date_issued']]);
    }
    $meta = [
        'subtype'        => $subtype,
        'expiration'     => ($_POST['expiration_date'] ?? '') ?: null,
        'classification' => $check,   // stored with the document: score, the system's guess, low-confidence flag
        'details'        => $details,
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

    // A re-upload is a new version of a document type the uploader already has
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM documents WHERE faculty_id = ? AND document_type = ? AND document_subtype <=> ? AND status = 'active'");
    $stmt->execute([$me['user_id'], $type, $subtype]);
    $reupload = (int)$stmt->fetchColumn() > 0;

    $stored = store_faculty_upload($pdo, $me, $type, $meta, $scan['relative_path'], $scan['result']);
    if ($stored === null) {
        if (is_file(ROOT_PATH . '/' . $scan['relative_path'])) {
            // Still have the scan: stay on the preview so it can simply be confirmed again
            $_SESSION['flash_error'] = 'The document could not be saved just now. Please press "Upload to My 201 File" again.';
        } else {
            unset($_SESSION['pending_scan']);
            $_SESSION['flash_error'] = 'The uploaded file could not be stored. Please upload it again.';
        }
        header('Location: ' . BASE_URL . '/faculty/submit_document.php');
        exit;
    }
    [$request_id, $document_id] = $stored;
    log_my_activity($pdo, 'UPLOAD', "Uploaded document #{$document_id} \"{$scan['original_name']}\" as " . document_type_label($type, $subtype)
        . ($check['low'] ? ' (low-confidence categorization, ' . confidence_label($check['score']) . ')' : '') . '.');
    // A PDS: read what it holds now (the cleaned-up page of a photo reads best), for the review page
    $pds_import = null;
    if ($type === 'PDS') {
        $stmt = $pdo->prepare("SELECT file_path FROM documents WHERE document_id = ?");
        $stmt->execute([$document_id]);
        $source = !empty($scan['page_image']) && is_file(ROOT_PATH . '/' . $scan['page_image'])
            ? ROOT_PATH . '/' . $scan['page_image'] : ROOT_PATH . '/' . $stmt->fetchColumn();
        $pds_import = pds_import_extract_file($source, $scan['result']['text'] ?? null);
        $_SESSION['pds_import'][$document_id] = ['ex' => $pds_import, 'nonce' => bin2hex(random_bytes(12))];
    }
    discard_files(pending_extra_files($scan));   // the original photo isn't kept -- the cleaned-up PDF is the record

    // Relevant information -> PDS
    $extra_lines = [];
    $pds_note = '';
    if ($type === 'Certificate' && in_array($subtype, ['Seminar', 'Training'], true) && !empty($_POST['add_to_pds'])) {
        $page = pds_add_training($pdo, $me['user_id'], [
            'title' => $details['title'], 'date_from' => $details['date_start'], 'date_to' => $details['date_end'],
            'hours' => $details['hours'], 'ld_type' => $_POST['ld_type'] ?? '', 'conducted_by' => $details['conducted_by'],
        ], $document_id);
        if ($page !== null) {
            log_my_activity($pdo, 'PDS_UPDATE', "Added a Learning and Development entry ({$page}) to their PDS from uploaded document #{$document_id}.");
            $extra_lines[] = 'PDS Section VI (Learning and Development) was updated with this ' . strtolower($subtype) . '.';
            $pds_note = " It was also added to Section VI (Learning and Development) of your PDS ({$page}).";
        }
    }
    if ($pds_import !== null) {
        $pds_note = $pds_import['sections']
            ? ' Review what was read from it below -- nothing goes into your digital PDS until you confirm.'
            : ' No PDS details could be read from it automatically, so please update your digital PDS by hand.';
    }

    $stmt = $pdo->prepare("SELECT user_id, role, full_name, employment_type, program, college FROM users WHERE user_id = ?");
    $stmt->execute([$me['user_id']]);
    notify_new_upload($pdo, $stmt->fetch(), $type, $subtype, $request_id, $extra_lines, $reupload);

    // A document already more than ARCHIVE_AFTER_YEARS old goes straight to the archive
    $archived = auto_archive_old_documents($pdo, $me, $document_id)['archived'] > 0;

    unset($_SESSION['pending_scan']);
    $_SESSION['flash_success'] = 'Your ' . document_type_label($type, $subtype) . ' has been uploaded to your 201 file.'
        . ($archived ? ' It is more than ' . ARCHIVE_AFTER_YEARS . ' years old, so it was filed directly in your archive (My Archive).' : '')
        . $pds_note . ($me['role'] === 'faculty' ? ' Your Program Chair and Dean have been notified.' : '');
    header('Location: ' . BASE_URL . match (true) {
        !empty($pds_import['sections']) => '/faculty/pds_import.php?document=' . $document_id,
        $archived                       => '/archive.php',
        $type === 'PDS'                 => '/faculty/pds.php',
        default                         => '/faculty/my_documents.php?type=' . urlencode($type),
    });
    exit;
}

// ---------------------------------------------------------------------
// Step 2b: faculty cancelled the previewed scan -> discard temp file
// ---------------------------------------------------------------------
if ($action === 'cancel' && isset($_SESSION['pending_scan'])) {
    discard_files(array_merge([$_SESSION['pending_scan']['relative_path']], pending_extra_files($_SESSION['pending_scan'])));
    unset($_SESSION['pending_scan']);
    header('Location: ' . BASE_URL . '/faculty/submit_document.php');
    exit;
}

// ---------------------------------------------------------------------
// Step 2c: faculty adjusted the crop corners (or chose the whole photo)
// -> re-process the original photo, then OCR / categorize again
// ---------------------------------------------------------------------
if ($action === 'recrop' && isset($_SESSION['pending_scan']['raw_path'])) {
    $scan = $_SESSION['pending_scan'];
    $whole = !empty($_POST['whole']);
    $corners = null;
    if (!$whole) {
        $c = json_decode((string)($_POST['corners'] ?? ''), true);
        if (is_array($c) && count($c) === 4) {
            foreach ($c as $pt) {
                if (!is_array($pt) || count($pt) !== 2 || !is_numeric($pt[0] ?? null) || !is_numeric($pt[1] ?? null)) { $c = null; break; }
            }
        } else {
            $c = null;
        }
        $corners = $c;
    }
    $base = ROOT_PATH . '/' . preg_replace('/\.pdf$/', '', $scan['relative_path']);
    $info = ($whole || $corners) ? DocScanner::scan(ROOT_PATH . '/' . $scan['raw_path'], $base, $corners, $whole) : null;
    if ($info === null) {
        $_SESSION['flash_error'] = 'The crop could not be applied. Please try again.';
    } else {
        $scan['docscan'] = $info;
        $scan['result'] = ocr_pending($scan, $me['full_name']);
        $_SESSION['pending_scan'] = $scan;
    }
    header('Location: ' . BASE_URL . '/faculty/submit_document.php');
    exit;
}

// ---------------------------------------------------------------------
// Step 1: faculty uploaded a scan -> run OCR/AI extraction + preview
// ---------------------------------------------------------------------
if ($action === 'scan') {
    $upload_error = $_FILES['scan_file']['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($upload_error !== UPLOAD_ERR_OK) {
        $_SESSION['flash_error'] = match ($upload_error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'That file is too large to upload. The maximum size is ' . (MAX_UPLOAD_BYTES / 1024 / 1024) . 'MB.',
            UPLOAD_ERR_PARTIAL => 'The upload was interrupted. Please try again.',
            UPLOAD_ERR_NO_FILE => 'Please choose a file to upload.',
            default => 'The file could not be uploaded because of a server problem. Please try again or contact the administrator.',
        };
        if (!in_array($upload_error, [UPLOAD_ERR_NO_FILE, UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE, UPLOAD_ERR_PARTIAL], true)) {
            error_log('Upload failed with PHP upload error ' . $upload_error);
        }
        header('Location: ' . BASE_URL . '/faculty/submit_document.php');
        exit;
    }
    $file = $_FILES['scan_file'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    // Check the file's real type; browsers report it inconsistently (image/jpg, image/pjpeg, '')
    $mime = function_exists('finfo_open') ? (finfo_file(finfo_open(FILEINFO_MIME_TYPE), $file['tmp_name']) ?: '') : $file['type'];

    $is_xlsx = $ext === 'xlsx' && in_array($mime, XLSX_MIME_TYPES, true) && xlsx_read_sheets($file['tmp_name']) !== null;   // must open as a workbook
    if (!$is_xlsx && (!in_array($ext, ALLOWED_EXTENSIONS, true) || !in_array($mime, ALLOWED_MIME_TYPES, true))) {
        $_SESSION['flash_error'] = 'Unsupported file type. Allowed: JPG, PNG, WEBP, PDF, and Excel (.xlsx) for the PDS soft copy.';
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
        error_log('Could not move upload to ' . $destAbs . ' (is temp_scans/ writable?)');
        $_SESSION['flash_error'] = 'Could not save the uploaded file. Please try again.';
        header('Location: ' . BASE_URL . '/faculty/submit_document.php');
        exit;
    }

    $pending = ['relative_path' => 'temp_scans/' . $filename, 'original_name' => $file['name']];

    // Photo of a paper document: find the page, crop & straighten it, and
    // file that (as a PDF) instead of the photo. OCR runs on the clean page.
    if (in_array($ext, DocScanner::IMAGE_EXTENSIONS, true)) {
        $base = pathinfo($filename, PATHINFO_FILENAME) . '_scan';
        $info = DocScanner::scan($destAbs, TEMP_SCAN_PATH . '/' . $base);
        if ($info !== null) {
            $pending = [
                'relative_path' => 'temp_scans/' . $base . '.pdf',
                'original_name' => $file['name'],
                'raw_path'      => 'temp_scans/' . $filename,
                'page_image'    => 'temp_scans/' . $base . '.jpg',
                'orig_preview'  => 'temp_scans/' . $base . '_orig.jpg',
                'docscan'       => $info,
            ];
        }
    }
    $pending['result'] = ocr_pending($pending, $me['full_name']);
    $_SESSION['pending_scan'] = $pending;

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
      <form action="submit_document.php" method="POST" enctype="multipart/form-data" id="scanForm">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="scan">

        <div class="mb-3">
          <label class="form-label small fw-semibold">Faculty Name</label>
          <input type="text" class="form-control" value="<?= h($me['full_name']) ?>" disabled>
        </div>

        <div class="mb-3">
          <label class="form-label small fw-semibold">Upload / Scan File</label>
          <div class="d-flex gap-2 flex-wrap">
            <button type="button" class="btn btn-outline-brand flex-fill" id="chooseBtn"><i class="fa-solid fa-folder-open"></i> Choose File</button>
            <button type="button" class="btn btn-outline-brand flex-fill" id="photoBtn"><i class="fa-solid fa-camera"></i> Take Photo</button>
          </div>
          <!-- Only one of these carries name="scan_file" at a time: whichever was used last -->
          <input type="file" name="scan_file" id="fileInput" accept=".jpg,.jpeg,.png,.webp,.pdf,.xlsx" class="d-none">
          <input type="file" id="cameraInput" accept="image/*" capture="environment" class="d-none">
          <div class="small mt-2" id="chosenFile"><span class="text-muted">No file selected.</span></div>
          <div class="form-text">JPG, PNG, WEBP, or PDF -- max <?= MAX_UPLOAD_BYTES / 1024 / 1024 ?>MB. Photos of paper documents are cropped and straightened automatically and saved as PDF; the document type is detected in the next step.
            For your PDS, the official Excel soft copy (.xlsx) is read most accurately; a PDF or scan of all or some of its pages works too.</div>
        </div>

        <button type="submit" class="btn btn-brand w-100" id="scanBtn" disabled>
          <i class="fa-solid fa-magnifying-glass"></i> Scan &amp; Preview
        </button>
      </form>
    </div>
  </div>

  <!-- Desktop "Take Photo": webcam capture -->
  <div class="modal fade" id="cameraModal" tabindex="-1" aria-labelledby="cameraTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="cameraTitle">Take a photo of the document</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body text-center">
          <video id="cameraVideo" class="w-100 rounded bg-dark" autoplay playsinline muted style="max-height:65vh"></video>
          <div class="small text-muted mt-2">Hold the whole page in view on a contrasting surface -- it's cropped and straightened automatically.</div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="button" class="btn btn-brand" id="captureBtn"><i class="fa-solid fa-camera"></i> Capture</button>
        </div>
      </div>
    </div>
  </div>

<script>
(function () {
  var MAX = <?= (int)MAX_UPLOAD_BYTES ?>;
  var form = document.getElementById('scanForm');
  var fileInput = document.getElementById('fileInput');
  var cameraInput = document.getElementById('cameraInput');
  var chosen = document.getElementById('chosenFile');
  var scanBtn = document.getElementById('scanBtn');
  var stream = null;

  function use(input) {   // the input the file is submitted from
    [fileInput, cameraInput].forEach(function (i) { if (i === input) i.setAttribute('name', 'scan_file'); else i.removeAttribute('name'); });
    var f = input.files && input.files[0];
    if (!f) { chosen.innerHTML = '<span class="text-muted">No file selected.</span>'; scanBtn.disabled = true; return; }
    var tooBig = f.size > MAX;
    chosen.innerHTML = '';
    var span = document.createElement('span');
    span.className = tooBig ? 'text-danger' : 'text-body';
    span.textContent = (tooBig ? 'Too large (max ' + Math.round(MAX / 1048576) + 'MB): ' : 'Selected: ') + f.name + ' (' + (f.size / 1048576).toFixed(1) + ' MB)';
    chosen.appendChild(span);
    scanBtn.disabled = tooBig;
  }
  fileInput.addEventListener('change', function () { use(fileInput); });
  cameraInput.addEventListener('change', function () { use(cameraInput); });
  document.getElementById('chooseBtn').addEventListener('click', function () { fileInput.click(); });

  // Phones / tablets open the camera app; desktops use the webcam (if any)
  var touch = window.matchMedia && window.matchMedia('(pointer: coarse)').matches;
  document.getElementById('photoBtn').addEventListener('click', function () {
    if (touch || !(navigator.mediaDevices && navigator.mediaDevices.getUserMedia) || typeof DataTransfer === 'undefined') {
      cameraInput.click();
      return;
    }
    navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment', width: { ideal: 3840 }, height: { ideal: 2160 } }, audio: false })
      .then(function (s) {
        stream = s;
        document.getElementById('cameraVideo').srcObject = s;
        bootstrap.Modal.getOrCreateInstance(document.getElementById('cameraModal')).show();
      })
      .catch(function () {
        chosen.innerHTML = '<span class="text-danger">No camera is available (or permission was denied). Use Choose File instead.</span>';
      });
  });
  document.getElementById('cameraModal').addEventListener('hidden.bs.modal', function () {
    if (stream) { stream.getTracks().forEach(function (t) { t.stop(); }); stream = null; }
  });
  document.getElementById('captureBtn').addEventListener('click', function () {
    var video = document.getElementById('cameraVideo');
    if (!video.videoWidth) return;
    var canvas = document.createElement('canvas');
    canvas.width = video.videoWidth;
    canvas.height = video.videoHeight;
    canvas.getContext('2d').drawImage(video, 0, 0);
    canvas.toBlob(function (blob) {
      var dt = new DataTransfer();
      dt.items.add(new File([blob], 'photo-' + Date.now() + '.jpg', { type: 'image/jpeg' }));
      fileInput.files = dt.files;
      use(fileInput);
      bootstrap.Modal.getInstance(document.getElementById('cameraModal')).hide();
    }, 'image/jpeg', 0.92);
  });

  form.addEventListener('submit', function (e) {
    if (scanBtn.disabled) { e.preventDefault(); return; }
    scanBtn.disabled = true;   // scanning can take a while on the server
    scanBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Scanning document&hellip; this can take up to a minute';
  });
})();
</script>

<?php else:
  $result = $pending['result'];
  $check = classification_check($result, $categories);
  $suggested = $check['suggested'];   // null below CONFIDENCE_THRESHOLD: nothing is pre-selected
  $suggested_sub = $result['detected_subtype'] ?? null;
  $not_applicable = $check['predicted'] && !isset($categories[$check['predicted']]);
  $period = $result['period'] ?? [];
  $training = $result['training'] ?? [];
  $ld_types = pds_ld_columns()['ld_type'][2];
?>

  <div class="card stat-card mx-auto" style="max-width:820px;">
    <div class="card-header bg-white fw-semibold">
      <i class="fa-solid fa-magnifying-glass-chart text-brand"></i> Extracted Data Preview
    </div>
    <div class="card-body">

      <?php if ($check['low']): ?>
      <div class="alert alert-warning small" id="lowConfidenceAlert">
        <div class="fw-semibold mb-1"><i class="fa-solid fa-triangle-exclamation"></i> The system is not confident about this document's type. Please select the correct category.</div>
        <i class="fa-solid fa-robot"></i> <?= h($result['confidence_note']) ?>
        <?php if ($not_applicable): ?>
          <div class="mt-1">The detected type (<?= h(document_type_label($result['detected_type'])) ?>) doesn't apply to <?= h(str_replace('_', '-', $employment_type)) ?> faculty -- please choose the correct type.</div>
        <?php endif; ?>
        <?php if ($check['candidates']): ?>
          <div class="mt-2 d-flex flex-wrap align-items-center gap-1">
            <span>Possible types:</span>
            <?php foreach ($check['candidates'] as $cand): ?>
              <button type="button" class="btn btn-sm btn-outline-secondary py-0 candidate-btn" data-type="<?= h($cand) ?>"><?= h($categories[$cand]['label']) ?></button>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
      <?php else: ?>
      <div class="alert alert-info small">
        <i class="fa-solid fa-robot"></i> <?= h($result['confidence_note']) ?>
      </div>
      <?php endif; ?>

      <?php if (!empty($pending['docscan'])): $ds = $pending['docscan']; ?>
      <div class="mb-3">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-1">
          <label class="form-label small fw-semibold mb-0">Processed Document</label>
          <span class="badge <?= $ds['cropped'] ? 'bg-success' : 'bg-secondary' ?>">
            <?= $ds['cropped'] ? 'Cropped &amp; straightened automatically' : 'Whole image kept -- no page edges to crop' ?>
          </span>
        </div>
        <div class="border rounded bg-light text-center p-2" id="pagePreview">
          <img src="submit_document.php?preview=page&amp;v=<?= time() ?>" alt="Processed document" class="img-fluid" style="max-height:480px">
        </div>
        <div class="form-text">This cleaned-up page is what will be saved to your 201 file, as a PDF.
          Not right? <button type="button" class="btn btn-link btn-sm p-0 align-baseline" id="adjustBtn">Adjust the crop</button></div>

        <div id="cropEditor" class="border rounded p-2 mt-2" hidden>
          <p class="small mb-2">Drag the four corner handles onto the corners of the paper.</p>
          <div class="crop-stage">
            <img id="cropImg" src="submit_document.php?preview=orig&amp;v=<?= time() ?>" alt="Original photo" draggable="false">
            <svg id="cropSvg"><polygon id="cropPoly"></polygon></svg>
          </div>
          <form method="POST" action="submit_document.php" id="cropForm" class="d-flex gap-2 flex-wrap mt-2">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="recrop">
            <input type="hidden" name="corners" id="cornersInput">
            <button type="submit" class="btn btn-brand btn-sm"><i class="fa-solid fa-crop-simple"></i> Apply crop</button>
            <button type="submit" name="whole" value="1" class="btn btn-outline-secondary btn-sm">Use whole photo</button>
            <button type="button" class="btn btn-link btn-sm" id="cropCancel">Cancel</button>
          </form>
        </div>
      </div>
      <script>
      (function () {
        var SRC = [<?= (int)$ds['src_width'] ?>, <?= (int)$ds['src_height'] ?>];
        var start = <?= json_encode($ds['corners'] ?: [[0, 0], [$ds['src_width'] - 1, 0], [$ds['src_width'] - 1, $ds['src_height'] - 1], [0, $ds['src_height'] - 1]]) ?>;
        var editor = document.getElementById('cropEditor'), img = document.getElementById('cropImg');
        var stage = img.parentNode, poly = document.getElementById('cropPoly');
        var pts = start.map(function (p) { return p.slice(); }), handles = [];

        function scale() { return img.clientWidth / SRC[0]; }
        function draw() {
          var s = scale();
          poly.setAttribute('points', pts.map(function (p) { return (p[0] * s) + ',' + (p[1] * s); }).join(' '));
          handles.forEach(function (h, i) { h.style.left = (pts[i][0] * s) + 'px'; h.style.top = (pts[i][1] * s) + 'px'; });
        }
        pts.forEach(function (p, i) {
          var h = document.createElement('div');
          h.className = 'crop-handle';
          h.addEventListener('pointerdown', function (e) {
            e.preventDefault();
            h.setPointerCapture(e.pointerId);
            function move(ev) {
              var r = img.getBoundingClientRect(), s = scale();
              pts[i] = [Math.min(SRC[0] - 1, Math.max(0, (ev.clientX - r.left) / s)), Math.min(SRC[1] - 1, Math.max(0, (ev.clientY - r.top) / s))];
              draw();
            }
            function up() { h.removeEventListener('pointermove', move); h.removeEventListener('pointerup', up); }
            h.addEventListener('pointermove', move);
            h.addEventListener('pointerup', up);
          });
          stage.appendChild(h);
          handles.push(h);
        });
        document.getElementById('adjustBtn').addEventListener('click', function () {
          editor.hidden = false;
          if (img.complete) draw(); else img.addEventListener('load', draw, { once: true });
          editor.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
        document.getElementById('cropCancel').addEventListener('click', function () { editor.hidden = true; });
        window.addEventListener('resize', function () { if (!editor.hidden) draw(); });
        document.getElementById('cropForm').addEventListener('submit', function (e) {
          document.getElementById('cornersInput').value = JSON.stringify(pts.map(function (p) { return [Math.round(p[0]), Math.round(p[1])]; }));
          var btns = this.querySelectorAll('button');
          setTimeout(function () { btns.forEach(function (b) { b.disabled = true; }); }, 0);   // after the clicked button's value is submitted
          (e.submitter || btns[0]).innerHTML = '<span class="spinner-border spinner-border-sm"></span> Processing&hellip;';
        });
      })();
      </script>
      <?php endif; ?>

      <div class="row g-3 mb-3">
        <div class="col-md-6">
          <label class="form-label small fw-semibold">Faculty Name</label>
          <input type="text" class="form-control" value="<?= h($me['full_name']) ?>" disabled>
        </div>
        <div class="col-md-6">
          <label class="form-label small fw-semibold">File</label>
          <input type="text" class="form-control text-truncate" value="<?= h($pending['original_name']) ?><?= !empty($pending['docscan']) ? ' (saved as PDF)' : '' ?>" disabled>
        </div>
      </div>

      <form action="submit_document.php" method="POST" id="uploadForm">
        <?= csrf_field() ?>

        <div class="row g-3 mb-3">
          <div class="col-md-6">
            <label class="form-label small fw-semibold">Document Type <span class="text-danger">*</span></label>
            <select name="document_type" id="docType" class="form-select<?= $check['low'] ? ' border-warning' : '' ?>" required>
              <option value="">-- Select type --</option>
              <?php foreach ($categories as $key => $meta): ?>
                <option value="<?= h($key) ?>" <?= $suggested === $key ? 'selected' : '' ?>>
                  <?= h($meta['label']) ?><?= $suggested === $key ? ' (auto-detected)' : '' ?>
                </option>
              <?php endforeach; ?>
            </select>
            <div class="invalid-feedback">Please select the document type before uploading.</div>
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

        <!-- Certificate: seminar / training details (Seminar & Training Report), optionally added to PDS Section VI (L&D) -->
        <div class="card border mb-3 type-field" data-types="Certificate">
          <div class="card-header bg-light small fw-semibold"><i class="fa-solid fa-chalkboard-user text-brand"></i> Seminar / Training Details</div>
          <div class="card-body">
            <p class="small text-muted mb-3">Read from the certificate -- please check and correct these before uploading. Leave a field blank if it isn't on the certificate.</p>
            <?php
            $values = ['title' => $training['title'] ?? null, 'date_start' => $training['date_from'] ?? null, 'date_end' => $training['date_to'] ?? null,
                       'venue' => $training['venue'] ?? null, 'conducted_by' => $training['conducted_by'] ?? null, 'hours' => $training['hours'] ?? null,
                       'training_type' => $training['training_type'] ?? (in_array($suggested_sub, TRAINING_TYPES, true) ? $suggested_sub : null),
                       'training_level' => $training['training_level'] ?? null];
            $prefix = 'details'; $detail_groups = ['training' => true]; $detail_attr = 'disabled';
            include __DIR__ . '/../includes/document_details_fields.php';
            ?>
            <div class="border rounded p-2 mt-3 type-field" data-types="Certificate" data-subtypes="Seminar Training" id="pdsBlock">
              <div class="d-flex align-items-center gap-2 flex-wrap">
                <input type="checkbox" class="form-check-input m-0" name="add_to_pds" value="1" id="addToPds" checked disabled>
                <label for="addToPds" class="small fw-semibold mb-0">Also add to my PDS -- Section VI: Learning and Development (L&amp;D)</label>
                <label for="ldType" class="small ms-md-auto mb-0">Type of L&amp;D</label>
                <select name="ld_type" id="ldType" class="form-select form-select-sm w-auto" disabled>
                  <?php foreach ($ld_types as $t): ?>
                    <option <?= ($training['ld_type'] ?? 'Technical') === $t ? 'selected' : '' ?>><?= h($t) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="form-text">Uses the title, dates, hours and organizer above.</div>
            </div>
          </div>
        </div>

        <!-- Any other document: its own date, for the auto-archive -->
        <div class="type-field mb-3" data-types="<?= h(implode(' ', array_diff(array_keys($categories), ['Certificate']))) ?>">
          <?php $values = []; $detail_groups = ['issued' => true]; $detail_attr = 'disabled';
          include __DIR__ . '/../includes/document_details_fields.php'; ?>
          <div class="form-text">The date printed on the document. Leave blank if there is none -- the upload date is used.</div>
        </div>

        <div class="alert alert-light border small type-field" data-types="PDS">
          <i class="fa-solid fa-id-card text-brand"></i> This file will be kept as your original PDS record for the year. Next, you'll see what was read from it -- all pages or only some --
          next to your <a href="<?= BASE_URL ?>/faculty/pds.php">digital PDS</a>, and choose what to add. Nothing is overwritten without your OK.
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
    if (e.target === typeSel) { typeSel.classList.toggle('is-invalid', typeSel.value === ''); }
    if (e.target === typeSel || e.target.name === 'document_subtype') { refresh(); }
  });
  // Low confidence: a hint button picks that type
  document.querySelectorAll('.candidate-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      typeSel.value = btn.dataset.type;
      typeSel.dispatchEvent(new Event('change', { bubbles: true }));
      typeSel.focus();
    });
  });
  // No upload without a document type (the server checks this too).
  // "required" blocks the submit natively; this also highlights the field.
  typeSel.addEventListener('invalid', function () { typeSel.classList.add('is-invalid'); });
  form.addEventListener('submit', function (e) {
    if (e.submitter && e.submitter.value === 'cancel') { return; }
    if (typeSel.value === '') {
      e.preventDefault();
      typeSel.classList.add('is-invalid');
      typeSel.focus();
    }
  });
  refresh();
})();
</script>

<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
