<?php
/**
 * Account profiles (profile.php): who may see whose profile, the profile
 * picture, and the details shown on it. Loaded by includes/functions.php.
 *
 * Where each profile detail comes from:
 *   full name, email, role, program / college, employment type / status,
 *   date hired (date_engaged), last login (login_attempts)   existing data
 *   position         role + employment type (user_position_label())
 *   employee ID      users.employee_id (Admin), else the PDS Agency Employee No.
 *   contact number   users.contact_number, else the PDS Mobile No. (kept in step)
 *   education        PDS Educational Background, else uploaded diplomas
 *   academic rank, specialization, profile picture            new columns
 */

/** users row, or null. */
function user_row(PDO $pdo, int $user_id): ?array {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
    $stmt->execute([$user_id]);
    return $stmt->fetch() ?: null;
}

/**
 * SQL condition (on users alias $a) for the accounts $viewer may see, and
 * its parameters. The single place this rule lives -- profiles, the
 * faculty directory, archives and the Seminar & Training Report all use it:
 *   Admin          every account
 *   Dean           themselves + faculty and Program Chairs of their college
 *   Program Chair  themselves + faculty of their program
 *   Faculty        themselves only
 * (Same scoping as upload notifications, upload_notification_recipients().)
 * @return array{0: string, 1: array}
 */
function profile_scope_sql(PDO $pdo, array $viewer, string $a = 'u'): array {
    $me = (int)$viewer['user_id'];
    switch ($viewer['role']) {
        case 'admin':
            return ['1=1', []];
        case 'dean':
            $v = user_row($pdo, $me);
            return ["({$a}.user_id = ? OR ({$a}.role IN ('faculty', 'program_chair') AND {$a}.college = ?))", [$me, (string)($v['college'] ?? '')]];
        case 'program_chair':
            $v = user_row($pdo, $me);
            return ["({$a}.user_id = ? OR ({$a}.role = 'faculty' AND {$a}.program = ? AND {$a}.college = ?))",
                    [$me, (string)($v['program'] ?? ''), (string)($v['college'] ?? '')]];
        default:
            return ["{$a}.user_id = ?", [$me]];
    }
}

/** Whether $viewer may open the profile (and archive list) of account $target_id. */
function can_view_profile(PDO $pdo, array $viewer, int $target_id): bool {
    if ($target_id === (int)$viewer['user_id']) { return true; }
    [$scope, $params] = profile_scope_sql($pdo, $viewer);
    $stmt = $pdo->prepare("SELECT 1 FROM users u WHERE u.user_id = ? AND $scope");
    $stmt->execute([$target_id, ...$params]);
    return (bool)$stmt->fetchColumn();
}

/** Position shown on the profile: Part-time / Full-time Faculty, Program Chair, Dean or Administrator. */
function user_position_label(array $u): string {
    return match ($u['role']) {
        'admin'         => 'Administrator',
        'dean'          => 'Dean',
        'program_chair' => 'Program Chair',
        default         => ($u['employment_type'] ?? null) ? employment_type_label($u['employment_type']) . ' Faculty' : 'Faculty',
    };
}

/** "Program Chair · BS Computer Science" style line: position, then program or college. */
function user_position_line(array $u): string {
    $unit = $u['role'] === 'dean'
        ? (COLLEGES[$u['college'] ?? ''] ?? null)
        : (PROGRAMS[$u['program'] ?? '']['label'] ?? null);
    return user_position_label($u) . ($unit ? ' · ' . $unit : '');
}

// ---------------------------------------------------------------------
// Names. users.full_name is the one name every page shows (pages join
// users by user_id; nothing else stores a copy). It is built from the parts
// (name_title, first_name, middle_name, last_name, name_suffix) by
// person_full_name() whenever the Admin creates or edits an account:
// "Dr. Juan S. Dela Cruz Jr." in lists, person_first_name() "Juan" in
// greetings. Accounts not edited since the parts were added keep their
// full_name; person_name_parts() reads the parts from it.
// ---------------------------------------------------------------------

