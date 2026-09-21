<?php

namespace App\Http\Controllers\Dashboard;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\Services\Agent\MonitoringPolicy;
use App\Services\Clients\PortalLogin;
use App\Support\Flash;
use App\Support\Token;
use App\Support\Visibility;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * `/app/onboarding` — the first-run setup wizard.
 *
 * **Two different wizards behind one path.** A company admin gets the five-step
 * org / clients / teams / accounts / tasks setup; a team manager gets a
 * two-step roster wizard instead, because a manager cannot create clients or
 * teams and would be staring at four steps they may not use. Everybody else is
 * sent to their overview — their first run is `/app/welcome`.
 *
 * ## Why a set-up organization is not simply redirected away
 *
 * The bounce fires only when the organization is BOTH onboarded and has
 * accounts besides the admin. An organization that finished the wizard without
 * adding anybody would otherwise be sent to the overview, which sends them
 * back here, forever. An explicit `?step=` always reopens the wizard, so it
 * stays revisitable from the role guide.
 *
 * ## Position lives in the URL
 *
 * Every POST redirects back to `?step=<the step it was posted from>`, so the
 * wizard has no server-side cursor that can disagree with the address bar.
 *
 * @see docs/migration/routes.md §2
 */
class OnboardingController extends Controller
{
    /** The company admin's five steps, plus the summary. */
    private const ADMIN_STEPS = ['org', 'clients', 'teams', 'agents', 'tasks', 'done'];

    /** The team manager's two, plus the summary. */
    private const MANAGER_STEPS = ['roster', 'tasks', 'done'];

    /**
     * Roles a company admin may create here.
     *
     * Client portal logins are deliberately absent: those are provisioned on
     * the Clients page, against a client record, and one created loose here
     * would belong to no customer.
     */
    private const ASSIGNABLE_ROLES = ['member', 'manager', 'hr_manager', 'it_admin'];

    public function __construct(
        private readonly MonitoringPolicy $policy,
        private readonly PortalLogin $portalLogin,
    ) {}

    public function show(Request $request)
    {
        $user = $request->user();

        if ($user->role === UserRole::Manager) {
            return $this->showManager($request, $user);
        }

        // Only the company admin and team managers run setup.
        if ($user->role !== UserRole::ClientAdmin) {
            return redirect('/app/overview');
        }

        $organizationId = (int) $user->effectiveOrgId();
        $organization = Organization::query()->whereKey($organizationId)->first();

        // Bounce only a FULLY set-up organization, and only when no step was
        // asked for. Onboarded-but-empty would otherwise ping-pong with the
        // overview forever.
        if ($organization->onboarded_at && $this->staffCount($organizationId) > 0 && ! $request->has('step')) {
            return redirect('/app/overview');
        }

        $members = User::query()
            ->where('org_id', $organizationId)
            ->whereKeyNot($user->id)
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role']);

        $teams = Team::query()->where('org_id', $organizationId)->orderBy('name')->get(['id', 'name']);

        return view('dashboard.onboarding', [
            'title'       => 'Set up DeskPulse',
            'active'      => '',
            'me'          => $user,
            'org'         => $organization,
            'policy'      => $this->policy->for($organizationId),
            'step'        => $this->step($request, self::ADMIN_STEPS, 'org'),
            'steps'       => self::ADMIN_STEPS,
            'members'     => $members,
            'clients'     => $this->clients($organizationId),
            'teams'       => $teams,
            'teamMembers' => $this->teamMembers($organizationId),
            // Qualified: tasksFor() joins users and clients, both of which
            // also have an org_id.
            'tasks'       => $this->tasksFor(Task::query()->where('tasks.org_id', $organizationId)),
        ]);
    }

