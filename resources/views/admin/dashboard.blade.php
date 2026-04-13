@extends('layouts.admin')

@section('title', 'Dashboard')
@section('topbar-title', 'Dashboard')

@section('content')
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-value">{{ \DB::table('sbn_leadsheets')->count() }}</div>
            <div class="stat-label">Leadsheets</div>
        </div>
        <div class="stat-card">
            <div class="stat-value">{{ \DB::table('sbn_chord_diagrams')->count() }}</div>
            <div class="stat-label">Chord Diagrams</div>
        </div>
        <div class="stat-card">
            <div class="stat-value">{{ \DB::table('sbn_chord_progressions')->count() }}</div>
            <div class="stat-label">Progressions</div>
        </div>
        <div class="stat-card">
            <div class="stat-value">{{ \DB::table('sbn_rhythm_patterns')->count() }}</div>
            <div class="stat-label">Rhythm Patterns</div>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h2>Getting Started</h2></div>
        <div class="card-body">
            <p style="color: var(--sbn-text); line-height: 1.7;">
                Welcome to the SBN Teaching Hub admin. Use the sidebar to manage your content modules.
                <strong>Phase 2 (Rhythm Patterns)</strong> is now live — create and edit rhythm patterns
                with the interactive grid editor.
            </p>
        </div>
    </div>
@endsection
