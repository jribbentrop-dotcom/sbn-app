<script setup lang="ts">
import { ref, watch, onMounted, onBeforeUnmount, nextTick } from 'vue';

type NodeType = 'chord' | 'rhythm' | 'progression' | 'song';

interface PaletteItem { slug: string; label: string; meta?: string }

const TABS: { type: NodeType; label: string }[] = [
    { type: 'chord',       label: 'Chord' },
    { type: 'rhythm',      label: 'Rhythm' },
    { type: 'progression', label: 'Progression' },
    { type: 'song',        label: 'Song' },
];

const API: Record<NodeType, string> = {
    chord:       '/api/sbn/chords',
    rhythm:      '/api/sbn/rhythms',
    progression: '/api/sbn/progressions',
    song:        '/api/sbn/songs',
};

// ── State ─────────────────────────────────────────────────────────────────────

const activeTab  = ref<NodeType>('chord');
const query      = ref('');
const results    = ref<PaletteItem[]>([]);
const loading    = ref(false);
const errorMsg   = ref('');

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
        const url = `${API[activeTab.value]}?q=${encodeURIComponent(query.value)}`;
        const res = await fetch(url, { headers: { Accept: 'application/json' } });
        if (!res.ok) throw new Error(`${res.status}`);
        const data = await res.json();

        // Chord search returns { results: [{slug, name, root_note, quality_label}] }
        // Others return { results: [{slug, label, meta}] }
        if (activeTab.value === 'chord') {
            results.value = (data.results ?? []).map((r: any) => ({
                slug:  r.slug,
                label: r.name ?? r.slug,
                meta:  [r.root_note, r.quality_label].filter(Boolean).join(' '),
            }));
        } else {
            results.value = data.results ?? [];
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

watch(query, scheduleSearch);
watch(activeTab, () => { query.value = ''; results.value = []; doSearch(); });

// ── Insert ────────────────────────────────────────────────────────────────────

function insert(item: PaletteItem) {
    // Bridge: LessonEditor.vue exposes window.__sbnInsert
    const fn = (window as any).__sbnInsert;
    if (typeof fn === 'function') {
        fn(activeTab.value, item.slug);
    } else {
        console.warn('[LessonPalette] window.__sbnInsert not ready');
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
    <div class="sbn-palette-search">
      <input
        ref="searchInputRef"
        v-model="query"
        type="text"
        class="sbn-search-input"
        :placeholder="`Search ${activeTab}s…`"
        style="padding-left:10px; font-size:12px; height:30px;"
      />
    </div>

    <!-- Results list -->
    <div class="sbn-palette-results">
      <div v-if="loading" class="sbn-palette-empty">Loading…</div>
      <div v-else-if="errorMsg" class="sbn-palette-empty sbn-palette-error">{{ errorMsg }}</div>
      <div v-else-if="!results.length" class="sbn-palette-empty">No results</div>
      <button
        v-for="item in results"
        :key="item.slug"
        type="button"
        class="sbn-palette-item"
        :class="`sbn-palette-item--${activeTab}`"
        @click="insert(item)"
      >
        <span class="sbn-palette-item-label">{{ item.label }}</span>
        <span v-if="item.meta" class="sbn-palette-item-meta">{{ item.meta }}</span>
      </button>
    </div>

  </div>
</template>
