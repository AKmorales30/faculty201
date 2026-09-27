<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_role(['program_chair', 'dean']);

$page_title = 'Recent Uploads';
$me = current_user();
$categories = document_categories();

// Anything left waiting under the old approval workflow gets filed now.
file_outstanding_requests($pdo);

$active_type = $_GET['type'] ?? '';
if (!array_key_exists($active_type, $categories)) { $active_type = ''; }

$sql = "SELECT d.document_id, d.request_id, d.document_type, d.document_subtype, d.academic_year, d.semester, d.period_year,
               d.file_path, d.filed_at, u.full_name, u.employment_type
        FROM documents d
        JOIN users u ON u.user_id = d.faculty_id";
$params = [];
if ($active_type !== '') { $sql .= " WHERE d.document_type = ?"; $params[] = $active_type; }
$sql .= " ORDER BY d.filed_at DESC LIMIT 50";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$uploads = $stmt->fetchAll();

$this_week   = (int)$pdo->query("SELECT COUNT(*) FROM documents WHERE filed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();
$filed_total = (int)$pdo->query("SELECT COUNT(*) FROM documents")->fetchColumn();
$unread      = unread_notification_count($pdo, $me['user_id']);

include __DIR__ . '/../includes/header.php';
?>
<h3 class="fw-bold mb-4">Welcome, <?= h($me['full_name']) ?></h3>

<div class="row g-3 mb-4">
  <div class="col-md-4 col-6">
    <div class="card stat-card p-3 h-100">
      <div class="text-muted small">Uploads in the Last 7 Days</div>
      <div class="stat-number text-accent-gold"><?= $this_week ?></div>
    </div>
  </div>
  <div class="col-md-4 col-6">
    <div class="card stat-card p-3 h-100">
      <div class="text-muted small">Documents Filed System-Wide</div>
      <div class="stat-number text-accent-teal"><?= $filed_total ?></div>
    </div>
  </div>
  <div class="col-md-4 col-12">
    <a href="<?= BASE_URL ?>/approval/notifications.php" class="text-decoration-none">
      <div class="card stat-card p-3 h-100">
        <div class="text-muted small">Unread Notifications</div>
        <div class="stat-number text-brand"><?= $unread ?></div>
      </div>
    </a>
  </div>
</div>

<h5 class="fw-bold mb-2">Recent Faculty Uploads</h5>
<p class="text-muted">Faculty uploads are added to their 201 file automatically -- no confirmation is needed. You're notified of each new upload.</p>

<div class="card stat-card">
  <div class="card-header bg-white d-flex justify-content-between align-items-center">
    <span class="fw-semibold"><?= $active_type ? h($categories[$active_type]['label']) : 'All Document Types' ?></span>
    <form method="GET">
      <select name="type" class="form-select form-select-sm" onchange="this.form.submit()" aria-label="Filter by document type">
        <option value="">All document types</option>
        <?php foreach ($categories as $key => $meta): ?>
          <option value="<?= h($key) ?>" <?= $active_type === $key ? 'selected' : '' ?>><?= h($meta['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </form>
  </div>
  <div class="card-body p-0 table-responsive">
    <table class="table mb-0 align-middle">
      <thead class="table-light">
        <tr><th>Faculty</th><th>Document Type</th><th>Employment</th><th>Date Uploaded</th><th></th></tr>
      </thead>
      <tbody>
        <?php if (!$uploads): ?>
          <tr><td colspan="5" class="text-center text-muted py-4">No documents have been uploaded yet.</td></tr>
        <?php endif; ?>
        <?php foreach ($uploads as $u): ?>
        <tr>
          <td><?= h($u['full_name']) ?></td>
          <td>
            <?= h(document_type_label($u['document_type'], $u['document_subtype'])) ?>
            <?php if ($p = document_period_label($u)): ?><div class="small text-muted"><?= h($p) ?></div><?php endif; ?>
          </td>
          <td class="text-capitalize"><?= h(str_replace('_',' ',$u['employment_type'] ?? '')) ?></td>
          <td><?= date('M j, Y g:ia', strtotime($u['filed_at'])) ?></td>
          <td class="text-nowrap"><a href="request_detail.php?id=<?= (int)$u['request_id'] ?>" class="btn btn-sm btn-outline-brand"><i class="fa-solid fa-eye"></i> View</a></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
