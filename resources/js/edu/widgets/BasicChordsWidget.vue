<script setup lang="ts">
import { computed, ref } from 'vue';

// ── Fretboard geometry (matches VoiceLeading / CAGED visual style) ────────────
const VIEW_W       = 260;
const VIEW_H       = 158;
const PAD_TOP      = 18;
const PAD_BOTTOM   = 18;
const PAD_LEFT     = 38;
const PAD_RIGHT    = 10;
const N_STRINGS    = 6;
const WINDOW_FRETS = 4;
const DOT_R        = 10;
const FRET_GAP     = (VIEW_W - PAD_LEFT - PAD_RIGHT) / WINDOW_FRETS;
const STRING_GAP   = (VIEW_H - PAD_TOP - PAD_BOTTOM) / (N_STRINGS - 1);

function stringY(s: number) { return PAD_TOP + (N_STRINGS - 1 - s) * STRING_GAP; }
function fretX(f: number)   { return PAD_LEFT + (f - 0.5) * FRET_GAP; }
function openX()            { return PAD_LEFT - 13; }

// ── Chord data ────────────────────────────────────────────────────────────────
// str: 0=low E … 5=high e  |  fret: 0=open, null=muted  |  fn: finger number

interface Dot {
  str: number;
  fret: number | null;
  fn?: number;          // finger number 1–4; omitted for open/muted
}

interface Chord {
  name: string;
  quality: string;
  dots: Dot[];
  caption: string;
}

const CHORDS: Chord[] = [
  {
    name: 'E', quality: 'major',
    dots: [
      { str: 0, fret: 0 },
      { str: 1, fret: 2, fn: 3 },
      { str: 2, fret: 2, fn: 2 },
      { str: 3, fret: 1, fn: 1 },
      { str: 4, fret: 0 },
      { str: 5, fret: 0 },
    ],
    caption: 'E major — all six strings. Root on low E. The E shape is also the foundation of the CAGED barre system.',
  },
  {
    name: 'Em', quality: 'minor',
    dots: [
      { str: 0, fret: 0 },
      { str: 1, fret: 2, fn: 2 },
      { str: 2, fret: 2, fn: 3 },
      { str: 3, fret: 0 },
      { str: 4, fret: 0 },
      { str: 5, fret: 0 },
    ],
    caption: 'E minor — all six strings, just two fingers. The 3rd (G♯) drops a semitone to G, making it minor.',
  },
  {
    name: 'A', quality: 'major',
    dots: [
      { str: 0, fret: null },
      { str: 1, fret: 0 },
      { str: 2, fret: 2, fn: 2 },
      { str: 3, fret: 2, fn: 3 },
      { str: 4, fret: 2, fn: 4 },
      { str: 5, fret: 0 },
    ],
    caption: 'A major — five strings, low E muted. Three fingers on the same fret. Root on the open A string.',
  },
  {
    name: 'Am', quality: 'minor',
    dots: [
      { str: 0, fret: null },
      { str: 1, fret: 0 },
      { str: 2, fret: 2, fn: 3 },
      { str: 3, fret: 2, fn: 2 },
      { str: 4, fret: 1, fn: 1 },
      { str: 5, fret: 0 },
    ],
    caption: 'A minor — five strings, low E muted. The C on string 4 (fret 1) is the minor 3rd that defines the colour.',
  },
  {
    name: 'D', quality: 'major',
    dots: [
      { str: 0, fret: null },
      { str: 1, fret: null },
      { str: 2, fret: 0 },
      { str: 3, fret: 2, fn: 1 },
      { str: 4, fret: 3, fn: 3 },
      { str: 5, fret: 2, fn: 2 },
    ],
    caption: 'D major — four strings, top two muted. Root on open D string. The triangle shape on the high strings is very characteristic.',
  },
  {
    name: 'Dm', quality: 'minor',
    dots: [
      { str: 0, fret: null },
      { str: 1, fret: null },
      { str: 2, fret: 0 },
      { str: 3, fret: 2, fn: 2 },
      { str: 4, fret: 3, fn: 3 },
      { str: 5, fret: 1, fn: 1 },
    ],
    caption: 'D minor — four strings. The F on high e (fret 1) replaces the F♯ of D major, lowering the 3rd a semitone.',
  },
  {
    name: 'C', quality: 'major',
    dots: [
      { str: 0, fret: null },
      { str: 1, fret: 3, fn: 3 },
      { str: 2, fret: 2, fn: 2 },
      { str: 3, fret: 0 },
      { str: 4, fret: 1, fn: 1 },
      { str: 5, fret: 0 },
    ],
    caption: 'C major — five strings, low E muted. The stretch from fret 1 to fret 3 makes this one of the trickier beginner shapes.',
  },
  {
    name: 'G', quality: 'major',
    dots: [
      { str: 0, fret: 3, fn: 2 },
      { str: 1, fret: 2, fn: 1 },
      { str: 2, fret: 0 },
      { str: 3, fret: 0 },
      { str: 4, fret: 0 },
      { str: 5, fret: 3, fn: 3 },
    ],
    caption: 'G major — all six strings. Roots on low E, high e, and the open G string. One of the richest-sounding open chords.',
  },
];

const idx = ref(0);
const chord = computed(() => CHORDS[idx.value]);

function dotCx(fret: number | null): number {
  if (fret === null) return -999;
  if (fret === 0) return openX();
  return fretX(fret);
}

