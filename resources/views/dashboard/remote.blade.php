@extends('layouts.app')

{{--
    Remote control console. Ported from server/templates/dashboard/remote.php.

    The worker sees a visible "remote control active" indicator in their tray
    for the whole session — that is the agent's doing, not this page's, and it
    must never be removed (§79, consent-first monitoring).

    Three inline styles became classes, per decision D13. The legacy header
    comment claimed "super-admin only"; the capability is `remote`, which
    company and IT admins hold too.
--}}

@section('content')

    <div class="panel" id="remote-panel"
        data-start-url="{{ url('/app/remote/' . (int) $device->id . '/start') }}"
        data-base="{{ url('/app/remote/') }}"
        data-csrf="{{ csrf_token() }}">
        <div class="live-head">
            <h3><span class="live-dot"></span> Remote control</h3>
            <span class="status" id="remote-pill">Idle</span>
        </div>

        <p class="muted">
            <b>{{ $worker->name ?? 'Unknown user' }}</b>
            · {{ $worker->organization?->name }}
            · device {{ $device->name ?: 'Desktop' }}
            · last seen
            @if ($device->last_seen)
                <x-time :at="$device->getRawOriginal('last_seen')" fmt="full" />
            @else
                never
            @endif
        </p>

        <p class="muted">
            Take full control of the worker's primary screen. The worker sees a visible
            "remote control active" indicator for the entire session. Control ends when you
            press Stop, when the agent stops responding, or after a period of inactivity.
        </p>

        <div class="remote-controls">
            <button type="button" class="btn" id="remote-start">Start control</button>
            <button type="button" class="btn danger" id="remote-stop" disabled>Stop</button>
        </div>

        <div class="remote-stage" tabindex="0">
            <p class="muted" id="remote-wait" hidden>Waiting for agent…</p>
            <img id="remote-frame" class="remote-screen" alt="Remote screen">
        </div>

        <p class="muted remote-hint">
            Click the screen, then type or move the mouse to control the remote machine.
        </p>
    </div>

@endsection
