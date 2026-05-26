<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { useBackNav } from '@/composables/useBackNav';

const props = defineProps<{
    libraryHref: string
    libraryLabel: string
    prevTitle?: string | null
}>()

const nav = useBackNav(props.libraryHref, props.libraryLabel, props.prevTitle)
const target = nav.prev ?? nav.library
</script>

<template>
  <Link :href="target.href" class="sbn-btn sbn-btn-secondary sbn-btn-sm sbn-back-btn">
    ← {{ target.label }}
  </Link>
</template>

<style scoped>
.sbn-back-btn {
  margin-bottom: 24px;
}
</style>
