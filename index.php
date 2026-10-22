<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/mailer.php';   // mask_email() -- no database needed on this page
if (is_logged_in()) { redirect_to_dashboard(); }

// Logo files live in assets/img/ (any of these extensions). Until one is
// added, a round initials badge is shown in its place.
function landing_logo(string $basename): ?string {
    foreach (['png', 'webp', 'jpg', 'jpeg', 'svg'] as $ext) {
        if (is_file(__DIR__ . "/assets/img/$basename.$ext")) {
            return BASE_URL . "/assets/img/$basename.$ext";
        }
    }
    return null;
}
$udm_logo = landing_logo('udm-logo');
$ccs_logo = landing_logo('ccs-logo');

$icons = [
    'monitor' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect width="20" height="14" x="2" y="3" rx="2"/><line x1="8" x2="16" y1="21" y2="21"/><line x1="12" x2="12" y1="17" y2="21"/></svg>',
    'shield'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/></svg>',
    'arrow'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>',
    'back'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M19 12H5"/><path d="m12 19-7-7 7-7"/></svg>',
    'eye'     => '<svg class="ld-eye-show" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M2.06 12.35a1 1 0 0 1 0-.7 10.75 10.75 0 0 1 19.88 0 1 1 0 0 1 0 .7 10.75 10.75 0 0 1-19.88 0"/><circle cx="12" cy="12" r="3"/></svg>',
    'eye_off' => '<svg class="ld-eye-hide" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68"/><path d="M6.61 6.61A13.53 13.53 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61"/><path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/><line x1="2" x2="22" y1="2" y2="22"/></svg>',
    'check'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/></svg>',
    'alert'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/></svg>',
];

// The two roles. The key is what login_process.php expects in its "portal"
// field; it re-checks there that the account really has that role.
$roles = [
    'faculty' => ['label' => 'Faculty',                      'icon' => 'monitor', 'tone' => 'teal'],
    'admin'   => ['label' => 'Program Chair / Dean / Admin', 'icon' => 'shield',  'tone' => 'blue'],
];

// ?role=... opens straight to that role's login form (also where a failed
// login comes back to). Anything else shows the role choice.
$role = $_GET['role'] ?? '';
if (!is_string($role) || !isset($roles[$role])) {
    $role = '';
}

// The second step of logging in (login_verify.php) or a forgotten-password
// request (forgot_password.php) in progress: shown on the third pane, for
// the role it was started from.
$pending = login_pending();
$reset = $pending ? null : password_reset_pending();
if ($pending || $reset) {
    $role = ($pending ?? $reset)['portal'];
}

// Flash from login_process.php: the error, the username typed, and whether the
// username / password were wrong (only then are the boxes outlined in red).
$error = $role !== '' ? ($_SESSION['login_error_' . $role] ?? null) : null;
$notice = $role !== '' ? ($_SESSION['login_notice_' . $role] ?? null) : null;   // e.g. "Your password has been reset."
$general_error = $_SESSION['login_error'] ?? null;   // e.g. the form came without a role
$username = $role !== '' ? (string)($_SESSION['login_username'] ?? '') : '';
$bad_fields = $error && !empty($_SESSION['login_bad_fields']);
// Flash for the third pane, and whether to slide over to it (just arrived from the login form)
$step_error = $_SESSION['step_error'] ?? null;
$step_notice = $_SESSION['step_notice'] ?? null;
$slide = !empty($_SESSION['step_slide']);
$step_view = $_SESSION['step_view'] ?? null;
unset($_SESSION['login_error_faculty'], $_SESSION['login_error_admin'], $_SESSION['login_error'], $_SESSION['login_notice_faculty'],
      $_SESSION['login_notice_admin'], $_SESSION['login_username'], $_SESSION['login_bad_fields'],
      $_SESSION['step_error'], $_SESSION['step_notice'], $_SESSION['step_slide'], $_SESSION['step_view']);

$active = $role !== '' ? $role : 'faculty';   // which pill / hidden value the form starts with

