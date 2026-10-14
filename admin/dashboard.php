<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_role('admin');

$page_title = 'Admin Dashboard';

// Anything left waiting under the old approval workflow gets filed now.
file_outstanding_requests($pdo);
// Auto-archive of unopened documents, then expiration alerts -- each at most once a
// day system-wide (before the header, so the bell counts them)
run_daily_jobs($pdo);
$expiring = expiring_documents($pdo);
$card_admin = true;

$total_faculty = $pdo->query("SELECT COUNT(*) FROM users WHERE role='faculty' AND is_active=1")->fetchColumn();
$full_time     = $pdo->query("SELECT COUNT(*) FROM users WHERE role='faculty' AND employment_type='full_time' AND is_active=1")->fetchColumn();
$part_time     = $pdo->query("SELECT COUNT(*) FROM users WHERE role='faculty' AND employment_type='part_time' AND is_active=1")->fetchColumn();
$month_count   = $pdo->query("SELECT COUNT(*) FROM documents WHERE status='active' AND filed_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')")->fetchColumn();
$filed_count   = $pdo->query("SELECT COUNT(*) FROM documents WHERE status='active'")->fetchColumn();
$review_count  = documents_for_review_count($pdo);
$alert_count   = unresolved_security_alert_count($pdo);

$recent = $pdo->query(
    "SELECT sr.request_id, sr.document_type_hint, sr.status, sr.submitted_at, u.full_name,
            d.document_id, d.document_type, d.document_subtype, d.title, d.file_path, d.status AS document_status
     FROM submission_requests sr
     JOIN users u ON u.user_id = sr.faculty_id
     LEFT JOIN documents d ON d.request_id = sr.request_id
     ORDER BY sr.submitted_at DESC LIMIT 8"
)->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
  <h3 class="fw-bold mb-0">Welcome, <?= h(current_user()['full_name']) ?></h3>
  <div class="d-flex flex-wrap gap-2">
    <a href="<?= BASE_URL ?>/admin/manage_documents.php" class="btn btn-outline-brand"><i class="fa-solid fa-list-check"></i> Manage Document<?= $review_count ? ' (' . (int)$review_count . ')' : '' ?></a>
    <a href="<?= BASE_URL ?>/admin/reports.php" class="btn btn-brand"><i class="fa-solid fa-file-lines"></i> Generate Report</a>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-md-3 col-6">
    <div class="card stat-card p-3 h-100">
      <div class="text-muted small">Total Faculty</div>
      <div class="stat-number text-brand"><?= (int)$total_faculty ?></div>
      <div class="small text-muted"><?= (int)$full_time ?> full-time · <?= (int)$part_time ?> part-time</div>
    </div>
  </div>
  <div class="col-md-3 col-6">
    <a href="<?= BASE_URL ?>/admin/manage_documents.php" class="text-decoration-none" data-app-modal="<?= h(BASE_URL . '/api/modal.php?view=list&category=for_review') ?>" data-app-modal-title="For Review">
      <div class="card stat-card stat-card-link p-3 h-100">
        <div class="text-muted small">For Review</div>
        <div class="stat-number text-accent-gold"><?= (int)$review_count ?></div>
        <div class="small text-muted">new uploads to check and post</div>
      </div>
    </a>
  </div>
  <div class="col-md-3 col-6">
    <div class="card stat-card p-3 h-100">
      <div class="text-muted small">Posted This Month</div>
      <div class="stat-number text-accent-teal"><?= (int)$month_count ?></div>
      <div class="small text-muted">this month's uploads now in Public Docs</div>
    </div>
  </div>
  <div class="col-md-3 col-6">
    <a href="<?= BASE_URL ?>/admin/public_docs.php" class="text-decoration-none">
      <div class="card stat-card stat-card-link p-3 h-100">
        <div class="text-muted small">Public Docs</div>
        <div class="stat-number text-brand"><?= (int)$filed_count ?></div>
        <div class="small text-muted">posted documents</div>
      </div>
    </a>
  </div>
</div>

<?php if ($alert_count > 0): ?>
<a href="<?= BASE_URL ?>/admin/security_alerts.php" class="alert alert-danger d-flex align-items-center gap-2 mb-4 text-decoration-none">
  <i class="fa-solid fa-shield-halved fa-lg"></i>
  <div><strong><?= (int)$alert_count ?></strong> security alert<?= $alert_count === 1 ? '' : 's' ?> need<?= $alert_count === 1 ? 's' : '' ?> your review.</div>
</a>
<?php endif; ?>

<?php $reminders_title = 'AI Overview'; $reminders_compact = true; include __DIR__ . '/../includes/reminders_card.php'; ?>

<?php include __DIR__ . '/../includes/reminders_summary_card.php'; ?>

<?php include __DIR__ . '/../includes/expiring_documents_card.php'; ?>

<div class="card stat-card">
  <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center flex-wrap gap-2">
    <span><i class="fa-solid fa-clock-rotate-left text-brand"></i> Recent File Uploads</span>
    <button type="button" class="btn btn-sm btn-outline-brand fw-normal" data-app-modal="<?= h(BASE_URL . '/api/modal.php?view=list&category=recent_uploads') ?>" data-app-modal-title="Recent File Uploads">
      <i class="fa-solid fa-list"></i> View all
    </button>
  </div>
  <div class="card-body p-0 table-responsive">
    <table class="table table-hover mb-0 align-middle">
      <thead class="table-light">
        <tr><th>File</th><th>Faculty</th><th>Document Type</th><th>Status</th><th>Uploaded</th></tr>
      </thead>
      <tbody>
        <?php if (!$recent): ?>
          <tr><td colspan="5" class="text-center text-muted py-4">No uploads yet.</td></tr>
        <?php endif; ?>
        <?php foreach ($recent as $r): [$label, $badge] = upload_status_badge($r); ?>
        <tr<?php if ($r['document_id']): ?> class="row-clickable" tabindex="0" role="button" data-app-modal="<?= h(BASE_URL . '/api/modal.php?view=document&id=' . (int)$r['document_id']) ?>"<?php endif; ?>>
          <td class="text-break small fw-semibold"><?= $r['document_id'] ? h(document_title($r)) : '<span class="text-muted">—</span>' ?></td>
          <td><?= h($r['full_name']) ?></td>
          <td><?= h(document_type_label($r['document_type'] ?? $r['document_type_hint'], $r['document_subtype'])) ?></td>
          <td><span class="badge <?= $badge ?>"><?= h($label) ?></span></td>
          <td class="text-nowrap small"><?= date('M j, Y g:ia', strtotime($r['submitted_at'])) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
