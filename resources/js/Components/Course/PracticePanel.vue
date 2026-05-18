<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import RhythmStrip from '@/Components/Library/RhythmStrip.vue';
import ChordCard from '@/Components/Library/ChordCard.vue';
import ChordDiagram from '@/Components/Library/ChordDiagram.vue';
import VideoEmbed from '@/Components/Library/Video/VideoEmbed.vue';
import type { RhythmPatternWithMeta } from '@/Components/Library/RhythmPattern.vue';
import { mountSbnNodes } from '@/lib/mountSbnNodes';
import { getCategoryColor } from '@/composables/useCategoryColors';
import { useVideoPlayhead } from '@/composables/useVideoPlayhead';

interface LessonData { slug: string; title: string; content: string | null; subsections: { title: string; slug: string }[] }
interface CourseData { slug: string; title: string; primaryGenre: string | null }
interface SelectedChord { slug: string; root: string; voicingData?: any }
interface RhythmOption { slug: string; name: string; description: string | null; pattern: RhythmPatternWithMeta }

const props = defineProps<{
  lesson: LessonData | null;
  course: CourseData;
  activeSoundSource?: 'sheet' | null;
  selectedChord?: SelectedChord | null;
  chordSlugs?: string[];
  lessonConcept?: { slug: string; title: string; body_html: string; has_widgets: boolean } | null;
  rhythms?: RhythmOption[];
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

const rhythmOptions = computed<RhythmOption[]>(() => props.rhythms ?? []);
const activeRhythmPattern = computed<RhythmPatternWithMeta | null>(
  () => rhythmOptions.value[activeRhythm.value]?.pattern ?? null,
);
const activeRhythmColor = computed<string | null>(() => {
  const cat = activeRhythmPattern.value?.category;
  return cat ? getCategoryColor(cat) : null;
});

// ── Video snippet ─────────────────────────────────────────────────────────────
// One panel-level embed slot, bound to the focused component. The rhythm is the
// default target — its real-world example video (when present) rides on the
// pattern record. The shared `useVideoPlayhead` reports YouTube time in seconds;
// RhythmStrip converts it to cells at its own edge via `videoPlayhead`.
const videoSnippet = computed(() => activeRhythmPattern.value?.videoSnippet ?? null);
const ph = useVideoPlayhead();

// Apply the snippet's loop window whenever the focused snippet changes.
watch(videoSnippet, (snip) => {
  if (snip?.endSec != null) {
    ph.setLoop({ startSec: snip.startSec, endSec: snip.endSec });
  } else {
    ph.setLoop(null);
  }
}, { immediate: true });

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

// ── Lesson concept expander ───────────────────────────────────────────────────
const conceptBodyEl = ref<HTMLElement | null>(null);
let conceptMounted = false;

function onConceptToggle(event: Event): void {
  if (!(event.target as HTMLDetailsElement).open) return;
  if (!props.lessonConcept?.has_widgets) return;
  if (conceptMounted) return;
  conceptMounted = true;
  if (conceptBodyEl.value) {
    mountSbnNodes(conceptBodyEl.value);
  }
}

// Reset mount flag when lesson changes so a new concept gets a fresh mount.
watch(() => props.lesson?.slug, () => { conceptMounted = false; });
</script>

<template>
  <aside class="vC-practice">
    <div class="vC-practice-head">
      <span class="vC-practice-dot" />
      Practice companion
    </div>

    <div v-if="selectedChord || lessonChordData.length" class="vC-card">
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
          class="vC-chord-cell"
          @click="emit('selectChord', chord.slug, parseRootFromChordName(chord.name || chord.slug))"
        >
          <div class="vC-chord-cell-name">{{ chord.name || chord.slug }}</div>
          <div v-if="chord.diagram_data" class="vC-chord-cell-diagram">
            <ChordDiagram :chord="chord" />
          </div>
        </button>
      </div>
    </div>

    <div v-if="videoSnippet" class="vC-card">
      <div class="vC-card-eyebrow">
        <span>Real-world example</span>
        <span class="vC-card-meta">{{ ph.playing.value ? 'playing' : 'video' }}</span>
      </div>
      <VideoEmbed
        :ref="ph.embedRef"
        :video-id="videoSnippet.videoId"
        :video-type="videoSnippet.videoType ?? 'youtube'"
        @timeupdate="ph.onTimeUpdate"
        @play-state-change="ph.onPlayStateChange"
      />
    </div>

    <div v-if="rhythmOptions.length" class="vC-card">
      <div class="vC-card-eyebrow">
        <span>Rhythm</span>
        <span class="vC-card-meta">{{ activeRhythmPattern?.timeSignature ?? '' }}</span>
      </div>
      <RhythmStrip
        v-if="activeRhythmPattern"
        :pattern="activeRhythmPattern"
        :tempo="bpm"
        :label="rhythmOptions[activeRhythm]?.name"
        :color="activeRhythmColor"
        :video-playhead="videoSnippet ? ph.playheadSec.value : null"
        :video-start-sec="videoSnippet?.startSec ?? 0"
        :video-bpm="videoSnippet?.tempoBpm"
        mini
        playable
      />
      <div v-if="rhythmOptions.length > 1" class="vC-rhythm-row">
        <button
          v-for="(r, i) in rhythmOptions"
          :key="r.slug"
          type="button"
          class="vC-rhythm-pill"
          :class="{ 'is-active': activeRhythm === i }"
          @click="activeRhythm = i"
        >
          {{ r.name }}
        </button>
      </div>
    </div>

    <details
      v-if="lessonConcept"
      class="vC-concept-expander"
      @toggle="onConceptToggle"
    >
      <summary>Learn more: {{ lessonConcept.title }}</summary>
      <div ref="conceptBodyEl" v-html="lessonConcept.body_html" />
    </details>

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

<style scoped>
.vC-concept-expander {
  font-size: 13px;
  border: 1px solid var(--clr-border);
  border-radius: var(--radius-sm);
  overflow: hidden;
}

.vC-concept-expander summary {
  padding: 8px 12px;
  cursor: pointer;
  font-weight: 500;
  color: var(--clr-accent);
  list-style: none;
  user-select: none;
}

.vC-concept-expander summary::-webkit-details-marker { display: none; }
.vC-concept-expander summary::before { content: '▶ '; font-size: 10px; opacity: 0.6; }
.vC-concept-expander[open] summary::before { content: '▼ '; }

.vC-concept-expander > div {
  padding: 12px;
  line-height: 1.6;
  color: var(--clr-text-dim);
  border-top: 1px solid var(--clr-border);
}

.vC-concept-expander > div p { margin: 0 0 8px 0; }
.vC-concept-expander > div p:last-child { margin-bottom: 0; }
.vC-concept-expander > div ul,
.vC-concept-expander > div ol { margin: 0 0 8px 0; padding-left: 20px; }
</style>
