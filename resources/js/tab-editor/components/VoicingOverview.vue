<template>
  <div class="sbn-vp-overview" v-if="hasModel">
    <div class="sbn-vp-overview-header">
      <div class="sbn-vp-subtitle">Song Voicings</div>
      <div style="display:flex;align-items:center;gap:8px">
        <button class="sbn-btn sbn-btn-xs" @click.stop="cleanUnused">Clean unused</button>
        <button class="sbn-btn sbn-btn-xs sbn-btn-danger" @click.stop="clearAll" :disabled="sortedUniqueChords.length === 0">Clear all</button>
        <button class="sbn-btn sbn-btn-xs sbn-btn-accent" @click.stop="toggleFillPanel" :disabled="filling">
          {{ filling ? 'Filling…' : 'Fill voicings' }}
        </button>
        <span class="sbn-vp-overview-count">{{ sortedUniqueChords.length }} voicings</span>
      </div>
    </div>

    <!-- Fill voicings options panel -->
    <div v-if="fillPanelOpen && !filling" class="sbn-fill-panel">
      <div class="sbn-fill-row">
        <label class="sbn-fill-label">Style</label>
        <select v-model="fillStyle" class="sbn-fill-select">
          <option value="jazz">Jazz</option>
          <option value="latin">Latin</option>
          <option value="pop">Pop</option>
          <option value="blues">Blues</option>
        </select>
      </div>
      <div class="sbn-fill-row">
        <label class="sbn-fill-label">Extensions</label>
        <select v-model="fillExtMode" class="sbn-fill-select">
          <option value="basic">Basic</option>
          <option value="extended">Extended</option>
        </select>
      </div>
      <div class="sbn-fill-row">
        <label class="sbn-fill-label sbn-fill-label-check">
          <input type="checkbox" v-model="fillGapsOnly" />
          Keep existing voicings
        </label>
      </div>
      <div class="sbn-fill-actions">
        <button class="sbn-btn sbn-btn-xs sbn-btn-accent" @click="runFill">Run</button>
        <button class="sbn-btn sbn-btn-xs" @click="fillPanelOpen = false">Cancel</button>
      </div>
      <div v-if="fillMessage" class="sbn-fill-message" :class="fillMessageKind">{{ fillMessage }}</div>
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
import { inject, computed, reactive, ref, onMounted, onUnmounted } from 'vue';
import { formatChordHtml, renderDiagramSVG } from '../utils/chordFormat.js';

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

// ── Fill voicings ─────────────────────────────────────────────────────────────

const fillPanelOpen = ref(false);
const fillStyle     = ref('jazz');
const fillExtMode   = ref('basic');
const fillGapsOnly  = ref(true);
const filling       = ref(false);
const fillMessage   = ref('');
const fillMessageKind = ref('');

function toggleFillPanel() {
    fillPanelOpen.value = !fillPanelOpen.value;
    fillMessage.value = '';
}

async function runFill() {
    const leadsheetId = window._sbnLeadsheetId;
    if (!leadsheetId) {
        fillMessage.value = 'No leadsheet ID found.';
        fillMessageKind.value = 'error';
        return;
    }

    filling.value = true;
    fillMessage.value = '';

    try {
        const csrf = document.querySelector('meta[name=csrf-token]')?.content ?? '';
        const resp = await fetch(`/api/admin/leadsheets/${leadsheetId}/fill-voicings`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf,
                'Accept': 'application/json',
            },
            body: JSON.stringify({
                voicing_style:  fillStyle.value,
                extension_mode: fillExtMode.value,
                fill_gaps_only: fillGapsOnly.value,
            }),
        });

        const data = await resp.json();
        if (!resp.ok || !data.success) {
            fillMessage.value = data.error ?? 'Fill failed.';
            fillMessageKind.value = 'error';
            return;
        }

        // Push new voicings into the bridge ref — useTabModel watches it and
        // patches model.value.chordVoicings reactively (correct data flow).
        document.dispatchEvent(new CustomEvent('sbn-chord-voicings-patch', {
            detail: { voicings: data.voicings },
        }));

        fillMessage.value = `Filled ${data.filled} voicing(s)` + (data.pinned ? `, kept ${data.pinned} existing` : '') + '.';
        fillMessageKind.value = 'ok';
        fillPanelOpen.value = false;

        // Signal Alpine to merge voicings into parsed.chordVoicings and mark dirty.
        document.dispatchEvent(new CustomEvent('sbn-voicings-filled', { detail: data }));

    } catch (err) {
        fillMessage.value = 'Request failed: ' + err.message;
        fillMessageKind.value = 'error';
    } finally {
        filling.value = false;
    }
}

// ── Context menu ──────────────────────────────────────────────────────────────

const contextMenu = reactive({
    show: false,
    x: 0,
    y: 0,
    chordName: null,
});

function showContextMenu(event, name) {
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

function clearAll() {
    if (!sortedUniqueChords.value.length) return;
    if (!confirm(`Remove all ${sortedUniqueChords.value.length} voicing(s)?`)) return;
    picker.clearAllVoicings();
}

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
