@extends('layouts.admin')

@section('title', 'Progression Builder')

@section('actions')
    <a href="{{ route('admin.progressions.index') }}" class="sbn-btn sbn-btn-secondary">
        <svg width="14" height="14" viewBox="0 0 20 20" fill="currentColor">
            <path fill-rule="evenodd" d="M9.707 16.707a1 1 0 01-1.414 0l-6-6a1 1 0 010-1.414l6-6a1 1 0 011.414 1.414L5.414 9H17a1 1 0 110 2H5.414l4.293 4.293a1 1 0 010 1.414z" clip-rule="evenodd"/>
        </svg>
        Progressions
    </a>
@endsection

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/progression-builder.css') }}">
@endpush

{{-- ══════════════════════════════════════════════════════════════════════
     MAIN CONTENT
══════════════════════════════════════════════════════════════════════ --}}
@section('content')
<div x-data="pbApp()" x-cloak>

    {{-- ── Input mode tabs ────────────────────────────────────────────── --}}
    <div class="sbn-pb-tabs">
        <button class="sbn-pb-tab" :class="inputMode === 'manual'      && 'is-active'" @click="inputMode = 'manual'">Manual chords</button>
        <button class="sbn-pb-tab" :class="inputMode === 'numerals'    && 'is-active'" @click="inputMode = 'numerals'">Roman numerals</button>
        <button class="sbn-pb-tab" :class="inputMode === 'leadsheet'   && 'is-active'" @click="inputMode = 'leadsheet'">Leadsheet</button>
        <button class="sbn-pb-tab" :class="inputMode === 'progression' && 'is-active'" @click="inputMode = 'progression'">Library progression</button>
    </div>

    {{-- ── Error notice ────────────────────────────────────────────────── --}}
    <template x-if="error">
        <div class="sbn-pb-error">
            <svg width="16" height="16" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
            </svg>
            <span x-text="error"></span>
        </div>
    </template>

    {{-- ══════════════════════════════════════════════════════════════════
         INPUT PANELS
    ══════════════════════════════════════════════════════════════════ --}}

    <div class="sbn-pb-input-panel" x-show="inputMode === 'manual'">
        <h3>Enter chord sequence</h3>
        <div class="sbn-pb-input-row">
            <label>Key</label>
            <select class="sbn-pb-key-select" x-model="key">
                <template x-for="k in allKeys" :key="k"><option :value="k" x-text="k"></option></template>
            </select>
            <div class="sbn-pb-chord-tag-wrap" @click="$refs.chordInput.focus()">
                <template x-for="(chord, i) in manualChords" :key="i">
                    <span class="sbn-pb-chord-tag">
                        <span x-text="chord"></span>
                        <button class="sbn-pb-chord-tag-remove" @click.stop="removeManualChord(i)" title="Remove">✕</button>
                    </span>
                </template>
                <input class="sbn-pb-chord-input" x-ref="chordInput" x-model="chordInputValue"
                    @keydown.enter.prevent="addManualChord()" @keydown.space.prevent="addManualChord()"
                    @keydown.backspace="chordInputValue === '' && removeManualChord(manualChords.length - 1)"
                    @keydown.comma.prevent="addManualChord()"
                    placeholder="Type chord, press Enter…" autocomplete="off" spellcheck="false">
            </div>
            <button class="sbn-btn sbn-btn-secondary" @click="manualChords = []; chordInputValue = ''"
                    x-show="manualChords.length > 0" title="Clear all chords">Clear</button>
        </div>
        <p class="sbn-pb-input-hint">Enter chord names separated by Enter, Space or comma. E.g. Dm7 → G7 → Cmaj7</p>
    </div>

    <div class="sbn-pb-input-panel" x-show="inputMode === 'numerals'">
        <h3>Enter Roman numerals</h3>
        <div class="sbn-pb-input-row">
            <label>Key</label>
            <select class="sbn-pb-key-select" x-model="key">
                <template x-for="k in allKeys" :key="k"><option :value="k" x-text="k"></option></template>
            </select>
            <input class="sbn-pb-numeral-input" x-model="numeralValue"
                placeholder="e.g. IIm7, V7, Imaj7" autocomplete="off" spellcheck="false"
                @keydown.enter.prevent="runBuild()">
        </div>
        <p class="sbn-pb-input-hint">Comma-separated numerals. Supported: Im7 IIm7 IIIm7 IVmaj7 V7 VIm7 VIIm7b5 — plus chromatic variants.</p>
    </div>

    <div class="sbn-pb-input-panel" x-show="inputMode === 'leadsheet'">
        <h3>Build from leadsheet</h3>
        <div class="sbn-pb-input-row">
            <label>Leadsheet</label>
            <select class="sbn-select" x-model="selectedLeadsheetId" style="flex:1;max-width:480px;">
                <option value="">— Select leadsheet —</option>
                @foreach($leadsheets as $ls)
                    <option value="{{ $ls->id }}">{{ $ls->title }}{{ $ls->key ? ' ('.$ls->key.')' : '' }}</option>
                @endforeach
            </select>
            <a x-show="selectedLeadsheetId" :href="'/admin/leadsheets/' + selectedLeadsheetId + '/edit'"
               class="sbn-btn sbn-btn-secondary" style="padding:8px 12px" title="Open in editor" target="_blank">
                <svg width="13" height="13" viewBox="0 0 20 20" fill="currentColor">
                    <path d="M11 3a1 1 0 100 2h2.586l-6.293 6.293a1 1 0 101.414 1.414L15 6.414V9a1 1 0 102 0V4a1 1 0 00-1-1h-5z"/>
                    <path d="M5 5a2 2 0 00-2 2v8a2 2 0 002 2h8a2 2 0 002-2v-3a1 1 0 10-2 0v3H5V7h3a1 1 0 000-2H5z"/>
                </svg>
            </a>
        </div>
        <p class="sbn-pb-input-hint">Generates voicings for every chord in the leadsheet. Long leadsheets are split by section.</p>
    </div>

    <div class="sbn-pb-input-panel" x-show="inputMode === 'progression'">
        <h3>Build from library progression</h3>
        <div class="sbn-pb-input-row">
            <label>Key</label>
            <select class="sbn-pb-key-select" x-model="key">
                <template x-for="k in allKeys" :key="k"><option :value="k" x-text="k"></option></template>
            </select>
            <select class="sbn-select" x-model="selectedProgressionId" style="flex:1;max-width:480px;">
                <option value="">— Select progression —</option>
                @foreach($progressions as $prog)
                    <option value="{{ $prog->id }}" data-numerals="{{ $prog->numerals }}" data-category="{{ $prog->category }}">
                        {{ $prog->name }} — {{ $prog->numerals }}
                    </option>
                @endforeach
            </select>
        </div>
        <p class="sbn-pb-input-hint">Resolves the progression's Roman numerals into concrete chords in the selected key.</p>
    </div>

    {{-- ══════════════════════════════════════════════════════════════════
         SETTINGS BAR
    ══════════════════════════════════════════════════════════════════ --}}
    <div class="sbn-pb-settings">
        <span class="sbn-pb-settings-label">Style</span>
        <div class="sbn-pb-seg" role="group">
            <template x-for="opt in styleOpts" :key="opt.val">
                <button class="sbn-pb-seg-btn" :class="settings.style === opt.val && 'is-active'"
                        @click="settings.style = opt.val" x-text="opt.label"></button>
            </template>
        </div>
        <div class="sbn-pb-divider"></div>
        <button class="sbn-pb-toggle" :class="settings.extensions && 'is-active'"
                @click="settings.extensions = !settings.extensions" title="Include 9ths, 11ths, 13ths">Extensions</button>
        <button class="sbn-pb-toggle" :class="!settings.rootOnly && 'is-active'"
                @click="settings.rootOnly = !settings.rootOnly" title="Allow inverted voicings">Inversions</button>

        <button class="sbn-pb-btn-suggest" @click="runBuild()" :disabled="loading">
            <template x-if="loading"><span class="sbn-pb-spinner"></span></template>
            <template x-if="!loading">
                <svg width="14" height="14" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M11.3 1.046A1 1 0 0112 2v5h4a1 1 0 01.82 1.573l-7 10A1 1 0 018 18v-5H4a1 1 0 01-.82-1.573l7-10a1 1 0 011.12-.38z" clip-rule="evenodd"/>
                </svg>
            </template>
            <span x-text="countSelections() > 0 ? '✦ Complete' : '✦ Suggest'"></span>
        </button>

        <button class="sbn-pb-btn-clear" x-show="countSelections() > 0" @click="clearSelections()">Clear</button>

        <button class="sbn-btn sbn-btn-primary"
                x-show="countSelections() > 0 && selectedLeadsheetId"
                :disabled="applying" @click="applyToLeadsheet()">
            <span x-show="applying" class="sbn-pb-spinner"></span>
            <span x-show="!applying">Apply to Leadsheet</span>
        </button>
    </div>

    {{-- ══════════════════════════════════════════════════════════════════
         OUTPUT — CHORD GRID  (4 chords per row, leadsheet-style)
    ══════════════════════════════════════════════════════════════════ --}}
    <div class="sbn-pb-output">

        <div class="sbn-pb-loading" x-show="loading">
            <span class="sbn-pb-spinner"></span>
            <span>Building voice-led voicings…</span>
        </div>

        <div class="sbn-pb-output-empty" x-show="!loading && chords.length === 0">
            <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2">
                <circle cx="12" cy="12" r="10"/>
                <path d="M9 9h.01M15 9h.01M8 13s1 2 4 2 4-2 4-2"/>
            </svg>
            <p>Enter a chord sequence and click <strong>Suggest</strong></p>
        </div>

        <template x-if="!loading && chords.length > 0">
            <div>
                {{-- Iterate sections, then rows of 4 within each section --}}
                <template x-for="(section, si) in gridSections()" :key="si">
                    <div class="sbn-pb-section">
                        {{-- Section header (only shown when there are multiple sections) --}}
                        <template x-if="gridSections().length > 1">
                            <div class="sbn-pb-section-header">
                                <div class="sbn-pb-section-id" x-text="section.label.charAt(0)"></div>
                                <span class="sbn-pb-section-name" x-text="section.label"></span>
                            </div>
                        </template>

                        {{-- Rows of 4 --}}
                        <template x-for="(row, ri) in section.rows" :key="ri">
                            <div class="sbn-pb-row">
                                <template x-for="(slot, ci) in row" :key="ci">
                                    <div class="sbn-ve-measure"
                                         :class="{ 'is-active': activeSlot === slot.idx }"
                                         style="position:relative"
                                         @click="toggleSlot(slot.idx)"
                                         @mouseenter="$dispatch('pb-hover-voicing', { voicing: selections[slot.idx], slot: slot.idx })"
                                         @mouseleave="$dispatch('pb-hover-voicing', { voicing: null, slot: slot.idx })">

                                        <div class="sbn-ve-measure-content">
                                            <div class="sbn-ve-chord">
                                                {{-- Chord name --}}
                                                <div class="sbn-ve-chord-name"
                                                     x-html="formatChordHtml(chords[slot.idx] ? chords[slot.idx].chord_name : '')"></div>

                                                {{-- Roman numeral --}}
                                                <div class="sbn-pb-numeral"
                                                     x-show="chords[slot.idx] && chords[slot.idx].roman_numeral"
                                                     x-text="chords[slot.idx] ? chords[slot.idx].roman_numeral : ''"></div>

                                                {{-- Diagram or placeholder --}}
                                                <div class="sbn-ve-chord-diagram"
                                                     :class="selections[slot.idx] ? '' : 'empty'">
                                                    <template x-if="selections[slot.idx]">
                                                        <div class="sbn-diagram-card" x-html="renderDiagram(selections[slot.idx])"></div>
                                                    </template>
                                                    <template x-if="!selections[slot.idx]">
                                                        <span>+</span>
                                                    </template>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </template>

                                {{-- Pad row to 4 with empty cells --}}
                                <template x-for="n in (4 - row.length)" :key="'pad-' + n">
                                    <div class="sbn-ve-measure" style="cursor:default;opacity:0;pointer-events:none"></div>
                                </template>
                            </div>
                        </template>
                    </div>
                </template>
            </div>
        </template>
    </div>

    {{-- ══════════════════════════════════════════════════════════════════
         FRETBOARD
    ══════════════════════════════════════════════════════════════════ --}}
    <div class="sbn-pb-fretboard"
         :class="fbTheme === 'light' ? 'theme-light' : ''"
         x-data="pbFretboard()"
         x-show="chords.length > 0"
         x-init="init()"
         @pb-hover-voicing.window="onHoverVoicing($event.detail)"
         @pb-slot-change.window="onSlotChange()">

        <div class="sbn-pb-fb-header">
            <span class="sbn-pb-fb-title">Fretboard</span>
            <template x-if="displayVoicing">
                <span class="sbn-pb-fb-chord-label" x-html="formatChordHtml(displayChordName)"></span>
            </template>
            <div class="sbn-pb-fb-header-actions">
                <button class="sbn-pb-fb-gt-btn" :class="guideToneMode && 'is-active'"
                        x-show="displayVoicing"
                        @click="guideToneMode = !guideToneMode; renderFretboard()"
                        title="Toggle guide tone visualization">Guide Tones</button>
                <button class="sbn-pb-fb-theme-btn" @click="fbTheme = fbTheme === 'dark' ? 'light' : 'dark'"
                        x-text="fbTheme === 'dark' ? '☀ Light' : '🌙 Dark'"></button>
            </div>
        </div>

        <div class="sbn-pb-fb-body">
            <div class="sbn-pb-fb-empty" x-show="!displayVoicing">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="opacity:0.4">
                    <rect x="2" y="5" width="20" height="14" rx="2"/>
                    <line x1="6" y1="5" x2="6" y2="19"/><line x1="10" y1="5" x2="10" y2="19"/>
                    <line x1="14" y1="5" x2="14" y2="19"/><line x1="18" y1="5" x2="18" y2="19"/>
                </svg>
                Select a chord slot to see voicing on fretboard
            </div>
            <div x-ref="fbMount" x-show="displayVoicing"></div>
        </div>

        <div class="sbn-pb-fb-legend" x-show="guideToneMode && displayVoicing">
            <span class="sbn-pb-fb-legend-item"><span class="sbn-pb-fb-legend-dot gt-seventh"></span> b7 / 7</span>
            <span class="sbn-pb-fb-legend-item"><span class="sbn-pb-fb-legend-dot gt-third"></span> 3 / b3</span>
            <span class="sbn-pb-fb-legend-item"><span class="sbn-pb-fb-legend-dot gt-root"></span> Root</span>
            <span class="sbn-pb-fb-legend-item"><span class="sbn-pb-fb-legend-dot gt-ninth"></span> 9 / b9</span>
            <span class="sbn-pb-fb-legend-item" style="margin-left:8px;font-size:10px;color:var(--clr-text-muted)">Dashed = next chord target</span>
        </div>
    </div>

