<template>
  <!-- Hero row: video left, Now Playing right -->
  <section class="stage-hero">
    <!-- Video card (left, 1.55fr). Hover anywhere on the card reveals the
         transport overlay (self-hosted video only) — the deck itself is
         invisible until revealed, so the hover target has to live on this
         larger, always-present container instead. -->
    <div
      class="stage-video-card"
      :class="{ 'stage-video-card--live': hasVideo }"
      @mouseenter="overlayHovered = true"
      @mouseleave="overlayHovered = false"
    >
      <!-- VideoPlayer or placeholder -->
      <VideoPlayer
        v-if="hasVideo"
        :video-id="videoId"
        :video-type="videoType"
        :muted="muted"
        ref="playerRef"
        @timeupdate="$emit('video-timeupdate', $event)"
        @play-state-change="$emit('video-play-state', $event)"
        @ready="$emit('video-ready')"
        @genuinely-playing="$emit('video-genuinely-playing')"
      />
      <div v-else class="stage-video-placeholder">
        <div class="stage-video-placeholder-icon">▶</div>
        <p class="stage-video-placeholder-hint">No video linked — attach a YouTube URL in the admin editor to enable sync.</p>
      </div>

      <!-- Transport overlay — only for self-hosted video. YouTube's own iframe
           controls already occupy this space, so we don't layer another
           transport on top of them. -->
      <slot v-if="hasVideo && videoType !== 'youtube'" name="overlay" :visible="overlayHovered" />
    </div>

    <!-- Now Playing plinth (right, 1fr) -->
    <div class="stage-hero-card stage-hero-card--now">
      <!-- Header label -->
      <div class="stage-hero-label">
        <span class="stage-hero-label-dot"></span>
        <span>Now Playing · Bar <strong>{{ currentBarNum }}</strong> / {{ sectionLabel }}</span>
      </div>

      <!-- Chord glyph + diagram -->
      <div class="stage-hero-content">
        <div class="stage-hero-left">
          <!-- 128px chord glyph -->
          <div
            class="stage-hero-chord"
            v-html="formatChordHtml(currentChordName)"
          ></div>

          <!-- Roman numeral sub-line -->
          <div class="stage-hero-chord-sub">
            <span>Roman: <strong>{{ romanNumeral }}</strong></span>
          </div>

          <!-- Next → dashed row -->
          <div class="stage-next-row">
            <span class="stage-next-label">Next →</span>
            <span class="stage-next-chord" v-html="formatChordHtml(nextChordName)"></span>
            <span class="stage-next-countdown">in <strong>{{ beatsUntilNext }}</strong> beats</span>
          </div>
        </div>

        <!-- Chord card (library card if available, neon fallback) -->
        <div class="stage-hero-diagram">
          <ChordCard
            v-if="currentChordCard"
            :chord="currentChordCard"
            :show-root="true"
            dot-color="#000"
          />
        </div>
      </div>

      <!-- Beat row (pinned to bottom) — RhythmStrip cell styling.
           beatCellStates is a flat "every beat is a hit" array for now; once
           the song's real rhythm pattern is wired in, only that computed's
           source changes (rest/hit/accent per step) — template + CSS stay. -->
      <div class="stage-beat-row">
        <span
          v-for="(state, i) in beatCellStates" :key="i"
          class="stage-beat-cell"
          :class="{ 'is-current': i === currentBeat }"
        ></span>
      </div>
    </div>
  </section>
</template>

<script setup>
import { ref, computed } from 'vue';
import VideoPlayer from '@/Components/Library/Video/VideoEmbed.vue';
import ChordCard from '@/Components/Library/ChordCard.vue';
import { formatChordHtml } from '@/tab-editor/utils/chordFormat.js';

const playerRef = ref(null);
const overlayHovered = ref(false);

const props = defineProps({
  // Video
  hasVideo:      { type: Boolean, default: false },
  videoId:       { type: String, default: '' },
  videoType:     { type: String, default: 'youtube' },
  muted:         { type: Boolean, default: false },
  // Now playing
  currentChordName: { type: String, default: '' },
  nextChordName:    { type: String, default: '' },
  currentBarNum:    { type: Number, default: 1 },
  sectionLabel:     { type: String, default: '' },
  romanNumeral:     { type: String, default: '—' },
  currentVoicing:   { type: Object, default: null },
  currentChordCard: { type: Object, default: null },
  beatsUntilNext:   { type: Number, default: 4 },

  // Playback
  currentBeat:      { type: Number, default: 0 },
  beatsPerMeasure:  { type: Number, default: 4 },
});

