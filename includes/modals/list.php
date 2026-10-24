<?php
/**
 * One-category list pop-up (api/modal.php?view=list&category=...). Every
 * clickable count / "View list" on the dashboards passes its own category,
 * and only that category's records are loaded here -- filtered in SQL / PHP
 * on the server, never just hidden in the browser. The title says which
 * list it is and how many records it has; each category shows only the
 * columns that matter for it.
 *
 *   category             who                   what
 *   pds_update           Admin, Chair, Dean    faculty whose PDS needs updating
 *   missing_documents    Admin, Chair, Dean    faculty missing required documents
 *   no_recent_seminar    Admin, Chair, Dean    faculty with no seminar / training lately
 *   expiring_followup    Admin, Chair, Dean    faculty with expired / expiring documents
 *     (the four above: the daily reminder check's open reminders, within the
 *      viewer's scope -- profile_scope_sql(); the details are worked out now)
 *   expiring             everyone              documents expired or expiring within 60 days
 *   expired, expiring_7, expiring_30, expiring_60   one expiration bucket of those
 *   flagged              everyone              uploads flagged for checking (already in 201-Files);
 *                                              'for_review' is the old name
 *   recent_uploads       everyone              the latest uploads
 *   (&program=KEY on the four faculty lists: only that program -- Analytics)
 *     (document lists: the Admin sees every faculty member's; anyone else
 *      only their own -- Program Chairs and Deans can't open faculty files)
 *
 * Expects $pdo, $me (api/modal.php). Every value shown is escaped (h()).
 */
require_once __DIR__ . '/../pds.php';

$is_admin = $me['role'] === 'admin';
$overseer = in_array($me['role'], ['admin', 'program_chair', 'dean'], true);
$uid = (int)$me['user_id'];

/** category => [title, reminder type (faculty lists) / expiration bucket, full-page link (relative to BASE_URL)] */
$categories = [
    'pds_update'        => ['PDS for Update', 'pds', 'reminder_list.php?type=pds'],
    'missing_documents' => ['Missing Required Documents', 'missing_docs', 'reminder_list.php?type=missing_docs'],
    'no_recent_seminar' => ['No Recent Seminar / Training', 'no_seminar', 'reminder_list.php?type=no_seminar'],
    'expiring_followup' => ['Faculty with Expired / Expiring Documents', 'expiring', 'reminder_list.php?type=expiring'],
    'expiring'          => ['Expiring / Expired Documents', null, $is_admin ? 'admin/expiring_documents.php' : null],
    'expired'           => ['Expired Documents', 'expired', $is_admin ? 'admin/expiring_documents.php?status=expired' : null],
    'expiring_7'        => ['Expiring Within 7 Days', '7', $is_admin ? 'admin/expiring_documents.php?status=7' : null],
    'expiring_30'       => ['Expiring in 8–30 Days', '30', $is_admin ? 'admin/expiring_documents.php?status=30' : null],
    'expiring_60'       => ['Expiring in 31–60 Days', '60', $is_admin ? 'admin/expiring_documents.php?status=60' : null],
    'flagged'           => ['Flagged for checking', null, $is_admin ? 'admin/manage_documents.php' : 'faculty/my_documents.php?tab=flagged'],
    'recent_uploads'    => ['Recent File Uploads', null, $is_admin ? 'admin/public_docs.php' : 'faculty/my_requests.php'],
];
$category = (string)($_GET['category'] ?? '');
if ($category === 'for_review') { $category = 'flagged'; }   // old links
if (!isset($categories[$category])) {
    modal_fail(404, 'Unknown list.');
}
[$title, $key, $full_page] = $categories[$category];
$faculty_list = in_array($category, ['pds_update', 'missing_documents', 'no_recent_seminar', 'expiring_followup'], true);
if ($faculty_list && !$overseer) {
    modal_fail(403, 'You do not have access to this list.');
}
$fmt = fn(?string $d, string $f = 'M j, Y'): string => $d ? date($f, strtotime($d)) : '—';
$doc_link = fn(int $id): string => BASE_URL . '/api/modal.php?view=document&id=' . $id;
$rows = [];

