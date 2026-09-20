<?php

namespace App\Console\Commands;

use App\Services\Agent\ScreenshotIngest;
use App\Support\Uploads;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Move screenshots off the public document root onto the private disk.
 *
 * A one-time migration for decision D4, run once per environment — here, and
 * again on production at cutover. Until it runs, every screenshot row points at
 * a file the new serving route cannot find.
 *
 * It is idempotent and safe to re-run: a file already on the private disk is
 * counted and skipped, and a row whose file is missing from both roots is
 * reported rather than treated as an error, because retention may have removed
 * it while the row survived.
 *
 * The file is COPIED and then removed, not renamed, because the two roots can
 * be on different volumes. The public copy is deleted only after the private
 * one is confirmed written — a half-finished run leaves images reachable at the
 * old path rather than leaving them nowhere.
 *
 * @see docs/migration/screenshots.md §4
 */
class RelocateScreenshots extends Command
{
    protected $signature = 'screenshots:relocate
                            {--keep-public : Copy without deleting the public original}
                            {--dry-run : Report what would move and change nothing}';

    protected $description = 'Move existing screenshots from public/uploads onto the private disk (decision D4)';

    public function handle(): int
    {
        $disk = ScreenshotIngest::disk();
        $dryRun = (bool) $this->option('dry-run');

        $moved = 0;
        $already = 0;
        $missing = 0;

        DB::table('screenshots')
            ->orderBy('id')
            ->select(['id', 'file_path'])
            ->chunk(500, function ($rows) use ($disk, $dryRun, &$moved, &$already, &$missing) {
                foreach ($rows as $row) {
                    $target = ScreenshotIngest::diskPath($row->file_path);

                    if ($disk->exists($target)) {
                        $already++;

                        continue;
                    }

                    $source = Uploads::path($row->file_path);

                    if (! is_file($source)) {
                        $missing++;
                        $this->warn("screenshot {$row->id}: no file at {$source}");

                        continue;
                    }

                    if ($dryRun) {
                        $moved++;

                        continue;
                    }

                    $handle = fopen($source, 'rb');
                    $written = $disk->writeStream($target, $handle);

                    if (is_resource($handle)) {
                        fclose($handle);
                    }

                    if (! $written) {
                        $this->error("screenshot {$row->id}: could not write {$target}");

                        continue;
                    }

                    if (! $this->option('keep-public')) {
                        @unlink($source);
                    }

                    $moved++;
                }
            });

        $this->info(sprintf(
            '%s%d moved, %d already private, %d missing.',
            $dryRun ? '[dry run] ' : '',
            $moved,
            $already,
            $missing
        ));

        $orphans = $this->orphanedImageFiles();

        if ($orphans > 0) {
            $this->warn("{$orphans} image file(s) under the public uploads root have no screenshot row.");
            $this->warn('They are leftovers from deleted rows and are still world-readable by URL.');
            $this->warn('Nothing here removes them — check them, then delete them by hand.');
        }

        return self::SUCCESS;
    }

    /**
     * Image files still sitting under `uploads/{user}/{session}/` with no row
     * pointing at them.
     *
     * Retention deletes a row and its file together, but a database reset or a
     * re-seed leaves the files behind, and every one of them stays readable by
     * anyone holding the URL. Counting them is the point: this command cannot
     * know whether a stray file is a forgotten screenshot or something a human
     * put there, so it reports and leaves the deleting to a person.
     */
    private function orphanedImageFiles(): int
    {
        $root = Uploads::path();

        if (! is_dir($root)) {
            return 0;
        }

        $known = [];

        foreach (DB::table('screenshots')->pluck('file_path') as $path) {
            $known[str_replace('\\', '/', (string) $path)] = true;
        }

        $orphans = 0;

        // Only the numeric {user}/{session} tree. `logos/` is public on purpose.
        foreach (glob($root . '/[0-9]*/[0-9]*/*') ?: [] as $file) {
            if (! is_file($file)) {
                continue;
            }

            $relative = ltrim(str_replace('\\', '/', substr($file, strlen($root))), '/');

            if (! isset($known[$relative])) {
                $orphans++;
            }
        }

        return $orphans;
    }
}
