@extends('layouts.public')

{{--
    The public share page. Ported from server/templates/share/summary.php.

    Stats and graphs only. There is deliberately no screenshot anywhere on this
    page at any scope, and no pay rate, labor cost or salary figure reaches the
    view at all — a share URL is a capability token, and whoever it is
    forwarded to can read everything on it.
--}}

@php
    $periods = ['day' => 'Day', 'week' => 'Week', 'month' => 'Month'];

    $dailySeries = [
        ['name' => 'Active', 'data' => $daily['active'], 'color' => '#3b82f6'],
        ['name' => 'Inactive', 'data' => $daily['inactive'], 'color' => '#475569'],
    ];

    $appMinutes = array_map(fn ($seconds) => round($seconds / 60, 1), $apps['seconds']);
@endphp

@section('content')

    <div class="share-wrap">
        <div class="share-head">
            <div>
                <h2>{{ $link->label ?: 'Activity summary' }}</h2>
                <p class="muted">{{ $org->name }} · read-only summary</p>
            </div>
            <div class="period-switch">
                @foreach ($periods as $key => $label)
                    <a class="{{ $period === $key ? 'on' : '' }}"
                        href="{{ url('/share/' . $link->token) }}?period={{ $key }}">{{ $label }}</a>
                @endforeach
            </div>
        </div>

        <div class="share-filters">
            <form method="get" action="{{ url('/share/' . $link->token) }}" class="row-form">
                <label>Week <input type="week" name="week" value="{{ request('week') }}"></label>
                <button class="btn" type="submit">View week</button>
            </form>
            <form method="get" action="{{ url('/share/' . $link->token) }}" class="row-form">
                <label>From <input type="date" name="from" value="{{ request('from') }}"></label>
                <label>To <input type="date" name="to" value="{{ request('to') }}"></label>
                <button class="btn" type="submit">View range</button>
            </form>
            <span class="muted">Showing: <b>{{ $label }}</b></span>
        </div>

        <div class="stat-row">
            <div class="stat"><span class="lbl">Active time</span><b>{{ \App\Support\Format::hms($summary['active_s']) }}</b></div>
            <div class="stat"><span class="lbl">Inactive time</span><b>{{ \App\Support\Format::hms($summary['inactive_s']) }}</b></div>
            <div class="stat"><span class="lbl">Activity</span><b>{{ $summary['activity_pct'] }}%</b></div>
            <div class="stat"><span class="lbl">Sessions</span><b>{{ $summary['count'] }}</b></div>
        </div>

        <div class="panel">
            <h3>Active vs inactive hours</h3>
            <canvas class="dp-chart" height="240" data-type="bars"
                data-labels='@json($daily['labels'])'
                data-series='@json($dailySeries)'></canvas>
        </div>

        <div class="panel">
            <h3>Top applications</h3>
            @if ($apps['labels'])
                <canvas class="dp-chart" height="220" data-type="hbars"
                    data-labels='@json($apps['labels'])'
                    data-values='@json($appMinutes)'
                    data-unit="min"></canvas>
            @else
                <p class="muted">No application data.</p>
            @endif
        </div>

        <div class="panel">
            <h3>Time spent per task</h3>
            @if ($taskTimes->isNotEmpty())
                <table class="data">
                    <thead><tr><th>Task</th><th>Status</th><th>Time spent</th></tr></thead>
                    <tbody>
                        @foreach ($taskTimes as $task)
                            <tr>
                                <td>{{ $task->title }}</td>
                                <td><span class="status {{ $task->status === 'done' ? 'approved' : 'pending' }}">{{ $task->status }}</span></td>
                                <td><b>{{ \App\Support\Format::hms($task->secs) }}</b></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <p class="muted">No task time recorded in this period.</p>
            @endif
        </div>
    </div>

@endsection
