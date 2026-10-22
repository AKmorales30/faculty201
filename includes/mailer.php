<?php
/**
 * Sending email through a web API (HTTPS, not SMTP -- Render's free plan
 * blocks SMTP): Gmail (Google's Gmail API) by default, or Postmark /
 * SendGrid. Used for the two-factor login codes, email verification,
 * password reset and account notices (includes/two_factor.php).
 *
 * Settings: config/mail.php (template config/mail.example.php, which has
 * the Google setup steps), or the environment variables MAIL_PROVIDER,
 * GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET, GOOGLE_REFRESH_TOKEN (or
 * POSTMARK_SERVER_TOKEN / SENDGRID_API_KEY), MAIL_FROM_EMAIL, MAIL_FROM_NAME
 * (Render). MAIL_CONFIG_FILE points at a settings file kept elsewhere.
 */

/** Settings from config/mail.php, with environment overrides; the template's placeholders count as not set. */
function mail_config(): array {
    static $config = null;
    if ($config !== null) { return $config; }
    $defaults = ['provider' => 'gmail', 'google_client_id' => '', 'google_client_secret' => '', 'google_refresh_token' => '',
                 'postmark_server_token' => '', 'sendgrid_api_key' => '', 'from_email' => '',
                 'from_name' => SYSTEM_NAME, 'log_file' => ''];
    $file = getenv('MAIL_CONFIG_FILE') ?: ROOT_PATH . '/config/mail.php';
    $loaded = is_file($file) ? (include $file) : [];
    $config = array_merge($defaults, is_array($loaded) ? $loaded : []);
    foreach (['MAIL_PROVIDER' => 'provider', 'GOOGLE_CLIENT_ID' => 'google_client_id', 'GOOGLE_CLIENT_SECRET' => 'google_client_secret',
              'GOOGLE_REFRESH_TOKEN' => 'google_refresh_token', 'POSTMARK_SERVER_TOKEN' => 'postmark_server_token',
              'SENDGRID_API_KEY' => 'sendgrid_api_key', 'MAIL_FROM_EMAIL' => 'from_email', 'MAIL_FROM_NAME' => 'from_name',
              'MAIL_LOG_FILE' => 'log_file'] as $env => $key) {
        if (getenv($env) !== false) { $config[$key] = getenv($env); }
    }
    foreach ($config as $key => $value) {
        $config[$key] = str_contains((string)$value, 'PASTE-') ? '' : trim((string)$value);
    }
    $provider = strtolower($config['provider']);
    $config['provider'] = in_array($provider, ['postmark', 'sendgrid'], true) ? $provider : 'gmail';
    return $config;
}

/** The provider's key / token from the settings ('' when not set). For Gmail: all three Google values, or ''. */
function mail_api_key(array $c): string {
    if ($c['provider'] === 'gmail') {
        $ready = $c['google_client_id'] !== '' && $c['google_client_secret'] !== '' && $c['google_refresh_token'] !== '';
        return $ready ? $c['google_refresh_token'] : '';
    }
    return $c['provider'] === 'sendgrid' ? $c['sendgrid_api_key'] : $c['postmark_server_token'];
}

/** Whether email can be sent (or, for local testing, written to the log file). */
function mail_ready(): bool {
    $c = mail_config();
    return $c['log_file'] !== '' || (mail_api_key($c) !== '' && $c['from_email'] !== '');
}

