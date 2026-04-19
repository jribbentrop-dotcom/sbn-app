{{-- ============================================================
   SBN Teaching Hub — Leadsheet Visual Editor (Phase 5b)
   resources/views/admin/leadsheets/edit.blade.php

   Layout (restructured pre-Phase 7):
   • Main area: tab bar (Chords / Analysis / Tab) + content only
   • Right panel: meta fields + toolbar + voicing picker
   • Bottom: description + shortcode
   ============================================================ --}}

@extends('layouts.admin')

@push('vite')
    @vite('resources/js/tab-editor/tab-editor.js')
@endpush

@section('title', $leadsheet ? 'Edit: ' . $leadsheet->title : 'New Leadsheet')

@section('actions')
    <a href="{{ route('admin.leadsheets.index') }}" class="sbn-btn sbn-btn-secondary">
        <svg width="16" height="16" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M9.707 16.707a1 1 0 01-1.414 0l-6-6a1 1 0 010-1.414l6-6a1 1 0 011.414 1.414L5.414 9H17a1 1 0 110 2H5.414l4.293 4.293a1 1 0 010 1.414z" clip-rule="evenodd"/></svg>
        Back
    </a>
@endsection

@push('styles')
<link rel="stylesheet" href="{{ asset('css/leadsheets.css') }}">
<link rel="stylesheet" href="{{ asset('css/sbn-context-menu.css') }}">
@endpush

