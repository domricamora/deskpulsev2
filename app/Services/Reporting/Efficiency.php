<?php

namespace App\Services\Reporting;

use App\Enums\Capability;
use App\Models\User;
use App\Support\Visibility;
use Illuminate\Support\Facades\DB;

/**
 * The efficiency report — one row per person in the viewer's scope.
 *
 * Ports efficiency_rows() and task_completion_by_user().
 *
 * ## The score, and why it can be null
 *
 * Effectiveness blends activity % with task-completion % when the person has
 * tasks assigned, and falls back to activity % alone when they do not — having
 * no tasks is not a strike against someone. Somebody with neither tracked time
 * nor tasks, which is every admin, HR and IT role, scores **null** rather than
 * a punitive 0%: they are not being measured, not measured badly. The page and
 * the CSV both render that as "n/a", and the organization average skips them.
 *
 * Rows are sorted by effectiveness descending, unscored last.
 *
 * @see docs/migration/reports.md §5
 */
class Efficiency
{
    public function __construct(private readonly SessionStats $stats) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function rows(User $viewer, string $startUtc, string $endUtc): array
    {
        $ids = Visibility::userIds($viewer);

        // Only the people this viewer can see, in the organization's own map —
        // which already excludes platform operators.
        $people = array_intersect_key(
            $this->stats->usersById((int) $viewer->effectiveOrgId()),
            array_flip($ids)
        );

        // Approved sessions only. A pending manual entry must not inflate
        // someone's figures before a manager has looked at it.
        $byUser = $this->stats->forUsers($ids, $startUtc, $endUtc)->groupBy('user_id');

        $tasks = $this->taskCompletion($ids);
        $canSeeRates = $viewer->hasCapability(Capability::ViewRates);

        $rows = [];

        foreach ($people as $id => $person) {
            $summary = $this->stats->summarize($byUser->get($id) ?? collect());

            $tasksTotal = $tasks[$id]['total'] ?? 0;
            $tasksDone = $tasks[$id]['done'] ?? 0;
            $taskPercent = $tasksTotal ? (int) round(100 * $tasksDone / $tasksTotal) : 0;

            $hasData = $summary['count'] > 0 || $tasksTotal > 0;

            $rows[] = [
                'id'                  => $id,
                'name'                => $person->name,
                'role'                => $person->role,
                'active_s'            => $summary['active_s'],
                'inactive_s'          => $summary['inactive_s'],
                'activity_pct'        => $summary['activity_pct'],
                'sessions'            => $summary['count'],
                'tasks_total'         => $tasksTotal,
                'tasks_done'          => $tasksDone,
                'task_completion_pct' => $taskPercent,
                'effectiveness_pct'   => match (true) {
                    ! $hasData   => null,
                    $tasksTotal > 0 => (int) round(($summary['activity_pct'] + $taskPercent) / 2),
                    default      => $summary['activity_pct'],
                },
                'cost'                => $canSeeRates
                    ? $summary['active_s'] / 3600.0 * $this->stats->hourlyRate($person)
                    : null,
                'currency'            => $person->currency ?? 'USD',
            ];
        }

        usort(
            $rows,
            fn (array $a, array $b) => ($b['effectiveness_pct'] ?? -1) <=> ($a['effectiveness_pct'] ?? -1)
        );

        return $rows;
    }

    /**
     * Organization-wide totals for the header strip.
     *
     * The average skips unscored people — including them as zeroes would drag
     * the number down with every admin who does not track time.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{active_s: int, inactive_s: int, avg_effectiveness: int}
     */
    public function totals(array $rows): array
    {
        $scored = array_filter($rows, fn (array $row) => $row['effectiveness_pct'] !== null);

        return [
            'active_s'          => (int) array_sum(array_column($rows, 'active_s')),
            'inactive_s'        => (int) array_sum(array_column($rows, 'inactive_s')),
            'avg_effectiveness' => $scored
                ? (int) round(array_sum(array_column($scored, 'effectiveness_pct')) / count($scored))
                : 0,
        ];
    }

    /**
     * Task counts per user. Ports task_completion_by_user().
     *
     * Deliberately NOT limited to the period: a task is open or done now, and
     * tasks carry no completion date to cut a window on.
     *
     * @param  list<int>  $ids
     * @return array<int, array{total: int, done: int}>
     */
    private function taskCompletion(array $ids): array
    {
        if (! $ids) {
            return [];
        }

        $counts = [];

        foreach (DB::table('tasks')
            ->selectRaw('user_id, status, COUNT(*) AS total')
            ->whereIn('user_id', $ids)
            ->groupBy('user_id', 'status')
            ->get() as $row) {
            $id = (int) $row->user_id;

            $counts[$id]['total'] = ($counts[$id]['total'] ?? 0) + (int) $row->total;
            $counts[$id]['done'] = ($counts[$id]['done'] ?? 0)
                + ($row->status === 'done' ? (int) $row->total : 0);
        }

        return $counts;
    }
}
