<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

/** Compact song reference — matches Leadsheet::toLinkArray() on the backend. */
export interface SongLinkData {
  id: number;
  slug: string;
  title: string;
  styleSlug: string;
  coverImagePath: string | null;
  composer: string | null;
  popularity: number | null;
}

const props = defineProps<{ song: SongLinkData }>();

const styleLabel = computed(() => (props.song.styleSlug ?? 'song').replace(/-/g, ' '));
</script>

<template>
  <Link :href="`/library/songs/${song.slug}`" class="sbn-song-link">
    <span class="sbn-song-link__thumb">
      <img
        v-if="song.coverImagePath"
        :src="song.coverImagePath"
        :alt="song.title"
        class="sbn-song-link__img"
      >
      <span
        v-else
        class="sbn-song-link__img sbn-song-link__img--fallback"
        :class="`sbn-cat-badge--${song.styleSlug}`"
      ></span>
    </span>
    <span class="sbn-song-link__title">{{ song.title }}</span>
    <span class="sbn-cat-badge" :class="`sbn-cat-badge--${song.styleSlug}`">
      {{ styleLabel }}
    </span>
  </Link>
</template>
