<template>
  <div class="sbn-leadsheet-viewer sbn-page-stage" :class="{ 'is-embedded': embedded }">
    <!-- Breadcrumb band (hidden when embedded) — lg variant matches Cinema's StageTopBar -->
    <Breadcrumb v-if="!embedded" :segments="breadcrumbSegments" :color="categoryColor" size="lg">
      <template #actions>
        <div class="sbn-leadsheet-controls">
          <!-- Secondary switches (arrangement + display mode) — collapsed so the
               bar can keep growing new switches without competing for space. -->
          <TopBarMenu label="Options" :style="{ '--tbm-active-clr': categoryColor }">
            <div v-if="hasMultipleVersions" class="sbn-tbm-group">
              <div class="sbn-tbm-label">Arrangement</div>
              <select
                class="sbn-tbm-select"
                :value="activeVersion"
                @change="switchVersion($event.target.value)"
                @click.stop
              >
                <option v-for="v in versions" :key="v.slug" :value="v.slug">
                  {{ versionOptionLabel(v) }}
                </option>
              </select>
            </div>

            <div class="sbn-tbm-group">
              <div class="sbn-tbm-label">Display</div>
              <div class="sbn-tbm-radio-row">
                <button
                  :class="{ active: mode === 'no-chords' }"
                  @click="mode = 'no-chords'"
                >Analysis</button>
                <button
                  :class="{ active: mode === 'chords' }"
                  @click="mode = 'chords'"
                >Chords</button>
                <button
                  v-if="hasTab"
                  :class="{ active: mode === 'tab' }"
                  @click="mode = 'tab'"
                >Tab</button>
              </div>
            </div>

            <div v-if="mode === 'tab' && hasChordTab" class="sbn-tbm-group">
              <div class="sbn-tbm-label">Tab layer</div>
              <div class="sbn-tbm-radio-row">
                <button
                  :class="{ active: tabLayer === 'melody' }"
                  @click="tabLayer = 'melody'"
                >Melody</button>
                <button
                  :class="{ active: tabLayer === 'chord' }"
                  @click="tabLayer = 'chord'"
                >Chords</button>
              </div>
            </div>
          </TopBarMenu>

          <!-- Cinema view toggle — stays pinned; it's the primary "switch experience" action -->
          <ViewToggle active="classic" :cinema-url="cinemaUrl" />
        </div>
      </template>
    </Breadcrumb>

    <!-- Two-column layout -->
    <div class="sbn-leadsheet-content">
      <!-- Main chord grid + transport. Hover anywhere in this column reveals
           the transport deck — the deck itself is invisible until revealed,
           so the hover target has to live on this larger container. -->
      <div
        class="sbn-leadsheet-main"
        @mouseenter="deckHovered = true"
        @mouseleave="deckHovered = false"
      >
        <!-- Tab view (Phase 9b) -->
        <div v-if="mode === 'tab' && model" class="sbn-tab-viewer">
          <div 
            v-for="(section, si) in model.sections" 
            :key="section.id || si"
            class="sbn-ve-section"
          >
            <div class="sbn-ve-section-header">
              <div v-if="section.id" class="sbn-ve-section-id">{{ section.id }}</div>
              <span class="sbn-ve-section-name">{{ section.name }}</span>
              <span class="sbn-ve-section-bar-count">{{ section.measures?.length || 0 }} bars</span>
            </div>
            <div class="sbn-ve-section-body">
              <div v-for="(row, ri) in tabMeasureRows(section)" :key="ri" class="sbn-tab-row">
                <div class="sbn-tab-measures">
                  <TabMeasure
                    v-for="(measure, li) in row"
                    :key="measure.index"
                    :measure="measure"
                    :is-first-of-section="ri === 0 && li === 0"
                    :show-clef="si === 0 && ri === 0 && li === 0"
                    :time-signature="timeSignatureRef"
                    :ticks-per-measure="model.ticksPerMeasure"
                    :next-measure="getNextTabMeasure(measure.index)"
                    :is-next-first-of-section="isNextTabMeasureFirstOfSection(measure.index)"
                    :chord-names="measure.chordNames || []"
                    :bars-per-row="row._intendedCount"
                    :flex-pct="row._pickupPct != null ? (li === 0 ? row._pickupPct : row._regularPct) : null"
                    :read-only="true"
                    :allow-chord-click="true"
                    :cursor="null"
                    :pending-digit="null"
                    :selected-events="new Set()"
                    @click="() => onTabMeasureClick(measure.index)"
                    @chord-click="onTabChordClick"
                  />
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Chord grid view (existing) -->
        <ChordGridView
          v-else-if="model"
          :sections="model.sections || []"
          :read-only="true"
          :density="density"
        />

        <!-- Transport deck (sticky, hover-reveal within main container — shared with Cinema).
             Tinted to the song's category color, same as Cinema's per-style palette. -->
        <HoverRevealDeck variant="score" :visible="deckHovered" :style="deckAccentStyle">
          <TransportDeck
            :playing="transportPlaying"
            :current-bar="currentBar"
            :current-beat="currentBeatInBar"
            :total-bars="totalBars"
            :beats-per-measure="beatsPerMeasure"
            :sections="model?.sections || []"
            :show-loop="false"
            :rate="rateMultiplier"
            @toggle="onTransportToggle"
            @prev="onPrevBar"
            @next="onNextBar"
            @seek-bar="onDeckSeekBar"
            @update:rate="onRateChange"
          />
        </HoverRevealDeck>
      </div>

      <!-- Education panel -->
      <div class="sbn-leadsheet-sidebar">
        <EduPanel
          :current-chord="currentChord"
          :current-section-id="currentSectionId"
          :selection-key="selectionKey"
          :song="songInfo"
          :progressions="progressions"
          :chord-cards="chordCards"
          :quality-by-key="qualityByKey"
          :edu-chord-qualities="eduChordQualities"
          :edu-related-concepts="eduRelatedConcepts"
          :skill-nodes="skillNodes"
          :related-theory="relatedTheory"
          :hovered-progression-id="hoveredProgressionId"
          @progression-hover="hoveredProgressionId = $event"
        />
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, provide, watch, onMounted, onUnmounted } from 'vue';
import { router } from '@inertiajs/vue3';

