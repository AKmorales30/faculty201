<?php
/**
 * Serves a 201-file document after an access check. Direct URLs to
 * uploads/ are blocked by the web server, so this is the only way to open
 * a filed document.
 *
 * Allowed: the document's owner while it's active or archived (My
 * Archive), or the Admin -- also deleted ones, to review them
 * (can_access_document()).
 * The file is read from the database (document_files).
 * Program Chairs and Deans can open only their own documents -- never a
 * faculty member's.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_login();

$me = current_user();
$stmt = $pdo->prepare(
    "SELECT d.document_id, d.faculty_id, d.document_type, d.document_subtype, d.file_path, d.status, u.full_name
     FROM documents d JOIN users u ON u.user_id = d.faculty_id WHERE d.document_id = ?"
);
$stmt->execute([(int)($_GET['id'] ?? 0)]);
$doc = $stmt->fetch();

if (!$doc || !can_access_document($me, $doc)) {
    http_response_code(403);
    exit('You do not have access to this document.');
}

log_my_activity($pdo, 'VIEW_DOCUMENT', "Viewed document #{$doc['document_id']} \"" . basename($doc['file_path']) . '" ('
    . document_type_label($doc['document_type'], $doc['document_subtype'])
    . ((int)$doc['faculty_id'] === (int)$me['user_id'] ? ', own 201 file' : ', 201 file of ' . $doc['full_name'])
    . ($doc['status'] !== 'active' ? ', ' . $doc['status'] : '') . ').');

// Served from the database (document_files); the server's disk is wiped on
// every redeploy. A file that only exists on disk (uploaded before files
// were kept in the database, e.g. on XAMPP) is copied in the first time
// it's opened.
$stmt = $pdo->prepare("SELECT mime_type, file_size, data FROM document_files WHERE document_id = ?");
$stmt->execute([(int)$doc['document_id']]);
$file = $stmt->fetch();

if (!$file) {
    $path = realpath(ROOT_PATH . '/' . $doc['file_path']);
    $uploads = realpath(UPLOADS_PATH);
    if (!$path || !$uploads || !str_starts_with($path, $uploads . DIRECTORY_SEPARATOR) || !is_file($path)) {
        http_response_code(404);
        exit('This file is no longer available: it was uploaded before documents were kept in the database, '
           . 'and the server copy was removed when the site was updated. Please upload it again.');
    }
    try {
        store_document_file($pdo, (int)$doc['document_id'], $path, $doc['file_path']);
    } catch (Throwable $e) {
        error_log('Copying document ' . $doc['document_id'] . ' into the database failed: ' . $e->getMessage());
    }
    $file = ['mime_type' => document_mime_type($path), 'file_size' => filesize($path), 'data' => file_get_contents($path)];
}

header('Content-Type: ' . $file['mime_type']);
header('Content-Length: ' . strlen($file['data']));
// ?download=1 saves the file instead of opening it (My Archive's download button)
header('Content-Disposition: ' . (!empty($_GET['download']) ? 'attachment' : 'inline') . '; filename="' . basename($doc['file_path']) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
echo $file['data'];
