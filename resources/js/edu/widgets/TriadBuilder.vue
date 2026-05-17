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
import { computed, onMounted, ref } from 'vue';

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

const chordName = computed(() => {
  const suffix = { major: '', minor: 'm', diminished: '°', augmented: '+' }[quality.value];
  return `${root.value}${suffix}`;
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
</script>

<template>
  <div class="sbn-edu-widget triad-builder">
    <div class="tb-controls">
      <label class="tb-field">
        <span class="tb-field-label">Root</span>
        <select v-model.number="rootIndex" class="tb-select">
          <option v-for="(name, i) in CHROMATIC" :key="i" :value="i">{{ name }}</option>
        </select>
      </label>
      <label class="tb-field">
        <span class="tb-field-label">Quality</span>
        <select v-model="quality" class="tb-select">
          <option v-for="(q, key) in QUALITIES" :key="key" :value="key">{{ q.label }}</option>
        </select>
      </label>
    </div>

    <div class="tb-stage">
      <div class="tb-chord-name">{{ chordName }}</div>

      <svg :viewBox="`0 0 ${SVG_W} ${svgHeight}`" width="100%" class="tb-svg" :style="{ maxHeight: svgHeight + 'px' }">
        <g
          v-for="v in placedVoices"
          :key="v.role"
          class="tb-voice"
          :class="{ 'tb-voice--hidden': !isVoiceVisible(v) }"
          :style="{ transform: `translateY(${v.y}px)` }"
        >
          <circle :cx="DOT_X" cy="0" :r="DOT_R" :fill="v.color" class="tb-dot" />
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
  </div>
</template>

<style scoped>
.triad-builder {
  display: flex;
  flex-direction: column;
  gap: 14px;
  padding: 16px;
  background: var(--clr-surface-2, #f7fafc);
  border: 1px solid var(--clr-border, #e2e8f0);
  border-radius: var(--radius, 10px);
  font-family: var(--font-body, system-ui, sans-serif);
}

.tb-controls {
  display: flex;
  gap: 16px;
  flex-wrap: wrap;
}

.tb-field {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.tb-field-label {
  font-size: 12px;
  font-weight: 600;
  color: var(--clr-text-muted, #8896a4);
  text-transform: uppercase;
  letter-spacing: 0.5px;
}

.tb-select {
  padding: 6px 10px;
  font-size: 14px;
  border: 1px solid var(--clr-border, #e2e8f0);
  border-radius: var(--radius-sm, 6px);
  background: var(--clr-surface, #fff);
  color: var(--clr-text, #2c3e50);
}

.tb-stage {
  display: flex;
  flex-direction: column;
  gap: 8px;
  align-items: center;
}

.tb-chord-name {
  font-size: 22px;
  font-weight: 700;
  color: var(--clr-text, #2c3e50);
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
  fill: var(--clr-text, #2c3e50);
  pointer-events: none;
}

.tb-dot-interval {
  font-size: 11px;
  fill: var(--clr-text-muted, #8896a4);
  pointer-events: none;
}

@media (prefers-reduced-motion: reduce) {
  .tb-voice {
    transition: none;
  }
}
</style>
