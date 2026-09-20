<?php

namespace App\Http\Controllers\Dashboard;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Contract;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkSession;
use App\Services\Clients\PortalLogin;
use App\Support\Flash;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * `/app/clients` — the companies the organization's people are placed with,
 * their contracts, and their read-only portal logins.
 *
 * Behind `clients_manage`. There is no read-only variant of this page: a role
 * that can open it can change everything on it.
 *
 * Contract terms are edited here, nested under their client. `/app/contracts`
 * is a different page for a different job — assigning the people who work under
 * each contract — and holds a different capability.
 *
 * @see docs/migration/billing.md
 */
class ClientController extends Controller
{
    public function __construct(private readonly PortalLogin $portalLogin) {}

    public function show(Request $request)
    {
        $user = $request->user();

        // A platform operator gets the cross-tenant view from the console.
        if ($user->isSuperAdmin() && ! $user->isActingAsOrganization()) {
            return redirect('/app/platform');
        }

        $organizationId = $user->effectiveOrgId();

        // Archived clients sort last but are not hidden — the page is where
        // you go to restore one.
        $clients = Client::query()
            ->where('org_id', $organizationId)
            ->orderBy('archived')
            ->orderBy('name')
            ->get();

        return view('dashboard.clients', [
            'title'        => 'Clients',
            'active'       => 'clients',
            'clients'      => $clients,
            'contracts'    => Contract::query()
                ->where('org_id', $organizationId)
                ->orderByDesc('created_at')
                ->get(),
            'rollup'       => $this->rollup($organizationId),
            'clientLogins' => $this->loginEmails($clients),
        ]);
    }

    public function update(Request $request)
    {
        $user = $request->user();
        $organizationId = $user->effectiveOrgId();

        match ((string) $request->input('action', '')) {
            'add_client'            => $this->addClient($request, $organizationId),
            'update_client'         => $this->updateClient($request, $organizationId),
            'delete_client'         => $this->deleteClient($request, $organizationId),
            'archive_client'        => $this->setArchived($request, $organizationId, true),
            'unarchive_client'      => $this->setArchived($request, $organizationId, false),
            'create_client_login'   => $this->createLogin($request, $organizationId),
            'reset_client_password' => $this->resetLoginPassword($request, $organizationId),
            'add_contract'          => $this->addContract($request, $organizationId),
            'update_contract'       => $this->updateContract($request, $organizationId),
            'delete_contract'       => $this->deleteContract($request, $organizationId),
            default                 => null,
        };

        return redirect('/app/clients');
    }

