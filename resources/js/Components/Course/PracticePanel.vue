<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import RhythmStrip from '@/Components/Library/RhythmStrip.vue';
import ChordCard from '@/Components/Library/ChordCard.vue';
import ChordDiagram from '@/Components/Library/ChordDiagram.vue';
import VideoEmbed from '@/Components/Library/Video/VideoEmbed.vue';
import type { RhythmPatternWithMeta } from '@/Components/Library/RhythmPattern.vue';
import { mountSbnNodes } from '@/lib/mountSbnNodes';
import { getCategoryColor } from '@/composables/useCategoryColors';
import { formatChordNameHtml } from '@/composables/useChordName';
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
interface SheetVideo { slug: string; title: string; videoId: string; videoType: 'youtube' | 'hosted' }

const props = defineProps<{
  lesson: LessonData | null;
  course: CourseData;
  activeSoundSource?: 'sheet' | null;
  selectedChord?: SelectedChord | null;
  chordSlugs?: string[];
  chordTags?: { slug: string; root: string }[];
  lessonConcepts?: { slug: string; title: string; body_html: string; has_widgets: boolean }[];
  rhythms?: RhythmOption[];
  progressions?: ProgressionOption[];
  sheets?: Record<string, SheetVideo>;
  collapsed?: boolean;
}>();

const emit = defineEmits<{
  selectChord: [slug: string, root: string];
  clearChord: [];
  collapse: [];
  expand: [];
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
interface SnippetEntry {
  snippet: VideoSnippet;
  kind: 'rhythm' | 'progression' | 'sheet';
  componentIndex: number;
  label: string;
  playheadKey?: string;
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
  Object.values(props.sheets ?? {}).forEach((s, i) => {
    const fakeSnippet: VideoSnippet = {
      id: `sheet:${s.slug}`,
      label: s.title,
      videoId: s.videoId,
      videoType: s.videoType,
      startSec: 0,
      tempoBpm: 120,
    };
    out.push({ snippet: fakeSnippet, kind: 'sheet', componentIndex: i, label: s.title, playheadKey: `sheet:${s.slug}` });
  });
  return out;
});

const focusedSnippetIndex = ref(0);
const focusedEntry = computed<SnippetEntry | null>(
  () => snippetEntries.value[focusedSnippetIndex.value] ?? null,
);
const videoSnippet = computed<VideoSnippet | null>(() => focusedEntry.value?.snippet ?? null);

watch(() => props.lesson?.slug, () => {
  focusedSnippetIndex.value = 0;
  activeRhythm.value = 0;
});

watch(focusedEntry, (entry) => {
  if (entry?.kind === 'rhythm') activeRhythm.value = entry.componentIndex;
});

const rhythmVideoActive = computed(
  () => focusedEntry.value?.kind === 'rhythm'
    && focusedEntry.value.componentIndex === activeRhythm.value,
);

const fallbackId = '__practice_panel_idle__';
const ph = computed(() => getVideoPlayhead(videoSnippet.value?.id ?? fallbackId));

watch(videoSnippet, (snip) => {
  if (snip?.endSec != null) {
    ph.value.setLoop({ startSec: snip.startSec, endSec: snip.endSec });
  } else {
    ph.value.setLoop(null);
  }
}, { immediate: true });

let _syncingFromVideo = false;

