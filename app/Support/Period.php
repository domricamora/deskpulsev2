<?php

namespace App\Support;

use App\Models\Organization;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\Request;

/**
 * Reporting periods — the single resolver behind every dated page.
 *
 * Sessions are stored in UTC. Every window is therefore cut in the
 * ORGANIZATION's reporting timezone and only then converted to UTC for the
 * query. Cutting it in PHP's default timezone (which the legacy repo never
 * sets) made "today" depend on php.ini, and the same session could land on
 * different days for two people looking at the same screen.
 *
 * Ports report_org_cfg(), period_bounds(), pay_period_bounds(), period_ctx(),
 * period_options() and period_apply_plan_limit() from server/src/reports.php.
 *
 * Registered as a singleton so the per-organization config cache lives exactly
 * one request — and exactly one test.
 *
 * @see docs/migration/reports.md
 */
class Period
{
    /** Every period key a page may offer, with its pill label. */
    public const OPTIONS = [
        'day'   => 'Today',
        'week'  => 'This week',
        'pay'   => 'Pay period',
        'month' => 'This month',
    ];

    /** @var array<int, array<string, mixed>> */
    private array $config = [];

    /* ── Organization reporting configuration ────────────────────────────── */

    /**
     * Timezone, week start, pay cycle and pay currency for an organization.
     *
     * Defaults to UTC with a Monday week start and a semimonthly pay cycle,
     * which is the behaviour on a host that has configured nothing.
     *
     * @return array{tz: string, week_start: int, pay_cycle: string, pay_cycle_anchor: ?string, pay_currency: string}
     */
    public function organizationConfig(int $organizationId): array
    {
        if (isset($this->config[$organizationId])) {
            return $this->config[$organizationId];
        }

        $config = [
            'tz'               => 'UTC',
            'week_start'       => 1,
            'pay_cycle'        => 'semimonthly',
            'pay_cycle_anchor' => null,
            'pay_currency'     => 'USD',
        ];

        if ($organizationId > 0) {
            $row = Organization::query()
                ->whereKey($organizationId)
                ->first(['report_tz', 'week_start', 'pay_cycle', 'pay_cycle_anchor', 'pay_currency']);

            if ($row) {
                // An unknown identifier would make DateTimeZone throw on every
                // page, so it is validated here rather than trusted.
                if ($row->report_tz && in_array($row->report_tz, timezone_identifiers_list(), true)) {
                    $config['tz'] = $row->report_tz;
                }

                $weekStart = (int) ($row->week_start ?? 1);
                $config['week_start'] = ($weekStart >= 1 && $weekStart <= 7) ? $weekStart : 1;

                $config['pay_cycle'] = in_array($row->pay_cycle, ['weekly', 'biweekly', 'semimonthly', 'rolling15', 'monthly'], true)
                    ? $row->pay_cycle
                    : 'semimonthly';

                // Normalised to a plain Y-m-d string. The model casts this
                // column to a Carbon, and payBounds() concatenates the value
                // with ' 12:00:00' the way the legacy code does with the raw
                // PDO string — concatenating a Carbon yields
                // "2026-01-05 00:00:00 12:00:00", which strtotime() parses
                // into a different date and silently shifts every pay period.
                $config['pay_cycle_anchor'] = $row->pay_cycle_anchor
                    ? (string) $row->getRawOriginal('pay_cycle_anchor')
                    : null;
                $config['pay_currency'] = $row->pay_currency ?: 'USD';
            }
        }

        return $this->config[$organizationId] = $config;
    }

    /** The organization's reporting timezone (IANA). */
    public function timezone(int $organizationId): string
    {
        return $this->organizationConfig($organizationId)['tz'];
    }

    public function flush(): void
    {
        $this->config = [];
    }

    /* ── Calendar arithmetic ─────────────────────────────────────────────── */