</div>
@endsection

{{-- ══════════════════════════════════════════════════════════════════════
     RIGHT CONTEXT PANEL — Voicing Picker
══════════════════════════════════════════════════════════════════════ --}}
@section('context')
<div x-data="pbApp()" x-cloak class="sbn-vp-context">

    {{-- Active picker: chord selected --}}
    <div class="sbn-vp-picker-wrap" x-show="activeSlot >= 0 && chords.length > 0">
        <div class="sbn-vp-header">
            <div>
                <div class="sbn-vp-subtitle">Choose voicing</div>
                <div class="sbn-vp-chord-name"
                     x-html="activeSlot >= 0 && chords[activeSlot] ? formatChordHtml(slotDisplayName(activeSlot)) : ''"></div>
            </div>
            <button class="sbn-vp-close" @click="activeSlot = -1" aria-label="Close">×</button>
        </div>

        {{-- Category + root string pills + Ext/Inv steppers --}}
        <div class="sbn-vp-filters">
            <div class="sbn-vp-filter-row">
                <template x-for="cat in pickerCategories()" :key="cat.val">
                    <button class="sbn-vp-pill"
                            :class="pickerCatFilter === cat.val && 'active'"
                            @click="pickerCatFilter = pickerCatFilter === cat.val ? '' : cat.val"
                            x-text="cat.label"></button>
                </template>
            </div>
            <div class="sbn-vp-filter-row">
                <template x-for="rs in pickerRootStrings()" :key="rs">
                    <button class="sbn-vp-pill"
                            :class="pickerRootString === rs && 'active'"
                            @click="pickerRootString = pickerRootString === rs ? '' : rs"
                            x-text="rs"></button>
                </template>
            </div>
            <div class="sbn-vp-filter-row sbn-vp-steppers">
                <div class="sbn-vp-stepper" :class="pickerExt && 'has-value'">
                    <span class="sbn-vp-stepper-label">Ext</span>
                    <button class="sbn-vp-step-btn" @click="stepPickerExt(-1)">&larr;</button>
                    <span class="sbn-vp-step-value"
                          :class="pickerExt && 'active'"
                          @click="pickerExt = ''"
                          x-text="pickerExt || '—'"></span>
                    <button class="sbn-vp-step-btn" @click="stepPickerExt(1)">&rarr;</button>
                </div>
                <div class="sbn-vp-stepper" :class="pickerInv && pickerInv !== 'all' && 'has-value'">
                    <span class="sbn-vp-stepper-label">Inv</span>
                    <button class="sbn-vp-step-btn" @click="stepPickerInv(-1)">&larr;</button>
                    <span class="sbn-vp-step-value"
                          :class="pickerInv && pickerInv !== 'all' && 'active'"
                          x-text="pickerInvLabel()"></span>
                    <button class="sbn-vp-step-btn" @click="stepPickerInv(1)">&rarr;</button>
                </div>
            </div>
            <div class="sbn-vp-filter-row sbn-vp-reset-row" x-show="pickerHasActiveFilters()">
                <button class="sbn-vp-reset" @click="resetPickerFilters()">Reset filters</button>
            </div>
        </div>

        <div class="sbn-vp-body">
            <div class="sbn-vp-empty" x-show="filteredPickerCards().length === 0">
                <div style="font-size:20px;margin-bottom:6px;opacity:0.4">📭</div>
                No voicings found.
                <div class="sbn-vp-empty-hint">Try adjusting the filter.</div>
            </div>

            <div class="sbn-vp-grid" x-show="filteredPickerCards().length > 0">
                <template x-for="v in filteredPickerCards()" :key="v._key">
                    <div class="sbn-vp-card"
                         :class="isSelected(v) && 'is-selected'"
                         @click="selectVoicing(activeSlot, v)"
                         @mouseenter="$dispatch('pb-hover-voicing', { voicing: v, slot: activeSlot })"
                         @mouseleave="$dispatch('pb-hover-voicing', { voicing: null, slot: activeSlot })">
                        <template x-if="isSelected(v)">
                            <div class="sbn-vp-check">✓</div>
                        </template>
                        <div class="sbn-vp-card-name" x-html="formatChordHtml(v.chord_name || '')"></div>
                        <span x-html="renderMiniDiagram(v)"></span>
                    </div>
                </template>
            </div>
        </div>

        <div class="sbn-vp-footer">
            <span></span>
            <span class="sbn-vp-count"
                  x-text="filteredPickerCards().length + ' voicing' + (filteredPickerCards().length !== 1 ? 's' : '')"></span>
        </div>
    </div>

    {{-- Resting state: no slot selected --}}
    <div class="sbn-vp-overview" x-show="activeSlot < 0 && chords.length > 0">
        <div class="sbn-vp-overview-header">
            <div class="sbn-vp-subtitle">Voicings</div>
            <span class="sbn-vp-overview-count" x-text="countSelections() + ' / ' + chords.length + ' selected'"></span>
        </div>
        <div class="sbn-vp-overview-grid">
            <template x-for="(chord, idx) in chords" :key="idx">
                <div class="sbn-vp-overview-card"
                     :class="selections[idx] ? 'has-voicing' : ''"
                     @click="toggleSlot(idx)">
                    <div class="sbn-vp-card-name" x-html="formatChordHtml(chord.chord_name)"></div>
                    <template x-if="selections[idx]">
                        <span x-html="renderDiagram(selections[idx], 56)"></span>
                    </template>
                    <template x-if="!selections[idx]">
                        <div class="sbn-vp-overview-empty"><span>+</span></div>
                    </template>
                </div>
            </template>
        </div>
    </div>

    {{-- Empty state: no build yet --}}
    <div x-show="chords.length === 0"
         style="display:flex;align-items:center;justify-content:center;flex:1;color:var(--clr-text-muted);font-size:13px;padding:16px">
        <div style="text-align:center">
            <div style="font-size:28px;margin-bottom:8px;opacity:0.3">🎸</div>
            Build a progression to select voicings.
        </div>
    </div>

