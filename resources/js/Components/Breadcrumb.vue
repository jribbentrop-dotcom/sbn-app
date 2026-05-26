<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

export interface BreadcrumbSegment {
  label: string
  href?: string
}

defineProps<{
  segments: BreadcrumbSegment[]
  color?: string  // category color hex — omit to use brand gradient
}>()
</script>

<template>
  <nav
    class="sbn-breadcrumb"
    :class="color ? 'sbn-breadcrumb--cat' : 'sbn-breadcrumb--brand'"
    :style="color ? { '--breadcrumb-clr': color } : undefined"
    aria-label="Breadcrumb"
  >
    <template v-for="(seg, i) in segments" :key="i">
      <span v-if="i > 0" class="sbn-breadcrumb-sep" aria-hidden="true">›</span>
      <Link v-if="seg.href" :href="seg.href" class="sbn-breadcrumb-link">{{ seg.label }}</Link>
      <span v-else class="sbn-breadcrumb-current" aria-current="page">{{ seg.label }}</span>
    </template>
  </nav>
</template>