// ---------------------------------------------------------------------
// Load only this category's records
// ---------------------------------------------------------------------
if ($faculty_list) {
    try {
        $people = reminder_scope_list($pdo, $me, $key);   // open reminders of this type, in the viewer's scope
    } catch (PDOException $e) {
        $people = [];   // migration not applied yet
    }
    // ?program= (a Dean's Analytics filter): only that program -- it narrows the scoped list, never widens it
    $only_program = (string)($_GET['program'] ?? '');
    if ($only_program !== '' && isset(PROGRAMS[$only_program])) {
        $people = array_values(array_filter($people, fn($p) => ($p['program'] ?? null) === $only_program));
        $title .= ' — ' . PROGRAMS[$only_program]['label'];
        $full_page = null;   // the full list page has no program filter
    }
    foreach ($people as $p) {
        $pid = (int)$p['user_id'];
        $row = ['user' => $p, 'reason' => $p['reason'], 'reminded' => $p['created_at']];
        if ($category === 'pds_update') {
            $pds = pds_load($pdo, $pid);
            $status = pds_status($pdo, $pid);
            $row['sections'] = ($pds['exists'] || $pds['ld']) ? array_keys(pds_validate_by_section($pds['data'], $pds['ld'])) : null;   // null = not filled in
            $row['updated_at'] = $status['updated_at'];
            $row['version'] = pds_latest_version($pdo, $pid);
            $row['current'] = $status['current'];
            $row['year'] = $status['year'];
        } elseif ($category === 'missing_documents') {
            $u = user_row($pdo, $pid);
            $paused = ($u['employment_status'] ?? null) === 'paused';   // no FTA / IPCR expected (same rule as the reminder)
            $row['missing'] = [];
            foreach (faculty_201_checklist($pdo, $pid, $u['employment_type'] ?? null) as $item) {
                if ($item['done'] || $item['type'] === 'PDS' || ($paused && in_array($item['type'], ['FTA', 'IPCR'], true))) { continue; }
                $row['missing'][] = reminder_checklist_label($item['label']);
            }
        } elseif ($category === 'no_recent_seminar') {
            $stmt = $pdo->prepare(
                "SELECT title, file_path, filed_at, date_start, date_end FROM documents
                 WHERE faculty_id = ? AND document_type = 'Certificate' AND status IN ('active', 'for_review', 'archived')
                   AND (document_subtype IN ('Seminar', 'Training') OR training_type IS NOT NULL)
                 ORDER BY filed_at DESC LIMIT 1"
            );
            $stmt->execute([$pid]);
            $row['last'] = $stmt->fetch() ?: null;
        } else {   // expiring_followup
            $row['docs'] = expiring_documents($pdo, $pid, (int)REMINDER_EXPIRING_DAYS);
        }
        $rows[] = $row;
    }
} elseif ($category === 'flagged') {
    $sql = "SELECT d.document_id, d.faculty_id, d.document_type, d.document_subtype, d.title, d.file_path, d.filed_at,
                   d.confidence_score, d.entry_method, d.flag_reason, u.full_name
            FROM documents d JOIN users u ON u.user_id = d.faculty_id
            WHERE " . document_flagged_sql('d') . ($is_admin ? '' : ' AND d.faculty_id = ?') . "
            ORDER BY d.filed_at ASC, d.document_id ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($is_admin ? [] : [$uid]);
    $rows = $stmt->fetchAll();
} elseif ($category === 'recent_uploads') {
    $stmt = $pdo->prepare(
        "SELECT sr.submitted_at, sr.status, sr.document_type_hint, u.full_name,
                d.document_id, d.document_type, d.document_subtype, d.title, d.file_path, d.status AS document_status
         FROM submission_requests sr
         JOIN users u ON u.user_id = sr.faculty_id
         LEFT JOIN documents d ON d.request_id = sr.request_id
         WHERE " . ($is_admin ? '1=1' : 'sr.faculty_id = ?') . "
         ORDER BY sr.submitted_at DESC, sr.request_id DESC LIMIT 50"
    );
    $stmt->execute($is_admin ? [] : [$uid]);
    $rows = $stmt->fetchAll();
} else {   // expiring documents: all of them, or one bucket
    $all = expiring_documents($pdo, $is_admin ? null : $uid);
    $rows = $key === null ? $all : array_values(array_filter($all, fn($d) => expiration_milestone($d['days_left']) === $key));
}

