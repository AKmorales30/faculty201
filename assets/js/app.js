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
    var footerExtra = document.getElementById('appModalFooterExtra');
    var request = 0;

    var openAppModal = function (url, opts) {
      opts = opts || {};
      var mine = ++request;
      dialog.classList.remove('modal-lg', 'modal-xl');
      dialog.classList.add(opts.size === 'lg' ? 'modal-lg' : 'modal-xl');
      title.textContent = opts.title || 'Loading…';
      footerExtra.innerHTML = '';
      body.innerHTML = '<div class="text-center text-muted py-5" role="status"><span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Loading…</div>';
      modal.show();
      fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (res) { return res.text().then(function (html) { return { ok: res.ok, status: res.status, html: html }; }); })
        .then(function (r) {
          if (mine !== request) return;   // a newer pop-up was opened meanwhile
          if (r.status === 401) { window.location.reload(); return; }   // signed out: the page sends them to the login
          body.innerHTML = r.html;
          var head = body.querySelector('[data-modal-title]');
          title.textContent = head ? head.dataset.modalTitle : (opts.title || (r.ok ? '' : 'Something went wrong'));
          // The fragment's footer part (e.g. "Open as a full page") goes next to the Close button
          var foot = body.querySelector('[data-modal-footer]');
          if (foot) { footerExtra.appendChild(foot); }
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
    modalEl.addEventListener('hidden.bs.modal', function () { request++; body.innerHTML = ''; footerExtra.innerHTML = ''; });
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

  // ----- Back after logging out -------------------------------------------------------
  // A page the browser restores from its back / forward cache (without asking the
  // server) is loaded again, so after logging out the server sends the login page.
  window.addEventListener('pageshow', function (e) {
    if (e.persisted) window.location.reload();
  });

  // ----- header search: results while typing (api/search.php) ---------------------
  // From 2 characters, 300 ms after the last keystroke; a newer search cancels the
  // one still on its way. Grouped results (documents; faculty for the Admin, Program
  // Chairs and Deans), the typed text in bold. Up / Down move, Enter opens the
  // selected result (or, with none selected, all results), Esc or a click outside
  // closes. Everything from the server is set as text, never as HTML.
  var search = document.getElementById('headerSearch');
  if (search) {
    var input = document.getElementById('headerSearchInput');
    var btn = search.querySelector('.header-search-btn');
    var box = document.getElementById('headerSearchResults');
    var liveUrl = search.dataset.liveEndpoint;
    var timer = null, controller = null, active = -1, lastQ = '';

    var setOpen = function (open) {
      search.classList.toggle('open', open);
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    };
    var showBox = function (show) {
      if (!box) return;
      box.hidden = !show;
      input.setAttribute('aria-expanded', show ? 'true' : 'false');
      if (!show) { active = -1; input.removeAttribute('aria-activedescendant'); }
    };
    var options = function () { return box ? Array.prototype.slice.call(box.querySelectorAll('[role="option"]')) : []; };
    var run = function () {   // all results: the search results pop-up (or the role's document list)
      var q = input.value.trim();
      if (!q) { input.focus(); return; }
      showBox(false);
      if (window.openAppModal) {
        window.openAppModal(search.dataset.endpoint + '&q=' + encodeURIComponent(q), { title: 'Search results' });
      } else {
        search.submit();
      }
    };
    var openItem = function (item) {
      showBox(false);
      if (item.modal && window.openAppModal) {
        window.openAppModal(item.modal, { title: item.group === 'faculty' ? 'Faculty profile' : 'Document', size: item.group === 'faculty' ? 'lg' : undefined });
      } else if (item.url) {
        window.location.href = item.url;
      }
    };

    // The text with every occurrence of q in bold (text nodes only)
    var highlight = function (el, text, q) {
      var lower = text.toLowerCase(), needle = q.toLowerCase(), at = 0, i;
      while (needle && (i = lower.indexOf(needle, at)) !== -1) {
        if (i > at) el.appendChild(document.createTextNode(text.slice(at, i)));
        var b = document.createElement('strong');
        b.textContent = text.slice(i, i + needle.length);
        el.appendChild(b);
        at = i + needle.length;
      }
      if (at < text.length) el.appendChild(document.createTextNode(text.slice(at)));
    };
    var message = function (text) {
      box.textContent = '';
      var p = document.createElement('div');
      p.className = 'header-search-note';
      p.textContent = text;
      box.appendChild(p);
      showBox(true);
    };
    var render = function (data, q) {
      box.textContent = '';
      var n = 0;
      (data.groups || []).forEach(function (g) {
        if (!g.items || !g.items.length) return;
        var head = document.createElement('div');
        head.className = 'header-search-group';
        head.setAttribute('role', 'presentation');
        head.textContent = g.label;
        box.appendChild(head);
        g.items.forEach(function (item) {
          item.group = g.key;
          var row = document.createElement('div');
          row.className = 'header-search-item';
          row.id = 'headerSearchOpt' + n++;
          row.setAttribute('role', 'option');
          row.setAttribute('aria-selected', 'false');
          var icon = document.createElement('span');
          icon.className = 'header-search-icon';
          icon.setAttribute('aria-hidden', 'true');
          var i = document.createElement('i');
          i.className = 'fa-solid';
          if (/^fa-[a-z0-9-]+$/.test(item.icon || '')) i.classList.add(item.icon);
          icon.appendChild(i);
          var text = document.createElement('span');
          text.className = 'header-search-text';
          var title = document.createElement('span');
          title.className = 'header-search-title';
          highlight(title, item.title || '', q);
          var detail = document.createElement('span');
          detail.className = 'header-search-detail';
          highlight(detail, item.detail || '', q);
          text.appendChild(title);
          text.appendChild(detail);
          row.appendChild(icon);
          row.appendChild(text);
          row.addEventListener('mousedown', function (e) { e.preventDefault(); });   // keep the focus in the box
          row.addEventListener('click', function () { openItem(item); });
          row._item = item;
          box.appendChild(row);
        });
      });
      if (!n) {
        message('No results found for ‘' + q + '’');
      }
      var all = document.createElement('button');
      all.type = 'button';
      all.className = 'header-search-all';
      all.textContent = 'See all results';
      all.addEventListener('mousedown', function (e) { e.preventDefault(); });
      all.addEventListener('click', run);
      box.appendChild(all);
      showBox(true);
    };
    var lookup = function () {
      var q = input.value.trim();
      if (q.length < 2 || !liveUrl || !box) { if (controller) controller.abort(); showBox(false); lastQ = ''; return; }
      if (q === lastQ && !box.hidden) return;
      lastQ = q;
      if (controller) controller.abort();
      controller = window.AbortController ? new AbortController() : null;
      message('Searching…');
      fetch(liveUrl + '?q=' + encodeURIComponent(q), { credentials: 'same-origin', headers: { 'Accept': 'application/json' }, signal: controller ? controller.signal : undefined })
        .then(function (res) {
          if (res.status === 401) { window.location.reload(); throw new Error('signed out'); }
          return res.json();
        })
        .then(function (data) {
          if (input.value.trim() !== q) return;   // typed on since: a newer search follows
          if (!data.ok) { message(data.error || 'Search isn’t available right now.'); return; }
          render(data, q);
        })
        .catch(function (err) {
          if (err && err.name === 'AbortError') return;
          if (err && err.message === 'signed out') return;
          message('Search isn’t available right now.');
        });
    };
    var move = function (step) {
      var opts = options();
      if (!opts.length) return;
      if (active >= 0 && opts[active]) opts[active].setAttribute('aria-selected', 'false');
      active = (active + step + opts.length) % opts.length;
      opts[active].setAttribute('aria-selected', 'true');
      opts[active].scrollIntoView({ block: 'nearest' });
      input.setAttribute('aria-activedescendant', opts[active].id);
    };

    // Hover opens it (CSS); a click opens it and puts the cursor in it, a second click searches
    btn.addEventListener('click', function () {
      if (search.classList.contains('open') && input.value.trim()) { run(); return; }
      setOpen(true);
      input.focus();
    });
    input.addEventListener('focus', function () { setOpen(true); if (input.value.trim().length >= 2 && box && box.childNodes.length) showBox(true); });
    input.addEventListener('blur', function () {
      setTimeout(function () {
        if (search.contains(document.activeElement)) return;
        showBox(false);
        if (!input.value.trim()) setOpen(false);
      }, 150);
    });
    input.addEventListener('input', function () {
      clearTimeout(timer);
      timer = setTimeout(lookup, 300);
    });
    input.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowDown') { e.preventDefault(); if (box && box.hidden) { lookup(); } else { move(1); } }
      else if (e.key === 'ArrowUp') { e.preventDefault(); move(-1); }
      else if (e.key === 'Enter' && active >= 0 && options()[active]) { e.preventDefault(); openItem(options()[active]._item); }
      else if (e.key === 'Escape') {
        if (box && !box.hidden) { showBox(false); }
        else { input.value = ''; input.blur(); setOpen(false); }
      }
    });
    document.addEventListener('mousedown', function (e) {
      if (box && !box.hidden && !search.contains(e.target)) showBox(false);
    });
    search.addEventListener('submit', function (e) { e.preventDefault(); clearTimeout(timer); run(); });
  }
})();
