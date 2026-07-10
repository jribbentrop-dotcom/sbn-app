<script setup lang="ts">
/**
 * The workhorse question type. Prompt (text, audio, diagram — anything
 * QuizPrompt renders) plus a set of options; the student picks one, or several
 * when `multi` is set.
 *
 * With an audio prompt this covers all ear training: a `{kind:"chord",
 * showDiagram:false}` prompt asking "which quality?" is a chord-quality ear
 * test, and `{kind:"notes"}` is an interval test. No dedicated component needed.
 *
 * The component submits option **ids**, never indices or labels — the server
 * grades by id, so option order can change (or be shuffled later) without
 * breaking a single stored answer key.
 */
import { computed, ref } from 'vue';
import QuizPrompt, { type QuizPromptSpec } from '../QuizPrompt.vue';

interface Option {
  id: string;
  label: string;
  /** Optional sub-label, e.g. a chord's interval spelling. */
  hint?: string;
}

const props = withDefaults(defineProps<{
  question: {
    q: string;
    prompt?: QuizPromptSpec;
    options: Option[];
    /** Allow several selections. Grading requires the exact set. */
    multi?: boolean;
  };
  disabled?: boolean;
}>(), { disabled: false });

const emit = defineEmits<{ answer: [value: string | string[] | null] }>();

const selected = ref<string[]>([]);

const isMulti = computed(() => props.question.multi === true);

function toggle(id: string): void {
  if (props.disabled) return;

  if (isMulti.value) {
    const i = selected.value.indexOf(id);
    if (i >= 0) selected.value.splice(i, 1);
    else selected.value.push(id);
  } else {
    selected.value = [id];
  }

  emitAnswer();
}

function emitAnswer(): void {
  if (!selected.value.length) {
    emit('answer', null);
    return;
  }
  // A single-select question submits a bare string so the authored `correct`
  // can be written as "b" rather than ["b"]. Multi always submits a list.
  emit('answer', isMulti.value ? [...selected.value] : selected.value[0]);
}

const isSelected = (id: string) => selected.value.includes(id);
</script>

<template>
  <div class="quiz-mc">
    <QuizPrompt v-if="question.prompt" :prompt="question.prompt" />

    <p v-if="isMulti" class="quiz-mc__hint">Select all that apply.</p>

    <div class="quiz-mc__options" :class="{ 'is-multi': isMulti }" role="group">
      <button
        v-for="option in question.options"
        :key="option.id"
        type="button"
        class="quiz-mc__option"
        :class="{ 'is-selected': isSelected(option.id) }"
        :disabled="disabled"
        :aria-pressed="isSelected(option.id)"
        @click="toggle(option.id)"
      >
        <span class="quiz-mc__label">{{ option.label }}</span>
        <span v-if="option.hint" class="quiz-mc__option-hint">{{ option.hint }}</span>
      </button>
    </div>
  </div>
</template>

<style scoped>
.quiz-mc {
  display: flex;
  flex-direction: column;
  gap: 1.5rem;
}

.quiz-mc__hint {
  margin: 0;
  text-align: center;
  font-size: 0.8125rem;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  color: var(--quiz-text-muted);
}

.quiz-mc__options {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(9rem, 1fr));
  gap: 0.75rem;
}

.quiz-mc__option {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 0.25rem;
  padding: 1rem 1.25rem;
  border: 1px solid var(--quiz-border);
  border-radius: 0.5rem;
  background: var(--quiz-surface);
  color: var(--quiz-text);
  font-family: var(--quiz-font-body);
  font-size: 1rem;
  cursor: pointer;
  transition:
    border-color 160ms var(--quiz-ease),
    background 160ms var(--quiz-ease),
    transform 120ms var(--quiz-ease);
}

.quiz-mc__option:hover:not(:disabled) {
  border-color: var(--quiz-accent);
  transform: translateY(-1px);
}

.quiz-mc__option.is-selected {
  border-color: var(--quiz-accent);
  background: var(--quiz-accent-tint);
}

.quiz-mc__option:disabled {
  cursor: default;
  opacity: 0.65;
}

.quiz-mc__label {
  font-weight: 500;
}

.quiz-mc__option-hint {
  font-family: var(--quiz-font-mono);
  font-size: 0.75rem;
  color: var(--quiz-text-muted);
}
</style>
