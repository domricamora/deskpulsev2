{{--
    Authenticated app shell — sidebar + main column.

    Phase 2 provides the STRUCTURE only. The navigation is capability-gated in the
    legacy app (docs/migration/authorization.md §2 describes the two-layer model:
    route guards plus in-page branching). Both layers land in Phase 5/6; the
    @stack and @yield slots below are where they attach.
--}}
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>@yield('title', 'Dashboard') · {{ config('app.name') }}</title>

    {{-- The app is never indexed. --}}
    <meta name="robots" content="noindex, nofollow">

    @stack('head')

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="app">

<aside class="sidebar" id="app-sidebar">
    <a class="brand" href="{{ url('/') }}">
        <span class="brand-mark">Desk<span class="brand-mark-accent">Pulse</span></span>
    </a>

    {{-- Organization logo, when one is set (organizations.logo_path). --}}
    @isset($orgLogo)
        <div class="org-brand"><img src="{{ $orgLogo }}" alt="Company logo"></div>
    @endisset

    <nav>
        @yield('nav')
    </nav>

    <div class="side-foot">
        @yield('sidebar-foot')
    </div>
</aside>

<div class="nav-backdrop" hidden></div>

<main class="main">
    <header class="topbar">
        @yield('topbar')
    </header>

    {{-- A super admin acting as an organization must always be able to see it. --}}
    @isset($actingAs)
        <div class="acting-banner" role="status">
            Acting as <strong>{{ $actingAs }}</strong>
            <a href="{{ url('/app/platform/return') }}">Return to platform</a>
        </div>
    @endisset

    @if (session('flash'))
        <div class="flash flash-{{ session('flash_type', 'info') }}" role="status">
            {{ session('flash') }}
        </div>
    @endif

    @yield('content')
</main>

@stack('scripts')
</body>
</html>
