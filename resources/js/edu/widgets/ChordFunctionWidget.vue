<script setup lang="ts">
import { ref, computed } from 'vue';

interface Degree {
  numeral: string;
  chord: string;
  function: 'Tonic' | 'Subdominant' | 'Dominant';
  explanation: string;
}

const DEGREES: Record<string, Degree[]> = {
  major: [
    { numeral: 'I',    chord: 'Cmaj7',   function: 'Tonic',       explanation: 'Home. The resolution point everything wants to reach. In Bossa Nova, often voiced as a lush maj9 — the sound of arrival.' },
    { numeral: 'II',   chord: 'Dm7',     function: 'Subdominant', explanation: 'The first step of the ii–V–I, the backbone of Jazz harmony. Creates forward motion — you feel the pull toward V the moment it lands.' },
    { numeral: 'III',  chord: 'Em7',     function: 'Tonic',       explanation: 'Tonic substitute. Softer, more ambiguous than I. Shares two notes with Cmaj7 — same family, different colour.' },
    { numeral: 'IV',   chord: 'Fmaj7',   function: 'Subdominant', explanation: 'The other subdominant. In Bossa, often coloured with a #11 — the Lydian sound that gives the genre its floating, sun-lit quality.' },
    { numeral: 'V',    chord: 'G7',      function: 'Dominant',    explanation: 'Tension. The tritone between B and F wants to collapse into I. Everything in tonal harmony leads here — and away from here.' },
    { numeral: 'VI',   chord: 'Am7',     function: 'Tonic',       explanation: 'The relative minor. Tonic function but melancholic — where the same home feels bittersweet. A pivot point between major and minor worlds.' },
    { numeral: 'VII',  chord: 'Bm7♭5',  function: 'Dominant',    explanation: 'Dominant substitute. The tritone from G7 reappears: B and F are still here. Half-diminished, unresolved, itching to move.' },
  ],
  minor: [
    { numeral: 'I',    chord: 'Cm7',     function: 'Tonic',       explanation: 'Home, but darker. The minor tonic carries weight and longing — in Bossa and Samba, it often opens a song mid-story, already aching.' },
    { numeral: 'II',   chord: 'Dm7♭5',  function: 'Subdominant', explanation: 'The half-diminished ii. Still starts the ii–V–I, but with added tension — the ♭5 sharpens the pull toward V7, more urgent than its major counterpart.' },
    { numeral: 'III',  chord: 'E♭maj7', function: 'Tonic',       explanation: 'The relative major. Tonic function — a brief exhale of warmth inside a minor key. The ♭III gives minor-key music its moments of unexpected light.' },
    { numeral: 'IV',   chord: 'Fm7',    function: 'Subdominant', explanation: 'Minor subdominant. Deeper and more resigned than IV major. In minor ii–V–Is, this chord colour sets the emotional stakes before the dominant arrives.' },
    { numeral: 'V',    chord: 'G7',     function: 'Dominant',    explanation: 'From harmonic minor — the raised 7th (B♮) creates the same tritone as in major. The dominant stays V7 so the resolution back to Im still snaps into place.' },
    { numeral: 'VI',   chord: 'A♭maj7', function: 'Tonic',       explanation: 'The ♭VI major. A signature sound of minor-key Jazz — rich, bittersweet, surprisingly stable. Often used as a deceptive resolution target instead of Im.' },
    { numeral: 'VII',  chord: 'B♭7',    function: 'Subdominant', explanation: 'The ♭VII dominant. Unlike major\'s VII, this one has no tritone pull — it functions more as a subdominant colour, a bluesy, unresolved warmth before Im.' },
  ],
};

const FUNCTION_STYLES = {
  Tonic:       { color: '#f59e0b', bg: 'rgba(245,158,11,0.12)', border: 'rgba(245,158,11,0.35)', glow: 'rgba(245,158,11,0.2)' },
  Subdominant: { color: '#14b8a6', bg: 'rgba(20,184,166,0.12)', border: 'rgba(20,184,166,0.35)', glow: 'rgba(20,184,166,0.2)' },
  Dominant:    { color: '#ef4444', bg: 'rgba(239,68,68,0.12)',  border: 'rgba(239,68,68,0.35)',  glow: 'rgba(239,68,68,0.2)'  },
};

const index = ref(0);
const mode = ref<'major' | 'minor'>('major');
const direction = ref<'left' | 'right' | null>(null);
const animating = ref(false);
const visible = ref(true);
const modeAnimating = ref(false);

