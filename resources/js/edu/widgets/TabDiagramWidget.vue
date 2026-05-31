<script setup lang="ts">
import { ref, onUnmounted } from 'vue';

interface NoteSpec { string: number; fret: number | null; }

const AM_NOTES: NoteSpec[] = [
  { string: 0, fret: null },
  { string: 1, fret: 0 },
  { string: 2, fret: 2 },
  { string: 3, fret: 2 },
  { string: 4, fret: 1 },
  { string: 5, fret: 0 },
];

const PHASE_LABELS = ['Fretboard', 'Tab', 'Chord Diagram'];
const PHASE_DURATION = 1800;

// Horizontal layout constants
const H_W = 240, H_H = 90;
const H_PAD_LEFT = 28, H_PAD_RIGHT = 12, H_PAD_TOP = 12, H_PAD_BOTTOM = 12;
const N_STRINGS = 6, N_FRETS = 4;
const H_STRING_GAP = (H_H - H_PAD_TOP - H_PAD_BOTTOM) / (N_STRINGS - 1);
const H_FRET_GAP = (H_W - H_PAD_LEFT - H_PAD_RIGHT) / N_FRETS;
const DOT_R = 11;

// Vertical layout constants
const V_W = 90, V_H = 110, V_PAD_TOP = 28, V_PAD_LEFT = 12, V_PAD_RIGHT = 12;
const V_STRING_GAP = (V_W - V_PAD_LEFT - V_PAD_RIGHT) / (N_STRINGS - 1);
const V_FRET_GAP = (V_H - V_PAD_TOP - 12) / N_FRETS;

function hStringY(s: number) { return H_PAD_TOP + (N_STRINGS - 1 - s) * H_STRING_GAP; }
function hFretX(fret: number) { return H_PAD_LEFT + (fret - 0.5) * H_FRET_GAP; }
function vStringX(s: number) { return V_PAD_LEFT + s * V_STRING_GAP; }
function vFretY(fret: number) { return V_PAD_TOP + (fret - 0.5) * V_FRET_GAP; }

const frettedNotes = AM_NOTES.filter(n => n.fret !== null && n.fret > 0);
const mutedNotes   = AM_NOTES.filter(n => n.fret === null);
const openNotes    = AM_NOTES.filter(n => n.fret === 0);

const phase = ref(0);
const auto  = ref(true);
const animKey = ref(0);
let timer: ReturnType<typeof setTimeout> | null = null;

function scheduleNext() {
  if (!auto.value) return;
  timer = setTimeout(() => {
    if (phase.value < PHASE_LABELS.length - 1) {
      phase.value++;
      scheduleNext();
    } else {
      auto.value = false;
    }
  }, PHASE_DURATION);
}
scheduleNext();

function replay() {
  if (timer) clearTimeout(timer);
  phase.value = 0;
  animKey.value++;
  auto.value = true;
  scheduleNext();
}

function goTo(i: number) {
  if (timer) clearTimeout(timer);
  auto.value = false;
  phase.value = i;
}

onUnmounted(() => { if (timer) clearTimeout(timer); });
</script>

