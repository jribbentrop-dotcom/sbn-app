<template>
    <div
        class="sbn-tab-editor-root"
        tabindex="0"
        @keydown="onKeydown"
        @focus="onEditorFocus"
        @click="onEditorClick"
        ref="editorRoot"
    >

        <!-- New Vue-owned Tabs -->
        <div class="sbn-ve-tabs">
            <button class="sbn-ve-tab" :class="{ 'is-active': viewMode === 'chords' }"
                    @click="setViewMode('chords')">Chords</button>
            <button class="sbn-ve-tab" :class="{ 'is-active': viewMode === 'tab' }"
                    @click="setViewMode('tab')">Tab</button>
            <button class="sbn-ve-tab" :class="{ 'is-active': viewMode === 'analysis' }"
                    @click="setViewMode('analysis')">Analysis</button>
            <button
                v-if="hasData && viewMode === 'tab'"
                class="sbn-ve-tab sbn-ve-play-btn"
                :class="{ 'is-playing': isPlaying }"
                @click="onTransportToggle"
                :title="isPlaying ? 'Pause (Space)' : 'Play / Resume (Space)'"
            >{{ isPlaying ? 'Pause' : 'Play' }}</button>
            <button
                v-if="hasChordsData && viewMode === 'chords'"
                class="sbn-ve-tab sbn-ve-play-btn"
                :class="{ 'is-playing': isChordPlaying }"
                @click="onTransportToggle"
                :title="isChordPlaying ? 'Pause (Space)' : 'Play / Resume (Space)'"
            >{{ isChordPlaying ? 'Pause' : 'Play' }}</button>
        </div>

        <!-- Chords Grid (Phase B) -->
        <div v-show="viewMode === 'chords'" class="sbn-ve-chords-root">
            <ChordGridView v-if="model" :sections="model.sections || []" />
        </div>

        <!-- No data state -->
        <div v-if="!hasData" v-show="viewMode === 'tab'" class="sbn-tab-no-data">
            <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M9 19V6l12-3v13M9 19c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zm12-3c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2z"/>
            </svg>
            <p class="sbn-tab-no-data-title">No tablature yet</p>
            <p class="sbn-tab-no-data-hint">
                Import a MusicXML file with TAB notation using the button above,
                <span v-if="hasChordsData"> or generate a blank tab skeleton from the chord chart:</span>
                <span v-else> or add chords in the Chords view first.</span>
            </p>
            <button
                v-if="hasChordsData"
                class="sbn-btn sbn-btn-primary sbn-tab-generate-btn"
                @click="onGenerateFromChords"
            >
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
                </svg>
                Generate blank tab from chords
            </button>
        </div>

        <!-- Tab content -->
        <template v-else-if="model">
            <div class="sbn-tab-editor-notation sbn-ve-content-panel" @mousedown.left="onNotationMousedown" ref="notationRoot" style="position:relative;" v-show="viewMode === 'tab'">
                
                <div v-if="marqueeState" :style="marqueeStyle" class="sbn-tab-marquee"></div>
                
                    <div v-for="(section, si) in model.sections" :key="section.id || si" class="sbn-ve-section">

                        <!-- Section header -->
                        <div class="sbn-ve-section-header" :class="{ 'is-collapsed': collapsedSections[si] }">
                            <button class="sbn-ve-section-collapse"
                                    :class="{ 'is-collapsed': collapsedSections[si] }"
                                    @click.stop="collapsedSections[si] = !collapsedSections[si]"
                                    title="Collapse section">▼</button>
                            <div v-if="section.id" class="sbn-ve-section-id">{{ section.id }}</div>
                            <input class="sbn-ve-section-name"
                                   :value="section.name"
                                   placeholder="Section name…"
                                   @blur="tabModel.renameSection(si, $event.target.value)"
                                   @keydown.enter="$event.target.blur()" />
                            <span class="sbn-ve-section-bar-count">{{ section.measures.length }} bars</span>
                            <div class="sbn-ve-section-actions">
                                <button class="sbn-ve-section-btn" @click="onAddMeasure(si)" title="Add bar">+</button>
                                <button v-if="model.sections.length > 1" class="sbn-ve-section-delete" @click="onDeleteSection(si)" title="Remove section">×</button>
                            </div>
                        </div>

                        <!-- Section body -->
                        <div class="sbn-ve-section-body" v-show="!collapsedSections[si]" style="padding:8px 4px 4px;">
                            <div v-for="(row, ri) in measureRows(section)" :key="ri" class="sbn-tab-row" :class="{ 'sbn-tab-row--has-volta': row.some(m => m.volta) }">

                                <div class="sbn-tab-measures">
                                    <TabMeasure
                                        v-for="(measure, li) in row"
                                        :key="measure.index"
                                        :measure="measure"
                                        :is-first-of-section="ri === 0 && li === 0"
                                        :ticks-per-measure="model.ticksPerMeasure"
                                        :next-measure="getNextMeasure(measure.index)"
                                        :is-next-first-of-section="isNextMeasureFirstOfSection(measure.index)"
                                        :chord-names="measure.chordNames || []"
                                        :cursor="cursorState"
                                        :bars-per-row="row._intendedCount"
                                        :pending-digit="pendingDigit"
                                        :selected-events="selectedEvents"
                                        @cursor-mousedown-event="onCursorMousedownEvent"
                                        @cursor-mouseenter-event="onCursorMouseenterEvent"
                                        @cursor-mousedown-rest="onCursorMousedownRest"
                                        @cursor-mouseenter-rest="onCursorMouseenterRest"
                                        @chord-click="onChordClick"
                                        @chord-identify="onChordIdentify"
                                        @chord-context-menu="onChordContextMenu"
                                        @chord-name-needed="onChordNameNeeded"
                                        @measure-context-menu="onMeasureContextMenu"
                                    />

                                    <!-- Fill empty slots to hold row width -->
                                    <div v-for="f in emptySlots(row)" :key="'empty-' + f"
                                         class="sbn-tab-measure" :style="{ flex: `0 0 ${100 / row._intendedCount}%`, visibility: 'hidden' }">
                                    </div>
                                </div>

                                <!-- Row resize controls (like chord editor) - outside measures so not cut off -->
                                <div class="sbn-tab-row-resize">
                                    <button
                                        class="sbn-tab-row-btn"
                                        :title="'Move last bar to next row'"
                                        @click.stop="rowShrink(si, ri)"
                                        :disabled="row.length <= 1"
                                    >−</button>
                                    <button
                                        class="sbn-tab-row-btn"
                                        :title="'Pull next bar into this row'"
                                        @click.stop="rowGrow(si, ri)"
                                        :disabled="ri >= measureRows(section).length - 1"
                                    >+</button>
                                    <button class="sbn-tab-row-btn sbn-ve-row-btn-section"
                                            @click.stop="onSplitSection(si, ri)" title="New section after this row">§</button>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <button class="sbn-ve-add-section" @click="onAddSection()">+ Add Section</button>
            </div>
        </template>
        <!-- Voicing picker panel — Teleports itself into #sbn-vp-slot -->
        <VoicingPicker v-if="voicingPickerStore" />

        <!-- Keyboard shortcut overlay (? key) -->
        <transition name="sbn-tab-overlay-fade">
            <div v-if="showShortcuts" class="sbn-tab-shortcut-overlay" @click.self="showShortcuts = false">
                <div class="sbn-tab-shortcut-panel">
                    <div class="sbn-tab-shortcut-header">
                        <span>Keyboard Shortcuts</span>
                        <button class="sbn-tab-shortcut-close" @click="showShortcuts = false">✕</button>
                    </div>
                    <div class="sbn-tab-shortcut-cols">
                        <div class="sbn-tab-shortcut-group">
                            <div class="sbn-tab-shortcut-group-title">Navigation</div>
                            <div class="sbn-tab-shortcut-row"><kbd>← →</kbd><span>Previous / next event</span></div>
                            <div class="sbn-tab-shortcut-row"><kbd>↑ ↓</kbd><span>Move between strings</span></div>
                            <div class="sbn-tab-shortcut-row"><kbd>Tab</kbd><span>Next measure</span></div>
                            <div class="sbn-tab-shortcut-row"><kbd>Shift+Tab</kbd><span>Previous measure</span></div>
                            <div class="sbn-tab-shortcut-row"><kbd>Home / End</kbd><span>First / last event</span></div>
                            <div class="sbn-tab-shortcut-row"><kbd>Esc</kbd><span>Cancel / back to navigate</span></div>
                        </div>
                        <div class="sbn-tab-shortcut-group">
                            <div class="sbn-tab-shortcut-group-title">Note Entry</div>
                            <div class="sbn-tab-shortcut-row"><kbd>0 – 9</kbd><span>Enter fret number</span></div>
                            <div class="sbn-tab-shortcut-row"><kbd>Del / ⌫</kbd><span>Remove note on string</span></div>
                            <div class="sbn-tab-shortcut-row"><kbd>A</kbd><span>Append rest at end</span></div>
                            <div class="sbn-tab-shortcut-row"><kbd>→ (at end)</kbd><span>Fill measure with rest</span></div>
                        </div>
                        <div class="sbn-tab-shortcut-group">
                            <div class="sbn-tab-shortcut-group-title">Duration</div>
                            <div class="sbn-tab-shortcut-row"><kbd>Ctrl+1 – 2, 4 – 6</kbd><span>Whole, half, 8th, 16th, 32nd</span></div>
                            <div class="sbn-tab-shortcut-row"><kbd>Ctrl+3</kbd><span>Toggle triplet (create / dissolve)</span></div>
                            <div class="sbn-tab-shortcut-row"><kbd>+ / =</kbd><span>Shorter duration (dissolves triplet)</span></div>
                            <div class="sbn-tab-shortcut-row"><kbd>−</kbd><span>Longer duration (dissolves triplet)</span></div>
                            <div class="sbn-tab-shortcut-row"><kbd>.</kbd><span>Toggle dotted</span></div>
                            <div class="sbn-tab-shortcut-row"><kbd>T</kbd><span>Toggle tie</span></div>
                        </div>
                        <div class="sbn-tab-shortcut-group">
                            <div class="sbn-tab-shortcut-group-title">Selection &amp; Clipboard</div>
                            <div class="sbn-tab-shortcut-row"><kbd>Shift+← →</kbd><span>Extend note selection</span></div>
                            <div class="sbn-tab-shortcut-row"><kbd>Ctrl+C</kbd><span>Copy note / selection</span></div>
                            <div class="sbn-tab-shortcut-row"><kbd>Ctrl+X</kbd><span>Cut note / selection</span></div>
                            <div class="sbn-tab-shortcut-row"><kbd>Ctrl+V</kbd><span>Paste at cursor</span></div>
                        </div>
                        <div class="sbn-tab-shortcut-group">
                            <div class="sbn-tab-shortcut-group-title">History</div>
                            <div class="sbn-tab-shortcut-row"><kbd>Ctrl+Z</kbd><span>Undo</span></div>
                            <div class="sbn-tab-shortcut-row"><kbd>Ctrl+Shift+Z</kbd><span>Redo</span></div>
                            <div class="sbn-tab-shortcut-row"><kbd>?</kbd><span>This help panel</span></div>
                        </div>
                    </div>
                </div>
            </div>
        </transition>

        <!-- Transport bar — visible in tab and chord views when there's data -->
        <TransportBar
            v-if="(viewMode === 'tab' && hasData) || (viewMode === 'chords' && hasChordsData)"
            :is-playing="transportPlaying"
            :current-beat="transportBeat"
            :total-beats="totalBeats"
            :tempo="model?.tempo ?? 120"
            :beats-per-measure="beatsPerMeasure"
            :view-mode="viewMode"
            :show-mixer="true"
            :volume-chord="volumeChord"
            :volume-rhythm="volumeRhythm"
            :volume-tab="volumeTab"
            @toggle="onTransportToggle"
            @stop="onTransportReset"
            @seek="onTransportSeek"
            @tempo-change="onTransportTempo"
            @volume-chord="onVolumeChord"
            @volume-rhythm="onVolumeRhythm"
            @volume-tab="onVolumeTab"
        />
    </div>
