<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_role(['faculty', 'program_chair', 'dean']);   // own 201 file only

$page_title = 'Upload History';   // the Upload history tab of 201-Files
$me = current_user();
$categories = document_categories();

$stmt = $pdo->prepare(
    "SELECT sr.*, d.document_id, d.file_path, d.title, d.document_type, d.document_subtype, d.status AS document_status FROM submission_requests sr
     LEFT JOIN documents d ON d.request_id = sr.request_id
     WHERE sr.faculty_id=? ORDER BY sr.submitted_at DESC"
);
$stmt->execute([$me['user_id']]);
$requests = $stmt->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<?= admin_intro('Every document you\'ve uploaded, newest first, with where it is now: Posted, Archived or Deleted. Uploads are posted to your 201-Files right away.',
    '<a href="' . BASE_URL . '/faculty/submit_document.php" class="btn btn-brand"><i class="fa-solid fa-file-circle-plus" aria-hidden="true"></i> Upload Document</a>') ?>
<?= my_docs_tabs($pdo, 'history') ?>

<section class="ui-card" aria-label="Upload history">
  <div class="table-responsive">
    <table class="table align-middle">
      <thead><tr><th>Document</th><th>Type</th><th>Status</th><th>Uploaded</th><th class="text-end"><span class="visually-hidden">Actions</span></th></tr></thead>
      <tbody>
        <?php if (!$requests): ?>
          <?= admin_empty_row(5, 'No uploads yet', 'Documents you upload will be listed here.', null, 'fa-clock-rotate-left') ?>
        <?php endif; ?>
        <?php foreach ($requests as $r): [$label, $badge] = upload_status_badge($r);
          $viewable = $r['file_path'] && in_array($r['document_status'], ['active', 'for_review', 'archived'], true);
          $view_url = $viewable ? BASE_URL . '/api/modal.php?view=document&id=' . (int)$r['document_id'] : null; ?>
        <tr<?php if ($view_url): ?> class="row-clickable" tabindex="0" role="button" data-app-modal="<?= h($view_url) ?>" aria-label="Open <?= h(document_title($r)) ?>"<?php endif; ?>>
          <td class="text-break fw-semibold"><?= $r['document_id'] ? h(document_title($r)) : '<span class="text-muted">—</span>' ?></td>
          <td><?= h(document_type_label($r['document_type'] ?? $r['document_type_hint'], $r['document_subtype'])) ?></td>
          <td>
            <span class="badge <?= $badge ?>"><?= h($label) ?></span>
            <?php if ($r['status'] === 'rejected'): ?><div class="ui-cell-sub text-danger"><?= h($r['rejection_reason']) ?></div><?php endif; ?>
          </td>
          <td class="text-nowrap"><?= h(date('M j, Y', strtotime($r['submitted_at']))) ?><div class="ui-cell-sub"><?= h(date('g:i A', strtotime($r['submitted_at']))) ?></div></td>
          <td>
            <div class="ui-actions">
              <?php if ($view_url): ?>
                <?= admin_icon_link(document_url((int)$r['document_id']), 'fa-eye', 'View file', 'target="_blank" data-app-modal="' . h($view_url) . '"') ?>
              <?php elseif ($r['document_status'] === 'deleted'): ?>
                <?= admin_icon_link(BASE_URL . '/recently_deleted.php', 'fa-trash-can-arrow-up', 'In Recently Deleted') ?>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>


<?php include __DIR__ . '/../includes/footer.php'; ?>
