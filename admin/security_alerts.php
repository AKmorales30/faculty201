<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_role('admin');

$page_title = 'Security Alerts';

if (($_POST['action'] ?? '') === 'mark_reviewed') {
    if (!csrf_valid()) {
        $_SESSION['flash_error'] = 'Your session expired before the form was sent. Please try again.';
    } else {
        $alert_id = (int)($_POST['alert_id'] ?? 0);
        $pdo->prepare("UPDATE security_alerts SET is_reviewed = 1 WHERE alert_id = ?")->execute([$alert_id]);
        $_SESSION['flash_success'] = 'Alert marked as reviewed.';
    }
    header('Location: ' . BASE_URL . '/admin/security_alerts.php' . (in_array($_GET['state'] ?? '', ['open', 'reviewed'], true) ? '?state=' . $_GET['state'] : ''));
    exit;
}

// End a brute-force login lock (an email or an IP address) before it expires
if (($_POST['action'] ?? '') === 'unlock_login') {
    $type = ($_POST['lock_type'] ?? '') === 'ip' ? 'ip' : 'account';
    if (!csrf_valid()) {
        $_SESSION['flash_error'] = 'Your session expired before the form was sent. Please try again.';
    } elseif (clear_login_lock($pdo, $type, (string)($_POST['lock_key'] ?? ''), (int)current_user()['user_id'])) {
        $_SESSION['flash_success'] = 'Unlocked ' . ($type === 'ip' ? 'IP address ' : '') . ($_POST['lock_key'] ?? '') . '.';
    } else {
        $_SESSION['flash_error'] = 'That lock has already ended.';
    }
    header('Location: ' . BASE_URL . '/admin/security_alerts.php');
    exit;
}

$locks = active_login_locks($pdo);
$lock_accounts = [];   // email => full name, for locks on existing accounts
if ($emails = array_column(array_filter($locks, fn($l) => $l['lock_type'] === 'account'), 'lock_key')) {
    $stmt = $pdo->prepare("SELECT LOWER(email), full_name FROM users WHERE email IN (" . implode(',', array_fill(0, count($emails), '?')) . ")");
    $stmt->execute($emails);
    $lock_accounts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
}

$alerts = $pdo->query(
    "SELECT sa.*, u.full_name, u.role, u.email
     FROM security_alerts sa
     JOIN users u ON u.user_id = sa.user_id
     ORDER BY sa.is_reviewed ASC, sa.created_at DESC"
)->fetchAll();
// Status filter (Unreviewed / Reviewed), applied to the list above
$state = in_array($_GET['state'] ?? '', ['open', 'reviewed'], true) ? $_GET['state'] : '';
$alert_total = count($alerts);
if ($state !== '') {
    $alerts = array_values(array_filter($alerts, fn($a) => (bool)$a['is_reviewed'] === ($state === 'reviewed')));
}

include __DIR__ . '/../includes/header.php';
?>

<?= admin_intro('Failed logins, lockouts and unusual sign-ins — a new IP address for an account, or several failed attempts right before a successful one. Mark an alert as reviewed once you\'ve checked it.') ?>

