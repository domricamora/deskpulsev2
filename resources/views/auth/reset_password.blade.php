{{-- Choose a new password. Expects $token, $error, $email. --}}
@extends('layouts.public')

@section('content')
<div class="auth-card">
  @if ($error)
    <h2>That link didn&rsquo;t work</h2>
    <p class="muted">{{ $error }}</p>
    <p><a class="btn block" href="{{ url('/forgot-password') }}">Request a new link</a></p>
    <p class="muted"><a href="{{ url('/login') }}">Back to sign in</a></p>
  @else
    <h2>Choose a new password</h2>
    @if ($email)
      <p class="muted">For <b>{{ $email }}</b>.</p>
    @endif
    <form method="post" action="{{ url('/reset-password') }}">
      @csrf
      <input type="hidden" name="token" value="{{ $token }}">
      <label>New password
        <span class="pw-wrap">
          <input type="password" name="password" minlength="8" required autofocus
                 id="dp-pass" autocomplete="new-password">
          <button type="button" class="pw-toggle" id="dp-pass-toggle"
                  aria-label="Show password">Show</button>
        </span>
        <small class="muted">At least 8 characters.</small>
      </label>
      <label>Confirm new password
        <input type="password" name="password_confirm" minlength="8" required
               autocomplete="new-password">
      </label>
      <button class="btn block" type="submit">Set new password and sign in</button>
    </form>
  @endif
</div>
@endsection
