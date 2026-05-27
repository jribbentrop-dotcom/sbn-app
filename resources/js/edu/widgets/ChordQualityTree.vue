<script setup lang="ts">
/**
 * chord-quality-tree
 * Shows how two triads branch into five 7th-chord qualities.
 * Major triad → maj7, dom7
 * Minor triad → m7, m7♭5, dim7
 *
 * Step 0: the parent triad (root + 3rd + 5th).
 * Step 1: the chosen 7th chord adds/adjusts the 7th (and sometimes the 5th).
 * Selecting a quality auto-advances to step 1 and slides the dots.
 */
import { ref, computed } from 'vue'

type QSlug = 'maj7' | 'dom7' | 'm7' | 'm7b5' | 'dim7'

interface Tone { role: string; semi: number; label: string }
interface Quality {
  slug: QSlug
  pill: string
  symbol: string
  parent: 'major' | 'minor'
  hue: string
  blurb: string
  triad: Tone[]   // step 0 — the parent triad
  seventh: Tone[] // step 1 — full 7th chord
}

const QUALITIES: Quality[] = [
  {
    slug: 'maj7',
    pill: 'maj7',
    symbol: 'Cmaj7',
    parent: 'major',
    hue: '#f0a020',
    blurb: 'Keep the major 7th high. The triad stays bright — the 7th resolves upward into the octave, giving that lush, suspended quality.',
    triad:   [
      { role: 'root',  semi: 0,  label: '1' },
      { role: 'third', semi: 4,  label: '3' },
      { role: 'fifth', semi: 7,  label: '5' },
    ],
    seventh: [
      { role: 'root',    semi: 0,  label: '1' },
      { role: 'third',   semi: 4,  label: '3' },
      { role: 'fifth',   semi: 7,  label: '5' },
      { role: 'seventh', semi: 11, label: '7' },
    ],
  },
  {
    slug: 'dom7',
    pill: '7',
    symbol: 'C7',
    parent: 'major',
    hue: '#e05a20',
    blurb: 'Lower the 7th a half-step. The major 3rd stays, but the ♭7 creates a tritone — the chord wants to resolve. The engine of the V chord.',
    triad:   [
      { role: 'root',  semi: 0, label: '1' },
      { role: 'third', semi: 4, label: '3' },
      { role: 'fifth', semi: 7, label: '5' },
    ],
    seventh: [
      { role: 'root',    semi: 0,  label: '1' },
      { role: 'third',   semi: 4,  label: '3' },
      { role: 'fifth',   semi: 7,  label: '5' },
      { role: 'seventh', semi: 10, label: '♭7' },
    ],
  },
  {
    slug: 'm7',
    pill: 'm7',
    symbol: 'Cm7',
    parent: 'minor',
    hue: '#6458c8',
    blurb: 'Add a minor 7th to the minor triad. Both colour tones are lowered — soft and mellow, the ii chord in every ii–V–I.',
    triad:   [
      { role: 'root',  semi: 0, label: '1' },
      { role: 'third', semi: 3, label: '♭3' },
      { role: 'fifth', semi: 7, label: '5' },
    ],
    seventh: [
      { role: 'root',    semi: 0,  label: '1' },
      { role: 'third',   semi: 3,  label: '♭3' },
      { role: 'fifth',   semi: 7,  label: '5' },
      { role: 'seventh', semi: 10, label: '♭7' },
    ],
  },
  {
    slug: 'm7b5',
    pill: 'm7♭5',
    symbol: 'Cm7♭5',
    parent: 'minor',
    hue: '#1e4a9e',
    blurb: 'Flatten the 5th. The chord loses its footing — unstable, shadowy, the half-diminished sound. The ii of a minor ii–V–I.',
    triad:   [
      { role: 'root',  semi: 0, label: '1' },
      { role: 'third', semi: 3, label: '♭3' },
      { role: 'fifth', semi: 7, label: '5' },
    ],
    seventh: [
      { role: 'root',    semi: 0,  label: '1' },
      { role: 'third',   semi: 3,  label: '♭3' },
      { role: 'fifth',   semi: 6,  label: '♭5' },
      { role: 'seventh', semi: 10, label: '♭7' },
    ],
  },
  {
    slug: 'dim7',
    pill: 'dim7',
    symbol: 'C°7',
    parent: 'minor',
    hue: '#9b2040',
    blurb: 'Lower the 7th to a diminished 7th. All four intervals are stacked minor thirds — fully symmetrical, rootless, maximum tension.',
    triad:   [
      { role: 'root',  semi: 0, label: '1' },
      { role: 'third', semi: 3, label: '♭3' },
      { role: 'fifth', semi: 6, label: '♭5' },
    ],
    seventh: [
      { role: 'root',    semi: 0, label: '1' },
      { role: 'third',   semi: 3, label: '♭3' },
      { role: 'fifth',   semi: 6, label: '♭5' },
      { role: 'seventh', semi: 9, label: '♭♭7' },
    ],
  },
]