<template>
  <div class="td-card">
    <!-- Header -->
    <div class="td-header">
      <div class="td-label">Tab &amp; Diagrams</div>
      <button class="td-replay" @click="replay" title="Replay">↺</button>
    </div>

    <!-- Pills -->
    <div class="td-pills">
      <button
        v-for="(label, i) in PHASE_LABELS"
        :key="label"
        :class="['td-pill', phase === i ? 'active' : '']"
        @click="goTo(i)"
      >{{ label }}</button>
    </div>

    <!-- Stage -->
    <div class="td-stage">
      <!-- Phase 0 + 1 — horizontal fretboard / tab -->
      <div :class="['td-h-wrap', phase < 2 ? 'visible' : 'hidden']">
        <svg :key="`h-${animKey}`" :width="H_W + 20" :height="H_H + 20" :viewBox="`-10 -10 ${H_W + 20} ${H_H + 20}`">
          <!-- TAB label -->
          <g v-if="phase === 1" class="fade-in">
            <text v-for="(l, i) in ['T','A','B']" :key="l"
              x="-2" :y="H_PAD_TOP + i * (H_STRING_GAP * 1.8) + 4"
              text-anchor="middle" font-family="'DM Mono', monospace" font-size="7"
              fill="rgba(255,255,255,0.3)"
            >{{ l }}</text>
          </g>

          <!-- Nut -->
          <line :x1="H_PAD_LEFT" :y1="H_PAD_TOP" :x2="H_PAD_LEFT" :y2="H_H - H_PAD_BOTTOM"
            stroke="rgba(255,255,255,0.5)" stroke-width="3" stroke-linecap="round"/>

          <!-- Fret lines -->
          <line v-for="i in N_FRETS + 1" :key="`fret-${i}`"
            :x1="H_PAD_LEFT + (i - 1) * H_FRET_GAP" :y1="H_PAD_TOP"
            :x2="H_PAD_LEFT + (i - 1) * H_FRET_GAP" :y2="H_H - H_PAD_BOTTOM"
            stroke="rgba(255,255,255,0.1)" stroke-width="1"
            :style="{ opacity: phase === 1 ? 0 : 1, transition: 'opacity 0.5s ease' }"/>

          <!-- String lines -->
          <line v-for="s in N_STRINGS" :key="`str-${s}`"
            :x1="H_PAD_LEFT" :y1="hStringY(s - 1)"
            :x2="H_W - H_PAD_RIGHT" :y2="hStringY(s - 1)"
            stroke="rgba(255,255,255,0.2)"
            :stroke-width="(s - 1) < 2 ? 1.6 : (s - 1) < 4 ? 1.1 : 0.75"/>

          <!-- Mute markers -->
          <text v-for="(n, i) in mutedNotes" :key="`mute-${i}`"
            :x="H_PAD_LEFT - 10" :y="hStringY(n.string) + 4"
            text-anchor="middle" font-family="'DM Mono', monospace" font-size="10"
            fill="rgba(255,255,255,0.25)"
          >×</text>

          <!-- Fretted dots -->
          <g v-for="(n, i) in frettedNotes" :key="`dot-${i}`">
            <circle :cx="hFretX(n.fret as number)" :cy="hStringY(n.string)" :r="DOT_R"
              :fill="phase === 1 ? '#f59e0b' : 'rgba(255,255,255,0.85)'"
              style="transition: fill 0.4s ease"/>
            <text v-if="phase === 1"
              :key="`num-${i}-${animKey}-${phase}`"
              :x="hFretX(n.fret as number)" :y="hStringY(n.string) + 4"
              text-anchor="middle" font-family="'DM Mono', monospace" font-size="10" font-weight="400"
              fill="#000000" class="num-pop"
            >{{ n.fret }}</text>
          </g>

          <!-- Open string circles -->
          <circle v-for="(n, i) in openNotes" :key="`open-c-${i}`"
            :cx="H_PAD_LEFT - 10" :cy="hStringY(n.string)" :r="DOT_R * 0.6"
            fill="none"
            :stroke="phase === 1 ? '#f59e0b' : 'rgba(255,255,255,0.5)'"
            stroke-width="1.5"
            style="transition: stroke 0.4s ease"/>

          <!-- Open string numbers in tab mode -->
          <text v-if="phase === 1" v-for="(n, i) in openNotes" :key="`open-num-${i}`"
            :x="H_PAD_LEFT - 10" :y="hStringY(n.string) + 4"
            text-anchor="middle" font-family="'DM Mono', monospace" font-size="10"
            fill="#f59e0b" class="num-pop"
          >0</text>
        </svg>
      </div>

      <!-- Phase 2 — vertical chord diagram -->
      <div :class="['td-v-wrap', phase === 2 ? 'visible' : 'hidden']">
        <div style="display:flex;flex-direction:column;align-items:center;gap:0.5rem">
          <div class="td-chord-name" :style="{ opacity: phase === 2 ? 1 : 0 }">Am</div>

          <svg :width="V_W + 20" :height="V_H + 10" :viewBox="`-10 0 ${V_W + 20} ${V_H + 10}`">
            <!-- Nut -->
            <line :x1="V_PAD_LEFT" :y1="V_PAD_TOP" :x2="V_W - V_PAD_RIGHT" :y2="V_PAD_TOP"
              stroke="rgba(255,255,255,0.6)" stroke-width="3.5" stroke-linecap="round"/>

            <!-- Fret lines -->
            <line v-for="i in N_FRETS + 1" :key="`vfret-${i}`"
              :x1="V_PAD_LEFT" :y1="V_PAD_TOP + (i - 1) * V_FRET_GAP"
              :x2="V_W - V_PAD_RIGHT" :y2="V_PAD_TOP + (i - 1) * V_FRET_GAP"
              stroke="rgba(255,255,255,0.1)" stroke-width="1"/>

            <!-- String lines -->
            <line v-for="s in N_STRINGS" :key="`vstr-${s}`"
              :x1="vStringX(s - 1)" :y1="V_PAD_TOP"
              :x2="vStringX(s - 1)" :y2="V_PAD_TOP + N_FRETS * V_FRET_GAP"
              stroke="rgba(255,255,255,0.2)"
              :stroke-width="(s - 1) === 0 || (s - 1) === 5 ? 0.75 : 1"/>

            <!-- String labels -->
            <text v-for="(l, s) in ['E','A','D','G','B','e']" :key="`vlabel-${s}`"
              :x="vStringX(s)" :y="V_PAD_TOP - 8"
              text-anchor="middle" font-family="'DM Mono', monospace" font-size="7"
              fill="rgba(255,255,255,0.25)"
            >{{ l }}</text>

            <!-- Mute markers -->
            <text v-for="(n, i) in mutedNotes" :key="`vmute-${i}`"
              :x="vStringX(n.string)" :y="V_PAD_TOP - 14"
              text-anchor="middle" font-family="'DM Mono', monospace" font-size="9"
              fill="rgba(255,255,255,0.3)"
            >×</text>

            <!-- Open circles -->
            <circle v-for="(n, i) in openNotes" :key="`vopen-${i}`"
              :cx="vStringX(n.string)" :cy="V_PAD_TOP - 14" r="4"
              fill="none" stroke="rgba(255,255,255,0.4)" stroke-width="1.5"/>

            <!-- Fretted dots -->
            <g v-for="(n, i) in frettedNotes" :key="`vdot-${i}`"
              class="num-pop"
              :style="{ transformOrigin: `${vStringX(n.string)}px ${vFretY(n.fret as number)}px` }"
            >
              <circle :cx="vStringX(n.string)" :cy="vFretY(n.fret as number)" :r="DOT_R - 2" fill="#f59e0b"/>
            </g>
          </svg>
        </div>
      </div>
    </div>

    <!-- Explanation -->
    <div class="td-explanation">
      <template v-if="phase === 0">A guitar neck — 6 strings, frets running left to right. Dots show where to place your fingers.</template>
      <template v-else-if="phase === 1">Tab reads the same neck from above. Each line is a string, each number is a fret. Simple.</template>
      <template v-else>Rotate 90° and you have a chord diagram — strings now run top to bottom, frets left to right. Same chord, different view.</template>
    </div>
  </div>
