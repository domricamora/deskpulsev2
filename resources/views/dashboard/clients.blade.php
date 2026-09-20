@extends('layouts.app')

{{--
    Clients. Ported from server/templates/dashboard/clients.php.

    Contracts are edited in place, nested under the client they belong to, which
    is why this page renders as a list of <details> panels rather than a table.

    The legacy confirm() prompts were inline onsubmit attributes. They are
    data-confirm here and wired up in resources/js/dashboard.js — decision D13
    rules out script-src 'unsafe-inline'. The prompts themselves are unchanged.
--}}

@php
    $contractsByClient = [];

    foreach ($contracts as $contract) {
        $contractsByClient[(int) $contract->client_id][] = $contract;
    }
@endphp

@section('content')

    <div class="panel">
        <h3>Add a client</h3>
        <p class="muted">Clients are the companies your remote workers are placed with.
            Add a contact email and we'll create a read-only portal login for them with a
            temporary password — they'll be asked to set their own on first sign-in.</p>
        <form method="post" action="{{ url('/app/clients') }}" class="row-form">
            @csrf
            <input type="hidden" name="action" value="add_client">
            <label class="grow">Company name <input type="text" name="name" required></label>
            <label>Contact email <input type="email" name="contact_email"></label>
            <label class="grow">Notes <input type="text" name="notes"></label>
            <label>Bill rate <input type="number" name="bill_rate" step="0.01" min="0" value="0"></label>
            <label>Currency <input type="text" name="currency" value="USD" maxlength="8"></label>
            <button class="btn" type="submit">Add client</button>
        </form>
    </div>

    @if ($clients->isEmpty())
        <div class="panel"><div class="empty-state">No clients yet — add one above.</div></div>
    @endif

    @foreach ($clients as $client)
        @php
            $tracked = $rollup[(int) $client->id] ?? ['secs' => 0, 'amount' => 0];
            $clientContracts = $contractsByClient[(int) $client->id] ?? [];
            $login = $clientLogins[(int) $client->id] ?? null;
        @endphp

        <details class="org-panel">
            <summary>
                <span class="op-name"><b>{{ $client->name }}</b>
                    <span class="status {{ $client->archived ? 'rejected' : 'approved' }}">{{ $client->archived ? 'archived' : 'active' }}</span>
                    @if ($login)<span class="status approved">portal login</span>@endif
                </span>
                <span class="muted small">{{ \App\Support\Format::hms($tracked['secs']) }} tracked
                    · {{ \App\Support\Format::money($tracked['amount']) }} billable
                    · {{ count($clientContracts) }} contract{{ count($clientContracts) === 1 ? '' : 's' }}</span>
            </summary>

            <div class="op-body">
                <h4>Client details</h4>
                <form method="post" action="{{ url('/app/clients') }}" class="row-form">
                    @csrf
                    <input type="hidden" name="action" value="update_client">
                    <input type="hidden" name="client_id" value="{{ $client->id }}">
                    <label class="grow">Company name <input type="text" name="name" value="{{ $client->name }}" required></label>
                    <label>Contact email <input type="email" name="contact_email" value="{{ $client->contact_email }}"></label>
                    <label class="grow">Notes <input type="text" name="notes" value="{{ $client->notes }}"></label>
                    <label>Bill rate <input type="number" name="bill_rate" step="0.01" min="0" value="{{ $client->bill_rate ?? 0 }}"></label>
                    <label>Currency <input type="text" name="currency" value="{{ $client->currency ?? 'USD' }}" maxlength="8"></label>
                    <button class="btn sm" type="submit">Save</button>
                </form>

                <div class="actions-row">
                    <form method="post" action="{{ url('/app/clients') }}">
                        @csrf
                        <input type="hidden" name="action" value="{{ $client->archived ? 'unarchive_client' : 'archive_client' }}">
                        <input type="hidden" name="client_id" value="{{ $client->id }}">
                        <button class="btn sm ghost" type="submit">{{ $client->archived ? 'Restore' : 'Archive' }}</button>
                    </form>

                    <form method="post" action="{{ url('/app/clients') }}"
                          data-confirm="Delete this client and its contracts? Tracked time is kept but un-tagged.">
                        @csrf
                        <input type="hidden" name="action" value="delete_client">
                        <input type="hidden" name="client_id" value="{{ $client->id }}">
                        <button class="lnk danger" type="submit">delete client</button>
                    </form>
                </div>

                <h4>Portal login</h4>
                @if ($login)
                    <p class="muted small">Read-only portal access for <b>{{ $login }}</b>.
                        They sign in at the normal login page and are prompted to set a password on first use.</p>
                    <form method="post" action="{{ url('/app/clients') }}"
                          data-confirm="Reset this client's password? A new temporary password will be shown for you to share.">
                        @csrf
                        <input type="hidden" name="action" value="reset_client_password">
                        <input type="hidden" name="client_id" value="{{ $client->id }}">
                        <button class="btn sm ghost" type="submit">Reset password</button>
                    </form>
                @else
                    <p class="muted small">No portal login yet for this client.</p>
                    <form method="post" action="{{ url('/app/clients') }}" class="row-form">
                        @csrf
                        <input type="hidden" name="action" value="create_client_login">
                        <input type="hidden" name="client_id" value="{{ $client->id }}">
                        <label class="grow">Login email
                            <input type="email" name="contact_email" value="{{ $client->contact_email }}" required></label>
                        <button class="btn sm" type="submit">Create login</button>
                    </form>
                @endif

                <h4>Contracts</h4>
                <div class="task-list">
                    @forelse ($clientContracts as $contract)
                        <div class="task-row">
                            <form method="post" action="{{ url('/app/clients') }}" class="task-edit">
                                @csrf
                                <input type="hidden" name="action" value="update_contract">
                                <input type="hidden" name="contract_id" value="{{ $contract->id }}">
                                <input type="text" name="title" value="{{ $contract->title }}" aria-label="Contract title">
                                <select name="status" aria-label="Status">
                                    <option value="active" @selected($contract->status !== 'ended')>active</option>
                                    <option value="ended" @selected($contract->status === 'ended')>ended</option>
                                </select>
                                <label class="dlabel">Start <input type="date" name="start_date" value="{{ $contract->start_date?->format('Y-m-d') }}"></label>
                                <label class="dlabel">End <input type="date" name="end_date" value="{{ $contract->end_date?->format('Y-m-d') }}"></label>
                                <label>Bill rate {{ $contract->currency }}
                                    <input type="number" name="bill_rate" step="0.01" min="0" class="rate-input"
                                           value="{{ $contract->bill_rate ?? 0 }}"></label>
                                <button class="btn sm" type="submit">Save</button>
                            </form>
                            <form method="post" action="{{ url('/app/clients') }}" class="task-del"
                                  data-confirm="Delete this contract?">
                                @csrf
                                <input type="hidden" name="action" value="delete_contract">
                                <input type="hidden" name="contract_id" value="{{ $contract->id }}">
                                <button class="lnk danger" type="submit">delete</button>
                            </form>
                        </div>
                    @empty
                        <p class="muted small">No contracts for this client.</p>
                    @endforelse
                </div>

                <form method="post" action="{{ url('/app/clients') }}" class="row-form">
                    @csrf
                    <input type="hidden" name="action" value="add_contract">
                    <input type="hidden" name="client_id" value="{{ $client->id }}">
                    <label class="grow">New contract <input type="text" name="title" placeholder="e.g. Q3 Support Retainer" required></label>
                    <label>Status <select name="status">
                            <option value="active">active</option>
                            <option value="ended">ended</option>
                        </select></label>
                    <label class="dlabel">Start <input type="date" name="start_date"></label>
                    <label class="dlabel">End <input type="date" name="end_date"></label>
                    <label>Bill rate
                        <input type="number" name="bill_rate" step="0.01" min="0" class="rate-input"
                               placeholder="{{ $client->bill_rate ?? 0 }}"></label>
                    <button class="btn" type="submit">Add contract</button>
                </form>
            </div>
        </details>
    @endforeach

@endsection
