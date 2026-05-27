<script setup lang="ts">
/**
 * chord-extensions — companion to chord-quality-tree.
 * Assumes you already know the five 7th-chord qualities; this widget
 * shows what happens when you keep stacking thirds beyond the 7th.
 *
 * Five quality columns, each with their own extension journey:
 *   maj7  → 9 → #11
 *   dom7  → 9 → #11 → 13   (+ option tones: ♭9 #9 ♭13)
 *   m7    → 9 → 11
 *   m7♭5  → 9 → 11
 *   dim7  → 9
 */
import { ref, computed } from 'vue'

type QSlug = 'maj7' | 'dom7' | 'm7' | 'm7b5' | 'dim7'

interface StackNote { degree: string; semitones: number; role: string }
interface OptionTone { label: string; unlockAt: number; replaces: string; semitones: number; description: string }

const STACK: Record<QSlug, StackNote[]> = {
  maj7: [
    { degree: '1',   semitones: 0,  role: 'root' },
    { degree: '3',   semitones: 4,  role: 'third' },
    { degree: '5',   semitones: 7,  role: 'fifth' },
    { degree: '7',   semitones: 11, role: 'seventh' },
    { degree: '9',   semitones: 14, role: 'ninth' },
    { degree: '#11', semitones: 18, role: 'eleventh' },
  ],
  dom7: [
    { degree: '1',  semitones: 0,  role: 'root' },
    { degree: '3',  semitones: 4,  role: 'third' },
    { degree: '5',  semitones: 7,  role: 'fifth' },
    { degree: '♭7', semitones: 10, role: 'seventh' },
    { degree: '9',  semitones: 14, role: 'ninth' },
    { degree: '11', semitones: 17, role: 'eleventh' },
    { degree: '13', semitones: 21, role: 'thirteenth' },
  ],
  m7: [
    { degree: '1',  semitones: 0,  role: 'root' },
    { degree: '♭3', semitones: 3,  role: 'third' },
    { degree: '5',  semitones: 7,  role: 'fifth' },
    { degree: '♭7', semitones: 10, role: 'seventh' },
    { degree: '9',  semitones: 14, role: 'ninth' },
    { degree: '11', semitones: 17, role: 'eleventh' },
  ],
  m7b5: [
    { degree: '1',   semitones: 0,  role: 'root' },
    { degree: '♭3',  semitones: 3,  role: 'third' },
    { degree: '♭5',  semitones: 6,  role: 'fifth' },
    { degree: '♭7',  semitones: 10, role: 'seventh' },
    { degree: '9',   semitones: 14, role: 'ninth' },
    { degree: '11',  semitones: 17, role: 'eleventh' },
  ],
  dim7: [
    { degree: '1',    semitones: 0, role: 'root' },
    { degree: '♭3',   semitones: 3, role: 'third' },
    { degree: '♭5',   semitones: 6, role: 'fifth' },
    { degree: '♭♭7',  semitones: 9, role: 'seventh' },
    { degree: '9',    semitones: 14, role: 'ninth' },
  ],
}

const EXT_STEPS: Record<QSlug, string[]> = {
  maj7:  ['7th', '9th', '#11th'],
  dom7:  ['7th', '9th', '11th', '13th'],
  m7:    ['7th', '9th', '11th'],
  m7b5:  ['7th', '9th', '11th'],
  dim7:  ['7th', '9th'],
}

const SYMBOLS: Record<QSlug, string[]> = {
  maj7:  ['Cmaj7', 'Cmaj9', 'Cmaj9#11'],
  dom7:  ['C7',    'C9',    'C11',      'C13'],
  m7:    ['Cm7',   'Cm9',   'Cm11'],
  m7b5:  ['Cm7♭5', 'Cm9♭5', 'Cm11♭5'],
  dim7:  ['C°7',   'C°9'],
}

