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
        <!-- The two notation layers (Melody = Tab I / melody_tab_xml, Chords = Tab II /
             chord_tab_xml) are top-level tabs. Both run under viewMode 'tab'; the active
             tabLayer selects which staff. Grid = the chord-cell grid (viewMode 'chords'). -->
        <div class="sbn-ve-tabs">
            <button class="sbn-ve-tab" :class="{ 'is-active': viewMode === 'chords' }"
                    @click="setViewMode('chords')">Grid</button>
            <button v-show="hasChordTab" class="sbn-ve-tab" :class="{ 'is-active': viewMode === 'tab' && tabLayer === 'chord' }"
                    @click="selectTabLayerView('chord')">Chords</button>
            <button v-show="hasMelodyTab" class="sbn-ve-tab" :class="{ 'is-active': viewMode === 'tab' && tabLayer === 'melody' }"
                    @click="selectTabLayerView('melody')">Melody</button>
            <button class="sbn-ve-tab" :class="{ 'is-active': viewMode === 'analysis' }"
                    @click="setViewMode('analysis')">Analysis</button>
            <button class="sbn-ve-tab" :class="{ 'is-active': videoSidebarOpen }"
                    @click="toggleVideoSidebar">🎬 Video</button>
            <span v-if="tuning === 'drop-d'" class="sbn-ve-tuning-badge">Drop D</span>
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
                            <label class="sbn-ve-section-bpr" title="Bars per row">
                                <span>cols</span>
                                <input
                                    type="number"
                                    min="1" max="12"
                                    :value="section.lineBreaks?.[0] ?? 4"
                                    @change="tabModel.setBarsPerRow(si, +$event.target.value)"
                                    @keydown.enter="$event.target.blur()"
                                    @click.stop
                                />
                            </label>
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
                                        :show-clef="si === 0 && ri === 0 && li === 0"
                                        :time-signature="timeSignature"
                                        :ticks-per-measure="model.ticksPerMeasure"
                                        :next-measure="getNextMeasure(measure.index)"
                                        :is-next-first-of-section="isNextMeasureFirstOfSection(measure.index)"
                                        :chord-names="measure.chordNames || []"
                                        :cursor="cursorState"
                                        :bars-per-row="row._intendedCount"
                                        :flex-pct="row._gracePct != null ? row._gracePct[li] : (row._pickupPct != null ? (li === 0 ? row._pickupPct : row._regularPct) : null)"
                                        :pending-digit="pendingDigit"
                                        :grace-mode="graceMode"
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
                                         class="sbn-tab-measure" :style="{ flex: `0 0 ${row._graceEmptyPct != null ? row._graceEmptyPct : (100 / row._intendedCount)}%`, visibility: 'hidden' }">
                                    </div>

                                </div>

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
        <!-- Video sidebar slot — only mounted when sidebar is open so the player
             doesn't exist in the DOM when the panel is hidden. -->
        <Teleport v-if="videoSidebarOpen" to="#sbn-video-slot">
            <div class="sbn-video-sidebar-panel">
                <VideoSyncEditor
                    ref="videoSyncEditorRef"
                    :video-id="videoSync.videoId.value"
                    :video-type="videoSync.videoType.value"
                    :sorted-mappings="videoSync.sortedMappings.value"
                    :video-time="videoSync.videoTime.value"
                    :player-ref="videoSync.playerRef.value"
                    :sequence-length="_expandedChordSequence.length"
                    :tap-cursor-gi="videoSync.tapCursorGi.value"
                    :tap-cursor-pass="tapCursorPass"
                    @set-video-id="({ id, type }) => videoSync.setVideoId(id, type)"
                    @add-mapping="(m) => videoSync.addMapping(m.measureIndex, m.videoTime)"
                    @remove-mapping="(mi) => videoSync.removeMapping(mi)"
                    @remove-mapping-identity="(m) => removeMappingByIdentity(m)"
                    @seek-to-mapping="(m) => seekToMapping(m)"
                    @clear-mappings="videoSync.clearMappings()"
                    @distribute-markers="() => { console.log('[TabEditor] distribute-markers received'); videoSync.distributeMarkers(); }"
                    @untap="videoSync.untap()"
                    @timeupdate="videoSync.onVideoTimeUpdate($event)"
                    @play-state-change="videoSync.onVideoPlayStateChange($event)"
                    @player-ref-change="videoSync.playerRef.value = $event"
                    @toggle-playback="onTransportToggle"
                    @tap-cursor-change="(pos) => { videoSync.tapCursor.value = pos; }"
                />
            </div>
        </Teleport>
        
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
                            <div class="sbn-tab-shortcut-row"><kbd>Tab</kbd><span>Cycle tabs (Grid / Chords / Melody)</span></div>
                            <div class="sbn-tab-shortcut-row"><kbd>V</kbd><span>Toggle video sidebar</span></div>
                            <div class="sbn-tab-shortcut-row"><kbd>Home / End</kbd><span>First / last event</span></div>
                            <div class="sbn-tab-shortcut-row"><kbd>Esc</kbd><span>Cancel / back to navigate</span></div>
                        </div>
                        <div class="sbn-tab-shortcut-group">
                            <div class="sbn-tab-shortcut-group-title">Note Entry</div>
                            <div class="sbn-tab-shortcut-row"><kbd>0 – 9</kbd><span>Enter fret number</span></div>
                            <div class="sbn-tab-shortcut-row"><kbd>Del / ⌫</kbd><span>Remove note on string</span></div>
                            <div class="sbn-tab-shortcut-row"><kbd>Ctrl+↑ ↓</kbd><span>Shift note / selection to adjacent string (transposes fret)</span></div>
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

        <!-- Transpose modal (Shift+T / Actions menu) -->
        <transition name="sbn-tab-overlay-fade">
            <div v-if="showTranspose" class="sbn-tab-shortcut-overlay" @mousedown.self="showTranspose = false">
                <div class="sbn-tab-shortcut-panel sbn-transpose-panel">
                    <div class="sbn-tab-shortcut-header">
                        <span>Transpose Sheet</span>
                        <button class="sbn-tab-shortcut-close" @click="showTranspose = false">✕</button>
                    </div>
                    <div class="sbn-transpose-body">
                        <div class="sbn-transpose-row">
                            <label class="sbn-transpose-label">From key</label>
                            <span class="sbn-transpose-from">{{ songKey || '—' }}</span>
                        </div>
                        <div class="sbn-transpose-row">
                            <label class="sbn-transpose-label">To key</label>
                            <select class="sbn-transpose-select" v-model="transposeTargetKey" @change="onTransposeKeySelect">
                                <option value="">— pick a key —</option>
                                <option v-for="k in transposeKeyOptions" :key="k.key" :value="k.key">{{ k.label }}</option>
                            </select>
                        </div>
                        <div class="sbn-transpose-row">
                            <label class="sbn-transpose-label">Semitones</label>
                            <input class="sbn-transpose-semitones" type="number" min="-11" max="11"
                                   v-model.number="transposeSemitones" />
                        </div>
                        <div class="sbn-transpose-actions">
                            <button class="sbn-btn sbn-btn-secondary" @click="showTranspose = false">Cancel</button>
                            <button class="sbn-btn sbn-btn-primary" :disabled="transposeSemitones === 0"
                                    @click="onConfirmTranspose">Transpose</button>
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
            :tempo="bridgeTempo"
            :beats-per-measure="beatsPerMeasure"
            :view-mode="viewMode"
            :show-mixer="true"
            :volume-chord="volumeChord"
            :volume-rhythm="volumeRhythm"
            :volume-tab="volumeTab"
            @toggle="onTransportToggle"
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

import { computed, defineExpose, ref, onMounted, onUnmounted, watch, nextTick, provide, Teleport } from 'vue';
import ChordGridView from './components/ChordGridView.vue';
import VoicingPicker from './components/VoicingPicker.vue';
import VideoSyncEditor from './components/VideoSyncEditor.vue';
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
import { initTabModelFacade, registerSetChordName, registerSetChordNameWithVoicing, registerSetTempo, registerSetTimeSignature } from './utils/tabModelFacade.js';
import { extractFretsAtChord, applyVoicingToChord } from './composables/useChordSync.js';
import TabMeasure from './components/TabMeasure.vue';
import { useChordGridOps }        from './composables/useChordGridOps.js';
import { useGridSelection }       from './composables/useGridSelection.js';
import { useChordClipboard }      from './composables/useChordClipboard.js';
import { useChordPickerStore }    from './composables/useChordPickerStore.js';
import { useVoicingPickerStore }  from './composables/useVoicingPickerStore.js';
import { useAudioEngine }         from './composables/useAudioEngine.js';
import { useChordAudio }          from './composables/useChordAudio.js';
import { useVideoSync }           from './composables/useVideoSync.js';
import { getAudioEngine }         from '../audio/engine/AudioEngine.js';
import { tabModelToEvents }       from '../audio/adapters/tabMeasureToEvents.js';
import { chordVoicingsToEvents }  from '../audio/adapters/chordVoicingsToEvents.js';
import { expandModelSequence, giAtPosition, firstPositionForGi } from '../audio/adapters/expandMeasureSequence.js';
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

// Tab layer: which notation layer is active within the Tab view
const tabLayer = ref('melody'); // 'melody' | 'chord'
provide('tabLayer', tabLayer);

function setTabLayer(layer) {
    if (layer === tabLayer.value) return;
    // Optimistic: flip the pill, ask Alpine to serialize-out + load-in. If Alpine
    // can't capture the outgoing layer it aborts and fires sbn-tab-layer-revert,
    // which snaps the pill back so it never lies about the loaded layer.
    tabLayer.value = layer;
    inlineRenameTarget.value = null;
    document.dispatchEvent(new CustomEvent('sbn-tab-layer-changed', {
        detail: { layer }
    }));
}
provide('setTabLayer', setTabLayer);

