<script setup lang="ts">
/**
 * AnimatedChordDiagram — native SVG fretboard with animatable dots.
 * Mirrors the coordinate system of sbnRenderDiagramSVG in public/js/chords.js:
 *   W=88 H=95  strSp=12  fretSp=16  left=14  top=12  numFrets=4
 */
import { computed, ref, watch } from 'vue';
import type { ChordDiagramData } from '@/Components/Library/ChordDiagram.vue';

const W = 88, H = 95;
const STR_SP = 12, FRET_SP = 16;
const LEFT = 14, TOP = 12, NUM_FRETS = 4;

interface Props {
    chord: ChordDiagramData;
    targetChord?: ChordDiagramData | null;
    animating?: boolean;
    dotColor?: string;
}

const props = withDefaults(defineProps<Props>(), {
    targetChord: null,
    animating: false,
    dotColor: 'var(--clr-red)',
});

function strX(s: number): number {
    return LEFT + (s - 1) * STR_SP;
}

function dotCy(fretVal: number, startFret: number): number {
    const rf = fretVal - startFret + 1;
    return TOP + rf * FRET_SP - FRET_SP / 2;
}

// ── Dot model ────────────────────────────────────────────────────────────

interface RenderDot {
    id: string;          // stable: "s{string}"
    string: number;
    cx: number;
    cy: number;          // actual SVG y — static anchor; use translateY for animation
    translateY: number;  // runtime offset (CSS transition-able)
    opacity: number;
    animate: boolean;    // false during instant commits (no transition flash)
}

interface DiagramState {
    dots: Array<{ string: number; fret: number; cy: number }>;
    muted: number[];
    open: number[];
    startFret: number;
    barres: ChordDiagramData['diagram_data']['barres'];
}

function parseChord(chord: ChordDiagramData): DiagramState {
    const data = chord.diagram_data;
    const sf = chord.start_fret ?? 1;
    const dots: DiagramState['dots'] = [];
    const mutedSet = new Set<number>(data.muted ?? []);

    for (const pos of data.positions ?? []) {
        dots.push({ string: pos.string, fret: pos.fret, cy: dotCy(pos.fret, sf) });
    }

    for (const b of data.barres ?? []) {
        const from = Math.min(b.from, b.to);
        const to   = Math.max(b.from, b.to);
        const existing = new Set(dots.map(d => d.string));
        for (let s = from; s <= to; s++) {
            if (!existing.has(s) && !mutedSet.has(s)) {
                dots.push({ string: s, fret: b.fret, cy: dotCy(b.fret, sf) });
            }
        }
    }

    return {
        dots,
        muted: data.muted ?? [],
        open:  data.open  ?? [],
        startFret: sf,
        barres: data.barres ?? [],
    };
}

// ── Reactive state ────────────────────────────────────────────────────────

const renderDots   = ref<RenderDot[]>([]);
const shownMuted   = ref<number[]>([]);
const shownOpen    = ref<number[]>([]);
const shownBarres  = ref<ChordDiagramData['diagram_data']['barres']>([]);
const shownStartFret = ref(1);
const isRunning    = ref(false);

function loadStatic(chord: ChordDiagramData) {
    const s = parseChord(chord);
    renderDots.value = s.dots.map(d => ({
        id:         `s${d.string}`,
        string:     d.string,
        cx:         strX(d.string),
        cy:         d.cy,
        translateY: 0,
        opacity:    1,
        animate:    false,
    }));
    shownMuted.value     = s.muted;
    shownOpen.value      = s.open;
    shownBarres.value    = s.barres;
    shownStartFret.value = s.startFret;
}

loadStatic(props.chord);

watch(() => props.chord, (c) => {
    if (!isRunning.value) loadStatic(c);
}, { deep: true });

watch(() => props.animating, async (go) => {
    if (!go || !props.targetChord || isRunning.value) return;
    await animate(parseChord(props.chord), parseChord(props.targetChord));
});

// ── Animation ─────────────────────────────────────────────────────────────

