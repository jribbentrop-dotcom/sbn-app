<template>
  <div class="sbn-vp-overview" v-if="hasModel">
    <div class="sbn-vp-overview-header">
      <div class="sbn-vp-subtitle">Song Voicings</div>
      <span class="sbn-vp-overview-count">{{ sortedUniqueChords.length }} chords</span>
    </div>
    <div class="sbn-vp-overview-grid">
      <div
        v-for="name in sortedUniqueChords"
        :key="name"
        class="sbn-vp-overview-card"
        :class="{ 'has-voicing': !!getVoicing(name) }"
        @click="picker.openForChord(name, null, null)"
      >
        <div class="sbn-vp-card-name" v-html="formatChordHtml(name)"></div>
        <div v-if="getVoicing(name)">
          <span v-html="renderDiagramSVG(getVoicing(name))"></span>
        </div>
        <div v-else class="sbn-vp-overview-empty"><span>+</span></div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { inject, computed } from 'vue';
import { formatChordHtml, renderDiagramSVG } from '../utils/chordFormat.js';

// model is a Ref provided by TabEditor.vue via provide('model', model)
const model  = inject('model');
const picker = inject('voicingPicker');

const hasModel = computed(() => !!model?.value);

const sortedUniqueChords = computed(() => {
    const m = model?.value;
    if (!m) return [];
    const names = new Set();
    m.sections.forEach(sec =>
        (sec.measures || []).forEach(meas =>
            (meas.chordNames || []).forEach(name => { if (name) names.add(name); })
        )
    );
    return [...names].sort((a, b) => a.localeCompare(b));
});

function getVoicing(name) {
    return model?.value?.chordVoicings?.[name] ?? null;
}
</script>
