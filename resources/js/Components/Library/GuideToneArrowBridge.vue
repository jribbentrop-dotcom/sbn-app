<script setup lang="ts">
/**
 * SVG overlay spanning both adjacent chord tiles plus the gap between them.
 * Positioned absolutely at the top-left of tile A, width = tileA + gap + tileB.
 * overflow:visible so arrows that slightly exceed the canvas are still rendered.
 *
 * Coordinate model (px):
 *   - Tile A diagram occupies [tilePadL .. tilePadL + diagW] horizontally.
 *   - Tile B diagram occupies [tileW + gapW + tilePadL .. same + diagW].
 *   - Both share the same vertical space starting at HEADER_H from the top.
 *   - Dot svgX / svgY are in SVG units (viewBox 80×95); multiply by `scale` for px.
 */
import { computed } from 'vue';
import { buildPitchMap, findResolutionPairs, arrowColor, SVG_W, SVG_H } from './guideToneResolution.js';

const props = defineProps({
    chordA:   { type: Object, default: null },
    chordB:   { type: Object, default: null },
    tileW:    { type: Number, default: 110 },
    gapW:     { type: Number, default: 8 },
    tilePadX: { type: Number, default: 20 },  // total horizontal padding (left+right)
});

// Diagram rendered width inside each tile
const diagW  = computed(() => props.tileW - props.tilePadX);
// px per SVG unit
const scale  = computed(() => diagW.value / SVG_W);
// Left padding of diagram inside tile (half of total pad)
const padL   = computed(() => props.tilePadX / 2);

// Total canvas: tile A + gap + tile B
const canvasW = computed(() => props.tileW * 2 + props.gapW);
const canvasH = computed(() => SVG_H * scale.value);

// Tile header height in px (chord name row)
const HEADER_H = 38;

const bridgeStyle = computed(() => ({
    position: 'absolute',
    top:  `${HEADER_H}px`,
    left: '0px',
    width:  `${canvasW.value}px`,
    height: `${canvasH.value}px`,
    pointerEvents: 'none',
    zIndex: 10,
    overflow: 'visible',
}));

const pairs = computed(() => {
    if (!props.chordA?.diagramData || !props.chordB?.diagramData) return [];
    const mapA     = buildPitchMap(props.chordA.diagramData);
    const mapB     = buildPitchMap(props.chordB.diagramData);
    const qualityB = (props.chordB.diagramData as any)?.quality ?? '';
    const level    = (props.chordB.diagramData as any)?.extensions ? 2 : 1;
    return findResolutionPairs(mapA, mapB, qualityB, level);
});

/** SVG-space dot → canvas px coords for diagram A (left tile). */
function toPxA(svgX: number, svgY: number) {
    const sc = scale.value;
    return { cx: padL.value + svgX * sc, cy: svgY * sc };
}

/** SVG-space dot → canvas px coords for diagram B (right tile). */
function toPxB(svgX: number, svgY: number) {
    const sc = scale.value;
    return { cx: props.tileW + props.gapW + padL.value + svgX * sc, cy: svgY * sc };
}

const arrows = computed(() => {
    const sc = scale.value;
    return (pairs.value as any[]).map((pair: any) => {
        const from  = toPxA(pair.from.svgX, pair.from.svgY);
        const to    = toPxB(pair.to.svgX,   pair.to.svgY);
        const color = arrowColor(pair.type);

        const dx = to.cx - from.cx;
        const dy = to.cy - from.cy;
        const angle = Math.atan2(dy, dx);
        const hLen  = 5;
        const ax = to.cx - hLen * Math.cos(angle - 0.4);
        const ay = to.cy - hLen * Math.sin(angle - 0.4);
        const bx = to.cx - hLen * Math.cos(angle + 0.4);
        const by = to.cy - hLen * Math.sin(angle + 0.4);

        return { from, to, color, ax, ay, bx, by, r: 3.5 * sc, label: pair.to.label };
    });
});
</script>

<template>
    <svg
        v-if="arrows.length"
        :style="bridgeStyle"
        :viewBox="`0 0 ${canvasW} ${canvasH}`"
        xmlns="http://www.w3.org/2000/svg"
    >
        <g v-for="(a, i) in arrows" :key="i">
            <line
                :x1="a.from.cx" :y1="a.from.cy"
                :x2="a.to.cx"   :y2="a.to.cy"
                :stroke="a.color"
                stroke-width="1.5"
                stroke-dasharray="4 3"
                opacity="0.65"
            />
            <polygon
                :points="`${a.to.cx},${a.to.cy} ${a.ax},${a.ay} ${a.bx},${a.by}`"
                :fill="a.color"
                opacity="0.85"
            />
            <circle
                class="gt-ghost"
                :cx="a.to.cx" :cy="a.to.cy" :r="a.r"
                :fill="a.color" fill-opacity="0.12"
                :stroke="a.color" stroke-width="1.2" stroke-dasharray="3 2"
            />
            <text
                class="gt-ghost"
                :x="a.to.cx" :y="a.to.cy"
                font-size="5" font-weight="800"
                text-anchor="middle" dominant-baseline="central"
                :fill="a.color"
            >{{ a.label }}</text>
        </g>
    </svg>
</template>

<style>
@keyframes gt-ghost-pulse {
    0%, 100% { opacity: 0.45; }
    50%       { opacity: 0.85; }
}
.gt-ghost { animation: gt-ghost-pulse 2s ease-in-out infinite; }
</style>
