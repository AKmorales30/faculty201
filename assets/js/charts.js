/**
 * Shared Chart.js setup (Chart.js 4.4.4, loaded before this file): the
 * system's chart palette and font (the same as admin/analytics.php), exact
 * numbers in tooltips, "No data available" and loading states, responsive
 * sizing, and the draw-in animation -- off under prefers-reduced-motion.
 *
 * Markup for one chart:
 *   <div class="ui-chart" data-chart-wrap>
 *     <canvas id="..." role="img" aria-label="..."></canvas>
 *     <div class="ui-chart-empty" hidden>No data available</div>
 *   </div>
 * Used by approval/analytics.php.
 *
 *   F201Charts.draw(canvas, config, hasData)  (re)draws a chart, or shows the empty note
 *   F201Charts.loading(wrapOrCard, on)        loading state on a chart / card
 *   F201Charts.countUp(el, value)             number counting up to value
 *   F201Charts.colors / .palette              the system's colors
 */
(function () {
  'use strict';

  var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  var colors = { navy: '#003283', teal: '#006560', gold: '#D99C0B', blue: '#0077AE', charcoal: '#5A6577', red: '#B42318' };
  var palette = [colors.navy, colors.gold, colors.blue, colors.teal, '#5B7DB8', '#6FA9A4', '#8A6206', '#B8BEC9', colors.charcoal, '#2F4B7C'];
  var charts = {};

  if (window.Chart) {
    Chart.defaults.font.family = "'Public Sans', system-ui, sans-serif";
    Chart.defaults.color = colors.charcoal;
    Chart.defaults.maintainAspectRatio = false;
    Chart.defaults.responsive = true;
    Chart.defaults.plugins.legend.labels.boxWidth = 12;
    Chart.defaults.plugins.tooltip.callbacks.label = function (ctx) {
      // Exact numbers; a pie / doughnut slice also gets its share
      var value = ctx.parsed !== null && typeof ctx.parsed === 'object' ? (ctx.chart.options.indexAxis === 'y' ? ctx.parsed.x : ctx.parsed.y) : ctx.parsed;
      var name = ctx.dataset.label && ctx.chart.data.datasets.length > 1 ? ctx.dataset.label : (ctx.label || '');
      var text = (name ? name + ': ' : '') + Number(value).toLocaleString();
      if (ctx.chart.config.type === 'doughnut' || ctx.chart.config.type === 'pie') {
        var total = ctx.dataset.data.reduce(function (a, b) { return a + (Number(b) || 0); }, 0);
        if (total) text += ' (' + Math.round(value / total * 100) + '%)';
      }
      return text;
    };
    if (reduced) {
      Chart.defaults.animation = false;
    } else {
      Chart.defaults.animation.duration = 700;
    }
  }

  function wrapOf(el) {
    return el.closest ? el.closest('[data-chart-wrap]') : null;
  }

  function loading(el, on) {
    if (!el) return;
    el.classList.toggle('is-loading', !!on);
    el.setAttribute('aria-busy', on ? 'true' : 'false');
  }

  function draw(canvas, config, hasData) {
    if (!canvas || !window.Chart) return null;
    var wrap = wrapOf(canvas);
    var empty = wrap ? wrap.querySelector('.ui-chart-empty') : null;
    if (charts[canvas.id]) {
      charts[canvas.id].destroy();
      delete charts[canvas.id];
    }
    loading(wrap, false);
    if (empty) empty.hidden = !!hasData;
    canvas.hidden = !hasData;
    if (!hasData) return null;
    charts[canvas.id] = new Chart(canvas, config);
    return charts[canvas.id];
  }

  function countUp(el, value) {
    value = Number(value) || 0;
    if (reduced || value <= 0) { el.textContent = value.toLocaleString(); return; }
    var start = null, ms = 700;
    function step(t) {
      if (start === null) start = t;
      var p = Math.min(1, (t - start) / ms);
      el.textContent = Math.round(value * (1 - Math.pow(1 - p, 3))).toLocaleString();
      if (p < 1) requestAnimationFrame(step);
    }
    requestAnimationFrame(step);
  }

  window.F201Charts = { draw: draw, loading: loading, countUp: countUp, colors: colors, palette: palette, reduced: reduced };
})();
