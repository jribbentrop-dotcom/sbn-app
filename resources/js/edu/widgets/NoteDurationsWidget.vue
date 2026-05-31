<script setup lang="ts">
import { ref } from 'vue';

interface Duration {
  name: string;
  beats: number;
  fraction: number;
  symbol: string;
  beams: number;
  explanation: string;
}

const DURATIONS: Duration[] = [
  { name: 'Whole Note',     beats: 4,    fraction: 1,      symbol: 'whole',     beams: 0, explanation: 'Four beats. The longest common note value — fill an entire 4/4 bar with a single sound. Rarely seen in Jazz melody but common in long held chords.' },
  { name: 'Half Note',      beats: 2,    fraction: 0.5,    symbol: 'half',      beams: 0, explanation: 'Two beats. Half a whole note. Common in slow ballads and as held chord tones. Two half notes fill a 4/4 bar.' },
  { name: 'Quarter Note',   beats: 1,    fraction: 0.25,   symbol: 'quarter',   beams: 0, explanation: 'One beat — the basic pulse unit in most music. In 4/4 there are four quarter notes per bar. The foundation of all rhythm counting.' },
  { name: 'Eighth Note',    beats: 0.5,  fraction: 0.125,  symbol: 'eighth',    beams: 1, explanation: 'Half a beat. Two eighth notes equal one quarter. In swing feel, eighth notes are played unevenly — long-short. The heartbeat of Jazz phrasing.' },
  { name: 'Sixteenth Note', beats: 0.25, fraction: 0.0625, symbol: 'sixteenth', beams: 2, explanation: 'A quarter of a beat. Four sixteenths per quarter note. Common in funk, fast passages, and ornaments. In Bossa Nova, the right-hand guitar patterns often imply sixteenth subdivisions.' },
];

const BAR_W = 220;
const HEAD_RY = 5;
const HEAD_RX = 6.5;
const NOTE_H = 44;

function countInBar(fraction: number) { return Math.round(4 / (fraction * 4)); }

function beatLabel(beats: number) {
  if (beats === 1) return '1 beat';
  if (beats < 1) return `${beats * 4}/4 of a beat`;
  return `${beats} beats`;
}

const idx = ref(0);
const direction = ref<'left' | 'right' | null>(null);
const visible = ref(true);
const animating = ref(false);
let touchStart: number | null = null;

function nav(dir: 'left' | 'right') {
  if (animating.value) return;
  const next = dir === 'right'
    ? Math.min(idx.value + 1, DURATIONS.length - 1)
    : Math.max(idx.value - 1, 0);
  if (next === idx.value) return;
  direction.value = dir;
  animating.value = true;
  visible.value = false;
  setTimeout(() => {
    idx.value = next;
    visible.value = true;
    setTimeout(() => { animating.value = false; }, 350);
  }, 210);
}

function onTouchStart(e: TouchEvent) { touchStart = e.touches[0].clientX; }
function onTouchEnd(e: TouchEvent) {
  if (touchStart === null) return;
  const delta = touchStart - e.changedTouches[0].clientX;
  if (Math.abs(delta) > 40) nav(delta > 0 ? 'right' : 'left');
  touchStart = null;
}

function animClass() {
  if (!visible.value) return direction.value === 'right' ? 'nd-exit-left' : 'nd-exit-right';
  if (direction.value === 'right') return 'nd-enter-right';
  if (direction.value === 'left') return 'nd-enter-left';
  return '';
}

function notePositions(dur: Duration, count: number) {
  const noteW = (BAR_W - 20) / count;
  const noteY = 25;
  return Array.from({ length: count }, (_, i) => ({
    x: 18 + i * noteW + noteW / 2,
    y: noteY,
    stemX: 18 + i * noteW + noteW / 2 + HEAD_RX - 1,
    stemTop: noteY - 18,
    noteW,
    i,
  }));
}
</script>

