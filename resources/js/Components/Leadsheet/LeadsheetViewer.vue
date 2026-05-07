<template>
  <div class="sbn-leadsheet-viewer" :class="{ 'is-embedded': embedded }">
    <!-- Header band (hidden when embedded) -->
    <div v-if="!embedded" class="sbn-leadsheet-header">
      <div class="sbn-leadsheet-back-section">
        <a href="/library/songs" class="sbn-back-link">← Back to Library</a>
      </div>

      <div class="sbn-leadsheet-controls">
        <!-- Mode toggle (Phase 9b: 3-way: no-chords / chords / tab) -->
        <div class="sbn-leadsheet-density-toggle">
          <button
            :class="{ active: mode === 'no-chords' }"
            @click="mode = 'no-chords'"
            title="No chords"
          >—</button>
          <button
            :class="{ active: mode === 'chords' }"
            @click="mode = 'chords'"
            title="Chords"
          >▦</button>
          <button
            v-if="hasTab"
            :class="{ active: mode === 'tab' }"
            @click="mode = 'tab'"
            title="Tab notation"
          >♫</button>
        </div>

        <!-- Cinema view toggle -->
        <div class="sbn-leadsheet-view-toggle">
          <button class="is-active" disabled>Classic</button>
          <a
            v-if="cinemaUrl"
            :href="cinemaUrl"
            class="sbn-leadsheet-view-toggle-link"
            title="Cinema view"
          >Cinema</a>
          <button v-else disabled title="Cinema view">Cinema</button>
        </div>
      </div>
    </div>

    <!-- Two-column layout -->
    <div class="sbn-leadsheet-content">
      <!-- Main chord grid + transport -->
      <div class="sbn-leadsheet-main">
        <!-- Tab view (Phase 9b) -->
        <div v-if="mode === 'tab' && model" class="sbn-tab-viewer">
          <div 
            v-for="(section, si) in model.sections" 
            :key="section.id || si"
            class="sbn-ve-section"
          >
            <div class="sbn-ve-section-header">
              <div v-if="section.id" class="sbn-ve-section-id">{{ section.id }}</div>
              <span class="sbn-ve-section-name">{{ section.name }}</span>
              <span class="sbn-ve-section-bar-count">{{ section.measures?.length || 0 }} bars</span>
            </div>
            <div class="sbn-ve-section-body">
              <div v-for="(row, ri) in tabMeasureRows(section)" :key="ri" class="sbn-tab-row">
                <div class="sbn-tab-measures">
                  <TabMeasure
                    v-for="(measure, li) in row"
                    :key="measure.index"
                    :measure="measure"
                    :is-first-of-section="ri === 0 && li === 0"
                    :ticks-per-measure="model.ticksPerMeasure"
                    :next-measure="getNextTabMeasure(measure.index)"
                    :is-next-first-of-section="isNextTabMeasureFirstOfSection(measure.index)"
                    :chord-names="measure.chordNames || []"
                    :bars-per-row="row._intendedCount"
                    :read-only="true"
                    :allow-chord-click="true"
                    :cursor="null"
                    :pending-digit="null"
                    :selected-events="new Set()"
                    @click="() => onTabMeasureClick(measure.index)"
                    @chord-click="onTabChordClick"
                  />
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Chord grid view (existing) -->
        <ChordGridView
          v-else-if="model"
          :sections="model.sections || []"
          :read-only="true"
          :density="density"
        />

        <!-- Transport bar (sticky within main container) -->
        <div
          class="sbn-leadsheet-transport"
          :class="{ 'is-visible': transportVisible || transportHovered, 'is-hidden': !transportVisible && !transportHovered }"
          @mouseenter="onTransportMouseEnter"
          @mouseleave="onTransportMouseLeave"
        >
      <TransportBar
        :is-playing="transportPlaying"
        :current-beat="transportBeat"
        :total-beats="totalBeats"
        :tempo="tempo"
        :beats-per-measure="beatsPerMeasure"
        :view-mode="mode === 'tab' ? 'tab' : 'chords'"
        @toggle="onTransportToggle"
        @seek="onTransportSeek"
        @tempo-change="onTempoChange"
      />
        </div>
      </div>

      <!-- Education panel -->
      <div class="sbn-leadsheet-sidebar">
        <EduPanel
          :current-chord="currentChord"
          :current-section-id="currentSectionId"
          :selection-key="selectionKey"
          :song="songInfo"
          :progressions="progressions"
          :chord-cards="chordCards"
          :quality-by-key="qualityByKey"
          :edu-chord-qualities="eduChordQualities"
        />
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, provide, watch, onMounted, onUnmounted } from 'vue';

