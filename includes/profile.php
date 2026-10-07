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
            "INSERT INTO profile_pictures (user_id, mime_type, file_size, data) VALUES (?, 'image/jpeg', ?, ?)
             ON DUPLICATE KEY UPDATE mime_type = VALUES(mime_type), file_size = VALUES(file_size), data = VALUES(data)"
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
