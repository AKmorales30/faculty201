<?php
/**
 * Delete / archive / restore / permanently delete a 201-file document
 * (POST + CSRF token only).
 *
 *   Faculty (and Program Chairs / Deans, for their own 201 file):
 *     delete  -- their own posted or For Review document, within
 *                FACULTY_DELETE_WINDOW_HOURS of uploading it; the reason is
 *                optional. It goes to Recently Deleted.
 *     restore -- a document of theirs in Recently Deleted that they deleted
 *                themselves, within DELETED_RETENTION_DAYS (can_restore_deleted())
 *   Admin:
 *     archive -- move a posted document to its owner's archive
 *     delete  -- move a posted, For Review or archived document to Recently Deleted
 *     restore -- bring an archived document back, or a deleted one back to
 *                where it was (within DELETED_RETENTION_DAYS)
 *     purge   -- "Delete permanently now": a document in Recently Deleted,
 *                file and record removed for good (permanently_delete_document())
 *     A reason is required to archive, delete and restore, and the owner is notified.
 *
 * Every check is made here, whatever buttons the page showed, and each
 * action is written to the activity log.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_login();

$me = current_user();
$is_admin = $me['role'] === 'admin';

// Back to the page the form was on -- only a page inside the app
$return = (string)($_POST['return'] ?? '');
if (!preg_match('#^((admin|faculty|approval)/)?[a-z_]+\.php(\?[A-Za-z0-9_.%=&\-\[\]+]*)?$#', $return)) {
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
if (!in_array($action, ['delete', 'archive', 'restore', 'purge'], true)) {
    $back('flash_error', 'Unknown action.');
}

$stmt = $pdo->prepare(
    "SELECT d.document_id, d.faculty_id, d.document_type, d.document_subtype, d.file_path, d.status, d.title,
            d.deleted_at, d.deleted_by, d.status_before_delete,
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
$name = document_title($doc) . ' (' . document_type_label($doc['document_type'], $doc['document_subtype']) . ')';
$was_deleted = $doc['status'] === 'deleted';

if (!$is_admin) {
    if ((int)$doc['faculty_id'] !== (int)$me['user_id'] || !in_array($action, ['delete', 'restore'], true)) {
        $back('flash_error', 'You can only delete or restore your own documents.');
    }
    if ($action === 'delete' && !in_array($doc['status'], ['active', 'for_review'], true)) {
        $back('flash_error', 'You can only delete your own documents.');
    }
    if ($action === 'delete' && !faculty_can_delete($me, $doc)) {
        $back('flash_error', 'This document was uploaded more than ' . FACULTY_DELETE_WINDOW_HOURS . ' hours ago and can no longer be deleted by you. Contact the Admin to remove this document.');
    }
    if ($action === 'restore' && !can_restore_deleted($me, $doc)) {
        $back('flash_error', !$was_deleted ? 'Only documents in Recently Deleted can be restored.'
            : (deleted_restore_open($doc) ? 'This document was deleted by the Admin. Please ask the Admin to restore it.'
                                          : 'The ' . (int)DELETED_RETENTION_DAYS . '-day restore period of this document is over.'));
    }
}

// Delete permanently now (Admin): only from Recently Deleted; no reason needed, the confirmation says it can't be undone
if ($action === 'purge') {
    if (!$was_deleted) {
        $back('flash_error', 'Only documents in Recently Deleted can be deleted permanently.');
    }
    if (!permanently_delete_document($pdo, (int)$doc['document_id'], $me)) {
        $back('flash_error', 'That document is no longer in Recently Deleted.');
    }
    if ((int)$doc['faculty_id'] !== (int)$me['user_id']) {
        notify($pdo, (int)$doc['faculty_id'], "The Admin permanently deleted your {$name} from Recently Deleted. It can no longer be restored.");
    }
    $back('flash_success', "Permanently deleted {$doc['full_name']}'s {$name}. It can no longer be restored.");
}
if ($action === 'restore' && $was_deleted && !deleted_restore_open($doc)) {
    $back('flash_error', 'The ' . (int)DELETED_RETENTION_DAYS . '-day restore period of this document is over; it will be permanently deleted at the next daily check.');
}

// Reason: one of the listed reasons plus an optional note. Required for the Admin's archive / delete.
$reason_choice = (string)($_POST['reason'] ?? '');
$note = trim(mb_substr((string)($_POST['reason_note'] ?? ''), 0, 200));
$reason = in_array($reason_choice, document_removal_reasons(), true) ? $reason_choice : '';
if ($note !== '') { $reason = $reason !== '' ? "{$reason} -- {$note}" : $note; }
if ($is_admin && $action !== 'restore' && $reason === '') {
    $back('flash_error', 'Please give a reason for ' . ($action === 'archive' ? 'archiving' : 'deleting') . ' the document.');
}
if ($is_admin && $action === 'restore') {
    $reason = $note;   // restoring takes a written reason, not one from the removal list
    if ($reason === '') { $back('flash_error', 'Please give a reason for restoring the document.'); }
}
// Tells a manual archive apart from the automatic one ("Auto: not opened in 3 years") in the archive lists
$stored_reason = $action === 'archive' && $is_admin ? 'Manual by Admin: ' . $reason : $reason;

if (!change_document_status($pdo, $doc, $action, (int)$me['user_id'], $stored_reason !== '' ? $stored_reason : null)) {
    $back('flash_error', "That can't be done: the document is currently " . strtolower(document_status_badge($doc['status'])[0]) . '.');
}

// Where a restored document went back to (Public Docs / Archived Docs / For Review)
$now_status = null;
if ($action === 'restore') {
    $stmt = $pdo->prepare("SELECT status FROM documents WHERE document_id = ?");
    $stmt->execute([$doc['document_id']]);
    $now_status = $stmt->fetchColumn();
}
$place = ['active' => 'Public Docs', 'archived' => 'Archived Docs', 'for_review' => 'For Review (Manage Document)'][$now_status] ?? '';
$until = $action === 'delete' ? deleted_purge_at(date('Y-m-d H:i:s'))->format('F j, Y') : '';

// Activity log: document, file, owner, action, reason
$verb = ['delete' => 'Deleted', 'archive' => 'Archived', 'restore' => 'Restored'][$action];
log_my_activity($pdo, 'DOCUMENT_' . strtoupper($action),
    "{$verb} document #{$doc['document_id']} \"" . basename($doc['file_path']) . '" ('
    . document_type_label($doc['document_type'], $doc['document_subtype']) . ') of ' . $doc['full_name']
    . ($action === 'delete' ? " -- moved to Recently Deleted, restorable until {$until}" : '')
    . ($action === 'restore' ? ' (was ' . ($was_deleted ? 'in Recently Deleted' : $doc['status']) . ") to {$place}" : '')
    . ($reason !== '' ? ". Reason: {$reason}" : '') . '.');

// The owner hears about anything the Admin does to their document
if ($is_admin && (int)$doc['faculty_id'] !== (int)$me['user_id']) {
    $message = match ($action) {
        'archive' => "The Admin moved your {$name} to your Archived Docs; it no longer appears in your Public Docs, but you can still view it there. Reason: {$reason}.",
        'delete'  => "The Admin deleted your {$name} from your 201 file. Reason: {$reason}. It stays in Recently Deleted until {$until}, "
                   . 'when it is permanently deleted -- contact the Admin if it should be restored.',
        'restore' => "The Admin restored your {$name} to your {$place}. Reason: {$reason}.",
    };
    notify($pdo, (int)$doc['faculty_id'], $message);
}

$owner = $is_admin && (int)$doc['faculty_id'] !== (int)$me['user_id'] ? "{$doc['full_name']}'s" : 'your';
$back('flash_success', match ($action) {
    'delete'  => ucfirst($owner) . " {$name} was moved to Recently Deleted. It can be restored until {$until}; after that it is permanently deleted.",
    'restore' => "Restored {$owner} {$name} to {$place}.",
    default   => "{$verb} {$owner} {$name}." . ($action === 'archive' ? ' It can be restored from Archived Docs.' : ''),
});
