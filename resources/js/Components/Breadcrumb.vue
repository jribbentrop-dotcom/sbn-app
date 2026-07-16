<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ref, computed, onMounted, onUnmounted } from 'vue';

export interface BreadcrumbSegment {
  label: string
  href?: string
}

const props = defineProps<{
  segments: BreadcrumbSegment[]
  color?: string  // category color hex — omit to use brand gradient
  /** 'lg' = taller band for stage-style pages (Viewer/Cinema); default fits prose Show pages. */
  size?: 'default' | 'lg'
}>()

// Phone screens can't fit a full multi-level trail (e.g. Songs › Style ›
// Difficulty › Title) alongside the page's own action buttons (Options menu,
// Classic/Cinema toggle) — restrict to the last 2 levels (immediate parent +
// current page) there. Centralized here (rather than per-page) so every
// breadcrumb everywhere gets it and nothing can drift out of sync.
const PHONE_MAX_SEGMENTS = 2;
const isPhone = ref(false);
let mql: MediaQueryList | null = null;
function updateIsPhone() { isPhone.value = mql?.matches ?? false; }

onMounted(() => {
  mql = window.matchMedia('(max-width: 640px)');
  updateIsPhone();
  mql.addEventListener('change', updateIsPhone);
});
onUnmounted(() => {
  mql?.removeEventListener('change', updateIsPhone);
});

const visibleSegments = computed(() => {
  if (!isPhone.value || props.segments.length <= PHONE_MAX_SEGMENTS) return props.segments;
  return props.segments.slice(-PHONE_MAX_SEGMENTS);
});
</script>

<template>
  <nav
    class="sbn-breadcrumb"
    :class="[color ? 'sbn-breadcrumb--cat' : 'sbn-breadcrumb--brand', size === 'lg' ? 'sbn-breadcrumb--lg' : '']"
    :style="color ? { '--breadcrumb-clr': color } : undefined"
    aria-label="Breadcrumb"
  >
    <template v-for="(seg, i) in visibleSegments" :key="i">
      <span v-if="i > 0" class="sbn-breadcrumb-sep" aria-hidden="true">›</span>
      <Link v-if="seg.href" :href="seg.href" class="sbn-breadcrumb-link">{{ seg.label }}</Link>
      <span v-else class="sbn-breadcrumb-current" aria-current="page">{{ seg.label }}</span>
    </template>
    <div v-if="$slots.actions" class="sbn-breadcrumb-spacer"></div>
    <slot name="actions" />
  </nav>
</template>

<style scoped>
/* Row can still get tight around the actions slot (Options menu, view
   toggle) even with segments trimmed above — let it wrap to a second line
   rather than overflow. Was previously duplicated ad hoc per consumer
   (LeadsheetViewer had it, StageTopBar didn't — the gap that caused Cinema's
   top bar to overflow); centralizing here means every consumer gets it. */
@media (max-width: 768px) {
  .sbn-breadcrumb {
    flex-wrap: wrap;
    row-gap: 10px;
  }
}
</style>
