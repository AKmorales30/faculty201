<?php
/**
 * Top of every page after login (every page includes it; all of them
 * require login), the same for every role -- Faculty-201 File Management
 * System. Expects $page_title to be set by the including page; $pdo from
 * includes/auth.php. Layout:
 *
 *   [sidebar: logos, system name, menu]  [top bar: page title · search · bell · avatar · Log out]
 *                                        [page]
 *
 * The menu is app_menu() (includes/nav_menu.php) for the user's role. Below
 * 992px the sidebar is a slide-out menu (Bootstrap offcanvas-lg), opened by
 * the round menu button in the top bar. The header search looks in every
 * document for the Admin and in their own 201 file for everyone else
 * (api/modal.php?view=search scopes it on the server); without JavaScript it
 * opens the role's document list with the same search. The avatar opens
 * Profile Preference; the bell, the notifications.
 * The ids / classes assets/js/app.js relies on: #appSidebar, .sb-menu /
 * .sb-sub / .sb-group-toggle (remembered closed sections), #headerSearch
 * (search pop-up), #appModal (footer).
 * Styles: assets/css/style.css, then assets/css/admin.css (body.admin-ui).
 */
$user = current_user();
require_once __DIR__ . '/admin_ui.php';
require_once __DIR__ . '/nav_menu.php';
$ad_page = nav_current_page();
$ad_menu = app_menu($pdo, $user);
$ad_unread = unread_notification_count($pdo, (int)$user['user_id']);
$ad_udm = admin_logo_url('udm-logo');
$ad_ccs = admin_logo_url('ccs-logo');
$ad_title = $page_title ?? 'Home';
$ad_avatar_user = ['user_id' => $user['user_id'], 'full_name' => $user['full_name'], 'profile_picture' => current_user_picture($pdo)];
$ad_is_admin = $user['role'] === 'admin';
$ad_search_page = $ad_is_admin ? 'admin/public_docs.php' : 'faculty/my_documents.php';
$ad_search_ph = $ad_is_admin ? 'Title, faculty or document type' : 'Search my documents';

