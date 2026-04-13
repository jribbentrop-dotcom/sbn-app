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
    @vite('resources/js/tab-editor.js')
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

            {{-- ── Tab bar ──────────────────────────────── --}}
            <div class="sbn-ve-tabs">
                <button class="sbn-ve-tab" :class="{ 'is-active': viewMode === 'chords' }"
                        @click="viewMode = 'chords'">Chords</button>
                <button class="sbn-ve-tab" :class="{ 'is-active': viewMode === 'analysis' }"
                        @click="viewMode = 'analysis'; loadAnalysis()">Analysis</button>
                <button class="sbn-ve-tab" :class="{ 'is-active': viewMode === 'tab' }"
                        @click="viewMode = 'tab'">Tab</button>
            </div>

            {{-- ══ CHORDS VIEW ══════════════════════════════ --}}
            <div class="sbn-ve-grid" x-show="viewMode === 'chords'" @click="handleGridClick($event)" @mouseup="handleGridMouseUp($event)">
                <template x-for="(section, si) in parsed.sections" :key="si">
                    <div class="sbn-ve-section">
                        {{-- Section header --}}
                        <div class="sbn-ve-section-header" :class="{ 'is-collapsed': collapsedSections[si] }">
                            <button class="sbn-ve-section-collapse" :class="{ 'is-collapsed': collapsedSections[si] }"
                                    @click="collapsedSections[si] = !collapsedSections[si]">▼</button>
                            <input type="text" class="sbn-ve-section-id" maxlength="1"
                                   :value="section.id" @input="section.id = $event.target.value.toUpperCase(); markDirty()">
                            <input type="text" class="sbn-ve-section-name" placeholder="Section name"
                                   :value="section.name" @input="section.name = $event.target.value; markDirty()">
                            <select class="sbn-ve-section-rhythm"
                                    :value="section.rhythmSlug || ''"
                                    @change="section.rhythmSlug = $event.target.value || null; markDirty()">
                                <option value="">Inherit rhythm</option>
                                @foreach($rhythms as $r)
                                    <option value="{{ $r->slug }}">{{ $r->name }}</option>
                                @endforeach
                            </select>
                            <input type="text" class="sbn-ve-section-tonality" placeholder="Key"
                                   title="Tonality override (e.g. Cm, G, Bbm)"
                                   :value="section.tonality || ''"
                                   @input="section.tonality = $event.target.value; markDirty()">
                            <span class="sbn-ve-section-bar-count" x-text="(section.measures||[]).length + ' bars'"></span>
                            <button class="sbn-ve-section-btn" @click="addMeasureToSection(si)" title="Add bar">+</button>
                            <button class="sbn-ve-section-delete" x-show="parsed.sections.length > 1"
                                    @click="deleteSection(si)" title="Remove section">×</button>
                        </div>

                        {{-- Collapsed preview --}}
                        <div class="sbn-ve-section-collapsed" x-show="collapsedSections[si]"
                             x-text="getCollapsedPreview(section)"></div>

                        {{-- Section body --}}
                        <div class="sbn-ve-section-body" x-show="!collapsedSections[si]">
                            <template x-for="(row, ri) in getRowLayout(si)" :key="ri">
                                <div class="sbn-ve-row">
                                    <template x-for="li in row.indices" :key="li">
                                        <div class="sbn-ve-measure"
                                             :class="measureClasses(si, li, getGlobalIdx(si, li))"
                                             :data-si="si" :data-mi="li"
                                             draggable="true"
                                             @dragstart="handleDragStart($event, si, li)"
                                             @dragover.prevent="handleDragOver($event, si, li)"
                                             @dragleave="handleDragLeave($event, si, li)"
                                             @drop.prevent="handleDrop($event, si, li)"
                                             @dragend="handleDragEnd()"
                                             @mousedown="handleMeasureMouseDown($event, si, li)"
                                             @mouseenter="handleMeasureMouseEnter($event, si, li)">

                                            <template x-if="getVolta(getGlobalIdx(si, li))">
                                                <div class="sbn-ve-volta" x-text="getVolta(getGlobalIdx(si, li)).number + '.'"></div>
                                            </template>

                                            <div class="sbn-ve-measure-num" x-text="getGlobalIdx(si, li) + 1"></div>

                                            <template x-if="section.measures[li] && section.measures[li]._fromTab">
                                                <div class="sbn-ve-tab-badge">TAB</div>
                                            </template>

                                            <template x-if="hasRepeat(getGlobalIdx(si, li), 'start')">
                                                <div class="sbn-ve-rep-sign rep-start">𝄆</div>
                                            </template>
                                            <template x-if="hasRepeat(getGlobalIdx(si, li), 'end')">
                                                <div class="sbn-ve-rep-sign rep-end">𝄇</div>
                                            </template>

                                            <div class="sbn-ve-measure-content">
                                                <template x-for="(chord, ci) in (section.measures[li]||{}).chords || []" :key="ci">
                                                    <div class="sbn-ve-chord"
                                                         :class="{
                                                             'double': (section.measures[li]?.chords?.length ?? 0) === 2,
                                                             'multi':  (section.measures[li]?.chords?.length ?? 0) >= 3 && (section.measures[li]?.chords?.length ?? 0) <= 4,
                                                             'dense':  (section.measures[li]?.chords?.length ?? 0) >= 5,
                                                             'sbn-ve-selected': isChordSelected(si, li, ci),
                                                         }"
                                                         @click.stop="handleChordCardClick($event, si, li, ci)"
                                                         @contextmenu.prevent.stop="showChordMenu($event, si, li, ci)">
                                                        <div class="sbn-ve-chord-name"
                                                             @click.stop="openChordPicker(si, li, ci, $event.target)"
                                                             x-html="formatChordHtml(chord.name)"></div>
                                                        <div class="sbn-ve-chord-diagram"
                                                             :class="{ 'empty': !getVoicing(chord.name, getGlobalIdx(si, li), ci) }"
                                                             @click.stop="chord.name ? openVoicingPicker(chord.name, chord.name + '@' + getGlobalIdx(si,li) + '.' + ci) : openChordPicker(si, li, ci, $el)">
                                                            <template x-if="getVoicing(chord.name, getGlobalIdx(si, li), ci)">
                                                                <div class="sbn-diagram-card" x-html="renderMiniDiagram(getVoicing(chord.name, getGlobalIdx(si, li), ci))"></div>
                                                            </template>
                                                            <template x-if="!getVoicing(chord.name, getGlobalIdx(si, li), ci)">
                                                                <span>🎸</span>
                                                            </template>
                                                        </div>
                                                        <template x-if="section.measures[li].chords.length < 5">
                                                            <div class="sbn-ve-beats">
                                                                <template x-for="b in Math.round(chord.beats)" :key="b">
                                                                    <div class="sbn-ve-beat-dot"></div>
                                                                </template>
                                                            </div>
                                                        </template>
                                                    </div>
                                                </template>
                                            </div>

                                            {{-- Hover actions removed — all operations via right-click context menu --}}
                                        </div>
                                    </template>

                                    {{-- Row resize --}}
                                    <div class="sbn-ve-row-resize">
                                        <button class="sbn-ve-row-btn" @click.stop="rowShrink(si, ri)" title="Move last bar to next row">−</button>
                                        <button class="sbn-ve-row-btn" @click.stop="rowGrow(si, ri)" title="Pull next bar into this row">+</button>
                                        <button class="sbn-ve-row-btn sbn-ve-row-btn-section"
                                                @click.stop="splitSection(si, ri)" title="New section after this row">§</button>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>
                </template>
            </div>

            {{-- Add section (chords view only) --}}
            <button class="sbn-ve-add-section" x-show="viewMode === 'chords'" @click="addSection()">+ Add Section</button>

            {{-- Grid footer / keyboard hint (chords view only) --}}
            <div class="sbn-ve-grid-footer" x-show="viewMode === 'chords'">
                <span class="sbn-ve-grid-footer-hint">Click to select · Shift+click range · Ctrl+C / X / V clipboard · Ctrl+Z undo</span>
            </div>

            {{-- ══ ANALYSIS VIEW ════════════════════════════ --}}
            <div class="sbn-analysis-panel" x-show="viewMode === 'analysis'" x-cloak>
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

            {{-- ══ TAB VIEW ══════════════════════════════════ --}}
            <div class="sbn-analysis-panel" x-show="viewMode === 'tab'" x-cloak>
                <div id="sbn-tab-editor"></div>
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

        {{-- ── Chord picker popup ────────────────────────── --}}
        <div class="sbn-ve-chord-picker" x-show="picker.open" x-cloak
             @click.away="picker.open = false"
             :style="'top:' + picker.top + 'px; left:' + picker.left + 'px'">
            <input type="text" class="sbn-ve-chord-input" x-model="picker.value"
                   @keydown.enter="applyChordPicker()"
                   @keydown.escape="picker.open = false"
                   x-ref="pickerInput">
            <div class="sbn-ve-picker-section">
                <div class="sbn-ve-picker-label">Root</div>
                <div class="sbn-ve-picker-buttons">
                    <template x-for="r in ROOTS" :key="r">
                        <button class="sbn-ve-picker-btn"
                                :class="{ 'is-active': pickerRoot === r }"
                                @click="setPickerRoot(r)" x-text="r"></button>
                    </template>
                </div>
            </div>
            <div class="sbn-ve-picker-section">
                <div class="sbn-ve-picker-label">Quality</div>
                <div class="sbn-ve-picker-buttons">
                    <template x-for="q in QUALITIES" :key="q.val">
                        <button class="sbn-ve-picker-btn"
                                :class="{ 'is-active': pickerQuality === q.val }"
                                @click="setPickerQuality(q.val)" x-text="q.label"></button>
                    </template>
                </div>
            </div>
            <div class="sbn-ve-picker-actions">
                <button class="sbn-btn sbn-btn-xs" @click="picker.open = false">Cancel</button>
                <button class="sbn-btn sbn-btn-primary sbn-btn-xs" @click="applyChordPicker()">Apply</button>
            </div>
        </div>

        {{-- ── Voicing modal (mobile fallback < 1024px only) --}}
        <div class="sbn-ve-modal-overlay sbn-vp-modal-only"
             x-show="voicingPicker.open" x-cloak
             @click.self="voicingPicker.open = false; voicingPicker._tabSource = null" x-transition.opacity>
            <div class="sbn-ve-modal" @click.stop>
                <div class="sbn-ve-modal-header">
                    <div>
                        <div class="sbn-ve-modal-subtitle">Select Voicing</div>
                        <div class="sbn-ve-modal-chord-name" x-html="formatChordHtml(voicingPicker.chordName)"></div>
                    </div>
                    <button class="sbn-ve-modal-close" @click="voicingPicker.open = false; voicingPicker._tabSource = null">×</button>
                </div>
                <div class="sbn-ve-modal-body">
                    <div class="sbn-vp-filters-compact" x-show="!voicingPicker.loading">
                        <template x-for="cat in voicingPicker.filters.voicing_categories" :key="cat">
                            <button class="sbn-vp-pill"
                                    :class="{ 'active': isPickerFilterActive('voicing_category', cat) }"
                                    @click="togglePickerFilter('voicing_category', cat)"
                                    x-text="cat"></button>
                        </template>
                        <template x-for="rs in voicingPicker.filters.root_strings" :key="rs">
                            <button class="sbn-vp-pill"
                                    :class="{ 'active': isPickerFilterActive('root_string', rs) }"
                                    @click="togglePickerFilter('root_string', rs)"
                                    x-text="rs"></button>
                        </template>
                    </div>
                    <div x-show="voicingPicker.loading" class="sbn-ve-modal-loading">Searching voicing database…</div>
                    <div x-show="!voicingPicker.loading && !voicingPicker.results.length" class="sbn-ve-modal-empty">
                        <div style="font-size:24px;margin-bottom:8px">📭</div>
                        No voicings found for <span x-text="voicingPicker.chordName"></span>.
                        <div class="sbn-ve-modal-empty-hint">Try adjusting the filters or add voicings in the Chord Diagrams page.</div>
                    </div>
                    <div class="sbn-ve-voicing-grid" x-show="!voicingPicker.loading && voicingPicker.results.length">
                        <!-- "Current (from tab)" card — shown only when opened from tab AND no library match -->
                        <div x-show="voicingPicker._tabSource && voicingPicker._tabMatchIndex === -1 && voicingPicker._tabSource.currentFrets"
                             class="sbn-ve-voicing-card sbn-vp-card--from-tab">
                            <div class="sbn-vp-card-name" x-html="formatChordHtml(voicingPicker._tabSource ? voicingPicker._tabSource.chordName : '')"></div>
                            <span x-html="voicingPicker._tabSource ? renderMiniDiagram({ frets: voicingPicker._tabSource.currentFrets, position: voicingPicker._tabSource.currentPosition || 1 }) : ''"></span>
                            <span class="sbn-vp-from-tab-label">Current (from tab)</span>
                        </div>
                        <template x-for="(v, vi) in voicingPicker.results" :key="vi">
                            <div class="sbn-ve-voicing-card"
                                 :class="{ 'is-selected': isVoicingSelected(v), 'sbn-vp-card--current': voicingPicker._tabSource && vi === voicingPicker._tabMatchIndex }"
                                 @click="selectVoicing(v)">
                                <template x-if="isVoicingSelected(v)">
                                    <div class="check-mark">✓</div>
                                </template>
                                <div class="sbn-vp-card-name" x-html="formatChordHtml(pickerDisplayName())"></div>
                                <span x-html="renderMiniDiagram({frets: v.frets, position: v.position})"></span>
                            </div>
                        </template>
                    </div>
                </div>
                <div class="sbn-ve-modal-footer">
                    <button class="sbn-btn sbn-btn-xs sbn-btn-danger"
                            x-show="voicingPicker.hasExisting"
                            @click="removeVoicing()">Remove voicing</button>
                    <span class="sbn-ve-modal-count"
                          x-text="voicingPicker.results.length + ' voicing' + (voicingPicker.results.length !== 1 ? 's' : '')"></span>
                </div>
            </div>
        </div>

    </div> {{-- end .sbn-vp-editor-main --}}

    {{-- ═══════════════════════════════════════════════════════
         RIGHT PANEL (meta + toolbar + voicing picker)
    ═══════════════════════════════════════════════════════ --}}
    <aside class="sbn-vp-panel sbn-vp-desktop-only">

        {{-- ── Song meta ──────────────────────────────────── --}}
        <template x-if="parsed">
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
                            @change="clearAllLineBreaks(); markDirty()"
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
        <div class="sbn-vp-toolbar" x-show="parsed">
            {{-- Row 1: stats --}}
            {{-- Copy/Cut/Paste removed — use right-click context menu on chord cards --}}
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

        {{-- ── Voicing picker / overview — CHORDS view, or tab view when picker is open ─── --}}
        <div class="sbn-vp-context" x-show="viewMode === 'chords' || (viewMode === 'tab' && voicingPicker.open)" x-cloak>

            {{-- Active picker state --}}
            <div class="sbn-vp-picker-wrap" x-show="voicingPicker.open" x-cloak>
                <div class="sbn-vp-header">
                    <div>
                        <div class="sbn-vp-subtitle">Select Voicing</div>
                        <div class="sbn-vp-chord-name" x-html="formatChordHtml(voicingPicker.chordName)"></div>
                    </div>
                    <button class="sbn-vp-close" @click="voicingPicker.open = false; voicingPicker._tabSource = null">×</button>
                </div>

                <div class="sbn-vp-filters">
                    <div class="sbn-vp-filter-row">
                        <template x-for="cat in voicingPicker.filters.voicing_categories" :key="cat">
                            <button class="sbn-vp-pill"
                                    :class="{ 'active': isPickerFilterActive('voicing_category', cat) }"
                                    @click="togglePickerFilter('voicing_category', cat)"
                                    x-text="cat"></button>
                        </template>
                    </div>
                    <div class="sbn-vp-filter-row">
                        <template x-for="rs in voicingPicker.filters.root_strings" :key="rs">
                            <button class="sbn-vp-pill"
                                    :class="{ 'active': isPickerFilterActive('root_string', rs) }"
                                    @click="togglePickerFilter('root_string', rs)"
                                    x-text="rs"></button>
                        </template>
                    </div>
                    <div class="sbn-vp-filter-row sbn-vp-steppers">
                        <div class="sbn-vp-stepper" :class="{ 'has-value': voicingPicker.activeFilters.extension }">
                            <span class="sbn-vp-stepper-label">Ext</span>
                            <button class="sbn-vp-step-btn" @click="stepExtension(-1)">&larr;</button>
                            <span class="sbn-vp-step-value"
                                  :class="{ 'active': voicingPicker.activeFilters.extension }"
                                  @click="clearExtension()"
                                  x-text="voicingPicker.activeFilters.extension || '—'"></span>
                            <button class="sbn-vp-step-btn" @click="stepExtension(1)">&rarr;</button>
                        </div>
                        <div class="sbn-vp-stepper" :class="{ 'has-value': voicingPicker.activeFilters.inversion !== 'all' }">
                            <span class="sbn-vp-stepper-label">Inv</span>
                            <button class="sbn-vp-step-btn" @click="stepInversion(-1)">&larr;</button>
                            <span class="sbn-vp-step-value"
                                  :class="{ 'active': voicingPicker.activeFilters.inversion !== 'all' }"
                                  x-text="getInversionLabel()"></span>
                            <button class="sbn-vp-step-btn" @click="stepInversion(1)">&rarr;</button>
                        </div>
                    </div>
                    <div class="sbn-vp-filter-row sbn-vp-reset-row" x-show="hasActiveFilters()">
                        <button class="sbn-vp-reset" @click="resetPickerFilters()">Reset filters</button>
                    </div>
                </div>

                <div class="sbn-vp-body">
                    <div class="sbn-vp-loading-overlay" x-show="voicingPicker.loading" x-transition.opacity.duration.150ms>
                        <span>Searching…</span>
                    </div>
                    <div x-show="!voicingPicker.loading && !voicingPicker.results.length" class="sbn-vp-empty">
                        <div style="font-size:20px;margin-bottom:6px;opacity:0.4">📭</div>
                        No voicings found.
                        <div class="sbn-vp-empty-hint">Try adjusting the filters.</div>
                    </div>
                    <div class="sbn-vp-grid"
                         :style="voicingPicker.loading ? 'opacity:0.3;pointer-events:none' : 'opacity:1'"
                         x-show="voicingPicker.results.length">
                        <!-- "Current (from tab)" card — shown only when opened from tab AND no library match -->
                        <div x-show="voicingPicker._tabSource && voicingPicker._tabMatchIndex === -1 && voicingPicker._tabSource.currentFrets"
                             class="sbn-vp-card sbn-vp-card--from-tab">
                            <div class="sbn-vp-card-name" x-html="formatChordHtml(voicingPicker._tabSource ? voicingPicker._tabSource.chordName : '')"></div>
                            <span x-html="voicingPicker._tabSource ? renderMiniDiagram({ frets: voicingPicker._tabSource.currentFrets, position: voicingPicker._tabSource.currentPosition || 1 }) : ''"></span>
                            <span class="sbn-vp-from-tab-label">Current (from tab)</span>
                        </div>
                        <template x-for="(v, vi) in voicingPicker.results" :key="vi">
                            <div class="sbn-vp-card"
                                 :class="{ 'is-selected': isVoicingSelected(v), 'sbn-vp-card--current': voicingPicker._tabSource && vi === voicingPicker._tabMatchIndex }"
                                 @click="selectVoicing(v)">
                                <template x-if="isVoicingSelected(v)">
                                    <div class="sbn-vp-check">✓</div>
                                </template>
                                <div class="sbn-vp-card-name" x-html="formatChordHtml(pickerDisplayName())"></div>
                                <span x-html="renderMiniDiagram({frets: v.frets, position: v.position})"></span>
                            </div>
                        </template>
                    </div>
                </div>

                <div class="sbn-vp-footer">
                    <button class="sbn-btn sbn-btn-xs sbn-btn-danger"
                            x-show="voicingPicker.hasExisting"
                            @click="removeVoicing()">Remove voicing</button>
                    <span class="sbn-vp-count"
                          x-text="voicingPicker.results.length + ' voicing' + (voicingPicker.results.length !== 1 ? 's' : '')"></span>
                </div>
            </div>

            {{-- Overview (resting) state --}}
            <div class="sbn-vp-overview" x-show="viewMode === 'chords' && !voicingPicker.open && parsed" x-cloak>
                <div class="sbn-vp-overview-header">
                    <div class="sbn-vp-subtitle">Song Voicings</div>
                    <span class="sbn-vp-overview-count" x-text="sortedUniqueChords.length + ' chords'"></span>
                </div>
                <div class="sbn-vp-overview-grid">
                    <template x-for="name in sortedUniqueChords" :key="name">
                        <div class="sbn-vp-overview-card"
                             :class="{ 'has-voicing': parsed.chordVoicings && parsed.chordVoicings[name] }"
                             @click="openVoicingPicker(name, null)">
                            <div class="sbn-vp-card-name" x-html="formatChordHtml(name)"></div>
                            <template x-if="parsed.chordVoicings && parsed.chordVoicings[name]">
                                <div>
                                    <span x-html="renderMiniDiagram(parsed.chordVoicings[name])"></span>
                                </div>
                            </template>
                            <template x-if="!(parsed.chordVoicings && parsed.chordVoicings[name])">
                                <div class="sbn-vp-overview-empty"><span>+</span></div>
                            </template>
                        </div>
                    </template>
                </div>
            </div>

            {{-- Placeholder (no data loaded) --}}
            <div x-show="viewMode === 'chords' && !voicingPicker.open && !parsed"
                 style="display:flex;align-items:center;justify-content:center;flex:1;color:var(--clr-text-muted);font-size:13px;padding:16px">
                <div style="text-align:center">
                    <div style="font-size:28px;margin-bottom:8px;opacity:0.3">🎸</div>
                    Import a leadsheet to get started.
                </div>
            </div>

        </div>{{-- end .sbn-vp-context (chords) --}}

        {{-- ── Analysis sidebar — ANALYSIS view only ──────── --}}
        <template x-if="viewMode === 'analysis'">
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
     x-show="viewMode === 'tab' && !voicingPicker.open"
     x-cloak
     style="display:flex;flex-direction:column;flex:1;min-height:0;overflow:hidden;">
