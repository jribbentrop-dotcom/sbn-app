<script setup lang="ts">
import { computed, ref, onMounted, onBeforeUnmount } from 'vue';
import { Link } from '@inertiajs/vue3';
import Breadcrumb from '@/Components/Breadcrumb.vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import { getCategoryStyle, getCategoryColor } from '@/composables/useCategoryColors';
import { chordShowUrl } from '@/composables/useChordUrl';

import ChordCard from '@/Components/Library/ChordCard.vue';
import RhythmStrip from '@/Components/Library/RhythmStrip.vue';
import ProgressionLink from '@/Components/Library/ProgressionLink.vue';
import MediaShelf from '@/Components/Library/MediaShelf.vue';
import CourseShelfCard from '@/Components/Course/CourseShelfCard.vue';
import type { CourseShelfCardData } from '@/Components/Course/CourseShelfCard.vue';

defineOptions({ layout: PublicLayout });

interface Song {
  id: number;
  slug: string;
  title: string;
  composer: string | null;
  songKey: string | null;
  tempo: number | null;
  timeSignature: string | null;
  description: string | null;
  harmonyNotes: string | null;
  formNotes: string | null;
  voicingNotes: string | null;
  rhythm: string | null;
  rhythmName: string | null;
  rhythmCategory: string | null;
  rhythmData: any | null;
  styleSlug: string;
  measureCount: number | null;
  popularity: number | null;
  coverImagePath: string | null;
  tags: string[];
}

interface ProgressionRef {
  id: number;
  slug: string;
  name: string;
  category: string;
  numeralsDisplay: string;
  tiles: any[];
}

interface Props {
  song: Song;
  chordNames: string[];
  chords: any[];
  progressions: ProgressionRef[];
  courses: CourseShelfCardData[];
}

const props = defineProps<Props>();

const categoryStyle = computed(() => getCategoryStyle(props.song.styleSlug));

const chordsScrollEl = ref<HTMLElement | null>(null);
const chordsCanLeft  = ref(false);
const chordsCanRight = ref(false);

function updateChordsScroll() {
  const el = chordsScrollEl.value;
  if (!el) return;
  chordsCanLeft.value  = el.scrollLeft > 0;
  chordsCanRight.value = el.scrollLeft + el.clientWidth < el.scrollWidth - 1;
}

function scrollChords(dir: 1 | -1) {
  chordsScrollEl.value?.scrollBy({ left: dir * 122, behavior: 'smooth' });
}

let chordsRo: ResizeObserver | null = null;
onMounted(() => {
  const el = chordsScrollEl.value;
  if (!el) return;
  el.addEventListener('scroll', updateChordsScroll, { passive: true });
  chordsRo = new ResizeObserver(updateChordsScroll);
  chordsRo.observe(el);
  updateChordsScroll();
});
onBeforeUnmount(() => {
  chordsScrollEl.value?.removeEventListener('scroll', updateChordsScroll);
  chordsRo?.disconnect();
});
const categoryColor = computed(() => getCategoryColor(props.song.styleSlug));
const styleLabel    = computed(() => (props.song.styleSlug ?? 'song').replace(/-/g, ' '));

const songPopularityTier = computed(() => {
  const p = props.song.popularity ?? 0;
  if (p >= 11) return { tier: 'iconic',     label: 'Iconic' };
  if (p >= 6)  return { tier: 'essential',  label: 'Essential' };
  if (p >= 3)  return { tier: 'common',     label: 'Common' };
  if (p >= 1)  return { tier: 'occasional', label: 'Rare' };
  return null;
});

</script>

