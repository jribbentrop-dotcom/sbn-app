<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title inertia>{{ config('app.name', 'Laravel') }}</title>

        <link rel="stylesheet" href="{{ asset('css/sbn-design-system.css') }}">
        <link rel="stylesheet" href="{{ asset('css/mega-menu.css') }}">
        <link rel="stylesheet" href="{{ asset('css/chord-symbols.css') }}">
        <script src="{{ asset('js/chords.js') }}"></script>

        <!-- Scripts & Styles -->
        @vite(['resources/css/app.css', 'resources/js/app.ts'])
        @inertiaHead
    </head>
    <body class="font-sans antialiased mega-menu-app">
        @inertia
    </body>
</html>
