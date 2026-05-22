<script setup lang="ts">
import { ref, watch, onMounted, onBeforeUnmount, nextTick } from 'vue';

type NodeType = 'chord' | 'rhythm' | 'progression' | 'sheet' | 'song' | 'media';

interface SnippetRef { id: string; label: string; key?: string | null }
interface PaletteItem {
    slug: string;
    label: string;
    meta?: string;
    metaMono?: boolean;
    /** Chord root, used to seed the inserted tag (kept separate from `meta`). */
    root?: string;
    url?: string;
    snippets?: SnippetRef[];
}

const TABS: { type: NodeType; label: string }[] = [
    { type: 'chord',       label: 'Chord' },
    { type: 'rhythm',      label: 'Rhythm' },
    { type: 'progression', label: 'Progression' },
    { type: 'sheet',       label: 'Exercise' },
    { type: 'song',        label: 'Song' },
    { type: 'media',       label: 'Media' },
];

const API: Record<NodeType, string> = {
    chord:       '/api/sbn/chords',
    rhythm:      '/api/sbn/rhythms',
    progression: '/api/sbn/progressions',
    sheet:       '/api/sbn/exercises',
    song:        '/api/sbn/songs',
    media:       '',
};

// ── State ─────────────────────────────────────────────────────────────────────

const activeTab  = ref<NodeType>('chord');
const query      = ref('');
const results    = ref<PaletteItem[]>([]);
const loading    = ref(false);
const errorMsg   = ref('');

// ── Inline Config ────────────────────────────────────────────────────────────

const selectedSlug = ref<string | null>(null);
const configRoot   = ref('C');
const configLabel  = ref('');
// Video-example picker — holds the chosen snippet id ('' = none).
const configSnippet      = ref('');
const configSnippetList  = ref<SnippetRef[]>([]);

const ROOTS = ['C', 'C#', 'D', 'D#', 'E', 'F', 'F#', 'G', 'G#', 'A', 'A#', 'B'];

// ── Bridge ────────────────────────────────────────────────────────────────────

const searchInputRef = ref<HTMLInputElement | null>(null);

onMounted(() => {
    (window as any).__sbnPalette = async (type: NodeType) => {
        activeTab.value = type;
        await nextTick();
        searchInputRef.value?.focus();
        searchInputRef.value?.select();
    };
    doSearch();
});

onBeforeUnmount(() => {
    delete (window as any).__sbnPalette;
});

// ── Search ────────────────────────────────────────────────────────────────────

let debounceTimer: ReturnType<typeof setTimeout> | null = null;

async function doSearch() {
    loading.value  = true;
    errorMsg.value = '';
    try {
        if (activeTab.value === 'media') {
            const lessonId = document.getElementById('lesson-palette')?.dataset.lessonId;
            if (!lessonId) {
                results.value = [];
                errorMsg.value = 'Save lesson to upload images';
                loading.value = false;
                return;
            }
            const res = await fetch(`/admin/lessons/${lessonId}/images`);
            if (!res.ok) throw new Error(`${res.status}`);
            const data = await res.json();
            const q = query.value.toLowerCase();
            const allImages = (data.images || []).map((img: any) => ({
                slug: img.url,
                label: img.name,
                url: img.url,
            }));
            results.value = q ? allImages.filter((i: any) => i.label.toLowerCase().includes(q)) : allImages;
        } else {
            const url = `${API[activeTab.value]}?q=${encodeURIComponent(query.value)}`;
            const res = await fetch(url, { headers: { Accept: 'application/json' } });
            if (!res.ok) throw new Error(`${res.status}`);
            const data = await res.json();

            if (activeTab.value === 'chord') {
                results.value = (data.results ?? []).map((r: any) => ({
                    slug:     r.slug,
                    label:    r.name ?? r.slug,
                    // Show the exact slug under the name so admins can copy it.
                    meta:     r.slug,
                    metaMono: true,
                    root:     r.root_note,
                }));
            } else {
                results.value = data.results ?? [];
            }
        }
    } catch (e: any) {
        errorMsg.value = 'Search failed';
        results.value  = [];
    } finally {
        loading.value = false;
    }
}

