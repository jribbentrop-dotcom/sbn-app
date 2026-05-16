<script setup lang="ts">
/**
 * triad-builder — the first edu widget. Pick a root and a triad quality,
 * see the three notes stack in thirds with their interval labels.
 *
 * Self-contained: pure interval math, no tab-editor or store dependency.
 * This is the widget the Edu Content System plan §4.3 designates to
 * validate the whole <sbn-widget> pipeline end to end.
 */
import { computed, ref } from 'vue';

// Twelve pitch classes, sharp spelling — enough for a teaching illustration.
const CHROMATIC = ['C', 'C♯', 'D', 'D♯', 'E', 'F', 'F♯', 'G', 'G♯', 'A', 'A♯', 'B'] as const;

// Triad qualities → semitone offsets of [third, fifth] from the root.
const QUALITIES = {
  major:      { label: 'Major',      thirdLabel: 'Major 3rd',     fifthLabel: 'Perfect 5th',     offsets: [4, 7] },
  minor:      { label: 'Minor',      thirdLabel: 'Minor 3rd',     fifthLabel: 'Perfect 5th',     offsets: [3, 7] },
  diminished: { label: 'Diminished', thirdLabel: 'Minor 3rd',     fifthLabel: 'Diminished 5th',  offsets: [3, 6] },
  augmented:  { label: 'Augmented',  thirdLabel: 'Major 3rd',     fifthLabel: 'Augmented 5th',   offsets: [4, 8] },
} as const;

type QualityKey = keyof typeof QUALITIES;

const rootIndex = ref(0);                 // index into CHROMATIC; default C
const quality = ref<QualityKey>('major');

const root = computed(() => CHROMATIC[rootIndex.value]);

const tones = computed(() => {
  const q = QUALITIES[quality.value];
  const [thirdOff, fifthOff] = q.offsets;
  return [
    { degree: 'Fifth', label: q.fifthLabel, note: CHROMATIC[(rootIndex.value + fifthOff) % 12] },
    { degree: 'Third', label: q.thirdLabel, note: CHROMATIC[(rootIndex.value + thirdOff) % 12] },
    { degree: 'Root',  label: 'Root',       note: root.value },
  ];
});

const chordName = computed(() => {
  const suffix = { major: '', minor: 'm', diminished: '°', augmented: '+' }[quality.value];
  return `${root.value}${suffix}`;
});
</script>

<template>
  <div class="sbn-edu-widget triad-builder">
    <div class="tb-controls">
      <label class="tb-field">
        <span class="tb-field-label">Root</span>
        <select v-model.number="rootIndex" class="tb-select">
          <option v-for="(name, i) in CHROMATIC" :key="i" :value="i">{{ name }}</option>
        </select>
      </label>
      <label class="tb-field">
        <span class="tb-field-label">Quality</span>
        <select v-model="quality" class="tb-select">
          <option v-for="(q, key) in QUALITIES" :key="key" :value="key">{{ q.label }}</option>
        </select>
      </label>
    </div>

    <div class="tb-result">
      <div class="tb-chord-name">{{ chordName }}</div>
      <ul class="tb-stack">
        <li v-for="tone in tones" :key="tone.degree" class="tb-tone">
          <span class="tb-tone-note">{{ tone.note }}</span>
          <span class="tb-tone-degree">{{ tone.degree }}</span>
          <span class="tb-tone-interval">{{ tone.label }}</span>
        </li>
      </ul>
    </div>
  </div>
</template>

<style scoped>
.triad-builder {
  display: flex;
  flex-direction: column;
  gap: 16px;
  padding: 16px;
  background: var(--clr-surface-2, #f5f5f7);
  border: 1px solid var(--clr-border, #ddd);
  border-radius: var(--radius, 8px);
}

.tb-controls {
  display: flex;
  gap: 16px;
  flex-wrap: wrap;
}

.tb-field {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.tb-field-label {
  font-size: 12px;
  font-weight: 600;
  color: var(--clr-text-muted, #888);
  text-transform: uppercase;
  letter-spacing: 0.5px;
}

.tb-select {
  padding: 6px 10px;
  font-size: 14px;
  border: 1px solid var(--clr-border, #ccc);
  border-radius: var(--radius-sm, 4px);
  background: var(--clr-surface, #fff);
  color: var(--clr-text, #222);
}

.tb-result {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.tb-chord-name {
  font-size: 22px;
  font-weight: 700;
  color: var(--clr-text, #222);
}

.tb-stack {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.tb-tone {
  display: grid;
  grid-template-columns: 48px 64px 1fr;
  align-items: center;
  gap: 8px;
  padding: 8px 12px;
  background: var(--clr-surface, #fff);
  border: 1px solid var(--clr-border, #e0e0e0);
  border-radius: var(--radius-sm, 4px);
}

.tb-tone-note {
  font-size: 18px;
  font-weight: 700;
  color: var(--clr-accent, #5b6cff);
}

.tb-tone-degree {
  font-size: 13px;
  font-weight: 600;
  color: var(--clr-text, #333);
}

.tb-tone-interval {
  font-size: 12px;
  color: var(--clr-text-muted, #888);
}
</style>
