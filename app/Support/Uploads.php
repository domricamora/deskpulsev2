<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;

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

    /**
     * Convert an uploaded image to a resized WebP under `logos/`.
     *
     * Ports store_logo_webp(). The 480x160 box is a branding box, not a square:
     * logos are usually wide, and `min(..., 1.0)` is what stops a small one
     * being upscaled into a blur. Alpha is preserved, so a transparent PNG does
     * not gain a black background on the dark sidebar.
     *
     * @return array{0: string|false, 1: string|null} [relative path, error]
     */
    public static function storeLogoWebp(?UploadedFile $file, string $prefix = 'org'): array
    {
        if (! $file || ! $file->isValid()) {
            return [false, 'No file was uploaded.'];
        }

        if ($file->getSize() > 8 * 1024 * 1024) {
            return [false, 'Image is too large (max 8 MB).'];
        }

        if (! function_exists('imagewebp') || ! function_exists('imagecreatefromstring')) {
            return [false, 'Server image support (GD/WebP) is unavailable.'];
        }

        $raw = @file_get_contents($file->getRealPath());

        if ($raw === false || $raw === '') {
            return [false, 'Could not read the uploaded file.'];
        }

        $source = @imagecreatefromstring($raw);

        if (! $source) {
            return [false, 'Unsupported image — use PNG, JPG, GIF or WebP.'];
        }

        $width = imagesx($source);
        $height = imagesy($source);

        // A branding box, not a square — logos are usually wide. The 1.0 term
        // is what stops small art being upscaled into a blur.
        $scale = min(480 / $width, 160 / $height, 1.0);
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        $target = imagecreatetruecolor($targetWidth, $targetHeight);

        // Alpha preserved, or a transparent PNG gains a black background on the
        // dark sidebar.
        imagealphablending($target, false);
        imagesavealpha($target, true);
        imagefill($target, 0, 0, imagecolorallocatealpha($target, 0, 0, 0, 127));
        imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        $relative = 'logos/' . preg_replace('/[^a-z0-9]+/i', '', $prefix) . '-' . Token::random(8) . '.webp';

        self::ensureDirectoryFor(self::path($relative));

        $saved = imagewebp($target, self::path($relative), 82);

        imagedestroy($source);
        imagedestroy($target);

        return $saved ? [$relative, null] : [false, 'Could not save the converted image.'];
    }
}
