<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

const siblingsCanLeft  = ref(false);
const siblingsCanRight = ref(false);

function updateSiblingsScroll() {
    const el = siblingsScrollEl.value;
    if (!el) return;
    siblingsCanLeft.value  = el.scrollLeft > 0;
    siblingsCanRight.value = el.scrollLeft + el.clientWidth < el.scrollWidth - 1;
}

let siblingsRo: ResizeObserver | null = null;
import { Link } from '@inertiajs/vue3';
import Breadcrumb from '@/Components/Breadcrumb.vue';
import { mountSbnNodes } from '@/lib/mountSbnNodes';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import ChordCard from '@/Components/Library/ChordCard.vue';
import ProgressionLink from '@/Components/Library/ProgressionLink.vue';
import MediaShelf from '@/Components/Library/MediaShelf.vue';
import SongShelfCard from '@/Components/Library/SongShelfCard.vue';
import CourseShelfCard from '@/Components/Course/CourseShelfCard.vue';
import type { CourseShelfCardData } from '@/Components/Course/CourseShelfCard.vue';
import type { SongLinkData } from '@/Components/Library/SongLink.vue';
import type { ChordDiagramData } from '@/Components/Library/ChordDiagram.vue';


interface ProgressionRef {
    id: number;
    slug: string;
    name: string;
    category: string;
    numeralsDisplay: string;
    pinnedSlot: number | null;
}

// Edu content for the chord's quality (EduContentService::qualityTopic).
// null when the quality has no qualities/*.md file.
interface QualityTopic {
    slug: string;
    title: string;
    summary: string;
    description: string | null;
    usage: string | null;
    body_html: string;
    has_widgets: boolean;
}

interface ChordAlias {
    root_note: string;
    quality: string;
    extensions: string;
    bass_note: string | null;
    interval_labels: string | null;
    notes: string | null;
    name: string;
    /** True for generated dim7 dom7(b9) readings — the dominant root is absent. */
    rootless?: boolean;
}

interface Props {
    chord: ChordDiagramData;
    aliases: ChordAlias[];
    aliasInversions?: Record<number, ChordDiagramData[]>;
    initialAliasIdx?: number | null;
    /** How the visitor arrived (dim7 pages): 'dominant' = searched a dom7(b9). */
    arrivedVia?: 'diminished' | 'dominant';
    siblings: ChordDiagramData[];
    inversions: ChordDiagramData[];
    songs: SongLinkData[];
    progressions: ProgressionRef[];
    qualityTopic?: QualityTopic | null;
    courses: CourseShelfCardData[];
}

const props = defineProps<Props>();
defineOptions({ layout: PublicLayout });

const siblingsScrollEl = ref<HTMLElement | null>(null);
function scrollSiblings(dir: 1 | -1) {
    siblingsScrollEl.value?.scrollBy({ left: dir * 122, behavior: 'smooth' });
}

onMounted(() => {
    const el = siblingsScrollEl.value;
    if (!el) return;
    el.addEventListener('scroll', updateSiblingsScroll, { passive: true });
    siblingsRo = new ResizeObserver(updateSiblingsScroll);
    siblingsRo.observe(el);
    updateSiblingsScroll();
});

// ── Theory data (mirrors legacy sbn_chord_detail_theory) ─────────────────────
const theoryMap: Record<string, { intervals: string; function: string; typical_context: string; related: string[]; tension: number }> = {
    maj7:  { intervals: 'Root, Major 3rd, Perfect 5th, Major 7th',       function: 'Tonic — creates a rich, stable, dreamy sound.',           typical_context: 'Most often appears on I or IV in major keys. A cornerstone of jazz harmony.',                   related: ['maj9','maj6','add9'],      tension: 1 },
    maj:   { intervals: 'Root, Major 3rd, Perfect 5th',                   function: 'Tonic — the fundamental major triad. Bright and stable.',  typical_context: 'Appears on I, IV, V in major keys. The building block of Western harmony.',                    related: ['maj7','maj6','add9'],      tension: 0 },
    m7:    { intervals: 'Root, Minor 3rd, Perfect 5th, Minor 7th',        function: 'Subdominant — softer and more introspective.',             typical_context: 'Appears on IIm7, IIIm7, VIm7 in major keys. The II chord in a II-V-I.',                        related: ['m9','m11','m6','mMaj7'],   tension: 2 },
    min:   { intervals: 'Root, Minor 3rd, Perfect 5th',                   function: 'Tonic minor — darker, more serious than major triad.',     typical_context: 'I, IV, V in minor keys. Im, IVm, Vm in modal contexts.',                                       related: ['m7','m9','mMaj7'],         tension: 0 },
    dom7:  { intervals: 'Root, Major 3rd, Perfect 5th, Minor 7th',        function: 'Dominant — high tension, strongly wants to resolve.',      typical_context: 'V7 in major or minor keys. Secondary dominants resolve a perfect 4th up.',                       related: ['9','13','7b9','7alt'],     tension: 5 },
    m7b5:  { intervals: 'Root, Minor 3rd, Diminished 5th, Minor 7th',     function: 'Half-diminished — IIø in minor II-V-I.',                   typical_context: 'IIm7b5 in minor keys. Also appears on the VII degree in major keys.',                          related: ['o7','m7'],                 tension: 4 },
    o7:    { intervals: 'Root, Minor 3rd, Diminished 5th, Diminished 7th',function: 'Fully diminished — maximum tension. Symmetrical.',         typical_context: 'VII°7 in harmonic minor. Substitute for dominant b9 chords.',                                  related: ['m7b5','dom7'],             tension: 5 },
    maj6:  { intervals: 'Root, Major 3rd, Perfect 5th, Major 6th',        function: 'Tonic — warm, slightly jazzy alternative to maj7.',        typical_context: 'Imaj6, IVmaj6. Very common in swing and bossa nova.',                                           related: ['maj7','add9'],             tension: 1 },
    m6:    { intervals: 'Root, Minor 3rd, Perfect 5th, Major 6th',        function: 'Minor tonic with a slight major colour.',                  typical_context: 'Im6, IVm6. Strong in minor ii-V-i resolutions and bossa nova.',                                  related: ['m7','mMaj7'],              tension: 2 },
    mMaj7: { intervals: 'Root, Minor 3rd, Perfect 5th, Major 7th',        function: 'Minor tonic with a leading tone — extremely tense.',       typical_context: 'ImMaj7 — first chord of a minor line cliché (descending inner voice motion).',                  related: ['m7','m6','o7'],            tension: 4 },
    aug7:  { intervals: 'Root, Major 3rd, Augmented 5th, Minor 7th',      function: 'Augmented dominant — tension with an upward pull.',        typical_context: 'V7#5, bVII+7. Common in jazz and gospel.',                                                     related: ['dom7'],                   tension: 5 },
};

