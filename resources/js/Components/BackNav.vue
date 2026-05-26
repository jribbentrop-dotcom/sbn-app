<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { useBackNav } from '@/composables/useBackNav';

const props = defineProps<{
    libraryHref: string
    libraryLabel: string
    prevTitle?: string | null
}>()

const nav = useBackNav(props.libraryHref, props.libraryLabel, props.prevTitle)
</script>

<template>
  <div class="sbn-back-nav">
    <Link
      v-if="nav.prev"
      :href="nav.prev.href"
      class="sbn-btn sbn-btn-secondary sbn-btn-sm"
    >← {{ nav.prev.label }}</Link>
    <Link
      :href="nav.library.href"
      class="sbn-back-link"
      :class="{ 'sbn-back-link--solo': !nav.prev }"
    >← {{ nav.library.label }}</Link>
  </div>
</template>

<style scoped>
.sbn-back-nav {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 6px;
  margin-bottom: 24px;
}
</style>
