<?php
/**
 * Faculty Home: their own 201 file only --
 *   - counts (201-Files, Flagged for checking, uploaded this month,
 *     archived); Flagged opens only that list in the pop-up
 *   - their reminders (AI-worded when AI is on) with Dismiss
 *     (includes/reminders_card.php)
 *   - their latest uploads (each opens its preview pop-up; View all opens the
 *     list pop-up) and the 201-file checklist (includes/checklist_card.php)
 *   - their expired / expiring documents
 * Every query is limited to the signed-in faculty member's own records.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_role('faculty');

$page_title = 'Home';
$me = current_user();
$uid = (int)$me['user_id'];

// Anything left waiting under the old approval workflow gets filed now.
file_outstanding_requests($pdo);
// Auto-archive of unopened documents, then expiration alerts -- each at most once a
// day system-wide (before the header, so the bell counts them)
run_daily_jobs($pdo);
$expiring = expiring_documents($pdo, $uid);
$card_admin = false;

$stmt = $pdo->prepare("SELECT status, COUNT(*) FROM documents WHERE faculty_id = ? GROUP BY status");
$stmt->execute([$uid]);
$by_status = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
$flagged_count = documents_flagged_count($pdo, $uid);
$stmt = $pdo->prepare("SELECT COUNT(*) FROM documents WHERE faculty_id = ? AND status IN ('active', 'for_review') AND filed_at >= ?");
$stmt->execute([$uid, date('Y-m-01')]);
$this_month = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare(
    "SELECT sr.request_id, sr.document_type_hint, sr.status, sr.submitted_at,
            d.document_id, d.document_type, d.document_subtype, d.title, d.file_path, d.status AS document_status
     FROM submission_requests sr
     LEFT JOIN documents d ON d.request_id = sr.request_id
     WHERE sr.faculty_id = ? ORDER BY sr.submitted_at DESC LIMIT 5"
);
$stmt->execute([$uid]);
$recent = $stmt->fetchAll();

$checklist = faculty_201_checklist($pdo, $uid, faculty_employment_type($pdo, $uid));

/** "Today, 9:12 AM" / "Yesterday, 4:51 PM" / "Oct 12, 2026". */
$when = function (string $at): string {
    $day = date('Y-m-d', strtotime($at));
    if ($day === date('Y-m-d')) { return 'Today, ' . date('g:i A', strtotime($at)); }
    if ($day === date('Y-m-d', strtotime('-1 day'))) { return 'Yesterday, ' . date('g:i A', strtotime($at)); }
    return date('M j, Y', strtotime($at));
};

include __DIR__ . '/../includes/header.php';
?>

<div class="ui-greet-row">
  <div>
    <div class="ui-greet-date"><?= h(date('l, F j, Y')) ?></div>
    <h2 class="ui-greet">Welcome back, <?= h(person_first_name($me)) ?></h2>
  </div>
  <div class="ui-intro-actions">
    <?php if (user_has_pds($pdo, (int)$me['user_id'])): /* part-time faculty have no PDS */ ?>
      <a href="<?= BASE_URL ?>/faculty/pds.php" class="btn btn-outline-brand"><i class="fa-solid fa-id-card" aria-hidden="true"></i> My PDS</a>
    <?php endif; ?>
    <a href="<?= BASE_URL ?>/faculty/submit_document.php" class="btn btn-brand"><i class="fa-solid fa-file-circle-plus" aria-hidden="true"></i> Upload Document</a>
  </div>
</div>

