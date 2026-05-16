<script setup lang="ts">
import { computed } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import ChordProgressionViewer from '@/Components/Library/ChordProgressionViewer.vue';
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
    title: string;
    composer: string;
    songKey: string;
    slug: string | number;
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
    builderPass?: 1 | 2;
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

// Test switch — Pass 1 (plain voicings) vs Pass 2 (option-tone upgrades).
// Reloads the page so the controller re-runs the builder with extensions=true.
const currentPass = computed(() => props.builderPass ?? 1);
function setBuilderPass(pass: 1 | 2) {
    if (currentPass.value === pass) return;
    const url = new URL(window.location.href);
    if (pass === 2) url.searchParams.set('pass', '2');
    else url.searchParams.delete('pass');
    router.visit(url.pathname + url.search, { preserveScroll: true });
}

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

// ── Category color classes ─────────────────────────────────────
function getCategoryClass(category: string): string {
    return `sbn-prog-cat-${category}`;
}

function getTonalityClass(tonality: string): string {
    return `sbn-prog-tonality-${tonality}`;
}
</script>

<template>
    <div class="sbn-prog-detail-page">
        <div class="sbn-prog-detail-container">
            <!-- Header -->
            <header class="sbn-prog-detail-header">
                <Link href="/library/progressions" class="sbn-back-link">← Back to Progressions</Link>
                <h1 class="sbn-prog-detail-title">{{ progression.name }}</h1>
                <p class="sbn-prog-detail-subtitle">
                    {{ categoryLabel }} chord progression
                    <span v-if="tonalityLabel"> • {{ tonalityLabel }}</span>
                    <span v-if="progression.chordCount"> • {{ progression.chordCount }} chords</span>
                </p>
                
                <div class="sbn-prog-detail-badges">
                    <span :class="['sbn-prog-row-cat-badge', getCategoryClass(progression.category)]">
                        {{ categoryLabel }}
                    </span>
                    <span 
                        v-if="tonalityLabel"
                        :class="['sbn-prog-tonality-badge', getTonalityClass(progression.tonality || '')]"
                    >
                        {{ tonalityLabel }}
                    </span>
                    <span 
                        v-for="tag in progression.tags.slice(0, 5)"
                        :key="tag"
                        class="sbn-prog-tag-chip"
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
                        <div class="sbn-builder-pass-toggle">
                            <span class="sbn-builder-pass-toggle-label">Builder pass</span>
                            <button
                                type="button"
                                class="sbn-builder-pass-toggle-btn"
                                :class="{ 'is-active': currentPass === 1 }"
                                @click="setBuilderPass(1)"
                            >Pass 1 — Plain</button>
                            <button
                                type="button"
                                class="sbn-builder-pass-toggle-btn"
                                :class="{ 'is-active': currentPass === 2 }"
                                @click="setBuilderPass(2)"
                            >Pass 2 — Extensions</button>
                        </div>
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
                            <li 
                                v-for="song in songs"
                                :key="song.id"
                                class="sbn-prog-song-item"
                            >
                                <Link 
                                    :href="`/library/songs/${song.slug}`"
                                    class="sbn-prog-song-link"
                                >
                                    {{ song.title }}
                                </Link>
                                <span v-if="song.composer" class="sbn-prog-song-composer">
                                    • {{ song.composer }}
                                </span>
                                <span v-if="song.songKey" class="sbn-prog-song-key">
                                    • {{ song.songKey }}
                                </span>
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
                            <span :class="['sbn-prog-row-cat-badge', getCategoryClass(sibling.category)]">
                                {{ categoryLabels[sibling.category] || sibling.category }}
                            </span>
                            <span class="sbn-prog-related-name">{{ sibling.name }}</span>
                            <span class="sbn-prog-related-numerals">{{ sibling.numeralsDisplay }}</span>
                        </Link>
                    </div>
                </div>
            </section>
        </div>
    </div>
</template>

