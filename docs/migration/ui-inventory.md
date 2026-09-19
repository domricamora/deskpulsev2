# UI inventory — what "exact replica" has to reproduce

> Added to the audit because of the user's constraint: *"the same exact replica
> functions, buttons, look, flow, layout, everything, except the fact that I want to
> use Laravel and modern Tailwind CSS."*

Source of truth: `server/public/assets/css/deskpulse.css` (1,182 lines),
`server/templates/layout.php`, `layout_public.php`, 70 templates, and 8 JS modules.

This is not a redesign brief. It is the specification Tailwind output must match.

## 1. The app is dark-themed — there is no light mode

```css
:root{
  --bg:#070b14; --bg-2:#0b111e; --card:#111a2e; --card-2:#16213a;
  --ink:#e9eef8; --muted:#94a3bd; --line:#233149; --line-2:#2c3b56;
}
```

Near-black surfaces, light ink. **A single theme — no light variant, no toggle, no
`prefers-color-scheme` handling.**

`claude.md` §74 asks for light/dark/system via Tailwind's dark-mode strategy. Under
the replica constraint that is **new functionality**, not a migration. Tailwind's
default is a light theme, so the port must explicitly rebuild the dark palette as the
*only* theme — otherwise the app comes out white.

## 2. Design tokens — map these into the Tailwind theme verbatim

| Group | Tokens |
|---|---|
| Surfaces | `--bg #070b14` · `--bg-2 #0b111e` · `--card #111a2e` · `--card-2 #16213a` |
| Ink | `--ink #e9eef8` · `--muted #94a3bd` |
| Lines | `--line #233149` · `--line-2 #2c3b56` |
| Brand (blue) | `--brand #3b82f6` · `--brand-dk #2563eb` · `--brand-lt #60a5fa` · `--brand-50 #132038` · `--brand-100 #16294a` |
| Accent (teal) | `--teal #2dd4bf` · `--teal-dk #14b8a6` · `--teal-lt #5eead4` |
| Status | `--good #34d399` · `--bad #f87171` · `--warn #fbbf24` |
| Shape | `--radius 4px` · `--ring rgba(45,212,191,.35)` |

Plus composed tokens that carry the product's texture:

```css
--grad-brand:  linear-gradient(135deg, var(--brand), var(--teal));
--grad-brand-v:linear-gradient(180deg, var(--brand-lt), var(--brand));
--shadow:        0 1px 2px rgba(0,0,0,.5), 0 10px 26px rgba(0,0,0,.40);
--shadow-raised: inset 0 1px 0 rgba(255,255,255,.04), 0 1px 2px rgba(0,0,0,.4), 0 14px 34px rgba(0,0,0,.42);
--inset:         inset 0 1px 3px rgba(0,0,0,.55), inset 0 1px 0 rgba(255,255,255,.02);
--glow:          0 0 0 1px rgba(59,130,246,.25), 0 10px 34px rgba(59,130,246,.14);
--glow-teal:     0 0 0 1px rgba(45,212,191,.28), 0 10px 34px rgba(45,212,191,.14);
```

This confirms `claude.md` §38's "blue DeskPulse identity" — blue `#3b82f6` primary
with a teal `#2dd4bf` accent, on near-black.

**`--radius` is 4px** (buttons 3px). Tailwind's defaults are much rounder; every
surface needs the tight radius or the product looks wrong.

## 3. Typography — self-hosted, not Google Fonts

```css
@font-face { font-family:"Inter"; … }
@font-face { font-family:"Plus Jakarta Sans"; … }
```

- Body: **Inter**, 15px, `line-height:1.55`, antialiased, `optimizeLegibility`.
- Headings: **Plus Jakarta Sans**, `letter-spacing:-.012em`.
- Brand wordmark: Plus Jakarta Sans 800, `-.02em`, `Desk` in `#e9eef8` + `Pulse` in
  teal `#2dd4bf`.

Fonts are **self-hosted** and the CSP sets `font-src 'self'`. Do not switch to a
Google Fonts link — it would be blocked and would change rendering.

## 4. Buttons are tactile — not flat