async function animate(from: DiagramState, to: DiagramState) {
    isRunning.value = true;

    const fromMap = new Map(from.dots.map(d => [d.string, d]));
    const toMap   = new Map(to.dots.map(d  => [d.string, d]));
    const allStrings = new Set([...fromMap.keys(), ...toMap.keys()]);

    // Build initial render: all from-dots at their from positions, to-only dots
    // placed at their target cy but invisible (so they don't flash then fade in).
    const initial: RenderDot[] = [];
    for (const s of allStrings) {
        const f = fromMap.get(s);
        const t = toMap.get(s);
        const cx = strX(s);

        if (f && t) {
            // Shared string — anchor at from.cy, translateY = 0
            initial.push({ id: `s${s}`, string: s, cx, cy: f.cy, translateY: 0, opacity: 1, animate: false });
        } else if (f) {
            // Will disappear
            initial.push({ id: `s${s}`, string: s, cx, cy: f.cy, translateY: 0, opacity: 1, animate: false });
        } else if (t) {
            // Will appear — anchor at target cy, start transparent
            initial.push({ id: `s${s}`, string: s, cx, cy: t.cy, translateY: 0, opacity: 0, animate: false });
        }
    }
    renderDots.value = initial;

    // Let browser commit initial state
    await nextFrame();

    // Apply target positions with transitions enabled:
    // - shared: translateY = to.cy - from.cy  (slides)
    // - disappearing: opacity → 0
    // - appearing: opacity → 1 (cy already at target, translateY stays 0)
    renderDots.value = initial.map(d => {
        const s = d.string;
        const f = fromMap.get(s);
        const t = toMap.get(s);
        if (f && t) {
            return { ...d, translateY: t.cy - f.cy, opacity: 1, animate: true };
        } else if (f && !t) {
            return { ...d, opacity: 0, animate: true };
        } else {
            // appearing
            return { ...d, opacity: 1, animate: true };
        }
    });

    await delay(650);

    // Finalise: re-anchor sliding dots WITHOUT transition (animate: false prevents
    // the CSS transition from firing as translateY resets to 0 and cy jumps to target).
    renderDots.value = Array.from(toMap.values()).map(t => ({
        id:         `s${t.string}`,
        string:     t.string,
        cx:         strX(t.string),
        cy:         t.cy,
        translateY: 0,
        opacity:    1,
        animate:    false,
    }));

    shownMuted.value     = to.muted;
    shownOpen.value      = to.open;
    shownBarres.value    = to.barres;
    shownStartFret.value = to.startFret;

    isRunning.value = false;
}

function nextFrame(): Promise<void> {
    return new Promise(r => requestAnimationFrame(() => requestAnimationFrame(r)));
}

function delay(ms: number): Promise<void> {
    return new Promise(r => setTimeout(r, ms));
}

const nutOrPosition = computed(() => {
    const data = props.chord.diagram_data;
    const posFrets = (data.positions ?? []).map(p => p.fret);
    const barreFrets = (data.barres ?? []).map(b => b.fret);
    const maxFret = Math.max(0, ...posFrets, ...barreFrets);
    const hasOpen = (data.open ?? []).length > 0 || posFrets.some(f => f === 0);
    // Nut only when all frets ≤ 4 AND there are open strings (movable shapes at low frets get a position marker)
    return (maxFret > 0 && maxFret <= 4 && hasOpen) ? 1 : shownStartFret.value;
});
</script>

<template>
    <svg
        class="sbn-chord-svg anim-diagram"
        :viewBox="`0 0 ${W} ${H}`"
        width="100%"
    >
        <!-- Nut for position 1; position label otherwise -->
        <rect
            v-if="nutOrPosition <= 1"
            :x="LEFT - 1"
            :y="TOP - 3"
            :width="STR_SP * 5 + 2"
            height="3"
            fill="var(--clr-text)"
            rx="0.5"
        />
        <text
            v-if="nutOrPosition > 1"

            x="1"
            :y="TOP + FRET_SP / 2 + 4"
            font-size="10"
            fill="var(--clr-text-muted)"
        >{{ nutOrPosition }}</text>

        <!-- Fret lines -->
        <line
            v-for="f in NUM_FRETS + 1"
            :key="`fl${f}`"
            :x1="LEFT"
            :y1="TOP + (f - 1) * FRET_SP"
            :x2="LEFT + STR_SP * 5"
            :y2="TOP + (f - 1) * FRET_SP"
            stroke="var(--clr-text)"
            stroke-width="0.4"
            opacity="0.4"
        />

        <!-- String lines -->
        <line
            v-for="s in 6"
            :key="`sl${s}`"
            :x1="LEFT + (s - 1) * STR_SP"
            :y1="nutOrPosition <= 1 ? TOP : TOP - 6"
            :x2="LEFT + (s - 1) * STR_SP"
            :y2="TOP + FRET_SP * NUM_FRETS + 5"
            stroke="var(--clr-text)"
            stroke-width="0.4"
            opacity="0.5"
        />

        <!-- Open (○) markers -->
        <circle
            v-for="s in shownOpen"
            :key="`o${s}`"
            :cx="strX(s)"
            :cy="TOP - 8"
            r="3"
            fill="none"
            stroke="var(--clr-text)"
            stroke-width="0.75"
        />

        <!-- Barre bar (cross-string pill) -->
        <rect
            v-for="(b, bi) in shownBarres"
            :key="`b${bi}`"
            :x="strX(Math.min(b.from, b.to)) - 4.5"
            :y="dotCy(b.fret, shownStartFret) - 4.5"
            :width="Math.abs(b.to - b.from) * STR_SP + 9"
            height="9"
            :fill="dotColor"
            rx="4.5"
            opacity="0.9"
        />

        <!-- Animated dots -->
        <circle
            v-for="dot in renderDots"
            :key="dot.id"
            class="anim-dot"
            :cx="dot.cx"
            :cy="dot.cy"
            r="4.5"
            :fill="dotColor"
            :style="{
                transform: `translateY(${dot.translateY}px)`,
                opacity: dot.opacity,
                transition: dot.animate
                    ? 'transform 0.5s cubic-bezier(0.4,0,0.2,1), opacity 0.35s ease'
                    : 'none',
            }"
        />
    </svg>
</template>

<style scoped>
.anim-diagram {
    display: block;
    width: 100%;
    height: auto;
    overflow: visible;
}

.anim-dot {
    will-change: transform, opacity;
}
</style>