const NAME_TITLES = ['Dr.', 'Engr.', 'Prof.', 'Atty.', 'Arch.', 'Mr.', 'Ms.', 'Mrs.'];
const NAME_SUFFIXES = ['Jr.', 'Sr.', 'II', 'III', 'IV', 'V'];

/** "Juan S. Dela Cruz Jr." from the parts (title, first, middle, last, suffix); a middle name becomes its initial. */
function person_full_name(array $p): string {
    $middle = trim((string)($p['middle_name'] ?? ''));
    $initial = $middle !== '' ? mb_strtoupper(mb_substr($middle, 0, 1)) . '.' : '';
    return trim(preg_replace('/\s+/u', ' ', implode(' ', [
        $p['name_title'] ?? '', $p['first_name'] ?? '', $initial, $p['last_name'] ?? '', $p['name_suffix'] ?? '',
    ])));
}

/**
 * The name parts of a users row: the stored ones, or -- for an account not
 * edited since the parts were added -- read from full_name ("Dr. Fernando
 * Del Rosario" -> title Dr., first Fernando, last Del Rosario; a lone
 * initial before the surname is the middle name).
 * @return array{name_title: string, first_name: string, middle_name: string, last_name: string, name_suffix: string}
 */
function person_name_parts(array $u): array {
    if (trim((string)($u['first_name'] ?? '')) !== '') {
        return ['name_title' => (string)($u['name_title'] ?? ''), 'first_name' => (string)$u['first_name'],
                'middle_name' => (string)($u['middle_name'] ?? ''), 'last_name' => (string)($u['last_name'] ?? ''),
                'name_suffix' => (string)($u['name_suffix'] ?? '')];
    }
    $words = array_values(array_filter(preg_split('/\s+/u', trim((string)($u['full_name'] ?? '')))));
    $key = fn(string $w): string => mb_strtolower(rtrim($w, '.,'));
    $title = '';
    while ($words && ($t = array_values(array_filter(NAME_TITLES, fn($x) => $key($x) === $key($words[0]))))) {
        $title = trim($title . ' ' . $t[0]);
        array_shift($words);
    }
    $suffix = '';
    if (count($words) > 1 && ($sx = array_values(array_filter(NAME_SUFFIXES, fn($x) => $key($x) === $key(end($words)))))) {
        $suffix = $sx[0];
        array_pop($words);
    }
    $last = $words ? [array_pop($words)] : [];
    $particles = ['de', 'del', 'dela', 'la', 'los', 'delos', 'san', 'santa', 'sta', 'van', 'von', 'da', 'di', 'dos', 'das'];
    while (count($words) > 1 && in_array($key(end($words)), $particles, true)) {
        array_unshift($last, array_pop($words));
    }
    $middle = '';
    if (count($words) > 1 && preg_match('/^\p{L}\.?$/u', end($words))) {
        $middle = rtrim(array_pop($words), '.');
    }
    return ['name_title' => $title, 'first_name' => implode(' ', $words) ?: implode(' ', $last), 'middle_name' => $middle,
            'last_name' => $words ? implode(' ', $last) : '', 'name_suffix' => $suffix];
}

/** Account status shown on a profile: [label, status-pill class] -- Active, Deactivated, Deleted (restorable) or Deleted permanently. */
function account_status_pill(array $u): array {
    return match (true) {
        !empty($u['purged_at'])  => ['Deleted permanently', 'status-pill-off'],
        !empty($u['deleted_at']) => ['Deleted', 'status-pill-off'],
        (bool)$u['is_active']    => ['Active', 'status-pill-active'],
        default                  => ['Deactivated', 'status-pill-off'],
    };
}

/** "Juan" -- the first given name, for greetings ("Welcome back, Juan"). */
function person_first_name(array $u): string {
    $first = trim((string)($u['first_name'] ?? ''));
    if ($first === '') {
        $first = person_name_parts($u)['first_name'];
    }
    if ($first === '') { return 'there'; }
    $words = preg_split('/\s+/u', $first);
    // "Ma. Teresa" -> "Ma. Teresa", not "Ma."
    return str_ends_with($words[0], '.') && isset($words[1]) ? $words[0] . ' ' . $words[1] : $words[0];
}

