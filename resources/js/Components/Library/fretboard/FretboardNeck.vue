<script setup lang="ts">
// Presentational SVG fretboard neck — extracted verbatim from
// ChordProgressionViewer.vue's inline <svg class="board"> block
// (Phase 1 of PLAN-fretboard-svg-unification.md).
//
// Phase 2 generalizes this so it also serves <sbn-fretboard>: optional
// startFret/fretCount (default = prog viewer's original 1..15 span), N dots
// per string (scale mode), nut + open/muted markers (chord mode), and an
// RH-finger label column. ALL new props default to values that reproduce the
// exact prog-viewer behavior — when the parent passes only {dots, viewBox,
// ghostDots, pulsingDotKeys} (as ChordProgressionViewer.vue still does),
// rendering is byte-for-byte identical to pre-Phase-2.
import { computed } from 'vue';
import {
    PAD_T, PAD_B, VB_H, VB_W,
    FRET_FROM, FRET_TO,
    stringY,
    makeGeometry,
    NECK_L as DEFAULT_NECK_L, NECK_R as DEFAULT_NECK_R,
    fretLines as defaultFretLines,
    stringLines as defaultStringLines,
    singleInlays as defaultSingleInlays,
    doubleInlays as defaultDoubleInlays,
    fretNumbers as defaultFretNumbers,
} from './fretboardGeometry';

export interface FretboardNeckDot {
    string: number;
    fret: number;
    cx: number;
    cy: number;
    visible: boolean;
    label: string;
    isRoot: boolean;
    vlColor: string | null;
}

export interface FretboardNeckGhostDot {
    string: number;
    cx: number;
    cy: number;
    color: string;
    label: string;
}

/** Open (○) / muted (×) string marker — chord/sequence mode only. */
export interface FretboardNeckOpenMarker {
    string: number; // 1..6
    kind: 'open' | 'muted';
    /** Optional guide-tone color for an open-string dot (muted markers ignore this). */
    color?: string | null;
    label?: string;
}

export interface FretboardNeckProps {
    /** Current frame's dots. Default behavior (prog viewer): one per string (1..6), `visible` toggles fade. Phase 2: any number of dots per string allowed (scale mode). */
    dots: FretboardNeckDot[];
    /** Next-chord VL targets, shown dimmed/dashed. */
    ghostDots?: FretboardNeckGhostDot[];
    /** SVG viewBox string; when provided, the parent owns the camera pan (smoothX lerp / prog-viewer excerpt). */
    viewBox?: string | null;
    /** "fromString,fromFret" keys that should pulse (resolving VL dots). */
    pulsingDotKeys?: Set<string>;
    /** First fret column shown. Default 1 (prog viewer's FRET_FROM). */
    startFret?: number;
    /** Number of fret columns shown. Default 15 (prog viewer's full span). */
    fretCount?: number;
    /** Draw the nut + open/muted string column. Chord/sequence mode only; default false (prog viewer never drew a nut/open column — its excerpt always starts mid-neck). */
    showNut?: boolean;
    /** Open (○) / muted (×) markers drawn at/before the nut. Only rendered when showNut is true. */
    openStrings?: FretboardNeckOpenMarker[];
    /** 6 RH-finger tokens (p/i/m/a), index 0 = string 1 (low E). Empty string or omitted array hides the column. */
    rhFingers?: string[];
}

const props = withDefaults(defineProps<FretboardNeckProps>(), {
    ghostDots: () => [],
    viewBox: null,
    pulsingDotKeys: () => new Set<string>(),
    startFret: FRET_FROM,
    fretCount: FRET_TO - FRET_FROM + 1,
    showNut: false,
    openStrings: () => [],
    rhFingers: () => [],
});

// Geometry: reuse the prog viewer's precomputed default-range constants when
// startFret/fretCount match the default (the common case — prog viewer and
// any full-span <sbn-fretboard>), otherwise build fresh via makeGeometry.
const isDefaultRange = computed(() =>
    props.startFret === FRET_FROM && props.fretCount === (FRET_TO - FRET_FROM + 1),
);

