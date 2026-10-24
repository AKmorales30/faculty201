<?php
/**
 * Program Chair / Dean Analytics (approval/analytics.php, data from
 * api/analytics.php). Every number is worked out here, on the server, for
 * the viewer's scope only -- analytics_scope(), built on profile_scope_sql():
 *   Program Chair  the faculty of their program
 *   Dean           the faculty and Program Chairs of their college
 * (With one program -- PROGRAMS in config.php -- both see the same faculty.)
 * The viewer themselves isn't counted. Only active accounts, and only active
 * documents (201-Files: not archived, deleted or still for review).
 *
 * PDS numbers count only accounts that keep a PDS (faculty_has_pds() /
 * sql_has_pds(): no part-time faculty). Dates are Asia/Manila (PHP and the
 * database session, config/db.php). All values are bound parameters.
 */

const ANALYTICS_MAX_DAYS = 3660;   // the longest date range (about 10 years)

/**
 * The accounts the viewer's analytics cover, as an SQL condition on users
 * alias u and its parameters.
 * @return array{0: string, 1: array}
 */
function analytics_scope(PDO $pdo, array $viewer): array {
    [$scope, $params] = profile_scope_sql($pdo, $viewer, 'u');
    $where = "$scope AND u.user_id <> ? AND u.is_active = 1 AND u.deleted_at IS NULL AND u.role IN ('faculty', 'program_chair')";
    $params[] = (int)$viewer['user_id'];
    return [$where, $params];
}

/** The scope's name for headings: a Program Chair's program, a Dean's college. */
function analytics_scope_label(PDO $pdo, array $viewer): string {
    $self = user_row($pdo, (int)$viewer['user_id']);
    if ($viewer['role'] === 'dean') {
        return COLLEGES[$self['college'] ?? ''] ?? 'Your college';
    }
    return PROGRAMS[$self['program'] ?? '']['label'] ?? 'Your program';
}

/**
 * A valid date range from the request: Y-m-d strings, from <= to, at most
 * ANALYTICS_MAX_DAYS. Default: the last 12 months (from the 1st of the month
 * 11 months back, to today).
 * @return array{0: string, 1: string}
 */
function analytics_range(?string $from, ?string $to): array {
    $valid = fn(?string $d): ?DateTimeImmutable => ($d !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)
        && ($dt = DateTimeImmutable::createFromFormat('!Y-m-d', $d)) && $dt->format('Y-m-d') === $d) ? $dt : null;
    $today = new DateTimeImmutable('today');
    $end = $valid($to) ?? $today;
    $start = $valid($from) ?? $end->modify('first day of this month')->modify('-11 months');
    if ($start > $end) { [$start, $end] = [$end, $start]; }
    if ($end->diff($start)->days > ANALYTICS_MAX_DAYS) { $start = $end->modify('-' . ANALYTICS_MAX_DAYS . ' days'); }
    return [$start->format('Y-m-d'), $end->format('Y-m-d')];
}

/** One integer from a query. */
function analytics_int(PDO $pdo, string $sql, array $params): int {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

/** key => int pairs from a two-column query. */
function analytics_pairs(PDO $pdo, string $sql, array $params): array {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_KEY_PAIR));
}

/** "A seminar or training" certificate (the same test as the no-recent-seminar reminder). */
const ANALYTICS_TRAINING_SQL = "d.document_type = 'Certificate' AND (d.document_subtype IN ('Seminar', 'Training') OR d.training_type IS NOT NULL)";
/** The day a seminar / training took place: its start date, or the upload date when none was read. */
const ANALYTICS_TRAINING_DAY = "COALESCE(d.date_start, CAST(d.filed_at AS DATE))";

/** Months from $from to $to as 'Y-m' => 'M Y' (label). */
function analytics_months(string $from, string $to): array {
    $out = [];
    $m = (new DateTimeImmutable($from))->modify('first day of this month');
    $last = (new DateTimeImmutable($to))->modify('first day of this month');
    while ($m <= $last) {
        $out[$m->format('Y-m')] = $m->format('M Y');
        $m = $m->modify('+1 month');
    }
    return $out;
}

/**
 * Everything the Analytics page shows, for the viewer's scope and the range.
 * Numbers are ints; series are parallel 'labels' / 'values' arrays.
 */
