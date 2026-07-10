<script setup lang="ts">
import { ref, computed } from 'vue';

type Symbol = 'whole' | 'half' | 'quarter' | 'eighth' | 'sixteenth';

interface Duration {
  name: string;
  beats: number;
  fraction: number;
  symbol: Symbol;
  explanation: string;
}

const DURATIONS: Duration[] = [
  { name: 'Whole Note',     beats: 4,    fraction: 1,      symbol: 'whole',
    explanation: 'Four beats. The longest common note value — fill an entire 4/4 bar with a single sound. Rarely seen in Jazz melody but common in long held chords.' },
  { name: 'Half Note',      beats: 2,    fraction: 0.5,    symbol: 'half',
    explanation: 'Two beats. Half a whole note. Common in slow ballads and as held chord tones. Two half notes fill a 4/4 bar.' },
  { name: 'Quarter Note',   beats: 1,    fraction: 0.25,   symbol: 'quarter',
    explanation: 'One beat — the basic pulse unit in most music. In 4/4 there are four quarter notes per bar. The foundation of all rhythm counting.' },
  { name: 'Eighth Note',    beats: 0.5,  fraction: 0.125,  symbol: 'eighth',
    explanation: 'Half a beat. Two eighth notes equal one quarter. In swing feel, eighth notes are played unevenly — long-short. The heartbeat of Jazz phrasing.' },
  { name: 'Sixteenth Note', beats: 0.25, fraction: 0.0625, symbol: 'sixteenth',
    explanation: 'A quarter of a beat. Four sixteenths per quarter note. Common in funk, fast passages, and ornaments. In Bossa Nova, the right-hand guitar patterns often imply sixteenth subdivisions.' },
];

// SMuFL (Bravura) glyphs — the same font and flag codepoints the tab editor
// uses (see tab-editor/utils/constants.js). Noteheads use the standard SMuFL
// notehead range; stems stay plain SVG lines, matching how the tab renders
// them (SMuFL has no "stem" glyph — engravers always draw it as a line).
const NOTEHEAD: Record<Symbol, string> = {
  whole: '', half: '', quarter: '', eighth: '', sixteenth: '',
};
const FLAG: Partial<Record<Symbol, string>> = {
  eighth: '', sixteenth: '',
};
const HAS_STEM: Record<Symbol, boolean> = {
  whole: false, half: true, quarter: true, eighth: true, sixteenth: true,
};
const INK = '#333';

const idx = ref(0);
const current = computed(() => DURATIONS[idx.value]);

// ── Hero note geometry ──────────────────────────────────────────────────────
// Rather than guessing the notehead glyph's exact ink width (SMuFL fonts
// don't expose their anchor metadata to plain CSS/SVG), the stem starts
// dead-centre on the notehead's own anchor point — guaranteed to sit inside
// the glyph's ink for any reasonably round notehead — with only a small
// fixed rightward nudge, then runs well past the top of the glyph.
const HERO_CX = 38;
const HERO_CY = 64;
const noteheadFont = computed(() => current.value.symbol === 'whole' ? 60 : 56);
const stemX  = HERO_CX + 7;
const stemY1 = HERO_CY;
const stemY2 = 14;
const flagFont = computed(() => noteheadFont.value * 0.72);

function countInBar(fraction: number) { return Math.round(4 / (fraction * 4)); }

function beatLabel(beats: number) {
  if (beats === 1) return '1 beat';
  if (beats < 1) return `${beats * 4}/4 of a beat`;
  return `${beats} beats`;
}

// ── Bar view: the rhythm-player's cell language (RhythmStrip.vue) ──────────
// One bar = 16 sixteenth-note cells. Every Nth cell is this duration's
// attack; the rest shrink to a thin rest nub — exactly how RhythmStrip shows
// a coarse pulse against its finest grid.
const CELLS_PER_BAR = 16;
const cellsPerHit = computed(() => CELLS_PER_BAR / countInBar(current.value.fraction));
function isHit(i: number) { return i % cellsPerHit.value === 0; }

