import { createApp } from 'vue';
import LessonEditor from './LessonEditor.vue';
import LessonPalette from './LessonPalette.vue';
import LessonAiPanel from './LessonAiPanel.vue';

const editorMount = document.getElementById('lesson-editor');
if (editorMount) {
    const dataEl = document.getElementById('lesson-content-data');
    const initial = dataEl ? JSON.parse(dataEl.textContent ?? 'null') ?? '' : '';
    createApp(LessonEditor, { initial }).mount(editorMount);
}

const paletteMount = document.getElementById('lesson-palette');
if (paletteMount) {
    createApp(LessonPalette).mount(paletteMount);
}

// AI chat drawer — fixed-position, so it just needs any mount node.
const aiMount = document.getElementById('lesson-ai-panel');
if (aiMount) {
    createApp(LessonAiPanel).mount(aiMount);
}
