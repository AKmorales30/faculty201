/**
 * Behaviour shared by every page after login (loaded by includes/footer.php):
 *   - the mobile slide-in menu closes once a link is tapped
 *   - collapsible sidebar sections remember being closed
 *   - hover / focus tooltips for icon-only buttons ([data-tooltip])
 *   - the one pop-up (#appModal, includes/app_modal.php): [data-app-modal]
 *     triggers load api/modal.php?view=... into it
 *   - the header search box, whose results open in the pop-up
 */
(function () {
  'use strict';

  // ----- sidebar ------------------------------------------------------------
  // Close the mobile slide-in menu once a link is tapped (navigation still proceeds).
  document.querySelectorAll('#appSidebar a.sb-row').forEach(function (link) {
    link.addEventListener('click', function () {
      var sb = bootstrap.Offcanvas.getInstance(document.getElementById('appSidebar'));
      if (sb) sb.hide();
    });
  });

  // Sections the user closed stay closed (this browser only), except the one with the current page
  var STORE_KEY = 'f201.closedNavGroups';
  function closedGroups() {
    try { return JSON.parse(localStorage.getItem(STORE_KEY) || '[]') || []; } catch (e) { return []; }
  }
  function saveClosedGroups(list) {
    try { localStorage.setItem(STORE_KEY, JSON.stringify(list)); } catch (e) { /* storage blocked: nothing remembered */ }
  }
  var closed = closedGroups();
  document.querySelectorAll('.sb-menu .sb-sub[data-group]').forEach(function (ul) {
    var name = ul.dataset.group;
    var toggle = document.querySelector('.sb-group-toggle[data-group="' + name + '"]');
    if (closed.indexOf(name) !== -1 && !ul.dataset.hasActive) {
      ul.classList.remove('show');
      if (toggle) { toggle.setAttribute('aria-expanded', 'false'); toggle.classList.add('collapsed'); }
    }
    ul.addEventListener('hidden.bs.collapse', function () {
      var list = closedGroups().filter(function (n) { return n !== name; });
      list.push(name);
      saveClosedGroups(list);
    });
    ul.addEventListener('shown.bs.collapse', function () {
      saveClosedGroups(closedGroups().filter(function (n) { return n !== name; }));
    });
  });

  // ----- tooltips -----------------------------------------------------------
  // Hover / focus tooltips for icon-only buttons: mark the button with data-tooltip and put the text in its title.
  // Delegated from <body>, so rows added or re-rendered later (and pop-up content) get them too. Shown in <body>
  // above the icon, so a scrolling table can't cut them off (they flip below near the top of the window).
  new bootstrap.Tooltip(document.body, { selector: '[data-tooltip]', placement: 'top', container: 'body', trigger: 'hover focus' });
  // Hide it once the button is clicked, so it doesn't stay up over a modal or confirm box the button opens
  document.addEventListener('click', function (e) {
    var el = e.target.closest && e.target.closest('[data-tooltip]');
    var tip = el && bootstrap.Tooltip.getInstance(el);
    if (tip) tip.hide();
  });

  // ----- the pop-up -----------------------------------------------------------
  var modalEl = document.getElementById('appModal');
  if (modalEl) {
    var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    var dialog = modalEl.querySelector('.modal-dialog');
    var body = document.getElementById('appModalBody');
    var title = document.getElementById('appModalTitle');
    var request = 0;

    var openAppModal = function (url, opts) {
      opts = opts || {};
      var mine = ++request;
      dialog.classList.remove('modal-lg', 'modal-xl');
      dialog.classList.add(opts.size === 'lg' ? 'modal-lg' : 'modal-xl');
      title.textContent = opts.title || 'Loading…';
      body.innerHTML = '<div class="text-center text-muted py-5"><span class="spinner-border spinner-border-sm"></span> Loading…</div>';
      modal.show();
      fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (res) { return res.text().then(function (html) { return { ok: res.ok, status: res.status, html: html }; }); })
        .then(function (r) {
          if (mine !== request) return;   // a newer pop-up was opened meanwhile
          if (r.status === 401) { window.location.reload(); return; }   // signed out: the page sends them to the login
          body.innerHTML = r.html;
          var head = body.querySelector('[data-modal-title]');
          title.textContent = head ? head.dataset.modalTitle : (opts.title || (r.ok ? '' : 'Something went wrong'));
          body.scrollTop = 0;
        })
        .catch(function () {
          if (mine !== request) return;
          title.textContent = 'Something went wrong';
          body.innerHTML = '<div class="alert alert-danger mb-0">This could not be loaded. Please check your connection and try again.</div>';
        });
    };
    window.openAppModal = openAppModal;

    var triggerOf = function (e) {
      var el = e.target.closest && e.target.closest('[data-app-modal]');
      if (!el) return null;
      // A button or link inside a clickable row does its own thing
      var inner = e.target.closest('a, button, input, select, textarea, label, form');
      if (inner && inner !== el && el.contains(inner)) return null;
      return el;
    };
    document.addEventListener('click', function (e) {
      var el = triggerOf(e);
      if (!el || e.ctrlKey || e.metaKey || e.shiftKey || e.button === 1) return;   // new tab / window: follow the link
      e.preventDefault();
      openAppModal(el.dataset.appModal, { size: el.dataset.appModalSize, title: el.dataset.appModalTitle });
    });
    // Clickable table rows work from the keyboard too
    document.addEventListener('keydown', function (e) {
      if ((e.key === 'Enter' || e.key === ' ') && e.target.matches && e.target.matches('tr[data-app-modal]')) {
        e.preventDefault();
        openAppModal(e.target.dataset.appModal, { size: e.target.dataset.appModalSize, title: e.target.dataset.appModalTitle });
      }
    });
    // Stop a previewed PDF / video from staying loaded once the pop-up is closed
    modalEl.addEventListener('hidden.bs.modal', function () { request++; body.innerHTML = ''; });
    // Forms inside the pop-up: no double submit
    body.addEventListener('submit', function (e) {
      var btns = e.target.querySelectorAll('button[type="submit"], button:not([type])');
      setTimeout(function () { btns.forEach(function (b) { b.disabled = true; }); }, 0);   // after the clicked button's value is sent
    });
  }

  // Fields that apply to some document types only (review pop-up): a select with data-type-switch
  // ("Type|Subtype" values) shows the [data-types~=Type] parts of its form; hidden ones are disabled
  // so they aren't sent. The server renders the starting state.
  document.addEventListener('change', function (e) {
    if (!e.target.matches || !e.target.matches('[data-type-switch]') || !e.target.form) return;
    var type = e.target.value.split('|')[0];
    e.target.form.querySelectorAll('[data-types]').forEach(function (el) {
      var show = el.dataset.types.split(' ').indexOf(type) !== -1;
      el.hidden = !show;
      el.querySelectorAll('input, select, textarea').forEach(function (i) { i.disabled = !show; });
    });
  });

  // ----- header search ----------------------------------------------------------
  var search = document.getElementById('headerSearch');
  if (search) {
    var input = document.getElementById('headerSearchInput');
    var btn = search.querySelector('.header-search-btn');
    var setOpen = function (open) {
      search.classList.toggle('open', open);
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    };
    var run = function () {
      var q = input.value.trim();
      if (!q) { input.focus(); return; }
      if (window.openAppModal) {
        window.openAppModal(search.dataset.endpoint + '&q=' + encodeURIComponent(q), { title: 'Search results' });
      } else {
        search.submit();
      }
    };
    // Hover opens it (CSS); a click opens it and puts the cursor in it, a second click searches
    btn.addEventListener('click', function () {
      if (search.classList.contains('open') && input.value.trim()) { run(); return; }
      setOpen(true);
      input.focus();
    });
    input.addEventListener('focus', function () { setOpen(true); });
    input.addEventListener('blur', function () { setTimeout(function () { if (!input.value.trim()) setOpen(false); }, 150); });
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') { input.value = ''; input.blur(); setOpen(false); }
    });
    search.addEventListener('submit', function (e) { e.preventDefault(); run(); });
  }
})();
