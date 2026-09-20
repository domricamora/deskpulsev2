@extends('layouts.app')

{{--
    Time off. Ported from server/templates/dashboard/leave.php.

    Balances are computed, never stored — entitlement minus approved days used.
    A negative balance is shown in the alert style rather than clamped to zero,
    because somebody being over their allowance is exactly what an approver
    needs to see.

    Two inline styles (the review note's width, and margins on the type forms)
    are classes now, per decision D13.
--}}

@php
    $activeTypes = $types->where('active', 1);
    $pending = $requests->where('status', 'pending');

    // "3.5" not "3.50", "3" not "3.00" — the legacy's number trimming.
    $trim = fn ($n) => rtrim(rtrim(number_format((float) $n, 2), '0'), '.');
@endphp

@section('content')

    @if ($balances)
        <div class="stat-row">
            @foreach ($balances as $balance)
                <div @class(['stat', 'alert' => $balance['left'] < 0])>
                    <div class="lbl">{{ $balance['type']->name }}{{ $balance['type']->paid ? '' : ' (unpaid)' }}</div>
                    <div>{{ $trim($balance['left']) }}</div>
                    <div class="sub">days left of {{ $trim($balance['entitled']) }}
                        · {{ $trim($balance['used']) }} used in {{ $year }}</div>
                </div>
            @endforeach
        </div>
    @endif

    <div class="panel">
        <h3>{{ $canApprove ? 'Record or request time off' : 'Request time off' }}</h3>
        <p class="muted">Days are counted against the member's own work schedule, so weekends and
            non-working days are never deducted.
            {{ $canApprove
                ? 'Anything you file here is approved immediately; requests from employees wait for your review.'
                : 'Your request goes to your manager or HR for approval.' }}</p>
        <form method="post" action="{{ url('/app/leave') }}" class="stack">
            @csrf
            <input type="hidden" name="action" value="request">
            <div class="inline">
                @if ($canApprove)
                    <label>Employee <select name="user_id">
                            @foreach ($members as $member)
                                <option value="{{ (int) $member->id }}" @selected((int) $member->id === $meId)>{{ $member->name }}</option>
                            @endforeach
                        </select></label>
                @endif
                <label>Leave type <select name="leave_type_id" required>
                        @foreach ($activeTypes as $type)
                            <option value="{{ (int) $type->id }}">{{ $type->name }}{{ $type->paid ? '' : ' — unpaid' }}</option>
                        @endforeach
                    </select></label>
                <label>From <input type="date" name="start_date" required></label>
                <label>To <input type="date" name="end_date" required></label>
                <label>Hours per day <input type="number" name="hours_per_day" step="0.5" min="0.5" max="24" value="8"></label>
            </div>
            <label class="check"><input type="checkbox" name="half_day"> Half day (single date only)</label>
            <label>Reason <textarea name="reason" rows="2" placeholder="Optional"></textarea></label>
            <button class="btn" type="submit">{{ $canApprove ? 'Record time off' : 'Submit request' }}</button>
        </form>
    </div>

    @if ($canApprove && $pending->isNotEmpty())
        <div class="panel">
            <h3>Awaiting your review <span class="badge">{{ $pending->count() }}</span></h3>
            @foreach ($pending as $leaveRequest)
                <div class="approval">
                    <div>
                        <b>{{ $leaveRequest->user_name }}</b> — {{ $leaveRequest->type_name }}
                        <span class="tag">{{ $leaveRequest->paid ? 'paid' : 'unpaid' }}</span><br>
                        {{ \App\Support\Period::rangeLabel($leaveRequest->start_date, $leaveRequest->end_date) }}
                        · {{ $trim($leaveRequest->total_days) }} day(s)
                        · {{ $trim($leaveRequest->total_hours) }} h
                        @if ($leaveRequest->reason)<br><small class="muted">{{ $leaveRequest->reason }}</small>@endif
                    </div>
                    <form method="post" action="{{ url('/app/leave') }}" class="actions-row">
                        @csrf
                        <input type="hidden" name="action" value="review">
                        <input type="hidden" name="request_id" value="{{ (int) $leaveRequest->id }}">
                        <input type="text" name="review_note" placeholder="Note (optional)" class="note-input">
                        <button class="btn sm" type="submit" name="decision" value="approve">Approve</button>
                        <button class="btn sm danger" type="submit" name="decision" value="reject">Reject</button>
                    </form>
                </div>
            @endforeach
        </div>
    @endif

    <div class="panel">
        <h3>{{ $canApprove ? 'All leave' : 'My leave' }}</h3>
        @if ($requests->isEmpty())
            <div class="empty-state">No time off recorded yet.</div>
        @else
            <table class="data">
                <thead>
                    <tr>
                        @if ($canApprove)<th>Employee</th>@endif
                        <th>Type</th><th>From</th><th>To</th><th>Days</th><th>Hours</th>
                        <th>Paid</th><th>Status</th><th>Reviewed by</th><th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($requests as $leaveRequest)
                        <tr>
                            @if ($canApprove)<td>{{ $leaveRequest->user_name }}</td>@endif
                            <td>{{ $leaveRequest->type_name }}</td>
                            <td data-sort="{{ $leaveRequest->start_date }}">{{ date('j M Y', strtotime($leaveRequest->start_date)) }}</td>
                            <td data-sort="{{ $leaveRequest->end_date }}">{{ date('j M Y', strtotime($leaveRequest->end_date)) }}</td>
                            <td>{{ $trim($leaveRequest->total_days) }}</td>
                            <td>{{ $trim($leaveRequest->total_hours) }}</td>
                            <td>{{ $leaveRequest->paid ? 'Yes' : 'No' }}</td>
                            <td><span class="status {{ $leaveRequest->status === 'cancelled' ? 'rejected' : $leaveRequest->status }}">{{ $leaveRequest->status }}</span></td>
                            <td class="small muted">{{ $leaveRequest->reviewer ?? '—' }}
                                @if ($leaveRequest->review_note)<br><small>{{ $leaveRequest->review_note }}</small>@endif
                            </td>
                            <td>
                                @if ($leaveRequest->status === 'pending' && ((int) $leaveRequest->user_id === $meId || $canApprove))
                                    <form method="post" action="{{ url('/app/leave') }}" class="inline-form">
                                        @csrf
                                        <input type="hidden" name="action" value="cancel">
                                        <input type="hidden" name="request_id" value="{{ (int) $leaveRequest->id }}">
                                        <button class="lnk danger" type="submit">cancel</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    @if ($canApprove)
        <div class="two-col">
            <div class="panel">
                <h3>Leave types</h3>
                <p class="muted">A <b>paid</b> type turns approved days into paid hours for hourly staff.
                    Salaried staff are already paid for the day, so their leave is recorded but not paid twice.</p>
                <table class="data">
                    <thead><tr><th>Name</th><th>Paid</th><th>Default days/yr</th><th>Active</th></tr></thead>
                    <tbody>
                        @foreach ($types as $type)
                            <tr>
                                <td>{{ $type->name }}<br><small class="muted"><code>{{ $type->code }}</code></small></td>
                                <td>{{ $type->paid ? 'Yes' : 'No' }}</td>
                                <td>{{ $trim($type->days_per_year) }}</td>
                                <td>
                                    <details>
                                        <summary class="lnk">{{ $type->active ? 'active' : 'inactive' }}</summary>
                                        <form method="post" action="{{ url('/app/leave') }}" class="stack edit-form">
                                            @csrf
                                            <input type="hidden" name="action" value="save_type">
                                            <input type="hidden" name="type_id" value="{{ (int) $type->id }}">
                                            <input type="hidden" name="code" value="{{ $type->code }}">
                                            <label>Name <input type="text" name="name" value="{{ $type->name }}"></label>
                                            <label>Default days per year
                                                <input type="number" name="days_per_year" step="0.5" min="0" value="{{ $type->days_per_year }}"></label>
                                            <label class="check"><input type="checkbox" name="paid" @checked($type->paid)> Paid</label>
                                            <label class="check"><input type="checkbox" name="active" @checked($type->active)> Active</label>
                                            <button class="btn sm" type="submit">Save</button>
                                        </form>
                                    </details>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <form method="post" action="{{ url('/app/leave') }}" class="row-form new-team">
                    @csrf
                    <input type="hidden" name="action" value="save_type">
                    <label class="grow">New type <input type="text" name="name" placeholder="e.g. Bereavement" required></label>
                    <label>Code <input type="text" name="code" placeholder="bereavement" required></label>
                    <label>Days/yr <input type="number" name="days_per_year" step="0.5" min="0" value="0"></label>
                    <label class="check"><input type="checkbox" name="paid" checked> Paid</label>
                    <button class="btn" type="submit">Add</button>
                </form>
            </div>

            <div class="panel">
                <h3>Set an entitlement</h3>
                <p class="muted">Overrides the type's default for one person and year — use it for part-timers
                    or pro-rated first-year allowances.</p>
                <form method="post" action="{{ url('/app/leave') }}" class="stack">
                    @csrf
                    <input type="hidden" name="action" value="save_entitlement">
                    <label>Employee <select name="user_id" required>
                            @foreach ($members as $member)
                                <option value="{{ (int) $member->id }}">{{ $member->name }}</option>
                            @endforeach
                        </select></label>
                    <label>Leave type <select name="leave_type_id" required>
                            @foreach ($activeTypes as $type)
                                <option value="{{ (int) $type->id }}">{{ $type->name }}</option>
                            @endforeach
                        </select></label>
                    <div class="inline">
                        <label>Year <input type="number" name="year" min="2000" max="2100" value="{{ $year }}"></label>
                        <label>Days <input type="number" name="days" step="0.5" min="0" value="0"></label>
                        <label>Carried over <input type="number" name="carried_days" step="0.5" min="0" value="0"></label>
                    </div>
                    <button class="btn" type="submit">Save entitlement</button>
                </form>
            </div>
        </div>
    @endif

@endsection
