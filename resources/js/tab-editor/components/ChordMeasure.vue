<template>
  <div
    ref="measureEl"
    class="sbn-ve-measure"
    :class="[measureClasses, { 'is-dragging': !!drag || !!resize || !!resizeLeft }]"
    :data-si="sectionIndex"
    :data-mi="measureIndex"
    :data-gi="globalIdx"
  >
    <div
      v-if="volta"
      class="sbn-ve-volta"
      :class="{
        'sbn-ve-volta--start': hasVoltaStart,
        'sbn-ve-volta--end':   hasVoltaEnd,
      }"
    >
      <span v-if="hasVoltaStart" class="sbn-ve-volta-label">{{ volta.text || volta.number + '.' }}</span>
    </div>
    <div class="sbn-ve-measure-num">{{ globalIdx + 1 }}</div>
    <div class="sbn-ve-tab-badge" v-if="measure._fromTab">TAB</div>
    <!-- Repeat start: thick bar + thin bar stretch full height; dots are in a separate non-stretched SVG -->
    <span v-if="hasRepStart" class="sbn-ve-rep-svg sbn-ve-rep-svg--start" aria-hidden="true">
      <svg class="sbn-ve-rep-bars" viewBox="0 0 12 100" preserveAspectRatio="none">
        <rect x="0" y="0" width="3" height="100" fill="currentColor"/>
        <rect x="5" y="0" width="1" height="100" fill="currentColor"/>
      </svg>
      <svg class="sbn-ve-rep-dots" viewBox="0 0 10 30" preserveAspectRatio="xMidYMid meet">
        <circle cx="5" cy="5"  r="2.4" fill="currentColor"/>
        <circle cx="5" cy="25" r="2.4" fill="currentColor"/>
      </svg>
    </span>
    <!-- Repeat end: two dots + thin bar + thick bar right -->
    <span v-if="hasRepEnd" class="sbn-ve-rep-svg sbn-ve-rep-svg--end" aria-hidden="true">
      <svg class="sbn-ve-rep-dots" viewBox="0 0 10 30" preserveAspectRatio="xMidYMid meet">
        <circle cx="5" cy="5"  r="2.4" fill="currentColor"/>
        <circle cx="5" cy="25" r="2.4" fill="currentColor"/>
      </svg>
      <svg class="sbn-ve-rep-bars" viewBox="0 0 12 100" preserveAspectRatio="none">
        <rect x="6" y="0" width="1" height="100" fill="currentColor"/>
        <rect x="9" y="0" width="3" height="100" fill="currentColor"/>
      </svg>
    </span>

    <SyncPointBadge v-if="syncPoint" :marker-index="syncPoint.markerIndex" :video-time="syncPoint.videoTime" :measure-index="globalIdx" :marks="syncPoint.marks" />

    <div class="sbn-ve-measure-content">
      <!-- Beat-grid tick marks — one per quarter-note beat across the measure -->
      <div class="sbn-ve-beat-grid">
        <div
          v-for="b in beatsPerMeasure"
          :key="b"
          class="sbn-ve-beat-tick"
          :class="{ 'beat-one': b === 1, 'beat-active': activeBeat === b }"
          :style="{ left: ((b - 0.5) / beatsPerMeasure * 100) + '%' }"
        ></div>
      </div>

      <!-- Real chord cards — absolutely positioned by beat offset -->
      <ChordCard
        v-for="(name, chordIndex) in chordNamesArray"
        :key="chordIndex"
        :chord="{ name, beats: chordBeats(chordIndex) }"
        :section-index="sectionIndex"
        :measure-index="globalIdx"
        :chord-index="chordIndex"
        :total-chords="chordNamesArray.length"
        :chord-offset="measure.chordOffsets?.[chordIndex]"
        :chord-duration="measure.chordBeats?.[chordIndex]"
        :is-being-dragged="drag?.ci === chordIndex || resize?.ci === chordIndex"
        :style="(drag?.ci === chordIndex || resize?.ci === chordIndex) ? { opacity: '0.35', ...chordPositionStyle(chordIndex) } : chordPositionStyle(chordIndex)"
        @contextmenu="onCardContextMenu"
        @chord-drag-start="onChordDragStart(chordIndex, $event)"
        @chord-resize-start="onChordResizeStart(chordIndex, $event)"
        @chord-resize-start-left="onChordResizeStartLeft(chordIndex, $event)"
      />

      <!-- Drag ghost -->
      <div v-if="drag" class="sbn-ve-chord-ghost" :style="ghostStyle">
        <span class="sbn-ve-chord-ghost-name" v-html="ghostName"></span>
      </div>

      <!-- Resize ghost (right edge) -->
      <div v-if="resize" class="sbn-ve-chord-ghost sbn-ve-chord-ghost--resize" :style="resizeGhostStyle">
        <span class="sbn-ve-chord-ghost-name" v-html="resizeGhostName"></span>
      </div>

      <!-- Resize ghost (left edge) -->
      <div v-if="resizeLeft" class="sbn-ve-chord-ghost sbn-ve-chord-ghost--resize" :style="resizeLeftGhostStyle">
        <span class="sbn-ve-chord-ghost-name" v-html="resizeLeftGhostName"></span>
      </div>
      <!-- Ghost slot — shown when bar is empty so it stays clickable/right-clickable -->
      <ChordCard
        v-if="chordNamesArray.length === 0"
        :chord="{ name: '', beats: 1 }"
        :section-index="sectionIndex"
        :measure-index="globalIdx"
        :chord-index="0"
        :total-chords="0"
        :style="{ position: 'absolute', left: '0', width: '100%' }"
        @contextmenu="onCardContextMenu"
      />
    </div>
  </div>
