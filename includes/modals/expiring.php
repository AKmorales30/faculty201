<?php
/**
 * Expiring / Expired Documents pop-up (Admin; api/modal.php?view=expiring),
 * opened from the dashboard card: every faculty member's documents that
 * have expired or expire within 60 days (expiring_documents()), with the
 * status buckets as filters. Each row opens its document pop-up. The full
 * page with more filters is admin/expiring_documents.php. Expects $pdo.
 */
$buckets = expiration_buckets();
$status = (string)($_GET['status'] ?? '');
if (!isset($buckets[$status])) { $status = ''; }
$all = expiring_documents($pdo);
$counts = expiration_bucket_counts($all);
$rows = $status === '' ? $all : array_values(array_filter($all, fn($d) => expiration_milestone($d['days_left']) === $status));
$tab = fn(string $key): string => BASE_URL . '/api/modal.php?view=expiring' . ($key !== '' ? '&status=' . $key : '');
?>
<div data-modal-title="Expiring / Expired Documents"></div>
<ul class="nav nav-pills flex-wrap gap-1 mb-3 small">
  <li class="nav-item"><a class="nav-link py-1 px-2 <?= $status === '' ? 'active' : '' ?>" href="<?= BASE_URL ?>/admin/expiring_documents.php" data-app-modal="<?= h($tab('')) ?>">All <span class="badge bg-light text-dark ms-1"><?= count($all) ?></span></a></li>
  <?php foreach ($buckets as $key => [$label, $class]): ?>
    <li class="nav-item">
      <a class="nav-link py-1 px-2 <?= $status === $key ? 'active' : '' ?>" href="<?= BASE_URL ?>/admin/expiring_documents.php?status=<?= h($key) ?>" data-app-modal="<?= h($tab($key)) ?>">
        <span class="badge <?= $class ?>"><?= (int)$counts[$key] ?></span> <?= h($label) ?>
      </a>
    </li>
  <?php endforeach; ?>
</ul>
<div class="table-responsive">
  <table class="table table-hover align-middle mb-0">
    <thead class="table-light"><tr><th>Faculty</th><th>Document</th><th>Category</th><th>Expires</th><th>Status</th></tr></thead>
    <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="5" class="text-center text-muted py-4">No documents here.</td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $d): [$badge_label, $badge_class] = expiration_badge($d['days_left']); ?>
        <tr class="row-clickable" tabindex="0" role="button" data-app-modal="<?= h(BASE_URL . '/api/modal.php?view=document&id=' . (int)$d['document_id']) ?>">
          <td><?= h($d['full_name']) ?></td>
          <td class="text-break small"><?= h(document_display_name($d['file_path'])) ?></td>
          <td class="small"><?= h(document_type_label($d['document_type'], $d['document_subtype'])) ?></td>
          <td class="text-nowrap small"><?= h(date('M j, Y', strtotime($d['expiration_date']))) ?></td>
          <td><span class="badge <?= $badge_class ?>"><?= h($badge_label) ?></span></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<div class="mt-3 small"><a href="<?= BASE_URL ?>/admin/expiring_documents.php<?= $status !== '' ? '?status=' . h($status) : '' ?>"><i class="fa-solid fa-sliders"></i> Open the full list with more filters</a></div>
