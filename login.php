<?php
require_once __DIR__ . '/includes/auth.php';
if (is_logged_in()) { redirect_to_dashboard(); }

// Which portal the person clicked on the landing page.
$allowed_portals = ['faculty', 'admin'];
$portal = $_GET['portal'] ?? ($_POST['portal'] ?? '');
if (!in_array($portal, $allowed_portals, true)) {
    $portal = '';
}

$portal_meta = [
    'faculty' => ['label' => 'Faculty', 'icon' => 'fa-chalkboard-user'],
    'admin'   => ['label' => 'Admin',   'icon' => 'fa-user-shield'],
];
$meta = $portal_meta[$portal] ?? ['label' => 'Faculty 201-File Repository', 'icon' => 'fa-right-to-bracket'];

// Matches the per-portal session key login_process.php actually sets
// (login_error_faculty / login_error_admin), so an error triggered by
// landing here via require_login() actually shows up.
$error_key = $portal !== '' ? 'login_error_' . $portal : 'login_error';
$error = $_SESSION[$error_key] ?? null;
$email = $_SESSION['login_email'] ?? '';
unset($_SESSION[$error_key], $_SESSION['login_email']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h($meta['label']) ?> Login — Faculty 201-File Repository</title>
<link href="<?= BASE_URL ?>/assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
<link href="<?= BASE_URL ?>/assets/vendor/fontawesome/css/all.min.css" rel="stylesheet">
<link href="<?= BASE_URL ?>/assets/css/style.css" rel="stylesheet">
</head>
<body>

<div class="login-hero" style="background-image: url('<?= BASE_URL ?>/assets/img/ccs-banner.jpg');">
  <div class="login-hero-overlay"></div>

  <a href="<?= BASE_URL ?>/index.php" class="login-hero-back">
    <i class="fa-solid fa-arrow-left"></i> Back to portal selection
  </a>

  <div class="login-float-card">
    <div class="d-flex align-items-center gap-2 mb-3">
      <i class="fa-solid <?= h($meta['icon']) ?> fa-lg text-brand"></i>
      <h4 class="fw-bold mb-0"><?= h($meta['label']) ?> Login</h4>
    </div>

    <?php if ($error): ?>
      <div class="alert alert-danger py-2 small"><?= h($error) ?></div>
    <?php endif; ?>

    <form action="login_process.php" method="POST">
      <input type="hidden" name="portal" value="<?= h($portal) ?>">
      <div class="mb-3">
        <label class="form-label small fw-semibold">Email</label>
        <input type="email" name="email" class="form-control<?= $error ? ' is-invalid' : '' ?>" value="<?= h($email) ?>" required<?= $email === '' ? ' autofocus' : '' ?>>
      </div>
      <div class="mb-3">
        <label class="form-label small fw-semibold">Password</label>
        <input type="password" name="password" class="form-control<?= $error ? ' is-invalid' : '' ?>" required<?= $email !== '' ? ' autofocus' : '' ?>>
      </div>
      <button type="submit" class="btn btn-brand w-100">
        <i class="fa-solid fa-right-to-bracket"></i> Login
      </button>
    </form>

    <hr>
    <p class="small text-muted mb-0">
      Accounts are created by the Admin. Contact your Admin if you don't have credentials yet.
    </p>
  </div>

  <div class="login-hero-footer">
    Copyright &copy; <?= date('Y') ?>. Universidad de Manila, Philippines
  </div>
</div>

</body>
</html>