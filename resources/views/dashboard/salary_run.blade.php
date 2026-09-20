@extends('layouts.app')

{{--
    Salary run. Ported from server/templates/dashboard/salary_run.php.

    Everybody in the period is listed, whether or not they make the batch, and
    the ones who do not are named at the top with a link to fix it. A payout
    file that silently omits people is how somebody goes unpaid for a month.
--}}

@php
    $rows = $run['rows'];
    $currency = $run['currency'];

    $payable = array_values(array_filter($rows, fn ($r) => $r['payable'] && $r['net'] > 0));
    $blocked = array_values(array_filter($rows, fn ($r) => ! $r['payable'] && ($r['net'] > 0 || $r['hours'] > 0)));
    $batchTotal = array_sum(array_column($payable, 'net'));

    $trim = fn ($n) => rtrim(rtrim(number_format((float) $n, 2), '0'), '.');
@endphp

@section('content')

    <x-period-switch :ctx="$period" :periods="$periods" path="/app/salary-run">
        <a class="btn sm right"
            href="{{ url('/app/salary-run.csv') }}?{{ \App\Support\Period::queryString($period) }}">Download Wise batch CSV</a>
    </x-period-switch>

    <div class="stat-row">
        <div class="stat"><div class="lbl">Ready to pay</div><div>{{ count($payable) }}</div>
            <div class="sub">of {{ count($rows) }} people</div></div>
        <div class="stat"><div class="lbl">Batch total</div><div>{{ \App\Support\Format::money($batchTotal, $currency) }}</div>
            <div class="sub">{{ $period['label'] }}</div></div>
        <div @class(['stat', 'alert' => $blocked])><div class="lbl">Missing payout details</div>
            <div>{{ count($blocked) }}</div>
            <div class="sub">{{ $blocked ? 'excluded from the export' : 'everyone is set up' }}</div></div>
        <div class="stat"><div class="lbl">Total hours</div>
            <div>{{ number_format($run['totals']['hours'] ?? 0, 2) }} h</div></div>
    </div>

    @if ($blocked)
        <div class="panel">
            <div class="tutorial"><b>These people are not in the export.</b>
                They have pay due but no usable Wise payout details:
                {{ implode(', ', array_slice(array_column($blocked, 'name'), 0, 12)) }}{{ count($blocked) > 12 ? ' …' : '' }}.
                <br><a class="lnk" href="{{ url('/app/wise') }}">Add their payout details →</a></div>
        </div>
    @endif

    <div class="panel">
        <h3>Salary run — {{ $period['label'] }}</h3>
        <p class="muted">Net pay is worked hours (or prorated salary) plus paid leave and extra earnings,
            minus deductions. The CSV matches the Wise batch-payment layout, with <b>Amount</b> filled in.</p>

        @if (! $rows)
            <div class="empty-state">No payable people in this period.</div>
        @else
            <table class="data">
                <thead>
                    <tr><th>Employee</th><th>Type</th><th>Hours</th><th>Leave</th><th>Base</th>
                        <th>Extras</th><th>Deductions</th><th>Net pay</th><th>Wise</th><th>Flags</th></tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        @php $extras = $row['earnings'] + $row['leave_pay']; @endphp
                        <tr>
                            <td>{{ $row['name'] }}<br><small class="muted">{{ $row['role']?->label() }}</small></td>
                            <td><span class="tag">{{ \App\Support\Employment::label($row['employment_type']) }}</span><br>
                                <small class="muted">{{ $row['pay_type'] }}</small></td>
                            <td data-sort="{{ number_format($row['hours'], 4, '.', '') }}">{{ number_format($row['hours'], 2) }} h</td>
                            <td data-sort="{{ number_format($row['leave_days'], 4, '.', '') }}">
                                {{ $row['leave_days'] > 0 ? $trim($row['leave_days']) . ' d' : '—' }}</td>
                            <td data-sort="{{ number_format($row['base'], 2, '.', '') }}">{{ \App\Support\Format::money($row['base'], $row['currency']) }}</td>
                            <td data-sort="{{ number_format($extras, 2, '.', '') }}">
                                {{ $extras > 0 ? '+' . \App\Support\Format::money($extras, $row['currency']) : '—' }}</td>
                            <td data-sort="{{ number_format($row['deductions'], 2, '.', '') }}"
                                @class(['negative' => $row['deductions'] > 0])>
                                {{ $row['deductions'] > 0 ? '-' . \App\Support\Format::money($row['deductions'], $row['currency']) : '—' }}</td>
                            <td data-sort="{{ number_format($row['net'], 2, '.', '') }}"><b>{{ \App\Support\Format::money($row['net'], $row['currency']) }}</b></td>
                            <td data-sort="{{ $row['payable'] ? '1' : '0' }}">
                                <span class="status {{ $row['payable'] ? 'approved' : 'rejected' }}">{{ $row['payable'] ? 'ready' : 'missing' }}</span></td>
                            <td class="small">
                                @forelse ($row['flags'] as $flag)
                                    <span class="status pending">{{ $flag }}</span><br>
                                @empty
                                    —
                                @endforelse
                            </td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr>
                        <th>Batch total (ready only)</th><th></th>
                        <th>{{ number_format($run['totals']['hours'] ?? 0, 2) }} h</th>
                        <th></th><th></th><th></th><th></th>
                        <th>{{ \App\Support\Format::money($batchTotal, $currency) }}</th><th></th><th></th>
                    </tr>
                </tfoot>
            </table>
        @endif
    </div>

@endsection
