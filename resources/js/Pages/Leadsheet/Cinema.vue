<script setup>
import { ref, computed, provide, onMounted, onUnmounted } from 'vue';
import { Head } from '@inertiajs/vue3';

import StageTopBar from '@/Components/Cinema/StageTopBar.vue';
import StageHeroNow from '@/Components/Cinema/StageHeroNow.vue';
import StageTransportDeck from '@/Components/Cinema/StageTransportDeck.vue';
import StageSectionsGrid from '@/Components/Cinema/StageSectionsGrid.vue';
import { useTabModel } from '@/tab-editor/composables/useTabModel.js';
import { useBackingTrack } from '@/composables/useBackingTrack.js';
import {
  expandMeasureSequenceWithPass,
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
const currentBarIndex         = ref(0);
const currentBeat             = ref(0);
const currentPlayPosition     = ref(0);   // floored linear play position
const fractionalPlayPosition  = ref(0);   // raw float — used for smooth scroll
const playing = ref(false);

// Toggle/loop/count/click toggles
const loopOn       = ref(false);
const playbackRate = ref(1);

const RATE_STEPS = [0.5, 0.75, 1, 1.25];

function setRate(rate) {
  playbackRate.value = rate;
  if (hasVideo.value) {
    heroRef.value?.setPlaybackRate(rate);
  } else if (_intervalId) {
    // Restart fallback clock at new tempo
    stopFallbackClock();
    if (playing.value) startFallbackClock();
  }
}

// ── Video sync state ─────────────────────────────────────────────────────
const videoSync = computed(() => jsonData.value.videoSync ?? null);
const videoId   = computed(() => videoSync.value?.videoId ?? '');
const videoType = computed(() => videoSync.value?.videoType ?? 'youtube');
const hasVideo  = computed(() => !!videoId.value);

// ── Backing-track toggle (with/without guitar) ───────────────────────────
// Video stays the sync master and is muted while a backing track is active;
// two audio buffers (backing-only, with-guitar) play in lockstep with it —
// see docs/SBN-Leadsheet-Reference.md §8.2 "Backing-track toggle".
const backingTrackData = computed(() => jsonData.value.backingTrack ?? null);
const hasBackingTrack  = computed(() => !!(backingTrackData.value?.enabled
  && backingTrackData.value?.backingUrl && backingTrackData.value?.guitarUrl));
const backingTrack = useBackingTrack();

// Raw sync mappings: [{ measureIndex, videoTime }] — gi-keyed, may contain
// multiple entries per gi when a repeated bar has per-pass timestamps.
const mappings = computed(() => videoSync.value?.mappings ?? []);

// Expanded play sequence (play position → gi). Drives repeat + volta-aware
// cursor and seeks. Built from flatBars (which now carries repeatStart /
// repeatEnd / volta flags via normalizeMeasure).
const _expanded          = computed(() => expandMeasureSequenceWithPass(flatBars.value));
const expandedSequence   = computed(() => _expanded.value.sequence);
const passAtPosition     = computed(() => _expanded.value.passAtPosition);

// Which volta pass is currently active — 1 on first time through, 2 on repeat.
// Falls back to 1 when there are no voltas / no sequence.
const activeVoltaPass = computed(() =>
  passAtPosition.value[currentPlayPosition.value] ?? 1
);

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
  if (hasBackingTrack.value && playing.value) backingTrack.resyncTo(time);
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

  currentBarIndex.value        = Math.max(0, Math.min(totalBars.value - 1, bar));
  currentBeat.value            = Math.max(0, beat);
  currentPlayPosition.value    = floored;
  fractionalPlayPosition.value = Math.max(0, pos);
}

function onVideoPlayState(playing_) {
  const wasPlaying = playing.value;
  playing.value = playing_;
  if (!hasBackingTrack.value) return;
  // Only PAUSE here. YouTube's BUFFERING state also reports "playing" (see
  // VideoEmbed's onYTStateChange — needed so a looping snippet doesn't read
  // as paused), but the video isn't actually advancing yet during buffering.
  // Starting the backing track on that signal left it running ahead of the
  // video by however long buffering took. video-genuinely-playing (YT state
  // === 1 only) is what starts it instead — see onVideoGenuinelyPlaying.
  if (!playing_ && wasPlaying) {
    backingTrack.pause();
  }
}

