<template>
  <div class="sbn-ve-section">
    <div class="sbn-ve-section-header" :class="{ 'is-collapsed': collapsed }">
      <!-- Collapse button - hidden in readOnly -->
      <button v-if="!props.readOnly"
              class="sbn-ve-section-collapse"
              :class="{ 'is-collapsed': collapsed }"
              @click.stop="collapsed = !collapsed"
              title="Collapse section">▼</button>
      <div v-if="section.id" class="sbn-ve-section-id">{{ section.id }}</div>
      <!-- Editable input in edit mode, span in readOnly -->
      <input v-if="!readOnly"
             class="sbn-ve-section-name"
             :value="section.name"
             placeholder="Section name…"
             @blur="renameSection(sectionIndex, $event.target.value)"
             @keydown.enter="$event.target.blur()" />
      <span v-else class="sbn-ve-section-name">{{ section.name }}</span>
      <span class="sbn-ve-section-bar-count">{{ section.measures ? section.measures.length : 0 }} bars</span>
      <label v-if="!readOnly" class="sbn-ve-section-bpr" title="Bars per row">
        <span>cols</span>
        <input
          type="number"
          min="1" max="12"
          :value="section.lineBreaks?.[0] ?? 4"
          @change="setBarsPerRow(sectionIndex, +$event.target.value)"
          @keydown.enter="$event.target.blur()"
          @click.stop
        />
      </label>
      <!-- Action buttons - hidden in readOnly -->
      <div v-if="!props.readOnly" class="sbn-ve-section-actions">
        <button class="sbn-ve-section-btn" @click="addMeasureToSection(sectionIndex)" title="Add bar">+</button>
        <button v-if="sectionCount > 1" class="sbn-ve-section-delete" @click="deleteSection(sectionIndex)" title="Remove section">×</button>
      </div>
    </div>

    <div class="sbn-ve-section-body" v-show="!collapsed">
      <div v-for="(row, ri) in rows" :key="ri" class="sbn-ve-row">
        <ChordMeasure
          v-for="measure in row"
          :key="measure.index"
          :measure="measure"
          :section-index="sectionIndex"
          :measure-index="localIndexOf(measure)"
          :read-only="readOnly"
          :density="density"
          @contextmenu="emit('contextmenu', $event)"
        />
        <!-- Row resize controls - hidden in readOnly -->
        <div v-if="!readOnly" class="sbn-ve-row-resize">
          <button class="sbn-ve-row-btn"
                  :disabled="row.length <= 1"
                  title="Move last bar to next row"
                  @click.stop="rowShrink(sectionIndex, ri)">−</button>
          <button class="sbn-ve-row-btn"
                  :disabled="ri >= rows.length - 1"
                  title="Pull next bar into this row"
                  @click.stop="rowGrow(sectionIndex, ri)">+</button>
          <button class="sbn-ve-row-btn sbn-ve-row-btn-section"
                  title="New section after this row"
                  @click.stop="rowSplit(sectionIndex, ri)">§</button>
        </div>

        <!-- Detected-progression bracket boxes (viewer only — progressionsList
             is null in the admin editor). One box per progression per
             row-segment; a progression spanning a row wrap gets one box per
             row it touches, computed in progressionBoxesByRow below. -->
        <div
          v-for="box in (progressionBoxesByRow[ri] || [])"
          :key="`${box.progId}-${box.segStart}`"
          class="sbn-ve-prog-box"
          :class="{ 'is-hovered': box.progId === hoveredProgressionId }"
          :style="{ left: box.left + '%', width: box.width + '%', '--prog-color': box.color }"
        />
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, inject, ref } from 'vue';
import ChordMeasure from './ChordMeasure.vue';
import { getCategoryColor } from '@/composables/useCategoryColors';

const props = defineProps({
  section: {
    type: Object,
    required: true,
  },
  sectionIndex: {
    type: Number,
    required: true,
  },
  readOnly: {
    type: Boolean,
    default: false,
  },
  density: {
    type: String,
    default: 'full',
    validator: (value) => ['full', 'compact'].includes(value),
  },
});

const emit = defineEmits(['contextmenu']);

const collapsed            = ref(false);
// All injects below are editor-only; provide null defaults so viewer mode doesn't warn.
const renameSection        = inject('renameSection', null);
const addMeasureToSection  = inject('addMeasureToSection', null);
const deleteSection        = inject('deleteSection', null);
const sectionCount         = inject('sectionCount', null);
const rowShrink            = inject('rowShrink', null);
const rowGrow              = inject('rowGrow', null);
const rowSplit             = inject('rowSplit', null);
const setBarsPerRow        = inject('setBarsPerRow', null);

// Detected-progression bracket boxes (viewer only — both null in the admin
// editor, so progressionBoxesByRow below is always empty there).
const progressionsList     = inject('progressionsList', null);
const hoveredProgressionId = inject('hoveredProgressionId', null);
const beatsPerMeasureRef   = inject('beatsPerMeasureRef', null);

// ── Row layout (respects lineBreaks from model) ───────────────────────────────

