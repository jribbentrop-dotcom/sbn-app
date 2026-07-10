<script setup lang="ts">
/**
 * The declarative prompt renderer — the keystone of DB-only quiz authoring.
 *
 * Every question carries a `prompt` object. This component is the only thing
 * that interprets it, which is why a new quiz never needs new code: an author
 * writes JSON referencing an existing chord/rhythm slug and gets an
 * interactive, audio-driven prompt for free.
 *
 *   { kind: "chord",   slug, root?, showDiagram? }  hear a voicing (optionally see it)
 *   { kind: "rhythm",  slug, bpm?, loop?, showStrip? }  hear/see a rhythm pattern
 *   { kind: "notes",   midi: [], mode?, gapSec? }   an interval or melodic fragment
 *   { kind: "diagram", slug, root? }                see a voicing, silently
 *   { kind: "text",    text }                       plain question text
 *
 * Consequence worth stating: there is no "ear-training" question TYPE. A
 * multiple-choice question whose prompt is `{kind:"chord", showDiagram:false}`
 * IS a chord-quality ear test. Intervals are `{kind:"notes"}`. The prompt
 * carries the audio; the question type only decides how the answer is entered.
 */
import { computed, onMounted, ref, watch } from 'vue';
import ChordDiagram from '../../Components/Library/ChordDiagram.vue';
import RhythmStrip from '../../Components/Library/RhythmStrip.vue';
import { useQuizAudio } from './useQuizAudio';

export interface QuizPromptSpec {
  kind: 'chord' | 'rhythm' | 'notes' | 'diagram' | 'text';
  /** chord | diagram | rhythm */
  slug?: string;
  /** chord | diagram — transposes the shape's displayed root. */
  root?: string;
  /** chord — hide the diagram to make it an ear test. Defaults to false. */
  showDiagram?: boolean;
  /** rhythm — show the step grid. Defaults to true; set false for a tap test. */
  showStrip?: boolean;
  /** rhythm */
  bpm?: number;
  loop?: boolean;
  /** notes */
  midi?: number[];
  mode?: 'melodic' | 'harmonic';
  gapSec?: number;
  /** text */
  text?: string;
}

const props = withDefaults(defineProps<{
  prompt: QuizPromptSpec;
  /** Hide the play button — the question type drives audio itself (RhythmTap). */
  hideControls?: boolean;
}>(), { hideControls: false });

/** The fetched payload for slug-backed kinds, exposed so parents can reuse it. */
const emit = defineEmits<{ loaded: [data: any] }>();

const audio = useQuizAudio();
const data = ref<any>(null);
const error = ref(false);

/**
 * Slug-keyed fetch cache, mirroring mountSbnNodes' own. A quiz that asks about
 * the same voicing three times fetches it once.
 */
const cache = new Map<string, Promise<any>>();

function fetchOnce(url: string): Promise<any> {
  if (!cache.has(url)) {
    cache.set(url, fetch(url, { headers: { Accept: 'application/json' } }).then((r) => {
      if (!r.ok) throw new Error(`quiz prompt fetch failed: ${r.status} ${url}`);
      return r.json();
    }));
  }
  return cache.get(url)!;
}

/** The same /api/sbn/* endpoints mountSbnNodes already uses. */
const endpoint = computed<string | null>(() => {
  const { kind, slug, root } = props.prompt;
  if (!slug) return null;
  if (kind === 'chord' || kind === 'diagram') {
    return `/api/sbn/chords/${slug}${root ? `?root=${encodeURIComponent(root)}` : ''}`;
  }
  if (kind === 'rhythm') return `/api/sbn/rhythms/${slug}`;
  return null;
});

const needsAudio = computed(() => ['chord', 'rhythm', 'notes'].includes(props.prompt.kind));

const showDiagram = computed(() =>
  props.prompt.kind === 'diagram' || (props.prompt.kind === 'chord' && props.prompt.showDiagram === true),
);

const showStrip = computed(() =>
  props.prompt.kind === 'rhythm' && props.prompt.showStrip !== false,
);

