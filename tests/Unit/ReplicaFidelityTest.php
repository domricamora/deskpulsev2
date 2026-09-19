<?php

/**
 * Replica fidelity — the migration must reproduce the existing product exactly,
 * changing only the stack. These compare the new assets against the legacy
 * application, which stays in the tree as the behavioural reference.
 *
 * Deliberately framework-free: they assert on files, so they stay fast and keep
 * working even if the application fails to boot.
 *
 * docs/migration/ui-inventory.md
 */

/** Repository root, without depending on the Laravel container. */
function repo_path(string $rel = ''): string
{
    return dirname(__DIR__, 2) . ($rel !== '' ? '/' . ltrim($rel, '/') : '');
}

test('every icon is byte-identical to the legacy set', function () {
    $icons = require repo_path('resources/icons/icons.php');
    $legacy = file_get_contents(repo_path('server/templates/layout.php'));

    expect($icons)->toHaveCount(30);

    foreach ($icons as $name => $path) {
        expect($legacy)->toContain($path);
    }
});

test('the design tokens match the legacy palette exactly', function () {
    // Taken from server/public/assets/css/deskpulse.css :root.
    // Tailwind's defaults would produce a light theme; these values are the
    // specification, not a starting point. docs/migration/ui-inventory.md §2.
    $expected = [
        '--color-bg'        => '#070b14',
        '--color-bg-2'      => '#0b111e',
        '--color-card'      => '#111a2e',
        '--color-card-2'    => '#16213a',
        '--color-ink'       => '#e9eef8',
        '--color-muted'     => '#94a3bd',
        '--color-line'      => '#233149',
        '--color-line-2'    => '#2c3b56',
        '--color-brand'     => '#3b82f6',
        '--color-brand-dk'  => '#2563eb',
        '--color-brand-lt'  => '#60a5fa',
        '--color-brand-50'  => '#132038',
        '--color-brand-100' => '#16294a',
        '--color-teal'      => '#2dd4bf',
        '--color-teal-dk'   => '#14b8a6',
        '--color-teal-lt'   => '#5eead4',
        '--color-good'      => '#34d399',
        '--color-bad'       => '#f87171',
        '--color-warn'      => '#fbbf24',
    ];

    $css = file_get_contents(repo_path('resources/css/app.css'));
    $legacyCss = strtolower(file_get_contents(repo_path('server/public/assets/css/deskpulse.css')));

    foreach ($expected as $token => $hex) {
        expect($css)->toContain("$token: $hex");
        // And the value must still be the one the legacy stylesheet uses.
        expect($legacyCss)->toContain($hex);
    }
});

test('the tight corner radius is preserved', function () {
    // Legacy uses 4px surfaces and 3px buttons; Tailwind's defaults are far rounder.
    $css = file_get_contents(repo_path('resources/css/app.css'));

    expect($css)->toContain('--radius-dp: 4px')
        ->and($css)->toContain('--radius-btn: 3px');
});

test('the app is dark-only with no light theme', function () {
    // docs/migration/ui-inventory.md §1 — a light variant is new functionality.
    $css = file_get_contents(repo_path('resources/css/app.css'));

    expect($css)->toContain('--color-bg: #070b14')
        ->and($css)->not->toContain('prefers-color-scheme');
});

test('fonts are self-hosted with no third-party font host', function () {
    // The CSP sets font-src 'self'; a font CDN would be blocked outright.
    $css = file_get_contents(repo_path('resources/css/app.css'));
    $vite = file_get_contents(repo_path('vite.config.js'));

    expect($css)->toContain("font-family: 'Inter'")
        ->and($css)->toContain("font-family: 'Plus Jakarta Sans'")
        ->and($css)->not->toMatch('/fonts\.googleapis|fonts\.gstatic|bunny\.net/i')
        ->and($vite)->not->toContain('laravel-vite-plugin/fonts');

    expect(repo_path('resources/fonts/inter-latin.woff2'))->toBeFile()
        ->and(repo_path('resources/fonts/plus-jakarta-sans-latin.woff2'))->toBeFile();
});

test('the legacy application is still present as the reference', function () {
    // Nothing in this migration may delete the PHP server or the Python agent.
    expect(repo_path('server/src/webhooks.php'))->toBeFile()
        ->and(repo_path('server/src/auth.php'))->toBeFile()
        ->and(repo_path('server/schema.sql'))->toBeFile()
        ->and(repo_path('agent/webhook_client.py'))->toBeFile();
});

test('composer pins the platform to the production PHP version', function () {
    // The production host serves PHP 8.3 only. Without this pin Composer resolves
    // against the local CLI and can select a package production cannot run.
    $composer = json_decode(file_get_contents(repo_path('composer.json')), true);

    expect($composer['config']['platform']['php'] ?? null)->toStartWith('8.3')
        ->and($composer['require']['php'] ?? '')->toContain('8.3');
});
