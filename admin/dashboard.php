<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_role('admin');

$page_title = 'Home';

// Anything left waiting under the old approval workflow gets filed now.
file_outstanding_requests($pdo);
// Auto-archive of unopened documents, then expiration alerts -- each at most once a
// day system-wide (before the header, so the bell counts them)
run_daily_jobs($pdo);
$expiring = expiring_documents($pdo);
$card_admin = true;

$total_faculty = $pdo->query("SELECT COUNT(*) FROM users WHERE role='faculty' AND is_active=1")->fetchColumn();
$full_time     = $pdo->query("SELECT COUNT(*) FROM users WHERE role='faculty' AND employment_type='full_time' AND is_active=1")->fetchColumn();
$part_time     = $pdo->query("SELECT COUNT(*) FROM users WHERE role='faculty' AND employment_type='part_time' AND is_active=1")->fetchColumn();
$month_count   = $pdo->query("SELECT COUNT(*) FROM documents WHERE status='active' AND filed_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')")->fetchColumn();
$filed_count   = $pdo->query("SELECT COUNT(*) FROM documents WHERE status='active'")->fetchColumn();
$review_count  = documents_for_review_count($pdo);
$alert_count   = unresolved_security_alert_count($pdo);

$recent = $pdo->query(
    "SELECT sr.request_id, sr.document_type_hint, sr.status, sr.submitted_at, u.full_name,
            d.document_id, d.document_type, d.document_subtype, d.title, d.file_path, d.status AS document_status
     FROM submission_requests sr
     JOIN users u ON u.user_id = sr.faculty_id
     LEFT JOIN documents d ON d.request_id = sr.request_id
     ORDER BY sr.submitted_at DESC LIMIT 6"
)->fetchAll();

// AI Overview: the Admin's own follow-up reminders (AI-worded when AI is on), when the daily check last ran,
// and how many faculty have each kind of open reminder (each opens only that list in the pop-up)
$me = current_user();
$ai_messages = open_reminders_for_user($pdo, (int)$me['user_id']);
$ai_status = reminder_check_status($pdo, (int)$me['user_id']);
$scope_counts = reminder_scope_counts($pdo, $me);
$summary_category = ['pds' => 'pds_update', 'missing_docs' => 'missing_documents', 'no_seminar' => 'no_recent_seminar', 'expiring' => 'expiring_followup'];
$breakdown = [];
foreach (personal_reminder_types() as $type => $meta) {
    $breakdown[$type] = ['meta' => $meta, 'n' => (int)($scope_counts[$type] ?? 0)];
}
$breakdown_max = max(1, ...array_column($breakdown, 'n'));

// Uploads per month: the upload log over the last 6 months (this month included)
$month_start = (new DateTimeImmutable('first day of this month'))->modify('-5 months');
$stmt = $pdo->prepare("SELECT DATE_FORMAT(submitted_at, '%Y-%m') AS ym, COUNT(*) FROM submission_requests WHERE submitted_at >= ? GROUP BY ym");
$stmt->execute([$month_start->format('Y-m-d 00:00:00')]);
$per_month = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
$months = [];
for ($i = 0; $i < 6; $i++) {
    $m = $month_start->modify("+$i months");
    $months[] = ['label' => $m->format('M'), 'n' => (int)($per_month[$m->format('Y-m')] ?? 0), 'current' => $i === 5];
}
$months_max = max(1, ...array_column($months, 'n'));
$months_range = $month_start->format('M') . ' – ' . date('M Y');

// Documents per category: posted documents (201-Files)
$categories = document_categories();
$category_colors = ['Certificate' => '#D99C0B', 'FTA' => '#0077AE', 'Diploma' => '#003283', 'TOR' => '#006560', 'PDS' => '#5B7DB8',
                    'IPCR' => '#6FA9A4', 'Contract' => '#8A6206', 'Affidavit' => '#C9A55A', 'Other' => '#B8BEC9'];
$per_category = $pdo->query("SELECT document_type, COUNT(*) AS n FROM documents WHERE status = 'active' GROUP BY document_type ORDER BY n DESC, document_type")->fetchAll(PDO::FETCH_KEY_PAIR);
$donut = [];
$angle = 0.0;
foreach ($per_category as $type => $n) {
    $color = $category_colors[$type] ?? '#98A2B3';
    $sweep = $filed_count ? $n / $filed_count * 360 : 0;
    $donut[] = sprintf('%s %.2fdeg %.2fdeg', $color, $angle, $angle + $sweep);
    $angle += $sweep;
}
$donut_css = $donut ? 'conic-gradient(' . implode(', ', $donut) . ')' : '#ECEEF2';

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
    <h2 class="ui-greet">Welcome back, <?= h(ai_first_name($me['full_name'])) ?></h2>
  </div>
  <div class="ui-intro-actions">
    <a href="<?= BASE_URL ?>/admin/manage_documents.php" class="btn btn-outline-brand"><i class="fa-solid fa-list-check" aria-hidden="true"></i> Manage Document<?= $review_count ? ' (' . (int)$review_count . ')' : '' ?></a>
    <a href="<?= BASE_URL ?>/admin/reports.php" class="btn btn-brand"><i class="fa-solid fa-file-lines" aria-hidden="true"></i> Generate Report</a>
  </div>
