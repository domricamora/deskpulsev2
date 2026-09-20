@extends('layouts.app')

{{--
    Live. Ported from server/templates/dashboard/live.php.

    The page is a shell: everything in it is drawn by resources/js/live.js from
    /app/live/data, polled every 15 seconds. The legacy loaded that script with
    its own <script> tag; it is part of the app bundle now, and no-ops on every
    other page because #live-grid is absent there.

    The copy is the legacy's, word for word, including the note about agents
    syncing about once a minute — it is the honest explanation of why a card can
    lag the person it describes.
--}}

@section('content')

    <div class="panel">
        <div class="live-head">
            <h3><span class="live-dot"></span> Live team activity</h3>
            <span class="muted" id="live-updated">connecting…</span>
        </div>
        <p class="muted">Everyone tracking right now, grouped by organization → team → client.
            Auto-refreshes every 15 seconds; agents sync roughly once a minute, so "current"
            reflects the most recent batch.</p>
        <div id="live-grid" class="live-sections" data-endpoint="{{ url('/app/live/data') }}"></div>
    </div>

@endsection
