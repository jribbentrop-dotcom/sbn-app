<script setup lang="ts">
import { computed, ref } from 'vue';

// ── Fretboard geometry (matches Fretboard.vue visual style) ───────────────────
const VIEW_W      = 260;
const VIEW_H      = 158;
const PAD_TOP     = 18;
const PAD_BOTTOM  = 18;
const PAD_LEFT    = 28;   // space for string labels
const PAD_RIGHT   = 10;
const N_STRINGS   = 6;
const WINDOW_FRETS = 4;
const DOT_R       = 10;
const FRET_GAP    = (VIEW_W - PAD_LEFT - PAD_RIGHT) / WINDOW_FRETS;
const STRING_GAP  = (VIEW_H - PAD_TOP - PAD_BOTTOM) / (N_STRINGS - 1);

// str: 0=low E, 5=high e  |  fret: 0=open
function stringY(s: number) { return PAD_TOP + (N_STRINGS - 1 - s) * STRING_GAP; }
function fretX(f: number)    { return PAD_LEFT + (f - 0.5) * FRET_GAP; }  // dot centre between frets
function openX()             { return PAD_LEFT - 10; }                      // open string dot left of nut

// ── Chord function colors ─────────────────────────────────────────────────────
const FUNC_COLOR: Record<string, string> = {
  '1':  '#f59e0b',   // root — amber
  '3':  '#3b82f6',   // third — blue
  '5':  '#10b981',   // fifth — green
  '7':  '#8b5cf6',   // seventh — purple
  'b7': '#8b5cf6',
  'b9': '#ec4899',   // extension — pink
  'maj7': '#8b5cf6',
};

// ── Chord data ─────────────────────────────────────────────────────────────────
// str: 0=low E … 5=high e  |  fret: 0=open, null=muted
// func: chord function label shown in dot


const tabKey = ref<'classical' | 'jazz'>('classical');
const chordIdx = ref(0);  // 0 = first chord, 1 = second chord

interface DotDef {
  str: number;
  fret: number | null;  // null = muted/absent, 0 = open
  func: string;
  note: string;
  moving?: boolean;  // highlight as moving voice
}

interface ChordDef {
  name: string;
  dots: DotDef[];
}

const CLASSICAL: [ChordDef, ChordDef] = [
  {
    name: 'B7',
    dots: [
      { str: 0, fret: null, func: '×',  note: '' },
      { str: 1, fret: 2,   func: '1',  note: 'B'  },
      { str: 2, fret: 1,   func: '3',  note: 'D♯' },
      { str: 3, fret: 2,   func: 'b7', note: 'A'  },
      { str: 4, fret: 0,   func: '1',  note: 'B'  },
      { str: 5, fret: null, func: '×',  note: '' },
    ],
  },
  {
    name: 'E',
    dots: [
      { str: 0, fret: 0,   func: '1',  note: 'E'  },
      { str: 1, fret: 2,   func: '5',  note: 'B'  },
      { str: 2, fret: 2,   func: '1',  note: 'E'  },
      { str: 3, fret: 1,   func: '3',  note: 'G♯' },
      { str: 4, fret: 0,   func: '5',  note: 'B'  },
      { str: 5, fret: 0,   func: '1',  note: 'E'  },
    ],
  },
];

const JAZZ: [ChordDef, ChordDef] = [
  {
    name: 'B7♭9',
    dots: [
      { str: 0, fret: null, func: '×',  note: '' },
      { str: 1, fret: 2,   func: '1',  note: 'B'  },
      { str: 2, fret: 1,   func: '3',  note: 'D♯' },
      { str: 3, fret: 2,   func: 'b7', note: 'A'  },
      { str: 4, fret: 1,   func: 'b9', note: 'C'  },
      { str: 5, fret: null, func: '×',  note: '' },
    ],
  },
  {
    name: 'Emaj7',
    dots: [
      { str: 0, fret: 0,   func: '1',   note: 'E'  },
      { str: 2, fret: 1,   func: 'maj7',note: 'D♯' },
      { str: 3, fret: 1,   func: '3',   note: 'G♯' },
      { str: 4, fret: 0,   func: '5',   note: 'B'  },
    ],
  },
];

// Which strings are "moving" between the two chords in each tab
// Classical: str2 (D#→E), str3 (A→G#), str0 and str5 appear/disappear (bass)
const MOVING_CLASSICAL = new Set([0, 2, 3, 5]);
// Jazz: str3 (A→G#), str4 (C→B), str0 appears; str1 and str5 disappear
const MOVING_JAZZ = new Set([0, 1, 3, 4, 5]);

