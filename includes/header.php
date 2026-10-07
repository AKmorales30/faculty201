<?php
/** Expects $page_title to be set by the including page. */
$user = current_user();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h($page_title ?? 'Faculty 201-File Repository') ?> — CCS, Universidad de Manila</title>
<link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
<link href="<?= BASE_URL ?>/assets/css/style.css" rel="stylesheet">
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
    <div class="d-flex align-items-center gap-2 gap-md-3 ms-auto flex-shrink-0">
      <?php $unread = unread_notification_count($pdo, $user['user_id']); ?>
      <a href="<?= BASE_URL . '/' . role_notifications_path($user['role']) ?>" class="text-white position-relative px-1" title="Notifications">
        <i class="fa-solid fa-bell fa-lg"></i>
        <?php if ($unread): ?>
          <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size:.6rem;"><?= $unread > 9 ? '9+' : $unread ?></span>
        <?php endif; ?>
      </a>
      <span class="text-white-50 small text-capitalize d-none d-lg-inline"><?= h(str_replace('_',' ',$user['role'])) ?></span>
      <a href="<?= BASE_URL ?>/profile.php" class="navbar-profile d-flex align-items-center gap-2 text-white text-decoration-none" title="My Profile" aria-label="My Profile">
        <?= user_avatar(['user_id' => $user['user_id'], 'full_name' => $user['full_name'], 'profile_picture' => current_user_picture($pdo)], 30, 'avatar-navbar') ?>
        <span class="fw-semibold d-none d-md-inline navbar-username text-truncate"><?= h($user['full_name']) ?></span>
      </a>
      <a href="<?= BASE_URL ?>/change_password.php" class="text-white px-1" title="Change Password" aria-label="Change Password"><i class="fa-solid fa-key"></i></a>
      <a href="<?= BASE_URL ?>/logout.php" class="btn btn-sm btn-outline-light" title="Logout"><i class="fa-solid fa-right-from-bracket"></i><span class="d-none d-sm-inline"> Logout</span></a>
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
