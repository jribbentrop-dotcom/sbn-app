<script setup lang="ts">
/**
 * triad-builder — the first edu widget, recast in the shared pitch-dot
 * visual language (same as Drop2Visualizer): each note is a labeled circle
 * positioned by pitch height — higher on screen = higher pitch.
 *
 * On load the three dots stack on one-by-one (root → third → fifth).
 * Changing the quality slides the third/fifth dots to their new heights,
 * so the eye reads "a minor third sits lower than a major third".
 *
 * Self-contained: pure interval math, no tab-editor or store dependency.
 */
import { computed, onMounted, ref, watch } from 'vue';

// Twelve pitch classes, sharp spelling — enough for a teaching illustration.
const CHROMATIC = ['C', 'C♯', 'D', 'D♯', 'E', 'F', 'F♯', 'G', 'G♯', 'A', 'A♯', 'B'] as const;

// Triad qualities → semitone offsets of [third, fifth] from the root.
const QUALITIES = {
  major:      { label: 'Major',      thirdLabel: 'Major 3rd',     fifthLabel: 'Perfect 5th',     offsets: [4, 7] },
  minor:      { label: 'Minor',      thirdLabel: 'Minor 3rd',     fifthLabel: 'Perfect 5th',     offsets: [3, 7] },
  diminished: { label: 'Diminished', thirdLabel: 'Minor 3rd',     fifthLabel: 'Diminished 5th',  offsets: [3, 6] },
  augmented:  { label: 'Augmented',  thirdLabel: 'Major 3rd',     fifthLabel: 'Augmented 5th',   offsets: [4, 8] },
} as const;

type QualityKey = keyof typeof QUALITIES;

// Fixed role hues — design-system tokens, consistent across all
// chord-construction widgets.
const ROLE_COLOR: Record<string, string> = {
  Root:  'var(--clr-role-root, #f39c12)',
  Third: 'var(--clr-role-third, #3b82f6)',
  Fifth: 'var(--clr-role-fifth, #10b981)',
};

const props = withDefaults(defineProps<{
  root?: string;
  quality?: string;
}>(), {
  root: 'C',
  quality: 'major',
});

const isQualityKey = (q: string): q is QualityKey =>
  Object.prototype.hasOwnProperty.call(QUALITIES, q);

const rootIndex = ref(Math.max(0, CHROMATIC.indexOf(props.root as any)));
const quality = ref<QualityKey>(isQualityKey(props.quality) ? props.quality : 'major');

const root = computed(() => CHROMATIC[rootIndex.value]);

interface Voice {
  role: 'Root' | 'Third' | 'Fifth';
  note: string;
  label: string;
  semitone: number;   // absolute semitone from root octave — drives pitch height
}

const voices = computed<Voice[]>(() => {
  const q = QUALITIES[quality.value];
  const [thirdOff, fifthOff] = q.offsets;
  const base = rootIndex.value;
  return [
    { role: 'Root',  note: root.value,                              label: 'Root',         semitone: base },
    { role: 'Third', note: CHROMATIC[(base + thirdOff) % 12],        label: q.thirdLabel,   semitone: base + thirdOff },
    { role: 'Fifth', note: CHROMATIC[(base + fifthOff) % 12],        label: q.fifthLabel,   semitone: base + fifthOff },
  ];
});


// ── Pitch-dot geometry ─────────────────────────────────────────────────────
// The third/fifth offsets span 3..8 semitones above the root, so a fixed
// range keeps dot heights stable as the quality changes.
const PITCH_MIN = 0;     // root
const PITCH_MAX = 8;     // augmented 5th — the highest a triad voice reaches
const STACK_TOP = 28;
const ROW_GAP = 30;      // px per semitone
const DOT_R = 24;

function yFor(semitone: number): number {
  // Higher pitch = smaller y. Offset is relative to the root.
  const rel = semitone - rootIndex.value;
  return STACK_TOP + (PITCH_MAX - rel) * ROW_GAP;
}

const SVG_W = 280;
const DOT_X = SVG_W / 2;
const svgHeight = STACK_TOP * 2 + (PITCH_MAX - PITCH_MIN) * ROW_GAP + DOT_R;

interface PlacedVoice extends Voice {
  y: number;
  color: string;
}

const placedVoices = computed<PlacedVoice[]>(() =>
  voices.value.map((v) => ({
    ...v,
    y: yFor(v.semitone),
    color: ROLE_COLOR[v.role],
  })),
);

// ── Build-up animation ─────────────────────────────────────────────────────
const visibleCount = ref(0);
const prefersReducedMotion =
  typeof window !== 'undefined' &&
  window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;

onMounted(() => {
  if (prefersReducedMotion) {
    visibleCount.value = 3;
    return;
  }
  let i = 0;
  const tick = () => {
    i += 1;
    visibleCount.value = i;
    if (i < 3) setTimeout(tick, 160);
  };
  setTimeout(tick, 120);
});

// Voices stack low → high: root (rank 0), third (1), fifth (2).
const VOICE_RANK: Record<string, number> = { Root: 0, Third: 1, Fifth: 2 };

function isVoiceVisible(v: PlacedVoice): boolean {
  return VOICE_RANK[v.role] < visibleCount.value;
}

// ── Pulse on quality change ────────────────────────────────────────────────
// Tracks which roles are currently pulsing so we can add/remove a CSS class.
const pulsingRoles = ref<Set<string>>(new Set());