    /* ── Clients ─────────────────────────────────────────────────────────── */

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
            'notes'         => substr((string) $request->input('notes', ''), 0, 2000),
            'bill_rate'     => $this->billRate($request),
            'currency'      => $this->currency($request),
        ]);

        if ($email === '') {
            Flash::success('Client added. Add a contact email to give them a portal login.');

            return;
        }

        // Treat the client as a user: a contact email is enough to give them
        // read-only access to what they are being billed for.
        [, $message] = $this->portalLogin->create($organizationId, $client, $name, $email);

        Flash::success('Client added.' . ($message ? ' ' . $message : ''));
    }

    private function updateClient(Request $request, int $organizationId): void
    {
        $name = trim((string) $request->input('name', ''));
        $client = $this->ownClient($request, $organizationId);

        if ($name === '' || ! $client) {
            return;
        }

        $client->forceFill([
            'name'          => substr($name, 0, 200),
            'contact_email' => substr((string) $request->input('contact_email', ''), 0, 190),
            'notes'         => substr((string) $request->input('notes', ''), 0, 2000),
            'bill_rate'     => $this->billRate($request),
            'currency'      => $this->currency($request),
        ])->save();

        Flash::success('Client updated.');
    }

    /**
     * Delete a client, keeping the history.
     *
     * Tracked time is never destroyed — the client tag is cleared from sessions
     * and tasks instead, so the hours stay on people's timesheets and in
     * payroll. Contracts and agent assignments cascade away through their
     * foreign keys. The portal login goes with the client, since it exists
     * solely to view that client's data.
     */
    private function deleteClient(Request $request, int $organizationId): void
    {
        $client = $this->ownClient($request, $organizationId);

        if (! $client) {
            return;
        }

        DB::transaction(function () use ($client, $organizationId) {
            WorkSession::query()->where('client_id', $client->id)->update(['client_id' => null]);
            Task::query()->where('client_id', $client->id)->update(['client_id' => null]);

            $loginId = $client->user_id;

            $client->delete();

            if ($loginId) {
                User::query()
                    ->whereKey($loginId)
                    ->where('org_id', $organizationId)
                    ->where('role', UserRole::ClientViewer->value)
                    ->delete();
            }
        });

        Flash::success('Client deleted.');
    }

    private function setArchived(Request $request, int $organizationId, bool $archived): void
    {
        Client::query()
            ->whereKey((int) $request->input('client_id', 0))
            ->where('org_id', $organizationId)
            ->update(['archived' => $archived ? 1 : 0]);

        Flash::success($archived ? 'Client archived.' : 'Client restored.');
    }

    /* ── Portal logins ───────────────────────────────────────────────────── */

    private function createLogin(Request $request, int $organizationId): void
    {
        $client = $this->ownClient($request, $organizationId);

        // Already has one — the form is not offered, and a replayed POST must
        // not mint a second account for the same client.
        if (! $client || $client->user_id) {
            return;
        }

        [$created, $message] = $this->portalLogin->create(
            $organizationId,
            $client,
            (string) $client->name,
            (string) $request->input('contact_email', $client->contact_email)
        );

        Flash::add($message ?: 'Could not create a login for this client.', $created ? 'success' : 'error');
    }

    private function resetLoginPassword(Request $request, int $organizationId): void
    {
        $client = $this->ownClient($request, $organizationId);

        if (! $client) {
            return;
        }

        $temporary = $this->portalLogin->resetPassword($organizationId, $client);

        if ($temporary) {
            Flash::success("Temporary password reset — share it with the client: {$temporary} "
                . "(they'll be asked to change it at next login).");
        }
    }

    /* ── Contracts ───────────────────────────────────────────────────────── */

    /**
     * A contract's bill rate is a quoted reference figure for the engagement,
     * defaulting to the client's standard rate when left blank. Actual billing
     * still rolls up from each person's own bill rate.
     *
     * Both "left blank" tests cast to string first. Laravel's
     * ConvertEmptyStringsToNull middleware turns an empty form field into null
     * before it reaches here, where the legacy `$_POST` saw `''` — comparing
     * the raw value to `''` would take the "a rate was given" branch for every
     * blank field and quietly write 0.00 instead of inheriting.
     */
    private function addContract(Request $request, int $organizationId): void
    {
        $client = $this->ownClient($request, $organizationId);

        if (! $client) {
            return;
        }

        Contract::create([
            'org_id'     => $organizationId,
            'client_id'  => $client->id,
            'title'      => substr((string) $request->input('title', 'Contract'), 0, 200),
            'bill_rate'  => (string) $request->input('bill_rate', '') !== ''
                ? $this->billRate($request)
                : (float) ($client->bill_rate ?? 0),
            'currency'   => trim((string) $request->input('currency', '')) !== ''
                ? $this->currency($request)
                : ($client->currency ?: 'USD'),
            'status'     => $this->status($request),
            'start_date' => $request->input('start_date') ?: null,
            'end_date'   => $request->input('end_date') ?: null,
        ]);

        Flash::success('Contract added.');
    }

    private function updateContract(Request $request, int $organizationId): void
    {
        $contract = Contract::query()
            ->whereKey((int) $request->input('contract_id', 0))
            ->where('org_id', $organizationId)
            ->first();

        if (! $contract) {
            return;
        }

        $contract->forceFill([
            'title'      => substr((string) $request->input('title', 'Contract'), 0, 200),
            'bill_rate'  => $this->billRate($request),
            'status'     => $this->status($request),
            'start_date' => $request->input('start_date') ?: null,
            'end_date'   => $request->input('end_date') ?: null,
        ])->save();

        Flash::success('Contract updated.');
    }

    private function deleteContract(Request $request, int $organizationId): void
    {
        $deleted = Contract::query()
            ->whereKey((int) $request->input('contract_id', 0))
            ->where('org_id', $organizationId)
            ->delete();

        if ($deleted) {
            Flash::success('Contract deleted.');
        }
    }

    /* ── Helpers ─────────────────────────────────────────────────────────── */

    /** Resolves ?client_id inside the tenant, or null. */
    private function ownClient(Request $request, int $organizationId): ?Client
    {
        return Client::query()
            ->whereKey((int) $request->input('client_id', 0))
            ->where('org_id', $organizationId)
            ->first();
    }

    private function billRate(Request $request): float
    {
        return max(0.0, round((float) $request->input('bill_rate', 0), 2));
    }

    private function currency(Request $request): string
    {
        return strtoupper(substr(trim((string) $request->input('currency', 'USD')), 0, 8)) ?: 'USD';
    }

    private function status(Request $request): string
    {
        return $request->input('status') === 'ended' ? 'ended' : 'active';
    }

    /**
     * Tracked seconds and billable amount per client.
     *
     * Approved sessions only, and the amount uses the WORKER's bill rate rather
     * than the client's or the contract's — those two are quoted figures, this
     * is what is actually charged.
     *
     * @return array<int, array{secs: int, amount: float}>
     */
    private function rollup(int $organizationId): array
    {
        $rows = DB::table('sessions as s')
            ->join('users as u', 'u.id', '=', 's.user_id')
            ->selectRaw('s.client_id, SUM(s.active_s) AS secs, SUM(s.active_s / 3600 * u.bill_rate) AS amount')
            ->where('u.org_id', $organizationId)
            ->whereNotNull('s.client_id')
            ->where('s.approval_status', 'approved')
            ->groupBy('s.client_id')
            ->get();

        $rollup = [];

        foreach ($rows as $row) {
            $rollup[(int) $row->client_id] = ['secs' => (int) $row->secs, 'amount' => (float) $row->amount];
        }

        return $rollup;
    }

    /**
     * client_id => portal login email.
     *
     * @return array<int, string>
     */
    private function loginEmails($clients): array
    {
        $userIds = $clients->pluck('user_id')->filter()->all();

        if (! $userIds) {
            return [];
        }

        $byUser = User::query()->whereIn('id', $userIds)->pluck('email', 'id')->all();

        $emails = [];

        foreach ($clients as $client) {
            if ($client->user_id && isset($byUser[$client->user_id])) {
                $emails[(int) $client->id] = $byUser[$client->user_id];
            }
        }

        return $emails;
    }
}