const DEFAULT_BARS_PER_ROW = 4;

// Density affects default bars per row - compact mode fits more measures
const barsPerRow = computed(() => {
  // Keep same structure per row regardless of density
  return DEFAULT_BARS_PER_ROW;
});

const rows = computed(() => {
  const lineBreaks = props.section.lineBreaks;
  const measures   = props.section.measures || [];

  // Always respect lineBreaks regardless of density
  if (lineBreaks?.length) {
    const out = [];
    let idx = 0;
    for (const count of lineBreaks) {
      if (idx >= measures.length) break;
      out.push(measures.slice(idx, idx + count));
      idx += count;
    }
    if (idx < measures.length) {
      out.push(measures.slice(idx));
    }
    return out;
  }

  // Fallback: uniform rows
  const out = [];
  const currentBarsPerRow = barsPerRow.value;
  for (let i = 0; i < measures.length; i += currentBarsPerRow) {
    out.push(measures.slice(i, i + currentBarsPerRow));
  }
  return out;
});

// ── Detected-progression bracket boxes ────────────────────────────────────────
// One box per progression per row-segment, positioned as a row-relative
// percentage rect so it draws as a single continuous outline around the
// bars it spans — replacing the old per-chord tint. A progression whose bars
// wrap across a row break gets one box per row it touches (each row is a
// separate absolutely-positioned box; a box can't visually span a wrap).
//
// Math mirrors ChordMeasure's own chordPositionStyle(): within a measure,
// chord ci's left/width are offset/bpm and dur/bpm (evenly divided when the
// model has no explicit chordOffsets/chordBeats). Measures within a row are
// equal-width flex children (`.sbn-ve-measure { flex: 1 1 0 }`), so measure
// mi's left/width within the row are mi/N and 1/N.
function chordFrac(measure, ci) {
  const total = measure.chordNames?.length || measure.chords?.length || 1;
  const bpm   = measure.pickupBeats ?? (beatsPerMeasureRef?.value ?? 4);
  const offset = measure.chordOffsets?.[ci] ?? (ci * (bpm / total));
  const dur    = measure.chordBeats?.[ci]   ?? (bpm / total);
  return { start: offset / bpm, end: (offset + dur) / bpm };
}

const progressionBoxesByRow = computed(() => {
  const result = {}; // ri → box[]
  if (!progressionsList?.value?.length) return result;

  rows.value.forEach((row, ri) => {
    const N = row.length;
    if (!N) return;
    const rowGiSet = new Map(row.map((m, mi) => [m.index, mi]));

    for (const prog of progressionsList.value) {
      const color = getCategoryColor(prog.category);

      for (const range of prog.ranges || []) {
        if (String(range.sectionId) !== String(props.section.id)) continue;

        const startMeasure  = range.startMeasure ?? 0;
        const endMeasure    = startMeasure + (range.length ?? 1) - 1;
        const startChord    = range.startChord    ?? 0;
        const endChord      = range.endChord      ?? 999;
        const endChordStart = range.endChordStart ?? 0;

        // Section-relative measure index → this section's flat measures array,
        // so we can resolve each measure's global index (rowGiSet is keyed by gi).
        let segStart = null;
        let segEndLeft = null; // right edge (as a 0..1 fraction of the row) of the running segment

        for (let mi = startMeasure; mi <= endMeasure; mi++) {
          const measure = props.section.measures[mi];
          if (!measure) continue;
          const rowMi = rowGiSet.get(measure.index);
          if (rowMi === undefined) {
            // This bar isn't in the current row — flush any open segment.
            if (segStart !== null) {
              pushBox(result, ri, prog, color, segStart, segEndLeft);
              segStart = null;
            }
            continue;
          }

          // Same per-measure chord-slot resolution as LeadsheetViewer's
          // progressionHighlights computed (kept in sync deliberately).
          const totalChords = measure.chordNames?.length ?? measure.chords?.length ?? 1;
          const ciStart = (mi === startMeasure) ? startChord
                        : (mi === endMeasure)   ? endChordStart
                        : 0;
          const ciEnd = (mi === endMeasure)
            ? Math.min(endChord, Math.max(0, totalChords - 1))
            : Math.max(0, totalChords - 1);

          const leftFrac  = (rowMi + chordFrac(measure, ciStart).start) / N;
          const rightFrac = (rowMi + chordFrac(measure, ciEnd).end) / N;

          if (segStart === null) segStart = leftFrac;
          segEndLeft = rightFrac;
        }

        if (segStart !== null) {
          pushBox(result, ri, prog, color, segStart, segEndLeft);
        }
      }
    }
  });

  return result;
});

function pushBox(result, ri, prog, color, leftFrac, rightFrac) {
  if (!result[ri]) result[ri] = [];
  result[ri].push({
    progId: prog.id,
    segStart: leftFrac,
    left: leftFrac * 100,
    width: Math.max(0, (rightFrac - leftFrac) * 100),
    color,
  });
}

function localIndexOf(measure) {
  return props.section.measures.indexOf(measure);
}
</script>