</template>

<script setup>
/**
 * TabEditor — Root component for the SBN tab editor.
 *
 * Phase 7a: Read-only rendering.
 * Phase 7b: Cursor navigation, click-to-select, keyboard shortcuts, sidebar.
 * Phase 7c: Fret entry, note deletion, rest↔note conversion, chord ID emit.
 * Phase 7d: Duration changes, reflow, tie toggle, dotted toggle.
 */

import { computed, defineExpose, ref, onMounted, onUnmounted, watch, nextTick, provide } from 'vue';
import ChordGridView from './components/ChordGridView.vue';
import VoicingPicker from './components/VoicingPicker.vue';
import { LAYOUT, generateId } from './utils/constants.js';
import { useAlpineBridge } from './composables/useAlpineBridge.js';
import { useTabModel } from './composables/useTabModel.js';
import { useCursor } from './composables/useCursor.js';
import { useNoteInput } from './composables/useNoteInput.js';
import { useReflow } from './composables/useReflow.js';
import { useUndo } from './composables/useUndo.js';
import { useSelection } from './composables/useSelection.js';
import { sidebarStore } from './composables/useSidebarStore.js';
import { modelToMusicXml } from './utils/musicXmlWriter.js';
import { initTabModelFacade } from './utils/tabModelFacade.js';
import { extractFretsAtChord, applyVoicingToChord } from './composables/useChordSync.js';
import TabMeasure from './components/TabMeasure.vue';
import { useChordGridOps }        from './composables/useChordGridOps.js';
import { useGridSelection }       from './composables/useGridSelection.js';
import { useChordClipboard }      from './composables/useChordClipboard.js';
import { useChordPickerStore }    from './composables/useChordPickerStore.js';
import { useVoicingPickerStore }  from './composables/useVoicingPickerStore.js';
import { useAudioEngine }         from './composables/useAudioEngine.js';
import { useChordAudio }          from './composables/useChordAudio.js';
import { getAudioEngine }         from '../audio/engine/AudioEngine.js';
import { tabModelToEvents }       from '../audio/adapters/tabMeasureToEvents.js';
import { chordVoicingsToEvents }  from '../audio/adapters/chordVoicingsToEvents.js';
import TransportBar               from './components/TransportBar.vue';

const props = defineProps({
    initialView: {
        type: String,
        default: 'chords'
    }
});

const viewMode = ref(props.initialView);
const collapsedSections = ref({});   // keyed by si, true = collapsed
provide('viewMode', viewMode);

function setViewMode(mode) {
    viewMode.value = mode;
    document.dispatchEvent(new CustomEvent('sbn-tab-view-changed', {
        detail: { viewMode: mode }
    }));
}

// ── Alpine Bridge ──────────────────────────────────────────

const bridge = useAlpineBridge();
const {
    melody, sections, chordVoicings, timeSignature, songKey,
    title, composer,
    tabXml, repeatMarkers, voltaEndings,
    initialized, setSaveHandler,
    setStructureHandler,
} = bridge;

// ── Working Model ──────────────────────────────────────────

const tabModel = useTabModel(
    melody, sections, timeSignature, repeatMarkers, voltaEndings, chordVoicings
);
const {
    model, hasData, buildModel, serializeModel, deserializeModel,
    insertMeasureAfter, insertMeasureBefore, deleteMeasure, deleteMeasuresByGlobalIndices,
    exportAlpineSections, cloneChordVoicings, applyChordVoicingOps,
} = tabModel;

// ── __sbnTabModel facade (Phase B Step 7) ─────────────────────────────────
// Exposes live Vue model data to Alpine via window.__sbnTabModel.
// Getter functions read directly from the reactive model — no snapshot needed.
// Alpine consumes this in sbn-tab-sections-sync (Step 7), save() (Step 8),
// and loadAnalysis() (Step 9).
initTabModelFacade({
    getSections:      () => exportAlpineSections(),
    getChordVoicings: () => cloneChordVoicings(model.value?.chordVoicings ?? {}),
    getRepeatMarkers: () => model.value?.repeatMarkers ?? null,
    getVoltaEndings:  () => model.value?.voltaEndings  ?? null,
    getMeta: () => model.value ? {
        title:         model.value.title,
        composer:      model.value.composer,
        key:           model.value.key,
        tempo:         model.value.tempo,
        timeSignature: model.value.timeSignature,
    } : {},
});

// ── Update Bridge with Model ───────────────────────────────
// Now that we have tabModel, update the bridge to use it for structural operations
bridge.setTabModel(tabModel);

// ── Chord Grid Operations (Phase B) ──────────────────────────

provide('model', model);
provide('globalIndexOf', globalMeasureIndex);

// ── Cursor ─────────────────────────────────────────────────

const {
    cursor,
    active: cursorActive,
    currentEvent,
    currentNote,
    allMeasures,
    handleKeydown: cursorKeydown,
    clickEvent,
    clickRest,
    moveTo,
    moveRight: cursorMoveRight,
} = useCursor(model);

const cursorState = computed(() => cursor.value);

// ── Audio Engine (Phase 7C) ───────────────────────────────

const { isPlaying, currentBeat, activeSourceId, play: playTab, pause: pauseTab, reset: resetTab, seek: seekTab } = useAudioEngine(model);

// ── Chord Audio (Phase 7D) ────────────────────────────────

const { isPlaying: isChordPlaying, currentBeat: chordCurrentBeat, activeSourceId: chordActiveSourceId, play: playChord, pause: pauseChord, reset: resetChord, seek: seekChord } = useChordAudio(model);

// ── Unified Audio Loading ─────────────────────────────────
// Centralized event loading to prevent each composable from overwriting
// the others' events. All voices (tab, chords, future rhythm) are merged
// into a single engine.load() call before playback starts.

const engine = getAudioEngine();
let _eventsLoaded = false; // Track if events have been loaded for current session

/**
 * Load combined events from all active voices into the audio engine.
 * Called once before playback starts; subsequent calls only reload if model changed.
 * Must be called after a user gesture (click/key) so the AudioContext can start.
 */
async function loadAllEvents() {
    if (!model.value) return;

    // Ensure engine is initialized (idempotent) before loading events.
    // This must happen after a user gesture for AudioContext autoplay policy.
    await engine.init({ bpm: model.value.tempo || 120 });

    // Gather events from all voices
    const tabEvents = tabModelToEvents(model.value, { startBeat: 0 });
    const chordEvents = chordVoicingsToEvents(model.value, { startBeat: 0 });
    // Future: const rhythmEvents = rhythmPatternToEvents(rhythmModel.value, { startBeat: 0 });

    // Merge and sort by time
    const combinedEvents = [...tabEvents, ...chordEvents].sort((a, b) => a.time - b.time);

    // Single engine.load() call with all events
    engine.load(combinedEvents);
    engine.setTempo(model.value.tempo || 120);

    _eventsLoaded = true;
}

/**
 * Public method to force reload events (e.g., when model changes while stopped).
 * Replaces the previous per-composable watch-based reloads.
 */
async function reloadEvents() {
    _eventsLoaded = false;
    await loadAllEvents();
}

// ── Transport helpers ─────────────────────────────────────

const totalBeats = computed(() => {
    if (!model.value?.sections) return 0;
    const bpm = (model.value.ticksPerMeasure ?? 1920) / 480;
    return model.value.sections.reduce((t, s) => t + s.measures.length, 0) * bpm;
});