    /** Wall-clock 'Y-m-d H:i:s' in $tz → the same instant as UTC 'Y-m-d H:i:s'. */
    public static function localToUtc(string $localDatetime, string $tz): string
    {
        try {
            $dt = new DateTimeImmutable($localDatetime, new DateTimeZone($tz));
        } catch (\Exception) {
            return $localDatetime;
        }

        return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /** UTC 'Y-m-d H:i:s' → the local calendar date it falls on in $tz. */
    public static function utcToLocalDate(string $utcDatetime, string $tz): string
    {
        try {
            $dt = new DateTimeImmutable($utcDatetime, new DateTimeZone('UTC'));

            return $dt->setTimezone(new DateTimeZone($tz))->format('Y-m-d');
        } catch (\Exception) {
            return substr($utcDatetime, 0, 10);
        }
    }

    /**
     * The VIEWER's timezone, from the `dp_tz` cookie. Ports user_tz().
     *
     * This is the third clock (`reports.md` §1) and the only place it is used
     * for anything but display: a person filling in a manual timesheet entry
     * types their own wall-clock, not the organization's and not UTC. An
     * unknown or absent value falls back to UTC rather than to the server's
     * timezone — a wrong guess here silently shifts somebody's hours.
     */
    public static function viewerTimezone(Request $request): string
    {
        $tz = (string) $request->cookie('dp_tz', '');

        return in_array($tz, timezone_identifiers_list(), true) ? $tz : 'UTC';
    }

    /**
     * A submitted datetime as UTC 'Y-m-d H:i:s' for storage. Ports utc_store().
     *
     * The browser sends both a UTC value it computed and the raw
     * `datetime-local` string. The UTC one is preferred by the caller; this
     * handles the fallback, interpreting a bare local string in the viewer's
     * own timezone. An empty value means now.
     */
    public static function submittedToUtc(string $submitted, string $viewerTimezone = 'UTC'): string
    {
        $submitted = trim($submitted);

        if ($submitted === '') {
            return gmdate('Y-m-d H:i:s');
        }

        try {
            return (new DateTimeImmutable($submitted, new DateTimeZone($viewerTimezone)))
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s');
        } catch (\Exception) {
            return gmdate('Y-m-d H:i:s', strtotime($submitted) ?: time());
        }
    }

    /**
     * Shift a Y-m-d date by N days.
     *
     * Anchored at midday so a DST transition can never move the result onto the
     * neighbouring date.
     */
    public static function addDays(string $ymd, int $days): string
    {
        return date('Y-m-d', strtotime($ymd . ' 12:00:00 ' . ($days >= 0 ? '+' : '-') . abs($days) . ' days'));
    }

    /** "3–9 Aug 2026", "28 Jul – 3 Aug 2026", "Mon 3 Aug 2026". */
    public static function rangeLabel(string $startDate, string $endDateInclusive): string
    {
        $a = strtotime($startDate . ' 12:00:00');
        $b = strtotime($endDateInclusive . ' 12:00:00');

        if ($a === $b) {
            return date('D j M Y', $a);
        }

        if (date('Y-m', $a) === date('Y-m', $b)) {
            return date('j', $a) . '–' . date('j M Y', $b);
        }

        if (date('Y', $a) === date('Y', $b)) {
            return date('j M', $a) . ' – ' . date('j M Y', $b);
        }

        return date('j M Y', $a) . ' – ' . date('j M Y', $b);
    }

    /* ── Period bounds ───────────────────────────────────────────────────── */

    /**
     * Resolve a period into local calendar bounds plus navigation anchors.
     *
     * @param  array<string, mixed>  $config
     * @return array{0: string, 1: string, 2: string, 3: string, 4: string}
     *         [startDate, endDateExclusive, label, prevAnchor, nextAnchor]
     */
    public function bounds(string $period, string $anchorDate, array $config): array
    {
        $ts = strtotime($anchorDate . ' 12:00:00');

        switch ($period) {
            case 'day':
                $start = date('Y-m-d', $ts);
                $end = self::addDays($start, 1);

                return [$start, $end, date('D j M Y', $ts), self::addDays($start, -1), $end];

            case 'month':
                $start = date('Y-m-01', $ts);
                $end = date('Y-m-d', strtotime($start . ' 12:00:00 first day of next month'));
                $prev = date('Y-m-d', strtotime($start . ' 12:00:00 first day of last month'));

                return [$start, $end, date('F Y', $ts), $prev, $end];

            case 'pay':
                return $this->payBounds($anchorDate, $config);

            case 'week':
            default:
                $dayOfWeek = (int) date('N', $ts);                       // 1=Mon..7=Sun
                $offset = ($dayOfWeek - $config['week_start'] + 7) % 7;
                $start = self::addDays(date('Y-m-d', $ts), -$offset);
                $end = self::addDays($start, 7);

                return [
                    $start,
                    $end,
                    self::rangeLabel($start, self::addDays($end, -1)),
                    self::addDays($start, -7),
                    $end,
                ];
        }
    }

    /**
     * The organization's configured payroll window containing $anchorDate.
     *
     *   semimonthly — 1st–15th and 16th–end of month (the standard cut-off)
     *   rolling15   — fixed 15-day blocks counted from the org's anchor date
     *   biweekly    — fixed 14-day blocks counted from the org's anchor date
     *   weekly / monthly — as the matching calendar period
     *
     * @param  array<string, mixed>  $config
     * @return array{0: string, 1: string, 2: string, 3: string, 4: string}
     */
    public function payBounds(string $anchorDate, array $config): array
    {
        $cycle = $config['pay_cycle'];
        $ts = strtotime($anchorDate . ' 12:00:00');

        if ($cycle === 'weekly' || $cycle === 'monthly') {
            return $this->bounds($cycle === 'weekly' ? 'week' : 'month', $anchorDate, $config);
        }

        if ($cycle === 'semimonthly') {
            $day = (int) date('j', $ts);

            if ($day <= 15) {
                $start = date('Y-m-01', $ts);
                $end = date('Y-m-16', $ts);
                $prev = date('Y-m-16', strtotime($start . ' 12:00:00 first day of last month'));
            } else {
                $start = date('Y-m-16', $ts);
                $end = date('Y-m-d', strtotime(date('Y-m-01', $ts) . ' 12:00:00 first day of next month'));
                $prev = date('Y-m-01', $ts);
            }

            return [$start, $end, self::rangeLabel($start, self::addDays($end, -1)), $prev, $end];
        }

        // Fixed-length blocks (rolling15 / biweekly) counted from the anchor.
        $length = $cycle === 'biweekly' ? 14 : 15;
        $origin = $config['pay_cycle_anchor'] ?: '2024-01-01';
        $originTs = strtotime($origin . ' 12:00:00');
        $elapsed = (int) floor(($ts - $originTs) / 86400);
        $blocks = (int) floor($elapsed / $length);

        $start = self::addDays(date('Y-m-d', $originTs), $blocks * $length);
        $end = self::addDays($start, $length);

        return [
            $start,
            $end,
            self::rangeLabel($start, self::addDays($end, -1)),
            self::addDays($start, -$length),
            $end,
        ];
    }

    /**
     * The period pills a page offers.
     *
     * @param  list<string>  $only
     * @return array<string, string>
     */
    public static function options(array $only = []): array
    {
        if (! $only) {
            return self::OPTIONS;
        }

        $out = [];

        foreach ($only as $key) {
            if (isset(self::OPTIONS[$key])) {
                $out[$key] = self::OPTIONS[$key];
            }
        }

        return $out;
    }

    /**
     * The current date filter as a query-string fragment, for an export link
     * that has to mirror exactly what is on screen.
     *
     * Ports period_qs(). A custom range carries from/to; everything else
     * carries the period and its anchor.
     *
     * @param  array<string, mixed>  $context
     */
    public static function queryString(array $context): string
    {
        if ($context['period'] === 'range' && $context['from'] && $context['to']) {
            return 'period=range&from=' . rawurlencode($context['from'])
                . '&to=' . rawurlencode($context['to']);
        }

        return 'period=' . rawurlencode($context['period'])
            . '&date=' . rawurlencode($context['anchor']);
    }

    /* ── The resolver ────────────────────────────────────────────────────── */

    /**
     * Resolve the full period context from the query string.
     *
     * Validates ?period, honours ?date as the in-window anchor for ANY period
     * (including day — the legacy code could only navigate week and month),
     * handles ?period=range&from&to, and returns the prev/next anchors that
     * drive the ◀ ▶ buttons.
     *
     * $allowed restricts which pills this page accepts; an out-of-range ?period
     * falls back to $default rather than silently rendering data with no pill
     * lit.
     *
     * @param  list<string>  $allowed
     * @return array<string, mixed>
     */
    public function context(
        Request $request,
        int $organizationId,
        string $default = 'week',
        array $allowed = []
    ): array {
        $config = $this->organizationConfig($organizationId);
        $tz = $config['tz'];
        $today = (new DateTimeImmutable('now', new DateTimeZone($tz)))->format('Y-m-d');
        $valid = $allowed ?: array_keys(self::OPTIONS);

        $period = (string) $request->query('period', $default);

        if ($period !== 'range' && ! in_array($period, $valid, true)) {
            $period = $default;
        }

        $ymd = '/^\d{4}-\d{2}-\d{2}$/';

        if ($period === 'range') {
            $from = (string) $request->query('from', '');
            $to = (string) $request->query('to', '');

            if (preg_match($ymd, $from) && preg_match($ymd, $to)) {
                if (strcmp($from, $to) > 0) {
                    [$from, $to] = [$to, $from];       // tolerate reversed inputs
                }

                $endExclusive = self::addDays($to, 1);
                $span = max(1, (int) round(
                    (strtotime($to . ' 12:00:00') - strtotime($from . ' 12:00:00')) / 86400
                ) + 1);

                return [
                    'period'      => 'range',
                    'tz'          => $tz,
                    'cycle'       => $config['pay_cycle'],
                    'start'       => self::localToUtc($from . ' 00:00:00', $tz),
                    'end'         => self::localToUtc($endExclusive . ' 00:00:00', $tz),
                    'start_date'  => $from,
                    'end_date'    => $to,
                    'end_date_ex' => $endExclusive,
                    'anchor'      => $from,
                    'from'        => $from,
                    'to'          => $to,
                    'label'       => self::rangeLabel($from, $to),
                    'prev'        => self::addDays($from, -$span),
                    'next'        => self::addDays($from, $span),
                    'today'       => $today,
                    'days'        => $span,
                ];
            }

            $period = $default;                        // incomplete range → fall back
        }

        $anchor = (string) $request->query('date', '');

        if (! preg_match($ymd, $anchor)) {
            $anchor = $today;
        }

        [$start, $endExclusive, $label, $prev, $next] = $this->bounds($period, $anchor, $config);

        return $this->applyPlanLimit([
            'period'      => $period,
            'tz'          => $tz,
            'cycle'       => $config['pay_cycle'],
            'start'       => self::localToUtc($start . ' 00:00:00', $tz),
            'end'         => self::localToUtc($endExclusive . ' 00:00:00', $tz),
            'start_date'  => $start,
            'end_date'    => self::addDays($endExclusive, -1),
            'end_date_ex' => $endExclusive,
            'anchor'      => $anchor,
            'from'        => null,
            'to'          => null,
            'label'       => $label,
            'prev'        => $prev,
            'next'        => $next,
            'today'       => $today,
            'days'        => max(1, (int) round(
                (strtotime($endExclusive . ' 12:00:00') - strtotime($start . ' 12:00:00')) / 86400
            )),
        ], $organizationId, $today);
    }

    /**
     * Clamp a resolved period to the plan's history window (Solo: 7 days).
     *
     * Applied here, in the single resolver, so every page, CSV export and share
     * link inherits it rather than each re-implementing the rule and one of
     * them forgetting. Sets 'history_capped' so a template can say why the
     * window is short instead of silently showing less than was asked for.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function applyPlanLimit(array $context, int $organizationId, string $today): array
    {
        if ($organizationId <= 0) {
            return $context;
        }

        $days = app(PlanLimits::class)->for($organizationId)['history_days'];

        if ($days <= 0) {
            return $context;
        }

        $earliest = self::addDays($today, -($days - 1));

        if (strcmp($context['start_date'], $earliest) >= 0) {
            return $context;                       // already inside the window
        }

        $context['start_date'] = $earliest;
        $context['start'] = self::localToUtc($earliest . ' 00:00:00', $context['tz']);
        $context['history_capped'] = $days;
        $context['days'] = max(1, (int) round(
            (strtotime($context['end_date_ex'] . ' 12:00:00') - strtotime($earliest . ' 12:00:00')) / 86400
        ));

        return $context;
    }
}
