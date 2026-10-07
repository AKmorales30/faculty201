<?php
/**
 * Document detail inputs, shared by the upload preview
 * (faculty/submit_document.php) and Edit Document Details
 * (document_details.php). Saved through document_details_clean().
 *
 * Expects:
 *   $values         current values, keyed by document_detail_columns()
 *   $prefix         input name prefix: fields are named {$prefix}[title] etc.
 *   $detail_groups  which inputs to show:
 *                     training  seminar / training details (with the title)
 *                     title     the title alone (documents that aren't certificates)
 *                     issued    the date issued
 *   $detail_attr    extra attributes for every input, e.g. 'disabled' (optional)
 */
$v = fn(string $k): string => (string)($values[$k] ?? '');
$n = fn(string $k): string => h($prefix) . '[' . $k . ']';
$attr = isset($detail_attr) ? ' ' . $detail_attr : '';
$archive_hint = 'A document dated more than ' . (int)ARCHIVE_AFTER_YEARS . ' years ago goes straight to the archive.';
$uid = 'dd' . bin2hex(random_bytes(3));   // unique ids when included twice on one page
?>
<div class="row g-2 document-details" data-cutoff="<?= h(archive_cutoff_date()) ?>">
  <?php if (!empty($detail_groups['training'])): ?>
  <div class="col-12">
    <label class="form-label small" for="<?= $uid ?>title">Seminar / Training Title</label>
    <input type="text" id="<?= $uid ?>title" name="<?= $n('title') ?>" class="form-control form-control-sm" value="<?= h($v('title')) ?>" maxlength="255"<?= $attr ?>>
  </div>
  <div class="col-md-3 col-6">
    <label class="form-label small" for="<?= $uid ?>start">Date From</label>
    <input type="date" id="<?= $uid ?>start" name="<?= $n('date_start') ?>" class="form-control form-control-sm detail-date" value="<?= h($v('date_start')) ?>"<?= $attr ?>>
  </div>
  <div class="col-md-3 col-6">
    <label class="form-label small" for="<?= $uid ?>end">Date To</label>
    <input type="date" id="<?= $uid ?>end" name="<?= $n('date_end') ?>" class="form-control form-control-sm detail-date" value="<?= h($v('date_end')) ?>"<?= $attr ?>>
  </div>
  <div class="col-md-3 col-6">
    <label class="form-label small" for="<?= $uid ?>hours">Number of Hours</label>
    <input type="number" step="0.5" min="0.5" max="9999" id="<?= $uid ?>hours" name="<?= $n('hours') ?>" class="form-control form-control-sm" value="<?= h(is_numeric($v('hours')) ? (string)(float)$v('hours') : '') ?>"<?= $attr ?>>
  </div>
  <div class="col-md-3 col-6">
    <label class="form-label small" for="<?= $uid ?>type">Type</label>
    <select id="<?= $uid ?>type" name="<?= $n('training_type') ?>" class="form-select form-select-sm"<?= $attr ?>>
      <option value="">Not specified</option>
      <?php foreach (TRAINING_TYPES as $t): ?><option <?= $v('training_type') === $t ? 'selected' : '' ?>><?= h($t) ?></option><?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-6">
    <label class="form-label small" for="<?= $uid ?>venue">Venue / Where Held</label>
    <input type="text" id="<?= $uid ?>venue" name="<?= $n('venue') ?>" class="form-control form-control-sm" value="<?= h($v('venue')) ?>" maxlength="255" placeholder="e.g. UDM Auditorium, Manila / Online (Zoom)"<?= $attr ?>>
  </div>
  <div class="col-md-3 col-6">
    <label class="form-label small" for="<?= $uid ?>level">Level</label>
    <select id="<?= $uid ?>level" name="<?= $n('training_level') ?>" class="form-select form-select-sm"<?= $attr ?>>
      <option value="">Not specified</option>
      <?php foreach (TRAINING_LEVELS as $t): ?><option <?= $v('training_level') === $t ? 'selected' : '' ?>><?= h($t) ?></option><?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-3 col-6"></div>
  <div class="col-12">
    <label class="form-label small" for="<?= $uid ?>org">Conducted / Organized By</label>
    <input type="text" id="<?= $uid ?>org" name="<?= $n('conducted_by') ?>" class="form-control form-control-sm" value="<?= h($v('conducted_by')) ?>" maxlength="255" placeholder="e.g. UDM, CCS Department, DICT, CHED"<?= $attr ?>>
  </div>
  <?php elseif (!empty($detail_groups['title'])): ?>
  <div class="col-md-8">
    <label class="form-label small" for="<?= $uid ?>title">Title / Description (optional)</label>
    <input type="text" id="<?= $uid ?>title" name="<?= $n('title') ?>" class="form-control form-control-sm" value="<?= h($v('title')) ?>" maxlength="255"<?= $attr ?>>
  </div>
  <?php endif; ?>
  <?php if (!empty($detail_groups['issued'])): ?>
  <div class="col-md-4 col-6">
    <label class="form-label small" for="<?= $uid ?>issued">Date Issued<?= !empty($detail_groups['training']) ? ' (if no seminar dates)' : ' (if any)' ?></label>
    <input type="date" id="<?= $uid ?>issued" name="<?= $n('date_issued') ?>" class="form-control form-control-sm detail-date" value="<?= h($v('date_issued')) ?>"<?= $attr ?>>
  </div>
  <?php endif; ?>
  <div class="col-12">
    <div class="small text-warning-emphasis detail-archive-note" hidden><i class="fa-solid fa-box-archive"></i> <?= h($archive_hint) ?></div>
  </div>
</div>
<script>
// Warn when the dates entered make the document old enough to be archived (the server decides)
(function (box) {
  function check() {
    var dates = Array.prototype.map.call(box.querySelectorAll('.detail-date'), function (i) { return i.disabled ? '' : i.value; });
    var end = dates[1] || dates[0] || dates[2] || '';   // same order as document_date_sql(): to, from, issued
    box.querySelector('.detail-archive-note').hidden = !(end && end < box.dataset.cutoff);
  }
  box.addEventListener('change', check);
  box.addEventListener('input', check);
  check();
})(document.currentScript.previousElementSibling);
</script>
