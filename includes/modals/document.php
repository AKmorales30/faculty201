<?php
/**
 * Document pop-up (api/modal.php?view=document): the file and its details
 * -- faculty, document type, upload date, status. Access already checked
 * (can_access_document()). Expects $doc (modal_document()) and $me.
 */
$is_admin = $me['role'] === 'admin';
$is_owner = (int)$doc['faculty_id'] === (int)$me['user_id'];
[$status_label, $status_badge] = document_status_badge($doc['status']);
$period = document_period_label($doc);
$fmt = fn(?string $d, string $f = 'M j, Y'): string => $d ? date($f, strtotime($d)) : '—';
$return = $is_admin ? 'admin/public_docs.php' : 'faculty/my_documents.php' . ($doc['status'] === 'for_review' ? '?tab=pending' : '');
?>
<div data-modal-title="<?= h(document_title($doc)) ?>"></div>
<div class="row g-3">
  <div class="col-lg-7">
    <?php include __DIR__ . '/preview.php'; ?>
  </div>
  <div class="col-lg-5">
    <dl class="profile-dl doc-details-dl mb-3">
      <dt>Faculty</dt>
      <dd>
        <?php if ($is_admin && in_array($doc['owner_role'], ['faculty', 'program_chair', 'dean'], true)): ?>
          <a href="<?= BASE_URL ?>/admin/faculty_documents.php?id=<?= (int)$doc['faculty_id'] ?>"><?= h($doc['full_name']) ?></a>
        <?php else: ?><?= h($doc['full_name']) ?><?php endif; ?>
      </dd>
      <dt>Document type</dt><dd><?= h(document_type_label($doc['document_type'], $doc['document_subtype'])) ?></dd>
      <?php if ($period !== ''): ?><dt>Period</dt><dd><?= h($period) ?></dd><?php endif; ?>
      <?php if (trim((string)$doc['title']) !== ''): ?><dt>Title</dt><dd><?= h($doc['title']) ?></dd><?php endif; ?>
      <?php if ($doc['document_type'] === 'Certificate' && ($doc['date_start'] || $doc['conducted_by'] || $doc['venue'])): ?>
        <dt>Seminar / training</dt>
        <dd><?= h(implode(' · ', array_filter([$doc['date_start'] ? document_date_range_label($doc['date_start'], $doc['date_end']) : null,
                                                $doc['conducted_by'], $doc['venue'], training_type_label($doc),
                                                $doc['hours'] !== null ? (float)$doc['hours'] . ' hours' : null]))) ?></dd>
      <?php endif; ?>
      <?php if ($doc['date_issued']): ?><dt>Date issued</dt><dd><?= h($fmt($doc['date_issued'])) ?></dd><?php endif; ?>
      <dt>Upload date</dt><dd><?= h($fmt($doc['filed_at'], 'M j, Y, g:i A')) ?></dd>
      <dt>Status</dt>
      <dd>
        <span class="badge <?= $status_badge ?>"><?= h($status_label) ?></span>
        <?php if ($doc['status'] === 'for_review'): ?>
          <div class="small text-muted mt-1">Waiting for the Admin to check its document type and post it.</div>
        <?php elseif ($doc['status'] === 'active' && $doc['posted_at']): ?>
          <div class="small text-muted mt-1">Posted <?= h($fmt($doc['posted_at'])) ?><?= $doc['posted_by_name'] && $is_admin ? ' by ' . h($doc['posted_by_name']) : '' ?></div>
        <?php elseif ($doc['status'] === 'archived'): ?>
          <div class="small text-muted mt-1">Archived <?= h($fmt($doc['archived_at'])) ?><?= $doc['archived_by_name'] ? ' by ' . h($doc['archived_by_name']) : ' automatically' ?><?= $doc['archive_reason'] ? ' — ' . h($doc['archive_reason']) : '' ?></div>
        <?php elseif ($doc['status'] === 'deleted'): $days_left = deleted_days_left($doc['deleted_at']); ?>
          <div class="small text-muted mt-1">In Recently Deleted since <?= h($fmt($doc['deleted_at'])) ?><?= $doc['delete_reason'] ? ' — ' . h($doc['delete_reason']) : '' ?></div>
          <div class="mt-1"><span class="purge-countdown small <?= $days_left <= 7 ? 'purge-countdown-soon' : '' ?>"><?= h(deleted_days_left_label($days_left)) ?></span></div>
        <?php endif; ?>
      </dd>
      <?php if ($doc['expiration_date']): $days = days_until($doc['expiration_date']); [$exp_label, $exp_badge] = expiration_badge($days); ?>
        <dt>Expires</dt><dd><?= h($fmt($doc['expiration_date'])) ?><?= $days <= 60 ? ' <span class="badge ' . $exp_badge . '">' . h($exp_label) . '</span>' : '' ?></dd>
      <?php endif; ?>
      <?php if ($is_admin): ?>
        <dt>Classification</dt>
        <dd>
          <span class="badge <?= confidence_badge_class($doc['confidence_score']) ?>"><?= h(confidence_label($doc['confidence_score'])) ?></span>
          <span class="small text-muted"><?= h(entry_method_label($doc['entry_method'])) ?></span>
        </dd>
        <dt>Last opened</dt><dd><?= h($fmt($doc['last_viewed_at'] ?? $doc['filed_at'])) ?> <span class="small text-muted">(before this preview)</span></dd>
      <?php endif; ?>
    </dl>

    <div class="d-flex flex-wrap gap-2">
      <?php if ($is_admin && $doc['status'] === 'for_review'): ?>
        <button type="button" class="btn btn-sm btn-brand" data-app-modal="<?= h(BASE_URL . '/api/modal.php?view=review&id=' . (int)$doc['document_id']) ?>"><i class="fa-solid fa-list-check"></i> Review &amp; Post</button>
      <?php endif; ?>
      <a href="<?= h(document_url((int)$doc['document_id'])) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-brand"><i class="fa-solid fa-up-right-from-square"></i> Open in new tab</a>
      <a href="<?= h(document_url((int)$doc['document_id'])) ?>&amp;download=1" class="btn btn-sm btn-outline-brand"><i class="fa-solid fa-download"></i> Download</a>
      <?php if ($doc['status'] !== 'deleted' && can_edit_document_details($me, $doc)): ?>
        <a href="<?= BASE_URL ?>/document_details.php?id=<?= (int)$doc['document_id'] ?>&amp;return=<?= h(urlencode($return)) ?>" class="btn btn-sm btn-outline-brand"><i class="fa-solid fa-pen-to-square"></i> Edit details</a>
      <?php endif; ?>
      <?php if ($doc['document_type'] === 'PDS' && ($is_admin || $is_owner) && in_array($doc['status'], ['active', 'for_review', 'archived'], true)): ?>
        <a href="<?= BASE_URL ?>/faculty/pds_import.php?document=<?= (int)$doc['document_id'] ?>" class="btn btn-sm btn-outline-brand"><i class="fa-solid fa-file-import"></i> Import to PDS</a>
      <?php endif; ?>
    </div>
  </div>
</div>
