<script setup>
defineProps({
  /** 'classic' | 'cinema' — which side is currently active. */
  active: { type: String, required: true },
  /** URL to the classic viewer. Omit/null on Viewer (already there). */
  classicUrl: { type: String, default: null },
  /** URL to the cinema view. Omit/null on Cinema (already there). */
  cinemaUrl: { type: String, default: null },
});
</script>

<template>
  <div class="sbn-view-toggle">
    <a v-if="active !== 'classic' && classicUrl" :href="classicUrl" class="sbn-view-toggle-link">Classic</a>
    <button v-else class="is-active" disabled>Classic</button>

    <a v-if="active !== 'cinema' && cinemaUrl" :href="cinemaUrl" class="sbn-view-toggle-link">Cinema</a>
    <button v-else disabled :class="{ 'is-active': active === 'cinema' }">Cinema</button>
  </div>
</template>

<style scoped>
/* Pinned Classic/Cinema switch — the primary "switch experience" action,
   glassy on the gradient breadcrumb band. Shared verbatim by LeadsheetViewer
   and Cinema's StageTopBar so the two never drift apart again. */
.sbn-view-toggle {
  display: inline-flex;
  border: 1px solid rgba(255, 255, 255, 0.3);
  border-radius: var(--radius-sm);
  overflow: hidden;
}

.sbn-view-toggle button,
.sbn-view-toggle-link {
  padding: 5px 12px;
  border: 0;
  background: rgba(255, 255, 255, 0.15);
  color: rgba(255, 255, 255, 0.92);
  font-size: 11px;
  font-weight: 500;
  cursor: pointer;
  transition: background 0.15s;
  text-decoration: none;
  display: inline-block;
}

.sbn-view-toggle button + button,
.sbn-view-toggle button + a,
.sbn-view-toggle a + button,
.sbn-view-toggle a + a {
  border-left: 1px solid rgba(255, 255, 255, 0.3);
}

.sbn-view-toggle button:hover:not(:disabled),
.sbn-view-toggle-link:hover {
  background: rgba(255, 255, 255, 0.25);
}

.sbn-view-toggle button:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.sbn-view-toggle button.is-active:disabled {
  background: rgba(255, 255, 255, 0.9);
  color: var(--breadcrumb-clr, var(--clr-accent-dim));
  opacity: 1;
}
</style>
