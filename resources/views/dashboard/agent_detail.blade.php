@extends('layouts.app')

{{--
    One agent, in full. Ported from server/templates/dashboard/agent_detail.php.

    Rates and screenshots are gated separately — a role can hold view_agents
    without either. The rates panel says so rather than disappearing, because a
    manager who cannot see a figure should know it exists and is withheld, not
    wonder whether it was never set.
--}}

@php
    $currency = $agent->currency ?: 'USD';
    $donutLabels = ['Active', 'Inactive'];
    $donutColors = ['#3b82f6', '#fbbf24'];
    $dailyHasData = array_sum($daily['active']) || array_sum($daily['inactive']);
@endphp

@section('content')

    <p><a class="lnk" href="{{ url('/app/agents') }}">&larr; Back to Agents</a></p>

    <div class="detail-head">
        <div>
            <h2>@if ($stats['live'])<span class="dot live"></span> @endif{{ $agent->name }}</h2>
            <p class="muted">{{ $agent->role?->label() }}@if ($agent->job_title) · {{ $agent->job_title }}@endif
                @if ($stats['live']) · <span class="pill on">tracking now</span>@endif</p>
        </div>
        <x-period-switch :ctx="$period" :periods="$periods" :path="'/app/agents/' . $agent->id" />
    </div>

    <div class="stat-row">
        <div class="stat"><span class="lbl">Active time</span><b>{{ \App\Support\Format::hms($summary['active_s']) }}</b></div>
        <div class="stat"><span class="lbl">Inactive time</span><b>{{ \App\Support\Format::hms($summary['inactive_s']) }}</b></div>
        <div class="stat"><span class="lbl">Activity</span><b>{{ $summary['activity_pct'] }}%</b></div>
        <div class="stat"><span class="lbl">Sessions</span><b>{{ $summary['count'] }}</b></div>
        <div class="stat"><span class="lbl">Avg session</span><b>{{ \App\Support\Format::hms($stats['avg_session_s']) }}</b>
            <span class="sub">longest {{ \App\Support\Format::hms($stats['longest_session_s']) }}</span></div>
        <div class="stat"><span class="lbl">Idle time</span><b>{{ \App\Support\Format::hms($stats['idle_total_s']) }}</b>
            <span class="sub">{{ $stats['idle_count'] }} idle break{{ $stats['idle_count'] == 1 ? '' : 's' }}</span></div>
        <div class="stat"><span class="lbl">Screenshots</span><b>{{ $stats['screenshots'] }}</b></div>
    </div>

    <div class="two-col">
        <div class="panel">
            <h3>Profile</h3>
            <dl class="kv">
                <dt>Email</dt><dd>{{ $agent->email }}</dd>
                <dt>Phone</dt><dd>{{ $agent->phone ?: '—' }}</dd>
                <dt>Job title</dt><dd>{{ $agent->job_title ?: '—' }}</dd>
                <dt>Teams</dt><dd>{{ $teams->isNotEmpty() ? $teams->implode(', ') : '—' }}</dd>
                <dt>Assigned clients</dt><dd>{{ $assignedClients->isNotEmpty() ? $assignedClients->implode(', ') : '—' }}</dd>
                <dt>Total tracked</dt><dd>{{ \App\Support\Format::hms($stats['total_active_s']) }} (all time)</dd>
            </dl>
        </div>
        <div class="panel">
            <h3>Rates @unless ($canSeeRates)<span class="muted small">— hidden for your role</span>@endunless</h3>
            @if ($canSeeRates)
                <dl class="kv">
                    <dt>Pay rate <span class="muted small">(internal cost)</span></dt>
                    <dd>{{ \App\Support\Format::money($agent->pay_rate, $currency) }} / {{ $agent->pay_type ?: 'hourly' }}</dd>
                    <dt>Bill rate <span class="muted small">(client charge)</span></dt>
                    <dd>{{ \App\Support\Format::money($agent->bill_rate, $currency) }} / {{ $agent->bill_type ?: 'hourly' }}</dd>
                    <dt>Currency</dt><dd>{{ $currency }}</dd>
                </dl>
            @else
                <p class="muted">Pay and bill rates are visible only to roles with rate access.</p>
            @endif

            <h3 class="spaced-top">Devices</h3>
            @forelse ($devices as $device)
                @if ($loop->first)<ul class="plain">@endif
                    <li>{{ $device->name }}
                        <span class="muted small">
                            @if ($device->last_seen)
                                · last seen <x-time :at="$device->last_seen" fmt="full" />
                            @else
                                · never connected
                            @endif
                        </span></li>
                @if ($loop->last)</ul>@endif
            @empty
                <p class="muted">No devices registered.</p>
            @endforelse
        </div>
    </div>

    <div class="panel">
        <h3>Activity timeline</h3>
        <canvas class="dp-chart" height="220" data-type="timeline"
            data-points='@json($timeline['points'])'
            data-markers='@json($timeline['markers'])'
            data-start='{{ $timeline['start'] }}'
            data-end='{{ $timeline['end'] }}'></canvas>
        <p class="muted">Activity % over time; <b>●</b> markers are screenshots — hover to preview.</p>
    </div>

    <div class="two-col">
        <div class="panel">
            <h3>Active vs inactive — daily hours</h3>
            @if ($dailyHasData)
                @php
                    $dailySeries = [
                        ['name' => 'Active', 'data' => $daily['active'], 'color' => '#3b82f6'],
                        ['name' => 'Inactive', 'data' => $daily['inactive'], 'color' => '#475569'],
                    ];
                @endphp
                <canvas class="dp-chart" height="240" data-type="bars"
                    data-labels='@json($daily['labels'])'
                    data-series='@json($dailySeries)'></canvas>
            @else
                <p class="muted">No tracked time in this period.</p>
            @endif
        </div>
        <div class="panel">
            <h3>Performance</h3>
            @if ($summary['active_s'] || $summary['inactive_s'])
                @php
                    $donutValues = [
                        round($summary['active_s'] / 3600, 2),
                        round($summary['inactive_s'] / 3600, 2),
                    ];
                @endphp
                <canvas class="dp-chart" height="160" data-type="donut" data-unit="h"
                    data-values='@json($donutValues)'
                    data-labels='@json($donutLabels)'
                    data-colors='@json($donutColors)'></canvas>
                <p class="muted small">Overall activity was <b>{{ $summary['activity_pct'] }}%</b> this period.</p>
            @else
                <p class="muted">No tracked time in this period yet.</p>
            @endif
        </div>
    </div>

    <div class="panel">
        <h3>Top applications</h3>
        @if ($apps['labels'])
            @php $appMinutes = array_map(fn ($s) => round($s / 60, 1), $apps['seconds']); @endphp
            <canvas class="dp-chart" height="240" data-type="hbars" data-fmt="hm"
                data-labels='@json($apps['labels'])'
                data-values='@json($appMinutes)'></canvas>
        @else
            <p class="muted">No application data in this period.</p>
        @endif
    </div>

    <div class="panel">
        <h3>Tasks — status &amp; time spent</h3>
        <table class="data">
            <thead><tr><th>Task</th><th>Client</th><th>Status</th><th>Sessions</th><th>Time spent</th></tr></thead>
            <tbody>
                @forelse ($tasks as $task)
                    @php $spent = $taskTime[$task->id] ?? ['secs' => 0, 'cnt' => 0]; @endphp
                    <tr>
                        <td>{{ $task->title }}</td>
                        <td>{{ $task->client?->name ?? '—' }}</td>
                        <td><span class="status {{ $task->status === 'done' ? 'approved' : 'pending' }}">{{ $task->status }}</span></td>
                        <td>{{ $spent['cnt'] }}</td>
                        <td><b>{{ \App\Support\Format::hms($spent['secs']) }}</b></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="muted">No tasks.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($canSeeScreenshots)
        <div class="panel">
            <h3>Recent screenshots</h3>
            @if ($screenshots->isNotEmpty())
                <div class="shot-grid">
                    @foreach ($screenshots as $shot)
                        <a href="{{ route('screenshots.image', $shot->id) }}" target="_blank">
                            <img src="{{ route('screenshots.image', $shot->id) }}" alt="screenshot" loading="lazy">
                            <span><x-time :at="$shot->ts" fmt="time" /></span>
                        </a>
                    @endforeach
                </div>
            @else
                <p class="muted">No screenshots in this period.</p>
            @endif
        </div>
    @endif

    <div class="panel">
        <h3>Activity times — recent sessions</h3>
        <table class="data">
            <thead><tr><th>Started</th><th>Ended</th><th>Client</th><th>Active</th><th>Inactive</th><th>Source</th></tr></thead>
            <tbody>
                @forelse ($recentSessions as $session)
                    <tr>
                        <td><x-time :at="$session->getRawOriginal('started_at')" fmt="full" /></td>
                        <td>
                            @if ($session->ended_at)
                                <x-time :at="$session->getRawOriginal('ended_at')" fmt="time" />
                            @else
                                <span class="pill on">live</span>
                            @endif
                        </td>
                        <td>{{ $session->client_name ?: '—' }}</td>
                        <td>{{ \App\Support\Format::hms($session->active_s) }}</td>
                        <td>{{ \App\Support\Format::hms($session->inactive_s) }}</td>
                        <td><span class="tag">{{ $session->source }}</span></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="muted">No sessions in this period.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

@endsection
