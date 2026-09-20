/* DeskPulse charts — thin Chart.js adapter.
 * Scans the DOM for <canvas class="dp-chart" data-type="bars|hbars|line|donut|timeline" ...>
 * and builds a Chart.js instance from the same data-* attribute contract the
 * templates already emit.
 * bars/hbars/line/donut all render as one basic flat-color vertical bar chart
 * (legacy type names kept as aliases so templates don't need to change); only
 * the continuous activity "timeline" (with screenshot markers) stays an area
 * chart, since that's a distinct feature, not a style choice.
 *
 * Chart.js was a 205 KB UMD file under assets/js/vendor and is now an npm
 * dependency bundled by Vite — same library, same version line, no CDN. That is
 * the only change to this file; the adapter below is the legacy one verbatim. */
import { Chart, registerables } from 'chart.js';

Chart.register(...registerables);

(function () {

  const _css = getComputedStyle(document.documentElement);
  const cv = (name, fb) => (_css.getPropertyValue(name).trim() || fb);
  const BRAND = cv('--brand', '#3b82f6');
  const TEAL = cv('--teal', '#2dd4bf');
  const PALETTE = [BRAND, TEAL, cv('--warn', '#fbbf24'), '#a78bfa', cv('--bad', '#f87171'), cv('--good', '#34d399')];
  const GRID = 'rgba(148,163,189,0.14)';
  const AXIS = '#aeb9d0';
  const INK = cv('--ink', '#e9eef8');
  const CARD = cv('--card', '#111a2e');
  const LINE2 = cv('--line-2', '#2c3b56');
  const MARK = TEAL;
  const FONT = '"Inter",-apple-system,"Segoe UI",Roboto,sans-serif';

  function withAlpha(color, aa) {
    return /^#[0-9a-fA-F]{6}$/.test(color) ? color + aa : color;
  }
  // Marketing-site-style gradient bar fill: a soft teal-tinted highlight at the
  // top fading into the series' own base color — same visual language as the
  // landing page's .mock-chart / .spot-viz decorative bars, applied per-series
  // so different series (e.g. Active vs Inactive) stay visually distinct.
  function barGradient(ctx, chartArea, color) {
    if (!chartArea) return color;
    const g = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom);
    g.addColorStop(0, TEAL);
    g.addColorStop(1, color);
    return g;
  }

  // ── Global Chart.js theme (dark, matches deskpulse.css tokens) ──
  Chart.defaults.color = AXIS;
  Chart.defaults.borderColor = GRID;
  Chart.defaults.font.family = FONT;
  Chart.defaults.font.size = 12.5;
  // Entrance animation disabled: this vendored Chart.js build throws
  // "this._fn is not a function" from its own Animator when several charts on
  // one page animate at once (reproduces outside headless too — not a
  // screenshot artifact), which silently leaves every canvas on the page
  // blank. Charts render instantly and reliably with animation off, which
  // also respects prefers-reduced-motion by default.
  Chart.defaults.animation = false;
  Chart.defaults.plugins.tooltip.backgroundColor = CARD;
  Chart.defaults.plugins.tooltip.titleColor = INK;
  Chart.defaults.plugins.tooltip.bodyColor = '#c3cfe6';
  Chart.defaults.plugins.tooltip.borderColor = LINE2;
  Chart.defaults.plugins.tooltip.borderWidth = 1;
  Chart.defaults.plugins.tooltip.padding = 10;
  Chart.defaults.plugins.tooltip.cornerRadius = 4;
  Chart.defaults.plugins.tooltip.boxPadding = 4;
  Chart.defaults.plugins.tooltip.titleFont = { family: FONT, weight: '700', size: 12.5 };
  Chart.defaults.plugins.tooltip.bodyFont = { family: FONT, size: 12 };
  Chart.defaults.plugins.legend.labels.color = AXIS;
  Chart.defaults.plugins.legend.labels.boxWidth = 11;
  Chart.defaults.plugins.legend.labels.boxHeight = 11;
  Chart.defaults.plugins.legend.labels.font = { family: FONT, size: 12 };
  Chart.defaults.plugins.legend.display = false;   // opt in per-chart

  function parse(canvas, attr, fallback) {
    const raw = canvas.getAttribute(attr);
    if (!raw) return fallback;
    try { return JSON.parse(raw); } catch (e) { return fallback; }
  }
  function fmtMins(mins) {
    mins = Math.round(+mins || 0);
    const hh = Math.floor(mins / 60), mm = mins % 60;
    return hh ? hh + 'h ' + mm + 'm' : mm + 'm';
  }
  function fmtNum(v) {
    v = +v || 0;
    return v >= 10 ? String(Math.round(v)) : String(Math.round(v * 10) / 10);
  }
  // ISO dates render as weekday names for a week-length series ("Mon", "Tue"…,
  // i.e. the 7 days of the work week) or "MMM D" for longer (month) series;
  // any other label (member/app name) is just truncated.
  function xLabel(lab, count) {
    const s = String(lab);
    if (/^\d{4}-\d{2}-\d{2}/.test(s)) {
      const d = new Date(s.length > 10 ? s : s + 'T00:00:00');
      if (isNaN(d)) return s;
      return count <= 8
        ? d.toLocaleDateString([], { weekday: 'short' })
        : d.toLocaleDateString([], { month: 'short', day: 'numeric' });
    }
    return s.length > 14 ? s.slice(0, 13) + '…' : s;
  }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"]/g, c => (
      { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  }
  function fmtTime(epochS) {
    return new Date(epochS * 1000).toLocaleString([], { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
  }
  // Chart.js's responsive engine must own the canvas's actual width/height —
  // setting a CSS height directly on a canvas that ALSO has an intrinsic
  // `height` attribute fights Chart.js's own resize/DPR logic and produces a
  // compounding grow/distort effect (looks like the chart "zooming" itself
  // taller on every redraw). The fix is Chart.js's own documented pattern:
  // give the canvas a wrapper with a fixed CSS height and let Chart.js size
  // the canvas to fill it (responsive:true + maintainAspectRatio:false).
  function ensureWrapper(canvas) {
    let wrap = canvas.parentNode;
    if (wrap.classList && wrap.classList.contains('dp-chart-wrap')) return wrap;
    const mini = canvas.hasAttribute('data-mini');
    const requested = parseInt(canvas.getAttribute('height') || '240', 10);
    // Mini donuts (e.g. one per row in a table) keep their exact small size —
    // the 140-220 floor below is for full-width panel charts only.
    const h = mini ? requested : Math.max(140, Math.min(220, Math.round(requested * 0.82)));   // more compact
    wrap = document.createElement('div');
    wrap.className = 'dp-chart-wrap' + (mini ? ' mini' : '');
    wrap.style.position = 'relative';
    wrap.style.height = h + 'px';
    wrap.style.width = mini ? h + 'px' : '100%';
    canvas.parentNode.insertBefore(wrap, canvas);
    wrap.appendChild(canvas);
    canvas.removeAttribute('height');
    canvas.removeAttribute('width');
    return wrap;
  }
  function baseOptions() {
    return { responsive: true, maintainAspectRatio: false, resizeDelay: 80 };
  }

  // ── Basic vertical bar chart — handles every comparison/trend chart:
  //    grouped series (data-series: e.g. active vs inactive per day) OR a
  //    single series (data-values+data-labels: top apps, per-session activity).
  //    Bars use the same teal→base gradient fill as the marketing site's
  //    decorative bar illustrations, per-series so categories stay distinct. ──
  function buildBars(canvas) {
    const labels = parse(canvas, 'data-labels', []);
    const unit = canvas.getAttribute('data-unit') || '';
    const fmt = canvas.getAttribute('data-fmt');
    const hrefs = parse(canvas, 'data-hrefs', []);
    const target = canvas.getAttribute('data-href-target');
    const hasSeries = !!canvas.getAttribute('data-series');

    let datasets, showLegend = false, showShare = false;
    if (hasSeries) {
      const series = parse(canvas, 'data-series', []);
      datasets = series.map((s, i) => {
        const base = s.color || PALETTE[i % PALETTE.length];
        return {
          label: s.name, borderRadius: 3, borderSkipped: false, maxBarThickness: 34, data: s.data,
          backgroundColor: (ctx) => barGradient(ctx.chart.ctx, ctx.chart.chartArea, base),
        };
      });
      showLegend = series.length > 1;
    } else {
      const values = parse(canvas, 'data-values', []);
      const colors = parse(canvas, 'data-colors', null);
      datasets = [{
        data: values, borderRadius: 3, borderSkipped: false, maxBarThickness: 40,
        backgroundColor: (ctx) => {
          const base = colors ? colors[ctx.dataIndex % colors.length] : PALETTE[ctx.dataIndex % PALETTE.length];
          return barGradient(ctx.chart.ctx, ctx.chart.chartArea, base);
        },
      }];
      showShare = true;
    }
    const total = showShare ? (datasets[0].data.reduce((a, b) => a + (+b || 0), 0) || 1) : 0;
    const manyLabels = labels.length > 10;

    const cfg = {
      type: 'bar',
      data: { labels, datasets },
      options: Object.assign(baseOptions(), {
        plugins: {
          legend: { display: showLegend, position: 'top', align: 'end' },
          tooltip: {
            callbacks: {
              label: (ctx) => {
                const v = ctx.parsed.y;
                let lbl = fmt === 'hm' ? fmtMins(v) : fmtNum(v) + (unit ? ' ' + unit : '');
                if (showShare) lbl += '  (' + Math.round(v / total * 100) + '%)';
                return (ctx.dataset.label ? ctx.dataset.label + ': ' : '') + lbl;
              },
            },
          },
        },
        scales: {
          x: {
            grid: { display: false },
            ticks: {
              callback: (v, i) => xLabel(labels[i], labels.length),
              maxRotation: manyLabels ? 45 : 0, minRotation: manyLabels ? 45 : 0,
            },
          },
          y: {
            beginAtZero: true, grid: { color: GRID },
            max: unit === '%' ? 100 : undefined,
            ticks: { callback: (v) => (fmt === 'hm' ? fmtMins(v) : fmtNum(v)) },
          },
        },
      }),
    };
    if (hrefs.length) {
      cfg.options.onClick = (evt, els, chart) => {
        const hit = chart.getElementsAtEventForMode(evt, 'nearest', { intersect: true }, true)[0];
        if (hit && hrefs[hit.index]) {
          if (target === 'blank') window.open(hrefs[hit.index], '_blank');
          else window.location = hrefs[hit.index];
        }
      };
      cfg.options.onHover = (evt, els, chart) => { chart.canvas.style.cursor = els.length ? 'pointer' : ''; };
    }
    return cfg;
  }

  // ── Activity timeline: area line + a screenshot-marker overlay dataset ──
  function buildTimeline(canvas) {
    const points = parse(canvas, 'data-points', []);
    const markers = parse(canvas, 'data-markers', []);
    const start = +canvas.getAttribute('data-start') || 0;
    const end = +canvas.getAttribute('data-end') || start + 1;
    const nearestPct = (t) => {
      if (!points.length) return 50;
      let best = points[0], bd = Infinity;
      for (const p of points) { const d = Math.abs(p.x - t); if (d < bd) { bd = d; best = p; } }
      return best.pct;
    };
    const linePts = points.map(p => ({ x: p.x, y: p.pct }));
    const markerPts = markers.map(m => ({ x: m.x, y: nearestPct(m.x), m }));
    return {
      type: 'line',
      data: {
        datasets: [
          Object.assign({
            label: 'Activity', data: linePts, borderColor: BRAND, borderWidth: 2, tension: 0.3, fill: true,
            pointRadius: 0, pointHitRadius: 0, pointHoverRadius: 0,
            backgroundColor: (ctx) => {
              const { chartArea, ctx: c } = ctx.chart;
              if (!chartArea) return withAlpha(BRAND, '33');
              const g = c.createLinearGradient(0, chartArea.top, 0, chartArea.bottom);
              g.addColorStop(0, withAlpha(BRAND, '40')); g.addColorStop(1, withAlpha(BRAND, '00'));
              return g;
            },
          }),
          Object.assign({
            label: 'Screenshots', data: markerPts, showLine: false, parsing: { yAxisKey: 'y' },
            pointRadius: 5, pointHoverRadius: 8, pointHitRadius: 6,
            pointBackgroundColor: MARK, pointBorderColor: CARD, pointBorderWidth: 2,
            pointHoverBackgroundColor: MARK, pointHoverBorderColor: '#fff', pointHoverBorderWidth: 2,
          }),
        ],
      },
      options: {
        responsive: true, maintainAspectRatio: false, resizeDelay: 80,
        interaction: { mode: 'point', intersect: true },
        plugins: {
          legend: { display: false },
          tooltip: {
            enabled: false,
            external(ctx) { externalMarkerTooltip(ctx, canvas); },
          },
        },
        scales: {
          x: {
            type: 'linear', min: start, max: end, grid: { display: false },
            ticks: {
              maxTicksLimit: 6,
              callback: (v) => fmtTime(v),
            },
          },
          y: { min: 0, max: 100, grid: { color: GRID }, ticks: { callback: (v) => v + '%' } },
        },
        onClick: (evt, els, chart) => {
          const hit = chart.getElementsAtEventForMode(evt, 'nearest', { intersect: true }, true)
            .find(e => e.datasetIndex === 1);
          if (hit) {
            const m = markerPts[hit.index].m;
            if (m && m.url) window.open(m.url, '_blank');
          }
        },
        onHover: (evt, els, chart) => {
          const hit = els.find(e => e.datasetIndex === 1);
          chart.canvas.style.cursor = hit ? 'pointer' : '';
        },
      },
    };
  }

  function tipEl() {
    let t = document.getElementById('dp-tip');
    if (!t) { t = document.createElement('div'); t.id = 'dp-tip'; document.body.appendChild(t); }
    return t;
  }
  // Custom image-preview tooltip for timeline screenshot markers (Chart.js
  // `external` callback — positions our existing #dp-tip element using the
  // chart's own tooltip model instead of manual hit-testing).
  function externalMarkerTooltip(context, canvas) {
    const tooltip = context.tooltip;
    const t = tipEl();
    const dp = tooltip.dataPoints && tooltip.dataPoints.find(d => d.datasetIndex === 1);
    if (tooltip.opacity === 0 || !dp) { t.style.display = 'none'; return; }
    const m = context.chart.data.datasets[1].data[dp.dataIndex].m;
    t.innerHTML = '<div class="dp-tip-h">' + esc(m.who || '') + ' · ' + esc(fmtTime(m.x)) + '</div>' +
      '<div class="dp-tip-app">' + esc(m.app || 'Unknown') +
      (m.title ? ' — ' + esc(String(m.title).slice(0, 60)) : '') + '</div>' +
      '<img src="' + esc(m.url) + '" alt="screenshot">';
    t.style.display = 'block';
    const rect = canvas.getBoundingClientRect();
    let lx = rect.left + tooltip.caretX + 14;
    if (lx + 250 > window.innerWidth) lx = rect.left + tooltip.caretX - 250;
    t.style.left = Math.max(4, lx) + 'px';
    t.style.top = (rect.top + window.scrollY + tooltip.caretY + 14) + 'px';
  }

  // ── Donut — proportion charts (active vs inactive share of hours), used on
  //    aggregate views for company admin / HR / IT admin / team managers /
  //    client viewers, where visible_user_ids() already scopes the underlying
  //    data to their org, team or client. A 2–3 slice proportion reads more
  //    clearly as a donut than as bars (matches ui-ux-pro-max chart-type
  //    guidance: comparison → bar, proportion → donut). ──
  function buildDonut(canvas) {
    const values = parse(canvas, 'data-values', []);
    const labels = parse(canvas, 'data-labels', []);
    const colors = parse(canvas, 'data-colors', PALETTE);
    const unit = canvas.getAttribute('data-unit') || '';
    const fmt = canvas.getAttribute('data-fmt');
    const mini = canvas.hasAttribute('data-mini');
    const total = values.reduce((a, b) => a + (+b || 0), 0);
    const empty = total <= 0;
    const fmtVal = (v) => (fmt === 'hm' ? fmtMins(v) : fmtNum(v) + (unit ? ' ' + unit : ''));
    return {
      type: 'doughnut',
      data: {
        labels: empty ? [''] : labels,
        datasets: [{ data: empty ? [1] : values, backgroundColor: empty ? [GRID] : colors, borderWidth: 0, hoverOffset: mini ? 0 : 6 }],
      },
      options: Object.assign(baseOptions(), {
        cutout: mini ? '70%' : '64%',
        // Mini (per-row) donuts skip the legend — there's no room, and the
        // adjacent table cell already shows the active/inactive figures as text.
        plugins: {
          legend: {
            display: !empty && !mini, position: 'bottom', align: 'center',
            labels: {
              generateLabels(chart) {
                const ds = chart.data.datasets[0];
                const tot = ds.data.reduce((a, b) => a + (+b || 0), 0) || 1;
                return chart.data.labels.map((lab, i) => ({
                  text: lab + '  ' + Math.round((ds.data[i] || 0) / tot * 100) + '%',
                  fillStyle: ds.backgroundColor[i], strokeStyle: ds.backgroundColor[i], index: i,
                }));
              },
            },
          },
          tooltip: { enabled: !empty, callbacks: { label: (ctx) => ctx.label + ': ' + fmtVal(ctx.parsed) } },
        },
      }),
    };
  }

  function render(canvas) {
    ensureWrapper(canvas);
    const type = canvas.getAttribute('data-type');
    let cfg;
    if (type === 'donut') cfg = buildDonut(canvas);
    else if (type === 'bars' || type === 'hbars' || type === 'line') cfg = buildBars(canvas);
    else if (type === 'timeline') cfg = buildTimeline(canvas);
    else return;
    if (canvas._chart) canvas._chart.destroy();
    canvas._chart = new Chart(canvas.getContext('2d'), cfg);
  }

  function renderAll() {
    document.querySelectorAll('canvas.dp-chart').forEach(render);
  }

  window.DP = window.DP || {};
  window.DP.renderCharts = renderAll;
  document.addEventListener('DOMContentLoaded', renderAll);
})();
