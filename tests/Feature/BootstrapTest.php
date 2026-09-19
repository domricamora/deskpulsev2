<?php

/**
 * Phase 2 smoke tests — the framework boots, the base layouts render, and the
 * icon component emits the legacy SVG markup.
 *
 * No business logic exists yet; that lands from Phase 5 onwards.
 */

use Illuminate\Support\Facades\Blade;

test('the application boots and serves a request', function () {
    $this->get('/')->assertSuccessful();
});

test('the public layout renders with the vite bundle', function () {
    $html = Blade::render(
        '@extends("layouts.public") @section("title","Test") @section("content")<p>hello</p>@endsection'
    );

    expect($html)->toContain('<!doctype html>')
        ->and($html)->toContain('Test · ' . config('app.name'))
        ->and($html)->toContain('hello');
});

test('the app layout renders the sidebar shell and brand wordmark', function () {
    $html = Blade::render(
        '@extends("layouts.app") @section("content")<p>dash</p>@endsection'
    );

    expect($html)->toContain('class="sidebar"')
        ->and($html)->toContain('class="main"')
        ->and($html)->toContain('Desk<span class="brand-mark-accent">Pulse</span>')
        ->and($html)->toContain('dash')
        // The authenticated app is never indexable.
        ->and($html)->toContain('name="robots"')
        ->and($html)->toContain('noindex');
});

test('the icon component emits the legacy SVG attributes', function () {
    $svg = Blade::render('<x-icon name="overview" />');

    expect($svg)->toContain('viewBox="0 0 24 24"')
        ->and($svg)->toContain('stroke="currentColor"')
        ->and($svg)->toContain('stroke-width="1.8"')
        ->and($svg)->toContain('aria-hidden="true"')
        ->and($svg)->toContain('<rect x="3" y="3" width="7" height="9" rx="1"/>');
});

test('an unknown icon renders nothing rather than breaking the page', function () {
    expect(trim(Blade::render('<x-icon name="does-not-exist" />')))->toBe('');
});
