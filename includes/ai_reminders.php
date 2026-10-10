<?php
/**
 * Reminders: rule-based, with AI wording. Loaded by includes/ai.php.
 *
 * WHO gets a reminder and WHY is decided here with SQL / PHP rules -- never
 * by the AI. Everyone with a 201 file (faculty, Program Chairs, Deans;
 * active accounts, not paused) is checked once a day (run_daily_jobs() on
 * dashboard load, throttled system-wide, and cron/reminder_check.php):
 *
 *   no_seminar    no seminar / training certificate uploaded in the last
 *                 REMINDER_NO_SEMINAR_MONTHS months
 *   missing_docs  required documents missing (faculty_201_checklist(),
 *                 the same list as the dashboard checklist; the PDS item
 *                 is covered by 'pds')
 *   pds           digital PDS not filled in, sections incomplete
 *                 (pds_validate_by_section()), or not updated this year
 *   expiring      documents expired or expiring within
 *                 REMINDER_EXPIRING_DAYS days -- dashboard card only: the
 *                 bell already gets the expiration alerts
 *                 (check_expiration_alerts()), so no second notification
 *
 * Gemini only writes the short message (first name + the reason); when AI
 * is off or fails, a fixed template is used. Each reminder is stored in
 * ai_reminders with a fingerprint of its reason (condition_key) and is
 * repeated only after REMINDER_REPEAT_DAYS, or sooner when the reason
 * changes. Once the condition clears it is marked resolved and disappears.
 */

/** type => label, icon, link (relative to BASE_URL), link text, whether it goes to the bell, summary sentence for Admin / Chair / Dean. */
function reminder_types(): array {
    return [
        'no_seminar'   => ['label' => 'No recent seminar / training', 'icon' => 'fa-chalkboard-user',
                           'link' => 'faculty/submit_document.php', 'link_text' => 'Upload a certificate', 'notify' => true,
                           'summary' => 'not uploaded a seminar or training certificate in ' . (int)REMINDER_NO_SEMINAR_MONTHS . ' months'],
        'missing_docs' => ['label' => 'Missing required documents', 'icon' => 'fa-folder-open',
                           'link' => 'faculty/submit_document.php', 'link_text' => 'Upload now', 'notify' => true,
                           'summary' => 'missing required 201-file documents'],
        'pds'          => ['label' => 'PDS needs updating', 'icon' => 'fa-id-card',
                           'link' => 'faculty/pds.php', 'link_text' => 'Update my PDS', 'notify' => true,
                           'summary' => 'an incomplete or outdated PDS'],
        'expiring'     => ['label' => 'Expired / expiring documents', 'icon' => 'fa-hourglass-half',
                           'link' => 'faculty/submit_document.php', 'link_text' => 'Upload updated copy', 'notify' => false,
                           'summary' => 'expired documents or documents expiring within ' . (int)REMINDER_EXPIRING_DAYS . ' days'],
    ];
}

/** A checklist label as a document name: "FTA -- 1st Semester, AY 2026-2027" -> "FTA for 1st Semester, AY 2026-2027", "Contract of Service on file" -> "Contract of Service". */
function reminder_checklist_label(string $label): string {
    return trim(str_replace([' -- ', ' on file', ' (per educational attainment)'], [' for ', '', ''], $label));
}

/** "Missing: A; B; C and 2 more" helper: the first $max items, then a count. */
function reminder_list_text(array $items, int $max = 3): string {
    $shown = array_slice($items, 0, $max);
    $more = count($items) - count($shown);
    return implode('; ', $shown) . ($more > 0 ? " and {$more} more" : '');
}

/**
 * The reminder conditions that hold for one person now.
 * $prefetched: last_seminar (filed_at of the newest seminar / training
 * certificate, or null) and expiring (their expiring_documents() rows),
 * looked up for everyone at once by check_reminders().
 * @return array<string, array{key: string, reason: string, facts: array}>  type => condition
 */
