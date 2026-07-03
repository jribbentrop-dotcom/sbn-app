<template>
  <div>
    <!-- Top bar: section tabs left, view toggle right -->
    <div class="stage-sec-topbar">
      <div class="stage-sec-tabs">
        <button
          v-for="(sec, si) in sections" :key="si"
          class="stage-sec-tab"
          :class="{ 'stage-sec-tab--active': si === activeSectionIndex }"
          :data-sec="si % 4"
          @click="activeSectionIndex = si"
        >
          <span class="stage-sec-tab-letter">{{ sec.id || String.fromCharCode(65 + si) }}</span>
          <span class="stage-sec-tab-name">{{ sec.name || '' }}</span>
        </button>
      </div>

      <!-- View toggle — only show Tab button when song has tab data -->
      <div class="stage-view-toggle">
        <button
          class="stage-view-btn"
          :class="{ 'stage-view-btn--active': view === 'chords' }"
          @click="view = 'chords'"
        >Chords</button>
        <button
          v-if="tabHasData"
          class="stage-view-btn"
          :class="{ 'stage-view-btn--active': view === 'tab' }"
          @click="view = 'tab'"
        >Tab</button>
      </div>
    </div>

    <!-- ── Chords view ── -->
    <div v-if="view === 'chords' && activeSection" class="stage-sec-panel" :data-sec="activeSectionIndex % 4">
      <div ref="chordsScrollEl" class="stage-sec-scroll" :class="{ 'stage-strip--playing': playing }">
        <div ref="chordsTrackEl" class="stage-strip-track">
          <div
            v-for="(measure, mi) in (activeSection.measures || [])" :key="measure.globalIndex ?? mi"
            class="stage-sec-measure"
            :class="{
              'stage-sec-measure--active': measure.globalIndex === currentBarIndex,
              'stage-sec-measure--past':   playing && measure.globalIndex < currentBarIndex,
              'stage-sec-measure--hidden': !isMeasureVisible(measure),
            }"
            @click="isMeasureVisible(measure) && $emit('seek-measure', measure.globalIndex ?? mi)"
          >
            <div class="stage-sec-bar-num">{{ (measure.globalIndex ?? mi) + 1 }}</div>
            <div class="stage-sec-measure-chords">
              <div
                v-for="(name, ci) in (measure.chordNames || [])" :key="ci"
                class="stage-chord-card"
              >
                <div class="stage-chord-name" v-html="formatChordHtml(name)"></div>
                <div
                  v-if="getVoicingAt(measure, ci)"
                  class="stage-chord-diagram"
                  v-html="renderDiagram(getVoicingAt(measure, ci))"
                ></div>
                <div v-else class="stage-chord-empty"></div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- ── Tab view — horizontal strip, transform-driven follow-cam ── -->
    <div v-if="view === 'tab' && tabModel" class="stage-tab-panel">
      <div ref="tabScrollEl" class="stage-tab-scroll" :class="{ 'stage-strip--playing': playing }">
        <div ref="tabTrackEl" class="stage-strip-track">
          <template v-for="(section, si) in tabSections" :key="section.id || si">
            <div
              v-for="measure in section.measures"
              :key="measure.index"
              class="stage-tab-measure-wrap"
              :class="{ 'stage-tab-measure-wrap--active': measure.index === currentBarIndex }"
              @click="emit('seek-measure', measure.index)"
            >
              <TabMeasure
                :measure="measure"
                :is-first-of-section="si === 0 && measure === section.measures[0]"
                :show-clef="measure.index === 0"
                :time-signature="props.timeSignature"
                :ticks-per-measure="tabModel.ticksPerMeasure"
                :next-measure="getNextTabMeasure(measure.index)"
                :is-next-first-of-section="isNextTabMeasureFirstOfSection(measure.index)"
                :chord-names="measure.chordNames || []"
                :bars-per-row="4"
                :read-only="true"
                :allow-chord-click="false"
                :cursor="null"
                :pending-digit="null"
                :selected-events="new Set()"
              />
            </div>
          </template>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch, nextTick, onMounted, onUnmounted } from 'vue';
import { formatChordHtml } from '@/tab-editor/utils/chordFormat.js';
import TabMeasure from '@/tab-editor/components/TabMeasure.vue';

