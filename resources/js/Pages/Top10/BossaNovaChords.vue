<script setup lang="ts">
import { ref, onMounted, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import Top10HeaderBar from '@/Components/Top10/Top10HeaderBar.vue';
import ChordCard from '@/Components/Library/ChordCard.vue';
import SyncedPlayer from '@/Components/SyncedPlayer/SyncedPlayer.vue';
import type { LeadsheetBar } from '@/Components/SyncedPlayer/SyncedPlayer.vue';
import type { ChordDiagramData } from '@/Components/Library/ChordDiagram.vue';
import type { RhythmPatternData } from '@/Components/Library/RhythmPattern.vue';
import type { VideoSnippet } from '@/Components/Library/ChordProgressionViewer.vue';
import { chordShowUrl } from '@/composables/useChordUrl';

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
        videoSnippets?: VideoSnippet[];
    } | null;
    relatedProducts: RelatedProduct[];
}

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
        videoSnippets?: VideoSnippet[];
    } | null;
    citation?: string;
    relatedProducts: RelatedProduct[];
    syncedPlayer?: SyncedPlayerConfig | null;
}

const props = defineProps<{
    top10Data: Top10DataItem[];
    rhythmPattern: RhythmPatternData | null;
}>();

const chords = ref<Top10ChordWithDetail[]>([]);
const selectedChord = ref<Top10ChordWithDetail | null>(null);
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
        console.warn('[BossaNovaChords] synced-player fetch failed', e);
    } finally {
        syncedFetching.value = false;
    }
}

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
        citation: item.citation,
        relatedProducts: item.relatedProducts,
        syncedPlayer: item.syncedPlayer,
    }));
    selectedChord.value = chords.value[0] || null;
    if (selectedChord.value?.syncedPlayer) {
        fetchSyncedBars(selectedChord.value.syncedPlayer);
    }
}

watch(() => selectedChord.value, (item) => {
    syncedBars.value = null;
    lastFetchedKey = '';
    if (item?.syncedPlayer) fetchSyncedBars(item.syncedPlayer);
});

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
    router.visit(chordShowUrl(chord));
}
</script>

<template>
    <Head>
        <title>10 Essential Bossa Nova Guitar Chords | Soul Bossa Nova</title>
        <meta name="description" content="The 10 most important Bossa Nova guitar chords — authentic voicings, the progressions they appear in, and the songs that made them famous. Build your chord vocabulary here." />
        <meta property="og:title" content="10 Essential Bossa Nova Guitar Chords | Soul Bossa Nova" />
        <meta property="og:description" content="Maj7, m7, dom7 and more — the 10 Bossa Nova chords every guitarist needs, with voicings, progressions and song context." />
        <meta property="og:type" content="website" />
        <meta property="og:url" content="https://www.soulbossanova.com/top10/bossa-nova-chords" />
        <meta property="og:image" content="https://www.soulbossanova.com/images/products/thumbnails/top10-bossa-nova-chords.webp" />
    </Head>

    <div class="sbn-top10-page">
        <Top10HeaderBar active="/top10/bossa-nova-chords" />

        <!-- Static intro — visible to search engines and no-JS users -->
        <div class="sbn-top10-intro">
            <h1 class="sbn-top10-intro-title">10 Essential Bossa Nova Guitar Chords</h1>
            <p class="sbn-top10-intro-text">The most important <Link href="/library/chords" class="sbn-intro-link">chords in Bossa Nova guitar</Link> — with authentic voicings, the <Link href="/library/progressions" class="sbn-intro-link">progressions</Link> they appear in, and the <Link href="/library/songs" class="sbn-intro-link">songs</Link> that made them famous. Whether you're building your chord vocabulary or refining your jazz sound, start here.</p>
        </div>

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
                        <div class="sbn-nav-card-artist">{{ chord.shortTitle }}</div>
                    </button>
                </div>

                <!-- Detail View -->
                <div v-if="selectedChord" class="sbn-top10-detail">
                    <div class="sbn-detail-header">
                        <span class="sbn-detail-badge">CHORD #{{ selectedChord.id }}</span>
                        <h1 class="sbn-detail-title">{{ selectedChord.title }}</h1>
                        <p class="sbn-detail-description" v-html="selectedChord.description"></p>
                    </div>

                    <!-- Panels Grid -->
                    <div class="sbn-panels-grid">
                        <!-- Voicing Panel -->
                        <div v-if="selectedChord.voicingData" class="sbn-panel sbn-voicing-panel">
                            <h3 class="sbn-panel-title">Chord Voicing</h3>
                            <div class="sbn-panel-content">
                                <ChordCard
                                    :chord="selectedChord.voicingData"
                                    :show-root="true"
                                    :on-chord-click="() => goToChordLibrary(selectedChord!.voicingData!)"
                                />
                                <p class="sbn-panel-caption" v-html="selectedChord.voicingCaption"></p>
                                <Link v-if="selectedChord.voicingData?.slug" :href="chordShowUrl(selectedChord.voicingData)" class="sbn-panel-link">Explore in Chord Library →</Link>
                            </div>
                        </div>

                        <!-- Progression Panel -->
                        <div class="sbn-panel">
                            <div class="sbn-panel-heading">
                                <h3 class="sbn-panel-title"><span class="sbn-panel-label">Key Chord Progression:</span> <Link v-if="selectedChord.progressionMeta?.slug" :href="`/library/progressions/${selectedChord.progressionMeta.slug}`" class="sbn-panel-title-link">{{ selectedChord.progressionName }}</Link><span v-else>{{ selectedChord.progressionName }}</span></h3>
                            </div>
                            <div class="sbn-panel-content">
                                <p class="sbn-panel-caption sbn-progression-caption" v-html="selectedChord.progressionCaption"></p>
                                <div class="sbn-synced-hero-card">
                                    <!-- Live leadsheet bars mode -->
                                    <SyncedPlayer
                                        v-if="selectedChord.syncedPlayer && syncedBars && syncedBars.length > 0"
                                        :bars="syncedBars"
                                        :rhythm-pattern="(syncedRhythm ?? props.rhythmPattern) ?? undefined"
                                        :autoplay="false"
                                        :loop="true"
                                        :key="selectedChord.slug + '-synced'"
                                        :citation="selectedChord.citation"
                                        :start-chord-name="selectedChord.voicingData?.name ?? undefined"
                                    />
                                    <!-- Loading placeholder -->
                                    <div v-else-if="selectedChord.syncedPlayer && syncedFetching" class="sbn-synced-loading">
                                        <div class="sbn-spinner"></div>
                                    </div>
                                    <!-- Static progression fallback -->
                                    <SyncedPlayer
                                        v-else
                                        :progression="selectedChord.progressionTiles.map(t => t.diagramData).filter(Boolean) as ChordDiagramData[]"
                                        :rhythm-pattern="props.rhythmPattern ?? undefined"
                                        :bars-per-chord="1"
                                        :autoplay="false"
                                        :key="selectedChord.slug"
                                        :citation="selectedChord.citation"
                                    />
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Navigation Buttons -->
                    <div class="sbn-detail-nav">
                        <button @click="prevChord" class="sbn-nav-btn sbn-nav-btn--outline">← Previous Chord</button>
                        <button @click="nextChord" class="sbn-nav-btn sbn-nav-btn--outline">Next Chord →</button>
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
/* Chords page has a side-by-side panel layout (voicing left, progression right) */
.sbn-panels-grid {
    gap: 20px;
    margin-bottom: 32px;
}

