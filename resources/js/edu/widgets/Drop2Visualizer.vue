<script setup lang="ts">
/**
 * drop2-visualizer — the drop-2 and drop-3 voicing technique as moving pitch dots.
 *
 * Fixed quality (Cmaj7) so the widget always has four voices and the drop
 * technique is always demonstrable. Closed / Drop 2 / Drop 3 badge pills
 * select the voicing state; the dropped dot slides and pulses to teach which
 * voice moved.
 */
import { computed, onMounted, ref, watch } from 'vue';

const CHROMATIC = ['C', 'C♯', 'D', 'D♯', 'E', 'F', 'F♯', 'G', 'G♯', 'A', 'A♯', 'B'] as const;

// Fixed: root C, quality maj7 — always four voices, drop always valid.
const ROOT_INDEX = 0;
const OFFSETS = [4, 7, 11] as const; // third, fifth, seventh

const ROLE_COLOR: Record<string, string> = {
  Root:    'var(--clr-role-root, #f39c12)',
  Third:   'var(--clr-role-third, #3b82f6)',
  Fifth:   'var(--clr-role-fifth, #10b981)',
  Seventh: 'var(--clr-role-seventh, #8b5cf6)',
};

type DropMode = 'closed' | 'drop2' | 'drop3';

const mode = ref<DropMode>('closed');

const MODES: { key: DropMode; label: string }[] = [
  { key: 'closed', label: 'Closed' },
  { key: 'drop2',  label: 'Drop 2' },
  { key: 'drop3',  label: 'Drop 3' },
];

interface Voice {
  role: 'Root' | 'Third' | 'Fifth' | 'Seventh';
  note: string;
  semitone: number;
  typicalString: number;
}

const CLOSED_STRINGS = { Root: 5, Third: 4, Fifth: 3, Seventh: 2 } as const;

const closedVoices = computed<Voice[]>(() => [
  { role: 'Root',    note: CHROMATIC[ROOT_INDEX],                  semitone: ROOT_INDEX,           typicalString: CLOSED_STRINGS.Root    },
  { role: 'Third',   note: CHROMATIC[(ROOT_INDEX + OFFSETS[0]) % 12], semitone: ROOT_INDEX + OFFSETS[0], typicalString: CLOSED_STRINGS.Third   },
  { role: 'Fifth',   note: CHROMATIC[(ROOT_INDEX + OFFSETS[1]) % 12], semitone: ROOT_INDEX + OFFSETS[1], typicalString: CLOSED_STRINGS.Fifth   },
  { role: 'Seventh', note: CHROMATIC[(ROOT_INDEX + OFFSETS[2]) % 12], semitone: ROOT_INDEX + OFFSETS[2], typicalString: CLOSED_STRINGS.Seventh },
]);

// Drop-2: drop the 2nd-highest voice (index length-2 in ascending order) by one octave.
const drop2Voices = computed<Voice[]>(() => {
  const ascending = [...closedVoices.value].sort((a, b) => a.semitone - b.semitone);
  const target = ascending[ascending.length - 2].role;
  return closedVoices.value.map(v =>
    v.role === target ? { ...v, semitone: v.semitone - 12, typicalString: v.typicalString + 1 } : v,
  );
});

// Drop-3: drop the 3rd-highest voice (index length-3) by one octave.
const drop3Voices = computed<Voice[]>(() => {
  const ascending = [...closedVoices.value].sort((a, b) => a.semitone - b.semitone);
  const target = ascending[ascending.length - 3].role;
  return closedVoices.value.map(v =>
    v.role === target ? { ...v, semitone: v.semitone - 12, typicalString: v.typicalString + 1 } : v,
  );
});

const activeVoices = computed(() => {
  if (mode.value === 'drop2') return drop2Voices.value;
  if (mode.value === 'drop3') return drop3Voices.value;
  return closedVoices.value;
});

// The role that moved — shown in caption and gets the pulse animation.
const movedRole = computed(() => {
  if (mode.value === 'closed') return null;
  const source = closedVoices.value;
  const target = activeVoices.value;
  return source.find((v, i) => v.semitone !== target[i].semitone)?.role ?? null;
});

// ── Pitch-dot geometry ────────────────────────────────────────────────────────
const STACK_TOP = 16;
const ROW_GAP   = 36;
const DOT_R     = 16;
const SVG_W     = 220;
const DOT_X     = SVG_W / 2;

const pitchRange = computed(() => {
  const all = [...closedVoices.value, ...drop2Voices.value, ...drop3Voices.value].map(v => v.semitone);
  return { min: Math.min(...all), max: Math.max(...all) };
});

function yFor(semitone: number): number {
  return STACK_TOP + (pitchRange.value.max - semitone) * (ROW_GAP / 2);
}

const svgHeight = computed(() => {
  const span = pitchRange.value.max - pitchRange.value.min;
  return STACK_TOP * 2 + span * (ROW_GAP / 2) + DOT_R * 2;
});

interface PlacedVoice extends Voice {
  y: number;
  color: string;
}

const placedVoices = computed<PlacedVoice[]>(() =>
  activeVoices.value.map(v => ({ ...v, y: yFor(v.semitone), color: ROLE_COLOR[v.role] })),
);

// ── Build-up animation ────────────────────────────────────────────────────────
const visibleCount = ref(0);
const prefersReducedMotion =
  typeof window !== 'undefined' &&
  window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;

onMounted(() => {
  if (prefersReducedMotion) { visibleCount.value = 4; return; }
  let i = 0;
  const tick = () => { i += 1; visibleCount.value = i; if (i < 4) setTimeout(tick, 140); };
  setTimeout(tick, 120);
});

