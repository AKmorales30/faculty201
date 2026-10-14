<?php
/**
 * Files > Manage Document (Admin): the classification review queue.
 *
 * Every new upload lands here first, For Review (documents.status
 * 'for_review'). Review opens a pop-up (api/modal.php?view=review) with the
 * file, the detected document type and its confidence score, and the
 * details read from it; the Admin corrects them if needed and posts the
 * document to Public Docs (review_save_document(), includes/document_review.php).
 * The owner is notified once it's posted. Only posted documents appear in
 * Public Docs, searches, reports and analytics.
 *
 * This is a classification check only -- the old Program Chair / Dean
 * approval stays removed. A wrong file is deleted with the normal Delete
 * action (with a reason; it can be restored from Archived Docs).
 * The Recently Posted tab lists what was reviewed (formerly Classification
 * Review's "Reviewed" list), with corrections marked.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_role('admin');

$page_title = 'Manage Document';
$categories = document_categories();
$tabs = ['review' => 'For Review', 'posted' => 'Recently Posted'];

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
// Save corrections / Post -- from the review pop-up (POST + CSRF token)
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $return = (string)($_POST['return'] ?? '');
    if (!preg_match('#^admin/manage_documents\.php(\?[A-Za-z0-9_.%=&\-]*)?$#', $return)) { $return = 'admin/manage_documents.php'; }
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
            [$ok, $message] = review_save_document($pdo, $doc, $_POST, current_user(), ($_POST['do'] ?? '') === 'post');
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
         WHERE d.status = 'for_review' $and
         ORDER BY d.filed_at ASC, d.document_id ASC"
    );
} else {
    $stmt = $pdo->prepare(
        "SELECT d.*, u.full_name, p.full_name AS posted_by_name
         FROM documents d JOIN users u ON u.user_id = d.faculty_id LEFT JOIN users p ON p.user_id = d.posted_by
         WHERE d.posted_by IS NOT NULL $and
         ORDER BY d.posted_at DESC, d.document_id DESC LIMIT 100"
    );
}
$stmt->execute($params);
$documents = $stmt->fetchAll();
$review_total = documents_for_review_count($pdo);

/** Category label for a stored key, or a dash when there is none. */
$cat_label = fn(?string $type): string => $type ? ($categories[$type]['label'] ?? $type) : '—';
$review_url = fn(int $id): string => BASE_URL . '/api/modal.php?view=review&id=' . $id . '&return=' . rawurlencode($self_path);

include __DIR__ . '/../includes/header.php';
include_once __DIR__ . '/../includes/document_actions.php';   // Delete button + confirmation dialog
?>

<h3 class="fw-bold mb-1">Manage Document</h3>
<p class="text-muted mb-3">
  Every new upload waits here For Review. Open one to check the document type the system detected (and how confident it was) and the details it read,
  correct anything that's wrong, then <strong>Post</strong> it to Public Docs -- the faculty member is notified. This is a classification check only;
  a wrong file can be deleted with a reason. Low-confidence uploads (below <?= h(confidence_label(CONFIDENCE_THRESHOLD)) ?>) are highlighted.
</p>

<ul class="nav nav-tabs page-tabs mb-3">
  <?php foreach ($tabs as $key => $label): ?>
    <li class="nav-item">
      <a class="nav-link <?= $tab === $key ? 'active' : '' ?>" href="manage_documents.php<?= $key !== 'review' ? '?tab=' . h($key) : '' ?>">
        <i class="fa-solid <?= $key === 'review' ? 'fa-hourglass-half' : 'fa-circle-check' ?>"></i> <?= h($label) ?>
        <?php if ($key === 'review'): ?><span class="badge rounded-pill bg-review ms-1"><?= (int)$review_total ?></span><?php endif; ?>
      </a>
    </li>
  <?php endforeach; ?>
</ul>