function reminder_conditions(PDO $pdo, array $user, array $prefetched): array {
    require_once __DIR__ . '/pds.php';
    $uid = (int)$user['user_id'];
    $out = [];

    // No seminar / training certificate in REMINDER_NO_SEMINAR_MONTHS months
    $cutoff = (new DateTimeImmutable('today'))->modify('-' . (int)REMINDER_NO_SEMINAR_MONTHS . ' months')->format('Y-m-d');
    $last = $prefetched['last_seminar'] ?? null;
    if ($last === null || substr($last, 0, 10) < $cutoff) {
        $out['no_seminar'] = [
            'key'    => 'no_seminar',
            'reason' => $last === null
                ? 'No seminar or training certificate uploaded yet.'
                : 'No seminar or training certificate uploaded in ' . (int)REMINDER_NO_SEMINAR_MONTHS . ' months (last one uploaded ' . date('M j, Y', strtotime($last)) . ').',
            'facts'  => ['months' => (int)REMINDER_NO_SEMINAR_MONTHS, 'last_certificate_uploaded' => $last ? date('F Y', strtotime($last)) : 'never'],
        ];
    }

    // Required documents missing (the dashboard checklist, PDS aside)
    $missing = [];
    foreach (faculty_201_checklist($pdo, $uid, $user['employment_type'] ?? null) as $item) {
        if (!$item['done'] && $item['type'] !== 'PDS') { $missing[] = reminder_checklist_label($item['label']); }
    }
    if ($missing) {
        $out['missing_docs'] = [
            'key'    => 'missing_docs:' . implode('|', $missing),
            'reason' => 'Missing from the 201 file: ' . reminder_list_text($missing, 6) . '.',
            'facts'  => ['missing_documents' => array_slice($missing, 0, 6)],
        ];
    }

    // PDS: not filled in / incomplete sections / not updated this year
    $pds = pds_load($pdo, $uid);
    $status = pds_status($pdo, $uid);
    $issues = [];
    if (!$pds['exists'] && !$pds['ld']) {
        $issues[] = 'the digital PDS has not been filled in yet';
        $sections = [];
    } else {
        $sections = array_keys(pds_validate_by_section($pds['data'], $pds['ld']));
        if ($sections) { $issues[] = 'incomplete sections: ' . reminder_list_text($sections, 4); }
    }
    if (!$status['current']) { $issues[] = 'not yet updated for ' . $status['year']; }
    if ($issues) {
        $out['pds'] = [
            'key'    => 'pds:' . ($pds['exists'] || $pds['ld'] ? 'filled' : 'empty') . '|' . implode('|', $sections) . '|' . ($status['current'] ? 'current' : 'old' . $status['year']),
            'reason' => 'PDS: ' . implode('; ', $issues) . '.',
            'facts'  => ['pds_issues' => $issues],
        ];
    }

    // Expired / expiring documents -- card only (the bell has the expiration alerts)
    $docs = $prefetched['expiring'] ?? [];
    if ($docs) {
        $expired = array_values(array_filter($docs, fn($d) => $d['days_left'] < 0));
        $names = array_map(fn($d) => document_type_label($d['document_type'], $d['document_subtype'])
            . ($d['days_left'] < 0 ? ' (expired ' : ' (expires ') . date('M j, Y', strtotime($d['expiration_date'])) . ')', $docs);
        $out['expiring'] = [
            'key'    => 'expiring:' . implode('|', array_map(fn($d) => $d['document_id'] . ($d['days_left'] < 0 ? 'x' : ''), $docs)),
            'reason' => count($docs) . ' document' . (count($docs) === 1 ? '' : 's') . ' expired or expiring within ' . (int)REMINDER_EXPIRING_DAYS . ' days: ' . reminder_list_text($names, 4) . '.',
            'facts'  => ['expired_count' => count($expired), 'expiring_soon_count' => count($docs) - count($expired),
                         'within_days' => (int)REMINDER_EXPIRING_DAYS, 'documents' => array_slice($names, 0, 4)],
        ];
    }
    return $out;
}