// Components
import ChordGridView from '@/tab-editor/components/ChordGridView.vue';
import EduPanel from './EduPanel.vue';
import TabMeasure from '@/tab-editor/components/TabMeasure.vue';
import Breadcrumb from '@/Components/Breadcrumb.vue';
import TopBarMenu from './TopBarMenu.vue';
import ViewToggle from './ViewToggle.vue';
import TransportDeck from './TransportDeck.vue';
import HoverRevealDeck from './HoverRevealDeck.vue';

// Style/category helpers (breadcrumb color + segments — shared with Songs/Show.vue + Cinema)
import { getCategoryColor } from '@/composables/useCategoryColors';
import { songBreadcrumbSegments } from '@/composables/useBreadcrumb';
import { useHoverRevealTransport } from '@/composables/useHoverRevealTransport';

// Composables
import { useTabModel } from '@/tab-editor/composables/useTabModel.js';
import { useGridSelection } from '@/tab-editor/composables/useGridSelection.js';
import { useChordAudio } from '@/tab-editor/composables/useChordAudio.js';
import { useAudioEngine } from '@/tab-editor/composables/useAudioEngine.js';

// Audio engine + adapters (required to load tab + chord events before playback)
import { getAudioEngine } from '@/audio/engine/AudioEngine.js';
import { tabModelToEvents } from '@/audio/adapters/tabMeasureToEvents.js';
import { chordVoicingsToEvents } from '@/audio/adapters/chordVoicingsToEvents.js';
import { rhythmPatternToEvents } from '@/audio/adapters/rhythmPatternToEvents.js';
import { expandModelSequence, flattenModelMeasures } from '@/audio/adapters/expandMeasureSequence.js';

const props = defineProps({
  /** Leadsheet payload from controller — see resources/js/types/leadsheet.ts */
  leadsheet: {
    type: Object,
    required: true,
  },
  /** ProgressionRef[] — see resources/js/types/leadsheet.ts */
  progressions: {
    type: Array,
    default: () => [],
  },
  /** ChordDiagramData map keyed by "chordName@gi.ci" — enriched chord card data */
  chordCards: {
    type: Object,
    default: () => ({}),
  },
  /** Quality slug map keyed by "chordName@gi.ci" — for edu blurbs (Step 6) */
  qualityByKey: {
    type: Object,
    default: () => ({}),
  },
  /** Roman-numeral map keyed by "chordName@gi.ci", relative to the song key —
   *  see EduContentService/LeadsheetViewerService::buildChordCards(). Powers
   *  the "Analysis" display mode. Null value = chord couldn't be analyzed
   *  (e.g. no key set); ChordCard falls back to the chord name in that case. */
  chordNumerals: {
    type: Object,
    default: () => ({}),
  },
  /** Edu content blurbs for chord qualities keyed by quality slug */
  eduChordQualities: {
    type: Object,
    default: () => ({}),
  },
  /** Related concept topics keyed by concept slug, for EduPanel expander */
  eduRelatedConcepts: {
    type: Object,
    default: () => ({}),
  },
  /** Skill nodes this song is tagged as teaching — see SkillNode::leadsheets() */
  skillNodes: {
    type: Array,
    default: () => [],
  },
  /** Concept topics resolved from skill nodes / progressions / genre / difficulty
   *  — see EduContentService::conceptsForLeadsheet() */
  relatedTheory: {
    type: Array,
    default: () => [],
  },
  /** When true, suppresses the standalone header band (course-lesson embed). */
  embedded: {
    type: Boolean,
    default: false,
  },
  /** URL to the cinema view for this leadsheet (Phase 10). */
  cinemaUrl: {
    type: String,
    default: null,
  },
  /** Arrangements for the version switcher (leadsheet-versions). Empty/length-1 hides it. */
  versions: {
    type: Array,
    default: () => [],
  },
  /** version_slug of the currently displayed arrangement. */
  activeVersion: {
    type: String,
    default: '',
  },
});

// ── Breadcrumb (Songs → style → difficulty → title — shared with Songs/Show.vue + Cinema) ─
const categoryColor = computed(() => getCategoryColor(props.leadsheet.styleSlug));
const breadcrumbSegments = computed(() => songBreadcrumbSegments(props.leadsheet));

// TransportDeck reads --stage-accent (falling back to --clr-accent) plus the two
// gradient vars for its play button/progress fill — set all three from the song's
// category color so the Viewer's deck is tinted the same way Cinema's is per-style.
const deckAccentStyle = computed(() => ({
  '--stage-accent': categoryColor.value,
  '--stage-gradient': `linear-gradient(120deg, ${categoryColor.value}, color-mix(in srgb, ${categoryColor.value} 72%, white))`,
  '--stage-gradient-hover': `linear-gradient(120deg, color-mix(in srgb, ${categoryColor.value} 88%, black), ${categoryColor.value})`,
}));

// ── 3-way mode state with localStorage persistence (Phase 9b) ───────────────
/**
 * Load initial mode from localStorage, migrating from old density key.
 * Mirrors Phase 9b §4: density → mode mapping during refactor.
 */
function loadInitialMode() {
  const newKey = localStorage.getItem('sbn.leadsheet.mode');
  if (newKey === 'no-chords' || newKey === 'chords' || newKey === 'tab') return newKey;

  // Migrate old density key
  const oldDensity = localStorage.getItem('sbn.leadsheet.density');
  if (oldDensity === 'compact') return 'no-chords';
  return 'chords'; // 'full' or default
}

const mode = ref(loadInitialMode());

/**
 * Mode watcher: persists to localStorage and handles audio handoff.
 * Per Phase 9b §5.3: switching modes while playing pauses, seeks both
 * paths to the same position, then resumes in the new path. Audio gap
 * is < 50 ms — imperceptible.
 *
 * Also converts selection between modes to preserve user context.
 *
 * Note: callbacks reference symbols declared further down (transportPlaying,
 * isTabPlaying, etc.). This works because the watcher only fires after
 * setup completes.
 */