const currentChord = computed(() => (tabKey.value === 'classical' ? CLASSICAL : JAZZ)[chordIdx.value]);
const movingStrings = computed(() => tabKey.value === 'classical' ? MOVING_CLASSICAL : MOVING_JAZZ);

const captions: Record<string, [string, string]> = {
  classical: [
    'B7 — dominant 7th. Leading tone D♯ and ♭7 A want to resolve.',
    'E major — D♯ rises a semitone to E (leading tone), A falls to G♯ (♭7→3).',
  ],
  jazz: [
    'B7♭9 — adds the ♭9 (C) above the chord. Stronger tension.',
    'Emaj7 — D♯ stays as maj7, C falls to B (♭9→5). Colour tones extend the resolution.',
  ],
};

const caption = computed(() => captions[tabKey.value][chordIdx.value]);

function switchTab(key: 'classical' | 'jazz') {
  tabKey.value = key;
  chordIdx.value = 0;
}

function toggle() {
  chordIdx.value = chordIdx.value === 0 ? 1 : 0;
}

// Dot cx/cy — open strings sit left of nut, fretted sit at fret midpoint
function dotCx(fret: number | null): number {
  if (fret === null) return -999; // off-screen / muted
  if (fret === 0) return openX();
  return fretX(fret);
}
function dotCy(str: number): number {
  return stringY(str);
}
function dotColor(func: string): string {
  return FUNC_COLOR[func] ?? 'rgba(255,255,255,0.8)';
}
function dotTextColor(func: string): string {
  // dark text on light dots
  return '#0f0f17';
}
function isVisible(dot: DotDef): boolean {
  return dot.fret !== null && dot.func !== '×';
}
</script>

<template>
  <div class="vl-card">

    <!-- Header -->
    <div class="vl-header">
      <div class="vl-label">Voice Leading</div>
    </div>

    <!-- Tab selector -->
    <div class="vl-tabs">
      <button
        v-for="t in (['classical', 'jazz'] as const)"
        :key="t"
        :class="['vl-tab', tabKey === t ? 'active' : '']"
        @click="switchTab(t)"
      >{{ t === 'classical' ? 'Classical' : 'Jazz' }}</button>
    </div>

    <!-- Fretboard -->
    <div class="vl-fb-wrap">
      <svg :viewBox="`0 0 ${VIEW_W} ${VIEW_H}`" width="100%" style="display:block">

        <!-- String labels -->
        <text v-for="(lbl, i) in ['E','A','D','G','B','e']" :key="'sl'+i"
          x="10" :y="stringY(i) + 4" text-anchor="middle"
          font-family="'DM Mono', monospace" font-size="7" fill="rgba(255,255,255,0.5)"
        >{{ lbl }}</text>

        <!-- Nut -->
        <line :x1="PAD_LEFT" :y1="PAD_TOP" :x2="PAD_LEFT" :y2="VIEW_H - PAD_BOTTOM"
          stroke="rgba(255,255,255,0.55)" stroke-width="3" stroke-linecap="round"/>

        <!-- Fret lines -->
        <line v-for="f in WINDOW_FRETS" :key="'fl'+f"
          :x1="PAD_LEFT + f * FRET_GAP" :y1="PAD_TOP"
          :x2="PAD_LEFT + f * FRET_GAP" :y2="VIEW_H - PAD_BOTTOM"
          stroke="rgba(255,255,255,0.08)" stroke-width="1"/>

        <!-- String lines -->
        <line v-for="(sw, si) in [2, 1.6, 1.3, 1, 0.8, 0.8]" :key="'str'+si"
          :x1="PAD_LEFT - 18" :y1="stringY(si)"
          :x2="PAD_LEFT + WINDOW_FRETS * FRET_GAP" :y2="stringY(si)"
          stroke="rgba(255,255,255,0.2)" :stroke-width="sw" vector-effect="non-scaling-stroke"/>

        <!-- Mute markers -->
        <text v-for="dot in currentChord.dots.filter(d => d.fret === null)" :key="'mx'+dot.str"
          :x="PAD_LEFT - 8" :y="stringY(dot.str) + 4"
          text-anchor="middle" font-family="'DM Mono', monospace" font-size="9"
          fill="rgba(255,255,255,0.35)"
        >×</text>

        <!-- Animated dots — wrap in <g transform> so CSS transition works on SVG -->
        <g v-for="dot in currentChord.dots.filter(d => isVisible(d))" :key="dot.str"
          class="vl-dot-group"
          :style="{ transform: `translate(${dotCx(dot.fret)}px, ${dotCy(dot.str)}px)` }"
        >
          <circle
            cx="0" cy="0"
            :r="DOT_R"
            :fill="dotColor(dot.func)"
            :class="['vl-dot', movingStrings.has(dot.str) ? 'vl-dot--moving' : '']"
          />
          <text
            x="0" y="1"
            text-anchor="middle"
            dominant-baseline="middle"
            font-family="'DM Mono', monospace"
            :font-size="dot.func.length > 2 ? 6 : 8"
            font-weight="700"
            :fill="dotTextColor(dot.func)"
            class="vl-dot-label"
          >{{ dot.func }}</text>
        </g>

        <!-- Fret numbers -->
        <text v-for="f in [1,2,3,4]" :key="'fn'+f"
          :x="PAD_LEFT + (f - 0.5) * FRET_GAP" :y="VIEW_H - 3"
          text-anchor="middle" font-family="'DM Mono', monospace" font-size="7"
          fill="rgba(255,255,255,0.3)"
        >{{ f }}</text>

      </svg>
    </div>

    <!-- Chord name + toggle -->
    <div class="vl-chord-row">
      <div class="vl-chord-name">{{ currentChord.name }}</div>
      <button class="vl-toggle" @click="toggle">
        {{ chordIdx === 0 ? 'Resolve →' : '← Back' }}
      </button>
    </div>

    <!-- Caption -->
    <div class="vl-caption">{{ caption }}</div>

  </div>
