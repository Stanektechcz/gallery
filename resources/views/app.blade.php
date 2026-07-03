<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#1a1a2e">

    <title inertia>{{ config('app.name', 'Stanektech Gallery') }}</title>

    <!-- PWA Meta -->
    <meta name="application-name" content="{{ config('app.name') }}">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="{{ config('app.name') }}">

    <!-- Security headers -->
    <meta name="referrer" content="strict-origin-when-cross-origin">

    <!-- Favicons (placeholder paths — replace with actual icons) -->
    <link rel="icon" type="image/png" href="/favicon.ico">

    @routes
    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/app.tsx'])
    @inertiaHead

    <script>
        window.__APP_NAME__ = @json(config('app.name'));
    </script>
</head>

<body class="h-full antialiased bg-[var(--color-bg-primary)] text-[var(--color-text-primary)]">
    @inertia
</body>

</html>