/**
 * The name parts from a form (name_title, first_name, middle_name,
 * last_name, name_suffix), checked: first and last name required, letters
 * (any language), spaces, hyphens, apostrophes and periods only, within
 * the column sizes; title and suffix from the lists (or blank).
 * @return array|string the cleaned parts, or an error message
 */
function person_name_input(array $in): array|string {
    $clean = fn($v) => trim(preg_replace('/\s+/u', ' ', (string)$v));
    $p = ['name_title' => $clean($in['name_title'] ?? ''), 'first_name' => $clean($in['first_name'] ?? ''),
          'middle_name' => $clean($in['middle_name'] ?? ''), 'last_name' => $clean($in['last_name'] ?? ''),
          'name_suffix' => $clean($in['name_suffix'] ?? '')];
    if ($p['first_name'] === '' || $p['last_name'] === '') {
        return 'Please enter the first name and the last name.';
    }
    foreach (['first_name' => 'First name', 'middle_name' => 'Middle name', 'last_name' => 'Last name'] as $k => $label) {
        if (mb_strlen($p[$k]) > 60) { return "{$label} is too long (60 characters at most)."; }
        if ($p[$k] !== '' && !preg_match("/^\p{L}[\p{L}\p{M} .'\x{2019}-]*$/u", $p[$k])) {
            return "{$label} may only contain letters, spaces, hyphens, apostrophes and periods.";
        }
    }
    if ($p['name_title'] !== '' && !in_array($p['name_title'], NAME_TITLES, true)) { return 'Please choose a title from the list.'; }
    if ($p['name_suffix'] !== '' && !in_array($p['name_suffix'], NAME_SUFFIXES, true)) { return 'Please choose a suffix from the list.'; }
    if (mb_strlen(person_full_name($p)) > 150) { return 'The full name is too long.'; }
    return $p;
}

// ---------------------------------------------------------------------
// Usernames: what people log in with, set by the Admin (Manage Faculty).
// 4-30 letters, numbers, dots and underscores, unique ignoring case --
// stored in lowercase, and what's typed at login is lowercased too.
// ---------------------------------------------------------------------

const USERNAME_RULE = 'Usernames are 4-30 characters: letters, numbers, dots (.) and underscores (_).';

function username_normalize(string $username): string {
    return mb_strtolower(trim($username));
}

function username_valid(string $username): bool {
    return (bool)preg_match('/^[a-z0-9._]{4,30}$/', $username);
}

/** Whether another account (not $except_user_id) has $username. */
function username_taken(PDO $pdo, string $username, int $except_user_id = 0): bool {
    $stmt = $pdo->prepare("SELECT 1 FROM users WHERE LOWER(username) = ? AND user_id <> ?");
    $stmt->execute([username_normalize($username), $except_user_id]);
    return (bool)$stmt->fetchColumn();
}

/** "delacruz" from "Dela Cruz": lowercase a-z and digits only, accents dropped (ñ -> n). */
function username_slug(string $s): string {
    $s = mb_strtolower(trim($s));
    $s = strtr($s, ['ñ' => 'n', 'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
                    'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o',
                    'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c']);
    return preg_replace('/[^a-z0-9]/', '', $s);
}

/**
 * Free usernames for a person, best first: first.last -- the first given
 * name that isn't an abbreviation ("Ma. Teresa Dela Cruz" -> teresa.delacruz)
 * -- then the employee ID, each with a number added if taken (juan.delacruz2).
 * $u: first_name / last_name (or full_name), employee_id.
 * @return string[] up to $max suggestions
 */
