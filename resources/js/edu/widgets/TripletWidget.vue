<script setup lang="ts">
import { ref, computed } from 'vue';

interface Example {
  id: string;
  title: string;
  subtitle: string;
  division: string;
  explanation: string;
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
    normalCount: 2, tripletCount: 3, noteType: 'quarter',
  },
  {
    id: 'eighth-triplet',
    title: 'Eighth Note Triplet',
    subtitle: '3 notes in the space of 1 quarter note',
    division: '3 in 1',
    explanation: "Three eighth notes squeezed into one beat. The most common triplet in Jazz and Bossa Nova. Swing feel is an approximation of triplets — the 'long-short' of swing is really the first and third notes of an eighth triplet.",
    normalCount: 2, tripletCount: 3, noteType: 'eighth',
  },
];

const SVG_W = 240;
const barLeft = 20;
const barRight = SVG_W - 20;
const barW = barRight - barLeft;
const noteY = 48;

const idx = ref(0);
const showComparison = ref(false);

const ex = computed(() => EXAMPLES[idx.value]);

const normalSpacing = computed(() =>
  ex.value.noteType === 'quarter' ? barW / 4 : barW / 8
);

const normalPositions = computed(() =>
  Array.from({ length: ex.value.normalCount }, (_, i) =>
    barLeft + normalSpacing.value * (i + 0.5)
  )
);

const tripletSpan = computed(() => normalSpacing.value * ex.value.normalCount);
const tripletSpacing = computed(() => tripletSpan.value / ex.value.tripletCount);
const tripletStart = computed(() => barLeft + normalSpacing.value * 0.5 - tripletSpacing.value / 2);

const tripletPositions = computed(() =>
  Array.from({ length: ex.value.tripletCount }, (_, i) =>
    tripletStart.value + tripletSpacing.value * (i + 0.5)
  )
);

const bracketX1 = computed(() => tripletPositions.value[0] - 6);
const bracketX2 = computed(() => tripletPositions.value[2] + 10);
const bracketMid = computed(() => (bracketX1.value + bracketX2.value) / 2);
const bracketY = computed(() => noteY - 32);

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
        <svg width="100%" :viewBox="`0 0 ${SVG_W} 80`" style="display:block">
          <!-- Barlines -->
          <line :x1="barLeft" :y1="noteY-30" :x2="barLeft" :y2="noteY+12" stroke="rgba(255,255,255,0.25)" stroke-width="1.5"/>
          <line :x1="barRight" :y1="noteY-30" :x2="barRight" :y2="noteY+12" stroke="rgba(255,255,255,0.25)" stroke-width="1.5"/>
          <line :x1="barRight-3" :y1="noteY-30" :x2="barRight-3" :y2="noteY+12" stroke="rgba(255,255,255,0.25)" stroke-width="1"/>
          <!-- Staff lines -->
          <line v-for="i in 5" :key="i" :x1="barLeft" :y1="noteY-8+(i-1)*6" :x2="barRight" :y2="noteY-8+(i-1)*6" stroke="rgba(255,255,255,0.08)" stroke-width="0.5"/>

          <!-- Normal notes (ghosted) -->
          <template v-if="showComparison">
            <g v-for="(x, i) in normalPositions" :key="`norm-${i}`" opacity="0.25">
              <!-- Quarter note head -->
              <template v-if="ex.noteType === 'quarter'">
                <ellipse :cx="x" :cy="noteY" rx="5.5" ry="4" fill="#ffffff" :transform="`rotate(-15,${x},${noteY})`"/>
                <line :x1="x+4.5" :y1="noteY-2" :x2="x+4.5" :y2="noteY-22" stroke="#ffffff" stroke-width="1.8" stroke-linecap="round"/>
              </template>
              <!-- Eighth note head -->
              <template v-else>
                <ellipse :cx="x" :cy="noteY" rx="4.5" ry="3.5" fill="#ffffff" :transform="`rotate(-15,${x},${noteY})`"/>
                <line :x1="x+3.8" :y1="noteY-2" :x2="x+3.8" :y2="noteY-18" stroke="#ffffff" stroke-width="1.5" stroke-linecap="round"/>
              </template>
            </g>
          </template>

          <!-- Triplet notes -->
          <template v-for="(x, i) in tripletPositions" :key="`trip-${i}`">
            <template v-if="ex.noteType === 'quarter'">
              <ellipse :cx="x" :cy="noteY" rx="5.5" ry="4" fill="#f59e0b" :transform="`rotate(-15,${x},${noteY})`"/>
              <line :x1="x+4.5" :y1="noteY-2" :x2="x+4.5" :y2="noteY-22" stroke="#f59e0b" stroke-width="1.8" stroke-linecap="round"/>
            </template>
            <template v-else>
              <ellipse :cx="x" :cy="noteY" rx="4.5" ry="3.5" fill="#f59e0b" :transform="`rotate(-15,${x},${noteY})`"/>
              <line :x1="x+3.8" :y1="noteY-2" :x2="x+3.8" :y2="noteY-18" stroke="#f59e0b" stroke-width="1.5" stroke-linecap="round"/>
            </template>
          </template>

          <!-- Beam for eighth triplets -->
          <line v-if="ex.noteType === 'eighth'"
            :x1="tripletPositions[0]+3.8" :y1="noteY-18"
            :x2="tripletPositions[2]+3.8" :y2="noteY-18"
            stroke="#f59e0b" stroke-width="2.5"/>

          <!-- Triplet bracket -->
          <line :x1="bracketX1" :y1="bracketY" :x2="bracketX1" :y2="bracketY-5" stroke="#f59e0b" stroke-width="1"/>
          <line :x1="bracketX1" :y1="bracketY" :x2="bracketMid-8" :y2="bracketY" stroke="#f59e0b" stroke-width="1"/>
          <line :x1="bracketX2" :y1="bracketY" :x2="bracketX2" :y2="bracketY-5" stroke="#f59e0b" stroke-width="1"/>
          <line :x1="bracketMid+8" :y1="bracketY" :x2="bracketX2" :y2="bracketY" stroke="#f59e0b" stroke-width="1"/>
          <text :x="bracketMid" :y="bracketY-2" text-anchor="middle" font-family="'DM Mono', monospace" font-size="9" fill="#f59e0b">3</text>

          <!-- Beat label for eighth -->
          <text v-if="ex.noteType === 'eighth'" :x="tripletPositions[0]-2" :y="noteY+20" font-family="'DM Mono', monospace" font-size="7" fill="rgba(255,255,255,0.2)">beat 1</text>
        </svg>
      </div>

      <!-- Comparison toggle -->
      <div class="tr-toggle-row">
        <span :class="['tr-toggle-label', showComparison ? 'on' : '']">
          {{ showComparison ? 'Showing normal notes' : 'Compare with normal' }}
        </span>
        <button :class="['tr-toggle', showComparison ? 'on' : '']" @click="showComparison = !showComparison">
          <div class="tr-knob" />
        </button>
      </div>

      <div class="tr-explanation">{{ ex.explanation }}</div>
  </div>