// ---- geometry ---------------------------------------------------------------
const VIEW_W  = 220
const VIEW_H  = 280
const DOT_R   = 15
const TOP_PAD = 30
const BOT_PAD = 30
const MAX_SEMI = 11
const COL_X   = 78

function yFor(semi: number): number {
  const usable = VIEW_H - TOP_PAD - BOT_PAD
  return TOP_PAD + usable * (1 - semi / MAX_SEMI)
}

const ROLE_TOKEN: Record<string, string> = {
  root:    'var(--clr-role-root)',
  third:   'var(--clr-role-third)',
  fifth:   'var(--clr-role-fifth)',
  seventh: 'var(--clr-role-seventh)',
}

// ---- state ------------------------------------------------------------------
const activeSlug = ref<QSlug>('maj7')
const step = ref<0 | 1>(0) // 0 = triad, 1 = seventh chord

const active   = computed(() => QUALITIES.find(q => q.slug === activeSlug.value)!)
const tones    = computed(() => step.value === 0 ? active.value.triad : active.value.seventh)
const hue      = computed(() => active.value.hue)

// pulse tracking
const pulsing = ref<Set<string>>(new Set())

function selectQuality(slug: QSlug) {
  const prev = active.value
  const next  = QUALITIES.find(q => q.slug === slug)!
  activeSlug.value = slug

  // if already at step 1, pulse changed tones
  if (step.value === 1) {
    const moved = new Set<string>()
    for (const t of next.seventh) {
      const before = prev.seventh.find(p => p.role === t.role)
      if (!before || before.semi !== t.semi) moved.add(t.role)
    }
    pulsing.value = moved
    window.setTimeout(() => { pulsing.value = new Set() }, 460)
  } else {
    step.value = 1
  }
}

function setStep(s: 0 | 1) {
  step.value = s
}

const majorQualities = QUALITIES.filter(q => q.parent === 'major')
const minorQualities = QUALITIES.filter(q => q.parent === 'minor')
</script>