</div>

<div class="ui-stats">
  <div class="ui-stat" role="group" aria-label="Total Faculty">
    <div class="ui-stat-top"><span class="ui-stat-label">Total Faculty</span><span class="ui-stat-icon tone-navy" aria-hidden="true"><i class="fa-solid fa-users"></i></span></div>
    <div class="ui-stat-value" data-count="<?= (int)$total_faculty ?>"><?= (int)$total_faculty ?></div>
    <div class="ui-stat-sub"><?= (int)$full_time ?> full-time · <?= (int)$part_time ?> part-time</div>
  </div>
  <a class="ui-stat" href="<?= BASE_URL ?>/admin/manage_documents.php" data-app-modal="<?= h(BASE_URL . '/api/modal.php?view=list&category=for_review') ?>" data-app-modal-title="For Review">
    <div class="ui-stat-top"><span class="ui-stat-label">For Review</span><span class="ui-stat-icon tone-gold" aria-hidden="true"><i class="fa-solid fa-inbox"></i></span></div>
    <div class="ui-stat-value" data-count="<?= (int)$review_count ?>"><?= (int)$review_count ?></div>
    <div class="ui-stat-sub">new uploads to check and post</div>
  </a>
  <div class="ui-stat" role="group" aria-label="Posted This Month">
    <div class="ui-stat-top"><span class="ui-stat-label">Posted This Month</span><span class="ui-stat-icon tone-teal" aria-hidden="true"><i class="fa-solid fa-upload"></i></span></div>
    <div class="ui-stat-value" data-count="<?= (int)$month_count ?>"><?= (int)$month_count ?></div>
    <div class="ui-stat-sub">this month's uploads now in 201-Files</div>
  </div>
  <a class="ui-stat" href="<?= BASE_URL ?>/admin/public_docs.php">
    <div class="ui-stat-top"><span class="ui-stat-label">201-Files</span><span class="ui-stat-icon tone-blue" aria-hidden="true"><i class="fa-solid fa-folder-open"></i></span></div>
    <div class="ui-stat-value" data-count="<?= (int)$filed_count ?>"><?= (int)$filed_count ?></div>
    <div class="ui-stat-sub">posted documents</div>
  </a>
</div>

<?php if ($alert_count > 0): ?>
<a href="<?= BASE_URL ?>/admin/security_alerts.php" class="ui-alert-banner">
  <span class="ui-alert-banner-icon" aria-hidden="true"><i class="fa-solid fa-shield-halved"></i></span>
  <span><strong><?= (int)$alert_count ?> security alert<?= $alert_count === 1 ? '' : 's' ?></strong> need<?= $alert_count === 1 ? 's' : '' ?> your review.</span>
  <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
</a>
<?php endif; ?>