watch(mode, async (newMode, oldMode) => {
  localStorage.setItem('sbn.leadsheet.mode', newMode);
  if (oldMode === newMode) return;

  // Preserve selection across mode switches
  if (oldMode === 'tab' && newMode !== 'tab') {
    // Tab → chord/no-chords: convert tabSelection to gridSelection
    if (tabSelection.value) {
      const gi = tabSelection.value.gi;
      gridSelection.handleClick(gi, 0, new MouseEvent('click'));
      tabSelection.value = null;
    }
  } else if (oldMode !== 'tab' && newMode === 'tab') {
    // Chord/no-chords → tab: convert gridSelection to tabSelection
    const sel = gridSelection.selection.value;
    if (sel.length) {
      const last = sel[sel.length - 1];
      tabSelection.value = { gi: last.gi };
      gridSelection.clearSelection();
    }
  }

  const wasPlaying = transportPlaying.value;
  const beat       = transportBeat.value;

  if (isTabPlaying.value)   pauseTab();
  if (isChordPlaying.value) pauseChord();

  seekTab(beat);
  seekChord(beat);

  if (wasPlaying) {
    if (!_eventsLoaded || _eventsLoadedForMode !== newMode) await loadAllEvents();
    if (newMode === 'tab') await playTab();
    else                   await playChord();
  }
});

/**
 * hasTab gate: Tab button only renders when leadsheet has actual tab notes
 * (string/fret data). useTabModel exposes hasData which scans melody.value
 * for notes with string/fret set — exactly the signal we want.
 */
const hasTab = computed(() => hasData.value);

// ── Tab layer (Axis B — melody vs. chord/comping TAB, within Tab mode) ──────
// hasChordTab/tabLayer declared here (used by the template); the watcher that
// actually swaps the model's melody staff lives further down, after
// melodyRef/buildModel/reloadEvents are declared (see "Tab layer switch").
const hasChordTab = computed(() => !!props.leadsheet.hasChordTab && !!props.leadsheet.chordLayerMelody);
const tabLayer = ref('melody');

// ── Arrangement (version) switcher ──────────────────────────────────────────
const hasMultipleVersions = computed(() => (props.versions?.length ?? 0) > 1);

function versionOptionLabel(v) {
  const name = v.performer || v.label || 'Basic';
  const d = v.difficulty ?? 0;
  const dots = d > 0 ? ' · ' + '●'.repeat(Math.min(d, 5)) : '';
  return name + dots;
}

function switchVersion(slug) {
  if (!slug || slug === props.activeVersion) return;
  // Reload the viewer for the chosen arrangement; server re-enriches chord cards
  // + progressions for that version. Full reload (preserveState:false) so the tab
  // model / audio engine rebuild cleanly from the new json_data.
  router.get(
    `/library/songs/${props.leadsheet.slug}/viewer`,
    { v: slug },
    { preserveScroll: true, preserveState: false },
  );
}

/**
 * Density computed: maps mode → density prop for ChordGridView.
 * 'no-chords' (the "Analysis" toggle button) keeps full chord-card spacing —
 * it swaps chord names for roman numerals (see showNumerals below) rather
 * than shrinking the grid the way the old "compact" density did.
 */
const density = computed(() => {
  if (mode.value === 'no-chords') return 'full';
  if (mode.value === 'chords') return 'full';
  return 'full'; // tab mode doesn't render ChordGridView, so density is irrelevant
});

// Analysis mode: chord cards render a giant light-grey roman numeral instead
// of the chord name/diagram. Provided to ChordCard (viewer-only — see provide()
// below); the admin tab editor never sets mode to 'no-chords' so this is a
// pure no-op there.
const showNumerals = computed(() => mode.value === 'no-chords');

// ── Transport deck hover-reveal (host is the whole score column, see template) ──
const { transportHovered: deckHovered } = useHoverRevealTransport();

// ── Keyboard shortcut ─────────────────────────────────────────────────────────
function onKeyDown(e) {
  if (e.code === 'Space' || e.key === ' ') {
    e.preventDefault(); // Prevent page scroll
    onTransportToggle();
  }
}

onMounted(() => {
  window.addEventListener('keydown', onKeyDown);
});

onUnmounted(() => {
  window.removeEventListener('keydown', onKeyDown);
});

// ── Tab model from leadsheet json_data ───────────────────────────────────────
// useTabModel expects refs as inputs (it calls .value on each). The admin
// editor wires these via useAlpineBridge; the viewer wraps the static JSON.
const json = props.leadsheet.jsonData ?? {};
const melodyRef        = ref(json.melody ?? null);
const sectionsRef      = ref(json.sections ?? []);
const timeSignatureRef = ref(json.timeSignature ?? '4/4');
const repeatMarkersRef = ref(json.repeatMarkers ?? {});
const voltaEndingsRef  = ref(json.voltaEndings ?? {});
const chordVoicingsRef = ref(json.chordVoicings ?? {});

const tabModel = useTabModel(
  melodyRef,
  sectionsRef,
  timeSignatureRef,
  repeatMarkersRef,
  voltaEndingsRef,
  chordVoicingsRef,
);
const { model, buildModel, hasData } = tabModel;

// Build the model immediately. (TabEditor relies on a watcher; we call directly
// since our refs never change after mount.)
buildModel();

// useTabModel doesn't store tempo — set it from the leadsheet so audio + UI
// pick it up. (TabEditor reads tempo from the Alpine bridge and bypasses the
// model for transport, which is why it works there.)
if (model.value) {
  model.value.tempo = props.leadsheet.tempo ?? 120;
}

// Mode fallback: if persisted mode === 'tab' but this song has no melody,
// silently demote to 'chords'. Done inline since model is now built.
if (mode.value === 'tab' && !hasTab.value) {
  mode.value = 'chords';
}

// ── Selection (read-only — drives EduPanel current-chord) ────────────────────
// Chord grid selection (used in chord/no-chords modes)
const gridSelection = useGridSelection(model);

// Tab mode selection (measure index only — chord index defaults to 0)
const tabSelection = ref(null); // { gi: number } or null

