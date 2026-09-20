<?php

namespace App\Http\Controllers\Dashboard;

use App\Enums\Capability;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\User;
use App\Services\Reporting\SessionStats;
use App\Services\Sharing\PersonalLinks;
use App\Support\Employment;
use App\Support\Flash;
use App\Support\Period;
use App\Support\PlanLimits;
use App\Support\Visibility;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;

/**
 * `/app/team` — accounts, roles, work schedules, employment terms and rates.
 *
 * Reached with `view_all`, but seeing the page and changing it are different
 * things. Two further capabilities split the work, and they are deliberately
 * not nested:
 *
 *   users_manage     create accounts, set roles, set BOTH rate pairs, teams
 *   profiles_manage  edit a person's details, schedule and employment terms
 *   set_pay_rate     write a pay rate — held by HR, who cannot VIEW rates
 *
 * An HR admin holds profiles_manage and set_pay_rate but not users_manage or
 * view_rates: they can set what someone is paid without being able to read what
 * anyone else is paid, and they can never touch a bill rate. That asymmetry is
 * intentional and is why each action checks its own capability rather than a
 * single "can edit" flag.
 *
 * @see docs/migration/authorization.md
 */
class TeamController extends Controller
{
    /**
     * Roles that may be ASSIGNED to an existing account.
     *
     * super_admin is absent: a platform operator is not something a tenant can
     * promote someone into.
     *
     * @var list<string>
     */
    private const ASSIGNABLE = [
        'member', 'manager', 'hr_manager', 'it_admin', 'client_admin', 'client_viewer',
    ];

    /**
     * Roles that may be CREATED here.
     *
     * client_viewer is absent: a portal login is provisioned on the Clients
     * page, attached to the client record it belongs to. Creating a stray one
     * here would produce a login with no client, which sees nothing.
     *
     * @var list<string>
     */
    private const CREATABLE = [
        'member', 'manager', 'hr_manager', 'it_admin', 'client_admin',
    ];

    public function __construct(
        private readonly SessionStats $stats,
        private readonly Period $period,
        private readonly PersonalLinks $personalLinks,
        private readonly PlanLimits $planLimits,
    ) {}

    public function show(Request $request)
    {
        $user = $request->user();

        // A platform operator gets a read-only view of every tenant's teams,
        // from the console. Managing an organization happens inside it.
        if ($user->isSuperAdmin() && ! $user->isActingAsOrganization()) {
            return redirect('/app/platform');
        }

        $organizationId = $user->effectiveOrgId();
        $ctx = $this->period->context($request, $organizationId, 'week', ['week', 'pay', 'month']);

        // Anyone added since the last visit gets their public page now.
        $this->personalLinks->ensureFor($organizationId);

        $usersById = $this->stats->usersById($organizationId);
        $ids = array_keys($usersById);
        $sessions = $this->stats->forUsers($ids, $ctx['start'], $ctx['end']);

        $perUser = [];

        foreach ($ids as $id) {
            $perUser[$id] = ['active_s' => 0, 'inactive_s' => 0];
        }

        foreach ($sessions as $session) {
            $perUser[(int) $session->user_id]['active_s'] += (int) $session->active_s;
            $perUser[(int) $session->user_id]['inactive_s'] += (int) $session->inactive_s;
        }

        $teams = Team::query()->where('org_id', $organizationId)->orderBy('name')->get();

        // team_id => [{tm_id, user_id, name}]
        $teamMembers = [];

        foreach (DB::table('team_members as tm')
            ->join('teams as t', 't.id', '=', 'tm.team_id')
            ->join('users as u', 'u.id', '=', 'tm.user_id')
            ->where('t.org_id', $organizationId)
            ->orderBy('u.name')
            ->get(['tm.id as tm_id', 'tm.team_id', 'tm.user_id', 'u.name']) as $row) {
            $teamMembers[$row->team_id][] = $row;
        }

        return view('dashboard.team', [
            'title'          => 'Team',
            'active'         => 'team',
            'ctx'            => $ctx,
            'periods'        => Period::options(['week', 'pay', 'month']),
            'daily'          => $this->stats->dailySeries($sessions, $ctx['start'], $ctx['end'], $ctx['tz']),
            'usersById'      => $usersById,
            'perUser'        => $perUser,
            'teams'          => $teams,
            'teamMembers'    => $teamMembers,
            'personalLinks'  => $this->personalLinkTokens($organizationId),
            'publicBase'     => rtrim(config('app.url'), '/'),
            'canManage'      => $user->hasCapability(Capability::UsersManage),
            'canProfiles'    => $user->hasCapability(Capability::ProfilesManage),
            'canViewRates'   => $user->hasCapability(Capability::ViewRates),
            'canSetPay'      => $user->hasCapability(Capability::SetPayRate),
            'assignable'     => self::ASSIGNABLE,
            'creatable'      => self::CREATABLE,
            'employmentTypes' => Employment::types(),
            'stats'          => $this->stats,
        ]);
    }

