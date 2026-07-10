<script setup lang="ts">
/**
 * Tap-the-rhythm. The only "construction" question type: the student produces
 * a performance rather than picking from options.
 *
 * How it works
 * ------------
 * 1. A one-bar count-in clicks on each beat. This is what locks the student to
 *    the grid, and it is why grading is ABSOLUTE against the transport clock
 *    rather than anchored to the student's first tap — anchoring would reward
 *    playing the right rhythm in the wrong place.
 * 2. The pattern's own audio does NOT play during recording (that would make it
 *    a copying exercise, not a test). Students may audition it beforehand.
 * 3. Taps (spacebar or pointer) are stamped with performance.now() and
 *    converted to pattern-relative beats.
 * 4. The RAW taps are emitted, in beats. The server re-grades them against the
 *    pattern's onsets. Nothing is scored here — a client-computed score would
 *    be trivially forged, and raw taps let the tolerance be re-tuned later
 *    against attempts already collected.
 *
 * The count-in click is synthesized as percussion EngineEvents rather than via
 * a general metronome, which doesn't exist in this codebase and isn't needed.
 */
import { computed, onBeforeUnmount, ref } from 'vue';
import QuizPrompt from '../QuizPrompt.vue';
import { useQuizAudio } from '../useQuizAudio';

const props = withDefaults(defineProps<{
  question: {
    q: string;
    prompt: { kind: 'rhythm'; slug: string; bpm?: number; showStrip?: boolean };
    /** Beats of count-in. Defaults to one 4/4 bar. */
    countInBeats?: number;
  };
  disabled?: boolean;
}>(), { disabled: false });

const emit = defineEmits<{ answer: [value: { taps: number[] } | null] }>();

const audio = useQuizAudio();

type Phase = 'idle' | 'counting' | 'recording' | 'done';
const phase = ref<Phase>('idle');
const taps = ref<number[]>([]);
const pattern = ref<any>(null);
/** Pulses the pad on each tap. */
const pulse = ref(0);

const countInBeats = computed(() => props.question.countInBeats ?? 4);

const bpm = computed(() => props.question.prompt.bpm ?? pattern.value?.bpm ?? 90);

const stepBeats = computed(() => (
  { eighth: 0.5, sixteenth: 0.25, triplet: 1 / 3 } as Record<string, number>
)[pattern.value?.gridType] ?? 0.25);

/** One pass through the pattern, in beats. */
const patternBeats = computed(() => (pattern.value?.beats ?? 8) * stepBeats.value);

const msPerBeat = computed(() => 60_000 / bpm.value);

/**
 * Wall-clock ms at which pattern beat 0 occurs — i.e. the moment the count-in
 * ends. Every tap is measured from here.
 */
let patternStartMs = 0;
let stopTimer: ReturnType<typeof setTimeout> | null = null;

function onPromptLoaded(data: any): void {
  pattern.value = data;
}

/** Percussion clicks on each beat of the count-in. */
function countInEvents(): any[] {
  return Array.from({ length: countInBeats.value }, (_, i) => ({
    time: i,
    voice: 'percussion',
    sample: 'hihat-brush',
    // Accent beat 1 so the student hears where the bar starts.
    variant: i === 0 ? 'accent' : 'soft',
    velocity: i === 0 ? 1.0 : 0.7,
    duration: 1,
  }));
}

async function start(): Promise<void> {
  if (props.disabled || phase.value === 'counting' || phase.value === 'recording') return;

  taps.value = [];
  phase.value = 'counting';

  await audio.engine.init({ samplesBaseUrl: '/audio/rhythm-samples/', bpm: bpm.value });
  audio.engine.setTempo(bpm.value);
  audio.engine.load(countInEvents(), { loop: false });
  await audio.engine.play();

  // The count-in occupies beats 0..countInBeats. Recording begins when it ends.
  // performance.now() is captured at play() rather than read from the engine's
  // tick, because a tick fires on a beat boundary and would quantize t0 to the
  // very grid we're trying to measure deviation from.
  const t0 = performance.now();
  patternStartMs = t0 + countInBeats.value * msPerBeat.value;

  setTimeout(() => {
    if (phase.value === 'counting') phase.value = 'recording';
  }, countInBeats.value * msPerBeat.value);

  // Give the student the full pattern plus a small tail to land the last note.
  const recordMs = (countInBeats.value + patternBeats.value) * msPerBeat.value + 350;
  stopTimer = setTimeout(finish, recordMs);
}

function tap(): void {
  if (phase.value !== 'recording' && phase.value !== 'counting') return;

  // Taps during the count-in are ignored rather than recorded as negative
  // beats: the student is still finding the pulse.
  if (phase.value !== 'recording') return;

  const beat = (performance.now() - patternStartMs) / msPerBeat.value;
  taps.value.push(Number(beat.toFixed(4)));
  pulse.value++;
}

function finish(): void {
  if (stopTimer) {
    clearTimeout(stopTimer);
    stopTimer = null;
  }
  audio.stop();
  phase.value = 'done';
  emit('answer', { taps: [...taps.value] });
}

function reset(): void {
  if (stopTimer) {
    clearTimeout(stopTimer);
    stopTimer = null;
  }
  audio.stop();
  taps.value = [];
  phase.value = 'idle';
  emit('answer', null);
}

