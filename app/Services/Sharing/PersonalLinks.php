<?php

namespace App\Services\Sharing;

use App\Models\ShareLink;
use App\Models\User;
use App\Support\Token;

/**
 * Every member gets a personal share link — a public, read-only timesheet URL
 * they can hand to a client without giving out a login.
 *
 * Created on signup and whenever the roster changes, so nobody has to remember
 * to mint one. A revoked link is not replaced silently: the query only skips
 * people who still have a live one.
 *
 * Phase 5 calls this from registration, which is where the legacy signup calls
 * ensure_personal_links(). The sharing feature itself lands in Phase 14.
 *
 * @see docs/migration/sharing.md
 */
class PersonalLinks
{
    public function ensureFor(int $organizationId): void
    {
        $missing = User::query()
            ->where('org_id', $organizationId)
            ->whereNotExists(function ($query) {
                $query->select('id')
                    ->from('share_links')
                    ->whereColumn('share_links.target_id', 'users.id')
                    ->whereColumn('share_links.org_id', 'users.org_id')
                    ->where('share_links.scope', 'user')
                    ->where('share_links.revoked', 0);
            })
            ->get(['id', 'name']);

        foreach ($missing as $user) {
            ShareLink::create([
                'org_id'         => $organizationId,
                'scope'          => 'user',
                'target_id'      => $user->id,
                'token'          => Token::random(18),
                'label'          => $user->name . ' — personal',
                'period_default' => 'day',
            ]);
        }
    }
}