</div>
@endsection

@push('scripts')
<script src="{{ asset('js/sbn-chord-name.js') }}"></script>
<script src="{{ asset('js/chords.js') }}"></script>
<script>
// =============================================================================
// HELPERS
// =============================================================================

const VOICING_LABELS = { drop2:'Drop 2', drop3:'Drop 3', shell:'Shell', closed:'Closed', open:'Open' };

function formatChordHtml(name) {
    if (!name) return '';
    if (typeof sbnFormatChord === 'function') return sbnFormatChord(name);
    const m = name.match(/^([A-G][#b♯♭]?)(.*)$/);
    if (!m) return name;
    let root = m[1].replace('#','♯').replace('b','♭');
    let qual = m[2], bass = '';
    const si = qual.indexOf('/');
    if (si >= 0) { bass = '/' + qual.slice(si+1).replace('#','♯').replace('b','♭'); qual = qual.slice(0,si); }
    if (!qual) return `<span class="sbn-chord-symbol"><span class="sbn-chord-root">${root}</span></span>`;
    return `<span class="sbn-chord-symbol"><span class="sbn-chord-root">${root}</span><span class="sbn-chord-ext">${qual}</span>${bass}</span>`;
}

function vlClass(score) {
    if (score === null || score === undefined) return 'empty';
    if (score <= 2) return 'excellent';
    if (score <= 5) return 'good';
    if (score <= 8) return 'fair';
    return 'rough';
}

// =============================================================================
// ALPINE REGISTRATION — store + components registered before DOM walk
// =============================================================================

document.addEventListener('alpine:init', () => {

    // ── Shared store ─────────────────────────────────────────────────────────
    Alpine.store('pb', {
        chords:       [],
        sections:     [],
        selections:   [],
        vlScores:     [],
        activeSlot:   -1,
        loading:      false,
        key:          'C',
        style:        '',
        selectedCount: 0,

        setResult(data, contextData) {
            this.key = contextData.key || 'C';
            const sels = data.selections || [];
            this.chords     = sels.map(s => ({ chord_name: s.chord_name, roman_numeral: s.roman_numeral || '', quality: s.quality || '', voicings: s.voicings || [] }));
            this.selections = sels.map(s => s.voicing || null);
            this.vlScores   = data.vl_scores || [];
            this.sections   = data.sections || [];
            this.activeSlot = -1;
            this.selectedCount = this.selections.filter(Boolean).length;
            window.dispatchEvent(new CustomEvent('pb-slot-change'));
        },

        setSelections(newSels, newScores) {
            this.selections    = newSels;
            this.vlScores      = newScores;
            this.selectedCount = newSels.filter(Boolean).length;
            window.dispatchEvent(new CustomEvent('pb-slot-change'));
        },
    });

    // ── pbApp component ───────────────────────────────────────────────────────
    Alpine.data('pbApp', () => ({
        inputMode:             'manual',
        key:                   'C',
        manualChords:          [],
        chordInputValue:       '',
        numeralValue:          '',
        selectedLeadsheetId:   '',
        selectedProgressionId: '',
        loading:               false,
        error:                 null,
        applying:              false,
        pickerCatFilter:       '',   // active category filter
        pickerRootString:      '',   // active root string filter (e.g. '6', '5', '4')
        pickerExt:             '',   // active extension filter (e.g. '9', 'b9', '11')
        pickerInv:             'all', // active inversion filter

        allKeys: ['C','Db','D','Eb','E','F','F#','G','Ab','A','Bb','B'],

        styleOpts: [
            { val:'',       label:'Any'    },
            { val:'shell',  label:'Shell'  },
            { val:'drop',   label:'Drop'   },
            { val:'closed', label:'Closed' },
        ],

        settings: { style:'', extensions:false, rootOnly:false },

        // Store shortcuts
        get chords()      { return this.$store.pb.chords; },
        get selections()  { return this.$store.pb.selections; },
        get vlScores()    { return this.$store.pb.vlScores; },
        get activeSlot()  { return this.$store.pb.activeSlot; },
        set activeSlot(v) { this.$store.pb.activeSlot = v; },

        // ── Tag input ─────────────────────────────────────────────────────
        addManualChord() {
            const v = this.chordInputValue.trim().replace(/,$/, '');
            if (v) { this.manualChords.push(v); this.chordInputValue = ''; }
        },
        removeManualChord(i) {
            if (i >= 0 && i < this.manualChords.length) this.manualChords.splice(i, 1);
        },

        // ── Build ─────────────────────────────────────────────────────────
        async runBuild() {
            this.error = null;
            if (this.inputMode === 'manual')      { this.addManualChord(); if (!this.manualChords.length) { this.error = 'Enter at least one chord.'; return; } }
            else if (this.inputMode === 'numerals')  { if (!this.numeralValue.trim()) { this.error = 'Enter Roman numeral string.'; return; } }
            else if (this.inputMode === 'leadsheet') { if (!this.selectedLeadsheetId) { this.error = 'Select a leadsheet.'; return; } }
            else if (this.inputMode === 'progression'){ if (!this.selectedProgressionId) { this.error = 'Select a progression from the library.'; return; } }

            this.loading = this.$store.pb.loading = true;
            try {
                const body = { style: this.settings.style, extensions: this.settings.extensions, root_only: this.settings.rootOnly, key: this.key };
                if      (this.inputMode === 'manual')      body.chords   = this.manualChords;
                else if (this.inputMode === 'numerals')    body.numerals = this.numeralValue;
                else if (this.inputMode === 'leadsheet')   { body.leadsheet_id = this.selectedLeadsheetId; delete body.key; }
                else if (this.inputMode === 'progression') {
                    const opt = document.querySelector(`select[x-model="selectedProgressionId"] option[value="${this.selectedProgressionId}"]`);
                    const numerals = opt ? opt.dataset.numerals : '';
                    if (!numerals) { this.error = 'Could not resolve numerals.'; this.loading = this.$store.pb.loading = false; return; }
                    body.numerals = numerals;
                }
                const resp = await fetch('/api/admin/progressions/build-voicings', {
                    method: 'POST',
                    headers: { 'Content-Type':'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content, 'Accept':'application/json' },
                    body: JSON.stringify(body),
                });
                const data = await resp.json();
                if (!resp.ok || !data.success) { this.error = data.error || 'Build failed.'; return; }
                this.$store.pb.setResult(data.data, data.context);
                this.pickerCatFilter = ''; this.pickerRootString = ''; this.pickerExt = ''; this.pickerInv = 'all';
            } catch(e) {
                this.error = 'Network error — could not reach the server.';
                console.error(e);
            } finally {
                this.loading = this.$store.pb.loading = false;
            }
        },

        // ── Slot interactions ─────────────────────────────────────────────
        toggleSlot(idx) {
            this.$store.pb.activeSlot = this.$store.pb.activeSlot === idx ? -1 : idx;
            this.pickerCatFilter = ''; this.pickerRootString = ''; this.pickerExt = ''; this.pickerInv = 'all';
            window.dispatchEvent(new CustomEvent('pb-slot-change'));
        },

        selectVoicing(idx, voicing) {
            const sels = [...this.$store.pb.selections];
            sels[idx] = voicing;
            this.$store.pb.setSelections(sels, [...this.$store.pb.vlScores]);
        },

        clearSelections() {
            const n = this.$store.pb.chords.length;
            this.$store.pb.setSelections(new Array(n).fill(null), new Array(Math.max(0, n-1)).fill(null));
            this.$store.pb.activeSlot = -1;
        },

        countSelections() { return this.$store.pb.selectedCount; },

        // ── Apply to leadsheet ────────────────────────────────────────────
        async applyToLeadsheet() {
            if (!this.selectedLeadsheetId || !this.$store.pb.selectedCount) return;
            const chords     = this.$store.pb.chords;
            const selections = this.$store.pb.selections.map((sel, i) => ({
                chord_name: chords[i]?.chord_name ?? (sel?.chord_name ?? ''),
                frets:      sel?.frets ?? null,
                position:   sel?.position ?? 1,
            }));
            this.applying = true; this.error = null;
            try {
                const resp = await fetch('/api/admin/leadsheets/' + this.selectedLeadsheetId + '/apply-progression', {
                    method: 'POST',
                    headers: { 'Content-Type':'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content, 'Accept':'application/json' },
                    body: JSON.stringify({ selections, time_signature: this.$store.pb.context?.time_signature ?? '4/4' }),
                });
                const data = await resp.json();
                if (!resp.ok || !data.success) { this.error = data.error || 'Apply failed.'; sbnToast(data.error || 'Apply failed.', 'error'); return; }
                sbnToast('Tab applied — ' + data.measures + ' measure(s) written.', 'success');
            } catch(e) {
                this.error = 'Network error.'; sbnToast('Network error.', 'error'); console.error(e);
            } finally {
                this.applying = false;
            }
        },

        // ── Display helpers ───────────────────────────────────────────────
        slotDisplayName(idx) {
            const chord = this.chords[idx];
            const sel   = this.selections[idx];
            if (!chord) return '';
            let base = chord.chord_name || '';
            if (!sel) return base;
            const ext = (sel.extensions || '').trim();
            if (ext && base.indexOf(ext) === -1) base += '(' + ext + ')';
            if (sel.inversion && sel.inversion !== 'root') {
                const notes = (sel.notes || '').split(',').filter(Boolean);
                if (notes[0]) base += '/' + notes[0];
            }
            return base;
        },

        renderDiagram(voicing) { return sbnRenderDiagramSVG(voicing); },

        vlClass,

        // ── Grid structure: sections → rows of 4 ─────────────────────────
        gridSections() {
            const store    = this.$store.pb;
            const chords   = store.chords;
            const sections = store.sections || [];

            // Build section boundaries
            const bounds = [];
            let offset = 0;
            for (const sec of sections) {
                bounds.push({ start: offset, end: offset + (sec.length || 0), label: sec.section_key || ('Section ' + (bounds.length + 1)) });
                offset += sec.length || 0;
            }
            // If no section data, treat all as one section
            if (!bounds.length) bounds.push({ start: 0, end: chords.length, label: 'A' });

            return bounds.map(b => {
                const sectionChords = [];
                for (let i = b.start; i < Math.min(b.end, chords.length); i++) sectionChords.push({ idx: i });
                // Split into rows of 4
                const rows = [];
                for (let i = 0; i < sectionChords.length; i += 4) rows.push(sectionChords.slice(i, i + 4));
                return { label: b.label, rows };
            }).filter(s => s.rows.length > 0);
        },

        // ── Context picker helpers ────────────────────────────────────────
        pickerCategories() {
            const idx = this.$store.pb.activeSlot;
            if (idx < 0) return [];
            const chord    = this.chords[idx];
            if (!chord) return [];
            const voicings = chord.voicings || [];
            const cats     = [...new Set(voicings.map(v => v.voicing_category || 'other'))];
            const order    = ['drop2','drop3','shell','closed','open','other'];
            return order.filter(c => cats.includes(c)).map(c => ({ val: c, label: VOICING_LABELS[c] || c }));
        },

        filteredPickerCards() {
            const idx = this.$store.pb.activeSlot;
            if (idx < 0) return [];
            const chord    = this.chords[idx];
            if (!chord) return [];
            const voicings = chord.voicings || [];
            return voicings
                .filter(v =>
                    (!this.pickerCatFilter || (v.voicing_category || 'other') === this.pickerCatFilter) &&
                    (!this.pickerRootString || String(v.root_string) === this.pickerRootString) &&
                    (!this.pickerExt || (v.extensions || '') === this.pickerExt) &&
                    (this.pickerInv === 'all' || !this.pickerInv || (v.inversion || 'root') === this.pickerInv)
                )
                .map((v, i) => Object.assign({}, v, { _key: (v.diagram_id ?? i) + '-' + i }));
        },

        isSelected(v) {
            const sel = this.selections[this.$store.pb.activeSlot];
            if (!sel || !v) return false;
            return sel.diagram_id === v.diagram_id && sel.voicing_category === v.voicing_category;
        },

        pickerRootStrings() {
            const idx = this.$store.pb.activeSlot;
            if (idx < 0) return [];
            const voicings = this.chords[idx]?.voicings || [];
            return [...new Set(voicings.map(v => String(v.root_string)).filter(Boolean))].sort();
        },

        stepPickerExt(dir) {
            const idx = this.$store.pb.activeSlot;
            const exts = [...new Set((this.chords[idx]?.voicings || [])
                .map(v => v.extensions || '').filter(Boolean))].sort();
            if (!exts.length) return;
            const i = exts.indexOf(this.pickerExt);
            if (dir > 0) this.pickerExt = i < exts.length - 1 ? exts[i + 1] : '';
            else         this.pickerExt = i > 0 ? exts[i - 1] : '';
        },

        stepPickerInv(dir) {
            const idx = this.$store.pb.activeSlot;
            const invs = ['all', ...[...new Set(
                (this.chords[idx]?.voicings || []).map(v => v.inversion || 'root').filter(Boolean)
            )]];
            const cur = invs.indexOf(this.pickerInv);
            const next = (cur === -1 ? 0 : cur) + dir;
            this.pickerInv = invs[(next + invs.length) % invs.length];
        },

        pickerInvLabel() {
            const labels = { all: 'All', root: 'Root', inv1: '1st', inv2: '2nd', inv3: '3rd' };
            return labels[this.pickerInv] || this.pickerInv || 'All';
        },

        pickerHasActiveFilters() {
            return !!(this.pickerCatFilter || this.pickerRootString || this.pickerExt ||
                (this.pickerInv && this.pickerInv !== 'all'));
        },

        resetPickerFilters() {
            this.pickerCatFilter = ''; this.pickerRootString = ''; this.pickerExt = ''; this.pickerInv = 'all';
        },

        renderMiniDiagram(voicing) { return sbnRenderMiniDiagramSVG(voicing); },
    }));

    // ── pbFretboard component ─────────────────────────────────────────────────
    Alpine.data('pbFretboard', () => ({
        fbTheme:          'dark',
        guideToneMode:    false,
        displayVoicing:   null,
        displayChordName: '',
        _hoverVoicing:    null,
        _hoverChordName:  '',

        init() { this.$watch('guideToneMode', () => this.renderFretboard()); },

        onHoverVoicing({ voicing, slot }) {
            this._hoverVoicing   = voicing || null;
            this._hoverChordName = voicing ? (Alpine.store('pb').chords[slot]?.chord_name || '') : '';
            this._refreshDisplay();
        },

        onSlotChange() { this._hoverVoicing = null; this._refreshDisplay(); },

        _refreshDisplay() {
            if (this._hoverVoicing) {
                this.displayVoicing = this._hoverVoicing; this.displayChordName = this._hoverChordName;
            } else {
                const store = Alpine.store('pb'), idx = store.activeSlot;
                if (idx >= 0 && store.selections[idx]) {
                    this.displayVoicing = store.selections[idx]; this.displayChordName = store.chords[idx]?.chord_name || '';
                } else {
                    this.displayVoicing = null; this.displayChordName = '';
                }
            }
            this.renderFretboard();
        },

        renderFretboard() {
            const mount = this.$refs.fbMount;
            if (!mount || !this.displayVoicing) return;
            mount.innerHTML = this._buildFretboardHtml(this.displayVoicing);
            if (this.guideToneMode) this._applyGuideTones(mount);
        },

        _buildFretboardHtml(voicing) {
            const fretStr    = voicing.frets || voicing.fret_string || '';
            const position   = parseInt(voicing.position || voicing.start_fret) || 1;
            const numFrets   = 12;
            const stringNames = ['e','B','G','D','A','E'];
            const fretMarkers = [3,5,7,9,12];
            const frets      = this._parseFretString(fretStr, position);
            const fingers    = (voicing.fingers || '').split('');
            const rhFings    = this._getRightHandFingers(frets);

            let h = `<div class="sbn-fretboard" data-position="${position}"><div class="sbn-fretboard-grid">`;
            h += '<div class="sbn-fretboard-string-labels">';
            stringNames.forEach((n, di) => { const si = 5-di; h += `<div class="sbn-fretboard-string-label" data-string="${si}">${n}</div>`; });
            h += '</div><div class="sbn-fretboard-open-fret">';
            stringNames.forEach((n, di) => {
                const si = 5-di, fv = frets[si];
                const isOpen = fv === 0 || fv === '0', isMuted = fv === 'x' || fv === 'X';
                h += `<div class="sbn-fretboard-string sbn-fretboard-open-string${isOpen?' is-open':''}${isMuted?' is-muted':''}" data-string="${si}">`;
                if (isOpen) h += '<div class="sbn-fretboard-open-dot"></div>';
                h += '</div>';
            });
            h += '</div><div class="sbn-fretboard-nut"></div><div class="sbn-fretboard-frets">';
            for (let f = 1; f <= numFrets; f++) {
                const hasMarker = fretMarkers.includes(f), isDouble = f === 12;
                h += `<div class="sbn-fretboard-fret${hasMarker?' has-marker':''}${isDouble?' double-marker':''}" data-fret="${f}">`;
                stringNames.forEach((n, di) => {
                    const si = 5-di, fv = frets[si], fNum = parseInt(fv), hasDot = !isNaN(fNum) && fNum === f;
                    h += `<div class="sbn-fretboard-string${hasDot?' has-dot':''}" data-string="${si}">`;
                    if (hasDot) { const fd = parseInt(fingers[si]); h += `<div class="sbn-fretboard-dot${fd > 0?' has-finger':''}">${fd > 0 ? fd : ''}</div>`; }
                    h += '</div>';
                });
                if ([1,3,5,7,9,12].includes(f)) h += `<div class="sbn-fretboard-fret-num">${f}</div>`;
                h += '</div>';
            }
            h += '</div><div class="sbn-fretboard-rh-fingers">';
            stringNames.forEach((n, di) => {
                const si = 5-di, isMuted = frets[si] === 'x' || frets[si] === 'X';
                h += `<div class="sbn-fretboard-rh-finger${isMuted?' is-muted':''}" data-string="${si}">${rhFings[si] || ''}</div>`;
            });
            h += '</div></div></div>';
            return h;
        },

        _applyGuideTones(mount) {
            const chordName = this.displayChordName;
            if (!chordName || !this.displayVoicing) return;
            const pitchMap = this._getVoicingPitchMap(this.displayVoicing, chordName);
            pitchMap.forEach(p => {
                const fretEl = p.fret === 0
                    ? mount.querySelector(`.sbn-fretboard-open-string[data-string="${p.string}"]`)
                    : (() => { const fe = mount.querySelector(`.sbn-fretboard-fret[data-fret="${p.fret}"]`); return fe ? fe.querySelector(`.sbn-fretboard-string[data-string="${p.string}"]`) : null; })();
                if (!fretEl || !p.label) return;
                const dot = p.fret === 0 ? fretEl.querySelector('.sbn-fretboard-open-dot') : fretEl.querySelector('.sbn-fretboard-dot');
                if (!dot) return;
                const gtClass = this._intervalToGtClass(p.label);
                if (gtClass) { dot.classList.add(gtClass); dot.textContent = p.label; dot.style.fontSize = '7px'; dot.style.fontWeight = '800'; }
            });
            const store = Alpine.store('pb'), idx = store.activeSlot;
            const nextVoicing = idx >= 0 ? store.selections[idx + 1] : null;
            const nextChordName = idx >= 0 ? store.chords[idx + 1]?.chord_name : null;
            if (nextVoicing && nextChordName) this._renderGuideToneGhosts(mount, pitchMap, nextVoicing, nextChordName);
        },

        _intervalToGtClass(label) {
            if (!label) return '';
            if (label === 'b7' || label === '7' || label === 'maj7') return 'gt-seventh';
            if (label === '3'  || label === 'b3')                    return 'gt-third';
            if (label === 'R')                                        return 'gt-root';
            if (label === '9'  || label === 'b9')                    return 'gt-ninth';
            if (label === '5')                                        return 'gt-fifth';
            return '';
        },

        _renderGuideToneGhosts(mount, currentPitchMap, nextVoicing, nextChordName) {
            const nextPitchMap = this._getVoicingPitchMap(nextVoicing, nextChordName);
            if (!nextPitchMap.length) return;
            const SEVENTH = {'b7':1,'7':1,'maj7':1}, THIRD = {'3':1,'b3':1}, ROOT = {'R':1};
            const pairs = [];
            currentPitchMap.filter(p => SEVENTH[p.label]).forEach(s7 => {
                let best = null, bd = 99;
                nextPitchMap.filter(p => THIRD[p.label]).forEach(t => { const d = Math.abs(s7.midi - t.midi); if (d < bd) { bd = d; best = t; } });
                if (best && bd <= 7) pairs.push({ from: s7, to: best, type: 'seventh-to-third' });
            });
            currentPitchMap.filter(p => THIRD[p.label]).forEach(t3 => {
                let best = null, bd = 99;
                [...nextPitchMap.filter(p => ROOT[p.label]), ...nextPitchMap.filter(p => SEVENTH[p.label])].forEach(tgt => { const d = Math.abs(t3.midi - tgt.midi); if (d < bd) { bd = d; best = tgt; } });
                if (best && bd <= 7) pairs.push({ from: t3, to: best, type: 'third-to-root' });
            });
            pairs.forEach(pair => {
                const { fret: tf, string: ts, label: tl } = pair.to;
                let sEl = tf === 0 ? mount.querySelector(`.sbn-fretboard-open-string[data-string="${ts}"]`) : (() => { const fe = mount.querySelector(`.sbn-fretboard-fret[data-fret="${tf}"]`); return fe ? fe.querySelector(`.sbn-fretboard-string[data-string="${ts}"]`) : null; })();
                if (!sEl) return;
                const ghost = document.createElement('div');
                ghost.className = `sbn-fretboard-ghost-dot ${pair.type}`; ghost.textContent = tl;
                sEl.appendChild(ghost);
                this._drawGuideToneArrow(mount, pair);
            });
        },

        _drawGuideToneArrow(mount, pair) {
            const gridEl = mount.querySelector('.sbn-fretboard-grid');
            if (!gridEl) return;
            const getEl = (si, fn) => fn === 0 ? gridEl.querySelector(`.sbn-fretboard-open-string[data-string="${si}"]`) : (() => { const fe = gridEl.querySelector(`.sbn-fretboard-fret[data-fret="${fn}"]`); return fe ? fe.querySelector(`.sbn-fretboard-string[data-string="${si}"]`) : null; })();
            const fromEl = getEl(pair.from.string, pair.from.fret), toEl = getEl(pair.to.string, pair.to.fret);
            if (!fromEl || !toEl) return;
            if (getComputedStyle(gridEl).position === 'static') gridEl.style.position = 'relative';
            let svg = gridEl.querySelector('.sbn-gt-arrows');
            if (!svg) { svg = document.createElementNS('http://www.w3.org/2000/svg','svg'); svg.setAttribute('class','sbn-gt-arrows'); gridEl.appendChild(svg); }
            const gR = gridEl.getBoundingClientRect(), fR = fromEl.getBoundingClientRect(), tR = toEl.getBoundingClientRect();
            svg.setAttribute('viewBox', `0 0 ${gR.width} ${gR.height}`);
            svg.style.cssText = 'position:absolute;top:0;left:0;width:100%;height:100%;pointer-events:none;z-index:10;overflow:visible;';
            const x1 = fR.left + fR.width/2 - gR.left, y1 = fR.top + fR.height/2 - gR.top;
            const x2 = tR.left + tR.width/2 - gR.left, y2 = tR.top + tR.height/2 - gR.top;
            const color = pair.type === 'seventh-to-third' ? '#f59e0b' : '#3b82f6';
            const angle = Math.atan2(y2-y1, x2-x1), hLen = 7;
            const line = document.createElementNS('http://www.w3.org/2000/svg','line');
            line.setAttribute('x1',x1); line.setAttribute('y1',y1); line.setAttribute('x2',x2); line.setAttribute('y2',y2);
            line.setAttribute('stroke',color); line.setAttribute('stroke-width','2'); line.setAttribute('stroke-dasharray','4 3'); line.setAttribute('opacity','0.7');
            svg.appendChild(line);
            const ax = x2 - hLen*Math.cos(angle-0.4), ay = y2 - hLen*Math.sin(angle-0.4);
            const bx = x2 - hLen*Math.cos(angle+0.4), by = y2 - hLen*Math.sin(angle+0.4);
            const arrow = document.createElementNS('http://www.w3.org/2000/svg','polygon');
            arrow.setAttribute('points',`${x2},${y2} ${ax},${ay} ${bx},${by}`); arrow.setAttribute('fill',color); arrow.setAttribute('opacity','0.7');
            svg.appendChild(arrow);
        },

        _parseFretString(fretStr, position) {
            const frets = [];
            (fretStr || '').split('').forEach((ch, i) => { frets[i] = (ch === 'x' || ch === 'X') ? 'x' : (isNaN(parseInt(ch,16)) ? 'x' : parseInt(ch,16)); });
            return frets;
        },

        _getRightHandFingers(frets) {
            const fingers = {}, played = [];
            frets.forEach((f, i) => { if (f !== 'x' && f !== undefined) played.push(i); });
            played.sort((a,b) => a-b);
            if (!played.length) return fingers;
            fingers[played[0]] = 'p';
            played.slice(1).slice(-3).forEach((si, i) => { fingers[si] = ['i','m','a'][i]; });
            return fingers;
        },

        _getVoicingPitchMap(voicing, chordName) {
            if (!voicing || !voicing.frets) return [];
            const OPEN_MIDI = [40,45,50,55,59,64];
            const NOTE_SEMI = {'C':0,'C#':1,'Db':1,'D':2,'D#':3,'Eb':3,'E':4,'Fb':4,'F':5,'E#':5,'F#':6,'Gb':6,'G':7,'G#':8,'Ab':8,'A':9,'A#':10,'Bb':10,'B':11,'Cb':11};
            const SEMI_TO_INT = {0:'R',1:'b9',2:'9',3:'b3',4:'3',5:'4',6:'b5',7:'5',8:'b13',9:'6',10:'b7',11:'maj7'};
            const position  = parseInt(voicing.position || voicing.start_fret) || 1;
            const frets     = this._parseFretString(voicing.frets || voicing.fret_string || '', position);
            const allLabels = (voicing.intervalLabels || '').split(',');
            const hasLabels = allLabels.some(l => l && l !== 'x' && l.trim() !== '');
            let rootSemi = null;
            if (!hasLabels && chordName) { const rm = chordName.match(/^([A-G][#b]?)/); if (rm) rootSemi = NOTE_SEMI[rm[1]]; }
            const result = [];
            for (let s = 0; s < 6; s++) {
                const f = frets[s];
                if (f === 'x' || f === undefined) continue;
                const fNum = parseInt(f, 10); if (isNaN(fNum)) continue;
                const midi = OPEN_MIDI[s] + fNum;
                let label = '';
                if (hasLabels) { const raw = (allLabels[s] || '').trim(); label = (raw === 'x' || raw === 'X') ? '' : raw; }
                else if (rootSemi !== null) label = SEMI_TO_INT[((midi % 12) - rootSemi + 12) % 12] || '';
                result.push({ midi, label, string: s, fret: fNum });
            }
            return result;
        },
    }));

}); // end alpine:init
</script>
@endpush
