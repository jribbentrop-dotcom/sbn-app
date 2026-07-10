<script setup lang="ts">
import { ref, computed } from 'vue';

interface Example {
  id: string;
  title: string;
  subtitle: string;
  division: string;
  explanation: string;
  /** The simple division-math callout, shown only once the comparison is toggled on. */
  mathNote: string;
  normalCount: number;
  tripletCount: number;
  noteType: 'quarter' | 'eighth';
}

const EXAMPLES: Example[] = [
  {
    id: 'quarter-triplet',
    title: 'Quarter Note Triplet',
    subtitle: '3 notes in the space of 2 quarter notes',
    division: '3 in 2',
    explanation: 'Three quarter notes squeezed into the space of two. Spans two beats — each triplet note is slightly shorter than a regular quarter. Creates a broad, sweeping feel. Common in Jazz ballads and as a rhythmic displacement device.',
    mathNote: 'A triplet divides these two beats into 3 equal parts, instead of the 50/50 split two quarter notes make. The last triplet note lands two-thirds of the way through — closer to beat three than the second quarter note would be.',
    normalCount: 2, tripletCount: 3, noteType: 'quarter',
  },
  {
    id: 'eighth-triplet',
    title: 'Eighth Note Triplet',
    subtitle: '3 notes in the space of 1 quarter note',
    division: '3 in 1',
    explanation: "Three eighth notes squeezed into one beat. The most common triplet in Jazz and Bossa Nova. Swing feel is an approximation of triplets — the 'long-short' of swing is really the first and third notes of an eighth triplet.",
    mathNote: 'A triplet divides the beat into 3 equal parts, instead of the 50/50 split of two eighth notes. The last triplet note lands two-thirds of the way through the beat — slightly closer to the next beat than an eighth note would be.',
    normalCount: 2, tripletCount: 3, noteType: 'eighth',
  },
];

// A big, centred triplet group — no staff, no barlines. Both the "normal"
// reference notes and the triplet notes are centred on the same midpoint so
// the two spans compare directly.
const SVG_W = 200;
const SVG_H = 100;
const CX = SVG_W / 2;
const noteY = 58;

const idx = ref(0);
const showComparison = ref(false);

const ex = computed(() => EXAMPLES[idx.value]);

// Positions are anchored by ATTACK time, not by the centre of an equal
// slot — the whole point here is that the first note of both groups lands
// on the exact same instant. The rhythm block below is the thing centred
// on the widget; the note-attack span starts half a division after the
// block's left edge, since each note sits centred within its own third.
const normalUnit = computed(() => ex.value.noteType === 'quarter' ? 44 : 38);
const beatSpan   = computed(() => normalUnit.value * ex.value.normalCount);
const tripletSpacing = computed(() => beatSpan.value / ex.value.tripletCount);
const blockLeft  = computed(() => CX - beatSpan.value / 2);
const spanLeft   = computed(() => blockLeft.value + tripletSpacing.value / 2);

const normalPositions = computed(() =>
  Array.from({ length: ex.value.normalCount }, (_, i) => spanLeft.value + normalUnit.value * i)
);

const tripletPositions = computed(() =>
  Array.from({ length: ex.value.tripletCount }, (_, i) => spanLeft.value + tripletSpacing.value * i)
);

const stemLen = computed(() => ex.value.noteType === 'quarter' ? 32 : 28);
const stemTopY = computed(() => noteY - stemLen.value);

// Bracket — quarter triplets only. Eighth triplets are already grouped by
// their beam, so real notation just shows the "3", no bracket.
const bracketX1 = computed(() => tripletPositions.value[0] - 8);
const bracketX2 = computed(() => tripletPositions.value[2] + 12);
const bracketMid = computed(() => (bracketX1.value + bracketX2.value) / 2);
const bracketY = computed(() => stemTopY.value - 8);
const TICK = 5; // bracket end-ticks point down, toward the notes

// ── The "rhythm block" — one grey span for the whole beat, marked with the
// triplet's 3 equal divisions, optionally overlaid with the straight note's
// 2 equal divisions. Notation above, the divided block below, sharing the
// same x-axis — traditional engraving fused with the rhythm-widget grid.
const BLOCK_Y = 76;
const BLOCK_H = 14;
const OVERLAY_EXT = 4; // how far the straight-division line pokes past the block

const tripletDividerXs = computed(() => [1, 2].map(n => blockLeft.value + tripletSpacing.value * n));
const straightMidX = computed(() => blockLeft.value + beatSpan.value / 2);

const numberY = computed(() => ex.value.noteType === 'quarter' ? bracketY.value - 2 : stemTopY.value - 8);
// Centre the "3" on the actual note group — the beamed pair's midpoint for
// eighths (which no longer sits at the widget's centre now that positions
// are attack-time based), the bracket's midpoint for quarters.
const numberX = computed(() => ex.value.noteType === 'quarter'
  ? bracketMid.value
  : (tripletPositions.value[0] + tripletPositions.value[2]) / 2 + 4.8
);

function selectExample(i: number) {
  idx.value = i;
  showComparison.value = false;
}
</script>