function scheduleSearch() {
    if (debounceTimer) clearTimeout(debounceTimer);
    debounceTimer = setTimeout(doSearch, 250);
}

watch(query, () => { selectedSlug.value = null; scheduleSearch(); });
watch(activeTab, () => { query.value = ''; results.value = []; selectedSlug.value = null; doSearch(); });

// ── Insert ────────────────────────────────────────────────────────────────────

function insert(item: PaletteItem) {
    // Media and Exercise have no config — insert straight away.
    if (activeTab.value === 'media' || activeTab.value === 'sheet') {
        doInsert(item.slug);
        return;
    }

    if (activeTab.value === 'chord') {
        // Detect root from query (e.g. "F#7" -> "F#")
        const q = query.value.trim().toUpperCase();
        const rootMatch = q.match(/^([A-G][#B]?)/);
        const root = (rootMatch && ROOTS.includes(rootMatch[1])) ? rootMatch[1] : (item.root || 'C');
        doInsert(item.slug, { root: ROOTS.includes(root) ? root : 'C' });
        return;
    }

    // Rhythm/progression only need the config panel to pick a video example.
    // With no snippets there's nothing to configure — insert straight away.
    if ((activeTab.value === 'rhythm' || activeTab.value === 'progression')
        && !(item.snippets?.length)) {
        doInsert(item.slug);
        return;
    }

    // Toggle or select new row for others (rhythm now uses the config path
    // too, so it can host the video-example picker — plan §0.5 step 5).
    if (selectedSlug.value === item.slug) {
        selectedSlug.value = null;
    } else {
        selectedSlug.value = item.slug;
        // Defaults
        if (activeTab.value === 'song') configLabel.value = item.label;
        // Rhythm and progression both host the video-example picker.
        if (activeTab.value === 'rhythm' || activeTab.value === 'progression') {
            configSnippet.value     = '';
            configSnippetList.value = item.snippets ?? [];
        }
    }
}

function doConfirmInsert() {
    if (!selectedSlug.value) return;
    const extras: Record<string, string> = {};
    if (activeTab.value === 'chord')       extras.root = configRoot.value;
    if (activeTab.value === 'song')        extras.label = configLabel.value;
    // Emitted as the `video-snippet` tag attribute; '' = no example.
    if ((activeTab.value === 'rhythm' || activeTab.value === 'progression') && configSnippet.value) {
        extras.videoSnippet = configSnippet.value;
        // A progression snippet may pin the key the recording is played in.
        // Stamp it onto the tag so the inserted <sbn-progression key="…">
        // matches the snippet — otherwise the node defaults to key="C" and
        // the course player builds/displays the progression in C.
        if (activeTab.value === 'progression') {
            const snip = configSnippetList.value.find(s => s.id === configSnippet.value);
            if (snip?.key) extras.key = snip.key;
        }
    }

    doInsert(selectedSlug.value, extras);
    selectedSlug.value = null;
}

function doInsert(slug: string, extras: Record<string, string> = {}) {
    // Bridge: LessonEditor.vue exposes window.__sbnInsert
    const fn = (window as any).__sbnInsert;
    if (typeof fn === 'function') {
        fn(activeTab.value, slug, extras);
    } else {
        console.warn('[LessonPalette] window.__sbnInsert not ready');
    }
}

async function uploadImage(e: Event) {
    const input = e.target as HTMLInputElement;
    if (!input.files?.length) return;
    const file = input.files[0];
    const lessonId = document.getElementById('lesson-palette')?.dataset.lessonId;
    if (!lessonId) {
        alert('Please save the lesson first before uploading images.');
        input.value = '';
        return;
    }

    const formData = new FormData();
    formData.append('image', file);
    try {
        const res = await fetch(`/admin/lessons/${lessonId}/upload-image`, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '' },
            body: formData
        });
        if (!res.ok) throw new Error();
        await doSearch();
    } catch {
        alert('Image upload failed.');
    } finally {
        input.value = '';
    }
}

function onDragStart(e: DragEvent, item: PaletteItem) {
    if (e.dataTransfer) {
        e.dataTransfer.setData('application/json', JSON.stringify({ type: activeTab.value, slug: item.slug }));
        e.dataTransfer.effectAllowed = 'copy';
    }
}
</script>

<template>
  <div class="sbn-palette">

    <!-- Tab strip -->
    <div class="sbn-palette-tabs">
      <button
        v-for="tab in TABS"
        :key="tab.type"
        type="button"
        class="sbn-palette-tab"
        :class="{ 'is-active': activeTab === tab.type, [`sbn-palette-tab--${tab.type}`]: true }"
        @click="activeTab = tab.type"
      >{{ tab.label }}</button>
    </div>

    <!-- Search input -->
    <div class="sbn-palette-search" style="display:flex; gap:8px;">
      <input
        ref="searchInputRef"
        v-model="query"
        type="text"
        class="sbn-search-input"
        :placeholder="`Search ${activeTab}s…`"
        style="font-size:12px; height:30px; flex:1;"
      />
      <label v-if="activeTab === 'media'" class="sbn-btn sbn-btn-primary" style="height:30px; line-height:30px; padding:0 12px; font-size:12px; cursor:pointer;">
        Upload
        <input type="file" accept="image/*" style="display:none;" @change="uploadImage" />
      </label>
    </div>

    <!-- Results list -->
    <div class="sbn-palette-results">
      <div v-if="loading" class="sbn-palette-empty">Loading…</div>
      <div v-else-if="errorMsg" class="sbn-palette-empty sbn-palette-error">{{ errorMsg }}</div>
      <div v-else-if="!results.length" class="sbn-palette-empty">No results</div>
      
      <div v-for="item in results" :key="item.slug">
        <button
          type="button"
          class="sbn-palette-item"
          :class="{ [`sbn-palette-item--${activeTab}`]: true, 'is-selected': selectedSlug === item.slug }"
          @click="insert(item)"
          draggable="true"
          @dragstart="(e) => onDragStart(e, item)"
        >
          <span class="sbn-palette-item-label">{{ item.label }}</span>
          <span
            v-if="item.meta"
            class="sbn-palette-item-meta"
            :class="{ 'sbn-palette-item-meta--mono': item.metaMono }"
          >{{ item.meta }}</span>
        </button>

        <!-- Inline Config Panel -->
        <div v-if="selectedSlug === item.slug" class="sbn-palette-config" @keydown.enter="doConfirmInsert">
          <div v-if="activeTab === 'song'" class="sbn-palette-config-row">
            <label>Label:</label>
            <input v-model="configLabel" type="text" class="sbn-search-input" style="height:28px; flex:1; font-size:12px;" />
          </div>
          <div v-if="activeTab === 'rhythm' || activeTab === 'progression'" class="sbn-palette-config-row">
            <label>Video example:</label>
            <select v-model="configSnippet" class="sbn-search-input" style="height:28px; flex:1; font-size:12px;">
              <option value="">None</option>
              <option v-for="s in configSnippetList" :key="s.id" :value="s.id">{{ s.label }}</option>
            </select>
          </div>
          <button type="button" class="sbn-btn sbn-btn-primary sbn-btn-sm" style="height:28px;" @click="doConfirmInsert">Insert</button>
        </div>
      </div>
    </div>

  </div>
</template>

<style scoped>
.sbn-palette-config {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 8px 12px;
    background: var(--clr-bg-muted);
    border-bottom: 1px solid var(--clr-border);
    font-size: 13px;
    box-sizing: border-box;
    width: 100%;
}
.sbn-palette-config-row {
    display: flex;
    align-items: center;
    gap: 8px;
    flex: 1;
}
.sbn-palette-config label {
    font-weight: 600;
    color: var(--clr-text-muted);
    white-space: nowrap;
}
.sbn-palette-item {
    width: 100%;
    text-align: left;
    box-sizing: border-box;
}
.sbn-palette-item.is-selected {
    background: var(--clr-bg-hover);
    border-left: 3px solid var(--clr-accent);
}
.sbn-palette-item-meta--mono {
    font-family: var(--font-mono);
    font-size: 10.5px;
    color: var(--clr-text-dim);
    letter-spacing: -0.01em;
}
</style>