function username_suggestions(PDO $pdo, array $u, int $except_user_id = 0, int $max = 3): array {
    $parts = person_name_parts($u);
    $first_words = array_values(array_filter(preg_split('/\s+/u', $parts['first_name']), fn($w) => !str_ends_with($w, '.')))
                ?: preg_split('/\s+/u', $parts['first_name']);
    $first = username_slug($first_words[0] ?? '');
    $last = username_slug($parts['last_name']);
    $bases = [];
    if ($first !== '' && $last !== '') { $bases[] = mb_substr($first . '.' . $last, 0, 28); }
    if ($first !== '' && $last === '') { $bases[] = mb_substr($first, 0, 28); }
    $emp = preg_replace('/[^a-z0-9._]/', '', mb_strtolower((string)($u['employee_id'] ?? '')));
    if ($emp !== '') { $bases[] = mb_substr(strlen($emp) < 4 ? 'emp' . $emp : $emp, 0, 28); }
    $found = [];
    foreach ($bases as $base) {
        $base = rtrim($base, '.');
        if (strlen($base) < 4) { $base = str_pad($base, 4, '0'); }
        for ($n = 1; $n < 100 && count($found) < $max; $n++) {
            $candidate = $n === 1 ? $base : mb_substr($base, 0, 30 - strlen((string)$n)) . $n;
            if (username_valid($candidate) && !in_array($candidate, $found, true) && !username_taken($pdo, $candidate, $except_user_id)) {
                $found[] = $candidate;
                break;
            }
        }
    }
    return $found;
}

/**
 * Give every account without a username one (first.last, a number added if
 * taken; "user" + id when there's no usable name). For the accounts from
 * before usernames existed; run on a login attempt, on Manage Faculty and by
 * tools/assign_usernames.php. Accounts deleted permanently are skipped.
 * @return array<int, array{user_id: int, username: string, full_name: string, role: string}> the ones just assigned
 */
