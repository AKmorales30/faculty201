<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/pds.php';
require_once __DIR__ . '/../includes/pds_import.php';
require_once __DIR__ . '/../ocr/OcrProcessor.php';
require_once __DIR__ . '/../ocr/DocScanner.php';
require_role(['faculty', 'program_chair', 'dean']);   // Program Chairs and Deans keep their own 201 file too

// Upload Document: two ways in, one page --
//   Upload & auto-detect   the file is read (OCR) and its type and details are
//                          detected, then checked by the uploader on a preview
//   Enter details manually the uploader picks the type and types the details in
//                          themselves (no OCR); the file is still attached, as
//                          every 201-file record is a document
// Either way the document goes to the uploader's 201-Files right away; the
// system flags the ones the Admin should check (Manage Document). An uploaded
// PDS is read into the digital PDS automatically.
$page_title = 'Upload Document';
$me = current_user();
$employment_type = faculty_employment_type($pdo, $me['user_id']);
$categories = categories_for_faculty($employment_type);
$has_pds = user_has_pds($pdo, (int)$me['user_id']);   // part-time faculty: no PDS (faculty_has_pds())
$period_now = current_academic_period();

$action = $_POST['action'] ?? '';

/** Temp files of a pending scan other than the one being filed (photo, page preview, photo preview). */
function pending_extra_files(array $pending): array {
    return array_values(array_filter([$pending['raw_path'] ?? null, $pending['page_image'] ?? null, $pending['orig_preview'] ?? null,
                                      ...($pending['pages'] ?? [])]));   // the pages of a several-picture upload
}
/** End a several-picture request with a JSON answer. */
function batch_json(array $data, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: private, no-store');
    echo json_encode($data);
    exit;
}
/** Delete the pictures of an unfinished several-picture upload. */
function batch_discard(?array $batch): void {
    foreach ((array)($batch['pages'] ?? []) as $p) { discard_files([$p['image']]); }
}
function discard_files(array $rel_paths): void {
    foreach ($rel_paths as $rel) {
        $abs = ROOT_PATH . '/' . $rel;
        if (is_file($abs)) { @unlink($abs); }
    }
}
/**
 * Whether $path is a HEIC / HEIF image (an iPhone photo), by its file
 * signature -- servers often report its type as application/octet-stream.
 */
function is_heic_file(string $path): bool {
    $head = (string)@file_get_contents($path, false, null, 0, 12);
    return strlen($head) === 12 && substr($head, 4, 4) === 'ftyp'
        && in_array(substr($head, 8, 4), ['heic', 'heix', 'hevc', 'hevx', 'heim', 'heis', 'mif1', 'msf1'], true);
}
/**
 * A HEIC / HEIF photo as a JPG, with whichever converter the server has:
 * heif-convert (libheif), ImageMagick, or sips (macOS). False if none could.
 */
function convert_heic_to_jpg(string $src, string $dest): bool {
    OcrProcessor::useToolDirs();
    $s = escapeshellarg($src);
    $d = escapeshellarg($dest);
    $tries = ['heif-convert' => "-q 92 $s $d", 'magick' => "{$s}[0] -auto-orient -quality 92 $d",
              'convert' => "{$s}[0] -auto-orient -quality 92 $d", 'sips' => "-s format jpeg $s --out $d"];
    foreach ($tries as $bin => $args) {
        if (trim((string)@shell_exec('command -v ' . $bin . ' 2>/dev/null')) === '') { continue; }
        @shell_exec($bin . ' ' . $args . ' >/dev/null 2>&1');
        if (is_file($dest) && @getimagesize($dest)) { return true; }
        @unlink($dest);
    }
    return false;
}
/** What a manual entry has instead of an OCR result: no text, nothing detected. */
function manual_entry_result(): array {
    return ['text' => '', 'detected_type' => null, 'detected_subtype' => null, 'scores' => [], 'confidence' => null, 'matched_name' => null,
            'confidence_note' => 'Entered manually -- no automatic detection.', 'pds' => null, 'ocr_poor' => false, 'period' => [], 'training' => []];
}
/** The "confident?" check of a pending upload; a manual entry has nothing detected, so nothing to be unsure about. */
function pending_classification(array $pending, array $categories): array {
    return !empty($pending['manual'])
        ? ['score' => null, 'predicted' => null, 'suggested' => null, 'low' => false, 'candidates' => []]
        : classification_check($pending['result'], $categories);
}
/**
 * For an uploader without a PDS (part-time faculty): a file read as a PDS is
 * offered as an ordinary document (Other Documents > Other), and nothing goes
 * into a digital PDS.
 */
function pds_detection_without_pds(array $result): array {
    if (($result['detected_type'] ?? null) !== 'PDS') {
        return $result;
    }
    $scores = $result['scores'] ?? [];
    $scores['Other'] = max((float)($scores['Other'] ?? 0), (float)($scores['PDS'] ?? 0));
    unset($scores['PDS']);
    return array_merge($result, [
        'detected_type' => 'Other', 'detected_subtype' => 'Other', 'scores' => $scores, 'pds' => null,
        'confidence_note' => trim(($result['confidence_note'] ?? '') . ' It looks like a PDS; part-time faculty don\'t keep a PDS in the system, so it is saved as an ordinary document.'),
    ]);
}
/**
 * OCR + categorization of a scan; uses the cleaned-up page when there is one. Skipped for a manual entry.
 * Reading the file never stops the upload: if it fails, nothing is detected and the uploader picks the type.
 */
function ocr_pending(array $pending, string $name): array {
    if (!empty($pending['manual'])) {
        return manual_entry_result();
    }
    try {
        if (!empty($pending['page_image'])) {
            return OcrProcessor::process(ROOT_PATH . '/' . $pending['page_image'], $name, true);
        }
        return OcrProcessor::process(ROOT_PATH . '/' . $pending['relative_path'], $name);
    } catch (Throwable $e) {
        error_log(sprintf('OCR / classification failed, continuing without it: %s: %s in %s:%d', get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()));
        return array_merge(manual_entry_result(), ['confidence' => 0.0, 'ocr_poor' => true,
            'confidence_note' => 'The file could not be read automatically. Please choose the document type and check the details.']);
    }
}

