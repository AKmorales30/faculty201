<?php
require_once __DIR__ . '/auth.php';   // config, session, h()
require_once __DIR__ . '/../config/db.php';

// ---------------------------------------------------------------------
// SQL that differs between MySQL / MariaDB (XAMPP, SkySQL) and PostgreSQL
// (Supabase), so the same queries run on both (config/db.php picks the
// database). Everything else is written in SQL both understand: CURRENT_DATE,
// INTERVAL '60' DAY, CASE, EXTRACT(YEAR FROM ...).
// ---------------------------------------------------------------------

/** Whether the database is PostgreSQL (Supabase) rather than MySQL / MariaDB. */
function db_is_pgsql(): bool {
    return DB_DRIVER === 'pgsql';
}

/** Case-insensitive LIKE: MySQL's LIKE already ignores case, PostgreSQL's needs ILIKE. */
function sql_like(): string {
    return db_is_pgsql() ? 'ILIKE' : 'LIKE';
}

/** $a equals $b, two NULLs counting as equal (MySQL's <=>). */
function sql_same(string $a, string $b): string {
    return db_is_pgsql() ? "{$a} IS NOT DISTINCT FROM {$b}" : "{$a} <=> {$b}";
}

/** Whole seconds from now until timestamp expression $expr (negative once it has passed). */
function sql_seconds_until(string $expr): string {
    return db_is_pgsql() ? "CAST(FLOOR(EXTRACT(EPOCH FROM (({$expr}) - LOCALTIMESTAMP))) AS INTEGER)" : "TIMESTAMPDIFF(SECOND, NOW(), {$expr})";
}

/** "2026-10" from date / timestamp expression $expr. */
function sql_year_month(string $expr): string {
    return db_is_pgsql() ? "to_char({$expr}, 'YYYY-MM')" : "DATE_FORMAT({$expr}, '%Y-%m')";
}

/** INSERT that skips a row whose key is already taken: sql_insert_ignore("t (a, b) VALUES (?, ?)"). */
function sql_insert_ignore(string $into): string {
    return db_is_pgsql() ? "INSERT INTO {$into} ON CONFLICT DO NOTHING" : "INSERT IGNORE INTO {$into}";
}

/**
 * The end of an INSERT that updates the existing row instead when the key
 * $key (the conflicting column(s)) is taken; $set as in an UPDATE, with
 * sql_inserted('col') for the value the INSERT tried to write.
 */
function sql_on_duplicate(string $key, string $set): string {
    return db_is_pgsql() ? "ON CONFLICT ({$key}) DO UPDATE SET {$set}" : "ON DUPLICATE KEY UPDATE {$set}";
}

/** In sql_on_duplicate()'s $set: the value the INSERT tried to write to $column. */
function sql_inserted(string $column): string {
    return db_is_pgsql() ? "EXCLUDED.{$column}" : "VALUES({$column})";
}

/** A binary column's value as a string (PostgreSQL hands bytea back as a stream). */
function db_blob($value): string {
    return is_resource($value) ? (string)stream_get_contents($value) : (string)$value;
}

/**
 * Apply any database migration in database/ that hasn't run yet, so a
 * deploy doesn't depend on someone running SQL by hand. Applied files
 * are recorded in schema_migrations. "Already exists" errors (duplicate
 * column / index / table) are skipped, so a half-finished run is simply
 * retried on the next request. Only files listed here are auto-applied.
 * The first two were once run by hand; they are listed too so a database
 * that missed them (login_attempts, security_alerts, expiration dates)
 * gets them -- on one that has them, every statement is an "already exists".
 * Each file is applied on its own: one that fails is logged and retried
 * later, without holding back the ones after it (a failure there once left
 * later tables -- login_lockouts -- missing, which broke every login).
 * MySQL / MariaDB only: these files are MySQL SQL, and a PostgreSQL
 * (Supabase) database gets all of them at once from
 * database/supabase/01_schema.sql.
 */
function run_pending_migrations(PDO $pdo): void {
    if (db_is_pgsql()) {
        return;
    }
    $migrations = ['migration_add_expiration.sql', 'migration_add_security_alerts.sql',
                   'migration_201_contents_pds.sql', 'migration_programs_colleges.sql', 'migration_document_files.sql',
                   'migration_classification_confidence.sql', 'migration_activity_logs.sql', 'migration_expiration_alerts.sql',
                   'migration_document_removal.sql', 'migration_password_management.sql', 'migration_login_lockout.sql',
                   'migration_search_indexes.sql', 'migration_profile_reports_archive.sql', 'migration_ai_features.sql',
                   'migration_reminder_checks.sql', 'migration_review_archive_views.sql',
                   'migration_recently_deleted.sql', 'migration_account_names_delete.sql', 'migration_document_file_chunks.sql',
                   'migration_flags_pds_versions.sql', 'migration_username_2fa.sql',
                   'migration_restore_two_factor.sql', 'migration_optional_two_factor.sql',
                   'migration_login_history.sql'];
    try {
        $applied = $pdo->query("SELECT name FROM schema_migrations")->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
                            name VARCHAR(190) PRIMARY KEY,
                            applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                        ) ENGINE=InnoDB");
        } catch (Throwable $e2) {
            error_log('Database migration failed: cannot create schema_migrations: ' . $e2->getMessage());
            return;
        }
        $applied = [];
    }
    foreach ($migrations as $file) {
        if (in_array($file, $applied, true)) { continue; }
        try {
            $sql = file_get_contents(__DIR__ . '/../database/' . $file);
            $sql = preg_replace('/^\s*--.*$/m', '', $sql);
            foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
                if (preg_match('/^USE\s/i', $statement)) { continue; }
                try {
                    $pdo->exec($statement);
                } catch (PDOException $e) {
                    // 1050 table exists, 1060 duplicate column, 1061 duplicate key name
                    if (!in_array((int)($e->errorInfo[1] ?? 0), [1050, 1060, 1061], true)) {
                        throw new RuntimeException($e->getMessage() . ' -- in: ' . mb_substr(preg_replace('/\s+/', ' ', $statement), 0, 200), 0, $e);
                    }
                }
            }
            $pdo->prepare(sql_insert_ignore("schema_migrations (name) VALUES (?)"))->execute([$file]);
        } catch (Throwable $e) {
            error_log("Database migration {$file} failed (retried on the next request; database/repair_schema.sql adds everything by hand): " . $e->getMessage());
        }
    }
}
run_pending_migrations($pdo);

/**
 * Insert a notification for a given user. $link: optional page it points
 * to, relative to BASE_URL (e.g. 'faculty/pds.php'), shown on the
 * Notifications page (notification_link_url()).
 */
function notify(PDO $pdo, int $user_id, string $message, ?int $request_id = null, ?string $link = null) {
    if ($link !== null) {
        $pdo->prepare("INSERT INTO notifications (user_id, request_id, message, link) VALUES (?, ?, ?, ?)")
            ->execute([$user_id, $request_id, $message, $link]);
        return;
    }
    $stmt = $pdo->prepare(
        "INSERT INTO notifications (user_id, request_id, message) VALUES (?, ?, ?)"
    );
    $stmt->execute([$user_id, $request_id, $message]);
}

