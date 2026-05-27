<script setup lang="ts">
import { ref, computed } from 'vue'

type Role = 'root' | 'third' | 'fifth' | 'seventh'

interface Tone {
  role: Role
  /** semitones above the root */
  semi: number
  /** degree label shown beside the dot */
  label: string
  /** spoken interval name */
  name: string
}

interface Quality {
  slug: string
  /** pill label (uses unicode flats) */
  pill: string
  /** large chord symbol */
  symbol: string
  /** one-line brightness descriptor for the badge */
  mood: string
  /** brightness hue carried on the chrome (frame / symbol / glow) */
  hue: string
  /** 1-2 sentence explanation */
  blurb: string
  tones: Tone[]
}

// Bright -> dark. Each step lowers one or more colour tones a half-step.
// Root is fixed (orange); the 3rd/5th/7th descend as the chord darkens.
const QUALITIES: Quality[] = [
  {
    slug: 'maj7',
    pill: 'maj7',
    symbol: 'Cmaj7',
    mood: 'Brightest',
    hue: '#f0a020',
    blurb:
      'The major 7th. Stable, open, resolved \u2014 the home sound. Both the 3rd and the 7th sit high, giving it that lush, sunlit quality.',
    tones: [
      { role: 'root',    semi: 0,  label: '1', name: 'root' },
      { role: 'third',   semi: 4,  label: '3', name: 'major 3rd' },
      { role: 'fifth',   semi: 7,  label: '5', name: 'perfect 5th' },
      { role: 'seventh', semi: 11, label: '7', name: 'major 7th' },
    ],
  },
  {
    slug: 'dom7',
    pill: '7',
    symbol: 'C7',
    mood: 'Bright \u00b7 tense',
    hue: '#e05a20',
    blurb:
      'Drop the 7th a half-step. Still a major 3rd, but the \u266d7 adds restless tension \u2014 it wants to resolve. The engine of the dominant chord.',
    tones: [
      { role: 'root',    semi: 0,  label: '1',     name: 'root' },
      { role: 'third',   semi: 4,  label: '3',     name: 'major 3rd' },
      { role: 'fifth',   semi: 7,  label: '5',     name: 'perfect 5th' },
      { role: 'seventh', semi: 10, label: '\u266d7', name: 'minor 7th' },
    ],
  },
  {
    slug: 'm7',
    pill: 'm7',
    symbol: 'Cm7',
    mood: 'Neutral',
    hue: '#6458c8',
    blurb:
      'Lower the 3rd as well. Now both colour tones are minor \u2014 soft, mellow, neither happy nor sad. The ii of every ii\u2013V\u2013I.',
    tones: [
      { role: 'root',    semi: 0,  label: '1',      name: 'root' },
      { role: 'third',   semi: 3,  label: '\u266d3', name: 'minor 3rd' },
      { role: 'fifth',   semi: 7,  label: '5',      name: 'perfect 5th' },
      { role: 'seventh', semi: 10, label: '\u266d7', name: 'minor 7th' },
    ],
  },
  {
    slug: 'm7b5',
    pill: 'm7\u266d5',
    symbol: 'Cm7\u266d5',
    mood: 'Dark',
    hue: '#1e4a9e',
    blurb:
      'Flatten the 5th. The chord loses its footing \u2014 unstable and shadowy. The half-diminished sound, the ii of a minor key.',
    tones: [
      { role: 'root',    semi: 0,  label: '1',      name: 'root' },
      { role: 'third',   semi: 3,  label: '\u266d3', name: 'minor 3rd' },
      { role: 'fifth',   semi: 6,  label: '\u266d5', name: 'diminished 5th' },
      { role: 'seventh', semi: 10, label: '\u266d7', name: 'minor 7th' },
    ],
  },
  {
    slug: 'dim7',
    pill: 'dim7',
    symbol: 'C\u00b07',
    mood: 'Dark \u00b7 tense',
    hue: '#9b2040',
    blurb:
      'Lower the 7th once more. Every interval is stacked in minor 3rds \u2014 fully symmetrical, rootless, the darkest and most ambiguous sound of all.',
    tones: [
      { role: 'root',    semi: 0, label: '1',          name: 'root' },
      { role: 'third',   semi: 3, label: '\u266d3',     name: 'minor 3rd' },
      { role: 'fifth',   semi: 6, label: '\u266d5',     name: 'diminished 5th' },
      { role: 'seventh', semi: 9, label: '\u266d\u266d7', name: 'diminished 7th' },
    ],
  },
]

