<script setup lang="ts">
import { ref, computed } from 'vue';

interface DotExample {
  base: string;
  baseFraction: number;
  dotFraction: number;
  totalFraction: number;
  explanation: string;
}
interface TieExample {
  noteA: { name: string; fraction: number };
  noteB: { name: string; fraction: number };
  totalFraction: number;
  context: string;
  explanation: string;
}

const DOT_EXAMPLES: DotExample[] = [
  { base: 'Half',    baseFraction: 0.5,   dotFraction: 0.25,   totalFraction: 0.75,   explanation: 'A dotted half note. Half note (2 beats) + dot (1 beat) = 3 beats total.' },
  { base: 'Quarter', baseFraction: 0.25,  dotFraction: 0.125,  totalFraction: 0.375,  explanation: 'A dotted quarter. Quarter (1 beat) + dot (half a beat) = 1½ beats. Common in Bossa Nova rhythms.' },
  { base: 'Eighth',  baseFraction: 0.125, dotFraction: 0.0625, totalFraction: 0.1875, explanation: 'A dotted eighth. Eighth + dot = ¾ of a beat. Creates the lilting swing feel in compound time.' },
];

const TIE_EXAMPLES: TieExample[] = [
  { noteA: { name: 'Half',    fraction: 0.5   }, noteB: { name: 'Half',    fraction: 0.5  }, totalFraction: 1,     context: 'Across barline',   explanation: 'Two half notes tied across a barline. Play the first, hold through the second — one smooth whole note across the bar.' },
  { noteA: { name: 'Quarter', fraction: 0.25  }, noteB: { name: 'Half',    fraction: 0.5  }, totalFraction: 0.75,  context: 'Within measure',   explanation: 'Quarter tied to a half. Three beats as one sound — same result as a dotted half, but written as two notes.' },
  { noteA: { name: 'Eighth',  fraction: 0.125 }, noteB: { name: 'Quarter', fraction: 0.25 }, totalFraction: 0.375, context: 'Syncopation',       explanation: 'Eighth tied to a quarter. The attack lands off the beat, the sound carries through — the foundation of syncopated phrasing.' },
];

const BAR_MAX_W = 180;
const BAR_H = 22;
const BAR_Y = 40;
const DOT_R = 5;
const SVG_H = 110;

function fractionToW(fraction: number) {
  return Math.max(12, fraction * BAR_MAX_W);
}

function totalLabel(f: number) {
  return f === 1 ? '1' : `${Math.round(f * 8)}/8`;
}

const tab     = ref<'dot' | 'tie'>('dot');
const exIdx   = ref(0);
const showDot = ref(false);
const direction = ref<'left' | 'right' | null>(null);
const visible = ref(true);

const examples  = computed(() => tab.value === 'dot' ? DOT_EXAMPLES : TIE_EXAMPLES);
const example   = computed(() => examples.value[exIdx.value]);

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

// Dot SVG computed values
const dotEx = computed(() => example.value as DotExample);
const baseW  = computed(() => fractionToW(dotEx.value.baseFraction));
const dotW   = computed(() => fractionToW(dotEx.value.dotFraction));
const totalW = computed(() => fractionToW(dotEx.value.totalFraction));
const barX   = computed(() => (BAR_MAX_W - totalW.value) / 2 + 10);

// Tie SVG computed values
const tieEx  = computed(() => example.value as TieExample);
const wA     = computed(() => fractionToW(tieEx.value.noteA.fraction));
const wB     = computed(() => fractionToW(tieEx.value.noteB.fraction));
const tieGap = 12;
const startX = computed(() => (BAR_MAX_W - wA.value - tieGap - wB.value) / 2 + 10);
const xA     = computed(() => startX.value);
const xB     = computed(() => startX.value + wA.value + tieGap);
const showBarline = computed(() => (example.value as TieExample).context === 'Across barline');
const barlineX = computed(() => startX.value + wA.value + tieGap / 2);
const arcX1  = computed(() => xA.value + wA.value * 0.2);
const arcX2  = computed(() => xB.value + wB.value * 0.8);
const arcMidX = computed(() => (arcX1.value + arcX2.value) / 2);
const arcY   = BAR_Y - 10;
const arcControl = BAR_Y - 26;
</script>

