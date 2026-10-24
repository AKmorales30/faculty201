<?php
/**
 * Analytics for Program Chairs and Deans (Report > Analytics): summary
 * cards, charts of their faculty's 201-Files and seminars / trainings, and
 * the faculty needing attention. The page is the frame; the numbers come
 * from api/analytics.php (assets/js/analytics.js), which works them out on
 * the server for the viewer's scope only (includes/analytics.php):
 * a Program Chair their program, a Dean their college. With one program
 * (PROGRAMS, config.php) both see the same faculty, so there's no program
 * filter. The date filter is a GET form, so the address can be bookmarked.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/analytics.php';
require_role(['program_chair', 'dean']);

$page_title = 'Analytics';
$me = current_user();
$is_dean = $me['role'] === 'dean';
[$from, $to] = analytics_range(is_string($_GET['from'] ?? null) ? $_GET['from'] : null, is_string($_GET['to'] ?? null) ? $_GET['to'] : null);
$scope_label = analytics_scope_label($pdo, $me);
$self = user_row($pdo, (int)$me['user_id']);
$scope_set = $is_dean ? !empty($self['college']) : !empty($self['program']);

/** One chart card. $wide: spans both columns. $extra: HTML after the title (e.g. a toggle). */
$chart_card = function (string $id, string $title, string $icon, string $tone, string $aria, bool $wide = false, string $extra = '', string $note = ''): string {
    return '<section class="ui-card an-card' . ($wide ? ' an-wide' : '') . '" aria-labelledby="' . h($id) . 'Title" data-animate>'
         . '<div class="ui-card-head"><span class="ui-head-icon tone-' . h($tone) . '" aria-hidden="true"><i class="fa-solid ' . h($icon) . '"></i></span>'
         . '<h2 class="ui-card-title" id="' . h($id) . 'Title">' . h($title) . '</h2>' . $extra . '</div>'
         . '<div class="ui-chart is-loading" data-chart-wrap aria-busy="true">'
         . '<canvas id="' . h($id) . '" role="img" aria-label="' . h($aria) . '"></canvas>'
         . '<div class="ui-chart-empty" hidden><i class="fa-regular fa-chart-bar" aria-hidden="true"></i> No data available</div>'
         . '</div>' . ($note !== '' ? '<p class="an-note">' . h($note) . '</p>' : '') . '</section>';
};

include __DIR__ . '/../includes/header.php';
?>

<?= admin_intro('Charts of the 201-Files and seminars / trainings of the faculty in <strong>' . h($scope_label) . '</strong>. Documents count only when posted to 201-Files (not archived or deleted).') ?>

<?php if (!$scope_set): ?>
  <?= admin_empty('No ' . ($is_dean ? 'college' : 'program') . ' set', 'Ask the Admin to set your ' . ($is_dean ? 'college' : 'program') . ' to see its analytics.', null, 'fa-chart-line') ?>
<?php else: ?>

<form class="ui-card an-filters" method="GET" id="analyticsFilters" aria-label="Analytics filters">
  <div class="an-filter">
    <label for="anFrom" class="form-label small fw-semibold mb-1">From</label>
    <input type="date" id="anFrom" name="from" class="form-control form-control-sm" value="<?= h($from) ?>" max="<?= h(date('Y-m-d')) ?>">
  </div>
  <div class="an-filter">
    <label for="anTo" class="form-label small fw-semibold mb-1">To</label>
    <input type="date" id="anTo" name="to" class="form-control form-control-sm" value="<?= h($to) ?>" max="<?= h(date('Y-m-d')) ?>">
  </div>
  <div class="an-filter">
    <span class="form-label small fw-semibold mb-1 d-block"><?= $is_dean ? 'College' : 'Program' ?></span>
    <span class="an-locked"><i class="fa-solid fa-lock" aria-hidden="true"></i> <?= h($scope_label) ?></span>
  </div>
  <div class="an-filter an-filter-actions">
    <button class="btn btn-sm btn-brand"><i class="fa-solid fa-filter" aria-hidden="true"></i> Apply</button>
    <a href="<?= BASE_URL ?>/approval/analytics.php" class="btn btn-sm btn-outline-secondary">Reset</a>
  </div>
  <p class="an-status" id="anStatus" role="status" aria-live="polite"></p>
