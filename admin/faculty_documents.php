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
$link_base = '?id=' . $faculty_id . '&';

include __DIR__ . '/../includes/header.php';
?>

<a href="<?= BASE_URL ?>/admin/view_records.php" class="small text-muted d-inline-block mb-3">
  <i class="fa-solid fa-arrow-left"></i> Back to Faculty Records
</a>

<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
  <div class="d-flex align-items-center gap-3">
    <div class="portal-icon portal-icon-navy"><i class="fa-solid fa-user"></i></div>
    <div>
      <h4 class="fw-bold mb-0"><?= h($faculty['full_name']) ?></h4>
      <div class="text-muted small text-capitalize"><?= h(str_replace('_',' ',$faculty['employment_type'])) ?> Faculty · <?= h($faculty['email']) ?></div>
    </div>
  </div>
  <a href="<?= BASE_URL ?>/faculty/pds_print.php?faculty_id=<?= $faculty_id ?>" target="_blank" class="btn btn-outline-brand btn-sm">
    <i class="fa-solid fa-id-card"></i> View Digital PDS
  </a>
</div>

<?php include __DIR__ . '/../includes/document_browser.php'; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
