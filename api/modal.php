<?php
/**
 * Content of the pop-up (#appModal, includes/app_modal.php), loaded by
 * assets/js/app.js. GET only -- it changes nothing (forms inside a pop-up
 * post to their own page with a CSRF token). Every view checks the role and
 * ownership here, whatever the page that opened it showed:
 *
 *   view=document&id=N   preview + details of a document     can_access_document():
 *                                                            its owner, or the Admin
 *   view=review&id=N     check / correct a document's type   Admin
 *                        and details (flagged or not)
 *   view=search&q=...    header search by title, faculty or  everyone; the Admin all
 *                        document type                       documents, others their own
 *   view=list&category=  ONE category of records -- the one  per category, see
 *                        clicked (PDS for update, expired,   includes/modals/list.php
 *                        flagged, recent uploads, ...)    (scope by role / ownership)
 *   view=pds_versions&user=N  the PDS version history       pds_can_view_versions(): own,
 *                        (open a version: owner / Admin)     Admin, Chair / Dean in scope
 *   view=person&id=N     a faculty member's profile summary  can_view_profile(): Admin,
 *                        (Faculty Profiles, Chair/Dean Home) Dean (college), Chair (program)
 *
 * Returns an HTML fragment for the shared pop-up (includes/app_modal.php):
 * its first element carries the title (data-modal-title), and an element
 * with data-modal-footer is moved next to the Close button. Every value
 * from the database is escaped (h()).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');

/** End with a short message in the pop-up. */
function modal_fail(int $code, string $message): never {
    http_response_code($code);
    echo '<div data-modal-title="' . h($code === 404 ? 'Not found' : 'Not available') . '"></div>'
       . '<div class="alert alert-warning mb-0"><i class="fa-solid fa-circle-info"></i> ' . h($message) . '</div>';
    exit;
}

if (!is_logged_in()) {
    http_response_code(401);   // app.js reloads the page, which sends them to the login
    exit;
}
$me = current_user();
if (!empty($me['must_change_password'])) {
    modal_fail(403, 'Please change your temporary password first.');
}

/** documents row with its owner and who posted / archived it, or null. */
function modal_document(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare(
        "SELECT d.*, u.full_name, u.employment_type, u.role AS owner_role, u.program,
                p.full_name AS posted_by_name, a.full_name AS archived_by_name, rv.full_name AS reviewed_by_name
         FROM documents d
         JOIN users u ON u.user_id = d.faculty_id
         LEFT JOIN users p ON p.user_id = d.posted_by
         LEFT JOIN users a ON a.user_id = d.archived_by
         LEFT JOIN users rv ON rv.user_id = d.reviewed_by
         WHERE d.document_id = ?"
    );
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

$view = (string)($_GET['view'] ?? '');
$is_admin = $me['role'] === 'admin';

switch ($view) {
    case 'document':
        $doc = modal_document($pdo, (int)($_GET['id'] ?? 0));
        if (!$doc || !can_access_document($me, $doc)) {
            modal_fail(403, 'You do not have access to this document.');
        }
        include __DIR__ . '/../includes/modals/document.php';
        break;

    case 'review':
        if (!$is_admin) {
            modal_fail(403, 'Only the Admin corrects document types.');
        }
        $doc = modal_document($pdo, (int)($_GET['id'] ?? 0));
        if (!$doc) {
            modal_fail(404, 'That document could not be found.');
        }
        if (!in_array($doc['status'], ['active', 'archived', 'for_review'], true)) {
            include __DIR__ . '/../includes/modals/document.php';   // in Recently Deleted: show it instead
            break;
        }
        include __DIR__ . '/../includes/modals/review.php';
        break;

    case 'search':
        include __DIR__ . '/../includes/modals/search.php';
        break;

    case 'list':
        include __DIR__ . '/../includes/modals/list.php';   // checks the role for its category
        break;

    case 'pds_versions':
        require_once __DIR__ . '/../includes/pds.php';
        require_once __DIR__ . '/../includes/admin_ui.php';   // admin_empty(), admin_icon_link()
        $owner = user_row($pdo, (int)($_GET['user'] ?? 0));
        if (!$owner || !in_array($owner['role'], ['faculty', 'program_chair', 'dean'], true) || !pds_can_view_versions($pdo, $me, (int)$owner['user_id'])) {
            modal_fail(403, 'You do not have access to that PDS.');
        }
        include __DIR__ . '/../includes/modals/pds_versions.php';
        break;

    case 'person':
        $person = user_row($pdo, (int)($_GET['id'] ?? 0));
        if (!$person || !in_array($person['role'], ['faculty', 'program_chair', 'dean'], true) || !can_view_profile($pdo, $me, (int)$person['user_id'])) {
            modal_fail(403, 'You do not have access to that profile.');
        }
        include __DIR__ . '/../includes/modals/person.php';
        break;

    default:
        modal_fail(404, 'Unknown pop-up.');
}
