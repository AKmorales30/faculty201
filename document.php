<?php
/**
 * Serves a 201-file document after an access check. Direct URLs to
 * uploads/ are blocked by the web server, so this is the only way to open
 * a filed document.
 *
 * Allowed: the document's owner, or the Admin (can_access_document()).
 * Program Chairs and Deans can open only their own documents -- never a
 * faculty member's.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_login();

$me = current_user();
$stmt = $pdo->prepare("SELECT document_id, faculty_id, file_path FROM documents WHERE document_id = ?");
$stmt->execute([(int)($_GET['id'] ?? 0)]);
$doc = $stmt->fetch();

if (!$doc || !can_access_document($me, $doc)) {
    http_response_code(403);
    exit('You do not have access to this document.');
}

$path = realpath(ROOT_PATH . '/' . $doc['file_path']);
$uploads = realpath(UPLOADS_PATH);
if (!$path || !$uploads || !str_starts_with($path, $uploads . DIRECTORY_SEPARATOR) || !is_file($path)) {
    http_response_code(404);
    exit('The file is no longer available on the server.');
}

$types = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="' . basename($path) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($path);
