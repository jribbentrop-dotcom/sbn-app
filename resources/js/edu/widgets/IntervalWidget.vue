<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from 'vue';

interface Interval { semitones: number; name: string; short: string; character: string; }

const INTERVALS: Interval[] = [
  { semitones: 1,  name: 'Minor Second',   short: 'm2', character: 'Dissonant. Tense, almost painful — the sound of unresolved friction.' },
  { semitones: 2,  name: 'Major Second',   short: 'M2', character: 'Stepwise. Neutral in motion, the basic building block of melody.' },
  { semitones: 3,  name: 'Minor Third',    short: 'm3', character: 'Melancholic. The defining sound of minor — introspective, tender.' },
  { semitones: 4,  name: 'Major Third',    short: 'M3', character: 'Bright. Open and warm — the heart of a major chord.' },
  { semitones: 5,  name: 'Perfect Fourth', short: 'P4', character: 'Open. Hollow and strong — ancient, found in every culture\'s music.' },
  { semitones: 6,  name: 'Tritone',        short: 'TT', character: 'Unstable. Exactly half an octave — the engine of dominant tension.' },
  { semitones: 7,  name: 'Perfect Fifth',  short: 'P5', character: 'Pure. The most consonant interval after the octave — powerful, stable.' },
  { semitones: 8,  name: 'Minor Sixth',    short: 'm6', character: 'Bittersweet. Longing — the inversion of the major third.' },
  { semitones: 9,  name: 'Major Sixth',    short: 'M6', character: 'Warm. Sweet and song-like — the inversion of the minor third.' },
  { semitones: 10, name: 'Minor Seventh',  short: 'm7', character: 'Bluesy. The sound of the dominant seventh — tension, expectation.' },
  { semitones: 11, name: 'Major Seventh',  short: 'M7', character: 'Luminous. A half-step from home — the Bossa Nova sound, suspended beauty.' },
  { semitones: 12, name: 'Octave',         short: 'P8', character: 'Complete. Same note, doubled — resolution, space, arrival.' },
];

const NOTE_NAMES = ['C','C#','D','D#','E','F','F#','G','G#','A','A#','B','C'];

const INTERVAL_COLOR = [
  '#ef4444','#f97316','#8b5cf6','#f59e0b','#14b8a6',
  '#ef4444','#10b981','#8b5cf6','#f59e0b','#3b82f6','#14b8a6','#10b981',
];

const DOT_R = 14;
const SVG_W = 120;
const SVG_H = 240;
const DOT_X = SVG_W / 2;
const ROOT_Y = SVG_H - 32;
const TOP_Y  = 32;

function intervalY(semitones: number) {
  return ROOT_Y - ((semitones / 12) * (ROOT_Y - TOP_Y));
}
function yToSemitones(y: number) {
  const raw = ((ROOT_Y - y) / (ROOT_Y - TOP_Y)) * 12;
  return Math.round(Math.max(1, Math.min(12, raw)));
}

const idx        = ref(0);
const dragY      = ref<number | null>(null);
const isDragging = ref(false);
const direction  = ref<'left' | 'right' | null>(null);
const visible    = ref(true);
const animating  = ref(false);

const svgEl = ref<SVGSVGElement | null>(null);
const dragActive = { current: false };
const swipeTouchStart = { current: null as number | null };

const interval  = computed(() => INTERVALS[idx.value]);
const color     = computed(() => INTERVAL_COLOR[idx.value]);
const upperY    = computed(() => dragY.value !== null ? dragY.value : intervalY(interval.value.semitones));
const upperNote = computed(() => NOTE_NAMES[interval.value.semitones]);

const animClass = computed(() => {
  if (!visible.value) return direction.value === 'right' ? 'iv-exit-left' : 'iv-exit-right';
  if (direction.value === 'right') return 'iv-enter-right';
  if (direction.value === 'left')  return 'iv-enter-left';
  return '';
});

function clientToSvgY(clientY: number): number | null {
  if (!svgEl.value) return null;
  const rect = svgEl.value.getBoundingClientRect();
  return (clientY - rect.top) * (SVG_H / rect.height);
}