    public function update(Request $request)
    {
        $user = $request->user();

        if ($user->role === UserRole::Manager) {
            return $this->updateManager($request, $user);
        }

        abort_unless($user->role === UserRole::ClientAdmin, 403, 'Not allowed');

        $organizationId = (int) $user->effectiveOrgId();
        $step = $this->step($request, self::ADMIN_STEPS, 'org');

        switch ((string) $request->input('action', '')) {
            case 'save_org':
                $this->saveOrganization($request, $organizationId);
                // The only action that advances on its own — it is the one
                // step with a Save button rather than a Continue link.
                $step = 'clients';
                break;

            case 'add_client':
                $this->addClient($request, $organizationId);
                break;

            case 'create_team':
                $this->createTeam($request, $organizationId);
                break;

            case 'add_team_member':
                $this->addTeamMember($request, $organizationId);
                break;

            case 'add_account':
                $this->addAccount($request, $organizationId);
                break;

            case 'add_task':
                $this->addTask($request, $organizationId, null);
                break;

            case 'finish':
                return $this->finishAdmin($organizationId);
        }

        return redirect('/app/onboarding?step=' . $step);
    }

    /* ── Company admin steps ─────────────────────────────────────────────── */

    private function saveOrganization(Request $request, int $organizationId): void
    {
        $organization = Organization::query()->whereKey($organizationId)->first();

        Organization::query()->whereKey($organizationId)->update([
            'name' => substr(trim((string) $request->input('name', '')), 0, 160) ?: $organization->name,

            // Same clamps as Settings — these drive what the agent does on
            // somebody's machine.
            'screenshot_interval_min' => max(1, min(120, (int) $request->input('screenshot_interval_min', 10))),
            'screenshot_blur'         => $request->has('screenshot_blur') ? 1 : 0,
            'idle_threshold_min'      => max(1, min(120, (int) $request->input('idle_threshold_min', 15))),
            'track_screenshots'       => $request->has('track_screenshots') ? 1 : 0,
            'track_windows'           => $request->has('track_windows') ? 1 : 0,
            'track_processes'         => $request->has('track_processes') ? 1 : 0,
        ]);

        Flash::success('Organization saved.');
    }

    private function addClient(Request $request, int $organizationId): void
    {
        $name = trim((string) $request->input('name', ''));

        if ($name === '') {
            return;
        }

        $email = strtolower(trim((string) $request->input('contact_email', '')));

        $client = Client::create([
            'org_id'        => $organizationId,
            'name'          => substr($name, 0, 200),
            'contact_email' => substr($email, 0, 190),
        ]);

        if ($email === '') {
            Flash::success('Client added.');

            return;
        }

        [, $message] = $this->portalLogin->create($organizationId, $client, $name, $email);

        Flash::success('Client added.' . ($message ? ' ' . $message : ''));
    }

    private function createTeam(Request $request, int $organizationId): void
    {
        $name = trim((string) $request->input('team_name', ''));

        if ($name === '') {
            return;
        }

        Team::create(['org_id' => $organizationId, 'name' => substr($name, 0, 160)]);

        Flash::success('Team created.');
    }

    private function addTeamMember(Request $request, int $organizationId): void
    {
        // Both sides are re-read against this tenant: a posted id is a claim,
        // not a fact.
        $team = Team::query()
            ->whereKey((int) $request->input('team_id', 0))
            ->where('org_id', $organizationId)
            ->first();

        $member = User::query()
            ->whereKey((int) $request->input('user_id', 0))
            ->where('org_id', $organizationId)
            ->first();

        if (! $team || ! $member) {
            return;
        }

        $already = DB::table('team_members')
            ->where('team_id', $team->id)
            ->where('user_id', $member->id)
            ->exists();

        if ($already) {
            return;
        }

        DB::table('team_members')->insert(['team_id' => $team->id, 'user_id' => $member->id]);

        Flash::success('Added to team.');
    }

