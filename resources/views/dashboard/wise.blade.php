@extends('layouts.app')

{{--
    Wise payout details. Ported from server/templates/dashboard/wise.php.

    The import matches against people who already exist and never creates any.
    Unmatched rows are listed rather than swallowed — a payout sheet naming
    somebody this organization has never heard of is worth a human's attention.
--}}

@section('content')

    <div class="panel">
        <h3>Import payout details <span class="tag">from a Wise export</span></h3>
        <p class="muted">Upload the payout sheet you already keep in Wise (<code>.xlsx</code> or
            <code>.csv</code>). Rows are matched to people who already exist in this organization —
            <b>nobody is created</b>. Anything that cannot be matched is listed for you to check.</p>
        <form method="post" action="{{ url('/app/wise') }}" class="row-form" enctype="multipart/form-data">
            @csrf
            <input type="hidden" name="action" value="preview">
            <label class="grow">Payout file <input type="file" name="file" accept=".xlsx,.csv,.tsv" required></label>
            <button class="btn" type="submit">Preview</button>
        </form>
    </div>

    <x-import-guide :spec="$specs['wise']" spec-key="wise" :open="! $preview" />

    @if ($preview)
        @php $summary = $preview['summary']; @endphp

        <div class="panel">
            <h3>Preview <span class="muted normal">— nothing has been saved yet</span></h3>
            <p class="muted">Read from sheet <b>{{ $preview['sheet'] }}</b> · {{ $preview['total'] }} row(s)
                @if ($preview['skipped']['count'] > 0), {{ $preview['skipped']['count'] }} skipped @endif.</p>

            <div class="stat-row">
                <div class="stat"><span class="lbl">Matched</span><b>{{ $summary['matched'] }}</b>
                    <span class="sub">{{ $summary['created'] }} new · {{ $summary['updated'] }} updated</span></div>
                <div @class(['stat', 'alert' => $summary['unmatched'] > 0])>
                    <span class="lbl">Unmatched</span><b>{{ $summary['unmatched'] }}</b>
                    <span class="sub">not imported</span></div>
            </div>

            @if ($summary['matches'])
                <h4>How each row matched</h4>
                <table class="data">
                    <thead><tr><th>Sheet name</th><th>Matched to</th><th>On</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($summary['matches'] as $match)
                            <tr>
                                <td>{{ $match['holder'] }}</td>
                                <td>{{ $match['name'] }}</td>
                                <td><span class="tag">{{ $match['how'] }}</span></td>
                                <td><span class="status {{ $match['new'] ? 'approved' : 'pending' }}">{{ $match['new'] ? 'new' : 'update' }}</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif

            @if ($summary['unmatched_names'])
                <p class="muted"><b>Not matched to anyone here:</b>
                    {{ implode(', ', $summary['unmatched_names']) }}
                    @if ($summary['unmatched'] > count($summary['unmatched_names'])) … @endif
                </p>
            @endif

            <form method="post" action="{{ url('/app/wise') }}" class="confirm-row"
                data-confirm="Save these payout details now?">
                @csrf
                <input type="hidden" name="action" value="commit">
                <input type="hidden" name="token" value="{{ $preview['token'] }}">
                <input type="hidden" name="ext" value="{{ $preview['ext'] }}">
                <button class="btn" type="submit">Confirm import</button>
                <a class="lnk spaced" href="{{ url('/app/wise') }}">Cancel</a>
            </form>
        </div>
    @endif

    <div class="panel">
        <h3>Payout details</h3>
        <p class="muted">One account per employee. A person with no details, or with them switched off,
            is left out of the salary-run batch and named on that page.</p>
        <table class="data">
            <thead>
                <tr><th>Employee</th><th>Account holder</th><th>Recipient ID</th><th>Account</th>
                    <th>Currencies</th><th>Type</th><th>Status</th><th></th></tr>
            </thead>
            <tbody>
                @foreach ($people as $person)
                    @php $account = $accounts->get($person->id); @endphp
                    <tr>
                        <td>{{ $person->name }}<br><small class="muted">{{ $person->role?->label() }}</small></td>
                        <td>{{ $account->account_holder ?? '—' }}</td>
                        <td class="small"><code>{{ $account->recipient_id ?? '—' }}</code></td>
                        <td class="small">{{ $account->account_summary ?? '—' }}</td>
                        <td class="small">{{ $account ? $account->source_currency . ' → ' . $account->target_currency : '—' }}</td>
                        <td>{{ $account->recipient_type ?? '—' }}</td>
                        <td>
                            <span class="status {{ $account && $account->active ? 'approved' : 'rejected' }}">
                                {{ $account ? ($account->active ? 'ready' : 'off') : 'not set' }}</span>
                        </td>
                        <td>
                            <details>
                                <summary class="lnk">edit</summary>
                                <form method="post" action="{{ url('/app/wise') }}" class="stack edit-form">
                                    @csrf
                                    <input type="hidden" name="action" value="save">
                                    <input type="hidden" name="user_id" value="{{ (int) $person->id }}">
                                    <label>Account holder
                                        <input type="text" name="account_holder" value="{{ $account->account_holder ?? $person->name }}"></label>
                                    <label>Wise recipient ID
                                        <input type="text" name="recipient_id" value="{{ $account->recipient_id ?? '' }}"></label>
                                    <label>Email <input type="email" name="email" value="{{ $account->email ?? '' }}"></label>
                                    <label>Account summary
                                        <input type="text" name="account_summary" value="{{ $account->account_summary ?? '' }}"></label>
                                    <div class="inline">
                                        <label>From <input type="text" name="source_currency" maxlength="8"
                                                value="{{ $account->source_currency ?? 'USD' }}"></label>
                                        <label>To <input type="text" name="target_currency" maxlength="8"
                                                value="{{ $account->target_currency ?? 'USD' }}"></label>
                                        <label>Type <select name="recipient_type">
                                                <option value="PERSON" @selected(($account->recipient_type ?? 'PERSON') === 'PERSON')>PERSON</option>
                                                <option value="BUSINESS" @selected(($account->recipient_type ?? '') === 'BUSINESS')>BUSINESS</option>
                                            </select></label>
                                    </div>
                                    <label class="check"><input type="checkbox" name="active"
                                            @checked(! $account || $account->active)> Include in salary runs</label>
                                    <button class="btn sm" type="submit">Save</button>
                                </form>
                                @if ($account)
                                    <form method="post" action="{{ url('/app/wise') }}" class="inline-form"
                                        data-confirm="Remove these payout details?">
                                        @csrf
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="wise_id" value="{{ (int) $account->id }}">
                                        <button class="lnk danger" type="submit">remove</button>
                                    </form>
                                @endif
                            </details>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

@endsection
