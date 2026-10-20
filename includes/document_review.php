<?php
/**
 * Checking a document's type and details (Admin > Files > Manage Document).
 * Loaded by includes/functions.php.
 *
 * Every new upload is posted to 201-Files right away. The system flags the
 * ones worth checking (documents.flag_reason: low confidence, no text
 * read, entered manually, type changed by the uploader); Manage Document
 * lists them. The Admin opens one in the pop-up (api/modal.php?view=review),
 * corrects the type and details if needed and marks it checked
 * (reviewed_at / reviewed_by), which takes it off the list. The Admin can
 * correct any posted or archived document the same way, flagged or not.
 * The owner is notified only when the document type is corrected.
 *
 * Nothing is rejected here (a wrong file is deleted with the normal Delete
 * action, with a reason), and a flag never hides a document.
 */

/** Select options for a document's category, "Type|Subtype" => label, limited to what applies to the owner. */
function review_category_options(?string $employment_type): array {
    $options = [];
    foreach (categories_for_faculty($employment_type) as $key => $meta) {
        foreach (!empty($meta['subtypes']) ? array_keys($meta['subtypes']) : [''] as $sub) {
            $options[$key . '|' . $sub] = document_type_label($key, $sub !== '' ? $sub : null);
        }
    }
    return $options;
}

/** "Entered manually" / "Uploaded, auto-detected" / "Uploaded" for documents.entry_method. */
function entry_method_label(?string $method): string {
    return match ($method) {
        'manual' => 'Entered manually (no auto-detection)',
        'ocr'    => 'Uploaded, auto-detected (OCR)',
        default  => 'Uploaded',
    };
}

/** Badge class for a stored confidence score (NULL = not scored). */
function confidence_badge_class($score): string {
    if ($score === null) { return 'bg-light text-muted border'; }
    if ((float)$score < 0.3) { return 'bg-danger'; }
    return (float)$score < CONFIDENCE_THRESHOLD ? 'bg-warning' : 'bg-success';
}

/**
 * Save the Admin's corrections to a document and, when $mark_checked is
 * true, mark it checked (off the Flagged for checking list). $doc:
 * documents row joined with the owner's full_name and employment_type.
 * $in: the form (category "Type|Subtype", academic_year, semester,
 * period_year, expiration_date, details[...]). Every value is checked
 * here; returns [ok, message for the flash].
 */
