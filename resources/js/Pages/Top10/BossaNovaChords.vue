<script setup lang="ts">
import { ref, onMounted } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import ChordCard from '@/Components/Library/ChordCard.vue';
import ChordProgressionViewer from '@/Components/Library/ChordProgressionViewer.vue';
import type { ChordDiagramData } from '@/Components/Library/ChordDiagram.vue';
import type { ProgressionChord } from '@/Components/Library/ChordProgressionViewer.vue';
import { getCategoryColor } from '@/composables/useCategoryColors';

defineOptions({ layout: PublicLayout });

interface Top10Chord {
    id: number;
    title: string;
    shortTitle: string;
    chordName: string;
    image: string;
    description: string;
    slug: string;
}

interface ProgressionTile {
    name: string;
    slug: string;
}

interface Top10ChordWithDetail extends Top10Chord {
    voicingData: ChordDiagramData | null;
    progressionTiles: Array<{ chordName: string; diagramData: ChordDiagramData | null; numeral?: string }>;
    voicingCaption: string;
    progressionName: string;
    progressionViewerName?: string;
    progressionCaption: string;
    progressionSeedKey?: string;
    progressionMeta?: {
        name: string;
        numerals: string;
        slug: string;
        category?: string;
    } | null;
    relatedProducts: RelatedProduct[];
}

interface RelatedProduct {
    title: string;
    description: string;
    url: string;
    type: string;
}

interface Top10DataItem {
    id: number;
    title: string;
    shortTitle: string;
    chordName: string;
    image: string;
    description: string;
    slug: string;
    voicingData: ChordDiagramData | null;
    voicingCaption: string;
    progressionName: string;
    progressionViewerName?: string;
    progressionCaption: string;
    progressionSeedKey?: string;
    progressionTiles: Array<{ chordName: string; diagramData: ChordDiagramData | null; numeral?: string }>;
    progressionMeta?: {
        name: string;
        numerals: string;
        slug: string;
        category?: string;
    } | null;
    relatedProducts: RelatedProduct[];
}

const props = defineProps<{
    top10Data: Top10DataItem[];
}>();

const chords = ref<Top10ChordWithDetail[]>([]);
const selectedChord = ref<Top10ChordWithDetail | null>(null);
const isLoading = ref(false);

onMounted(() => {
    loadChords();
});

function loadChords() {
    chords.value = props.top10Data.map((item, index) => ({
        id: index + 1,
        title: item.title,
        shortTitle: item.shortTitle,
        chordName: item.chordName,
        image: item.image,
        description: item.description,
        slug: item.slug,
        voicingData: item.voicingData,
        progressionTiles: item.progressionTiles,
        voicingCaption: item.voicingCaption,
        progressionName: item.progressionName,
        progressionViewerName: item.progressionViewerName,
        progressionCaption: item.progressionCaption,
        progressionSeedKey: item.progressionSeedKey,
        progressionMeta: item.progressionMeta,
        relatedProducts: item.relatedProducts,
    }));
    selectedChord.value = chords.value[0] || null;
}

function selectChord(chord: Top10ChordWithDetail) {
    selectedChord.value = chord;
}

function nextChord() {
    const currentIndex = chords.value.findIndex(c => c.id === selectedChord.value?.id);
    const nextIndex = (currentIndex + 1) % chords.value.length;
    selectedChord.value = chords.value[nextIndex];
}

function prevChord() {
    const currentIndex = chords.value.findIndex(c => c.id === selectedChord.value?.id);
    const prevIndex = (currentIndex - 1 + chords.value.length) % chords.value.length;
    selectedChord.value = chords.value[prevIndex];
}

function goToChordLibrary(chord: ChordDiagramData) {
    if (!chord.slug) return;
    const url = `/library/chords/${chord.slug}?root=${chord.root_note || 'C'}`;
    router.visit(url);
}
</script>

