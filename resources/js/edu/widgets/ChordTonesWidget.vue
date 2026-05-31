<script setup lang="ts">
import { ref, computed } from 'vue';

interface StackNote { degree: string; semitones: number; role: string; }
interface OptionTone { label: string; unlockAt: number; replaces: string; semitones: number; description: string; }

const STACK: Record<string, StackNote[]> = {
  maj: [
    { degree: 'R',   semitones: 0,  role: 'root' },
    { degree: '3',   semitones: 4,  role: 'third' },
    { degree: '5',   semitones: 7,  role: 'fifth' },
    { degree: '7',   semitones: 11, role: 'seventh' },
    { degree: '9',   semitones: 14, role: 'ninth' },
    { degree: '#11', semitones: 18, role: 'eleventh' },
  ],
  min: [
    { degree: 'R',   semitones: 0,  role: 'root' },
    { degree: '3',   semitones: 3,  role: 'third' },
    { degree: '5',   semitones: 7,  role: 'fifth' },
    { degree: '7',   semitones: 10, role: 'seventh' },
    { degree: '9',   semitones: 14, role: 'ninth' },
    { degree: '11',  semitones: 17, role: 'eleventh' },
  ],
  dom: [
    { degree: 'R',   semitones: 0,  role: 'root' },
    { degree: '3',   semitones: 4,  role: 'third' },
    { degree: '5',   semitones: 7,  role: 'fifth' },
    { degree: '7',   semitones: 10, role: 'seventh' },
    { degree: '9',   semitones: 14, role: 'ninth' },
    { degree: '11',  semitones: 17, role: 'eleventh' },
    { degree: '13',  semitones: 21, role: 'thirteenth' },
  ],
};

const OPTION_TONES: Record<string, OptionTone[]> = {
  maj: [],
  min: [
    { label: '#11', unlockAt: 3, replaces: 'eleventh',    semitones: 18, description: 'Dorian colour — minor with a raised 4th, used in Jazz minor.' },
  ],
  dom: [
    { label: '♭9',  unlockAt: 1, replaces: 'ninth',       semitones: 13, description: 'Altered tension — dark, Spanish flavour.' },
    { label: '#9',  unlockAt: 1, replaces: 'ninth',       semitones: 15, description: 'Bluesy, ambiguous — the Hendrix chord tone.' },
    { label: '#11', unlockAt: 2, replaces: 'eleventh',    semitones: 18, description: 'Lydian dominant — bright tension, tritone sub sound.' },
    { label: '♭13', unlockAt: 3, replaces: 'thirteenth',  semitones: 20, description: 'Altered dominant — maximum tension before resolution.' },
  ],
};

const EXT_LABELS: Record<string, string[]> = {
  maj: ['Triad', '7th', '9th', '11th'],
  min: ['Triad', '7th', '9th', '11th'],
  dom: ['7th', '9th', '11th', '13th'],
};

const CHARACTER: Record<string, string[]> = {
  maj: [
    'A triad. Three notes — the full harmonic identity in its simplest form.',
    'The major seventh. Adds luminosity — that suspended, almost resolved feeling.',
    'The ninth adds air. The chord breathes, opens up, reaches outward.',
    'The #11 is the natural extension on major — Lydian colour, bright and floating.',
  ],
  min: [
    'The minor triad. Dark, introspective — three notes that carry weight.',
    'Minor seventh. Softer than the triad alone — the ii chord lives here.',
    'The ninth brightens the minor sound slightly without losing its colour.',
    'The eleventh feels natural in minor — no clash with the minor third.',
  ],
  dom: [
    'The dominant seventh. The tritone appears — the engine of resolution.',
    'The ninth adds colour without extra tension. A smooth dominant sound.',
    'The eleventh — use #11 to avoid the clash with the major 3rd.',
    'The thirteenth. Natural on dominant — alter it to ♭13 for maximum tension.',
  ],
};

const ROLE_COLOR: Record<string, string> = {
  root:       '#f59e0b',
  third:      '#3b82f6',
  fifth:      '#10b981',
  seventh:    '#8b5cf6',
  ninth:      '#14b8a6',
  eleventh:   '#ef4444',
  thirteenth: '#f97316',
};

const SVG_W = 80;
const DOT_R = 11;
const DOT_GAP = 34;
const DOT_X = SVG_W / 2;

function dotY(i: number, total: number) {
  return DOT_R + (total - 1 - i) * DOT_GAP;
}

function buildSymbol(quality: string, extensionIdx: number, activeOptions: OptionTone[]) {
  const domExts = ['7', '9', '11', '13'];
  const majExts = ['', '7', '9', '11'];
  const baseExt = quality === 'dom' ? domExts[extensionIdx] : majExts[extensionIdx];
  const topRole = quality === 'dom'
    ? ['seventh', 'ninth', 'eleventh', 'thirteenth'][extensionIdx]
    : ['', 'seventh', 'ninth', 'eleventh'][extensionIdx];
  const topOpt = activeOptions.find(o => o.replaces === topRole);
  const extStr = topOpt ? topOpt.label : baseExt;
  const otherOpts = activeOptions.filter(o => o.replaces !== topRole).map(o => o.label).join('');
  let sym = '';
  if (quality === 'dom') {
    sym = extStr;
  } else if (extensionIdx === 0) {
    sym = quality === 'maj' ? 'maj' : 'm';
  } else {
    sym = quality === 'maj' ? `maj${extStr}` : `m${extStr}`;
  }
  return sym + (otherOpts ? `(${otherOpts})` : '');
}