const CHARACTER: Record<QSlug, string[]> = {
  maj7: [
    'The major seventh. Luminous and resolved — the home sound of Jazz ballads.',
    'Add the 9th. The chord breathes and opens; a touch of air above the 7th.',
    'The #11 gives Lydian colour — floating and bright, the natural extension on major.',
  ],
  dom7: [
    'The dominant seventh. The tritone between 3 and ♭7 is the engine of resolution.',
    'The 9th smooths the dominant without adding tension. A warm, coloured V chord.',
    'The 11th — use #11 (Lydian dominant) to avoid clashing with the major 3rd.',
    'The 13th. Natural on dominant — add option tones to push toward maximum tension.',
  ],
  m7: [
    'The minor seventh. Softer than the triad alone — the ii chord lives here.',
    'The 9th brightens the minor sound slightly without changing its character.',
    'The 11th feels natural in minor — no clash with the minor 3rd below it.',
  ],
  m7b5: [
    'The half-diminished seventh. Unstable and shadowy — the minor ii chord.',
    'The 9th adds a touch of colour to the half-diminished without resolving the tension.',
    'The 11th extends the half-diminished further — rarely used but valid in modal contexts.',
  ],
  dim7: [
    'The fully diminished 7th. All stacked minor thirds — dark, ambiguous, symmetrical.',
    'Adding the 9th above dim7 is enharmonic — the symmetry means any note can be the root.',
  ],
}

const OPTION_TONES: Partial<Record<QSlug, OptionTone[]>> = {
  dom7: [
    { label: '♭9',  unlockAt: 1, replaces: 'ninth',      semitones: 13, description: 'Altered tension — dark, Spanish flavour.' },
    { label: '#9',  unlockAt: 1, replaces: 'ninth',      semitones: 15, description: 'Bluesy, ambiguous — the Hendrix chord tone.' },
    { label: '#11', unlockAt: 2, replaces: 'eleventh',   semitones: 18, description: 'Lydian dominant — bright tension, tritone sub sound.' },
    { label: '♭13', unlockAt: 3, replaces: 'thirteenth', semitones: 20, description: 'Altered dominant — maximum tension before resolution.' },
  ],
}

const HUE: Record<QSlug, string> = {
  maj7:  '#f0a020',
  dom7:  '#e05a20',
  m7:    '#6458c8',
  m7b5:  '#1e4a9e',
  dim7:  '#9b2040',
}

const ROLE_COLOR: Record<string, string> = {
  root:       'var(--clr-role-root,  #f59e0b)',
  third:      'var(--clr-role-third, #3b82f6)',
  fifth:      'var(--clr-role-fifth, #10b981)',
  seventh:    '#8b5cf6',
  ninth:      '#14b8a6',
  eleventh:   '#ef4444',
  thirteenth: '#f97316',
}

const SVG_W  = 80
const DOT_R  = 11
const DOT_GAP = 32
const DOT_X  = SVG_W / 2

function dotY(i: number, total: number) {
  return DOT_R + (total - 1 - i) * DOT_GAP
}

// ---- state ------------------------------------------------------------------
const quality      = ref<QSlug>('maj7')
const extIdx       = ref(0)
const activeOptions = ref<OptionTone[]>([])

const stack     = computed(() => STACK[quality.value])
const extSteps  = computed(() => EXT_STEPS[quality.value])
const hue       = computed(() => HUE[quality.value])

// noteCount = 7th chord has 4 notes; each extension step adds 1
const noteCount = computed(() => 4 + extIdx.value)
const visibleNotes = computed(() =>
  stack.value.slice(0, noteCount.value).map(note => {
    const opt = activeOptions.value.find(o => o.replaces === note.role)
    return opt ? { ...note, semitones: opt.semitones, optLabel: opt.label } : note
  })
)
const svgH      = computed(() => (noteCount.value - 1) * DOT_GAP + DOT_R * 2 + 8)
const symbol    = computed(() => SYMBOLS[quality.value][extIdx.value] ?? SYMBOLS[quality.value].at(-1)!)
const character = computed(() => CHARACTER[quality.value][extIdx.value] ?? '')