const beatsPerMeasure = computed(() => (model.value?.ticksPerMeasure ?? 1920) / 480);

// Both composables read from the same Transport clock once inited, so they stay in sync.
// Priority: whichever is actively playing; fallback to the other for position display.
const transportPlaying = computed(() => isPlaying.value || isChordPlaying.value);
const transportBeat    = computed(() => {
    if (isPlaying.value)      return currentBeat.value;
    if (isChordPlaying.value) return chordCurrentBeat.value;
    // Neither playing — show last known position from either (they share the clock)
    return currentBeat.value || chordCurrentBeat.value;
});

async function onTransportToggle() {
    if (transportPlaying.value) {
        // Pause — keep position for resume.
        if (isPlaying.value)      pauseTab();
        if (isChordPlaying.value) pauseChord();
    } else {
        // Resume or start from current position.
        // Load all events once before playback to ensure all voices are merged.
        if (!_eventsLoaded) {
            loadAllEvents();
        }
        if (viewMode.value === 'tab') await playTab();
        else await playChord();
    }
}

/** Full stop: seek to 0, clear all playback state. Mapped to Escape / ⏹ button. */
function onTransportReset() {
    resetTab();
    resetChord();
    // Clear loaded flag so next playback reloads fresh events
    _eventsLoaded = false;
}

function onTransportSeek(beat) {
    // Both composables share the same engine; seekTab moves the clock for both.
    seekTab(beat);
    // If chord view is live, also update its currentBeat ref so the slider stays correct.
    if (isChordPlaying.value) seekChord(beat);
}

/**
 * Seek the playback cursor to the start of a global measure index and start playing.
 * Called from ChordCard clicks (chord view) and can be used from tab view too.
 * If already playing, immediately jumps to the new position.
 */
async function seekToMeasure(gi) {
    if (!model.value) return;
    const beatStart = gi * beatsPerMeasure.value;

    if (transportPlaying.value) {
        // Already playing — jump the clock; the scheduler seekTo fires automatically via engine.seek
        seekTab(beatStart);
        if (isChordPlaying.value) seekChord(beatStart);
    } else {
        // Not playing — seek to position then start from there
        if (!_eventsLoaded) await loadAllEvents();
        // Seek before play so engine.play() picks up the right scheduler index
        seekTab(beatStart);
        if (viewMode.value === 'tab') await playTab();
        else await playChord();
    }
}

/**
 * Convert a beat position to { measureIndex, eventIndex } in the tab model.
 * Used to drive the tab cursor during playback.
 */
function beatToMeasureEvent(beat) {
    if (!model.value) return null;
    const bpm = beatsPerMeasure.value;
    const ppq = (model.value.ticksPerMeasure ?? 1920) / bpm;
    const mi = Math.floor(beat / bpm);
    const beatInMeasure = beat % bpm;
    const tickInMeasure = beatInMeasure * ppq;

    const measures = allMeasures.value;
    const m = measures.find(m => m.index === mi);
    if (!m) return null;

    const v1 = m.events.filter(e => (e.voice || 1) === 1).sort((a, b) => a.tick - b.tick);
    if (!v1.length) return { measureIndex: mi, eventIndex: 0 };

    let ei = 0;
    for (let i = 0; i < v1.length; i++) {
        if ((v1[i].tickInMeasure ?? 0) <= tickInMeasure) ei = i;
        else break;
    }
    return { measureIndex: mi, eventIndex: ei };
}

function onTransportTempo(bpm) {
    if (model.value) model.value.tempo = bpm;
    getAudioEngine().setTempo(bpm);
}

// ── Per-voice mixer ───────────────────────────────────────────────────────────

const volumeChord  = ref(1.0);
const volumeRhythm = ref(1.0);
const volumeTab    = ref(1.0);

// Convert 0–1 linear to dB for PitchedSynth.setVolume (0 = unity = -16dB default).
// We apply a relative offset from the default: volume 1.0 → 0dB offset (no change).
function linearToRelativeDb(linear) {
    if (linear <= 0) return -Infinity;
    return 20 * Math.log10(linear); // 1.0→0, 0.5→-6, 0.0→-Inf
}

function onVolumeChord(v) {
    volumeChord.value = v;
    // Reload events so chord velocity scaling is recalculated.
    // The PitchedSynth is shared; events already carry velocity — no setVolume needed.
    _eventsLoaded = false;
}

function onVolumeRhythm(v) {
    volumeRhythm.value = v;
    _eventsLoaded = false;
}

function onVolumeTab(v) {
    volumeTab.value = v;
    // Tab uses PitchedSynth directly; adjust its gain node.
    const eng = getAudioEngine();
    if (eng.isInited) {
        eng._voices?.pitched?.setVolume?.(linearToRelativeDb(v) - 16); // offset from synth default (-16)
    }
}

// Unified position cursor: always shows current beat as a measure index, whether
// playing or paused. Beat 0 before anything has played highlights measure 0 (useful
// as a "you'll start here" indicator). Consumers (ChordCard, TabMeasure) use this
// for the single unified highlight that covers both playback tracking and position.
const playingMeasureIndex = computed(() =>
    Math.floor(transportBeat.value / beatsPerMeasure.value)
);

provide('tabActiveSourceId',    activeSourceId);      // note-level SVG highlight (tab only)
provide('chordActiveSourceId',  chordActiveSourceId); // kept for any future per-chord use
provide('playingMeasureIndex',  playingMeasureIndex); // unified cursor highlight (both views)
provide('transportBeat',        transportBeat);       // raw beat for sub-measure chord highlighting
provide('beatsPerMeasureRef',   beatsPerMeasure);     // chord cards need this to compute windows
provide('seekToMeasure',        seekToMeasure);       // chord-card click → seek + play

// ── Unified cursor: drive tab cursor to follow playback ───────────────────────
// While the tab engine is playing, move the orange cursor column to match the
// current beat. Uses beatToMeasureEvent to translate a beat into a measure+event
// address. String index is preserved so string selection is not disrupted.
watch(transportBeat, (beat) => {
    if (!isPlaying.value) return;       // only when tab is actively playing
    if (viewMode.value !== 'tab') return;
    const pos = beatToMeasureEvent(beat);
    if (!pos) return;
    moveTo(pos.measureIndex, pos.eventIndex, cursor.value.stringIndex);
});

// ── Undo / Redo (Phase 7e) ─────────────────────────────────

const { canUndo, canRedo, wrapCommand, undo, redo, reset: resetUndo } = useUndo(model);

// Reset undo stack when a new leadsheet loads
watch(model, (newVal, oldVal) => {
    if (newVal && !oldVal) resetUndo();
});

// ── Chord Grid composables (Phase B Step 4) ────────────────

const chordGridOps      = useChordGridOps(model, { wrapCommand }, tabModel);
const gridSelection     = useGridSelection(model);
const chordClipboard    = useChordClipboard(model, { wrapCommand });
const chordPickerStore  = useChordPickerStore();
// onVoicingApplied is a function declaration (hoisted) — safe to reference here
const voicingPickerStore = useVoicingPickerStore(model, { wrapCommand }, { applyTabFrets: onVoicingApplied });

// Provide to the entire ChordGridView / VoicingPicker subtree
provide('chordGridOps',      chordGridOps);
provide('gridSelection',     gridSelection);
provide('chordClipboard',    chordClipboard);
provide('chordPicker',       chordPickerStore);
provide('voicingPicker',     voicingPickerStore);
provide('renameSection',        (si, name) => tabModel.renameSection(si, name));
provide('addMeasureToSection',  (si)       => tabModel.addMeasureToSection(si));
provide('deleteSection',        (si)       => tabModel.deleteSection(si));
provide('sectionCount',         computed(() => model.value?.sections?.length ?? 0));
provide('rowShrink',            (si, ri)   => rowShrink(si, ri));
provide('rowGrow',              (si, ri)   => rowGrow(si, ri));
provide('rowSplit',             (si, ri)   => onSplitSection(si, ri));

// ── Step 4: Structural sync — clamp cursor after grid changes ──
// When Alpine adds/removes measures, buildModel() re-slices from the
// updated sections array. If the cursor's measureIndex now exceeds the
// new measure count, clamp it to the last valid measure so navigation
// doesn't break.
watch(allMeasures, (newMeasures) => {
    if (!newMeasures.length) return;
    const max = newMeasures.length - 1;
    if (cursor.value.measureIndex > max) {
        moveTo(max, 0, cursor.value.stringIndex);
    }
}, { flush: 'post' });

const {
    selectedEvents,
    hasNoteSelection,
    extendNoteSelection,
    clearNoteSelection,
    setSelectedEvents,
    clipboard,
    copy:        selectionCopy,
    prepareCut,
    preparePaste,
} = useSelection(model);

// Note-selection anchor: event index where Shift+Arrow started.
// Reset on any plain cursor move.
const _noteSelAnchorIdx = ref(null);
const _measureSelAnchor = ref(null);

function getSelectedMeasureIndices() {
    if (!selectedEvents.value.size || !allMeasures.value) return [];
    const indices = new Set();
    const frozen = selectedEvents.value;
    allMeasures.value.forEach(m => {
        if (m.events.some(e => frozen.has(e.id))) {
            indices.add(m.index);
        }
    });
    return [...indices].sort((a, b) => a - b);
}

function globalToSectionMeasure(gi) {
    if (!model.value) return null;
    let g = 0;
    for (let si = 0; si < model.value.sections.length; si++) {
        const sec = model.value.sections[si];
        for (let mi = 0; mi < sec.measures.length; mi++) {
            if (g === gi) return { si, mi };
            g++;
        }
    }
    return null;
}

function globalMeasureIndex(si, mi) {
    if (!model.value) return 0;
    let g = 0;
    for (let i = 0; i < si; i++) g += model.value.sections[i].measures.length;
    return g + mi;
}