const QUALITY_COLOR: Record<string, string> = {
  major: '#f59e0b',
  minor: '#3b82f6',
};

function dotFill(c: Chord): string {
  return QUALITY_COLOR[c.quality] ?? 'rgba(255,255,255,0.75)';
}
</script>

<template>
  <div class="bc-card">

    <!-- Header -->
    <div class="bc-header">
      <div class="bc-label">The Basic Eight</div>
    </div>

    <!-- Pills -->
    <div class="bc-pills">
      <button
        v-for="(c, i) in CHORDS"
        :key="c.name"
        :class="['bc-pill', i === idx ? 'active' : '']"
        @click="idx = i"
      >{{ c.name }}</button>
    </div>

    <!-- Fretboard -->
    <div class="bc-fb-wrap">
      <svg :viewBox="`0 0 ${VIEW_W} ${VIEW_H}`" width="100%" style="display:block">

        <!-- String labels -->
        <text v-for="(lbl, i) in ['E','A','D','G','B','e']" :key="'sl'+i"
          x="6" :y="stringY(i) + 4" text-anchor="middle"
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

        <!-- Muted strings -->
        <text v-for="dot in chord.dots.filter(d => d.fret === null)" :key="'mx'+dot.str"
          :x="PAD_LEFT - 8" :y="stringY(dot.str) + 4"
          text-anchor="middle" font-family="'DM Mono', monospace" font-size="9"
          fill="rgba(255,255,255,0.3)"
        >×</text>

        <!-- Dots -->
        <g v-for="dot in chord.dots.filter(d => d.fret !== null)" :key="'d'+dot.str"
          class="bc-dot-group"
          :style="{ transform: `translate(${dotCx(dot.fret)}px, ${stringY(dot.str)}px)` }"
        >
          <!-- Open string: light grey filled dot -->
          <circle v-if="dot.fret === 0"
            cx="0" cy="0" :r="DOT_R"
            fill="rgba(255,255,255,0.32)"
          />
          <!-- Fretted: filled dot -->
          <template v-else>
            <circle cx="0" cy="0" :r="DOT_R" :fill="dotFill(chord)" />
            <text x="0" y="1"
              text-anchor="middle" dominant-baseline="middle"
              font-family="'DM Mono', monospace" font-size="8" font-weight="700"
              fill="#0f0f17"
            >{{ dot.fn }}</text>
          </template>
        </g>

        <!-- Fret numbers -->
        <text v-for="f in [1,2,3,4]" :key="'fn'+f"
          :x="PAD_LEFT + (f - 0.5) * FRET_GAP" :y="VIEW_H - 3"
          text-anchor="middle" font-family="'DM Mono', monospace" font-size="7"
          fill="rgba(255,255,255,0.3)"
        >{{ f }}</text>

      </svg>
    </div>

    <!-- Chord name -->
    <div class="bc-chord-row">
      <div class="bc-chord-name">{{ chord.name }}</div>
      <div class="bc-chord-quality">{{ chord.quality }}</div>
    </div>

    <!-- Caption -->
    <div class="bc-caption">{{ chord.caption }}</div>

  </div>
</template>

<style scoped>
.bc-card {
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

.bc-header { width: 100%; display: flex; align-items: center; }
.bc-label { font-family: 'DM Mono', monospace; font-size: 0.65rem; letter-spacing: 0.15em; text-transform: uppercase; color: #ffffff; }

/* ── Pills ─────────────────────────────────────────────────────────────────── */
.bc-pills { display: grid; grid-template-columns: repeat(4, 1fr); gap: 6px; width: 100%; }
.bc-pill {
  font-family: 'DM Mono', monospace;
  font-size: 0.7rem;
  letter-spacing: 0.08em;
  padding: 0.32rem 0.85rem;
  border-radius: 999px;
  border: 1px solid rgba(255,255,255,0.12);
  background: rgba(255,255,255,0.08);
  color: #ffffff;
  cursor: pointer;
  transition: all 0.2s ease;
}
.bc-pill:hover { border-color: rgba(255,255,255,0.35); color: #ffffff; }
.bc-pill.active { background: #ffffff; color: #0f0f17; border-color: transparent; }

/* ── Fretboard ─────────────────────────────────────────────────────────────── */
.bc-fb-wrap {
  width: 100%;
  background: #0a0a12;
  border-radius: 0.75rem;
  border: 1px solid rgba(255,255,255,0.06);
  overflow: hidden;
}

.bc-dot-group {
  transition: transform 0.4s cubic-bezier(0.65, 0, 0.35, 1);
}

/* ── Chord name row ────────────────────────────────────────────────────────── */
.bc-chord-row {
  width: 100%;
  display: flex;
  align-items: baseline;
  gap: 0.75rem;
}
.bc-chord-name {
  font-family: 'Cormorant Garamond', serif;
  font-size: 2rem;
  font-weight: 300;
  color: #ffffff;
  line-height: 1;
}
.bc-chord-quality {
  font-family: 'DM Mono', monospace;
  font-size: 0.6rem;
  letter-spacing: 0.12em;
  text-transform: uppercase;
  color: rgba(255,255,255,0.4);
}

/* ── Caption ───────────────────────────────────────────────────────────────── */
.bc-caption {
  font-family: system-ui, sans-serif;
  font-size: 0.82rem;
  line-height: 1.6;
  color: #ffffff;
  min-height: 2.8rem;
}
</style>
