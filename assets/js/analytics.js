/**
 * Data Analytics charts (admin/analytics.php). Needs Chart.js v4
 * (assets/js/chart.umd.min.js, loaded first). Each chart's data comes from
 * the JSON block #analytics-data as { labels: [...], values: [...] } and is
 * drawn into the [data-chart="<key>"] box; an empty dataset shows
 * "No data available" instead of a blank chart.
 */
document.addEventListener('DOMContentLoaded', function () {
  const boxes = document.querySelectorAll('[data-chart]');

  function showMessage(box, text) {
    box.innerHTML = '<div class="chart-empty"><i class="fa-regular fa-chart-bar"></i><span></span></div>';
    box.querySelector('span').textContent = text;
  }

  if (typeof Chart === 'undefined') {
    console.error('Data Analytics: Chart.js did not load -- check that assets/js/chart.umd.min.js is reachable (Network tab).');
    boxes.forEach(box => showMessage(box, 'Charts could not be loaded.'));
    return;
  }

  let data;
  try {
    data = JSON.parse(document.getElementById('analytics-data').textContent);
  } catch (e) {
    console.error('Data Analytics: chart data is not valid JSON.', e);
    boxes.forEach(box => showMessage(box, 'Chart data could not be read.'));
    return;
  }

  // Brand colors (assets/css/style.css :root)
  const navy = '#1b4b93', teal = '#157975', gold = '#e0aa28', gray = '#8a9099', red = '#dc3545';

  Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
  Chart.defaults.color = '#6c757d';
  Chart.defaults.maintainAspectRatio = false;   // the .chart-box sets the height
  Chart.defaults.plugins.legend.labels.boxWidth = 12;

  const countAxis = { beginAtZero: true, ticks: { precision: 0 }, grid: { color: '#eef0f3' }, border: { display: false } };
  const labelAxis = { grid: { display: false } };

  function bar(d, horizontal) {
    return {
      type: 'bar',
      data: { labels: d.labels, datasets: [{ label: 'Documents', data: d.values, backgroundColor: navy, borderRadius: 4, maxBarThickness: 36 }] },
      options: {
        indexAxis: horizontal ? 'y' : 'x',
        plugins: { legend: { display: false } },
        scales: horizontal ? { x: countAxis, y: labelAxis } : { x: labelAxis, y: countAxis },
      },
    };
  }

  // Legend labels carry the count, so a slice is never told apart by color alone
  function doughnut(d, colors) {
    return {
      type: 'doughnut',
      data: {
        labels: d.labels.map((label, i) => label + ' (' + d.values[i] + ')'),
        datasets: [{ data: d.values, backgroundColor: colors, borderColor: '#fff', borderWidth: 2 }],
      },
      options: { cutout: '60%', plugins: { legend: { position: 'bottom' } } },
    };
  }

  const builders = {
    monthly: d => ({
      type: 'line',
      data: {
        labels: d.labels,
        datasets: [{ label: 'Documents uploaded', data: d.values, borderColor: teal, backgroundColor: 'rgba(21,121,117,.12)',
                     borderWidth: 2, pointRadius: 4, pointBackgroundColor: teal, fill: true, cubicInterpolationMode: 'monotone' }],
      },
      options: {
        interaction: { mode: 'index', intersect: false },
        plugins: { legend: { display: false } },
        scales: { x: labelAxis, y: countAxis },
      },
    }),
    by_category: d => bar(d, true),
    by_program:  d => bar(d, false),
    uploaders:   d => bar(d, true),
    expiration:  d => doughnut(d, [teal, gold, red]),
    employment:  d => doughnut(d, [navy, gold, gray]),
    confidence:  d => doughnut(d, [navy, gold, gray]),
  };

  boxes.forEach(function (box) {
    const key = box.dataset.chart;
    const d = data[key];
    const build = builders[key];
    if (!build) {
      console.error('Data Analytics: no chart defined for "' + key + '".');
      return;
    }
    if (!d || !Array.isArray(d.values) || d.values.reduce((sum, v) => sum + Number(v), 0) === 0) {
      showMessage(box, 'No data available');
      return;
    }
    try {
      new Chart(box.querySelector('canvas'), build(d));
    } catch (e) {
      // One broken chart must not stop the others
      console.error('Data Analytics: chart "' + key + '" failed.', e);
      showMessage(box, 'This chart could not be displayed.');
    }
  });
});
