<?php

namespace App\Support;

/**
 * Where uploaded media lives on disk.
 *
 * Ports upload_path(). Screenshots, organization logos and remote frames all
 * land under one directory, served at `/uploads/<relative path>`.
 *
 * ## Why this is not a Laravel disk yet
 *
 * Decisions D4 and D5 move screenshots and remote frames to a PRIVATE disk,
 * behind an authorizing controller, in Phases 9 and 16. Today they are public
 * files whose only protection is an unguessable 16-hex filename, and the paths
 * are already recorded in `screenshots.file_path` rows that exist in the live
 * database.
 *
 * Phase 7 is the ingest contract, so it writes exactly where the legacy writes
 * and records the same relative path — otherwise every screenshot already
 * stored becomes unreachable. Centralising it here is what makes D4 a change in
 * one place rather than in every handler.
 *
 * @see docs/migration/screenshots.md
 * @see docs/migration/migration-map.md D4, D5
 */
class Uploads
{
    /** Absolute path for a relative upload path, creating nothing. */
    public static function path(string $relative = ''): string
    {
        $directory = rtrim(public_path('uploads'), '/\\');

        return $relative === '' ? $directory : $directory . '/' . ltrim($relative, '/\\');
    }

    /** The public URL the agent and the dashboard both use. */
    public static function url(string $relative): string
    {
        return url('/uploads/' . ltrim($relative, '/'));
    }

    /**
     * Ensure the directory for a file exists.
     *
     * Screenshots nest per user and per session, so the first upload of every
     * session creates two levels.
     */
    public static function ensureDirectoryFor(string $absolutePath): void
    {
        $directory = dirname($absolutePath);

        if (! is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }
    }
}