const options         = computed(() => OPTION_TONES[quality.value] ?? [])
const visibleRoles    = computed(() => visibleNotes.value.map(n => n.role))
const unlockedOptions = computed(() =>
  options.value.filter(o => o.unlockAt <= extIdx.value && visibleRoles.value.includes(o.replaces))
)

const symbolSize = computed(() => {
  const len = symbol.value.length
  return len <= 4 ? 'short' : len <= 8 ? 'medium' : 'long'
})

function switchQuality(q: QSlug) {
  quality.value = q
  extIdx.value  = 0
  activeOptions.value = []
}

function switchExt(i: number) {
  extIdx.value = i
  activeOptions.value = activeOptions.value.filter(o =>
    (OPTION_TONES[quality.value] ?? []).some(n => n.label === o.label && n.unlockAt <= i)
  )
}

function toggleOption(opt: OptionTone) {
  const existing = activeOptions.value.find(o => o.replaces === opt.replaces)
  if (existing?.label === opt.label) {
    activeOptions.value = activeOptions.value.filter(o => o.replaces !== opt.replaces)
  } else {
    activeOptions.value = [
      ...activeOptions.value.filter(o => o.replaces !== opt.replaces),
      opt,
    ]
  }
}

function isOptionActive(opt: OptionTone) {
  return activeOptions.value.some(o => o.label === opt.label)
}
</script>

<template>
  <div class="ce-card" :style="{ '--ce-hue': hue }">
    <div class="ce-glow" :style="{ background: hue }" aria-hidden="true"></div>

    <!-- Header -->
    <div class="ce-header">
      <div class="ce-title">Extensions</div>
      <div class="ce-pills">
        <button
          v-for="q in (['maj7','dom7','m7','m7b5','dim7'] as QSlug[])"
          :key="q"
          class="ce-pill"
          :class="{ active: quality === q }"
          @click="switchQuality(q)"
        >{{ q === 'm7b5' ? 'm7♭5' : q === 'dim7' ? 'dim7' : q }}</button>
      </div>
    </div>

    <!-- Body -->
    <div class="ce-body">
      <!-- Dot stack -->
      <div class="ce-dot-col">
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
      <div class="ce-info">
        <div class="ce-symbol" :class="symbolSize">{{ symbol }}</div>
        <div class="ce-character">{{ character }}</div>
      </div>
    </div>

    <!-- Option tones -->
    <div class="ce-options-wrap">
      <template v-if="unlockedOptions.length > 0">
        <div class="ce-options-label">Option tones</div>
        <div class="ce-options-pills">
          <button
            v-for="opt in unlockedOptions"
            :key="opt.label"
            class="ce-option-pill"
            :class="{ active: isOptionActive(opt) }"
            :title="opt.description"
            @click="toggleOption(opt)"
          >{{ opt.label }}</button>
        </div>
      </template>
    </div>

    <!-- Extension stepper -->
    <div class="ce-ext-row">
      <button
        v-for="(label, i) in extSteps"
        :key="label"
        class="ce-ext-pill"
        :class="{ active: extIdx === i }"
        @click="switchExt(i)"
      >{{ label }}</button>
    </div>
  </div>
</template>

