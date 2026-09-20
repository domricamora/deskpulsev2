<?php

namespace App\Http\Controllers\Dashboard;

use App\Enums\Capability;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\Organization;
use App\Models\ShareLink;
use App\Services\Agent\MonitoringPolicy;
use App\Services\Oidc\ProviderRegistry;
use App\Services\Sharing\PersonalLinks;
use App\Support\Flash;
use App\Support\LegacyCipher;
use App\Support\Period;
use App\Support\Token;
use App\Support\Uploads;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * `/app/settings` — the desktop agent, company branding, period policy,
 * monitoring policy, single sign-on and connected accounts.
 *
 * **Not a capability route.** It needs only a login, and then branches: a
 * manager is bounced home (they have no Settings page) and `org_settings`
 * opens the four admin panels. That in-page branching is the second layer
 * `authorization.md` §2 describes, and a `cap:` middleware alone would not
 * reproduce it.
 *
 * ## Two refusals that protect an administrator from themselves
 *
 * SSO cannot be ENFORCED unless it is first ENABLED, and cannot be enabled
 * without an issuer and a client id. Enforcing single sign-on that has never
 * been proven to work locks every administrator out of their own workspace,
 * and that is not a recoverable mistake from inside the product.
 *
 * ## A blank client secret means "keep it"
 *
 * The field renders masked, so an empty submit is somebody saving the other
 * fields — not an instruction to wipe the secret.
 *
 * ## The two share-link actions have no form
 *
 * `share_create` and `share_revoke` are handled here because the legacy
 * handler handles them, but nothing in the UI posts them — `/app/share-links`
 * is a read-only directory. They are kept so a bookmarked POST behaves the
 * same, not because a button reaches them.
 *
 * @see docs/migration/routes.md §2
 */
class SettingsController extends Controller
{
    public function __construct(
        private readonly PersonalLinks $links,
        private readonly MonitoringPolicy $policy,
        private readonly ProviderRegistry $providers,
        private readonly Period $period,
    ) {}

    public function show(Request $request)
    {
        $user = $request->user();

        // Managers have no Settings page. Home with a notice, not a 403.
        if ($user->role === UserRole::Manager) {
            Flash::error("You don't have access to that page.");

            return redirect('/app');
        }

        $organizationId = (int) $user->effectiveOrgId();

        // is_manager() — anybody with organization or team scope. Minting the
        // missing personal links on this page view is how the legacy keeps a
        // roster that grew since signup covered.
        if ($user->hasCapability(Capability::ViewAll) || $user->hasCapability(Capability::ViewTeam)) {
            $this->links->ensureFor($organizationId);
        }

        $organization = Organization::query()->whereKey($organizationId)->first();

        return view('dashboard.settings', [
            'title'        => 'Settings',
            'active'       => 'settings',
            'me'           => $user->fresh(),
            'devices'      => Device::query()
                ->where('user_id', $user->id)
                ->orderByDesc('created_at')
                ->get(),
            'publicBase'   => rtrim(url('/'), '/'),

            // The composer supplies this to the LAYOUT; the body needs its own
            // copy, because a composer does not reach @section('content').
            'orgLogo'      => $organization?->logo_path ? Uploads::url($organization->logo_path) : null,
            'policy'       => $this->policy->for($organizationId),
            'periodConfig' => $this->periodContext($organizationId),
            'isAdmin'      => $user->hasCapability(Capability::OrgSettings),
            'sso'          => $organization,
            'identities'   => DB::table('user_identities')
                ->where('user_id', $user->id)
                ->orderBy('provider')
                ->get(['provider', 'email', 'created_at', 'last_login_at']),
            'oauthProviders' => $this->providers->providers(),
        ]);
    }

    public function update(Request $request)
    {
        $user = $request->user();

        abort_if($user->role === UserRole::Manager, 403, 'Not allowed');

        $organizationId = (int) $user->effectiveOrgId();

        $result = match ((string) $request->input('action', '')) {
            'share_create'  => $this->createShareLink($request, $organizationId),
            'share_revoke'  => $this->revokeShareLink($request, $organizationId),
            'device_delete' => $this->deleteDevice($request),
            'policy'        => $this->savePolicy($request, $organizationId),
            'sso'           => $this->saveSso($request, $organizationId),
            'period_policy' => $this->savePeriodPolicy($request, $organizationId),
            'upload_logo'   => $this->uploadLogo($request, $organizationId),
            'remove_logo'   => $this->removeLogo($request, $organizationId),
            default         => null,
        };

        return $result ?? redirect('/app/settings');
    }

