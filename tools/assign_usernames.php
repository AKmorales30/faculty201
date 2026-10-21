<?php
/**
 * Gives every account without a username one (first.last, a number added if
 * taken -- assign_missing_usernames() in includes/profile.php), then lists
 * every account's username, for the Admin to pass on.
 *
 *   php tools/assign_usernames.php
 *
 * Uses the same database as the website (config/db.php: config/database.php
 * for Supabase, or the XAMPP MySQL). The app also assigns them by itself on
 * the next login attempt or when the Admin opens Manage Faculty; this just
 * does it now and prints the list. Safe to run any number of times.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Command line only.');
}
require_once __DIR__ . '/../includes/functions.php';

$assigned = assign_missing_usernames($pdo);
echo count($assigned) . " username(s) assigned now.\n\n";

$rows = $pdo->query("SELECT user_id, username, full_name, role, is_active, deleted_at FROM users
                     WHERE purged_at IS NULL ORDER BY user_id")->fetchAll();
$new = array_column($assigned, 'user_id');
printf("%-5s %-28s %-36s %-14s %s\n", 'ID', 'Username', 'Name', 'Role', 'Note');
foreach ($rows as $u) {
    $note = array_filter([
        in_array((int)$u['user_id'], $new, true) ? 'new' : '',
        !empty($u['deleted_at']) ? 'deleted' : ((int)$u['is_active'] !== 1 ? 'deactivated' : ''),
    ]);
    printf("%-5d %-28s %-36s %-14s %s\n", $u['user_id'], (string)$u['username'], mb_substr($u['full_name'], 0, 36),
           str_replace('_', ' ', $u['role']), implode(', ', $note));
}