<div class="ui-stats">
  <a class="ui-stat" href="<?= BASE_URL ?>/faculty/my_documents.php">
    <div class="ui-stat-top"><span class="ui-stat-label">201-Files</span><span class="ui-stat-icon tone-navy" aria-hidden="true"><i class="fa-solid fa-folder-open"></i></span></div>
    <div class="ui-stat-value" data-count="<?= (int)($by_status['active'] ?? 0) ?>"><?= (int)($by_status['active'] ?? 0) ?></div>
    <div class="ui-stat-sub">posted to your 201 file</div>
  </a>
  <a class="ui-stat" href="<?= BASE_URL ?>/faculty/my_documents.php?tab=flagged" data-app-modal="<?= h(BASE_URL . '/api/modal.php?view=list&category=flagged') ?>" data-app-modal-title="Flagged for checking">
    <div class="ui-stat-top"><span class="ui-stat-label">Flagged for checking</span><span class="ui-stat-icon tone-gold" aria-hidden="true"><i class="fa-solid fa-flag"></i></span></div>
    <div class="ui-stat-value" data-count="<?= $flagged_count ?>"><?= $flagged_count ?></div>
    <div class="ui-stat-sub">in your 201-Files; the Admin may correct them</div>
  </a>
  <div class="ui-stat" role="group" aria-label="Uploaded This Month">
    <div class="ui-stat-top"><span class="ui-stat-label">Uploaded This Month</span><span class="ui-stat-icon tone-teal" aria-hidden="true"><i class="fa-solid fa-upload"></i></span></div>
    <div class="ui-stat-value" data-count="<?= $this_month ?>"><?= $this_month ?></div>
    <div class="ui-stat-sub">posted or waiting for review</div>
  </div>
  <a class="ui-stat" href="<?= BASE_URL ?>/archive.php">
    <div class="ui-stat-top"><span class="ui-stat-label">Archived Docs</span><span class="ui-stat-icon tone-gray" aria-hidden="true"><i class="fa-solid fa-box-archive"></i></span></div>
    <div class="ui-stat-value" data-count="<?= (int)($by_status['archived'] ?? 0) ?>"><?= (int)($by_status['archived'] ?? 0) ?></div>
    <div class="ui-stat-sub">not opened in <?= h(archive_period_label()) ?></div>
  </a>
</div>

<div class="ui-grid-home">
  <div class="ui-stack">
    <?php include __DIR__ . '/../includes/reminders_card.php'; ?>

    <section class="ui-card" aria-labelledby="recentTitle">
      <div class="ui-card-head">
        <span class="ui-head-icon tone-navy" aria-hidden="true"><i class="fa-solid fa-clock-rotate-left"></i></span>
        <h2 class="ui-card-title" id="recentTitle">My recent uploads</h2>
        <a href="<?= BASE_URL ?>/faculty/my_requests.php" class="btn btn-sm btn-outline-brand"
           data-app-modal="<?= h(BASE_URL . '/api/modal.php?view=list&category=recent_uploads') ?>" data-app-modal-title="Recent File Uploads">View all</a>
      </div>
      <div>
        <?php if (!$recent): ?>
          <?= admin_empty('No uploads yet', 'Documents you upload will appear here.', null, 'fa-upload') ?>
        <?php endif; ?>
        <?php foreach ($recent as $r): [$label, $badge] = upload_status_badge($r);
          $openable = $r['document_id'] && in_array($r['document_status'], ['active', 'for_review', 'archived'], true); ?>
          <div class="ui-recent-row"<?php if ($openable): ?> tabindex="0" role="button" data-app-modal="<?= h(BASE_URL . '/api/modal.php?view=document&id=' . (int)$r['document_id']) ?>"<?php endif; ?>>
            <div class="min-w-0">
              <div class="ui-recent-file"><?= $r['document_id'] ? h(document_title($r)) : '—' ?></div>
              <div class="ui-recent-meta"><?= h(document_type_label($r['document_type'] ?? $r['document_type_hint'], $r['document_subtype'])) ?></div>
            </div>
            <div class="ui-recent-side">
              <span class="badge <?= $badge ?>"><?= h($label) ?></span>
              <span class="ui-recent-when"><?= h($when($r['submitted_at'])) ?></span>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </section>
  </div>

  <?php include __DIR__ . '/../includes/checklist_card.php'; ?>
</div>

<?php include __DIR__ . '/../includes/expiring_documents_card.php'; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
