<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_role('admin');

// Files > Archived Docs: documents taken out of 201-Files -- archived
// (automatically when nobody has opened them in ARCHIVE_UNVIEWED_YEARS
// years, or by the Admin). They can be restored or deleted here. Opening an
// archived document doesn't restore it. Each faculty member sees their own
// archived documents in their Archived Docs (archive.php). Deleted
// documents are in Recently Deleted (recently_deleted.php), never here.

// The old "Deleted" tab is now Recently Deleted
if (($_GET['tab'] ?? '') === 'deleted') {
    header('Location: ' . BASE_URL . '/recently_deleted.php');
    exit;
}

$page_title = 'Archived Docs';
$categories = document_categories();
$category = $_GET['category'] ?? '';
$faculty_id = (int)($_GET['faculty'] ?? 0);
$how = $_GET['how'] ?? '';   // 'auto' / 'manual'
if (!array_key_exists($category, $categories)) { $category = ''; }
if (!in_array($how, ['auto', 'manual'], true)) { $how = ''; }

$sql = "SELECT d.document_id, d.faculty_id, d.document_type, d.document_subtype, d.file_path, d.title, d.status, d.filed_at, d.last_viewed_at,
               d.archived_at AS removed_at, d.archive_reason AS removed_reason, u.full_name, r.full_name AS removed_by_name, r.role AS removed_by_role
        FROM documents d
        JOIN users u ON u.user_id = d.faculty_id
        LEFT JOIN users r ON r.user_id = d.archived_by
        WHERE d.status = 'archived'";
$params = [];
if ($category !== '') { $sql .= " AND d.document_type = ?"; $params[] = $category; }
if ($faculty_id)      { $sql .= " AND d.faculty_id = ?";   $params[] = $faculty_id; }
if ($how !== '')      { $sql .= $how === 'auto' ? " AND d.archived_by IS NULL" : " AND d.archived_by IS NOT NULL"; }
$stmt = $pdo->prepare($sql . " ORDER BY removed_at DESC, d.document_id DESC");
$stmt->execute($params);
$documents = $stmt->fetchAll();

$deleted_count = (int)$pdo->query("SELECT COUNT(*) FROM documents WHERE status = 'deleted'")->fetchColumn();
$faculty_list = $pdo->query(
    "SELECT DISTINCT u.user_id, u.full_name FROM documents d JOIN users u ON u.user_id = d.faculty_id
     WHERE d.status = 'archived' ORDER BY u.full_name"
)->fetchAll(PDO::FETCH_KEY_PAIR);
if (!isset($faculty_list[$faculty_id])) { $faculty_id = 0; }

include __DIR__ . '/../includes/header.php';
include_once __DIR__ . '/../includes/document_actions.php';   // Restore / Delete buttons + confirmation dialog
?>

