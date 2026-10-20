<?php
/**
 * Program Chair / Dean Home. They keep their own 201 file like faculty do,
 * and follow the faculty they oversee (profile_scope_sql(): a Program Chair
 * their program, a Dean their college):
 *   - their own counts (201-Files, For Review, unread notifications, archived)
 *   - their own reminders (AI-worded when AI is on), each with a link to the
 *     page that fixes it and Dismiss (api/reminders.php, POST + CSRF token)
 *   - the faculty in their scope with an open reminder, per kind (each kind
 *     opens only that list in the pop-up) and per person
 *   - their own 201-file checklist and expiring documents
 * They are notified when faculty in their scope upload, but can't open
 * faculty files -- people link to the profile (can_view_profile()).
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_role(['program_chair', 'dean']);

$page_title = 'Home';
$me = current_user();

// Anything left waiting under the old approval workflow gets filed now.
file_outstanding_requests($pdo);
// Auto-archive of unopened documents, then expiration alerts -- each at most once a
// day system-wide (before the header, so the bell counts them)
run_daily_jobs($pdo);
$expiring = expiring_documents($pdo, (int)$me['user_id']);
$card_admin = false;

$self = user_row($pdo, (int)$me['user_id']);
$stmt = $pdo->prepare("SELECT status, COUNT(*) FROM documents WHERE faculty_id = ? GROUP BY status");
$stmt->execute([$me['user_id']]);
$by_status = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
$unread = unread_notification_count($pdo, $me['user_id']);
$unit = $me['role'] === 'dean' ? 'college' : 'program';

$checklist = faculty_201_checklist($pdo, (int)$me['user_id'], $self['employment_type'] ?? null);

$scope = $me['role'] === 'dean'
    ? (COLLEGES[$self['college'] ?? ''] ?? null)
    : (isset(PROGRAMS[$self['program'] ?? '']) ? PROGRAMS[$self['program']]['label'] : null);


// Follow-up: how many people in scope have each kind of open reminder (each opens that list in the pop-up),
// and who they are, with their open kinds as tags
$personal = personal_reminder_types();
$scope_counts = reminder_scope_counts($pdo, $me);
$summary_category = ['pds' => 'pds_update', 'missing_docs' => 'missing_documents', 'no_seminar' => 'no_recent_seminar', 'expiring' => 'expiring_followup'];
$follow_max = max(1, ...array_map('intval', array_values($scope_counts) ?: [0]));
$follow_people = [];
foreach ($personal as $type => $meta) {
    try {
        $rows = ($scope_counts[$type] ?? 0) ? reminder_scope_list($pdo, $me, $type) : [];
    } catch (PDOException $e) {
        $rows = [];   // migration not applied yet
    }
    foreach ($rows as $r) {
        $id = (int)$r['user_id'];
        $follow_people[$id] ??= ['user' => $r, 'issues' => []];
        $follow_people[$id]['issues'][] = $meta['label'];
    }
}
uasort($follow_people, fn($a, $b) => [count($b['issues']), $a['user']['full_name']] <=> [count($a['issues']), $b['user']['full_name']]);
$follow_shown = array_slice($follow_people, 0, 6, true);

include __DIR__ . '/../includes/header.php';
?>

<div class="ui-greet-row">
  <div>
    <div class="ui-greet-date"><?= h(date('l, F j, Y')) ?></div>
    <h2 class="ui-greet">Welcome back, <?= h(ai_first_name($me['full_name'])) ?></h2>
    <p class="ui-greet-sub">
      <?php if ($scope): ?>
        You're notified whenever faculty in <strong><?= h($scope) ?></strong> upload to their 201 file.
      <?php else: ?>
        Your <?= $unit ?> hasn't been set yet, so you won't receive upload notifications. Please ask the Admin to set it.
      <?php endif; ?>
    </p>
  </div>
  <div class="ui-intro-actions">
    <a href="<?= BASE_URL ?>/faculty_directory.php" class="btn btn-outline-brand"><i class="fa-solid fa-address-book" aria-hidden="true"></i> Faculty Profiles</a>
    <a href="<?= BASE_URL ?>/faculty/submit_document.php" class="btn btn-brand"><i class="fa-solid fa-file-circle-plus" aria-hidden="true"></i> Upload Document</a>
  </div>
</div>

<div class="ui-stats">
  <a class="ui-stat" href="<?= BASE_URL ?>/faculty/my_documents.php">
    <div class="ui-stat-top"><span class="ui-stat-label">My 201-Files</span><span class="ui-stat-icon tone-navy" aria-hidden="true"><i class="fa-solid fa-folder-open"></i></span></div>
    <div class="ui-stat-value" data-count="<?= (int)($by_status['active'] ?? 0) ?>"><?= (int)($by_status['active'] ?? 0) ?></div>
    <div class="ui-stat-sub">posted to your 201 file</div>
  </a>
  <a class="ui-stat" href="<?= BASE_URL ?>/faculty/my_documents.php?tab=pending" data-app-modal="<?= h(BASE_URL . '/api/modal.php?view=list&category=for_review') ?>" data-app-modal-title="For Review">
    <div class="ui-stat-top"><span class="ui-stat-label">For Review</span><span class="ui-stat-icon tone-gold" aria-hidden="true"><i class="fa-solid fa-inbox"></i></span></div>
    <div class="ui-stat-value" data-count="<?= (int)($by_status['for_review'] ?? 0) ?>"><?= (int)($by_status['for_review'] ?? 0) ?></div>
    <div class="ui-stat-sub">waiting for the Admin to post</div>
  </a>
  <a class="ui-stat" href="<?= BASE_URL ?>/approval/notifications.php">
    <div class="ui-stat-top"><span class="ui-stat-label">Unread Notifications</span><span class="ui-stat-icon tone-teal" aria-hidden="true"><i class="fa-solid fa-bell"></i></span></div>
    <div class="ui-stat-value" data-count="<?= (int)$unread ?>"><?= (int)$unread ?></div>
    <div class="ui-stat-sub">uploads in your <?= $unit ?> and more</div>
  </a>
  <a class="ui-stat" href="<?= BASE_URL ?>/archive.php">
    <div class="ui-stat-top"><span class="ui-stat-label">My Archived Docs</span><span class="ui-stat-icon tone-gray" aria-hidden="true"><i class="fa-solid fa-box-archive"></i></span></div>
    <div class="ui-stat-value" data-count="<?= (int)($by_status['archived'] ?? 0) ?>"><?= (int)($by_status['archived'] ?? 0) ?></div>
    <div class="ui-stat-sub">not opened in <?= h(archive_period_label()) ?></div>
  </a>
</div>

<div class="ui-grid-home">
  <div class="ui-stack">
    <?php include __DIR__ . '/../includes/reminders_card.php'; ?>

    <section class="ui-card" aria-labelledby="followTitle" data-animate>
      <div class="ui-card-head">
        <span class="ui-head-icon tone-navy" aria-hidden="true"><i class="fa-solid fa-user-clock"></i></span>
        <h2 class="ui-card-title" id="followTitle">Faculty needing follow-up</h2>
        <?php if ($scope): ?><span class="ui-card-meta"><?= h($scope) ?></span><?php endif; ?>
      </div>
      <?php if (!$scope): ?>
        <?= admin_empty('No ' . $unit . ' set', 'Ask the Admin to set your ' . $unit . ' to follow up its faculty.', null, 'fa-user-clock') ?>
      <?php else: ?>
        <div class="ui-breakdown ui-follow-kinds">
          <?php foreach ($personal as $type => $meta): $n = (int)($scope_counts[$type] ?? 0); ?>
            <?php if ($n): ?>
            <button type="button" class="ui-breakdown-row" data-app-modal="<?= h(BASE_URL . '/api/modal.php?view=list&category=' . $summary_category[$type]) ?>" data-app-modal-title="<?= h($meta['label']) ?>">
            <?php else: ?>
            <div class="ui-breakdown-row">
            <?php endif; ?>
              <span class="ui-head-icon" aria-hidden="true"><i class="fa-solid <?= h($meta['icon']) ?>"></i></span>
              <span class="ui-breakdown-label"><?= h($meta['label']) ?><span class="visually-hidden">: <?= $n ?> faculty</span></span>
              <span class="ui-bar" aria-hidden="true"><span style="--w: <?= round($n / $follow_max * 100) ?>%"></span></span>
              <span class="ui-breakdown-n" aria-hidden="true"><?= $n ?></span>
            <?= $n ? '</button>' : '</div>' ?>
          <?php endforeach; ?>
        </div>
        <?php if ($follow_shown): ?>
          <ul class="ui-people-list" aria-label="Faculty with open reminders">
            <?php foreach ($follow_shown as $id => $p): ?>
              <li>
                <a class="ui-person-row" href="<?= BASE_URL ?>/profile.php?id=<?= (int)$id ?>"
                   data-app-modal="<?= h(BASE_URL . '/api/modal.php?view=person&id=' . (int)$id) ?>" data-app-modal-size="lg" data-app-modal-title="Faculty profile">
                  <span class="ui-avatar-sm" aria-hidden="true"><?= h(user_initials($p['user']['full_name'])) ?></span>
                  <span class="ui-person-name"><?= h($p['user']['full_name']) ?></span>
                  <span class="ui-pillset">
                    <?php foreach ($p['issues'] as $issue): ?><span class="ui-pill"><?= h($issue) ?></span><?php endforeach; ?>
                  </span>
                </a>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php elseif ($reminder_status['checked_at'] !== null): ?>
          <p class="ui-card-note">No one in <?= h($scope) ?> needs follow-up on their 201 file right now.</p>
        <?php endif; ?>
        <div class="ui-card-foot">
          <span>Checked once a day; the list clears as faculty update their 201 files.<?= count($follow_people) > count($follow_shown) ? ' Showing ' . count($follow_shown) . ' of ' . count($follow_people) . '.' : '' ?></span>
          <div class="ui-ai-foot-btns">
            <a href="<?= BASE_URL ?>/reminder_list.php" class="btn btn-sm btn-outline-brand">Full list</a>
            <a href="<?= BASE_URL ?>/faculty_directory.php" class="btn btn-sm btn-outline-brand">Open Faculty Profiles</a>
          </div>
        </div>
      <?php endif; ?>
    </section>
  </div>

  <?php include __DIR__ . '/../includes/checklist_card.php'; ?>
</div>

<?php include __DIR__ . '/../includes/expiring_documents_card.php'; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
