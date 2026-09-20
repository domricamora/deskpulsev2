<?php

namespace App\View\Composers;

use App\Enums\Capability;
use App\Models\Organization;
use App\Models\WorkSession;
use App\Support\Navigation;
use App\Support\Visibility;
use Illuminate\View\View;

/**
 * Everything the app shell needs, supplied once instead of by every controller.
 *
 * Ports the data half of nav_context(). The legacy function merges its result
 * into each page's view variables by hand, which means a new page that forgets
 * the merge renders a sidebar with no badges and no acting-as banner. A
 * composer cannot be forgotten.
 *
 * ## What nav_context() also did, and where it went
 *
 * | Legacy call | Where it lives now |
 * |---|---|
 * | `require_approved_org()` | `EnsureOrganizationApproved` middleware (Phase 5) |
 * | `close_stale_sessions()` | Phase 9 — needs the monitoring pipeline |
 * | `recompute_pending_overtime()` | Phase 9 — same |
 * | onboarding / welcome redirects | Deferred with their pages; see below |
 * | `mail_maybe_flush()` | Phase 14 — the queue drain belongs with the mailer |
 * | `notices_for_user()` | With the messaging pages |
 *
 * The three first-run redirects (company admin to `/app/onboarding`, team
 * manager to `/app/onboarding`, everyone else to `/app/welcome`) are
 * deliberately NOT ported yet: their destinations do not exist, so porting the
 * redirect would send a brand-new organization to a 404 on its first sign-in.
 * They land with those pages.
 */
class NavigationComposer
{
    public function compose(View $view): void
    {
        $user = auth()->user();

        // The layout sits behind `auth`, so in the product there is always a
        // user. Returning early without the variables would still turn any
        // other render — the style guide, a future error page — into a 500
        // three levels down inside a foreach. An empty shell is the honest
        // answer instead.
        if (! $user) {
            $view->with([
                'navUser'     => null,
                'navGroups'   => [],
                'navPlatform' => [],
                'navBadges'   => ['approvals' => 0, 'overtime' => 0],
                'actingOrg'   => null,
                'orgLogo'     => null,
            ]);

            return;
        }

        $organizationId = $user->effectiveOrgId();

        $view->with([
            'navUser'     => $user,
            'navGroups'   => Navigation::forUser($user),
            'navPlatform' => $user->isSuperAdmin() && ! $user->isActingAsOrganization()
                ? Navigation::platformGroup()
                : [],
            'navBadges'   => $this->badges($user),
            'actingOrg'   => $this->actingOrganizationName($user),
            'orgLogo'     => $this->logoUrl($organizationId),
        ]);
    }

    /**
     * The two counts the sidebar shows as pills: time entries and overtime
     * waiting on this viewer.
     *
     * Each is scoped to the people this viewer can see, so a team manager's
     * badge counts their team's queue and not the organization's.
     *
     * @return array{approvals: int, overtime: int}
     */
    private function badges($user): array
    {
        $badges = ['approvals' => 0, 'overtime' => 0];

        $approveTime = $user->hasCapability(Capability::ApproveTime);
        $approveOvertime = $user->hasCapability(Capability::ApproveOvertime);

        if (! $approveTime && ! $approveOvertime) {
            return $badges;
        }

        $ids = Visibility::userIds($user);

        if ($approveTime) {
            $badges['approvals'] = WorkSession::query()
                ->whereIn('user_id', $ids)
                ->where('approval_status', 'pending')
                ->count();
        }

        if ($approveOvertime) {
            $badges['overtime'] = WorkSession::query()
                ->whereIn('user_id', $ids)
                ->where('overtime_status', 'pending')
                ->count();
        }

        return $badges;
    }

    /**
     * The tenant name for the act-as banner, or null.
     *
     * A platform operator looking at a customer's data must always be able to
     * tell that is what they are doing.
     */
    private function actingOrganizationName($user): ?string
    {
        if (! $user->isActingAsOrganization()) {
            return null;
        }

        return Organization::query()
            ->whereKey($user->effectiveOrgId())
            ->value('name');
    }

    /** Company branding, shown in the sidebar to everyone in the organization. */
    private function logoUrl(int $organizationId): ?string
    {
        $path = Organization::query()
            ->whereKey($organizationId)
            ->value('logo_path');

        // Decision D4/D5 move private media off the public path; branding is
        // deliberately public and stays where it is.
        return $path ? url('/uploads/' . $path) : null;
    }
}