```css
.btn{ background:var(--grad-brand-v); border:1px solid var(--brand-dk); border-radius:3px;
  padding:.55rem 1.05rem; font-weight:600; text-shadow:0 -1px 0 rgba(0,0,0,.25);
  box-shadow:inset 0 1px 0 rgba(255,255,255,.22), 0 4px 16px rgba(37,99,235,.35);
  transition:transform .18s ease, box-shadow .2s ease, background .2s ease }
.btn:hover{ background:linear-gradient(180deg,#4f92ff,var(--brand-dk));
  transform:translateY(-1px); box-shadow:inset 0 1px 0 rgba(255,255,255,.22), 0 8px 24px rgba(37,99,235,.5) }
.btn:active{ box-shadow:var(--inset); transform:translateY(1px) }
.btn.ghost{ background:rgba(255,255,255,.03); color:var(--brand-lt); border:1px solid var(--line-2) }
```

Vertical gradient, inset top highlight, coloured glow, and a **1px lift on hover /
1px press on active**. Reproducing this with default Tailwind utilities gives a flat
button — it needs a component class or a custom plugin.

## 5. Icons — 30 inline SVGs, no library

`layout.php` defines a `$dpIcons` map of ~30 Lucide-style paths rendered through a
`$dpIcon()` closure at `viewBox="0 0 24 24"`, `fill="none"`,
`stroke="currentColor"`, `stroke-width="1.8"`, round caps/joins.

Keys: `overview, clock, tasks, approvals, overtime, live, image, users, clients,
contracts, billing, devices, audit, download, upload, settings, profile, agents,
share, payroll, payslip, platform, orgs, menu, help, efficiency, adjust, leave, wise,
salary, message`.

No icon package, no CDN, no build step. Port as a Blade component
(`<x-icon name="overview" />`) with the same paths and stroke attributes — swapping in
an icon library changes every glyph.

## 6. Layout shell

```
<body class="app">
  <aside class="sidebar">
     brand (DeskPulse wordmark)
     org logo (organizations.logo_path, when set)
     nav — grouped, capability-gated, with icons
     side-foot
  <main class="main">
     topbar
     flash / notice-banner / acting-banner
     page content
```

- `layout.php` — authenticated app shell. `layout_public.php` — marketing/auth/share.
- Mobile: `.nav-toggle` + `.nav-backdrop` drive a slide-in sidebar.
- `.acting-banner` shows when a super admin is acting as an org — a distinct,
  always-visible state.
- `.notice-banner` renders dismissible org notices (`notices` + `notice_reads`).

## 7. Class inventory — ~200 classes to reproduce

Grouped by area (full list in the CSS):

| Area | Classes |
|---|---|
| Shell | `app sidebar main topbar brand org-brand nav-group nav-group-label nav-toggle nav-backdrop side-foot subnav acting-banner notice-banner flash` |
| Surfaces | `card panel panel-head stat stat-row metric metrics mini-stats empty-state modal-* ` |
| Controls | `btn btn.ghost pill chips tag badge dot status inline inline-form row-form row-actions pw-wrap pw-toggle oauth-btn oauth-row` |
| Tables | `data dp-table-wrap dp-tbar trunc nowrap` |
| Periods | `period-switch period-nav period-label period-range period-custom` |
| Live | `live-grid live-card live-client live-client-head live-team live-team-head live-dot live-head` |
| Charts | `dp-chart dp-chart-wrap ticks split-bar split-legend` |
| Sharing | `share-wrap share-head share-filters share-url shot-grid` |
| Onboarding | `onb-intro onb-steps onb-row onb-list onb-nav onb-skip onb-done guide-card guide-cards welcome-hero step step-track` |
| Payroll | `adj-row sched-days sched-h dev-row assign-form bill-form` |
| Marketing | `hero hero-grid hero-copy hero-actions hero-mock hero-trust features feat-* price price-* pricing faq-* showcase show-* carousel* cta-* how* privacy* role-grid prose*` |

Marketing carries its own substantial component set (hero, pricing grid, FAQ,
showcase carousel, feature categories) — it is roughly a third of the CSS.

## 8. Cross-cutting JavaScript behaviour — the part most at risk

These are **behaviours**, not decoration. Losing them changes how every page works.

### `tables.js` (397 lines) — every `table.data`, no per-page markup

- Per-column filter row: a `<select>` when a column has ≤12 short distinct values and
  no form controls, otherwise a text input.
- Click-to-sort headers with `aria-sort`.
- "Showing N of M" counter.
- CSV export of **exactly what is on screen**.
- Wraps **every** `table.data` (including `data-nofilter`) in `.dp-table-wrap` so wide
  tables scroll inside themselves rather than pushing the page sideways.
- Columns are read from the **rendered `<thead>`**, because Team / Efficiency / share
  pages show different columns per capability.