const quality = ref<'maj' | 'min' | 'dom'>('maj');
const extIdx  = ref(0);
const activeOptions = ref<OptionTone[]>([]);

const stack    = computed(() => STACK[quality.value]);
const options  = computed(() => OPTION_TONES[quality.value] || []);
const extLabels = computed(() => EXT_LABELS[quality.value]);

const safeExtIdx  = computed(() => Math.min(extIdx.value, extLabels.value.length - 1));
const noteCount   = computed(() => quality.value === 'dom' ? safeExtIdx.value + 4 : safeExtIdx.value + 3);
const visibleNotes = computed(() =>
  stack.value.slice(0, noteCount.value).map(note => {
    const opt = activeOptions.value.find(o => o.replaces === note.role);
    return opt ? { ...note, semitones: opt.semitones, optLabel: opt.label } : note;
  })
);
const svgH    = computed(() => (noteCount.value - 1) * DOT_GAP + DOT_R * 2 + 8);
const symbol  = computed(() => buildSymbol(quality.value, safeExtIdx.value, activeOptions.value));
const character = computed(() => CHARACTER[quality.value][safeExtIdx.value]);
const visibleRoles = computed(() => visibleNotes.value.map(n => n.role));
const unlockedOptions = computed(() =>
  options.value.filter(o => o.unlockAt <= safeExtIdx.value && visibleRoles.value.includes(o.replaces))
);

const symbolSize = computed(() => {
  const len = symbol.value.length;
  return len <= 4 ? 'short' : len <= 7 ? 'medium' : 'long';
});

function toggleOption(opt: OptionTone) {
  const existing = activeOptions.value.find(o => o.replaces === opt.replaces);
  if (existing && existing.label === opt.label) {
    activeOptions.value = activeOptions.value.filter(o => o.replaces !== opt.replaces);
  } else {
    activeOptions.value = [
      ...activeOptions.value.filter(o => o.replaces !== opt.replaces),
      opt,
    ];
  }
}

function switchQuality(q: 'maj' | 'min' | 'dom') {
  quality.value = q;
  activeOptions.value = [];
  extIdx.value = 0;
}

function switchExt(i: number) {
  extIdx.value = i;
  const newOpts = OPTION_TONES[quality.value]?.filter(o => o.unlockAt <= i) || [];
  activeOptions.value = activeOptions.value.filter(o => newOpts.some(n => n.label === o.label));
}

function isOptionActive(opt: OptionTone) {
  return activeOptions.value.some(o => o.label === opt.label);
}
</script>

<template>
  <div class="ct-card">

    <!-- Header -->
    <div class="ct-header">
      <div class="ct-title">Chord Tones</div>
      <div class="ct-pills">
        <button
          v-for="q in ['maj','min','dom']"
          :key="q"
          class="ct-pill"
          :class="{ active: quality === q }"
          @click="switchQuality(q as any)"
        >{{ q }}</button>
      </div>
    </div>

    <!-- Body -->
    <div class="ct-body">

      <!-- Dot stack -->
      <div class="ct-dot-col">
        <svg
          :width="SVG_W" :height="svgH"
          :viewBox="`0 0 ${SVG_W} ${svgH}`"
          style="transition: height 0.4s ease; overflow: visible"
        >
          <line :x1="DOT_X" :y1="DOT_R" :x2="DOT_X" :y2="svgH - DOT_R"
            stroke="rgba(255,255,255,0.08)" stroke-width="1.5"
          />
          <g
            v-for="(note, i) in visibleNotes"
            :key="`${quality}-${extIdx}-${note.role}`"
            class="dot-pop"
            :style="{ transformOrigin: `${DOT_X}px ${dotY(i, noteCount)}px` }"
          >
            <circle :cx="DOT_X" :cy="dotY(i, noteCount)" :r="DOT_R" :fill="ROLE_COLOR[note.role]" />
            <circle :cx="DOT_X" :cy="dotY(i, noteCount)" :r="DOT_R * 0.38" fill="rgba(255,255,255,0.65)" />
            <text
              :x="DOT_X - DOT_R - 5" :y="dotY(i, noteCount) + 4"
              text-anchor="end" font-family="'DM Mono', monospace" font-size="8"
              :fill="ROLE_COLOR[note.role]"
            >{{ (note as any).optLabel || note.degree }}</text>
          </g>
        </svg>
      </div>

      <!-- Info -->
      <div class="ct-info">
        <div class="ct-symbol" :class="symbolSize">{{ symbol || '—' }}</div>
        <div class="ct-character">{{ character }}</div>
      </div>
    </div>

    <!-- Option tones -->
    <div class="ct-options-wrap">
      <template v-if="unlockedOptions.length > 0">
        <div class="ct-options-label">Option tones</div>
        <div class="ct-options-pills">
          <button
            v-for="opt in unlockedOptions"
            :key="opt.label"
            class="ct-option-pill"
            :class="{ active: isOptionActive(opt) }"
            :title="opt.description"
            @click="toggleOption(opt)"
          >{{ opt.label }}</button>
        </div>
      </template>
    </div>

    <!-- Extension stepper -->
    <div class="ct-ext-row">
      <button
        v-for="(label, i) in extLabels"
        :key="label"
        class="ct-ext-pill"
        :class="{ active: safeExtIdx === i }"
        @click="switchExt(i)"
      >{{ label }}</button>
    </div>

  </div>
