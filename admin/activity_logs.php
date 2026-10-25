<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_role('admin');

// "View Activity / Login Logs" (Objective 3c, section 3.5.4, Fig. 4 / Fig. 8).
// Read-only: two tabs, Activity Logs (activity_logs, written by
// log_activity()) and Login Attempts (the existing login_attempts table).

$page_title = 'Activity Logs';
$actions = activity_actions();
$roles = ['admin' => 'Admin', 'faculty' => 'Faculty', 'program_chair' => 'Program Chair', 'dean' => 'Dean'];
$results = ['success' => 'Successful', 'failed' => 'Failed', 'blocked' => 'Blocked (locked out, deactivated or deleted)'];
const LOGS_PER_PAGE = 25;

// Filters (all optional). Anything invalid is ignored rather than erroring.
// ($role_filter, not $role: the sidebar include sets $role to the viewer's role.)
$valid_date = function (string $d): string {
    $dt = DateTime::createFromFormat('!Y-m-d', $d);
    return ($dt && $dt->format('Y-m-d') === $d) ? $d : '';
};
$tab     = ($_GET['tab'] ?? '') === 'logins' ? 'logins' : 'activity';
$from    = $valid_date(trim($_GET['from'] ?? ''));
$to      = $valid_date(trim($_GET['to'] ?? ''));
$user_id = (int)($_GET['user'] ?? 0);
$role_filter = $_GET['role'] ?? '';
$action  = $_GET['action'] ?? '';
$result  = $_GET['result'] ?? '';
$q       = trim($_GET['q'] ?? '');
$page    = max(1, (int)($_GET['page'] ?? 1));
if (!array_key_exists($role_filter, $roles)) { $role_filter = ''; }
if (!array_key_exists($action, $actions)) { $action = ''; }
if (!array_key_exists($result, $results)) { $result = ''; }
if ($from !== '' && $to !== '' && $from > $to) { [$from, $to] = [$to, $from]; }

$users = $pdo->query("SELECT user_id, full_name, role FROM users ORDER BY full_name")->fetchAll();
if (!in_array($user_id, array_map('intval', array_column($users, 'user_id')), true)) { $user_id = 0; }

// Filters shared by both tabs are kept when switching tabs
$common_query = array_filter(['from' => $from, 'to' => $to, 'user' => $user_id ?: '', 'role' => $role_filter, 'q' => $q], fn($v) => $v !== '');
$filter_query = $common_query + array_filter($tab === 'activity' ? ['action' => $action] : ['result' => $result], fn($v) => $v !== '');

// ---------------------------------------------------------------------
// Query for the active tab: WHERE clauses + params, then count + one page
// ---------------------------------------------------------------------
$where = [];
$params = [];
if ($tab === 'activity') {
    $date_col = 'a.created_at';
    if ($user_id) { $where[] = 'a.user_id = ?';   $params[] = $user_id; }
    if ($role_filter) { $where[] = 'a.user_role = ?'; $params[] = $role_filter; }
    if ($action)  { $where[] = 'a.action = ?';    $params[] = $action; }
    if ($q !== '') { $where[] = '(a.details ' . sql_like() . ' ? OR a.ip_address ' . sql_like() . ' ?)'; array_push($params, "%$q%", "%$q%"); }
    $from_sql = "FROM activity_logs a LEFT JOIN users u ON u.user_id = a.user_id";
    $select = "SELECT a.created_at, a.user_role, a.action, a.details, a.ip_address, u.full_name";
    $order = "ORDER BY a.created_at DESC, a.id DESC";
} else {
    $date_col = 'la.attempted_at';
    if ($user_id) { $where[] = 'la.user_id = ?'; $params[] = $user_id; }
    if ($role_filter) { $where[] = 'u.role = ?';     $params[] = $role_filter; }
    if ($result)  { $where[] = ['success' => 'la.was_successful = 1', 'failed' => 'la.was_successful = 0 AND la.was_blocked = 0', 'blocked' => 'la.was_blocked = 1'][$result]; }
    if ($q !== '') { $where[] = '(la.username ' . sql_like() . ' ? OR la.email ' . sql_like() . ' ? OR la.ip_address ' . sql_like() . ' ?)'; array_push($params, "%$q%", "%$q%", "%$q%"); }
    $from_sql = "FROM login_attempts la LEFT JOIN users u ON u.user_id = la.user_id";
    // username typed (email for the attempts from before usernames)
    $select = "SELECT la.attempted_at, COALESCE(la.username, la.email) AS login_name, la.was_successful, la.was_blocked, la.ip_address, u.full_name, u.role";
    $order = "ORDER BY la.attempted_at DESC, la.attempt_id DESC";
}
if ($from !== '') { $where[] = "$date_col >= ?"; $params[] = $from . ' 00:00:00'; }
if ($to !== '')   { $where[] = "$date_col < CAST(? AS DATE) + INTERVAL '1' DAY"; $params[] = $to; }
$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

