<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_role('admin');

// Every faculty member's documents that are expired or expire within 60
// days (the "View all" of the dashboard's Expiring / Expired Documents
// card), soonest first. Replaced versions and deactivated accounts are
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

<a href="<?= BASE_URL ?>/admin/dashboard.php" class="small text-muted d-inline-block mb-3">
  <i class="fa-solid fa-arrow-left"></i> Back to Dashboard
</a>

<h3 class="fw-bold mb-1">Expiring Documents</h3>
<p class="text-muted mb-4">Faculty documents that have expired or will expire within 60 days, soonest first. The owner and every Admin are notified 60, 30 and 7 days before and on the expiration date.</p>

<div class="row g-3 mb-4">
  <?php foreach ($buckets as $key => [$label, $class]): ?>
  <div class="col-6 col-md-3">
    <a href="expiring_documents.php?status=<?= h($key) ?>" class="text-decoration-none">
      <div class="card stat-card p-3 h-100 <?= $status === $key ? 'border-2 border-primary' : '' ?>">
        <div class="text-muted small"><span class="badge <?= $class ?>">&nbsp;</span> <?= h($label) ?></div>
        <div class="stat-number"><?= (int)$counts[$key] ?></div>
      </div>
    </a>
  </div>
  <?php endforeach; ?>
</div>

<div class="card stat-card mb-4">
  <div class="card-body">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-md-4 col-xl-3">
        <label for="fStatus" class="form-label small fw-semibold">Status</label>
        <select name="status" id="fStatus" class="form-select">
          <option value="">All (expired and within 60 days)</option>
          <?php foreach ($buckets as $key => [$label]): ?>
            <option value="<?= h($key) ?>" <?= $status === $key ? 'selected' : '' ?>><?= h($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4 col-xl-3">
        <label for="fCategory" class="form-label small fw-semibold">Category</label>
        <select name="category" id="fCategory" class="form-select">
          <option value="">All Categories</option>
          <?php foreach ($categories as $key => $meta): ?>
            <option value="<?= h($key) ?>" <?= $category === $key ? 'selected' : '' ?>><?= h($meta['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4 col-xl-3">
        <label for="fFaculty" class="form-label small fw-semibold">Faculty Member</label>
        <select name="faculty" id="fFaculty" class="form-select">
          <option value="">All Faculty</option>
          <?php foreach ($faculty_names as $id => $name): ?>
            <option value="<?= (int)$id ?>" <?= $faculty_id === (int)$id ? 'selected' : '' ?>><?= h($name) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-6 col-xl-3 d-flex gap-2">
        <button class="btn btn-brand flex-fill"><i class="fa-solid fa-filter"></i> Filter</button>
        <a href="expiring_documents.php" class="btn btn-outline-secondary flex-fill">Reset</a>
      </div>
    </form>
  </div>
</div>

<div class="small text-muted mb-2"><?= count($documents) ?> document<?= count($documents) === 1 ? '' : 's' ?></div>

<div class="card stat-card">
  <div class="card-body p-0 table-responsive">
    <table class="table mb-0 align-middle">
      <thead class="table-light">
        <tr><th>Faculty</th><th>Document</th><th>Category</th><th>Expires</th><th>Status</th><th></th></tr>
      </thead>
      <tbody>
        <?php if (!$documents): ?>
          <tr><td colspan="6" class="text-center text-muted py-4">No documents match these filters.</td></tr>
        <?php endif; ?>
        <?php foreach ($documents as $d): [$badge_label, $badge_class] = expiration_badge($d['days_left']); ?>
        <tr>
          <td><a href="<?= BASE_URL ?>/admin/faculty_documents.php?id=<?= (int)$d['faculty_id'] ?>"><?= h($d['full_name']) ?></a></td>
          <td class="text-break"><?= h(document_display_name($d['file_path'])) ?></td>
          <td><?= h(document_type_label($d['document_type'], $d['document_subtype'])) ?></td>
          <td class="text-nowrap"><?= h(date('M j, Y', strtotime($d['expiration_date']))) ?></td>
          <td><span class="badge <?= $badge_class ?>"><?= h($badge_label) ?></span></td>
          <td class="text-end"><a href="<?= h(document_url((int)$d['document_id'])) ?>" target="_blank" class="btn btn-sm btn-outline-brand" data-tooltip title="View document" aria-label="View document"><i class="fa-solid fa-eye"></i></a></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