$count = count($rows);
$show_faculty = $is_admin;   // document lists: only the Admin's span several people
?>
<div data-modal-title="<?= h($title) ?> (<?= $count ?><?= $category === 'recent_uploads' && $count === 50 ? '+' : '' ?>)"></div>

<?php if (!$rows): ?>
  <div class="text-center text-muted py-5"><i class="fa-regular fa-folder-open fa-2x mb-2 d-block" aria-hidden="true"></i>No records found.</div>

<?php elseif ($faculty_list): ?>
  <p class="small text-muted mb-2">From the daily reminder check<?= $me['role'] === 'program_chair' ? ', for the faculty of your program' : ($me['role'] === 'dean' ? ', for the faculty and Program Chairs of your college' : '') ?>. Each person has already been reminded.</p>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th>Faculty</th>
          <?php if ($category === 'pds_update'): ?><th>Incomplete PDS Sections</th><th class="text-nowrap">PDS Version · Last Updated</th>
          <?php elseif ($category === 'missing_documents'): ?><th>Missing Documents</th>
          <?php elseif ($category === 'no_recent_seminar'): ?><th>Latest Seminar / Training Certificate</th><th class="text-nowrap">Uploaded</th>
          <?php else: ?><th>Expired / Expiring Documents</th><th class="text-nowrap">Soonest</th><?php endif; ?>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): $p = $r['user']; ?>
          <tr>
            <td style="min-width:14rem">
              <div class="fw-semibold"><?= h($p['full_name']) ?></div>
              <div class="small text-muted"><?= h(user_position_line($p)) ?></div>
            </td>
            <?php if ($category === 'pds_update'): ?>
              <td class="small">
                <?php if ($r['sections'] === null): ?>
                  <span class="text-danger">Not filled in yet</span>
                <?php elseif ($r['sections']): ?>
                  <?= h(implode(', ', $r['sections'])) ?>
                <?php else: ?>
                  <span class="text-muted">All sections complete</span>
                <?php endif; ?>
                <?php if (!$r['current']): ?><div class="text-muted">Not yet updated for <?= (int)$r['year'] ?></div><?php endif; ?>
              </td>
              <td class="small text-nowrap">
                <?php if ($r['version']): ?>Version <?= (int)$r['version']['version_number'] ?> · <?= h(pds_version_date($r['version']['created_at'])) ?>
                  <div class="text-muted"><?= h(pds_version_source_label($r['version'])) ?></div>
                <?php else: ?><?= $r['updated_at'] ? h($fmt($r['updated_at'])) : '<span class="text-muted">Never</span>' ?><?php endif; ?>
              </td>
            <?php elseif ($category === 'missing_documents'): ?>
              <td class="small"><?= $r['missing'] ? h(implode('; ', $r['missing'])) : h($r['reason']) ?></td>
            <?php elseif ($category === 'no_recent_seminar'): ?>
              <td class="small"><?= $r['last'] ? h(document_title($r['last'])) : '<span class="text-muted">None uploaded yet</span>' ?></td>
              <td class="small text-nowrap"><?= $r['last'] ? h($fmt($r['last']['filed_at'])) : '—' ?></td>
            <?php else:
              $expired = count(array_filter($r['docs'], fn($d) => $d['days_left'] < 0)); ?>
              <td class="small">
                <?php if ($r['docs']): ?>
                  <?= $expired ? '<span class="text-danger">' . $expired . ' expired</span>' : '' ?><?= $expired && count($r['docs']) > $expired ? ', ' : '' ?><?= count($r['docs']) > $expired ? (count($r['docs']) - $expired) . ' expiring within ' . (int)REMINDER_EXPIRING_DAYS . ' days' : '' ?>
                  <div class="text-muted"><?= h(reminder_list_text(array_map(fn($d) => document_type_label($d['document_type'], $d['document_subtype']), $r['docs']), 3)) ?></div>
                <?php else: ?><?= h($r['reason']) ?><?php endif; ?>
              </td>
              <td class="small text-nowrap"><?= $r['docs'] ? h($fmt($r['docs'][0]['expiration_date'])) : '—' ?></td>
            <?php endif; ?>
            <td class="text-end"><a href="<?= BASE_URL ?>/profile.php?id=<?= (int)$p['user_id'] ?>" class="btn btn-sm btn-outline-brand text-nowrap"><i class="fa-solid fa-circle-user"></i> View</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

