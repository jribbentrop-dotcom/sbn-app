<script setup lang="ts">
import { ref } from 'vue';

interface TimeSig {
  top: number;
  bottom: number;
  name: string;
  feel: string;
  beats: number[];
  strong: number[];
  explanation: string;
}

const TIME_SIGNATURES: TimeSig[] = [
  { top: 2, bottom: 4, name: 'Two-Four', feel: 'March / Samba', beats: [1,2], strong: [0], explanation: 'Two quarter-note beats per bar. The heartbeat of traditional Brazilian music — Choro, Baião, and the underlying pulse of Bossa Nova. Feels forward, direct, one-two one-two.' },
  { top: 3, bottom: 4, name: 'Three-Four', feel: 'Waltz / Ballad', beats: [1,2,3], strong: [0], explanation: 'Three quarter-note beats per bar. The waltz feel — one strong beat followed by two lighter ones. Used in Jazz ballads and some Bossa Nova compositions for a lilting, circular quality.' },
  { top: 4, bottom: 4, name: 'Four-Four', feel: 'Swing / Standard', beats: [1,2,3,4], strong: [0,2], explanation: 'Four quarter-note beats per bar. The standard for Jazz and swing. Beats 1 and 3 are strong, 2 and 4 carry the backbeat. Most Jazz standards, including many Bossa Novas played in the US tradition, use 4/4.' },
  { top: 6, bottom: 8, name: 'Six-Eight', feel: 'Afro-Cuban / Compound', beats: [1,2,3,4,5,6], strong: [0,3], explanation: 'Six eighth-note beats per bar, felt in two groups of three. The compound feel creates a rolling, triplet-like pulse. Common in Afro-Cuban styles, some Samba variations, and slow Bossa ballads where the triplet feel is made explicit.' },
  { top: 5, bottom: 4, name: 'Five-Four', feel: 'Asymmetric', beats: [1,2,3,4,5], strong: [0,2], explanation: "Five beats per bar — asymmetric, restless, always slightly off-balance. Rare in traditional Bossa but found in modal Jazz and progressive Brazilian music. Dave Brubeck's 'Take Five' made it famous." },
  { top: 7, bottom: 8, name: 'Seven-Eight', feel: 'Asymmetric / Balkan', beats: [1,2,3,4,5,6,7], strong: [0,3,5], explanation: 'Seven eighth-note beats, usually grouped 2+2+3 or 3+2+2. Extreme asymmetry — found in advanced Jazz compositions, Brazilian progressive music, and Balkan-influenced arrangements.' },
];

const BEAT_SIZE = 18;
const BEAT_GAP = 10;

const idx = ref(0);
const direction = ref<'left' | 'right' | null>(null);
const visible = ref(true);
const animating = ref(false);
let touchStart: number | null = null;

const sig = () => TIME_SIGNATURES[idx.value];

const totalBeatsW = () => sig().beats.length * (BEAT_SIZE + BEAT_GAP) - BEAT_GAP;

function nav(dir: 'left' | 'right') {
  if (animating.value) return;
  const next = dir === 'right'
    ? (idx.value + 1) % TIME_SIGNATURES.length
    : (idx.value - 1 + TIME_SIGNATURES.length) % TIME_SIGNATURES.length;
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
  if (!visible.value) return direction.value === 'right' ? 'ts-exit-left' : 'ts-exit-right';
  if (direction.value === 'right') return 'ts-enter-right';
  if (direction.value === 'left') return 'ts-enter-left';
  return '';
}
</script>

<template>
  <div class="ts-card" @touchstart="onTouchStart" @touchend="onTouchEnd">
      <div class="ts-glow" />

      <div class="ts-header">
        <div class="ts-label">Time Signature</div>
        <div class="ts-feel-pill">{{ sig().feel }}</div>
      </div>

      <div :class="['ts-content', animClass()]">
        <div class="ts-fraction">
          <div class="ts-num">{{ sig().top }}</div>
          <div class="ts-divider" />
          <div class="ts-denom">{{ sig().bottom }}</div>
        </div>
        <div class="ts-right">
          <div class="ts-name">{{ sig().name }}</div>
          <div class="ts-explanation">{{ sig().explanation }}</div>
        </div>
      </div>

      <div class="ts-beats-wrap">
        <div class="ts-beats-label">Beat pattern</div>
        <svg width="100%" :viewBox="`0 0 ${Math.max(totalBeatsW(), 10)} ${BEAT_SIZE + 16}`" style="overflow:visible">
          <g v-for="(b, i) in sig().beats" :key="i">
            <rect
              :x="i * (BEAT_SIZE + BEAT_GAP)"
              :y="sig().strong.includes(i) ? 0 : 5"
              :width="BEAT_SIZE"
              :height="sig().strong.includes(i) ? BEAT_SIZE : BEAT_SIZE - 10"
              rx="3"
              :fill="sig().strong.includes(i) ? '#f59e0b' : 'rgba(255,255,255,0.12)'"
            />
            <text
              :x="i * (BEAT_SIZE + BEAT_GAP) + BEAT_SIZE / 2"
              :y="BEAT_SIZE + 12"
              text-anchor="middle"
              font-family="'DM Mono', monospace"
              font-size="7"
              :fill="sig().strong.includes(i) ? 'rgba(245,158,11,0.6)' : 'rgba(255,255,255,0.2)'"
            >{{ b }}</text>
          </g>
        </svg>
      </div>

      <nav class="ts-nav">
        <button class="ts-arrow" @click="nav('left')">‹</button>
        <div class="ts-stepdots">
          <div v-for="(_, i) in TIME_SIGNATURES" :key="i" :class="['ts-stepdot', i === idx ? 'active' : '']" />
        </div>
        <button class="ts-arrow" @click="nav('right')">›</button>
      </nav>
  </div>
