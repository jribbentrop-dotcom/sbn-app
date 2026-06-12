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
const genreLabel = computed(() => (props.course.primaryGenre ?? 'Course').replace(/-/g, ' ').replace(/\b\w/g, c => c.toUpperCase()));
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

      <!-- Top row: genre badge only -->
      <div class="sbn-course-card-badge-row">
        <span class="sbn-course-badge-genre">{{ genreLabel }}</span>
      </div>


<!-- View button -->
      <div class="sbn-course-card-btn-wrap">
        <span class="sbn-course-view-btn">View Course <span class="sbn-view-btn-arrow">→</span></span>
      </div>

    </Link>

    <div class="sbn-course-card-body">
      <div class="sbn-course-card-level">
        <span class="sbn-badge sbn-badge-muted">
          <span class="sbn-course-card-stars">
            <span v-for="i in 5" :key="i" :class="i <= stars ? 'star-filled' : 'star-empty'">★</span>
          </span>
          {{ levelLabel }}
        </span>
      </div>
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
  border: 1px solid var(--clr-border);
  border-radius: var(--radius);
  overflow: hidden;
  transition: border-color 0.2s var(--ease);
  position: relative;
  --category-color: var(--clr-style-default);
  --category-gradient: linear-gradient(
    135deg,
    var(--category-color) 0%,
    color-mix(in srgb, var(--category-color) 60%, white) 100%
  );
}

.sbn-course-card:hover {
  border-color: var(--clr-text-muted);
}

/* Image area — 1:1 square */
.sbn-course-card-image-wrap {
  display: block;
  position: relative;
  aspect-ratio: 1 / 1;
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




/* View button */
.sbn-course-card-btn-wrap {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  pointer-events: none;
  z-index: 5;
}

.sbn-course-view-btn {
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

.sbn-course-card:hover .sbn-course-view-btn {
  opacity: 1;
  transform: translateY(0) scale(1);
}

.sbn-course-view-btn:hover {
  box-shadow: 0 4px 12px rgba(0,0,0,0.18);
}

.sbn-view-btn-arrow {
  display: inline-block;
  transition: transform 0.2s var(--ease);
}

.sbn-course-view-btn:hover .sbn-view-btn-arrow {
  transform: translateX(4px);
}

/* Card body */
.sbn-course-card-body {
  padding: 12px 14px 14px;
}

.sbn-course-card-level {
  margin-bottom: 8px;
}

.sbn-course-card-stars {
  display: inline-flex;
  gap: 1px;
  margin-right: 4px;
}

.sbn-course-card-stars .star-filled { color: var(--clr-star); }
.sbn-course-card-stars .star-empty  { color: var(--clr-border); }

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
