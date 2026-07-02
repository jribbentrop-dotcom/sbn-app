<script setup lang="ts">
import { ref, computed, provide, watch, onMounted, onBeforeUnmount } from 'vue';
import { getAudioEngine } from '@/audio/engine/AudioEngine.js';
import { getSharedNylon } from '@/audio/engine/voices/sharedNylon.js';
import { tabModelToEvents } from '@/audio/adapters/tabMeasureToEvents.js';
import { expandModelSequence, firstPositionForGi } from '@/audio/adapters/expandMeasureSequence.js';
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

interface VideoSyncData {
  videoId: string;
  videoType?: 'youtube' | 'hosted';
  mappings: Array<{ measureIndex: number; videoTime: number }>;
}

const props = defineProps<{
  exercise: ExercisePayload;
  onChordSelect?: ((slug: string, root: string, voicingData?: any) => void) | null;
  /**
   * Current playhead in recording-seconds, driven by the shared VideoEmbed in
   * PracticePanel. When non-null AND videoSync is present, the mapping pipeline
   * converts seconds → fractional play-position → beat, matching the full editor.
   */
  videoPlayhead?: number | null;
  /** Full videoSync block from exercise.videoSync — enables mapping interpolation. */
  videoSync?: VideoSyncData | null;
  onVideoPlay?: (() => void) | null;
  onVideoPause?: (() => void) | null;
  onVideoSeek?: ((seconds: number) => void) | null;
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

// Expanded playback sequence (repeat + volta aware) — cached per model build.
const expandedSequence = computed(() => {
  if (!model.value) return [];
  return expandModelSequence(model.value);
});

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
  // Ensure nylon samples are loaded before scheduling note events.
  const nylon = getSharedNylon();
  if (nylon._readyPromise) await nylon._readyPromise;
  const tabEvents = tabModelToEvents(model.value, { startBeat: 0, voice: 'nylon' });
  engine.load(tabEvents);
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
  if (props.videoSync?.videoId) {
    if (isPlaying.value) {
      props.onVideoPause?.();
      isPlaying.value = false;
    } else {
      props.onVideoPlay?.();
      isPlaying.value = true;
    }
    return;
  }
  if (isPlaying.value) stopPlayback();
  else startPlayback();
}

