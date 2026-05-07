<script setup lang="ts">
import { ref } from 'vue';
import RhythmPattern from '@/Components/Library/RhythmPattern.vue';
import type { RhythmPatternData } from '@/Components/Library/RhythmPattern.vue';

const active = ref<string | null>(null);

const demoPattern: RhythmPatternData = {
  name: 'Bossa Pulse',
  slug: 'bossa-pulse',
  meter: '4/4',
  bars: 1,
  bpm: 108,
  pattern_top: 'x...x...x...x...',
  pattern_mid: '....x.......x...',
  pattern_bass: 'x.......x.......',
  acc_top: '....X...........',
  acc_mid: '................',
  acc_bass: '................',
  perc_top: 'shaker',
  perc_mid: 'snare',
  perc_bass: 'kick',
  description: null,
  tags: [],
};

function toggle(tab: string): void {
  active.value = active.value === tab ? null : tab;
}
</script>

<template>
  <div class="sbn-bottom-bar" :class="{ 'is-open': !!active }">
    <div class="sbn-bottom-tabs">
      <button type="button" :class="{ 'is-active': active === 'chords' }" @click="toggle('chords')">?? Chords</button>
      <button type="button" :class="{ 'is-active': active === 'rhythms' }" @click="toggle('rhythms')">?? Rhythms</button>
      <button type="button" :class="{ 'is-active': active === 'songs' }" @click="toggle('songs')">?? Songs</button>
      <button type="button" :class="{ 'is-active': active === 'tools' }" @click="toggle('tools')">? Tools</button>
    </div>

    <div class="sbn-bottom-panel" v-if="active === 'chords'">
      <p>Common chords in this lesson key: Imaj7, iim7, V7, vim7.</p>
    </div>
    <div class="sbn-bottom-panel" v-else-if="active === 'rhythms'">
      <RhythmPattern :pattern="demoPattern" mini :playable="false" />
    </div>
    <div class="sbn-bottom-panel" v-else-if="active === 'songs'">
      <p>Practice songs coming soon.</p>
    </div>
    <div class="sbn-bottom-panel" v-else-if="active === 'tools'">
      <p>Metronome + tuner coming soon.</p>
    </div>
  </div>
</template>
