<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_role('admin');

$faculty_id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ? AND role IN ('faculty', 'program_chair', 'dean')");   // Chairs and Deans keep a 201 file too
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
    <?= user_avatar($faculty, 56) ?>
    <div>
      <h4 class="fw-bold mb-0"><?= h($faculty['full_name']) ?></h4>
      <div class="text-muted small text-capitalize"><?= h($faculty['role'] === 'faculty' ? str_replace('_', ' ', (string)$faculty['employment_type']) . ' Faculty' : str_replace('_', ' ', $faculty['role'])) ?> · <span class="text-lowercase"><?= h($faculty['email']) ?></span></div>
    </div>
  </div>
  <div class="d-flex flex-wrap gap-2">
    <a href="<?= BASE_URL ?>/profile.php?id=<?= $faculty_id ?>" class="btn btn-outline-brand btn-sm"><i class="fa-solid fa-circle-user"></i> Profile</a>
    <?php $stmt = $pdo->prepare("SELECT COUNT(*) FROM documents WHERE faculty_id = ? AND status = 'archived'"); $stmt->execute([$faculty_id]); ?>
    <a href="<?= BASE_URL ?>/archive.php?id=<?= $faculty_id ?>" class="btn btn-outline-brand btn-sm"><i class="fa-solid fa-box-archive"></i> Archive (<?= (int)$stmt->fetchColumn() ?>)</a>
    <a href="<?= BASE_URL ?>/faculty/pds_print.php?faculty_id=<?= $faculty_id ?>" target="_blank" class="btn btn-outline-brand btn-sm">
      <i class="fa-solid fa-id-card"></i> View Digital PDS
    </a>
  </div>
</div>

<?php include __DIR__ . '/../includes/document_browser.php'; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
