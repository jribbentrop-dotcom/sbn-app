<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';

const CHROMATIC = ['C', 'C♯', 'D', 'D♯', 'E', 'F', 'F♯', 'G', 'G♯', 'A', 'A♯', 'B'] as const;

const ROOT_INDEX = 0;
const OFFSETS = [4, 7, 11] as const; // third, fifth, seventh

const ROLE_COLOR: Record<string, string> = {
  Root:    'var(--clr-role-root,    #f39c12)',
  Third:   'var(--clr-role-third,   #3b82f6)',
  Fifth:   'var(--clr-role-fifth,   #10b981)',
  Seventh: 'var(--clr-role-seventh, #8b5cf6)',
};

type DropMode = 'closed' | 'drop2' | 'drop3' | 'shell';

const mode = ref<DropMode>('closed');

const MODES: { key: DropMode; label: string }[] = [
  { key: 'closed', label: 'Closed' },
  { key: 'drop2',  label: 'Drop 2' },
  { key: 'drop3',  label: 'Drop 3' },
  { key: 'shell',  label: 'Shell'  },
];

interface Voice {
  role: 'Root' | 'Third' | 'Fifth' | 'Seventh';
  note: string;
  semitone: number;
}

const closedVoices = computed<Voice[]>(() => [
  { role: 'Root',    note: CHROMATIC[ROOT_INDEX],                        semitone: ROOT_INDEX              },
  { role: 'Third',   note: CHROMATIC[(ROOT_INDEX + OFFSETS[0]) % 12],    semitone: ROOT_INDEX + OFFSETS[0] },
  { role: 'Fifth',   note: CHROMATIC[(ROOT_INDEX + OFFSETS[1]) % 12],    semitone: ROOT_INDEX + OFFSETS[1] },
  { role: 'Seventh', note: CHROMATIC[(ROOT_INDEX + OFFSETS[2]) % 12],    semitone: ROOT_INDEX + OFFSETS[2] },
]);

// Drop-2: 2nd-highest voice drops an octave.
const drop2Voices = computed<Voice[]>(() => {
  const ascending = [...closedVoices.value].sort((a, b) => a.semitone - b.semitone);
  const target = ascending[ascending.length - 2].role;
  return closedVoices.value.map(v =>
    v.role === target ? { ...v, semitone: v.semitone - 12 } : v,
  );
});

// Drop-3: 3rd-highest voice drops an octave.
const drop3Voices = computed<Voice[]>(() => {
  const ascending = [...closedVoices.value].sort((a, b) => a.semitone - b.semitone);
  const target = ascending[ascending.length - 3].role;
  return closedVoices.value.map(v =>
    v.role === target ? { ...v, semitone: v.semitone - 12 } : v,
  );
});

// Shell: root + 3rd + 7th (5th omitted entirely — adds little colour).
const shellVoices = computed<Voice[]>(() =>
  closedVoices.value.filter(v => v.role !== 'Fifth'),
);

const activeVoices = computed<Voice[]>(() => {
  if (mode.value === 'drop2') return drop2Voices.value;
  if (mode.value === 'drop3') return drop3Voices.value;
  if (mode.value === 'shell') return shellVoices.value;
  return closedVoices.value;
});

// The role that moved — gets the arc animation.
const movedRole = computed(() => {
  if (mode.value === 'closed' || mode.value === 'shell') return null;
  const source = closedVoices.value;
  const target = activeVoices.value;
  return source.find((v, i) => v.semitone !== target[i].semitone)?.role ?? null;
});

// ── Pitch-dot geometry ────────────────────────────────────────────────────────
// Upper voices are evenly spaced at ROW_GAP.
// The bottom gap = ROW_GAP + the semitone distance × EXTRA_SCALE, so a
// dropped note (which lands further from its neighbour) shows more space.
const STACK_TOP   = 20;
const ROW_GAP     = 40;          // gap between upper voices
const EXTRA_SCALE = 6.5;         // extra px per semitone for the bottom gap
const DOT_R       = 15;
const SVG_W       = 220;
const DOT_X       = SVG_W / 2;

interface PlacedVoice extends Voice {
  y: number;
  color: string;
}