function snapToSemitone(rawY: number) {
  const semitones = yToSemitones(rawY);
  idx.value = semitones - 1;
  dragY.value = null;
  isDragging.value = false;
  dragActive.current = false;
}

function onMouseDown(e: MouseEvent) {
  e.preventDefault();
  dragActive.current = true;
  isDragging.value = true;
  const y = clientToSvgY(e.clientY);
  if (y !== null) dragY.value = Math.max(TOP_Y, Math.min(ROOT_Y, y));
}

function onDotTouchStart(e: TouchEvent) {
  e.stopPropagation();
  dragActive.current = true;
  isDragging.value = true;
  const y = clientToSvgY(e.touches[0].clientY);
  if (y !== null) dragY.value = Math.max(TOP_Y, Math.min(ROOT_Y, y));
}

function onMouseMove(e: MouseEvent) {
  if (!dragActive.current) return;
  const y = clientToSvgY(e.clientY);
  if (y !== null) {
    const clamped = Math.max(TOP_Y, Math.min(ROOT_Y, y));
    dragY.value = clamped;
    idx.value = yToSemitones(clamped) - 1;
  }
}

function onMouseUp(e: MouseEvent) {
  if (!dragActive.current) return;
  const y = clientToSvgY(e.clientY);
  if (y !== null) snapToSemitone(Math.max(TOP_Y, Math.min(ROOT_Y, y)));
  else { dragY.value = null; isDragging.value = false; dragActive.current = false; }
}

function onTouchMove(e: TouchEvent) {
  if (!dragActive.current) return;
  e.preventDefault();
  const y = clientToSvgY(e.touches[0].clientY);
  if (y !== null) {
    const clamped = Math.max(TOP_Y, Math.min(ROOT_Y, y));
    dragY.value = clamped;
    idx.value = yToSemitones(clamped) - 1;
  }
}

function onTouchEnd(e: TouchEvent) {
  if (!dragActive.current) return;
  const y = clientToSvgY(e.changedTouches[0].clientY);
  if (y !== null) snapToSemitone(Math.max(TOP_Y, Math.min(ROOT_Y, y)));
  else { dragY.value = null; isDragging.value = false; dragActive.current = false; }
}

onMounted(() => {
  window.addEventListener('mousemove', onMouseMove);
  window.addEventListener('mouseup', onMouseUp);
  window.addEventListener('touchmove', onTouchMove, { passive: false });
  window.addEventListener('touchend', onTouchEnd);
});
onUnmounted(() => {
  window.removeEventListener('mousemove', onMouseMove);
  window.removeEventListener('mouseup', onMouseUp);
  window.removeEventListener('touchmove', onTouchMove);
  window.removeEventListener('touchend', onTouchEnd);
});

function onCardTouchStart(e: TouchEvent) {
  if (dragActive.current) return;
  swipeTouchStart.current = e.touches[0].clientX;
}
function onCardTouchEnd(e: TouchEvent) {
  if (dragActive.current || swipeTouchStart.current === null) return;
  const delta = swipeTouchStart.current - e.changedTouches[0].clientX;
  if (Math.abs(delta) > 40) navigate(delta > 0 ? 'right' : 'left');
  swipeTouchStart.current = null;
}

function navigate(dir: 'left' | 'right') {
  if (animating.value || isDragging.value) return;
  const next = dir === 'right'
    ? Math.min(idx.value + 1, INTERVALS.length - 1)
    : Math.max(idx.value - 1, 0);
  if (next === idx.value) return;
  direction.value = dir;
  animating.value = true;
  visible.value = false;
  idx.value = next;
  setTimeout(() => {
    visible.value = true;
    setTimeout(() => { animating.value = false; }, 350);
  }, 200);
}

const SEMITONES_LIST = Array.from({ length: 12 }, (_, i) => i + 1);
</script>