// ── Audio (two playback paths: chord + tab — Phase 9b) ───────────────────────
// Chord audio path (existing)
const chordAudio = useChordAudio(model);
const {
  isPlaying: isChordPlaying,
  currentBeat: chordCurrentBeat,
  play:   playChord,
  pause:  pauseChord,
  seek:   seekChord,
} = chordAudio;

// Tab audio path (new for Phase 9b — mirrors TabEditor.vue)
const tabAudio = useAudioEngine(model);
const {
  isPlaying: isTabPlaying,
  currentBeat: tabCurrentBeat,
  play:   playTab,
  pause:  pauseTab,
  seek:   seekTab,
} = tabAudio;

// Engine event-loading. The engine is a singleton shared with the admin
// editor and only holds one event list at a time, so only the events for the
// currently-active mode are loaded — Tab mode plays tab notes only, Chords/
// Analysis mode plays chord strums only (previously both were combined and
// played together regardless of which transport button was pressed).
const engine = getAudioEngine();
let _eventsLoaded = false;
let _eventsLoadedForMode = null;

// Steps-per-beat for each rhythm-pattern grid type — matches rhythmPatternToEvents.js's
// own GRID_STEP_BEATS table (kept local since that map isn't exported).
const RHYTHM_STEP_BEATS = { eighth: 0.5, sixteenth: 0.25, triplet: 1 / 3 };

/**
 * Loop the song's assigned rhythm pattern (percussion only — see below) to
 * cover `totalBeats`, anchored at `anchorBeat` rather than absolute beat 0.
 *
 * anchorBeat matters when the song opens with a pickup bar: the chord grid's
 * "downbeat 1" of the first full measure sits at beat `pickupBeats` (e.g. 3
 * for a quarter-note pickup in 4/4), not at beat 0. Looping the pattern from
 * 0 regardless would permanently offset every subsequent repetition by the
 * pickup's shortfall — a constant, song-wide sync drift (heard as "everything
 * is a quarter-note off"), not a cumulative one, since both streams still run
 * at the same tempo afterward.
 *
 * Picking-mode patterns are skipped: rhythmPatternToEvents() generates their
 * pitched notes from generic open-string MIDI defaults, not this song's real
 * chord voicings, so layering them under actual chord audio would sound like
 * two unrelated guitar parts playing at once. Percussion-mode patterns
 * (percTop/percBass samples) have no such collision and merge cleanly.
 */
function buildRhythmEvents(totalBeats, anchorBeat = 0) {
  const pattern = props.leadsheet.rhythmData;
  if (!pattern || pattern.pickingMode || !totalBeats) return [];

  const stepBeats = RHYTHM_STEP_BEATS[pattern.gridType] ?? 0.25;
  const patLen = Math.max(pattern.thumb?.length ?? 0, pattern.fingers?.length ?? 0) * stepBeats;
  if (!patLen) return [];

  const events = [];
  // Before the anchor (i.e. during the pickup bar itself), the pattern isn't
  // rendered — there's no well-defined "which step of the pattern" a pickup's
  // partial bar corresponds to. Playback of the rhythm layer begins at the
  // first full measure, same as where the chord grid's regular meter begins.
  let offset = anchorBeat;
  while (offset < totalBeats) {
    for (const ev of rhythmPatternToEvents(pattern, { startBeat: offset })) {
      if (ev.time >= totalBeats) break;
      events.push(ev);
    }
    offset += patLen;
  }
  return events;
}

async function loadAllEvents() {
  if (!model.value) return;
  // A non-empty samplesBaseUrl arms the shared NylonSampler singleton (its own
  // sample path is hardcoded to /audio/nylon/ inside AudioEngine.init()) — this
  // value itself is the percussion sampler's base path, matching other callers.
  await engine.init({ bpm: model.value.tempo ?? 120, samplesBaseUrl: '/audio/rhythm-samples/' });

  // Real guitar samples (nylon-string) for viewer/cinema playback — the admin
  // tab editor keeps the 'pitched' synth voice (see TabEditor.vue).
  const events = mode.value === 'tab'
    ? tabModelToEvents(model.value, { startBeat: 0, voice: 'nylon' })
    : chordVoicingsToEvents(model.value, { startBeat: 0, voice: 'nylon' });

  // Rhythm-pattern layer: Chords and Tab modes (per product decision — Analysis
  // mode plays the same chord audio today but doesn't additionally get the
  // rhythm layer). Tab's note-accurate melody/comping is a distinct voice from
  // the percussion-only rhythm layer, so the two don't compete the way a second
  // pitched guitar part would.
  let allEvents = events;
  if ((mode.value === 'chords' || mode.value === 'tab') && events.length) {
    const totalBeats = events.at(-1).time + events.at(-1).duration;
    // A pickup-bar first measure shifts "downbeat 1" away from beat 0 — see
    // buildRhythmEvents' anchorBeat doc. flattenModelMeasures gives the first
    // flat measure regardless of section nesting.
    const firstMeasure = flattenModelMeasures(model.value).flatMeasures[0];
    const anchorBeat = firstMeasure?.pickupBeats ?? 0;
    const rhythmEvents = buildRhythmEvents(totalBeats, anchorBeat);
    if (rhythmEvents.length) {
      allEvents = [...events, ...rhythmEvents].sort((a, b) => a.time - b.time);
    }
  }

  engine.load(allEvents);
  engine.setTempo(model.value.tempo ?? 120);
  _eventsLoaded = true;
  _eventsLoadedForMode = mode.value;
}

async function reloadEvents() {
  _eventsLoaded = false;
  await loadAllEvents();
}

// ── Tab layer switch (Axis B) ────────────────────────────────────────────────
// Mirrors the admin editor's melody/chord layer switch (see
// SBN-Admin-Chord-Tab-Editor-Reference.md "Tab layers"), simplified for a
// read-only viewer: the two layers share one sections/chordVoicings/repeats
// skeleton and differ only in the staff notes, so switching just swaps
// melodyRef's contents and rebuilds — no save-serialization round trip needed.
watch(tabLayer, async (layer) => {
  melodyRef.value = layer === 'chord' && hasChordTab.value
    ? props.leadsheet.chordLayerMelody
    : (json.melody ?? null);
  buildModel();
  if (model.value) model.value.tempo = props.leadsheet.tempo ?? 120;
  if (mode.value === 'tab') await reloadEvents();
});