watch(quality, (next: QualityKey, prev: QualityKey) => {
  if (prefersReducedMotion) return;
  const prevQ = QUALITIES[prev];
  const nextQ = QUALITIES[next];
  const moved = new Set<string>();
  if (prevQ.offsets[0] !== nextQ.offsets[0]) moved.add('Third');
  if (prevQ.offsets[1] !== nextQ.offsets[1]) moved.add('Fifth');
  if (!moved.size) return;

  pulsingRoles.value = moved;
  setTimeout(() => { pulsingRoles.value = new Set(); }, 500);
});
</script>

<template>
  <div class="sbn-edu-widget triad-builder">
    <div class="tb-stage">
      <svg :viewBox="`0 0 ${SVG_W} ${svgHeight}`" width="100%" class="tb-svg" :style="{ maxHeight: svgHeight + 'px' }">
        <g
          v-for="v in placedVoices"
          :key="v.role"
          class="tb-voice"
          :class="{ 'tb-voice--hidden': !isVoiceVisible(v) }"
          :style="{ transform: `translateY(${v.y}px)` }"
        >
          <circle
            :cx="DOT_X" cy="0" :r="DOT_R" :fill="v.color"
            class="tb-dot"
            :class="{
              'tb-dot--popin': VOICE_RANK[v.role] === visibleCount - 1,
              'tb-dot--pulse': pulsingRoles.has(v.role),
            }"
          />
          <text :x="DOT_X" y="0" text-anchor="middle" dominant-baseline="central" class="tb-dot-note">
            {{ v.note }}
          </text>
          <text :x="DOT_X + DOT_R + 12" y="0" dominant-baseline="central" class="tb-dot-role">
            {{ v.role }}
          </text>
          <text :x="DOT_X + DOT_R + 12" y="15" dominant-baseline="central" class="tb-dot-interval">
            {{ v.label }}
          </text>
        </g>
      </svg>
    </div>

    <div class="tb-badges">
      <button
        v-for="(q, key) in QUALITIES"
        :key="key"
        class="tb-badge"
        :class="{ active: quality === key }"
        @click="quality = key"
      >{{ q.label }}</button>
    </div>
  </div>
</template>

<style scoped>
.triad-builder {
  display: flex;
  flex-direction: column;
  gap: 12px;
  padding: 16px;
  background: #0f0f17;
  font-family: var(--font-body, system-ui, sans-serif);
}

.tb-stage {
  display: flex;
  flex-direction: column;
  align-items: center;
}

.tb-badges {
  display: flex;
  gap: 6px;
  justify-content: center;
  flex-wrap: wrap;
}

.tb-badge {
  padding: 0.32rem 0.75rem;
  border-radius: 999px;
  border: 1px solid rgba(255,255,255,0.18);
  background: rgba(255,255,255,0.08);
  color: rgba(255,255,255,0.6);
  font-family: 'DM Mono', monospace;
  font-size: 0.65rem;
  font-weight: 500;
  cursor: pointer;
  transition: background 0.15s, color 0.15s, border-color 0.15s;
}

.tb-badge:hover {
  border-color: rgba(255,255,255,0.35);
  color: rgba(255,255,255,0.9);
  background: rgba(255,255,255,0.12);
}

.tb-badge.active {
  background: rgba(255,255,255,0.92);
  border-color: transparent;
  color: #0f0f17;
}

.tb-svg {
  max-width: 280px;
  display: block;
  overflow: visible;
}

/* Each voice group animates its y position — sliding when the quality changes.
   Custom overshoot easing (not --ease) so the slide settles with a slight
   bounce — the motion is the teaching point here. */
.tb-voice {
  transition: transform 0.4s cubic-bezier(0.34, 1.2, 0.64, 1), opacity 0.25s var(--ease, ease);
}

.tb-voice--hidden {
  opacity: 0;
  transition: none;
}

.tb-dot-note {
  font-size: 16px;
  font-weight: 700;
  fill: #fff;
  pointer-events: none;
}

.tb-dot-role {
  font-size: 13px;
  font-weight: 700;
  fill: rgba(255,255,255,0.85);
  pointer-events: none;
}

.tb-dot-interval {
  font-size: 11px;
  fill: rgba(255,255,255,0.55);
  pointer-events: none;
}

/* Pop-in: the dot that just became visible springs from scale 0 */
@keyframes tb-popin {
  0%   { transform: scale(0);    opacity: 0; }
  60%  { transform: scale(1.25); opacity: 1; }
  100% { transform: scale(1);    opacity: 1; }
}

/* Pulse: a quick scale ripple on the dot that moved quality */
@keyframes tb-pulse {
  0%   { transform: scale(1);    }
  35%  { transform: scale(1.35); }
  100% { transform: scale(1);    }
}

.tb-dot--popin {
  animation: tb-popin 0.35s cubic-bezier(0.34, 1.4, 0.64, 1) both;
  transform-origin: center;
  transform-box: fill-box;
}

.tb-dot--pulse {
  animation: tb-pulse 0.45s cubic-bezier(0.34, 1.2, 0.64, 1) both;
  transform-origin: center;
  transform-box: fill-box;
}

@media (prefers-reduced-motion: reduce) {
  .tb-voice { transition: none; }
  .tb-dot--popin, .tb-dot--pulse { animation: none; }
}
</style>
