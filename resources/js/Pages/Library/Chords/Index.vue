<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import { Link, Head } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import ChordCard from '@/Components/Library/ChordCard.vue';
import ChordDiagram from '@/Components/Library/ChordDiagram.vue';
import AnimatedChordDiagram from '@/Components/Library/AnimatedChordDiagram.vue';
import type { ChordDiagramData } from '@/Components/Library/ChordDiagram.vue';
import { chordShowUrl } from '@/composables/useChordUrl';
import { readDifficultyQueryParam } from '@/composables/useBreadcrumb';
import FilterToggleButton from '@/Components/Library/FilterToggleButton.vue';
import FilterSidebar from '@/Components/Library/FilterSidebar.vue';

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

interface ExtFamilies {
    tiles: ChordDiagramData[];
}

interface Props {
    archetypeFamilies: ArchetypeFamily[];
    barreFamilies: BarreFamily[];
    dropFamilies: Record<string, DropTarget>;
    shellFamilies: Record<string, ShellTarget>;
    extFamilies: ExtFamilies;
    otherChords: ChordDiagramData[];
    top10Slugs: string[];
    voicingCategories: Record<string, string>;
    chordQualities: Record<string, string>;
    totalCount: number;
}

const props = defineProps<Props>();

// ── Filter / sort state ────────────────────────────────────
const fSort    = ref('top10');
const search   = ref('');
const fQuality = ref('');
const fVoicing = ref(typeof window !== 'undefined' ? (new URLSearchParams(window.location.search).get('voicing') ?? '') : '');
const fPop     = ref('');
const fDiff    = ref(readDifficultyQueryParam());
const fInv     = ref(typeof window !== 'undefined' ? (new URLSearchParams(window.location.search).get('inversion') ?? '') : '');

// Extensions are composable facets, not one option per combination: the DB
// stores each chord's extensions as a comma-joined combo string (e.g.
// 'b9,13'), so a naive "one pill per unique string" list explodes into
// near-duplicate options. We split into individual tokens (9, b9, #11, ...)
// and let a chord match if it carries ANY of the selected tokens.
function extensionTokens(raw: string | null | undefined): string[] {
    return (raw ?? '').split(',').map(s => s.trim()).filter(Boolean);
}
function toggleExt(token: string) {
    fExt.value = fExt.value.includes(token)
        ? fExt.value.filter(t => t !== token)
        : [...fExt.value, token];
}
const fExt = ref<string[]>(
    typeof window !== 'undefined'
        ? extensionTokens(new URLSearchParams(window.location.search).get('ext'))
        : []
);

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