<?= admin_intro('Documents taken out of 201-Files — archived by the Admin, or automatically when nobody has opened them in ' . h(archive_period_label()) . ' (checked once a day).
  Their owners still see them in their Archived Docs. Opening one does not restore it; restore it here, with a reason.
  Deleted documents are in <a href="' . BASE_URL . '/recently_deleted.php">Recently Deleted</a>' . ($deleted_count ? ' (' . $deleted_count . ')' : '') . '.') ?>

<?php $filtered = $category !== '' || $faculty_id || $how !== ''; ?>
<section class="ui-card" aria-label="Archived documents">
  <form method="GET" class="ui-toolbar" data-autosubmit>
    <label for="fCategory" class="visually-hidden">Category</label>
    <select name="category" id="fCategory" class="form-select">
      <option value="">All categories</option>
      <?php foreach ($categories as $key => $meta): ?>
        <option value="<?= h($key) ?>" <?= $category === $key ? 'selected' : '' ?>><?= h($meta['label']) ?></option>
      <?php endforeach; ?>
    </select>
    <label for="fHow" class="visually-hidden">How it was archived</label>
    <select name="how" id="fHow" class="form-select">
      <option value="">All reasons</option>
      <option value="auto" <?= $how === 'auto' ? 'selected' : '' ?>>Auto-archived</option>
      <option value="manual" <?= $how === 'manual' ? 'selected' : '' ?>>Archived by Admin</option>
    </select>
    <label for="fFaculty" class="visually-hidden">Faculty member</label>
    <select name="faculty" id="fFaculty" class="form-select">
      <option value="">All faculty</option>
      <?php foreach ($faculty_list as $id => $name): ?>
        <option value="<?= (int)$id ?>" <?= $faculty_id === (int)$id ? 'selected' : '' ?>><?= h($name) ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-sm btn-brand ui-apply" data-auto><i class="fa-solid fa-filter" aria-hidden="true"></i> Filter</button>
    <?php if ($filtered): ?><a href="archived_documents.php" class="btn btn-sm btn-outline-secondary">Clear filters</a><?php endif; ?>
    <span class="ui-count"><?= count($documents) ?> result<?= count($documents) === 1 ? '' : 's' ?></span>
  </form>

  <div class="table-responsive">
    <table class="table align-middle">
      <thead>
        <tr><th>File</th><th>Faculty</th><th>Type</th><th>Archived</th><th>Reason</th><th class="text-end"><span class="visually-hidden">Actions</span></th></tr>
      </thead>
      <tbody>
        <?php if (!$documents): ?>
          <?= admin_empty_row(6, $filtered ? 'No archived documents match' : 'No archived documents',
                $filtered ? 'Try different filters, or clear them to see everything.' : 'Archived documents will appear here.',
                $filtered ? 'archived_documents.php' : null, 'fa-box-archive') ?>
        <?php endif; ?>
        <?php foreach ($documents as $d): $view_url = BASE_URL . '/api/modal.php?view=document&id=' . (int)$d['document_id']; ?>
        <tr class="row-clickable" tabindex="0" role="button" data-app-modal="<?= h($view_url) ?>" aria-label="Open <?= h(document_title($d)) ?>">
          <td class="text-break">
            <div class="fw-semibold"><?= h(document_title($d)) ?></div>
            <div class="ui-cell-sub">Uploaded <?= h(date('M j, Y', strtotime($d['filed_at']))) ?> · last opened <?= h(date('M j, Y', strtotime($d['last_viewed_at'] ?? $d['filed_at']))) ?></div>
          </td>
          <td><a href="<?= BASE_URL ?>/archive.php?id=<?= (int)$d['faculty_id'] ?>" class="text-body fw-semibold" title="This faculty member's archive"><?= h($d['full_name']) ?></a></td>
          <td><?= h(document_type_label($d['document_type'], $d['document_subtype'])) ?></td>
          <td class="text-nowrap"><?= $d['removed_at'] ? h(date('M j, Y', strtotime($d['removed_at']))) : '—' ?></td>
          <td>
            <?php if ($d['removed_by_name']): ?>
              <span class="badge bg-info">Archived by <?= $d['removed_by_role'] !== 'admin' ? 'owner' : 'Admin' ?></span>
              <?php if ($d['removed_by_role'] === 'admin'): ?><div class="ui-cell-sub"><?= h($d['removed_by_name']) ?></div><?php endif; ?>
            <?php else: ?>
              <span class="badge bg-secondary">Auto-archived</span>
              <div class="ui-cell-sub">Not opened in <?= h(archive_period_label()) ?></div>
            <?php endif; ?>
            <?php if ($d['removed_reason'] !== null && $d['removed_reason'] !== ''): ?><div class="ui-cell-sub text-break"><?= h($d['removed_reason']) ?></div><?php endif; ?>
          </td>
          <td>
            <div class="ui-actions">
              <?= admin_icon_link(document_url((int)$d['document_id']), 'fa-eye', 'View details', 'target="_blank" data-app-modal="' . h($view_url) . '"') ?>
              <?= document_action_buttons($d, current_user()) ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<?php include __DIR__ . '/../includes/footer.php'; ?>
