<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_role(['program_chair', 'dean']);

$page_title = 'Uploaded Document';
$categories = document_categories();
$id = (int)($_GET['id'] ?? 0);

// Read-only view of a faculty upload (opened from the Recent Uploads list
// or a notification). Uploads are filed automatically, so there is no
// confirm / reject step here.
$stmt = $pdo->prepare(
    "SELECT sr.*, u.full_name, u.email, u.employment_type, u.employment_status,
            d.document_id, d.file_path, d.expiration_date, d.ocr_matched_name, d.ocr_confidence_note, d.filed_at
     FROM submission_requests sr
     JOIN users u ON u.user_id = sr.faculty_id
     LEFT JOIN documents d ON d.request_id = sr.request_id
     WHERE sr.request_id = ?"
);
$stmt->execute([$id]);
$request = $stmt->fetch();

if (!$request) {
    $_SESSION['flash_error'] = 'That document could not be found.';
    header('Location: ' . BASE_URL . '/approval/dashboard.php');
    exit;
}

$file_path = $request['file_path'] ?: $request['temp_file_path'];
$file_exists = $file_path && is_file(ROOT_PATH . '/' . $file_path);
$ext = strtolower(pathinfo($file_path ?? '', PATHINFO_EXTENSION));
$type_label = $categories[$request['document_type_hint']]['label'] ?? $request['document_type_hint'];
[$label, $badge] = status_badge($request['status']);

include __DIR__ . '/../includes/header.php';
?>

<a href="<?= BASE_URL ?>/approval/dashboard.php" class="small text-muted d-inline-block mb-3">
  <i class="fa-solid fa-arrow-left"></i> Back to Recent Uploads
</a>

<div class="row g-4">
  <div class="col-lg-5">
    <div class="card stat-card">
      <div class="card-header bg-white fw-semibold">Uploaded Document</div>
      <div class="card-body text-center">
        <?php if (!$file_exists): ?>
          <div class="text-muted small py-4"><i class="fa-solid fa-file-circle-exclamation fa-2x mb-2 d-block"></i>The file is no longer available on the server.</div>
        <?php elseif ($ext === 'pdf'): ?>
          <i class="fa-solid fa-file-pdf fa-4x text-danger mb-3"></i><br>
          <a href="<?= BASE_URL . '/' . h($file_path) ?>" target="_blank" class="btn btn-outline-brand btn-sm">
            <i class="fa-solid fa-up-right-from-square"></i> Open PDF
          </a>
        <?php else: ?>
          <a href="<?= BASE_URL . '/' . h($file_path) ?>" target="_blank">
            <img src="<?= BASE_URL . '/' . h($file_path) ?>" class="img-fluid rounded border" alt="Uploaded document">
          </a>
          <div class="mt-2">
            <a href="<?= BASE_URL . '/' . h($file_path) ?>" target="_blank" class="btn btn-outline-brand btn-sm">
              <i class="fa-solid fa-up-right-from-square"></i> Open Full Size
            </a>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-lg-7">
    <div class="card stat-card mb-3">
      <div class="card-header bg-white fw-semibold">Document Details</div>
      <div class="card-body">
        <dl class="row mb-0 small">
          <dt class="col-sm-4">Faculty</dt><dd class="col-sm-8"><?= h($request['full_name']) ?></dd>
          <dt class="col-sm-4">Email</dt><dd class="col-sm-8"><?= h($request['email']) ?></dd>
          <dt class="col-sm-4">Employment</dt><dd class="col-sm-8 text-capitalize"><?= h(str_replace('_',' ',$request['employment_type'] ?? '—')) ?></dd>
          <dt class="col-sm-4">Document Type</dt><dd class="col-sm-8"><?= h($type_label) ?></dd>
          <dt class="col-sm-4">Date Uploaded</dt><dd class="col-sm-8"><?= date('F j, Y g:ia', strtotime($request['submitted_at'])) ?></dd>
          <dt class="col-sm-4">Expiration</dt><dd class="col-sm-8"><?= $request['expiration_date'] ? date('M j, Y', strtotime($request['expiration_date'])) : '—' ?></dd>
          <dt class="col-sm-4">Name Match (OCR)</dt>
          <dd class="col-sm-8">
            <?php if ($request['ocr_matched_name']): ?>
              <span class="badge bg-success"><i class="fa-solid fa-check"></i> <?= h($request['ocr_matched_name']) ?></span>
            <?php else: ?>
              <span class="badge bg-secondary">Not matched</span>
            <?php endif; ?>
          </dd>
          <dt class="col-sm-4">Status</dt><dd class="col-sm-8"><span class="badge <?= $badge ?>"><?= h($label) ?></span></dd>
        </dl>

        <?php if ($request['status'] === 'rejected'): ?>
          <div class="alert alert-danger small mt-3 mb-0"><strong>Rejection reason:</strong> <?= h($request['rejection_reason']) ?></div>
        <?php endif; ?>
      </div>
    </div>

    <div class="alert alert-info small">
      <i class="fa-solid fa-circle-info"></i> This document was added to the faculty member's 201 Repository automatically when it was uploaded. No action is needed.
    </div>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