// The third pane: '' (holds the Forgot password form, for landing.js to slide to), or the step to show now
$step = match (true) {
    $pending && $pending['step'] === 'prompt'     => 'setup_prompt',
    $pending && $pending['step'] === 'enable'     => 'setup_enable',
    $pending && $pending['step'] === 'email'      => 'setup_email',
    $pending && $pending['purpose'] === 'login'   => 'login_code',
    (bool)$pending                                 => 'setup_code',
    $reset && $reset['step'] === 'password'       => 'reset_password',
    (bool)$reset                                   => 'reset_code',
    $role !== '' && ($step_view === 'reset_start' || ($_GET['forgot'] ?? '') === '1') => 'reset_start',
    default                                        => '',
};
$flow = $pending ?? $reset;
$resend_wait = $flow ? max(0, (int)$flow['sent_at'] + OTP_RESEND_SECONDS - time()) : 0;
$code_left = $flow ? max(0, (int)$flow['sent_at'] + OTP_TTL_SECONDS - time()) : 0;   // the expiry countdown under the code boxes
// REQUIRE_2FA_FOR_ADMINS: an Admin can't skip setting up the email (the account is only known by its portal here)
$step_required = $pending && REQUIRE_2FA_FOR_ADMINS && !empty($pending['required']);
$code_minutes = (int)ceil(OTP_TTL_SECONDS / 60);
$view = $step !== '' ? 'step' : ($role !== '' ? 'login' : 'roles');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h(SYSTEM_NAME) ?> — CCS, Universidad de Manila</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=Public+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="<?= h(admin_asset('assets/css/landing.css')) ?>" rel="stylesheet">
<script src="<?= h(admin_asset('assets/js/landing.js')) ?>" defer></script>
</head>
<body class="landing">

<header class="ld-header">
  <?php if ($udm_logo): ?>
    <img class="ld-logo" src="<?= h($udm_logo) ?>" alt="Universidad de Manila seal" width="90" height="90">
  <?php else: ?>
    <span class="ld-logo ld-logo-fallback" role="img" aria-label="Universidad de Manila seal">UdM</span>
  <?php endif; ?>

  <h1 class="ld-university">Universidad de Manila</h1>

  <?php if ($ccs_logo): ?>
    <img class="ld-logo ld-logo-contain" src="<?= h($ccs_logo) ?>" alt="College of Computing Studies logo" width="90" height="90">
  <?php else: ?>
    <span class="ld-logo ld-logo-fallback" role="img" aria-label="College of Computing Studies logo">CCS</span>
  <?php endif; ?>
</header>

