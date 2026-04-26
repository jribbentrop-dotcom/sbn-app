<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import ChordCard from '@/Components/Library/ChordCard.vue';
import ChordDiagram from '@/Components/Library/ChordDiagram.vue';
import type { ChordDiagramData } from '@/Components/Library/ChordDiagram.vue';

defineOptions({ layout: PublicLayout });

interface ArchetypeFamily {
    key: string;
    label: string;
    chords: ChordDiagramData[];
}

interface Props {
    archetypeFamilies: ArchetypeFamily[];
    otherChords: ChordDiagramData[];
    voicingCategories: Record<string, string>;
    chordQualities: Record<string, string>;
    totalCount: number;
}

const props = defineProps<Props>();

// ── Filter state ───────────────────────────────────────────
const search   = ref('');
const fQuality = ref('');
const fVoicing = ref('');
const fPop     = ref('');
const fDiff    = ref('');
const fInv     = ref('');
const fExt     = ref('');

// ── Archetype drawer ───────────────────────────────────────
const activeFamily = ref<string | null>(null);

const activeFamilyIndex = computed(() =>
    activeFamily.value
        ? props.archetypeFamilies.findIndex(f => f.key === activeFamily.value)
        : -1
);

function toggleFamily(key: string) {
    activeFamily.value = activeFamily.value === key ? null : key;
}

// ── Sidebar toggle values (derived from data) ──────────────
const allQualities = computed(() => {
    const seen = new Set<string>();
    const out: Array<{ key: string; label: string }> = [];
    for (const c of props.otherChords) {
        if (c.quality && !seen.has(c.quality)) {
            seen.add(c.quality);
            out.push({ key: c.quality, label: props.chordQualities[c.quality] ?? c.quality });
        }
    }
    return out.sort((a, b) => a.key.localeCompare(b.key));
});

const allVoicings = computed(() => {
    const seen = new Set<string>();
    const out: Array<{ key: string; label: string }> = [];
    for (const c of props.otherChords) {
        if (c.voicing_category && !seen.has(c.voicing_category)) {
            seen.add(c.voicing_category);
            out.push({ key: c.voicing_category, label: props.voicingCategories[c.voicing_category] ?? c.voicing_category });
        }
    }
    return out;
});

const allInversions = computed(() => {
    const inv: Record<string, string> = {
        inv1: '1st Inversion',
        inv2: '2nd Inversion',
        inv3: '3rd Inversion',
    };
    const seen = new Set<string>();
    const out: Array<{ key: string; label: string }> = [];
    for (const c of props.otherChords) {
        if (c.inversion && c.inversion !== 'root' && !seen.has(c.inversion)) {
            seen.add(c.inversion);
            out.push({ key: c.inversion, label: inv[c.inversion] ?? c.inversion });
        }
    }
    return out;
});

const allExtensions = computed(() => {
    const seen = new Set<string>();
    const out: string[] = [];
    for (const c of props.otherChords) {
        if (c.extensions && !seen.has(c.extensions)) {
            seen.add(c.extensions);
            out.push(c.extensions);
        }
    }
    return out.sort();
});

const popularityOptions = [
    { key: 'occasional', label: 'Rare',   min: 1,  max: 2  },
    { key: 'common',     label: 'Common', min: 3,  max: 5  },
    { key: 'essential',  label: 'Core',   min: 6,  max: 10 },
    { key: 'iconic',     label: 'Iconic', min: 11, max: 999 },
];

const difficultyOptions = [
    { key: '1', label: '★' },
    { key: '2', label: '★★' },
    { key: '3', label: '★★★' },
    { key: '4', label: '★★★★' },
    { key: '5', label: '★★★★★' },
];