async function load(): Promise<void> {
  error.value = false;
  data.value = null;

  const url = endpoint.value;
  if (!url) return;

  try {
    data.value = await fetchOnce(url);
    emit('loaded', data.value);
  } catch (e) {
    console.warn('[QuizPrompt]', e);
    error.value = true;
  }
}

onMounted(load);
watch(() => [props.prompt.kind, props.prompt.slug, props.prompt.root], load);

/** Play whatever this prompt sounds like. Safe to call before data arrives. */
async function play(): Promise<void> {
  if (audio.isPlaying.value) {
    audio.stop();
    return;
  }

  const p = props.prompt;

  if (p.kind === 'chord' && data.value) {
    await audio.playChord(data.value);
  } else if (p.kind === 'rhythm' && data.value) {
    await audio.playRhythm(data.value, p.bpm, p.loop ?? true);
  } else if (p.kind === 'notes' && p.midi?.length) {
    await audio.playNotes(p.midi, p.mode ?? 'melodic', p.gapSec ?? 0.6);
  }
}

defineExpose({ play, stop: audio.stop, data });
</script>

<template>
  <div class="quiz-prompt">
    <p v-if="prompt.text" class="quiz-prompt__text">{{ prompt.text }}</p>

    <p v-if="error" class="quiz-prompt__error">
      Couldn't load <code>{{ prompt.slug }}</code>.
    </p>

    <!-- Audio-bearing prompts get a big, obvious replay affordance. An ear test
         is unusable if the student can't hear the prompt again on demand. -->
    <button
      v-if="needsAudio && !hideControls"
      type="button"
      class="quiz-prompt__play"
      :class="{ 'is-playing': audio.isPlaying.value }"
      :disabled="!!endpoint && !data && !error"
      @click="play"
    >
      <span class="quiz-prompt__play-icon" aria-hidden="true">
        {{ audio.isPlaying.value ? '■' : '▶' }}
      </span>
      <span>{{ audio.isPlaying.value ? 'Stop' : 'Play' }}</span>
    </button>

    <div v-if="showDiagram && data" class="quiz-prompt__diagram">
      <ChordDiagram :chord="data" />
    </div>

    <div v-if="showStrip && data" class="quiz-prompt__strip">
      <!-- playable=false: the strip is a visual reference. The prompt's own
           play button owns audio, so a tap test can show the grid without
           handing the student a metronome. -->
      <RhythmStrip :pattern="data" :playable="false" :show-meta="true" />
    </div>
  </div>
</template>

<style scoped>
.quiz-prompt {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 1rem;
}

.quiz-prompt__text {
  margin: 0;
  font-family: var(--quiz-font-body);
  font-size: 1.125rem;
  line-height: 1.5;
  text-align: center;
  color: var(--quiz-text);
}

.quiz-prompt__error {
  margin: 0;
  font-size: 0.875rem;
  color: var(--quiz-fail);
}

.quiz-prompt__play {
  display: inline-flex;
  align-items: center;
  gap: 0.6rem;
  padding: 0.75rem 1.5rem;
  border: 1px solid var(--quiz-accent);
  border-radius: 999px;
  background: transparent;
  color: var(--quiz-accent);
  font-family: var(--quiz-font-body);
  font-size: 0.9375rem;
  letter-spacing: 0.02em;
  cursor: pointer;
  transition: background 160ms var(--quiz-ease), color 160ms var(--quiz-ease);
}

.quiz-prompt__play:hover:not(:disabled) {
  background: var(--quiz-accent);
  color: var(--quiz-accent-ink);
}

.quiz-prompt__play:disabled {
  opacity: 0.4;
  cursor: wait;
}

.quiz-prompt__play.is-playing {
  background: var(--quiz-accent);
  color: var(--quiz-accent-ink);
}

.quiz-prompt__play-icon {
  font-size: 0.75rem;
  line-height: 1;
}

.quiz-prompt__diagram,
.quiz-prompt__strip {
  width: 100%;
  max-width: 22rem;
}

.quiz-prompt__strip {
  max-width: 30rem;
}
</style>
