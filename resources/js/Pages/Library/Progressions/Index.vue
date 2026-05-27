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
        <div class="sbn-prog-lib-page-header">
            <h1 class="sbn-prog-lib-page-title">Chord Progression Library</h1>
            <p class="sbn-prog-lib-page-subtitle">
                Explore the harmonic building blocks of jazz, bossa nova, blues and beyond —
                ranked by how often they appear in the song library.
            </p>

            <div class="sbn-prog-lib-search-wrap">
                <div class="sbn-prog-lib-search-box">
                    <svg class="sbn-prog-search-icon" width="18" height="18" viewBox="0 0 20 20" fill="none">
                        <circle cx="9" cy="9" r="6" stroke="currentColor" stroke-width="1.8"/>
                        <path d="M15 15l3.5 3.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                    </svg>
                    <input 
                        v-model="search"
                        type="text"
                        class="sbn-prog-search-input"
                        placeholder="Search progressions, degrees (IIm7 V7), keywords…"
                        autocomplete="off"
                    />
                    <button 
                        v-if="search"
                        @click="search = ''"
                        class="sbn-prog-search-clear"
                        aria-label="Clear search"
                    >
                        <svg width="12" height="12" viewBox="0 0 14 14" fill="none">
                            <path d="M1 1l12 12M13 1L1 13" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                    </button>
                </div>
                <div class="sbn-prog-search-examples">
                    <span>Try:</span>
                    <button 
                        v-for="example in exampleQueries"
                        :key="example.query"
                        @click="setExampleQuery(example.query)"
                        class="sbn-prog-example-btn"
                    >
                        {{ example.label }}
                    </button>
                </div>
            </div>
        </div>

        <!-- Content Wrapper -->
        <div class="sbn-prog-content-wrapper">
            <!-- Progressions List -->
            <div class="sbn-prog-list-container">
                <!-- Status Bar -->
                <div class="sbn-prog-list-status">
                    <span id="sbn-prog-count-text">{{ totalFiltered }} progression{{ totalFiltered !== 1 ? 's' : '' }}</span>
                    <button 
                        v-if="search || fCategory"
                        @click="clearFilters"
                        class="sbn-prog-clear-filters"
                    >
                        Clear filters
                    </button>
                </div>

                <!-- Progressions List -->
                <div class="sbn-prog-list" role="list">
                    <div 
                        v-for="(progression, index) in filteredProgressions"
                        :key="progression.id"
                        class="sbn-prog-row"
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
                        <div class="sbn-prog-row-rank">{{ index + 1 }}</div>

                        <!-- Row Body -->
                        <div class="sbn-prog-row-body">
                            <!-- Top badges -->
                            <div class="sbn-prog-row-top">
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
                            <h3 class="sbn-prog-row-title">
                                <Link :href="`/library/progressions/${progression.slug}`" class="sbn-prog-row-link">
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
                            <p v-if="progression.songCount > 0" class="sbn-prog-row-popularity-phrase">
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
                            <Link :href="`/library/progressions/${progression.slug}`" class="sbn-prog-row-read-more">
                                Read more about the {{ progression.name }} progression
                                <svg width="13" height="13" viewBox="0 0 16 16" fill="none">
                                    <path d="M3 8h10M9 4l4 4-4 4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                            </Link>
                        </div>
                    </div>
                </div>

                <!-- Empty State -->
                <div v-if="totalFiltered === 0" class="sbn-prog-lib-empty">
                    No progressions match your filters.
                </div>
            </div>

            <!-- Filter Sidebar -->
            <aside class="sbn-prog-filter-sidebar" id="sbn-prog-filter-sidebar">
                <div class="sbn-prog-sidebar-header">
                    <h3>Filter</h3>
                </div>

                <!-- Style/Category Filter -->
                <div class="sbn-prog-sidebar-section">
                    <p class="sbn-prog-sidebar-label">Style</p>
                    <div class="sbn-prog-sidebar-options" id="sbn-prog-cat-options">
                        <button
                            v-for="category in categories"
                            :key="category"
                            :class="['sbn-prog-sidebar-option', { 'sbn-filter-active': fCategory === category }]"
                            :style="{ '--cat-clr': getCategoryColor(category) }"
                            @click="fCategory = fCategory === category ? '' : category"
                        >
                            {{ categoryLabels[category] || category }}
                        </button>
                    </div>
                </div>

                <!-- Tag Filter -->
                <div class="sbn-prog-sidebar-section">
                    <p class="sbn-prog-sidebar-label">Keywords</p>
                    <div class="sbn-prog-sidebar-options" id="sbn-prog-tag-options">
                        <button
                            v-for="tag in tags.slice(0, 10)"
                            :key="tag"
                            class="sbn-prog-sidebar-option"
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
                <div class="sbn-prog-sidebar-section">
                    <p class="sbn-prog-sidebar-label">Tonality</p>
                    <div class="sbn-prog-sidebar-options">
                        <button class="sbn-prog-sidebar-option" @click="() => {}">
                            Major
                        </button>
                        <button class="sbn-prog-sidebar-option" @click="() => {}">
                            Minor
                        </button>
                    </div>
                </div>

                <!-- Sort Options -->
                <div class="sbn-prog-sidebar-section">
                    <p class="sbn-prog-sidebar-label">Sort by</p>
                    <div class="sbn-prog-sidebar-options" id="sbn-prog-sort-options">
                        <button 
                            :class="['sbn-prog-sidebar-option', { 'sbn-sort-active': fSort === 'popularity' }]"
                            @click="fSort = 'popularity'"
                        >
                            Most songs
                        </button>
                        <button 
                            :class="['sbn-prog-sidebar-option', { 'sbn-sort-active': fSort === 'name' }]"
                            @click="fSort = 'name'"
                        >
                            A–Z
                        </button>
                        <button 
                            :class="['sbn-prog-sidebar-option', { 'sbn-sort-active': fSort === 'category' }]"
                            @click="fSort = 'category'"
                        >
                            Style
                        </button>
                    </div>
                </div>

                <button 
                    v-if="search || fCategory"
                    @click="clearFilters"
                    class="sbn-prog-sidebar-clear"
                >
                    Clear all filters
                </button>
            </aside>
        </div>
    </div>
