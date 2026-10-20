<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_role('admin');

// Every faculty member's documents that are expired or expire within 60
// days, soonest first -- the Expiring documents tab of Report > Document
// Status (the dashboard card's "View all" opens the same list in a pop-up). Replaced versions and deactivated accounts are
// left out -- see expiring_documents().

$page_title = 'Expiring Documents';
$categories = document_categories();
$buckets = expiration_buckets();

// Filters (all optional). Anything invalid is ignored rather than erroring.
$status     = $_GET['status'] ?? '';
$category   = $_GET['category'] ?? '';
$faculty_id = (int)($_GET['faculty'] ?? 0);
if (!array_key_exists($status, $buckets)) { $status = ''; }
if (!array_key_exists($category, $categories)) { $category = ''; }

$all = expiring_documents($pdo);
$counts = expiration_bucket_counts($all);

// Faculty who have something in the list, for the filter
$faculty_names = array_column($all, 'full_name', 'faculty_id');
asort($faculty_names);
if (!isset($faculty_names[$faculty_id])) { $faculty_id = 0; }

$documents = array_values(array_filter($all, function ($d) use ($status, $category, $faculty_id) {
    return ($status === '' || expiration_milestone($d['days_left']) === $status)
        && ($category === '' || $d['document_type'] === $category)
        && (!$faculty_id || (int)$d['faculty_id'] === $faculty_id);
}));

include __DIR__ . '/../includes/header.php';
?>

<?= admin_intro('Faculty documents that have expired or will expire within 60 days, soonest first. The owner and every Admin are notified 60, 30 and 7 days before and on the expiration date.') ?>
<?= report_tabs('expiring') ?>

<?php $bucket_dots = ['expired' => 'var(--ad-red)', '7' => 'var(--ad-orange)', '30' => 'var(--ad-gold)', '60' => 'var(--ad-gray)']; ?>
<div class="ui-tiles">
  <?php foreach ($buckets as $key => [$label, $class]): ?>
    <a class="ui-tile ui-tile-stack" href="expiring_documents.php?status=<?= h($key) ?>" style="--dot: <?= $bucket_dots[$key] ?? 'var(--ad-gray)' ?><?= $status === $key ? ';border-color:var(--ad-navy)' : '' ?>"<?= $status === $key ? ' aria-current="true"' : '' ?>>
      <span class="ui-tile-dot"><?= h($label) ?></span>
      <span class="ui-tile-n" data-count="<?= (int)$counts[$key] ?>"><?= (int)$counts[$key] ?></span>
    </a>
  <?php endforeach; ?>
</div>

<?php $filtered = $status !== '' || $category !== '' || $faculty_id; ?>
<section class="ui-card" aria-label="Expiring and expired documents">
  <form method="GET" class="ui-toolbar" data-autosubmit>
    <label for="fStatus" class="visually-hidden">Status</label>
    <select name="status" id="fStatus" class="form-select">
      <option value="">All (expired and within 60 days)</option>
      <?php foreach ($buckets as $key => [$label]): ?>
        <option value="<?= h($key) ?>" <?= $status === $key ? 'selected' : '' ?>><?= h($label) ?></option>
      <?php endforeach; ?>
    </select>
    <label for="fCategory" class="visually-hidden">Category</label>
    <select name="category" id="fCategory" class="form-select">
      <option value="">All categories</option>
      <?php foreach ($categories as $key => $meta): ?>
        <option value="<?= h($key) ?>" <?= $category === $key ? 'selected' : '' ?>><?= h($meta['label']) ?></option>
      <?php endforeach; ?>
    </select>
    <label for="fFaculty" class="visually-hidden">Faculty member</label>
    <select name="faculty" id="fFaculty" class="form-select">
      <option value="">All faculty</option>
      <?php foreach ($faculty_names as $id => $name): ?>
        <option value="<?= (int)$id ?>" <?= $faculty_id === (int)$id ? 'selected' : '' ?>><?= h($name) ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-sm btn-brand ui-apply" data-auto><i class="fa-solid fa-filter" aria-hidden="true"></i> Filter</button>
    <?php if ($filtered): ?><a href="expiring_documents.php" class="btn btn-sm btn-outline-secondary">Clear filters</a><?php endif; ?>
    <span class="ui-count"><?= count($documents) ?> document<?= count($documents) === 1 ? '' : 's' ?></span>
  </form>
  <div class="table-responsive">
    <table class="table align-middle">
      <thead>
        <tr><th>Faculty</th><th>Document</th><th>Category</th><th>Expires</th><th>Status</th><th class="text-end"><span class="visually-hidden">Actions</span></th></tr>
      </thead>
      <tbody>
        <?php if (!$documents): ?>
          <?= admin_empty_row(6, $filtered ? 'No documents match' : 'Nothing is expiring', $filtered ? 'Try different filters, or clear them to see everything.' : 'No documents are expired or expiring within 60 days.', $filtered ? 'expiring_documents.php' : null, 'fa-hourglass-half') ?>
        <?php endif; ?>
        <?php foreach ($documents as $d): [$badge_label, $badge_class] = expiration_badge($d['days_left']); $view_url = BASE_URL . '/api/modal.php?view=document&id=' . (int)$d['document_id']; ?>
        <tr class="row-clickable" tabindex="0" role="button" data-app-modal="<?= h($view_url) ?>" aria-label="Open <?= h(document_display_name($d['file_path'])) ?>">
          <td><a href="<?= BASE_URL ?>/admin/faculty_documents.php?id=<?= (int)$d['faculty_id'] ?>" class="text-body fw-semibold"><?= h($d['full_name']) ?></a></td>
          <td class="text-break"><?= h(document_display_name($d['file_path'])) ?></td>
          <td><?= h(document_type_label($d['document_type'], $d['document_subtype'])) ?></td>
          <td class="text-nowrap"><?= h(date('M j, Y', strtotime($d['expiration_date']))) ?></td>
          <td><span class="badge <?= $badge_class ?>"><?= h($badge_label) ?></span></td>
          <td><div class="ui-actions"><?= admin_icon_link(document_url((int)$d['document_id']), 'fa-eye', 'View details', 'target="_blank" data-app-modal="' . h($view_url) . '"') ?></div></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<?php include __DIR__ . '/../includes/footer.php'; ?>
