<script setup lang="ts">
import { ref, computed } from 'vue';

interface ScaleDegree { degree: string; role: string; pentatonic: boolean; }
interface ScaleData {
  degrees: ScaleDegree[];
  diatonicLabel: string;
  pentatonicLabel: string;
  diatonicBlurb: string;
  pentatonicBlurb: string;
}

const SCALES: Record<string, ScaleData> = {
  major: {
    degrees: [
      { degree: '1',  role: 'root',    pentatonic: true  },
      { degree: '2',  role: 'second',  pentatonic: true  },
      { degree: '3',  role: 'third',   pentatonic: true  },
      { degree: '4',  role: 'fourth',  pentatonic: false },
      { degree: '5',  role: 'fifth',   pentatonic: true  },
      { degree: '6',  role: 'sixth',   pentatonic: true  },
      { degree: '7',  role: 'seventh', pentatonic: false },
    ],
    diatonicLabel:   'Major Scale',
    pentatonicLabel: 'Major Pentatonic',
    diatonicBlurb:   'Seven notes. The 4th and 7th create half-step tension — against the 3rd and the root.',
    pentatonicBlurb: 'Remove the 4th and 7th. Five notes with no half-step clashes — works over almost any major chord.',
  },
  minor: {
    degrees: [
      { degree: '1',  role: 'root',    pentatonic: true  },
      { degree: '2',  role: 'second',  pentatonic: false },
      { degree: 'b3', role: 'third',   pentatonic: true  },
      { degree: '4',  role: 'fourth',  pentatonic: true  },
      { degree: '5',  role: 'fifth',   pentatonic: true  },
      { degree: 'b6', role: 'sixth',   pentatonic: false },
      { degree: 'b7', role: 'seventh', pentatonic: true  },
    ],
    diatonicLabel:   'Natural Minor Scale',
    pentatonicLabel: 'Minor Pentatonic',
    diatonicBlurb:   'Seven notes. The 2nd and ♭6 sit a half-step away from the 3rd and 5th — they clash in dense textures.',
    pentatonicBlurb: 'Remove the 2nd and ♭6. The five remaining notes are the core of blues, rock, and Jazz minor phrasing.',
  },
};

const ROLE_COLOR: Record<string, string> = {
  root:    '#f59e0b',
  third:   '#3b82f6',
  fifth:   '#10b981',
  second:  'rgba(255,255,255,0.6)',
  fourth:  'rgba(255,255,255,0.6)',
  sixth:   'rgba(255,255,255,0.6)',
  seventh: 'rgba(255,255,255,0.6)',
};

const DOT_R   = 12;
const DOT_GAP = 32;
const SVG_W   = 80;
const DOT_X   = SVG_W / 2;

function dotY(i: number, total: number) {
  return DOT_R + (total - 1 - i) * DOT_GAP;
}

const quality   = ref<'major' | 'minor'>('major');
const showPenta = ref(false);

const scale   = computed(() => SCALES[quality.value]);
const degrees = computed(() => scale.value.degrees);
const total   = computed(() => degrees.value.length);
const svgH    = computed(() => (total.value - 1) * DOT_GAP + DOT_R * 2 + 4);

function switchQuality(q: 'major' | 'minor') {
  quality.value = q;
  showPenta.value = false;
}

function dotFill(note: ScaleDegree) {
  if (showPenta.value && !note.pentatonic) return 'rgba(255,255,255,0.08)';
  return ROLE_COLOR[note.role] || 'rgba(255,255,255,0.6)';
}
function dotRadius(note: ScaleDegree) {
  return showPenta.value && !note.pentatonic ? DOT_R * 0.5 : DOT_R;
}
function dotOpacity(note: ScaleDegree) {
  return showPenta.value && !note.pentatonic ? 0.5 : 1;
}
function isDropped(note: ScaleDegree) {
  return showPenta.value && !note.pentatonic;
}