</template>

<style scoped>
.sbn-prog-lib-page-header {
    text-align: center;
    margin-bottom: 40px;
}

.sbn-prog-lib-page-title {
    font-size: 38px;
    font-weight: 900;
    color: #1a202c;
    margin: 0 0 10px;
    letter-spacing: -0.02em;
    line-height: 1.1;
}

.sbn-prog-lib-page-subtitle {
    font-size: 17px;
    color: #718096;
    margin: 0 auto 32px;
    font-weight: 400;
    line-height: 1.6;
    max-width: 600px;
}

.sbn-prog-lib-search-wrap {
    max-width: 640px;
    margin: 0 auto;
}

.sbn-prog-lib-search-box {
    display: flex;
    align-items: center;
    background: #fff;
    border: 2px solid #e2e8f0;
    border-radius: 16px;
    padding: 6px 6px 6px 20px;
    box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);
    transition: border-color 0.2s, box-shadow 0.2s;
    margin-bottom: 12px;
}

.sbn-prog-lib-search-box:focus-within {
    border-color: #e85d3b;
    box-shadow: 0 0 0 3px rgba(232,93,59,0.1);
}

.sbn-prog-search-icon {
    color: #a0aec0;
    flex-shrink: 0;
    margin-right: 12px;
    transition: color 0.2s;
}

.sbn-prog-lib-search-box:focus-within .sbn-prog-search-icon {
    color: #e85d3b;
}

.sbn-prog-search-input {
    flex: 1;
    border: none;
    outline: none;
    font-size: 17px;
    color: #2d3748;
    background: transparent;
    padding: 12px 0;
    font-weight: 500;
}

.sbn-prog-search-input::placeholder {
    color: #a0aec0;
    font-weight: 400;
}

.sbn-prog-search-clear {
    width: 34px;
    height: 34px;
    border-radius: 10px;
    border: none;
    background: #f7fafc;
    color: #718096;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: background 0.15s, color 0.15s;
    flex-shrink: 0;
}

.sbn-prog-search-clear:hover {
    background: #e85d3b;
    color: #fff;
}

.sbn-prog-search-examples {
    font-size: 13px;
    color: #718096;
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 6px;
    justify-content: center;
}

.sbn-prog-search-examples span {
    font-weight: 600;
    color: #4a5568;
}

.sbn-prog-example-btn {
    padding: 4px 12px;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    background: #fff;
    color: #4a5568;
    font-size: 12px;
    font-weight: 500;
    cursor: pointer;
    transition: border-color 0.15s, color 0.15s, background 0.15s;
}

.sbn-prog-example-btn:hover {
    border-color: #e85d3b;
    color: #e85d3b;
    background: #fff8f5;
}

.sbn-prog-content-wrapper {
    display: flex;
    gap: 24px;
    align-items: flex-start;
}

.sbn-prog-list-container {
    flex: 1;
    min-width: 0;
    order: 0;
}

.sbn-prog-list-status {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 16px;
    padding: 8px 0;
}

#sbn-prog-count-text {
    font-size: 13px;
    color: #718096;
    font-weight: 500;
}

.sbn-prog-clear-filters {
    border: 1px solid #e2e8f0;
    background: none;
    color: #718096;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    padding: 4px 10px;
    border-radius: 6px;
    transition: all 0.15s;
}

.sbn-prog-clear-filters:hover {
    color: #e85d3b;
    border-color: #e85d3b;
    background: #fff8f5;
}

.sbn-prog-list {
    display: flex;
    flex-direction: column;
}

.sbn-prog-row {
    display: flex;
    align-items: flex-start;
    gap: 16px;
    padding: 20px 20px 20px 0;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    background: #fff;
    margin-bottom: 8px;
    transition: border-color 0.15s;
    cursor: default;
}

