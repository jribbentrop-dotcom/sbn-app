<template>
    <TabSidebar
        :cursor="{
            measureIndex: store.measureIndex,
            eventIndex:   store.eventIndex,
            stringIndex:  store.stringIndex,
            mode:         store.mode,
        }"
        :current-event="store.currentEvent"
        :current-note="store.currentNote"
        :active="store.active"
        :ticks-per-measure="store.ticksPerMeasure"
        :pending-digit="store.pendingDigit"
        :measure-overfill="store.measureOverfill"
        :can-undo="store.canUndo"
        :can-redo="store.canRedo"
        :has-clipboard="store.hasClipboard"
        :clipboard-count="store.clipboardCount"
        :clipboard-mode="store.clipboardMode"
        @set-duration="onSetDuration"
        @toggle-tie="onToggleTie"
        @toggle-dotted="onToggleDotted"
        @undo="onUndo"
        @redo="onRedo"
        @copy="onCopy"
        @cut="onCut"
        @paste="onPaste"
    />
</template>

<script setup>
/**
 * TabSidebarApp — standalone Vue app mounted on #sbn-tab-sidebar.
 *
 * Reads reactive state written by TabEditor (the other Vue app on the page)
 * via the shared sidebarStore module singleton. Because both apps are in the
 * same Vite bundle they share the same module instance, so the reactive()
 * object is truly shared — no CustomEvents, no props needed.
 *
 * Mounted from tab-editor.js alongside the main TabEditor mount:
 *
 *   const sidebarEl = document.getElementById('sbn-tab-sidebar');
 *   if (sidebarEl) createApp(TabSidebarApp).mount(sidebarEl);
 */

import { sidebarStore as store } from './composables/useSidebarStore.js';
import TabSidebar from './components/TabSidebar.vue';

function onSetDuration(durCode) {
    // Phase 7d: dispatch to TabEditor via CustomEvent
    document.dispatchEvent(new CustomEvent('sbn-sidebar-set-duration', { detail: { durCode } }));
}

function onToggleTie() {
    // Phase 7d: dispatch to TabEditor
    document.dispatchEvent(new CustomEvent('sbn-sidebar-toggle-tie'));
}

function onToggleDotted() {
    // Phase 7d: dispatch to TabEditor
    document.dispatchEvent(new CustomEvent('sbn-sidebar-toggle-dotted'));
}

function onUndo() {
    document.dispatchEvent(new CustomEvent('sbn-sidebar-undo'));
}

function onRedo() {
    document.dispatchEvent(new CustomEvent('sbn-sidebar-redo'));
}

// Phase 7g: copy/paste/cut via sidebar buttons
function onCopy() {
    document.dispatchEvent(new CustomEvent('sbn-sidebar-copy'));
}
function onCut() {
    document.dispatchEvent(new CustomEvent('sbn-sidebar-cut'));
}
function onPaste() {
    document.dispatchEvent(new CustomEvent('sbn-sidebar-paste'));
}

</script>