const LEGEND_ITEMS = [
  { color: '#f59e0b', label: 'Root' },
  { color: '#3b82f6', label: '3rd' },
  { color: '#10b981', label: '5th' },
  { color: 'rgba(255,255,255,0.6)', label: 'Other' },
  { color: 'rgba(255,255,255,0.08)', label: 'Removed', dashed: true },
];
</script>

<template>
  <div class="pt-card">

    <!-- Header -->
    <div class="pt-header">
      <div class="pt-title">5 vs 7</div>
      <div class="pt-quality-pills">
        <button
          v-for="q in ['major', 'minor']"
          :key="q"
          class="pt-pill"
          :class="{ active: quality === q }"
          @click="switchQuality(q as any)"
        >{{ q }}</button>
      </div>
    </div>

    <!-- Body -->
    <div class="pt-body">

      <!-- Dot stack -->
      <svg :width="SVG_W" :height="svgH" :viewBox="`0 0 ${SVG_W} ${svgH}`" style="flex-shrink:0; overflow:visible">
        <line :x1="DOT_X" :y1="DOT_R" :x2="DOT_X" :y2="svgH - DOT_R"
          stroke="rgba(255,255,255,0.08)" stroke-width="1.5"
        />

        <g
          v-for="(note, i) in degrees"
          :key="`${quality}-${note.degree}`"
          :style="{ transition: 'opacity 0.4s ease', opacity: dotOpacity(note), transformOrigin: `${DOT_X}px ${dotY(i, total)}px` }"
        >
          <!-- Ghost ring on dropped notes -->
          <circle v-if="isDropped(note)"
            :cx="DOT_X" :cy="dotY(i, total)" :r="DOT_R"
            fill="none" stroke="rgba(255,255,255,0.15)" stroke-width="1" stroke-dasharray="3 3"
          />

          <!-- Main dot -->
          <circle
            :cx="DOT_X" :cy="dotY(i, total)" :r="dotRadius(note)"
            :fill="dotFill(note)"
            style="transition: r 0.35s cubic-bezier(0.34,1.2,0.64,1), fill 0.35s ease"
          />

          <!-- Inner highlight -->
          <circle v-if="!isDropped(note)"
            :cx="DOT_X" :cy="dotY(i, total)" :r="DOT_R * 0.35"
            fill="rgba(255,255,255,0.85)" style="pointer-events:none"
          />

          <!-- Degree label -->
          <text
            :x="DOT_X - DOT_R - 6" :y="dotY(i, total) + 4"
            text-anchor="end" font-family="'DM Mono', monospace"
            :font-size="isDropped(note) ? '7' : '8'"
            :fill="isDropped(note) ? 'rgba(255,255,255,0.2)' : ROLE_COLOR[note.role] || 'rgba(255,255,255,0.6)'"
            style="transition: fill 0.35s ease"
          >{{ note.degree }}</text>

          <!-- Strike-through on dropped -->
          <line v-if="isDropped(note)"
            :x1="DOT_X - DOT_R - 14" :y1="dotY(i, total)"
            :x2="DOT_X - 2"          :y2="dotY(i, total)"
            stroke="rgba(255,255,255,0.18)" stroke-width="0.75"
          />
        </g>
      </svg>

      <!-- Info -->
      <div class="pt-info">
        <div class="pt-scale-label">{{ showPenta ? scale.pentatonicLabel : scale.diatonicLabel }}</div>
        <div class="pt-count">{{ showPenta ? '5 notes' : '7 notes' }}</div>
        <div class="pt-blurb">{{ showPenta ? scale.pentatonicBlurb : scale.diatonicBlurb }}</div>
      </div>
    </div>

    <!-- Legend -->
    <div class="pt-legend">
      <div v-for="item in LEGEND_ITEMS" :key="item.label" class="pt-legend-item">
        <div class="pt-legend-dot" :style="{
          background: item.color,
          border: (item as any).dashed ? '1px dashed rgba(255,255,255,0.2)' : 'none',
        }" />
        <span class="pt-legend-text">{{ item.label }}</span>
      </div>
    </div>

    <!-- Toggle -->
    <div class="pt-toggle-wrap">
      <span class="pt-toggle-label" :class="{ active: showPenta }">
        {{ showPenta ? 'Pentatonic' : 'Diatonic' }}
      </span>
      <button class="pt-toggle" :class="{ on: showPenta }" @click="showPenta = !showPenta">
        <div class="pt-toggle-knob" />
      </button>
    </div>

  </div>