<template>
  <div class="iv-card" @touchstart="onCardTouchStart" @touchend="onCardTouchEnd">
    <div class="iv-glow" :style="{ background: color }" />

    <!-- Header -->
    <div class="iv-header">
      <div class="iv-title">Intervals from C</div>
      <div class="iv-semitone">{{ interval.semitones }} {{ interval.semitones === 1 ? 'semitone' : 'semitones' }}</div>
    </div>

    <!-- Content -->
    <div class="iv-content">

      <!-- Pitch axis SVG -->
      <div class="iv-svg-wrap">
        <svg
          ref="svgEl"
          :width="SVG_W" :height="SVG_H"
          :viewBox="`0 0 ${SVG_W} ${SVG_H}`"
          style="display:block; touch-action:none"
        >
          <!-- Snap guides -->
          <line v-for="s in SEMITONES_LIST" :key="s"
            :x1="DOT_X - 8" :y1="intervalY(s)"
            :x2="DOT_X + 8" :y2="intervalY(s)"
            :stroke="s === interval.semitones ? color : 'rgba(255,255,255,0.08)'"
            :stroke-width="s === interval.semitones ? 1.5 : 1"
            style="transition: stroke 0.3s ease"
          />

          <!-- Axis line -->
          <line :x1="DOT_X" :y1="TOP_Y - DOT_R - 4" :x2="DOT_X" :y2="ROOT_Y + DOT_R + 4"
            stroke="rgba(255,255,255,0.07)" stroke-width="1.5"
          />

          <!-- Span line -->
          <line
            :x1="DOT_X" :y1="upperY" :x2="DOT_X" :y2="ROOT_Y - DOT_R"
            :stroke="color" stroke-width="2" stroke-dasharray="3 4" opacity="0.35"
            :style="isDragging ? {} : { transition: 'y1 0.45s cubic-bezier(0.34,1.2,0.64,1), stroke 0.3s ease' }"
          />

          <!-- Root dot -->
          <circle :cx="DOT_X" :cy="ROOT_Y" :r="DOT_R" fill="rgba(255,255,255,0.7)" />
          <circle :cx="DOT_X" :cy="ROOT_Y" :r="DOT_R * 0.35" fill="rgba(15,15,23,0.5)" />
          <text :x="DOT_X + DOT_R + 6" :y="ROOT_Y + 4"
            font-family="'DM Mono', monospace" font-size="10" fill="rgba(255,255,255,0.7)">C</text>

          <!-- Upper dot (draggable) -->
          <circle
            :cx="DOT_X" :cy="upperY" :r="DOT_R"
            :fill="color"
            :style="{
              filter: isDragging ? `drop-shadow(0 0 6px ${color})` : 'none',
              transform: isDragging ? 'scale(1.15)' : 'scale(1)',
              transformOrigin: `${DOT_X}px ${upperY}px`,
              transition: isDragging
                ? 'filter 0.15s ease, transform 0.15s ease'
                : 'cy 0.45s cubic-bezier(0.34,1.2,0.64,1), fill 0.3s ease, filter 0.15s ease, transform 0.15s ease',
              cursor: 'ns-resize',
            }"
            @mousedown="onMouseDown"
            @touchstart.stop="onDotTouchStart"
          />
          <circle
            :cx="DOT_X" :cy="upperY" :r="DOT_R * 0.35"
            fill="rgba(255,255,255,0.7)"
            :style="{
              pointerEvents: 'none',
              transition: isDragging ? 'none' : 'cy 0.45s cubic-bezier(0.34,1.2,0.64,1)',
            }"
          />

          <!-- Upper note label -->
          <text
            :x="DOT_X + DOT_R + 6" :y="upperY + 4"
            font-family="'DM Mono', monospace" font-size="10" :fill="color"
            :style="{
              pointerEvents: 'none',
              transition: isDragging ? 'none' : 'y 0.45s cubic-bezier(0.34,1.2,0.64,1), fill 0.3s ease',
            }"
          >{{ upperNote }}</text>
        </svg>
      </div>

      <!-- Text -->
      <div class="iv-text iv-fade-wrap" :class="animClass">
        <div class="iv-short" :style="{ color }">{{ interval.short }}</div>
        <div class="iv-name">{{ interval.name }}</div>
        <div class="iv-character">{{ interval.character }}</div>
      </div>
    </div>

    <!-- Nav -->
    <nav class="iv-nav">
      <button class="iv-arrow" :disabled="idx === 0" @click="navigate('left')">‹</button>
      <div class="iv-stepdots">
        <div
          v-for="(_, i) in INTERVALS" :key="i"
          class="iv-stepdot"
          :class="{ active: i === idx }"
          :style="i === idx ? { background: color } : {}"
        />
      </div>
      <button class="iv-arrow" :disabled="idx === INTERVALS.length - 1" @click="navigate('right')">›</button>
    </nav>
  </div>
