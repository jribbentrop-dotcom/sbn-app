<script setup lang="ts">
import { ref, computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import RhythmCard from '@/Components/Library/RhythmCard.vue';
import type { RhythmPatternWithMeta } from '@/Components/Library/RhythmPattern.vue';

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
            <h2 class="sbn-category-header">
              <span class="sbn-cat-dot" :class="`sbn-cat-dot--${patterns[0]?.styleSlug || 'pop'}`"></span>
              {{ category }}
              <span class="sbn-category-count">({{ patterns.length }})</span>
            </h2>
            <div class="sbn-patterns-grid">
              <RhythmCard
                v-for="pattern in patterns"
                :key="pattern.id"
                :pattern="pattern"
                :mini="false"
              />
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
  gap: 8px;
  font-size: 14px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--clr-text-muted);
  margin: 0 0 16px;
  padding-bottom: 8px;
  border-bottom: 2px solid var(--clr-border);
}

.sbn-cat-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
}

.sbn-cat-dot--samba { background: var(--clr-style-samba); }
.sbn-cat-dot--jazz { background: var(--clr-style-jazz); }
.sbn-cat-dot--latin { background: var(--clr-style-latin); }
.sbn-cat-dot--pop { background: var(--clr-style-pop); }
.sbn-cat-dot--bossa { background: var(--clr-style-bossa); }
.sbn-cat-dot--blues { background: var(--clr-style-blues); }
.sbn-cat-dot--classical { background: var(--clr-style-classical); }
.sbn-cat-dot--gold { background: var(--clr-style-gold); }

.sbn-category-count {
  color: var(--clr-text-muted);
  font-weight: 400;
  font-size: 12px;
}

/* Patterns grid */
.sbn-patterns-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
  gap: 20px;
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
  .sbn-patterns-grid {
    grid-template-columns: 1fr;
  }

  .sbn-category-header {
    font-size: 12px;
  }
}
</style>
