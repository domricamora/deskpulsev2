@extends('layouts.app')

{{--
    The dashboard. Ported from server/templates/dashboard/overview.php.

    The two `@if` blocks that matter are $agent (a member's personal workspace)
    and $roster (the people under a manager). One of them is always absent —
    together they are what makes this one route render as three different pages.
--}}

@php
    $activeColor = '#3b82f6';   // blue active
    $idleColor = '#fbbf24';     // amber inactive

    // Built here rather than inline: @json takes one expression and cannot
    // parse a multi-line array literal.
    $dailySeries = [
        ['name' => 'Active', 'data' => $daily['active'], 'color' => $activeColor],
        ['name' => 'Inactive', 'data' => $daily['inactive'], 'color' => $idleColor],
    ];

    $donutValues = [
        round($summary['active_s'] / 3600, 2),
        round($summary['inactive_s'] / 3600, 2),
    ];

    $appSeries = [[
        'name'  => 'Minutes focused',
        'data'  => array_map(fn ($s) => round($s / 60, 1), $apps['seconds']),
        'color' => $activeColor,
    ]];
@endphp

@section('content')

    <x-period-switch :ctx="$ctx" :periods="$periods" path="/app/overview">
        <a class="ghost right"
           href="{{ url('/app/export.csv?' . \App\Support\Period::queryString($ctx)) }}">Export CSV</a>
    </x-period-switch>

    <div class="stat-row">
        <div class="stat"><span class="lbl">Active time</span><b>{{ \App\Support\Format::hms($summary['active_s']) }}</b>
            <span class="sub">avg {{ \App\Support\Format::hms($stats['avg_daily_s']) }}/day</span></div>
        <div class="stat"><span class="lbl">Inactive time</span><b>{{ \App\Support\Format::hms($summary['inactive_s']) }}</b></div>
        <div class="stat"><span class="lbl">Activity</span><b>{{ $summary['activity_pct'] }}%</b></div>
        <div class="stat"><span class="lbl">Sessions</span><b>{{ $summary['count'] }}</b>
            <span class="sub">{{ $stats['days_tracked'] }} day{{ $stats['days_tracked'] == 1 ? '' : 's' }} tracked</span></div>
        <div class="stat"><span class="lbl">Avg session</span><b>{{ \App\Support\Format::hms($stats['avg_session_s']) }}</b>
            <span class="sub">longest {{ \App\Support\Format::hms($stats['longest_session_s']) }}</span></div>
        <div class="stat"><span class="lbl">Idle time</span><b>{{ \App\Support\Format::hms($stats['idle_total_s']) }}</b>
            <span class="sub">{{ $stats['idle_count'] }} idle break{{ $stats['idle_count'] == 1 ? '' : 's' }}</span></div>
        @if ($canViewRates)
            <div class="stat"><span class="lbl">Labor cost</span><b>{{ \App\Support\Format::money($cost['amount'], $cost['currency']) }}</b></div>
        @endif
        <div class="stat"><span class="lbl">Screenshots</span><b>{{ $stats['screenshots'] }}</b></div>
        <div class="stat"><span class="lbl">Open tasks</span><b>{{ $stats['open_tasks'] }}</b></div>
        @if ($stats['team_view'])
            <div class="stat"><span class="lbl">Active people</span><b>{{ $stats['people_active'] }}</b>
                <span class="sub">of {{ $stats['headcount'] }} · {{ $stats['tracking_now'] }} live now</span></div>
        @endif
    </div>

    @if ($agent)
        <div class="panel agent-workspace">
            <div class="panel-head">
                <h3>Your information</h3>
                <a class="btn sm ghost" href="{{ url('/app/profile') }}">Edit profile</a>
            </div>
            <div class="ws-grid">
                <div class="ws-item"><span class="lbl">Name</span><b>{{ $agent['name'] }}</b></div>
                <div class="ws-item"><span class="lbl">Role</span><b>{{ $agent['role']?->label() }}</b></div>
                <div class="ws-item"><span class="lbl">Email</span><b>{{ $agent['email'] }}</b></div>
                <div class="ws-item"><span class="lbl">Phone</span><b>{{ $agent['phone'] ?: '—' }}</b></div>
                <div class="ws-item"><span class="lbl">Job title</span><b>{{ $agent['job_title'] ?: '—' }}</b></div>
                <div class="ws-item"><span class="lbl">Pay rate</span><b>{{ $agent['pay'] }}</b>
                    <span class="muted small">set by your admin/HR</span></div>
                <div class="ws-item"><span class="lbl">Work schedule</span><b>{{ $agent['schedule'] }}</b>
                    <span class="muted small">set by your admin/HR</span></div>
            </div>
            @if ($agent['live'] && $agent['in_schedule'] === false)
                <p class="flash out-of-schedule">⏰ <b>Heads up:</b> you're tracking
                    <b>outside your scheduled hours</b> ({{ $agent['schedule'] }}). Monitoring continues and
                    this time is still recorded.</p>
            @endif
        </div>

        <div class="panel agent-workspace">
            <div class="panel-head">
                <h3>Your workspace</h3>
                <span class="pill {{ $agent['live'] ? 'on' : 'off' }}">{{ $agent['live'] ? '● Tracking now' : 'Not tracking' }}</span>
            </div>
            <div class="ws-grid">
                <div class="ws-item"><span class="lbl">Current task</span>
                    <b>{{ $agent['current_task'] ?: '—' }}</b>
                    <a class="muted small" href="{{ url('/app/tasks') }}">manage tasks</a></div>
                <div class="ws-item"><span class="lbl">Tracking device</span>
                    @if ($agent['device'])
                        <b>{{ $agent['device']->name }}</b>
                        <span class="muted small">
                            @if ($agent['device']->last_seen)
                                last seen
                                <time class="dp-time" data-utc="{{ $agent['device']->last_seen }}" data-fmt="full"></time>
                            @else
                                never connected
                            @endif
                        </span>
                    @else
                        <b>No device</b><a class="muted small" href="{{ url('/app/download') }}">download the app</a>
                    @endif
                </div>
                <div class="ws-item ws-links"><span class="lbl">Quick links</span>
                    <div class="ws-linkrow">
                        <a class="btn sm ghost" href="{{ url('/app/timesheets') }}">Timesheets</a>
                        <a class="btn sm ghost" href="{{ url('/app/tasks') }}">Tasks</a>
                        <a class="btn sm ghost" href="{{ url('/app/settings') }}">Settings</a>
                        <a class="btn sm ghost" href="{{ url('/app/download') }}">Get the app</a>
                    </div>
                </div>
            </div>
        </div>
    @endif

    @if ($roster)
        <div class="panel">
            <div class="panel-head">
                <h3>Agents under you</h3>
                <span class="muted small">{{ count($roster) }} {{ count($roster) === 1 ? 'person' : 'people' }}
                    · {{ $periods[$ctx['period']] ?? '' }} · click for details</span>
            </div>
            <div class="agent-rows">
                @foreach ($roster as $member)
                    <button type="button" class="agent-link" data-user-id="{{ $member['id'] }}">
                        @if ($member['live'])<span class="dot live"></span>@endif
                        {{ $member['name'] }}
                        <span class="muted">· {{ $member['role']?->label() }} · {{ \App\Support\Format::hms($member['active_s']) }}
                            · {{ $member['activity_pct'] }}%</span>
                    </button>
                @endforeach
            </div>
        </div>
    @endif

    <div class="panel">
        <h3>Activity timeline</h3>
        <canvas class="dp-chart" height="240"
                data-type="timeline"
                data-points='@json($timeline['points'])'
                data-markers='@json($timeline['markers'])'
                data-start='{{ $timeline['start'] }}'
                data-end='{{ $timeline['end'] }}'></canvas>
        <p class="muted">Activity % over time. The <b>●</b> markers are screenshots — hover to
            see the running app/window and a preview, or click to open the full screenshot.</p>
    </div>

    <div class="two-col">
        <div class="panel">
            <h3>Active vs inactive — daily hours</h3>
            @if (array_sum($daily['active']) || array_sum($daily['inactive']))
                <canvas class="dp-chart" height="240" data-type="bars"
                        data-labels='@json($daily['labels'])'
                        data-series='@json($dailySeries)'></canvas>
                <p class="muted small">Hours tracked each day, split into <b>active</b> (genuine
                    mouse/keyboard input) and <b>inactive</b> time. Bars are labelled in hours.</p>
            @else
                <p class="muted">No tracked time in this period yet.</p>
            @endif
        </div>
        <div class="panel">
            <h3>Activity breakdown</h3>
            @if ($summary['active_s'] || $summary['inactive_s'])
                <canvas class="dp-chart" height="160" data-type="donut" data-unit="h"
                        data-values='@json($donutValues)'
                        data-labels='@json(['Active', 'Inactive'])'
                        data-colors='@json([$activeColor, $idleColor])'></canvas>
                <p class="muted small">Hours active vs inactive — overall activity was
                    <b>{{ $summary['activity_pct'] }}%</b>.</p>
            @else
                <p class="muted">No tracked time in this period yet.</p>
            @endif
            <ul class="plain mini-stats">
                <li>Active time <b>{{ \App\Support\Format::hms($summary['active_s']) }}</b></li>
                <li>Inactive time <b>{{ \App\Support\Format::hms($summary['inactive_s']) }}</b></li>
                <li>Idle breaks <b>{{ $stats['idle_count'] }} · {{ \App\Support\Format::hms($stats['idle_total_s']) }}</b></li>
                <li>Avg session <b>{{ \App\Support\Format::hms($stats['avg_session_s']) }}</b></li>
                <li>Longest session <b>{{ \App\Support\Format::hms($stats['longest_session_s']) }}</b></li>
            </ul>
        </div>
    </div>

    <div class="two-col">
        <div class="panel">
            <h3>Top applications</h3>
            @if ($apps['labels'])
                <canvas class="dp-chart" height="240" data-type="bars"
                        data-labels='@json($apps['labels'])'
                        data-series='@json($appSeries)'></canvas>
                <p class="muted small">Most-used applications by focused time (minutes). Hover the
                    timeline above for moment-by-moment detail.</p>
            @else
                <p class="muted">No application data yet. Track a session with the desktop agent.</p>
            @endif
        </div>
        <div class="panel">
            <h3>Recent screenshots</h3>
            @if (count($recentShots))
                <div class="shot-grid sm">
                    @foreach ($recentShots as $shot)
                        <a href="{{ url('/uploads/' . $shot->file_path) }}" target="_blank">
                            <img src="{{ url('/uploads/' . $shot->file_path) }}" alt="screenshot" loading="lazy">
                        </a>
                    @endforeach
                </div>
            @else
                <p class="muted">No screenshots yet.</p>
            @endif
        </div>
    </div>

    @if ($roster)
        <div id="user-modal" class="modal-backdrop" hidden data-base="{{ url('/app/agent/') }}">
            <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="um-title">
                <div class="modal-head"><h3 id="um-title">Agent</h3>
                    <button type="button" class="modal-close" id="um-close" aria-label="Close">&times;</button></div>
                <div class="modal-body" id="um-body"></div>
            </div>
        </div>
    @endif

@endsection