// Preview images of the pending scan (temp_scans/ is not web-accessible); &n=K: page K of a several-picture upload
if (isset($_GET['preview'])) {
    $pending_pages = ($_SESSION['pending_scan'] ?? [])['pages'] ?? null;
    $rel = $pending_pages !== null
        ? ($pending_pages[max(1, (int)($_GET['n'] ?? 1)) - 1] ?? null)
        : (($_SESSION['pending_scan'] ?? [])[$_GET['preview'] === 'orig' ? 'orig_preview' : 'page_image'] ?? null);
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
$batch_action = in_array($action, ['batch_start', 'batch_add', 'batch_finish'], true);
if ($batch_action && !csrf_valid()) {
    batch_json(['ok' => false, 'error' => 'Your session expired. Please reload the page and try again.'], 403);
}
if ($action !== '' && !csrf_valid()) {
    $_SESSION['flash_error'] = 'Your session expired before the form was sent. Please try again.';
    header('Location: ' . BASE_URL . '/faculty/submit_document.php');
    exit;
}

// ---------------------------------------------------------------------
// Step 2a: faculty confirmed the previewed scan / entered details -> store it,
// posted to their 201-Files right away (no review step), update the PDS where
// applicable, then notify the Admin and the Program Chair / Dean. Uploads the
// Admin should check (low confidence, no text read, type entered or changed
// by the uploader) are flagged in Manage Document -- still in 201-Files.
// A PDS is read into the digital PDS automatically (pds_import_auto_apply()).
// ---------------------------------------------------------------------
if ($action === 'confirm' && isset($_SESSION['pending_scan'])) {
    $scan = $_SESSION['pending_scan'];
    $check = pending_classification($scan, $categories);
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
        'entry_method'   => !empty($scan['manual']) ? 'manual' : 'ocr',
        'flag_reason'    => document_flag_for_upload($check, !empty($scan['manual']), $type, $scan['result']['text'] ?? null),
    ];
    // Its own date (as checked in the preview / typed in), ARCHIVE_UNVIEWED_YEARS+ years ago: straight to Archived Docs
    $own_date = document_own_date($details);
    $meta['archive'] = archive_at_upload($type, $own_date);
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
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM documents WHERE faculty_id = ? AND document_type = ? AND " . sql_same('document_subtype', '?') . " AND status IN ('active', 'for_review')");
    $stmt->execute([$me['user_id'], $type, $subtype]);
    $reupload = (int)$stmt->fetchColumn() > 0;

    $stored = store_faculty_upload($pdo, $me, $type, $meta, $scan['relative_path'], $scan['result'], $failure);
    if ($stored === null) {
        // The real cause is in the PHP error log ("Storing upload failed: ..."); the user gets a plain message
        if (($failure['reason'] ?? '') === 'too_large') {
            $_SESSION['flash_error'] = 'This file is too large to save right now (the limit is about ' . max(1, intdiv((int)$failure['max'], 1024)) . ' KB). '
                . 'Please upload a smaller file, or ask the Admin to raise the limit.';
        } elseif (is_file(ROOT_PATH . '/' . $scan['relative_path'])) {
            // Still have the scan: stay on the preview so it can simply be confirmed again
            $_SESSION['flash_error'] = 'The document could not be saved just now. Please press "Save Document" again. If it keeps failing, please contact the Admin.';
        } else {
            unset($_SESSION['pending_scan']);
            $_SESSION['flash_error'] = 'The uploaded file could not be stored. Please upload it again.';
        }
        header('Location: ' . BASE_URL . '/faculty/submit_document.php');
        exit;
    }
    [$request_id, $document_id] = $stored;
    log_my_activity($pdo, 'UPLOAD', "Uploaded document #{$document_id} \"{$scan['original_name']}\" as " . document_type_label($type, $subtype)
        . (!empty($scan['manual']) ? ' (details entered manually)' : '')
        . ($check['low'] ? ' (low-confidence categorization, ' . confidence_label($check['score']) . ')' : '')
        . ($meta['archive'] ? '; saved to Archived Docs (dated ' . date('M j, Y', strtotime($own_date)) . ', ' . archive_period_label() . ' or more ago)' : '; posted to 201-Files')
        . ($meta['flag_reason'] ? ' and flagged for checking (' . document_flag_text($meta['flag_reason']) . ')' : '') . '.');

    // Relevant information -> PDS
    $extra_lines = [];
    if ($type === 'PDS' && $has_pds) {
        // Read what it holds (the cleaned-up page of a photo reads best) straight into the digital PDS
        $stmt = $pdo->prepare("SELECT file_path FROM documents WHERE document_id = ?");
        $stmt->execute([$document_id]);
        $source = !empty($scan['page_image']) && is_file(ROOT_PATH . '/' . $scan['page_image'])
            ? ROOT_PATH . '/' . $scan['page_image'] : ROOT_PATH . '/' . $stmt->fetchColumn();
        try {
            // Several pictures: every page was read already, in order (no second OCR of the whole PDF)
            $pds_read = !empty($scan['pages']) ? pds_import_from_text((string)($scan['result']['text'] ?? ''), 'ocr')
                                               : pds_import_extract_file($source, $scan['result']['text'] ?? null);
            $update = pds_import_auto_apply($pdo, (int)$me['user_id'], $document_id, $pds_read, (int)$me['user_id']);
            $_SESSION['pds_update'][$document_id] = ['counts' => $update['counts'], 'flags' => $update['flags'], 'duplicates' => $update['duplicates'],
                                                     'version_id' => $update['version']['version_id'] ?? null];
            if ($update['version']) {
                $summary = pds_update_summary($update['counts']);
                log_my_activity($pdo, 'PDS_IMPORT', "Updated their digital PDS automatically from uploaded PDS document #{$document_id} -- {$summary}"
                    . ($update['flags'] ? '; ' . count($update['flags']) . ' value(s) flagged to check' : '') . " (version {$update['version']['version_number']}).");
                $extra_lines[] = "Their digital PDS was updated from it: {$summary}.";
            }
        } catch (Throwable $e) {
            error_log(sprintf('Automatic PDS update from document %d failed: %s: %s in %s:%d', $document_id, get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()));
            $_SESSION['pds_update'][$document_id] = ['error' => true];
        }
    }
    discard_files(pending_extra_files($scan));   // the original photo isn't kept -- the cleaned-up PDF is the record
    if ($type === 'Certificate' && in_array($subtype, ['Seminar', 'Training'], true) && !empty($_POST['add_to_pds']) && $has_pds) {
        $page = pds_add_training($pdo, $me['user_id'], [
            'title' => $details['title'], 'date_from' => $details['date_start'], 'date_to' => $details['date_end'],
            'hours' => $details['hours'], 'ld_type' => $_POST['ld_type'] ?? '', 'conducted_by' => $details['conducted_by'],
        ], $document_id);
        if ($page !== null) {
            log_my_activity($pdo, 'PDS_UPDATE', "Added a Learning and Development entry ({$page}) to their PDS from uploaded document #{$document_id}.");
            $extra_lines[] = 'PDS Section VI (Learning and Development) was updated with this ' . strtolower($subtype) . '.';
            $_SESSION['pds_update'][$document_id] = ['training_page' => $page];
        }
    }

    $stmt = $pdo->prepare("SELECT user_id, role, full_name, employment_type, program, college FROM users WHERE user_id = ?");
    $stmt->execute([$me['user_id']]);
    notify_new_upload($pdo, $stmt->fetch(), $type, $subtype, $request_id, $extra_lines, $reupload, $meta['flag_reason'],
                      $meta['archive'] ? $own_date : null);

    unset($_SESSION['pending_scan']);
    header('Location: ' . BASE_URL . '/faculty/submit_document.php?submitted=' . $document_id);   // step 3: saved (and what changed in the PDS)
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
        if (!$has_pds) { $scan['result'] = pds_detection_without_pds($scan['result']); }
        $_SESSION['pending_scan'] = $scan;
    }
    header('Location: ' . BASE_URL . '/faculty/submit_document.php');
    exit;
}

// ---------------------------------------------------------------------
// Step 1, several pictures (2 to MAX_UPLOAD_PICTURES) -> one PDF. The browser
// sends them one at a time, in their final order (shrunk to PICTURE_MAX_SIDE
// when it can): batch_start, batch_add for each, batch_finish. So no request
// runs long, each picture is checked, cropped and straightened (DocScanner)
// and read (OCR) as it arrives -- the original photo isn't kept. batch_finish
// puts a PDS in the page order printed on it ("Page 2 of 4") when every page
// has one, combines the pages into ONE PDF (DocScanner::combine(), Pillow)
// and opens the usual preview. Files get new names (batch_<random>_<n>);
// what the user's files were called is only shown, never used in a path.
// ---------------------------------------------------------------------
if ($action === 'batch_start') {
    batch_discard($_SESSION['photo_batch'] ?? null);
    unset($_SESSION['photo_batch']);
    $count = (int)($_POST['count'] ?? 0);
    if ($count > MAX_UPLOAD_PICTURES) {
        batch_json(['ok' => false, 'error' => 'You can upload up to ' . MAX_UPLOAD_PICTURES . ' pictures at a time.'], 400);
    }
    if ($count < 2) {
        batch_json(['ok' => false, 'error' => 'Choose at least 2 pictures to combine.'], 400);
    }
    if (!is_dir(TEMP_SCAN_PATH)) { @mkdir(TEMP_SCAN_PATH, 0775, true); }
    $_SESSION['photo_batch'] = ['id' => bin2hex(random_bytes(8)), 'expected' => $count, 'pages' => [], 'bytes' => 0, 'started' => time(),
                                'type_hint' => isset($categories[$_POST['type_hint'] ?? '']) ? $_POST['type_hint'] : null];
    batch_json(['ok' => true]);
}