<template>
  <div class="dur-card">

    <!-- Header -->
    <div class="dur-header">
      <div class="dur-title">Note Duration</div>
      <div class="dur-tabs">
        <button class="dur-tab" :class="{ active: tab === 'dot' }" @click="switchTab('dot')">Dotted</button>
        <button class="dur-tab" :class="{ active: tab === 'tie' }" @click="switchTab('tie')">Tied</button>
      </div>
    </div>

    <!-- Visual -->
    <div class="dur-visual" :class="animClass">

      <!-- Dot tab -->
      <svg v-if="tab === 'dot'" width="100%" :viewBox="`0 0 ${BAR_MAX_W + 20} ${SVG_H}`" style="display:block">
        <rect :x="barX" :y="BAR_Y" :width="baseW" :height="BAR_H" rx="4" fill="#1e1e28"
          style="transition: width 0.4s cubic-bezier(0.34,1.2,0.64,1)" />
        <text :x="barX + baseW / 2" :y="BAR_Y - 8" text-anchor="middle"
          font-family="'DM Mono', monospace" font-size="8" fill="rgba(30,30,40,0.4)"
        >{{ dotEx.base }}</text>

        <template v-if="showDot">
          <rect :x="barX + baseW + 3" :y="BAR_Y" :width="dotW" :height="BAR_H" rx="4" fill="#f59e0b"
            style="animation: durBarGrow 0.38s cubic-bezier(0.34,1.2,0.64,1) forwards" />
          <circle :cx="barX + baseW + 3 + dotW / 2" :cy="BAR_Y + BAR_H + 12" :r="DOT_R" fill="#f59e0b"
            style="animation: durDotPop 0.35s cubic-bezier(0.34,1.2,0.64,1) forwards" />
          <text :x="barX + totalW / 2" :y="BAR_Y + BAR_H + 26" text-anchor="middle"
            font-family="'DM Mono', monospace" font-size="8" fill="#f59e0b"
            style="animation: durFadeIn 0.3s ease forwards"
          >= {{ totalLabel(dotEx.totalFraction) }} note</text>
          <!-- Bracket -->
          <line :x1="barX"             :y1="BAR_Y - 2"  :x2="barX"             :y2="BAR_Y - 16" stroke="rgba(30,30,40,0.15)" stroke-width="1" />
          <line :x1="barX + totalW + 3" :y1="BAR_Y - 2" :x2="barX + totalW + 3" :y2="BAR_Y - 16" stroke="rgba(30,30,40,0.15)" stroke-width="1" />
          <line :x1="barX"             :y1="BAR_Y - 16" :x2="barX + totalW + 3" :y2="BAR_Y - 16" stroke="rgba(30,30,40,0.15)" stroke-width="1" />
        </template>
      </svg>

      <!-- Tie tab -->
      <svg v-else width="100%" :viewBox="`0 0 ${BAR_MAX_W + 20} ${SVG_H}`" style="display:block">
        <template v-if="showBarline">
          <line :x1="barlineX" :y1="BAR_Y - 8" :x2="barlineX" :y2="BAR_Y + BAR_H + 8"
            stroke="rgba(30,30,40,0.2)" stroke-width="1.5" />
          <text :x="barlineX - 4" :y="BAR_Y - 12" text-anchor="end"
            font-family="'DM Mono', monospace" font-size="7" fill="rgba(30,30,40,0.2)">bar 1</text>
          <text :x="barlineX + 4" :y="BAR_Y - 12" text-anchor="start"
            font-family="'DM Mono', monospace" font-size="7" fill="rgba(30,30,40,0.2)">bar 2</text>
        </template>

        <rect :x="xA" :y="BAR_Y" :width="wA" :height="BAR_H" rx="4" fill="#1e1e28" />
        <text :x="xA + wA / 2" :y="BAR_Y + BAR_H + 14" text-anchor="middle"
          font-family="'DM Mono', monospace" font-size="8" fill="rgba(30,30,40,0.35)"
        >{{ tieEx.noteA.name }}</text>

        <rect :x="xB" :y="BAR_Y" :width="wB" :height="BAR_H" rx="4" fill="rgba(30,30,40,0.25)" />
        <text :x="xB + wB / 2" :y="BAR_Y + BAR_H + 14" text-anchor="middle"
          font-family="'DM Mono', monospace" font-size="8" fill="rgba(30,30,40,0.25)"
        >{{ tieEx.noteB.name }}</text>

        <path
          :d="`M ${arcX1} ${arcY} Q ${arcMidX} ${arcControl} ${arcX2} ${arcY}`"
          fill="none" stroke="#f59e0b" stroke-width="2" stroke-linecap="round"
        />
        <text :x="(xA + xB + wB) / 2" :y="BAR_Y + BAR_H + 28" text-anchor="middle"
          font-family="'DM Mono', monospace" font-size="8" fill="#f59e0b"
        >= {{ tieEx.totalFraction === 1 ? '1 whole' : `${Math.round(tieEx.totalFraction * 8)}/8` }} note</text>
      </svg>
    </div>

    <!-- Dot toggle -->
    <div v-if="tab === 'dot'" class="dur-dot-toggle">
      <button class="dur-dot-btn" :class="{ on: showDot }" @click="showDot = !showDot">
        {{ showDot ? 'Dot on' : 'Add dot' }}
      </button>
    </div>

    <!-- Context label -->
    <div v-if="tab === 'tie'" class="dur-context-label">{{ tieEx.context }}</div>

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
  background: #ffffff;
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
  font-family: 'DM Mono', monospace; font-size: 0.6rem;
  letter-spacing: 0.15em; text-transform: uppercase; color: rgba(30,30,40,0.35);
}