</template>

<style scoped>
.ct-card {
  width: 100%;
  background: #0f0f17;
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 1.5rem 1.25rem;
  gap: 1.25rem;
  position: relative;
  overflow: hidden;
  user-select: none;
  box-sizing: border-box;
}

.ct-header { width: 100%; display: flex; align-items: center; justify-content: space-between; }
.ct-title {
  font-family: 'DM Mono', monospace;
  font-size: 0.7rem; letter-spacing: 0.15em;
  text-transform: uppercase; color: #ffffff;
}
.ct-pills { display: flex; gap: 3px; }
.ct-pill {
  font-family: 'DM Mono', monospace; font-size: 0.65rem; letter-spacing: 0.08em;
  padding: 0.32rem 0.65rem; border-radius: 999px;
  border: 1px solid rgba(255,255,255,0.18); background: rgba(255,255,255,0.08);
  color: #ffffff; cursor: pointer; transition: all 0.2s ease;
}
.ct-pill:hover { border-color: rgba(255,255,255,0.35); color: rgba(255,255,255,0.9); background: rgba(255,255,255,0.12); }
.ct-pill.active { background: rgba(255,255,255,0.92); color: #0f0f17; border-color: transparent; }

.ct-body { display: flex; align-items: center; gap: 1.25rem; width: 100%; }
.ct-dot-col { display: flex; flex-direction: column; align-items: center; flex-shrink: 0; }
.ct-info { flex: 1; display: flex; flex-direction: column; gap: 0.75rem; }

.ct-symbol {
  font-family: 'Cormorant Garamond', serif;
  font-weight: 300; line-height: 1; letter-spacing: -0.02em;
  color: #ffffff; min-height: 3rem; transition: all 0.3s ease;
}
.ct-symbol.short  { font-size: 3rem; }
.ct-symbol.medium { font-size: 2.2rem; }
.ct-symbol.long   { font-size: 1.6rem; }

.ct-character {
  font-family: system-ui, sans-serif;
  font-size: 0.82rem; line-height: 1.6;
  color: #ffffff; min-height: 4rem; transition: opacity 0.25s ease;
}

.ct-options-wrap { width: 100%; min-height: 1.5rem; }
.ct-options-label {
  font-family: 'DM Mono', monospace; font-size: 0.65rem;
  letter-spacing: 0.12em; text-transform: uppercase;
  color: #ffffff; margin-bottom: 0.4rem;
}
.ct-options-pills { display: flex; gap: 4px; flex-wrap: wrap; }
.ct-option-pill {
  font-family: 'DM Mono', monospace; font-size: 0.65rem; letter-spacing: 0.06em;
  padding: 0.32rem 0.65rem; border-radius: 999px;
  border: 1px solid rgba(255,255,255,0.18); background: rgba(255,255,255,0.08);
  color: #ffffff; cursor: pointer; transition: all 0.2s ease;
}
.ct-option-pill:hover { border-color: rgba(255,255,255,0.35); color: rgba(255,255,255,0.9); background: rgba(255,255,255,0.12); }
.ct-option-pill.active { background: rgba(239,68,68,0.85); border-color: transparent; color: #ffffff; }

.ct-ext-row { display: flex; gap: 3px; width: 100%; justify-content: center; }
.ct-ext-pill {
  font-family: 'DM Mono', monospace; font-size: 0.65rem; letter-spacing: 0.06em;
  padding: 0.32rem 0.65rem; border-radius: 999px;
  border: 1px solid rgba(255,255,255,0.18); background: rgba(255,255,255,0.08);
  color: #ffffff; cursor: pointer; transition: all 0.2s ease; white-space: nowrap;
}
.ct-ext-pill:hover { border-color: rgba(255,255,255,0.35); color: rgba(255,255,255,0.9); background: rgba(255,255,255,0.12); }
.ct-ext-pill.active { background: rgba(255,255,255,0.92); color: #0f0f17; border-color: transparent; }

@keyframes dotPop {
  0%   { transform: scale(0); opacity: 0; }
  65%  { transform: scale(1.28); opacity: 1; }
  100% { transform: scale(1); opacity: 1; }
}
.dot-pop { animation: dotPop 0.38s cubic-bezier(0.34,1.2,0.64,1) forwards; }

@media (prefers-reduced-motion: reduce) { .dot-pop { animation: none; } }
</style>