<template>
  <div class="sbn-page-detail sbn-song-show sbn-has-category-gradient" :style="categoryStyle">

    <Breadcrumb :segments="[{ label: 'Song Library', href: '/library/songs' }, { label: song.title }]" :color="categoryColor" />

    <!-- ── Hero ──────────────────────────────────────────────────────────── -->
    <header class="sbn-ss-hero sbn-detail-hero">

      <div class="sbn-ss-hero-body">
        <!-- Left: text -->
        <div class="sbn-ss-hero-text">
          <div class="sbn-ss-hero-badges">
            <span class="sbn-cat-badge sbn-cat-badge-filled" :style="{ '--cat-clr': categoryColor }">{{ styleLabel }}</span>
            <span v-if="songPopularityTier" class="sbn-card-pop" :class="`sbn-pop-${songPopularityTier.tier}`">{{ songPopularityTier.label }}</span>
            <span v-for="tag in (song.tags ?? [])" :key="tag" class="sbn-hashtag">#{{ tag }}</span>
          </div>

          <h1 class="sbn-ss-title">{{ song.title }}</h1>
          <p v-if="song.composer" class="sbn-ss-composer">{{ song.composer }}</p>

          <div class="sbn-ss-meta">
            <span v-if="song.songKey"       class="sbn-song-meta-chip"><strong>Key</strong> {{ song.songKey }}</span>
            <span v-if="song.tempo"         class="sbn-song-meta-chip"><strong>Tempo</strong> {{ song.tempo }} bpm</span>
            <span v-if="song.timeSignature" class="sbn-song-meta-chip"><strong>Time</strong> {{ song.timeSignature }}</span>
            <span v-if="song.rhythm"        class="sbn-song-meta-chip"><strong>Rhythm</strong> {{ song.rhythm }}</span>
            <span v-if="song.measureCount"  class="sbn-song-meta-chip"><strong>Bars</strong> {{ song.measureCount }}</span>
          </div>

          <div class="sbn-ss-cta">
            <Link :href="`/library/songs/${song.slug}/viewer`" class="sbn-btn sbn-btn-primary sbn-btn-lg">
              Open in viewer →
            </Link>
          </div>
        </div>

        <!-- Right: image or gradient -->
        <div class="sbn-ss-hero-image">
          <img v-if="song.coverImagePath" :src="song.coverImagePath" :alt="song.title" class="sbn-ss-hero-img" />
          <div v-else class="sbn-ss-hero-fallback">
            <span class="sbn-ss-hero-fallback-label">{{ styleLabel }}</span>
          </div>
        </div>
      </div>
    </header>

    <!-- ── Description ───────────────────────────────────────────────────── -->
    <div v-if="song.description" class="sbn-ss-section">
      <h2 class="sbn-ss-section-title">About this song</h2>
      <p class="sbn-ss-description">{{ song.description }}</p>
    </div>

    <!-- ── Chords + Progressions side by side ───────────────────────────── -->
    <div v-if="(chords && chords.length) || progressions.length" class="sbn-ss-two-col">

      <div v-if="chords && chords.length" class="sbn-ss-section sbn-ss-col">
        <h2 class="sbn-ss-section-title">Chords</h2>
        <div class="sbn-card-scroll-wrap">
          <div ref="chordsScrollEl" class="sbn-card-scroll">
            <Link v-for="chord in chords" :key="chord.id" :href="chordShowUrl(chord)" class="sbn-card-scroll-item">
              <ChordCard :chord="chord" mini :show-root="true" :no-nav="true" />
            </Link>
          </div>
          <button v-show="chordsCanLeft"  class="sbn-card-scroll-btn sbn-card-scroll-btn--prev" @click="scrollChords(-1)" aria-label="Scroll left">‹</button>
          <button v-show="chordsCanRight" class="sbn-card-scroll-btn sbn-card-scroll-btn--next" @click="scrollChords(1)"  aria-label="Scroll right">›</button>
        </div>
      </div>

      <div v-if="progressions.length" class="sbn-ss-section sbn-ss-col">
        <h2 class="sbn-ss-section-title">Progressions</h2>
        <div class="sbn-ss-prog-list">
          <ProgressionLink
            v-for="prog in progressions"
            :key="prog.id"
            :progression="prog"
          />
        </div>
      </div>

    </div>

    <!-- ── Rhythm ─────────────────────────────────────────────────────────── -->
    <div v-if="song.rhythmData" class="sbn-ss-section">
      <h2 class="sbn-ss-section-title">
        Rhythm
        <Link v-if="song.rhythm" :href="`/library/rhythms/${song.rhythm}`" class="sbn-ss-rhythm-link">
          {{ song.rhythmName || song.rhythm }} →
        </Link>
      </h2>
      <RhythmStrip
        :pattern="song.rhythmData"
        :tempo="song.tempo ?? undefined"
        :playable="true"
        :show-meta="true"
        :color="categoryColor"
      />
    </div>

    <!-- ── Related Courses ──────────────────────────────────────────────── -->
    <div v-if="courses && courses.length" class="sbn-ss-section">
      <MediaShelf title="Related Courses" view-all-href="/learn">
        <CourseShelfCard v-for="course in courses" :key="course.id" :course="course" />
      </MediaShelf>
    </div>

  </div>
