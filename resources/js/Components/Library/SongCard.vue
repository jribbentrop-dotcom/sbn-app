<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { getCategoryStyle, difficultyLabel } from '@/composables/useCategoryColors';

export interface SongCardData {
  id: number;
  slug: string;
  title: string;
  composer: string | null;
  songKey: string | null;
  tempo: number | null;
  timeSignature: string | null;
  rhythm: string | null;
  styleSlug: string;
  description: string | null;
  popularity: number | null;
  difficulty: number | null;
  measureCount: number | null;
  coverImagePath: string | null;
}

const props = defineProps<{ song: SongCardData }>();

const cardStyle = computed(() => getCategoryStyle(props.song.styleSlug));

const styleLabel = computed(() =>
  (props.song.styleSlug ?? 'song').replace(/-/g, ' ')
);

const stars = computed(() => props.song.difficulty ?? 0);
const level = computed(() => difficultyLabel(stars.value));

function tempoLabel(bpm: number | null): string {
  if (!bpm) return '';
  if (bpm < 80)  return 'Ballad';
  if (bpm < 110) return 'Slow';
  if (bpm < 140) return 'Medium';
  if (bpm < 180) return 'Uptempo';
  return 'Fast';
}


</script>

<template>
  <article class="sbn-song-card" :style="cardStyle">

    <!-- Hero image area (gradient fallback — no real image yet) -->
    <Link :href="`/library/songs/${song.slug}`" class="sbn-song-card-image-wrap">
      <img
        v-if="song.coverImagePath"
        :src="song.coverImagePath"
        :alt="song.title"
        class="sbn-song-card-image"
      >
      <div v-else class="sbn-song-card-fallback"></div>

      <!-- Top row: style badge -->
      <div class="sbn-song-card-badge-row">
        <span class="sbn-song-badge-style">{{ styleLabel }}</span>
      </div>


<!-- View button -->
      <div class="sbn-song-card-btn-wrap">
        <span class="sbn-song-view-btn">View Song <span class="sbn-view-btn-arrow">→</span></span>
      </div>

    </Link>

    <!-- Card body -->
    <div class="sbn-song-card-body">
      <div v-if="stars" class="sbn-song-card-level">
        <span class="sbn-badge sbn-badge-muted">
          <span class="sbn-song-card-stars">
            <span v-for="i in 5" :key="i" :class="i <= stars ? 'star-filled' : 'star-empty'">★</span>
          </span>
          {{ level }}
        </span>
      </div>
      <h3 class="sbn-song-card-title">
        <Link :href="`/library/songs/${song.slug}`">{{ song.title }}</Link>
      </h3>
      <p v-if="song.composer" class="sbn-song-card-composer">{{ song.composer }}</p>
    </div>

  </article>
</template>

<style scoped>
.sbn-song-card {
  background: var(--clr-white);
  border: 1px solid var(--clr-border);
  border-radius: var(--radius);
  padding: 10px 10px 0;
  transition: border-color 0.2s var(--ease);
  position: relative;
  --category-color: var(--clr-style-default);
  --category-gradient: linear-gradient(
    135deg,
    var(--category-color) 0%,
    color-mix(in srgb, var(--category-color) 60%, white) 100%
  );
}

.sbn-song-card:hover {
  border-color: var(--clr-text-muted);
}

/* Hero area — 1:1 square */
.sbn-song-card-image-wrap {
  display: block;
  position: relative;
  aspect-ratio: 1 / 1;
  overflow: hidden;
  text-decoration: none;
  background: var(--clr-surface-2);
  border-radius: var(--radius-sm);
}

.sbn-song-card-image {
  width: 100%;
  height: 100%;
  object-fit: cover;
  transition: transform 0.4s var(--ease);
}

.sbn-song-card:hover .sbn-song-card-image {
  transform: scale(1.04);
}

.sbn-song-card-fallback {
  width: 100%;
  height: 100%;
  background: var(--category-gradient);
}

/* Top badge row */
.sbn-song-card-badge-row {
  position: absolute;
  top: 10px;
  left: 10px;
  right: 10px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  z-index: 10;
}

.sbn-song-badge-style {
  background: var(--category-gradient);
  color: var(--clr-white);
  padding: 4px 10px;
  border-radius: var(--radius-sm);
  font-size: 0.7em;
  font-weight: 600;
  text-transform: capitalize;
  transition: all 0.3s var(--ease);
}


/* View button */
.sbn-song-card-btn-wrap {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  pointer-events: none;
  z-index: 5;
}

.sbn-song-view-btn {
  background: var(--clr-white);
  color: var(--clr-text);
  padding: 8px 20px;
  border-radius: var(--radius-sm);
  font-weight: 600;
  font-size: 0.82em;
  letter-spacing: 0.02em;
  pointer-events: auto;
  opacity: 0;
  transform: translateY(6px) scale(0.94);
  transition: opacity 0.25s var(--ease), transform 0.25s var(--ease), box-shadow 0.2s var(--ease);
}

.sbn-song-card:hover .sbn-song-view-btn {
  opacity: 1;
  transform: translateY(0) scale(1);
}

.sbn-song-view-btn:hover {
  box-shadow: 0 4px 12px rgba(0,0,0,0.18);
}

.sbn-view-btn-arrow {
  display: inline-block;
  transition: transform 0.2s var(--ease);
}

.sbn-song-view-btn:hover .sbn-view-btn-arrow {
  transform: translateX(4px);
}


/* Card body */
.sbn-song-card-level {
  margin-bottom: 8px;
}

.sbn-song-card-stars {
  display: inline-flex;
  gap: 1px;
  margin-right: 4px;
}

.sbn-song-card-stars .star-filled { color: var(--clr-star); }
.sbn-song-card-stars .star-empty  { color: var(--clr-border); }

.sbn-song-card-body {
  padding: 12px 14px 14px;
}

.sbn-song-card-title {
  margin: 0 0 4px;
  font-size: 1em;
  font-weight: 600;
}

.sbn-song-card-title a {
  color: var(--clr-text);
  text-decoration: none;
}

.sbn-song-card-title a:hover {
  color: var(--category-color);
}

.sbn-song-card-composer {
  margin: 0;
  color: var(--clr-text-muted);
  font-size: 0.875em;
}
</style>