const direction = ref<'left' | 'right' | null>(null);
const visible = ref(true);
const animating = ref(false);
let touchStart: number | null = null;

function nav(dir: 'left' | 'right') {
  if (animating.value) return;
  const next = dir === 'right'
    ? Math.min(idx.value + 1, DURATIONS.length - 1)
    : Math.max(idx.value - 1, 0);
  if (next === idx.value) return;
  direction.value = dir;
  animating.value = true;
  visible.value = false;
  setTimeout(() => {
    idx.value = next;
    visible.value = true;
    setTimeout(() => { animating.value = false; }, 350);
  }, 210);
}

function onTouchStart(e: TouchEvent) { touchStart = e.touches[0].clientX; }
function onTouchEnd(e: TouchEvent) {
  if (touchStart === null) return;
  const delta = touchStart - e.changedTouches[0].clientX;
  if (Math.abs(delta) > 40) nav(delta > 0 ? 'right' : 'left');
  touchStart = null;
}

function animClass() {
  if (!visible.value) return direction.value === 'right' ? 'nd-exit-left' : 'nd-exit-right';
  if (direction.value === 'right') return 'nd-enter-right';
  if (direction.value === 'left') return 'nd-enter-left';
  return '';
}
</script>

<template>
  <div class="nd-card" @touchstart="onTouchStart" @touchend="onTouchEnd">
      <div class="nd-header">
        <div class="nd-label">Note Values</div>
      </div>

      <div :class="['nd-content', animClass()]">
        <div class="nd-note-row">
          <!-- Hero note: real SMuFL notehead + stem + flag -->
          <svg width="96" height="108" viewBox="0 0 96 108" class="nd-hero-svg">
            <text :x="HERO_CX" :y="HERO_CY" text-anchor="middle" dominant-baseline="central"
              font-family="Bravura" :font-size="noteheadFont" :fill="INK"
            >{{ NOTEHEAD[current.symbol] }}</text>
            <line v-if="HAS_STEM[current.symbol]" :x1="stemX" :y1="stemY1" :x2="stemX" :y2="stemY2"
              :stroke="INK" stroke-width="2.2" stroke-linecap="round" />
            <text v-if="FLAG[current.symbol]" :x="stemX" :y="stemY2" font-family="Bravura" :font-size="flagFont" :fill="INK"
            >{{ FLAG[current.symbol] }}</text>
          </svg>

          <div>
            <div class="nd-title">{{ current.name }}</div>
            <div class="nd-meta">{{ beatLabel(current.beats) }}</div>
            <div class="nd-meta" style="margin-top:0.15rem">= {{ countInBar(current.fraction) }} per 4/4 bar</div>
          </div>
        </div>

        <!-- Bar view: rhythm-player cell grid -->
        <div class="nd-bar-wrap">
          <div class="nd-bar-label">One bar of 4/4</div>
          <div class="nd-cellrow">
            <span v-for="i in CELLS_PER_BAR" :key="i - 1" class="nd-cell" :class="isHit(i - 1) ? 'is-hit' : 'is-rest'" />
          </div>
        </div>

        <div class="nd-explanation">{{ current.explanation }}</div>
      </div>

      <nav class="nd-nav">
        <button class="nd-arrow" @click="nav('left')" :disabled="idx === 0">‹</button>
        <div class="nd-stepdots">
          <div v-for="(_, i) in DURATIONS" :key="i" :class="['nd-stepdot', i === idx ? 'active' : '']" />
        </div>
        <button class="nd-arrow" @click="nav('right')" :disabled="idx === DURATIONS.length - 1">›</button>
      </nav>
  </div>
</template>