<template>
  <div class="nd-card" @touchstart="onTouchStart" @touchend="onTouchEnd">
      <div class="nd-header">
        <div class="nd-label">Note Durations</div>
      </div>

      <div :class="['nd-content', animClass()]">
        <div class="nd-note-row">
          <!-- Big single note -->
          <svg width="100" height="100" viewBox="0 0 100 100">
            <!-- Whole note -->
            <ellipse v-if="DURATIONS[idx].symbol === 'whole'" cx="40" cy="70" :rx="HEAD_RX+2" :ry="HEAD_RY+1" fill="none" stroke="#ffffff" stroke-width="2.5"/>
            <!-- Half note -->
            <ellipse v-else-if="DURATIONS[idx].symbol === 'half'" cx="40" cy="70" :rx="HEAD_RX" :ry="HEAD_RY" fill="none" stroke="#ffffff" stroke-width="2.5" :transform="`rotate(-15,40,70)`"/>
            <!-- Filled note head -->
            <ellipse v-else cx="40" cy="70" :rx="HEAD_RX" :ry="HEAD_RY" fill="#ffffff" :transform="`rotate(-15,40,70)`"/>
            <!-- Stem -->
            <line v-if="DURATIONS[idx].symbol !== 'whole'" :x1="40+HEAD_RX-1" :y1="70-HEAD_RY+2" :x2="40+HEAD_RX-1" :y2="70-NOTE_H" stroke="#ffffff" stroke-width="2" stroke-linecap="round"/>
            <!-- Eighth flag -->
            <path v-if="DURATIONS[idx].symbol === 'eighth'" :d="`M ${40+HEAD_RX-1} ${70-NOTE_H} C ${40+HEAD_RX+17} ${70-NOTE_H+8} ${40+HEAD_RX+13} ${70-NOTE_H+18} ${40+HEAD_RX+1} ${70-NOTE_H+24}`" fill="none" stroke="#ffffff" stroke-width="2.5" stroke-linecap="round"/>
            <!-- Sixteenth flags -->
            <template v-if="DURATIONS[idx].symbol === 'sixteenth'">
              <path :d="`M ${40+HEAD_RX-1} ${70-NOTE_H} C ${40+HEAD_RX+17} ${70-NOTE_H+8} ${40+HEAD_RX+13} ${70-NOTE_H+18} ${40+HEAD_RX+1} ${70-NOTE_H+24}`" fill="none" stroke="#ffffff" stroke-width="2.5" stroke-linecap="round"/>
              <path :d="`M ${40+HEAD_RX-1} ${70-NOTE_H+10} C ${40+HEAD_RX+15} ${70-NOTE_H+18} ${40+HEAD_RX+11} ${70-NOTE_H+28} ${40+HEAD_RX+1} ${70-NOTE_H+34}`" fill="none" stroke="#ffffff" stroke-width="2.5" stroke-linecap="round"/>
            </template>
          </svg>

          <div>
            <div class="nd-title">{{ DURATIONS[idx].name }}</div>
            <div class="nd-meta">{{ beatLabel(DURATIONS[idx].beats) }}</div>
            <div class="nd-meta" style="margin-top:0.15rem">= {{ countInBar(DURATIONS[idx].fraction) }} per 4/4 bar</div>
          </div>
        </div>

        <!-- Bar view -->
        <div class="nd-bar-wrap">
          <div class="nd-bar-label">One bar of 4/4</div>
          <svg width="100%" :viewBox="`0 0 ${BAR_W} 50`" style="display:block">
            <line x1="10" y1="10" x2="10" y2="40" stroke="rgba(255,255,255,0.4)" stroke-width="2"/>
            <line :x1="BAR_W-10" y1="10" :x2="BAR_W-10" y2="40" stroke="rgba(255,255,255,0.4)" stroke-width="2"/>
            <line :x1="BAR_W-13" y1="10" :x2="BAR_W-13" y2="40" stroke="rgba(255,255,255,0.4)" stroke-width="1"/>
            <line x1="10" y1="25" :x2="BAR_W-10" y2="25" stroke="rgba(255,255,255,0.12)" stroke-width="0.75"/>

            <template v-for="n in notePositions(DURATIONS[idx], countInBar(DURATIONS[idx].fraction))" :key="n.i">
              <!-- Whole note head -->
              <ellipse v-if="DURATIONS[idx].symbol === 'whole'" :cx="n.x" :cy="n.y" :rx="HEAD_RX+1" :ry="HEAD_RY" fill="none" stroke="#f59e0b" stroke-width="1.8"/>
              <!-- Half note head -->
              <ellipse v-else-if="DURATIONS[idx].symbol === 'half'" :cx="n.x" :cy="n.y" :rx="HEAD_RX-1" :ry="HEAD_RY-1" fill="none" stroke="#f59e0b" stroke-width="1.8" :transform="`rotate(-15,${n.x},${n.y})`"/>
              <!-- Filled head -->
              <ellipse v-else :cx="n.x" :cy="n.y" :rx="HEAD_RX-1" :ry="HEAD_RY-1" fill="#f59e0b" :transform="`rotate(-15,${n.x},${n.y})`"/>
              <!-- Stem -->
              <line v-if="DURATIONS[idx].symbol !== 'whole'" :x1="n.stemX" :y1="n.y-HEAD_RY+1" :x2="n.stemX" :y2="n.stemTop" stroke="#f59e0b" stroke-width="1.5" stroke-linecap="round"/>
              <!-- Eighth beam -->
              <line v-if="DURATIONS[idx].symbol === 'eighth' && n.i < countInBar(DURATIONS[idx].fraction)-1"
                :x1="n.stemX" :y1="n.stemTop"
                :x2="notePositions(DURATIONS[idx], countInBar(DURATIONS[idx].fraction))[n.i+1].stemX" :y2="n.stemTop"
                stroke="#f59e0b" stroke-width="2.5"/>
              <!-- Sixteenth beams -->
              <template v-if="DURATIONS[idx].symbol === 'sixteenth' && n.i < countInBar(DURATIONS[idx].fraction)-1">
                <line :x1="n.stemX" :y1="n.stemTop" :x2="notePositions(DURATIONS[idx], countInBar(DURATIONS[idx].fraction))[n.i+1].stemX" :y2="n.stemTop" stroke="#f59e0b" stroke-width="2.5"/>
                <line :x1="n.stemX" :y1="n.stemTop+5" :x2="notePositions(DURATIONS[idx], countInBar(DURATIONS[idx].fraction))[n.i+1].stemX" :y2="n.stemTop+5" stroke="#f59e0b" stroke-width="2.5"/>
              </template>
            </template>
          </svg>
        </div>

        <div class="nd-explanation">{{ DURATIONS[idx].explanation }}</div>
      </div>

      <nav class="nd-nav">
        <button class="nd-arrow" @click="nav('left')" :disabled="idx === 0">‹</button>
        <div class="nd-stepdots">
          <div v-for="(_, i) in DURATIONS" :key="i" :class="['nd-stepdot', i === idx ? 'active' : '']" />
        </div>
        <button class="nd-arrow" @click="nav('right')" :disabled="idx === DURATIONS.length - 1">›</button>
      </nav>
  </div>
