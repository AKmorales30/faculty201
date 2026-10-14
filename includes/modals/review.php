<?php
/**
 * Classification review pop-up (Admin; api/modal.php?view=review): the
 * file, the detected type and its confidence, the details read from it,
 * and a form to correct them and Post. The form posts to
 * admin/manage_documents.php (CSRF token), which checks everything again
 * (review_save_document()). Expects $doc (modal_document(), status
 * 'for_review') and $me.
 *
 * Fields that apply only to some document types are switched by
 * assets/js/app.js ([data-type-switch] / [data-types]); hidden ones are
 * disabled so they aren't sent.
 */
$categories = categories_for_faculty($doc['employment_type']);
$current = $doc['document_type'] . '|' . ($doc['document_subtype'] ?? '');
$options = review_category_options($doc['employment_type']);
if (!isset($options[$current])) {   // e.g. IPCR uploaded before the account became part-time: still selectable
    $options = [$current => document_type_label($doc['document_type'], $doc['document_subtype']) . ' (does not apply to this account)'] + $options;
}
$predicted = $doc['predicted_category'];
$chosen = $doc['chosen_category'] ?? $doc['document_type'];
$score = $doc['confidence_score'];
$is_cert = $doc['document_type'] === 'Certificate';
$freq = document_categories()[$doc['document_type']]['frequency'] ?? null;
$period_now = current_academic_period();
$ay_list = academic_year_options();
$ay_sel = $doc['academic_year'] ?: $period_now['academic_year'];
if (!in_array($ay_sel, $ay_list, true)) { array_unshift($ay_list, $ay_sel); }
$types_with = fn(string $frequency): string => implode(' ', array_keys(array_filter(document_categories(), fn($c) => $c['frequency'] === $frequency)));
$other_types = implode(' ', array_diff(array_keys(document_categories()), ['Certificate']));
$return = (string)($_GET['return'] ?? '');
if (!preg_match('#^admin/manage_documents\.php(\?[A-Za-z0-9_.%=&\-]*)?$#', $return)) { $return = 'admin/manage_documents.php'; }
?>
<div data-modal-title="Review: <?= h(document_title($doc)) ?>"></div>
<div class="row g-3">
  <div class="col-lg-6">
    <?php include __DIR__ . '/preview.php'; ?>
    <div class="small text-muted mt-2">
      <?= h($doc['full_name']) ?> · uploaded <?= h(date('M j, Y, g:i A', strtotime($doc['filed_at']))) ?> ·
      <a href="<?= h(document_url((int)$doc['document_id'])) ?>" target="_blank" rel="noopener">Open in new tab <i class="fa-solid fa-up-right-from-square small"></i></a>
    </div>
    <?php if (trim((string)$doc['ocr_extracted_text']) !== ''): ?>
      <details class="mt-3">
        <summary class="small fw-semibold">Extracted text</summary>
        <pre class="review-ocr-text small border rounded bg-white p-2 mt-2 mb-0"><?= h($doc['ocr_extracted_text']) ?></pre>
      </details>
    <?php endif; ?>
  </div>

  <div class="col-lg-6">
    <div class="border rounded p-3 mb-3 bg-light">
      <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
        <div>
          <div class="small text-muted">Detected type</div>
          <div class="fw-semibold"><?= $predicted ? h(document_type_label($predicted)) : '<span class="text-muted">' . ($doc['entry_method'] === 'manual' ? 'None -- entered manually' : 'Could not be detected') . '</span>' ?></div>
        </div>
        <div class="text-end">
          <div class="small text-muted">Confidence</div>
          <span class="badge <?= confidence_badge_class($score) ?>"><?= h(confidence_label($score)) ?></span>
        </div>
      </div>
      <div class="small mt-2">
        Chosen by the uploader: <strong><?= h(document_type_label($chosen, $chosen === $doc['document_type'] ? $doc['document_subtype'] : null)) ?></strong>
        · <?= h(entry_method_label($doc['entry_method'])) ?>
      </div>
      <?php if ($doc['is_low_confidence']): ?>
        <div class="small text-warning-emphasis mt-1"><i class="fa-solid fa-triangle-exclamation"></i> Low confidence (below <?= h(confidence_label(CONFIDENCE_THRESHOLD)) ?>) -- please check the document type carefully.</div>
      <?php endif; ?>
      <?php if ($doc['ocr_matched_name']): ?>
        <div class="small mt-1"><i class="fa-solid fa-user-check text-success"></i> Name found on the document: <?= h($doc['ocr_matched_name']) ?></div>
      <?php endif; ?>
    </div>

    <form method="POST" action="<?= BASE_URL ?>/admin/manage_documents.php" class="review-form">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="review">
      <input type="hidden" name="document_id" value="<?= (int)$doc['document_id'] ?>">
      <input type="hidden" name="return" value="<?= h($return) ?>">

      <div class="row g-2 mb-2">
        <div class="col-md-7">
          <label for="rvCategory" class="form-label small fw-semibold">Document type</label>
          <select name="category" id="rvCategory" class="form-select form-select-sm" data-type-switch required>
            <?php foreach ($options as $val => $label): ?>
              <option value="<?= h($val) ?>" <?= $val === $current ? 'selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-5">
          <label for="rvExpiration" class="form-label small fw-semibold">Expiration date</label>
          <input type="date" name="expiration_date" id="rvExpiration" class="form-control form-control-sm" value="<?= h((string)$doc['expiration_date']) ?>">
        </div>
        <div class="col-6 col-md-4" data-types="<?= h($types_with('semester')) ?>" <?= $freq === 'semester' ? '' : 'hidden' ?>>
          <label for="rvSemester" class="form-label small fw-semibold">Semester</label>
          <select name="semester" id="rvSemester" class="form-select form-select-sm" <?= $freq === 'semester' ? '' : 'disabled' ?>>
            <?php foreach (semester_options() as $num => $label): ?>
              <option value="<?= $num ?>" <?= (int)($doc['semester'] ?? $period_now['semester']) === $num ? 'selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-6 col-md-4" data-types="<?= h($types_with('semester')) ?>" <?= $freq === 'semester' ? '' : 'hidden' ?>>
          <label for="rvAy" class="form-label small fw-semibold">Academic year</label>
          <select name="academic_year" id="rvAy" class="form-select form-select-sm" <?= $freq === 'semester' ? '' : 'disabled' ?>>
            <?php foreach ($ay_list as $ay): ?>
              <option value="<?= h($ay) ?>" <?= $ay === $ay_sel ? 'selected' : '' ?>><?= h($ay) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-6 col-md-4" data-types="<?= h($types_with('yearly')) ?>" <?= $freq === 'yearly' ? '' : 'hidden' ?>>
          <label for="rvYear" class="form-label small fw-semibold">Year</label>
          <input type="number" name="period_year" id="rvYear" class="form-control form-control-sm" min="1990" max="<?= (int)date('Y') + 1 ?>"
                 value="<?= (int)($doc['period_year'] ?: date('Y', strtotime($doc['filed_at']))) ?>" <?= $freq === 'yearly' ? '' : 'disabled' ?>>
        </div>
      </div>

      <div class="small fw-semibold mb-1">Extracted details</div>
      <?php $values = array_intersect_key($doc, array_flip(document_detail_columns())); $prefix = 'details'; ?>
      <div data-types="Certificate" <?= $is_cert ? '' : 'hidden' ?>>
        <?php $detail_groups = ['training' => true, 'issued' => true]; $detail_attr = $is_cert ? '' : 'disabled';
        include __DIR__ . '/../document_details_fields.php'; ?>
      </div>
      <div data-types="<?= h($other_types) ?>" <?= $is_cert ? 'hidden' : '' ?>>
        <?php $detail_groups = ['title' => true, 'issued' => true]; $detail_attr = $is_cert ? 'disabled' : '';
        include __DIR__ . '/../document_details_fields.php'; ?>
      </div>
      <?php unset($detail_attr); ?>

      <div class="d-flex flex-wrap gap-2 mt-3 pt-3 border-top">
        <button type="submit" name="do" value="post" class="btn btn-brand"><i class="fa-solid fa-check"></i> Post to Public Docs</button>
        <button type="submit" name="do" value="save" class="btn btn-outline-brand"><i class="fa-solid fa-floppy-disk"></i> Save corrections</button>
        <button type="button" class="btn btn-outline-secondary ms-auto" data-bs-dismiss="modal">Close</button>
      </div>
      <div class="form-text">Posting saves the type and details shown here, moves the document to Public Docs and notifies <?= h($doc['full_name']) ?>.</div>
    </form>
  </div>
</div>