/** Prepare + execute with integer params bound as integers (needed for LIMIT / OFFSET). */
$run = function (string $sql, array $params) use ($pdo): PDOStatement {
    $stmt = $pdo->prepare($sql);
    foreach (array_values($params) as $i => $v) {
        $stmt->bindValue($i + 1, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->execute();
    return $stmt;
};

$total = (int)$run("SELECT COUNT(*) $from_sql $where_sql", $params)->fetchColumn();
$pages = max(1, (int)ceil($total / LOGS_PER_PAGE));
$page  = min($page, $pages);
$rows  = $run("$select $from_sql $where_sql $order LIMIT ? OFFSET ?", array_merge($params, [LOGS_PER_PAGE, ($page - 1) * LOGS_PER_PAGE]))->fetchAll();

$role_label = fn(?string $r): string => $r ? ($roles[$r] ?? ucwords(str_replace('_', ' ', $r))) : '—';
$when = fn(string $ts): string => date('M j, Y g:i:s A', strtotime($ts));

include __DIR__ . '/../includes/header.php';
?>

<?= admin_intro('Everything users and the system did, and every login attempt — newest first. Records are read-only; filter by user, role or action to narrow the list.') ?>

<div class="nav page-tabs" role="tablist" aria-label="Logs">
  <a class="nav-link <?= $tab === 'activity' ? 'active' : '' ?>" role="tab" aria-selected="<?= $tab === 'activity' ? 'true' : 'false' ?>" href="activity_logs.php?<?= h(http_build_query($common_query)) ?>"><i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i> Activity</a>
  <a class="nav-link <?= $tab === 'logins' ? 'active' : '' ?>" role="tab" aria-selected="<?= $tab === 'logins' ? 'true' : 'false' ?>" href="activity_logs.php?<?= h(http_build_query($common_query + ['tab' => 'logins'])) ?>"><i class="fa-solid fa-right-to-bracket" aria-hidden="true"></i> Login attempts</a>
</div>

<?php $filtered = (bool)array_diff_key($filter_query, ['tab' => 1]); ?>
<section class="ui-card" aria-label="<?= $tab === 'activity' ? 'Activity' : 'Login attempts' ?>">
  <form method="GET" class="ui-toolbar" data-autosubmit data-live-search role="search">
    <?php if ($tab === 'logins'): ?><input type="hidden" name="tab" value="logins"><?php endif; ?>
    <label class="ui-search" for="fQ">
      <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
      <span class="visually-hidden"><?= $tab === 'activity' ? 'Search details or IP address' : 'Search username or IP address' ?></span>
      <input type="search" name="q" id="fQ" value="<?= h($q) ?>" class="ui-search-input" placeholder="<?= $tab === 'activity' ? 'Search details or IP address' : 'Search username or IP address' ?>">
    </label>
    <label for="fUser" class="visually-hidden">User</label>
    <select name="user" id="fUser" class="form-select">
      <option value="">All users</option>
      <?php foreach ($users as $u): ?>
        <option value="<?= (int)$u['user_id'] ?>" <?= $user_id === (int)$u['user_id'] ? 'selected' : '' ?>><?= h($u['full_name']) ?> (<?= h($role_label($u['role'])) ?>)</option>
      <?php endforeach; ?>
    </select>
    <label for="fRole" class="visually-hidden">Role</label>
    <select name="role" id="fRole" class="form-select">
      <option value="">All roles</option>
      <?php foreach ($roles as $key => $label): ?>
        <option value="<?= h($key) ?>" <?= $role_filter === $key ? 'selected' : '' ?>><?= h($label) ?></option>
      <?php endforeach; ?>
    </select>
    <?php if ($tab === 'activity'): ?>
      <label for="fAction" class="visually-hidden">Action</label>
      <select name="action" id="fAction" class="form-select">
        <option value="">All actions</option>
        <?php foreach ($actions as $key => $label): ?>
          <option value="<?= h($key) ?>" <?= $action === $key ? 'selected' : '' ?>><?= h($label) ?></option>
        <?php endforeach; ?>
      </select>
    <?php else: ?>
      <label for="fResult" class="visually-hidden">Result</label>
      <select name="result" id="fResult" class="form-select">
        <option value="">All results</option>
        <?php foreach ($results as $key => $label): ?>
          <option value="<?= h($key) ?>" <?= $result === $key ? 'selected' : '' ?>><?= h($label) ?></option>
        <?php endforeach; ?>
      </select>
    <?php endif; ?>
    <label for="fFrom" class="visually-hidden">From</label>
    <input type="date" name="from" id="fFrom" value="<?= h($from) ?>" class="form-control" title="From">
    <label for="fTo" class="visually-hidden">To</label>
    <input type="date" name="to" id="fTo" value="<?= h($to) ?>" class="form-control" title="To">
    <button class="btn btn-sm btn-brand ui-apply"><i class="fa-solid fa-filter" aria-hidden="true"></i> Apply</button>
    <?php if ($filtered): ?><a href="activity_logs.php<?= $tab === 'logins' ? '?tab=logins' : '' ?>" class="btn btn-sm btn-outline-secondary">Clear filters</a><?php endif; ?>
    <span class="ui-count"><?= $total ?> record<?= $total === 1 ? '' : 's' ?></span>
  </form>

  <div class="table-responsive">
    <table class="table align-middle">
      <?php if ($tab === 'activity'): ?>
      <thead>
        <tr><th class="text-nowrap">Date / time</th><th>User</th><th>Role</th><th>Action</th><th>Details</th><th>IP address</th></tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <?= admin_empty_row(6, $filtered ? 'No activity matches' : 'No activity yet', $filtered ? 'Try a different search, or clear the filters to see everything.' : '', $filtered ? 'activity_logs.php' : null, 'fa-clock-rotate-left') ?>
        <?php endif; ?>
        <?php foreach ($rows as $r): ?>
        <tr>
          <td class="text-nowrap"><?= h(date('M j, Y', strtotime($r['created_at']))) ?><div class="ui-cell-sub"><?= h(date('g:i:s A', strtotime($r['created_at']))) ?></div></td>
          <td class="fw-semibold"><?= h($r['full_name'] ?? ($r['user_role'] === 'system' ? 'System (scheduled)' : '—')) ?></td>
          <td class="text-nowrap"><?= h($role_label($r['user_role'])) ?></td>
          <td><?= h($actions[$r['action']] ?? $r['action']) ?></td>
          <td class="text-break"><?= h($r['details'] ?? '') ?></td>
          <td class="text-nowrap"><?= h($r['ip_address'] ?? '—') ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <?php else: ?>
      <thead>
        <tr><th class="text-nowrap">Date / time</th><th>Username entered</th><th>Account</th><th>Result</th><th>IP address</th></tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <?= admin_empty_row(5, $filtered ? 'No login attempts match' : 'No login attempts yet', $filtered ? 'Try a different search, or clear the filters to see everything.' : '', $filtered ? 'activity_logs.php?tab=logins' : null, 'fa-right-to-bracket') ?>
        <?php endif; ?>
        <?php foreach ($rows as $r): ?>
        <tr>
          <td class="text-nowrap"><?= h(date('M j, Y', strtotime($r['attempted_at']))) ?><div class="ui-cell-sub"><?= h(date('g:i:s A', strtotime($r['attempted_at']))) ?></div></td>
          <td class="text-break"><?= h((string)$r['login_name']) ?></td>
          <td>
            <?php if ($r['full_name']): ?>
              <span class="fw-semibold"><?= h($r['full_name']) ?></span><div class="ui-cell-sub"><?= h($role_label($r['role'])) ?></div>
            <?php else: ?>
              <span class="text-muted">No matching account</span>
            <?php endif; ?>
          </td>
          <td>
            <?php [$res_label, $res_class] = $r['was_successful'] ? ['Success', 'bg-success'] : ($r['was_blocked'] ? ['Blocked', 'bg-secondary'] : ['Failed', 'bg-danger']); ?>
            <span class="badge <?= $res_class ?>"><?= h($res_label) ?></span>
          </td>
          <td class="text-nowrap"><?= h($r['ip_address'] ?? '—') ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <?php endif; ?>
    </table>
  </div>
  <div class="ui-card-foot">
    <span><?= h(admin_range_label($page, LOGS_PER_PAGE, count($rows), $total)) ?></span>
    <?php
    $page_url = fn(int $p): string => 'activity_logs.php?' . http_build_query($filter_query + ($tab === 'logins' ? ['tab' => 'logins'] : []) + ['page' => $p]);
    $pagination_labels = ['<i class="fa-solid fa-chevron-left" aria-hidden="true"></i><span class="visually-hidden">Newer</span>', '<i class="fa-solid fa-chevron-right" aria-hidden="true"></i><span class="visually-hidden">Older</span>'];
    include __DIR__ . '/../includes/pagination.php';
    ?>
  </div>
</section>

<?php include __DIR__ . '/../includes/footer.php'; ?>
