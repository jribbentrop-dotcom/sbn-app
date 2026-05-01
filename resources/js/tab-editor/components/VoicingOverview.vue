<template>
  <div class="sbn-vp-overview" v-if="hasModel">
    <div class="sbn-vp-overview-header">
      <div class="sbn-vp-subtitle">Song Voicings</div>
      <div style="display:flex;align-items:center;gap:8px">
        <button class="sbn-btn sbn-btn-xs" @click.stop="cleanUnused">Clean unused</button>
        <span class="sbn-vp-overview-count">{{ sortedUniqueChords.length }} voicings</span>
      </div>
    </div>
    <div class="sbn-vp-overview-grid">
      <div
        v-for="name in sortedUniqueChords"
        :key="name"
        class="sbn-vp-overview-card"
        :class="{ 'has-voicing': !!getVoicing(name) }"
        @click="picker.openForChord(name, null, null)"
        @contextmenu.prevent="showContextMenu($event, name)"
      >
        <div class="sbn-vp-card-name" v-html="formatChordHtml(name)"></div>
        <div v-if="getVoicing(name)">
          <span v-html="renderDiagramSVG(getVoicing(name))"></span>
        </div>
        <div v-else class="sbn-vp-overview-empty"><span>+</span></div>
      </div>
    </div>

    <!-- Context Menu -->
    <div
      v-if="contextMenu.show"
      class="sbn-context-menu"
      :style="{ left: contextMenu.x + 'px', top: contextMenu.y + 'px' }"
    >
      <div class="sbn-context-menu-item" @click="removeVoicing()">
        <span class="sbn-context-menu-icon">🗑️</span>
        Remove voicing
      </div>
    </div>
  </div>
</template>

<script setup>
import { inject, computed, reactive, onMounted, onUnmounted } from 'vue';
import { formatChordHtml, renderDiagramSVG } from '../utils/chordFormat.js';

// model is a Ref provided by TabEditor.vue via provide('model', model)
const model  = inject('model');
const picker = inject('voicingPicker');

const hasModel = computed(() => !!model?.value);

const overviewVoicings = computed(() => {
    const m = model?.value;
    if (!m?.chordVoicings) return {};

    const out = {};
    for (const [key, voicing] of Object.entries(m.chordVoicings)) {
        const baseName = key.includes('@') ? key.split('@')[0] : key;
        if (!baseName || !voicing) continue;

        if (!out[baseName] || !key.includes('@')) {
            out[baseName] = voicing;
        }
    }

    return out;
});

const sortedUniqueChords = computed(() => {
    return Object.keys(overviewVoicings.value).sort((a, b) => a.localeCompare(b));
});

function getVoicing(name) {
    return overviewVoicings.value?.[name] ?? null;
}

// Context menu state
const contextMenu = reactive({
    show: false,
    x: 0,
    y: 0,
    chordName: null,
});

function showContextMenu(event, name) {
    // Only show context menu if there's a voicing to remove
    if (!getVoicing(name)) return;

    contextMenu.x = event.clientX;
    contextMenu.y = event.clientY;
    contextMenu.chordName = name;
    contextMenu.show = true;
}

function hideContextMenu() {
    contextMenu.show = false;
    contextMenu.chordName = null;
}

function removeVoicing() {
    if (contextMenu.chordName) {
        picker.removeVoicingByName(contextMenu.chordName);
    }
    hideContextMenu();
}

function cleanUnused() {
    picker.cleanUnusedVoicings();
}

// Hide context menu on click outside
function onDocumentClick(event) {
    if (!event.target.closest('.sbn-context-menu')) {
        hideContextMenu();
    }
}

onMounted(() => {
    document.addEventListener('click', onDocumentClick);
});

onUnmounted(() => {
    document.removeEventListener('click', onDocumentClick);
});
</script>
