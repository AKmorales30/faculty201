<?php
/**
 * Sending email through SendGrid's web API (HTTPS, not SMTP -- Render's free
 * plan blocks SMTP). Used for the two-factor login codes, email
 * verification, password reset and account notices (includes/two_factor.php).
 *
 * Settings: config/mail.php (template config/mail.example.php), or the
 * environment variables SENDGRID_API_KEY, MAIL_FROM_EMAIL, MAIL_FROM_NAME
 * (Render). MAIL_CONFIG_FILE points at a settings file kept elsewhere.
 */

/** Settings from config/mail.php, with environment overrides; the template's placeholders count as not set. */
function mail_config(): array {
    static $config = null;
    if ($config !== null) { return $config; }
    $defaults = ['sendgrid_api_key' => '', 'from_email' => '', 'from_name' => SYSTEM_NAME, 'log_file' => ''];
    $file = getenv('MAIL_CONFIG_FILE') ?: ROOT_PATH . '/config/mail.php';
    $loaded = is_file($file) ? (include $file) : [];
    $config = array_merge($defaults, is_array($loaded) ? $loaded : []);
    foreach (['SENDGRID_API_KEY' => 'sendgrid_api_key', 'MAIL_FROM_EMAIL' => 'from_email', 'MAIL_FROM_NAME' => 'from_name',
              'MAIL_LOG_FILE' => 'log_file'] as $env => $key) {
        if (getenv($env) !== false) { $config[$key] = getenv($env); }
    }
    foreach ($config as $key => $value) {
        $config[$key] = str_contains((string)$value, 'PASTE-') ? '' : trim((string)$value);
    }
    return $config;
}

/** Whether email can be sent (or, for local testing, written to the log file). */
function mail_ready(): bool {
    $c = mail_config();
    return $c['log_file'] !== '' || ($c['sendgrid_api_key'] !== '' && $c['from_email'] !== '');
}

/** "j***@gmail.com" -- an address shown on screen or in logs without giving it away. */
function mask_email(string $email): string {
    [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
    return mb_substr($local, 0, 1) . '***@' . $domain;
}

/**
 * Send one email. Returns whether SendGrid accepted it. Never throws; a
 * failure is logged without the message (it may hold a code).
 */
function send_email(string $to, string $subject, string $text, string $html): bool {
    $c = mail_config();
    if ($c['log_file'] !== '') {
        $entry = json_encode(['at' => date('Y-m-d H:i:s'), 'to' => $to, 'subject' => $subject, 'text' => $text]) . "\n";
        return file_put_contents($c['log_file'], $entry, FILE_APPEND | LOCK_EX) !== false;
    }
    if (!mail_ready()) {
        error_log('Email to ' . mask_email($to) . ' not sent: SendGrid is not set up (config/mail.php, or SENDGRID_API_KEY and MAIL_FROM_EMAIL)');
        return false;
    }
    $payload = [
        'personalizations'  => [['to' => [['email' => $to]]]],
        'from'              => ['email' => $c['from_email'], 'name' => $c['from_name']],
        'subject'           => $subject,
        'content'           => [['type' => 'text/plain', 'value' => $text], ['type' => 'text/html', 'value' => $html]],
        // Codes must reach the reader as typed: no rewritten links or tracking pixels
        'tracking_settings' => ['click_tracking' => ['enable' => false, 'enable_text' => false], 'open_tracking' => ['enable' => false]],
    ];
    $ch = curl_init('https://api.sendgrid.com/v3/mail/send');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $c['sendgrid_api_key'], 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($status === 202) {
        return true;
    }
    error_log('Email to ' . mask_email($to) . ' not sent: SendGrid answered ' . ($status ?: 'nothing') . ($error !== '' ? " ({$error})" : '')
        . ': ' . mb_substr((string)$body, 0, 300));
    return false;
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
