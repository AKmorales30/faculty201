<?php
/**
 * "Expiring / Expired Documents" dashboard card (Objective 3a, Fig. 2).
 *
 * Expects: $expiring  rows from expiring_documents()
 *          $card_admin true on the Admin dashboard: all faculty, with a
 *                      Faculty column, summary counts, the first rows only
 *                      and a "View all" button that opens the whole list in
 *                      the pop-up (api/modal.php?view=expiring)
 * Each document's eye button opens its preview pop-up.
 *
 * Colors: red = expired, orange = within 7 days, yellow = within 30 days,
 * neutral = within 60 days (expiration_badge()).
 */
$card_limit = 8;
$card_rows = $card_admin ? array_slice($expiring, 0, $card_limit) : $expiring;
?>
<div class="card stat-card mb-4">
  <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center flex-wrap gap-2">
    <span><i class="fa-solid fa-hourglass-half text-brand"></i> Expiring / Expired Documents</span>
    <?php if ($card_admin): ?>
      <button type="button" class="btn btn-sm btn-outline-brand fw-normal" data-app-modal="<?= h(BASE_URL . '/api/modal.php?view=expiring') ?>" data-app-modal-title="Expiring / Expired Documents">
        <i class="fa-solid fa-list"></i> View all<?= $expiring ? ' (' . count($expiring) . ')' : '' ?>
      </button>
    <?php elseif ($expiring): ?>
      <span class="badge bg-danger"><?= count($expiring) ?></span>
    <?php endif; ?>
  </div>

  <?php if ($card_admin):
    $buckets = expiration_bucket_counts($expiring); ?>
  <div class="card-body border-bottom py-2">
    <div class="d-flex flex-wrap gap-2 small">
      <?php foreach (expiration_buckets() as $key => [$label, $class]): ?>
        <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none" data-app-modal="<?= h(BASE_URL . '/api/modal.php?view=expiring&status=' . $key) ?>" data-app-modal-title="Expiring / Expired Documents">
          <span class="badge <?= $class ?>"><?= (int)$buckets[$key] ?></span> <span class="text-body small"><?= h($label) ?></span>
        </button>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <div class="card-body p-0 table-responsive">
    <table class="table mb-0 align-middle">
      <thead class="table-light">
        <tr>
          <?php if ($card_admin): ?><th>Faculty</th><?php endif; ?>
          <th>Document</th><th>Category</th><th>Expires</th><th>Status</th><th></th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$card_rows): ?>
          <tr><td colspan="<?= $card_admin ? 6 : 5 ?>" class="text-center text-muted py-4">No documents are expired or expiring within 60 days.</td></tr>
        <?php endif; ?>
        <?php foreach ($card_rows as $d): [$badge_label, $badge_class] = expiration_badge($d['days_left']); ?>
        <tr>
          <?php if ($card_admin): ?><td><?= h($d['full_name']) ?></td><?php endif; ?>
          <td class="text-break"><?= h(document_display_name($d['file_path'])) ?></td>
          <td><?= h(document_type_label($d['document_type'], $d['document_subtype'])) ?></td>
          <td class="text-nowrap"><?= h(date('M j, Y', strtotime($d['expiration_date']))) ?></td>
          <td><span class="badge <?= $badge_class ?>"><?= h($badge_label) ?></span></td>
          <td class="text-nowrap text-end">
            <a href="<?= h(document_url((int)$d['document_id'])) ?>" target="_blank" class="btn btn-sm btn-outline-brand" data-tooltip title="View document" aria-label="View document"
               data-app-modal="<?= h(BASE_URL . '/api/modal.php?view=document&id=' . (int)$d['document_id']) ?>"><i class="fa-solid fa-eye"></i></a>
            <?php if (!$card_admin): ?>
              <a href="<?= BASE_URL ?>/faculty/submit_document.php" class="btn btn-sm btn-brand" title="Upload an updated copy"><i class="fa-solid fa-file-arrow-up"></i> Re-upload</a>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
