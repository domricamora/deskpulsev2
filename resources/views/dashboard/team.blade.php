@extends('layouts.app')

{{--
    Team. Ported from server/templates/dashboard/team.php.

    The table's columns change with the viewer: an HR admin sees a pay column
    and no bill column, because they can set what someone is paid but never
    read what a client is charged. tables.js reads the columns from the
    rendered <thead> for exactly this reason — nothing may assume a fixed shape.
--}}

@php
    $activeColor = '#3b82f6';   // matches the overview
    $idleColor = '#fbbf24';

    $dailySeries = [
        ['name' => 'Active', 'data' => $daily['active'], 'color' => '#3b82f6'],
        ['name' => 'Inactive', 'data' => $daily['inactive'], 'color' => '#475569'],
    ];

    $weekdays = ['1' => 'Mon', '2' => 'Tue', '3' => 'Wed', '4' => 'Thu', '5' => 'Fri', '6' => 'Sat', '7' => 'Sun'];

    // "7.5" rather than "7.50", and "" rather than "0.00" — the legacy trim.
    $capValue = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
@endphp

@section('content')

    <x-period-switch :ctx="$ctx" :periods="$periods" path="/app/team" />

    @if (array_sum($daily['active']) || array_sum($daily['inactive']))
        <div class="panel">
            <h3>Team hours by day</h3>
            <canvas class="dp-chart" height="240" data-type="bars"
                    data-labels='@json($daily['labels'])'
                    data-series='@json($dailySeries)'></canvas>
        </div>
    @endif

    <div class="panel">
        <h3>Members &amp; public links</h3>
        <p class="muted">Each member has an automatic public page (day summary, no salary info).</p>
        <table class="data">
            <thead>
            <tr>
                <th>Name</th>
                <th>Role</th>
                @if ($canViewRates || $canSetPay)<th>Pay (cost)</th>@endif
                @if ($canViewRates)<th>Bill (client)</th>@endif
                <th>Active</th>
                <th>Activity</th>
                @if ($canViewRates)<th>Labor cost</th>@endif
                <th>Public page</th>
                @if ($canManage || $canProfiles)<th>Manage</th>@endif
            </tr>
            </thead>
            <tbody>
            @foreach ($usersById as $id => $member)
                @php
                    $tracked = $perUser[$id] ?? ['active_s' => 0, 'inactive_s' => 0];
                    $cost = $tracked['active_s'] / 3600.0 * $stats->hourlyRate($member);
                    $total = $tracked['active_s'] + $tracked['inactive_s'];
                    $donut = [round($tracked['active_s'] / 3600, 2), round($tracked['inactive_s'] / 3600, 2)];
                    $workDays = explode(',', $member->work_days ?? '1,2,3,4,5');
                @endphp
                <tr>
                    <td>{{ $member->name }}<br><small class="muted">{{ $member->email }}@if ($member->job_title) · {{ $member->job_title }}@endif</small></td>
                    <td><span class="tag">{{ $member->role?->label() }}</span></td>

                    @if ($canViewRates || $canSetPay)
                        <td>{{ \App\Support\Format::money($member->pay_rate, $member->currency) }}<small class="muted">/{{ $member->pay_type === 'monthly' ? 'mo' : 'hr' }}</small></td>
                    @endif

                    @if ($canViewRates)
                        <td>{{ \App\Support\Format::money($member->bill_rate ?? 0, $member->currency) }}<small class="muted">/{{ ($member->bill_type ?? 'hourly') === 'monthly' ? 'mo' : 'hr' }}</small></td>
                    @endif

                    <td>{{ \App\Support\Format::hms($tracked['active_s']) }}</td>
                    <td>
                        @if ($total > 0)
                            <canvas class="dp-chart" data-type="donut" data-mini height="46"
                                    data-values='@json($donut)'
                                    data-labels='@json(['Active', 'Inactive'])'
                                    data-colors='@json([$activeColor, $idleColor])'
                                    title="{{ round(100 * $tracked['active_s'] / max(1, $total)) }}% active"></canvas>
                        @else
                            <span class="muted small">—</span>
                        @endif
                    </td>

                    @if ($canViewRates)<td>{{ \App\Support\Format::money($cost, $member->currency) }}</td>@endif

                    <td>
                        @if (! empty($personalLinks[$id]))
                            @php $publicUrl = $publicBase . '/share/' . $personalLinks[$id]; @endphp
                            <a class="lnk" href="{{ $publicUrl }}" target="_blank">open</a>
                            <button type="button" class="lnk copy-link" data-link="{{ $publicUrl }}" title="Copy link">copy</button>
                        @else
                            —
                        @endif
                    </td>

                    @if ($canManage || $canProfiles)
                        <td>
                            <details>
                                <summary class="lnk">edit</summary>

                                @if ($canManage)
                                    <form method="post" action="{{ url('/app/team') }}" class="stack edit-form">
                                        @csrf
                                        <input type="hidden" name="action" value="update_user">
                                        <input type="hidden" name="user_id" value="{{ $id }}">
                                        <div class="inline">
                                            <label>Pay type <select name="pay_type">
                                                    <option value="hourly" @selected($member->pay_type === 'hourly')>hourly</option>
                                                    <option value="monthly" @selected($member->pay_type === 'monthly')>monthly</option>
                                                </select></label>
                                            <label>Pay rate <input type="number" name="pay_rate" step="0.01" min="0" value="{{ $member->pay_rate }}"></label>
                                        </div>
                                        <div class="inline">
                                            <label>Bill type <select name="bill_type">
                                                    <option value="hourly" @selected(($member->bill_type ?? 'hourly') === 'hourly')>hourly</option>
                                                    <option value="monthly" @selected(($member->bill_type ?? 'hourly') === 'monthly')>monthly service</option>
                                                </select></label>
                                            <label>Bill rate <input type="number" name="bill_rate" step="0.01" min="0" value="{{ $member->bill_rate ?? 0 }}"></label>
                                        </div>
                                        <div class="inline">
                                            <label>Currency <input type="text" name="currency" value="{{ $member->currency }}" maxlength="8"></label>
                                            <label>Role <select name="role">
                                                    @foreach ($assignable as $role)
                                                        <option value="{{ $role }}" @selected($member->role?->value === $role)>{{ \App\Enums\UserRole::from($role)->label() }}</option>
                                                    @endforeach
                                                    {{-- A platform operator's own row keeps its role rather than
                                                         silently becoming a member on the next save. --}}
                                                    @if ($member->role === \App\Enums\UserRole::SuperAdmin)
                                                        <option value="super_admin" selected>Super admin</option>
                                                    @endif
                                                </select></label>
                                        </div>

                                        <h5 class="sched-h">Employment</h5>
                                        @include('dashboard.partials.employment-fields', ['member' => $member, 'employmentTypes' => $employmentTypes, 'capValue' => $capValue])
                                        <h5 class="sched-h">Work schedule</h5>
                                        @include('dashboard.partials.schedule-fields', ['member' => $member, 'weekdays' => $weekdays, 'workDays' => $workDays])

                                        <button class="btn" type="submit">Save</button>
                                    </form>
                                @else
                                    {{-- HR: the person's details, plus their pay rate when allowed. --}}
                                    <form method="post" action="{{ url('/app/team') }}" class="stack edit-form">
                                        @csrf
                                        <input type="hidden" name="action" value="update_profile">
                                        <input type="hidden" name="user_id" value="{{ $id }}">
                                        <label>Name <input type="text" name="name" value="{{ $member->name }}"></label>
                                        <label>Email <input type="email" name="email" value="{{ $member->email }}"></label>
                                        <div class="inline">
                                            <label>Phone <input type="text" name="phone" value="{{ $member->phone ?? '' }}"></label>
                                            <label>Job title <input type="text" name="job_title" value="{{ $member->job_title ?? '' }}"></label>
                                        </div>
                                        @if ($canSetPay)
                                            <div class="inline">
                                                <label>Pay type <select name="pay_type">
                                                        <option value="hourly" @selected($member->pay_type === 'hourly')>hourly</option>
                                                        <option value="monthly" @selected($member->pay_type === 'monthly')>monthly</option>
                                                    </select></label>
                                                <label>Pay rate <input type="number" name="pay_rate" step="0.01" min="0" value="{{ $member->pay_rate }}"></label>
                                                <label>Currency <input type="text" name="currency" value="{{ $member->currency }}" maxlength="8"></label>
                                            </div>
                                        @endif

                                        <h5 class="sched-h">Employment</h5>
                                        @include('dashboard.partials.employment-fields', ['member' => $member, 'employmentTypes' => $employmentTypes, 'capValue' => $capValue])
                                        <h5 class="sched-h">Work schedule</h5>
                                        @include('dashboard.partials.schedule-fields', ['member' => $member, 'weekdays' => $weekdays, 'workDays' => $workDays])

                                        <button class="btn" type="submit">Save profile</button>
                                    </form>
                                @endif
                            </details>
                        </td>
                    @endif
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>

    @if ($canManage)
        <div class="two-col">
            <div class="panel">
                <h3>Add a team member</h3>
                <form method="post" action="{{ url('/app/team') }}" class="stack">
                    @csrf
                    <input type="hidden" name="action" value="create_user">
                    <label>Name <input type="text" name="name" required></label>
                    <label>Email <input type="email" name="email" required></label>
                    <label>Temp password <input type="text" name="password" placeholder="leave blank to auto-generate"></label>
                    <div class="inline">
                        <label>Role <select name="role">
                                @foreach ($creatable as $role)
                                    <option value="{{ $role }}">{{ \App\Enums\UserRole::from($role)->label() }}</option>
                                @endforeach
                            </select></label>
                        <label>Pay type <select name="pay_type">
                                <option value="hourly">hourly</option>
                                <option value="monthly">monthly</option>
                            </select></label>
                    </div>
                    <div class="inline">
                        <label>Pay rate <input type="number" name="pay_rate" step="0.01" min="0" value="0"></label>
                        <label>Bill rate <input type="number" name="bill_rate" step="0.01" min="0" value="0"></label>
                        <label>Currency <input type="text" name="currency" value="USD" maxlength="8"></label>
                    </div>
                    <button class="btn" type="submit">Create member</button>
                </form>
            </div>

            <div class="panel">
                <h3>Teams &amp; membership</h3>
                <p class="muted">Assign managers and client viewers to teams — they can only see members
                    of teams they belong to.</p>

                @foreach ($teams as $team)
                    <div class="team-block">
                        <b>{{ $team->name }}</b>
                        <ul class="chips">
                            @forelse ($teamMembers[$team->id] ?? [] as $membership)
                                <li>{{ $membership->name }}
                                    <form method="post" action="{{ url('/app/team') }}" class="inline-form">
                                        @csrf
                                        <input type="hidden" name="action" value="remove_team_member">
                                        <input type="hidden" name="tm_id" value="{{ $membership->tm_id }}">
                                        <button class="lnk danger" title="Remove">✕</button>
                                    </form>
                                </li>
                            @empty
                                <li class="muted">No members</li>
                            @endforelse
                        </ul>
                        <form method="post" action="{{ url('/app/team') }}" class="row-form">
                            @csrf
                            <input type="hidden" name="action" value="add_team_member">
                            <input type="hidden" name="team_id" value="{{ $team->id }}">
                            <label>Add <select name="user_id">
                                    @foreach ($usersById as $uid => $member)
                                        <option value="{{ $uid }}">{{ $member->name }} ({{ $member->role?->label() }})</option>
                                    @endforeach
                                </select></label>
                            <button class="btn sm" type="submit">Add</button>
                        </form>
                    </div>
                @endforeach

                <form method="post" action="{{ url('/app/team') }}" class="row-form new-team">
                    @csrf
                    <input type="hidden" name="action" value="create_team">
                    <label class="grow">New team <input type="text" name="team_name" required></label>
                    <button class="btn" type="submit">Add team</button>
                </form>
            </div>
        </div>
    @endif

@endsection
