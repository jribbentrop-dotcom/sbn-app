<template>
  <!--
    Teleport into the slot only when the Phase B flag is active.
    The slot (#sbn-vp-slot) is a plain div with no Alpine directives — Vue
    owns its content completely. Alpine's .sbn-vp-context is a sibling and
    remains untouched until Step 6 cleanup.
  -->
  <Teleport to="#sbn-vp-slot">
    <!--
      Outer .sbn-vp-context mirrors Alpine's visibility rule:
        - always visible in chords view
        - visible in tab view only when the picker is actively open
    -->
    <div
      class="sbn-vp-context"
      v-show="viewMode === 'chords' || (viewMode === 'tab' && picker.open)"
    >

      <!-- ── Active picker state ──────────────────────────────────────── -->
      <div class="sbn-vp-picker-wrap" v-show="picker.open">

        <div class="sbn-vp-header">
          <div>
            <div class="sbn-vp-subtitle">Select Voicing</div>
            <div class="sbn-vp-chord-name" v-html="formatChordHtml(picker.chordName)"></div>
          </div>
          <button class="sbn-vp-close" @click="picker.close()">×</button>
        </div>

        <div class="sbn-vp-filters">

          <!-- Voicing category pills -->
          <div class="sbn-vp-filter-row">
            <button
              v-for="cat in picker.filters.voicing_categories"
              :key="cat"
              class="sbn-vp-pill"
              :class="{ active: picker.activeFilters.voicing_category === cat }"
              @click="picker.togglePickerFilter('voicing_category', cat)"
            >{{ cat }}</button>
          </div>

          <!-- Root string pills -->
          <div class="sbn-vp-filter-row">
            <button
              v-for="rs in picker.filters.root_strings"
              :key="rs"
              class="sbn-vp-pill"
              :class="{ active: picker.activeFilters.root_string === rs }"
              @click="picker.togglePickerFilter('root_string', rs)"
            >{{ rs }}</button>
          </div>

          <!-- Extension + Inversion steppers -->
          <div class="sbn-vp-filter-row sbn-vp-steppers">
            <div class="sbn-vp-stepper" :class="{ 'has-value': picker.activeFilters.extension }">
              <span class="sbn-vp-stepper-label">Ext</span>
              <button class="sbn-vp-step-btn" @click="picker.stepExtension(-1)">&larr;</button>
              <span
                class="sbn-vp-step-value"
                :class="{ active: picker.activeFilters.extension }"
                @click="picker.clearExtension()"
              >{{ picker.activeFilters.extension || '—' }}</span>
              <button class="sbn-vp-step-btn" @click="picker.stepExtension(1)">&rarr;</button>
            </div>
            <div class="sbn-vp-stepper" :class="{ 'has-value': picker.activeFilters.inversion !== 'all' }">
              <span class="sbn-vp-stepper-label">Inv</span>
              <button class="sbn-vp-step-btn" @click="picker.stepInversion(-1)">&larr;</button>
              <span
                class="sbn-vp-step-value"
                :class="{ active: picker.activeFilters.inversion !== 'all' }"
              >{{ picker.getInversionLabel() }}</span>
              <button class="sbn-vp-step-btn" @click="picker.stepInversion(1)">&rarr;</button>
            </div>
          </div>

          <!-- Reset filters row -->
          <div class="sbn-vp-filter-row sbn-vp-reset-row" v-show="picker.hasActiveFilters()">
            <button class="sbn-vp-reset" @click="picker.resetPickerFilters()">Reset filters</button>
          </div>

        </div>

        <div class="sbn-vp-body">

          <div class="sbn-vp-loading-overlay" v-show="picker.loading">
            <span>Searching…</span>
          </div>

          <div v-show="!picker.loading && !picker.results.length" class="sbn-vp-empty">
            <div style="font-size:20px;margin-bottom:6px;opacity:0.4">📭</div>
            No voicings found.
            <div class="sbn-vp-empty-hint">Try adjusting the filters.</div>
          </div>

          <div
            class="sbn-vp-grid"
            :style="picker.loading ? 'opacity:0.3;pointer-events:none' : 'opacity:1'"
            v-show="picker.results.length"
          >
            <!-- "Current (from tab)" card — only when opened from tab AND no match in results -->
            <div
              v-show="picker._tabSource && picker._tabMatchIndex === -1 && picker._tabSource.currentFrets"
              class="sbn-vp-card sbn-vp-card--from-tab"
            >
              <div
                class="sbn-vp-card-name"
                v-html="formatChordHtml(picker._tabSource ? picker._tabSource.chordName : '')"
              ></div>
              <span v-html="picker._tabSource
                ? renderDiagramSVG({ frets: picker._tabSource.currentFrets, position: picker._tabSource.currentPosition || 1 })
                : ''"
              ></span>
            </div>

            <!-- Grouped result cards -->
            <template v-for="(item, gi) in groupedResults" :key="gi">

              <!-- Primary voicing card (normal) -->
              <div
                v-if="item.type === 'primary'"
                class="sbn-vp-card"
                :class="{
                  'is-selected':          picker.isVoicingSelected(item.v),
                  'sbn-vp-card--current': picker._tabSource && picker.results.indexOf(item.v) === picker._tabMatchIndex,
                }"
                @click="picker.applyVoicing(item.v)"
              >
                <div
                  class="sbn-vp-card-name"
                  v-html="formatChordHtml(item.v.dim_name || picker.pickerDisplayName())"
                ></div>
                <span v-html="renderDiagramSVG({ frets: item.v.frets, position: item.v.position })"></span>
              </div>

              <!-- Alias group: face card + badge. Spans one grid cell. -->
              <!-- The popover (when open) spans all 3 columns via grid-column. -->
              <div
                v-else-if="item.type === 'alias-group'"
                class="sbn-vp-card sbn-vp-card--alias"
                :class="{ 'is-selected': picker.isVoicingSelected(item.face) }"
                @click="picker.applyVoicing(item.face)"
              >
                <div class="sbn-vp-card-name" v-html="formatChordHtml(item.face.dim_name || item.face.name || picker.pickerDisplayName())"></div>
                <span v-html="renderDiagramSVG({ frets: item.face.frets, position: item.face.position })"></span>
                <button
                  class="sbn-vp-alias-badge"
                  :title="`${item.alts.length} alternate reading${item.alts.length !== 1 ? 's' : ''}`"
                  @click.stop="toggleAliasPopover(item.id, $event)"
                >≡ +{{ item.alts.length }}</button>
              </div>

              <!-- Popover: full-width row, inserted into grid flow after face card -->
              <div
                v-if="item.type === 'alias-group' && openAliasPopover === item.id"
                class="sbn-vp-alias-popover"
                @click.stop
              >
                <div class="sbn-vp-alias-popover-title">Alternate readings</div>
                <div class="sbn-vp-alias-popover-cards">
                  <div
                    v-for="(alt, ai) in item.alts"
                    :key="ai"
                    class="sbn-vp-alias-popover-card"
                    :class="{ 'is-selected': picker.isVoicingSelected(alt) }"
                    @click="picker.applyVoicing(alt); closePopovers()"
                  >
                    <span class="sbn-vp-alias-popover-name" v-html="formatChordHtml(alt.dim_name || alt.name)"></span>
                    <span v-html="renderDiagramSVG({ frets: alt.frets, position: alt.position })"></span>
                  </div>
                </div>
              </div>

            </template>
          </div>

        </div>

        <div class="sbn-vp-footer">
          <button
            class="sbn-btn sbn-btn-xs sbn-btn-danger"
            v-show="picker.hasExisting"
            @click="picker.removeVoicing()"
          >Remove voicing</button>
          <span class="sbn-vp-count">
            {{ picker.results.length }} voicing{{ picker.results.length !== 1 ? 's' : '' }}
            <span v-if="groupedResults.length < picker.results.length"> ({{ groupedResults.length }} shown)</span>
          </span>
        </div>

      </div>
      <!-- /picker-wrap -->

      <!-- ── Overview (resting) state ─────────────────────────────────── -->
      <VoicingOverview v-show="viewMode === 'chords' && !picker.open" />

    </div>
    <!-- /sbn-vp-context -->
  </Teleport>
