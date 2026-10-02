<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_role('admin');

$page_title = 'Search Documents';
$categories = document_categories();

$q            = trim($_GET['q'] ?? '');
$type         = $_GET['type'] ?? '';
$expiring_only = isset($_GET['expiring_only']);
if (!array_key_exists($type, $categories)) { $type = ''; }

$sql = "SELECT d.*, u.full_name, u.employment_type
        FROM documents d
        JOIN users u ON u.user_id = d.faculty_id
        WHERE 1=1";
$params = [];

if ($q !== '') {
    $sql .= " AND (u.full_name LIKE ? OR d.ocr_extracted_text LIKE ? OR d.ocr_matched_name LIKE ? OR d.file_path LIKE ?)";
    $like = "%$q%";
    array_push($params, $like, $like, $like, $like);
}
if ($type !== '') {
    $sql .= " AND d.document_type = ?";
    $params[] = $type;
}
if ($expiring_only) {
    $sql .= " AND d.expiration_date IS NOT NULL AND d.expiration_date <= DATE_ADD(CURDATE(), INTERVAL 60 DAY)";
}
$sql .= " ORDER BY d.filed_at DESC LIMIT 200";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$documents = $stmt->fetchAll();

if ($q !== '' || $type !== '' || $expiring_only) {   // a search was run, not just the page opened
    log_my_activity($pdo, 'SEARCH', 'Search Documents -- ' . describe_filters([
        'Keywords' => $q, 'Type' => $type !== '' ? $categories[$type]['label'] : '', 'Expiring only' => $expiring_only,
    ]) . ' (' . count($documents) . ' result' . (count($documents) === 1 ? '' : 's') . ').');
}

include __DIR__ . '/../includes/header.php';
?>

<h3 class="fw-bold mb-1">Search Documents</h3>
<p class="text-muted mb-4">Search and filter across every faculty member's filed 201-file documents.</p>

<div class="card stat-card mb-4">
  <div class="card-body">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-md-5">
        <label class="form-label small fw-semibold">Search / Filter Documents</label>
        <input type="search" name="q" value="<?= h($q) ?>" class="form-control" placeholder="Faculty name, extracted text, filename...">
      </div>
      <div class="col-md-3">
        <label class="form-label small fw-semibold">Document Type</label>
        <select name="type" class="form-select">
          <option value="">All Types</option>
          <?php foreach ($categories as $key => $meta): ?>
            <option value="<?= h($key) ?>" <?= $type === $key ? 'selected' : '' ?>><?= h($meta['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3 form-check ms-md-2">
        <input type="checkbox" name="expiring_only" id="expiringOnly" class="form-check-input" value="1" <?= $expiring_only ? 'checked' : '' ?>>
        <label for="expiringOnly" class="form-check-label small">Expiring within 60 days</label>
      </div>
      <div class="col-md-1">
        <button class="btn btn-brand w-100"><i class="fa-solid fa-magnifying-glass"></i></button>
      </div>
    </form>
  </div>
</div>

<div class="card stat-card">
  <div class="card-header bg-white fw-semibold"><?= count($documents) ?> result<?= count($documents) === 1 ? '' : 's' ?></div>
  <div class="card-body p-0 table-responsive">
    <table class="table mb-0 align-middle">
      <thead class="table-light"><tr><th>Faculty</th><th>Type</th><th>File</th><th>Matched Name</th><th>Expiration</th><th>Filed</th><th></th></tr></thead>
      <tbody>
        <?php if (!$documents): ?>
          <tr><td colspan="7" class="text-center text-muted py-4">No documents match your search.</td></tr>
        <?php endif; ?>
        <?php foreach ($documents as $d):
          $expiring = $d['expiration_date'] && strtotime($d['expiration_date']) <= strtotime('+60 days');
        ?>
        <tr>
          <td>
            <a href="faculty_documents.php?id=<?= (int)$d['faculty_id'] ?>"><?= h($d['full_name']) ?></a>
            <div class="text-muted small text-capitalize"><?= h(str_replace('_',' ',$d['employment_type'])) ?></div>
          </td>
          <td><?= h(document_type_label($d['document_type'], $d['document_subtype'])) ?><?php if ($p = document_period_label($d)): ?><div class="small text-muted"><?= h($p) ?></div><?php endif; ?></td>
          <td><?= h(basename($d['file_path'])) ?></td>
          <td><?= $d['ocr_matched_name'] ? h($d['ocr_matched_name']) : '<span class="text-muted">—</span>' ?></td>
          <td>
            <?php if ($d['expiration_date']): ?>
              <span class="<?= $expiring ? 'text-danger fw-semibold' : '' ?>"><?= date('M j, Y', strtotime($d['expiration_date'])) ?></span>
            <?php else: ?><span class="text-muted">—</span><?php endif; ?>
          </td>
          <td><?= date('M j, Y', strtotime($d['filed_at'])) ?></td>
          <td><a href="<?= h(document_url((int)$d['document_id'])) ?>" target="_blank" class="btn btn-sm btn-outline-brand"><i class="fa-solid fa-eye"></i></a></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