defineEmits(['video-timeupdate', 'video-play-state', 'video-ready', 'video-genuinely-playing']);

// Basic beat row: every beat is a "hit" cell. Swap the source here (a real
// rhythm pattern's hit/rest/accent steps) to upgrade — template/CSS unchanged.
const beatCellStates = computed(() => Array.from({ length: props.beatsPerMeasure }, () => 'hit'));

defineExpose({
  play:            () => playerRef.value?.play(),
  pause:           () => playerRef.value?.pause(),
  seekTo:          (s) => playerRef.value?.seekTo(s),
  setPlaybackRate: (r) => playerRef.value?.setPlaybackRate(r),
});

</script>

<style scoped>
.stage-hero {
  display: grid;
  grid-template-columns: 1.55fr 1fr;
  gap: 24px;
  margin-bottom: 28px;
}

/* ── Video card ─────────────────────────────────────── */
.stage-video-card {
  background: var(--clr-surface-3);
  border: 1px solid var(--clr-border);
  border-radius: var(--radius-lg);
  position: relative;
  overflow: hidden;
  aspect-ratio: 16/9;
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 280px;
}

.stage-video-card--live {
  border-color: rgba(var(--stage-accent-rgb), 0.4);
}

/* VideoPlayer fills the card */
.stage-video-card :deep(.sbn-video-player) {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
}

.stage-video-card :deep(.sbn-video-iframe-wrap) {
  position: absolute !important;
  inset: 0 !important;
  padding-top: 0 !important;
  width: 100% !important;
  height: 100% !important;
}

.stage-video-card :deep(.sbn-video-iframe-wrap > div) {
  position: absolute;
  inset: 0;
}

.stage-video-card :deep(iframe) {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  border: 0;
}

.stage-video-meta {
  position: absolute;
  top: 14px;
  left: 14px;
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 6px 10px 6px 8px;
  background: var(--clr-overlay-dark);
  backdrop-filter: blur(8px);
  border-radius: 100px;
  font-family: var(--font-mono);
  font-size: 10px;
  font-weight: 600;
  letter-spacing: 1.5px;
  text-transform: uppercase;
  color: var(--clr-white);
  z-index: 2;
}

.stage-video-meta-dot {
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: var(--stage-accent);
  box-shadow: 0 0 6px var(--stage-accent);
  animation: stagePulse 1.2s ease-in-out infinite;
}

.stage-video-placeholder {
  position: absolute;
  inset: 0;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 14px;
  background: radial-gradient(ellipse 400px 200px at 50% 0%, rgba(var(--stage-accent-rgb), 0.1), transparent 70%),
              linear-gradient(180deg, var(--clr-surface-2) 0%, var(--clr-bg) 100%);
  color: var(--clr-text);
  text-align: center;
  padding: 32px;
}

.stage-video-placeholder-icon {
  width: 56px;
  height: 56px;
  border-radius: 50%;
  background: var(--stage-accent);
  color: var(--clr-white);
  display: grid;
  place-items: center;
  font-size: 18px;
  box-shadow: 0 0 0 8px rgba(var(--stage-accent-rgb), 0.12), 0 8px 24px rgba(var(--stage-accent-rgb), 0.25);
}

.stage-video-placeholder-hint {
  margin: 0;
  font-size: 12px;
  color: var(--clr-text-muted);
  max-width: 28ch;
  line-height: 1.5;
}

.stage-video-sync-strip {
  position: absolute;
  left: 14px;
  right: 14px;
  bottom: 14px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 8px 12px;
  background: var(--clr-overlay-dark);
  backdrop-filter: blur(10px);
  border-radius: var(--radius-sm);
  color: var(--clr-white);
  font-family: var(--font-mono);
  font-size: 11px;
  z-index: 2;
}

.stage-video-sync-strip strong {
  color: var(--clr-white);
  font-weight: 600;
}

.stage-sync-on  { color: var(--stage-accent); }
.stage-sync-off { color: var(--clr-text-muted); }

/* ── Now playing card ───────────────────────────────── */
.stage-hero-card {
  background: linear-gradient(180deg, var(--clr-surface-2) 0%, var(--clr-white) 100%);
  border: 1px solid var(--clr-border);
  border-radius: var(--radius-lg);
  padding: 28px 32px;
  position: relative;
  overflow: hidden;
  min-height: 280px;
}