</template>

<script setup>

import { inject, computed, ref, onMounted, onUnmounted } from 'vue';
import { formatChordHtml, renderDiagramSVG } from '../utils/chordFormat.js';
import VoicingOverview from './VoicingOverview.vue';

const picker   = inject('voicingPicker');
const viewMode = inject('viewMode');

// ── Alias grouping ────────────────────────────────────────────────────────────
// Primary results (not alias_match) render as normal cards.
// Alias results are grouped by `id` (same physical shape) and collapsed into
// a single face card with a badge showing the count of alternate readings.
// Clicking the badge toggles an inline popover listing those readings.

const openAliasPopover = ref(null); // id of the alias group whose popover is open

function toggleAliasPopover(id, event) {
    event.stopPropagation();
    openAliasPopover.value = openAliasPopover.value === id ? null : id;
}

function closePopovers() {
    openAliasPopover.value = null;
}

// Split results into primary and alias groups, then merge for rendering.
//
// Two groupable result types:
//   dim_inversion:true  — the 4 inversions of a dim7 shape (same physical shape,
//                         different root names). Group by id; face = root inversion.
//   alias_match:true    — dom7(b9) rootless or m6/m7b5 alias readings. Group by id;
//                         face = first item, alts = remaining items (face excluded).
//
// The face card is NEVER included in alts[]; the popover shows only the others.
const groupedResults = computed(() => {
    const primaries  = [];
    const dimMap     = new Map(); // id → { type, id, face, alts[] }
    const aliasMap   = new Map(); // id → { type, id, face, alts[] }

    // Pass 1: collect dim_inversion results into dimMap.
    // Pass 2: fold alias_match results — into dimMap if that shape is already
    // there (same physical shape, just a different reading), otherwise aliasMap.
    // Two passes ensure ordering in the API response doesn't affect the outcome.
    for (const v of picker.results) {
        if (!v.dim_inversion) continue;
        const key = v.id ?? v.frets;
        if (!dimMap.has(key)) {
            dimMap.set(key, { type: 'alias-group', id: key, face: null, alts: [] });
        }
        const g = dimMap.get(key);
        if (!g.face || v.inversion === 'root') {
            if (g.face) g.alts.push(g.face);
            g.face = v;
        } else {
            g.alts.push(v);
        }
    }

    for (const v of picker.results) {
        const key = v.id ?? v.frets;

        if (v.dim_inversion) continue; // already handled

        if (v.alias_match) {
            if (dimMap.has(key)) {
                // Same physical shape already shown as a dim group — add as alternate reading
                dimMap.get(key).alts.push(v);
            } else if (!aliasMap.has(key)) {
                aliasMap.set(key, { type: 'alias-group', id: `alias-${key}`, face: v, alts: [] });
            } else {
                aliasMap.get(key).alts.push(v);
            }
            continue;
        }

        primaries.push({ type: 'primary', v });
    }

    const dimGroups   = [...dimMap.values()].filter(g => g.face);
    const aliasGroups = [...aliasMap.values()].filter(g => g.alts.length > 0);

    return [...primaries, ...dimGroups, ...aliasGroups];
});

// ── Voicing hint handler ──────────────────────────────────────────────────────

function handleVoicingHint(e) {
    const { chord, frets, position } = e.detail;
    if (typeof picker.applyVoicingWithFrets === 'function') {
        picker.applyVoicingWithFrets(chord, frets, position);
    }
}

onMounted(() => {
    document.addEventListener('sbn-voicing-hint-applied', handleVoicingHint);
    document.addEventListener('click', closePopovers);
});

onUnmounted(() => {
    document.removeEventListener('sbn-voicing-hint-applied', handleVoicingHint);
    document.removeEventListener('click', closePopovers);
});
</script>