// ── Unified transport state (mirrors TabEditor.vue:464-477) ───────────────────
// Phase 9b: unified state across both playback paths. Mode dispatch happens in
// transport handlers (Step 3 adds mode-aware dispatch).
const transportPlaying = computed(() => isTabPlaying.value || isChordPlaying.value);

const transportBeat = computed(() => {
  if (isTabPlaying.value)   return tabCurrentBeat.value;
  if (isChordPlaying.value) return chordCurrentBeat.value;
  return tabCurrentBeat.value ?? chordCurrentBeat.value ?? 0;  // parked
});

// ── Transport derived values ─────────────────────────────────────────────────
const tempo = ref(props.leadsheet.tempo ?? 120);

// Match the engine-internal definition (TICKS_PER_BEAT = 480) so seek/scrub align.
const beatsPerMeasure = computed(() => {
  const tpm = model.value?.ticksPerMeasure;
  if (tpm) return tpm / 480;
  // Fallback before model builds — derive from time signature.
  const ts = props.leadsheet.timeSignature ?? '4/4';
  const num = parseInt(String(ts).split('/')[0], 10);
  return Number.isFinite(num) && num > 0 ? num : 4;
});

const totalBeats = computed(() => {
  const sections = model.value?.sections ?? [];
  let measures = 0;
  for (const s of sections) measures += (s.measures?.length ?? 0);
  return measures * beatsPerMeasure.value;
});

// ── Transport handlers ───────────────────────────────────────────────────────
// Phase 9b: unified handlers that drive both playback paths with mode dispatch.
async function onTransportToggle() {
  if (transportPlaying.value) {
    // Pause whichever path is active
    if (isTabPlaying.value)   pauseTab();
    if (isChordPlaying.value) pauseChord();
  } else {
    if (!_eventsLoaded || _eventsLoadedForMode !== mode.value) await loadAllEvents();
    // Dispatch by mode: tab mode uses tab audio, else chord audio
    if (mode.value === 'tab') await playTab();
    else                      await playChord();
  }
}

function onTransportSeek(beat) {
  // Seek both paths (cheap; only one is active)
  seekTab(beat);
  seekChord(beat);
}

function onTempoChange(newTempo) {
  tempo.value = newTempo;
  if (model.value) model.value.tempo = newTempo;
  engine.setTempo(newTempo);
}

// ── TransportDeck adapter (shared component with Cinema) ─────────────────────
// TransportDeck works in bars, not raw beats — derive bar position from the
// existing beat-based transport state instead of teaching the engine bars.
const totalBars  = computed(() => Math.max(1, playPositionBeatTable.value.length || Math.round(totalBeats.value / (beatsPerMeasure.value || 1))));

// Shared lookup for the current transportBeat's table entry — currentBar and
// currentBeatInBar must agree on which bar/range they're describing, or
// TransportDeck's `currentBar + currentBeat/beatsPerMeasure` sweep desyncs at
// every bar boundary after a pickup (currentBar jumping to the next table
// index while currentBeat is still computed against the OLD bar's length).
const currentBarEntry = computed(() => {
  const b = transportBeat.value ?? 0;
  const table = playPositionBeatTable.value;
  return table.find(e => b >= e.beatStart && b < e.beatEnd) ?? null;
});
const currentBar = computed(() => {
  const entry = currentBarEntry.value;
  if (entry) return playPositionBeatTable.value.indexOf(entry);
  return Math.floor((transportBeat.value ?? 0) / (beatsPerMeasure.value || 1));
});
// Beats elapsed within the current bar, using THAT bar's own beat count
// (pickupBeats when it's a pickup) rather than the global time signature.
const currentBeatInBar = computed(() => {
  const entry = currentBarEntry.value;
  const b = transportBeat.value ?? 0;
  if (entry) return b - entry.beatStart;
  return b % (beatsPerMeasure.value || 1);
});

function onDeckSeekBar(bar) {
  const entry = playPositionBeatTable.value[bar];
  onTransportSeek(entry ? entry.beatStart : bar * beatsPerMeasure.value);
}

function onPrevBar() {
  seekToMeasure(Math.max(0, currentBar.value - 1));
}

function onNextBar() {
  seekToMeasure(Math.min(totalBars.value - 1, currentBar.value + 1));
}

// ±20% playback-rate multiplier — engine.setTempo takes an absolute BPM, so
// the slider's multiplier is applied against the leadsheet's base tempo.
const baseTempo = props.leadsheet.tempo ?? 120;
const rateMultiplier = ref(1);

function onRateChange(rate) {
  rateMultiplier.value = rate;
  onTempoChange(Math.round(baseTempo * rate));
}

// Click-to-seek from a chord card: seek to measure + chord offset (only auto-play if already playing)
async function seekToMeasure(gi, ci = 0) {
  let total = 1;
  let offset = null;
  if (model.value) {
    let currentMeasure = null;
    for (const section of model.value.sections || []) {
      const found = (section.measures || []).find(m => m.index === gi);
      if (found) { currentMeasure = found; break; }
    }
    if (currentMeasure) {
      if (currentMeasure.chordNames) {
        total = currentMeasure.chordNames.length || 1;
      }
      if (currentMeasure.chordOffsets && currentMeasure.chordOffsets[ci] != null) {
        offset = currentMeasure.chordOffsets[ci];
      }
    }
  }
  const bpm = beatsPerMeasure.value;
  const beatOffset = offset != null ? offset : ci * (bpm / total);
  // Use the first play-position beat for this globalIndex (repeat-aware).
  // Falls back to linear gi * bpm if the sequence hasn't been computed yet.
  const measureBeat = firstBeatForGi.value.get(gi) ?? gi * bpm;
  const beatStart = measureBeat + beatOffset;

  if (!_eventsLoaded || _eventsLoadedForMode !== mode.value) await loadAllEvents();
  // Seek both paths
  seekTab(beatStart);
  seekChord(beatStart);
  // If already playing, playback continues from new position
  // If stopped/paused, just seek without starting playback
}

