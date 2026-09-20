{{-- Request a reset link. Expects $sent. --}}
@extends('layouts.public')

@section('content')
<div class="auth-card">
  @if ($sent)
    <h2>Check your email</h2>
    <p class="muted">If an account exists for that address, a reset link is on its way. It is
      valid for 60 minutes and can only be used once.</p>
    <p class="muted small">Nothing arrived? Check spam, then
      <a href="{{ url('/forgot-password') }}">try again</a> — and make sure you used the
      address you sign in with.</p>
    <p class="muted"><a href="{{ url('/login') }}">Back to sign in</a></p>
  @else
    <h2>Reset your password</h2>
    <p class="muted">Enter the email address you sign in with and we&rsquo;ll send you a link
      to choose a new password.</p>
    <form method="post" action="{{ url('/forgot-password') }}">
      @csrf
      <label>Email
        <input type="email" name="email" required autofocus autocomplete="email" inputmode="email">
      </label>
      <button class="btn block" type="submit">Send reset link</button>
    </form>
    <p class="muted">Remembered it? <a href="{{ url('/login') }}">Sign in</a></p>
  @endif
</div>
@endsection