const props = defineProps({
  sections:               { type: Array,   default: () => [] },
  currentBarIndex:        { type: Number,  default: 0 },
  // Fractional play position (e.g. 2.73 = bar 2, 73% through). Used for
  // sub-bar continuous scroll — updated on every video timeupdate (~10 Hz),
  // which combined with RAF lerp gives smooth motion between beats.
  fractionalPlayPosition: { type: Number,  default: 0 },
  playing:                { type: Boolean, default: false },
  chordVoicings:          { type: Object,  default: () => ({}) },
  activeVoltaPass:        { type: Number,  default: 1 },
  tabModel:               { type: Object,  default: null },
  tabHasData:             { type: Boolean, default: false },
  timeSignature:          { type: String,  default: '4/4' },
});

const emit = defineEmits(['seek-measure']);

const view = ref('chords');
const activeSectionIndex = ref(0);
const activeSection = computed(() => props.sections[activeSectionIndex.value] ?? null);

const chordsScrollEl = ref(null);
const tabScrollEl    = ref(null);
const chordsTrackEl  = ref(null);
const tabTrackEl     = ref(null);

// ── Follow-cam: direct scrollLeft, not a lerp'd chaser ───────────────────────
// We know exactly where the playhead is every frame (fractionalPlayPosition →
// pixel), so we SET container.scrollLeft to it directly each RAF tick instead of
// lerping toward it. Direct assignment is rigid — no feedback loop, so it can't
// drift or lag — while staying native scroll, so overflow-x:auto's scrollbar
// stays live and draggable-when-paused. RAF just re-reads the (continuous)
// position and re-applies scrollLeft at 60fps.
//
// The beat metronome column inside TabMeasure / the active chord card is a
// separate, beat-snapped cursor and is intentionally left untouched.

// Where the playhead sits in the viewport, as a fraction of its width.
// 0.5 = dead center; below 0.5 biases LEFT so upcoming bars stay on-screen to
// the right (you read ahead).
const PLAYHEAD_ANCHOR = 0.4;

let _raf           = null;
let _measureLayout = [];   // per-visible-bar { offset, width } in active strip

function activeContainer() {
  return view.value === 'tab' ? tabScrollEl.value : chordsScrollEl.value;
}

function activeTrack() {
  return view.value === 'tab' ? tabTrackEl.value : chordsTrackEl.value;
}

function barSelector() {
  return view.value === 'tab' ? '.stage-tab-measure-wrap' : '.stage-sec-measure';
}

function rebuildLayout() {
  const track = activeTrack();
  if (!track) { _measureLayout = []; return; }
  // Exclude hidden volta measures: playheadX indexes this array by VISIBLE
  // measure order (it skips hidden ones), so the layout must be visible-only too,
  // or every bar after a collapsed volta maps to the wrong card. (Chords only —
  // the tab view has no hidden measures.)
  const els = Array.from(track.querySelectorAll(barSelector()))
    .filter(el => !el.classList.contains('stage-sec-measure--hidden'));
  // offsetLeft is measured from the track's origin (the track is position:relative,
  // so it's the offsetParent) which coincides with the container's scroll-content
  // origin — so offsetLeft is directly usable as a scrollLeft target.
  _measureLayout = els.map(el => ({ offset: el.offsetLeft, width: el.offsetWidth }));
}

// Pixel-X of the playhead inside the active section's strip (track coordinates).
// currentBarIndex (a gi) picks the bar (repeat/volta-aware); the sub-bar
// fraction comes from fractionalPlayPosition.
function playheadX() {
  if (!_measureLayout.length) return null;
  const measures = activeSection.value?.measures ?? [];
  let visibleIdx = -1;
  for (let mi = 0; mi < measures.length; mi++) {
    if (!isMeasureVisible(measures[mi])) continue;
    visibleIdx++;
    if (measures[mi].globalIndex === props.currentBarIndex) break;
  }
  if (visibleIdx < 0 || visibleIdx >= _measureLayout.length) return null;
  const bar  = _measureLayout[visibleIdx];
  const pos  = props.fractionalPlayPosition;
  const frac = pos - Math.floor(pos);
  return bar.offset + frac * bar.width;
}

// scrollLeft that places the playhead at PLAYHEAD_ANCHOR of the viewport,
// clamped to the container's own scroll range (last bar rests at the right edge;
// no empty gap past the score).
function anchorScrollLeft(container, px) {
  const vw        = container.clientWidth;
  const maxScroll = Math.max(0, container.scrollWidth - vw);
  return Math.max(0, Math.min(maxScroll, px - vw * PLAYHEAD_ANCHOR));
}

// The follow-cam drives native scrollLeft DIRECTLY (no lerp). Direct assignment
// is as rigid as a transform — it can't drift — but unlike a transform it keeps
// overflow-x:auto's scrollbar live and accurate during playback, so the user
// always sees their position in the section.
function applyFollow() {
  const container = activeContainer();
  if (!container) return;
  const px = playheadX();
  if (px === null) return;
  container.scrollLeft = anchorScrollLeft(container, px);
}