<section class="ui-card" aria-labelledby="locksTitle">
  <div class="ui-card-head">
    <span class="ui-head-icon tone-red" aria-hidden="true"><i class="fa-solid fa-lock"></i></span>
    <h2 class="ui-card-title" id="locksTitle">Locked logins</h2>
    <span class="ui-card-meta"><?= (int)MAX_FAILED_ATTEMPTS ?> failed attempts for one email, or <?= (int)MAX_IP_FAILED_ATTEMPTS ?> from one IP, within <?= (int)FAILED_ATTEMPT_WINDOW_MINUTES ?> minutes lock logins for <?= (int)LOCKOUT_MINUTES ?> minutes</span>
  </div>
  <?php if (!$locks): ?>
    <div class="px-4 py-3 text-muted">No logins are locked right now.</div>
  <?php endif; ?>
  <?php foreach ($locks as $l): ?>
    <div class="ui-lock-row">
      <div class="ui-lock-who">
        <?php if ($l['lock_type'] === 'ip'): ?>
          <div class="fw-semibold">IP address <?= h($l['lock_key']) ?></div>
          <div class="ui-cell-sub">IP address lock</div>
        <?php else: ?>
          <div class="fw-semibold"><?= h($lock_accounts[$l['lock_key']] ?? '') ?: 'No matching account' ?> · <?= h($l['lock_key']) ?></div>
          <div class="ui-cell-sub">Account lock</div>
        <?php endif; ?>
      </div>
      <div class="ui-lock-n"><strong><?= (int)$l['failed_count'] ?></strong> failed attempts</div>
      <div class="ui-lock-when">Since <?= h(date('g:i A', strtotime($l['locked_at']))) ?> · until <?= h(date('g:i A', time() + (int)$l['seconds_left'])) ?> (<?= h(login_lock_minutes_label((int)$l['seconds_left'])) ?>)</div>
      <form method="POST" data-confirm="<?= h(($l['lock_type'] === 'ip' ? 'IP address ' : '') . $l['lock_key']) ?> can try logging in again right away." data-confirm-title="Unlock login?" data-confirm-ok="Unlock">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="unlock_login">
        <input type="hidden" name="lock_type" value="<?= h($l['lock_type']) ?>">
        <input type="hidden" name="lock_key" value="<?= h($l['lock_key']) ?>">
        <button class="btn btn-sm btn-outline-brand"><i class="fa-solid fa-lock-open" aria-hidden="true"></i> Unlock</button>
      </form>
    </div>
  <?php endforeach; ?>
</section>

<section class="ui-card" aria-label="Security alerts">
  <form method="GET" class="ui-toolbar" data-autosubmit>
    <label for="fState" class="visually-hidden">Status</label>
    <select name="state" id="fState" class="form-select">
      <option value="">All alerts</option>
      <option value="open" <?= $state === 'open' ? 'selected' : '' ?>>Unreviewed</option>
      <option value="reviewed" <?= $state === 'reviewed' ? 'selected' : '' ?>>Reviewed</option>
    </select>
    <button class="btn btn-sm btn-brand ui-apply" data-auto><i class="fa-solid fa-filter" aria-hidden="true"></i> Filter</button>
    <span class="ui-count"><?= count($alerts) ?> alert<?= count($alerts) === 1 ? '' : 's' ?></span>
  </form>
  <div class="table-responsive">
    <table class="table align-middle">
      <thead>
        <tr><th>Account</th><th>Reason</th><th>IP address</th><th>Flagged</th><th>Status</th><th class="text-end"><span class="visually-hidden">Actions</span></th></tr>
      </thead>
      <tbody>
        <?php if (!$alerts): ?>
          <?= admin_empty_row(6, $alert_total ? 'No alerts match' : 'No security alerts', $alert_total ? 'Try a different filter.' : 'Unusual sign-ins will appear here.', $alert_total ? 'security_alerts.php' : null, 'fa-shield-halved') ?>
        <?php endif; ?>
        <?php foreach ($alerts as $a): ?>
        <tr>
          <td>
            <div class="fw-semibold"><?= h($a['full_name']) ?></div>
            <div class="ui-cell-sub"><span class="text-capitalize"><?= h(str_replace('_', ' ', $a['role'])) ?></span> · <?= h($a['email']) ?></div>
          </td>
          <td><?= h($a['reason']) ?></td>
          <td class="text-nowrap"><?= h($a['ip_address'] ?? '—') ?></td>
          <td class="text-nowrap" title="<?= h(date('M j, Y g:i A', strtotime($a['created_at']))) ?>"><?= h(date('M j, Y', strtotime($a['created_at']))) ?><div class="ui-cell-sub"><?= h(time_ago($a['created_at'])) ?></div></td>
          <td><span class="badge <?= $a['is_reviewed'] ? 'bg-secondary' : 'bg-warning' ?>"><?= $a['is_reviewed'] ? 'Reviewed' : 'Unreviewed' ?></span></td>
          <td>
            <div class="ui-actions">
            <?php if (!$a['is_reviewed']): ?>
            <form method="POST" action="security_alerts.php<?= $state !== '' ? '?state=' . h($state) : '' ?>">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="mark_reviewed">
              <input type="hidden" name="alert_id" value="<?= (int)$a['alert_id'] ?>">
              <button class="btn btn-sm btn-outline-brand"><i class="fa-solid fa-check" aria-hidden="true"></i> Mark reviewed</button>
            </form>
            <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<?php include __DIR__ . '/../includes/footer.php'; ?>
