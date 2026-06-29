<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Link, Head } from '@inertiajs/vue3';
import Breadcrumb from '@/Components/Breadcrumb.vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import RhythmPattern from '@/Components/Library/RhythmPattern.vue';
import RhythmPatternExpanded from '@/Components/Library/RhythmPatternExpanded.vue';
import RhythmLink from '@/Components/Library/RhythmLink.vue';
import type { RhythmLinkData } from '@/Components/Library/RhythmLink.vue';
import MediaShelf from '@/Components/Library/MediaShelf.vue';
import SongShelfCard from '@/Components/Library/SongShelfCard.vue';
import CourseShelfCard from '@/Components/Course/CourseShelfCard.vue';
import type { CourseShelfCardData } from '@/Components/Course/CourseShelfCard.vue';
import type { RhythmPatternWithMeta } from '@/Components/Library/RhythmPattern.vue';
import type { SongLinkData } from '@/Components/Library/SongLink.vue';
import { getCategoryStyle, getCategoryColor } from '@/composables/useCategoryColors';
import { difficultyBreadcrumbSegment } from '@/composables/useBreadcrumb';
import { getAudioEngine } from '../../../audio/engine/AudioEngine.js';
import SkillsBuiltPanel from '@/Components/Skill/SkillsBuiltPanel.vue';
import type { SkillRef } from '@/Components/Skill/SkillsBuiltPanel.vue';

defineOptions({ layout: PublicLayout });

interface Props {
  pattern: RhythmPatternWithMeta;
  siblings: RhythmPatternWithMeta[];
  songs: SongLinkData[];
  courses: CourseShelfCardData[];
  skills: SkillRef[];
}

const props = defineProps<Props>();

const CATEGORY_LABELS: Record<string, string> = {
  'bossa-nova': 'Bossa Nova',
  'jazz':       'Jazz',
  'classical':  'Classical',
  'pop':        'Pop',
};

const categoryStyle = computed(() => getCategoryStyle(props.pattern.styleSlug));
const categoryColor = computed(() => getCategoryColor(props.pattern.styleSlug));
const categoryLabel = computed(() =>
  CATEGORY_LABELS[props.pattern.category]
  ?? props.pattern.category.replace(/-/g, ' ').replace(/\b\w/g, (c: string) => c.toUpperCase()),
);

// Blend slider: 0 = pure samples, 1 = pure demo MP3.
// Only shown when the pattern has a demo URL.
const blend = ref(0);
const hasDemo = computed(() => !!props.pattern.demoUrl);
const engine = getAudioEngine();

watch(blend, (v) => {
  if (engine.isInited) engine.setBlend(v);
});

// Reset blend when navigating between patterns.
watch(() => props.pattern.slug, () => {
  blend.value = 0;
  if (engine.isInited) engine.setBlend(0);
});

const breadcrumbSegments = computed(() => {
  const segs = [{ label: 'Rhythms', href: '/library/rhythms' }];
  const filterParams: Record<string, string> = {};

  if (props.pattern.category) {
    filterParams.category = props.pattern.category;
    segs.push({
      label: categoryLabel.value,
      href: `/library/rhythms?category=${encodeURIComponent(props.pattern.category)}`,
    });
  }

  const difficultySeg = difficultyBreadcrumbSegment(props.pattern.difficulty, '/library/rhythms', filterParams);
  if (difficultySeg) segs.push(difficultySeg);

  segs.push({ label: props.pattern.name });
  return segs;
});
</script>

