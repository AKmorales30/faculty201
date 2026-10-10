<?php
/**
 * AI summary of the Seminar & Training Report (training_report.php):
 * Admin, Program Chairs and Deans, within their scope (profile_scope_sql()).
 *
 * PHP works out every number from the report's own filtered rows plus SQL
 * (faculty counts, seminars and hours per faculty, top organizers, faculty
 * with no training in the period, missing required documents, expired /
 * expiring documents, archived counts). Only that summary data -- names,
 * counts, categories, dates, titles, organizers; no ID numbers, contact
 * details or files -- goes to Gemini, which writes the overview, key
 * findings, faculty needing attention and recommendations as JSON.
 * Each summary is saved in ai_reports and shown again for the same filters
 * until Regenerate is pressed, so Gemini isn't called on every page load.
 */

/** Hash that finds the saved summary for the same viewer and filters. */
function ai_report_hash(int $viewer_id, array $filter_query): string {
    ksort($filter_query);
    return hash('sha256', json_encode(['type' => 'training', 'viewer' => $viewer_id, 'filters' => array_map('strval', $filter_query)]));
}

/**
 * The summary data sent to Gemini.
 * $faculty   users rows (user_id => row) the report covers, already scoped and filtered
 * $rows      the report's seminar / training rows (same filters as on screen)
 * $applied   the filters in words
 */
