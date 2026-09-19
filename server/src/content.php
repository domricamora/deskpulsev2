<?php
/**
 * Flat-file content system for the marketing site: comparison pages, use cases, blog
 * posts and the trust pages.
 *
 * Sources live in server/content/<collection>/<slug>.md — above the document root, so
 * the markdown is never web-servable. One route handler serves them all, and the
 * sitemap enumerates them, so adding a page is adding a file.
 *
 * The markdown subset is hand-rolled, in keeping with xlsx_rows()/csv_rows()/pdf.php:
 * no Composer here. It only ever parses trusted files from this repo — never user
 * input — which is why a small parser is acceptable where normally it would not be.
 * Anything outside the supported subset is escaped, not passed through.
 */

/** Root of the content tree (outside public/). */
function content_dir(): string
{
    return dirname(__DIR__) . '/content';
}

/** Collections that map to a URL prefix. */
function content_collections(): array
{
    return ['pages' => '', 'compare' => '/compare', 'use-cases' => '/use-cases', 'blog' => '/blog'];
}

/**
 * A slug is a filename component. Validate BEFORE touching the filesystem — never
 * concatenate request input into a path and hope realpath() catches it later.
 */
function content_slug_ok(string $slug): bool
{
    return (bool) preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $slug);
}

function content_path(string $collection, string $slug): ?string
{
    if (!isset(content_collections()[$collection]) || !content_slug_ok($slug)) {
        return null;
    }
    $p = content_dir() . '/' . $collection . '/' . $slug . '.md';
    return is_file($p) ? $p : null;
}

/**
 * Split `---` front matter from the body. Flat `key: value` only — the repo has no
 * YAML parser and these files need no nesting. `faq_auto: false` and similar booleans
 * are returned as real booleans; everything else stays a string.
 */
function content_front_matter(string $raw): array
{
    $meta = [];
    $body = $raw;
    if (preg_match('/^---\R(.*?)\R---\R?(.*)$/s', ltrim($raw, "\xEF\xBB\xBF"), $m)) {
        $body = $m[2];
        foreach (preg_split('/\R/', $m[1]) as $line) {
            if (!preg_match('/^\s*([A-Za-z0-9_]+)\s*:\s*(.*)$/', $line, $kv)) {
                continue;
            }
            $v = trim($kv[2], " \t\"'");
            if ($v === 'true' || $v === 'false') {
                $v = $v === 'true';
            }
            $meta[$kv[1]] = $v;
        }
    }
    return [$meta, $body];
}

/**
 * Substitute live pricing into content, so a reprice can never leave a comparison page
 * advertising a number we no longer charge. This is the same problem the campaign's
 * sync-prices.mjs solves for its own documents — solved once here, at render time.
 */
function content_tokens(string $html): string
{
    $p = plan_base_prices();
    $money = fn(float $v) => '$' . (fmod($v, 1.0) === 0.0 ? number_format($v, 0) : number_format($v, 2));
    $map = [
        '{{price.solo}}'        => $p['solo'] > 0 ? $money((float) $p['solo']) : 'Free',
        '{{price.individual}}'  => $money((float) $p['individual']),
        '{{price.per_seat}}'    => $money((float) $p['per_seat']),
        '{{price.cap}}'         => $money((float) $p['seat_cap']),
        '{{price.team_floor}}'  => $money(plan_seat_price(max(1, (int) $p['seats_bill_min']), $p)),
        '{{seats.min}}'         => (string) (int) $p['seats_bill_min'],
        '{{seats.cap_engages}}' => (string) plan_cap_seats($p),
        '{{seats.cap_covers}}'  => (string) (int) $p['seats_cap_covers'],
        '{{year}}'              => gmdate('Y'),
    ];
    // Competitor list prices, so prose like "Hubstaff's $10 Team tier" tracks the same
    // source the comparison tables and the calculator use.
    foreach (competitor_prices() as $v) {
        $map['{{vendor.' . $v['key'] . '.price}}'] = $money((float) $v['per_seat']);
        $map['{{vendor.' . $v['key'] . '.tier}}'] = $v['tier'];
        $map['{{vendor.' . $v['key'] . '.checked}}'] = $v['checked'];
    }
    $html = strtr($html, $map);

    // {{table.vs:<vendor>}} — the seat-by-seat cost table, generated rather than typed.
    // A comparison page that hardcodes its own arithmetic is a page that silently lies
    // the first time either side changes a price.
    $html = (string) preg_replace_callback('/\{\{table\.vs:([a-z0-9_]+)\}\}/', function ($m) {
        return content_vs_table($m[1]);
    }, $html);

    // {{table.matrix}} — every vendor against DeskPulse at the standard seat bands.
    return str_replace('{{table.matrix}}', content_matrix_table(), $html);
}

