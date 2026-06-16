import './bootstrap';
import { createApp, h, DefineComponent } from 'vue';
import { createInertiaApp, router } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import PublicLayout from './Layouts/PublicLayout.vue';
import { getAudioEngine } from './audio/engine/AudioEngine.js';

// Kill audio on every Inertia navigation so sound never bleeds across pages.
router.on('navigate', () => {
    const engine = getAudioEngine();
    if (engine.isInited) engine.stop();
});

createInertiaApp({
    resolve: async (name) => {
        const page = await resolvePageComponent(`./Pages/${name}.vue`, import.meta.glob<DefineComponent>('./Pages/**/*.vue'));
        if (page.default.layout === undefined) {
            page.default.layout = PublicLayout;
        }
        return page;
    },
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .mount(el)
    },
});
