<template>
  <div class="stage-top">
    <div class="stage-top-title">
      <strong>{{ titleMain }}</strong>
      <span v-if="titleSuffix"> {{ titleSuffix }}</span>
    </div>
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
    <button class="stage-top-icon-btn" title="Toggle light/dark theme" @click="$emit('toggle-theme')" aria-label="Toggle theme">
      <svg v-if="theme === 'dark'" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
      <svg v-else width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
    </button>
    <button class="stage-top-icon-btn" title="Transpose" @click="$emit('transpose')">♭♯</button>
    <button class="stage-top-icon-btn" title="Settings" @click="$emit('settings')">⚙</button>
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
  theme:         { type: String, default: 'dark' },
});

defineEmits(['transpose', 'settings', 'toggle-theme']);

const titleMain = computed(() => {
  const words = (props.title ?? '').trim().split(/\s+/);
  return words[0] ?? '';
});

const titleSuffix = computed(() => {
  const words = (props.title ?? '').trim().split(/\s+/);
  return words.slice(1).join(' ') || null;
});

const credit = computed(() => {
  if (!props.composer) return null;
  return props.composer;
});
</script>

<style scoped>
.stage-top {
  display: flex;
  align-items: center;
  gap: 16px;
  padding-bottom: 18px;
  border-bottom: 1px solid var(--stage-line);
  margin-bottom: 28px;
}

.stage-top-title {
  font-family: var(--stage-font-chord);
  font-style: italic;
  font-size: 22px;
  font-weight: 600;
  color: var(--stage-text);
  letter-spacing: 0.2px;
  white-space: nowrap;
}

.stage-top-title strong {
  font-style: normal;
  font-weight: 700;
}

.stage-top-credit {
  font-size: 12px;
  color: var(--stage-text-mute);
  letter-spacing: 0.2px;
  white-space: nowrap;
}

.stage-top-divider {
  width: 1px;
  height: 22px;
  background: var(--stage-line-2);
  flex-shrink: 0;
}

.stage-top-meta {
  display: flex;
  align-items: center;
  gap: 10px;
}

.stage-top-chip {
  font-family: var(--stage-font-mono);
  font-size: 11px;
  font-weight: 500;
  padding: 5px 10px;
  border: 1px solid var(--stage-line-2);
  border-radius: 4px;
  color: var(--stage-text-dim);
  letter-spacing: 0.3px;
  white-space: nowrap;
}

.stage-top-chip strong {
  color: var(--stage-text);
  font-weight: 600;
}

.stage-top-spacer {
  flex: 1;
}

.stage-top-icon-btn {
  width: 32px;
  height: 32px;
  border: 1px solid var(--stage-line-2);
  background: transparent;
  color: var(--stage-text-dim);
  border-radius: 6px;
  cursor: pointer;
  display: grid;
  place-items: center;
  transition: all 0.15s ease;
  font-size: 13px;
  text-decoration: none;
  flex-shrink: 0;
}

.stage-top-icon-btn:hover {
  color: var(--stage-text);
  background: var(--stage-bg-2);
  border-color: var(--stage-line-2);
}
</style>
