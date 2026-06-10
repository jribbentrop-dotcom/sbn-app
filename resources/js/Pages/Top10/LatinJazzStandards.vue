<script setup lang="ts">
import { ref, watch, onMounted } from 'vue';
import { Link, Head, router } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import Top10HeaderBar from '@/Components/Top10/Top10HeaderBar.vue';
import ChordCard from '@/Components/Library/ChordCard.vue';
import SyncedPlayer from '@/Components/SyncedPlayer/SyncedPlayer.vue';
import type { LeadsheetBar } from '@/Components/SyncedPlayer/SyncedPlayer.vue';
import type { ChordDiagramData } from '@/Components/Library/ChordDiagram.vue';
import type { VideoSnippet } from '@/Components/Library/ChordProgressionViewer.vue';
import type { RhythmPatternData } from '@/Components/Library/RhythmPattern.vue';
import { chordShowUrl } from '@/composables/useChordUrl';

defineOptions({ layout: PublicLayout });

interface RelatedProduct {
    title: string;
    description: string;
    url: string;
    type: string;
}

interface SyncedPlayerConfig {
    slug: string;
    type?: string;
    start?: number;
    end?: number;
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
    syncedPlayer?: SyncedPlayerConfig | null;
}

const props = defineProps<{
    top10Data: Top10DataItem[];
    rhythmPattern: RhythmPatternData | null;
}>();

const chords = ref<Top10DataItem[]>([]);
const selectedChord = ref<Top10DataItem | null>(null);
const isLoading = ref(false);

// ── Synced player bars fetch ──────────────────────────────────────────────────
const syncedBars = ref<LeadsheetBar[] | null>(null);
const syncedRhythm = ref<RhythmPatternData | null>(null);
const syncedFetching = ref(false);
let lastFetchedKey = '';

async function fetchSyncedBars(cfg: SyncedPlayerConfig) {
    const key = `${cfg.slug}:${cfg.start ?? ''}:${cfg.end ?? ''}`;
    if (key === lastFetchedKey) return;
    lastFetchedKey = key;
    syncedBars.value = null;
    syncedFetching.value = true;
    try {
        const params = new URLSearchParams({ type: cfg.type ?? 'leadsheet' });
        if (cfg.start != null) params.set('start', String(cfg.start));
        if (cfg.end   != null) params.set('end',   String(cfg.end));
        const res = await fetch(`/api/sbn/synced-player/${cfg.slug}?${params}`, {
            headers: { Accept: 'application/json' },
        });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        const data = await res.json();
        syncedBars.value   = data.bars ?? [];
        syncedRhythm.value = data.rhythmPattern ?? null;
    } catch (e) {
        console.warn('[LatinJazzStandards] synced-player fetch failed', e);
    } finally {
        syncedFetching.value = false;
    }
}

onMounted(() => {
    chords.value = props.top10Data.map((item, index) => ({
        ...item,
        id: index + 1,
    }));
    selectedChord.value = chords.value[0] || null;
    if (selectedChord.value?.syncedPlayer) {
        fetchSyncedBars(selectedChord.value.syncedPlayer);
    }
});

watch(() => selectedChord.value, (item) => {
    syncedBars.value = null;
    lastFetchedKey = '';
    if (item?.syncedPlayer) fetchSyncedBars(item.syncedPlayer);
});

function selectChord(chord: Top10DataItem) {
    selectedChord.value = chord;
}

function goToChordLibrary(chord: ChordDiagramData) {
    if (!chord.slug) return;
    router.visit(chordShowUrl(chord));
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
        <Top10HeaderBar active="/top10/latin-jazz-standards" />

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
                        <!-- Voicing Panel -->
                        <div v-if="selectedChord.voicingData" class="sbn-panel sbn-voicing-panel">
                            <h3 class="sbn-panel-title">{{ selectedChord.voicingName || 'Chord Voicing' }}</h3>
                            <div class="sbn-panel-content">
                                <ChordCard
                                    :chord="selectedChord.voicingData"
                                    :show-root="true"
                                    :on-chord-click="() => goToChordLibrary(selectedChord!.voicingData!)"
                                />
                                <p class="sbn-panel-caption" v-html="selectedChord.voicingCaption"></p>
                            </div>
                        </div>

                        <!-- Progression Panel -->
                        <div class="sbn-panel">
                            <h3 class="sbn-panel-title">{{ selectedChord.progressionName }}</h3>
                            <div class="sbn-panel-content">
                                <div class="sbn-synced-hero-card">
                                    <!-- Live leadsheet bars mode -->
                                    <SyncedPlayer
                                        v-if="selectedChord.syncedPlayer && syncedBars && syncedBars.length > 0"
                                        :bars="syncedBars"
                                        :rhythm-pattern="(syncedRhythm ?? props.rhythmPattern) ?? undefined"
                                        :autoplay="false"
                                        :loop="true"
                                        :key="selectedChord.slug + '-synced'"
                                    />
                                    <!-- Loading placeholder -->
                                    <div v-else-if="selectedChord.syncedPlayer && syncedFetching" class="sbn-synced-loading">
                                        <div class="sbn-spinner"></div>
                                    </div>
                                    <!-- Static progression fallback -->
                                    <SyncedPlayer
                                        v-else-if="!selectedChord.syncedPlayer && selectedChord.progressionTiles.length > 0"
                                        :progression="selectedChord.progressionTiles.map(t => t.diagramData).filter(Boolean) as ChordDiagramData[]"
                                        :rhythm-pattern="props.rhythmPattern ?? undefined"
                                        :bars-per-chord="1"
                                        :autoplay="false"
                                        :key="selectedChord.slug"
                                    />
                                </div>
                                <p class="sbn-panel-caption" v-html="selectedChord.progressionCaption"></p>
                                <div v-if="selectedChord.progressionCitation" class="sbn-panel-citation" v-html="selectedChord.progressionCitation"></div>
                            </div>
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
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
/* Two-panel layout matching Bossa Nova Chords (voicing left, progression right) */
.sbn-panels-grid {
    gap: 20px;
    margin-bottom: 32px;
}

@media (min-width: 768px) {
    .sbn-panels-grid {
        grid-template-columns: 3fr 7fr;
    }
}

.sbn-panel {
    padding: 20px;
}

.sbn-panel-title {
    font-weight: 600;
    margin-bottom: 12px;
    letter-spacing: 0;
}

.sbn-panel-content {
    gap: 16px;
    justify-content: center;
}

.sbn-synced-hero-card {
    width: 100%;
}

.sbn-synced-loading {
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 200px;
}

.sbn-panel-caption {
    color: var(--clr-text-muted);
    margin-top: 12px;
}

/* Voicing panel: no border on mobile, card fills available width */
.sbn-voicing-panel {
    border: none;
    padding: 16px;
    align-items: center;
    --card-name-size: 1.75rem;
}

@media (min-width: 768px) {
    .sbn-voicing-panel {
        --card-name-size: 1.25rem;
    }
}

.sbn-voicing-panel .sbn-panel-title {
    text-align: center;
}

.sbn-voicing-panel .sbn-panel-content {
    align-items: center;
}

/* On desktop, restore the panel box and cap the card width */
@media (min-width: 768px) {
    .sbn-voicing-panel {
        background: var(--clr-surface-2);
        border: 1px solid var(--clr-border);
        border-radius: var(--radius);
        padding: 20px;
    }

    .sbn-voicing-panel :deep(.sbn-chord-card) {
        max-width: 200px;
        width: 100%;
    }
}
</style>
