<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { Head } from '@inertiajs/vue3';

import StageTopBar from '@/Components/Cinema/StageTopBar.vue';
import StageHeroNow from '@/Components/Cinema/StageHeroNow.vue';
import StageTransportDeck from '@/Components/Cinema/StageTransportDeck.vue';
import StageSectionsGrid from '@/Components/Cinema/StageSectionsGrid.vue';

// No public layout — cinema view is full-page dark, no nav chrome
// defineOptions({ layout: null }) is the default when layout is unset

const props = defineProps({
  leadsheet: { type: Object, required: true },
  classicUrl: { type: String, required: true },
});

// ── Flat bar list from leadsheet JSON data ───────────────────────────────
const jsonData = computed(() => props.leadsheet.jsonData ?? {});
const rawSections = computed(() => jsonData.value.sections ?? []);
const chordVoicings = computed(() => jsonData.value.chordVoicings ?? {});

/**
 * Normalize a raw measure from parsed_data into a shape Cinema can use.
 * Raw: { chords: [{ name, beats, beatInMeasure }] }
 * Out: { chordNames: string[], chords: [...], globalIndex: number, ... }
 */
function normalizeMeasure(m, globalIndex, si, sec) {
  // Support both raw format (chords[].name) and pre-normalized (chordNames[])
  const chordNames = m.chordNames
    ?? (m.chords ?? []).map(c => c.name).filter(Boolean);
  return {
    ...m,
    chordNames,
    globalIndex,
    sectionIndex: si,
    sectionId: sec.id ?? String.fromCharCode(65 + si),
    sectionName: sec.name ?? '',
  };
}

/**
 * Flat list of all measures with globalIndex assigned sequentially.
 */
const flatBars = computed(() => {
  const bars = [];
  rawSections.value.forEach((sec, si) => {
    (sec.measures || []).forEach((m) => {
      bars.push(normalizeMeasure(m, bars.length, si, sec));
    });
  });
  return bars;
});

/**
 * Sections normalized so StageSectionsGrid gets measures with chordNames.
 */
const sections = computed(() => {
  let globalIndex = 0;
  return rawSections.value.map((sec, si) => ({
    ...sec,
    measures: (sec.measures || []).map((m) => {
      const norm = normalizeMeasure(m, globalIndex, si, sec);
      globalIndex++;
      return norm;
    }),
  }));
});

const totalBars = computed(() => flatBars.value.length);

const beatsPerMeasure = computed(() => {
  const ts = props.leadsheet.timeSignature ?? '4/4';
  const n = parseInt(String(ts).split('/')[0], 10);
  return Number.isFinite(n) && n > 0 ? n : 4;
});

// ── Playback state (bar/beat clock driven by video time when synced) ─────
const currentBarIndex = ref(0);
const currentBeat = ref(0);
const playing = ref(false);

// Toggle/loop/count/click toggles
const countOn = ref(false);
const loopOn  = ref(false);
const clickOn = ref(false);

// ── Video sync state ─────────────────────────────────────────────────────
const videoSync = computed(() => jsonData.value.videoSync ?? null);
const videoId   = computed(() => videoSync.value?.videoId ?? '');
const videoType = computed(() => videoSync.value?.videoType ?? 'youtube');
const hasVideo  = computed(() => !!videoId.value);

// Sorted sync mappings: [{ measureIndex, videoTime }]
const mappings = computed(() => {
  const raw = videoSync.value?.mappings ?? [];
  return [...raw].sort((a, b) => a.measureIndex - b.measureIndex);
});

// Current video time (updated by VideoPlayer timeupdate via StageHeroNow)
const videoTime = ref(0);
const videoDuration = ref(0);

/**
 * Convert video time → fractional measure index via interpolation.
 * Returns null if no mappings.
 */
function videoTimeToMeasureIndex(time) {
  const ms = mappings.value;
  if (!ms.length) return null;
  if (time <= ms[0].videoTime) return ms[0].measureIndex;
  if (time >= ms[ms.length - 1].videoTime) return ms[ms.length - 1].measureIndex;
  let lo = 0, hi = ms.length - 1;
  while (lo < hi - 1) {
    const mid = (lo + hi) >> 1;
    if (ms[mid].videoTime <= time) lo = mid;
    else hi = mid;
  }
  const a = ms[lo], b = ms[hi];
  const t = (time - a.videoTime) / (b.videoTime - a.videoTime);
  return a.measureIndex + t * (b.measureIndex - a.measureIndex);
}

function onVideoTimeUpdate(time) {
  videoTime.value = time;
  if (!hasVideo.value || !playing.value) return;

  const mi = videoTimeToMeasureIndex(time);
  if (mi === null) return;

  // Fractional measure index: integer part = bar, fractional part × beatsPerMeasure = beat
  const bar = Math.min(Math.floor(mi), totalBars.value - 1);
  const beat = Math.floor((mi - Math.floor(mi)) * beatsPerMeasure.value);

  currentBarIndex.value = Math.max(0, bar);
  currentBeat.value = Math.max(0, beat);
}