/** Every tracked vendor priced at the standard seat bands, against DeskPulse. */
function content_matrix_table(): string
{
    $p = plan_base_prices();
    $bands = [5, 20, 65, 100];
    $m = fn(float $v) => money_short($v);
    $head = '<tr><th>Tool</th><th>Per seat</th>';
    foreach ($bands as $b) {
        $head .= '<th>' . $b . ' seats</th>';
    }
    $head .= '</tr>';

    $rows = '';
    foreach (competitor_prices() as $v) {
        $rows .= '<tr><td>' . e($v['name']) . ' <span class="muted">(' . e($v['tier']) . ')</span></td>'
            . '<td data-sort="' . $v['per_seat'] . '">' . e($m((float) $v['per_seat'])) . '</td>';
        foreach ($bands as $b) {
            $rows .= '<td data-sort="' . ($v['per_seat'] * $b) . '">' . e($m($v['per_seat'] * $b)) . '</td>';
        }
        $rows .= '</tr>';
    }
    // DeskPulse last and highlighted — it is the row the reader is being asked to check.
    $capLabel = (float) $p['seat_cap'] > 0
        ? $m((float) $p['per_seat']) . ', capped at ' . $m((float) $p['seat_cap'])
        : $m((float) $p['per_seat']);
    $rows .= '<tr class="row-self"><td><b>DeskPulse</b> <span class="muted">(Team)</span></td>'
        . '<td data-sort="' . $p['per_seat'] . '"><b>' . e($capLabel) . '</b></td>';
    foreach ($bands as $b) {
        $rows .= '<td data-sort="' . plan_seat_price($b, $p) . '"><b>'
            . e($m(plan_seat_price($b, $p))) . '</b></td>';
    }
    $rows .= '</tr>';

    return '<table class="data" data-nofilter><thead>' . $head . '</thead><tbody>' . $rows
        . '</tbody></table>';
}

/** Seat-by-seat cost table for a comparison page, built from live prices. */
function content_vs_table(string $vendorKey): string
{
    $vendor = null;
    foreach (competitor_prices() as $v) {
        if ($v['key'] === $vendorKey) {
            $vendor = $v;
            break;
        }
    }
    if (!$vendor) {
        return '';
    }
    $p = plan_base_prices();
    $capSeats = plan_cap_seats($p);
    $m = fn(float $v) => money_short($v);
    $rows = '';
    foreach ([5, 10, 20, 30, 45, 65, 80, 100] as $seats) {
        $dp = plan_seat_price($seats, $p);
        $them = $vendor['per_seat'] * $seats;
        $capped = $capSeats > 0 && $seats >= $capSeats;
        $rows .= '<tr><td data-sort="' . $seats . '"><b>' . $seats . '</b></td>'
            . '<td data-sort="' . $dp . '"><b>' . e($m($dp)) . '</b>'
            . ($capped ? ' <span class="tag">cap</span>' : '') . '</td>'
            . '<td data-sort="' . $them . '">' . e($m($them)) . '</td>'
            . '<td data-sort="' . ($them - $dp) . '">&minus;' . e($m($them - $dp)) . '/mo</td>'
            . '<td data-sort="' . round((1 - $dp / max(0.01, $them)) * 100) . '"><b>'
            . round((1 - $dp / max(0.01, $them)) * 100) . '%</b></td></tr>';
    }
    return '<table class="data" data-nofilter><thead><tr><th>Seats</th><th>DeskPulse</th>'
        . '<th>' . e($vendor['name']) . ' ' . e($vendor['tier']) . ' (' . e($m($vendor['per_seat']))
        . '/seat)</th><th>Difference</th><th>You save</th></tr></thead><tbody>'
        . $rows . '</tbody></table>'
        . '<p class="muted small">' . e($vendor['name']) . ' list price for the '
        . e($vendor['tier']) . ' tier, checked ' . e($vendor['checked'])
        . '. DeskPulse is ' . e($m((float) $p['per_seat'])) . '/seat until the total reaches '
        . e($m((float) $p['seat_cap'])) . ', then it stops.</p>';
}

/**
 * Markdown → HTML for the supported subset: ATX headings, bold/italic/code, links,
 * bullet and ordered lists, GFM pipe tables, blockquotes, horizontal rules and
 * paragraphs. Tables matter most — these pages are largely comparison tables.
 */
