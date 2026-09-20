<?php

namespace App\Http\Controllers\Dashboard;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Task;
use App\Models\User;
use App\Support\Flash;
use App\Support\Visibility;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * `/app/tasks` — what people are working on, and how long it took.
 *
 * Login only. Tasks belong to the person doing the work: a member creates and
 * edits their own, and everybody else — manager, admin, client portal — sees
 * them read-only with time per task.
 *
 * That split is by ROLE, not by capability, and it is the one place in the app
 * where that is correct: there is no "manage someone else's tasks" capability
 * because no role has it. A company admin cannot edit an employee's task list.
 */
class TaskController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user();
        $organizationId = $user->effectiveOrgId();

        $ids = Visibility::userIds($user);

        $tasks = Task::query()
            ->join('users', 'users.id', '=', 'tasks.user_id')
            ->whereIn('tasks.user_id', $ids)
            ->orderBy('tasks.status')
            ->orderByDesc('tasks.created_at')
            ->get(['tasks.*', 'users.name as owner_name']);

        // A client portal sees only tasks tagged to its own engagement.
        // Untagged tasks are not "related to this client", so they are excluded
        // rather than shown — and an unlinked login (-1) sees nothing at all.
        $clientFilter = Visibility::clientFilter($user);

        if ($clientFilter !== null) {
            $tasks = $tasks->filter(fn ($task) => (int) $task->client_id === $clientFilter)->values();
        }

        return view('dashboard.tasks', [
            'title'      => 'Tasks',
            'active'     => 'tasks',
            'tasks'      => $tasks,
            'timeByTask' => $this->timePerTask($ids),
            'clients'    => Client::query()
                ->where('org_id', $organizationId)
                ->where('archived', 0)
                ->orderBy('name')
                ->get(['id', 'name']),
            'clientNames' => Client::query()
                ->where('org_id', $organizationId)
                ->pluck('name', 'id')
                ->all(),
            'canManage'  => $user->role === UserRole::Member,
        ]);
    }

    public function update(Request $request)
    {
        $user = $request->user();

        abort_unless(
            $user->role === UserRole::Member,
            Response::HTTP_FORBIDDEN,
            'Only agents can manage their own tasks.'
        );

        match ((string) $request->input('action', '')) {
            'add'    => $this->add($request, $user),
            'edit'   => $this->edit($request, $user),
            'delete' => $this->remove($request, $user),
            'done'   => $this->markDone($request, $user),
            default  => null,
        };

        return redirect('/app/tasks');
    }

    /* ── Actions ─────────────────────────────────────────────────────────── */

    private function add(Request $request, User $user): void
    {
        $title = trim((string) $request->input('title', ''));

        if ($title === '') {
            return;
        }

        Task::create([
            'org_id'    => $user->effectiveOrgId(),
            'user_id'   => $user->id,
            'client_id' => $this->clientId($request, $user),
            'title'     => substr($title, 0, 240),
        ]);

        Flash::success('Task added.');
    }

    private function edit(Request $request, User $user): void
    {
        $task = $this->ownTask($request, $user);

        if (! $task) {
            return;
        }

        // A blank title keeps the existing one rather than emptying the row.
        $title = trim((string) $request->input('title', '')) ?: $task->title;

        $task->forceFill([
            'title'     => substr($title, 0, 240),
            'client_id' => $this->clientId($request, $user),
            'status'    => $request->input('status', $task->status) === 'done' ? 'done' : 'open',
        ])->save();

        Flash::success('Task updated.');
    }

    private function remove(Request $request, User $user): void
    {
        $deleted = Task::query()
            ->whereKey((int) $request->input('task_id', 0))
            ->where('user_id', $user->id)
            ->delete();

        if ($deleted) {
            Flash::success('Task removed.');
        }
    }

    /** The one-click "done" from the desktop agent's task list. No flash. */
    private function markDone(Request $request, User $user): void
    {
        Task::query()
            ->whereKey((int) $request->input('task_id', 0))
            ->where('user_id', $user->id)
            ->update(['status' => 'done']);
    }

    /* ── Helpers ─────────────────────────────────────────────────────────── */

    /**
     * Ownership is the whole authorization check here: `user_id = me`, with no
     * organization clause needed, because a task's owner is in one.
     */
    private function ownTask(Request $request, User $user): ?Task
    {
        return Task::query()
            ->whereKey((int) $request->input('task_id', 0))
            ->where('user_id', $user->id)
            ->first();
    }

    /** A client id from this tenant, or null — never a forged one. */
    private function clientId(Request $request, User $user): ?int
    {
        $id = (int) $request->input('client_id', 0);

        if ($id <= 0) {
            return null;
        }

        $exists = Client::query()
            ->whereKey($id)
            ->where('org_id', $user->effectiveOrgId())
            ->exists();

        return $exists ? $id : null;
    }

    /**
     * Active seconds and session count per task, across approved sessions.
     *
     * @param  list<int>  $ids
     * @return array<int, array{secs: int, cnt: int}>
     */
    private function timePerTask(array $ids): array
    {
        $rows = DB::table('sessions')
            ->selectRaw('task_id, SUM(active_s) AS secs, COUNT(*) AS cnt')
            ->whereNotNull('task_id')
            ->where('approval_status', 'approved')
            ->whereIn('user_id', $ids)
            ->groupBy('task_id')
            ->get();

        $byTask = [];

        foreach ($rows as $row) {
            $byTask[(int) $row->task_id] = ['secs' => (int) $row->secs, 'cnt' => (int) $row->cnt];
        }

        return $byTask;
    }
}
