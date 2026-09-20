@extends('layouts.app')

{{--
    Pay adjustments. Ported from server/templates/dashboard/adjustments.php.

    The amount field only accepts a positive number; the KIND carries the sign.
    Two ways to express a deduction would eventually produce one entered wrong.

    The inline confirm() is data-confirm here and the amount colour is a class,
    both for decision D13. The prompt wording is unchanged.
--}}

@php
    $earnings = 0.0;
    $deductions = 0.0;

    foreach ($rows as $row) {
        if ((int) $row->sign < 0) {
            $deductions += (float) $row->amount;
        } else {
            $earnings += (float) $row->amount;
        }
    }
@endphp

@section('content')

    <x-period-switch :ctx="$period" :periods="$periods" path="/app/adjustments" />

    <div class="stat-row">
        <div class="stat"><div class="lbl">Extra earnings</div><div>{{ \App\Support\Format::money($earnings, $currency) }}</div>
            <div class="sub">bonuses, commissions, reimbursements</div></div>
        <div class="stat"><div class="lbl">Deductions</div><div>{{ \App\Support\Format::money($deductions, $currency) }}</div></div>
        <div class="stat"><div class="lbl">Net effect on payroll</div>
            <div>{{ \App\Support\Format::money($earnings - $deductions, $currency) }}</div>
            <div class="sub">{{ $period['label'] }}</div></div>
        <div class="stat"><div class="lbl">Entries</div><div>{{ count($rows) }}</div></div>
    </div>

    <div class="panel">
        <h3>Add an adjustment</h3>
        <p class="muted">Anything that isn't hours &times; rate: a bonus, sales commission, expense
            reimbursement, allowance — or a deduction. The <b>effective date</b> decides which pay period
            it lands in, and it flows straight through to the payslip and the salary run.</p>
        <form method="post" action="{{ url('/app/adjustments') }}" class="stack">
            @csrf
            <input type="hidden" name="action" value="create">
            <div class="inline">
                <label>Employee <select name="user_id" required>
                        <option value="">Choose…</option>
                        @foreach ($members as $member)
                            <option value="{{ (int) $member->id }}">{{ $member->name }}</option>
                        @endforeach
                    </select></label>
                <label>Type <select name="kind">
                        @foreach ($kinds as $key => [$label, $sign])
                            <option value="{{ $key }}">{{ $label }}{{ $sign < 0 ? ' (subtracts)' : '' }}</option>
                        @endforeach
                    </select></label>
                <label>Amount <input type="number" name="amount" step="0.01" min="0" required></label>
                <label>Currency <input type="text" name="currency" maxlength="8" value="{{ $currency }}"></label>
            </div>
            <div class="inline">
                <label class="grow">Description
                    <input type="text" name="label" maxlength="160" placeholder="e.g. Q3 performance bonus"></label>
                <label>Effective date
                    <input type="date" name="effective_date" value="{{ $period['end_date'] }}" required></label>
            </div>
            <label class="check"><input type="checkbox" name="taxable" checked> Taxable</label>
            <label>Note <textarea name="note" rows="2" placeholder="Optional — why this was awarded"></textarea></label>
            <button class="btn" type="submit">Add adjustment</button>
        </form>
    </div>

    <div class="panel">
        <h3>Adjustments in {{ $period['label'] }}</h3>
        @if (! count($rows))
            <div class="empty-state">No adjustments recorded for this period.</div>
        @else
            <table class="data">
                <thead>
                    <tr><th>Date</th><th>Employee</th><th>Type</th><th>Description</th>
                        <th>Amount</th><th>Taxable</th><th>Added by</th><th></th></tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        @php $negative = (int) $row->sign < 0; @endphp
                        <tr>
                            <td data-sort="{{ $row->effective_date }}">{{ date('j M Y', strtotime($row->effective_date)) }}</td>
                            <td>{{ $row->user_name }}</td>
                            <td><span class="tag">{{ $kinds[$row->kind][0] ?? ucfirst($row->kind) }}</span></td>
                            <td>{{ $row->label }}
                                @if ($row->note)<br><small class="muted">{{ $row->note }}</small>@endif
                            </td>
                            <td data-sort="{{ ($negative ? '-' : '') . $row->amount }}"
                                @class(['negative' => $negative, 'positive' => ! $negative])>
                                {{ $negative ? '-' : '+' }}{{ \App\Support\Format::money($row->amount, $row->currency) }}</td>
                            <td>{{ $row->taxable ? 'Yes' : 'No' }}</td>
                            <td class="small muted">{{ $row->created_by ?? '—' }}</td>
                            <td>
                                <form method="post" action="{{ url('/app/adjustments') }}" class="inline-form"
                                    data-confirm="Remove this adjustment?">
                                    @csrf
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="adjustment_id" value="{{ (int) $row->id }}">
                                    <button class="lnk danger" type="submit">remove</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

@endsection
