<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\ShareLink;
use App\Models\User;
use App\Services\Reporting\SessionStats;
use App\Support\Period;
use App\Support\Visibility;
use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * `GET /share/{token}` — a public, unauthenticated summary page.
 *
 * ## What it must never show
 *
 * **No screenshots, ever, at any scope.** No pay rate, labor cost or salary —
 * none of it reaches the view, rather than reaching it and being hidden. A
 * share URL is a capability token: anybody holding it can read this, including
 * whoever it gets forwarded to.
 *
 * ## Revoked and expired are indistinguishable
 *
 * Both answer the same 404 with the same wording as an unknown token. Telling
 * them apart lets somebody holding a dead token learn whether it ever worked
 * and whether the account is still live.
 *
 * ## Indexing is blocked by HEADER, not by a meta tag
 *
 * A crawler told not to fetch a page never reads the `noindex` inside it, so
 * anything already indexed stays indexed. A response header is always honoured.
 *
 * ## The window is the OWNING organization's clock
 *
 * There is no signed-in user to take a timezone from, and the figures have to
 * match what that organization sees on its own dashboard.
 *
 * @see docs/migration/sharing.md
 */
class ShareController extends Controller
{
    public function __construct(private readonly SessionStats $stats) {}

    public function show(Request $request, string $token)
    {
        // random_token() is URL-safe base64 — A-Z a-z 0-9 - _ — NOT hex. A
        // past bug sanitized a token with [^a-f0-9] and broke every link.
        $token = preg_replace('/[^A-Za-z0-9_-]/', '', $token);

        $link = ShareLink::query()->where('token', $token)->first();

        abort_if(
            $link === null || ! $this->isActive($link),
            404,
            'This share link is invalid or has expired.'
        );

        $organization = Organization::query()->whereKey($link->org_id)->firstOrFail();

        [$start, $end, $period, $label] = $this->resolveRange($request, $link);

        $ids = $this->targetUserIds($link);

        // Approved only — the same rule every internal report uses.
        $sessions = $this->stats->forUsers($ids, $start, $end);
        $sessionIds = $sessions->pluck('id')->all();

        $timezone = app(Period::class)->timezone((int) $link->org_id);

        return response()
            ->view('share.summary', [
                'title'    => $organization->name . ' — activity',
                'org'      => $organization,
                'link'     => $link,
                'label'    => $label,
                'period'   => $period,
                'summary'  => $this->stats->summarize($sessions),
                'daily'    => $this->stats->dailySeries($sessions, $start, $end, $timezone),
                'apps'     => $this->stats->topApps($sessionIds),
                'taskTimes' => $this->taskTimes($ids, $start, $end),
                'people'   => User::query()->whereIn('id', $ids)->orderBy('name')->pluck('name'),
            ])
            // Always honoured, unlike a meta tag on a page a crawler was told
            // not to fetch.
            ->header('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }

    /** Revoked or past its expiry. Both look the same from outside. */
    private function isActive(ShareLink $link): bool
    {
        if ($link->revoked) {
            return false;
        }

        return ! $link->expires_at || $link->expires_at->getTimestamp() >= time();
    }

    /**
     * Whose time this link covers.
     *
     * @return list<int>
     */
    private function targetUserIds(ShareLink $link): array
    {
        return match ($link->scope) {
            'org'  => Visibility::organizationUserIds((int) $link->org_id),
            'team' => DB::table('team_members')
                ->where('team_id', $link->target_id)
                ->pluck('user_id')
                ->map(fn ($id) => (int) $id)
                ->all() ?: [0],
            default => [(int) $link->target_id],
        };
    }

    /**
     * The six date modes, in precedence order.
     *
     * @return array{0: string, 1: string, 2: string, 3: string}
     */
    private function resolveRange(Request $request, ShareLink $link): array
    {
        $period = app(Period::class);
        $organizationId = (int) $link->org_id;
        $config = $period->organizationConfig($organizationId);
        $timezone = $config['tz'];

        $from = (string) $request->query('from', '');
        $to = (string) $request->query('to', '');

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            if (strcmp($from, $to) > 0) {
                [$from, $to] = [$to, $from];            // tolerate a reversed range
            }

            return [
                Period::localToUtc($from . ' 00:00:00', $timezone),
                Period::localToUtc(Period::addDays($to, 1) . ' 00:00:00', $timezone),
                'range',
                Period::rangeLabel($from, $to),
            ];
        }

        if (preg_match('/^(\d{4})-W(\d{2})$/', (string) $request->query('week', ''), $matches)) {
            $date = new DateTime();
            $date->setISODate((int) $matches[1], (int) $matches[2]);
            $startDate = $date->format('Y-m-d');

            return [
                Period::localToUtc($startDate . ' 00:00:00', $timezone),
                Period::localToUtc(Period::addDays($startDate, 7) . ' 00:00:00', $timezone),
                'week',
                'Week ' . $matches[2] . ', ' . $matches[1],
            ];
        }

        $requested = (string) $request->query('period', $link->period_default);

        if (! in_array($requested, ['day', 'week', 'month'], true)) {
            $requested = 'week';
        }

        $anchor = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query('date', ''))
            ? (string) $request->query('date')
            : (new DateTimeImmutable('now', new DateTimeZone($timezone)))->format('Y-m-d');

        // bounds() answers in LOCAL calendar dates; the queries want UTC
        // instants, cut in the owning organization's timezone.
        [$startDate, $endDateExclusive, $label] = $period->bounds($requested, $anchor, $config);

        return [
            Period::localToUtc($startDate . ' 00:00:00', $timezone),
            Period::localToUtc($endDateExclusive . ' 00:00:00', $timezone),
            $requested,
            $label,
        ];
    }

    /**
     * Time per task in the window — what was worked on, never who costs what.
     *
     * @param  list<int>  $ids
     * @return \Illuminate\Support\Collection
     */
    private function taskTimes(array $ids, string $start, string $end)
    {
        return DB::table('sessions as s')
            ->join('tasks as t', 't.id', '=', 's.task_id')
            ->whereIn('s.user_id', $ids)
            ->where('s.approval_status', 'approved')
            ->where('s.started_at', '>=', $start)
            ->where('s.started_at', '<', $end)
            ->groupBy('t.id', 't.title', 't.status')
            ->orderByDesc(DB::raw('SUM(s.active_s)'))
            ->limit(20)
            ->get(['t.title', 't.status', DB::raw('SUM(s.active_s) AS secs')]);
    }
}
