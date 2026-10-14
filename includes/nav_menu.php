<?php
/**
 * The left menu of each role, in one place. includes/sidebar.php draws
 * whatever app_menu() returns, so every page of a role shows the same menu
 * and nothing is copied into pages.
 *
 * An entry is a link or a group (a collapsible section header with links):
 *   link   ['label', 'icon', 'url' (relative to BASE_URL), 'active' => fn(string $page): bool, 'badge' => ?int]
 *   group  ['group' => label, 'icon', 'items' => [links]]
 * $page is the script being shown, relative to the app root
 * ('admin/public_docs.php'), from nav_current_page(). A page that isn't in
 * the menu (e.g. Faculty Records) highlights the entry it belongs to.
 *
 * Pages reached another way: notifications (bell icon), the 201 File
 * Assistant (floating button), search (header), Faculty Needing Follow-up
 * (pop-up on Home).
 */

/** The script being shown, relative to the app root, e.g. 'admin/public_docs.php'. */
function nav_current_page(): string {
    return ltrim(str_replace('\\', '/', substr((string)realpath($_SERVER['SCRIPT_FILENAME']), strlen((string)realpath(ROOT_PATH)))), '/');
}

/** Whether a page that takes ?id= (profile, archive) shows the signed-in user's own record. */
function nav_viewing_own(): bool {
    return !isset($_GET['id']) || (int)$_GET['id'] === (int)(current_user()['user_id'] ?? 0);
}

/** fn($page) => whether $page is one of $pages. */
function nav_on(string ...$pages): Closure {
    return fn(string $page): bool => in_array($page, $pages, true);
}

