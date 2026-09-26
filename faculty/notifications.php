<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_role('faculty');

$page_title = 'Notifications';
$me = current_user();

if (($_POST['action'] ?? '') === 'mark_all_read') {
    mark_all_notifications_read($pdo, $me['user_id']);
    header('Location: ' . BASE_URL . '/faculty/notifications.php');
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 50");
$stmt->execute([$me['user_id']]);
$notifications = $stmt->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <h3 class="fw-bold mb-0">Notifications</h3>
  <form method="POST"><input type="hidden" name="action" value="mark_all_read">
    <button class="btn btn-outline-brand btn-sm"><i class="fa-solid fa-check-double"></i> Mark all as read</button>
  </form>
</div>

<div class="card stat-card">
  <div class="list-group list-group-flush">
    <?php if (!$notifications): ?>
      <div class="text-center text-muted py-5">No notifications yet.</div>
    <?php endif; ?>
    <?php foreach ($notifications as $n): ?>
      <div class="list-group-item notif-item <?= $n['is_read'] ? '' : 'notif-unread' ?>">
        <div class="d-flex justify-content-between">
          <div><?= h($n['message']) ?></div>
          <div class="text-muted small text-nowrap ms-3"><?= time_ago($n['created_at']) ?></div>
        </div>
        <?php if ($n['request_id']): ?>
          <a href="<?= BASE_URL ?>/faculty/my_requests.php" class="small">View my requests <i class="fa-solid fa-arrow-right"></i></a>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
