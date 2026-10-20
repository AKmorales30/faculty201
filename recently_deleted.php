<?php
/**
 * Recently Deleted -- every role (Files / File > Recently Deleted).
 *   Admin     every deleted document; Restore (any) and Delete permanently now
 *   others    their own deleted documents; Restore for the ones they deleted
 *             themselves (can_restore_deleted()) -- the Admin restores the rest
 *
 * A deleted document stays here for DELETED_RETENTION_DAYS from when it was
 * deleted, then the daily check deletes it permanently
 * (cleanup_recently_deleted()). Restore puts it back where it was
 * (201-Files, Archived Docs or For Review). Clicking a row opens its preview.
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
    default      => '201-Files',
};
$has_filters = $category !== '' || $faculty_id || $from !== '' || $to !== '';

include __DIR__ . '/includes/header.php';
include_once __DIR__ . '/includes/document_actions.php';   // Restore / Delete permanently + confirmation dialog
?>

<?= admin_intro($is_admin
    ? 'Deleted files stay here for ' . (int)DELETED_RETENTION_DAYS . ' days, then they are removed for good, file and record. Restore a file to put it back where it was — 201-Files, Archived Docs or For Review — or delete it permanently now.'
    : 'Files you deleted stay here for ' . (int)DELETED_RETENTION_DAYS . ' days, then they are removed for good. Restore one to put it back where it was — 201-Files, Archived Docs or For Review. For a file the Admin deleted, ask the Admin.') ?>

<section class="ui-card" aria-label="Recently deleted documents">
  <form method="GET" class="ui-toolbar" data-autosubmit>
    <?php if ($is_admin): ?>
    <label for="fFaculty" class="visually-hidden">Faculty member</label>
    <select name="faculty" id="fFaculty" class="form-select">
      <option value="">All faculty</option>
      <?php foreach ($faculty_list as $id => $name): ?>
        <option value="<?= (int)$id ?>" <?= $faculty_id === (int)$id ? 'selected' : '' ?>><?= h($name) ?></option>
      <?php endforeach; ?>
    </select>
    <?php endif; ?>
    <label for="fCategory" class="visually-hidden">Document type</label>
    <select name="category" id="fCategory" class="form-select">
      <option value="">All types</option>
      <?php foreach ($categories as $key => $meta): ?>
        <option value="<?= h($key) ?>" <?= $category === $key ? 'selected' : '' ?>><?= h($meta['label']) ?></option>
      <?php endforeach; ?>
    </select>
    <label for="fFrom" class="visually-hidden">Deleted from</label>
    <input type="date" name="from" id="fFrom" value="<?= h($from) ?>" class="form-control" title="Deleted from">
    <label for="fTo" class="visually-hidden">Deleted to</label>
    <input type="date" name="to" id="fTo" value="<?= h($to) ?>" class="form-control" title="Deleted to">
    <button class="btn btn-sm btn-brand ui-apply"><i class="fa-solid fa-filter" aria-hidden="true"></i> Apply</button>
    <?php if ($has_filters): ?><a href="recently_deleted.php" class="btn btn-sm btn-outline-secondary">Clear filters</a><?php endif; ?>
    <span class="ui-count"><?= count($documents) ?> result<?= count($documents) === 1 ? '' : 's' ?> · removed soonest first</span>
  </form>

  <div class="table-responsive">
    <table class="table align-middle">
      <thead>
        <tr><th>File</th><?php if ($is_admin): ?><th>Faculty</th><?php endif; ?><th>Type</th><th>Deleted</th><th>Removed in</th><th class="text-end"><span class="visually-hidden">Actions</span></th></tr>
      </thead>
      <tbody>
        <?php if (!$documents): ?>
          <?= admin_empty_row($is_admin ? 6 : 5, $has_filters ? 'No deleted documents match' : 'Nothing in Recently Deleted',
                $has_filters ? 'Try different filters, or clear them to see everything.' : 'Deleted documents stay here for ' . (int)DELETED_RETENTION_DAYS . ' days.',
                $has_filters ? 'recently_deleted.php' : null, 'fa-trash-can-arrow-up') ?>
        <?php endif; ?>
        <?php foreach ($documents as $d):
          $days = deleted_days_left($d['deleted_at']);
          $soon = $days <= 7; ?>
        <tr class="row-clickable" tabindex="0" role="button" data-app-modal="<?= h(BASE_URL . '/api/modal.php?view=document&id=' . (int)$d['document_id']) ?>" aria-label="Open <?= h(document_title($d)) ?>">
          <td class="text-break">
            <div class="fw-semibold"><?= h(document_title($d)) ?></div>
            <div class="ui-cell-sub">Restores to <?= h($restores_to($d)) ?></div>
          </td>
          <?php if ($is_admin): ?><td><?= h($d['full_name']) ?></td><?php endif; ?>
          <td><?= h(document_type_label($d['document_type'], $d['document_subtype'])) ?></td>
          <td class="text-nowrap">
            <?= h(date('M j, Y', strtotime($d['deleted_at']))) ?>
            <div class="ui-cell-sub">
              <?php if ($d['deleted_by_name']): ?>by <?= (int)$d['deleted_by'] === (int)$me['user_id'] ? 'you' : h($d['deleted_by_name']) . ((int)$d['deleted_by'] === (int)$d['faculty_id'] ? ' (owner)' : '') ?><?php endif; ?>
            </div>
            <?php if ($d['delete_reason'] !== null && $d['delete_reason'] !== ''): ?><div class="ui-cell-sub text-wrap" style="max-width:16rem"><?= h($d['delete_reason']) ?></div><?php endif; ?>
          </td>
          <td>
            <span class="badge <?= $soon ? 'bg-danger' : 'bg-secondary' ?>" title="Permanently deleted on <?= h(deleted_purge_at($d['deleted_at'])->format('F j, Y g:i A')) ?>"><?= $days <= 0 ? 'Today' : $days . ' day' . ($days === 1 ? '' : 's') ?></span>
          </td>
          <td><div class="ui-actions"><?= document_action_buttons($d, $me) ?></div></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>


<?php include __DIR__ . '/includes/footer.php'; ?>