<div class="card stat-card mb-4">
  <div class="card-body">
    <form method="GET" class="row g-2 align-items-end">
      <?php if ($tab !== 'review'): ?><input type="hidden" name="tab" value="<?= h($tab) ?>"><?php endif; ?>
      <div class="col-6 col-md-3 col-xl-2">
        <label for="fFrom" class="form-label small fw-semibold">Uploaded From</label>
        <input type="date" name="from" id="fFrom" value="<?= h($from) ?>" class="form-control">
      </div>
      <div class="col-6 col-md-3 col-xl-2">
        <label for="fTo" class="form-label small fw-semibold">Uploaded To</label>
        <input type="date" name="to" id="fTo" value="<?= h($to) ?>" class="form-control">
      </div>
      <div class="col-md-6 col-xl-3">
        <label for="fCategory" class="form-label small fw-semibold">Document Type</label>
        <select name="category" id="fCategory" class="form-select">
          <option value="">All Types</option>
          <?php foreach ($categories as $key => $meta): ?>
            <option value="<?= h($key) ?>" <?= $category === $key ? 'selected' : '' ?>><?= h($meta['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-6 col-xl-2">
        <div class="form-check mb-2">
          <input type="checkbox" name="low" value="1" id="fLow" class="form-check-input" <?= $low_only ? 'checked' : '' ?>>
          <label for="fLow" class="form-check-label small">Low confidence only</label>
        </div>
      </div>
      <div class="col-md-6 col-xl-3 d-flex gap-2">
        <button class="btn btn-brand flex-fill"><i class="fa-solid fa-filter"></i> Filter</button>
        <a href="manage_documents.php<?= $tab !== 'review' ? '?tab=' . h($tab) : '' ?>" class="btn btn-outline-secondary flex-fill">Reset</a>
      </div>
    </form>
  </div>
</div>

<?php if ($tab === 'review'): ?>
<div class="small text-muted mb-2"><?= count($documents) ?> document<?= count($documents) === 1 ? '' : 's' ?> waiting, oldest first · click a row to review it</div>
<div class="card stat-card">
  <div class="card-body p-0 table-responsive">
    <table class="table table-hover mb-0 align-middle">
      <thead class="table-light">
        <tr><th>Faculty</th><th>Document</th><th>Detected Type</th><th>Chosen by Uploader</th><th>Confidence</th><th>Uploaded</th><th></th></tr>
      </thead>
      <tbody>
        <?php if (!$documents): ?>
          <tr><td colspan="7" class="text-center text-muted py-4"><i class="fa-solid fa-circle-check text-success"></i> Nothing is waiting for review<?= $where ? ' with these filters' : '' ?>.</td></tr>
        <?php endif; ?>
        <?php foreach ($documents as $d): $score = $d['confidence_score']; ?>
        <tr class="row-clickable <?= $d['is_low_confidence'] ? 'row-attention' : '' ?>" tabindex="0" role="button" data-app-modal="<?= h($review_url((int)$d['document_id'])) ?>">
          <td><?= h($d['full_name']) ?></td>
          <td class="text-break small">
            <div class="fw-semibold"><?= h(document_title($d)) ?></div>
            <?php if (trim((string)$d['title']) !== ''): ?><div class="text-muted"><?= h(document_display_name($d['file_path'])) ?></div><?php endif; ?>
          </td>
          <td class="small"><?= $d['entry_method'] === 'manual' ? '<span class="text-muted">Entered manually</span>' : h($cat_label($d['predicted_category'])) ?></td>
          <td class="small"><?= h(document_type_label($d['document_type'], $d['document_subtype'])) ?><?php if ($p = document_period_label($d)): ?><div class="text-muted"><?= h($p) ?></div><?php endif; ?></td>
          <td><span class="badge <?= confidence_badge_class($score) ?>"><?= h(confidence_label($score)) ?></span></td>
          <td class="text-nowrap small"><?= h(date('M j, Y g:ia', strtotime($d['filed_at']))) ?></td>
          <td class="text-nowrap">
            <button type="button" class="btn btn-sm btn-brand" data-app-modal="<?= h($review_url((int)$d['document_id'])) ?>"><i class="fa-solid fa-list-check"></i> Review</button>
            <?= document_action_buttons($d, current_user()) ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php else: ?>
<div class="small text-muted mb-2">The last <?= count($documents) ?> document<?= count($documents) === 1 ? '' : 's' ?> posted through this review, newest first</div>
<div class="card stat-card">
  <div class="card-body p-0 table-responsive">
    <table class="table table-hover mb-0 align-middle">
      <thead class="table-light">
        <tr><th>Faculty</th><th>Document</th><th>Posted As</th><th>Confidence</th><th>Uploaded</th><th>Posted</th><th>Now</th></tr>
      </thead>
      <tbody>
        <?php if (!$documents): ?>
          <tr><td colspan="7" class="text-center text-muted py-4">No documents have been posted through the review yet.</td></tr>
        <?php endif; ?>
        <?php foreach ($documents as $d):
          $corrected = $d['chosen_category'] !== null && $d['chosen_category'] !== $d['document_type'];
          [$st_label, $st_badge] = document_status_badge($d['status']); ?>
        <tr class="row-clickable" tabindex="0" role="button" data-app-modal="<?= h(BASE_URL . '/api/modal.php?view=document&id=' . (int)$d['document_id']) ?>">
          <td><?= h($d['full_name']) ?></td>
          <td class="text-break small fw-semibold"><?= h(document_title($d)) ?></td>
          <td class="small">
            <?= h(document_type_label($d['document_type'], $d['document_subtype'])) ?>
            <?php if ($corrected): ?><div><span class="badge bg-info" title="The uploader chose <?= h($cat_label($d['chosen_category'])) ?>">Corrected</span></div><?php endif; ?>
          </td>
          <td><span class="badge <?= confidence_badge_class($d['confidence_score']) ?>"><?= h(confidence_label($d['confidence_score'])) ?></span></td>
          <td class="text-nowrap small"><?= h(date('M j, Y', strtotime($d['filed_at']))) ?></td>
          <td class="small"><?= h(date('M j, Y g:ia', strtotime($d['posted_at']))) ?><?php if ($d['posted_by_name']): ?><div class="text-muted">by <?= h($d['posted_by_name']) ?></div><?php endif; ?></td>
          <td><span class="badge <?= $st_badge ?>"><?= h($st_label) ?></span></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
