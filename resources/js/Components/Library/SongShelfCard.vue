<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { getCategoryStyle, getCategoryColor } from '@/composables/useCategoryColors';
import type { SongLinkData } from '@/Components/Library/SongLink.vue';

/** SongShelfCard accepts the same shape as SongLinkData (toLinkArray()). */
export type SongShelfCardData = SongLinkData;

const props = defineProps<{ song: SongShelfCardData }>();

const cardStyle  = computed(() => getCategoryStyle(props.song.styleSlug));
const color      = computed(() => getCategoryColor(props.song.styleSlug));
const styleLabel = computed(() => (props.song.styleSlug ?? 'song').replace(/-/g, ' '));

const popularityTier = computed(() => {
    const p = props.song.popularity ?? 0;
    if (p >= 11) return { tier: 'iconic',     label: 'Iconic' };
    if (p >= 6)  return { tier: 'essential',  label: 'Essential' };
    if (p >= 3)  return { tier: 'common',     label: 'Common' };
    if (p >= 1)  return { tier: 'occasional', label: 'Rare' };
    return null;
});
</script>

<template>
    <Link
        :href="`/library/songs/${song.slug}`"
        class="sbn-song-shelf-card sbn-has-category-gradient"
        :style="cardStyle"
    >
        <!-- Square image area -->
        <div class="sbn-song-shelf-card__image">
            <img
                v-if="song.coverImagePath"
                :src="song.coverImagePath"
                :alt="song.title"
                class="sbn-song-shelf-card__img"
            >
            <div v-else class="sbn-song-shelf-card__fallback" />

            <!-- Category badge — always visible, top-left -->
            <span class="sbn-song-shelf-card__badge">{{ styleLabel }}</span>

            <!-- Hover overlay — slides up from bottom -->
            <div class="sbn-song-shelf-card__overlay">
                <p class="sbn-song-shelf-card__overlay-title">{{ song.title }}</p>
                <p v-if="song.composer" class="sbn-song-shelf-card__overlay-sub">{{ song.composer }}</p>
                <span
                    v-if="popularityTier"
                    class="sbn-song-shelf-card__pop"
                    :class="`sbn-pop-${popularityTier.tier}`"
                >{{ popularityTier.label }}</span>
            </div>
        </div>
    </Link>
</template>

<style scoped>
.sbn-song-shelf-card {
    display: block;
    width: 130px;
    text-decoration: none;
    border-radius: var(--radius);
    overflow: hidden;
    flex-shrink: 0;
    transition: box-shadow 0.2s var(--ease), transform 0.2s var(--ease);
}

.sbn-song-shelf-card:hover {
    box-shadow: var(--clr-shadow);
    transform: translateY(-2px);
}

/* 1:1 square image container */
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

/* Category badge — always on top-left */
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

/* Hover overlay — slides up from bottom */
.sbn-song-shelf-card__overlay {
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    z-index: 8;
    padding: 28px 8px 8px;
    background: linear-gradient(to top, rgba(0,0,0,0.72) 0%, transparent 100%);
    transform: translateY(100%);
    transition: transform 0.25s var(--ease);
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.sbn-song-shelf-card:hover .sbn-song-shelf-card__overlay {
    transform: translateY(0);
}

.sbn-song-shelf-card__overlay-title {
    margin: 0;
    font-size: 0.75em;
    font-weight: 700;
    color: #fff;
    line-height: 1.3;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.sbn-song-shelf-card__overlay-sub {
    margin: 0;
    font-size: 0.65em;
    color: rgba(255,255,255,0.78);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.sbn-song-shelf-card__pop {
    align-self: flex-start;
    font-size: 0.6em;
    font-weight: 700;
    padding: 2px 6px;
    border-radius: 4px;
    margin-top: 2px;
}
</style>