@section('content')
<div x-data="leadsheetEditor()" x-cloak class="sbn-vp-layout">

    {{-- ═══════════════════════════════════════════════════════
         MAIN EDITOR COLUMN
    ═══════════════════════════════════════════════════════ --}}
    <div class="sbn-vp-editor-main">

        {{-- ── Upload zone (no data yet) ─────────────────── --}}
        <div x-show="!parsed" class="sbn-upload-zone"
             @click="$refs.fileInput.click()"
             @dragover.prevent="$el.classList.add('drag-over')"
             @dragleave.prevent="$el.classList.remove('drag-over')"
             @drop.prevent="$el.classList.remove('drag-over'); handleFileDrop($event)">
            <div class="sbn-upload-icon">🎸</div>
            <div class="sbn-upload-text">Import MusicXML file</div>
            <div class="sbn-upload-hint">Drop a .xml or .musicxml file here, or click to browse</div>
            <input type="file" x-ref="fileInput" accept=".xml,.musicxml" style="display:none"
                   @change="handleFileSelect($event)">
        </div>

        {{-- ── Visual editor (data loaded) ───────────────── --}}
        <template x-if="parsed">
        <div>

            {{-- ── Vue Mount Point (replaces Tab bar, Chords view, Tab view) ── --}}
            <div id="sbn-editor-content"></div>


            {{-- ══ ANALYSIS VIEW ════════════════════════════ --}}
            <div class="sbn-analysis-panel" x-show="alpineViewMode === 'analysis'" x-cloak>
                <div class="sbn-analysis-loading" x-show="analysisLoading">
                    🔍 Analysing harmonic structure…
                </div>

                <template x-if="analysisData && !analysisLoading">
                    <div>
                        <template x-for="(section, asi) in analysisData.sections" :key="asi">
                            <div class="sbn-analysis-section">
                                <div class="sbn-analysis-section-header"
                                     :class="{ 'is-collapsed': analysisCollapsed[asi] }">
                                    <button class="sbn-analysis-section-collapse"
                                            :class="{ 'is-collapsed': analysisCollapsed[asi] }"
                                            @click="analysisCollapsed[asi] = !analysisCollapsed[asi]">▼</button>
                                    <div class="sbn-analysis-section-id" x-text="section.section_id"></div>
                                    <span class="sbn-analysis-section-name" x-text="section.section_name"></span>
                                    <span class="sbn-analysis-section-key" x-text="'Key: ' + section.key"></span>
                                    <span class="sbn-analysis-section-key"
                                          x-text="Object.keys(section.measure_numerals).length + ' bars'"></span>
                                </div>

                                <div class="sbn-analysis-grid" x-show="!analysisCollapsed[asi]">
                                    <template x-for="(mChords, mi) in section.measure_numerals" :key="mi">
                                        <div class="sbn-analysis-measure"
                                             :class="{ 'is-highlighted': isMeasureInMatch(asi, parseInt(mi)) }">
                                            <div class="sbn-analysis-measure-num" x-text="parseInt(mi) + 1"></div>
                                            <div class="sbn-analysis-chord-row">
                                                <template x-for="(slot, ci) in mChords" :key="ci">
                                                    <div class="sbn-analysis-chord-slot">
                                                        <div class="sbn-analysis-chord-name" x-text="slot.chord"></div>
                                                        <div class="sbn-analysis-numeral"
                                                             :class="{ 'is-unknown': slot.numeral === '?' || slot.numeral.startsWith('chr') }"
                                                             x-text="formatNumeral(slot.numeral)"></div>
                                                    </div>
                                                </template>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </template>

                    </div>
                </template>
            </div>



            {{-- ── Bottom panels ─────────────────────────── --}}
            <div class="sbn-ve-bottom">
                <div class="sbn-ve-desc-panel">
                    <div class="sbn-ve-desc-label">Description / Notes</div>
                    <textarea x-model="description" @input="markDirty()"
                              placeholder="Song description, teaching notes…"></textarea>
                </div>

                <div class="sbn-ve-shortcode-panel">
                    <div class="sbn-ve-shortcode-header">
                        <span>Generated Shortcode</span>
                        <div style="display:flex;gap:6px;align-items:center;">
                            <label style="display:flex;align-items:center;gap:4px;font-size:11px;font-weight:400;color:var(--clr-text-muted);cursor:pointer">
                                <input type="checkbox" x-model="includeMelody" @change="markDirty()"
                                       style="accent-color:var(--clr-accent)">
                                Include melody
                            </label>
                            <button class="sbn-btn sbn-btn-xs" @click="copyShortcode()">Copy</button>
                        </div>
                    </div>
                    <textarea class="sbn-code-output" x-text="shortcodeOutput" readonly></textarea>
                </div>
            </div>

        </div>
        </template>



    </div> {{-- end .sbn-vp-editor-main --}}

    {{-- ═══════════════════════════════════════════════════════
         RIGHT PANEL (meta + toolbar + voicing picker)
    ═══════════════════════════════════════════════════════ --}}
    <aside class="sbn-vp-panel sbn-vp-desktop-only">

        {{-- ── Song meta ──────────────────────────────────── --}}
        <template x-if="parsed && !videoSidebarOpen">
        <div class="sbn-vp-meta">
            <div class="sbn-vp-meta-title-row">
                <input type="text" class="sbn-vp-meta-title"
                       x-model="parsed.title" placeholder="Song title" @input="markDirty()">
                <span class="sbn-vp-meta-by">by</span>
                <input type="text" class="sbn-vp-meta-composer"
                       x-model="parsed.composer" placeholder="Composer" @input="markDirty()">
            </div>
            <div class="sbn-vp-meta-fields">
                <div class="sbn-vp-meta-field">
                    <span class="sbn-vp-meta-label">Key</span>
                    <input type="text" class="sbn-vp-meta-input"
                           x-model="parsed.key" @input="markDirty()">
                </div>
                <div class="sbn-vp-meta-field">
                    <span class="sbn-vp-meta-label">Tempo</span>
                    <input type="number" class="sbn-vp-meta-input"
                           x-model.number="parsed.tempo" min="20" max="300" @input="markDirty()">
                </div>
                <div class="sbn-vp-meta-field">
                    <span class="sbn-vp-meta-label">Time</span>
                    <input type="text" class="sbn-vp-meta-input"
                           x-model="parsed.timeSignature" @input="markDirty()">
                </div>
                <div class="sbn-vp-meta-field">
                    <span class="sbn-vp-meta-label">Bars/row</span>
                    <select class="sbn-vp-meta-input" x-model.number="barsPerRow"
                            @change="markDirty()"
                            style="font-family:var(--font-body);font-weight:600;">
                        <option value="2">2</option>
                        <option value="3">3</option>
                        <option value="4">4</option>
                        <option value="5">5</option>
                        <option value="6">6</option>
                        <option value="8">8</option>
                    </select>
                </div>
                <div class="sbn-vp-meta-field sbn-vp-meta-rhythm">
                    <span class="sbn-vp-meta-label">Rhythm</span>
                    <select class="sbn-vp-meta-select" x-model="rhythmSlug" @change="markDirty()">
                        <option value="">None</option>
                        @foreach($rhythms->groupBy('category') as $cat => $group)
                            <optgroup label="{{ $cat ?: 'Other' }}">
                                @foreach($group as $r)
                                    <option value="{{ $r->slug }}">{{ $r->name }}</option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                </div>
            </div>
        </div>
        </template>

        {{-- ── Toolbar ─────────────────────────────────────── --}}
        <div class="sbn-vp-toolbar" x-show="parsed && !videoSidebarOpen">
            {{-- Row 1: stats --}}
            <div class="sbn-vp-toolbar-row">
                <span class="sbn-vp-stats" x-text="statsText"></span>
            </div>
            {{-- Row 2: save --}}
            <div class="sbn-vp-save-row">
                <button class="sbn-btn sbn-btn-primary" style="flex:1" @click="save()" :disabled="saving"
                        x-text="saving ? 'Saving…' : (leadsheetId ? 'Update Leadsheet' : 'Save Leadsheet')"></button>
                <span class="sbn-vp-dirty-hint" x-show="dirty">Unsaved</span>
            </div>
        </div>

        {{-- ── Voicing picker / overview — rendered by Vue via Teleport into #sbn-vp-slot ── --}}
        <div id="sbn-vp-slot" x-show="!videoSidebarOpen"></div>
        {{-- Alpine .sbn-vp-context removed in Phase B Step 10b — Vue VoicingPicker.vue owns this panel --}}

        {{-- ── Video sync panel — rendered by Vue via Teleport into #sbn-video-slot ──────── --}}
        {{-- Phase D: sibling to #sbn-vp-slot; visibility toggled independently of viewMode --}}
        <div id="sbn-video-slot"
             x-show="videoSidebarOpen"
             style="display:none;flex-direction:column;flex:1;min-height:0;overflow:auto;border-top:1px solid var(--clr-border);margin-top:8px;padding-top:8px;">
        </div>

        {{-- ── Analysis sidebar — ANALYSIS view only ──────── --}}
        <template x-if="alpineViewMode === 'analysis' && !videoSidebarOpen">
        <div style="display:flex;flex-direction:column;flex:1;min-height:0;overflow:hidden;">

            {{-- Progression matches per section --}}
            <div class="sbn-sidebar-analysis">
                <div class="sbn-sidebar-analysis-header">
                    <span class="sbn-sidebar-analysis-title">Detected Progressions</span>
                    <template x-if="analysisData">
                        <span class="sbn-sidebar-analysis-key" x-text="analysisData.song_key"></span>
                    </template>
                </div>

                <template x-if="analysisData && !analysisLoading">
                    <div>
                        <template x-for="(section, asi) in analysisData.sections" :key="asi">
                            <div class="sbn-sidebar-section">
                                <div class="sbn-sidebar-section-label">
                                    <div class="sbn-sidebar-section-id" x-text="section.section_id"></div>
                                    <span class="sbn-sidebar-section-name" x-text="section.section_name"></span>
                                </div>
                                <div class="sbn-sidebar-matches" x-show="section.matches && section.matches.length">
                                    <template x-for="(match, mti) in section.matches" :key="mti">
                                        <a class="sbn-sidebar-match"
                                           :href="match.progression_id ? '{{ route('admin.progressions.edit', ['progression' => '__ID__']) }}'.replace('__ID__', match.progression_id) : '#'"
                                           :target="match.progression_id ? '_blank' : null"
                                           @mouseenter="setMatchHighlight(asi, match)"
                                           @mouseleave="highlightMatch = null">
                                            <span class="sbn-analysis-match-cat"
                                                  :class="'cat-' + match.category"
                                                  x-text="match.category"></span>
                                            <span class="sbn-analysis-match-name" x-text="match.name"></span>
                                            <span class="sbn-analysis-match-measures"
                                                  x-text="'m' + (match.start_measure + 1) + (match.end_measure > match.start_measure ? '–' + (match.end_measure + 1) : '')"></span>
                                            <span class="sbn-analysis-match-confidence"
                                                  x-text="Math.round(match.confidence * 100) + '%'"></span>
                                        </a>
                                    </template>
                                </div>
                                <div class="sbn-sidebar-no-matches"
                                     x-show="!section.matches || !section.matches.length">
                                    No progressions detected.
                                </div>
                            </div>
                        </template>
                    </div>
                </template>

                <div x-show="analysisLoading"
                     style="font-size:12px;color:var(--clr-text-muted);padding:12px 0;">
                    Analysing…
                </div>
                <div x-show="!analysisData && !analysisLoading"
                     style="font-size:12px;color:var(--clr-text-muted);padding:12px 0;font-style:italic;">
                    Switch to the Analysis tab to run detection.
                </div>
            </div>

            {{-- Detect & Store button pinned to bottom --}}
            <div class="sbn-sidebar-detect-row">
                <button class="sbn-btn sbn-btn-primary sbn-btn-sm" style="flex:1"
                        @click="runDetection()"
                        :disabled="detecting"
                        x-text="detecting ? 'Detecting…' : '💾 Detect & Store'"></button>
                <span x-show="detectionResult"
                      style="font-size:11px;color:var(--clr-text-muted);"
                      x-text="detectionResult"></span>
            </div>

        </div>
        </template>
{{-- ── Tab sidebar — TAB view only ─────────────────── --}}
<div id="sbn-tab-sidebar"
     x-show="alpineViewMode === 'tab' && !videoSidebarOpen"
     x-cloak
     style="display:flex;flex-direction:column;flex:1;min-height:0;overflow:hidden;">
</div>
    
    </aside>

</div>
@endsection
@push('scripts')
<script>window.__sbnRhythmPatterns = @json($rhythmPatterns);</script>
{{-- Chord diagram renderer --}}
<script src="{{ asset('js/chords.js') }}"></script>
{{-- Chord name formatter (carried from Phase 4d) --}}
<script src="{{ asset('js/sbn-chord-name.js') }}"></script>
{{-- Context menu singleton + operation vocabulary (grid-interact Phase 1) --}}
<script src="{{ asset('js/sbn-context-menu.js') }}"></script>
<script src="{{ asset('js/sbn-grid-ops.js') }}"></script>

<script>
// =============================================================================
// MUSICXML PARSER — carried over verbatim from WP leadsheet-admin.js
// (Pure vanilla JS, no jQuery, no DOM manipulation)
// =============================================================================

class MusicXMLParser {
    constructor(xmlString) {
        const parser = new DOMParser();
        this.doc = parser.parseFromString(xmlString, 'text/xml');
        this.divisions = 1;
    }

    parse() {
        const result = {
            title: this.getTitle(),
            composer: this.getComposer(),
            tempo: this.getTempo(),
            timeSignature: this.getTimeSignature(),
            key: this.getKey(),
            measures: [],
            chordVoicings: {},
            melody: []
        };

        const measures = this.doc.querySelectorAll('measure');
        const ticksPerBeat = 480;
        let lastChord = null;
        const timeSig = result.timeSignature.split('/');
        const beatsPerMeasure = parseInt(timeSig[0]) || 4;
        const beatType = parseInt(timeSig[1]) || 4;
        const ticksPerBeatUnit = ticksPerBeat * (4 / beatType);
        const ticksPerMeasure = ticksPerBeatUnit * beatsPerMeasure;

        const tabExtracted = this.extractVoicingsFromTab();
        let tabMeasureMap = {};
        if (tabExtracted) {
            tabExtracted.measures.forEach(m => { tabMeasureMap[m.measureNumber - 1] = m; });
            Object.assign(result.chordVoicings, tabExtracted.chordVoicings);
        }

        measures.forEach((measure, idx) => {
            const measureData = this.parseMeasure(measure, idx);
            const directions = measure.querySelectorAll('direction');
            let rehearsalMark = null;
            directions.forEach(dir => {
                const rehearsal = dir.querySelector('direction-type rehearsal');
                if (rehearsal) rehearsalMark = rehearsal.textContent.trim();
            });

            const barlines = measure.querySelectorAll('barline');
            let hasRepeatBackward = false, hasRepeatForward = false;
            barlines.forEach(barline => {
                const repeat = barline.querySelector('repeat');
                if (repeat) {
                    const direction = repeat.getAttribute('direction');
                    if (direction === 'backward') hasRepeatBackward = true;
                    if (direction === 'forward') hasRepeatForward = true;
                }
                const ending = barline.querySelector('ending');
                if (ending) {
                    const endingNumber = ending.getAttribute('number');
                    const endingType = ending.getAttribute('type');
                    const endingText = ending.textContent || `${endingNumber}.`;
                    if (!result.voltaEndings) result.voltaEndings = {};
                    if (!result._pendingVoltaList) result._pendingVoltaList = [];
                    result._pendingVoltaList.push({ number: parseInt(endingNumber), type: endingType, text: endingText });
                }
            });

            const pushMeasure = (md) => {
                md.repeatStart = hasRepeatForward;
                md.repeatEnd = hasRepeatBackward;
                md.rehearsalMark = rehearsalMark;
                if (result._pendingVoltaList && result._pendingVoltaList.length > 0) {
                    if (!result.voltaEndings) result.voltaEndings = {};
                    const pushedIdx = result.measures.length;
                    result._pendingVoltaList.forEach(v => {
                        const key = result.voltaEndings[pushedIdx] ? pushedIdx + '_' + v.type : pushedIdx;
                        result.voltaEndings[key] = v;
                    });
                    result._pendingVoltaList = [];
                }
                result.measures.push(md);
                md.chords.forEach((chord) => {
                    if (chord.voicing && !result.chordVoicings[chord.name]) {
                        result.chordVoicings[chord.name] = chord.voicing;
                    }
                });
                if (md.chords.length) lastChord = md.chords[md.chords.length - 1];
            };

            if (measureData.chords.length > 0) {
                pushMeasure(measureData);
            } else if (tabMeasureMap[idx]) {
                const tabMeasure = tabMeasureMap[idx];
                tabMeasure._fromTab = true;
                pushMeasure(tabMeasure);
            } else if (lastChord) {
                pushMeasure({
                    chords: [{ name: lastChord.name, beats: lastChord.beats, voicing: lastChord.voicing }],
                    notes: measureData.notes || [], measureNumber: idx + 1
                });
            } else if (measureData.notes && measureData.notes.length > 0) {
                pushMeasure({ chords: [{ name: '?', beats: beatsPerMeasure }], notes: measureData.notes, measureNumber: idx + 1 });
            } else if (tabExtracted) {
                pushMeasure({ chords: [{ name: '—', beats: beatsPerMeasure }], notes: [], measureNumber: idx + 1 });
            }

            if (hasRepeatForward) {
                if (!result.repeatMarkers) result.repeatMarkers = [];
                result.repeatMarkers.push({ type: 'start', measureIndex: result.measures.length - 1 });
            }
            if (hasRepeatBackward) {
                if (!result.repeatMarkers) result.repeatMarkers = [];
                result.repeatMarkers.push({ type: 'end', measureIndex: result.measures.length - 1 });
            }
        });

        // Group into sections
        result.sections = [];
        let currentSection = null;
        const sectionLetters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        let sectionCounter = 0;
        result.measures.forEach((measure, idx) => {
            if (measure.rehearsalMark || idx === 0) {
                if (currentSection) result.sections.push(currentSection);
                const mark = measure.rehearsalMark || '';
                const isSingleLetter = /^[A-Z]$/i.test(mark);
                const sectionId = isSingleLetter ? mark.toUpperCase() : sectionLetters[sectionCounter] || ('S' + sectionCounter);
                currentSection = { id: sectionId, name: isSingleLetter ? sectionId : (mark || sectionId), measures: [], rhythmSlug: null };
                sectionCounter++;
            }
            if (currentSection) currentSection.measures.push(measure);
        });
        if (currentSection && currentSection.measures.length > 0) result.sections.push(currentSection);

        // Per-occurrence voicing overrides
        let globalMeasureIdx = 0;
        result.sections.forEach(section => {
            section.measures.forEach(measure => {
                measure.chords.forEach((chord, ci) => {
                    if (!chord.voicing) return;
                    const defaultV = result.chordVoicings[chord.name];
                    if (!defaultV) return;
                    if (chord.voicing.frets !== defaultV.frets || chord.voicing.position !== defaultV.position) {
                        result.chordVoicings[chord.name + '@' + globalMeasureIdx + '.' + ci] = chord.voicing;
                    }
                });
                globalMeasureIdx++;
            });
        });

        // Build melody
        const allNotes = [];
        result.measures.forEach((md, measureIdx) => {
            const measureStartTick = measureIdx * ticksPerMeasure;
            if (md.notes && md.notes.length > 0) {
                md.notes.forEach(note => {
                    const ticks = this.durationToTicks(note.durationName, ticksPerBeat);
                    const absoluteTick = measureStartTick + (note.measureTick * ticksPerBeat);
                    allNotes.push({ ...note, tick: absoluteTick, ticks: ticks, measureIdx: measureIdx });
                });
            }
        });
        for (let i = 0; i < allNotes.length; i++) {
            const note = allNotes[i];
            if (note.isRest) {
                result.melody.push({
                    tick: note.tick, duration: note.durationName, ticks: note.ticks, voice: note.voice || 1, isRest: true,
                    tupletActual: note.tupletActual || null, tupletNormal: note.tupletNormal || null,
                    tupletType: note.tupletType || null, tupletBracket: note.tupletBracket || false,
                });
                continue;
            }
            result.melody.push({
                tick: note.tick, pitch: note.pitch, octave: note.octave, duration: note.durationName,
                ticks: note.ticks, tieStart: note.tieStart, tieStop: note.tieStop, voice: note.voice || 1,
                string: note.string, fret: note.fret, isChordNote: note.isChordNote || false, isRest: false,
                beam1: note.beam1 || null, beam2: note.beam2 || null,
                tupletActual: note.tupletActual || null, tupletNormal: note.tupletNormal || null,
                tupletType: note.tupletType || null, tupletBracket: note.tupletBracket || false,
            });
        }

        return result;
    }

    durationToTicks(durName, ticksPerBeat) {
        const isDotted = durName && durName.endsWith('d');
        const baseDur = isDotted ? durName.slice(0, -1) : durName;
        const durMap = { 'w': ticksPerBeat*4, 'h': ticksPerBeat*2, 'q': ticksPerBeat, 'e': ticksPerBeat/2, 's': ticksPerBeat/4, 't': ticksPerBeat/8 };
        let ticks = durMap[baseDur] || ticksPerBeat;
        if (isDotted) ticks *= 1.5;
        return ticks;
    }

    getTitle() { const t = this.doc.querySelector('work-title'); return t ? t.textContent.trim() : 'Untitled'; }
    getComposer() { const c = this.doc.querySelector('creator[type="composer"]'); return c ? c.textContent.trim() : ''; }
    getTempo() { const t = this.doc.querySelector('per-minute'); return t ? parseInt(t.textContent) : 120; }
    getTimeSignature() {
        const b = this.doc.querySelector('beats'), bt = this.doc.querySelector('beat-type');
        return (b && bt) ? b.textContent + '/' + bt.textContent : '4/4';
    }
    getKey() {
        const fifths = this.doc.querySelector('fifths');
        if (!fifths) return 'C';
        const mode = this.doc.querySelector('mode');
        const isMinor = mode && mode.textContent.trim().toLowerCase() === 'minor';
        const keys = ['C','G','D','A','E','B','F#','C#'], keysFlat = ['C','F','Bb','Eb','Ab','Db','Gb','Cb'];
        const minorKeys = ['Am','Em','Bm','F#m','C#m','G#m','D#m','A#m'], minorKeysFlat = ['Am','Dm','Gm','Cm','Fm','Bbm','Ebm','Abm'];
        const val = parseInt(fifths.textContent);
        if (isMinor) return val >= 0 ? (minorKeys[val] || 'Am') : (minorKeysFlat[Math.abs(val)] || 'Am');
        return val >= 0 ? (keys[val] || 'C') : (keysFlat[Math.abs(val)] || 'C');
    }

    parseMeasure(measure, measureIndex) {
        const divisions = measure.querySelector('divisions');
        if (divisions) this.divisions = parseInt(divisions.textContent);
        const beatsEl = measure.querySelector('beats');
        const beatsPerMeasure = beatsEl ? parseInt(beatsEl.textContent) : 4;
        const totalDivs = this.divisions * beatsPerMeasure; // total divisions in the measure

        if (measure.querySelectorAll('harmony').length === 0 && measure.querySelectorAll('note').length === 0) {
            return { chords: [], notes: [], measureNumber: measureIndex + 1 };
        }

        // Walk children in document order to assign each <harmony> an exact tick position.
        // MusicXML spec: a <harmony> with no <offset> sits at the current note-cursor position.
        // An <offset> child (in divisions, can be negative) shifts it relative to that cursor.
        const chords = [];
        let cursorDivs = 0;  // note cursor in divisions
        let lastNoteDivs = 0; // tracks last non-chord note start (for <chord/> notes)
        const children = measure.children;
        for (let i = 0; i < children.length; i++) {
            const el = children[i];
            const tag = el.tagName.toLowerCase();
            if (tag === 'attributes') {
                const d = el.querySelector('divisions');
                if (d) this.divisions = parseInt(d.textContent);
                continue;
            }
            if (tag === 'backup') {
                const d = el.querySelector('duration');
                if (d) cursorDivs = Math.max(0, cursorDivs - parseInt(d.textContent));
                continue;
            }
            if (tag === 'forward') {
                const d = el.querySelector('duration');
                if (d) cursorDivs += parseInt(d.textContent);
                continue;
            }
            if (tag === 'harmony') {
                const offsetEl = el.querySelector('offset');
                const tickDivs = offsetEl
                    ? Math.max(0, cursorDivs + parseInt(offsetEl.textContent))
                    : cursorDivs;
                const beatInMeasure = tickDivs / this.divisions; // quarter beats from measure start
                const chord = this.parseHarmony(el);
                chord.beatInMeasure = beatInMeasure;
                chords.push(chord);
                continue;
            }
            if (tag === 'note') {
                const isChordNote = !!el.querySelector('chord');
                const dur = el.querySelector('duration');
                const d = dur ? parseInt(dur.textContent) : this.divisions;
                if (!isChordNote) {
                    lastNoteDivs = cursorDivs;
                    cursorDivs += d;
                }
                // chord notes don't advance the cursor
            }
        }

        // Derive each chord's duration (in quarter beats) from successive start positions.
        // The last chord spans to the end of the measure.
        if (chords.length > 0) {
            const divsPerBeat = this.divisions;
            for (let i = 0; i < chords.length; i++) {
                const startBeat = chords[i].beatInMeasure;
                const endBeat = i + 1 < chords.length
                    ? chords[i + 1].beatInMeasure
                    : beatsPerMeasure;
                chords[i].beats = Math.max(0.25, endBeat - startBeat);
            }
        }

        const notes = this.parseNotes(measure);
        return { chords, notes, measureNumber: measureIndex + 1 };
    }

    parseNotes(measure) {
        const notes = [];
        let currentTick = 0, lastNoteTick = 0;
        const children = measure.children;
        for (let i = 0; i < children.length; i++) {
            const el = children[i], tag = el.tagName.toLowerCase();
            if (tag === 'backup') { const d = el.querySelector('duration'); if (d) { currentTick -= parseInt(d.textContent)/this.divisions; if (currentTick<0) currentTick=0; } continue; }
            if (tag === 'forward') { const d = el.querySelector('duration'); if (d) currentTick += parseInt(d.textContent)/this.divisions; continue; }
            if (tag !== 'note') continue;
            const voiceEl = el.querySelector('voice'), voice = voiceEl ? parseInt(voiceEl.textContent) : 1;
            const duration = this.parseDuration(el), durationName = this.getDurationName(el);
            const isChordNote = !!el.querySelector('chord');
            let noteTick;
            if (isChordNote) { noteTick = lastNoteTick; } else { noteTick = currentTick; lastNoteTick = currentTick; currentTick += duration; }
            if (el.querySelector('rest')) {
                const timeMod = el.querySelector('time-modification');
                let rTupletActual = null, rTupletNormal = null;
                if (timeMod) {
                    const an = timeMod.querySelector('actual-notes'), nn = timeMod.querySelector('normal-notes');
                    if (an) rTupletActual = parseInt(an.textContent);
                    if (nn) rTupletNormal = parseInt(nn.textContent);
                }
                const rTupletEl = el.querySelector('notations tuplet');
                const rTupletType    = rTupletEl ? rTupletEl.getAttribute('type') : null;
                const rTupletBracket = rTupletEl ? rTupletEl.getAttribute('bracket') !== 'no' : false;
                notes.push({ isRest: true, duration, durationName, voice, measureTick: noteTick, tupletActual: rTupletActual, tupletNormal: rTupletNormal, tupletType: rTupletType, tupletBracket: rTupletBracket });
                continue;
            }
            const pitch = el.querySelector('pitch'); if (!pitch) continue;
            const step = pitch.querySelector('step'), octave = pitch.querySelector('octave'), alter = pitch.querySelector('alter');
            if (!step || !octave) continue;
            let noteName = step.textContent;
            if (alter) { const a = parseInt(alter.textContent); if (a===1) noteName+='#'; else if (a===-1) noteName+='b'; }
            const tieStart = el.querySelector('tie[type="start"]'), tieStop = el.querySelector('tie[type="stop"]');
            const technical = el.querySelector('notations technical');
            let tabString = null, tabFret = null;
            if (technical) { const s=technical.querySelector('string'),f=technical.querySelector('fret'); if(s)tabString=parseInt(s.textContent); if(f)tabFret=parseInt(f.textContent); }
            // Read MusicXML beam tags — beam number="1" is primary beam, number="2" is secondary (16ths), etc.
            const beamEls = el.querySelectorAll('beam');
            let beam1 = null, beam2 = null;
            beamEls.forEach(b => {
                const num = parseInt(b.getAttribute('number') || '1');
                if (num === 1) beam1 = b.textContent.trim();
                if (num === 2) beam2 = b.textContent.trim();
            });
            // Read tuplet info for triplet "3" label rendering
            const timeMod = el.querySelector('time-modification');
            let tupletActual = null, tupletNormal = null;
            if (timeMod) {
                const an = timeMod.querySelector('actual-notes'), nn = timeMod.querySelector('normal-notes');
                if (an) tupletActual = parseInt(an.textContent);
                if (nn) tupletNormal = parseInt(nn.textContent);
            }
            const tupletEl = el.querySelector('notations tuplet');
            const tupletType    = tupletEl ? tupletEl.getAttribute('type') : null; // 'start' | 'stop' | null
            const tupletBracket = tupletEl ? tupletEl.getAttribute('bracket') !== 'no' : false; // default bracket=yes when attribute absent
            notes.push({ pitch: noteName, octave: parseInt(octave.textContent), duration, durationName, tieStart: !!tieStart, tieStop: !!tieStop, isRest: false, isChordNote, voice, measureTick: noteTick, string: tabString, fret: tabFret, beam1, beam2, tupletActual, tupletNormal, tupletType, tupletBracket });
        }
        return notes;
    }

    parseDuration(noteEl) { const d = noteEl.querySelector('duration'); return d ? parseInt(d.textContent)/this.divisions : 1; }
    getDurationName(noteEl) {
        const t = noteEl.querySelector('type'); if (!t) return 'q';
        const typeMap = {'whole':'w','half':'h','quarter':'q','eighth':'e','16th':'s','32nd':'t','64th':'x'};
        let dur = typeMap[t.textContent] || 'q';
        if (noteEl.querySelector('dot')) dur += 'd';
        return dur;
    }

    parseHarmony(harmony) {
        const rootStep = harmony.querySelector('root-step'), rootAlter = harmony.querySelector('root-alter');
        const kind = harmony.querySelector('kind'), bassStep = harmony.querySelector('bass-step'), bassAlter = harmony.querySelector('bass-alter');
        let chordName = rootStep ? rootStep.textContent : 'C';
        if (rootAlter) { const a = parseInt(rootAlter.textContent); if(a===1)chordName+='#'; else if(a===-1)chordName+='b'; }
        if (kind) {
            const kindValue = kind.textContent || '';
            const kindValueMap = {'major':'','minor':'m','augmented':'aug','diminished':'dim','dominant':'7','major-seventh':'Maj7','minor-seventh':'m7','diminished-seventh':'°7','augmented-seventh':'aug7','half-diminished':'m7b5','major-minor':'mMaj7','major-sixth':'6','minor-sixth':'m6','dominant-ninth':'9','major-ninth':'Maj9','minor-ninth':'m9','dominant-11th':'11','major-11th':'Maj11','minor-11th':'m11','dominant-13th':'13','major-13th':'Maj13','minor-13th':'m13','suspended-second':'sus2','suspended-fourth':'sus4','power':'5'};
            const kindText = kind.getAttribute('text') || '';
            if (kindValue && kindValueMap.hasOwnProperty(kindValue)) chordName += kindValueMap[kindValue];
            else if (kindText) chordName += kindText;
        }
        const degrees = harmony.querySelectorAll('degree');
        if (degrees.length > 0) {
            const extensions = [];
            degrees.forEach(degree => {
                const dv = degree.querySelector('degree-value'), da = degree.querySelector('degree-alter'), dt = degree.querySelector('degree-type');
                if (dv) { let ext=''; const val=dv.textContent, alt=da?parseInt(da.textContent):0, type=dt?dt.textContent:'add'; if(alt===-1)ext+='b'; else if(alt===1)ext+='#'; ext+=val; if(type==='subtract')ext='no'+val; extensions.push(ext); }
            });
            if (extensions.length > 0) { const useP = kind && kind.getAttribute('parentheses-degrees')==='yes'; chordName += useP ? '('+extensions.join(',')+')' : extensions.join(''); }
        }
        if (bassStep) { let bass=bassStep.textContent; if(bassAlter){const a=parseInt(bassAlter.textContent);if(a===1)bass+='#';else if(a===-1)bass+='b';} chordName+='/'+bass; }
        const frame = harmony.querySelector('frame');
        let voicing = null;
        if (frame) voicing = this.parseFrame(frame);
        return { name: chordName, voicing };
    }

    parseFrame(frame) {
        const fs = frame.querySelector('frame-strings'), numStrings = fs ? parseInt(fs.textContent) : 6;
        const ff = frame.querySelector('first-fret'), position = ff ? parseInt(ff.textContent) : 1;
        const frets = Array(numStrings).fill('x'), fingers = Array(numStrings).fill('0');
        frame.querySelectorAll('frame-note').forEach(note => {
            const s=note.querySelector('string'),f=note.querySelector('fret'),fg=note.querySelector('fingering');
            if(s&&f){const sn=parseInt(s.textContent),fn=parseInt(f.textContent),idx=numStrings-sn; frets[idx]=fn===0?'0':(fn<=9?fn.toString():fn.toString(16)); if(fg)fingers[idx]=fg.textContent;}
        });
        return { frets: frets.join(''), fingers: fingers.join(''), position };
    }

    extractVoicingsFromTab() {
        const measures = this.doc.querySelectorAll('measure');
        if (!measures.length) return null;
        const hasTab = this.doc.querySelector('notations technical string') && this.doc.querySelector('notations technical fret');
        if (!hasTab) return null;
        const harmonyCount = this.doc.querySelectorAll('harmony').length;
        const measuresWithHarmony = new Set();
        measures.forEach((m,i)=>{if(m.querySelectorAll('harmony').length>0)measuresWithHarmony.add(i);});
        if (measuresWithHarmony.size > measures.length * 0.5) return null;
        const initialDivs = this.doc.querySelector('divisions');
        if (initialDivs) this.divisions = parseInt(initialDivs.textContent);
        let eighthTicks = this.divisions/2, arpeggioWindow = eighthTicks*2;
        const timeSig = this.getTimeSignature().split('/');
        const beatsPerMeasure = parseInt(timeSig[0])||4;
        const allMeasureVoicings = [];
        const halfMeasureTicks = this.divisions * beatsPerMeasure / 2;
        measures.forEach((measure,mIdx)=>{
            const divEl=measure.querySelector('divisions'); if(divEl){this.divisions=parseInt(divEl.textContent);eighthTicks=this.divisions/2;arpeggioWindow=eighthTicks*2;}
            if(measuresWithHarmony.has(mIdx)){allMeasureVoicings.push([]);return;}
            const noteEvents=this._collectTabNotes(measure);
            if(!noteEvents.length){allMeasureVoicings.push([]);return;}
            const byTick={};noteEvents.forEach(n=>{if(!byTick[n.tick])byTick[n.tick]=[];byTick[n.tick].push(n);});
            const ticks=Object.keys(byTick).map(Number).sort((a,b)=>a-b);
            const blockChords=[],blockTicks=new Set();
            ticks.forEach(t=>{if(byTick[t].length>=3){blockChords.push({tick:t,notes:byTick[t]});blockTicks.add(t);}});
            const gripChords=[],gripClaimedTicks=new Set([...blockTicks]);
            let ni=0;
            while(ni<noteEvents.length){
                const startNote=noteEvents[ni];
                if(gripClaimedTicks.has(startNote.tick)){ni++;continue;}
                const grip=[startNote],stringsUsed=new Set([startNote.string]);
                for(let nj=ni+1;nj<Math.min(ni+6,noteEvents.length);nj++){
                    const c=noteEvents[nj];if(gripClaimedTicks.has(c.tick))break;if(stringsUsed.has(c.string))break;
                    if(c.tick-startNote.tick>=halfMeasureTicks)break;
                    const pM=this._stringFretToMidi(grip[grip.length-1].string,grip[grip.length-1].fret);
                    const cM=this._stringFretToMidi(c.string,c.fret);if(Math.abs(cM-pM)<=2)break;
                    grip.push(c);stringsUsed.add(c.string);
                }
                if(grip.length>=3){
                    const tc={};let hasBass=false;grip.forEach(n=>{tc[n.tick]=(tc[n.tick]||0)+1;if(n.string>=5)hasBass=true;});
                    if(Math.max(...Object.values(tc))>=3||hasBass){gripChords.push({tick:grip[0].tick,notes:grip,isGrip:true});grip.forEach(n=>gripClaimedTicks.add(n.tick));}
                    ni+=grip.length;
                }else ni++;
            }
            const arpeggioChords=[];
            ticks.forEach((t,ti)=>{
                if(gripClaimedTicks.has(t))return;const notes=byTick[t];const bassNotes=notes.filter(n=>n.string>=4);if(!bassNotes.length)return;if(notes.length>=3)return;
                const upperNotes=[];for(let j=ti+1;j<ticks.length;j++){const t2=ticks[j];if(t2-t>arpeggioWindow)break;if(gripClaimedTicks.has(t2))break;byTick[t2].forEach(n=>{if(n.string<=3)upperNotes.push(n);});}
                if(upperNotes.length>=2)arpeggioChords.push({tick:t,notes:[...bassNotes,...upperNotes],isArpeggio:true});
            });
            blockChords.forEach(bc=>{
                const hasBass=bc.notes.some(n=>n.string>=5);if(hasBass)return;
                for(let ti=ticks.indexOf(bc.tick)-1;ti>=0;ti--){const t2=ticks[ti];if(bc.tick-t2>arpeggioWindow)break;if(blockTicks.has(t2))break;const n2=byTick[t2];if(n2.length>2)break;const bh=n2.filter(n=>n.string>=4);if(!bh.length)continue;bh.forEach(bn=>{if(!bc.notes.some(n=>n.string===bn.string))bc.notes.push(bn);});break;}
            });
            let combined=[...blockChords,...gripChords,...arpeggioChords].sort((a,b)=>a.tick-b.tick);
            const measureVoicings=combined.map(group=>{
                const v=this._notesToVoicing(group.notes);
                // A chord group is a tie-start if ALL its non-bass notes (strings 1-4)
                // have tieStart=true — meaning the whole voicing is tied into the next bar.
                // Bass note (string 5-6) may or may not participate in the tie.
                const nonBassNotes=group.notes.filter(n=>n.string<=4&&n.tieStart!==undefined);
                const isTieStart=nonBassNotes.length>0&&nonBassNotes.every(n=>n.tieStart);
                // A chord group is an incoming tie-stop if ALL its non-bass notes are
                // tie-stops. The bass may be a fresh walking-bass note arriving at the
                // same tick — that's fine, but the upper voicing is unchanged and should
                // not generate a new chord symbol.
                const nonBassForStop=group.notes.filter(n=>n.string<=4&&n.tieStop!==undefined);
                const isTieStop=nonBassForStop.length>0&&nonBassForStop.every(n=>n.tieStop);
                return{tick:group.tick,beat:group.tick/this.divisions+1,voicing:v,noteCount:group.notes.length,isArpeggio:!!group.isArpeggio,isTieStart,isTieStop};
            });
            // Dedup: same frets anywhere within the measure = skip (not just half-measure).
            // Also propagate tieStart flag from the chord group to the voicing event.
            const deduped=[];
            measureVoicings.forEach(mv=>{
                const last=deduped.length?deduped[deduped.length-1]:null;
                // Same frets already seen in this measure — skip entirely
                if(last&&last.voicing.frets===mv.voicing.frets)return;
                // Upper-string match with better bass: upgrade existing entry
                if(last){const lU=last.voicing.frets.substring(3),cU=mv.voicing.frets.substring(3);if(lU===cU&&lU!=='xxx'){const lB=last.voicing.frets.substring(0,3).replace(/x/g,'').length,cB=mv.voicing.frets.substring(0,3).replace(/x/g,'').length;if(cB>lB)deduped[deduped.length-1]=mv;return;}}
                // Mark as tieStart if all upper notes (strings 1-4) are tie-starts
                // — meaning this chord is tied into the next measure
                mv.tieStart = mv.isTieStart || false;
                mv.tieStop  = mv.isTieStop  || false;
                // Suppress incoming tie-stop groups — upper voicing unchanged from previous bar.
                if(mv.tieStop) return;
                // Suppress late-beat tie-start chords: if a chord is tied into the next
                // bar AND falls in the last quarter of the measure (beat 4e/4+/4a equivalent),
                // it shouldn't generate a chord symbol — it's a pickup into the next bar.
                const measureTotalTicks = this.divisions * beatsPerMeasure;
                if(mv.tieStart && mv.tick >= measureTotalTicks * 0.75) return;
                deduped.push(mv);
            });
            allMeasureVoicings.push(deduped);
        });
        const shapeToName={};let shapeCounter=1;const resultMeasures=[],resultVoicings={};

        // Pre-pass: for each measure, determine if the measure ends on a tie-start
        // and what frets that tied chord has, so we can suppress the tie-stop at
        // the start of the next measure.
        const measureEndTiedFrets = allMeasureVoicings.map(mv => {
            if (!mv.length) return null;
            const last = mv[mv.length - 1];
            // If the last chord of this measure is a tie-start, return its frets
            // so the next measure can suppress the tie-stop chord.
            return last.tieStart ? last.voicing.frets : null;
        });

        allMeasureVoicings.forEach((mv,mIdx)=>{
            if(!mv.length)return;
            const chords=[];
            const prevTiedFrets = mIdx > 0 ? measureEndTiedFrets[mIdx-1] : null;

            mv.forEach((v,vi)=>{
                // Suppress tie-stop chord: first event of this measure, same frets
                // as the last tie-start chord of the previous measure.
                // These are notes tied over the barline — they ring from the previous
                // bar and should not appear as a new chord symbol here.
                if(vi===0 && prevTiedFrets && v.voicing.frets===prevTiedFrets) return;

                const nextTick=(vi+1<mv.length)?mv[vi+1].tick:this.divisions*beatsPerMeasure;
                const beats=(nextTick-v.tick)/this.divisions;
                let name=shapeToName[v.voicing.frets];
                if(!name){name='Tab'+shapeCounter++;shapeToName[v.voicing.frets]=name;}
                chords.push({name,beats:Math.max(beats,0.5),beatInMeasure:v.tick/this.divisions,voicing:v.voicing});
                if(!resultVoicings[name])resultVoicings[name]=v.voicing;
            });
            if(chords.length)resultMeasures.push({chords,notes:this.parseNotes(measures[mIdx]),measureNumber:mIdx+1,_fromTab:true});
        });
        return{measures:resultMeasures,chordVoicings:resultVoicings,shapeToName};
    }

    _collectTabNotes(measure){const notes=[];let tick=0,lastTick=0;const ch=measure.children;for(let i=0;i<ch.length;i++){const el=ch[i],tag=el.tagName.toLowerCase();if(tag==='backup'){const d=el.querySelector('duration');if(d)tick-=parseInt(d.textContent);if(tick<0)tick=0;continue;}if(tag==='forward'){const d=el.querySelector('duration');if(d)tick+=parseInt(d.textContent);continue;}if(tag!=='note')continue;const isChord=!!el.querySelector('chord'),isRest=!!el.querySelector('rest');const dEl=el.querySelector('duration'),dur=dEl?parseInt(dEl.textContent):0;let ct;if(isChord){ct=lastTick;}else{ct=tick;lastTick=tick;tick+=dur;}if(isRest)continue;const tech=el.querySelector('notations technical');if(!tech)continue;const sEl=tech.querySelector('string'),fEl=tech.querySelector('fret');if(!sEl||!fEl)continue;const pitch=el.querySelector('pitch');let pn='',oc=0;if(pitch){const st=pitch.querySelector('step'),o=pitch.querySelector('octave'),al=pitch.querySelector('alter');pn=st?st.textContent:'';if(al){const a=parseInt(al.textContent);if(a===1)pn+='#';else if(a===-1)pn+='b';}oc=o?parseInt(o.textContent):0;}
        // Track ties: a note is tieStart if it has <tie type="start">, tieStop if <tie type="stop">
        const tieStart=!!el.querySelector('tie[type="start"]'),tieStop=!!el.querySelector('tie[type="stop"]');
        notes.push({tick:ct,string:parseInt(sEl.textContent),fret:parseInt(fEl.textContent),pitch:pn,octave:oc,duration:dur,tieStart,tieStop});}return notes;}

    _notesToVoicing(notes){const fm={};notes.forEach(n=>{if(n.string>=1&&n.string<=6&&fm[n.string]===undefined)fm[n.string]=n.fret;});const frets=Array(6).fill('x');for(let s=1;s<=6;s++){if(fm[s]!==undefined){const f=fm[s];frets[6-s]=f===0?'0':(f<=9?f.toString():f.toString(16));}}const fv=Object.values(fm).filter(f=>f>0);let position=1;if(fv.length){const mn=Math.min(...fv);if(mn>1)position=mn;}return{frets:frets.join(''),position,fingers:'000000'};}

    _stringFretToMidi(string,fret){const os={1:64,2:59,3:55,4:50,5:45,6:40};return(os[string]||40)+fret;}
}

// =============================================================================
// SHORTCODE GENERATOR — carried over verbatim
// =============================================================================

function escapeAttr(str) { if (!str) return ''; return str.replace(/"/g, '\\"').replace(/\n/g, ' '); }

function generateShortcode(parsed, options) {
    let attrs = [];
    attrs.push('title="' + escapeAttr(parsed.title) + '"');
    if (parsed.composer) attrs.push('composer="' + escapeAttr(parsed.composer) + '"');
    attrs.push('key="' + escapeAttr(parsed.key) + '"');
    attrs.push('tempo="' + (parseInt(parsed.tempo) || parseInt(options.tempo) || 120) + '"');
    attrs.push('time="' + escapeAttr(parsed.timeSignature) + '"');
    if (options.rhythm) attrs.push('rhythm="' + escapeAttr(options.rhythm) + '"');
    let shortcode = '[sbn_leadsheet ' + attrs.join(' ') + ']\n\n';
    const hasSections = parsed.sections && parsed.sections.length > 0;
    const usesSectionMarkers = hasSections && (parsed.sections.length > 1 || (parsed.sections[0].id && parsed.sections[0].id !== 'A') || (parsed.sections[0].name && parsed.sections[0].name !== 'Main') || parsed.sections[0].rhythmSlug || parsed.sections[0].tonality);
    const barsPerRow = 4;
    if (usesSectionMarkers) {
        parsed.sections.forEach(section => {
            let sl = '[' + (section.id || 'A');
            if (section.name && section.name !== section.id) sl += ' label="' + escapeAttr(section.name) + '"';
            if (section.rhythmSlug) sl += ' rhythm="' + escapeAttr(section.rhythmSlug) + '"';
            if (section.lineBreaks && section.lineBreaks.length) sl += ' breaks="' + section.lineBreaks.join(',') + '"';
            if (section.tonality) sl += ' tonality="' + escapeAttr(section.tonality) + '"';
            sl += ']\n'; shortcode += sl;
            const ml = section.measures || [];
            if (section.lineBreaks && section.lineBreaks.length) {
                let mp = 0;
                section.lineBreaks.forEach(rl => { for (let i=0; i<rl && mp<ml.length; i++) { shortcode += '| ' + ml[mp].chords.map(c=>c.name).join(' ').padEnd(12) + ' '; mp++; } shortcode += '|\n'; });
                while (mp < ml.length) { shortcode += '| ' + ml[mp].chords.map(c=>c.name).join(' ').padEnd(12) + ' '; mp++; }
            } else {
                let bc = 0;
                ml.forEach(m => { shortcode += '| ' + m.chords.map(c=>c.name).join(' ').padEnd(12) + ' '; bc++; if (bc%barsPerRow===0) shortcode += '|\n'; });
                if (bc%barsPerRow!==0) shortcode += '|\n';
            }
            shortcode += '\n';
        });
    } else {
        const measures = hasSections ? parsed.sections.flatMap(s=>s.measures||[]) : parsed.measures||[];
        let bc = 0;
        measures.forEach(m => { shortcode += '| ' + m.chords.map(c=>c.name).join(' ').padEnd(12) + ' '; bc++; if(bc%barsPerRow===0) shortcode += '|\n'; });
        if (bc%barsPerRow!==0) shortcode += '|\n';
    }
    if (Object.keys(parsed.chordVoicings||{}).length > 0) {
        shortcode += '\n[sbn_voicings]\n';
        Object.keys(parsed.chordVoicings).sort().forEach(name => {
            const v = parsed.chordVoicings[name];
            if (!v || !v.frets || typeof v.frets !== 'string') return;
            if (v.frets.length !== 6 || !/^[x0-9a-f]{6}$/i.test(v.frets)) return;
            shortcode += name + ': ' + v.frets;
            if (v.position && v.position > 1) shortcode += ' @' + v.position;
            if (v.fingers && v.fingers !== '000000' && v.fingers !== '') shortcode += ' (' + v.fingers + ')';
            shortcode += '\n';
        });
        shortcode += '[/sbn_voicings]\n';
    }
    // Repeat markers
    const repeatMarkers = {};
    if (parsed.sections) { let gi=0; parsed.sections.forEach(s=>{(s.measures||[]).forEach(m=>{if(m.repeatStart)repeatMarkers[gi]={...repeatMarkers[gi],start:true};if(m.repeatEnd)repeatMarkers[gi]={...repeatMarkers[gi],end:true};gi++;});}); }
    if (parsed.repeatMarkers && typeof parsed.repeatMarkers === 'object') Object.keys(parsed.repeatMarkers).forEach(k=>{const v=parsed.repeatMarkers[k];repeatMarkers[k]={...repeatMarkers[k],...v};});
    if (Object.keys(repeatMarkers).length > 0) { shortcode += '\n[sbn_repeats]\n' + JSON.stringify(repeatMarkers) + '\n[/sbn_repeats]\n'; }
    if (parsed.voltaEndings && Object.keys(parsed.voltaEndings).length > 0) { shortcode += '\n[sbn_endings]\n' + JSON.stringify(parsed.voltaEndings) + '\n[/sbn_endings]\n'; }
    if (options.includeMelody && parsed.melody && parsed.melody.length > 0) { shortcode += '\n[sbn_melody]\n' + JSON.stringify(parsed.melody) + '\n[/sbn_melody]\n'; }
    if (options.description) { shortcode += '\n[sbn_info]\n[description]\n' + options.description + '\n[/description]\n[/sbn_info]\n'; }
    shortcode += '\n[/sbn_leadsheet]';
    return shortcode;
}

// renderMiniDiagramSVG removed — use sbnRenderDiagramSVG() from chords.js

// =============================================================================
// ALPINE.JS EDITOR COMPONENT
// =============================================================================

// SMuFL glyph map — module-level so no Alpine `this` binding needed
const SBN_SMUFL = {
    flag8thUp:   '\uE240', flag16thUp:  '\uE242',
    flag8thDown: '\uE241', flag16thDown: '\uE243',
    restWhole:   '\uE4E3', restHalf:    '\uE4E4',
    restQuarter: '\uE4E5', rest8th:     '\uE4E6',
    rest16th:    '\uE4E7', rest32nd:    '\uE4E8',
};

function leadsheetEditor() {
    return {
        // State
        parsed: null,
        leadsheetId: {{ $leadsheet ? $leadsheet->id : 'null' }},
        rhythmSlug: '{{ $leadsheet ? $leadsheet->rhythm : '' }}',
        description: '{{ $leadsheet ? addslashes($leadsheet->description ?? '') : '' }}',
        barsPerRow: 4,
        collapsedSections: {},
        analysisCollapsed: {},
        dirty: false,
        saving: false,
        showShortcode: false,
        includeMelody: true,

        // View mode (Phase 5d)
        alpineViewMode: 'chords',
        videoSidebarOpen: false,  // Phase D: toggle independent of viewMode
        analysisData: null,
        analysisLoading: false,
        highlightMatch: null,
        detecting: false,
        detectionResult: '',

        // Tab editor (Phase 7)
        bravuraReady: false,
        tabXml: null,
        _suppressTabInit: false,   // guards $watch('parsed') from re-dispatching sbn-tab-init
        _tabInitDone: false,       // ensures sbn-tab-init only fires once on first tab view switch
        _tabVueInitialized: false,  // set to true once Vue confirms receipt of sbn-tab-init
        _tabInitTimer: null,       // debounce handle for _dispatchTabInit
        _analysisStale: false,     // set to true on markDirty or sections-sync; reset on successful loadAnalysis

        // Computed
        get uniqueChords() {
            if (!this.parsed) return [];
            const set = {};
            this.parsed.sections.forEach(s => (s.measures||[]).forEach(m => m.chords.forEach(c => set[c.name] = true)));
            return Object.keys(set);
        },


        get statsText() {
            if (!this.parsed) return '';
            const total = this.parsed.sections.reduce((s,sec) => s + (sec.measures||[]).length, 0);
            return total + ' bars · ' + this.uniqueChords.length + ' chords';
        },

        get melodyText() {
            if (!this.parsed || !this.parsed.melody) return '';
            const tab = this.parsed.melody.filter(n => n.string != null && n.fret != null && !n.isRest);
            let t = this.parsed.melody.length + ' melody notes';
            if (tab.length) t += ' (' + tab.length + ' with tab)';
            return t;
        },

        get shortcodeOutput() {
            if (!this.parsed) return '';

            // Build a temporary parsed-like object from the facade to ensure
            // the shortcode is generated from the absolute latest Vue state.
            const model = window.__sbnTabModel;
            const sections      = model ? model.getSections()      : this.parsed.sections;
            const chordVoicings = model ? model.getChordVoicings() : (this.parsed.chordVoicings || {});
            const repeatMarkers = model ? model.getRepeatMarkers() : (this.parsed.repeatMarkers || null);
            const voltaEndings  = model ? model.getVoltaEndings()  : (this.parsed.voltaEndings || null);

            const snapshot = {
                ...this.parsed,
                sections,
                chordVoicings,
                repeatMarkers,
                voltaEndings
            };

            return generateShortcode(snapshot, {
                tempo: snapshot.tempo,
                rhythm: this.rhythmSlug,
                includeMelody: this.includeMelody,
                description: this.description
            });
        },

        // ── Init ──────────────────────────────────────────────
        init() {
            if (this.leadsheetId) {
                this.loadExistingData();
            }
            // Load Bravura SMuFL font and force tab re-render when ready
            const bravura = new FontFace('Bravura',
                "url('/fonts/Bravura.otf')"
            );
            bravura.load().then(font => {
                document.fonts.add(font);
                this.bravuraReady = true;
            }).catch(err => {
                console.warn('[SBN] Bravura font failed to load:', err);
            });

            // ── Phase 7: Alpine → Vue tab editor bridge ──────────
            // Listen for Vue's init request and send data
            document.addEventListener('sbn-tab-init-ack', () => {
                // Vue confirmed it received sbn-tab-init — stop any further re-dispatches.
                this._tabVueInitialized = true;
            });

            document.addEventListener('sbn-tab-request-init', () => {
                if (!this.parsed) return;
                // Vue is asking for init data. Two cases:
                // A) _tabInitDone=false: we haven't dispatched yet (Vue mounted before
                //    loadExistingData finished). Just call _dispatchTabInit() — it will
                //    queue if not already queued.
                // B) _tabInitDone=true AND _tabVueInitialized=false: we dispatched but
                //    Vue missed it (loaded before Vue mounted). Reset so Vue gets data.
                //    Set _tabVueInitialized=true immediately to prevent further resets
                //    (the ack will confirm it, but we can't risk a second reset window).
                if (this._tabInitDone && !this._tabVueInitialized && !this._tabInitTimer) {
                    this._tabInitDone = false;
                    this._tabVueInitialized = true; // prevent further resets from retries
                }
                this._dispatchTabInit();
            });

            // Also dispatch whenever tab data changes — but NOT when the tab editor
            // is already live (that would rebuild Vue's model and wipe any tab edits).
            // Once _tabInitDone is true, chord/voicing changes from Alpine reach Vue
            // via targeted events (sbn-chords-changed, sbn-tab-voicing-applied) instead.
            this.$watch('parsed', (val) => {
                if (val && !this._suppressTabInit && !this._tabInitDone) this._dispatchTabInit();
            });

            // Dispatch when switching to tab view FOR THE FIRST TIME
            // (Vue stays mounted via x-show, so subsequent switches must
            //  not overwrite the live model with stale parsed.melody)
            this.$watch('alpineViewMode', (val) => {
                if (val === 'analysis' && (!this.analysisData || this._analysisStale)) {
                    this.loadAnalysis();
                }
                if (val === 'tab' && this.parsed && !this._tabInitDone) {
                    this._dispatchTabInit();
                }
            });

            document.addEventListener('sbn-tab-view-changed', (e) => {
                this.alpineViewMode = e.detail.viewMode;
            });

            // Phase D: Vue tab button toggles the video sidebar
            document.addEventListener('sbn-video-sidebar-toggle', (e) => {
                this.videoSidebarOpen = e.detail.open;
            });

            // Listen for tab edits from Vue
            document.addEventListener('sbn-tab-edited', (e) => {
                const { sectionIndex, measureIndex, chordName, tabEvent } = e.detail || {};
                if (chordName !== undefined && sectionIndex !== undefined && measureIndex !== undefined) {
                    const sec = this.parsed?.sections?.[sectionIndex];
                    if (sec && sec.measures?.[measureIndex]) {
                        // Phase 7c will flesh this out with chord identification
                        this.markDirty();
                    }
                }
            });

            // 6f-ctx: Generate a blank tab skeleton from the chord grid
            document.addEventListener('sbn-tab-generate-from-chords', () => {
                if (!this.parsed?.sections?.length) return;

                const timeSig  = (this.parsed.timeSignature || '4/4').split('/');
                const beats    = parseInt(timeSig[0]) || 4;
                const beatType = parseInt(timeSig[1]) || 4;
                const tpm      = 480 * beats * (4 / beatType);

                const diagToTabString = di => 6 - di;

                function fretsToNotes(fretsStr) {
                    const notes = [];
                    let first = true;
                    fretsStr.split('').forEach((ch, di) => {
                        if (ch === 'x' || ch === 'X') return;
                        const fret = parseInt(ch, 16);
                        if (isNaN(fret)) return;
                        notes.push({ string: diagToTabString(di), fret, isChordNote: !first });
                        first = false;
                    });
                    return notes;
                }

                function chordDuration(numChords) {
                    if (numChords <= 1) return { dur: 'w', ticks: tpm };
                    if (numChords === 2) return { dur: 'h', ticks: tpm / 2 };
                    return { dur: 'q', ticks: tpm / 4 };
                }

                const voicings   = this.parsed.chordVoicings || {};
                const melody     = [];
                let   globalTick = 0;

                this.parsed.sections.forEach(section => {
                    (section.measures || []).forEach(measure => {
                        const chords    = measure.chords || [];
                        const numChords = chords.length || 1;
                        const { dur, ticks: chordTicks } = chordDuration(numChords);
                        let tickInMeasure = 0;

                        chords.forEach(chord => {
                            const name    = chord.name || '';
                            const voicing = voicings[name];
                            const tick    = globalTick + tickInMeasure;

                            if (voicing && voicing.frets && voicing.frets.length === 6) {
                                const notes = fretsToNotes(voicing.frets);
                                if (notes.length) {
                                    notes.forEach(n => {
                                        melody.push({
                                            tick, pitch: null, octave: null,
                                            duration: dur, ticks: chordTicks,
                                            tieStart: false, tieStop: false, voice: 1,
                                            string: n.string, fret: n.fret,
                                            isChordNote: n.isChordNote, isRest: false,
                                            beam1: null, beam2: null,
                                            tupletActual: null, tupletNormal: null,
                                            tupletType: null, tupletBracket: false,
                                        });
                                    });
                                    tickInMeasure += chordTicks;
                                    return;
                                }
                            }
                            melody.push({ tick, duration: dur, ticks: chordTicks, voice: 1, isRest: true });
                            tickInMeasure += chordTicks;
                        });

                        globalTick += tpm;
                    });
                });

                // MusicXML for tabXml persistence
                const keyEl = '<key><fifths>0</fifths><mode>major</mode></key>';
                let measureNum = 1;
                let measuresXml = '';
                this.parsed.sections.forEach(section => {
                    (section.measures || []).forEach(measure => {
                        const chords    = measure.chords || [];
                        const numChords = chords.length || 1;
                        const { dur, ticks: chordTicks } = chordDuration(numChords);
                        const typeStr = dur === 'w' ? 'whole' : dur === 'h' ? 'half' : 'quarter';
                        const attrs = measureNum === 1
                            ? '<attributes><divisions>480</divisions>' + keyEl + '<time><beats>' + beats + '</beats><beat-type>' + beatType + '</beat-type></time><staves>1</staves><clef><sign>TAB</sign></clef></attributes>'
                            : '';

                        let notesXml = '';
                        let offsetTick = 0;
                        chords.forEach(chord => {
                            const name    = chord.name || '';
                            const voicing = voicings[name];
                            const offsetEl = offsetTick > 0 ? '<offset>' + Math.round(offsetTick) + '</offset>' : '';
                            const harmXml = '<harmony>' + offsetEl + '<root><root-step>' + name.charAt(0) + '</root-step></root><kind text="' + name + '">other</kind></harmony>';

                            if (voicing && voicing.frets && voicing.frets.length === 6) {
                                const notes = fretsToNotes(voicing.frets);
                                if (notes.length) {
                                    notesXml += harmXml;
                                    notes.forEach((n, ni) => {
                                        const chordEl = ni > 0 ? '<chord/>' : '';
                                        notesXml += '<note>' + chordEl + '<pitch><step>E</step><octave>4</octave></pitch><duration>' + Math.round(chordTicks) + '</duration><type>' + typeStr + '</type><voice>1</voice><staff>1</staff><notations><technical><string>' + n.string + '</string><fret>' + n.fret + '</fret></technical></notations></note>';
                                    });
                                    offsetTick += chordTicks;
                                    return;
                                }
                            }
                            notesXml += harmXml + '<note><rest/><duration>' + Math.round(chordTicks) + '</duration><type>' + typeStr + '</type><voice>1</voice><staff>1</staff></note>';
                            offsetTick += chordTicks;
                        });

                        measuresXml += '<measure number="' + measureNum + '">' + attrs + notesXml + '</measure>';
                        measureNum++;
                    });
                });

                const xml = '<?xml version="1.0" encoding="UTF-8"?><score-partwise version="3.1"><part-list><score-part id="P1"><part-name>Guitar</part-name></score-part></part-list><part id="P1">' + measuresXml + '</part></score-partwise>';

                this.tabXml        = xml;
                this.parsed.melody = melody;
                // Reset init flag and timer so the new file gets a fresh init dispatch.
                this._tabInitDone = false;
                if (this._tabInitTimer) { clearTimeout(this._tabInitTimer); this._tabInitTimer = null; }

                this.$nextTick(() => {
                    this._dispatchTabInit();
                    this.markDirty();
                    if (this.alpineViewMode !== 'tab') this.alpineViewMode = 'tab';
                });
            });



            // Step 3: Vue identified a chord from frets → update chord name in grid
            document.addEventListener('sbn-tab-chord-update', (e) => {
                const { globalMeasureIndex, chordIndex, newName } = e.detail || {};
                console.log('[SBN chord-update] received', { globalMeasureIndex, chordIndex, newName });
                if (newName === undefined || globalMeasureIndex === undefined || chordIndex === undefined) return;

                // Resolve globalMeasureIndex → sectionIndex + local measureIndex
                let remaining = globalMeasureIndex;
                let resolved = false;
                for (let si = 0; si < (this.parsed?.sections || []).length; si++) {
                    const measures = this.parsed.sections[si].measures || [];
                    console.log('[SBN chord-update] si', si, 'measures.length', measures.length, 'remaining', remaining);
                    if (remaining < measures.length) {
                        const oldName = measures[remaining].chords?.[chordIndex]?.name;
                        console.log('[SBN chord-update] found measure — oldName:', oldName, 'newName:', newName);
                        if (oldName !== undefined) {
                            if (oldName === newName) {
                                sbnToast('Already ' + newName, 'info');
                            } else {
                                // Guard against $watch('parsed') re-dispatching sbn-tab-init
                                this._suppressTabInit = true;
                                this.parsed.sections[si].measures[remaining].chords[chordIndex].name = newName;
                                this._suppressTabInit = false;
                                this.markDirty();
                                sbnToast('Chord updated to ' + newName, 'success');
                            }
                        }
                        resolved = true;
                        break;
                    }
                    remaining -= measures.length;
                }
                if (!resolved) console.warn('[SBN] sbn-tab-chord-update: measure not found for globalIndex', globalMeasureIndex);
            });

            // Step 3: Vue tab identified chord as different name → show confirm UI
            document.addEventListener('sbn-tab-identify-result', (e) => {
                const { oldName, newName, measureIndex, chordIndex } = e.detail || {};
                if (!oldName || !newName) return;
                const msg = oldName === newName
                    ? `Confirmed as <strong>${newName}</strong> — update?`
                    : `Identified as <strong>${newName}</strong> (was ${oldName}) — update?`;
                sbnConfirmToast(
                    msg,
                    'Update',
                    () => {
                        document.dispatchEvent(new CustomEvent('sbn-tab-chord-update', {
                            detail: { globalMeasureIndex: measureIndex, chordIndex, newName },
                        }));
                    }
                );
            });


            // Phase B Step 7: Vue is now the authority on sections/voicings.
            // When Vue makes a structural change it dispatches sbn-tab-sections-sync
            // on document (syncTabSectionsToAlpine) and window (useChordGridOps).
            // Pull fresh data from the facade so save() still works correctly.
            const _syncFromFacade = () => {
                if (!window.__sbnTabModel?._ready) return;
                this.parsed.sections      = window.__sbnTabModel.getSections();
                this.parsed.chordVoicings = window.__sbnTabModel.getChordVoicings();
                const rm = window.__sbnTabModel.getRepeatMarkers();
                const ve = window.__sbnTabModel.getVoltaEndings();
                if (rm !== null) this.parsed.repeatMarkers = rm;
                if (ve !== null) this.parsed.voltaEndings  = ve;
                this._analysisStale = true;
            };
            document.addEventListener('sbn-tab-sections-sync', _syncFromFacade);
            window.addEventListener('sbn-tab-sections-sync', _syncFromFacade);

            // Alpine owns parsed.sections, so insert/delete must happen here.

            // Undo/redo delegation from tab editor (when Vue's own stack is empty)
            document.addEventListener('sbn-tab-structure-request', (e) => {
                if (e.detail._fromAlpine) return;  // Don't re-handle chord-grid-initiated events
                const { action, measureIndex } = e.detail;
                const coord = this._globalToLocal(measureIndex);

                switch (action) {
                    case 'insertBarAfter':
                        if (!coord) return;
                        this.insertMeasureAfter(coord.si, coord.mi, true);
                        break;
                    case 'insertBarBefore':
                        if (!coord) return;
                        this.insertMeasureBefore(coord.si, coord.mi, true);
                        break;
                    case 'deleteBar':
                        if (!coord) return;
                        this.deleteMeasure(coord.si, coord.mi, true);
                        break;
                    case 'deleteSelection': {
                        // selectedIndices is an array of global measure indices.
                        // Delete in reverse order so earlier indices don't shift
                        // as later ones are removed.
                        const indices = e.detail.selectedIndices || (coord ? [measureIndex] : []);
                        const delHint = { action: 'deleteSelection', measureIndex, selectedIndices: indices };
                        const coords  = indices
                            .map(gi => this._globalToLocal(gi))
                            .filter(Boolean);
                        coords.sort((a, b) =>
                            this.getGlobalIdx(b.si, b.mi) - this.getGlobalIdx(a.si, a.mi)
                        );
                        this._wrapStructuralUndo('Delete bars (from tab)', () => {
                            coords.forEach(c => {
                                const sec = this.parsed.sections[c.si];
                                if (sec && (sec.measures || []).length > 1) {
                                    sec.measures.splice(c.mi, 1);
                                }
                            });
                            this.markDirty();
                        }, delHint);
                        break;
                    }
                }
            });
        },

        async loadExistingData() {
    try {
        const resp = await fetch('/api/admin/leadsheets/' + this.leadsheetId + '/data');
        const data = await resp.json();
        if (data.success && data.leadsheet) {
            const ls = data.leadsheet;
            if (ls.json_data && typeof ls.json_data === 'object' && ls.json_data.sections) {
                this.parsed = ls.json_data;
            } else if (ls.shortcode_content) {
                this.parsed = this.parseShortcodeClient(ls.shortcode_content, ls);
            }
            if (this.parsed) {
                this.parsed.title         = ls.title          || this.parsed.title;
                this.parsed.composer      = ls.composer       || this.parsed.composer;
                this.parsed.key           = ls.song_key       || this.parsed.key;
                this.parsed.tempo         = ls.tempo          || this.parsed.tempo;
                this.parsed.timeSignature = ls.time_signature || this.parsed.timeSignature;
            }
            this.rhythmSlug  = ls.rhythm      || '';
            this.description = ls.description || '';
            this.tabXml      = ls.tab_xml     || null;

            // Push data to Vue tab editor once fetch completes.
            // Vue may already be mounted and waiting — its sbn-tab-request-init
            // fires before Alpine's init() registers the listener, so the
            // handshake silently fails. This guarantees data always arrives.
            // _tabInitDone guards against double-initialization if both paths fire.
            if (this.parsed) this._dispatchTabInit();
        }
    } catch (e) {
        console.error('[SBN Editor] Load error:', e);
        sbnToast('Error loading leadsheet data', 'error');
    }
},

        // Client-side shortcode parser (minimal, for edit mode)
        parseShortcodeClient(sc, ls) {
            const beatsInBar = parseInt((ls.time_signature || '4/4').split('/')[0]) || 4;
            const parsed = {
                title: ls.title || '', composer: ls.composer || '', key: ls.song_key || 'C',
                tempo: ls.tempo || 120, timeSignature: ls.time_signature || '4/4',
                measures: [], sections: [], chordVoicings: {}, melody: null, repeatMarkers: null, voltaEndings: null
            };
            let curSec = null;
            let curSecInferredBreaks = [];
            sc.split('\n').forEach(line => {
                const t = line.trim();
                const sm = t.match(/^\[([A-Z])\s*(.*?)\]\s*$/);
                if (sm) {
                    if (curSec) { if (curSecInferredBreaks.length) curSec.lineBreaks = curSecInferredBreaks; parsed.sections.push(curSec); }
                    let lbl=sm[1], rs=null, secBreaks=null, tonality='';
                    const lm=sm[2].match(/label="([^"]*)"/); if(lm) lbl=lm[1];
                    const rm=sm[2].match(/rhythm="([^"]*)"/); if(rm) rs=rm[1];
                    const bkm=sm[2].match(/breaks="([^"]*)"/);
                    if(bkm){secBreaks=bkm[1].split(',').map(n=>parseInt(n)||0).filter(n=>n>0);if(!secBreaks.length)secBreaks=null;}
                    const tm=sm[2].match(/tonality="([^"]*)"/); if(tm) tonality=tm[1];
                    curSec = {id:sm[1], name:lbl, measures:[], rhythmSlug:rs, lineBreaks:secBreaks, tonality};
                    curSecInferredBreaks = [];
                    return;
                }
                if (t.startsWith('|') && !t.startsWith('|--')) {
                    if (!curSec) { curSec = {id:'A',name:'Main',measures:[],rhythmSlug:null,lineBreaks:null,tonality:''}; curSecInferredBreaks=[]; }
                    let barsOnLine = 0;
                    t.split('|').filter(m=>m.trim()).forEach(ms => {
                        const cn = ms.trim().split(/\s+/).filter(c=>c);
                        if (cn.length) { curSec.measures.push({chords:cn.map(n=>({name:n,beats:beatsInBar/cn.length}))}); barsOnLine++; }
                    });
                    if (barsOnLine > 0 && !curSec.lineBreaks) curSecInferredBreaks.push(barsOnLine);
                }
            });
            if (curSec && curSec.measures.length) { if (!curSec.lineBreaks && curSecInferredBreaks.length) curSec.lineBreaks = curSecInferredBreaks; parsed.sections.push(curSec); }
            parsed.sections.forEach(s => (s.measures||[]).forEach(m => parsed.measures.push(m)));

            // Parse voicings
            const vm = sc.match(/\[sbn_voicings\]([\s\S]*?)\[\/sbn_voicings\]/);
            if (vm) {
                vm[1].split('\n').filter(l=>l.trim()).forEach(l => {
                    const sep = l.indexOf(': '); if (sep===-1) return;
                    let key = l.substring(0,sep).trim();
                    const rest = l.substring(sep+2).trim();
                    const m = rest.match(/^([x0-9a-f]+)(?:\s*@(\d+))?(?:\s*\(([0-9]+)\))?/i);
                    if (!m) return;
                    const frets = m[1];
                    if (frets.length !== 6 || !/^[x0-9a-f]{6}$/i.test(frets)) return;
                    key = key.replace(/(@\d+):(\d+)$/, '$1.$2');
                    parsed.chordVoicings[key] = { frets, position: m[2] ? parseInt(m[2]) : 1, fingers: m[3] || '000000' };
                });
            }
            // Parse repeats, voltas, melody
            const rp = sc.match(/\[sbn_repeats\]([\s\S]*?)\[\/sbn_repeats\]/);
            if (rp) try { parsed.repeatMarkers = JSON.parse(rp[1].trim()); } catch(e) {}
            const ve = sc.match(/\[sbn_endings\]([\s\S]*?)\[\/sbn_endings\]/);
            if (ve) try { parsed.voltaEndings = JSON.parse(ve[1].trim()); } catch(e) {}
            const mm = sc.match(/\[sbn_melody\]([\s\S]*?)\[\/sbn_melody\]/);
            if (mm) try { parsed.melody = JSON.parse(mm[1].trim()); } catch(e) {}
            return parsed;
        },

        markDirty() { 
            this.dirty = true; 
            this._analysisStale = true;
        },

        // ── Undo / Redo removed in Phase B — Alpine no longer manages its own chord grid history.
        // Legacy wrapper methods are preserved as no-ops for compatibility with existing action callers.
        _wrapUndo(label, fn) { fn(); },
        _wrapStructuralUndo(label, fn, hint = null) { fn(); },

        /**
         * Single point of dispatch for sbn-tab-init.
         * Debounced with setTimeout(0) so multiple same-tick callers
         * ($watch parsed, $watch viewMode, loadExistingData, request-init)
         * collapse into exactly one dispatch. _tabInitDone blocks any
         * further calls after the first one fires.
         */
        _dispatchTabInit() {
            if (this._tabInitDone) return;
            if (this._tabInitTimer) return; // already queued this tick
            this._tabInitTimer = setTimeout(() => {
                this._tabInitTimer = null;
                if (this._tabInitDone || !this.parsed) return;
                this._tabInitDone = true;
                console.log('[SBN] dispatching tab-init (debounced)');
                document.dispatchEvent(new CustomEvent('sbn-tab-init', {
                    detail: {
                        parsed: JSON.parse(JSON.stringify(this.parsed)),
                        tabXml: this.tabXml,
                        videoSync: this.parsed.videoSync ? JSON.parse(JSON.stringify(this.parsed.videoSync)) : null,
                    }
                }));
            }, 0);
        },

        /**
         * Request a serialized tab model snapshot from Vue (synchronous).
         * Returns the snapshot object, or null if Vue hasn't mounted yet.
         */
        _requestTabSnapshot() {
            const evt = new CustomEvent('sbn-tab-request-snapshot', { detail: {} });
            document.dispatchEvent(evt);
            const snap = evt.detail.tabSnapshot || null;
            // Once Vue can return a snapshot, it has a live model — mark as initialized
            if (snap) this._tabVueInitialized = true;
            return snap;
        },

        // ── File handling ─────────────────────────────────────
        handleFileDrop(e) {
            const files = e.dataTransfer.files;
            if (files && files.length) this.processFile(files[0]);
        },
        handleFileSelect(e) {
            if (e.target.files && e.target.files.length) this.processFile(e.target.files[0]);
        },
        processFile(file) {
            const name = file.name.toLowerCase();
            if (!name.endsWith('.xml') && !name.endsWith('.musicxml')) {
                sbnToast(name.endsWith('.mxl') ? 'Compressed .mxl not yet supported' : 'Please upload a .xml or .musicxml file', 'error');
                return;
            }
            const reader = new FileReader();
            reader.onload = async (e) => {
                try {
                    const xmlString = e.target.result;
                    const parser = new MusicXMLParser(xmlString);

                    // Suppress the $watch('parsed') auto-dispatch so Vue doesn't receive
                    // Tab1/Tab2 placeholder names before identification renames them.
                    this._suppressTabInit = true;
                    this.parsed = parser.parse();
                    this._suppressTabInit = false;

                    this.tabXml = xmlString;
                    this.markDirty();
                    let msg = 'Parsed: ' + this.parsed.measures.length + ' bars';
                    if (this.parsed.melody && this.parsed.melody.length) msg += ', ' + this.parsed.melody.length + ' melody notes';
                    sbnToast(msg, 'success');

                    // Await identification so Vue gets real chord names, not Tab1/Tab2.
                    await this.identifyTabVoicings();

                    // A file import fully replaces the model — reset the init gate so
                    // Vue receives a fresh sbn-tab-init with the renamed chord data.
                    // Without this, _tabInitDone=true from loadExistingData silently
                    // swallows the dispatch and Tab1/Tab2 names persist.
                    this._tabInitDone = false;
                    this._tabVueInitialized = false;

                    // Now dispatch to Vue with fully-named chords.
                    this._dispatchTabInit();
                } catch (err) {
                    console.error('[SBN Editor] Parse error:', err);
                    sbnToast('Error: ' + err.message, 'error');
                }
            };
            reader.readAsText(file);
        },

        async identifyTabVoicings() {
            if (!this.parsed || !this.parsed.chordVoicings) return;
            const tabVoicings = {};
            Object.keys(this.parsed.chordVoicings).forEach(name => {
                if (/^Tab\d+$/.test(name)) tabVoicings[name] = this.parsed.chordVoicings[name];
            });
            if (!Object.keys(tabVoicings).length) return;
            try {
                const resp = await fetch('/api/admin/leadsheets/identify-voicings', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                    body: JSON.stringify({ voicings: tabVoicings })
                });
                const data = await resp.json();
                if (data.success && data.results) {
                    const renameMap = {};
                    Object.keys(data.results).forEach(tabName => {
                        const r = data.results[tabName];
                        if (r.name && r.confidence !== 'none') renameMap[tabName] = r.name;
                    });
                    if (Object.keys(renameMap).length) {
                        // Apply renames
                        Object.keys(renameMap).forEach(old => {
                            const newN = renameMap[old];
                            if (this.parsed.chordVoicings[old]) {
                                if (!this.parsed.chordVoicings[newN]) this.parsed.chordVoicings[newN] = this.parsed.chordVoicings[old];
                                delete this.parsed.chordVoicings[old];
                            }
                        });
                        this.parsed.sections.forEach(s => (s.measures||[]).forEach(m => m.chords.forEach(c => { if (renameMap[c.name]) c.name = renameMap[c.name]; })));
                        sbnToast('Identified ' + Object.keys(renameMap).length + ' chord(s) from tab', 'success');
                    }
                }
            } catch(e) { console.warn('[SBN Editor] Identify failed:', e); }
        },

        // ── Grid helpers ──────────────────────────────────────
        getGlobalIdx(si, li) {
            let g = 0;
            for (let i = 0; i < si; i++) g += (this.parsed.sections[i].measures || []).length;
            return g + li;
        },

        /**
         * Convert a global measure index to { si, mi } (section index, measure index).
         * Returns null if the index is out of range.
         * Used by the sbn-tab-structure-request listener (Phase 2b).
         */
        _globalToLocal(gi) {
            let g = 0;
            for (let si = 0; si < this.parsed.sections.length; si++) {
                const measures = this.parsed.sections[si].measures || [];
                for (let mi = 0; mi < measures.length; mi++) {
                    if (g === gi) return { si, mi };
                    g++;
                }
            }
            return null;
        },

        getRowLayout(si) {
            const sec = this.parsed.sections[si];
            if (!sec) return [];
            const measures = sec.measures || [];
            const total = measures.length;
            let rowSizes = [];
            if (sec.lineBreaks && sec.lineBreaks.length) {
                let sum = 0;
                sec.lineBreaks.forEach(n => { rowSizes.push(n); sum += n; });
                if (sum < total) rowSizes.push(total - sum);
                else if (sum > total) { let excess = sum - total; while (excess>0 && rowSizes.length) { const last = rowSizes[rowSizes.length-1]; if(last<=excess){excess-=last;rowSizes.pop();}else{rowSizes[rowSizes.length-1]=last-excess;excess=0;} } }
            } else {
                for (let i=0;i<total;i+=this.barsPerRow) rowSizes.push(Math.min(this.barsPerRow, total-i));
            }
            const rows = [];
            let idx = 0;
            rowSizes.forEach(size => {
                const indices = [];
                for (let i=0; i<size && idx<total; i++) { indices.push(idx); idx++; }
                rows.push({ indices });
            });
            return rows;
        },

        measureClasses(si, li, gi) {
            const m = this.parsed.sections[si].measures[li];
            const cls = [];
            if (this.hasRepeat(gi, 'start')) cls.push('has-rep-start');
            if (this.hasRepeat(gi, 'end')) cls.push('has-rep-end');
            if (this.getVolta(gi)) cls.push('has-volta');
            if (m && m.chords && m.chords.length >= 5) cls.push('is-dense');
            if (m && m._fromTab) cls.push('from-tab');
            return cls.join(' ');
        },

        hasRepeat(gi, type) {
            const rm = this.parsed.repeatMarkers;
            if (!rm) return false;
            if (typeof rm === 'object' && !Array.isArray(rm)) return !!(rm[gi] && rm[gi][type]);
            return false;
        },

        getVolta(gi) {
            if (!this.parsed.voltaEndings) return null;
            let found = null;
            Object.keys(this.parsed.voltaEndings).forEach(k => {
                if (parseInt(k) === gi) found = this.parsed.voltaEndings[k];
            });
            return found;
        },

        getVoicing(chordName, gi, ci) {
            if (!this.parsed.chordVoicings) return null;
            return this.parsed.chordVoicings[chordName + '@' + gi + '.' + ci]
                || this.parsed.chordVoicings[chordName]
                || null;
        },

        // ── Save ──────────────────────────────────────────────
        // ── Phase 7f: Tab-aware save ───────────────────────────
        // When the tab editor is active, collect fresh MusicXML from Vue,
        // re-parse it into parsed.melody so json_data stays in sync with
        // tab_xml, then proceed with the normal save.

        async save() {
            if (!this.parsed) return;
            this.saving = true;

            // If the tab editor is mounted, collect updated XML from Vue first
            if (this.viewMode === 'tab' && document.getElementById('sbn-tab-editor')) {
                const xml = await this._requestTabXml();
                if (xml) {
                    this.tabXml = xml;
                    // Re-parse the XML to get an updated melody for json_data persistence.
                    // IMPORTANT: we store the result in a local variable and inject it
                    // directly into the POST body — we never assign to this.parsed.melody.
                    try {
                        const parser = new MusicXMLParser(xml);
                        const reparsed = parser.parse();
                        if (reparsed && reparsed.melody) {
                            this._savedMelody = reparsed.melody;
                        }
                    } catch (err) {
                        console.error('[SBN] Re-parse of saved XML failed:', err);
                    }
                }
            }

            // ── Suppress sbn-tab-init during save mutations ──
            this._suppressTabInit = true;

            // Pull fresh structural data from the facade (Phase B Step 8)
            const model = window.__sbnTabModel;
            const sections      = model ? model.getSections()      : this.parsed.sections;
            const chordVoicings = model ? model.getChordVoicings() : (this.parsed.chordVoicings || {});
            const repeatMarkers = model ? model.getRepeatMarkers() : (this.parsed.repeatMarkers || null);
            const voltaEndings  = model ? model.getVoltaEndings()  : (this.parsed.voltaEndings || null);

            // Rebuild flattened measures array for server-side measure_count
            const allMeasures = sections.flatMap(s => s.measures || []);

            // Phase D: pull videoSync from the facade; fall back to what's already in parsed
            const videoSyncData = (model ? model.getVideoSync() : null) ?? this.parsed.videoSync ?? null;

            // Construct final json_data by merging Alpine meta fields with facade structural data
            const finalJsonData = {
                ...this.parsed,
                sections,
                measures: allMeasures,
                chordVoicings,
                repeatMarkers,
                voltaEndings,
                melody: this._savedMelody || this.parsed.melody,
            };
            if (videoSyncData) finalJsonData.videoSync = videoSyncData;

            const shortcode = this.shortcodeOutput;
            const url = this.leadsheetId
                ? '/admin/leadsheets/' + this.leadsheetId
                : '/admin/leadsheets';
            const method = this.leadsheetId ? 'PUT' : 'POST';

            try {
                const resp = await fetch(url, {
                    method,
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({
                        title: this.parsed.title,
                        composer: this.parsed.composer,
                        song_key: this.parsed.key,
                        tempo: this.parsed.tempo,
                        time_signature: this.parsed.timeSignature,
                        rhythm: this.rhythmSlug,
                        course_id: null,
                        shortcode_content: shortcode,
                        json_data: JSON.stringify(finalJsonData),
                        tab_xml: this.tabXml,
                        description: this.description,
                        harmony_notes: '',
                        form_notes: '',
                        voicing_notes: ''
                    })
                });
                const data = await resp.json();
                if (data.success || data.id) {
                    this.dirty = false;
                    this._savedMelody = null;
                    this._suppressTabInit = false;
                    sbnToast('Leadsheet saved!', 'success');
                    if (!this.leadsheetId && data.id) {
                        window.history.replaceState({}, '', '/admin/leadsheets/' + data.id + '/edit');
                        this.leadsheetId = data.id;
                    }
                } else {
                    sbnToast('Error saving: ' + (data.message || 'Unknown error'), 'error');
                }
            } catch (e) {
                sbnToast('Network error saving leadsheet', 'error');
            } finally {
                this._suppressTabInit = false;
            }
            this.saving = false;
        },

        _requestTabXml() {
            return new Promise((resolve) => {
                const timeout = setTimeout(() => {
                    document.removeEventListener('sbn-tab-save-response', handler);
                    console.warn('[SBN] Tab XML save response timed out — saving without updated tab_xml');
                    resolve(null);
                }, 3000);

                function handler(e) {
                    clearTimeout(timeout);
                    document.removeEventListener('sbn-tab-save-response', handler);
                    if (e.detail?.error) {
                        console.error('[SBN] Tab XML serialization error:', e.detail.error);
                        resolve(null);
                    } else {
                        resolve(e.detail?.xml ?? null);
                    }
                }

                document.addEventListener('sbn-tab-save-response', handler);
                document.dispatchEvent(new CustomEvent('sbn-tab-save-request'));
            });
        },

        copyShortcode() {
            navigator.clipboard.writeText(this.shortcodeOutput).then(() => sbnToast('Copied!', 'success'));
        },

        // ── Analysis (Phase 5d) ────────────────────────────────
        async loadAnalysis() {
            if (!this.leadsheetId) return;
            // Only re-fetch if we have no data or the data is stale (dirty/synced)
            if (this.analysisData && !this._analysisStale) return;

            this.analysisLoading = true;
            this.detectionResult = '';

            // Read fresh sections from the facade (Phase B Step 9)
            // Ensures the latest Vue state is considered before we fetch.
            const sections = window.__sbnTabModel ? window.__sbnTabModel.getSections() : this.parsed.sections;

            try {
                const resp = await fetch('/api/admin/leadsheets/' + this.leadsheetId + '/analyse-progressions');
                const data = await resp.json();
                if (data.success) {
                    this.analysisData = data.data;
                    this._analysisStale = false;
                }
            } catch (e) {
                console.error('Analysis failed:', e);
                sbnToast('Failed to load analysis', 'error');
            }
            this.analysisLoading = false;
        },

        async runDetection() {
            if (!this.leadsheetId) return;
            this.detecting = true;
            this.detectionResult = '';
            try {
                const token = document.querySelector('meta[name="csrf-token"]').content;
                const resp = await fetch('/api/admin/leadsheets/' + this.leadsheetId + '/detect-progressions', {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': token, 'Content-Type': 'application/json' },
                });
                const data = await resp.json();
                if (data.success) {
                    this.detectionResult = '\u2713 ' + data.data.occurrences + ' progressions stored';
                    sbnToast(data.data.occurrences + ' progressions detected & stored', 'success');
                }
            } catch (e) {
                this.detectionResult = '\u2717 Error: ' + e.message;
                sbnToast('Detection failed', 'error');
            }
            this.detecting = false;
        },

        formatNumeral(numeral) {
            if (!numeral) return '?';
            return numeral
                .replace('maj7', '\u25B37')
                .replace('m7b5', '\u00F87')
                .replace(/o7$/, '\u00B07');
        },

        isMeasureInMatch(sectionIdx, measureIdx) {
            if (!this.highlightMatch) return false;
            // Check all highlighted ranges (may span multiple sections)
            for (const range of this.highlightMatch.ranges) {
                if (range.section === sectionIdx &&
                    measureIdx >= range.start && measureIdx <= range.end) {
                    return true;
                }
            }
            return false;
        },

        setMatchHighlight(sectionIdx, match) {
            const ranges = [
                { section: sectionIdx, start: match.start_measure, end: match.end_measure }
            ];
            // Check if this match has a resolution in the next section
            if (this.analysisData) {
                const section = this.analysisData.sections[sectionIdx];
                if (section && section.resolutions) {
                    for (const res of section.resolutions) {
                        if (res.from_progression === match.name) {
                            // Find the target section index
                            const targetIdx = this.analysisData.sections.findIndex(
                                s => s.section_id === res.target_section_id
                            );
                            if (targetIdx >= 0) {
                                ranges.push({ section: targetIdx, start: res.start_measure, end: res.end_measure });
                            }
                        }
                    }
                }
            }
            this.highlightMatch = { ranges };
        },
    };
}

// Toast helper (matches existing admin pattern)
function sbnToast(message, type) {
    const toast = document.createElement('div');
    toast.className = 'sbn-toast sbn-toast-' + (type || 'info');
    toast.textContent = message;
    document.body.appendChild(toast);
    setTimeout(() => { toast.style.opacity = '0'; toast.style.transform = 'translateY(16px)'; setTimeout(() => toast.remove(), 300); }, 3000);
}
window.sbnToast = sbnToast;

// Singleton ref — only one identify confirm toast at a time
let _sbnIdentifyToast = null;

function sbnConfirmToast(htmlMessage, confirmLabel, onConfirm) {
    // Kill any existing identify confirm toast immediately
    if (_sbnIdentifyToast) {
        _sbnIdentifyToast.remove();
        _sbnIdentifyToast = null;
    }

    const toast = document.createElement('div');
    toast.className = 'sbn-toast sbn-toast-confirm';
    toast.innerHTML = `<span class="sbn-toast-msg">${htmlMessage}</span>`;

    const btnConfirm = document.createElement('button');
    btnConfirm.className = 'sbn-toast-btn sbn-toast-btn--confirm';
    btnConfirm.textContent = confirmLabel || 'OK';

    const btnDismiss = document.createElement('button');
    btnDismiss.className = 'sbn-toast-btn sbn-toast-btn--dismiss';
    btnDismiss.textContent = '✕';

    let autoTimer = null;

    function dismiss() {
        // Null the singleton immediately so the next toast can show without waiting
        if (_sbnIdentifyToast === toast) _sbnIdentifyToast = null;
        clearTimeout(autoTimer);
        toast.style.opacity = '0';
        toast.style.transform = 'translateY(16px)';
        setTimeout(() => toast.remove(), 300);
    }

    btnConfirm.addEventListener('click', () => { dismiss(); onConfirm(); });
    btnDismiss.addEventListener('click', dismiss);

    toast.appendChild(btnConfirm);
    toast.appendChild(btnDismiss);
    document.body.appendChild(toast);
    _sbnIdentifyToast = toast;

    autoTimer = setTimeout(dismiss, 8000);
}
</script>


@endpush
