@extends('layouts.app')

{{--
    Payroll import. Ported from server/templates/dashboard/import.php.

    The preview is the real ingest run with commit = false, so what it promises
    is what the confirm does. The inline confirm() is data-confirm here and the
    two inline margins are classes, per decision D13; the prompt wording is the
    legacy's, unchanged.
--}}

@section('content')

    <div class="panel">
        <h3>Import payroll from Excel <span class="tag">admin · HR</span></h3>
        <p class="muted">Upload a payroll spreadsheet (<code>.xlsx</code>) to load a whole team
            into <b>this organization</b> at once. It creates a <b>client</b> for each distinct
            Client, an <b>employee</b> for each VT&nbsp;ID (with the sheet's hourly pay rate), and
            one approved <b>time entry</b> per employee, per day, per client. Re-importing the same
            file updates the same records instead of duplicating them — so it's safe to run again
            after corrections.</p>
        <form method="post" action="{{ url('/app/import') }}" class="row-form" enctype="multipart/form-data">
            @csrf
            <input type="hidden" name="action" value="preview">
            <label class="grow">Payroll file <input type="file" name="file" accept=".xlsx" required></label>
            <button class="btn" type="submit">Preview import</button>
        </form>
    </div>

    <x-import-guide :spec="$specs['payroll']" spec-key="payroll" :open="! $preview" />

    @if ($preview)
        @php $summary = $preview['summary']; @endphp

        <div class="panel">
            <h3>Preview <span class="muted normal">— nothing has been saved yet</span></h3>
            <p class="muted">Read from sheet <b>{{ $preview['sheet'] }}</b> · {{ $preview['total'] }} valid data
                row(s)@if ($preview['skipped']['count'] > 0), {{ $preview['skipped']['count'] }} skipped @endif.</p>

            <div class="stat-row">
                <div class="stat"><span class="lbl">Clients</span><b>{{ $summary['clients_new'] }} new</b>
                    <span class="sub">{{ $summary['clients_existing'] }} already exist</span></div>
                <div class="stat"><span class="lbl">Employees</span><b>{{ $summary['emps_new'] }} new</b>
                    <span class="sub">{{ $summary['emps_updated'] }} updated</span></div>
                <div class="stat"><span class="lbl">Time entries</span><b>{{ $summary['sessions_new'] }} added</b>
                    <span class="sub">{{ $summary['sessions_updated'] }} updated</span></div>
            </div>

            @if ($summary['rate_changes'])
                <p class="muted"><b>Pay-rate changes</b> (the sheet is authoritative):
                    @foreach (array_slice($summary['rate_changes'], 0, 12) as $change)
                        <br>{{ $change['name'] }}: {{ \App\Support\Format::money($change['from'], 'USD') }}
                        → {{ \App\Support\Format::money($change['to'], 'USD') }}
                    @endforeach
                    @if (count($summary['rate_changes']) > 12)
                        <br>… and {{ count($summary['rate_changes']) - 12 }} more
                    @endif
                </p>
            @endif

            @if ($preview['skipped']['reasons'])
                <p class="muted"><b>Skipped rows:</b>
                    @foreach ($preview['skipped']['reasons'] as $why => $count)
                        <span class="tag">{{ $count }} · {{ $why }}</span>
                    @endforeach
                </p>
            @endif

            @if ($summary['errors'])
                <p class="danger"><b>Row errors:</b></p>
                <ul class="plain">
                    @foreach (array_slice($summary['errors'], 0, 10) as $error)
                        <li class="danger">{{ $error }}</li>
                    @endforeach
                </ul>
            @endif

            <h4>First rows</h4>
            <table class="data">
                <thead>
                    <tr><th>VT ID</th><th>Employee</th><th>Client</th><th>Date</th><th>Hours</th><th>Rate</th></tr>
                </thead>
                <tbody>
                    @foreach ($preview['sample'] as $row)
                        <tr>
                            <td><code>{{ $row['vt_id'] }}</code></td>
                            <td>{{ $row['name'] }}</td>
                            <td>{{ $row['client_name'] !== '' ? $row['client_name'] : '—' }}</td>
                            <td>{{ $row['date'] }}</td>
                            <td>{{ \App\Support\Format::hms($row['active_s']) }}</td>
                            <td>{{ \App\Support\Format::money($row['pay_rate'], 'USD') }}<small class="muted">/hr</small></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <form method="post" action="{{ url('/app/import') }}" class="confirm-row"
                data-confirm="Import this data into your organization now?">
                @csrf
                <input type="hidden" name="action" value="commit">
                <input type="hidden" name="token" value="{{ $preview['token'] }}">
                <button class="btn" type="submit">Confirm import</button>
                <a class="lnk spaced" href="{{ url('/app/import') }}">Cancel</a>
            </form>
        </div>
    @endif

@endsection
