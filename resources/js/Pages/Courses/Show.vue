<script setup lang="ts">
import { computed, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import Breadcrumb from '@/Components/Breadcrumb.vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import RhythmLink from '@/Components/Library/RhythmLink.vue';
import type { RhythmPatternData } from '@/Components/Library/RhythmPattern.vue';
import { getCategoryColor, getCategoryStyle, difficultyLabel } from '@/composables/useCategoryColors';

defineOptions({ layout: PublicLayout });

// ── Types ────────────────────────────────────────────────────────────────────

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
  id: number;
  slug: string;
  title: string;
  excerpt: string | null;
  category: string | null;
  levels: string[];
  topics: string[];
  primaryGenre: string | null;
  primaryLevel: string | null;
  lessonCount: number;
  isFree: boolean;
  isGated: boolean;
  featuredImagePath: string | null;
  productSlug: string | null;
}

interface SongRef {
  id: number;
  slug: string;
  title: string;
  composer: string | null;
  key: string | null;
  tempo: number | null;
  rhythm: string | null;
}

interface RhythmRef {
  id: number;
  slug: string;
  name: string;
  category: string;
  styleSlug: string;
  description: string | null;
  bpm: number;
  timeSignature: string;
  playerData: RhythmPatternData;
}

const props = defineProps<{
  course: CourseData;
  lessons: LessonStub[];
  songs: SongRef[];
  rhythms: RhythmRef[];
}>();

// ── Computed ─────────────────────────────────────────────────────────────────

const levelToStars: Record<string, number> = {
  basic: 1,
  'early-intermediate': 2,
  intermediate: 3,
  'late-intermediate': 4,
  advanced: 5,
};

const stars = computed(() => levelToStars[props.course.primaryLevel ?? ''] ?? 0);
const levelLabel = computed(() => difficultyLabel(stars.value));
const genreColor = computed(() => getCategoryColor(props.course.primaryGenre ?? undefined));
const heroStyle = computed(() => getCategoryStyle(props.course.primaryGenre ?? undefined));
const genreLabel = computed(() => (props.course.primaryGenre ?? 'Course').replace(/-/g, ' '));

const ctaHref = computed(() =>
  props.course.productSlug ? `/shop/product/${props.course.productSlug}` : '/shop'
);

const grouped = computed(() => {
  const out: Array<{ title: string; items: LessonStub[] }> = [];
  for (const lesson of props.lessons) {
    const title = lesson.sectionTitle || 'Course Lessons';
    const found = out.find((g) => g.title === title);
    if (found) found.items.push(lesson);
    else out.push({ title, items: [lesson] });
  }
  return out;
});

// Expand/collapse lesson sections — all open by default
const collapsedSections = ref<Set<string>>(new Set());
function toggleSection(title: string): void {
  if (collapsedSections.value.has(title)) collapsedSections.value.delete(title);
  else collapsedSections.value.add(title);
}
function isSectionOpen(title: string): boolean {
  return !collapsedSections.value.has(title);
}

const totalSections = computed(() => grouped.value.length);

</script>

