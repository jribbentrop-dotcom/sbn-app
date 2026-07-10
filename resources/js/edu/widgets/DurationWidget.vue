<script setup lang="ts">
import { ref, computed } from 'vue';

interface DotExample {
  base: string;
  baseFraction: number;
  dotFraction: number;
  totalFraction: number;
  /** Name of the half-value unit the note splits into (e.g. a half note → "quarter"). */
  unitName: string;
  /** Where this dotted value shows up in practice — shown as a small context tag. */
  genre: string;
  explanation: string;
}
interface TieExample {
  noteA: { name: string; fraction: number };
  noteB: { name: string; fraction: number };
  totalFraction: number;
  context: string;
  explanation: string;
}

// A dotted note is extended by half its own value. We show this literally:
// the base note splits into two "half value" units, and the dot is a third,
// equal-sized unit — the same idea in every example, just at a different
// grid resolution.
const DOT_EXAMPLES: DotExample[] = [
  { base: 'Half', baseFraction: 0.5, dotFraction: 0.25, totalFraction: 0.75, unitName: 'quarter', genre: '3/4 Time',
    explanation: 'A dotted note is extended by half its own value. Split the half into two quarter-beats, then add a third — the dot is simply one more of the same unit. Three quarter-beats fill an entire bar of 3/4, so a dotted half is the sound of a whole waltz measure in one note.' },
  { base: 'Quarter', baseFraction: 0.25, dotFraction: 0.125, totalFraction: 0.375, unitName: 'eighth', genre: 'Bossa Nova',
    explanation: 'The quarter splits into two eighth-beats; the dot adds a third. Many jazz and bossa melodies begin with a dotted quarter note — the extra eighth-beat gives the pickup its long-short lift into the phrase.' },
  { base: 'Eighth', baseFraction: 0.125, dotFraction: 0.0625, totalFraction: 0.1875, unitName: 'sixteenth', genre: 'Samba & Choro, 2/4',
    explanation: 'The eighth splits into two sixteenth-beats; the dot adds a third. Paired with one quick sixteenth, this dotted-eighth skip is the subdivision that drives fast 2/4 styles like samba and choro.' },
];

const TIE_EXAMPLES: TieExample[] = [
  { noteA: { name: 'Eighth', fraction: 0.125 }, noteB: { name: 'Quarter', fraction: 0.25 }, totalFraction: 0.375, context: 'Across barline',
    explanation: 'A tie generally lengthens a note. Across a barline, an eighth tied into a quarter lets a melody attack before the downbeat and carry through it — how phrases that start on the upbeat, rather than landing squarely on beat one, get notated. A very common and important reading skill.' },
  { noteA: { name: 'Eighth', fraction: 0.125 }, noteB: { name: 'Half', fraction: 0.5 }, totalFraction: 0.625, context: 'Within measure',
    explanation: 'Within a measure, a tie can build note lengths that don’t otherwise exist — an eighth tied to a half adds up to two-and-a-half beats, a duration no single notehead can represent. Ties let any rhythm be written exactly, however unusual the length.' },
];

// ── Shared geometry ─────────────────────────────────────────────────────────
const CELLS_PER_BAR = 16; // sixteenth-note resolution, used only for fraction→cell math
const CELL_W   = 9;
const CELL_GAP = 2;
const SVG_W    = 200;
const SVG_H    = 100;

function fractionToCells(fraction: number) {
  return Math.max(1, Math.round(fraction * CELLS_PER_BAR));
}
function rowWidth(cellCount: number) {
  return cellCount * CELL_W + (cellCount - 1) * CELL_GAP;
}

const tab     = ref<'dot' | 'tie'>('dot');
const exIdx   = ref(0);
const showDot = ref(false);
const direction = ref<'left' | 'right' | null>(null);
const visible = ref(true);

const examples  = computed(() => tab.value === 'dot' ? DOT_EXAMPLES : TIE_EXAMPLES);
const example   = computed(() => examples.value[exIdx.value]);

const contextText = computed(() =>
  tab.value === 'dot' ? (example.value as DotExample).genre : (example.value as TieExample).context
);

const animClass = computed(() => {
  if (!visible.value) return direction.value === 'right' ? 'dur-exit-left' : 'dur-exit-right';
  return direction.value === 'right' ? 'dur-enter-right' : direction.value === 'left' ? 'dur-enter-left' : 'dur-enter-right';
});

