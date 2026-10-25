/**
 * Program Chair / Dean Analytics (approval/analytics.php). Loads the numbers
 * from api/analytics.php (scoped on the server) and draws them with the
 * shared chart helper (assets/js/charts.js):
 *   - the date filter reloads the data in place, and the
 *     address follows them (it can be bookmarked); without JavaScript the
 *     form is an ordinary GET form
 *   - cards count up, charts draw in (not under reduced motion)
 *   - each chart shows a loading state, then the chart or "No data available"
 *   - "Faculty needing attention" rows open the existing one-category pop-up
 * Every text from the server is set as text (textContent), never as HTML.
 */
(function () {
  'use strict';

  var root = document.getElementById('analyticsRoot');
  var form = document.getElementById('analyticsFilters');
  if (!root || !form || !window.F201Charts) return;

  var C = window.F201Charts;
  var colors = C.colors;
  var endpoint = root.getAttribute('data-endpoint');
  var status = document.getElementById('anStatus');
  var data = null;
  var facultyGroup = 'ranks';
  var request = 0;

  function el(id) { return document.getElementById(id); }
  function has(series) { return !!series && series.values.some(function (v) { return v > 0; }); }
  function axis() { return { beginAtZero: true, ticks: { precision: 0 } }; }

  function setLoading(on) {
    root.querySelectorAll('[data-chart-wrap], .an-stat, #anAttention').forEach(function (w) { C.loading(w, on); });
  }

  // ----- cards ------------------------------------------------------------------
  function cards(c) {
    var subs = {
      pds_incomplete: c.pds_total ? 'of ' + c.pds_total.toLocaleString() + ' full-time faculty' : 'full-time faculty only',
      expiring: c.expired ? c.expired.toLocaleString() + ' already expired' : 'documents, within ' + (root.getAttribute('data-soon-days') || '60') + ' days'
    };
    root.querySelectorAll('.an-stat').forEach(function (card) {
      var key = card.getAttribute('data-card');
      C.loading(card, false);
      C.countUp(card.querySelector('[data-value]'), c[key]);
      if (subs[key]) card.querySelector('[data-sub]').textContent = subs[key];
    });
  }

  // ----- charts -------------------------------------------------------------------
  function charts(d) {
    C.draw(el('chUploads'), {
      type: 'line',
      data: { labels: d.uploads.labels, datasets: [{ label: 'Documents uploaded', data: d.uploads.values, borderColor: colors.teal,
        backgroundColor: 'rgba(0,101,96,.12)', fill: true, tension: .3, pointRadius: 3 }] },
      options: { plugins: { legend: { display: false } }, scales: { y: axis() } }
    }, has(d.uploads));

    C.draw(el('chCategories'), {
      type: 'doughnut',
      data: { labels: d.categories.labels, datasets: [{ data: d.categories.values, backgroundColor: C.palette }] },
      options: { plugins: { legend: { position: 'bottom' } }, cutout: '58%' }
    }, has(d.categories));

    C.draw(el('chExpiration'), {
      type: 'doughnut',
      data: { labels: d.expiration.labels, datasets: [{ data: d.expiration.values, backgroundColor: [colors.teal, colors.gold, colors.red] }] },
      options: { plugins: { legend: { position: 'bottom' } }, cutout: '58%' }
    }, has(d.expiration));

    var perYear = d.trainings.per === 'year';
    el('chTrainingsTitle').textContent = 'Seminars / trainings attended per ' + (perYear ? 'year' : 'month');
    C.draw(el('chTrainings'), {
      type: 'bar',
      data: { labels: d.trainings.labels, datasets: [{ label: 'Seminars / trainings', data: d.trainings.values, backgroundColor: colors.gold, borderRadius: 6 }] },
      options: { plugins: { legend: { display: false } }, scales: { y: axis() } }
    }, has(d.trainings));

    C.draw(el('chOrganizers'), {
      type: 'bar',
      data: { labels: d.organizers.labels, datasets: [{ label: 'Seminars / trainings', data: d.organizers.values, backgroundColor: colors.navy, borderRadius: 6 }] },
      options: { indexAxis: 'y', plugins: { legend: { display: false } }, scales: { x: axis() } }
    }, has(d.organizers));

    drawFaculty();

    var pe = d.per_employment;
    C.draw(el('chEmployment'), {
      type: 'bar',
      data: { labels: pe.labels, datasets: [
        { label: 'Faculty', data: pe.faculty, backgroundColor: colors.teal, borderRadius: 6 },
        { label: 'Documents in 201-Files', data: pe.documents, backgroundColor: colors.navy, borderRadius: 6 },
        { label: 'Seminars / trainings', data: pe.trainings, backgroundColor: colors.gold, borderRadius: 6 }
      ] },
      options: { plugins: { legend: { position: 'bottom' } }, scales: { y: axis() } }
    }, pe.faculty.concat(pe.documents, pe.trainings).some(function (v) { return v > 0; }));
  }

  function drawFaculty() {
    var series = data[facultyGroup];
    el('chFacultyTitle').textContent = facultyGroup === 'ranks' ? 'Faculty by rank' : 'Faculty by employment type';
    el('chFaculty').setAttribute('aria-label', 'Doughnut chart of faculty by ' + (facultyGroup === 'ranks' ? 'academic rank' : 'employment type'));
    C.draw(el('chFaculty'), {
      type: 'doughnut',
      data: { labels: series.labels, datasets: [{ data: series.values, backgroundColor: C.palette }] },
      options: { plugins: { legend: { position: 'bottom' } }, cutout: '58%' }
    }, has(series));
  }

  root.querySelectorAll('.an-toggle [data-group]').forEach(function (button) {
    button.addEventListener('click', function () {
      facultyGroup = button.getAttribute('data-group');
      root.querySelectorAll('.an-toggle [data-group]').forEach(function (b) {
        var on = b === button;
        b.classList.toggle('active', on);
        b.setAttribute('aria-pressed', on ? 'true' : 'false');
      });
      if (data) drawFaculty();
    });
  });

  // ----- faculty needing attention --------------------------------------------------
  function attention(rows) {
    var box = el('anAttention');
    box.textContent = '';
    C.loading(box, false);
    rows.forEach(function (r) {
      var row = document.createElement(r.count ? 'button' : 'div');
      row.className = 'an-attention-row';
      if (r.count) {
        row.type = 'button';
        row.setAttribute('data-app-modal', r.modal);
        row.setAttribute('data-app-modal-title', r.label);
      }
      var icon = document.createElement('span');
      icon.className = 'ui-head-icon tone-navy';
      icon.setAttribute('aria-hidden', 'true');
      icon.innerHTML = '<i class="fa-solid"></i>';
      icon.firstChild.classList.add(r.icon);
      var text = document.createElement('span');
      text.className = 'an-attention-text';
      var label = document.createElement('span');
      label.className = 'an-attention-label';
      label.textContent = r.label;
      var names = document.createElement('span');
      names.className = 'an-attention-names';
      names.textContent = r.count ? r.names.join(', ') + (r.count > r.names.length ? ' and ' + (r.count - r.names.length) + ' more' : '') : 'No one right now';
      text.appendChild(label);
      text.appendChild(names);
      var n = document.createElement('span');
      n.className = 'an-attention-n';
      n.textContent = r.count.toLocaleString();
      row.appendChild(icon);
      row.appendChild(text);
      row.appendChild(n);
      box.appendChild(row);
    });
  }

  // ----- loading ---------------------------------------------------------------------
  function params() {
    var p = new URLSearchParams();
    ['from', 'to'].forEach(function (name) {
      var input = form.elements[name];
      if (input && input.value) p.set(name, input.value);
    });
    return p;
  }

  function load(push) {
    var id = ++request;
    var p = params();
    setLoading(true);
    status.textContent = 'Loading…';
    if (push) history.replaceState(null, '', window.location.pathname + (p.toString() ? '?' + p.toString() : ''));
    fetch(endpoint + '?' + p.toString(), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (res) {
        if (res.status === 401) { window.location.reload(); throw new Error('signed out'); }
        return res.json().then(function (body) { if (!res.ok || !body.ok) throw new Error(body.error || 'Error'); return body; });
      })
      .then(function (body) {
        if (id !== request) return;   // a newer filter change is on its way
        data = body;
        cards(body.cards);
        charts(body);
        attention(body.attention);
        status.textContent = body.scope.label + ' · ' + body.scope.from + ' to ' + body.scope.to;
        if (form.elements.from) form.elements.from.value = body.scope.from;
        if (form.elements.to) form.elements.to.value = body.scope.to;
      })
      .catch(function (err) {
        if (id !== request) return;
        setLoading(false);
        status.textContent = err.message === 'signed out' ? '' : 'The analytics could not be loaded: ' + err.message;
      });
  }

  form.addEventListener('submit', function (e) { e.preventDefault(); load(true); });
  form.querySelectorAll('input, select').forEach(function (input) {
    input.addEventListener('change', function () { load(true); });
  });

  load(false);
})();
