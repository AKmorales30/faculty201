<?php
/**
 * Live search for the top bar (assets/js/app.js): GET ?q=..., JSON, a few
 * results per group while the user types. Login required; every rule is
 * applied here, in SQL, whatever the browser asks for:
 *   Documents  posted (active) documents only -- never archived, deleted or
 *              for review. The Admin's: every faculty member's; anyone
 *              else's: their own (only the owner and the Admin may open a
 *              document, can_access_document()). No PDS of a part-time
 *              faculty member (faculty_has_pds()).
 *   Faculty    Admin: everyone with a 201 file (opens their 201-Files);
 *              Program Chair / Dean: the faculty in their scope
 *              (profile_scope_sql(); opens the profile pop-up). Not for faculty.
 * Matches title, type / category, organizer, extracted text (OCR) and, for
 * the Admin, the faculty name -- case-insensitive, all values bound.
 * At most SEARCH_PER_MINUTE requests a minute per session. Not written to
 * Activity Logs (one search per keystroke pause would flood it); the full
 * results (Enter, api/modal.php?view=search) are.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');

const SEARCH_PER_GROUP = 6;
const SEARCH_PER_MINUTE = 60;

function search_fail(int $code, string $message): never {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}

if (!is_logged_in()) {
    search_fail(401, 'Please log in again.');
}
$me = current_user();
if (!empty($me['must_change_password'])) {
    search_fail(403, 'Please change your temporary password first.');
}

// Rate limit: the request times of the last minute, in the session
$now = time();
$recent = array_values(array_filter((array)($_SESSION['live_search_times'] ?? []), fn($t) => is_int($t) && $t > $now - 60));
if (count($recent) >= SEARCH_PER_MINUTE) {
    search_fail(429, 'Too many searches. Please wait a moment.');
}
$recent[] = $now;
$_SESSION['live_search_times'] = $recent;
session_write_close();   // the search doesn't change the session: don't hold other requests

$q = mb_substr(trim(preg_replace('/\s+/', ' ', (string)($_GET['q'] ?? ''))), 0, 100);
$is_admin = $me['role'] === 'admin';
$overseer = in_array($me['role'], ['program_chair', 'dean'], true);
$see_all = BASE_URL . '/api/modal.php?view=search&q=' . rawurlencode($q);
if (mb_strlen($q) < 2) {
    echo json_encode(['ok' => true, 'q' => $q, 'groups' => [], 'see_all' => $see_all]);
    exit;
}

$like = '%' . addcslashes($q, '%_\\') . '%';
$op = sql_like();
$groups = [];

try {
    // ----- documents -------------------------------------------------------------
    $type_keys = [];
    foreach (document_categories() as $key => $cat) {
        foreach ([$key, $cat['label'], $cat['short'], $cat['description'] ?? '', ...array_values($cat['subtypes'] ?? [])] as $name) {
            if ($name !== '' && mb_stripos($name, $q) !== false) { $type_keys[$key] = true; }
        }
    }
    $match = ["d.title $op ?", "d.conducted_by $op ?", "d.ocr_extracted_text $op ?"];
    $params = [$like, $like, $like];
    if ($is_admin) { $match[] = "u.full_name $op ?"; $params[] = $like; }
    if ($type_keys) {
        $match[] = 'd.document_type IN (' . implode(',', array_fill(0, count($type_keys), '?')) . ')';
        array_push($params, ...array_keys($type_keys));
    }
    $where = "d.status = 'active' AND (" . implode(' OR ', $match) . ") AND NOT (d.document_type = 'PDS' AND NOT " . sql_has_pds('u') . ')';
    if (!$is_admin) {
        $where .= ' AND d.faculty_id = ?';
        $params[] = (int)$me['user_id'];
    }
    $stmt = $pdo->prepare(
        "SELECT d.document_id, d.document_type, d.document_subtype, d.title, d.file_path, d.filed_at, d.conducted_by, u.full_name,
                CASE WHEN d.title $op ? THEN 1 ELSE 0 END AS title_hit
         FROM documents d JOIN users u ON u.user_id = d.faculty_id
         WHERE $where
         ORDER BY title_hit DESC, d.filed_at DESC, d.document_id DESC LIMIT " . SEARCH_PER_GROUP);
    $stmt->execute([$like, ...$params]);
    $categories = document_categories();
    $docs = [];
    foreach ($stmt->fetchAll() as $d) {
        $title = document_title($d);
        $in_text = mb_stripos($title, $q) === false && mb_stripos((string)$d['conducted_by'], $q) === false
                && !isset($type_keys[$d['document_type']]) && !($is_admin && mb_stripos($d['full_name'], $q) !== false);
        $docs[] = [
            'title'  => $title,
            'detail' => implode(' · ', array_filter([
                document_type_label($d['document_type'], $d['document_subtype']),
                $is_admin ? $d['full_name'] : null,
                'Uploaded ' . date('M j, Y', strtotime($d['filed_at'])),
                $in_text ? 'found in the document text' : null,
            ])),
            'icon'   => $categories[$d['document_type']]['icon'] ?? 'fa-file',
            'modal'  => BASE_URL . '/api/modal.php?view=document&id=' . (int)$d['document_id'],
            'url'    => BASE_URL . '/document_details.php?id=' . (int)$d['document_id'],
        ];
    }
    $groups[] = ['key' => 'documents', 'label' => $is_admin ? 'Documents in 201-Files' : 'Your 201-Files', 'items' => $docs];

    // ----- faculty (Admin, Program Chair, Dean) -------------------------------------
    if ($is_admin || $overseer) {
        [$scope, $scope_params] = profile_scope_sql($pdo, $me);
        $stmt = $pdo->prepare(
            "SELECT u.user_id, u.full_name, u.username, u.role, u.employment_type, u.program, u.college, u.academic_rank
             FROM users u
             WHERE $scope AND u.role IN ('faculty', 'program_chair', 'dean') AND u.deleted_at IS NULL AND u.user_id <> ?
               AND (u.full_name $op ? OR u.username $op ?)
             ORDER BY u.full_name LIMIT " . SEARCH_PER_GROUP);
        $stmt->execute([...$scope_params, (int)$me['user_id'], $like, $like]);
        $people = [];
        foreach ($stmt->fetchAll() as $u) {
            $people[] = [
                'title'  => $u['full_name'],
                'detail' => implode(' · ', array_filter([user_position_line($u), $u['academic_rank'] && $u['academic_rank'] !== 'N/A' ? $u['academic_rank'] : null])),
                'icon'   => 'fa-user',
                'modal'  => $is_admin ? null : BASE_URL . '/api/modal.php?view=person&id=' . (int)$u['user_id'],
                'url'    => $is_admin ? BASE_URL . '/admin/faculty_documents.php?id=' . (int)$u['user_id'] : BASE_URL . '/profile.php?id=' . (int)$u['user_id'],
            ];
        }
        $groups[] = ['key' => 'faculty', 'label' => 'Faculty', 'items' => $people];
    }
} catch (Throwable $e) {
    error_log('Live search failed: ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    search_fail(500, 'Search isn\'t available right now.');
}

echo json_encode(['ok' => true, 'q' => $q, 'groups' => $groups, 'see_all' => $see_all], JSON_UNESCAPED_UNICODE);