/** Menu entries for $user's role. */
function app_menu(PDO $pdo, array $user): array {
    $own_profile = fn(string $page): bool => ($page === 'profile.php' && nav_viewing_own())
        || in_array($page, ['faculty/pds.php', 'faculty/pds_import.php', 'faculty/pds_print.php'], true);
    $account = ['group' => 'Account', 'icon' => 'fa-user-gear', 'items' => [
        ['label' => 'Profile Preference', 'icon' => 'fa-user-pen', 'url' => 'profile.php', 'active' => $own_profile],
        ['label' => 'Change Password', 'icon' => 'fa-key', 'url' => 'change_password.php', 'active' => nav_on('change_password.php')],
    ]];

    if ($user['role'] === 'admin') {
        return [
            ['label' => 'Home', 'icon' => 'fa-house', 'url' => 'admin/dashboard.php', 'active' => nav_on('admin/dashboard.php', 'reminder_list.php')],
            ['group' => 'Files', 'icon' => 'fa-folder', 'items' => [
                ['label' => 'Public Docs', 'icon' => 'fa-folder-open', 'url' => 'admin/public_docs.php',
                 'active' => nav_on('admin/public_docs.php', 'admin/search_documents.php', 'admin/view_records.php', 'admin/faculty_documents.php', 'document_details.php')],
                ['label' => 'Archived Docs', 'icon' => 'fa-box-archive', 'url' => 'admin/archived_documents.php',
                 'active' => nav_on('admin/archived_documents.php', 'archive.php')],
                ['label' => 'Recently Deleted', 'icon' => 'fa-trash-can-arrow-up', 'url' => 'recently_deleted.php', 'active' => nav_on('recently_deleted.php')],
                ['label' => 'Manage Document', 'icon' => 'fa-file-pen', 'url' => 'admin/manage_documents.php',
                 'active' => nav_on('admin/manage_documents.php', 'admin/classification_review.php'),
                 'badge' => documents_for_review_count($pdo), 'badge_title' => 'For Review'],
            ]],
            ['group' => 'Report', 'icon' => 'fa-chart-pie', 'items' => [
                ['label' => 'Document Status', 'icon' => 'fa-file-circle-check', 'url' => 'admin/reports.php',
                 'active' => nav_on('admin/reports.php', 'admin/expiring_documents.php', 'admin/analytics.php')],
                ['label' => 'Training Summary', 'icon' => 'fa-chalkboard-user', 'url' => 'training_report.php', 'active' => nav_on('training_report.php')],
            ]],
            ['group' => 'Security', 'icon' => 'fa-shield-halved', 'items' => [
                ['label' => 'Manage Faculty', 'icon' => 'fa-users-gear', 'url' => 'admin/manage_faculty.php',
                 'active' => fn(string $page): bool => in_array($page, ['admin/manage_faculty.php', 'admin/academic_ranks.php', 'faculty_directory.php'], true)
                                                     || ($page === 'profile.php' && !nav_viewing_own())],
                ['label' => 'Security Alerts', 'icon' => 'fa-triangle-exclamation', 'url' => 'admin/security_alerts.php',
                 'active' => nav_on('admin/security_alerts.php'), 'badge' => unresolved_security_alert_count($pdo), 'badge_title' => 'Unreviewed alerts'],
                ['label' => 'Activity Logs', 'icon' => 'fa-clock-rotate-left', 'url' => 'admin/activity_logs.php', 'active' => nav_on('admin/activity_logs.php')],
            ]],
            $account,
        ];
    }

    // Faculty, Program Chairs and Deans keep their own 201 file
    $own_public = nav_on('faculty/my_documents.php', 'faculty/my_requests.php', 'document_details.php');
    $own_archive = fn(string $page): bool => $page === 'archive.php' && nav_viewing_own();
    $pending = documents_for_review_count($pdo, (int)$user['user_id']);

    if ($user['role'] === 'faculty') {
        return [
            ['label' => 'Home', 'icon' => 'fa-house', 'url' => 'faculty/dashboard.php', 'active' => nav_on('faculty/dashboard.php')],
            ['label' => 'Upload Document', 'icon' => 'fa-file-circle-plus', 'url' => 'faculty/submit_document.php', 'active' => nav_on('faculty/submit_document.php')],
            ['group' => 'File', 'icon' => 'fa-folder', 'items' => [
                ['label' => 'Public Docs', 'icon' => 'fa-folder-open', 'url' => 'faculty/my_documents.php', 'active' => $own_public,
                 'badge' => $pending, 'badge_title' => 'Waiting for review', 'badge_class' => 'bg-review'],
                ['label' => 'Archived Docs', 'icon' => 'fa-box-archive', 'url' => 'archive.php', 'active' => $own_archive],
                ['label' => 'Recently Deleted', 'icon' => 'fa-trash-can-arrow-up', 'url' => 'recently_deleted.php', 'active' => nav_on('recently_deleted.php')],
            ]],
            $account,
        ];
    }

    // Program Chair / Dean: their own 201 file, plus profiles and the training report of the faculty they oversee
    return [
        ['label' => 'Home', 'icon' => 'fa-house', 'url' => 'approval/dashboard.php', 'active' => nav_on('approval/dashboard.php', 'reminder_list.php')],
        ['label' => 'Upload Document', 'icon' => 'fa-file-circle-plus', 'url' => 'faculty/submit_document.php', 'active' => nav_on('faculty/submit_document.php')],
        ['group' => 'Files', 'icon' => 'fa-folder', 'items' => [
            ['label' => 'Public Docs', 'icon' => 'fa-folder-open', 'url' => 'faculty/my_documents.php', 'active' => $own_public,
             'badge' => $pending, 'badge_title' => 'Waiting for review', 'badge_class' => 'bg-review'],
            ['label' => 'Archived Docs', 'icon' => 'fa-box-archive', 'url' => 'archive.php', 'active' => $own_archive],
            ['label' => 'Recently Deleted', 'icon' => 'fa-trash-can-arrow-up', 'url' => 'recently_deleted.php', 'active' => nav_on('recently_deleted.php')],
        ]],
        ['group' => 'Report', 'icon' => 'fa-chart-pie', 'items' => [
            ['label' => 'Faculty Profiles', 'icon' => 'fa-address-book', 'url' => 'faculty_directory.php',
             'active' => fn(string $page): bool => $page === 'faculty_directory.php'
                                                 || (in_array($page, ['profile.php', 'archive.php'], true) && !nav_viewing_own())],
            ['label' => 'Training Summary', 'icon' => 'fa-chalkboard-user', 'url' => 'training_report.php', 'active' => nav_on('training_report.php')],
        ]],
        $account,
    ];
}