watch(playing, (isPlaying) => {
  if (_syncingFromVideo || !videoSnippet.value) return;
  const phv = ph.value;
  if (isPlaying) {
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

watch(() => ph.value.playing.value, (videoPlaying) => {
  if (videoPlaying === playing.value) return;
  _syncingFromVideo = true;
  playing.value = videoPlaying;
  _syncingFromVideo = false;
});

watch(focusedSnippetIndex, () => { playing.value = false; });

function parseRootFromChordName(name: string): string {
  const m = (name || '').match(/^([A-G](?:#|b)?)/);
  return m ? m[1] : 'C';
}

async function loadLessonChords(): Promise<void> {
  const tags = props.chordTags?.length
    ? props.chordTags
    : (props.chordSlugs ?? []).map(slug => ({ slug, root: '' }));

  if (!tags.length) {
    lessonChordData.value = [];
    return;
  }

  const rows = await Promise.all(tags.map(async ({ slug, root }) => {
    try {
      const qs = root ? `?root=${encodeURIComponent(root)}` : '';
      const res = await fetch(`/api/sbn/chords/${encodeURIComponent(slug)}${qs}`, { headers: { Accept: 'application/json' } });
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

  // Don't clear heroChord while loading — keep the previous chord visible until
  // the new one is ready to avoid flicker when switching chords.
  let fetched: any = null;
  try {
    const res = await fetch(`/api/sbn/chords/${encodeURIComponent(sel.slug)}?root=${encodeURIComponent(sel.root)}`, { headers: { Accept: 'application/json' } });
    if (res.ok) {
      fetched = await res.json();
    } else {
      const search = await fetch(`/api/sbn/chords?q=${encodeURIComponent(sel.slug)}`, { headers: { Accept: 'application/json' } });
      if (search.ok) {
        const data = await search.json();
        const results: any[] = data.results ?? [];
        if (results.length) {
          const targetFrets = sel.voicingData?.frets ?? null;
          fetched = targetFrets
            ? (results.find(r => fretsFromDiagramData(r.diagram_data) === targetFrets) ?? results[0])
            : results[0];
        }
      }
    }
  } finally {
    heroChord.value = fetched;
    loadingHero.value = false;
  }
}

watch(() => props.chordTags ?? props.chordSlugs, () => { void loadLessonChords(); }, { immediate: true, deep: true });
watch(() => props.selectedChord, (v) => { void loadHeroChord(v ?? null); if (v) chordPanelOpen.value = true; }, { immediate: true });

const chordPanelOpen = ref(false);
const videoPanelOpen = ref(true);

function expandVideo() { videoPanelOpen.value = true; }
defineExpose({ expandVideo });

const heroChordUrl = computed(() => {
  if (!heroChord.value?.slug) return null;
  if (heroChord.value.alias_match) return null;
  const root = encodeURIComponent(heroChord.value.root_note ?? '');
  return `/library/chords/${heroChord.value.slug}?root=${root}`;
});

function decreaseBpm(): void { bpm.value = Math.max(40, bpm.value - 2); }
function increaseBpm(): void { bpm.value = Math.min(240, bpm.value + 2); }
function setBpmPreset(val: number): void { bpm.value = val; }

// ── Panel accordion — close siblings when one opens ──────────────────────────
const practiceEl = ref<HTMLElement | null>(null);

function onPanelToggle(event: Event): void {
  const opened = event.target as HTMLDetailsElement;
  if (!opened.open) return;
  practiceEl.value?.querySelectorAll<HTMLDetailsElement>('details.vC-panel').forEach(d => {
    if (d !== opened) d.open = false;
  });
}

// ── Lesson concept expanders ──────────────────────────────────────────────────
const conceptBodyEls = ref<Record<string, HTMLElement | null>>({});
const conceptsMounted = ref<Record<string, boolean>>({});

function onConceptToggle(event: Event, slug: string): void {
  if (!(event.target as HTMLDetailsElement).open) return;
  if (conceptsMounted.value[slug]) return;
  conceptsMounted.value[slug] = true;
  const el = conceptBodyEls.value[slug];
  if (el) mountSbnNodes(el);
}

watch(() => props.lesson?.slug, () => { conceptsMounted.value = {}; });
</script>

<template>
  <!-- ── COLLAPSED: slim rail ── -->
  <aside v-if="collapsed" class="vC-practice-rail">
    <button
      type="button"
      class="vC-practice-rail-toggle"
      title="Expand practice companion"
      @click="emit('expand')"
    >
      <svg width="14" height="14" viewBox="0 0 16 16">
        <path d="M10 4L6 8l4 4" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>
      </svg>
    </button>
    <span class="vC-practice-rail-label">Practice</span>
  </aside>

  <!-- ── EXPANDED ── -->
  <aside v-else class="vC-practice" ref="practiceEl" @toggle.capture="onPanelToggle">
    <div class="vC-practice-head">
      <span class="vC-practice-dot" />
      Practice companion
      <button
        type="button"
        class="vC-practice-collapse"
        title="Collapse practice companion"
        @click="emit('collapse')"
      >
        <svg width="14" height="14" viewBox="0 0 16 16">
          <path d="M6 4l4 4-4 4" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
      </button>
    </div>

    <!-- ── Chords ── -->
    <details
      v-if="selectedChord || lessonChordData.length"
      class="vC-panel"
      :open="chordPanelOpen"
      @toggle="chordPanelOpen = ($event.target as HTMLDetailsElement).open"
    >
      <summary class="vC-panel-summary">
        <span class="vC-panel-title">{{ selectedChord ? 'Selected chord' : 'Chords' }}</span>
        <span class="vC-panel-meta">{{ selectedChord ? 'active' : lessonChordData.length }}</span>
      </summary>
      <div class="vC-panel-body">
        <div v-if="selectedChord" style="display:flex; flex-direction:column; gap:10px;">
          <button type="button" class="sbn-btn sbn-btn-ghost sbn-btn-sm" style="align-self:flex-start;" @click="emit('clearChord')">← Back</button>
          <div v-if="loadingHero && !heroChord" class="sbn-text-dim">Loading chord…</div>
          <div class="vC-chord-hero-wrap">
            <Transition name="vC-chord-swap" mode="out-in">
              <template v-if="heroChord" :key="heroChord.slug">
                <a v-if="heroChordUrl" :href="heroChordUrl" class="vC-hero-chord-link">
                  <ChordCard :chord="heroChord" :show-root="true" :mini="true" />
                </a>
                <ChordCard v-else :chord="heroChord" :show-root="true" :mini="true" />
              </template>
              <div v-else key="unavailable" class="sbn-text-dim">Chord unavailable.</div>
            </Transition>
          </div>
        </div>

        <div v-else class="vC-chord-list">
          <button
            v-for="(chord, i) in lessonChordData"
            :key="chord.slug + i"
            type="button"
            class="vC-chord-cell"
            @click="emit('selectChord', chord.slug, chord.root_note ?? parseRootFromChordName(chord.name || chord.slug))"
          >
            <div class="vC-chord-cell-name" v-html="formatChordNameHtml(chord, true)" />
            <div v-if="chord.diagram_data" class="vC-chord-cell-diagram">
              <ChordDiagram :chord="chord" />
            </div>
          </button>
        </div>
      </div>
    </details>

    <!-- ── Progressions ── -->
    <details
      v-if="progressionOptions.length"
      class="vC-panel"
    >
      <summary class="vC-panel-summary">
        <span class="vC-panel-title">Progressions</span>
        <span class="vC-panel-meta">{{ progressionOptions.length }}</span>
      </summary>
      <div class="vC-panel-body">
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
    </details>

    <!-- ── Video ── -->
    <details
      v-if="videoSnippet"
      class="vC-panel vC-panel--video"
      :open="videoPanelOpen"
      @toggle="videoPanelOpen = ($event.target as HTMLDetailsElement).open"
    >
      <summary class="vC-panel-summary">
        <span class="vC-panel-title">Video example</span>
        <span class="vC-panel-meta">{{ ph.playing.value ? 'playing' : 'video' }}</span>
      </summary>
      <div class="vC-panel-body vC-panel-body--flush">
        <VideoEmbed
          :ref="ph.embedRef"
          :video-id="videoSnippet.videoId"
          :video-type="videoSnippet.videoType ?? 'youtube'"
          :facade="true"
          :start-sec="videoSnippet.startSec ?? 0"
          @timeupdate="ph.onTimeUpdate"
          @play-state-change="ph.onPlayStateChange"
        />
        <a
          v-if="focusedEntry?.kind === 'sheet' && focusedEntry.playheadKey"
          :href="`/library/exercises/${focusedEntry.playheadKey.replace('sheet:', '')}/cinema`"
          target="_blank"
          class="vC-video-cinema-link"
        >Open in cinema player ↗</a>
        <div v-if="snippetEntries.length > 1" class="vC-pill-row vC-pill-row--inset">
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
    </details>

    <!-- ── Rhythm ── -->
    <details
      v-if="rhythmOptions.length"
      class="vC-panel"
    >
      <summary class="vC-panel-summary">
        <span class="vC-panel-title">Rhythm</span>
        <span class="vC-panel-meta">{{ rhythmOptions.length }}</span>
      </summary>
      <div class="vC-panel-body vC-panel-body--list">
        <div
          v-for="(r, i) in rhythmOptions"
          :key="r.slug"
          class="vC-rhythm-item"
          :class="{ 'is-active': activeRhythm === i }"
          @click="activeRhythm = i"
        >
          <div class="vC-rhythm-item-head">
            <span class="vC-rhythm-item-name">{{ r.name }}</span>
            <span class="vC-rhythm-item-meta">{{ r.pattern.timeSignature }} · {{ r.pattern.bpm }} bpm</span>
          </div>
          <RhythmStrip
            :pattern="r.pattern"
            :tempo="activeRhythm === i ? bpm : r.pattern.bpm"
            :color="getCategoryColor(r.pattern.category)"
            :video-playhead="activeRhythm === i && rhythmVideoActive ? ph.playheadSec.value : null"
            :video-start-sec="r.pattern.videoSnippet?.startSec ?? 0"
            :video-bpm="r.pattern.videoSnippet?.tempoBpm"
            :video-playing="activeRhythm === i && rhythmVideoActive && ph.playing.value"
            @play-request="activeRhythm = i; playing = !playing"
            mini
            playable
          />
        </div>
      </div>
    </details>

    <!-- ── Theory concepts ── -->
    <details
      v-for="concept in (lessonConcepts ?? [])"
      :key="concept.slug"
      class="vC-panel"
      @toggle="onConceptToggle($event, concept.slug)"
    >
      <summary class="vC-panel-summary">
        <span class="vC-panel-title">{{ concept.title }}</span>
      </summary>
      <div
        class="vC-panel-body vC-panel-body--prose"
        :ref="el => { conceptBodyEls[concept.slug] = el as HTMLElement | null }"
        v-html="concept.body_html"
      />
    </details>

    <!-- ── Transport ── -->
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
/* ── Chord swap transition ── */
.vC-chord-hero-wrap {
  min-height: 250px;
  display: flex;
  align-items: flex-start;
}
.vC-chord-swap-enter-active, .vC-chord-swap-leave-active { transition: opacity 0.15s ease; }
.vC-chord-swap-enter-from, .vC-chord-swap-leave-to      { opacity: 0; }

/* ── Collapsed rail ── */
.vC-practice-rail {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 12px;
  padding: 12px 0;
  background: var(--clr-white);
  border: 1px solid var(--clr-border);
  border-radius: var(--radius);
  position: sticky;
  top: calc(var(--header-height, 96px) + 16px);
  align-self: start;
  width: 40px;
}

.vC-practice-rail-toggle {
  width: 28px; height: 28px;
  border-radius: 6px;
  border: 1px solid var(--clr-border);
  background: transparent;
  color: var(--clr-text-dim);
  cursor: pointer;
  display: flex; align-items: center; justify-content: center;
  transition: background 0.15s, color 0.15s;
}
.vC-practice-rail-toggle:hover {
  background: var(--genre-bg);
  color: var(--genre-text);
  border-color: var(--genre-border);
}

.vC-practice-rail-label {
  writing-mode: vertical-rl;
  transform: rotate(180deg);
  font-size: 10px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: var(--clr-text-dim);
}

/* ── Collapse button inside header ── */
.vC-practice-collapse {
  margin-left: auto;
  width: 24px; height: 24px;
  border-radius: 4px;
  border: 1px solid var(--clr-border);
  background: transparent;
  color: var(--clr-text-dim);
  cursor: pointer;
  display: flex; align-items: center; justify-content: center;
  transition: background 0.15s, color 0.15s;
  flex-shrink: 0;
}
.vC-practice-collapse:hover {
  background: var(--genre-bg);
  color: var(--genre-text);
  border-color: var(--genre-border);
}

/* ── Unified collapsible panel ── */
.vC-panel {
  border: 1px solid var(--clr-border);
  border-radius: var(--radius-sm);
  overflow: hidden;
}

.vC-panel-summary {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  padding: 10px 14px;
  cursor: pointer;
  list-style: none;
  user-select: none;
  background: var(--clr-surface-2, var(--clr-bg));
  transition: background 0.12s;
}

.vC-panel-summary::-webkit-details-marker { display: none; }

.vC-panel-summary:hover {
  background: var(--clr-bg-hover);
}

.vC-panel-title {
  font-size: 12px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: var(--clr-text-dim);
  display: flex;
  align-items: center;
  gap: 6px;
}

/* chevron */
.vC-panel-title::before {
  content: '';
  display: inline-block;
  width: 6px;
  height: 6px;
  border-right: 1.5px solid var(--clr-text-muted);
  border-bottom: 1.5px solid var(--clr-text-muted);
  transform: rotate(-45deg);
  transition: transform 0.15s;
  flex-shrink: 0;
  margin-top: 1px;
}

.vC-panel[open] .vC-panel-title::before {
  transform: rotate(45deg);
  margin-top: -2px;
}

.vC-panel-meta {
  font-size: 11px;
  color: var(--clr-text-muted);
  font-weight: 500;
  flex-shrink: 0;
}

.vC-panel-body {
  padding: 12px 14px;
  border-top: 1px solid var(--clr-border);
  display: flex;
  flex-direction: column;
  gap: 10px;
}

/* flush variant — video embed touches the panel edges */
.vC-panel-body--flush {
  padding: 0;
  gap: 0;
}

.vC-panel--video :deep(.sbn-video-player) {
  border-radius: 0;
}

.vC-video-cinema-link {
  display: block;
  font-size: 11px;
  color: var(--clr-accent);
  text-decoration: none;
  padding: 6px 14px;
  border-top: 1px solid var(--clr-border);
}

.vC-video-cinema-link:hover { text-decoration: underline; }

/* prose body for theory concepts */
.vC-panel-body--prose {
  font-size: 13px;
  line-height: 1.6;
  color: var(--clr-text-dim);
}

.vC-panel-body--prose p { margin: 0 0 8px; }
.vC-panel-body--prose p:last-child { margin-bottom: 0; }
.vC-panel-body--prose ul,
.vC-panel-body--prose ol { margin: 0 0 8px; padding-left: 20px; }

/* ── Rhythm item list ── */
.vC-panel-body--list {
  padding: 0;
  gap: 0;
}

.vC-rhythm-item {
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding: 10px 14px;
  border-bottom: 1px solid var(--clr-border);
  cursor: pointer;
  transition: background 0.12s;
  border-left: 3px solid transparent;
}

.vC-rhythm-item:last-child {
  border-bottom: none;
}

.vC-rhythm-item:hover {
  background: var(--clr-surface-2);
}

.vC-rhythm-item.is-active {
  border-left-color: var(--clr-accent);
  background: var(--clr-accent-bg, var(--clr-surface-2));
}

.vC-rhythm-item-head {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 8px;
}

.vC-rhythm-item-name {
  font-size: 12px;
  font-weight: 600;
  color: var(--clr-text);
}

.vC-rhythm-item-meta {
  font-size: 11px;
  color: var(--clr-text-muted);
  white-space: nowrap;
}

/* ── Pill rows (snippet switchers) ── */
.vC-pill-row {
  display: flex;
  flex-wrap: wrap;
  gap: 5px;
}

.vC-pill-row--inset {
  padding: 8px 14px;
  border-top: 1px solid var(--clr-border);
}

/* ── Progressions list ── */
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
</style>