function assign_missing_usernames(PDO $pdo): array {
    $rows = $pdo->query("SELECT user_id, full_name, first_name, last_name, employee_id, role FROM users
                         WHERE username IS NULL AND purged_at IS NULL ORDER BY user_id")->fetchAll();
    $done = [];
    foreach ($rows as $u) {
        $username = username_suggestions($pdo, ['employee_id' => null] + $u, (int)$u['user_id'], 1)[0] ?? 'user' . (int)$u['user_id'];
        if (!username_valid($username) || username_taken($pdo, $username, (int)$u['user_id'])) {
            $username = 'user' . (int)$u['user_id'];
        }
        $stmt = $pdo->prepare("UPDATE users SET username = ? WHERE user_id = ? AND username IS NULL");
        $stmt->execute([$username, (int)$u['user_id']]);
        if ($stmt->rowCount() === 1) {
            $done[] = ['user_id' => (int)$u['user_id'], 'username' => $username, 'full_name' => $u['full_name'], 'role' => $u['role']];
            log_activity($pdo, null, 'USERNAME_CHANGE', "Username \"{$username}\" assigned automatically to account #{$u['user_id']} {$u['full_name']} "
                . '(accounts from before usernames get first.last).', 'system');
        }
    }
    return $done;
}

// ---------------------------------------------------------------------
// Contact email (optional; not used to log in). Stored in lowercase and
// unique: two accounts can't share one.
// ---------------------------------------------------------------------

function email_normalize(string $email): string {
    return mb_strtolower(trim($email));
}

function email_valid(string $email): bool {
    return $email !== '' && mb_strlen($email) <= 150 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/** Whether another account (not $except_user_id) has $email. */
function email_taken(PDO $pdo, string $email, int $except_user_id = 0): bool {
    $stmt = $pdo->prepare("SELECT 1 FROM users WHERE LOWER(email) = ? AND user_id <> ?");
    $stmt->execute([email_normalize($email), $except_user_id]);
    return (bool)$stmt->fetchColumn();
}

/** assign_missing_usernames() when some account still has no username (one quick check otherwise). Never throws. */
function ensure_usernames(PDO $pdo): void {
    try {
        if ($pdo->query("SELECT 1 FROM users WHERE username IS NULL AND purged_at IS NULL LIMIT 1")->fetchColumn()) {
            assign_missing_usernames($pdo);
        }
    } catch (Throwable $e) {
        error_log('Assigning usernames failed: ' . $e->getMessage());
    }
}

/**
 * A message addressed to the user ("Hi, Chrystal! ...") with the greeting
 * using their current first name. Reminder and notification texts are
 * written once, with the name at that time; this keeps them right after a
 * rename.
 */
function greeting_with_name(string $message, string $first_name): string {
    return preg_replace('/^(\s*(?:Hi|Hey|Hello|Good (?:morning|afternoon|evening|day)),?\s+)[^!,.?\n]{1,60}?([!,.])/u',
        '${1}' . str_replace(['\\', '$'], ['\\\\', '\\$'], $first_name) . '${2}', $message, 1) ?? $message;
}

/** "FD" for "Dr. Fernando Del Rosario": first and last name, titles and suffixes skipped. */
function user_initials(string $full_name): string {
    $skip = ['dr', 'engr', 'prof', 'mr', 'ms', 'mrs', 'atty', 'arch', 'jr', 'sr', 'ii', 'iii', 'iv'];
    $words = array_values(array_filter(preg_split('/\s+/u', trim($full_name)), function ($w) use ($skip) {
        return $w !== '' && !in_array(mb_strtolower(rtrim($w, '.,')), $skip, true);
    }));
    if (!$words) { return '?'; }
    $first = mb_strtoupper(mb_substr($words[0], 0, 1));
    return count($words) > 1 ? $first . mb_strtoupper(mb_substr(end($words), 0, 1)) : $first;
}

function profile_photo_url(int $user_id, string $picture): string {
    return BASE_URL . '/profile_photo.php?id=' . $user_id . '&v=' . rawurlencode($picture);
}

/**
 * Round profile picture, or the initials when there is none. $u needs
 * user_id, full_name and profile_picture.
 */
function user_avatar(array $u, int $size = 36, string $class = '', string $alt = ''): string {
    $style = "width:{$size}px;height:{$size}px;";
    if (!empty($u['profile_picture'])) {
        return '<img src="' . h(profile_photo_url((int)$u['user_id'], $u['profile_picture'])) . '" alt="' . h($alt) . '"'
             . ' class="avatar ' . h($class) . '" style="' . $style . '" width="' . $size . '" height="' . $size . '" loading="lazy">';
    }
    return '<span class="avatar avatar-initials ' . h($class) . '" style="' . $style . 'font-size:' . round($size * 0.4) . 'px"'
         . ($alt !== '' ? ' role="img" aria-label="' . h($alt) . '"' : ' aria-hidden="true"') . '>' . h(user_initials($u['full_name'])) . '</span>';
}

/**
 * The signed-in user's picture name, for the header. Kept in the session;
 * looked up once for sessions that started before it was stored there.
 */
function current_user_picture(PDO $pdo): ?string {
    if (!isset($_SESSION['user'])) { return null; }
    if (!array_key_exists('profile_picture', $_SESSION['user'])) {
        try {
            $stmt = $pdo->prepare("SELECT profile_picture FROM users WHERE user_id = ?");
            $stmt->execute([$_SESSION['user']['user_id']]);
            $_SESSION['user']['profile_picture'] = $stmt->fetchColumn() ?: null;
        } catch (PDOException $e) {
            return null;   // migration not applied yet
        }
    }
    return $_SESSION['user']['profile_picture'];
}

/** Active ranks for the Academic Rank dropdown, plus $current if it was hidden since. */
function academic_rank_options(PDO $pdo, ?string $current = null): array {
    $names = $pdo->query("SELECT name FROM academic_ranks WHERE is_active = 1 ORDER BY sort_order, name")->fetchAll(PDO::FETCH_COLUMN);
    if ($current !== null && $current !== '' && !in_array($current, $names, true)) { $names[] = $current; }
    return $names;
}

/** "7 years, 2 months" since $date_hired (Y-m-d), in Asia/Manila. */
function years_of_service_label(?string $date_hired): string {
    if (!$date_hired) { return 'Not set'; }
    $hired = new DateTimeImmutable($date_hired);
    $today = new DateTimeImmutable('today');
    if ($hired > $today) { return 'Starts ' . $hired->format('M j, Y'); }
    $d = $hired->diff($today);
    $parts = [];
    if ($d->y) { $parts[] = $d->y . ' year' . ($d->y === 1 ? '' : 's'); }
    if ($d->m) { $parts[] = $d->m . ' month' . ($d->m === 1 ? '' : 's'); }
    return $parts ? implode(', ', $parts) : 'Less than a month';
}

/**
 * Highest educational attainment: the highest level in the PDS Educational
 * Background (Graduate Studies > College > ...; a doctorate over a master's;
 * completed over ongoing), else the highest uploaded diploma, else null.
 */
function highest_education(PDO $pdo, int $user_id, array $pds_data): ?string {
    $levels = ['Elementary' => 1, 'Secondary' => 2, 'Vocational / Trade Course' => 3, 'College' => 4, 'Graduate Studies' => 5];
    $best = null;
    $best_score = 0;
    foreach ((array)($pds_data['education'] ?? []) as $row) {
        $score = ($levels[$row['level'] ?? ''] ?? 0) * 10;
        if (!$score) { continue; }
        $degree = (string)($row['degree'] ?? '');
        if ($row['level'] === 'Graduate Studies') {
            if (preg_match('/doctor|ph\.?\s?d|ed\.?\s?d|d\.?\s?i\.?\s?t/i', $degree)) { $score += 4; }
            elseif (preg_match('/master|\bm\.?\s?[as]\.?\b|\bmit\b|\bmba\b/i', $degree)) { $score += 2; }
        }
        $graduated = preg_match('/^\d{4}$/', trim((string)($row['year_graduated'] ?? '')));
        if ($graduated) { $score += 1; }
        if ($score > $best_score) { $best_score = $score; $best = $row + ['_graduated' => $graduated]; }
    }
    if ($best) {
        $what = trim((string)($best['degree'] ?? '')) ?: $best['level'];
        $school = trim((string)($best['school'] ?? ''));
        $note = $best['_graduated'] ? $best['year_graduated'] : 'ongoing' . (trim((string)($best['units'] ?? '')) !== '' && $best['units'] !== 'N/A' ? ', ' . $best['units'] : '');
        return $what . ($school !== '' ? ' — ' . $school : '') . ' (' . $note . ')';
    }

    $stmt = $pdo->prepare("SELECT DISTINCT document_subtype FROM documents WHERE faculty_id = ? AND document_type = 'Diploma' AND status IN ('active', 'archived')");
    $stmt->execute([$user_id]);
    $have = $stmt->fetchAll(PDO::FETCH_COLUMN);
    foreach (['Doctorate', 'Master', 'Bachelor'] as $level) {
        if (in_array($level, $have, true)) {
            return document_categories()['Diploma']['subtypes'][$level] . ' (from uploaded diploma)';
        }
    }
    return null;
}

/**
 * Successful logins, newest first: [latest, the one before]. For the
 * signed-in user the latest is this session, so the profile shows the one before.
 */
function recent_logins(PDO $pdo, int $user_id): array {
    $stmt = $pdo->prepare("SELECT attempted_at FROM login_attempts WHERE user_id = ? AND was_successful = 1 ORDER BY attempt_id DESC LIMIT 2");
    $stmt->execute([$user_id]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * The 201-file summary on a profile.
 * @return array{counts: array<string,int>, total: int, archived: int, trainings: int, trainings_archived: int, expired: int, expiring: int}
 */
function profile_201_summary(PDO $pdo, int $user_id): array {
    $counts = faculty_document_counts($pdo, $user_id);   // active only

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM documents WHERE faculty_id = ? AND status = 'archived'");
    $stmt->execute([$user_id]);
    $archived = (int)$stmt->fetchColumn();

    // Seminars / trainings attended: archived ones were still attended
    $stmt = $pdo->prepare(
        "SELECT status, COUNT(*) FROM documents
         WHERE faculty_id = ? AND document_type = 'Certificate' AND status IN ('active', 'archived')
           AND (document_subtype IN ('Seminar', 'Training') OR training_type IS NOT NULL)
         GROUP BY status"
    );
    $stmt->execute([$user_id]);
    $trainings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $expiring = expiring_documents($pdo, $user_id);
    $expired = count(array_filter($expiring, fn($d) => $d['days_left'] < 0));

    return [
        'counts'             => $counts,
        'total'              => array_sum($counts),
        'archived'           => $archived,
        'trainings'          => (int)($trainings['active'] ?? 0) + (int)($trainings['archived'] ?? 0),
        'trainings_archived' => (int)($trainings['archived'] ?? 0),
        'expired'            => $expired,
        'expiring'           => count($expiring) - $expired,
    ];
}

/** Contact number format accepted on the profile (mobile or landline). */
function valid_contact_number(string $v): bool {
    return (bool)preg_match('/^\+?[0-9()\s-]{7,20}$/', $v) && preg_match_all('/\d/', $v) >= 7;
}

/**
 * Save the user's contact number, and copy it to their PDS Mobile No. when
 * they have a PDS and it's a valid mobile number there -- without counting
 * as the annual PDS update (updated_at is kept). The PDS page copies the
 * other way when the PDS is saved.
 */
function profile_save_contact(PDO $pdo, int $user_id, ?string $contact): void {
    $pdo->prepare("UPDATE users SET contact_number = ? WHERE user_id = ?")->execute([$contact, $user_id]);
    if ($contact === null) { return; }
    require_once __DIR__ . '/pds.php';
    $pds = pds_load($pdo, $user_id);
    if (!$pds['exists'] || ($pds['data']['mobile_no'] ?? '') === $contact
        || pds_check_value(pds_rules()['mobile_no'], 'text', $contact, $pds['data']) !== null) {
        return;
    }
    pds_snapshot($pdo, $user_id, 'Before Mobile No. change from My Profile');
    $data = $pds['data'];
    $data['mobile_no'] = $contact;
    $pdo->prepare("UPDATE pds_records SET data = ?, updated_at = updated_at WHERE faculty_id = ?")->execute([json_encode($data), $user_id]);
    pds_record_version($pdo, $user_id, 'manual edit', null, $user_id);   // the Mobile No. changed
}

// ---------------------------------------------------------------------
// Profile picture: JPG / PNG up to PROFILE_PICTURE_MAX_BYTES. The real file
// type is checked on the server (content, not the name or the browser's
// claim), then the image is re-drawn as a square JPEG of
// PROFILE_PICTURE_SIZE pixels -- which also drops anything hidden in the
// original file (metadata, scripts). Stored in profile_pictures under a
// random name; served only by profile_photo.php.
// ---------------------------------------------------------------------

/**
 * Validate an uploaded picture ($_FILES entry) and make the square JPEG.
 * @return array{ok: bool, data?: string, error?: string}
 */
function profile_picture_from_upload(array $file): array {
    $max_mb = PROFILE_PICTURE_MAX_BYTES / 1024 / 1024;
    $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($error === UPLOAD_ERR_NO_FILE) { return ['ok' => false, 'error' => 'Please choose a picture to upload.']; }
    if (in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || ($error === UPLOAD_ERR_OK && $file['size'] > PROFILE_PICTURE_MAX_BYTES)) {
        return ['ok' => false, 'error' => "That picture is too large. The maximum size is {$max_mb} MB."];
    }
    if ($error !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        return ['ok' => false, 'error' => 'The picture could not be uploaded. Please try again.'];
    }

    $types = ['image/jpeg' => [IMAGETYPE_JPEG, ['jpg', 'jpeg']], 'image/png' => [IMAGETYPE_PNG, ['png']]];
    $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    $mime = function_exists('finfo_open') ? (finfo_file(finfo_open(FILEINFO_MIME_TYPE), $file['tmp_name']) ?: '') : '';
    $info = @getimagesize($file['tmp_name']);
    if (!isset($types[$mime]) || !in_array($ext, $types[$mime][1], true) || !$info || $info[2] !== $types[$mime][0]) {
        return ['ok' => false, 'error' => 'Only JPG and PNG pictures are allowed.'];
    }
    if ($info[0] < 64 || $info[1] < 64) {
        return ['ok' => false, 'error' => 'That picture is too small. Please use one at least 64 × 64 pixels.'];
    }
    if ($info[0] * $info[1] > 40000000) {   // 40 megapixels: too big to resize safely in memory
        return ['ok' => false, 'error' => 'That picture has too many pixels. Please use a smaller one.'];
    }

    $data = square_jpeg($file['tmp_name'], $mime, PROFILE_PICTURE_SIZE);
    return $data !== null ? ['ok' => true, 'data' => $data] : ['ok' => false, 'error' => 'The picture could not be processed. Please try a different file.'];
}

/**
 * Crop the middle square of an image, resize it to $size x $size and return
 * it as JPEG bytes (transparent areas become white). Uses GD, or ImageMagick
 * where GD isn't installed (the Docker image has ImageMagick for OCR).
 */
function square_jpeg(string $path, string $mime, int $size): ?string {
    if (function_exists('imagecreatefromjpeg') && function_exists('imagecreatefrompng')) {
        $src = $mime === 'image/png' ? @imagecreatefrompng($path) : @imagecreatefromjpeg($path);
        if (!$src) { return null; }
        // Phone photos are often stored sideways with an EXIF "rotate me" tag
        if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
            $exif = @exif_read_data($path);
            $angle = [3 => 180, 6 => -90, 8 => 90][is_array($exif) ? (int)($exif['Orientation'] ?? 1) : 1] ?? 0;
            if ($angle) { $src = imagerotate($src, $angle, 0); }
        }
        $w = imagesx($src);
        $h = imagesy($src);
        $side = min($w, $h);
        $dst = imagecreatetruecolor($size, $size);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $src, 0, 0, intdiv($w - $side, 2), intdiv($h - $side, 2), $size, $size, $side, $side);
        ob_start();
        imagejpeg($dst, null, 85);
        $out = ob_get_clean();
        imagedestroy($src);
        imagedestroy($dst);
        return $out !== '' && $out !== false ? $out : null;
    }
    $convert = trim((string)@shell_exec('command -v convert 2>/dev/null'));
    if ($convert === '') { return null; }
    $out = @shell_exec(escapeshellcmd($convert) . ' ' . escapeshellarg($path . '[0]') . ' -auto-orient -thumbnail ' . escapeshellarg("{$size}x{$size}^")
        . " -gravity center -extent {$size}x{$size} -background white -flatten -strip -quality 85 jpg:- 2>/dev/null");
    return is_string($out) && str_starts_with($out, "\xFF\xD8") ? $out : null;
}

/** Store a processed picture for $user_id under a new random name; returns the name. */
function save_profile_picture(PDO $pdo, int $user_id, string $jpeg): string {
    $name = bin2hex(random_bytes(16)) . '.jpg';
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO profile_pictures (user_id, mime_type, file_size, data) VALUES (?, 'image/jpeg', ?, ?) "
            . sql_on_duplicate('user_id', 'mime_type = ' . sql_inserted('mime_type') . ', file_size = ' . sql_inserted('file_size')
                                          . ', data = ' . sql_inserted('data'))
        );
        $stmt->bindValue(1, $user_id, PDO::PARAM_INT);
        $stmt->bindValue(2, strlen($jpeg), PDO::PARAM_INT);
        $stmt->bindValue(3, $jpeg, PDO::PARAM_LOB);
        $stmt->execute();
        $pdo->prepare("UPDATE users SET profile_picture = ? WHERE user_id = ?")->execute([$name, $user_id]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return $name;
}

function remove_profile_picture(PDO $pdo, int $user_id): void {
    $pdo->prepare("DELETE FROM profile_pictures WHERE user_id = ?")->execute([$user_id]);
    $pdo->prepare("UPDATE users SET profile_picture = NULL WHERE user_id = ?")->execute([$user_id]);
}
