<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import RhythmStrip from '@/Components/Library/RhythmStrip.vue';
import type { RhythmPatternWithMeta } from '@/Components/Library/RhythmPattern.vue';
import { getCategoryColor } from '@/composables/useCategoryColors';

defineOptions({ layout: PublicLayout });

interface RhythmPatternWithCount extends RhythmPatternWithMeta {
  songCount: number;
  tags: string[];
}

interface Props {
  patterns: RhythmPatternWithCount[];
  categories: string[];
  timeSignatures: string[];
  gridTypes: string[];
  totalCount: number;
  activeFilters: {
    sort?: string;
    search?: string;
    category?: string;
  };
}

const props = defineProps<Props>();

// ── Filter state ────────────────────────────────────────
const search    = ref(props.activeFilters.search   || '');
const fCategory = ref(props.activeFilters.category || '');
const fTimeSig  = ref('');
const fGridType = ref('');
const fSort     = ref(props.activeFilters.sort || 'popularity');

// ── Client-side filtering ────────────────────────────────
function matchesFilters(p: RhythmPatternWithCount): boolean {
  if (search.value.trim()) {
    const q = search.value.toLowerCase();
    const haystack = [p.name, p.category, p.description]
      .filter(Boolean).join(' ').toLowerCase();
    if (!haystack.includes(q)) return false;
  }
  if (fCategory.value && p.category !== fCategory.value) return false;
  if (fTimeSig.value  && p.timeSignature !== fTimeSig.value)  return false;
  if (fGridType.value && p.gridType      !== fGridType.value) return false;
  return true;
}

const filteredPatterns = computed(() => {
  let result = props.patterns.filter(matchesFilters);
  if (fSort.value === 'name') {
    result = [...result].sort((a, b) => a.name.localeCompare(b.name));
  } else if (fSort.value === 'category') {
    result = [...result].sort((a, b) => a.category.localeCompare(b.category));
  }
  // popularity: server already sorted descending, preserve order
  return result;
});

const hasFilters = computed(() =>
  !!(search.value || fCategory.value || fTimeSig.value || fGridType.value)
);

const isGroupedView = computed(() => fSort.value === 'category');

// ── Grouped display (category sort only) ────────────────
const filteredGrouped = computed(() => {
  const grouped: Record<string, RhythmPatternWithCount[]> = {};
  for (const p of filteredPatterns.value) {
    if (!grouped[p.category]) grouped[p.category] = [];
    grouped[p.category].push(p);
  }
  return grouped;
});

// ── Popularity tier ──────────────────────────────────────
function getPopularityTier(count: number): { tier: string; label: string } {
  if (count >= 10) return { tier: 'iconic',     label: 'Iconic' };
  if (count >= 5)  return { tier: 'essential',  label: 'Essential' };
  if (count >= 2)  return { tier: 'common',     label: 'Common' };
  return                   { tier: 'occasional', label: 'Rare' };
}

// ── URL sync ─────────────────────────────────────────────
watch([search, fCategory, fSort], () => {
  const params: Record<string, string> = {};
  if (search.value)                          params.search   = search.value;
  if (fCategory.value)                       params.category = fCategory.value;
  if (fSort.value && fSort.value !== 'popularity') params.sort = fSort.value;

  router.get('/library/rhythms', params, { preserveState: true, replace: true });
}, { deep: true });

function clearFilters() {
  search.value    = '';
  fCategory.value = '';
  fTimeSig.value  = '';
  fGridType.value = '';
  fSort.value     = 'popularity';
}

const CATEGORY_LABELS: Record<string, string> = {
  'bossa-nova': 'Bossa Nova',
  'jazz':       'Jazz',
  'classical':  'Classical',
  'pop':        'Pop',
};

</script>

