<?php
/**
 * The landing page's third pane (index.php): what comes after the username
 * and password, on the same sliding panel -- the emailed code, the optional
 * email / two-factor setup, or Forgot password. $step (from index.php) says
 * which: '' / reset_start (Forgot password: the username), login_code,
 * setup_prompt (Set up now / Not now), setup_email, setup_code, setup_enable
 * (Turn on 2FA / Maybe later), reset_code, reset_password.
 * Posts to login_verify.php (logging in) or forgot_password.php.
 */
$step_handler = in_array($step, ['login_code', 'setup_prompt', 'setup_email', 'setup_code', 'setup_enable'], true) ? 'login_verify.php' : 'forgot_password.php';
$step_masked = !empty($pending['email']) ? mask_email($pending['email']) : '';
$code_step = in_array($step, ['login_code', 'setup_code', 'reset_code'], true);

/** A small POST form with one button (resend, cancel, Not now, ...). */
$step_button = function (string $action, string $label, string $class = 'ld-link-button', array $attrs = []) use ($step_handler, $active): string {
    $extra = '';
    foreach ($attrs as $k => $v) { $extra .= ' ' . $k . '="' . h((string)$v) . '"'; }
    return '<form method="POST" action="' . BASE_URL . '/' . $step_handler . '" class="ld-step-form">' . csrf_field()
         . '<input type="hidden" name="portal" value="' . h($active) . '">'
         . '<input type="hidden" name="action" value="' . h($action) . '">'
         . '<button type="submit" class="' . h($class) . '"' . $extra . '>' . $label . '</button></form>';
};
?>
    <!-- State c: the next step after the password, or Forgot password -->
    <section class="ld-pane ld-pane-step" aria-labelledby="ld-step-title">
      <div class="ld-pane-inner">
        <?php if ($step === '' || $step === 'reset_start'): ?>
          <a class="ld-back" href="<?= BASE_URL ?>/index.php?role=<?= h($active) ?>" id="ld-step-back">
            <?= $icons['back'] ?> Back to log in
          </a>
        <?php else: ?>
          <?= $step_button('cancel', $icons['back'] . ' Back to log in', 'ld-back') ?>
        <?php endif; ?>

        <?php
        [$title, $sub] = match ($step) {
            'login_code'     => ['Check your email', 'We sent a 6-digit code to <strong>' . h($step_masked) . '</strong>. Enter it to finish logging in.'],
            'setup_prompt'   => ['Set up your email', 'Add your email to enable two-factor authentication (2FA) and password recovery. This adds an extra layer of security to your account.'],
            'setup_email'    => ['Set up your email', 'Enter a personal or work email you can open right now. We\'ll send a 6-digit code to verify it.'],
            'setup_code'     => ['Verify your email', 'We sent a 6-digit code to <strong>' . h($step_masked) . '</strong>. Enter it to confirm this address.'],
            'setup_enable'   => ['Turn on two-factor authentication?', 'Your email <strong>' . h($step_masked) . '</strong> is verified. With two-factor authentication on, each login also needs a 6-digit code sent to it. You can change this anytime in Profile Preference &rsaquo; Security.'],
            'reset_code'     => ['Check your email', 'If this account has a verified email, we\'ve sent a 6-digit code to it.'],
            'reset_password' => ['Set a new password', 'At least 8 characters, with an uppercase letter, a lowercase letter and a number.'],
            default          => ['Forgot password?', 'Enter your username. If your account has a verified email, we\'ll send a code to it so you can set a new password.'],
        };
        ?>
        <h2 class="ld-heading" id="ld-step-title"><?= h($title) ?></h2>
        <p class="ld-sub"><?= $sub ?></p>
        <?php if ($step === 'reset_code' || $step === '' || $step === 'reset_start'): ?>
          <p class="ld-note">No email set up yet? Please contact the administrator to reset your password.</p>
        <?php endif; ?>
        <?php if ($step_required && in_array($step, ['setup_prompt', 'setup_email'], true)): ?>
          <p class="ld-note">Two-factor authentication is required for Admin accounts.</p>
        <?php endif; ?>

        <?php if ($step_error): ?>
          <div class="ld-alert" id="ld-step-error" role="alert" aria-live="assertive">
            <?= $icons['alert'] ?><span><?= h($step_error) ?></span>
          </div>
        <?php elseif ($step_notice): ?>
          <div class="ld-alert ld-alert-success" role="status">
            <?= $icons['check'] ?><span><?= h($step_notice) ?></span>
          </div>
        <?php endif; ?>

        <form class="ld-form ld-step-form" action="<?= BASE_URL ?>/<?= $step_handler ?>" method="POST">
          <?= csrf_field() ?>
          <input type="hidden" name="portal" class="ld-portal-field" value="<?= h($active) ?>">

          <?php if ($code_step): ?>
            <input type="hidden" name="action" value="verify">
            <div class="ld-field">
              <label for="ld-code">6-digit code</label>
              <input type="text" id="ld-code" name="code" class="ld-input ld-code<?= $step_error ? ' is-invalid' : '' ?>"
                     inputmode="numeric" autocomplete="one-time-code" maxlength="7" placeholder="000000" required
                     <?= $step_error ? 'aria-invalid="true" aria-describedby="ld-step-error"' : '' ?> autofocus>
              <p class="ld-countdown" id="ld-countdown" data-expires="<?= (int)$code_left ?>" aria-live="off">
                The code expires in <?= $code_minutes ?> minutes.
              </p>
            </div>
            <?php $step_submit = 'Verify'; ?>

          <?php elseif ($step === 'setup_prompt'): ?>
            <input type="hidden" name="action" value="setup_now">
            <?php $step_submit = 'Set up now'; ?>

          <?php elseif ($step === 'setup_enable'): ?>
            <input type="hidden" name="action" value="enable_2fa">
            <?php $step_submit = 'Turn on'; ?>

          <?php elseif ($step === 'setup_email'): ?>
            <input type="hidden" name="action" value="set_email">
            <div class="ld-field">
              <label for="ld-setup-email">Email</label>
              <input type="email" id="ld-setup-email" name="email" class="ld-input<?= $step_error ? ' is-invalid' : '' ?>"
                     placeholder="you@gmail.com" maxlength="150" autocomplete="email" autocapitalize="none" spellcheck="false" required
                     <?= $step_error ? 'aria-invalid="true" aria-describedby="ld-step-error"' : '' ?> autofocus>
            </div>
            <?php $step_submit = 'Send code'; ?>

          <?php elseif ($step === 'reset_password'): ?>
            <input type="hidden" name="action" value="set_password">
            <div class="ld-field">
              <label for="ld-new-password">New password</label>
              <input type="password" id="ld-new-password" name="new_password" class="ld-input" minlength="8" autocomplete="new-password" required autofocus>
            </div>
            <div class="ld-field">
              <label for="ld-confirm-password">Confirm new password</label>
              <input type="password" id="ld-confirm-password" name="confirm_password" class="ld-input" minlength="8" autocomplete="new-password" required>
            </div>
            <?php $step_submit = 'Save password'; ?>

          <?php else: ?>
            <input type="hidden" name="action" value="request">
            <div class="ld-field">
              <label for="ld-reset-username">Username</label>
              <input type="text" id="ld-reset-username" name="username" class="ld-input<?= $step_error ? ' is-invalid' : '' ?>"
                     placeholder="e.g. juan.delacruz" maxlength="150" autocomplete="username" autocapitalize="none" spellcheck="false" required
                     <?= $step === 'reset_start' ? 'autofocus' : '' ?>>
            </div>
            <?php $step_submit = 'Send code'; ?>
          <?php endif; ?>

          <button type="submit" class="ld-submit"<?= in_array($step, ['setup_prompt', 'setup_enable'], true) ? ' autofocus' : '' ?>>
            <span class="ld-spinner" aria-hidden="true"></span>
            <span class="ld-submit-text"><?= h($step_submit) ?></span>
          </button>
        </form>

        <?php if (($step === 'setup_prompt' || $step === 'setup_email') && !$step_required): ?>
          <?= $step_button('not_now', $step === 'setup_prompt' ? 'Not now' : 'Not now, skip this', $step === 'setup_prompt' ? 'ld-secondary' : 'ld-link-button ld-skip') ?>
        <?php elseif ($step === 'setup_enable'): ?>
          <?= $step_button('maybe_later', 'Maybe later', 'ld-secondary') ?>
        <?php endif; ?>

        <?php if ($code_step): ?>
          <div class="ld-step-actions">
            <?= $step_button('resend', 'Resend code', 'ld-link-button', ['data-wait' => $resend_wait, 'data-label' => 'Resend code']) ?>
            <?php if ($step === 'setup_code'): ?>
              <?= $step_button('other_email', 'Use a different email') ?>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
    </section>
