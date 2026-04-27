<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import { getCategoryStyle } from '@/composables/useCategoryColors';

import ChordCard from '@/Components/Library/ChordCard.vue';

defineOptions({ layout: PublicLayout });

interface Song {
  id: number;
  slug: string;
  title: string;
  composer: string | null;
  songKey: string | null;
  tempo: number | null;
  timeSignature: string | null;
  description: string | null;
  harmonyNotes: string | null;
  formNotes: string | null;
  voicingNotes: string | null;
  rhythm: string | null;
  styleSlug: string;
  measureCount: number | null;
  popularity: number | null;
}

interface ProgressionRef {
  id: number;
  slug: string;
  name: string;
  category: string;
  numeralsDisplay: string;
}

interface Props {
  song: Song;
  chordNames: string[];
  chords: any[];
  progressions: ProgressionRef[];
}

const props = defineProps<Props>();

const categoryStyle = computed(() => getCategoryStyle(props.song.styleSlug));

const categoryLabels: Record<string, string> = {
  jazz: 'Jazz', blues: 'Blues', pop: 'Pop / Rock',
  modal: 'Modal', classical: 'Classical', latin: 'Latin', other: 'Other',
};

function chordShowUrl(chord: any): string {
    const base = `/library/chords/${chord.slug}`;
    const root = chord.root_note ?? '';
    const isRootless = chord.voicing_category === 'rootless';
    const hasRoot = chord.transposed_from != null;
    if (isRootless) return `${base}?root=C`;
    if (hasRoot || (root && root !== 'C')) return `${base}?root=${encodeURIComponent(root)}`;
    return base;
}
</script>

<template>
  <div class="sbn-song-show" :style="categoryStyle">

    <!-- Back Link -->
    <div style="margin-bottom: 24px;">
      <Link href="/library/songs" class="sbn-back-link">← Back to Library</Link>
    </div>

    <!-- Header card -->
    <div class="sbn-song-show-header">
      <div class="sbn-song-show-style-bar"></div>
      <div class="sbn-song-show-header-body">
        <h1 class="sbn-song-show-title">{{ song.title }}</h1>
        <p v-if="song.composer" class="sbn-song-show-composer">{{ song.composer }}</p>

        <div class="sbn-song-show-meta">
          <span v-if="song.songKey" class="sbn-song-meta-chip">
            <strong>Key</strong> {{ song.songKey }}
          </span>
          <span v-if="song.tempo" class="sbn-song-meta-chip">
            <strong>Tempo</strong> {{ song.tempo }} bpm
          </span>
          <span v-if="song.timeSignature" class="sbn-song-meta-chip">
            <strong>Time</strong> {{ song.timeSignature }}
          </span>
          <span v-if="song.rhythm" class="sbn-song-meta-chip">
            <strong>Rhythm</strong> {{ song.rhythm }}
          </span>
          <span v-if="song.measureCount" class="sbn-song-meta-chip">
            <strong>Measures</strong> {{ song.measureCount }}
          </span>
        </div>

        <!-- Open in viewer CTA -->
        <div class="sbn-song-show-cta">
          <Link :href="`/library/songs/${song.slug}/viewer`" class="sbn-btn sbn-btn-primary sbn-btn-lg">
            Open in viewer →
          </Link>
        </div>
      </div>
    </div>

    <!-- Description -->
    <div v-if="song.description" class="sbn-song-show-section">
      <h2 class="sbn-song-show-section-title">About this song</h2>
      <p class="sbn-song-show-description">{{ song.description }}</p>
    </div>

    <!-- Chords used (Top 4 by popularity) -->
    <div v-if="chords && chords.length" class="sbn-song-show-section">
      <h2 class="sbn-song-show-section-title">Chords</h2>
      <div class="sbn-song-chords-grid">
        <Link
          v-for="chord in chords"
          :key="chord.id"
          :href="chordShowUrl(chord)"
          style="text-decoration: none;"
        >
          <ChordCard :chord="chord" mini :show-root="true" />
        </Link>
      </div>
    </div>

    <!-- Progressions detected -->
    <div v-if="progressions.length" class="sbn-song-show-section">
      <h2 class="sbn-song-show-section-title">Progressions in this song</h2>
      <ul class="sbn-song-prog-list">
        <li
          v-for="prog in progressions"
          :key="prog.id"
          class="sbn-song-prog-item"
        >
          <Link :href="`/library/progressions/${prog.slug}`" class="sbn-song-prog-link">
            {{ prog.name }}
          </Link>
          <span class="sbn-song-prog-cat">{{ categoryLabels[prog.category] || prog.category }}</span>
          <span class="sbn-song-prog-numerals">{{ prog.numeralsDisplay }}</span>
        </li>
      </ul>
    </div>

    <!-- Rhythm info -->
    <div v-if="song.rhythm" class="sbn-song-show-section">
      <h2 class="sbn-song-show-section-title">Rhythm</h2>
      <div class="sbn-song-rhythm-row">
        <span class="sbn-song-rhythm-label">{{ song.rhythm }}</span>
        <Link :href="`/library/rhythms/${song.rhythm}`" class="sbn-song-rhythm-link">
          View pattern →
        </Link>
      </div>
    </div>

  </div>
