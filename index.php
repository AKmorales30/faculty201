<?php
require_once __DIR__ . '/includes/auth.php';
if (is_logged_in()) { redirect_to_dashboard(); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Faculty 201-File Repository — CCS, Universidad de Manila</title>
<link href="<?= BASE_URL ?>/assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
<link href="<?= BASE_URL ?>/assets/vendor/fontawesome/css/all.min.css" rel="stylesheet">
<link href="<?= BASE_URL ?>/assets/css/style.css" rel="stylesheet">
</head>
<body>

<header class="hero-banner text-center text-white">
  <div class="container py-5">
    <div class="text-uppercase fw-semibold small text-accent-gold mb-2">Universidad de Manila</div>
    <h1 class="fw-bold display-5">College of Computing Studies</h1>
    <h2 class="fw-semibold h4 mb-4">Faculty 201-File Repository</h2>
    <p class="mx-auto hero-lead">
      A secure, centralized system for uploading, storing, and monitoring faculty 201-file
      documents — Transcripts of Records, Diplomas, Certificates, and other supporting documents.
    </p>
  </div>
</header>

<main class="container py-5">
  <h3 class="fw-bold text-center mb-4">Select Your Login Portal</h3>

  <div class="row g-4 justify-content-center">

    <div class="col-md-6 col-lg-4">
      <div class="card portal-card h-100 text-center p-4">
        <div class="portal-icon portal-icon-teal mx-auto mb-3">
          <i class="fa-solid fa-chalkboard-user"></i>
        </div>
        <h5 class="fw-bold">Faculty</h5>
        <p class="text-muted small flex-grow-1">
          For all CCS faculty members, full-time and part-time, submitting and tracking 201-file documents.
        </p>
        <a href="login_faculty.php" target="_blank" rel="noopener" class="fw-semibold text-brand">
          Login <i class="fa-solid fa-arrow-right"></i>
        </a>
      </div>
    </div>

    <div class="col-md-6 col-lg-4">
      <div class="card portal-card h-100 text-center p-4">
        <div class="portal-icon portal-icon-navy mx-auto mb-3">
          <i class="fa-solid fa-user-shield"></i>
        </div>
        <h5 class="fw-bold">Admin</h5>
        <p class="text-muted small flex-grow-1">
          For the Admin, Program Chairs, and Deans — managing accounts and records, and staying notified of faculty document uploads.
        </p>
        <a href="login_admin.php" target="_blank" rel="noopener" class="fw-semibold text-brand">
          Login <i class="fa-solid fa-arrow-right"></i>
        </a>
      </div>
    </div>

  </div>
</main>

<footer class="app-footer text-center text-white-50 py-4">
  <div class="container small">
    <div><?= date('Y') ?> © Universidad de Manila — College of Computing Studies</div>
    <div>Developed by Arnesto, Austria, Morales</div>
  </div>
</footer>

</body>
</html>