// Components
import ChordGridView from '@/tab-editor/components/ChordGridView.vue';
import TransportBar from '@/tab-editor/components/TransportBar.vue';
import EduPanel from './EduPanel.vue';
import TabMeasure from '@/tab-editor/components/TabMeasure.vue';

// Composables
import { useTabModel } from '@/tab-editor/composables/useTabModel.js';
import { useGridSelection } from '@/tab-editor/composables/useGridSelection.js';
import { useChordAudio } from '@/tab-editor/composables/useChordAudio.js';
import { useAudioEngine } from '@/tab-editor/composables/useAudioEngine.js';

// Audio engine + adapters (required to load tab + chord events before playback)
import { getAudioEngine } from '@/audio/engine/AudioEngine.js';
import { tabModelToEvents } from '@/audio/adapters/tabMeasureToEvents.js';
import { chordVoicingsToEvents } from '@/audio/adapters/chordVoicingsToEvents.js';

const props = defineProps({
  /** Leadsheet payload from controller — see resources/js/types/leadsheet.ts */
  leadsheet: {
    type: Object,
    required: true,
  },
  /** ProgressionRef[] — see resources/js/types/leadsheet.ts */
  progressions: {
    type: Array,
    default: () => [],
  },
  /** ChordDiagramData map keyed by "chordName@gi.ci" — enriched chord card data */
  chordCards: {
    type: Object,
    default: () => ({}),
  },
  /** Quality slug map keyed by "chordName@gi.ci" — for edu blurbs (Step 6) */
  qualityByKey: {
    type: Object,
    default: () => ({}),
  },
  /** Edu content blurbs for chord qualities keyed by quality slug */
  eduChordQualities: {
    type: Object,
    default: () => ({}),
  },
  /** When true, suppresses the standalone header band (course-lesson embed). */
  embedded: {
    type: Boolean,
    default: false,
  },
  /** URL to the cinema view for this leadsheet (Phase 10). */
  cinemaUrl: {
    type: String,
    default: null,
  },
});

// ── 3-way mode state with localStorage persistence (Phase 9b) ───────────────
/**
 * Load initial mode from localStorage, migrating from old density key.
 * Mirrors Phase 9b §4: density → mode mapping during refactor.
 */
function loadInitialMode() {
  const newKey = localStorage.getItem('sbn.leadsheet.mode');
  if (newKey === 'no-chords' || newKey === 'chords' || newKey === 'tab') return newKey;

  // Migrate old density key
  const oldDensity = localStorage.getItem('sbn.leadsheet.density');
  if (oldDensity === 'compact') return 'no-chords';
  return 'chords'; // 'full' or default
}

const mode = ref(loadInitialMode());

/**
 * Mode watcher: persists to localStorage and handles audio handoff.
 * Per Phase 9b §5.3: switching modes while playing pauses, seeks both
 * paths to the same position, then resumes in the new path. Audio gap
 * is < 50 ms — imperceptible.
 *
 * Also converts selection between modes to preserve user context.
 *
 * Note: callbacks reference symbols declared further down (transportPlaying,
 * isTabPlaying, etc.). This works because the watcher only fires after
 * setup completes.
 */
watch(mode, async (newMode, oldMode) => {
  localStorage.setItem('sbn.leadsheet.mode', newMode);
  if (oldMode === newMode) return;

  // Preserve selection across mode switches
  if (oldMode === 'tab' && newMode !== 'tab') {
    // Tab → chord/no-chords: convert tabSelection to gridSelection
    if (tabSelection.value) {
      const gi = tabSelection.value.gi;
      gridSelection.handleClick(gi, 0, new MouseEvent('click'));
      tabSelection.value = null;
    }
  } else if (oldMode !== 'tab' && newMode === 'tab') {
    // Chord/no-chords → tab: convert gridSelection to tabSelection
    const sel = gridSelection.selection.value;
    if (sel.length) {
      const last = sel[sel.length - 1];
      tabSelection.value = { gi: last.gi };
      gridSelection.clearSelection();
    }
  }

  const wasPlaying = transportPlaying.value;
  const beat       = transportBeat.value;

  if (isTabPlaying.value)   pauseTab();
  if (isChordPlaying.value) pauseChord();

  seekTab(beat);
  seekChord(beat);

  if (wasPlaying) {
    if (!_eventsLoaded) await loadAllEvents();
    if (newMode === 'tab') await playTab();
    else                   await playChord();
  }
});