function review_save_document(PDO $pdo, array $doc, array $in, array $admin, bool $mark_checked): array {
    if (!in_array($doc['status'], ['active', 'archived', 'for_review'], true)) {
        return [false, 'That document is in Recently Deleted. Restore it first to correct it.'];
    }
    $was_flagged = document_is_flagged($doc) || $doc['status'] === 'for_review';

    // Category, limited to what applies to the owner
    [$type, $sub] = array_pad(explode('|', (string)($in['category'] ?? ''), 2), 2, '');
    $cat = categories_for_faculty($doc['employment_type'])[$type] ?? null;
    if (!$cat) {
        return [false, 'Please choose a document type that applies to ' . $doc['full_name'] . '.'];
    }
    $sub = !empty($cat['subtypes']) ? (isset($cat['subtypes'][$sub]) ? $sub : $cat['default_subtype']) : null;

    // Period: semester (FTA / IPCR) or year (PDS); cleared for other types
    $ay = $sem = $year = null;
    if ($cat['frequency'] === 'semester') {
        $ay = preg_match('/^(\d{4})-(\d{4})$/', (string)($in['academic_year'] ?? ''), $m) && (int)$m[2] === (int)$m[1] + 1 ? $in['academic_year'] : null;
        $sem = isset(semester_options()[(int)($in['semester'] ?? 0)]) ? (int)$in['semester'] : null;
        if ($ay === null || $sem === null) {
            return [false, 'Please choose the semester and academic year of this ' . $cat['short'] . '.'];
        }
    } elseif ($cat['frequency'] === 'yearly') {
        $year = (int)($in['period_year'] ?? 0);
        if ($year < 1990 || $year > (int)date('Y') + 1) {
            return [false, 'Please enter the year of this ' . $cat['short'] . '.'];
        }
    }

    $expiration = trim((string)($in['expiration_date'] ?? ''));
    $exp_dt = DateTime::createFromFormat('!Y-m-d', $expiration);
    if ($expiration !== '' && (!$exp_dt || $exp_dt->format('Y-m-d') !== $expiration)) {
        return [false, 'Please enter a valid expiration date, or leave it blank.'];
    }
    $expiration = $expiration !== '' ? $expiration : null;

    // Details: certificates keep the seminar / training details; other documents a title and the date issued
    $details = document_details_clean((array)($in['details'] ?? []));
    if ($type !== 'Certificate') {
        $details = array_intersect_key($details, array_flip(['title', 'date_issued']));
    }

    // What changed, for the activity log
    $new = ['document_type' => $type, 'document_subtype' => $sub, 'academic_year' => $ay, 'semester' => $sem,
            'period_year' => $year, 'expiration_date' => $expiration] + $details;
    $changes = [];
    foreach ($new as $col => $value) {
        $old = $doc[$col] ?? null;
        if ($col === 'hours' && $old !== null) { $old = (float)$old; }
        if ((string)$old !== (string)$value) {
            $changes[ucfirst(str_replace('_', ' ', $col))] = ($old === null || $old === '' ? '(none)' : $old) . ' -> ' . ($value ?? '(none)');
        }
    }
    $type_changed = $type !== $doc['document_type'] || $sub !== $doc['document_subtype'];

    // Keep the local copy in the matching folder; the database copy (document_files) is unaffected
    $file_path = $type_changed
        ? (move_to_repository((int)$doc['faculty_id'], $type, $doc['file_path'], $sub) ?? $doc['file_path'])
        : $doc['file_path'];

    if (!$changes && !($mark_checked && $was_flagged)) {
        return [true, 'Nothing was changed.'];
    }

    $sets = ['file_path = ?'];
    $params = [$file_path];
    foreach ($new as $col => $value) {
        $sets[] = "$col = ?";
        $params[] = $value;
    }
    // Checked: off the flagged list. A document that wasn't flagged counts as checked once the Admin corrects it.
    if ($mark_checked || !$was_flagged) {
        $sets[] = 'reviewed_at = NOW()';
        $sets[] = 'reviewed_by = ?';
        $params[] = (int)$admin['user_id'];
    }
    if ($doc['status'] === 'for_review') {   // left over from the old review step: post it now
        $sets[] = "status = 'active'";
        $sets[] = 'posted_at = COALESCE(posted_at, filed_at)';
    }
    $params[] = (int)$doc['document_id'];
    $stmt = $pdo->prepare("UPDATE documents SET " . implode(', ', $sets) . " WHERE document_id = ? AND status IN ('active', 'archived', 'for_review')");
    $stmt->execute($params);
    if ($stmt->rowCount() === 0) {
        return [false, 'That document could not be updated -- it may have been deleted just now.'];
    }

    $label = document_type_label($type, $sub);
    $what = "document #{$doc['document_id']} \"" . basename($doc['file_path']) . "\" of {$doc['full_name']}";
    if ($type_changed) {
        log_my_activity($pdo, 'CATEGORY_CORRECT', "Corrected the category of {$what} from "
            . document_type_label($doc['document_type'], $doc['document_subtype']) . " to {$label} in Manage Document.");
    }
    $change_text = $changes ? ' -- ' . describe_filters($changes) : ' without changes';
    if ($mark_checked && $was_flagged) {
        log_my_activity($pdo, 'DOCUMENT_CHECKED', "Checked {$what} as {$label}{$change_text} (flagged: " . (document_flag_text($doc['flag_reason'] ?? null) ?: 'old review step') . ').');
    } else {
        log_my_activity($pdo, 'DOCUMENT_REVIEW_EDIT', "Corrected {$what} in Manage Document{$change_text}.");
    }
    // The owner hears about it only when the type was corrected (their document is in 201-Files either way)
    if ($type_changed) {
        $place = $doc['status'] === 'archived' ? 'archive.php' : 'faculty/my_documents.php?type=' . rawurlencode($type);
        notify($pdo, (int)$doc['faculty_id'], 'The Admin corrected the document type of your '
            . document_title(['title' => $details['title'] ?? $doc['title'], 'file_path' => $file_path])
            . ' from ' . document_type_label($doc['document_type'], $doc['document_subtype']) . " to {$label}.",
            null, $place);
    }
    $msg = $changes ? 'Your corrections were saved' . ($type_changed ? " and {$doc['full_name']} has been notified of the new type" : '') . '.' : 'No changes.';
    return [true, $mark_checked && $was_flagged ? $msg . ' The document is marked as checked.' : $msg];
}
