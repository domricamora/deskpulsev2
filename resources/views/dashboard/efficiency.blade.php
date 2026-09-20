@extends('layouts.app')

{{--
    Efficiency report. Ported from server/templates/dashboard/efficiency.php.

    The per-person donut is the same charts.js the overview uses, in mini form.
    Colours match overview.php exactly — blue active, amber inactive — because
    the two pages are read side by side.

    "no data" and "no tasks" are not zeroes. Someone with no tracked time and no
    tasks is not being measured; showing them as 0% would rank an IT admin last
    for doing their job.
--}}

@php
    // Hoisted rather than written inline: Blade's @json() directive cannot
    // parse an array literal containing quoted strings.
    $donutLabels = ['Active', 'Inactive'];
    $donutColors = ['#3b82f6', '#fbbf24'];
    $scoreClass = fn (int $pct) => $pct >= 80 ? 'approved' : ($pct >= 50 ? 'pending' : 'rejected');
@endphp

@section('content')

    <x-period-switch :ctx="$period" :periods="$periods" path="/app/reports/efficiency">
        <a class="ghost right"
            href="{{ url('/app/reports/efficiency.csv') }}?{{ \App\Support\Period::queryString($period) }}">Export CSV</a>
    </x-period-switch>

    <div class="stat-row">
        <div class="stat"><span class="lbl">People reported</span><b>{{ count($rows) }}</b></div>
        <div class="stat"><span class="lbl">Active time</span><b>{{ \App\Support\Format::hms($totals['active_s']) }}</b></div>
        <div class="stat"><span class="lbl">Inactive time</span><b>{{ \App\Support\Format::hms($totals['inactive_s']) }}</b></div>
        <div class="stat"><span class="lbl">Avg effectiveness</span><b>{{ $totals['avg_effectiveness'] }}%</b></div>
    </div>

    <div class="panel">
        <h3>Employee efficiency &amp; effectiveness</h3>
        <p class="muted">Ranked by effectiveness — a blend of activity % and task completion % (activity %
            alone when someone has no tasks assigned). Use this alongside the Team page's per-person
            activity donuts for a fuller performance picture.</p>

        @if (! $rows)
            <div class="empty-state">No one to report on for this period yet.</div>
        @else
            <table class="data">
                <thead>
                    <tr>
                        <th>Name</th><th>Role</th><th>Activity</th>
                        <th>Active</th><th>Inactive</th><th>Sessions</th>
                        <th>Tasks done</th><th>Effectiveness</th>
                        @if ($canSeeRates)<th>Labor cost</th>@endif
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr>
                            <td><b>{{ $row['name'] }}</b></td>
                            <td><span class="tag">{{ $row['role']?->label() }}</span></td>
                            <td>
                                @if ($row['active_s'] + $row['inactive_s'] > 0)
                                    @php
                                        $donutValues = [
                                            round($row['active_s'] / 3600, 2),
                                            round($row['inactive_s'] / 3600, 2),
                                        ];
                                    @endphp
                                    <canvas class="dp-chart" data-type="donut" data-mini height="46"
                                        data-values='@json($donutValues)'
                                        data-labels='@json($donutLabels)'
                                        data-colors='@json($donutColors)'
                                        title="{{ $row['activity_pct'] }}% active"></canvas>
                                @else
                                    <span class="muted small">—</span>
                                @endif
                            </td>
                            <td>{{ \App\Support\Format::hms($row['active_s']) }}</td>
                            <td>{{ \App\Support\Format::hms($row['inactive_s']) }}</td>
                            <td>{{ $row['sessions'] }}</td>
                            <td>
                                @if ($row['tasks_total'])
                                    {{ $row['tasks_done'] }} / {{ $row['tasks_total'] }} ({{ $row['task_completion_pct'] }}%)
                                @else
                                    <span class="muted">no tasks</span>
                                @endif
                            </td>
                            <td>
                                @if ($row['effectiveness_pct'] === null)
                                    <span class="muted small">no data</span>
                                @else
                                    <span class="status {{ $scoreClass($row['effectiveness_pct']) }}">{{ $row['effectiveness_pct'] }}%</span>
                                @endif
                            </td>
                            @if ($canSeeRates)
                                <td>{{ $row['cost'] !== null ? \App\Support\Format::money($row['cost'], $row['currency']) : '—' }}</td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

@endsection
