<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import ChordCard from '@/Components/Library/ChordCard.vue';
import ChordDiagram from '@/Components/Library/ChordDiagram.vue';
import AnimatedChordDiagram from '@/Components/Library/AnimatedChordDiagram.vue';
import type { ChordDiagramData } from '@/Components/Library/ChordDiagram.vue';
import { chordShowUrl } from '@/composables/useChordUrl';

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
    chromaticChords: ChordDiagramData[];
}

interface DropTarget {
    label: string;
    chord: ChordDiagramData;
    inversions: ChordDiagramData[];
}

interface ShellRelatedGroup {
    label: string;
    chords: ChordDiagramData[];
}

interface ShellTarget {
    label: string;
    chord: ChordDiagramData;
    related: ShellRelatedGroup[];
}

interface Props {
    archetypeFamilies: ArchetypeFamily[];
    barreFamilies: BarreFamily[];
    dropFamilies: Record<string, DropTarget>;
    shellFamilies: Record<string, ShellTarget>;
    otherChords: ChordDiagramData[];
    voicingCategories: Record<string, string>;
    chordQualities: Record<string, string>;
    totalCount: number;
}

const props = defineProps<Props>();

// ── Filter state ───────────────────────────────────────────
const search   = ref('');
const fQuality = ref('');
const fVoicing = ref(typeof window !== 'undefined' ? (new URLSearchParams(window.location.search).get('voicing') ?? '') : '');
const fPop     = ref('');
const fDiff    = ref('');
const fInv     = ref(typeof window !== 'undefined' ? (new URLSearchParams(window.location.search).get('inversion') ?? '') : '');
const fExt     = ref(typeof window !== 'undefined' ? (new URLSearchParams(window.location.search).get('ext') ?? '') : '');

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
    shellExiting.value = false;
    shellMode.value = false;
    shellAnimating.value = false;
    shellDone.value = false;
    shellTileTranslates.value = {};
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
    if (fVoicing.value && !(c.voicing_category === fVoicing.value || (fVoicing.value === 'archetype' && c.voicing_category.startsWith('archetype')))) return false;
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

// ── Shell voicing level ───────────────────────────────────────────────────
// Level 4: the two centred drop voicings (Fmaj7 / Fm7) lose their highest
// dot, revealing the shell voicing beneath.
// shellExiting: Bb tiles are fading out (brief phase before shellMode)
// shellMode:    only E-shape tiles remain, showing drop voicings
// shellAnimating: the dot-removal animation is running
// shellDone:    animation complete, shell blurb is visible
const shellExiting   = ref(false);
const shellMode      = ref(false);
const shellAnimating = ref(false);
const shellDone      = ref(false);

// Which barre tile indices show E-shape (keep) vs A-shape (exit) for shell level.
// barreFamilies order: [E, Em, A, Am] → indices 0,1 keep; 2,3 exit.
const SHELL_EXIT_INDICES = new Set([2, 3]);
const SHELL_KEEP_INDICES = new Set([0, 1]);

function shellTileClass(idx: number): Record<string, boolean> {
    if (SHELL_EXIT_INDICES.has(idx)) {
        // Keep sbn-tile--exit during exiting (animation running) and after
        // (forwards fill holds opacity:0). Add sbn-tile--shell-gone once
        // shellMode commits so the tile also leaves the flex flow.
        return {
            'sbn-tile--exit':        true,
            'sbn-tile--shell-gone':  shellMode.value || shellDone.value,
        };
    }
    return {};
}

// Translate E-shape tiles to center (reuse same FLIP pattern as barré gather)
const shellTileTranslates = ref<Record<number, string>>({});

function computeShellGatherTranslates(): Record<number, string> {
    if (!tilesRowRef.value) return {};
    const rowRect    = tilesRowRef.value.getBoundingClientRect();
    const rowCenterX = rowRect.left + rowRect.width / 2;
    const allTiles   = Array.from(tilesRowRef.value.querySelectorAll<HTMLElement>('.sbn-archetype-tile'));
    const keepTiles  = allTiles.filter((_, i) => SHELL_KEEP_INDICES.has(i));
    if (!keepTiles.length) return {};

    const tileW = keepTiles[0].getBoundingClientRect().width;
    const gap   = 10;
    // Target layout is 2 tiles (Gmaj7 | Gm7) centred in the row.
    const totalTiles = 2;
    const total  = totalTiles * tileW + (totalTiles - 1) * gap;
    const startX = rowCenterX - total / 2;

    const result: Record<number, string> = {};
    keepTiles.forEach((el, ci) => {
        const rect   = el.getBoundingClientRect();
        const currCx = rect.left + rect.width / 2;
        const destCx = startX + ci * (tileW + gap) + tileW / 2;
        result[ci] = `translateX(${(destCx - currCx).toFixed(1)}px)`;
    });
    return result;
}