<?php elseif ($category === 'flagged'): ?>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light">
        <tr><th>Document</th><?php if ($show_faculty): ?><th>Faculty</th><?php endif; ?><th>Document Type</th><th>Why flagged</th><?php if ($is_admin): ?><th>Confidence</th><?php endif; ?><th class="text-nowrap">Uploaded</th><th></th></tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $d): $open = $is_admin ? BASE_URL . '/api/modal.php?view=review&id=' . (int)$d['document_id'] : $doc_link((int)$d['document_id']); ?>
          <tr class="row-clickable" tabindex="0" role="button" data-app-modal="<?= h($open) ?>">
            <td class="text-break small fw-semibold"><?= h(document_title($d)) ?></td>
            <?php if ($show_faculty): ?><td class="small"><?= h($d['full_name']) ?></td><?php endif; ?>
            <td class="small"><?= h(document_type_label($d['document_type'], $d['document_subtype'])) ?></td>
            <td class="small"><?= h(implode(', ', document_flag_labels($d['flag_reason']))) ?></td>
            <?php if ($is_admin): ?><td><span class="badge <?= confidence_badge_class($d['confidence_score']) ?>"><?= h($d['entry_method'] === 'manual' ? 'Manual' : confidence_label($d['confidence_score'])) ?></span></td><?php endif; ?>
            <td class="small text-nowrap"><?= h($fmt($d['filed_at'], 'M j, Y g:ia')) ?></td>
            <td class="text-end"><button type="button" class="btn btn-sm <?= $is_admin ? 'btn-brand' : 'btn-outline-brand' ?> text-nowrap" data-app-modal="<?= h($open) ?>"><i class="fa-solid <?= $is_admin ? 'fa-list-check' : 'fa-eye' ?>"></i> <?= $is_admin ? 'Check' : 'View' ?></button></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

<?php elseif ($category === 'recent_uploads'): ?>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light">
        <tr><th>File</th><?php if ($show_faculty): ?><th>Faculty</th><?php endif; ?><th>Document Type</th><th>Status</th><th class="text-nowrap">Uploaded</th></tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): [$label, $badge] = upload_status_badge($r);
          $openable = $r['document_id'] && ($is_admin || in_array($r['document_status'], ['active', 'for_review', 'archived', 'deleted'], true)); ?>
          <tr<?php if ($openable): ?> class="row-clickable" tabindex="0" role="button" data-app-modal="<?= h($doc_link((int)$r['document_id'])) ?>"<?php endif; ?>>
            <td class="text-break small fw-semibold"><?= $r['document_id'] ? h(document_title($r)) : '<span class="text-muted">—</span>' ?></td>
            <?php if ($show_faculty): ?><td class="small"><?= h($r['full_name']) ?></td><?php endif; ?>
            <td class="small"><?= h(document_type_label($r['document_type'] ?? $r['document_type_hint'], $r['document_subtype'])) ?></td>
            <td><span class="badge <?= $badge ?>"><?= h($label) ?></span></td>
            <td class="small text-nowrap"><?= h($fmt($r['submitted_at'], 'M j, Y g:ia')) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

<?php else: ?>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light">
        <tr><?php if ($show_faculty): ?><th>Faculty</th><?php endif; ?><th>Document</th><th>Document Type</th><th class="text-nowrap">Expires</th><th>Status</th></tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $d): [$badge_label, $badge_class] = expiration_badge($d['days_left']); ?>
          <tr class="row-clickable" tabindex="0" role="button" data-app-modal="<?= h($doc_link((int)$d['document_id'])) ?>">
            <?php if ($show_faculty): ?><td class="small"><?= h($d['full_name']) ?></td><?php endif; ?>
            <td class="text-break small fw-semibold"><?= h(document_display_name($d['file_path'])) ?></td>
            <td class="small"><?= h(document_type_label($d['document_type'], $d['document_subtype'])) ?></td>
            <td class="small text-nowrap"><?= h($fmt($d['expiration_date'])) ?></td>
            <td><span class="badge <?= $badge_class ?>"><?= h($badge_label) ?></span></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<?php if ($full_page): ?>
  <div data-modal-footer><a href="<?= h(BASE_URL . '/' . $full_page) ?>"><i class="fa-solid fa-up-right-from-square"></i> Open as a full page</a></div>
<?php endif; ?>