<template>
  <div class="sbn-page-detail sbn-course-show" :style="heroStyle">

    <Breadcrumb :segments="[{ label: 'Courses', href: '/courses' }, { label: course.title }]" :color="genreColor" />

    <!-- ── Hero ──────────────────────────────────────────────────────────── -->
    <header class="sbn-cs-hero sbn-detail-hero">

      <div class="sbn-cs-hero-body">
        <!-- Left: text content -->
        <div class="sbn-cs-hero-text">
          <div class="sbn-cs-hero-badges">
            <span class="sbn-cat-badge sbn-cat-badge-filled" :style="{ '--cat-clr': getCategoryColor(course.category ?? '') }">
              {{ genreLabel }}
            </span>
            <span class="sbn-badge sbn-badge-muted">
              <span class="sbn-cs-stars">
                <span v-for="i in 5" :key="i" :class="i <= stars ? 'star-on' : 'star-off'">★</span>
              </span>
              {{ levelLabel }}
            </span>
            <span v-if="course.isFree" class="sbn-badge sbn-badge-success">Free</span>
          </div>

          <h1 class="sbn-cs-title">{{ course.title }}</h1>
          <p v-if="course.excerpt" class="sbn-cs-excerpt">{{ course.excerpt }}</p>

          <!-- Quick stats row -->
          <div class="sbn-cs-stats">
            <div class="sbn-cs-stat">
              <span class="sbn-cs-stat-num">{{ course.lessonCount }}</span>
              <span class="sbn-cs-stat-lbl">lessons</span>
            </div>
            <div class="sbn-cs-stat-div" />
            <div class="sbn-cs-stat">
              <span class="sbn-cs-stat-num">{{ totalSections }}</span>
              <span class="sbn-cs-stat-lbl">sections</span>
            </div>
            <div v-if="songs.length" class="sbn-cs-stat-div" />
            <div v-if="songs.length" class="sbn-cs-stat">
              <span class="sbn-cs-stat-num">{{ songs.length }}</span>
              <span class="sbn-cs-stat-lbl">songs</span>
            </div>
          </div>

          <!-- Topics -->
          <div v-if="course.topics?.length" class="sbn-cs-topics">
            <span
              v-for="topic in course.topics"
              :key="topic"
              class="sbn-cs-topic-pill"
            >
              {{ topic }}
            </span>
          </div>

          <!-- CTAs -->
          <div class="sbn-cs-cta-row">
            <Link :href="`/learn/${course.slug}/play`" class="sbn-btn sbn-btn-primary sbn-btn-lg">
              Start learning →
            </Link>
            <Link v-if="course.isGated && course.productSlug" :href="ctaHref" class="sbn-btn sbn-btn-secondary">
              Buy course
            </Link>
          </div>
        </div>

        <!-- Right: featured image or gradient tile -->
        <div class="sbn-cs-hero-image">
          <img
            v-if="course.featuredImagePath"
            :src="course.featuredImagePath"
            :alt="course.title"
            class="sbn-cs-hero-img"
          />
          <div v-else class="sbn-cs-hero-fallback">
            <span class="sbn-cs-hero-fallback-label">{{ genreLabel }}</span>
          </div>
        </div>
      </div>
    </header>

    <!-- ── Two-column body ───────────────────────────────────────────────── -->
    <div class="sbn-cs-body">

      <!-- Main column -->
      <div class="sbn-cs-main">

        <!-- Lesson list ---------------------------------------------------- -->
        <section class="sbn-cs-section">
          <h2 class="sbn-cs-section-title">
            Course contents
            <span class="sbn-cs-section-count">{{ course.lessonCount }} lessons</span>
          </h2>

          <div class="sbn-cs-lesson-list">
            <div
              v-for="(group, gi) in grouped"
              :key="group.title"
              class="sbn-cs-lesson-group"
            >
              <!-- Section header -->
              <button
                type="button"
                class="sbn-cs-section-header"
                :class="{ 'is-open': isSectionOpen(group.title) }"
                @click="toggleSection(group.title)"
              >
                <span class="sbn-cs-section-num">{{ gi + 1 }}</span>
                <span class="sbn-cs-section-name">{{ group.title }}</span>
                <span class="sbn-cs-section-meta">{{ group.items.length }} lesson{{ group.items.length !== 1 ? 's' : '' }}</span>
                <svg class="sbn-cs-section-chevron" width="14" height="14" viewBox="0 0 14 14">
                  <path d="M3 5l4 4 4-4" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
              </button>

              <!-- Lesson rows -->
              <div v-if="isSectionOpen(group.title)" class="sbn-cs-lesson-rows">
                <Link
                  v-for="(lesson, li) in group.items"
                  :key="lesson.id"
                  :href="`/learn/${course.slug}/play/${lesson.slug}`"
                  class="sbn-cs-lesson-row"
                >
                  <span class="sbn-cs-lesson-num">{{ li + 1 }}</span>
                  <span class="sbn-cs-lesson-title">{{ lesson.title }}</span>
                  <span v-if="lesson.subsections.length" class="sbn-cs-lesson-subs">
                    {{ lesson.subsections.length }} parts
                  </span>
                  <span v-if="lesson.isPreview" class="sbn-cs-preview-pill">Preview</span>
                  <svg class="sbn-cs-lesson-arrow" width="12" height="12" viewBox="0 0 12 12">
                    <path d="M4 2l4 4-4 4" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                  </svg>
                </Link>
              </div>
            </div>
          </div>
        </section>

        <!-- Related songs -------------------------------------------------- -->
        <section v-if="songs.length" class="sbn-cs-section">
          <h2 class="sbn-cs-section-title">
            Songs in this style
            <span class="sbn-cs-section-count">{{ songs.length }}</span>
          </h2>

          <div class="sbn-cs-song-list">
            <Link
              v-for="song in songs"
              :key="song.id"
              :href="`/library/songs/${song.slug}`"
              class="sbn-cs-song-row"
            >
              <div class="sbn-cs-song-row-main">
                <span class="sbn-cs-song-title">{{ song.title }}</span>
                <span v-if="song.composer" class="sbn-cs-song-composer">{{ song.composer }}</span>
              </div>
              <div class="sbn-cs-song-row-meta">
                <span v-if="song.key" class="sbn-cs-song-chip">{{ song.key }}</span>
                <span v-if="song.tempo" class="sbn-cs-song-chip">{{ song.tempo }} bpm</span>
              </div>
              <svg class="sbn-cs-song-arrow" width="12" height="12" viewBox="0 0 12 12">
                <path d="M4 2l4 4-4 4" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
              </svg>
            </Link>
          </div>

          <div class="sbn-cs-section-footer">
            <Link href="/library/songs" class="sbn-btn sbn-btn-ghost sbn-btn-sm">Browse all songs →</Link>
          </div>
        </section>

      </div><!-- /main -->

      <!-- Sidebar -->
      <aside class="sbn-cs-sidebar">

        <!-- Start CTA card ------------------------------------------------- -->
        <div class="sbn-cs-sidebar-card sbn-cs-cta-card" :style="{ '--genre-color': genreColor }">
          <div class="sbn-cs-cta-card-bar" />
          <div class="sbn-cs-cta-card-body">
            <div class="sbn-cs-cta-eyebrow">
              <span class="sbn-cs-stars">
                <span v-for="i in 5" :key="i" :class="i <= stars ? 'star-on' : 'star-off'">★</span>
              </span>
              {{ levelLabel }}
            </div>
            <div class="sbn-cs-cta-meta">
              <span>{{ course.lessonCount }} lessons</span>
              <span>·</span>
              <span>{{ course.isFree ? 'Free' : 'Premium' }}</span>
            </div>
            <Link :href="`/learn/${course.slug}/play`" class="sbn-btn sbn-btn-primary sbn-cs-cta-btn">
              Start learning →
            </Link>
            <Link v-if="course.isGated && course.productSlug" :href="ctaHref" class="sbn-btn sbn-btn-secondary sbn-cs-cta-btn">
              Buy course
            </Link>
          </div>
        </div>

        <!-- Rhythms card --------------------------------------------------- -->
        <div v-if="rhythms.length" class="sbn-cs-sidebar-card">
          <div class="sbn-cs-card-eyebrow">
            <span>Rhythms used</span>
            <span class="sbn-cs-card-count">{{ rhythms.length }}</span>
          </div>

          <div class="sbn-cs-rhythm-list">
            <RhythmLink
              v-for="rhythm in rhythms"
              :key="rhythm.id"
              :rhythm="rhythm"
            />
          </div>

          <div class="sbn-cs-section-footer">
            <Link href="/library/rhythms" class="sbn-btn sbn-btn-ghost sbn-btn-sm">All rhythms →</Link>
          </div>
        </div>

        <!-- Quick info card ------------------------------------------------ -->
        <div class="sbn-cs-sidebar-card">
          <div class="sbn-cs-card-eyebrow">
            <span>Course info</span>
          </div>
          <dl class="sbn-cs-info-dl">
            <div v-if="course.primaryGenre" class="sbn-cs-info-row">
              <dt>Style</dt>
              <dd>{{ genreLabel }}</dd>
            </div>
            <div v-if="course.primaryLevel" class="sbn-cs-info-row">
              <dt>Level</dt>
              <dd>{{ levelLabel }}</dd>
            </div>
            <div class="sbn-cs-info-row">
              <dt>Lessons</dt>
              <dd>{{ course.lessonCount }}</dd>
            </div>
            <div v-if="course.topics?.length" class="sbn-cs-info-row">
              <dt>Topics</dt>
              <dd>{{ course.topics.join(', ') }}</dd>
            </div>
            <div class="sbn-cs-info-row">
              <dt>Access</dt>
              <dd>{{ course.isFree ? 'Free' : 'Premium' }}</dd>
            </div>
          </dl>
        </div>

      </aside>
    </div><!-- /body -->

  </div>
