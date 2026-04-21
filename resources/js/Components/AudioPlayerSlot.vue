<script setup lang="ts">
import { ref } from 'vue';

const audioSlotRef = ref<HTMLElement | null>(null);

defineExpose({ audioSlotRef });
</script>

<template>
  <div ref="audioSlotRef" class="audio-player-slot"></div>
</template>
