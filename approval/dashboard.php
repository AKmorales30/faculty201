<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_role(['program_chair', 'dean']);

$page_title = 'Dashboard';
$me = current_user();

// Anything left waiting under the old approval workflow gets filed now.
file_outstanding_requests($pdo);
// Expiration alerts, at most once a day system-wide (before the header, so the bell counts them)
run_daily_expiration_check($pdo);
$expiring = expiring_documents($pdo, (int)$me['user_id']);
$card_admin = false;

// Program Chairs and Deans keep their own 201 file like faculty do. They are
// notified when faculty in their program / college upload, but can't open
// faculty files -- so this dashboard shows only their own documents.
$stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ?");
$stmt->execute([$me['user_id']]);
$self = $stmt->fetch();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM documents WHERE faculty_id = ?");
$stmt->execute([$me['user_id']]);
$my_docs = (int)$stmt->fetchColumn();
$unread = unread_notification_count($pdo, $me['user_id']);

$checklist = faculty_201_checklist($pdo, $me['user_id'], $self['employment_type'] ?? null);
$missing = count(array_filter($checklist, fn($i) => !$i['done']));

$scope = $me['role'] === 'dean'
    ? (COLLEGES[$self['college'] ?? ''] ?? null)
    : (isset(PROGRAMS[$self['program'] ?? '']) ? PROGRAMS[$self['program']]['label'] : null);

include __DIR__ . '/../includes/header.php';
?>
<h3 class="fw-bold mb-1">Welcome, <?= h($me['full_name']) ?></h3>
<p class="text-muted mb-4">
  <?php if ($scope): ?>
    You're notified whenever faculty in <strong><?= h($scope) ?></strong> upload to their 201 file.
  <?php else: ?>
    Your <?= $me['role'] === 'dean' ? 'college' : 'program' ?> hasn't been set yet, so you won't receive upload notifications -- please ask the Admin to set it.
  <?php endif; ?>
</p>

<div class="row g-3 mb-4">
  <div class="col-6 col-md-4">
    <a href="<?= BASE_URL ?>/approval/notifications.php" class="text-decoration-none">
      <div class="card stat-card p-3 h-100">
        <div class="text-muted small">Unread Notifications</div>
        <div class="stat-number text-accent-gold"><?= $unread ?></div>
      </div>
    </a>
  </div>
  <div class="col-6 col-md-4">
    <div class="card stat-card p-3 h-100">
      <div class="text-muted small">Documents in My 201 File</div>
      <div class="stat-number text-accent-teal"><?= $my_docs ?></div>
    </div>
  </div>
  <div class="col-12 col-md-4">
    <div class="card stat-card p-3 h-100 d-flex flex-column justify-content-center gap-2">
      <a href="<?= BASE_URL ?>/faculty/submit_document.php" class="btn btn-brand">
        <i class="fa-solid fa-file-arrow-up"></i> Upload a New Document
      </a>
      <a href="<?= BASE_URL ?>/faculty/pds.php" class="btn btn-outline-brand">
        <i class="fa-solid fa-id-card"></i> Edit My PDS
      </a>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../includes/expiring_documents_card.php'; ?>

<div class="card stat-card">
  <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
    My 201 File Checklist
    <?php if ($missing): ?>
      <span class="badge bg-warning"><?= $missing ?> to do</span>
    <?php else: ?>
      <span class="badge bg-success">Complete</span>
    <?php endif; ?>
  </div>
  <ul class="list-group list-group-flush">
    <?php foreach ($checklist as $item): ?>
      <li class="list-group-item d-flex justify-content-between align-items-center gap-2 small">
        <span>
          <i class="fa-solid <?= $item['done'] ? 'fa-circle-check text-success' : 'fa-circle-exclamation text-warning' ?> me-1"></i>
          <?= h($item['label']) ?>
        </span>
        <?php if (!$item['done']): ?>
          <a href="<?= BASE_URL ?>/faculty/<?= $item['type'] === 'PDS' ? 'pds.php' : 'submit_document.php' ?>" class="text-nowrap"><?= $item['type'] === 'PDS' ? 'Update' : 'Upload' ?></a>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