async function enterShellMode() {
    if (!dropMode.value || shellMode.value || shellAnimating.value) return;
    activeFamily.value = null;

    // 1 — fade out Bb tiles (A/Am-shape, indices 2 & 3)
    shellExiting.value = true;
    await delay(480);

    // 2 — slide remaining two tiles to center
    shellTileTranslates.value = computeShellGatherTranslates();
    await delay(540);

    // 3 — commit shell mode and drop translates atomically.
    //     Exit tiles leave the DOM via the v-if filter; keep tiles have their
    //     inline transform cleared in the same tick so flexbox centres them
    //     immediately with no second slide.
    shellTileTranslates.value = {};
    shellMode.value = true;
    shellExiting.value = false;
    await delay(200);

    // 4 — run the dot-removal animation
    shellAnimating.value = true;
    await delay(800);
    shellAnimating.value = false;
    shellDone.value = true;
}

function shellTargetFor(barreFamily: BarreFamily): ChordDiagramData | null {
    return props.shellFamilies[barreFamily.key]?.chord ?? null;
}

// In shell mode, the AnimatedChordDiagram source is the drop voicing;
// its target is the shell voicing. We show it only for keep-indices.
function isShellKeep(idx: number): boolean {
    return SHELL_KEEP_INDICES.has(idx);
}

// ── Stepper ───────────────────────────────────────────────
const steps = [
    { n: 1, label: 'Open shapes' },
    { n: 2, label: 'Barré' },
    { n: 3, label: 'Drop voicings' },
    { n: 4, label: 'Shell' },
    { n: 5, label: 'Extensions', upcoming: true },  // ready for L5
];

const currentLevel = computed(() => {
    if (shellMode.value || shellDone.value) return 4;
    if (dropMode.value)                      return 3;
    if (barreMode.value)                     return 2;
    return 1;
});

function exitDropMode() {
    activeFamily.value = null;
    shellExiting.value = false;
    shellMode.value = false;
    shellAnimating.value = false;
    shellDone.value = false;
    shellTileTranslates.value = {};
    dropAnimating.value = false;
    dropMode.value = false;
}

function exitShellMode() {
    activeFamily.value = null;
    shellExiting.value = false;
    shellMode.value = false;
    shellAnimating.value = false;
    shellDone.value = false;
    shellTileTranslates.value = {};
}

function jumpToLevel(n: number) {
    // Allow jumping BACKWARD only — forward is gated through the animation pipeline.
    if (panelPhase.value !== 'idle') return;
    if (shellExiting.value || shellAnimating.value) return;
    if (n >= currentLevel.value) return;
    if (n === 1) exitBarreMode();
    if (n === 2) exitDropMode();
    if (n === 3) exitShellMode();
}
</script>