// Alpine aborted a layer switch (serialize failed/timed out) — restore the pill
// to the layer that is actually still loaded.
function onTabLayerRevert(e) {
    const loaded = e.detail?.layer;
    if (loaded && loaded !== tabLayer.value) {
        tabLayer.value = loaded;
    }
}

// External request (Actions dropdown "Import into Melody/Chords") to select a
// notation layer + enter the tab view. Routes through selectTabLayerView so the
// serialize-out → graft-in round-trip runs exactly as a pill click would.
function onExternalSetLayer(e) {
    const layer = e.detail?.layer;
    if (layer === 'melody' || layer === 'chord') {
        selectTabLayerView(layer);
    }
}

// Video sidebar open state — mirrors Alpine's videoSidebarOpen, driven via CustomEvent
const videoSidebarOpen = ref(false);


function setViewMode(mode) {
    viewMode.value = mode;
    inlineRenameTarget.value = null;  // cancel any open inline rename input
    document.dispatchEvent(new CustomEvent('sbn-tab-view-changed', {
        detail: { viewMode: mode }
    }));
}

// Top-level Melody/Chords tabs: enter the tab view (if not already) and switch
// to the requested layer. setTabLayer() is a no-op when already on that layer
// and otherwise drives the serialize-out → graft-in round-trip (with abort/revert).
function selectTabLayerView(layer) {
    if (viewMode.value !== 'tab') {
        // Entering tab view from Grid/Analysis: the tab model for the current
        // (default melody) layer has NOT been built yet this tick. Switching the
        // layer immediately makes Alpine serialize an unbuilt/empty melody staff
        // (sbn-tab-layer-changed → _requestTabXml), which then snapshots an empty
        // melody and can drop real notation on the next save (the Acapulco loss).
        // Let the tab view mount and build the melody model first, THEN switch.
        setViewMode('tab');
        nextTick(() => setTabLayer(layer));
        return;
    }
    setTabLayer(layer);
}

function toggleVideoSidebar() {
    videoSidebarOpen.value = !videoSidebarOpen.value;
    document.dispatchEvent(new CustomEvent('sbn-video-sidebar-toggle', {
        detail: { open: videoSidebarOpen.value }
    }));
    // Auto-switch audio source: video tab → video audio, else → synth
    videoSync.setAudioSource(videoSidebarOpen.value ? 'video' : 'synth');
}


// ── Alpine Bridge ──────────────────────────────────────────

const bridge = useAlpineBridge();
const {
    melody, sections, chordVoicings, timeSignature, tempo: bridgeTempo, songKey,
    title, composer,
    tabXml, repeatMarkers, voltaEndings,
    videoSync: bridgeVideoSync,
    openVideoSidebar: bridgeOpenVideoSidebar,
    tuning,
    hasMelodyTab, hasChordTab,
    initialized, setSaveHandler,
    setStructureHandler,
} = bridge;

// ── Working Model ──────────────────────────────────────────

const tabModel = useTabModel(
    melody, sections, timeSignature, repeatMarkers, voltaEndings, chordVoicings, undefined, songKey
);
const {
    model, hasData, buildModel, serializeModel, deserializeModel,
    insertMeasureAfter, insertMeasureBefore, deleteMeasure, deleteMeasuresByGlobalIndices,
    exportAlpineSections, cloneChordVoicings, applyChordVoicingOps, transposeSheet,
} = tabModel;

// Placeholder for videoSync — populated after useUndo + useVideoSync below.
// Using a plain object ref so the facade getter can safely call it before videoSync is assigned.
const _videoSyncHolder = { instance: null };