    /* ── Share links (view_all) ──────────────────────────────────────────── */

    private function createShareLink(Request $request, int $organizationId)
    {
        $this->requireShare($request);

        $scope = in_array($request->input('scope'), ['user', 'team', 'org'], true)
            ? (string) $request->input('scope')
            : 'user';

        ShareLink::create([
            'org_id'         => $organizationId,
            'scope'          => $scope,
            'target_id'      => $scope === 'org' ? null : (int) $request->input('target_id', $request->user()->id),
            'token'          => Token::random(18),
            'label'          => substr((string) $request->input('label', ''), 0, 160),
            'period_default' => in_array($request->input('period'), ['day', 'week', 'month'], true)
                ? (string) $request->input('period')
                : 'week',
            'expires_at'     => $request->filled('expires_at')
                ? date('Y-m-d H:i:s', strtotime((string) $request->input('expires_at')))
                : null,
        ]);

        Flash::success('Public share link created.');

        return null;
    }

    private function revokeShareLink(Request $request, int $organizationId)
    {
        $this->requireShare($request);

        // Revoked, never deleted: the row is what makes a leaked URL answer
        // 404 rather than leaving the token free to be minted again.
        ShareLink::query()
            ->whereKey((int) $request->input('link_id', 0))
            ->where('org_id', $organizationId)
            ->update(['revoked' => 1]);

        Flash::success('Share link revoked.');

        return null;
    }

    /* ── Own devices (any signed-in user) ────────────────────────────────── */

    private function deleteDevice(Request $request)
    {
        // Scoped to the OWNER, not the organization: this is somebody removing
        // their own install, not an admin revoking someone else's. /app/devices
        // is where that happens, and it needs the `devices` capability.
        Device::query()
            ->whereKey((int) $request->input('device_id', 0))
            ->where('user_id', $request->user()->id)
            ->delete();

        Flash::success('Device removed. That copy of the desktop app will need to sign in again.');

        return null;
    }

    /* ── Organization settings (org_settings) ────────────────────────────── */

    private function savePolicy(Request $request, int $organizationId)
    {
        $this->requireConfigure($request);

        Organization::query()->whereKey($organizationId)->update([
            // Clamped, because these drive what the agent does on somebody's
            // machine and a typo should not mean a screenshot every second.
            'screenshot_interval_min' => max(1, min(120, (int) $request->input('screenshot_interval_min', 10))),
            'screenshot_blur'         => $request->has('screenshot_blur') ? 1 : 0,
            'idle_threshold_min'      => max(1, min(120, (int) $request->input('idle_threshold_min', 15))),
            'sync_interval_s'         => max(15, min(600, (int) $request->input('sync_interval_s', 60))),
            'track_screenshots'       => $request->has('track_screenshots') ? 1 : 0,
            'track_windows'           => $request->has('track_windows') ? 1 : 0,
            'track_processes'         => $request->has('track_processes') ? 1 : 0,
        ]);

        Flash::success('Monitoring policy saved. Agents apply it on the next session.');

        return null;
    }

    private function saveSso(Request $request, int $organizationId)
    {
        $this->requireConfigure($request);

        $issuer = trim((string) $request->input('sso_issuer', ''));

        // Discovery drives every downstream endpoint, so the issuer must be
        // HTTPS — an http:// issuer would leak the whole exchange.
        if ($issuer !== '' && ! preg_match('~^https://[\w.-]+(/[\w./-]*)?$~', $issuer)) {
            Flash::error('The issuer URL must be an https:// address.');

            return redirect('/app/settings');
        }

        $clientId = substr(trim((string) $request->input('sso_client_id', '')), 0, 255);
        $enabled = $request->has('sso_enabled') ? 1 : 0;
        $enforce = $request->has('sso_enforce') ? 1 : 0;

        // Refuse to enforce single sign-on that has not been proven to work.
        if ($enforce && ! $enabled) {
            Flash::error('Enable single sign-on before enforcing it.');

            return redirect('/app/settings');
        }

        if ($enabled && ($issuer === '' || $clientId === '')) {
            Flash::error('An issuer URL and client ID are required to enable single sign-on.');

            return redirect('/app/settings');
        }

        $domains = implode(',', array_filter(array_map(
            fn (string $domain) => preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', trim($domain)) ? trim($domain) : '',
            explode(',', strtolower(substr(trim((string) $request->input('sso_domains', '')), 0, 255)))
        )));

        $secret = trim((string) $request->input('sso_client_secret', ''));

        // Blank means keep: the field renders masked, so an empty submit is a
        // save of the other fields.
        if ($secret !== '') {
            Organization::query()->whereKey($organizationId)
                ->update(['sso_client_secret' => LegacyCipher::encrypt($secret)]);
        }

        Organization::query()->whereKey($organizationId)->update([
            'sso_enabled'   => $enabled,
            'sso_issuer'    => $issuer ?: null,
            'sso_client_id' => $clientId ?: null,
            'sso_domains'   => $domains ?: null,
            'sso_enforce'   => $enforce,
        ]);

        Flash::success($enabled
            ? 'Single sign-on saved. Test it in a private window before enforcing it.'
            : 'Single sign-on disabled.');

        return null;
    }

