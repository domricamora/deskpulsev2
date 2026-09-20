@extends('layouts.app')

{{--
    My profile. Ported from server/templates/dashboard/profile.php.

    Left panel edits; right panel is read-only and says who sets each figure, so
    nobody hunts for a control that is on someone else's page.
--}}

@section('content')

    <div class="two-col">
        <div class="panel">
            <h3>Profile information</h3>
            <form method="post" action="{{ url('/app/profile') }}" class="stack">
                @csrf
                <label>Full name <input type="text" name="name" value="{{ $me->name }}" required></label>
                <label>Email <input type="email" name="email" value="{{ $me->email }}" required></label>
                <div class="inline">
                    <label>Phone <input type="text" name="phone" value="{{ $me->phone ?? '' }}"></label>
                    <label>Job title <input type="text" name="job_title" value="{{ $me->job_title ?? '' }}"></label>
                </div>
                <label>New password
                    <input type="password" name="password" minlength="8" placeholder="leave blank to keep current">
                    <small class="muted">At least 8 characters.</small></label>
                <button class="btn" type="submit">Save profile</button>
            </form>
        </div>
        <div class="panel">
            <h3>Account</h3>
            <ul class="plain">
                <li>Role <span class="tag">{{ $me->role->label() }}</span></li>
                <li>Organization <b>{{ $org->name ?? '' }}</b></li>
                @if (! $me->isSuperAdmin() && $me->role !== \App\Enums\UserRole::ClientViewer)
                    <li>Work schedule <b>{{ \App\Support\Format::workSchedule($me) }}</b>
                        <small class="muted">set by your admin/HR</small></li>
                    <li>Pay rate <b>{{ \App\Support\Format::money($me->pay_rate, $me->currency) }}</b>
                        <small class="muted">/{{ $me->pay_type === 'monthly' ? 'mo' : 'hr' }} · set by your admin/HR</small></li>
                    @if ($canRates)
                        <li>Bill (client) {{ \App\Support\Format::money($me->bill_rate ?? 0, $me->currency) }}
                            <small class="muted">/{{ ($me->bill_type ?? 'hourly') === 'monthly' ? 'mo' : 'hr' }}</small></li>
                    @endif
                @endif
            </ul>
        </div>
    </div>

@endsection
