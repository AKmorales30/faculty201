<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_role('faculty');

$page_title = 'My Dashboard';
$me = current_user();

// Anything left waiting under the old approval workflow gets filed now.
file_outstanding_requests($pdo);

$stmt = $pdo->prepare("SELECT COUNT(*) FROM documents WHERE faculty_id=? AND filed_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')");
$stmt->execute([$me['user_id']]);
$this_month = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM documents WHERE faculty_id=?");
$stmt->execute([$me['user_id']]);
$filed = $stmt->fetchColumn();

$stmt = $pdo->prepare(
    "SELECT sr.*, d.document_subtype FROM submission_requests sr
     LEFT JOIN documents d ON d.request_id = sr.request_id
     WHERE sr.faculty_id=? ORDER BY sr.submitted_at DESC LIMIT 5"
);
$stmt->execute([$me['user_id']]);
$recent = $stmt->fetchAll();

$checklist = faculty_201_checklist($pdo, $me['user_id'], faculty_employment_type($pdo, $me['user_id']));
$missing = count(array_filter($checklist, fn($i) => !$i['done']));

include __DIR__ . '/../includes/header.php';
?>
<h3 class="fw-bold mb-4">Welcome, <?= h($me['full_name']) ?></h3>

<div class="row g-3 mb-4">
  <div class="col-6 col-md-4">
    <div class="card stat-card p-3 h-100">
      <div class="text-muted small">Uploaded This Month</div>
      <div class="stat-number text-accent-gold"><?= (int)$this_month ?></div>
    </div>
  </div>
  <div class="col-6 col-md-4">
    <div class="card stat-card p-3 h-100">
      <div class="text-muted small">Documents in My 201 File</div>
      <div class="stat-number text-accent-teal"><?= (int)$filed ?></div>
    </div>
  </div>
  <div class="col-12 col-md-4">
    <div class="card stat-card p-3 h-100 d-flex flex-column justify-content-center gap-2">
      <a href="<?= BASE_URL ?>/faculty/submit_document.php" class="btn btn-brand">
        <i class="fa-solid fa-file-arrow-up"></i> Upload a New Document
      </a>
      <a href="<?= BASE_URL ?>/faculty/pds.php" class="btn btn-outline-brand">
        <i class="fa-solid fa-id-card"></i> Edit My PDS
      </a>
    </div>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-5">
    <div class="card stat-card h-100">
      <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
        201 File Checklist
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
      <div class="card-header bg-white fw-semibold">My Recent Uploads</div>
      <div class="card-body p-0 table-responsive">
        <table class="table mb-0 align-middle">
          <thead class="table-light"><tr><th>Document Type</th><th>Status</th><th>Uploaded</th></tr></thead>
          <tbody>
            <?php if (!$recent): ?>
              <tr><td colspan="3" class="text-center text-muted py-4">You haven't uploaded any documents yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($recent as $r): [$label,$badge] = status_badge($r['status']); ?>
            <tr>
              <td><?= h(document_type_label($r['document_type_hint'], $r['document_subtype'])) ?></td>
              <td><span class="badge <?= $badge ?>"><?= h($label) ?></span></td>
              <td><?= date('M j, Y g:ia', strtotime($r['submitted_at'])) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