</template>

<script setup>
import { inject, computed, ref } from 'vue';
import SyncPointBadge from './SyncPointBadge.vue';
import ChordCard from './ChordCard.vue';

const props = defineProps({
  measure: {
    type: Object,
    required: true,
  },
  sectionIndex: {
    type: Number,
    required: true,
  },
  measureIndex: {
    // This is the LOCAL measure index within the section (for data attrs / Alpine compat).
    // The global index is computed from globalIndexOf below.
    type: Number,
    required: true,
  },
  readOnly: {
    type: Boolean,
    default: false,
  },
  density: {
    type: String,
    default: 'full',
    validator: (value) => ['full', 'compact'].includes(value),
  },
});

const emit = defineEmits(['contextmenu']);

// ── Injected from TabEditor ───────────────────────────────────────────────────

const globalIndexOf       = inject('globalIndexOf', null);
const playingMeasureIndex = inject('playingMeasureIndex', null);
const transportBeat       = inject('transportBeat', null);
const tapCursor           = inject('tapCursor', null);
const videoSyncMap        = inject('videoSyncMap', null);
const chordGridOps        = inject('chordGridOps', null);

// videoSyncMap.value is Map<gi, Array<{ videoTime, pass, pos, mappingIdx }>>.
// The badge takes the FIRST mark (pass 1) as its representative and an array
// of all marks so it can show a "·N" count for repeated bars.
const syncMarks = computed(() => videoSyncMap?.value?.get(globalIdx.value) ?? null);
const syncPoint = computed(() => {
    const marks = syncMarks.value;
    if (!marks?.length) return null;
    return { markerIndex: marks[0].mappingIdx, videoTime: marks[0].videoTime, marks };
});

// ── Derived ───────────────────────────────────────────────────────────────────

// The model stores measure.index as the global index.
// globalIndexOf is the canonical source; fall back to measure.index.
const globalIdx = computed(() =>
  globalIndexOf
    ? globalIndexOf(props.sectionIndex, props.measureIndex)
    : props.measure.index ?? props.measureIndex
);

