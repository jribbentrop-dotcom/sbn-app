/**
 * SBN Tab Editor — Vite entry point
 *
 * Mounts two Vue apps on the leadsheet edit page:
 *
 *   #sbn-tab-editor  — notation area (TabEditor)
 *   #sbn-tab-sidebar — Blade right panel (TabSidebarApp)
 *
 * Both apps share the sidebarStore module singleton, so TabEditor can write
 * cursor/selection state and TabSidebarApp can read it reactively with no
 * CustomEvents or cross-app prop passing needed.
 *
 * NOTE: #sbn-tab-editor lives inside x-show="viewMode === 'tab'", so it is
 * always present in the DOM but may not be visible yet when this module first
 * executes. We retry until the element appears or give up after 15s.
 * The Vue app is created ONCE here; only .mount() is deferred until the
 * element exists, preventing multiple app instances from being created if
 * the interval fires before clearInterval() runs on the next tick.
 */

import { createApp } from 'vue';
import TabEditor     from './TabEditor.vue';
import TabSidebarApp from './TabSidebarApp.vue';

// ── Mount 1: notation area ────────────────────────────────

// Create the app exactly once — outside the retry loop.
const editorApp = createApp(TabEditor, { initialView: 'chords' });
let editorMounted = false;

function mountEditor() {
    if (editorMounted) return true; // already mounted — ignore spurious retries
    const el = document.getElementById('sbn-editor-content');
    if (!el) return false;
    editorMounted = true;
    editorApp.mount(el);
    return true;
}

if (!mountEditor()) {
    let attempts = 0;
    const maxAttempts = 75; // 75 × 200ms = 15s
    const timer = setInterval(() => {
        attempts++;
        if (mountEditor()) {
            clearInterval(timer);
            console.log('[SBN] Tab editor mounted after ' + attempts + ' retries');
        } else if (attempts >= maxAttempts) {
            clearInterval(timer);
            console.warn('[SBN] Tab editor mount element never appeared');
        }
    }, 200);
}

// ── Mount 2: right panel sidebar ───────────────────────────

const sidebarEl = document.getElementById('sbn-tab-sidebar');
if (sidebarEl) {
    createApp(TabSidebarApp).mount(sidebarEl);
}
