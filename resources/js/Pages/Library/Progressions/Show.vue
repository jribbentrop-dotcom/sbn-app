<script setup lang="ts">
import { computed } from 'vue';
import Breadcrumb from '@/Components/Breadcrumb.vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import ChordProgressionViewer from '@/Components/Library/ChordProgressionViewer.vue';
import ProgressionLink from '@/Components/Library/ProgressionLink.vue';
import MediaShelf from '@/Components/Library/MediaShelf.vue';
import SongShelfCard from '@/Components/Library/SongShelfCard.vue';
import CourseShelfCard from '@/Components/Course/CourseShelfCard.vue';
import type { CourseShelfCardData } from '@/Components/Course/CourseShelfCard.vue';
import type { ProgressionChord } from '@/Components/Library/ChordProgressionViewer.vue';
import { getCategoryColor } from '@/composables/useCategoryColors';

interface ProgressionTile {
    chordName: string;
    diagramData: ChordDiagramData | null;
    slug?: string | null;
    numeral?: string | null;
}

defineOptions({ layout: PublicLayout });

interface SongData {
    id: number;
    slug: string;
    title: string;
    styleSlug: string;
    coverImagePath: string | null;
    composer: string | null;
    popularity: number | null;
}

interface ProgressionData {
    id: number;
    slug: string;
    name: string;
    category: string;
    styleSlug: string;
    numerals: string;
    numeralsDisplay: string;
    tonality?: string;
    tags: string[];
    description?: string;
    typicalGenres?: string;
    chordCount: number;
    songCount: number;
}

interface Props {
    progression: ProgressionData;
    songs: SongData[];
    siblings: ProgressionData[];
    tiles: ProgressionTile[];
    courses: CourseShelfCardData[];
}

const props = defineProps<Props>();

// ── Category labels ─────────────────────────────────────────
const categoryLabels: Record<string, string> = {
    'bossa-nova': 'Bossa Nova',
    'jazz':       'Jazz',
    'classical':  'Classical',
    'pop':        'Pop',
};

// ── Computed properties ───────────────────────────────────────
const categoryLabel = computed(() => {
    return categoryLabels[props.progression.category] || props.progression.category;
});

const tonalityLabel = computed(() => {
    if (!props.progression.tonality || props.progression.tonality === 'both') return '';
    return props.progression.tonality === 'major' ? 'Major' : 'Minor';
});

const hasSongs = computed(() => props.songs.length > 0);
const hasSiblings = computed(() => props.siblings.length > 0);
const hasDescription = computed(() => props.progression.description && props.progression.description.trim());
const hasGenres = computed(() => props.progression.typicalGenres && props.progression.typicalGenres.trim());
const hasTags = computed(() => props.progression.tags.length > 0);

// Convert tiles to chords for the viewer
const chords = computed((): ProgressionChord[] => {
    return props.tiles.map((tile) => ({
        chordName: tile.chordName,
        diagramData: tile.diagramData,
        beats: 4,
        slug: tile.slug,
        numeral: tile.numeral ?? undefined,
    }));
});


const n = parseInt(new URLSearchParams(window.location.search).get('highlight') ?? '', 10);
const highlightIndex = (!isNaN(n) && n >= 0) ? n : 0;
</script>

