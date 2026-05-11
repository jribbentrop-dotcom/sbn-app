<template>
  <svg :viewBox="`0 0 ${W} ${H}`" :width="width" class="chord-svg-neon" xmlns="http://www.w3.org/2000/svg">
    <defs>
      <filter :id="filterId" x="-50%" y="-50%" width="200%" height="200%">
        <feGaussianBlur stdDeviation="1.5" result="blur"/>
        <feMerge>
          <feMergeNode in="blur"/>
          <feMergeNode in="SourceGraphic"/>
        </feMerge>
      </filter>
    </defs>

    <!-- Position indicator or nut -->
    <text v-if="pos > 1"
      :x="left - 2" :y="top + fretSp - 4"
      font-size="9" :fill="txt"
      font-family="JetBrains Mono, monospace"
      text-anchor="end"
    >{{ pos }}</text>
    <rect v-else
      :x="left - 1" :y="top - 4"
      :width="strSp * 5 + 2" height="3"
      :fill="gridStrong" rx="0.5"
    />

    <!-- Fret lines -->
    <line
      v-for="f in numFrets + 1" :key="`fret-${f}`"
      :x1="left" :y1="top + (f - 1) * fretSp"
      :x2="left + strSp * 5" :y2="top + (f - 1) * fretSp"
      :stroke="grid" stroke-width="0.5"
    />

    <!-- String lines -->
    <line
      v-for="s in 6" :key="`str-${s}`"
      :x1="left + (s - 1) * strSp" :y1="top"
      :x2="left + (s - 1) * strSp" :y2="top + fretSp * numFrets"
      :stroke="grid" stroke-width="0.6"
    />

    <!-- Muted strings (×) -->
    <template v-for="(f, i) in parsed" :key="`mute-${i}`">
      <text
        v-if="f === 'x'"
        :x="left + i * strSp" :y="top - 5"
        font-size="9" font-weight="600" text-anchor="middle"
        :fill="txt" font-family="JetBrains Mono, monospace"
      >×</text>
    </template>

    <!-- Open strings (○) -->
    <template v-for="(f, i) in parsed" :key="`open-${i}`">
      <circle
        v-if="f === 0"
        :cx="left + i * strSp" :cy="top - 7"
        r="3" fill="none" :stroke="txt" stroke-width="0.9"
      />
    </template>

    <!-- Fretted dots -->
    <template v-for="(f, i) in parsed" :key="`dot-${i}`">
      <template v-if="f !== 'x' && f !== 0 && f - pos + 1 > 0 && f - pos + 1 <= numFrets">
        <circle
          :cx="left + i * strSp"
          :cy="top + (f - pos + 1) * fretSp - fretSp / 2"
          r="6" :fill="accent" :filter="`url(#${filterId})`"
        />
        <circle
          :cx="left + i * strSp"
          :cy="top + (f - pos + 1) * fretSp - fretSp / 2"
          r="4.5" :fill="accent"
        />
      </template>
    </template>
  </svg>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  frets:    { type: String, default: '' },
  position: { type: Number, default: 1 },
  accent:   { type: String, default: 'var(--stage-accent, #ff7a1a)' },
  width:    { type: [String, Number], default: '100%' },
});

const W = 80, H = 100;
const strSp = 12, fretSp = 17;
const left = 14, top = 18, numFrets = 4;
const grid = 'var(--neon-grid, rgba(255,255,255,0.28))';
const gridStrong = 'var(--neon-grid-strong, rgba(255,255,255,0.55))';
const txt = 'var(--neon-txt, rgba(255,255,255,0.7))';

const filterId = `neon-glow-${Math.random().toString(36).slice(2, 7)}`;

const pos = computed(() => Math.max(1, props.position ?? 1));

const parsed = computed(() => {
  const s = props.frets ?? '';
  if (!s) return ['x', 'x', 'x', 'x', 'x', 'x'];
  const out = [];
  for (const c of s) {
    out.push(c === 'x' || c === 'X' ? 'x' : parseInt(c, 16));
  }
  while (out.length < 6) out.push('x');
  return out.slice(0, 6);
});
</script>
