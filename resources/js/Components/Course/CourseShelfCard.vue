<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { getCategoryStyle, getCategoryColor, difficultyLabel } from '@/composables/useCategoryColors';

/** Subset of CourseData needed for a shelf tile. */
export interface CourseShelfCardData {
    id: number;
    slug: string;
    title: string;
    primaryGenre: string | null;
    primaryLevel: string | null;
    lessonCount: number;
    featuredImagePath: string | null;
}

const props = defineProps<{ course: CourseShelfCardData }>();

const levelToStars: Record<string, number> = {
    basic: 1,
    'early-intermediate': 2,
    intermediate: 3,
    'late-intermediate': 4,
    advanced: 5,
};

const cardStyle  = computed(() => getCategoryStyle(props.course.primaryGenre ?? undefined));
const color      = computed(() => getCategoryColor(props.course.primaryGenre ?? undefined));
const genreLabel = computed(() => (props.course.primaryGenre ?? 'course').replace(/-/g, ' '));
const stars      = computed(() => levelToStars[props.course.primaryLevel ?? ''] ?? 0);
const levelLabel = computed(() => difficultyLabel(stars.value));
</script>

<template>
    <Link
        :href="`/learn/${course.slug}`"
        class="sbn-course-shelf-card sbn-has-category-gradient"
        :style="cardStyle"
    >
        <!-- Square image area -->
        <div class="sbn-course-shelf-card__image">
            <img
                v-if="course.featuredImagePath"
                :src="course.featuredImagePath"
                :alt="course.title"
                class="sbn-course-shelf-card__img"
            >
            <div v-else class="sbn-course-shelf-card__fallback" />

            <!-- Top row: genre badge left, stars right — always visible -->
            <div class="sbn-course-shelf-card__badge-row">
                <span class="sbn-course-shelf-card__badge">{{ genreLabel }}</span>
                <span class="sbn-course-shelf-card__stars" :title="levelLabel">
                    <span v-for="i in 5" :key="i" :class="i <= stars ? 'star-filled' : 'star-empty'">{{ i <= stars ? '★' : '☆' }}</span>
                </span>
            </div>

            <!-- Hover overlay — slides up from bottom -->
            <div class="sbn-course-shelf-card__overlay">
                <p class="sbn-course-shelf-card__overlay-title">{{ course.title }}</p>
                <p class="sbn-course-shelf-card__overlay-sub">{{ course.lessonCount }} lessons · {{ levelLabel }}</p>
            </div>
        </div>
    </Link>
</template>

<style scoped>
.sbn-course-shelf-card {
    display: block;
    width: 130px;
    text-decoration: none;
    border-radius: var(--radius);
    overflow: hidden;
    flex-shrink: 0;
    transition: box-shadow 0.2s var(--ease), transform 0.2s var(--ease);
}

.sbn-course-shelf-card:hover {
    box-shadow: var(--clr-shadow);
    transform: translateY(-2px);
}

/* 1:1 square image container */
.sbn-course-shelf-card__image {
    position: relative;
    aspect-ratio: 1 / 1;
    overflow: hidden;
    background: var(--clr-surface-2);
}

.sbn-course-shelf-card__img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
    transition: transform 0.35s var(--ease);
}

.sbn-course-shelf-card:hover .sbn-course-shelf-card__img {
    transform: scale(1.06);
}

.sbn-course-shelf-card__fallback {
    width: 100%;
    height: 100%;
    background: var(--category-gradient);
}

/* Top badge row */
.sbn-course-shelf-card__badge-row {
    position: absolute;
    top: 7px;
    left: 7px;
    right: 7px;
    z-index: 10;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.sbn-course-shelf-card__badge {
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

.sbn-course-shelf-card:hover .sbn-course-shelf-card__badge {
    background: var(--clr-white);
    color: var(--clr-text);
}

.sbn-course-shelf-card__stars {
    font-size: 0.68em;
    display: flex;
    gap: 1px;
    padding: 3px 5px;
    border-radius: var(--radius-sm);
    background: rgba(0,0,0,0.30);
    transition: background 0.2s var(--ease);
}

.sbn-course-shelf-card:hover .sbn-course-shelf-card__stars {
    background: var(--clr-white);
}

.sbn-course-shelf-card__stars .star-filled { color: var(--clr-star); }
.sbn-course-shelf-card__stars .star-empty  { color: rgba(255,255,255,0.4); }

.sbn-course-shelf-card:hover .sbn-course-shelf-card__stars .star-empty {
    color: var(--clr-border);
}

/* Hover overlay — slides up from bottom */
.sbn-course-shelf-card__overlay {
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

.sbn-course-shelf-card:hover .sbn-course-shelf-card__overlay {
    transform: translateY(0);
}

.sbn-course-shelf-card__overlay-title {
    margin: 0;
    font-size: 0.75em;
    font-weight: 700;
    color: #fff;
    line-height: 1.3;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.sbn-course-shelf-card__overlay-sub {
    margin: 0;
    font-size: 0.65em;
    color: rgba(255,255,255,0.78);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
</style>
