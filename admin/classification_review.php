<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_role('admin');

// Uploads whose auto-categorization was below CONFIDENCE_THRESHOLD (Fig. 5
// "confident?" = no). The faculty member picked the category themselves;
// the Admin can check it here, correct it, and mark it reviewed. Review
// only -- these documents are filed and visible like any other upload.

$page_title = 'Classification Review';
$categories = document_categories();

$review_options = ['pending' => 'Needs review', 'reviewed' => 'Reviewed', 'all' => 'All'];

// Filters (all optional). Anything invalid is ignored rather than erroring.
$valid_date = function (string $d): string {
    $dt = DateTime::createFromFormat('!Y-m-d', $d);
    return ($dt && $dt->format('Y-m-d') === $d) ? $d : '';
};
$from     = $valid_date(trim($_GET['from'] ?? ''));
$to       = $valid_date(trim($_GET['to'] ?? ''));
$category = $_GET['category'] ?? '';
$review   = $_GET['review'] ?? 'pending';
if (!array_key_exists($category, $categories)) { $category = ''; }
if (!array_key_exists($review, $review_options)) { $review = 'pending'; }
if ($from !== '' && $to !== '' && $from > $to) { [$from, $to] = [$to, $from]; }

$filter_query = array_filter(['from' => $from, 'to' => $to, 'category' => $category, 'review' => $review], fn($v) => $v !== '');
$self_url = BASE_URL . '/admin/classification_review.php?' . http_build_query($filter_query);

// ---------------------------------------------------------------------
// Correct the category (if changed) and mark the document reviewed
// ---------------------------------------------------------------------
if (($_POST['action'] ?? '') === 'review') {
    $stmt = $pdo->prepare(
        "SELECT d.document_id, d.faculty_id, d.document_type, d.document_subtype, d.file_path, u.employment_type
         FROM documents d JOIN users u ON u.user_id = d.faculty_id
         WHERE d.document_id = ? AND d.is_low_confidence = 1 AND d.status = 'active'"
    );
    $stmt->execute([(int)($_POST['document_id'] ?? 0)]);
    $doc = $stmt->fetch();

    // "Type|Subtype" from the category select, limited to what applies to the faculty member
    [$new_type, $new_sub] = array_pad(explode('|', (string)($_POST['category'] ?? ''), 2), 2, '');
    $allowed = $doc ? categories_for_faculty($doc['employment_type']) : [];
    $cat = $allowed[$new_type] ?? null;
    if ($cat) {
        $new_sub = !empty($cat['subtypes']) ? (isset($cat['subtypes'][$new_sub]) ? $new_sub : $cat['default_subtype']) : null;
    }

    if (!$doc || !$cat) {
        $_SESSION['flash_error'] = 'That document or category could not be found.';
    } else {
        $changed = $new_type !== $doc['document_type'] || $new_sub !== $doc['document_subtype'];
        $file_path = $doc['file_path'];
        if ($changed) {
            // Keep the local copy in the matching folder; the database copy (document_files) is unaffected
            $file_path = move_to_repository((int)$doc['faculty_id'], $new_type, $doc['file_path'], $new_sub) ?? $doc['file_path'];
        }
        // Period fields that don't apply to the new category are cleared
        $pdo->prepare(
            "UPDATE documents
             SET document_type = ?, document_subtype = ?, file_path = ?,
                 academic_year = IF(?, academic_year, NULL), semester = IF(?, semester, NULL),
                 period_year = IF(?, period_year, NULL),
                 reviewed_at = NOW(), reviewed_by = ?
             WHERE document_id = ?"
        )->execute([
            $new_type, $new_sub, $file_path,
            (int)($cat['frequency'] === 'semester'), (int)($cat['frequency'] === 'semester'),
            (int)($cat['frequency'] === 'yearly'),
            current_user()['user_id'], $doc['document_id'],
        ]);
        if ($changed) {
            log_my_activity($pdo, 'CATEGORY_CORRECT', "Corrected the category of document #{$doc['document_id']} \"" . basename($doc['file_path']) . '" from '
                . document_type_label($doc['document_type'], $doc['document_subtype']) . ' to ' . document_type_label($new_type, $new_sub) . '.');
        }
        $_SESSION['flash_success'] = $changed
            ? 'Category corrected to ' . document_type_label($new_type, $new_sub) . ' and marked as reviewed.'
            : 'Marked as reviewed.';
    }
    header('Location: ' . $self_url);
    exit;
}

// ---------------------------------------------------------------------
// The list: lowest confidence first
// ---------------------------------------------------------------------
$sql = "SELECT d.document_id, d.faculty_id, d.document_type, d.document_subtype, d.file_path, d.filed_at,
               d.confidence_score, d.predicted_category, d.chosen_category, d.reviewed_at,
               u.full_name, u.employment_type, r.full_name AS reviewer_name
        FROM documents d
        JOIN users u ON u.user_id = d.faculty_id
        LEFT JOIN users r ON r.user_id = d.reviewed_by
        WHERE d.is_low_confidence = 1 AND d.status = 'active'";
$params = [];

if ($from !== '') {
    $sql .= " AND d.filed_at >= ?";
    $params[] = $from . ' 00:00:00';
}
if ($to !== '') {
    $sql .= " AND d.filed_at < DATE_ADD(?, INTERVAL 1 DAY)";
    $params[] = $to;
}
if ($category !== '') {
    $sql .= " AND d.document_type = ?";
    $params[] = $category;
}
if ($review === 'pending') {
    $sql .= " AND d.reviewed_at IS NULL";
} elseif ($review === 'reviewed') {
    $sql .= " AND d.reviewed_at IS NOT NULL";
}
$sql .= " ORDER BY d.confidence_score IS NULL, d.confidence_score ASC, d.filed_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$documents = $stmt->fetchAll();