function onVideoGenuinelyPlaying() {
  if (!hasBackingTrack.value || backingTrack.playing.value) return;
  // Pass a live getter, not a snapshot — ctx.resume() inside play() can take
  // real time, during which the video keeps advancing. Reading videoTime.value
  // only at start()-time (not when play() was called) keeps the two aligned.
  backingTrack.play(() => videoTime.value);
}

// ── Fallback interval clock (when no video / no mappings) ────────────────
// Ground rule 1: when video is master, this does NOT run.
// Only activates if playing without a synced video.
let _intervalId = null;
const tempo = computed(() => props.leadsheet.tempo ?? 120);

function startFallbackClock() {
  if (_intervalId) return;
  const ms = (60 / ((tempo.value || 120) * (playbackRate.value || 1))) * 1000;
  _intervalId = setInterval(() => {
    if (!playing.value) return;
    // Count in play-position space so repeats/voltas replay correctly; the
    // highlighted bar (gi) is derived from the play position via giAtPosition,
    // never advanced linearly. See SBN-Leadsheet §8.2.
    const seq   = expandedSequence.value;
    const seqLen = seq.length || totalBars.value;
    let beat = currentBeat.value + 1;
    let pos  = currentPlayPosition.value;
    if (beat >= beatsPerMeasure.value) {
      beat = 0;
      pos  = pos + 1;
      if (pos >= seqLen) {
        if (loopOn.value) {
          pos = 0;
        } else {
          pos = seqLen - 1;
          stopFallbackClock();
          playing.value = false;
          return;
        }
      }
    }
    const bar = seq.length ? giAtPosition(seq, pos) : pos;
    currentBeat.value            = beat;
    currentBarIndex.value        = Math.max(0, Math.min(totalBars.value - 1, bar));
    currentPlayPosition.value    = pos;
    fractionalPlayPosition.value = pos + beat / beatsPerMeasure.value;
  }, ms);
}

function stopFallbackClock() {
  if (_intervalId) {
    clearInterval(_intervalId);
    _intervalId = null;
  }
}

