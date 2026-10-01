<?php
require_once __DIR__ . '/includes/auth.php';
if (is_logged_in()) { redirect_to_dashboard(); }
$error = $_SESSION['login_error_admin'] ?? null;
$email = $_SESSION['login_email'] ?? '';
unset($_SESSION['login_error_admin'], $_SESSION['login_email']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Login — Faculty 201-File Repository</title>
<link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
<link href="<?= BASE_URL ?>/assets/css/style.css" rel="stylesheet">
</head>
<body>
<div class="login-wrapper">
  <div class="card login-card p-4">
    <div class="card-body">
      <a href="<?= BASE_URL ?>/index.php" class="small text-muted d-inline-block mb-3">
        <i class="fa-solid fa-arrow-left"></i> Back to portal selection
      </a>

      <div class="text-center mb-4">
        <div class="portal-icon portal-icon-navy mx-auto mb-2">
          <i class="fa-solid fa-user-shield"></i>
        </div>
        <h4 class="fw-bold mb-0">Admin</h4>
        <small class="text-muted">Faculty 201-File Repository — CCS, Universidad de Manila</small>
      </div>

      <?php if ($error): ?>
        <div class="alert alert-danger py-2"><?= h($error) ?></div>
      <?php endif; ?>

      <form action="login_process.php" method="POST">
        <input type="hidden" name="portal" value="admin">
        <div class="mb-3">
          <label class="form-label">Email</label>
          <input type="email" name="email" class="form-control<?= $error ? ' is-invalid' : '' ?>" value="<?= h($email) ?>" required<?= $email === '' ? ' autofocus' : '' ?>>
        </div>
        <div class="mb-3">
          <label class="form-label">Password</label>
          <input type="password" name="password" class="form-control<?= $error ? ' is-invalid' : '' ?>" required<?= $email !== '' ? ' autofocus' : '' ?>>
        </div>
        <button type="submit" class="btn btn-brand w-100">
          <i class="fa-solid fa-right-to-bracket"></i> Log In
        </button>
      </form>

      <hr>
      <p class="small text-muted mb-0">
        This portal is for the Admin, Program Chair, and Dean — system administration, faculty account management, and monitoring of faculty document uploads.
      </p>
    </div>
  </div>
</div>
</body>
</html>
