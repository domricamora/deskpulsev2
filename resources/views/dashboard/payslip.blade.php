@extends('layouts.app')

{{--
    A person's own payslip. Ported from server/templates/dashboard/payslip.php.

    The daily figures are CREDITABLE hours, so overtime HR has not approved is
    absent from the pay — and said so explicitly in the note, because seeing
    hours you worked missing from your own payslip without explanation is
    exactly the sort of thing that starts a support ticket.
--}}

@section('content')

    <x-period-switch :ctx="$period" :periods="$periods" path="/app/payslip">
        <a class="btn sm right" target="_blank"
            href="{{ url('/app/payslip.pdf') }}?{{ \App\Support\Period::queryString($period) }}">Download PDF payslip</a>
    </x-period-switch>

    <div class="stat-row">
        <div class="stat"><span class="lbl">Estimated pay</span><b>{{ \App\Support\Format::money($totalPay, $currency) }}</b>
            <span class="sub">{{ $period['label'] }}</span></div>
        <div class="stat"><span class="lbl">Credited hours</span><b>{{ \App\Support\Format::hms($totalActive) }}</b></div>
        @if ($pendingOvertime > 0)
            <div class="stat"><span class="lbl">Overtime awaiting HR</span><b>{{ \App\Support\Format::hms($pendingOvertime) }}</b>
                <span class="sub">not yet paid</span></div>
        @endif
        <div class="stat"><span class="lbl">Pay rate</span><b>{{ \App\Support\Format::money($me->pay_rate, $currency) }}</b>
            <span class="sub">/{{ $me->pay_type === 'monthly' ? 'mo' : 'hr' }}@if ($me->pay_type === 'monthly')
                    · ≈{{ \App\Support\Format::money($hourly, $currency) }}/hr
                @endif</span></div>
    </div>

    <div class="panel">
        <h3>Payslip — {{ $me->name }}</h3>
        <p class="muted">Your pay per day = active hours × rate.
            @if ($me->pay_type === 'monthly')
                Your monthly salary is normalized to an hourly equivalent (÷ 173.33 h/mo) for the daily figure.
            @endif
            Active hours are genuine tracked time; this is an estimate, not a final statement.
            @if ($pendingOvertime > 0)
                <br><strong>Note:</strong> {{ \App\Support\Format::hms($pendingOvertime) }}
                of overtime is awaiting HR approval and is <em>not</em> included above; it will be credited once approved.
            @endif
            @if ($approvedOvertime > 0)
                <br>Includes {{ \App\Support\Format::hms($approvedOvertime) }} of HR-approved overtime.
            @endif
        </p>

        <table class="data">
            <thead><tr><th>Date</th><th>Hours worked</th><th>Rate</th><th>Pay</th></tr></thead>
            <tbody>
                @forelse ($rows as $row)
                    <tr>
                        <td><x-time :at="$row['date'] . ' 00:00:00'" fmt="date" /></td>
                        <td>{{ number_format($row['hours'], 2) }} h</td>
                        <td>{{ \App\Support\Format::money($hourly, $currency) }}<small class="muted">/hr</small></td>
                        <td><b>{{ \App\Support\Format::money($row['pay'], $currency) }}</b></td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="muted">No worked days in this period.</td></tr>
                @endforelse
            </tbody>
            @if ($rows)
                <tfoot>
                    <tr>
                        <th>Total</th>
                        <th>{{ number_format($totalActive / 3600, 2) }} h</th>
                        <th></th>
                        <th>{{ \App\Support\Format::money($totalPay, $currency) }}</th>
                    </tr>
                </tfoot>
            @endif
        </table>
    </div>

@endsection
