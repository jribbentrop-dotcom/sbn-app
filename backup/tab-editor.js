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
 * NOTE: #sbn-tab-editor lives inside <template x-if="parsed">, so it may not
 * exist in the DOM when this module first executes (Alpine hasn't set `parsed`
 * yet). We retry until the element appears or give up after 15s.
 */

import { createApp } from 'vue';
import TabEditor     from './tab-editor/TabEditor.vue';
import TabSidebarApp from './tab-editor/TabSidebarApp.vue';

// ── Mount 1: notation area (may be delayed by x-if="parsed") ──

function mountEditor() {
    const el = document.getElementById('sbn-tab-editor');
    if (el) {
        createApp(TabEditor).mount(el);
        return true;
    }
    return false;
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