if ($action === 'batch_add') {
    $batch = $_SESSION['photo_batch'] ?? null;
    if (!$batch || time() - (int)$batch['started'] > PENDING_LOGIN_SECONDS * 2) {
        batch_json(['ok' => false, 'error' => 'The upload timed out. Please choose the pictures again.'], 400);
    }
    $n = count($batch['pages']) + 1;
    if ($n > MAX_UPLOAD_PICTURES || $n > (int)$batch['expected']) {
        batch_json(['ok' => false, 'error' => 'You can upload up to ' . MAX_UPLOAD_PICTURES . ' pictures at a time.'], 400);
    }
    $file = $_FILES['page'] ?? null;
    $shown_name = mb_substr((string)($file['name'] ?? 'picture'), 0, 120);
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $too_big = in_array($file['error'] ?? 0, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true);
        batch_json(['ok' => false, 'error' => $too_big ? "\"{$shown_name}\" is too large (max " . (MAX_UPLOAD_BYTES / 1048576) . ' MB).' : "\"{$shown_name}\" could not be uploaded. Please try again."], 400);
    }
    if ($file['size'] > MAX_UPLOAD_BYTES) {
        batch_json(['ok' => false, 'error' => "\"{$shown_name}\" is too large (max " . (MAX_UPLOAD_BYTES / 1048576) . ' MB).'], 400);
    }
    if ($batch['bytes'] + $file['size'] > MAX_PICTURES_TOTAL_BYTES) {
        batch_json(['ok' => false, 'error' => 'The pictures are too large together (max ' . (MAX_PICTURES_TOTAL_BYTES / 1048576) . ' MB in all).'], 400);
    }
    // The real type, from the file's content -- not its name
    $mime = function_exists('finfo_open') ? (finfo_file(finfo_open(FILEINFO_MIME_TYPE), $file['tmp_name']) ?: '') : '';
    $ext = match ($mime) { 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', default => is_heic_file($file['tmp_name']) ? 'heic' : null };
    if ($ext === null) {
        batch_json(['ok' => false, 'error' => "\"{$shown_name}\" isn't a picture. Only JPG, PNG, WEBP or HEIC pictures can be combined into a PDF."], 400);
    }
    $base = 'batch_' . $batch['id'] . '_' . $n;
    $photo = TEMP_SCAN_PATH . "/{$base}.{$ext}";
    if (!move_uploaded_file($file['tmp_name'], $photo)) {
        error_log('Could not move a batch picture to ' . $photo . ' (is temp_scans/ writable?)');
        batch_json(['ok' => false, 'error' => 'The picture could not be saved on the server. Please try again.'], 500);
    }
    if ($ext === 'heic') {   // iPhone photo: a JPG copy for the scanner and OCR
        $converted = convert_heic_to_jpg($photo, TEMP_SCAN_PATH . "/{$base}.jpg");
        @unlink($photo);
        if (!$converted) {
            batch_json(['ok' => false, 'error' => "\"{$shown_name}\" (an iPhone HEIC photo) could not be converted on the server. Please choose it as a JPG instead "
                . '(on the iPhone: Settings > Camera > Formats > Most Compatible).'], 400);
        }
        $photo = TEMP_SCAN_PATH . "/{$base}.jpg";
    }
    @set_time_limit(180);
    // Crop and straighten it like a single photo; keep the cleaned page, not the photo
    $info = DocScanner::scan($photo, TEMP_SCAN_PATH . "/{$base}_page");
    if ($info !== null) {
        discard_files(["temp_scans/{$base}_page.pdf", "temp_scans/{$base}_page_orig.jpg", 'temp_scans/' . basename($photo)]);
        $image = "temp_scans/{$base}_page.jpg";
    } else {
        $image = 'temp_scans/' . basename($photo);   // no scanner here: the photo itself (rotated and resized when combined)
    }
    try {
        $read = OcrProcessor::readPage(ROOT_PATH . '/' . $image);
    } catch (Throwable $e) {
        error_log('OCR of batch picture failed, continuing without its text: ' . $e->getMessage());
        $read = ['text' => '', 'detect' => '', 'ocr' => true];
    }
    $batch['pages'][] = ['image' => $image, 'name' => $shown_name, 'text' => (string)$read['text'], 'detect' => (string)$read['detect'],
                         'cropped' => $info !== null && !empty($info['cropped'])];
    $batch['bytes'] += (int)$file['size'];
    $_SESSION['photo_batch'] = $batch;
    batch_json(['ok' => true, 'page' => $n]);
}

if ($action === 'batch_finish') {
    $batch = $_SESSION['photo_batch'] ?? null;
    if (!$batch || count($batch['pages']) < 2) {
        batch_json(['ok' => false, 'error' => 'The pictures were not all received. Please choose them again.'], 400);
    }
    @set_time_limit(180);
    $pages = $batch['pages'];
    $join = fn(array $pages): array => ['text' => implode("\n\n", array_column($pages, 'text')), 'detect' => implode("\n\n", array_column($pages, 'detect')), 'ocr' => true];
    $result = OcrProcessor::analyze($join($pages), $me['full_name']);

    // A PDS: the page numbers printed on the form ("CS Form 212 ..., Page 2 of 4") decide the order,
    // when every page has a different one -- also over the file names' numbering (and the user is told)
    $page_note = null;
    if ($has_pds && (($result['detected_type'] ?? null) === 'PDS' || $batch['type_hint'] === 'PDS')) {
        $printed = [];
        foreach ($pages as $i => $p) {
            if (preg_match_all('/\bpage\s*([1-9])\s*of\s*([1-9])\b/i', $p['detect'] . "\n" . $p['text'], $m)) {
                $printed[$i] = (int)end($m[1]);
            }
        }
        if (count($printed) === count($pages) && count(array_unique($printed)) === count($printed)) {
            asort($printed);
            if (array_keys($printed) !== array_keys($pages)) {
                $pages = array_values(array_map(fn($i) => $pages[$i], array_keys($printed)));
                $result = OcrProcessor::analyze($join($pages), $me['full_name']);
                $page_note = 'The pages were put in the order printed on the PDS (Page ' . implode(', ', array_values($printed)) . ')'
                    . (($_POST['name_order'] ?? '') === '1' ? ', which differs from the numbering in the file names' : '') . '. Check them below.';
            }
        }
    }

    $pdf = 'temp_scans/batch_' . $batch['id'] . '.pdf';
    if (!DocScanner::combine(array_map(fn($p) => ROOT_PATH . '/' . $p['image'], $pages), ROOT_PATH . '/' . $pdf, PICTURE_MAX_SIDE)) {
        batch_discard($batch);
        unset($_SESSION['photo_batch']);
        batch_json(['ok' => false, 'error' => 'The pictures could not be combined into a PDF on the server. Please try again, or upload them one at a time.'], 500);
    }
    $pending = [
        'relative_path' => $pdf,
        'original_name' => count($pages) . ' pictures combined into one PDF',
        'pages'         => array_column($pages, 'image'),   // the cleaned pages, for the preview; deleted on saving / cancelling
        'page_names'    => array_column($pages, 'name'),
        'page_note'     => $page_note,
        'manual'        => false,
    ];
    if ($batch['type_hint'] !== null) { $pending['type_hint'] = $batch['type_hint']; }
    $pending['result'] = $has_pds ? $result : pds_detection_without_pds($result);
    unset($_SESSION['photo_batch']);
    $_SESSION['pending_scan'] = $pending;
    batch_json(['ok' => true, 'redirect' => BASE_URL . '/faculty/submit_document.php']);
}

