<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import RhythmStrip from '@/Components/Library/RhythmStrip.vue';
import { getCategoryColor } from '@/composables/useCategoryColors';
import type { RhythmPatternData } from '@/Components/Library/RhythmPattern.vue';

export interface RhythmLinkData {
  id: number;
  slug: string;
  name: string;
  category: string;
  styleSlug: string;
  bpm: number;
  timeSignature: string;
  playerData: RhythmPatternData;
}

const props = defineProps<{ rhythm: RhythmLinkData }>();
</script>

<template>
  <Link
    :href="`/library/rhythms/${rhythm.slug}`"
    class="sbn-rhythm-link"
    :style="{ '--rhythm-clr': getCategoryColor(rhythm.styleSlug) }"
  >
    <div class="sbn-rhythm-link__head">
      <span class="sbn-rhythm-link__name">{{ rhythm.name }}</span>
      <span class="sbn-rhythm-link__meta">{{ rhythm.timeSignature }} · {{ rhythm.bpm }} bpm</span>
    </div>
    <RhythmStrip :pattern="rhythm.playerData" :playable="true" :color="getCategoryColor(rhythm.styleSlug)" />
  </Link>
</template>

<style scoped>
.sbn-rhythm-link {
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding: 10px 14px;
  border-radius: var(--radius);
  border: 1px solid var(--clr-border);
  border-left: 3px solid var(--rhythm-clr, var(--clr-accent));
  text-decoration: none;
  background: var(--clr-white);
  transition: background 0.15s ease;
}

.sbn-rhythm-link:hover {
  background: var(--clr-surface-2);
}

.sbn-rhythm-link__head {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 8px;
}

.sbn-rhythm-link__name {
  font-size: 0.92em;
  font-weight: 600;
  color: var(--clr-text);
}

.sbn-rhythm-link__meta {
  font-size: 0.78em;
  color: var(--clr-text-muted);
  white-space: nowrap;
}
</style>
