<?php
/**
 * Header search results (api/modal.php?view=search&q=...; "See all results"
 * and Enter in the top bar search): documents whose title, faculty name,
 * organizer, file name or extracted text (OCR) contains the words, or whose
 * document type matches them -- posted (active) documents only, like the
 * live search (api/search.php). The Admin searches every faculty member's;
 * anyone else only their own. No PDS of part-time faculty. Each result opens
 * its document pop-up. Expects $me, $pdo.
 */
$q = mb_substr(trim(preg_replace('/\s+/', ' ', (string)($_GET['q'] ?? ''))), 0, 100);
$is_admin = $me['role'] === 'admin';
$full_page = BASE_URL . '/' . ($is_admin ? 'admin/public_docs.php' : 'faculty/my_documents.php') . '?q=' . rawurlencode($q);
$limit = 50;
$rows = [];

if (mb_strlen($q) >= 2) {
    // Document types whose name contains the search: "cert" -> Certificates, "seminar" -> Certificates > Seminar
    $type_keys = [];
    $subtype_pairs = [];
    foreach (document_categories() as $key => $cat) {
        foreach ([$key, $cat['label'], $cat['short'], $cat['description'] ?? ''] as $name) {
            if ($name !== '' && mb_stripos($name, $q) !== false) { $type_keys[$key] = true; }
        }
        foreach ($cat['subtypes'] ?? [] as $skey => $slabel) {
            if (mb_stripos($slabel, $q) !== false) { $subtype_pairs[] = [$key, $skey]; }
        }
    }

    $like = '%' . addcslashes($q, '%_\\') . '%';
    $like_op = sql_like();
    $match = ["d.title $like_op ?", "d.file_path $like_op ?", "d.conducted_by $like_op ?", "d.ocr_extracted_text $like_op ?"];
    $params = [$like, $like, $like, $like];
    if ($is_admin) { $match[] = "u.full_name $like_op ?"; $params[] = $like; }
    if ($type_keys) {
        $match[] = "d.document_type IN (" . implode(',', array_fill(0, count($type_keys), '?')) . ")";
        array_push($params, ...array_keys($type_keys));
    }
    foreach ($subtype_pairs as [$t, $s]) {
        $match[] = "(d.document_type = ? AND d.document_subtype = ?)";
        array_push($params, $t, $s);
    }
    $sql = "SELECT d.document_id, d.faculty_id, d.document_type, d.document_subtype, d.title, d.file_path, d.status, d.filed_at,
                   d.academic_year, d.semester, d.period_year, u.full_name
            FROM documents d JOIN users u ON u.user_id = d.faculty_id
            WHERE d.status = 'active' AND (" . implode(' OR ', $match) . ")
              AND NOT (d.document_type = 'PDS' AND NOT " . sql_has_pds('u') . ")";
    if (!$is_admin) {
        $sql .= " AND d.faculty_id = ?";
        $params[] = (int)$me['user_id'];
    }
    $stmt = $pdo->prepare($sql . " ORDER BY d.filed_at DESC, d.document_id DESC LIMIT " . ($limit + 1));
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    log_my_activity($pdo, 'SEARCH', 'Header search' . ($is_admin ? '' : ' (own documents)') . ' -- ' . describe_filters(['Keywords' => $q])
        . ' (' . (count($rows) > $limit ? 'more than ' . $limit : count($rows)) . ' result' . (count($rows) === 1 ? '' : 's') . ').');
}
$more = count($rows) > $limit;
$rows = array_slice($rows, 0, $limit);
?>
<div data-modal-title="Search results<?= $q !== '' ? ' for “' . h($q) . '”' : '' ?><?= mb_strlen($q) >= 2 ? ' (' . ($more ? $limit . '+' : count($rows)) . ')' : '' ?>"></div>
<?php if (mb_strlen($q) < 2): ?>
  <p class="text-muted mb-0">Type at least 2 characters: a title, <?= $is_admin ? 'a faculty member\'s name, ' : '' ?>or a document type such as “TOR” or “certificate”.</p>
<?php else: ?>
  <p class="small text-muted mb-2">
    <?= $more ? 'The first ' . $limit : count($rows) ?> document<?= count($rows) === 1 ? '' : 's' ?>
    <?= $is_admin ? 'across all faculty' : 'in your 201 file' ?> · click one to preview it.
  </p>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light">
        <tr><th>Document</th><?php if ($is_admin): ?><th>Faculty</th><?php endif; ?><th>Type</th><th>Status</th><th class="text-nowrap">Uploaded</th></tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="<?= $is_admin ? 5 : 4 ?>" class="text-center text-muted py-4">No records found for “<?= h($q) ?>”.</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $r): [$st_label, $st_badge] = document_status_badge($r['status']); ?>
          <tr class="row-clickable" tabindex="0" role="button" data-app-modal="<?= h(BASE_URL . '/api/modal.php?view=document&id=' . (int)$r['document_id']) ?>">
            <td class="text-break"><span class="fw-semibold"><?= h(document_title($r)) ?></span></td>
            <?php if ($is_admin): ?><td><?= h($r['full_name']) ?></td><?php endif; ?>
            <td class="small"><?= h(document_type_label($r['document_type'], $r['document_subtype'])) ?><?php if ($p = document_period_label($r)): ?><div class="text-muted"><?= h($p) ?></div><?php endif; ?></td>
            <td><span class="badge <?= $st_badge ?>"><?= h($st_label) ?></span></td>
            <td class="small text-nowrap"><?= h(date('M j, Y', strtotime($r['filed_at']))) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div data-modal-footer>
    <a href="<?= h($full_page) ?>"><i class="fa-solid fa-sliders"></i> Search with more filters in <?= $is_admin ? '201-Files' : 'your 201-Files' ?></a>
  </div>
<?php endif; ?>
