<script setup>
const props = defineProps({
  /** Playback-rate multiplier, 0.8–1.2 (±20%). 1 = normal speed. */
  modelValue: { type: Number, default: 1 },
});

const emit = defineEmits(['update:modelValue']);

function onInput(e) {
  emit('update:modelValue', +e.target.value);
}
</script>

<template>
  <div class="sbn-rate-slider">
    <span class="sbn-rate-label">Speed</span>
    <input
      type="range"
      min="0.8"
      max="1.2"
      step="0.01"
      :value="modelValue"
      @input="onInput"
    />
    <span class="sbn-rate-value">{{ Math.round(modelValue * 100) }}%</span>
  </div>
</template>

<style scoped>
.sbn-rate-slider {
  display: flex;
  align-items: center;
  gap: 8px;
}

.sbn-rate-label {
  font-size: 11px;
  font-weight: 600;
  color: var(--clr-text-dim);
  white-space: nowrap;
}

.sbn-rate-slider input[type="range"] {
  width: 80px;
  accent-color: var(--stage-accent, var(--clr-accent));
}

.sbn-rate-value {
  font-family: var(--font-mono);
  font-size: 11px;
  font-weight: 600;
  color: var(--clr-text-dim);
  min-width: 32px;
  text-align: right;
}
</style>
