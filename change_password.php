<?php
/**
 * Change Password -- every role. Also where require_login() sends anyone
 * still on a temporary password from the Admin (users.must_change_password),
 * who can't open any other page until they've chosen their own.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_login();

$page_title = 'Change Password';
$me = current_user();
$forced = !empty($me['must_change_password']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current = (string)($_POST['current_password'] ?? '');
    $new     = (string)($_POST['new_password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');

    $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE user_id = ? AND is_active = 1");
    $stmt->execute([$me['user_id']]);
    $hash = $stmt->fetchColumn();

    $error = null;
    if (!csrf_valid()) {
        $error = 'Your session expired before the form was sent. Please try again.';
    } elseif ($hash === false) {
        $error = 'Your account could not be found.';
    } elseif (!password_verify($current, $hash)) {
        $error = 'Your current password is incorrect.';
    } elseif ($new !== $confirm) {
        $error = 'The new password and its confirmation don\'t match.';
    } elseif ($rules = password_rule_errors($new)) {
        $error = password_rule_message($rules);
    } elseif (password_verify($new, $hash)) {
        $error = 'The new password must be different from your current password.';
    }

    if ($error !== null) {
        $_SESSION['flash_error'] = $error;
        header('Location: ' . BASE_URL . '/change_password.php');
        exit;
    }

    $pdo->prepare("UPDATE users SET password_hash = ?, must_change_password = 0, password_changed_at = NOW() WHERE user_id = ?")
        ->execute([password_hash($new, PASSWORD_DEFAULT), $me['user_id']]);
    log_my_activity($pdo, 'PASSWORD_CHANGE', $forced
        ? 'Replaced the temporary password set by the Admin with their own.'
        : 'Changed their own password.');

    session_regenerate_id(true);
    unset($_SESSION['csrf_token']);
    $_SESSION['user']['must_change_password'] = false;
    $_SESSION['flash_success'] = 'Your password has been changed.';
    if ($forced) {
        redirect_to_dashboard();   // the rest of the system is open now
    }
    header('Location: ' . BASE_URL . '/change_password.php');
    exit;
}

include __DIR__ . '/includes/header.php';

/** Password input with a show / hide toggle. */
function password_input(string $name, string $label, string $autocomplete): string {
    return '<div class="mb-3">'
         . '<label for="' . $name . '" class="form-label small fw-semibold">' . h($label) . '</label>'
         . '<div class="input-group">'
         . '<input type="password" name="' . $name . '" id="' . $name . '" class="form-control" autocomplete="' . $autocomplete . '" required>'
         . '<button type="button" class="btn btn-outline-secondary pw-toggle" data-target="' . $name . '" aria-label="Show password" title="Show password"><i class="fa-solid fa-eye"></i></button>'
         . '</div></div>';
}
?>

<h3 class="fw-bold mb-1">Change Password</h3>
<p class="text-muted mb-4">Choose a password only you know. You'll use it the next time you log in.</p>

<?php if ($forced): ?>
<div class="alert alert-warning mx-auto" style="max-width:520px">
  <i class="fa-solid fa-key"></i> You're signed in with a <strong>temporary password</strong> set by the Admin.
  Please choose your own password to continue.
</div>
<?php endif; ?>

<div class="card stat-card mx-auto" style="max-width:520px">
  <div class="card-body">
    <form method="POST" id="pwForm" novalidate>
      <?= csrf_field() ?>
      <?= password_input('current_password', $forced ? 'Temporary Password' : 'Current Password', 'current-password') ?>
      <?= password_input('new_password', 'New Password', 'new-password') ?>

      <ul class="list-unstyled small mb-3" id="pwRules">
        <li data-rule="length"><i class="fa-solid fa-circle-xmark text-muted"></i> At least 8 characters</li>
        <li data-rule="upper"><i class="fa-solid fa-circle-xmark text-muted"></i> An uppercase letter (A-Z)</li>
        <li data-rule="lower"><i class="fa-solid fa-circle-xmark text-muted"></i> A lowercase letter (a-z)</li>
        <li data-rule="number"><i class="fa-solid fa-circle-xmark text-muted"></i> A number (0-9)</li>
      </ul>
      <div class="progress mb-1" style="height:6px" aria-hidden="true"><div class="progress-bar" id="pwMeter" style="width:0"></div></div>
      <div class="small text-muted mb-3" id="pwStrength">Password strength: --</div>

      <?= password_input('confirm_password', 'Confirm New Password', 'new-password') ?>
      <div class="small text-danger mb-3" id="pwMismatch" hidden>The passwords don't match.</div>

      <button class="btn btn-brand w-100"><i class="fa-solid fa-key"></i> Change Password</button>
    </form>
  </div>
</div>

<script>
// Strength hint and show / hide toggles. The server checks the same rules (password_rule_errors()).
(function () {
  var form = document.getElementById('pwForm');
  var pw = document.getElementById('new_password'), confirm = document.getElementById('confirm_password');
  var tests = { length: /.{8,}/, upper: /[A-Z]/, lower: /[a-z]/, number: /[0-9]/ };

  function check() {
    var v = pw.value, passed = 0;
    Object.keys(tests).forEach(function (rule) {
      var ok = tests[rule].test(v);
      if (ok) passed++;
      var icon = document.querySelector('#pwRules [data-rule="' + rule + '"] i');
      icon.className = 'fa-solid ' + (ok ? 'fa-circle-check text-success' : 'fa-circle-xmark text-muted');
    });
    // Extra credit beyond the rules: length 12+ and a symbol
    var score = passed + (v.length >= 12 ? 1 : 0) + (/[^A-Za-z0-9]/.test(v) ? 1 : 0);
    var levels = [['--', 'bg-secondary', 0], ['Weak', 'bg-danger', 25], ['Weak', 'bg-danger', 25], ['Fair', 'bg-warning', 50],
                  ['Fair', 'bg-warning', 50], ['Good', 'bg-success', 75], ['Strong', 'bg-success', 100]];
    var level = v === '' ? levels[0] : (passed < 4 ? levels[Math.min(passed, 2)] : levels[Math.min(score, 6)]);
    var meter = document.getElementById('pwMeter');
    meter.className = 'progress-bar ' + level[1];
    meter.style.width = level[2] + '%';
    document.getElementById('pwStrength').textContent = 'Password strength: ' + level[0];
    document.getElementById('pwMismatch').hidden = confirm.value === '' || confirm.value === v;
    return passed === 4;
  }
  pw.addEventListener('input', check);
  confirm.addEventListener('input', check);

  form.addEventListener('submit', function (e) {
    if (!check() || pw.value !== confirm.value || !form.current_password.value) {
      e.preventDefault();
      (!form.current_password.value ? form.current_password : (!check() ? pw : confirm)).focus();
      document.getElementById('pwMismatch').hidden = confirm.value === pw.value;
    }
  });

  document.querySelectorAll('.pw-toggle').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var input = document.getElementById(btn.dataset.target), show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      btn.querySelector('i').className = 'fa-solid ' + (show ? 'fa-eye-slash' : 'fa-eye');
      btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
      btn.title = show ? 'Hide password' : 'Show password';
    });
  });
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
