<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import ChordCard from '@/Components/Library/ChordCard.vue';
import ChordDiagram from '@/Components/Library/ChordDiagram.vue';
import AnimatedChordDiagram from '@/Components/Library/AnimatedChordDiagram.vue';
import type { ChordDiagramData } from '@/Components/Library/ChordDiagram.vue';

defineOptions({ layout: PublicLayout });

interface ArchetypeFamily {
    key: string;
    label: string;
    chords: ChordDiagramData[];
}

interface BarreFamily {
    key: string;
    label: string;
    root: string;
    chords: ChordDiagramData[];
}

interface DropTarget {
    label: string;
    chord: ChordDiagramData;
}

interface Props {
    archetypeFamilies: ArchetypeFamily[];
    barreFamilies: BarreFamily[];
    dropFamilies: Record<string, DropTarget>;
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

// ── Archetype / barré panel mode ──────────────────────────
// Forward animation (archetype → barré):
//   idle      → normal
//   exiting   → D/Dm/C/G fade+shrink away, E/Em/A/Am untouched
//   gathering → 4 keeper tiles slide to center cluster via inline translate
//   morphing  → content (diagram+label) switches to barré while gathered; shimmer plays
//               → animation ends here; tiles stay centered, barreMode commits
//
// Reverse (barré → archetype): simple exit-all → enter-all.
const barreMode  = ref(false);
const panelPhase = ref<'idle' | 'exiting' | 'gathering' | 'morphing' | 'entering'>('idle');

// showBarreContent: true while the tiles should display barré diagrams/labels,
// even before barreMode flips (so the spread plays with barré content).
const showBarreContent = ref(false);

// Tile row ref for FLIP measurements
const tilesRowRef = ref<HTMLElement | null>(null);
// Per-tile inline translateX, keyed by morph-tile index (0-3)
const tileTranslates = ref<Record<number, string>>({});

// Indices that exit (D/Dm/C/G = 4,5,6,7) vs morph/keep (E/Em/A/Am = 0,1,2,3)
const EXIT_INDICES  = new Set([4, 5, 6, 7]);
const MORPH_INDICES = new Set([0, 1, 2, 3]);

function tileClass(idx: number): Record<string, boolean> {
    const phase = panelPhase.value;
    if (phase === 'idle') return {};
    if (phase === 'exiting') {
        if (barreMode.value) return { 'sbn-tile--exit': true };
        return { 'sbn-tile--exit': EXIT_INDICES.has(idx) };
    }
    // After exit phase the 4 gone tiles stay invisible through gather/morph/spread
    const isGone = EXIT_INDICES.has(idx);
    if (phase === 'gathering') return isGone ? { 'sbn-tile--gone': true } : { 'sbn-tile--gather': true };
    if (phase === 'morphing')  return isGone ? { 'sbn-tile--gone': true } : { 'sbn-tile--morphing': true };
    if (phase === 'entering')  return { 'sbn-tile--enter': true };
    return {};
}

// Template reads this to decide which diagram/label to show for tiles 0-3
const isMorphActive = computed(() => showBarreContent.value && !barreMode.value);

// Compute translateX values to cluster morph tiles at row center
function computeGatherTranslates(): Record<number, string> {
    if (!tilesRowRef.value) return {};
    const rowRect    = tilesRowRef.value.getBoundingClientRect();
    const rowCenterX = rowRect.left + rowRect.width / 2;
    const allTiles   = Array.from(tilesRowRef.value.querySelectorAll<HTMLElement>('.sbn-archetype-tile'));
    const morphTiles = allTiles.filter((_, i) => MORPH_INDICES.has(i));
    if (!morphTiles.length) return {};

    const tileW  = morphTiles[0].getBoundingClientRect().width;
    const gap    = 10;
    const total  = morphTiles.length * tileW + (morphTiles.length - 1) * gap;
    const startX = rowCenterX - total / 2;

    const result: Record<number, string> = {};
    morphTiles.forEach((el, ci) => {
        const rect      = el.getBoundingClientRect();
        const currCx    = rect.left + rect.width / 2;
        const destCx    = startX + ci * (tileW + gap) + tileW / 2;
        result[ci] = `translateX(${(destCx - currCx).toFixed(1)}px)`;
    });
    return result;
}

async function enterBarreMode() {
    if (panelPhase.value !== 'idle') return;
    activeFamily.value = null;

    // 1 — exit the 4 non-transferable tiles
    panelPhase.value = 'exiting';
    await delay(480);

    // 2 — slide keeper tiles to center
    tileTranslates.value = computeGatherTranslates();
    panelPhase.value = 'gathering';
    await delay(540);

    // 3 — switch content to barré while gathered; shimmer plays
    showBarreContent.value = true;
    panelPhase.value = 'morphing';
    await delay(700);

    // animation done — tiles stay centered; commit barré state
    barreMode.value = true;
    tileTranslates.value = {};
    panelPhase.value = 'idle';
}

async function exitBarreMode() {
    if (panelPhase.value !== 'idle') return;
    activeFamily.value = null;
    dropMode.value = false;
    dropAnimating.value = false;
    panelPhase.value = 'exiting';
    await delay(400);
    showBarreContent.value = false;
    barreMode.value = false;
    panelPhase.value = 'entering';
    await delay(520);
    panelPhase.value = 'idle';
}

function delay(ms: number): Promise<void> {
    return new Promise(r => setTimeout(r, ms));
}

// ── Archetype drawer ───────────────────────────────────────
const activeFamily = ref<string | null>(null);

const activeFamilyIndex = computed(() => {
    if (barreMode.value) {
        return activeFamily.value
            ? props.barreFamilies.findIndex(f => f.key === activeFamily.value)
            : -1;
    }
    return activeFamily.value
        ? props.archetypeFamilies.findIndex(f => f.key === activeFamily.value)
        : -1;
});

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

// ── Voicing groups (unfiltered browse mode only) ───────────────
// Category order mirrors VOICING_CATEGORIES constant on the model.
const CATEGORY_ORDER = [
    'drop2', 'drop3', 'shell', 'rootless', 'closed', 'closed_triads', 'spread_triads', 'slash', 'custom',
];

interface VoicingGroup {
    key: string;
    label: string;
    chords: ChordDiagramData[];
}

const voicingGroups = computed((): VoicingGroup[] => {
    const map = new Map<string, ChordDiagramData[]>();
    for (const c of props.otherChords) {
        const cat = c.voicing_category || 'custom';
        if (!map.has(cat)) map.set(cat, []);
        map.get(cat)!.push(c);
    }
    const result: VoicingGroup[] = [];
    for (const key of CATEGORY_ORDER) {
        const chords = map.get(key);
        if (chords?.length) {
            result.push({ key, label: props.voicingCategories[key] ?? key, chords });
        }
    }
    // Any categories not in CATEGORY_ORDER go last
    for (const [key, chords] of map) {
        if (!CATEGORY_ORDER.includes(key)) {
            result.push({ key, label: props.voicingCategories[key] ?? key, chords });
        }
    }
    return result;
});

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

// Active tile count depends on which panel is showing (for drawer connector calc)
const activeTileCount = computed(() =>
    barreMode.value ? props.barreFamilies.length : props.archetypeFamilies.length
);

// ── Drop2/Drop3 level ─────────────────────────────────────────────────────
// dropMode: dots inside barre tiles have animated to their drop voicing positions.
// dropAnimating: the animation is currently running (prevents re-trigger).
const dropMode      = ref(false);
const dropAnimating = ref(false);

async function enterDropMode() {
    if (!barreMode.value || dropMode.value || dropAnimating.value) return;
    activeFamily.value = null;
    dropAnimating.value = true;
    // Animation runs inside AnimatedChordDiagram; we just wait for it to finish
    await delay(800);
    dropAnimating.value = false;
    dropMode.value = true;
}

// Key lookup: barreFamilies[idx].key → dropFamilies entry
function dropTargetFor(barreFamily: BarreFamily): ChordDiagramData | null {
    return props.dropFamilies[barreFamily.key]?.chord ?? null;
}

function dropLabelFor(barreFamily: BarreFamily): string {
    return props.dropFamilies[barreFamily.key]?.label ?? barreFamily.label;
}
</script>

<template>
    <div class="sbn-page sbn-chord-library-main">

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

