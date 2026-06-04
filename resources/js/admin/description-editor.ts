import { createApp, type App } from 'vue';
import DescriptionEditorModal from './DescriptionEditorModal.vue';

let activeApp: App | null = null;

interface OpenOptions {
    initial: string;
    placeholder?: string;
    eventName?: string;
    entityType?: 'rhythm' | 'progression' | 'chord' | 'leadsheet' | 'course';
    entityMeta?: Record<string, any>;
}

function open(options: OpenOptions) {
    if (activeApp) {
        activeApp.unmount();
        activeApp = null;
    }

    const mount = document.getElementById('desc-editor-root');
    if (!mount) return;

    const app = createApp(DescriptionEditorModal, {
        ...options,
        onClose() {
            app.unmount();
            activeApp = null;
        },
    });

    app.mount(mount);
    activeApp = app;
}

(window as any).__descEditor = { open };
