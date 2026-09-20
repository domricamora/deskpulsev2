/* DeskPulse — universal table column filters + sorting.
 *
 * Enhances every <table class="data"> on the page without any per-page markup:
 * adds a per-column filter row (a <select> when a column has few distinct values,
 * a text box otherwise), click-to-sort headers, a "showing N of M" counter and a
 * CSV export of exactly what's on screen.
 *
 * Design notes:
 *  - Columns are read from the RENDERED <thead>, because several tables show
 *    different columns depending on the viewer's capabilities (Team, Efficiency,
 *    the share page). Nothing here may assume a fixed schema.
 *  - Sort values come from `data-sort` on the cell when present, then from
 *    <time data-utc> (the UTC instant, not the localized text dashboard.js writes
 *    in asynchronously), then from a parser that understands the formats this app
 *    prints: "3h 05m", "USD 1,234.00", "83%", ISO dates.
 *  - <tfoot> is never filtered or sorted — those are period totals.
 *  - Opt out with data-nofilter on the table.
 */
(function () {
  'use strict';

  var MIN_ROWS = 3;          // below this a filter bar is just noise
  var SELECT_MAX_DISTINCT = 12;
  var SELECT_MAX_LEN = 32;

  // ── Value parsing ─────────────────────────────────────────────────────────

  var DUR = /^\s*(?:(\d+(?:\.\d+)?)\s*h)?\s*(?:(\d+(?:\.\d+)?)\s*m)?\s*(?:(\d+(?:\.\d+)?)\s*s)?\s*$/i;

  function parseDuration(s) {
    if (!/[hms]/i.test(s) || !DUR.test(s)) return null;
    var m = s.match(DUR);
    if (!m || (!m[1] && !m[2] && !m[3])) return null;
    return (parseFloat(m[1] || 0) * 3600) + (parseFloat(m[2] || 0) * 60) + parseFloat(m[3] || 0);
  }

  function parseNumeric(s) {
    if (s === '' || s === '—' || s === '-') return null;
    var d = parseDuration(s);
    if (d !== null) return d;
    // Currency / percent / plain number, with thousands separators and a
    // leading or trailing currency code ("USD 1,234.00", "1 234,00 €", "-12.5%").
    var t = s.replace(/[ \s]/g, '')
             .replace(/^[A-Z]{2,4}/i, '')
             .replace(/[A-Z]{2,4}$/i, '')
             .replace(/[$£€₱%]/g, '')
             .replace(/,/g, '');
    if (t === '' || !/^[-+]?\d*\.?\d+$/.test(t)) return null;
    return parseFloat(t);
  }

  function cellSortValue(td) {
    if (!td) return '';
    if (td.hasAttribute('data-sort')) return td.getAttribute('data-sort');
    var t = td.querySelector('time[data-utc]');
    if (t) return t.getAttribute('data-utc');
    var input = td.querySelector('select');
    if (input && input.selectedIndex >= 0) return input.options[input.selectedIndex].text;
    return (td.textContent || '').replace(/\s+/g, ' ').trim();
  }

  function cellFilterText(td) {
    if (!td) return '';
    return ((td.textContent || '') + ' ' + (td.getAttribute('data-sort') || ''))
      .replace(/\s+/g, ' ').trim().toLowerCase();
  }

  // ── Per-table enhancement ─────────────────────────────────────────────────

  function enhance(table, index) {
    if (table.dataset.dpEnhanced) return;
    var thead = table.tHead;
    var tbody = table.tBodies[0];
    if (!thead || !tbody) return;

    var headRow = thead.rows[thead.rows.length - 1];
    if (!headRow) return;
    var ths = Array.prototype.slice.call(headRow.cells);
    var cols = ths.length;
    if (cols < 2) return;

    // Only rows that actually have a full set of cells participate. Rows with a
    // colspan (empty-state messages, group separators) are always shown.
    var allRows = Array.prototype.slice.call(tbody.rows);
    var rows = allRows.filter(function (r) { return r.cells.length === cols; });
    if (rows.length < MIN_ROWS) return;

    table.dataset.dpEnhanced = '1';
    var key = 'dpTable:' + location.pathname + ':' + index;

    // Column profiles: distinct values, whether the column holds form controls,
    // and whether every value is numeric (→ numeric sort + right alignment).
    var profiles = ths.map(function (th, c) {
      var distinct = Object.create(null);
      var n = 0, numeric = 0, filled = 0, hasControl = false, maxLen = 0;
      var sample = Math.min(rows.length, 60);
      for (var i = 0; i < sample; i++) {
        var td = rows[i].cells[c];
        if (!hasControl && td.querySelector('input,select,textarea,details')) hasControl = true;
        var v = cellSortValue(td);
        if (v !== '' && v !== '—') {
          filled++;
          if (parseNumeric(v) !== null) numeric++;
          if (!(v in distinct)) { distinct[v] = 1; n++; }
          if (v.length > maxLen) maxLen = v.length;
        }
      }
      return {
        label: (th.textContent || '').trim(),
        distinct: Object.keys(distinct).sort(function (a, b) { return a.localeCompare(b); }),
        numeric: filled > 0 && numeric === filled,
        useSelect: !hasControl && n > 1 && n <= SELECT_MAX_DISTINCT && maxLen <= SELECT_MAX_LEN,
        hasControl: hasControl
      };
    });

    var state = loadState(key, cols);

    // ── Toolbar ──
    var bar = document.createElement('div');
    bar.className = 'dp-tbar';
    bar.innerHTML =
      '<button type="button" class="btn sm ghost dp-tf" aria-pressed="false">Filter</button>' +
      '<input type="search" class="dp-tsearch" placeholder="Search this table…" aria-label="Search this table">' +
      '<span class="dp-tcount" aria-live="polite"></span>' +
      '<span class="dp-tscroll" aria-hidden="true">↔ scroll for more columns</span>' +
      '<button type="button" class="lnk dp-tclear" hidden>clear</button>' +
      '<button type="button" class="lnk dp-tcsv">export view</button>';
    // The toolbar sits above the scroll container, not inside it, so it stays put
    // while the table scrolls sideways.
    var host = table.parentNode.classList.contains('dp-table-wrap') ? table.parentNode : table;
    host.parentNode.insertBefore(bar, host);

    var btnFilter = bar.querySelector('.dp-tf');
    var search = bar.querySelector('.dp-tsearch');
    var counter = bar.querySelector('.dp-tcount');
    var btnClear = bar.querySelector('.dp-tclear');
    var btnCsv = bar.querySelector('.dp-tcsv');

    // ── Filter row ──
    var frow = document.createElement('tr');
    frow.className = 'dp-filter-row';
    var controls = [];
    profiles.forEach(function (p, c) {
      var th = document.createElement('th');
      var el;
      if (p.useSelect) {
        el = document.createElement('select');
        el.innerHTML = '<option value="">All</option>' + p.distinct.map(function (v) {
          return '<option value="' + escapeAttr(v) + '">' + escapeHtml(v) + '</option>';
        }).join('');
      } else {
        el = document.createElement('input');
        el.type = 'text';
        el.placeholder = 'Filter';
      }
      el.setAttribute('aria-label', 'Filter by ' + (p.label || 'column ' + (c + 1)));
      el.value = state.filters[c] || '';
      el.addEventListener('input', onFilterChange);
      el.addEventListener('change', onFilterChange);
      th.appendChild(el);
      frow.appendChild(th);
      controls.push(el);
    });
    thead.appendChild(frow);

    // ── Sorting ──
    ths.forEach(function (th, c) {
      if (!(th.textContent || '').trim()) return;
      th.classList.add('dp-sortable');
      th.setAttribute('tabindex', '0');
      th.setAttribute('role', 'button');
      th.addEventListener('click', function () { toggleSort(c); });
      th.addEventListener('keydown', function (ev) {
        if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); toggleSort(c); }
      });
    });

    function toggleSort(c) {
      if (state.sortCol === c) {
        state.sortDir = state.sortDir === 'asc' ? 'desc' : (state.sortDir === 'desc' ? null : 'asc');
        if (state.sortDir === null) state.sortCol = null;
      } else {
        state.sortCol = c;
        state.sortDir = 'asc';
      }
      applySort();
      saveState(key, state);
    }

    var originalOrder = rows.slice();

    function applySort() {
      ths.forEach(function (th, i) {
        th.classList.remove('sort-asc', 'sort-desc');
        if (state.sortCol === i && state.sortDir) {
          th.classList.add(state.sortDir === 'asc' ? 'sort-asc' : 'sort-desc');
          th.setAttribute('aria-sort', state.sortDir === 'asc' ? 'ascending' : 'descending');
        } else {
          th.removeAttribute('aria-sort');
        }
      });
      var ordered;
      if (state.sortCol === null || !state.sortDir) {
        ordered = originalOrder;
      } else {
        var c = state.sortCol, numeric = profiles[c].numeric, dir = state.sortDir === 'asc' ? 1 : -1;
        ordered = rows.slice().sort(function (a, b) {
          var av = cellSortValue(a.cells[c]), bv = cellSortValue(b.cells[c]);
          if (numeric) {
            var an = parseNumeric(av), bn = parseNumeric(bv);
            // Blanks always sink, regardless of direction.
            if (an === null && bn === null) return 0;
            if (an === null) return 1;
            if (bn === null) return -1;
            return (an - bn) * dir;
          }
          if (av === bv) return 0;
          if (av === '') return 1;
          if (bv === '') return -1;
          return av.localeCompare(bv, undefined, { numeric: true, sensitivity: 'base' }) * dir;
        });
      }
      var frag = document.createDocumentFragment();
      ordered.forEach(function (r) { frag.appendChild(r); });
      tbody.appendChild(frag);
      // Rows excluded from sorting (colspan/empty-state) stay at the end.
      allRows.forEach(function (r) { if (rows.indexOf(r) === -1) tbody.appendChild(r); });
    }

    var timer = null;
    function onFilterChange() {
      clearTimeout(timer);
      timer = setTimeout(function () {
        state.filters = controls.map(function (el) { return el.value; });
        state.search = search.value;
        applyFilters();
        saveState(key, state);
      }, 110);
    }
    search.addEventListener('input', onFilterChange);

    function applyFilters() {
      var needles = state.filters.map(function (v) { return (v || '').trim().toLowerCase(); });
      var q = (state.search || '').trim().toLowerCase();
      var any = q !== '' || needles.some(function (v) { return v !== ''; });
      var shown = 0;
      rows.forEach(function (r) {
        var ok = true;
        for (var c = 0; c < cols && ok; c++) {
          if (!needles[c]) continue;
          var text = cellFilterText(r.cells[c]);
          // A dropdown filter is an exact pick; a text box is a contains match.
          ok = profiles[c].useSelect ? text === needles[c] : text.indexOf(needles[c]) !== -1;
        }
        if (ok && q) ok = (r.textContent || '').toLowerCase().indexOf(q) !== -1;
        r.classList.toggle('dp-row-hidden', !ok);
        if (ok) shown++;
      });
      counter.textContent = any ? 'showing ' + shown + ' of ' + rows.length
                                : rows.length + ' row' + (rows.length === 1 ? '' : 's');
      counter.classList.toggle('on', any);
      btnClear.hidden = !any;
      table.classList.toggle('dp-filtered', any);
      if (any && !btnFilter.getAttribute('aria-pressed').match(/true/)) showFilterRow(true);
    }

    function showFilterRow(on) {
      table.classList.toggle('dp-show-filters', on);
      btnFilter.setAttribute('aria-pressed', on ? 'true' : 'false');
      btnFilter.classList.toggle('on', on);
      state.open = on;
    }

    btnFilter.addEventListener('click', function () {
      showFilterRow(!table.classList.contains('dp-show-filters'));
      saveState(key, state);
    });

    btnClear.addEventListener('click', function () {
      controls.forEach(function (el) { el.value = ''; });
      search.value = '';
      state.filters = controls.map(function () { return ''; });
      state.search = '';
      applyFilters();
      saveState(key, state);
    });

    btnCsv.addEventListener('click', function () {
      var lines = [ths.map(function (th) { return (th.textContent || '').trim(); })];
      rows.forEach(function (r) {
        if (r.classList.contains('dp-row-hidden')) return;
        lines.push(Array.prototype.slice.call(r.cells).map(function (td) {
          return (td.textContent || '').replace(/\s+/g, ' ').trim();
        }));
      });
      var csv = lines.map(function (row) {
        return row.map(function (v) { return '"' + String(v).replace(/"/g, '""') + '"'; }).join(',');
      }).join('\r\n');
      var blob = new Blob(["﻿" + csv], { type: 'text/csv;charset=utf-8;' });
      var a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = (document.title || 'deskpulse').replace(/[^\w]+/g, '-').toLowerCase() + '-view.csv';
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      setTimeout(function () { URL.revokeObjectURL(a.href); }, 1000);
    });

    // Restore persisted state.
    search.value = state.search || '';
    if (state.open) showFilterRow(true);
    applySort();
    applyFilters();
  }

  // ── State persistence ─────────────────────────────────────────────────────
  // Kept in sessionStorage so a post/redirect/get (every form on the dashboard)
  // doesn't silently drop the filter the user set a moment ago.

  function loadState(key, cols) {
    var blank = { filters: new Array(cols).fill(''), search: '', sortCol: null, sortDir: null, open: false };
    try {
      var raw = sessionStorage.getItem(key);
      if (!raw) return blank;
      var s = JSON.parse(raw);
      if (!s || !Array.isArray(s.filters) || s.filters.length !== cols) return blank;
      return {
        filters: s.filters, search: s.search || '',
        sortCol: (typeof s.sortCol === 'number' && s.sortCol < cols) ? s.sortCol : null,
        sortDir: s.sortDir === 'asc' || s.sortDir === 'desc' ? s.sortDir : null,
        open: !!s.open
      };
    } catch (e) { return blank; }
  }

  function saveState(key, state) {
    try { sessionStorage.setItem(key, JSON.stringify(state)); } catch (e) { /* private mode */ }
  }

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function escapeAttr(s) { return escapeHtml(s); }

  /**
   * Give every data table its own horizontal scroll container. This applies to ALL
   * tables — including data-nofilter ones and tables too small to get a filter bar —
   * because containment is a layout concern, not a filtering one. Without it a wide
   * table pushes the whole page sideways on any screen narrower than the table.
   */
  function wrapAll() {
    var tables = document.querySelectorAll('table.data');
    Array.prototype.forEach.call(tables, function (t) {
      var p = t.parentNode;
      if (!p || (p.classList && p.classList.contains('dp-table-wrap'))) return;
      var w = document.createElement('div');
      w.className = 'dp-table-wrap';
      p.insertBefore(w, t);
      w.appendChild(t);
    });
  }

  /** Show "scroll for more columns" only on tables that genuinely overflow. */
  function refreshScrollHints() {
    var wraps = document.querySelectorAll('.dp-table-wrap');
    Array.prototype.forEach.call(wraps, function (w) {
      var bar = w.previousElementSibling;
      if (!bar || !bar.classList.contains('dp-tbar')) return;
      var hint = bar.querySelector('.dp-tscroll');
      if (!hint) return;
      hint.classList.toggle('on', w.scrollWidth > w.clientWidth + 2);
    });
  }

  function init() {
    wrapAll();
    var tables = document.querySelectorAll('table.data:not([data-nofilter])');
    Array.prototype.forEach.call(tables, function (t, i) {
      try { enhance(t, i); } catch (e) { /* never let one table break the page */ }
    });
    refreshScrollHints();
    var rt = null;
    window.addEventListener('resize', function () {
      clearTimeout(rt);
      rt = setTimeout(refreshScrollHints, 150);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
