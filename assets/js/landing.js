/**
 * Landing page (index.php): choosing a role slides the right panel over to
 * the login form, all on this page. Without JavaScript the role cards and
 * "Change role" are ordinary links (?role=...), and the server renders the
 * matching panel, so everything still works.
 *   - role card      -> slide to the form for that role, then focus Username
 *   - Change role    -> slide back to the role cards
 *   - Back / Forward -> follow the ?role= in the address bar
 *   - eye button     -> show / hide the password
 *   - Log In         -> loading state, no double submit
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

  window.addEventListener('popstate', function () {
    var role = roleFromUrl();
    if (role) {
      showLogin(role);
    } else if (panel.getAttribute('data-view') !== 'roles') {
      showRoles();
    }
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
