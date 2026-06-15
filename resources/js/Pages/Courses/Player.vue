<script setup lang="ts">
import { ref, computed, watch, onBeforeUnmount } from 'vue';
import { Link } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import LessonSidebar from '@/Components/Course/LessonSidebar.vue';
import LessonContent from '@/Components/Course/LessonContent.vue';
import PracticePanel from '@/Components/Course/PracticePanel.vue';
import { getAudioEngine } from '@/audio/engine/AudioEngine.js';
import { getCategoryColor } from '@/composables/useCategoryColors';

defineOptions({ layout: PublicLayout });

interface Subsection { title: string; slug: string }
interface CourseData {
  slug: string;
  title: string;
  primaryGenre: string | null;
  primaryLevel: string | null;
  lessonCount: number;
  productSlug: string | null;
}
interface LessonStub {
  id: number;
  slug: string;
  title: string;
  sectionTitle: string | null;
  isPreview: boolean;
  sortOrder: number;
  subsections: Subsection[];
}
interface LessonData extends LessonStub { content: string | null }
interface SelectedChord { slug: string; root: string; voicingData?: any }
interface RhythmOption { slug: string; name: string; description: string | null; pattern: any }
interface VideoSnippet { id: string; label?: string; videoId: string; videoType?: 'youtube' | 'hosted'; startSec: number; endSec?: number; tempoBpm: number; key?: string; chords?: string[] }
interface ProgressionOption {
  slug: string;
  name: string;
  key: string;
  category: string;
  videoSnippet: VideoSnippet | null;
}
interface SheetVideo { slug: string; title: string; videoId: string; videoType: 'youtube' | 'hosted' }

const props = defineProps<{
  course: CourseData;
  lessons: LessonStub[];
  lesson: LessonData | null;
  hasAccess: boolean;
  chordSlugs?: string[];
  chordTags?: { slug: string; root: string }[];
  lessonConcepts?: { slug: string; title: string; body_html: string; has_widgets: boolean }[];
  rhythms?: RhythmOption[];
  progressions?: ProgressionOption[];
  sheets?: Record<string, SheetVideo>;
}>();

// snippet id → sync anchor, for the inline <sbn-progression> in the lesson
// body. mountSbnNodes hands these to the body component so its highlight can
// follow the same playhead PracticePanel's <VideoEmbed> drives.
const snippetSync = computed<Record<string, { startSec: number; tempoBpm: number; key?: string; chords?: string[] }>>(() => {
  const map: Record<string, { startSec: number; tempoBpm: number; key?: string; chords?: string[] }> = {};
  for (const p of props.progressions ?? []) {
    const s = p.videoSnippet;
    if (s) map[s.id] = { startSec: s.startSec, tempoBpm: s.tempoBpm, key: s.key, chords: s.chords };
  }
  return map;
});

const contentRef = ref<InstanceType<typeof LessonContent> | null>(null);
const practicePanelRef = ref<InstanceType<typeof PracticePanel> | null>(null);
const activeSubsection = ref<string | null>(null);
const navCollapsed = ref(localStorage.getItem('sbn_nav_collapsed') === 'true');
const practiceCollapsed = ref(localStorage.getItem('sbn_practice_collapsed') === 'true');
const mobileSidebarOpen = ref(false);
const activeSoundSource = ref<'sheet' | null>(null);
const selectedChord = ref<SelectedChord | null>(null);
const engine = getAudioEngine();
const unsubPlayStarted = engine.on('playStarted', (sourceTag: string | null) => {
  activeSoundSource.value = sourceTag === 'sheet' ? 'sheet' : null;
});
const unsubEnded = engine.on('ended', () => {
  activeSoundSource.value = null;
});

watch(navCollapsed, (val) => {
  localStorage.setItem('sbn_nav_collapsed', val ? 'true' : 'false');
});
watch(practiceCollapsed, (val) => {
  localStorage.setItem('sbn_practice_collapsed', val ? 'true' : 'false');
});

const flatIndex = computed(() => props.lessons.findIndex(l => l.slug === props.lesson?.slug));
const prevLesson = computed(() => flatIndex.value > 0 ? props.lessons[flatIndex.value - 1] : null);
const nextLesson = computed(() => flatIndex.value < props.lessons.length - 1 ? props.lessons[flatIndex.value + 1] : null);

function jumpSubsection(slug: string): void {
  contentRef.value?.goSubsection(slug);
}
function onChordSelect(slug: string, root: string, voicingData?: any): void {
  selectedChord.value = { slug, root, voicingData };
  practiceCollapsed.value = false;
}
function clearChord(): void {
  selectedChord.value = null;
}
function onExpandVideo(): void {
  practiceCollapsed.value = false;
  practicePanelRef.value?.expandVideo();
}

onBeforeUnmount(() => {
  unsubPlayStarted?.();
  unsubEnded?.();
});

watch(() => props.lesson?.slug, () => {
  selectedChord.value = null;
});
</script>

<template>
  <div class="sbn-player-container">
    <div
      class="vC-grid"
      :class="{
        'is-collapsed': navCollapsed,
        'is-practice-collapsed': practiceCollapsed,
        'mobile-sidebar-open': mobileSidebarOpen,
      }"
      :style="{ '--genre-color': getCategoryColor(course.primaryGenre ?? undefined) }"
    >
      <!-- Mobile overlay toggle -->
      <button
        type="button"
        class="vC-mobile-overlay"
        v-if="mobileSidebarOpen"
        @click="mobileSidebarOpen = false"
      />

      <LessonSidebar
        :course="course"
        :lessons="lessons"
        :active-lesson-slug="lesson?.slug ?? null"
        :active-subsection="activeSubsection"
        :has-access="hasAccess"
        :nav-collapsed="navCollapsed"
        @collapse="navCollapsed = true"
        @expand="navCollapsed = false"
        @jump-subsection="jumpSubsection"
        @close-mobile="mobileSidebarOpen = false"
      />

      <main class="vC-main">
        <LessonContent
          ref="contentRef"
          :lesson="lesson"
          :has-access="hasAccess"
          :product-slug="course.productSlug"
          :prev-lesson="prevLesson ? { slug: prevLesson.slug, title: prevLesson.title } : null"
          :next-lesson="nextLesson ? { slug: nextLesson.slug, title: nextLesson.title } : null"
          :course-slug="course.slug"
          :on-chord-select="onChordSelect"
          :snippet-sync="snippetSync"
          :on-expand-practice="() => practiceCollapsed = false"
          :on-expand-video="onExpandVideo"
          @subsection-change="activeSubsection = $event"
          @open-sidebar="mobileSidebarOpen = true"
        />
      </main>

      <PracticePanel
        ref="practicePanelRef"
        :lesson="lesson"
        :course="course"
        :selected-chord="selectedChord"
        :chord-slugs="props.chordSlugs ?? []"
        :chord-tags="props.chordTags ?? []"
        :active-sound-source="activeSoundSource"
        :lesson-concepts="props.lessonConcepts ?? []"
        :rhythms="props.rhythms ?? []"
        :progressions="props.progressions ?? []"
        :sheets="props.sheets ?? {}"
        :collapsed="practiceCollapsed"
        @select-chord="onChordSelect"
        @clear-chord="clearChord"
        @collapse="practiceCollapsed = true"
        @expand="practiceCollapsed = false"
      />
    </div>
  </div>
</template>