// ── Edu data ─────────────────────────────────────────────────────────────────
// Quality description/usage now comes from resources/edu/qualities/*.md via the
// `qualityTopic` prop (EduContentService). The former inline `qualityEdu` map
// was removed in Task 3 (8.1).

const voicingEdu: Record<string, { name: string; detail: string; tip: string }> = {
    archetype:     { name: 'Archetypes',       detail: 'The fundamental open-position guitar chords (E, Em, A, Am, D, Dm, C, G) and their 7th-chord siblings. These are transposable shapes that form barré chords when moved up the neck.',                                            tip: 'Master all 8 basic archetypes first, then learn their 7th-chord variants. Once comfortable, practice barré versions starting with the E and A shapes.' },
    drop2:         { name: 'Drop 2',            detail: 'Take a closed-position chord and drop the second-highest note down an octave. This opens up the voicing, spreading the notes across a wider range while keeping the sound balanced.',                                          tip: 'Practice connecting Drop 2 voicings through ii-V-I progressions. Move the minimum number of fingers between chords.' },
    drop3:         { name: 'Drop 3',            detail: 'Drop the third-highest note from a closed voicing down an octave. Creates a wider spread than Drop 2, with a gap in the middle. Works well for solo guitar and chord melody.',                                                 tip: 'Drop 3 shapes often skip a string in the middle. The payoff is a rich, full sound that fills more sonic space.' },
    shell:         { name: 'Shell Voicings',    detail: 'Strip a chord down to its bare essentials: root, third, and seventh. Three notes that define the chord quality with nothing extra.',                                                                                           tip: 'Start with shells on the 6th and 5th strings. Learn to comp through entire standards using only shells.' },
    rootless:      { name: 'Rootless',          detail: 'Remove the root entirely and let the bass player handle it. What remains are the chord\'s color tones — third, seventh, and extensions.',                                                                                     tip: 'These only work well when a bass player is covering the root. In a trio or quartet? Go rootless and enjoy the freedom.' },
    closed:        { name: 'Closed Position',   detail: 'All four chord tones of a seventh chord packed within one octave. Compact and dense — the textbook chord spelling.',                                                                                                          tip: 'Understanding closed voicings is essential — they\'re the foundation from which Drop 2 and Drop 3 are derived.' },
    closed_triads: { name: 'Closed Triads',     detail: 'Three-note chords in close position — root, third, and fifth all within one octave. Systematic inversions across all string sets.',                                                                                           tip: 'Learn all three inversions on one string set, then connect them up and down the neck. This is how you unlock the entire fretboard.' },
    spread_triads: { name: 'Spread Triads',     detail: 'Three-note chords with notes spread across a wider range than one octave. Bigger sound, great for fills and melodic playing.',                                                                                               tip: 'Spread triads work especially well for country, R&B, and jazz fills.' },
    custom:        { name: 'Custom Voicings',   detail: 'Unique shapes that don\'t fit neatly into standard categories. Includes open-string voicings, hybrid grips, and guitar-specific fingerings.',                                                                                 tip: 'Some of the most beautiful guitar chords live in this category.' },
};

const inversionEdu: Record<string, { desc: string; context: string }> = {
    inv1: { desc: 'The third is the lowest note. The chord sounds lighter and less anchored — same harmony, different angle.',      context: 'Creates smoother bass movement. Instead of jumping by 4ths and 5ths, the bass can move by step.' },
    inv2: { desc: 'The fifth is the lowest note. Slightly less stable than root position — more open and transitional.',           context: 'Second inversions connect other chords smoothly. Often used as a passing or transitional voicing.' },
    inv3: { desc: 'The seventh is the lowest note. Only possible on 7th chords. Creates a strong pull toward resolution.',         context: 'The seventh in the bass typically resolves down by a half step. A powerful voice-leading tool in jazz.' },
};

// ── Computed ──────────────────────────────────────────────────────────────────
const theory       = computed(() => theoryMap[props.chord.quality] ?? null);
const activeTheory = computed(() => theoryMap[activeQuality.value] ?? theory.value);
// eduQ: the quality's description/usage prose, or null when the quality has no
// topic or no description — in which case the identity section falls back to
// `theory.typical_context`, exactly as before.
const eduQ = computed(() => {
    const t = props.qualityTopic;
    if (!t || !t.description) return null;
    return { description: t.description, usage: t.usage ?? '' };
});

// Quality body_html is rendered through mountSbnNodes ONLY when the body
// carries an <sbn-widget> (has_widgets, decided at the parse layer). No
// quality body has one today, so this is dormant until one does — the
// auto-light-up seam, not dead code. Prose-only bodies are never shown here
// because they would just restate `description`.
const showQualityBody = computed(() => props.qualityTopic?.has_widgets === true);
const qualityBodyRef = ref<HTMLElement | null>(null);
let unmountQualityBody: (() => void) | null = null;

async function mountQualityBody(): Promise<void> {
    if (unmountQualityBody) { unmountQualityBody(); unmountQualityBody = null; }
    if (!showQualityBody.value || !qualityBodyRef.value) return;
    unmountQualityBody = await mountSbnNodes(qualityBodyRef.value);
}

