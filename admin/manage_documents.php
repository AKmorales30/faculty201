<?php
/**
 * Files > Manage Document (Admin): documents flagged for checking.
 *
 * Uploads are posted to 201-Files right away. The system flags the ones
 * worth checking (documents.flag_reason): the type was detected with low
 * confidence, no text could be read, the details were entered manually,
 * or the uploader picked a different type than the one detected. A flag
 * never blocks or hides a document. Check opens a pop-up
 * (api/modal.php?view=review) with the file, the detected type and its
 * confidence and the details read from it; the Admin corrects them if
 * needed and marks it checked (review_save_document(),
 * includes/document_review.php). The owner is notified only when the type
 * is corrected. The Admin can correct any document the same way from its
 * pop-up in 201-Files or Archived Docs.
 *
 * Tabs: Flagged for checking (oldest first) and Checked (the latest 100
 * checked or corrected, with corrections marked).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_role('admin');

$page_title = 'Manage Document';
$categories = document_categories();
$tabs = ['review' => 'Flagged for checking', 'checked' => 'Checked'];

// Filters (all optional). Anything invalid is ignored rather than erroring.
$valid_date = function (string $d): string {
    $dt = DateTime::createFromFormat('!Y-m-d', $d);
    return ($dt && $dt->format('Y-m-d') === $d) ? $d : '';
};
$tab      = array_key_exists($_GET['tab'] ?? '', $tabs) ? $_GET['tab'] : 'review';
$from     = $valid_date(trim($_GET['from'] ?? ''));
$to       = $valid_date(trim($_GET['to'] ?? ''));
$category = $_GET['category'] ?? '';
$low_only = !empty($_GET['low']);
if (!array_key_exists($category, $categories)) { $category = ''; }
if ($from !== '' && $to !== '' && $from > $to) { [$from, $to] = [$to, $from]; }

$filter_query = array_filter(['tab' => $tab !== 'review' ? $tab : '', 'from' => $from, 'to' => $to, 'category' => $category, 'low' => $low_only ? '1' : ''], fn($v) => $v !== '');
$self_path = 'admin/manage_documents.php' . ($filter_query ? '?' . http_build_query($filter_query) : '');

// ---------------------------------------------------------------------
// Save corrections / mark as checked -- from the pop-up (POST + CSRF token)
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $return = (string)($_POST['return'] ?? '');
    if (!preg_match('#^(admin/[a-z_]+|archive|profile)\.php(\?[A-Za-z0-9_.%=&\-\[\]+]*)?$#', $return)) { $return = 'admin/manage_documents.php'; }
    if (!csrf_valid()) {
        $_SESSION['flash_error'] = 'Your session expired before the form was sent. Please try again.';
    } elseif (($_POST['action'] ?? '') !== 'review') {
        $_SESSION['flash_error'] = 'Unknown action.';
    } else {
        $stmt = $pdo->prepare("SELECT d.*, u.full_name, u.employment_type FROM documents d JOIN users u ON u.user_id = d.faculty_id WHERE d.document_id = ?");
        $stmt->execute([(int)($_POST['document_id'] ?? 0)]);
        $doc = $stmt->fetch();
        if (!$doc) {
            $_SESSION['flash_error'] = 'That document could not be found.';
        } else {
            [$ok, $message] = review_save_document($pdo, $doc, $_POST, current_user(), ($_POST['do'] ?? '') === 'checked');
            $_SESSION[$ok ? 'flash_success' : 'flash_error'] = $message;
        }
    }
    header('Location: ' . BASE_URL . '/' . $return);
    exit;
}

// ---------------------------------------------------------------------
// The lists
// ---------------------------------------------------------------------
$where = [];
$params = [];
if ($from !== '')     { $where[] = "d.filed_at >= ?"; $params[] = $from . ' 00:00:00'; }
if ($to !== '')       { $where[] = "d.filed_at < DATE_ADD(?, INTERVAL 1 DAY)"; $params[] = $to; }
if ($category !== '') { $where[] = "d.document_type = ?"; $params[] = $category; }
if ($low_only)        { $where[] = "d.is_low_confidence = 1"; }
$and = $where ? ' AND ' . implode(' AND ', $where) : '';

if ($tab === 'review') {
    // Oldest first, so nothing waits too long
    $stmt = $pdo->prepare(
        "SELECT d.*, u.full_name, u.employment_type, " . delete_window_sql('d') . " AS delete_seconds_left
         FROM documents d JOIN users u ON u.user_id = d.faculty_id
         WHERE " . document_flagged_sql('d') . " $and
         ORDER BY d.filed_at ASC, d.document_id ASC"
    );
} else {
    $stmt = $pdo->prepare(
        "SELECT d.*, u.full_name, rv.full_name AS reviewed_by_name
         FROM documents d JOIN users u ON u.user_id = d.faculty_id LEFT JOIN users rv ON rv.user_id = d.reviewed_by
         WHERE d.reviewed_at IS NOT NULL AND d.status IN ('active', 'archived') $and
         ORDER BY d.reviewed_at DESC, d.document_id DESC LIMIT 100"
    );
}
$stmt->execute($params);
$documents = $stmt->fetchAll();
$review_total = documents_flagged_count($pdo);

/** Category label for a stored key, or a dash when there is none. */
$cat_label = fn(?string $type): string => $type ? ($categories[$type]['label'] ?? $type) : '—';
$review_url = fn(int $id): string => BASE_URL . '/api/modal.php?view=review&id=' . $id . '&return=' . rawurlencode($self_path);

