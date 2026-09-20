@props([
    'ctx',
    'path',
    'periods' => [],
    'extra' => [],
])

@php
    /**
     * The period pills, the ◀ ▶ navigator, the resolved window label and the
     * custom from→to range form. Ports period_switch_html().
     *
     * $ctx is the array App\Support\Period::context() returned for this page —
     * the legacy widget reaches into $GLOBALS['DP_PERIOD_CTX'] for the same
     * thing. $extra is preserved on every link and form, which is how a
     * page-level filter (?client=, ?user_id=) survives a period change.
     */
    $active = $ctx['period'];
    $anchor = $ctx['anchor'];

    $link = fn (array $params) => url($path) . '?' . http_build_query(array_merge($extra, $params));

    // An active period that is not one of this page's pills (a hand-typed
    // ?period=) still gets one, so the UI never renders with nothing lit.
    if ($active !== 'range' && ! isset($periods[$active])) {
        $periods[$active] = \App\Support\Period::OPTIONS[$active] ?? ucfirst($active);
    }

    $span = max(0, (int) $ctx['days'] - 1);

    $prevParams = $active === 'range'
        ? ['period' => 'range', 'from' => $ctx['prev'], 'to' => \App\Support\Period::addDays($ctx['prev'], $span)]
        : ['period' => $active, 'date' => $ctx['prev']];

    $nextParams = $active === 'range'
        ? ['period' => 'range', 'from' => $ctx['next'], 'to' => \App\Support\Period::addDays($ctx['next'], $span)]
        : ['period' => $active, 'date' => $ctx['next']];

    $atToday = strcmp($ctx['start_date'], $ctx['today']) <= 0
        && strcmp($ctx['end_date'], $ctx['today']) >= 0;

    $ymd = '/^\d{4}-\d{2}-\d{2}$/';
    $rangeFrom = preg_match($ymd, (string) ($ctx['from'] ?? '')) ? $ctx['from'] : '';
    $rangeTo = preg_match($ymd, (string) ($ctx['to'] ?? '')) ? $ctx['to'] : '';
@endphp

<div class="period-switch">
    @foreach ($periods as $key => $label)
        {{-- Keep the anchor when switching pills, so "this week" → "this month"
             stays on the week you were looking at rather than jumping to today. --}}
        <a @class(['on' => $active === $key]) href="{{ $link(['period' => $key, 'date' => $anchor]) }}">{{ $label }}</a>
    @endforeach

    <span class="period-nav">
        <a class="pnav" rel="prev" aria-label="Previous period" href="{{ $link($prevParams) }}">&#9664;</a>
        <span class="period-label" @if ($atToday) data-now="1" @endif>{{ $ctx['label'] }}</span>
        <a class="pnav" rel="next" aria-label="Next period" href="{{ $link($nextParams) }}">&#9654;</a>
    </span>

    @unless ($atToday)
        <a class="pnav-today"
           href="{{ $link(['period' => $active === 'range' ? 'week' : $active, 'date' => $ctx['today']]) }}">Today</a>
    @endunless

    @if ($active !== 'range')
        <form method="get" action="{{ url($path) }}" class="period-custom">
            <input type="hidden" name="period" value="{{ $active }}">
            @foreach ($extra as $name => $value)
                <input type="hidden" name="{{ $name }}" value="{{ $value }}">
            @endforeach
            <input type="date" name="date" value="{{ $anchor }}" aria-label="Jump to date" data-autosubmit>
        </form>
    @endif

    <form method="get" action="{{ url($path) }}"
          @class(['period-custom', 'period-range', 'on' => $active === 'range'])>
        <input type="hidden" name="period" value="range">
        @foreach ($extra as $name => $value)
            <input type="hidden" name="{{ $name }}" value="{{ $value }}">
        @endforeach
        <input type="date" name="from" value="{{ $rangeFrom }}" aria-label="From date" required>
        <span class="rng-sep">→</span>
        <input type="date" name="to" value="{{ $rangeTo }}" aria-label="To date" required>
        <button type="submit" class="btn sm">Apply</button>
    </form>

    {{ $slot }}
</div>