const degrees = computed(() => DEGREES[mode.value]);
const degree  = computed(() => degrees.value[index.value]);
const style   = computed(() => FUNCTION_STYLES[degree.value.function]);

const animClass = computed(() => {
  if (!visible.value) {
    if (modeAnimating.value) return 'cfi-fade-out';
    return direction.value === 'right' ? 'cfi-slide-exit-left' : 'cfi-slide-exit-right';
  }
  if (modeAnimating.value) return 'cfi-fade-in';
  if (direction.value === 'right') return 'cfi-slide-enter-right';
  if (direction.value === 'left')  return 'cfi-slide-enter-left';
  return '';
});

function navigate(dir: 'left' | 'right') {
  if (animating.value || modeAnimating.value) return;
  const len = degrees.value.length;
  const next = dir === 'right' ? (index.value + 1) % len : (index.value - 1 + len) % len;
  direction.value = dir;
  animating.value = true;
  visible.value = false;
  setTimeout(() => {
    index.value = next;
    direction.value = dir === 'right' ? 'left' : 'right';
    visible.value = true;
    setTimeout(() => { animating.value = false; }, 350);
  }, 220);
}

function switchMode(newMode: 'major' | 'minor') {
  if (newMode === mode.value || modeAnimating.value) return;
  modeAnimating.value = true;
  visible.value = false;
  setTimeout(() => {
    mode.value = newMode;
    visible.value = true;
    setTimeout(() => { modeAnimating.value = false; }, 350);
  }, 200);
}

let touchStartX: number | null = null;
function onTouchStart(e: TouchEvent) { touchStartX = e.touches[0].clientX; }
function onTouchEnd(e: TouchEvent) {
  if (touchStartX === null) return;
  const delta = touchStartX - e.changedTouches[0].clientX;
  if (Math.abs(delta) > 40) navigate(delta > 0 ? 'right' : 'left');
  touchStartX = null;
}
</script>

<template>
  <div class="cfi-card" @touchstart="onTouchStart" @touchend="onTouchEnd">
    <div class="cfi-glow" :style="{ background: style.glow }" />

    <!-- Header -->
    <div class="cfi-header">
      <div class="cfi-key-label">Key of C</div>
      <div class="cfi-mode-toggle">
        <button class="cfi-mode-btn" :class="{ active: mode === 'major' }" @click="switchMode('major')">Major</button>
        <button class="cfi-mode-btn" :class="{ active: mode === 'minor' }" @click="switchMode('minor')">Minor</button>
      </div>
    </div>

    <!-- Content -->
    <div class="cfi-numeral-wrap" :class="animClass">
      <div class="cfi-numeral" :style="{ color: style.color }">{{ degree.numeral }}</div>
      <div class="cfi-chord">{{ degree.chord }}</div>
      <div class="cfi-pill" :style="{ color: style.color, background: style.bg, borderColor: style.border }">
        {{ degree.function }}
      </div>
      <div class="cfi-explanation">{{ degree.explanation }}</div>
    </div>

    <!-- Nav -->
    <nav class="cfi-nav">
      <button class="cfi-arrow" @click="navigate('left')">‹</button>
      <div class="cfi-dots">
        <div
          v-for="(_, i) in degrees"
          :key="i"
          class="cfi-dot"
          :class="{ active: i === index }"
          :style="i === index ? { background: style.color } : {}"
        />
      </div>
      <button class="cfi-arrow" @click="navigate('right')">›</button>
    </nav>
  </div>
</template>

<style scoped>
.cfi-card {
  width: 100%;
  min-height: 520px;
  background: #0f0f17;
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 1.5rem 1.5rem;
  position: relative;
  overflow: hidden;
  user-select: none;
  box-sizing: border-box;
}

.cfi-glow {
  position: absolute;
  top: -60px; left: 50%;
  transform: translateX(-50%);
  width: 200px; height: 200px;
  border-radius: 50%;
  pointer-events: none;
  transition: background 0.5s ease;
  filter: blur(60px);
  opacity: 0.25;
}

.cfi-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  width: 100%;
  margin-bottom: 1.75rem;
  z-index: 1;
}

.cfi-key-label {
  font-family: 'DM Mono', monospace;
  font-size: 0.7rem;
  letter-spacing: 0.15em;
  text-transform: uppercase;
  color: rgba(255,255,255,0.65);
}

.cfi-mode-toggle {
  display: flex;
  background: rgba(255,255,255,0.08);
  border-radius: 999px;
  padding: 3px;
  gap: 2px;
  border: 1px solid rgba(255,255,255,0.12);
}