<template>
    <div class="sbn-top10-page">
        <!-- Loading State -->
        <div v-if="isLoading" class="sbn-top10-loading">
            <div class="sbn-spinner"></div>
            <div>Loading Top 10...</div>
        </div>

        <!-- Main Content -->
        <div v-else class="sbn-top10-content">
            <div class="sbn-top10-container">
                <!-- Mobile Navigation -->
                <div class="sbn-top10-nav-mobile">
                    <div class="sbn-nav-scroll">
                        <button
                            v-for="chord in chords"
                            :key="chord.id"
                            @click="selectChord(chord)"
                            class="sbn-nav-thumb"
                            :class="{ 'sbn-nav-thumb--active': selectedChord?.id === chord.id }"
                        >
                            <div class="sbn-nav-thumb-image">
                                <img :src="chord.image" :alt="chord.shortTitle" />
                                <div class="sbn-nav-thumb-number">{{ chord.id }}</div>
                            </div>
                            <div class="sbn-nav-thumb-title">{{ chord.title }}</div>
                        </button>
                    </div>
                </div>

                <!-- Desktop Navigation -->
                <div class="sbn-top10-nav-desktop">
                    <button
                        v-for="chord in chords"
                        :key="chord.id"
                        @click="selectChord(chord)"
                        class="sbn-nav-card"
                        :class="{ 'sbn-nav-card--active': selectedChord?.id === chord.id }"
                    >
                        <div class="sbn-nav-card-image">
                            <img :src="chord.image" :alt="chord.shortTitle" />
                            <div class="sbn-nav-card-number">{{ chord.id }}</div>
                        </div>
                        <div class="sbn-nav-card-title">{{ chord.title }}</div>
                    </button>
                </div>

                <!-- Detail View -->
                <div v-if="selectedChord" class="sbn-top10-detail">
                    <div class="sbn-detail-header">
                        <span class="sbn-detail-badge">CHORD #{{ selectedChord.id }}</span>
                        <h1 class="sbn-detail-title">{{ selectedChord.title }}</h1>
                        <p class="sbn-detail-description">{{ selectedChord.description }}</p>
                    </div>

                    <!-- Panels Grid -->
                    <div class="sbn-panels-grid">
                        <!-- Voicing Panel -->
                        <div v-if="selectedChord.voicingData" class="sbn-panel">
                            <h3 class="sbn-panel-title">Chord Voicing</h3>
                            <div class="sbn-panel-content">
                                <ChordCard 
                                    :chord="selectedChord.voicingData" 
                                    :show-root="true" 
                                    :on-chord-click="() => goToChordLibrary(selectedChord!.voicingData!)"
                                />
                                <p class="sbn-panel-caption">{{ selectedChord.voicingCaption }}</p>
                            </div>
                        </div>

                        <!-- Progression Panel -->
                        <div class="sbn-panel">
                            <h3 class="sbn-panel-title">{{ selectedChord.progressionName }}</h3>
                            <div class="sbn-panel-content">
                                <ChordProgressionViewer
                                    :chords="selectedChord.progressionTiles.map((t): ProgressionChord => ({ chordName: t.chordName, diagramData: t.diagramData, beats: 4, numeral: t.numeral }))"
                                    :interactive="true"
                                    :vintage-card="true"
                                    :color="getCategoryColor(selectedChord.progressionMeta?.category || 'bossa-nova')"
                                    :name="selectedChord.progressionViewerName || selectedChord.progressionName"
                                    :category="selectedChord.progressionMeta?.category"
                                    :key-label="selectedChord.progressionSeedKey ? `Key: ${selectedChord.progressionSeedKey}` : ''"
                                    :numerals="selectedChord.progressionMeta?.numerals"
                                />
                                <p class="sbn-panel-caption">{{ selectedChord.progressionCaption }}</p>
                            </div>
                        </div>
                    </div>

                    <!-- Navigation Buttons -->
                    <div class="sbn-detail-nav">
                        <button @click="prevChord" class="sbn-nav-btn">← Previous</button>
                        <button @click="nextChord" class="sbn-nav-btn">Next →</button>
                    </div>

                    <!-- Related Products -->
                    <div v-if="selectedChord.relatedProducts && selectedChord.relatedProducts.length > 0" class="sbn-related-products">
                        <h3 class="sbn-related-title">Related Products & Courses</h3>
                        <div class="sbn-related-list">
                            <Link
                                v-for="(product, index) in selectedChord.relatedProducts"
                                :key="index"
                                :href="product.url"
                                class="sbn-card-link sbn-related-item"
                            >
                                <div class="sbn-related-content">
                                    <div class="sbn-related-header">
                                        <span class="sbn-related-badge" :class="`sbn-related-badge--${product.type}`">{{ product.type }}</span>
                                    </div>
                                    <h4 class="sbn-related-name">{{ product.title }}</h4>
                                    <p class="sbn-related-desc">{{ product.description }}</p>
                                </div>
                                <div class="sbn-related-arrow">
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <line x1="5" y1="12" x2="19" y2="12"></line>
                                        <polyline points="12 5 19 12 12 19"></polyline>
                                    </svg>
                                </div>
                            </Link>
                        </div>
                    </div>

                    <!-- Footer Links -->
                    <div class="sbn-footer-links">
                        <Link href="/top10/bossa-nova-chords" class="sbn-footer-link sbn-footer-link--active">TOP 10 Bossa Nova Chords</Link>
                        <span class="sbn-footer-separator">•</span>
                        <Link href="/top10/latin-jazz-standards" class="sbn-footer-link">TOP 10 Latin Jazz Standards</Link>
                        <span class="sbn-footer-separator">•</span>
                        <Link href="/top10/bossa-nova-songs" class="sbn-footer-link">TOP 10 Bossa Nova Songs</Link>
                        <span class="sbn-footer-separator">•</span>
                        <Link href="/top10/latin-jazz-guitar-players" class="sbn-footer-link">TOP 10 Latin Jazz Guitar Players</Link>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.sbn-top10-page {
    background: white;
    min-height: 100vh;
    padding-bottom: 40px;
}

