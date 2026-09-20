@extends('layouts.app')

{{-- Approvals. Ported from server/templates/dashboard/approvals.php. --}}

@section('content')

    <div class="panel">
        <h3>Pending manual entries</h3>
        <p class="muted">Members' manual time entries and adjustments wait here for your review.</p>

        @if ($pending->isEmpty())
            <p class="muted">Nothing pending. 🎉</p>
        @endif

        @foreach ($pending as $session)
            <div class="approval">
                <div class="ap-info">
                    <b>{{ $usersById[$session->user_id]->name ?? '' }}</b>
                    <span class="muted"><x-time :at="$session->getRawOriginal('started_at')" fmt="full" /> –
                        <x-time :at="$session->getRawOriginal('ended_at')" fmt="time" /></span>
                    <div>Active time: <b>{{ \App\Support\Format::hms($session->active_s) }}</b>
                        @if ($session->client_id) · {{ $clientNames[$session->client_id] ?? '' }} @endif
                    </div>
                    @if ($session->note)
                        <div class="note">“{{ $session->note }}”</div>
                    @endif
                </div>
                <form method="post" action="{{ url('/app/approvals/' . $session->id) }}" class="ap-actions">
                    @csrf
                    <input type="text" name="review_note" placeholder="Optional note">
                    <button class="btn" name="decision" value="approve">Approve</button>
                    <button class="btn danger" name="decision" value="reject">Reject</button>
                </form>
            </div>
        @endforeach
    </div>

@endsection