.stage-hero-card--now {
  border-color: rgba(var(--stage-accent-rgb), 0.35);
  box-shadow: inset 0 0 0 1px rgba(var(--stage-accent-rgb), 0.1);
}

.stage-hero-label {
  display: flex;
  align-items: center;
  gap: 8px;
  font-family: var(--font-mono);
  font-size: 10px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 2px;
  color: var(--stage-accent);
  position: relative;
  z-index: 1;
}

.stage-hero-label-dot {
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: var(--stage-accent);
  box-shadow: 0 0 8px var(--stage-accent);
  animation: stagePulse 1.2s ease-in-out infinite;
}

.stage-hero-content {
  display: grid;
  grid-template-columns: 1fr auto;
  gap: 24px;
  align-items: center;
  margin-top: 20px;
  position: relative;
  z-index: 1;
}

.stage-hero-left {
  min-width: 0;
}

.stage-hero-chord {
  font-family: var(--font-chord);
  font-size: 128px;
  line-height: 0.88;
  letter-spacing: -0.03em;
  font-weight: 700;
  color: var(--stage-accent-2);
}

.stage-hero-chord :deep(.sbn-chord-accidental) {
  font-size: 0.65em;
  vertical-align: 0.25em;
  font-weight: 400;
}

.stage-hero-chord :deep(.sbn-chord-quality) {
  font-size: 0.55em;
  font-weight: 400;
  font-style: italic;
}

.stage-hero-chord :deep(.sbn-chord-ext) {
  font-size: 0.35em;
  vertical-align: 1em;
  font-weight: 600;
  color: var(--stage-accent);
  margin-left: 4px;
}

.stage-hero-chord-sub {
  margin-top: 16px;
  font-size: 13px;
  color: var(--clr-text-dim);
  letter-spacing: 0.3px;
}

.stage-hero-chord-sub strong {
  color: var(--clr-text);
  font-weight: 600;
}

.stage-next-row {
  margin-top: 18px;
  padding-top: 16px;
  border-top: 1px dashed var(--clr-border);
  display: flex;
  align-items: baseline;
  gap: 12px;
  flex-wrap: wrap;
}

.stage-next-label {
  font-family: var(--font-mono);
  font-size: 10px;
  font-weight: 600;
  letter-spacing: 1.5px;
  text-transform: uppercase;
  color: var(--clr-text-muted);
}

.stage-next-chord {
  font-family: var(--font-chord);
  font-size: 32px;
  font-weight: 700;
  color: var(--clr-text-dim);
  line-height: 1;
  letter-spacing: -0.01em;
}

.stage-next-chord :deep(.sbn-chord-accidental) { font-size: 0.7em; vertical-align: 0.2em; }
.stage-next-chord :deep(.sbn-chord-quality)    { font-size: 0.55em; font-style: italic; font-weight: 400; }
.stage-next-chord :deep(.sbn-chord-ext)        { font-size: 0.45em; vertical-align: 0.8em; font-weight: 600; color: var(--stage-accent); margin-left: 1px; }

.stage-next-countdown {
  font-size: 12px;
  color: var(--clr-text-muted);
}

.stage-next-countdown strong {
  color: var(--stage-accent);
  font-weight: 600;
}

.stage-hero-diagram {
  width: 180px;
  flex-shrink: 0;
}

/* Beat row — RhythmStrip cell styling, driven by beatCellStates */
.stage-beat-row {
  position: absolute;
  left: 32px;
  right: 32px;
  bottom: 28px;
  display: grid;
  grid-auto-flow: column;
  grid-auto-columns: 1fr;
  gap: 8px;
}

.stage-beat-cell {
  height: 22px;
  border-radius: 3px;
  background: var(--stage-line);
  transition: background 0.1s, transform 0.1s;
}

.stage-beat-cell.is-current {
  background: var(--stage-gradient);
  box-shadow: 0 0 6px rgba(var(--stage-accent-rgb), 0.3);
  outline: 1.5px solid var(--stage-accent);
  outline-offset: 1px;
  transform: translateY(-1px);
}

@keyframes stagePulse {
  0%, 100% { opacity: 1; transform: scale(1); }
  50%       { opacity: 0.55; transform: scale(1.4); }
}

@media (max-width: 960px) {
  .stage-hero {
    grid-template-columns: 1fr;
  }

  .stage-hero-chord {
    font-size: 96px;
  }

  .stage-video-card {
    min-height: 220px;
  }
}
</style>