function switchTab(t: 'dot' | 'tie') {
  if (t === tab.value) return;
  tab.value = t;
  exIdx.value = 0;
  showDot.value = false;
  visible.value = false;
  setTimeout(() => { visible.value = true; }, 200);
}

function navigate(dir: 'left' | 'right') {
  const next = dir === 'right'
    ? Math.min(exIdx.value + 1, examples.value.length - 1)
    : Math.max(exIdx.value - 1, 0);
  if (next === exIdx.value) return;
  direction.value = dir;
  showDot.value = false;
  visible.value = false;
  setTimeout(() => { exIdx.value = next; visible.value = true; }, 200);
}

// ── Dot tab: base note shown as 2 half-value units, dot = a 3rd unit ───────
const dotEx = computed(() => example.value as DotExample);
const UNIT_GAP = 6;
const unitCells  = computed(() => fractionToCells(dotEx.value.dotFraction)); // the "half value" unit
const unitWidth  = computed(() => rowWidth(unitCells.value));
const unitCount  = computed(() => showDot.value ? 3 : 2);
const dotRowWidth = computed(() => unitCount.value * unitWidth.value + (unitCount.value - 1) * UNIT_GAP);
const dotRowStart = computed(() => (SVG_W - dotRowWidth.value) / 2);
function unitX(i: number) { return dotRowStart.value + i * (unitWidth.value + UNIT_GAP); }
const dotCountLabel = computed(() => `${unitCount.value} × ${dotEx.value.unitName}-beats`);

const ROW_Y  = 40;
const CELL_H = 24;

// ── Tie tab: two circled "dots" sized by duration, joined by a tie ─────────
const tieEx = computed(() => example.value as TieExample);
function circleRadius(fraction: number) { return Math.round(6 + fraction * 24); }
const rA = computed(() => circleRadius(tieEx.value.noteA.fraction));
const rB = computed(() => circleRadius(tieEx.value.noteB.fraction));
const maxR = computed(() => Math.max(rA.value, rB.value));
const CIRCLE_Y = 46;
const CIRCLE_GAP = 22; // half-gap from centre line to each circle's inner edge
const cxA = computed(() => SVG_W / 2 - CIRCLE_GAP - rA.value);
const cxB = computed(() => SVG_W / 2 + CIRCLE_GAP + rB.value);

const showBarline = computed(() => tieEx.value.context === 'Across barline');
const barlineX = computed(() => (cxA.value + rA.value + cxB.value - rB.value) / 2);

// Tie starts near the top of the first dot, ends near the front (top-leading
// edge) of the second — the same anchoring a real notation tie/slur uses.
const tieX1 = computed(() => cxA.value + rA.value * 0.55);
const tieY1 = computed(() => CIRCLE_Y - rA.value * 0.78);
const tieX2 = computed(() => cxB.value - rB.value * 0.55);
const tieY2 = computed(() => CIRCLE_Y - rB.value * 0.78);
const tieLensPath = computed(() => {
  const x1 = tieX1.value, x2 = tieX2.value;
  const y = (tieY1.value + tieY2.value) / 2;
  const span = Math.abs(x2 - x1);
  const rx = span / 2;
  const ryOuter = Math.min(Math.max(span * 0.28, 5), 13);
  const ryInner = Math.max(ryOuter - 2, 1.5);
  return `M ${x1} ${y} A ${rx} ${ryOuter} 0 0 1 ${x2} ${y} A ${rx} ${ryInner} 0 0 0 ${x1} ${y} Z`;
});
</script>