function ai_report_data(PDO $pdo, array $faculty, array $rows, array $applied, string $from, string $to, bool $include_archived): array {
    $full = !empty(ai_config()['report_send_full_names']);
    $name_of = fn(array $u): string => $full ? $u['full_name'] : ai_short_name($u['full_name']);
    $ids = array_map('intval', array_keys($faculty));

    // Seminars / trainings per faculty, organizers, types, years -- from the report rows
    $per = array_fill_keys($ids, ['count' => 0, 'hours' => 0.0, 'last' => null]);
    $orgs = $types = $levels = $years = [];
    $org_names = [];
    $total_hours = 0.0;
    $missing_details = 0;
    foreach ($rows as $r) {
        $fid = (int)$r['faculty_id'];
        if (!isset($per[$fid])) { continue; }
        $per[$fid]['count']++;
        $per[$fid]['hours'] += (float)$r['hours'];
        $total_hours += (float)$r['hours'];
        $date = $r['date_end'] ?: $r['date_start'];
        if ($date && ($per[$fid]['last'] === null || $date > $per[$fid]['last'])) { $per[$fid]['last'] = $date; }
        $org_key = $r['conducted_by'] !== null && trim($r['conducted_by']) !== '' ? mb_strtolower(trim($r['conducted_by'])) : '';
        $org_names[$org_key] ??= $org_key === '' ? 'Not specified' : trim($r['conducted_by']);
        $orgs[$org_key] = ($orgs[$org_key] ?? 0) + 1;
        $types[$r['training_type'] ?: 'Not specified'] = ($types[$r['training_type'] ?: 'Not specified'] ?? 0) + 1;
        $levels[$r['training_level'] ?: 'Not specified'] = ($levels[$r['training_level'] ?: 'Not specified'] ?? 0) + 1;
        $y = $r['date_start'] ? substr($r['date_start'], 0, 4) : 'Not specified';
        $years[$y] = ($years[$y] ?? 0) + 1;
        if (trim((string)$r['title']) === '' || !$r['date_start'] || trim((string)$r['conducted_by']) === '') { $missing_details++; }
    }
    arsort($orgs);
    krsort($years);

    // Expired / expiring (60 days) and archived documents of these faculty
    $expiring = array_fill_keys($ids, ['expired' => 0, 'soon' => 0]);
    foreach (expiring_documents($pdo) as $d) {
        $fid = (int)$d['faculty_id'];
        if (isset($expiring[$fid])) { $expiring[$fid][$d['days_left'] < 0 ? 'expired' : 'soon']++; }
    }
    $archived = [];
    $archived_certs = 0;
    if ($ids) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT faculty_id, COUNT(*) AS n, SUM(document_type = 'Certificate') AS certs FROM documents
                               WHERE status = 'archived' AND faculty_id IN ($in) GROUP BY faculty_id");
        $stmt->execute($ids);
        foreach ($stmt->fetchAll() as $a) { $archived[(int)$a['faculty_id']] = (int)$a['n']; $archived_certs += (int)$a['certs']; }
    }

    // One entry per faculty member (missing documents: the dashboard checklist)
    $people = [];
    $without = [];
    $max_people = 80;
    foreach (array_slice($faculty, 0, $max_people, true) as $fid => $u) {
        $missing = [];
        foreach (faculty_201_checklist($pdo, (int)$fid, $u['employment_type'] ?? null) as $item) {
            if (!$item['done']) { $missing[] = reminder_checklist_label($item['label']); }
        }
        $p = $per[$fid];
        $people[] = [
            'name'                         => $name_of($u),
            'position'                     => user_position_label($u) . (isset(PROGRAMS[$u['program'] ?? '']) ? ', ' . $u['program'] : ''),
            'seminars_trainings_in_period' => $p['count'],
            'training_hours_in_period'     => round($p['hours'], 1),
            'latest_training_date'         => $p['last'],
            'missing_required_documents'   => $missing,
            'expired_documents'            => $expiring[$fid]['expired'],
            'documents_expiring_within_60_days' => $expiring[$fid]['soon'],
            'archived_documents'           => $archived[(int)$fid] ?? 0,
        ];
        if ($p['count'] === 0) { $without[] = $name_of($u); }
    }

    $top_orgs = [];
    foreach (array_slice($orgs, 0, 10, true) as $k => $n) { $top_orgs[] = ['organizer' => $org_names[$k], 'seminars_trainings' => $n]; }

    return ai_private_filter([
        'report'               => 'Faculty Seminar & Training Report, College of Computing Studies, Universidad de Manila',
        'generated_on'         => date('F j, Y'),
        'filters'              => $applied,
        'period'               => ($from !== '' || $to !== '')
                                  ? ($from !== '' ? date('F j, Y', strtotime($from)) : 'earliest record') . ' to ' . ($to !== '' ? date('F j, Y', strtotime($to)) : 'today')
                                  : 'all dates on record',
        'archived_seminars_included' => $include_archived,
        'totals' => [
            'faculty_covered'               => count($faculty),
            'faculty_with_trainings'        => count($faculty) - count(array_filter($per, fn($p) => $p['count'] === 0)),
            'faculty_without_trainings'     => count(array_filter($per, fn($p) => $p['count'] === 0)),
            'seminars_trainings'            => count($rows),
            'total_training_hours_recorded' => round($total_hours, 1),
            'entries_missing_title_date_or_organizer' => $missing_details,
            'faculty_missing_required_documents' => count(array_filter($people, fn($p) => $p['missing_required_documents'])),
            'expired_documents'             => array_sum(array_column($expiring, 'expired')),
            'documents_expiring_within_60_days' => array_sum(array_column($expiring, 'soon')),
            'archived_documents'            => array_sum($archived),
            'archived_certificates'         => $archived_certs,
        ],
        'top_organizers'       => $top_orgs,
        'by_type'              => $types,
        'by_level'             => $levels,
        'by_year'              => $years,
        'faculty_without_trainings_in_period' => $without,
        'faculty'              => $people,
        'faculty_list_truncated' => count($faculty) > $max_people,
    ]);
}

/** Gemini's JSON answer format. */
function ai_report_schema(): array {
    $strings = ['type' => 'array', 'items' => ['type' => 'string']];
    return ['type' => 'object', 'properties' => [
        'overview'                  => ['type' => 'string'],
        'key_findings'              => $strings,
        'faculty_needing_attention' => ['type' => 'array', 'items' => ['type' => 'object',
                                         'properties' => ['name' => ['type' => 'string'], 'reason' => ['type' => 'string']], 'required' => ['name', 'reason']]],
        'recommendations'           => $strings,
    ], 'required' => ['overview', 'key_findings', 'faculty_needing_attention', 'recommendations']];
}

/**
 * Ask Gemini for the summary. Returns the cleaned summary array, or null
 * (logged in ai_usage_log either way). Names Gemini lists that aren't in
 * the data are dropped -- it may not invent people.
 */
