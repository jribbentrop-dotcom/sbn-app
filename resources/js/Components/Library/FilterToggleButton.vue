<script setup lang="ts">
defineProps<{
  modelValue: boolean;
  hasFilters?: boolean;
}>();

const emit = defineEmits<{
  'update:modelValue': [value: boolean];
}>();
</script>

<template>
  <button type="button" class="sbn-lib-filter-toggle" @click="emit('update:modelValue', true)">
    <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
      <path d="M2 4h12M4.5 8h7M7 12h2" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
    </svg>
    <slot>Filters</slot>
    <span v-if="hasFilters" class="sbn-lib-filter-toggle-dot" aria-hidden="true"></span>
  </button>
</template>
