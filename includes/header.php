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
<nav class="navbar navbar-expand-lg navbar-dark app-navbar">
  <div class="container-fluid">
    <a class="navbar-brand fw-bold" href="<?= BASE_URL ?>/index.php">
      <i class="fa-solid fa-folder-open"></i> CCS Faculty 201-File Repository
    </a>
    <?php if ($user): ?>
    <div class="d-flex align-items-center gap-3 ms-auto">
      <?php $unread = unread_notification_count($pdo, $user['user_id']); ?>
      <a href="<?= BASE_URL . '/' . role_notifications_path($user['role']) ?>" class="text-white position-relative" title="Notifications">
        <i class="fa-solid fa-bell fa-lg"></i>
        <?php if ($unread): ?>
          <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size:.6rem;"><?= $unread > 9 ? '9+' : $unread ?></span>
        <?php endif; ?>
      </a>
      <span class="text-white-50 small text-capitalize"><?= h(str_replace('_',' ',$user['role'])) ?></span>
      <span class="text-white fw-semibold"><?= h($user['full_name']) ?></span>
      <a href="<?= BASE_URL ?>/logout.php" class="btn btn-sm btn-outline-light"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
    </div>
    <?php endif; ?>
  </div>
</nav>
<div class="container-fluid">
  <div class="row">
    <?php if ($user): include __DIR__ . '/sidebar.php'; endif; ?>
    <main class="<?= $user ? 'col-md-10 ms-sm-auto col-lg-10 px-md-4' : 'col-12' ?> py-4">
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