/** Category label for a stored key, or a dash when there is none. */
$cat_label = fn(?string $type): string => $type ? ($categories[$type]['label'] ?? $type) : '—';

include __DIR__ . '/../includes/header.php';
?>

<h3 class="fw-bold mb-1">Classification Review</h3>
<p class="text-muted mb-4">
  Uploads the system could not confidently categorize (below <?= h(confidence_label(CONFIDENCE_THRESHOLD)) ?> confidence), so the faculty member chose the category themselves.
  Open the document, correct its category if needed, then mark it as reviewed. These documents are already filed -- nothing is waiting on this review.
</p>

<div class="card stat-card mb-4">
  <div class="card-body">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-6 col-md-3 col-xl-2">
        <label for="fFrom" class="form-label small fw-semibold">Uploaded From</label>
        <input type="date" name="from" id="fFrom" value="<?= h($from) ?>" class="form-control">
      </div>
      <div class="col-6 col-md-3 col-xl-2">
        <label for="fTo" class="form-label small fw-semibold">Uploaded To</label>
        <input type="date" name="to" id="fTo" value="<?= h($to) ?>" class="form-control">
      </div>
      <div class="col-md-6 col-xl-3">
        <label for="fCategory" class="form-label small fw-semibold">Category</label>
        <select name="category" id="fCategory" class="form-select">
          <option value="">All Categories</option>
          <?php foreach ($categories as $key => $meta): ?>
            <option value="<?= h($key) ?>" <?= $category === $key ? 'selected' : '' ?>><?= h($meta['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-6 col-xl-2">
        <label for="fReview" class="form-label small fw-semibold">Status</label>
        <select name="review" id="fReview" class="form-select">
          <?php foreach ($review_options as $key => $label): ?>
            <option value="<?= h($key) ?>" <?= $review === $key ? 'selected' : '' ?>><?= h($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-6 col-xl-3 d-flex gap-2">
        <button class="btn btn-brand flex-fill"><i class="fa-solid fa-filter"></i> Filter</button>
        <a href="classification_review.php" class="btn btn-outline-secondary flex-fill">Reset</a>
      </div>
    </form>
  </div>
</div>

<div class="small text-muted mb-2"><?= count($documents) ?> document<?= count($documents) === 1 ? '' : 's' ?>, lowest confidence first</div>

<div class="card stat-card">
  <div class="card-body p-0 table-responsive">
    <table class="table mb-0 align-middle">
      <thead class="table-light">
        <tr><th>Faculty</th><th>Document</th><th>Predicted</th><th>Chosen by Faculty</th><th>Confidence</th><th>Uploaded</th><th style="min-width:260px">Category &amp; Review</th></tr>
      </thead>
      <tbody>
        <?php if (!$documents): ?>
          <tr><td colspan="7" class="text-center text-muted py-4">No low-confidence documents match these filters.</td></tr>
        <?php endif; ?>
        <?php foreach ($documents as $d):
          $score = $d['confidence_score'];
          $current = $d['document_type'] . '|' . ($d['document_subtype'] ?? '');
          $corrected = $d['chosen_category'] !== null && $d['chosen_category'] !== $d['document_type']; ?>
        <tr class="<?= $d['reviewed_at'] ? '' : 'table-warning' ?>">
          <td><?= h($d['full_name']) ?></td>
          <td>
            <a href="<?= h(document_url((int)$d['document_id'])) ?>" target="_blank" rel="noopener" class="text-break">
              <i class="fa-solid fa-up-right-from-square small"></i> <?= h(basename($d['file_path'])) ?>
            </a>
          </td>
          <td><?= h($cat_label($d['predicted_category'])) ?></td>
          <td><?= h($cat_label($d['chosen_category'] ?? $d['document_type'])) ?></td>
          <td>
            <span class="badge <?= $score === null ? 'bg-light text-muted border' : ((float)$score < 0.3 ? 'bg-danger' : 'bg-warning') ?>">
              <?= h(confidence_label($score)) ?>
            </span>
          </td>
          <td class="text-nowrap"><?= h(date('M j, Y', strtotime($d['filed_at']))) ?></td>
          <td>
            <form method="POST" action="<?= h($self_url) ?>" class="d-flex gap-2">
              <input type="hidden" name="action" value="review">
              <input type="hidden" name="document_id" value="<?= (int)$d['document_id'] ?>">
              <select name="category" class="form-select form-select-sm" aria-label="Category">
                <?php foreach (categories_for_faculty($d['employment_type']) as $key => $meta):
                  foreach (!empty($meta['subtypes']) ? array_keys($meta['subtypes']) : [''] as $sub):
                    $val = $key . '|' . $sub; ?>
                  <option value="<?= h($val) ?>" <?= $val === $current ? 'selected' : '' ?>><?= h(document_type_label($key, $sub ?: null)) ?></option>
                <?php endforeach; endforeach; ?>
              </select>
              <button class="btn btn-sm btn-outline-brand text-nowrap">
                <i class="fa-solid fa-check"></i> <?= $d['reviewed_at'] ? 'Update' : 'Mark Reviewed' ?>
              </button>
            </form>
            <div class="small text-muted mt-1">
              <?php if ($corrected): ?><span class="badge bg-info">Corrected</span><?php endif; ?>
              <?php if ($d['reviewed_at']): ?>
                Reviewed <?= h(date('M j, Y', strtotime($d['reviewed_at']))) ?><?= $d['reviewer_name'] ? ' by ' . h($d['reviewer_name']) : '' ?>
              <?php else: ?>
                Needs review
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
