@extends('layouts.admin')

@section('title', 'Dashboard')

@section('content')
<div class="sbn-dashboard">

    <div class="sbn-welcome">
        <div class="sbn-welcome-text">
            <h2>Welcome back, Lucas</h2>
            <p>SBN Teaching Hub — your content at a glance.</p>
        </div>
    </div>

    {{-- Zone 1: Totals --}}
    <div class="sbn-stats-grid">
        @php
            $modules = [
                ['label' => 'Leadsheets',     'key' => 'leadsheets',    'color' => 'var(--clr-mod-leadsheet)',   'icon' => '<path fill-rule="evenodd" d="M4 4a2 2 0 012-2h4.586A2 2 0 0112 2.586L15.414 6A2 2 0 0116 7.414V16a2 2 0 01-2 2H6a2 2 0 01-2-2V4z" clip-rule="evenodd"/>'],
                ['label' => 'Chord Diagrams', 'key' => 'chords',        'color' => 'var(--clr-mod-chord)',       'icon' => '<path d="M18 3a1 1 0 00-1.196-.98l-10 2A1 1 0 006 5v9.114A4.369 4.369 0 005 14c-1.657 0-3 .895-3 2s1.343 2 3 2 3-.895 3-2V7.82l8-1.6v5.894A4.37 4.37 0 0015 12c-1.657 0-3 .895-3 2s1.343 2 3 2 3-.895 3-2V3z"/>'],
                ['label' => 'Progressions',   'key' => 'progressions',  'color' => 'var(--clr-mod-progression)', 'icon' => '<path fill-rule="evenodd" d="M3 4a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm0 4a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1zm0 4a1 1 0 011-1h12a1 1 0 110 2H4a1 1 0 01-1-1z" clip-rule="evenodd"/>'],
                ['label' => 'Rhythms',        'key' => 'rhythms',       'color' => 'var(--clr-mod-rhythm)',      'icon' => '<path fill-rule="evenodd" d="M9.383 3.076A1 1 0 0110 4v12a1 1 0 01-1.707.707L4.586 13H2a1 1 0 01-1-1V8a1 1 0 011-1h2.586l3.707-3.707a1 1 0 011.09-.217z" clip-rule="evenodd"/>'],
                ['label' => 'Voicing Links',  'key' => 'voicing_usage', 'color' => 'var(--clr-mod-voicing)',     'icon' => '<path d="M11 17a1 1 0 001.447.894l4-2A1 1 0 0017 15V9.236a1 1 0 00-1.447-.894l-4 2a1 1 0 00-.553.894V17z"/><path d="M15.211 6.276a1 1 0 000-1.788l-4.764-2.382a1 1 0 00-.894 0L4.789 4.488a1 1 0 000 1.788l4.764 2.382a1 1 0 00.894 0l4.764-2.382z"/>'],
                ['label' => 'Pending Drafts', 'key' => 'drafts',        'color' => 'var(--clr-warning)',         'icon' => '<path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>'],
                ['label' => 'Courses',        'key' => 'courses',       'color' => 'var(--clr-accent)',          'icon' => '<path d="M10.394 2.08a1 1 0 00-.788 0l-7 3a1 1 0 000 1.84L5.25 8.051a.999.999 0 01.356-.257l4-1.714a1 1 0 11.788 1.838L7.667 9.088l1.94.831a1 1 0 00.787 0l7-3a1 1 0 000-1.838l-7-3zM3.31 9.397L5 10.12v4.102a8.969 8.969 0 00-1.05-.174 1 1 0 01-.89-.89 11.115 11.115 0 01.25-3.762zM9.3 16.573A9.026 9.026 0 007 14.935v-3.957l1.818.78a3 3 0 002.364 0l5.508-2.361a11.026 11.026 0 01.25 3.762 1 1 0 01-.89.89 8.968 8.968 0 00-5.35 2.524 1 1 0 01-1.4 0zM6 18a1 1 0 001-1v-2.065a8.935 8.935 0 00-2-.712V17a1 1 0 001 1z"/>'],
                ['label' => 'Lessons',        'key' => 'lessons',       'color' => 'var(--clr-info, #2563eb)',   'icon' => '<path fill-rule="evenodd" d="M4 4a2 2 0 012-2h4.586A2 2 0 0112 2.586L15.414 6A2 2 0 0116 7.414V16a2 2 0 01-2 2H6a2 2 0 01-2-2V4zm2 6a1 1 0 011-1h6a1 1 0 110 2H7a1 1 0 01-1-1zm1 3a1 1 0 100 2h6a1 1 0 100-2H7z" clip-rule="evenodd"/>'],
                ['label' => 'Skill Nodes',    'key' => 'skill_nodes',   'color' => 'var(--clr-success, #16a34a)','icon' => '<path fill-rule="evenodd" d="M11.3 1.046A1 1 0 0112 2v5h4a1 1 0 01.82 1.573l-7 10A1 1 0 018 18v-5H4a1 1 0 01-.82-1.573l7-10a1 1 0 011.12-.38z" clip-rule="evenodd"/>'],
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

    <div class="sbn-dash-lower">

        {{-- Zone 2: Recently edited --}}
        <div class="sbn-dash-card">
            <div class="sbn-dash-card-header">
                <h3>Recently Edited</h3>
            </div>
            <div class="sbn-dash-card-body">
                @if(empty($recentlyEdited))
                    <p class="sbn-dash-empty">No recent edits found.</p>
                @else
                    <table class="sbn-table sbn-table-compact">
                        <thead>
                            <tr>
                                <th style="width:100px;">Type</th>
                                <th>Title</th>
                                <th style="width:140px;">Updated</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($recentlyEdited as $item)
                            <tr>
                                <td>
                                    <span class="sbn-badge sbn-badge-type">{{ $item['type'] }}</span>
                                </td>
                                <td>
                                    <a href="{{ $item['edit_url'] }}" class="sbn-dash-link">
                                        {{ $item['title'] }}
                                    </a>
                                </td>
                                <td style="color:var(--clr-text-dim);font-size:13px;">
                                    {{ \Carbon\Carbon::parse($item['updated_at'])->diffForHumans() }}
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>

        {{-- Zone 3: Content Health --}}
        <div class="sbn-dash-card">
            <div class="sbn-dash-card-header">
                <h3>Content Health</h3>
                <a href="{{ route('admin.skill-nodes.coverage') }}" class="sbn-dash-card-link">View all →</a>
            </div>
            <div class="sbn-dash-card-body">
                <table class="sbn-table sbn-table-compact">
                    <tbody>
                        @foreach($healthSummary as $item)
                        <tr>
                            <td style="font-size:13px;">{{ $item['label'] }}</td>
                            <td style="width:60px;text-align:right;">
                                <a href="{{ route('admin.skill-nodes.coverage') }}#{{ $item['anchor'] }}"
                                   class="sbn-health-count {{ $item['count'] > 0 ? 'sbn-health-count--warn' : 'sbn-health-count--ok' }}">
                                    {{ $item['count'] }}
                                </a>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

    </div>{{-- .sbn-dash-lower --}}

</div>
@endsection
