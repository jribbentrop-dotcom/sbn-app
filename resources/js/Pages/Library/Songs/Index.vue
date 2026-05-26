<script setup lang="ts">
import { ref, computed } from 'vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import SongCard from '@/Components/Library/SongCard.vue';
import type { SongCardData } from '@/Components/Library/SongCard.vue';

defineOptions({ layout: PublicLayout });

interface Props {
  songs: SongCardData[];
  composers: string[];
  keys: string[];
  rhythms: string[];
  totalCount: number;
}

const props = defineProps<Props>();

// ── Filter state ─────────────────────────────────────────────
const search     = ref('');
const fKey       = ref('');
const fComposer  = ref('');
const fRhythm    = ref('');
const fTempo     = ref('');  // 'slow' | 'medium' | 'fast'

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
  if (fKey.value      && s.songKey !== fKey.value)         return false;
  if (fComposer.value && s.composer !== fComposer.value)   return false;
  if (fRhythm.value   && s.rhythm !== fRhythm.value)       return false;
  if (fTempo.value    && tempoRange(s.tempo) !== fTempo.value) return false;
  return true;
}

const filtered = computed(() => props.songs.filter(matchesFilters));

const hasFilters = computed(() =>
  !!(search.value || fKey.value || fComposer.value || fRhythm.value || fTempo.value)
);

function clearFilters() {
  search.value    = '';
  fKey.value      = '';
  fComposer.value = '';
  fRhythm.value   = '';
  fTempo.value    = '';
}

// ── Example search chips ──────────────────────────────────────
const examples = ['Wave', 'Jobim', 'bossa', 'Dm7'];

function applyExample(ex: string) {
  search.value = ex;
  fKey.value = fComposer.value = fRhythm.value = fTempo.value = '';
}

// ── Rhythm display label ──────────────────────────────────────
function rhythmLabel(slug: string): string {
  return slug.replace(/-/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
}
</script>

<template>
  <div class="sbn-page sbn-song-library-main">

    <!-- ── Header ── -->
    <header class="sbn-library-header">
      <h1 class="sbn-library-title">Song Library</h1>
      <p class="sbn-library-subtitle">Explore bossa nova, samba, and jazz standards</p>

      <div class="sbn-search-container">
        <div class="sbn-search-box">
          <svg class="sbn-search-icon" width="20" height="20" viewBox="0 0 20 20" fill="none">
            <circle cx="8" cy="8" r="6" stroke="currentColor" stroke-width="2"/>
            <path d="M13 13L18 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
          </svg>
          <input
            v-model="search"
            type="text"
            class="sbn-search-input"
            placeholder="Search songs, artists, chords..."
            autocomplete="off"
          >
          <button
            v-if="search"
            type="button"
            class="sbn-search-clear"
            aria-label="Clear search"
            @click="search = ''"
          >
            <svg width="14" height="14" viewBox="0 0 14 14" fill="none">
              <path d="M2 2L12 12M12 2L2 12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
            </svg>
          </button>
        </div>

        <div class="sbn-search-examples">
          <span class="sbn-search-try">Try: </span>
          <button
            v-for="ex in examples"
            :key="ex"
            type="button"
            class="sbn-example-btn"
            @click="applyExample(ex)"
          >{{ ex }}</button>
        </div>
      </div>
    </header>

    <!-- ── Count bar ── -->
    <div class="sbn-count-bar">
      <strong>{{ filtered.length }}</strong> of {{ totalCount }} songs
      <button v-if="hasFilters" type="button" class="sbn-count-clear" @click="clearFilters">
        Clear filters
      </button>
    </div>

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
      <aside class="sbn-filter-sidebar">
        <div class="sbn-sidebar-header">
          <h3>Filters</h3>
        </div>

        <!-- Key -->
        <div class="sbn-sidebar-section">
          <span class="sbn-sidebar-label">Key</span>
          <div class="sbn-sidebar-options">
            <button
              v-for="k in keys"
              :key="k"
              type="button"
              :class="['sbn-sidebar-option', { active: fKey === k }]"
              @click="fKey = fKey === k ? '' : k"
            >{{ k }}</button>
          </div>
        </div>

        <!-- Composer -->
        <div class="sbn-sidebar-section">
          <span class="sbn-sidebar-label">Composer</span>
          <div class="sbn-sidebar-options">
            <button
              v-for="c in composers"
              :key="c"
              type="button"
              :class="['sbn-sidebar-option', { active: fComposer === c }]"
              @click="fComposer = fComposer === c ? '' : c"
            >{{ c }}</button>
          </div>
        </div>

        <!-- Rhythm -->
        <div class="sbn-sidebar-section">
          <span class="sbn-sidebar-label">Rhythm / Style</span>
          <div class="sbn-sidebar-options">
            <button
              v-for="r in rhythms"
              :key="r"
              type="button"
              :class="['sbn-sidebar-option', { active: fRhythm === r }]"
              @click="fRhythm = fRhythm === r ? '' : r"
            >{{ rhythmLabel(r) }}</button>
          </div>
        </div>

        <!-- Tempo -->
        <div class="sbn-sidebar-section">
          <span class="sbn-sidebar-label">Tempo</span>
          <div class="sbn-sidebar-options">
            <button
              v-for="t in [{ val: 'slow', label: 'Slow (< 100)' }, { val: 'medium', label: 'Medium (100–140)' }, { val: 'fast', label: 'Fast (> 140)' }]"
              :key="t.val"
              type="button"
              :class="['sbn-sidebar-option', { active: fTempo === t.val }]"
              @click="fTempo = fTempo === t.val ? '' : t.val"
            >{{ t.label }}</button>
          </div>
        </div>

        <button type="button" class="sbn-clear-filters-btn" @click="clearFilters">
          Clear All Filters
        </button>
      </aside>

    </div>
  </div>
</template>
