<?php
/**
 * Edit a document's details: for certificates, the seminar / training
 * details listed in the Seminar & Training Report (title, dates, venue,
 * conducted by, type, level, hours); for every document, its date issued.
 * The document's owner (while it's active) or the Admin can edit
 * (can_edit_document_details()); POST + CSRF token.
 *
 * Certificates uploaded before these details existed show "Not specified"
 * in the report. "Pre-fill from scanned text" re-reads the OCR text already
 * stored with the document (no new scan) to fill in the empty fields.
 *
 * After saving, the document's age is checked again: one dated more than
 * ARCHIVE_AFTER_YEARS ago moves to the archive straight away.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/ocr/OcrProcessor.php';
require_login();

$me = current_user();
$document_id = (int)($_POST['document_id'] ?? $_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT d.*, u.full_name FROM documents d JOIN users u ON u.user_id = d.faculty_id WHERE d.document_id = ? AND d.status <> 'deleted'");
$stmt->execute([$document_id]);
$doc = $stmt->fetch();
if (!$doc || !can_edit_document_details($me, $doc)) {
    $_SESSION['flash_error'] = 'You can only edit the details of your own active documents.';
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}
$is_cert = $doc['document_type'] === 'Certificate';

// Back to the page the edit was opened from -- only a page inside the app
$return = (string)($_POST['return'] ?? $_GET['return'] ?? '');
if (!preg_match('#^((admin|faculty|approval)/)?[a-z_]+\.php(\?[A-Za-z0-9_.%=&\-\[\]+]*)?$#', $return)) {
    $return = $me['role'] === 'admin'
        ? 'admin/faculty_documents.php?id=' . (int)$doc['faculty_id'] . '&type=' . urlencode($doc['document_type'])
        : 'faculty/my_documents.php?type=' . urlencode($doc['document_type']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid()) {
        $_SESSION['flash_error'] = 'Your session expired before the form was sent. Please try again.';
        header('Location: ' . BASE_URL . '/document_details.php?id=' . $document_id . '&return=' . urlencode($return));
        exit;
    }
    $clean = document_details_clean((array)($_POST['details'] ?? []));
    if (!$is_cert) {   // only a title and the date issued apply to other documents; the rest is left as it is
        $clean = array_intersect_key($clean, array_flip(['title', 'date_issued']));
    }

    $changes = [];
    foreach ($clean as $col => $value) {
        $old = $doc[$col];
        if ($col === 'hours' && $old !== null) { $old = (float)$old; }
        if ((string)$old !== (string)$value) {
            $changes[ucfirst(str_replace('_', ' ', $col))] = ($old === null || $old === '' ? '(none)' : $old) . ' -> ' . ($value ?? '(none)');
        }
    }
    if ($changes) {
        $sets = implode(', ', array_map(fn($c) => "$c = ?", array_keys($clean)));
        $pdo->prepare("UPDATE documents SET $sets WHERE document_id = ?")->execute([...array_values($clean), $document_id]);
        log_my_activity($pdo, 'DOCUMENT_DETAILS', "Updated the details of document #{$document_id} \"" . basename($doc['file_path']) . '" ('
            . document_type_label($doc['document_type'], $doc['document_subtype']) . ') of ' . $doc['full_name'] . ' -- ' . describe_filters($changes) . '.');
    }

    // New dates can make it more than ARCHIVE_AFTER_YEARS old: archive it now, not tomorrow
    $archived = $doc['status'] === 'active' && auto_archive_old_documents($pdo, $me, $document_id)['archived'] > 0;
    $_SESSION['flash_success'] = ($changes ? 'The document details were saved.' : 'Nothing was changed.')
        . ($archived ? ' It is more than ' . ARCHIVE_AFTER_YEARS . ' years old, so it was moved to ' . ((int)$doc['faculty_id'] === (int)$me['user_id'] ? 'your archive (My Archive).' : 'the archive.') : '');
    header('Location: ' . BASE_URL . '/' . $return);
    exit;
}

// Values shown: what's stored, or -- after "Pre-fill" -- the stored text read
// again, for the fields that are still empty
$values = array_intersect_key($doc, array_flip(document_detail_columns()));
$prefilled = 0;
if (isset($_GET['prefill']) && trim((string)$doc['ocr_extracted_text']) !== '') {
    $t = OcrProcessor::extractTraining($doc['ocr_extracted_text']);
    $guess = ['title' => $t['title'], 'date_start' => $t['date_from'], 'date_end' => $t['date_to'], 'venue' => $t['venue'],
              'conducted_by' => $t['conducted_by'], 'training_type' => $t['training_type'], 'training_level' => $t['training_level'], 'hours' => $t['hours']];
    if (!$is_cert) { $guess = ['title' => null, 'date_issued' => $t['date_from']]; }
    foreach ($guess as $col => $v) {
        if (($values[$col] === null || $values[$col] === '') && $v !== null && $v !== '') { $values[$col] = $v; $prefilled++; }
    }
}

$page_title = 'Edit Document Details';
include __DIR__ . '/includes/header.php';
?>

<a href="<?= h(BASE_URL . '/' . $return) ?>" class="small text-muted d-inline-block mb-3"><i class="fa-solid fa-arrow-left"></i> Back</a>

<div class="card stat-card mx-auto" style="max-width:820px;">
  <div class="card-header bg-white fw-semibold"><i class="fa-solid fa-pen-to-square text-brand"></i> Edit Document Details</div>
  <div class="card-body">
    <div class="d-flex flex-wrap justify-content-between gap-2 mb-3">
      <div class="min-w-0">
        <div class="fw-semibold text-break"><?= h(document_display_name($doc['file_path'])) ?></div>
        <div class="small text-muted">
          <?= h(document_type_label($doc['document_type'], $doc['document_subtype'])) ?> · uploaded <?= h(date('M j, Y', strtotime($doc['filed_at']))) ?>
          <?= (int)$doc['faculty_id'] !== (int)$me['user_id'] ? ' · ' . h($doc['full_name']) : '' ?>
          <?= $doc['status'] === 'archived' ? ' · <span class="badge bg-light text-dark border">Archived</span>' : '' ?>
        </div>
      </div>
      <div class="d-flex gap-2 align-items-start">
        <a href="<?= h(document_url($document_id)) ?>" target="_blank" class="btn btn-sm btn-outline-brand"><i class="fa-solid fa-eye"></i> View document</a>
        <?php if (trim((string)$doc['ocr_extracted_text']) !== ''): ?>
          <a href="?id=<?= $document_id ?>&amp;prefill=1&amp;return=<?= h(urlencode($return)) ?>" class="btn btn-sm btn-outline-brand" title="Fill the empty fields from the text read from this document when it was uploaded">
            <i class="fa-solid fa-wand-magic-sparkles"></i> Pre-fill from scanned text
          </a>
        <?php endif; ?>
      </div>
    </div>

    <?php if (isset($_GET['prefill'])): ?>
      <div class="alert <?= $prefilled ? 'alert-info' : 'alert-light border' ?> small">
        <?= $prefilled ? "Filled {$prefilled} empty field" . ($prefilled === 1 ? '' : 's') . ' from the text read from the document. Please check them -- nothing is saved until you press Save.'
                       : 'Nothing more could be read from the document\'s text. Please fill in the details yourself.' ?>
      </div>
    <?php endif; ?>

    <form method="POST" id="detailsForm">
      <?= csrf_field() ?>
      <input type="hidden" name="document_id" value="<?= $document_id ?>">
      <input type="hidden" name="return" value="<?= h($return) ?>">
      <?php $prefix = 'details';
      $detail_groups = $is_cert ? ['training' => true, 'issued' => true] : ['title' => true, 'issued' => true];
      include __DIR__ . '/includes/document_details_fields.php'; ?>
      <div class="d-flex gap-2 mt-3">
        <button class="btn btn-brand"><i class="fa-solid fa-floppy-disk"></i> Save</button>
        <a href="<?= h(BASE_URL . '/' . $return) ?>" class="btn btn-outline-secondary">Cancel</a>
      </div>
    </form>
  </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