    private function savePeriodPolicy(Request $request, int $organizationId)
    {
        $this->requireConfigure($request);

        $timezone = (string) $request->input('report_tz', 'UTC');

        if (! in_array($timezone, timezone_identifiers_list(), true)) {
            $timezone = 'UTC';
        }

        $cycle = (string) $request->input('pay_cycle', 'semimonthly');

        if (! in_array($cycle, ['weekly', 'biweekly', 'semimonthly', 'rolling15', 'monthly'], true)) {
            $cycle = 'semimonthly';
        }

        $anchor = (string) $request->input('pay_cycle_anchor', '');

        Organization::query()->whereKey($organizationId)->update([
            // This is the clock every window, every pay run and every overtime
            // split is cut in. Changing it restates figures that are already
            // on somebody's payslip.
            'report_tz'        => $timezone,
            'week_start'       => max(1, min(7, (int) $request->input('week_start', 1))),
            'pay_cycle'        => $cycle,
            'pay_cycle_anchor' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $anchor) ? $anchor : null,
            'pay_currency'     => strtoupper(substr(trim((string) $request->input('pay_currency', 'USD')), 0, 8)) ?: 'USD',
        ]);

        // Period memoises per request; the redirect re-reads.
        $this->period->flush();

        Flash::success('Reporting and payroll period settings saved.');

        return null;
    }

    private function uploadLogo(Request $request, int $organizationId)
    {
        $this->requireConfigure($request);

        [$relative, $error] = Uploads::storeLogoWebp($request->file('logo'), 'org' . $organizationId);

        if (! $relative) {
            Flash::error($error ?: 'Could not upload the logo.');

            return null;
        }

        $previous = Organization::query()->whereKey($organizationId)->value('logo_path');

        Organization::query()->whereKey($organizationId)->update(['logo_path' => $relative]);

        if ($previous && $previous !== $relative) {
            @unlink(Uploads::path($previous));
        }

        Flash::success('Company logo updated.');

        return null;
    }

    private function removeLogo(Request $request, int $organizationId)
    {
        $this->requireConfigure($request);

        $previous = Organization::query()->whereKey($organizationId)->value('logo_path');

        if ($previous) {
            @unlink(Uploads::path($previous));
        }

        Organization::query()->whereKey($organizationId)->update(['logo_path' => null]);

        Flash::success('Company logo removed.');

        return null;
    }

    /* ── Context ─────────────────────────────────────────────────────────── */

    /**
     * Ports period_settings_ctx(): the stored configuration plus the pay
     * period today falls in, so the page can show what the settings currently
     * produce rather than only what they say.
     */
    private function periodContext(int $organizationId): array
    {
        $config = $this->period->organizationConfig($organizationId);
        $today = (new \DateTime('now', new \DateTimeZone($config['tz'])))->format('Y-m-d');

        [$start, $end, $label] = $this->period->payBounds($today, $config);

        return $config + ['current_label' => $label, 'current_start' => $start, 'current_end' => $end];
    }

    /* ── Guards ──────────────────────────────────────────────────────────── */

    private function requireShare(Request $request): void
    {
        abort_unless($request->user()->hasCapability(Capability::ViewAll), 403, 'Not allowed');
    }

    private function requireConfigure(Request $request): void
    {
        abort_unless($request->user()->hasCapability(Capability::OrgSettings), 403, 'Not allowed');
    }
}