function onVideoPlayState(playing_) {
  playing.value = playing_;
}

// ── Fallback interval clock (when no video / no mappings) ────────────────
// Ground rule 1: when video is master, this does NOT run.
// Only activates if playing without a synced video.
let _intervalId = null;
const tempo = computed(() => props.leadsheet.tempo ?? 120);

function startFallbackClock() {
  if (_intervalId) return;
  const ms = (60 / (tempo.value || 120)) * 1000;
  _intervalId = setInterval(() => {
    if (!playing.value) return;
    let beat = currentBeat.value + 1;
    let bar  = currentBarIndex.value;
    if (beat >= beatsPerMeasure.value) {
      beat = 0;
      bar  = bar + 1;
      if (bar >= totalBars.value) {
        if (loopOn.value) {
          bar = 0;
        } else {
          bar = totalBars.value - 1;
          stopFallbackClock();
          playing.value = false;
          return;
        }
      }
    }
    currentBeat.value = beat;
    currentBarIndex.value = bar;
  }, ms);
}

function stopFallbackClock() {
  if (_intervalId) {
    clearInterval(_intervalId);
    _intervalId = null;
  }
}

function onTransportToggle() {
  if (hasVideo.value && mappings.value.length) {
    // Video is master — VideoPlayer controls play/pause via StageHeroNow
    // The play-state-change event updates `playing`. We just echo the intent.
    // The actual video play/pause is triggered by clicking the VideoPlayer
    // (the iframe handles it natively). We don't need to do anything here for
    // video-synced mode — the VideoPlayer's own controls drive play state.
    playing.value = !playing.value;
  } else {
    // Fallback clock
    playing.value = !playing.value;
    if (playing.value) {
      startFallbackClock();
    } else {
      stopFallbackClock();
    }
  }
}

function onPrev() {
  stopFallbackClock();
  currentBarIndex.value = Math.max(0, currentBarIndex.value - 1);
  currentBeat.value = 0;
  if (playing.value && !hasVideo.value) startFallbackClock();
}

function onNext() {
  stopFallbackClock();
  currentBarIndex.value = Math.min(totalBars.value - 1, currentBarIndex.value + 1);
  currentBeat.value = 0;
  if (playing.value && !hasVideo.value) startFallbackClock();
}

function onSeekBar(bar) {
  stopFallbackClock();
  currentBarIndex.value = Math.max(0, Math.min(totalBars.value - 1, bar));
  currentBeat.value = 0;
  if (playing.value && !hasVideo.value) startFallbackClock();
}

function onSeekMeasure(globalIndex) {
  // globalIndex == array position in flatBars (assigned sequentially)
  onSeekBar(globalIndex);
}

// ── Keyboard shortcuts ────────────────────────────────────────────────────
function onKeyDown(e) {
  if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;
  if (e.code === 'Space') { e.preventDefault(); onTransportToggle(); }
  if (e.code === 'ArrowLeft')  onPrev();
  if (e.code === 'ArrowRight') onNext();
}

onMounted(() => window.addEventListener('keydown', onKeyDown));
onUnmounted(() => {
  window.removeEventListener('keydown', onKeyDown);
  stopFallbackClock();
});

// ── Derived "Now Playing" data ────────────────────────────────────────────
const currentFlatBar = computed(() => flatBars.value[currentBarIndex.value] ?? null);
const nextFlatBar    = computed(() => flatBars.value[currentBarIndex.value + 1] ?? flatBars.value[0] ?? null);

const currentChordName = computed(() => currentFlatBar.value?.chordNames?.[0] ?? '');
const nextChordName = computed(() => {
  const bar = currentFlatBar.value;
  if ((bar?.chordNames?.length ?? 0) > 1) return bar.chordNames[1];
  return nextFlatBar.value?.chordNames?.[0] ?? '';
});

const currentBarNum = computed(() => currentBarIndex.value + 1);
const sectionLabel  = computed(() => currentFlatBar.value?.sectionId ?? '');

// Roman numeral from HarmonicContext if available in jsonData, else '—'
const harmonicContext = computed(() => jsonData.value.harmonicContext ?? null);
const romanNumeral = computed(() => {
  if (!harmonicContext.value) return '—';
  const bar = currentFlatBar.value;
  if (!bar) return '—';
  const mi = bar.globalIndex;
  for (const sec of harmonicContext.value.sections ?? []) {
    const chord = (sec.chords ?? []).find(c => c.measure_index === mi);
    if (chord?.roman_numeral) return chord.roman_numeral;
  }
  return '—';
});

const beatsUntilNext = computed(() => beatsPerMeasure.value - currentBeat.value);