.dur-tabs { display: flex; background: rgba(0,0,0,0.05); border-radius: 999px; padding: 2px; gap: 2px; }
.dur-tab {
  font-family: 'DM Mono', monospace; font-size: 0.58rem; letter-spacing: 0.08em;
  padding: 0.22rem 0.65rem; border-radius: 999px; border: none;
  background: transparent; color: rgba(30,30,40,0.4); cursor: pointer; transition: all 0.25s ease;
}
.dur-tab.active { background: #ffffff; color: rgba(30,30,40,0.8); box-shadow: 0 1px 4px rgba(0,0,0,0.1); }

.dur-visual {
  width: 100%;
  background: rgba(0,0,0,0.015);
  border: 1px solid rgba(0,0,0,0.05);
  border-radius: 0.75rem;
  overflow: hidden;
  transition: opacity 0.2s ease;
}

.dur-dot-toggle { width: 100%; display: flex; align-items: center; justify-content: center; gap: 0.6rem; }
.dur-dot-btn {
  font-family: 'DM Mono', monospace; font-size: 0.58rem; letter-spacing: 0.08em;
  padding: 0.25rem 0.85rem; border-radius: 999px;
  border: 1px solid rgba(0,0,0,0.12); background: transparent;
  color: rgba(30,30,40,0.4); cursor: pointer; transition: all 0.2s ease;
}
.dur-dot-btn.on { background: #f59e0b; border-color: #f59e0b; color: #ffffff; }

.dur-explanation {
  font-family: system-ui, sans-serif;
  font-size: 0.82rem; line-height: 1.65;
  color: rgba(30,30,40,0.5); text-align: center; padding: 0 0.25rem; min-height: 3.5rem;
}

.dur-nav { display: flex; align-items: center; gap: 1.25rem; }
.dur-arrow {
  background: none; border: 1px solid rgba(0,0,0,0.12); color: rgba(30,30,40,0.35);
  width: 32px; height: 32px; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  cursor: pointer; font-size: 0.85rem; transition: all 0.2s ease;
}
.dur-arrow:hover:not(:disabled) { border-color: rgba(0,0,0,0.25); color: rgba(30,30,40,0.7); }
.dur-arrow:disabled { opacity: 0.2; cursor: default; }

.dur-stepdots { display: flex; gap: 5px; align-items: center; }
.dur-stepdot { width: 5px; height: 5px; border-radius: 50%; background: rgba(0,0,0,0.12); transition: all 0.3s ease; }
.dur-stepdot.active { width: 14px; border-radius: 3px; background: #f59e0b; }

.dur-context-label {
  font-family: 'DM Mono', monospace; font-size: 0.55rem; letter-spacing: 0.12em;
  text-transform: uppercase; color: rgba(30,30,40,0.25); align-self: flex-start; padding-left: 0.25rem;
}

@keyframes durBarGrow { from { width: 0; opacity: 0; } to { opacity: 1; } }
@keyframes durDotPop  { 0% { transform:scale(0); opacity:0; } 65% { transform:scale(1.3); opacity:1; } 100% { transform:scale(1); opacity:1; } }
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