function startScrollFollow() {
  if (_raf) return;
  function tick() {
    applyFollow();
    _raf = requestAnimationFrame(tick);
  }
  _raf = requestAnimationFrame(tick);
}

function stopScrollFollow() {
  if (_raf) { cancelAnimationFrame(_raf); _raf = null; }
}

// Viewport width feeds both the end-pad and the anchor math, so re-measure on
// resize. During playback the RAF reads live geometry and self-corrects; this
// keeps the cached layout + end-pad fresh for the paused state too.
function onResize() {
  rebuildLayout();
  applyFollow();   // re-anchor after viewport change (RAF continues if playing)
}
onMounted(() => window.addEventListener('resize', onResize));
onUnmounted(() => {
  stopScrollFollow();
  window.removeEventListener('resize', onResize);
});

watch(() => props.playing, async (isPlaying) => {
  if (isPlaying) {
    await nextTick();
    rebuildLayout();
    startScrollFollow();
  } else {
    stopScrollFollow();
  }
}, { immediate: true });

// Switching Chords ↔ Tab swaps which container the follow-cam drives, but
// _measureLayout still holds the OLD view's geometry until something rebuilds it.
// Re-measure the now-active view and re-anchor to the current bar so focus isn't
// lost across the toggle.
watch(view, async () => {
  await nextTick();
  rebuildLayout();
  applyFollow();
});

// A volta pass flip changes which measures are visible, and each collapse/expand
// animates over 0.35s (see .stage-sec-measure transition). Re-measure once the
// animation settles so the follow-cam's cached offsets match the new layout
// instead of desyncing until the next seek/section change. (Chords-only concern.)
watch(() => props.activeVoltaPass, () => {
  if (view.value !== 'chords') return;
  setTimeout(() => {
    rebuildLayout();
    applyFollow();
  }, 380);
});

watch(() => props.currentBarIndex, async (barIndex) => {
  const si = props.sections.findIndex(sec =>
    (sec.measures ?? []).some(m => (m.globalIndex ?? -1) === barIndex)
  );
  if (si !== -1 && si !== activeSectionIndex.value) {
    activeSectionIndex.value = si;
    await nextTick();
    rebuildLayout();
  }
  if (!props.playing) {
    // Paused seek: reveal the bar (RAF isn't running, so anchor it once).
    await nextTick();
    rebuildLayout();
    applyFollow();
  }
});

// A volta measure is visible only when its number matches the active pass.
function isMeasureVisible(measure) {
  if (!measure.volta) return true;
  return measure.volta.number === props.activeVoltaPass;
}

function getVoicingAt(measure, ci) {
  const name = measure.chordNames?.[ci];
  if (!name) return null;
  const gi = measure.globalIndex ?? 0;
  return props.chordVoicings?.[`${name}@${gi}.${ci}`] ?? props.chordVoicings?.[name] ?? null;
}

function renderDiagram(voicing) {
  if (!voicing || typeof window.sbnRenderDiagramSVG !== 'function') return '';
  return window.sbnRenderDiagramSVG(voicing, { dotColor: '#000', showFingers: true });
}

// ── Tab helpers ──────────────────────────────────────────────────────────────

// Only the active section's measures in the tab view
const tabSections = computed(() => {
  if (!props.tabModel) return [];
  const sec = props.tabModel.sections[activeSectionIndex.value];
  return sec ? [sec] : [];
});

function getNextTabMeasure(index) {
  if (!props.tabModel) return null;
  const all = props.tabModel.sections.flatMap(s => s.measures ?? []);
  const idx = all.findIndex(m => m.index === index);
  return idx >= 0 && idx < all.length - 1 ? all[idx + 1] : null;
}

function isNextTabMeasureFirstOfSection(index) {
  if (!props.tabModel) return false;
  const next = getNextTabMeasure(index);
  if (!next) return false;
  for (const section of props.tabModel.sections) {
    if (section.measures?.[0]?.index === next.index) return true;
  }
  return false;
}
</script>

<style scoped>
/* ── Top bar: section tabs + view toggle ── */
.stage-sec-topbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 14px;
  flex-wrap: wrap;
}

.stage-sec-tabs {
  display: flex;
  gap: 6px;
  flex-wrap: wrap;
}

