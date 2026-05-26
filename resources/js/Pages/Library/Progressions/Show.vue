<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import BackNav from '@/Components/BackNav.vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import ChordProgressionViewer from '@/Components/Library/ChordProgressionViewer.vue';
import SongLink from '@/Components/Library/SongLink.vue';
import type { ProgressionChord } from '@/Components/Library/ChordProgressionViewer.vue';
import type { SongLinkData } from '@/Components/Library/SongLink.vue';
import { getCategoryColor } from '@/composables/useCategoryColors';

interface ProgressionTile {
    chordName: string;
    diagramData: ChordDiagramData | null;
    slug?: string | null;
    numeral?: string | null;
}

defineOptions({ layout: PublicLayout });

type SongData = SongLinkData;

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
}

const props = defineProps<Props>();

// ── Category labels ─────────────────────────────────────────
const categoryLabels: Record<string, string> = {
    'jazz': 'Jazz',
    'blues': 'Blues',
    'pop': 'Pop / Rock',
    'modal': 'Modal',
    'classical': 'Classical',
    'latin': 'Latin',
    'other': 'Other',
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
        <div class="sbn-prog-detail-container">
            <!-- Header -->
            <header class="sbn-prog-detail-header">
                <BackNav library-href="/library/progressions" library-label="Progressions" />
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
                        class="sbn-badge sbn-badge-muted"
                    >
                        {{ tag }}
                    </span>
                </div>
            </header>

            <!-- Main content -->
            <div class="sbn-prog-detail-content">
                <!-- Main content area -->
                <div class="sbn-prog-detail-main">
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

                    <!-- Songs Featuring This Progression will be in sidebar -->
                </div>

                <!-- Sidebar with songs -->
                <aside class="sbn-prog-detail-sidebar">
                    <div class="sbn-sidebar-section">
                        <h3>Songs Featuring This Progression
                            <span v-if="hasSongs" class="sbn-prog-detail-section-count">({{ songs.length }})</span>
                        </h3>
                        
                        <ul v-if="hasSongs" class="sbn-prog-songs-list">
                            <li v-for="song in songs" :key="song.id">
                                <SongLink :song="song" />
                            </li>
                        </ul>
                        
                        <p v-else class="sbn-prog-detail-description">
                            No songs in our library currently feature this progression.
                        </p>
                    </div>
                </aside>
            </div>

            <!-- Related Progressions at bottom -->
            <section v-if="hasSiblings" class="sbn-prog-detail-section">
                <h2 class="sbn-prog-detail-section-title">More {{ categoryLabel }} progressions</h2>
                <div class="sbn-prog-related-list">
                    <div 
                        v-for="sibling in siblings.slice(0, 6)"
                        :key="sibling.id"
                        class="sbn-prog-related-item"
                    >
                        <Link 
                            :href="`/library/progressions/${sibling.slug}`"
                            class="sbn-prog-related-link"
                        >
                            <span class="sbn-cat-badge sbn-cat-badge-filled" :style="{ '--cat-clr': getCategoryColor(sibling.category) }">
                                {{ categoryLabels[sibling.category] || sibling.category }}
                            </span>
                            <span class="sbn-prog-related-name">{{ sibling.name }}</span>
                            <div class="sbn-numeral-chip-row">
                                <span
                                    v-for="n in sibling.numeralsDisplay.split('–').map(s => s.trim()).filter(Boolean)"
                                    :key="n"
                                    class="sbn-numeral-chip"
                                >{{ n }}</span>
                            </div>
                        </Link>
                    </div>
                </div>
            </section>
        </div>
    </div>
</template>

<style scoped>

.sbn-prog-detail-container {
    max-width: 1200px;
    margin: 0 auto;
}

/* Header */
.sbn-prog-detail-header {
    margin-bottom: 32px;
    padding-bottom: 24px;
    border-bottom: 3px solid #e85d3b;
}



.sbn-prog-detail-title {
    font-size: 36px;
    font-weight: 900;
    color: #1a202c;
    margin: 0 0 12px;
    letter-spacing: -0.02em;
    line-height: 1.1;
}

.sbn-prog-detail-subtitle {
    font-size: 18px;
    color: #718096;
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

/* Two-column layout */
.sbn-prog-detail-content {
    display: flex;
    gap: 40px;
}

.sbn-prog-detail-main {
    flex: 1;
    min-width: 0;
}

.sbn-prog-detail-sidebar {
    width: 320px;
    flex-shrink: 0;
}

/* Main content sections */
.sbn-prog-detail-section {
    margin-bottom: 40px;
}

.sbn-prog-detail-section-title {
    font-size: 20px;
    font-weight: 700;
    color: #1a202c;
    margin: 0 0 16px;
    border-bottom: 2px solid #e2e8f0;
    padding-bottom: 8px;
}

.sbn-prog-detail-section-count {
    font-size: 14px;
    font-weight: 400;
    color: #718096;
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
    color: #4a5568;
    margin-bottom: 32px;
}

/* Songs list — rows rendered by SongLink.vue / sbn-design-system.css */
.sbn-prog-songs-list {
    list-style: none;
    padding: 0;
    margin: 0;
}

/* Sidebar */
.sbn-sidebar-section {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 20px;
}

.sbn-sidebar-section h3 {
    font-size: 16px;
    font-weight: 600;
    color: #1a202c;
    margin: 0 0 16px;
}

/* Related Progressions (bottom section) */
.sbn-prog-detail-section .sbn-prog-related-list {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 16px;
}

.sbn-prog-detail-section .sbn-prog-related-item {
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 16px;
    transition: border-color 0.12s;
}

.sbn-prog-detail-section .sbn-prog-related-item:hover {
    border-color: #e85d3b;
}

.sbn-prog-detail-section .sbn-prog-related-link {
    display: flex;
    flex-direction: column;
    gap: 8px;
    text-decoration: none;
    color: inherit;
}

.sbn-prog-detail-section .sbn-prog-related-name {
    font-size: 16px;
    font-weight: 600;
    color: #1a202c;
}


/* Related Progressions (sidebar - not used anymore but keeping for reference) */
.sbn-prog-detail-sidebar .sbn-prog-related-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.sbn-prog-detail-sidebar .sbn-prog-related-item {
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 12px;
    transition: border-color 0.12s;
}

.sbn-prog-detail-sidebar .sbn-prog-related-item:hover {
    border-color: #e85d3b;
}

.sbn-prog-detail-sidebar .sbn-prog-related-link {
    display: flex;
    flex-direction: column;
    gap: 6px;
    text-decoration: none;
    color: inherit;
}

.sbn-prog-detail-sidebar .sbn-prog-related-name {
    font-size: 14px;
    font-weight: 600;
    color: #1a202c;
}



.sbn-chord-symbol {
    font-family: 'Georgia', 'Times New Roman', serif;
}

/* Responsive */
@media (max-width: 1024px) {
    .sbn-prog-detail-content {
        flex-direction: column;
        gap: 30px;
    }
    
    .sbn-prog-detail-sidebar {
        width: 100%;
    }
}

@media (max-width: 768px) {
    .sbn-prog-detail-page {
        padding: 0 16px 60px;
    }
    
    .sbn-prog-detail-title {
        font-size: 28px;
    }
    
    .sbn-prog-detail-subtitle {
        font-size: 16px;
    }
}
</style>
