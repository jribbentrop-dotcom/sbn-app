<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import RhythmPattern from '@/Components/Library/RhythmPattern.vue';
import RhythmCard from '@/Components/Library/RhythmCard.vue';
import type { RhythmPatternWithMeta } from '@/Components/Library/RhythmPattern.vue';
import { getCategoryStyle } from '@/composables/useCategoryColors';
import { getAudioEngine } from '../../../audio/engine/AudioEngine.js';

defineOptions({ layout: PublicLayout });

interface SongRef {
  id: number;
  slug: string;
  title: string;
  composer: string | null;
  songKey: string | null;
}

interface Props {
  pattern: RhythmPatternWithMeta;
  siblings: RhythmPatternWithMeta[];
  songs: SongRef[];
}

const props = defineProps<Props>();

const categoryStyle = computed(() => getCategoryStyle(props.pattern.styleSlug));

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
</script>

<template>
  <div class="sbn-rhythm-show">
    <div class="sbn-rhythm-show-container">
      <!-- Header -->
      <header class="sbn-rhythm-show-header" :style="categoryStyle">
        <Link href="/library/rhythms" class="sbn-back-link">← Back to Rhythm Library</Link>
        <h1 class="sbn-rhythm-show-title">{{ pattern.name }}</h1>
        <div class="sbn-rhythm-show-meta">
          <span class="sbn-category-badge">{{ pattern.category }}</span>
          <span class="sbn-meta-badge">{{ pattern.timeSignature }}</span>
          <span class="sbn-meta-badge">{{ pattern.bpm }} BPM</span>
          <span class="sbn-meta-badge">{{ pattern.gridType }}</span>
        </div>
      </header>

      <!-- Main content -->
      <div class="sbn-rhythm-show-content">
        <!-- Full pattern display -->
        <div class="sbn-rhythm-show-main">
          <div class="sbn-pattern-display">
            <RhythmPattern
              :pattern="pattern"
              :playable="true"
              :mini="false"
              :demo-url="pattern.demoUrl"
            >
              <template v-if="hasDemo" #transport-extra>
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

          <!-- Description -->
          <div v-if="pattern.description" class="sbn-pattern-description">
            <h2>Description</h2>
            <p>{{ pattern.description }}</p>
          </div>

          <!-- Used in songs -->
          <div v-if="songs.length" class="sbn-pattern-songs">
            <h2>Used in songs</h2>
            <ul class="sbn-songs-list">
              <li v-for="song in songs" :key="song.id" class="sbn-song-row">
                <Link :href="`/library/songs/${song.slug}`" class="sbn-song-row-link">
                  {{ song.title }}
                </Link>
                <span v-if="song.composer" class="sbn-song-row-meta">{{ song.composer }}</span>
                <span v-if="song.songKey" class="sbn-song-row-key">{{ song.songKey }}</span>
              </li>
            </ul>
          </div>
        </div>

        <!-- Sidebar with siblings -->
        <aside class="sbn-rhythm-show-sidebar">
          <div class="sbn-sidebar-section">
            <h3>More {{ pattern.category }} patterns</h3>
            <div v-if="siblings.length" class="sbn-siblings-list">
              <RhythmCard
                v-for="sibling in siblings"
                :key="sibling.id"
                :pattern="sibling"
                :mini="true"
              />
            </div>
            <p v-else class="sbn-empty-siblings">No other patterns in this category.</p>
          </div>
        </aside>
      </div>
    </div>
  </div>
</template>

<style scoped>
.sbn-rhythm-show {
  max-width: 1400px;
  margin: 0 auto;
  padding: 40px 20px 80px;
}

.sbn-rhythm-show-container {
  max-width: 1200px;
  margin: 0 auto;
}

/* Header */
.sbn-rhythm-show-header {
  margin-bottom: 32px;
  padding-bottom: 24px;
  border-bottom: 3px solid var(--category-color, var(--clr-red));
}



.sbn-rhythm-show-title {
  margin: 0 0 12px;
  font-size: 2.2em;
  font-weight: 700;
  color: var(--clr-text);
}

.sbn-rhythm-show-meta {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
}

.sbn-category-badge {
  padding: 4px 12px;
  border-radius: 4px;
  font-size: 12px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  background: var(--category-color, var(--clr-red));
  color: var(--clr-white);
}

.sbn-meta-badge {
  padding: 4px 12px;
  border-radius: 4px;
  font-size: 12px;
  font-weight: 500;
  background: var(--clr-surface-2);
  color: var(--clr-text-muted);
}

