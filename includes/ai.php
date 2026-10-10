<?php
/**
 * AI features (Google Gemini): settings, the shared service, usage log,
 * data privacy and safe display. Loaded by includes/functions.php.
 *
 *   AI report summary   includes/ai_report.php    (training_report.php)
 *   Reminders           includes/ai_reminders.php (dashboards, cron/reminder_check.php)
 *   Chatbot             includes/ai_chatbot.php   (api/chatbot.php, includes/chatbot_widget.php)
 *
 * Privacy: Gemini only ever receives what a feature needs -- names, counts,
 * document categories, dates, seminar titles and organizers. Never ID
 * numbers (GSIS, TIN, PhilHealth, SSS, Pag-IBIG, UMID, PhilSys, employee
 * no.), addresses, birth dates, contact numbers, e-mail addresses,
 * passwords, or file contents / images / OCR text. Every payload is built
 * from chosen fields and then passed through ai_private_filter() as a
 * safety net; user-typed text goes through ai_redact_text().
 */
require_once __DIR__ . '/GeminiService.php';

/** Settings from config/ai.php (or AI_CONFIG_FILE), with environment overrides. Missing file = AI off. */
function ai_config(): array {
    static $config = null;
    if ($config !== null) { return $config; }
    $defaults = [
        'ai_enabled' => false, 'gemini_api_key' => '', 'gemini_model' => 'gemini-3.8-flash',
        'request_timeout' => 25, 'connect_timeout' => 5,
        'chatbot_messages_per_hour' => 30, 'chatbot_context_messages' => 6,
        'report_send_full_names' => true, 'ca_bundle' => '',
    ];
    $file = getenv('AI_CONFIG_FILE') ?: ROOT_PATH . '/config/ai.php';
    $loaded = is_file($file) ? (include $file) : [];
    $config = array_merge($defaults, is_array($loaded) ? $loaded : []);
    if (getenv('GEMINI_API_KEY') !== false) { $config['gemini_api_key'] = getenv('GEMINI_API_KEY'); }
    if (getenv('GEMINI_MODEL') !== false)   { $config['gemini_model'] = getenv('GEMINI_MODEL'); }
    if (getenv('AI_ENABLED') !== false)     { $config['ai_enabled'] = getenv('AI_ENABLED') === '1'; }
    // The template's placeholder is not a key
    if (str_contains((string)$config['gemini_api_key'], 'PASTE-YOUR')) { $config['gemini_api_key'] = ''; }
    return $config;
}

/** The shared AI service. $replace swaps it (tests, or a provider subclass). */
function ai_service(?GeminiService $replace = null): GeminiService {
    static $service = null;
    if ($replace) { $service = $replace; }
    return $service ??= new GeminiService(ai_config());
}

/** AI switched on and a key set. (Gemini may still be unreachable -- every caller has a fallback.) */
function ai_enabled(): bool {
    return ai_service()->isAvailable();
}

/**
 * One row per AI call in ai_usage_log: who, which feature (report /
 * reminder / chatbot), success, and the short error code on failure --
 * never the key, the prompt or the answer. Never throws.
 */
function ai_log_usage(PDO $pdo, ?int $user_id, string $feature, bool $success, ?string $error = null): void {
    try {
        $pdo->prepare("INSERT INTO ai_usage_log (user_id, feature, success, error_message) VALUES (?, ?, ?, ?)")
            ->execute([$user_id, $feature, $success ? 1 : 0, $error !== null ? mb_substr($error, 0, 255) : null]);
    } catch (Throwable $e) {
        error_log('AI usage log failed: ' . $e->getMessage());
    }
}

// ---------------------------------------------------------------------
// Data privacy
// ---------------------------------------------------------------------

/** Keys never sent to the AI, matched case-insensitively anywhere in a key name. */
function ai_private_key_patterns(): array {
    return ['gsis', 'tin', 'philhealth', 'sss', 'pagibig', 'pag_ibig', 'umid', 'philsys', 'pcn', 'employee_no', 'employee_id',
            'gov_id', 'license', 'address', 'res_', 'perm_', 'zip', 'birth', 'dob', 'contact', 'mobile', 'telephone', 'phone',
            'email', 'password', 'hash', 'file_path', 'ocr', 'file_data', 'image', 'picture', 'ip_address', 'user_agent', 'csrf'];
}