<template>
  <div class="dur-card">

    <!-- Header -->
    <div class="dur-header">
      <div class="dur-title">Dots &amp; Ties</div>
      <div class="dur-tabs">
        <button class="dur-tab" :class="{ active: tab === 'dot' }" @click="switchTab('dot')">Dotted</button>
        <button class="dur-tab" :class="{ active: tab === 'tie' }" @click="switchTab('tie')">Tied</button>
      </div>
    </div>

    <!-- Visual -->
    <div class="dur-visual" :class="animClass">

      <!-- Dot tab: base note = 2 half-value units, dot = a 3rd unit -->
      <svg v-if="tab === 'dot'" width="100%" :viewBox="`0 0 ${SVG_W} ${SVG_H}`" style="display:block">
        <text :x="SVG_W / 2" :y="ROW_Y - 12" text-anchor="middle"
          font-family="'DM Mono', monospace" font-size="8" fill="#64748b"
        >{{ dotEx.base }} note</text>

        <rect :x="unitX(0)" :y="ROW_Y" :width="unitWidth" :height="CELL_H" rx="4" fill="#94a3b8" />
        <rect :x="unitX(1)" :y="ROW_Y" :width="unitWidth" :height="CELL_H" rx="4" fill="#94a3b8" />

        <rect v-if="showDot" :x="unitX(2)" :y="ROW_Y" :width="unitWidth" :height="CELL_H" rx="4" fill="#f59e0b"
          style="animation: durCellPop 0.32s cubic-bezier(0.34,1.2,0.64,1) both" />

        <text :x="SVG_W / 2" :y="ROW_Y + CELL_H + 22" text-anchor="middle"
          font-family="'DM Mono', monospace" font-size="8" :fill="showDot ? '#b45309' : '#64748b'"
        >{{ dotCountLabel }}</text>
      </svg>

      <!-- Tie tab: two circled "dots" joined by a tie -->
      <svg v-else width="100%" :viewBox="`0 0 ${SVG_W} ${SVG_H}`" style="display:block">
        <line v-if="showBarline" :x1="barlineX" :y1="CIRCLE_Y - maxR - 8" :x2="barlineX" :y2="CIRCLE_Y + maxR + 8"
          stroke="rgba(15,23,42,0.18)" stroke-width="1.5" />

        <path :d="tieLensPath" fill="#f59e0b" stroke="none" />

        <circle :cx="cxA" :cy="CIRCLE_Y" :r="rA" fill="#94a3b8" />
        <text :x="cxA" :y="CIRCLE_Y + maxR + 14" text-anchor="middle"
          font-family="'DM Mono', monospace" font-size="8" fill="#475569"
        >{{ tieEx.noteA.name }}</text>

        <circle :cx="cxB" :cy="CIRCLE_Y" :r="rB" fill="#f59e0b" />
        <text :x="cxB" :y="CIRCLE_Y + maxR + 14" text-anchor="middle"
          font-family="'DM Mono', monospace" font-size="8" fill="#b45309"
        >{{ tieEx.noteB.name }}</text>

        <text :x="SVG_W / 2" :y="CIRCLE_Y + maxR + 28" text-anchor="middle"
          font-family="'DM Mono', monospace" font-size="8" fill="#b45309"
        >= {{ tieEx.totalFraction === 1 ? '1 whole' : `${Math.round(tieEx.totalFraction * 8)}/8` }} note</text>
      </svg>
    </div>

    <!-- Dot toggle -->
    <div v-if="tab === 'dot'" class="dur-dot-toggle">
      <button class="dur-dot-btn" :class="{ on: showDot }" @click="showDot = !showDot">
        {{ showDot ? 'Dot on' : 'Add dot' }}
      </button>
    </div>

    <!-- Context / genre tag -->
    <div class="dur-context-label">{{ contextText }}</div>

    <!-- Explanation -->
    <div class="dur-explanation">{{ example.explanation }}</div>

    <!-- Nav -->
    <nav class="dur-nav">
      <button class="dur-arrow" :disabled="exIdx === 0" @click="navigate('left')">‹</button>
      <div class="dur-stepdots">
        <div v-for="(_, i) in examples" :key="i" class="dur-stepdot" :class="{ active: i === exIdx }" />
      </div>
      <button class="dur-arrow" :disabled="exIdx === examples.length - 1" @click="navigate('right')">›</button>
    </nav>

  </div>
</template>

