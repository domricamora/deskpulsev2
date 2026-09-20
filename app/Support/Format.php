<?php

namespace App\Support;

use App\Models\User;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Display formatting, ported from server/src/helpers.php.
 *
 * The non-breaking spaces are not decoration. "USD 1,234.00" and "3h 05m" each
 * have to stay on one line inside a narrow stat card or table cell; a normal
 * space lets the browser break them in half. The payslip PDF renderer maps
 * U+00A0 back to a normal space, and CSV exports format their own numbers, so
 * nothing downstream inherits the character.
 */
class Format
{
    /** "3h 05m", or "45m" under an hour. Ports fmt_hms(). */
    public static function hms(int|float|string|null $seconds): string
    {
        $seconds = (int) $seconds;
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        return $hours
            ? $hours . "h\u{00A0}" . sprintf('%02dm', $minutes)
            : $minutes . 'm';
    }

    /** "USD 1,234.00". Display only — never used to build a CSV. */
    public static function money(int|float|string|null $amount, ?string $currency = 'USD'): string
    {
        return ($currency ?: 'USD') . "\u{00A0}" . number_format((float) $amount, 2);
    }

    /** Hours to one decimal, as the charts and rollups show them. */
    public static function hours(int|float|null $seconds): string
    {
        return number_format(((int) $seconds) / 3600, 1);
    }

    /** ISO weekday number (1=Mon..7=Sun) → short label. */
    public static function weekday(string|int $number): string
    {
        return [
            '1' => 'Mon', '2' => 'Tue', '3' => 'Wed', '4' => 'Thu',
            '5' => 'Fri', '6' => 'Sat', '7' => 'Sun',
        ][(string) $number] ?? (string) $number;
    }

    /**
     * "Mon, Tue, Wed, Thu, Fri · 09:00–17:00", or "Not set".
     *
     * Ports work_schedule_label(). A schedule needs all three parts to mean
     * anything, so any one of them missing reads as unset.
     */
    public static function workSchedule(User $user): string
    {
        $start = $user->work_start;
        $end = $user->work_end;
        $days = array_values(array_filter(explode(',', (string) $user->work_days)));

        if (! $start || ! $end || ! $days) {
            return 'Not set';
        }

        return implode(', ', array_map([self::class, 'weekday'], $days))
            . ' · ' . substr((string) $start, 0, 5) . '–' . substr((string) $end, 0, 5);
    }

    /**
     * Is the given moment inside the user's standard schedule? null when no
     * schedule is set.
     *
     * Ports within_work_schedule(). Read in the ORGANIZATION's reporting
     * timezone, which is the same clock {@see \App\Services\Reporting\Overtime}
     * splits overtime on — the badge and the money must agree, or the overview
     * says someone is inside their hours while the calculation bills the time
     * as overtime.
     *
     * Both used to read server-local wall-clock, so both answered for the
     * host's timezone rather than the tenant's. Corrected together (decision
     * D2), as they must be.
     *
     * A window that wraps midnight still reads as empty, here and in the
     * overtime split alike. See that class for why it stays that way.
     */
    public static function withinWorkSchedule(User $user, string $timezone = 'UTC', ?int $timestamp = null): ?bool
    {
        $start = $user->work_start;
        $end = $user->work_end;
        $days = array_values(array_filter(explode(',', (string) $user->work_days)));

        if (! $start || ! $end || ! $days) {
            return null;
        }

        try {
            $moment = (new DateTimeImmutable('@' . ($timestamp ?? time())))
                ->setTimezone(new DateTimeZone($timezone));
        } catch (\Exception) {
            return null;
        }

        if (! in_array((string) ((int) $moment->format('N')), $days, true)) {
            return false;
        }

        $now = $moment->format('H:i:s');

        return $now >= $start && $now <= $end;
    }
}
