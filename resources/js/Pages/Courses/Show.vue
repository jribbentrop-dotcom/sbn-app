<script setup lang="ts">
import { computed, ref, reactive, onMounted } from 'vue';
import { Link, Head, usePage } from '@inertiajs/vue3';
import Breadcrumb from '@/Components/Breadcrumb.vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import RhythmStrip from '@/Components/Library/RhythmStrip.vue';
import ChordDiagram from '@/Components/Library/ChordDiagram.vue';
import SkillIcon from '@/Components/Skill/SkillIcon.vue';
import type { RhythmPatternData } from '@/Components/Library/RhythmPattern.vue';
import { getCategoryColor, getCategoryStyle, difficultyLabel } from '@/composables/useCategoryColors';
import { formatChordNameHtml } from '@/composables/useChordName';

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
  description: string | null;
  category: string | null;
  levels: string[];
  primaryGenre: string | null;
  primaryLevel: string | null;
  lessonCount: number;
  isFree: boolean;
  isGated: boolean;
  featuredImagePath: string | null;
  productSlug: string | null;
  learningOutcomes: string[];
}

interface RhythmRef {
  id: number;
  slug: string;
  name: string;
  category: string;
  styleSlug: string;
  bpm: number;
  timeSignature: string;
  playerData: RhythmPatternData;
  lessonSlug: string | null;
}

interface ChordRef {
  slug: string;
  name: string;
  rootNote: string | null;
  lessonSlug: string | null;
}

interface ExerciseRef {
  slug: string;
  title: string;
  keyCenter: string | null;
  type: string | null;
  lessonSlug: string | null;
}

interface SongRef {
  slug: string;
  title: string;
  composer: string | null;
  lessonSlug: string | null;
}

interface ProgressionRef {
  slug: string;
  name: string;
  category: string;
  lessonSlug: string | null;
}

interface WidgetRef {
  slug: string;
  title: string;
  lessonSlug: string | null;
}

interface SkillRef {
  slug: string;
  title: string;
  branch: string;
  grade: number | null;
  iconKey: string | null;
  iconPath: string | null;
  done: boolean;
}

const props = defineProps<{
  course: CourseData;
  lessons: LessonStub[];
  rhythms: RhythmRef[];
  chords: ChordRef[];
  exercises: ExerciseRef[];
  songs: SongRef[];
  progressions: ProgressionRef[];
  widgets: WidgetRef[];
  skills: SkillRef[];
}>();

// ── Chord diagram data (fetched from API, same pattern as PracticePanel) ─────
const chordDiagramData = ref<Record<string, any>>({});

onMounted(async () => {
  if (!props.chords.length) return;
  await Promise.all(props.chords.map(async (c) => {
    try {
      const qs = c.rootNote ? `?root=${encodeURIComponent(c.rootNote)}` : '';
      const res = await fetch(`/api/sbn/chords/${encodeURIComponent(c.slug)}${qs}`, {
        headers: { Accept: 'application/json' },
      });
      if (res.ok) chordDiagramData.value[c.slug] = await res.json();
    } catch { /* silent */ }
  }));
});

function lessonUrl(lessonSlug: string | null): string {
  if (!lessonSlug) return `/learn/${props.course.slug}/play`;
  return `/learn/${props.course.slug}/play/${lessonSlug}`;
}

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
const genreLabel = computed(() =>
  (props.course.primaryGenre ?? 'Course').replace(/-/g, ' ').replace(/\b\w/g, c => c.toUpperCase())
);

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

const collapsedSections = ref<Set<string>>(new Set());
function toggleSection(title: string): void {
  if (collapsedSections.value.has(title)) collapsedSections.value.delete(title);
  else collapsedSections.value.add(title);
}
function isSectionOpen(title: string): boolean {
  return !collapsedSections.value.has(title);
}

const totalSections = computed(() => grouped.value.length);
const learningOutcomes = computed(() => props.course.learningOutcomes ?? []);

// ── Skills this course teaches ───────────────────────────────────────────────
// Signed-in students can self-report completion right here (same toggle as
// /account/skills). Guests see the list read-only — clicking sends them to register.
const isAuthed = computed(() => !!usePage().props.auth?.user);

const skillDone = reactive<Record<string, true>>(
  Object.fromEntries(props.skills.filter(s => s.done).map(s => [s.slug, true]))
);
const skillPending = reactive<Record<string, true>>({});

