@extends('layouts.app')

{{--
    Screenshots. Ported from server/templates/dashboard/screenshots.php.

    Two things carried over deliberately rather than tidied:

    * The "Day (UTC)" label. The filter is actually cut in the ORGANIZATION's
      reporting timezone, not UTC — the label has been wrong in the legacy app
      all along, and relabelling it is a visible change this migration is not
      making.
    * The `style="margin-bottom:1rem"` on the filter form is the `.filter-form`
      class now, same value, because decision D13 rules out style-src
      'unsafe-inline'.

    The images are no longer public files. Each one is fetched through
    /app/screenshots/{id}/image, which checks the viewer may see the person in
    it — see docs/migration/screenshots.md §4 and decision D4.
--}}

@section('content')

    <div class="panel">
        <h3>Screenshots</h3>

        <form method="get" action="{{ url('/app/screenshots') }}" class="row-form filter-form">
            @if ($isManager)
                <label>Member
                    <select name="user_id">
                        <option value="0">Everyone</option>
                        @foreach ($members as $member)
                            <option value="{{ (int) $member->id }}"
                                @selected($filterUser === (int) $member->id)>{{ $member->name }}</option>
                        @endforeach
                    </select>
                </label>
            @endif
            <label>Day (UTC) <input type="date" name="date" value="{{ $filterDate }}"></label>
            <button class="btn" type="submit">Filter</button>
            @if ($filterUser || $filterDate)
                <a class="ghost" href="{{ url('/app/screenshots') }}">Clear</a>
            @endif
        </form>

        @if ($shots->isNotEmpty())
            <div class="shot-grid">
                @foreach ($shots as $shot)
                    @php $at = strtotime($shot->ts . ' UTC'); @endphp
                    <a href="{{ route('screenshots.image', $shot->id) }}" target="_blank">
                        <img src="{{ route('screenshots.image', $shot->id) }}" loading="lazy" alt="screenshot">
                        <span>{{ $shot->user_name }} ·
                            <time class="dp-time" data-utc="{{ gmdate('c', $at) }}"
                                data-fmt="datetime">{{ gmdate('M j, H:i', $at) }}</time>@if ($shot->monitor) ·
                                <span class="tag">Monitor {{ (int) $shot->monitor }}</span>
                            @endif
                        </span>
                    </a>
                @endforeach
            </div>
        @else
            <p class="muted">No screenshots
                {{ ($filterUser || $filterDate)
                    ? 'match this filter.'
                    : 'captured yet. They appear here once a desktop agent uploads them during a tracked session.' }}</p>
        @endif
    </div>

@endsection
