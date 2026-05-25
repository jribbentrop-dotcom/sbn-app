<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import RhythmStrip from '@/Components/Library/RhythmStrip.vue';
import ChordCard from '@/Components/Library/ChordCard.vue';
import ChordDiagram from '@/Components/Library/ChordDiagram.vue';
import VideoEmbed from '@/Components/Library/Video/VideoEmbed.vue';
import type { RhythmPatternWithMeta } from '@/Components/Library/RhythmPattern.vue';
import { mountSbnNodes } from '@/lib/mountSbnNodes';
import { getCategoryColor } from '@/composables/useCategoryColors';
import { getVideoPlayhead } from '@/composables/useVideoPlayhead';
import type { VideoSnippet } from '@/Components/Library/RhythmPattern.vue';

interface LessonData { slug: string; title: string; content: string | null; subsections: { title: string; slug: string }[] }
interface CourseData { slug: string; title: string; primaryGenre: string | null }
interface SelectedChord { slug: string; root: string; voicingData?: any }
interface RhythmOption { slug: string; name: string; description: string | null; pattern: RhythmPatternWithMeta }
interface ProgressionOption {
  slug: string;
  name: string;
  key: string;
  category: string;
  videoSnippet: VideoSnippet | null;
}

const props = defineProps<{
  lesson: LessonData | null;
  course: CourseData;
  activeSoundSource?: 'sheet' | null;
  selectedChord?: SelectedChord | null;
  chordSlugs?: string[];
  lessonConcepts?: { slug: string; title: string; body_html: string; has_widgets: boolean }[];
  rhythms?: RhythmOption[];
  progressions?: ProgressionOption[];
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

// ── Video snippets ────────────────────────────────────────────────────────────
// One panel-level embed slot, focus-switched across every snippet in the
// lesson — rhythm snippets ride on the pattern record, progression snippets on
// the progression entry. Each entry keeps a back-reference so focusing a
// snippet can also surface its component. The shared playhead is keyed by
// snippet id (registry): PracticePanel owns the <VideoEmbed> here; the inline
// <sbn-progression> body component reads the same instance for its highlight.
interface SnippetEntry {
  snippet: VideoSnippet;
  kind: 'rhythm' | 'progression';
  /** index into rhythmOptions / progressionOptions */
  componentIndex: number;
  label: string;
}

const progressionOptions = computed<ProgressionOption[]>(() => props.progressions ?? []);

const snippetEntries = computed<SnippetEntry[]>(() => {
  const out: SnippetEntry[] = [];
  rhythmOptions.value.forEach((r, i) => {
    if (r.pattern?.videoSnippet) {
      out.push({ snippet: r.pattern.videoSnippet, kind: 'rhythm', componentIndex: i, label: r.name });
    }
  });
  progressionOptions.value.forEach((p, i) => {
    if (p.videoSnippet) {
      out.push({ snippet: p.videoSnippet, kind: 'progression', componentIndex: i, label: p.name });
    }
  });
  return out;
});

const focusedSnippetIndex = ref(0);
const focusedEntry = computed<SnippetEntry | null>(
  () => snippetEntries.value[focusedSnippetIndex.value] ?? null,
);
const videoSnippet = computed<VideoSnippet | null>(() => focusedEntry.value?.snippet ?? null);

// Reset focus when the lesson (and thus its snippet list) changes.
watch(() => props.lesson?.slug, () => {
  focusedSnippetIndex.value = 0;
  activeRhythm.value = 0;
});

// Focusing a rhythm snippet surfaces its pattern in the Rhythm selector, so
// the panel's RhythmStrip shows the component the embed drives. Progression
// snippets need no equivalent — the synced progression lives inline in the
// lesson body (see mountSbnNodes), not in this panel.
watch(focusedEntry, (entry) => {
  if (entry?.kind === 'rhythm') activeRhythm.value = entry.componentIndex;
});

// The video clock drives the panel's RhythmStrip only when the focused snippet
// is that rhythm. Progression sync is handled by the inline body component.
const rhythmVideoActive = computed(
  () => focusedEntry.value?.kind === 'rhythm'
    && focusedEntry.value.componentIndex === activeRhythm.value,
);

// The shared playhead for the focused snippet — same registry instance the
// inline <sbn-progression> reads. Falls back to a throwaway instance when no
// snippet is focused so `ph` is always defined.
const fallbackId = '__practice_panel_idle__';
const ph = computed(() => getVideoPlayhead(videoSnippet.value?.id ?? fallbackId));

// Apply the snippet's loop window whenever the focused snippet changes.
watch(videoSnippet, (snip) => {
  if (snip?.endSec != null) {
    ph.value.setLoop({ startSec: snip.startSec, endSec: snip.endSec });
  } else {
    ph.value.setLoop(null);
  }
}, { immediate: true });

// ── Transport ⇄ video sync ────────────────────────────────────────────────────
// The transport's `playing` ref is the single source of truth. When a video
// snippet is bound, transport play/pause drives the embed, and the embed seeks
// to the snippet's startSec on each play (the loop window only handles the
// wrap, not the initial anchor). `_syncingFromVideo` guards against the two
// watchers ping-ponging.
let _syncingFromVideo = false;

watch(playing, (isPlaying) => {
  if (_syncingFromVideo || !videoSnippet.value) return;
  const phv = ph.value;
  if (isPlaying) {
    // Anchor to the snippet start before playing. If the playhead has drifted
    // outside the loop window (or sits at 0 from a fresh load), reset it.
    const snip = videoSnippet.value;
    const at = phv.playheadSec.value;
    if (at < snip.startSec || (snip.endSec != null && at >= snip.endSec)) {
      phv.seek(snip.startSec);
    }
    phv.play();
  } else {
    phv.pause();
  }
});

// Reverse: if the user drives the YouTube player directly, mirror its state
// onto the transport. The source is `ph.value.playing` — a getter-based watch
// so it re-subscribes when the focused snippet (and its playhead) changes.
watch(() => ph.value.playing.value, (videoPlaying) => {
  if (videoPlaying === playing.value) return;
  _syncingFromVideo = true;
  playing.value = videoPlaying;
  _syncingFromVideo = false;
});

// Switching focus while playing: stop the transport so the old embed pauses
// and the new one isn't left in an inconsistent state.
watch(focusedSnippetIndex, () => { playing.value = false; });

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

// ── Lesson concept expanders (one per sbn-widget in the lesson) ───────────────
const conceptBodyEls = ref<Record<string, HTMLElement | null>>({});
const conceptsMounted = ref<Record<string, boolean>>({});

function onConceptToggle(event: Event, slug: string): void {
  if (!(event.target as HTMLDetailsElement).open) return;
  if (conceptsMounted.value[slug]) return;
  conceptsMounted.value[slug] = true;
  const el = conceptBodyEls.value[slug];
  if (el) mountSbnNodes(el);
}

// Reset mount flags when the lesson changes.
watch(() => props.lesson?.slug, () => { conceptsMounted.value = {}; });
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

    <div v-if="progressionOptions.length" class="vC-card">
      <div class="vC-card-eyebrow">
        <span>Progressions in this lesson</span>
        <span class="vC-card-meta">{{ progressionOptions.length }}</span>
      </div>
      <div class="vC-prog-list">
        <a
          v-for="(p, i) in progressionOptions"
          :key="`${p.slug}-${i}`"
          :href="`/library/progressions/${p.slug}?key=${encodeURIComponent(p.key)}`"
          class="vC-prog-link"
        >
          <span class="vC-prog-name">{{ p.name }}</span>
          <span class="vC-prog-meta">
            <span class="vC-prog-key">Key of {{ p.key }}</span>
            <span class="vC-prog-style">{{ p.category }}</span>
          </span>
        </a>
      </div>
    </div>

    <div v-if="videoSnippet" class="vC-card vC-card--video">
      <div class="vC-card-eyebrow vC-card-eyebrow--inset">
        <span>Real-world example</span>
        <span class="vC-card-meta">{{ ph.playing.value ? 'playing' : 'video' }}</span>
      </div>
      <VideoEmbed
        :ref="ph.embedRef"
        :video-id="videoSnippet.videoId"
        :video-type="videoSnippet.videoType ?? 'youtube'"
        :facade="true"
        :start-sec="videoSnippet.startSec ?? 0"
        @timeupdate="ph.onTimeUpdate"
        @play-state-change="ph.onPlayStateChange"
      />
      <div v-if="snippetEntries.length > 1" class="vC-rhythm-row vC-rhythm-row--inset">
        <button
          v-for="(entry, i) in snippetEntries"
          :key="entry.snippet.id"
          type="button"
          class="vC-rhythm-pill"
          :class="{ 'is-active': focusedSnippetIndex === i }"
          @click="focusedSnippetIndex = i"
        >
          {{ entry.snippet.label || entry.label }}
        </button>
      </div>
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
        :video-playhead="rhythmVideoActive ? ph.playheadSec.value : null"
        :video-start-sec="activeRhythmPattern?.videoSnippet?.startSec ?? 0"
        :video-bpm="activeRhythmPattern?.videoSnippet?.tempoBpm"
        :video-playing="rhythmVideoActive && ph.playing.value"
        @play-request="playing = !playing"
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
      v-for="concept in (lessonConcepts ?? [])"
      :key="concept.slug"
      class="vC-concept-expander"
      @toggle="onConceptToggle($event, concept.slug)"
    >
      <summary>Learn more: {{ concept.title }}</summary>
      <div :ref="el => { conceptBodyEls[concept.slug] = el as HTMLElement | null }" v-html="concept.body_html" />
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
/* Video section — full-bleed to the sidebar edge. The shared .vC-card adds
   14px 16px padding; zero the horizontal padding so the embed touches the
   sidebar edges, then re-inset the header and snippet-pill row (text still
   needs the gutter). A bigger frame also makes YouTube's overlay a smaller
   fraction of the picture. */
.vC-card--video {
  padding-left: 0;
  padding-right: 0;
}
.vC-card-eyebrow--inset {
  padding: 0 16px 10px;
  margin-bottom: 0;
}
.vC-rhythm-row--inset {
  padding: 10px 16px 0;
}
/* The video sits flush — no rounding against the sidebar edge. VideoEmbed
   is self-styled, hence :deep. */
.vC-card--video :deep(.sbn-video-player) {
  border-radius: 0;
}

/* Progressions-in-this-lesson reference list */
.vC-prog-list {
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.vC-prog-link {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  padding: 8px 10px;
  border: 1px solid var(--clr-border);
  border-radius: var(--radius-sm);
  text-decoration: none;
  color: var(--clr-text);
  transition: background 0.15s ease, border-color 0.15s ease;
}
.vC-prog-link:hover {
  background: var(--clr-bg-hover);
  border-color: var(--clr-accent);
}
.vC-prog-name {
  font-size: 13px;
  font-weight: 600;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.vC-prog-meta {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-shrink: 0;
}
.vC-prog-key {
  font-size: 11px;
  color: var(--clr-text-dim);
  white-space: nowrap;
}
.vC-prog-style {
  font-size: 10px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--clr-text-muted);
  border: 1px solid var(--clr-border);
  border-radius: 4px;
  padding: 1px 6px;
}

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
