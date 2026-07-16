<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#1a1a2e">
    <meta name="color-scheme" content="dark">

    <title inertia>{{ config('app.name', 'Stanektech Gallery') }}</title>

    <!-- PWA Meta -->
    <meta name="application-name" content="{{ config('app.name') }}">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="{{ config('app.name') }}">

    <!-- Security headers -->
    <meta name="referrer" content="strict-origin-when-cross-origin">

    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" type="image/svg+xml" href="/icons/maki-app.svg">
    <link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">

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
