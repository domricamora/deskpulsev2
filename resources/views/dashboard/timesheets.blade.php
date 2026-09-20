@extends('layouts.app')

{{--
    Timesheets. Ported from server/templates/dashboard/timesheets.php.

    The multi-row "Add more" form and its local→UTC conversion on submit are
    already in resources/js/dashboard.js (ported in Phase 6), which hooks
    #adj-rows, #add-row, .remove-row and #manual-entry — so the ids and classes
    here are load-bearing, not decoration.

    The one inline style, on the button row, is the .adj-actions class now for
    the same reason as the rest: decision D13 rules out style-src 'unsafe-inline'.
--}}

@section('content')

    <x-period-switch :ctx="$period" :periods="$periods" path="/app/timesheets">
        <a class="ghost right"
            href="{{ url('/app/export.csv') }}?{{ \App\Support\Period::queryString($period) }}">Export CSV</a>
    </x-period-switch>

    @if ($canLog)
        <div class="panel">
            <h3>Add manual entries / adjustments</h3>
            <p class="muted">Forgot to track time? Enter start and end in <b>your local time</b>
                (stored in UTC). Use <b>Add more</b> to log several entries for a day at once.
                Entries you submit go to a manager for approval.</p>
            <form method="post" action="{{ url('/app/timesheets') }}" id="manual-entry">
                @csrf
                <div id="adj-rows">
                    <div class="adj-row row-form">
                        <input type="hidden" name="started_at_utc[]"><input type="hidden" name="ended_at_utc[]">
                        <label>Start <input type="datetime-local" name="started_at[]"></label>
                        <label>End <input type="datetime-local" name="ended_at[]"></label>
                        <label>Client / company
                            <select name="client_id[]">
                                <option value="">— none —</option>
                                @foreach ($clients as $client)
                                    <option value="{{ (int) $client->id }}">{{ $client->name }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="grow">Note <input type="text" name="note[]" placeholder="e.g. client call"></label>
                        <button type="button" class="lnk danger remove-row" title="Remove this row">✕</button>
                    </div>
                </div>
                <div class="row-form adj-actions">
                    <button type="button" class="btn ghost" id="add-row">+ Add more</button>
                    <button class="btn" type="submit">Submit all</button>
                </div>
            </form>
        </div>
    @endif

    <div class="panel">
        <h3>Sessions</h3>
        <table class="data">
            <thead>
                <tr><th>Date</th><th>Who</th><th>Client</th><th>Task</th><th>Active</th><th>Inactive</th>
                    <th>Activity</th><th>Source</th><th>Status</th><th></th></tr>
            </thead>
            <tbody>
                @if ($sessions->isEmpty())
                    <tr><td colspan="10" class="muted">No sessions in this period.</td></tr>
                @endif
                @foreach ($sessions as $session)
                    <tr>
                        <td><x-time :at="$session->getRawOriginal('started_at')" fmt="full" /></td>
                        <td>{{ $usersById[$session->user_id]->name ?? '' }}</td>
                        <td>{{ $session->client_id ? ($clientNames[$session->client_id] ?? '—') : '—' }}</td>
                        <td>{{ $taskNames[$session->task_id] ?? '—' }}</td>
                        <td>{{ \App\Support\Format::hms($session->active_s) }}</td>
                        <td>{{ \App\Support\Format::hms($session->inactive_s) }}</td>
                        <td>{{ $session->activityPercent() }}%</td>
                        <td><span class="tag">{{ $session->source }}</span></td>
                        <td><span class="status {{ $session->approval_status }}">{{ $session->approval_status }}</span></td>
                        <td><a class="lnk" href="{{ url('/app/session/' . $session->id) }}">details</a></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

@endsection
