# Marketing site, content CMS, SEO and downloads

> Partly covered by the migration plan §36/§77, but the **markdown CMS** and the
> **download system** are not mentioned at all. Sources: `server/src/marketing.php`
> (875 lines), `server/src/content.php` (425 lines), `server/content/`,
> `server/templates/marketing/` (11 templates).

## 1. Routes

| Path | Handler |
|---|---|
| `/` | `marketing_index` |
| `/pricing` | `marketing_pricing` |
| `/tools/cost-calculator` | `marketing_calculator` |
| `/download` | `marketing_download` |
| `/download/app` | `marketing_download_app` |
| `/robots.txt` `/sitemap.xml` `/llms.txt` | generated |
| `/blog`, `/blog/{slug}` | `marketing_blog_index`, `marketing_content('blog', …)` |
| `/compare/{slug}` | `marketing_content('compare', …)` |
| `/use-cases/{slug}` | `marketing_content('use-cases', …)` |
| `/privacy` `/terms` `/security` `/about` `/contact` | `marketing_content('pages', …)` |

The migration plan §36 lists home/features/pricing/security/download/signup/login. The
real site also has a blog, comparison pages, use-case pages, a cost calculator and
`llms.txt`.

## 2. The content system is a hand-rolled CMS

`server/content/<collection>/<slug>.md` — collections `blog`, `compare`, `pages`,
`use-cases` (10 files today).

| Function | Role |
|---|---|
| `md_to_html()` | **a hand-written Markdown parser** (~120 lines) |
| `md_inline()`, `md_anchor()`, `md_table_cells()` | inline spans, heading anchors, tables |
| `content_front_matter()` | YAML-ish front matter |
| `content_parse()`, `content_list()` | load one / list a collection |
| `content_slug_ok()` | slug allow-list — path-traversal guard |
| `content_tokens()` | token substitution inside rendered HTML |
| `content_matrix_table()` | generated feature-matrix table |
| `content_vs_table($vendorKey)` | generated head-to-head comparison table |
| `content_faq()` | extracts Q&A pairs, feeding FAQ JSON-LD |

Two things make this more than "render some markdown":

1. **`content_tokens()`, `content_matrix_table()` and `content_vs_table()` inject
   generated content into an article.** A comparison page's pricing table is not
   written in the markdown — it is produced from `competitor_prices()`. Swapping in
   a standard Markdown library without porting the token pass silently empties those
   tables.
2. **`content_faq()` parses the article's own Q&A into FAQ structured data**, so the
   JSON-LD cannot drift from the visible copy.

`content_slug_ok()` is a security control — the slug comes from the URL and is used to
build a filesystem path. Keep the allow-list; do not relax it to "any string".

> A Laravel port can use CommonMark, but must keep: front matter, slug validation,
> heading anchors, the token/table injection pass, and FAQ extraction. Rendering
> markdown is the easy half.

## 3. SEO

`marketing_meta()` builds title, description, canonical, Open Graph and Twitter cards.
Five JSON-LD generators:

| Function | Schema |
|---|---|
| `schema_software()` | SoftwareApplication + offers, from live prices |
| `schema_organization()` | Organization |
| `schema_faq()` | FAQPage, fed by `content_faq()` |
| `schema_breadcrumb()` | BreadcrumbList |
| `schema_article()` | Article, from front matter |

`robots.txt`, `sitemap.xml` and `llms.txt` are **generated**, not static files.
`llms.txt` is an AI-crawler manifest — keep it; it is part of the site's discovery
surface.

`/app/*` and `/api/*` must stay out of the index (the migration plan §77), and share
pages are excluded by response header rather than robots (`sharing.md` §5).

## 4. Analytics and attribution

`analytics_ids()` returns the GA4 measurement id and Microsoft Clarity project id,
stored per-org (`organizations.ga4_measurement_id`, `clarity_project_id`). Both are
**optional** — absent ids mean no tags render. The CSP allow-lists exactly these hosts;
without them the tags load into a blocked request and silently collect nothing
(`security.md` §1).

`attribution_capture()` runs in `bootstrap.php` on **every request**, before any
handler, so a visitor is credited to the channel that brought them even if they sign up
several pages later. It writes first-touch UTM data (`utm_source`, `utm_medium`,
`utm_campaign`, `utm_content`, `utm_landing`) which `attribution_stamp($orgId)` later
persists onto the organization at signup.

**First-touch, not last-touch** — do not "fix" it by overwriting on later visits.

The migration plan §78 asks that analytics stay optional and configurable. They already
are.

## 5. Pricing page

