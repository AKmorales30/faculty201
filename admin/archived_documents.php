<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_role('admin');

// Documents taken out of the 201 files: archived (automatically once more
// than ARCHIVE_AFTER_YEARS old, or by the Admin), or deleted by the Admin or
// by their owner. Both are kept (soft delete) and can be restored here; an
// archived one can also be deleted. Each faculty member sees their own
// archived documents in My Archive (archive.php).

$page_title = 'Archived Documents';
$categories = document_categories();
$tabs = ['archived' => 'Archived', 'deleted' => 'Deleted'];

$tab = array_key_exists($_GET['tab'] ?? '', $tabs) ? $_GET['tab'] : 'archived';
$category = $_GET['category'] ?? '';
$faculty_id = (int)($_GET['faculty'] ?? 0);
$how = $_GET['how'] ?? '';   // archived tab: 'auto' / 'manual'
if (!array_key_exists($category, $categories)) { $category = ''; }
if ($tab !== 'archived' || !in_array($how, ['auto', 'manual'], true)) { $how = ''; }

// Removed-at / by / reason columns of the tab being shown
[$at_col, $by_col, $reason_col] = $tab === 'archived'
    ? ['d.archived_at', 'd.archived_by', 'd.archive_reason']
    : ['d.deleted_at', 'd.deleted_by', 'd.delete_reason'];

$sql = "SELECT d.document_id, d.faculty_id, d.document_type, d.document_subtype, d.file_path, d.title, d.status, d.filed_at,
               $at_col AS removed_at, $reason_col AS removed_reason, u.full_name, r.full_name AS removed_by_name, r.role AS removed_by_role
        FROM documents d
        JOIN users u ON u.user_id = d.faculty_id
        LEFT JOIN users r ON r.user_id = $by_col
        WHERE d.status = ?";
$params = [$tab];
if ($category !== '') { $sql .= " AND d.document_type = ?"; $params[] = $category; }
if ($faculty_id)      { $sql .= " AND d.faculty_id = ?";   $params[] = $faculty_id; }
if ($how !== '')      { $sql .= $how === 'auto' ? " AND d.archived_by IS NULL" : " AND d.archived_by IS NOT NULL"; }
$stmt = $pdo->prepare($sql . " ORDER BY removed_at DESC, d.document_id DESC");
$stmt->execute($params);
$documents = $stmt->fetchAll();

$counts = $pdo->query("SELECT status, COUNT(*) FROM documents WHERE status IN ('archived', 'deleted') GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
$faculty_list = $pdo->query(
    "SELECT DISTINCT u.user_id, u.full_name FROM documents d JOIN users u ON u.user_id = d.faculty_id
     WHERE d.status IN ('archived', 'deleted') ORDER BY u.full_name"
)->fetchAll(PDO::FETCH_KEY_PAIR);
if (!isset($faculty_list[$faculty_id])) { $faculty_id = 0; }

include __DIR__ . '/../includes/header.php';
include_once __DIR__ . '/../includes/document_actions.php';   // Restore / Delete buttons + confirmation dialog
?>

<h3 class="fw-bold mb-1">Archived Documents</h3>
<p class="text-muted mb-4">Documents taken out of faculty 201 files. Documents more than <?= (int)ARCHIVE_AFTER_YEARS ?> years old are archived automatically.
  Archived documents stay visible to their owner in My Archive; neither archived nor deleted documents appear in searches, reports or expiration alerts.
  The file and record are kept and can be restored (with a reason).</p>

<ul class="nav nav-tabs mb-3">
  <?php foreach ($tabs as $key => $label): ?>
  <li class="nav-item">
    <a class="nav-link <?= $tab === $key ? 'active' : '' ?>" href="archived_documents.php?tab=<?= h($key) ?>">
      <i class="fa-solid <?= $key === 'archived' ? 'fa-box-archive' : 'fa-trash-can' ?>"></i> <?= h($label) ?>
      <span class="badge bg-light text-dark border ms-1"><?= (int)($counts[$key] ?? 0) ?></span>
    </a>
  </li>
  <?php endforeach; ?>
</ul>

<div class="card stat-card mb-4">
  <div class="card-body">
    <form method="GET" class="row g-2 align-items-end">
      <input type="hidden" name="tab" value="<?= h($tab) ?>">
      <div class="<?= $tab === 'archived' ? 'col-md-3' : 'col-md-4' ?>">
        <label for="fCategory" class="form-label small fw-semibold">Category</label>
        <select name="category" id="fCategory" class="form-select">
          <option value="">All Categories</option>
          <?php foreach ($categories as $key => $meta): ?>
            <option value="<?= h($key) ?>" <?= $category === $key ? 'selected' : '' ?>><?= h($meta['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php if ($tab === 'archived'): ?>
      <div class="col-md-2">
        <label for="fHow" class="form-label small fw-semibold">Archived</label>
        <select name="how" id="fHow" class="form-select">
          <option value="">Any way</option>
          <option value="auto" <?= $how === 'auto' ? 'selected' : '' ?>>Automatically</option>
          <option value="manual" <?= $how === 'manual' ? 'selected' : '' ?>>By the Admin</option>
        </select>
      </div>
      <?php endif; ?>
      <div class="<?= $tab === 'archived' ? 'col-md-3' : 'col-md-4' ?>">
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
        <a href="archived_documents.php?tab=<?= h($tab) ?>" class="btn btn-outline-secondary flex-fill">Reset</a>
      </div>
    </form>
  </div>
</div>

<div class="card stat-card">
  <div class="card-body p-0 table-responsive">
    <table class="table mb-0 align-middle">
      <thead class="table-light">
        <tr><th>Faculty</th><th>Document</th><th>Category</th><th>Uploaded</th><th><?= $tab === 'archived' ? 'Archived' : 'Deleted' ?></th><th>Reason</th><th></th></tr>
      </thead>
      <tbody>
        <?php if (!$documents): ?>
          <tr><td colspan="7" class="text-center text-muted py-4">No <?= $tab === 'archived' ? 'archived' : 'deleted' ?> documents.</td></tr>
        <?php endif; ?>
        <?php foreach ($documents as $d): ?>
        <tr>
          <td><a href="<?= BASE_URL ?>/archive.php?id=<?= (int)$d['faculty_id'] ?>" title="This faculty member's archive"><?= h($d['full_name']) ?></a></td>
          <td class="text-break small"><?= h(document_title($d)) ?></td>
          <td><?= h(document_type_label($d['document_type'], $d['document_subtype'])) ?></td>
          <td class="text-nowrap small"><?= h(date('M j, Y', strtotime($d['filed_at']))) ?></td>
          <td class="small">
            <?= $d['removed_at'] ? h(date('M j, Y g:i A', strtotime($d['removed_at']))) : '—' ?>
            <?php if ($d['removed_by_name']): ?>
              <div class="text-muted">by <?= h($d['removed_by_name']) ?><?= $d['removed_by_role'] !== 'admin' ? ' (owner)' : '' ?></div>
            <?php elseif ($tab === 'archived'): ?>
              <div class="text-muted">automatically</div>
            <?php endif; ?>
          </td>
          <td class="small text-break"><?= $d['removed_reason'] !== null ? h($d['removed_reason']) : '<span class="text-muted">—</span>' ?></td>
          <td>
            <div class="d-flex flex-wrap gap-1">
              <a href="<?= h(document_url((int)$d['document_id'])) ?>" target="_blank" class="btn btn-sm btn-outline-brand" title="View"><i class="fa-solid fa-eye"></i></a>
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
