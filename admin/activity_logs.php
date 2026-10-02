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
$results = ['success' => 'Successful', 'failed' => 'Failed', 'blocked' => 'Blocked (locked out)'];
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
    if ($q !== '') { $where[] = '(a.details LIKE ? OR a.ip_address LIKE ?)'; array_push($params, "%$q%", "%$q%"); }
    $from_sql = "FROM activity_logs a LEFT JOIN users u ON u.user_id = a.user_id";
    $select = "SELECT a.created_at, a.user_role, a.action, a.details, a.ip_address, u.full_name";
    $order = "ORDER BY a.created_at DESC, a.id DESC";
} else {
    $date_col = 'la.attempted_at';
    if ($user_id) { $where[] = 'la.user_id = ?'; $params[] = $user_id; }
    if ($role_filter) { $where[] = 'u.role = ?';     $params[] = $role_filter; }
    if ($result)  { $where[] = ['success' => 'la.was_successful = 1', 'failed' => 'la.was_successful = 0 AND la.was_blocked = 0', 'blocked' => 'la.was_blocked = 1'][$result]; }
    if ($q !== '') { $where[] = '(la.email LIKE ? OR la.ip_address LIKE ?)'; array_push($params, "%$q%", "%$q%"); }
    $from_sql = "FROM login_attempts la LEFT JOIN users u ON u.user_id = la.user_id";
    $select = "SELECT la.attempted_at, la.email, la.was_successful, la.was_blocked, la.ip_address, u.full_name, u.role";
    $order = "ORDER BY la.attempted_at DESC, la.attempt_id DESC";
}
if ($from !== '') { $where[] = "$date_col >= ?"; $params[] = $from . ' 00:00:00'; }
if ($to !== '')   { $where[] = "$date_col < DATE_ADD(?, INTERVAL 1 DAY)"; $params[] = $to; }
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

<h3 class="fw-bold mb-1">Activity Logs</h3>
<p class="text-muted mb-4">Who did what in the system, and every login attempt. Newest first; records are read-only.</p>

<ul class="nav nav-tabs mb-3">
  <li class="nav-item">
    <a class="nav-link <?= $tab === 'activity' ? 'active' : '' ?>" href="activity_logs.php?<?= h(http_build_query($common_query)) ?>"><i class="fa-solid fa-clock-rotate-left"></i> Activity Logs</a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $tab === 'logins' ? 'active' : '' ?>" href="activity_logs.php?<?= h(http_build_query($common_query + ['tab' => 'logins'])) ?>"><i class="fa-solid fa-right-to-bracket"></i> Login Attempts</a>
  </li>
</ul>

