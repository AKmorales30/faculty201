<?php
/**
 * PDS versions (pds_versions). Every change that actually changes a
 * faculty member's digital PDS is saved as a new numbered version, with
 * the sections it changed, how (source), the uploaded document behind it,
 * who made it, and a snapshot of the whole PDS after the change -- so any
 * version can be viewed read-only (faculty/pds_print.php?version=ID).
 *
 *   source        when
 *   upload        automatic update from an uploaded PDS (pds_import_auto_apply()),
 *                 a re-import of one by its owner, or a certificate added to Section VI
 *   manual edit   saved on the PDS page, or the mobile number from Profile Preference
 *   admin edit    the Admin imported an uploaded PDS into someone's PDS
 *   undo          an automatic update undone (pds_undo_auto_update()) -- it adds
 *                 a version that restores the earlier values; none is deleted
 *   initial       version 1 of a PDS that existed before versions were kept
 *
 * Who sees what: faculty their own versions; the Admin anyone's; Program
 * Chairs / Deans the list for people in their scope (profile_scope_sql()),
 * but -- like the files -- only the owner and the Admin can open a
 * version's contents. All dates are Asia/Manila.
 *
 * Loaded by includes/pds.php.
 */

/** Section key => label, in the form's order (VI, Learning and Development, is 'LD'). */
function pds_section_labels(): array {
    $out = [];
    foreach (pds_schema() as $key => $part) {
        if ($key === 'VII') { $out['LD'] = 'Learning and Development'; }
        $out[$key] = $part['title'];
    }
    $out['VII'] = 'Voluntary Work';
    return $out;
}

/** Where a section opens on the PDS page (faculty/pds.php#partI, #partLD, ...). */
function pds_section_anchor(string $section): string {
    return 'part' . $section;
}

/** data key => section key: every field, every table, and each table's "N/A" box. */
function pds_section_of_keys(): array {
    $out = ['ld_na' => 'LD'];
    foreach (pds_schema() as $key => $part) {
        foreach (array_keys($part['fields'] ?? []) as $f) { $out[$f] = $key; }
        foreach (array_keys($part['tables'] ?? []) as $t) { $out[$t] = $key; $out[$t . '_na'] = $key; }
    }
    return $out;
}

/** The whole PDS as stored now: ['data' => Parts I-V, VII-..., 'ld' => Section VI rows]. */
function pds_current_state(PDO $pdo, int $faculty_id): array {
    $pds = pds_load($pdo, $faculty_id);
    $ld = array_map(fn($r) => array_intersect_key($r, pds_ld_columns() + ['source_document_id' => 1]), $pds['ld']);
    return ['data' => $pds['data'], 'ld' => array_values($ld), 'exists' => $pds['exists'] || $pds['ld']];
}

/** A value made comparable: trimmed text, numbers without trailing zeros, links to source documents dropped. */
function pds_state_norm($v) {
    if (is_array($v)) {
        unset($v['_source'], $v['source_document_id']);
        $out = [];
        foreach ($v as $k => $x) {
            $x = pds_state_norm($x);
            if ($x === '' || $x === []) { continue; }   // an empty value is the same as no value
            $out[$k] = $x;
        }
        if (array_is_list($v)) { return array_values($out); }
        ksort($out);
        return $out;
    }
    $s = trim((string)($v ?? ''));
    return is_numeric($s) ? (string)(0 + $s) : $s;
}

/** Section keys whose content differs between two states ($before null: the sections that have any content). */
function pds_state_changed_sections(?array $before, array $after): array {
    $of = pds_section_of_keys();
    $changed = [];
    $a = pds_state_norm($before['data'] ?? []);
    $b = pds_state_norm($after['data'] ?? []);
    foreach (array_unique(array_merge(array_keys($a), array_keys($b))) as $key) {
        if (($a[$key] ?? null) !== ($b[$key] ?? null) && isset($of[$key])) { $changed[$of[$key]] = true; }
    }
    if (pds_state_norm($before['ld'] ?? []) !== pds_state_norm($after['ld'] ?? [])) { $changed['LD'] = true; }
    return array_values(array_intersect(array_keys(pds_section_labels()), array_keys($changed)));
}

