<?php
/**
 * Archive of one faculty member's 201 file (Archived Docs): documents moved
 * out of 201-Files, automatically (not opened in ARCHIVE_UNVIEWED_YEARS
 * years) or by the Admin. Nothing is deleted -- the file and record are kept.
 * Opening an archived document doesn't restore it; the Admin does that.
 *
 *   archive.php        Archived Docs: your own (view and download only)
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

$page_title = $is_own ? 'Archived Docs' : $owner['full_name'] . ' — Archive';
include __DIR__ . '/includes/header.php';
if ($is_admin) { include_once __DIR__ . '/includes/document_actions.php'; }   // Restore / Delete + confirmation dialog
?>

<?php
  $filtered = $q !== '' || $category !== '';
  $clear_url = 'archive.php' . ($is_own ? '' : '?id=' . $target_id);
  $can_open = $is_own || $is_admin; ?>
<?php if (!$is_own): ?>
  <a href="<?= BASE_URL ?>/profile.php?id=<?= $target_id ?>" class="ui-back"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Back to <?= h($owner['full_name']) ?>'s profile</a>
<?php endif; ?>
<?= admin_intro(($is_own
      ? 'Your documents that haven\'t been opened in ' . h(archive_period_label()) . ', or that the Admin archived. They are kept, but no longer appear in 201-Files, searches or expiration alerts. You can still view and download them; ask the Admin if one should be restored.'
      : 'Documents of <strong>' . h($owner['full_name']) . '</strong> that haven\'t been opened in ' . h(archive_period_label()) . ', or that the Admin archived.'
        . ($is_admin ? ' Opening one does not restore it; restore it here, with a reason.' : ' This list shows the document details only; the files can be opened by their owner and the Admin.'))) ?>

<section class="ui-card" aria-label="Archived documents">
  <form method="GET" class="ui-toolbar" data-autosubmit role="search">
    <?php if (!$is_own): ?><input type="hidden" name="id" value="<?= $target_id ?>"><?php endif; ?>
    <label class="ui-search"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
      <input type="search" name="q" value="<?= h($q) ?>" placeholder="Title, file name, organizer, venue" aria-label="Search archived documents"></label>
    <label for="fCategory" class="visually-hidden">Category</label>
    <select name="category" id="fCategory" class="form-select">
      <option value="">All categories</option>
      <?php foreach ($categories as $key => $meta): ?>
        <option value="<?= h($key) ?>" <?= $category === $key ? 'selected' : '' ?>><?= h($meta['label']) ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-sm btn-brand"><i class="fa-solid fa-filter" aria-hidden="true"></i> Filter</button>
    <?php if ($filtered): ?><a href="<?= h($clear_url) ?>" class="btn btn-sm btn-outline-secondary">Clear filters</a><?php endif; ?>
    <span class="ui-count"><?= count($documents) ?> result<?= count($documents) === 1 ? '' : 's' ?></span>
  </form>

  <div class="table-responsive">
    <table class="table align-middle">
      <thead>
        <tr><th>Document</th><th>Type</th><th>Document date</th><th>Archived</th><th>Reason</th><?php if ($can_open): ?><th class="text-end"><span class="visually-hidden">Actions</span></th><?php endif; ?></tr>
      </thead>
      <tbody>
        <?php if (!$documents): ?>
          <?= admin_empty_row($can_open ? 6 : 5, $filtered ? 'No archived documents match' : 'No archived documents',
                $filtered ? 'Try different filters, or clear them to see everything.' : 'Documents that haven\'t been opened in ' . archive_period_label() . ' will appear here.',
                $filtered ? $clear_url : null, 'fa-box-archive') ?>
        <?php endif; ?>
        <?php foreach ($documents as $d): $view_url = BASE_URL . '/api/modal.php?view=document&id=' . (int)$d['document_id']; ?>
        <tr<?php if ($can_open): ?> class="row-clickable" tabindex="0" role="button" data-app-modal="<?= h($view_url) ?>" aria-label="Open <?= h(document_title($d)) ?>"<?php endif; ?>>
          <td class="text-break">
            <div class="fw-semibold"><?= h(document_title($d)) ?></div>
            <?php if (trim((string)$d['title']) !== ''): ?><div class="ui-cell-sub"><?= h(document_display_name($d['file_path'])) ?></div><?php endif; ?>
            <?php if ($d['conducted_by'] || $d['venue']): ?><div class="ui-cell-sub"><?= h(implode(' · ', array_filter([$d['conducted_by'], $d['venue']]))) ?></div><?php endif; ?>
          </td>
          <td><?= h(document_type_label($d['document_type'], $d['document_subtype'])) ?></td>
          <td class="text-nowrap">
            <?= h($d['date_start'] ? document_date_range_label($d['date_start'], $d['date_end']) : date('M j, Y', strtotime($d['doc_date']))) ?>
            <?php if (!$d['date_start'] && !$d['date_issued']): ?><div class="ui-cell-sub">upload date</div><?php endif; ?>
          </td>
          <td class="text-nowrap">
            <?= $d['archived_at'] ? h(date('M j, Y', strtotime($d['archived_at']))) : '—' ?>
            <div class="ui-cell-sub">last opened <?= h(date('M j, Y', strtotime($d['last_viewed_at'] ?? $d['filed_at']))) ?></div>
          </td>
          <td>
            <?php if (!$d['archived_by_name'] && !empty($owner['purged_at'])): ?>
              <span class="badge bg-secondary">Former faculty</span>
            <?php elseif ($d['archived_by_name']): ?>
              <span class="badge bg-info">Archived by <?= (int)$d['archived_by'] === (int)$d['faculty_id'] ? 'owner' : 'Admin' ?></span>
            <?php else: ?>
              <span class="badge bg-secondary">Auto-archived</span>
              <div class="ui-cell-sub">Not opened in <?= h(archive_period_label()) ?></div>
            <?php endif; ?>
            <?php if ($d['archive_reason'] !== null && $d['archive_reason'] !== ''): ?><div class="ui-cell-sub text-break"><?= h($d['archive_reason']) ?></div><?php endif; ?>
          </td>
          <?php if ($can_open): ?>
          <td>
            <div class="ui-actions">
              <?= admin_icon_link(document_url((int)$d['document_id']), 'fa-eye', 'View file', 'target="_blank" data-app-modal="' . h($view_url) . '"') ?>
              <?= admin_icon_link(document_url((int)$d['document_id']) . '&download=1', 'fa-download', 'Download') ?>
              <?php if ($is_admin): ?><?= document_action_buttons($d, $me) ?><?php endif; ?>
            </div>
          </td>
          <?php endif; ?>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>


<?php include __DIR__ . '/includes/footer.php'; ?>
