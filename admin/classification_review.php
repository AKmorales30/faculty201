<?php
/**
 * Old address of Classification Review, now Files > Manage Document
 * (admin/manage_documents.php), where every new upload is reviewed. Kept so
 * bookmarks and old links still work.
 */
require_once __DIR__ . '/../includes/auth.php';
require_role('admin');
header('Location: ' . BASE_URL . '/admin/manage_documents.php', true, 301);
exit;