/* Content layout */
.sbn-rhythm-show-content {
  display: grid;
  grid-template-columns: 1fr 320px;
  gap: 32px;
}

/* Main pattern display */
.sbn-pattern-display {
  background: var(--clr-surface-2);
  border-radius: var(--radius);
  padding: 24px;
  margin-bottom: 24px;
}

/* Blend slider — inline next to play button */
.sbn-blend-control {
  display: flex;
  align-items: center;
  gap: 8px;
}

.sbn-blend-label {
  font-size: 11px;
  font-weight: 600;
  color: var(--clr-text-muted);
  white-space: nowrap;
  transition: color 0.15s;
}

.sbn-blend-label.is-active {
  color: var(--category-color, var(--clr-red));
}

.sbn-blend-slider {
  width: 160px;
  accent-color: var(--category-color, var(--clr-red));
}

/* Description section */
.sbn-pattern-description {
  margin-bottom: 24px;
}

/* Songs section */
.sbn-pattern-songs {
  margin-bottom: 24px;
}

.sbn-pattern-songs h2 {
  margin: 0 0 12px;
  font-size: 1.1em;
  font-weight: 600;
  color: var(--clr-text);
}

.sbn-songs-list {
  list-style: none;
  padding: 0;
  margin: 0;
  background: var(--clr-white);
  border: 1px solid var(--clr-border);
  border-radius: var(--radius);
  overflow: hidden;
}

.sbn-song-row {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 14px;
  border-bottom: 1px solid var(--clr-surface-2);
}

.sbn-song-row:last-child {
  border-bottom: none;
}

.sbn-song-row-link {
  font-size: 0.9em;
  font-weight: 500;
  color: var(--clr-text);
  text-decoration: none;
  flex: 1;
}

.sbn-song-row-link:hover {
  color: var(--category-color, var(--clr-red));
}

.sbn-song-row-meta {
  font-size: 0.82em;
  color: var(--clr-text-muted);
}

.sbn-song-row-key {
  font-size: 0.78em;
  font-weight: 600;
  padding: 1px 6px;
  border-radius: 4px;
  background: var(--clr-surface-2);
  color: var(--clr-text-muted);
  white-space: nowrap;
}

.sbn-pattern-description h2 {
  margin: 0 0 12px;
  font-size: 1.1em;
  font-weight: 600;
  color: var(--clr-text);
}

.sbn-pattern-description p {
  margin: 0;
  font-size: 15px;
  line-height: 1.7;
  color: var(--clr-text-muted);
}

/* Details section */
.sbn-pattern-details {
  background: var(--clr-white);
  border: 1px solid var(--clr-border);
  border-radius: var(--radius);
  padding: 20px;
}

.sbn-pattern-details h2 {
  margin: 0 0 16px;
  font-size: 1.1em;
  font-weight: 600;
  color: var(--clr-text);
}

.sbn-details-list {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 12px;
}

.sbn-detail-row {
  display: flex;
  justify-content: space-between;
  padding: 8px 0;
  border-bottom: 1px solid var(--clr-surface-2);
}

.sbn-detail-row:last-child {
  border-bottom: none;
}

.sbn-detail-row dt {
  font-size: 13px;
  color: var(--clr-text-muted);
  font-weight: 500;
}

.sbn-detail-row dd {
  font-size: 13px;
  color: var(--clr-text);
  font-weight: 600;
  margin: 0;
}

/* Sidebar */
.sbn-rhythm-show-sidebar {
  position: sticky;
  top: 80px;
  align-self: start;
}

.sbn-sidebar-section {
  background: var(--clr-white);
  border: 1px solid var(--clr-border);
  border-radius: var(--radius);
  padding: 20px;
}

.sbn-sidebar-section h3 {
  margin: 0 0 16px;
  font-size: 0.95em;
  font-weight: 600;
  color: var(--clr-text);
}

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

/* Responsive */
@media (max-width: 1024px) {
  .sbn-rhythm-show-content {
    grid-template-columns: 1fr;
  }

  .sbn-rhythm-show-sidebar {
    position: static;
    order: -1;
  }

  .sbn-siblings-list {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
  }
}

@media (max-width: 768px) {
  .sbn-rhythm-show {
    padding: 24px 16px 60px;
  }

  .sbn-rhythm-show-title {
    font-size: 1.8em;
  }

  .sbn-details-list {
    grid-template-columns: 1fr;
  }

  .sbn-siblings-list {
    grid-template-columns: 1fr;
  }
}
</style>
