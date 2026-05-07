<script setup lang="ts">
import { ref, computed, onMounted, watch, nextTick } from 'vue';
import RhythmStrip from '@/Components/Library/RhythmStrip.vue';
import type { RhythmPatternData } from '@/Components/Library/RhythmPattern.vue';

interface LessonData { slug: string; title: string; content: string | null; subsections: { title: string; slug: string }[] }
interface CourseData { slug: string; title: string; primaryGenre: string | null }

const props = defineProps<{
  lesson: LessonData | null;
  course: CourseData;
}>();

const bpm = ref(108);
const playing = ref(false);
const activeRhythm = ref(0);
const diagramContainer = ref<HTMLElement | null>(null);

// Demo chord set — Phase 12 will source from lesson metadata.
const chords = computed(() => [
  { name: 'Dm7',   frets: 'x57565', baseFret: 5 },
  { name: 'G7',    frets: '3x343x', baseFret: 1 },
  { name: 'Cmaj7', frets: 'x32000', baseFret: 1 },
  { name: 'Am7',   frets: 'x02010', baseFret: 1 },
]);

const rhythmOptions: Array<{ label: string; pattern: RhythmPatternData }> = [
  {
    label: 'Bossa pulse',
    pattern: {
      name: 'Bossa Pulse',
      beats: 8,
      gridType: 'sixteenth',
      timeSignature: '2/4',
      bpm: 87,
      fingers: 'x.x..x..',
      thumb:   'x...x...',
      percTop: 'tamborim',
      percBass: 'kick',
    },
  },
  {
    label: 'Partido alto',
    pattern: {
      name: 'Partido Alto',
      beats: 8,
      gridType: 'sixteenth',
      timeSignature: '2/4',
      bpm: 100,
      fingers: 'x.x.xx.x',
      thumb:   'x...x...',
      percTop: 'tamborim',
      percBass: 'kick',
    },
  },
];

function hydrateDiagrams(): void {
  if (!diagramContainer.value) return;
  const win = window as any;
  if (typeof win.sbnRenderMiniDiagramSVG !== 'function') return;
  const slots = diagramContainer.value.querySelectorAll<HTMLElement>('[data-diagram-slot]');
  slots.forEach((slot) => {
    const frets = slot.dataset.frets ?? '';
    const barre = parseInt(slot.dataset.barre ?? '1') || 1;
    slot.innerHTML = win.sbnRenderMiniDiagramSVG({ fretString: frets, position: barre });
  });
}

onMounted(async () => { await nextTick(); hydrateDiagrams(); });
watch(() => props.lesson?.slug, async () => { await nextTick(); hydrateDiagrams(); });

function decreaseBpm(): void { bpm.value = Math.max(40, bpm.value - 2); }
function increaseBpm(): void { bpm.value = Math.min(240, bpm.value + 2); }
function setBpmPreset(val: number): void { bpm.value = val; }

function fretNote(frets: string, baseFret: number): string {
  return `${baseFret > 1 ? baseFret + 'fr' : 'open'} · ${frets.replace(/x/g, '×')}`;
}
</script>

<template>
  <aside class="vC-practice" ref="diagramContainer">
    <div class="vC-practice-head">
      <span class="vC-practice-dot" />
      Practice companion
    </div>

    <!-- Chord list (B-style rows with mini diagram + name) -->
    <div class="vC-card">
      <div class="vC-card-eyebrow">
        <span>Chords in this lesson</span>
        <span class="vC-card-meta">{{ chords.length }}</span>
      </div>
      <div class="vC-chord-list">
        <button
          v-for="chord in chords"
          :key="chord.name"
          type="button"
          class="vC-chord-row"
        >
          <div
            class="vC-chord-row-diagram"
            data-diagram-slot
            :data-frets="chord.frets"
            :data-barre="chord.baseFret"
          />
          <div class="vC-chord-row-text">
            <div class="vC-chord-row-name sbn-chord">{{ chord.name }}</div>
            <div class="vC-chord-row-sub">{{ fretNote(chord.frets, chord.baseFret) }}</div>
          </div>
          <span class="vC-chord-row-arrow">›</span>
        </button>
      </div>
      <button type="button" class="sbn-btn sbn-btn-ghost sbn-btn-sm vC-card-cta">See all chords →</button>
    </div>

    <!-- Rhythm card -->
    <div class="vC-card">
      <div class="vC-card-eyebrow">
        <span>Rhythm</span>
        <span class="vC-card-meta">4/4 · 1 bar</span>
      </div>
      <RhythmStrip
        :pattern="rhythmOptions[activeRhythm].pattern"
        :tempo="bpm"
        :label="rhythmOptions[activeRhythm].label"
        playable
      />
      <div class="vC-rhythm-row">
        <button
          v-for="(r, i) in rhythmOptions"
          :key="r.label"
          type="button"
          class="vC-rhythm-pill"
          :class="{ 'is-active': activeRhythm === i }"
          @click="activeRhythm = i"
        >
          {{ r.label }}
        </button>
      </div>
    </div>

    <!-- Transport -->
    <div class="vC-transport">
      <button
        type="button"
        class="vC-play"
        :class="{ 'is-playing': playing }"
        @click="playing = !playing"
      >
        <svg v-if="playing" width="22" height="22" viewBox="0 0 22 22">
          <rect x="6" y="5" width="4" height="12" fill="white" />
          <rect x="12" y="5" width="4" height="12" fill="white" />
        </svg>
        <svg v-else width="22" height="22" viewBox="0 0 22 22">
          <path d="M7 5l11 6-11 6z" fill="white" />
        </svg>
      </button>
      <div class="vC-bpm">
        <button type="button" class="vC-bpm-step" @click="decreaseBpm">−</button>
        <div class="vC-bpm-val">
          <span class="vC-bpm-num">{{ bpm }}</span>
          <span class="vC-bpm-lbl">bpm</span>
        </div>
        <button type="button" class="vC-bpm-step" @click="increaseBpm">+</button>
      </div>
      <div class="vC-bpm-presets">
        <button
          v-for="preset in [72, 100, 132]"
          :key="preset"
          type="button"
          class="vC-bpm-preset"
          :class="{ 'is-active': bpm === preset }"
          @click="setBpmPreset(preset)"
        >
          {{ preset }}
        </button>
      </div>
    </div>
  </aside>
</template>