<template>
  <div class="qt-widget" :style="{ '--qt-hue': hue }">
    <div class="qt-glow" :style="{ background: hue }" aria-hidden="true"></div>

    <!-- chord symbol -->
    <header class="qt-head">
      <div class="qt-symbol">{{ step === 0 ? (active.parent === 'major' ? 'C' : 'Cm') : active.symbol }}</div>
      <span class="qt-badge">{{ step === 0 ? (active.parent === 'major' ? 'Major triad' : 'Minor triad') : active.pill }}</span>
    </header>

    <!-- pitch-dot stack -->
    <svg
      class="qt-stage"
      :viewBox="`0 0 ${VIEW_W} ${VIEW_H}`"
      role="img"
      :aria-label="active.symbol"
    >
      <line
        :x1="COL_X" :y1="yFor(MAX_SEMI) - DOT_R"
        :x2="COL_X" :y2="yFor(0) + DOT_R"
        class="qt-rail"
      />
      <g
        v-for="t in tones"
        :key="t.role"
        class="qt-dotg"
        :class="{ 'is-pulsing': pulsing.has(t.role) }"
        :style="{ transform: `translateY(${yFor(t.semi)}px)` }"
      >
        <text :x="COL_X - DOT_R - 12" y="0" class="qt-deg">{{ t.label }}</text>
        <circle :cx="COL_X" cy="0" :r="DOT_R" class="qt-dot" :style="{ fill: ROLE_TOKEN[t.role] }" />
        <text :x="COL_X + DOT_R + 10" y="0" class="qt-iname">{{ t.role }}</text>
      </g>
    </svg>

    <!-- triad / 7th step toggle -->
    <div class="qt-step-row" role="tablist" aria-label="Chord step">
      <button class="qt-step-pill" :class="{ 'is-active': step === 0 }" role="tab" :aria-selected="step === 0" @click="setStep(0)">Triad</button>
      <button class="qt-step-pill" :class="{ 'is-active': step === 1 }" role="tab" :aria-selected="step === 1" @click="setStep(1)">7th chord</button>
    </div>

    <!-- quality selectors, grouped by parent triad -->
    <div class="qt-groups">
      <div class="qt-group">
        <span class="qt-group-label">major</span>
        <div class="qt-pills" role="tablist" aria-label="Major-family qualities">
          <button
            v-for="q in majorQualities" :key="q.slug"
            class="qt-pill"
            :class="{ 'is-active': activeSlug === q.slug }"
            role="tab" :aria-selected="activeSlug === q.slug"
            @click="selectQuality(q.slug)"
          >{{ q.pill }}</button>
        </div>
      </div>
      <div class="qt-group">
        <span class="qt-group-label">minor</span>
        <div class="qt-pills" role="tablist" aria-label="Minor-family qualities">
          <button
            v-for="q in minorQualities" :key="q.slug"
            class="qt-pill"
            :class="{ 'is-active': activeSlug === q.slug }"
            role="tab" :aria-selected="activeSlug === q.slug"
            @click="selectQuality(q.slug)"
          >{{ q.pill }}</button>
        </div>
      </div>
    </div>

    <!-- blurb -->
    <p class="qt-blurb">{{ step === 0 ? (active.parent === 'major' ? 'The major triad — root, major 3rd, perfect 5th. Bright and stable, the starting point for maj7 and dom7.' : 'The minor triad — root, minor 3rd, perfect 5th. Darker and more introspective, the seed of m7, m7♭5, and dim7.') : active.blurb }}</p>
  </div>
</template>

<style scoped>
.qt-widget {
  --qt-hue: #f0a020;
  position: relative;
  overflow: hidden;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.75rem;
  padding: 1rem;
  background: #0f0f17;
  font-family: var(--font-body, system-ui, sans-serif);
}

.qt-glow {
  position: absolute;
  top: -40px;
  left: 50%;
  transform: translateX(-50%);
  width: 220px;
  height: 220px;
  border-radius: 50%;
  pointer-events: none;
  filter: blur(60px);
  opacity: 0.35;
  transition: background 0.55s ease;
  z-index: 0;
}

/* ---- all content above glow ---------------------------------------------- */
.qt-head, .qt-stage, .qt-step-row, .qt-groups, .qt-blurb {
  position: relative;
  z-index: 1;
}

/* ---- head ----------------------------------------------------------------- */
.qt-head {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.4rem;
}
.qt-symbol {
  font-family: 'Cormorant Garamond', 'Cormorant', Georgia, serif;
  font-size: 2.4rem;
  font-weight: 300;
  line-height: 1;
  color: var(--qt-hue);
  transition: color 0.35s ease;
}
.qt-badge {
  font-family: 'DM Mono', monospace;
  font-size: 0.65rem;
  font-weight: 500;
  letter-spacing: 0.03em;
  padding: 0.2rem 0.7rem;
  border-radius: 999px;
  color: rgba(255, 255, 255, 0.65);
  border: 1px solid rgba(255, 255, 255, 0.18);
  background: rgba(255, 255, 255, 0.08);
  transition: color 0.35s ease, background 0.35s ease, border-color 0.35s ease;
}

