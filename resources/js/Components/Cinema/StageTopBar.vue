<template>
  <div class="stage-top">
    <div class="stage-top-title">{{ title }}</div>
    <div v-if="credit" class="stage-top-credit">{{ credit }}</div>
    <div class="stage-top-divider"></div>
    <div class="stage-top-meta">
      <span class="stage-top-chip"><strong>{{ songKey }}</strong></span>
      <span class="stage-top-chip">{{ timeSignature }}</span>
      <span class="stage-top-chip">{{ barCount }} bars</span>
    </div>
    <div class="stage-top-spacer"></div>
    <!-- Back to classic view -->
    <a v-if="classicUrl" :href="classicUrl" class="stage-top-icon-btn" title="Classic view">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>
    </a>
  </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  title:         { type: String, default: '' },
  composer:      { type: String, default: null },
  songKey:       { type: String, default: '' },
  timeSignature: { type: String, default: '4/4' },
  barCount:      { type: Number, default: 0 },
  classicUrl:    { type: String, required: true },
});


const credit = computed(() => props.composer ?? null);
</script>

<style scoped>
.stage-top {
  display: flex;
  align-items: center;
  gap: 16px;
  padding-bottom: 18px;
  border-bottom: 1px solid var(--clr-border);
  margin-bottom: 28px;
}

.stage-top-title {
  font-family: var(--font-display);
  font-style: normal;
  font-size: 22px;
  font-weight: 600;
  color: var(--clr-text);
  letter-spacing: 0.2px;
  white-space: nowrap;
}


.stage-top-credit {
  font-size: 12px;
  color: var(--clr-text-muted);
  letter-spacing: 0.2px;
  white-space: nowrap;
}

.stage-top-divider {
  width: 1px;
  height: 22px;
  background: var(--clr-border);
  flex-shrink: 0;
}

.stage-top-meta {
  display: flex;
  align-items: center;
  gap: 10px;
}

.stage-top-chip {
  font-family: var(--font-mono);
  font-size: 11px;
  font-weight: 500;
  padding: 5px 10px;
  border: 1px solid var(--clr-border);
  border-radius: var(--radius-sm);
  color: var(--clr-text-dim);
  letter-spacing: 0.3px;
  white-space: nowrap;
}

.stage-top-chip strong {
  color: var(--clr-text);
  font-weight: 600;
}

.stage-top-spacer {
  flex: 1;
}

.stage-top-icon-btn {
  width: 32px;
  height: 32px;
  border: 1px solid var(--clr-border);
  background: transparent;
  color: var(--clr-text-dim);
  border-radius: var(--radius-sm);
  cursor: pointer;
  display: grid;
  place-items: center;
  transition: all 0.15s ease;
  font-size: 13px;
  text-decoration: none;
  flex-shrink: 0;
}

.stage-top-icon-btn:hover {
  color: var(--clr-text);
  background: var(--clr-surface-2);
  border-color: var(--clr-border);
}
</style>