/**
 * hasTab gate: Tab button only renders when leadsheet has actual tab notes
 * (string/fret data). useTabModel exposes hasData which scans melody.value
 * for notes with string/fret set — exactly the signal we want.
 */
const hasTab = computed(() => hasData.value);

/**
 * Density computed: maps mode → density prop for ChordGridView.
 * Per Phase 9b §4: mode === 'chords' → density='full', mode === 'no-chords' → density='compact'.
 */
const density = computed(() => {
  if (mode.value === 'chords') return 'full';
  if (mode.value === 'no-chords') return 'compact';
  return 'full'; // tab mode doesn't render ChordGridView, so density is irrelevant
});

// ── Smart sticky transport bar (contained within main content) ───────────────
// Hide on scroll down, show on scroll up (modern header pattern)
const transportVisible = ref(true);
const transportHovered = ref(false);
let _lastScrollY = 0;
let _scrollTimeout = null;

function onScroll() {
  const currentY = window.scrollY;
  const scrollDelta = currentY - _lastScrollY;
  
  // Show when scrolling up, hide when scrolling down (with threshold)
  if (scrollDelta < -5) {
    transportVisible.value = true; // Scrolling up
  } else if (scrollDelta > 10 && currentY > 100) {
    transportVisible.value = false; // Scrolling down, past initial viewport
  }
  
  // Always show when near bottom of page
  const nearBottom = (window.innerHeight + currentY) >= (document.documentElement.scrollHeight - 100);
  if (nearBottom) {
    transportVisible.value = true;
  }
  
  _lastScrollY = currentY;
  
  // Clear existing timeout and set new one to show bar after scroll stops
  clearTimeout(_scrollTimeout);
  _scrollTimeout = setTimeout(() => {
    transportVisible.value = true;
  }, 2000); // Show after 2s of no scrolling
}

function onTransportMouseEnter() {
  transportHovered.value = true;
  transportVisible.value = true;
}

function onTransportMouseLeave() {
  transportHovered.value = false;
}

function onKeyDown(e) {
  if (e.code === 'Space' || e.key === ' ') {
    e.preventDefault(); // Prevent page scroll
    onTransportToggle();
  }
}

onMounted(() => {
  window.addEventListener('scroll', onScroll, { passive: true });
  window.addEventListener('keydown', onKeyDown);
});

onUnmounted(() => {
  window.removeEventListener('scroll', onScroll);
  window.removeEventListener('keydown', onKeyDown);
  clearTimeout(_scrollTimeout);
});

// ── Tab model from leadsheet json_data ───────────────────────────────────────
// useTabModel expects refs as inputs (it calls .value on each). The admin
// editor wires these via useAlpineBridge; the viewer wraps the static JSON.
const json = props.leadsheet.jsonData ?? {};
const melodyRef        = ref(json.melody ?? null);
const sectionsRef      = ref(json.sections ?? []);
const timeSignatureRef = ref(json.timeSignature ?? '4/4');
const repeatMarkersRef = ref(json.repeatMarkers ?? {});
const voltaEndingsRef  = ref(json.voltaEndings ?? {});
const chordVoicingsRef = ref(json.chordVoicings ?? {});

const tabModel = useTabModel(
  melodyRef,
  sectionsRef,
  timeSignatureRef,
  repeatMarkersRef,
  voltaEndingsRef,
  chordVoicingsRef,
);
const { model, buildModel, hasData } = tabModel;

// Build the model immediately. (TabEditor relies on a watcher; we call directly
// since our refs never change after mount.)
buildModel();

// useTabModel doesn't store tempo — set it from the leadsheet so audio + UI
// pick it up. (TabEditor reads tempo from the Alpine bridge and bypasses the
// model for transport, which is why it works there.)
if (model.value) {
  model.value.tempo = props.leadsheet.tempo ?? 120;
}

