<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_role('faculty');

$page_title = 'My Dashboard';
$me = current_user();
$uid = (int)$me['user_id'];

// Anything left waiting under the old approval workflow gets filed now.
file_outstanding_requests($pdo);
// Auto-archive of unopened documents, then expiration alerts -- each at most once a
// day system-wide (before the header, so the bell counts them)
run_daily_jobs($pdo);
$expiring = expiring_documents($pdo, $uid);
$card_admin = false;

// Same cards as the Admin dashboard, for their own 201 file
$stmt = $pdo->prepare("SELECT status, COUNT(*) FROM documents WHERE faculty_id = ? GROUP BY status");
$stmt->execute([$uid]);
$by_status = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
$stmt = $pdo->prepare("SELECT COUNT(*) FROM documents WHERE faculty_id = ? AND status IN ('active', 'for_review') AND filed_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')");
$stmt->execute([$uid]);
$this_month = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare(
    "SELECT sr.request_id, sr.document_type_hint, sr.status, sr.submitted_at,
            d.document_id, d.document_type, d.document_subtype, d.title, d.file_path, d.status AS document_status
     FROM submission_requests sr
     LEFT JOIN documents d ON d.request_id = sr.request_id
     WHERE sr.faculty_id = ? ORDER BY sr.submitted_at DESC LIMIT 6"
);
$stmt->execute([$uid]);
$recent = $stmt->fetchAll();

$checklist = faculty_201_checklist($pdo, $uid, faculty_employment_type($pdo, $uid));
$missing = count(array_filter($checklist, fn($i) => !$i['done']));

include __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
  <h3 class="fw-bold mb-0">Welcome, <?= h($me['full_name']) ?></h3>
  <div class="d-flex flex-wrap gap-2">
    <a href="<?= BASE_URL ?>/faculty/pds.php" class="btn btn-outline-brand"><i class="fa-solid fa-id-card"></i> My PDS</a>
    <a href="<?= BASE_URL ?>/faculty/submit_document.php" class="btn btn-brand"><i class="fa-solid fa-file-circle-plus"></i> Upload Document</a>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-md-3 col-6">
    <a href="<?= BASE_URL ?>/faculty/my_documents.php" class="text-decoration-none">
      <div class="card stat-card stat-card-link p-3 h-100">
        <div class="text-muted small">Public Docs</div>
        <div class="stat-number text-brand"><?= (int)($by_status['active'] ?? 0) ?></div>
        <div class="small text-muted">posted to your 201 file</div>
      </div>
    </a>
  </div>
  <div class="col-md-3 col-6">
    <a href="<?= BASE_URL ?>/faculty/my_documents.php?tab=pending" class="text-decoration-none" data-app-modal="<?= h(BASE_URL . '/api/modal.php?view=list&category=for_review') ?>" data-app-modal-title="For Review">
      <div class="card stat-card stat-card-link p-3 h-100">
        <div class="text-muted small">For Review</div>
        <div class="stat-number text-accent-gold"><?= (int)($by_status['for_review'] ?? 0) ?></div>
        <div class="small text-muted">waiting for the Admin to post</div>
      </div>
    </a>
  </div>
  <div class="col-md-3 col-6">
    <div class="card stat-card p-3 h-100">
      <div class="text-muted small">Uploaded This Month</div>
      <div class="stat-number text-accent-teal"><?= $this_month ?></div>
      <div class="small text-muted">posted or waiting for review</div>
    </div>
  </div>
  <div class="col-md-3 col-6">
    <a href="<?= BASE_URL ?>/archive.php" class="text-decoration-none">
      <div class="card stat-card stat-card-link p-3 h-100">
        <div class="text-muted small">Archived Docs</div>
        <div class="stat-number text-secondary"><?= (int)($by_status['archived'] ?? 0) ?></div>
        <div class="small text-muted">not opened in <?= h(archive_period_label()) ?></div>
      </div>
    </a>
  </div>
</div>

<?php include __DIR__ . '/../includes/reminders_card.php'; ?>

<?php include __DIR__ . '/../includes/expiring_documents_card.php'; ?>

<div class="row g-3">
  <div class="col-lg-5">
    <div class="card stat-card h-100">
      <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
        <span><i class="fa-solid fa-list-check text-brand"></i> 201 File Checklist</span>
        <?php if ($missing): ?>
          <span class="badge bg-warning"><?= $missing ?> to do</span>
        <?php else: ?>
          <span class="badge bg-success">Complete</span>
        <?php endif; ?>
      </div>
      <ul class="list-group list-group-flush">
        <?php foreach ($checklist as $item): ?>
          <li class="list-group-item d-flex justify-content-between align-items-center gap-2 small">
            <span>
              <i class="fa-solid <?= $item['done'] ? 'fa-circle-check text-success' : 'fa-circle-exclamation text-warning' ?> me-1"></i>
              <?= h($item['label']) ?>
            </span>
            <?php if (!$item['done']): ?>
              <a href="<?= BASE_URL ?>/faculty/<?= $item['type'] === 'PDS' ? 'pds.php' : 'submit_document.php' ?>" class="text-nowrap"><?= $item['type'] === 'PDS' ? 'Update' : 'Upload' ?></a>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>

  <div class="col-lg-7">
    <div class="card stat-card h-100">
      <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span><i class="fa-solid fa-clock-rotate-left text-brand"></i> My Recent Uploads</span>
        <a href="<?= BASE_URL ?>/faculty/my_requests.php" class="btn btn-sm btn-outline-brand fw-normal"
           data-app-modal="<?= h(BASE_URL . '/api/modal.php?view=list&category=recent_uploads') ?>" data-app-modal-title="Recent File Uploads"><i class="fa-solid fa-list"></i> View all</a>
      </div>
      <div class="card-body p-0 table-responsive">
        <table class="table table-hover mb-0 align-middle">
          <thead class="table-light"><tr><th>File</th><th>Document Type</th><th>Status</th><th>Uploaded</th></tr></thead>
          <tbody>
            <?php if (!$recent): ?>
              <tr><td colspan="4" class="text-center text-muted py-4">You haven't uploaded any documents yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($recent as $r): [$label, $badge] = upload_status_badge($r);
              $openable = $r['document_id'] && in_array($r['document_status'], ['active', 'for_review', 'archived'], true); ?>
            <tr<?php if ($openable): ?> class="row-clickable" tabindex="0" role="button" data-app-modal="<?= h(BASE_URL . '/api/modal.php?view=document&id=' . (int)$r['document_id']) ?>"<?php endif; ?>>
              <td class="text-break small fw-semibold"><?= $r['document_id'] ? h(document_title($r)) : '<span class="text-muted">—</span>' ?></td>
              <td class="small"><?= h(document_type_label($r['document_type'] ?? $r['document_type_hint'], $r['document_subtype'])) ?></td>
              <td><span class="badge <?= $badge ?>"><?= h($label) ?></span></td>
              <td class="small text-nowrap"><?= date('M j, Y g:ia', strtotime($r['submitted_at'])) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