const currentVoicing = computed(() => {
  const name = currentChordName.value;
  if (!name) return null;
  const gi = currentFlatBar.value?.globalIndex ?? 0;
  // Try per-slot key first, then bare chord name
  return chordVoicings.value[`${name}@${gi}.0`] ?? chordVoicings.value[name] ?? null;
});

// Classic view URL
const classicUrl = computed(() => props.classicUrl);
</script>

<template>
  <Head :title="`${leadsheet.title} — Cinema | SBN`" />

  <!-- Full-page dark stage wrapper -->
  <div class="leadsheet-stage">
    <!-- Stage scrim -->
    <div class="stage-scrim" aria-hidden="true"></div>

    <div class="stage-content">
      <!-- Top bar -->
      <StageTopBar
        :title="leadsheet.title"
        :composer="leadsheet.composer"
        :song-key="leadsheet.songKey ?? ''"
        :time-signature="leadsheet.timeSignature ?? '4/4'"
        :bar-count="totalBars"
        :classic-url="classicUrl"
      />

      <!-- Hero: video + Now Playing -->
      <StageHeroNow
        :has-video="hasVideo"
        :video-id="videoId"
        :video-type="videoType"
        :video-time="videoTime"
        :video-duration="videoDuration"
        :current-chord-name="currentChordName"
        :next-chord-name="nextChordName"
        :current-bar-num="currentBarNum"
        :section-label="sectionLabel"
        :roman-numeral="romanNumeral"
        :current-voicing="currentVoicing"
        :beats-until-next="beatsUntilNext"
        :current-beat="currentBeat"
        :beats-per-measure="beatsPerMeasure"
        @video-timeupdate="onVideoTimeUpdate"
        @video-play-state="onVideoPlayState"
      />

      <!-- Transport deck -->
      <StageTransportDeck
        :playing="playing"
        :current-bar="currentBarIndex"
        :current-beat="currentBeat"
        :total-bars="totalBars"
        :beats-per-measure="beatsPerMeasure"
        :sections="sections"
        :count-on="countOn"
        :loop-on="loopOn"
        :click-on="clickOn"
        @toggle="onTransportToggle"
        @prev="onPrev"
        @next="onNext"
        @seek-bar="onSeekBar"
        @toggle-count="countOn = !countOn"
        @toggle-loop="loopOn = !loopOn"
        @toggle-click="clickOn = !clickOn"
      />

      <!-- Sections grid -->
      <StageSectionsGrid
        :sections="sections"
        :current-bar-index="currentFlatBar?.globalIndex ?? currentFlatBar?.index ?? 0"
        :playing="playing"
        :chord-voicings="chordVoicings"
        @seek-measure="onSeekMeasure"
      />
    </div>
  </div>
</template>

<style>
/* ── Stage palette (dark-only, Phase 10) ─────────────────────── */
/* Scoped under .leadsheet-stage so tokens don't leak into the rest of the app */
.leadsheet-stage {
  --stage-bg:          #0a0a0c;
  --stage-bg-1:        #111117;
  --stage-bg-2:        #16161e;
  --stage-bg-3:        #1d1d27;
  --stage-line:        rgba(255,255,255,0.08);
  --stage-line-2:      rgba(255,255,255,0.14);
  --stage-text:        #e8e4dc;
  --stage-text-dim:    #8a8a96;
  --stage-text-mute:   #545460;
  --stage-accent:      #ff7a1a;
  --stage-accent-2:    #ffb347;
  --stage-accent-rgb:  255,122,26;
  --stage-good:        #4ade80;
  --stage-scrim-1:     rgba(255,122,26,0.08);
  --stage-scrim-2:     rgba(100,80,255,0.05);
  --stage-primary-ink: #1a0b00;
  --stage-font-body:   'DM Sans', system-ui, sans-serif;
  --stage-font-chord:  'Crimson Text', Georgia, serif;
  --stage-font-mono:   'JetBrains Mono', monospace;

  min-height: 100vh;
  background: var(--stage-bg);
  color: var(--stage-text);
  font-family: var(--stage-font-body);
  -webkit-font-smoothing: antialiased;
  position: relative;
  overflow-x: hidden;
}

/* Vignette / spotlight scrim */
.stage-scrim {
  position: fixed;
  inset: 0;
  background:
    radial-gradient(ellipse 900px 700px at 50% -10%, rgba(255,122,26,0.08), transparent 60%),
    radial-gradient(ellipse 1200px 800px at 50% 110%, rgba(100,80,255,0.05), transparent 60%);
  pointer-events: none;
  z-index: 0;
}

.stage-content {
  max-width: 1400px;
  margin: 0 auto;
  padding: 24px 28px 64px;
  position: relative;
  z-index: 1;
}

/* Ensure child components inherit the stage font vars */
.leadsheet-stage * {
  box-sizing: border-box;
}
</style>
