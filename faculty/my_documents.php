<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_role('faculty');

$page_title = 'My 201 File';
$me = current_user();
$categories = document_categories();
$counts = faculty_document_counts($pdo, $me['user_id']);

$active_type = $_GET['type'] ?? '';
if (!array_key_exists($active_type, $categories)) { $active_type = ''; }
$q = trim($_GET['q'] ?? '');

$sql = "SELECT * FROM documents WHERE faculty_id = ?";
$params = [$me['user_id']];
if ($active_type !== '') { $sql .= " AND document_type = ?"; $params[] = $active_type; }
if ($q !== '') { $sql .= " AND (ocr_extracted_text LIKE ? OR file_path LIKE ?)"; $params[] = "%$q%"; $params[] = "%$q%"; }
$sql .= " ORDER BY filed_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$documents = $stmt->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<h3 class="fw-bold mb-1">My 201 File</h3>
<p class="text-muted mb-4">Your officially filed documents, organized by category.</p>

<div class="row g-3 mb-4">
  <?php foreach ($categories as $key => $meta): ?>
  <div class="col-md-4">
    <a href="?type=<?= h($key) ?>" class="text-decoration-none">
      <div class="card folder-card h-100 <?= $active_type === $key ? 'folder-card-active' : '' ?>">
        <div class="card-body d-flex align-items-center gap-3">
          <div class="folder-icon folder-icon-<?= $meta['color'] ?>"><i class="fa-solid <?= $meta['icon'] ?>"></i></div>
          <div>
            <div class="fw-semibold text-charcoal"><?= h($meta['label']) ?></div>
            <div class="text-muted small"><?= (int)$counts[$key] ?> file<?= $counts[$key] === 1 ? '' : 's' ?></div>
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
      <?= $active_type ? h($categories[$active_type]['label']) : 'All Documents' ?>
      <?php if ($active_type): ?><a href="my_documents.php" class="small ms-2">(clear filter)</a><?php endif; ?>
    </span>
    <form class="d-flex" method="GET">
      <?php if ($active_type): ?><input type="hidden" name="type" value="<?= h($active_type) ?>"><?php endif; ?>
      <input type="search" name="q" value="<?= h($q) ?>" class="form-control form-control-sm" placeholder="Search extracted text or filename...">
      <button class="btn btn-sm btn-outline-brand ms-2"><i class="fa-solid fa-magnifying-glass"></i></button>
    </form>
  </div>
  <div class="card-body p-0">
    <table class="table mb-0 align-middle">
      <thead class="table-light">
        <tr><th>Type</th><th>File</th><th>Matched Name</th><th>Expiration</th><th>Filed</th><th></th></tr>
      </thead>
      <tbody>
        <?php if (!$documents): ?>
          <tr><td colspan="6" class="text-center text-muted py-4">No documents here yet.</td></tr>
        <?php endif; ?>
        <?php foreach ($documents as $d):
          $expiring = $d['expiration_date'] && strtotime($d['expiration_date']) <= strtotime('+60 days');
        ?>
        <tr>
          <td><?= h($d['document_type']) ?></td>
          <td><?= h(basename($d['file_path'])) ?></td>
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
          <td><?= date('M j, Y', strtotime($d['filed_at'])) ?></td>
          <td><a href="<?= BASE_URL . '/' . h($d['file_path']) ?>" target="_blank" class="btn btn-sm btn-outline-brand"><i class="fa-solid fa-eye"></i> View</a></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