// ---- geometry -------------------------------------------------------------
const VIEW_W = 220
const VIEW_H = 300
const DOT_R = 16
const TOP_PAD = 34
const BOT_PAD = 34
const MAX_SEMI = 11 // major 7th is the highest tone in the set
const COL_X = 78  // dots sit left of centre; interval name labels extend right

function yFor(semi: number): number {
  // higher semitone -> higher on screen (smaller y)
  const usable = VIEW_H - TOP_PAD - BOT_PAD
  return TOP_PAD + usable * (1 - semi / MAX_SEMI)
}

const ROLE_TOKEN: Record<Role, string> = {
  root: 'var(--clr-role-root)',
  third: 'var(--clr-role-third)',
  fifth: 'var(--clr-role-fifth)',
  seventh: 'var(--clr-role-seventh)',
}

// ---- state ----------------------------------------------------------------
const activeIdx = ref(0)
const active = computed(() => QUALITIES[activeIdx.value])

// track which tones just changed pitch, to fire the pulse animation
const pulsing = ref<Record<Role, boolean>>({
  root: false,
  third: false,
  fifth: false,
  seventh: false,
})

function select(idx: number) {
  if (idx === activeIdx.value) return
  const prev = QUALITIES[activeIdx.value]
  const next = QUALITIES[idx]
  const moved: Record<Role, boolean> = {
    root: false,
    third: false,
    fifth: false,
    seventh: false,
  }
  for (const t of next.tones) {
    const before = prev.tones.find((p) => p.role === t.role)
    if (before && before.semi !== t.semi) moved[t.role] = true
  }
  pulsing.value = moved
  activeIdx.value = idx
  window.setTimeout(() => {
    pulsing.value = { root: false, third: false, fifth: false, seventh: false }
  }, 460)
}

// the gradient stops for the brightness rail, in pill order
const railStops = QUALITIES.map((q) => q.hue).join(', ')
</script>

<template>
  <div class="cq-widget" :style="{ '--cq-hue': active.hue }">
    <div class="cq-glow" :style="{ background: active.hue }" aria-hidden="true"></div>
    <!-- chord symbol + mood badge -->
    <header class="cq-head">
      <div class="cq-symbol">{{ active.symbol }}</div>
      <span class="cq-badge">{{ active.mood }}</span>
    </header>

    <!-- pitch-dot stack -->
    <svg
      class="cq-stage"
      :viewBox="`0 0 ${VIEW_W} ${VIEW_H}`"
      role="img"
      :aria-label="`${active.symbol}: ${active.tones.map((t) => t.name).join(', ')}`"
    >
      <!-- guide rail -->
      <line
        :x1="COL_X"
        :y1="yFor(MAX_SEMI) - DOT_R"
        :x2="COL_X"
        :y2="yFor(0) + DOT_R"
        class="cq-rail"
      />
      <!-- dots -->
      <g
        v-for="t in active.tones"
        :key="t.role"
        class="cq-dotg"
        :class="{ 'is-pulsing': pulsing[t.role] }"
        :style="{ transform: `translateY(${yFor(t.semi)}px)` }"
      >
        <text :x="COL_X - DOT_R - 14" y="0" class="cq-deg">{{ t.label }}</text>
        <circle
          :cx="COL_X"
          cy="0"
          :r="DOT_R"
          class="cq-dot"
          :style="{ fill: ROLE_TOKEN[t.role] }"
        />
        <text :x="COL_X + DOT_R + 12" y="0" class="cq-iname">{{ t.name }}</text>
      </g>
    </svg>

    <!-- brightness rail + pills -->
    <div class="cq-rail-track" :style="{ '--cq-rail': `linear-gradient(90deg, ${railStops})` }">
      <span class="cq-rail-end">bright</span>
      <span class="cq-rail-bar" aria-hidden="true"></span>
      <span class="cq-rail-end">dark</span>
    </div>

    <div class="cq-pills" role="tablist" aria-label="Chord quality, bright to dark">
      <button
        v-for="(q, i) in QUALITIES"
        :key="q.slug"
        class="cq-pill"
        :class="{ 'is-active': i === activeIdx }"
        role="tab"
        :aria-selected="i === activeIdx"
        @click="select(i)"
      >
        {{ q.pill }}
      </button>
    </div>

    <!-- explanation -->
    <p class="cq-blurb">{{ active.blurb }}</p>
  </div>