.stage-sec-tab {
  display: flex;
  align-items: center;
  gap: 7px;
  padding: 6px 14px 6px 10px;
  border-radius: var(--radius-sm);
  border: 1px solid var(--clr-border);
  background: transparent;
  cursor: pointer;
  transition: all 0.15s ease;
  color: var(--clr-text-dim);
}

.stage-sec-tab:hover {
  background: var(--clr-surface-2);
  color: var(--clr-text);
}

.stage-sec-tab--active {
  background: var(--clr-surface-2);
  border-color: rgba(var(--stage-accent-rgb), 0.4);
  color: var(--clr-text);
}

.stage-sec-tab-letter {
  font-family: var(--font-mono);
  font-size: 11px;
  font-weight: 700;
  width: 20px;
  height: 20px;
  border-radius: 4px;
  display: grid;
  place-items: center;
  background: var(--clr-surface-3);
  color: var(--clr-text-dim);
  flex-shrink: 0;
}

.stage-sec-tab--active .stage-sec-tab-letter,
.stage-sec-tab[data-sec="0"] .stage-sec-tab-letter,
.stage-sec-tab[data-sec="1"] .stage-sec-tab-letter {
  background: rgba(var(--stage-accent-rgb), 0.12);
  color: var(--stage-accent);
}

