<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import { getCategoryColor } from '@/composables/useCategoryColors';

defineOptions({ layout: PublicLayout });

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
    progressions: ProgressionData[];
    categories: string[];
    tags: string[];
    totalCount: number;
    activeFilters: {
        category?: string;
        search?: string;
        sort?: string;
    };
}

const props = defineProps<Props>();

// ── Filter state ───────────────────────────────────────────
const search = ref(props.activeFilters.search || '');
const fCategory = ref(props.activeFilters.category || '');
const fSort = ref(props.activeFilters.sort || 'popularity');

// ── Category labels ─────────────────────────────────────────
const categoryLabels: Record<string, string> = {
    'bossa-nova': 'Bossa Nova',
    'jazz':       'Jazz',
    'classical':  'Classical',
    'pop':        'Pop',
};

// ── Filtered and sorted progressions ─────────────────────────────
const filteredProgressions = computed(() => {
    let result = [...props.progressions];

    // Filter by category
    if (fCategory.value) {
        result = result.filter(p => p.category === fCategory.value);
    }

    // Filter by search
    if (search.value) {
        const searchTerm = search.value.toLowerCase();
        result = result.filter(p => 
            p.name.toLowerCase().includes(searchTerm) ||
            p.numerals.toLowerCase().includes(searchTerm) ||
            p.description?.toLowerCase().includes(searchTerm) ||
            p.typicalGenres?.toLowerCase().includes(searchTerm) ||
            p.tags.some(tag => tag.toLowerCase().includes(searchTerm))
        );
    }

    // Apply sorting
    if (fSort.value === 'name') {
        result.sort((a, b) => a.name.localeCompare(b.name));
    } else if (fSort.value === 'category') {
        result.sort((a, b) => a.category.localeCompare(b.category));
    } else {
        // Default: sort by song count (popularity)
        result.sort((a, b) => b.songCount - a.songCount);
    }

    return result;
});

const totalFiltered = computed(() => filteredProgressions.value.length);

// ── Popularity tier calculation ───────────────────────────────
function getPopularityTier(songCount: number): { tier: string; label: string } {
    if (songCount >= 10) return { tier: 'iconic',     label: 'Iconic' };
    if (songCount >= 5)  return { tier: 'essential',  label: 'Essential' };
    if (songCount >= 2)  return { tier: 'common',     label: 'Common' };
    return                      { tier: 'occasional', label: 'Rare' };
}

// ── Example search queries ─────────────────────────────────────
const exampleQueries = [
    { query: 'ii v i', label: 'ii–V–I' },
    { query: 'secondary dominant', label: 'Secondary dominants' },
    { query: 'blues', label: 'Blues' },
    { query: 'minor subdominant', label: 'Minor subdominant' },
];

// ── URL updates ─────────────────────────────────────────────
watch([search, fCategory, fSort], () => {
    const params: Record<string, any> = {};
    
    if (search.value) params.search = search.value;
    if (fCategory.value) params.category = fCategory.value;
    if (fSort.value && fSort.value !== 'popularity') params.sort = fSort.value;
    
    router.get('/library/progressions', params, {
        preserveState: true,
        replace: true,
    });
}, { deep: true });

// ── Filter management ───────────────────────────────────────────
function clearFilters() {
    search.value = '';
    fCategory.value = '';
    fSort.value = 'popularity';
}

function setExampleQuery(query: string) {
    search.value = query;
}

</script>

