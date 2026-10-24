<?php
/**
 * Login History (Admin, a tab of Security Alerts): every login attempt in
 * login_history (includes/login_activity.php) -- who, when (Asia/Manila),
 * device, IP, approximate location and result -- filtered by user, result
 * and date. Attempts with a username that doesn't exist have no account
 * (and nothing typed is kept). Kept for LOGIN_HISTORY_DAYS days. Read-only.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_role('admin');

$page_title = 'Security Alerts';
const LOGIN_HISTORY_PER_PAGE = 50;

$valid_date = function (string $d): string {
    $dt = DateTime::createFromFormat('!Y-m-d', $d);
    return ($dt && $dt->format('Y-m-d') === $d) ? $d : '';
};
$users = $pdo->query("SELECT user_id, full_name, role FROM users ORDER BY full_name")->fetchAll();
$user_raw = (string)($_GET['user'] ?? '');
$user_id = $user_raw === 'unknown' ? 0 : ((int)$user_raw ?: null);   // 0: unknown usernames
$status = isset(login_status_labels()[$_GET['status'] ?? '']) ? $_GET['status'] : '';
$from = $valid_date(trim((string)($_GET['from'] ?? '')));
$to = $valid_date(trim((string)($_GET['to'] ?? '')));
$page = max(1, (int)($_GET['page'] ?? 1));
$filters = ['user_id' => $user_id, 'status' => $status, 'from' => $from, 'to' => $to];
$filtered = $user_id !== null || $status !== '' || $from !== '' || $to !== '';

$rows = [];
$total = 0;
$error = null;
try {
    $total = login_history_count($pdo, $filters);
    $pages = max(1, (int)ceil($total / LOGIN_HISTORY_PER_PAGE));
    $page = min($page, $pages);
    $rows = login_history_rows($pdo, $filters, LOGIN_HISTORY_PER_PAGE, ($page - 1) * LOGIN_HISTORY_PER_PAGE);
} catch (Throwable $e) {
    error_log('Login History: ' . $e->getMessage());
    $error = 'Login history isn\'t available yet: run database/supabase/08_login_history.sql in Supabase.';
    $pages = 1;
}
$status_labels = login_status_labels();
$filter_query = array_filter(['user' => $user_raw !== '' ? $user_raw : '', 'status' => $status, 'from' => $from, 'to' => $to], 'strlen');

include __DIR__ . '/../includes/header.php';
?>

<?= admin_intro('Every login attempt: when (Philippine time), from which device and IP address, the approximate location, and the result. Kept for ' . (int)LOGIN_HISTORY_DAYS . ' days. Attempts with a username that doesn\'t exist show no account, and what was typed isn\'t kept.') ?>
<?= security_tabs('logins') ?>

<?php if ($error): ?><div class="alert alert-warning"><?= h($error) ?></div><?php endif; ?>

<section class="ui-card" aria-labelledby="loginHistoryTitle">
  <div class="ui-card-head">
    <span class="ui-head-icon tone-navy" aria-hidden="true"><i class="fa-solid fa-clock-rotate-left"></i></span>
    <h2 class="ui-card-title" id="loginHistoryTitle">Login history</h2>
  </div>
  <form method="GET" class="ui-toolbar" data-autosubmit role="search">
    <label for="fUser" class="visually-hidden">User</label>
    <select name="user" id="fUser" class="form-select">
      <option value="">All users</option>
      <option value="unknown" <?= $user_id === 0 ? 'selected' : '' ?>>Unknown username</option>
      <?php foreach ($users as $u): ?>
        <option value="<?= (int)$u['user_id'] ?>" <?= $user_id === (int)$u['user_id'] ? 'selected' : '' ?>><?= h($u['full_name']) ?> (<?= h(str_replace('_', ' ', $u['role'])) ?>)</option>
      <?php endforeach; ?>
    </select>
    <label for="fStatus" class="visually-hidden">Result</label>
    <select name="status" id="fStatus" class="form-select">
      <option value="">All results</option>
      <?php foreach ($status_labels as $key => [$label]): ?>
        <option value="<?= h($key) ?>" <?= $status === $key ? 'selected' : '' ?>><?= h($label) ?></option>
      <?php endforeach; ?>
    </select>
    <label for="fFrom" class="visually-hidden">From</label>
    <input type="date" name="from" id="fFrom" value="<?= h($from) ?>" class="form-control" title="From">
    <label for="fTo" class="visually-hidden">To</label>
    <input type="date" name="to" id="fTo" value="<?= h($to) ?>" class="form-control" title="To">
    <button class="btn btn-sm btn-brand ui-apply"><i class="fa-solid fa-filter" aria-hidden="true"></i> Apply</button>
    <?php if ($filtered): ?><a href="login_history.php" class="btn btn-sm btn-outline-secondary">Clear filters</a><?php endif; ?>
    <span class="ui-count"><?= $total ?> attempt<?= $total === 1 ? '' : 's' ?></span>
  </form>

  <div class="table-responsive">
    <table class="table align-middle">
      <thead><tr><th>Date and time</th><th>Account</th><th>Device</th><th>IP address</th><th>Location <span class="fw-normal text-muted">(approximate)</span></th><th>Result</th></tr></thead>
      <tbody>
        <?php if (!$rows): ?><?= admin_empty_row(6, 'No login attempts', $filtered ? 'Try a different filter.' : 'Attempts appear here from the next login.', $filtered ? 'login_history.php' : null, 'fa-clock-rotate-left') ?><?php endif; ?>
        <?php foreach ($rows as $r): [$st_label, $st_class] = $status_labels[$r['status']] ?? [$r['status'], 'bg-light text-muted']; ?>
          <tr>
            <td class="text-nowrap"><?= h(login_time_label($r['created_at'])) ?></td>
            <td>
              <?php if ($r['user_id']): ?>
                <a href="<?= BASE_URL ?>/profile.php?id=<?= (int)$r['user_id'] ?>" class="text-body fw-semibold"><?= h($r['full_name']) ?></a>
                <div class="ui-cell-sub"><?= h((string)$r['username']) ?></div>
              <?php else: ?>
                <span class="text-muted">Unknown username</span>
              <?php endif; ?>
            </td>
            <td><?= h($r['device_label'] ?: 'Unknown device') ?><?php if (!empty($r['new_device']) && $r['new_device'] !== 'f'): ?> <span class="badge bg-warning-subtle text-warning-emphasis">New device</span><?php endif; ?></td>
            <td class="text-nowrap"><?= h($r['ip_address'] ?: 'unknown') ?></td>
            <td><?= $r['location'] !== null ? h($r['location']) : '<span class="text-muted">unknown</span>' ?></td>
            <td><span class="badge <?= h($st_class) ?>"><?= h($st_label) ?></span></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="ui-card-foot">
    <span><?= h(admin_range_label($page, LOGIN_HISTORY_PER_PAGE, count($rows), $total)) ?></span>
    <?php
    $page_url = fn(int $p): string => 'login_history.php?' . http_build_query($filter_query + ['page' => $p]);
    $pagination_labels = ['<i class="fa-solid fa-chevron-left" aria-hidden="true"></i><span class="visually-hidden">Newer</span>', '<i class="fa-solid fa-chevron-right" aria-hidden="true"></i><span class="visually-hidden">Older</span>'];
    include __DIR__ . '/../includes/pagination.php';
    ?>
  </div>
</section>

<?php include __DIR__ . '/../includes/footer.php'; ?>
