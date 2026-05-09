<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { getCategoryColor, difficultyLabel } from '@/composables/useCategoryColors';

interface Subsection { title: string; slug: string }
interface LessonStub {
  id: number;
  slug: string;
  title: string;
  sectionTitle: string | null;
  isPreview: boolean;
  sortOrder: number;
  subsections: Subsection[];
}
interface CourseData {
  slug: string;
  title: string;
  primaryGenre: string | null;
  primaryLevel: string | null;
  lessonCount: number;
}

const props = defineProps<{
  course: CourseData;
  lessons: LessonStub[];
  activeLessonSlug: string | null;
  activeSubsection: string | null;
  hasAccess: boolean;
  navCollapsed: boolean;
}>();

const emit = defineEmits<{
  collapse: [];
  expand: [];
  jumpSubsection: [slug: string];
  closeMobile: [];
}>();

const levelToStars: Record<string, number> = {
  basic: 1,
  'early-intermediate': 2,
  intermediate: 3,
  'late-intermediate': 4,
  advanced: 5,
};

const grouped = computed(() => {
  const out: Array<{ title: string; items: LessonStub[] }> = [];
  for (const lesson of props.lessons) {
    const title = lesson.sectionTitle || 'Lessons';
    const found = out.find((g) => g.title === title);
    if (found) found.items.push(lesson);
    else out.push({ title, items: [lesson] });
  }
  return out;
});

const flatLessons = computed(() => props.lessons);
const stars = computed(() => levelToStars[props.course.primaryLevel ?? ''] ?? 0);
const levelLabel = computed(() => difficultyLabel(stars.value));
const genreColor = computed(() => getCategoryColor(props.course.primaryGenre ?? undefined));
const doneCount = 0; // Phase 12 will track real completion
const totalCount = computed(() => props.lessons.length);

function lessonIndex(slug: string): number {
  return flatLessons.value.findIndex(l => l.slug === slug) + 1;
}
</script>

<template>
  <!-- ── COLLAPSED: B-style slim numbered rail ── -->
  <aside v-if="navCollapsed" class="vC-rail">
    <button
      type="button"
      class="vC-rail-toggle"
      title="Expand lesson list"
      @click="emit('expand')"
    >
      <svg width="14" height="14" viewBox="0 0 16 16">
        <path d="M6 4l4 4-4 4" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>
      </svg>
    </button>

    <div class="vC-rail-progress">
      <svg width="40" height="40" viewBox="0 0 40 40">
        <circle cx="20" cy="20" r="18" fill="none" stroke="var(--clr-border)" stroke-width="3" />
        <circle
          cx="20" cy="20" r="18" fill="none"
          stroke="var(--clr-accent)" stroke-width="3"
          stroke-linecap="round"
          :stroke-dasharray="`${Math.round(doneCount / Math.max(totalCount, 1) * 113)} 113`"
          transform="rotate(-90 20 20)"
        />
        <text x="20" y="24" text-anchor="middle" font-size="10" font-weight="700" fill="var(--clr-text-dim)">{{ Math.round(doneCount / Math.max(totalCount, 1) * 100) }}%</text>
      </svg>
    </div>

    <div class="vC-rail-divider" />

    <ol class="vC-rail-lessons">
      <li
        v-for="(lesson, i) in flatLessons"
        :key="lesson.slug"
        :class="{
          'is-current': activeLessonSlug === lesson.slug,
          'is-upcoming': activeLessonSlug !== lesson.slug,
        }"
        :title="`${i + 1}. ${lesson.title}`"
      >
        <button
          v-if="activeLessonSlug === lesson.slug"
          type="button"
          class="vC-rail-node"
          @click="emit('expand')"
        >
          <span>{{ i + 1 }}</span>
        </button>
        <Link v-else :href="`/learn/${course.slug}/play/${lesson.slug}`" class="vC-rail-node">
          <span>{{ i + 1 }}</span>
        </Link>
      </li>
    </ol>
  </aside>

  <!-- ── EXPANDED: full course nav ── -->
  <aside v-else class="vC-nav" :style="{ '--genre-color': genreColor }">
    <div class="vC-nav-hero">
      <div class="vC-nav-hero-top">
        <span class="sbn-badge" :style="{ background: `color-mix(in srgb, ${genreColor} 14%, white)`, color: genreColor }">
          {{ course.primaryGenre ?? 'Course' }}
        </span>
        <button
          type="button"
          class="vC-nav-collapse"
          title="Collapse lesson list"
          @click="emit('collapse')"
        >
          <svg width="14" height="14" viewBox="0 0 16 16">
            <path d="M10 4L6 8l4 4" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
        </button>
      </div>
      <h3>{{ course.title }}</h3>
      <div class="vC-nav-progress">
        <div class="vC-nav-progress-bar">
          <span :style="{ width: `${Math.round(doneCount / Math.max(totalCount, 1) * 100)}%` }" />
        </div>
        <span class="vC-nav-progress-text">{{ doneCount }} of {{ totalCount }} done</span>
      </div>
    </div>

    <div class="vC-nav-list">
      <div v-for="group in grouped" :key="group.title" class="vC-nav-section">
        <div class="vC-nav-label">{{ group.title }}</div>
        
        <template v-for="item in group.items" :key="item.id">
          <Link
            :href="`/learn/${course.slug}/play/${item.slug}`"
            class="vC-nav-item"
            :class="{
              'is-current': activeLessonSlug === item.slug,
            }"
          >
            <span class="vC-nav-num">{{ lessonIndex(item.slug) }}</span>
            <span class="vC-nav-title">
              {{ item.title }}
              <span v-if="!hasAccess && !item.isPreview" class="vC-nav-lock">🔒</span>
              <span v-else-if="item.isPreview" class="vC-nav-preview">Preview</span>
            </span>
          </Link>

          <!-- Subsections for active lesson -->
          <div
            v-if="activeLessonSlug === item.slug && item.subsections.length"
            class="vC-nav-subs"
          >
            <button
              v-for="sub in item.subsections"
              :key="sub.slug"
              type="button"
              class="vC-nav-sub-btn"
              :class="{ 'is-active': activeSubsection === sub.slug }"
              @click="emit('jumpSubsection', sub.slug)"
            >
              {{ sub.title }}
            </button>
          </div>
        </template>
      </div>
    </div>
  </aside>
</template>
