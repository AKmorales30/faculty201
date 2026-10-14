<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_role('admin');

// Files > Archived Docs: documents taken out of Public Docs -- archived
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

<h3 class="fw-bold mb-1">Archived Docs</h3>
<p class="text-muted mb-4">Documents taken out of Public Docs. A posted document that nobody has opened in <?= h(archive_period_label()) ?> is archived automatically (checked once a day).
  Archived documents stay visible to their owner in their Archived Docs and don't appear in Public Docs, searches, reports or expiration alerts.
  Opening an archived document does not restore it -- the file and record are kept and can be restored here (with a reason).
  Deleted documents are in <a href="<?= BASE_URL ?>/recently_deleted.php">Recently Deleted</a><?= $deleted_count ? ' (' . $deleted_count . ')' : '' ?>.</p>

<div class="card stat-card mb-4">
  <div class="card-body">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-md-3">
        <label for="fCategory" class="form-label small fw-semibold">Category</label>
        <select name="category" id="fCategory" class="form-select">
          <option value="">All Categories</option>
          <?php foreach ($categories as $key => $meta): ?>
            <option value="<?= h($key) ?>" <?= $category === $key ? 'selected' : '' ?>><?= h($meta['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label for="fHow" class="form-label small fw-semibold">Archived</label>
        <select name="how" id="fHow" class="form-select">
          <option value="">Any way</option>
          <option value="auto" <?= $how === 'auto' ? 'selected' : '' ?>>Automatically</option>
          <option value="manual" <?= $how === 'manual' ? 'selected' : '' ?>>By the Admin</option>
        </select>
      </div>
      <div class="col-md-3">
        <label for="fFaculty" class="form-label small fw-semibold">Faculty Member</label>
        <select name="faculty" id="fFaculty" class="form-select">
          <option value="">All Faculty</option>
          <?php foreach ($faculty_list as $id => $name): ?>
            <option value="<?= (int)$id ?>" <?= $faculty_id === (int)$id ? 'selected' : '' ?>><?= h($name) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4 d-flex gap-2">
        <button class="btn btn-brand flex-fill"><i class="fa-solid fa-filter"></i> Filter</button>
        <a href="archived_documents.php" class="btn btn-outline-secondary flex-fill">Reset</a>
      </div>
    </form>
  </div>
</div>

<div class="card stat-card">
  <div class="card-body p-0 table-responsive">
    <table class="table mb-0 align-middle">
      <thead class="table-light">
        <tr><th>Faculty</th><th>Document</th><th>Category</th><th>Uploaded</th><th>Last Opened</th><th>Archived</th><th>Reason</th><th></th></tr>
      </thead>
      <tbody>
        <?php if (!$documents): ?>
          <tr><td colspan="8" class="text-center text-muted py-4">No archived documents.</td></tr>
        <?php endif; ?>
        <?php foreach ($documents as $d): ?>
        <tr>
          <td><a href="<?= BASE_URL ?>/archive.php?id=<?= (int)$d['faculty_id'] ?>" title="This faculty member's archive"><?= h($d['full_name']) ?></a></td>
          <td class="text-break small"><a href="<?= h(document_url((int)$d['document_id'])) ?>" target="_blank" class="text-body"
              data-app-modal="<?= h(BASE_URL . '/api/modal.php?view=document&id=' . (int)$d['document_id']) ?>"><?= h(document_title($d)) ?></a></td>
          <td><?= h(document_type_label($d['document_type'], $d['document_subtype'])) ?></td>
          <td class="text-nowrap small"><?= h(date('M j, Y', strtotime($d['filed_at']))) ?></td>
          <td class="text-nowrap small"><?= h(date('M j, Y', strtotime($d['last_viewed_at'] ?? $d['filed_at']))) ?></td>
          <td class="small">
            <?= $d['removed_at'] ? h(date('M j, Y g:i A', strtotime($d['removed_at']))) : '—' ?>
            <?php if ($d['removed_by_name']): ?>
              <div class="text-muted">by <?= h($d['removed_by_name']) ?><?= $d['removed_by_role'] !== 'admin' ? ' (owner)' : '' ?></div>
            <?php else: ?>
              <div class="text-muted">automatically</div>
            <?php endif; ?>
          </td>
          <td class="small text-break"><?= $d['removed_reason'] !== null ? h($d['removed_reason']) : '<span class="text-muted">—</span>' ?></td>
          <td>
            <div class="d-flex flex-wrap gap-1">
              <a href="<?= h(document_url((int)$d['document_id'])) ?>" target="_blank" class="btn btn-sm btn-outline-brand" data-tooltip title="View document" aria-label="View document"
                 data-app-modal="<?= h(BASE_URL . '/api/modal.php?view=document&id=' . (int)$d['document_id']) ?>"><i class="fa-solid fa-eye"></i></a>
              <?= document_action_buttons($d, current_user()) ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