<template>
  <div class="tr-card">
      <div class="tr-header">
        <div class="tr-label">Triplets</div>
        <div class="tr-pills">
          <button
            v-for="(e, i) in EXAMPLES" :key="e.id"
            :class="['tr-pill', i === idx ? 'active' : '']"
            @click="selectExample(i)"
          >{{ e.noteType === 'quarter' ? '♩' : '♪' }} triplet</button>
        </div>
      </div>

      <div style="width:100%">
        <div class="tr-title">{{ ex.title }}</div>
        <div class="tr-subtitle">{{ ex.subtitle }}</div>
      </div>

      <div class="tr-diagram">
        <svg width="100%" :viewBox="`0 0 ${SVG_W} ${SVG_H}`" style="display:block">

          <!-- Triplet notes (drawn first — the black reference notes below
               sit on top so a shared attack point is never hidden). -->
          <template v-for="(x, i) in tripletPositions" :key="`trip-${i}`">
            <template v-if="ex.noteType === 'quarter'">
              <ellipse :cx="x" :cy="noteY" rx="7" ry="5" fill="#f59e0b" :transform="`rotate(-15,${x},${noteY})`"/>
              <line :x1="x+5.5" :y1="noteY-2" :x2="x+5.5" :y2="stemTopY" stroke="#f59e0b" stroke-width="2" stroke-linecap="round"/>
            </template>
            <template v-else>
              <ellipse :cx="x" :cy="noteY" rx="6" ry="4.5" fill="#f59e0b" :transform="`rotate(-15,${x},${noteY})`"/>
              <line :x1="x+4.8" :y1="noteY-2" :x2="x+4.8" :y2="stemTopY" stroke="#f59e0b" stroke-width="1.8" stroke-linecap="round"/>
            </template>
          </template>

          <!-- Beam for eighth triplets -->
          <line v-if="ex.noteType === 'eighth'"
            :x1="tripletPositions[0]+4.8" :y1="stemTopY"
            :x2="tripletPositions[2]+4.8" :y2="stemTopY"
            stroke="#f59e0b" stroke-width="2.5"/>

          <!-- Triplet bracket — quarter triplets only, ticks pointing down -->
          <template v-if="ex.noteType === 'quarter'">
            <line :x1="bracketX1" :y1="bracketY" :x2="bracketX1" :y2="bracketY+TICK" stroke="#f59e0b" stroke-width="1"/>
            <line :x1="bracketX1" :y1="bracketY" :x2="bracketMid-8" :y2="bracketY" stroke="#f59e0b" stroke-width="1"/>
            <line :x1="bracketX2" :y1="bracketY" :x2="bracketX2" :y2="bracketY+TICK" stroke="#f59e0b" stroke-width="1"/>
            <line :x1="bracketMid+8" :y1="bracketY" :x2="bracketX2" :y2="bracketY" stroke="#f59e0b" stroke-width="1"/>
          </template>

          <!-- Triplet number — centred over the actual note/beam group -->
          <text :x="numberX" :y="numberY" text-anchor="middle"
            font-family="'DM Mono', monospace" font-size="10" fill="#f59e0b">3</text>

          <!-- Normal notes (real notation ink, drawn on top) -->
          <template v-if="showComparison">
            <template v-if="ex.noteType === 'quarter'">
              <g v-for="(x, i) in normalPositions" :key="`norm-${i}`">
                <ellipse :cx="x" :cy="noteY" rx="7" ry="5" fill="#333" :transform="`rotate(-15,${x},${noteY})`"/>
                <line :x1="x+5.5" :y1="noteY-2" :x2="x+5.5" :y2="stemTopY" stroke="#333" stroke-width="2" stroke-linecap="round"/>
              </g>
            </template>
            <template v-else>
              <g v-for="(x, i) in normalPositions" :key="`norm-${i}`">
                <ellipse :cx="x" :cy="noteY" rx="6" ry="4.5" fill="#333" :transform="`rotate(-15,${x},${noteY})`"/>
                <line :x1="x+4.8" :y1="noteY-2" :x2="x+4.8" :y2="stemTopY" stroke="#333" stroke-width="1.8" stroke-linecap="round"/>
              </g>
              <line :x1="normalPositions[0]+4.8" :y1="stemTopY" :x2="normalPositions[1]+4.8" :y2="stemTopY"
                stroke="#333" stroke-width="2.5"/>
            </template>
          </template>

          <!-- Rhythm block: the whole beat as one grey span, marked with the
               triplet's 3 equal divisions; the straight note's 2 equal
               divisions overlay as a dashed ink line when toggled on. -->
          <rect :x="blockLeft" :y="BLOCK_Y" :width="beatSpan" :height="BLOCK_H" rx="3"
            fill="var(--clr-surface-3, #eef1f5)" stroke="var(--clr-border, #e2e8f0)" stroke-width="1"/>
          <line v-for="(x, i) in tripletDividerXs" :key="`tdiv-${i}`"
            :x1="x" :y1="BLOCK_Y" :x2="x" :y2="BLOCK_Y + BLOCK_H"
            stroke="#f59e0b" stroke-width="1.6"/>
          <line v-if="showComparison" :x1="straightMidX" :y1="BLOCK_Y - OVERLAY_EXT" :x2="straightMidX" :y2="BLOCK_Y + BLOCK_H + OVERLAY_EXT"
            str