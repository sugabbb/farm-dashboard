<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex,nofollow">
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <script>
        const theme = localStorage.getItem('pbb-theme') || 'light';
        document.documentElement.dataset.theme = theme;
        document.documentElement.dataset.bsTheme = theme;
    </script>
    @viteReactRefresh
    @vite('resources/js/app.tsx')
    <x-inertia::head />
</head>
<body>
    <x-inertia::app />
</body>
</html>
