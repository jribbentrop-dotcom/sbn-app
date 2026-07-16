<script setup lang="ts">
import { ref, computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { readDifficultyQueryParam } from '@/composables/useBreadcrumb';
import { getCategoryColor } from '@/composables/useCategoryColors';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import SongCard from '@/Components/Library/SongCard.vue';
import type { SongCardData } from '@/Components/Library/SongCard.vue';
import FilterToggleButton from '@/Components/Library/FilterToggleButton.vue';
import FilterSidebar from '@/Components/Library/FilterSidebar.vue';

defineOptions({ layout: PublicLayout });

interface Props {
  songs: SongCardData[];
  composers: string[];
  keys: string[];
  rhythms: string[];
  totalCount: number;
}

const props = defineProps<Props>();

const CANONICAL_STYLES = ['bossa-nova', 'jazz', 'classical', 'pop'] as const;
const STYLE_LABELS: Record<string, string> = {
  'bossa-nova': 'Bossa Nova',
  'jazz':       'Jazz',
  'classical':  'Classical',
  'pop':        'Pop',
};

const initialQuery = typeof window !== 'undefined'
  ? new URLSearchParams(window.location.search)
  : new URLSearchParams();
const queryStyle = initialQuery.get('style') ?? '';
const queryRhythm = initialQuery.get('rhythm') ?? '';
// ?slugs= is a comma-separated allow-list used by "View all" links from a
// chord/progression show page, whose related songs aren't a single-column
// match and so can't be expressed as one of the filters below.
const querySlugs = (initialQuery.get('slugs') ?? '').split(',').map((s) => s.trim()).filter(Boolean);
// ?from= is a human-readable label for the thing that was viewed to reach a
// ?slugs=/?rhythm= deep link (e.g. a chord or rhythm pattern name) — purely
// cosmetic, shown in the tailored subtitle below.
const queryFrom = initialQuery.get('from') ?? '';

// ── Filter state ─────────────────────────────────────────────
const search     = ref('');
const filtersOpen = ref(false);
const fStyle     = ref(CANONICAL_STYLES.includes(queryStyle as typeof CANONICAL_STYLES[number]) ? queryStyle : '');
const fDifficulty = ref(readDifficultyQueryParam());
const fKey       = ref('');
const fComposer  = ref('');
const fRhythm    = ref(props.rhythms.includes(queryRhythm) ? queryRhythm : '');
const fTempo     = ref('');  // 'slow' | 'medium' | 'fast'
const fromLabel  = ref(queryFrom);
const fSlugs     = ref<string[]>(querySlugs);

// ── Client-side filtering ─────────────────────────────────────
function tempoRange(bpm: number | null): string {
  if (!bpm) return '';
  if (bpm < 100) return 'slow';
  if (bpm <= 140) return 'medium';
  return 'fast';
}

function matchesFilters(s: SongCardData): boolean {
  if (search.value.trim()) {
    const q = search.value.toLowerCase();
    const haystack = [s.title, s.composer, s.description]
      .filter(Boolean).join(' ').toLowerCase();
    if (!haystack.includes(q)) return false;
  }
  if (fStyle.value    && s.styleSlug !== fStyle.value)     return false;
  if (fDifficulty.value && String(s.difficulty ?? '') !== fDifficulty.value) return false;
  if (fKey.value      && s.songKey !== fKey.value)         return false;
  if (fComposer.value && s.composer !== fComposer.value)   return false;
  if (fRhythm.value   && s.rhythm !== fRhythm.value)       return false;
  if (fTempo.value    && tempoRange(s.tempo) !== fTempo.value) return false;
  if (fSlugs.value.length && !fSlugs.value.includes(s.slug)) return false;
  return true;
}

const filtered = computed(() => props.songs.filter(matchesFilters));

const hasFilters = computed(() =>
  !!(search.value || fStyle.value || fDifficulty.value || fKey.value || fComposer.value || fRhythm.value || fTempo.value || fSlugs.value.length)
);

// True while the page is still showing the subset a "View all" deep link
// scoped it to — i.e. the slug allow-list is still active, or the rhythm
// filter still matches the value the link arrived with (the user may have
// since picked a *different* rhythm pill manually, which should drop the
// "Showing songs related to…" framing since it's no longer describing what's
// on screen).
const isScopedView = computed(() =>
  fromLabel.value !== '' && (fSlugs.value.length > 0 || (queryRhythm !== '' && fRhythm.value === queryRhythm))
);

function clearFilters() {
  search.value    = '';
  fStyle.value    = '';
  fDifficulty.value = '';
  fKey.value      = '';
  fComposer.value = '';
  fRhythm.value   = '';
  fTempo.value    = '';
  fSlugs.value    = [];
  fromLabel.value = '';
}

// ── Example search chips ──────────────────────────────────────
const examples = ['Wave', 'Jobim', 'bossa', 'Dm7'];

function applyExample(ex: string) {
  search.value = ex;
  fStyle.value = fDifficulty.value = fKey.value = fComposer.value = fRhythm.value = fTempo.value = '';
}

// ── Rhythm display label ──────────────────────────────────────
function rhythmLabel(slug: string): string {
  return slug.replace(/-/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
}

// ── Composer list is capped in the sidebar — the server already limits it
// to the top 40 by song count, but rendering all 40 as pills in a narrow
// column is unusable. Show the top slice and let "Show more" reveal the rest.
const COMPOSER_VISIBLE_COUNT = 10;
const composersExpanded = ref(false);
const visibleComposers = computed(() =>
  composersExpanded.value ? props.composers : props.composers.slice(0, COMPOSER_VISIBLE_COUNT)
);
const hiddenComposerCount = computed(() => Math.max(0, props.composers.length - COMPOSER_VISIBLE_COUNT));
</script>

<template>
    <Head>
        <title>Bossa Nova &amp; Latin Jazz Songs | Soul Bossa Nova</title>
        <meta name="description" content="Browse our full leadsheet library — Bossa Nova classics, Latin Jazz standards and more. Interactive chord diagrams and synced playback." />
        <meta property="og:title" content="Bossa Nova & Latin Jazz Songs | Soul Bossa Nova" />
        <meta property="og:description" content="Interactive leadsheet library with Bossa Nova classics and Latin Jazz standards — chords, rhythm and synced playback." />
        <meta property="og:type" content="website" />
    </Head>

  <div class="sbn-page sbn-song-library-main">

    <!-- ── Header ── -->
    <div class="sbn-lib-page-header">
      <h1 class="sbn-lib-page-title">Song Library</h1>
      <p v-if="isScopedView" class="sbn-lib-page-subtitle">
        Showing songs related to <strong>{{ fromLabel }}</strong> —
        <Link href="/library/songs" class="sbn-lib-scope-clear">browse the full library</Link>
      </p>
      <p v-else class="sbn-lib-page-subtitle">Explore bossa nova, samba, and jazz standards</p>

      <div class="sbn-lib-search-wrap">
        <div class="sbn-lib-search-box">
          <svg class="sbn-lib-search-icon" width="20" height="20" viewBox="0 0 20 20" fill="none">
            <circle cx="8" cy="8" r="6" stroke="currentColor" stroke-width="2"/>
            <path d="M13 13L18 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
          </svg>
          <input
            v-model="search"
            type="text"
            class="sbn-lib-search-input"
            placeholder="Search songs, artists, chords..."
            autocomplete="off"
          >
          <button
            v-if="search"
            type="button"
            class="sbn-lib-search-clear"
            aria-label="Clear search"
            @click="search = ''"
          >
            <svg width="14" height="14" viewBox="0 0 14 14" fill="none">
              <path d="M2 2L12 12M12 2L2 12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
            </svg>
          </button>
        </div>

        <FilterToggleButton v-model="filtersOpen" :has-filters="hasFilters">Filters</FilterToggleButton>
      </div>
    </div>

    <!-- ── Count bar ── -->

    <!-- ── Content: grid + sidebar ── -->
    <div class="sbn-content-wrapper">

      <!-- Songs grid -->
      <div class="sbn-results-container">
        <div v-if="filtered.length" class="sbn-songs-grid">
          <SongCard v-for="song in filtered" :key="song.id" :song="song" />
        </div>
        <div v-else class="sbn-no-results">
          <svg width="48" height="48" viewBox="0 0 48 48" fill="none">
            <circle cx="20" cy="20" r="12" stroke="currentColor" stroke-width="2"/>
            <path d="M30 30L42 42" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
          </svg>
          <h3>No songs found</h3>
          <p>Try adjusting your search or filters</p>
        </div>
      </div>

      <!-- Filter sidebar -->
      <FilterSidebar v-model="filtersOpen" :has-filters="hasFilters" :show-clear-all="true" @clear="clearFilters">
        <template #title>Filters</template>
        <template #count><strong>{{ filtered.length }}</strong> of {{ totalCount }} songs</template>

        <!-- Style -->
        <div class="sbn-lib-sidebar-section">
          <span class="sbn-lib-sidebar-label">Style</span>
          <div class="sbn-lib-sidebar-options">
            <button
              v-for="style in CANONICAL_STYLES"
              :key="style"
              type="button"
              :class="['sbn-lib-sidebar-option', { 'sbn-filter-active': fStyle === style }]"
              :style="{ '--cat-clr': getCategoryColor(style) }"
              @click="fStyle = fStyle === style ? '' : style"
            >{{ STYLE_LABELS[style] || style }}</button>
          </div>
        </div>

        <!-- Key -->
        <div class="sbn-lib-sidebar-section">
          <span class="sbn-lib-sidebar-label">Key</span>
          <div class="sbn-lib-sidebar-options">
            <button
              v-for="k in keys"
              :key="k"
              type="button"
              :class="['sbn-lib-sidebar-option', { 'sbn-filter-active': fKey === k }]"
              @click="fKey = fKey === k ? '' : k"
            >{{ k }}</button>
          </div>
        </div>

        <!-- Composer -->
        <div class="sbn-lib-sidebar-section">
          <span class="sbn-lib-sidebar-label">Composer</span>
          <div class="sbn-lib-sidebar-options">
            <button
              v-for="c in visibleComposers"
              :key="c"
              type="button"
              :class="['sbn-lib-sidebar-option', { 'sbn-filter-active': fComposer === c }]"
              @click="fComposer = fComposer === c ? '' : c"
            >{{ c }}</button>
            <button
              v-if="!composersExpanded && hiddenComposerCount > 0"
              type="button"
              class="sbn-lib-sidebar-option sbn-lib-sidebar-more"
              @click="composersExpanded = true"
            >+{{ hiddenComposerCount }} more</button>
            <button
              v-else-if="composersExpanded && hiddenComposerCount > 0"
              type="button"
              class="sbn-lib-sidebar-option sbn-lib-sidebar-more"
              @click="composersExpanded = false"
            >Show less</button>
          </div>
        </div>

        <!-- Rhythm -->
        <div class="sbn-lib-sidebar-section">
          <span class="sbn-lib-sidebar-label">Rhythm / Style</span>
          <div class="sbn-lib-sidebar-options">
            <button
              v-for="r in rhythms"
              :key="r"
              type="button"
              :class="['sbn-lib-sidebar-option', { 'sbn-filter-active': fRhythm === r }]"
              @click="fRhythm = fRhythm === r ? '' : r"
            >{{ rhythmLabel(r) }}</button>
          </div>
        </div>

        <!-- Tempo -->
        <div class="sbn-lib-sidebar-section">
          <span class="sbn-lib-sidebar-label">Tempo</span>
          <div class="sbn-lib-sidebar-options">
            <button
              v-for="t in [{ val: 'slow', label: 'Slow (< 100)' }, { val: 'medium', label: 'Medium (100–140)' }, { val: 'fast', label: 'Fast (> 140)' }]"
              :key="t.val"
              type="button"
              :class="['sbn-lib-sidebar-option', { 'sbn-filter-active': fTempo === t.val }]"
              @click="fTempo = fTempo === t.val ? '' : t.val"
            >{{ t.label }}</button>
          </div>
        </div>

      </FilterSidebar>

    </div>
  </div>
</template>
