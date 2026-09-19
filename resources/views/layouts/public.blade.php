<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>@yield('title', config('app.name')) · {{ config('app.name') }}</title>

    @hasSection('description')
        <meta name="description" content="@yield('description')">
    @endif

    @hasSection('canonical')
        <link rel="canonical" href="@yield('canonical')">
    @endif

    @stack('head')

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="app">

    <main class="pub-main">
        @yield('content')
    </main>

    @stack('scripts')
</body>
</html>