/** Chord names per bar for voicing key cleanup in Alpine (global indices, descending order). */
function captureVoicingDeletesByGlobalIndices(indices) {
    if (!model.value) return [];
    const desc = [...new Set(indices)].sort((a, b) => b - a);
    return desc.map(gi => {
        let g = 0;
        let names = [];
        outer: for (const sec of model.value.sections) {
            for (const m of sec.measures) {
                if (g === gi) {
                    names = [...(m.chordNames || [])];
                    break outer;
                }
                g++;
            }
        }
        return { gi, names };
    });
}

function syncTabSectionsToAlpine(detail = {}) {
    if (!model.value) return;
    document.dispatchEvent(new CustomEvent('sbn-tab-sections-sync', {
        detail: {
        sections: exportAlpineSections(),
        chordVoicings: cloneChordVoicings(model.value.chordVoicings),
        ...detail,
        },
    }));
}

function afterStructuralUndoRedo() {
    syncTabSectionsToAlpine({ replaceSectionsOnly: true });
}

const structuralUndoOptions = {
    serializeModel,
    deserializeModel,
    afterApply: afterStructuralUndoRedo,
};

function onAddMeasure(si) {
    wrapCommand('Add Bar', [], () => {
        tabModel.addMeasureToSection(si);
    }, structuralUndoOptions);
    syncTabSectionsToAlpine();
}

function onAddSection() {
    wrapCommand('Add Section', [], () => {
        tabModel.addSection();
    }, structuralUndoOptions);
    syncTabSectionsToAlpine();
}

function onDeleteSection(si) {
    if (confirm('Are you sure you want to delete this section?')) {
        wrapCommand('Delete Section', [], () => {
            tabModel.deleteSection(si);
        }, structuralUndoOptions);
        syncTabSectionsToAlpine();
    }
}

function onSplitSection(si, ri) {
    wrapCommand('Split Section', [], () => {
        tabModel.splitSection(si, ri);
    }, structuralUndoOptions);
    syncTabSectionsToAlpine();
}

/**
 * Return the measure index for a given TabEvent.
 */
function measureIdxOf(event) {
    return event?.measureIdx ?? cursor.value?.measureIndex ?? 0;
}

// Wrapped mutation helpers — all editor actions go through these.

function cmdChangeDuration(ev, newDur) {
    wrapCommand('duration', [measureIdxOf(ev)], () => changeDuration(ev, newDur));
}

function cmdToggleDotted(ev) {
    wrapCommand('dotted', [measureIdxOf(ev)], () => toggleDotted(ev));
}

function cmdToggleTie(ev, stringIndex) {
    wrapCommand('tie', [measureIdxOf(ev)], () => toggleTie(ev, stringIndex));
}

function cmdHandleDurationKey(e, ev, mode, stringIndex) {
    // handleDurationKey returns true if it consumed the event.
    // We need to know which operation fires to snapshot correctly.
    // Simplest: snapshot the current measure, run, check if changed.
    if (!ev) return false;
    const idx = measureIdxOf(ev);
    let consumed = false;
    wrapCommand('duration-key', [idx], () => {
        consumed = handleDurationKey(e, ev, mode, stringIndex);
    });
    return consumed;
}
// ── Reflow (Phase 7d) ──────────────────────────────────────

const {
    changeDuration,
    toggleDotted,
    toggleTie,
    measureFill,
    handleDurationKey,
    toggleTriplet,
    dissolveTupletGroup,
    repositionMeasure,
} = useReflow(model);

// ── Note Input ─────────────────────────────────────────────

const {
    pendingDigit,
    handleKeydown: noteInputKeydown,
    dispose: disposeNoteInput,
} = useNoteInput(cursor, model, wrapCommand, repositionMeasure);


// ── Event insertion helpers (Phase 7d / 7-polish) ──────────────

const STD_DURS = [
    { dur: 'w', ticks: 1920 }, { dur: 'hd', ticks: 1440 },
    { dur: 'h', ticks: 960 },  { dur: 'qd', ticks: 720 },
    { dur: 'q', ticks: 480 },  { dur: 'ed', ticks: 360 },
    { dur: 'e', ticks: 240 },  { dur: 'sd', ticks: 180 },
    { dur: 's', ticks: 120 },  { dur: 't', ticks: 60 },
];

/**
 * Insert a rest event immediately after the cursor event in the current measure.
 *
 * Duration = cursor event's duration (same subdivision).
 * The measure is allowed to go overfull — repositionMeasure handles the visual
 * stretch exactly as when a duration is lengthened beyond the bar capacity.
 *
 * If the cursor is on the LAST event, the behaviour is the same (append after last).
 * Works mid-measure too: events after the insertion point are pushed forward.
 *
 * Returns the new event, or null if preconditions aren't met.
 */
function insertRestAfterCursor() {
    if (!model.value) return null;

    const measures = allMeasures.value;
    const m = measures[cursor.value.measureIndex];
    if (!m) return null;

    const tpm = model.value.ticksPerMeasure;
    const v1  = m.events.filter(ev => (ev.voice || 1) === 1).sort((a, b) => a.tick - b.tick);
    if (!v1.length) return null;

    const cursorEv    = v1[cursor.value.eventIndex] || v1[v1.length - 1];
    const cursorEvIdx = v1.indexOf(cursorEv);
    const dur         = cursorEv.duration || 'q';
    const ticks       = STD_DURS.find(d => d.dur === dur)?.ticks || 480;

    const rest = {
        id:            generateId(),
        // Temporary tick: just after cursorEv so the initial sort lands it in the right slot.
        // repositionMeasure will overwrite all ticks sequentially anyway.
        tick:          cursorEv.tick + cursorEv.ticks - 0.5,
        tickInMeasure: 0,
        measureIdx:    m.index,
        duration:      dur,
        ticks,
        voice:         1,
        isRest:        true,
        notes:         [],
        tieStart:      false,
        tieStop:       false,
        tieStartEvent: null,
        tieEndEvent:   null,
        stemDir:       null,
        flagCount:     0,
        beam1:         null,
        beam2:         null,
        beamStart:     false,
        beamEnd:       false,
        beamContinue:  false,
        beamWith:      null,
        tupletActual:  null,
        tupletNormal:  null,
        tupletType:    null,
        xPos:          0,
        originalIdx:   null,
    };

    // Splice into m.events directly after the cursor event's position in v1.
    // Find the cursor event in the full events array and insert after it.
    const fullIdx = m.events.indexOf(cursorEv);
    if (fullIdx !== -1) {
        m.events.splice(fullIdx + 1, 0, rest);
    } else {
        m.events.push(rest);
    }

    // repositionMeasure sorts v1 by tick, then reassigns all ticks sequentially.
    // The -0.5 on the new rest's tick ensures it sorts immediately after cursorEv
    // and before the next event (which retains its original tick value).
    repositionMeasure(m);

    // Move cursor to the newly inserted event
    const newV1  = m.events.filter(ev => (ev.voice || 1) === 1).sort((a, b) => a.tick - b.tick);
    const newIdx = newV1.findIndex(ev => ev.id === rest.id);
    if (newIdx !== -1) {
        moveTo(cursor.value.measureIndex, newIdx, cursor.value.stringIndex);
    }

    return rest;
}

/**
 * ArrowRight at end of incomplete measure → fill with a rest using insertRestAfterCursor.
 */
function handleArrowRight(e) {
    if (!cursorActive.value || !model.value) return false;
    if (cursor.value.mode !== 'navigate') return false;

    const measures = allMeasures.value;
    const m = measures[cursor.value.measureIndex];
    if (!m) return false;

    const v1 = m.events.filter(ev => (ev.voice || 1) === 1);

    // Only intercept when we're on the LAST event in the measure
    if (cursor.value.eventIndex < v1.length - 1) return false;

    // Only fill if the measure has remaining space (ArrowRight fill, not overfill)
    const fill = measureFill(m);
    if (fill.totalTicks >= fill.tpm) return false;

    const result = insertRestAfterCursor();
    if (!result) return false;

    e.preventDefault();
    return true;
}

/**
 * "A" key: insert a new rest event immediately after the cursor event.
 * Always allowed — measure may go overfull (intentional, same as duration lengthening).
 */
function handleInsertEvent(e) {
    if (!cursorActive.value || !model.value) return false;
    if (cursor.value.mode !== 'navigate') return false;

    const measures = allMeasures.value;
    const m = measures[cursor.value.measureIndex];
    if (!m) return false;

    let result = null;
    wrapCommand('insert', [m.index], () => {
        result = insertRestAfterCursor();
    });

    if (!result) return false;
    e.preventDefault();
    return true;
}

// Window-level mouseup finishes drags (Phase 7h) // MOVED TO MARQUEE

// ── Sidebar event listeners (Phase 7d) ─────────────────────
// TabSidebarApp dispatches CustomEvents for duration/tie/dotted buttons.

function onSidebarSetDuration(e) {
    const ev = currentEvent.value;
    if (!ev) return;
    const { durCode } = e.detail;
    if (durCode) cmdChangeDuration(ev, durCode);
}

function onSidebarToggleTie() {
    const ev = currentEvent.value;
    if (!ev) return;
    cmdToggleTie(ev, cursor.value.stringIndex);
}

function onSidebarToggleDotted() {
    const ev = currentEvent.value;
    if (!ev) return;
    cmdToggleDotted(ev);
}

