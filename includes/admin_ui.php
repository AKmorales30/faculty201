<?php
/**
 * Markup helpers shared by the pages of the Faculty-201 File Management
 * System (every role uses the same layout: includes/header.php /
 * footer.php). Styles: assets/css/admin.css (scoped to body.admin-ui);
 * behaviour: assets/js/admin.js.
 */

/** The signed-in user's Home page, relative to BASE_URL. */
function portal_home_path(): string {
    return match (current_user()['role'] ?? '') {
        'admin'                 => 'admin/dashboard.php',
        'program_chair', 'dean' => 'approval/dashboard.php',
        default                 => 'faculty/dashboard.php',
    };
}

/** URL of a logo in assets/img/ (udm-logo / ccs-logo, any common extension), or null when the file is missing. */
function admin_logo_url(string $basename): ?string {
    foreach (['png', 'webp', 'jpg', 'jpeg', 'svg'] as $ext) {
        if (is_file(ROOT_PATH . "/assets/img/$basename.$ext")) {
            return BASE_URL . "/assets/img/$basename.$ext";
        }
    }
    return null;
}

/** Cache-busting ?v= for a file under the app root. */
function admin_asset(string $path): string {
    return BASE_URL . '/' . $path . '?v=' . (int)@filemtime(ROOT_PATH . '/' . $path);
}

/**
 * The line under the top bar on every Admin page: a short explanation on
 * the left and the page's main buttons on the right. $intro_html is
 * trusted markup (escape values inside it); $actions_html likewise.
 */
function admin_intro(string $intro_html, string $actions_html = ''): string {
    return '<div class="ui-intro">'
         . ($intro_html !== '' ? '<p class="ui-intro-text">' . $intro_html . '</p>' : '<span></span>')
         . ($actions_html !== '' ? '<div class="ui-intro-actions">' . $actions_html . '</div>' : '')
         . '</div>';
}

/** "Nothing here" block: round icon, title, a line of help, and (optional) a Clear filters link. */
function admin_empty(string $title, string $text = '', ?string $clear_url = null, string $icon = 'fa-inbox'): string {
    return '<div class="ui-empty">'
         . '<span class="ui-empty-icon" aria-hidden="true"><i class="fa-solid ' . h($icon) . '"></i></span>'
         . '<div class="ui-empty-title">' . h($title) . '</div>'
         . ($text !== '' ? '<div class="ui-empty-text">' . h($text) . '</div>' : '')
         . ($clear_url !== null ? '<a class="btn btn-sm btn-outline-brand" href="' . h($clear_url) . '">Clear filters</a>' : '')
         . '</div>';
}

/** admin_empty() as a table row spanning $cols columns. */
function admin_empty_row(int $cols, string $title, string $text = '', ?string $clear_url = null, string $icon = 'fa-inbox'): string {
    return '<tr class="ui-empty-row"><td colspan="' . $cols . '">' . admin_empty($title, $text, $clear_url, $icon) . '</td></tr>';
}

/** "Showing 26–50 of 112" under a paged list ($shown = rows on this page). */
function admin_range_label(int $page, int $per_page, int $shown, int $total): string {
    if ($total === 0) { return 'No results'; }
    $start = ($page - 1) * $per_page + 1;
    return 'Showing ' . $start . '–' . ($start + $shown - 1) . ' of ' . $total;
}

/**
 * Round icon-only button for table rows, with a hover / focus tooltip.
 * $attrs: extra attributes, already escaped (e.g. data-app-modal="...").
 */
function admin_icon_link(string $href, string $icon, string $label, string $attrs = '', string $tone = ''): string {
    return '<a href="' . h($href) . '" class="btn btn-icon' . ($tone ? ' btn-icon-' . h($tone) : '') . '" data-tooltip title="' . h($label) . '" aria-label="' . h($label) . '" ' . $attrs . '>'
         . '<i class="fa-solid ' . h($icon) . '" aria-hidden="true"></i></a>';
}

/** Confidence score as a short bar + percentage (teal = sure, gold = check it, red = below the low-confidence line). */
function admin_confidence($score): string {
    if ($score === null) { return '<span class="text-muted">Not scored</span>'; }
    $pct = (int)round((float)$score * 100);
    $color = $score >= 0.8 ? 'var(--ad-teal)' : ($score >= CONFIDENCE_THRESHOLD ? 'var(--ad-gold)' : 'var(--ad-red)');
    return '<span class="ui-conf"><span class="ui-bar" aria-hidden="true"><span style="--w:' . $pct . '%;--bar:' . $color . '"></span></span>'
         . '<span class="ui-conf-n">' . $pct . '%</span></span>';
}
