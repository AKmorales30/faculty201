<?php
/**
 * Free usernames for Manage Faculty's "Suggest" button (Admin only):
 * GET first, last, employee_id, except (the account being edited)
 * -> JSON {"suggestions": ["juan.delacruz", ...]} (username_suggestions()).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
if (!is_logged_in() || current_user()['role'] !== 'admin' || !empty(current_user()['must_change_password'])) {
    http_response_code(403);
    echo json_encode(['suggestions' => [], 'error' => 'Not allowed.']);
    exit;
}

$person = [
    'first_name'  => mb_substr(trim((string)($_GET['first'] ?? '')), 0, 60),
    'last_name'   => mb_substr(trim((string)($_GET['last'] ?? '')), 0, 60),
    'employee_id' => mb_substr(trim((string)($_GET['employee_id'] ?? '')), 0, 30),
];
if ($person['first_name'] === '' && $person['employee_id'] === '') {
    echo json_encode(['suggestions' => [], 'error' => 'Enter the first and last name first.']);
    exit;
}
try {
    echo json_encode(['suggestions' => username_suggestions($pdo, $person, (int)($_GET['except'] ?? 0), 3)]);
} catch (Throwable $e) {
    error_log('Username suggestion failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['suggestions' => [], 'error' => 'Could not get a suggestion. Type a username instead.']);
}
