@props(['at' => null, 'fmt' => 'datetime'])

{{--
    A stored UTC instant, localized in the browser. Ports tlocal().

    The server renders a UTC fallback so the value is readable before the script
    runs (and in a feed reader, or with JS off); dashboard.js then rewrites the
    text to the VIEWER's timezone — the third of the three clocks in
    reports.md §1. The instant itself goes out as a proper ISO-8601 string with
    its offset, not the raw SQL datetime: `new Date("2026-09-20 07:41:39")` is
    parsed as LOCAL time by most browsers, which would silently shift every
    timestamp by the viewer's offset.

    Formats match the legacy exactly: datetime, date, time, sec, full.
--}}

@php
    $formats = [
        'datetime' => 'M j, H:i',
        'date'     => 'M j, Y',
        'time'     => 'H:i',
        'sec'      => 'H:i:s',
        'full'     => 'M j, Y H:i',
    ];

    $raw = $at instanceof \DateTimeInterface ? $at->format('Y-m-d H:i:s') : (string) $at;
    $epoch = $raw === '' ? null : strtotime($raw . ' UTC');
@endphp

@if ($epoch === null || $epoch === false)
    <span class="muted">—</span>
@else
    <time class="dp-time" data-utc="{{ gmdate('c', $epoch) }}"
        data-fmt="{{ $fmt }}">{{ gmdate($formats[$fmt] ?? $formats['datetime'], $epoch) }}</time>
@endif
