<?php
/**
 * Old address of the Admin's document search, now Files > 201-Files
 * (admin/public_docs.php). Kept so bookmarks and old links still work;
 * the filters in the query string are passed on.
 */
require_once __DIR__ . '/../includes/auth.php';
require_role('admin');
header('Location: ' . BASE_URL . '/admin/public_docs.php' . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : ''), true, 301);
exit;
