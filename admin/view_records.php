<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_role('admin');

$page_title = 'Faculty Records';
$categories = document_categories();
$q = trim($_GET['q'] ?? '');

$sql = "SELECT * FROM users WHERE role = 'faculty'";
$params = [];
if ($q !== '') { $sql .= " AND full_name LIKE ?"; $params[] = "%$q%"; }
$sql .= " ORDER BY full_name";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$faculty = $stmt->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
  <h3 class="fw-bold mb-0">Faculty Records</h3>
  <form method="GET" class="d-flex">
    <input type="search" name="q" value="<?= h($q) ?>" class="form-control form-control-sm" placeholder="Search faculty name...">
    <button class="btn btn-sm btn-outline-brand ms-2"><i class="fa-solid fa-magnifying-glass"></i></button>
  </form>
</div>

<div class="row g-3">
  <?php if (!$faculty): ?>
    <div class="col-12 text-center text-muted py-5">No faculty found.</div>
  <?php endif; ?>
  <?php foreach ($faculty as $f):
    $counts = faculty_document_counts($pdo, (int)$f['user_id']);
    $total = array_sum($counts);
  ?>
  <div class="col-md-6 col-lg-4">
    <a href="faculty_documents.php?id=<?= (int)$f['user_id'] ?>" class="text-decoration-none">
      <div class="card portal-card h-100 p-3">
        <div class="d-flex align-items-center gap-3 mb-2">
          <div class="portal-icon portal-icon-navy" style="width:48px;height:48px;font-size:1.1rem;"><i class="fa-solid fa-user"></i></div>
          <div>
            <div class="fw-bold text-charcoal"><?= h($f['full_name']) ?></div>
            <div class="text-muted small text-capitalize">
              <?= h(str_replace('_',' ',$f['employment_type'])) ?> ·
              <span class="<?= $f['employment_status']==='active' ? 'text-success' : 'text-warning' ?>"><?= h($f['employment_status']) ?></span>
            </div>
          </div>
        </div>
        <div class="d-flex gap-2 flex-wrap">
          <?php foreach ($categories as $key => $meta): ?>
            <span class="badge bg-light text-dark border"><?= h($key) ?>: <?= (int)$counts[$key] ?></span>
          <?php endforeach; ?>
        </div>
        <div class="text-muted small mt-2"><?= $total ?> document<?= $total === 1 ? '' : 's' ?> filed</div>
      </div>
    </a>
  </div>
  <?php endforeach; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