</template>

<style scoped>
/* ── Page wrapper ──────────────────────────────────────────────────────────── */
.sbn-course-show {
  --category-color: var(--clr-style-bossa);
  --category-gradient: linear-gradient(135deg, var(--category-color) 0%, color-mix(in srgb, var(--category-color) 60%, white) 100%);
}


/* ── Hero ──────────────────────────────────────────────────────────────────── */
.sbn-cs-hero {
  overflow: hidden;
  margin-bottom: 28px;
}

.sbn-cs-hero-body {
  display: grid;
  grid-template-columns: 1fr 280px;
  gap: 0;
}

.sbn-cs-hero-text {
  padding: 28px 32px 28px;
}

/* Badges row */
.sbn-cs-hero-badges {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 14px;
}

.sbn-cs-stars { display: inline-flex; gap: 1px; }
.star-on  { color: var(--clr-accent); }
.star-off { color: var(--clr-border); }

/* Title / excerpt */
.sbn-cs-title {
  font-size: 2em;
  font-weight: 800;
  letter-spacing: -0.025em;
  line-height: 1.15;
  color: var(--clr-text);
  margin: 0 0 10px;
}

.sbn-cs-excerpt {
  font-size: 1em;
  line-height: 1.65;
  color: var(--clr-text-muted);
  margin: 0 0 18px;
  max-width: 540px;
}

