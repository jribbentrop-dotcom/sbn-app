@extends('layouts.admin')

@section('title', 'Chord Progressions')

@section('actions')
    <button class="sbn-btn sbn-btn-secondary" id="sbn-reprocess-btn"
            onclick="sbnReprocess()" title="Re-scan all leadsheets for progression matches">
        <svg width="14" height="14" viewBox="0 0 20 20" fill="currentColor" style="flex-shrink:0">
            <path fill-rule="evenodd" d="M4 2a1 1 0 011 1v2.101a7.002 7.002 0 0111.601 2.566 1 1 0 11-1.885.666A5.002 5.002 0 005.999 7H9a1 1 0 010 2H4a1 1 0 01-1-1V3a1 1 0 011-1zm.008 9.057a1 1 0 011.276.61A5.002 5.002 0 0014.001 13H11a1 1 0 110-2h5a1 1 0 011 1v5a1 1 0 11-2 0v-2.101a7.002 7.002 0 01-11.601-2.566 1 1 0 01.61-1.276z" clip-rule="evenodd"/>
        </svg>
        Reprocess
    </button>
    <a href="{{ route('admin.progressions.create') }}" class="sbn-btn sbn-btn-primary">+ New Progression</a>
@endsection

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/progressions.css') }}">
    <link rel="stylesheet" href="{{ asset('css/chord-symbols.css') }}">
    <style>
    /* ── Song group header with actions ─────────────────────── */
    .sbn-occ-song-header {
        display: flex;
        align-items: center;
        gap: 8px;
        width: 100%;
    }
    .sbn-occ-song-toggle {
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex: 1;
        background: none;
        border: none;
        padding: 0;
        cursor: pointer;
        font: inherit;
        color: inherit;
        text-align: left;
    }
    .sbn-occ-song-actions {
        display: flex;
        gap: 6px;
        flex-shrink: 0;
    }
    .sbn-btn-analysis {
        display: inline-flex; align-items: center; gap: 5px;
        font-size: 11px; font-weight: 600; padding: 5px 12px; border-radius: 5px;
        border: none; cursor: pointer; white-space: nowrap;
        background: linear-gradient(135deg, var(--clr-accent), var(--clr-accent-dark, #c0392b));
        color: white;
        transition: opacity 0.15s, box-shadow 0.15s;
        box-shadow: 0 1px 3px rgba(0,0,0,0.12);
    }
    .sbn-btn-analysis:hover { opacity: 0.9; box-shadow: 0 2px 6px rgba(0,0,0,0.18); }
    .sbn-btn-analysis.is-active {
        background: var(--clr-text);
        box-shadow: none;
    }

    /* ── Inline Analysis Panel (reuses leadsheet analysis styles) ── */
    .sbn-analysis-inline {
        border-top: 1px solid var(--clr-border);
        padding-top: 16px;
        margin-top: 8px;
    }
    .sbn-analysis-panel { padding: 0 4px; }
    .sbn-analysis-loading {
        text-align: center; padding: 24px;
        color: var(--clr-text-dim); font-size: 13px;
    }
    .sbn-analysis-key {
        font-size: 12px; color: var(--clr-text-dim); margin-bottom: 16px;
    }
    .sbn-analysis-key strong { color: var(--clr-text); }
    .sbn-analysis-section { margin-bottom: 20px; }
    .sbn-analysis-section:last-child { margin-bottom: 0; }
    .sbn-analysis-section-header {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-bottom: 8px;
        padding: 4px 0;
        border-bottom: 1px solid var(--clr-border);
        font-size: 12px;
    }
    .sbn-analysis-section-id {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 22px; height: 22px;
        background: var(--clr-accent);
        color: white;
        border-radius: 4px;
        font-weight: 700;
        font-size: 11px;
        flex-shrink: 0;
    }
    .sbn-analysis-section-name { font-weight: 600; color: var(--clr-text); }
    .sbn-analysis-section-key { color: var(--clr-text-muted); font-size: 11px; }
    .sbn-analysis-grid {
        display: flex;
        flex-wrap: wrap;
        gap: 0;
        border: 1px solid var(--clr-border);
        border-radius: 6px;
        overflow: hidden;
    }
    .sbn-analysis-measure {
        flex: 0 0 25%;
        min-width: 80px;
        padding: 6px 8px;
        border-right: 1px solid var(--clr-border);
        border-bottom: 1px solid var(--clr-border);
        background: var(--clr-surface);
        transition: background 0.15s;
    }
    .sbn-analysis-measure:nth-child(4n) { border-right: none; }
    .sbn-analysis-measure-num {
        font-size: 9px;
        color: var(--clr-text-muted);
        margin-bottom: 2px;
        font-family: var(--font-mono);
    }
    .sbn-analysis-chord-row {
        display: flex;
        gap: 6px;
        align-items: flex-start;
        min-height: 32px;
    }
    .sbn-analysis-chord-slot { text-align: center; flex: 1; }
    .sbn-analysis-chord-name {
        font-family: var(--font-mono);
        font-size: 12px;
        font-weight: 600;
        color: var(--clr-text);
    }
    .sbn-analysis-numeral {
        font-size: 11px;
        color: var(--clr-accent);
        font-weight: 600;
        font-family: var(--font-mono);
    }
    .sbn-analysis-numeral.is-unknown {
        color: var(--clr-text-muted);
        font-weight: 400;
        font-style: italic;
    }
    .sbn-analysis-matches {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
        margin-top: 8px;
    }
    .sbn-analysis-match {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 4px 10px;
        background: var(--clr-surface);
        border: 1px solid var(--clr-border);
        border-radius: 6px;
        font-size: 12px;
        cursor: default;
        transition: border-color 0.15s;
    }
    .sbn-analysis-match:hover { border-color: var(--clr-accent); }
    .sbn-analysis-match-cat {
        display: inline-block;
        padding: 1px 6px;
        border-radius: 3px;
        font-size: 10px;
        font-weight: 600;
        color: white;
        text-transform: uppercase;
    }
    .sbn-analysis-match-cat.cat-jazz { background: #8b5cf6; }
    .sbn-analysis-match-cat.cat-blues { background: #3b82f6; }
    .sbn-analysis-match-cat.cat-pop { background: #ec4899; }
    .sbn-analysis-match-cat.cat-modal { background: #10b981; }
    .sbn-analysis-match-cat.cat-classical { background: #f59e0b; }
    .sbn-analysis-match-cat.cat-latin { background: #ef4444; }
    .sbn-analysis-match-cat.cat-other { background: #6b7280; }
    .sbn-analysis-match-name { color: var(--clr-text); }
    .sbn-analysis-match-measures { color: var(--clr-text-muted); font-weight: 400; }
    .sbn-analysis-match-confidence { font-size: 10px; color: var(--clr-text-dim); }
    .sbn-analysis-match-root { font-size: 10px; color: var(--clr-text-muted); font-style: italic; }
    .sbn-analysis-measure.is-highlighted { background: var(--clr-accent-bg); }
    </style>
@endpush

@section('content')
<div x-data="progressionsPage()" x-cloak>

    {{-- ── Stats Cards ─────────────────────────────────────────── --}}
    <div class="sbn-stats-grid sbn-prog-stats">
        <div class="sbn-stat-card">
            <div class="sbn-stat-info">
                <span class="sbn-stat-number">{{ $stats['total_progressions'] }}</span>
                <span class="sbn-stat-label">Progressions</span>
            </div>
        </div>
        <div class="sbn-stat-card">
            <div class="sbn-stat-info">
                <span class="sbn-stat-number" id="stat-total-occ">{{ $stats['total_occurrences'] }}</span>
                <span class="sbn-stat-label">Occurrences</span>
            </div>
        </div>
        <div class="sbn-stat-card">
            <div class="sbn-stat-info">
                <span class="sbn-stat-number">{{ $stats['leadsheets_with_matches'] }}<span class="sbn-stat-dim">/{{ $stats['total_leadsheets'] }}</span></span>
                <span class="sbn-stat-label">Songs with Matches</span>
            </div>
        </div>
        @if($stats['most_common']->isNotEmpty())
        <div class="sbn-stat-card">
            <div class="sbn-stat-info">
                <span class="sbn-stat-number sbn-stat-name">{{ $stats['most_common']->first()->name }}</span>
                <span class="sbn-stat-label">Most Common</span>
            </div>
        </div>
        @endif
    </div>

    {{-- ── Tab Toggle ──────────────────────────────────────────── --}}
    <div class="sbn-prog-tabs">
        <button class="sbn-prog-tab" :class="tab === 'library' && 'is-active'" @click="tab = 'library'">
            Library
            <span class="sbn-prog-tab-count">{{ $progressions->count() }}</span>
        </button>
        <button class="sbn-prog-tab" :class="tab === 'occurrences' && 'is-active'" @click="tab = 'occurrences'">
            Occurrences
            <span class="sbn-prog-tab-count" id="tab-total-occ">{{ $stats['total_occurrences'] }}</span>
        </button>
    </div>

    {{-- ══════════════════════════════════════════════════════════
         LIBRARY TAB
    ══════════════════════════════════════════════════════════ --}}
    <div x-show="tab === 'library'">

        {{-- ── Category Filter + Search ──────────────────────────── --}}
        <div class="sbn-filter-bar">
            <div class="sbn-prog-cat-pills">
                <a href="{{ route('admin.progressions.index') }}"
                   class="sbn-prog-cat-pill {{ empty($category) ? 'is-active' : '' }}">All</a>
                @foreach($categories as $cat)
                    <a href="{{ route('admin.progressions.index', ['category' => $cat]) }}"
                       class="sbn-prog-cat-pill {{ $category === $cat ? 'is-active' : '' }}"
                       style="--pill-clr: {{ \App\Models\ChordProgression::CATEGORY_COLORS[$cat] ?? '#6b7280' }}">
                        {{ ucfirst($cat) }}
                    </a>
                @endforeach
            </div>
            <div class="sbn-search-wrap" style="max-width: 260px;">
                <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M8 4a4 4 0 100 8 4 4 0 000-8zM2 8a6 6 0 1110.89 3.476l4.817 4.817a1 1 0 01-1.414 1.414l-4.816-4.816A6 6 0 012 8z" clip-rule="evenodd"/></svg>
                <input type="text" class="sbn-search-input" placeholder="Search…"
                       value="{{ $search }}"
                       onchange="window.location.href='{{ route('admin.progressions.index') }}?q=' + encodeURIComponent(this.value) + '{{ $category ? '&category='.$category : '' }}'">
            </div>
        </div>

        {{-- ── Library Table ─────────────────────────────────────── --}}
        @if($progressions->isEmpty())
            <div class="sbn-empty">
                <h3>No progressions found</h3>
                <p>{{ $category || $search ? 'Try a different filter or search term.' : 'Add your first progression to get started.' }}</p>
            </div>
        @else
            <div class="sbn-table-wrap">
                <table class="sbn-table sbn-prog-table">
                    <thead>
                        <tr>
                            <th class="sbn-th-sort" @click="sortBy('name')">
                                <span class="sbn-th-sort-inner">
                                    Name
                                    <span class="sbn-sort-arrow"
                                          :class="{ 'is-active': sortCol === 'name', 'is-asc': sortCol === 'name' && sortAsc }">▼</span>
                                </span>
                            </th>
                            <th class="sbn-th-sort" @click="sortBy('category')">
                                <span class="sbn-th-sort-inner">
                                    Category
                                    <span class="sbn-sort-arrow"
                                          :class="{ 'is-active': sortCol === 'category', 'is-asc': sortCol === 'category' && sortAsc }">▼</span>
                                </span>
                            </th>
                            <th>Numerals</th>
                            <th class="sbn-th-sort" style="width: 70px; text-align: center;" @click="sortBy('song_count')">
                                <span class="sbn-th-sort-inner">
                                    Songs
                                    <span class="sbn-sort-arrow"
                                          :class="{ 'is-active': sortCol === 'song_count', 'is-asc': sortCol === 'song_count' && sortAsc }">▼</span>
                                </span>
                            </th>
                            <th style="width: 120px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="prog in sorted" :key="prog.id">
                        <tr x-data="{ deleting: false }">
                            <td>
                                <strong x-text="prog.name"></strong>
                                <template x-if="prog.featured">
                                    <span class="sbn-badge sbn-badge-accent" style="margin-left: 6px; font-size: 10px;">Featured</span>
                                </template>
                                <template x-if="prog.desc">
                                    <p class="sbn-prog-desc" x-text="prog.desc"></p>
                                </template>
                            </td>
                            <td>
                                <span class="sbn-prog-cat-badge" :style="'--cat-clr:' + prog.cat_color">
                                    <span x-text="prog.category"></span>
                                </span>
                                <template x-if="prog.tonality !== 'both'">
                                    <span class="sbn-prog-tonality" x-text="prog.tonality"></span>
                                </template>
                                <template x-if="prog.match_mode === 'degree'">
                                    <span class="sbn-prog-tonality" title="Matches on degree only">degree</span>
                                </template>
                            </td>
                            <td>
                                <code class="sbn-prog-numerals" x-text="prog.numerals_display"></code>
                            </td>
                            <td style="text-align: center;">
                                <template x-if="prog.song_count > 0">
                                    <span class="sbn-prog-song-count" x-text="prog.song_count"></span>
                                </template>
                                <template x-if="prog.song_count === 0">
                                    <span class="sbn-prog-song-none">—</span>
                                </template>
                            </td>
                            <td>
                                <div class="sbn-prog-actions">
                                    <a :href="prog.edit_url" class="sbn-btn-sm" title="Edit">
                                        <svg width="14" height="14" viewBox="0 0 20 20" fill="currentColor">
                                            <path d="M13.586 3.586a2 2 0 112.828 2.828l-.793.793-2.828-2.828.793-.793zM11.379 5.793L3 14.172V17h2.828l8.38-8.379-2.83-2.828z"/>
                                        </svg>
                                    </a>
                                    <button class="sbn-btn-sm sbn-btn-sm-danger"
                                            :disabled="deleting"
                                            @click="if(confirm('Delete \'' + prog.name + '\'?\n\nThis also removes all detected occurrences.')) { deleting = true; sbnDelete(prog.id, $el); }"
                                            title="Delete">
                                        <svg width="14" height="14" viewBox="0 0 20 20" fill="currentColor">
                                            <path fill-rule="evenodd" d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zM7 8a1 1 0 012 0v6a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v6a1 1 0 102 0V8a1 1 0 00-1-1z" clip-rule="evenodd"/>
                                        </svg>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{-- ══════════════════════════════════════════════════════════
         OCCURRENCES TAB
    ══════════════════════════════════════════════════════════ --}}
    <div x-show="tab === 'occurrences'">

        {{-- ── Occurrence Filters ────────────────────────────────── --}}
        <div class="sbn-filter-bar" style="margin-bottom: 16px;">
            <form method="get" action="{{ route('admin.progressions.index') }}" style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
                <input type="hidden" name="tab" value="occurrences">
                <select name="prog_id" class="sbn-select" onchange="this.form.submit()">
                    <option value="">All Progressions</option>
                    @foreach($occurrenceFilters['progressions'] as $p)
                        <option value="{{ $p->id }}" {{ $filterProgId == $p->id ? 'selected' : '' }}>{{ $p->name }}</option>
                    @endforeach
                </select>
                <select name="ls_id" class="sbn-select" onchange="this.form.submit()">
                    <option value="">All Songs</option>
                    @foreach($occurrenceFilters['leadsheets'] as $l)
                        <option value="{{ $l->leadsheet_id }}" {{ $filterLsId == $l->leadsheet_id ? 'selected' : '' }}>{{ $l->title }}</option>
                    @endforeach
                </select>
                @if($filterProgId || $filterLsId)
                    <a href="{{ route('admin.progressions.index') }}?tab=occurrences" class="sbn-btn sbn-btn-secondary" style="padding: 7px 14px;">Clear</a>
                @endif
            </form>
        </div>

        {{-- ── Collapsible Song Groups ───────────────────────────── --}}
        @if(empty($occurrenceGroups))
            <div class="sbn-empty">
                <h3>No occurrences found</h3>
                <p>Run <strong>Reprocess</strong> to detect progressions in your leadsheet library.</p>
            </div>
        @else
            <div class="sbn-occ-list">
                @foreach($occurrenceGroups as $group)
                @php $groupLsId = reset($group['occurrences'])->leadsheet_id ?? null; @endphp
                <div class="sbn-occ-song" x-data="{ open: {{ count($occurrenceGroups) <= 5 ? 'true' : 'false' }}, showAnalysis: false, analysisData: null, analysisLoading: false, highlightMatch: null }">
                    <div class="sbn-occ-song-header">
                        <button class="sbn-occ-song-toggle" @click="open = !open">
                            <div class="sbn-occ-song-info">
                                <svg class="sbn-occ-chevron" :class="open && 'is-open'" width="16" height="16" viewBox="0 0 20 20" fill="currentColor">
                                    <path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"/>
                                </svg>
                                <strong>{{ $group['leadsheet_title'] }}</strong>
                                @if($group['song_key'])
                                    <span class="sbn-occ-key">{{ $group['song_key'] }}</span>
                                @endif
                            </div>
                            <span class="sbn-occ-song-count">{{ count($group['occurrences']) }} match{{ count($group['occurrences']) !== 1 ? 'es' : '' }}</span>
                        </button>
                        <div class="sbn-occ-song-actions">
                            @if($groupLsId)
                            <button class="sbn-btn-analysis"
                                    @click.stop="showAnalysis = !showAnalysis; if (showAnalysis) { open = false; } if (showAnalysis && !analysisData) loadAnalysis($el, {{ $groupLsId }})"
                                    :class="showAnalysis && 'is-active'">
                                <svg width="12" height="12" viewBox="0 0 20 20" fill="currentColor"><path d="M9 2a1 1 0 000 2h2l-4.5 4.5a1 1 0 001.414 1.414L12.5 5.414V8a1 1 0 102 0V3a1 1 0 00-1-1H9z"/><path d="M3 7a2 2 0 012-2h3a1 1 0 010 2H5v8h8v-3a1 1 0 112 0v3a2 2 0 01-2 2H5a2 2 0 01-2-2V7z"/></svg>
                                <span x-text="showAnalysis ? 'Hide Analysis' : 'Analysis'"></span>
                            </button>
                            <a href="{{ route('admin.leadsheets.edit', $groupLsId) }}" class="sbn-btn-sm" title="Open in leadsheet editor"
                               @click.stop>
                                Edit
                            </a>
                            @endif
                        </div>
                    </div>
                    <div class="sbn-occ-song-body" x-show="open || showAnalysis" x-collapse>
                        {{-- Occurrences table --}}
                        <div x-show="open">
                        <table class="sbn-table sbn-occ-table">
                            <thead>
                                <tr>
                                    <th>Section</th>
                                    <th>Progression</th>
                                    <th>Numerals</th>
                                    <th>Root</th>
                                    <th>Measures</th>
                                    <th>Confidence</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($group['occurrences'] as $occ)
                                <tr>
                                    <td><code>{{ $occ->section_id }}</code></td>
                                    <td>
                                        <span class="sbn-prog-cat-badge" style="--cat-clr: {{ \App\Models\ChordProgression::CATEGORY_COLORS[$occ->category] ?? '#6b7280' }}">
                                            {{ $occ->category }}
                                        </span>
                                        {{ $occ->prog_name }}
                                    </td>
                                    <td><code class="sbn-prog-numerals">{{ str_replace(',', ' – ', $occ->numerals) }}</code></td>
                                    <td>{{ $occ->detected_root }}</td>
                                    <td>
                                        {{ $occ->start_measure + 1 }}@if($occ->length_measures > 1)–{{ $occ->start_measure + $occ->length_measures }}@endif
                                    </td>
                                    <td>
                                        <div class="sbn-occ-confidence">
                                            <div class="sbn-occ-confidence-bar">
                                                <div class="sbn-occ-confidence-fill" style="width: {{ round($occ->confidence * 100) }}%"></div>
                                            </div>
                                            <span>{{ round($occ->confidence * 100) }}%</span>
                                        </div>
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                        </div>

                        {{-- Inline Analysis Panel --}}
                        <template x-if="showAnalysis">
                            <div class="sbn-analysis-panel sbn-analysis-inline">
                                <div class="sbn-analysis-loading" x-show="analysisLoading">
                                    🔍 Analysing harmonic structure…
                                </div>

                                <template x-if="analysisData && !analysisLoading">
                                    <div>
                                        <div class="sbn-analysis-key">
                                            Song key: <strong x-text="analysisData.song_key"></strong>
                                        </div>

                                        <template x-for="(section, asi) in analysisData.sections" :key="asi">
                                            <div class="sbn-analysis-section">
                                                <div class="sbn-analysis-section-header">
                                                    <div class="sbn-analysis-section-id" x-text="section.section_id"></div>
                                                    <span class="sbn-analysis-section-name" x-text="section.section_name"></span>
                                                    <span class="sbn-analysis-section-key" x-text="'Key: ' + section.key"></span>
                                                    <span class="sbn-analysis-section-key"
                                                          x-text="Object.keys(section.measure_numerals).length + ' bars'"></span>
                                                </div>

                                                <div class="sbn-analysis-grid">
                                                    <template x-for="(mChords, mi) in section.measure_numerals" :key="mi">
                                                        <div class="sbn-analysis-measure"
                                                             :class="{ 'is-highlighted': sbnIsMeasureHighlighted(highlightMatch, asi, parseInt(mi)) }">
                                                            <div class="sbn-analysis-measure-num"
                                                                 x-text="parseInt(mi) + 1"></div>
                                                            <div class="sbn-analysis-chord-row">
                                                                <template x-for="(slot, ci) in mChords" :key="ci">
                                                                    <div class="sbn-analysis-chord-slot">
                                                                        <div class="sbn-analysis-chord-name"
                                                                             x-html="sbnStyledChord(slot.chord)"></div>
                                                                        <div class="sbn-analysis-numeral"
                                                                             :class="{ 'is-unknown': slot.numeral === '?' || slot.numeral.startsWith('chr') }"
                                                                             x-text="sbnFormatNumeral(slot.numeral)"></div>
                                                                    </div>
                                                                </template>
                                                            </div>
                                                        </div>
                                                    </template>
                                                </div>

                                                <div class="sbn-analysis-matches" x-show="section.matches && section.matches.length">
                                                    <template x-for="(match, mti) in section.matches" :key="mti">
                                                        <div class="sbn-analysis-match"
                                                             @mouseenter="highlightMatch = sbnBuildHighlight(analysisData, asi, match)"
                                                             @mouseleave="highlightMatch = null">
                                                            <span class="sbn-analysis-match-cat"
                                                                  :class="'cat-' + match.category"
                                                                  x-text="match.category"></span>
                                                            <span class="sbn-analysis-match-name" x-text="match.name"></span>
                                                            <span class="sbn-analysis-match-measures"
                                                                  x-text="'m' + (match.start_measure + 1) + (match.end_measure > match.start_measure ? '–' + (match.end_measure + 1) : '')"></span>
                                                            <span class="sbn-analysis-match-root"
                                                                  x-text="'root: ' + match.detected_root"></span>
                                                            <span class="sbn-analysis-match-confidence"
                                                                  x-text="Math.round(match.confidence * 100) + '%'"></span>
                                                        </div>
                                                    </template>
                                                </div>

                                                <div class="sbn-analysis-matches"
                                                     x-show="!section.matches || !section.matches.length"
                                                     style="color:var(--clr-text-dim); font-size:11px; padding:6px 0;">
                                                    No known progressions detected in this section.
                                                </div>
                                            </div>
                                        </template>
                                    </div>
                                </template>
                            </div>
                        </template>
                    </div>
                </div>
                @endforeach
            </div>
        @endif
    </div>

</div>
@endsection

@push('scripts')
<script src="{{ asset('js/sbn-chord-name.js') }}"></script>
<script>
/* ── Chord Styling Helper ────────────────────────────────── */
function sbnStyledChord(name) {
    if (typeof sbnFormatChord === 'function') return sbnFormatChord(name);
    // Fallback: escape and return plain
    const d = document.createElement('span');
    d.textContent = name;
    return d.innerHTML;
}

/* ── Analysis Helpers (shared by all occurrence groups) ──── */

async function loadAnalysis(el, leadsheetId) {
    const scope = Alpine.$data(el.closest('[x-data]'));
    if (scope.analysisData) return;
    scope.analysisLoading = true;
    try {
        const resp = await fetch('/api/admin/leadsheets/' + leadsheetId + '/analyse-progressions');
        const data = await resp.json();
        if (data.success) {
            scope.analysisData = data.data;
        }
    } catch (e) {
        console.error('Analysis failed:', e);
        sbnToast('Failed to load analysis', 'error');
    }
    scope.analysisLoading = false;
}

function sbnFormatNumeral(numeral) {
    if (!numeral) return '?';
    return numeral
        .replace('maj7', '\u25B37')
        .replace('m7b5', '\u00F87')
        .replace(/o7$/, '\u00B07');
}

function sbnIsMeasureHighlighted(highlightMatch, sectionIdx, measureIdx) {
    if (!highlightMatch) return false;
    for (const range of highlightMatch.ranges) {
        if (range.section === sectionIdx &&
            measureIdx >= range.start && measureIdx <= range.end) {
            return true;
        }
    }
    return false;
}

function sbnBuildHighlight(analysisData, sectionIdx, match) {
    const ranges = [
        { section: sectionIdx, start: match.start_measure, end: match.end_measure }
    ];
    if (analysisData) {
        const section = analysisData.sections[sectionIdx];
        if (section && section.resolutions) {
            for (const res of section.resolutions) {
                if (res.from_progression === match.name) {
                    const targetIdx = analysisData.sections.findIndex(
                        s => s.section_id === res.target_section_id
                    );
                    if (targetIdx >= 0) {
                        ranges.push({ section: targetIdx, start: res.start_measure, end: res.end_measure });
                    }
                }
            }
        }
    }
    return { ranges };
}
</script>

<script>
function progressionsPage() {
    const params = new URLSearchParams(window.location.search);
    const initialTab = params.get('tab') || 'library';

    // Serialize progression data for client-side sorting
    const rows = @json($progressionsJson);

    return {
        tab: initialTab,
        rows: rows,
        sortCol: 'song_count',
        sortAsc: false,

        get sorted() {
            const col = this.sortCol;
            const asc = this.sortAsc;
            return [...this.rows].sort((a, b) => {
                let va = a[col];
                let vb = b[col];
                if (typeof va === 'string') {
                    va = va.toLowerCase();
                    vb = vb.toLowerCase();
                }
                if (va < vb) return asc ? -1 : 1;
                if (va > vb) return asc ? 1 : -1;
                // Secondary sort: name ascending
                if (col !== 'name') {
                    const na = a.name.toLowerCase();
                    const nb = b.name.toLowerCase();
                    if (na < nb) return -1;
                    if (na > nb) return 1;
                }
                return 0;
            });
        },

        sortBy(col) {
            if (this.sortCol === col) {
                this.sortAsc = !this.sortAsc;
            } else {
                this.sortCol = col;
                // Default direction: desc for song_count, asc for text
                this.sortAsc = (col !== 'song_count');
            }
        },
    };
}

/* ── Delete progression (AJAX) ───────────────────────────── */
async function sbnDelete(id, btn) {
    const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
    const row = btn.closest('tr');

    try {
        const resp = await fetch(`/admin/progressions/${id}`, {
            method: 'DELETE',
            headers: {
                'X-CSRF-TOKEN': csrfToken,
                'Accept': 'application/json',
            },
        });
        const data = await resp.json();
        if (data.success) {
            row.style.transition = 'opacity 0.3s, transform 0.3s';
            row.style.opacity = '0';
            row.style.transform = 'translateX(-20px)';
            setTimeout(() => row.remove(), 300);
            sbnToast(data.message || 'Deleted.', 'success');
        } else {
            sbnToast('Error: ' + (data.message || 'Could not delete.'), 'error');
        }
    } catch (e) {
        sbnToast('Network error.', 'error');
    }
}

/* ── Reprocess (AJAX) ────────────────────────────────────── */
async function sbnReprocess() {
    const btn = document.getElementById('sbn-reprocess-btn');
    const csrfToken = document.querySelector('meta[name="csrf-token"]').content;

    btn.disabled = true;
    btn.innerHTML = '<svg class="sbn-spin" width="14" height="14" viewBox="0 0 20 20" fill="currentColor" style="flex-shrink:0"><path fill-rule="evenodd" d="M4 2a1 1 0 011 1v2.101a7.002 7.002 0 0111.601 2.566 1 1 0 11-1.885.666A5.002 5.002 0 005.999 7H9a1 1 0 010 2H4a1 1 0 01-1-1V3a1 1 0 011-1zm.008 9.057a1 1 0 011.276.61A5.002 5.002 0 0014.001 13H11a1 1 0 110-2h5a1 1 0 011 1v5a1 1 0 11-2 0v-2.101a7.002 7.002 0 01-11.601-2.566 1 1 0 01.61-1.276z" clip-rule="evenodd"/></svg> Processing…';

    try {
        const resp = await fetch('/api/admin/progressions/reprocess', {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': csrfToken,
                'Accept': 'application/json',
            },
        });
        const data = await resp.json();
        if (data.success) {
            // Update stat cards + tab badge in-place
            const occEl    = document.getElementById('stat-total-occ');
            const tabOccEl = document.getElementById('tab-total-occ');
            if (occEl)    occEl.textContent    = data.total_occ;
            if (tabOccEl) tabOccEl.textContent = data.total_occ;
            sbnToast(`Processed ${data.processed} leadsheets — ${data.total_occ} occurrences found.`, 'success');
        } else {
            sbnToast('Error during reprocessing.', 'error');
        }
    } catch (e) {
        sbnToast('Network error.', 'error');
    }

    btn.disabled = false;
    btn.innerHTML = '<svg width="14" height="14" viewBox="0 0 20 20" fill="currentColor" style="flex-shrink:0"><path fill-rule="evenodd" d="M4 2a1 1 0 011 1v2.101a7.002 7.002 0 0111.601 2.566 1 1 0 11-1.885.666A5.002 5.002 0 005.999 7H9a1 1 0 010 2H4a1 1 0 01-1-1V3a1 1 0 011-1zm.008 9.057a1 1 0 011.276.61A5.002 5.002 0 0014.001 13H11a1 1 0 110-2h5a1 1 0 011 1v5a1 1 0 11-2 0v-2.101a7.002 7.002 0 01-11.601-2.566 1 1 0 01.61-1.276z" clip-rule="evenodd"/></svg> Reprocess';
}

/* ── Toast ────────────────────────────────────────────────── */
function sbnToast(msg, type) {
    const el = document.createElement('div');
    el.className = 'sbn-toast sbn-toast-' + (type || 'info');
    el.textContent = msg;
    document.body.appendChild(el);
    setTimeout(() => { el.style.opacity = '0'; el.style.transform = 'translateY(16px)'; }, 3000);
    setTimeout(() => el.remove(), 3500);
}
</script>
@endpush