/* Section identity tints — kept as local literals, not DS-mapped */
.stage-sec-tab[data-sec="2"] .stage-sec-tab-letter { background: rgba(139,92,246,0.12); color: #7c3aed; }
.stage-sec-tab[data-sec="3"] .stage-sec-tab-letter { background: rgba(16,185,129,0.12); color: var(--clr-success); }

.stage-sec-tab-name {
  font-family: var(--font-chord);
  font-style: italic;
  font-size: 13px;
}

/* ── View toggle ── */
.stage-view-toggle {
  display: flex;
  border: 1px solid var(--clr-border);
  border-radius: var(--radius-sm);
  overflow: hidden;
  flex-shrink: 0;
}

.stage-view-btn {
  padding: 5px 14px;
  background: transparent;
  border: none;
  color: var(--clr-text-dim);
  font-family: var(--font-mono);
  font-size: 11px;
  font-weight: 600;
  letter-spacing: 0.5px;
  cursor: pointer;
  transition: background 0.15s, color 0.15s;
}

.stage-view-btn + .stage-view-btn {
  border-left: 1px solid var(--clr-border);
}

.stage-view-btn:hover {
  background: var(--clr-surface-2);
  color: var(--clr-text);
}

.stage-view-btn--active {
  background: rgba(var(--stage-accent-rgb), 0.1);
  color: var(--stage-accent);
}

/* ── Chords panel ── */
.stage-sec-panel {
  background: transparent;
  border: none;
  border-radius: var(--radius);
  padding: 4px 0 14px;
}

.stage-sec-scroll {
  overflow-x: auto;
  padding-bottom: 6px;
  scrollbar-width: thin;
  scrollbar-color: var(--clr-border) transparent;
}

.stage-sec-scroll::-webkit-scrollbar { height: 4px; }
.stage-sec-scroll::-webkit-scrollbar-track { background: transparent; }
.stage-sec-scroll::-webkit-scrollbar-thumb { background: var(--clr-border); border-radius: 2px; }

/* Inner track: the row of measures. position:relative makes THIS the offsetParent
   for the measures, so their offsetLeft is measured from the track's own origin —
   which equals the scroll-content origin of the container. That's what lets the
   follow-cam use offsetLeft directly as a scrollLeft target (any other offsetParent
   would fold in an extra, per-view constant and mis-anchor the playhead). */
.stage-strip-track {
  display: flex;
  width: max-content;
  position: relative;
}

/* During playback the follow-cam owns scrollLeft, so a scrollbar would be a
   false affordance (its thumb moves on its own and fights a manual drag) and a
   small motion distraction under the notation. Hide it while playing — scroll
   still works, it's just not shown; it returns on pause for manual panning.
   Both classes are named in the ::-webkit selectors so they outrank the per-view
   .stage-*-scroll::-webkit-scrollbar { height:4px } rules regardless of source order. */
.stage-sec-scroll.stage-strip--playing,
.stage-tab-scroll.stage-strip--playing {
  scrollbar-width: none;              /* Firefox */
}
.stage-sec-scroll.stage-strip--playing::-webkit-scrollbar,
.stage-tab-scroll.stage-strip--playing::-webkit-scrollbar { display: none; }   /* Chromium/WebKit */

.stage-sec-measure {
  display: flex;
  flex-direction: column;
  gap: 6px;
  flex-shrink: 0;
  cursor: pointer;
  padding: 8px 10px 10px;
  border-radius: var(--radius-sm);
  border: 1px solid var(--clr-border);
  background: var(--clr-white);
  box-shadow: var(--clr-shadow-sm);
  min-width: 80px;
  position: relative;
  margin-right: 8px;
  max-width: 300px;
  overflow: hidden;
  transition:
    max-width    0.35s cubic-bezier(0.4, 0, 0.2, 1),
    min-width    0.35s cubic-bezier(0.4, 0, 0.2, 1),
    opacity      0.25s ease,
    margin       0.35s cubic-bezier(0.4, 0, 0.2, 1),
    padding      0.35s cubic-bezier(0.4, 0, 0.2, 1),
    border-width 0.35s cubic-bezier(0.4, 0, 0.2, 1),
    border-color 0.15s ease,
    box-shadow   0.15s ease;
}

.stage-sec-measure:hover {
  border-color: rgba(var(--stage-accent-rgb), 0.4);
  box-shadow: var(--clr-shadow);
}

.stage-sec-measure--active {
  border-color: var(--stage-accent);
  background: rgba(var(--stage-accent-rgb), 0.04);
  box-shadow: 0 0 0 1px rgba(var(--stage-accent-rgb), 0.12), var(--clr-shadow);
}

.stage-sec-measure--past {
  opacity: 0.4;
}

.stage-sec-measure--hidden {
  max-width: 0;
  min-width: 0;
  opacity: 0;
  padding: 0;
  margin: 0;
  border-width: 0;
  pointer-events: none;
}

.stage-sec-bar-num {
  font-family: var(--font-mono);
  font-size: 9px;
  color: var(--clr-text-muted);
  font-weight: 500;
}

.stage-sec-measure--active .stage-sec-bar-num {
  color: var(--stage-accent);
}

.stage-sec-measure-chords {
  display: flex;
  gap: 8px;
}

.stage-chord-card {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 6px;
  flex-shrink: 0;
  width: 72px;
}

.stage-chord-name {
  font-family: var(--font-chord);
  font-size: 14px;
  font-weight: 600;
  color: var(--clr-text);
  --sbn-chord-color: var(--clr-text);
  text-align: center;
  line-height: 1;
  white-space: nowrap;
}

.stage-chord-name :deep(.sbn-chord-accidental) { font-size: 0.75em; vertical-align: 0.15em; }
.stage-chord-name :deep(.sbn-chord-quality)    { font-size: 0.7em; font-style: italic; font-weight: 400; }
.stage-chord-name :deep(.sbn-chord-ext)        { font-size: 0.6em; vertical-align: 0.5em; font-weight: 600; }

.stage-chord-diagram :deep(svg) {
  width: 100%;
  height: auto;
  display: block;
}

.stage-chord-empty {
  width: 100%;
  height: 80px;
}

/* ── Tab panel ── */
.stage-tab-panel {
  padding: 4px 0 14px;
}

.stage-tab-scroll {
  overflow-x: auto;
  padding-bottom: 6px;
  scrollbar-width: thin;
  scrollbar-color: var(--clr-border) transparent;
  --sbn-tab-bg:           transparent;
  --sbn-tab-active-bg:    rgba(var(--stage-accent-rgb), 0.08);
  --sbn-chord-color:      var(--clr-text);
}

.stage-tab-scroll::-webkit-scrollbar { height: 4px; }
.stage-tab-scroll::-webkit-scrollbar-track { background: transparent; }
.stage-tab-scroll::-webkit-scrollbar-thumb { background: var(--clr-border); border-radius: 2px; }

/* Tab measure wrapper — click target + active highlight */
.stage-tab-measure-wrap {
  cursor: pointer;
  border-radius: var(--radius-sm);
  transition: background 0.15s;
  flex-shrink: 0;
}

.stage-tab-measure-wrap:hover {
  background: rgba(var(--stage-accent-rgb), 0.04);
}

.stage-tab-measure-wrap--active {
  background: rgba(var(--stage-accent-rgb), 0.07);
  outline: 1px solid rgba(var(--stage-accent-rgb), 0.3);
  outline-offset: -1px;
}

/* Chord names in tab view */
.stage-tab-scroll :deep(.sbn-tab-chord-bar .sbn-tab-chord-name) {
  font-size: 26px;
  color: var(--clr-text);
  --sbn-chord-color: var(--clr-text);
  --clr-text: var(--clr-text);
}

.stage-tab-scroll :deep(.sbn-tab-chord-bar) {
  height: 50px;
}
</style>
