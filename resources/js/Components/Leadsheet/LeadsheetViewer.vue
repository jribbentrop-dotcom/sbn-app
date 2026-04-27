<template>
  <div class="sbn-leadsheet-viewer" :class="{ 'is-embedded': embedded }">
    <!-- Header band (hidden when embedded) -->
    <div v-if="!embedded" class="sbn-leadsheet-header">
      <div class="sbn-leadsheet-back-section">
        <a href="/library/songs" class="sbn-back-link"><svg width="16" height="16" viewBox="0 0 16 16" fill="none"><path d="M10 3L3 8l7 5V3z" fill="currentColor"/></svg><span>Back to Library</span></a>
      </div>

      <div class="sbn-leadsheet-controls">
        <!-- Density toggle (Step 9 polishes UI + adds localStorage) -->
        <div class="sbn-leadsheet-density-toggle">
          <button
            :class="{ active: density === 'full' }"
            @click="density = 'full'"
            title="Show diagrams"
          >▦</button>
          <button
            :class="{ active: density === 'compact' }"
            @click="density = 'compact'"
            title="Names only"
          >≡</button>
        </div>

        <!-- Cinema view toggle placeholder (Step 8) -->
        <div class="sbn-leadsheet-view-toggle">
          <button class="is-active" disabled>Classic</button>
          <button disabled title="Cinema view — coming in Phase 10">Cinema</button>
        </div>
      </div>
    </div>

    <!-- Two-column layout -->
    <div class="sbn-leadsheet-content">
      <!-- Main chord grid + transport -->
      <div class="sbn-leadsheet-main">
        <ChordGridView
          v-if="model"
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
        :is-playing="isPlaying"
        :current-beat="currentBeat"
        :total-beats="totalBeats"
        :tempo="tempo"
        :beats-per-measure="beatsPerMeasure"
        view-mode="chords"
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

// Composables
import { useTabModel } from '@/tab-editor/composables/useTabModel.js';
import { useGridSelection } from '@/tab-editor/composables/useGridSelection.js';
import { useChordAudio } from '@/tab-editor/composables/useChordAudio.js';

// Audio engine + adapters (required to load chord events before playback)
import { getAudioEngine } from '@/audio/engine/AudioEngine.js';
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
});

// ── Density state with localStorage persistence (Step 9) ───
const density = ref(localStorage.getItem('sbn.leadsheet.density') || 'full');

watch(density, (newVal) => {
  localStorage.setItem('sbn.leadsheet.density', newVal);
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
const { model, buildModel } = tabModel;

// Build the model immediately. (TabEditor relies on a watcher; we call directly
// since our refs never change after mount.)
buildModel();

// useTabModel doesn't store tempo — set it from the leadsheet so audio + UI
// pick it up. (TabEditor reads tempo from the Alpine bridge and bypasses the
// model for transport, which is why it works there.)
if (model.value) {
  model.value.tempo = props.leadsheet.tempo ?? 120;
}

// ── Selection (read-only — drives EduPanel current-chord) ────────────────────
const gridSelection = useGridSelection(model);

// ── Audio (chord playback only — no tab in classic viewer Phase 9a) ──────────
const chordAudio = useChordAudio(model);
const {
  isPlaying,
  currentBeat,
  play:   chordPlay,
  pause:  chordPause,
  reset:  chordReset,
  seek:   chordSeek,
} = chordAudio;

// Engine event-loading. The audio engine is a singleton shared with the admin
// editor; events must be (re)loaded once per model before play(). Replicates
// TabEditor.loadAllEvents(), but chord-only — no tab events in Phase 9a.
const engine = getAudioEngine();
let _eventsLoaded = false;

async function ensureEventsLoaded() {
  if (!model.value) return;
  if (_eventsLoaded) return;
  await engine.init({ bpm: model.value.tempo ?? props.leadsheet.tempo ?? 120 });
  const events = chordVoicingsToEvents(model.value, { startBeat: 0 });
  engine.load(events);
  engine.setTempo(model.value.tempo ?? props.leadsheet.tempo ?? 120);
  _eventsLoaded = true;
}

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
async function onTransportToggle() {
  if (isPlaying.value) {
    chordPause();
  } else {
    await ensureEventsLoaded();
    await chordPlay();
  }
}


function onTransportSeek(beat) {
  chordSeek(beat);
}

function onTempoChange(newTempo) {
  tempo.value = newTempo;
  if (model.value) model.value.tempo = newTempo;
  engine.setTempo(newTempo);
}

// Click-to-seek from a chord card: seek to measure (only auto-play if already playing)
async function seekToMeasure(gi) {
  const beatStart = gi * beatsPerMeasure.value;
  await ensureEventsLoaded();
  chordSeek(beatStart);
  // If already playing, playback continues from new position
  // If stopped/paused, just seek without starting playback
}

// ── Active-measure highlight (drives the beat-tick sweep across the grid) ────
const playingMeasureIndex = ref(0);
const transportBeat = computed(() => currentBeat.value ?? 0);
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

const currentChord = computed(() => {
  const sel = gridSelection.selection.value;
  if (!sel.length) return null;
  const last = sel[sel.length - 1];
  const found = _findInModel(last.gi);
  if (!found) return null;
  return found.measure.chordNames?.[last.ci] ?? null;
});

const currentSectionId = computed(() => {
  const sel = gridSelection.selection.value;
  if (!sel.length) return null;
  const last = sel[sel.length - 1];
  const found = _findInModel(last.gi);
  return found?.section?.id ?? null;
});

// Selection key for chordCards lookup: "chordName@gi.ci"
const selectionKey = computed(() => {
  const sel = gridSelection.selection.value;
  if (!sel.length) return null;
  const last = sel[sel.length - 1];
  const found = _findInModel(last.gi);
  if (!found) return null;
  const name = found.measure.chordNames?.[last.ci];
  if (!name) return null;
  return `${name}@${last.gi}.${last.ci}`;
});

const songInfo = computed(() => ({
  title:         props.leadsheet.title,
  composer:      props.leadsheet.composer ?? null,
  songKey:       props.leadsheet.songKey ?? null,
  tempo:         props.leadsheet.tempo ?? null,
  timeSignature: props.leadsheet.timeSignature ?? null,
  rhythm:        props.leadsheet.rhythm ?? null,
}));

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
  padding: 24px;
  display: flex;
  flex-direction: column;
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