const geometry = computed(() => isDefaultRange.value
    ? null
    : makeGeometry(props.startFret, props.fretCount));

const NECK_L = computed(() => geometry.value ? geometry.value.NECK_L : DEFAULT_NECK_L);
const NECK_R = computed(() => geometry.value ? geometry.value.NECK_R : DEFAULT_NECK_R);
const fretLines = computed(() => geometry.value ? geometry.value.fretLines : defaultFretLines);
const stringLines = computed(() => geometry.value ? geometry.value.stringLines : defaultStringLines);
const singleInlays = computed(() => geometry.value ? geometry.value.singleInlays : defaultSingleInlays);
const doubleInlays = computed(() => geometry.value ? geometry.value.doubleInlays : defaultDoubleInlays);
const fretNumbers = computed(() => geometry.value ? geometry.value.fretNumbers : defaultFretNumbers);

// Crop around the dot cluster with fixed SVG padding on each side.
// The width grows with the actual dot span so no dots are cut off at any
// neck position, but the SVG is constrained in CSS to the same display
// width as the positions board (see .sbn-fv-board max-width in SbnFretboard).
const SCALE_PAD_X = 35; // SVG units of breathing room left+right of the dot cluster
const resolvedViewBox = computed(() => {
    if (props.viewBox) return props.viewBox;
    const y = PAD_T - 14;
    const h = (VB_H - PAD_B + 22) - y;

    const g = geometry.value;
    const edgeX = g ? g.fretEdgeX : defaultGeometry.fretEdgeX;
    const sf = props.startFret;
    const fc = props.fretCount;

    const frettedDots = props.dots.filter(d => d.visible !== false);
    let minCx: number, maxCx: number;
    if (frettedDots.length > 0) {
        minCx = Math.min(...frettedDots.map(d => d.cx));
        maxCx = Math.max(...frettedDots.map(d => d.cx));
    } else {
        minCx = edgeX[0];
        maxCx = edgeX[fc];
    }

    const neckL = edgeX[0];
    const neckR = edgeX[fc];
    const x = Math.max(neckL - SCALE_PAD_X, minCx - SCALE_PAD_X);
    const xRight = Math.min(neckR + SCALE_PAD_X, maxCx + SCALE_PAD_X);
    const w = xRight - x;
    return `${x} ${y} ${w} ${h}`;
});

function openMarkerFor(s: number): FretboardNeckOpenMarker | undefined {
    return props.openStrings.find(m => m.string === s);
}
</script>

