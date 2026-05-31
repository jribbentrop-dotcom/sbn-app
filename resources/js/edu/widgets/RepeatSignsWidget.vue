<script setup lang="ts">
import { ref } from 'vue';

interface Concept {
  id: string;
  title: string;
  subtitle: string;
  explanation: string;
}

const CONCEPTS: Concept[] = [
  { id: 'repeat',   title: 'Repeat Signs',  subtitle: 'Play again from the top',      explanation: 'The double barline with dots marks a section to be repeated. Play through once, then return to the opening repeat sign (or the beginning) and play it again. Fundamental to song forms — verse, chorus, A section.' },
  { id: 'volta1',   title: 'First Ending',  subtitle: '1st volta bracket',            explanation: 'On the repeat, play the first ending the first time through, then skip it on the repeat. The bracket above the staff marks which bars belong to the first ending only.' },
  { id: 'volta2',   title: 'Second Ending', subtitle: '2nd volta bracket',            explanation: "After skipping the first ending on the repeat, jump directly to the second ending. First time: play bars under '1.' Second time: skip '1.' and play bars under '2.' Common in AABA and verse-chorus forms." },
  { id: 'dalSegno', title: 'D.S. al Coda',  subtitle: 'Dal Segno — return to sign',   explanation: "Dal Segno (D.S.) means 'from the sign' — return to the 𝄋 symbol and play again. Al Coda means 'to the coda' — when you reach the coda symbol (𝄌) the second time, jump to the coda section at the end." },
  { id: 'daCapo',   title: 'D.C. al Fine',  subtitle: 'Da Capo — from the top',       explanation: "Da Capo (D.C.) means 'from the head' — go back to the very beginning. Al Fine means play until the word Fine marks the end. Common in jazz lead sheets: play through, D.C. al Fine, end at Fine." },
];

const STAFF_W = 240;
const STAFF_LEFT = 10;
const STAFF_RIGHT = STAFF_W - 10;

const idx = ref(0);
const visible = ref(true);
const direction = ref<'left' | 'right' | null>(null);
const animating = ref(false);
let touchStart: number | null = null;

function nav(dir: 'left' | 'right') {
  if (animating.value) return;
  const next = dir === 'right'
    ? (idx.value + 1) % CONCEPTS.length
    : (idx.value - 1 + CONCEPTS.length) % CONCEPTS.length;
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
  if (!visible.value) return direction.value === 'right' ? 'rs-exit-left' : 'rs-exit-right';
  if (direction.value === 'right') return 'rs-enter-right';
  if (direction.value === 'left') return 'rs-enter-left';
  return '';
}

// Staff lines helper — returns array of y positions
function staffLines(yBase: number) {
  return Array.from({ length: 5 }, (_, i) => yBase + i * 8);
}
</script>

