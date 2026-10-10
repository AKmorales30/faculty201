<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_role(['program_chair', 'dean']);

$page_title = 'Notifications';
$me = current_user();

$action = $_POST['action'] ?? '';
if ($action === 'mark_all_read') {
    mark_all_notifications_read($pdo, $me['user_id']);
    header('Location: ' . BASE_URL . '/approval/notifications.php');
    exit;
}
// Clicking a notification only marks it as read -- upload notifications
// never link to a faculty member's files.
if ($action === 'mark_read') {
    mark_notification_read($pdo, $me['user_id'], (int)($_POST['id'] ?? 0));
    header('Location: ' . BASE_URL . '/approval/notifications.php');
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 50");
$stmt->execute([$me['user_id']]);
$notifications = $stmt->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
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
      <form method="POST" class="m-0">
        <input type="hidden" name="action" value="mark_read">
        <input type="hidden" name="id" value="<?= (int)$n['notification_id'] ?>">
        <button type="submit" class="list-group-item list-group-item-action notif-item text-start w-100 <?= $n['is_read'] ? '' : 'notif-unread' ?>"
                <?= $n['is_read'] ? 'disabled' : 'title="Mark as read"' ?>>
          <div class="d-flex justify-content-between">
            <div class="notif-message"><?= h($n['message']) ?></div>
            <div class="text-muted small text-nowrap ms-3"><?= time_ago($n['created_at']) ?></div>
          </div>
        </button>
        <?php if ($url = notification_link_url($n['link'] ?? null)): ?>
          <a href="<?= h($url) ?>" class="small d-block px-3 pb-2 notif-item <?= $n['is_read'] ? '' : 'notif-unread' ?>">Go to page <i class="fa-solid fa-arrow-right"></i></a>
        <?php endif; ?>
      </form>
    <?php endforeach; ?>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