- Sort keys prefer `data-sort` on the cell, then `<time data-utc>`, then a parser that
  understands this app's formats (`3h 05m`, `USD 1,234.00`, `83%`).
- `<tfoot>` is never sorted or filtered.
- Tables with fewer than 3 body rows are left alone; opt out with `data-nofilter`.
- State persists per table in `sessionStorage`, so post/redirect/get keeps the filter.

> Any new grid/flex container holding a table needs `min-width:0` on its children, or
> the track refuses to shrink and the page scrolls sideways. Do not reintroduce a
> blanket `white-space:nowrap` on tables.

### `charts.js` (383 lines) + vendored Chart.js

`<canvas class="dp-chart" data-type="bars|hbars|line" …>` → Chart.js, **vendored** at
`assets/js/vendor/chart.umd.js` and loaded same-origin because the CSP blocks external
scripts. `claude.md` §39's "dependency-free canvas charts" is outdated.

### `dashboard.js` (125)

Renders `<time data-utc>` in the **viewer's** timezone and sets the `dp_tz` cookie —
the display half of the timezone model (`reports.md`). Every timestamp depends on it.

### Others

`live.js` (85) 15s poll · `remote.js` (162) control viewer · `platform.js` (60) ·
`reveal.js` (28) scroll reveal · `hero-globe.js` (163) marketing hero.

## 9. Inline styles and handlers vs. the CSP

The CSP keeps `'unsafe-inline'` for **both** `style-src` and `script-src` because the
templates use inline `<style>`, `<script>` and inline event handlers heavily — visible
even in the sidebar brand markup.

Moving to Tailwind + Vite makes dropping `unsafe-inline` achievable, but only if every
inline handler becomes a listener. A missed handler fails **silently** under a strict
CSP. Treat CSP tightening as a separate, verified pass after visual parity — not as
part of the template port.

## 10. Migration approach

1. Port tokens in §2 into the Tailwind 4 theme (`@theme`) as CSS variables — same
   names, same values. Everything else keys off them.
2. Self-host Inter and Plus Jakarta Sans via Vite; keep `font-src 'self'`.
3. Build Blade components for the repeated primitives (`claude.md` §73):
   `x-button`, `x-card`, `x-panel`, `x-stat`, `x-badge`, `x-pill`, `x-table`,
   `x-modal`, `x-alert`, `x-empty-state`, `x-icon`, `x-period-switch`.
4. **Port `tables.js` and `charts.js` as-is first.** They are framework-agnostic and
   carry behaviour no Tailwind class replaces. Restyle their generated markup after.
5. Follow the §72 page order (marketing → auth → shell → dashboard → …), diffing each
   page against the legacy render at the same viewport widths.
6. Keep the dark theme as the only theme.

## 11. Required tests

- Visual diff every migrated page against the legacy app at the **132 page-width
  combinations** the existing team already used as their responsive pass.
- Tokens resolve to the exact hex values in §2.
- Buttons keep gradient, inset highlight, glow, and the hover-lift/active-press.
- `--radius` 4px surfaces, 3px buttons.
- Fonts load from same-origin; no external font request.
- Icons render identical paths at `stroke-width:1.8`.
- `tables.js`: filters, sort, counter, CSV, `sessionStorage` persistence, `.dp-table-wrap`
  on every `table.data`, `<tfoot>` untouched, <3-row tables skipped.
- No horizontal page scroll at any width with a wide table present.
- `<time data-utc>` localizes; `dp_tz` cookie set.
- Charts render from the vendored bundle with no external request.
- Capability-dependent columns still drive the filter row correctly.

## 12. Migration risks

| Risk | Severity | Note |
|---|---|---|
| Tailwind defaults producing a light theme | **High** | App is dark-only; the port must rebuild the palette as the sole theme |
| Adding light/dark toggle per §74 | Medium | New functionality; excluded by the replica constraint |
| Losing `tables.js` behaviour | **High** | Filters, sort and CSV exist on every table with no per-page markup |
| Replacing the icon set with a library | Medium | Every glyph changes |
| Google Fonts instead of self-hosted | Medium | CSP-blocked; different rendering |
| Default Tailwind radii/shadows | Medium | 4px radius and the tactile button are core to the identity |
| Flattening buttons | Medium | Gradient + glow + lift is the product's signature control |
| Dropping `unsafe-inline` before porting handlers | Medium | Silent JS breakage |
| Reintroducing `white-space:nowrap` on tables | Low | Documented regression; breaks responsive tables |
