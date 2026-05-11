<script setup lang="ts">
import { computed, ref, watch, onMounted } from 'vue';
import { Link, Head } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import ChordProgressionViewer from '@/Components/Library/ChordProgressionViewer.vue';
import RhythmPattern from '@/Components/Library/RhythmPattern.vue';
import { getCategoryColor } from '@/composables/useCategoryColors';
import { getAudioEngine } from '@/audio/engine/AudioEngine';
import type { ChordDiagramData } from '@/Components/Library/ChordDiagram.vue';
import type { ProgressionChord } from '@/Components/Library/ChordProgressionViewer.vue';

defineOptions({ layout: PublicLayout });

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
    artist: string;
    image: string;
    description: string;
    slug: string;
    recordings?: string;
    voicingData: ChordDiagramData | null;
    voicingName: string;
    voicingCaption: string;
    progressionName: string;
    progressionCaption: string;
    progressionTiles: Array<{ chordName: string; diagramData: ChordDiagramData | null }>;
    rhythmData: any | null;
    rhythmName: string;
    rhythmCaption: string;
    rhythmCitation?: string;
    relatedProducts: RelatedProduct[];
}

const props = defineProps<{
    top10Data: Top10DataItem[];
}>();

const chords = ref<Top10DataItem[]>([]);
const selectedChord = ref<Top10DataItem | null>(null);
const isLoading = ref(false);

// Blend slider: 0 = pure samples, 1 = pure demo MP3.
const blend = ref(0);
const engine = getAudioEngine();

watch(blend, (v) => {
    if (engine.isInited) engine.setBlend(v);
});

onMounted(() => {
    chords.value = props.top10Data.map((item, index) => ({
        ...item,
        id: index + 1,
    }));
    selectedChord.value = chords.value[0] || null;
});

// Reset blend when navigating between items.
watch(() => selectedChord.value?.id, () => {
    blend.value = 0;
    if (engine.isInited) engine.setBlend(0);
});

function selectChord(chord: Top10DataItem) {
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
</script>

<template>
    <Head>
        <title>TOP 10 Bossa Nova Songs | Soul Bossa Nova</title>
        <meta name="description" content="Explore the essential TOP 10 Bossa Nova Songs, featuring history, chords, and rhythms for guitarists." />
    </Head>

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
                        <div class="sbn-nav-card-artist">{{ chord.artist }}</div>
                    </button>
                </div>

                <!-- Detail View -->
                <div v-if="selectedChord" class="sbn-top10-detail">
                    <div class="sbn-detail-header">
                        <h1 class="sbn-detail-title">{{ selectedChord.title }}</h1>
                        <p class="sbn-detail-artist">{{ selectedChord.artist }}</p>
                        <p class="sbn-detail-description" v-html="selectedChord.description"></p>
                    </div>

                    <!-- Panels Grid -->
                    <div class="sbn-panels-grid">
                        <!-- Chords Panel -->
                        <div v-if="selectedChord.progressionTiles.length > 0" class="sbn-panel-ghost">
                            <h3 class="sbn-panel-title">{{ selectedChord.voicingName }}</h3>
                            <div class="sbn-progression-wrapper">
                                <ChordProgressionViewer
                                    :chords="selectedChord.progressionTiles.map((t): ProgressionChord => ({ chordName: t.chordName, diagramData: t.diagramData, beats: 4 }))"
                                    :interactive="true"
                                    :show-flow-arrows="selectedChord.progressionTiles.length > 2"
                                    :vintage-card="true"
                                    :color="getCategoryColor('bossa-nova')"
                                    class="sbn-progression-large"
                                />
                            </div>
                            <p class="sbn-panel-caption" v-html="selectedChord.voicingCaption"></p>
                        </div>

                        <!-- Rhythm Panel -->
                        <div v-if="selectedChord.rhythmData" class="sbn-panel-ghost">
                            <h3 class="sbn-panel-title">{{ selectedChord.rhythmName }}</h3>
                            <div class="sbn-rhythm-wrapper">
                                <RhythmPattern
                                    :pattern="selectedChord.rhythmData"
                                    :playable="true"
                                    :mini="false"
                                    :vintage-card="true"
                                    :demo-url="selectedChord.rhythmData.demoUrl"
                                    :color="getCategoryColor(selectedChord.rhythmData.styleSlug || 'bossa-nova')"
                                >
                                    <template v-if="selectedChord.rhythmData.demoUrl" #transport-extra>
                                        <div class="sbn-blend-control">
                                            <span class="sbn-blend-label" :class="{ 'is-active': blend < 0.5 }">Samples</span>
                                            <input
                                                type="range"
                                                min="0"
                                                max="1"
                                                step="0.01"
                                                v-model.number="blend"
                                                class="sbn-blend-slider"
                                                aria-label="Blend between samples and demo audio"
                                            />
                                            <span class="sbn-blend-label" :class="{ 'is-active': blend >= 0.5 }">Demo</span>
                                        </div>
                                    </template>
                                </RhythmPattern>
                            </div>
                            <p class="sbn-panel-caption" v-html="selectedChord.rhythmCaption"></p>
                            <div v-if="selectedChord.rhythmCitation" class="sbn-panel-citation" v-html="selectedChord.rhythmCitation"></div>
                        </div>
                    </div>

                    <!-- Navigation Buttons -->
                    <div class="sbn-detail-nav">
                        <button @click="prevChord" class="sbn-nav-btn sbn-nav-btn--outline">← Previous</button>
                        <button @click="nextChord" class="sbn-nav-btn sbn-nav-btn--outline">Next →</button>
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
                        <Link href="/top10/bossa-nova-chords" class="sbn-footer-link">TOP 10 Bossa Nova Chords</Link>
                        <span class="sbn-footer-separator">•</span>
                        <Link href="/top10/latin-jazz-standards" class="sbn-footer-link">TOP 10 Latin Jazz Standards</Link>
                        <span class="sbn-footer-separator">•</span>
                        <Link href="/top10/bossa-nova-songs" class="sbn-footer-link sbn-footer-link--active">TOP 10 Bossa Nova Songs</Link>
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
    padding-bottom: 60px;
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
    background: white;
}

