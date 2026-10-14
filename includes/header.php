<?php
/**
 * Top of every page. Expects $page_title to be set by the including page.
 *
 * Signed in, the same header for every role, upper right:
 *   [search] [bell] (initials avatar) [Log out]
 * Search: hovering or clicking the magnifier opens a box that searches
 * documents by title, faculty or document type -- the Admin everyone's,
 * anyone else only their own. Results open in the pop-up (assets/js/app.js
 * -> api/modal.php?view=search); without JavaScript the form opens the
 * role's document list with the same search. The avatar opens Profile
 * Preference; the bell, the notifications.
 */
$user = current_user();
$search_page = $user && $user['role'] === 'admin' ? 'admin/public_docs.php' : 'faculty/my_documents.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h($page_title ?? 'Faculty 201-File Repository') ?> — CCS, Universidad de Manila</title>
<link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
<link href="<?= BASE_URL ?>/assets/css/style.css?v=<?= (int)@filemtime(ROOT_PATH . '/assets/css/style.css') ?>" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-dark app-navbar">
  <div class="container-fluid flex-nowrap">
    <?php if ($user): ?>
    <!-- Mobile only: opens the sidebar as a slide-in menu -->
    <button class="btn btn-link text-white d-md-none px-1 me-2 sidebar-toggle" type="button"
            data-bs-toggle="offcanvas" data-bs-target="#appSidebar" aria-controls="appSidebar" aria-label="Open menu">
      <i class="fa-solid fa-bars fa-lg"></i>
    </button>
    <?php endif; ?>
    <a class="navbar-brand fw-bold text-truncate me-2" href="<?= BASE_URL ?>/index.php">
      <i class="fa-solid fa-folder-open"></i>
      <span class="d-none d-sm-inline">CCS Faculty 201-File Repository</span>
      <span class="d-sm-none">CCS 201-File</span>
    </a>
    <?php if ($user): ?>
    <div class="d-flex align-items-center gap-2 gap-md-3 ms-auto flex-shrink-0 header-actions">
      <?php if (empty($user['must_change_password'])): ?>
      <form class="header-search" id="headerSearch" role="search" method="GET" action="<?= h(BASE_URL . '/' . $search_page) ?>"
            data-endpoint="<?= h(BASE_URL . '/api/modal.php?view=search') ?>">
        <label for="headerSearchInput" class="visually-hidden">Search documents</label>
        <input type="search" name="q" id="headerSearchInput" class="form-control form-control-sm header-search-input" maxlength="100" autocomplete="off"
               placeholder="<?= $user['role'] === 'admin' ? 'Title, faculty or document type' : 'Search my documents' ?>">
        <button type="button" class="btn btn-link text-white px-1 header-search-btn" aria-controls="headerSearchInput" aria-expanded="false"
                data-tooltip data-bs-placement="bottom" title="Search documents" aria-label="Search documents">
          <i class="fa-solid fa-magnifying-glass fa-lg"></i>
        </button>
      </form>
      <?php endif; ?>
      <?php $unread = unread_notification_count($pdo, $user['user_id']); ?>
      <a href="<?= BASE_URL . '/' . role_notifications_path($user['role']) ?>" class="text-white position-relative px-1" data-tooltip data-bs-placement="bottom" title="Notifications<?= $unread ? ' (' . $unread . ' unread)' : '' ?>" aria-label="Notifications<?= $unread ? ' (' . $unread . ' unread)' : '' ?>">
        <i class="fa-solid fa-bell fa-lg"></i>
        <?php if ($unread): ?>
          <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size:.6rem;"><?= $unread > 9 ? '9+' : $unread ?></span>
        <?php endif; ?>
      </a>
      <a href="<?= BASE_URL ?>/profile.php" class="navbar-profile text-decoration-none" data-tooltip data-bs-placement="bottom"
         title="Profile Preference — <?= h($user['full_name']) ?>" aria-label="Profile Preference (<?= h($user['full_name']) ?>)">
        <span class="avatar avatar-initials avatar-navbar" aria-hidden="true"><?= h(user_initials($user['full_name'])) ?></span>
      </a>
      <a href="<?= BASE_URL ?>/logout.php" class="btn btn-sm btn-outline-light text-nowrap"><i class="fa-solid fa-right-from-bracket"></i><span class="d-none d-sm-inline"> Log out</span></a>
    </div>
    <?php endif; ?>
  </div>
</nav>
<div class="container-fluid">
  <div class="row">
    <?php if ($user): include __DIR__ . '/sidebar.php'; endif; ?>
    <main class="<?= $user ? 'col-12 col-md-9 col-lg-10 ms-sm-auto px-3 px-md-4' : 'col-12' ?> py-3 py-md-4 app-main">
      <?php if (!empty($_SESSION['flash_error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show"><?= h($_SESSION['flash_error']) ?>
          <button class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php unset($_SESSION['flash_error']); ?>
      <?php endif; ?>
      <?php if (!empty($_SESSION['flash_success'])): ?>
        <div class="alert alert-success alert-dismissible fade show"><?= h($_SESSION['flash_success']) ?>
          <button class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php unset($_SESSION['flash_success']); ?>
      <?php endif; ?>
