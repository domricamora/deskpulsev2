@extends('layouts.app')

{{--
    Generating and emailing a period's payslips.
    Ported from server/templates/dashboard/payslips.php.

    Re-sending is opt-in. "Everybody got their payslip four times" is both
    alarming and impossible to take back, so the checkbox is the only way past
    payslips.emailed_at.
--}}

@php
    $rows = $run['rows'];
    $emailed = $existing->filter(fn ($slip) => $slip->emailed_at)->count();
@endphp

@section('content')

    <x-period-switch :ctx="$period" :periods="$periods" path="/app/payslips" />

    <div class="stat-row">
        <div class="stat"><div class="lbl">Employees in period</div><div>{{ count($rows) }}</div>
            <div class="sub">{{ $period['label'] }}</div></div>
        <div class="stat"><div class="lbl">Payslips generated</div><div>{{ $existing->count() }}</div></div>
        <div class="stat"><div class="lbl">Emailed</div><div>{{ $emailed }}</div></div>
        <div class="stat"><div class="lbl">Total net</div>
            <div>{{ \App\Support\Format::money($run['totals']['net'] ?? 0, $run['currency']) }}</div></div>
    </div>

    <div class="panel">
        <h3>Payslips for {{ $period['label'] }}</h3>
        <p class="muted">Generating rebuilds the PDF from the current pay run, so a payslip always
            matches what the Payroll page shows. Emailing attaches it and records the send —
            running this again will not re-send unless you say so.</p>

        <form method="post" action="{{ url('/app/payslips') }}" class="row-form">
            @csrf
            <input type="hidden" name="period" value="{{ $period['period'] }}">
            <input type="hidden" name="date" value="{{ $period['anchor'] }}">
            <input type="hidden" name="from" value="{{ $period['from'] ?? '' }}">
            <input type="hidden" name="to" value="{{ $period['to'] ?? '' }}">
            <label>Employee
                <select name="user_id">
                    <option value="0">Everyone</option>
                    @foreach ($rows as $row)
                        <option value="{{ (int) $row['user_id'] }}">{{ $row['name'] }}</option>
                    @endforeach
                </select>
            </label>
            <label class="check"><input type="checkbox" name="resend"> Re-send to people already emailed</label>
            <button class="btn ghost" type="submit" name="action" value="generate">Generate only</button>
            <button class="btn" type="submit" name="action" value="generate_email">Generate &amp; email</button>
        </form>
    </div>

    <div class="panel">
        <table class="data">
            <thead>
                <tr><th>Employee</th><th>Hours</th><th>Gross</th><th>Deductions</th><th>Net</th>
                    <th>Payslip</th><th>Emailed</th><th></th></tr>
            </thead>
            <tbody>
                @forelse ($rows as $row)
                    @php $slip = $existing->get($row['user_id']); @endphp
                    <tr>
                        <td>{{ $row['name'] }}<br><small class="muted">{{ $row['role']?->label() }}</small></td>
                        <td>{{ number_format($row['hours'], 2) }} h</td>
                        <td>{{ \App\Support\Format::money($row['gross'], $row['currency']) }}</td>
                        <td @class(['negative' => $row['deductions'] > 0])>
                            {{ $row['deductions'] > 0 ? '-' . \App\Support\Format::money($row['deductions'], $row['currency']) : '—' }}</td>
                        <td><b>{{ \App\Support\Format::money($row['net'], $row['currency']) }}</b></td>
                        <td>
                            <span class="status {{ $slip ? 'approved' : 'pending' }}">{{ $slip ? 'generated' : 'not yet' }}</span>
                        </td>
                        <td class="small muted">
                            @if ($slip?->emailed_at)
                                <x-time :at="$slip->getRawOriginal('emailed_at')" fmt="full" />
                            @else
                                —
                            @endif
                        </td>
                        <td>
                            <a class="lnk" target="_blank"
                                href="{{ url('/app/payslip.pdf') }}?user_id={{ (int) $row['user_id'] }}&{{ \App\Support\Period::queryString($period) }}">PDF</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="muted">Nobody to pay in this period.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

@endsection