function onKeydown(e: KeyboardEvent): void {
  if (e.code !== 'Space') return;
  e.preventDefault(); // don't scroll the lesson out from under the student
  tap();
}

onBeforeUnmount(() => {
  if (stopTimer) clearTimeout(stopTimer);
  audio.stop();
});

const padLabel = computed(() => ({
  idle:      'Press Start, wait for the count-in, then tap the rhythm',
  counting:  'Count-in…',
  recording: 'Tap!',
  done:      `${taps.value.length} tap${taps.value.length === 1 ? '' : 's'} recorded`,
}[phase.value]));
</script>

<template>
  <div class="quiz-tap">
    <!-- hide-controls: the prompt's own play button would let the student hear
         the answer while recording. They audition it with "Listen" instead. -->
    <QuizPrompt
      :prompt="{ ...question.prompt, showStrip: question.prompt.showStrip !== false }"
      :hide-controls="phase === 'counting' || phase === 'recording'"
      @loaded="onPromptLoaded"
    />

    <div
      class="quiz-tap__pad"
      :class="[`is-${phase}`, { 'is-pulsing': pulse > 0 }]"
      :key="pulse"
      role="button"
      tabindex="0"
      :aria-disabled="disabled || phase === 'idle' || phase === 'done'"
      @pointerdown.prevent="tap"
      @keydown="onKeydown"
    >
      <span class="quiz-tap__pad-label">{{ padLabel }}</span>
      <span v-if="phase === 'recording'" class="quiz-tap__count">{{ taps.length }}</span>
    </div>

    <div class="quiz-tap__controls">
      <button
        v-if="phase === 'idle'"
        type="button"
        class="quiz-tap__btn is-primary"
        :disabled="disabled || !pattern"
        @click="start"
      >
        Start
      </button>

      <button
        v-if="phase === 'counting' || phase === 'recording'"
        type="button"
        class="quiz-tap__btn"
        @click="finish"
      >
        Done
      </button>

      <button
        v-if="phase === 'done'"
        type="button"
        class="quiz-tap__btn"
        :disabled="disabled"
        @click="reset"
      >
        Try again
      </button>
    </div>

    <p class="quiz-tap__hint">Tap the pad or press <kbd>Space</kbd>.</p>
  </div>
</template>

<style scoped>
.quiz-tap {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 1.25rem;
}

.quiz-tap__pad {
  position: relative;
  display: grid;
  place-items: center;
  gap: 0.5rem;
  width: 100%;
  max-width: 26rem;
  min-height: 9rem;
  padding: 1.5rem;
  border: 2px dashed var(--quiz-border);
  border-radius: 0.75rem;
  background: var(--quiz-surface);
  color: var(--quiz-text-muted);
  text-align: center;
  cursor: pointer;
  user-select: none;
  touch-action: manipulation;
  transition: border-color 160ms var(--quiz-ease), background 120ms var(--quiz-ease);
}

.quiz-tap__pad.is-counting {
  border-color: var(--quiz-accent);
  border-style: solid;
}

.quiz-tap__pad.is-recording {
  border-color: var(--quiz-fail);
  border-style: solid;
  color: var(--quiz-text);
  animation: quiz-tap-live 900ms ease-in-out infinite;
}

.quiz-tap__pad.is-recording.is-pulsing {
  animation: quiz-tap-hit 140ms ease-out;
}

.quiz-tap__pad.is-idle:hover {
  border-color: var(--quiz-accent);
}

.quiz-tap__pad.is-done {
  border-style: solid;
  border-color: var(--quiz-border);
  cursor: default;
}

@keyframes quiz-tap-live {
  0%, 100% { background: var(--quiz-surface); }
  50%      { background: color-mix(in srgb, var(--quiz-fail) 10%, var(--quiz-surface)); }
}

@keyframes quiz-tap-hit {
  from { background: color-mix(in srgb, var(--quiz-accent) 32%, var(--quiz-surface)); }
  to   { background: var(--quiz-surface); }
}

.quiz-tap__pad-label {
  font-family: var(--quiz-font-body);
  font-size: 0.9375rem;
}

.quiz-tap__count {
  font-family: var(--quiz-font-mono);
  font-size: 2rem;
  color: var(--quiz-accent);
}

.quiz-tap__controls {
  display: flex;
  gap: 0.75rem;
}

.quiz-tap__btn {
  padding: 0.6rem 1.5rem;
  border: 1px solid var(--quiz-border);
  border-radius: 999px;
  background: transparent;
  color: var(--quiz-text);
  font-family: var(--quiz-font-body);
  font-size: 0.9375rem;
  cursor: pointer;
  transition: border-color 160ms var(--quiz-ease);
}

.quiz-tap__btn.is-primary {
  border-color: var(--quiz-accent);
  color: var(--quiz-accent);
}

.quiz-tap__btn:hover:not(:disabled) {
  border-color: var(--quiz-accent);
}

.quiz-tap__btn:disabled {
  opacity: 0.4;
  cursor: not-allowed;
}

.quiz-tap__hint {
  margin: 0;
  font-size: 0.8125rem;
  color: var(--quiz-text-muted);
}

kbd {
  padding: 0.1rem 0.35rem;
  border: 1px solid var(--quiz-border);
  border-radius: 0.25rem;
  font-family: var(--quiz-font-mono);
  font-size: 0.75rem;
}
</style>
