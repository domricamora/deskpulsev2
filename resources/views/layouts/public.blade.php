{{--
    Minimal public shell for auth and share pages. Ports templates/layout_public.php.

    Defaults to noindex: /login and /register are thin duplicate-content pages,
    and a share URL is a capability token — anything holding one must never be
    crawlable. A page that wants indexing sets @section('robots').
--}}
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>@yield('title', $title ?? config('app.name')) · {{ config('app.name') }}</title>

    <meta name="robots" content="@yield('robots', 'noindex,nofollow')">

    @hasSection('description')
        <meta name="description" content="@yield('description')">
    @endif

    @hasSection('canonical')
        <link rel="canonical" href="@yield('canonical')">
    @endif

    @stack('head')

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="public">

    <header class="pub-top">
        <a class="brand" href="{{ url('/') }}">
            <img src="{{ url('/assets/img/favicon.svg') }}" width="22" height="22" alt="">
            <span class="brand-mark">Desk<span class="brand-mark-accent">Pulse</span></span>
        </a>
        <nav>
            <a href="{{ url('/login') }}">Sign in</a>
            <a class="btn" href="{{ url('/register') }}">Get started</a>
        </nav>
    </header>

    @include('partials.flashes', ['centered' => true])

    <main class="pub-main">
        @yield('content')
    </main>

    <footer class="pub-foot">
        © DeskPulse · Remote work monitoring for teams &amp; individuals
    </footer>

    @stack('scripts')
</body>
</html>