// Mode fallback: if persisted mode === 'tab' but this song has no melody,
// silently demote to 'chords'. Done inline since model is now built.
if (mode.value === 'tab' && !hasTab.value) {
  mode.value = 'chords';
}

// ── Selection (read-only — drives EduPanel current-chord) ────────────────────
// Chord grid selection (used in chord/no-chords modes)
const gridSelection = useGridSelection(model);

// Tab mode selection (measure index only — chord index defaults to 0)
const tabSelection = ref(null); // { gi: number } or null

// ── Audio (two playback paths: chord + tab — Phase 9b) ───────────────────────
// Chord audio path (existing)
const chordAudio = useChordAudio(model);
const {
  isPlaying: isChordPlaying,
  currentBeat: chordCurrentBeat,
  play:   playChord,
  pause:  pauseChord,
  seek:   seekChord,
} = chordAudio;

// Tab audio path (new for Phase 9b — mirrors TabEditor.vue)
const tabAudio = useAudioEngine(model);
const {
  isPlaying: isTabPlaying,
  currentBeat: tabCurrentBeat,
  play:   playTab,
  pause:  pauseTab,
  seek:   seekTab,
} = tabAudio;

// Engine event-loading. Phase 9b: always load both tab + chord events.
// The engine is a singleton shared with the admin editor; events must be
// (re)loaded once per model before play(). Mirrors TabEditor.vue:421-441.
const engine = getAudioEngine();
let _eventsLoaded = false;

async function loadAllEvents() {
  if (!model.value) return;
  await engine.init({ bpm: model.value.tempo ?? 120 });

  const tabEvents   = tabModelToEvents(model.value, { startBeat: 0 });
  const chordEvents = chordVoicingsToEvents(model.value, { startBeat: 0 });
  const combined    = [...tabEvents, ...chordEvents].sort((a, b) => a.time - b.time);

  engine.load(combined);
  engine.setTempo(model.value.tempo ?? 120);
  _eventsLoaded = true;
}

async function reloadEvents() {
  _eventsLoaded = false;
  await loadAllEvents();
}

// ── Unified transport state (mirrors TabEditor.vue:464-477) ───────────────────
// Phase 9b: unified state across both playback paths. Mode dispatch happens in
// transport handlers (Step 3 adds mode-aware dispatch).
const transportPlaying = computed(() => isTabPlaying.value || isChordPlaying.value);

const transportBeat = computed(() => {
  if (isTabPlaying.value)   return tabCurrentBeat.value;
  if (isChordPlaying.value) return chordCurrentBeat.value;
  return tabCurrentBeat.value ?? chordCurrentBeat.value ?? 0;  // parked
});

// ── Transport derived values ─────────────────────────────────────────────────
const tempo = ref(props.leadsheet.tempo ?? 120);

// Match the engine-internal definition (TICKS_PER_BEAT = 480) so seek/scrub align.
const beatsPerMeasure = computed(() => {
  const tpm = model.value?.ticksPerMeasure;
  if (tpm) return tpm / 480;
  // Fallback before model builds — derive from time signature.
  const ts = props.leadsheet.timeSignature ?? '4/4';
  const num = parseInt(String(ts).split('/')[0], 10);
  return Number.isFinite(num) && num > 0 ? num : 4;
});

const totalBeats = computed(() => {
  const sections = model.value?.sections ?? [];
  let measures = 0;
  for (const s of sections) measures += (s.measures?.length ?? 0);
  return measures * beatsPerMeasure.value;
});

// ── Transport handlers ───────────────────────────────────────────────────────
// Phase 9b: unified handlers that drive both playback paths with mode dispatch.
async function onTransportToggle() {
  if (transportPlaying.value) {
    // Pause whichever path is active
    if (isTabPlaying.value)   pauseTab();
    if (isChordPlaying.value) pauseChord();
  } else {
    if (!_eventsLoaded) await loadAllEvents();
    // Dispatch by mode: tab mode uses tab audio, else chord audio
    if (mode.value === 'tab') await playTab();
    else                      await playChord();
  }
}

function onTransportSeek(beat) {
  // Seek both paths (cheap; only one is active)
  seekTab(beat);
  seekChord(beat);
}

