<?php
/**
 * Tab bars that join the pages behind one menu entry, so pages that left
 * the menu stay one click away:
 *   Profile Preference   Profile | My PDS
 *   201-Files (own)    Posted | Pending review | Upload history
 *   201-Files (Admin)  All documents | By faculty (Faculty Records)
 *   Document Status      Status report | Expiring documents | Data analytics
 */

/** Tab bar: $tabs key => [label, url (relative to BASE_URL), icon, badge count (optional)]. */
function page_tabs(array $tabs, string $active): string {
    $out = '<ul class="nav nav-tabs page-tabs mb-4">';
    foreach ($tabs as $key => $tab) {
        [$label, $url, $icon] = $tab;
        $badge = (int)($tab[3] ?? 0);
        $is = $key === $active;
        $out .= '<li class="nav-item"><a class="nav-link' . ($is ? ' active' : '') . '" href="' . h(BASE_URL . '/' . $url) . '"' . ($is ? ' aria-current="page"' : '') . '>'
              . '<i class="fa-solid ' . h($icon) . '" aria-hidden="true"></i> ' . h($label)
              . ($badge ? ' <span class="badge rounded-pill bg-review ms-1">' . $badge . '</span>' : '') . '</a></li>';
    }
    return $out . '</ul>';
}

/** Profile Preference: your profile and your PDS. The Admin keeps no 201 file, so has no PDS tab (and no tab bar). */
function profile_tabs(string $active): string {
    if (current_user()['role'] === 'admin') {
        return '';
    }
    return page_tabs([
        'profile' => ['Profile', 'profile.php', 'fa-circle-user'],
        'pds'     => ['My PDS', 'faculty/pds.php', 'fa-id-card'],
    ], $active);
}

/** A faculty member's (or Chair's / Dean's) own 201-Files. */
function my_docs_tabs(PDO $pdo, string $active): string {
    return page_tabs([
        'posted'  => ['Posted', 'faculty/my_documents.php', 'fa-folder-open'],
        'pending' => ['Pending review', 'faculty/my_documents.php?tab=pending', 'fa-hourglass-half', documents_for_review_count($pdo, (int)current_user()['user_id'])],
        'history' => ['Upload history', 'faculty/my_requests.php', 'fa-clock-rotate-left'],
    ], $active);
}

/** The Admin's 201-Files: the document list or the per-faculty records. */
function admin_docs_tabs(string $active): string {
    return page_tabs([
        'list'    => ['All documents', 'admin/public_docs.php', 'fa-table-list'],
        'faculty' => ['By faculty', 'admin/view_records.php', 'fa-users'],
    ], $active);
}

/** Admin > Report > Document Status. */
function report_tabs(string $active): string {
    return page_tabs([
        'status'    => ['Status report', 'admin/reports.php', 'fa-file-lines'],
        'expiring'  => ['Expiring documents', 'admin/expiring_documents.php', 'fa-hourglass-half'],
        'analytics' => ['Data analytics', 'admin/analytics.php', 'fa-chart-column'],
    ], $active);
}