// ── Expanded playback sequence (repeat + volta aware) ────────────────────────
// Maps play-position index → globalIndex of the bar that plays at that position.
// Used to derive which bar is highlighted during playback and where to seek.
const expandedSequence = computed(() => {
  if (!model.value) return [];
  return expandModelSequence(model.value);
});

// Play-position → beat-range table, accounting for pickup bars whose beat
// count differs from the global time signature. Mirrors TabEditor.vue's
// playPositionBeatTable — without this, a pickup bar (fewer beats than a
// full measure) throws every beatStart after it off by
// (beatsPerMeasure - pickupBeats), desyncing the playhead/highlight from the
// audio engine clock (which chordVoicingsToEvents.js/tabMeasureToEvents.js
// already build correctly using pickupBeats).
const playPositionBeatTable = computed(() => {
  const bpm = beatsPerMeasure.value;
  const { measureByGi } = flattenModelMeasures(model.value);
  const table = [];
  let cursor = 0;
  for (const gi of expandedSequence.value) {
    const beats = measureByGi.get(gi)?.pickupBeats ?? bpm;
    table.push({ gi, beatStart: cursor, beatEnd: cursor + beats });
    cursor += beats;
  }
  return table;
});

// Inverse map: globalIndex → first play-position beat (for seek).
// When a bar repeats, points to its FIRST occurrence so clicking it
// seeks to the beginning of the phrase containing that bar.
const firstBeatForGi = computed(() => {
  const map = new Map();
  for (const entry of playPositionBeatTable.value) {
    if (!map.has(entry.gi)) map.set(entry.gi, entry.beatStart);
  }
  return map;
});

// ── Active-measure highlight (drives the beat-tick sweep across the grid) ────
const playingMeasureIndex = ref(0);
watch(
  [transportBeat, playPositionBeatTable],
  ([beat]) => {
    const b = beat ?? 0;
    const table = playPositionBeatTable.value;
    const entry = table.find(e => b >= e.beatStart && b < e.beatEnd) ?? table[table.length - 1];
    const pos = entry ? table.indexOf(entry) : Math.floor(b / (beatsPerMeasure.value || 4));
    playingMeasureIndex.value = entry?.gi ?? expandedSequence.value[pos] ?? pos;
  },
  { immediate: true }
);


// ── Detected-progression highlight ───────────────────────────────────────────
// Which progression entry the user is hovering in the EduPanel list. null when
// none — drives the "intensified" highlight (one progression at a time).
const hoveredProgressionId = ref(null);

// Map: globalIndex → array of progression ids whose detected range covers that
// bar. `start_measure` is section-relative; we resolve it to the grid's global
// measure index by matching the occurrence's sectionId against model.sections.
// Map: gi → { chords: Set<ci>, byId: Map<progId, Set<ci>> }
// chords = merged set of all ci in any progression (for passive highlight).
// byId   = per-progression ci sets (for hover highlight — avoids cross-contamination).
const progressionHighlights = computed(() => {
  const map = new Map();
  if (!model.value) return map;

  const sectionsById = new Map();
  for (const sec of model.value.sections || []) {
    if (sec.id != null) sectionsById.set(String(sec.id), sec.measures || []);
  }

  for (const prog of props.progressions || []) {
    for (const range of prog.ranges || []) {
      const measures = sectionsById.get(String(range.sectionId));
      if (!measures) continue;
      const startMeasure  = range.startMeasure ?? 0;
      const endMeasure    = startMeasure + (range.length ?? 1) - 1;
      const startChord    = range.startChord    ?? 0;
      const endChord      = range.endChord      ?? 999;
      const endChordStart = range.endChordStart ?? 0;

      for (let mi = startMeasure; mi <= endMeasure && mi < measures.length; mi++) {
        const gi = measures[mi].index;
        if (gi == null) continue;

        if (!map.has(gi)) map.set(gi, { chords: new Set(), byId: new Map() });
        const entry = map.get(gi);

        if (!entry.byId.has(prog.id)) entry.byId.set(prog.id, new Set());
        const progChords = entry.byId.get(prog.id);

        const totalChords = measures[mi].chordNames?.length ?? measures[mi].chords?.length ?? 0;
        const ciStart = (mi === startMeasure) ? startChord
                      : (mi === endMeasure)   ? endChordStart
                      : 0;
        const ciEnd   = (mi === endMeasure) ? Math.min(endChord, totalChords - 1) : totalChords - 1;
        for (let ci = ciStart; ci <= ciEnd; ci++) {
          entry.chords.add(ci);
          progChords.add(ci);
        }
      }
    }
  }
  return map;
});

// ── EduPanel current-chord derivation (selection uses { gi, ci }) ────────────
function _findInModel(gi) {
  if (!model.value) return null;
  for (let si = 0; si < model.value.sections.length; si++) {
    const sec = model.value.sections[si];
    for (let mi = 0; mi < (sec.measures?.length ?? 0); mi++) {
      const m = sec.measures[mi];
      if (m.index === gi) return { section: sec, measure: m };
    }
  }
  return null;
}

/**
 * Current selection data for EduPanel.
 * Phase 9b: Unified across chord mode ({ gi, ci }) and tab mode ({ gi } only, ci defaults to 0).
 */
const selectionData = computed(() => {
  // Tab mode: use tabSelection (measure index only, or with ci if chord was clicked)
  if (mode.value === 'tab' && tabSelection.value) {
    const found = _findInModel(tabSelection.value.gi);
    if (found) {
      return {
        gi: tabSelection.value.gi,
        ci: tabSelection.value.ci ?? 0, // Use ci if chord was clicked, else default to 0
        section: found.section,
        measure: found.measure,
      };
    }
    return null;
  }

  // Chord/no-chords mode: use gridSelection
  const sel = gridSelection.selection.value;
  if (!sel.length) return null;
  const last = sel[sel.length - 1];
  const found = _findInModel(last.gi);
  if (!found) return null;
  return {
    gi: last.gi,
    ci: last.ci,
    section: found.section,
    measure: found.measure,
  };
});

const currentChord = computed(() => {
  const data = selectionData.value;
  if (!data) return null;
  return data.measure.chordNames?.[data.ci] ?? null;
});