@media (min-width: 768px) {
    .sbn-panels-grid {
        grid-template-columns: 3fr 7fr;
    }
}

/* Chords page uses tighter panel padding and slightly different typography */
.sbn-panel {
    padding: 20px;
}

.sbn-panel-title {
    font-weight: 600;
    margin-bottom: 12px;
    letter-spacing: 0;
}

.sbn-intro-link {
    color: var(--sbn-accent);
    text-decoration: underline;
    text-underline-offset: 2px;
}

.sbn-panel-link {
    display: inline-block;
    margin-top: 10px;
    font-size: 0.82rem;
    font-weight: 600;
    color: var(--sbn-accent);
    text-decoration: none;
    letter-spacing: 0.02em;
}
.sbn-panel-link:hover { text-decoration: underline; }

.sbn-panel-heading {
    margin-bottom: 12px;
}
.sbn-panel-heading .sbn-panel-title {
    margin-bottom: 2px;
}
.sbn-panel-label {
    color: var(--sbn-text-muted, #888);
    font-weight: 400;
}
.sbn-panel-sublink {
    display: inline-block;
    font-size: 0.78rem;
    font-weight: 400;
    color: var(--sbn-text-muted, #999);
    text-decoration: none;
    letter-spacing: 0.01em;
}
.sbn-panel-sublink:hover { color: var(--sbn-accent); text-decoration: underline; }

.sbn-panel-title-link {
    color: inherit;
    text-decoration: none;
}
.sbn-panel-title-link:hover { color: var(--sbn-accent); text-decoration: underline; }

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

.sbn-detail-description {
    color: var(--clr-text);
}

.sbn-panel-caption {
    color: var(--clr-text-dim);
    margin-top: 12px;
}

.sbn-progression-caption {
    margin-top: 0;
    margin-bottom: 14px;
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

/* Chords page detail-title has slightly more bottom margin */
.sbn-detail-title {
    margin-bottom: 8px;
}

/* Chords page description scales to 1.25rem (vs 1.15rem on songs/standards) */
@media (min-width: 768px) {
    .sbn-detail-description {
        font-size: 1.25rem;
    }
}

/* Active thumb title is bold on this page */
.sbn-nav-thumb-title {
    font-weight: normal;
}

.sbn-nav-thumb--active .sbn-nav-thumb-title {
    font-weight: bold;
}

/* ── Static intro ─────────────────────────────────────────────────────────── */
.sbn-top10-intro {
    max-width: 760px;
    margin: 0 auto;
    padding: 32px 20px 0;
    text-align: center;
}

.sbn-top10-intro-title {
    font-size: clamp(1.4rem, 3vw, 2rem);
    font-weight: 700;
    margin-bottom: 12px;
    color: var(--clr-text);
}

.sbn-top10-intro-text {
    color: var(--clr-text-muted);
    font-size: 1rem;
    line-height: 1.6;
    margin-bottom: 0;
}
</style>