    public function update(Request $request)
    {
        $user = $request->user();
        $organizationId = $user->effectiveOrgId();
        $action = (string) $request->input('action', '');

        // Every action that names a user resolves it inside the tenant. A
        // forged user_id from another organization simply does not resolve.
        $target = User::query()
            ->whereKey((int) $request->input('user_id', 0))
            ->where('org_id', $organizationId)
            ->first();

        if ($action === 'update_profile') {
            abort_unless($user->hasCapability(Capability::ProfilesManage), Response::HTTP_FORBIDDEN, 'Not allowed');

            $this->updateProfile($request, $user, $target);

            return redirect('/app/team');
        }

        abort_unless($user->hasCapability(Capability::UsersManage), Response::HTTP_FORBIDDEN, 'You cannot manage team members.');

        $elsewhere = match ($action) {
            'create_user'        => $this->createUser($request, $organizationId),
            'update_user'        => $this->updateUser($request, $user, $target),
            'create_team'        => $this->createTeam($request, $organizationId),
            'add_team_member'    => $this->addTeamMember($request, $organizationId, $target),
            'remove_team_member' => $this->removeTeamMember($request, $organizationId),
            default              => null,
        };

        // Only one branch lands anywhere but back here: a refused seat sends
        // the admin to the page that sells them one.
        return $elsewhere ?? redirect('/app/team');
    }

    /* ── Actions ─────────────────────────────────────────────────────────── */

    /**
     * HR (or an admin) edits someone's details.
     *
     * A single guard covers name, email, phone and job title; the pay rate has
     * its own, because set_pay_rate and profiles_manage are held by different
     * roles. The bill rate is not writable here at all — HR never sets what a
     * client is charged.
     */
    private function updateProfile(Request $request, User $user, ?User $target): void
    {
        $email = strtolower(trim((string) $request->input('email', '')));

        // Email is globally unique, not per-organization: it is the login.
        $taken = $email !== '' && User::query()
            ->where('email', $email)
            ->when($target, fn ($q) => $q->whereKeyNot($target->id))
            ->exists();

        if (! $target || $email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL) || $taken) {
            Flash::error('Could not update profile (missing or duplicate email).');

            return;
        }

        $target->forceFill([
            'name'      => trim((string) $request->input('name', '')),
            'email'     => $email,
            'phone'     => substr((string) $request->input('phone', ''), 0, 40),
            'job_title' => substr((string) $request->input('job_title', ''), 0, 120),
        ])->save();

        if ($user->hasCapability(Capability::SetPayRate) && $request->has('pay_rate')) {
            $target->forceFill([
                'pay_type' => $request->input('pay_type') === 'monthly' ? 'monthly' : 'hourly',
                'pay_rate' => (float) $request->input('pay_rate', 0),
                'currency' => substr((string) $request->input('currency', 'USD'), 0, 8),
            ])->save();
        }

        $this->saveSchedule($request, $target);
        $this->saveEmployment($request, $target);