/**
 * Safety net for anything sent to the AI: drops private keys (recursively)
 * and redacts ID numbers, phone numbers and e-mail addresses inside text.
 * 'tin' is matched as a whole word part (so "training" / "meeting" stay);
 * 'res_' / 'perm_' only at the start of a key.
 */
function ai_private_filter($data) {
    if (is_string($data)) { return ai_redact_text($data); }
    if (!is_array($data)) { return $data; }
    $out = [];
    foreach ($data as $key => $value) {
        if (is_string($key) && ai_is_private_key($key)) { continue; }
        $out[$key] = ai_private_filter($value);
    }
    return $out;
}

function ai_is_private_key(string $key): bool {
    $k = strtolower($key);
    foreach (ai_private_key_patterns() as $p) {
        $hit = match (true) {
            $p === 'tin'            => (bool)preg_match('/(^|_)tin($|_)/', $k),
            str_ends_with($p, '_')  => str_starts_with($k, $p),   // res_city, perm_street
            default                 => str_contains($k, $p),
        };
        if ($hit) { return true; }
    }
    return false;
}

/** Replace e-mail addresses, PH phone numbers and 9+ digit ID numbers in free text. Dates (8 digits) are kept. */
function ai_redact_text(string $text): string {
    $text = preg_replace('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', '[e-mail removed]', $text);
    $text = preg_replace('/(?<!\d)(?:\+?63|0)9\d{2}[\s-]?\d{3}[\s-]?\d{4}(?!\d)/', '[number removed]', $text);
    return preg_replace('/(?<![\d-])\d(?:[-\s]?\d){8,}(?![\d-])/', '[number removed]', $text);
}

/** "Maria" for "Dr. Maria Santos" -- titles skipped (same list as user_initials()). */
function ai_first_name(string $full_name): string {
    $skip = ['dr', 'engr', 'prof', 'mr', 'ms', 'mrs', 'atty', 'arch'];
    foreach (preg_split('/\s+/u', trim($full_name)) as $w) {
        if ($w !== '' && !in_array(mb_strtolower(rtrim($w, '.,')), $skip, true)) { return $w; }
    }
    return 'there';
}

/** "Maria S." -- first name and last initial, for report_send_full_names = false. */
function ai_short_name(string $full_name): string {
    $words = array_values(array_filter(preg_split('/\s+/u', trim($full_name)),
        fn($w) => $w !== '' && !in_array(mb_strtolower(rtrim($w, '.,')), ['dr', 'engr', 'prof', 'mr', 'ms', 'mrs', 'atty', 'arch', 'jr', 'sr', 'ii', 'iii', 'iv'], true)));
    if (count($words) < 2) { return $words[0] ?? $full_name; }
    return $words[0] . ' ' . mb_strtoupper(mb_substr(end($words), 0, 1)) . '.';
}

// ---------------------------------------------------------------------
// Display: AI text is always escaped; only line breaks, bullet / numbered
// lists and **bold** are turned into HTML, after escaping.
// ---------------------------------------------------------------------

function ai_format_html(string $text): string {
    $html = '';
    $list = null;   // 'ul' / 'ol' while inside a list
    $inline = fn(string $s): string => preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', h($s));
    foreach (preg_split('/\R/u', trim($text)) as $line) {
        $line = trim($line);
        $type = preg_match('/^[-*•]\s+(.*)$/u', $line, $m) ? 'ul' : (preg_match('/^\d{1,2}[.)]\s+(.*)$/', $line, $m) ? 'ol' : null);
        if ($list && $type !== $list) { $html .= "</{$list}>"; $list = null; }
        if ($type) {
            if (!$list) { $html .= "<{$type} class=\"mb-1 ps-3\">"; $list = $type; }
            $html .= '<li>' . $inline($m[1]) . '</li>';
        } elseif ($line === '') {
            $html .= '<div class="ai-gap"></div>';
        } else {
            $line = preg_replace('/^#{1,6}\s+/', '', $line);   // markdown headings -> plain bold line
            $html .= '<div>' . $inline($line) . '</div>';
        }
    }
    if ($list) { $html .= "</{$list}>"; }
    return $html;
}

function ai_disclaimer_html(string $extra_class = ''): string {
    return '<div class="small text-muted ai-disclaimer ' . h($extra_class) . '"><i class="fa-solid fa-circle-info"></i> '
         . 'AI-generated content may contain errors. Please review before use.</div>';
}

require_once __DIR__ . '/ai_reminders.php';