/** The fixed message used when AI is off or fails. */
function reminder_template_message(string $type, string $first_name, array $facts): string {
    $hi = "Hi, {$first_name}! ";
    switch ($type) {
        case 'no_seminar':
            return $hi . ($facts['last_certificate_uploaded'] === 'never'
                ? "You haven't uploaded a seminar or training certificate yet. Please upload your certificates to keep your 201 file complete."
                : "It's been over {$facts['months']} months since you uploaded a seminar or training certificate. Please upload any recent ones to keep your 201 file complete.");
        case 'missing_docs':
            $n = count($facts['missing_documents']);
            return $hi . 'Your 201 file is still missing ' . reminder_list_text($facts['missing_documents'], 3) . '. Please upload ' . ($n === 1 ? 'it' : 'them') . ' when you can.';
        case 'pds':
            return $hi . 'Your PDS needs attention (' . implode('; ', $facts['pds_issues']) . '). Please review and update it on the My PDS page.';
        case 'expiring':
            $total = $facts['expired_count'] + $facts['expiring_soon_count'];
            $have = $total === 1 ? 'has' : 'have';
            $what = match (true) {
                !$facts['expiring_soon_count'] => "{$have} expired",
                !$facts['expired_count']       => 'will expire within ' . $facts['within_days'] . ' days',
                default                        => "{$have} expired or will expire within " . $facts['within_days'] . ' days',
            };
            return $hi . ($total === 1 ? 'One of your documents ' : "{$total} of your documents ") . $what
                 . '. Please upload ' . ($total === 1 ? 'an updated copy' : 'updated copies') . ' to keep your 201 file complete.';
    }
    return $hi . 'Please check your 201 file.';
}

/**
 * Messages for new reminders: Gemini writes them in batches (one request
 * per 25 reminders, so the daily check stays quick); any it doesn't write
 * -- AI off, a failure, or an answer that's empty or too long -- get the
 * template. After the first failure the rest use templates at once.
 * $items: [ref => [type, first_name, facts]].
 * @return array<int|string, array{message: string, ai: bool}>
 */
function reminder_write_messages(PDO $pdo, array $items): array {
    $out = [];
    foreach ($items as $ref => $it) {
        $out[$ref] = ['message' => reminder_template_message($it['type'], $it['first_name'], $it['facts']), 'ai' => false];
    }
    if (!$items || !ai_enabled()) { return $out; }

    $system = "You write short reminder messages for faculty members in a university's Faculty 201-File Repository system.\n"
            . "For each item, write ONE message: at most 2 sentences and under 280 characters, professional but warm.\n"
            . "Start with a greeting that uses the first name, e.g. \"Hi, Maria!\" or \"Hey, Maria!\".\n"
            . "Say what is missing or needs attention and what to do (upload the document, update the PDS, upload an updated copy).\n"
            . "Use ONLY the facts given for that item. Do not invent dates, documents, numbers or deadlines. Write in English.\n"
            . "Return every item's ref unchanged.";
    $schema = ['type' => 'object', 'properties' => ['messages' => ['type' => 'array', 'items' => [
        'type' => 'object', 'properties' => ['ref' => ['type' => 'string'], 'message' => ['type' => 'string']], 'required' => ['ref', 'message'],
    ]]], 'required' => ['messages']];

    foreach (array_chunk($items, 25, true) as $chunk) {
        $payload = [];
        foreach ($chunk as $ref => $it) {
            $payload[] = ['ref' => (string)$ref, 'first_name' => $it['first_name'], 'reminder_type' => reminder_types()[$it['type']]['label'], 'facts' => $it['facts']];
        }
        $answer = ai_service()->generate($system, "Write the reminder messages for these items (JSON):\n"
            . json_encode(ai_private_filter($payload), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), ['json_schema' => $schema, 'temperature' => 0.6]);
        ai_log_usage($pdo, null, 'reminder', is_array($answer), ai_service()->lastError());
        if (!is_array($answer)) { break; }   // Gemini unreachable: templates for the rest, no more waiting

        foreach ((array)($answer['messages'] ?? []) as $m) {
            $ref = (string)($m['ref'] ?? '');
            $text = trim(preg_replace('/\s+/', ' ', (string)($m['message'] ?? '')));
            if (isset($chunk[$ref]) && mb_strlen($text) >= 15 && mb_strlen($text) <= 400) {
                $out[$ref] = ['message' => $text, 'ai' => true];
            }
        }
    }
    return $out;
}