<style scoped>
/* Builder pass test switch (Pass 1 = plain, Pass 2 = option-tone upgrades). */
.sbn-builder-pass-toggle {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 12px;
    font-size: 12px;
}
.sbn-builder-pass-toggle-label {
    color: var(--clr-text-muted, #888);
    text-transform: uppercase;
    letter-spacing: 0.05em;
    font-weight: 600;
}
.sbn-builder-pass-toggle-btn {
    padding: 4px 10px;
    border: 1px solid var(--clr-border, #d0d0d0);
    background: transparent;
    border-radius: 4px;
    font-size: 12px;
    cursor: pointer;
    color: var(--clr-text-muted, #888);
}
.sbn-builder-pass-toggle-btn.is-active {
    background: var(--clr-text, #222);
    color: var(--clr-bg, #fff);
    border-color: var(--clr-text, #222);
}

/* Layout matching rhythm show page */
.sbn-prog-detail-page {
    max-width: 1400px;
    margin: 0 auto;
    padding: 40px 20px 80px;
}

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

/* Songs list */
.sbn-prog-songs-list {
    list-style: none;
    padding: 0;
    margin: 0;
}

.sbn-prog-song-item {
    padding: 12px 0;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    gap: 12px;
}

.sbn-prog-song-item:last-child {
    border-bottom: none;
}

.sbn-prog-song-link {
    color: #2d3748;
    text-decoration: none;
    font-weight: 500;
    transition: color 0.12s;
}

.sbn-prog-song-link:hover {
    color: #e85d3b;
}

.sbn-prog-song-composer,
.sbn-prog-song-key {
    color: #718096;
    font-size: 14px;
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

.sbn-prog-detail-section .sbn-prog-related-numerals {
    font-size: 14px;
    color: #6b7280;
    font-family: 'Georgia', 'Times New Roman', serif;
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

.sbn-prog-detail-sidebar .sbn-prog-related-numerals {
    font-size: 12px;
    color: #6b7280;
    font-family: 'Georgia', 'Times New Roman', serif;
}

/* Category Badges (same as index page) */
.sbn-prog-row-cat-badge {
    font-size: 9px;
    font-weight: 800;
    letter-spacing: 0.07em;
    text-transform: uppercase;
    padding: 2px 8px;
    border-radius: 10px;
    display: inline-block;
}

.sbn-prog-row-cat-badge.sbn-prog-cat-jazz      { background: #e3f2fd; color: #1565c0; }
.sbn-prog-row-cat-badge.sbn-prog-cat-blues     { background: #fce4ec; color: #c62828; }
.sbn-prog-row-cat-badge.sbn-prog-cat-pop       { background: #e0f2f1; color: #00695c; }
.sbn-prog-row-cat-badge.sbn-prog-cat-modal     { background: #ede7f6; color: #4527a0; }
.sbn-prog-row-cat-badge.sbn-prog-cat-classical { background: #e8f5e9; color: #2e7d32; }
.sbn-prog-row-cat-badge.sbn-prog-cat-latin     { background: linear-gradient(135deg, #ff8c42, #e65100); color: #fff; }

/* Tonality Badges */
.sbn-prog-tonality-badge {
    font-size: 9px;
    font-weight: 700;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    padding: 2px 8px;
    border-radius: 10px;
    display: inline-block;
}

.sbn-prog-tonality-badge.sbn-prog-tonality-major { background: #fef9c3; color: #854d0e; }
.sbn-prog-tonality-badge.sbn-prog-tonality-minor { background: #ede9fe; color: #4c1d95; }

/* Tag Chips */
.sbn-prog-tag-chip {
    font-size: 9px;
    font-weight: 700;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    padding: 2px 8px;
    border-radius: 10px;
    background: #f3f4f6;
    color: #6b7280;
    border: 1px solid #e5e7eb;
    display: inline-block;
}

/* Numerals */
.sbn-prog-numeral-chip {
    display: inline-block;
    background: #f3f4f6;
    border: 1px solid #e5e7eb;
    border-radius: 5px;
    padding: 6px 12px;
    font-size: 16px;
    line-height: 1.4;
    color: #374151;
    font-family: 'Georgia', 'Times New Roman', serif;
    letter-spacing: 0.01em;
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
    
    .sbn-prog-song-item {
        flex-direction: column;
        align-items: flex-start;
        gap: 4px;
    }
}
</style>