/** Full URL of a notification's link, or null -- only pages of this system (no other sites). */
function notification_link_url(?string $link): ?string {
    if ($link === null || !preg_match('#^[a-z0-9_/]+\.php(\?[A-Za-z0-9_=&%.-]*)?(\#[A-Za-z0-9_-]+)?$#', $link)) {
        return null;
    }
    return BASE_URL . '/' . $link;
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

/**
 * status_badge() for an upload-log row that also has document_status
 * (documents.status): Posted (uploads go to 201-Files right away); a
 * document later archived or deleted says so. 'for_review' is the old
 * review step, kept for documents not yet moved on by the migration.
 */
function upload_status_badge(array $row): array {
    return match ($row['document_status'] ?? null) {
        'for_review' => ['For Review', 'bg-review'],
        'active'     => ['Posted', 'bg-success'],
        'deleted'    => ['Deleted', 'bg-secondary'],
        'archived'   => ['Archived', 'bg-light text-dark border'],
        default      => status_badge($row['status']),
    };
}

/** [label, badge class] of a documents.status value, as shown in lists and the preview pop-up. */
function document_status_badge(string $status): array {
    return upload_status_badge(['document_status' => $status, 'status' => 'uploaded']);
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
 * folder uploads/{faculty_id}/{document_type}[/{subtype}]/ and return its
 * new relative path, or null if the file could not be moved.
 */
function move_to_repository(int $faculty_id, string $document_type, string $source_rel, ?string $subtype = null): ?string {
    $srcAbs = ROOT_PATH . '/' . $source_rel;
    $relDir = 'uploads/' . $faculty_id . '/' . $document_type . ($subtype ? '/' . $subtype : '');
    $destDir = ROOT_PATH . '/' . $relDir;
    if (!is_dir($destDir) && !@mkdir($destDir, 0775, true) && !is_dir($destDir)) {
        error_log("Storing upload failed: could not create {$destDir} (is uploads/ writable by the web server?)");
        return null;
    }
    $filename = basename($source_rel);
    if (!is_file($srcAbs)) {
        error_log("Storing upload failed: the scanned file {$srcAbs} is missing");
        return null;
    }
    if (!@rename($srcAbs, $destDir . '/' . $filename)) {
        error_log("Storing upload failed: could not move {$srcAbs} to {$destDir}/ (" . (error_get_last()['message'] ?? 'unknown error') . ')');
        return null;
    }
    return $relDir . '/' . $filename;
}

/**
 * Tell every Program Chair and Dean (and the Admin, as before) that a
 * faculty member uploaded a document to their 201 file. $extra_lines
 * are appended (e.g. that the upload also updated their PDS). $archived_date:
 * the document's own date when it went straight to Archived Docs (archive_at_upload()).
 */
function notify_new_upload(PDO $pdo, array $uploader, string $document_type, ?string $subtype, int $request_id, array $extra_lines = [], bool $reupload = false, ?string $flag_reason = null, ?string $archived_date = null): void {
    $when = new DateTime('now', new DateTimeZone('Asia/Manila'));

    // Admin: full details, as before. The upload is in 201-Files already; a flagged one is also in Manage Document.
    $msg = "New Document Uploaded\n"
         . "Faculty: {$uploader['full_name']}\n"
         . "Document Type: " . document_type_label($document_type, $subtype) . "\n"
         . "Date Uploaded: " . $when->format('F j, Y, g:i A') . "\n"
         . match (true) {
             $archived_date !== null => 'It is dated ' . date('F j, Y', strtotime($archived_date)) . ', more than ' . archive_period_label()
                                        . ' ago, so it was saved to Archived Docs.',
             $flag_reason !== null   => 'It is in 201-Files, and flagged for checking in Manage Document: ' . document_flag_text($flag_reason) . '.',
             default                 => 'It is in 201-Files.',
         };
    foreach ($extra_lines as $line) {
        $msg .= "\n" . $line;
    }
    notify_role($pdo, 'admin', $msg, $request_id);

    // Program Chair / Dean: only who uploaded and when -- no document details
    // and no link (request_id NULL): they don't have access to faculty files.
    if (($uploader['role'] ?? '') === 'faculty') {
        $type = employment_type_label($uploader['employment_type'] ?? null);
        $short = $uploader['full_name'] . ($type ? " ({$type})" : '')
               . ($reupload ? ' re-uploaded a file to their 201 file.' : ' uploaded a file to their 201 file.')
               . ($archived_date !== null ? ' (An older document: saved to their Archived Docs.)' : '')
               . ' – ' . str_replace('Sep ', 'Sept ', $when->format('M j, Y, g:i A'));
        foreach (upload_notification_recipients($pdo, $uploader) as $uid) {
            notify($pdo, $uid, $short, null);
        }
    }
}

/**
 * Who hears about a faculty upload: the active Program Chair(s) of the
 * faculty member's program and the active Dean(s) of their college.
 * A faculty member with no program / college set notifies nobody at that level.
 * @return int[] user ids
 */
function upload_notification_recipients(PDO $pdo, array $faculty): array {
    $ids = [];
    if (!empty($faculty['program']) && !empty($faculty['college'])) {
        $stmt = $pdo->prepare("SELECT user_id FROM users WHERE role = 'program_chair' AND is_active = 1 AND program = ? AND college = ?");
        $stmt->execute([$faculty['program'], $faculty['college']]);
        $ids = array_merge($ids, $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
    if (!empty($faculty['college'])) {
        $stmt = $pdo->prepare("SELECT user_id FROM users WHERE role = 'dean' AND is_active = 1 AND college = ?");
        $stmt->execute([$faculty['college']]);
        $ids = array_merge($ids, $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
    return array_values(array_unique(array_map('intval', $ids)));
}

/**
 * The program everyone in a program belongs to when PROGRAMS (config.php)
 * has just one: its key, or null when there is a choice. Forms then set it
 * automatically and show it as text, and program filters are hidden.
 */
function only_program(): ?string {
    return count(PROGRAMS) === 1 ? (string)array_key_first(PROGRAMS) : null;
}

/** A program's name: its label, or the stored key for one no longer in PROGRAMS (e.g. an old BSCS), or ''. */
function program_label(?string $program): string {
    return $program === null || $program === '' ? '' : (PROGRAMS[$program]['label'] ?? $program);
}

function employment_type_label(?string $type): string {
    return ['full_time' => 'Full-time', 'part_time' => 'Part-time'][$type] ?? '';
}

/** Mark one of the user's own notifications as read. */
function mark_notification_read(PDO $pdo, int $user_id, int $notification_id): void {
    $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE notification_id = ? AND user_id = ?")->execute([$notification_id, $user_id]);
}

/** MIME type served for a stored document, from its file extension. */
function document_mime_type(string $file_path): string {
    $types = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp',
              'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
    return $types[strtolower(pathinfo($file_path, PATHINFO_EXTENSION))] ?? 'application/octet-stream';
}

/** A file bigger than the database can store (max_allowed_packet, and the chunk table isn't set up yet). */
class DocumentFileTooLarge extends RuntimeException {
    public function __construct(public readonly int $size, public readonly int $max) {
        parent::__construct("File of {$size} bytes exceeds what the database accepts in one query ({$max} bytes; max_allowed_packet) "
            . '-- apply database/migration_document_file_chunks.sql to store files in pieces');
    }
}

/** Whether database/migration_document_file_chunks.sql has been applied (files are stored in pieces). */
function document_file_chunks_ready(PDO $pdo): bool {
    static $ready = null;
    if ($ready === null) {
        try {
            $ready = (bool)$pdo->query(db_is_pgsql() ? "SELECT to_regclass('document_file_chunks') IS NOT NULL" : "SHOW TABLES LIKE 'document_file_chunks'")->fetchColumn();
        } catch (PDOException $e) {
            $ready = false;
        }
    }
    return $ready;
}

/**
 * Save a document's file in the database, so it survives the hosting server
 * wiping its disk. $abs_path is the file on disk.
 *
 * MySQL refuses any query (and any row it sends back) bigger than
 * max_allowed_packet -- 1 MB on XAMPP -- so the file is written to
 * document_file_chunks in pieces of half that size; document_files keeps
 * its type and size, with an empty data column. Before
 * migration_document_file_chunks.sql is applied, the whole file goes into
 * document_files.data, which only works for files that fit in one packet
 * (DocumentFileTooLarge otherwise). Throws if the file can't be read or a
 * query fails.
 */
function store_document_file(PDO $pdo, int $document_id, string $abs_path, string $file_path): void {
    $bytes = @file_get_contents($abs_path);
    if ($bytes === false) {
        throw new RuntimeException("Could not read $abs_path to store it");
    }
    $size = strlen($bytes);
    // PostgreSQL has no packet limit: 0 = none (pieces of 8 MB below)
    $limit = db_is_pgsql() ? 0 : (int)$pdo->query("SELECT @@max_allowed_packet")->fetchColumn();
    $chunked = document_file_chunks_ready($pdo);
    if (!$chunked) {
        // Room for the rest of the query: 1 MB, or an eighth of a small limit
        $max = $limit > 0 ? $limit - min(1024 * 1024, intdiv($limit, 8)) : PHP_INT_MAX;
        if ($size > $max) {
            throw new DocumentFileTooLarge($size, $max);
        }
    }
    $own = !$pdo->inTransaction();   // all or nothing: never half a file
    if ($own) { $pdo->beginTransaction(); }
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO document_files (document_id, mime_type, file_size, data) VALUES (?, ?, ?, ?) "
            . sql_on_duplicate('document_id', 'mime_type = ' . sql_inserted('mime_type') . ', file_size = ' . sql_inserted('file_size')
                                              . ', data = ' . sql_inserted('data'))
        );
        $stmt->bindValue(1, $document_id, PDO::PARAM_INT);
        $stmt->bindValue(2, document_mime_type($file_path));
        $stmt->bindValue(3, $size, PDO::PARAM_INT);
        $stmt->bindValue(4, $chunked ? '' : $bytes, PDO::PARAM_LOB);
        $stmt->execute();
        if ($chunked) {
            $pdo->prepare("DELETE FROM document_file_chunks WHERE document_id = ?")->execute([$document_id]);
            $piece = max(64 * 1024, min(8 * 1024 * 1024, intdiv($limit > 0 ? $limit : 16 * 1024 * 1024, 2)));
            $insert = $pdo->prepare("INSERT INTO document_file_chunks (document_id, seq, data) VALUES (?, ?, ?)");
            for ($seq = 0, $offset = 0; $offset < $size || $seq === 0; $seq++, $offset += $piece) {
                $insert->bindValue(1, $document_id, PDO::PARAM_INT);
                $insert->bindValue(2, $seq, PDO::PARAM_INT);
                $insert->bindValue(3, substr($bytes, $offset, $piece), PDO::PARAM_LOB);
                $insert->execute();
            }
        }
        if ($own) { $pdo->commit(); }
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
}

/**
 * A document's stored file: ['mime_type', 'file_size'] or null when it isn't
 * in the database. Read the bytes with document_file_bytes() or
 * document_file_each().
 */
function document_file_info(PDO $pdo, int $document_id): ?array {
    $stmt = $pdo->prepare("SELECT mime_type, file_size, LENGTH(data) AS inline_size FROM document_files WHERE document_id = ?");
    $stmt->execute([$document_id]);
    return $stmt->fetch() ?: null;
}

/** Send a stored file's bytes to $out piece by piece (no row bigger than one packet is ever read). */
function document_file_each(PDO $pdo, int $document_id, array $info, callable $out): void {
    if ((int)$info['inline_size'] > 0) {   // saved in one row (before files were stored in pieces)
        $stmt = $pdo->prepare("SELECT data FROM document_files WHERE document_id = ?");
        $stmt->execute([$document_id]);
        $out(db_blob($stmt->fetchColumn()));
        return;
    }
    if (!document_file_chunks_ready($pdo)) {
        return;
    }
    $stmt = $pdo->prepare("SELECT data FROM document_file_chunks WHERE document_id = ? ORDER BY seq");
    $stmt->execute([$document_id]);
    while (($piece = $stmt->fetchColumn()) !== false) {
        $out(db_blob($piece));
    }
}

/** A stored file's bytes, or null when it isn't in the database. */
function document_file_bytes(PDO $pdo, int $document_id): ?string {
    $info = document_file_info($pdo, $document_id);
    if ($info === null) {
        return null;
    }
    $bytes = '';
    document_file_each($pdo, $document_id, $info, function (string $piece) use (&$bytes) { $bytes .= $piece; });
    return $bytes;
}

/**
 * Who may open a 201-file document: its owner (posted, waiting for review,
 * archived, or in Recently Deleted -- to preview it before restoring), or
 * the Admin. Program Chairs and Deans can see faculty profiles, archive
 * lists and report details, but can't view, download or change anyone
 * else's files.
 */
function can_access_document(array $user, array $document): bool {
    if ($user['role'] === 'admin') {
        return true;   // including archived / deleted documents, to review or restore them
    }
    return (int)$document['faculty_id'] === (int)$user['user_id']
        && in_array($document['status'] ?? 'active', ['active', 'for_review', 'archived', 'deleted'], true);
}

/**
 * Record that a document was opened (document.php). The auto-archive moves
 * posted documents nobody has opened in ARCHIVE_UNVIEWED_YEARS years.
 * Opening an archived document is recorded too but does not restore it.
 */
function mark_document_viewed(PDO $pdo, int $document_id): void {
    try {
        $pdo->prepare("UPDATE documents SET last_viewed_at = NOW() WHERE document_id = ?")->execute([$document_id]);
    } catch (PDOException $e) {
        error_log('Recording a document view failed: ' . $e->getMessage());   // migration not applied yet
    }
}

// ---------------------------------------------------------------------
// Deleting / archiving documents (soft delete -- rows are never removed).
// documents.status: 'active' (Posted) everywhere; 'for_review' in Manage
// Document and its owner's Pending review tab; 'archived' and 'deleted' on
// the Admin's Archived Docs page (archived also in the owner's Archived
// Docs). A faculty member may delete their own document within
// FACULTY_DELETE_WINDOW_HOURS of uploading it; the Admin may archive,
// delete or restore any document. Done through
// document_action.php (POST + CSRF token).
// ---------------------------------------------------------------------

/** Reasons offered when deleting / archiving a document. */
function document_removal_reasons(): array {
    return ['Wrong file', 'Duplicate', 'Outdated', 'Other'];
}

/**
 * SQL for the seconds left in the owner's delete window of documents row
 * $alias (0 or negative once it has closed). Worked out by the database,
 * comparing filed_at with NOW() on the same clock, so it's right whatever
 * time zone the database server runs in; deadlines are then shown in
 * Asia/Manila (delete_deadline_label()).
 */
function delete_window_sql(string $alias = 'd'): string {
    return sql_seconds_until("{$alias}.filed_at + INTERVAL '" . (int)FACULTY_DELETE_WINDOW_HOURS . "' HOUR");
}

/**
 * Whether $user may delete $document themselves: it's theirs, posted or
 * still waiting for review, and inside the delete window. $document needs
 * delete_seconds_left (selected with delete_window_sql()). The Admin uses
 * the admin actions instead.
 */
function faculty_can_delete(array $user, array $document): bool {
    return $user['role'] !== 'admin'
        && (int)$document['faculty_id'] === (int)$user['user_id']
        && in_array($document['status'] ?? '', ['active', 'for_review'], true)
        && (int)($document['delete_seconds_left'] ?? 0) > 0;
}

/** "Oct 3, 2026, 9:15 AM" (Asia/Manila) -- when the delete window closes. */
function delete_deadline_label(int $seconds_left): string {
    return date('M j, Y, g:i A', time() + $seconds_left);
}

/** Where a deleted document's local file copy is kept: uploads/12/TOR/x.pdf -> uploads/_deleted/12/TOR/x.pdf */
function deleted_file_path(string $file_path): string {
    return str_starts_with($file_path, 'uploads/_deleted/') ? $file_path : 'uploads/_deleted/' . preg_replace('#^uploads/#', '', $file_path);
}

/** The reverse of deleted_file_path(), for a restore. */
function restored_file_path(string $file_path): string {
    return str_starts_with($file_path, 'uploads/_deleted/') ? 'uploads/' . substr($file_path, strlen('uploads/_deleted/')) : $file_path;
}

/**
 * Move a document's local file copy (paths relative to ROOT_PATH) and
 * return the path to store. A copy that's not on disk (the server's disk
 * was wiped -- the database copy in document_files is what's served) just
 * gets the new path; one that can't be moved keeps its old path.
 */
function move_document_file(string $from, string $to): string {
    $src = ROOT_PATH . '/' . $from;
    if ($from === $to || !is_file($src)) {
        return $to;
    }
    $dir = dirname(ROOT_PATH . '/' . $to);
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
    return @rename($src, ROOT_PATH . '/' . $to) ? $to : $from;
}

/**
 * Archive, delete or restore one document. $doc is its documents row;
 * $action 'archive' (posted only), 'delete' (posted or archived ->
 * Recently Deleted) or 'restore':
 *   archived -> posted
 *   deleted  -> back where it was when deleted (status_before_delete:
 *               201-Files or Archived Docs), with its details;
 *               only within DELETED_RETENTION_DAYS (deleted_restore_open())
 * Deleting moves the local file into uploads/_deleted/; restoring moves it
 * back. Restoring to 201-Files counts as a view (last_viewed_at), so the
 * auto-archive doesn't take it straight back.
 * Returns false if the action doesn't apply to the document's current status.
 */
function change_document_status(PDO $pdo, array $doc, string $action, int $actor_id, ?string $reason): bool {
    $status = $doc['status'];
    if ($action === 'archive' && $status === 'active') {
        $pdo->prepare("UPDATE documents SET status = 'archived', archived_at = NOW(), archived_by = ?, archive_reason = ? WHERE document_id = ?")
            ->execute([$actor_id, $reason, $doc['document_id']]);
        return true;
    }
    if ($action === 'delete' && in_array($status, ['active', 'for_review', 'archived'], true)) {
        $path = move_document_file($doc['file_path'], deleted_file_path($doc['file_path']));
        $pdo->prepare(
            "UPDATE documents SET status_before_delete = status, status = 'deleted', file_path = ?,
                    deleted_at = NOW(), deleted_by = ?, delete_reason = ?, purge_warned_at = NULL
             WHERE document_id = ?"
        )->execute([$path, $actor_id, $reason, $doc['document_id']]);
        return true;
    }
    if ($action === 'restore' && $status === 'deleted') {
        if (!deleted_restore_open($doc)) {
            return false;   // past the restore window: waiting for the permanent deletion
        }
        $path = move_document_file($doc['file_path'], restored_file_path($doc['file_path']));
        // MySQL applies SET left to right: work out the place it returns to before clearing status_before_delete
        // (PostgreSQL reads every value from before the update, which gives the same result)
        $pdo->prepare(
            "UPDATE documents SET
                    last_viewed_at = CASE WHEN status_before_delete = 'archived' THEN last_viewed_at ELSE NOW() END,
                    status = CASE WHEN status_before_delete = 'archived' THEN 'archived' ELSE 'active' END,
                    file_path = ?, deleted_at = NULL, deleted_by = NULL, delete_reason = NULL,
                    status_before_delete = NULL, purge_warned_at = NULL
             WHERE document_id = ? AND status = 'deleted'"
        )->execute([$path, $doc['document_id']]);
        return true;
    }
    if ($action === 'restore' && $status === 'archived') {
        $path = move_document_file($doc['file_path'], restored_file_path($doc['file_path']));
        $pdo->prepare(
            "UPDATE documents SET status = 'active', posted_at = COALESCE(posted_at, filed_at), file_path = ?,
                    archive_exempt = CASE WHEN archive_reason LIKE ? THEN 1 ELSE archive_exempt END,   -- archived for its date: not again
                    archived_at = NULL, archived_by = NULL, archive_reason = NULL,
                    deleted_at = NULL, deleted_by = NULL, delete_reason = NULL,
                    last_viewed_at = NOW()
             WHERE document_id = ?"
        )->execute([$path, 'Auto: document dated%', $doc['document_id']]);
        return true;
    }
    return false;
}

// ---------------------------------------------------------------------
// Recently Deleted (recently_deleted.php). A deleted document can be
// restored for DELETED_RETENTION_DAYS from deleted_at -- by the Admin, or
// by its owner if they deleted it themselves. Afterwards the daily check
// (run_daily_deleted_cleanup()) deletes it permanently: the Admin can also
// do that at once ("Delete permanently now"). Permanent deletion removes
// the file (disk copy and document_files), the documents row, its upload
// log row (submission_requests) and the notifications linked to it;
// expiration_alerts_sent rows go with it (ON DELETE CASCADE) and PDS
// entries made from it are kept (source_document_id ON DELETE SET NULL).
// Only the activity log keeps a record. Dates are Asia/Manila.
// ---------------------------------------------------------------------

/** When the restore window of a document deleted at $deleted_at ends: DELETED_RETENTION_DAYS later (Asia/Manila). */
function deleted_purge_at(string $deleted_at): DateTimeImmutable {
    return (new DateTimeImmutable($deleted_at))->modify('+' . (int)DELETED_RETENTION_DAYS . ' days');
}

/** Whole days left before the permanent deletion (0 = today or overdue). */
function deleted_days_left(string $deleted_at): int {
    return max(0, days_until(deleted_purge_at($deleted_at)->format('Y-m-d')));
}

/** "Deletes permanently in 12 days" / "in 1 day" / "today". */
function deleted_days_left_label(int $days): string {
    return 'Deletes permanently ' . ($days <= 0 ? 'today' : 'in ' . $days . ' day' . ($days === 1 ? '' : 's'));
}

/** Whether a deleted document is still inside its restore window. */
function deleted_restore_open(array $doc): bool {
    return ($doc['status'] ?? '') === 'deleted'
        && (empty($doc['deleted_at']) || deleted_purge_at($doc['deleted_at']) > new DateTimeImmutable('now'));
}

/**
 * Whether $user may restore a deleted document: the Admin any, its owner
 * only one they deleted themselves (one the Admin deleted, the Admin
 * restores). $doc needs faculty_id, status, deleted_at, deleted_by.
 */
function can_restore_deleted(array $user, array $doc): bool {
    if (!deleted_restore_open($doc)) {
        return false;
    }
    if ($user['role'] === 'admin') {
        return true;
    }
    return (int)$doc['faculty_id'] === (int)$user['user_id'] && (int)($doc['deleted_by'] ?? 0) === (int)$user['user_id'];
}

/** SQL condition: deleted documents whose restore window is over. */
function deleted_expired_sql(string $alias = 'd'): string {
    return "{$alias}.status = 'deleted' AND {$alias}.deleted_at < NOW() - INTERVAL '" . (int)DELETED_RETENTION_DAYS . "' DAY";
}

/**
 * Delete one document for good (it must be in Recently Deleted; with
 * $expired_only, only once its restore window is over). $actor: the Admin
 * who chose "Delete permanently now", or null for the daily cleanup
 * (logged as "system"). The database rows go in one transaction; the local
 * file is removed after it commits. Returns false if the document wasn't
 * (or is no longer) there to delete.
 */
function permanently_delete_document(PDO $pdo, int $document_id, ?array $actor, bool $expired_only = false): bool {
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            "SELECT d.document_id, d.request_id, d.faculty_id, d.document_type, d.document_subtype, d.file_path, d.title,
                    d.deleted_at, d.delete_reason, u.full_name
             FROM documents d JOIN users u ON u.user_id = d.faculty_id
             WHERE d.document_id = ? AND " . ($expired_only ? deleted_expired_sql('d') : "d.status = 'deleted'") . " FOR UPDATE"
        );
        $stmt->execute([$document_id]);
        $doc = $stmt->fetch();
        if (!$doc) {
            $pdo->rollBack();
            return false;
        }
        // Notifications about this upload, then the document (document_files, document_file_chunks and
        // expiration_alerts_sent follow by ON DELETE CASCADE; PDS entries made from it keep their data, unlinked), then its upload log
        $pdo->prepare("DELETE FROM notifications WHERE request_id = ?")->execute([$doc['request_id']]);
        $pdo->prepare("DELETE FROM document_files WHERE document_id = ?")->execute([$document_id]);
        $pdo->prepare("DELETE FROM documents WHERE document_id = ?")->execute([$document_id]);
        $pdo->prepare("DELETE FROM submission_requests WHERE request_id = ? AND NOT EXISTS (SELECT 1 FROM documents WHERE request_id = ?)")
            ->execute([$doc['request_id'], $doc['request_id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }

    // The local copy (uploads/_deleted/...), only ever inside uploads/
    $abs = realpath(ROOT_PATH . '/' . $doc['file_path']);
    $uploads = realpath(UPLOADS_PATH);
    if ($abs && $uploads && str_starts_with($abs, $uploads . DIRECTORY_SEPARATOR) && is_file($abs) && !@unlink($abs)) {
        error_log("Permanent deletion of document {$document_id}: the file {$doc['file_path']} could not be removed from the disk");
    }

    $what = "document #{$document_id} \"" . basename($doc['file_path']) . '" (' . document_type_label($doc['document_type'], $doc['document_subtype'])
          . (trim((string)$doc['title']) !== '' ? ', ' . $doc['title'] : '') . ") of {$doc['full_name']}, deleted "
          . date('M j, Y g:i A', strtotime($doc['deleted_at'])) . ($doc['delete_reason'] ? " (reason: {$doc['delete_reason']})" : '');
    if ($actor) {
        log_activity($pdo, (int)$actor['user_id'], 'DOCUMENT_PURGE', "Permanently deleted {$what} from Recently Deleted. The file and record were removed.", $actor['role']);
    } else {
        log_activity($pdo, null, 'DOCUMENT_AUTO_PURGE', "Permanently deleted {$what} -- " . (int)DELETED_RETENTION_DAYS
            . ' days in Recently Deleted had passed. The file and record were removed.', 'system');
    }
    return true;
}

/**
 * The daily Recently Deleted check: permanently delete every document whose
 * restore window is over, then warn owners whose deleted documents go in
 * DELETED_PURGE_WARNING_DAYS days (once per deletion). A document that
 * fails is logged and the others still run; the run then counts as failed,
 * so run_once_a_day() tries again on the next page load.
 * @return array{purged: int, warned: int, document_ids: int[]}
 */
function cleanup_recently_deleted(PDO $pdo): array {
    $ids = $pdo->query("SELECT d.document_id FROM documents d WHERE " . deleted_expired_sql('d') . " ORDER BY d.deleted_at")->fetchAll(PDO::FETCH_COLUMN);
    $purged = [];
    $failed = [];
    foreach ($ids as $id) {
        try {
            if (permanently_delete_document($pdo, (int)$id, null, true)) { $purged[] = (int)$id; }
        } catch (Throwable $e) {
            error_log("Permanent deletion of document {$id} failed: " . $e->getMessage());
            $failed[] = (int)$id;
        }
    }

    $warned = 0;
    if ((int)DELETED_PURGE_WARNING_DAYS > 0) {
        $stmt = $pdo->prepare(
            "SELECT d.document_id, d.faculty_id, d.document_type, d.document_subtype, d.file_path, d.title, d.deleted_at, d.deleted_by
             FROM documents d
             WHERE d.status = 'deleted' AND d.purge_warned_at IS NULL
               AND d.deleted_at < NOW() - INTERVAL '" . ((int)DELETED_RETENTION_DAYS - (int)DELETED_PURGE_WARNING_DAYS) . "' DAY
               AND NOT (" . deleted_expired_sql('d') . ")"
        );
        $stmt->execute();
        $claim = $pdo->prepare("UPDATE documents SET purge_warned_at = NOW() WHERE document_id = ? AND purge_warned_at IS NULL");
        foreach ($stmt->fetchAll() as $d) {
            $claim->execute([$d['document_id']]);
            if ($claim->rowCount() !== 1) { continue; }   // warned by a run at the same moment
            $own = (int)$d['deleted_by'] === (int)$d['faculty_id'];
            notify($pdo, (int)$d['faculty_id'], 'Your deleted ' . document_title($d) . ' (' . document_type_label($d['document_type'], $d['document_subtype'])
                . ') will be permanently deleted on ' . deleted_purge_at($d['deleted_at'])->format('F j, Y') . '. '
                . ($own ? 'Restore it from Recently Deleted before then if you still need it.' : 'If you still need it, ask the Admin to restore it before then.'),
                null, 'recently_deleted.php');
            $warned++;
        }
    }
    if ($failed) {
        throw new RuntimeException('Could not permanently delete document(s) #' . implode(', #', $failed) . '; deleted: ' . count($purged));
    }
    return ['purged' => count($purged), 'warned' => $warned, 'document_ids' => $purged];
}

/** cleanup_recently_deleted() at most once a day (run_once_a_day()); $force: cron/auto_archive.php. */
function run_daily_deleted_cleanup(PDO $pdo, bool $force = false): ?array {
    return run_once_a_day($pdo, 'deleted_cleanup_last_run', 'cleanup_recently_deleted', $force);
}

// ---------------------------------------------------------------------
// Deleted accounts (Manage Faculty > Delete). A deleted account can't log
// in and can be restored for DELETED_RETENTION_DAYS. After that the daily
// cleanup deletes it permanently: the login is removed for good (email
// released, password replaced by an unusable one, picture and contact
// number removed, its notifications and reminders deleted) and its posted
// and For Review documents move to Archived Docs, labeled with the former
// faculty member's name. The users row stays (purged_at): documents, the
// PDS and the activity log point to it, and deleting it would delete those
// official 201-file records with it (ON DELETE CASCADE).
// ---------------------------------------------------------------------

/** Permanently delete one account past its restore window. Returns how many documents moved to Archived Docs, or null if it doesn't qualify. */
function purge_deleted_account(PDO $pdo, int $user_id): ?int {
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            "SELECT user_id, full_name, role, email, deleted_at, delete_reason FROM users
             WHERE user_id = ? AND deleted_at IS NOT NULL AND purged_at IS NULL
               AND deleted_at < NOW() - INTERVAL '" . (int)DELETED_RETENTION_DAYS . "' DAY FOR UPDATE"
        );
        $stmt->execute([$user_id]);
        $u = $stmt->fetch();
        if (!$u) {
            $pdo->rollBack();
            return null;
        }
        $reason = mb_substr("Former faculty: {$u['full_name']}. The account was deleted permanently on " . date('F j, Y')
            . '; the document is kept as an official 201-file record.', 0, 255);
        $stmt = $pdo->prepare("UPDATE documents SET status = 'archived', archived_at = NOW(), archived_by = NULL, archive_reason = ?
                               WHERE faculty_id = ? AND status IN ('active', 'for_review')");
        $stmt->execute([$reason, $user_id]);
        $moved = $stmt->rowCount();
        foreach (['notifications', 'ai_reminders', 'ai_reminder_checks', 'profile_pictures', 'login_otps'] as $table) {
            $pdo->prepare("DELETE FROM {$table} WHERE user_id = ?")->execute([$user_id]);
        }
        $pdo->prepare(
            "UPDATE users SET email = ?, email_verified_at = NULL, username = NULL, password_hash = ?, profile_picture = NULL, contact_number = NULL, must_change_password = 0,
                              is_active = 0, purged_at = NOW() WHERE user_id = ?"
        )->execute(['deleted-' . $user_id . '-' . bin2hex(random_bytes(4)) . '@deleted.invalid',
                    password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT), $user_id]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
    log_activity($pdo, null, 'ACCOUNT_PURGE', "Deleted account #{$user_id} {$u['full_name']} (" . str_replace('_', ' ', $u['role']) . ", {$u['email']}) permanently -- "
        . (int)DELETED_RETENTION_DAYS . ' days after it was deleted (' . date('F j, Y', strtotime($u['deleted_at'])) . ', reason: ' . $u['delete_reason'] . '). '
        . "{$moved} document(s) moved to Archived Docs under their name; the email can be used for a new account.", 'system');
    return $moved;
}

/** Every deleted account past its restore window, deleted permanently (purge_deleted_account()). */
function cleanup_deleted_accounts(PDO $pdo): array {
    try {
        $ids = $pdo->query("SELECT user_id FROM users WHERE deleted_at IS NOT NULL AND purged_at IS NULL
                            AND deleted_at < NOW() - INTERVAL '" . (int)DELETED_RETENTION_DAYS . "' DAY")->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        return ['purged' => 0, 'user_ids' => []];   // migration not applied yet
    }
    $purged = [];
    foreach ($ids as $id) {
        try {
            if (purge_deleted_account($pdo, (int)$id) !== null) { $purged[] = (int)$id; }
        } catch (Throwable $e) {
            error_log("Permanent deletion of account {$id} failed: " . $e->getMessage());
        }
    }
    return ['purged' => count($purged), 'user_ids' => $purged];
}

/** cleanup_deleted_accounts() at most once a day (run_once_a_day()). $force: cron/auto_archive.php. */
function run_daily_account_cleanup(PDO $pdo, bool $force = false): ?array {
    return run_once_a_day($pdo, 'account_cleanup_last_run', 'cleanup_deleted_accounts', $force);
}

/** URL that serves a 201-file document through the access check (document.php). */
function document_url(int $document_id): string {
    return BASE_URL . '/document.php?id=' . $document_id;
}

/**
 * Store a faculty member's upload: move the scan into the repository, log
 * it in submission_requests as 'uploaded' and insert the documents row,
 * posted to 201-Files right away (status 'active') -- or, with
 * $meta['archive'] (archive_at_upload()), to Archived Docs; $meta['flag_reason']
 * flags it for the Admin to check in Manage Document (document_flag_for_upload()).
 * $ocr is the OcrProcessor::process() result
 * from the preview step (empty for a manual entry); $meta holds subtype /
 * academic_year / semester / period_year / expiration, entry_method
 * ('ocr' / 'manual') and details (document_details_clean()) -- the seminar /
 * training details and date issued. The caller notifies the Admin, Chair
 * and Dean (notify_new_upload) once any PDS update has been applied.
 * Returns [request_id, document_id], or null if the file could not be stored.
 */
function store_faculty_upload(PDO $pdo, array $faculty, string $document_type, array $meta, string $scan_rel, array $ocr, ?array &$failure = null): ?array {
    $failure = null;   // on failure: ['reason' => 'move' | 'too_large' | 'database', 'max' => bytes (too_large)]
    $subtype = $meta['subtype'] ?? null;
    $filePath = move_to_repository((int)$faculty['user_id'], $document_type, $scan_rel, $subtype);   // logs why it failed
    if ($filePath === null) {
        $failure = ['reason' => 'move'];
        return null;
    }

    try {
        $pdo->beginTransaction();
        $pdo->prepare(
            "INSERT INTO submission_requests (faculty_id, document_type_hint, expiration_date_hint, temp_file_path, status)
             VALUES (?, ?, ?, ?, 'uploaded')"
        )->execute([$faculty['user_id'], $document_type, $meta['expiration'] ?? null, $filePath]);
        $request_id = (int)$pdo->lastInsertId();

        $check = $meta['classification'] ?? null;   // classification_check() result
        $details = $meta['details'] ?? document_details_clean([]);
        $pdo->prepare(
            "INSERT INTO documents (request_id, faculty_id, document_type, document_subtype, academic_year, semester, period_year,
                                    title, date_start, date_end, venue, conducted_by, training_type, training_level, hours, date_issued,
                                    file_path, expiration_date, ocr_extracted_text, ocr_matched_name, ocr_confidence_note,
                                    confidence_score, predicted_category, chosen_category, is_low_confidence, flag_reason,
                                    status, archived_at, archive_reason, entry_method, posted_at, last_viewed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())"
        )->execute([
            $request_id, $faculty['user_id'], $document_type, $subtype,
            $meta['academic_year'] ?? null, $meta['semester'] ?? null, $meta['period_year'] ?? null,
            ...array_values($details),
            $filePath, $meta['expiration'] ?? null,
            $ocr['text'] ?? null, $ocr['matched_name'] ?? null,
            isset($ocr['confidence_note']) ? mb_substr($ocr['confidence_note'], 0, 255) : null,
            $check['score'] ?? null, $check['predicted'] ?? null, $document_type, !empty($check['low']) ? 1 : 0, $meta['flag_reason'] ?? null,
            // Dated ARCHIVE_UNVIEWED_YEARS+ years ago (archive_at_upload()): straight to Archived Docs
            !empty($meta['archive']) ? 'archived' : 'active',
            !empty($meta['archive']) ? date('Y-m-d H:i:s') : null,
            !empty($meta['archive']) ? upload_archive_reason() : null,
            ($meta['entry_method'] ?? 'ocr') === 'manual' ? 'manual' : 'ocr',
        ]);
        $document_id = (int)$pdo->lastInsertId();
        // The file itself goes into the database too -- the disk copy doesn't survive a redeploy
        store_document_file($pdo, $document_id, ROOT_PATH . '/' . $filePath, $filePath);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        // Put the scan back so the faculty member can retry from the preview.
        @rename(ROOT_PATH . '/' . $filePath, ROOT_PATH . '/' . $scan_rel);
        // The real cause, for the PHP error log (the user sees a friendly message)
        error_log(sprintf('Storing upload failed: %s: %s in %s:%d (faculty %d, %s, %s)', get_class($e), $e->getMessage(),
            $e->getFile(), $e->getLine(), (int)$faculty['user_id'], $document_type, $filePath)
            . ($e instanceof PDOException ? ' SQLSTATE ' . ($e->errorInfo[0] ?? '?') . ' / driver error ' . ($e->errorInfo[1] ?? '?') : ''));
        $failure = $e instanceof DocumentFileTooLarge ? ['reason' => 'too_large', 'max' => $e->max] : ['reason' => 'database'];
        return null;
    }

    return [$request_id, $document_id];
}

/**
 * The "confident?" check (Fig. 5) on an OcrProcessor::process() result,
 * for the categories that apply to the uploader. Low confidence when the
 * score is under CONFIDENCE_THRESHOLD, nothing was detected, or the
 * detected category doesn't apply to them (e.g. IPCR for part-time) --
 * then nothing is pre-selected and the upload is flagged for Admin review.
 *
 * @return array{score: float, predicted: ?string, suggested: ?string, low: bool, candidates: string[]}
 *   suggested  category to pre-select (null when low)
 *   candidates up to 3 applicable categories with keyword hits, best first (hints)
 */
function classification_check(array $ocr, array $categories): array {
    $scores = $ocr['scores'] ?? [];
    if (!isset($ocr['confidence'])) {   // preview started before the confidence check existed
        require_once ROOT_PATH . '/ocr/OcrProcessor.php';
        $ocr['confidence'] = OcrProcessor::confidence($scores);
    }
    $score = (float)$ocr['confidence'];
    $predicted = $ocr['detected_type'] ?? null;
    $low = $predicted === null || !isset($categories[$predicted]) || $score < CONFIDENCE_THRESHOLD;

    arsort($scores);
    $candidates = array_slice(array_keys(array_filter($scores, fn($s, $type) => $s > 0 && isset($categories[$type]), ARRAY_FILTER_USE_BOTH)), 0, 3);

    return ['score' => $score, 'predicted' => $predicted, 'suggested' => $low ? null : $predicted, 'low' => $low, 'candidates' => $candidates];
}

/** "72%" for a stored confidence_score, or "Not scored" for NULL (documents uploaded before scoring). */
function confidence_label($score): string {
    return $score === null ? 'Not scored' : round((float)$score * 100) . '%';
}

// ---------------------------------------------------------------------
// Flagged for checking. Uploads are posted to 201-Files right away; the
// system flags the ones the Admin should check (documents.flag_reason, a
// comma-separated list of the codes below). The Admin corrects the type /
// details in Manage Document and marks it checked (reviewed_at). A flag
// never hides a document.
// ---------------------------------------------------------------------

/** Flag code => what it means. */
function document_flag_reasons(): array {
    return [
        'low_confidence' => 'the document type was detected with low confidence',
        'no_text'        => 'no text could be read from the file',
        'manual_entry'   => 'the details were entered manually',
        'type_changed'   => 'the uploader picked a different type than the one detected',
    ];
}

/** The flag codes that apply to a new upload (null when there's nothing to check). */
function document_flag_for_upload(array $check, bool $manual, string $chosen_type, ?string $ocr_text): ?string {
    $codes = [];
    if ($manual) {
        $codes[] = 'manual_entry';
    } else {
        if (trim((string)$ocr_text) === '') { $codes[] = 'no_text'; }
        if (!empty($check['low'])) { $codes[] = 'low_confidence'; }
        elseif (!empty($check['predicted']) && $check['predicted'] !== $chosen_type) { $codes[] = 'type_changed'; }
    }
    return $codes ? implode(',', $codes) : null;
}

/** "the document type was detected with low confidence; no text could be read from the file" */
function document_flag_text(?string $flag_reason): string {
    $labels = document_flag_reasons();
    $parts = array_map(fn($c) => $labels[$c] ?? $c, array_filter(explode(',', (string)$flag_reason)));
    return implode('; ', $parts);
}

/** Short labels for lists: "Low confidence", "No text read", "Entered manually", "Type changed by uploader". */
function document_flag_labels(?string $flag_reason): array {
    $short = ['low_confidence' => 'Low confidence', 'no_text' => 'No text read', 'manual_entry' => 'Entered manually', 'type_changed' => 'Type changed by uploader'];
    return array_map(fn($c) => $short[$c] ?? $c, array_filter(explode(',', (string)$flag_reason)));
}

/** SQL condition: document $a is flagged and not checked yet (and not archived / deleted). */
function document_flagged_sql(string $a = 'd'): string {
    return "{$a}.flag_reason IS NOT NULL AND {$a}.reviewed_at IS NULL AND {$a}.status = 'active'";
}

/** Whether a documents row is flagged and not checked yet. */
function document_is_flagged(array $doc): bool {
    return !empty($doc['flag_reason']) && empty($doc['reviewed_at']) && ($doc['status'] ?? '') === 'active';
}

/** Documents flagged for checking (Manage Document badge, dashboards); $faculty_id: one person's. */
function documents_flagged_count(PDO $pdo, ?int $faculty_id = null): int {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM documents d WHERE " . document_flagged_sql() . ($faculty_id === null ? '' : ' AND d.faculty_id = ?'));
        $stmt->execute($faculty_id === null ? [] : [$faculty_id]);
        return (int)$stmt->fetchColumn();
    } catch (PDOException $e) {
        return 0;   // migration not applied yet
    }
}

// ---------------------------------------------------------------------
// Semesters. The academic year starts in August: 1st semester Aug-Dec,
// 2nd semester Jan-May, Summer Jun-Jul.
// ---------------------------------------------------------------------

/** @return array{academic_year: string, semester: int} */
function current_academic_period(?int $timestamp = null): array {
    $ts = $timestamp ?? time();
    $y = (int)date('Y', $ts);
    $m = (int)date('n', $ts);
    if ($m >= 8) { return ['academic_year' => $y . '-' . ($y + 1), 'semester' => 1]; }
    if ($m <= 5) { return ['academic_year' => ($y - 1) . '-' . $y, 'semester' => 2]; }
    return ['academic_year' => ($y - 1) . '-' . $y, 'semester' => 3];
}

function semester_options(): array {
    return [1 => '1st Semester', 2 => '2nd Semester', 3 => 'Summer'];
}

/** Academic years offered in upload forms: next year back to 6 years ago. */
function academic_year_options(): array {
    $start = (int)explode('-', current_academic_period()['academic_year'])[0];
    $years = [];
    for ($y = $start + 1; $y >= $start - 6; $y--) { $years[] = $y . '-' . ($y + 1); }
    return $years;
}

/**
 * What a faculty member's 201 file should currently contain, and whether
 * it does: PDS for this year, FTA (and IPCR for full-time) for the
 * current semester -- during Summer, the 2nd semester just ended -- and
 * Contract of Service / Affidavit of Undertaking for part-time faculty.
 * @return array<int, array{label: string, done: bool, type: string}>
 */
function faculty_201_checklist(PDO $pdo, int $faculty_id, ?string $employment_type): array {
    require_once __DIR__ . '/pds.php';
    $period = current_academic_period();
    if ($period['semester'] === 3) { $period['semester'] = 2; }
    $sem_label = semester_options()[$period['semester']] . ', AY ' . $period['academic_year'];

    // An upload still waiting For Review counts as on file (it's uploaded; the Admin only checks its type).
    // $archived: an auto-archived copy still counts too -- for degrees and transcripts
    $has = function (string $type, ?array $sem = null, bool $archived = false) use ($pdo, $faculty_id): bool {
        $sql = "SELECT COUNT(*) FROM documents WHERE faculty_id = ? AND document_type = ? AND status IN ('active', 'for_review'" . ($archived ? ", 'archived'" : '') . ")";
        $params = [$faculty_id, $type];
        if ($sem) { $sql .= " AND academic_year = ? AND semester = ?"; array_push($params, $sem['academic_year'], $sem['semester']); }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn() > 0;
    };

    $items = [];
    if (faculty_has_pds(['employment_type' => $employment_type])) {   // no PDS for part-time faculty
        $pds = pds_status($pdo, $faculty_id);
        $items[] = ['label' => 'PDS updated for ' . $pds['year'], 'done' => $pds['current'], 'type' => 'PDS'];
    }
    $items[] = ['label' => 'FTA -- ' . $sem_label, 'done' => $has('FTA', $period), 'type' => 'FTA'];
    if ($employment_type !== 'part_time') {
        $items[] = ['label' => 'IPCR -- ' . $sem_label, 'done' => $has('IPCR', $period), 'type' => 'IPCR'];
    }
    if ($employment_type !== 'full_time') {
        $items[] = ['label' => 'Contract of Service on file', 'done' => $has('Contract'), 'type' => 'Contract'];
        $items[] = ['label' => 'Affidavit of Undertaking on file', 'done' => $has('Affidavit'), 'type' => 'Affidavit'];
    }
    $items[] = ['label' => 'Diploma on file (per educational attainment)', 'done' => $has('Diploma', null, true), 'type' => 'Diploma'];
    $items[] = ['label' => 'Transcript of Records on file', 'done' => $has('TOR', null, true), 'type' => 'TOR'];
    return $items;
}

/** "1st Semester, AY 2026-2027" / "2026" / "" for a documents row. */
function document_period_label(array $doc): string {
    if (!empty($doc['academic_year'])) {
        $sem = semester_options()[(int)($doc['semester'] ?? 0)] ?? null;
        return ($sem ? $sem . ', ' : '') . 'AY ' . $doc['academic_year'];
    }
    return !empty($doc['period_year']) ? (string)$doc['period_year'] : '';
}

/**
 * Newest document in each group (category + subtype; FTA / IPCR by
 * latest semester) -- these get the "Latest" badge. Older versions stay
 * in the list as history.
 * @param array $documents rows from `documents`
 * @return array<int,true> document_id => true
 */
function latest_document_ids(array $documents): array {
    usort($documents, 'compare_documents_latest_first');
    $latest = [];
    $seen = [];
    foreach ($documents as $d) {
        $group = $d['document_type'] . '|' . ($d['document_subtype'] ?? '');
        if (!isset($seen[$group])) {
            $seen[$group] = true;
            $latest[(int)$d['document_id']] = true;
        }
    }
    return $latest;
}

/** Sort order for one category: latest period first, then latest upload. */
function compare_documents_latest_first(array $a, array $b): int {
    return [$b['academic_year'] ?? '', (int)($b['semester'] ?? 0), (int)($b['period_year'] ?? 0), $b['filed_at']]
       <=> [$a['academic_year'] ?? '', (int)($a['semester'] ?? 0), (int)($a['period_year'] ?? 0), $a['filed_at']];
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
        if (is_file(ROOT_PATH . '/' . $filePath)) {
            try { store_document_file($pdo, (int)$pdo->lastInsertId(), ROOT_PATH . '/' . $filePath, $filePath); }
            catch (Throwable $e) { error_log('Storing filed request failed: ' . $e->getMessage()); }
        }
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

/**
 * The Faculty 201 File categories (from the interview). Every folder
 * view, upload form, filter and chart in the system is built from this
 * single list so the categories stay consistent everywhere. The key is
 * what's stored in documents.document_type.
 *
 *   applies_to  null = everyone, or 'full_time' / 'part_time' only
 *   frequency   'yearly' (PDS), 'semester' (FTA, IPCR) or null
 *   subtypes    optional sub-folders; default_subtype when OCR can't tell
 */
function document_categories(): array {
    return [
        'PDS'         => ['label' => 'Personal Data Sheet (PDS)', 'short' => 'PDS', 'icon' => 'fa-id-card', 'color' => 'navy',
                          'applies_to' => null, 'frequency' => 'yearly'],
        'Certificate' => ['label' => 'Certificates', 'short' => 'Certificates', 'icon' => 'fa-certificate', 'color' => 'gold',
                          'applies_to' => null, 'frequency' => null,
                          'subtypes' => ['Seminar' => 'Seminar', 'Training' => 'Training', 'Other' => 'Other Certificate'],
                          'default_subtype' => 'Other'],
        'Diploma'     => ['label' => 'Diploma', 'short' => 'Diploma', 'icon' => 'fa-graduation-cap', 'color' => 'navy',
                          'applies_to' => null, 'frequency' => null,
                          'subtypes' => ['Bachelor' => "Bachelor's Degree", 'Master' => "Master's Degree", 'Doctorate' => 'PhD / Doctorate'],
                          'default_subtype' => 'Bachelor'],
        'TOR'         => ['label' => 'Transcript of Records (TOR)', 'short' => 'TOR', 'icon' => 'fa-file-lines', 'color' => 'teal',
                          'applies_to' => null, 'frequency' => null],
        'FTA'         => ['label' => 'Final Teaching Assignment (FTA)', 'short' => 'FTA', 'icon' => 'fa-chalkboard-user', 'color' => 'blue',
                          'applies_to' => null, 'frequency' => 'semester'],
        'IPCR'        => ['label' => 'IPCR', 'short' => 'IPCR', 'icon' => 'fa-chart-line', 'color' => 'teal',
                          'applies_to' => 'full_time', 'frequency' => 'semester',
                          'description' => 'Individual Performance Commitment and Review'],
        'Contract'    => ['label' => 'Contract of Service', 'short' => 'Contract of Service', 'icon' => 'fa-file-signature', 'color' => 'blue',
                          'applies_to' => 'part_time', 'frequency' => null],
        'Affidavit'   => ['label' => 'Affidavit of Undertaking', 'short' => 'Affidavit of Undertaking', 'icon' => 'fa-stamp', 'color' => 'gold',
                          'applies_to' => 'part_time', 'frequency' => null],
        'Other'       => ['label' => 'Other Documents', 'short' => 'Other Documents', 'icon' => 'fa-folder', 'color' => 'navy',
                          'applies_to' => null, 'frequency' => null,
                          'subtypes' => ['Memo' => 'Memorandum / Memo', 'Notice' => 'Notice', 'Promotion' => 'Promotion', 'Other' => 'Other'],
                          'default_subtype' => 'Other'],
    ];
}

/** "Certificates > Seminar", "Diploma > Master's Degree", "FTA", ... */
function document_type_label(string $type, ?string $subtype = null): string {
    $cat = document_categories()[$type] ?? null;
    if (!$cat) { return $type; }
    $label = $cat['short'];
    if ($subtype && isset($cat['subtypes'][$subtype])) {
        $label .= ' > ' . $cat['subtypes'][$subtype];
    }
    return $label;
}

function faculty_employment_type(PDO $pdo, int $user_id): ?string {
    $stmt = $pdo->prepare("SELECT employment_type FROM users WHERE user_id = ?");
    $stmt->execute([$user_id]);
    return $stmt->fetchColumn() ?: null;
}

// ---------------------------------------------------------------------
// Who keeps a PDS -- the one place this rule lives. Faculty, Program Chairs
// and Deans keep a Personal Data Sheet, except part-time faculty
// (users.employment_type = 'part_time'), who have none: no My PDS, no PDS
// folder or version history, no PDS reminders, not counted in PDS numbers.
// A PDS they upload is saved as an ordinary document (Other). Changing to
// part-time hides an existing PDS without deleting it; changing back to
// full-time shows it again (or an empty one).
// ---------------------------------------------------------------------

/** Whether this account keeps a PDS. $user: a users row (role, employment_type); without 'role' only the employment type is checked. */
function faculty_has_pds(array $user): bool {
    if (isset($user['role']) && !in_array($user['role'], ['faculty', 'program_chair', 'dean'], true)) {
        return false;
    }
    return ($user['employment_type'] ?? null) !== 'part_time';
}

/** faculty_has_pds() for an account id. */
function user_has_pds(PDO $pdo, int $user_id): bool {
    static $known = [];
    if (!isset($known[$user_id])) {
        $stmt = $pdo->prepare("SELECT role, employment_type FROM users WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $row = $stmt->fetch();
        $known[$user_id] = $row ? faculty_has_pds($row) : false;
    }
    return $known[$user_id];
}

/** faculty_has_pds() as an SQL condition on the users alias $a. */
function sql_has_pds(string $a = 'u'): string {
    return "({$a}.role IN ('faculty', 'program_chair', 'dean') AND ({$a}.employment_type IS NULL OR {$a}.employment_type <> 'part_time'))";
}

/**
 * Categories that apply to a faculty member (IPCR is full-time only;
 * Contract of Service and Affidavit of Undertaking are part-time only; no
 * PDS for part-time faculty -- faculty_has_pds()).
 */
function categories_for_faculty(?string $employment_type): array {
    return array_filter(document_categories(), function ($cat, $key) use ($employment_type) {
        if ($key === 'PDS' && !faculty_has_pds(['employment_type' => $employment_type])) { return false; }
        return $cat['applies_to'] === null || $employment_type === null || $cat['applies_to'] === $employment_type;
    }, ARRAY_FILTER_USE_BOTH);
}

/**
 * Per-category filed-document counts for one faculty member. Always
 * returns all three category keys (zero-filled) so a folder tile never
 * has to guess at a missing key.
 */
function faculty_document_counts(PDO $pdo, int $faculty_id): array {
    $counts = array_fill_keys(array_keys(document_categories()), 0);
    $stmt = $pdo->prepare(
        "SELECT document_type, COUNT(*) c FROM documents WHERE faculty_id = ? AND status = 'active' GROUP BY document_type"
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
           AND d.status = 'active'
           AND d.expiration_date >= CURRENT_DATE
           AND d.expiration_date <= CURRENT_DATE + INTERVAL '" . $days . "' DAY
         ORDER BY d.expiration_date ASC"
    );
    $stmt->execute();
    return $stmt->fetchAll();
}

// ---------------------------------------------------------------------
// Expiration alerts (Objective 3a, section 1.5, Fig. 2). The owner of a
// document and every Admin are notified (existing notifications table)
// at 60, 30 and 7 days before it expires and on the day it expires --
// each milestone once per document and expiration date, recorded in
// expiration_alerts_sent. A missed milestone isn't sent late: only the
// one the document is in now goes out (first checked 5 days before ->
// the 7-day alert only). All dates are Asia/Manila (config.php), worked
// out in PHP rather than with the database server's CURDATE().
// ---------------------------------------------------------------------

/** Alert milestones, most urgent first: milestone => days before expiration (0 = the day itself or later). */
function expiration_milestones(): array {
    return ['expired' => 0, '7' => 7, '30' => 30, '60' => 60];
}

/** Whole days from today until $date (Y-m-d); 0 on the day, negative once passed. */
function days_until(string $date): int {
    return (int)(new DateTimeImmutable('today'))->diff(new DateTimeImmutable($date))->format('%r%a');
}

/** The milestone a document with $days_left is in now, or null when it's more than 60 days away. */
function expiration_milestone(int $days_left): ?string {
    foreach (expiration_milestones() as $milestone => $days) {
        if ($days_left <= $days) { return $milestone; }
    }
    return null;
}

/** "Expired" / "Expires today" / "5 days left", and the badge class for the card colors. */
function expiration_badge(int $days_left): array {
    if ($days_left < 0)   { return ['Expired', 'bg-danger']; }
    if ($days_left === 0) { return ['Expires today', 'bg-danger']; }
    $label = $days_left . ' day' . ($days_left === 1 ? '' : 's') . ' left';
    if ($days_left <= 7)  { return [$label, 'bg-expiry-soon']; }
    if ($days_left <= 30) { return [$label, 'bg-warning']; }
    return [$label, 'bg-light text-dark border'];
}

/** Non-overlapping status buckets for counts / filters: key => [label, badge class]. */
function expiration_buckets(): array {
    return [
        'expired' => ['Expired', 'bg-danger'],
        '7'       => ['Within 7 days', 'bg-expiry-soon'],
        '30'      => ['8-30 days', 'bg-warning'],
        '60'      => ['31-60 days', 'bg-light text-dark border'],
    ];
}

/** Count of expiring_documents() rows per bucket (the bucket keys are the alert milestones). */
function expiration_bucket_counts(array $rows): array {
    $counts = array_fill_keys(array_keys(expiration_buckets()), 0);
    foreach ($rows as $r) {
        $b = expiration_milestone($r['days_left']);
        if ($b !== null) { $counts[$b]++; }
    }
    return $counts;
}

/**
 * Readable name of a filed document from its stored file name, which
 * safe_filename() prefixed with the upload time and suffixed with a
 * random tag: "20261002_101500_My_Cert_a1b2c3.pdf" -> "My Cert.pdf".
 */
function document_display_name(string $file_path): string {
    $name = preg_replace(['/^\d{8}_\d{6}_/', '/_[0-9a-f]{6}(_scan)?$/'], '', pathinfo($file_path, PATHINFO_FILENAME));
    $name = trim(str_replace('_', ' ', $name));
    $ext = pathinfo($file_path, PATHINFO_EXTENSION);
    return ($name !== '' ? $name : 'document') . ($ext !== '' ? '.' . $ext : '');
}

/**
 * Documents that are expired or expire within $days days, soonest first,
 * each with days_left. Leaves out documents of deactivated accounts and
 * versions that were replaced: a newer upload in the same folder and
 * period with a later expiration date (re-uploads are new rows, the old
 * one stays as history). $faculty_id limits it to one person's 201 file.
 */
function expiring_documents(PDO $pdo, ?int $faculty_id = null, int $days = 60): array {
    $sql = "SELECT d.document_id, d.faculty_id, d.document_type, d.document_subtype, d.file_path, d.expiration_date, u.full_name
            FROM documents d
            JOIN users u ON u.user_id = d.faculty_id
            WHERE d.expiration_date IS NOT NULL
              AND d.expiration_date <= ?
              AND d.status = 'active'
              AND u.is_active = 1
              AND NOT EXISTS (
                  SELECT 1 FROM documents n
                  WHERE n.status = 'active' AND n.faculty_id = d.faculty_id AND n.document_type = d.document_type
                    AND " . sql_same('n.document_subtype', 'd.document_subtype') . " AND " . sql_same('n.academic_year', 'd.academic_year') . "
                    AND " . sql_same('n.semester', 'd.semester') . " AND " . sql_same('n.period_year', 'd.period_year') . "
                    AND n.document_id > d.document_id AND n.expiration_date > d.expiration_date
              )";
    $params = [(new DateTimeImmutable('today'))->modify("+{$days} days")->format('Y-m-d')];
    if ($faculty_id !== null) {
        $sql .= " AND d.faculty_id = ?";
        $params[] = $faculty_id;
    }
    $stmt = $pdo->prepare($sql . " ORDER BY d.expiration_date ASC, u.full_name ASC, d.document_id ASC");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['days_left'] = days_until($row['expiration_date']);
    }
    return $rows;
}

/** [message to the owner, message to the Admins] for an expiring_documents() row. */
function expiration_alert_messages(array $doc): array {
    $name = document_display_name($doc['file_path']) . ' (' . document_type_label($doc['document_type'], $doc['document_subtype']) . ')';
    $date = date('F j, Y', strtotime($doc['expiration_date']));
    $n = $doc['days_left'];
    if ($n < 0) {
        $when = "expired on {$date}";
    } elseif ($n === 0) {
        $when = "expires today, {$date}";
    } else {
        $when = "will expire in {$n} day" . ($n === 1 ? '' : 's') . " on {$date}";
    }
    return [
        "Your {$name} {$when}." . ($n <= 0 ? ' Please upload an updated copy.' : ''),
        "{$doc['full_name']}'s {$name} {$when}.",
    ];
}

/**
 * Send every expiration alert that is due: for each expired / expiring
 * document, the milestone it is in now, unless already sent for this
 * expiration date. The expiration_alerts_sent row is written first
 * (sql_insert_ignore() on the unique key), so two runs at once can't both
 * send it; it's rolled back if the notifications fail, to be retried.
 * @return array{documents: int, alerts: int}
 */
function check_expiration_alerts(PDO $pdo): array {
    $documents = expiring_documents($pdo);
    $sent = 0;
    $claim = $pdo->prepare(sql_insert_ignore("expiration_alerts_sent (document_id, milestone, expiration_date) VALUES (?, ?, ?)"));
    foreach ($documents as $doc) {
        $milestone = expiration_milestone($doc['days_left']);
        if ($milestone === null) { continue; }
        try {
            $pdo->beginTransaction();
            $claim->execute([$doc['document_id'], $milestone, $doc['expiration_date']]);
            if ($claim->rowCount() === 1) {
                [$to_owner, $to_admin] = expiration_alert_messages($doc);
                notify($pdo, (int)$doc['faculty_id'], $to_owner);
                notify_role($pdo, 'admin', $to_admin);
                $sent++;
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            error_log("Expiration alert for document {$doc['document_id']} failed: " . $e->getMessage());
        }
    }
    return ['documents' => count($documents), 'alerts' => $sent];
}

/** Minutes after which a daily job that never finished (e.g. PHP's time limit stopped it) may be started again. */
const DAILY_JOB_STALE_MINUTES = 10;

/**
 * Run $job at most once a day system-wide (dashboard load): the first call
 * of the day claims $key in system_settings ("running:<time>") and runs it;
 * today's date is recorded only once it finished, so a run that failed or
 * was cut off is tried again (a cut-off one after DAILY_JOB_STALE_MINUTES).
 * Later calls -- done today, or running elsewhere -- return null at once.
 * $force runs it anyway (the cron scripts, "Run reminder check now").
 * Never throws.
 */
function run_once_a_day(PDO $pdo, string $key, callable $job, bool $force = false): ?array {
    try {
        $today = date('Y-m-d');
        $running = 'running:' . date('Y-m-d H:i:s');
        $stale = 'running:' . date('Y-m-d H:i:s', time() - 60 * DAILY_JOB_STALE_MINUTES);
        // Leaves the row alone (0 rows) when today is recorded or a run started less than
        // DAILY_JOB_STALE_MINUTES ago (a date sorts before any "running:" value).
        // MySQL counts a row set to its own value as 0 rows; PostgreSQL skips the update by its WHERE.
        $stmt = $pdo->prepare(db_is_pgsql()
            ? "INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)
               ON CONFLICT (setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value
               WHERE NOT (CAST(? AS INTEGER) = 0 AND (system_settings.setting_value IS NOT DISTINCT FROM CAST(? AS VARCHAR)
                                                      OR system_settings.setting_value > CAST(? AS VARCHAR)))"
            : "INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)
               ON DUPLICATE KEY UPDATE setting_value = IF(? = 0 AND (setting_value <=> ? OR setting_value > ?), setting_value, VALUES(setting_value))"
        );
        $stmt->execute([$key, $running, $force ? 1 : 0, $today, $stale]);
        if ($stmt->rowCount() === 0 && !$force) {
            return null;
        }
        // On a dashboard load the job may take a while (AI wording); PHP's default 30 s could cut it off
        if (PHP_SAPI !== 'cli' && (int)ini_get('max_execution_time') > 0) { @set_time_limit(180); }
        $result = $job($pdo);
        $pdo->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = ?")->execute([$today, $key]);
        return $result;
    } catch (Throwable $e) {
        error_log("Daily job {$key} failed: " . $e->getMessage());
        try {   // let the next page load try again
            $pdo->prepare("UPDATE system_settings SET setting_value = NULL WHERE setting_key = ?")->execute([$key]);
        } catch (Throwable $ignored) {}
        return null;
    }
}

/** check_expiration_alerts() at most once a day (run_once_a_day()). $force: cron/expiration_check.php. */
function run_daily_expiration_check(PDO $pdo, bool $force = false): ?array {
    return run_once_a_day($pdo, 'expiration_check_last_run', 'check_expiration_alerts', $force);
}

// ---------------------------------------------------------------------
// Auto-archive. A posted document nobody has opened in
// ARCHIVE_UNVIEWED_YEARS years (documents.last_viewed_at, set by
// document.php) moves to its owner's archive: status 'archived',
// archived_by NULL, archive_reason auto_archive_reason(). The file and
// record are kept; the owner sees it in their Archived Docs, the
// Admin can restore it (which counts as a view). Opening an archived
// document does not restore it. Archived documents are left out of
// 201-Files, searches, dashboards, expiration alerts and analytics, which all
// filter on status = 'active'. Runs at most once a day
// (run_daily_auto_archive(), dashboards and cron/auto_archive.php).
// ---------------------------------------------------------------------

/**
 * SQL for the date a documents row $alias is "from": the seminar / training
 * end date, else its start date, else the date issued, else the upload date.
 * (Shown in archive lists; no longer used by the archive rule.)
 */
function document_date_sql(string $alias = 'd'): string {
    return "COALESCE({$alias}.date_end, {$alias}.date_start, {$alias}.date_issued, DATE({$alias}.filed_at))";
}

/** SQL for when a documents row $alias was last opened (its upload time if never). */
function document_last_viewed_sql(string $alias = 'd'): string {
    return "COALESCE({$alias}.last_viewed_at, {$alias}.filed_at)";
}

/** Documents last opened before this moment (Y-m-d H:i:s, Asia/Manila) haven't been opened in ARCHIVE_UNVIEWED_YEARS years. */
function archive_cutoff_datetime(): string {
    return (new DateTimeImmutable('now'))->modify('-' . (int)ARCHIVE_UNVIEWED_YEARS . ' years')->format('Y-m-d H:i:s');
}

/** "3 years" -- the archive rule's period, for messages. */
function archive_period_label(): string {
    return (int)ARCHIVE_UNVIEWED_YEARS . ' year' . ((int)ARCHIVE_UNVIEWED_YEARS === 1 ? '' : 's');
}

function auto_archive_reason(): string {
    return 'Auto: not opened in ' . archive_period_label();
}

// ---------------------------------------------------------------------
// Old documents straight to Archived Docs. At upload, a document whose own
// date (not the upload date) is ARCHIVE_UNVIEWED_YEARS or more years ago is
// saved as archived -- e.g. a 2005 certificate uploaded today. Its date is
// the one read by OCR and checked in the preview, or typed in Manual Input:
// the seminar / training end date, else its start date, else the date
// issued. No date: 201-Files as usual (no guessing). Never the PDS (a living
// document with versions). Restoring one marks it archive_exempt, so neither
// rule takes it back.
// ---------------------------------------------------------------------

/** The reason stored for a document archived at upload because of its own date. */
function upload_archive_reason(): string {
    return 'Auto: document dated ' . (int)ARCHIVE_UNVIEWED_YEARS . '+ years ago at upload';
}

/** A document's own date (Y-m-d) from its details (document_details_clean()), or null when it has none. */
function document_own_date(array $details): ?string {
    return $details['date_end'] ?? $details['date_start'] ?? $details['date_issued'] ?? null;
}

/** Documents dated on or before this day (Asia/Manila) are ARCHIVE_UNVIEWED_YEARS or more years old. */
function archive_old_date_cutoff(): string {
    return (new DateTimeImmutable('today'))->modify('-' . (int)ARCHIVE_UNVIEWED_YEARS . ' years')->format('Y-m-d');
}

/** Whether a new $type document with own date $date goes straight to Archived Docs. */
function archive_at_upload(string $type, ?string $date): bool {
    return $type !== 'PDS' && $date !== null && $date <= archive_old_date_cutoff();
}

/**
 * Archive every posted document not opened in ARCHIVE_UNVIEWED_YEARS years.
 * Documents the Admin restored under the old date rule (archive_exempt)
 * and ARCHIVE_EXEMPT_CATEGORIES are skipped. The daily run is logged as
 * "system"; one activity-log entry per run, and each owner is notified.
 * @return array{archived: int, document_ids: int[]}
 */
function auto_archive_unviewed_documents(PDO $pdo): array {
    $last_viewed = document_last_viewed_sql('d');
    $sql = "SELECT d.document_id, d.faculty_id, d.document_type, d.document_subtype, d.file_path, d.title
            FROM documents d
            WHERE d.status = 'active' AND d.archive_exempt = 0 AND $last_viewed < ?";
    $params = [archive_cutoff_datetime()];
    if (ARCHIVE_EXEMPT_CATEGORIES) {
        $sql .= " AND d.document_type NOT IN (" . implode(',', array_fill(0, count(ARCHIVE_EXEMPT_CATEGORIES), '?')) . ")";
        array_push($params, ...ARCHIVE_EXEMPT_CATEGORIES);
    }
    $stmt = $pdo->prepare($sql . " ORDER BY d.faculty_id, d.document_id");
    $stmt->execute($params);
    $docs = $stmt->fetchAll();
    if (!$docs) {
        return ['archived' => 0, 'document_ids' => []];
    }

    // Checked again in the UPDATE: not taken by a run at the same moment, and not opened in the meantime
    $archive = $pdo->prepare(
        "UPDATE documents d SET status = 'archived', archived_at = NOW(), archived_by = NULL, archive_reason = ?
         WHERE d.document_id = ? AND d.status = 'active' AND d.archive_exempt = 0 AND $last_viewed < ?"
    );
    $done = [];
    foreach ($docs as $d) {
        $archive->execute([auto_archive_reason(), $d['document_id'], $params[0]]);
        if ($archive->rowCount() === 1) { $done[] = $d; }
    }
    if (!$done) {
        return ['archived' => 0, 'document_ids' => []];
    }

    $ids = array_map(fn($d) => (int)$d['document_id'], $done);
    log_activity($pdo, null, 'DOCUMENT_AUTO_ARCHIVE', 'Auto-archived ' . count($done) . ' document' . (count($done) === 1 ? '' : 's')
        . ' not opened since ' . date('M j, Y', strtotime(archive_cutoff_datetime())) . ' (' . archive_period_label() . '): '
        . implode(', ', array_map(fn($d) => "#{$d['document_id']}", $done)) . '.', 'system');

    $by_owner = [];
    foreach ($done as $d) { $by_owner[(int)$d['faculty_id']][] = $d; }
    foreach ($by_owner as $owner_id => $owned) {
        $names = array_map(fn($d) => document_title($d), array_slice($owned, 0, 5));
        notify($pdo, $owner_id, count($owned) . ' document' . (count($owned) === 1 ? ' was' : 's were') . ' moved to your Archived Docs because '
            . (count($owned) === 1 ? 'it has' : 'they have') . ' not been opened in ' . archive_period_label() . ': '
            . implode('; ', $names) . (count($owned) > 5 ? '; and ' . (count($owned) - 5) . ' more' : '')
            . '. You can still view and download ' . (count($owned) === 1 ? 'it' : 'them') . ' there; ask the Admin if '
            . (count($owned) === 1 ? 'it' : 'one') . ' should be restored.', null, 'archive.php');
    }
    return ['archived' => count($done), 'document_ids' => $ids];
}

/** auto_archive_unviewed_documents() at most once a day (run_once_a_day()). $force: cron/auto_archive.php. */
function run_daily_auto_archive(PDO $pdo, bool $force = false): ?array {
    return run_once_a_day($pdo, 'auto_archive_last_run', 'auto_archive_unviewed_documents', $force);
}

/**
 * The dashboards' once-a-day jobs: archive unopened documents first, so they get
 * no expiration alerts; then the Recently Deleted cleanup; reminders last, so they know which expiration
 * alerts already went out (includes/ai_reminders.php). Then the signed-in
 * user's own reminders, if the day's run didn't cover them (a new account,
 * or their check failed).
 */
function run_daily_jobs(PDO $pdo): void {
    run_daily_auto_archive($pdo);
    run_daily_deleted_cleanup($pdo);
    run_daily_account_cleanup($pdo);
    run_daily_expiration_check($pdo);
    run_daily_reminder_check($pdo);
    run_daily_login_history_cleanup($pdo);   // login history and trusted devices older than 90 days
    if ($me = current_user()) { ensure_reminders_checked($pdo, (int)$me['user_id']); }
    login_history_backfill($pdo);   // locations the login requests didn't get to (looked up after this page is sent)
}

// ---------------------------------------------------------------------
// Seminar / training details of a document (title, dates, venue,
// conducted by, type, level, hours) and its date issued. Pre-filled from
// the OCR text at upload (OcrProcessor::extractTraining()), reviewed by the
// faculty member, editable later in document_details.php, and listed in
// the Seminar & Training Report.
// ---------------------------------------------------------------------

/** Detail columns of documents, in the order document_details_clean() returns them. */
function document_detail_columns(): array {
    return ['title', 'date_start', 'date_end', 'venue', 'conducted_by', 'training_type', 'training_level', 'hours', 'date_issued'];
}

/**
 * Sanitize submitted details: lengths cut to the column sizes, dates must
 * be real Y-m-d dates (an end date before the start is swapped), type and
 * level must be listed in TRAINING_TYPES / TRAINING_LEVELS, hours a number
 * from 0.5 to 9999. Anything invalid becomes NULL ("Not specified").
 * @return array<string, mixed> keyed and ordered as document_detail_columns()
 */
function document_details_clean(array $in): array {
    $text = function ($v, int $max): ?string {
        $v = trim(preg_replace('/\s+/', ' ', (string)$v));
        return $v === '' ? null : mb_substr($v, 0, $max);
    };
    $date = function ($v): ?string {
        $v = trim((string)$v);
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1]) && (int)$m[1] >= 1950 ? $v : null;
    };
    $start = $date($in['date_start'] ?? '');
    $end = $date($in['date_end'] ?? '');
    if ($start === null && $end !== null) { [$start, $end] = [$end, null]; }
    if ($start !== null && $end !== null && $end < $start) { [$start, $end] = [$end, $start]; }
    $hours = trim((string)($in['hours'] ?? ''));
    return [
        'title'          => $text($in['title'] ?? '', 255),
        'date_start'     => $start,
        'date_end'       => $end ?? $start,
        'venue'          => $text($in['venue'] ?? '', 255),
        'conducted_by'   => $text($in['conducted_by'] ?? '', 255),
        'training_type'  => in_array($in['training_type'] ?? '', TRAINING_TYPES, true) ? $in['training_type'] : null,
        'training_level' => in_array($in['training_level'] ?? '', TRAINING_LEVELS, true) ? $in['training_level'] : null,
        'hours'          => is_numeric($hours) && (float)$hours >= 0.5 && (float)$hours <= 9999 ? round((float)$hours, 1) : null,
        'date_issued'    => $date($in['date_issued'] ?? ''),
    ];
}

/** "Sept 15-17, 2026" / "Sept 30 - Oct 2, 2026" / "Dec 30, 2025 - Jan 2, 2026" / "Not specified". */
function document_date_range_label(?string $start, ?string $end): string {
    if (!$start) { return 'Not specified'; }
    $fmt = fn(string $d, string $f) => str_replace('Sep ', 'Sept ', date($f, strtotime($d)));
    if (!$end || $end === $start) { return $fmt($start, 'M j, Y'); }
    if (substr($start, 0, 7) === substr($end, 0, 7)) { return $fmt($start, 'M j') . '-' . date('j, Y', strtotime($end)); }
    if (substr($start, 0, 4) === substr($end, 0, 4)) { return $fmt($start, 'M j') . ' - ' . $fmt($end, 'M j, Y'); }
    return $fmt($start, 'M j, Y') . ' - ' . $fmt($end, 'M j, Y');
}

/** A document's title if one was recorded, else its readable file name. */
function document_title(array $doc): string {
    return trim((string)($doc['title'] ?? '')) !== '' ? $doc['title'] : document_display_name($doc['file_path']);
}

/** "Seminar · National" from training_type / training_level, or '' when neither is set. */
function training_type_label(array $doc): string {
    return implode(' · ', array_filter([$doc['training_type'] ?? null, $doc['training_level'] ?? null]));
}

/** Whether $user may edit a document's details: its owner while it's posted or waiting for review, or the Admin. */
function can_edit_document_details(array $user, array $doc): bool {
    return $user['role'] === 'admin'
        || ((int)$doc['faculty_id'] === (int)$user['user_id'] && in_array($doc['status'] ?? '', ['active', 'for_review'], true));
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

/**
 * One login attempt, by the username typed (lowercased). $success: both
 * steps passed (password and emailed code). $blocked: refused because of a
 * lockout, password not checked (not counted as a failure).
 * login_attempts.email is kept for the attempts from before usernames.
 */
function record_login_attempt(PDO $pdo, string $username, ?int $user_id, bool $success, string $ip, bool $blocked = false): void {
    $pdo->prepare(
        "INSERT INTO login_attempts (username, user_id, ip_address, was_successful, was_blocked) VALUES (?, ?, ?, ?, ?)"
    )->execute([$username, $user_id, $ip, $success ? 1 : 0, $blocked ? 1 : 0]);
}

// ---------------------------------------------------------------------
// Brute-force protection. MAX_FAILED_ATTEMPTS failures for one username, or
// MAX_IP_FAILED_ATTEMPTS from one IP, within FAILED_ATTEMPT_WINDOW_MINUTES
// lock further logins for LOCKOUT_MINUTES (config.php). Failures are
// counted from login_attempts by time window; login_lockouts holds one row
// per lock, so the Admins are notified once and can unlock early. All
// times are compared inside the database (NOW(), session time zone Asia/Manila).
// ---------------------------------------------------------------------

/**
 * The lock in force on a username ('account') or IP address ('ip'), if any.
 * @return array{id: int, locked_until: string, seconds_left: int}|null
 */
function active_login_lock(PDO $pdo, string $type, string $key): ?array {
    $stmt = $pdo->prepare(
        "SELECT id, locked_until, " . sql_seconds_until('locked_until') . " AS seconds_left
         FROM login_lockouts
         WHERE lock_type = ? AND lock_key = ? AND cleared_at IS NULL AND locked_until > NOW()
         ORDER BY locked_until DESC LIMIT 1"
    );
    $stmt->execute([$type, $key]);
    $lock = $stmt->fetch();
    return $lock ? ['id' => (int)$lock['id'], 'locked_until' => $lock['locked_until'], 'seconds_left' => (int)$lock['seconds_left']] : null;
}

/**
 * Failures that count toward locking an email / IP: in the window, and
 * since its last lock started or was cleared -- and, for an email, since
 * its last successful login (a successful login resets the count).
 * Attempts refused during a lock (was_blocked) don't count.
 */
function recent_login_failures(PDO $pdo, string $type, string $key): int {
    $column = $type === 'account' ? 'username' : 'ip_address';
    $since = "GREATEST(NOW() - INTERVAL '" . (int)FAILED_ATTEMPT_WINDOW_MINUTES . "' MINUTE,
                       COALESCE((SELECT MAX(GREATEST(locked_at, COALESCE(cleared_at, locked_at))) FROM login_lockouts
                                 WHERE lock_type = ? AND lock_key = ?), '1000-01-01')"
           . ($type === 'account'
                ? ", COALESCE((SELECT MAX(attempted_at) FROM login_attempts WHERE username = ? AND was_successful = 1), '1000-01-01')"
                : '')
           . ")";
    $params = [$type, $key];
    if ($type === 'account') { $params[] = $key; }
    $params[] = $key;
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM login_attempts
         WHERE attempted_at > $since AND $column = ? AND was_successful = 0 AND was_blocked = 0"
    );
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

/** "12 minutes left" -- remaining lock time, rounded up to a whole minute. */
function login_lock_minutes_label(int $seconds_left): string {
    $minutes = max(1, (int)ceil($seconds_left / 60));
    return $minutes . ' minute' . ($minutes === 1 ? '' : 's') . ' left';
}

/** "Too many failed login attempts. Please try again in 12 minutes." */
function login_lock_message(int $seconds_left): string {
    $minutes = max(1, (int)ceil($seconds_left / 60));
    return 'Too many failed login attempts. Please try again in ' . $minutes . ' minute' . ($minutes === 1 ? '' : 's') . '.';
}

/**
 * After a failed login: lock the email and / or IP if this failure reached
 * its limit -- recording the lock, logging it and notifying every Admin,
 * once per lock. Returns the new lock (the longer one when both were
 * locked), or null with $attempts_left set for the email.
 */
function register_failed_login(PDO $pdo, string $username, string $ip, ?int &$attempts_left = null): ?array {
    $limits = ['account' => [$username, (int)MAX_FAILED_ATTEMPTS], 'ip' => [$ip, (int)MAX_IP_FAILED_ATTEMPTS]];
    $new_lock = null;
    foreach ($limits as $type => [$key, $limit]) {
        $failures = recent_login_failures($pdo, $type, $key);
        if ($type === 'account') { $attempts_left = max(0, $limit - $failures); }
        if ($failures < $limit || active_login_lock($pdo, $type, $key)) { continue; }

        $pdo->prepare(
            "INSERT INTO login_lockouts (lock_type, lock_key, failed_count, locked_at, locked_until)
             VALUES (?, ?, ?, NOW(), NOW() + INTERVAL '" . (int)LOCKOUT_MINUTES . "' MINUTE)"
        )->execute([$type, $key, $failures]);
        $lock = active_login_lock($pdo, $type, $key);
        $until = date('g:i A', time() + $lock['seconds_left']);
        $what = $type === 'account' ? "Login for username {$username} locked" : "Logins from IP address {$ip} locked";
        $why = "{$failures} failed attempts in " . FAILED_ATTEMPT_WINDOW_MINUTES . ' minutes'
             . ($type === 'account' ? " (last one from {$ip})" : '');
        log_activity($pdo, null, 'LOGIN_LOCKOUT', "{$what} until {$until} after {$why}.");
        notify_role($pdo, 'admin', "{$what} until {$until} after {$why}. It unlocks by itself, or you can unlock it "
            . ($type === 'account' ? 'in Manage Faculty & Accounts' : 'in Security Alerts') . '.');
        if (!$new_lock || $lock['seconds_left'] > $new_lock['seconds_left']) { $new_lock = $lock; }
    }
    return $new_lock;
}

/** Admin unlock: end the lock on an email / IP now; its earlier failures stop counting. Returns whether one was active. */
function clear_login_lock(PDO $pdo, string $type, string $key, int $admin_id): bool {
    $stmt = $pdo->prepare(
        "UPDATE login_lockouts SET cleared_at = NOW(), cleared_by = ?
         WHERE lock_type = ? AND lock_key = ? AND cleared_at IS NULL AND locked_until > NOW()"
    );
    $stmt->execute([$admin_id, $type, $key]);
    if ($stmt->rowCount() === 0) { return false; }
    log_my_activity($pdo, 'LOGIN_UNLOCK', ($type === 'account' ? "Unlocked login for {$key}" : "Unlocked logins from IP address {$key}") . ' before the lock expired.');
    return true;
}

/** Locks in force now: rows of login_lockouts plus seconds_left, latest first. */
function active_login_locks(PDO $pdo): array {
    return $pdo->query(
        "SELECT id, lock_type, lock_key, failed_count, locked_at, locked_until, " . sql_seconds_until('locked_until') . " AS seconds_left
         FROM login_lockouts WHERE cleared_at IS NULL AND locked_until > NOW() ORDER BY locked_at DESC"
    )->fetchAll();
}

function flag_suspicious_login(PDO $pdo, int $user_id, string $full_name, string $ip): void {
    $reasons = [];

    // A new IP address alone is not flagged: mobile and home IPs in the Philippines change
    // often. A new device is (new_device_login(), includes/login_activity.php).

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM login_attempts
         WHERE username = (SELECT username FROM users WHERE user_id = ?)
           AND was_successful = 0
           AND attempted_at >= NOW() - INTERVAL '15' MINUTE"
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

// ---------------------------------------------------------------------
// Activity logs (Objective 3c, section 3.5.4, Fig. 4 "View Activity /
// Login Logs"). One row per user action, shown read-only to the Admin in
// admin/activity_logs.php. Login attempts themselves stay in login_attempts.
// ---------------------------------------------------------------------

/** Action codes stored in activity_logs.action => label shown to the Admin. */
function activity_actions(): array {
    return [
        'LOGIN'              => 'Login',
        'LOGOUT'             => 'Logout',
        'UPLOAD'             => 'Upload Document',
        'VIEW_DOCUMENT'      => 'View Document',
        'SEARCH'             => 'Search',
        'GENERATE_REPORT'    => 'Generate Report',
        'PDS_UPDATE'         => 'PDS Update',
        'PDS_IMPORT'         => 'PDS Updated from Upload',
        'PDS_UNDO'           => 'PDS Update Undone',
        'ACCOUNT_CREATE'     => 'Account Created',
        'ACCOUNT_UPDATE'     => 'Account Updated',
        'ACCOUNT_DEACTIVATE' => 'Account Deactivated',
        'ACCOUNT_ACTIVATE'   => 'Account Activated',
        'ACCOUNT_DELETE'     => 'Account Deleted',
        'ACCOUNT_RESTORE'    => 'Account Restored',
        'ACCOUNT_PURGE'      => 'Account Permanently Deleted (automatic)',
        'CATEGORY_CORRECT'   => 'Category Corrected',
        'DOCUMENT_DELETE'    => 'Document Deleted',
        'DOCUMENT_ARCHIVE'   => 'Document Archived',
        'DOCUMENT_RESTORE'   => 'Document Restored',
        'DOCUMENT_AUTO_ARCHIVE' => 'Documents Auto-Archived',
        'DOCUMENT_POST'      => 'Document Posted (Classification Review)',
        'DOCUMENT_REVIEW_EDIT' => 'Document Type / Details Corrected',
        'DOCUMENT_CHECKED'   => 'Flagged Document Checked',
        'DOCUMENT_PURGE'     => 'Document Permanently Deleted',
        'DOCUMENT_AUTO_PURGE' => 'Document Permanently Deleted (automatic)',
        'DOCUMENT_DETAILS'   => 'Document Details Updated',
        'PROFILE_UPDATE'     => 'Profile Updated',
        'PROFILE_PICTURE'    => 'Profile Picture Changed',
        'RANK_LIST_UPDATE'   => 'Academic Rank List Updated',
        'PASSWORD_CHANGE'    => 'Password Changed',
        'PASSWORD_RESET'     => 'Password Reset',
        'LOGIN_LOCKOUT'      => 'Login Locked',
        'LOGIN_BLOCKED'      => 'Login Blocked (Deactivated / Deleted Account)',
        'SESSION_ENDED'      => 'Signed Out (Account Deactivated / Deleted)',
        'LOGIN_UNLOCK'       => 'Login Unlocked',
        'AI_SUMMARY'         => 'AI Report Summary Generated',
        'REMINDER_CHECK'     => 'Reminder Check',
        'LOGIN_CODE_SENT'    => 'Login Code Sent',
        'LOGIN_CODE_FAILED'  => 'Login Code Failed (Too Many Wrong Codes)',
        'EMAIL_SETUP'        => 'Email Setup Asked at Login',
        'EMAIL_CODE_SENT'    => 'Email Verification Code Sent',
        'EMAIL_VERIFIED'     => 'Email Verified',
        'EMAIL_CHANGE'       => 'Email Changed',
        'EMAIL_2FA_RESET'    => 'Email / Two-Factor Reset by Admin',
        'EMAIL_SETUP_SKIPPED' => 'Email Setup Skipped (Not Now)',
        'TWO_FACTOR_ON'      => 'Two-Factor Authentication Turned On',
        'TWO_FACTOR_OFF'     => 'Two-Factor Authentication Turned Off',
        'USERNAME_CHANGE'    => 'Username Changed',
        'PASSWORD_RESET_REQUEST' => 'Forgot Password Requested',
        'PASSWORD_RESET_SELF' => 'Password Reset with Emailed Code',
        'DEVICE_REMOVED'     => 'Trusted Device Removed',
    ];
}

/**
 * Record one action in activity_logs, with the user's role, IP address,
 * browser and time filled in automatically. $user_id is null when nobody
 * is signed in; $role defaults to the signed-in user's role (pass it when
 * logging for a user who isn't in the session yet, e.g. at login).
 * Never throws: a logging problem must not break the action being logged.
 */
function log_activity(PDO $pdo, ?int $user_id, string $action, string $details = '', ?string $role = null): void {
    try {
        if ($role === null && $user_id !== null) {
            $me = current_user();
            $role = ($me && (int)$me['user_id'] === $user_id) ? $me['role'] : null;
        }
        $pdo->prepare(
            "INSERT INTO activity_logs (user_id, user_role, action, details, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?)"
        )->execute([
            $user_id, $role, $action, $details !== '' ? $details : null,
            client_ip(),   // same source as login_attempts.ip_address
            isset($_SERVER['HTTP_USER_AGENT']) ? mb_substr($_SERVER['HTTP_USER_AGENT'], 0, 255) : null,
        ]);
    } catch (Throwable $e) {
        error_log('Activity log failed (' . $action . '): ' . $e->getMessage());
    }
}

/** log_activity() for the signed-in user. */
function log_my_activity(PDO $pdo, string $action, string $details = ''): void {
    $me = current_user();
    log_activity($pdo, $me ? (int)$me['user_id'] : null, $action, $details, $me['role'] ?? null);
}

/** "Category: TOR; Uploaded from: 2026-09-01" -- the non-empty filters, for SEARCH / GENERATE_REPORT details. */
function describe_filters(array $filters): string {
    $parts = [];
    foreach ($filters as $label => $value) {
        if ($value !== '' && $value !== null && $value !== false) {
            $parts[] = $label . ': ' . ($value === true ? 'yes' : $value);
        }
    }
    return $parts ? implode('; ', $parts) : 'no filters';
}

function unresolved_security_alert_count(PDO $pdo): int {
    return (int)$pdo->query("SELECT COUNT(*) FROM security_alerts WHERE is_reviewed = 0")->fetchColumn();
}

require_once __DIR__ . '/profile.php';   // profiles: access scope, pictures, profile details, usernames
require_once __DIR__ . '/two_factor.php';   // emailed login codes, two-factor email, finishing a login
require_once __DIR__ . '/login_activity.php';   // login history, trusted devices, new-device alerts
require_once __DIR__ . '/document_review.php';   // Manage Document: classification review, posting
require_once __DIR__ . '/page_tabs.php';         // tab bars joining the pages behind one menu entry
require_once __DIR__ . '/ai.php';        // AI (Gemini): settings, privacy, reminders
