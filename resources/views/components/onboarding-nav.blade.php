{{--
    The Back / Continue pair under a wizard step.

    Ports the `$nav` closure the legacy template defines inline. Back is absent
    on the first step rather than disabled — there is nowhere behind it.
--}}
@props(['previous' => null, 'next' => 'done'])

<div class="onb-nav">
    @if ($previous)
        <a class="btn ghost" href="{{ url('/app/onboarding?step=' . $previous) }}">← Back</a>
    @endif
    <a class="btn" href="{{ url('/app/onboarding?step=' . $next) }}">Continue →</a>
</div>
