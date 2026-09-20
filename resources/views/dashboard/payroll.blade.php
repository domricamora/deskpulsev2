@extends('layouts.app')

{{--
    Pay breakdown. Ported from server/templates/dashboard/payroll.php.

    Every figure comes from PayRun, the single source of truth for money. The
    "OT awaiting HR" column is the exception and is not pay at all — it is what
    is being held back, so an admin can see why the total looks light.

    The deduction cell's inline style="color:var(--bad)" is the .negative class
    now (decision D13, same as the rest).
--}}

@section('content')

    <x-period-switch :ctx="$period" :periods="$periods" path="/app/payroll">
        @if ($canAdjust)
            <a class="ghost right"
                href="{{ url('/app/adjustments') }}?{{ \App\Support\Period::queryString($period) }}">Pay adjustments →</a>
        @endif
    </x-period-switch>

    <div class="stat-row">
        <div class="stat"><span class="lbl">Base labor cost</span><b>{{ \App\Support\Format::money($totalCost, $currency) }}</b>
            <span class="sub">{{ $period['label'] }}</span></div>
        @if ($totalExtras > 0 || $totalDeductions > 0)
            <div class="stat"><span class="lbl">Extras</span><b>+{{ \App\Support\Format::money($totalExtras, $currency) }}</b>
                <span class="sub">bonuses, commissions, paid leave</span></div>
            <div class="stat"><span class="lbl">Deductions</span><b>-{{ \App\Support\Format::money($totalDeductions, $currency) }}</b></div>
        @endif
        <div class="stat"><span class="lbl">Total net pay</span><b>{{ \App\Support\Format::money($totalNet, $currency) }}</b></div>
        <div class="stat"><span class="lbl">Members</span><b>{{ count($rows) }}</b></div>
        <div class="stat"><span class="lbl">Credited hours</span><b>{{ \App\Support\Format::hms($totalActiveSeconds) }}</b></div>
        @if ($totalOvertimePending > 0)
            <div class="stat alert"><span class="lbl">Overtime awaiting HR</span><b>{{ \App\Support\Format::hms($totalOvertimePending) }}</b>
                <span class="sub">not yet paid</span></div>
        @endif
    </div>

    <div class="panel">
        <h3>Salary &amp; pay breakdown</h3>
        <p class="muted">Per-member pay from tracked active time. Hourly: credited hours × rate.
            Monthly salaries are prorated across the pay period by calendar days, so the two halves of a
            semi-monthly month add back up to exactly one month's salary.
            Overtime is excluded until HR approves it on the <a href="{{ url('/app/overtime') }}">Overtime</a> page,
            and paid leave is only added for hourly staff (a salary already covers the day off).</p>

        <table class="data">
            <thead>
                <tr><th>Member</th><th>Employment</th><th>Pay type</th><th>Pay rate</th>
                    <th>Credited</th><th>Leave</th><th>OT awaiting HR</th><th>Base</th>
                    <th>Extras</th><th>Deductions</th><th>Net pay</th><th>Flags</th></tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    <tr>
                        <td><b>{{ $row['name'] }}</b><br><small class="muted">{{ $row['role']?->label() }}</small></td>
                        <td><span class="tag">{{ \App\Support\Employment::label($row['employment_type']) }}</span></td>
                        <td>{{ $row['pay_type'] }}</td>
                        <td data-sort="{{ number_format($row['pay_rate'], 2, '.', '') }}">
                            {{ \App\Support\Format::money($row['pay_rate'], $row['currency']) }}<small
                                class="muted">/{{ $row['pay_type'] === 'monthly' ? 'mo' : 'hr' }}</small></td>
                        <td data-sort="{{ $row['active_s'] }}">{{ \App\Support\Format::hms($row['active_s']) }}</td>
                        <td data-sort="{{ number_format($row['leave_days'], 4, '.', '') }}">
                            {{ $row['leave_days'] > 0 ? rtrim(rtrim(number_format($row['leave_days'], 2), '0'), '.') . ' d' : '—' }}</td>
                        <td data-sort="{{ $row['overtime_pending_s'] }}">
                            @if ($row['overtime_pending_s'] > 0)
                                <span class="muted">{{ \App\Support\Format::hms($row['overtime_pending_s']) }}</span>
                            @else
                                —
                            @endif
                        </td>
                        <td data-sort="{{ number_format($row['cost'], 2, '.', '') }}">{{ \App\Support\Format::money($row['cost'], $row['currency']) }}</td>
                        <td data-sort="{{ number_format($row['extras'], 2, '.', '') }}">
                            {{ $row['extras'] > 0 ? '+' . \App\Support\Format::money($row['extras'], $row['currency']) : '—' }}</td>
                        <td data-sort="{{ number_format($row['deductions'], 2, '.', '') }}"
                            @class(['negative' => $row['deductions'] > 0])>
                            {{ $row['deductions'] > 0 ? '-' . \App\Support\Format::money($row['deductions'], $row['currency']) : '—' }}</td>
                        <td data-sort="{{ number_format($row['net'], 2, '.', '') }}">
                            <b>{{ \App\Support\Format::money($row['net'], $row['currency']) }}</b></td>
                        <td class="small">
                            @forelse ($row['flags'] as $flag)
                                <span class="status pending">{{ $flag }}</span><br>
                            @empty
                                —
                            @endforelse
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="12" class="muted">No members to report.</td></tr>
                @endforelse
            </tbody>
            @if ($rows)
                <tfoot>
                    <tr>
                        <td colspan="4"><b>Totals</b></td>
                        <td><b>{{ \App\Support\Format::hms($totalActiveSeconds) }}</b></td>
                        <td></td>
                        <td>@if ($totalOvertimePending > 0)<span class="muted">{{ \App\Support\Format::hms($totalOvertimePending) }}</span>@endif</td>
                        <td><b>{{ \App\Support\Format::money($totalCost, $currency) }}</b></td>
                        <td>{{ $totalExtras > 0 ? '+' . \App\Support\Format::money($totalExtras, $currency) : '' }}</td>
                        <td>{{ $totalDeductions > 0 ? '-' . \App\Support\Format::money($totalDeductions, $currency) : '' }}</td>
                        <td><b>{{ \App\Support\Format::money($totalNet, $currency) }}</b></td>
                        <td></td>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>

@endsection