<div class="ui-grid-2">
  <section class="ui-card" aria-labelledby="aiTitle" data-animate>
    <div class="ui-card-head">
      <span class="ui-head-icon tone-gold" aria-hidden="true"><i class="fa-solid fa-wand-magic-sparkles"></i></span>
      <h2 class="ui-card-title" id="aiTitle">AI Overview</h2>
      <?php if ($ai_status['checked_at']): ?><span class="ui-card-meta">Checked <?= h(time_ago($ai_status['checked_at'])) ?></span><?php endif; ?>
    </div>
    <div class="ui-ai-body">
      <?php if ($ai_messages): ?>
        <?php foreach (array_slice($ai_messages, 0, 2) as $r): ?>
          <p class="ui-ai-text"><?= h($r['message']) ?></p>
        <?php endforeach; ?>
      <?php elseif ($ai_status['checked_at'] === null): ?>
        <p class="ui-ai-text">The daily follow-up check hasn't run yet. Run it now to see which faculty need follow-up on their 201 files.</p>
      <?php else: ?>
        <p class="ui-ai-text">No faculty need follow-up on their 201 files right now.</p>
      <?php endif; ?>

      <div class="ui-breakdown">
        <?php foreach ($breakdown as $type => $b): $n = $b['n']; ?>
          <?php if ($n): ?>
          <button type="button" class="ui-breakdown-row" data-app-modal="<?= h(BASE_URL . '/api/modal.php?view=list&category=' . $summary_category[$type]) ?>" data-app-modal-title="<?= h($b['meta']['label']) ?>">
          <?php else: ?>
          <div class="ui-breakdown-row">
          <?php endif; ?>
            <span class="ui-head-icon" aria-hidden="true"><i class="fa-solid <?= h($b['meta']['icon']) ?>"></i></span>
            <span class="ui-breakdown-label"><?= h($b['meta']['label']) ?><span class="visually-hidden">: <?= $n ?> faculty</span></span>
            <span class="ui-bar" aria-hidden="true"><span style="--w: <?= round($n / $breakdown_max * 100) ?>%"></span></span>
            <span class="ui-breakdown-n" aria-hidden="true"><?= $n ?></span>
          <?= $n ? '</button>' : '</div>' ?>
        <?php endforeach; ?>
      </div>

      <div class="ui-ai-foot">
        <span><?= array_filter(array_column($ai_messages, 'ai_generated')) ? 'AI-generated summary. Check the details before acting on it.' : 'Checked once a day; the list clears as faculty update their 201 files.' ?></span>
        <div class="ui-ai-foot-btns">
          <form method="POST" action="<?= BASE_URL ?>/api/reminders.php">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="run_check">
            <button class="btn btn-sm btn-outline-brand" title="Check every account now instead of waiting for tomorrow (already-sent reminders are not repeated)"><i class="fa-solid fa-rotate" aria-hidden="true"></i> Run check now</button>
          </form>
          <a href="<?= BASE_URL ?>/reminder_list.php" class="btn btn-sm btn-outline-brand">Faculty needing follow-up</a>
        </div>
      </div>
    </div>
  </section>

  <section class="ui-card" aria-labelledby="monthsTitle" data-animate>
    <div class="ui-card-head">
      <h2 class="ui-card-title" id="monthsTitle">Uploads per month</h2>
      <span class="ui-card-meta"><?= h($months_range) ?></span>
    </div>
    <div class="ui-chart-body">
      <div class="ui-columns" role="img" aria-label="Uploads per month: <?= h(implode(', ', array_map(fn($m) => $m['label'] . ' ' . $m['n'], $months))) ?>">
        <?php foreach ($months as $m): ?>
          <div class="ui-col">
            <div class="ui-col-plot">
              <span class="ui-col-value"><?= $m['n'] ?></span>
              <span class="ui-col-bar<?= $m['current'] ? ' is-current' : '' ?>" style="--h: <?= round($m['n'] / $months_max * 100) ?>%"></span>
            </div>
            <span class="ui-col-label"><?= h($m['label']) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </section>
</div>

<div class="ui-grid-2">
  <section class="ui-card" aria-labelledby="recentTitle">
    <div class="ui-card-head">
      <h2 class="ui-card-title" id="recentTitle">Recent file uploads</h2>
      <button type="button" class="btn btn-sm btn-outline-brand" data-app-modal="<?= h(BASE_URL . '/api/modal.php?view=list&category=recent_uploads') ?>" data-app-modal-title="Recent File Uploads">View all</button>
    </div>
    <div>
      <?php if (!$recent): ?>
        <?= admin_empty('No uploads yet', 'New uploads from faculty will appear here.') ?>
      <?php endif; ?>
      <?php foreach ($recent as $r): [$label, $badge] = upload_status_badge($r); ?>
        <div class="ui-recent-row"<?php if ($r['document_id']): ?> tabindex="0" role="button" data-app-modal="<?= h(BASE_URL . '/api/modal.php?view=document&id=' . (int)$r['document_id']) ?>"<?php endif; ?>>
          <div class="min-w-0">
            <div class="ui-recent-file"><?= $r['document_id'] ? h(document_title($r)) : '—' ?></div>
            <div class="ui-recent-meta"><?= h($r['full_name']) ?> · <?= h(document_type_label($r['document_type'] ?? $r['document_type_hint'], $r['document_subtype'])) ?></div>
          </div>
          <div class="ui-recent-side">
            <span class="badge <?= $badge ?>"><?= h($label) ?></span>
            <span class="ui-recent-when"><?= h($when($r['submitted_at'])) ?></span>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="ui-card" aria-labelledby="catTitle" data-animate>
    <div class="ui-card-head">
      <h2 class="ui-card-title" id="catTitle">Documents per category</h2>
      <span class="ui-card-meta">201-Files</span>
    </div>
    <div class="ui-donut-wrap">
      <div class="ui-donut" style="background: <?= h($donut_css) ?>" role="img" aria-label="<?= (int)$filed_count ?> posted documents by category">
        <div class="ui-donut-hole"><span class="ui-donut-total"><?= (int)$filed_count ?></span><span class="ui-donut-caption">documents</span></div>
      </div>
      <ul class="ui-legend">
        <?php if (!$per_category): ?><li class="text-muted">No posted documents yet.</li><?php endif; ?>
        <?php foreach ($per_category as $type => $n): ?>
          <li>
            <span class="ui-legend-swatch" style="background: <?= h($category_colors[$type] ?? '#98A2B3') ?>" aria-hidden="true"></span>
            <span class="ui-legend-label"><?= h($categories[$type]['short'] ?? $type) ?></span>
            <span class="ui-legend-n"><?= (int)$n ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  </section>
</div>

<?php include __DIR__ . '/../includes/expiring_documents_card.php'; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