function toggleSkill(skill: SkillRef) {
  if (!isAuthed.value) { window.location.href = '/register'; return; }
  if (skillPending[skill.slug]) return;
  skillPending[skill.slug] = true;

  const wasDone = !!skillDone[skill.slug];
  wasDone ? delete skillDone[skill.slug] : (skillDone[skill.slug] = true);

  fetch(`/account/skills/${skill.slug}/toggle`, {
    method: 'POST',
    headers: { 'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '' },
  }).catch(() => {
    wasDone ? (skillDone[skill.slug] = true) : delete skillDone[skill.slug];
  }).finally(() => {
    delete skillPending[skill.slug];
  });
}
const hasExplore = computed(() =>
  props.chords.length || props.rhythms.length || props.exercises.length ||
  props.songs.length || props.progressions.length || props.widgets.length
);

type SidebarSection = 'chords' | 'rhythms' | 'exercises' | 'songs' | 'progressions' | 'widgets';

const openSection = ref<SidebarSection | null>(
  props.chords.length ? 'chords'
  : props.rhythms.length ? 'rhythms'
  : props.exercises.length ? 'exercises'
  : props.songs.length ? 'songs'
  : props.progressions.length ? 'progressions'
  : props.widgets.length ? 'widgets'
  : null
);
function toggleSidebar(key: SidebarSection) {
  openSection.value = openSection.value === key ? null : key;
}
</script>

<template>
    <Head>
        <title>{{ course.title }} | Soul Bossa Nova</title>
        <meta name="description" :content="course.excerpt || `Learn ${course.title} — a Bossa Nova guitar course with ${course.lessonCount} lessons on Soul Bossa Nova.`" />
        <meta property="og:title" :content="`${course.title} | Soul Bossa Nova`" />
        <meta property="og:description" :content="course.excerpt || `Bossa Nova guitar course: ${course.title}`" />
        <meta property="og:type" content="website" />
        <meta v-if="course.featuredImagePath" property="og:image" :content="course.featuredImagePath" />
    </Head>

  <div class="sbn-page-detail sbn-course-show" :style="heroStyle">

    <Breadcrumb
      :segments="[
        { label: 'Courses', href: '/learn' },
        ...(course.primaryGenre ? [{ label: genreLabel, href: `/learn?genre=${encodeURIComponent(course.primaryGenre)}` }] : []),
        ...(course.primaryLevel ? [{ label: levelLabel, href: `/learn?genre=${encodeURIComponent(course.primaryGenre ?? '')}&level=${encodeURIComponent(course.primaryLevel)}` }] : []),
        { label: course.title },
      ]"
      :color="genreColor"
    />

    <!-- ── Hero ──────────────────────────────────────────────────────────── -->
    <header class="sbn-cs-hero sbn-detail-hero">
      <img v-if="course.featuredImagePath" :src="course.featuredImagePath" :alt="course.title" class="sbn-cs-hero-bg" />
      <div v-else class="sbn-cs-hero-bg sbn-cs-hero-bg--fallback" />
      <div class="sbn-cs-hero-overlay" />

      <div class="sbn-cs-hero-text">
        <div class="sbn-cs-hero-badges">
          <span class="sbn-cat-badge sbn-cat-badge-filled" :style="{ '--cat-clr': genreColor }">
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
          <template v-if="rhythms.length">
            <div class="sbn-cs-stat-div" />
            <div class="sbn-cs-stat">
              <span class="sbn-cs-stat-num">{{ rhythms.length }}</span>
              <span class="sbn-cs-stat-lbl">rhythm{{ rhythms.length !== 1 ? 's' : '' }}</span>
            </div>
          </template>
          <template v-if="chords.length">
            <div class="sbn-cs-stat-div" />
            <div class="sbn-cs-stat">
              <span class="sbn-cs-stat-num">{{ chords.length }}</span>
              <span class="sbn-cs-stat-lbl">chords</span>
            </div>
          </template>
        </div>

        <div class="sbn-cs-cta-row">
          <Link :href="`/learn/${course.slug}/play`" class="sbn-btn sbn-btn-primary sbn-btn-lg">
            Start learning →
          </Link>
          <Link v-if="course.isGated && course.productSlug" :href="ctaHref" class="sbn-btn sbn-btn-secondary">
            Buy course
          </Link>
        </div>
      </div>
    </header>

    <!-- ── Two-column body ──────────────────────────────────────────────── -->
    <div class="sbn-cs-body" :class="{ 'has-sidebar': hasExplore }">

      <!-- Main column -->
      <div class="sbn-cs-main">

        <!-- What you'll learn -->
        <section v-if="learningOutcomes.length" class="sbn-cs-section">
          <h2 class="sbn-cs-section-title">What you'll learn</h2>
          <ul class="sbn-cs-topics-grid">
            <li v-for="outcome in learningOutcomes" :key="outcome" class="sbn-cs-topic-item">
              <svg class="sbn-cs-topic-check" width="16" height="16" viewBox="0 0 16 16" fill="none">
                <circle cx="8" cy="8" r="7.25" stroke="currentColor" stroke-width="1.5"/>
                <path d="M5 8l2 2 4-4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
              </svg>
              <span>{{ outcome }}</span>
            </li>
          </ul>
        </section>

        <!-- Skills you'll build -->
        <section v-if="skills.length" class="sbn-cs-section">
          <h2 class="sbn-cs-section-title">Skills you'll build</h2>
          <p class="sbn-cs-skills-hint">
            <template v-if="isAuthed">Tick a skill once you've got it — it syncs to <Link href="/account/skills">My Skills</Link>.</template>
            <template v-else>The atomic skills this course develops. <Link href="/register">Sign in</Link> to track your progress.</template>
          </p>
          <div class="sbn-cs-skills-grid">
            <button
              v-for="skill in skills"
              :key="skill.slug"
              type="button"
              class="sbn-cs-skill"
              :class="{ 'is-done': !!skillDone[skill.slug], 'is-pending': !!skillPending[skill.slug] }"
              :aria-pressed="!!skillDone[skill.slug]"
              :aria-label="(skillDone[skill.slug] ? 'Mark incomplete: ' : 'Mark complete: ') + skill.title"
              @click="toggleSkill(skill)"
            >
              <span class="sbn-cs-skill-icon">
                <SkillIcon :icon-path="skill.iconPath" :icon-key="skill.iconKey" :branch="skill.branch" :size="18" />
              </span>
              <span class="sbn-cs-skill-title">{{ skill.title }}</span>
              <span class="sbn-cs-skill-check" aria-hidden="true">
                <svg v-if="skillDone[skill.slug]" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                  <path d="M20 6 9 17l-5-5"/>
                </svg>
              </span>
            </button>
          </div>
        </section>

        <!-- About -->
        <section v-if="course.description" class="sbn-cs-section">
          <h2 class="sbn-cs-section-title">About this course</h2>
          <div class="sbn-cs-about-body sbn-prose" v-html="course.description" />
        </section>

        <!-- Course contents -->
        <section class="sbn-cs-section">
          <h2 class="sbn-cs-section-title">
            Course contents
            <span class="sbn-cs-section-count">{{ course.lessonCount }} lessons</span>
          </h2>
          <div class="sbn-cs-lesson-list">
            <div v-for="(group, gi) in grouped" :key="group.title" class="sbn-cs-lesson-group">
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
              <div v-if="isSectionOpen(group.title)" class="sbn-cs-lesson-rows">
                <Link
                  v-for="(lesson, li) in group.items"
                  :key="lesson.id"
                  :href="`/learn/${course.slug}/play/${lesson.slug}`"
                  class="sbn-cs-lesson-row"
                >
                  <span class="sbn-cs-lesson-num">{{ li + 1 }}</span>
                  <span class="sbn-cs-lesson-title">{{ lesson.title }}</span>
                  <span v-if="lesson.subsections.length" class="sbn-cs-lesson-subs">{{ lesson.subsections.length }} parts</span>
                  <span v-if="lesson.isPreview" class="sbn-cs-preview-pill">Preview</span>
                  <svg class="sbn-cs-lesson-arrow" width="12" height="12" viewBox="0 0 12 12">
                    <path d="M4 2l4 4-4 4" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                  </svg>
                </Link>
              </div>
            </div>
          </div>
        </section>

        <!-- Bottom CTA -->
        <div class="sbn-cs-bottom-cta">
          <Link :href="`/learn/${course.slug}/play`" class="sbn-btn sbn-btn-primary sbn-btn-lg">
            Start learning →
          </Link>
          <Link v-if="course.isGated && course.productSlug" :href="ctaHref" class="sbn-btn sbn-btn-secondary">
            Buy course
          </Link>
        </div>

      </div><!-- /main -->

      <!-- Explore sidebar -->
      <aside v-if="hasExplore" class="sbn-cs-sidebar">
        <div class="sbn-cs-explore-head">Preview content</div>

        <!-- Chords -->
        <div v-if="chords.length" class="sbn-cs-explore-block">
          <button type="button" class="sbn-cs-explore-toggle" :class="{ 'is-open': openSection === 'chords' }" @click="toggleSidebar('chords')">
            <span class="sbn-cs-explore-toggle-label">
              <svg width="14" height="14" viewBox="0 0 14 14" fill="none">
                <rect x="1.5" y="1.5" width="4" height="11" rx="1" stroke="currentColor" stroke-width="1.4"/>
                <rect x="8.5" y="1.5" width="4" height="11" rx="1" stroke="currentColor" stroke-width="1.4"/>
              </svg>
              Chords
            </span>
            <span class="sbn-cs-explore-count">{{ chords.length }}</span>
          </button>
          <div v-if="openSection === 'chords'" class="sbn-cs-explore-body">
            <div class="sbn-cs-chord-grid">
              <Link
                v-for="chord in chords"
                :key="chord.slug"
                :href="lessonUrl(chord.lessonSlug)"
                class="sbn-cs-chord-cell"
              >
                <div v-if="chordDiagramData[chord.slug]" class="sbn-cs-chord-diagram">
                  <ChordDiagram :chord="chordDiagramData[chord.slug]" />
                </div>
                <div v-else class="sbn-cs-chord-diagram-placeholder" />
                <span class="sbn-cs-chord-name" v-html="formatChordNameHtml(chordDiagramData[chord.slug] ?? { root_note: chord.rootNote }, true)" />
              </Link>
            </div>
          </div>
        </div>

        <!-- Rhythms -->
        <div v-if="rhythms.length" class="sbn-cs-explore-block">
          <button type="button" class="sbn-cs-explore-toggle" :class="{ 'is-open': openSection === 'rhythms' }" @click="toggleSidebar('rhythms')">
            <span class="sbn-cs-explore-toggle-label">
              <svg width="14" height="14" viewBox="0 0 14 14" fill="none">
                <circle cx="3" cy="7" r="1.5" fill="currentColor"/>
                <circle cx="7" cy="4" r="1.5" fill="currentColor"/>
                <circle cx="11" cy="7" r="1.5" fill="currentColor"/>
                <circle cx="7" cy="10" r="1.5" fill="currentColor"/>
              </svg>
              Rhythms
            </span>
            <span class="sbn-cs-explore-count">{{ rhythms.length }}</span>
          </button>
          <div v-if="openSection === 'rhythms'" class="sbn-cs-explore-body">
            <div class="sbn-cs-rhythm-list">
              <Link
                v-for="rhythm in rhythms"
                :key="rhythm.slug"
                :href="lessonUrl(rhythm.lessonSlug)"
                class="sbn-cs-rhythm-row"
                :style="{ '--rhythm-clr': getCategoryColor(rhythm.styleSlug) }"
              >
                <div class="sbn-cs-rhythm-row-head">
                  <span class="sbn-cs-rhythm-name">{{ rhythm.name }}</span>
                  <span class="sbn-cs-rhythm-meta">{{ rhythm.timeSignature }} · {{ rhythm.bpm }} bpm</span>
                </div>
                <RhythmStrip :pattern="rhythm.playerData" :color="getCategoryColor(rhythm.styleSlug)" />
              </Link>
            </div>
          </div>
        </div>

        <!-- Exercises -->
        <div v-if="exercises.length" class="sbn-cs-explore-block">
          <button type="button" class="sbn-cs-explore-toggle" :class="{ 'is-open': openSection === 'exercises' }" @click="toggleSidebar('exercises')">
            <span class="sbn-cs-explore-toggle-label">
              <svg width="14" height="14" viewBox="0 0 14 14" fill="none">
                <rect x="1.5" y="2.5" width="11" height="9" rx="1.5" stroke="currentColor" stroke-width="1.4"/>
                <path d="M4 6h6M4 8.5h4" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/>
              </svg>
              Exercises
            </span>
            <span class="sbn-cs-explore-count">{{ exercises.length }}</span>
          </button>
          <div v-if="openSection === 'exercises'" class="sbn-cs-explore-body">
            <div class="sbn-cs-exercise-list">
              <Link
                v-for="ex in exercises"
                :key="ex.slug"
                :href="lessonUrl(ex.lessonSlug)"
                class="sbn-cs-exercise-row"
              >
                <span class="sbn-cs-exercise-title">{{ ex.title }}</span>
                <span v-if="ex.keyCenter" class="sbn-cs-exercise-key">{{ ex.keyCenter }}</span>
              </Link>
            </div>
          </div>
        </div>

        <!-- Songs -->
        <div v-if="songs.length" class="sbn-cs-explore-block">
          <button type="button" class="sbn-cs-explore-toggle" :class="{ 'is-open': openSection === 'songs' }" @click="toggleSidebar('songs')">
            <span class="sbn-cs-explore-toggle-label">
              <svg width="14" height="14" viewBox="0 0 14 14" fill="none">
                <path d="M9 2v7.5A2 2 0 1 1 7 7.5V4L4 5V2l5-1z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/>
              </svg>
              Songs
            </span>
            <span class="sbn-cs-explore-count">{{ songs.length }}</span>
          </button>
          <div v-if="openSection === 'songs'" class="sbn-cs-explore-body">
            <div class="sbn-cs-exercise-list">
              <Link
                v-for="song in songs"
                :key="song.slug"
                :href="lessonUrl(song.lessonSlug)"
                class="sbn-cs-exercise-row"
              >
                <span class="sbn-cs-exercise-title">{{ song.title }}</span>
                <span v-if="song.composer" class="sbn-cs-exercise-key">{{ song.composer }}</span>
              </Link>
            </div>
          </div>
        </div>

        <!-- Progressions -->
        <div v-if="progressions.length" class="sbn-cs-explore-block">
          <button type="button" class="sbn-cs-explore-toggle" :class="{ 'is-open': openSection === 'progressions' }" @click="toggleSidebar('progressions')">
            <span class="sbn-cs-explore-toggle-label">
              <svg width="14" height="14" viewBox="0 0 14 14" fill="none">
                <rect x="1.5" y="3.5" width="3" height="7" rx="1" stroke="currentColor" stroke-width="1.4"/>
                <rect x="5.5" y="1.5" width="3" height="9" rx="1" stroke="currentColor" stroke-width="1.4"/>
                <rect x="9.5" y="5.5" width="3" height="5" rx="1" stroke="currentColor" stroke-width="1.4"/>
              </svg>
              Progressions
            </span>
            <span class="sbn-cs-explore-count">{{ progressions.length }}</span>
          </button>
          <div v-if="openSection === 'progressions'" class="sbn-cs-explore-body">
            <div class="sbn-cs-exercise-list">
              <Link
                v-for="prog in progressions"
                :key="prog.slug"
                :href="lessonUrl(prog.lessonSlug)"
                class="sbn-cs-exercise-row"
              >
                <span class="sbn-cs-exercise-title">{{ prog.name }}</span>
                <span class="sbn-cs-exercise-key">{{ prog.category }}</span>
              </Link>
            </div>
          </div>
        </div>

        <!-- Theory widgets -->
        <div v-if="widgets.length" class="sbn-cs-explore-block">
          <button type="button" class="sbn-cs-explore-toggle" :class="{ 'is-open': openSection === 'widgets' }" @click="toggleSidebar('widgets')">
            <span class="sbn-cs-explore-toggle-label">
              <svg width="14" height="14" viewBox="0 0 14 14" fill="none">
                <circle cx="7" cy="7" r="5.25" stroke="currentColor" stroke-width="1.4"/>
                <path d="M7 4.5v3l2 1.5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/>
              </svg>
              Theory
            </span>
            <span class="sbn-cs-explore-count">{{ widgets.length }}</span>
          </button>
          <div v-if="openSection === 'widgets'" class="sbn-cs-explore-body">
            <div class="sbn-cs-exercise-list">
              <Link
                v-for="widget in widgets"
                :key="widget.slug"
                :href="lessonUrl(widget.lessonSlug)"
                class="sbn-cs-exercise-row"
              >
                <span class="sbn-cs-exercise-title">{{ widget.title }}</span>
              </Link>
            </div>
          </div>
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
  /* Tint helpers derived from the style colour — used throughout the page */
  --cat-bg:     color-mix(in srgb, var(--category-color) 8%,  var(--clr-white));
  --cat-border: color-mix(in srgb, var(--category-color) 30%, var(--clr-border));
  --cat-text:   color-mix(in srgb, var(--category-color) 70%, #000);
  --cat-glow:   color-mix(in srgb, var(--category-color) 20%, transparent);
}