<template>
  <div class="rs-card" @touchstart="onTouchStart" @touchend="onTouchEnd">
      <div class="rs-header">
        <div class="rs-label">Repeat Signs</div>
        <div class="rs-step">{{ idx + 1 }} / {{ CONCEPTS.length }}</div>
      </div>

      <div :class="['rs-content', animClass()]">
        <div>
          <div class="rs-title">{{ CONCEPTS[idx].title }}</div>
          <div class="rs-subtitle">{{ CONCEPTS[idx].subtitle }}</div>
        </div>

        <!-- Diagram area -->
        <div class="rs-diagram">
          <!-- Repeat barlines -->
          <svg v-if="CONCEPTS[idx].id === 'repeat'" width="100%" :viewBox="`0 0 ${STAFF_W} 60`" style="display:block">
            <line v-for="y in staffLines(10)" :key="y" :x1="STAFF_LEFT" :y1="y" :x2="STAFF_RIGHT" :y2="y" stroke="rgba(255,255,255,0.2)" stroke-width="0.75"/>
            <line x1="40" y1="10" x2="40" y2="42" stroke="rgba(255,255,255,0.3)" stroke-width="1"/>
            <line x1="43" y1="10" x2="43" y2="42" stroke="rgba(255,255,255,0.7)" stroke-width="2.5"/>
            <circle cx="47" cy="22" r="2.5" fill="#f59e0b"/>
            <circle cx="47" cy="30" r="2.5" fill="#f59e0b"/>
            <line :x1="STAFF_RIGHT-40" y1="10" :x2="STAFF_RIGHT-40" y2="42" stroke="rgba(255,255,255,0.3)" stroke-width="1"/>
            <line :x1="STAFF_RIGHT-43" y1="10" :x2="STAFF_RIGHT-43" y2="42" stroke="rgba(255,255,255,0.7)" stroke-width="2.5"/>
            <circle :cx="STAFF_RIGHT-47" cy="22" r="2.5" fill="#f59e0b"/>
            <circle :cx="STAFF_RIGHT-47" cy="30" r="2.5" fill="#f59e0b"/>
            <path :d="`M ${STAFF_W/2} 54 L ${STAFF_W/2-30} 54`" stroke="#f59e0b" stroke-width="1.5" fill="none" stroke-dasharray="3 3" opacity="0.5"/>
            <text :x="STAFF_W/2+5" y="57" font-family="'DM Mono', monospace" font-size="7" fill="rgba(245,158,11,0.5)">repeat</text>
          </svg>

          <!-- Volta 1 -->
          <svg v-else-if="CONCEPTS[idx].id === 'volta1'" width="100%" :viewBox="`0 0 ${STAFF_W} 75`" style="display:block">
            <line v-for="y in staffLines(28)" :key="y" :x1="STAFF_LEFT" :y1="y" :x2="STAFF_RIGHT" :y2="y" stroke="rgba(255,255,255,0.2)" stroke-width="0.75"/>
            <line :x1="STAFF_LEFT" y1="28" :x2="STAFF_LEFT" y2="60" stroke="rgba(255,255,255,0.3)" stroke-width="1"/>
            <line x1="100" y1="28" x2="100" y2="60" stroke="rgba(255,255,255,0.3)" stroke-width="1"/>
            <line :x1="STAFF_RIGHT-10" y1="28" :x2="STAFF_RIGHT-10" y2="60" stroke="rgba(255,255,255,0.3)" stroke-width="1"/>
            <line :x1="STAFF_RIGHT-13" y1="28" :x2="STAFF_RIGHT-13" y2="60" stroke="rgba(255,255,255,0.7)" stroke-width="2.5"/>
            <circle :cx="STAFF_RIGHT-17" cy="38" r="2.5" fill="#f59e0b"/>
            <circle :cx="STAFF_RIGHT-17" cy="46" r="2.5" fill="#f59e0b"/>
            <line :x1="STAFF_LEFT" y1="22" x2="100" y2="22" stroke="#f59e0b" stroke-width="1.5"/>
            <line :x1="STAFF_LEFT" y1="22" :x2="STAFF_LEFT" y2="28" stroke="#f59e0b" stroke-width="1.5"/>
            <line x1="100" y1="22" x2="100" y2="28" stroke="#f59e0b" stroke-width="1.5"/>
            <text :x="STAFF_LEFT+6" y="20" font-family="'DM Mono', monospace" font-size="8" fill="#f59e0b">1.</text>
            <text x="20" y="74" font-family="'DM Mono', monospace" font-size="7" fill="rgba(245,158,11,0.4)">1st time → play</text>
          </svg>

          <!-- Volta 2 -->
          <svg v-else-if="CONCEPTS[idx].id === 'volta2'" width="100%" :viewBox="`0 0 ${STAFF_W} 90`" style="display:block">
            <line v-for="y in staffLines(35)" :key="y" :x1="STAFF_LEFT" :y1="y" :x2="STAFF_RIGHT" :y2="y" stroke="rgba(255,255,255,0.2)" stroke-width="0.75"/>
            <line :x1="STAFF_LEFT" y1="35" :x2="STAFF_LEFT" y2="67" stroke="rgba(255,255,255,0.3)" stroke-width="1"/>
            <line x1="100" y1="35" x2="100" y2="67" stroke="rgba(255,255,255,0.3)" stroke-width="1"/>
            <line :x1="STAFF_RIGHT-10" y1="35" :x2="STAFF_RIGHT-10" y2="67" stroke="rgba(255,255,255,0.3)" stroke-width="1"/>
            <line :x1="STAFF_LEFT" y1="28" x2="100" y2="28" stroke="rgba(255,255,255,0.2)" stroke-width="1"/>
            <line :x1="STAFF_LEFT" y1="28" :x2="STAFF_LEFT" y2="35" stroke="rgba(255,255,255,0.2)" stroke-width="1"/>
            <line x1="100" y1="28" x2="100" y2="35" stroke="rgba(255,255,255,0.2)" stroke-width="1"/>
            <text :x="STAFF_LEFT+6" y="27" font-family="'DM Mono', monospace" font-size="8" fill="rgba(255,255,255,0.2)">1.</text>
            <line x1="100" y1="28" :x2="STAFF_RIGHT-10" y2="28" stroke="#f59e0b" stroke-width="1.5"/>
            <line x1="100" y1="28" x2="100" y2="35" stroke="#f59e0b" stroke-width="1.5"/>
            <text x="106" y="27" font-family="'DM Mono', monospace" font-size="8" fill="#f59e0b">2.</text>
            <text x="18" y="80" font-family="'DM Mono', monospace" font-size="7" fill="rgba(255,255,255,0.2)">1st time: play 1.</text>
            <text x="18" y="89" font-family="'DM Mono', monospace" font-size="7" fill="rgba(245,158,11,0.6)">2nd time: skip to 2.</text>
          </svg>

          <!-- D.S. al Coda -->
          <svg v-else-if="CONCEPTS[idx].id === 'dalSegno'" width="100%" :viewBox="`0 0 ${STAFF_W} 65`" style="display:block">
            <line v-for="y in staffLines(15)" :key="y" :x1="STAFF_LEFT" :y1="y" :x2="STAFF_RIGHT" :y2="y" stroke="rgba(255,255,255,0.2)" stroke-width="0.75"/>
            <text x="20" y="13" font-family="serif" font-size="16" fill="#f59e0b" opacity="0.9">𝄋</text>
            <text x="15" y="55" font-family="'DM Mono', monospace" font-size="7" fill="rgba(245,158,11,0.5)">§ sign</text>
            <text x="120" y="55" font-family="'DM Mono', monospace" font-size="8" fill="#f59e0b">D.S.</text>
            <text x="185" y="13" font-family="serif" font-size="16" fill="rgba(255,255,255,0.4)">𝄌</text>
            <text x="178" y="55" font-family="'DM Mono', monospace" font-size="7" fill="rgba(255,255,255,0.25)">coda</text>
            <path d="M 135 48 Q 80 62 35 50" stroke="rgba(245,158,11,0.4)" stroke-width="1.2" fill="none" stroke-dasharray="3 3"/>
          </svg>

          <!-- D.C. al Fine -->
          <svg v-else-if="CONCEPTS[idx].id === 'daCapo'" width="100%" :viewBox="`0 0 ${STAFF_W} 65`" style="display:block">
            <line v-for="y in staffLines(15)" :key="y" :x1="STAFF_LEFT" :y1="y" :x2="STAFF_RIGHT" :y2="y" stroke="rgba(255,255,255,0.2)" stroke-width="0.75"/>
            <text x="90" y="55" font-family="'Cormorant Garamond', serif" font-size="11" fill="rgba(255,255,255,0.35)" font-style="italic">Fine</text>
            <line x1="90" y1="15" x2="90" y2="47" stroke="rgba(255,255,255,0.15)" stroke-width="1"/>
            <text x="175" y="55" font-family="'DM Mono', monospace" font-size="8" fill="#f59e0b">D.C.</text>
            <path d="M 185 48 Q 100 65 15 50" stroke="rgba(245,158,11,0.4)" stroke-width="1.2" fill="none" stroke-dasharray="3 3"/>
            <polygon points="15,50 20,46 20,54" fill="rgba(245,158,11,0.4)"/>
            <text x="8" y="13" font-family="'DM Mono', monospace" font-size="7" fill="rgba(245,158,11,0.4)">top</text>
          </svg>
        </div>

        <div class="rs-explanation">{{ CONCEPTS[idx].explanation }}</div>
      </div>

      <nav class="rs-nav">
        <button class="rs-arrow" @click="nav('left')">‹</button>
        <div class="rs-stepdots">
          <div v-for="(_, i) in CONCEPTS" :key="i" :class="['rs-stepdot', i === idx ? 'active' : '']" />
        </div>
        <button class="rs-arrow" @click="nav('right')">›</button>
      </nav>
  </div>
