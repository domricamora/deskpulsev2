@extends('layouts.app')

{{--
    Session detail. Ported from server/templates/dashboard/session.php.

    Clicking a bar on the activity chart opens the screenshot captured nearest
    that moment — the two are on different timers, so "nearest" is the only
    honest pairing. Screenshots go through the authorized route now (decision
    D4), so the hrefs carry ids rather than file paths.
--}}

@php
    $shotTimes = $screenshots->map(fn ($shot) => strtotime($shot->ts . ' UTC'))->all();

    $labels = [];
    $values = [];
    $hrefs = [];

    foreach ($samples as $sample) {
        $labels[] = gmdate('H:i', strtotime($sample->ts . ' UTC'));
        $values[] = (int) $sample->activity_pct;

        $at = strtotime($sample->ts . ' UTC');
        $nearest = null;
        $smallest = PHP_INT_MAX;

        foreach ($shotTimes as $index => $shotAt) {
            if (abs($shotAt - $at) < $smallest) {
                $smallest = abs($shotAt - $at);
                $nearest = $screenshots[$index];
            }
        }

        $hrefs[] = $nearest ? route('screenshots.image', $nearest->id) : '';
    }
@endphp

@section('content')

    <p><a class="lnk" href="{{ url('/app/timesheets') }}">← Timesheets</a></p>

    <div class="stat-row">
        <div class="stat"><span class="lbl">Worker</span><b>{{ $owner }}</b></div>
        <div class="stat"><span class="lbl">Client</span><b>{{ $client ?? '—' }}</b></div>
        <div class="stat"><span class="lbl">Task</span><b>{{ $task ?? '—' }}</b></div>
        <div class="stat"><span class="lbl">Started</span><b><x-time :at="$session->getRawOriginal('started_at')" fmt="full" /></b></div>
        <div class="stat"><span class="lbl">Active</span><b>{{ \App\Support\Format::hms($session->active_s) }}</b></div>
        <div class="stat"><span class="lbl">Inactive</span><b>{{ \App\Support\Format::hms($session->inactive_s) }}</b></div>
        <div class="stat"><span class="lbl">Activity</span><b>{{ $session->activityPercent() }}%</b></div>
    </div>

    <div class="panel">
        <h3>Activity over time</h3>
        @if (count($values))
            <canvas class="dp-chart" height="200" data-type="bars"
                data-labels='@json($labels)'
                data-values='@json($values)' data-unit="%"
                data-hrefs='@json($hrefs)' data-href-target="blank"></canvas>
            <p class="muted">Tip: click a bar on the graph to open the nearest screenshot.</p>
        @else
            <p class="muted">No activity samples recorded.</p>
        @endif
    </div>

    <div class="two-col">
        <div class="panel">
            <h3>Windows — summary</h3>
            <table class="data">
                <thead><tr><th>App</th><th>Window</th><th>Focus</th></tr></thead>
                <tbody>
                    @forelse ($windowsAgg as $window)
                        <tr>
                            <td>{{ $window->app_name }}</td>
                            <td class="trunc">{{ $window->window_title }}</td>
                            <td>{{ \App\Support\Format::hms($window->secs) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="muted">No window data.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="panel">
            <h3>Running programs</h3>
            <ul class="chips">
                @forelse ($processes as $process)
                    <li>{{ $process }}</li>
                @empty
                    <li class="muted">None recorded.</li>
                @endforelse
            </ul>
            <h3>Idle periods (≥ threshold)</h3>
            <ul class="plain">
                @forelse ($idlePeriods as $idle)
                    <li><x-time :at="$idle->start_ts" fmt="time" />–<x-time :at="$idle->end_ts" fmt="time" />
                        ({{ \App\Support\Format::hms($idle->duration_s) }})</li>
                @empty
                    <li class="muted">No idle periods.</li>
                @endforelse
            </ul>
        </div>
    </div>

    <div class="panel">
        <h3>Windows — detail timeline</h3>
        <table class="data">
            <thead><tr><th>Time</th><th>App</th><th>Window title</th></tr></thead>
            <tbody>
                @forelse ($windowTimeline as $window)
                    <tr>
                        <td><x-time :at="$window->ts" fmt="sec" /></td>
                        <td>{{ $window->app_name }}</td>
                        <td class="trunc">{{ $window->window_title }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="muted">No timeline.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="panel">
        <h3>Screenshots</h3>
        @if ($screenshots->isNotEmpty())
            <div class="shot-grid">
                @foreach ($screenshots as $shot)
                    <a href="{{ route('screenshots.image', $shot->id) }}" target="_blank">
                        <img src="{{ route('screenshots.image', $shot->id) }}" loading="lazy" alt="screenshot">
                        <span><x-time :at="$shot->ts" fmt="time" /></span>
                    </a>
                @endforeach
            </div>
        @else
            <p class="muted">No screenshots for this session.</p>
        @endif
    </div>

@endsection
