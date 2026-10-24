<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-force-light="{{ str_starts_with($page['component'] ?? '', 'Public/') ? '1' : '0' }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex, nofollow">
        <meta name="theme-color" content="#0b1020">

        {{-- Theme before first paint (no flash). Client-facing pages always stay light. --}}
        <script>
            (function () {
                var d = document.documentElement, t = 'system';
                try { t = localStorage.getItem('klok-theme') || 'system'; } catch (e) {}
                if (d.dataset.forceLight !== '1' && (t === 'dark' || (t === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches))) d.classList.add('dark');
            })();
        </script>

        <title inertia>{{ config('app.name', 'Klok Ads') }}</title>
        <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
        {{-- Installed from the personal side it becomes its own app: "Klok Personal". --}}
        <link rel="manifest" href="{{ asset(str_starts_with($page['component'] ?? '', 'Personal/') ? 'personal.webmanifest' : 'manifest.webmanifest') }}">
        <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
        <meta name="apple-mobile-web-app-title" content="Klok">

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700|plus-jakarta-sans:600,700,800&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @routes
        @vite(['resources/js/app.js', "resources/js/Pages/{$page['component']}.vue"])
        @inertiaHead
    </head>
    <body class="bg-canvas font-sans text-ink-900 antialiased">
        @inertia
    </body>
</html>