<main class="ld-main">

  <section class="ld-hero" aria-labelledby="ld-title">
    <p class="ld-eyebrow">College of Computing Studies</p>
    <h2 class="ld-title" id="ld-title">
      <span class="ld-nowrap">Faculty-201 File</span>
      <span>Management System</span>
    </h2>
    <hr class="ld-rule">
    <p class="ld-lead">
      A secure, centralized system for uploading, storing, and monitoring faculty 201-file documents.
    </p>
  </section>

  <div class="ld-panel" id="ld-panel" data-view="<?= $view ?>"<?= $view === 'step' && $slide ? ' data-slide-from="login"' : '' ?>>

    <!-- State a: choose a role. Each card is a real link (?role=...), so this works without JavaScript too. -->
    <section class="ld-pane ld-pane-roles" aria-labelledby="ld-roles-title">
      <div class="ld-pane-inner">
        <h2 class="ld-heading" id="ld-roles-title">Log in as:</h2>
        <p class="ld-sub">Choose your role to continue.</p>

        <?php if ($general_error): ?>
          <div class="ld-alert" role="alert">
            <?= $icons['alert'] ?>
            <span><?= h($general_error) ?></span>
          </div>
        <?php endif; ?>

        <ul class="ld-cards">
          <?php foreach ($roles as $key => $r): ?>
            <li>
              <a class="role-card" href="<?= BASE_URL ?>/index.php?role=<?= h($key) ?>" data-role="<?= h($key) ?>">
                <span class="role-icon role-icon-<?= h($r['tone']) ?>"><?= $icons[$r['icon']] ?></span>
                <span class="role-label"><?= h($r['label']) ?></span>
                <span class="role-arrow"><?= $icons['arrow'] ?></span>
              </a>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    </section>

    <!-- State b: the login form for the chosen role. -->
    <section class="ld-pane ld-pane-login" aria-labelledby="ld-login-title">
      <div class="ld-pane-inner">
        <a class="ld-back" href="<?= BASE_URL ?>/index.php" id="ld-back">
          <?= $icons['back'] ?> Change role
        </a>

        <h2 class="ld-heading" id="ld-login-title">Logging in as:</h2>
        <p class="ld-pill-row">
          <?php foreach ($roles as $key => $r): ?>
            <span class="ld-pill ld-pill-<?= h($r['tone']) ?>" data-role="<?= h($key) ?>"<?= $key === $active ? '' : ' hidden' ?>>
              <span class="ld-pill-icon"><?= $icons[$r['icon']] ?></span>
              <?= h($r['label']) ?>
            </span>
          <?php endforeach; ?>
        </p>

        <form class="ld-form" id="ld-form" action="<?= BASE_URL ?>/login_process.php" method="POST">
          <?= csrf_field() ?>
          <input type="hidden" name="portal" id="ld-portal" value="<?= h($active) ?>">

          <div class="ld-alert" id="ld-error" role="alert" aria-live="assertive"<?= $error ? '' : ' hidden' ?>>
            <?= $icons['alert'] ?>
            <span id="ld-error-text"><?= h($error ?? '') ?></span>
          </div>
          <?php if ($notice && !$error): ?>
            <div class="ld-alert ld-alert-success" id="ld-notice" role="status">
              <?= $icons['check'] ?>
              <span><?= h($notice) ?></span>
            </div>
          <?php endif; ?>

          <div class="ld-field">
            <label for="ld-username">Username</label>
            <input type="text" id="ld-username" name="username" class="ld-input<?= $bad_fields ? ' is-invalid' : '' ?>"
                   value="<?= h($username) ?>" placeholder="e.g. juan.delacruz" maxlength="150"
                   autocomplete="username" autocapitalize="none" spellcheck="false" required
                   <?= $bad_fields ? 'aria-invalid="true" aria-describedby="ld-error"' : '' ?>
                   <?= $view === 'login' && $username === '' ? 'autofocus' : '' ?>>
          </div>

          <div class="ld-field">
            <label for="ld-password">Password</label>
            <div class="ld-password">
              <input type="password" id="ld-password" name="password" class="ld-input<?= $bad_fields ? ' is-invalid' : '' ?>"
                     placeholder="Enter your password" autocomplete="current-password" required
                     <?= $bad_fields ? 'aria-invalid="true" aria-describedby="ld-error"' : '' ?>
                     <?= $view === 'login' && $username !== '' ? 'autofocus' : '' ?>>
              <button type="button" class="ld-toggle" id="ld-toggle" aria-label="Show password" aria-pressed="false" aria-controls="ld-password" hidden>
                <?= $icons['eye'] ?><?= $icons['eye_off'] ?>
              </button>
            </div>
          </div>

          <p class="ld-forgot-row">
            <a class="ld-forgot" id="ld-forgot" href="<?= BASE_URL ?>/index.php?role=<?= h($active) ?>&amp;forgot=1">Forgot password?</a>
          </p>

          <button type="submit" class="ld-submit" id="ld-submit">
            <span class="ld-spinner" aria-hidden="true"></span>
            <span class="ld-submit-text">Log In</span>
          </button>
        </form>
      </div>
    </section>

<?php require __DIR__ . '/includes/login_step_pane.php'; ?>

  </div>

</main>

<footer class="ld-footer">
  <p><?= date('Y') ?> © Universidad de Manila — College of Computing Studies</p>
  <p>Developed by Arnesto, Austria, Morales &middot; <a href="<?= BASE_URL ?>/privacy.php">Privacy Policy</a></p>
</footer>

</body>
</html>
