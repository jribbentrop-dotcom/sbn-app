/**
 * Quiz question-type registry — the single source of truth for what a
 * question's `type` field may name.
 *
 * Deliberately mirrors resources/js/edu/widgets/registry.ts: slug → lazy import
 * thunk, plus a type guard. QuizRunner awaits the thunk and mounts the
 * component. Adding a question type = one Vue component + one line here + one
 * `case` in QuizGradingService::gradeQuestion().
 *
 * Note what ISN'T here. There is no `ear-interval` type: ear training is a
 * multiple-choice question with an audio prompt (see QuizPrompt). A type earns
 * its place only when the ANSWER INPUT is genuinely different — text buttons
 * (multiple-choice), a rack of diagrams (chord-identify), a tap pad
 * (rhythm-tap). Everything else is prompt configuration, and prompt
 * configuration is data.
 */

export const quizQuestions = {
  'multiple-choice': () => import('./questions/MultipleChoice.vue'),
  'chord-identify':  () => import('./questions/ChordIdentify.vue'),
  'rhythm-tap':      () => import('./questions/RhythmTap.vue'),
} as const;

export type QuizQuestionType = keyof typeof quizQuestions;

/** True if `type` names a registered question component. Narrows the type. */
export function isQuizQuestion(type: string): type is QuizQuestionType {
  return Object.prototype.hasOwnProperty.call(quizQuestions, type);
}
