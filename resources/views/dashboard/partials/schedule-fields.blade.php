{{--
    The standard work schedule, shared by both Team edit forms.

    Ports the $scheduleFields closure in server/templates/dashboard/team.php.
    Days default to Mon–Fri when the member has none set, which is the same
    default the controller writes back when every box is cleared.
--}}
<div class="inline">
    <label>Work start <input type="time" name="work_start" value="{{ substr((string) ($member->work_start ?? ''), 0, 5) }}"></label>
    <label>Work end <input type="time" name="work_end" value="{{ substr((string) ($member->work_end ?? ''), 0, 5) }}"></label>
</div>
<div class="sched-days"><span class="muted small">Work days</span>
    @foreach ($weekdays as $number => $label)
        <label class="check">
            <input type="checkbox" name="work_days[]" value="{{ $number }}"
                   @checked(in_array((string) $number, $workDays, true))> {{ $label }}
        </label>
    @endforeach
</div>