<template>
    <svg class="board" :viewBox="resolvedViewBox" preserveAspectRatio="xMidYMid meet" style="overflow: hidden">
        <!-- Neck surface panel — drawn first so it sits behind everything -->
        <rect
            class="neck-surface"
            :x="NECK_L"
            :y="PAD_T - 6"
            :width="NECK_R - NECK_L"
            :height="(stringY(1) - stringY(6)) + 12"
            rx="9"
        />
        <!-- Fret lines (non-nut) -->
        <line
            v-for="(fl, i) in fretLines.filter(fl => !fl.isNut)"
            :key="`f${i}`"
            class="fret-line"
            :x1="fl.x" :x2="fl.x"
            :y1="PAD_T" :y2="VB_H - PAD_B"
        />
        <!-- Nut: left-edge cap drawn with explicit rx=9 arcs matching the neck surface
             corners exactly. Right side is straight (flush against the neck). -->
        <path
            v-if="fretLines.some(fl => fl.isNut)"
            class="nut-block"
            :d="`M${NECK_L + 5},${PAD_T - 6}
                 L${NECK_L + 9},${PAD_T - 6}
                 A9,9 0 0 0 ${NECK_L},${PAD_T - 6 + 9}
                 L${NECK_L},${PAD_T - 6 + (stringY(1) - stringY(6)) + 12 - 9}
                 A9,9 0 0 0 ${NECK_L + 9},${PAD_T - 6 + (stringY(1) - stringY(6)) + 12}
                 L${NECK_L + 5},${PAD_T - 6 + (stringY(1) - stringY(6)) + 12}
                 Z`"
        />
        <!-- Strings (graded weight: low E 1.5 → high E 0.54) -->
        <line
            v-for="sl in stringLines"
            :key="`s${sl.s}`"
            class="string-line"
            :x1="sl.x1" :x2="sl.x2"
            :y1="sl.y" :y2="sl.y"
            :stroke-width="1.5 - (sl.s - 1) * 0.16"
        />
        <!-- Inlay dots -->
        <circle v-for="(d, i) in singleInlays" :key="`si${i}`" class="inlay" :cx="d.cx" :cy="d.cy" r="3.5" />
        <circle v-for="(d, i) in doubleInlays" :key="`di${i}`" class="inlay" :cx="d.cx" :cy="d.cy" r="3.5" />
        <!-- Fret numbers -->
        <text
            v-for="(t, i) in fretNumbers"
            :key="`fn${i}`"
            class="fret-num"
            :x="t.x" :y="t.y"
            text-anchor="middle"
        >{{ t.n }}</text>
        <!-- Finger dots: 6 persistent <g> elements keyed by string.
             Translating the <g> lets the label ride with the dot. -->
        <g
            v-for="(d, di) in dots"
            :key="`dot-${d.string}-${di}`"
            class="dot-group"
            :class="{
                'is-hidden': !d.visible,
                'is-pulsing': d.visible && pulsingDotKeys.has(`${d.string},${d.fret}`),
            }"
            :style="`transform: translate(${d.cx}px, ${d.cy}px); --vl-color: ${d.vlColor ?? 'transparent'}`"
        >
            <circle v-if="d.isRoot" class="root-ring" r="12.5" cx="0" cy="0" />
            <circle class="dot" r="9" cx="0" cy="0" :style="d.vlColor ? `fill: ${d.vlColor}` : ''" />
            <circle class="dot-sheen" r="9" cx="0" cy="0" />
            <text
                v-if="d.label"
                class="dot-finger"
                x="0" y="0"
                text-anchor="middle"
                dominant-baseline="central"
            >{{ d.label }}</text>
        </g>
        <!-- Ghost dots: next chord's VL targets, hollow dashed outline -->
        <g
            v-for="g in ghostDots"
            :key="`ghost-${g.string}`"
            class="ghost-dot-group"
            :style="`transform: translate(${g.cx}px, ${g.cy}px)`"
        >
            <circle class="ghost-dot" r="9" cx="0" cy="0" />
            <text
                v-if="g.label"
                class="dot-finger"
                x="0" y="0"
                text-anchor="middle"
                dominant-baseline="central"
            >{{ g.label }}</text>
        </g>
        <!-- Nut + open/muted column — chord/sequence mode only, when showNut is true.
             String 6 (high e) is at TOP of SVG (smallest y), string 1 (low E) at BOTTOM.
             Drawn to the LEFT of the neck surface (x = NECK_L - 28 offset). -->
        <g v-if="showNut" class="nut-group">
            <!-- Thick grey line at the left edge of the neck surface, signals the nut -->
            <line
                class="nut-bar"
                :x1="NECK_L" :x2="NECK_L"
                :y1="PAD_T - 6" :y2="(PAD_T - 6) + (stringY(1) - stringY(6)) + 12"
            />
            <!-- Open (○) and muted (×) markers for each string -->
            <g
                v-for="s in [1,2,3,4,5,6]"
                :key="`om-${s}`"
            >
                <template v-if="openMarkerFor(s)">
                    <!-- open string ○ -->
                    <circle
                        v-if="openMarkerFor(s)!.kind === 'open'"
                        class="open-string-dot"
                        :cx="NECK_L - 14"
                        :cy="stringY(s)"
                        r="5.5"
                        :style="openMarkerFor(s)!.color ? `stroke: ${openMarkerFor(s)!.color}; fill: none` : ''"
                    />
                    <!-- muted string × -->
                    <g v-else class="muted-string-mark" :style="`transform: translate(${NECK_L - 14}px, ${stringY(s)}px)`">
                        <line x1="-4" y1="-4" x2="4" y2="4" />
                        <line x1="4" y1="-4" x2="-4" y2="4" />
                    </g>
                </template>
            </g>
        </g>
        <!-- RH finger label column — shown when rhFingers has any non-empty token.
             Drawn to the RIGHT of the neck surface. Labels are p/i/m/a.
             rhFingers[0] = string 1 (low E, rendered at BOTTOM = largest y). -->
        <g v-if="rhFingers.some(f => f)" class="rh-fingers-group">
            <text
                v-for="s in [1,2,3,4,5,6]"
                :key="`rh-${s}`"
                class="rh-finger-label"
                :x="NECK_R + 10"
                :y="stringY(s)"
                dominant-baseline="central"
                text-anchor="start"
            >{{ rhFingers[s - 1] ?? '' }}</text>
        </g>
    </svg>
</template>

<style scoped>
.board {
    width: 100%;
    display: block;
    transition: all 0.55s var(--ease);
}
.board .neck-surface {
    fill: var(--clr-surface-2);
    stroke: var(--clr-border);
    stroke-width: 1;
}
.board .string-line { stroke: var(--str-graded, var(--clr-text)); }
.board .fret-line { stroke: var(--clr-border); stroke-width: 1; }
.board .nut-block { fill: var(--clr-border); stroke: none; }
.board .fret-num {
    font-size: 10px;
    font-weight: 600;
    fill: var(--clr-text-dim);
    letter-spacing: 0.02em;
}
.board .inlay { fill: var(--clr-border); }
.board .open-string-dot {
    fill: none;
    stroke: var(--clr-text-dim);
    stroke-width: 1.6;
}
.board .muted-string-mark line {
    stroke: var(--clr-text-muted, var(--clr-text-dim));
    stroke-width: 1.6;
    stroke-linecap: round;
}
.board .rh-finger-label {
    font-size: 9px;
    font-weight: 600;
    font-style: italic;
    fill: var(--clr-text-dim);
}
.board .active-window {
    fill: color-mix(in srgb, var(--clr-red) 14%, transparent);
    transition: x 0.55s var(--ease), width 0.55s var(--ease);
}
.board .dot-group {
    transition: transform 1.1s var(--ease), opacity 0.35s ease;
    filter: drop-shadow(0 1px 2px rgba(0, 0, 0, 0.16));
}
.board .dot-sheen {
    fill: none;
    stroke: rgba(255, 255, 255, 0.30);
    stroke-width: 1;
}
.board .root-ring {
    fill: none;
    stroke: var(--prog-color);
    stroke-width: 1.4;
    opacity: 0.85;
}
.board .dot-group.is-hidden {
    opacity: 0;
    pointer-events: none;
}
.board .dot-group.is-pulsing .dot {
    animation: vl-glow 1.6s ease-in-out infinite;
}
@keyframes vl-glow {
    0%, 100% { filter: drop-shadow(0 0 1px transparent); }
    50%       { filter: drop-shadow(0 0 4px var(--vl-color)); }
}
.board .dot {
    fill: var(--clr-text);
}
.board .ghost-dot-group {
    opacity: 0.9;
    pointer-events: none;
    transition: transform 1.1s var(--ease);
}
.board .ghost-dot {
    fill: var(--clr-white);
    stroke: var(--clr-text-muted);
    stroke-width: 1.4;
    stroke-dasharray: 2.2 2.2;
}
.board .ghost-dot-group .dot-finger {
    fill: var(--clr-text-muted);
}
.board .dot-finger {
    fill: var(--clr-white);
    font-size: 8px;
    font-weight: 700;
    font-family: 'Inter', system-ui, sans-serif;
    pointer-events: none;
    letter-spacing: -0.02em;
}
</style>