// Normalise chord names: measure.chordNames is the canonical array (string[])
const chordNamesArray = computed(() => {
  const names = props.measure.chordNames || props.measure.chords || [];
  return Array.isArray(names)
    ? names.map(c => (typeof c === 'string' ? c : (c?.name || c?.chordName || '')))
    : [];
});

// Compute quarter-note beats per chord slot: evenly divide the measure.
// beatsPerMeasureRef is injected from TabEditor (provides 'beatsPerMeasureRef').
const beatsPerMeasureRef = inject('beatsPerMeasureRef', null);

function chordBeats(ci) {
  const total = chordNamesArray.value.length || 1;
  const bpm   = beatsPerMeasureRef?.value ?? 4;
  // If the model has explicit per-chord beat data, honour it; otherwise divide evenly.
  return props.measure.chordBeats?.[ci] ?? (bpm / total);
}

// Which beat (1-based) is currently playing in this measure, or 0 if none.
const activeBeat = computed(() => {
  if (playingMeasureIndex?.value !== globalIdx.value) return 0;
  const bpm  = beatsPerMeasureRef?.value ?? 4;
  const beat = transportBeat?.value ?? 0;
  // beatInMeasure is 0-based; floor gives us the current quarter-beat slot (0..bpm-1)
  return Math.floor(((beat % bpm) + bpm) % bpm) + 1; // 1-based
});

// Total beats in the measure — the denominator for the beat grid.
const beatsPerMeasure = computed(() => beatsPerMeasureRef?.value ?? 4);

// Absolute position styles for all chord cards — computed so Vue tracks
// chordOffsets/chordBeats reactively and re-renders on drag commit.
const chordPositionStyles = computed(() => {
  const total = chordNamesArray.value.length || 1;
  const bpm   = beatsPerMeasure.value;
  return chordNamesArray.value.map((_, ci) => {
    const offset = props.measure.chordOffsets?.[ci] ?? (ci * (bpm / total));
    const dur    = props.measure.chordBeats?.[ci]   ?? (bpm / total);
    return {
      left:  (offset / bpm * 100) + '%',
      width: (dur    / bpm * 100) + '%',
    };
  });
});

function chordPositionStyle(ci) {
  return chordPositionStyles.value[ci] ?? { left: '0%', width: '100%' };
}

// Repeat signs and volta are serialized directly onto the measure object.
const volta        = computed(() => props.measure.volta);
const hasVoltaStart = computed(() => props.measure.voltaStart);
const hasVoltaEnd   = computed(() => props.measure.voltaEnd);
const hasRepStart   = computed(() => props.measure.repeatStart);
const hasRepEnd     = computed(() => props.measure.repeatEnd);

const measureClasses = computed(() => ({
  'has-volta':      !!volta.value,
  'rep-start-bar':  hasRepStart.value,
  'rep-end-bar':    hasRepEnd.value,
  'is-empty':       chordNamesArray.value.length === 0,
  'is-tap-target':  tapCursor?.value === globalIdx.value,  // D2: pulse when this measure is tap cursor
  'is-compact':     props.density === 'compact',
  'is-full':        props.density === 'full',
}));

// ── Ghost overlay computeds ───────────────────────────────────────────────────

const ghostStyle = computed(() => {
    if (!drag.value) return {};
    const bpm    = beatsPerMeasure.value;
    const offset = drag.value.currentOffset;
    const ci     = drag.value.ci;
    const dur    = props.measure.chordBeats?.[ci] ?? (bpm / (chordNamesArray.value.length || 1));
    // Clamp end to measure boundary
    const clampedDur = Math.min(dur, bpm - offset);
    return {
        left:  (offset / bpm * 100) + '%',
        width: (clampedDur / bpm * 100) + '%',
    };
});

const ghostName = computed(() => {
    if (!drag.value) return '';
    const name = chordNamesArray.value[drag.value.ci] || '';
    if (typeof window !== 'undefined' && typeof window.sbnFormatChord === 'function') {
        return window.sbnFormatChord(name);
    }
    return name;
});