// Sort by scale degree, then flat/natural/sharp — '9' before '#9', 'b13'
// before '13' — rather than plain alphabetical (which would scatter '#11'
// and 'b9' away from their neighbors).
function extensionSortKey(tok: string): [number, number] {
    const m = tok.match(/^(b|#)?(\d+)$/);
    if (!m) return [999, 1];
    const accidental = m[1] === 'b' ? 0 : m[1] === '#' ? 2 : 1;
    return [parseInt(m[2], 10), accidental];
}

const allExtensions = computed(() => {
    const seen = new Set<string>();
    for (const c of props.otherChords) {
        for (const tok of extensionTokens(c.extensions)) {
            seen.add(tok);
        }
    }
    return [...seen].sort((a, b) => {
        const [numA, accA] = extensionSortKey(a);
        const [numB, accB] = extensionSortKey(b);
        return numA !== numB ? numA - numB : accA - accB;
    });
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
    if (fExt.value.length) {
        const tokens = extensionTokens(c.extensions);
        if (!fExt.value.some(sel => tokens.includes(sel))) return false;
    }
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
    !!(search.value || fQuality.value || fVoicing.value || fPop.value || fDiff.value || fInv.value || fExt.value.length)
);

const filtersOpen = ref(false);

const visibleCount = computed(() => filteredOther.value.length);

// ── Quality labels ─────────────────────────────────────────
const QUALITY_LABELS: Record<string, string> = {
    maj7: 'Major 7th', maj: 'Major', m7: 'Minor 7th', min: 'Minor',
    dom7: 'Dominant 7th', m7b5: 'Half Diminished', o7: 'Diminished 7th',
    maj6: 'Major 6th', m6: 'Minor 6th', mMaj7: 'Minor Major 7th',
    aug7: 'Augmented 7th', aug: 'Augmented', dim: 'Diminished',
    add9: 'Add 9', maj9: 'Major 9th', m9: 'Minor 9th',
};
function qualityLabel(q: string): string { return QUALITY_LABELS[q] ?? q; }

// ── Top 10 hitlist ─────────────────────────────────────────
// Promoted slugs come first (in config order), then remaining chords by popularity.
const top10Chords = computed((): ChordDiagramData[] => {
    const slugOrder = new Map(props.top10Slugs.map((s, i) => [s, i]));
    const promoted: ChordDiagramData[] = [];
    const rest: ChordDiagramData[] = [];
    for (const c of props.otherChords) {
        if (c.slug && slugOrder.has(c.slug)) {
            promoted.push(c);
        } else {
            rest.push(c);
        }
    }
    promoted.sort((a, b) => (slugOrder.get(a.slug!) ?? 999) - (slugOrder.get(b.slug!) ?? 999));
    return [...promoted, ...rest];
});

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
    fExt.value = [];
    fSort.value = 'top10';
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
const SHELL_EXIT_INDICES = new Set([1, 2, 3]);
const SHELL_KEEP_INDICES = new Set([0]);

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
    // Single tile — translate to row centre.
    const result: Record<number, string> = {};
    keepTiles.forEach((el, ci) => {
        const rect   = el.getBoundingClientRect();
        const currCx = rect.left + rect.width / 2;
        result[ci] = `translateX(${(rowCenterX - currCx).toFixed(1)}px)`;
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
// ── Extensions level ──────────────────────────────────────
const extMode      = ref(false);
const extCards     = ref<ChordDiagramData[]>([]);
const extAnimating = ref(false);
// Track which card keys are in their entry animation this render cycle
const extEntering  = ref(new Set<number>());

async function enterExtMode() {
    if (!shellDone.value || extMode.value || extAnimating.value) return;
    activeFamily.value = null;
    extMode.value = true;
    extAnimating.value = true;

    for (const tile of props.extFamilies.tiles) {
        extEntering.value = new Set([tile.id]);
        extCards.value = [...extCards.value, tile];
        await delay(600);
        extEntering.value = new Set();
        await delay(200);
    }

    extAnimating.value = false;
}

function exitExtMode() {
    activeFamily.value = null;
    extMode.value = false;
    extCards.value = [];
    extAnimating.value = false;
    extEntering.value = new Set();
}

const steps = [
    { n: 1, label: 'Open shapes' },
    { n: 2, label: 'Barré' },
    { n: 3, label: 'Drop voicings' },
    { n: 4, label: 'Shell' },
    { n: 5, label: 'Extensions' },
];

const currentLevel = computed(() => {
    if (extMode.value)                       return 5;
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
    exitExtMode();
}

function jumpToLevel(n: number) {
    // Allow jumping BACKWARD only — forward is gated through the animation pipeline.
    if (panelPhase.value !== 'idle') return;
    if (shellExiting.value || shellAnimating.value || extAnimating.value) return;
    if (n >= currentLevel.value) return;
    if (n === 1) exitBarreMode();
    if (n === 2) exitDropMode();
    if (n === 3) exitShellMode();
    if (n === 4) exitExtMode();
}
</script>

<template>
    <Head>
        <title>Bossa Nova &amp; Jazz Guitar Chords | Soul Bossa Nova</title>
        <meta name="description" content="Browse hundreds of guitar chords with interactive diagrams — voicings, intervals and fingerings for Bossa Nova and Latin Jazz." />
        <meta property="og:title" content="Bossa Nova & Jazz Guitar Chords | Soul Bossa Nova" />
        <meta property="og:description" content="Interactive chord library with voicings, interval labels and fingerings for Bossa Nova and Latin Jazz guitarists." />
        <meta property="og:type" content="website" />
    </Head>

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

            <FilterToggleButton v-model="filtersOpen" :has-filters="hasFilters">Filters</FilterToggleButton>
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
                                {{ shellMode ? 'Shell Voicing'
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
                            v-for="(family, idx) in (barreMode ? barreFamilies : archetypeFamilies).filter((_, i) => !(shellMode || shellDone) || !SHELL_EXIT_INDICES.has(i)).filter((_, i) => !shellDone || i === 0)"
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

                            <!-- Drop badge: shown on exit tiles during L1→L2 transition -->
                            <span
                                v-if="panelPhase === 'exiting' && !barreMode && EXIT_INDICES.has(idx)"
                                class="sbn-tile-badge"
                            >drop</span>

                            <!-- −1 tone badge: shown on keep tiles during shell dot-removal -->
                            <span
                                v-if="shellAnimating && SHELL_KEEP_INDICES.has(idx)"
                                class="sbn-tile-badge sbn-tile-badge--minus"
                            >−1 tone</span>
                        </button>

                        <!-- Extension tiles — slide in from right one at a time -->
                        <button
                            v-for="chord in extCards"
                            :key="chord.id"
                            class="sbn-archetype-tile"
                            :class="{ 'sbn-tile--ext-enter': extEntering.has(chord.id), active: activeFamily === ('ext-' + chord.id) }"
                            @click="toggleFamily('ext-' + chord.id)"
                        >
                            <span class="sbn-archetype-tile-name">{{ chord.name }}</span>
                            <div class="sbn-archetype-tile-diagram sbn-tile-diagram-wrap">
                                <ChordDiagram :chord="chord" />
                            </div>
                            <span class="sbn-tile-hint">{{ activeFamily === ('ext-' + chord.id) ? 'collapse' : 'extension' }}</span>

                            <!-- New badge: shown while the extension tile is entering -->
                            <span
                                v-if="extEntering.has(chord.id)"
                                class="sbn-tile-badge sbn-tile-badge--new"
                            >new</span>
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
                            Next level: Shell Voicing →
                        </button>
                    </div>

                    <!-- Shell done / ext CTA row -->
                    <div v-if="shellDone && !extMode" class="sbn-archetype-next">
                        <button class="sbn-barre-back-btn" @click="exitShellMode">
                            ← Drop Voicings
                        </button>
                        <button class="sbn-archetype-next-btn" :disabled="extAnimating" @click="enterExtMode">
                            Next level: Extensions →
                        </button>
                    </div>

                    <!-- Ext mode back button -->
                    <div v-if="extMode && !extAnimating" class="sbn-archetype-next">
                        <button class="sbn-barre-back-btn" @click="exitExtMode">
                            ← Shell Voicing
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
                            <button class="sbn-drop-link" @click="fVoicing = 'shell'">Browse Shell Voicing →</button>
                        </div>
                    </div>

                </div>

                <!-- Top 10 hitlist (sort = top10, no active text/quality filters) -->
                <div v-if="!hasFilters && fSort === 'top10'" class="sbn-lib-hitlist" role="list">
                    <div
                        v-for="(chord, index) in top10Chords"
                        :key="chord.id"
                        class="sbn-lib-row sbn-clib-hitlist-row"
                        role="listitem"
                    >
                        <div class="sbn-hitlist-rank">{{ index + 1 }}</div>
                        <Link :href="chordShowUrl(chord)" class="sbn-clib-row-inner">
                            <div class="sbn-clib-row-card">
                                <ChordCard :chord="chord" :no-nav="true" :show-root="true" :same-tab="true" />
                            </div>
                            <div class="sbn-clib-row-meta">
                                <div class="sbn-clib-row-chips">
                                    <span v-if="chord.quality" class="sbn-chord-tab-value sbn-chord-tab-value--quality">{{ qualityLabel(chord.quality) }}</span>
                                    <span v-if="chord.inversion_label" class="sbn-chord-tab-value sbn-chord-tab-value--inv">{{ chord.inversion_label }}</span>
                                </div>
                                <p v-if="chord.description" class="sbn-lib-row-desc">{{ chord.description }}</p>
                                <span class="sbn-lib-row-read-more">
                                    Read more
                                    <svg width="13" height="13" viewBox="0 0 16 16" fill="none">
                                        <path d="M3 8h10M9 4l4 4-4 4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                                    </svg>
                                </span>
                            </div>
                        </Link>
                    </div>
                </div>

                <!-- Grouped panels (browse mode) -->
                <template v-else-if="!hasFilters && voicingGroups.length">
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
            <FilterSidebar v-model="filtersOpen" :has-filters="hasFilters" @clear="clearFilters">
                <template #title>Filters</template>
                <template #count>
                    <template v-if="searchLoading">Searching…</template>
                    <template v-else-if="usingTransposeSearch"><strong>{{ visibleCount }}</strong> voicings for <em>{{ search }}</em></template>
                    <template v-else-if="hasFilters"><strong>{{ visibleCount }}</strong> of {{ totalCount }} voicings</template>
                    <template v-else><strong>{{ totalCount }}</strong> voicings</template>
                    <span v-if="searchError" style="color: var(--clr-danger, #c00);">Search failed</span>
                </template>

                <!-- Sort -->
                <div class="sbn-lib-sidebar-section">
                    <span class="sbn-lib-sidebar-label">Sort by</span>
                    <div class="sbn-lib-sidebar-options">
                        <button
                            :class="['sbn-lib-sidebar-option', { 'sbn-sort-active': fSort === 'popularity' }]"
                            @click="fSort = 'popularity'"
                        >Popularity</button>
                        <button
                            :class="['sbn-lib-sidebar-option', { 'sbn-sort-active': fSort === 'top10' }]"
                            @click="fSort = 'top10'"
                        >Top 10</button>
                    </div>
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

                <!-- Extensions (composable — pick any combination) -->
                <div v-if="allExtensions.length" class="sbn-lib-sidebar-section">
                    <span class="sbn-lib-sidebar-label">Extensions</span>
                    <div class="sbn-lib-sidebar-options">
                        <button
                            v-for="ext in allExtensions"
                            :key="ext"
                            class="sbn-lib-sidebar-option"
                            :class="{ 'sbn-filter-active': fExt.includes(ext) }"
                            @click="toggleExt(ext)"
                        >{{ ext }}</button>
                    </div>
                </div>

            </FilterSidebar>

        </div>
    </div>
</template>

<style scoped>
/* ── Two-column hitlist grid ── */
.sbn-lib-hitlist {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
}

@media (max-width: 640px) {
    .sbn-lib-hitlist { grid-template-columns: 1fr; }
}

/* ── Hitlist row ── */
.sbn-clib-hitlist-row {
    align-items: flex-start;
}
.sbn-clib-hitlist-row .sbn-hitlist-rank {
    background: var(--clr-surface-2);
    border-radius: var(--radius);
}

.sbn-clib-row-card :deep(.sbn-chord-card) {
    width: 140px;
}

.sbn-clib-row-inner {
    display: flex;
    align-items: flex-start;
    gap: 20px;
    flex: 1;
    text-decoration: none;
    color: inherit;
}

.sbn-clib-row-inner:hover .sbn-lib-row-desc {
    color: var(--clr-text);
}

.sbn-clib-row-card {
    flex-shrink: 0;
    --card-name-size: 1.4rem;
}

.sbn-clib-row-card :deep(.sbn-chord-card) {
    width: 140px;
}

.sbn-clib-row-meta {
    display: flex;
    flex-direction: column;
    gap: 10px;
    align-self: flex-start;
    min-width: 0;
    padding: 12px 0;
}

.sbn-clib-row-chips {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}

/* ── Chord tab badges (replicate Show.vue styles, not scoped there) ── */
.sbn-clib-row-chips .sbn-chord-tab-value {
    display: inline-block;
    padding: 1px 6px;
    border-radius: 999px;
    font-size: 0.65em;
    font-weight: 700;
    letter-spacing: 0.02em;
    color: #fff;
}

.sbn-clib-row-chips .sbn-chord-tab-value--quality { background: #4a9e6b; }
.sbn-clib-row-chips .sbn-chord-tab-value--voicing { background: var(--clr-mod-chord); }
.sbn-clib-row-chips .sbn-chord-tab-value--ext     { background: var(--clr-mod-progression); }
.sbn-clib-row-chips .sbn-chord-tab-value--inv     { background: var(--clr-primary); }
.sbn-clib-row-chips .sbn-chord-tab-value:not([class*="--"]) { background: var(--clr-text-muted); }

.sbn-clib-hitlist-row .sbn-lib-row-desc {
    margin: 0;
    align-self: center;
    display: block;
    -webkit-line-clamp: unset;
    line-clamp: unset;
    overflow: visible;
}
</style>
