<script setup lang="ts">
import { ref, computed, provide, onMounted, onBeforeUnmount } from 'vue';
import { getAudioEngine } from '@/audio/engine/AudioEngine.js';
import { tabModelToEvents } from '@/audio/adapters/tabMeasureToEvents.js';
import { chordVoicingsToEvents } from '@/audio/adapters/chordVoicingsToEvents.js';
import { useTabModel } from '@/tab-editor/composables/useTabModel.js';
import TabMeasure from '@/tab-editor/components/TabMeasure.vue';

// ── Props ────────────────────────────────────────────────────────────────────

interface ExercisePayload {
  meta?: {
    slug?: string;
    title?: string;
    key_center?: string;
    time_sig?: string;
    bpm_default?: number;
    type?: string;
  };
  // LeadsheetJson fields (content_json shape from the leadsheet editor)
  sections?: any[];
  melody?: any;
  timeSignature?: string;
  repeatMarkers?: any;
  voltaEndings?: any;
  chordVoicings?: any;
}

const props = defineProps<{
  exercise: ExercisePayload;
  onChordSelect?: ((slug: string, root: string, voicingData?: any) => void) | null;
}>();

// ── Tab model — mirrors LeadsheetViewer setup exactly ────────────────────────

const melodyRef        = ref(props.exercise?.melody         ?? null);
const sectionsRef      = ref(props.exercise?.sections       ?? []);
const timeSignatureRef = ref(props.exercise?.timeSignature  ?? props.exercise?.meta?.time_sig ?? '4/4');
const repeatMarkersRef = ref(props.exercise?.repeatMarkers  ?? {});
const voltaEndingsRef  = ref(props.exercise?.voltaEndings   ?? {});
const chordVoicingsRef = ref(props.exercise?.chordVoicings  ?? {});

const { model, buildModel } = useTabModel(
  melodyRef, sectionsRef, timeSignatureRef,
  repeatMarkersRef, voltaEndingsRef, chordVoicingsRef,
);
buildModel();

// ── Transport state ───────────────────────────────────────────────────────────

const bpm       = ref<number>(props.exercise?.meta?.bpm_default ?? 100);
const isPlaying = ref(false);
const playingMeasureIndex = ref(0);
const transportBeat = ref(0);

const beatsPerMeasure = computed(() => {
  const tpm = model.value?.ticksPerMeasure;
  if (tpm) return tpm / 480;
  const ts = timeSignatureRef.value;
  const num = parseInt(String(ts).split('/')[0], 10);
  return Number.isFinite(num) && num > 0 ? num : 4;
});

// ── Audio ─────────────────────────────────────────────────────────────────────

const engine = getAudioEngine();
let _eventsLoaded = false;
let _unsubs: Array<() => void> = [];

async function loadAllEvents() {
  if (!model.value) return;
  await engine.init({ bpm: bpm.value, samplesBaseUrl: '/audio/rhythm-samples/' });
  const tabEvents   = tabModelToEvents(model.value, { startBeat: 0 });
  const chordEvents = chordVoicingsToEvents(model.value, { startBeat: 0 });
  const combined    = [...tabEvents, ...chordEvents].sort((a: any, b: any) => a.time - b.time);
  engine.load(combined);
  engine.setTempo(bpm.value);
  _eventsLoaded = true;
}

async function startPlayback() {
  if (!_eventsLoaded) await loadAllEvents();
  await engine.play('sheet');
  isPlaying.value = true;
}

function stopPlayback() {
  engine.stop();
  isPlaying.value = false;
  transportBeat.value = 0;
  playingMeasureIndex.value = 0;
}

function togglePlayback() {
  if (isPlaying.value) stopPlayback();
  else startPlayback();
}

onMounted(() => {
  _unsubs.push(
    engine.on('tick', (beat: number) => {
      if (!isPlaying.value) return;
      transportBeat.value = beat;
      playingMeasureIndex.value = Math.floor(beat / beatsPerMeasure.value);
    }),
    engine.on('ended', () => {
      isPlaying.value = false;
      transportBeat.value = 0;
      playingMeasureIndex.value = 0;
    }),
    engine.on('playStarted', (sourceTag: string | null) => {
      // Another source took over — reset our state
      if (sourceTag !== 'sheet' && isPlaying.value) {
        isPlaying.value = false;
        transportBeat.value = 0;
        playingMeasureIndex.value = 0;
      }
    }),
  );
});

onBeforeUnmount(() => {
  _unsubs.forEach(fn => fn?.());
  _unsubs = [];
  if (isPlaying.value) engine.stop();
});

// ── BPM change ────────────────────────────────────────────────────────────────

function onBpmChange() {
  engine.setTempo(bpm.value);
  _eventsLoaded = false; // force reload on next play so tempo bakes in
}

// ── Chord click → context panel ───────────────────────────────────────────────

