<script setup lang="ts">
/**
 * drop2-visualizer — the drop-2 voicing technique as moving pitch dots.
 *
 * Visual language (shared with TriadBuilder): each note is a labeled circle
 * positioned by pitch height — higher on screen = higher pitch. Pressing
 * "Drop the 2nd voice" arcs the 2nd-from-top dot down an octave; same dot,
 * continuous motion, so the eye reads "this voice moved", not "a note
 * vanished".
 *
 * Self-contained: pure interval math, no tab-editor or store dependency.
 * The voice data layer carries `typicalString` (guitar-aware) even though
 * the current render is abstract dots — a later fretboard pass can use it
 * without restructuring the logic.
 */
import { computed, onMounted, ref } from 'vue';

const CHROMATIC = ['C', 'C♯', 'D', 'D♯', 'E', 'F', 'F♯', 'G', 'G♯', 'A', 'A♯', 'B'] as const;

// 7th-chord qualities → semitone offsets of [third, fifth, seventh] from root.
const QUALITIES = {
  maj7: { label: 'Major 7th',      suffix: 'maj7', offsets: [4, 7, 11] },
  m7:   { label: 'Minor 7th',      suffix: 'm7',   offsets: [3, 7, 10] },
  dom7: { label: 'Dominant 7th',   suffix: '7',    offsets: [4, 7, 10] },
  maj:  { label: 'Major (triad)',  suffix: '',     offsets: [4, 7] },
  min:  { label: 'Minor (triad)',  suffix: 'm',    offsets: [3, 7] },
} as const;

type QualityKey = keyof typeof QUALITIES;

// Fixed role hues — design-system tokens, consistent across all
// chord-construction widgets.
const ROLE_COLOR: Record<string, string> = {
  Root:    'var(--clr-role-root, #f39c12)',
  Third:   'var(--clr-role-third, #3b82f6)',
  Fifth:   'var(--clr-role-fifth, #10b981)',
  Seventh: 'var(--clr-role-seventh, #8b5cf6)',
};

const props = withDefaults(defineProps<{
  quality?: string;
  root?: string;
}>(), {
  quality: 'maj7',
  root: 'C',
});

const isQualityKey = (q: string): q is QualityKey =>
  Object.prototype.hasOwnProperty.call(QUALITIES, q);

const rootIndex = ref(Math.max(0, CHROMATIC.indexOf(props.root as any)));
const quality = ref<QualityKey>(isQualityKey(props.quality) ? props.quality : 'maj7');
const dropped = ref(false);

// Build-up animation: voices appear one-by-one on load.
const visibleCount = ref(0);
const prefersReducedMotion =
  typeof window !== 'undefined' &&
  window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;

interface Voice {
  role: 'Root' | 'Third' | 'Fifth' | 'Seventh';
  note: string;
  semitone: number;   // absolute semitone from C4 (root octave) — drives pitch height
  // Guitar-aware data layer: the string this voice typically lands on in a
  // standard drop-2 grip (6 = low E … 1 = high E). Not yet visualised.
  typicalString: number;
}

// Typical drop-2 string assignment skips the 5th (A) string — that gap is
// what makes a drop-2 grip playable. Closed grips would cluster on adjacent
// strings; the drop opens the spacing.
const CLOSED_STRINGS = { Root: 5, Third: 4, Fifth: 3, Seventh: 2 } as const;

// Closed voicing: root + stacked intervals, all within one octave above root.
const closedVoices = computed<Voice[]>(() => {
  const q = QUALITIES[quality.value];
  const base = rootIndex.value;
  const voices: Voice[] = [
    { role: 'Root', note: CHROMATIC[base % 12], semitone: base, typicalString: CLOSED_STRINGS.Root },
  ];
  const roles: Array<'Third' | 'Fifth' | 'Seventh'> = ['Third', 'Fifth', 'Seventh'];
  q.offsets.forEach((off, i) => {
    voices.push({
      role: roles[i],
      note: CHROMATIC[(base + off) % 12],
      semitone: base + off,
      typicalString: CLOSED_STRINGS[roles[i]],
    });
  });
  return voices;
});

// Drop-2: take the 2nd-highest voice and drop it an octave (−12 semitones).
const drop2Voices = computed<Voice[]>(() => {
  const closed = closedVoices.value;
  if (closed.length < 3) return closed; // triads have no real drop-2
  // Sort ascending by pitch; 2nd-from-top is index length-2.
  const ascending = [...closed].sort((a, b) => a.semitone - b.semitone);
  const secondFromTop = ascending[ascending.length - 2];
  return closed.map((v) =>
    v.role === secondFromTop.role
      ? { ...v, semitone: v.semitone - 12, typicalString: v.typicalString + 1 }
      : v,
  );
});

