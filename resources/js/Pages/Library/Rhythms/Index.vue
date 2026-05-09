<script setup lang="ts">
import { ref, computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import RhythmStrip from '@/Components/Library/RhythmStrip.vue';
import type { RhythmPatternWithMeta } from '@/Components/Library/RhythmPattern.vue';
import { getCategoryColor } from '@/composables/useCategoryColors';

defineOptions({ layout: PublicLayout });

interface Props {
  patterns: RhythmPatternWithMeta[];
  grouped: Record<string, RhythmPatternWithMeta[]>;
  categories: string[];
  timeSignatures: string[];
  gridTypes: string[];
  totalCount: number;
}

const props = defineProps<Props>();

// ── Filter state ───────────────────────────────────────────
const search = ref('');
const fCategory = ref('');
const fTimeSig = ref('');
const fGridType = ref('');

// ── Client-side filtering ──────────────────────────────────
function matchesFilters(p: RhythmPatternWithMeta): boolean {
  // Text search
  if (search.value.trim()) {
    const q = search.value.toLowerCase();
    const haystack = [p.name, p.category, p.description]
      .filter(Boolean).join(' ').toLowerCase();
    if (!haystack.includes(q)) return false;
  }

  if (fCategory.value && p.category !== fCategory.value) return false;
  if (fTimeSig.value && p.timeSignature !== fTimeSig.value) return false;
  if (fGridType.value && p.gridType !== fGridType.value) return false;

  return true;
}

const filteredPatterns = computed(() => {
  return props.patterns.filter(matchesFilters);
});

const hasFilters = computed(() =>
  !!(search.value || fCategory.value || fTimeSig.value || fGridType.value)
);

function clearFilters() {
  search.value = '';
  fCategory.value = '';
  fTimeSig.value = '';
  fGridType.value = '';
}

// ── Grouped display ────────────────────────────────────────
const filteredGrouped = computed(() => {
  const grouped: Record<string, RhythmPatternWithMeta[]> = {};
  for (const pattern of filteredPatterns.value) {
    if (!grouped[pattern.category]) {
      grouped[pattern.category] = [];
    }
    grouped[pattern.category].push(pattern);
  }
  return grouped;
});
</script>

<template>
  <div class="sbn-rhythm-library-main">
    <!-- ── Header ── -->
    <header class="sbn-library-header">
      <h1 class="sbn-library-title">Rhythm Patterns</h1>
      <p class="sbn-library-subtitle">Browse and play percussion patterns</p>

      <div class="sbn-search-container">
        <div class="sbn-search-box">
          <svg class="sbn-search-icon" width="18" height="18" viewBox="0 0 20 20" fill="none">
            <circle cx="8" cy="8" r="6" stroke="currentColor" stroke-width="2"/>
            <path d="M13 13L18 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
          </svg>
          <input
            v-model="search"
            type="search"
            class="sbn-search-input"
            placeholder="Search patterns..."
            autocomplete="off"
          />
          <button
            v-if="search"
            class="sbn-search-clear"
            @click="search = ''"
            aria-label="Clear search"
          >
            <svg width="14" height="14" viewBox="0 0 16 16" fill="none">
              <path d="M4 4L12 12M12 4L4 12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
            </svg>
          </button>
        </div>
      </div>
    </header>

    <!-- ── Count bar ── -->
    <div class="sbn-count-bar">
      <span v-if="hasFilters">
        Showing <strong>{{ filteredPatterns.length }}</strong> of {{ totalCount }} patterns
      </span>
      <span v-else>
        <strong>{{ totalCount }}</strong> patterns
      </span>
      <button v-if="hasFilters" class="sbn-count-clear" @click="clearFilters">
        Clear filters
      </button>
    </div>

    <!-- ── Content wrapper ── -->
    <div class="sbn-content-wrapper">
      <!-- Results -->
      <div class="sbn-results-container">
        <div v-if="filteredPatterns.length" class="sbn-patterns-by-category">
          <div
            v-for="(patterns, category) in filteredGrouped"
            :key="category"
            class="sbn-category-section"
          >
            <h2
              class="sbn-category-header"
              :class="`sbn-category-header--${patterns[0]?.styleSlug || 'pop'}`"
            >
              {{ category }}
              <span class="sbn-category-count">{{ patterns.length }}</span>
            </h2>
            <div class="sbn-patterns-list">
              <Link
                v-for="pattern in patterns"
                :key="pattern.id"
                :href="`/library/rhythms/${pattern.slug}`"
                class="sbn-pattern-row"
                :class="`sbn-pattern-row--${patterns[0]?.styleSlug || 'pop'}`"
              >
                <div class="sbn-pattern-row-head">
                  <span class="sbn-pattern-row-name">{{ pattern.name }}</span>
                  <span class="sbn-pattern-row-badges">
                    <span class="sbn-badge sbn-badge-muted">{{ pattern.timeSignature }}</span>
                    <span class="sbn-badge sbn-badge-muted">{{ pattern.bpm }} BPM</span>
                    <span v-if="pattern.gridType !== 'sixteenth'" class="sbn-badge" :class="`sbn-badge-${pattern.gridType}`">{{ pattern.gridType }}</span>
                  </span>
                </div>
                <p v-if="pattern.description" class="sbn-pattern-row-desc">{{ pattern.description }}</p>
                <RhythmStrip :pattern="pattern" :show-meta="false" :color="getCategoryColor(pattern.styleSlug)" />
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

      <!-- Filter Sidebar -->
      <aside class="sbn-filter-sidebar">
        <div class="sbn-sidebar-header">
          <h3>Filters</h3>
        </div>

        <!-- Category -->
        <div class="sbn-sidebar-section">
          <span class="sbn-sidebar-label">Category</span>
          <div class="sbn-sidebar-options">
            <button
              v-for="cat in categories"
              :key="cat"
              class="sbn-sidebar-option"
              :class="{ active: fCategory === cat }"
              @click="fCategory = fCategory === cat ? '' : cat"
            >
              {{ cat }}
            </button>
          </div>
        </div>

        <!-- Time Signature -->
        <div v-if="timeSignatures.length" class="sbn-sidebar-section">
          <span class="sbn-sidebar-label">Time Signature</span>
          <div class="sbn-sidebar-options">
            <button
              v-for="ts in timeSignatures"
              :key="ts"
              class="sbn-sidebar-option"
              :class="{ active: fTimeSig === ts }"
              @click="fTimeSig = fTimeSig === ts ? '' : ts"
            >
              {{ ts }}
            </button>
          </div>
        </div>

        <!-- Grid Type -->
        <div class="sbn-sidebar-section">
          <span class="sbn-sidebar-label">Grid Type</span>
          <div class="sbn-sidebar-options">
            <button
              v-for="gt in gridTypes"
              :key="gt"
              class="sbn-sidebar-option"
              :class="{ active: fGridType === gt }"
              @click="fGridType = fGridType === gt ? '' : gt"
            >
              {{ gt }}
            </button>
          </div>
        </div>

        <button v-if="hasFilters" class="sbn-clear-filters-btn" @click="clearFilters">
          Clear All Filters
        </button>
      </aside>
    </div>
  </div>
</template>

<style scoped>
/* Page shell */
.sbn-rhythm-library-main {
  max-width: 1400px;
  margin: 0 auto;
  padding: 40px 20px 80px;
}

/* Category sections */
.sbn-category-section {
  margin-bottom: 32px;
}

.sbn-category-header {
  display: flex;
  align-items: center;
  gap: 10px;
  font-size: 13px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.07em;
  color: #fff;
  margin: 0 0 14px;
  padding: 10px 16px;
  border-radius: var(--radius);
  background: var(--cat-color, var(--clr-style-default));
}

.sbn-category-header--bossa    { --cat-color: linear-gradient(100deg, var(--clr-style-bossa), color-mix(in srgb, var(--clr-style-bossa) 40%, white)); }
.sbn-category-header--jazz     { --cat-color: linear-gradient(100deg, var(--clr-style-jazz), color-mix(in srgb, var(--clr-style-jazz) 40%, white)); }
.sbn-category-header--samba    { --cat-color: linear-gradient(100deg, var(--clr-style-samba), color-mix(in srgb, var(--clr-style-samba) 40%, white)); }
.sbn-category-header--latin    { --cat-color: linear-gradient(100deg, var(--clr-style-latin), color-mix(in srgb, var(--clr-style-latin) 40%, white)); }
.sbn-category-header--blues    { --cat-color: linear-gradient(100deg, var(--clr-style-blues), color-mix(in srgb, var(--clr-style-blues) 40%, white)); }
.sbn-category-header--pop      { --cat-color: linear-gradient(100deg, var(--clr-style-pop), color-mix(in srgb, var(--clr-style-pop) 40%, white)); }
.sbn-category-header--classical{ --cat-color: linear-gradient(100deg, var(--clr-style-classical), color-mix(in srgb, var(--clr-style-classical) 40%, white)); }
.sbn-category-header--gold     { --cat-color: linear-gradient(100deg, var(--clr-style-gold), color-mix(in srgb, var(--clr-style-gold) 40%, white)); }

.sbn-category-count {
  font-weight: 500;
  font-size: 12px;
  opacity: 0.8;
  background: rgba(255,255,255,0.2);
  padding: 1px 7px;
  border-radius: 999px;
}

/* Patterns list */
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
  border-right: 3px solid var(--row-color, var(--clr-border));
  border-bottom: 3px solid var(--row-color, var(--clr-border));
  border-radius: var(--radius);
  text-decoration: none;
  color: inherit;
  transition: box-shadow 0.15s, transform 0.15s;
}