<template>
    <div class="sbn-page sbn-prog-lib" id="sbn-prog-lib">
        <!-- Page Header -->
        <div class="sbn-lib-page-header">
            <h1 class="sbn-lib-page-title">Chord Progression Library</h1>
            <p class="sbn-lib-page-subtitle">
                Explore the harmonic building blocks of jazz, bossa nova, blues and beyond —
                ranked by how often they appear in the song library.
            </p>

            <div class="sbn-lib-search-wrap">
                <div class="sbn-lib-search-box">
                    <svg class="sbn-lib-search-icon" width="18" height="18" viewBox="0 0 20 20" fill="none">
                        <circle cx="9" cy="9" r="6" stroke="currentColor" stroke-width="1.8"/>
                        <path d="M15 15l3.5 3.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                    </svg>
                    <input
                        v-model="search"
                        type="text"
                        class="sbn-lib-search-input"
                        placeholder="Search progressions, degrees (IIm7 V7), keywords…"
                        autocomplete="off"
                    />
                    <button
                        v-if="search"
                        @click="search = ''"
                        class="sbn-lib-search-clear"
                        aria-label="Clear search"
                    >
                        <svg width="12" height="12" viewBox="0 0 14 14" fill="none">
                            <path d="M1 1l12 12M13 1L1 13" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                    </button>
                </div>
            </div>
        </div>

        <!-- Content Wrapper -->
        <div class="sbn-lib-content-wrapper">
            <!-- Progressions List -->
            <div class="sbn-lib-list-container">
                <!-- Progressions List -->
                <div class="sbn-lib-hitlist" role="list">
                    <div
                        v-for="(progression, index) in filteredProgressions"
                        :key="progression.id"
                        class="sbn-lib-row"
                        :id="`prog-${progression.id}`"
                        :data-id="progression.id"
                        :data-rank="index + 1"
                        :data-category="progression.category"
                        :data-name="progression.name.toLowerCase()"
                        :data-numerals="progression.numerals.toLowerCase()"
                        :data-tags="progression.tags.join(',').toLowerCase()"
                        :data-genres="(progression.typicalGenres || '').toLowerCase()"
                        :data-desc="(progression.description || '').toLowerCase()"
                        :data-song-count="progression.songCount"
                        :data-tonality="progression.tonality || 'both'"
                        role="listitem"
                    >
                        <!-- Rank Number -->
                        <div class="sbn-hitlist-rank">{{ index + 1 }}</div>

                        <!-- Row Body -->
                        <div class="sbn-lib-row-body">
                            <!-- Top badges -->
                            <div class="sbn-lib-row-top">
                                <span class="sbn-cat-badge sbn-cat-badge-filled" :style="{ '--cat-clr': getCategoryColor(progression.category) }">
                                    {{ categoryLabels[progression.category] || progression.category }}
                                </span>
                                <span
                                    v-if="progression.tonality && progression.tonality !== 'both'"
                                    class="sbn-badge"
                                    :class="progression.tonality === 'major' ? 'sbn-badge-tonality-major' : 'sbn-badge-tonality-minor'"
                                >
                                    {{ progression.tonality === 'major' ? 'Major' : 'Minor' }}
                                </span>
                                <span
                                    v-for="tag in progression.tags.slice(0, 3)"
                                    :key="tag"
                                    class="sbn-hashtag"
                                >#{{ tag }}</span>
                            </div>

                            <!-- Title -->
                            <h3 class="sbn-lib-row-title">
                                <Link :href="`/library/progressions/${progression.slug}`" class="sbn-lib-row-link">
                                    {{ progression.name }}
                                </Link>
                            </h3>

                            <!-- Numerals -->
                            <div class="sbn-prog-row-numerals">
                                <span 
                                    v-for="(numeral, idx) in progression.numeralsDisplay.split('–')"
                                    :key="idx"
                                    class="sbn-numeral-chip"
                                >
                                    <span class="sbn-chord-symbol">{{ numeral }}</span>
                                </span>
                            </div>

                            <!-- Popularity phrase -->
                            <p v-if="progression.songCount > 0" class="sbn-lib-row-popularity-phrase">
                                This is
                                <span
                                    class="sbn-card-pop"
                                    :class="`sbn-pop-${getPopularityTier(progression.songCount).tier}`"
                                >{{ getPopularityTier(progression.songCount).label }}</span>
                                chord progression that appears in
                                <strong>{{ progression.songCount }} song{{ progression.songCount !== 1 ? 's' : '' }}</strong>
                                in the library.
                            </p>

                            <!-- Read more link -->
                            <Link :href="`/library/progressions/${progression.slug}`" class="sbn-lib-row-read-more">
                                Read more about the {{ progression.name }} progression
                                <svg width="13" height="13" viewBox="0 0 16 16" fill="none">
                                    <path d="M3 8h10M9 4l4 4-4 4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                            </Link>
                        </div>
                    </div>
                </div>

                <!-- Empty State -->
                <div v-if="totalFiltered === 0" class="sbn-lib-no-results">
                    <h3>No progressions match your filters.</h3>
                    <p>Try adjusting your search or filters.</p>
                </div>
            </div>

            <!-- Filter Sidebar -->
            <aside class="sbn-lib-filter-sidebar" id="sbn-prog-filter-sidebar">
                <div class="sbn-lib-sidebar-header">
                    <h3>Filter</h3>
                    <span class="sbn-lib-sidebar-count">
                        {{ totalFiltered }} progression{{ totalFiltered !== 1 ? 's' : '' }}
                        <button v-if="search || fCategory" @click="clearFilters" class="sbn-lib-clear-btn">Clear</button>
                    </span>
                </div>

                <!-- Style/Category Filter -->
                <div class="sbn-lib-sidebar-section">
                    <p class="sbn-lib-sidebar-label">Style</p>
                    <div class="sbn-lib-sidebar-options">
                        <button
                            v-for="category in categories"
                            :key="category"
                            :class="['sbn-lib-sidebar-option', { 'sbn-filter-active': fCategory === category }]"
                            :style="{ '--cat-clr': getCategoryColor(category) }"
                            @click="fCategory = fCategory === category ? '' : category"
                        >
                            {{ categoryLabels[category] || category }}
                        </button>
                    </div>
                </div>

                <!-- Tag Filter -->
                <div class="sbn-lib-sidebar-section">
                    <p class="sbn-lib-sidebar-label">Keywords</p>
                    <div class="sbn-lib-sidebar-options">
                        <button
                            v-for="tag in tags.slice(0, 10)"
                            :key="tag"
                            class="sbn-lib-sidebar-option"
                            @click="() => {}"
                        >
                            #{{ tag }}
                        </button>
                        <p v-if="tags.length === 0" class="sbn-prog-tags-empty-note">
                            Add keyword tags to progressions in the admin to enable this filter.
                        </p>
                    </div>
                </div>

                <!-- Tonality Filter -->
                <div class="sbn-lib-sidebar-section">
                    <p class="sbn-lib-sidebar-label">Tonality</p>
                    <div class="sbn-lib-sidebar-options">
                        <button class="sbn-lib-sidebar-option" @click="() => {}">
                            Major
                        </button>
                        <button class="sbn-lib-sidebar-option" @click="() => {}">
                            Minor
                        </button>
                    </div>
                </div>

                <!-- Sort Options -->
                <div class="sbn-lib-sidebar-section">
                    <p class="sbn-lib-sidebar-label">Sort by</p>
                    <div class="sbn-lib-sidebar-options">
                        <button
                            :class="['sbn-lib-sidebar-option', { 'sbn-sort-active': fSort === 'popularity' }]"
                            @click="fSort = 'popularity'"
                        >
                            Most songs
                        </button>
                        <button
                            :class="['sbn-lib-sidebar-option', { 'sbn-sort-active': fSort === 'name' }]"
                            @click="fSort = 'name'"
                        >
                            A–Z
                        </button>
                        <button
                            :class="['sbn-lib-sidebar-option', { 'sbn-sort-active': fSort === 'category' }]"
                            @click="fSort = 'category'"
                        >
                            Style
                        </button>
                    </div>
                </div>

                <button
                    v-if="search || fCategory"
                    @click="clearFilters"
                    class="sbn-lib-sidebar-clear"
                >
                    Clear all filters
                </button>
            </aside>
        </div>
    </div>
</template>

<style scoped>
/* ── Progression-specific: numeral chips ── */
.sbn-prog-row-numerals {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
    margin-bottom: 10px;
}

@media (max-width: 600px) {
    .sbn-prog-lib { padding: 0 12px 48px; }
}
</style>
