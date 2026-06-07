<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue';

export interface ChordShape {
    name: string;
    frets: string;       // fret string e.g. "x32010", compatible with sbnRenderDiagramSVG
    position: number;    // start fret (1 = open position)
    fingers?: string;    // finger string e.g. "032010"
    intervalLabels?: string; // comma-separated e.g. "x,R,5,b7,b3,x"
}

const props = defineProps<{
    chords: ChordShape[];
}>();

const sectionEl = ref<HTMLElement | null>(null);

const MAGNET_RADIUS = 160;
const MAGNET_SCALE  = 1.38;
const MAGNET_LIFT   = 18;

let rafId = 0;
let running = false;
let resizeTimer = 0;
let mouseX = -9999;
let mouseY = -9999;

interface CardState {
    el: HTMLElement;
    col: number;
    tier: number;
    speed: number;
    baseOpacity: number;
    y: number;
    cardH: number;
    cardW: number;
    chordIdx: number;
    x: number;
}

let cards: CardState[] = [];
let lastTs = 0;
let sectionW = 0;
let sectionH = 0;

function renderSVG(chord: ChordShape): string {
    const fn = (window as any).sbnRenderDiagramSVG;
    if (!fn) return '';
    return fn(
        { frets: chord.frets, fret_string: chord.frets, position: chord.position, start_fret: chord.position, fingers: chord.fingers ?? '000000' },
        { showFingers: false, intervalLabels: chord.intervalLabels ?? undefined }
    );
}

function buildCard(chord: ChordShape, w: number): HTMLElement {
    const wrap = document.createElement('div');
    wrap.className = 'chord-rain-card';
    wrap.style.cssText = `position:absolute;width:${w}px;pointer-events:auto;border-radius:8px;background:#fff;border:1px solid var(--clr-line);padding:5px 6px 6px;box-sizing:border-box;`;

    const label = document.createElement('div');
    const formatFn = (window as any).sbnFormatChordHtml;
    if (formatFn) {
        label.innerHTML = formatFn(chord.name);
    } else {
        label.textContent = chord.name;
    }
    label.style.cssText = 'font-size:11px;font-weight:700;text-align:center;color:var(--clr-text);letter-spacing:.01em;margin-bottom:4px;white-space:nowrap;overflow:hidden;line-height:1.3;';
    wrap.appendChild(label);

    const svg = document.createElement('div');
    svg.innerHTML = renderSVG(chord);
    svg.style.cssText = 'line-height:0;';
    wrap.appendChild(svg);

    return wrap;
}

const TIER_W       = [58, 80, 108];
const TIER_OPACITY = [0.22, 0.46, 0.78];
const TIER_SPEED   = [22, 16, 10];

function scatter() {
    if (!sectionEl.value) return;
    teardown();

    sectionW = sectionEl.value.offsetWidth;
    sectionH = sectionEl.value.offsetHeight;

    // Constrain column layout to home-wrap width (max 1200px), centered
    const WRAP_MAX = 1200;
    const WRAP_PAD = 24;
    const innerW = Math.min(sectionW, WRAP_MAX) - WRAP_PAD * 2;
    const offsetX = Math.max(0, (sectionW - innerW) / 2);

    const COL_W = 80 + 12;
    const numCols = Math.max(1, Math.floor(innerW / COL_W));
    const CARDS_PER_COL = 5;
    const SLOT_H = sectionH / CARDS_PER_COL;

    const canvas = sectionEl.value.querySelector('.chord-rain-canvas') as HTMLElement;
    if (!canvas) return;
    canvas.innerHTML = '';
    cards = [];

    let chordIdx = 0;
    for (let col = 0; col < numCols; col++) {
        const baseTier = col % 3;
        const tier = Math.random() < 0.15 ? (baseTier + 1) % 3 : baseTier;
        const w = TIER_W[tier];
        const speed = TIER_SPEED[tier] + (Math.random() * 4 - 2);
        const baseOpacity = TIER_OPACITY[tier];
        const phaseOffset = Math.random() * SLOT_H;
        const colX = offsetX + (col / numCols) * innerW + (innerW / numCols - w) / 2;

        for (let k = 0; k < CARDS_PER_COL; k++) {
            const chord = props.chords[chordIdx % props.chords.length];
            chordIdx++;
            const cardEl = buildCard(chord, w);
            canvas.appendChild(cardEl);
            const cardH = Math.round(w * 1.18) + 22; // svg + label

            const startY = k * SLOT_H + phaseOffset + Math.random() * Math.max(0, SLOT_H - cardH - 8);

            cardEl.style.left = `${colX}px`;
            cardEl.style.top = '0px';

            cards.push({
                el: cardEl,
                col,
                tier,
                speed,
                baseOpacity: baseOpacity + (Math.random() * 0.1 - 0.05),
                y: startY,
                cardH,
                cardW: w,
                chordIdx: (chordIdx - 1) % props.chords.length,
                x: colX,
            });
        }
    }

    lastTs = performance.now();
    running = true;
    rafId = requestAnimationFrame(tick);
}