// The voice that moves — used to label/animate the dropped dot.
const droppedRole = computed(() => {
  const closed = closedVoices.value;
  if (closed.length < 3) return null;
  const ascending = [...closed].sort((a, b) => a.semitone - b.semitone);
  return ascending[ascending.length - 2].role;
});

const activeVoices = computed(() => (dropped.value ? drop2Voices.value : closedVoices.value));

// Render order: top of stack = highest pitch. Map semitone → y position.
const STACK_TOP = 24;       // y of the highest possible dot
const ROW_GAP = 56;         // vertical px per semitone-rank step
const DOT_R = 22;

// All semitones across both states, so the dot's y is stable as it animates.
const pitchRange = computed(() => {
  const all = [...closedVoices.value, ...drop2Voices.value].map((v) => v.semitone);
  return { min: Math.min(...all), max: Math.max(...all) };
});

function yFor(semitone: number): number {
  // Higher pitch = smaller y (nearer the top).
  return STACK_TOP + (pitchRange.value.max - semitone) * (ROW_GAP / 2);
}

const svgHeight = computed(() => {
  const span = pitchRange.value.max - pitchRange.value.min;
  return STACK_TOP * 2 + span * (ROW_GAP / 2) + DOT_R * 2;
});

// Voices with their resolved screen position, in render order.
interface PlacedVoice extends Voice {
  y: number;
  color: string;
  visible: boolean;
}

const placedVoices = computed<PlacedVoice[]>(() =>
  activeVoices.value.map((v) => ({
    ...v,
    y: yFor(v.semitone),
    color: ROLE_COLOR[v.role],
    visible: true,
  })),
);

const chordName = computed(() => `${CHROMATIC[rootIndex.value % 12]}${QUALITIES[quality.value].suffix}`);
const isTriad = computed(() => closedVoices.value.length < 4);

function toggleDrop() {
  if (isTriad.value) return;
  dropped.value = !dropped.value;
}

onMounted(() => {
  if (prefersReducedMotion) {
    visibleCount.value = closedVoices.value.length;
    return;
  }
  // Stagger the build-up: one voice every 140ms, low to high.
  const total = closedVoices.value.length;
  let i = 0;
  const tick = () => {
    i += 1;
    visibleCount.value = i;
    if (i < total) setTimeout(tick, 140);
  };
  setTimeout(tick, 120);
});

// During build-up, only the lowest `visibleCount` voices are shown.
function isVoiceVisible(v: PlacedVoice): boolean {
  if (visibleCount.value >= activeVoices.value.length) return true;
  const ascending = [...activeVoices.value].sort((a, b) => a.semitone - b.semitone);
  const rank = ascending.findIndex((x) => x.role === v.role);
  return rank < visibleCount.value;
}

const SVG_W = 280;
const DOT_X = SVG_W / 2;
</script>

<template>
  <div class="sbn-edu-widget d2-widget">
    <div class="d2-controls">
      <label class="d2-field">
        <span class="d2-field-label">Root</span>
        <select v-model.number="rootIndex" class="d2-select">
          <option v-for="(name, i) in CHROMATIC" :key="i" :value="i">{{ name }}</option>
        </select>
      </label>
      <label class="d2-field">
        <span class="d2-field-label">Quality</span>
        <select v-model="quality" class="d2-select">
          <option v-for="(q, key) in QUALITIES" :key="key" :value="key">{{ q.label }}</option>
        </select>
      </label>
    </div>

    <div class="d2-stage">
      <div class="d2-state-label">
        {{ dropped ? 'Drop 2 voicing' : 'Closed voicing' }}
        <span class="d2-chord-name">{{ chordName }}</span>
      </div>

      <svg :viewBox="`0 0 ${SVG_W} ${svgHeight}`" width="100%" class="d2-svg" :style="{ maxHeight: svgHeight + 'px' }">
        <g
          v-for="v in placedVoices"
          :key="v.role"
          class="d2-voice"
          :class="{
            'd2-voice--hidden': !isVoiceVisible(v),
            'd2-voice--dropped': dropped && v.role === droppedRole,
          }"
          :style="{ transform: `translateY(${v.y}px)` }"
        >
          <circle :cx="DOT_X" cy="0" :r="DOT_R" :fill="v.color" class="d2-dot" />
          <text :x="DOT_X" y="0" text-anchor="middle" dominant-baseline="central" class="d2-dot-note">
            {{ v.note }}
          </text>
          <text :x="DOT_X + DOT_R + 10" y="0" dominant-baseline="central" class="d2-dot-role">
            {{ v.role }}
          </text>
        </g>
      </svg>
    </div>

    <button
      class="d2-toggle"
      :disabled="isTriad"
      @click="toggleDrop"
    >
      {{ isTriad ? 'Triads have no drop-2' : dropped ? 'Reset to closed' : 'Drop the 2nd voice' }}
    </button>

    <p class="d2-caption">
      <template v-if="isTriad">
        Drop-2 needs four voices — pick a 7th-chord quality.
      </template>
      <template v-else-if="dropped">
        The <strong>{{ droppedRole }}</strong> — the 2nd voice from the top —
        dropped an octave. The voicing now spans wider, easier to grab on guitar.
      </template>
      <template v-else>
        A <strong>closed</strong> voicing packs all four notes inside one octave.
        Press the button to drop the 2nd voice from the top.
      </template>
    </p>
  </div>