<template>
    <div class="sbn-page-detail sbn-prog-detail-page">
        <Breadcrumb :segments="[{ label: 'Progressions', href: '/library/progressions' }, { label: progression.name }]" :color="getCategoryColor(progression.category)" />
            <!-- Header -->
            <header class="sbn-prog-detail-header sbn-detail-hero">
                <h1 class="sbn-prog-detail-title">{{ progression.name }}</h1>
                <p class="sbn-prog-detail-subtitle">
                    {{ categoryLabel }} chord progression
                    <span v-if="tonalityLabel"> • {{ tonalityLabel }}</span>
                    <span v-if="progression.chordCount"> • {{ progression.chordCount }} chords</span>
                </p>
                
                <div class="sbn-prog-detail-badges">
                    <span class="sbn-cat-badge sbn-cat-badge-filled" :style="{ '--cat-clr': getCategoryColor(progression.category) }">
                        {{ categoryLabel }}
                    </span>
                    <span
                        v-if="tonalityLabel"
                        class="sbn-badge"
                        :class="progression.tonality === 'major' ? 'sbn-badge-tonality-major' : 'sbn-badge-tonality-minor'"
                    >
                        {{ tonalityLabel }}
                    </span>
                    <span
                        v-for="tag in progression.tags.slice(0, 5)"
                        :key="tag"
                        class="sbn-hashtag"
                    >#{{ tag }}</span>
                </div>
            </header>

            <!-- Main content -->
            <div class="sbn-prog-detail-content">
                <!-- Chord Progression Viewer -->
                <section v-if="tiles.length" class="sbn-prog-detail-section">
                    <ChordProgressionViewer
                        :chords="chords"
                        :interactive="true"
                        :show-flow-arrows="true"
                        :name="progression.name"
                        :category="progression.category"
                        :key-label="`Standard Root`"
                        :numerals="progression.numeralsDisplay"
                        :color="getCategoryColor(progression.category)"
                        :vintage-card="true"
                        :initial-index="highlightIndex"
                    />
                </section>

                <!-- Description -->
                <section v-if="hasDescription" class="sbn-prog-detail-section">
                    <h2 class="sbn-prog-detail-section-title">Description</h2>
                    <div class="sbn-prog-detail-description">
                        {{ progression.description }}
                    </div>
                </section>

                <!-- Typical Genres -->
                <section v-if="hasGenres" class="sbn-prog-detail-section">
                    <h2 class="sbn-prog-detail-section-title">Typical Genres</h2>
                    <div class="sbn-prog-detail-description">
                        {{ progression.typicalGenres }}
                    </div>
                </section>

                <!-- Songs featuring this progression -->
                <section v-if="hasSongs" class="sbn-prog-detail-section">
                    <MediaShelf :title="`Songs featuring this progression (${songs.length})`">
                        <SongShelfCard v-for="song in songs" :key="song.id" :song="song" />
                    </MediaShelf>
                </section>

                <!-- Related Courses -->
                <section v-if="courses && courses.length" class="sbn-prog-detail-section">
                    <MediaShelf title="Related Courses">
                        <CourseShelfCard v-for="course in courses" :key="course.id" :course="course" />
                    </MediaShelf>
                </section>
            </div>

            <!-- Related Progressions at bottom -->
            <section v-if="hasSiblings" class="sbn-prog-detail-section">
                <h2 class="sbn-prog-detail-section-title">More {{ categoryLabel }} progressions</h2>
                <div class="sbn-prog-related-list">
                    <ProgressionLink
                        v-for="sibling in siblings.slice(0, 6)"
                        :key="sibling.id"
                        :progression="sibling"
                    />
                </div>
            </section>
    </div>
</template>

<style scoped>

/* Header */
.sbn-prog-detail-header {
    padding: 24px 28px;
    margin-bottom: 32px;
}

.sbn-prog-detail-title {
    font-size: 36px;
    font-weight: 900;
    color: var(--clr-text);
    margin: 0 0 12px;
    letter-spacing: -0.02em;
    line-height: 1.1;
}

.sbn-prog-detail-subtitle {
    font-size: 18px;
    color: var(--clr-text-muted);
    margin: 0 0 16px;
    font-weight: 400;
    line-height: 1.5;
}

.sbn-prog-detail-badges {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 24px;
}

/* Main content — single column now that songs moved to a shelf */
.sbn-prog-detail-content {
    display: block;
}

/* Main content sections */
.sbn-prog-detail-section {
    margin-bottom: 40px;
}

.sbn-prog-detail-section-title {
    font-size: 20px;
    font-weight: 700;
    color: var(--clr-text);
    margin: 0 0 16px;
    border-bottom: 2px solid var(--clr-border);
    padding-bottom: 8px;
}

.sbn-prog-detail-section-count {
    font-size: 14px;
    font-weight: 400;
    color: var(--clr-text-muted);
    margin-left: 8px;
}

.sbn-prog-detail-numerals {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-bottom: 24px;
}

.sbn-prog-detail-description {
    font-size: 16px;
    line-height: 1.7;
    color: var(--clr-text-muted);
    margin-bottom: 32px;
}

/* Related Progressions (rows rendered by ProgressionLink.vue) */
.sbn-prog-detail-section .sbn-prog-related-list {
    display: flex;
    flex-direction: column;
    gap: 6px;
}


@media (max-width: 768px) {
    .sbn-prog-detail-title {
        font-size: 28px;
    }

    .sbn-prog-detail-subtitle {
        font-size: 16px;
    }
}
</style>