function analytics_data(PDO $pdo, array $viewer, string $from, string $to): array {
    [$scope, $sp] = analytics_scope($pdo, $viewer);
    $users = "FROM users u WHERE $scope";
    $docs = "FROM documents d JOIN users u ON u.user_id = d.faculty_id WHERE d.status = 'active' AND $scope";
    $range_end = (new DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d');   // filed_at is a timestamp: < the next day
    $today = date('Y-m-d');
    $soon = (new DateTimeImmutable('today'))->modify('+30 days')->format('Y-m-d');

    // ----- summary cards ------------------------------------------------------
    $faculty = analytics_int($pdo, "SELECT COUNT(*) $users", $sp);
    $with_pds = analytics_int($pdo, "SELECT COUNT(*) $users AND " . sql_has_pds('u'), $sp);
    $documents = analytics_int($pdo, "SELECT COUNT(*) $docs", $sp);
    $trainings = analytics_int($pdo, "SELECT COUNT(*) $docs AND " . ANALYTICS_TRAINING_SQL . " AND " . ANALYTICS_TRAINING_DAY . " BETWEEN ? AND ?",
                               [...$sp, $from, $to]);
    $expiry = analytics_pairs($pdo,
        "SELECT CASE WHEN d.expiration_date < ? THEN 'expired' WHEN d.expiration_date <= ? THEN 'expiring' ELSE 'valid' END AS bucket, COUNT(*)
         $docs AND d.expiration_date IS NOT NULL GROUP BY bucket", [$today, $soon, ...$sp]);

    // ----- documents uploaded per month (in the range) ---------------------------
    $per_month = analytics_pairs($pdo,
        "SELECT " . sql_year_month('d.filed_at') . " AS ym, COUNT(*) $docs AND d.filed_at >= ? AND d.filed_at < ? GROUP BY ym",
        [...$sp, $from, $range_end]);
    $months = analytics_months($from, $to);
    $uploads = ['labels' => array_values($months), 'values' => array_map(fn($ym) => $per_month[$ym] ?? 0, array_keys($months))];

    // ----- documents per category (201-Files now) ------------------------------
    $by_type = analytics_pairs($pdo, "SELECT d.document_type, COUNT(*) $docs GROUP BY d.document_type", $sp);
    $categories = ['labels' => [], 'values' => []];
    foreach (document_categories() as $key => $cat) {
        if (!empty($by_type[$key])) { $categories['labels'][] = $cat['short']; $categories['values'][] = $by_type[$key]; }
    }

    // ----- seminars / trainings per month, or per year over 2 years ----------------
    $t_month = analytics_pairs($pdo,
        "SELECT " . sql_year_month(ANALYTICS_TRAINING_DAY) . " AS ym, COUNT(*) $docs AND " . ANALYTICS_TRAINING_SQL
        . " AND " . ANALYTICS_TRAINING_DAY . " BETWEEN ? AND ? GROUP BY ym", [...$sp, $from, $to]);
    if (count($months) > 24) {
        $years = [];
        foreach (array_keys($months) as $ym) { $years[substr($ym, 0, 4)] = ($years[substr($ym, 0, 4)] ?? 0) + ($t_month[$ym] ?? 0); }
        $training_series = ['labels' => array_map('strval', array_keys($years)), 'values' => array_values($years), 'per' => 'year'];
    } else {
        $training_series = ['labels' => array_values($months), 'values' => array_map(fn($ym) => $t_month[$ym] ?? 0, array_keys($months)), 'per' => 'month'];
    }

    // ----- top organizers (in the range) -----------------------------------------
    $stmt = $pdo->prepare(
        "SELECT LOWER(TRIM(d.conducted_by)) AS k, MIN(TRIM(d.conducted_by)) AS name, COUNT(*) AS n
         $docs AND " . ANALYTICS_TRAINING_SQL . " AND d.conducted_by IS NOT NULL AND TRIM(d.conducted_by) <> ''
           AND " . ANALYTICS_TRAINING_DAY . " BETWEEN ? AND ?
         GROUP BY LOWER(TRIM(d.conducted_by)) ORDER BY n DESC, name LIMIT 8");
    $stmt->execute([...$sp, $from, $to]);
    $organizers = ['labels' => [], 'values' => []];
    foreach ($stmt->fetchAll() as $r) { $organizers['labels'][] = $r['name']; $organizers['values'][] = (int)$r['n']; }

    // ----- faculty by rank and by employment type (now) ----------------------------
    // Employment group: Full-time / Part-time faculty, Program Chair, or Not set
    $group_sql = "CASE WHEN u.role = 'program_chair' THEN 'Program Chair' WHEN u.employment_type = 'full_time' THEN 'Full-time'
                       WHEN u.employment_type = 'part_time' THEN 'Part-time' ELSE 'Not set' END";
    $stmt = $pdo->prepare("SELECT u.academic_rank, $group_sql AS grp $users");
    $stmt->execute($sp);
    $ranks = $employment = [];
    foreach ($stmt->fetchAll() as $u) {
        $rank = trim((string)$u['academic_rank']);
        $rank = ($rank === '' || $rank === 'N/A') ? 'Not set' : $rank;
        $ranks[$rank] = ($ranks[$rank] ?? 0) + 1;
        $employment[$u['grp']] = ($employment[$u['grp']] ?? 0) + 1;
    }
    arsort($ranks);
    arsort($employment);

    // ----- per employment type: faculty, documents now, seminars / trainings in the range ----
    $doc_n = analytics_pairs($pdo, "SELECT $group_sql AS grp, COUNT(*) $docs GROUP BY grp", $sp);
    $tr_n = analytics_pairs($pdo, "SELECT $group_sql AS grp, COUNT(*) $docs AND " . ANALYTICS_TRAINING_SQL . " AND " . ANALYTICS_TRAINING_DAY
                                 . " BETWEEN ? AND ? GROUP BY grp", [...$sp, $from, $to]);
    $per_employment = ['labels' => [], 'documents' => [], 'trainings' => [], 'faculty' => []];
    foreach (['Full-time', 'Part-time', 'Program Chair', 'Not set'] as $grp) {
        if (empty($employment[$grp]) && empty($doc_n[$grp])) { continue; }
        $per_employment['labels'][] = $grp;
        $per_employment['documents'][] = $doc_n[$grp] ?? 0;
        $per_employment['trainings'][] = $tr_n[$grp] ?? 0;
        $per_employment['faculty'][] = $employment[$grp] ?? 0;
    }

    // ----- faculty needing attention (the daily reminder check's open reminders) ----------
    $attention = analytics_attention($pdo, $scope, $sp);

    return [
        'scope'   => ['label' => analytics_scope_label($pdo, $viewer), 'from' => $from, 'to' => $to],
        'cards'   => [
            'faculty'        => $faculty,
            'documents'      => $documents,
            'trainings'      => $trainings,
            'expiring'       => ($expiry['expired'] ?? 0) + ($expiry['expiring'] ?? 0),
            'expired'        => $expiry['expired'] ?? 0,
            'pds_incomplete' => $attention['pds']['count'],
            'pds_total'      => $with_pds,
        ],
        'uploads'     => $uploads,
        'categories'  => $categories,
        'trainings'   => $training_series,
        'organizers'  => $organizers,
        'expiration'  => ['labels' => ['Valid', 'Expiring within 30 days', 'Expired'],
                          'values' => [$expiry['valid'] ?? 0, $expiry['expiring'] ?? 0, $expiry['expired'] ?? 0]],
        'ranks'       => ['labels' => array_map('strval', array_keys($ranks)), 'values' => array_values($ranks)],
        'employment'  => ['labels' => array_keys($employment), 'values' => array_values($employment)],
        'per_employment' => $per_employment,
        'attention'   => array_values($attention),
    ];
}

/**
 * Faculty needing attention: open reminders of the daily check, per kind,
 * in the scope. PDS only for accounts that keep one (sql_has_pds()). Each
 * kind opens its existing one-category pop-up (includes/modals/list.php).
 */
function analytics_attention(PDO $pdo, string $scope, array $sp): array {
    $kinds = [
        'pds'        => ['label' => 'Incomplete PDS (full-time faculty)', 'icon' => 'fa-id-card', 'category' => 'pds_update'],
        'no_seminar' => ['label' => 'No seminar / training in ' . (int)REMINDER_NO_SEMINAR_MONTHS . ' months', 'icon' => 'fa-chalkboard-user', 'category' => 'no_recent_seminar'],
        'expiring'   => ['label' => 'Expired or expiring documents', 'icon' => 'fa-hourglass-half', 'category' => 'expiring_followup'],
    ];
    $out = [];
    foreach ($kinds as $type => $k) {
        $names = [];
        try {
            $stmt = $pdo->prepare(
                "SELECT DISTINCT u.user_id, u.full_name FROM ai_reminders r JOIN users u ON u.user_id = r.user_id
                 WHERE r.resolved_at IS NULL AND r.reminder_type = ? AND $scope" . ($type === 'pds' ? ' AND ' . sql_has_pds('u') : '') . "
                 ORDER BY u.full_name");
            $stmt->execute([$type, ...$sp]);
            $names = $stmt->fetchAll(PDO::FETCH_COLUMN, 1);
        } catch (PDOException $e) {
            // the reminder tables aren't there yet: nothing to show
        }
        $out[$type] = [
            'type'   => $type,
            'label'  => $k['label'],
            'icon'   => $k['icon'],
            'count'  => count($names),
            'names'  => array_slice($names, 0, 3),
            'modal'  => BASE_URL . '/api/modal.php?view=list&category=' . $k['category'],
        ];
    }
    return $out;
}
