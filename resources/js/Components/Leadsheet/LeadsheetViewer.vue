<template>
  <div class="sbn-leadsheet-viewer" :class="{ 'is-embedded': embedded }">
    <!-- Header band (hidden when embedded) -->
    <div v-if="!embedded" class="sbn-leadsheet-header">
      <div class="sbn-leadsheet-title-section">
        <h1 class="sbn-leadsheet-title">{{ leadsheet.title }}</h1>
        <div class="sbn-leadsheet-meta">
          <span v-if="leadsheet.composer" class="sbn-leadsheet-composer">{{ leadsheet.composer }}</span>
          <span v-if="leadsheet.songKey" class="sbn-leadsheet-key">Key: {{ leadsheet.songKey }}</span>
          <span v-if="leadsheet.tempo" class="sbn-leadsheet-tempo">{{ leadsheet.tempo }} BPM</span>
          <span v-if="leadsheet.timeSignature" class="sbn-leadsheet-time-sig">{{ leadsheet.timeSignature }}</span>
        </div>
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
      <!-- Main chord grid -->
      <div class="sbn-leadsheet-main">
        <ChordGridView
          v-if="model"
          :sections="model.sections || []"
          :read-only="true"
          :density="density"
        />
      </div>

      <!-- Education panel -->
      <div class="sbn-leadsheet-sidebar">
        <EduPanel
          :current-chord="currentChord"
          :current-section-id="currentSectionId"
          :song="songInfo"
          :progressions="progressions"
        />
      </div>
    </div>

    <!-- Transport bar (sticky) -->
    <div class="sbn-leadsheet-transport">
      <TransportBar
        :is-playing="isPlaying"
        :current-beat="currentBeat"
        :total-beats="totalBeats"
        :tempo="tempo"
        :beats-per-measure="beatsPerMeasure"
        view-mode="chords"
        @toggle="onTransportToggle"
        @stop="onTransportStop"
        @seek="onTransportSeek"
        @tempo-change="onTempoChange"
      />
    </div>
  </div>
</template>

<script setup>
import { ref, computed, provide, watch } from 'vue';

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
  /** When true, suppresses the standalone header band (course-lesson embed). */
  embedded: {
    type: Boolean,
    default: false,
  },
});

// ── Density state (Step 9 will add localStorage persistence + polished UI) ───
const density = ref('full');

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

function onTransportStop() {
  // First press parks at current position via pause; second press returns to 0.
  if (isPlaying.value) {
    chordPause();
  } else {
    chordReset();
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

// Click-to-play from a chord card: seek + play from that measure.
async function seekToMeasure(gi) {
  const beatStart = gi * beatsPerMeasure.value;
  await ensureEventsLoaded();
  chordSeek(beatStart);
  if (!isPlaying.value) {
    await chordPlay();
  }
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
  display: flex;
  flex-direction: column;
  min-height: calc(100vh - 80px);
  background: var(--clr-bg);
}

.sbn-leadsheet-viewer.is-embedded {
  min-height: 0;
  border-radius: var(--radius);
  box-shadow: var(--clr-shadow);
  overflow: hidden;
}

/* Header */
.sbn-leadsheet-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 24px;
  padding: 24px 32px 16px;
  border-bottom: 1px solid var(--clr-border);
  background: var(--clr-surface);
}

.sbn-leadsheet-title {
  margin: 0 0 8px 0;
  font-size: 28px;
  font-weight: 700;
  color: var(--clr-text);
}

.sbn-leadsheet-meta {
  display: flex;
  gap: 12px;
  flex-wrap: wrap;
  font-size: 14px;
  color: var(--clr-text-dim);
}

.sbn-leadsheet-meta span {
  padding: 4px 10px;
  background: var(--clr-surface-2);
  border-radius: var(--radius-sm);
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

/* Two-column layout */
.sbn-leadsheet-content {
  display: flex;
  flex: 1;
  min-height: 0;
}

.sbn-leadsheet-main {
  flex: 1;
  min-width: 0;
  padding: 24px 24px 96px;
  overflow-y: auto;
}

.sbn-leadsheet-sidebar {
  width: 320px;
  flex-shrink: 0;
  border-left: 1px solid var(--clr-border);
  background: var(--clr-surface-2);
  padding: 24px;
  overflow-y: auto;
}

/* Transport bar */
.sbn-leadsheet-transport {
  position: sticky;
  bottom: 0;
  z-index: 10;
  background: var(--clr-surface);
  border-top: 1px solid var(--clr-border);
  padding: 12px 24px;
}

/* Mobile */
@media (max-width: 768px) {
  .sbn-leadsheet-header {
    flex-direction: column;
    gap: 16px;
    padding: 20px 20px 12px;
  }

  .sbn-leadsheet-content {
    flex-direction: column;
  }

  .sbn-leadsheet-main {
    padding: 20px 20px 96px;
  }

  .sbn-leadsheet-sidebar {
    width: 100%;
    border-left: none;
    border-top: 1px solid var(--clr-border);
  }
}
</style>
