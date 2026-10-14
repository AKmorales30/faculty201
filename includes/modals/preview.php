<?php
/**
 * The file itself, for the document and review pop-ups: a PDF in a frame,
 * an image as an image, anything else (Excel PDS) as a download link.
 * Served through document.php, so the access check applies and opening it
 * counts as a view (last_viewed_at). Expects $doc (documents row).
 */
$pv_url = document_url((int)$doc['document_id']);
$pv_mime = document_mime_type($doc['file_path']);
?>
<div class="doc-preview border rounded bg-light">
  <?php if ($pv_mime === 'application/pdf'): ?>
    <iframe src="<?= h($pv_url) ?>" class="doc-preview-frame" title="Preview of <?= h(document_title($doc)) ?>"></iframe>
  <?php elseif (str_starts_with($pv_mime, 'image/')): ?>
    <img src="<?= h($pv_url) ?>" alt="Preview of <?= h(document_title($doc)) ?>" class="doc-preview-img">
  <?php else: ?>
    <div class="text-center text-muted p-5">
      <i class="fa-solid fa-file-excel fa-3x mb-3 text-accent-teal" aria-hidden="true"></i>
      <p class="mb-3">This file type can't be previewed here.</p>
      <a href="<?= h($pv_url) ?>&amp;download=1" class="btn btn-outline-brand btn-sm"><i class="fa-solid fa-download"></i> Download to open it</a>
    </div>
  <?php endif; ?>
</div>