</template>

<style scoped>
.td-card { width: 100%; background: #0f0f17; border-radius: 1.25rem; border: 1px solid rgba(255,255,255,0.06); display: flex; flex-direction: column; align-items: center; padding: 1.75rem 1.5rem 1.5rem; gap: 1.25rem; user-select: none; }
.td-header { width: 100%; display: flex; align-items: center; justify-content: space-between; }
.td-label { font-family: 'DM Mono', monospace; font-size: 0.65rem; letter-spacing: 0.15em; text-transform: uppercase; color: #ffffff; }
.td-replay { background: none; border: 1px solid rgba(255,255,255,0.15); color: #ffffff; width: 28px; height: 28px; border-radius: 50%; cursor: pointer; font-size: 0.75rem; display: flex; align-items: center; justify-content: center; transition: all 0.2s ease; }
.td-replay:hover { border-color: rgba(255,255,255,0.25); color: rgba(255,255,255,0.85); }
.td-stage { width: 100%; display: flex; align-items: center; justify-content: center; min-height: 130px; position: relative; }
.td-h-wrap { position: absolute; display: flex; align-items: center; justify-content: center; transition: opacity 0.6s ease, transform 0.8s cubic-bezier(0.65,0,0.35,1); }
.td-h-wrap.hidden { opacity: 0; pointer-events: none; transform: rotate(-90deg) scale(0.7); }
.td-h-wrap.visible { opacity: 1; transform: rotate(0deg) scale(1); }
.td-v-wrap { position: absolute; display: flex; align-items: center; justify-content: center; transition: opacity 0.6s ease, transform 0.8s cubic-bezier(0.65,0,0.35,1); }
.td-v-wrap.hidden { opacity: 0; pointer-events: none; transform: rotate(90deg) scale(0.7); }
.td-v-wrap.visible { opacity: 1; transform: rotate(0deg) scale(1); }
.td-chord-name { font-family: 'Cormorant Garamond', serif; font-weight: 300; font-size: 2.2rem; line-height: 1; color: #ffffff; transition: opacity 0.5s ease; text-align: center; }
.td-explanation { font-family: system-ui, sans-serif; font-size: 0.85rem; line-height: 1.6; color: #ffffff; min-height: 2.8rem; }
.td-pills { display: flex; gap: 4px; }
.td-pill { font-family: 'DM Mono', monospace; font-size: 0.6rem; letter-spacing: 0.08em; padding: 0.22rem 0.6rem; border-radius: 999px; border: 1px solid rgba(255,255,255,0.12); background: transparent; color: #ffffff; cursor: pointer; transition: all 0.2s ease; }
.td-pill:hover { border-color: rgba(255,255,255,0.25); color: rgba(255,255,255,0.7); }
.td-pill.active { background: rgba(255,255,255,0.08); color: #ffffff; border-color: rgba(255,255,255,0.22); }
@keyframes numPop { 0% { transform: scale(0); opacity: 0; } 65% { transform: scale(1.25); opacity: 1; } 100% { transform: scale(1); opacity: 1; } }
.num-pop { animation: numPop 0.38s cubic-bezier(0.34,1.2,0.64,1) forwards; transform-box: fill-box; transform-origin: center; }
@keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
.fade-in { animation: fadeIn 0.5s ease forwards; }
@media (prefers-reduced-motion: reduce) { .td-h-wrap, .td-v-wrap { transition: opacity 0.3s ease; transform: none !important; } .num-pop { animation: none; } }
</style>
