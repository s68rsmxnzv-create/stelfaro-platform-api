<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, interactive-widget=resizes-content">
        <meta name="theme-color" content="#07111f">
        <meta name="mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
        <meta name="apple-mobile-web-app-title" content="Stelfaro">

        <title inertia>{{ config('app.name', 'Stelfaro') }}</title>

        <link rel="manifest" href="/manifest.json" crossorigin="use-credentials">
        <link rel="icon" href="/pwa/stelfaro.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/pwa/stelfaro-192.png">

        <script nonce="{{ Vite::cspNonce() }}">
            (() => {
                try {
                    const storedTheme = window.localStorage.getItem('stelfaro:theme');
                    const prefersDark = window.matchMedia?.('(prefers-color-scheme: dark)').matches ?? false;
                    const darkMode = storedTheme ? storedTheme === 'dark' : prefersDark;

                    document.documentElement.classList.toggle('dark', darkMode);
                    document.documentElement.dataset.theme = darkMode ? 'dark' : 'light';
                    document.documentElement.style.colorScheme = darkMode ? 'dark' : 'light';
                } catch {
                    document.documentElement.dataset.theme = 'light';
                }
            })();
        </script>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @routes(nonce: Vite::cspNonce())
        @vite('resources/js/app.js')
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
