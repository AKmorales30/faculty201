<?php
require_once __DIR__ . '/../config/db.php';

/**
 * Insert a notification for a given user.
 */
function notify(PDO $pdo, int $user_id, string $message, ?int $request_id = null) {
    $stmt = $pdo->prepare(
        "INSERT INTO notifications (user_id, request_id, message) VALUES (?, ?, ?)"
    );
    $stmt->execute([$user_id, $request_id, $message]);
}

/**
 * Notify every user holding a given role (used to alert both the
 * Program Chair and Dean when a faculty member submits a document).
 */
function notify_role(PDO $pdo, string $role, string $message, ?int $request_id = null) {
    $stmt = $pdo->prepare("SELECT user_id FROM users WHERE role = ? AND is_active = 1");
    $stmt->execute([$role]);
    foreach ($stmt->fetchAll() as $row) {
        notify($pdo, (int)$row['user_id'], $message, $request_id);
    }
}

function unread_notification_count(PDO $pdo, int $user_id): int {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$user_id]);
    return (int)$stmt->fetchColumn();
}

function recent_notifications(PDO $pdo, int $user_id, int $limit = 8): array {
    $stmt = $pdo->prepare(
        "SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT ?"
    );
    $stmt->bindValue(1, $user_id, PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * Human-friendly label + Bootstrap badge class for a request status.
 */
function status_badge(string $status): array {
    $map = [
        'pending'          => ['Pending Review', 'bg-secondary'],
        'chair_confirmed'  => ['Confirmed by Program Chair — awaiting Dean', 'bg-info text-dark'],
        'dean_confirmed'   => ['Confirmed by Dean — awaiting Program Chair', 'bg-info text-dark'],
        'fully_confirmed'  => ['Fully Confirmed — filing in progress', 'bg-primary'],
        'uploaded'         => ['Uploaded to 201 File', 'bg-success'],
        'rejected'         => ['Rejected', 'bg-danger'],
    ];
    return $map[$status] ?? [$status, 'bg-secondary'];
}

// ---------------------------------------------------------------------
// Upload workflow (notification-based). A faculty upload is accepted and
// filed as soon as the file is stored -- there is no Program Chair / Dean
// approval step. The Chair and Dean are notified instead.
// submission_requests is still written (status 'uploaded') as the upload
// log, and documents.request_id points back to it.
// ---------------------------------------------------------------------

/**
 * Move a scan (path relative to ROOT_PATH) into the permanent repository
 * folder uploads/{faculty_id}/{document_type}/ and return its new
 * relative path, or null if the file could not be moved.
 */
function move_to_repository(int $faculty_id, string $document_type, string $source_rel): ?string {
    $srcAbs = ROOT_PATH . '/' . $source_rel;
    $destDir = UPLOADS_PATH . '/' . $faculty_id . '/' . $document_type;
    if (!is_dir($destDir)) { @mkdir($destDir, 0775, true); }
    $filename = basename($source_rel);
    if (!is_file($srcAbs) || !@rename($srcAbs, $destDir . '/' . $filename)) {
        return null;
    }
    return 'uploads/' . $faculty_id . '/' . $document_type . '/' . $filename;
}

/**
 * Tell every Program Chair and Dean (and the Admin, as before) that a
 * faculty member uploaded a document to their 201 file.
 */
function notify_new_upload(PDO $pdo, string $faculty_name, string $document_type, int $request_id, ?string $uploaded_at = null): void {
    $categories = document_categories();
    $label = $categories[$document_type]['label'] ?? $document_type;
    $when = new DateTime($uploaded_at ?? 'now', new DateTimeZone('Asia/Manila'));

    $msg = "New Document Uploaded\n"
         . "Faculty: {$faculty_name}\n"
         . "Document Type: {$label}\n"
         . "Date Uploaded: " . $when->format('F j, Y, g:i A') . "\n"
         . "The document has been added to the faculty member's 201 Repository.";

    notify_role($pdo, 'program_chair', $msg, $request_id);
    notify_role($pdo, 'dean', $msg, $request_id);
    notify_role($pdo, 'admin', $msg, $request_id);
}

/**
 * File a faculty member's upload immediately: move the scan into the
 * repository, log it in submission_requests as 'uploaded', insert the
 * documents row, and notify the Program Chair / Dean. $ocr is the
 * OcrProcessor::process() result from the preview step.
 * Returns the request_id, or null if the file could not be stored.
 */
function store_faculty_upload(PDO $pdo, array $faculty, string $document_type, ?string $expiration, string $scan_rel, array $ocr): ?int {
    $filePath = move_to_repository((int)$faculty['user_id'], $document_type, $scan_rel);
    if ($filePath === null) {
        return null;
    }

    try {
        $pdo->beginTransaction();
        $pdo->prepare(
            "INSERT INTO submission_requests (faculty_id, document_type_hint, expiration_date_hint, temp_file_path, status)
             VALUES (?, ?, ?, ?, 'uploaded')"
        )->execute([$faculty['user_id'], $document_type, $expiration, $filePath]);
        $request_id = (int)$pdo->lastInsertId();

        $pdo->prepare(
            "INSERT INTO documents (request_id, faculty_id, document_type, file_path, expiration_date, ocr_extracted_text, ocr_matched_name, ocr_confidence_note)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        )->execute([
            $request_id, $faculty['user_id'], $document_type, $filePath, $expiration,
            $ocr['text'] ?? null, $ocr['matched_name'] ?? null, $ocr['confidence_note'] ?? null,
        ]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        // Put the scan back so the faculty member can retry from the preview.
        @rename(ROOT_PATH . '/' . $filePath, ROOT_PATH . '/' . $scan_rel);
        throw $e;
    }

    notify_new_upload($pdo, $faculty['full_name'], $document_type, $request_id);
    return $request_id;
}

/**
 * One-time catch-up for requests submitted under the old approval
 * workflow that were still waiting on the Program Chair / Dean: file
 * them now so nothing stays stuck. Safe to call repeatedly -- it does
 * nothing once no such requests remain.
 */
function file_outstanding_requests(PDO $pdo): void {
    $rows = $pdo->query(
        "SELECT sr.*, u.full_name FROM submission_requests sr
         JOIN users u ON u.user_id = sr.faculty_id
         WHERE sr.status IN ('pending','chair_confirmed','dean_confirmed','fully_confirmed')"
    )->fetchAll();
    if (!$rows) { return; }

    require_once ROOT_PATH . '/ocr/OcrProcessor.php';
    foreach ($rows as $req) {
        $scanAbs = ROOT_PATH . '/' . $req['temp_file_path'];
        $ocr = is_file($scanAbs) ? OcrProcessor::process($scanAbs, $req['full_name']) : [];
        $filePath = move_to_repository((int)$req['faculty_id'], $req['document_type_hint'], $req['temp_file_path'])
                 ?? $req['temp_file_path'];

        $pdo->prepare(
            "INSERT INTO documents (request_id, faculty_id, document_type, file_path, expiration_date, ocr_extracted_text, ocr_matched_name, ocr_confidence_note)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        )->execute([
            $req['request_id'], $req['faculty_id'], $req['document_type_hint'], $filePath, $req['expiration_date_hint'] ?: null,
            $ocr['text'] ?? null, $ocr['matched_name'] ?? null, $ocr['confidence_note'] ?? null,
        ]);
        $pdo->prepare("UPDATE submission_requests SET status='uploaded', temp_file_path=? WHERE request_id=?")
            ->execute([$filePath, $req['request_id']]);

        notify($pdo, (int)$req['faculty_id'],
            "Your {$req['document_type_hint']} has been added to your 201 file.", (int)$req['request_id']);
    }
}

function safe_filename(string $original): string {
    $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    $base = preg_replace('/[^a-zA-Z0-9_-]/', '_', pathinfo($original, PATHINFO_FILENAME));
    return date('Ymd_His') . '_' . substr($base, 0, 40) . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
}

function h(?string $s): string {
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * The three faculty-document categories the 201-file repository is
 * organized around (matches document_type ENUM('TOR','Diploma',
 * 'Certificate') in the documents table, and Ch.3 of the capstone
 * paper). Every folder view in the system (Faculty "My 201 File",
 * Admin "Faculty Records" drill-down) is built from this single list
 * so the categories stay consistent everywhere.
 */
function document_categories(): array {
    return [
        'TOR'         => ['label' => 'Transcript of Records', 'icon' => 'fa-file-lines',     'color' => 'teal'],
        'Diploma'     => ['label' => 'Diploma',                'icon' => 'fa-graduation-cap', 'color' => 'navy'],
        'Certificate' => ['label' => 'Certificates',           'icon' => 'fa-certificate',    'color' => 'gold'],
    ];
}

/**
 * Per-category filed-document counts for one faculty member. Always
 * returns all three category keys (zero-filled) so a folder tile never
 * has to guess at a missing key.
 */
function faculty_document_counts(PDO $pdo, int $faculty_id): array {
    $counts = array_fill_keys(array_keys(document_categories()), 0);
    $stmt = $pdo->prepare(
        "SELECT document_type, COUNT(*) c FROM documents WHERE faculty_id = ? GROUP BY document_type"
    );
    $stmt->execute([$faculty_id]);
    foreach ($stmt->fetchAll() as $row) {
        $counts[$row['document_type']] = (int)$row['c'];
    }
    return $counts;
}

/**
 * Filed documents expiring within the next $days days (not yet expired)
 * -- implements the "document expiration monitoring" module described
 * in the capstone paper (Ch.1, p.18).
 */
function documents_expiring_soon(PDO $pdo, int $days = 60): array {
    $stmt = $pdo->prepare(
        "SELECT d.*, u.full_name FROM documents d
         JOIN users u ON u.user_id = d.faculty_id
         WHERE d.expiration_date IS NOT NULL
           AND d.expiration_date >= CURDATE()
           AND d.expiration_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
         ORDER BY d.expiration_date ASC"
    );
    $stmt->execute([$days]);
    return $stmt->fetchAll();
}

/**
 * Human-friendly relative time ("5 minutes ago", "3 days ago") used in
 * every role's Notifications list.
 */
function time_ago(string $datetime): string {
    $diff = time() - strtotime($datetime);
    if ($diff < 60) { return 'just now'; }
    $units = [31536000 => 'year', 2592000 => 'month', 604800 => 'week', 86400 => 'day', 3600 => 'hour', 60 => 'minute'];
    foreach ($units as $secs => $label) {
        $n = intdiv($diff, $secs);
        if ($n >= 1) { return $n . ' ' . $label . ($n > 1 ? 's' : '') . ' ago'; }
    }
    return 'just now';
}

function mark_all_notifications_read(PDO $pdo, int $user_id): void {
    $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?")->execute([$user_id]);
}

/**
 * Which role's Notifications page a bell-icon link should point to --
 * used by includes/header.php so the notification bell works no matter
 * who is logged in.
 */
function role_notifications_path(string $role): string {
    switch ($role) {
        case 'admin':  return 'admin/notifications.php';
        case 'faculty': return 'faculty/notifications.php';
        default:        return 'approval/notifications.php'; // program_chair / dean
    }
}

// ---------------------------------------------------------------------
// RBAC + suspicious login alerts (Ch.3 3.1 of the capstone paper lists
// this as one of the system's core modules). Every login attempt --
// success or failure -- is logged to login_attempts. A successful login
// is then screened for two simple, transparent red flags: a brand-new
// IP address for that account, or a burst of recent failures right
// before it. Either one writes a row to security_alerts and notifies
// every Admin through the existing notification system.
// ---------------------------------------------------------------------

function record_login_attempt(PDO $pdo, string $email, ?int $user_id, bool $success, string $ip): void {
    $pdo->prepare(
        "INSERT INTO login_attempts (email, user_id, ip_address, was_successful) VALUES (?, ?, ?, ?)"
    )->execute([$email, $user_id, $ip, $success ? 1 : 0]);
}

function flag_suspicious_login(PDO $pdo, int $user_id, string $full_name, string $ip): void {
    $reasons = [];

    // Every IP this account has EVER successfully logged in from,
    // oldest first. The row for *this* login was already recorded by
    // record_login_attempt() just before this function runs, so the
    // last entry is the current one -- drop it to get the prior history.
    $stmt = $pdo->prepare(
        "SELECT ip_address FROM login_attempts
         WHERE user_id = ? AND was_successful = 1 AND ip_address IS NOT NULL
         ORDER BY attempt_id ASC"
    );
    $stmt->execute([$user_id]);
    $history = array_column($stmt->fetchAll(), 'ip_address');
    array_pop($history);

    if ($history && !in_array($ip, $history, true)) {
        $reasons[] = "Login from a new IP address ({$ip}) not seen on this account before.";
    }

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM login_attempts
         WHERE email = (SELECT email FROM users WHERE user_id = ?)
           AND was_successful = 0
           AND attempted_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)"
    );
    $stmt->execute([$user_id]);
    if ((int)$stmt->fetchColumn() >= 3) {
        $reasons[] = 'Three or more failed login attempts in the 15 minutes before this login.';
    }

    foreach ($reasons as $reason) {
        $pdo->prepare(
            "INSERT INTO security_alerts (user_id, reason, ip_address) VALUES (?, ?, ?)"
        )->execute([$user_id, $reason, $ip]);
        notify_role($pdo, 'admin', "Suspicious login flagged for {$full_name}: {$reason}");
    }
}

function unresolved_security_alert_count(PDO $pdo): int {
    return (int)$pdo->query("SELECT COUNT(*) FROM security_alerts WHERE is_reviewed = 0")->fetchColumn();
}
