/**
 * Landing page (index.php): choosing a role slides the right panel over to
 * the login form, all on this page; after the password, the next step (the
 * emailed code, the optional email / two-factor setup) and Forgot password slide
 * in as a third pane. Without JavaScript the role cards, "Change role" and
 * "Forgot password?" are ordinary links (?role=...&forgot=1), and the server
 * renders the matching pane, so everything still works.
 *   - role card        -> slide to the form for that role, then focus Username
 *   - Change role      -> slide back to the role cards
 *   - Forgot password? -> slide to the third pane (username for the reset code)
 *   - Back / Forward   -> follow the ?role= / &forgot=1 in the address bar
 *   - arriving at a step from the login form -> slide over to it
 *   - eye button       -> show / hide the password
 *   - code             -> six boxes (auto-advance, paste, Backspace), sent at
 *                         the sixth digit; the expiry and "Resend code" count down
 *   - any submit       -> loading state, no double submit
 */
(function () {
  'use strict';

  var panel = document.getElementById('ld-panel');
  var form = document.getElementById('ld-form');
  if (!panel || !form) return;

  var portalInput = document.getElementById('ld-portal');
  var username = document.getElementById('ld-username');
  var password = document.getElementById('ld-password');
  var errorBox = document.getElementById('ld-error');
  var toggle = document.getElementById('ld-toggle');
  var submit = document.getElementById('ld-submit');
  var submitText = submit.querySelector('.ld-submit-text');
  var cards = panel.querySelectorAll('.role-card[data-role]');
  var pills = panel.querySelectorAll('.ld-pill[data-role]');
  var roles = Array.prototype.map.call(cards, function (c) { return c.getAttribute('data-role'); });
  var forgot = document.getElementById('ld-forgot');
  var stepBack = document.getElementById('ld-step-back');   // only while the third pane holds Forgot password
  var resetUsername = document.getElementById('ld-reset-username');

  var focusTimer = null;

  // Matches --ld-slide in landing.css (shorter under reduced motion).
  function slideMs() {
    var v = getComputedStyle(document.documentElement).getPropertyValue('--ld-slide').trim();
    var n = parseFloat(v);
    if (isNaN(n)) return 380;
    return /ms$/.test(v) ? n : n * 1000;
  }

  function roleFromUrl() {
    var r = new URLSearchParams(window.location.search).get('role');
    return roles.indexOf(r) !== -1 ? r : '';
  }

  function forgotInUrl() {
    return new URLSearchParams(window.location.search).get('forgot') === '1';
  }

  // The server's error / red outlines belong to the attempt that produced
  // them; once the person goes back to choose a role they no longer apply.
  function clearError() {
    errorBox.hidden = true;
    [username, password].forEach(function (input) {
      if (!input) return;   // a page from another version of the form: skip, don't break the slide
      input.classList.remove('is-invalid');
      input.removeAttribute('aria-invalid');
      input.removeAttribute('aria-describedby');
    });
  }

  function setRole(role) {
    portalInput.value = role;
    Array.prototype.forEach.call(panel.querySelectorAll('.ld-portal-field'), function (input) { input.value = role; });
    var base = window.location.pathname + '?role=' + encodeURIComponent(role);
    if (forgot) forgot.href = base + '&forgot=1';
    if (stepBack) stepBack.href = base;
    Array.prototype.forEach.call(pills, function (pill) {
      pill.hidden = pill.getAttribute('data-role') !== role;
    });
  }

  function afterSlide(fn) {
    clearTimeout(focusTimer);
    focusTimer = setTimeout(fn, slideMs() + 20);
  }

  function showLogin(role) {
    if (portalInput.value !== role) clearError();
    setRole(role);
    panel.setAttribute('data-view', 'login');
    afterSlide(function () { if (username) username.focus(); });
  }

  // Forgot password, on the third pane (only while it isn't showing a login step)
  function showForgot(role) {
    if (!stepBack) return;
    setRole(role);
    panel.setAttribute('data-view', 'step');
    afterSlide(function () { if (resetUsername) resetUsername.focus(); });
  }

  function showRoles() {
    var previous = portalInput.value;
    clearError();
    panel.setAttribute('data-view', 'roles');
    afterSlide(function () {
      var card = panel.querySelector('.role-card[data-role="' + previous + '"]') || cards[0];
      if (card) card.focus();
    });
  }

  // ----- role cards and Change role ----------------------------------------
  Array.prototype.forEach.call(cards, function (card) {
    card.addEventListener('click', function (e) {
      if (e.ctrlKey || e.metaKey || e.shiftKey || e.altKey || e.button !== 0) return;   // let "open in new tab" work as a link
      e.preventDefault();
      var role = card.getAttribute('data-role');
      history.pushState({ role: role }, '', card.href);
      showLogin(role);
    });
  });

  document.getElementById('ld-back').addEventListener('click', function (e) {
    if (e.ctrlKey || e.metaKey || e.shiftKey || e.altKey || e.button !== 0) return;
    e.preventDefault();
    history.pushState({ role: '' }, '', this.href);
    showRoles();
  });

  if (forgot && stepBack) {
    forgot.addEventListener('click', function (e) {
      if (e.ctrlKey || e.metaKey || e.shiftKey || e.altKey || e.button !== 0) return;
      e.preventDefault();
      history.pushState({ role: portalInput.value, forgot: true }, '', this.href);
      showForgot(portalInput.value);
    });
    stepBack.addEventListener('click', function (e) {
      if (e.ctrlKey || e.metaKey || e.shiftKey || e.altKey || e.button !== 0) return;
      e.preventDefault();
      history.pushState({ role: portalInput.value }, '', this.href);
      showLogin(portalInput.value);
    });
  }

  window.addEventListener('popstate', function () {
    var role = roleFromUrl();
    if (role && forgotInUrl() && stepBack) {
      showForgot(role);
    } else if (role) {
      showLogin(role);
    } else if (panel.getAttribute('data-view') !== 'roles') {
      showRoles();
    }
  });

  // ----- arriving at a step from the login form: slide over to it ------------
  var slideFrom = panel.getAttribute('data-slide-from');
  if (slideFrom && panel.getAttribute('data-view') === 'step'
      && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
    panel.classList.add('ld-no-anim');
    panel.setAttribute('data-view', slideFrom);
    void panel.offsetWidth;   // apply the starting position before animating
    panel.classList.remove('ld-no-anim');
    requestAnimationFrame(function () { panel.setAttribute('data-view', 'step'); });
  }

  // ----- the code: six boxes over the one real input ---------------------------
  // The real input (name="code") stays in the form, hidden; without JavaScript
  // it's a single box. Typing moves to the next box, Backspace to the one
  // before, a pasted (or autofilled) code fills all six, and the sixth digit
  // sends the form.
  var code = document.getElementById('ld-code');
  if (code) {
    var invalid = code.classList.contains('is-invalid');
    var group = document.createElement('div');
    group.className = 'ld-otp' + (invalid ? ' is-invalid' : '');
    group.setAttribute('role', 'group');
    group.setAttribute('aria-label', '6-digit code');
    var boxes = [];
    for (var i = 0; i < 6; i++) {
      var box = document.createElement('input');
      box.type = 'text';
      box.className = 'ld-otp-box';
      box.inputMode = 'numeric';
      box.maxLength = 1;
      box.autocomplete = i === 0 ? 'one-time-code' : 'off';
      box.setAttribute('aria-label', 'Digit ' + (i + 1) + ' of 6');
      if (invalid) box.setAttribute('aria-describedby', 'ld-step-error');
      group.appendChild(box);
      boxes.push(box);
    }
    code.parentNode.insertBefore(group, code);
    code.type = 'hidden';
    code.removeAttribute('autofocus');
    var label = document.querySelector('label[for="ld-code"]');
    if (label) label.removeAttribute('for');
    var codeForm = code.form;
    // Fewer than six digits: point at the first empty box instead of sending
    // (registered before the loading-state handler below, which it stops)
    if (codeForm) {
      codeForm.addEventListener('submit', function (e) {
        code.value = boxes.map(function (b) { return b.value; }).join('');
        if (code.value.length === 6) return;
        e.preventDefault();
        e.stopImmediatePropagation();
        var empty = boxes.filter(function (b) { return !b.value; })[0] || boxes[0];
        empty.focus();
      });
    }

    function sync() {
      code.value = boxes.map(function (b) { return b.value; }).join('');
      if (code.value.length === 6 && codeForm) {
        if (codeForm.requestSubmit) { codeForm.requestSubmit(); } else { codeForm.submit(); }
      }
    }
    function fill(from, digits) {   // digits typed or pasted into box `from`
      for (var j = 0; j < digits.length && from + j < 6; j++) boxes[from + j].value = digits[j];
      var next = Math.min(from + digits.length, 5);
      boxes[next].focus();
      boxes[next].select();
      sync();
    }
    boxes.forEach(function (b, idx) {
      b.addEventListener('input', function () {
        var digits = b.value.replace(/\D/g, '');
        b.value = '';
        if (digits) { fill(idx, digits); } else { sync(); }
      });
      b.addEventListener('paste', function (e) {
        var text = (e.clipboardData || window.clipboardData).getData('text') || '';
        var digits = text.replace(/\D/g, '').slice(0, 6);
        e.preventDefault();
        if (digits) fill(digits.length === 6 ? 0 : idx, digits);
      });
      b.addEventListener('keydown', function (e) {
        if (e.key === 'Backspace' && !b.value && idx > 0) {
          boxes[idx - 1].value = '';
          boxes[idx - 1].focus();
          sync();
          e.preventDefault();
        } else if (e.key === 'ArrowLeft' && idx > 0) {
          boxes[idx - 1].focus();
          e.preventDefault();
        } else if (e.key === 'ArrowRight' && idx < 5) {
          boxes[idx + 1].focus();
          e.preventDefault();
        }
      });
      b.addEventListener('focus', function () { b.select(); });
    });
    // After the slide, so focusing doesn't jump the panel mid-animation
    setTimeout(function () { boxes[0].focus(); }, panel.getAttribute('data-slide-from') ? slideMs() + 20 : 0);
  }

  // ----- the code's expiry countdown -----------------------------------------
  var countdown = document.getElementById('ld-countdown');
  if (countdown) {
    var expiresLeft = parseInt(countdown.getAttribute('data-expires'), 10) || 0;
    (function tickExpiry() {
      if (expiresLeft > 0) {
        var m = Math.floor(expiresLeft / 60), s = expiresLeft % 60;
        countdown.textContent = 'The code expires in ' + m + ':' + (s < 10 ? '0' : '') + s + '.';
        expiresLeft -= 1;
        setTimeout(tickExpiry, 1000);
      } else {
        countdown.textContent = 'This code has expired. Use "Resend code" to get a new one.';
        countdown.classList.add('is-expired');
      }
    })();
  }

  // ----- "Send a new code": disabled, counting down, until it may be sent ---
  Array.prototype.forEach.call(panel.querySelectorAll('[data-wait]'), function (button) {
    var left = parseInt(button.getAttribute('data-wait'), 10) || 0;
    var label = button.getAttribute('data-label') || button.textContent;
    function tick() {
      if (left > 0) {
        button.disabled = true;
        button.textContent = label + ' (' + left + 's)';
        left -= 1;
        setTimeout(tick, 1000);
      } else {
        button.disabled = false;
        button.textContent = label;
      }
    }
    tick();
  });

  // ----- the third pane's forms: loading state, one request only --------------
  Array.prototype.forEach.call(panel.querySelectorAll('.ld-step-form'), function (stepForm) {
    var busy = false;
    stepForm.addEventListener('submit', function (e) {
      if (busy) { e.preventDefault(); return; }
      busy = true;
      var button = stepForm.querySelector('.ld-submit');
      if (button) {
        button.disabled = true;
        button.setAttribute('aria-busy', 'true');
      }
    });
  });

  // ----- show / hide password -----------------------------------------------
  toggle.hidden = false;
  toggle.addEventListener('click', function () {
    var show = password.type === 'password';
    password.type = show ? 'text' : 'password';
    toggle.setAttribute('aria-pressed', show ? 'true' : 'false');
    toggle.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    password.focus();
  });

  // ----- submit: loading state, one request only ----------------------------
  var submitting = false;

  form.addEventListener('submit', function (e) {
    if (submitting) { e.preventDefault(); return; }
    submitting = true;
    password.type = 'password';   // never leave it readable on the way out
    submit.disabled = true;
    submit.setAttribute('aria-busy', 'true');
    submitText.textContent = 'Logging in…';
  });

  // Coming back with the browser's Back button can restore the page as it
  // was mid-submit; make the button usable again.
  window.addEventListener('pageshow', function (e) {
    if (!e.persisted) return;
    submitting = false;
    submit.disabled = false;
    submit.removeAttribute('aria-busy');
    submitText.textContent = 'Log In';
    toggle.setAttribute('aria-pressed', 'false');
    toggle.setAttribute('aria-label', 'Show password');
  });
})();