        Flash::success('Profile updated.');
    }

    private function createUser(Request $request, int $organizationId): ?RedirectResponse
    {
        $email = strtolower(trim((string) $request->input('email', '')));

        // Solo is a one-user plan. Enforced here, not merely advertised.
        $seatCap = $this->planLimits->for($organizationId)['max_seats'];

        if ($seatCap > 0 && count(Visibility::organizationUserIds($organizationId)) >= $seatCap) {
            Flash::error('The free Solo plan covers one user. Upgrade to add your team.');

            return redirect('/app/subscription');
        }

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)
            || User::query()->where('email', $email)->exists()) {
            Flash::error('Could not create member (missing or duplicate email).');

            return null;
        }

        $role = in_array($request->input('role'), self::CREATABLE, true)
            ? $request->input('role')
            : UserRole::Member->value;

        $password = (string) $request->input('password', '');

        $created = User::create([
            'org_id'        => $organizationId,
            'name'          => trim((string) $request->input('name', 'New User')),
            'email'         => $email,
            // A blank temp password becomes a random one rather than an empty
            // hash — the admin is expected to send a reset link either way.
            'password_hash' => Hash::make($password !== '' ? $password : \App\Support\Token::random(10)),
            'role'          => $role,
            'pay_type'      => $request->input('pay_type') === 'monthly' ? 'monthly' : 'hourly',
            'pay_rate'      => (float) $request->input('pay_rate', 0),
            'bill_type'     => $request->input('bill_type') === 'monthly' ? 'monthly' : 'hourly',
            'bill_rate'     => (float) $request->input('bill_rate', 0),
            'currency'      => substr((string) $request->input('currency', 'USD'), 0, 8),
        ]);

        $this->saveSchedule($request, $created);
        $this->saveEmployment($request, $created);

        Flash::success('Team member created.');

        return null;
    }

    private function updateUser(Request $request, User $user, ?User $target): void
    {
        if (! $target) {
            return;
        }

        $attributes = [
            'pay_type'  => $request->input('pay_type') === 'monthly' ? 'monthly' : 'hourly',
            'pay_rate'  => (float) $request->input('pay_rate', 0),
            // bill_type was rendered on the legacy form but never written, so a
            // monthly service charge could not actually be set from the UI.
            'bill_type' => $request->input('bill_type') === 'monthly' ? 'monthly' : 'hourly',
            'bill_rate' => (float) $request->input('bill_rate', 0),
            'currency'  => substr((string) $request->input('currency', 'USD'), 0, 8),
        ];

        // Only an account manager changes roles. Anyone else posting a role
        // field has it ignored rather than rejected, as in the legacy code.
        if ($user->hasCapability(Capability::UsersManage)) {
            $attributes['role'] = in_array($request->input('role'), self::ASSIGNABLE, true)
                ? $request->input('role')
                : UserRole::Member->value;
        }

        $target->forceFill($attributes)->save();

        $this->saveSchedule($request, $target);
        $this->saveEmployment($request, $target);

        Flash::success('Member updated.');
    }

    private function createTeam(Request $request, int $organizationId): void
    {
        Team::create([
            'org_id' => $organizationId,
            'name'   => trim((string) $request->input('team_name', 'New Team')),
        ]);

        Flash::success('Team created.');
    }

    private function addTeamMember(Request $request, int $organizationId, ?User $target): void
    {
        $team = Team::query()
            ->whereKey((int) $request->input('team_id', 0))
            ->where('org_id', $organizationId)
            ->first();

        if (! $team || ! $target) {
            return;
        }

        $alreadyOn = TeamMember::query()
            ->where('team_id', $team->id)
            ->where('user_id', $target->id)
            ->exists();

        if ($alreadyOn) {
            return;
        }

        TeamMember::create(['team_id' => $team->id, 'user_id' => $target->id]);

        Flash::success('Added to team.');
    }

    private function removeTeamMember(Request $request, int $organizationId): void
    {
        // The join is the tenancy check: team_members has no org_id.
        TeamMember::query()
            ->whereKey((int) $request->input('tm_id', 0))
            ->whereExists(fn ($q) => $q->select(DB::raw(1))
                ->from('teams')
                ->whereColumn('teams.id', 'team_members.team_id')
                ->where('teams.org_id', $organizationId))
            ->delete();

        Flash::success('Removed from team.');
    }

    /* ── Shared field groups ─────────────────────────────────────────────── */

    /**
     * The standard work schedule.
     *
     * Absent entirely when the form did not offer it, so an action that posts
     * no schedule fields cannot blank someone's existing one.
     */
    private function saveSchedule(Request $request, User $target): void
    {
        if (! $request->has('work_start') && ! $request->has('work_days')) {
            return;
        }

        $days = implode(',', array_values(array_intersect(
            ['1', '2', '3', '4', '5', '6', '7'],
            array_map('strval', (array) $request->input('work_days', []))
        )));

        $pattern = '/^\d{2}:\d{2}$/';
        $start = preg_match($pattern, (string) $request->input('work_start', ''))
            ? $request->input('work_start') . ':00'
            : null;
        $end = preg_match($pattern, (string) $request->input('work_end', ''))
            ? $request->input('work_end') . ':00'
            : null;

        $target->forceFill([
            'work_start' => $start,
            'work_end'   => $end,
            // Clearing every checkbox falls back to a Mon–Fri week rather than
            // no schedule at all, which is what the legacy form does.
            'work_days'  => $days ?: '1,2,3,4,5',
        ])->save();
    }

    /**
     * Employment type and contractual hour caps.
     *
     * Caps are advisory: hours over one still track and still pay. They flag
     * the person on the Payroll page so somebody reviews the run. 0 = no cap.
     */
    private function saveEmployment(Request $request, User $target): void
    {
        if (! $request->has('employment_type')) {
            return;
        }

        $type = array_key_exists((string) $request->input('employment_type'), Employment::types())
            ? $request->input('employment_type')
            : 'full_time';

        $hired = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->input('hired_on', ''))
            ? $request->input('hired_on')
            : null;

        $cap = fn (string $key) => max(0.0, min(999.0, (float) $request->input($key, 0)));

        $target->forceFill([
            'employment_type'  => $type,
            'hired_on'         => $hired,
            'daily_hours_cap'  => $cap('daily_hours_cap'),
            'weekly_hours_cap' => $cap('weekly_hours_cap'),
            'period_hours_cap' => $cap('period_hours_cap'),
        ])->save();
    }

    /**
     * user_id => live personal share token.
     *
     * @return array<int, string>
     */
    private function personalLinkTokens(int $organizationId): array
    {
        return DB::table('share_links')
            ->where('org_id', $organizationId)
            ->where('scope', 'user')
            ->where('revoked', 0)
            ->pluck('token', 'target_id')
            ->all();
    }
}
