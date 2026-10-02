<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_role(['faculty', 'program_chair', 'dean']);   // own 201 file only

$page_title = 'My 201 File';
$me = current_user();
$stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
$stmt->execute([$me['user_id']]);
$faculty = $stmt->fetch();
$link_base = '?';

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-1">
  <h3 class="fw-bold mb-0">My 201 File</h3>
  <div class="d-flex gap-2">
    <a href="<?= BASE_URL ?>/faculty/pds.php" class="btn btn-outline-brand btn-sm"><i class="fa-solid fa-id-card"></i> My Digital PDS</a>
    <a href="<?= BASE_URL ?>/faculty/submit_document.php" class="btn btn-brand btn-sm"><i class="fa-solid fa-file-arrow-up"></i> Upload</a>
  </div>
</div>
<p class="text-muted mb-4">Your filed documents, organized by category. The latest version of each is shown first; older versions are kept as history.
  Uploaded the wrong file or a duplicate? You can delete it yourself within <?= (int)FACULTY_DELETE_WINDOW_HOURS ?> hours of uploading; after that, contact the Admin.</p>

<?php include __DIR__ . '/../includes/document_browser.php'; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