onMounted(() => {
    document.addEventListener('sbn-sidebar-set-duration', onSidebarSetDuration);
    document.addEventListener('sbn-sidebar-toggle-tie', onSidebarToggleTie);
    document.addEventListener('sbn-sidebar-toggle-dotted', onSidebarToggleDotted);
    document.addEventListener('sbn-sidebar-undo', undo);
    document.addEventListener('sbn-sidebar-redo', redo);
    document.addEventListener('sbn-sidebar-copy',  handleCopy);
    document.addEventListener('sbn-sidebar-cut',   handleCut);
    document.addEventListener('sbn-sidebar-paste', handlePaste);

    // Register the XML serializer so the bridge can respond to sbn-tab-save-request
    setSaveHandler(() => {
        if (!model.value) return null;
        return modelToMusicXml(model.value, {
            title:    title.value    || undefined,
            composer: composer.value || undefined,
        });
    });

    // Register voicing-applied handler so bridge can call back into Vue
    // (deprecated in Step 10 — direct onVoicingApplied calls preferred)

    // Register structure handler for tab-initiated structural operations
    setStructureHandler((detail) => {
        const { action } = detail;
        let targetMeasureIndex = null;
        
        if (action === 'moveMeasure') {
            const { si, fromMi, toMi } = detail;
            tabModel.moveMeasure(si, fromMi, toMi);
            targetMeasureIndex = toMi;
        } else if (action === 'splitSection') {
            const { si, mi, newSectionId, newSectionName } = detail;
            // Apply the surgical hint directly
            if (tabModel.pendingStructureHint) {
                tabModel.pendingStructureHint.value = {
                    action: 'splitSection',
                    sectionIndex: si,
                    measureIndex: mi,
                    newSectionId,
                    newSectionName,
                };
            }
            targetMeasureIndex = mi;
        } else if (action === 'deleteSection') {
            const { si } = detail;
            // Apply the surgical hint directly
            if (tabModel.pendingStructureHint) {
                tabModel.pendingStructureHint.value = {
                    action: 'deleteSection',
                    sectionIndex: si,
                };
            }
        }
        
        // Sync changes back to Alpine
        syncSectionsToAlpine();
        
        // Scroll to keep target measure in viewport
        if (targetMeasureIndex !== null && editorRoot.value) {
            nextTick(() => {
                const measureElements = editorRoot.value.querySelectorAll('.sbn-tab-measure');
                const targetElement = measureElements[targetMeasureIndex];
                if (targetElement) {
                    targetElement.scrollIntoView({ 
                        behavior: 'smooth', 
                        block: 'center' 
                    });
                }
            });
        }
    });
});

onUnmounted(() => {
    disposeNoteInput();
    document.removeEventListener('sbn-sidebar-set-duration', onSidebarSetDuration);
    document.removeEventListener('sbn-sidebar-toggle-tie', onSidebarToggleTie);
    document.removeEventListener('sbn-sidebar-toggle-dotted', onSidebarToggleDotted);
    document.removeEventListener('sbn-sidebar-undo', undo);
    document.removeEventListener('sbn-sidebar-redo', redo);
    document.removeEventListener('sbn-sidebar-copy',  handleCopy);
    document.removeEventListener('sbn-sidebar-cut',   handleCut);
    document.removeEventListener('sbn-sidebar-paste', handlePaste);
});

// ── Sync cursor + note state into shared sidebar store ─────
// TabSidebarApp (separate Vue mount in the Blade right panel) reads this.

watch(
    [cursor, cursorActive, currentEvent, currentNote, pendingDigit, model],
    () => {
        sidebarStore.active           = cursorActive.value;
        sidebarStore.measureIndex     = cursor.value.measureIndex;
        sidebarStore.eventIndex       = cursor.value.eventIndex;
        sidebarStore.stringIndex      = cursor.value.stringIndex;
        sidebarStore.mode             = cursor.value.mode;
        sidebarStore.hasClipboard   = !!clipboard.value;
        sidebarStore.clipboardMode  = clipboard.value?.mode ?? '';
        sidebarStore.clipboardCount = clipboard.value?.mode === 'events'
            ? (clipboard.value?.events?.length ?? 0)
            : clipboard.value?.mode === 'note' ? 1 : 0;
        sidebarStore.currentEvent   = currentEvent.value
            ? { ...currentEvent.value, notes: currentEvent.value.notes ? [...currentEvent.value.notes] : [] }
            : null;
        sidebarStore.currentNote    = currentNote.value ? { ...currentNote.value } : null;
        sidebarStore.pendingDigit   = pendingDigit.value;
        sidebarStore.canUndo        = canUndo.value;
        sidebarStore.canRedo        = canRedo.value;
        sidebarStore.ticksPerMeasure = model.value?.ticksPerMeasure ?? 1920;

        // Phase 7d: overfill indicator for current measure
        if (model.value && cursorActive.value) {
            const measures = model.value.sections.flatMap(s => s.measures);
            const m = measures[cursor.value.measureIndex];
            if (m) {
                const fill = measureFill(m);
                sidebarStore.measureOverfill = fill.overfill;
                sidebarStore.measureTotalTicks = fill.totalTicks;
            } else {
                sidebarStore.measureOverfill = false;
                sidebarStore.measureTotalTicks = 0;
            }
        } else {
            sidebarStore.measureOverfill = false;
            sidebarStore.measureTotalTicks = 0;
        }
    },
    { flush: 'post' }
);

// ── Editor root ref ────────────────────────────────────────

const editorRoot = ref(null);

// ── Keyboard shortcut overlay (Phase 7g) ───────────────────

const showShortcuts = ref(false);

// ── Empty state helpers (Phase 7g) ─────────────────────────

const hasChordsData = computed(() => {
    if (!sections.value?.length) return false;
    return sections.value.some(s =>
        s.measures?.some(m => m.chords?.length || m.chord_names?.length)
    );
});

function onGenerateFromChords() {
    // Dispatch to Alpine — tells it to build a blank tab skeleton
    // matching the chord chart structure (one whole-rest per measure).
    document.dispatchEvent(new CustomEvent('sbn-tab-generate-from-chords'));
}

// ── Keyboard handling ──────────────────────────────────────

function onKeydown(e) {
    // Audio playback (Phase 7C)
    if (e.key === ' ' && !e.ctrlKey && !e.metaKey && !e.altKey) {
        const canPlay = (viewMode.value === 'tab' && hasData.value) ||
                        (viewMode.value === 'chords' && hasChordsData.value);
        if (canPlay) {
            e.preventDefault();
            onTransportToggle();
            return;
        }
    }
    if (e.key === 'Escape' && (transportPlaying.value || transportBeat.value > 0)) {
        e.preventDefault();
        onTransportReset();
        return;
    }

    // Undo / Redo (Phase 7e)
    if ((e.ctrlKey || e.metaKey) && !e.shiftKey && e.key === 'z') {
        e.preventDefault();
        if (canUndo.value) undo();
        return;
    }
    if ((e.ctrlKey || e.metaKey) && (e.key === 'y' || (e.shiftKey && e.key === 'z') || (e.shiftKey && e.key === 'Z'))) {
        e.preventDefault();
        if (canRedo.value) redo();
        return;
    }

    // Shortcut reference overlay (Phase 7g)
    if (e.key === '?' && !e.ctrlKey && !e.metaKey && !e.altKey && cursor.value.mode !== 'input') {
        e.preventDefault();
        showShortcuts.value = !showShortcuts.value;
        return;
    }

    // Copy / Cut / Paste (Phase 7g)
    if ((e.ctrlKey || e.metaKey) && e.key === 'c') {
        e.preventDefault();
        handleCopy();
        return;
    }
    if ((e.ctrlKey || e.metaKey) && e.key === 'x') {
        e.preventDefault();
        handleCut();
        return;
    }
    if ((e.ctrlKey || e.metaKey) && e.key === 'v') {
        e.preventDefault();
        handlePaste();
        return;
    }

    // Shift+Tab / Shift+Arrow: extend measure selection (Phase 7g)
    // Note: Shift+ArrowLeft/Right extend selection measure by measure.
    // ── Shift+←/→: note-level selection ───────────────────────
    if (e.shiftKey && cursor.value.mode === 'navigate'
        && (e.key === 'ArrowLeft' || e.key === 'ArrowRight')) {
        e.preventDefault();
        const mi = cursor.value.measureIndex;
        const ei = cursor.value.eventIndex;
        const si = cursor.value.stringIndex;
        const m  = allMeasures.value[mi];
        if (!m) return;
        const v1 = m.events.filter(ev => (ev.voice ?? 1) === 1).sort((a, b) => a.tick - b.tick);

        // Lock anchor on first Shift press
        if (_noteSelAnchorIdx.value === null) _noteSelAnchorIdx.value = ei;

        let newEi = ei;
        if (e.key === 'ArrowLeft'  && ei > 0)             newEi = ei - 1;
        if (e.key === 'ArrowRight' && ei < v1.length - 1) newEi = ei + 1;

        moveTo(mi, newEi, si);
        extendNoteSelection(v1, _noteSelAnchorIdx.value, newEi);
        return;
    }

    // Escape: clear note selection
    if (e.key === 'Escape' && hasNoteSelection.value) {
        clearNoteSelection();
        _noteSelAnchorIdx.value = null;
        e.preventDefault();
        return;
    }

    // 0. ArrowRight: fill with rests when at end of incomplete measure (Phase 7d)
    if (e.key === 'ArrowRight' && !e.shiftKey && handleArrowRight(e)) return;

    // 1. Cursor navigation — plain move always clears note selection
    const cursorConsumed = cursorKeydown(e);
    if (cursorConsumed) {
        clearNoteSelection();
        _noteSelAnchorIdx.value = null;
        return;
    }

    // 2. Duration / tie shortcuts (Phase 7d)
    if (cmdHandleDurationKey(e, currentEvent.value, cursor.value.mode, cursor.value.stringIndex)) return;

    // 3. "A" key: insert new event at end of measure (Phase 7d)
    if ((e.key === 'a' || e.key === 'A') && !e.ctrlKey && !e.metaKey && !e.altKey) {
        if (cursor.value.mode === 'navigate' && handleInsertEvent(e)) return;
    }

    // 3b. Delete removed — handleDeleteSelected now cleans up all events functionally.

    // 4. Delete selected events (Shift+Arrow range active)
    if ((e.key === 'Delete' || e.key === 'Backspace') && hasNoteSelection.value) {
        e.preventDefault();
        handleDeleteSelected();
        return;
    }

    // 5. Note input (digits 0-9, Delete, Backspace)
    noteInputKeydown(e);
}

