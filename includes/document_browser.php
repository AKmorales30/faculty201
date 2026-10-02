<?php
/**
 * 201-file folder tiles + document list, shared by Faculty "My 201 File"
 * and the Admin per-faculty drill-down.
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

$stmt = $pdo->prepare("SELECT * FROM documents WHERE faculty_id = ?");
$stmt->execute([$faculty['user_id']]);
$all_docs = $stmt->fetchAll();
$latest_ids = latest_document_ids($all_docs);

$documents = array_filter($all_docs, function ($d) use ($active_type, $active_sub, $q) {
    if ($active_type !== '' && $d['document_type'] !== $active_type) { return false; }
    if ($active_sub !== '' && $d['document_subtype'] !== $active_sub) { return false; }
    if ($q !== '' && stripos(($d['ocr_extracted_text'] ?? '') . ' ' . $d['file_path'], $q) === false) { return false; }
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
          </div>
        </div>
      </div>
    </a>
  </div>
  <?php endforeach; ?>
</div>

<div class="card stat-card">
  <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
    <span class="fw-semibold">
      <?= $active_type ? h($all_categories[$active_type]['label']) : 'All Documents (latest first)' ?>
      <?php if ($active_type): ?><a href="<?= h(rtrim($link_base, '?&')) ?: '?' ?>" class="small ms-2 fw-normal">(show all)</a><?php endif; ?>
    </span>
    <form class="d-flex" method="GET">
      <?php foreach (['id' => $_GET['id'] ?? null, 'type' => $active_type ?: null, 'sub' => $active_sub ?: null] as $k => $v): if ($v === null) continue; ?>
        <input type="hidden" name="<?= $k ?>" value="<?= h((string)$v) ?>">
      <?php endforeach; ?>
      <input type="search" name="q" value="<?= h($q) ?>" class="form-control form-control-sm" placeholder="Search extracted text or filename...">
      <button class="btn btn-sm btn-outline-brand ms-2"><i class="fa-solid fa-magnifying-glass"></i></button>
    </form>
  </div>

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

  <div class="card-body p-0 table-responsive">
    <table class="table mb-0 align-middle">
      <thead class="table-light">
        <tr><th>Type</th><th>Period</th><th>File</th><th>Matched Name</th><th>Expiration</th><th>Uploaded</th><th></th></tr>
      </thead>
      <tbody>
        <?php if (!$documents): ?>
          <tr><td colspan="7" class="text-center text-muted py-4">No documents here yet.</td></tr>
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
            </div>
          </td>
          <td class="small"><?= h(document_period_label($d)) ?: '<span class="text-muted">—</span>' ?></td>
          <td class="small text-break"><?= h(basename($d['file_path'])) ?></td>
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
          <td><a href="<?= h(document_url((int)$d['document_id'])) ?>" target="_blank" class="btn btn-sm btn-outline-brand text-nowrap"><i class="fa-solid fa-eye"></i> View</a></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
