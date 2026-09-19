{{--
    Phase 2 scaffold check — a living style guide.

    This exists so the design foundation can be verified by eye before Phase 18
    builds pages on top of it: the tokens, typography, icon set and app shell.
    It is NOT part of the product. Phase 6 replaces this route with the real
    dashboard and Phase 18 with the marketing home.
--}}
@extends('layouts.app')

@section('title', 'Scaffold check')

@section('nav')
    <div class="nav-group">
        <div class="nav-group-label">Phase 2</div>
        <a href="#tokens"><x-icon name="overview" /><span class="lbl">Tokens</span></a>
        <a href="#type"><x-icon name="payslip" /><span class="lbl">Typography</span></a>
        <a href="#icons"><x-icon name="image" /><span class="lbl">Icons</span></a>
    </div>
@endsection

@section('sidebar-foot')
    Laravel {{ app()->version() }} · PHP {{ PHP_VERSION }}
@endsection

@section('topbar')
    <h1>Scaffold check</h1>
@endsection

@section('content')

    <p class="pv-lede">
        The Laravel + Tailwind foundation, rendered on the real app layout. Every value
        below is reproduced from the legacy stylesheet — if something looks wrong here,
        it will look wrong on every page built later.
    </p>

    <h2 id="tokens">Colour tokens</h2>
    <p class="pv-note">
        Dark-only by design. Tailwind's defaults would render this light.
    </p>

    @php
        $groups = [
            'Surfaces' => ['bg' => '#070b14', 'bg-2' => '#0b111e', 'card' => '#111a2e', 'card-2' => '#16213a'],
            'Ink & lines' => ['ink' => '#e9eef8', 'muted' => '#94a3bd', 'line' => '#233149', 'line-2' => '#2c3b56'],
            'Brand (blue)' => ['brand' => '#3b82f6', 'brand-dk' => '#2563eb', 'brand-lt' => '#60a5fa', 'brand-50' => '#132038', 'brand-100' => '#16294a'],
            'Accent (teal)' => ['teal' => '#2dd4bf', 'teal-dk' => '#14b8a6', 'teal-lt' => '#5eead4'],
            'Status' => ['good' => '#34d399', 'bad' => '#f87171', 'warn' => '#fbbf24'],
        ];
    @endphp

    @foreach ($groups as $label => $tokens)
        <h3>{{ $label }}</h3>
        <div class="pv-swatches">
            @foreach ($tokens as $name => $hex)
                <div class="pv-swatch">
                    <span class="pv-chip" style="background: var(--color-{{ $name }})"></span>
                    <code>--color-{{ $name }}</code>
                    <small>{{ $hex }}</small>
                </div>
            @endforeach
        </div>
    @endforeach

    <h2 id="type">Typography</h2>
    <p class="pv-note">
        Headings in Plus Jakarta Sans, body in Inter — both self-hosted, because the
        CSP blocks third-party font hosts.
    </p>

    <h1>Heading 1 — Plus Jakarta Sans</h1>
    <h2>Heading 2 — Plus Jakarta Sans</h2>
    <h3>Heading 3 — Plus Jakarta Sans</h3>
    <p>
        Body copy in Inter at 15px / 1.55. Here is <a href="#type">a link</a>, some
        <code>inline code</code>, and <span class="pv-muted">muted secondary text</span>.
    </p>

    <h2 id="icons">Icon set <small>({{ count($icons) }})</small></h2>
    <p class="pv-note">
        Extracted verbatim from the legacy layout — a test asserts every path is
        byte-identical.
    </p>

    <div class="pv-icons">
        @foreach ($icons as $name => $path)
            <div class="pv-icon">
                <x-icon :name="$name" />
                <small>{{ $name }}</small>
            </div>
        @endforeach
    </div>

    <h2>Surfaces &amp; shape</h2>
    <div class="pv-cards">
        <div class="pv-card">
            <strong>Card</strong>
            <p class="pv-muted">4px radius, 1px line, raised shadow.</p>
        </div>
        <div class="pv-card pv-card-2">
            <strong>Raised card</strong>
            <p class="pv-muted">The second surface level.</p>
        </div>
    </div>

    <p class="pv-foot">
        Phase 2 scaffold. No business logic exists yet — the dashboard arrives in
        Phase 6, the full component set in Phase 18.
    </p>

@endsection
