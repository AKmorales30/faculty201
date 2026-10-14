<?php
/**
 * Recently Deleted -- every role (Files / File > Recently Deleted).
 *   Admin     every deleted document; Restore (any) and Delete permanently now
 *   others    their own deleted documents; Restore for the ones they deleted
 *             themselves (can_restore_deleted()) -- the Admin restores the rest
 *
 * A deleted document stays here for DELETED_RETENTION_DAYS from when it was
 * deleted, then the daily check deletes it permanently
 * (cleanup_recently_deleted()). Restore puts it back where it was (Public
 * Docs, Archived Docs or For Review). Clicking a row opens its preview.
 * Actions post to document_action.php (CSRF token), which checks the role
 * and ownership again.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_login();

$me = current_user();
$is_admin = $me['role'] === 'admin';
$page_title = 'Recently Deleted';
$categories = document_categories();

// Filters (all optional). Anything invalid is ignored rather than erroring.
$valid_date = function (string $d): string {
    $dt = DateTime::createFromFormat('!Y-m-d', $d);
    return ($dt && $dt->format('Y-m-d') === $d) ? $d : '';
};
$category   = $_GET['category'] ?? '';
$faculty_id = $is_admin ? (int)($_GET['faculty'] ?? 0) : 0;
$from       = $valid_date(trim($_GET['from'] ?? ''));
$to         = $valid_date(trim($_GET['to'] ?? ''));
if (!array_key_exists($category, $categories)) { $category = ''; }
if ($from !== '' && $to !== '' && $from > $to) { [$from, $to] = [$to, $from]; }

$where = ["d.status = 'deleted'"];
$params = [];
if (!$is_admin)       { $where[] = "d.faculty_id = ?"; $params[] = (int)$me['user_id']; }   // only your own
if ($faculty_id)      { $where[] = "d.faculty_id = ?"; $params[] = $faculty_id; }
if ($category !== '') { $where[] = "d.document_type = ?"; $params[] = $category; }
if ($from !== '')     { $where[] = "d.deleted_at >= ?"; $params[] = $from . ' 00:00:00'; }
if ($to !== '')       { $where[] = "d.deleted_at < DATE_ADD(?, INTERVAL 1 DAY)"; $params[] = $to; }
$stmt = $pdo->prepare(
    "SELECT d.document_id, d.faculty_id, d.document_type, d.document_subtype, d.file_path, d.title, d.status, d.filed_at,
            d.deleted_at, d.deleted_by, d.delete_reason, d.status_before_delete, d.posted_at,
            u.full_name, r.full_name AS deleted_by_name, r.role AS deleted_by_role
     FROM documents d
     JOIN users u ON u.user_id = d.faculty_id
     LEFT JOIN users r ON r.user_id = d.deleted_by
     WHERE " . implode(' AND ', $where) . "
     ORDER BY d.deleted_at ASC, d.document_id ASC"   // the ones going soonest first
);
$stmt->execute($params);
$documents = $stmt->fetchAll();

$faculty_list = [];
if ($is_admin) {
    $faculty_list = $pdo->query(
        "SELECT DISTINCT u.user_id, u.full_name FROM documents d JOIN users u ON u.user_id = d.faculty_id
         WHERE d.status = 'deleted' ORDER BY u.full_name"
    )->fetchAll(PDO::FETCH_KEY_PAIR);
}

/** Where Restore puts a document back. */
$restores_to = fn(array $d): string => match ($d['status_before_delete'] ?: ($d['posted_at'] ? 'active' : 'for_review')) {
    'archived'   => 'Archived Docs',
    'for_review' => 'For Review',
    default      => 'Public Docs',
};
$has_filters = $category !== '' || $faculty_id || $from !== '' || $to !== '';

include __DIR__ . '/includes/header.php';
include_once __DIR__ . '/includes/document_actions.php';   // Restore / Delete permanently + confirmation dialog
?>

<h3 class="fw-bold mb-1">Recently Deleted</h3>
<p class="text-muted mb-4">
  Deleted documents stay here for <?= (int)DELETED_RETENTION_DAYS ?> days and can be restored to where they were -- Public Docs, Archived Docs or For Review -- with their details.
  After that they are deleted permanently, file and record, and can't be brought back.
  <?php if ($is_admin): ?>You can also delete one permanently now.<?php else: ?>You can restore documents you deleted yourself; for one the Admin deleted, ask the Admin.<?php endif; ?>
  Deleted documents don't appear in Public Docs, Archived Docs, searches or reports. Click a row to preview it.
</p>