</form>

<noscript><div class="alert alert-warning">The charts need JavaScript. Please turn it on to see the analytics.</div></noscript>

<div id="analyticsRoot" data-endpoint="<?= h(BASE_URL . '/api/analytics.php') ?>">
  <div class="ui-stats an-stats" id="anCards">
    <?php foreach ([
        ['faculty', 'Total faculty', 'fa-users', 'navy', 'active accounts in scope'],
        ['documents', 'Documents in 201-Files', 'fa-folder-open', 'blue', 'posted, not archived'],
        ['trainings', 'Seminars / trainings', 'fa-chalkboard-user', 'gold', 'in the selected period'],
        ['expiring', 'Expiring / expired', 'fa-hourglass-half', 'red', 'documents, within 30 days'],
        ['pds_incomplete', 'Incomplete PDS', 'fa-id-card', 'teal', 'full-time faculty only'],
      ] as [$key, $label, $icon, $tone, $sub]): ?>
      <div class="ui-stat an-stat is-loading" data-card="<?= h($key) ?>" aria-busy="true">
        <div class="ui-stat-top"><span class="ui-stat-label"><?= h($label) ?></span><span class="ui-stat-icon tone-<?= h($tone) ?>" aria-hidden="true"><i class="fa-solid <?= h($icon) ?>"></i></span></div>
        <div class="ui-stat-value" data-value>&nbsp;</div>
        <div class="ui-stat-sub" data-sub><?= h($sub) ?></div>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="an-grid">
    <?= $chart_card('chUploads', 'Documents uploaded per month', 'fa-chart-line', 'navy', 'Line chart of documents uploaded per month', true) ?>
    <?= $chart_card('chCategories', 'Documents per category', 'fa-folder-tree', 'blue', 'Doughnut chart of documents per category in 201-Files') ?>
    <?= $chart_card('chExpiration', 'Expiration overview', 'fa-hourglass-half', 'red', 'Doughnut chart of documents that are valid, expiring within 30 days, or expired', false, '', 'Documents with an expiration date only.') ?>
    <?= $chart_card('chTrainings', 'Seminars / trainings attended', 'fa-chalkboard-user', 'gold', 'Bar chart of seminars and trainings attended over time') ?>
    <?= $chart_card('chOrganizers', 'Top training organizers', 'fa-building-columns', 'teal', 'Horizontal bar chart of the organizers with the most seminars and trainings') ?>
    <?= $chart_card('chFaculty', 'Faculty by rank', 'fa-user-tie', 'navy', 'Doughnut chart of faculty by academic rank', false,
        '<div class="btn-group btn-group-sm an-toggle" role="group" aria-label="Group faculty by">'
        . '<button type="button" class="btn btn-outline-brand active" data-group="ranks" aria-pressed="true">Rank</button>'
        . '<button type="button" class="btn btn-outline-brand" data-group="employment" aria-pressed="false">Employment</button></div>') ?>

    <section class="ui-card an-card" aria-labelledby="anAttentionTitle" data-animate>
      <div class="ui-card-head">
        <span class="ui-head-icon tone-gold" aria-hidden="true"><i class="fa-solid fa-user-clock"></i></span>
        <h2 class="ui-card-title" id="anAttentionTitle">Faculty needing attention</h2>
      </div>
      <div class="an-attention is-loading" id="anAttention" aria-busy="true"></div>
      <p class="an-note">From the daily reminder check. Click a row to see who. PDS: full-time faculty only.</p>
    </section>

    <?= $chart_card('chEmployment', 'Comparison by employment type', 'fa-scale-balanced', 'blue',
        'Grouped bar chart of faculty, documents and seminars / trainings for full-time faculty, part-time faculty and Program Chairs', true, '',
        'Faculty and documents in 201-Files: now. Seminars / trainings: in the selected period.') ?>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script src="<?= h(admin_asset('assets/js/charts.js')) ?>"></script>
<script src="<?= h(admin_asset('assets/js/analytics.js')) ?>"></script>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
