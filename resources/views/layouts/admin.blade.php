<!DOCTYPE html>
<html lang="en" data-theme="modern" x-data="{ sidebarOpen: true, sidebarCollapsed: localStorage.getItem('sbn_sidebar') === 'collapsed' }"
      x-init="$watch('sidebarCollapsed', val => localStorage.setItem('sbn_sidebar', val ? 'collapsed' : 'open'))">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard') — SBN Teaching Hub</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300..700;1,9..40,300..700&family=JetBrains+Mono:wght@400;500&family=Crimson+Text:ital,wght@0,400;0,600;1,400&display=swap" rel="stylesheet">
    <script defer src="https://cdn.jsdelivr.net/npm/@alpinejs/collapse@3.x.x/dist/cdn.min.js"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <link rel="stylesheet" href="{{ asset('css/sbn-design-system.css') }}">
    <link rel="stylesheet" href="{{ asset('css/admin2.css') }}?v={{ filemtime(public_path('css/admin2.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/chord-symbols.css') }}">
@stack('styles')
</head>
<body class="sbn-admin-body">

    <aside class="sbn-sidebar" :class="{ 'collapsed': sidebarCollapsed }">

        <div class="sbn-sidebar-logo">
            <a href="{{ route('admin.dashboard') }}">
                <span class="sbn-logo-icon">
                    <svg viewBox="0 0 32 32" fill="none"><defs><linearGradient id="sbn-lg" x1="0" y1="0" x2="32" y2="32" gradientUnits="userSpaceOnUse"><stop offset="0%" stop-color="#f39c12"/><stop offset="100%" stop-color="#e74c3c"/></linearGradient></defs><rect width="32" height="32" rx="8" fill="url(#sbn-lg)"/><path d="M8 22V10l8 6-8 6z" fill="#fff" opacity="0.9"/><path d="M16 22V10l8 6-8 6z" fill="#fff" opacity="0.6"/></svg>
                </span>
                <span class="sbn-logo-text" x-show="!sidebarCollapsed" x-transition.opacity>
                    SBN <span class="sbn-logo-accent">Hub</span>
                </span>
            </a>
        </div>

        <nav class="sbn-sidebar-nav">
            <div class="sbn-nav-section">
                <span class="sbn-nav-label" x-show="!sidebarCollapsed" x-transition.opacity>Overview</span>
                <x-admin.nav-item route="admin.dashboard" icon="dashboard" label="Dashboard" />
            </div>
            <div class="sbn-nav-section">
                <span class="sbn-nav-label" x-show="!sidebarCollapsed" x-transition.opacity>Content</span>
                <x-admin.nav-item route="admin.leadsheets.index" icon="leadsheet" label="Leadsheets" />
                <x-admin.nav-item route="admin.chords.index" icon="chord" label="Chord Diagrams" :badge="$pendingDraftsCount ?? null" />
                <x-admin.nav-item route="admin.progressions.index" icon="progression" label="Progressions" />
                <x-admin.nav-item route="admin.rhythms.index" icon="rhythm" label="Rhythm Patterns" />
                <x-admin.nav-item route="admin.courses.index" icon="leadsheet" label="Courses" />
            </div>
            <div class="sbn-nav-section">
                <span class="sbn-nav-label" x-show="!sidebarCollapsed" x-transition.opacity>Shop</span>
                <x-admin.nav-item route="admin.orders.index" icon="leadsheet" label="Orders" />
            </div>
            <div class="sbn-nav-section">
                <span class="sbn-nav-label" x-show="!sidebarCollapsed" x-transition.opacity>Tools</span>
                <x-admin.nav-item route="admin.progressions.builder" icon="progression" label="Prog. Builder" />
            </div>
        </nav>

        <button class="sbn-sidebar-toggle" @click="sidebarCollapsed = !sidebarCollapsed"
                :title="sidebarCollapsed ? 'Expand' : 'Collapse'">
            <svg viewBox="0 0 20 20" fill="currentColor" :class="{ 'rotated': sidebarCollapsed }">
                <path fill-rule="evenodd" d="M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z" clip-rule="evenodd"/>
            </svg>
        </button>
    </aside>

    <div class="sbn-main" :class="{ 'sidebar-collapsed': sidebarCollapsed }">

        <header class="sbn-topbar">
            <div class="sbn-topbar-left">
                <button class="sbn-mobile-menu-btn" @click="sidebarOpen = !sidebarOpen">
                    <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M3 5a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm0 5a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm0 5a1 1 0 011-1h6a1 1 0 110 2H4a1 1 0 01-1-1z" clip-rule="evenodd"/></svg>
                </button>
                <h1 class="sbn-page-title">@yield('title', 'Dashboard')</h1>
            </div>
            <div class="sbn-topbar-right">
                @hasSection('actions')
                    <div class="sbn-topbar-actions">@yield('actions')</div>
                @endif
                <div class="sbn-user-menu" x-data="{ open: false }">
                    <button @click="open = !open" class="sbn-user-btn">
                        <span class="sbn-user-avatar">L</span>
                        <span class="sbn-user-name">Lucas</span>
                    </button>
                    <div class="sbn-user-dropdown" x-show="open" @click.away="open = false" x-transition>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit">Sign out</button>
                        </form>
                    </div>
                </div>
            </div>
        </header>

        @if(session('success'))
            <div class="sbn-flash sbn-flash-success" x-data="{ show: true }" x-show="show"
                 x-init="setTimeout(() => show = false, 4000)" x-transition>
                {{ session('success') }}
            </div>
        @endif

        @if($errors->any())
            <div class="sbn-flash sbn-flash-danger" style="background-color: #fee2e2; color: #b91c1c; border-left: 4px solid #ef4444;" x-data="{ show: true }" x-show="show"
                 x-init="setTimeout(() => show = false, 8000)" x-transition>
                <ul style="margin: 0; padding-left: 20px;">
                    @foreach($errors->all() as $err)
                        <li>{{ $err }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <main class="sbn-content">
            @hasSection('context')
                <div class="sbn-content-layout">
                    <div class="sbn-content-main">
                        @yield('content')
                    </div>
                    <aside class="sbn-context-panel">
                        @yield('context')
                    </aside>
                </div>
            @else
                @yield('content')
            @endif
        </main>
    </div>

    @stack('vite')
    @stack('scripts')
</body>
</html>