<style scoped>
.dur-card {
  width: 100%;
  background: var(--clr-surface, #ffffff);
  border: 1px solid var(--clr-border, #e8edf3);
  border-radius: 0.875rem;
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 1.5rem 1.25rem;
  gap: 1.1rem;
  user-select: none;
  box-sizing: border-box;
}

.dur-header { width: 100%; display: flex; align-items: center; justify-content: space-between; }
.dur-title {
  font-family: 'DM Mono', monospace; font-size: 0.7rem;
  letter-spacing: 0.15em; text-transform: uppercase; color: var(--clr-text-muted, #64748b);
}

.dur-tabs { display: flex; background: var(--clr-surface-3, #eef1f5); border-radius: 999px; padding: 3px; gap: 2px; border: 1px solid var(--clr-border, #e8edf3); }
.dur-tab {
  font-family: 'DM Mono', monospace; font-size: 0.65rem; letter-spacing: 0.08em;
  padding: 0.32rem 0.85rem; border-radius: 999px; border: none;
  background: transparent; color: var(--clr-text-muted, #64748b); cursor: pointer; transition: all 0.25s ease;
}
.dur-tab.active { background: var(--clr-accent, #f39c12); color: #ffffff; box-shadow: 0 1px 4px rgba(243,156,18,0.35); }

.dur-visual {
  width: 100%;
  background: var(--clr-surface-3, #f8fafc);
  border: 1px solid var(--clr-border, #e8edf3);
  border-radius: 0.75rem;
  overflow: hidden;
  transition: opacity 0.2s ease;
}

.dur-dot-toggle { width: 100%; display: flex; align-items: center; justify-content: center; gap: 0.6rem; }
.dur-dot-btn {
  font-family: 'DM Mono', monospace; font-size: 0.65rem; letter-spacing: 0.08em;
  padding: 0.32rem 1rem; border-radius: 999px;
  border: 1px solid var(--clr-border, #e8edf3); background: var(--clr-surface-3, #eef1f5);
  color: var(--clr-text, #1a1a2e); cursor: pointer; transition: all 0.2s ease;
}
.dur-dot-btn.on { background: var(--clr-accent, #f39c12); border-color: transparent; color: #ffffff; }

.dur-explanation {
  font-family: system-ui, sans-serif;
  font-size: 0.82rem; line-height: 1.65;
  color: var(--clr-text, #1a1a2e); text-align: center; padding: 0 0.25rem; min-height: 3.5rem;
}

.dur-nav { display: flex; align-items: center; gap: 1.25rem; }
.dur-arrow {
  background: var(--clr-surface-3, #eef1f5); border: 1px solid var(--clr-border, #e8edf3); color: var(--clr-text-muted, #64748b);
  width: 34px; height: 34px; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  cursor: pointer; font-size: 0.85rem; transition: all 0.2s ease;
}
.dur-arrow:hover:not(:disabled) { border-color: var(--clr-accent-border, rgba(243,156,18,0.35)); color: var(--clr-text, #1a1a2e); }
.dur-arrow:disabled { opacity: 0.3; cursor: default; }

.dur-stepdots { display: flex; gap: 5px; align-items: center; }
.dur-stepdot { width: 5px; height: 5px; border-radius: 50%; background: var(--clr-border, #dde3ea); transition: all 0.3s ease; }
.dur-stepdot.active { width: 14px; border-radius: 3px; background: var(--clr-accent, #f39c12); }

.dur-context-label {
  font-family: 'DM Mono', monospace; font-size: 0.65rem; letter-spacing: 0.12em;
  text-transform: uppercase; color: var(--clr-text-muted, #64748b); align-self: flex-start; padding-left: 0.25rem;
}

@keyframes durCellPop { from { opacity: 0; transform: scaleY(0.3); } to { opacity: 1; transform: scaleY(1); } }
@keyframes durFadeIn  { from { opacity: 0; } to { opacity: 1; } }

.dur-enter-right { animation: durEnterRight 0.3s cubic-bezier(0.34,1.2,0.64,1) forwards; }
.dur-enter-left  { animation: durEnterLeft  0.3s cubic-bezier(0.34,1.2,0.64,1) forwards; }
.dur-exit-left   { opacity: 0; transform: translateX(-16px); }
.dur-exit-right  { opacity: 0; transform: translateX(16px); }
@keyframes durEnterRight { from { opacity:0; transform:translateX(16px);  } to { opacity:1; transform:translateX(0); } }
@keyframes durEnterLeft  { from { opacity:0; transform:translateX(-16px); } to { opacity:1; transform:translateX(0); } }

@media (prefers-reduced-motion: reduce) {
  .dur-tab, .dur-dot-btn, .dur-stepdot { transition: none; }
  .dur-enter-right, .dur-enter-left { animation: none; }
}
</style>