function onTabChordClick({ measureIndex, chordIndex, chordName }: { measureIndex: number; chordIndex: number; chordName: string }) {
  if (!props.onChordSelect || !chordName) return;
  const rootMatch = chordName.match(/^([A-G](?:#|b)?)/);
  const root = rootMatch ? rootMatch[1] : (props.exercise?.meta?.key_center ?? 'C');

  // Look up the exact voicing stored in the exercise's chordVoicings map.
  // Keys are "chordName@gi.ci" — gi is the global measure index.
  const voicings: Record<string, any> = props.exercise?.chordVoicings ?? {};
  const key = `${chordName}@${measureIndex}.${chordIndex}`;
  const voicingData = voicings[key] ?? voicings[chordName] ?? null;

  props.onChordSelect(chordName, root, voicingData);
}

// ── Tab layout helpers — copied from LeadsheetViewer ─────────────────────────

function tabMeasureRows(section: any) {
  const lineBreaks = section.lineBreaks;
  const measures = section.measures || [];

  if (lineBreaks?.length) {
    const rows: any[] = [];
    let idx = 0;
    for (const count of lineBreaks) {
      if (idx >= measures.length) break;
      const row: any = measures.slice(idx, idx + count);
      row._intendedCount = count;
      rows.push(row);
      idx += count;
    }
    if (idx < measures.length) {
      const row: any = measures.slice(idx);
      row._intendedCount = row.length;
      rows.push(row);
    }
    return rows;
  }

  // All measures in one row for the strip layout (horizontal scroll)
  const row: any = [...measures];
  row._intendedCount = measures.length;
  return [row];
}

function getNextTabMeasure(index: number) {
  if (!model.value) return null;
  const all = model.value.sections.flatMap((s: any) => s.measures || []);
  const idx = all.findIndex((m: any) => m.index === index);
  return idx >= 0 && idx < all.length - 1 ? all[idx + 1] : null;
}

function isNextTabMeasureFirstOfSection(index: number) {
  if (!model.value) return false;
  const next = getNextTabMeasure(index);
  if (!next) return false;
  for (const section of model.value.sections) {
    if (section.measures?.[0]?.index === next.index) return true;
  }
  return false;
}

// ── Provide (required by TabMeasure / ChordGridView descendants) ──────────────

provide('model', model);
provide('beatsPerMeasureRef', beatsPerMeasure);
provide('playingMeasureIndex', playingMeasureIndex);
provide('transportBeat', transportBeat);
provide('transportPlaying', isPlaying);
provide('readOnly', true);
provide('seekToMeasure', () => {});
provide('gridSelection', { selection: ref([]), handleClick: () => {} });
provide('globalIndexOf', (si: number, mi: number) => {
  const sec = model.value?.sections?.[si];
  return sec?.measures?.[mi]?.index ?? mi;
});
// Suppress TabMeasure's watch(inlineRenameTarget) warning — editor-only, not used in viewer
provide('inlineRenameTarget', ref(null));
</script>

<template>
  <div class="sbn-sheet-player">

    <!-- Play/Pause button -->
    <button
      type="button"
      class="sbn-sheet-play"
      :class="{ 'is-playing': isPlaying }"
      :title="isPlaying ? 'Pause' : 'Play'"
      @click="togglePlayback"
    >
      <svg v-if="isPlaying" width="22" height="22" viewBox="0 0 22 22">
        <rect x="6" y="5" width="4" height="12" fill="white" />
        <rect x="12" y="5" width="4" height="12" fill="white" />
      </svg>
      <svg v-else width="22" height="22" viewBox="0 0 22 22">
        <path d="M7 5l11 6-11 6z" fill="white" />
      </svg>
    </button>

    <!-- Measures: horizontal scroll, one row, TabMeasure for each -->
    <div class="sbn-sheet-measures" v-if="model">
      <template v-for="(section, si) in model.sections" :key="section.id || si">
        <template v-for="(row, ri) in tabMeasureRows(section)" :key="ri">
          <TabMeasure
            v-for="(measure, li) in row"
            :key="measure.index"
            :measure="measure"
            :is-first-of-section="si === 0 && ri === 0 && li === 0"
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
            @chord-click="onTabChordClick"
          />
        </template>
      </template>
    </div>

    <div v-else class="sbn-sheet-empty">Loading…</div>

  </div>
</template>

<style scoped>
/* ── Container ────────────────────────────────────────────────────────────── */
.sbn-sheet-player {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 12px 0;
  background: #ffffff;
  overflow: hidden;
}

/* ── Play/Pause button ──────────────────────────────── */
.sbn-sheet-play {
  width: 36px;
  height: 36px;
  border-radius: 999px;
  border: none;
  background: var(--clr-gradient, linear-gradient(135deg, #f39c12 0%, #e74c3c 100%));
  color: #ffffff;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  transition: transform 0.15s ease;
}

.sbn-sheet-play:hover {
  transform: scale(1.05);
}

.sbn-sheet-play svg {
  width: 18px;
  height: 18px;
}

/* ── Measures ──────────────────────────────────────────────────────────────── */
.sbn-sheet-measures {
  display: flex;
  flex-direction: row;
  gap: 0;
  overflow-x: auto;
  flex: 1 1 auto;
  min-width: 0;
}

.sbn-sheet-empty {
  color: var(--clr-text-muted, #6b7280);
  font-size: 13px;
  padding: 8px;
}
</style>