function onTransportToggle() {
  console.log('[Cinema] onTransportToggle', { playingBefore: playing.value, hasVideo: hasVideo.value });
  if (hasVideo.value) {
    // Video is master — call play/pause on the actual YT player. The backing
    // track is NOT started here: onVideoPlayState is the single source of
    // truth for "is the video actually playing" (playVideo()/pauseVideo() are
    // fire-and-forget and may not take effect immediately), so it drives the
    // backing-track buffers reactively instead — that also covers play/pause
    // triggered by YouTube's own native iframe controls, not just this button.
    if (playing.value) {
      heroRef.value?.pause();
    } else {
      heroRef.value?.play();
    }
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
    if (t !== null) {
      heroRef.value?.seekTo(t);
      if (hasBackingTrack.value) backingTrack.seekTo(t);
    }
  } else {
    // Keep the play position in sync so the repeat-aware fallback clock resumes
    // from the seeked bar (first pass) rather than a stale position.
    const seq = expandedSequence.value;
    const pos = seq.length ? firstPositionForGi(seq, clampedBar) : clampedBar;
    currentPlayPosition.value = pos >= 0 ? pos : clampedBar;
    fractionalPlayPosition.value = currentPlayPosition.value;
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

onMounted(() => {
  window.addEventListener('keydown', onKeyDown);
  if (hasBackingTrack.value) {
    backingTrack.load(backingTrackData.value.backingUrl, backingTrackData.value.guitarUrl);
  }
});
onUnmounted(() => {
  window.removeEventListener('keydown', onKeyDown);
  stopFallbackClock();
  backingTrack.destroy();
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

// ── Tab model (for cinema tab view) ─────────────────────────────────────────
// Mirrors SheetMiniPlayer setup. Cinema owns the playhead so no audio engine
// here — playingMeasureIndex and transportBeat are fed from currentBarIndex /
// currentBeat directly, driven by video or fallback clock.

const _melodyRef        = computed(() => jsonData.value.melody        ?? null);
const _sectionsRef      = computed(() => jsonData.value.sections      ?? []);
const _timeSignatureRef = computed(() => props.leadsheet.timeSignature ?? '4/4');
const _repeatMarkersRef = computed(() => jsonData.value.repeatMarkers ?? {});
const _voltaEndingsRef  = computed(() => jsonData.value.voltaEndings  ?? {});
const _chordVoicingsRef = computed(() => jsonData.value.chordVoicings ?? {});

const { model: tabModel, buildModel, hasData: tabHasData } = useTabModel(
  _melodyRef, _sectionsRef, _timeSignatureRef,
  _repeatMarkersRef, _voltaEndingsRef, _chordVoicingsRef,
);
buildModel();

// Provide the full contract TabMeasure expects — playhead driven by Cinema clock
provide('model', tabModel);
provide('beatsPerMeasureRef', beatsPerMeasure);
provide('playingMeasureIndex', currentBarIndex);
// Fractional beat within the current bar — from the raw play position, not the
// floored currentBeat. This gives the tab playhead sub-quarter-note resolution
// so 8th (and finer) notes highlight, not just downbeats. The hero's pulse row
// and multi-chord split still use the integer currentBeat.
const transportBeatFractional = computed(() => {
  const frac = fractionalPlayPosition.value - Math.floor(fractionalPlayPosition.value);
  return frac * beatsPerMeasure.value;
});
provide('transportBeat', computed(() => transportBeatFractional.value + currentBarIndex.value * beatsPerMeasure.value));
provide('transportPlaying', playing);
provide('readOnly', true);
provide('seekToMeasure', (gi) => onSeekBar(gi));
provide('gridSelection', { selection: ref([]), handleClick: () => {} });
provide('globalIndexOf', (si, mi) => {
  const sec = tabModel.value?.sections?.[si];
  return sec?.measures?.[mi]?.index ?? mi;
});
provide('inlineRenameTarget', ref(null));
// Editor-only injects — null defaults so TabMeasure guards don't throw
provide('chordPicker',    null);
provide('voicingPicker',  null);
provide('chordClipboard', null);
provide('undo',           null);
provide('noteInput',      null);
provide('tapCursor',      ref(null));
</script>

<template>
  <Head :title="`${leadsheet.title} — Cinema | SBN`" />

  <!-- Full-page stage wrapper -->
  <div class="leadsheet-stage" :data-style="leadsheet.styleSlug || 'bossa-nova'">
    <div class="stage-content">
      <!-- Top bar -->
      <StageTopBar
        :title="leadsheet.title"
        :composer="leadsheet.composer"
        :song-key="leadsheet.songKey ?? ''"
        :time-signature="leadsheet.timeSignature ?? '4/4'"
        :bar-count="totalBars"
        :classic-url="classicUrl"
        :style-slug="leadsheet.styleSlug ?? ''"
      />

      <!-- Hero: video + Now Playing -->
      <StageHeroNow
        ref="heroRef"
        :has-video="hasVideo"
        :video-id="videoId"
        :video-type="videoType"
        :muted="hasBackingTrack"
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
        @video-genuinely-playing="onVideoGenuinelyPlaying"
      />

      <!-- Transport deck -->
      <StageTransportDeck
        :playing="playing"
        :current-bar="currentBarIndex"
        :current-beat="currentBeat"
        :total-bars="totalBars"
        :beats-per-measure="beatsPerMeasure"
        :sections="sections"
        :loop-on="loopOn"
        :playback-rate="playbackRate"
        :rate-steps="RATE_STEPS"
        :has-backing-track="hasBackingTrack"
        :guitar-on="backingTrack.guitarOn.value"
        @toggle="onTransportToggle"
        @prev="onPrev"
        @next="onNext"
        @seek-bar="onSeekBar"
        @toggle-loop="loopOn = !loopOn"
        @set-rate="setRate"
        @toggle-guitar="backingTrack.toggleGuitar()"
      />

      <!-- Sections grid -->
      <StageSectionsGrid
        :sections="sections"
        :current-bar-index="currentFlatBar?.globalIndex ?? currentFlatBar?.index ?? 0"
        :fractional-play-position="fractionalPlayPosition"
        :playing="playing"
        :chord-voicings="chordVoicings"
        :active-volta-pass="activeVoltaPass"
        :tab-model="tabModel"
        :tab-has-data="tabHasData"
        :time-signature="props.leadsheet.timeSignature ?? '4/4'"
        @seek-measure="onSeekMeasure"
      />
    </div>
  </div>
</template>

<style>
/* ── Stage palette — aliases onto the DS token set ── */
.leadsheet-stage {
  /* Accent — brand gradient for filled elements, amber for text/borders */
  --stage-accent:       var(--clr-accent-dim);   /* #e67e22 — softer on white */
  --stage-accent-2:     var(--clr-accent);        /* #f39c12 — lighter variant */
  --stage-accent-rgb:   230,126,34;               /* matches --clr-accent-dim */
  --stage-gradient:     var(--clr-gradient);
  --stage-gradient-hover: var(--clr-gradient-hover);

  /* Surfaces — map directly to DS */
  --stage-bg:   var(--clr-white);
  --stage-bg-1: var(--clr-white);
  --stage-bg-2: var(--clr-surface-2);
  --stage-bg-3: var(--clr-surface-3);

  /* Lines — DS border tokens */
  --stage-line:   var(--clr-border-dim);
  --stage-line-2: var(--clr-border);

  /* Text */
  --stage-text:      var(--clr-text);
  --stage-text-dim:  var(--clr-text-dim);
  --stage-text-mute: var(--clr-text-muted);

  /* Misc */
  --stage-good:        var(--clr-success);
  --stage-primary-ink: var(--clr-white);

  /* Fonts — identical to DS */
  --stage-font-body:  var(--font-body);
  --stage-font-chord: var(--font-chord);
  --stage-font-mono:  var(--font-mono);

  min-height: 100vh;
  background: var(--stage-bg);
  color: var(--stage-text);
  font-family: var(--stage-font-body);
  -webkit-font-smoothing: antialiased;
  overflow-x: hidden;
}

/* ── Per-style accent — recolours the whole stage from the song's style ──
   Every descendant reads --stage-accent / --stage-accent-rgb / --stage-gradient,
   so overriding them here re-tints the hero glow, transport, section tabs,
   play buttons, chord focus rings, and header band in one place.
   RGB triplets mirror the --clr-style-* hex values (rgba() needs channels). */
.leadsheet-stage[data-style="bossa-nova"],
.leadsheet-stage[data-style="bossa"] {
  --stage-accent:     var(--clr-style-bossa);   /* #f39c12 */
  --stage-accent-2:   var(--clr-style-bossa);
  --stage-accent-rgb: 243, 156, 18;
}
.leadsheet-stage[data-style="jazz"] {
  --stage-accent:     var(--clr-style-jazz);    /* #3b82f6 */
  --stage-accent-2:   var(--clr-style-jazz);
  --stage-accent-rgb: 59, 130, 246;
}
.leadsheet-stage[data-style="classical"] {
  --stage-accent:     var(--clr-style-classical); /* #10b981 */
  --stage-accent-2:   var(--clr-style-classical);
  --stage-accent-rgb: 16, 185, 129;
}
.leadsheet-stage[data-style="pop"] {
  --stage-accent:     var(--clr-style-pop);     /* #ec4899 */
  --stage-accent-2:   var(--clr-style-pop);
  --stage-accent-rgb: 236, 72, 153;
}
/* Filled "gradient" elements (play button, primary CTA) — a soft same-hue
   gradient keyed to the active accent, so they follow the style too. */
.leadsheet-stage[data-style] {
  --stage-gradient: linear-gradient(120deg,
    rgb(var(--stage-accent-rgb)),
    color-mix(in srgb, rgb(var(--stage-accent-rgb)) 72%, white));
  --stage-gradient-hover: linear-gradient(120deg,
    color-mix(in srgb, rgb(var(--stage-accent-rgb)) 88%, black),
    rgb(var(--stage-accent-rgb)));
}

.stage-content {
  max-width: 1400px;
  margin: 0 auto;
  padding: 24px 28px 64px;
}

.leadsheet-stage * {
  box-sizing: border-box;
}
</style>