function md_to_html(string $md): string
{
    // HTML comments are notes to whoever maintains the file, not page content — strip
    // them before parsing. (The inline parser escapes everything, so a comment left in
    // place would otherwise render as literal "<!-- ... -->" text on a public page.)
    $md = (string) preg_replace('/<!--.*?-->/s', '', $md);
    $lines = preg_split('/\R/', str_replace("\t", '    ', $md));
    $out = [];
    $n = count($lines);
    $i = 0;
    $para = [];

    $flush = function () use (&$para, &$out) {
        if ($para) {
            $out[] = '<p>' . md_inline(implode(' ', $para)) . '</p>';
            $para = [];
        }
    };

    while ($i < $n) {
        $line = $lines[$i];
        $trim = trim($line);

        if ($trim === '') {
            $flush();
            $i++;
            continue;
        }
        // Fenced code — emitted verbatim and escaped.
        if (preg_match('/^```/', $trim)) {
            $flush();
            $code = [];
            $i++;
            while ($i < $n && !preg_match('/^```/', trim($lines[$i]))) {
                $code[] = $lines[$i];
                $i++;
            }
            $i++;
            $out[] = '<pre><code>' . e(implode("\n", $code)) . '</code></pre>';
            continue;
        }
        if (preg_match('/^(#{1,4})\s+(.*)$/', $trim, $m)) {
            $flush();
            $lvl = strlen($m[1]) + 1;          // '#' is the page <h1>; content starts at <h2>
            $lvl = min(6, $lvl);
            $text = md_inline($m[2]);
            $out[] = "<h{$lvl} id=\"" . e(md_anchor($m[2])) . "\">{$text}</h{$lvl}>";
            $i++;
            continue;
        }
        if (preg_match('/^(---+|\*\*\*+)$/', $trim)) {
            $flush();
            $out[] = '<hr>';
            $i++;
            continue;
        }
        // GFM table: a header row followed by a |---|---| separator.
        if (str_contains($trim, '|') && $i + 1 < $n && preg_match('/^\s*\|?[\s:|-]+\|[\s:|-]*$/', $lines[$i + 1])) {
            $flush();
            $head = md_table_cells($trim);
            $i += 2;
            $rows = [];
            while ($i < $n && str_contains($lines[$i], '|') && trim($lines[$i]) !== '') {
                $rows[] = md_table_cells(trim($lines[$i]));
                $i++;
            }
            $h = '';
            foreach ($head as $c) {
                $h .= '<th>' . md_inline($c) . '</th>';
            }
            $b = '';
            foreach ($rows as $r) {
                $b .= '<tr>';
                foreach ($r as $c) {
                    $b .= '<td>' . md_inline($c) . '</td>';
                }
                $b .= '</tr>';
            }
            // class="data" opts the table into tables.js: filters, sorting and the
            // responsive scroll wrapper the whole app already uses.
            $out[] = '<table class="data"><thead><tr>' . $h . '</tr></thead><tbody>' . $b . '</tbody></table>';
            continue;
        }
        if (preg_match('/^>\s?(.*)$/', $trim, $m)) {
            $flush();
            $quote = [$m[1]];
            $i++;
            while ($i < $n && preg_match('/^>\s?(.*)$/', trim($lines[$i]), $m2)) {
                $quote[] = $m2[1];
                $i++;
            }
            $out[] = '<blockquote><p>' . md_inline(implode(' ', $quote)) . '</p></blockquote>';
            continue;
        }
        if (preg_match('/^([-*+]|\d+\.)\s+/', $trim)) {
            $flush();
            $ordered = (bool) preg_match('/^\d+\./', $trim);
            $items = [];
            while ($i < $n && preg_match('/^\s*(?:[-*+]|\d+\.)\s+(.*)$/', $lines[$i], $m3)) {
                $item = $m3[1];
                $i++;
                // Continuation lines belong to the current bullet.
                while ($i < $n && trim($lines[$i]) !== ''
                       && !preg_match('/^\s*(?:[-*+]|\d+\.)\s+/', $lines[$i])
                       && !preg_match('/^(#{1,4}\s|>|```)/', trim($lines[$i]))) {
                    $item .= ' ' . trim($lines[$i]);
                    $i++;
                }
                $items[] = '<li>' . md_inline($item) . '</li>';
            }
            $tag = $ordered ? 'ol' : 'ul';
            $out[] = "<{$tag}>" . implode('', $items) . "</{$tag}>";
            continue;
        }
        $para[] = $trim;
        $i++;
    }
    $flush();
    return implode("\n", $out);
}