<style scoped>
.nd-card {
  width: 100%;
  background: var(--clr-surface, #ffffff);
  border: 1px solid var(--clr-border, #e8edf3);
  border-radius: 0.875rem;
  display: flex; flex-direction: column; align-items: center;
  padding: 1.75rem 1.5rem 1.5rem; gap: 1.25rem; user-select: none;
}
.nd-header { width: 100%; display: flex; align-items: center; justify-content: space-between; }
.nd-label {
  font-family: 'DM Mono', monospace; font-size: 0.65rem; letter-spacing: 0.15em;
  text-transform: uppercase; color: var(--clr-text-muted, #64748b);
}
.nd-content { width: 100%; display: flex; flex-direction: column; align-items: center; gap: 1rem; transition: opacity 0.2s ease, transform 0.2s ease; }
.nd-note-row { display: flex; align-items: center; gap: 1.25rem; width: 100%; }
.nd-hero-svg { flex-shrink: 0; }
.nd-title { font-family: 'Cormorant Garamond', serif; font-size: 2rem; font-weight: 300; color: var(--clr-text, #1a1a2e); line-height: 1; }
.nd-meta { font-family: 'DM Mono', monospace; font-size: 0.6rem; letter-spacing: 0.1em; color: var(--clr-text-muted, #64748b); margin-top: 0.35rem; }

.nd-bar-wrap {
  width: 100%;
  background: var(--clr-surface-3, #f8fafc);
  border: 1px solid var(--clr-border, #e8edf3);
  border-radius: 0.5rem;
  padding: 0.65rem 0.65rem 0.75rem;
}
.nd-bar-label {
  font-family: 'DM Mono', monospace; font-size: 0.55rem; letter-spacing: 0.12em;
  text-transform: uppercase; color: var(--clr-text-muted, #64748b); margin-bottom: 0.4rem;
}

/* Rhythm-player cell grid — same grammar as RhythmStrip.vue's step cells. */
.nd-cellrow { display: grid; grid-auto-flow: column; grid-auto-columns: 1fr; gap: 3px; width: 100%; }
.nd-cell { height: 20px; border-radius: 3px; background: var(--clr-border, #e2e8f0); align-self: center; }
.nd-cell.is-rest { height: 6px; }
.nd-cell.is-hit { height: 20px; background: var(--clr-accent, #f39c12); opacity: 0.85; }

.nd-explanation { font-family: system-ui, sans-serif; font-size: 0.85rem; line-height: 1.6; color: var(--clr-text, #1a1a2e); min-height: 4rem; }
.nd-nav { display: flex; align-items: center; gap: 1.25rem; }
.nd-arrow {
  background: var(--clr-surface-3, #eef1f5); border: 1px solid var(--clr-border, #e8edf3); color: var(--clr-text-muted, #64748b);
  width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center;
  cursor: pointer; font-size: 0.85rem; transition: all 0.2s ease;
}
.nd-arrow:hover:not(:disabled) { border-color: var(--clr-accent-border, rgba(243,156,18,0.35)); color: var(--clr-text, #1a1a2e); }
.nd-arrow:disabled { opacity: 0.3; cursor: default; }
.nd-stepdots { display: flex; gap: 4px; align-items: center; }
.nd-stepdot { width: 5px; height: 5px; border-radius: 50%; background: var(--clr-border, #dde3ea); transition: all 0.3s ease; }
.nd-stepdot.active { width: 14px; border-radius: 3px; background: var(--clr-accent, #f39c12); }
.nd-enter-right { animation: ndEnterRight 0.32s cubic-bezier(0.34,1.2,0.64,1) forwards; }
.nd-enter-left  { animation: ndEnterLeft  0.32s cubic-bezier(0.34,1.2,0.64,1) forwards; }
.nd-exit-left   { opacity: 0; transform: translateX(-24px); }
.nd-exit-right  { opacity: 0; transform: translateX(24px); }
@keyframes ndEnterRight { from { opacity: 0; transform: translateX(24px); } to { opacity: 1; transform: translateX(0); } }
@keyframes ndEnterLeft  { from { opacity: 0; transform: translateX(-24px); } to { opacity: 1; transform: translateX(0); } }

@media (prefers-reduced-motion: reduce) {
  .nd-arrow, .nd-stepdot { transition: none; }
  .nd-enter-right, .nd-enter-left { animation: none; }
}
</style>
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                          