const placedVoices = computed<PlacedVoice[]>(() => {
  const sorted = [...activeVoices.value].sort((a, b) => b.semitone - a.semitone); // high→low
  let y = STACK_TOP;
  return sorted.map((v, rank) => {
    if (rank > 0) {
      const prev = sorted[rank - 1];
      const semis = Math.abs(v.semitone - prev.semitone);
      if (mode.value === 'closed') {
        // perfectly even — no extra gap anywhere
        y += ROW_GAP;
      } else if (mode.value === 'shell' && prev.role === 'Seventh' && v.role === 'Third') {
        // shell: show the missing 5th as a gap between 7th and 3rd (high→low order)
        y += ROW_GAP + semis * EXTRA_SCALE;
      } else if ((mode.value === 'drop2' || mode.value === 'drop3') && rank === sorted.length - 1) {
        // drop modes: bottom note gets extra space proportional to its distance
        y += ROW_GAP + semis * EXTRA_SCALE;
      } else {
        y += ROW_GAP;
      }
    }
    return { ...v, y, color: ROLE_COLOR[v.role] };
  });
});

const svgHeight = computed(() => {
  const pv = placedVoices.value;
  if (!pv.length) return 100;
  return pv[pv.length - 1].y + DOT_R + STACK_TOP;
});

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

</script>

<template>
  <div class="sbn-edu-widget d2-widget">

    <div class="d2-header">
      <div class="d2-label">Drop Voicings</div>
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

    <div class="d2-stage">
      <svg :viewBox="`0 0 ${SVG_W} ${svgHeight}`" width="100%" class="d2-svg" :style="{ height: svgHeight + 'px' }">

        <!-- Voice dots -->
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

    <p class="d2-caption">
      <template v-if="mode === 'closed'">
        A <strong>closed</strong> voicing packs all four notes inside one octave.
      </template>
      <template v-else-if="mode === 'drop2'">
        The <strong>2nd note from the top</strong> is dropped an octave.
      </template>
      <template v-else-if="mode === 'drop3'">
        The <strong>3rd note from the top</strong> is dropped an octave.
      </template>
      <template v-else>
        A <strong>shell voicing</strong> drops the 5th — it adds little colour. Root, 3rd and 7th remain, giving a lean, harmonically complete sound.
      </template>
    </p>
  </div>
</template>

<style scoped>
.d2-widget {
  display: flex;
  flex-direction: column;
  gap: 12px;
  padding: 1.75rem 1.5rem 1.5rem;
  background: #0f0f17;
  font-family: var(--font-body, system-ui, sans-serif);
}

.d2-header { width: 100%; display: flex; align-items: center; justify-content: space-between; margin-bottom: 2px; }
.d2-label { font-family: 'DM Mono', monospace; font-size: 0.65rem; letter-spacing: 0.15em; text-transform: uppercase; color: #ffffff; }

/* ── Badges ──────────────────────────────────────────────────────────────── */
.d2-badges {
  display: flex;
  gap: 6px;
  justify-content: center;
}

.d2-badge {
  padding: 0.28rem 0.65rem;
  border-radius: 999px;
  border: 1px solid rgba(255,255,255,0.18);
  background: rgba(255,255,255,0.08);
  color: #ffffff;
  font-family: 'DM Mono', monospace;
  font-size: 0.62rem;
  font-weight: 500;
  cursor: pointer;
  transition: background 0.15s, color 0.15s, border-color 0.15s;
}

.d2-badge:hover {
  border-color: rgba(255,255,255,0.35);
  background: rgba(255,255,255,0.12);
}

.d2-badge.active {
  background: rgba(255,255,255,0.92);
  border-color: transparent;
  color: #0f0f17;
}

.d2-stage {
  display: flex;
  justify-content: center;
}

.d2-svg {
  max-width: 220px;
  display: block;
  overflow: visible;
}

/* ── Voice transition ─────────────────────────────────────────────────────── */
.d2-voice {
  transition: transform 0.45s cubic-bezier(0.34, 1.2, 0.64, 1), opacity 0.25s ease;
}

.d2-voice--hidden {
  opacity: 0;
  transition: none;
}


/* ── Caption ─────────────────────────────────────────────────────────────── */
.d2-caption {
  margin: 0;
  font-size: 0.82rem;
  line-height: 1.6;
  color: #ffffff;
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
  fill: #ffffff;
  pointer-events: none;
}

@media (prefers-reduced-motion: reduce) {
  .d2-voice { transition: none; }
}
</style>