</template>

<style scoped>
.tr-card { width: 100%; background: #0f0f17; border-radius: 1.25rem; border: 1px solid rgba(255,255,255,0.06); display: flex; flex-direction: column; align-items: center; padding: 1.75rem 1.5rem 1.5rem; gap: 1.25rem; user-select: none; }
.tr-header { width: 100%; display: flex; align-items: center; justify-content: space-between; }
.tr-label { font-family: 'DM Mono', monospace; font-size: 0.65rem; letter-spacing: 0.15em; text-transform: uppercase; color: #ffffff; }
.tr-pills { display: flex; gap: 3px; }
.tr-pill { font-family: 'DM Mono', monospace; font-size: 0.6rem; letter-spacing: 0.08em; padding: 0.22rem 0.55rem; border-radius: 999px; border: 1px solid rgba(255,255,255,0.12); background: transparent; color: #ffffff; cursor: pointer; transition: all 0.2s ease; }
.tr-pill:hover { border-color: rgba(255,255,255,0.25); color: rgba(255,255,255,0.7); }
.tr-pill.active { background: rgba(255,255,255,0.08); color: #ffffff; border-color: rgba(255,255,255,0.22); }
.tr-title { font-family: 'Cormorant Garamond', serif; font-size: 1.5rem; font-weight: 300; color: #ffffff; line-height: 1.1; }
.tr-subtitle { font-family: 'DM Mono', monospace; font-size: 0.6rem; letter-spacing: 0.08em; text-transform: uppercase; color: rgba(245,158,11,0.7); margin-top: 0.25rem; }
.tr-diagram { width: 100%; background: rgba(255,255,255,0.02); border: 1px solid rgba(255,255,255,0.06); border-radius: 0.75rem; padding: 0.5rem; }
.tr-toggle-row { width: 100%; display: flex; align-items: center; gap: 0.6rem; }
.tr-toggle-label { font-family: 'DM Mono', monospace; font-size: 0.6rem; letter-spacing: 0.1em; text-transform: uppercase; color: #ffffff; flex: 1; transition: color 0.3s; }
.tr-toggle-label.on { color: #f59e0b; }
.tr-toggle { width: 40px; height: 22px; border-radius: 999px; background: rgba(255,255,255,0.08); border: 1px solid rgba(255,255,255,0.12); cursor: pointer; position: relative; transition: background 0.3s ease; flex-shrink: 0; }
.tr-toggle.on { background: #f59e0b; border-color: transparent; }
.tr-knob { position: absolute; top: 3px; left: 3px; width: 14px; height: 14px; border-radius: 50%; background: #ffffff; box-shadow: 0 1px 3px rgba(0,0,0,0.3); transition: transform 0.3s cubic-bezier(0.34,1.2,0.64,1); }
.tr-toggle.on .tr-knob { transform: translateX(18px); }
.tr-explanation { font-family: system-ui, sans-serif; font-size: 0.85rem; line-height: 1.6; color: #ffffff; min-height: 4rem; }
</style>
