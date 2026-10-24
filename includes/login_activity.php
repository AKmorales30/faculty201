<?php
/**
 * Login activity: a record of every login attempt, the browsers ("devices")
 * an account has logged in from, and new-device alerts. Loaded by
 * includes/functions.php; tables from database/supabase/08_login_history.sql.
 *
 *   login_history    one row per attempt -- 'success' (after the code when
 *                    2FA is on), 'failed_password', 'failed_2fa',
 *                    'blocked_locked' (brute-force lockout),
 *                    'blocked_deactivated' (deactivated / deleted account) --
 *                    with the IP (client_ip()), "OS · Browser" and the
 *                    approximate location. An unknown username is stored as
 *                    user_id NULL; what was typed is never kept.
 *   trusted_devices  a browser that logged in before: a random token in an
 *                    HttpOnly cookie, stored only as its SHA-256. Used for
 *                    new-device alerts only -- it never skips 2FA.
 *   ip_locations     ipinfo.io answers, cached IP_LOCATION_CACHE_HOURS.
 *
 * Nothing here may break or slow down logging in: every function catches its
 * own errors (server error log), and the slow parts -- the location lookup
 * and the new-device email -- run after the response has been sent
 * (after_response()). The brute-force lockout keeps using login_attempts.
 * Times are stored in UTC (timestamptz) and shown in Asia/Manila.
 */

const DEVICE_COOKIE = 'f201_device';

// ---------------------------------------------------------------------
// Work after the response
// ---------------------------------------------------------------------

/**
 * Run $job once the response has gone to the browser (end of the request).
 * Apache runs PHP as a module here, so there's no fastcgi_finish_request():
 * the response is closed early instead (Content-Length + Connection: close),
 * which works for the login redirects (no body). Errors are only logged.
 */
function after_response(callable $job): void {
    static $registered = false;
    $GLOBALS['after_response_jobs'][] = $job;
    if (!$registered) {
        $registered = true;
        register_shutdown_function('run_after_response');
    }
}