<style scoped>
.ce-card {
  --ce-hue: #f0a020;
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

.ce-glow {
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

.ce-header, .ce-body, .ce-options-wrap, .ce-ext-row { position: relative; z-index: 1; }

.ce-header { width: 100%; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 0.5rem; }
.ce-title {
  font-family: 'DM Mono', monospace;
  font-size: 0.7rem; letter-spacing: 0.15em;
  text-transform: uppercase; color: rgba(255,255,255,0.65);
}
.ce-pills { display: flex; gap: 3px; flex-wrap: wrap; }
.ce-pill {
  font-family: 'DM Mono', monospace; font-size: 0.65rem; letter-spacing: 0.06em;
  padding: 0.28rem 0.6rem; border-radius: 999px;
  border: 1px solid rgba(255,255,255,0.18); background: rgba(255,255,255,0.08);
  color: rgba(255,255,255,0.6); cursor: pointer; transition: all 0.2s ease;
}
.ce-pill:hover { border-color: rgba(255,255,255,0.35); color: rgba(255,255,255,0.9); background: rgba(255,255,255,0.12); }
.ce-pill.active { background: rgba(255,255,255,0.92); color: #0f0f17; border-color: transparent; }

.ce-body { display: flex; align-items: center; gap: 1.25rem; width: 100%; }
.ce-dot-col { display: flex; flex-direction: column; align-items: center; flex-shrink: 0; }
.ce-info { flex: 1; display: flex; flex-direction: column; gap: 0.75rem; }

.ce-symbol {
  font-family: 'Cormorant Garamond', serif;
  font-weight: 300; line-height: 1; letter-spacing: -0.02em;
  color: var(--ce-hue); min-height: 3rem; transition: color 0.35s ease, font-size 0.2s ease;
}
.ce-symbol.short  { font-size: 3rem; }
.ce-symbol.medium { font-size: 2.2rem; }
.ce-symbol.long   { font-size: 1.6rem; }

.ce-character {
  font-family: system-ui, sans-serif;
  font-size: 0.82rem; line-height: 1.6;
  color: rgba(255,255,255,0.85); min-height: 4rem; transition: opacity 0.25s ease;
}

.ce-options-wrap { width: 100%; min-height: 1.5rem; }
.ce-options-label {
  font-family: 'DM Mono', monospace; font-size: 0.65rem;
  letter-spacing: 0.12em; text-transform: uppercase;
  color: rgba(255,255,255,0.5); margin-bottom: 0.4rem;
}
.ce-options-pills { display: flex; gap: 4px; flex-wrap: wrap; }
.ce-option-pill {
  font-family: 'DM Mono', monospace; font-size: 0.65rem; letter-spacing: 0.06em;
  padding: 0.28rem 0.6rem; border-radius: 999px;
  border: 1px solid rgba(255,255,255,0.18); background: rgba(255,255,255,0.08);
  color: rgba(255,255,255,0.6); cursor: pointer; transition: all 0.2s ease;
}
.ce-option-pill:hover { border-color: rgba(255,255,255,0.35); color: rgba(255,255,255,0.9); background: rgba(255,255,255,0.12); }
.ce-option-pill.active { background: rgba(239,68,68,0.85); border-color: transparent; color: #fff; }

.ce-ext-row { display: flex; gap: 3px; width: 100%; justify-content: center; flex-wrap: wrap; }
.ce-ext-pill {
  font-family: 'DM Mono', monospace; font-size: 0.65rem; letter-spacing: 0.06em;
  padding: 0.28rem 0.65rem; border-radius: 999px;
  border: 1px solid rgba(255,255,255,0.18); background: rgba(255,255,255,0.08);
  color: rgba(255,255,255,0.6); cursor: pointer; transition: all 0.2s ease; white-space: nowrap;
}
.ce-ext-pill:hover { border-color: rgba(255,255,255,0.35); color: rgba(255,255,255,0.9); background: rgba(255,255,255,0.12); }
.ce-ext-pill.active { background: rgba(255,255,255,0.92); color: #0f0f17; border-color: transparent; }

@keyframes dotPop {
  0%   { transform: scale(0); opacity: 0; }
  65%  { transform: scale(1.28); opacity: 1; }
  100% { transform: scale(1); opacity: 1; }
}
.dot-pop { animation: dotPop 0.38s cubic-bezier(0.34,1.2,0.64,1) forwards; }

@media (prefers-reduced-motion: reduce) { .dot-pop { animation: none; } }
</style>
