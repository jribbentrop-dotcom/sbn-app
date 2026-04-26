<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import RhythmPattern from './RhythmPattern.vue';
import type { RhythmPatternWithMeta } from './RhythmPattern.vue';
import { getCategoryStyle } from '../../composables/useCategoryColors';

interface Props {
  pattern: RhythmPatternWithMeta;
  mini?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
  mini: false,
});

const categoryStyle = computed(() => getCategoryStyle(props.pattern.styleSlug));
</script>

<template>
  <div
    class="sbn-rhythm-card"
    :style="categoryStyle"
  >
    <!-- Clickable header area -->
    <Link :href="`/library/rhythms/${pattern.slug}`" class="sbn-rhythm-card-link">
      <!-- Header -->
      <div class="sbn-rhythm-card-header">
        <h3 class="sbn-rhythm-card-name">{{ pattern.name }}</h3>
        <span class="sbn-rhythm-card-category">{{ pattern.category }}</span>
      </div>

      <!-- Meta -->
      <div class="sbn-rhythm-card-meta">
        <span class="sbn-badge sbn-badge-muted">{{ pattern.timeSignature }}</span>
        <span class="sbn-badge sbn-badge-muted">{{ pattern.bpm }} BPM</span>
        <span
          v-if="pattern.gridType !== 'sixteenth'"
          class="sbn-badge"
          :class="`sbn-badge-${pattern.gridType}`"
        >
          {{ pattern.gridType }}
        </span>
      </div>

      <!-- Description (if not mini) -->
      <p v-if="!mini && pattern.description" class="sbn-rhythm-card-desc">
        {{ pattern.description }}
      </p>
    </Link>

    <!-- Pattern preview with play button -->
    <div class="sbn-rhythm-card-preview">
      <RhythmPattern
        :pattern="pattern"
        :mini="true"
        :playable="true"
        :loop="false"
      />
    </div>
  </div>
</template>

<style scoped>
.sbn-rhythm-card {
  position: relative;
  background: var(--clr-white);
  border: 1px solid var(--clr-border);
  border-radius: var(--radius);
  padding: 16px;
  transition: transform 0.25s var(--ease), border-color 0.2s, box-shadow 0.2s;
  overflow: hidden;
}

.sbn-rhythm-card:hover {
  transform: translateY(-2px);
  border-color: var(--category-color, var(--clr-red));
  box-shadow: var(--clr-shadow);
}

.sbn-rhythm-card-link {
  text-decoration: none;
  color: inherit;
  display: block;
}

/* Header */
.sbn-rhythm-card-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  margin-bottom: 8px;
  gap: 8px;
}

.sbn-rhythm-card-name {
  margin: 0;
  font-size: 16px;
  font-weight: 600;
  color: var(--clr-text);
  line-height: 1.3;
}

.sbn-rhythm-card-category {
  font-size: 11px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--category-color, var(--clr-text-muted));
  background: var(--clr-surface-2);
  padding: 2px 8px;
  border-radius: 4px;
  white-space: nowrap;
}

/* Meta badges */
.sbn-rhythm-card-meta {
  display: flex;
  gap: 6px;
  margin-bottom: 12px;
  flex-wrap: wrap;
}

.sbn-badge {
  padding: 2px 8px;
  border-radius: 4px;
  font-size: 11px;
  font-weight: 500;
  background: var(--clr-surface-2);
  color: var(--clr-text-muted);
}

.sbn-badge-muted {
  background: var(--clr-surface-3);
}

.sbn-badge-eighth {
  background: #ebf8ff;
  color: var(--clr-style-jazz);
}

.sbn-badge-triplet {
  background: #f0fdf4;
  color: var(--clr-style-samba);
}

/* Preview area */
.sbn-rhythm-card-preview {
  background: var(--clr-surface-2);
  border-radius: var(--radius-sm);
  padding: 12px;
  margin-bottom: 12px;
}

/* Description */
.sbn-rhythm-card-desc {
  margin: 0;
  font-size: 13px;
  color: var(--clr-text-muted);
  line-height: 1.5;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

/* Controls overlay (play button) */
.sbn-rhythm-card-controls {
  margin-top: 8px;
}

/* Category color accent on hover */
.sbn-rhythm-card:hover .sbn-rhythm-card-category {
  background: var(--category-color, var(--clr-surface-2));
  color: var(--clr-white);
}
</style>