onMounted(mountQualityBody);
watch(() => props.qualityTopic?.slug, mountQualityBody);
onBeforeUnmount(() => {
    unmountQualityBody?.(); unmountQualityBody = null;
    siblingsScrollEl.value?.removeEventListener('scroll', updateSiblingsScroll);
    siblingsRo?.disconnect();
});

// ── Alias switcher ────────────────────────────────────────────────────────────
// -1 = primary chord; 0..n = alias index.
// Search-result deep-links land with `initialAliasIdx` set so the hero opens
// already showing the searched alias instead of the parent chord. Inertia
// reuses the page component across navigations, so we also watch the prop —
// otherwise the initial ref value sticks across links and the second deep-link
// silently shows the primary instead of the requested alias.
const activeAliasIdx = ref(props.initialAliasIdx ?? -1);
watch(() => props.initialAliasIdx, (v) => {
    activeAliasIdx.value = v ?? -1;
});
const activeAlias = computed(() => activeAliasIdx.value >= 0 ? props.aliases[activeAliasIdx.value] : null);

// Diminished-7th page: the primary chord is a dim7, so the inversions are the
// four equal-minor-third readings and the aliases are the four rootless dom7(b9)
// interpretations. Drives the two dim7-specific edu blurbs + the rootless badge.
const isDiminished = computed(() => ['o7', 'dim7', 'dim'].includes(props.chord.quality));
const activeIsRootless = computed(() => activeAlias.value?.rootless === true);
// On a dim7 page, did the visitor arrive by searching a dom7(b9)? If so, the
// teaching angle flips: the dominant they wanted is revealed to BE a dim7 shape.
const arrivedViaDominant = computed(() => isDiminished.value && props.arrivedVia === 'dominant');

// Merged view: fret shape always from chord, name/quality/extensions from active alias when set
const activeQuality    = computed(() => activeAlias.value?.quality    ?? props.chord.quality);
const activeExtensions = computed(() => activeAlias.value?.extensions ?? props.chord.extensions ?? '');

// Chord passed to ChordCard — overlay all alias fields when active
const displayChord = computed(() => {
    if (!activeAlias.value) return props.chord;
    return {
        ...props.chord,
        root_note:       activeAlias.value.root_note,
        quality:         activeAlias.value.quality,
        extensions:      activeAlias.value.extensions,
        bass_note:       activeAlias.value.bass_note ?? '',
        interval_labels: activeAlias.value.interval_labels ?? props.chord.interval_labels,
        notes:           activeAlias.value.notes ?? props.chord.notes,
    };
});