<div class="card stat-card mb-4">
  <div class="card-body">
    <form method="GET" class="row g-2 align-items-end">
      <?php if ($is_admin): ?>
      <div class="col-md-6 col-xl-3">
        <label for="fFaculty" class="form-label small fw-semibold">Faculty Member</label>
        <select name="faculty" id="fFaculty" class="form-select">
          <option value="">All Faculty</option>
          <?php foreach ($faculty_list as $id => $name): ?>
            <option value="<?= (int)$id ?>" <?= $faculty_id === (int)$id ? 'selected' : '' ?>><?= h($name) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <div class="col-md-6 col-xl-3">
        <label for="fCategory" class="form-label small fw-semibold">Document Type</label>
        <select name="category" id="fCategory" class="form-select">
          <option value="">All Types</option>
          <?php foreach ($categories as $key => $meta): ?>
            <option value="<?= h($key) ?>" <?= $category === $key ? 'selected' : '' ?>><?= h($meta['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-3 col-xl-2">
        <label for="fFrom" class="form-label small fw-semibold">Deleted From</label>
        <input type="date" name="from" id="fFrom" value="<?= h($from) ?>" class="form-control">
      </div>
      <div class="col-6 col-md-3 col-xl-2">
        <label for="fTo" class="form-label small fw-semibold">Deleted To</label>
        <input type="date" name="to" id="fTo" value="<?= h($to) ?>" class="form-control">
      </div>
      <div class="col-md-6 col-xl-2 d-flex gap-2">
        <button class="btn btn-brand flex-fill"><i class="fa-solid fa-filter"></i> Filter</button>
        <a href="recently_deleted.php" class="btn btn-outline-secondary flex-fill">Reset</a>
      </div>
    </form>
  </div>
</div>

<div class="small text-muted mb-2"><?= count($documents) ?> deleted document<?= count($documents) === 1 ? '' : 's' ?>, the ones deleted permanently soonest first</div>
<div class="card stat-card">
  <div class="card-body p-0 table-responsive">
    <table class="table table-hover mb-0 align-middle">
      <thead class="table-light">
        <tr>
          <th>Document</th><?php if ($is_admin): ?><th>Faculty</th><?php endif; ?><th>Document Type</th>
          <th>Deleted By</th><th>Reason</th><th>Deleted</th><th>Time Left</th><th></th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$documents): ?>
          <tr><td colspan="<?= $is_admin ? 8 : 7 ?>" class="text-center text-muted py-4">
            <i class="fa-solid fa-trash-can-arrow-up"></i> <?= $has_filters ? 'No deleted documents match these filters.' : 'Recently Deleted is empty.' ?>
          </td></tr>
        <?php endif; ?>
        <?php foreach ($documents as $d):
          $days = deleted_days_left($d['deleted_at']);
          $soon = $days <= 7; ?>
        <tr class="row-clickable" tabindex="0" role="button" data-app-modal="<?= h(BASE_URL . '/api/modal.php?view=document&id=' . (int)$d['document_id']) ?>">
          <td class="text-break small">
            <div class="fw-semibold"><?= h(document_title($d)) ?></div>
            <div class="text-muted">Restores to <?= h($restores_to($d)) ?></div>
          </td>
          <?php if ($is_admin): ?><td class="small"><?= h($d['full_name']) ?></td><?php endif; ?>
          <td class="small"><?= h(document_type_label($d['document_type'], $d['document_subtype'])) ?></td>
          <td class="small">
            <?php if ($d['deleted_by_name']): ?>
              <?= (int)$d['deleted_by'] === (int)$me['user_id'] ? 'You' : h($d['deleted_by_name']) ?>
              <?php if ($d['deleted_by_role'] === 'admin' && (int)$d['deleted_by'] !== (int)$me['user_id']): ?><div class="text-muted">Admin</div><?php endif; ?>
              <?php if ($is_admin && (int)$d['deleted_by'] === (int)$d['faculty_id']): ?><div class="text-muted">owner</div><?php endif; ?>
            <?php else: ?><span class="text-muted">—</span><?php endif; ?>
          </td>
          <td class="small" style="min-width:7rem;overflow-wrap:anywhere"><?= $d['delete_reason'] !== null && $d['delete_reason'] !== '' ? h($d['delete_reason']) : '<span class="text-muted">—</span>' ?></td>
          <td class="small text-nowrap"><?= h(date('M j, Y', strtotime($d['deleted_at']))) ?><div class="text-muted"><?= h(date('g:i A', strtotime($d['deleted_at']))) ?></div></td>
          <td class="small">
            <span class="purge-countdown <?= $soon ? 'purge-countdown-soon' : '' ?>" title="Permanently deleted on <?= h(deleted_purge_at($d['deleted_at'])->format('F j, Y g:i A')) ?>">
              <?php if ($soon): ?><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i><?php endif; ?>
              <?= h(deleted_days_left_label($days)) ?>
            </span>
          </td>
          <td class="text-nowrap"><?= document_action_buttons($d, $me) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