// ── Chord name click → open voicing picker (Phase 7-int Step 5) ──

/**
 * Called when a chord name is clicked in TabMeasure.
 * Extracts current frets at that chord position (if any chordal event exists)
 * and dispatches sbn-tab-open-picker to Alpine.
 */
function onChordClick({ measureIndex, chordIndex, chordName }) {
    if (!model.value) return;

    const measures = allMeasures.value;
    const measure  = measures.find(m => m.index === measureIndex);
    if (!measure) return;

    const tpm     = model.value.ticksPerMeasure;
    const tabData = extractFretsAtChord(measure, chordIndex, tpm);

    // Build voicingKey matching the format: "ChordName@globalIdx.chordIdx"
    const voicingKey = chordName + '@' + measureIndex + '.' + chordIndex;

    voicingPickerStore.openForTab({
        chordName,
        voicingKey,
        currentFrets:       tabData ? tabData.frets    : null,
        currentPosition:    tabData ? tabData.position : 1,
        globalMeasureIndex: measureIndex,
        chordIndex,
    });
}

/**
 * Called by the bridge when Alpine dispatches sbn-tab-voicing-applied.
 * Applies the selected voicing frets into the tab model.
 */
function onVoicingApplied({ globalMeasureIndex, chordIndex, chordName, frets, position }) {
    console.log('[SBN] onVoicingApplied called', { globalMeasureIndex, chordIndex, frets });
    if (!model.value || !frets) {
        console.warn('[SBN] onVoicingApplied: early return — model:', !!model.value, 'frets:', frets);
        return;
    }

    const measures = allMeasures.value;
    const measure  = measures.find(m => m.index === globalMeasureIndex);
    if (!measure) {
        console.warn('[SBN] onVoicingApplied: measure not found for index', globalMeasureIndex, 'available:', measures.map(m => m.index));
        return;
    }

    console.log('[SBN] onVoicingApplied: applying to measure', globalMeasureIndex, 'chordIndex', chordIndex, 'frets', frets, 'events count:', measure.events.length);
    const tpm = model.value.ticksPerMeasure;
    wrapCommand('voicing-apply', [globalMeasureIndex], () => {
        applyVoicingToChord(measure, chordIndex, tpm, frets);
    });
    console.log('[SBN] onVoicingApplied: done, measure events now:', measure.events.map(e => e.notes));
}

/**
 * Called when user clicks 🔍 next to a chord name.
 * Extracts frets from the tab, POSTs to identifySingle, shows a confirmation.
 */
async function onChordIdentify({ measureIndex, chordIndex, chordName }) {
    console.log('[SBN identify] clicked', { measureIndex, chordIndex, chordName });
    if (!model.value) { console.warn('[SBN identify] no model'); return; }

    const measures = allMeasures.value;
    const measure  = measures.find(m => m.index === measureIndex);
    if (!measure) { console.warn('[SBN identify] measure not found', measureIndex); return; }

    const tpm     = model.value.ticksPerMeasure;
    const tabData = extractFretsAtChord(measure, chordIndex, tpm);
    console.log('[SBN identify] tabData', tabData);

    if (!tabData) {
        _showIdentifyToast('No chord found at this position', 'warn');
        return;
    }

    // Build harmonic context from neighbor chord names + song key
    const allMeasureList = allMeasures.value;
    const measureFlatIdx = allMeasureList.findIndex(m => m.index === measureIndex);
    const prevChords = [];
    const nextChords = [];
    if (measureFlatIdx !== -1) {
        // Collect up to 2 chord names before and after this chord slot
        // Walking backwards through chordNames of preceding measures
        for (let mi = measureFlatIdx; mi >= 0 && prevChords.length < 2; mi--) {
            const m = allMeasureList[mi];
            const names = m.chordNames || [];
            const startCi = mi === measureFlatIdx ? chordIndex - 1 : names.length - 1;
            for (let ci = startCi; ci >= 0 && prevChords.length < 2; ci--) {
                if (names[ci]) prevChords.unshift(names[ci]);
            }
        }
        // Walking forwards
        for (let mi = measureFlatIdx; mi < allMeasureList.length && nextChords.length < 2; mi++) {
            const m = allMeasureList[mi];
            const names = m.chordNames || [];
            const startCi = mi === measureFlatIdx ? chordIndex + 1 : 0;
            for (let ci = startCi; ci < names.length && nextChords.length < 2; ci++) {
                if (names[ci]) nextChords.push(names[ci]);
            }
        }
    }

    try {
        console.log('[SBN identify] fetching for frets', tabData.frets);
        const csrf = document.querySelector('meta[name=csrf-token]')?.content;
        const resp = await fetch('/api/admin/leadsheets/identify-single', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                ...(csrf ? { 'X-CSRF-TOKEN': csrf } : {}),
            },
            body: JSON.stringify({
                frets:       tabData.frets,
                position:    tabData.position,
                song_key:    songKey.value || null,
                prev_chords: prevChords,
                next_chords: nextChords,
            }),
        });

        if (!resp.ok) {
            console.warn('[SBN] identifySingle HTTP error', resp.status);
            _showIdentifyToast('Identification failed', 'error');
            return;
        }

        const data = await resp.json();
        console.log('[SBN identify] server response', data);
        if (!data.success || !data.name || data.confidence === 'none') {
            _showIdentifyToast('Could not identify chord', 'warn');
            return;
        }

        const identifiedName = data.name;

        // Always show confirm — even if same name, user may want to verify
        _showIdentifyConfirm(chordName, identifiedName, measureIndex, chordIndex);

    } catch (err) {
        console.error('[SBN] onChordIdentify fetch error:', err);
        _showIdentifyToast('Identification request failed', 'error');
    }
}

function _showIdentifyToast(msg, type = 'info') {
    // Use global sbnToast if available (defined in edit_blade.php)
    if (typeof window !== 'undefined' && typeof window.sbnToast === 'function') {
        window.sbnToast(msg, type);
    } else {
        console.log('[SBN identify]', msg);
    }
}

function _showIdentifyConfirm(oldName, newName, measureIndex, chordIndex) {
    // Dispatch a custom event that Alpine can handle to show a confirm toast
    document.dispatchEvent(new CustomEvent('sbn-tab-identify-result', {
        detail: { oldName, newName, measureIndex, chordIndex },
    }));
}

// ── Copy / Cut / Paste handlers (Phase 7g) ─────────────────
// If a note-level selection (Shift+←/→) is active, copy/cut only
// those events. Otherwise copy/cut the whole measure.

function handleCopy() {
    // Pass the actual event object + string index — same as delete uses
    selectionCopy(currentEvent.value, cursor.value.stringIndex, selectedEvents.value);
}

function handleCut() {
    const op = prepareCut(currentEvent.value, cursor.value.stringIndex, selectedEvents.value);
    if (!op) return;
    wrapCommand('cut', op.affectedIndices, op.mutate);
    clearNoteSelection();
    _noteSelAnchorIdx.value = null;
}

function handlePaste() {
    const op = preparePaste(currentEvent.value, cursor.value.stringIndex);
    if (!op) return;
    wrapCommand('paste', op.affectedIndices, op.mutate);
    clearNoteSelection();
    _noteSelAnchorIdx.value = null;
}

/**
 * Delete all selected events (Shift+Arrow range).
 * Each selected event is removed from its measure; repositionMeasure
 * recalculates ticks/xPos/actualTicks afterward.
 * Cursor lands on the event immediately before the first deleted one,
 * or event 0 if deleting from the start.
 */
function handleDeleteSelected() {
    if (!hasNoteSelection.value || !model.value) return false;

    const frozenIds = new Set(selectedEvents.value);
    const affectedMeasureIndices = getSelectedMeasureIndices();
    if (affectedMeasureIndices.length === 0) return false;

    const measures = allMeasures.value;
    const anchorMeasure = measures.find(m => m.index === affectedMeasureIndices[0]);
    let firstSelectedIdx = 0;
    
    if (anchorMeasure) {
        const v1Before = anchorMeasure.events.filter(e => (e.voice || 1) === 1).sort((a, b) => a.tick - b.tick);
        const idx = v1Before.findIndex(e => frozenIds.has(e.id));
        if (idx !== -1) firstSelectedIdx = idx;
    }

    wrapCommand('delete-selected', affectedMeasureIndices, () => {
        affectedMeasureIndices.forEach(mi => {
            const m = measures.find(measure => measure.index === mi);
            if (!m) return;
            
            m.events = m.events.filter(e => !frozenIds.has(e.id));
            
            // If measure is now empty, insert a whole rest so it remains accessible
            const v1 = m.events.filter(e => (e.voice || 1) === 1);
            if (v1.length === 0) {
                const tpm = model.value.ticksPerMeasure;
                m.events.push({
                    id: generateId(),
                    tick: m.index * tpm, tickInMeasure: 0,
                    measureIdx: m.index, duration: 'w', ticks: tpm,
                    voice: 1, isRest: true, notes: [],
                    tieStart: false, tieStop: false, tieStartEvent: null, tieEndEvent: null,
                    stemDir: null, flagCount: 0,
                    beam1: null, beam2: null, beamStart: false, beamEnd: false, beamContinue: false,
                    beamWith: null, noBeamBar: false,
                    tupletActual: null, tupletNormal: null, tupletType: null, tupletBracket: false,
                    xPos: 0, originalIdx: null,
                });
            }
            repositionMeasure(m);
        });
    });

    if (anchorMeasure) {
        const v1After = anchorMeasure.events.filter(e => (e.voice || 1) === 1).sort((a, b) => a.tick - b.tick);
        const newIdx = v1After.length === 0 ? 0 : Math.max(0, firstSelectedIdx - 1);
        moveTo(anchorMeasure.index, newIdx, cursor.value.stringIndex);
    }

    clearNoteSelection();
    _noteSelAnchorIdx.value = null;
    return true;
}



