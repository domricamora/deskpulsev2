{{--
    Employment type and contractual hour caps, shared by both Team edit forms.

    Ports the $employmentFields closure in server/templates/dashboard/team.php.
    The caps are advisory: hours over a cap still track and still pay, but the
    member is flagged on the Payroll page so someone reviews them before the
    run. 0 means no cap.
--}}
<div class="inline">
    <label>Employment type
        <select name="employment_type">
            @foreach ($employmentTypes as $key => $label)
                <option value="{{ $key }}" @selected(($member->employment_type ?? 'full_time') === $key)>{{ $label }}</option>
            @endforeach
        </select></label>
    <label>Hired on <input type="date" name="hired_on" value="{{ $member->hired_on?->format('Y-m-d') }}"></label>
</div>
<div class="inline">
    <label>Daily cap <input type="number" step="0.25" min="0" name="daily_hours_cap"
                            value="{{ $capValue($member->daily_hours_cap ?? 0) }}">
        <small class="muted">hours · 0 = none</small></label>
    <label>Weekly cap <input type="number" step="0.25" min="0" name="weekly_hours_cap"
                             value="{{ $capValue($member->weekly_hours_cap ?? 0) }}">
        <small class="muted">hours · 0 = none</small></label>
    <label>Pay-period cap <input type="number" step="0.25" min="0" name="period_hours_cap"
                                 value="{{ $capValue($member->period_hours_cap ?? 0) }}">
        <small class="muted">hours · 0 = none</small></label>
</div>
