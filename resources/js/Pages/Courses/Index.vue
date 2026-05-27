<script setup lang="ts">
import { computed, ref } from 'vue';
import CourseCard from '@/Components/Course/CourseCard.vue';

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

const props = defineProps<{
  courses: CourseData[];
  categories: string[];
  levels: string[];
}>();

const filterGenre = ref('');
const filterLevel = ref('');

const filtered = computed(() => props.courses.filter((course) => {
  if (filterGenre.value && course.primaryGenre !== filterGenre.value) return false;
  if (filterLevel.value && course.primaryLevel !== filterLevel.value) return false;
  return true;
}));

const grouped = computed(() => {
  const groups: Record<string, CourseData[]> = {};
  for (const course of filtered.value) {
    const key = course.primaryGenre || 'other';
    if (!groups[key]) groups[key] = [];
    groups[key].push(course);
  }
  return groups;
});
</script>

<template>
  <main class="sbn-page sbn-course-library-main">
    <header class="sbn-library-header">
      <h1 class="sbn-library-title">Course Library</h1>
      <p class="sbn-library-subtitle">Structured pathways from basics to advanced performance skills.</p>
    </header>

    <div class="sbn-count-bar">
      <strong>{{ filtered.length }}</strong> courses
    </div>

    <div class="sbn-content-wrapper">
      <section class="sbn-results-container">
        <div v-if="filterGenre || filterLevel" class="sbn-courses-grid">
          <CourseCard v-for="course in filtered" :key="course.id" :course="course" />
        </div>

        <template v-else>
          <section v-for="(items, genre) in grouped" :key="genre" class="sbn-course-genre-section">
            <h2>{{ String(genre).replace(/-/g, ' ').replace(/\b\w/g, c => c.toUpperCase()) }}</h2>
            <div class="sbn-courses-carousel">
              <CourseCard v-for="course in items" :key="course.id" :course="course" />
            </div>
          </section>
        </template>
      </section>

      <aside class="sbn-filter-sidebar">
        <div class="sbn-sidebar-section">
          <span class="sbn-sidebar-label">Category</span>
          <div class="sbn-sidebar-options">
            <button v-for="cat in categories" :key="cat" type="button" :class="['sbn-sidebar-option', { active: filterGenre === cat }]" @click="filterGenre = filterGenre === cat ? '' : cat">{{ cat.replace(/-/g, ' ').replace(/\b\w/g, c => c.toUpperCase()) }}</button>
          </div>
        </div>
        <div class="sbn-sidebar-section">
          <span class="sbn-sidebar-label">Level</span>
          <div class="sbn-sidebar-options">
            <button v-for="level in levels" :key="level" type="button" :class="['sbn-sidebar-option', { active: filterLevel === level }]" @click="filterLevel = filterLevel === level ? '' : level">{{ level }}</button>
          </div>
        </div>
      </aside>
    </div>
  </main>
</template>