/* Override primary button gradient to match the course style colour */
.sbn-course-show :deep(.sbn-btn-primary) {
  background: var(--category-gradient);
}
.sbn-course-show :deep(.sbn-btn-primary:hover) {
  background: linear-gradient(135deg,
    color-mix(in srgb, var(--category-color) 85%, #000) 0%,
    color-mix(in srgb, var(--category-color) 50%, #000) 100%);
}

/* ── Hero ──────────────────────────────────────────────────────────────────── */
.sbn-cs-hero {
  position: relative;
  overflow: hidden;
  margin-bottom: 28px;
  min-height: 280px;
  display: flex;
  align-items: center;
}

.sbn-cs-hero-bg {
  position: absolute;
  top: 0; bottom: 0;
  left: 20%; right: -80px;
  width: calc(80% + 80px);
  height: 100%;
  object-fit: cover;
  object-position: center center;
  z-index: 0;
}
.sbn-cs-hero-bg--fallback { background: var(--category-gradient); }

.sbn-cs-hero-overlay {
  position: absolute;
  inset: 0;
  background: linear-gradient(
    to right,
    var(--clr-white) 30%,
    color-mix(in srgb, var(--clr-white) 75%, transparent) 65%,
    color-mix(in srgb, var(--clr-white) 20%, transparent) 100%
  );
  z-index: 1;
}

.sbn-cs-hero-text {
  position: relative;
  z-index: 2;
  padding: 36px 40px;
  max-width: 640px;
}

.sbn-cs-hero-badges { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 14px; }
.sbn-cs-stars { display: inline-flex; gap: 1px; }
.star-on  { color: var(--category-color); }
.star-off { color: var(--clr-border); }

.sbn-cs-title {
  font-size: 2em; font-weight: 800;
  letter-spacing: -0.025em; line-height: 1.15;
  color: var(--clr-text); margin: 0 0 10px;
}
.sbn-cs-excerpt {
  font-size: 1em; line-height: 1.65;
  color: var(--clr-text-muted);
  margin: 0 0 18px; max-width: 540px;
}

.sbn-cs-stats { display: flex; align-items: center; gap: 14px; margin-bottom: 20px; }
.sbn-cs-stat { display: flex; flex-direction: column; gap: 1px; }
.sbn-cs-stat-num {
  font-family: var(--font-chord, Georgia, serif);
  font-size: 22px; font-weight: 600;
  color: var(--clr-text); line-height: 1;
}
.sbn-cs-stat-lbl {
  font-size: 11px; color: var(--clr-text-muted);
  text-transform: uppercase; letter-spacing: 0.04em;
}
.sbn-cs-stat-div { width: 1px; height: 28px; background: var(--clr-border); }

.sbn-cs-cta-row { display: flex; gap: 10px; flex-wrap: wrap; }

/* ── Body ──────────────────────────────────────────────────────────────────── */
.sbn-cs-body {
  display: grid;
  grid-template-columns: 1fr;
  gap: 28px;
  align-items: start;
}
.sbn-cs-body.has-sidebar {
  grid-template-columns: minmax(0, 1fr) 296px;
}

/* ── Sections ──────────────────────────────────────────────────────────────── */
.sbn-cs-section { margin-bottom: 36px; }

.sbn-cs-section-title {
  font-size: 1em; font-weight: 700;
  color: var(--clr-text);
  margin: 0 0 16px;
  padding-bottom: 10px;
  border-bottom: 2px solid var(--cat-border);
  display: flex; align-items: center; gap: 10px;
}
.sbn-cs-section-count {
  font-size: 12px; font-weight: 600;
  color: var(--clr-text-muted);
  background: var(--clr-surface-3);
  border-radius: 999px; padding: 2px 8px;
}

/* ── What you'll learn ─────────────────────────────────────────────────────── */
.sbn-cs-topics-grid {
  list-style: none; padding: 0; margin: 0;
  display: grid; grid-template-columns: repeat(2, 1fr);
  gap: 10px 24px;
}
.sbn-cs-topic-item {
  display: flex; align-items: flex-start;
  gap: 10px; font-size: 14px;
  color: var(--clr-text-dim); line-height: 1.4;
}
.sbn-cs-topic-check { color: var(--cat-text); flex-shrink: 0; margin-top: 1px; }

/* ── Skills you'll build ───────────────────────────────────────────────────── */
.sbn-cs-skills-hint {
  font-size: 13px; color: var(--clr-text-muted);
  margin: -8px 0 14px; line-height: 1.5;
}
.sbn-cs-skills-hint a { color: var(--cat-text); text-decoration: underline; }
.sbn-cs-skills-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
  gap: 8px;
}
.sbn-cs-skill {
  display: flex; align-items: center; gap: 10px;
  padding: 9px 12px;
  border: 1px solid var(--clr-border);
  border-radius: var(--radius);
  background: var(--clr-white);
  cursor: pointer; text-align: left;
  color: var(--clr-text);
  transition: border-color 0.15s, background 0.15s, opacity 0.1s;
}
.sbn-cs-skill:hover { border-color: var(--cat-border); }
.sbn-cs-skill.is-done {
  background: color-mix(in srgb, var(--cat-text) 8%, transparent);
  border-color: color-mix(in srgb, var(--cat-text) 40%, transparent);
}
.sbn-cs-skill.is-pending { opacity: 0.6; pointer-events: none; }
.sbn-cs-skill-icon { flex-shrink: 0; color: var(--clr-text-muted); display: flex; }
.sbn-cs-skill.is-done .sbn-cs-skill-icon { color: var(--cat-text); }
.sbn-cs-skill-title { flex: 1; font-size: 13px; line-height: 1.3; }
.sbn-cs-skill-check {
  flex-shrink: 0; width: 15px; height: 15px;
  display: flex; align-items: center; justify-content: center;
  color: var(--cat-text);
}

/* ── About ─────────────────────────────────────────────────────────────────── */
.sbn-cs-about-body { font-size: 14px; line-height: 1.7; color: var(--clr-text-dim); }
.sbn-cs-about-body :deep(p) { margin: 0 0 12px; }
.sbn-cs-about-body :deep(p:last-child) { margin-bottom: 0; }
.sbn-cs-about-body :deep(ul), .sbn-cs-about-body :deep(ol) { margin: 0 0 12px; padding-left: 22px; }
.sbn-cs-about-body :deep(li) { margin-bottom: 6px; }
.sbn-cs-about-body :deep(strong) { color: var(--clr-text); font-weight: 600; }

/* ── Lesson accordion ──────────────────────────────────────────────────────── */
.sbn-cs-lesson-list { display: flex; flex-direction: column; gap: 4px; }

.sbn-cs-lesson-group {
  border: 1px solid var(--clr-border);
  border-radius: var(--radius); overflow: hidden;
}

.sbn-cs-section-header {
  display: grid;
  grid-template-columns: 26px 1fr auto 16px;
  gap: 10px; align-items: center;
  width: 100%; padding: 12px 14px;
  background: var(--clr-surface-2);
  border: 0; cursor: pointer; text-align: left;
  transition: background 0.12s;
}
.sbn-cs-section-header:hover { background: var(--clr-surface-3); }
.sbn-cs-section-header.is-open { background: var(--clr-white); }

.sbn-cs-section-num {
  display: grid; place-items: center;
  width: 26px; height: 26px;
  border-radius: 999px;
  background: var(--clr-white);
  border: 1.5px solid var(--clr-border);
  color: var(--clr-text-muted);
  font-size: 11px; font-weight: 700;
  flex-shrink: 0; transition: all 0.2s;
}
.sbn-cs-section-header.is-open .sbn-cs-section-num {
  background: var(--category-color); color: white;
  border-color: transparent;
  box-shadow: 0 0 0 3px var(--cat-glow);
}
.sbn-cs-section-name { font-size: 13.5px; font-weight: 700; color: var(--clr-text); line-height: 1.3; }
.sbn-cs-section-meta { font-size: 12px; color: var(--clr-text-muted); white-space: nowrap; }
.sbn-cs-section-chevron { color: var(--clr-text-muted); transition: transform 0.2s; flex-shrink: 0; }
.sbn-cs-section-header.is-open .sbn-cs-section-chevron { transform: rotate(180deg); }

.sbn-cs-lesson-rows { display: flex; flex-direction: column; }

.sbn-cs-lesson-row {
  display: grid;
  grid-template-columns: 26px 1fr auto auto 14px;
  gap: 10px; align-items: center;
  padding: 10px 14px;
  text-decoration: none; color: var(--clr-text-dim);
  border-top: 1px solid var(--clr-border);
  font-size: 13.5px; transition: background 0.1s;
}
.sbn-cs-lesson-row:hover { background: var(--cat-bg); color: var(--clr-text); }
.sbn-cs-lesson-row:hover .sbn-cs-lesson-arrow { color: var(--cat-text); }

.sbn-cs-lesson-num {
  display: grid; place-items: center;
  width: 22px; height: 22px;
  border-radius: 999px;
  background: var(--clr-white);
  border: 1.5px solid var(--clr-border-dim);
  font-size: 10px; font-weight: 700;
  color: var(--clr-text-muted); flex-shrink: 0; transition: all 0.15s;
}
.sbn-cs-lesson-row:hover .sbn-cs-lesson-num { border-color: var(--cat-border); color: var(--cat-text); }
.sbn-cs-lesson-title { font-weight: 500; line-height: 1.35; }
.sbn-cs-lesson-subs { font-size: 11px; color: var(--clr-text-muted); white-space: nowrap; }
.sbn-cs-preview-pill {
  font-size: 10px; font-weight: 700; letter-spacing: 0.04em;
  text-transform: uppercase;
  color: var(--cat-text); background: var(--cat-bg);
  border: 1px solid var(--cat-border);
  padding: 2px 7px; border-radius: 999px; white-space: nowrap;
}
.sbn-cs-lesson-arrow { color: var(--clr-border); transition: color 0.1s; }

/* ── Bottom CTA ────────────────────────────────────────────────────────────── */
.sbn-cs-bottom-cta {
  display: flex; gap: 10px; flex-wrap: wrap;
  padding: 28px 0 8px;
  border-top: 2px solid var(--cat-border);
}

/* ── Explore sidebar ───────────────────────────────────────────────────────── */
.sbn-cs-sidebar {
  position: sticky;
  top: calc(var(--site-header-h, 96px) + 16px);
  background: var(--clr-white);
  border: 1px solid var(--cat-border);
  border-top: 3px solid var(--category-color);
  border-radius: var(--radius);
  overflow: hidden;
}

.sbn-cs-explore-head {
  padding: 12px 14px 10px;
  font-size: 10.5px; font-weight: 700;
  text-transform: uppercase; letter-spacing: 0.08em;
  color: var(--clr-text-muted);
  border-bottom: 1px solid var(--clr-border);
}

.sbn-cs-explore-block {
  border-bottom: 1px solid var(--clr-border);
}
.sbn-cs-explore-block:last-child { border-bottom: 0; }

.sbn-cs-explore-toggle {
  display: flex; align-items: center; justify-content: space-between;
  width: 100%; padding: 11px 14px;
  background: transparent; border: 0; cursor: pointer;
  transition: background 0.12s;
}
.sbn-cs-explore-toggle:hover { background: var(--clr-surface-2); }
.sbn-cs-explore-toggle.is-open { background: var(--cat-bg); }

.sbn-cs-explore-toggle-label {
  display: flex; align-items: center; gap: 8px;
  font-size: 13px; font-weight: 600;
  color: var(--clr-text-dim);
}
.sbn-cs-explore-toggle.is-open .sbn-cs-explore-toggle-label { color: var(--cat-text); }

.sbn-cs-explore-count {
  font-size: 11px; font-weight: 600;
  color: var(--clr-text-muted);
  background: var(--clr-surface-3);
  border-radius: 999px; padding: 1px 7px;
}
.sbn-cs-explore-toggle.is-open .sbn-cs-explore-count {
  background: var(--cat-border);
  color: var(--cat-text);
}

.sbn-cs-explore-body {
  border-top: 1px solid var(--clr-border);
  padding: 12px 14px;
  display: flex; flex-direction: column; gap: 10px;
}

.sbn-cs-explore-more {
  font-size: 12px; color: var(--clr-text-muted);
  text-decoration: none; text-align: right;
  padding-top: 4px;
}
.sbn-cs-explore-more:hover { color: var(--cat-text); }

/* Chord grid — mirrors PracticePanel vC-chord-list */
.sbn-cs-chord-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(72px, 1fr));
  gap: 6px;
}
.sbn-cs-chord-cell {
  display: flex; flex-direction: column;
  align-items: center; gap: 4px;
  padding: 6px 4px;
  border: 1px solid var(--clr-border);
  border-radius: var(--radius-sm);
  text-decoration: none;
  background: var(--clr-white);
  transition: background 0.12s, border-color 0.12s;
}
.sbn-cs-chord-cell:hover {
  background: var(--cat-bg);
  border-color: var(--cat-border);
}
.sbn-cs-chord-diagram { width: 64px; }
.sbn-cs-chord-diagram-placeholder {
  width: 64px; height: 72px;
  background: var(--clr-surface-2);
  border-radius: var(--radius-sm);
  animation: cs-pulse 1.5s ease-in-out infinite;
}
@keyframes cs-pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.4; } }
.sbn-cs-chord-name {
  font-family: var(--font-chord, Georgia, serif);
  font-size: 13px; line-height: 1.1;
  text-align: center; color: var(--clr-text-dim);
}
.sbn-cs-chord-name :deep(.sbn-chord-root) { font-weight: 700; }
.sbn-cs-chord-name :deep(.sbn-chord-quality) { font-size: 0.78em; }
.sbn-cs-chord-name :deep(.sbn-chord-ext) { font-size: 0.7em; vertical-align: super; line-height: 0; }

