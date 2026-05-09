<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import RhythmStrip from '@/Components/Library/RhythmStrip.vue';
import ChordCard from '@/Components/Library/ChordCard.vue';
import type { RhythmPatternData } from '@/Components/Library/RhythmPattern.vue';

interface LessonData { slug: string; title: string; content: string | null; subsections: { title: string; slug: string }[] }
interface CourseData { slug: string; title: string; primaryGenre: string | null }
interface SelectedChord { slug: string; root: string; voicingData?: any }

const props = defineProps<{
  lesson: LessonData | null;
  course: CourseData;
  activeSoundSource?: 'sheet' | null;
  selectedChord?: SelectedChord | null;
  chordSlugs?: string[];
}>();

const emit = defineEmits<{
  selectChord: [slug: string, root: string];
  clearChord: [];
}>();

const bpm = ref(108);
const playing = ref(false);
const activeRhythm = ref(0);
const transportDisabled = computed(() => props.activeSoundSource === 'sheet');

const lessonChordData = ref<any[]>([]);
const heroChord = ref<any | null>(null);
const loadingHero = ref(false);

const rhythmOptions: Array<{ label: string; pattern: RhythmPatternData }> = [
  {
    label: 'Bossa pulse',
    pattern: {
      name: 'Bossa Pulse', beats: 8, gridType: 'sixteenth', timeSignature: '2/4', bpm: 87,
      fingers: 'x.x..x..', thumb: 'x...x...', percTop: 'tamborim', percBass: 'kick',
    },
  },
  {
    label: 'Partido alto',
    pattern: {
      name: 'Partido Alto', beats: 8, gridType: 'sixteenth', timeSignature: '2/4', bpm: 100,
      fingers: 'x.x.xx.x', thumb: 'x...x...', percTop: 'tamborim', percBass: 'kick',
    },
  },
];