</template>

<style scoped>
.d2-widget {
  display: flex;
  flex-direction: column;
  gap: 14px;
  padding: 16px;
  background: var(--clr-surface-2, #f7fafc);
  border: 1px solid var(--clr-border, #e2e8f0);
  border-radius: var(--radius, 10px);
  font-family: var(--font-body, system-ui, sans-serif);
}

.d2-controls {
  display: flex;
  gap: 16px;
  flex-wrap: wrap;
}

.d2-field {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.d2-field-label {
  font-size: 12px;
  font-weight: 600;
  color: var(--clr-text-muted, #8896a4);
  text-transform: uppercase;
  letter-spacing: 0.5px;
}

.d2-select {
  padding: 6px 10px;
  font-size: 14px;
  border: 1px solid var(--clr-border, #e2e8f0);
  border-radius: var(--radius-sm, 6px);
  background: var(--clr-surface, #fff);
  color: var(--clr-text, #2c3e50);
}

.d2-stage {
  display: flex;
  flex-direction: column;
  gap: 8px;
  align-items: center;
}

.d2-state-label {
  font-size: 14px;
  font-weight: 700;
  color: var(--clr-text, #2c3e50);
  display: flex;
  gap: 8px;
  align-items: baseline;
}

.d2-chord-name {
  font-size: 13px;
  font-weight: 600;
  color: var(--clr-accent, #f39c12);
}

.d2-svg {
  max-width: 280px;
  display: block;
  overflow: visible;
}

/* Each voice group animates its y position — the dropped dot slides.
   Custom overshoot easing (not --ease) so the dropped voice settles
   with a slight bounce — the motion is the teaching point here. */
.d2-voice {
  transition: transform 0.45s cubic-bezier(0.34, 1.2, 0.64, 1), opacity 0.25s var(--ease, ease);
}

.d2-voice--hidden {
  opacity: 0;
  /* build-up: hidden voices start nudged down so they rise into place */
  transition: none;
}

.d2-dot {
  transition: filter 0.2s ease;
}

.d2-voice--dropped .d2-dot {
  filter: drop-shadow(0 2px 6px rgba(0, 0, 0, 0.25));
}

.d2-dot-note {
  font-size: 15px;
  font-weight: 700;
  fill: #fff;
  pointer-events: none;
}

.d2-dot-role {
  font-size: 12px;
  font-weight: 600;
  fill: var(--clr-text-muted, #8896a4);
  pointer-events: none;
}

.d2-toggle {
  align-self: center;
  padding: 8px 18px;
  font-size: 14px;
  font-weight: 600;
  color: var(--clr-white, #fff);
  background: var(--clr-accent, #f39c12);
  border: none;
  border-radius: var(--radius-sm, 6px);
  cursor: pointer;
  transition: background 0.15s var(--ease, ease);
}

.d2-toggle:hover:not(:disabled) {
  background: var(--clr-accent-dim, #e67e22);
}

.d2-toggle:disabled {
  background: var(--clr-border, #e2e8f0);
  color: var(--clr-text-muted, #8896a4);
  cursor: not-allowed;
}

.d2-caption {
  margin: 0;
  font-size: 13px;
  line-height: 1.5;
  color: var(--clr-text-dim, #5a5a5a);
  text-align: center;
}

@media (prefers-reduced-motion: reduce) {
  .d2-voice {
    transition: none;
  }
}
</style>
