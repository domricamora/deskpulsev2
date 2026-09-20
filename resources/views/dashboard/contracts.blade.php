@extends('layouts.app')

{{--
    Contracts. Ported from server/templates/dashboard/contracts.php.

    Terms, rate, status and dates are edited on the Clients page, nested under
    the client. This page is only the member roster, which is why HR can reach
    it with contracts_manage without holding clients_manage.
--}}

@section('content')

    <div class="panel">
        <h3>Contracts</h3>
        <p class="muted">Contract terms, billing rate and status are managed from the
            <a class="lnk" href="{{ url('/app/clients') }}">Clients</a> page. Here you can see every
            contract at a glance and control its member roster.</p>
    </div>

    @if ($contracts->isEmpty())
        <div class="panel"><div class="empty-state">No contracts yet — add one from the Clients page.</div></div>
    @endif

    @foreach ($contracts as $contract)
        @php
            $assigned = $membersByContract[$contract->id] ?? [];
            $assignedIds = array_map('intval', array_column($assigned, 'user_id'));
            $available = array_filter($people, fn ($m) => ! in_array((int) $m->id, $assignedIds, true));
        @endphp

        <details class="org-panel">
            <summary>
                <span class="op-name"><b>{{ $contract->title }}</b>
                    <span class="tag">{{ $contract->client_name }}</span>
                    <span class="status {{ $contract->status === 'ended' ? 'rejected' : 'approved' }}">{{ $contract->status }}</span>
                </span>
                <span class="muted small">{{ $contract->start_date ?: '—' }} – {{ $contract->end_date ?: '—' }}
                    · {{ count($assigned) }} member{{ count($assigned) === 1 ? '' : 's' }}</span>
            </summary>
            <div class="op-body">
                <h4>Assigned members</h4>
                <ul class="chips">
                    @forelse ($assigned as $member)
                        <li>{{ $member->name }}
                            <form method="post" action="{{ url('/app/contracts') }}" class="inline-form">
                                @csrf
                                <input type="hidden" name="action" value="unassign_member">
                                <input type="hidden" name="contract_id" value="{{ (int) $contract->id }}">
                                <input type="hidden" name="user_id" value="{{ (int) $member->user_id }}">
                                <button class="lnk danger" title="Remove">✕</button>
                            </form>
                        </li>
                    @empty
                        <li class="muted">No members assigned yet</li>
                    @endforelse
                </ul>

                @if ($available)
                    <form method="post" action="{{ url('/app/contracts') }}" class="row-form">
                        @csrf
                        <input type="hidden" name="action" value="assign_member">
                        <input type="hidden" name="contract_id" value="{{ (int) $contract->id }}">
                        <label class="grow">Assign member
                            <select name="user_id">
                                @foreach ($available as $person)
                                    <option value="{{ (int) $person->id }}">{{ $person->name }}</option>
                                @endforeach
                            </select>
                        </label>
                        <button class="btn" type="submit">Assign</button>
                    </form>
                @else
                    <p class="muted small">Everybody in the organization is already on this contract.</p>
                @endif
            </div>
        </details>
    @endforeach

@endsection
