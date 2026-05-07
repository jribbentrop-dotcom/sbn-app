<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title inertia>{{ config('app.name', 'Laravel') }}</title>

        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300..700;1,9..40,300..700&family=JetBrains+Mono:wght@400;500&family=Crimson+Text:ital,wght@0,400;0,600;1,400&display=swap" rel="stylesheet">

        <link rel="stylesheet" href="{{ asset('css/sbn-design-system.css') }}">
        <link rel="stylesheet" href="{{ asset('css/mega-menu.css') }}">
        <link rel="stylesheet" href="{{ asset('css/chord-symbols.css') }}">
        <link rel="stylesheet" href="{{ asset('css/shop.css') }}">
        <link rel="stylesheet" href="{{ asset('css/chord-library.css') }}">
        <link rel="stylesheet" href="{{ asset('css/rhythm-library.css') }}">
        <link rel="stylesheet" href="{{ asset('css/progression-library.css') }}">
        <link rel="stylesheet" href="{{ asset('css/song-library.css') }}">
        <link rel="stylesheet" href="{{ asset('css/course-player.css') }}">
        <script src="{{ asset('js/chords.js') }}"></script>

        <!-- Scripts & Styles -->
        @vite(['resources/css/app.css', 'resources/js/app.ts'])
        @inertiaHead
    </head>
    <body class="font-sans antialiased mega-menu-app">
        @inertia
    </body>
</html>
