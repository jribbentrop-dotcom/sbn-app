<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import Breadcrumb from '@/Components/Breadcrumb.vue';
import { mountSbnNodes } from '@/lib/mountSbnNodes';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import ChordCard from '@/Components/Library/ChordCard.vue';
import SongLink from '@/Components/Library/SongLink.vue';
import type { SongLinkData } from '@/Components/Library/SongLink.vue';
import type { ChordDiagramData } from '@/Components/Library/ChordDiagram.vue';
import { getCategoryColor } from '@/composables/useCategoryColors';

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
}

interface Props {
    chord: ChordDiagramData;
    aliases: ChordAlias[];
    siblings: ChordDiagramData[];
    songs: SongLinkData[];
    progressions: ProgressionRef[];
    qualityTopic?: QualityTopic | null;
}

const props = defineProps<Props>();
defineOptions({ layout: PublicLayout });

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
onBeforeUnmount(() => { unmountQualityBody?.(); unmountQualityBody = null; });

// ── Alias switcher ────────────────────────────────────────────────────────────
// -1 = primary chord; 0..n = alias index
const activeAliasIdx = ref(-1);
const activeAlias = computed(() => activeAliasIdx.value >= 0 ? props.aliases[activeAliasIdx.value] : null);

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
    const root = (activeAlias.value?.root_note ?? props.chord.root_note ?? '').replace(/#/g, '♯').replace(/b/g, '♭');
    const quality = activeAlias.value?.quality ?? props.chord.quality;
    const [qual, core] = qualityMap[quality] ?? ['', quality];
    const ext = (activeAlias.value?.extensions ?? props.chord.extensions ?? '').replace(/#/g, '♯').replace(/b(?=[0-9])/g, '♭');
    const bass = (activeAlias.value?.bass_note ?? props.chord.bass_note ?? '').replace(/#/g, '♯').replace(/b/g, '♭');
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

function formatNote(n: string): string {
    return n.replace(/#/g, '♯').replace(/b/g, '♭');
}

const qualityMap: Record<string, [string, string]> = {
    'maj': ['', ''], 'min': ['m', ''], 'aug': ['aug', ''], 'dim': ['°', ''],
    '5': ['', '5'], 'sus4': ['sus', '4'], 'sus2': ['sus', '2'], 'add9': ['', 'add9'],
    'maj7': ['maj', '7'], 'm7': ['m', '7'], 'dom7': ['', '7'], 'm7b5': ['m', '7♭5'],
    'o7': ['°', '7'], 'maj6': ['maj', '6'], 'm6': ['m', '6'],
    'mMaj7': ['m', 'maj7'], 'aug7': ['aug', '7'], '7sus4': ['', '7sus4'],
};

function formatAliasName(alias: ChordAlias): string {
    const root = alias.root_note.replace(/#/g, '♯').replace(/b/g, '♭');
    const [qual, core] = qualityMap[alias.quality] ?? ['', alias.quality];
    const ext = alias.extensions.replace(/#/g, '♯').replace(/b(?=[0-9])/g, '♭');
    const bass = (alias.bass_note ?? '').replace(/#/g, '♯').replace(/b/g, '♭');
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
    const root = (props.chord.root_note ?? '').replace(/#/g, '♯').replace(/b/g, '♭');
    const [qual, core] = qualityMap[props.chord.quality] ?? ['', props.chord.quality];
    const ext = (props.chord.extensions ?? '').replace(/#/g, '♯').replace(/b(?=[0-9])/g, '♭');
    const bass = (props.chord.bass_note ?? '').replace(/#/g, '♯').replace(/b/g, '♭');
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
        <div class="sbn-chord-identity sbn-detail-hero">

            <!-- Left: diagram + intervals + tension -->
            <div class="sbn-chord-identity-left">

                <div class="sbn-chord-identity-diagram">
                    <ChordCard :chord="displayChord" :showRoot="true" :detail="true" />
                </div>

                <!-- Chord tone names -->
                <div v-if="soundingNotes.length" class="sbn-chord-identity-intervals-row">
                    <span
                        v-for="n in soundingNotes"
                        :key="n"
                        class="sbn-iv"
                    >{{ formatNote(n) }}</span>
                </div>

            </div><!-- .sbn-chord-identity-left -->

            <!-- Right: about + accordions -->
            <div class="sbn-chord-identity-right">

                <h2 class="sbn-chord-identity-about">
                    About the <span v-html="formattedActiveName" /> chord
                </h2>

                <template v-if="activeAlias">
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

                <!-- Alias switcher — shown below description when aliases exist -->
                <div v-if="aliases.length" class="sbn-alias-block">
                    <p class="sbn-alias-blurb">
                        This voicing can be interpreted as different chords depending on context.
                        <Link v-if="activeQualityWidget" :href="`/theory?widget=${activeQualityWidget}`" class="sbn-alias-blurb-link">Learn more about alias voicings →</Link>
                    </p>
                </div>
                <div v-if="aliases.length" class="sbn-alias-switcher">
                    <button
                        class="sbn-alias-btn"
                        :class="{ active: activeAliasIdx === -1 }"
                        @click="activeAliasIdx = -1"
                    ><span v-html="formattedChordName" /></button>
                    <button
                        v-for="(alias, i) in aliases"
                        :key="i"
                        class="sbn-alias-btn"
                        :class="{ active: activeAliasIdx === i }"
                        @click="activeAliasIdx = i"
                    ><span v-html="formatAliasName(alias)" /></button>
                </div>

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
                    <details v-if="eduInv" class="sbn-accordion">
                        <summary class="sbn-accordion-summary">
                            <span class="sbn-accordion-icon sbn-accordion-icon--inv">inv</span>
                            <span class="sbn-accordion-label">Inversion</span>
                            <span class="sbn-accordion-value">{{ chord.inversion_label }}</span>
                            <span class="sbn-accordion-chevron">›</span>
                        </summary>
                        <div class="sbn-accordion-body">
                            <p>{{ eduInv.desc }}</p>
                            <p v-if="eduInv.context" class="sbn-accordion-tip">{{ eduInv.context }}</p>
                        </div>
                    </details>

                </div>

            </div><!-- .sbn-chord-identity-right -->
        </div><!-- .sbn-chord-identity -->

        <div class="sbn-chord-detail-lower">

            <!-- ════ PROGRESSIONS ════ -->
            <div v-if="progressions.length" class="sbn-chord-detail-section">
                <div class="sbn-chord-detail-section-heading-row">
                    <h2 class="sbn-chord-detail-section-heading">Progressions with <span v-html="formattedChordName" class="sbn-chord-detail-heading-chord" /></h2>
                    <Link href="/library/progressions" class="sbn-chord-detail-section-link">View all →</Link>
                </div>
                <ul class="sbn-chord-detail-progressions">
                    <li v-for="prog in progressions.slice(0, 4)" :key="prog.id">
                        <Link
                            :href="`/library/progressions/${prog.slug}?chord=${chord.slug}&highlight=${prog.pinnedSlot ?? 0}`"
                            class="sbn-chord-detail-prog-link"
                            :style="{ '--prog-color': getCategoryColor(prog.category) }"
                        >
                            <span class="sbn-chord-detail-prog-name">{{ prog.name }}</span>
                            <div class="sbn-numeral-chip-row">
                                <span
                                    v-for="n in prog.numeralsDisplay.split('–').map(s => s.trim()).filter(Boolean)"
                                    :key="n"
                                    class="sbn-numeral-chip"
                                >{{ n }}</span>
                            </div>
                        </Link>
                    </li>
                </ul>
            </div>

            <!-- ════ SONGS ════ -->
            <div v-if="songs.length" class="sbn-chord-detail-section">
                <div class="sbn-chord-detail-section-heading-row">
                    <h2 class="sbn-chord-detail-section-heading">Songs with <span v-html="formattedChordName" class="sbn-chord-detail-heading-chord" /></h2>
                    <Link href="/library/songs" class="sbn-chord-detail-section-link">View all →</Link>
                </div>
                <ul class="sbn-chord-detail-songs">
                    <li v-for="song in songs.slice(0, 4)" :key="song.id">
                        <SongLink :song="song" />
                    </li>
                </ul>
            </div>

        </div><!-- .sbn-chord-detail-lower -->

        <!-- ════ OTHER VOICINGS ════ -->
        <div v-if="siblings.length" class="sbn-chord-detail-section sbn-chord-detail-other-voicings">
            <h2 class="sbn-chord-detail-section-heading">Other {{ chord.quality_label }} Voicings</h2>
            <div class="sbn-chord-detail-siblings">
                <Link
                    v-for="sib in siblings"
                    :key="sib.id"
                    :href="`/library/chords/${sib.slug}`"
                    class="sbn-chord-detail-sibling-card"
                >
                    <ChordCard :chord="sib" :showRoot="true" />
                </Link>
            </div>
        </div>

    </div>
</template>

<style scoped>



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

.sbn-chord-identity-diagram {
    width: 100%;
}

/* Interval dots row */
.sbn-chord-identity-intervals-row {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    justify-content: center;
}

.sbn-iv {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 4px;
    font-size: 0.8em;
    font-weight: 600;
    background: var(--clr-white);
    border: 1px solid var(--clr-border);
    color: var(--clr-text);
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

/* ── Section wrapper ── */
.sbn-chord-detail-section {
    margin-bottom: 56px;
}

.sbn-chord-detail-other-voicings .sbn-chord-detail-siblings {
    overflow: visible;
}

.sbn-chord-detail-section-heading-row {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 16px;
    margin-bottom: 24px;
    padding-bottom: 12px;
    border-bottom: 2px solid var(--clr-border);
}

.sbn-chord-detail-section-heading {
    font-size: 1.1em;
    font-weight: 700;
    color: var(--clr-text);
    margin: 0;
}
.sbn-chord-detail-heading-chord :deep(.sbn-chord-symbol) {
    font-family: var(--font-chord, 'Crimson Text', Georgia, serif);
    font-size: 1.15em;
    font-weight: 400;
}
.sbn-chord-detail-heading-chord :deep(.sbn-chord-root)    { font-weight: 700; }
.sbn-chord-detail-heading-chord :deep(.sbn-chord-quality) { font-size: 0.82em; }
.sbn-chord-detail-heading-chord :deep(.sbn-chord-ext)     { font-size: 0.72em; font-weight: 600; vertical-align: super; line-height: 0; }

.sbn-chord-detail-section-link {
    font-size: 0.85em;
    font-weight: 500;
    color: var(--clr-text-muted);
    text-decoration: none;
    white-space: nowrap;
    transition: color 0.15s;
}
.sbn-chord-detail-section-link:hover { color: var(--clr-text); }

/* ── Lower two-column layout ── */
.sbn-chord-detail-lower {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 32px;
    align-items: start;
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
.sbn-chord-detail-prog-link {
    display: flex;
    flex-direction: column;
    gap: 3px;
    padding: 10px 14px;
    border-radius: var(--radius);
    border: 1px solid var(--clr-border);
    border-left: 3px solid var(--prog-color, var(--clr-accent));
    text-decoration: none;
    transition: background 0.15s ease;
}
.sbn-chord-detail-prog-link:hover {
    background: var(--clr-surface-2);
}
.sbn-chord-detail-prog-name {
    font-weight: 600;
    color: var(--clr-text);
    font-size: 14px;
}

/* ── Sibling voicings ── */
.sbn-chord-detail-siblings {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(120px, 140px));
    gap: 12px;
    padding-top: 4px;
    overflow: visible;
}


.sbn-chord-detail-sibling-card {
    text-decoration: none;
    display: block;
    border-radius: var(--radius);
    overflow: visible;
    transition: transform 0.15s;
}
.sbn-chord-detail-sibling-card:hover {
    transform: translateY(-2px);
}

/* ── Songs ── (rows rendered by SongLink.vue / sbn-design-system.css) */
.sbn-chord-detail-songs {
    list-style: none;
    padding: 0;
    margin: 0;
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.sbn-chord-detail-songs li :deep(.sbn-song-link) {
    border: 1px solid var(--clr-border);
    border-left: 3px solid var(--cat-clr, var(--clr-accent));
    border-radius: var(--radius);
    padding: 10px 14px;
}
.sbn-chord-detail-songs li :deep(.sbn-song-link:hover) {
    background: var(--clr-surface-2);
}
.sbn-chord-detail-songs li :deep(.sbn-song-link__img) {
    width: 44px;
    height: 44px;
    border-radius: 6px;
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
