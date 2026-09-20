{{--
    Sign in. Expects $providers (configured OIDC providers), $ssoHint (an
    organization whose enforced SSO covers the address just attempted, if any)
    and $oldEmail.

    Social buttons sit ABOVE the password form: for a B2B audience they convert
    better than email/password, and burying them under the form wastes that.
--}}
@extends('layouts.public')

@section('content')
@php
    $next = request()->query('next');
    $action = url('/login') . ($next !== null ? '?next=' . rawurlencode($next) : '');
@endphp
<div class="auth-card">
  <h2>Sign in</h2>

  @if ($ssoHint)
    <div class="sso-hint">
      <p><b>{{ $ssoHint->name }}</b> uses single sign-on. Continue with your
        organization account.</p>
      <a class="btn block" href="{{ url('/auth/sso?org=' . (int) $ssoHint->id) }}">
        Continue with single sign-on</a>
    </div>
  @endif

  @if ($providers)
    <div class="oauth-row">
      @foreach ($providers as $key => $provider)
        <a class="btn ghost oauth-btn" href="{{ url('/auth/' . $key) }}">
          <x-oauth-mark :provider="$key" /> Continue with {{ $provider['label'] }}</a>
      @endforeach
    </div>
    <div class="or-rule"><span>or</span></div>
  @endif

  <form method="post" action="{{ $action }}">
    @csrf
    <label>Email
      <input type="email" name="email" required autofocus autocomplete="email"
             value="{{ $oldEmail }}"></label>
    <label>Password
      <input type="password" name="password" required autocomplete="current-password"></label>
    <button class="btn block" type="submit">Sign in</button>
  </form>

  <p class="muted small" style="margin-top:.6rem">
    <a href="{{ url('/forgot-password') }}">Forgot your password?</a>
  </p>
  <p class="muted">New here? <a href="{{ url('/register') }}">Create an account</a></p>
</div>
@endsection