/** The latest version of a PDS, or null. */
function pds_latest_version(PDO $pdo, int $faculty_id): ?array {
    try {
        $stmt = $pdo->prepare(pds_versions_select() . " WHERE v.user_id = ? ORDER BY v.version_number DESC LIMIT 1");
        $stmt->execute([$faculty_id]);
        return $stmt->fetch() ?: null;
    } catch (PDOException $e) {
        return null;   // migration not applied yet
    }
}

/** Every version of a PDS, newest first (without the snapshots). */
function pds_versions(PDO $pdo, int $faculty_id): array {
    try {
        $stmt = $pdo->prepare(str_replace('v.snapshot, v.changes,', 'v.changes,', pds_versions_select()) . " WHERE v.user_id = ? ORDER BY v.version_number DESC");
        $stmt->execute([$faculty_id]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

/** One version (with its snapshot), or null. */
function pds_version(PDO $pdo, int $version_id): ?array {
    $stmt = $pdo->prepare(pds_versions_select() . " WHERE v.version_id = ?");
    $stmt->execute([$version_id]);
    return $stmt->fetch() ?: null;
}

function pds_versions_select(): string {
    return "SELECT v.version_id, v.user_id, v.version_number, v.changed_sections, v.source, v.source_document_id, v.changed_by,
                   v.snapshot, v.changes, v.undo_of, v.undone_at, v.undone_by, v.created_at,
                   u.full_name AS changed_by_name, u.role AS changed_by_role,
                   d.document_type AS source_document_type, d.file_path AS source_file_path, d.status AS source_document_status,
                   uv.version_number AS undo_of_number
            FROM pds_versions v
            LEFT JOIN users u ON u.user_id = v.changed_by
            LEFT JOIN documents d ON d.document_id = v.source_document_id
            LEFT JOIN pds_versions uv ON uv.version_id = v.undo_of";
}

/**
 * Save the PDS as it is now as a new version -- only if it differs from the
 * latest version. Call it right after the change (inside the change's
 * transaction when there is one). $changes: for an automatic update, what it
 * changed (for Undo) and what needs checking. Returns the new version, or
 * null when nothing changed.
 */
function pds_record_version(PDO $pdo, int $faculty_id, string $source, ?int $document_id, ?int $changed_by,
                            ?array $changes = null, ?int $undo_of = null): ?array {
    $state = pds_current_state($pdo, $faculty_id);
    $latest = pds_latest_version($pdo, $faculty_id);
    $before = $latest ? (json_decode($latest['snapshot'], true) ?: null) : null;
    $sections = pds_state_changed_sections($before, $state);
    if (!$sections) {
        return null;   // nothing actually changed
    }
    $number = (int)($latest['version_number'] ?? 0) + 1;
    $pdo->prepare(
        "INSERT INTO pds_versions (user_id, version_number, changed_sections, source, source_document_id, changed_by, snapshot, changes, undo_of, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    )->execute([
        $faculty_id, $number, implode(',', $sections), $source, $document_id, $changed_by,
        json_encode(['data' => $state['data'], 'ld' => $state['ld']]), $changes !== null ? json_encode($changes) : null,
        $undo_of, (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d H:i:s'),
    ]);
    return pds_version($pdo, (int)$pdo->lastInsertId());
}

/** "Personal Information, Learning and Development" */
function pds_sections_text(?string $sections): string {
    $labels = pds_section_labels();
    $keys = array_filter(explode(',', (string)$sections));
    return $keys ? implode(', ', array_map(fn($k) => $labels[$k] ?? $k, $keys)) : '—';
}

/** How a version came about: "Updated from uploaded PDS", "Edited manually", ... */
function pds_version_source_label(array $v): string {
    return match ($v['source']) {
        'upload'      => ($v['source_document_type'] ?? '') === 'Certificate' ? 'Added from an uploaded certificate' : 'Updated from uploaded PDS',
        'manual edit' => 'Edited manually',
        'admin edit'  => 'Edited by the Admin',
        'undo'        => 'Undo of version ' . ($v['undo_of_number'] ?? '?'),
        'initial'     => 'First recorded version',
        default       => ucfirst($v['source']),
    };
}

/** "October 20, 2026, 11:19 AM" (Asia/Manila). */
function pds_version_date(string $at): string {
    return (new DateTimeImmutable($at, new DateTimeZone('Asia/Manila')))->format('F j, Y, g:i A');
}

/** Whether $viewer may see the version list of $faculty_id's PDS. */
function pds_can_view_versions(PDO $pdo, array $viewer, int $faculty_id): bool {
    if ((int)$viewer['user_id'] === $faculty_id || $viewer['role'] === 'admin') { return true; }
    return in_array($viewer['role'], ['program_chair', 'dean'], true) && can_view_profile($pdo, $viewer, $faculty_id);
}

/** Whether $viewer may open what a version contained: its owner or the Admin (like the files). */
function pds_can_view_version_content(array $viewer, int $faculty_id): bool {
    return (int)$viewer['user_id'] === $faculty_id || $viewer['role'] === 'admin';
}

/**
 * The PDS line shown in 201-Files, on My PDS, on profiles and in reports:
 * "Personal Data Sheet — Version 4 · Last updated October 20, 2026, 11:19 AM",
 * then how and by whom, with a Version history button (pop-up) and -- for the
 * owner and the Admin -- a button to open the PDS. Empty when $viewer may not
 * see it. $open_button false: no button to open the PDS (on My PDS itself).
 */
function pds_version_card(PDO $pdo, int $faculty_id, array $viewer, bool $compact = false, bool $open_button = true): string {
    if (!pds_can_view_versions($pdo, $viewer, $faculty_id)) { return ''; }
    $v = pds_latest_version($pdo, $faculty_id);
    $own = (int)$viewer['user_id'] === $faculty_id;
    $history = '<button type="button" class="btn btn-sm btn-outline-brand" data-app-modal="' . h(BASE_URL . '/api/modal.php?view=pds_versions&user=' . $faculty_id)
             . '" data-app-modal-size="lg" data-app-modal-title="PDS version history"><i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i> Version history</button>';
    $open = '';
    if (!$open_button) {
        // already on the PDS
    } elseif ($own && $viewer['role'] !== 'admin') {
        $open = '<a href="' . BASE_URL . '/faculty/pds.php" class="btn btn-sm btn-outline-brand"><i class="fa-solid fa-id-card" aria-hidden="true"></i> Open My PDS</a>';
    } elseif ($viewer['role'] === 'admin') {
        $open = '<a href="' . BASE_URL . '/faculty/pds_print.php?faculty_id=' . $faculty_id . '" target="_blank" class="btn btn-sm btn-outline-brand"><i class="fa-solid fa-id-card" aria-hidden="true"></i> View Digital PDS</a>';
    }
    if (!$v) {
        $line = '<div class="pds-version-title">Personal Data Sheet — not filled in yet</div>';
        $sub = $own ? 'Fill it in on My PDS, or upload your PDS: it is read into your digital PDS automatically.' : 'No digital PDS yet.';
    } else {
        $line = '<div class="pds-version-title">Personal Data Sheet — Version ' . (int)$v['version_number'] . ' · Last updated ' . h(pds_version_date($v['created_at'])) . '</div>';
        $sub = h(pds_version_source_label($v)) . ($v['changed_by_name'] ? ' by ' . h($v['changed_by_name']) : '')
             . ($v['changed_sections'] && $v['source'] !== 'initial' ? ' · ' . h(pds_sections_text($v['changed_sections'])) : '');
    }
    return '<div class="pds-version' . ($compact ? ' pds-version-compact' : '') . '">'
         . '<span class="pds-version-icon" aria-hidden="true"><i class="fa-solid fa-id-card"></i></span>'
         . '<div class="pds-version-text">' . $line . '<div class="pds-version-sub">' . $sub . '</div></div>'
         . '<div class="pds-version-actions">' . ($v ? $history : '') . $open . '</div></div>';
}
