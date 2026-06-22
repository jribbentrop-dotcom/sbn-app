<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { getCategoryStyle } from '@/composables/useCategoryColors';
import type { SongLinkData } from '@/Components/Library/SongLink.vue';

/** SongShelfCard accepts the same shape as SongLinkData (toLinkArray()). */
export type SongShelfCardData = SongLinkData;

const props = defineProps<{ song: SongShelfCardData }>();

const cardStyle  = computed(() => getCategoryStyle(props.song.styleSlug));
const styleLabel = computed(() => (props.song.styleSlug ?? 'song').replace(/-/g, ' '));
</script>

<template>
    <Link
        :href="`/library/songs/${song.slug}`"
        class="sbn-song-shelf-card sbn-has-category-gradient"
        :style="cardStyle"
    >
        <div class="sbn-song-shelf-card__image">
            <img
                v-if="song.coverImagePath"
                :src="song.coverImagePath"
                :alt="song.title"
                class="sbn-song-shelf-card__img"
            >
            <div v-else class="sbn-song-shelf-card__fallback" />

            <span class="sbn-song-shelf-card__badge">{{ styleLabel }}</span>
            <div class="sbn-song-shelf-card__title">
                <span class="sbn-song-shelf-card__title-text">{{ song.title }}</span>
            </div>
        </div>
    </Link>
</template>

<style scoped>
.sbn-song-shelf-card {
    display: block;
    width: 160px;
    text-decoration: none;
    border-radius: var(--radius);
    overflow: hidden;
    flex-shrink: 0;
}

@media (max-width: 600px) {
    .sbn-song-shelf-card { width: calc(50vw - 20px); }
}

.sbn-song-shelf-card__image {
    position: relative;
    aspect-ratio: 1 / 1;
    overflow: hidden;
    background: var(--clr-surface-2);
}

.sbn-song-shelf-card__img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
    transition: transform 0.35s var(--ease);
}

.sbn-song-shelf-card:hover .sbn-song-shelf-card__img {
    transform: scale(1.06);
}

.sbn-song-shelf-card__fallback {
    width: 100%;
    height: 100%;
    background: var(--category-gradient);
}

.sbn-song-shelf-card__badge {
    position: absolute;
    top: 7px;
    left: 7px;
    z-index: 10;
    background: var(--category-gradient);
    color: var(--clr-white);
    font-size: 0.62em;
    font-weight: 700;
    text-transform: capitalize;
    padding: 3px 8px;
    border-radius: var(--radius-sm);
    letter-spacing: 0.02em;
    transition: background 0.2s var(--ease), color 0.2s var(--ease);
}

.sbn-song-shelf-card:hover .sbn-song-shelf-card__badge {
    background: var(--clr-white);
    color: var(--clr-text);
}

.sbn-song-shelf-card__title {
    position: absolute;
    inset: 0;
    z-index: 9;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 12px;
    margin: 0;
    text-align: center;
    background: var(--category-gradient);
    opacity: 0;
    transition: opacity 0.25s var(--ease);
}

.sbn-song-shelf-card:hover .sbn-song-shelf-card__title {
    opacity: 1;
}

.sbn-song-shelf-card__title-text {
    font-size: 1.1em;
    font-weight: 700;
    color: #fff;
    line-height: 1.3;
}

.sbn-song-shelf-card__sub {
    font-size: 0.8em;
}
</style>
