<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { Head } from '@inertiajs/vue3';

import StageTopBar from '@/Components/Cinema/StageTopBar.vue';
import StageHeroNow from '@/Components/Cinema/StageHeroNow.vue';
import StageTransportDeck from '@/Components/Cinema/StageTransportDeck.vue';
import StageSectionsGrid from '@/Components/Cinema/StageSectionsGrid.vue';
import {
  expandMeasureSequence,
  giAtPosition,
  firstPositionForGi,
} from '@/audio/adapters/expandMeasureSequence.js';

// No public layout — cinema view is full-page dark, no nav chrome
// defineOptions({ layout: null }) is the default when layout is unset

const props = defineProps({
  leadsheet:  { type: Object, required: true },
  classicUrl: { type: String, required: true },
  chordCards: { type: Object, default: () => ({}) },
});

// ── Theme (light / dark) ─────────────────────────────────────────────────
const THEME_KEY = 'cinema-theme';
const theme = ref('dark');
try { theme.value = localStorage.getItem(THEME_KEY) ?? 'dark'; } catch {}
function toggleTheme() {
  theme.value = theme.value === 'dark' ? 'light' : 'dark';
  try { localStorage.setItem(THEME_KEY, theme.value); } catch {}
}

// ── Flat bar list from leadsheet JSON data ───────────────────────────────
const jsonData = computed(() => props.leadsheet.jsonData ?? {});
const rawSections = computed(() => jsonData.value.sections ?? []);
const chordVoicings = computed(() => jsonData.value.chordVoicings ?? {});

/**
 * Build a flat volta flag map (gi → { volta, voltaStart, voltaEnd }) from
 * the top-level voltaEndings object, matching useTabModel's populate pass.
 * voltaEndings shape: { "5": { type: "start"|"stop", number, text? }, ... }
 */
const voltaFlagsByGi = computed(() => {
  const ve = jsonData.value.voltaEndings ?? {};
  const result = {};
  if (!Object.keys(ve).length) return result;

  // Count total measures
  let total = 0;
  rawSections.value.forEach(sec => { total += (sec.measures ?? []).length; });

  let activeVolta = null;
  for (let i = 0; i < total; i++) {
    const entry = ve[i.toString()];
    if (entry?.type === 'start') {
      activeVolta = { number: entry.number, text: entry.text || `${entry.number}.` };
      result[i] = { volta: { ...activeVolta }, voltaStart: true, voltaEnd: false };
    } else if (activeVolta) {
      result[i] = { volta: { ...activeVolta }, voltaStart: false, voltaEnd: false };
    }
    if (entry?.type === 'stop' && result[i]) {
      result[i].voltaEnd = true;
      activeVolta = null;
    }
  }
  // Auto-close unclosed bracket on last bar carrying it
  if (activeVolta !== null) {
    for (let i = total - 1; i >= 0; i--) {
      if (result[i]?.volta) { result[i].voltaEnd = true; break; }
    }
  }
  return result;
});

/**
 * repeatMarkers: { "gi": { start: bool, end: bool } } — top-level json_data
 * field, populated by the tab editor. Cinema uses these to expand the play
 * sequence so the video-time → bar interpolation jumps back on repeats.
 */
const repeatMarkersByGi = computed(() => jsonData.value.repeatMarkers ?? {});

/**
 * Normalize a raw measure from parsed_data into a shape Cinema can use.
 * Raw: { chords: [{ name, beats, beatInMeasure }] }
 * Out: { chordNames: string[], chords: [...], globalIndex: number, volta + repeat flags }
 */