</template>

<style scoped>
.iv-card {
  width: 100%;
  background: #0f0f17;
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 1.75rem 1.5rem 1.5rem;
  gap: 0;
  position: relative;
  overflow: hidden;
  user-select: none;
  box-sizing: border-box;
}

.iv-glow {
  position: absolute; top: -40px; left: 50%;
  transform: translateX(-50%);
  width: 180px; height: 180px;
  border-radius: 50%; pointer-events: none;
  filter: blur(55px); opacity: 0.32; transition: background 0.5s ease;
}

.iv-header {
  width: 100%; display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 1.25rem; z-index: 1;
}
.iv-title {
  font-family: 'DM Mono', monospace; font-size: 0.7rem;
  letter-spacing: 0.15em; text-transform: uppercase; color: #ffffff;
}
.iv-semitone {
  font-family: 'DM Mono', monospace; font-size: 0.7rem;
  letter-spacing: 0.08em; color: #ffffff; transition: opacity 0.2s;
}

.iv-content {
  display: flex; align-items: center; gap: 1.5rem;
  width: 100%; z-index: 1; margin-bottom: 1.25rem;
}
.iv-svg-wrap { flex-shrink: 0; cursor: ns-resize; }

.iv-text { flex: 1; display: flex; flex-direction: column; gap: 0.5rem; }
.iv-short {
  font-family: 'Cormorant Garamond', serif; font-weight: 300;
  font-size: 3.8rem; line-height: 1; letter-spacing: -0.02em; transition: color 0.3s ease;
}
.iv-name {
  font-family: 'DM Mono', monospace; font-size: 0.65rem;
  letter-spacing: 0.06em; color: #ffffff;
  text-transform: uppercase; line-height: 1.4;
}
.iv-character {
  font-family: system-ui, sans-serif;
  font-size: 0.82rem; line-height: 1.6; color: #ffffff; margin-top: 0.25rem;
}

.iv-nav { display: flex; align-items: center; gap: 1.25rem; margin-top: 0.5rem; z-index: 1; }
.iv-arrow {
  background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.15); color: #ffffff;
  width: 34px; height: 34px; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  cursor: pointer; font-size: 0.85rem; transition: all 0.2s ease;
}
.iv-arrow:hover:not(:disabled) { border-color: rgba(255,255,255,0.22); color: rgba(255,255,255,0.85); }
.iv-arrow:disabled { opacity: 0.2; cursor: default; }

.iv-stepdots { display: flex; gap: 4px; align-items: center; flex-wrap: wrap; max-width: 120px; justify-content: center; }
.iv-stepdot { width: 4px; height: 4px; border-radius: 50%; background: rgba(255,255,255,0.12); transition: all 0.3s ease; flex-shrink: 0; }
.iv-stepdot.active { width: 12px; border-radius: 2px; }

.iv-fade-wrap { transition: opacity 0.2s ease, transform 0.2s ease; }
.iv-fade-wrap.iv-exit-left   { opacity: 0; transform: translateX(-16px); }
.iv-fade-wrap.iv-exit-right  { opacity: 0; transform: translateX(16px); }
.iv-fade-wrap.iv-enter-right { animation: ivEnterRight 0.3s cubic-bezier(0.34,1.2,0.64,1) forwards; }
.iv-fade-wrap.iv-enter-left  { animation: ivEnterLeft  0.3s cubic-bezier(0.34,1.2,0.64,1) forwards; }

@keyframes ivEnterRight { from { opacity:0; transform:translateX(16px);  } to { opacity:1; transform:translateX(0); } }
@keyframes ivEnterLeft  { from { opacity:0; transform:translateX(-16px); } to { opacity:1; transform:translateX(0); } }

@media (prefers-reduced-motion: reduce) {
  .iv-fade-wrap, .iv-fade-wrap.iv-enter-right, .iv-fade-wrap.iv-enter-left { animation: none; transition: none; }
}
</style>
