<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../ocr/OcrProcessor.php';
require_role('faculty');

$page_title = 'Submit Document';
$me = current_user();
$categories = document_categories();

$action = $_POST['action'] ?? '';

// ---------------------------------------------------------------------
// Step 2a: faculty confirmed the previewed scan -> file it immediately
// (no Program Chair / Dean approval; they are notified instead)
// ---------------------------------------------------------------------
if ($action === 'confirm' && isset($_SESSION['pending_scan'])) {
    $scan = $_SESSION['pending_scan'];
    $type = $_POST['document_type'] ?? '';
    if (!array_key_exists($type, $categories)) {
        $_SESSION['flash_error'] = 'Please choose a valid document type before confirming.';
        header('Location: ' . BASE_URL . '/faculty/submit_document.php');
        exit;
    }

    $expiration = $_POST['expiration_date'] ?: null;

    $request_id = store_faculty_upload($pdo, $me, $type, $expiration, $scan['relative_path'], $scan['result']);
    if ($request_id === null) {
        unset($_SESSION['pending_scan']);
        $_SESSION['flash_error'] = 'The uploaded file could not be stored. Please upload it again.';
        header('Location: ' . BASE_URL . '/faculty/submit_document.php');
        exit;
    }

    unset($_SESSION['pending_scan']);
    $_SESSION['flash_success'] = "Your {$categories[$type]['label']} has been uploaded to your 201 file. The Program Chair and Dean have been notified.";
    header('Location: ' . BASE_URL . '/faculty/my_documents.php?type=' . urlencode($type));
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

<h3 class="fw-bold mb-1">Submit Document</h3>
<p class="text-muted mb-4">Scan and upload a TOR, Diploma, or Certificate. The system will extract and pre-categorize it automatically -- once you confirm, it's added straight to your 201 file and the Program Chair and Dean are notified.</p>

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
          <div class="form-text">JPG, PNG, WEBP, or PDF -- max 10MB. Document type is auto-detected in the next step.</div>
        </div>

        <button type="submit" class="btn btn-brand w-100">
          <i class="fa-solid fa-magnifying-glass"></i> Scan &amp; Preview
        </button>
      </form>
    </div>
  </div>

<?php else:
  $result = $pending['result'];
  $scores = $result['scores'];
  arsort($scores);
  $suggested = $result['detected_type'];
?>

  <div class="card stat-card mx-auto" style="max-width:760px;">
    <div class="card-header bg-white fw-semibold">
      <i class="fa-solid fa-magnifying-glass-chart text-brand"></i> Extracted Data Preview
    </div>
    <div class="card-body">

      <div class="alert <?= $suggested ? 'alert-info' : 'alert-warning' ?> small">
        <i class="fa-solid fa-robot"></i> <?= h($result['confidence_note']) ?>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-md-6">
          <label class="form-label small fw-semibold">Faculty Name</label>
          <input type="text" class="form-control" value="<?= h($me['full_name']) ?>" disabled>
        </div>
        <div class="col-md-6">
          <label class="form-label small fw-semibold">File</label>
          <input type="text" class="form-control" value="<?= h($pending['original_name']) ?>" disabled>
        </div>
      </div>

      <form action="submit_document.php" method="POST">

        <div class="row g-3 mb-3">
          <div class="col-md-6">
            <label class="form-label small fw-semibold">Document Type <span class="text-danger">*</span></label>
            <select name="document_type" class="form-select" required>
              <option value="">-- Select type --</option>
              <?php foreach ($categories as $key => $meta): ?>
                <option value="<?= h($key) ?>" <?= $suggested === $key ? 'selected' : '' ?>>
                  <?= h($meta['label']) ?><?= $suggested === $key ? ' (auto-detected)' : '' ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label small fw-semibold">Expiration Date (if any)</label>
            <input type="date" name="expiration_date" class="form-control">
            <div class="form-text">Leave blank if this document does not expire.</div>
          </div>
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

<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
