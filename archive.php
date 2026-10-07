<?php
/**
 * Archive of one faculty member's 201 file: documents moved out of the
 * active file, automatically (more than ARCHIVE_AFTER_YEARS old) or by the
 * Admin. Nothing is deleted -- the file and record are kept.
 *
 *   archive.php        My Archive: your own (view and download only)
 *   archive.php?id=N   someone else's, if profile_scope_sql() allows it.
 *                      The Admin can open and restore documents; Program
 *                      Chairs and Deans see the list only, as they can't
 *                      open faculty files (can_access_document()).
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_login();

$me = current_user();
$is_admin = $me['role'] === 'admin';
$target_id = isset($_GET['id']) ? (int)$_GET['id'] : (int)$me['user_id'];
$is_own = $target_id === (int)$me['user_id'];

if ($is_own && $is_admin) {   // the Admin has no 201 file: their page is the all-faculty one
    header('Location: ' . BASE_URL . '/admin/archived_documents.php');
    exit;
}
$owner = user_row($pdo, $target_id);
if (!$owner || !in_array($owner['role'], ['faculty', 'program_chair', 'dean'], true) || !can_view_profile($pdo, $me, $target_id)) {
    $_SESSION['flash_error'] = 'You do not have access to that archive.';
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$categories = document_categories();
$category = $_GET['category'] ?? '';
if (!array_key_exists($category, $categories)) { $category = ''; }
$q = trim($_GET['q'] ?? '');

$sql = "SELECT d.*, " . document_date_sql('d') . " AS doc_date, u.full_name, r.full_name AS archived_by_name
        FROM documents d
        JOIN users u ON u.user_id = d.faculty_id
        LEFT JOIN users r ON r.user_id = d.archived_by
        WHERE d.faculty_id = ? AND d.status = 'archived'";
$params = [$target_id];
if ($category !== '') { $sql .= " AND d.document_type = ?"; $params[] = $category; }
if ($q !== '') {
    $sql .= " AND (d.title LIKE ? OR d.file_path LIKE ? OR d.conducted_by LIKE ? OR d.venue LIKE ?)";
    array_push($params, "%$q%", "%$q%", "%$q%", "%$q%");
}
$stmt = $pdo->prepare($sql . " ORDER BY doc_date DESC, d.document_id DESC");
$stmt->execute($params);
$documents = $stmt->fetchAll();
if ($q !== '') {
    log_my_activity($pdo, 'SEARCH', 'Archive of ' . $owner['full_name'] . ' -- ' . describe_filters([
        'Keywords' => $q, 'Category' => $category !== '' ? $categories[$category]['label'] : '',
    ]) . ' (' . count($documents) . ' result' . (count($documents) === 1 ? '' : 's') . ').');
}

$page_title = $is_own ? 'My Archive' : $owner['full_name'] . ' — Archive';
include __DIR__ . '/includes/header.php';
if ($is_admin) { include_once __DIR__ . '/includes/document_actions.php'; }   // Restore / Delete + confirmation dialog
?>

<?php if (!$is_own): ?>
  <a href="<?= BASE_URL ?>/profile.php?id=<?= $target_id ?>" class="small text-muted d-inline-block mb-3"><i class="fa-solid fa-arrow-left"></i> Back to <?= h($owner['full_name']) ?>'s profile</a>
<?php endif; ?>

<div class="d-flex align-items-center gap-3 mb-1">
  <?php if (!$is_own): ?><?= user_avatar($owner, 44) ?><?php endif; ?>
  <h3 class="fw-bold mb-0"><?= $is_own ? 'My Archive' : h($owner['full_name']) . ' — Archive' ?></h3>
</div>
<p class="text-muted mb-4">
  Documents more than <?= (int)ARCHIVE_AFTER_YEARS ?> years old (by their seminar date or date issued, otherwise their upload date) move here automatically,
  as do documents the Admin archives. They are kept, but no longer appear in the active 201 file, searches, dashboards or expiration alerts.
  <?php if ($is_own): ?>You can still view and download them. Ask the Admin if one should be restored.<?php endif; ?>
  <?php if (!$is_own && !$is_admin): ?>This list shows the document details only; the files can be opened by their owner and the Admin.<?php endif; ?>
</p>

<div class="card stat-card mb-4">
  <div class="card-body">
    <form method="GET" class="row g-2 align-items-end">
      <?php if (!$is_own): ?><input type="hidden" name="id" value="<?= $target_id ?>"><?php endif; ?>
      <div class="col-md-5">
        <label for="fQ" class="form-label small fw-semibold">Search</label>
        <input type="search" name="q" id="fQ" value="<?= h($q) ?>" class="form-control" placeholder="Title, file name, organizer, venue...">
      </div>
      <div class="col-md-4">
        <label for="fCategory" class="form-label small fw-semibold">Category</label>
        <select name="category" id="fCategory" class="form-select">
          <option value="">All Categories</option>
          <?php foreach ($categories as $key => $meta): ?>
            <option value="<?= h($key) ?>" <?= $category === $key ? 'selected' : '' ?>><?= h($meta['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3 d-flex gap-2">
        <button class="btn btn-brand flex-fill"><i class="fa-solid fa-filter"></i> Filter</button>
        <a href="archive.php<?= $is_own ? '' : '?id=' . $target_id ?>" class="btn btn-outline-secondary flex-fill">Reset</a>
      </div>
    </form>
  </div>
</div>

<div class="card stat-card">
  <div class="card-header bg-white fw-semibold"><?= count($documents) ?> archived document<?= count($documents) === 1 ? '' : 's' ?></div>
  <div class="card-body p-0 table-responsive">
    <table class="table mb-0 align-middle">
      <thead class="table-light">
        <tr><th>Document</th><th>Category</th><th>Document Date</th><th>Archived</th><th>Reason</th><?php if ($is_own || $is_admin): ?><th></th><?php endif; ?></tr>
      </thead>
      <tbody>
        <?php if (!$documents): ?>
          <tr><td colspan="6" class="text-center text-muted py-4">No archived documents<?= $q !== '' || $category !== '' ? ' match these filters' : '' ?>.</td></tr>
        <?php endif; ?>
        <?php foreach ($documents as $d): ?>
        <tr>
          <td class="text-break">
            <div class="fw-semibold small"><?= h(document_title($d)) ?></div>
            <?php if (trim((string)$d['title']) !== ''): ?><div class="small text-muted"><?= h(document_display_name($d['file_path'])) ?></div><?php endif; ?>
            <?php if ($d['conducted_by'] || $d['venue']): ?>
              <div class="small text-muted"><?= h(implode(' · ', array_filter([$d['conducted_by'], $d['venue']]))) ?></div>
            <?php endif; ?>
          </td>
          <td class="small"><?= h(document_type_label($d['document_type'], $d['document_subtype'])) ?></td>
          <td class="small text-nowrap">
            <?= h($d['date_start'] ? document_date_range_label($d['date_start'], $d['date_end']) : date('M j, Y', strtotime($d['doc_date']))) ?>
            <?php if (!$d['date_start'] && !$d['date_issued']): ?><div class="text-muted">(upload date)</div><?php endif; ?>
          </td>
          <td class="small text-nowrap">
            <?= $d['archived_at'] ? h(date('M j, Y', strtotime($d['archived_at']))) : '—' ?>
            <div class="text-muted"><?= $d['archived_by_name'] ? 'by ' . h($d['archived_by_name']) : 'automatically' ?></div>
          </td>
          <td class="small text-break"><?= $d['archive_reason'] !== null ? h($d['archive_reason']) : '<span class="text-muted">—</span>' ?></td>
          <?php if ($is_own || $is_admin): ?>
          <td>
            <div class="d-flex flex-wrap gap-1">
              <a href="<?= h(document_url((int)$d['document_id'])) ?>" target="_blank" class="btn btn-sm btn-outline-brand text-nowrap" title="View"><i class="fa-solid fa-eye"></i> View</a>
              <a href="<?= h(document_url((int)$d['document_id'])) ?>&amp;download=1" class="btn btn-sm btn-outline-brand text-nowrap" title="Download"><i class="fa-solid fa-download"></i></a>
              <?php if ($is_admin): ?><?= document_action_buttons($d, $me) ?><?php endif; ?>
            </div>
          </td>
          <?php endif; ?>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