@media (min-width: 768px) {
    .sbn-top10-container {
        padding: 32px;
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
    width: 80px;
    height: 80px;
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
    display: flex;
    flex-direction: column;
}

.sbn-nav-card--active {
    opacity: 1;
    transform: scale(1.05);
}

.sbn-nav-card-image {
    width: 100%;
    height: 110px;
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
}

.sbn-nav-card-artist {
    font-size: 10px;
    color: var(--clr-text-muted);
    text-align: left;
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
    margin-bottom: 4px;
    color: var(--clr-text);
    line-height: 1.2;
}

@media (min-width: 768px) {
    .sbn-detail-title {
        font-size: 3rem;
    }
}

.sbn-detail-artist {
    font-size: 1.25rem;
    color: var(--clr-text-muted);
    margin-bottom: 32px;
    font-weight: 500;
}

.sbn-detail-description {
    color: var(--clr-text-muted);
    line-height: 1.8;
    font-size: 1rem;
    margin-bottom: 32px;
}

@media (min-width: 768px) {
    .sbn-detail-description {
        font-size: 1.15rem;
    }
}

/* Panels Grid */
.sbn-panels-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 32px;
    margin-bottom: 48px;
}

@media (min-width: 1024px) {
    .sbn-panels-grid {
        grid-template-columns: 0.9fr 1.4fr;
        align-items: stretch;
    }
}

.sbn-panel {
    background: var(--clr-white);
    border: 1px solid var(--clr-border);
    border-radius: 12px;
    padding: 24px;
    display: flex;
    flex-direction: column;
    height: 100%;
}

.sbn-panel--rhythm {
    border: 1px solid var(--clr-border);
    background: var(--clr-white);
    box-shadow: none;
}

.sbn-panel-title {
    font-size: 14px;
    font-weight: 700;
    color: var(--clr-text);
    margin-bottom: 16px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.sbn-panel-content {
    flex: 1;
    display: flex;
    flex-direction: column;
    justify-content: flex-start;
    align-items: center;
}

.sbn-progression-wrapper, .sbn-rhythm-wrapper {
    width: 100%;
    display: flex;
    flex-direction: column;
    align-items: stretch;
    padding: 10px 0 20px;
}

.sbn-progression-large {
    --tile-size: 150px !important;
}

.sbn-panel-caption {
    font-size: 14px;
    color: var(--clr-text);
    line-height: 1.6;
    margin-top: 16px;
    text-align: center;
}

.sbn-panel-citation {
    margin-top: 12px;
    padding-top: 12px;
    border-top: 1px dashed var(--clr-border);
    font-size: 13px;
    color: var(--clr-text-muted);
    font-style: italic;
    text-align: center;
    width: 100%;
}

/* Blend slider — handled by RhythmPattern :deep styles */

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
    padding: 10px 24px;
    border-radius: var(--radius-sm);
    font-weight: 600;
    font-size: 14px;
    border: none;
    cursor: pointer;
    transition: all 0.2s;
}

.sbn-nav-btn--outline {
    background: var(--clr-white);
    color: var(--clr-text);
    border: 1.5px solid var(--clr-border);
}

.sbn-nav-btn--outline:hover {
    border-color: var(--clr-text);
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

.sbn-related-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 16px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    text-decoration: none;
    transition: border-color 0.2s;
}

.sbn-related-item:hover {
    border-color: var(--clr-accent);
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
    margin-top: 60px;
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
    color: var(--clr-border);
    font-size: 20px;
}

@media (max-width: 767px) {
    .sbn-footer-separator {
        display: none;
    }
}
</style>
