/**
 * Every page after login (loaded by includes/footer.php, after app.js):
 *   - the toast after saving slides in, then hides itself (or with its X)
 *   - numbers marked data-count count up, bars / columns / the donut grow in
 *   - filter forms marked data-autosubmit apply as soon as a filter changes
 *   - search boxes in forms marked data-live-search filter the table while typing
 *   - Change Password: the password rules tick as they are met
 *   - rows marked data-href open that page (click, Enter or Space)
 *   - forms marked data-confirm ask first in the confirmation pop-up (#adConfirm)
 * Everything works without it; prefers-reduced-motion skips the animations.
 */
(function () {
  'use strict';

  var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  // ----- toast ----------------------------------------------------------------
  document.querySelectorAll('.ad-toast').forEach(function (toast) {
    var timer = null;
    var hide = function () {
      toast.classList.add('is-leaving');
      setTimeout(function () { toast.remove(); }, reduced ? 0 : 280);
    };
    var arm = function () { clearTimeout(timer); timer = setTimeout(hide, 5000); };
    toast.querySelector('.ad-toast-close').addEventListener('click', hide);
    toast.addEventListener('mouseenter', function () { clearTimeout(timer); });
    toast.addEventListener('mouseleave', arm);
    toast.addEventListener('focusin', function () { clearTimeout(timer); });
    arm();
  });

  // ----- count-up and growing charts ----------------------------------------
  // The real value is already in the page; it is only replayed from 0.
  var countUp = function (el) {
    var target = parseInt(el.getAttribute('data-count'), 10);
    if (isNaN(target) || target <= 0 || reduced) return;
    var t0 = performance.now();
    var step = function (t) {
      var k = Math.min(1, (t - t0) / 800);
      el.textContent = Math.round(target * (1 - Math.pow(1 - k, 3))).toLocaleString();
      if (k < 1) requestAnimationFrame(step);
    };
    el.textContent = '0';
    requestAnimationFrame(step);
  };
  document.querySelectorAll('[data-count]').forEach(countUp);

  var reveal = function (el) { el.classList.add('is-in'); };
  var animated = document.querySelectorAll('[data-animate]');
  if (reduced || !('IntersectionObserver' in window)) {
    animated.forEach(reveal);
  } else {
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (e) {
        if (e.isIntersecting) { reveal(e.target); io.unobserve(e.target); }
      });
    }, { threshold: 0.15 });
    // Two frames later, so the starting (empty) state is painted and the bars visibly grow
    requestAnimationFrame(function () { requestAnimationFrame(function () { animated.forEach(function (el) { io.observe(el); }); }); });
  }

  // ----- filters apply on change ---------------------------------------------
  document.querySelectorAll('form[data-autosubmit]').forEach(function (form) {
    form.addEventListener('change', function (e) {
      // Dates and typed text wait for the Search / Apply button (a range needs both ends)
      if (!e.target.matches('select, input[type="checkbox"], input[type="radio"]')) return;
      if (form.requestSubmit) { form.requestSubmit(); } else { form.submit(); }
    });
  });

  // ----- table search: filters while typing ---------------------------------------
  // A form marked data-live-search reloads its results 300 ms after typing stops in
  // its search box (name="q"): the page is fetched with the form's other filters
  // (back to page 1) and only the results are swapped -- everything after the form in
  // its card, and its result count -- so the box keeps the cursor. data-live-search="#id"
  // names the results instead. The address keeps the search, so a refresh shows the
  // same. A newer search cancels the one on its way. Enter still submits normally.
  var liveTimer = null, liveCtrl = null;
  var liveRefresh = function (form, input) {
    var target = form.getAttribute('data-live-search');
    var card = form.closest('.ui-card, .card');
    if (!target && !card) return;
    var params = new URLSearchParams(new FormData(form));
    params.delete('page');
    var url = (form.getAttribute('action') || window.location.pathname) + '?' + params.toString();
    var forms = Array.prototype.slice.call(document.querySelectorAll('form[data-live-search]'));
    var index = forms.indexOf(form);
    if (liveCtrl) liveCtrl.abort();
    liveCtrl = window.AbortController ? new AbortController() : null;
    (target ? document.querySelector(target) : card).setAttribute('aria-busy', 'true');
    fetch(url, { credentials: 'same-origin', headers: { 'X-Live-Search': '1' }, signal: liveCtrl ? liveCtrl.signal : undefined })
      .then(function (res) {
        if (res.status === 401 || res.redirected && /index\.php/.test(res.url)) { window.location.reload(); throw new Error('signed out'); }
        if (!res.ok) throw new Error('HTTP ' + res.status);
        return res.text();
      })
      .then(function (html) {
        var doc = new DOMParser().parseFromString(html, 'text/html');
        if (target) {
          var fresh = doc.querySelector(target), old = document.querySelector(target);
          if (!fresh || !old) { window.location.href = url; return; }
          old.replaceWith(document.importNode(fresh, true));
        } else {
          var newForm = doc.querySelectorAll('form[data-live-search]')[index];
          var newCard = newForm && newForm.closest('.ui-card, .card');
          if (!newCard) { window.location.href = url; return; }
          // Everything after the form: the table, its footer and pager
          var parent = form.parentNode;
          while (form.nextSibling) parent.removeChild(form.nextSibling);
          var node = newForm.nextSibling;
          while (node) { var next = node.nextSibling; parent.appendChild(document.importNode(node, true)); node = next; }
          var count = form.querySelector('.ui-count'), newCount = newForm.querySelector('.ui-count');
          if (count && newCount) count.textContent = newCount.textContent;
          card.removeAttribute('aria-busy');
        }
        history.replaceState(null, '', url);
      })
      .catch(function (err) {
        if (err && (err.name === 'AbortError' || err.message === 'signed out')) return;
        if (form.requestSubmit) { form.requestSubmit(); } else { form.submit(); }   // fall back to an ordinary search
      });
  };
  document.addEventListener('input', function (e) {
    var input = e.target;
    if (!input.matches || !input.matches('form[data-live-search] input[name="q"]')) return;
    clearTimeout(liveTimer);
    liveTimer = setTimeout(function () { liveRefresh(input.form, input); }, 300);
  });

  // ----- rows that open a page --------------------------------------------------
  var openRow = function (row, e) {
    if (e.target.closest('a, button, input, select, textarea, label, form')) return;
    if (e.ctrlKey || e.metaKey) { window.open(row.dataset.href, '_blank'); return; }
    window.location.href = row.dataset.href;
  };
  document.addEventListener('click', function (e) {
    var row = e.target.closest && e.target.closest('tr[data-href]');
    if (row) openRow(row, e);
  });
  document.addEventListener('keydown', function (e) {
    if ((e.key === 'Enter' || e.key === ' ') && e.target.matches && e.target.matches('tr[data-href]')) {
      e.preventDefault();
      window.location.href = e.target.dataset.href;
    }
  });

  // ----- password rules (Change Password) --------------------------------------
  document.querySelectorAll('[data-pw-rules]').forEach(function (list) {
    var form = list.closest('form');
    var nw = form && form.querySelector('[data-pw="new"]');
    var cf = form && form.querySelector('[data-pw="confirm"]');
    if (!nw) return;
    var tests = {
      length: function (v) { return v.length >= 8; },
      upper: function (v) { return /[A-Z]/.test(v); },
      lower: function (v) { return /[a-z]/.test(v); },
      number: function (v) { return /[0-9]/.test(v); },
      match: function (v) { return v.length > 0 && cf && v === cf.value; }
    };
    var update = function () {
      list.querySelectorAll('[data-rule]').forEach(function (li) {
        var ok = tests[li.dataset.rule] ? tests[li.dataset.rule](nw.value) : false;
        li.classList.toggle('is-ok', ok);
        var sr = li.querySelector('.visually-hidden');
        if (sr) sr.textContent = ok ? ' (done)' : ' (not yet)';
      });
    };
    nw.addEventListener('input', update);
    if (cf) cf.addEventListener('input', update);
    update();
  });

  // ----- unsaved changes -------------------------------------------------------------
  // A form whose fields were changed and not yet sent, or a page marked
  // data-unsaved-work (e.g. an upload waiting in its preview). Search and filter
  // forms don't count. Used by the Log out pop-up (data-confirm-unsaved).
  var dirtyForms = [];
  var countsAsWork = function (form) {
    return form && !form.matches('[role="search"], [data-autosubmit], [data-confirm-unsaved], #headerSearch, .ad-logout-form');
  };
  ['input', 'change'].forEach(function (type) {
    document.addEventListener(type, function (e) {
      var form = e.target.form || (e.target.closest && e.target.closest('form'));
      if (countsAsWork(form) && dirtyForms.indexOf(form) === -1) dirtyForms.push(form);
    });
  });
  document.addEventListener('submit', function (e) {
    var i = dirtyForms.indexOf(e.target);
    if (i !== -1) dirtyForms.splice(i, 1);
  });
  var hasUnsavedWork = function () {
    return dirtyForms.some(function (f) { return document.contains(f); }) || !!document.querySelector('[data-unsaved-work]');
  };

  // ----- confirmation pop-up ------------------------------------------------------
  // <form data-confirm="text" data-confirm-title="..." data-confirm-ok="Deactivate" data-confirm-danger="1">
  // Optional: data-confirm-cancel (the Cancel button's label), data-confirm-focus="cancel"
  // (focus Cancel instead of nothing), data-confirm-unsaved (the text instead, when the
  // page has unsaved changes). Esc or a click outside closes it, like Cancel.
  var confirmEl = document.getElementById('adConfirm');
  if (confirmEl && window.bootstrap) {
    var confirmModal = bootstrap.Modal.getOrCreateInstance(confirmEl);
    var okBtn = document.getElementById('adConfirmOk');
    var cancelBtn = document.getElementById('adConfirmCancel');
    var pending = null;
    document.addEventListener('submit', function (e) {
      var form = e.target;
      if (!form.matches || !form.matches('form[data-confirm]') || form.dataset.confirmed === '1') return;
      e.preventDefault();
      pending = { form: form, submitter: e.submitter || null };
      document.getElementById('adConfirmTitle').textContent = form.dataset.confirmTitle || 'Are you sure?';
      document.getElementById('adConfirmText').textContent = form.dataset.confirmUnsaved && hasUnsavedWork()
        ? form.dataset.confirm + ' ' + form.dataset.confirmUnsaved : form.dataset.confirm;
      if (cancelBtn) cancelBtn.textContent = form.dataset.confirmCancel || 'Close';
      confirmEl.dataset.focus = form.dataset.confirmFocus || '';
      okBtn.textContent = form.dataset.confirmOk || 'Confirm';
      okBtn.className = 'btn ' + (form.dataset.confirmDanger ? 'btn-danger' : 'btn-brand');
      confirmModal.show();
    }, true);
    okBtn.addEventListener('click', function () {
      if (!pending) return;
      var p = pending;
      pending = null;
      okBtn.disabled = true;
      p.form.dataset.confirmed = '1';
      if (p.form.requestSubmit) { p.form.requestSubmit(p.submitter || undefined); } else { p.form.submit(); }
    });
    confirmEl.addEventListener('shown.bs.modal', function () {
      if (confirmEl.dataset.focus === 'cancel' && cancelBtn) cancelBtn.focus();
    });
    confirmEl.addEventListener('hidden.bs.modal', function () { okBtn.disabled = false; pending = null; });
  }
})();