<template>
  <div class="sbn-page sbn-rhythm-library-main">

    <!-- ── Header ── -->
    <div class="sbn-lib-page-header">
      <h1 class="sbn-lib-page-title">Rhythm Pattern Library</h1>
      <p class="sbn-lib-page-subtitle">
        Explore percussion and strumming patterns ranked by how often they appear in the song library.
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
            placeholder="Search patterns..."
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

    <!-- ── Content wrapper ── -->
    <div class="sbn-lib-content-wrapper">

      <!-- ── Main list / grid ── -->
      <div class="sbn-lib-list-container">

        <!-- ── HITLIST VIEW (popularity / name sort) ── -->
        <div v-if="!isGroupedView && filteredPatterns.length" class="sbn-lib-hitlist" role="list">
          <div
            v-for="(pattern, index) in filteredPatterns"
            :key="pattern.id"
            class="sbn-lib-row"
            role="listitem"
          >
            <!-- Rank -->
            <div class="sbn-hitlist-rank">{{ index + 1 }}</div>

            <!-- Body -->
            <div class="sbn-lib-row-body">
              <!-- Top badges -->
              <div class="sbn-lib-row-top">
                <span
                  class="sbn-cat-badge sbn-cat-badge-filled"
                  :style="{ '--cat-clr': getCategoryColor(pattern.category) }"
                >
                  {{ CATEGORY_LABELS[pattern.category] || pattern.category }}
                </span>
                <span class="sbn-badge sbn-badge-muted">{{ pattern.timeSignature }}</span>
                <span class="sbn-badge sbn-badge-muted">{{ pattern.bpm }} BPM</span>
                <span
                  v-if="pattern.gridType !== 'sixteenth'"
                  class="sbn-badge"
                  :class="`sbn-badge-grid-${pattern.gridType}`"
                >{{ pattern.gridType }}</span>
                <span v-for="tag in pattern.tags" :key="tag" class="sbn-hashtag">#{{ tag }}</span>
              </div>

              <!-- Title -->
              <h3 class="sbn-lib-row-title">
                <Link :href="`/library/rhythms/${pattern.slug}`" class="sbn-lib-row-link">
                  {{ pattern.name }}
                </Link>
              </h3>

              <!-- Description -->
              <p v-if="pattern.description" class="sbn-lib-row-desc">{{ pattern.description }}</p>

              <!-- RhythmStrip preview -->
              <div class="sbn-rlib-row-strip">
                <RhythmStrip :pattern="pattern" :show-meta="false" :color="getCategoryColor(pattern.styleSlug)" :max-beats="16" />
              </div>

              <!-- Popularity phrase -->
              <p v-if="pattern.songCount > 0" class="sbn-lib-row-popularity-phrase">
                This is a
                <span
                  class="sbn-card-pop"
                  :class="`sbn-pop-${getPopularityTier(pattern.songCount).tier}`"
                >{{ getPopularityTier(pattern.songCount).label }}</span>
                rhythm pattern that appears in
                <strong>{{ pattern.songCount }} song{{ pattern.songCount !== 1 ? 's' : '' }}</strong>
                in the library.
              </p>

              <!-- Read more -->
              <Link :href="`/library/rhythms/${pattern.slug}`" class="sbn-lib-row-read-more">
                View {{ pattern.name }}
                <svg width="13" height="13" viewBox="0 0 16 16" fill="none">
                  <path d="M3 8h10M9 4l4 4-4 4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
              </Link>
            </div>
          </div>
        </div>

        <!-- ── GROUPED VIEW (category sort) ── -->
        <div v-else-if="isGroupedView && filteredPatterns.length" class="sbn-patterns-by-category">
          <div
            v-for="(patterns, category) in filteredGrouped"
            :key="category"
            class="sbn-lib-category-section"
          >
            <h2
              class="sbn-lib-category-header"
              :class="`sbn-lib-category-header--${patterns[0]?.styleSlug || 'pop'}`"
            >
              {{ CATEGORY_LABELS[category] || category }}
              <span class="sbn-lib-category-count">{{ patterns.length }}</span>
            </h2>
            <div class="sbn-patterns-list">
              <Link
                v-for="pattern in patterns"
                :key="pattern.id"
                :href="`/library/rhythms/${pattern.slug}`"
                class="sbn-pattern-row"
                :class="`sbn-pattern-row--${pattern.styleSlug || 'pop'}`"
              >
                <div class="sbn-pattern-row-head">
                  <span class="sbn-pattern-row-name">{{ pattern.name }}</span>
                  <span class="sbn-pattern-row-badges">
                    <span class="sbn-badge sbn-badge-muted">{{ pattern.timeSignature }}</span>
                    <span class="sbn-badge sbn-badge-muted">{{ pattern.bpm }} BPM</span>
                    <span v-if="pattern.gridType !== 'sixteenth'" class="sbn-badge" :class="`sbn-badge-grid-${pattern.gridType}`">{{ pattern.gridType }}</span>
                  </span>
                </div>
                <p v-if="pattern.description" class="sbn-pattern-row-desc">{{ pattern.description }}</p>
                <RhythmStrip :pattern="pattern" :show-meta="false" :color="getCategoryColor(pattern.styleSlug)" :max-beats="16" />
                <span v-if="pattern.songCount > 0" class="sbn-pattern-row-song-count">
                  {{ pattern.songCount }} song{{ pattern.songCount !== 1 ? 's' : '' }}
                </span>
              </Link>
            </div>
          </div>
        </div>

        <!-- No results -->
        <div v-else class="sbn-no-results">
          <svg width="48" height="48" viewBox="0 0 48 48" fill="none">
            <circle cx="20" cy="20" r="12" stroke="currentColor" stroke-width="2"/>
            <path d="M30 30L42 42" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
          </svg>
          <h3>No patterns match your search</h3>
          <p>Try adjusting your filters</p>
        </div>
      </div>

      <!-- ── Filter Sidebar ── -->
      <aside class="sbn-lib-filter-sidebar">
        <div class="sbn-lib-sidebar-header">
          <h3>Filter</h3>
          <span class="sbn-lib-sidebar-count">
            {{ filteredPatterns.length }}{{ hasFilters ? ` of ${totalCount}` : '' }}
            pattern{{ filteredPatterns.length !== 1 ? 's' : '' }}
            <button v-if="hasFilters" class="sbn-lib-clear-btn" @click="clearFilters">Clear</button>
          </span>
        </div>

        <!-- Sort -->
        <div class="sbn-lib-sidebar-section">
          <span class="sbn-lib-sidebar-label">Sort by</span>
          <div class="sbn-lib-sidebar-options">
            <button
              :class="['sbn-lib-sidebar-option', { 'sbn-sort-active': fSort === 'popularity' }]"
              @click="fSort = 'popularity'"
            >Most songs</button>
            <button
              :class="['sbn-lib-sidebar-option', { 'sbn-sort-active': fSort === 'name' }]"
              @click="fSort = 'name'"
            >A–Z</button>
            <button
              :class="['sbn-lib-sidebar-option', { 'sbn-sort-active': fSort === 'category' }]"
              @click="fSort = 'category'"
            >Style</button>
          </div>
        </div>

        <!-- Category -->
        <div class="sbn-lib-sidebar-section">
          <span class="sbn-lib-sidebar-label">Category</span>
          <div class="sbn-lib-sidebar-options">
            <button
              v-for="cat in categories"
              :key="cat"
              :class="['sbn-lib-sidebar-option', { 'sbn-filter-active': fCategory === cat }]"
              :style="{ '--cat-clr': getCategoryColor(cat) }"
              @click="fCategory = fCategory === cat ? '' : cat"
            >{{ CATEGORY_LABELS[cat] || cat }}</button>
          </div>
        </div>

        <!-- Time Signature -->
        <div v-if="timeSignatures.length" class="sbn-lib-sidebar-section">
          <span class="sbn-lib-sidebar-label">Time Signature</span>
          <div class="sbn-lib-sidebar-options">
            <button
              v-for="ts in timeSignatures"
              :key="ts"
              :class="['sbn-lib-sidebar-option', { 'sbn-sort-active': fTimeSig === ts }]"
              @click="fTimeSig = fTimeSig === ts ? '' : ts"
            >{{ ts }}</button>
          </div>
        </div>

        <!-- Grid Type -->
        <div class="sbn-lib-sidebar-section">
          <span class="sbn-lib-sidebar-label">Grid Type</span>
          <div class="sbn-lib-sidebar-options">
            <button
              v-for="gt in gridTypes"
              :key="gt"
              :class="['sbn-lib-sidebar-option', { 'sbn-sort-active': fGridType === gt }]"
              @click="fGridType = fGridType === gt ? '' : gt"
            >{{ gt }}</button>
          </div>
        </div>

        <button v-if="hasFilters || fSort !== 'popularity'" class="sbn-lib-sidebar-clear" @click="clearFilters">
          Clear all filters
        </button>
      </aside>
    </div>
  </div>