</template>

<style scoped>
.vl-card {
  width: 100%;
  background: #0f0f17;
  border-radius: 1.25rem;
  border: 1px solid rgba(255,255,255,0.06);
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 1.75rem 1.5rem 1.5rem;
  gap: 1rem;
  user-select: none;
}

.vl-header { width: 100%; display: flex; align-items: center; }
.vl-label { font-family: 'DM Mono', monospace; font-size: 0.65rem; letter-spacing: 0.15em; text-transform: uppercase; color: #ffffff; }

/* ── Tabs ──────────────────────────────────────────────────────────────────── */
.vl-tabs { display: flex; gap: 4px; }
.vl-tab {
  font-family: 'DM Mono', monospace;
  font-size: 0.6rem;
  letter-spacing: 0.08em;
  padding: 0.25rem 0.7rem;
  border-radius: 999px;
  border: 1px solid rgba(255,255,255,0.12);
  background: transparent;
  color: rgba(255,255,255,0.5);
  cursor: pointer;
  transition: all 0.2s ease;
}
.vl-tab:hover { border-color: rgba(255,255,255,0.25); color: #ffffff; }
.vl-tab.active { background: rgba(255,255,255,0.08); color: #ffffff; border-color: rgba(255,255,255,0.22); }

/* ── Fretboard ─────────────────────────────────────────────────────────────── */
.vl-fb-wrap {
  width: 100%;
  background: #0a0a12;
  border-radius: 0.75rem;
  border: 1px solid rgba(255,255,255,0.06);
  overflow: hidden;
}

/* Dot transition — the <g> translates smoothly when chordIdx changes */
.vl-dot-group {
  transition: transform 0.5s cubic-bezier(0.65, 0, 0.35, 1);
}
.vl-dot {
  transition: fill 0.35s ease;
}
.vl-dot-label {
  pointer-events: none;
}

/* Moving voices pulse subtly on arrival */
.vl-dot--moving {
  filter: drop-shadow(0 0 4px currentColor);
}

/* ── Chord row ─────────────────────────────────────────────────────────────── */
.vl-chord-row {
  width: 100%;
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.vl-chord-name {
  font-family: 'Cormorant Garamond', serif;
  font-size: 1.8rem;
  font-weight: 300;
  color: #ffffff;
  line-height: 1;
}
.vl-toggle {
  font-family: 'DM Mono', monospace;
  font-size: 0.6rem;
  letter-spacing: 0.1em;
  padding: 0.3rem 0.8rem;
  border-radius: 999px;
  border: 1px solid rgba(255,255,255,0.2);
  background: rgba(255,255,255,0.06);
  color: #ffffff;
  cursor: pointer;
  transition: all 0.2s ease;
}
.vl-toggle:hover { background: rgba(255,255,255,0.12); border-color: rgba(255,255,255,0.35); }

/* ── Caption ───────────────────────────────────────────────────────────────── */
.vl-caption {
  font-family: system-ui, sans-serif;
  font-size: 0.82rem;
  line-height: 1.6;
  color: #ffffff;
  text-align: center;
  min-height: 2.6rem;
}
</style>