/** Inline markdown. Escapes first, so no raw HTML from a source file reaches the page. */
function md_inline(string $s): string
{
    $s = e($s);
    // Code spans first — their contents must not be re-processed for emphasis.
    $s = preg_replace_callback('/`([^`]+)`/', fn($m) => '<code>' . $m[1] . '</code>', $s);
    $s = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $s);
    $s = preg_replace('/(?<![\w*])\*([^*\n]+)\*(?![\w*])/', '<em>$1</em>', $s);
    // [text](url) — http(s), mailto and site-relative only. Anything else (javascript:,
    // data:) is left as literal text.
    $s = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', function ($m) {
        $href = $m[2];
        $ok = preg_match('~^(https?://|mailto:|/)~i', $href);
        if (!$ok) {
            return $m[0];
        }
        $ext = (bool) preg_match('~^https?://~i', $href);
        $attr = $ext ? ' target="_blank" rel="noopener nofollow"' : '';
        // Site-relative links go through url() so they work under a subdirectory install.
        if (!$ext && !str_starts_with($href, 'mailto:')) {
            $href = url($href);
        }
        return '<a href="' . $href . '"' . $attr . '>' . $m[1] . '</a>';
    }, $s);
    return $s;
}

function md_anchor(string $s): string
{
    $s = strtolower(trim(strip_tags($s)));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim((string) $s, '-');
}

function md_table_cells(string $row): array
{
    $row = trim($row);
    $row = preg_replace('/^\|/', '', $row);
    $row = preg_replace('/\|$/', '', $row);
    return array_map('trim', explode('|', (string) $row));
}

/**
 * Pull "**Question?**" + following paragraph pairs out of a `## FAQ` section so every
 * content page gets FAQPage structured data without hand-maintaining a second copy.
 * FAQ blocks are the single most quoted element in AI answers and rich results.
 */
function content_faq(string $md): array
{
    if (!preg_match('/^##\s+FAQ\s*$(.*?)(?=^##\s|\z)/msi', $md, $m)) {
        return [];
    }
    $out = [];
    if (preg_match_all('/^\*\*(.+?)\*\*\s*$\R+(.+?)(?=\R\R|\z)/ms', $m[1], $pairs, PREG_SET_ORDER)) {
        foreach ($pairs as $p) {
            $q = trim($p[1]);
            $a = trim(preg_replace('/\s+/', ' ', strip_tags(md_inline($p[2]))));
            if ($q !== '' && $a !== '') {
                $out[] = [$q, html_entity_decode($a, ENT_QUOTES, 'UTF-8')];
            }
        }
    }
    return $out;
}

/** Parse one content file into ['meta' => …, 'html' => …, 'faq' => …]. */
function content_parse(string $path, string $urlPath): array
{
    $raw = (string) file_get_contents($path);
    [$meta, $body] = content_front_matter($raw);
    $meta['slug'] = basename($path, '.md');
    $meta['url'] = $urlPath;
    $meta['lastmod'] = $meta['lastmod'] ?? gmdate('Y-m-d', (int) filemtime($path));
    return [
        'meta' => $meta,
        'html' => content_tokens(md_to_html($body)),
        'faq'  => ($meta['faq_auto'] ?? true) === false ? [] : content_faq(content_tokens($body)),
    ];
}

/**
 * List a collection from front matter only (no body parse) — for the blog index, the
 * footer's compare column and the sitemap. Sorted newest first where dated.
 */
function content_list(string $collection): array
{
    static $cache = [];
    if (isset($cache[$collection])) {
        return $cache[$collection];
    }
    $prefix = content_collections()[$collection] ?? null;
    if ($prefix === null) {
        return [];
    }
    $out = [];
    foreach (glob(content_dir() . '/' . $collection . '/*.md') ?: [] as $f) {
        $raw = (string) file_get_contents($f);
        [$meta] = content_front_matter($raw);
        if (($meta['draft'] ?? false) === true) {
            continue;
        }
        $slug = basename($f, '.md');
        $meta['slug'] = $slug;
        $meta['url'] = ($prefix === '' ? '/' . $slug : $prefix . '/' . $slug);
        $meta['lastmod'] = $meta['lastmod'] ?? gmdate('Y-m-d', (int) filemtime($f));
        $meta['title'] = $meta['title'] ?? $slug;
        $out[] = $meta;
    }
    usort($out, fn($a, $b) => strcmp((string) ($b['published'] ?? $b['lastmod']),
                                    (string) ($a['published'] ?? $a['lastmod'])));
    $cache[$collection] = $out;
    return $out;
}
