<?php
/**
 * 201-file folder tiles + document list of posted documents, shared by the
 * faculty member's 201-Files and the Admin per-faculty drill-down. View
 * opens the preview pop-up.
 *
 * Expects: $pdo, $faculty (users row), $link_base (query-string prefix
 * for this page, e.g. '?' or '?id=12&').
 *
 * Within a category the latest document comes first (FTA / IPCR by
 * latest semester) and is badged "Latest"; older versions stay listed
 * as history -- nothing is ever replaced.
 */
$all_categories = document_categories();
$counts = faculty_document_counts($pdo, (int)$faculty['user_id']);
// Folders that apply to this faculty member, plus any that already hold files
$folder_categories = array_filter($all_categories, function ($cat, $key) use ($faculty, $counts) {
    return $cat['applies_to'] === null || $cat['applies_to'] === $faculty['employment_type'] || ($counts[$key] ?? 0) > 0;
}, ARRAY_FILTER_USE_BOTH);

$active_type = $_GET['type'] ?? '';
if (!array_key_exists($active_type, $all_categories)) { $active_type = ''; }
$active_sub = $_GET['sub'] ?? '';
if ($active_type === '' || !isset($all_categories[$active_type]['subtypes'][$active_sub])) { $active_sub = ''; }
$q = trim($_GET['q'] ?? '');

