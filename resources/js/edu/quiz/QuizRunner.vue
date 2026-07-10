<script setup lang="ts">
/**
 * The quiz shell — progress, sequencing, submit, results.
 *
 * This is the ONE component both surfaces render: <sbn-quiz> embedded in a
 * lesson, and (later) a standalone quiz page. Question components stay dumb:
 * they receive `question`, they emit `answer`. All state, navigation and
 * network lives here.
 *
 * Nothing is graded client-side. On submit the collected answers POST to the
 * server, which re-derives correctness from the stored key and returns
 * per-question results plus any skill nodes earned.
 */
import { computed, ref, shallowRef, watch, type Component } from 'vue';
import { isQuizQuestion, quizQuestions } from './registry';

export interface QuizQuestion {
  q: string;
  type: string;
  [key: string]: unknown;
}

export interface QuizData {
  slug: string;
  title: string;
  description?: string | null;
  passThreshold: number;
  questions: QuizQuestion[];
  submitUrl: string;
}

interface Result {
  score: number;
  passed: boolean;
  perQuestion: Array<{ q: string; correct: boolean }>;
  earnedNodes: Array<{ slug: string; title: string }>;
}

const props = defineProps<{ quiz: QuizData }>();

const index = ref(0);
/** question id -> submitted value. Absent = unanswered. */
const answers = ref<Record<string, unknown>>({});
const submitting = ref(false);
const result = ref<Result | null>(null);
const submitError = ref<string | null>(null);

/** Lazily-resolved question component for the current index. */
const currentComponent = shallowRef<Component | null>(null);

const total = computed(() => props.quiz.questions.length);
const current = computed(() => props.quiz.questions[index.value] ?? null);
const isLast = computed(() => index.value === total.value - 1);

const answered = computed(() => {
  const q = current.value;
  if (!q) return false;
  const value = answers.value[q.q];
  return value !== undefined && value !== null;
});

const progressPct = computed(() => (total.value ? ((index.value + 1) / total.value) * 100 : 0));

/** Resolve the component for whatever question we're on. */
watch(current, async (question) => {
  currentComponent.value = null;
  if (!question) return;

  if (!isQuizQuestion(question.type)) {
    console.warn(`[QuizRunner] unknown question type "${question.type}"`);
    return;
  }

  const mod: any = await quizQuestions[question.type]();
  currentComponent.value = mod.default ?? mod;
}, { immediate: true });

function onAnswer(value: unknown): void {
  const q = current.value;
  if (q) answers.value[q.q] = value;
}

function next(): void {
  if (index.value < total.value - 1) index.value++;
}

function prev(): void {
  if (index.value > 0) index.value--;
}

async function submit(): Promise<void> {
  if (submitting.value) return;

  submitting.value = true;
  submitError.value = null;

  const payload = {
    answers: props.quiz.questions.map((q) => ({ q: q.q, value: answers.value[q.q] ?? null })),
  };

  try {
    const res = await fetch(props.quiz.submitUrl, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '',
      },
      body: JSON.stringify(payload),
    });

    if (!res.ok) throw new Error(`submit failed: ${res.status}`);

    result.value = await res.json();
  } catch (e) {
    console.warn('[QuizRunner]', e);
    submitError.value = 'Could not submit your answers. Please try again.';
  } finally {
    submitting.value = false;
  }
}

function retry(): void {
  answers.value = {};
  index.value = 0;
  result.value = null;
  submitError.value = null;
}

const scorePct = computed(() => Math.round((result.value?.score ?? 0) * 100));
</script>

<template>
  <section class="quiz" :aria-label="quiz.title">
    <!-- ── Results ──────────────────────────────────────────────────────── -->
    <div v-if="result" class="quiz__results">
      <p class="quiz__verdict" :class="result.passed ? 'is-pass' : 'is-fail'">
        {{ result.passed ? 'Passed' : 'Not yet' }}
      </p>

      <p class="quiz__score">{{ scorePct }}%</p>

      <p class="quiz__score-detail">
        {{ result.perQuestion.filter((r) => r.correct).length }} of {{ total }} correct
        <span v-if="!result.passed">
          — you need {{ Math.round(quiz.passThreshold * 100) }}% to pass.
        </span>
      </p>

      <div v-if="result.earnedNodes.length" class="quiz__earned">
        <p class="quiz__earned-heading">Skills earned</p>
        <ul class="quiz__earned-list">
          <li v-for="node in result.earnedNodes" :key="node.slug">{{ node.title }}</li>
        </ul>
      </div>

      <ol class="quiz__breakdown">
        <li
          v-for="(r, i) in result.perQuestion"
          :key="r.q"
          class="quiz__breakdown-item"
          :class="r.correct ? 'is-correct' : 'is-wrong'"
        >
          <span class="quiz__breakdown-num">{{ i + 1 }}</span>
          <span aria-hidden="true">{{ r.correct ? '✓' : '✗' }}</span>
          <span class="sr-only">{{ r.correct ? 'correct' : 'incorrect' }}</span>
        </li>
      </ol>

      <button type="button" class="quiz__btn is-primary" @click="retry">
        {{ result.passed ? 'Take it again' : 'Try again' }}
      </button>
    </div>

    <!-- ── Questions ────────────────────────────────────────────────────── -->
    <div v-else-if="current" class="quiz__body">
      <header class="quiz__head">
        <h3 class="quiz__title">{{ quiz.title }}</h3>
        <p class="quiz__counter">{{ index + 1 }} / {{ total }}</p>
      </header>

      <div class="quiz__progress" role="progressbar" :aria-valuenow="index + 1" aria-valuemin="1" :aria-valuemax="total">
        <div class="quiz__progress-bar" :style="{ width: `${progressPct}%` }" />
      </div>

      <div class="quiz__question">
        <component
          :is="currentComponent"
          v-if="currentComponent"
          :key="current.q"
          :question="current"
          @answer="onAnswer"
        />
        <p v-else class="quiz__unknown">
          This question can't be displayed (unknown type “{{ current.type }}”).
        </p>
      </div>

      <p v-if="submitError" class="quiz__error">{{ submitError }}</p>

      <footer class="quiz__nav">
        <button type="button" class="quiz__btn" :disabled="index === 0" @click="prev">Back</button>

        <button
          v-if="!isLast"
          type="button"
          class="quiz__btn is-primary"
          :disabled="!answered"
          @click="next"
        >
          Next
        </button>

        <button
          v-else
          type="button"
          class="quiz__btn is-primary"
          :disabled="!answered || submitting"
          @click="submit"
        >
          {{ submitting ? 'Checking…' : 'Finish' }}
        </button>
      </footer>
    </div>

    <p v-else class="quiz__empty">This quiz has no questions yet.</p>
  </section>