// ---------------------------------------------------------------------
// Step 1: faculty uploaded a scan -> run OCR/AI extraction + preview.
// Manual entry (mode=manual): the type is chosen here and no OCR runs; the
// next step is the same form, empty, to type the details in.
// ---------------------------------------------------------------------
if ($action === 'scan') {
    $manual = ($_POST['mode'] ?? '') === 'manual';
    $back_to_form = BASE_URL . '/faculty/submit_document.php' . ($manual ? '?mode=manual' : '');
    if ($manual && !isset($categories[$_POST['type_hint'] ?? ''])) {
        $_SESSION['flash_error'] = 'Please choose the document type.';
        header('Location: ' . $back_to_form);
        exit;
    }
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
        header('Location: ' . $back_to_form);
        exit;
    }
    $file = $_FILES['scan_file'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    // Check the file's real type; browsers report it inconsistently (image/jpg, image/pjpeg, '')
    $mime = function_exists('finfo_open') ? (finfo_file(finfo_open(FILEINFO_MIME_TYPE), $file['tmp_name']) ?: '') : $file['type'];

    $is_xlsx = $ext === 'xlsx' && in_array($mime, XLSX_MIME_TYPES, true) && xlsx_read_sheets($file['tmp_name']) !== null;   // must open as a workbook
    $is_heic = in_array($ext, ['heic', 'heif'], true) && is_heic_file($file['tmp_name']);
    if (!$is_xlsx && !$is_heic && (!in_array($ext, ALLOWED_EXTENSIONS, true) || !in_array($mime, ALLOWED_MIME_TYPES, true))) {
        $_SESSION['flash_error'] = 'Unsupported file type. Allowed: JPG, PNG, WEBP, HEIC (iPhone photos), PDF, and Excel (.xlsx) for the PDS soft copy.';
        header('Location: ' . $back_to_form);
        exit;
    }
    if ($file['size'] > MAX_UPLOAD_BYTES) {
        $_SESSION['flash_error'] = 'File is too large. Maximum size is 10MB.';
        header('Location: ' . $back_to_form);
        exit;
    }

    if (!is_dir(TEMP_SCAN_PATH)) { @mkdir(TEMP_SCAN_PATH, 0775, true); }
    $filename = safe_filename($file['name']);
    $destAbs = TEMP_SCAN_PATH . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $destAbs)) {
        error_log('Could not move upload to ' . $destAbs . ' (is temp_scans/ writable?)');
        $_SESSION['flash_error'] = 'Could not save the uploaded file. Please try again.';
        header('Location: ' . $back_to_form);
        exit;
    }

    // iPhone photo (HEIC / HEIF): continue with a JPG copy -- the OCR and page-cropping tools can't read HEIC
    if (in_array($ext, ['heic', 'heif'], true)) {
        $jpg = pathinfo($filename, PATHINFO_FILENAME) . '.jpg';
        $converted = convert_heic_to_jpg($destAbs, TEMP_SCAN_PATH . '/' . $jpg);
        @unlink($destAbs);
        if (!$converted) {
            error_log('HEIC upload could not be converted: no working heif-convert / ImageMagick / sips');
            $_SESSION['flash_error'] = 'This iPhone photo (HEIC) could not be converted on the server. Please upload it as a JPG instead: '
                . 'use the Take Photo button, or on the iPhone set Settings > Camera > Formats > Most Compatible, then take the photo again.';
            header('Location: ' . $back_to_form);
            exit;
        }
        [$filename, $destAbs, $ext] = [$jpg, TEMP_SCAN_PATH . '/' . $jpg, 'jpg'];
    }

    $pending = ['relative_path' => 'temp_scans/' . $filename, 'original_name' => $file['name'], 'manual' => $manual];
    // The type chosen before uploading (optional): pre-selected on the preview, whatever is detected
    if (isset($categories[$_POST['type_hint'] ?? ''])) { $pending['type_hint'] = $_POST['type_hint']; }

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
                'manual'        => $manual,
            ];
            if (isset($categories[$_POST['type_hint'] ?? ''])) { $pending['type_hint'] = $_POST['type_hint']; }
        }
    }
    $pending['result'] = ocr_pending($pending, $me['full_name']);
    if (!$has_pds) { $pending['result'] = pds_detection_without_pds($pending['result']); }
    $_SESSION['pending_scan'] = $pending;

    header('Location: ' . BASE_URL . '/faculty/submit_document.php');
    exit;
}

$pending = $_SESSION['pending_scan'] ?? null;

