<?php

namespace App\Support;

/**
 * Employment vocabulary, ported from server/src/payroll.php.
 *
 * The Team page writes these and the Payroll pages read them, so they are
 * shared rather than defined at either end.
 */
class Employment
{
    /** @return array<string, string> */
    public static function types(): array
    {
        return [
            'full_time'  => 'Full time',
            'part_time'  => 'Part time',
            'contractor' => 'Contractor',
        ];
    }

    public static function label(?string $key): string
    {
        return self::types()[$key ?? 'full_time'] ?? 'Full time';
    }
}