</div>
    
    </aside>

</div>
@endsection
@push('scripts')
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
        const beats = measure.querySelector('beats');
        const beatsPerMeasure = beats ? parseInt(beats.textContent) : 4;
        const harmonies = measure.querySelectorAll('harmony');
        const chords = [];
        if (harmonies.length === 0 && measure.querySelectorAll('note').length === 0) {
            return { chords: [], notes: [], measureNumber: measureIndex + 1 };
        }
        const beatsPerChord = harmonies.length > 0 ? beatsPerMeasure / harmonies.length : beatsPerMeasure;
        harmonies.forEach(h => { const c = this.parseHarmony(h); c.beats = beatsPerChord; chords.push(c); });
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
                chords.push({name,beats:Math.max(beats,0.5),voicing:v.voicing});
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
        viewMode: 'chords',
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

        // Selection (grid-interact Phase 1 — two-tier model)
        // items: [{ si, mi, ci }] — per chord-card granularity
        // selectionAnchor: { si, mi } — for Shift+Click range extension
        selection: [],
        selectionAnchor: null,
        clipboard: null,

        // Drag-to-reorder (within section)
        _drag: null,          // { si, mi } — measure being dragged
        _dragOver: null,      // { si, mi } — current drop target
        _dragOverPos: null,   // 'before' | 'after' — which side of the target
        _dragGhost: null,     // cloned DOM node used as drag image

        // Shift+drag batch selection
        _mouseSelectActive: false,
        _mouseSelectAnchor: null,  // { si, mi } where mousedown started
        _mouseSelectMoved: false,  // true if drag crossed into a different measure

        // Undo / redo (grid-interact Phase 2a)
        _undoStack: [],
        _undoPointer: -1,
        _MAX_UNDO: 50,

        // Chord picker
        picker: { open: false, top: 0, left: 0, value: '', si: 0, mi: 0, ci: 0 },
        ROOTS: ['C','C#','Db','D','D#','Eb','E','F','F#','Gb','G','G#','Ab','A','A#','Bb','B'],
        QUALITIES: [
            {label:'△7',val:'maj7'},{label:'7',val:'7'},{label:'–7',val:'m7'},
            {label:'ø7',val:'m7b5'},{label:'°7',val:'dim7'},{label:'6',val:'6'},
            {label:'–6',val:'m6'},{label:'9',val:'9'},{label:'–9',val:'m9'},
            {label:'sus4',val:'sus4'},{label:'+',val:'aug'},{label:'–',val:'m'},
            {label:'maj',val:''}
        ],

        // Voicing picker (context panel + modal fallback)
        voicingPicker: {
            open: false, chordName: '', voicingKey: null,
            loading: false, results: [], hasExisting: false,
            // Parsed chord components for filtering
            root: '', quality: '', extension: '', bassNote: '', inversion: '',
            // Filter state
            filters: { voicing_categories: [], root_strings: [], extensions: [], inversions: [] },
            activeFilters: { voicing_category: 'all', root_string: 'all', extension: '', inversion: 'all' },
            // Extension cycling
            extensionCycles: {
                '9':  ['b9','9','#9'],
                '11': ['11','#11'],
                '13': ['b13','13'],
            },
            extensionGroup: '',   // current group key: '9', '11', '13', or ''
            extensionIdx: -1,     // index within the cycle
            // Step 5: set when picker is opened from a tab chord click
            _tabSource: null,     // { chordName, voicingKey, currentFrets, currentPosition, globalMeasureIndex, chordIndex }
            _tabMatchIndex: -1,   // index in results[] that matches currentFrets, or -1
        },

        // Computed
        get pickerRoot() { return (this.picker.value.match(/^([A-G][#b]?)/) || ['',''])[1]; },
        get pickerQuality() { return (this.picker.value.match(/^[A-G][#b]?(.*)/) || ['',''])[1]; },

        get uniqueChords() {
            if (!this.parsed) return [];
            const set = {};
            this.parsed.sections.forEach(s => (s.measures||[]).forEach(m => m.chords.forEach(c => set[c.name] = true)));
            return Object.keys(set);
        },

        get sortedUniqueChords() {
            return [...this.uniqueChords].sort((a, b) => a.localeCompare(b));
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
            return generateShortcode(this.parsed, {
                tempo: this.parsed.tempo,
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
            // Keyboard shortcuts
            document.addEventListener('keydown', (e) => this.handleKeydown(e));

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
            this.$watch('viewMode', (val) => {
                if (val === 'tab' && this.parsed && !this._tabInitDone) this._dispatchTabInit();
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
                    if (this.viewMode !== 'tab') this.viewMode = 'tab';
                });
            });


            // Step 5: Vue tab chord name clicked → open voicing picker
            document.addEventListener('sbn-tab-open-picker', (e) => {
                const { chordName, voicingKey, currentFrets, currentPosition, globalMeasureIndex, chordIndex } = e.detail || {};
                if (!chordName) return;
                // Store tab source context on the picker before opening
                this.voicingPicker._tabSource = {
                    chordName,
                    voicingKey,
                    currentFrets:       currentFrets || null,
                    currentPosition:    currentPosition || 1,
                    globalMeasureIndex,
                    chordIndex,
                };
                this.voicingPicker._tabMatchIndex = -1;
                this.openVoicingPicker(chordName, voicingKey);
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
                                this._emitChordsChanged();
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

            // Phase 2b: tab editor requests chord name picker for an empty bar
            document.addEventListener('sbn-tab-open-chord-picker', (e) => {
                const { globalMeasureIndex, chordIndex, clientX, clientY } = e.detail || {};
                if (globalMeasureIndex === undefined) return;
                const coord = this._globalToLocal(globalMeasureIndex);
                if (!coord) return;

                if (clientX !== undefined && clientY !== undefined) {
                    // Position using the click coordinates — no DOM query needed.
                    // Clamp so picker doesn't overflow viewport (picker is ~300px tall, 272px wide).
                    const ci    = chordIndex ?? 0;
                    const top   = Math.min(clientY + 4, window.innerHeight - 320);
                    const left  = Math.max(8, Math.min(clientX - 40, window.innerWidth - 290));
                    const chords = this.parsed.sections[coord.si].measures[coord.mi].chords;
                    const value  = chords[ci] ? chords[ci].name : '';
                    this.picker = { open: true, top, left, value, si: coord.si, mi: coord.mi, ci };
                    this.$nextTick(() => { if (this.$refs.pickerInput) this.$refs.pickerInput.focus(); });
                } else {
                    this.openChordPicker(coord.si, coord.mi, chordIndex ?? 0, null);
                }
            });
            // Alpine owns parsed.sections, so insert/delete must happen here.
            // After each mutation, _emitChordsChanged() fires sbn-chords-changed,
            // which Vue picks up via patchStructure().

            // Undo/redo delegation from tab editor (when Vue's own stack is empty)
            document.addEventListener('sbn-tab-delegate-undo', () => { if (this.parsed) this.gridUndo(); });
            document.addEventListener('sbn-tab-delegate-redo', () => { if (this.parsed) this.gridRedo(); });

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
                            this._emitChordsChanged();
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

        markDirty() { this.dirty = true; },

        // ── Undo / Redo (grid-interact Phase 2a) ─────────────────────────

        // Snapshot both sections and chordVoicings so chord renames and
        // voicing assignments are fully reversible.
        _snapshotState() {
            return {
                sections:      JSON.parse(JSON.stringify(this.parsed.sections)),
                chordVoicings: JSON.parse(JSON.stringify(this.parsed.chordVoicings || {})),
            };
        },

        /**
         * Restore chordVoicings in-place so Alpine's reactivity system sees
         * individual key changes rather than a full reference replacement.
         * Replacing the object reference (parsed.chordVoicings = newObj) doesn't
         * reliably trigger re-renders on deeply nested x-if/x-html bindings.
         */
        _restoreChordVoicings(snapshot) {
            const cv = this.parsed.chordVoicings;
            // Remove keys not in snapshot
            for (const k of Object.keys(cv)) {
                if (!(k in snapshot)) delete cv[k];
            }
            // Add/update keys from snapshot
            for (const [k, v] of Object.entries(snapshot)) {
                cv[k] = v;
            }
        },

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

        // Wrap any mutating fn: snapshot before & after, push to stack.
        _wrapUndo(label, fn) {
            const before = this._snapshotState();
            fn();
            const after = this._snapshotState();
            // Skip if nothing actually changed
            if (JSON.stringify(before) === JSON.stringify(after)) return;

            // Discard any redo history above the current pointer
            this._undoStack.splice(this._undoPointer + 1);
            this._undoStack.push({ label, before, after });
            if (this._undoStack.length > this._MAX_UNDO) {
                this._undoStack.shift();
            } else {
                this._undoPointer++;
            }
        },

        /**
         * Wrap a structural mutation (insert/delete/move bar, section ops) in an
         * undo entry that captures the Vue tab model snapshot before the change.
         *
         * On undo: both Alpine state and tab model are restored atomically via
         *   sbn-tab-restore-snapshot, bypassing patchStructure().
         * On redo: the structural hint is re-dispatched so patchStructure() can
         *   do a surgical splice, then _emitChordsChanged() triggers the forward
         *   path as if the operation were performed fresh.
         *
         * @param {string}   label - human-readable label
         * @param {Function} fn    - the mutation (should call _emitChordsChanged)
         * @param {object|null} hint - structural hint that was dispatched before
         *   this call (e.g. { action:'deleteBar', measureIndex:3 }). Stored so
         *   redo can re-dispatch it for surgical tab model updates.
         */
        _wrapStructuralUndo(label, fn, hint = null) {
            const tabBefore    = this._requestTabSnapshot();
            const alpineBefore = this._snapshotState();

            fn();

            const alpineAfter = this._snapshotState();

            // Skip if nothing actually changed
            if (JSON.stringify(alpineBefore) === JSON.stringify(alpineAfter)) return;

            this._undoStack.splice(this._undoPointer + 1);
            this._undoStack.push({
                label,
                structural: true,
                structuralHint: hint,
                before: { ...alpineBefore, tabSnapshot: tabBefore },
                after:  alpineAfter,
            });
            if (this._undoStack.length > this._MAX_UNDO) {
                this._undoStack.shift();
            } else {
                this._undoPointer++;
            }
        },

        gridUndo() {
            if (this._undoPointer < 0) { sbnToast('Nothing to undo', 'info'); return; }
            const cmd = this._undoStack[this._undoPointer];
            this.parsed.sections      = JSON.parse(JSON.stringify(cmd.before.sections));
            this._restoreChordVoicings(JSON.parse(JSON.stringify(cmd.before.chordVoicings)));
            // Force Alpine reactivity: in-place key mutations (delete + add) in
            // _restoreChordVoicings don't reliably trigger x-if/x-html re-evaluation.
            // Reassigning the reference ensures all getVoicing() calls re-evaluate.
            this.parsed.chordVoicings = { ...this.parsed.chordVoicings };
            this._undoPointer--;
            this.selection = [];
            this.markDirty();

            if (cmd.structural && cmd.before.tabSnapshot) {
                // Atomic restore — deserializeModel() assigns model directly and
                // sets _restoringSnapshot=true. The _emitChordsChanged() below
                // triggers the sections watcher, but patchStructure() will consume
                // the flag and return early without clobbering the restored model.
                // This also keeps Vue's sections ref in sync (Bug 1 fix).
                document.dispatchEvent(new CustomEvent('sbn-tab-restore-snapshot', {
                    detail: { snapshot: cmd.before.tabSnapshot }
                }));
            }
            // Always fire — structural path needs Vue's sections ref updated too.
            this._emitChordsChanged();
            sbnToast('Undo: ' + cmd.label, 'info');
        },

        gridRedo() {
            if (this._undoPointer >= this._undoStack.length - 1) { sbnToast('Nothing to redo', 'info'); return; }
            this._undoPointer++;
            const cmd = this._undoStack[this._undoPointer];
            this.parsed.sections      = JSON.parse(JSON.stringify(cmd.after.sections));
            this._restoreChordVoicings(JSON.parse(JSON.stringify(cmd.after.chordVoicings)));
            this.parsed.chordVoicings = { ...this.parsed.chordVoicings };
            this.selection = [];
            this.markDirty();

            if (cmd.structural && cmd.structuralHint) {
                // Re-dispatch the surgical hint so patchStructure() can do
                // a correct splice instead of a positional rebuild.
                document.dispatchEvent(new CustomEvent('sbn-tab-structure-request', {
                    detail: { ...cmd.structuralHint, _fromAlpine: true }
                }));
            }
            // Always fire chords-changed for redo — whether structural or not,
            // Vue needs to see the updated sections.
            this._emitChordsChanged();
            sbnToast('Redo: ' + cmd.label, 'info');
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
            reader.onload = (e) => {
                try {
                    const xmlString = e.target.result;
                    const parser = new MusicXMLParser(xmlString);
                    this.parsed = parser.parse();
                    this.tabXml = xmlString; // Preserve raw XML for Phase 7 tab editor
                    this.markDirty();
                    let msg = 'Parsed: ' + this.parsed.measures.length + ' bars';
                    if (this.parsed.melody && this.parsed.melody.length) msg += ', ' + this.parsed.melody.length + ' melody notes';
                    sbnToast(msg, 'success');
                    // Fire tab voicing identification if needed
                    this.identifyTabVoicings();
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
                        this._emitChordsChanged();
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
            if (this._drag && this._drag.si === si && this._drag.mi === li) cls.push('is-dragging');
            if (this._dragOver && this._dragOver.si === si && this._dragOver.mi === li) {
                cls.push('is-drag-target');
                cls.push(this._dragOverPos === 'before' ? 'drop-gap-before' : 'drop-gap-after');
            }
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

        /**
         * Shift all position-specific voicing keys (Name@N.ci) by `delta`
         * for every measure index N >= atGlobalIdx.
         * Call with delta=+1 after insert, delta=-1 after delete.
         * Keys without @N (global defaults) are left untouched.
         */
        _reindexVoicings(atGlobalIdx, delta) {
            const v = this.parsed.chordVoicings;
            if (!v) return;
            const re = /^(.+)@(\d+)\.(\d+)$/;
            const toRename = [];
            for (const key of Object.keys(v)) {
                const m = re.exec(key);
                if (!m) continue;
                const gi = parseInt(m[2]);
                if (gi >= atGlobalIdx) toRename.push({ key, name: m[1], gi, ci: m[3] });
            }
            // Process highest index first for insert (+1), lowest first for delete (-1)
            // to avoid key collisions when shifting by 1.
            toRename.sort((a, b) => delta > 0 ? b.gi - a.gi : a.gi - b.gi);
            for (const { key, name, gi, ci } of toRename) {
                const newKey = name + '@' + (gi + delta) + '.' + ci;
                v[newKey] = v[key];
                delete v[key];
            }
            // Force Alpine to re-evaluate all getVoicing() calls —
            // in-place delete+add of keys doesn't reliably trigger x-if re-renders.
            this.parsed.chordVoicings = { ...this.parsed.chordVoicings };
        },

        getCollapsedPreview(section) {
            const mm = section.measures || [];
            let preview = mm.slice(0,8).map(m => '| ' + m.chords.map(c => c.name).join(' ') + ' ').join('');
            if (mm.length > 8) preview += '| ... +' + (mm.length - 8) + ' more';
            else preview += '|';
            return preview;
        },

        // ── Formatting ────────────────────────────────────────
        formatChordHtml(name) {
            if (!name) return '?';
            if (typeof sbnFormatChord === 'function') return sbnFormatChord(name);
            // Fallback
            const m = name.match(/^([A-G][#b]?)(.*)$/);
            if (!m) return name;
            let root = m[1].replace('#','♯').replace('b','♭');
            let qual = m[2], bass = '';
            const si = qual.indexOf('/');
            if (si >= 0) { bass = '/' + qual.slice(si+1).replace('#','♯').replace('b','♭'); qual = qual.slice(0,si); }
            return root + '<sup>' + qual + '</sup>' + bass;
        },

        sbnStyledChordInner(name) {
            if (typeof sbnFormatChord === 'function') return sbnFormatChord(name);
            return this.formatChordHtml(name);
        },

        renderMiniDiagram(voicing) {
            return sbnRenderDiagramSVG(voicing);
        },

        // ── Selection ─────────────────────────────────────────
        // ── Selection helpers ─────────────────────────────────────────────

        isChordSelected(si, mi, ci) {
            return this.selection.some(s => s.si === si && s.mi === mi && s.ci === ci);
        },

        isMeasureFullySelected(si, mi) {
            const m = this.parsed.sections[si]?.measures[mi];
            if (!m) return false;
            return m.chords.every((_, ci) => this.isChordSelected(si, mi, ci));
        },

        isMeasurePartiallySelected(si, mi) {
            return this.selection.some(s => s.si === si && s.mi === mi);
        },

        getSelectionLevel() {
            if (!this.selection.length) return 'none';
            const measures = new Map();
            this.selection.forEach(s => {
                const key = s.si + '-' + s.mi;
                if (!measures.has(key)) {
                    const m = this.parsed.sections[s.si]?.measures[s.mi];
                    measures.set(key, { total: m?.chords.length || 1, selected: 0 });
                }
                measures.get(key).selected++;
            });
            const allFull = [...measures.values()].every(m => m.selected >= m.total);
            return allFull ? 'measure' : 'chord';
        },

        getSelectedMeasureCoords() {
            const seen = new Set();
            return this.selection.filter(s => {
                const key = s.si + '-' + s.mi;
                if (seen.has(key)) return false;
                seen.add(key);
                return true;
            }).map(s => ({ si: s.si, mi: s.mi }));
        },

        selectWholeMeasure(si, mi, additive) {
            const m = this.parsed.sections[si].measures[mi];
            const chords = m.chords.map((_, ci) => ({ si, mi, ci }));
            if (additive) {
                this.selection = this.selection.filter(s => !(s.si === si && s.mi === mi));
                this.selection.push(...chords);
            } else {
                this.selection = chords;
            }
            this.selectionAnchor = { si, mi };
        },

        deselectMeasure(si, mi) {
            this.selection = this.selection.filter(s => !(s.si === si && s.mi === mi));
        },

        toggleChordSelection(si, mi, ci) {
            const idx = this.selection.findIndex(s => s.si === si && s.mi === mi && s.ci === ci);
            if (idx !== -1) {
                this.selection.splice(idx, 1);
            } else {
                this.selection.push({ si, mi, ci });
            }
            // Anchor unchanged — Ctrl+Click is additive, no range start
        },

        selectMeasureRange(fromSi, fromMi, toSi, toMi) {
            const fromG = this.getGlobalIdx(fromSi, fromMi);
            const toG   = this.getGlobalIdx(toSi, toMi);
            const minG  = Math.min(fromG, toG);
            const maxG  = Math.max(fromG, toG);
            this.selection = [];
            let g = 0;
            this.parsed.sections.forEach((sec, si) => {
                (sec.measures || []).forEach((m, mi) => {
                    if (g >= minG && g <= maxG) {
                        m.chords.forEach((_, ci) => this.selection.push({ si, mi, ci }));
                    }
                    g++;
                });
            });
            // Anchor stays at the original click point — not updated here
        },

        // ── Click handlers ────────────────────────────────────────────────

        handleGridClick(e) {
            // Suppress click that fires immediately after a shift+drag multi-select
            if (this._mouseSelectMoved) {
                this._mouseSelectMoved = false;
                return;
            }
            if (!e.target.closest('.sbn-ve-measure') && !e.target.closest('.sbn-ve-row-resize')) {
                this.selection = [];
                this.selectionAnchor = null;
            }
            if (!e.target.closest('.sbn-ve-chord-picker')) this.picker.open = false;
        },

        handleChordCardClick(event, si, mi, ci) {
            // Close chord picker if open and click is outside it
            if (this.picker.open && !event.target.closest('.sbn-ve-chord-picker')) {
                this.picker.open = false;
            }
            // Suppress click that fires immediately after a shift+drag multi-select
            if (this._mouseSelectMoved) {
                this._mouseSelectMoved = false;
                return;
            }
            const m = this.parsed.sections[si].measures[mi];
            const isSingleChord = m.chords.length === 1;

            // Shift+Click
            if (event.shiftKey) {
                if (this.selectionAnchor) {
                    this.selectMeasureRange(this.selectionAnchor.si, this.selectionAnchor.mi, si, mi);
                } else {
                    this.selectWholeMeasure(si, mi);
                }
                return;
            }

            // Ctrl/Cmd+Click
            if (event.ctrlKey || event.metaKey) {
                if (isSingleChord) {
                    if (this.isMeasureFullySelected(si, mi)) {
                        this.deselectMeasure(si, mi);
                    } else {
                        this.selectWholeMeasure(si, mi, true);
                    }
                } else {
                    this.toggleChordSelection(si, mi, ci);
                }
                return;
            }

            // Plain click
            if (isSingleChord) {
                if (this.selection.length === 1 && this.isChordSelected(si, mi, 0)) {
                    this.selection = [];
                    this.selectionAnchor = null;
                    return;
                }
                this.selection = [{ si, mi, ci: 0 }];
            } else {
                if (this.selection.length === 1 && this.isChordSelected(si, mi, ci)) {
                    this.selection = [];
                    this.selectionAnchor = null;
                    return;
                }
                this.selection = [{ si, mi, ci }];
            }
            this.selectionAnchor = { si, mi };
        },

        // ── Context menu ──────────────────────────────────────────────────

        showChordMenu(event, si, mi, ci) {
            const m = this.parsed.sections[si].measures[mi];

            // Right-clicking an unselected chord: replace selection with it
            if (!this.isChordSelected(si, mi, ci)) {
                if (m.chords.length === 1) {
                    this.selection = [{ si, mi, ci: 0 }];
                } else {
                    this.selection = [{ si, mi, ci }];
                }
                this.selectionAnchor = { si, mi };
            }

            const level    = this.getSelectionLevel();
            const measures = this.getSelectedMeasureCoords();

            const state = {
                selectionLevel: level,
                selectionCount: level === 'measure' ? measures.length : this.selection.length,
                hasClipboard:   !!this.clipboard,
                chordCount:     m.chords.length,
                canAddChord:    m.chords.length < 4,
                canRemoveChord: m.chords.length > 1,
                clickedChord:   ci,
            };

            const items = buildMenuItems('leadsheet', state);
            showContextMenu(event, items, (actionId) => {
                this.handleContextAction(actionId, si, mi, ci);
            });
        },

        handleContextAction(actionId, si, mi, ci) {
            switch (actionId) {
                case OPS.RENAME_CHORD:
                    this.openChordPicker(si, mi, ci, null);
                    break;
                case OPS.CHANGE_VOICING: {
                    const m  = this.parsed.sections[si].measures[mi];
                    const gi = this.getGlobalIdx(si, mi);
                    this.openVoicingPicker(m.chords[ci].name, m.chords[ci].name + '@' + gi + '.' + ci);
                    break;
                }
                case OPS.ADD_CHORD:
                    this.addChord(si, mi);
                    break;
                case OPS.REMOVE_CHORD:
                    this.removeChord(si, mi);
                    break;
                case OPS.INSERT_BAR_AFTER:
                    this.insertMeasureAfter(si, mi);
                    break;
                case OPS.INSERT_BAR_BEFORE:
                    this.insertMeasureBefore(si, mi);
                    break;
                case OPS.DELETE_BAR:
                    this.deleteMeasure(si, mi);
                    break;
                case OPS.TOGGLE_REPEAT:
                    this.toggleRepeat(this.getGlobalIdx(si, mi));
                    break;
                case OPS.COPY:
                    this.doCopy();
                    break;
                case OPS.CUT:
                    this.doCut();
                    break;
                case OPS.PASTE:
                    this.doPaste();
                    break;
                case OPS.CLEAR_CHORDS:
                    this.doClearChords();
                    break;
                case OPS.DELETE_SELECTION:
                    this.doDeleteSelection();
                    break;
                case OPS.INSERT_N_BEFORE:
                    this.doInsertNBefore();
                    break;
            }
        },

        // ── Drag-to-reorder (within section) ─────────────────────────────

        handleDragStart(event, si, mi) {
            // Don't start a drag if a chord-name or diagram was the mousedown target
            if (event.target.closest('.sbn-ve-chord-name, .sbn-ve-chord-diagram, .sbn-ve-chord-picker')) {
                event.preventDefault();
                return;
            }
            this._drag = { si, mi };
            this._dragOver = null;
            this._dragOverPos = null;
            this._dragGhost = null;
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('text/plain', si + ',' + mi);

            // Custom drag ghost — clone positioned at the measure's actual location
            // so the browser can paint it before setDragImage captures it.
            // Must set explicit width+height — flex children have no intrinsic size.
            const el = event.currentTarget;
            const rect = el.getBoundingClientRect();
            const clone = el.cloneNode(true);
            clone.style.cssText = [
                'position:fixed',
                'top:' + rect.top + 'px',
                'left:' + rect.left + 'px',
                'width:' + rect.width + 'px',
                'height:' + rect.height + 'px',
                'pointer-events:none',
                'transform-origin:top left',
                'transform:rotate(2deg) scale(1.05)',
                'box-shadow:0 16px 40px rgba(0,0,0,0.35)',
                'border-radius:6px',
                'background:var(--clr-bg-card,#fff)',
                'border:2px solid var(--clr-accent,#1976d2)',
                'z-index:99999',
                'overflow:hidden',
            ].join(';');
            clone.querySelectorAll('.sbn-ve-drop-before,.sbn-ve-drop-after').forEach(n => n.remove());
            // Strip all Alpine reactive attributes so Alpine doesn't try to evaluate
            // x-for / x-html / :class etc. on the clone outside its scope context.
            [clone, ...clone.querySelectorAll('*')].forEach(el => {
                [...el.attributes].forEach(attr => {
                    if (attr.name.startsWith('x-') || attr.name.startsWith(':') || attr.name.startsWith('@')) {
                        el.removeAttribute(attr.name);
                    }
                });
            });
            document.body.appendChild(clone);
            this._dragGhost = clone;

            event.dataTransfer.setDragImage(clone, event.clientX - rect.left, event.clientY - rect.top);

            // Two rAFs — some browsers need two frames to capture the image
            requestAnimationFrame(() => requestAnimationFrame(() => {
                if (this._dragGhost) { this._dragGhost.remove(); this._dragGhost = null; }
            }));
        },

        handleDragOver(event, si, mi) {
            if (!this._drag) return;
            // Only allow drop within same section
            if (si !== this._drag.si) { event.dataTransfer.dropEffect = 'none'; return; }
            // Skip drop onto self
            if (si === this._drag.si && mi === this._drag.mi) {
                this._dragOver = null;
                return;
            }
            event.dataTransfer.dropEffect = 'move';
            // Determine before/after based on cursor X relative to measure centre
            const rect = event.currentTarget.getBoundingClientRect();
            const pos = event.clientX < rect.left + rect.width / 2 ? 'before' : 'after';
            this._dragOver = { si, mi };
            this._dragOverPos = pos;
        },

        handleDragLeave(event, si, mi) {
            // Only clear if leaving to outside the measure (not into a child)
            if (!event.currentTarget.contains(event.relatedTarget)) {
                if (this._dragOver && this._dragOver.si === si && this._dragOver.mi === mi) {
                    this._dragOver = null;
                    this._dragOverPos = null;
                }
            }
        },

        handleDrop(event, toSi, toMi) {
            if (!this._drag) return;
            const { si: fromSi, mi: fromMi } = this._drag;
            if (fromSi !== toSi) { this.handleDragEnd(); return; } // cross-section: ignore
            if (fromMi === toMi) { this.handleDragEnd(); return; } // same measure: no-op

            // Resolve final insertion index based on before/after
            let insertAt = this._dragOverPos === 'before' ? toMi : toMi + 1;
            // Adjust for the removal of the source measure
            if (fromMi < insertAt) insertAt--;

            this.moveMeasure(fromSi, fromMi, insertAt);
            this.handleDragEnd();
        },

        handleDragEnd() {
            this._drag = null;
            this._dragOver = null;
            this._dragOverPos = null;
        },

        moveMeasure(si, fromMi, toMi) {
            if (fromMi === toMi) return;
            this._wrapStructuralUndo('Move bar', () => {
            const sec = this.parsed.sections[si];
            const [moved] = sec.measures.splice(fromMi, 1);
            sec.measures.splice(toMi, 0, moved);

            // Rebuild lineBreaks to match new measure count (simplest: reset to current barsPerRow)
            if (sec.lineBreaks && sec.lineBreaks.length) {
                const total = sec.measures.length;
                sec.lineBreaks = [];
                for (let i = 0; i < total; i += this.barsPerRow) {
                    sec.lineBreaks.push(Math.min(this.barsPerRow, total - i));
                }
            }

            // Update selection to follow the moved measure
            this.selection = this.selection.map(s => {
                if (s.si !== si) return s;
                if (s.mi === fromMi) return { ...s, mi: toMi };
                // Shift other measures in the affected range
                if (fromMi < toMi && s.mi > fromMi && s.mi <= toMi) return { ...s, mi: s.mi - 1 };
                if (fromMi > toMi && s.mi >= toMi && s.mi < fromMi) return { ...s, mi: s.mi + 1 };
                return s;
            });

            this.markDirty();
            this._emitChordsChanged();
            });
        },

        // ── Shift+drag mouse batch selection ─────────────────────────────

        handleMeasureMouseDown(event, si, mi) {
            // Only activate shift+drag — plain mousedown is handled by chord card click
            if (!event.shiftKey) return;
            // Prevent text selection during drag
            event.preventDefault();
            this._mouseSelectActive = true;
            this._mouseSelectAnchor = { si, mi };
            // Start selection from anchor
            this.selectWholeMeasure(si, mi);
            this.selectionAnchor = { si, mi };
        },

        handleMeasureMouseEnter(event, si, mi) {
            if (!this._mouseSelectActive || !this._mouseSelectAnchor) return;
            this.selectMeasureRange(
                this._mouseSelectAnchor.si, this._mouseSelectAnchor.mi,
                si, mi
            );
        },

        handleGridMouseUp(event) {
            if (this._mouseSelectActive) {
                this._mouseSelectActive = false;
                this._mouseSelectAnchor = null;
                // Always suppress the click that follows mouseup, whether or not
                // the drag actually moved across measures
                this._mouseSelectMoved = true;
            }
        },

        // ── Clipboard ─────────────────────────────────────────
        getSelectedData() {
            const sorted = this.getSelectedMeasureCoords()
                .sort((a, b) => this.getGlobalIdx(a.si, a.mi) - this.getGlobalIdx(b.si, b.mi));
            return sorted.map(s => JSON.parse(JSON.stringify(this.parsed.sections[s.si].measures[s.mi])));
        },

        doCopy() {
            if (!this.selection.length) return;
            this.clipboard = { mode: 'copy', measures: this.getSelectedData() };
            sbnToast(this.clipboard.measures.length + ' bar(s) copied', 'success');
        },

        doCut() {
            if (!this.selection.length) return;
            this.clipboard = { mode: 'cut', measures: this.getSelectedData() };
            const cutCount = this.clipboard.measures.length;
            this._wrapStructuralUndo('Cut', () => {
            const sorted = this.getSelectedMeasureCoords()
                .sort((a, b) => this.getGlobalIdx(b.si, b.mi) - this.getGlobalIdx(a.si, a.mi));
            sorted.forEach(sel => {
                const sec = this.parsed.sections[sel.si];
                if (sec.measures.length > 1) {
                    const delGi = this.getGlobalIdx(sel.si, sel.mi);
                    const chordCount = (sec.measures[sel.mi]?.chords || []).length;
                    const v = this.parsed.chordVoicings || {};
                    for (let ci = 0; ci < chordCount; ci++) {
                        delete v[sec.measures[sel.mi].chords[ci]?.name + '@' + delGi + '.' + ci];
                    }
                    sec.measures.splice(sel.mi, 1);
                    this._reindexVoicings(delGi + 1, -1);
                }
            });
            this.selection = [];
            this.markDirty();
            this._emitChordsChanged();
            });
            sbnToast(cutCount + ' bar(s) cut', 'success');
        },

        doPaste() {
            if (!this.clipboard || !this.clipboard.measures.length) return;
            this._wrapStructuralUndo('Paste', () => {
            let targetSi, targetMi;
            const measures = this.getSelectedMeasureCoords();
            if (measures.length) {
                // Insert after the last selected measure (highest global index)
                const sorted = measures.sort((a, b) => this.getGlobalIdx(b.si, b.mi) - this.getGlobalIdx(a.si, a.mi));
                targetSi = sorted[0].si;
                targetMi = sorted[0].mi + 1;
            } else {
                // Nothing selected: append to end of last section
                targetSi = this.parsed.sections.length - 1;
                targetMi = this.parsed.sections[targetSi].measures.length;
            }
            const newMeasures = JSON.parse(JSON.stringify(this.clipboard.measures));
            const sec = this.parsed.sections[targetSi];
            for (let i = 0; i < newMeasures.length; i++) {
                const insGi = this.getGlobalIdx(targetSi, targetMi + i);
                sec.measures.splice(targetMi + i, 0, newMeasures[i]);
                this._reindexVoicings(insGi, +1);
            }
            // Select the newly pasted measures
            this.selection = [];
            newMeasures.forEach((m, i) => {
                m.chords.forEach((_, ci) => this.selection.push({ si: targetSi, mi: targetMi + i, ci }));
            });
            this.markDirty();
            this._emitChordsChanged();
            sbnToast(newMeasures.length + ' bar(s) pasted', 'success');
            });
        },

        doClearChords() {
            this._wrapUndo('Clear chords', () => {
            const tsBeats = parseInt((this.parsed.timeSignature || '4/4').split('/')[0]) || 4;
            this.getSelectedMeasureCoords().forEach(sel => {
                const m = this.parsed.sections[sel.si].measures[sel.mi];
                m.chords = [{ name: '%', beats: tsBeats }];
            });
            this.selection = [];
            this.markDirty();
            this._emitChordsChanged();
            });
        },

        doDeleteSelection() {
            this._wrapStructuralUndo('Delete bars', () => {
            const sorted = this.getSelectedMeasureCoords()
                .sort((a, b) => this.getGlobalIdx(b.si, b.mi) - this.getGlobalIdx(a.si, a.mi));
            sorted.forEach(sel => {
                const sec = this.parsed.sections[sel.si];
                if (sec.measures.length > 1) {
                    const delGi = this.getGlobalIdx(sel.si, sel.mi);
                    const chordCount = (sec.measures[sel.mi]?.chords || []).length;
                    const v = this.parsed.chordVoicings || {};
                    for (let ci = 0; ci < chordCount; ci++) {
                        delete v[sec.measures[sel.mi].chords[ci]?.name + '@' + delGi + '.' + ci];
                    }
                    sec.measures.splice(sel.mi, 1);
                    this._reindexVoicings(delGi + 1, -1);
                }
            });
            this.selection = [];
            this.markDirty();
            this._emitChordsChanged();
            });
        },

        doInsertNBefore() {
            this._wrapStructuralUndo('Insert bars', () => {
            const sorted = this.getSelectedMeasureCoords()
                .sort((a, b) => this.getGlobalIdx(a.si, a.mi) - this.getGlobalIdx(b.si, b.mi));
            const first   = sorted[0];
            const count   = sorted.length;
            const tsBeats = parseInt((this.parsed.timeSignature || '4/4').split('/')[0]) || 4;
            const sec     = this.parsed.sections[first.si];
            const refChord = sec.measures[first.mi].chords[0].name;
            for (let i = 0; i < count; i++) {
                sec.measures.splice(first.mi, 0, { chords: [{ name: refChord, beats: tsBeats }] });
            }
            this.selection = [];
            this.markDirty();
            this._emitChordsChanged();
            });
        },

        // ── Keyboard shortcuts ────────────────────────────────
        handleKeydown(e) {
            if (!this.parsed) return;
            if (['INPUT','TEXTAREA','SELECT'].includes(e.target.tagName)) return;
            const isCtrl = e.ctrlKey || e.metaKey;
            // Undo / redo — only when tab editor is not focused
            if (isCtrl && e.key === 'z' && !e.shiftKey && this.viewMode !== 'tab') {
                e.preventDefault(); this.gridUndo(); return;
            }
            if (isCtrl && e.key === 'z' && e.shiftKey && this.viewMode !== 'tab') {
                e.preventDefault(); this.gridRedo(); return;
            }
            if (isCtrl && e.key === 'c' && this.selection.length) { e.preventDefault(); this.doCopy(); }
            else if (isCtrl && e.key === 'x' && this.selection.length) { e.preventDefault(); this.doCut(); }
            else if (isCtrl && e.key === 'v' && this.clipboard) { e.preventDefault(); this.doPaste(); }
            else if (isCtrl && e.key === 'a') {
                e.preventDefault();
                this.selection = [];
                this.parsed.sections.forEach((sec, si) =>
                    (sec.measures || []).forEach((m, mi) =>
                        m.chords.forEach((_, ci) => this.selection.push({ si, mi, ci }))
                    )
                );
            }
            else if (e.key === 'Escape') { this.selection = []; this.selectionAnchor = null; }
            else if ((e.key === 'Delete' || e.key === 'Backspace') && this.selection.length) {
                e.preventDefault();
                this.doDeleteSelection();
            }
        },

        // ── Section management ────────────────────────────────
        addSection() {
            this._wrapStructuralUndo('Add section', () => {
            const used = this.parsed.sections.map(s => s.id);
            let letter = 'B';
            'ABCDEFGHIJKLMNOPQRSTUVWXYZ'.split('').some(l => { if (!used.includes(l)) { letter = l; return true; } });
            const tsBeats = parseInt((this.parsed.timeSignature||'4/4').split('/')[0]) || 4;
            this.parsed.sections.push({ id: letter, name: letter, measures: [{chords:[{name:'Cmaj7',beats:tsBeats}]}], rhythmSlug: null, lineBreaks: null, tonality: '' });
            this.markDirty();
            this._emitChordsChanged();
            });
        },

        deleteSection(si) {
            if (this.parsed.sections.length <= 1) return;
            if (!confirm('Delete section ' + this.parsed.sections[si].id + '?')) return;
            this._wrapStructuralUndo('Delete section', () => {
            const measures = this.parsed.sections[si].measures || [];
            const target = si > 0 ? si - 1 : 1;
            this.parsed.sections[target].measures = (this.parsed.sections[target].measures || []).concat(measures);
            this.parsed.sections.splice(si, 1);
            this.collapsedSections = {};
            this.markDirty();
            this._emitChordsChanged();
            });
        },

        splitSection(si, ri) {
            const sec = this.parsed.sections[si];
            const layout = this.getRowLayout(si);
            if (ri >= layout.length - 1) {
                this.addSection();
                return;
            }
            this._wrapStructuralUndo('Split section', () => {
            const cutPoint = layout[ri].indices[layout[ri].indices.length - 1] + 1;
            const used = this.parsed.sections.map(s => s.id);
            let letter = 'B';
            'ABCDEFGHIJKLMNOPQRSTUVWXYZ'.split('').some(l => { if (!used.includes(l)) { letter = l; return true; } });
            const keepMeasures = sec.measures.slice(0, cutPoint);
            const moveMeasures = sec.measures.slice(cutPoint);
            let keepBreaks = null, moveBreaks = null;
            if (sec.lineBreaks) { keepBreaks = sec.lineBreaks.slice(0, ri+1); moveBreaks = sec.lineBreaks.slice(ri+1); }
            sec.measures = keepMeasures;
            sec.lineBreaks = keepBreaks;
            this.parsed.sections.splice(si+1, 0, { id: letter, name: letter, measures: moveMeasures, rhythmSlug: sec.rhythmSlug, lineBreaks: moveBreaks, tonality: '' });
            this.markDirty();
            this._emitChordsChanged();
            });
            sbnToast('Section split', 'success');
        },

        // ── Measure management ────────────────────────────────
        addMeasureToSection(si) {
            this._wrapStructuralUndo('Add bar', () => {
            const sec = this.parsed.sections[si];
            const tsBeats = parseInt((this.parsed.timeSignature||'4/4').split('/')[0]) || 4;
            sec.measures.push({ chords: [{ name: '', beats: tsBeats }] });
            if (sec.lineBreaks && sec.lineBreaks.length) sec.lineBreaks[sec.lineBreaks.length-1] += 1;
            this.markDirty();
            this._emitChordsChanged();
            });
        },

        insertMeasureAfter(si, mi, _fromListener = false) {
            const hint = { action: 'insertBarAfter', measureIndex: this.getGlobalIdx(si, mi) };
            if (!_fromListener) {
                document.dispatchEvent(new CustomEvent('sbn-tab-structure-request', {
                    detail: { ...hint, _fromAlpine: true }
                }));
            }
            this._wrapStructuralUndo('Insert bar after', () => {
            const sec = this.parsed.sections[si];
            const tsBeats = parseInt((this.parsed.timeSignature||'4/4').split('/')[0]) || 4;
            sec.measures.splice(mi+1, 0, { chords: [{ name: '', beats: tsBeats }] });
            if (sec.lineBreaks && sec.lineBreaks.length) {
                let pos = 0;
                for (let ri=0; ri<sec.lineBreaks.length; ri++) {
                    if (mi < pos + sec.lineBreaks[ri]) { sec.lineBreaks[ri] += 1; break; }
                    pos += sec.lineBreaks[ri];
                }
            }
            this._reindexVoicings(hint.measureIndex + 1, +1);
            this.markDirty();
            this._emitChordsChanged();
            }, hint);
        },

        insertMeasureBefore(si, mi, _fromListener = false) {
            const hint = { action: 'insertBarBefore', measureIndex: this.getGlobalIdx(si, mi) };
            if (!_fromListener) {
                document.dispatchEvent(new CustomEvent('sbn-tab-structure-request', {
                    detail: { ...hint, _fromAlpine: true }
                }));
            }
            this._wrapStructuralUndo('Insert bar before', () => {
            const sec = this.parsed.sections[si];
            const tsBeats = parseInt((this.parsed.timeSignature || '4/4').split('/')[0]) || 4;
            sec.measures.splice(mi, 0, { chords: [{ name: '', beats: tsBeats }] });
            if (sec.lineBreaks && sec.lineBreaks.length) {
                let pos = 0;
                for (let ri = 0; ri < sec.lineBreaks.length; ri++) {
                    if (mi < pos + sec.lineBreaks[ri]) { sec.lineBreaks[ri] += 1; break; }
                    pos += sec.lineBreaks[ri];
                }
            }
            this._reindexVoicings(hint.measureIndex, +1);
            this.markDirty();
            this._emitChordsChanged();
            }, hint);
        },

        deleteMeasure(si, mi, _fromListener = false) {
            const hint = { action: 'deleteBar', measureIndex: this.getGlobalIdx(si, mi) };
            if (!_fromListener) {
                document.dispatchEvent(new CustomEvent('sbn-tab-structure-request', {
                    detail: { ...hint, _fromAlpine: true }
                }));
            }
            this._wrapStructuralUndo('Delete bar', () => {
            const sec = this.parsed.sections[si];
            if (sec.measures.length <= 1) return;
            if (sec.lineBreaks && sec.lineBreaks.length) {
                let pos = 0;
                for (let ri=0; ri<sec.lineBreaks.length; ri++) {
                    if (mi < pos + sec.lineBreaks[ri]) { sec.lineBreaks[ri] -= 1; if (sec.lineBreaks[ri]<=0) sec.lineBreaks.splice(ri,1); break; }
                    pos += sec.lineBreaks[ri];
                }
            }
            // Drop the deleted bar's own instance key, then shift everything after it down.
            const delGi = hint.measureIndex;
            const chordCount = (sec.measures[mi]?.chords || []).length;
            const v = this.parsed.chordVoicings || {};
            for (let ci = 0; ci < chordCount; ci++) {
                delete v[sec.measures[mi].chords[ci]?.name + '@' + delGi + '.' + ci];
            }
            sec.measures.splice(mi, 1);
            this._reindexVoicings(delGi + 1, -1);
            this.markDirty();
            this._emitChordsChanged();
            }, hint);
        },

        addChord(si, mi) {
            this._wrapUndo('Add chord', () => {
            const m = this.parsed.sections[si].measures[mi];
            const tsBeats = parseInt((this.parsed.timeSignature||'4/4').split('/')[0]) || 4;
            m.chords.push({ name: m.chords[m.chords.length-1].name, beats: tsBeats/(m.chords.length+1) });
            const beatsEach = tsBeats / m.chords.length;
            m.chords.forEach(c => c.beats = beatsEach);
            this.markDirty();
            this._emitChordsChanged();
            });
        },

        removeChord(si, mi) {
            this._wrapUndo('Remove chord', () => {
            const m = this.parsed.sections[si].measures[mi];
            if (m.chords.length <= 1) return;
            m.chords.pop();
            const tsBeats = parseInt((this.parsed.timeSignature||'4/4').split('/')[0]) || 4;
            const beatsEach = tsBeats / m.chords.length;
            m.chords.forEach(c => c.beats = beatsEach);
            this.markDirty();
            this._emitChordsChanged();
            });
        },

        _emitChordsChanged() {
            document.dispatchEvent(new CustomEvent('sbn-chords-changed', {
                detail: { sections: this.parsed.sections }
            }));
        },

        toggleRepeat(gi) {
            this._wrapUndo('Toggle repeat', () => {
            if (!this.parsed.repeatMarkers) this.parsed.repeatMarkers = {};
            const rm  = Object.assign({}, this.parsed.repeatMarkers);
            const cur = rm[gi] || {};
            if (!cur.start && !cur.end)       rm[gi] = { start: true };
            else if (cur.start && !cur.end)   rm[gi] = { end: true };
            else if (!cur.start && cur.end)   rm[gi] = { start: true, end: true };
            else                              delete rm[gi];
            this.parsed.repeatMarkers = rm;   // replace reference — triggers Alpine reactivity
            this.markDirty();
            });
        },

        // ── Row resize ────────────────────────────────────────
        ensureLineBreaks(si) {
            const sec = this.parsed.sections[si];
            if (sec.lineBreaks && sec.lineBreaks.length) return;
            const total = (sec.measures||[]).length;
            sec.lineBreaks = [];
            for (let i=0;i<total;i+=this.barsPerRow) sec.lineBreaks.push(Math.min(this.barsPerRow, total-i));
        },

        rowGrow(si, ri) {
            this.ensureLineBreaks(si);
            const lb = this.parsed.sections[si].lineBreaks;
            if (ri >= lb.length - 1) return;
            if (lb[ri+1] <= 1) { lb[ri] += 1; lb.splice(ri+1, 1); }
            else { lb[ri] += 1; lb[ri+1] -= 1; }
            this.markDirty();
        },

        rowShrink(si, ri) {
            this.ensureLineBreaks(si);
            const lb = this.parsed.sections[si].lineBreaks;
            if (lb[ri] <= 1) return;
            lb[ri] -= 1;
            if (ri+1 < lb.length) lb[ri+1] += 1;
            else lb.push(1);
            this.markDirty();
        },

        clearAllLineBreaks() {
            if (this.parsed && this.parsed.sections) {
                this.parsed.sections.forEach(sec => { delete sec.lineBreaks; });
            }
        },

        // ── Chord Picker ──────────────────────────────────────
        openChordPicker(si, mi, ci, el) {
            const chord = this.parsed.sections[si].measures[mi].chords[ci];
            let top, left;
            if (el) {
                const rect = el.getBoundingClientRect();
                top  = Math.min(rect.bottom + 4, window.innerHeight - 300);
                left = Math.max(8, Math.min(rect.left - 40, window.innerWidth - 290));
            } else {
                // Called without an element — try chord grid measure first,
                // then fall back to tab editor measure (data-measure attribute).
                const gi = this.getGlobalIdx(si, mi);
                const measureEl =
                    document.querySelector('.sbn-ve-measure[data-si="' + si + '"][data-mi="' + mi + '"]') ||
                    document.querySelector('.sbn-tab-measure[data-measure="' + gi + '"]');
                if (measureEl) {
                    const rect = measureEl.getBoundingClientRect();
                    top  = Math.min(rect.bottom + 4, window.innerHeight - 300);
                    left = Math.max(8, Math.min(rect.left - 40, window.innerWidth - 290));
                } else {
                    top  = Math.max(8, (window.innerHeight - 300) / 2);
                    left = Math.max(8, (window.innerWidth  - 290) / 2);
                }
            }
            this.picker = { open: true, top, left, value: chord.name, si, mi, ci };
            this.$nextTick(() => { if (this.$refs.pickerInput) this.$refs.pickerInput.focus(); });
        },

        setPickerRoot(r) {
            const curQual = (this.picker.value.match(/^[A-G][#b]?(.*)/) || ['','maj7'])[1] || 'maj7';
            this.picker.value = r + curQual;
        },

        setPickerQuality(q) {
            const curRoot = (this.picker.value.match(/^([A-G][#b]?)/) || ['','C'])[1] || 'C';
            this.picker.value = curRoot + q;
        },

        applyChordPicker() {
            const name = this.picker.value.trim();
            if (!name || !name.match(/^[A-G]/)) return;

            const { si, mi, ci } = this.picker;
            this._wrapUndo('Rename chord', () => {
            const oldName = this.parsed.sections[si].measures[mi].chords[ci].name;

            // Update the chord name in the grid
            this.parsed.sections[si].measures[mi].chords[ci].name = name;

            // Migrate chordVoicings if the name actually changed
            if (name !== oldName && this.parsed.chordVoicings) {
                const gi = this.getGlobalIdx(si, mi);

                // 1. Instance-specific key: "OldName@gi.ci"
                const instanceKey = oldName + '@' + gi + '.' + ci;
                if (this.parsed.chordVoicings[instanceKey] !== undefined) {
                    this.parsed.chordVoicings[name + '@' + gi + '.' + ci] = this.parsed.chordVoicings[instanceKey];
                    delete this.parsed.chordVoicings[instanceKey];
                }

                // 2. Global key: "OldName" — only migrate if this chord name no longer
                //    exists anywhere else in the leadsheet (otherwise the global voicing
                //    still belongs to those remaining instances)
                if (this.parsed.chordVoicings[oldName] !== undefined) {
                    const stillExists = this.parsed.sections.some(s =>
                        (s.measures || []).some(m =>
                            m.chords.some(c => c.name === oldName)
                        )
                    );
                    if (!stillExists) {
                        // No more instances of old name — re-key the global voicing
                        if (this.parsed.chordVoicings[name] === undefined) {
                            this.parsed.chordVoicings[name] = this.parsed.chordVoicings[oldName];
                        }
                        delete this.parsed.chordVoicings[oldName];
                    }
                }
            }

            this.markDirty();
            this._emitChordsChanged();
            });
            this.picker.open = false;
        },

        // ── Voicing Picker (context panel) ─────────────────────
        parseChordForPicker(chordName) {
            // Client-side chord parsing matching server's parseChordName
            const m = chordName.match(/^([A-G][#b]?)(.*?)(?:\/([A-G][#b]?))?$/);
            if (!m) return { root: '', quality: 'maj', extension: '', bassNote: '', inversion: '' };
            let root = m[1], body = m[2] || '', bassNote = m[3] || '';

            // Shorthand map (subset — covers common cases)
            const shorthands = {
                '9':['dom7','9'],'11':['dom7','11'],'13':['dom7','13'],
                '7b9':['dom7','b9'],'7#9':['dom7','#9'],'7b13':['dom7','b13'],'7#11':['dom7','#11'],
                'maj9':['maj7','9'],'maj11':['maj7','11'],'maj13':['maj7','13'],'maj7#11':['maj7','#11'],
                'M9':['maj7','9'],'M11':['maj7','11'],'M13':['maj7','13'],
                'Δ9':['maj7','9'],'△9':['maj7','9'],
                'm9':['m7','9'],'m11':['m7','11'],'m13':['m7','13'],
                'min9':['m7','9'],'min11':['m7','11'],'min13':['m7','13'],
                '-9':['m7','9'],'-11':['m7','11'],'-13':['m7','13'],
                'ø9':['m7b5','9'],
            };

            // Extract parenthesised extension: m7(b9) → m7 + b9
            let extension = '';
            const parenMatch = body.match(/^(.+?)\(([^)]+)\)$/);
            if (parenMatch) { body = parenMatch[1]; extension = parenMatch[2]; }

            // Shorthand check (only if no extension already)
            if (!extension && shorthands[body]) {
                return { root, quality: shorthands[body][0], extension: shorthands[body][1], bassNote, inversion: '' };
            }

            // Quality map (longest-first to prevent prefix false matches)
            const qualMap = [
                ['m7b5','m7b5'],['m7♭5','m7b5'],['ø7','m7b5'],['ø','m7b5'],
                ['mMaj7','mMaj7'],['mmaj7','mMaj7'],['mM7','mMaj7'],
                ['maj7','maj7'],['M7','maj7'],['Δ7','maj7'],['△7','maj7'],
                ['maj6','maj6'],['M6','maj6'],['6','maj6'],
                ['min7','m7'],['m7','m7'],['min6','m6'],['m6','m6'],
                ['dom7','dom7'],['7','dom7'],['7sus4','7sus4'],
                ['aug7','aug7'],['aug','aug'],['+7','aug7'],['+','aug'],
                ['dim7','o7'],['o7','o7'],['°7','o7'],['dim','dim'],['°','dim'],
                ['sus4','sus4'],['sus2','sus2'],['sus','sus4'],['add9','add9'],
                ['maj','maj'],['min','min'],['m','min'],['-','min'],
            ];
            // Case-sensitive patterns that must NOT match case-insensitively
            // (prevents 'm7' matching 'M7' → maj7, 'm6' matching 'M6' → maj6, etc.)
            const caseSensitiveOnly = new Set(['M','M7','M6']);

            // Pass 1: exact match (case-sensitive, then case-insensitive with guards)
            for (const [pat, canon] of qualMap) {
                if (body === pat) {
                    return { root, quality: canon, extension, bassNote, inversion: '' };
                }
                if (!caseSensitiveOnly.has(pat) && body.toLowerCase() === pat.toLowerCase()) {
                    return { root, quality: canon, extension, bassNote, inversion: '' };
                }
            }

            // Pass 2: progressive decomposition (only if no extension already extracted)
            if (!extension) {
                for (const [pat, canon] of qualMap) {
                    if (body.startsWith(pat)) {
                        const trail = body.slice(pat.length);
                        if (trail && /^[b#]?\d/.test(trail)) {
                            return { root, quality: canon, extension: trail, bassNote, inversion: '' };
                        }
                    }
                }
            }

            if (body === '') return { root, quality: 'maj', extension, bassNote, inversion: '' };
            return { root, quality: body, extension, bassNote, inversion: '' };
        },

        inferInversion(bassNote, root, quality) {
            if (!bassNote) return '';
            const semi = {'C':0,'C#':1,'Db':1,'D':2,'D#':3,'Eb':3,'E':4,'F':5,'F#':6,'Gb':6,'G':7,'G#':8,'Ab':8,'A':9,'A#':10,'Bb':10,'B':11};
            const rootS = semi[root], bassS = semi[bassNote];
            if (rootS === undefined || bassS === undefined) return '';
            const interval = ((bassS - rootS) + 12) % 12;
            // Common chord tone intervals → DB inversion value
            // 3/4 = minor/major 3rd → 1st inversion, 7 = 5th → 2nd inversion, 10/11 = b7/7 → 3rd inversion
            const inv = { 3:'inv1', 4:'inv1', 7:'inv2', 10:'inv3', 11:'inv3' };
            return inv[interval] || '';
        },

        async openVoicingPicker(chordName, voicingKey) {
            const lookupKey = voicingKey || chordName;
            const parsed = this.parseChordForPicker(chordName);
            const inversion = this.inferInversion(parsed.bassNote, parsed.root, parsed.quality);

            this.voicingPicker = {
                ...this.voicingPicker,
                open: true, chordName, voicingKey,
                loading: true, results: [],
                hasExisting: !!(this.parsed.chordVoicings && (this.parsed.chordVoicings[lookupKey] || this.parsed.chordVoicings[chordName])),
                root: parsed.root, quality: parsed.quality,
                extension: parsed.extension, bassNote: parsed.bassNote,
                inversion: inversion || '',
                activeFilters: {
                    voicing_category: 'all', root_string: 'all',
                    extension: parsed.extension || '',
                    inversion: inversion ? inversion : 'all',
                },
                extensionGroup: '', extensionIdx: -1,
            };

            // Determine initial extension group/idx
            if (parsed.extension) {
                for (const [grp, cycle] of Object.entries(this.voicingPicker.extensionCycles)) {
                    const idx = cycle.indexOf(parsed.extension);
                    if (idx !== -1) {
                        this.voicingPicker.extensionGroup = grp;
                        this.voicingPicker.extensionIdx = idx;
                        break;
                    }
                }
            }

            await this.fetchVoicings();
        },

        async fetchVoicings() {
            const vp = this.voicingPicker;
            vp.loading = true;
            try {
                const params = new URLSearchParams({
                    root: vp.root, quality: vp.quality,
                    extension: vp.activeFilters.extension || '',
                    inversion: vp.activeFilters.inversion || 'all',
                    voicing_category: vp.activeFilters.voicing_category || 'all',
                    root_string: vp.activeFilters.root_string || 'all',
                    bass_note: vp.bassNote || '',
                });
                const resp = await fetch('/api/admin/leadsheets/search-voicings-advanced?' + params.toString(), {
                    headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'Accept': 'application/json' }
                });
                const data = await resp.json();
                if (data.success && Array.isArray(data.results)) {
                    vp.results = data.results.map(v => {
                        let frets = '', pos = parseInt(v.start_fret) || 1;
                        if (v.diagram_data) {
                            try {
                                const dd = typeof v.diagram_data === 'string' ? JSON.parse(v.diagram_data) : v.diagram_data;
                                frets = this.diagramDataToFrets(dd);
                            } catch(e) {}
                        }
                        return { frets, position: pos, voicing_category: v.voicing_category, inversion: v.inversion, root_string: v.root_string, popularity: v.popularity || 0 };
                    }).filter(v => v.frets);
                }
                if (data.filters) vp.filters = data.filters;
            } catch (e) { console.warn('[SBN Editor] Voicing search failed:', e); }
            vp.loading = false;

            // Step 5: if opened from tab, find which result matches the current tab frets
            if (vp._tabSource && vp._tabSource.currentFrets) {
                const tabFrets = vp._tabSource.currentFrets;
                const matchIdx = vp.results.findIndex(r => r.frets === tabFrets);
                vp._tabMatchIndex = matchIdx; // -1 if no match → show "from tab" card
            } else {
                vp._tabMatchIndex = -1;
            }
        },

        togglePickerFilter(type, value) {
            const af = this.voicingPicker.activeFilters;
            if (type === 'voicing_category') {
                af.voicing_category = af.voicing_category === value ? 'all' : value;
            } else if (type === 'root_string') {
                af.root_string = af.root_string === value ? 'all' : value;
            }
            this.fetchVoicings();
        },

        stepExtension(dir) {
            const vp = this.voicingPicker;
            const groups = ['9','11','13'];
            const cycles = vp.extensionCycles;

            if (!vp.extensionGroup) {
                // No extension active — start with first group
                vp.extensionGroup = groups[0];
                vp.extensionIdx = dir > 0 ? 0 : cycles[groups[0]].length - 1;
            } else {
                const cycle = cycles[vp.extensionGroup];
                let newIdx = vp.extensionIdx + dir;
                if (newIdx < -1) {
                    // Wrap to previous group
                    const gi = groups.indexOf(vp.extensionGroup);
                    if (gi > 0) {
                        vp.extensionGroup = groups[gi - 1];
                        vp.extensionIdx = cycles[vp.extensionGroup].length - 1;
                    } else {
                        // Clear extension
                        vp.extensionGroup = ''; vp.extensionIdx = -1;
                        vp.activeFilters.extension = '';
                        this.fetchVoicings(); return;
                    }
                } else if (newIdx >= cycle.length) {
                    // Wrap to next group
                    const gi = groups.indexOf(vp.extensionGroup);
                    if (gi < groups.length - 1) {
                        vp.extensionGroup = groups[gi + 1];
                        vp.extensionIdx = 0;
                    } else {
                        // Clear extension
                        vp.extensionGroup = ''; vp.extensionIdx = -1;
                        vp.activeFilters.extension = '';
                        this.fetchVoicings(); return;
                    }
                } else if (newIdx === -1) {
                    // Clear extension within current group
                    vp.extensionGroup = ''; vp.extensionIdx = -1;
                    vp.activeFilters.extension = '';
                    this.fetchVoicings(); return;
                } else {
                    vp.extensionIdx = newIdx;
                }
            }
            vp.activeFilters.extension = cycles[vp.extensionGroup][vp.extensionIdx];
            this.fetchVoicings();
        },

        clearExtension() {
            this.voicingPicker.extensionGroup = '';
            this.voicingPicker.extensionIdx = -1;
            this.voicingPicker.activeFilters.extension = '';
            this.fetchVoicings();
        },

        stepInversion(dir) {
            const vp = this.voicingPicker;
            const options = ['all', 'root', '1st', '2nd', '3rd'];
            // Filter to only available inversions + 'all'
            const available = ['all', ...vp.filters.inversions];
            let idx = available.indexOf(vp.activeFilters.inversion);
            if (idx === -1) idx = 0;
            idx = (idx + dir + available.length) % available.length;
            vp.activeFilters.inversion = available[idx];
            this.fetchVoicings();
        },

        getInversionLabel() {
            const inv = this.voicingPicker.activeFilters.inversion;
            if (inv === 'all' || !inv) return 'All';
            const labels = { 'root':'Root', 'inv1':'1st', 'inv2':'2nd', 'inv3':'3rd' };
            return labels[inv] || inv;
        },

        pickerDisplayName() {
            const vp = this.voicingPicker;
            // Map DB quality back to display notation
            const qDisplay = {
                'maj7':'maj7','dom7':'7','m7':'m7','m7b5':'m7b5','o7':'dim7',
                'mMaj7':'mMaj7','maj6':'6','m6':'m6','aug7':'aug7','aug':'aug',
                'dim':'dim','sus4':'sus4','sus2':'sus2','7sus4':'7sus4',
                'add9':'add9','maj':'maj','min':'m','5':'5'
            };
            let name = vp.root + (qDisplay[vp.quality] || vp.quality);

            // Extension
            const ext = vp.activeFilters.extension;
            if (ext) {
                name += '(' + ext + ')';
            }

            // Inversion → bass note
            const inv = vp.activeFilters.inversion;
            if (inv && inv !== 'all' && inv !== 'root') {
                // Compute bass note from inversion
                const semi = {'C':0,'C#':1,'Db':1,'D':2,'D#':3,'Eb':3,'E':4,'F':5,'F#':6,'Gb':6,'G':7,'G#':8,'Ab':8,'A':9,'A#':10,'Bb':10,'B':11};
                const semiToNote = ['C','Db','D','Eb','E','F','F#','G','Ab','A','Bb','B'];
                const invIntervals = {
                    'maj7':{'inv1':4,'inv2':7,'inv3':11},'dom7':{'inv1':4,'inv2':7,'inv3':10},
                    'm7':{'inv1':3,'inv2':7,'inv3':10},'m6':{'inv1':3,'inv2':7,'inv3':9},
                    'maj6':{'inv1':4,'inv2':7,'inv3':9},'m7b5':{'inv1':3,'inv2':6,'inv3':10},
                    'o7':{'inv1':3,'inv2':6,'inv3':9},'mMaj7':{'inv1':3,'inv2':7,'inv3':11},
                    'aug7':{'inv1':4,'inv2':8,'inv3':10},
                    'maj':{'inv1':4,'inv2':7},'min':{'inv1':3,'inv2':7},
                    'aug':{'inv1':4,'inv2':8},'dim':{'inv1':3,'inv2':6},
                    'sus4':{'inv1':5,'inv2':7},'sus2':{'inv1':2,'inv2':7},
                };
                const rootSemi = semi[vp.root];
                const intervals = invIntervals[vp.quality];
                if (rootSemi !== undefined && intervals && intervals[inv] !== undefined) {
                    const bassSemi = (rootSemi + intervals[inv]) % 12;
                    name += '/' + semiToNote[bassSemi];
                }
            } else if (vp.bassNote) {
                name += '/' + vp.bassNote;
            }

            return name;
        },

        isPickerFilterActive(type, value) {
            return this.voicingPicker.activeFilters[type] === value;
        },

        hasActiveFilters() {
            const af = this.voicingPicker.activeFilters;
            const vp = this.voicingPicker;
            // Compare against defaults derived from chord
            return af.voicing_category !== 'all' || af.root_string !== 'all' ||
                   af.extension !== (vp.extension || '') ||
                   af.inversion !== (vp.inversion ? vp.inversion : 'all');
        },

        resetPickerFilters() {
            const vp = this.voicingPicker;
            vp.activeFilters = {
                voicing_category: 'all', root_string: 'all',
                extension: vp.extension || '',
                inversion: vp.inversion ? vp.inversion : 'all',
            };
            // Reset extension stepper
            if (vp.extension) {
                for (const [grp, cycle] of Object.entries(vp.extensionCycles)) {
                    const idx = cycle.indexOf(vp.extension);
                    if (idx !== -1) { vp.extensionGroup = grp; vp.extensionIdx = idx; break; }
                }
            } else {
                vp.extensionGroup = ''; vp.extensionIdx = -1;
            }
            this.fetchVoicings();
        },

        diagramDataToFrets(dd) {
            if (!dd) return '';
            const result = ['x','x','x','x','x','x'];
            if (dd.open) dd.open.forEach(s => { if (s>=1 && s<=6) result[s-1] = '0'; });
            if (dd.positions) dd.positions.forEach(p => { if (p.string>=1 && p.string<=6 && p.fret>0) result[p.string-1] = p.fret<=9 ? String(p.fret) : p.fret.toString(16); });
            if (dd.barres) dd.barres.forEach(b => { const from=Math.min(b.fromString,b.toString), to=Math.max(b.fromString,b.toString); for(let s=from;s<=to;s++){if(s>=1&&s<=6&&result[s-1]==='x')result[s-1]=b.fret<=9?String(b.fret):b.fret.toString(16);} });
            return result.join('');
        },

        isVoicingSelected(v) {
            const lookupKey = this.voicingPicker.voicingKey || this.voicingPicker.chordName;
            const cur = this.parsed.chordVoicings ? (this.parsed.chordVoicings[lookupKey] || this.parsed.chordVoicings[this.voicingPicker.chordName]) : null;
            return cur && v.frets === cur.frets && parseInt(v.position) === parseInt(cur.position);
        },

        selectVoicing(v) {
            if (!this.parsed.chordVoicings) this.parsed.chordVoicings = {};
            const vp = this.voicingPicker;
            const oldName = vp.chordName;
            const newName = this.pickerDisplayName();
            const assignKey = vp.voicingKey || oldName;

            this._wrapUndo('Assign voicing', () => {
            // If the chord name changed (extension/inversion filter changed), update the leadsheet
            if (newName !== oldName) {
                if (vp.voicingKey) {
                    // Specific chord instance: voicingKey = "ChordName@globalIdx.chordIdx"
                    const keyMatch = vp.voicingKey.match(/^.+@(\d+)\.(\d+)$/);
                    if (keyMatch) {
                        const globalIdx = parseInt(keyMatch[1]);
                        const ci = parseInt(keyMatch[2]);
                        // Find the section/measure for this global index
                        let idx = 0;
                        for (const section of this.parsed.sections) {
                            for (let mi = 0; mi < (section.measures || []).length; mi++) {
                                if (idx === globalIdx) {
                                    if (section.measures[mi].chords[ci]) {
                                        section.measures[mi].chords[ci].name = newName;
                                    }
                                }
                                idx++;
                            }
                        }
                        // Re-key the voicing assignment to the new chord name
                        const newAssignKey = newName + '@' + keyMatch[1] + '.' + keyMatch[2];
                        // Remove old key if it exists
                        if (this.parsed.chordVoicings[assignKey]) {
                            delete this.parsed.chordVoicings[assignKey];
                        }
                        this.parsed.chordVoicings[newAssignKey] = { frets: v.frets, position: v.position, fingers: '000000' };
                    }
                } else {
                    // Global assignment: rename ALL instances of this chord in the leadsheet
                    for (const section of this.parsed.sections) {
                        for (const measure of (section.measures || [])) {
                            for (const chord of measure.chords) {
                                if (chord.name === oldName) {
                                    chord.name = newName;
                                }
                            }
                        }
                    }
                    // Remove old voicing key, assign under new name
                    if (this.parsed.chordVoicings[oldName]) {
                        delete this.parsed.chordVoicings[oldName];
                    }
                    this.parsed.chordVoicings[newName] = { frets: v.frets, position: v.position, fingers: '000000' };
                }
            } else {
                // Name unchanged — assign normally
                this.parsed.chordVoicings[assignKey] = { frets: v.frets, position: v.position, fingers: '000000' };
            }
            }); // end _wrapUndo

            this.voicingPicker.open = false;

            // Step 5: dispatch to Vue so frets are written into the tab model.
            // Path A: opened from tab editor — _tabSource has full context.
            // Path B: opened from chord grid — extract coords from voicingKey.
            if (this.voicingPicker._tabSource) {
                const src = this.voicingPicker._tabSource;
                console.log('[SBN] dispatching sbn-tab-voicing-applied (tab source)', { globalMeasureIndex: src.globalMeasureIndex, chordIndex: src.chordIndex, frets: v.frets });
                document.dispatchEvent(new CustomEvent('sbn-tab-voicing-applied', {
                    detail: {
                        globalMeasureIndex: src.globalMeasureIndex,
                        chordIndex:         src.chordIndex,
                        chordName:          newName,
                        frets:              v.frets,
                        position:           v.position,
                    },
                }));
                this.voicingPicker._tabSource = null;
            } else {
                // Chord grid path: extract globalMeasureIndex and chordIndex from voicingKey
                const keyMatch = (vp.voicingKey || '').match(/^.+@(\d+)\.(\d+)$/);
                if (keyMatch) {
                    const globalMeasureIndex = parseInt(keyMatch[1]);
                    const chordIndex         = parseInt(keyMatch[2]);
                    console.log('[SBN] dispatching sbn-tab-voicing-applied (grid source)', { globalMeasureIndex, chordIndex, frets: v.frets });
                    document.dispatchEvent(new CustomEvent('sbn-tab-voicing-applied', {
                        detail: {
                            globalMeasureIndex,
                            chordIndex,
                            chordName: newName,
                            frets:     v.frets,
                            position:  v.position,
                        },
                    }));
                } else {
                    console.log('[SBN] selectVoicing: no voicingKey coords — skipping tab dispatch');
                }
            }

            this._suppressTabInit = true;
            this.markDirty();
            this._suppressTabInit = false;

            if (newName !== oldName) {
                this._emitChordsChanged();
            }

            sbnToast('Voicing assigned' + (newName !== oldName ? ' — chord renamed to ' + newName : ''), 'success');
        },

        removeVoicing() {
            const removeKey = this.voicingPicker.voicingKey || this.voicingPicker.chordName;
            delete this.parsed.chordVoicings[removeKey];
            this.voicingPicker.open = false;
            this.markDirty();
            sbnToast('Voicing removed', 'success');
        },

        // ── Prune orphan voicings ─────────────────────────────
        // Removes any chordVoicings entries whose chord no longer exists in the grid.
        // Called on every save to clean up zombies from renaming, deletion, etc.
        pruneOrphanVoicings() {
            if (!this.parsed || !this.parsed.chordVoicings) return;

            // Build lookup structures from the current grid state
            const globalNames = new Set();   // all chord names present anywhere
            const instanceMap = new Map();   // "gi.ci" → chord name

            let gi = 0;
            for (const section of (this.parsed.sections || [])) {
                for (const measure of (section.measures || [])) {
                    for (let ci = 0; ci < measure.chords.length; ci++) {
                        const name = measure.chords[ci].name;
                        globalNames.add(name);
                        instanceMap.set(gi + '.' + ci, name);
                    }
                    gi++;
                }
            }

            const toDelete = [];
            for (const key of Object.keys(this.parsed.chordVoicings)) {
                const instanceMatch = key.match(/^(.+)@(\d+\.\d+)$/);
                if (instanceMatch) {
                    // Instance key: "ChordName@gi.ci" — valid only if that exact
                    // chord still sits at that position
                    const expectedName = instanceMatch[1];
                    const pos = instanceMatch[2];
                    if (instanceMap.get(pos) !== expectedName) {
                        toDelete.push(key);
                    }
                } else {
                    // Global key: valid if any chord in the grid has this name
                    if (!globalNames.has(key)) {
                        toDelete.push(key);
                    }
                }
            }

            if (toDelete.length > 0) {
                toDelete.forEach(k => delete this.parsed.chordVoicings[k]);
                console.log('SBN: pruned ' + toDelete.length + ' orphan voicing(s):', toDelete);
            }
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
                    // Assigning to this.parsed triggers Alpine's $watch('parsed') which
                    // dispatches sbn-tab-init and overwrites Vue's live model.
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

            // Remove voicings that no longer have a matching chord in the grid
            this.pruneOrphanVoicings();

            // Flatten measures for measure_count
            const allMeasures = [];
            this.parsed.sections.forEach(s => (s.measures||[]).forEach(m => allMeasures.push(m)));
            this.parsed.measures = allMeasures;

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
                        json_data: JSON.stringify(
                            this._savedMelody
                                ? { ...this.parsed, melody: this._savedMelody }
                                : this.parsed
                        ),
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
            if (this.analysisData && !this.dirty) return;

            this.analysisLoading = true;
            this.detectionResult = '';
            try {
                const resp = await fetch('/api/admin/leadsheets/' + this.leadsheetId + '/analyse-progressions');
                const data = await resp.json();
                if (data.success) {
                    this.analysisData = data.data;
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

{{-- ── SBN DEBUG PANEL ── remove before deploy ────────────────── --}}
<script>
(function(){
  const panel = document.createElement('div');
  panel.id = 'sbn-debug';
  panel.style.cssText = 'position:fixed;bottom:0;right:0;width:480px;height:320px;background:#1a1a1a;color:#e0e0e0;font-family:monospace;font-size:11px;z-index:99999;display:flex;flex-direction:column;border-top:2px solid #444;border-left:2px solid #444;';
  panel.innerHTML = `
    <div style="display:flex;gap:6px;padding:4px 6px;background:#111;flex-shrink:0;align-items:center">
      <span style="color:#888;flex:1">SBN Debug</span>
      <button onclick="document.getElementById('sbn-debug-log').innerHTML=''" style="font-size:10px;padding:2px 6px;background:#333;color:#ccc;border:1px solid #555;cursor:pointer">clear</button>
      <button onclick="sbnSnapState()" style="font-size:10px;padding:2px 6px;background:#333;color:#ccc;border:1px solid #555;cursor:pointer">snap</button>
      <button onclick="(function(){const p=document.getElementById('sbn-debug');p.style.display=p.style.display==='none'?'flex':'none'})()" style="font-size:10px;padding:2px 6px;background:#333;color:#ccc;border:1px solid #555;cursor:pointer">hide</button>
    </div>
    <div id="sbn-debug-log" style="flex:1;overflow-y:auto;padding:4px 6px;"></div>
    <div id="sbn-debug-status" style="padding:2px 6px;background:#111;font-size:10px;color:#666;flex-shrink:0"></div>
  `;
  document.body.appendChild(panel);

  const log = document.getElementById('sbn-debug-log');
  const status = document.getElementById('sbn-debug-status');
  const colors = {chord:'#7eb8f7',tab:'#7ef7b8',undo:'#f7d87e',snap:'#c9a8f7',voicing:'#f7a87e',struct:'#f77eb8',warn:'#f74444'};
  let n = 0;

  function add(cat, msg, detail){
    n++;
    const t = new Date().toISOString().slice(11,23);
    const c = colors[cat]||'#aaa';
    const row = document.createElement('div');
    row.style.cssText = 'padding:1px 0;border-bottom:1px solid #222;cursor:pointer';
    row.innerHTML = '<span style="color:#555">'+t+'</span> <span style="color:'+c+'">['+cat+']</span> <span>'+msg+'</span>';
    if(detail) row.title = detail;
    row.onclick = function(){ row.style.background = row.style.background?'':'#2a2a2a'; if(detail) console.log('[SBN]',cat,msg,'\n',detail); };
    log.appendChild(row);
    log.scrollTop = log.scrollHeight;
    status.textContent = n+' events';
  }

  function getAl(){
    try{
      const el = document.querySelector('[x-data="leadsheetEditor()"]');
      if(!el) return null;
      if(el._x_dataStack) return el._x_dataStack[0];
      if(window.Alpine && Alpine.$data) return Alpine.$data(el);
      return null;
    }catch(e){ return null; }
  }

  window.sbnSnapState = function(){
    const al = getAl();
    if(!al){ add('warn','cannot reach Alpine'); return; }
    const cv = al.parsed?.chordVoicings||{};
    const secs = al.parsed?.sections||[];
    const bars = secs.reduce((n,s)=>n+(s.measures||[]).length,0);
    const inst = Object.keys(cv).filter(k=>/@\d+\.\d+$/.test(k));
    const glob = Object.keys(cv).filter(k=>!/@\d+\.\d+$/.test(k));
    add('snap',
      secs.length+' secs / '+bars+' bars / '+Object.keys(cv).length+' voicings',
      'global: '+(glob.join(', ')||'none')+'\ninstance: '+(inst.join(', ')||'none')+'\nundoPtr: '+al._undoPointer+'/'+(( al._undoStack?.length||1)-1)
    );
    console.log('[SBN snap] chordVoicings:', JSON.parse(JSON.stringify(cv)));
    console.log('[SBN snap] undoStack length:', al._undoStack?.length, 'ptr:', al._undoPointer);
    console.log('[SBN snap] sections:', JSON.parse(JSON.stringify(secs)));
  };

  const EVTS = [
    ['sbn-chords-changed',        'chord',   function(e){ const s=e.detail?.sections||[]; return 'chords-changed: '+s.length+' secs, '+s.reduce((n,x)=>n+(x.measures||[]).length,0)+' bars'; }],
    ['sbn-tab-init',              'tab',     function(){ return 'tab-init'; }],
    ['sbn-tab-structure-request', 'struct',  function(e){ return 'struct-req: '+e.detail?.action+' @ mi='+e.detail?.measureIndex; }],
    ['sbn-tab-restore-snapshot',  'snap',    function(e){ const sn=e.detail?.snapshot; return 'restore-snap: '+sn?.sections?.length+' secs / '+sn?.sections?.reduce((n,s)=>n+s.measures.length,0)+' bars'; }],
    ['sbn-tab-request-snapshot',  'snap',    function(e){
      // After the event fires synchronously, the handler should have written e.detail.tabSnapshot
      // We read it via a setTimeout(0) so the synchronous handler has run
      setTimeout(function(){
        const got = e.detail && e.detail.tabSnapshot;
        add('snap', 'request-snapshot result: ' + (got ? 'GOT snapshot ('+
          (got.sections ? got.sections.length+' secs/'+got.sections.reduce(function(n,s){return n+s.measures.length;},0)+' bars' : 'no sections')
          +')' : 'NULL — handler missing or model not built'));
      }, 10);
      return 'request-snapshot (checking...)';
    }],
    ['sbn-tab-chord-update',      'chord',   function(e){ return 'chord-update gi='+e.detail?.globalMeasureIndex+' ci='+e.detail?.chordIndex+' -> '+e.detail?.chordName; }],
    ['sbn-tab-voicing-applied',   'voicing', function(e){ return 'voicing-applied gi='+e.detail?.globalMeasureIndex+' frets='+e.detail?.frets; }],
    ['sbn-tab-delegate-undo',     'undo',    function(){ return 'delegate-undo (tab->alpine)'; }],
    ['sbn-tab-delegate-redo',     'undo',    function(){ return 'delegate-redo (tab->alpine)'; }],
  ];

  EVTS.forEach(function(ev_def){
    const ev=ev_def[0], cat=ev_def[1], parse=ev_def[2];
    document.addEventListener(ev, function(e){
      try{ add(cat, parse(e)); }
      catch(err){ add('warn', ev+' handler error: '+err.message); }
    }, true);
  });

  let _ptr = -99;
  setInterval(function(){
    const al = getAl();
    if(!al) return;
    const ptr = al._undoPointer;
    if(ptr === undefined || ptr === null) return;
    if(ptr !== _ptr){
      _ptr = ptr;
      const cmd = al._undoStack?.[ptr];
      add('undo',
        'ptr '+ptr+'/'+(( al._undoStack?.length||1)-1)+': "'+(cmd?.label||'?')+'" structural='+!!cmd?.structural+' tabSnap='+!!cmd?.before?.tabSnapshot,
        cmd ? JSON.stringify({label:cmd.label,structural:cmd.structural,hasTabSnap:!!cmd.before?.tabSnapshot,beforeVoicings:Object.keys(cmd.before?.chordVoicings||{}).length,afterVoicings:Object.keys(cmd.after?.chordVoicings||{}).length}) : ''
      );
    }
  }, 150);

  add('snap', 'debug panel ready');

  // After 3s, test the snapshot handler directly
  setTimeout(function(){
    const evt = new CustomEvent('sbn-tab-request-snapshot', { detail: {} });
    document.dispatchEvent(evt);
    const snap = evt.detail.tabSnapshot;
    if (snap) {
      add('snap', 'HANDLER TEST OK: snapshot captured — '+(snap.sections?snap.sections.length:'?')+' secs');
    } else {
      add('warn', 'HANDLER TEST FAIL: snapshot returned null after 3s — serializeModel not registered or model.value is null');
    }
  }, 3000);

  // Count how many times each sbn-tab-init fires per second — detects multiple Vue instances
  let initCount = 0;
  document.addEventListener('sbn-tab-init', function(){ initCount++; }, true);
  setInterval(function(){
    if(initCount > 0){
      if(initCount > 1) add('warn', 'tab-init fired '+initCount+'x in last second — MULTIPLE VUE INSTANCES');
      initCount = 0;
    }
  }, 1000);

  // Check for multiple Vue app mounts on #sbn-tab-editor
  setInterval(function(){
    const el = document.getElementById('sbn-tab-editor');
    if(!el) return;
    const apps = el._vei || el.__vueParentComponent;
    const children = el.childElementCount;
    if(children > 1) add('warn', 'sbn-tab-editor has '+children+' children — possible duplicate mount');
  }, 2000);
})();
</script>

@endpush