.sbn-top10-loading {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    min-height: 400px;
    background: white;
}

.sbn-spinner {
    width: 50px;
    height: 50px;
    border: 4px solid var(--clr-surface-2);
    border-top-color: var(--clr-style-bossa);
    border-radius: 50%;
    animation: spin 0.8s linear infinite;
    margin-bottom: 16px;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

.sbn-top10-content {
    animation: fadeIn 0.4s ease-in;
}

@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

.sbn-top10-container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 16px;
}

@media (min-width: 768px) {
    .sbn-top10-container {
        padding: 48px;
    }
}

/* Mobile Navigation */
.sbn-top10-nav-mobile {
    display: block;
    overflow-x: auto;
    margin-bottom: 24px;
    padding-bottom: 16px;
    border-bottom: 1px solid var(--clr-border);
    margin: -16px -16px 24px -16px;
    padding: 0 16px 16px 16px;
}

.sbn-nav-scroll {
    display: flex;
    gap: 12px;
}

.sbn-nav-thumb {
    flex-shrink: 0;
    width: 80px;
    background: transparent;
    border: none;
    cursor: pointer;
    padding: 0;
    opacity: 0.5;
    transition: opacity 0.3s ease;
}

.sbn-nav-thumb--active {
    opacity: 1;
}

.sbn-nav-thumb-image {
    width: 100px;
    height: 100px;
    border-radius: 8px;
    overflow: hidden;
    position: relative;
    margin-bottom: 8px;
    border: 2px solid transparent;
}

.sbn-nav-thumb--active .sbn-nav-thumb-image {
    border-color: var(--clr-accent);
}

.sbn-nav-thumb-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.sbn-nav-thumb-number {
    position: absolute;
    top: 4px;
    left: 4px;
    width: 20px;
    height: 20px;
    border-radius: 50%;
    background: var(--clr-text-muted);
    color: white;
    font-size: 11px;
    font-weight: bold;
    display: flex;
    align-items: center;
    justify-content: center;
}

.sbn-nav-thumb--active .sbn-nav-thumb-number {
    background: var(--clr-accent);
}

.sbn-nav-thumb-title {
    font-size: 10px;
    font-weight: normal;
    color: var(--clr-text);
    text-align: center;
    line-height: 1.2;
    overflow: hidden;
    text-overflow: ellipsis;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    line-clamp: 2;
    -webkit-box-orient: vertical;
}

.sbn-nav-thumb--active .sbn-nav-thumb-title {
    font-weight: bold;
}

/* Desktop Navigation */
.sbn-top10-nav-desktop {
    display: none;
    grid-template-columns: repeat(5, 1fr);
    gap: 16px;
    margin-bottom: 32px;
    padding-bottom: 32px;
    border-bottom: 1px solid var(--clr-surface-2);
}

@media (min-width: 768px) {
    .sbn-top10-nav-mobile {
        display: none;
    }
    .sbn-top10-nav-desktop {
        display: grid;
    }
}

.sbn-nav-card {
    background: transparent;
    border: none;
    cursor: pointer;
    opacity: 0.6;
    transition: all 0.3s ease;
    padding: 0;
}

.sbn-nav-card--active {
    opacity: 1;
    transform: scale(1.05);
}

.sbn-nav-card-image {
    width: 100%;
    height: 140px;
    border-radius: 8px;
    overflow: hidden;
    position: relative;
    margin-bottom: 8px;
}

.sbn-nav-card-image img {
    width: 100%;
    height: 100%;
    object-fit: contain;
}

.sbn-nav-card-number {
    position: absolute;
    top: 8px;
    left: 8px;
    width: 28px;
    height: 28px;
    border-radius: 50%;
    background: var(--clr-text-muted);
    color: white;
    font-size: 14px;
    font-weight: bold;
    display: flex;
    align-items: center;
    justify-content: center;
}

.sbn-nav-card--active .sbn-nav-card-number {
    background: var(--clr-accent);
}

.sbn-nav-card-title {
    font-size: 12px;
    font-weight: bold;
    text-align: left;
    color: var(--clr-text);
    line-height: 1.2;
    overflow: hidden;
    text-overflow: ellipsis;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    line-clamp: 2;
    -webkit-box-orient: vertical;
}

/* Detail View */
.sbn-top10-detail {
    padding: 16px 0;
}