onMounted(() => {
  _unsubs.push(
    engine.on('tick', (beat: number) => {
      if (!isPlaying.value) return;
      if (props.videoPlayhead != null) return; // video clock owns the playhead
      transportBeat.value = beat;
      const pos = Math.floor(beat / beatsPerMeasure.value);
      playingMeasureIndex.value = expandedSequence.value[pos] ?? pos;
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

// ── Video-sync clock ──────────────────────────────────────────────────────────
// Mapping-interpolation pipeline (mirrors useVideoSync.videoTimeToPlayPosition):
// binary-search + linear interpolation over the authored tap-to-mark table,
// then convert fractional play-position → beat → measure index.

function videoTimeToBeat(sec: number): number {
  const vs = props.videoSync;
  if (!vs?.mappings?.length) return 0;

  // Project stored gi-keyed mappings onto play positions (same as useVideoSync).
  const seq = expandedSequence.value;
  const byGi = new Map<number, number[]>();
  for (const m of vs.mappings) {
    if (!byGi.has(m.measureIndex)) byGi.set(m.measureIndex, []);
    byGi.get(m.measureIndex)!.push(m.videoTime);
  }
  for (const arr of byGi.values()) arr.sort((a, b) => a - b);

  const pts: Array<{ pos: number; videoTime: number }> = [];
  const posCountByGi = new Map<number, number>();
  for (let pos = 0; pos < seq.length; pos++) {
    const gi = seq[pos];
    const times = byGi.get(gi);
    if (!times) continue;
    const k = posCountByGi.get(gi) ?? 0;
    posCountByGi.set(gi, k + 1);
    pts.push({ pos, videoTime: times[Math.min(k, times.length - 1)] });
  }
  pts.sort((a, b) => a.videoTime - b.videoTime);

  if (!pts.length) return 0;
  if (sec <= pts[0].videoTime) return pts[0].pos * beatsPerMeasure.value;
  if (sec >= pts[pts.length - 1].videoTime) return pts[pts.length - 1].pos * beatsPerMeasure.value;

  let lo = 0, hi = pts.length - 1;
  while (lo < hi - 1) {
    const mid = (lo + hi) >> 1;
    if (pts[mid].videoTime <= sec) lo = mid; else hi = mid;
  }
  const a = pts[lo], b = pts[hi];
  const t = b.videoTime === a.videoTime ? 0 : (sec - a.videoTime) / (b.videoTime - a.videoTime);
  return (a.pos + t * (b.pos - a.pos)) * beatsPerMeasure.value;
}

watch(
  () => props.videoPlayhead,
  (sec, oldSec) => {
    if (!props.videoSync) return;
    if (sec == null) {
      // Video stopped externally (sidebar pause) — mirror onto button state.
      if (oldSec != null) isPlaying.value = false;
      return;
    }
    isPlaying.value = true;
    const beat = videoTimeToBeat(sec);
    transportBeat.value = beat;
    const pos = Math.floor(beat / beatsPerMeasure.value);
    playingMeasureIndex.value = expandedSequence.value[pos] ?? pos;
  },
);

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

// ── Measure click → seek ──────────────────────────────────────────────────────

function onMeasureClick(gi: number) {
  const seq = expandedSequence.value;
  const playPos = firstPositionForGi(seq, gi);

  if (props.videoSync?.mappings?.length) {
    const mapping = props.videoSync.mappings
      .filter((m: any) => m.measureIndex === gi)
      .sort((a: any, b: any) => a.videoTime - b.videoTime)[0];
    if (mapping) {
      props.onVideoSeek?.(mapping.videoTime);
    }
  } else {
    const beat = playPos * beatsPerMeasure.value;
    transportBeat.value = beat;
    playingMeasureIndex.value = gi;
    engine.seek?.(beat);
  }
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
      stampPickupFlexPcts(row);
      rows.push(row);
      idx += count;
    }
    if (idx < measures.length) {
      const row: any = measures.slice(idx);
      row._intendedCount = row.length;
      stampPickupFlexPcts(row);
      rows.push(row);
    }
    return rows;
  }

  // No lineBreaks stored — break into rows of 4 bars.
  const bpr = 4;
  const rows: any[] = [];
  for (let i = 0; i < measures.length; i += bpr) {
    const row: any = measures.slice(i, i + bpr);
    row._intendedCount = bpr;
    stampPickupFlexPcts(row);
    rows.push(row);
  }
  return rows;
}

function stampPickupFlexPcts(row: any) {
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

    <!-- Play/Pause button — vertically centred beside the notation -->
    <button
      type="button"
      class="sbn-sheet-play"
      :class="{ 'is-playing': isPlaying }"
      :title="isPlaying ? 'Pause' : 'Play'"
      @click.stop="togglePlayback"
    >
      <svg v-if="isPlaying" width="14" height="14" viewBox="0 0 14 14">
        <rect x="2" y="1" width="3.5" height="12" fill="currentColor" />
        <rect x="8.5" y="1" width="3.5" height="12" fill="currentColor" />
      </svg>
      <svg v-else width="14" height="14" viewBox="0 0 14 14">
        <path d="M3 1l10 6-10 6z" fill="currentColor" />
      </svg>
    </button>

    <!-- Measures: one div per lineBreak row, rows stack vertically -->
    <div class="sbn-sheet-measures" v-if="model">
      <template v-for="(section, si) in model.sections" :key="section.id || si">
        <div
          v-for="(row, ri) in tabMeasureRows(section)"
          :key="ri"
          class="sbn-sheet-row"
        >
          <div
            v-for="(measure, li) in row"
            :key="measure.index"
            class="sbn-sheet-measure-wrap"
            @click="onMeasureClick(measure.index)"
          >
            <TabMeasure
              :measure="measure"
              :is-first-of-section="si === 0 && ri === 0 && li === 0"
              :show-clef="si === 0 && ri === 0 && li === 0"
              :time-signature="timeSignatureRef"
              :ticks-per-measure="model.ticksPerMeasure"
              :next-measure="getNextTabMeasure(measure.index)"
              :is-next-first-of-section="isNextTabMeasureFirstOfSection(measure.index)"
              :chord-names="measure.chordNames || []"
              :bars-per-row="Math.max(row._intendedCount, 4)"
              :flex-pct="row._pickupPct != null ? (li === 0 ? row._pickupPct : row._regularPct) : null"
              :read-only="true"
              :allow-chord-click="true"
              :cursor="null"
              :pending-digit="null"
              :selected-events="new Set()"
              @chord-click="onTabChordClick"
            />
          </div>
        </div>
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
  padding: 12px 0 12px 4px;
  background: #ffffff;
  overflow: hidden;
}

/* ── Play/Pause button ── */
.sbn-sheet-play {
  flex-shrink: 0;
  align-self: center;
  margin-top: -22px;
  width: 28px;
  height: 28px;
  border-radius: 50%;
  background: var(--clr-surface-3, #f3f4f6);
  border: 1px solid var(--clr-border, #e5e7eb);
  color: var(--clr-text-muted, #9ca3af);
  cursor: pointer;
  display: grid;
  place-items: center;
  padding: 0;
}

/* ── Measures ── */
.sbn-sheet-measures {
  display: flex;
  flex-direction: column;
  gap: 0;
  min-width: 0;
  overflow-x: auto;
}

.sbn-sheet-row {
  display: flex;
  flex-direction: row;
}


.sbn-sheet-row:not(:first-child) {
  padding-left: 28px;
}

.sbn-sheet-measure-wrap {
  cursor: pointer;
  min-width: 0;
}

.sbn-sheet-empty {
  color: var(--clr-text-muted, #6b7280);
  font-size: 13px;
  padding: 8px;
}
</style>
