@extends('layouts.app')

{{-- Overtime queue. Ported from server/templates/dashboard/overtime.php. --}}

@section('content')

    <div class="panel">
        <h3>Overtime awaiting approval</h3>
        <p class="muted">Activity worked beyond a member's schedule (outside their hours/days or past
            their daily hours) is held here. Approve it to credit those hours to payroll; reject it to
            leave them unpaid. The regular portion of each session is already credited.</p>

        @if ($pending->isEmpty())
            <p class="muted">No overtime pending review. 🎉</p>
        @endif

        @foreach ($pending as $session)
            <div class="approval">
                <div class="ap-info">
                    <b>{{ $usersById[$session->user_id]->name ?? '' }}</b>
                    <span class="muted"><x-time :at="$session->getRawOriginal('started_at')" fmt="full" /> –
                        <x-time :at="$session->getRawOriginal('ended_at')" fmt="time" /></span>
                    <div>Overtime: <b>{{ \App\Support\Format::hms($session->overtime_s) }}</b>
                        <small class="muted">of {{ \App\Support\Format::hms($session->active_s) }} active</small>
                        @if ($session->client_id) · {{ $clientNames[$session->client_id] ?? '' }} @endif
                        · <span class="tag">{{ $session->source }}</span>
                    </div>
                    @if ($session->note)
                        <div class="note">“{{ $session->note }}”</div>
                    @endif
                </div>
                <form method="post" action="{{ url('/app/overtime/' . $session->id) }}" class="ap-actions">
                    @csrf
                    <input type="text" name="review_note" placeholder="Optional note">
                    <button class="btn" name="decision" value="approve">Approve</button>
                    <button class="btn danger" name="decision" value="reject">Reject</button>
                </form>
            </div>
        @endforeach
    </div>

@endsection