function isVoiceVisible(v: PlacedVoice): boolean {
  if (visibleCount.value >= 4) return true;
  const ascending = [...activeVoices.value].sort((a, b) => a.semitone - b.semitone);
  return ascending.findIndex(x => x.role === v.role) < visibleCount.value;
}

// ── Pulse on mode change ──────────────────────────────────────────────────────
const pulsingRole = ref<string | null>(null);

watch(mode, () => {
  if (prefersReducedMotion) return;
  const role = movedRole.value;
  if (!role) return;
  pulsingRole.value = role;
  setTimeout(() => { pulsingRole.value = null; }, 500);
});
</script>

<template>
  <div class="sbn-edu-widget d2-widget">
    <div class="d2-stage">
      <svg :viewBox="`0 0 ${SVG_W} ${svgHeight}`" width="100%" class="d2-svg" :style="{ maxHeight: svgHeight + 'px' }">
        <g
          v-for="v in placedVoices"
          :key="v.role"
          class="d2-voice"
          :class="{ 'd2-voice--hidden': !isVoiceVisible(v) }"
          :style="{ transform: `translateY(${v.y}px)` }"
        >
          <circle
            :cx="DOT_X" cy="0" :r="DOT_R" :fill="v.color"
            class="d2-dot"
            :class="{
              'd2-dot--popin':  isVoiceVisible(v) && visibleCount - 1 === [closedVoices.findIndex(x => x.role === v.role)][0] && mode === 'closed',
              'd2-dot--pulse':  pulsingRole === v.role,
            }"
          />
          <text :x="DOT_X" y="0" text-anchor="middle" dominant-baseline="central" class="d2-dot-note">
            {{ v.note }}
          </text>
          <text :x="DOT_X + DOT_R + 8" y="0" dominant-baseline="central" class="d2-dot-role">
            {{ v.role }}
          </text>
        </g>
      </svg>
    </div>

    <div class="d2-badges">
      <button
        v-for="m in MODES"
        :key="m.key"
        class="d2-badge"
        :class="{ active: mode === m.key }"
        @click="mode = m.key"
      >{{ m.label }}</button>
    </div>

    <p class="d2-caption">
      <template v-if="mode === 'closed'">
        A <strong>closed</strong> voicing packs all four notes inside one octave.
      </template>
      <template v-else-if="mode === 'drop2'">
        The <strong>{{ movedRole }}</strong> — 2nd from the top — dropped an octave. Wider spacing, easier to grab on guitar.
      </template>
      <template v-else>
        The <strong>{{ movedRole }}</strong> — 3rd from the top — dropped an octave. A different spread, different fingering shape.
      </template>
    </p>
  </div>
</template>

<style scoped>
.d2-widget {
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding: 12px;
  font-family: var(--font-body, system-ui, sans-serif);
}

.d2-stage {
  display: flex;
  flex-direction: column;
  align-items: center;
}

.d2-svg {
  max-width: 220px;
  display: block;
  overflow: visible;
}

.d2-voice {
  transition: transform 0.45s cubic-bezier(0.34, 1.2, 0.64, 1), opacity 0.25s var(--ease, ease);
}

.d2-voice--hidden {
  opacity: 0;
  transition: none;
}

/* ── Badges ──────────────────────────────────────────────────────────────── */
.d2-badges {
  display: flex;
  gap: 8px;
  justify-content: center;
}

.d2-badge {
  padding: 3px 10px;
  border-radius: 999px;
  border: 1px solid var(--clr-border, #e2e8f0);
  background: transparent;
  color: var(--clr-text-muted, #8896a4);
  font-size: 11px;
  font-weight: 500;
  cursor: pointer;
  transition: background 0.15s, color 0.15s, border-color 0.15s;
}

.d2-badge:hover {
  border-color: var(--clr-accent, #f39c12);
  color: var(--clr-accent, #f39c12);
}

.d2-badge.active {
  background: var(--clr-accent, #f39c12);
  border-color: var(--clr-accent, #f39c12);
  color: #000;
}

/* ── Caption ─────────────────────────────────────────────────────────────── */
.d2-caption {
  margin: 0;
  font-size: 12px;
  line-height: 1.5;
  color: var(--clr-text-muted, #8896a4);
  text-align: center;
}

.d2-dot-note {
  font-size: 11px;
  font-weight: 700;
  fill: #fff;
  pointer-events: none;
}

.d2-dot-role {
  font-size: 10px;
  font-weight: 600;
  fill: var(--clr-text-muted, #8896a4);
  pointer-events: none;
}

/* ── Dot animations ──────────────────────────────────────────────────────── */
@keyframes d2-popin {
  0%   { transform: scale(0);    opacity: 0; }
  60%  { transform: scale(1.25); opacity: 1; }
  100% { transform: scale(1);    opacity: 1; }
}

@keyframes d2-pulse {
  0%   { transform: scale(1);    }
  35%  { transform: scale(1.35); }
  100% { transform: scale(1);    }
}

.d2-dot--popin {
  animation: d2-popin 0.35s cubic-bezier(0.34, 1.4, 0.64, 1) both;
  transform-origin: center;
  transform-box: fill-box;
}

.d2-dot--pulse {
  animation: d2-pulse 0.45s cubic-bezier(0.34, 1.2, 0.64, 1) both;
  transform-origin: center;
  transform-box: fill-box;
}

@media (prefers-reduced-motion: reduce) {
  .d2-voice { transition: none; }
  .d2-dot--popin, .d2-dot--pulse { animation: none; }
}
</style>