// Posted documents (flagged ones included, with a badge) -- archived ones in
// the faculty member's archive (archive.php), deleted ones on the Admin's Archived Docs page
$stmt = $pdo->prepare("SELECT d.*, " . delete_window_sql('d') . " AS delete_seconds_left, ? AS full_name
                       FROM documents d WHERE d.faculty_id = ? AND d.status = 'active'");
$stmt->execute([$faculty['full_name'], $faculty['user_id']]);
$all_docs = $stmt->fetchAll();
$latest_ids = latest_document_ids($all_docs);

$documents = array_filter($all_docs, function ($d) use ($active_type, $active_sub, $q) {
    if ($active_type !== '' && $d['document_type'] !== $active_type) { return false; }
    if ($active_sub !== '' && $d['document_subtype'] !== $active_sub) { return false; }
    if ($q !== '' && stripos(($d['ocr_extracted_text'] ?? '') . ' ' . $d['file_path'] . ' ' . $d['title'] . ' ' . $d['conducted_by'] . ' ' . $d['venue'], $q) === false) { return false; }
    return true;
});
if ($q !== '') {
    log_my_activity($pdo, 'SEARCH', '201 file of ' . $faculty['full_name'] . ' -- ' . describe_filters([
        'Keywords' => $q,
        'Folder'   => $active_type !== '' ? document_type_label($active_type, $active_sub ?: null) : '',
    ]) . ' (' . count($documents) . ' result' . (count($documents) === 1 ? '' : 's') . ').');
}
if ($active_type !== '') {
    usort($documents, 'compare_documents_latest_first');
} else {
    usort($documents, fn($a, $b) => strcmp($b['filed_at'], $a['filed_at']));
}

$sub_counts = [];
foreach ($all_docs as $d) {
    if ($d['document_type'] === $active_type) {
        $sub_counts[$d['document_subtype'] ?? ''] = ($sub_counts[$d['document_subtype'] ?? ''] ?? 0) + 1;
    }
}
include_once __DIR__ . '/document_actions.php';   // Delete / Archive buttons + confirmation dialog
require_once __DIR__ . '/pds.php';                 // the digital PDS's version (PDS folder)
$pds_latest = pds_latest_version($pdo, (int)$faculty['user_id']);
$show_all_url = rtrim($link_base, '?&') ?: '?';
?>

<div class="row g-3 mb-4">
  <?php foreach ($folder_categories as $key => $meta): ?>
  <div class="col-6 col-md-4 col-xl-3">
    <a href="<?= h($link_base) ?>type=<?= h($key) ?>" class="text-decoration-none">
      <div class="card folder-card h-100 <?= $active_type === $key ? 'folder-card-active' : '' ?>">
        <div class="card-body d-flex align-items-center gap-3">
          <div class="folder-icon folder-icon-<?= $meta['color'] ?>"><i class="fa-solid <?= $meta['icon'] ?>"></i></div>
          <div class="min-w-0">
            <div class="fw-semibold text-charcoal folder-title"><?= h($meta['short']) ?></div>
            <div class="text-muted small"><?= (int)($counts[$key] ?? 0) ?> file<?= ($counts[$key] ?? 0) === 1 ? '' : 's' ?></div>
            <?php if ($key === 'PDS' && $pds_latest): ?><div class="text-muted small">Digital PDS: Version <?= (int)$pds_latest['version_number'] ?> · <?= h(date('M j, Y', strtotime($pds_latest['created_at']))) ?></div><?php endif; ?>
          </div>
        </div>
      </div>
    </a>
  </div>
  <?php endforeach; ?>
</div>

<?php if ($active_type === 'PDS'): /* "Personal Data Sheet — Version 4 · Last updated ..." with the version history */ ?>
  <?= pds_version_card($pdo, (int)$faculty['user_id'], current_user()) ?>
<?php endif; ?>
<section class="ui-card" aria-label="Documents">
  <form class="ui-toolbar" method="GET" role="search">
    <?php foreach (['id' => $_GET['id'] ?? null, 'type' => $active_type ?: null, 'sub' => $active_sub ?: null] as $k => $v): if ($v === null) continue; ?>
      <input type="hidden" name="<?= $k ?>" value="<?= h((string)$v) ?>">
    <?php endforeach; ?>
    <label class="ui-search"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
      <input type="search" name="q" value="<?= h($q) ?>" placeholder="Search extracted text or file name" aria-label="Search documents"></label>
    <button class="btn btn-sm btn-brand"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i> Search</button>
    <?php if ($q !== '' || $active_type): ?><a href="<?= h($show_all_url) ?>" class="btn btn-sm btn-outline-secondary">Clear filters</a><?php endif; ?>
    <span class="ui-count"><?= $active_type ? h($all_categories[$active_type]['label']) . ' · ' : 'All documents, latest first · ' ?><?= count($documents) ?> result<?= count($documents) === 1 ? '' : 's' ?></span>
  </form>

  <?php if ($active_type && !empty($all_categories[$active_type]['subtypes'])): ?>
  <div class="px-3 pt-3 d-flex flex-wrap gap-2">
    <a href="<?= h($link_base) ?>type=<?= h($active_type) ?>" class="btn btn-sm <?= $active_sub === '' ? 'btn-brand' : 'btn-outline-brand' ?>">All</a>
    <?php foreach ($all_categories[$active_type]['subtypes'] as $skey => $slabel): ?>
      <a href="<?= h($link_base) ?>type=<?= h($active_type) ?>&amp;sub=<?= h($skey) ?>" class="btn btn-sm <?= $active_sub === $skey ? 'btn-brand' : 'btn-outline-brand' ?>">
        <?= h($slabel) ?> <span class="badge bg-light text-dark ms-1"><?= (int)($sub_counts[$skey] ?? 0) ?></span>
      </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if ($active_type && !empty($all_categories[$active_type]['description'])): ?>
    <div class="px-3 pt-2 small text-muted"><?= h($all_categories[$active_type]['description']) ?></div>
  <?php endif; ?>

  <div class="table-responsive">
    <table class="table mb-0 align-middle">
      <thead class="table-light">
        <tr><th>Type</th><th>Period</th><th>File</th><th>Matched Name</th><th>Expiration</th><th>Uploaded</th><th class="text-end"><span class="visually-hidden">Actions</span></th></tr>
      </thead>
      <tbody>
        <?php if (!$documents): ?>
          <?= admin_empty_row(7, $q !== '' || $active_type ? 'No documents match' : 'No documents here yet',
                $q !== '' || $active_type ? 'Try another search or folder, or clear the filters.' : 'Posted documents will appear here.',
                $q !== '' || $active_type ? $show_all_url : null, 'fa-folder-open') ?>
        <?php endif; ?>
        <?php foreach ($documents as $d):
          $expiring = $d['expiration_date'] && strtotime($d['expiration_date']) <= strtotime('+60 days');
          $is_latest = isset($latest_ids[(int)$d['document_id']]);
        ?>
        <tr class="<?= $is_latest ? '' : 'doc-history' ?>">
          <td>
            <?= h(document_type_label($d['document_type'], $d['document_subtype'])) ?>
            <div>
              <?php if ($is_latest): ?>
                <span class="badge bg-success">Latest</span>
              <?php else: ?>
                <span class="badge bg-light text-muted border">Previous version</span>
              <?php endif; ?>
              <?php if (document_is_flagged($d)): ?>
                <span class="badge bg-review doc-flag-badge" data-tooltip title="<?= h(ucfirst(document_flag_text($d['flag_reason']))) ?>. It stays in 201-Files; the Admin may correct its type or details.">Flagged for checking</span>
              <?php endif; ?>
            </div>
          </td>
          <td class="small"><?= h(document_period_label($d)) ?: '<span class="text-muted">—</span>' ?></td>
          <td class="small text-break">
            <?php if (trim((string)$d['title']) !== ''): ?>
              <div class="fw-semibold"><?= h($d['title']) ?></div>
              <?php if ($d['document_type'] === 'Certificate'): ?>
                <div class="text-muted"><?= h(implode(' · ', array_filter([$d['date_start'] ? document_date_range_label($d['date_start'], $d['date_end']) : null, $d['conducted_by'], training_type_label($d)]))) ?></div>
              <?php endif; ?>
            <?php elseif ($d['document_type'] === 'Certificate'): ?>
              <div class="text-muted fst-italic">Seminar / training details not specified</div>
            <?php endif; ?>
            <div class="<?= trim((string)$d['title']) !== '' || $d['document_type'] === 'Certificate' ? 'text-muted' : '' ?>"><?= h(basename($d['file_path'])) ?></div>
          </td>
          <td>
            <?php if ($d['ocr_matched_name']): ?>
              <span class="badge bg-success"><i class="fa-solid fa-check"></i> <?= h($d['ocr_matched_name']) ?></span>
            <?php else: ?>
              <span class="badge bg-secondary">Not matched</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($d['expiration_date']): ?>
              <span class="<?= $expiring ? 'text-danger fw-semibold' : '' ?>">
                <?= date('M j, Y', strtotime($d['expiration_date'])) ?>
                <?= $expiring ? '<i class="fa-solid fa-triangle-exclamation ms-1" title="Expiring soon"></i>' : '' ?>
              </span>
            <?php else: ?>
              <span class="text-muted">—</span>
            <?php endif; ?>
          </td>
          <td class="text-nowrap"><?= date('M j, Y', strtotime($d['filed_at'])) ?></td>
          <td>
            <?php $can_import = $d['document_type'] === 'PDS' && (current_user()['role'] === 'admin' || (int)$d['faculty_id'] === (int)current_user()['user_id']); ?>
            <div class="ui-actions">
              <?= admin_icon_link(document_url((int)$d['document_id']), 'fa-eye', 'View file', 'target="_blank" data-app-modal="' . h(BASE_URL . '/api/modal.php?view=document&id=' . (int)$d['document_id']) . '"') ?>
              <?php if ($can_import): ?><?= admin_icon_link(BASE_URL . '/faculty/pds_import.php?document=' . (int)$d['document_id'], 'fa-file-import', 'Import to PDS') ?><?php endif; ?>
              <?php if (can_edit_document_details(current_user(), $d)): ?><?= admin_icon_link(BASE_URL . '/document_details.php?id=' . (int)$d['document_id'] . '&return=' . urlencode($doc_action_return), 'fa-pen-to-square', 'Edit details') ?><?php endif; ?>
              <?= admin_icon_link(document_url((int)$d['document_id']) . '&download=1', 'fa-download', 'Download') ?>
              <?= document_action_buttons($d, current_user()) ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
