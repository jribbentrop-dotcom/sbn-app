import { createApp } from 'vue';
import LessonEditor from './LessonEditor.vue';
import LessonPalette from './LessonPalette.vue';

const editorMount = document.getElementById('lesson-editor');
if (editorMount) {
    const initial = editorMount.dataset.initial ?? '';
    createApp(LessonEditor, { initial }).mount(editorMount);
}

const paletteMount = document.getElementById('lesson-palette');
if (paletteMount) {
    createApp(LessonPalette).mount(paletteMount);
}