function onTempoChange(newTempo) {
  tempo.value = newTempo;
  if (model.value) model.value.tempo = newTempo;
  engine.setTempo(newTempo);
}

// Click-to-seek from a chord card: seek to measure + chord offset (only auto-play if already playing)
async function seekToMeasure(gi, ci = 0) {
  let total = 1;
  let offset = null;
  if (model.value) {
    let currentMeasure = null;
    for (const section of model.value.sections || []) {
      const found = (section.measures || []).find(m => m.index === gi);
      if (found) { currentMeasure = found; break; }
    }
    if (currentMeasure) {
      if (currentMeasure.chordNames) {
        total = currentMeasure.chordNames.length || 1;
      }
      if (currentMeasure.chordOffsets && currentMeasure.chordOffsets[ci] != null) {
        offset = currentMeasure.chordOffsets[ci];
      }
    }
  }
  const bpm = beatsPerMeasure.value;
  const beatOffset = offset != null ? offset : ci * (bpm / total);
  const beatStart = gi * bpm + beatOffset;

  if (!_eventsLoaded) await loadAllEvents();
  // Seek both paths
  seekTab(beatStart);
  seekChord(beatStart);
  // If already playing, playback continues from new position
  // If stopped/paused, just seek without starting playback
}

// ── Active-measure highlight (drives the beat-tick sweep across the grid) ────
const playingMeasureIndex = ref(0);
watch(
  [transportBeat, beatsPerMeasure],
  ([beat, bpm]) => {
    playingMeasureIndex.value = Math.floor((beat ?? 0) / (bpm || 4));
  },
  { immediate: true }
);

// ── EduPanel current-chord derivation (selection uses { gi, ci }) ────────────
function _findInModel(gi) {
  if (!model.value) return null;
  for (let si = 0; si < model.value.sections.length; si++) {
    const sec = model.value.sections[si];
    for (let mi = 0; mi < (sec.measures?.length ?? 0); mi++) {
      const m = sec.measures[mi];
      if (m.index === gi) return { section: sec, measure: m };
    }
  }
  return null;
}

/**
 * Current selection data for EduPanel.
 * Phase 9b: Unified across chord mode ({ gi, ci }) and tab mode ({ gi } only, ci defaults to 0).
 */
const selectionData = computed(() => {
  // Tab mode: use tabSelection (measure index only, or with ci if chord was clicked)
  if (mode.value === 'tab' && tabSelection.value) {
    const found = _findInModel(tabSelection.value.gi);
    if (found) {
      return {
        gi: tabSelection.value.gi,
        ci: tabSelection.value.ci ?? 0, // Use ci if chord was clicked, else default to 0
        section: found.section,
        measure: found.measure,
      };
    }
    return null;
  }

  // Chord/no-chords mode: use gridSelection
  const sel = gridSelection.selection.value;
  if (!sel.length) return null;
  const last = sel[sel.length - 1];
  const found = _findInModel(last.gi);
  if (!found) return null;
  return {
    gi: last.gi,
    ci: last.ci,
    section: found.section,
    measure: found.measure,
  };
});

const currentChord = computed(() => {
  const data = selectionData.value;
  if (!data) return null;
  return data.measure.chordNames?.[data.ci] ?? null;
});

const currentSectionId = computed(() => {
  const data = selectionData.value;
  return data?.section?.id ?? null;
});

// Selection key for chordCards lookup: "chordName@gi.ci"
const selectionKey = computed(() => {
  const data = selectionData.value;
  if (!data) return null;
  const name = data.measure.chordNames?.[data.ci];
  if (!name) return null;
  return `${name}@${data.gi}.${data.ci}`;
});

/** Tab measure selection handler (Phase 9b). */
function onTabMeasureClick(gi) {
  tabSelection.value = { gi };
  seekToMeasure(gi);
}

/** Tab chord name click handler (Phase 9b) - updates selection with chord index. */
function onTabChordClick({ measureIndex, chordIndex, chordName }) {
  tabSelection.value = { gi: measureIndex, ci: chordIndex };
  seekToMeasure(measureIndex, chordIndex);
}

