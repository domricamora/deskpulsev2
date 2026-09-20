@extends('layouts.app')

{{--
    The first-run role guide. Ported from server/templates/dashboard/welcome.php.

    The guide copy itself moved to App\Support\RoleGuides — it is a table keyed
    by role, and six roles' worth of wording inside a template is where a card
    quietly goes missing. What stays here is the wizard chrome: the step rail,
    one card at a time, and the done panel.

    Position lives in ?step=N and nowhere else.
--}}

@php
    $count = count($cards);
    $go = fn (int $i) => url('/app/welcome?step=' . $i);
@endphp

@section('content')

    <div class="welcome-hero">
        <div class="wh-badge">{!! $guide['badge'] !!}</div>
        <div>
            <h2>{{ $guide['title'] }}</h2>
            <p class="muted" style="margin:.25rem 0 0;max-width:62ch">{{ $guide['intro'] }}</p>
        </div>
    </div>

    <ol class="onb-steps">
        @foreach ($cards as $i => $card)
            <li class="{{ $i === $step ? 'on' : ($i < $step ? 'done' : '') }}">
                <a href="{{ $go($i) }}"><span class="n">{{ $i < $step ? '✓' : $i + 1 }}</span>{{ $card[0] }}</a>
            </li>
        @endforeach
        <li class="{{ $isDone ? 'on' : '' }}">
            <a href="{{ $go($count) }}"><span class="n">{{ $isDone ? '★' : $count + 1 }}</span>Done</a>
        </li>
    </ol>

    @if (! $isDone)
        @php $card = $cards[$step]; @endphp

        @if ($step === 0)
            <div class="tutorial">
                <b>Your role: {{ $me->role->label() }}.</b> The sidebar only shows the pages you have
                access to. Step through the cards below for a quick tour of what each one does.
            </div>
        @endif

        <div class="panel">
            <div class="guide-card" style="border:0;padding:0">
                <span class="gc-step">{{ $step + 1 }}</span>
                <h3 style="margin:.1rem 0 .4rem">{{ $card[0] }}</h3>
                <p style="font-size:.95rem">{{ $card[1] }}</p>
                @if (! empty($card[2]))
                    <a class="btn sm ghost" style="margin-top:.7rem"
                        href="{{ url($card[2]) }}">{{ $card[3] ?? 'Open' }} →</a>
                @endif
            </div>
            <div class="onb-nav">
                @if ($step > 0)
                    <a class="btn ghost" href="{{ $go($step - 1) }}">← Back</a>
                @endif
                <a class="btn ghost" href="{{ $go($count) }}">Skip tour</a>
                <a class="btn" href="{{ $go($step + 1) }}">Continue →</a>
            </div>
        </div>
    @else
        <div class="panel onb-done">
            <h3>🎉 You're all set</h3>
            <p class="muted">That's the quick tour for your role. You can reopen this guide any time from
                <b>Getting started</b> in the sidebar.</p>
            <form method="post" action="{{ url('/app/welcome') }}" class="guide-actions">
                @csrf
                <input type="hidden" name="action" value="dismiss">
                @if ($isFirstRun)
                    <button class="btn lg" type="submit" name="to" value="/app/overview">Go to my dashboard →</button>
                    <span class="muted small">You won't see this automatically again.</span>
                @else
                    <a class="btn lg" href="{{ url('/app/overview') }}">Back to dashboard →</a>
                @endif
            </form>
        </div>
    @endif

@endsection
