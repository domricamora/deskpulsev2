{{--
    Signup. Expects $plan, $soloish, $old, $prices, $trialDays, $planPrice.

    Design notes, each one a fix rather than a preference:
     - The plan rides as a hidden field, so a pricing-page CTA survives the POST.
     - Easiest field first (name), hardest last (company) — and company is
       optional on the single-user plans, where "company workspace name" is
       meaningless friction.
     - Every field repopulates on error. Losing the form to one typo is the
       biggest avoidable drop-off there is.
     - Trial length and "no card required" are stated at the point of commitment.
--}}
@extends('layouts.public')

@section('content')
@php
    $planNames = ['solo' => 'Solo', 'individual' => 'Individual',
                  'per_seat' => 'Team', 'organization' => 'Organization'];
    $planName = $planNames[$plan] ?? 'Team';
    $free = $plan === 'solo';
@endphp
<div class="auth-card">
  <h2>Create your account</h2>

  <div class="signup-plan">
    <div>
      <span class="muted small">Selected plan</span>
      <b>{{ $planName }}</b>
    </div>
    <div class="signup-plan-price">{{ $planPrice }}</div>
    <a class="signup-plan-change" href="{{ url('/pricing') }}">Change</a>
  </div>

  <p class="muted small signup-assure">
    @if ($free)
      Free forever, one user. No card, no trial clock.
    @else
      {{ (int) $trialDays }}-day free trial. <b>No credit card required</b> — you pay by
      bank transfer only when you decide to keep it.
    @endif
  </p>

  <form method="post" action="{{ url('/register') }}" class="signup-form">
    @csrf
    <input type="hidden" name="plan" value="{{ $plan }}">

    <label>Your name
      <input type="text" name="name" required autofocus autocomplete="name"
             value="{{ $old['name'] }}"></label>

    <label>Work email
      <input type="email" name="email" required autocomplete="email" inputmode="email"
             id="dp-email" value="{{ $old['email'] }}">
      <small class="muted" id="dp-email-hint" hidden></small></label>

    <label>Password
      <span class="pw-wrap">
        <input type="password" name="password" minlength="8" required id="dp-pass"
               autocomplete="new-password">
        <button type="button" class="pw-toggle" id="dp-pass-toggle"
                aria-label="Show password">Show</button>
      </span>
      <small class="muted">At least 8 characters.</small></label>

    <label>{!! $soloish ? 'Workspace name <span class="muted">(optional)</span>' : 'Company / workspace name' !!}
      <input type="text" name="company" @if (! $soloish) required @endif autocomplete="organization"
             value="{{ $old['company'] }}"
             placeholder="{{ $soloish ? 'Defaults to your name' : '' }}">
      @if (! $soloish)<small class="muted">This is your private, isolated workspace.</small>@endif
    </label>

    <button class="btn block" type="submit">
      {{ $free ? 'Create free account' : 'Start free trial' }}
    </button>
  </form>

  <p class="muted small">By creating an account you agree to our
    <a href="{{ url('/terms') }}">Terms</a> and
    <a href="{{ url('/privacy') }}">Privacy policy</a>.
    Tracking only ever runs on a worker's own machine, after they press Start.</p>

  <p class="muted">Already have an account? <a href="{{ url('/login') }}">Sign in</a></p>
</div>
@endsection
