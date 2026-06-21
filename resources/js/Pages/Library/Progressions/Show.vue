<script setup lang="ts">
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import Breadcrumb from '@/Components/Breadcrumb.vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import ChordProgressionViewer from '@/Components/Library/ChordProgressionViewer.vue';
import ProgressionLink from '@/Components/Library/ProgressionLink.vue';
import MediaShelf from '@/Components/Library/MediaShelf.vue';
import SongShelfCard from '@/Components/Library/SongShelfCard.vue';
import CourseShelfCard from '@/Components/Course/CourseShelfCard.vue';
import type { CourseShelfCardData } from '@/Components/Course/CourseShelfCard.vue';
import type { ProgressionChord, StyleVariant } from '@/Components/Library/ChordProgressionViewer.vue';
import { getCategoryColor } from '@/composables/useCategoryColors';
import { difficultyBreadcrumbSegment } from '@/composables/useBreadcrumb';

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
    chordCount: number;
    songCount: number;
    difficulty?: number | null;
}

interface Props {
    progression: ProgressionData;
    songs: SongData[];
    siblings: ProgressionData[];
    tiles: ProgressionTile[];
    courses: CourseShelfCardData[];
    progressionKey?: string;
}

const props = defineProps<Props>();

const categoryLabels: Record<string, string> = {
    'bossa-nova': 'Bossa Nova',
    'jazz':       'Jazz',
    'classical':  'Classical',
    'pop':        'Pop',
};

const categoryLabel = computed(() => categoryLabels[props.progression.category] || props.progression.category);

const tonalityLabel = computed(() => {
    if (!props.progression.tonality || props.progression.tonality === 'both') return '';
    return props.progression.tonality === 'major' ? 'Major' : 'Minor';
});

const popularityTier = computed(() => {
    const n = props.progression.songCount ?? 0;
    if (n >= 10) return { tier: 'iconic',     label: 'Iconic' };
    if (n >= 5)  return { tier: 'essential',  label: 'Essential' };
    if (n >= 2)  return { tier: 'common',     label: 'Common' };
    if (n >= 1)  return { tier: 'occasional', label: 'Rare' };
    return null;
});

const hasSongs      = computed(() => props.songs.length > 0);
const hasSiblings   = computed(() => props.siblings.length > 0);
const hasDescription = computed(() => props.progression.description && props.progression.description.trim());

const chords = computed((): ProgressionChord[] =>
    props.tiles.map((tile) => ({
        chordName: tile.chordName,
        diagramData: tile.diagramData,
        beats: 4,
        slug: tile.slug,
        numeral: tile.numeral ?? undefined,
    }))
);

const stubVariants = computed((): StyleVariant[] => [
    { id: 'basic',      label: 'Basic',       chords: chords.value },
    { id: 'extensions', label: 'Extensions',  chords: chords.value },
    { id: 'wes',        label: 'Wes Style',   chords: chords.value },
    { id: 'joao',       label: 'João Style',  chords: chords.value },
]);

const n = parseInt(new URLSearchParams(window.location.search).get('highlight') ?? '', 10);
const highlightIndex = (!isNaN(n) && n >= 0) ? n : 0;


const breadcrumbSegments = computed(() => {
    const segs = [{ label: 'Progressions', href: '/library/progressions' }];
    const filterParams: Record<string, string> = {};

    if (props.progression.category) {
        filterParams.category = props.progression.category;
        segs.push({
            label: categoryLabel.value,
            href: `/library/progressions?category=${encodeURIComponent(props.progression.category)}`,
        });
    }

    const difficultySeg = difficultyBreadcrumbSegment(props.progression.difficulty, '/library/progressions', filterParams);
    if (difficultySeg) segs.push(difficultySeg);

    segs.push({ label: props.progression.name });
    return segs;
});
</script>

