<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { getCategoryStyle } from '@/composables/useCategoryColors';

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
  measureCount: number | null;
  coverImagePath: string | null;
}

const props = defineProps<{ song: SongCardData }>();

const cardStyle = computed(() => getCategoryStyle(props.song.styleSlug));

const styleLabel = computed(() =>
  (props.song.styleSlug ?? 'song').replace(/-/g, ' ')
);

function tempoLabel(bpm: number | null): string {
  if (!bpm) return '';
  if (bpm < 80)  return 'Ballad';
  if (bpm < 110) return 'Slow';
  if (bpm < 140) return 'Medium';
  if (bpm < 180) return 'Uptempo';
  return 'Fast';
}

function popularityLabel(pop: number | null): string {
  if (!pop || pop <= 1) return 'Rare';
  if (pop <= 3)          return 'Common';
  if (pop <= 6)          return 'Essential';
  return 'Iconic';
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

      <!-- Top row: style badge (left) + popularity (right) -->
      <div class="sbn-song-card-badge-row">
        <span class="sbn-song-badge-style">{{ styleLabel }}</span>
        <span class="sbn-song-badge-pop">{{ popularityLabel(song.popularity) }}</span>
      </div>

      <!-- Bottom: key + tempo pills -->
      <div class="sbn-song-card-meta-row">
        <span v-if="song.songKey" class="sbn-song-pill">{{ song.songKey }}</span>
        <span v-if="song.timeSignature" class="sbn-song-pill">{{ song.timeSignature }}</span>
        <span v-if="song.tempo" class="sbn-song-pill">{{ tempoLabel(song.tempo) }}</span>
      </div>

      <!-- Hover overlay -->
      <div class="sbn-song-card-overlay">
        <span class="sbn-song-view-btn">View Song</span>
      </div>
    </Link>

    <!-- Card body -->
    <div class="sbn-song-card-body">
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
  border-radius: var(--radius);
  overflow: hidden;
  transition: box-shadow 0.3s var(--ease);
  position: relative;
  --category-color: var(--clr-style-default);
  --category-gradient: linear-gradient(
    135deg,
    var(--category-color) 0%,
    color-mix(in srgb, var(--category-color) 60%, white) 100%
  );
}

.sbn-song-card:hover {
  box-shadow: var(--clr-shadow);
}

/* Hero area — 4:3 landscape */
.sbn-song-card-image-wrap {
  display: block;
  position: relative;
  aspect-ratio: 4 / 3;
  overflow: hidden;
  text-decoration: none;
  background: var(--clr-surface-2);
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

.sbn-song-card:hover .sbn-song-badge-style {
  background: var(--clr-white);
  color: var(--clr-text);
}

.sbn-song-badge-pop {
  padding: 4px 8px;
  border-radius: var(--radius-sm);
  font-size: 0.7em;
  font-weight: 600;
  background: transparent;
  color: var(--clr-white);
  transition: background 0.3s var(--ease);
}

.sbn-song-card:hover .sbn-song-badge-pop {
  background: var(--clr-white);
  color: var(--clr-text);
}

/* Bottom meta row */
.sbn-song-card-meta-row {
  position: absolute;
  bottom: 10px;
  left: 10px;
  right: 10px;
  display: flex;
  gap: 5px;
  flex-wrap: wrap;
  z-index: 10;
}

.sbn-song-pill {
  background: var(--clr-overlay-dark);
  color: var(--clr-white);
  font-size: 0.68em;
  font-weight: 600;
  padding: 3px 9px;
  border-radius: 999px;
  transition: background 0.3s var(--ease);
}

.sbn-song-card:hover .sbn-song-pill {
  background: color-mix(in srgb, var(--clr-white) 85%, transparent);
  color: var(--clr-text);
}

/* Hover overlay */
.sbn-song-card-overlay {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  background: var(--category-gradient);
  opacity: 0;
  transition: opacity 0.3s var(--ease);
  pointer-events: none;
  z-index: 5;
}

.sbn-song-card:hover .sbn-song-card-overlay {
  opacity: 0.7;
  pointer-events: auto;
}

.sbn-song-view-btn {
  background: var(--clr-white);
  color: var(--clr-text);
  padding: 10px 24px;
  border-radius: var(--radius-sm);
  font-weight: 600;
  font-size: 0.85em;
  pointer-events: none;
}

/* Card body */
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