/** "j***@gmail.com" -- an address shown on screen or in logs without giving it away. */
function mask_email(string $email): string {
    [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
    return mb_substr($local, 0, 1) . '***@' . $domain;
}

/**
 * Send one email. Returns whether the provider accepted it. Never throws; a
 * failure is logged without the message (it may hold a code).
 */
function send_email(string $to, string $subject, string $text, string $html): bool {
    $c = mail_config();
    if ($c['log_file'] !== '') {
        $entry = json_encode(['at' => date('Y-m-d H:i:s'), 'to' => $to, 'subject' => $subject, 'text' => $text]) . "\n";
        return file_put_contents($c['log_file'], $entry, FILE_APPEND | LOCK_EX) !== false;
    }
    $names = ['gmail' => 'Gmail', 'postmark' => 'Postmark', 'sendgrid' => 'SendGrid'];
    $name = $names[$c['provider']];
    if (!mail_ready()) {
        $needs = ['gmail' => 'GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET, GOOGLE_REFRESH_TOKEN', 'postmark' => 'POSTMARK_SERVER_TOKEN',
                  'sendgrid' => 'SENDGRID_API_KEY'][$c['provider']];
        error_log('Email to ' . mask_email($to) . " not sent: {$name} is not set up (config/mail.php, or {$needs} and MAIL_FROM_EMAIL)");
        return false;
    }
    if ($c['provider'] === 'gmail') {
        $token = gmail_access_token($c);
        if ($token === null) {
            return false;   // logged by gmail_access_token()
        }
        $url = 'https://gmail.googleapis.com/gmail/v1/users/me/messages/send';
        $headers = ['Authorization: Bearer ' . $token, 'Content-Type: application/json'];
        $payload = ['raw' => base64url_encode(mime_message($c['from_email'], $c['from_name'], $to, $subject, $text, $html))];
    } elseif ($c['provider'] === 'sendgrid') {
        $url = 'https://api.sendgrid.com/v3/mail/send';
        $headers = ['Authorization: Bearer ' . $c['sendgrid_api_key'], 'Content-Type: application/json'];
        $payload = [
            'personalizations'  => [['to' => [['email' => $to]]]],
            'from'              => ['email' => $c['from_email'], 'name' => $c['from_name']],
            'subject'           => $subject,
            'content'           => [['type' => 'text/plain', 'value' => $text], ['type' => 'text/html', 'value' => $html]],
            // Codes must reach the reader as typed: no rewritten links or tracking pixels
            'tracking_settings' => ['click_tracking' => ['enable' => false, 'enable_text' => false], 'open_tracking' => ['enable' => false]],
        ];
    } else {
        $url = 'https://api.postmarkapp.com/email';
        $headers = ['X-Postmark-Server-Token: ' . $c['postmark_server_token'], 'Accept: application/json', 'Content-Type: application/json'];
        $from_name = str_replace(['"', "\r", "\n"], '', $c['from_name']);
        $payload = [
            'From'          => $from_name !== '' ? "\"{$from_name}\" <{$c['from_email']}>" : $c['from_email'],
            'To'            => $to,
            'Subject'       => $subject,
            'TextBody'      => $text,
            'HtmlBody'      => $html,
            'MessageStream' => 'outbound',   // transactional
            'TrackOpens'    => false,
            'TrackLinks'    => 'None',
        ];
    }
    [$status, $body, $error] = mail_http_post($url, $headers, json_encode($payload));
    // Gmail: 200. SendGrid: 202 Accepted. Postmark: 200 with ErrorCode 0 (any other code says why, e.g. 406 inactive recipient).
    $ok = match ($c['provider']) {
        'gmail'    => $status === 200,
        'sendgrid' => $status === 202,
        default    => $status === 200 && (int)(json_decode((string)$body, true)['ErrorCode'] ?? -1) === 0,
    };
    if ($ok) {
        return true;
    }
    error_log('Email to ' . mask_email($to) . " not sent: {$name} answered " . ($status ?: 'nothing') . ($error !== '' ? " ({$error})" : '')
        . ': ' . mb_substr((string)$body, 0, 300));
    return false;
}

/** POST to a web API. @return array{0: int, 1: string|false, 2: string} [HTTP status (0 = no answer), body, cURL error] */
function mail_http_post(string $url, array $headers, string $body): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $answer = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    return [$status, $answer, $error];
}

/**
 * A short-lived Gmail access token from the refresh token (Google's OAuth
 * token endpoint), or null -- logged, e.g. invalid_grant when the refresh
 * token was revoked or expired (see config/mail.example.php, step 2).
 * Kept for the rest of the request; one is good for about an hour.
 */