const songInfo = computed(() => ({
  title:         props.leadsheet.title,
  composer:      props.leadsheet.composer ?? null,
  songKey:       props.leadsheet.songKey ?? null,
  tempo:         props.leadsheet.tempo ?? null,
  timeSignature: props.leadsheet.timeSignature ?? null,
  rhythm:        props.leadsheet.rhythm ?? null,
}));

// ── Tab view helpers (Phase 9b) ───────────────────────────────────────────────
/**
 * Simplified measure row computation for tab viewer.
 * Respects section.lineBreaks if present; otherwise uses 4 measures per row.
 */
function tabMeasureRows(section) {
  const lineBreaks = section.lineBreaks;
  const measures = section.measures || [];

  if (lineBreaks?.length) {
    const rows = [];
    let idx = 0;
    for (const count of lineBreaks) {
      if (idx >= measures.length) break;
      const row = measures.slice(idx, idx + count);
      row._intendedCount = count;
      rows.push(row);
      idx += count;
    }
    if (idx < measures.length) {
      const row = measures.slice(idx);
      row._intendedCount = row.length;
      rows.push(row);
    }
    return rows;
  }

  // Fallback: uniform rows of 4 measures
  const rows = [];
  const barsPerRow = 4;
  for (let i = 0; i < measures.length; i += barsPerRow) {
    const row = measures.slice(i, i + barsPerRow);
    row._intendedCount = barsPerRow;
    rows.push(row);
  }
  return rows;
}

/** Get the next measure in the tab sequence (for cross-measure ties). */
function getNextTabMeasure(index) {
  if (!model.value) return null;
  const allMeasures = model.value.sections.flatMap(s => s.measures || []);
  const idx = allMeasures.findIndex(m => m.index === index);
  return idx >= 0 && idx < allMeasures.length - 1 ? allMeasures[idx + 1] : null;
}

/** Check if the next measure is the first in its section. */
function isNextTabMeasureFirstOfSection(index) {
  if (!model.value) return false;
  const next = getNextTabMeasure(index);
  if (!next) return false;
  for (const section of model.value.sections) {
    if (section.measures?.[0]?.index === next.index) return true;
  }
  return false;
}

// ── Provide to descendants (matches tab-editor contract) ─────────────────────
// Only the read-only essentials. Editor-only stores (chordPicker, voicingPicker,
// chordClipboard, chordGridOps) are deliberately NOT provided — children use
// inject(..., null) defaults and short-circuit on falsy values.
provide('model', model);
provide('gridSelection', gridSelection);
provide('beatsPerMeasureRef', beatsPerMeasure);
provide('playingMeasureIndex', playingMeasureIndex);
provide('transportBeat', transportBeat);
provide('seekToMeasure', seekToMeasure);
provide('transportPlaying', transportPlaying);
provide('readOnly', true);
// globalIndexOf: in the viewer the model already stores measure.index as the
// global index, so the identity-style fallback in ChordMeasure is fine. We
// still provide a function so descendants can rely on it being callable.
provide('globalIndexOf', (si, mi) => {
  const sec = model.value?.sections?.[si];
  return sec?.measures?.[mi]?.index ?? mi;
});
</script>

<style scoped>
.sbn-leadsheet-viewer {
  max-width: 1400px;
  margin: 0 auto;
  padding: 40px 20px 80px;
  background: white;
}

.sbn-leadsheet-viewer.is-embedded {
  max-width: none;
  margin: 0;
  padding: 0;
  border-radius: var(--radius);
  box-shadow: var(--clr-shadow);
  overflow: hidden;
}

/* Header - now inline, not full-width band */
.sbn-leadsheet-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 24px;
  padding: 0 0 24px;
  background: transparent;
}



/* Controls */
.sbn-leadsheet-controls {
  display: flex;
  gap: 12px;
  align-items: center;
}

.sbn-leadsheet-density-toggle,
.sbn-leadsheet-view-toggle {
  display: inline-flex;
  border: 1px solid var(--clr-border);
  border-radius: var(--radius-sm);
  overflow: hidden;
}

.sbn-leadsheet-density-toggle button,
.sbn-leadsheet-view-toggle button {
  padding: 6px 12px;
  border: 0;
  background: var(--clr-surface);
  cursor: pointer;
  transition: background 0.15s;
}

.sbn-leadsheet-density-toggle button + button,
.sbn-leadsheet-view-toggle button + button {
  border-left: 1px solid var(--clr-border);
}