function onEditorFocus() {
    // Intentionally left minimal — cursor activates on click or first keypress
}

function onEditorClick() {
    editorRoot.value?.focus({ preventScroll: true });
}

// ── Cursor mousedown / drag handlers ──────────────────────────

function onCursorMousedownEvent({ measureIndex, eventId, stringIndex, event }) {
    clickEvent(measureIndex, eventId, stringIndex);
    setSelectedEvents([eventId]);
    _noteSelAnchorIdx.value = null;
    editorRoot.value?.focus({ preventScroll: true });
}

function onCursorMouseenterEvent({ eventId }) { }

function onCursorMousedownRest({ measureIndex, eventId, event }) {
    clickRest(measureIndex, eventId);
    setSelectedEvents([eventId]);
    _noteSelAnchorIdx.value = null;
    editorRoot.value?.focus({ preventScroll: true });
}

function onCursorMouseenterRest({ eventId }) { }

// ── Marquee Selection ──────────────────────────────────────────────

const notationRoot = ref(null);
const marqueeState = ref(null);

const marqueeStyle = computed(() => {
    if (!marqueeState.value) return {};
    const { startX, startY, currentX, currentY } = marqueeState.value;
    const left = Math.min(startX, currentX);
    const top = Math.min(startY, currentY);
    const width = Math.abs(currentX - startX);
    const height = Math.abs(currentY - startY);
    return {
        position: 'absolute',
        left: `${left}px`,
        top: `${top}px`,
        width: `${width}px`,
        height: `${height}px`,
        backgroundColor: 'rgba(243, 156, 18, 0.15)',
        border: '1px solid rgba((243, 156, 18, 0.25)',
        pointerEvents: 'none',
        zIndex: 50
    };
});

function onNotationMousedown(e) {
    if (e.target.closest('.sbn-tab-chord-name, button, .sbn-ve-section-header')) return;
    if (e.target.closest('.sbn-cursor-hit')) return; // handled by note mousedown

    const rect = notationRoot.value.getBoundingClientRect();
    const x = e.clientX - rect.left;
    const y = e.clientY - rect.top;

    marqueeState.value = {
        startX: x, startY: y, currentX: x, currentY: y,
        clientStartX: e.clientX, clientStartY: e.clientY,
        isDragging: false
    };

    const measureNode = e.target.closest('.sbn-tab-measure');
    const clickedMeasureIndex = measureNode ? parseInt(measureNode.getAttribute('data-measure'), 10) : null;

    if (!e.shiftKey && !e.ctrlKey && !e.metaKey) {
        clearNoteSelection();
        _measureSelAnchor.value = null;
    }

    const mousemoveHandler = (moveEvent) => {
        if (!marqueeState.value) return;
        const cx = moveEvent.clientX - rect.left;
        const cy = moveEvent.clientY - rect.top;
        marqueeState.value.currentX = cx;
        marqueeState.value.currentY = cy;

        const box = {
            left: Math.min(marqueeState.value.clientStartX, moveEvent.clientX),
            right: Math.max(marqueeState.value.clientStartX, moveEvent.clientX),
            top: Math.min(marqueeState.value.clientStartY, moveEvent.clientY),
            bottom: Math.max(marqueeState.value.clientStartY, moveEvent.clientY)
        };

        const dist = Math.max(box.right - box.left, box.bottom - box.top);
        if (dist > 3) {
            marqueeState.value.isDragging = true;
            const intersectedIds = new Set();
            document.querySelectorAll('.sbn-cursor-hit').forEach(el => {
                const r = el.getBoundingClientRect();
                if (r.left < box.right && r.right > box.left && r.top < box.bottom && r.bottom > box.top) {
                    intersectedIds.add(el.getAttribute('data-event-id'));
                }
            });
            // If dragging, we dynamically update selection
            setSelectedEvents(intersectedIds);
        }
    };

    const mouseupHandler = (upEvent) => {
        window.removeEventListener('mousemove', mousemoveHandler);
        window.removeEventListener('mouseup', mouseupHandler);

        if (marqueeState.value && !marqueeState.value.isDragging && clickedMeasureIndex !== null) {
            // Treat as a click on the measure background
            const clickedM = allMeasures.value.find(m => m.index === clickedMeasureIndex);
            if (clickedM) {
                const clickedV1 = clickedM.events.filter(ev => (ev.voice ?? 1) === 1).map(ev => ev.id);
                if (e.shiftKey) {
                    const anchorMi = _measureSelAnchor.value ?? cursor.value.measureIndex;
                    const lo = Math.min(anchorMi, clickedMeasureIndex);
                    const hi = Math.max(anchorMi, clickedMeasureIndex);
                    const next = new Set();
                    for (let i = lo; i <= hi; i++) {
                        const m = allMeasures.value[i];
                        if (m) m.events.filter(ev => (ev.voice ?? 1) === 1).forEach(ev => next.add(ev.id));
                    }
                    setSelectedEvents(next);
                } else if (e.ctrlKey || e.metaKey) {
                     const next = new Set(selectedEvents.value);
                     let allSelected = clickedV1.length > 0 && clickedV1.every(id => next.has(id));
                     clickedV1.forEach(id => allSelected ? next.delete(id) : next.add(id));
                     setSelectedEvents(next);
                     _measureSelAnchor.value = clickedMeasureIndex;
                } else {
                    setSelectedEvents(clickedV1);
                    _measureSelAnchor.value = clickedMeasureIndex;
                }
            }
        }

        marqueeState.value = null;
    };

    window.addEventListener('mousemove', mousemoveHandler);
    window.addEventListener('mouseup', mouseupHandler);
    editorRoot.value?.focus({ preventScroll: true });
}

// ── Measure context menu (Phase 2b Step 3) ────────────────────

/**
 * Fired by TabMeasure on right-click anywhere in the measure.
 * Ensures the right-clicked measure is selected, then shows the
 * context menu via the global showContextMenu singleton.
 */
function onMeasureContextMenu({ measureIndex, event }) {
    const indices = getSelectedMeasureIndices();
    
    if (!indices.includes(measureIndex)) {
        const all = allMeasures.value;
        const clickedM = all[measureIndex];
        if (clickedM) {
            const clickedV1 = clickedM.events.filter(e => (e.voice ?? 1) === 1).map(e => e.id);
            setSelectedEvents(clickedV1);
        }
        _measureSelAnchor.value = measureIndex;
    }

    const currentIndices = getSelectedMeasureIndices();
    const single  = currentIndices.length === 1;
    const m       = allMeasures.value[measureIndex];
    const chordName = m?.chordNames?.[0];
    const items   = [];

    const hasPartialBarSelection = single && selectedEvents.value.size > 0 && selectedEvents.value.size < m.events.filter(e => (e.voice ?? 1) === 1).length;
    const isSingleNoteSelection = selectedEvents.value.size === 1;

    if (single) {
        if (chordName) {
            items.push(
                { id: 'openVoicingPicker', label: 'Open voicing picker', icon: '🎸', group: 'chord' },
                { id: 'identifyChord',     label: 'Identify chord from tab', icon: '🔍', group: 'chord' },
            );
        }
        items.push(
            { id: 'copy', label: isSingleNoteSelection ? 'Copy note' : hasPartialBarSelection ? 'Copy selected beat(s)' : 'Copy bar', shortcut: 'Ctrl+C', group: 'clipboard' },
            { id: 'cut',  label: isSingleNoteSelection ? 'Cut note' : hasPartialBarSelection ? 'Cut selected beat(s)' : 'Cut bar',  shortcut: 'Ctrl+X', group: 'clipboard' },
            { id: 'paste', label: 'Paste', shortcut: 'Ctrl+V', group: 'clipboard', disabled: !clipboard.value },
        );
        items.push(
            { id: 'insertBarAfter',  label: 'Insert bar after',  group: 'structure' },
            { id: 'insertBarBefore', label: 'Insert bar before', group: 'structure' },
            { divider: true, group: 'structure' },
            { id: 'toggleRepeatStart', label: m?.repeatStart ? 'Remove Start Repeat' : 'Start Repeat', group: 'structure' },
            { id: 'toggleRepeatEnd',   label: m?.repeatEnd   ? 'Remove End Repeat'   : 'End Repeat',   group: 'structure' },
            { id: 'deleteBar',       label: 'Delete bar', danger: true, group: 'danger' },
        );
    } else {
        items.push(
            { id: 'copy', label: `Copy selection (${indices.length} bars)`,   shortcut: 'Ctrl+C', group: 'clipboard' },
            { id: 'cut',  label: `Cut selection (${indices.length} bars)`,    shortcut: 'Ctrl+X', group: 'clipboard' },
            { id: 'paste', label: 'Paste', shortcut: 'Ctrl+V', group: 'clipboard', disabled: !clipboard.value },
            { id: 'deleteSelection', label: `Delete ${indices.length} bars`, danger: true, group: 'danger' },
        );
    }

    if (typeof window.showContextMenu === 'function') {
        window.showContextMenu(event, items, (actionId) => {
            handleTabContextAction(actionId, measureIndex);
        });
    }

    editorRoot.value?.focus({ preventScroll: true });
}

