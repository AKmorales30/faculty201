<?php
/**
 * Delete / archive / restore a 201-file document (POST + CSRF token only).
 *
 *   Faculty (and Program Chairs / Deans, for their own 201 file):
 *     delete -- their own active document, within FACULTY_DELETE_WINDOW_HOURS
 *               of uploading it; the reason is optional
 *   Admin:
 *     archive -- hide an active document from everyone but the Admin
 *     delete  -- remove an active or archived document
 *     restore -- bring an archived or deleted document back
 *     A reason is required to archive or delete, and the owner is notified.
 *
 * Every check is made here, whatever buttons the page showed. Rows are
 * never removed (see change_document_status()), and each action is
 * written to the activity log.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_login();

$me = current_user();
$is_admin = $me['role'] === 'admin';

// Back to the page the form was on -- only a page inside the app
$return = (string)($_POST['return'] ?? '');
if (!preg_match('#^(admin|faculty|approval)/[a-z_]+\.php(\?[A-Za-z0-9_.%=&\-\[\]+]*)?$#', $return)) {
    $return = $is_admin ? 'admin/archived_documents.php' : 'faculty/my_documents.php';
}
$back = function (string $key, string $message) use ($return) {
    $_SESSION[$key] = $message;
    header('Location: ' . BASE_URL . '/' . $return);
    exit;
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_URL . '/' . $return);
    exit;
}
if (!csrf_valid()) {
    $back('flash_error', 'Your session expired before the form was sent. Please try again.');
}

$action = $_POST['action'] ?? '';
if (!in_array($action, ['delete', 'archive', 'restore'], true)) {
    $back('flash_error', 'Unknown action.');
}

$stmt = $pdo->prepare(
    "SELECT d.document_id, d.faculty_id, d.document_type, d.document_subtype, d.file_path, d.status,
            " . delete_window_sql('d') . " AS delete_seconds_left, u.full_name
     FROM documents d JOIN users u ON u.user_id = d.faculty_id
     WHERE d.document_id = ?"
);
$stmt->execute([(int)($_POST['document_id'] ?? 0)]);
$doc = $stmt->fetch();

// Who may do what (never trust the buttons): faculty only delete their own, in the window
if (!$doc) {
    $back('flash_error', 'That document could not be found.');
}
if (!$is_admin) {
    if ($action !== 'delete' || (int)$doc['faculty_id'] !== (int)$me['user_id'] || $doc['status'] !== 'active') {
        $back('flash_error', 'You can only delete your own documents.');
    }
    if (!faculty_can_delete($me, $doc)) {
        $back('flash_error', 'This document was uploaded more than ' . FACULTY_DELETE_WINDOW_HOURS . ' hours ago and can no longer be deleted by you. Contact the Admin to remove this document.');
    }
}

// Reason: one of the listed reasons plus an optional note. Required for the Admin's archive / delete.
$reason_choice = (string)($_POST['reason'] ?? '');
$note = trim(mb_substr((string)($_POST['reason_note'] ?? ''), 0, 200));
$reason = in_array($reason_choice, document_removal_reasons(), true) ? $reason_choice : '';
if ($note !== '') { $reason = $reason !== '' ? "{$reason} -- {$note}" : $note; }
if ($is_admin && $action !== 'restore' && $reason === '') {
    $back('flash_error', 'Please give a reason for ' . ($action === 'archive' ? 'archiving' : 'deleting') . ' the document.');
}

$name = document_display_name($doc['file_path']) . ' (' . document_type_label($doc['document_type'], $doc['document_subtype']) . ')';
if (!change_document_status($pdo, $doc, $action, (int)$me['user_id'], $reason !== '' ? $reason : null)) {
    $back('flash_error', "That can't be done: the document is currently {$doc['status']}.");
}

// Activity log: document, file, owner, action, reason
$verb = ['delete' => 'Deleted', 'archive' => 'Archived', 'restore' => 'Restored'][$action];
log_my_activity($pdo, 'DOCUMENT_' . strtoupper($action),
    "{$verb} document #{$doc['document_id']} \"" . basename($doc['file_path']) . '" ('
    . document_type_label($doc['document_type'], $doc['document_subtype']) . ') of ' . $doc['full_name']
    . ($action === 'restore' ? " (was {$doc['status']})" : '')
    . ($reason !== '' ? ". Reason: {$reason}" : '') . '.');

// The owner hears about anything the Admin does to their document
if ($is_admin && (int)$doc['faculty_id'] !== (int)$me['user_id']) {
    $message = match ($action) {
        'archive' => "The Admin archived your {$name}; it no longer appears in your 201 file. Reason: {$reason}.",
        'delete'  => "The Admin deleted your {$name} from your 201 file. Reason: {$reason}. Contact the Admin if this was a mistake.",
        'restore' => "The Admin restored your {$name} to your 201 file.",
    };
    notify($pdo, (int)$doc['faculty_id'], $message);
}

$back('flash_success', $is_admin
    ? "{$verb} {$doc['full_name']}'s {$name}." . ($action !== 'restore' ? ' It can be restored from Archived Documents.' : '')
    : "Deleted your {$name}.");