.sbn-leadsheet-density-toggle button:hover,
.sbn-leadsheet-view-toggle button:hover:not(:disabled) {
  background: var(--clr-surface-2);
}

.sbn-leadsheet-density-toggle button.active {
  background: var(--clr-accent-bg);
  color: var(--clr-accent);
}

.sbn-leadsheet-view-toggle button:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.sbn-leadsheet-view-toggle button.is-active:disabled {
  background: var(--clr-accent-bg);
  color: var(--clr-accent);
  opacity: 1;
}

.sbn-leadsheet-view-toggle-link {
  padding: 6px 12px;
  border: 0;
  border-left: 1px solid var(--clr-border);
  background: var(--clr-surface);
  cursor: pointer;
  transition: background 0.15s;
  text-decoration: none;
  color: inherit;
  font: inherit;
  display: inline-block;
}

.sbn-leadsheet-view-toggle-link:hover {
  background: var(--clr-surface-2);
}

/* Two-column layout - match library structure */
.sbn-leadsheet-content {
  display: flex;
  gap: 24px;
  align-items: flex-start;
  padding: 0 0 40px;
}

.sbn-leadsheet-main {
  flex: 1;
  min-width: 0;
  order: 0;
  background: var(--clr-surface);
  border: 1px solid var(--clr-border);
  border-radius: var(--radius);
  padding: 0 24px;
  display: flex;
  flex-direction: column;
}

.sbn-ve-section {
  padding: 24px 0;
  margin: 0;
}

.sbn-ve-section:first-child {
  padding-top: 0;
}

/* Remove borders from ChordGridView to match tab viewer layout */
.sbn-ve-grid {
  border: none;
  margin: 0;
  padding: 0;
}

/* Remove padding from chord view section-body to match tab view */
.sbn-ve-grid .sbn-ve-section-body {
  padding: 0;
}

/* Tab viewer chord name hover styling */
.sbn-tab-viewer .sbn-tab-chord-name-wrap {
  cursor: pointer;
}

.sbn-tab-viewer .sbn-tab-chord-name-wrap:hover .sbn-tab-chord-name,
.sbn-tab-chord-name--clickable:hover {
  color: var(--clr-red, #e74c3c) !important;
}

.sbn-leadsheet-sidebar {
  position: sticky;
  top: 80px;
  align-self: flex-start;
  width: 280px;
  min-width: 280px;
  flex-shrink: 0;
  order: 1;
}

/* Transport bar container - sticky within main container */
.sbn-leadsheet-transport {
    position: sticky;
    bottom: 20px;
    z-index: 100;
    margin-top: auto;
    transition: transform 0.3s var(--ease), opacity 0.3s var(--ease);
    pointer-events: auto;
}

/* Hidden state: slide down + fade out */
.sbn-leadsheet-transport.is-hidden {
    transform: translateY(120%);
    opacity: 0;
    pointer-events: none;
}

/* Visible state: slide up + fade in */
.sbn-leadsheet-transport.is-visible {
    transform: translateY(0);
    opacity: 1;
}

/* Hover peek area - invisible strip at bottom that triggers show */
.sbn-leadsheet-transport::before {
    content: '';
    position: absolute;
    bottom: 100%;
    left: 0;
    right: 0;
    height: 40px;
    background: transparent;
}

/* Mobile - match library responsive behavior */
@media (max-width: 1024px) {
  .sbn-leadsheet-content {
    flex-direction: column;
  }

  .sbn-leadsheet-sidebar {
    position: static;
    width: 100%;
    min-width: 100%;
    order: -1;
  }
}

@media (max-width: 768px) {
  .sbn-leadsheet-viewer {
    padding: 24px 16px 60px;
  }

  .sbn-leadsheet-header {
    flex-direction: column;
    gap: 16px;
    padding: 0 0 20px;
  }

  .sbn-leadsheet-content {
    gap: 24px;
    padding: 0 0 20px;
  }

  .sbn-leadsheet-main {
    padding: 20px;
  }

  /* Mobile: transport bar adjustments */
  .sbn-leadsheet-transport {
    bottom: 12px;
  }

  /* Reduce hover peek area on mobile */
  .sbn-leadsheet-transport::before {
    height: 20px;
  }
}
</style>