/* ---- svg ------------------------------------------------------------------ */
.qt-stage {
  width: 100%;
  max-width: 240px;
  height: auto;
}
.qt-rail {
  stroke: rgba(255, 255, 255, 0.08);
  stroke-width: 2;
}
.qt-dotg {
  transition: transform 0.42s cubic-bezier(0.34, 1.2, 0.64, 1);
  animation: qt-popin 0.35s cubic-bezier(0.34, 1.4, 0.64, 1) both;
}
.qt-dot { transition: fill 0.35s ease; }
.qt-dotg.is-pulsing .qt-dot { animation: qt-pulse 0.45s ease; }
.qt-deg {
  fill: rgba(255, 255, 255, 0.85);
  font-size: 12px;
  font-weight: 700;
  font-family: 'DM Mono', monospace;
  text-anchor: end;
  dominant-baseline: central;
}
.qt-iname {
  fill: rgba(255, 255, 255, 0.55);
  font-size: 11px;
  font-family: 'DM Mono', monospace;
  text-anchor: start;
  dominant-baseline: central;
}

/* ---- step toggle ---------------------------------------------------------- */
.qt-step-row {
  display: flex;
  gap: 4px;
}
.qt-step-pill {
  font-family: 'DM Mono', monospace;
  font-size: 0.65rem;
  font-weight: 500;
  padding: 0.32rem 0.85rem;
  border-radius: 999px;
  cursor: pointer;
  color: rgba(255, 255, 255, 0.6);
  background: rgba(255, 255, 255, 0.08);
  border: 1px solid rgba(255, 255, 255, 0.18);
  transition: background 0.15s, color 0.15s, border-color 0.15s;
}
.qt-step-pill:hover {
  color: rgba(255, 255, 255, 0.9);
  background: rgba(255, 255, 255, 0.12);
  border-color: rgba(255, 255, 255, 0.35);
}
.qt-step-pill.is-active {
  color: #0f0f17;
  background: rgba(255, 255, 255, 0.92);
  border-color: transparent;
}

/* ---- quality groups ------------------------------------------------------- */
.qt-groups {
  display: flex;
  flex-direction: column;
  gap: 0.45rem;
  width: 100%;
  max-width: 300px;
}
.qt-group {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}
.qt-group-label {
  font-family: 'DM Mono', monospace;
  font-size: 0.62rem;
  letter-spacing: 0.08em;
  color: rgba(255, 255, 255, 0.4);
  width: 2.8rem;
  flex-shrink: 0;
  text-align: right;
}
.qt-pills {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
}
.qt-pill {
  font-family: 'DM Mono', monospace;
  font-size: 0.65rem;
  font-weight: 500;
  padding: 0.28rem 0.65rem;
  border-radius: 999px;
  cursor: pointer;
  color: rgba(255, 255, 255, 0.6);
  background: rgba(255, 255, 255, 0.08);
  border: 1px solid rgba(255, 255, 255, 0.18);
  transition: background 0.15s, color 0.15s, border-color 0.15s;
}
.qt-pill:hover {
  color: rgba(255, 255, 255, 0.9);
  background: rgba(255, 255, 255, 0.12);
  border-color: rgba(255, 255, 255, 0.35);
}
.qt-pill.is-active {
  color: #0f0f17;
  background: rgba(255, 255, 255, 0.92);
  border-color: transparent;
}

/* ---- blurb ---------------------------------------------------------------- */
.qt-blurb {
  margin: 0;
  font-size: 0.82rem;
  line-height: 1.6;
  text-align: center;
  color: rgba(255, 255, 255, 0.85);
  max-width: 300px;
  min-height: 4.2em;
}

/* ---- animations ----------------------------------------------------------- */
@keyframes qt-popin {
  0%   { opacity: 0; }
  100% { opacity: 1; }
}
@keyframes qt-pulse {
  0%   { transform: scale(1); }
  45%  { transform: scale(1.35); }
  100% { transform: scale(1); }
}

@media (prefers-reduced-motion: reduce) {
  .qt-dotg, .qt-dot, .qt-symbol, .qt-badge { transition: none; animation: none; }
  .qt-dotg.is-pulsing .qt-dot { animation: none; }
}
</style>