const formattedActiveName = computed(() => {
    const root = formatNote(activeAlias.value?.root_note ?? props.chord.root_note ?? '');
    const quality = activeAlias.value?.quality ?? props.chord.quality;
    const [qual, core] = qualityMap[quality] ?? ['', quality];
    const ext = (activeAlias.value?.extensions ?? props.chord.extensions ?? '').replace(/#/g, '♯').replace(/b(?=[0-9])/g, '♭');
    const bass = formatNote(activeAlias.value?.bass_note ?? props.chord.bass_note ?? '');
    let html = '<span class="sbn-chord-symbol">';
    if (root) html += `<span class="sbn-chord-root">${root}</span>`;
    if (qual) html += `<span class="sbn-chord-quality">${qual}</span>`;
    if (core) html += `<span class="sbn-chord-ext">${core}</span>`;
    if (ext)  html += `<span class="sbn-chord-ext sbn-chord-ext--extra">(${ext})</span>`;
    if (bass) html += `<span class="sbn-chord-bass">/${bass}</span>`;
    html += '</span>';
    return html;
});

const chordPopularityTier = computed(() => {
    const p = props.chord.popularity ?? 0;
    if (p >= 11) return { tier: 'iconic',     label: 'Iconic' };
    if (p >= 6)  return { tier: 'essential',  label: 'Essential' };
    if (p >= 3)  return { tier: 'common',     label: 'Common' };
    if (p >= 1)  return { tier: 'occasional', label: 'Rare' };
    return null;
});

const eduV     = computed(() => voicingEdu[props.chord.voicing_category] ?? null);
const eduInv   = computed(() => props.chord.inversion && props.chord.inversion !== 'root' ? (inversionEdu[props.chord.inversion] ?? null) : null);

const INV_ORDER: Record<string, number> = { root: 0, inv1: 1, inv2: 2, inv3: 3 };

// When an alias is active the inversion list swaps to the alias's identity
// (its quality + extensions + reinterpreted root). The "self" entry is still
// the current chord's diagram — same shape, just relabeled — so we keep
// pushing props.chord on the list for the self-highlight.
const activeInversions = computed<ChordDiagramData[]>(() => {
    if (activeAliasIdx.value < 0) return props.inversions ?? [];
    return props.aliasInversions?.[activeAliasIdx.value] ?? [];
});

// When an alias is active, the current diagram's inversion role under the
// alias's identity differs from its native inversion_label. Derive from
// the alias's interval_labels (first non-x token → slot).
const ALIAS_SLOT_LABEL: Record<string, string> = {
    root: 'Root Position', inv1: '1st Inversion', inv2: '2nd Inversion', inv3: '3rd Inversion',
    rootless: 'Rootless',
};
function aliasInversionSlotFrontend(intervalLabels: string | null): string {
    if (!intervalLabels) return 'root';
    const tokens = intervalLabels.split(',').map(t => t.trim()).filter(t => t && t.toLowerCase() !== 'x');
    if (!tokens.length) return 'root';
    const norm = tokens[0].replace(/[#b]/g, '').toUpperCase();
    if (norm === 'R' || norm === '1') return 'root';
    if (norm === '3') return 'inv1';
    if (norm === '5') return 'inv2';
    if (norm === '7' || norm === '6') return 'inv3';
    return 'root';
}
const activeSelfInversion = computed(() => {
    if (!activeAlias.value) return props.chord.inversion ?? 'root';
    // Generated dom7(b9) readings are rootless — the displayed voicing sits in the
    // "Rootless" slot, not a conventional root position.
    if (activeAlias.value.rootless) return 'rootless';
    return aliasInversionSlotFrontend(activeAlias.value.interval_labels);
});
const activeInversionLabel = computed(() => {
    if (!activeAlias.value) return props.chord.inversion_label;
    return ALIAS_SLOT_LABEL[activeSelfInversion.value] ?? props.chord.inversion_label;
});

const INV_SORT: Record<string, number> = { root: 0, inv1: 1, inv2: 2, inv3: 3, rootless: 0 };

const allInversions = computed(() => {
    if (!activeInversions.value.length) return [];
    // Diminished pages generate the FULL inversion/alias lists server-side (the
    // current shape is already one of the four slots), so we must NOT synthesize
    // a "self" entry — doing so duplicates root position (dim7) or adds a bogus
    // Root Position alongside the Rootless slot (dom7b9).
    if (isDiminished.value) {
        return [...activeInversions.value]
            .sort((a, b) => (INV_SORT[a.inversion] ?? 99) - (INV_SORT[b.inversion] ?? 99));
    }
    // Build a synthetic "self" entry so the current diagram appears in the
    // list at its alias-derived slot when an alias is active.
    const selfEntry: ChordDiagramData = activeAlias.value
        ? {
            ...props.chord,
            root_note: activeAlias.value.root_note,
            quality: activeAlias.value.quality,
            extensions: activeAlias.value.extensions,
            bass_note: activeAlias.value.bass_note ?? '',
            interval_labels: activeAlias.value.interval_labels ?? props.chord.interval_labels,
            notes: activeAlias.value.notes ?? props.chord.notes,
            inversion: activeSelfInversion.value,
            inversion_label: activeInversionLabel.value,
        }
        : props.chord;
    return [...activeInversions.value, selfEntry]
        .sort((a, b) => (INV_ORDER[a.inversion] ?? 99) - (INV_ORDER[b.inversion] ?? 99));
});

// Which inversion-row entry represents the chord currently shown in the hero.
// Non-dim pages: the entry whose diagram id matches (distinct diagrams per slot).
// Dim pages: every generated slot shares the same diagram id (one shape), so we
// match by inversion slot instead — and these entries aren't separate diagrams,
// so they're never navigable links.
function inversionIsCurrent(inv: ChordDiagramData): boolean {
    if (isDiminished.value) return inv.inversion === activeSelfInversion.value;
    return inv.id === props.chord.id;
}

const DROP2_CATEGORIES = new Set(['drop2', 'drop3']);
const TRIAD_CATEGORIES = new Set(['closed_triads', 'spread_triads', 'archetype', 'closed']);
const voicingWidget = computed(() => {
    const cat = props.chord.voicing_category;
    if (DROP2_CATEGORIES.has(cat)) return 'drop2';
    if (TRIAD_CATEGORIES.has(cat)) return 'triad';
    return null;
});

const QUALITY_WIDGET_MAP: Record<string, string> = {
    maj7: 'drop2-visualizer', maj6: 'drop2-visualizer',
    m7: 'drop2-visualizer', mMaj7: 'drop2-visualizer', m6: 'drop2-visualizer',
    dom7: 'drop2-visualizer', aug7: 'drop2-visualizer', m7b5: 'drop2-visualizer',
    o7: 'drop2-visualizer',
    maj: 'triad-builder', min: 'triad-builder', aug: 'triad-builder', dim: 'triad-builder',
};
const activeQualityWidget = computed(() => QUALITY_WIDGET_MAP[activeQuality.value] ?? null);

const soundingNotes = computed(() => {
    const notes = displayChord.value.notes;
    if (!notes) return [];
    const seen = new Set<string>();
    const result: string[] = [];
    for (const n of notes.split(',')) {
        const t = n.trim();
        if (t && t !== 'x' && t !== 'X' && !seen.has(t)) { seen.add(t); result.push(t); }
    }
    return result;
});

// Canonical note-name formatter. Double accidentals first (bb→𝄫, ##→𝄪) so the
// single-accidental pass doesn't split them into two glyphs. Used for roots and
// bass notes, where a doubled accidental can occur (e.g. strict dim7 spelling).
function formatNote(n: string): string {
    return n
        .replace(/bb/g, '𝄫').replace(/##/g, '𝄪')
        .replace(/#/g, '♯').replace(/b/g, '♭');
}

const qualityMap: Record<string, [string, string]> = {
    'maj': ['', ''], 'min': ['m', ''], 'aug': ['aug', ''], 'dim': ['°', ''],
    '5': ['', '5'], 'sus4': ['sus', '4'], 'sus2': ['sus', '2'], 'add9': ['', 'add9'],
    'maj7': ['maj', '7'], 'm7': ['m', '7'], 'dom7': ['', '7'], 'm7b5': ['m', '7♭5'],
    'o7': ['°', '7'], 'maj6': ['maj', '6'], 'm6': ['m', '6'],
    'mMaj7': ['m', 'maj7'], 'aug7': ['aug', '7'], '7sus4': ['', '7sus4'],
};

function formatAliasName(alias: ChordAlias): string {
    const root = formatNote(alias.root_note);
    const [qual, core] = qualityMap[alias.quality] ?? ['', alias.quality];
    const ext = alias.extensions.replace(/#/g, '♯').replace(/b(?=[0-9])/g, '♭');
    const bass = formatNote(alias.bass_note ?? '');
    let html = '<span class="sbn-chord-symbol">';
    html += `<span class="sbn-chord-root">${root}</span>`;
    if (qual) html += `<span class="sbn-chord-quality">${qual}</span>`;
    if (core) html += `<span class="sbn-chord-ext">${core}</span>`;
    if (ext)  html += `<span class="sbn-chord-ext sbn-chord-ext--extra">(${ext})</span>`;
    if (bass) html += `<span class="sbn-chord-bass">/${bass}</span>`;
    html += '</span>';
    return html;
}

const formattedChordName = computed(() => {
    const root = formatNote(props.chord.root_note ?? '');
    const [qual, core] = qualityMap[props.chord.quality] ?? ['', props.chord.quality];
    const ext = (props.chord.extensions ?? '').replace(/#/g, '♯').replace(/b(?=[0-9])/g, '♭');
    const bass = formatNote(props.chord.bass_note ?? '');
    let html = '<span class="sbn-chord-symbol">';
    if (root) html += `<span class="sbn-chord-root">${root}</span>`;
    if (qual) html += `<span class="sbn-chord-quality">${qual}</span>`;
    if (core) html += `<span class="sbn-chord-ext">${core}</span>`;
    if (ext)  html += `<span class="sbn-chord-ext sbn-chord-ext--extra">(${ext})</span>`;
    if (bass) html += `<span class="sbn-chord-bass">/${bass}</span>`;
    html += '</span>';
    return html;
});

</script>

<template>
    <div class="sbn-page-detail sbn-chord-detail">

        <Breadcrumb :segments="[{ label: 'Chord Library', href: '/library/chords' }, { label: chord.name }]" />

        <!-- ════ IDENTITY PANEL ════ -->
        <div class="sbn-chord-identity sbn-detail-hero" :style="{ '--category-color': 'var(--clr-mod-chord)' }">

            <!-- Left: diagram + intervals + tension -->
            <div class="sbn-chord-identity-left">

                <div class="sbn-chord-identity-diagram">
                    <ChordCard :chord="displayChord" :showRoot="true" :detail="true" />
                </div>

                <!-- Chord tone names -->
                <div v-if="soundingNotes.length" class="sbn-chord-identity-intervals-row">
                    <span v-for="n in soundingNotes" :key="n" class="sbn-iv">
                        {{ formatNote(n) }}
                    </span>
                </div>

            </div><!-- .sbn-chord-identity-left -->

            <!-- Right: accordions -->
            <div class="sbn-chord-identity-right">

                <!-- Quality body — rendered only when it embeds an <sbn-widget>;
                     mountSbnNodes turns the tag into a live component. -->
                <div
                    v-if="showQualityBody"
                    ref="qualityBodyRef"
                    class="sbn-chord-identity-body"
                    v-html="qualityTopic!.body_html"
                ></div>

                <div class="sbn-accordion-group">

                    <!-- Voicing type row -->
                    <details v-if="eduV" class="sbn-accordion">
                        <summary class="sbn-accordion-summary">
                            <span class="sbn-accordion-icon sbn-accordion-icon--voicing">{{ chord.voicing_category.slice(0,2).toUpperCase() }}</span>
                            <span class="sbn-accordion-label">Voicing type</span>
                            <span class="sbn-accordion-value">{{ eduV.name }}</span>
                            <span class="sbn-accordion-chevron">›</span>
                        </summary>
                        <div class="sbn-accordion-body">
                            <p>{{ eduV.detail }}</p>
                            <p class="sbn-accordion-tip">{{ eduV.tip }}</p>
                            <Link v-if="voicingWidget === 'drop2'" href="/theory?widget=drop2-visualizer" class="sbn-accordion-explore">Explore Drop 2 &amp; Drop 3 →</Link>
                            <Link v-else-if="voicingWidget === 'triad'" href="/theory?widget=triad-builder" class="sbn-accordion-explore">Explore triad voicings →</Link>
                        </div>
                    </details>

                    <!-- Extensions row -->
                    <details v-if="activeExtensions" class="sbn-accordion">
                        <summary class="sbn-accordion-summary">
                            <span class="sbn-accordion-icon sbn-accordion-icon--ext">♭♯</span>
                            <span class="sbn-accordion-label">Extensions</span>
                            <span class="sbn-accordion-value">{{ activeExtensions }}</span>
                            <span class="sbn-accordion-chevron">›</span>
                        </summary>
                        <div class="sbn-accordion-body">
                            <p>This voicing adds <strong>{{ activeExtensions }}</strong> on top of the base {{ activeQuality }} quality, enriching the colour without changing the chord's harmonic function.</p>
                        </div>
                    </details>

                    <!-- Inversion row -->
                    <details v-if="eduInv || activeInversions.length" class="sbn-accordion">
                        <summary class="sbn-accordion-summary">
                            <span class="sbn-accordion-icon sbn-accordion-icon--inv">inv</span>
                            <span class="sbn-accordion-label">Inversion</span>
                            <span class="sbn-accordion-value">{{ activeInversionLabel }}</span>
                            <span class="sbn-accordion-chevron">›</span>
                        </summary>
                        <div class="sbn-accordion-body">
                            <!-- dim7: explain why the four inversions are the same shape -->
                            <p v-if="isDiminished && !activeIsRootless">
                                A diminished 7th is built from four stacked minor thirds, so it’s
                                perfectly symmetric: move the shape up three frets and you land on
                                the same four notes with a new name. These “inversions” are really
                                the one shape re-rooted — <span v-html="formattedActiveName" /> is
                                also each of its own inversions.
                            </p>
                            <p v-else-if="isDiminished && activeIsRootless" class="sbn-inversion-rootless-note">
                                Because this is a rootless voicing, there’s no true root position —
                                the slot where the root would sit is marked <strong>Rootless</strong>.
                                The other positions place the 3rd, 5th and ♭7 in the bass.
                            </p>
                            <template v-else>
                                <p v-if="eduInv">{{ eduInv.desc }}</p>
                                <p v-if="eduInv?.context" class="sbn-accordion-tip">{{ eduInv.context }}</p>
                            </template>
                            <div v-if="allInversions.length" class="sbn-inversion-siblings">
                                <p class="sbn-inversion-heading">All inversions of the <span v-html="formattedActiveName" /> chord</p>
                                <div class="sbn-inversion-cards">
                                    <component
                                        :is="inversionIsCurrent(inv) ? 'span' : Link"
                                        v-for="inv in allInversions"
                                        :key="`${inv.id}-${inv.inversion}`"
                                        :href="inversionIsCurrent(inv) ? undefined : `/library/chords/${inv.slug}`"
                                        class="sbn-inversion-sibling"
                                        :class="{ 'sbn-inversion-sibling--current': inversionIsCurrent(inv) }"
                                    >
                                        <ChordCard :chord="inv" mini :showRoot="true" :noNav="true" />
                                        <span class="sbn-inversion-sibling-label">{{ inv.inversion_label }}</span>
                                    </component>
                                </div>
                            </div>
                        </div>
                    </details>

                </div>

                <!-- Alias blurb + switcher — below accordions -->
                <div v-if="aliases.length" class="sbn-alias-block">
                    <!-- dim7: user searched a dom7(b9) → reveal it's a pure dim7 shape -->
                    <template v-if="arrivedViaDominant">
                        <p class="sbn-alias-blurb">
                            The dominant you searched is voiced as a pure <strong>diminished 7th</strong>:
                            drop the root of a 7(♭9) and the remaining 3rd, 5th, ♭7 and ♭9 spell a dim7.
                            Because that dim7 is symmetric, this one grip covers four different
                            7(♭9) chords — switch between them below, or see it as the dim7 under
                            “This voicing”.
                        </p>
                    </template>
                    <!-- dim7: user searched the diminished chord → reveal its dominant uses -->
                    <template v-else-if="isDiminished">
                        <p class="sbn-alias-blurb">
                            This shape also voices four rootless <strong>dominant 7(♭9)</strong> chords,
                            each rooted a semitone below one of its notes. It’s the favourite
                            harmonic device of guitarists from Django Reinhardt to João Gilberto.
                        </p>
                    </template>
                    <template v-else>
                        <p class="sbn-alias-blurb">
                            This voicing can be interpreted as different chords depending on context.
                            <Link v-if="activeQualityWidget" :href="`/theory?widget=${activeQualityWidget}`" class="sbn-alias-blurb-link">Learn more about alias voicings →</Link>
                        </p>
                    </template>
                </div>
                <div v-if="aliases.length" class="sbn-alias-switcher">
                    <span v-if="isDiminished" class="sbn-alias-group-label">This voicing</span>
                    <button
                        class="sbn-alias-btn"
                        :class="{ active: activeAliasIdx === -1 }"
                        @click="activeAliasIdx = -1"
                    ><span v-html="formattedChordName" /></button>
                    <span v-if="isDiminished" class="sbn-alias-group-label">Also reads as</span>
                    <button
                        v-for="(alias, i) in aliases"
                        :key="i"
                        class="sbn-alias-btn"
                        :class="{ active: activeAliasIdx === i }"
                        @click="activeAliasIdx = i"
                    ><span v-html="formatAliasName(alias)" /></button>
                </div>

            </div><!-- .sbn-chord-identity-right -->
        </div><!-- .sbn-chord-identity -->

        <!-- ════ ABOUT ════ -->
        <div class="sbn-chord-about">
            <h2 class="sbn-chord-identity-about">
                About the <span v-html="formattedActiveName" /> chord
            </h2>
            <template v-if="activeAlias">
                <p v-if="activeIsRootless" class="sbn-chord-identity-description">
                    This is a <strong>rootless</strong> voicing — the
                    <span v-html="formatNote(activeAlias.root_note)" /> root isn’t played. The
                    diminished shape supplies the 3rd, 5th, ♭7 and ♭9, which is enough to imply the
                    dominant. That’s why the lowest note is never the root, and the slot where the
                    root would sit is labelled “Rootless” rather than “Root Position”.
                </p>
                <p class="sbn-chord-identity-description">
                    {{ activeTheory?.typical_context ?? eduQ?.description ?? '' }}
                </p>
            </template>
            <template v-else-if="eduQ">
                <p class="sbn-chord-identity-description">
                    {{ eduQ.description }}
                    <span class="sbn-chord-identity-usage">{{ eduQ.usage }}</span>
                </p>
            </template>
            <template v-else-if="theory">
                <p class="sbn-chord-identity-description">{{ theory.typical_context }}</p>
            </template>
        </div>

        <!-- ════ PROGRESSIONS + SIBLINGS ════ -->
        <div v-if="progressions.length || siblings.length" class="sbn-chord-detail-lower">

            <div v-if="siblings.length" class="sbn-chord-detail-section">
                <div class="sbn-section-heading-row">
                    <h2 class="sbn-section-heading">Other <span class="sbn-chord-quality-label">{{ chord.quality_label }}</span> Voicings</h2>
                </div>
                <div class="sbn-card-scroll-wrap sbn-chord-siblings-wrap">
                    <div ref="siblingsScrollEl" class="sbn-card-scroll">
                        <Link
                            v-for="sib in siblings"
                            :key="sib.id"
                            :href="`/library/chords/${sib.slug}`"
                            class="sbn-card-scroll-item"
                        >
                            <ChordCard :chord="sib" mini :showRoot="true" :noNav="true" />
                        </Link>
                    </div>
                    <button v-show="siblingsCanLeft"  class="sbn-card-scroll-btn sbn-card-scroll-btn--prev" @click="scrollSiblings(-1)" aria-label="Scroll left">‹</button>
                    <button v-show="siblingsCanRight" class="sbn-card-scroll-btn sbn-card-scroll-btn--next" @click="scrollSiblings(1)"  aria-label="Scroll right">›</button>
                </div>
            </div>

            <div v-if="progressions.length" class="sbn-chord-detail-section">
                <div class="sbn-section-heading-row">
                    <h2 class="sbn-section-heading">Progressions with <span v-html="formattedChordName" class="sbn-chord-detail-heading-chord" /></h2>
                    <Link href="/library/progressions" class="sbn-section-link">View all →</Link>
                </div>
                <ul class="sbn-chord-detail-progressions">
                    <li v-for="prog in progressions.slice(0, 2)" :key="prog.id">
                        <ProgressionLink :progression="{ ...prog, pinnedChordSlug: chord.slug, pinnedChordRoot: displayChord.root_note }" />
                    </li>
                </ul>
            </div>

        </div>

        <!-- ════ SONGS + COURSES ════ -->
        <div v-if="songs.length || (courses && courses.length)" class="sbn-chord-detail-lower">

            <div v-if="songs.length" class="sbn-chord-detail-section">
                <MediaShelf title="Songs" view-all-href="/library/songs">
                    <SongShelfCard v-for="song in songs" :key="song.id" :song="song" />
                </MediaShelf>
            </div>

            <div v-if="courses && courses.length" class="sbn-chord-detail-section">
                <MediaShelf title="Related Courses" view-all-href="/learn">
                    <CourseShelfCard v-for="course in courses" :key="course.id" :course="course" />
                </MediaShelf>
            </div>

        </div>

    </div>
</template>

<style scoped>



/* ── About section (below hero) ── */
.sbn-chord-about {
    margin-bottom: 40px;
}

/* ── Identity panel ── */
.sbn-chord-identity {
    display: grid;
    grid-template-columns: 220px 1fr;
    gap: 48px;
    align-items: start;
    margin-bottom: 56px;
    padding: 28px;
}

/* Left column */
.sbn-chord-identity-left {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 16px;
    position: sticky;
    top: 90px;
}

/* Alias block — blurb + switcher */
.sbn-alias-block {
    margin-top: 20px;
    margin-bottom: 12px;
}
.sbn-alias-blurb {
    font-size: 0.85em;
    color: var(--clr-text-muted);
    margin: 0;
    line-height: 1.5;
}
.sbn-alias-blurb-link {
    display: inline;
    font-weight: 600;
    color: var(--clr-mod-chord);
    text-decoration: none;
    margin-left: 4px;
}
.sbn-alias-blurb-link:hover { text-decoration: underline; }

/* Alias switcher — below description */
.sbn-alias-switcher {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin: 0 0 20px;
}
.sbn-alias-btn {
    font-family: var(--font-chord, 'Crimson Text', Georgia, serif);
    font-size: 15px;
    line-height: 1.3;
    padding: 5px 14px;
    border-radius: 6px;
    border: 1.5px solid var(--clr-border);
    background: var(--clr-surface-2);
    color: var(--clr-text);
    cursor: pointer;
    transition: all 0.15s ease;
    white-space: nowrap;
}
.sbn-alias-btn:hover {
    border-color: var(--clr-mod-chord);
    background: var(--clr-white);
}
.sbn-alias-btn.active {
    border-color: var(--clr-mod-chord);
    background: var(--clr-white);
    box-shadow: 0 0 0 2px color-mix(in srgb, var(--clr-mod-chord) 20%, transparent);
}
.sbn-alias-btn :deep(.sbn-chord-root)    { font-weight: 700; }
.sbn-alias-btn :deep(.sbn-chord-quality) { font-size: 0.78em; }
.sbn-alias-btn :deep(.sbn-chord-ext)     { font-size: 0.72em; font-weight: 600; vertical-align: super; line-height: 0; }
.sbn-alias-btn :deep(.sbn-chord-bass)    { font-size: 0.85em; color: var(--clr-text-muted); }

/* Grouping labels in the dim7 switcher: separate the primary ("This voicing")
   from its alternate readings ("Also reads as"). */
.sbn-alias-group-label {
    align-self: center;
    font-size: 0.72em;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--clr-text-muted);
}
.sbn-alias-group-label:not(:first-child) { margin-left: 6px; }

.sbn-chord-identity-diagram {
    width: 100%;
}

/* Subtle shadow on diagram lines in the hero */
.sbn-chord-identity-diagram :deep(.chord-diagram-svg svg) {
    filter: drop-shadow(0 1px 2px rgba(15, 17, 23, 0.18));
}

/* Stronger shadow on dots only */
.sbn-chord-identity-diagram :deep(.sbn-svg-dot) {
    filter: drop-shadow(0 1px 2px rgba(15, 17, 23, 0.15));
}

/* Hero card lift */
.sbn-chord-identity-diagram :deep(.sbn-chord-card) {
    border-radius: 18px;
    border-top: 3px solid var(--clr-mod-chord);
    box-shadow:
        0 12px 40px -8px rgba(15, 17, 23, 0.18),
        0 4px 12px rgba(15, 17, 23, 0.06);
    padding: 24px 20px 16px;
    transition: box-shadow 0.2s ease;
}

.sbn-chord-identity-diagram :deep(.sbn-chord-card--detail:hover) {
    border-color: var(--clr-mod-chord);
    box-shadow:
        0 16px 48px -8px rgba(15, 17, 23, 0.22),
        0 6px 16px rgba(15, 17, 23, 0.08);
}

/* Bigger chord name in hero */
.sbn-chord-identity-diagram :deep(.sbn-card-chord-name .sbn-chord-symbol) {
    font-size: 2em;
    letter-spacing: -0.01em;
}

.sbn-chord-identity-diagram :deep(.sbn-card-chord-name .sbn-chord-quality) {
    font-size: 0.72em;
}

.sbn-chord-identity-diagram :deep(.sbn-card-chord-name .sbn-chord-ext) {
    font-size: 0.62em;
}

/* Interval dots row */
.sbn-chord-identity-intervals-row {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    justify-content: center;
}

.sbn-iv {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    border-radius: 50%;
    font-size: 0.82em;
    font-weight: 800;
    background: var(--clr-red);
    color: #fff;
    border: none;
    box-shadow: 0 1px 2px rgba(15, 17, 23, 0.15);
    letter-spacing: -0.02em;
}


/* Right column */
.sbn-chord-identity-right {
    padding-top: 4px;
    display: flex;
    flex-direction: column;
    gap: 0;
}

.sbn-chord-identity-about {
    font-size: 1.35em;
    font-weight: 700;
    color: var(--clr-text);
    margin: 0 0 14px;
}

.sbn-chord-identity-description {
    font-size: 0.95em;
    line-height: 1.65;
    color: var(--clr-text);
    margin: 0 0 20px;
}

.sbn-chord-identity-usage {
    display: block;
    margin-top: 8px;
    color: var(--clr-text-muted);
    font-size: 0.93em;
}

/* Accordions — iOS settings rows */
.sbn-accordion-group {
    margin-top: 16px;
    border: 1px solid var(--clr-border);
    border-radius: var(--radius);
    overflow: hidden;
}
.sbn-accordion {
    border-bottom: 1px solid var(--clr-border);
}
.sbn-accordion:last-child { border-bottom: none; }
.sbn-accordion-summary {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 11px 14px;
    cursor: pointer;
    list-style: none;
    user-select: none;
    transition: background 0.12s;
}
.sbn-accordion-summary::-webkit-details-marker { display: none; }
.sbn-accordion-summary:hover { background: var(--clr-surface-2); }
.sbn-accordion[open] > .sbn-accordion-summary { background: var(--clr-surface-2); }

.sbn-accordion-icon {
    flex-shrink: 0;
    width: 30px;
    height: 30px;
    border-radius: 7px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: -0.02em;
    color: #fff;
}
.sbn-accordion-icon--voicing { background: var(--clr-mod-chord); }
.sbn-accordion-icon--ext     { background: var(--clr-mod-progression); font-size: 12px; }
.sbn-accordion-icon--inv     { background: var(--clr-primary); font-size: 9px; text-transform: uppercase; }

.sbn-accordion-label {
    flex: 1;
    font-size: 0.9em;
    font-weight: 500;
    color: var(--clr-text);
}
.sbn-accordion-value {
    font-size: 0.85em;
    color: var(--clr-text-muted);
    margin-right: 4px;
}
.sbn-accordion-chevron {
    font-size: 1.1em;
    color: var(--clr-text-muted);
    transition: transform 0.2s;
    line-height: 1;
}
.sbn-accordion[open] > .sbn-accordion-summary .sbn-accordion-chevron { transform: rotate(90deg); }

.sbn-accordion-body {
    padding: 2px 14px 14px 56px;
    font-size: 0.88em;
    line-height: 1.6;
    color: var(--clr-text);
    border-top: 1px solid var(--clr-border-dim);
    background: var(--clr-surface-2);
}
.sbn-accordion-body p { margin: 8px 0 0; }
.sbn-accordion-tip { color: var(--clr-text-muted); font-style: italic; }
.sbn-accordion-explore {
    display: inline-block;
    margin-top: 10px;
    font-size: 0.85em;
    font-weight: 600;
    color: var(--clr-mod-chord);
    text-decoration: none;
}
.sbn-accordion-explore:hover { text-decoration: underline; }

/* Inversion sibling cards */
.sbn-inversion-siblings {
    margin-top: 12px;
}

.sbn-inversion-heading {
    font-size: 0.8em;
    font-weight: 600;
    color: var(--clr-text-muted);
    margin: 0 0 10px;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}

.sbn-inversion-heading :deep(.sbn-chord-symbol) {
    font-family: var(--font-chord, 'Crimson Text', Georgia, serif);
    font-size: 1.1em;
    font-weight: 600;
    text-transform: none;
    letter-spacing: 0;
}

.sbn-inversion-cards {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
}

.sbn-inversion-sibling {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 4px;
    text-decoration: none;
    color: inherit;
}

.sbn-inversion-sibling :deep(.sbn-chord-card) {
    width: 90px;
    padding: 8px 8px 6px;
}

.sbn-inversion-sibling :deep(.sbn-card-chord-name .sbn-chord-symbol) {
    font-size: 0.85em;
}

.sbn-inversion-sibling--current :deep(.sbn-chord-card) {
    border-color: var(--clr-primary);
    box-shadow: 0 0 0 2px color-mix(in srgb, var(--clr-primary) 20%, transparent);
}

.sbn-inversion-sibling-label {
    font-size: 0.75em;
    color: var(--clr-text-muted);
    text-align: center;
}

.sbn-inversion-sibling--current .sbn-inversion-sibling-label {
    color: var(--clr-primary);
    font-weight: 600;
}

/* ── Section wrapper ── */
.sbn-chord-detail-section {
    margin-bottom: 56px;
}


.sbn-chord-quality-label {
    font-family: var(--font-chord, 'Crimson Text', Georgia, serif);
    font-size: 1.15em;
    font-weight: 600;
}

.sbn-chord-detail-heading-chord :deep(.sbn-chord-symbol) {
    font-family: var(--font-chord, 'Crimson Text', Georgia, serif);
    font-size: 1.15em;
    font-weight: 400;
}
.sbn-chord-detail-heading-chord :deep(.sbn-chord-root)    { font-weight: 700; }
.sbn-chord-detail-heading-chord :deep(.sbn-chord-quality) { font-size: 0.82em; }
.sbn-chord-detail-heading-chord :deep(.sbn-chord-ext)     { font-size: 0.72em; font-weight: 600; vertical-align: super; line-height: 0; }

/* ── Lower two-column layout ── */
.sbn-chord-detail-lower {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 32px;
    align-items: start;
}

.sbn-chord-detail-lower .sbn-section-heading-row {
    min-height: 44px;
    align-items: center;
}
@media (max-width: 720px) {
    .sbn-chord-detail-lower { grid-template-columns: 1fr; }
}

/* ── Progressions ── */
.sbn-chord-detail-progressions {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 6px;
}

/* ── Sibling voicings ── */
.sbn-chord-siblings-wrap {
    max-width: calc(4 * 110px + 3 * 12px);
}


/* ── Responsive ── */
@media (max-width: 720px) {
    .sbn-chord-identity {
        grid-template-columns: 1fr;
    }
    .sbn-chord-identity-left {
        position: static;
    }
}
</style>
