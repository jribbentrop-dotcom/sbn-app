<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { getCategoryStyle, difficultyLabel } from '@/composables/useCategoryColors';

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

const levelToStars: Record<string, number> = {
    basic: 1, 'early-intermediate': 2, intermediate: 3, 'late-intermediate': 4, advanced: 5,
};

const props = defineProps<{ course: CourseShelfCardData }>();

const cardStyle  = computed(() => getCategoryStyle(props.course.primaryGenre ?? undefined));
const genreLabel = computed(() => (props.course.primaryGenre ?? 'Course').replace(/-/g, ' ').replace(/\b\w/g, c => c.toUpperCase()));
const stars      = computed(() => levelToStars[props.course.primaryLevel ?? ''] ?? 0);
const levelLabel = computed(() => difficultyLabel(stars.value));
</script>

<template>
    <Link
        :href="`/learn/${course.slug}`"
        class="sbn-course-shelf-card sbn-has-category-gradient"
        :style="cardStyle"
    >
        <div class="sbn-course-shelf-card__image">
            <img
                v-if="course.featuredImagePath"
                :src="course.featuredImagePath"
                :alt="course.title"
                class="sbn-course-shelf-card__img"
            >
            <div v-else class="sbn-course-shelf-card__fallback" />

            <span class="sbn-course-shelf-card__badge">{{ genreLabel }}</span>
            <div class="sbn-course-shelf-card__title">
                <span class="sbn-course-shelf-card__title-text">{{ course.title }}</span>
            </div>
        </div>
    </Link>
</template>

<style scoped>
.sbn-course-shelf-card {
    display: block;
    width: 160px;
    text-decoration: none;
    border-radius: var(--radius);
    overflow: hidden;
    flex-shrink: 0;
}

@media (max-width: 600px) {
    .sbn-course-shelf-card { width: calc(42vw - 8px); }
}

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

.sbn-course-shelf-card__badge {
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

.sbn-course-shelf-card:hover .sbn-course-shelf-card__badge {
    background: var(--clr-white);
    color: var(--clr-text);
}

.sbn-course-shelf-card__title {
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

.sbn-course-shelf-card:hover .sbn-course-shelf-card__title {
    opacity: 1;
}

.sbn-course-shelf-card__title-text {
    font-size: 1.1em;
    font-weight: 700;
    color: #fff;
    line-height: 1.3;
}

.sbn-course-shelf-card__level {
    gap: 4px;
    font-size: 0.8em;
}

.sbn-course-shelf-card__stars {
    display: inline-flex;
    gap: 1px;
    margin-right: 2px;
}

.sbn-course-shelf-card__stars .star-filled { color: var(--clr-star); }
.sbn-course-shelf-card__stars .star-empty  { color: var(--clr-border); }
</style>