<template>
    <Head>
        <title>{{ pattern.name }} Rhythm Pattern | Soul Bossa Nova</title>
        <meta name="description" :content="pattern.descriptionExcerpt || `Learn the ${pattern.name} guitar rhythm pattern — interactive notation, audio playback and fingering guide for Bossa Nova and Latin Jazz.`" />
        <meta property="og:title" :content="`${pattern.name} | Soul Bossa Nova`" />
        <meta property="og:description" :content="pattern.descriptionExcerpt || `${pattern.name} — Bossa Nova rhythm pattern with interactive notation and audio.`" />
        <meta property="og:type" content="website" />
    </Head>

  <div class="sbn-page-detail sbn-rhythm-show sbn-has-category-gradient" :style="categoryStyle">

    <Breadcrumb :segments="breadcrumbSegments" :color="categoryColor" />

    <header class="sbn-rhythm-show-header sbn-detail-hero">
      <div class="sbn-show-hero-badges">
        <span class="sbn-cat-badge sbn-cat-badge-filled" :style="{ '--cat-clr': categoryColor }">{{ categoryLabel }}</span>
        <span v-for="tag in (pattern.tags ?? [])" :key="tag" class="sbn-hashtag">#{{ tag }}</span>
      </div>
      <h1 class="sbn-show-hero-title">{{ pattern.name }}</h1>
      <div class="sbn-show-hero-meta">
        <span class="sbn-meta-chip"><strong>Time</strong> {{ pattern.timeSignature }}</span>
        <span class="sbn-meta-chip"><strong>Tempo</strong> {{ pattern.bpm }} bpm</span>
        <span v-if="pattern.gridType !== 'sixteenth'" class="sbn-meta-chip"><strong>Grid</strong> {{ pattern.gridType }}</span>
      </div>
    </header>
    <div class="sbn-show-body">

      <!-- Left: main content -->
      <div class="sbn-show-main">

        <div class="sbn-rhythm-pattern-section sbn-card">
          <!-- Picking mode: expanded 4-row p/i/m/a grid -->
          <RhythmPatternExpanded
            v-if="pattern.pickingMode"
            :pattern="pattern"
            :playable="true"
            :demo-url="pattern.demoUrl"
            :color="categoryColor"
          >
            <template v-if="pattern.demoUrl" #transport-extra>
              <div class="sbn-blend-control">
                <span class="sbn-blend-label" :class="{ 'is-active': blend < 0.5 }">Samples</span>
                <input type="range" min="0" max="1" step="0.01" v-model.number="blend"
                       class="sbn-blend-slider" aria-label="Blend between samples and demo audio" />
                <span class="sbn-blend-label" :class="{ 'is-active': blend >= 0.5 }">Demo</span>
              </div>
            </template>
          </RhythmPatternExpanded>

          <!-- Standard mode: existing 2-row component -->
          <RhythmPattern
            v-else
            :pattern="pattern"
            :playable="true"
            :mini="false"
            :demo-url="pattern.demoUrl"
            :color="categoryColor"
          >
            <template v-if="pattern.demoUrl" #transport-extra>
              <div class="sbn-blend-control">
                <span class="sbn-blend-label" :class="{ 'is-active': blend < 0.5 }">Samples</span>
                <input
                  type="range"
                  min="0"
                  max="1"
                  step="0.01"
                  v-model.number="blend"
                  class="sbn-blend-slider"
                  aria-label="Blend between samples and demo audio"
                />
                <span class="sbn-blend-label" :class="{ 'is-active': blend >= 0.5 }">Demo</span>
              </div>
            </template>
          </RhythmPattern>
        </div>

        <div v-if="pattern.description" class="sbn-rhythm-section">
          <h2 class="sbn-section-heading">Description</h2>
          <div class="sbn-rhythm-section-body sbn-prose" v-html="pattern.description"></div>
        </div>

        <div v-if="songs.length" class="sbn-rhythm-section">
          <MediaShelf title="Used in songs" view-all-href="/library/songs">
            <SongShelfCard v-for="song in songs" :key="song.id" :song="song" />
          </MediaShelf>
        </div>

        <div v-if="courses && courses.length" class="sbn-rhythm-section">
          <MediaShelf title="Related Courses" view-all-href="/learn">
            <CourseShelfCard v-for="course in courses" :key="course.id" :course="course" />
          </MediaShelf>
        </div>

      </div>

      <!-- Right: related patterns sidebar -->
      <aside class="sbn-show-sidebar">
        <SkillsBuiltPanel v-if="skills && skills.length" :skills="skills" />
        <div class="sbn-show-sidebar-card">
          <h3 class="sbn-show-sidebar-heading">More {{ categoryLabel }} patterns</h3>
          <div v-if="siblings.length" class="sbn-siblings-list">
            <RhythmLink
              v-for="sibling in siblings"
              :key="sibling.id"
              :rhythm="{ ...sibling, playerData: sibling }"
            />
          </div>
          <p v-else class="sbn-empty-siblings">No other patterns in this category.</p>
        </div>
      </aside>

    </div>
  </div>
</template>

<style scoped>

/* Header */
.sbn-rhythm-show-header {
  padding: 24px 28px;
  margin-bottom: 32px;
}


/* Pattern section */
.sbn-rhythm-pattern-section {
  margin-bottom: 32px;
}

/* Content sections */
.sbn-rhythm-section {
  margin-bottom: 32px;
}

.sbn-rhythm-show :deep(.sbn-section-heading) {
  margin: 0 0 14px;
  padding-bottom: 8px;
}

.sbn-rhythm-show :deep(.sbn-rhythm-link:hover) {
  background: var(--cat-bg);
  border-color: var(--cat-border);
}

.sbn-rhythm-show :deep(.sbn-rhythm-link:hover .sbn-rhythm-link__name) {
  color: var(--cat-text);
}

.sbn-rhythm-section-body {
  margin: 0;
  font-size: 15px;
  line-height: 1.7;
  color: var(--clr-text);
}

/* Siblings list */
.sbn-siblings-list {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.sbn-empty-siblings {
  margin: 0;
  font-size: 13px;
  color: var(--clr-text-muted);
  font-style: italic;
}

@media (max-width: 768px) {
  .sbn-rhythm-show-title {
    font-size: 1.8em;
  }
}
</style>
