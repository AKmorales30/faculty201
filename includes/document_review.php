<?php
/**
 * Classification review (Admin > Files > Manage Document). Loaded by
 * includes/functions.php.
 *
 * Every new upload is saved For Review (documents.status 'for_review').
 * The Admin opens it in the review pop-up (api/modal.php?view=review),
 * checks the detected document type, confidence and extracted details,
 * corrects them if needed, and posts it: status 'active' (Posted), with
 * posted_at / posted_by. Only posted documents appear in 201-Files,
 * searches, reports and analytics. The owner is notified when it's posted.
 *
 * This is a classification check only -- not the old Program Chair / Dean
 * approval, which stays removed: nothing is rejected here (a wrong file is
 * deleted with the normal Delete action, with a reason).
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
 * Save the Admin's corrections to a For Review document and, when $post is
 * true, post it. $doc: documents row joined with the owner's full_name and
 * employment_type. $in: the review form (category "Type|Subtype",
 * academic_year, semester, period_year, expiration_date, details[...]).
 * Every value is checked here; returns [ok, message for the flash].
 */
function review_save_document(PDO $pdo, array $doc, array $in, array $admin, bool $post): array {
    if ($doc['status'] !== 'for_review') {
        return [false, 'That document is no longer waiting for review.'];
    }

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

    $sets = ['file_path = ?', 'reviewed_at = NOW()', 'reviewed_by = ?'];
    $params = [$file_path, (int)$admin['user_id']];
    foreach ($new as $col => $value) {
        $sets[] = "$col = ?";
        $params[] = $value;
    }
    if ($post) {
        $sets[] = "status = 'active'";
        $sets[] = 'posted_at = NOW()';
        $sets[] = 'posted_by = ?';
        $params[] = (int)$admin['user_id'];
    }
    $params[] = (int)$doc['document_id'];
    $stmt = $pdo->prepare("UPDATE documents SET " . implode(', ', $sets) . " WHERE document_id = ? AND status = 'for_review'");
    $stmt->execute($params);
    if ($stmt->rowCount() === 0 && $post) {
        return [false, 'That document is no longer waiting for review.'];
    }

    $label = document_type_label($type, $sub);
    $what = "document #{$doc['document_id']} \"" . basename($doc['file_path']) . "\" of {$doc['full_name']}";
    if ($type_changed) {
        log_my_activity($pdo, 'CATEGORY_CORRECT', "Corrected the category of {$what} from "
            . document_type_label($doc['document_type'], $doc['document_subtype']) . " to {$label} in Manage Document.");
    }
    $change_text = $changes ? ' -- ' . describe_filters($changes) : ' without changes';
    if ($post) {
        log_my_activity($pdo, 'DOCUMENT_POST', "Reviewed and posted {$what} as {$label}{$change_text}.");
        $owner_link = 'faculty/my_documents.php?type=' . rawurlencode($type);
        notify($pdo, (int)$doc['faculty_id'], 'Your ' . document_title(['title' => $details['title'] ?? $doc['title'], 'file_path' => $file_path])
            . " ({$label}) was reviewed and posted to your 201-Files."
            . ($type_changed ? ' Its document type was corrected from ' . document_type_label($doc['document_type'], $doc['document_subtype']) . '.' : ''),
            null, $owner_link);
        return [true, "Posted {$doc['full_name']}'s {$label} to 201-Files. {$doc['full_name']} has been notified."];
    }
    if ($changes) {
        log_my_activity($pdo, 'DOCUMENT_REVIEW_EDIT', "Corrected {$what} in Manage Document (still For Review){$change_text}.");
    }
    return [true, $changes ? 'Your corrections were saved. The document is still For Review.' : 'Nothing was changed.'];
}