// ── __sbnTabModel facade (Phase B Step 7) ─────────────────────────────────
// Exposes live Vue model data to Alpine via window.__sbnTabModel.
// Getter functions read directly from the reactive model — no snapshot needed.
// Alpine consumes this in sbn-tab-sections-sync (Step 7), save() (Step 8),
// and loadAnalysis() (Step 9).
initTabModelFacade({
    getSections:      () => exportAlpineSections(),
    getChordVoicings: () => cloneChordVoicings(model.value?.chordVoicings ?? {}),
    getRepeatMarkers: () => _buildRepeatMarkersFromModel(),
    getVoltaEndings:  () => _buildVoltaEndingsFromModel(),
    // Phase D: expose videoSync for Alpine save pipeline (populated after undo stack init)
    getVideoSync:     () => _videoSyncHolder.instance?.getVideoSync() ?? null,
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

// Forwarding ref for videoSync.videoPlaying — populated after videoSync is created.
// Allows transportPlaying (computed below) to include video state without a forward ref issue.
const _videoPlayingRef = ref(false);

// Reference to videoSync for transport computed (set after videoSync is created)
let _videoSyncRef = null;

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
    const tabEvents = tabModelToEvents(model.value, { startBeat: 0, tuning: tuning.value });
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

const beatsPerMeasure = computed(() => (model.value?.ticksPerMeasure ?? 1920) / 480);

/**
 * Per-measure beat offset table — accounts for pickup bars whose beat count
 * differs from the global time signature.
 *
 * Each entry: { gi, beatStart, beatEnd, beats }
 * Ordered by global measure index. Used by beatToMeasureEvent() and the
 * transportBeat watcher to map play-position beats → measure gi.
 */
const measureBeatTable = computed(() => {
    if (!model.value?.sections) return [];
    const globalBpm = beatsPerMeasure.value;
    const table = [];
    let cursor = 0;
    const allM = allMeasures.value.slice().sort((a, b) => a.index - b.index);
    for (const m of allM) {
        const beats = m.pickupBeats ?? globalBpm;
        table.push({ gi: m.index, beatStart: cursor, beatEnd: cursor + beats, beats });
        cursor += beats;
    }
    return table;
});

const totalBeats = computed(() => {
    const t = measureBeatTable.value;
    return t.length ? t[t.length - 1].beatEnd : 0;
});

// ── Transport clock ─────────────────────────────────────
// When video is master, YouTube drives the clock. Otherwise, synth engine drives it.
const transportPlaying = computed(() => {
    if (_videoSyncRef?.isVideoMaster.value) return _videoSyncRef.videoPlaying.value;
    return isPlaying.value || isChordPlaying.value;
});

const transportBeat = computed(() => {
    if (_videoSyncRef?.isVideoMaster.value) {
        return _videoSyncRef.videoBeat.value ?? 0;
    }
    if (isPlaying.value)      return currentBeat.value;
    if (isChordPlaying.value) return chordCurrentBeat.value;
    // Neither playing — show parked position (prefer tab beat; both share the same engine clock)
    return currentBeat.value ?? chordCurrentBeat.value ?? 0;
});

async function onTransportToggle() {
    // When video audio is active, YouTube is the clock: play/pause video, synth stays silent.
    if (videoSync.isVideoMaster.value) {
        if (videoSync.videoPlaying.value) {
            videoSync.playerRef.value?.pause();
        } else {
            // Resume from last known video timestamp (preserved on pause).
            const t = videoSync.videoTime.value;
            if (t > 0) videoSync.playerRef.value?.seekTo(t);
            videoSync.playerRef.value?.play();
        }
        return;
    }

    if (transportPlaying.value) {
        // Pause — keep position for resume.
        if (isPlaying.value)      pauseTab();
        if (isChordPlaying.value) pauseChord();
    } else {
        // Resume or start from current position.
        if (!_eventsLoaded) {
            loadAllEvents();
        }
        if (viewMode.value === 'tab') await playTab();
        else await playChord();
    }
}

/**
 * Stop: first press parks at current position (pause + keep beat).
 * Second press while already stopped resets to beat 0.
 * Escape always resets to 0.
 */
function onTransportReset({ toZero = false } = {}) {
    if (videoSync.isVideoMaster.value) {
        const wasVideoPlaying = videoSync.videoPlaying.value;
        videoSync.playerRef.value?.pause();
        videoSync.onVideoPlayStateChange(false);
        if (toZero || !wasVideoPlaying) {
            videoSync.playerRef.value?.seekTo(0);
            videoSync.videoTime.value = 0;
            videoSync.videoMeasureIndex.value = null;
        }
        return;
    }

    const wasPlaying = transportPlaying.value;
    if (wasPlaying) {
        // First press: pause and park at current beat
        if (isPlaying.value)      pauseTab();
        if (isChordPlaying.value) pauseChord();
        if (toZero) {
            seekTab(0);
            seekChord(0);
            _eventsLoaded = false;
        }
    } else {
        // Already stopped: reset to beat 0
        seekTab(0);
        seekChord(0);
        _eventsLoaded = false;
    }
}

function onTransportSeek(beat) {
    seekTab(beat);
    seekChord(beat);
}

/**
 * Seek to a specific **play position** in the expanded sequence. Use this
 * when you have an exact pass in mind (e.g. clicking a pass-2 mapping row).
 * Skips the video seek — caller is expected to handle that separately if
 * needed, because we don't always know which video timestamp goes with this
 * position.
 */
function seekToPosition(playPos) {
    if (!model.value || playPos == null) return;
    const entry = playPositionBeatTable.value[playPos];
    const beatStart = entry ? entry.beatStart : playPos * beatsPerMeasure.value;
    seekTab(beatStart);
    seekChord(beatStart);
}

/**
 * Seek to the start of a global measure index.
 * If already playing, jumps immediately. If stopped, updates the resume position
 * without starting playback — so the next Play/Resume starts from there.
 */
async function seekToMeasure(gi) {
    if (!model.value) return;
    // The engine clock counts play positions, not gi — a repeated bar plays at
    // several positions. Seek to its FIRST occurrence (start of its phrase).
    const playPos = firstPositionForGi(_expandedChordSequence.value, gi);
    const entry = playPositionBeatTable.value[playPos];
    const beatStart = entry ? entry.beatStart : playPos * beatsPerMeasure.value;

    if (videoSync.isVideoMaster.value) {
        // Video is master: seek video once (no continuous seeks during playback)
        const t = videoSync.measureToVideoTime(gi);
        if (t !== null) videoSync.playerRef.value?.seekTo(t);
        // Keep synth position synced for mode-switch continuity
        seekTab(beatStart);
        seekChord(beatStart);
        return;
    }

    // Always seek both — they share the engine clock, keeping refs in sync
    seekTab(beatStart);
    seekChord(beatStart);
}

/**
 * Convert a beat position to { measureIndex, eventIndex } in the tab model.
 * Used to drive the tab cursor during playback.
 */
function beatToMeasureEvent(beat) {
    if (!model.value) return null;
    const ppq = model.value.ticksPerMeasure ?? 1920;

    // Find the measure that contains this beat using the offset table
    const table = measureBeatTable.value;
    const entry = table.find(e => beat >= e.beatStart && beat < e.beatEnd)
                  ?? table[table.length - 1];
    if (!entry) return null;

    const mi = entry.gi;
    const beatInMeasure = beat - entry.beatStart;
    // Map beat-within-measure to ticks using the measure's own beat count
    const ticksPerBeat = ppq / (entry.beats || beatsPerMeasure.value);
    const tickInMeasure = beatInMeasure * ticksPerBeat;

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
    bridgeTempo.value = bpm;
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
// Unified playback cursor: audio engine beat takes priority; YouTube-driven index
// fills in when audio is stopped. Declared as ref so useVideoSync can feed into it.
// Value is updated by a watcher set up after videoSync is created below.
const playingMeasureIndex = ref(0);

provide('tabActiveSourceId',    activeSourceId);      // note-level SVG highlight (tab only)
provide('chordActiveSourceId',  chordActiveSourceId); // kept for any future per-chord use
provide('playingMeasureIndex',  playingMeasureIndex); // unified cursor highlight (both views)
provide('transportBeat',        transportBeat);       // raw beat for sub-measure chord highlighting
provide('beatsPerMeasureRef',   beatsPerMeasure);     // chord cards need this to compute windows
// Map of gi → beatStart for the current play pass — lets TabMeasure/ChordMeasure
// compute true beat-within-measure without assuming all measures are the same length.
// For repeated bars, uses the pass currently being played (the entry whose range
// contains transportBeat), falling back to the first occurrence.
provide('measureBeatStartMap', computed(() => {
    const beat  = transportBeat.value ?? 0;
    const table = playPositionBeatTable.value;
    const map   = new Map();
    // First pass: populate with first occurrence of each gi
    for (const e of table) {
        if (!map.has(e.gi)) map.set(e.gi, e.beatStart);
    }
    // Second pass: if the current beat falls in a later occurrence, override
    const active = table.find(e => beat >= e.beatStart && beat < e.beatEnd);
    if (active) map.set(active.gi, active.beatStart);
    return map;
}));
provide('seekToMeasure',        seekToMeasure);       // chord-card click → seek + play
provide('seekToPosition',       seekToPosition);      // sync-row click → exact-pass seek
provide('transportPlaying',     transportPlaying);    // playback active flag for cursor visibility
// D2: tap-to-mark cursor.
//   - `tapCursor`    → measures use this for the "tap target" highlight; it's
//                      mapped to a gi via the expanded sequence so a repeated
//                      bar lights up on whichever pass the cursor is on.
//   - `tapCursorPos` → the raw play-position cursor (for the sync editor).
provide('tapCursor', computed(() => {
    if (!videoSync.sidebarOpen.value) return -1;
    return videoSync.tapCursorGi.value;
}));
provide('tapCursorPos', computed(() => videoSync.tapCursor.value));

// "Set downbeat" mode: when armed by VideoSyncEditor, the next tab-note click
// is interpreted as "this note is beat 1" rather than a normal edit/select.
const downbeatPickMode = ref(false);
provide('downbeatPickMode', downbeatPickMode);

// Video sync map — provided after videoSync is created below (patched in post-init block)


// ── Undo / Redo (Phase 7e) ─────────────────────────────────

const { canUndo, canRedo, wrapCommand, undo, redo, reset: resetUndo } = useUndo(model);

// Expanded play sequence: play position → gi (repeat + volta aware). The audio
// engine clock counts play positions; the score / video sync use gi. This is
// the single source of truth for converting between the two.
const _expandedChordSequence = computed(() => {
    if (!model.value) return [];
    return expandModelSequence(model.value);
});

// ── Video Sync (Phase D) ──────────────────────────────────────
const videoSync = useVideoSync(model, {
    wrapCommand,
    playingMeasureIndex,
    transportPlaying,
    beatsPerMeasure,
    getSequence: () => _expandedChordSequence.value,
});
_videoSyncHolder.instance = videoSync;
_videoSyncRef = videoSync; // For transportPlaying/transportBeat computed

// Map of gi → array of { videoTime, pass, pos, mappingIdx } — consumed by
// measure badges. A repeated bar carries multiple entries (one per pass), so
// badges can render a stacked indicator and a per-pass popover.
// Only populated when the video sidebar is open.
provide('videoSyncMap', computed(() => {
    if (!videoSidebarOpen.value) return null;
    return videoSync.mappingsByGi.value;
}));

// How many times the gi at the current tap cursor has appeared in the
// expanded sequence up to and including that position (1-based). Used by
// VideoSyncEditor to render "pass N" on the Mark button.
const tapCursorPass = computed(() => {
    const seq = _expandedChordSequence.value;
    const pos = videoSync.tapCursor.value;
    if (!seq.length || pos < 0) return 1;
    const gi = seq[Math.min(pos, seq.length - 1)];
    let count = 0;
    for (let i = 0; i <= Math.min(pos, seq.length - 1); i++) {
        if (seq[i] === gi) count++;
    }
    return Math.max(1, count);
});

function removeMappingByIdentity({ measureIndex, videoTime }) {
    const idx = videoSync.mappings.value.findIndex(
        m => m.measureIndex === measureIndex && m.videoTime === videoTime
    );
    if (idx >= 0) videoSync.removeMappingAt(idx);
}

/**
 * Seek the synth transport to the exact play position of a specific mapping
 * (so clicking a "pass 2" row lands on pass 2, not pass 1).
 * The video player is seeked by the editor itself; here we only handle the
 * audio side.
 */
function seekToMapping({ measureIndex, videoTime }) {
    // Find this mark's pos via mappingsByGi (already groups marks by gi and
    // tags each with its play position).
    const marks = videoSync.mappingsByGi.value.get(measureIndex);
    const mark  = marks?.find(m => m.videoTime === videoTime);
    if (mark) {
        seekToPosition(mark.pos);
    } else {
        // Fallback — shouldn't happen, but if the mapping vanished, just seek
        // by gi to the first pass.
        seekToMeasure(measureIndex);
    }
}

// Nudge a mapping time by delta seconds (drag handler in measure badges → undoable).
provide('nudgeSyncMapping', (measureIndex, delta) => {
    const m = videoSync.mappings.value.find(m => m.measureIndex === measureIndex);
    if (!m) return;
    videoSync.addMapping(measureIndex, Math.max(0, m.videoTime + delta));
});

// Wire videoPlaying into the forwarding ref so transportPlaying sees it
watch(videoSync.videoPlaying, (v) => { _videoPlayingRef.value = v; }, { immediate: true });

// Populate videoSync from bridge data on first load
watch(bridgeVideoSync, (data) => {
    if (data) videoSync.setVideoSync(data);
}, { immediate: true });
 
// Phase D: Auto-open video sidebar if requested via bridge (flashed session)
watch(bridgeOpenVideoSidebar, (val) => {
    if (val) {
        videoSidebarOpen.value = true;
        videoSync.setAudioSource('video');
    }
}, { immediate: true });

/**
 * Play-position beat offset table — maps each slot in the expanded sequence
 * to its beat range, accounting for pickup bars whose beat count differs from
 * the global time signature.
 *
 * Each entry: { pos, gi, beatStart, beatEnd }
 */
const playPositionBeatTable = computed(() => {
    const seq     = _expandedChordSequence.value;
    const globalBpm = beatsPerMeasure.value;
    const table   = [];
    let cursor    = 0;
    for (let pos = 0; pos < seq.length; pos++) {
        const gi    = seq[pos];
        const m     = allMeasures.value.find(m => m.index === gi);
        const beats = m?.pickupBeats ?? globalBpm;
        table.push({ pos, gi, beatStart: cursor, beatEnd: cursor + beats });
        cursor += beats;
    }
    return table;
});

// Keep playingMeasureIndex in sync with transportBeat.
// transportBeat is a **play-position** beat (engine clock when synth is master,
// videoBeat when video is master — both play-position based). We map the play
// position back to a gi via the expanded sequence so repeat + volta bars
// highlight the correct bar on each pass.
watch(
    [transportBeat, playPositionBeatTable],
    ([beat]) => {
        const b = beat ?? 0;
        const table = playPositionBeatTable.value;
        const entry = table.find(e => b >= e.beatStart && b < e.beatEnd)
                      ?? table[table.length - 1];
        const pos = entry?.pos ?? Math.floor(b / (beatsPerMeasure.value || 4));
        playingMeasureIndex.value = giAtPosition(_expandedChordSequence.value, pos);
    },
    { immediate: true }
);


// ── Mode switch handling ─────────────────────────────────
// When switching audio sources, pause the outgoing source and seed position.
watch(
    () => videoSync.audioSource.value,
    (newSource, oldSource) => {
        if (newSource === oldSource) return;

        if (newSource === 'video') {
            // Synth → Video: pause synth, keep position for video
            if (isPlaying.value) pauseTab();
            if (isChordPlaying.value) pauseChord();
        } else {
            // Video → Synth: pause video, seed synth position
            if (videoSync.videoPlaying.value) {
                videoSync.playerRef.value?.pause();
            }
            // Seed synth to the video's current play position (preserves which
            // pass of a repeat we're on). Fall back to the highlighted bar's
            // first play position when the video never started.
            const pos = videoSync.videoPlayPosition.value
                ?? firstPositionForGi(_expandedChordSequence.value, playingMeasureIndex.value ?? 0);
            const posEntry = playPositionBeatTable.value[pos];
            const beatStart = posEntry ? posEntry.beatStart : pos * beatsPerMeasure.value;
            seekTab(beatStart);
            seekChord(beatStart);
        }
    }
);

// Reset undo stack when a new leadsheet loads
watch(model, (newVal, oldVal) => {
    if (newVal && !oldVal) resetUndo();
});

// ── Chord Grid composables (Phase B Step 4) ────────────────

const chordGridOps      = useChordGridOps(model, { wrapCommand }, tabModel);
registerSetChordName((gi, ci, name) => chordGridOps.setChordName(gi, ci, name));
registerSetTempo((bpm) => onTransportTempo(bpm));
registerSetTimeSignature((timeSig) => {
    const oldSig = timeSignature.value || '4/4';
    if (timeSig === oldSig) return;

    // Rescale chord offsets/beats from the old time sig to the new one.
    // chordOffsets and chordBeats are stored in quarter-beat units relative to
    // the old bar length — if the bar changes size they must be rescaled so cards
    // don't overflow or bunch up.
    const [oldBeatsStr, oldBeatTypeStr] = oldSig.split('/');
    const [newBeatsStr, newBeatTypeStr] = timeSig.split('/');
    const oldBpm = (parseInt(oldBeatsStr) || 4) * (4 / (parseInt(oldBeatTypeStr) || 4));
    const newBpm = (parseInt(newBeatsStr) || 4) * (4 / (parseInt(newBeatTypeStr) || 4));
    const scale  = newBpm / oldBpm;

    if (scale !== 1 && sections.value?.length) {
        sections.value = sections.value.map(sec => ({
            ...sec,
            measures: (sec.measures || []).map(m => {
                const chords = m.chords || [];
                if (!chords.length || !chords.some(c => typeof c === 'object' && c.beatInMeasure != null)) {
                    return m;
                }
                return {
                    ...m,
                    chords: chords.map(c => {
                        if (typeof c !== 'object' || c.beatInMeasure == null) return c;
                        return {
                            ...c,
                            beatInMeasure: c.beatInMeasure * scale,
                            beats:         (c.beats ?? (newBpm / chords.length)) * scale,
                        };
                    }),
                };
            }),
        }));
    }

    timeSignature.value = timeSig;
});
registerSetChordNameWithVoicing((gi, ci, name, tabData) => {
    chordGridOps.setChordName(gi, ci, name);
    if (tabData && model.value?.chordVoicings) {
        model.value.chordVoicings[`${name}@${gi}.${ci}`] = {
            frets:    tabData.frets,
            position: tabData.position,
            fingers:  tabData.fingers ?? '000000',
        };
    }
});
const gridSelection     = useGridSelection(model);
const chordClipboard    = useChordClipboard(model, { wrapCommand });
const chordPickerStore  = useChordPickerStore();
// onVoicingApplied is a function declaration (hoisted) — safe to reference here
const voicingPickerStore = useVoicingPickerStore(model, { wrapCommand }, { applyTabFrets: onVoicingApplied });

// Provide to the entire ChordGridView / VoicingPicker subtree
provide('chordGridOps',      chordGridOps);
provide('setChordName',      (gi, ci, name) => chordGridOps.setChordName(gi, ci, name));

const inlineRenameTarget = ref(null);  // { gi, ci } — set to trigger inline edit on matching card
provide('inlineRenameTarget',  inlineRenameTarget);
provide('triggerInlineRename', (gi, ci) => { inlineRenameTarget.value = { gi, ci, ts: Date.now(), source: 'tab' }; });
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
provide('setBarsPerRow',        (si, n)    => tabModel.setBarsPerRow(si, n));

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
    copyMeasures,
    prepareCut,
    preparePaste,
    preparePasteMeasures,
    makeWholeRest,
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

/**
 * Rebuild repeatMarkers map from live measure flags.
 * Shape: { "gi": { start: bool, end: bool }, ... }
 */
function _buildRepeatMarkersFromModel() {
    if (!model.value) return null;
    const out = {};
    let gi = 0;
    for (const sec of model.value.sections) {
        for (const m of sec.measures) {
            if (m.repeatStart || m.repeatEnd) {
                out[gi] = { start: !!m.repeatStart, end: !!m.repeatEnd };
            }
            gi++;
        }
    }
    return Object.keys(out).length ? out : null;
}

/**
 * Rebuild voltaEndings map from live measure flags.
 * Shape: { "gi": { type: "start"|"stop", number, text? }, ... }
 *
 * Rules:
 *  - voltaStart → emit { type: "start", number, text }
 *  - voltaEnd but NOT voltaStart → emit { type: "stop", number }
 *  - Single-bar bracket (voltaStart AND voltaEnd on same measure): only emit
 *    the "start" entry. The populate pass in useTabModel auto-closes the last
 *    measure carrying a volta that was never explicitly stopped.
 */
function _buildVoltaEndingsFromModel() {
    if (!model.value) return null;
    const out = {};
    let gi = 0;
    for (const sec of model.value.sections) {
        for (const m of sec.measures) {
            if (m.voltaStart && m.volta) {
                out[String(gi)] = { type: 'start', number: m.volta.number, text: m.volta.text || `${m.volta.number}.` };
            }
            if (m.voltaEnd && m.volta && !m.voltaStart) {
                out[String(gi)] = { type: 'stop', number: m.volta.number };
            }
            // Single-bar bracket (voltaStart + voltaEnd): emit an explicit stop under a '_stop' key
            // so useTabModel doesn't auto-close at a later bar.
            if (m.voltaStart && m.voltaEnd && m.volta) {
                out[String(gi) + '_stop'] = { type: 'stop', number: m.volta.number };
            }
            gi++;
        }
    }
    return Object.keys(out).length ? out : null;
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
    graceMode,
    handleKeydown: noteInputKeydown,
    shiftNoteToString,
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

function _onStructuralSync() {
    // Repeat/volta ops dispatch sbn-tab-sections-sync on window via _dispatchSync().
    // If the transport is stopped, mark events stale so next play() rebuilds them
    // from the updated expanded sequence. Never reload mid-playback — engine.load()
    // resets the scheduler's _nextIdx to 0 while WebAudio nodes are already in
    // flight, causing a double audio stream.
    if (!isChordPlaying.value && !isPlaying.value) {
        _eventsLoaded = false;
    }
}

onMounted(() => {
    window.addEventListener('sbn-tab-sections-sync', _onStructuralSync);
    window.addEventListener('sbn-transpose', openTransposeModal);
    document.addEventListener('sbn-sidebar-set-duration', onSidebarSetDuration);
    document.addEventListener('sbn-sidebar-toggle-tie', onSidebarToggleTie);
    document.addEventListener('sbn-sidebar-toggle-dotted', onSidebarToggleDotted);
    document.addEventListener('sbn-sidebar-undo', undo);
    document.addEventListener('sbn-sidebar-redo', redo);
    document.addEventListener('sbn-sidebar-copy',  handleCopy);
    document.addEventListener('sbn-sidebar-cut',   handleCut);
    document.addEventListener('sbn-sidebar-paste', handlePaste);
    document.addEventListener('sbn-tab-layer-revert', onTabLayerRevert);
    document.addEventListener('sbn-tab-set-layer', onExternalSetLayer);

    // Register the XML serializer so the bridge can respond to sbn-tab-save-request
    setSaveHandler(() => {
        if (!model.value) return null;
        return modelToMusicXml(model.value, {
            title:    title.value    || undefined,
            composer: composer.value || undefined,
            tuning:   tuning.value   || 'standard',
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
    window.removeEventListener('sbn-tab-sections-sync', _onStructuralSync);
    window.removeEventListener('sbn-transpose', openTransposeModal);
    disposeNoteInput();
    document.removeEventListener('sbn-sidebar-set-duration', onSidebarSetDuration);
    document.removeEventListener('sbn-sidebar-toggle-tie', onSidebarToggleTie);
    document.removeEventListener('sbn-sidebar-toggle-dotted', onSidebarToggleDotted);
    document.removeEventListener('sbn-sidebar-undo', undo);
    document.removeEventListener('sbn-sidebar-redo', redo);
    document.removeEventListener('sbn-sidebar-copy',  handleCopy);
    document.removeEventListener('sbn-sidebar-cut',   handleCut);
    document.removeEventListener('sbn-sidebar-paste', handlePaste);
    document.removeEventListener('sbn-tab-layer-revert', onTabLayerRevert);
    document.removeEventListener('sbn-tab-set-layer', onExternalSetLayer);
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
// Template ref to the video sidebar editor — used so a tab-note click in
// "set downbeat" mode can hand the chosen beat back to the re-shift tool.
const videoSyncEditorRef = ref(null);

// ── Keyboard shortcut overlay (Phase 7g) ───────────────────

const showShortcuts = ref(false);

// ── Transpose modal ─────────────────────────────────────────

const showTranspose      = ref(false);
const transposeSemitones = ref(0);
const transposeTargetKey = ref('');

// 12 keys in the enharmonic core's preferred spelling
// (flat side uses flats, genuine sharp keys use sharps; C is neutral/first)
const transposeKeyOptions = [
    { key: 'C',  label: 'C'  }, { key: 'Db', label: 'Db/C#' },
    { key: 'D',  label: 'D'  }, { key: 'Eb', label: 'Eb/D#' },
    { key: 'E',  label: 'E'  }, { key: 'F',  label: 'F'  },
    { key: 'F#', label: 'F#/Gb' }, { key: 'G', label: 'G'  },
    { key: 'Ab', label: 'Ab/G#' }, { key: 'A', label: 'A'  },
    { key: 'Bb', label: 'Bb/A#' }, { key: 'B', label: 'B'  },
];

const NOTE_SEMI = { C:0,'C#':1,Db:1,D:2,'D#':3,Eb:3,E:4,F:5,'F#':6,Gb:6,G:7,'G#':8,Ab:8,A:9,'A#':10,Bb:10,B:11 };

function onTransposeKeySelect() {
    if (!transposeTargetKey.value) { transposeSemitones.value = 0; return; }
    const fromKey = songKey?.value || 'C';
    const fromTonic = fromKey.replace(/m$/, '').split(' ')[0];
    const toTonic   = transposeTargetKey.value.replace(/m$/, '').split(' ')[0];
    const from = NOTE_SEMI[fromTonic] ?? 0;
    const to   = NOTE_SEMI[toTonic]   ?? 0;
    let diff = ((to - from) % 12 + 12) % 12;
    if (diff > 6) diff -= 12;  // prefer shortest interval (e.g. +5 over -7)
    transposeSemitones.value = diff;
}

function openTransposeModal() {
    transposeSemitones.value = 0;
    transposeTargetKey.value = '';
    showTranspose.value = true;
}

async function onConfirmTranspose() {
    if (transposeSemitones.value === 0) return;

    const leadsheetId   = window.__sbnLeadsheet?.id;
    const versionSlug   = window.__sbnLeadsheet?.versionSlug ?? null;
    const csrf          = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

    if (!leadsheetId) {
        // Fallback: client-side transpose (exercises, etc. with no server id)
        let overflowCount = 0;
        wrapCommand(
            `Transpose ${transposeSemitones.value > 0 ? '+' : ''}${transposeSemitones.value} semitones`,
            [],
            () => { overflowCount = transposeSheet(transposeSemitones.value); },
            structuralUndoOptions,
        );
        syncTabSectionsToAlpine();
        showTranspose.value = false;
        if (overflowCount > 0) {
            window.sbnToast?.(`${overflowCount} note(s) were clamped to fret range (0–24)`, 'warning');
        }
        return;
    }

    showTranspose.value = false;

    try {
        const body = { semitones: transposeSemitones.value };
        if (versionSlug) body.v = versionSlug;

        const resp = await fetch(`/admin/leadsheets/${leadsheetId}/transpose`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf,
                'Accept': 'application/json',
            },
            body: JSON.stringify(body),
        });

        const data = await resp.json();
        if (!resp.ok || !data.ok) {
            window.sbnToast?.('Transpose failed: ' + (data.message ?? 'server error'), 'error');
            return;
        }

        // Stash a one-shot undo marker so the page can offer an Undo toast after reload.
        // The inverse POST will NOT re-stash this marker (one level of undo only).
        sessionStorage.setItem('sbn_transpose_undo', JSON.stringify({
            leadsheetId,
            versionSlug,
            semitones: transposeSemitones.value,
            newKey: data.song_key,
            ts: Date.now(),
        }));

        // Reload page so both layers and the chord grid reflect the server-stored transposed data.
        // A full reload is the safe path here — the backend is now the source of truth.
        window.location.reload();
    } catch (err) {
        const msg = err instanceof Error ? err.message : String(err);
        window.sbnToast?.('Transpose request failed: ' + msg, 'error');
    }
}

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
    // Let native inputs handle their own keys
    const tag = e.target?.tagName;
    if (tag === 'INPUT' || tag === 'TEXTAREA' || e.target?.isContentEditable) return;

    // Audio playback (Phase 7C) - Space always triggers play/pause
    if (e.key === ' ' && !e.ctrlKey && !e.metaKey && !e.altKey) {
        const canPlay = (videoSidebarOpen.value && videoSync.hasVideo.value) ||
                        (viewMode.value === 'tab' && hasData.value) ||
                        (viewMode.value === 'chords' && hasChordsData.value);
        if (canPlay) {
            e.preventDefault();
            onTransportToggle();
            return;
        }
    }

    // M key: create video sync point at the currently-playing measure.
    // ONLY active when the video sidebar is CLOSED — when it's open, the
    // VideoSyncEditor handles M itself (using the tap cursor, which is the
    // correct AABA-aware behavior). Without this guard both handlers fire and
    // every press creates two duplicate mappings.
    if ((e.key === 'm' || e.key === 'M') && !e.ctrlKey && !e.metaKey && !e.altKey) {
        if (videoSync.hasVideo.value && !videoSidebarOpen.value) {
            e.preventDefault();
            const mi = playingMeasureIndex.value ?? cursor.value.measureIndex ?? 0;
            videoSync.addMapping(mi, videoSync.videoTime.value);
            return;
        }
    }

    if (e.key === 'Escape' && (transportPlaying.value || transportBeat.value > 0)) {
        e.preventDefault();
        onTransportReset({ toZero: true });
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

    // Tab: cycle tabs (Grid → Chords → Melody → Grid, skipping absent layers)
    if (e.key === 'Tab' && !e.altKey && !e.ctrlKey && !e.metaKey) {
        e.preventDefault();
        const tabs = [
            { mode: 'chords',   layer: null    },
            ...(hasChordTab.value   ? [{ mode: 'tab', layer: 'chord'   }] : []),
            ...(hasMelodyTab.value  ? [{ mode: 'tab', layer: 'melody'  }] : []),
        ];
        const current = tabs.findIndex(t =>
            t.mode !== 'tab'
                ? viewMode.value === t.mode
                : viewMode.value === 'tab' && tabLayer.value === t.layer
        );
        const next = tabs[(current + 1) % tabs.length];
        if (next.mode === 'tab') selectTabLayerView(next.layer);
        else setViewMode(next.mode);
        return;
    }

    // Shift+T: open transpose modal
    if (e.shiftKey && (e.key === 't' || e.key === 'T') && !e.ctrlKey && !e.metaKey && !e.altKey) {
        e.preventDefault();
        openTransposeModal();
        return;
    }

    // V: toggle video sidebar
    if ((e.key === 'v' || e.key === 'V') && !e.ctrlKey && !e.metaKey && !e.altKey) {
        e.preventDefault();
        toggleVideoSidebar();
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

    // ── Shift+↑/↓: select all events at the current beat (same tickInMeasure) ──
    if (e.shiftKey && cursor.value.mode === 'navigate'
        && (e.key === 'ArrowUp' || e.key === 'ArrowDown')) {
        e.preventDefault();
        const mi = cursor.value.measureIndex;
        const ei = cursor.value.eventIndex;
        const m  = allMeasures.value[mi];
        if (!m) return;
        const v1 = m.events.filter(ev => (ev.voice ?? 1) === 1).sort((a, b) => a.tick - b.tick);
        const curEv = v1[ei];
        if (!curEv) return;
        const beatTick = curEv.tickInMeasure ?? 0;
        const beatIds = v1
            .filter(ev => (ev.tickInMeasure ?? 0) === beatTick)
            .map(ev => ev.id);
        setSelectedEvents(beatIds);
        _noteSelAnchorIdx.value = null;
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

    // Ctrl+Up / Ctrl+Down: shift note(s) to adjacent string (transposes fret accordingly).
    // Up → lower str# (higher pitch, toward high e); Down → higher str# (toward low E).
    // Works on single cursor note OR the entire multi-note / multi-bar selection.
    if (e.ctrlKey && !e.shiftKey && !e.altKey && !e.metaKey
        && (e.key === 'ArrowUp' || e.key === 'ArrowDown')) {
        e.preventDefault();
        const direction = e.key === 'ArrowUp' ? -1 : 1;

        if (hasNoteSelection.value && selectedEvents.value.size > 0) {
            // ── Multi-note selection: shift every selected note on each event ──
            // intervalBetween mirrors the helper in useNoteInput (not exported).
            const intervalBetween = (a, b) => {
                const lo = Math.min(a, b), hi = Math.max(a, b);
                return (lo === 2 && hi === 3) ? 4 : 5; // B↔G = maj3, all others = P4
            };
            const MIN_FRET = 0, MAX_FRET = 24;

            // Collect affected measure indices for undo snapshot.
            const affectedIndices = getSelectedMeasureIndices();
            if (affectedIndices.length === 0) return;

            wrapCommand('shift-string-selection', affectedIndices, () => {
                const frozenIds = selectedEvents.value;
                for (const measure of allMeasures.value) {
                    for (const ev of measure.events) {
                        if (!frozenIds.has(ev.id) || ev.isRest) continue;
                        // Shift every note in this event that has a valid target string.
                        // We process in order so notes moving toward each other don't collide.
                        const notes = ev.notes.slice().sort((a, b) =>
                            direction === -1 ? a.string - b.string : b.string - a.string
                        );
                        for (const n of notes) {
                            const targetStr = n.string + direction;
                            if (targetStr < 1 || targetStr > 6) continue;
                            const newFret = n.fret + direction * intervalBetween(n.string, targetStr);
                            if (newFret < MIN_FRET || newFret > MAX_FRET) continue;
                            // Remove any existing note on the target string.
                            const colIdx = ev.notes.findIndex(x => x.string === targetStr);
                            if (colIdx !== -1) ev.notes.splice(colIdx, 1);
                            const srcIdx = ev.notes.findIndex(x => x.string === n.string);
                            if (srcIdx === -1) continue;
                            ev.notes[srcIdx].string = targetStr;
                            ev.notes[srcIdx].fret   = newFret;
                        }
                        ev.notes.sort((a, b) => a.string - b.string);
                    }
                }
            });
        } else {
            // ── Single cursor note ──
            const targetStr = shiftNoteToString(direction);
            if (targetStr) moveTo(cursor.value.measureIndex, cursor.value.eventIndex, targetStr);
        }
        return;
    }

    // 1. Cursor navigation — plain move always clears note selection
    const cursorConsumed = cursorKeydown(e);
    if (cursorConsumed) {
        clearNoteSelection();
        _noteSelAnchorIdx.value = null;
        return;
    }

    // 2. Duration / tie shortcuts (Phase 7d)
    // T key with a multi-note selection: tie all selected events.
    if ((e.key === 't' || e.key === 'T') && !e.altKey && !e.ctrlKey && !e.metaKey
        && cursor.value.mode === 'navigate'
        && hasNoteSelection.value && selectedEvents.value.size > 0) {
        e.preventDefault();
        const affectedIndices = getSelectedMeasureIndices();
        if (affectedIndices.length > 0) {
            wrapCommand('tie-selection', affectedIndices, () => {
                const frozenIds = selectedEvents.value;
                for (const measure of allMeasures.value) {
                    for (const ev of measure.events) {
                        if (!frozenIds.has(ev.id) || ev.isRest) continue;
                        for (const note of ev.notes) {
                            toggleTie(ev, note.string);
                        }
                    }
                }
            });
        }
        return;
    }
    if (cmdHandleDurationKey(e, currentEvent.value, cursor.value.mode, cursor.value.stringIndex)) return;

    // 3. "A" key: insert new event at end of measure (Phase 7d)
    if ((e.key === 'a' || e.key === 'A') && !e.ctrlKey && !e.metaKey && !e.altKey) {
        if (cursor.value.mode === 'navigate' && handleInsertEvent(e)) return;
    }

    // 3b. Delete removed — handleDeleteSelected now cleans up all events functionally.

    // 4. Delete selected events / bars
    if ((e.key === 'Delete' || e.key === 'Backspace') && hasNoteSelection.value) {
        e.preventDefault();
        const indices = getSelectedMeasureIndices();
        if (indices.length > 1) {
            // Multi-bar keyboard delete → structural delete (same as context menu)
            const voicingDeletes = captureVoicingDeletesByGlobalIndices(indices);
            wrapCommand('Delete bars (tab)', [], () => {
                applyChordVoicingOps({ voicingDeletes });
                deleteMeasuresByGlobalIndices(indices);
                syncTabSectionsToAlpine();
            }, structuralUndoOptions);
            clearNoteSelection();
        } else {
            handleDeleteSelected();
        }
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

    // Select associated tab notes & synchronize cursor
    const total      = (measure.chordNames || []).length || 1;
    const slotTicks  = tpm / total;
    const startTick  = chordIndex * slotTicks;
    const endTick    = (chordIndex + 1) * slotTicks;

    const slotEvents = (measure.events || []).filter(ev =>
        ev.tickInMeasure >= startTick - 5 &&
        ev.tickInMeasure < endTick - 5
    ).sort((a, b) => (a.tickInMeasure || 0) - (b.tickInMeasure || 0));

    if (slotEvents.length > 0) {
        const eventIds = slotEvents.map(ev => ev.id);
        selectedEvents.value = new Set(eventIds);

        const v1 = measure.events.filter(ev => (ev.voice || 1) === 1).sort((a, b) => a.tick - b.tick);
        const eventIdx = v1.findIndex(ev => ev.id === slotEvents[0].id);
        if (eventIdx !== -1) {
            moveTo(measureIndex, eventIdx, cursor.value.stringIndex || 1);
        }
    }
}

/**
 * Called by the bridge when Alpine dispatches sbn-tab-voicing-applied.
 * Applies the selected voicing frets into the tab model.
 */
function onVoicingApplied({ globalMeasureIndex, chordIndex, chordName, frets, position, skipIfTabExists = false }) {
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

    if (skipIfTabExists && (measure.events || []).some(ev => !ev.isRest)) {
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
        _showIdentifyConfirm(chordName, identifiedName, measureIndex, chordIndex, tabData);

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

function _showIdentifyConfirm(oldName, newName, measureIndex, chordIndex, tabData = null) {
    document.dispatchEvent(new CustomEvent('sbn-tab-identify-result', {
        detail: { oldName, newName, measureIndex, chordIndex, tabData },
    }));
}

// ── Copy / Cut / Paste handlers (Phase 7g) ─────────────────
// If a note-level selection (Shift+←/→) is active, copy/cut only
// those events. Otherwise copy/cut the whole measure.

function handleCopy() {
    if (viewMode.value === 'chords') {
        const sel = gridSelection.selection.value;
        const selGis = [...new Set(sel.map(s => s.gi))];
        if (selGis.length > 1) {
            chordClipboard.copySelection(sel);
        } else if (selGis.length === 1) {
            chordClipboard.copyMeasure(selGis[0]);
        }
        return;
    }
    // Tab view: multi-bar selection → measure-level copy
    const indices = getSelectedMeasureIndices();
    if (indices.length > 1) {
        const measureObjects = indices.map(gi => allMeasures.value.find(m => m.index === gi)).filter(Boolean);
        copyMeasures(measureObjects);
        return;
    }
    selectionCopy(currentEvent.value, cursor.value.stringIndex, selectedEvents.value);
}

function handleCut() {
    if (viewMode.value === 'chords') {
        const sel = gridSelection.selection.value;
        const selGis = [...new Set(sel.map(s => s.gi))];
        if (selGis.length > 1) {
            chordClipboard.cutSelection(sel, (s) => chordGridOps.deleteChords(s));
        } else if (selGis.length === 1) {
            chordClipboard.cutMeasure(selGis[0]);
        }
        gridSelection.clearSelection();
        return;
    }
    // Tab view: multi-bar selection → measure-level cut (copy then clear)
    const indices = getSelectedMeasureIndices();
    if (indices.length > 1) {
        const measureObjects = indices.map(gi => allMeasures.value.find(m => m.index === gi)).filter(Boolean);
        copyMeasures(measureObjects);
        const tpm = model.value.ticksPerMeasure;
        wrapCommand('cut bars (tab)', indices, () => {
            indices.forEach(gi => {
                const m = allMeasures.value.find(m => m.index === gi);
                if (!m) return;
                m.events = [makeWholeRest(gi, tpm)];
                m.actualTicks = tpm;
                m.chordNames.splice(0, m.chordNames.length);
            });
        });
        clearNoteSelection();
        return;
    }
    const op = prepareCut(currentEvent.value, cursor.value.stringIndex, selectedEvents.value);
    if (!op) return;
    wrapCommand('cut', op.affectedIndices, op.mutate);
    clearNoteSelection();
    _noteSelAnchorIdx.value = null;
}

function handlePaste() {
    if (viewMode.value === 'chords') {
        const sel = gridSelection.selection.value;
        const targetGi = sel.length > 0
            ? Math.min(...sel.map(s => s.gi))
            : cursor.value.measureIndex;
        if (targetGi != null) chordClipboard.pasteMeasure(targetGi);
        return;
    }
    // Tab view: measure-level paste when clipboard has measures mode
    if (clipboard.value?.mode === 'measures') {
        const targetGi = cursor.value.measureIndex;
        const op = preparePasteMeasures(allMeasures.value, targetGi);
        if (!op) return;
        wrapCommand('paste bars (tab)', op.affectedIndices, op.mutate);
        clearNoteSelection();
        return;
    }
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
            
            // If measure is now empty, insert a rest matching the measure's capacity.
            // Pickup bars get a rest sized to their pickupBeats, not the full bar.
            const v1 = m.events.filter(e => (e.voice || 1) === 1);
            if (v1.length === 0) {
                const tpm = model.value.ticksPerMeasure;
                const capacityTicks = m.pickupBeats != null
                    ? Math.round(m.pickupBeats * 480)
                    : tpm;
                // Choose the nearest standard duration for the fallback rest
                const STD = [
                    { dur: 'w', ticks: 1920 }, { dur: 'h', ticks: 960 },
                    { dur: 'q', ticks: 480 },  { dur: 'e', ticks: 240 },
                    { dur: 's', ticks: 120 },
                ];
                const best = STD.reduce((a, b) =>
                    Math.abs(b.ticks - capacityTicks) < Math.abs(a.ticks - capacityTicks) ? b : a
                );
                m.events.push({
                    id: generateId(),
                    tick: m.index * tpm, tickInMeasure: 0,
                    measureIdx: m.index, duration: best.dur, ticks: best.ticks,
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

function onEditorClick(e) {
    const tag = e.target?.tagName;
    if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || tag === 'BUTTON' || e.target?.isContentEditable) return;
    editorRoot.value?.focus({ preventScroll: true });
}

// ── Cursor mousedown / drag handlers ──────────────────────────

// When "set downbeat" mode is armed, a click on any tab event means "this is
// beat 1" instead of a normal edit. Returns true if the click was consumed.
//
// Transcription quantizes note starts to an 8th/16th grid, so a note can sit
// at any tick within its bar — and that's fine: the re-shift works at tick
// resolution, so any note (on- or off-beat) can become the new "1".
function maybePickDownbeat(measureIndex, eventId) {
    if (!downbeatPickMode.value) return false;
    const m = allMeasures.value?.find(m => m.index === measureIndex);
    const ev = m?.events?.find(e => e.id === eventId);
    if (!ev) return false;
    // tickInMeasure is the note's bar-relative position; hand it over verbatim.
    videoSyncEditorRef.value?.pickDownbeatFromTick(ev.tickInMeasure ?? 0);
    downbeatPickMode.value = false;
    return true;
}

function onCursorMousedownEvent({ measureIndex, eventId, stringIndex, event }) {
    if (maybePickDownbeat(measureIndex, eventId)) return;
    clickEvent(measureIndex, eventId, stringIndex);
    setSelectedEvents([eventId]);
    _noteSelAnchorIdx.value = null;
    editorRoot.value?.focus({ preventScroll: true });
    seekToMeasure(measureIndex);
}

function onCursorMouseenterEvent({ eventId }) { }

function onCursorMousedownRest({ measureIndex, eventId, event }) {
    if (maybePickDownbeat(measureIndex, eventId)) return;
    clickRest(measureIndex, eventId);
    setSelectedEvents([eventId]);
    _noteSelAnchorIdx.value = null;
    editorRoot.value?.focus({ preventScroll: true });
    seekToMeasure(measureIndex);
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

    // Beat position of the right-click — used both for slot lookup and new slot placement
    const _bpm = (model.value?.ticksPerMeasure ?? 1920) / 480;
    let clickBeat = 0;
    if (event?.currentTarget) {
        const rect = event.currentTarget.getBoundingClientRect();
        clickBeat = ((event.clientX - rect.left) / rect.width) * _bpm;
    }

    // Determine which chord slot was right-clicked using actual offsets
    const slotCount = m?.chordNames?.length || 0;
    let clickedChordIndex = 0;
    if (slotCount > 0) {
        const offsets = m.chordOffsets?.length === slotCount ? m.chordOffsets : m.chordNames.map((_, i) => i * (_bpm / slotCount));
        const beats   = m.chordBeats?.length  === slotCount ? m.chordBeats   : m.chordNames.map(() => _bpm / slotCount);
        // Find last slot whose start is <= clickBeat
        clickedChordIndex = 0;
        for (let i = 0; i < slotCount; i++) {
            if (offsets[i] <= clickBeat) clickedChordIndex = i;
        }
    }

    const chordName = m?.chordNames?.[clickedChordIndex];
    const items   = [];

    const hasPartialBarSelection = single && selectedEvents.value.size > 0 && selectedEvents.value.size < m.events.filter(e => (e.voice ?? 1) === 1).length;
    const isSingleNoteSelection = selectedEvents.value.size === 1;

    if (single) {
        if (chordName) {
            items.push(
                { id: 'renameChord',   label: 'Rename chord',            icon: '✏️', group: 'chord' },
                { id: 'changeVoicing', label: 'Change voicing',          icon: '🎸', group: 'chord' },
                { id: 'identifyChord', label: 'Identify chord from tab', icon: '🔍', group: 'chord' },
                { id: 'addChordSlot',  label: 'Add chord slot',                       group: 'chord' },
            );
        } else {
            items.push(
                { id: 'identifyChord', label: 'Identify chord from tab', icon: '🔍', group: 'chord' },
                { id: 'addChordSlot',  label: 'Add chord slot manually',              group: 'chord' },
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
        if (window.__sbnLeadsheet?.id) {
            items.push({ divider: true, group: 'exercise' });
            items.push({ id: 'saveAsExercise', label: 'Save bar as exercise', group: 'exercise' });
        }
    } else {
        items.push(
            { id: 'copy', label: `Copy selection (${indices.length} bars)`,   shortcut: 'Ctrl+C', group: 'clipboard' },
            { id: 'cut',  label: `Cut selection (${indices.length} bars)`,    shortcut: 'Ctrl+X', group: 'clipboard' },
            { id: 'paste', label: 'Paste', shortcut: 'Ctrl+V', group: 'clipboard', disabled: !clipboard.value },
            { id: 'insertBarsAfter',  label: `Insert ${indices.length} bars after`,  group: 'structure' },
            { id: 'insertBarsBefore', label: `Insert ${indices.length} bars before`, group: 'structure' },
            { id: 'deleteSelection', label: `Delete ${indices.length} bars`, danger: true, group: 'danger' },
        );
        if (window.__sbnLeadsheet?.id) {
            items.push({ divider: true, group: 'exercise' });
            items.push({ id: 'saveAsExercise', label: `Save ${indices.length} bars as exercise`, group: 'exercise' });
        }
    }

    if (typeof window.showContextMenu === 'function') {
        window.showContextMenu(event, items, (actionId) => {
            handleTabContextAction(actionId, measureIndex, clickedChordIndex, clickBeat);
        });
    }

    editorRoot.value?.focus({ preventScroll: true });
}

/**
 * Context menu actions for the tab view. Structural ops mutate the Vue model
 * and sync harmony to Alpine via sbn-tab-sections-sync.
 * Clipboard ops are stubs pending measure-level tab copy/paste.
 */
function handleTabContextAction(actionId, measureIndex, chordIndex = 0, clickBeat = 0) {
    switch (actionId) {
        case 'renameChord': {
            inlineRenameTarget.value = { gi: measureIndex, ci: chordIndex, ts: Date.now(), source: 'tab' };
            break;
        }
        case 'changeVoicing': {
            const m = allMeasures.value[measureIndex];
            onChordClick({ measureIndex, chordIndex, chordName: m?.chordNames?.[chordIndex] || '' });
            break;
        }
        case 'addChordSlot': {
            chordGridOps.addChordAtBeat(measureIndex, clickBeat);
            nextTick(() => {
                const m = allMeasures.value[measureIndex];
                const snapped = Math.round(clickBeat / 0.5) * 0.5;
                let ci = (m.chordOffsets || []).findIndex(o => Math.abs(o - snapped) < 0.01);
                if (ci === -1) ci = (m?.chordNames?.length ?? 1) - 1;
                inlineRenameTarget.value = { gi: measureIndex, ci, ts: Date.now(), source: 'tab' };
            });
            break;
        }
        case 'identifyChord': {
            const m = allMeasures.value[measureIndex];
            if (!m?.chordNames?.length) {
                // No slots yet — create one at the clicked beat position
                chordGridOps.addChordAtBeat(measureIndex, clickBeat);
                // Find the new slot index by matching the snapped beat
                const snapped = Math.round(clickBeat / 0.5) * 0.5;
                chordIndex = (m.chordOffsets || []).findIndex(o => Math.abs(o - snapped) < 0.01);
                if (chordIndex === -1) chordIndex = 0;
            }
            onChordIdentify({ measureIndex, chordIndex, chordName: m?.chordNames?.[chordIndex] || '' });
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
        case 'insertBarsAfter':
        case 'insertBarsBefore': {
            const selectedIndices = getSelectedMeasureIndices();
            const count = selectedIndices.length;
            const insertAfter = actionId === 'insertBarsAfter';
            const refGi = insertAfter ? Math.max(...selectedIndices) : Math.min(...selectedIndices);
            const c = globalToSectionMeasure(refGi);
            if (!c) break;
            const refG = globalMeasureIndex(c.si, c.mi);
            wrapCommand(`Insert ${count} bars (tab)`, [], () => {
                for (let i = 0; i < count; i++) {
                    // Re-resolve coord each time since indices shift after each insert
                    const coord = globalToSectionMeasure(insertAfter ? refG + i : refG);
                    if (!coord) continue;
                    const G = globalMeasureIndex(coord.si, coord.mi);
                    if (insertAfter) {
                        insertMeasureAfter(coord.si, coord.mi);
                        applyChordVoicingOps({ voicingReindexAt: G + 1, voicingDelta: 1 });
                    } else {
                        insertMeasureBefore(coord.si, coord.mi);
                        applyChordVoicingOps({ voicingReindexAt: G, voicingDelta: 1 });
                    }
                }
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
        case 'saveAsExercise':
            handleSaveAsExercise(measureIndex);
            break;
    }
}

async function handleSaveAsExercise(contextMeasureIndex) {
    const leadsheetId = window.__sbnLeadsheet?.id;
    if (!leadsheetId) return;

    const indices = hasNoteSelection.value
        ? getSelectedMeasureIndices()
        : [contextMeasureIndex];
    if (!indices.length) return;

    const firstGi = Math.min(...indices);
    const lastGi  = Math.max(...indices);
    const defaultTitle = `${model.value?.title ?? ''} (bars ${firstGi + 1}–${lastGi + 1})`.trim();
    const title = window.prompt('Exercise title:', defaultTitle);
    if (title === null) return; // cancelled

    const token = document.querySelector('meta[name="csrf-token"]')?.content;
    try {
        const resp = await fetch(`/admin/exercises/from-leadsheet/${leadsheetId}/slice`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token, 'Accept': 'application/json' },
            body: JSON.stringify({ measure_indices: indices, title: title || defaultTitle }),
        });
        const raw = await resp.text();
        console.log('[saveAsExercise] status', resp.status, 'body:', raw);
        if (!resp.ok) {
            let msg = raw;
            try { const j = JSON.parse(raw); msg = j.message || JSON.stringify(j.errors ?? j); } catch {}
            alert('Save as exercise failed (' + resp.status + '):\n' + msg);
            return;
        }
        const data = JSON.parse(raw);
        if (data.success) {
            if (typeof window.sbnToast === 'function') {
                window.sbnToast(data.message, 'success');
                // Second toast with clickable link (innerHTML, not textContent)
                const t = document.createElement('div');
                t.className = 'sbn-toast sbn-toast-success';
                t.style.cssText = 'position:fixed;bottom:60px;right:16px;z-index:9999;padding:10px 18px;border-radius:8px;font-size:14px;cursor:pointer;';
                t.innerHTML = '<a href="' + data.editUrl + '" target="_blank" style="color:inherit;text-decoration:underline;">Open exercise →</a>';
                document.body.appendChild(t);
                setTimeout(() => { t.style.opacity = '0'; setTimeout(() => t.remove(), 300); }, 5000);
            } else {
                if (confirm(data.message + '\n\nOpen exercise in new tab?')) {
                    window.open(data.editUrl, '_blank');
                }
            }
        } else {
            alert('Error: ' + (data.message || 'Unknown error'));
        }
    } catch (e) {
        alert('Network error saving exercise.');
    }
}

/**
 * Click on the "?" placeholder in an empty bar.
 * Tells Alpine to open the chord name text-input picker for that measure.
 */
function onChordNameNeeded({ measureIndex, chordIndex }) {
    inlineRenameTarget.value = { gi: measureIndex, ci: chordIndex, ts: Date.now(), source: 'tab' };
}
function onChordContextMenu({ measureIndex, chordIndex, chordName, event }) {
    const m = allMeasures.value[measureIndex];
    const slotCount = m?.chordNames?.length ?? 0;
    const items = [
        { id: 'renameChord',   label: 'Rename chord',            icon: '✏️', group: 'chord' },
        { id: 'changeVoicing', label: 'Change voicing',          icon: '🎸', group: 'chord' },
        { id: 'identifyChord', label: 'Identify chord from tab', icon: '🔍', group: 'chord' },
        { divider: true, group: 'chord' },
        { id: 'addChordSlot',    label: 'Add chord slot',  group: 'chord' },
        { id: 'deleteChordSlot', label: 'Delete this slot', danger: true, group: 'chord' },
    ];
    if (typeof window.showContextMenu === 'function') {
        window.showContextMenu(event, items, (actionId) => {
            switch (actionId) {
                case 'renameChord':
                    inlineRenameTarget.value = { gi: measureIndex, ci: chordIndex, ts: Date.now(), source: 'tab' };
                    break;
                case 'changeVoicing':
                    onChordClick({ measureIndex, chordIndex, chordName });
                    break;
                case 'identifyChord':
                    onChordIdentify({ measureIndex, chordIndex, chordName });
                    break;
                case 'addChordSlot':
                    chordGridOps.addChordToMeasure(measureIndex);
                    break;
                case 'deleteChordSlot':
                    chordGridOps.deleteChords([{ gi: measureIndex, ci: chordIndex }]);
                    break;
            }
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
                const row = section.measures.slice(idx, idx + count);
                row._fromLineBreaks = true;
                row._intendedCount = count;
                _stampPickupFlexPcts(row);
                _stampGraceFlexPcts(row);
                rows.push(row);
                idx += count;
            }
        });
        if (idx < section.measures.length) {
            const row = section.measures.slice(idx);
            row._fromLineBreaks = true;
            row._intendedCount = row.length;
            _stampPickupFlexPcts(row);
            _stampGraceFlexPcts(row);
            rows.push(row);
        }
        return rows;
    }

    // Fallback: uniform rows of LAYOUT.measuresPerRow
    const rows = [];
    for (let i = 0; i < section.measures.length; i += LAYOUT.measuresPerRow) {
        const row = section.measures.slice(i, i + LAYOUT.measuresPerRow);
        row._intendedCount = LAYOUT.measuresPerRow;
        _stampPickupFlexPcts(row);
        _stampGraceFlexPcts(row);
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
 * Stamps _pickupPct and _regularPct onto a row array when the first bar is a
 * pickup. Called once per row in measureRows() so the template reads cached values.
 *
 * Example: 1-beat pickup in 4/4, N=4 bars:
 *   pickupPct  = 100/4 * (1/4) = 6.25%   (pickup bar width)
 *   regularPct = (100 - 6.25) / 3 = 31.25% (each remaining bar)
 */
function _stampPickupFlexPcts(row) {
    const first = row[0];
    if (!first || first.pickupBeats == null) {
        row._pickupPct  = null;
        row._regularPct = null;
        return;
    }
    const N = row._intendedCount || row.length;
    const globalBpm = beatsPerMeasure.value || 4;
    const ratio = Math.min(1, Math.max(0.05, first.pickupBeats / globalBpm));
    row._pickupPct  = (100 / N) * ratio * 2;
    row._regularPct = N > 1 ? (100 - row._pickupPct) / (N - 1) : 100;
}

/**
 * Total px a bar's grace clusters demand (sum of cluster widths across events).
 * Must match the cluster-width formula in TabMeasure.vue's grace renderer.
 */
function _graceWidthPx(measure) {
    const GRACE_DX = 8, GRACE_PAD = 8, GRACE_GLYPH_W = 3;  // keep in sync with TabMeasure.vue
    let total = 0;
    for (const ev of (measure.events || [])) {
        const c = ev.graceNotes?.length ?? 0;
        if (c > 0) total += GRACE_PAD + (c - 1) * GRACE_DX + GRACE_GLYPH_W;
    }
    return total;
}

/**
 * Proportional flex redistribution for rows containing grace notes.
 *
 * Each bar gets a "demand" = base (weighted by content density so busy bars
 * resist shrinking) + grace demand (px width / standard bar width). Flex % is
 * each bar's demand share of the row total, so the row stays exactly 100% wide:
 * grace bars grow, slack (long-note) bars give up their room. Per-bar flex % is
 * stamped onto row._gracePct[] and read by the template.
 *
 * Skipped when the row has a pickup (that owns its own flex %) or has no grace.
 */
function _stampGraceFlexPcts(row) {
    row._gracePct = null;
    if (row._pickupPct != null) return;               // pickup rows own their flex
    if (!row.some(m => _graceWidthPx(m) > 0)) return; // no grace → leave uniform

    const N = row._intendedCount || row.length;
    const stdBar = LAYOUT.measureWidth || 160;

    const demands = row.map(m => {
        const eventCount = (m.events || []).filter(e => !e.isRest).length;
        // Base: 0.7 (sparse) → ~1.4 (busy) so width is stolen mostly from slack bars.
        const base = 0.6 + 0.1 * Math.min(eventCount, 8);
        const grace = _graceWidthPx(m) / stdBar;
        return base + grace;
    });

    // Account for empty padding slots (fallback rows) so the row still sums to 100%.
    const emptyCount = Math.max(0, N - row.length);
    const baseEmpty = 1.0;  // each hidden slot holds a uniform share
    const total = demands.reduce((a, b) => a + b, 0) + emptyCount * baseEmpty;
    if (total <= 0) return;

    row._gracePct      = demands.map(d => (d / total) * 100);
    row._graceEmptyPct = (baseEmpty / total) * 100;
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
.sbn-ve-tuning-badge {
    display: inline-flex;
    align-items: center;
    margin-left: auto;
    padding: 2px 8px;
    font-size: 11px;
    font-weight: 600;
    letter-spacing: 0.04em;
    background: #1e40af;
    color: #fff;
    border-radius: 4px;
    pointer-events: none;
    user-select: none;
}
.sbn-ve-tab-layer-pills {
    display: inline-flex;
    align-items: center;
    margin-left: 8px;
    gap: 2px;
    background: rgba(0,0,0,0.08);
    border-radius: 5px;
    padding: 2px;
}
.sbn-ve-tab-layer-pill {
    padding: 2px 10px;
    font-size: 11px;
    font-weight: 600;
    border-radius: 3px;
    border: none;
    background: transparent;
    color: inherit;
    cursor: pointer;
    opacity: 0.6;
    transition: background 0.12s, opacity 0.12s;
}
.sbn-ve-tab-layer-pill:hover {
    opacity: 0.85;
}
.sbn-ve-tab-layer-pill.is-active {
    background: #fff;
    opacity: 1;
    box-shadow: 0 1px 2px rgba(0,0,0,0.12);
}

/* Tab row layout — flex row, buttons vertically centered on the SVG staff.
   The chord bar is 38px tall above the SVG; shifting the resize group down
   by half that (19px) moves it from "centre of full row" to "centre of SVG". */
.sbn-tab-row {
    display: flex;
    align-items: center;
}
.sbn-tab-measures {
    flex: 1;
    display: flex;
}

/* Row resize controls */
.sbn-tab-row-resize {
    display: flex;
    flex-direction: column;
    gap: 5px;
    padding-left: 8px;
    margin-top: -15px;
    opacity: 0;
    transition: opacity 0.2s ease;
}
.sbn-tab-row:hover .sbn-tab-row-resize {
    opacity: 1;
}
.sbn-tab-row-btn {
    width: 17px;
    height: 17px;
    display: flex;
    align-items: center;
    justify-content: center;
    border: none;
    border-radius: 50%;
    background: var(--clr-gradient);
    color: #fff;
    font-size: 11px;
    font-weight: 700;
    cursor: pointer;
    padding: 0;
    transition: opacity 0.15s ease, background 0.15s ease;
    line-height: 1;
}
.sbn-tab-row-btn:hover:not(:disabled) {
    background: var(--clr-gradient-hover);
    opacity: 1;
}
.sbn-tab-row-btn:disabled {
    opacity: 0.25;
    cursor: not-allowed;
}
.sbn-ve-row-btn-section {
    background: transparent;
    border: 1.5px dashed var(--clr-accent);
    color: var(--clr-accent);
    font-size: 9px;
}
.sbn-ve-row-btn-section:hover:not(:disabled) {
    background: var(--clr-gradient);
    border-color: transparent;
    color: #fff;
}

/* Section header action buttons — override leadsheets.css defaults */
.sbn-ve-section-btn {
    width: 18px;
    height: 18px;
    display: flex;
    align-items: center;
    justify-content: center;
    border: none;
    border-radius: 50%;
    background: var(--clr-gradient);
    color: #fff;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    padding: 0;
    line-height: 1;
    transition: opacity 0.15s ease, background 0.15s ease;
}
.sbn-ve-section-btn:hover {
    background: var(--clr-gradient-hover);
}
.sbn-ve-section-delete {
    width: 18px;
    height: 18px;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 1.5px solid var(--clr-red);
    border-radius: 50%;
    background: transparent;
    color: var(--clr-red);
    font-size: 12px;
    cursor: pointer;
    padding: 0;
    line-height: 1;
    transition: background 0.15s ease, color 0.15s ease;
}
.sbn-ve-section-delete:hover {
    background: var(--clr-red);
    color: #fff;
}
.sbn-transpose-panel {
    max-width: 340px;
    width: 100%;
}
.sbn-transpose-body {
    padding: 16px;
    display: flex;
    flex-direction: column;
    gap: 14px;
}
.sbn-transpose-row {
    display: flex;
    align-items: center;
    gap: 12px;
}
.sbn-transpose-label {
    width: 80px;
    font-size: 0.85rem;
    font-weight: 600;
    flex-shrink: 0;
}
.sbn-transpose-from {
    font-size: 0.9rem;
    font-weight: 500;
}
.sbn-transpose-select,
.sbn-transpose-semitones {
    flex: 1;
    padding: 5px 8px;
    border: 1px solid var(--clr-border, #d1d5db);
    border-radius: 5px;
    background: var(--clr-surface, #fff);
    font-size: 0.9rem;
}
.sbn-transpose-semitones {
    width: 70px;
    flex: none;
    text-align: center;
}
.sbn-transpose-actions {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    margin-top: 4px;
}
</style>