// Step 3 (saved): only for the uploader's own document, with what changed in their PDS
$submitted = null;
$pds_version = null;
if (!$pending && isset($_GET['submitted'])) {
    $stmt = $pdo->prepare("SELECT document_id, document_type, document_subtype, title, file_path, status, flag_reason, reviewed_at,
                                  date_start, date_end, date_issued, archive_reason
                           FROM documents WHERE document_id = ? AND faculty_id = ? AND status <> 'deleted'");
    $stmt->execute([(int)$_GET['submitted'], (int)$me['user_id']]);
    $submitted = $stmt->fetch() ?: null;
    if ($submitted) {
        $stmt = $pdo->prepare("SELECT version_id FROM pds_versions WHERE user_id = ? AND source_document_id = ? AND source = 'upload' ORDER BY version_id DESC LIMIT 1");
        $stmt->execute([(int)$me['user_id'], (int)$submitted['document_id']]);
        $pds_version = ($vid = $stmt->fetchColumn()) ? pds_version($pdo, (int)$vid) : null;
    }
}
$pds_info = $submitted ? ($_SESSION['pds_update'][$submitted['document_id']] ?? []) : [];
$step = $submitted ? 3 : ($pending ? 2 : 1);

include __DIR__ . '/../includes/header.php';
?>

<?php if ($step === 1): ?>
<?= admin_intro('Add a document to your 201 file. Upload it and let the system read it and detect its type, or enter its details yourself. Either way it goes to your 201-Files right away.' . ($has_pds ? ' An uploaded PDS also updates your digital PDS automatically.' : '')) ?>
<?php endif; ?>

<ol class="ui-steps" aria-label="Steps">
  <?php foreach ([1 => 'Choose a file', 2 => 'Check the details', 3 => 'Saved'] as $n => $label): ?>
    <li class="<?= $n < $step ? 'is-done' : ($n === $step ? 'is-current' : '') ?>"<?= $n === $step ? ' aria-current="step"' : '' ?>>
      <span class="ui-step-n" aria-hidden="true"><?php if ($n < $step): ?><i class="fa-solid fa-check"></i><?php else: ?><?= $n ?><?php endif; ?></span><?= h($label) ?>
    </li>
  <?php endforeach; ?>
</ol>

<?php if ($submitted): ?>
  <section class="ui-card ui-done" aria-labelledby="doneTitle">
    <span class="ui-done-icon" aria-hidden="true"><i class="fa-solid fa-circle-check"></i></span>
    <?php $saved_archived = $submitted['status'] === 'archived';
          $saved_date = $saved_archived ? document_own_date($submitted) : null; ?>
    <?php if ($saved_archived): ?>
      <h2 class="ui-done-title" id="doneTitle">Saved to Archived Docs<?= $saved_date ? ' (document dated ' . h(date('Y', strtotime($saved_date))) . ')' : '' ?></h2>
      <p class="ui-done-text"><strong><?= h(document_title($submitted)) ?></strong>
        (<?= h(document_type_label($submitted['document_type'], $submitted['document_subtype'])) ?>) is dated
        <?= $saved_date ? h(date('F j, Y', strtotime($saved_date))) : 'more than ' . h(archive_period_label()) . ' ago' ?>, more than <?= h(archive_period_label()) ?> ago,
        so it was saved to <a href="<?= BASE_URL ?>/archive.php">your Archived Docs</a>. You can view and download it there; the Admin can move it to 201-Files if it's needed.
    <?php else: ?>
    <h2 class="ui-done-title" id="doneTitle">Saved to your 201-Files</h2>
    <p class="ui-done-text"><strong><?= h(document_title($submitted)) ?></strong>
      (<?= h(document_type_label($submitted['document_type'], $submitted['document_subtype'])) ?>) is in your 201-Files now.
    <?php endif; ?>
      <?php if (document_is_flagged($submitted) && !$saved_archived): ?>The system flagged it for the Admin to check (<?= h(document_flag_text($submitted['flag_reason'])) ?>); it stays in your 201-Files, and the Admin may correct its type or details.<?php endif; ?>
      <?php if ($me['role'] === 'faculty'): ?>Your Program Chair and Dean have been notified.<?php endif; ?></p>
    <?php if ($submitted['document_type'] === 'PDS'): ?>
      <div class="ui-done-pds text-start">
        <?php if ($pds_version): ?>
          <?= pds_update_summary_html($pds_version, $me) ?>
        <?php elseif (!empty($pds_info['error'])): ?>
          <div class="alert alert-warning mb-0">Your digital PDS could not be updated from this file just now. Open <a href="<?= BASE_URL ?>/faculty/pds.php">My PDS</a> and use <em>Import into my PDS</em> next to the file to try again.</div>
        <?php elseif (!empty($pds_info['duplicates'])): ?>
          <div class="alert alert-info mb-0">Your digital PDS already has everything read from this file (<?= (int)$pds_info['duplicates'] ?> entr<?= $pds_info['duplicates'] === 1 ? 'y was' : 'ies were' ?> there already), so nothing was added.</div>
        <?php else: ?>
          <div class="alert alert-info mb-0">No PDS details could be read from this file automatically, so your digital PDS was not changed. You can fill it in on <a href="<?= BASE_URL ?>/faculty/pds.php">My PDS</a>.</div>
        <?php endif; ?>
      </div>
    <?php elseif (!empty($pds_info['training_page'])): ?>
      <p class="ui-done-text">It was also added to Section VI (Learning and Development) of your PDS (<?= h($pds_info['training_page']) ?>).</p>
    <?php endif; ?>
    <div class="ui-done-actions">
      <a href="<?= BASE_URL ?>/faculty/submit_document.php" class="btn btn-outline-brand"><i class="fa-solid fa-plus" aria-hidden="true"></i> Upload another</a>
      <?php if ($submitted['document_type'] === 'PDS'): ?><a href="<?= BASE_URL ?>/faculty/pds.php" class="btn btn-outline-brand"><i class="fa-solid fa-id-card" aria-hidden="true"></i> Open My PDS</a><?php endif; ?>
      <?php if ($saved_archived): ?>
        <a href="<?= BASE_URL ?>/archive.php" class="btn btn-brand">Go to Archived Docs</a>
      <?php else: ?>
        <a href="<?= BASE_URL ?>/faculty/my_documents.php?type=<?= h(rawurlencode($submitted['document_type'])) ?>" class="btn btn-brand">Go to 201-Files</a>
      <?php endif; ?>
    </div>
  </section>

<?php elseif (!$pending):
  $mode = ($_GET['mode'] ?? '') === 'manual' ? 'manual' : 'upload'; ?>

  <div style="max-width:820px;">
    <div class="row g-3 mb-3 create-options" role="tablist" aria-label="How to create the document">
      <div class="col-sm-6">
        <button type="button" class="create-option w-100 h-100 <?= $mode === 'upload' ? 'active' : '' ?>" id="optUpload" data-bs-toggle="pill" data-bs-target="#paneUpload"
                role="tab" aria-controls="paneUpload" aria-selected="<?= $mode === 'upload' ? 'true' : 'false' ?>">
          <i class="fa-solid fa-wand-magic-sparkles fa-lg text-brand" aria-hidden="true"></i>
          <span class="fw-semibold d-block mt-2">Upload &amp; auto-detect</span>
          <span class="small text-muted d-block">Upload a file or take a photo. The system reads it (OCR) and fills in the type and details for you to check.</span>
        </button>
      </div>
      <div class="col-sm-6">
        <button type="button" class="create-option w-100 h-100 <?= $mode === 'manual' ? 'active' : '' ?>" id="optManual" data-bs-toggle="pill" data-bs-target="#paneManual"
                role="tab" aria-controls="paneManual" aria-selected="<?= $mode === 'manual' ? 'true' : 'false' ?>">
          <i class="fa-solid fa-keyboard fa-lg text-brand" aria-hidden="true"></i>
          <span class="fw-semibold d-block mt-2">Enter details manually</span>
          <span class="small text-muted d-block">Choose the document type, attach the file, and type in the details yourself -- no automatic reading.</span>
        </button>
      </div>
    </div>

  <div class="tab-content">
  <div class="tab-pane fade <?= $mode === 'upload' ? 'show active' : '' ?>" id="paneUpload" role="tabpanel" aria-labelledby="optUpload">
  <div class="card stat-card">
    <div class="card-header bg-white fw-semibold">
      <i class="fa-solid fa-file-arrow-up text-brand"></i> Upload &amp; OCR/AI Extraction
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
          <div class="ui-dropzone" id="dropZone">
            <span class="ui-empty-icon" aria-hidden="true"><i class="fa-solid fa-cloud-arrow-up"></i></span>
            <div class="ui-dropzone-title">Drop a file here, or choose one -- or up to <?= (int)MAX_UPLOAD_PICTURES ?> pictures of the pages</div>
          <div class="d-flex gap-2 flex-wrap">
            <button type="button" class="btn btn-outline-brand flex-fill" id="chooseBtn"><i class="fa-solid fa-folder-open"></i> Choose File</button>
            <button type="button" class="btn btn-outline-brand flex-fill" id="photoBtn"><i class="fa-solid fa-camera"></i> Take Photo</button>
          </div>
          </div>
          <!-- The chosen files are kept by the script below; one file goes up in name="scan_file" as before,
               several pictures one at a time (batch_add) and are combined into one PDF -->
          <input type="file" name="scan_file" id="fileInput" accept=".jpg,.jpeg,.png,.webp,.heic,.heif,.pdf,.xlsx,image/*" multiple class="d-none">
          <input type="file" id="cameraInput" accept="image/*" capture="environment" class="d-none">
          <div class="small mt-2" id="chosenFile" aria-live="polite"><span class="text-muted">No file selected.</span></div>

          <div class="ui-page-picker mt-2" id="pagePicker" hidden>
            <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
              <span class="small fw-semibold me-auto" id="pageTotal"></span>
              <button type="button" class="btn btn-sm btn-outline-brand" id="sortByName"><i class="fa-solid fa-arrow-down-1-9" aria-hidden="true"></i> Sort by file name</button>
              <button type="button" class="btn btn-sm btn-outline-brand" id="addMore"><i class="fa-solid fa-plus" aria-hidden="true"></i> Add more</button>
            </div>
            <ol class="ui-page-grid list-unstyled mb-1" id="pageThumbs" aria-label="Pages, in order"></ol>
            <div class="form-text">Drag the pictures (or use the arrows) to change the order. Each picture becomes one page of the PDF.</div>
          </div>

          <div class="form-text">JPG, PNG, WEBP, HEIC (iPhone), or PDF -- max <?= MAX_UPLOAD_BYTES / 1024 / 1024 ?>MB.
            Several pages? Choose up to <?= (int)MAX_UPLOAD_PICTURES ?> pictures at once (max <?= MAX_PICTURES_TOTAL_BYTES / 1024 / 1024 ?>MB in all): they're put in order by the numbers in their file names and combined into one PDF.
            Photos of paper documents are cropped and straightened automatically and saved as PDF; the document type is detected in the next step.
            For your PDS, the official Excel soft copy (.xlsx) is read most accurately; a PDF or scan of all or some of its pages works too.</div>
        </div>

        <div class="mb-3">
          <label for="typeHint" class="form-label small fw-semibold">Document Type <span class="fw-normal text-muted">(optional)</span></label>
          <select name="type_hint" id="typeHint" class="form-select">
            <option value="">Detect automatically</option>
            <?php foreach ($categories as $key => $meta): ?>
              <option value="<?= h($key) ?>"><?= h($meta['label']) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="form-text">Already know what it is -- e.g. a photo of your Personal Data Sheet? Choose it here; you can still change it in the next step.</div>
        </div>

        <button type="submit" class="btn btn-brand w-100" id="scanBtn" disabled>
          <i class="fa-solid fa-magnifying-glass"></i> Scan &amp; Preview
        </button>
      </form>
    </div>
  </div>
  </div>

  <div class="tab-pane fade <?= $mode === 'manual' ? 'show active' : '' ?>" id="paneManual" role="tabpanel" aria-labelledby="optManual">
  <div class="card stat-card">
    <div class="card-header bg-white fw-semibold">
      <i class="fa-solid fa-keyboard text-brand"></i> Enter Document Details Manually
    </div>
    <div class="card-body">
      <form action="submit_document.php" method="POST" enctype="multipart/form-data" id="manualForm">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="scan">
        <input type="hidden" name="mode" value="manual">
        <div class="mb-3">
          <label for="manualType" class="form-label small fw-semibold">Document Type <span class="text-danger">*</span></label>
          <select name="type_hint" id="manualType" class="form-select" required>
            <option value="">-- Select type --</option>
            <?php foreach ($categories as $key => $meta): ?>
              <option value="<?= h($key) ?>"><?= h($meta['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mb-3">
          <label for="manualFile" class="form-label small fw-semibold">The Document's File <span class="text-danger">*</span></label>
          <input type="file" name="scan_file" id="manualFile" class="form-control" accept=".jpg,.jpeg,.png,.webp,.heic,.heif,.pdf,.xlsx" required>
          <div class="form-text">JPG, PNG, WEBP, HEIC, PDF or Excel (.xlsx), max <?= MAX_UPLOAD_BYTES / 1024 / 1024 ?>MB. Photos are still cropped and straightened, but nothing is read from the file.</div>
        </div>
        <button type="submit" class="btn btn-brand w-100" id="manualBtn"><i class="fa-solid fa-arrow-right"></i> Next: Enter the Details</button>
      </form>
    </div>
  </div>
  </div>
  </div>
  </div>

  <!-- Shown while the server reads the file (the form is still being sent) -->
  <section class="ui-card ui-scan" id="scanProgress" hidden aria-live="polite" style="max-width:820px">
    <div class="ui-scan-head">
      <span class="ui-scan-icon" aria-hidden="true"><i class="fa-solid fa-magnifying-glass"></i></span>
      <div class="min-w-0"><div class="ui-scan-title" id="scanTitle">Reading your document</div><div class="ui-cell-sub text-truncate" id="scanFile"></div></div>
    </div>
    <div class="ui-scan-bar" aria-hidden="true"><span></span></div>
    <ul class="ui-scan-steps" id="scanSteps">
      <li><i class="fa-solid fa-crop-simple" aria-hidden="true"></i> Photos are cropped and straightened</li>
      <li><i class="fa-solid fa-font" aria-hidden="true"></i> The text is read (OCR)</li>
      <li><i class="fa-solid fa-tags" aria-hidden="true"></i> The document type and details are detected</li>
    </ul>
    <p class="ui-cell-sub mb-0">This can take up to a minute. Please keep this page open.</p>
  </section>

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
  var MAX_PICTURES = <?= (int)MAX_UPLOAD_PICTURES ?>;
  var MAX_TOTAL = <?= (int)MAX_PICTURES_TOTAL_BYTES ?>;
  var MAX_SIDE = <?= (int)PICTURE_MAX_SIDE ?>;
  var form = document.getElementById('scanForm');
  var fileInput = document.getElementById('fileInput');
  var cameraInput = document.getElementById('cameraInput');
  var chosen = document.getElementById('chosenFile');
  var scanBtn = document.getElementById('scanBtn');
  var picker = document.getElementById('pagePicker');
  var thumbs = document.getElementById('pageThumbs');
  var totalEl = document.getElementById('pageTotal');
  var stream = null;

  // The chosen files, in their final order: {file, url, problem}
  var items = [];
  var manualOrder = false;   // the user moved a page: adding more doesn't re-sort
  var collator = window.Intl ? new Intl.Collator(undefined, { numeric: true, sensitivity: 'base' }) : null;
  var PICTURE = /\.(jpe?g|png|webp|heic|heif)$/i;
  var mb = function (b) { return (b / 1048576).toFixed(1) + ' MB'; };
  var isPicture = function (f) { return PICTURE.test(f.name) || /^image\//.test(f.type); };
  var hasNumber = function (f) { return /\d/.test(f.name.replace(PICTURE, '')); };

  // Natural order of the numbers in the file names ("page2" before "page10", "IMG_0012"
  // before "IMG_0105"); names without numbers keep the order they were chosen in
  function sortByName() {
    if (!items.some(function (it) { return hasNumber(it.file); })) return false;
    items.sort(function (a, b) { return collator ? collator.compare(a.file.name, b.file.name) : (a.file.name < b.file.name ? -1 : 1); });
    return true;
  }
  function nameOrder() {   // whether the current order is the file names' numbering
    if (!items.some(function (it) { return hasNumber(it.file); })) return false;
    var copy = items.slice();
    copy.sort(function (a, b) { return collator ? collator.compare(a.file.name, b.file.name) : (a.file.name < b.file.name ? -1 : 1); });
    return copy.every(function (it, i) { return it === items[i]; });
  }

  function check(f, many) {
    if (many && !isPicture(f)) return 'Only pictures can be combined. Upload a PDF or Excel file on its own.';
    if (!/\.(jpe?g|png|webp|heic|heif|pdf|xlsx)$/i.test(f.name) && !/^image\/(jpeg|png|webp|heic|heif)$/.test(f.type)) return 'Not a supported file type.';
    if (f.size > MAX) return 'Too large (max ' + Math.round(MAX / 1048576) + ' MB).';
    return '';
  }

  function add(files) {
    var list = Array.prototype.slice.call(files || []);
    if (!list.length) return;
    var room = MAX_PICTURES - items.length;
    var dropped = Math.max(0, list.length - room);
    list.slice(0, Math.max(0, room)).forEach(function (f) {
      items.push({ file: f, url: /^image\/(jpeg|png|webp|gif)$/.test(f.type) && window.URL ? URL.createObjectURL(f) : null, problem: '' });
    });
    if (!manualOrder) sortByName();
    render(dropped ? 'You can upload up to ' + MAX_PICTURES + ' pictures at a time.' : '');
  }
  function remove(i) {
    if (items[i] && items[i].url) URL.revokeObjectURL(items[i].url);
    items.splice(i, 1);
    render('');
  }
  function move(from, to) {
    if (to < 0 || to >= items.length || from === to) return;
    items.splice(to, 0, items.splice(from, 1)[0]);
    manualOrder = true;
    render('');
  }

  function render(message) {
    var many = items.length > 1;
    items.forEach(function (it) { it.problem = check(it.file, many); });
    var total = items.reduce(function (s, it) { return s + it.file.size; }, 0);
    var bad = items.some(function (it) { return it.problem; }) || (many && total > MAX_TOTAL);

    chosen.textContent = '';
    var note = document.createElement('span');
    if (message) { note.className = 'text-danger'; note.textContent = message; }
    else if (!items.length) { note.className = 'text-muted'; note.textContent = 'No file selected.'; }
    else if (!many) {
      note.className = items[0].problem ? 'text-danger' : 'text-body';
      note.textContent = (items[0].problem ? items[0].problem + ' ' : 'Selected: ') + items[0].file.name + ' (' + mb(items[0].file.size) + ')';
    }
    chosen.appendChild(note);

    picker.hidden = !many;
    thumbs.textContent = '';
    if (many) {
      totalEl.textContent = items.length + ' of ' + MAX_PICTURES + ' pictures · ' + mb(total) + ' of ' + Math.round(MAX_TOTAL / 1048576) + ' MB'
        + (total > MAX_TOTAL ? ' -- too large together, remove some' : '');
      totalEl.className = 'small fw-semibold me-auto' + (total > MAX_TOTAL ? ' text-danger' : '');
      items.forEach(function (it, i) {
        var li = document.createElement('li');
        li.className = 'ui-page-thumb' + (it.problem ? ' has-problem' : '');
        li.draggable = true;
        li.dataset.index = i;
        if (it.url) {
          var img = document.createElement('img');
          img.src = it.url;
          img.alt = '';
          li.appendChild(img);
        } else {
          var ph = document.createElement('span');
          ph.className = 'ui-page-ph';
          ph.innerHTML = '<i class="fa-solid fa-image" aria-hidden="true"></i>';
          li.appendChild(ph);
        }
        var label = document.createElement('span');
        label.className = 'ui-page-label';
        label.textContent = 'Page ' + (i + 1);
        var name = document.createElement('span');
        name.className = 'ui-page-name text-truncate';
        name.textContent = it.file.name;
        name.title = it.file.name;
        var size = document.createElement('span');
        size.className = 'ui-page-size';
        size.textContent = mb(it.file.size);
        li.appendChild(label);
        li.appendChild(name);
        li.appendChild(size);
        if (it.problem) {
          var warn = document.createElement('span');
          warn.className = 'ui-page-problem';
          warn.textContent = it.problem;
          li.appendChild(warn);
        }
        var tools = document.createElement('span');
        tools.className = 'ui-page-tools';
        [['fa-arrow-left', 'Move earlier', function () { move(i, i - 1); }, i === 0],
         ['fa-arrow-right', 'Move later', function () { move(i, i + 1); }, i === items.length - 1],
         ['fa-xmark', 'Remove ' + it.file.name, function () { remove(i); }, false]].forEach(function (t) {
          var b = document.createElement('button');
          b.type = 'button';
          b.className = 'btn btn-sm ' + (t[0] === 'fa-xmark' ? 'btn-outline-danger' : 'btn-outline-secondary');
          b.setAttribute('aria-label', t[1] + ' (page ' + (i + 1) + ')');
          b.title = t[1];
          b.disabled = t[3];
          b.innerHTML = '<i class="fa-solid ' + t[0] + '" aria-hidden="true"></i>';
          b.addEventListener('click', t[2]);
          tools.appendChild(b);
        });
        li.appendChild(tools);
        thumbs.appendChild(li);
      });
    }
    scanBtn.disabled = !items.length || bad;
    scanBtn.innerHTML = many
      ? '<i class="fa-solid fa-file-pdf"></i> Upload ' + items.length + ' pictures as one PDF'
      : '<i class="fa-solid fa-magnifying-glass"></i> Scan &amp; Preview';
  }

  // Drag a thumbnail onto another to move it there (mouse; the arrows work everywhere)
  var dragFrom = null;
  thumbs.addEventListener('dragstart', function (e) {
    var li = e.target.closest('.ui-page-thumb');
    if (!li) return;
    dragFrom = +li.dataset.index;
    e.dataTransfer.effectAllowed = 'move';
    try { e.dataTransfer.setData('text/plain', String(dragFrom)); } catch (err) {}
    li.classList.add('is-dragging');
  });
  thumbs.addEventListener('dragover', function (e) { if (dragFrom !== null) e.preventDefault(); });
  thumbs.addEventListener('drop', function (e) {
    var li = e.target.closest('.ui-page-thumb');
    if (dragFrom === null || !li) return;
    e.preventDefault();
    move(dragFrom, +li.dataset.index);
    dragFrom = null;
  });
  thumbs.addEventListener('dragend', function () { dragFrom = null; render(''); });

  document.getElementById('sortByName').addEventListener('click', function () {
    manualOrder = false;
    render(sortByName() ? '' : 'The file names have no numbers, so the order stays as chosen.');
  });
  document.getElementById('addMore').addEventListener('click', function () { fileInput.click(); });
  fileInput.addEventListener('change', function () { add(fileInput.files); fileInput.value = ''; });
  cameraInput.addEventListener('change', function () { add(cameraInput.files); cameraInput.value = ''; });
  document.getElementById('chooseBtn').addEventListener('click', function () { fileInput.click(); });

  // Drop files on the drop area
  var zone = document.getElementById('dropZone');
  if (zone) {
    ['dragenter', 'dragover'].forEach(function (t) { zone.addEventListener(t, function (e) { e.preventDefault(); zone.classList.add('is-over'); }); });
    ['dragleave', 'drop'].forEach(function (t) { zone.addEventListener(t, function () { zone.classList.remove('is-over'); }); });
    zone.addEventListener('drop', function (e) {
      e.preventDefault();
      if (e.dataTransfer && e.dataTransfer.files) add(e.dataTransfer.files);
    });
  }

  // Phones / tablets open the camera app; desktops use the webcam (if any)
  var touch = window.matchMedia && window.matchMedia('(pointer: coarse)').matches;
  document.getElementById('photoBtn').addEventListener('click', function () {
    if (touch || !(navigator.mediaDevices && navigator.mediaDevices.getUserMedia)) {
      cameraInput.click();
      return;
    }
    navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment', width: { ideal: 3840 }, height: { ideal: 2160 } }, audio: false })
      .then(function (s) {
        stream = s;
        document.getElementById('cameraVideo').srcObject = s;
        bootstrap.Modal.getOrCreateInstance(document.getElementById('cameraModal')).show();
      })
      .catch(function () { render('No camera is available (or permission was denied). Use Choose File instead.'); });
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
      add([new File([blob], 'photo-' + Date.now() + '.jpg', { type: 'image/jpeg' })]);
      bootstrap.Modal.getInstance(document.getElementById('cameraModal')).hide();
    }, 'image/jpeg', 0.92);
  });

  // While the server works, the choices give way to the "Reading your document" panel
  function showProgress(title, file, manual) {
    var panel = document.getElementById('scanProgress');
    if (!panel) return;
    document.getElementById('scanTitle').textContent = title;
    document.getElementById('scanFile').textContent = file || '';
    if (manual) document.getElementById('scanSteps').hidden = true;
    panel.previousElementSibling.hidden = true;   // the options and forms
    panel.hidden = false;
  }
  function hideProgress() {
    var panel = document.getElementById('scanProgress');
    if (!panel) return;
    panel.hidden = true;
    panel.previousElementSibling.hidden = false;
  }

  // A JPG / PNG / WEBP no bigger than MAX_SIDE on its longest side, upright (EXIF); anything
  // else (HEIC, or a browser that can't) goes as it is and the server does the same
  function shrink(file) {
    if (!/^image\/(jpeg|png|webp)$/.test(file.type) || !window.createImageBitmap) return Promise.resolve(file);
    return createImageBitmap(file, { imageOrientation: 'from-image' }).then(function (bmp) {
      var f = Math.min(1, MAX_SIDE / Math.max(bmp.width, bmp.height));
      if (f === 1 && file.type === 'image/jpeg') return file;
      var c = document.createElement('canvas');
      c.width = Math.round(bmp.width * f);
      c.height = Math.round(bmp.height * f);
      c.getContext('2d').drawImage(bmp, 0, 0, c.width, c.height);
      return new Promise(function (resolve) {
        c.toBlob(function (blob) {
          resolve(blob && blob.size < file.size ? new File([blob], file.name.replace(/\.\w+$/, '') + '.jpg', { type: 'image/jpeg' }) : file);
        }, 'image/jpeg', 0.88);
      });
    }).catch(function () { return file; });
  }
  function post(fields) {
    var body = new FormData();
    body.append('csrf_token', form.querySelector('[name="csrf_token"]').value);
    Object.keys(fields).forEach(function (k) { body.append(k, fields[k]); });
    return fetch('submit_document.php', { method: 'POST', body: body, credentials: 'same-origin' })
      .then(function (res) {
        return res.json().catch(function () { throw new Error('The server could not be reached properly. Please reload the page and try again.'); });
      })
      .then(function (data) { if (!data.ok) throw new Error(data.error || 'Something went wrong.'); return data; });
  }

  form.addEventListener('submit', function (e) {
    if (scanBtn.disabled || !items.length) { e.preventDefault(); return; }
    scanBtn.disabled = true;
    if (items.length === 1) {   // one file: the usual upload, as before
      if (typeof DataTransfer !== 'undefined') {
        var dt = new DataTransfer();
        dt.items.add(items[0].file);
        fileInput.files = dt.files;
      }
      scanBtn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Scanning document&hellip; this can take up to a minute';
      showProgress('Reading your document', items[0].file.name, false);
      return;
    }
    // Several pictures: one at a time, in this order, then one PDF
    e.preventDefault();
    var list = items.slice(), hint = form.querySelector('[name="type_hint"]').value, byName = nameOrder();
    showProgress('Uploading 1 of ' + list.length + '…', list.length + ' pictures', false);
    var i = 0;
    var next = function () {
      if (i >= list.length) {
        showProgress('Creating PDF…', list.length + ' pages', false);
        return post({ action: 'batch_finish', name_order: byName ? '1' : '0' });
      }
      document.getElementById('scanTitle').textContent = 'Uploading ' + (i + 1) + ' of ' + list.length + '…';
      document.getElementById('scanFile').textContent = list[i].file.name;
      return shrink(list[i].file).then(function (f) {
        return post({ action: 'batch_add', page: f });
      }).then(function () { i += 1; return next(); });
    };
    post({ action: 'batch_start', count: String(list.length), type_hint: hint })
      .then(next)
      .then(function (data) { window.location.href = data.redirect; })
      .catch(function (err) {
        hideProgress();
        render(err.message);
        scanBtn.disabled = false;
      });
  });
  var manualForm = document.getElementById('manualForm'), manualFile = document.getElementById('manualFile');
  manualFile.addEventListener('change', function () {
    var f = manualFile.files && manualFile.files[0];
    manualFile.setCustomValidity(f && f.size > MAX ? 'Too large: the maximum is ' + Math.round(MAX / 1048576) + 'MB.' : '');
  });
  manualForm.addEventListener('submit', function () {
    if (!manualForm.checkValidity()) return;
    showProgress('Uploading your file', manualFile.files[0] ? manualFile.files[0].name : '', true);
    var btn = document.getElementById('manualBtn');
    setTimeout(function () { btn.disabled = true; }, 0);
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Uploading&hellip;';
  });
  // The chosen option is kept in the address, so a reload or an error comes back to it
  document.querySelectorAll('.create-option').forEach(function (b) {
    b.addEventListener('shown.bs.tab', function () {
      history.replaceState(null, '', b.id === 'optManual' ? '?mode=manual' : location.pathname);
    });
  });
})();
</script>

