<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_role('admin');

$page_title = 'Security Alerts';

if (($_POST['action'] ?? '') === 'mark_reviewed') {
    $alert_id = (int)($_POST['alert_id'] ?? 0);
    $pdo->prepare("UPDATE security_alerts SET is_reviewed = 1 WHERE alert_id = ?")->execute([$alert_id]);
    header('Location: ' . BASE_URL . '/admin/security_alerts.php');
    exit;
}

$alerts = $pdo->query(
    "SELECT sa.*, u.full_name, u.role, u.email
     FROM security_alerts sa
     JOIN users u ON u.user_id = sa.user_id
     ORDER BY sa.is_reviewed ASC, sa.created_at DESC"
)->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<h3 class="fw-bold mb-1">Security Alerts</h3>
<p class="text-muted mb-4">Logins flagged as unusual — a new IP address for the account, or several failed attempts right before a successful one.</p>

<div class="card stat-card">
  <div class="card-body p-0 table-responsive">
    <table class="table mb-0 align-middle">
      <thead class="table-light">
        <tr><th>Account</th><th>Reason</th><th>IP Address</th><th>Flagged</th><th>Status</th><th></th></tr>
      </thead>
      <tbody>
        <?php if (!$alerts): ?>
          <tr><td colspan="6" class="text-center text-muted py-4">No security alerts on record.</td></tr>
        <?php endif; ?>
        <?php foreach ($alerts as $a): ?>
        <tr class="<?= $a['is_reviewed'] ? '' : 'table-warning' ?>">
          <td>
            <?= h($a['full_name']) ?>
            <div class="text-muted small text-capitalize"><?= h(str_replace('_',' ',$a['role'])) ?> · <?= h($a['email']) ?></div>
          </td>
          <td><?= h($a['reason']) ?></td>
          <td><code><?= h($a['ip_address'] ?? '—') ?></code></td>
          <td><?= time_ago($a['created_at']) ?></td>
          <td>
            <span class="badge <?= $a['is_reviewed'] ? 'bg-secondary' : 'bg-danger' ?>">
              <?= $a['is_reviewed'] ? 'Reviewed' : 'Needs Review' ?>
            </span>
          </td>
          <td>
            <?php if (!$a['is_reviewed']): ?>
            <form method="POST" class="d-inline">
              <input type="hidden" name="action" value="mark_reviewed">
              <input type="hidden" name="alert_id" value="<?= (int)$a['alert_id'] ?>">
              <button class="btn btn-sm btn-outline-brand"><i class="fa-solid fa-check"></i> Mark Reviewed</button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
