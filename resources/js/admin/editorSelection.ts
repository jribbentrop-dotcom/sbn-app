import { ref } from 'vue';

/**
 * Shared reactive editor-selection state.
 *
 * LessonEditor.vue writes this on every selection change; LessonAiPanel.vue
 * reads it so the "Replace selection" button enables/disables live as the
 * admin selects text — no window-bridge polling.
 */
export const hasSelection = ref(false);