// ── Chord resize ─────────────────────────────────────────────────────────────

const resizeGhostStyle = computed(() => {
    if (!resize.value) return {};
    const bpm    = beatsPerMeasure.value;
    const ci     = resize.value.ci;
    const start  = props.measure.chordOffsets?.[ci] ?? (ci * (bpm / (chordNamesArray.value.length || 1)));
    const end    = resize.value.currentEnd;
    const dur    = Math.max(0.5, end - start);
    return {
        left:  (start / bpm * 100) + '%',
        width: (dur   / bpm * 100) + '%',
    };
});

const resizeGhostName = computed(() => {
    if (!resize.value) return '';
    const name = chordNamesArray.value[resize.value.ci] || '';
    if (typeof window !== 'undefined' && typeof window.sbnFormatChord === 'function') {
        return window.sbnFormatChord(name);
    }
    return name;
});

function onChordResizeStart(ci, pointerEvent) {
    if (props.readOnly || !chordGridOps) return;
    pointerEvent.preventDefault();
    pointerEvent.stopPropagation();

    const rect = measureEl.value?.getBoundingClientRect();
    if (!rect) return;

    const bpm = beatsPerMeasure.value;
    const start = props.measure.chordOffsets?.[ci] ?? (ci * (bpm / (chordNamesArray.value.length || 1)));

    resize.value = {
        ci,
        measureRect: rect,
        currentEnd: start + (props.measure.chordBeats?.[ci] ?? (bpm / (chordNamesArray.value.length || 1))),
    };

    window.addEventListener('pointermove', onResizeMove);
    window.addEventListener('pointerup',   onResizeEnd);
}

function onResizeMove(e) {
    if (!resize.value) return;
    const { measureRect } = resize.value;
    const bpm = beatsPerMeasure.value;
    const rawPct  = (e.clientX - measureRect.left) / measureRect.width;
    const rawBeat = rawPct * bpm;
    const snapped = Math.round(rawBeat / 0.5) * 0.5;
    resize.value = { ...resize.value, currentEnd: Math.max(0.5, Math.min(snapped, bpm)) };
}

function onResizeEnd() {
    window.removeEventListener('pointermove', onResizeMove);
    window.removeEventListener('pointerup',   onResizeEnd);
    if (!resize.value) return;
    const { ci, currentEnd } = resize.value;
    resize.value = null;
    chordGridOps.setChordEnd(globalIdx.value, ci, currentEnd);
}

// ── Chord left-resize ────────────────────────────────────────────────────────

const resizeLeftGhostStyle = computed(() => {
    if (!resizeLeft.value) return {};
    const bpm   = beatsPerMeasure.value;
    const start = resizeLeft.value.currentStart;
    const end   = resizeLeft.value.fixedEnd;
    const dur   = Math.max(0.5, end - start);
    return {
        left:  (start / bpm * 100) + '%',
        width: (dur   / bpm * 100) + '%',
    };
});

const resizeLeftGhostName = computed(() => {
    if (!resizeLeft.value) return '';
    const name = chordNamesArray.value[resizeLeft.value.ci] || '';
    if (typeof window !== 'undefined' && typeof window.sbnFormatChord === 'function') {
        return window.sbnFormatChord(name);
    }
    return name;
});

function onChordResizeStartLeft(ci, pointerEvent) {
    if (props.readOnly || !chordGridOps) return;
    pointerEvent.preventDefault();
    pointerEvent.stopPropagation();

    const rect = measureEl.value?.getBoundingClientRect();
    if (!rect) return;

    const bpm    = beatsPerMeasure.value;
    const start  = props.measure.chordOffsets?.[ci] ?? (ci * (bpm / (chordNamesArray.value.length || 1)));
    const beats  = props.measure.chordBeats?.[ci]   ?? (bpm / (chordNamesArray.value.length || 1));

    resizeLeft.value = {
        ci,
        measureRect: rect,
        fixedEnd:     start + beats,
        currentStart: start,
    };

    window.addEventListener('pointermove', onResizeLeftMove);
    window.addEventListener('pointerup',   onResizeLeftEnd);
}

