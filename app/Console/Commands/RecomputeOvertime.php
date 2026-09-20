<?php

namespace App\Console\Commands;

use App\Services\Reporting\Overtime;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Restate every closed session's overtime split.
 *
 * Needed once after the day-bucketing correction (decision D2): rows computed
 * before it were split against the host's clock rather than the organization's,
 * and nothing recomputes them on its own — `recomputePending()` only picks up
 * rows marked `overtime_computed = 0`, and these are all marked done.
 *
 * This changes what people are paid, so it reports before committing and
 * `--dry-run` shows the whole impact without writing anything. Run the dry run
 * first, every time.
 *
 * What it cannot change, by design: an overtime decision HR has already made.
 * An approved or rejected split keeps its status and only its AMOUNT is
 * refreshed — so an approved session whose amount moves DOES move money, and
 * those are counted separately below because they are the ones worth looking at.
 *
 * @see docs/migration/monitoring.md §4
 */
class RecomputeOvertime extends Command
{
    protected $signature = 'overtime:recompute
                            {--dry-run : Report the impact and write nothing}
                            {--user= : Restrict to one user id}';

    protected $description = 'Restate overtime for closed sessions in the organization timezone (decision D2)';

    public function handle(Overtime $overtime): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $before = $this->snapshot();

        if ($before === []) {
            $this->info('No closed sessions to restate.');

            return self::SUCCESS;
        }

        $userIds = DB::table('sessions')
            ->whereNotNull('ended_at')
            ->when($this->option('user'), fn ($q) => $q->where('user_id', (int) $this->option('user')))
            ->distinct()
            ->pluck('user_id');

        DB::beginTransaction();

        $progress = $this->output->createProgressBar($userIds->count());

        foreach ($userIds as $userId) {
            $overtime->recompute((int) $userId);
            $progress->advance();
        }

        $progress->finish();
        $this->newLine(2);

        $this->report($before, $this->snapshot());

        if ($dryRun) {
            DB::rollBack();
            $this->warn('Dry run — rolled back, nothing was written.');
        } else {
            DB::commit();
            $this->info('Committed.');
        }

        return self::SUCCESS;
    }

    /**
     * Every closed session's current split, keyed by id.
     *
     * @return array<int, array{s: int, status: string}>
     */
    private function snapshot(): array
    {
        return DB::table('sessions')
            ->whereNotNull('ended_at')
            ->when($this->option('user'), fn ($q) => $q->where('user_id', (int) $this->option('user')))
            ->get(['id', 'user_id', 'overtime_s', 'overtime_status'])
            ->keyBy('id')
            ->map(fn ($row) => [
                's'      => (int) $row->overtime_s,
                'status' => (string) ($row->overtime_status ?? 'none'),
                'user'   => (int) $row->user_id,
            ])
            ->all();
    }

    /**
     * @param  array<int, array{s: int, status: string, user: int}>  $before
     * @param  array<int, array{s: int, status: string, user: int}>  $after
     */
    private function report(array $before, array $after): void
    {
        $changed = 0;
        $approvedChanged = 0;
        $totalBefore = 0;
        $totalAfter = 0;
        $byUser = [];

        foreach ($before as $id => $was) {
            $is = $after[$id] ?? $was;

            $totalBefore += $was['s'];
            $totalAfter += $is['s'];

            if ($was['s'] === $is['s']) {
                continue;
            }

            $changed++;
            $byUser[$was['user']] = ($byUser[$was['user']] ?? 0) + ($is['s'] - $was['s']);

            // Already signed off by HR, so this delta is money.
            if ($was['status'] === 'approved') {
                $approvedChanged++;
            }
        }

        $this->table(
            ['', 'Sessions', 'Overtime hours'],
            [
                ['before', count($before), $this->hours($totalBefore)],
                ['after', count($after), $this->hours($totalAfter)],
                ['changed', $changed, $this->hours($totalAfter - $totalBefore)],
            ]
        );

        if ($approvedChanged > 0) {
            $this->warn("{$approvedChanged} session(s) HR had already APPROVED changed amount — this moves pay.");
        }

        if ($byUser === []) {
            $this->info('No session changed. The old and new clocks agree on this data.');

            return;
        }

        arsort($byUser);

        $this->line('Largest movers:');

        foreach (array_slice($byUser, 0, 10, true) as $userId => $delta) {
            $this->line(sprintf('  user %-6d %+s hours', $userId, $this->hours($delta)));
        }
    }

    private function hours(int $seconds): string
    {
        return number_format($seconds / 3600, 2);
    }
}
