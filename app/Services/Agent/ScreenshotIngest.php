<?php

namespace App\Services\Agent;

use App\Models\Organization;
use App\Models\Screenshot;
use App\Models\WorkSession;
use App\Support\Platform;
use App\Support\Uploads;
use Illuminate\Support\Facades\DB;

/**
 * Screenshot upload and retention.
 *
 * The image arrives as the RAW request body with its metadata in the query
 * string. That is deliberate and must not become multipart: multipart would
 * empty the raw body, and the HMAC signs the raw body. See
 * docs/migration/api-contract.md §2.
 */
class ScreenshotIngest
{
    /** @var list<string> */
    public const ALLOWED_EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp'];

    public function __construct(private readonly Platform $platform) {}

    /**
     * Store the bytes and record the row. Returns the new screenshot id.
     *
     * Path is `{user_id}/{session_id}/{16 hex}.{ext}` relative to the uploads
     * directory — the same layout the legacy writes, because rows already in
     * the live database point at it.
     */
    public function store(WorkSession $session, string $bytes, string $extension, array $query): int
    {
        $relative = $session->user_id . '/' . $session->id
            . '/' . bin2hex(random_bytes(8)) . '.' . $extension;

        $absolute = Uploads::path($relative);
        Uploads::ensureDirectoryFor($absolute);
        file_put_contents($absolute, $bytes);

        $screenshot = Screenshot::create([
            'session_id' => $session->id,
            'ts'         => SessionIngest::timestamp($query['ts'] ?? null),
            'file_path'  => $relative,
            // Truthy for exactly these three, matching the legacy in_array().
            'blurred'    => in_array($query['blurred'] ?? '', ['1', 'true', 'True'], true) ? 1 : 0,
            'monitor'    => isset($query['monitor']) ? max(0, (int) $query['monitor']) : null,
        ]);

        // Retention, enforced opportunistically so an upload never stalls on it.
        if (random_int(1, 50) === 1) {
            $this->purge(100);
        }

        return (int) $screenshot->id;
    }

    /**
     * Delete screenshots — rows and files — past the platform retention
     * window. Capped per call. 0 days means keep forever.
     */
    public function purge(int $limit = 500): int
    {
        $days = $this->retentionDays();

        if ($days <= 0) {
            return 0;
        }

        $cutoff = gmdate('Y-m-d H:i:s', strtotime("-{$days} days"));

        $old = DB::table('screenshots')
            ->where('ts', '<', $cutoff)
            ->orderBy('ts')
            ->limit(max(1, $limit))
            ->get(['id', 'file_path']);

        $deleted = 0;

        foreach ($old as $row) {
            $path = Uploads::path($row->file_path);

            if (is_file($path)) {
                @unlink($path);
            }

            DB::table('screenshots')->where('id', $row->id)->delete();
            $deleted++;
        }

        return $deleted;
    }

    /**
     * Retention is platform-wide, read from the PLATFORM organization rather
     * than the tenant's — it is an operator setting, not a customer one.
     */
    private function retentionDays(): int
    {
        $platformOrgId = $this->platform->organizationId();

        if (! $platformOrgId) {
            return 0;
        }

        return (int) (Organization::query()
            ->whereKey($platformOrgId)
            ->value('screenshot_retention_days') ?? 0);
    }
}