/** One menu link: (round icon) label [count]. */
$ad_link = function (array $item, bool $nested) use ($ad_page): string {
    $active = ($item['active'])($ad_page);
    $badge = (int)($item['badge'] ?? 0);
    return '<li><a class="sb-row' . ($nested ? ' sb-row-sub' : '') . ($active ? ' active' : '') . '"'
         . ' href="' . h(BASE_URL . '/' . $item['url']) . '"' . ($active ? ' aria-current="page"' : '') . '>'
         . '<span class="sb-icon" aria-hidden="true"><i class="fa-solid ' . h($item['icon']) . '"></i></span>'
         . '<span class="sb-label">' . h($item['label']) . '</span>'
         . ($badge ? '<span class="sb-count" title="' . h($badge . ' ' . ($item['badge_title'] ?? '')) . '">'
                   . ($badge > 99 ? '99+' : $badge) . '<span class="visually-hidden"> ' . h($item['badge_title'] ?? '') . '</span></span>' : '')
         . '</a></li>';
};
?>
<!DOCTYPE html>
<html lang="en" class="no-js">
<head>
<meta charset="UTF-8">
<script>document.documentElement.className = document.documentElement.className.replace('no-js', 'js');</script>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h($ad_title) ?> — <?= h(SYSTEM_NAME) ?></title>
<?php if ($ad_udm): ?><link rel="icon" href="<?= h($ad_udm) ?>"><?php endif; ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=Public+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
<link href="<?= h(admin_asset('assets/css/style.css')) ?>" rel="stylesheet">
<link href="<?= h(admin_asset('assets/css/admin.css')) ?>" rel="stylesheet">
</head>
<body class="admin-ui role-<?= h(str_replace('_', '-', $user['role'])) ?>">
<a class="ad-skip" href="#adContent">Skip to content</a>
<div class="ad-app">

  <aside class="ad-sidebar offcanvas-lg offcanvas-start" id="appSidebar" tabindex="-1" aria-label="Main menu">
    <div class="ad-brand">
      <div class="ad-brand-logos">
        <?php if ($ad_udm): ?><img src="<?= h($ad_udm) ?>" alt="Universidad de Manila seal" width="42" height="42"><?php endif; ?>
        <?php if ($ad_ccs): ?><img src="<?= h($ad_ccs) ?>" alt="College of Computing Studies logo" width="42" height="42" class="ad-logo-contain"><?php endif; ?>
        <button type="button" class="ad-round-btn ad-drawer-close d-lg-none" data-bs-dismiss="offcanvas" data-bs-target="#appSidebar" aria-label="Close menu">
          <i class="fa-solid fa-xmark" aria-hidden="true"></i>
        </button>
      </div>
      <a class="ad-brand-name" href="<?= h(BASE_URL . '/' . portal_home_path()) ?>"><?= h(SYSTEM_NAME) ?></a>
      <div class="ad-brand-sub">Universidad de Manila · College of Computing Studies</div>
    </div>
    <nav class="ad-nav" aria-label="Main menu">
      <ul class="sb-menu">
        <?php foreach ($ad_menu as $i => $entry): ?>
          <?php if (!isset($entry['group'])): ?>
            <?= $ad_link($entry, false) ?>
          <?php else:
            $group_id = 'navGroup' . $i;
            $group_active = (bool)array_filter($entry['items'], fn($item) => ($item['active'])($ad_page)); ?>
            <li class="sb-group">
              <button class="sb-row sb-group-toggle<?= $group_active ? ' has-active' : '' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#<?= $group_id ?>"
                      aria-expanded="true" aria-controls="<?= $group_id ?>" data-group="<?= h($entry['group']) ?>">
                <span class="sb-icon" aria-hidden="true"><i class="fa-solid <?= h($entry['icon']) ?>"></i></span>
                <span class="sb-label"><?= h($entry['group']) ?></span>
                <span class="sb-chevron" aria-hidden="true"><i class="fa-solid fa-chevron-down"></i></span>
              </button>
              <ul class="sb-sub collapse show" id="<?= $group_id ?>" data-group="<?= h($entry['group']) ?>"<?= $group_active ? ' data-has-active="1"' : '' ?>>
                <?php foreach ($entry['items'] as $item): ?>
                  <?= $ad_link($item, true) ?>
                <?php endforeach; ?>
              </ul>
            </li>
          <?php endif; ?>
        <?php endforeach; ?>
      </ul>
    </nav>
  </aside>

  <div class="ad-main">
    <header class="ad-topbar">
      <button class="ad-round-btn ad-menu-btn d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#appSidebar" aria-controls="appSidebar" aria-label="Open menu">
        <i class="fa-solid fa-bars" aria-hidden="true"></i>
      </button>
      <h1 class="ad-title"><?= h($ad_title) ?></h1>
      <div class="ad-actions">
        <?php if (empty($user['must_change_password'])): ?>
        <form class="header-search" id="headerSearch" role="search" method="GET" action="<?= h(BASE_URL . '/' . $ad_search_page) ?>"
              data-endpoint="<?= h(BASE_URL . '/api/modal.php?view=search') ?>">
          <label for="headerSearchInput" class="visually-hidden">Search documents</label>
          <input type="search" name="q" id="headerSearchInput" class="header-search-input" maxlength="100" autocomplete="off" placeholder="<?= h($ad_search_ph) ?>">
          <button type="button" class="header-search-btn" aria-controls="headerSearchInput" aria-expanded="false"
                  data-tooltip data-bs-placement="bottom" title="Search documents" aria-label="Search documents">
            <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
          </button>
        </form>
        <?php endif; ?>
        <a href="<?= h(BASE_URL . '/' . role_notifications_path($user['role'])) ?>" class="ad-round-btn ad-bell" data-tooltip data-bs-placement="bottom"
           title="Notifications<?= $ad_unread ? ' (' . $ad_unread . ' unread)' : '' ?>" aria-label="Notifications<?= $ad_unread ? ', ' . $ad_unread . ' unread' : '' ?>">
          <i class="fa-regular fa-bell" aria-hidden="true"></i>
          <?php if ($ad_unread): ?><span class="ad-bell-count" aria-hidden="true"><?= $ad_unread > 9 ? '9+' : $ad_unread ?></span><?php endif; ?>
        </a>
        <a href="<?= BASE_URL ?>/profile.php" class="ad-avatar" data-tooltip data-bs-placement="bottom"
           title="Profile Preference — <?= h($user['full_name']) ?>" aria-label="Profile Preference — <?= h($user['full_name']) ?>">
          <?= user_avatar($ad_avatar_user, 38) ?>
        </a>
        <a href="<?= BASE_URL ?>/logout.php" class="ad-logout" aria-label="Log out">
          <i class="fa-solid fa-arrow-right-from-bracket" aria-hidden="true"></i><span class="ad-logout-text">Log out</span>
        </a>
      </div>
    </header>

    <main class="app-main ad-content" id="adContent" tabindex="-1">
      <?php if (!empty($_SESSION['flash_success'])): ?>
        <div class="ad-toast" role="status" aria-live="polite">
          <span class="ad-toast-icon" aria-hidden="true"><i class="fa-solid fa-circle-check"></i></span>
          <div class="ad-toast-text"><?= h($_SESSION['flash_success']) ?></div>
          <button type="button" class="ad-toast-close" aria-label="Dismiss"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
        </div>
        <?php unset($_SESSION['flash_success']); ?>
      <?php endif; ?>
      <div class="ad-page">
      <?php if (!empty($_SESSION['flash_error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert"><i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i> <?= h($_SESSION['flash_error']) ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>
        <?php unset($_SESSION['flash_error']); ?>
      <?php endif; ?>
      <?php // No verified email (e.g. "Not now" at login): a reminder on the dashboard, until closed for this login
      if (!empty($_SESSION['user']) && empty($_SESSION['user']['email']) && empty($_SESSION['hide_security_banner'])
          && basename($_SERVER['PHP_SELF']) === 'dashboard.php'): ?>
        <div class="alert alert-warning alert-dismissible fade show d-flex align-items-start gap-2" role="status" id="securityBanner">
          <i class="fa-solid fa-shield-halved mt-1" aria-hidden="true"></i>
          <div><strong>Secure your account:</strong> set up your email for two-factor authentication.
            <a href="<?= BASE_URL ?>/profile.php#security" class="alert-link">Go to Profile Preference</a></div>
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Dismiss"></button>
        </div>
        <script>
        // Closing it hides it for the rest of this login (profile.php, action hide_security_banner)
        document.getElementById('securityBanner').addEventListener('closed.bs.alert', function () {
          var body = new FormData();
          body.append('action', 'hide_security_banner');
          body.append('csrf_token', <?= json_encode(csrf_token()) ?>);
          fetch(<?= json_encode(BASE_URL . '/profile.php') ?>, { method: 'POST', body: body, headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
        });
        </script>
      <?php endif; ?>