function normalizeMeasure(m, globalIndex, si, sec) {
  // Support both raw format (chords[].name) and pre-normalized (chordNames[])
  const chordNames = m.chordNames
    ?? (m.chords ?? []).map(c => c.name).filter(Boolean);
  const voltaFlags = voltaFlagsByGi.value[globalIndex] ?? {};
  const rmEntry    = repeatMarkersByGi.value[String(globalIndex)] ?? repeatMarkersByGi.value[globalIndex];
  return {
    ...m,
    chordNames,
    globalIndex,
    sectionIndex: si,
    sectionId: sec.id ?? String.fromCharCode(65 + si),
    sectionName: sec.name ?? '',
    volta:      voltaFlags.volta      ?? m.volta      ?? null,
    voltaStart: voltaFlags.voltaStart ?? m.voltaStart ?? false,
    voltaEnd:   voltaFlags.voltaEnd   ?? m.voltaEnd   ?? false,
    repeatStart: !!(rmEntry?.start ?? m.repeatStart),
    repeatEnd:   !!(rmEntry?.end   ?? m.repeatEnd),
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

// ── Ref to StageHeroNow (exposes play/pause/seekTo on the VideoPlayer) ───
const heroRef = ref(null);

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

// Raw sync mappings: [{ measureIndex, videoTime }] — gi-keyed, may contain
// multiple entries per gi when a repeated bar has per-pass timestamps.
const mappings = computed(() => videoSync.value?.mappings ?? []);

// Expanded play sequence (play position → gi). Drives repeat + volta-aware
// cursor and seeks. Built from flatBars (which now carries repeatStart /
// repeatEnd / volta flags via normalizeMeasure).
const expandedSequence = computed(() => expandMeasureSequence(flatBars.value));

// Mappings projected onto play positions. For each gi we sort that gi's marks
// by videoTime ascending and pair them with that gi's successive play
// positions in the expanded sequence — so the AABA author who taps once for
// A1 and once for A2 ends up with one mark at pos 0 and one at pos 8.
const mappingsByPosition = computed(() => {
  const seq = expandedSequence.value;
  if (!seq.length) {
    return [...mappings.value]
      .map(m => ({ pos: m.measureIndex, videoTime: m.videoTime }))
      .sort((a, b) => a.pos - b.pos || a.videoTime - b.videoTime);
  }

  const byGi = new Map();
  for (const m of mappings.value) {
    if (!byGi.has(m.measureIndex)) byGi.set(m.measureIndex, []);
    byGi.get(m.measureIndex).push(m);
  }
  for (const arr of byGi.values()) arr.sort((a, b) => a.videoTime - b.videoTime);

  const positionsByGi = new Map();
  seq.forEach((gi, pos) => {
    if (!positionsByGi.has(gi)) positionsByGi.set(gi, []);
    positionsByGi.get(gi).push(pos);
  });

  const out = [];
  for (const [gi, marks] of byGi.entries()) {
    const positions = positionsByGi.get(gi) ?? [firstPositionForGi(seq, gi)];
    for (let k = 0; k < marks.length; k++) {
      const pos = positions[Math.min(k, positions.length - 1)];
      out.push({ pos, videoTime: marks[k].videoTime });
    }
  }
  return out.sort((a, b) => a.pos - b.pos || a.videoTime - b.videoTime);
});

// Current video time (updated by VideoPlayer timeupdate via StageHeroNow).
const videoTime = ref(0);

/**
 * video time → fractional **play position**. The cursor uses this to know
 * which entry in the expanded sequence we're sitting on; the bar is derived
 * by giAtPosition() on the parent side.
 * Returns null if no mappings.
 */
function videoTimeToPlayPosition(time) {
  const ms = mappingsByPosition.value;
  if (!ms.length) return null;
  if (time <= ms[0].videoTime) return ms[0].pos;
  if (time >= ms[ms.length - 1].videoTime) return ms[ms.length - 1].pos;
  let lo = 0, hi = ms.length - 1;
  while (lo < hi - 1) {
    const mid = (lo + hi) >> 1;
    if (ms[mid].videoTime <= time) lo = mid;
    else hi = mid;
  }
  const a = ms[lo], b = ms[hi];
  const t = b.videoTime === a.videoTime ? 0 : (time - a.videoTime) / (b.videoTime - a.videoTime);
  return a.pos + t * (b.pos - a.pos);
}

function onVideoTimeUpdate(time) {
  videoTime.value = time;
  if (!hasVideo.value || !playing.value) return;

  const pos = videoTimeToPlayPosition(time);
  if (pos === null) return;

  // Translate the play position back to a gi for the score-side cursor.
  // Repeated bars (e.g. AABA pass 2) correctly resolve to the same gi as
  // their pass-1 counterparts because seq[pos] always returns the score's
  // bar index, regardless of which pass produced this video time.
  const seq = expandedSequence.value;
  const floored = Math.max(0, Math.min(seq.length ? seq.length - 1 : Math.floor(pos), Math.floor(pos)));
  const bar = seq.length ? giAtPosition(seq, floored) : floored;
  const fraction = pos - Math.floor(pos);
  const beat = Math.floor(fraction * beatsPerMeasure.value);

  currentBarIndex.value = Math.max(0, Math.min(totalBars.value - 1, bar));
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
  if (hasVideo.value) {
    // Video is master — call play/pause on the actual YT player
    if (playing.value) {
      heroRef.value?.pause();
    } else {
      heroRef.value?.play();
    }
    // playing state will update reactively via onVideoPlayState
  } else {
    playing.value = !playing.value;
    if (playing.value) {
      startFallbackClock();
    } else {
      stopFallbackClock();
    }
  }
}

function onPrev() {
  onSeekBar(currentBarIndex.value - 1);
}

function onNext() {
  onSeekBar(currentBarIndex.value + 1);
}

/**
 * Linear interpolation in pos-space → video time. Used by bar-click seek.
 */
function playPositionToVideoTime(pos) {
  const ms = mappingsByPosition.value;
  if (!ms.length) return null;
  if (pos <= ms[0].pos) return ms[0].videoTime;
  if (pos >= ms[ms.length - 1].pos) return ms[ms.length - 1].videoTime;
  for (let i = 0; i < ms.length - 1; i++) {
    const a = ms[i], b = ms[i + 1];
    if (pos >= a.pos && pos <= b.pos) {
      const t = b.pos === a.pos ? 0 : (pos - a.pos) / (b.pos - a.pos);
      return a.videoTime + t * (b.videoTime - a.videoTime);
    }
  }
  return null;
}

/**
 * gi → video time. Clicking a bar that occurs at multiple play positions
 * (a repeated bar) seeks to the FIRST pass — the start of its phrase.
 * Mirrors the tab editor's seekToMeasure semantics.
 */
function measureIndexToVideoTime(measureIndex) {
  const seq = expandedSequence.value;
  return playPositionToVideoTime(firstPositionForGi(seq, measureIndex));
}

function onSeekBar(bar) {
  const clampedBar = Math.max(0, Math.min(totalBars.value - 1, bar));
  currentBarIndex.value = clampedBar;
  currentBeat.value = 0;
  if (hasVideo.value) {
    const t = measureIndexToVideoTime(clampedBar);
    if (t !== null) heroRef.value?.seekTo(t);
  } else {
    stopFallbackClock();
    if (playing.value) startFallbackClock();
  }
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

// Which chord index within the current bar is active, based on beat position
const currentChordIndex = computed(() => {
  const bar = currentFlatBar.value;
  if (!bar) return 0;
  const names = bar.chordNames ?? [];
  const count = names.length;
  if (count <= 1) return 0;
  // Each chord occupies an equal slice of the measure
  const beatsPerChord = beatsPerMeasure.value / count;
  return Math.min(count - 1, Math.floor(currentBeat.value / beatsPerChord));
});

const currentChordName = computed(() => {
  const bar = currentFlatBar.value;
  return bar?.chordNames?.[currentChordIndex.value] ?? '';
});

const nextChordName = computed(() => {
  const bar = currentFlatBar.value;
  const names = bar?.chordNames ?? [];
  // If there's a next chord within this bar, use it; otherwise first chord of next bar
  if (currentChordIndex.value < names.length - 1) return names[currentChordIndex.value + 1];
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


const currentChordCard = computed(() => {
  const name = currentChordName.value;
  if (!name) return null;
  const gi = currentFlatBar.value?.globalIndex ?? 0;
  const ci = currentChordIndex.value;
  return props.chordCards[`${name}@${gi}.${ci}`] ?? props.chordCards[name] ?? null;
});

// Classic view URL
const classicUrl = computed(() => props.classicUrl);
</script>

<template>
  <Head :title="`${leadsheet.title} — Cinema | SBN`" />

  <!-- Full-page dark stage wrapper -->
  <div class="leadsheet-stage" :data-theme="theme">
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
        :theme="theme"
        @toggle-theme="toggleTheme"
      />

      <!-- Hero: video + Now Playing -->
      <StageHeroNow
        ref="heroRef"
        :has-video="hasVideo"
        :video-id="videoId"
        :video-type="videoType"
        :current-chord-name="currentChordName"
        :current-chord-card="currentChordCard"
        :next-chord-name="nextChordName"
        :current-bar-num="currentBarNum"
        :section-label="sectionLabel"
        :roman-numeral="romanNumeral"
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
/* ── Stage palette — scoped so tokens don't leak into the rest of the app */
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

  /* ── Dark (default) ── */
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

/* ── Light theme overrides ── */
.leadsheet-stage[data-theme="light"] .chord-svg-neon {
  --neon-grid:        rgba(0,0,0,0.25);
  --neon-grid-strong: rgba(0,0,0,0.6);
  --neon-txt:         rgba(0,0,0,0.7);
}

.leadsheet-stage[data-theme="light"] {
  --stage-bg:          #ffffff;
  --stage-bg-1:        #ffffff;
  --stage-bg-2:        #f8f8f8;
  --stage-bg-3:        #f0f0f0;
  --stage-line:        rgba(30,20,12,0.1);
  --stage-line-2:      rgba(30,20,12,0.18);
  --stage-text:        #1c150e;
  --stage-text-dim:    #6b5d4d;
  --stage-text-mute:   #a89782;
  --stage-accent:      #d64a0c;
  --stage-accent-2:    #b83808;
  --stage-accent-rgb:  214,74,12;
  --stage-good:        #15803d;
  --stage-scrim-1:     rgba(214,74,12,0.06);
  --stage-scrim-2:     rgba(100,80,255,0.03);
  --stage-primary-ink: #ffffff;
}

/* Vignette / spotlight scrim */
.stage-scrim {
  position: fixed;
  inset: 0;
  background:
    radial-gradient(ellipse 900px 700px at 50% -10%, var(--stage-scrim-1), transparent 60%),
    radial-gradient(ellipse 1200px 800px at 50% 110%, var(--stage-scrim-2), transparent 60%);
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