</template>

<style scoped>
.pt-card {
  width: 100%;
  background: #0f0f17;
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 1.5rem 1.25rem;
  gap: 1.25rem;
  user-select: none;
  box-sizing: border-box;
}

.pt-header { width: 100%; display: flex; align-items: center; justify-content: space-between; }
.pt-title {
  font-family: 'DM Mono', monospace; font-size: 0.7rem;
  letter-spacing: 0.15em; text-transform: uppercase; color: #ffffff;
}
.pt-quality-pills { display: flex; gap: 3px; }
.pt-pill {
  font-family: 'DM Mono', monospace; font-size: 0.65rem; letter-spacing: 0.08em;
  padding: 0.32rem 0.65rem; border-radius: 999px;
  border: 1px solid rgba(255,255,255,0.18); background: rgba(255,255,255,0.08);
  color: #ffffff; cursor: pointer; transition: all 0.2s ease;
}
.pt-pill:hover { border-color: rgba(255,255,255,0.35); color: rgba(255,255,255,0.9); background: rgba(255,255,255,0.12); }
.pt-pill.active { background: rgba(255,255,255,0.92); color: #0f0f17; border-color: transparent; }

.pt-body { display: flex; align-items: center; gap: 1.5rem; width: 100%; }
.pt-info { flex: 1; display: flex; flex-direction: column; gap: 0.6rem; }

.pt-scale-label {
  font-family: 'Cormorant Garamond', serif; font-size: 1.4rem; font-weight: 300;
  line-height: 1.1; color: #ffffff; transition: opacity 0.3s ease; min-height: 3rem;
}
.pt-count {
  font-family: 'DM Mono', monospace; font-size: 0.7rem;
  letter-spacing: 0.1em; color: #ffffff;
}
.pt-blurb {
  font-family: system-ui, sans-serif;
  font-size: 0.82rem; line-height: 1.6; color: #ffffff;
  min-height: 4.5rem; transition: opacity 0.25s ease;
}

.pt-toggle-wrap { width: 100%; display: flex; align-items: center; gap: 0.75rem; }
.pt-toggle-label {
  font-family: 'DM Mono', monospace; font-size: 0.7rem;
  letter-spacing: 0.1em; text-transform: uppercase;
  color: #ffffff; flex: 1; transition: color 0.3s ease;
}
.pt-toggle-label.active { color: #f59e0b; }

.pt-toggle {
  width: 48px; height: 28px; border-radius: 999px;
  background: rgba(255,255,255,0.12); border: 1px solid rgba(255,255,255,0.18); cursor: pointer;
  position: relative; transition: background 0.3s ease; flex-shrink: 0;
}
.pt-toggle.on { background: #f59e0b; border-color: transparent; }
.pt-toggle-knob {
  position: absolute; top: 3px; left: 3px;
  width: 20px; height: 20px; border-radius: 50%;
  background: #ffffff; box-shadow: 0 1px 3px rgba(0,0,0,0.4);
  transition: transform 0.3s cubic-bezier(0.34,1.2,0.64,1);
}
.pt-toggle.on .pt-toggle-knob { transform: translateX(20px); }

.pt-legend { display: flex; gap: 0.75rem; align-items: center; width: 100%; }
.pt-legend-item { display: flex; align-items: center; gap: 4px; }
.pt-legend-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
.pt-legend-text {
  font-family: 'DM Mono', monospace; font-size: 0.65rem;
  letter-spacing: 0.1em; text-transform: uppercase; color: #ffffff;
}

@media (prefers-reduced-motion: reduce) {
  .pt-toggle-knob, .pt-toggle, .pt-blurb, .pt-scale-label { transition: none; }
}
</style>
