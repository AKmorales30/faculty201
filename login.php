<?php
// Old shared login page (login.php?portal=faculty|admin). Logging in now
// happens on the landing page; this address only forwards there, with the
// matching role's form open when the old link named one.
require_once __DIR__ . '/includes/auth.php';
$portal = $_GET['portal'] ?? '';
$query = in_array($portal, ['faculty', 'admin'], true) ? '?role=' . $portal : '';
header('Location: ' . BASE_URL . '/index.php' . $query);
exit;