</template>

<style scoped>
/* --category-color and --category-gradient come from .sbn-has-category-gradient
   (sbn-design-system.css). getCategoryStyle() sets --category-color inline. */
.sbn-song-show {}

/* ── Hero ────────────────────────────────────────────────────────────────── */

.sbn-ss-hero {
  overflow: hidden;
  margin-bottom: 32px;
}

.sbn-ss-hero-body {
  display: grid;
  grid-template-columns: 1fr 260px;
}

.sbn-ss-hero-text {
  padding: 28px 32px;
}

.sbn-ss-hero-badges {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
  margin-bottom: 12px;
}


.sbn-ss-hero-image {
  position: relative;
  overflow: hidden;
  min-height: 220px;
}

.sbn-ss-hero-img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}

.sbn-ss-hero-fallback {
  width: 100%;
  height: 100%;
  background: var(--category-gradient);
  display: flex;
  align-items: center;
  justify-content: center;
}

.sbn-ss-hero-fallback-label {
  color: rgba(255,255,255,0.75);
  font-size: 0.85em;
  font-weight: 600;
  text-transform: capitalize;
  letter-spacing: 0.04em;
}

/* ── Shared typography ───────────────────────────────────────────────────── */

.sbn-ss-title {
  font-size: 2em;
  font-weight: 800;
  color: var(--clr-text);
  margin: 0 0 6px;
  letter-spacing: -0.02em;
}

.sbn-ss-composer {
  font-size: 1.05em;
  color: var(--clr-text-muted);
  margin: 0 0 16px;
}


.sbn-ss-meta {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 20px;
}

.sbn-song-meta-chip {
  background: var(--clr-surface-2);
  border-radius: 6px;
  padding: 4px 10px;
  font-size: 0.82em;
  color: var(--clr-text-muted);
}

.sbn-song-meta-chip strong {
  color: var(--clr-text);
  margin-right: 4px;
}

.sbn-ss-cta {
  display: flex;
  align-items: center;
  gap: 12px;
}

/* ── Sections ────────────────────────────────────────────────────────────── */

.sbn-ss-section {
  margin-bottom: 32px;
}

.sbn-ss-section-title {
  font-size: 1.05em;
  font-weight: 700;
  color: var(--clr-text);
  margin: 0 0 14px;
  padding-bottom: 8px;
  border-bottom: 2px solid var(--clr-border);
  display: flex;
  align-items: baseline;
  gap: 10px;
}

.sbn-ss-description {
  font-size: 0.95em;
  line-height: 1.7;
  color: var(--clr-text-muted);
  margin: 0;
}

/* ── Chords ──────────────────────────────────────────────────────────────── */

.sbn-card-scroll-wrap {
  max-width: calc(4 * 110px + 3 * 12px);
}

/* ── Two-column layout ───────────────────────────────────────────────────── */

.sbn-ss-two-col {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 32px;
  align-items: start;
}

.sbn-ss-col { margin-bottom: 0; }

/* ── Progression list (rows rendered by ProgressionLink.vue) ─────────────── */

.sbn-ss-prog-list {
  display: flex;
  flex-direction: column;
  gap: 6px;
}


/* ── Rhythm section title link ───────────────────────────────────────────── */

.sbn-ss-rhythm-link {
  font-size: 0.82em;
  font-weight: 500;
  color: var(--clr-text-muted);
  text-decoration: none;
  margin-left: auto;
}

.sbn-ss-rhythm-link:hover {
  color: var(--category-color);
}

/* ── Responsive ──────────────────────────────────────────────────────────── */

@media (max-width: 768px) {
.sbn-ss-hero-body { grid-template-columns: 1fr; }

  .sbn-ss-hero-image {
    min-height: 180px;
    order: -1;
  }

  .sbn-ss-hero-text { padding: 20px; }

  .sbn-ss-title { font-size: 1.6em; }

  .sbn-ss-two-col {
    grid-template-columns: 1fr;
    gap: 0;
  }

  .sbn-ss-col { margin-bottom: 32px; }
}
</style>