/* Rhythm list */
.sbn-cs-rhythm-list { display: flex; flex-direction: column; gap: 8px; }
.sbn-cs-rhythm-row {
  display: flex; flex-direction: column; gap: 6px;
  padding: 8px 10px;
  border: 1px solid var(--clr-border);
  border-left: 3px solid var(--rhythm-clr, var(--clr-accent));
  border-radius: var(--radius-sm);
  text-decoration: none;
  background: var(--clr-white);
  transition: background 0.12s;
}
.sbn-cs-rhythm-row:hover { background: var(--clr-surface-2); }
.sbn-cs-rhythm-row-head { display: flex; align-items: baseline; justify-content: space-between; gap: 8px; }
.sbn-cs-rhythm-name { font-size: 12px; font-weight: 600; color: var(--clr-text); }
.sbn-cs-rhythm-meta { font-size: 10.5px; color: var(--clr-text-muted); white-space: nowrap; }

/* Exercise list */
.sbn-cs-exercise-list { display: flex; flex-direction: column; gap: 4px; }
.sbn-cs-exercise-row {
  display: flex; align-items: center; justify-content: space-between;
  gap: 8px; padding: 8px 10px;
  border: 1px solid var(--clr-border);
  border-radius: var(--radius-sm);
  text-decoration: none; color: var(--clr-text);
  transition: background 0.12s, border-color 0.12s;
}
.sbn-cs-exercise-row:hover { background: var(--cat-bg); border-color: var(--cat-border); }
.sbn-cs-exercise-title { font-size: 12.5px; font-weight: 500; color: var(--clr-text-dim); min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.sbn-cs-exercise-row:hover .sbn-cs-exercise-title { color: var(--clr-text); }
.sbn-cs-exercise-key {
  font-size: 11px; font-weight: 600;
  background: var(--clr-surface-3); color: var(--clr-text-muted);
  border-radius: 999px; padding: 1px 7px; white-space: nowrap; flex-shrink: 0;
}

/* ── Responsive ────────────────────────────────────────────────────────────── */
@media (max-width: 960px) {
  .sbn-cs-body.has-sidebar { grid-template-columns: 1fr; }
  .sbn-cs-sidebar { position: static; }
  .sbn-cs-topics-grid { grid-template-columns: 1fr; }
}

@media (max-width: 900px) {
  .sbn-cs-hero { min-height: 260px; }
  .sbn-cs-hero-bg { left: 0; width: 100%; opacity: 0.35; }
  .sbn-cs-hero-overlay {
    background: linear-gradient(
      to bottom,
      color-mix(in srgb, var(--clr-white) 55%, transparent) 0%,
      var(--clr-white) 80%
    );
  }
  .sbn-cs-hero-text { padding: 28px 20px 24px; max-width: 100%; }
  .sbn-cs-title { font-size: 1.6em; }
}

@media (max-width: 600px) {
  .sbn-cs-hero { min-height: 200px; }
  .sbn-cs-lesson-row { grid-template-columns: 22px 1fr 14px; }
  .sbn-cs-lesson-subs, .sbn-cs-preview-pill { display: none; }
}
</style>