`marketing_pricing()` renders the live price ladder from the platform org row
(`payments.md` §5), with `competitor_prices()` for comparison and
`marketing_pricing_faqs()` for FAQ + JSON-LD. `/tools/cost-calculator` is an
interactive per-seat cost tool. `money_short()` formats compact figures.

Prices shown to visitors come from the **database**, not from a template. A Laravel
port that hardcodes prices into Blade will drift the moment a super admin reprices.

## 6. Downloads — Windows, macOS and Linux

`GET /download` renders the page; `GET /download/app` serves a binary. Artifacts are
resolved by globbing `server/public/downloads/` (gitignored — binaries are not repo
content):

| Function | Matches |
|---|---|
| `prebuilt_installer()` | `DeskPulse-Setup.exe`, `DeskPulse-<version>-Setup.exe`, `DeskPulse*Setup*.exe` |
| `prebuilt_bundle()` | `DeskPulse*Windows*.zip` (PyInstaller output) |
| `prebuilt_mac()` | `DeskPulse*.dmg`, `DeskPulse*mac*.zip`, `DeskPulse*macOS*.zip` |
| `prebuilt_linux()` | `DeskPulse*Linux*.tar.gz`, `DeskPulse*linux*.tar.gz`, `DeskPulse*.AppImage` |

**All three platforms have download slots.** If no Windows installer is present,
`marketing_download_app()` builds a portable `DeskPulse-v1.zip` on the fly — agent
source plus one-click `.bat` launchers.

`serve_file()` streams the artifact; `download_unavailable()` handles a missing one
gracefully rather than 500-ing.

> Note for the agent question (`agent-protocol.md` §1): the *site* offers Windows,
> macOS and Linux downloads, and the agent *code* runs on all three. In-repo build
> scripts exist for Windows (`build.ps1` + Inno Setup) and macOS (`build_mac.sh`);
> a Linux artifact must currently be built outside the repo and dropped into
> `downloads/`. Nothing here changes in this migration.

## 7. Laravel destination

| Current | Laravel |
|---|---|
| `marketing.php` handlers | `MarketingController` |
| `content.php` | `ContentService` + CommonMark, keeping §2's extras |
| `server/content/**.md` | unchanged on disk |
| `schema_*()` | `app/Services/Seo/StructuredData` |
| `robots.txt` / `sitemap.xml` / `llms.txt` | routed, generated |
| `attribution_capture()` | middleware, first-touch |
| `analytics_ids()` | config from the org row; tags in a Blade partial |
| downloads | `DownloadController` + `Storage`, same glob patterns |
| `templates/marketing/*` | `resources/views/marketing/*` with Tailwind |

Marketing carries roughly a third of the CSS (`ui-inventory.md` §7) — hero, pricing
grid, FAQ, showcase carousel, feature categories — and is first in the §72 page order.

## 8. Required tests

- Every marketing route renders 200, including each content collection.
- An unknown slug 404s; a traversal attempt (`../`, absolute path) is rejected by
  `content_slug_ok()`.
- Front matter parses; missing front matter does not fatal.
- Generated tables appear in comparison pages (token pass ran).
- FAQ JSON-LD matches the visible Q&A.
- All five JSON-LD types validate.
- Canonical, OG and Twitter tags present and unique per page.
- `robots.txt` disallows `/app/*` and `/api/*`; `sitemap.xml` lists public pages only;
  `llms.txt` renders.
- Prices on `/pricing` follow a super-admin reprice without a deploy.
- Analytics tags absent when ids are unset; present and CSP-allowed when set.
- First-touch attribution survives later visits and stamps the org at signup.
- Downloads: each platform's glob resolves; the on-the-fly zip fallback works; a
  missing artifact degrades gracefully.
- `HEAD` on every marketing route returns the same status as `GET`
  (`architecture.md` §2).

## 9. Migration risks

| Risk | Severity | Note |
|---|---|---|
| Dropping the token/table injection pass | **High** | Comparison pages silently lose their tables |
| Relaxing `content_slug_ok()` | **High** | Path traversal into the filesystem |
| Hardcoding prices in Blade | Medium | Drifts from the database on any reprice |
| Last-touch attribution | Medium | Misattributes every signup |
| Omitting the blog/compare/use-case routes | **High** | Live indexed URLs 404 — direct SEO loss |
| Losing `llms.txt` or generated sitemap | Medium | Discovery surface shrinks |
| Analytics hosts missing from the CSP | Medium | Tags load into a blocked request, collect nothing |
| Treating `downloads/` as repo content | Low | Binaries are deliberately gitignored |