    private function addAccount(Request $request, int $organizationId): void
    {
        $email = strtolower(trim((string) $request->input('email', '')));

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)
            || User::query()->where('email', $email)->exists()) {
            Flash::error('Could not create account — missing or duplicate email.');

            return;
        }

        $currency = Organization::query()->whereKey($organizationId)->value('billing_currency');

        User::create([
            'org_id'        => $organizationId,
            'name'          => substr(trim((string) $request->input('name', 'New User')), 0, 120) ?: 'New User',
            'email'         => $email,
            // A blank password is not a blank password: it is a random one the
            // admin never sees, so the account cannot be signed into until it
            // is reset.
            'password_hash' => Hash::make(((string) $request->input('password', '')) ?: Token::random(10)),
            'role'          => in_array($request->input('role'), self::ASSIGNABLE_ROLES, true)
                ? (string) $request->input('role')
                : 'member',
            'currency'      => substr($currency ?: 'USD', 0, 8),
        ]);

        Flash::success('Account created.');
    }

    /**
     * @param  list<int>|null  $roster  the ids a manager may assign to, or null for the whole tenant
     */
    private function addTask(Request $request, int $organizationId, ?array $roster): void
    {
        $title = trim((string) $request->input('title', ''));

        // Tasks belong to the people who do the work: agents and team
        // managers. Nobody else can own one.
        $owner = User::query()
            ->whereKey((int) $request->input('user_id', 0))
            ->where('org_id', $organizationId)
            ->whereIn('role', [UserRole::Member->value, UserRole::Manager->value])
            ->when($roster !== null, fn ($query) => $query->whereIn('id', $roster))
            ->first();

        if ($title === '' || ! $owner) {
            Flash::error($roster === null
                ? 'Add a title and pick who the task is for.'
                : 'Add a title and pick someone in your roster.');

            return;
        }

        $clientId = null;

        if ($request->filled('client_id')) {
            $clientId = Client::query()
                ->whereKey((int) $request->input('client_id'))
                ->where('org_id', $organizationId)
                ->value('id');
        }

        Task::create([
            'org_id'    => $organizationId,
            'user_id'   => $owner->id,
            'client_id' => $clientId,
            'title'     => substr($title, 0, 240),
        ]);

        Flash::success('Task added.');
    }

    private function finishAdmin(int $organizationId)
    {
        // Finish and skip are the same button. Setup is optional — everything
        // in it can be done later from the app — so skipping still stamps, or
        // the wizard reappears on every sign-in.
        Organization::query()->whereKey($organizationId)->update(['onboarded_at' => now()]);

        Flash::success($this->staffCount($organizationId) > 0
            ? 'Setup complete — welcome to DeskPulse!'
            : 'Setup skipped — you can add your team any time.');

        return redirect('/app/overview');
    }

    /* ── Team manager wizard ─────────────────────────────────────────────── */

    private function showManager(Request $request, User $user)
    {
        $organizationId = (int) $user->effectiveOrgId();
        $rosterIds = $this->rosterIds($user);

        return view('dashboard.onboarding_manager', [
            'title'   => 'Build your roster',
            'active'  => '',
            'me'      => $user,
            'step'    => $this->step($request, self::MANAGER_STEPS, 'roster'),
            'steps'   => self::MANAGER_STEPS,
            'roster'  => $rosterIds
                ? User::query()->whereIn('id', $rosterIds)->orderBy('name')->get(['id', 'name', 'email', 'role'])
                : collect(),
            'clients' => $this->clients($organizationId),
            'tasks'   => $this->tasksFor(Task::query()->whereIn('tasks.user_id', $rosterIds ?: [0])),
        ]);
    }

    private function updateManager(Request $request, User $user)
    {
        $organizationId = (int) $user->effectiveOrgId();
        $step = $this->step($request, self::MANAGER_STEPS, 'roster');

        switch ((string) $request->input('action', '')) {
            case 'add_agent':
                $this->addAgent($request, $user, $organizationId);
                break;

            case 'add_task':
                $this->addTask($request, $organizationId, $this->rosterIds($user) ?: [0]);
                break;

            case 'finish':
                if ($user->welcomed_at === null) {
                    $user->forceFill(['welcomed_at' => now()])->save();
                }

                Flash::success($this->rosterIds($user)
                    ? 'Your roster is ready.'
                    : 'Skipped — add agents any time from your dashboard.');

                return redirect('/app/overview');
        }

        return redirect('/app/onboarding?step=' . $step);
    }

    private function addAgent(Request $request, User $manager, int $organizationId): void
    {
        $email = strtolower(trim((string) $request->input('email', '')));

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)
            || User::query()->where('email', $email)->exists()) {
            Flash::error('Could not add agent — missing or duplicate email.');

            return;
        }

        $agent = User::create([
            'org_id'        => $organizationId,
            'name'          => substr(trim((string) $request->input('name', 'New User')), 0, 120) ?: 'New User',
            'email'         => $email,
            'password_hash' => Hash::make(((string) $request->input('password', '')) ?: Token::random(10)),
            // A manager can only ever make members. Anything else would be a
            // privilege escalation dressed up as a roster.
            'role'          => UserRole::Member,
            'currency'      => 'USD',
        ]);

        DB::table('team_members')->insert([
            'team_id' => $this->managerTeamId($manager),
            'user_id' => $agent->id,
        ]);

        Flash::success('Agent added to your roster.');
    }

    /**
     * The manager's first team, created on demand.
     *
     * Ports manager_team_id($u, true). A manager with no team sees nobody at
     * all — {@see Visibility::teamMemberIds()} returns `[0]` — so the wizard
     * cannot add an agent without one existing.
     */
    private function managerTeamId(User $manager): int
    {
        $existing = DB::table('team_members')
            ->join('teams', 'teams.id', '=', 'team_members.team_id')
            ->where('team_members.user_id', $manager->id)
            ->orderBy('teams.id')
            ->value('teams.id');

        if ($existing) {
            return (int) $existing;
        }

        $team = Team::create([
            'org_id' => (int) $manager->effectiveOrgId(),
            'name'   => substr((string) $manager->name, 0, 150) . "'s Team",
        ]);

        DB::table('team_members')->insert(['team_id' => $team->id, 'user_id' => $manager->id]);

        return (int) $team->id;
    }

    /** The manager's roster: their team, minus themselves. @return list<int> */
    private function rosterIds(User $manager): array
    {
        return array_values(array_diff(Visibility::teamMemberIds((int) $manager->id), [(int) $manager->id, 0]));
    }

    /* ── Shared reads ────────────────────────────────────────────────────── */

    private function clients(int $organizationId)
    {
        return Client::query()
            ->where('org_id', $organizationId)
            ->where('archived', 0)
            ->orderBy('name')
            ->get(['id', 'name', 'contact_email', 'user_id']);
    }

    /** Team id => the names in it. @return array<int, list<string>> */
    private function teamMembers(int $organizationId): array
    {
        $rows = DB::table('team_members')
            ->join('users', 'users.id', '=', 'team_members.user_id')
            ->join('teams', 'teams.id', '=', 'team_members.team_id')
            ->where('teams.org_id', $organizationId)
            ->orderBy('users.name')
            ->get(['team_members.team_id', 'users.name']);

        $out = [];

        foreach ($rows as $row) {
            $out[(int) $row->team_id][] = $row->name;
        }

        return $out;
    }

    /** Tasks with their owner and client names, newest first. */
    private function tasksFor($query)
    {
        return $query
            ->join('users', 'users.id', '=', 'tasks.user_id')
            ->leftJoin('clients', 'clients.id', '=', 'tasks.client_id')
            ->orderByDesc('tasks.created_at')
            ->get(['tasks.title', 'users.name as owner', 'clients.name as client']);
    }

    /** Accounts in this tenant that are neither the company admin nor a platform operator. */
    private function staffCount(int $organizationId): int
    {
        return User::query()
            ->where('org_id', $organizationId)
            ->whereNotIn('role', [UserRole::ClientAdmin->value, UserRole::SuperAdmin->value])
            ->count();
    }

    /* ── Step resolution ─────────────────────────────────────────────────── */

    /**
     * The step named in the request, or the first one.
     *
     * One method for both verbs: the legacy reads `$_GET['step']` on the way
     * in and `$_POST['step']` on the way out, and `input()` is both.
     *
     * @param  list<string>  $steps
     */
    private function step(Request $request, array $steps, string $default): string
    {
        return in_array($request->input('step'), $steps, true)
            ? (string) $request->input('step')
            : $default;
    }
}