function run_after_response(): void {
    $jobs = $GLOBALS['after_response_jobs'] ?? [];
    $GLOBALS['after_response_jobs'] = [];
    if (!$jobs) { return; }
    try {
        ignore_user_abort(true);
        if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }   // the next page mustn't wait for the session lock
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } else {
            $body = '';
            while (ob_get_level() > 0) { $body = ob_get_clean() . $body; }
            if (!headers_sent()) {
                header('Content-Length: ' . strlen($body));
                header('Connection: close');
            }
            echo $body;
            flush();
        }
        if (PHP_SAPI !== 'cli' && (int)ini_get('max_execution_time') > 0) { @set_time_limit(60); }
    } catch (Throwable $e) {
        error_log('After-response: closing the response failed: ' . $e->getMessage());
    }
    foreach ($jobs as $job) {
        try {
            $job();
        } catch (Throwable $e) {
            error_log('After-response job failed: ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        }
    }
}

// ---------------------------------------------------------------------
// Device ("OS · Browser") and location
// ---------------------------------------------------------------------

/**
 * "Windows · Chrome", "iOS 17 · Safari", "Android 14 · Samsung Internet" from
 * the User-Agent -- matomo/device-detector (installed by Composer in the
 * Docker image), or a small built-in reading where it isn't installed. No
 * phone models. At most 100 characters.
 */
function device_label(?string $ua = null): string {
    $ua = trim((string)($ua ?? ($_SERVER['HTTP_USER_AGENT'] ?? '')));
    if ($ua === '') { return 'Unknown device'; }
    $os = $browser = null;
    if (!class_exists('DeviceDetector\\DeviceDetector') && is_file(ROOT_PATH . '/vendor/autoload.php')) {
        require_once ROOT_PATH . '/vendor/autoload.php';
    }
    if (class_exists('DeviceDetector\\DeviceDetector')) {
        try {
            $dd = new \DeviceDetector\DeviceDetector($ua);
            $dd->discardBotInformation();
            $dd->parse();
            if ($dd->isBot()) { return 'Automated client'; }
            $o = $dd->getOs();
            $c = $dd->getClient();
            $os_name = is_array($o) ? (string)($o['name'] ?? '') : '';
            if ($os_name !== '' && $os_name !== 'UNK') {
                $os_name = ['Mac' => 'macOS', 'GNU/Linux' => 'Linux', 'Chrome OS' => 'ChromeOS'][$os_name] ?? $os_name;
                $major = explode('.', (string)($o['version'] ?? ''))[0];
                $os = in_array($os_name, ['iOS', 'iPadOS', 'Android'], true) && ctype_digit($major) ? "{$os_name} {$major}" : $os_name;
            }
            $client = is_array($c) ? (string)($c['name'] ?? '') : '';
            if ($client !== '' && $client !== 'UNK') {
                $browser = ['Mobile Safari' => 'Safari', 'Chrome Mobile' => 'Chrome', 'Chrome Mobile iOS' => 'Chrome', 'Firefox Mobile' => 'Firefox',
                            'Firefox Mobile iOS' => 'Firefox', 'Microsoft Edge' => 'Edge', 'Opera Mobile' => 'Opera'][$client] ?? $client;
            }
        } catch (Throwable $e) {
            error_log('Device detection failed: ' . $e->getMessage());
        }
    }
    if ($os === null || $browser === null) {
        [$basic_os, $basic_browser] = device_label_basic($ua);
        $os ??= $basic_os;
        $browser ??= $basic_browser;
    }
    return mb_substr(($os ?? 'Unknown system') . ' · ' . ($browser ?? 'Unknown browser'), 0, 100);
}

/** [OS, browser] read from common User-Agent patterns (the fallback of device_label()). */
function device_label_basic(string $ua): array {
    $os = match (true) {
        (bool)preg_match('/\b(?:iPhone|iPad|iPod).*? OS (\d+)/', $ua, $m) => 'iOS ' . $m[1],
        (bool)preg_match('/Android (\d+)/', $ua, $m)                      => 'Android ' . $m[1],
        str_contains($ua, 'Windows')                                      => 'Windows',
        str_contains($ua, 'CrOS')                                         => 'ChromeOS',
        str_contains($ua, 'Mac OS X')                                     => 'macOS',
        str_contains($ua, 'Linux')                                        => 'Linux',
        default                                                           => null,
    };
    $browser = match (true) {
        str_contains($ua, 'Edg')                                          => 'Edge',
        str_contains($ua, 'OPR/') || str_contains($ua, 'Opera')           => 'Opera',
        str_contains($ua, 'SamsungBrowser')                               => 'Samsung Internet',
        str_contains($ua, 'Firefox') || str_contains($ua, 'FxiOS')        => 'Firefox',
        str_contains($ua, 'Chrome') || str_contains($ua, 'CriOS')         => 'Chrome',
        str_contains($ua, 'Safari')                                       => 'Safari',
        default                                                           => null,
    };
    return [$os, $browser];
}

/** Whether $ip is a public address worth locating (not private, loopback or reserved). */
function ip_is_public(string $ip): bool {
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
}

/**
 * Approximate location of $ip ("Manila, Metro Manila, PH") from ipinfo.io,
 * with the token in the IPINFO_TOKEN environment variable; cached per IP for
 * IP_LOCATION_CACHE_HOURS. null for private IPs, without a token, or when the
 * lookup fails. A 3-second limit; call it after the response (after_response()).
 */
function ip_location(PDO $pdo, string $ip): ?string {
    if (!ip_is_public($ip)) { return null; }
    $token = trim((string)getenv('IPINFO_TOKEN'));
    if ($token === '') { return null; }
    try {
        $stmt = $pdo->prepare("SELECT location FROM ip_locations WHERE ip_address = ? AND looked_up_at >= ?");
        $stmt->execute([$ip, date('Y-m-d H:i:s', time() - IP_LOCATION_CACHE_HOURS * 3600)]);
        $cached = $stmt->fetch();
        if ($cached) { return $cached['location']; }
    } catch (Throwable $e) {
        error_log('IP location cache read failed: ' . $e->getMessage());
    }
    $ch = curl_init('https://ipinfo.io/' . rawurlencode($ip));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Accept: application/json', 'Authorization: Bearer ' . $token],
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT        => 3,
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($status !== 200) {
        error_log('IP location lookup failed: ipinfo.io answered ' . ($status ?: 'nothing') . ($error !== '' ? " ({$error})" : ''));
        return null;   // not cached: tried again next time
    }
    $data = json_decode((string)$body, true) ?: [];
    $parts = array_filter(array_map(fn($k) => trim((string)($data[$k] ?? '')), ['city', 'region', 'country']), 'strlen');
    $location = $parts ? mb_substr(implode(', ', array_unique($parts)), 0, 100) : null;
    try {
        $pdo->prepare("INSERT INTO ip_locations (ip_address, location, looked_up_at) VALUES (?, ?, CURRENT_TIMESTAMP) "
            . sql_on_duplicate('ip_address', 'location = ' . sql_inserted('location') . ', looked_up_at = CURRENT_TIMESTAMP'))
            ->execute([$ip, $location]);
    } catch (Throwable $e) {
        error_log('IP location cache write failed: ' . $e->getMessage());
    }
    return $location;
}

// ---------------------------------------------------------------------
// Login history
// ---------------------------------------------------------------------

/** Status => what it means, in plain words (Login Activity). */
function login_status_labels(): array {
    return [
        'success'             => ['Signed in', 'bg-success-subtle text-success-emphasis'],
        'failed_password'     => ['Wrong password', 'bg-danger-subtle text-danger-emphasis'],
        'failed_2fa'          => ['Wrong login code', 'bg-danger-subtle text-danger-emphasis'],
        'blocked_locked'      => ['Blocked: too many attempts', 'bg-warning-subtle text-warning-emphasis'],
        'blocked_deactivated' => ['Blocked: account deactivated', 'bg-secondary-subtle text-secondary-emphasis'],
    ];
}

/**
 * Record one login attempt (user_id null for a username that doesn't exist).
 * The location is filled in after the response. Never throws.
 */
function login_history_record(PDO $pdo, ?int $user_id, string $status, bool $new_device = false): void {
    try {
        $ip = client_ip();
        $pdo->prepare("INSERT INTO login_history (user_id, ip_address, device_label, status, new_device) VALUES (?, ?, ?, ?, ?)")
            ->execute([$user_id, $ip === 'unknown' ? null : $ip, device_label(), $status, db_is_pgsql() ? ($new_device ? 'true' : 'false') : ($new_device ? 1 : 0)]);
        $id = (int)$pdo->lastInsertId();
        if ($id > 0 && $ip !== 'unknown') {
            after_response(fn() => login_history_locate($pdo, $id, $ip));
        }
    } catch (Throwable $e) {
        error_log('Login history: recording failed (login continues): ' . $e->getMessage());
    }
}

/** Fill in the approximate location of login_history row $id. */
function login_history_locate(PDO $pdo, int $id, string $ip): void {
    $location = ip_location($pdo, $ip);
    $pdo->prepare("UPDATE login_history SET location = ?, location_checked = " . (db_is_pgsql() ? 'true' : '1') . " WHERE id = ?")
        ->execute([$location, $id]);
}

/**
 * Rows from the last 2 days still without a location lookup (the request that
 * logged them ended before it ran): looked up after this response. Called on
 * dashboard loads (run_daily_jobs()).
 */
function login_history_backfill(PDO $pdo, int $limit = 5): void {
    try {
        $stmt = $pdo->prepare("SELECT id, ip_address FROM login_history WHERE location_checked = " . (db_is_pgsql() ? 'false' : '0')
            . " AND ip_address IS NOT NULL AND created_at >= ? ORDER BY id LIMIT " . (int)$limit);
        $stmt->execute([date('Y-m-d H:i:s', time() - 2 * 86400)]);
        $rows = $stmt->fetchAll();
    } catch (Throwable $e) {
        return;   // table not there yet
    }
    if ($rows) {
        after_response(function () use ($pdo, $rows) {
            foreach ($rows as $r) { login_history_locate($pdo, (int)$r['id'], (string)$r['ip_address']); }
        });
    }
}

/**
 * Login history rows, newest first. $filters: user_id, status, from / to
 * (Y-m-d, Asia/Manila days). Only ever called with the signed-in user's own
 * id (Profile Preference) or by the Admin (admin/login_history.php).
 */
function login_history_rows(PDO $pdo, array $filters, int $limit, int $offset = 0): array {
    [$where, $params] = login_history_where($filters);
    $stmt = $pdo->prepare(
        "SELECT h.id, h.user_id, h.ip_address, h.device_label, h.location, h.status, h.new_device, h.created_at,
                u.full_name, u.username, u.role
         FROM login_history h LEFT JOIN users u ON u.user_id = h.user_id
         WHERE $where ORDER BY h.created_at DESC, h.id DESC LIMIT " . (int)$limit . ' OFFSET ' . (int)$offset);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function login_history_count(PDO $pdo, array $filters): int {
    [$where, $params] = login_history_where($filters);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM login_history h WHERE $where");
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

/** @return array{0: string, 1: array} the WHERE condition (alias h) and its parameters */
function login_history_where(array $f): array {
    $where = ['1=1'];
    $params = [];
    if (array_key_exists('user_id', $f) && $f['user_id'] !== null) {
        if ($f['user_id'] === 0) { $where[] = 'h.user_id IS NULL'; }   // unknown usernames
        else { $where[] = 'h.user_id = ?'; $params[] = (int)$f['user_id']; }
    }
    if (!empty($f['status']) && isset(login_status_labels()[$f['status']])) { $where[] = 'h.status = ?'; $params[] = $f['status']; }
    if (!empty($f['from'])) { $where[] = 'h.created_at >= ?'; $params[] = $f['from'] . ' 00:00:00'; }
    if (!empty($f['to'])) { $where[] = 'h.created_at < ?'; $params[] = (new DateTimeImmutable($f['to']))->modify('+1 day')->format('Y-m-d') . ' 00:00:00'; }
    return [implode(' AND ', $where), $params];
}

/** A stored time as "Oct 25, 2026, 2:05 PM" (Asia/Manila). */
function login_time_label(?string $t): string {
    return $t ? date('M j, Y, g:i A', strtotime($t)) : '—';
}

// ---------------------------------------------------------------------
// Trusted devices (new-device alerts only)
// ---------------------------------------------------------------------

/** The device token in this browser's cookie (64 hex characters), or null. */
function device_cookie_token(): ?string {
    $t = $_COOKIE[DEVICE_COOKIE] ?? null;
    return is_string($t) && preg_match('/^[a-f0-9]{64}$/', $t) ? $t : null;
}

/** This browser's trusted_devices row for $user_id (used in the last TRUSTED_DEVICE_DAYS), or null. */
function trusted_device_current(PDO $pdo, int $user_id): ?array {
    $token = device_cookie_token();
    if ($token === null) { return null; }
    try {
        $stmt = $pdo->prepare("SELECT * FROM trusted_devices WHERE user_id = ? AND token_hash = ? AND last_seen >= ?");
        $stmt->execute([$user_id, hash('sha256', $token), date('Y-m-d H:i:s', time() - TRUSTED_DEVICE_DAYS * 86400)]);
        return $stmt->fetch() ?: null;
    } catch (Throwable $e) {
        error_log('Trusted device check failed: ' . $e->getMessage());
        return null;
    }
}

/** Whether the request came over HTTPS (directly, or to the proxy in front, as on Render). */
function request_is_https(): bool {
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') { return true; }
    $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $behind_proxy = $remote !== '' && filter_var($remote, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    return $behind_proxy && strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

/**
 * After a successful login: this browser becomes (or stays) a trusted device
 * of $user_id -- last_seen updated, or a new row. One token per browser, in an
 * HttpOnly, Secure, SameSite=Lax cookie for TRUSTED_DEVICE_DAYS; accounts
 * sharing a browser each get their own row for it. Never throws.
 */
function trusted_device_remember(PDO $pdo, int $user_id, string $label): void {
    try {
        $token = device_cookie_token() ?? bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);
        $stmt = $pdo->prepare("UPDATE trusted_devices SET last_seen = CURRENT_TIMESTAMP, device_label = ? WHERE user_id = ? AND token_hash = ?");
        $stmt->execute([$label, $user_id, $hash]);
        if ($stmt->rowCount() === 0) {
            $pdo->prepare("INSERT INTO trusted_devices (user_id, token_hash, device_label) VALUES (?, ?, ?)")->execute([$user_id, $hash, $label]);
        }
        if (!headers_sent()) {
            setcookie(DEVICE_COOKIE, $token, [
                'expires'  => time() + TRUSTED_DEVICE_DAYS * 86400,
                'path'     => '/',
                'secure'   => request_is_https(),   // Render: always (HTTPS); local http://localhost can't keep a Secure cookie
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
    } catch (Throwable $e) {
        error_log('Trusted device: saving failed (login continues): ' . $e->getMessage());
    }
}

/** $user_id's trusted devices, most recently used first. */
function trusted_devices_for_user(PDO $pdo, int $user_id): array {
    try {
        $stmt = $pdo->prepare("SELECT id, token_hash, device_label, first_seen, last_seen FROM trusted_devices WHERE user_id = ? ORDER BY last_seen DESC");
        $stmt->execute([$user_id]);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * A correct password from a browser that isn't one of the account's trusted
 * devices (called by login_process.php, before any 2FA code). Only when the
 * account already has a trusted device -- otherwise this is its first login
 * since login activity began, and every account would alert once. At most one
 * alert per user and device per NEW_DEVICE_ALERT_MINUTES. A "New device
 * login" Security Alert for the Admin, and -- after the response -- an email
 * to the verified address, if there is one. The IP alone never alerts.
 */
function new_device_login(PDO $pdo, array $user): void {
    try {
        $user_id = (int)$user['user_id'];
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM trusted_devices WHERE user_id = ?");
        $stmt->execute([$user_id]);
        if ((int)$stmt->fetchColumn() === 0) { return; }

        $label = device_label();
        $reason = mb_substr('New device login: ' . $label, 0, 255);
        $stmt = $pdo->prepare("SELECT 1 FROM security_alerts WHERE user_id = ? AND reason = ? AND created_at >= ?");
        $stmt->execute([$user_id, $reason, date('Y-m-d H:i:s', time() - NEW_DEVICE_ALERT_MINUTES * 60)]);
        if ($stmt->fetchColumn()) { return; }

        $ip = client_ip();
        $pdo->prepare("INSERT INTO security_alerts (user_id, reason, ip_address) VALUES (?, ?, ?)")->execute([$user_id, $reason, $ip]);
        $email = verified_email($user);
        if ($email === null) { return; }   // logged and alerted; no email to send
        $when = date('F j, Y, g:i A') . ' (Philippine time)';
        after_response(function () use ($pdo, $user, $email, $label, $ip, $when) {
            $location = $ip !== 'unknown' ? ip_location($pdo, $ip) : null;
            [$text, $html] = email_bodies('New device login', [
                "Your account {$user['username']} on the " . SYSTEM_NAME . ' was just signed in to (the correct password was entered) from a device it hasn\'t used before:',
                'Device: ' . $label,
                'IP address: ' . $ip,
                'Approximate location: ' . ($location ?? 'unknown'),
                'Date and time: ' . $when,
                "If this was you, you can ignore this email. If this wasn't you, change your password and contact the administrator.",
            ]);
            if (!send_email($email, 'New device login', $text, $html)) {
                error_log('New-device email to ' . mask_email($email) . ' not sent.');
            }
        });
    } catch (Throwable $e) {
        error_log('New-device alert failed (login continues): ' . $e->getMessage());
    }
}

// ---------------------------------------------------------------------
// Retention (once a day, with the dashboards' daily jobs)
// ---------------------------------------------------------------------

/** Delete login history older than LOGIN_HISTORY_DAYS, devices unused for TRUSTED_DEVICE_DAYS, old location cache. */
function cleanup_login_history(PDO $pdo): array {
    $out = [];
    foreach ([
        'login_history'   => ["DELETE FROM login_history WHERE created_at < ?", LOGIN_HISTORY_DAYS * 86400],
        'trusted_devices' => ["DELETE FROM trusted_devices WHERE last_seen < ?", TRUSTED_DEVICE_DAYS * 86400],
        'ip_locations'    => ["DELETE FROM ip_locations WHERE looked_up_at < ?", 7 * 86400],
    ] as $table => [$sql, $age]) {
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([date('Y-m-d H:i:s', time() - $age)]);
            $out[$table] = $stmt->rowCount();
        } catch (Throwable $e) {
            error_log("Login history cleanup of {$table} failed: " . $e->getMessage());
        }
    }
    return $out;
}

/** cleanup_login_history() at most once a day (run_once_a_day()). */
function run_daily_login_history_cleanup(PDO $pdo, bool $force = false): ?array {
    return run_once_a_day($pdo, 'login_history_cleanup_last_run', 'cleanup_login_history', $force);
}