// ── Transposition search (hits /library/chords/search) ────
// Detect when the search looks like a chord name (starts with a valid root
// followed by something). If so, we call the backend which parses + transposes
// shapes. Otherwise we fall back to the client-side substring filter.
const CHORD_NAME_RE = /^[A-Ga-g][#b]?(\S*)?$/;

const searchResults = ref<ChordDiagramData[]>([]);
const searchLoading = ref(false);
const searchError = ref<string | null>(null);
let searchSeq = 0;
let searchTimer: ReturnType<typeof setTimeout> | null = null;

function looksLikeChordName(q: string): boolean {
    const t = q.trim();
    if (t.length < 1) return false;
    return CHORD_NAME_RE.test(t);
}

// Normalize user input so the backend parser sees a capitalized root, e.g.
// "dm7" → "Dm7", "f#maj7" → "F#maj7". Only the first letter is touched; the
// quality part stays case-sensitive since the parser distinguishes m vs M.
function normalizeChordQuery(q: string): string {
    const t = q.trim();
    if (!t) return t;
    return t.charAt(0).toUpperCase() + t.slice(1);
}

async function runTransposeSearch(q: string) {
    const mySeq = ++searchSeq;
    searchLoading.value = true;
    searchError.value = null;
    try {
        const url = `/library/chords/search?q=${encodeURIComponent(q)}`;
        const resp = await fetch(url, { headers: { Accept: 'application/json' } });
        if (!resp.ok) throw new Error(`HTTP ${resp.status}`);
        const data = await resp.json();
        if (mySeq !== searchSeq) return;
        searchResults.value = (data.results ?? []) as ChordDiagramData[];
    } catch (e: any) {
        if (mySeq !== searchSeq) return;
        searchError.value = e?.message ?? 'Search failed';
        searchResults.value = [];
    } finally {
        if (mySeq === searchSeq) searchLoading.value = false;
    }
}

watch(search, (v) => {
    if (searchTimer) { clearTimeout(searchTimer); searchTimer = null; }
    const q = v.trim();
    if (!looksLikeChordName(q)) {
        searchResults.value = [];
        searchLoading.value = false;
        return;
    }
    searchTimer = setTimeout(() => runTransposeSearch(normalizeChordQuery(q)), 200);
});

const usingTransposeSearch = computed(() =>
    looksLikeChordName(search.value.trim()) && (searchLoading.value || searchResults.value.length > 0 || !!searchError.value)
);

// ── Client-side filtering ──────────────────────────────────
function matchesFilters(c: ChordDiagramData): boolean {
    // When we're in transposition mode, don't re-apply the text filter —
    // the backend already matched by parsed root+quality.
    if (!usingTransposeSearch.value) {
        const q = search.value.trim().toLowerCase();
        if (q) {
            const haystack = [c.name, c.quality, c.category_label, c.extensions, c.voicing_category]
                .filter(Boolean).join(' ').toLowerCase();
            if (!haystack.includes(q)) return false;
        }
    }
    if (fQuality.value && c.quality !== fQuality.value) return false;
    if (fVoicing.value && c.voicing_category !== fVoicing.value) return false;
    if (fInv.value && (c.inversion ?? 'root') !== fInv.value) return false;
    if (fExt.value && c.extensions !== fExt.value) return false;
    if (fPop.value) {
        const tier = popularityOptions.find(o => o.key === fPop.value);
        const p = c.popularity ?? 0;
        if (!tier || p < tier.min || p > tier.max) return false;
    }
    if (fDiff.value && String(c.difficulty ?? '') !== fDiff.value) return false;
    return true;
}

const filteredOther = computed(() => {
    const source = usingTransposeSearch.value ? searchResults.value : props.otherChords;
    return source.filter(matchesFilters);
});

const hasFilters = computed(() =>
    !!(search.value || fQuality.value || fVoicing.value || fPop.value || fDiff.value || fInv.value || fExt.value)
);

const visibleCount = computed(() => filteredOther.value.length);

function clearFilters() {
    search.value = '';
    fQuality.value = '';
    fVoicing.value = '';
    fPop.value = '';
    fDiff.value = '';
    fInv.value = '';
    fExt.value = '';
}

// Build the detail page URL, carrying root context when relevant:
// - transposed search results always carry their root
// - rootless voicings default to C
// - everything else (archetypes, other shapes stored at C) needs no param
function chordShowUrl(chord: ChordDiagramData): string {
    const base = `/library/chords/${chord.slug}`;
    const root = chord.root_note ?? '';
    const isRootless = chord.voicing_category === 'rootless';
    const hasRoot = (chord as any).transposed_from != null;
    if (isRootless) return `${base}?root=C`;
    if (hasRoot || (root && root !== 'C')) return `${base}?root=${encodeURIComponent(root)}`;
    return base;
}
</script>

<template>
    <div class="sbn-chord-library-main">

        <!-- ── Header ── -->
        <header class="sbn-library-header">
            <h1 class="sbn-library-title">Chord Dictionary</h1>
            <p class="sbn-library-subtitle">Search by chord name or browse by category</p>

            <div class="sbn-search-container">
                <div class="sbn-search-box">
                    <svg class="sbn-search-icon" width="18" height="18" viewBox="0 0 20 20" fill="none">
                        <circle cx="8" cy="8" r="6" stroke="currentColor" stroke-width="2"/>
                        <path d="M13 13L18 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                    <input
                        v-model="search"
                        type="search"
                        class="sbn-search-input"
                        placeholder="Try: maj7, drop 2, rootless…"
                        autocomplete="off"
                    />
                    <button
                        v-if="search"
                        class="sbn-search-clear"
                        @click="search = ''"
                        aria-label="Clear search"
                    >
                        <svg width="14" height="14" viewBox="0 0 16 16" fill="none">
                            <path d="M4 4L12 12M12 4L4 12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                    </button>
                </div>
            </div>
        </header>

        <!-- ── Count bar ── -->
        <div class="sbn-count-bar">
            <span v-if="searchLoading">Searching…</span>
            <span v-else-if="usingTransposeSearch">
                <strong>{{ visibleCount }}</strong> voicings for <em>{{ search }}</em>
            </span>
            <span v-else-if="hasFilters">
                Showing <strong>{{ visibleCount }}</strong> of {{ totalCount }} voicings
            </span>
            <span v-else>
                <strong>{{ totalCount }}</strong> voicings
            </span>
            <button v-if="hasFilters" class="sbn-count-clear" @click="clearFilters">
                Clear filters
            </button>
        </div>

        <div v-if="searchError" class="sbn-count-bar" style="color: var(--clr-danger, #c00);">
            Search failed: {{ searchError }}
        </div>

        <!-- ── Content wrapper: grid left, sidebar right ── -->
        <div class="sbn-content-wrapper">

            <!-- Results -->
            <div class="sbn-results-container">

                <!-- Archetype panel (hidden during search/filter) -->
                <div v-if="!hasFilters && archetypeFamilies.length" class="sbn-archetype-panel">
                    <p class="sbn-archetype-panel-title">Archetypes</p>
                    <p class="sbn-archetype-panel-subtitle">
                        The fundamental open-position shapes — transposable as barré chords
                    </p>

                    <div class="sbn-archetype-tiles">
                        <button
                            v-for="(family, i) in archetypeFamilies"
                            :key="family.key"
                            class="sbn-archetype-tile"
                            :class="{ active: activeFamily === family.key }"
                            @click="toggleFamily(family.key)"
                        >
                            <span class="sbn-archetype-tile-name">{{ family.label.replace(' Shape', '') }}</span>
                            <div v-if="family.chords[0]" class="sbn-archetype-tile-diagram">
                                <ChordDiagram :chord="family.chords[0]" />
                            </div>
                            <span class="sbn-tile-hint">
                                {{ activeFamily === family.key ? 'collapse' : `${family.chords.length} voicings` }}
                            </span>
                        </button>
                    </div>

                    <!-- Connector: arrow pointing up from drawer to active tile -->
                    <div
                        v-if="activeFamily"
                        class="sbn-drawer-connector"
                        :style="{ '--tile-index': activeFamilyIndex, '--tile-count': archetypeFamilies.length }"
                    />

                    <!-- Drawer -->
                    <div v-if="activeFamily" class="sbn-archetype-drawer">
                        <div class="sbn-drawer-cards">
                            <Link
                                v-for="chord in archetypeFamilies.find(f => f.key === activeFamily)?.chords"
                                :key="chord.id"
                                :href="chordShowUrl(chord)"
                            >
                                <ChordCard :chord="chord" />
                            </Link>
                        </div>
                    </div>
                </div>

                <!-- Main chords grid -->
                <div v-if="filteredOther.length" class="sbn-chords-grid">
                    <Link
                        v-for="chord in filteredOther"
                        :key="chord.id"
                        :href="chordShowUrl(chord)"
                        style="text-decoration: none;"
                    >
                        <ChordCard :chord="chord" />
                    </Link>
                </div>

                <!-- No results -->
                <div v-else-if="hasFilters" class="sbn-no-results">
                    <svg width="48" height="48" viewBox="0 0 48 48" fill="none">
                        <circle cx="20" cy="20" r="12" stroke="currentColor" stroke-width="2"/>
                        <path d="M30 30L42 42" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        <path d="M16 20H24M20 16V24" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                    <h3>No chords match your search</h3>
                    <p>Try a different name or adjust your filters</p>
                </div>
            </div>

            <!-- Filter Sidebar -->
            <aside class="sbn-filter-sidebar">
                <div class="sbn-sidebar-header">
                    <h3>Filters</h3>
                </div>

                <!-- Quality -->
                <div class="sbn-sidebar-section">
                    <span class="sbn-sidebar-label">Chord Quality</span>
                    <div class="sbn-sidebar-options">
                        <button
                            v-for="q in allQualities"
                            :key="q.key"
                            class="sbn-sidebar-option"
                            :class="{ active: fQuality === q.key }"
                            @click="fQuality = fQuality === q.key ? '' : q.key"
                        >{{ q.key }}</button>
                    </div>
                </div>

                <!-- Voicing type -->
                <div class="sbn-sidebar-section">
                    <span class="sbn-sidebar-label">Voicing Type</span>
                    <div class="sbn-sidebar-options">
                        <button
                            v-for="v in allVoicings"
                            :key="v.key"
                            class="sbn-sidebar-option"
                            :class="{ active: fVoicing === v.key }"
                            @click="fVoicing = fVoicing === v.key ? '' : v.key"
                        >{{ v.label }}</button>
                    </div>
                </div>

                <!-- Popularity -->
                <div class="sbn-sidebar-section">
                    <span class="sbn-sidebar-label">Popularity</span>
                    <div class="sbn-sidebar-options">
                        <button
                            v-for="p in popularityOptions"
                            :key="p.key"
                            class="sbn-sidebar-option"
                            :class="{ active: fPop === p.key }"
                            @click="fPop = fPop === p.key ? '' : p.key"
                        >{{ p.label }}</button>
                    </div>
                </div>

                <!-- Difficulty -->
                <div class="sbn-sidebar-section">
                    <span class="sbn-sidebar-label">Difficulty</span>
                    <div class="sbn-sidebar-options">
                        <button
                            v-for="d in difficultyOptions"
                            :key="d.key"
                            class="sbn-sidebar-option"
                            :class="{ active: fDiff === d.key }"
                            @click="fDiff = fDiff === d.key ? '' : d.key"
                        >{{ d.label }}</button>
                    </div>
                </div>

                <!-- Inversions -->
                <div v-if="allInversions.length" class="sbn-sidebar-section">
                    <span class="sbn-sidebar-label">Inversion</span>
                    <div class="sbn-sidebar-options">
                        <button
                            v-for="inv in allInversions"
                            :key="inv.key"
                            class="sbn-sidebar-option"
                            :class="{ active: fInv === inv.key }"
                            @click="fInv = fInv === inv.key ? '' : inv.key"
                        >{{ inv.label }}</button>
                    </div>
                </div>

                <!-- Extensions -->
                <div v-if="allExtensions.length" class="sbn-sidebar-section">
                    <span class="sbn-sidebar-label">Extensions</span>
                    <div class="sbn-sidebar-options">
                        <button
                            v-for="ext in allExtensions"
                            :key="ext"
                            class="sbn-sidebar-option"
                            :class="{ active: fExt === ext }"
                            @click="fExt = fExt === ext ? '' : ext"
                        >{{ ext }}</button>
                    </div>
                </div>

                <button v-if="hasFilters" class="sbn-clear-filters-btn" @click="clearFilters">
                    Clear All Filters
                </button>
            </aside>

        </div>
    </div>
</template>
