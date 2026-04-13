@extends('layouts.admin')

@section('title', 'Dashboard')

@section('content')
<div class="sbn-dashboard">

    <div class="sbn-welcome">
        <div class="sbn-welcome-text">
            <h2>Welcome back, Lucas</h2>
            <p>SBN Teaching Hub -- your content at a glance.</p>
        </div>
    </div>

    <div class="sbn-stats-grid">
        @php
            $modules = [
                ['label' => 'Leadsheets',    'key' => 'leadsheets',    'color' => 'var(--clr-mod-leadsheet)',   'icon' => '<path fill-rule="evenodd" d="M4 4a2 2 0 012-2h4.586A2 2 0 0112 2.586L15.414 6A2 2 0 0116 7.414V16a2 2 0 01-2 2H6a2 2 0 01-2-2V4z" clip-rule="evenodd"/>'],
                ['label' => 'Chord Diagrams','key' => 'chords',        'color' => 'var(--clr-mod-chord)',       'icon' => '<path d="M18 3a1 1 0 00-1.196-.98l-10 2A1 1 0 006 5v9.114A4.369 4.369 0 005 14c-1.657 0-3 .895-3 2s1.343 2 3 2 3-.895 3-2V7.82l8-1.6v5.894A4.37 4.37 0 0015 12c-1.657 0-3 .895-3 2s1.343 2 3 2 3-.895 3-2V3z"/>'],
                ['label' => 'Progressions',  'key' => 'progressions',  'color' => 'var(--clr-mod-progression)', 'icon' => '<path fill-rule="evenodd" d="M3 4a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm0 4a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm0 4a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1z" clip-rule="evenodd"/>'],
                ['label' => 'Rhythm Patterns','key' => 'rhythms',      'color' => 'var(--clr-mod-rhythm)',      'icon' => '<path fill-rule="evenodd" d="M9.383 3.076A1 1 0 0110 4v12a1 1 0 01-1.707.707L4.586 13H2a1 1 0 01-1-1V8a1 1 0 011-1h2.586l3.707-3.707a1 1 0 011.09-.217z" clip-rule="evenodd"/>'],
                ['label' => 'Voicing Links', 'key' => 'voicing_usage', 'color' => 'var(--clr-mod-voicing)',     'icon' => '<path d="M11 17a1 1 0 001.447.894l4-2A1 1 0 0017 15V9.236a1 1 0 00-1.447-.894l-4 2a1 1 0 00-.553.894V17z"/><path d="M15.211 6.276a1 1 0 000-1.788l-4.764-2.382a1 1 0 00-.894 0L4.789 4.488a1 1 0 000 1.788l4.764 2.382a1 1 0 00.894 0l4.764-2.382z"/>'],
                ['label' => 'Pending Drafts','key' => 'drafts',        'color' => 'var(--clr-warning)',         'icon' => '<path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>'],
            ];
        @endphp

        @foreach($modules as $mod)
        <div class="sbn-stat-card">
            <div class="sbn-stat-icon" style="--accent: {{ $mod['color'] }}">
                <svg viewBox="0 0 20 20" fill="currentColor">{!! $mod['icon'] !!}</svg>
            </div>
            <div class="sbn-stat-info">
                <span class="sbn-stat-number">{{ number_format($stats[$mod['key']] ?? 0) }}</span>
                <span class="sbn-stat-label">{{ $mod['label'] }}</span>
            </div>
        </div>
        @endforeach
    </div>

    <div class="sbn-migration-card">
        <h3>Migration Progress</h3>
        <div class="sbn-migration-phases">
            @php
                $phases = [
                    ['num' => 0, 'label' => 'Local Setup',    'status' => 'completed'],
                    ['num' => 1, 'label' => 'Admin Shell',    'status' => 'completed'],
                    ['num' => 2, 'label' => 'Rhythms',        'status' => 'active'],
                    ['num' => 3, 'label' => 'Progressions',   'status' => ''],
                    ['num' => 4, 'label' => 'Chords',         'status' => ''],
                    ['num' => 5, 'label' => 'Leadsheets',     'status' => ''],
                    ['num' => 6, 'label' => 'Frontend',       'status' => ''],
                    ['num' => 7, 'label' => 'Courses & Pay',  'status' => ''],
                ];
            @endphp
            @foreach($phases as $phase)
            <div class="sbn-phase {{ $phase['status'] }}">
                <span class="sbn-phase-num">{{ $phase['num'] }}</span>
                <span class="sbn-phase-label">{{ $phase['label'] }}</span>
            </div>
            @endforeach
        </div>
    </div>
</div>
@endsection
