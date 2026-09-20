<?php

namespace App\Support;

use App\Models\Organization;
use Throwable;

/**
 * First-touch acquisition attribution.
 *
 * The UTM parameters captured on the visitor's first page view are stamped onto
 * the organization row at signup, so "which channel produced PAYING tenants" is
 * a database question rather than a guess. GA4 alone cannot join a click to
 * revenue.
 *
 * Capture happens on the marketing pages (Phase 14). This is only the stamp,
 * which registration needs now.
 *
 * @see docs/migration/marketing-content.md
 */
class Attribution
{
    public const SESSION_KEY = 'dp_utm';

    public static function stamp(int $organizationId): void
    {
        $utm = session(self::SESSION_KEY);

        if (! is_array($utm) || $utm === [] || $organizationId <= 0) {
            return;
        }

        try {
            Organization::query()->where('id', $organizationId)->update([
                'utm_source'   => $utm['utm_source'] ?? null,
                'utm_medium'   => $utm['utm_medium'] ?? null,
                'utm_campaign' => $utm['utm_campaign'] ?? null,
                'utm_content'  => $utm['utm_content'] ?? null,
                'utm_landing'  => $utm['landing'] ?? null,
            ]);
        } catch (Throwable) {
            // Attribution is reporting, never a reason to fail a signup.
        }
    }
}
