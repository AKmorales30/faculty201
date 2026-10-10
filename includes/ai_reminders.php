<?php
/**
 * Reminders: rule-based, with AI wording. Loaded by includes/ai.php.
 *
 * WHO gets a reminder and WHY is decided here with SQL / PHP rules -- never
 * by the AI. Every active account is checked once a day: the first
 * dashboard visit of the day by anyone (run_daily_jobs(), throttled
 * system-wide), cron/reminder_check.php, or the Admin's "Run reminder check
 * now". An account the day's run didn't cover -- created after it, or its
 * check failed -- is checked on its own next dashboard visit
 * (ensure_reminders_checked(); ai_reminder_checks records each account's
 * last finished check).
 *
 * Personal reminders, for everyone with a 201 file (faculty, Program
 * Chairs, Deans; paused faculty too, minus this semester's FTA / IPCR):
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
 * Follow-up reminder, for the Admin, Program Chairs and Deans:
 *
 *   follow_up     how many people in their scope (profile_scope_sql())
 *                 have open personal reminders, by type, linking to the
 *                 list (reminder_list.php). Worked out after the personal
 *                 ones, so it counts today's; the counts in the message
 *                 are refreshed in place every day, and the bell is only
 *                 notified again when a new kind of issue appears or after
 *                 REMINDER_REPEAT_DAYS.
 *
 * Gemini only writes the short message of a personal reminder (first name
 * + the reason); when AI is off, fails or is slow, the fixed template is
 * used -- a reminder is never skipped. Each reminder is stored in
 * ai_reminders with a fingerprint of its reason (condition_key) and is
 * repeated only after REMINDER_REPEAT_DAYS (also when it was dismissed),
 * or sooner when the reason changes. Once the condition clears it is
 * marked resolved and disappears. Each run is written to the activity log
 * (REMINDER_CHECK).
 */

/**
 * type => label, icon, link (relative to BASE_URL), link text, whether it
 * goes to the bell, whether it's about one's own 201 file (personal),
 * summary sentence for Admin / Chair / Dean and the short "with ..." phrase
 * of the follow-up message.
 */
function reminder_types(): array {
    return [
        'no_seminar'   => ['label' => 'No recent seminar / training', 'icon' => 'fa-chalkboard-user',
                           'link' => 'faculty/submit_document.php', 'link_text' => 'Upload a certificate', 'notify' => true, 'personal' => true,
                           'summary' => 'not uploaded a seminar or training certificate in ' . (int)REMINDER_NO_SEMINAR_MONTHS . ' months',
                           'short' => 'no seminar / training certificate in ' . (int)REMINDER_NO_SEMINAR_MONTHS . ' months'],
        'missing_docs' => ['label' => 'Missing required documents', 'icon' => 'fa-folder-open',
                           'link' => 'faculty/submit_document.php', 'link_text' => 'Upload now', 'notify' => true, 'personal' => true,
                           'summary' => 'missing required 201-file documents', 'short' => 'missing required documents'],
        'pds'          => ['label' => 'PDS needs updating', 'icon' => 'fa-id-card',
                           'link' => 'faculty/pds.php', 'link_text' => 'Update my PDS', 'notify' => true, 'personal' => true,
                           'summary' => 'an incomplete or outdated PDS', 'short' => 'an incomplete or outdated PDS'],
        'expiring'     => ['label' => 'Expired / expiring documents', 'icon' => 'fa-hourglass-half',
                           'link' => 'faculty/submit_document.php', 'link_text' => 'Upload updated copy', 'notify' => false, 'personal' => true,
                           'summary' => 'expired documents or documents expiring within ' . (int)REMINDER_EXPIRING_DAYS . ' days',
                           'short' => 'expired / expiring documents'],
        'follow_up'    => ['label' => 'Faculty needing follow-up', 'icon' => 'fa-user-clock',
                           'link' => 'reminder_list.php', 'link_text' => 'View list', 'notify' => true, 'personal' => false],
    ];
}

