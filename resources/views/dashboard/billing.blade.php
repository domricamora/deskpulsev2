@extends('layouts.app')

{{--
    Billing. Ported from server/templates/dashboard/billing.php.

    Every figure here is a CLIENT CHARGE (bill_rate). Labor cost (pay_rate)
    never appears on this page — a client portal holds `billing` and not
    `view_rates`, and the difference between the two is the agency's margin.

    The read-only notice is shown to everyone but a platform operator, because
    the billing arrangement is set centrally.
--}}

@section('content')

    @unless ($isSuper)
        <div class="tutorial"><b>Read-only.</b> Customer billing is set by your DeskPulse platform
            administrator (super&nbsp;admin). You can view and export it here, but the billing
            arrangement is managed centrally and can&rsquo;t be changed from this account.</div>
    @endunless

    <x-period-switch :ctx="$period" :periods="$periods" path="/app/billing">
        <a class="ghost right"
            href="{{ url('/app/billing.csv') }}?{{ \App\Support\Period::queryString($period) }}">Export CSV</a>
    </x-period-switch>

    <div class="stat-row">
        <div class="stat"><span class="lbl">Total billable</span><b>{{ \App\Support\Format::money($total) }}</b></div>
        <div class="stat"><span class="lbl">Agents billed</span><b>{{ count($byAgent) }}</b></div>
        <div class="stat"><span class="lbl">Customers</span><b>{{ count($byClient) }}</b></div>
    </div>

    <div class="two-col">
        <div class="panel">
            <h3>Billing per agent</h3>
            <p class="muted">Hourly agents: active hours × rate. Monthly agents: flat service charge (prorated).</p>
            <table class="data">
                <thead><tr><th>Agent</th><th>Type</th><th>Active</th><th>Rate</th><th>Amount</th></tr></thead>
                <tbody>
                    @forelse ($byAgent as $agent)
                        <tr>
                            <td>{{ $agent['name'] }}</td>
                            <td><span class="tag">{{ $agent['bill_type'] }}</span></td>
                            <td>{{ \App\Support\Format::hms($agent['secs']) }}</td>
                            <td>{{ \App\Support\Format::money($agent['rate'], $agent['currency']) }}<small
                                    class="muted">/{{ $agent['bill_type'] === 'monthly' ? 'mo' : 'hr' }}</small></td>
                            <td><b>{{ \App\Support\Format::money($agent['amount'], $agent['currency']) }}</b></td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="muted">Nothing billable in this period.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="panel">
            <h3>Billing per customer</h3>
            <table class="data">
                <thead><tr><th>Customer</th><th>Active</th><th>Amount</th></tr></thead>
                <tbody>
                    @forelse ($byClient as $client)
                        <tr>
                            <td>{{ $client['name'] }}</td>
                            <td>{{ \App\Support\Format::hms($client['secs']) }}</td>
                            <td><b>{{ \App\Support\Format::money($client['amount']) }}</b></td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="muted">No customer billing yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if ($contractBilling)
        <div class="panel">
            <h3>Billing by contract</h3>
            <p class="muted">The figures above, split across your engagements in proportion to how much
                of this period each one covers — not a second calculation.</p>
            <table class="data">
                <thead><tr><th>Contract</th><th>Term</th><th>Status</th><th>Active</th><th>Amount</th></tr></thead>
                <tbody>
                    @foreach ($contractBilling as $contract)
                        <tr>
                            <td>{{ $contract['title'] }}</td>
                            <td class="muted small">{{ $contract['start_date'] ?: '—' }} – {{ $contract['end_date'] ?: '—' }}</td>
                            <td><span class="status {{ $contract['status'] === 'ended' ? 'rejected' : 'approved' }}">{{ $contract['status'] }}</span></td>
                            <td>{{ \App\Support\Format::hms($contract['secs']) }}</td>
                            <td><b>{{ \App\Support\Format::money($contract['amount'], $contract['currency']) }}</b></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

@endsection