const currentSectionId = computed(() => {
  const data = selectionData.value;
  return data?.section?.id ?? null;
});

// Selection key for chordCards lookup: "chordName@gi.ci"
const selectionKey = computed(() => {
  const data = selectionData.value;
  if (!data) return null;
  const name = data.measure.chordNames?.[data.ci];
  if (!name) return null;
  return `${name}@${data.gi}.${data.ci}`;
});

/** Tab measure selection handler (Phase 9b). */
function onTabMeasureClick(gi) {
  tabSelection.value = { gi };
  seekToMeasure(gi);
}

/** Tab chord name click handler (Phase 9b) - updates selection with chord index. */
function onTabChordClick({ measureIndex, chordIndex, chordName }) {
  tabSelection.value = { gi: measureIndex, ci: chordIndex };
  seekToMeasure(measureIndex, chordIndex);
}

const songInfo = computed(() => ({
  title:         props.leadsheet.title,
  composer:      props.leadsheet.composer ?? null,
  performer:     props.leadsheet.performer ?? null,
  songKey:       props.leadsheet.songKey ?? null,
  tempo:         props.leadsheet.tempo ?? null,
  timeSignature: props.leadsheet.timeSignature ?? null,
  rhythm:        props.leadsheet.rhythm ?? null,
  styleSlug:     props.leadsheet.styleSlug ?? null,
}));

// ── Tab view helpers (Phase 9b) ───────────────────────────────────────────────
/**
 * Simplified measure row computation for tab viewer.
 * Respects section.lineBreaks if present; otherwise uses 4 measures per row.
 */
function tabMeasureRows(section) {
  const lineBreaks = section.lineBreaks;
  const measures = section.measures || [];

  if (lineBreaks?.length) {
    const rows = [];
    let idx = 0;
    for (const count of lineBreaks) {
      if (idx >= measures.length) break;
      const row = measures.slice(idx, idx + count);
      row._intendedCount = count;
      _stampPickupFlexPcts(row);
      rows.push(row);
      idx += count;
    }
    if (idx < measures.length) {
      const row = measures.slice(idx);
      row._intendedCount = row.length;
      _stampPickupFlexPcts(row);
      rows.push(row);
    }
    return rows;
  }

  // Fallback: uniform rows of 4 measures
  const rows = [];
  const barsPerRow = 4;
  for (let i = 0; i < measures.length; i += barsPerRow) {
    const row = measures.slice(i, i + barsPerRow);
    row._intendedCount = barsPerRow;
    _stampPickupFlexPcts(row);
    rows.push(row);
  }
  return rows;
}

function _stampPickupFlexPcts(row) {
  const first = row[0];
  if (!first || first.pickupBeats == null) {
    row._pickupPct  = null;
    row._regularPct = null;
    return;
  }
  const N = row._intendedCount || row.length;
  const globalBpm = beatsPerMeasure.value || 4;
  const ratio = Math.min(1, Math.max(0.05, first.pickupBeats / globalBpm));
  row._pickupPct  = (100 / N) * ratio * 2;
  row._regularPct = N > 1 ? (100 - row._pickupPct) / (N - 1) : 100;
}

/** Get the next measure in the tab sequence (for cross-measure ties). */
function getNextTabMeasure(index) {
  if (!model.value) return null;
  const allMeasures = model.value.sections.flatMap(s => s.measures || []);
  const idx = allMeasures.findIndex(m => m.index === index);
  return idx >= 0 && idx < allMeasures.length - 1 ? allMeasures[idx + 1] : null;
}

/** Check if the next measure is the first in its section. */
function isNextTabMeasureFirstOfSection(index) {
  if (!model.value) return false;
  const next = getNextTabMeasure(index);
  if (!next) return false;
  for (const section of model.value.sections) {
    if (section.measures?.[0]?.index === next.index) return true;
  }
  return false;
}

// ── Provide to descendants (matches tab-editor contract) ─────────────────────
// Only the read-only essentials. Editor-only stores (chordPicker, voicingPicker,
// chordClipboard, chordGridOps) are deliberately NOT provided — children use
// inject(..., null) defaults and short-circuit on falsy values.
provide('model', model);
provide('gridSelection', gridSelection);
provide('beatsPerMeasureRef', beatsPerMeasure);
provide('playingMeasureIndex', playingMeasureIndex);
provide('transportBeat', transportBeat);
provide('seekToMeasure', seekToMeasure);
provide('transportPlaying', transportPlaying);
provide('readOnly', true);
// Song's assigned rhythm pattern — Chords mode only (Analysis mode shows the
// same chord audio but keeps the flat tick row; Tab mode has real note-
// accurate playback that a generic backing rhythm shouldn't visually imply).
// ChordMeasure falls back to its default flat-tick behavior when this is null.
provide('rhythmData', computed(() => (mode.value === 'chords' ? props.leadsheet.rhythmData ?? null : null)));
// Map of gi → beatStart for the current play pass (mirrors TabEditor.vue) —
// TabMeasure's beatInThisMeasure needs this to compute true beat-within-measure
// for a pickup bar. Without it, TabMeasure falls back to `beat % thisBarsBpm`,
// which desyncs every measure after a pickup (the flat modulo doesn't know the
// timeline before it wasn't uniform) — the "chainsaw" sub-measure cursor jump.
provide('measureBeatStartMap', computed(() => {
  const beat  = transportBeat.value ?? 0;
  const table = playPositionBeatTable.value;
  const map   = new Map();
  for (const e of table) {
    if (!map.has(e.gi)) map.set(e.gi, e.beatStart);
  }
  const active = table.find(e => beat >= e.beatStart && beat < e.beatEnd);
  if (active) map.set(active.gi, active.beatStart);
  return map;
}));
// Detected-progression highlight: map of gi → progression ids, plus the
// currently-hovered progression id. ChordMeasure injects both.
provide('progressionHighlights', progressionHighlights);
provide('hoveredProgressionId', hoveredProgressionId);
// Analysis mode (roman numerals instead of chord names) — ChordCard injects both.
provide('showNumerals', showNumerals);
provide('chordNumerals', props.chordNumerals);
// Raw progression list (id/name/category/ranges) — ChordSection injects this
// to draw one bracket-box per detected progression (per row segment) instead
// of tinting each chord card individually. progressionHighlights (above) is
// the flattened per-chord version other components still use.
provide('progressionsList', computed(() => props.progressions || []));
// globalIndexOf: in the viewer the model already stores measure.index as the
// global index, so the identity-style fallback in ChordMeasure is fine. We
// still provide a function so descendants can rely on it being callable.
provide('globalIndexOf', (si, mi) => {
  const sec = model.value?.sections?.[si];
  return sec?.measures?.[mi]?.index ?? mi;
});
</script>

