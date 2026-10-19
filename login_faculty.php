<?php
// Old Faculty login page. Logging in now happens on the landing page; this
// address only forwards there (with the Faculty form open) so bookmarks still work.
require_once __DIR__ . '/includes/auth.php';
header('Location: ' . BASE_URL . '/index.php?role=faculty');
exit;