function swapChord(state: CardState) {
    state.chordIdx = (state.chordIdx + 1) % props.chords.length;
    const chord = props.chords[state.chordIdx];
    const children = state.el.querySelectorAll('div');
    // children[0] = label, children[1] = svg wrapper
    const label = children[0] as HTMLElement;
    const svgDiv = children[1] as HTMLElement;
    if (label) {
        const formatFn = (window as any).sbnFormatChordHtml;
        if (formatFn) { label.innerHTML = formatFn(chord.name); }
        else { label.textContent = chord.name; }
    }
    if (svgDiv) svgDiv.innerHTML = renderSVG(chord);
}

function clamp(v: number, lo: number, hi: number) {
    return v < lo ? lo : v > hi ? hi : v;
}

function smoothstep(v: number) {
    v = clamp(v, 0, 1);
    return v * v * (3 - 2 * v);
}

function tick(ts: number) {
    if (!running) return;
    const dt = Math.min((ts - lastTs) / 1000, 0.05);
    lastTs = ts;

    for (const state of cards) {
        state.y += state.speed * dt;

        if (state.y > sectionH + 20) {
            state.y = -(state.cardH + 20);
            swapChord(state);
        }

        // Depth fade
        const fadeIn  = smoothstep((state.y + state.cardH) / (sectionH * 0.12));
        const fadeOut = smoothstep((sectionH - state.y)    / (sectionH * 0.12));
        let opacity = state.baseOpacity * Math.min(fadeIn, fadeOut);

        // Magnetic field
        const cx = state.x + state.cardW / 2;
        const cy = state.y + state.cardH / 2;
        const dist = Math.hypot(cx - mouseX, cy - mouseY);
        const proximity = Math.max(0, 1 - dist / MAGNET_RADIUS);
        const ease = proximity * proximity;
        const scale = 1 + ease * (MAGNET_SCALE - 1);

        if (ease > 0.02) {
            opacity = Math.min(1, opacity + ease * 0.3);
            state.el.style.boxShadow = `0 ${ease * MAGNET_LIFT}px ${ease * MAGNET_LIFT * 2 + 12}px -4px rgba(80,60,20,${(ease * 0.30).toFixed(2)})`;
            state.el.style.zIndex = ease > 0.05 ? '10' : '';
        } else {
            state.el.style.boxShadow = '';
            state.el.style.zIndex = '';
        }

        state.el.style.transform = `translate(0, ${state.y}px) scale(${scale})`;
        state.el.style.opacity   = String(opacity);
    }

    rafId = requestAnimationFrame(tick);
}

function teardown() {
    running = false;
    cancelAnimationFrame(rafId);
    cards = [];
}

function onMouseMove(e: MouseEvent) {
    if (!sectionEl.value) return;
    const rect = sectionEl.value.getBoundingClientRect();
    mouseX = e.clientX - rect.left;
    mouseY = e.clientY - rect.top;
}

function onMouseLeave() {
    mouseX = -9999;
    mouseY = -9999;
}

function onResize() {
    clearTimeout(resizeTimer);
    resizeTimer = window.setTimeout(scatter, 200);
}

const reduce = typeof window !== 'undefined' && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

onMounted(() => {
    if (!sectionEl.value || !props.chords.length) return;
    window.addEventListener('resize', onResize);
    // Wait a frame for sbnRenderDiagramSVG to be available
    requestAnimationFrame(() => {
        scatter();
        if (reduce) {
            running = false;
            cancelAnimationFrame(rafId);
        }
    });
});

onUnmounted(() => {
    teardown();
    window.removeEventListener('resize', onResize);
    clearTimeout(resizeTimer);
});
</script>

<template>
    <section
        ref="sectionEl"
        class="chord-rain-section"
        @mousemove="onMouseMove"
        @mouseleave="onMouseLeave"
    >
        <!-- Rain canvas — z-index 1 -->
        <div class="chord-rain-canvas" />

        <!-- Vignette — z-index 2 -->

        <!-- Copy — z-index 3 -->
        <div class="chord-rain-copy">
            <div class="eyebrow">240+ voicings mapped</div>
            <h2>Every chord,<br><em>colour-coded</em> by function.</h2>
            <p>Root, third, fifth, seventh — the theory is visible in every dot, on every shape, across all six strings.</p>
            <a href="/library/chords" class="btn btn-solid btn-lg">Browse the voicing library →</a>
            <div class="rain-stats">
                <div class="rain-stat"><span class="rain-stat-n">240+</span><span class="rain-stat-l">Voicings</span></div>
                <div class="rain-stat"><span class="rain-stat-n">8</span><span class="rain-stat-l">Chord qualities</span></div>
                <div class="rain-stat"><span class="rain-stat-n">4</span><span class="rain-stat-l">Depth tiers</span></div>
            </div>
        </div>
    </section>
</template>
