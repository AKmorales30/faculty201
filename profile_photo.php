<?php
/**
 * Serves a profile picture (from profile_pictures) to someone allowed to
 * see that profile: the account holder, or a viewer can_view_profile()
 * allows. Pictures are never stored at a guessable URL; ?v= is the
 * picture's random name, so a new picture isn't hidden by the browser cache.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

// Signed in is enough (not require_login(): its redirect to Change Password
// would break the header picture while a temporary password is being replaced)
$user_id = (int)($_GET['id'] ?? 0);
if (!is_logged_in() || !can_view_profile($pdo, current_user(), $user_id)) {
    http_response_code(403);
    exit;
}
$stmt = $pdo->prepare("SELECT mime_type, data FROM profile_pictures WHERE user_id = ?");
$stmt->execute([$user_id]);
$picture = $stmt->fetch();
if (!$picture) {
    http_response_code(404);
    exit;
}

header('Content-Type: ' . $picture['mime_type']);
header('Content-Length: ' . strlen($picture['data']));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=86400');
echo $picture['data'];