                <!-- Archetype / Barré panel (hidden during search/filter) -->
                <div v-if="!hasFilters && archetypeFamilies.length" class="sbn-archetype-panel">

                    <!-- ── Panel header (switches title/subtitle/back btn) ── -->
                    <div class="sbn-archetype-panel-header">
                        <div>
                            <p class="sbn-archetype-panel-title">
                                {{ dropMode ? '4-Part 7th Chords' : barreMode ? 'Common Barré Shapes' : 'Archetypes' }}
                            </p>
                            <p class="sbn-archetype-panel-subtitle">
                                {{ dropMode
                                    ? 'Drop voicings spread the 7th chord across the neck — the gateway to jazz harmony'
                                    : barreMode
                                        ? 'E and A shapes moved up the neck — the same fingering, any root'
                                        : 'The 8 fundamental open-position shapes' }}
                            </p>
                        </div>
                        <button v-if="barreMode" class="sbn-barre-back-btn" @click="exitBarreMode">
                            ← Archetypes
                        </button>
                    </div>

                    <!-- ── Unified tile row ── -->
                    <!-- Source: always archetypeFamilies during forward animation so the
                         same 4 DOM nodes persist through exit→gather→morph→spread.
                         Only after barreMode commits do we switch to barreFamilies. -->
                    <div ref="tilesRowRef" class="sbn-archetype-tiles" :class="{ 'sbn-tiles--animating': panelPhase !== 'idle' }">
                        <button
                            v-for="(family, idx) in (barreMode ? barreFamilies : archetypeFamilies)"
                            :key="family.key"
                            class="sbn-archetype-tile"
                            :class="[{ active: activeFamily === family.key }, tileClass(idx)]"
                            :style="{
                                '--tile-i': idx,
                                transition: panelPhase === 'gathering' ? 'transform 0.5s cubic-bezier(0.4,0,0.2,1)' : undefined,
                                transform: (MORPH_INDICES.has(idx) && tileTranslates[idx]) ? tileTranslates[idx] : undefined,
                            }"
                            :disabled="panelPhase !== 'idle'"
                            @click="toggleFamily(family.key)"
                        >
                            <!-- Label -->
                            <span class="sbn-archetype-tile-name">
                                <!-- Drop mode: crossfade barré name → drop name -->
                                <template v-if="barreMode">
                                    <span
                                        v-if="dropAnimating"
                                        class="sbn-tile-label-out"
                                    >{{ family.label }}</span>
                                    <span
                                        v-if="dropAnimating"
                                        class="sbn-tile-label-in"
                                    >{{ dropLabelFor(family as BarreFamily) }}</span>
                                    <span v-else>{{ dropMode ? dropLabelFor(family as BarreFamily) : family.label }}</span>
                                </template>
                                <!-- Archetype mode: crossfade during forward morph -->
                                <template v-else>
                                    <span
                                        v-if="isMorphActive && MORPH_INDICES.has(idx)"
                                        class="sbn-tile-label-out"
                                    >{{ family.label.replace(' Shape', '') }}</span>
                                    <span
                                        v-if="isMorphActive && MORPH_INDICES.has(idx)"
                                        class="sbn-tile-label-in"
                                    >{{ barreFamilies[idx]?.label ?? '' }}</span>
                                    <span v-else>{{ family.label.replace(' Shape', '') }}</span>
                                </template>
                            </span>

                            <!-- Diagram -->
                            <div class="sbn-archetype-tile-diagram sbn-tile-diagram-wrap">
                                <!-- Barré mode: AnimatedChordDiagram so dots can morph to drop voicings -->
                                <AnimatedChordDiagram
                                    v-if="barreMode && family.chords[0]"
                                    :chord="family.chords[0]"
                                    :target-chord="dropTargetFor(family as BarreFamily)"
                                    :animating="dropAnimating"
                                />
                                <!-- Archetype mode: plain ChordDiagram; during forward morph, crossfade pair -->
                                <template v-else>
                                    <ChordDiagram
                                        v-if="family.chords[0]"
                                        :chord="family.chords[0]"
                                        :class="isMorphActive && MORPH_INDICES.has(idx) ? 'sbn-diag--out' : ''"
                                    />
                                    <ChordDiagram
                                        v-if="isMorphActive && MORPH_INDICES.has(idx) && barreFamilies[idx]?.chords[0]"
                                        :chord="barreFamilies[idx].chords[0]"
                                        class="sbn-diag--in"
                                    />
                                </template>
                            </div>

                            <span class="sbn-tile-hint">
                                {{ activeFamily === family.key ? 'collapse' : `${family.chords.length} voicings` }}
                            </span>
                        </button>
                    </div>

                    <!-- Connector -->
                    <div
                        v-if="activeFamily"
                        class="sbn-drawer-connector"
                        :style="{ '--tile-index': activeFamilyIndex, '--tile-count': activeTileCount }"
                    />

                    <!-- Drawer -->
                    <div v-if="activeFamily" class="sbn-archetype-drawer">
                        <div class="sbn-drawer-cards">
                            <Link
                                v-for="chord in (barreMode ? barreFamilies : archetypeFamilies).find(f => f.key === activeFamily)?.chords"
                                :key="chord.id"
                                :href="chordShowUrl(chord)"
                            >
                                <ChordCard :chord="chord" />
                            </Link>
                        </div>
                    </div>

                    <!-- Next-level CTA (archetype mode only) -->
                    <div v-if="!barreMode" class="sbn-archetype-next">
                        <button class="sbn-archetype-next-btn" :disabled="panelPhase !== 'idle'" @click="enterBarreMode">
                            Next level: 4 Common Barré Shapes →
                        </button>
                    </div>

                    <!-- Drop level CTA (barré mode, not yet in drop mode) -->
                    <div v-if="barreMode && !dropMode && !dropAnimating" class="sbn-archetype-next">
                        <button class="sbn-archetype-next-btn" :disabled="panelPhase !== 'idle'" @click="enterDropMode">
                            Next level: Drop Voicings →
                        </button>
                    </div>

                    <!-- Drop mode blurb (shown after animation completes) -->
                    <div v-if="dropMode" class="sbn-drop-blurb">
                        <p>
                            These are <strong>drop voicings</strong> — 4-part 7th chords with one voice
                            lowered an octave to spread across the strings.
                            Drop&nbsp;2 and Drop&nbsp;3 are the backbone of jazz comping.
                        </p>
                        <div class="sbn-drop-blurb-links">
                            <button class="sbn-drop-link" @click="fVoicing = 'drop2'">Browse Drop 2 →</button>
                            <button class="sbn-drop-link" @click="fVoicing = 'drop3'">Browse Drop 3 →</button>
                        </div>
                    </div>

                </div>

                <!-- Grouped panels (browse mode) -->
                <template v-if="!hasFilters && voicingGroups.length">
                    <details
                        v-for="group in voicingGroups"
                        :key="group.key"
                        class="sbn-voicing-group"
                        open
                    >
                        <summary class="sbn-voicing-group-summary">
                            <span class="sbn-voicing-group-name">{{ group.label }}</span>
                            <span class="sbn-voicing-group-count">{{ group.chords.length }}</span>
                            <span class="sbn-voicing-group-chevron">›</span>
                        </summary>
                        <div class="sbn-voicing-group-body">
                            <div class="sbn-chords-grid">
                                <Link
                                    v-for="chord in group.chords"
                                    :key="chord.id"
                                    :href="chordShowUrl(chord)"
                                    style="text-decoration: none;"
                                >
                                    <ChordCard :chord="chord" />
                                </Link>
                            </div>
                        </div>
                    </details>
                </template>

                <!-- Flat grid (filtered / search results) -->
                <div v-else-if="filteredOther.length" class="sbn-chords-grid">
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
