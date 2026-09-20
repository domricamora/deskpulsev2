{{--
    The authenticated app shell — sidebar plus main column.

    Ported from server/templates/layout.php. The sidebar's three visibility
    rules live in App\Support\Navigation rather than in this file: expressing
    them as conditions inside the loop is how one of them quietly stops
    applying. Everything the shell needs arrives from NavigationComposer, so a
    new page cannot forget to supply it.

    Expects from the page: $title, $active. Everything else is composed.
--}}
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>{{ $title ?? 'DeskPulse' }} · {{ config('app.name') }}</title>

    {{-- The app is never indexed. --}}
    <meta name="robots" content="noindex, nofollow">

    @stack('head')

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="app">

<aside class="sidebar" id="app-sidebar">
    <a class="brand" href="{{ url('/?site=1') }}">
        <img src="{{ url('/assets/img/favicon.svg') }}" width="22" height="22" alt="">
        <span class="brand-mark">Desk<span class="brand-mark-accent">Pulse</span></span>
    </a>

    @if (! empty($orgLogo))
        <div class="org-brand"><img src="{{ $orgLogo }}" alt="Company logo"></div>
    @endif

    <nav>
        {{-- The platform console, for an operator who is not acting as a tenant. --}}
        @if (! empty($navPlatform))
            @php
                $orgCluster = in_array($active ?? '', App\Support\Navigation::ORG_CLUSTER, true);
            @endphp
            <div class="nav-group">
                <div class="nav-group-label">Platform</div>
                @foreach ($navPlatform as [$key, $label, $href, $icon])
                    <a class="{{ ($key === 'platform_orgs' ? $orgCluster : ($active ?? '') === $key) ? 'on' : '' }}"
                       href="{{ url($href) }}">
                        <x-icon :name="$icon" /><span class="lbl">{{ $label }}</span>
                    </a>
                @endforeach
            </div>
        @endif

        @foreach ($navGroups as $groupLabel => $items)
            <div class="nav-group">
                <div class="nav-group-label">{{ $groupLabel }}</div>
                @foreach ($items as $item)
                    <a class="{{ ($active ?? '') === $item['key'] ? 'on' : '' }}"
                       href="{{ url($item['href']) }}"
                       title="{{ $item['tooltip'] }}">
                        <x-icon :name="$item['icon']" /><span class="lbl">{{ $item['label'] }}</span>
                        @if ($item['key'] === 'approvals' && ! empty($navBadges['approvals']))
                            <span class="badge">{{ $navBadges['approvals'] }}</span>
                        @endif
                        @if ($item['key'] === 'overtime' && ! empty($navBadges['overtime']))
                            <span class="badge">{{ $navBadges['overtime'] }}</span>
                        @endif
                    </a>
                @endforeach
            </div>
        @endforeach
    </nav>

    @if ($navUser)
        <div class="side-foot">
            <div class="who">{{ $navUser->name }}<br><small>{{ $navUser->role?->label() }}</small></div>
            <a class="ghost" href="{{ url('/logout') }}">Sign out</a>
        </div>
    @endif
</aside>

<div class="nav-backdrop" id="nav-backdrop"></div>

<main class="main">
    {{-- A platform operator looking at a customer's data must always be able to
         tell that is what they are doing. --}}
    @if ($actingOrg)
        <div class="acting-banner">★ Viewing <b>{{ $actingOrg }}</b> as super admin
            · <a href="{{ url('/app/platform/return') }}">Return to platform</a></div>
    @endif

    <header class="topbar">
        <button type="button" class="nav-toggle" id="nav-toggle" aria-label="Open menu"
                aria-controls="app-sidebar" aria-expanded="false"><x-icon name="menu" /></button>
        <h1>{{ $title ?? '' }}</h1>
    </header>

    @include('partials.flashes')

    <div class="content">@yield('content')</div>
</main>

@stack('scripts')
</body>
</html>