</template>

<style scoped>
.ts-card { width: 100%; background: #0f0f17; border-radius: 1.25rem; border: 1px solid rgba(255,255,255,0.06); display: flex; flex-direction: column; align-items: center; padding: 1.75rem 1.5rem 1.5rem; gap: 1.25rem; position: relative; overflow: hidden; user-select: none; }
.ts-glow { position: absolute; top: -60px; left: 50%; transform: translateX(-50%); width: 200px; height: 200px; border-radius: 50%; pointer-events: none; filter: blur(60px); opacity: 0.15; background: #f59e0b; }
.ts-header { width: 100%; display: flex; align-items: center; justify-content: space-between; z-index: 1; }
.ts-label { font-family: 'DM Mono', monospace; font-size: 0.65rem; letter-spacing: 0.15em; text-transform: uppercase; color: #ffffff; }
.ts-feel-pill { font-family: 'DM Mono', monospace; font-size: 0.6rem; letter-spacing: 0.1em; text-transform: uppercase; padding: 0.22rem 0.65rem; border-radius: 999px; border: 1px solid rgba(245,158,11,0.3); color: #f59e0b; background: rgba(245,158,11,0.08); }
.ts-content { display: flex; align-items: center; gap: 1.75rem; z-index: 1; width: 100%; transition: opacity 0.2s ease, transform 0.2s ease; }
.ts-fraction { display: flex; flex-direction: column; align-items: center; gap: 2px; flex-shrink: 0; }
.ts-num { font-family: 'Cormorant Garamond', serif; font-weight: 300; font-size: 4.5rem; line-height: 1; color: #ffffff; letter-spacing: -0.02em; }
.ts-divider { width: 36px; height: 1.5px; background: rgba(255,255,255,0.2); border-radius: 1px; }
.ts-denom { font-family: 'Cormorant Garamond', serif; font-weight: 300; font-size: 4.5rem; line-height: 1; color: rgba(255,255,255,0.4); letter-spacing: -0.02em; }
.ts-right { flex: 1; display: flex; flex-direction: column; gap: 0.6rem; }
.ts-name { font-family: 'DM Mono', monospace; font-size: 0.65rem; letter-spacing: 0.1em; text-transform: uppercase; color: #ffffff; }
.ts-explanation { font-family: system-ui, sans-serif; font-size: 0.85rem; line-height: 1.6; color: #ffffff; min-height: 5rem; }
.ts-beats-wrap { z-index: 1; display: flex; flex-direction: column; align-items: center; gap: 0.5rem; width: 100%; }
.ts-beats-label { font-family: 'DM Mono', monospace; font-size: 0.55rem; letter-spacing: 0.12em; text-transform: uppercase; color: #ffffff; align-self: flex-start; }
.ts-nav { display: flex; align-items: center; gap: 1.25rem; z-index: 1; }
.ts-arrow { background: none; border: 1px solid rgba(255,255,255,0.15); color: #ffffff; width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; cursor: pointer; font-size: 0.85rem; transition: all 0.2s ease; }
.ts-arrow:hover { border-color: rgba(255,255,255,0.25); color: rgba(255,255,255,0.85); }
.ts-stepdots { display: flex; gap: 4px; align-items: center; }
.ts-stepdot { width: 5px; height: 5px; border-radius: 50%; background: rgba(255,255,255,0.12); transition: all 0.3s ease; }
.ts-stepdot.active { width: 14px; border-radius: 3px; background: #f59e0b; }
.ts-enter-right { animation: tsEnterRight 0.32s cubic-bezier(0.34,1.2,0.64,1) forwards; }
.ts-enter-left  { animation: tsEnterLeft  0.32s cubic-bezier(0.34,1.2,0.64,1) forwards; }
.ts-exit-left   { opacity: 0; transform: translateX(-24px); }
.ts-exit-right  { opacity: 0; transform: translateX(24px); }
@keyframes tsEnterRight { from { opacity: 0; transform: translateX(24px); } to { opacity: 1; transform: translateX(0); } }
@keyframes tsEnterLeft  { from { opacity: 0; transform: translateX(-24px); } to { opacity: 1; transform: translateX(0); } }
@media (prefers-reduced-motion: reduce) { .ts-enter-right, .ts-enter-left { animation: none; } }
</style>