/* Stats row */
.sbn-cs-stats {
  display: flex;
  align-items: center;
  gap: 14px;
  margin-bottom: 16px;
}
.sbn-cs-stat { display: flex; flex-direction: column; gap: 1px; }
.sbn-cs-stat-num {
  font-family: var(--font-chord, Georgia, serif);
  font-size: 22px;
  font-weight: 600;
  color: var(--clr-text);
  line-height: 1;
}
.sbn-cs-stat-lbl {
  font-size: 11px;
  color: var(--clr-text-muted);
  text-transform: uppercase;
  letter-spacing: 0.04em;
}
.sbn-cs-stat-div {
  width: 1px;
  height: 28px;
  background: var(--clr-border);
}

/* Topic pills */
.sbn-cs-topics {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin-bottom: 22px;
}
.sbn-cs-topic-pill {
  background: var(--clr-accent-bg);
  color: var(--clr-accent-dim);
  border: 1px solid var(--clr-accent-border);
  border-radius: 999px;
  padding: 3px 10px;
  font-size: 11.5px;
  font-weight: 600;
  text-transform: capitalize;
}

/* CTAs */
.sbn-cs-cta-row {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
}

/* Hero image / fallback */
.sbn-cs-hero-image {
  position: relative;
  overflow: hidden;
  min-height: 240px;
}
.sbn-cs-hero-img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}
.sbn-cs-hero-fallback {
  width: 100%;
  height: 100%;
  background: var(--category-gradient);
  display: flex;
  align-items: center;
  justify-content: center;
}
.sbn-cs-hero-fallback-label {
  font-family: var(--font-chord, Georgia, serif);
  font-size: 22px;
  font-weight: 600;
  color: white;
  text-transform: capitalize;
  opacity: 0.85;
  letter-spacing: 0.04em;
}

/* ── Two-column body ───────────────────────────────────────────────────────── */
.sbn-cs-body {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 300px;
  gap: 24px;
  align-items: start;
}

/* ── Section titles ────────────────────────────────────────────────────────── */
.sbn-cs-section {
  margin-bottom: 32px;
}

.sbn-cs-section-title {
  font-size: 1em;
  font-weight: 700;
  color: var(--clr-text);
  margin: 0 0 14px;
  padding-bottom: 8px;
  border-bottom: 2px solid var(--clr-border);
  display: flex;
  align-items: center;
  gap: 10px;
}

.sbn-cs-section-count {
  font-size: 12px;
  font-weight: 600;
  color: var(--clr-text-muted);
  background: var(--clr-surface-3);
  border-radius: 999px;
  padding: 2px 8px;
}

.sbn-cs-section-footer {
  margin-top: 10px;
}