</template>

<style scoped>
.nd-card { width: 100%; background: #0f0f17; border-radius: 1.25rem; border: 1px solid rgba(255,255,255,0.06); display: flex; flex-direction: column; align-items: center; padding: 1.75rem 1.5rem 1.5rem; gap: 1.25rem; user-select: none; }
.nd-header { width: 100%; display: flex; align-items: center; justify-content: space-between; }
.nd-label { font-family: 'DM Mono', monospace; font-size: 0.65rem; letter-spacing: 0.15em; text-transform: uppercase; color: #ffffff; }
.nd-content { width: 100%; display: flex; flex-direction: column; align-items: center; gap: 1rem; transition: opacity 0.2s ease, transform 0.2s ease; }
.nd-note-row { display: flex; align-items: center; gap: 1.25rem; width: 100%; }
.nd-title { font-family: 'Cormorant Garamond', serif; font-size: 2rem; font-weight: 300; color: #ffffff; line-height: 1; }
.nd-meta { font-family: 'DM Mono', monospace; font-size: 0.6rem; letter-spacing: 0.1em; color: #ffffff; margin-top: 0.35rem; }
.nd-bar-wrap { width: 100%; background: rgba(255,255,255,0.02); border: 1px solid rgba(255,255,255,0.06); border-radius: 0.5rem; padding: 0.5rem; }
.nd-bar-label { font-family: 'DM Mono', monospace; font-size: 0.55rem; letter-spacing: 0.12em; text-transform: uppercase; color: #ffffff; margin-bottom: 0.25rem; }
.nd-explanation { font-family: system-ui, sans-serif; font-size: 0.85rem; line-height: 1.6; color: #ffffff; min-height: 4rem; }
.nd-nav { display: flex; align-items: center; gap: 1.25rem; }
.nd-arrow { background: none; border: 1px solid rgba(255,255,255,0.15); color: #ffffff; width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; cursor: pointer; font-size: 0.85rem; transition: all 0.2s ease; }
.nd-arrow:hover:not(:disabled) { border-color: rgba(255,255,255,0.25); color: rgba(255,255,255,0.85); }
.nd-arrow:disabled { opacity: 0.2; cursor: default; }
.nd-stepdots { display: flex; gap: 4px; align-items: center; }
.nd-stepdot { width: 5px; height: 5px; border-radius: 50%; background: rgba(255,255,255,0.12); transition: all 0.3s ease; }
.nd-stepdot.active { width: 14px; border-radius: 3px; background: #f59e0b; }
.nd-enter-right { animation: ndEnterRight 0.32s cubic-bezier(0.34,1.2,0.64,1) forwards; }
.nd-enter-left  { animation: ndEnterLeft  0.32s cubic-bezier(0.34,1.2,0.64,1) forwards; }
.nd-exit-left   { opacity: 0; transform: translateX(-24px); }
.nd-exit-right  { opacity: 0; transform: translateX(24px); }
@keyframes ndEnterRight { from { opacity: 0; transform: translateX(24px); } to { opacity: 1; transform: translateX(0); } }
@keyframes ndEnterLeft  { from { opacity: 0; transform: translateX(-24px); } to { opacity: 1; transform: translateX(0); } }
</style>