<?php else:
  $result = $pending['result'];
  $manual = !empty($pending['manual']);
  $check = pending_classification($pending, $categories);
  $suggested = $check['suggested'];   // null below CONFIDENCE_THRESHOLD: nothing is pre-selected
  $hint = isset($categories[$pending['type_hint'] ?? '']) ? $pending['type_hint'] : null;   // chosen before uploading
  $preselect = $hint ?? $suggested;
  $pds_check = $result['pds'] ?? null;
  $suggested_sub = $result['detected_subtype'] ?? null;
  $not_applicable = $check['predicted'] && !isset($categories[$check['predicted']]);
  $period = $result['period'] ?? [];
  $training = $result['training'] ?? [];
  $ld_types = pds_ld_columns()['ld_type'][2];
?>

  <div class="card stat-card" style="max-width:820px;">
    <div class="card-header bg-white fw-semibold">
      <?php if ($manual): ?>
        <i class="fa-solid fa-keyboard text-brand"></i> Enter the Document Details
      <?php else: ?>
        <i class="fa-solid fa-magnifying-glass-chart text-brand"></i> Extracted Data Preview
      <?php endif; ?>
    </div>
    <div class="card-body">

      <?php if ($manual): ?>
      <div class="alert alert-light border small">
        <i class="fa-solid fa-keyboard text-brand"></i> Fill in the details below -- nothing was read from the file. The Admin checks them before the document is posted.
      </div>
      <?php elseif ($check['low']): ?>
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

      <?php if (!$manual && $pds_check && $pds_check['ask'] && $preselect !== 'PDS' && isset($categories['PDS'])): ?>
      <div class="alert alert-primary small d-flex flex-wrap align-items-center gap-2" id="pdsAsk">
        <span class="me-auto"><i class="fa-solid fa-id-card"></i> <strong>Is this a Personal Data Sheet (PDS)?</strong>
          Some of the form's labels were read, but not enough to be sure.</span>
        <button type="button" class="btn btn-sm btn-brand" id="pdsYes">Yes, it's my PDS</button>
        <button type="button" class="btn btn-sm btn-outline-secondary" id="pdsNo">No</button>
      </div>
      <?php endif; ?>

      <?php if (!empty($result['ocr_poor'])): ?>
      <div class="alert alert-secondary small">
        <i class="fa-solid fa-camera-rotate"></i> Only a little text could be read from this file.
        Please retake the photo flat, in good lighting, with only the page in the frame, or upload a scan/Excel copy.
      </div>
      <?php endif; ?>

      <?php if (!empty($pending['pages'])): /* several pictures combined into one PDF */ ?>
      <div class="mb-3">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-1">
          <label class="form-label small fw-semibold mb-0">Combined PDF -- <?= count($pending['pages']) ?> pages</label>
          <span class="badge bg-success">One picture per page, in this order</span>
        </div>
        <?php if (!empty($pending['page_note'])): ?>
          <div class="alert alert-info small py-2"><i class="fa-solid fa-arrow-down-1-9" aria-hidden="true"></i> <?= h($pending['page_note']) ?></div>
        <?php endif; ?>
        <ol class="ui-page-grid list-unstyled mb-1">
          <?php foreach ($pending['pages'] as $i => $unused): ?>
            <li class="ui-page-thumb">
              <img src="submit_document.php?preview=page&amp;n=<?= $i + 1 ?>&amp;v=<?= time() ?>" alt="Page <?= $i + 1 ?>" loading="lazy">
              <span class="ui-page-label">Page <?= $i + 1 ?></span>
              <span class="ui-page-name text-truncate"><?= h($pending['page_names'][$i] ?? '') ?></span>
            </li>
          <?php endforeach; ?>
        </ol>
        <div class="form-text">These cleaned-up pages are saved to your 201 file as one PDF. Wrong order or a wrong picture? Cancel and upload them again.</div>
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

      <form action="submit_document.php" method="POST" id="uploadForm" data-unsaved-work>
        <?= csrf_field() ?>

        <div class="row g-3 mb-3">
          <div class="col-md-6">
            <label class="form-label small fw-semibold">Document Type <span class="text-danger">*</span></label>
            <select name="document_type" id="docType" class="form-select<?= $check['low'] ? ' border-warning' : '' ?>" required>
              <option value="">-- Select type --</option>
              <?php foreach ($categories as $key => $meta): ?>
                <option value="<?= h($key) ?>" <?= $preselect === $key ? 'selected' : '' ?>>
                  <?= h($meta['label']) ?><?= $suggested === $key ? ' (auto-detected)' : ($hint === $key ? ' (your choice)' : '') ?>
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
            <p class="small text-muted mb-3"><?= $manual ? 'Type in the details from the certificate.' : 'Read from the certificate -- please check and correct these before uploading.' ?> Leave a field blank if it isn't on the certificate.</p>
            <?php
            $values = ['title' => $training['title'] ?? null, 'date_start' => $training['date_from'] ?? null, 'date_end' => $training['date_to'] ?? null,
                       'venue' => $training['venue'] ?? null, 'conducted_by' => $training['conducted_by'] ?? null, 'hours' => $training['hours'] ?? null,
                       'training_type' => $training['training_type'] ?? (in_array($suggested_sub, TRAINING_TYPES, true) ? $suggested_sub : null),
                       'training_level' => $training['training_level'] ?? null];
            $prefix = 'details'; $detail_groups = ['training' => true]; $detail_attr = 'disabled';
            include __DIR__ . '/../includes/document_details_fields.php';
            ?>
            <?php if ($has_pds): /* part-time faculty have no PDS */ ?>
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
            <?php endif; ?>
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

        <?php if (!$manual): ?>
        <div class="mb-3">
          <label class="form-label small fw-semibold">Extracted Text Preview</label>
          <textarea class="form-control" rows="6" disabled><?= h($result['text'] !== '' ? $result['text'] : '(No text extracted -- please confirm the details manually.)') ?></textarea>
        </div>
        <?php endif; ?>

        <?php // Shown by the script below while the document's own date is ARCHIVE_UNVIEWED_YEARS+ years ago (the server decides again on saving) ?>
        <div class="alert alert-info small d-flex gap-2 align-items-start" id="archiveNotice" role="status" aria-live="polite"
             data-cutoff="<?= h(archive_old_date_cutoff()) ?>" data-period="<?= h(archive_period_label()) ?>" hidden>
          <i class="fa-solid fa-box-archive mt-1" aria-hidden="true"></i><span></span>
        </div>

        <div class="d-flex gap-2">
          <button type="submit" name="action" value="confirm" class="btn btn-brand flex-grow-1">
            <i class="fa-solid fa-check"></i> Save Document
          </button>
          <button type="submit" name="action" value="cancel" formnovalidate class="btn btn-outline-secondary">
            <i class="fa-solid fa-xmark"></i> Cancel
          </button>
        </div>
      </form>

    </div>
  </div>