<div class="card stat-card mb-4">
  <div class="card-body">
    <form method="GET" class="row g-2 align-items-end">
      <?php if ($tab === 'logins'): ?><input type="hidden" name="tab" value="logins"><?php endif; ?>
      <div class="col-6 col-md-3 col-xl-2">
        <label for="fFrom" class="form-label small fw-semibold">From</label>
        <input type="date" name="from" id="fFrom" value="<?= h($from) ?>" class="form-control">
      </div>
      <div class="col-6 col-md-3 col-xl-2">
        <label for="fTo" class="form-label small fw-semibold">To</label>
        <input type="date" name="to" id="fTo" value="<?= h($to) ?>" class="form-control">
      </div>
      <div class="col-md-6 col-xl-2">
        <label for="fUser" class="form-label small fw-semibold">User</label>
        <select name="user" id="fUser" class="form-select">
          <option value="">All Users</option>
          <?php foreach ($users as $u): ?>
            <option value="<?= (int)$u['user_id'] ?>" <?= $user_id === (int)$u['user_id'] ? 'selected' : '' ?>><?= h($u['full_name']) ?> (<?= h($role_label($u['role'])) ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-3 col-xl-2">
        <label for="fRole" class="form-label small fw-semibold">Role</label>
        <select name="role" id="fRole" class="form-select">
          <option value="">All Roles</option>
          <?php foreach ($roles as $key => $label): ?>
            <option value="<?= h($key) ?>" <?= $role_filter === $key ? 'selected' : '' ?>><?= h($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php if ($tab === 'activity'): ?>
      <div class="col-6 col-md-3 col-xl-2">
        <label for="fAction" class="form-label small fw-semibold">Action</label>
        <select name="action" id="fAction" class="form-select">
          <option value="">All Actions</option>
          <?php foreach ($actions as $key => $label): ?>
            <option value="<?= h($key) ?>" <?= $action === $key ? 'selected' : '' ?>><?= h($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php else: ?>
      <div class="col-6 col-md-3 col-xl-2">
        <label for="fResult" class="form-label small fw-semibold">Result</label>
        <select name="result" id="fResult" class="form-select">
          <option value="">All</option>
          <?php foreach ($results as $key => $label): ?>
            <option value="<?= h($key) ?>" <?= $result === $key ? 'selected' : '' ?>><?= h($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <div class="col-md-6 col-xl-2">
        <label for="fQ" class="form-label small fw-semibold">Keyword</label>
        <input type="search" name="q" id="fQ" value="<?= h($q) ?>" class="form-control"
               placeholder="<?= $tab === 'activity' ? 'Details or IP address' : 'Email or IP address' ?>">
      </div>
      <div class="col-md-6 col-xl-3 d-flex gap-2">
        <button class="btn btn-brand flex-fill"><i class="fa-solid fa-filter"></i> Filter</button>
        <a href="activity_logs.php<?= $tab === 'logins' ? '?tab=logins' : '' ?>" class="btn btn-outline-secondary flex-fill">Reset</a>
      </div>
    </form>
  </div>
</div>

<div class="small text-muted mb-2">
  <?= $total ?> record<?= $total === 1 ? '' : 's' ?><?= $total ? ' -- showing ' . (($page - 1) * LOGS_PER_PAGE + 1) . '-' . (($page - 1) * LOGS_PER_PAGE + count($rows)) : '' ?>
</div>

<div class="card stat-card">
  <div class="card-body p-0 table-responsive">
    <table class="table mb-0 align-middle">
      <?php if ($tab === 'activity'): ?>
      <thead class="table-light">
        <tr><th class="text-nowrap">Date / Time</th><th>User</th><th>Role</th><th>Action</th><th>Details</th><th>IP Address</th></tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="6" class="text-center text-muted py-4">No records found.</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $r): ?>
        <tr>
          <td class="text-nowrap small"><?= h($when($r['created_at'])) ?></td>
          <td><?= h($r['full_name'] ?? '—') ?></td>
          <td class="text-nowrap"><?= h($role_label($r['user_role'])) ?></td>
          <td class="text-nowrap"><span class="badge bg-light text-dark border"><?= h($actions[$r['action']] ?? $r['action']) ?></span></td>
          <td class="small text-break"><?= h($r['details'] ?? '') ?></td>
          <td><code><?= h($r['ip_address'] ?? '—') ?></code></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <?php else: ?>
      <thead class="table-light">
        <tr><th class="text-nowrap">Date / Time</th><th>Email Entered</th><th>Account</th><th>Result</th><th>IP Address</th></tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="5" class="text-center text-muted py-4">No records found.</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $r): ?>
        <tr>
          <td class="text-nowrap small"><?= h($when($r['attempted_at'])) ?></td>
          <td class="text-break"><?= h($r['email']) ?></td>
          <td>
            <?php if ($r['full_name']): ?>
              <?= h($r['full_name']) ?> <span class="text-muted small">(<?= h($role_label($r['role'])) ?>)</span>
            <?php else: ?>
              <span class="text-muted small">No matching account</span>
            <?php endif; ?>
          </td>
          <td>
            <?php [$res_label, $res_class] = $r['was_successful'] ? ['Success', 'bg-success'] : ($r['was_blocked'] ? ['Blocked (locked)', 'bg-secondary'] : ['Failed', 'bg-danger']); ?>
            <span class="badge <?= $res_class ?>"><?= h($res_label) ?></span>
          </td>
          <td><code><?= h($r['ip_address'] ?? '—') ?></code></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <?php endif; ?>
    </table>
  </div>
</div>

<?php
$page_url = fn(int $p): string => 'activity_logs.php?' . http_build_query($filter_query + ($tab === 'logins' ? ['tab' => 'logins'] : []) + ['page' => $p]);
$pagination_labels = ['&laquo; Newer', 'Older &raquo;'];
include __DIR__ . '/../includes/pagination.php';
?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