.sbn-pattern-row:hover {
  box-shadow: 3px 3px 0 var(--row-color, var(--clr-border));
  transform: translate(-1px, -1px);
}

.sbn-pattern-row--bossa     { --row-color: var(--clr-style-bossa); }
.sbn-pattern-row--jazz      { --row-color: var(--clr-style-jazz); }
.sbn-pattern-row--samba     { --row-color: var(--clr-style-samba); }
.sbn-pattern-row--latin     { --row-color: var(--clr-style-latin); }
.sbn-pattern-row--blues     { --row-color: var(--clr-style-blues); }
.sbn-pattern-row--pop       { --row-color: var(--clr-style-pop); }
.sbn-pattern-row--classical { --row-color: var(--clr-style-classical); }
.sbn-pattern-row--gold      { --row-color: var(--clr-style-gold); }

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

.sbn-badge {
  padding: 3px 8px;
  border-radius: 4px;
  font-size: 12px;
  font-weight: 600;
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

.sbn-badge {
  padding: 2px 7px;
  border-radius: 4px;
  font-size: 11px;
  font-weight: 500;
  background: var(--clr-surface-2);
  color: var(--clr-text-muted);
}

.sbn-badge-muted {
  background: var(--clr-surface-3);
}

.sbn-badge-eighth {
  background: #ebf8ff;
  color: var(--clr-style-jazz);
}

.sbn-badge-triplet {
  background: #f0fdf4;
  color: var(--clr-style-samba);
}

/* No results */
.sbn-no-results {
  text-align: center;
  padding: 60px 20px;
  color: var(--clr-text-muted);
}

.sbn-no-results h3 {
  font-size: 1.1em;
  font-weight: 600;
  margin: 16px 0 8px;
  color: var(--clr-text-dim);
}

.sbn-no-results p {
  font-size: 0.9em;
  margin: 0;
}

/* Responsive */
@media (max-width: 768px) {
  .sbn-patterns-list {
    grid-template-columns: 1fr;
  }

  .sbn-category-header {
    font-size: 12px;
  }
}
</style>