function parseRootFromChordName(name: string): string {
  const m = (name || '').match(/^([A-G](?:#|b)?)/);
  return m ? m[1] : 'C';
}

async function loadLessonChords(): Promise<void> {
  const slugs = Array.from(new Set(props.chordSlugs ?? []));
  if (!slugs.length) {
    lessonChordData.value = [];
    return;
  }

  const rows = await Promise.all(slugs.map(async (slug) => {
    try {
      const res = await fetch(`/api/sbn/chords/${slug}`, { headers: { Accept: 'application/json' } });
      if (!res.ok) return null;
      return await res.json();
    } catch {
      return null;
    }
  }));

  lessonChordData.value = rows.filter(Boolean);
}

// Parse a fret string (e.g. "x32010") into the diagram_data shape ChordDiagram expects.
function fretStringToChordDiagram(chordName: string, rootNote: string, frets: string, startFret = 1): any {
  const positions: { string: number; fret: number }[] = [];
  const muted: number[] = [];
  const open: number[] = [];
  const chars = frets.toLowerCase().split('');
  chars.forEach((ch, i) => {
    const str = i + 1;
    if (ch === 'x') { muted.push(str); return; }
    const fret = parseInt(ch, 16);
    if (fret === 0) { open.push(str); }
    else { positions.push({ string: str, fret }); }
  });
  return {
    chord_name:       chordName,
    root_note:        rootNote,
    start_fret:       startFret,
    diagram_data:     { positions, barres: [], muted, open },
    quality:          '',
    quality_label:    '',
    voicing_category: '',
    category_label:   '',
    root_string:      '',
    root_string_label:'',
    inversion:        '',
    inversion_label:  '',
  };
}

async function loadHeroChord(sel: SelectedChord | null | undefined): Promise<void> {
  if (!sel) {
    heroChord.value = null;
    return;
  }

  loadingHero.value = true;
  try {
    // Use exercise voicing directly when available — exact frets, no DB lookup needed
    if (sel.voicingData?.frets) {
      heroChord.value = fretStringToChordDiagram(sel.slug, sel.root, sel.voicingData.frets, sel.voicingData.position ?? 1);
      return;
    }

    // Otherwise try direct slug lookup
    const res = await fetch(`/api/sbn/chords/${encodeURIComponent(sel.slug)}?root=${encodeURIComponent(sel.root)}`, { headers: { Accept: 'application/json' } });
    if (res.ok) {
      heroChord.value = await res.json();
    } else {
      // Slug was a chord name (from exercise click) — search by name
      const search = await fetch(`/api/sbn/chords?q=${encodeURIComponent(sel.slug)}`, { headers: { Accept: 'application/json' } });
      if (search.ok) {
        const data = await search.json();
        const first = data.results?.[0];
        if (first?.slug) {
          const res2 = await fetch(`/api/sbn/chords/${first.slug}?root=${encodeURIComponent(sel.root)}`, { headers: { Accept: 'application/json' } });
          heroChord.value = res2.ok ? await res2.json() : null;
        } else {
          heroChord.value = null;
        }
      } else {
        heroChord.value = null;
      }
    }
  } finally {
    loadingHero.value = false;
  }
}

watch(() => props.lesson?.slug, () => { void loadLessonChords(); }, { immediate: true });
watch(() => props.chordSlugs, () => { void loadLessonChords(); });
watch(() => props.selectedChord, (v) => { void loadHeroChord(v ?? null); }, { immediate: true });

function decreaseBpm(): void { bpm.value = Math.max(40, bpm.value - 2); }
function increaseBpm(): void { bpm.value = Math.min(240, bpm.value + 2); }
function setBpmPreset(val: number): void { bpm.value = val; }
</script>

<template>
  <aside class="vC-practice">
    <div class="vC-practice-head">
      <span class="vC-practice-dot" />
      Practice companion
    </div>

    <div class="vC-card">
      <div class="vC-card-eyebrow">
        <span>{{ selectedChord ? 'Selected chord' : 'Chords in this lesson' }}</span>
        <span class="vC-card-meta">{{ selectedChord ? 'hero' : lessonChordData.length }}</span>
      </div>

      <div v-if="selectedChord" style="display:flex; flex-direction:column; gap:10px;">
        <button type="button" class="sbn-btn sbn-btn-ghost sbn-btn-sm" style="align-self:flex-start;" @click="emit('clearChord')">? Back</button>
        <div v-if="loadingHero" class="sbn-text-dim">Loading chord�</div>
        <ChordCard v-else-if="heroChord" :chord="heroChord" :show-root="true" />
        <div v-else class="sbn-text-dim">Chord unavailable.</div>
      </div>

      <div v-else class="vC-chord-list">
        <button
          v-for="chord in lessonChordData"
          :key="chord.slug"
          type="button"
          class="vC-chord-row"
          @click="emit('selectChord', chord.slug, parseRootFromChordName(chord.name || chord.slug))"
        >
          <div class="vC-chord-row-text">
            <div class="vC-chord-row-name sbn-chord">{{ chord.name || chord.slug }}</div>
            <div class="vC-chord-row-sub">{{ chord.slug }}</div>
          </div>
          <span class="vC-chord-row-arrow">�</span>
        </button>
        <div v-if="!lessonChordData.length" class="sbn-text-dim">No lesson chords found.</div>
      </div>
    </div>

    <div class="vC-card">
      <div class="vC-card-eyebrow">
        <span>Rhythm</span>
        <span class="vC-card-meta">4/4 � 1 bar</span>
      </div>
      <RhythmStrip :pattern="rhythmOptions[activeRhythm].pattern" :tempo="bpm" :label="rhythmOptions[activeRhythm].label" playable />
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

    <div class="vC-transport">
      <button type="button" class="vC-play" :class="{ 'is-playing': playing }" :disabled="transportDisabled" :title="transportDisabled ? 'Playing from exercise' : undefined" @click="playing = !playing">
        <svg v-if="playing" width="22" height="22" viewBox="0 0 22 22"><rect x="6" y="5" width="4" height="12" fill="white" /><rect x="12" y="5" width="4" height="12" fill="white" /></svg>
        <svg v-else width="22" height="22" viewBox="0 0 22 22"><path d="M7 5l11 6-11 6z" fill="white" /></svg>
      </button>
      <div class="vC-bpm">
        <button type="button" class="vC-bpm-step" @click="decreaseBpm">-</button>
        <div class="vC-bpm-val"><span class="vC-bpm-num">{{ bpm }}</span><span class="vC-bpm-lbl">bpm</span></div>
        <button type="button" class="vC-bpm-step" @click="increaseBpm">+</button>
      </div>
      <div class="vC-bpm-presets">
        <button v-for="preset in [72, 100, 132]" :key="preset" type="button" class="vC-bpm-preset" :class="{ 'is-active': bpm === preset }" @click="setBpmPreset(preset)">{{ preset }}</button>
      </div>
      <div v-if="transportDisabled" class="vC-transport-note">? playing from exercise</div>
    </div>
  </aside>
</template>
