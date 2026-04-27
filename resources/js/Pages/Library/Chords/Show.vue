<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import ChordDiagram from '@/Components/Library/ChordDiagram.vue';
import ChordCard from '@/Components/Library/ChordCard.vue';
import ChordProgressionViewer from '@/Components/Library/ChordProgressionViewer.vue';
import type { ChordDiagramData } from '@/Components/Library/ChordDiagram.vue';
import type { ProgressionChord } from '@/Components/Library/ChordProgressionViewer.vue';

interface ProgressionTile {
    chordName: string;
    diagramData: ChordDiagramData | null;
    slug?: string | null;
}

interface SongRef {
    id: number;
    slug: string;
    title: string;
    composer: string | null;
    songKey: string | null;
    rhythm: string | null;
}

interface ProgressionRef {
    id: number;
    slug: string;
    name: string;
    category: string;
    numeralsDisplay: string;
    tiles: ProgressionTile[];
}

interface Props {
    chord: ChordDiagramData;
    siblings: ChordDiagramData[];
    songs: SongRef[];
    progressions: ProgressionRef[];
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

// ── Edu data (mirrors legacy sbn_chord_detail_edu) ───────────────────────────
const qualityEdu: Record<string, { description: string; usage: string }> = {
    maj:   { description: 'The foundation of Western harmony. Three notes — root, major third, perfect fifth — producing a bright, stable, resolved sound.',                                                                                                                            usage: 'The home base. Appears as the I, IV, and V chord in major keys.' },
    min:   { description: 'Lower the third by a half step and the entire character shifts. The minor triad sounds darker, more introspective — but equally stable.',                                                                                                                    usage: 'The ii, iii, and vi chords in major keys. Dominates minor keys as the i chord.' },
    maj7:  { description: 'Add the major seventh to a major triad and you get a lush, sophisticated sound. The interval between root and seventh is just a half step shy of an octave, creating a gentle tension that floats rather than pushes.',                                      usage: 'The signature sound of bossa nova and smooth jazz. Functions as I and IV in major keys. Think Jobim, think "Girl from Ipanema."' },
    m7:    { description: 'The workhorse of jazz harmony. A minor triad plus a flatted seventh creates a mellow, warm sound that sits comfortably in almost any context.',                                                                                                              usage: 'The ii chord in major II-V-I progressions. The i chord in minor keys. Everywhere in jazz, R&B, neo-soul, and bossa nova.' },
    dom7:  { description: 'Major triad plus a flatted seventh. The tritone between the third and seventh creates tension that wants to resolve — this is the engine of harmonic motion.',                                                                                               usage: 'The V chord that pulls you home to I. The backbone of blues (I7, IV7, V7). In jazz, dominant chords appear everywhere.' },
    m7b5:  { description: 'A minor seventh chord with a flatted fifth. Less tense than fully diminished — the minor seventh softens the sound, giving it a melancholy, yearning quality.',                                                                                              usage: 'The ii chord in minor key ii-V-i progressions. Essential for minor jazz harmony.' },
    o7:    { description: 'Three stacked minor thirds dividing the octave into four equal parts. Symmetrical and mysterious — every note can function as the root.',                                                                                                                    usage: 'The classic dramatic chord. Used as a passing chord, a dominant substitute, or for chromatic voice leading.' },
    maj6:  { description: 'A major triad with an added sixth. Warmer and more relaxed than maj7 — less "pretty," more grounded.',                                                                                                                                                       usage: 'Common tonic chord in swing and early jazz. Often used instead of maj7 for a vintage feel. The classic "jazz ending" chord.' },
    m6:    { description: 'A minor triad with an added major sixth. The sixth adds a bittersweet brightness to the minor quality — tense but beautiful.',                                                                                                                               usage: 'Classic minor tonic chord in jazz. Often interchangeable with m7. Characteristic sound of gypsy jazz.' },
    mMaj7: { description: 'Minor triad plus a major seventh. The clash between the minor third and major seventh gives it a mysterious, cinematic quality.',                                                                                                                            usage: 'Im(maj7) — most famous as the first chord of a minor line cliché. Stunning in the right context.' },
    aug7:  { description: 'Major triad plus augmented fifth and minor seventh. The raised fifth adds an upward, striving quality to the dominant tension.',                                                                                                                             usage: 'V7#5, used when resolving to a major tonic. Common in jazz and gospel.' },
    aug:   { description: 'Two stacked major thirds. Like diminished, it\'s symmetrical — but where diminished contracts, augmented expands. An unsettled, reaching sound.',                                                                                                           usage: 'Often used as a passing chord or dominant variation (V+). Common in Beatles progressions and film scores.' },
    dim:   { description: 'Two stacked minor thirds create an unstable, tense sound. The diminished fifth (tritone from root) gives this chord its restless character.',                                                                                                                usage: 'Appears naturally as vii° in major keys. Often used as a passing chord between two more stable chords.' },
    sus4:  { description: 'The third is replaced by a perfect fourth, removing the major/minor identity. The result is open, ambiguous, and full of potential.',                                                                                                                        usage: 'Creates tension that typically resolves to a major or minor chord. Widely used in pop, rock, and gospel.' },
    sus2:  { description: 'The third is replaced by a major second. Even more open than sus4 — airy, modern, and neither major nor minor.',                                                                                                                                             usage: 'Common in pop and modern worship music. Creates space and ambiguity.' },
    add9:  { description: 'A major triad with an added ninth — no seventh. The ninth adds sparkle and openness without the sophistication of a full ninth chord.',                                                                                                                      usage: 'A staple of pop, folk, and acoustic guitar. Cadd9 (x32030) is one of the most iconic guitar shapes ever.' },
    '5':   { description: 'Just root and fifth — no third means no major or minor identity. Raw, ambiguous, and powerful under distortion.',                                                                                                                                            usage: 'The foundation of rock, punk, and metal guitar.' },
};

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
const theory   = computed(() => theoryMap[props.chord.quality]   ?? null);
const eduQ     = computed(() => qualityEdu[props.chord.quality]  ?? null);
const eduV     = computed(() => voicingEdu[props.chord.voicing_category] ?? null);
const eduInv   = computed(() => props.chord.inversion && props.chord.inversion !== 'root' ? (inversionEdu[props.chord.inversion] ?? null) : null);

const soundingIntervals = computed(() => {
    if (!props.chord.interval_labels) return [];
    const order: Record<string, number> = {
        R: 0, b2: 1, '2': 2, b3: 3, '3': 4, '4': 5, '#4': 6, b5: 6, '5': 7,
        '#5': 8, b6: 8, '6': 9, bb7: 9, b7: 10, '7': 11,
        b9: 1, '9': 2, '#9': 3, '11': 5, '#11': 6, b13: 8, '13': 9,
    };
    const seen = new Set<string>();
    const result: string[] = [];
    for (const iv of props.chord.interval_labels.split(',')) {
        const t = iv.trim();
        if (t && t !== 'x' && t !== 'X' && !seen.has(t)) { seen.add(t); result.push(t); }
    }
    result.sort((a, b) => (order[a] ?? 99) - (order[b] ?? 99));
    return result;
});

function formatInterval(iv: string): string {
    if (iv === 'R') return iv;
    return iv.replace(/bb/g, '♭♭').replace(/b/g, '♭').replace(/#/g, '♯');
}

const qualityMap: Record<string, [string, string]> = {
    'maj': ['', 'major'], 'min': ['m', ''], 'aug': ['aug', ''], 'dim': ['°', ''],
    '5': ['', '5'], 'sus4': ['sus', '4'], 'sus2': ['sus', '2'], 'add9': ['', 'add9'],
    'maj7': ['maj', '7'], 'm7': ['m', '7'], 'dom7': ['', '7'], 'm7b5': ['m', '7♭5'],
    'o7': ['°', '7'], 'maj6': ['maj', '6'], 'm6': ['m', '6'],
    'mMaj7': ['m', 'maj7'], 'aug7': ['aug', '7'], '7sus4': ['', '7sus4'],
};

const formattedChordName = computed(() => {
    const root = (props.chord.root_note ?? '').replace(/#/g, '♯').replace(/b/g, '♭');
    const [qual, core] = qualityMap[props.chord.quality] ?? ['', props.chord.quality];
    const ext = (props.chord.extensions ?? '').replace(/#/g, '♯').replace(/b(?=[0-9])/g, '♭');
    let html = '<span class="sbn-chord-symbol">';
    if (root) html += `<span class="sbn-chord-root">${root}</span>`;
    if (qual) html += `<span class="sbn-chord-quality">${qual}</span>`;
    if (core) html += `<span class="sbn-chord-ext">${core}</span>`;
    if (ext)  html += `<span class="sbn-chord-ext sbn-chord-ext--extra">(${ext})</span>`;
    html += '</span>';
    return html;
});

// Convert tiles to chords for the viewer
function getChords(prog: ProgressionRef): ProgressionChord[] {
    return prog.tiles.map((tile) => ({
        chordName: tile.chordName,
        diagramData: tile.diagramData,
        beats: 4,
        slug: tile.slug,
    }));
}
</script>

<template>
    <div class="sbn-chord-detail">

        <!-- Back Link -->
        <div style="margin-bottom: 24px;">
          <Link href="/library/chords" class="sbn-back-link">← Back to Chord Library</Link>
        </div>

        <!-- ════ IDENTITY PANEL ════ -->
        <div class="sbn-chord-identity">

            <!-- Left: diagram + intervals + tension -->
            <div class="sbn-chord-identity-left">

                <div class="sbn-chord-identity-diagram">
                    <ChordCard :chord="chord" :showRoot="true" />
                </div>

                <!-- Interval dots -->
                <div v-if="soundingIntervals.length" class="sbn-chord-identity-intervals-row">
                    <span
                        v-for="iv in soundingIntervals"
                        :key="iv"
                        :class="['sbn-iv', iv === 'R' ? 'sbn-iv-root' : '']"
                    >{{ formatInterval(iv) }}</span>
                </div>
                <div v-else-if="theory" class="sbn-chord-identity-intervals-row sbn-iv-fallback">
                    {{ theory.intervals }}
                </div>

                <!-- Tension meter -->
                <div v-if="theory" class="sbn-chord-detail-tension" title="Harmonic tension (0 = stable, 5 = maximum)">
                    <span class="sbn-chord-detail-tension-label">Tension</span>
                    <div class="sbn-chord-detail-tension-dots">
                        <span
                            v-for="i in 5"
                            :key="i"
                            :class="['sbn-tension-dot', i <= theory.tension ? 'filled' : '']"
                        />
                    </div>
                </div>

            </div><!-- .sbn-chord-identity-left -->

            <!-- Right: about + accordions -->
            <div class="sbn-chord-identity-right">

                <h2 class="sbn-chord-identity-about">
                    About the <span v-html="formattedChordName" /> chord
                </h2>

                <template v-if="eduQ">
                    <p class="sbn-chord-identity-description">
                        {{ eduQ.description }}
                        <span class="sbn-chord-identity-usage">{{ eduQ.usage }}</span>
                    </p>
                </template>
                <template v-else-if="theory">
                    <p class="sbn-chord-identity-description">{{ theory.typical_context }}</p>
                </template>

                <!-- Voicing type accordion -->
                <details v-if="eduV" class="sbn-accordion">
                    <summary class="sbn-accordion-summary">
                        <span class="sbn-accordion-badge">{{ eduV.name }}</span>
                        Voicing type
                    </summary>
                    <div class="sbn-accordion-body">
                        <p>{{ eduV.detail }}</p>
                        <p class="sbn-accordion-tip">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                            {{ eduV.tip }}
                        </p>
                    </div>
                </details>

                <!-- Inversion accordion -->
                <details v-if="eduInv" class="sbn-accordion">
                    <summary class="sbn-accordion-summary">
                        <span class="sbn-accordion-badge sbn-accordion-badge--inv">{{ chord.inversion_label }}</span>
                        Inversion
                    </summary>
                    <div class="sbn-accordion-body">
                        <p>{{ eduInv.desc }}</p>
                        <p v-if="eduInv.context" class="sbn-accordion-context">{{ eduInv.context }}</p>
                    </div>
                </details>

                <!-- Related chord types accordion -->
                <details v-if="theory && theory.related.length" class="sbn-accordion">
                    <summary class="sbn-accordion-summary">
                        <span class="sbn-accordion-badge sbn-accordion-badge--related">Related</span>
                        Related chord types
                    </summary>
                    <div class="sbn-accordion-body">
                        <div class="sbn-accordion-related-chips">
                            <Link
                                v-for="rel in theory.related"
                                :key="rel"
                                :href="`/library/chords?quality=${rel}`"
                                class="sbn-theory-related-chip"
                            >
                                <span v-html="rel" />
                            </Link>
                        </div>
                    </div>
                </details>

            </div><!-- .sbn-chord-identity-right -->
        </div><!-- .sbn-chord-identity -->

        <!-- ════ PROGRESSIONS ════ -->
        <div v-if="progressions.length" class="sbn-chord-detail-section">
            <h2 class="sbn-chord-detail-section-heading">Chord Progression Examples</h2>
            <div class="sbn-chord-detail-progressions">
                <div
                    v-for="prog in progressions"
                    :key="prog.id"
                    class="sbn-chord-detail-prog-item"
                >
                    <div class="sbn-chord-detail-prog-header">
                        <Link :href="`/library/progressions/${prog.slug}`" class="sbn-chord-detail-prog-name">
                            {{ prog.name }}
                        </Link>
                        <span class="sbn-chord-detail-prog-numerals">{{ prog.numeralsDisplay }}</span>
                    </div>
                    <ChordProgressionViewer
                        :chords="getChords(prog)"
                        :interactive="true"
                        :compact="true"
                        :show-flow-arrows="true"
                    />
                </div>
            </div>
        </div>

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

        <!-- ════ SONGS ════ -->
        <div v-if="songs.length" class="sbn-chord-detail-section">
            <h2 class="sbn-chord-detail-section-heading">Songs using this chord</h2>
            <ul class="sbn-chord-detail-songs">
                <li v-for="song in songs" :key="song.id" class="sbn-chord-detail-song-item">
                    <Link :href="`/library/songs/${song.slug}`" class="sbn-chord-detail-song-link">
                        {{ song.title }}
                    </Link>
                    <span v-if="song.composer" class="sbn-chord-detail-song-meta">{{ song.composer }}</span>
                    <span v-if="song.songKey" class="sbn-chord-detail-song-key">{{ song.songKey }}</span>
                </li>
            </ul>
        </div>

    </div>
</template>

<style scoped>
/* ── Page wrapper ── */
.sbn-chord-detail {
    max-width: 1100px;
    margin: 0 auto;
    padding: 24px 20px 80px;
}



/* ── Identity panel ── */
.sbn-chord-identity {
    display: grid;
    grid-template-columns: 220px 1fr;
    gap: 48px;
    align-items: start;
    margin-bottom: 56px;
    background: var(--clr-surface-2);
    border: 1px solid var(--clr-border);
    border-radius: var(--radius);
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

.sbn-iv-root {
    background: var(--clr-accent-bg);
    color: var(--clr-red);
}

.sbn-iv-fallback {
    font-size: 0.82em;
    color: var(--clr-text-muted);
    text-align: center;
}

/* Tension meter */
.sbn-chord-detail-tension {
    display: flex;
    align-items: center;
    gap: 8px;
}

.sbn-chord-detail-tension-label {
    font-size: 0.75em;
    font-weight: 500;
    color: var(--clr-text-muted);
    text-transform: uppercase;
    letter-spacing: 0.4px;
}

.sbn-chord-detail-tension-dots {
    display: flex;
    gap: 4px;
}

.sbn-tension-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: var(--clr-border);
}
.sbn-tension-dot.filled { background: var(--clr-red); }

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

/* Accordions */
.sbn-accordion {
    border-top: 1px solid var(--clr-border);
    padding: 0;
}
.sbn-accordion:last-child { border-bottom: 1px solid var(--clr-border); }

.sbn-accordion-summary {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 13px 0;
    cursor: pointer;
    font-size: 0.88em;
    font-weight: 500;
    color: var(--clr-text);
    list-style: none;
    user-select: none;
}
.sbn-accordion-summary::-webkit-details-marker { display: none; }
.sbn-accordion-summary::after {
    content: '›';
    margin-left: auto;
    font-size: 1.1em;
    color: var(--clr-text-muted);
    transition: transform 0.2s;
}
.sbn-accordion[open] > .sbn-accordion-summary::after { transform: rotate(90deg); }

.sbn-accordion-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 4px;
    font-size: 0.82em;
    font-weight: 600;
    background: var(--clr-white);
    border: 1px solid var(--clr-border);
    color: var(--clr-text-muted);
}
.sbn-accordion-badge--inv     { background: rgba(99,102,241,0.1); color: #6366f1; }
.sbn-accordion-badge--related { background: rgba(16,185,129,0.1);  color: #10b981; }

.sbn-accordion-body {
    padding: 0 0 16px;
    font-size: 0.88em;
    line-height: 1.6;
    color: var(--clr-text);
}
.sbn-accordion-body p { margin: 0 0 8px; }
.sbn-accordion-body p:last-child { margin: 0; }

.sbn-accordion-tip {
    display: flex;
    align-items: flex-start;
    gap: 6px;
    color: var(--clr-text-muted);
    font-style: italic;
}
.sbn-accordion-tip svg { flex-shrink: 0; margin-top: 2px; }

.sbn-accordion-context { color: var(--clr-text-muted); }

.sbn-accordion-related-chips {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    padding-top: 4px;
}

.sbn-theory-related-chip {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 12px;
    border: 1px solid var(--clr-border);
    border-radius: var(--radius-sm);
    font-size: 0.88em;
    font-weight: 600;
    font-family: var(--font-chord);
    color: var(--clr-text);
    text-decoration: none;
    transition: border-color 0.15s, color 0.15s;
}
.sbn-theory-related-chip:hover { border-color: var(--clr-red); color: var(--clr-red); }

/* ── Section wrapper ── */
.sbn-chord-detail-section {
    margin-bottom: 56px;
}

.sbn-chord-detail-other-voicings .sbn-chord-detail-siblings {
    overflow: visible;
}

.sbn-chord-detail-section-heading {
    font-size: 1.1em;
    font-weight: 700;
    color: var(--clr-text);
    margin: 0 0 24px;
    padding-bottom: 12px;
    border-bottom: 2px solid var(--clr-border);
}

/* ── Progressions ── */
.sbn-chord-detail-progressions {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.sbn-chord-detail-prog-item {
    background: var(--clr-white);
    border-radius: var(--radius);
    padding: 16px 20px;
}

.sbn-chord-detail-prog-header {
    display: flex;
    align-items: baseline;
    gap: 12px;
    margin-bottom: 12px;
}

.sbn-chord-detail-prog-name {
    font-weight: 600;
    font-size: 0.95em;
    color: var(--clr-text);
    text-decoration: none;
}
.sbn-chord-detail-prog-name:hover { color: var(--clr-red); }

.sbn-chord-detail-prog-numerals {
    font-size: 0.82em;
    color: var(--clr-text-muted);
    font-family: Georgia, serif;
}

/* ── Sibling voicings ── */
.sbn-chord-detail-siblings {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
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

/* ── Songs ── */
.sbn-chord-detail-songs {
    list-style: none;
    padding: 0;
    margin: 0;
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
}

.sbn-chord-detail-song-item {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 12px;
    border-bottom: 1px solid var(--clr-surface-2);
}

.sbn-chord-detail-song-link {
    font-weight: 500;
    font-size: 0.9em;
    color: var(--clr-text);
    text-decoration: none;
}
.sbn-chord-detail-song-link:hover { color: var(--clr-red); }

.sbn-chord-detail-song-meta {
    font-size: 0.8em;
    color: var(--clr-text-muted);
}

.sbn-chord-detail-song-key {
    font-size: 0.78em;
    font-weight: 600;
    color: var(--clr-style-bossa);
    background: color-mix(in srgb, var(--clr-style-bossa) 10%, white);
    padding: 1px 6px;
    border-radius: 4px;
    margin-left: auto;
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
