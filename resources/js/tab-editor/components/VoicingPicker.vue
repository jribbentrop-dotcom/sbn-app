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

            <!-- Voicing result cards -->
            <div
              v-for="(v, vi) in picker.results"
              :key="vi"
              class="sbn-vp-card"
              :class="{
                'is-selected':       picker.isVoicingSelected(v),
                'sbn-vp-card--current': picker._tabSource && vi === picker._tabMatchIndex,
              }"
              @click="picker.applyVoicing(v)"
            >
              <div
                class="sbn-vp-card-name"
                v-html="formatChordHtml(v.dim_name || picker.pickerDisplayName())"
              ></div>
              <span v-html="renderDiagramSVG({ frets: v.frets, position: v.position })"></span>
            </div>
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

import { inject, onMounted, onUnmounted } from 'vue';
import { formatChordHtml, renderDiagramSVG } from '../utils/chordFormat.js';
import VoicingOverview from './VoicingOverview.vue';

const picker           = inject('voicingPicker');
const viewMode         = inject('viewMode');

function handleVoicingHint(e) {
    const { chord, frets, position } = e.detail;
    // We assume the picker store has a method to apply frets directly.
    // Let's check useVoicingPickerStore.js to be sure.
    if (typeof picker.applyVoicingWithFrets === 'function') {
        picker.applyVoicingWithFrets(chord, frets, position);
    }
}

onMounted(() => {
    document.addEventListener('sbn-voicing-hint-applied', handleVoicingHint);
});

onUnmounted(() => {
    document.removeEventListener('sbn-voicing-hint-applied', handleVoicingHint);
});
</script>