function gmail_access_token(array $c): ?string {
    static $token = null, $expires = 0;
    if ($token !== null && time() < $expires) { return $token; }
    [$status, $body, $error] = mail_http_post('https://oauth2.googleapis.com/token',
        ['Content-Type: application/x-www-form-urlencoded'],
        http_build_query(['client_id' => $c['google_client_id'], 'client_secret' => $c['google_client_secret'],
                          'refresh_token' => $c['google_refresh_token'], 'grant_type' => 'refresh_token']));
    $data = json_decode((string)$body, true);
    if ($status !== 200 || empty($data['access_token'])) {
        error_log('Gmail access token not received: Google answered ' . ($status ?: 'nothing') . ($error !== '' ? " ({$error})" : '')
            . ': ' . mb_substr((string)$body, 0, 300));
        return null;
    }
    $token = $data['access_token'];
    $expires = time() + max(60, (int)($data['expires_in'] ?? 3600) - 60);
    return $token;
}

function base64url_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/** A header value with non-ASCII text encoded (RFC 2047), so names and subjects with ñ etc. arrive intact. */
function mime_header_text(string $value): string {
    $value = str_replace(["\r", "\n"], '', $value);
    return preg_match('/[^\x20-\x7E]/', $value) ? '=?UTF-8?B?' . base64_encode($value) . '?=' : $value;
}

/** The whole email (RFC 5322, text and HTML alternatives) as Gmail's API takes it before base64url. */
function mime_message(string $from_email, string $from_name, string $to, string $subject, string $text, string $html): string {
    $boundary = 'b' . bin2hex(random_bytes(12));
    $from_name = str_replace(['"', '\\'], '', $from_name);
    // An encoded name (non-ASCII) must not be quoted (RFC 2047); a plain one is, for commas etc.
    $display = mime_header_text($from_name);
    $display = $display === $from_name ? "\"{$from_name}\"" : $display;
    $from = $from_name !== '' ? "{$display} <{$from_email}>" : $from_email;
    $strip = fn($v) => str_replace(["\r", "\n"], '', $v);
    return implode("\r\n", [
        'From: ' . $from,
        'To: ' . $strip($to),
        'Subject: ' . mime_header_text($subject),
        'MIME-Version: 1.0',
        "Content-Type: multipart/alternative; boundary=\"{$boundary}\"",
        '',
        "--{$boundary}",
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
        '',
        chunk_split(base64_encode($text)),
        "--{$boundary}",
        'Content-Type: text/html; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
        '',
        chunk_split(base64_encode($html)),
        "--{$boundary}--",
        '',
    ]);
}

/**
 * The plain-text and HTML versions of a system email: a heading, paragraphs
 * and, if given, a code shown large. Every value is escaped for the HTML.
 * @return array{0: string, 1: string} [text, html]
 */
function email_bodies(string $heading, array $paragraphs, ?string $code = null): array {
    $text = $heading . "\n\n" . ($code !== null ? "    {$code}\n\n" : '') . implode("\n\n", $paragraphs)
          . "\n\n-- \n" . SYSTEM_NAME . "\nCollege of Computing Studies, Universidad de Manila";
    $html = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:520px;margin:0 auto;color:#1f2937">'
          . '<h2 style="color:#062A78;margin:0 0 16px">' . h($heading) . '</h2>'
          . ($code !== null ? '<p style="font-size:32px;font-weight:bold;letter-spacing:8px;margin:0 0 20px;color:#062A78">' . h($code) . '</p>' : '')
          . implode('', array_map(fn($p) => '<p style="margin:0 0 14px;line-height:1.5">' . h($p) . '</p>', $paragraphs))
          . '<p style="margin:24px 0 0;font-size:12px;color:#6b7280">' . h(SYSTEM_NAME) . '<br>College of Computing Studies, Universidad de Manila</p>'
          . '</div>';
    return [$text, $html];
}