</template>

<style scoped>
.sbn-song-show {
  max-width: 960px;
  margin: 0 auto;
  padding: 30px 20px 80px;
}

/* Breadcrumb — reuse global class, no extra needed */

/* Header card */
.sbn-song-show-header {
  background: var(--clr-white);
  border-radius: var(--radius);
  overflow: hidden;
  margin-bottom: 32px;
  box-shadow: 0 1px 4px rgba(0,0,0,0.06);
}

.sbn-song-show-style-bar {
  height: 6px;
  background: var(--category-color, var(--clr-style-bossa));
}

.sbn-song-show-header-body {
  padding: 24px 28px;
}

.sbn-song-show-title {
  font-size: 2em;
  font-weight: 800;
  color: var(--clr-text);
  margin: 0 0 6px;
  letter-spacing: -0.02em;
}

.sbn-song-show-composer {
  font-size: 1.05em;
  color: var(--clr-text-muted);
  margin: 0 0 16px;
}

.sbn-song-show-meta {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 20px;
}

.sbn-song-meta-chip {
  background: var(--clr-surface-2);
  border-radius: 6px;
  padding: 4px 10px;
  font-size: 0.82em;
  color: var(--clr-text-muted);
}

.sbn-song-meta-chip strong {
  color: var(--clr-text);
  margin-right: 4px;
}

/* CTA */
.sbn-song-show-cta {
  display: flex;
  align-items: center;
  gap: 12px;
}


/* Sections */
.sbn-song-show-section {
  margin-bottom: 36px;
}

.sbn-song-show-section-title {
  font-size: 1.05em;
  font-weight: 700;
  color: var(--clr-text);
  margin: 0 0 14px;
  padding-bottom: 8px;
  border-bottom: 2px solid var(--clr-border);
}

.sbn-song-show-description {
  font-size: 0.95em;
  line-height: 1.7;
  color: var(--clr-text-muted);
  margin: 0;
}

/* Chords grid */
.sbn-song-chords-grid {
  display: flex;
  flex-wrap: wrap;
  gap: 16px;
  margin-top: 14px;
}

.sbn-song-chords-grid > a {
  width: 120px;
  text-decoration: none;
}

/* Progressions list */
.sbn-song-prog-list {
  list-style: none;
  padding: 0;
  margin: 0;
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.sbn-song-prog-item {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 14px;
  background: var(--clr-white);
  border-radius: 6px;
}

.sbn-song-prog-link {
  font-weight: 600;
  font-size: 0.9em;
  color: var(--clr-text);
  text-decoration: none;
  min-width: 180px;
}

.sbn-song-prog-link:hover {
  color: var(--category-color, var(--clr-style-bossa));
}

.sbn-song-prog-cat {
  font-size: 0.75em;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--clr-text-muted);
  background: var(--clr-surface-2);
  padding: 2px 8px;
  border-radius: 4px;
  white-space: nowrap;
}

.sbn-song-prog-numerals {
  font-size: 0.82em;
  color: var(--clr-text-muted);
  font-family: Georgia, serif;
  margin-left: auto;
}

/* Rhythm row */
.sbn-song-rhythm-row {
  display: flex;
  align-items: center;
  gap: 16px;
  background: var(--clr-white);
  border-radius: 6px;
  padding: 12px 16px;
}

.sbn-song-rhythm-label {
  font-weight: 600;
  font-size: 0.95em;
  color: var(--clr-text);
}

.sbn-song-rhythm-link {
  font-size: 0.88em;
  color: var(--category-color, var(--clr-style-bossa));
  text-decoration: none;
  font-weight: 500;
}

.sbn-song-rhythm-link:hover {
  text-decoration: underline;
}

@media (max-width: 768px) {
  .sbn-song-show {
    padding: 20px 16px 60px;
  }

  .sbn-song-show-title {
    font-size: 1.6em;
  }

  .sbn-song-prog-item {
    flex-wrap: wrap;
    gap: 6px;
  }

  .sbn-song-prog-numerals {
    margin-left: 0;
  }
}
</style>