function onResizeLeftMove(e) {
    if (!resizeLeft.value) return;
    const { measureRect, fixedEnd } = resizeLeft.value;
    const bpm     = beatsPerMeasure.value;
    const rawBeat = ((e.clientX - measureRect.left) / measureRect.width) * bpm;
    const snapped = Math.round(rawBeat / 0.5) * 0.5;
    resizeLeft.value = {
        ...resizeLeft.value,
        currentStart: Math.max(0, Math.min(snapped, fixedEnd - 0.5)),
    };
}

function onResizeLeftEnd() {
    window.removeEventListener('pointermove', onResizeLeftMove);
    window.removeEventListener('pointerup',   onResizeLeftEnd);
    if (!resizeLeft.value) return;
    const { ci, currentStart } = resizeLeft.value;
    resizeLeft.value = null;
    chordGridOps.setChordStart(globalIdx.value, ci, currentStart);
}

// ── Chord drag-and-drop ───────────────────────────────────────────────────────

const measureEl    = ref(null);
const drag         = ref(null);   // null | { ci, measureRect, currentOffset }
const resize       = ref(null);   // null | { ci, measureRect, currentEnd }
const resizeLeft   = ref(null);   // null | { ci, measureRect, fixedEnd, currentStart }

/**
 * Called by ChordCard when user presses down on its drag handle.
 * ci — the chord slot index being dragged.
 */
function onChordDragStart(ci, pointerEvent) {
    if (props.readOnly || !chordGridOps) return;
    pointerEvent.preventDefault();
    pointerEvent.stopPropagation();

    const rect = measureEl.value?.getBoundingClientRect();
    if (!rect) return;

    drag.value = {
        ci,
        measureRect: rect,
        currentOffset: props.measure.chordOffsets?.[ci] ?? (ci * (beatsPerMeasure.value / (chordNamesArray.value.length || 1))),
    };

    window.addEventListener('pointermove', onDragMove);
    window.addEventListener('pointerup',   onDragEnd);
}

function onDragMove(e) {
    if (!drag.value) return;
    const { measureRect } = drag.value;
    const bpm = beatsPerMeasure.value;

    // Raw beat offset from pointer X relative to measure width
    const rawPct = (e.clientX - measureRect.left) / measureRect.width;
    const rawBeat = rawPct * bpm;

    const snapped = Math.round(rawBeat / 0.5) * 0.5;
    const ci  = drag.value.ci;
    const dur = props.measure.chordBeats?.[ci] ?? (bpm / (chordNamesArray.value.length || 1));
    // Clamp so the card end never exceeds the measure boundary
    const maxOffset = Math.max(0, bpm - Math.max(0.5, dur));
    drag.value = {
        ...drag.value,
        currentOffset: Math.max(0, Math.min(snapped, maxOffset)),
    };
}

function onDragEnd() {
    window.removeEventListener('pointermove', onDragMove);
    window.removeEventListener('pointerup',   onDragEnd);

    if (!drag.value) return;
    const { ci, currentOffset } = drag.value;
    drag.value = null;

    chordGridOps.setChordOffset(globalIdx.value, ci, currentOffset);
}

// ── Context menu ─────────────────────────────────────────────────────────────

/**
 * A ChordCard emitted a contextmenu event — augment with measure coords and re-emit
 * so ChordSection (and ultimately ChordGridView) can position the context menu.
 */
function onCardContextMenu(payload) {
  if (props.readOnly) return;
  emit('contextmenu', {
    ...payload,
    si:      props.sectionIndex,
    mi:      props.measureIndex,
    measure: props.measure,
  });
}
</script>
