<script setup lang="ts">
defineProps<{
  modelValue: boolean;
  hasFilters?: boolean;
  /** Overrides the "Clear all" button's visibility when a page's clear
   * condition is broader than hasFilters (e.g. a non-default sort with
   * no other filter active). Falls back to hasFilters when omitted. */
  showClearAll?: boolean;
}>();

const emit = defineEmits<{
  'update:modelValue': [value: boolean];
  clear: [];
}>();
</script>

<template>
  <button
    type="button"
    class="sbn-lib-filter-overlay"
    v-if="modelValue"
    @click="emit('update:modelValue', false)"
    aria-label="Close filters"
  />

  <aside class="sbn-lib-filter-sidebar" :class="{ 'sbn-lib-filter-open': modelValue }">
    <button type="button" class="sbn-lib-filter-close" @click="emit('update:modelValue', false)" aria-label="Close filters">
      <svg width="14" height="14" viewBox="0 0 14 14" fill="none">
        <path d="M1 1l12 12M13 1L1 13" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
      </svg>
    </button>

    <div class="sbn-lib-sidebar-header">
      <h3><slot name="title">Filters</slot></h3>
      <span v-if="$slots.count" class="sbn-lib-sidebar-count">
        <slot name="count" />
        <button v-if="hasFilters" type="button" class="sbn-lib-clear-btn" @click="emit('clear')">Clear</button>
      </span>
    </div>

    <slot />

    <button
      v-if="showClearAll ?? hasFilters"
      type="button"
      class="sbn-lib-sidebar-clear"
      @click="emit('clear')"
    >
      <slot name="clear-label">Clear All Filters</slot>
    </button>
  </aside>
</template>