.sbn-detail-header {
    margin-bottom: 32px;
}

.sbn-detail-badge {
    display: inline-block;
    padding: 4px 12px;
    background: var(--clr-accent);
    font-size: 12px;
    font-weight: 600;
    border-radius: 9999px;
    margin-bottom: 12px;
    color: white;
}

.sbn-detail-title {
    font-size: 1.75rem;
    font-weight: bold;
    margin-bottom: 8px;
    color: var(--clr-text);
    line-height: 1.2;
}

@media (min-width: 768px) {
    .sbn-detail-title {
        font-size: 3rem;
    }
}

.sbn-detail-description {
    color: var(--clr-text-muted);
    line-height: 1.8;
    font-size: 1rem;
    margin-bottom: 32px;
}

@media (min-width: 768px) {
    .sbn-detail-description {
        font-size: 1.25rem;
    }
}

/* Panels Grid - side by side layout */
.sbn-panels-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 20px;
    margin-bottom: 32px;
}

@media (min-width: 768px) {
    .sbn-panels-grid {
        grid-template-columns: 3fr 7fr;
    }
}

/* Panel - extends design system with Top10-specific gray background */
.sbn-panel {
    background: var(--clr-surface-2);
    border: 1px solid var(--clr-border);
    border-radius: var(--radius);
    padding: 20px;
}

.sbn-panel-title {
    font-size: 14px;
    font-weight: 600;
    color: var(--clr-style-bossa);
    margin-bottom: 12px;
    text-transform: uppercase;
}

.sbn-panel-content {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 16px;
}

.sbn-panel-content .sbn-chord-card {
    max-width: 150px;
}

.sbn-panel-caption {
    font-size: 14px;
    color: var(--clr-text-muted);
    line-height: 1.6;
    margin-top: 12px;
    text-align: center;
}

/* Navigation Buttons */
.sbn-detail-nav {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    margin-top: 32px;
    padding-top: 32px;
    border-top: 1px solid var(--clr-surface-2);
}

@media (min-width: 768px) {
    .sbn-detail-nav {
        justify-content: center;
    }
}

.sbn-nav-btn {
    background: var(--clr-gradient);
    color: white;
    padding: 10px 20px;
    border-radius: var(--radius-sm);
    font-weight: 600;
    font-size: 14px;
    border: none;
    cursor: pointer;
    transition: transform 0.2s;
}

.sbn-nav-btn:hover {
    transform: translateY(-2px);
}

@media (min-width: 768px) {
    .sbn-nav-btn {
        flex: none;
        width: 180px;
    }
}

/* Related Products */
.sbn-related-products {
    margin-top: 48px;
    padding-top: 32px;
    border-top: 2px solid var(--clr-surface-2);
}

.sbn-related-title {
    font-size: 1.25rem;
    font-weight: bold;
    color: var(--clr-text);
    margin-bottom: 20px;
}

.sbn-related-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.sbn-related-content {
    flex: 1;
}

.sbn-related-header {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 4px;
}

.sbn-related-badge {
    padding: 2px 8px;
    background: var(--clr-text-muted);
    color: white;
    font-size: 10px;
    font-weight: bold;
    border-radius: 4px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.sbn-related-badge--product {
    background: var(--clr-text-muted);
}

.sbn-related-badge--course {
    background: var(--clr-accent);
}

.sbn-related-name {
    font-size: 1rem;
    font-weight: 600;
    color: var(--clr-text);
    margin: 0 0 4px 0;
}

.sbn-related-desc {
    font-size: 0.875rem;
    color: var(--clr-text-muted);
    margin: 0;
    line-height: 1.4;
}

.sbn-related-arrow {
    flex-shrink: 0;
    margin-left: 16px;
    color: var(--clr-accent);
}

/* Footer Links */
.sbn-footer-links {
    margin-top: 40px;
    padding-top: 32px;
    border-top: 1px solid var(--clr-border);
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 16px;
    font-size: 14px;
    flex-wrap: wrap;
}

.sbn-footer-link {
    color: var(--clr-text-muted);
    text-decoration: none;
    transition: color 0.2s;
}

.sbn-footer-link:hover {
    color: var(--clr-accent);
}

.sbn-footer-link--active {
    color: var(--clr-accent);
    font-weight: bold;
}

.sbn-footer-separator {
    color: var(--clr-text);
    font-size: 20px;
}

@media (max-width: 767px) {
    .sbn-footer-separator {
        display: none;
    }
}

/* Update container to match legacy - no border-radius, no box-shadow */
.sbn-top10-container {
    background: white;
    border-radius: 0;
    border: none;
    padding: 16px;
    box-shadow: none;
}

@media (min-width: 768px) {
    .sbn-top10-container {
        padding: 32px;
    }
}
</style>
