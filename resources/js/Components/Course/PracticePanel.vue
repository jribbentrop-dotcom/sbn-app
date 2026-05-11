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


function fretsFromDiagramData(dd: any): string {
  if (!dd) return '';
  const result = ['x','x','x','x','x','x'];
  (dd.open ?? []).forEach((s: number) => { if (s >= 1 && s <= 6) result[s-1] = '0'; });
  (dd.positions ?? []).forEach((p: any) => {
    if (p.string >= 1 && p.string <= 6 && p.fret > 0)
      result[p.string-1] = p.fret <= 9 ? String(p.fret) : p.fret.toString(16);
  });
  (dd.barres ?? []).forEach((b: any) => {
    const from = Math.min(b.fromString, b.toString);
    const to   = Math.max(b.fromString, b.toString);
    for (let s = from; s <= to; s++)
      if (s >= 1 && s <= 6 && result[s-1] === 'x')
        result[s-1] = b.fret <= 9 ? String(b.fret) : b.fret.toString(16);
  });
  return result.join('');
}

async function loadHeroChord(sel: SelectedChord | null | undefined): Promise<void> {
  if (!sel) {
    heroChord.value = null;
    return;
  }

  loadingHero.value = true;
  try {
    // Try direct slug lookup first (sel.slug may already be a chord library slug)
    const res = await fetch(`/api/sbn/chords/${encodeURIComponent(sel.slug)}?root=${encodeURIComponent(sel.root)}`, { headers: { Accept: 'application/json' } });
    if (res.ok) {
      heroChord.value = await res.json();
      return;
    }

    // sel.slug is a chord name (from exercise/sheet click) — search by name
    const search = await fetch(`/api/sbn/chords?q=${encodeURIComponent(sel.slug)}`, { headers: { Accept: 'application/json' } });
    if (!search.ok) { heroChord.value = null; return; }

    const data = await search.json();
    const results: any[] = data.results ?? [];
    if (!results.length) { heroChord.value = null; return; }

    // Pick the result whose frets match the stored voicing exactly; fall back to first
    const targetFrets = sel.voicingData?.frets ?? null;
    const match = targetFrets
      ? (results.find(r => fretsFromDiagramData(r.diagram_data) === targetFrets) ?? results[0])
      : results[0];

    // Search results already contain full diagram_data — use directly, no second fetch
    heroChord.value = match;
  } finally {
    loadingHero.value = false;
  }
}

watch(() => props.lesson?.slug, () => { void loadLessonChords(); }, { immediate: true });
watch(() => props.chordSlugs, () => { void loadLessonChords(); });
watch(() => props.selectedChord, (v) => { void loadHeroChord(v ?? null); }, { immediate: true });

const heroChordUrl = computed(() => {
  if (!heroChord.value?.slug) return null;
  if (heroChord.value.alias_match) return null; // alias: library page would show wrong name
  const root = encodeURIComponent(heroChord.value.root_note ?? '');
  return `/library/chords/${heroChord.value.slug}?root=${root}`;
});

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
        <span class="vC-card-meta">{{ selectedChord ? 'active' : lessonChordData.length }}</span>
      </div>

      <div v-if="selectedChord" style="display:flex; flex-direction:column; gap:10px;">
        <button type="button" class="sbn-btn sbn-btn-ghost sbn-btn-sm" style="align-self:flex-start;" @click="emit('clearChord')">← Back</button>
        <div v-if="loadingHero" class="sbn-text-dim">Loading chord…</div>
        <template v-else-if="heroChord">
          <a v-if="heroChordUrl" :href="heroChordUrl" class="vC-hero-chord-link">
            <ChordCard :chord="heroChord" :show-root="true" :mini="true" />
          </a>
          <ChordCard v-else :chord="heroChord" :show-root="true" :mini="true" />
        </template>
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
          <span class="vC-chord-row-arrow">›</span>
        </button>
        <div v-if="!lessonChordData.length" class="sbn-text-dim">No lesson chords found.</div>
      </div>
    </div>

    <div class="vC-card">
      <div class="vC-card-eyebrow">
        <span>Rhythm</span>
        <span class="vC-card-meta">4/4 · 1 bar</span>
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
      <div v-if="transportDisabled" class="vC-transport-note">· playing from exercise</div>
    </div>
  </aside>
</template>
