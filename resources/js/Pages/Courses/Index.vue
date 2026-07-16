<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import CourseCard from '@/Components/Course/CourseCard.vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import FilterToggleButton from '@/Components/Library/FilterToggleButton.vue';
import FilterSidebar from '@/Components/Library/FilterSidebar.vue';

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

defineOptions({ layout: PublicLayout });

// Initialize filters from the URL query (?genre=, ?level=) so deep links — e.g.
// the mega-menu "By Style" / "By Level" cards — open pre-filtered. Unknown values
// are ignored so a bad param falls back to the full catalogue rather than empty.
const initialQuery = typeof window !== 'undefined'
  ? new URLSearchParams(window.location.search)
  : new URLSearchParams();
const queryGenre = initialQuery.get('genre') ?? '';
const queryLevel = initialQuery.get('level') ?? '';
// ?slugs= is a comma-separated allow-list used by "View all" links from a
// chord/rhythm/progression/song show page, scoping the catalogue down to the
// exact related-courses set that page computed (tag match + category fallback).
const querySlugs = (initialQuery.get('slugs') ?? '').split(',').map((s) => s.trim()).filter(Boolean);

const search      = ref('');
const filtersOpen = ref(false);
const filterGenre = ref(props.categories.includes(queryGenre) ? queryGenre : '');
const filterLevel = ref(props.levels.includes(queryLevel) ? queryLevel : '');
const filterSlugs = ref<string[]>(querySlugs);

const filtered = computed(() => props.courses.filter((course) => {
  if (filterGenre.value && course.primaryGenre !== filterGenre.value) return false;
  if (filterLevel.value && course.primaryLevel !== filterLevel.value) return false;
  if (filterSlugs.value.length && !filterSlugs.value.includes(course.slug)) return false;
  if (search.value.trim()) {
    const q = search.value.toLowerCase();
    const hay = [course.title, course.excerpt, course.primaryGenre].filter(Boolean).join(' ').toLowerCase();
    if (!hay.includes(q)) return false;
  }
  return true;
}));

const hasFilters = computed(() => !!(search.value || filterGenre.value || filterLevel.value || filterSlugs.value.length));

const grouped = computed(() => {
  const groups: Record<string, CourseData[]> = {};
  for (const course of filtered.value) {
    const key = course.primaryGenre || 'other';
    if (!groups[key]) groups[key] = [];
    groups[key].push(course);
  }
  return groups;
});

function clearFilters() {
  search.value      = '';
  filterGenre.value = '';
  filterLevel.value = '';
  filterSlugs.value = [];
}

// JSON-LD: ItemList of the full (unfiltered) catalogue so Google can see all
// course URLs from this one page, independent of the client-side filter UI.
const courseListJsonLd = JSON.stringify({
  '@context': 'https://schema.org',
  '@type': 'ItemList',
  itemListElement: props.courses.map((course, i) => ({
    '@type': 'ListItem',
    position: i + 1,
    url: `https://www.soulbossanova.com/learn/${course.slug}`,
    name: course.title,
  })),
}).replace(/</g, '\\u003c');
</script>

<template>
    <Head>
        <title>Bossa Nova Guitar Courses | Soul Bossa Nova</title>
        <meta name="description" content="Video courses for Bossa Nova guitar — from beginner fundamentals to advanced harmony and technique, with leadsheets and interactive exercises." />
        <meta property="og:title" content="Bossa Nova Guitar Courses | Soul Bossa Nova" />
        <meta property="og:description" content="Structured Bossa Nova guitar courses with video lessons, leadsheets and interactive exercises for all levels." />
        <meta property="og:type" content="website" />
        <component :is="'script'" type="application/ld+json">{{ courseListJsonLd }}</component>
    </Head>

  <main class="sbn-page sbn-course-library-main">
    <div class="sbn-lib-page-header">
      <h1 class="sbn-lib-page-title">Course Library</h1>
      <p class="sbn-lib-page-subtitle">Structured pathways from basics to advanced performance skills.</p>

      <div class="sbn-lib-search-wrap">
        <div class="sbn-lib-search-box">
          <svg class="sbn-lib-search-icon" width="18" height="18" viewBox="0 0 20 20" fill="none">
            <circle cx="9" cy="9" r="6" stroke="currentColor" stroke-width="1.8"/>
            <path d="M15 15l3.5 3.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
          </svg>
          <input
            v-model="search"
            type="text"
            class="sbn-lib-search-input"
            placeholder="Search courses..."
            autocomplete="off"
          />
          <button
            v-if="search"
            @click="search = ''"
            class="sbn-lib-search-clear"
            aria-label="Clear search"
          >
            <svg width="12" height="12" viewBox="0 0 14 14" fill="none">
              <path d="M1 1l12 12M13 1L1 13" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
            </svg>
          </button>
        </div>

        <FilterToggleButton v-model="filtersOpen" :has-filters="hasFilters">Filters</FilterToggleButton>
      </div>
    </div>

    <div class="sbn-lib-content-wrapper">
      <section class="sbn-lib-list-container">
        <div v-if="hasFilters" class="sbn-courses-grid">
          <CourseCard v-for="course in filtered" :key="course.id" :course="course" />
        </div>

        <template v-else>
          <section v-for="(items, genre) in grouped" :key="genre" class="sbn-lib-category-section">
            <h2 class="sbn-lib-category-header" :class="`sbn-lib-category-header--${genre}`">
              {{ String(genre).replace(/-/g, ' ').replace(/\b\w/g, c => c.toUpperCase()) }}
              <span class="sbn-lib-category-count">{{ items.length }}</span>
            </h2>
            <div class="sbn-courses-carousel">
              <CourseCard v-for="course in items" :key="course.id" :course="course" />
            </div>
          </section>
        </template>
      </section>

      <FilterSidebar v-model="filtersOpen" :has-filters="hasFilters" @clear="clearFilters">
        <template #title>Filter</template>
        <template #count><strong>{{ filtered.length }}</strong> course{{ filtered.length !== 1 ? 's' : '' }}</template>

        <div class="sbn-lib-sidebar-section">
          <span class="sbn-lib-sidebar-label">Category</span>
          <div class="sbn-lib-sidebar-options">
            <button
              v-for="cat in categories"
              :key="cat"
              type="button"
              :class="['sbn-lib-sidebar-option', { 'sbn-filter-active': filterGenre === cat }]"
              @click="filterGenre = filterGenre === cat ? '' : cat"
            >{{ cat.replace(/-/g, ' ').replace(/\b\w/g, c => c.toUpperCase()) }}</button>
          </div>
        </div>

        <div class="sbn-lib-sidebar-section">
          <span class="sbn-lib-sidebar-label">Level</span>
          <div class="sbn-lib-sidebar-options">
            <button
              v-for="level in levels"
              :key="level"
              type="button"
              :class="['sbn-lib-sidebar-option', { 'sbn-filter-active': filterLevel === level }]"
              @click="filterLevel = filterLevel === level ? '' : level"
            >{{ level }}</button>
          </div>
        </div>

        <template #clear-label>Clear all filters</template>
      </FilterSidebar>
    </div>
  </main>
</template>