</template>

<style scoped>
/* ── Rhythm-specific: strip sizing in library list ── */
.sbn-rlib-row-strip {
  margin-bottom: 10px;
}
.sbn-rlib-row-strip :deep(.sbn-rhythm-strip-row) {
  grid-auto-columns: 28px;
  gap: 4px;
}
.sbn-rlib-row-strip :deep(.sbn-rhythm-strip-cell) { height: 28px; }
.sbn-rlib-row-strip :deep(.sbn-rhythm-strip-cell.is-rest) { height: 8px; }
.sbn-rlib-row-strip :deep(.sbn-rhythm-strip-row-thumb) { height: 10px; }
.sbn-rlib-row-strip :deep(.sbn-rhythm-strip-cell-thumb) { height: 10px; }
.sbn-rlib-row-strip :deep(.sbn-rhythm-strip-cell-thumb.is-rest) { height: 4px; }


.sbn-patterns-list {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 8px;
}

.sbn-pattern-row {
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding: 12px 14px;
  background: var(--clr-white);
  border: 1px solid var(--clr-border);
  border-radius: var(--radius);
  text-decoration: none;
  color: inherit;
  transition: border-color 0.15s var(--ease);
  overflow: hidden;
}
.sbn-pattern-row:hover { border-color: var(--clr-text); }
.sbn-pattern-row--bossa-nova { --row-color: var(--clr-style-bossa); }
.sbn-pattern-row--jazz       { --row-color: var(--clr-style-jazz); }
.sbn-pattern-row--classical  { --row-color: var(--clr-style-classical); }
.sbn-pattern-row--pop        { --row-color: var(--clr-style-pop); }

.sbn-pattern-row-desc {
  margin: 0;
  font-size: 13px;
  color: var(--clr-text-dim);
  line-height: 1.5;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
.sbn-pattern-row-head {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}
.sbn-pattern-row-name {
  font-size: 16px;
  font-weight: 700;
  color: var(--clr-text);
  flex: 1;
}
.sbn-pattern-row-badges {
  display: flex;
  gap: 5px;
  flex-wrap: wrap;
}
.sbn-pattern-row-song-count {
  font-size: 11px;
  color: var(--clr-text-muted);
  font-weight: 500;
}

@media (max-width: 768px) {
  .sbn-patterns-list { grid-template-columns: 1fr; }
}
</style>