<template>
    <div class="sbn-page sbn-chord-library-main">

        <!-- ── Header ── -->
        <div class="sbn-lib-page-header">
            <h1 class="sbn-lib-page-title">Chord Dictionary</h1>
            <p class="sbn-lib-page-subtitle">Search by chord name or browse by category</p>

            <div class="sbn-lib-search-wrap">
                <div class="sbn-lib-search-box">
                    <svg class="sbn-lib-search-icon" width="18" height="18" viewBox="0 0 20 20" fill="none">
                        <circle cx="8" cy="8" r="6" stroke="currentColor" stroke-width="2"/>
                        <path d="M13 13L18 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                    <input
                        v-model="search"
                        type="search"
                        class="sbn-lib-search-input"
                        placeholder="Try: maj7, drop 2, rootless…"
                        autocomplete="off"
                    />
                    <button
                        v-if="search"
                        class="sbn-lib-search-clear"
                        @click="search = ''"
                        aria-label="Clear search"
                    >
                        <svg width="14" height="14" viewBox="0 0 16 16" fill="none">
                            <path d="M4 4L12 12M12 4L4 12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                    </button>
                </div>
            </div>
        </div>

        <!-- ── Content wrapper: grid left, sidebar right ── -->
        <div class="sbn-content-wrapper">

            <!-- Results -->
            <div class="sbn-results-container">

                <!-- Archetype / Barré panel (hidden during search/filter) -->
                <div v-if="!hasFilters && archetypeFamilies.length" id="archetypes" class="sbn-archetype-panel">

                    <!-- ── Stepper ── -->
                    <nav class="sbn-stepper" aria-label="Chord progression levels">
                        <template v-for="(step, i) in steps" :key="step.n">
                            <span
                                v-if="i > 0"
                                class="sbn-stepper-track"
                                :class="{
                                    'is-on':       currentLevel >= step.n,
                                    'is-upcoming': step.upcoming,
                                }"
                            />
                            <button
                                class="sbn-stepper-pill"
                                :class="{
                                    'is-current':  currentLevel === step.n,
                                    'is-done':     currentLevel >  step.n,
                                    'is-upcoming': step.upcoming,
                                }"
                                :disabled="step.upcoming || step.n > currentLevel"
                                @click="jumpToLevel(step.n)"
                            >
                                <span class="sbn-stepper-n">{{ step.n }}</span>
                                <span class="sbn-stepper-l">{{ step.label }}</span>
                            </button>
                        </template>
                    </nav>

                    <!-- ── Panel header (switches title/subtitle/back btn) ── -->
                    <div class="sbn-archetype-panel-header">
                        <div>
                            <p class="sbn-archetype-panel-title">
                                {{ shellMode ? 'Shell Voicings'
                                    : dropMode ? '4-Part 7th Chords'
                                    : barreMode ? 'Common Barré Shapes'
                                    : 'Archetypes' }}
                            </p>
                            <p class="sbn-archetype-panel-subtitle">
                                {{ shellMode
                                    ? '3-note structures — the ideal platform for adding extensions on top'
                                    : dropMode
                                            ? 'Drop voicings spread the 7th chord across the neck — the gateway to jazz harmony'
                                            : barreMode
                                                ? 'E and A shapes moved up the neck — the same fingering, any root'
                                                : 'The 8 fundamental open-position shapes' }}
                            </p>
                        </div>
                    </div>

                    <!-- ── Unified tile row ── -->
                    <!-- Source: always archetypeFamilies during forward animation so the
                         same 4 DOM nodes persist through exit→gather→morph→spread.
                         Only after barreMode commits do we switch to barreFamilies. -->
                    <div ref="tilesRowRef" class="sbn-archetype-tiles" :class="{ 'sbn-tiles--animating': panelPhase !== 'idle' }">
                        <button
                            v-for="(family, idx) in (barreMode ? barreFamilies : archetypeFamilies).filter((_, i) => !(shellMode || shellDone) || !SHELL_EXIT_INDICES.has(i))"
                            :key="family.key"
                            class="sbn-archetype-tile"
                            :class="[{ active: activeFamily === family.key }, shellExiting ? shellTileClass(idx) : tileClass(idx)]"
                            :style="{
                                '--tile-i': idx,
                                transition: (panelPhase === 'gathering' || (shellExiting && isShellKeep(idx)))
                                    ? 'transform 0.5s cubic-bezier(0.4,0,0.2,1)' : undefined,
                                transform: (shellExiting || shellMode || shellAnimating)
                                    ? (isShellKeep(idx) && shellTileTranslates[idx] ? shellTileTranslates[idx] : undefined)
                                    : (MORPH_INDICES.has(idx) && tileTranslates[idx] ? tileTranslates[idx] : undefined),
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
                                <!-- Shell mode: AnimatedChordDiagram with drop→shell target (keep tiles only) -->
                                <AnimatedChordDiagram
                                    v-if="shellMode && isShellKeep(idx) && family.chords[0]"
                                    :chord="dropTargetFor(family as BarreFamily) ?? family.chords[0]"
                                    :target-chord="shellTargetFor(family as BarreFamily)"
                                    :animating="shellAnimating"
                                />
                                <!-- Barré mode: AnimatedChordDiagram so dots can morph to drop voicings -->
                                <AnimatedChordDiagram
                                    v-else-if="barreMode && family.chords[0]"
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
                                <template v-if="activeFamily === family.key">collapse</template>
                                <template v-else-if="dropMode">{{ (dropFamilies[family.key]?.inversions?.length ?? 0) }} inversions</template>
                                <template v-else>{{ family.chords.length }} voicings</template>
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
                        <!-- Shell mode: all related voicings in one row -->
                        <template v-if="shellMode || shellDone">
                            <div class="sbn-drawer-cards">
                                <ChordCard
                                    v-for="chord in shellFamilies[activeFamily]?.related.flatMap(g => g.chords)"
                                    :key="chord.id"
                                    :chord="chord"
                                    :show-root="true"
                                    :same-tab="true"
                                />
                            </div>
                        </template>
                        <!-- Drop mode: inversions of the selected drop voicing -->
                        <template v-else-if="dropMode">
                            <p class="sbn-barre-drawer-intro">All inversions — same chord, different string sets</p>
                            <div class="sbn-drawer-cards">
                                <ChordCard
                                    v-for="chord in dropFamilies[activeFamily]?.inversions"
                                    :key="chord.id"
                                    :chord="chord"
                                    :show-root="true"
                                    :same-tab="true"
                                />
                            </div>
                        </template>
                        <!-- Barré mode: 12 chromatic positions to show movable shape concept -->
                        <template v-else-if="barreMode">
                            <p class="sbn-barre-drawer-intro">Same shape — every root</p>
                            <div class="sbn-drawer-cards">
                                <ChordCard
                                    v-for="chord in barreFamilies.find(f => f.key === activeFamily)?.chromaticChords"
                                    :key="chord.root_note"
                                    :chord="chord"
                                    :show-root="true"
                                    :same-tab="true"
                                />
                            </div>
                        </template>
                        <!-- Archetype mode: voicing cards -->
                        <div v-else class="sbn-drawer-cards">
                            <ChordCard
                                v-for="chord in archetypeFamilies.find(f => f.key === activeFamily)?.chords"
                                :key="chord.id"
                                :chord="chord"
                                :same-tab="true"
                            />
                        </div>
                    </div>

                    <!-- Next-level CTA (archetype mode only) -->
                    <div v-if="!barreMode" class="sbn-archetype-next">
                        <button class="sbn-archetype-next-btn" :disabled="panelPhase !== 'idle'" @click="enterBarreMode">
                            Next level: Barré Shapes →
                        </button>
                    </div>

                    <!-- Drop level CTA (barré mode, not yet in drop mode) -->
                    <div v-if="barreMode && !dropMode && !dropAnimating" class="sbn-archetype-next">
                        <button class="sbn-barre-back-btn" :disabled="panelPhase !== 'idle'" @click="exitBarreMode">
                            ← Open Shapes
                        </button>
                        <button class="sbn-archetype-next-btn" :disabled="panelPhase !== 'idle'" @click="enterDropMode">
                            Next level: Drop Voicings →
                        </button>
                    </div>

                    <!-- Drop mode blurb (shown after animation completes, before shell level) -->
                    <div v-if="dropMode && !shellMode && !shellExiting" class="sbn-drop-blurb">
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

                    <!-- Shell level CTA (shown after drop animation, before entering shell mode) -->
                    <div v-if="dropMode && !shellMode && !shellExiting && !shellDone" class="sbn-archetype-next">
                        <button class="sbn-barre-back-btn" @click="exitDropMode">
                            ← Barré Shapes
                        </button>
                        <button class="sbn-archetype-next-btn" @click="enterShellMode">
                            Next level: Shell Voicings →
                        </button>
                    </div>

                    <!-- Shell done back button -->
                    <div v-if="shellDone" class="sbn-archetype-next">
                        <button class="sbn-barre-back-btn" @click="exitShellMode">
                            ← Drop Voicings
                        </button>
                    </div>

                    <!-- Shell done blurb -->
                    <div v-if="shellDone" class="sbn-drop-blurb sbn-shell-blurb">
                        <p>
                            <strong>One step back, two steps forward.</strong>
                            Shell voicings strip a drop voicing down to its 3 essential tones —
                            root, 3rd and 7th. This lean structure leaves the top strings free,
                            making it the ideal platform to <em>add extension tones on top</em>:
                            a 9th here, a 13th there. Every jazz guitarist's secret weapon.
                        </p>
                        <div class="sbn-drop-blurb-links">
                            <button class="sbn-drop-link" @click="fVoicing = 'shell'">Browse Shell Voicings →</button>
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
                                    <ChordCard :chord="chord" :no-nav="true" />
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
                        <ChordCard :chord="chord" :no-nav="true" />
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
            <aside class="sbn-lib-filter-sidebar">
                <div class="sbn-lib-sidebar-header">
                    <h3>Filters</h3>
                    <span class="sbn-lib-sidebar-count">
                        <template v-if="searchLoading">Searching…</template>
                        <template v-else-if="usingTransposeSearch"><strong>{{ visibleCount }}</strong> voicings for <em>{{ search }}</em></template>
                        <template v-else-if="hasFilters"><strong>{{ visibleCount }}</strong> of {{ totalCount }} voicings</template>
                        <template v-else><strong>{{ totalCount }}</strong> voicings</template>
                        <span v-if="searchError" style="color: var(--clr-danger, #c00);">Search failed</span>
                        <button v-if="hasFilters" class="sbn-lib-clear-btn" @click="clearFilters">Clear</button>
                    </span>
                </div>

                <!-- Quality -->
                <div class="sbn-lib-sidebar-section">
                    <span class="sbn-lib-sidebar-label">Chord Quality</span>
                    <div class="sbn-lib-sidebar-options">
                        <button
                            v-for="q in allQualities"
                            :key="q.key"
                            class="sbn-lib-sidebar-option"
                            :class="{ 'sbn-filter-active': fQuality === q.key }"
                            @click="fQuality = fQuality === q.key ? '' : q.key"
                        >{{ q.key }}</button>
                    </div>
                </div>

                <!-- Voicing type -->
                <div class="sbn-lib-sidebar-section">
                    <span class="sbn-lib-sidebar-label">Voicing Type</span>
                    <div class="sbn-lib-sidebar-options">
                        <button
                            v-for="v in allVoicings"
                            :key="v.key"
                            class="sbn-lib-sidebar-option"
                            :class="{ 'sbn-filter-active': fVoicing === v.key }"
                            @click="fVoicing = fVoicing === v.key ? '' : v.key"
                        >{{ v.label }}</button>
                    </div>
                </div>

                <!-- Popularity -->
                <div class="sbn-lib-sidebar-section">
                    <span class="sbn-lib-sidebar-label">Popularity</span>
                    <div class="sbn-lib-sidebar-options">
                        <button
                            v-for="p in popularityOptions"
                            :key="p.key"
                            class="sbn-lib-sidebar-option"
                            :class="{ 'sbn-filter-active': fPop === p.key }"
                            @click="fPop = fPop === p.key ? '' : p.key"
                        >{{ p.label }}</button>
                    </div>
                </div>

                <!-- Difficulty -->
                <div class="sbn-lib-sidebar-section">
                    <span class="sbn-lib-sidebar-label">Difficulty</span>
                    <div class="sbn-lib-sidebar-options">
                        <button
                            v-for="d in difficultyOptions"
                            :key="d.key"
                            class="sbn-lib-sidebar-option"
                            :class="{ 'sbn-filter-active': fDiff === d.key }"
                            @click="fDiff = fDiff === d.key ? '' : d.key"
                        >{{ d.label }}</button>
                    </div>
                </div>

                <!-- Inversions -->
                <div v-if="allInversions.length" class="sbn-lib-sidebar-section">
                    <span class="sbn-lib-sidebar-label">Inversion</span>
                    <div class="sbn-lib-sidebar-options">
                        <button
                            v-for="inv in allInversions"
                            :key="inv.key"
                            class="sbn-lib-sidebar-option"
                            :class="{ 'sbn-filter-active': fInv === inv.key }"
                            @click="fInv = fInv === inv.key ? '' : inv.key"
                        >{{ inv.label }}</button>
                    </div>
                </div>

                <!-- Extensions -->
                <div v-if="allExtensions.length" class="sbn-lib-sidebar-section">
                    <span class="sbn-lib-sidebar-label">Extensions</span>
                    <div class="sbn-lib-sidebar-options">
                        <button
                            v-for="ext in allExtensions"
                            :key="ext"
                            class="sbn-lib-sidebar-option"
                            :class="{ 'sbn-filter-active': fExt === ext }"
                            @click="fExt = fExt === ext ? '' : ext"
                        >{{ ext }}</button>
                    </div>
                </div>

                <button v-if="hasFilters" class="sbn-lib-sidebar-clear" @click="clearFilters">
                    Clear All Filters
                </button>
            </aside>

        </div>
    </div>
</template>
