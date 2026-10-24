<?php
/**
 * Data for the Program Chair / Dean Analytics page (approval/analytics.php).
 * GET only, read-only, JSON. Program Chairs and Deans only; the scope is
 * enforced in every query (includes/analytics.php, analytics_scope()):
 *   ?from=YYYY-MM-DD&to=YYYY-MM-DD   the date range (default: last 12 months)
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/analytics.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');

/** End with an error as JSON. */
function analytics_fail(int $code, string $message): never {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}

if (!is_logged_in()) {
    analytics_fail(401, 'Please log in again.');
}
$me = current_user();
if (!empty($me['must_change_password'])) {
    analytics_fail(403, 'Please change your temporary password first.');
}
if (!in_array($me['role'], ['program_chair', 'dean'], true)) {
    analytics_fail(403, 'Analytics is for Program Chairs and Deans.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    analytics_fail(405, 'Use GET.');
}

[$from, $to] = analytics_range(is_string($_GET['from'] ?? null) ? $_GET['from'] : null, is_string($_GET['to'] ?? null) ? $_GET['to'] : null);
try {
    echo json_encode(['ok' => true] + analytics_data($pdo, $me, $from, $to), JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('Analytics failed: ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    analytics_fail(500, 'The analytics could not be loaded right now. Please try again.');
}
