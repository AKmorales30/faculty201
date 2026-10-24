<?php
/**
 * TEMPORARY -- remove after checking. Shows which request headers carry the
 * visitor's real IP address behind Render's proxy, to choose the header for
 * the client IP helper. Admin only, read-only, never cached.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_role('admin');

header('Cache-Control: private, no-store');
header('Content-Type: text/plain; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

foreach (['REMOTE_ADDR', 'HTTP_X_FORWARDED_FOR', 'HTTP_CF_CONNECTING_IP', 'HTTP_TRUE_CLIENT_IP', 'HTTP_X_REAL_IP', 'HTTP_FORWARDED',
          'HTTP_X_FORWARDED_PROTO', 'HTTP_CF_RAY', 'HTTP_X_REQUEST_START', 'HTTP_RENDER_PROXY_TTL'] as $key) {
    echo str_pad($key, 24) . ': ' . (isset($_SERVER[$key]) ? (string)$_SERVER[$key] : '(not set)') . "\n";
}