.sbn-prog-row:hover {
    border-color: #e85d3b;
}

.sbn-prog-row-rank {
    flex-shrink: 0;
    width: 44px;
    text-align: center;
    font-size: 20px;
    font-weight: 900;
    color: #d1d5db;
    padding-top: 2px;
    font-variant-numeric: tabular-nums;
    letter-spacing: -0.03em;
    padding-left: 16px;
}

.sbn-prog-row-body {
    flex: 1;
    min-width: 0;
}

.sbn-prog-row-top {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 6px;
    margin-bottom: 6px;
}


.sbn-prog-row-title {
    font-size: 20px;
    font-weight: 800;
    color: #111827;
    margin: 0 0 8px;
    line-height: 1.2;
    letter-spacing: -0.01em;
}

.sbn-prog-row-link {
    color: inherit;
    text-decoration: none;
    transition: color 0.12s;
}

.sbn-prog-row-link:hover { 
    color: #e85d3b; 
    text-decoration: none; 
}

.sbn-prog-row-numerals {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
    margin-bottom: 10px;
}


.sbn-prog-row-popularity-phrase {
    font-size: 13px;
    color: #6b7280;
    line-height: 1.5;
    margin: 0;
}


.sbn-prog-row-read-more {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    font-size: 13px;
    font-weight: 600;
    color: #e85d3b;
    text-decoration: none;
    margin-top: 6px;
    transition: color 0.12s;
}

.sbn-prog-row-read-more:hover {
    color: #c0392b;
    text-decoration: none;
}

.sbn-prog-row-read-more svg {
    flex-shrink: 0;
    transition: transform 0.12s;
}

.sbn-prog-row-read-more:hover svg {
    transform: translateX(2px);
}

.sbn-prog-lib-empty {
    text-align: center;
    color: #9ca3af;
    font-size: 15px;
    padding: 60px 0;
}

.sbn-prog-filter-sidebar {
    position: sticky;
    top: 20px;
    align-self: flex-start;
    width: 220px;
    min-width: 220px;
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    padding: 20px;
    max-height: calc(100vh - 40px);
    overflow-y: auto;
    order: 1;
}

.sbn-prog-sidebar-header {
    margin-bottom: 16px;
    padding-bottom: 12px;
    border-bottom: 1px solid #e2e8f0;
}

.sbn-prog-sidebar-header h3 {
    margin: 0;
    font-size: 15px;
    font-weight: 700;
    color: #1a202c;
}

.sbn-prog-sidebar-section { 
    margin-bottom: 20px; 
}

.sbn-prog-sidebar-label {
    font-size: 10px;
    font-weight: 700;
    color: #718096;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    margin: 0 0 8px;
    display: block;
}

.sbn-prog-sidebar-options {
    display: flex;
    flex-wrap: wrap;
    gap: 5px;
}

.sbn-prog-sidebar-option {
    padding: 5px 10px;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    background: #fff;
    color: #4a5568;
    font-size: 12px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.12s;
    line-height: 1.2;
}

.sbn-prog-sidebar-option:hover {
    border-color: #e85d3b;
    color: #e85d3b;
    background: #fff8f5;
}

.sbn-prog-sidebar-option.sbn-filter-active {
    background: var(--cat-clr, var(--clr-text));
    border-color: var(--cat-clr, var(--clr-text));
    color: #fff;
}

.sbn-prog-sidebar-option.sbn-filter-active:hover {
    background: color-mix(in srgb, var(--cat-clr, var(--clr-text)) 80%, #000);
    border-color: color-mix(in srgb, var(--cat-clr, var(--clr-text)) 80%, #000);
}

.sbn-sort-active {
    background: var(--clr-text);
    border-color: var(--clr-text);
    color: #fff;
}

.sbn-sort-active:hover {
    background: var(--clr-gradient);
    border-color: transparent;
}

.sbn-prog-sidebar-clear {
    width: 100%;
    padding: 9px;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    background: #fff;
    color: #718096;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.15s;
    margin-top: 4px;
}

.sbn-prog-sidebar-clear:hover {
    border-color: #e85d3b;
    color: #e85d3b;
    background: #fff8f5;
}

.sbn-prog-tags-empty-note {
    font-size: 11px;
    color: #9ca3af;
    font-style: italic;
    margin: 0;
}

/* Responsive */
@media (max-width: 900px) {
    .sbn-prog-content-wrapper { 
        flex-direction: column-reverse; 
    }
    .sbn-prog-filter-sidebar {
        position: static;
        width: 100%;
        min-width: 0;
        max-height: none;
        order: 0;
    }
}

@media (max-width: 600px) {
    .sbn-prog-lib { 
        padding: 0 12px 48px; 
    }
    .sbn-prog-lib-page-title { 
        font-size: 28px; 
    }
    .sbn-prog-row-rank { 
        width: 30px; 
        font-size: 16px; 
        padding-left: 12px; 
    }
    .sbn-prog-row-title { 
        font-size: 17px; 
    }
}
</style>
