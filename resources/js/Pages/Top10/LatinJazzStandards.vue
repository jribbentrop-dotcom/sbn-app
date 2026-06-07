<script setup lang="ts">
import { computed, ref, watch, onMounted } from 'vue';
import { Link, Head } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import ChordProgressionViewer from '@/Components/Library/ChordProgressionViewer.vue';
import RhythmPattern from '@/Components/Library/RhythmPattern.vue';
import { getCategoryColor } from '@/composables/useCategoryColors';
import { getAudioEngine } from '@/audio/engine/AudioEngine';
import type { ChordDiagramData } from '@/Components/Library/ChordDiagram.vue';
import type { ProgressionChord, VideoSnippet } from '@/Components/Library/ChordProgressionViewer.vue';

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
    voicingData: ChordDiagramData | null;
    voicingName: string;
    voicingCaption: string;
    progressionName: string;
    progressionCaption: string;
    progressionSeedKey?: string;
    progressionTiles: Array<{ chordName: string; diagramData: ChordDiagramData | null; numeral?: string }>;
    progressionMeta?: {
        name: string;
        numerals: string;
        slug: string;
        category?: string;
        videoSnippets?: VideoSnippet[];
    } | null;
    rhythmData: any | null;
    rhythmName: string;
    rhythmCaption: string;
    rhythmCitation?: string;
    progressionCitation?: string;
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
        <title>TOP 10 Latin Jazz Standards | Soul Bossa Nova</title>
        <meta name="description" content="Explore the essential TOP 10 Latin Jazz Standards, featuring voicings, progressions, and history for guitarists." />
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
                            <h3 class="sbn-panel-title">Key chord progression</h3>
                            <p class="sbn-panel-caption" v-html="selectedChord.progressionCaption"></p>
                            <div class="sbn-progression-wrapper">
                                <ChordProgressionViewer
                                    :chords="selectedChord.progressionTiles.map((t): ProgressionChord => ({ 
                                        chordName: t.chordName, 
                                        diagramData: t.diagramData, 
                                        beats: 4,
                                        numeral: t.numeral 
                                    }))"
                                    :interactive="true"
                                    :show-flow-arrows="selectedChord.progressionTiles.length > 2"
                                    :vintage-card="true"
                                    :color="getCategoryColor(selectedChord.progressionMeta?.category || 'latin')"
                                    :name="selectedChord.progressionName"
                                    :category="selectedChord.progressionMeta?.category"
                                    :key-label="selectedChord.progressionSeedKey ? `Key: ${selectedChord.progressionSeedKey}` : ''"
                                    :numerals="selectedChord.progressionMeta?.numerals"

                                    class="sbn-progression-large"
                                />
                            </div>
                            <div v-if="selectedChord.progressionCitation" class="sbn-panel-citation" v-html="selectedChord.progressionCitation"></div>
                        </div>

                        <!-- Rhythm Panel -->
                        <div v-if="selectedChord.rhythmData" class="sbn-panel-ghost">
                            <h3 class="sbn-panel-title">{{ selectedChord.rhythmName }}</h3>
                            <p class="sbn-panel-caption" v-html="selectedChord.rhythmCaption"></p>
                            <div class="sbn-rhythm-wrapper">
                                <div class="sbn-card sbn-rhythm-card">
                                    <RhythmPattern
                                        :pattern="selectedChord.rhythmData"
                                        :playable="true"
                                        :mini="false"
                                        :demo-url="selectedChord.rhythmData.demoUrl"
                                        :color="getCategoryColor(selectedChord.rhythmData.styleSlug || 'latin')"
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
                            </div>
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
                        <Link href="/top10/latin-jazz-standards" class="sbn-footer-link sbn-footer-link--active">TOP 10 Latin Jazz Standards</Link>
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
/* Rhythm/progression wrappers */
.sbn-progression-wrapper,
.sbn-rhythm-wrapper {
    width: 100%;
    display: flex;
    flex-direction: column;
    align-items: stretch;
    padding: 10px 0 20px;
}

.sbn-rhythm-card {
    overflow: hidden;
}

.sbn-progression-large {
    --tile-size: 150px !important;
}
</style>
