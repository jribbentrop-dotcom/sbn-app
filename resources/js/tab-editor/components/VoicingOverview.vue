<template>
  <div class="sbn-ve-voicings-overview">
    <!-- Overview of all voicings used in the leadsheet -->
    <div v-for="(voicing, name) in voicings" :key="name" class="sbn-ve-voicings-card">
      <span class="sbn-ve-voicings-name">{{ name }}</span>
      <div class="sbn-ve-voicings-diagram">
        <!-- SVG diagram -->
      </div>
    </div>
  </div>
</template>

<script setup>
/**
 * VoicingOverview
 * The fixed section at the top/bottom of the grid that shows a summary 
 * of all unique chord voicings defined in the current document.
 */

const props = defineProps({
  voicings: {
    type: Object,
    default: () => ({})
  }
});
</script>
