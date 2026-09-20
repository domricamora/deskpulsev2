{{-- Gate 2's destination. Expects $user. --}}
@extends('layouts.public')

@section('content')
<div class="auth-card">
  <h2>Set your password</h2>
  <p class="muted">Welcome, {{ $user->name }}. For security, choose your own password
    before continuing — your account was created with a temporary one.</p>
  <form method="post" action="{{ url('/app/change-password') }}">
    @csrf
    <label>New password <input type="password" name="password" required autofocus minlength="8"></label>
    <label>Confirm password <input type="password" name="password_confirm" required minlength="8"></label>
    <button class="btn block" type="submit">Save password</button>
  </form>
  <p class="muted"><a href="{{ url('/logout') }}">Sign out</a></p>
</div>
@endsection