</template>

<style scoped>
.cq-widget {
  --cq-hue: var(--clr-accent);
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
.cq-glow {
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

/* ---- head ---------------------------------------------------------------- */
.cq-head {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.4rem;
}
.cq-symbol {
  font-family: 'Cormorant Garamond', 'Cormorant', Georgia, serif;
  font-size: 2.4rem;
  font-weight: 300;
  line-height: 1;
  color: var(--cq-hue);
  transition: color 0.35s ease;
}
.cq-badge {
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

/* ---- head + svg sit above the glow --------------------------------------- */
.cq-head,
.cq-stage,
.cq-rail-track,
.cq-pills,
.cq-blurb {
  position: relative;
  z-index: 1;
}

/* ---- svg stage ----------------------------------------------------------- */
.cq-stage {
  width: 100%;
  max-width: 240px;
  height: auto;
}
.cq-rail {
  stroke: rgba(255, 255, 255, 0.08);
  stroke-width: 2;
}
.cq-dotg {
  transition: transform 0.42s cubic-bezier(0.34, 1.2, 0.64, 1);
  animation: cq-popin 0.35s cubic-bezier(0.34, 1.4, 0.64, 1) both;
}
.cq-dot {
  transition: fill 0.35s ease;
}
.cq-dotg.is-pulsing .cq-dot {
  animation: cq-pulse 0.45s ease;
}
.cq-deg {
  fill: rgba(255, 255, 255, 0.85);
  font-size: 13px;
  font-weight: 700;
  font-family: 'DM Mono', monospace;
  text-anchor: end;
  dominant-baseline: central;
}
.cq-iname {
  fill: rgba(255, 255, 255, 0.55);
  font-size: 11px;
  font-family: 'DM Mono', monospace;
  text-anchor: start;
  dominant-baseline: central;
}

/* ---- brightness rail ----------------------------------------------------- */
.cq-rail-track {
  display: flex;
  align-items: center;
  gap: 8px;
  width: 100%;
  max-width: 240px;
}
.cq-rail-bar {
  flex: 1;
  height: 6px;
  border-radius: 999px;
  background: var(--cq-rail);
}
.cq-rail-end {
  font-family: 'DM Mono', monospace;
  font-size: 0.65rem;
  color: rgba(255, 255, 255, 0.65);
}

/* ---- pills --------------------------------------------------------------- */
.cq-pills {
  display: flex;
  flex-wrap: wrap;
  justify-content: center;
  gap: 6px;
}
.cq-pill {
  font-family: 'DM Mono', monospace;
  font-size: 0.65rem;
  font-weight: 500;
  padding: 0.32rem 0.75rem;
  border-radius: 999px;
  cursor: pointer;
  color: rgba(255, 255, 255, 0.6);
  background: rgba(255, 255, 255, 0.08);
  border: 1px solid rgba(255, 255, 255, 0.18);
  transition: background 0.15s, color 0.15s, border-color 0.15s;
}
.cq-pill:hover {
  color: rgba(255, 255, 255, 0.9);
  background: rgba(255, 255, 255, 0.12);
  border-color: rgba(255, 255, 255, 0.35);
}
.cq-pill.is-active {
  color: #0f0f17;
  background: rgba(255, 255, 255, 0.92);
  border-color: transparent;
}

/* ---- blurb --------------------------------------------------------------- */
.cq-blurb {
  margin: 0;
  font-size: 0.82rem;
  line-height: 1.6;
  text-align: center;
  color: rgba(255, 255, 255, 0.85);
  max-width: 300px;
  min-height: 4.2em;
}

/* ---- animations ---------------------------------------------------------- */
@keyframes cq-popin {
  0% { opacity: 0; }
  100% { opacity: 1; }
}
@keyframes cq-pulse {
  0% { transform: scale(1); }
  45% { transform: scale(1.35); }
  100% { transform: scale(1); }
}

@media (prefers-reduced-motion: reduce) {
  .cq-dotg,
  .cq-dot,
  .cq-symbol,
  .cq-badge {
    transition: none;
    animation: none;
  }
  .cq-dotg.is-pulsing .cq-dot {
    animation: none;
  }
}
</style>
