<script setup lang="ts">
/**
 * Chord identification, in both directions.
 *
 *   answerMode "name"    — the prompt shows (or plays) a voicing; the student
 *                          picks its name from text options.
 *   answerMode "diagram" — the prompt names a chord; the student picks the
 *                          matching diagram from a rack of voicings.
 *
 * This type exists (rather than folding into multiple-choice) only because of
 * the second mode: the ANSWER INPUT is a set of rendered diagrams, not text.
 * In "name" mode it is deliberately identical to multiple-choice, and the
 * server grades both with the same comparator.
 *
 * Submitted value is a chord slug (diagram mode) or an option id (name mode).
 */
import { onMounted, ref } from 'vue';
import ChordDiagram from '../../../Components/Library/ChordDiagram.vue';
import QuizPrompt, { type QuizPromptSpec } from '../QuizPrompt.vue';

interface NameOption {
  id: string;
  label: string;
}

interface DiagramOption {
  /** The chord slug — this is what gets submitted and graded. */
  slug: string;
  root?: string;
}

const props = withDefaults(defineProps<{
  question: {
    q: string;
    prompt?: QuizPromptSpec;
    answerMode?: 'name' | 'diagram';
    /** answerMode="name" */
    options?: NameOption[];
    /** answerMode="diagram" */
    choices?: DiagramOption[];
  };
  disabled?: boolean;
}>(), { disabled: false });

const emit = defineEmits<{ answer: [value: string | null] }>();

const selected = ref<string | null>(null);
/** slug -> fetched chord payload, for the diagram rack. */
const diagrams = ref<Record<string, any>>({});

function pick(value: string): void {
  if (props.disabled) return;
  selected.value = value;
  emit('answer', value);
}

/**
 * Fetch each answer-diagram once. These come from the same chord endpoint the
 * prompt uses, so a chord appearing as both prompt and option is cheap.
 */
async function loadChoices(): Promise<void> {
  const choices = props.question.choices ?? [];

  await Promise.all(choices.map(async (choice) => {
    const url = `/api/sbn/chords/${choice.slug}${choice.root ? `?root=${encodeURIComponent(choice.root)}` : ''}`;
    try {
      const res = await fetch(url, { headers: { Accept: 'application/json' } });
      if (!res.ok) throw new Error(String(res.status));
      diagrams.value[choice.slug] = await res.json();
    } catch (e) {
      console.warn(`[ChordIdentify] failed to load ${choice.slug}`, e);
    }
  }));
}

onMounted(() => {
  if ((props.question.answerMode ?? 'name') === 'diagram') loadChoices();
});
</script>

<template>
  <div class="quiz-ci">
    <QuizPrompt v-if="question.prompt" :prompt="question.prompt" />

    <!-- Name mode: text options, same shape as multiple-choice. -->
    <div v-if="(question.answerMode ?? 'name') === 'name'" class="quiz-ci__names">
      <button
        v-for="option in question.options ?? []"
        :key="option.id"
        type="button"
        class="quiz-ci__name"
        :class="{ 'is-selected': selected === option.id }"
        :disabled="disabled"
        :aria-pressed="selected === option.id"
        @click="pick(option.id)"
      >
        {{ option.label }}
      </button>
    </div>

    <!-- Diagram mode: a rack of voicings; the student picks the shape. -->
    <div v-else class="quiz-ci__diagrams">
      <button
        v-for="choice in question.choices ?? []"
        :key="choice.slug"
        type="button"
        class="quiz-ci__diagram"
        :class="{ 'is-selected': selected === choice.slug }"
        :disabled="disabled"
        :aria-pressed="selected === choice.slug"
        @click="pick(choice.slug)"
      >
        <ChordDiagram v-if="diagrams[choice.slug]" :chord="diagrams[choice.slug]" />
        <span v-else class="quiz-ci__loading">…</span>
      </button>
    </div>
  </div>
</template>

<style scoped>
.quiz-ci {
  display: flex;
  flex-direction: column;
  gap: 1.5rem;
}

.quiz-ci__names {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(8rem, 1fr));
  gap: 0.75rem;
}

.quiz-ci__name {
  padding: 1rem 1.25rem;
  border: 1px solid var(--quiz-border);
  border-radius: 0.5rem;
  background: var(--quiz-surface);
  color: var(--quiz-text);
  font-family: var(--quiz-font-body);
  font-size: 1rem;
  cursor: pointer;
  transition: border-color 160ms var(--quiz-ease), background 160ms var(--quiz-ease);
}

.quiz-ci__name:hover:not(:disabled),
.quiz-ci__diagram:hover:not(:disabled) {
  border-color: var(--quiz-accent);
}

.quiz-ci__name.is-selected,
.quiz-ci__diagram.is-selected {
  border-color: var(--quiz-accent);
  background: var(--quiz-accent-tint);
}

.quiz-ci__name:disabled,
.quiz-ci__diagram:disabled {
  cursor: default;
  opacity: 0.65;
}

.quiz-ci__diagrams {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(7.5rem, 1fr));
  gap: 0.75rem;
}

.quiz-ci__diagram {
  display: grid;
  place-items: center;
  min-height: 8rem;
  padding: 0.75rem;
  border: 1px solid var(--quiz-border);
  border-radius: 0.5rem;
  background: var(--quiz-surface);
  cursor: pointer;
  transition: border-color 160ms var(--quiz-ease), background 160ms var(--quiz-ease);
}

.quiz-ci__loading {
  color: var(--quiz-text-muted);
}
</style>
