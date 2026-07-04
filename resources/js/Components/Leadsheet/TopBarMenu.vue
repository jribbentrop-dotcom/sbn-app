<script setup>
import { ref, onMounted, onUnmounted } from 'vue';

defineProps({
  label: { type: String, default: 'Options' },
});

const open = ref(false);
const rootRef = ref(null);

function toggle() {
  open.value = !open.value;
}

function onClickOutside(e) {
  if (open.value && rootRef.value && !rootRef.value.contains(e.target)) {
    open.value = false;
  }
}

function onKeydown(e) {
  if (e.key === 'Escape') open.value = false;
}

onMounted(() => {
  document.addEventListener('click', onClickOutside);
  document.addEventListener('keydown', onKeydown);
});
onUnmounted(() => {
  document.removeEventListener('click', onClickOutside);
  document.removeEventListener('keydown', onKeydown);
});
</script>

<template>
  <div ref="rootRef" class="tbm-root">
    <button class="tbm-trigger" type="button" :aria-expanded="open" @click="toggle">
      <span>{{ label }}</span>
      <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
    </button>
    <div v-if="open" class="tbm-panel" @click="open = false">
      <slot />
    </div>
  </div>
</template>

<style scoped>
.tbm-root {
  position: relative;
}

.tbm-trigger {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 5px 12px;
  font-size: 11px;
  font-weight: 500;
  color: rgba(255, 255, 255, 0.92);
  background: rgba(255, 255, 255, 0.15);
  border: 1px solid rgba(255, 255, 255, 0.3);
  border-radius: var(--radius-sm);
  cursor: pointer;
  transition: background 0.15s;
}

.tbm-trigger:hover {
  background: rgba(255, 255, 255, 0.25);
}

.tbm-trigger[aria-expanded="true"] svg {
  transform: rotate(180deg);
}

.tbm-trigger svg {
  transition: transform 0.15s ease;
}

.tbm-panel {
  position: absolute;
  top: calc(100% + 8px);
  right: 0;
  min-width: 220px;
  background: var(--clr-surface);
  border: 1px solid var(--clr-border);
  border-radius: var(--radius);
  box-shadow: var(--clr-shadow-lg, 0 12px 28px rgba(0, 0, 0, 0.18));
  padding: 12px;
  display: flex;
  flex-direction: column;
  gap: 12px;
  z-index: 50;
  color: var(--clr-text);
}
</style>
