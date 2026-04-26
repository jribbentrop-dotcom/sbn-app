<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
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
}

const props = defineProps<{ song: SongCardData }>();

function tempoLabel(bpm: number | null): string {
  if (!bpm) return '';
  if (bpm < 80)  return 'Ballad';
  if (bpm < 110) return 'Slow';
  if (bpm < 140) return 'Medium';
  if (bpm < 180) return 'Uptempo';
  return 'Fast';
}

function popularityClass(pop: number | null): string {
  if (!pop || pop <= 1) return 'sbn-pop-occasional';
  if (pop <= 3)          return 'sbn-pop-common';
  if (pop <= 6)          return 'sbn-pop-essential';
  return 'sbn-pop-iconic';
}

function popularityLabel(pop: number | null): string {
  if (!pop || pop <= 1) return 'Rare';
  if (pop <= 3)          return 'Common';
  if (pop <= 6)          return 'Essential';
  return 'Iconic';
}
</script>

<template>
  <Link
    :href="`/library/songs/${song.slug}`"
    class="sbn-song-card"
    :style="getCategoryStyle(song.styleSlug)"
  >
    <div class="sbn-song-card-header">
      <div class="sbn-song-card-style-bar"></div>
    </div>

    <div class="sbn-song-card-body">
      <h3 class="sbn-song-card-title">{{ song.title }}</h3>
      <p v-if="song.composer" class="sbn-song-card-composer">{{ song.composer }}</p>
    </div>

    <div class="sbn-song-card-meta">
      <span v-if="song.songKey" class="sbn-song-card-key">{{ song.songKey }}</span>
      <span v-if="song.timeSignature" class="sbn-song-card-timesig">{{ song.timeSignature }}</span>
      <span v-if="song.tempo" class="sbn-song-card-tempo">{{ tempoLabel(song.tempo) }}</span>
    </div>

    <div v-if="song.description" class="sbn-song-card-desc">{{ song.description }}</div>

    <div class="sbn-song-card-footer">
      <span v-if="song.rhythm" class="sbn-song-card-rhythm">{{ song.rhythm }}</span>
      <span :class="['sbn-card-pop', popularityClass(song.popularity)]">
        {{ popularityLabel(song.popularity) }}
      </span>
    </div>
  </Link>
</template>