<template>
    <Head>
        <title>{{ progression.name }} ({{ progression.numeralsDisplay }}) | Soul Bossa Nova</title>
        <meta name="description" :content="progression.description || `Learn the ${progression.name} chord progression (${progression.numeralsDisplay}) — a key pattern in Bossa Nova and Latin Jazz guitar.`" />
        <meta property="og:title" :content="`${progression.name} | Soul Bossa Nova`" />
        <meta property="og:description" :content="progression.description || `${progression.name} (${progression.numeralsDisplay}) — Bossa Nova chord progression with interactive diagrams and audio.`" />
        <meta property="og:type" content="website" />
    </Head>

    <div class="sbn-page-detail sbn-prog-detail-page">
        <Breadcrumb :segments="breadcrumbSegments" :color="getCategoryColor(progression.category)" />

        <header class="sbn-prog-detail-header sbn-detail-hero" :style="{ '--category-color': getCategoryColor(progression.category) }">
            <div class="sbn-show-hero-badges">
                <span class="sbn-cat-badge sbn-cat-badge-filled" :style="{ '--cat-clr': getCategoryColor(progression.category) }">{{ categoryLabel }}</span>
                <span v-if="popularityTier" class="sbn-card-pop" :class="`sbn-pop-${popularityTier.tier}`">{{ popularityTier.label }}</span>
                <span v-for="tag in progression.tags.slice(0, 5)" :key="tag" class="sbn-hashtag">#{{ tag }}</span>
            </div>
            <h1 class="sbn-show-hero-title">{{ progression.name }}</h1>
            <div class="sbn-show-hero-meta">
                <span v-if="tonalityLabel" class="sbn-meta-chip"><strong>Tonality</strong> {{ tonalityLabel }}</span>
                <span v-if="progression.chordCount" class="sbn-meta-chip"><strong>Chords</strong> {{ progression.chordCount }}</span>
            </div>
        </header>

        <div class="sbn-show-body">

            <!-- Left: main content -->
            <div class="sbn-show-main">

                <section v-if="tiles.length" class="sbn-prog-detail-section">
                    <ChordProgressionViewer
                        :chords="chords"
                        :variants="stubVariants"
                        :interactive="true"
                        :show-flow-arrows="true"
                        :name="progression.name"
                        :category="progression.category"
                        :key-label="progressionKey ?? 'C'"
                        :numerals="progression.numeralsDisplay"
                        :color="getCategoryColor(progression.category)"
                        :vintage-card="true"
                        :initial-index="highlightIndex"
                    />
                </section>

                <section v-if="hasDescription" class="sbn-prog-detail-section">
                    <h2 class="sbn-section-heading">Description</h2>
                    <div class="sbn-prog-detail-description sbn-prose" v-html="progression.description"></div>
                </section>

                <section v-if="hasSongs" class="sbn-prog-detail-section">
                    <MediaShelf title="Songs" view-all-href="/library/songs">
                        <SongShelfCard v-for="song in songs" :key="song.id" :song="song" />
                    </MediaShelf>
                </section>

                <section v-if="courses && courses.length" class="sbn-prog-detail-section">
                    <MediaShelf title="Related Courses" view-all-href="/learn">
                        <CourseShelfCard v-for="course in courses" :key="course.id" :course="course" />
                    </MediaShelf>
                </section>

            </div>

            <!-- Right: related progressions sidebar -->
            <aside v-if="hasSiblings" class="sbn-show-sidebar">
                <div class="sbn-show-sidebar-card">
                    <h3 class="sbn-show-sidebar-heading">More {{ categoryLabel }} progressions</h3>
                    <div class="sbn-prog-related-list">
                        <ProgressionLink
                            v-for="sibling in siblings.slice(0, 8)"
                            :key="sibling.id"
                            :progression="sibling"
                        />
                    </div>
                </div>
            </aside>

        </div>
    </div>
</template>

<style scoped>

.sbn-prog-detail-header {
    padding: 24px 28px;
    margin-bottom: 32px;
}


.sbn-prog-detail-section {
    margin-bottom: 40px;
}



.sbn-prog-detail-description {
    font-size: 16px;
    line-height: 1.7;
    color: var(--clr-text-muted);
    margin-bottom: 32px;
}

.sbn-prog-related-list {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

</style>