<script>
// "This document is dated ..., so it will be saved to Archived Docs": its own date -- the
// seminar / training end date, else its start date, else the date issued (the fields that
// apply to the chosen type) -- on or before the cutoff. Not for the PDS. Updates as the
// type or dates change. The server checks again on saving (archive_at_upload()).
(function () {
  var form = document.getElementById('uploadForm');
  var notice = document.getElementById('archiveNotice');
  if (!form || !notice) return;
  var cutoff = notice.getAttribute('data-cutoff');
  function value(name) {
    var el = form.querySelector('input[name="details[' + name + ']"]');
    return el && !el.disabled && /^\d{4}-\d{2}-\d{2}$/.test(el.value) ? el.value : '';
  }
  function update() {
    var type = (form.querySelector('[name="document_type"]') || {}).value || '';
    var date = type === 'Certificate' ? (value('date_end') || value('date_start') || value('date_issued')) : value('date_issued');
    var show = type !== '' && type !== 'PDS' && date !== '' && date <= cutoff;
    notice.hidden = !show;
    if (show) {
      var shown = new Date(date + 'T00:00:00').toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
      notice.querySelector('span').textContent = 'This document is dated ' + shown + ', more than ' + notice.getAttribute('data-period')
        + ' ago, so it will be saved to Archived Docs.';
    }
  }
  form.addEventListener('input', update);
  form.addEventListener('change', function () { setTimeout(update, 0); });   // after the type's fields are shown / hidden
  setTimeout(update, 0);   // after the script below has set up the fields for the type
})();
</script>

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
  // "Is this a PDS?" -- Yes picks the PDS type (its import runs after uploading); either answer closes the question
  var pdsAsk = document.getElementById('pdsAsk');
  if (pdsAsk) {
    document.getElementById('pdsYes').addEventListener('click', function () {
      typeSel.value = 'PDS';
      typeSel.dispatchEvent(new Event('change', { bubbles: true }));
      pdsAsk.remove();
    });
    document.getElementById('pdsNo').addEventListener('click', function () { pdsAsk.remove(); });
  }
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