<style scoped>
/* Box model (max-width/padding) comes from the shared .sbn-page-stage class —
   same container Cinema uses, so the two views line up exactly. This rule
   only adds what's specific to the classic viewer. */
.sbn-leadsheet-viewer {
  background: white;
}

.sbn-leadsheet-viewer.is-embedded {
  max-width: none;
  margin: 0;
  padding: 0;
  border-radius: var(--radius);
  box-shadow: var(--clr-shadow);
  overflow: hidden;
}

/* Controls — sit in the breadcrumb band's actions slot, glassy on the
   gradient (mirrors StageTopBar's chip/icon-button treatment). */
.sbn-leadsheet-controls {
  display: flex;
  gap: 10px;
  align-items: center;
}

/* Options menu panel contents (rendered inside TopBarMenu's popover, which is
   an opaque surface — not the gradient band — so these use normal DS tokens). */
.sbn-tbm-group + .sbn-tbm-group {
  border-top: 1px solid var(--clr-border);
  padding-top: 12px;
}

.sbn-tbm-label {
  font-size: 11px;
  font-weight: 600;
  color: var(--clr-text-muted);
  text-transform: uppercase;
  letter-spacing: 0.4px;
  margin-bottom: 6px;
}

.sbn-tbm-select {
  width: 100%;
  padding: 6px 8px;
  font-size: 13px;
  color: var(--clr-text);
  background: var(--clr-surface-2);
  border: 1px solid var(--clr-border);
  border-radius: var(--radius-sm);
  cursor: pointer;
}

.sbn-tbm-radio-row {
  display: flex;
  gap: 6px;
}

.sbn-tbm-radio-row button {
  flex: 1;
  padding: 6px 8px;
  font-size: 12px;
  font-weight: 500;
  color: var(--clr-text);
  background: var(--clr-surface-2);
  border: 1px solid var(--clr-border);
  border-radius: var(--radius-sm);
  cursor: pointer;
  transition: background 0.15s;
}

.sbn-tbm-radio-row button:hover {
  background: var(--clr-surface-3);
}

.sbn-tbm-radio-row button.active {
  /* Song's own category color, not the global brand orange — a grey toggle
     row shouldn't read as an accent/CTA. Falls back to --clr-accent only if
     no category color was threaded in (shouldn't happen in the viewer). */
  background: color-mix(in srgb, var(--tbm-active-clr, var(--clr-accent)) 12%, var(--clr-surface-2));
  color: var(--tbm-active-clr, var(--clr-accent));
  border-color: var(--tbm-active-clr, var(--clr-accent));
}

/* Two-column layout - match library structure */
.sbn-leadsheet-content {
  display: flex;
  gap: 24px;
  align-items: flex-start;
  padding: 0 0 40px;
}

.sbn-leadsheet-main {
  flex: 1;
  min-width: 0;
  order: 0;
  background: var(--clr-surface);
  border: 1px solid var(--clr-border);
  border-radius: var(--radius);
  padding: 0 24px;
  display: flex;
  flex-direction: column;
}

.sbn-ve-section {
  padding: 24px 0;
  margin: 0;
}

.sbn-ve-section:first-child {
  padding-top: 0;
}

/* Remove borders from ChordGridView to match tab viewer layout */
.sbn-ve-grid {
  border: none;
  margin: 0;
  padding: 0;
}

/* Remove padding from chord view section-body to match tab view */
.sbn-ve-grid .sbn-ve-section-body {
  padding: 0;
}

/* Bleed section headers past .sbn-leadsheet-main's own 24px side padding so
   they span edge-to-edge (the shared DS rule bleeds -20px by default, matching
   the admin editor's grid inset instead — see sbn-design-system.css). Applies
   to both the tab view's inline header and the chords view's ChordSection. */
.sbn-ve-section-header,
.sbn-ve-grid :deep(.sbn-ve-section-header) {
  margin: 0 -24px;
  width: calc(100% + 48px);
}

/* Tab viewer chord name hover styling */
.sbn-tab-viewer .sbn-tab-chord-name-wrap {
  cursor: pointer;
}

.sbn-tab-viewer .sbn-tab-chord-name-wrap:hover .sbn-tab-chord-name,
.sbn-tab-chord-name--clickable:hover {
  color: var(--clr-red, #e74c3c) !important;
}

.sbn-leadsheet-sidebar {
  position: sticky;
  top: 80px;
  align-self: flex-start;
  width: 340px;
  min-width: 340px;
  flex-shrink: 0;
  order: 1;
}

/* Mobile - match library responsive behavior */
@media (max-width: 1024px) {
  .sbn-leadsheet-content {
    flex-direction: column;
  }

  .sbn-leadsheet-sidebar {
    position: static;
    width: 100%;
    min-width: 100%;
    order: -1;
  }
}

@media (max-width: 768px) {
  .sbn-breadcrumb {
    flex-wrap: wrap;
    row-gap: 10px;
  }

  .sbn-leadsheet-controls {
    flex-wrap: wrap;
  }

  .sbn-leadsheet-content {
    gap: 24px;
    padding: 0 0 20px;
  }

  .sbn-leadsheet-main {
    padding: 20px;
  }

  /* Mobile: transport deck adjustments (HoverRevealDeck's root lands in this
     scope since it's rendered directly in this component's template). */
  .sbn-hover-deck--score {
    bottom: 12px;
  }

  .sbn-hover-deck--score::before {
    height: 20px;
  }
}
</style>