/* ── Lesson accordion ──────────────────────────────────────────────────────── */
.sbn-cs-lesson-list {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.sbn-cs-lesson-group {
  border: 1px solid var(--clr-border);
  border-radius: var(--radius);
  overflow: hidden;
}

.sbn-cs-section-header {
  display: grid;
  grid-template-columns: 26px 1fr auto 16px;
  gap: 10px;
  align-items: center;
  width: 100%;
  padding: 12px 14px;
  background: var(--clr-surface-2);
  border: 0;
  cursor: pointer;
  text-align: left;
  transition: background 0.12s;
}
.sbn-cs-section-header:hover { background: var(--clr-surface-3); }
.sbn-cs-section-header.is-open { background: var(--clr-white); }

.sbn-cs-section-num {
  display: grid;
  place-items: center;
  width: 26px;
  height: 26px;
  border-radius: 999px;
  background: var(--clr-white);
  border: 1.5px solid var(--clr-border);
  color: var(--clr-text-muted);
  font-size: 11px;
  font-weight: 700;
  flex-shrink: 0;
  transition: all 0.2s;
}

.sbn-cs-section-header.is-open .sbn-cs-section-num {
  background: var(--clr-gradient);
  color: white;
  border-color: transparent;
  box-shadow: 0 0 0 3px rgba(243, 156, 18, 0.18);
}

.sbn-cs-section-name {
  font-size: 13.5px;
  font-weight: 700;
  color: var(--clr-text);
  line-height: 1.3;
}

.sbn-cs-section-meta {
  font-size: 12px;
  color: var(--clr-text-muted);
  white-space: nowrap;
}

.sbn-cs-section-chevron {
  color: var(--clr-text-muted);
  transition: transform 0.2s;
  flex-shrink: 0;
}
.sbn-cs-section-header.is-open .sbn-cs-section-chevron {
  transform: rotate(180deg);
}

/* Lesson rows */
.sbn-cs-lesson-rows {
  display: flex;
  flex-direction: column;
}

.sbn-cs-lesson-row {
  display: grid;
  grid-template-columns: 26px 1fr auto auto 14px;
  gap: 10px;
  align-items: center;
  padding: 10px 14px;
  text-decoration: none;
  color: var(--clr-text-dim);
  border-top: 1px solid var(--clr-border);
  font-size: 13.5px;
  transition: background 0.1s;
}
.sbn-cs-lesson-row:hover {
  background: var(--clr-accent-bg);
  color: var(--clr-text);
}
.sbn-cs-lesson-row:hover .sbn-cs-lesson-arrow { color: var(--clr-accent-dim); }

.sbn-cs-lesson-num {
  display: grid;
  place-items: center;
  width: 22px;
  height: 22px;
  border-radius: 999px;
  background: var(--clr-white);
  border: 1.5px solid var(--clr-border-dim);
  font-size: 10px;
  font-weight: 700;
  color: var(--clr-text-muted);
  text-align: center;
  flex-shrink: 0;
  transition: all 0.15s;
}

.sbn-cs-lesson-row:hover .sbn-cs-lesson-num {
  border-color: var(--clr-accent-border);
  color: var(--clr-accent-dim);
}

.sbn-cs-lesson-title { font-weight: 500; line-height: 1.35; }

.sbn-cs-lesson-subs {
  font-size: 11px;
  color: var(--clr-text-muted);
  white-space: nowrap;
}

.sbn-cs-preview-pill {
  font-size: 10px;
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  color: var(--clr-accent-dim);
  background: var(--clr-accent-bg);
  border: 1px solid var(--clr-accent-border);
  padding: 2px 7px;
  border-radius: 999px;
  white-space: nowrap;
}

.sbn-cs-lesson-arrow {
  color: var(--clr-border);
  transition: color 0.1s;
}

/* ── Song list ─────────────────────────────────────────────────────────────── */
.sbn-cs-song-list {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.sbn-cs-song-row {
  display: grid;
  grid-template-columns: 1fr auto 14px;
  gap: 12px;
  align-items: center;
  padding: 10px 14px;
  background: var(--clr-white);
  border: 1px solid var(--clr-border);
  border-radius: var(--radius-sm);
  text-decoration: none;
  color: var(--clr-text);
  transition: all 0.12s;
}
.sbn-cs-song-row:hover {
  border-color: var(--clr-accent-border);
  background: var(--clr-accent-bg);
}
.sbn-cs-song-row:hover .sbn-cs-song-arrow { color: var(--clr-accent-dim); }

.sbn-cs-song-row-main { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
.sbn-cs-song-title { font-size: 13.5px; font-weight: 600; color: var(--clr-text); }
.sbn-cs-song-composer { font-size: 12px; color: var(--clr-text-muted); }

.sbn-cs-song-row-meta { display: flex; gap: 6px; align-items: center; }
.sbn-cs-song-chip {
  font-size: 11px;
  font-weight: 600;
  background: var(--clr-surface-3);
  color: var(--clr-text-muted);
  padding: 2px 7px;
  border-radius: 999px;
  white-space: nowrap;
}

.sbn-cs-song-arrow { color: var(--clr-border); }

/* ── Sidebar ───────────────────────────────────────────────────────────────── */
.sbn-cs-sidebar {
  position: sticky;
  top: calc(var(--site-header-h, 96px) + 16px);
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.sbn-cs-sidebar-card {
  background: var(--clr-white);
  border: 1px solid var(--clr-border);
  border-radius: var(--radius);
  overflow: hidden;
}

/* CTA card */
.sbn-cs-cta-card {
  --genre-color: var(--clr-style-bossa);
  --genre-gradient: linear-gradient(135deg, var(--genre-color) 0%, color-mix(in srgb, var(--genre-color) 60%, white) 100%);
}
.sbn-cs-cta-card-bar {
  height: 4px;
  background: var(--genre-gradient);
}
.sbn-cs-cta-card-body {
  padding: 18px;
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.sbn-cs-cta-eyebrow {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 12px;
  font-weight: 600;
  color: var(--clr-text-dim);
}
.sbn-cs-cta-meta {
  display: flex;
  gap: 6px;
  font-size: 12px;
  color: var(--clr-text-muted);
}
.sbn-cs-cta-btn { width: 100%; justify-content: center; }

/* Card eyebrow */
.sbn-cs-card-eyebrow {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 12px 14px 10px;
  font-size: 11px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: var(--clr-text-muted);
  border-bottom: 1px solid var(--clr-border);
}
.sbn-cs-card-count {
  font-weight: 600;
  color: var(--clr-text-muted);
  text-transform: none;
  letter-spacing: 0;
  font-size: 12px;
}

/* Rhythm list in sidebar */
.sbn-cs-rhythm-list {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

/* Section footer inside sidebar cards */
.sbn-cs-sidebar-card .sbn-cs-section-footer {
  padding: 8px 14px 12px;
  border-top: 1px solid var(--clr-border);
  margin: 0;
}

/* Info dl */
.sbn-cs-info-dl {
  display: flex;
  flex-direction: column;
  gap: 0;
  padding: 6px 0;
}
.sbn-cs-info-row {
  display: flex;
  justify-content: space-between;
  align-items: baseline;
  gap: 12px;
  padding: 8px 14px;
  border-bottom: 1px solid var(--clr-border);
  font-size: 13px;
}
.sbn-cs-info-row:last-child { border-bottom: 0; }
.sbn-cs-info-row dt { color: var(--clr-text-muted); font-weight: 500; flex-shrink: 0; }
.sbn-cs-info-row dd {
  margin: 0;
  color: var(--clr-text);
  font-weight: 600;
  text-align: right;
  text-transform: capitalize;
}

/* ── Responsive ────────────────────────────────────────────────────────────── */
@media (max-width: 900px) {
  .sbn-cs-hero-body {
    grid-template-columns: 1fr;
  }
  .sbn-cs-hero-image {
    min-height: 180px;
    order: -1;
  }
  .sbn-cs-hero-text {
    padding: 20px 20px 22px;
  }
  .sbn-cs-body {
    grid-template-columns: 1fr;
  }
  .sbn-cs-sidebar {
    position: static;
  }
  .sbn-cs-title {
    font-size: 1.6em;
  }
}

@media (max-width: 600px) {
.sbn-cs-lesson-row { grid-template-columns: 22px 1fr 14px; }
  .sbn-cs-lesson-subs,
  .sbn-cs-preview-pill { display: none; }
}
</style>