.cfi-mode-btn {
  font-family: 'DM Mono', monospace;
  font-size: 0.65rem;
  letter-spacing: 0.1em;
  text-transform: uppercase;
  padding: 0.32rem 0.75rem;
  border-radius: 999px;
  border: none;
  cursor: pointer;
  transition: all 0.25s ease;
  background: transparent;
  color: rgba(255,255,255,0.6);
}
.cfi-mode-btn.active {
  background: rgba(255,255,255,0.92);
  box-shadow: 0 1px 4px rgba(0,0,0,0.3);
  color: #0f0f17;
}

.cfi-numeral-wrap {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  z-index: 1;
  width: 100%;
}

.cfi-numeral {
  font-family: 'Cormorant Garamond', serif;
  font-weight: 300;
  font-size: 6.5rem;
  line-height: 1;
  letter-spacing: -0.02em;
  margin-bottom: 0.4rem;
  transition: color 0.4s ease;
}

.cfi-chord {
  font-family: 'DM Mono', monospace;
  font-size: 1rem;
  font-weight: 300;
  color: rgba(255,255,255,0.55);
  letter-spacing: 0.05em;
  margin-bottom: 1.25rem;
}

.cfi-pill {
  display: inline-block;
  font-family: 'DM Mono', monospace;
  font-size: 0.7rem;
  letter-spacing: 0.14em;
  text-transform: uppercase;
  padding: 0.3rem 0.85rem;
  border-radius: 999px;
  border: 1px solid;
  margin-bottom: 1.25rem;
  transition: all 0.4s ease;
}

.cfi-explanation {
  font-family: system-ui, sans-serif;
  font-size: 0.82rem;
  line-height: 1.65;
  color: rgba(255,255,255,0.85);
  text-align: center;
  padding: 0 0.25rem;
}

.cfi-nav {
  display: flex;
  align-items: center;
  gap: 1.5rem;
  margin-top: 1.75rem;
  z-index: 1;
}

.cfi-arrow {
  background: rgba(255,255,255,0.06);
  border: 1px solid rgba(255,255,255,0.15);
  color: rgba(255,255,255,0.65);
  width: 38px; height: 38px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  font-size: 0.9rem;
  transition: all 0.2s ease;
}
.cfi-arrow:hover { border-color: rgba(255,255,255,0.22); color: rgba(255,255,255,0.85); }

.cfi-dots { display: flex; gap: 5px; align-items: center; }
.cfi-dot {
  width: 5px; height: 5px;
  border-radius: 50%;
  background: rgba(255,255,255,0.12);
  transition: all 0.3s ease;
}
.cfi-dot.active { width: 14px; border-radius: 3px; }

/* Slide animations */
.cfi-slide-enter-right { animation: cfiSlideInRight 0.32s cubic-bezier(0.34,1.2,0.64,1) forwards; }
.cfi-slide-enter-left  { animation: cfiSlideInLeft  0.32s cubic-bezier(0.34,1.2,0.64,1) forwards; }
.cfi-slide-exit-left   { animation: cfiSlideOutLeft  0.2s ease forwards; }
.cfi-slide-exit-right  { animation: cfiSlideOutRight 0.2s ease forwards; }
.cfi-fade-in           { animation: cfiFadeIn  0.3s ease forwards; }
.cfi-fade-out          { animation: cfiFadeOut 0.2s ease forwards; }

@keyframes cfiSlideInRight  { from { opacity:0; transform:translateX(40px);  } to { opacity:1; transform:translateX(0); } }
@keyframes cfiSlideInLeft   { from { opacity:0; transform:translateX(-40px); } to { opacity:1; transform:translateX(0); } }
@keyframes cfiSlideOutLeft  { from { opacity:1; transform:translateX(0); } to { opacity:0; transform:translateX(-40px); } }
@keyframes cfiSlideOutRight { from { opacity:1; transform:translateX(0); } to { opacity:0; transform:translateX(40px);  } }
@keyframes cfiFadeIn  { from { opacity:0; transform:translateY(6px); } to { opacity:1; transform:translateY(0); } }
@keyframes cfiFadeOut { from { opacity:1; transform:translateY(0); } to { opacity:0; transform:translateY(-6px); } }

@media (prefers-reduced-motion: reduce) {
  .cfi-slide-enter-right, .cfi-slide-enter-left,
  .cfi-slide-exit-left, .cfi-slide-exit-right,
  .cfi-fade-in, .cfi-fade-out { animation: none; }
}
</style>