function ai_report_generate(PDO $pdo, int $user_id, array $data): ?array {
    $system = "You write formal faculty development reports for a university (College of Computing Studies, Universidad de Manila).\n"
            . "Use ONLY the data provided. Do not invent names, numbers, organizers or events. If data is missing or zero, say so plainly.\n"
            . "Write in formal, clear English for university administrators.\n"
            . "overview: one paragraph (3-5 sentences) covering the period, faculty covered, seminars/trainings, hours and main organizers.\n"
            . "key_findings: 3-6 short bullet sentences with specific numbers from the data.\n"
            . "faculty_needing_attention: faculty from the data with no trainings in the period, missing required documents, or expired documents; "
            . "use the names exactly as given, with a short reason. Empty list if none.\n"
            . "recommendations: 2-5 practical, specific recommendations based on the findings.\n"
            . "Do not use markdown.";
    $answer = ai_service()->generate($system, "Report data (JSON):\n" . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        ['json_schema' => ai_report_schema(), 'temperature' => 0.3]);
    $ok = is_array($answer) && trim((string)($answer['overview'] ?? '')) !== '';
    ai_log_usage($pdo, $user_id, 'report', $ok, $ok ? null : (ai_service()->lastError() ?? 'empty: no overview'));
    if (!$ok) { return null; }

    $known = array_map('mb_strtolower', array_column($data['faculty'] ?? [], 'name'));
    $text = fn($v): string => trim(mb_substr((string)$v, 0, 2000));
    $list = fn($v): array => array_values(array_filter(array_map($text, is_array($v) ? array_slice($v, 0, 10) : [])));
    return [
        'overview'        => $text($answer['overview']),
        'key_findings'    => $list($answer['key_findings'] ?? []),
        'faculty_needing_attention' => array_values(array_filter(array_map(
            fn($f) => ['name' => $text($f['name'] ?? ''), 'reason' => $text($f['reason'] ?? '')],
            is_array($answer['faculty_needing_attention'] ?? null) ? array_slice($answer['faculty_needing_attention'], 0, 40) : []
        ), fn($f) => $f['name'] !== '' && in_array(mb_strtolower($f['name']), $known, true))),
        'recommendations' => $list($answer['recommendations'] ?? []),
    ];
}

function ai_report_save(PDO $pdo, int $user_id, array $filters, string $hash, array $summary): void {
    $pdo->prepare("INSERT INTO ai_reports (generated_by, report_type, filters, filters_hash, summary_text) VALUES (?, 'training', ?, ?, ?)")
        ->execute([$user_id, json_encode($filters, JSON_UNESCAPED_UNICODE), $hash, json_encode($summary, JSON_UNESCAPED_UNICODE)]);
}

/** The latest saved summary for this viewer and filters: ['summary' => array, 'created_at' => string], or null. */
function ai_report_latest(PDO $pdo, string $hash): ?array {
    try {
        $stmt = $pdo->prepare("SELECT summary_text, created_at FROM ai_reports WHERE filters_hash = ? ORDER BY created_at DESC, id DESC LIMIT 1");
        $stmt->execute([$hash]);
        $row = $stmt->fetch();
    } catch (PDOException $e) {
        return null;   // migration not applied yet
    }
    $summary = $row ? json_decode($row['summary_text'], true) : null;
    return is_array($summary) ? ['summary' => $summary, 'created_at' => $row['created_at']] : null;
}

/** The saved summary as HTML -- every piece of AI text escaped. */
function ai_report_html(array $s): string {
    $ul = function (array $items): string {
        return $items ? '<ul class="mb-0 ps-3">' . implode('', array_map(fn($i) => '<li>' . h($i) . '</li>', $items)) . '</ul>'
                      : '<p class="text-muted mb-0">None.</p>';
    };
    $html = '<p class="mb-3">' . nl2br(h($s['overview'] ?? '')) . '</p>';
    $html .= '<h6 class="fw-bold ai-summary-heading">Key Findings</h6><div class="mb-3">' . $ul($s['key_findings'] ?? []) . '</div>';
    $attention = array_map(fn($f) => $f['name'] . ' — ' . $f['reason'], $s['faculty_needing_attention'] ?? []);
    $html .= '<h6 class="fw-bold ai-summary-heading">Faculty Needing Attention</h6><div class="mb-3">' . $ul($attention) . '</div>';
    $html .= '<h6 class="fw-bold ai-summary-heading">Recommendations</h6><div>' . $ul($s['recommendations'] ?? []) . '</div>';
    return $html;
}
