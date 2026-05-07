<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { getCategoryColor, getCategoryStyle, difficultyLabel } from '@/composables/useCategoryColors';

interface CourseData {
  id: number;
  slug: string;
  title: string;
  excerpt: string | null;
  primaryGenre: string | null;
  primaryLevel: string | null;
  lessonCount: number;
  featuredImagePath: string | null;
}

const props = defineProps<{ course: CourseData }>();

const levelToStars: Record<string, number> = {
  basic: 1,
  'early-intermediate': 2,
  intermediate: 3,
  'late-intermediate': 4,
  advanced: 5,
};

const stars = computed(() => levelToStars[props.course.primaryLevel ?? ''] ?? 0);
const levelLabel = computed(() => difficultyLabel(stars.value));
const cardStyle = computed(() => getCategoryStyle(props.course.primaryGenre ?? undefined));
const genreLabel = computed(() => (props.course.primaryGenre ?? 'Course').replace(/-/g, ' '));
</script>

<template>
  <article class="sbn-course-card" :style="cardStyle">
    <Link :href="`/learn/${course.slug}`" class="sbn-course-card-image-wrap">

      <!-- Image or gradient fallback -->
      <img
        v-if="course.featuredImagePath"
        :src="course.featuredImagePath"
        :alt="course.title"
        class="sbn-course-card-image"
      >
      <div v-else class="sbn-course-card-fallback"></div>

      <!-- Top row: genre badge (left) + stars (right) -->
      <div class="sbn-course-card-badge-row">
        <span class="sbn-course-badge-genre">{{ genreLabel }}</span>
        <span class="sbn-course-badge-stars" :title="levelLabel">
          <span v-for="i in 5" :key="i" :class="i <= stars ? 'star-filled' : 'star-empty'">
            {{ i <= stars ? '★' : '☆' }}
          </span>
        </span>
      </div>

      <!-- Bottom: lesson count -->
      <div class="sbn-course-card-meta-row">
        <span class="sbn-course-pill">{{ course.lessonCount }} lessons</span>
      </div>

      <!-- Hover overlay -->
      <div class="sbn-course-card-overlay">
        <span class="sbn-course-view-btn">View Course</span>
      </div>

    </Link>

    <div class="sbn-course-card-body">
      <h3 class="sbn-course-card-title">
        <Link :href="`/learn/${course.slug}`">{{ course.title }}</Link>
      </h3>
      <p class="sbn-course-card-excerpt">{{ course.excerpt || 'Explore focused lessons and musical applications.' }}</p>
    </div>
  </article>
</template>

<style scoped>
.sbn-course-card {
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

.sbn-course-card:hover {
  box-shadow: var(--clr-shadow);
}

/* Image area — 4:3 landscape */
.sbn-course-card-image-wrap {
  display: block;
  position: relative;
  aspect-ratio: 4 / 3;
  overflow: hidden;
  text-decoration: none;
  background: var(--clr-surface-2);
}

.sbn-course-card-image {
  width: 100%;
  height: 100%;
  object-fit: cover;
  transition: transform 0.4s var(--ease);
}

.sbn-course-card:hover .sbn-course-card-image {
  transform: scale(1.04);
}

/* Gradient fallback when no image */
.sbn-course-card-fallback {
  width: 100%;
  height: 100%;
  background: var(--category-gradient);
}

/* Top badge row */
.sbn-course-card-badge-row {
  position: absolute;
  top: 10px;
  left: 10px;
  right: 10px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  z-index: 10;
}

.sbn-course-badge-genre {
  background: var(--category-gradient);
  color: var(--clr-white);
  padding: 4px 10px;
  border-radius: var(--radius-sm);
  font-size: 0.7em;
  font-weight: 600;
  text-transform: capitalize;
  transition: all 0.3s var(--ease);
}

.sbn-course-card:hover .sbn-course-badge-genre {
  background: var(--clr-white);
  color: var(--clr-text);
}

.sbn-course-badge-stars {
  padding: 4px 8px;
  border-radius: var(--radius-sm);
  font-size: 0.78em;
  display: flex;
  gap: 1px;
  background: transparent;
  transition: background 0.3s var(--ease);
}

.sbn-course-badge-stars .star-filled { color: var(--clr-star); }
.sbn-course-badge-stars .star-empty  { color: var(--clr-border); }

.sbn-course-card:hover .sbn-course-badge-stars {
  background: var(--clr-white);
}

/* Bottom meta row */
.sbn-course-card-meta-row {
  position: absolute;
  bottom: 10px;
  left: 10px;
  right: 10px;
  display: flex;
  gap: 6px;
  z-index: 10;
}

.sbn-course-pill {
  background: var(--clr-overlay-dark);
  color: var(--clr-white);
  font-size: 0.7em;
  font-weight: 600;
  padding: 3px 9px;
  border-radius: 999px;
  transition: background 0.3s var(--ease);
}

.sbn-course-card:hover .sbn-course-pill {
  background: color-mix(in srgb, var(--clr-white) 85%, transparent);
  color: var(--clr-text);
}

/* Hover overlay */
.sbn-course-card-overlay {
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

.sbn-course-card:hover .sbn-course-card-overlay {
  opacity: 0.7;
  pointer-events: auto;
}

.sbn-course-view-btn {
  background: var(--clr-white);
  color: var(--clr-text);
  padding: 10px 24px;
  border-radius: var(--radius-sm);
  font-weight: 600;
  font-size: 0.85em;
  pointer-events: none;
}

/* Card body */
.sbn-course-card-body {
  padding: 12px 14px 14px;
}

.sbn-course-card-title {
  margin: 0 0 6px;
  font-size: 1em;
  font-weight: 600;
}

.sbn-course-card-title a {
  color: var(--clr-text);
  text-decoration: none;
}

.sbn-course-card-title a:hover {
  color: var(--clr-style-bossa);
}

.sbn-course-card-excerpt {
  margin: 0;
  color: var(--clr-text-muted);
  font-size: 0.875em;
  line-height: 1.45;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
</style>
