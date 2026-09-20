<?php

namespace App\Console\Commands;

use App\Services\Agent\ScreenshotIngest;
use Illuminate\Console\Command;

/**
 * Delete screenshots past the platform retention window.
 *
 * The legacy app has no cron for this: one upload in fifty runs
 * `purge_old_screenshots(100)`. Retention therefore depends on upload volume,
 * so a quiet organization keeps its images well past the stated window, and an
 * organization that stops tracking entirely keeps them forever.
 *
 * Decision D12 schedules it instead. The opportunistic purge on upload is kept
 * as well — it costs nothing and covers an environment where the scheduler is
 * not running, which on shared hosting is a real possibility. This is a
 * behaviour change and a deliberate one: retention becomes timely rather than
 * volume-driven, strictly in the privacy-positive direction.
 *
 * @see docs/migration/screenshots.md §5
 */
class PruneScreenshots extends Command
{
    protected $signature = 'screenshots:prune {--limit=500 : Most rows to delete in one pass}';

    protected $description = 'Delete screenshots past the platform retention window (decision D12)';

    public function handle(ScreenshotIngest $screenshots): int
    {
        $deleted = $screenshots->purge((int) $this->option('limit'));

        $this->info($deleted === 0
            ? 'Nothing past the retention window.'
            : "Deleted {$deleted} screenshot(s) past the retention window.");

        return self::SUCCESS;
    }
}
