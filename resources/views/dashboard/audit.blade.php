@extends('layouts.app')

{{--
    Audit log. Ported from server/templates/dashboard/audit.php.

    Derived from timestamps that already exist rather than read from a log
    table — see AuditController for why (decision D14).
--}}

@section('content')

    <div class="panel">
        <h3>Audit log</h3>
        <p class="muted">Recent activity across the organization — sessions, time-entry reviews,
            device registrations and share links.</p>
        <table class="data">
            <thead><tr><th>When</th><th>Actor</th><th>Event</th><th>Detail</th></tr></thead>
            <tbody>
                @forelse ($events as $event)
                    <tr>
                        <td><x-time :at="$event['ts']" fmt="full" /></td>
                        <td>{{ $event['who'] }}</td>
                        <td><span class="tag">{{ $event['event'] }}</span></td>
                        <td class="trunc">{{ $event['detail'] }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="muted">No activity yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

@endsection
