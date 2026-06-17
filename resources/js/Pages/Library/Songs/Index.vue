<script setup lang="ts">
import { ref, computed } from 'vue';
import { Head } from '@inertiajs/vue3';
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
      <p class="sbn-lib-page-subtitle">Explore bossa nova, samba, and jazz standards</p>

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
      <aside class="sbn-lib-filter-sidebar">
        <div class="sbn-lib-sidebar-header">
          <h3>Filters</h3>
          <span class="sbn-lib-sidebar-count">
            <strong>{{ filtered.length }}</strong> of {{ totalCount }} songs
            <button v-if="hasFilters" type="button" class="sbn-lib-clear-btn" @click="clearFilters">Clear</button>
          </span>
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
              v-for="c in composers"
              :key="c"
              type="button"
              :class="['sbn-lib-sidebar-option', { 'sbn-filter-active': fComposer === c }]"
              @click="fComposer = fComposer === c ? '' : c"
            >{{ c }}</button>
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

        <button type="button" class="sbn-lib-sidebar-clear" @click="clearFilters">
          Clear All Filters
        </button>
      </aside>

    </div>
  </div>
</template>
