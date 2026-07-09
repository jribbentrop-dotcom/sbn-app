import './bootstrap';
import { createApp, h, DefineComponent } from 'vue';
import { createInertiaApp, router, usePage } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import PublicLayout from './Layouts/PublicLayout.vue';
import { getAudioEngine } from './audio/engine/AudioEngine.js';
import { isGatedPath } from './lib/gatedRoutes';
import { useAuthModal } from './composables/useAuthModal';

// Kill audio on every Inertia navigation so sound never bleeds across pages.
router.on('navigate', () => {
    const engine = getAudioEngine();
    if (engine.isInited) engine.stop();
});

// Any link/visit to a gated route (library, theory, account, course player)
// clicked by a guest opens the auth modal in place instead of following the
// server's redirect-to-/register — so the current page never unmounts.
router.on('before', (event) => {
    const url = event.detail.visit.url;
    if (!isGatedPath(url.pathname)) return;

    const page = usePage();
    if (page.props.auth?.user) return;

    event.preventDefault();
    useAuthModal().open('register', { redirectTo: url.pathname + url.search });
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