</template>

<style scoped>
.quiz {
  container-type: inline-size;
  padding: 1.75rem;
  border: 1px solid var(--quiz-border);
  border-radius: 0.75rem;
  background: var(--quiz-bg);
}

.quiz__body,
.quiz__results {
  display: flex;
  flex-direction: column;
  gap: 1.25rem;
}

.quiz__head {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 1rem;
}

.quiz__title {
  margin: 0;
  font-family: var(--quiz-font-heading);
  font-size: 1.375rem;
  font-weight: 500;
  color: var(--quiz-text);
}

.quiz__counter {
  margin: 0;
  font-family: var(--quiz-font-mono);
  font-size: 0.8125rem;
  color: var(--quiz-text-muted);
}

.quiz__progress {
  height: 2px;
  border-radius: 999px;
  background: var(--quiz-border);
  overflow: hidden;
}

.quiz__progress-bar {
  height: 100%;
  background: var(--quiz-accent);
  transition: width 260ms var(--quiz-ease);
}

.quiz__question {
  min-height: 12rem;
  padding: 0.5rem 0;
}

.quiz__nav {
  display: flex;
  justify-content: space-between;
  gap: 0.75rem;
}

.quiz__btn {
  padding: 0.6rem 1.75rem;
  border: 1px solid var(--quiz-border);
  border-radius: 999px;
  background: transparent;
  color: var(--quiz-text);
  font-family: var(--quiz-font-body);
  font-size: 0.9375rem;
  cursor: pointer;
  transition: border-color 160ms var(--quiz-ease), background 160ms var(--quiz-ease);
}

.quiz__btn.is-primary {
  border-color: var(--quiz-accent);
  color: var(--quiz-accent);
}

.quiz__btn.is-primary:hover:not(:disabled) {
  background: var(--quiz-accent);
  color: var(--quiz-accent-ink);
}

.quiz__btn:disabled {
  opacity: 0.35;
  cursor: not-allowed;
}

/* ── Results ──────────────────────────────────────────────────────────── */

.quiz__results {
  align-items: center;
  text-align: center;
}

.quiz__verdict {
  margin: 0;
  font-family: var(--quiz-font-heading);
  font-size: 1.75rem;
  letter-spacing: 0.02em;
}

.quiz__verdict.is-pass { color: var(--quiz-pass); }
.quiz__verdict.is-fail { color: var(--quiz-text-muted); }

.quiz__score {
  margin: 0;
  font-family: var(--quiz-font-mono);
  font-size: 3rem;
  line-height: 1;
  color: var(--quiz-accent);
}

.quiz__score-detail {
  margin: 0;
  font-size: 0.875rem;
  color: var(--quiz-text-muted);
}

.quiz__earned {
  padding: 1rem 1.5rem;
  border: 1px solid color-mix(in srgb, var(--quiz-pass) 40%, transparent);
  border-radius: 0.5rem;
  background: color-mix(in srgb, var(--quiz-pass) 8%, transparent);
}

.quiz__earned-heading {
  margin: 0 0 0.35rem;
  font-size: 0.75rem;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--quiz-pass);
}

.quiz__earned-list {
  margin: 0;
  padding: 0;
  list-style: none;
  font-family: var(--quiz-font-body);
  color: var(--quiz-text);
}

.quiz__breakdown {
  display: flex;
  flex-wrap: wrap;
  justify-content: center;
  gap: 0.5rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

.quiz__breakdown-item {
  display: flex;
  align-items: center;
  gap: 0.35rem;
  padding: 0.3rem 0.6rem;
  border: 1px solid var(--quiz-border);
  border-radius: 0.35rem;
  font-family: var(--quiz-font-mono);
  font-size: 0.8125rem;
}

.quiz__breakdown-item.is-correct { color: var(--quiz-pass); }
.quiz__breakdown-item.is-wrong   { color: var(--quiz-fail); }

.quiz__breakdown-num { color: var(--quiz-text-muted); }

.quiz__error {
  margin: 0;
  font-size: 0.875rem;
  color: var(--quiz-fail);
}

.quiz__empty,
.quiz__unknown {
  margin: 0;
  text-align: center;
  color: var(--quiz-text-muted);
}

.sr-only {
  position: absolute;
  width: 1px; height: 1px;
  padding: 0; margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  white-space: nowrap;
  border: 0;
}
</style>