</template>

<style scoped>
.rs-card { width: 100%; background: #0f0f17; border-radius: 1.25rem; border: 1px solid rgba(255,255,255,0.06); display: flex; flex-direction: column; align-items: center; padding: 1.75rem 1.5rem 1.5rem; gap: 1.25rem; position: relative; overflow: hidden; user-select: none; }
.rs-header { width: 100%; display: flex; align-items: center; justify-content: space-between; z-index: 1; }
.rs-label { font-family: 'DM Mono', monospace; font-size: 0.65rem; letter-spacing: 0.15em; text-transform: uppercase; color: #ffffff; }
.rs-step { font-family: 'DM Mono', monospace; font-size: 0.6rem; letter-spacing: 0.1em; color: #ffffff; }
.rs-content { width: 100%; z-index: 1; display: flex; flex-direction: column; gap: 0.75rem; transition: opacity 0.2s ease, transform 0.2s ease; }
.rs-title { font-family: 'Cormorant Garamond', serif; font-size: 1.6rem; font-weight: 300; color: #ffffff; line-height: 1.1; }
.rs-subtitle { font-family: 'DM Mono', monospace; font-size: 0.6rem; letter-spacing: 0.1em; text-transform: uppercase; color: rgba(245,158,11,0.7); }
.rs-diagram { width: 100%; background: rgba(255,255,255,0.02); border: 1px solid rgba(255,255,255,0.06); border-radius: 0.75rem; padding: 0.75rem 0.5rem; }
.rs-explanation { font-family: system-ui, sans-serif; font-size: 0.85rem; line-height: 1.6; color: #ffffff; min-height: 4rem; }
.rs-nav { display: flex; align-items: center; gap: 1.25rem; z-index: 1; }
.rs-arrow { background: none; border: 1px solid rgba(255,255,255,0.15); color: #ffffff; width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; cursor: pointer; font-size: 0.85rem; transition: all 0.2s ease; }
.rs-arrow:hover { border-color: rgba(255,255,255,0.25); color: rgba(255,255,255,0.85); }
.rs-stepdots { display: flex; gap: 4px; align-items: center; }
.rs-stepdot { width: 5px; height: 5px; border-radius: 50%; background: rgba(255,255,255,0.12); transition: all 0.3s ease; }
.rs-stepdot.active { width: 14px; border-radius: 3px; background: #f59e0b; }
.rs-enter-right { animation: rsEnterRight 0.32s cubic-bezier(0.34,1.2,0.64,1) forwards; }
.rs-enter-left  { animation: rsEnterLeft  0.32s cubic-bezier(0.34,1.2,0.64,1) forwards; }
.rs-exit-left   { opacity: 0; transform: translateX(-24px); }
.rs-exit-right  { opacity: 0; transform: translateX(24px); }
@keyframes rsEnterRight { from { opacity: 0; transform: translateX(24px); } to { opacity: 1; transform: translateX(0); } }
@keyframes rsEnterLeft  { from { opacity: 0; transform: translateX(-24px); } to { opacity: 1; transform: translateX(0); } }
</style>