/**
 * Context menu actions for the tab view. Structural ops mutate the Vue model
 * and sync harmony to Alpine via sbn-tab-sections-sync.
 * Clipboard ops are stubs pending measure-level tab copy/paste.
 */
function handleTabContextAction(actionId, measureIndex) {
    switch (actionId) {
        case 'openVoicingPicker': {
            const m = allMeasures.value[measureIndex];
            if (m?.chordNames?.[0]) {
                onChordClick({ measureIndex, chordIndex: 0, chordName: m.chordNames[0] });
            }
            break;
        }
        case 'identifyChord': {
            const m = allMeasures.value[measureIndex];
            if (m?.chordNames?.[0]) {
                onChordIdentify({ measureIndex, chordIndex: 0, chordName: m.chordNames[0] });
            }
            break;
        }
        case 'insertBarAfter': {
            const c = globalToSectionMeasure(measureIndex);
            if (!c) break;
            const G = globalMeasureIndex(c.si, c.mi);
            wrapCommand('Insert bar after (tab)', [], () => {
                insertMeasureAfter(c.si, c.mi);
                applyChordVoicingOps({ voicingReindexAt: G + 1, voicingDelta: 1 });
                syncTabSectionsToAlpine();
            }, structuralUndoOptions);
            clearNoteSelection();
            break;
        }
        case 'insertBarBefore': {
            const c = globalToSectionMeasure(measureIndex);
            if (!c) break;
            const G = globalMeasureIndex(c.si, c.mi);
            wrapCommand('Insert bar before (tab)', [], () => {
                insertMeasureBefore(c.si, c.mi);
                applyChordVoicingOps({ voicingReindexAt: G, voicingDelta: 1 });
                syncTabSectionsToAlpine();
            }, structuralUndoOptions);
            clearNoteSelection();
            break;
        }
        case 'deleteBar': {
            const c = globalToSectionMeasure(measureIndex);
            if (!c) break;
            const G = globalMeasureIndex(c.si, c.mi);
            const voicingDeletes = captureVoicingDeletesByGlobalIndices([G]);
            wrapCommand('Delete bar (tab)', [], () => {
                applyChordVoicingOps({ voicingDeletes });
                deleteMeasure(c.si, c.mi);
                syncTabSectionsToAlpine();
            }, structuralUndoOptions);
            clearNoteSelection();
            break;
        }
        case 'toggleRepeatStart': {
            const m = allMeasures.value[measureIndex];
            if (m) {
                wrapCommand('Toggle Repeat Start', [], () => {
                    m.repeatStart = !m.repeatStart;
                    syncTabSectionsToAlpine();
                }, structuralUndoOptions);
            }
            break;
        }
        case 'toggleRepeatEnd': {
            const m = allMeasures.value[measureIndex];
            if (m) {
                wrapCommand('Toggle Repeat End', [], () => {
                    m.repeatEnd = !m.repeatEnd;
                    syncTabSectionsToAlpine();
                }, structuralUndoOptions);
            }
            break;
        }
        case 'deleteSelection': {
            const indices = getSelectedMeasureIndices();
            const voicingDeletes = captureVoicingDeletesByGlobalIndices(indices);
            wrapCommand('Delete bars (tab)', [], () => {
                applyChordVoicingOps({ voicingDeletes });
                deleteMeasuresByGlobalIndices(indices);
                syncTabSectionsToAlpine();
            }, structuralUndoOptions);
            clearNoteSelection();
            break;
        }
        case 'copy':
            handleCopy();
            break;
        case 'cut':
            handleCut();
            break;
        case 'paste':
            handlePaste();
            break;
    }
}

/**
 * Click on the "?" placeholder in an empty bar.
 * Tells Alpine to open the chord name text-input picker for that measure.
 */
function onChordNameNeeded({ measureIndex, chordIndex, clientX, clientY }) {
    // Use a fake DOMRect so openAt can compute top/left from the click coords
    chordPickerStore.openAt({ bottom: clientY, left: clientX }, measureIndex, chordIndex, '');
}
function onChordContextMenu({ measureIndex, chordIndex, chordName, event }) {
    const items = [
        { id: 'openVoicingPicker', label: 'Rename chord (open picker)', icon: '✏️', group: 'chord' },
        { id: 'changeVoicing',     label: 'Change voicing',             icon: '🎸', group: 'chord' },
        { id: 'identifyChord',     label: 'Identify chord from tab',    icon: '🔍', group: 'chord' },
    ];
    if (typeof window.showContextMenu === 'function') {
        window.showContextMenu(event, items, (actionId) => {
            // Reuse the existing action handlers — all three are already wired
            // in handleTabContextAction and operate on measureIndex.
            handleTabContextAction(actionId, measureIndex);
        });
    }
}

/**
 * Row resize: move last bar of current row to next row (shrink)
 */
function rowShrink(si, ri) {
    if (!model.value) { console.log('[SBN rowShrink] no model'); return; }
    const sec = model.value.sections[si];
    if (!sec) { console.log('[SBN rowShrink] no section'); return; }
    
    wrapCommand('Shrink row', [], () => {
        // Initialize lineBreaks from current layout if it doesn't exist
        if (!sec.lineBreaks) {
            const rows = measureRows(sec);
            sec.lineBreaks = rows.map(row => row.length);
        }
        
        if (ri >= sec.lineBreaks.length) return;
        if (sec.lineBreaks[ri] <= 1) return; // Can't shrink if row has only 1 bar
        
        sec.lineBreaks[ri] -= 1;
        
        if (ri + 1 < sec.lineBreaks.length) {
            sec.lineBreaks[ri + 1] += 1;
        } else {
            sec.lineBreaks.push(1); // Add new row with 1 bar
        }
        
        // Sync new layout back to Alpine
        syncTabSectionsToAlpine();
    }, structuralUndoOptions);
}

/**
 * Row resize: pull first bar from next row into current row (grow)
 */
function rowGrow(si, ri) {
    if (!model.value) { console.log('[SBN rowGrow] no model'); return; }
    const sec = model.value.sections[si];
    if (!sec) { console.log('[SBN rowGrow] no section'); return; }
    
    wrapCommand('Grow row', [], () => {
        if (!sec.lineBreaks) {
            const rows = measureRows(sec);
            sec.lineBreaks = rows.map(row => row.length);
        }
        
        if (ri >= sec.lineBreaks.length - 1) return;
        if (sec.lineBreaks[ri + 1] <= 0) return; // Can't grow if next row empty
        
        sec.lineBreaks[ri] += 1;
        sec.lineBreaks[ri + 1] -= 1;
        
        if (sec.lineBreaks[ri + 1] === 0) {
            sec.lineBreaks.splice(ri + 1, 1);
        }
        
        // Sync new layout back to Alpine
        syncTabSectionsToAlpine();
    }, structuralUndoOptions);
}

function measureRows(section) {
    // Phase A: Vue now owns layout and lineBreaks.
    // Read lineBreaks directly from the Vue section model.
    const lineBreaks = section.lineBreaks;

    if (lineBreaks && lineBreaks.length) {
        const rows = [];
        let idx = 0;
        lineBreaks.forEach(count => {
            if (idx < section.measures.length) {
                // Bug 5 fix: mark rows from lineBreaks so emptySlots skips padding.
                const row = section.measures.slice(idx, idx + count);
                row._fromLineBreaks = true;
                    row._intendedCount = count;
                rows.push(row);
                idx += count;
            }
        });
        // Any remaining measures beyond lineBreaks sum go into a final row
        if (idx < section.measures.length) {
            const row = section.measures.slice(idx);
            row._fromLineBreaks = true;
                row._intendedCount = row.length; // Just fill the remaining space
            rows.push(row);
        }
        return rows;
    }

    // Fallback: uniform rows of LAYOUT.measuresPerRow
    const rows = [];
    for (let i = 0; i < section.measures.length; i += LAYOUT.measuresPerRow) {
            const row = section.measures.slice(i, i + LAYOUT.measuresPerRow);
            row._intendedCount = LAYOUT.measuresPerRow;
            rows.push(row);
    }
    return rows;
}

function emptySlots(row) {
    // lineBreaks rows are marked by measureRows() — they never need padding
    // because each row's width is intentional (measures stretch to fill via flex).
    // Only pad fallback rows (uniform LAYOUT.measuresPerRow) so all rows in a
    // section have the same number of visual slots.
    if (row._fromLineBreaks) return [];
        const intended = row._intendedCount || LAYOUT.measuresPerRow || 4;
        if (row.length >= intended) return [];
        const count = intended - row.length;
    return count > 0 ? Array.from({ length: count }, (_, i) => i) : [];
}

/**
 * Get the next measure after the given global index (for cross-measure ties).
 */
function getNextMeasure(globalIndex) {
    const measures = allMeasures.value;
    const idx = measures.findIndex(m => m.index === globalIndex);
    if (idx === -1 || idx >= measures.length - 1) return null;
    return measures[idx + 1];
}

/**
 * Is the measure after globalIndex the first in its section?
 */
function isNextMeasureFirstOfSection(globalIndex) {
    if (!model.value) return false;
    const nextMeasure = getNextMeasure(globalIndex);
    if (!nextMeasure) return false;
    for (const section of model.value.sections) {
        if (section.measures.length && section.measures[0].index === nextMeasure.index) {
            return true;
        }
    }
    return false;
}

// ── Expose for Alpine integration ──────────────────────────

defineExpose({
    getTabModelJson() {
        return model.value ? JSON.stringify(serializeModel()) : null;
    },
    rebuild() {
        buildModel();
    },
});
</script>

<style>
.sbn-tab-note-text.sbn-playing {
    fill: #ef4444 !important;
}
.sbn-tab-rest.sbn-playing {
    fill: #ef4444 !important;
}
</style>