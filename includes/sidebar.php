<?php
/**
 * Left menu of every page after login, drawn from app_menu()
 * (includes/nav_menu.php) for the signed-in user's role.
 *
 * Look: every row -- links and section headers alike -- is a rounded,
 * full-width row with a round icon holder and a one-line label (styles:
 * "Sidebar menu" in assets/css/style.css). Sections (Files, Report, ...)
 * are real buttons that expand / collapse their links (Bootstrap collapse,
 * aria-expanded); the section holding the current page is always open, and
 * a section you close stays closed on the next page (saved in the browser
 * by assets/js/app.js). The current page's row is highlighted.
 * Static on md+; a slide-in (offcanvas) menu below md, opened by the
 * header's hamburger button.
 */
require_once __DIR__ . '/nav_menu.php';
$sb_user = current_user();
$sb_page = nav_current_page();
$sb_menu = app_menu($pdo, $sb_user);

/** One menu link: [round icon] label [badge]. */
$sb_link = function (array $item, bool $nested) use ($sb_page): string {
    $active = ($item['active'])($sb_page);
    $badge = (int)($item['badge'] ?? 0);
    return '<li><a class="sb-row' . ($nested ? ' sb-row-sub' : '') . ($active ? ' active' : '') . '"'
         . ' href="' . h(BASE_URL . '/' . $item['url']) . '"' . ($active ? ' aria-current="page"' : '') . '>'
         . '<span class="sb-icon" aria-hidden="true"><i class="fa-solid ' . h($item['icon']) . '"></i></span>'
         . '<span class="sb-label">' . h($item['label']) . '</span>'
         . ($badge ? '<span class="badge rounded-pill sb-badge ' . h($item['badge_class'] ?? 'bg-warning') . '" title="' . h($badge . ' ' . ($item['badge_title'] ?? '')) . '">'
                   . ($badge > 99 ? '99+' : $badge) . '<span class="visually-hidden"> ' . h($item['badge_title'] ?? '') . '</span></span>' : '')
         . '</a></li>';
};
?>
<nav class="col-md-3 col-lg-2 sidebar offcanvas-md offcanvas-start" id="appSidebar" tabindex="-1" aria-labelledby="appSidebarLabel">
  <div class="offcanvas-header sidebar-mobile-header d-md-none">
    <div class="text-truncate">
      <div class="fw-semibold text-truncate" id="appSidebarLabel"><?= h($sb_user['full_name']) ?></div>
      <div class="small text-white-50 text-capitalize"><?= h(str_replace('_', ' ', $sb_user['role'])) ?></div>
    </div>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" data-bs-target="#appSidebar" aria-label="Close menu"></button>
  </div>
  <div class="sidebar-body">
    <ul class="sb-menu" aria-label="Main menu">
      <?php foreach ($sb_menu as $i => $entry): ?>
        <?php if (!isset($entry['group'])): ?>
          <?= $sb_link($entry, false) ?>
        <?php else:
          $group_id = 'navGroup' . $i;
          $group_active = (bool)array_filter($entry['items'], fn($item) => ($item['active'])($sb_page)); ?>
          <li class="sb-group">
            <button class="sb-row sb-group-toggle" type="button" data-bs-toggle="collapse" data-bs-target="#<?= $group_id ?>"
                    aria-expanded="true" aria-controls="<?= $group_id ?>" data-group="<?= h($entry['group']) ?>">
              <span class="sb-icon" aria-hidden="true"><i class="fa-solid <?= h($entry['icon']) ?>"></i></span>
              <span class="sb-label"><?= h($entry['group']) ?></span>
              <i class="fa-solid fa-chevron-down sb-chevron" aria-hidden="true"></i>
            </button>
            <ul class="sb-sub collapse show" id="<?= $group_id ?>" data-group="<?= h($entry['group']) ?>"<?= $group_active ? ' data-has-active="1"' : '' ?>>
              <?php foreach ($entry['items'] as $item): ?>
                <?= $sb_link($item, true) ?>
              <?php endforeach; ?>
            </ul>
          </li>
        <?php endif; ?>
      <?php endforeach; ?>
      <li class="d-md-none sb-divider" role="separator"></li>
      <li class="d-md-none">
        <a class="sb-row sb-row-danger" href="<?= BASE_URL ?>/logout.php">
          <span class="sb-icon" aria-hidden="true"><i class="fa-solid fa-right-from-bracket"></i></span><span class="sb-label">Log out</span>
        </a>
      </li>
    </ul>
  </div>
</nav>
