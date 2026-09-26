<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_role('admin');

$faculty_id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ? AND role = 'faculty'");
$stmt->execute([$faculty_id]);
$faculty = $stmt->fetch();

if (!$faculty) {
    $_SESSION['flash_error'] = 'That faculty record could not be found.';
    header('Location: ' . BASE_URL . '/admin/view_records.php');
    exit;
}

$page_title = $faculty['full_name'] . ' — 201 File';
$categories = document_categories();
$counts = faculty_document_counts($pdo, $faculty_id);

$active_type = $_GET['type'] ?? '';
if (!array_key_exists($active_type, $categories)) { $active_type = ''; }

$sql = "SELECT * FROM documents WHERE faculty_id = ?";
$params = [$faculty_id];
if ($active_type !== '') { $sql .= " AND document_type = ?"; $params[] = $active_type; }
$sql .= " ORDER BY filed_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$documents = $stmt->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<a href="<?= BASE_URL ?>/admin/view_records.php" class="small text-muted d-inline-block mb-3">
  <i class="fa-solid fa-arrow-left"></i> Back to Faculty Records
</a>

<div class="d-flex align-items-center gap-3 mb-4">
  <div class="portal-icon portal-icon-navy"><i class="fa-solid fa-user"></i></div>
  <div>
    <h4 class="fw-bold mb-0"><?= h($faculty['full_name']) ?></h4>
    <div class="text-muted small text-capitalize"><?= h(str_replace('_',' ',$faculty['employment_type'])) ?> Faculty · <?= h($faculty['email']) ?></div>
  </div>
</div>

<div class="row g-3 mb-4">
  <?php foreach ($categories as $key => $meta): ?>
  <div class="col-md-4">
    <a href="?id=<?= $faculty_id ?>&type=<?= h($key) ?>" class="text-decoration-none">
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
  <div class="card-header bg-white fw-semibold">
    <?= $active_type ? h($categories[$active_type]['label']) : 'All Documents' ?>
    <?php if ($active_type): ?><a href="?id=<?= $faculty_id ?>" class="small ms-2 fw-normal">(clear filter)</a><?php endif; ?>
  </div>
  <div class="card-body p-0">
    <table class="table mb-0 align-middle">
      <thead class="table-light"><tr><th>Type</th><th>File</th><th>OCR Matched Name</th><th>Expiration</th><th>Filed</th><th></th></tr></thead>
      <tbody>
        <?php if (!$documents): ?>
          <tr><td colspan="6" class="text-center text-muted py-4">No documents in this category yet.</td></tr>
        <?php endif; ?>
        <?php foreach ($documents as $d):
          $expiring = $d['expiration_date'] && strtotime($d['expiration_date']) <= strtotime('+60 days');
        ?>
        <tr>
          <td><?= h($d['document_type']) ?></td>
          <td><?= h(basename($d['file_path'])) ?></td>
          <td><?= $d['ocr_matched_name'] ? h($d['ocr_matched_name']) : '<span class="text-muted">—</span>' ?></td>
          <td>
            <?php if ($d['expiration_date']): ?>
              <span class="<?= $expiring ? 'text-danger fw-semibold' : '' ?>"><?= date('M j, Y', strtotime($d['expiration_date'])) ?></span>
            <?php else: ?><span class="text-muted">—</span><?php endif; ?>
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