/**
 * The daily check: work out everyone's conditions, resolve reminders whose
 * condition cleared, and send the new / repeated / changed ones.
 * @return array{checked: int, sent: int, resolved: int, ai_written: int}
 */
function check_reminders(PDO $pdo): array {
    $users = $pdo->query(
        "SELECT user_id, full_name, role, employment_type FROM users
         WHERE is_active = 1 AND role IN ('faculty', 'program_chair', 'dean')
           AND (employment_status IS NULL OR employment_status <> 'paused')
         ORDER BY user_id"
    )->fetchAll();

    // Looked up for everyone at once
    $last_seminar = $pdo->query(
        "SELECT faculty_id, MAX(filed_at) FROM documents
         WHERE document_type = 'Certificate' AND status IN ('active', 'archived')
           AND (document_subtype IN ('Seminar', 'Training') OR training_type IS NOT NULL)
         GROUP BY faculty_id"
    )->fetchAll(PDO::FETCH_KEY_PAIR);
    $expiring = [];
    foreach (expiring_documents($pdo, null, (int)REMINDER_EXPIRING_DAYS) as $d) { $expiring[(int)$d['faculty_id']][] = $d; }

    $open = [];
    foreach ($pdo->query("SELECT id, user_id, reminder_type, condition_key, created_at FROM ai_reminders WHERE resolved_at IS NULL")->fetchAll() as $r) {
        $open[(int)$r['user_id']][$r['reminder_type']] = $r;
    }
    $repeat_after = (new DateTimeImmutable())->modify('-' . (int)REMINDER_REPEAT_DAYS . ' days')->format('Y-m-d H:i:s');
    $resolve = $pdo->prepare("UPDATE ai_reminders SET resolved_at = NOW() WHERE id = ? AND resolved_at IS NULL");

    $new = [];
    $resolved = 0;
    foreach ($users as $u) {
        $uid = (int)$u['user_id'];
        try {
            $conditions = reminder_conditions($pdo, $u, ['last_seminar' => $last_seminar[$uid] ?? null, 'expiring' => $expiring[$uid] ?? []]);
        } catch (Throwable $e) {
            error_log("Reminder check for user {$uid} failed: " . $e->getMessage());
            continue;
        }
        foreach (array_keys(reminder_types()) as $type) {
            $current = $open[$uid][$type] ?? null;
            if (!isset($conditions[$type])) {
                if ($current) { $resolve->execute([$current['id']]); $resolved++; }
                continue;
            }
            $c = $conditions[$type];
            $key = mb_strlen($c['key']) > 190 ? $type . ':' . sha1($c['key']) : $c['key'];
            if ($current && $current['condition_key'] === $key && $current['created_at'] > $repeat_after) {
                continue;   // already reminded about exactly this, recently
            }
            $new[$uid . ':' . $type] = ['user' => $u, 'type' => $type, 'key' => $key, 'reason' => $c['reason'],
                                        'first_name' => ai_first_name($u['full_name']), 'facts' => $c['facts'],
                                        'replaces' => $current['id'] ?? null];
        }
    }

    $messages = reminder_write_messages($pdo, $new);
    $insert = $pdo->prepare(
        "INSERT INTO ai_reminders (user_id, reminder_type, condition_key, reason, message, link, ai_generated, notification_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $sent = $ai_written = 0;
    foreach ($new as $ref => $n) {
        $meta = reminder_types()[$n['type']];
        $msg = mb_substr($messages[$ref]['message'], 0, 500);
        try {
            $pdo->beginTransaction();
            if ($n['replaces']) { $resolve->execute([$n['replaces']]); }
            $notification_id = null;
            if ($meta['notify']) {
                notify($pdo, (int)$n['user']['user_id'], $msg, null, $meta['link']);
                $notification_id = (int)$pdo->lastInsertId();
            }
            $insert->execute([$n['user']['user_id'], $n['type'], $n['key'], mb_substr($n['reason'], 0, 500), $msg, $meta['link'],
                              $messages[$ref]['ai'] ? 1 : 0, $notification_id]);
            $pdo->commit();
            $sent++;
            if ($messages[$ref]['ai']) { $ai_written++; }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            error_log("Reminder {$ref} failed: " . $e->getMessage());
        }
    }
    return ['checked' => count($users), 'sent' => $sent, 'resolved' => $resolved, 'ai_written' => $ai_written];
}

/** check_reminders() at most once a day (run_once_a_day()). $force: cron/reminder_check.php. */
function run_daily_reminder_check(PDO $pdo, bool $force = false): ?array {
    return run_once_a_day($pdo, 'reminder_check_last_run', 'check_reminders', $force);
}

/** The user's own reminders for the dashboard card: open and not dismissed, newest first. */
function open_reminders_for_user(PDO $pdo, int $user_id): array {
    try {
        $stmt = $pdo->prepare(
            "SELECT id, reminder_type, message, link, ai_generated, created_at FROM ai_reminders
             WHERE user_id = ? AND resolved_at IS NULL AND dismissed_at IS NULL ORDER BY created_at DESC, id DESC"
        );
        $stmt->execute([$user_id]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];   // migration not applied yet
    }
}

/** Hide one of the user's own reminders. Returns whether it was theirs and still shown. */
function dismiss_reminder(PDO $pdo, int $user_id, int $reminder_id): bool {
    $stmt = $pdo->prepare("UPDATE ai_reminders SET dismissed_at = NOW() WHERE id = ? AND user_id = ? AND dismissed_at IS NULL");
    $stmt->execute([$reminder_id, $user_id]);
    return $stmt->rowCount() === 1;
}

/**
 * For the Admin / Program Chair / Dean dashboards: how many people in
 * their scope (profile_scope_sql(), not counting themselves) have an open
 * reminder of each type. Dismissing doesn't remove someone -- only fixing it does.
 * @return array<string, int> type => people
 */
function reminder_scope_counts(PDO $pdo, array $viewer): array {
    try {
        [$scope, $params] = profile_scope_sql($pdo, $viewer);
        $stmt = $pdo->prepare(
            "SELECT r.reminder_type, COUNT(DISTINCT r.user_id) FROM ai_reminders r JOIN users u ON u.user_id = r.user_id
             WHERE r.resolved_at IS NULL AND u.is_active = 1 AND u.user_id <> ? AND $scope GROUP BY r.reminder_type"
        );
        $stmt->execute([(int)$viewer['user_id'], ...$params]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_KEY_PAIR));
    } catch (PDOException $e) {
        return [];
    }
}

/** The people behind reminder_scope_counts() for one type, with the rule-based reason. */
function reminder_scope_list(PDO $pdo, array $viewer, string $type): array {
    [$scope, $params] = profile_scope_sql($pdo, $viewer);
    $stmt = $pdo->prepare(
        "SELECT r.reason, r.created_at, r.dismissed_at, u.user_id, u.full_name, u.role, u.employment_type, u.program, u.college
         FROM ai_reminders r JOIN users u ON u.user_id = r.user_id
         WHERE r.resolved_at IS NULL AND r.reminder_type = ? AND u.is_active = 1 AND u.user_id <> ? AND $scope
         ORDER BY u.full_name"
    );
    $stmt->execute([$type, (int)$viewer['user_id'], ...$params]);
    return $stmt->fetchAll();
}