include __DIR__ . '/../includes/header.php';
include_once __DIR__ . '/../includes/document_actions.php';   // Delete button + confirmation dialog
?>

<?php $filtered = $from !== '' || $to !== '' || $category !== '' || $low_only; ?>
<?= admin_intro('Uploads go to 201-Files right away. The system flags the ones worth checking — the type was detected with low confidence (below ' . h(confidence_label(CONFIDENCE_THRESHOLD)) . '), no text could be read, or the details were entered manually. Open one to correct its type or details, then <strong>mark it as checked</strong>. The faculty member is notified only if you change the type. You can also correct any document from its pop-up in 201-Files.') ?>

<div class="nav page-tabs" role="tablist" aria-label="Manage Document">
  <?php foreach ($tabs as $key => $label): ?>
    <a class="nav-link <?= $tab === $key ? 'active' : '' ?>" role="tab" aria-selected="<?= $tab === $key ? 'true' : 'false' ?>" href="manage_documents.php<?= $key !== 'review' ? '?tab=' . h($key) : '' ?>">
      <?= h($label) ?>
      <?php if ($key === 'review'): ?><span class="badge"><?= (int)$review_total ?></span><?php endif; ?>
    </a>
  <?php endforeach; ?>
</div>

<section class="ui-card" aria-label="<?= h($tabs[$tab]) ?>">
  <form method="GET" class="ui-toolbar" data-autosubmit>
    <?php if ($tab !== 'review'): ?><input type="hidden" name="tab" value="<?= h($tab) ?>"><?php endif; ?>
    <label for="fCategory" class="visually-hidden">Document type</label>
    <select name="category" id="fCategory" class="form-select">
      <option value="">All types</option>
      <?php foreach ($categories as $key => $meta): ?>
        <option value="<?= h($key) ?>" <?= $category === $key ? 'selected' : '' ?>><?= h($meta['label']) ?></option>
      <?php endforeach; ?>
    </select>
    <label for="fFrom" class="visually-hidden">Uploaded from</label>
    <input type="date" name="from" id="fFrom" value="<?= h($from) ?>" class="form-control" title="Uploaded from">
    <label for="fTo" class="visually-hidden">Uploaded to</label>
    <input type="date" name="to" id="fTo" value="<?= h($to) ?>" class="form-control" title="Uploaded to">
    <div class="form-check">
      <input type="checkbox" name="low" value="1" id="fLow" class="form-check-input" <?= $low_only ? 'checked' : '' ?>>
      <label for="fLow" class="form-check-label">Low confidence only</label>
    </div>
    <button class="btn btn-sm btn-brand ui-apply"><i class="fa-solid fa-filter" aria-hidden="true"></i> Apply</button>
    <?php if ($filtered): ?><a href="manage_documents.php<?= $tab !== 'review' ? '?tab=' . h($tab) : '' ?>" class="btn btn-sm btn-outline-secondary">Clear filters</a><?php endif; ?>
    <span class="ui-count"><?= count($documents) ?> <?= $tab === 'review' ? 'flagged · oldest first' : 'checked · newest first' ?></span>
  </form>

  <div class="table-responsive">
  <?php if ($tab === 'review'): ?>
    <table class="table align-middle">
      <thead>
        <tr><th>Faculty</th><th>Document</th><th>Why flagged</th><th>Detected type</th><th>Saved as</th><th>Confidence</th><th>Uploaded</th><th class="text-end"><span class="visually-hidden">Actions</span></th></tr>
      </thead>
      <tbody>
        <?php if (!$documents): ?>
          <?= admin_empty_row(8, $filtered ? 'Nothing matches these filters' : 'All caught up',
                $filtered ? 'Try different filters, or clear them to see everything.' : 'No uploads are flagged for checking.',
                $filtered ? 'manage_documents.php' : null, $filtered ? 'fa-inbox' : 'fa-circle-check') ?>
        <?php endif; ?>
        <?php foreach ($documents as $d): ?>
        <tr class="row-clickable <?= $d['is_low_confidence'] ? 'row-attention' : '' ?>" tabindex="0" role="button" data-app-modal="<?= h($review_url((int)$d['document_id'])) ?>" data-app-modal-title="Check document" aria-label="Check <?= h(document_title($d)) ?>">
          <td class="fw-semibold"><?= h($d['full_name']) ?></td>
          <td class="text-break">
            <?= h(document_title($d)) ?>
            <?php if (trim((string)$d['title']) !== ''): ?><div class="ui-cell-sub"><?= h(document_display_name($d['file_path'])) ?></div><?php endif; ?>
          </td>
          <td><?php foreach (document_flag_labels($d['flag_reason']) as $fl): ?><span class="badge bg-review me-1 mb-1"><?= h($fl) ?></span><?php endforeach; ?></td>
          <td><?= $d['entry_method'] === 'manual' ? '<span class="text-muted">Entered manually</span>' : h($cat_label($d['predicted_category'])) ?></td>
          <td><?= h(document_type_label($d['document_type'], $d['document_subtype'])) ?><?php if ($p = document_period_label($d)): ?><div class="ui-cell-sub"><?= h($p) ?></div><?php endif; ?></td>
          <td><?= admin_confidence($d['confidence_score']) ?></td>
          <td class="text-nowrap"><?= h(date('M j, Y', strtotime($d['filed_at']))) ?><div class="ui-cell-sub"><?= h(date('g:i A', strtotime($d['filed_at']))) ?></div></td>
          <td>
            <div class="ui-actions">
              <button type="button" class="btn btn-sm btn-brand" data-app-modal="<?= h($review_url((int)$d['document_id'])) ?>" data-app-modal-title="Check document"><i class="fa-solid fa-list-check" aria-hidden="true"></i> Check</button>
              <?= document_action_buttons($d, current_user()) ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php else: ?>
    <table class="table align-middle">
      <thead>
        <tr><th>Faculty</th><th>Document</th><th>Type</th><th>Confidence</th><th>Uploaded</th><th>Checked</th><th>Now</th></tr>
      </thead>
      <tbody>
        <?php if (!$documents): ?>
          <?= admin_empty_row(7, $filtered ? 'Nothing matches these filters' : 'Nothing checked yet',
                $filtered ? 'Try different filters, or clear them to see everything.' : 'Documents you check or correct appear here.',
                $filtered ? 'manage_documents.php?tab=checked' : null) ?>
        <?php endif; ?>
        <?php foreach ($documents as $d):
          $corrected = $d['chosen_category'] !== null && $d['chosen_category'] !== $d['document_type'];
          [$st_label, $st_badge] = document_status_badge($d['status']); ?>
        <tr class="row-clickable" tabindex="0" role="button" data-app-modal="<?= h($review_url((int)$d['document_id'])) ?>" data-app-modal-title="Correct document" aria-label="Open <?= h(document_title($d)) ?>">
          <td class="fw-semibold"><?= h($d['full_name']) ?></td>
          <td class="text-break"><?= h(document_title($d)) ?></td>
          <td>
            <?= h(document_type_label($d['document_type'], $d['document_subtype'])) ?>
            <?php if ($corrected): ?><div class="ui-cell-sub" title="The uploader chose <?= h($cat_label($d['chosen_category'])) ?>">Corrected from <?= h($cat_label($d['chosen_category'])) ?></div><?php endif; ?>
          </td>
          <td><?= admin_confidence($d['confidence_score']) ?></td>
          <td class="text-nowrap"><?= h(date('M j, Y', strtotime($d['filed_at']))) ?></td>
          <td class="text-nowrap"><?= h(date('M j, Y', strtotime($d['reviewed_at']))) ?><?php if ($d['reviewed_by_name']): ?><div class="ui-cell-sub">by <?= h($d['reviewed_by_name']) ?></div><?php endif; ?><?php if ($d['flag_reason']): ?><div class="ui-cell-sub">was flagged: <?= h(implode(', ', document_flag_labels($d['flag_reason']))) ?></div><?php endif; ?></td>
          <td><span class="badge <?= $st_badge ?>"><?= h($st_label === 'Posted' ? 'In 201-Files' : $st_label) ?></span></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
  </div>
</section>

<?php include __DIR__ . '/../includes/footer.php'; ?>