/** The reminders about one's own 201 file -- all but the Admin / Chair / Dean follow-up. */
function personal_reminder_types(): array {
    return array_filter(reminder_types(), fn($meta) => $meta['personal']);
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

    // Required documents missing (the dashboard checklist, PDS aside). While employment is
    // paused there is no teaching load this semester, so no FTA / IPCR is expected.
    $paused = ($user['employment_status'] ?? null) === 'paused';
    $missing = [];
    foreach (faculty_201_checklist($pdo, $uid, $user['employment_type'] ?? null) as $item) {
        if ($item['done'] || $item['type'] === 'PDS' || ($paused && in_array($item['type'], ['FTA', 'IPCR'], true))) { continue; }
        $missing[] = reminder_checklist_label($item['label']);
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

/**
 * The follow-up condition of an Admin / Program Chair / Dean: the people in
 * their scope with open personal reminders now, by type. Null when no one
 * has any. The key changes only when a new kind of issue appears, not with
 * every count, so the bell isn't notified daily.
 * @return array{key: string, reason: string, facts: array, link: string}|null
 */
function reminder_follow_up_condition(PDO $pdo, array $viewer): ?array {
    $counts = array_filter(reminder_scope_counts($pdo, $viewer));
    if (!$counts) { return null; }
    [$scope, $params] = profile_scope_sql($pdo, $viewer);
    $stmt = $pdo->prepare(
        "SELECT COUNT(DISTINCT r.user_id) FROM ai_reminders r JOIN users u ON u.user_id = r.user_id
         WHERE r.resolved_at IS NULL AND r.reminder_type <> 'follow_up' AND u.is_active = 1 AND u.user_id <> ? AND $scope"
    );
    $stmt->execute([(int)$viewer['user_id'], ...$params]);
    $people = (int)$stmt->fetchColumn();

    $parts = [];
    foreach (personal_reminder_types() as $type => $meta) {
        if (!empty($counts[$type])) { $parts[] = $counts[$type] . ' with ' . $meta['short']; }
    }
    $where = ['program_chair' => ' in your program', 'dean' => ' in your college'][$viewer['role']] ?? '';
    $who = $people === 1 ? "1 faculty member{$where} needs" : "{$people} faculty{$where} need";
    return [
        'key'    => 'follow_up:' . implode('|', array_keys($counts)),
        'reason' => "{$who} follow-up on their 201 file" . ($people === 1 ? '' : 's') . ': ' . implode('; ', $parts) . '.',
        'facts'  => ['who' => $who, 'plural' => $people !== 1, 'parts' => $parts],
        'link'   => 'reminder_list.php?type=' . array_key_first(array_intersect_key(personal_reminder_types(), $counts)),
    ];
}

/** The fixed message used when AI is off or fails (always for the follow-up, whose counts change daily). */
function reminder_template_message(string $type, string $first_name, array $facts): string {
    $hi = "Hi, {$first_name}! ";
    switch ($type) {
        case 'follow_up':
            return $hi . $facts['who'] . ' to update their 201 file' . ($facts['plural'] ? 's' : '') . ' (' . implode('; ', $facts['parts']) . '). Open the list to follow up.';
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
 * template. After the first failure the rest use templates at once, and no
 * new request is started after REMINDER_AI_SECONDS (the check may be
 * running in someone's dashboard request). Follow-up reminders always use
 * the template.
 * $items: [ref => [type, first_name, facts]].
 * @return array<int|string, array{message: string, ai: bool}>
 */
function reminder_write_messages(PDO $pdo, array $items): array {
    $out = [];
    foreach ($items as $ref => $it) {
        $out[$ref] = ['message' => reminder_template_message($it['type'], $it['first_name'], $it['facts']), 'ai' => false];
    }
    $items = array_filter($items, fn($it) => reminder_types()[$it['type']]['personal']);
    if (!$items || !ai_enabled()) { return $out; }
    $deadline = microtime(true) + REMINDER_AI_SECONDS;

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
        if (microtime(true) > $deadline) { break; }   // the rest keep their templates
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
 * The reminder check: work out each account's conditions, resolve
 * reminders whose condition cleared, and send the new / repeated / changed
 * ones. Every active account, whatever its role, uploads or profile
 * details: first the personal reminders of everyone with a 201 file (all
 * but the Admin), then the follow-up of the Admin, Program Chairs and
 * Deans, so it counts today's personal ones. An account whose check fails
 * is logged and left out -- the others still get theirs -- and is not
 * marked as checked (ai_reminder_checks), so its next dashboard visit
 * tries again. $only_user: just that account (ensure_reminders_checked()).
 * @return array{checked: int, sent: int, updated: int, resolved: int, ai_written: int, failed: int[]}
 */
function check_reminders(PDO $pdo, ?int $only_user = null): array {
    $one = $only_user !== null ? [$only_user] : [];
    $stmt = $pdo->prepare(
        "SELECT user_id, full_name, role, employment_type, employment_status FROM users
         WHERE is_active = 1" . ($one ? ' AND user_id = ?' : '') . " ORDER BY user_id"
    );
    $stmt->execute($one);
    $users = $stmt->fetchAll();

    // Looked up for everyone at once. Someone with no seminar / training certificate -- or no
    // uploads at all -- isn't in $last_seminar: that's "never", which gets the reminder too.
    $stmt = $pdo->prepare(
        "SELECT faculty_id, MAX(filed_at) FROM documents
         WHERE document_type = 'Certificate' AND status IN ('active', 'archived')
           AND (document_subtype IN ('Seminar', 'Training') OR training_type IS NOT NULL)" . ($one ? ' AND faculty_id = ?' : '') . "
         GROUP BY faculty_id"
    );
    $stmt->execute($one);
    $last_seminar = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $expiring = [];
    foreach (expiring_documents($pdo, $only_user, (int)REMINDER_EXPIRING_DAYS) as $d) { $expiring[(int)$d['faculty_id']][] = $d; }

    // Open reminders, one per account and type (a duplicate from two checks at once is resolved)
    $resolve = $pdo->prepare("UPDATE ai_reminders SET resolved_at = NOW() WHERE id = ? AND resolved_at IS NULL");
    $stmt = $pdo->prepare(
        "SELECT id, user_id, reminder_type, condition_key, reason, created_at FROM ai_reminders
         WHERE resolved_at IS NULL" . ($one ? ' AND user_id = ?' : '') . " ORDER BY id"
    );
    $stmt->execute($one);
    $open = [];
    foreach ($stmt->fetchAll() as $r) {
        if (isset($open[(int)$r['user_id']][$r['reminder_type']])) { $resolve->execute([$open[(int)$r['user_id']][$r['reminder_type']]['id']]); }
        $open[(int)$r['user_id']][$r['reminder_type']] = $r;
    }

    $run = ['checked' => count($users), 'sent' => 0, 'updated' => 0, 'resolved' => 0, 'ai_written' => 0];
    $failed = [];

    // 1. Personal reminders: everyone with a 201 file
    $new = [];
    foreach ($users as $u) {
        $uid = (int)$u['user_id'];
        try {
            $conditions = $u['role'] === 'admin' ? []
                : reminder_conditions($pdo, $u, ['last_seminar' => $last_seminar[$uid] ?? null, 'expiring' => $expiring[$uid] ?? []]);
            $new += reminder_changes($pdo, $u, $conditions, array_keys(personal_reminder_types()), $open[$uid] ?? [], $run);
        } catch (Throwable $e) {
            error_log("Reminder check of account {$uid} failed: " . $e->getMessage());
            $failed[$uid] = true;
        }
    }
    reminder_send($pdo, $new, $run, $failed);

    // 2. Follow-up: the Admin, Program Chairs and Deans, over the people in their scope
    $new = [];
    foreach ($users as $u) {
        $uid = (int)$u['user_id'];
        if (isset($failed[$uid])) { continue; }
        try {
            $c = in_array($u['role'], ['admin', 'program_chair', 'dean'], true) ? reminder_follow_up_condition($pdo, $u) : null;
            $new += reminder_changes($pdo, $u, $c ? ['follow_up' => $c] : [], ['follow_up'], $open[$uid] ?? [], $run);
        } catch (Throwable $e) {
            error_log("Follow-up reminder check of account {$uid} failed: " . $e->getMessage());
            $failed[$uid] = true;
        }
    }
    reminder_send($pdo, $new, $run, $failed);

    // Done for today: the accounts checked without errors
    $mark = $pdo->prepare(
        "INSERT INTO ai_reminder_checks (user_id, checked_at, started_at) VALUES (?, ?, NULL)
         ON DUPLICATE KEY UPDATE checked_at = VALUES(checked_at), started_at = NULL"
    );
    $now = date('Y-m-d H:i:s');
    foreach ($users as $u) {
        if (!isset($failed[(int)$u['user_id']])) { $mark->execute([(int)$u['user_id'], $now]); }
    }
    return $run + ['failed' => array_keys($failed)];
}

/**
 * For one account and the given reminder types: resolve the open
 * reminders whose condition cleared, and return the ones to send -- new,
 * with a changed reason, or due again after REMINDER_REPEAT_DAYS (also
 * when the person dismissed it). A follow-up already sent for the same
 * kinds of issue gets its counts refreshed in place, without a new
 * notification. $open: the account's open reminders by type.
 */
function reminder_changes(PDO $pdo, array $u, array $conditions, array $types, array $open, array &$run): array {
    $repeat_after = (new DateTimeImmutable())->modify('-' . (int)REMINDER_REPEAT_DAYS . ' days')->format('Y-m-d H:i:s');
    $new = [];
    foreach ($types as $type) {
        $current = $open[$type] ?? null;
        if (!isset($conditions[$type])) {
            if ($current) {
                $pdo->prepare("UPDATE ai_reminders SET resolved_at = NOW() WHERE id = ? AND resolved_at IS NULL")->execute([$current['id']]);
                $run['resolved']++;
            }
            continue;
        }
        $c = $conditions[$type];
        $key = mb_strlen($c['key']) > 190 ? $type . ':' . sha1($c['key']) : $c['key'];
        $reason = mb_substr($c['reason'], 0, 500);
        $link = $c['link'] ?? reminder_types()[$type]['link'];
        if ($current && $current['condition_key'] === $key && $current['created_at'] > $repeat_after) {
            // Already reminded about exactly this, recently
            if ($type === 'follow_up' && $current['reason'] !== $reason) {
                $message = reminder_template_message($type, ai_first_name($u['full_name']), $c['facts']);
                $pdo->prepare("UPDATE ai_reminders SET reason = ?, message = ?, link = ? WHERE id = ?")
                    ->execute([$reason, mb_substr($message, 0, 500), $link, $current['id']]);
                $run['updated']++;
            }
            continue;
        }
        $new[$u['user_id'] . ':' . $type] = ['user' => $u, 'type' => $type, 'key' => $key, 'reason' => $reason, 'link' => $link,
                                             'first_name' => ai_first_name($u['full_name']), 'facts' => $c['facts'],
                                             'replaces' => $current['id'] ?? null];
    }
    return $new;
}

/**
 * Send what reminder_changes() returned: word the messages, notify the bell
 * (for types that do) and store each reminder, replacing the older one of
 * its type. A reminder that can't be stored marks its account as failed.
 */
function reminder_send(PDO $pdo, array $new, array &$run, array &$failed): void {
    if (!$new) { return; }
    $messages = reminder_write_messages($pdo, $new);
    $resolve = $pdo->prepare("UPDATE ai_reminders SET resolved_at = NOW() WHERE id = ? AND resolved_at IS NULL");
    $insert = $pdo->prepare(
        "INSERT INTO ai_reminders (user_id, reminder_type, condition_key, reason, message, link, ai_generated, notification_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    );
    foreach ($new as $ref => $n) {
        $uid = (int)$n['user']['user_id'];
        $msg = mb_substr($messages[$ref]['message'], 0, 500);
        try {
            $pdo->beginTransaction();
            if ($n['replaces']) { $resolve->execute([$n['replaces']]); }
            $notification_id = null;
            if (reminder_types()[$n['type']]['notify']) {
                notify($pdo, $uid, $msg, null, $n['link']);
                $notification_id = (int)$pdo->lastInsertId();
            }
            $insert->execute([$uid, $n['type'], $n['key'], $n['reason'], $msg, $n['link'], $messages[$ref]['ai'] ? 1 : 0, $notification_id]);
            $pdo->commit();
            $run['sent']++;
            if ($messages[$ref]['ai']) { $run['ai_written']++; }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            error_log("Reminder {$ref} failed: " . $e->getMessage());
            $failed[$uid] = true;
        }
    }
}

/**
 * check_reminders() and its activity-log entry (REMINDER_CHECK: accounts
 * checked, reminders sent, errors) -- also when the whole check fails.
 * $trigger: what started it. $actor: the Admin who pressed "Run reminder
 * check now"; otherwise it is logged as the system.
 */
function run_reminder_check(PDO $pdo, string $trigger, ?int $only_user = null, ?array $actor = null): array {
    $log = fn(string $details) => log_activity($pdo, $actor ? (int)$actor['user_id'] : null, 'REMINDER_CHECK', $details, $actor['role'] ?? 'system');
    try {
        $r = check_reminders($pdo, $only_user);
    } catch (Throwable $e) {
        $log("Reminder check ({$trigger}) failed: " . mb_substr($e->getMessage(), 0, 200) . ' -- it is tried again on the next dashboard visit.');
        throw $e;
    }
    $n = fn(int $count, string $word) => $count . ' ' . $word . ($count === 1 ? '' : 's');
    $log("Reminder check ({$trigger}): " . $n($r['checked'], 'account') . ' checked, ' . $n($r['sent'], 'reminder') . ' sent'
        . ($r['sent'] ? " ({$r['ai_written']} worded by AI, the rest from the template)" : '')
        . ', ' . $n($r['updated'], 'follow-up count') . ' updated, ' . $r['resolved'] . ' resolved, '
        . ($r['failed'] ? $n(count($r['failed']), 'error') . ' (account #' . implode(', #', $r['failed'])
                          . ' -- see the PHP error log; retried on their next dashboard visit)' : 'no errors') . '.');
    return $r;
}

/**
 * The check of every account, at most once a day (run_once_a_day()).
 * $force: cron/reminder_check.php and the Admin's "Run reminder check now".
 */
function run_daily_reminder_check(PDO $pdo, bool $force = false, string $trigger = 'first dashboard visit of the day', ?array $actor = null): ?array {
    return run_once_a_day($pdo, 'reminder_check_last_run', fn(PDO $pdo) => run_reminder_check($pdo, $trigger, null, $actor), $force);
}

/**
 * Check one account now unless it was already checked today: on its
 * dashboard, for an account the day's run didn't cover (created after it,
 * or its check failed). Claims the account first (started_at), so two page
 * loads don't both send; skipped while the day's run is still going.
 * Never throws.
 */
function ensure_reminders_checked(PDO $pdo, int $user_id): void {
    try {
        $stale = time() - 60 * DAILY_JOB_STALE_MINUTES;
        $daily = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'reminder_check_last_run'")->fetchColumn();
        if (is_string($daily) && $daily > 'running:' . date('Y-m-d H:i:s', $stale)) {
            return;   // the day's run is checking everyone right now
        }
        $claim = $pdo->prepare(
            "INSERT INTO ai_reminder_checks (user_id, started_at) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE started_at = IF((checked_at IS NULL OR checked_at < ?) AND (started_at IS NULL OR started_at < ?),
                                                     VALUES(started_at), started_at)"
        );
        $claim->execute([$user_id, date('Y-m-d H:i:s'), date('Y-m-d 00:00:00'), date('Y-m-d H:i:s', $stale)]);
        if ($claim->rowCount() === 0) {
            return;   // checked today, or being checked by another page load
        }
        run_reminder_check($pdo, "account #{$user_id} not yet checked today, on its dashboard", $user_id);
    } catch (Throwable $e) {
        error_log("Reminder check of account {$user_id} failed: " . $e->getMessage());
    }
}

/**
 * For the dashboard Reminders card when nothing is shown: when this
 * account was last checked (null: not yet -- so "You're all set" can't be
 * claimed) and how many open reminders the person dismissed.
 * @return array{checked_at: ?string, dismissed: int}
 */
function reminder_check_status(PDO $pdo, int $user_id): array {
    try {
        $stmt = $pdo->prepare(
            "SELECT (SELECT checked_at FROM ai_reminder_checks WHERE user_id = ?),
                    (SELECT COUNT(*) FROM ai_reminders WHERE user_id = ? AND resolved_at IS NULL AND dismissed_at IS NOT NULL)"
        );
        $stmt->execute([$user_id, $user_id]);
        [$checked_at, $dismissed] = $stmt->fetch(PDO::FETCH_NUM);
        return ['checked_at' => $checked_at, 'dismissed' => (int)$dismissed];
    } catch (PDOException $e) {
        return ['checked_at' => null, 'dismissed' => 0];   // migration not applied yet
    }
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
 * personal reminder of each type. Dismissing doesn't remove someone -- only fixing it does.
 * @return array<string, int> type => people
 */
function reminder_scope_counts(PDO $pdo, array $viewer): array {
    try {
        [$scope, $params] = profile_scope_sql($pdo, $viewer);
        $stmt = $pdo->prepare(
            "SELECT r.reminder_type, COUNT(DISTINCT r.user_id) FROM ai_reminders r JOIN users u ON u.user_id = r.user_id
             WHERE r.resolved_at IS NULL AND r.reminder_type <> 'follow_up' AND u.is_active = 1 AND u.user_id <> ? AND $scope
             GROUP BY r.reminder_type"
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
