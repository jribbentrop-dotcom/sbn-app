@extends('layouts.admin')

@section('title', 'Chord Diagrams')

@section('actions')
    <a href="{{ route('admin.chords.create') }}" class="sbn-btn sbn-btn-primary">+ New Chord</a>
@endsection

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/chords.css') }}">
    <style>
        .sbn-diagram-added-notes {
            display: flex;
            flex-wrap: wrap;
            gap: 4px;
            padding: 4px 8px 0;
        }
        .sbn-added-note-tag {
            font-size: 10px;
            font-family: var(--font-mono);
            background: color-mix(in srgb, var(--clr-accent) 12%, transparent);
            color: var(--clr-accent);
            border: 1px solid color-mix(in srgb, var(--clr-accent) 25%, transparent);
            border-radius: 3px;
            padding: 1px 5px;
            white-space: nowrap;
        }
        /* Enriched voicings tab */
        .sbn-enriched-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .sbn-enriched-group {
            display: flex;
            gap: 16px;
            align-items: flex-start;
            background: var(--clr-surface);
            border: 1px solid var(--clr-border);
            border-radius: 8px;
            padding: 12px;
        }
        .sbn-enriched-diagram-col {
            flex-shrink: 0;
        }
        .sbn-enriched-occurrences-col {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 6px;
            padding-top: 4px;
        }
        .sbn-enriched-occurrence {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 6px 10px;
            background: var(--clr-bg);
            border: 1px solid var(--clr-border);
            border-radius: 6px;
        }
        .sbn-enriched-occ-left {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        .sbn-enriched-chord-name {
            font-family: var(--font-mono);
            font-size: 13px;
            font-weight: 600;
            color: var(--clr-text);
        }
        .sbn-enriched-song-link {
            display: flex;
            align-items: center;
            gap: 4px;
            font-size: 12px;
            color: var(--clr-text-dim);
            text-decoration: none;
            white-space: nowrap;
        }
        .sbn-enriched-song-link:hover {
            color: var(--clr-accent);
        }
    </style>
@endpush

@section('content')
<div x-data="chordsPage()" x-init="init()">

    {{-- ============================================================
         TABS
         ============================================================ --}}
    <div class="sbn-tabs">
        <button class="sbn-tab" :class="{ 'sbn-tab-active': tab === 'library' }"
                @click="tab = 'library'">
            Library
            <span class="sbn-tab-count">{{ $stats['total'] }}</span>
        </button>
        <button class="sbn-tab" :class="{ 'sbn-tab-active': tab === 'popular' }"
                @click="tab = 'popular'">
            Most Popular
            @if($popularVoicings->isNotEmpty())
                <span class="sbn-tab-count">{{ $popularVoicings->count() }}</span>
            @endif
        </button>
        <button class="sbn-tab" :class="{ 'sbn-tab-active': tab === 'unmatched' }"
                @click="tab = 'unmatched'">
            Unmatched Voicings
            @if($pendingCount > 0)
                <span class="sbn-tab-count sbn-tab-count-warn">{{ $pendingCount }}</span>
            @else
                <span class="sbn-tab-count">0</span>
            @endif
        </button>
        <button class="sbn-tab" :class="{ 'sbn-tab-active': tab === 'enriched' }"
                @click="tab = 'enriched'">
            Enriched Voicings
            @if($enrichedCount > 0)
                <span class="sbn-tab-count">{{ $enrichedCount }}</span>
            @endif
        </button>
    </div>

    {{-- ============================================================
         TAB: LIBRARY
         ============================================================ --}}
    <div x-show="tab === 'library'" x-cloak>

        {{-- Stats row --}}
        <div class="sbn-chord-stats">
            <div class="sbn-chord-stat">
                <span class="sbn-chord-stat-num">{{ $stats['total'] }}</span>
                <span class="sbn-chord-stat-label">Total Shapes</span>
            </div>
            <div class="sbn-chord-stat">
                <span class="sbn-chord-stat-num">{{ $stats['categories'] }}</span>
                <span class="sbn-chord-stat-label">Categories</span>
            </div>
            <div class="sbn-chord-stat">
                <span class="sbn-chord-stat-num">{{ $stats['qualities'] }}</span>
                <span class="sbn-chord-stat-label">Qualities</span>
            </div>
            <div class="sbn-chord-stat">
                <span class="sbn-chord-stat-num">{{ $stats['archetypes'] }}</span>
                <span class="sbn-chord-stat-label">Archetypes</span>
            </div>
        </div>

        {{-- Filters --}}
        <div class="sbn-chord-filters">
            <div class="sbn-search-wrap">
                <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M8 4a4 4 0 100 8 4 4 0 000-8zM2 8a6 6 0 1110.89 3.476l4.817 4.817a1 1 0 01-1.414 1.414l-4.816-4.816A6 6 0 012 8z" clip-rule="evenodd"/></svg>
                <input type="text" class="sbn-search-input" placeholder="Search chords…"
                       x-model="search" @input.debounce.200ms="applyFilters()">
            </div>
            <select class="sbn-select" x-model="filterCategory" @change="applyFilters()">
                <option value="">All Voicings</option>
                @foreach($voicingCategories as $key => $label)
                    <option value="{{ $key }}">{{ $label }}</option>
                @endforeach
            </select>
            <select class="sbn-select" x-model="filterQuality" @change="applyFilters()">
                <option value="">All Qualities</option>
                @foreach($chordQualities as $key => $label)
                    <option value="{{ $key }}">{{ $label }}</option>
                @endforeach
            </select>
            <select class="sbn-select" x-model="filterRootString" @change="applyFilters()">
                <option value="">All Root Strings</option>
                @foreach($rootStrings as $key => $label)
                    <option value="{{ $key }}">{{ $label }}</option>
                @endforeach
            </select>
            <div style="flex:1;"></div>
            <button class="sbn-btn sbn-btn-secondary" @click="recomputeIntervals()" :disabled="recomputing">
                <span x-text="recomputing ? '⏳ Recomputing…' : '↻ Recompute Intervals'"></span>
            </button>
        </div>

        {{-- Visible count --}}
        <div class="sbn-chord-count" x-show="visibleCount !== chords.length">
            Showing <strong x-text="visibleCount"></strong> of <strong>{{ count($chords) }}</strong> shapes
        </div>

        {{-- Card grid grouped by category → root string --}}
        <template x-for="catGroup in organised" :key="catGroup.category">
            <div class="sbn-voicing-section" x-show="catGroup.visible"
                 x-data="{ collapsed: true }">
                <h2 class="sbn-voicing-header" @click="collapsed = !collapsed">
                    <span x-text="catGroup.categoryLabel"></span>
                    <span class="sbn-voicing-count" x-text="catGroup.count + ' shapes'"></span>
                    <svg class="sbn-collapse-icon" :class="{ 'sbn-collapsed': collapsed }" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
                </h2>

                <div x-show="!collapsed" x-transition.opacity.duration.200ms>
                    <template x-for="rsGroup in catGroup.rootStrings" :key="rsGroup.rootString">
                        <div class="sbn-root-string-group" x-show="rsGroup.visible">
                            <h3 class="sbn-root-string-header" x-text="rsGroup.rootStringLabel"></h3>
                            <div class="sbn-shapes-row">
                                <template x-for="chord in rsGroup.chords" :key="chord.id">
                                    <div class="sbn-diagram-card" x-show="chord._visible">
                                        <div class="sbn-diagram-card-header">
                                            <div class="sbn-shape-title-row">
                                                <span class="sbn-shape-quality" x-text="chord.quality_short"></span><span class="sbn-shape-ext" x-show="chord.extensions" x-text="chord.extensions"></span><span class="sbn-shape-bass" x-show="chord.bass_note" x-text="'/' + chord.bass_note"></span>
                                            </div>
                                            <span class="sbn-shape-inv" x-show="chord.inversion !== 'root'" x-text="chord.inversion_label"></span>
                                        </div>

                                        <div class="sbn-diagram-preview">
                                            <div class="sbn-chord-fretboard"
                                                 x-init="$nextTick(() => sbnRenderMini($el, JSON.stringify(chord.diagram_data), chord.start_fret, chord.interval_labels))">
                                            </div>
                                        </div>

                                        <div class="sbn-diagram-actions">
                                            <a :href="'/admin/chords/' + chord.id + '/edit'" class="sbn-btn-sm">Edit</a>
                                            <button class="sbn-btn-sm" @click="duplicateChord(chord)">Dup</button>
                                            <button class="sbn-btn-sm sbn-btn-sm-danger" @click="deleteChord(chord)">Del</button>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </template>
                </div>
            </div>
        </template>

        {{-- Empty states --}}
        <div class="sbn-empty" x-show="chords.length === 0">
            <h3>No chord diagrams yet</h3>
            <p>Import your chord library to get started.</p>
        </div>
        <div class="sbn-empty" x-show="chords.length > 0 && visibleCount === 0">
            <h3>No matching shapes</h3>
            <p>Try adjusting your filters.</p>
        </div>

    </div>

    {{-- ============================================================
         TAB: MOST POPULAR
         ============================================================ --}}
    <div x-show="tab === 'popular'" x-cloak>
        @if($popularVoicings->isEmpty())
            <div class="sbn-empty">
                <h3>No popularity data yet</h3>
                <p>Process your leadsheets to see which voicings are used most.</p>
            </div>
        @else
            <p style="font-size:13px;color:var(--clr-text-dim);margin-bottom:16px;">
                The most-used chord diagrams across your leadsheets, ranked by how many songs each voicing appears in.
            </p>
            <div class="sbn-shapes-row">
                @foreach($popularVoicings as $v)
                @php
                    $dd = json_decode($v->diagram_data, true) ?: ['positions'=>[],'barres'=>[],'muted'=>[],'open'=>[]];
                @endphp
                <div class="sbn-diagram-card">
                    <div class="sbn-diagram-card-header">
                        <div class="sbn-shape-title-row">
                            <span class="sbn-shape-quality">{{ $v->name }}</span>
                        </div>
                        <span class="sbn-shape-inv">{{ $v->popularity }} song{{ $v->popularity != 1 ? 's' : '' }}</span>
                    </div>
                    <div class="sbn-diagram-preview">
                        <div class="sbn-chord-fretboard"
                             data-diagram="{{ json_encode($dd) }}"
                             data-start-fret="{{ $v->start_fret }}"
                             data-intervals="{{ $v->interval_labels }}">
                        </div>
                    </div>
                    @if(!empty($v->added_notes))
                    <div class="sbn-diagram-added-notes">
                        @foreach(array_filter(array_map('trim', explode(',', $v->added_notes))) as $note)
                            <span class="sbn-added-note-tag">{{ $note }}</span>
                        @endforeach
                    </div>
                    @endif
                    <div class="sbn-diagram-actions">
                        <a href="{{ route('admin.chords.edit', $v->id) }}" class="sbn-btn-sm">Edit</a>
                        <span class="sbn-btn-sm" style="color:var(--clr-text-muted);border-color:transparent;cursor:default;">{{ $v->category_label }}</span>
                    </div>
                </div>
                @endforeach
            </div>
        @endif
    </div>

    {{-- ============================================================
         TAB: UNMATCHED VOICINGS
         ============================================================ --}}
    <div x-show="tab === 'unmatched'" x-cloak>

        <div class="sbn-drafts-header">
            <p class="sbn-drafts-intro-text">
                These voicings from your leadsheets don't match any chord diagram in your library.
                <strong>Add them</strong> to the library or <strong>dismiss</strong> them.
            </p>
            <div class="sbn-drafts-header-actions">
                @if($pendingCount > 0)
                <button class="sbn-btn sbn-btn-danger sbn-btn-xs" @click="clearAllDrafts()" x-show="!clearing">
                    Clear All
                </button>
                <span class="sbn-btn sbn-btn-danger sbn-btn-xs" x-show="clearing" style="opacity:.6">Clearing…</span>
                @endif
                <button class="sbn-btn sbn-btn-secondary sbn-btn-xs" @click="reprocessAll()" :disabled="reprocessing">
                    <span x-text="reprocessing ? 'Processing…' : '↻ Reprocess All'"></span>
                </button>
            </div>
        </div>

        @if($groupedDrafts->isEmpty())
            <div class="sbn-empty">
                <h3>All voicings matched!</h3>
                <p>Every voicing in your leadsheets has been matched to a chord diagram in your library.</p>
            </div>
        @else
            @foreach($groupedDrafts as $leadsheetId => $group)
            <div class="sbn-draft-group" data-leadsheet="{{ $leadsheetId }}">
                <h4 class="sbn-draft-group-title">
                    {{ $group['title'] }}
                    <span class="sbn-draft-count">{{ $group['drafts']->count() }} unmatched</span>
                </h4>

                <div class="sbn-draft-cards">
                    @foreach($group['drafts'] as $draft)
                    <div class="sbn-draft-card"
                         x-ref="draft{{ $draft->id }}"
                         x-show="!dismissed.includes({{ $draft->id }})"
                         x-transition:leave="transition ease-in duration-200"
                         x-transition:leave-start="opacity-100 scale-100"
                         x-transition:leave-end="opacity-0 scale-95">

                        <div class="sbn-draft-card-header">
                            <span class="sbn-draft-chord-name">{{ $draft->chord_name }}</span>
                        </div>

                        <div class="sbn-draft-diagram"
                             data-diagram="{{ json_encode($draft->toDiagramData()) }}"
                             data-start-fret="{{ max(1, $draft->position ?? 1) }}">
                        </div>

                        <div class="sbn-draft-actions">
                            <button class="sbn-btn sbn-btn-primary sbn-btn-xs"
                                    @click="promoteDraft({{ $draft->id }})"
                                    :disabled="promoting === {{ $draft->id }}">
                                <span x-show="promoting !== {{ $draft->id }}">Add</span>
                                <span x-show="promoting === {{ $draft->id }}">…</span>
                            </button>
                            <button class="sbn-btn sbn-btn-secondary sbn-btn-xs"
                                    @click="dismissDraft({{ $draft->id }})"
                                    :disabled="dismissing === {{ $draft->id }}">
                                <span x-show="dismissing !== {{ $draft->id }}">Dismiss</span>
                                <span x-show="dismissing === {{ $draft->id }}">…</span>
                            </button>
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>
            @endforeach
        @endif
    </div>

    {{-- ============================================================
         TAB: ENRICHED VOICINGS
         Diagrams that were matched but had extra/doubled notes detected
         ============================================================ --}}
    <template x-if="tab === 'enriched'">
        <div>
        @if($enrichedGroups->isEmpty())
            <div class="sbn-empty">
                <h3>No enriched voicings found</h3>
                <p>When a voicing in a leadsheet matches a diagram but contains extra or doubled notes, it will appear here.</p>
            </div>
        @else
            <p style="font-size:13px;color:var(--clr-text-dim);margin-bottom:16px;">
                These chord diagrams were matched in your leadsheets with additional or doubled notes beyond the template shape.
                Each group shows which songs contain the enriched version.
            </p>
            <div class="sbn-enriched-list">
                @foreach($enrichedGroups as $group)
                <div class="sbn-enriched-group">
                    <div class="sbn-enriched-diagram-col">
                        <div class="sbn-diagram-card" style="width:140px;flex-shrink:0;">
                            <div class="sbn-diagram-card-header">
                                <div class="sbn-shape-title-row">
                                    <span class="sbn-shape-quality" style="font-size:11px;">{{ $group['diagram_name'] }}</span>
                                </div>
                                <span class="sbn-shape-inv">{{ $group['count'] }} {{ $group['count'] === 1 ? 'song' : 'songs' }}</span>
                            </div>
                            <div class="sbn-diagram-preview">
                                <div class="sbn-chord-fretboard sbn-enriched-fretboard"
                                     data-diagram="{{ json_encode($group['diagram_data']) }}"
                                     data-start-fret="{{ $group['start_fret'] }}"
                                     data-intervals="{{ $group['interval_labels'] }}">
                                </div>
                            </div>
                            <div class="sbn-diagram-actions">
                                <a href="{{ route('admin.chords.edit', $group['diagram_id']) }}" class="sbn-btn-sm">Edit</a>
                            </div>
                        </div>
                    </div>

                    <div class="sbn-enriched-occurrences-col">
                        @foreach($group['occurrences'] as $occ)
                        <div class="sbn-enriched-occurrence">
                            <div class="sbn-enriched-occ-left">
                                <span class="sbn-enriched-chord-name">{{ $occ['chord_name'] }}</span>
                                <div class="sbn-diagram-added-notes" style="padding:0;margin-top:4px;">
                                    @foreach(array_filter(array_map('trim', explode(',', $occ['added_notes']))) as $note)
                                        <span class="sbn-added-note-tag">+{{ $note }}</span>
                                    @endforeach
                                </div>
                            </div>
                            <a href="{{ route('admin.leadsheets.edit', $occ['leadsheet_id']) }}"
                               class="sbn-enriched-song-link" title="Open in leadsheet editor">
                                {{ $occ['leadsheet_title'] }}
                                <svg width="11" height="11" viewBox="0 0 20 20" fill="currentColor" style="opacity:.5;flex-shrink:0">
                                    <path d="M11 3a1 1 0 100 2h2.586l-6.293 6.293a1 1 0 101.414 1.414L15 6.414V9a1 1 0 102 0V4a1 1 0 00-1-1h-5z"/>
                                    <path d="M5 5a2 2 0 00-2 2v8a2 2 0 002 2h8a2 2 0 002-2v-3a1 1 0 10-2 0v3H5V7h3a1 1 0 000-2H5z"/>
                                </svg>
                            </a>
                        </div>
                        @endforeach
                    </div>
                </div>
                @endforeach
            </div>
        @endif
        </div>
    </template>

</div>
@endsection

@push('scripts')
<script>
/**
 * Mini Fretboard Renderer + Toast
 * Inline to avoid Herd cache issues with external JS files.
 */
const SBN_FRETS_TO_SHOW = 5;

function sbnRenderMini(el, diagramJson, sfParam, intParam) {
    if (!el || el.dataset.sbnRendered === '1') return;
    el.dataset.sbnRendered = '1';

    let rawData = diagramJson || el.dataset.diagram || el.getAttribute('data-diagram');
    const startFret = parseInt(sfParam || el.dataset.startFret || el.getAttribute('data-start-fret')) || 1;
    const intervalsStr = (intParam != null ? intParam : (el.dataset.intervals || el.getAttribute('data-intervals'))) || '';
    const intervals = intervalsStr.split(',').filter(Boolean);

    let localData;
    if (typeof rawData === 'string') {
        try { localData = JSON.parse(rawData); } catch (e) {
            localData = { positions: [], barres: [], muted: [], open: [] };
        }
    } else {
        localData = rawData || { positions: [], barres: [], muted: [], open: [] };
    }

    const positions = localData.positions || [];
    const barres = localData.barres || [];
    const muted = localData.muted || [];
    const open = localData.open || [];

    let html = '<div class="sbn-fb-mini">';

    if (startFret > 1) {
        html += '<span class="sbn-fb-fret-num">' + startFret + 'fr</span>';
    }

    html += '<div class="sbn-fb-indicators">';
    for (let s = 1; s <= 6; s++) {
        if (muted.includes(s)) {
            html += '<span class="sbn-fb-ind sbn-fb-ind-muted">×</span>';
        } else if (open.includes(s)) {
            html += '<span class="sbn-fb-ind sbn-fb-ind-open">○</span>';
        } else {
            html += '<span class="sbn-fb-ind"></span>';
        }
    }
    html += '</div>';

    if (startFret === 1) {
        html += '<div class="sbn-fb-nut"></div>';
    }

    html += '<div class="sbn-fb-frets">';
    for (let f = 0; f < SBN_FRETS_TO_SHOW; f++) {
        const actualFret = startFret + f;
        html += '<div class="sbn-fb-row" data-fret="' + actualFret + '">';
        for (let s = 1; s <= 6; s++) {
            html += '<div class="sbn-fb-cell" data-string="' + s + '" data-fret="' + actualFret + '"></div>';
        }
        html += '</div>';
    }
    html += '</div>';

    if (intervals.length > 0) {
        html += '<div class="sbn-fb-intervals">';
        for (let s = 1; s <= 6; s++) {
            html += '<span class="sbn-fb-int">' + (intervals[s - 1] || '') + '</span>';
        }
        html += '</div>';
    }

    html += '</div>';
    el.innerHTML = html;

    positions.forEach(pos => {
        const fretIndex = pos.fret - startFret;
        if (fretIndex >= 0 && fretIndex < SBN_FRETS_TO_SHOW) {
            const cell = el.querySelector(
                '.sbn-fb-cell[data-string="' + pos.string + '"][data-fret="' + pos.fret + '"]'
            );
            if (cell) {
                const dot = document.createElement('div');
                dot.className = 'sbn-fb-dot';
                dot.textContent = pos.finger || '';
                cell.appendChild(dot);
            }
        }
    });

    if (barres.length) {
        requestAnimationFrame(() => {
            barres.forEach(barre => {
                const fretIndex = barre.fret - startFret;
                if (fretIndex >= 0 && fretIndex < SBN_FRETS_TO_SHOW) {
                    const row = el.querySelector('.sbn-fb-row[data-fret="' + barre.fret + '"]');
                    if (!row) return;
                    const fromCell = row.querySelector('.sbn-fb-cell[data-string="' + barre.fromString + '"]');
                    const toCell = row.querySelector('.sbn-fb-cell[data-string="' + barre.toString + '"]');
                    if (fromCell && toCell) {
                        const fromLeft = fromCell.offsetLeft + fromCell.offsetWidth / 2;
                        const toLeft = toCell.offsetLeft + toCell.offsetWidth / 2;
                        const barreEl = document.createElement('div');
                        barreEl.className = 'sbn-fb-barre';
                        barreEl.textContent = barre.finger || '';
                        barreEl.style.left = Math.min(fromLeft, toLeft) + 'px';
                        barreEl.style.width = Math.max(Math.abs(toLeft - fromLeft), 20) + 'px';
                        barreEl.style.top = (row.offsetHeight / 2) + 'px';
                        row.appendChild(barreEl);
                    }
                }
            });
        });
    }
}

/**
 * SVG-based draft fretboard renderer (compact, no interval labels)
 */
function sbnRenderDraftMini(container) {
    const diagramData = JSON.parse(container.dataset.diagram || '{}');
    const startFret = parseInt(container.dataset.startFret) || 1;

    const positions = diagramData.positions || [];
    const barres    = diagramData.barres || [];
    const muted     = (diagramData.muted || []).map(Number);
    const open      = (diagramData.open || []).map(Number);

    const FRETS = 4, STRINGS = 6;
    const mT = 18, mB = 4, mL = 16, mR = 8;
    const fH = 18, sS = 13;
    const showNut = startFret === 1;
    const fbW = sS * (STRINGS - 1), fbH = fH * FRETS;
    const W = mL + fbW + mR, H = mT + fbH + mB;

    const ns = 'http://www.w3.org/2000/svg';
    const svg = document.createElementNS(ns, 'svg');
    svg.setAttribute('viewBox', `0 0 ${W} ${H}`);
    svg.setAttribute('width', W);
    svg.setAttribute('height', H);
    svg.style.display = 'block';

    const sw = [1.4, 1.1, 0.9, 0.75, 0.6, 0.5];

    if (showNut) {
        const nut = document.createElementNS(ns, 'rect');
        nut.setAttribute('x', mL - 1); nut.setAttribute('y', mT - 2.5);
        nut.setAttribute('width', fbW + 2); nut.setAttribute('height', 2.5);
        nut.setAttribute('fill', '#2c3e50'); nut.setAttribute('rx', 1);
        svg.appendChild(nut);
    } else {
        const lbl = document.createElementNS(ns, 'text');
        lbl.setAttribute('x', mL - 4); lbl.setAttribute('y', mT + fH / 2 + 3);
        lbl.setAttribute('text-anchor', 'end'); lbl.setAttribute('font-size', '8');
        lbl.setAttribute('fill', '#8896a4'); lbl.setAttribute('font-family', 'DM Sans, sans-serif');
        lbl.textContent = startFret + 'fr'; svg.appendChild(lbl);
    }

    for (let f = 0; f <= FRETS; f++) {
        const y = mT + f * fH;
        const l = document.createElementNS(ns, 'line');
        l.setAttribute('x1', mL); l.setAttribute('y1', y);
        l.setAttribute('x2', mL + fbW); l.setAttribute('y2', y);
        l.setAttribute('stroke', '#cbd5e0');
        l.setAttribute('stroke-width', f === 0 && !showNut ? 1.2 : 0.7);
        svg.appendChild(l);
    }

    for (let s = 0; s < STRINGS; s++) {
        const x = mL + s * sS;
        const l = document.createElementNS(ns, 'line');
        l.setAttribute('x1', x); l.setAttribute('y1', mT);
        l.setAttribute('x2', x); l.setAttribute('y2', mT + fbH);
        l.setAttribute('stroke', '#94a3b8'); l.setAttribute('stroke-width', sw[s]);
        svg.appendChild(l);
    }

    for (let s = 1; s <= STRINGS; s++) {
        const x = mL + (s - 1) * sS, y = mT - 8;
        if (muted.includes(s)) {
            const t = document.createElementNS(ns, 'text');
            t.setAttribute('x', x); t.setAttribute('y', y);
            t.setAttribute('text-anchor', 'middle'); t.setAttribute('font-size', '8');
            t.setAttribute('fill', '#a0aec0'); t.setAttribute('font-family', 'DM Sans');
            t.textContent = '×'; svg.appendChild(t);
        } else if (open.includes(s)) {
            const c = document.createElementNS(ns, 'circle');
            c.setAttribute('cx', x); c.setAttribute('cy', y - 2);
            c.setAttribute('r', 2.8); c.setAttribute('fill', 'none');
            c.setAttribute('stroke', '#718096'); c.setAttribute('stroke-width', 0.8);
            svg.appendChild(c);
        }
    }

    barres.forEach(b => {
        const fX = mL + (b.from - 1) * sS, tX = mL + (b.to - 1) * sS;
        const y = mT + (b.fret - startFret) * fH + fH / 2;
        const r = document.createElementNS(ns, 'rect');
        r.setAttribute('x', fX - 3); r.setAttribute('y', y - 3);
        r.setAttribute('width', tX - fX + 6); r.setAttribute('height', 6);
        r.setAttribute('rx', 3); r.setAttribute('fill', '#2c3e50');
        svg.appendChild(r);
    });

    const fCol = { 1: '#2c3e50', 2: '#e74c3c', 3: '#f39c12', 4: '#3b82f6' };
    positions.forEach(p => {
        const x = mL + (p.string - 1) * sS;
        const y = mT + (p.fret - startFret) * fH + fH / 2;
        const c = document.createElementNS(ns, 'circle');
        c.setAttribute('cx', x); c.setAttribute('cy', y);
        c.setAttribute('r', 4); c.setAttribute('fill', fCol[p.finger] || '#2c3e50');
        svg.appendChild(c);
    });

    container.innerHTML = '';
    container.appendChild(svg);
}

function sbnToast(message, type) {
    type = type || 'info';
    const toast = document.createElement('div');
    toast.className = 'sbn-toast sbn-toast-' + type;
    toast.textContent = message;
    document.body.appendChild(toast);
    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transform = 'translateY(16px)';
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}
</script>

<script>
function chordsPage() {
    return {
        tab: 'library',
        chords: @json($chords),
        organised: [],
        search: '',
        filterCategory: '',
        filterQuality: '',
        filterRootString: '',
        visibleCount: 0,
        recomputing: false,

        // Draft management
        dismissed: [],
        promoting: null,
        dismissing: null,
        clearing: false,
        reprocessing: false,

        categoryLabels: @json($voicingCategories),
        rootStringLabels: @json($rootStrings),

        csrfToken: document.querySelector('meta[name="csrf-token"]').content,

        init() {
            this.buildOrganised();
            this.applyFilters();

            // Check URL hash for direct tab linking
            if (window.location.hash === '#unmatched') {
                this.tab = 'unmatched';
            }

            // Render draft fretboards after Alpine settles
            this.$nextTick(() => {
                document.querySelectorAll('.sbn-draft-diagram[data-diagram]').forEach(sbnRenderDraftMini);
            });

            // Re-render fretboards when switching tabs
            this.$watch('tab', val => {
                if (val === 'unmatched') {
                    this.$nextTick(() => {
                        document.querySelectorAll('.sbn-draft-diagram[data-diagram]').forEach(el => {
                            if (!el.querySelector('svg')) sbnRenderDraftMini(el);
                        });
                    });
                }
                if (val === 'popular') {
                    this.$nextTick(() => {
                        document.querySelectorAll('[x-show="tab === \'popular\'"] .sbn-chord-fretboard[data-diagram]').forEach(el => {
                            sbnRenderMini(el);
                        });
                    });
                }
                if (val === 'enriched') {
                    this.$nextTick(() => {
                        document.querySelectorAll('.sbn-enriched-fretboard[data-diagram]').forEach(el => {
                            sbnRenderMini(el);
                        });
                    });
                }
            });
        },

        // =============================================
        // LIBRARY TAB
        // =============================================

        buildOrganised() {
            const catOrder = Object.keys(this.categoryLabels);
            const rsOrder  = Object.keys(this.rootStringLabels);
            const map = {};

            this.chords.forEach(c => {
                c._visible = true;
                const cat = c.voicing_category || 'custom';
                const rs  = c.root_string || 'roota';
                if (!map[cat]) map[cat] = {};
                if (!map[cat][rs]) map[cat][rs] = [];
                map[cat][rs].push(c);
            });

            this.organised = catOrder
                .filter(cat => map[cat])
                .map(cat => {
                    const rs = rsOrder
                        .filter(rs => map[cat] && map[cat][rs])
                        .map(rs => ({
                            rootString: rs,
                            rootStringLabel: this.rootStringLabels[rs] || rs,
                            visible: true,
                            chords: map[cat][rs],
                        }));
                    return {
                        category: cat,
                        categoryLabel: this.categoryLabels[cat] || cat,
                        visible: true,
                        count: rs.reduce((sum, g) => sum + g.chords.length, 0),
                        rootStrings: rs,
                    };
                });

            Object.keys(map).forEach(cat => {
                if (!catOrder.includes(cat)) {
                    const rs = Object.keys(map[cat]).map(rs => ({
                        rootString: rs,
                        rootStringLabel: this.rootStringLabels[rs] || rs,
                        visible: true,
                        chords: map[cat][rs],
                    }));
                    this.organised.push({
                        category: cat,
                        categoryLabel: cat,
                        visible: true,
                        count: rs.reduce((sum, g) => sum + g.chords.length, 0),
                        rootStrings: rs,
                    });
                }
            });
        },

        applyFilters() {
            const s  = this.search.toLowerCase().trim();
            const fc = this.filterCategory;
            const fq = this.filterQuality;
            const fr = this.filterRootString;
            let count = 0;

            this.organised.forEach(catGroup => {
                let catVisible = false;
                if (fc && catGroup.category !== fc) {
                    catGroup.visible = false;
                    catGroup.rootStrings.forEach(rsg => {
                        rsg.visible = false;
                        rsg.chords.forEach(c => c._visible = false);
                    });
                    return;
                }

                catGroup.rootStrings.forEach(rsGroup => {
                    let rsVisible = false;
                    if (fr && rsGroup.rootString !== fr) {
                        rsGroup.visible = false;
                        rsGroup.chords.forEach(c => c._visible = false);
                        return;
                    }

                    rsGroup.chords.forEach(c => {
                        let vis = true;
                        if (fq && c.quality !== fq) vis = false;
                        if (s && vis) {
                            const haystack = [
                                c.name, c.slug, c.quality_label,
                                c.category_label, c.extensions,
                                c.shape_slug, c.description
                            ].join(' ').toLowerCase();
                            vis = haystack.includes(s);
                        }
                        c._visible = vis;
                        if (vis) { rsVisible = true; count++; }
                    });

                    rsGroup.visible = rsVisible;
                    if (rsVisible) catVisible = true;
                });

                catGroup.visible = catVisible;
            });

            this.visibleCount = count;
        },

        async deleteChord(chord) {
            if (!confirm('Delete this chord diagram?')) return;
            const res = await fetch(`/api/admin/chords/${chord.id}`, {
                method: 'DELETE',
                headers: { 'X-CSRF-TOKEN': this.csrfToken, 'Accept': 'application/json' },
            });
            if (res.ok) {
                this.chords = this.chords.filter(c => c.id !== chord.id);
                this.buildOrganised();
                this.applyFilters();
                sbnToast('Chord diagram deleted', 'success');
            } else {
                sbnToast('Error deleting diagram', 'error');
            }
        },

        async duplicateChord(chord) {
            const res = await fetch(`/api/admin/chords/${chord.id}/duplicate`, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': this.csrfToken, 'Accept': 'application/json' },
            });
            if (res.ok) {
                sbnToast('Chord duplicated! Reloading…', 'success');
                setTimeout(() => location.reload(), 800);
            } else {
                sbnToast('Error duplicating', 'error');
            }
        },

        async recomputeIntervals() {
            this.recomputing = true;
            const res = await fetch('/api/admin/chords/recompute', {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': this.csrfToken, 'Accept': 'application/json' },
            });
            if (res.ok) {
                const json = await res.json();
                const d = json.data;
                sbnToast(`Updated ${d.updated} shapes` + (d.skipped > 0 ? `, ${d.skipped} skipped` : ''), 'success');
                setTimeout(() => location.reload(), 1000);
            } else {
                sbnToast('Error recomputing', 'error');
            }
            this.recomputing = false;
        },

        // =============================================
        // UNMATCHED VOICINGS TAB
        // =============================================

        async dismissDraft(id) {
            this.dismissing = id;
            try {
                const res = await fetch(`/api/admin/voicings/${id}/dismiss`, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': this.csrfToken, 'Accept': 'application/json' },
                });
                const data = await res.json();
                if (data.success) {
                    this.dismissed.push(id);
                    sbnToast('Draft dismissed', 'success');
                } else {
                    sbnToast(data.error || 'Error', 'error');
                }
            } catch (e) { sbnToast('Request failed', 'error'); }
            this.dismissing = null;
        },

        async promoteDraft(id) {
            this.promoting = id;
            try {
                const res = await fetch(`/api/admin/voicings/${id}/promote`, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': this.csrfToken, 'Accept': 'application/json' },
                });
                const data = await res.json();
                if (data.success && data.edit_url) {
                    window.location.href = data.edit_url;
                } else {
                    sbnToast(data.error || 'Error', 'error');
                    this.promoting = null;
                }
            } catch (e) {
                sbnToast('Request failed', 'error');
                this.promoting = null;
            }
        },

        async clearAllDrafts() {
            if (!confirm('Delete all pending unmatched voicings? This cannot be undone.')) return;
            this.clearing = true;
            try {
                const res = await fetch('/api/admin/voicings/clear-all', {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': this.csrfToken, 'Accept': 'application/json' },
                });
                const data = await res.json();
                if (data.success) { window.location.reload(); }
                else { sbnToast('Error', 'error'); this.clearing = false; }
            } catch (e) { sbnToast('Request failed', 'error'); this.clearing = false; }
        },

        async reprocessAll() {
            this.reprocessing = true;
            try {
                const res = await fetch('/api/admin/voicings/reprocess', {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': this.csrfToken, 'Accept': 'application/json' },
                });
                const data = await res.json();
                if (data.success) {
                    sbnToast('Reprocessing complete!', 'success');
                    setTimeout(() => window.location.reload(), 1500);
                } else {
                    sbnToast(data.message || 'Not yet available', 'warning');
                }
            } catch (e) { sbnToast('Request failed', 'error'); }
            this.reprocessing = false;
        },
    };
}
</script>
@endpush
