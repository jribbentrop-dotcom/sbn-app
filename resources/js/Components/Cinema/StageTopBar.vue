<template>
  <div class="stage-top sbn-breadcrumb sbn-breadcrumb--cat sbn-breadcrumb--lg" :style="{ '--breadcrumb-clr': styleColor }">
    <template v-for="(seg, i) in segments" :key="i">
      <span v-if="i > 0" class="sbn-breadcrumb-sep" aria-hidden="true">›</span>
      <Link v-if="seg.href" :href="seg.href" class="sbn-breadcrumb-link">{{ seg.label }}</Link>
      <span v-else class="sbn-breadcrumb-current" aria-current="page">{{ seg.label }}</span>
    </template>

    <div class="sbn-breadcrumb-spacer"></div>

    <!-- Secondary switches (display mode + backing track) — same Options menu
         pattern as the classic Viewer's top bar. -->
    <TopBarMenu label="Options" :style="{ '--tbm-active-clr': styleColor }">
      <div v-if="tabHasData" class="sbn-tbm-group">
        <div class="sbn-tbm-label">Display</div>
        <div class="sbn-tbm-radio-row">
          <button
            :class="{ active: displayMode === 'chords' }"
            @click="$emit('update:displayMode', 'chords')"
          >Chords</button>
          <button
            :class="{ active: displayMode === 'tab' }"
            @click="$emit('update:displayMode', 'tab')"
          >Tab</button>
        </div>
      </div>

      <div v-if="hasBackingTrack" class="sbn-tbm-group">
        <div class="sbn-tbm-label">Backing track</div>
        <div class="sbn-tbm-radio-row">
          <button
            :class="{ active: guitarOn }"
            title="Practice along with your own guitar"
            @click="$emit('toggle-guitar')"
          >🎸 Guitar on</button>
          <button
            :class="{ active: !guitarOn }"
            title="Mute the guitar part"
            @click="$emit('toggle-guitar')"
          >Guitar off</button>
        </div>
      </div>
    </TopBarMenu>

    <!-- Classic/Cinema toggle — stays pinned; it's the primary "switch experience" action -->
    <ViewToggle active="cinema" :classic-url="classicUrl" />
  </div>
</template>

<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { getCategoryColor } from '@/composables/useCategoryColors';
import { songBreadcrumbSegments } from '@/composables/useBreadcrumb';
import TopBarMenu from '@/Components/Leadsheet/TopBarMenu.vue';
import ViewToggle from '@/Components/Leadsheet/ViewToggle.vue';

const props = defineProps({
  title:         { type: String, default: '' },
  styleSlug:     { type: String, default: '' },
  difficulty:    { type: Number, default: null },
  classicUrl:    { type: String, required: true },
  /** 'chords' | 'tab' — v-model:display-mode from the page. */
  displayMode:      { type: String, default: 'chords' },
  tabHasData:       { type: Boolean, default: false },
  hasBackingTrack:  { type: Boolean, default: false },
  guitarOn:         { type: Boolean, default: true },
});

defineEmits(['update:displayMode', 'toggle-guitar']);

const styleColor = computed(() => getCategoryColor(props.styleSlug || undefined));
const segments = computed(() => songBreadcrumbSegments(props));
</script>

<style scoped>
/* Band shape/gradient/padding all come from .sbn-breadcrumb .sbn-breadcrumb--cat
   .sbn-breadcrumb--lg (shared with the classic Viewer's Breadcrumb) — this
   just adds the Options menu panel styling; ViewToggle brings its own CSS. */

/* Options menu panel contents — mirrors LeadsheetViewer's .sbn-tbm-* rules
   (opaque popover surface, so normal DS tokens rather than glassy-on-gradient). */
.sbn-tbm-group + .sbn-tbm-group {
  border-top: 1px solid var(--clr-border);
  padding-top: 12px;
}

.sbn-tbm-label {
  font-size: 11px;
  font-weight: 600;
  color: var(--clr-text-muted);
  text-transform: uppercase;
  letter-spacing: 0.4px;
  margin-bottom: 6px;
}

.sbn-tbm-radio-row {
  display: flex;
  gap: 6px;
}

.sbn-tbm-radio-row button {
  flex: 1;
  padding: 6px 8px;
  font-size: 12px;
  font-weight: 500;
  color: var(--clr-text);
  background: var(--clr-surface-2);
  border: 1px solid var(--clr-border);
  border-radius: var(--radius-sm);
  cursor: pointer;
  transition: background 0.15s;
  white-space: nowrap;
}

.sbn-tbm-radio-row button:hover {
  background: var(--clr-surface-3);
}

.sbn-tbm-radio-row button.active {
  /* Song's own category color, not the global brand orange — matches the
     classic Viewer's Options menu (LeadsheetViewer.vue). */
  background: color-mix(in srgb, var(--tbm-active-clr, var(--clr-accent)) 12%, var(--clr-surface-2));
  color: var(--tbm-active-clr, var(--clr-accent));
  border-color: var(--tbm-active-clr, var(--clr-accent));
}
</style>
