<script setup lang="ts">
/**
 * SyncedHero — sliding chord track + mini rhythm grid, shared clock.
 *
 * Layout: 5 persistent board slots [off-left · prev · CENTER · next · off-right].
 * On each bar the whole track translates one slot-width left; boards cross-fade
 * to their next roles; on transitionend the leftmost board is recycled to the
 * right end. `strike()` always fires on boards[CENTER_IDX] (index 2).
 *
 * Clock is injected via useClock — swap for Tone.js Transport with no view changes.
 */
import { ref, onMounted, onBeforeUnmount } from 'vue';
import { useClock, type StepType } from './useClock';

// ── Chord data ──────────────────────────────────────────────────────────────
// Format: frets[6] string 6→1; fret 0=open, -1=mute. roles[6] = R|3|5|7|null
interface ChordDef {
    name: string;
    sub: string;
    frets: (number | null)[];
    roles: (string | null)[];
}

const CHORDS: ChordDef[] = [
    { name: 'Cmaj7',  sub: 'Bright · major seventh', frets: [-1,3,2,0,0,0],    roles: [null,'R','7','3','5','R'] },
    { name: 'Dm7',    sub: 'Warm · minor seventh',   frets: [-1,0,0,2,1,1],    roles: [null,'R','5','7','3','5'] },
    { name: 'G13',    sub: 'Tense · dominant',       frets: [3,-1,3,4,5,-1],   roles: ['R',null,'7','3','13',null] },
    { name: 'Am7',    sub: 'Mellow · minor seventh', frets: [-1,0,2,0,1,0],    roles: [null,'R','5','7','3','5'] },
    { name: 'Fmaj7',  sub: 'Bright · major seventh', frets: [-1,-1,3,2,1,0],   roles: [null,null,'R','7','3','5'] },
];

const PATTERN: StepType[] = ['accent','rest','rest','ghost','accent','rest','ghost','rest','rest','accent','rest','ghost','accent','rest','ghost','rest'];
const BPM = 132;
const SLIDE_MS = 560;
const slideMs = ref(SLIDE_MS); // needed for v-bind() in scoped CSS
const CENTER_IDX = 2;
const ROLES = ['off', 'side', 'center', 'side', 'off'] as const;
const LBLS  = ['', 'prev', '', 'next', ''];

// ── Fretboard geometry (matches prototype's 160×200 viewBox) ────────────────
const FB_W = 160, FB_H = 200;
const FB_PAD = 26, FB_TOP = 24, FB_BOT = 176;
const strX  = (s: number) => FB_PAD + (FB_W - 2 * FB_PAD) * s / 5;
const fretY = (f: number) => FB_TOP + (FB_BOT - FB_TOP) * f / 5;
const dotCy = (fret: number) => (fretY(fret - 1) + fretY(fret)) / 2;

function roleColor(r: string | null): string {
    if (r === 'R')  return 'var(--clr-root)';
    if (r === '3')  return 'var(--clr-third)';
    if (r === '5')  return 'var(--clr-fifth)';
    if (r === '7')  return 'var(--clr-seventh)';
    return 'var(--clr-text-dim)';
}

// ── Reactive board state ─────────────────────────────────────────────────────
interface BoardState {
    chord: ChordDef;
    role: typeof ROLES[number];
    label: string;
    striking: boolean;
}

const boards = ref<BoardState[]>(
    ROLES.map((role, k) => ({
        chord: CHORDS[(k - CENTER_IDX + CHORDS.length * 2) % CHORDS.length],
        role,
        label: LBLS[k],
        striking: false,
    }))
);

// Track offset for the sliding animation
const trackOffset = ref(0);
const trackTransition = ref(false);
let head = ref(0);
let sliding = false;

// DOM ref for the track element (needed to measure dx)
const trackEl = ref<HTMLElement | null>(null);
const boardEls = ref<HTMLElement[]>([]);

// ── Strike (dot pulse on center board) ──────────────────────────────────────
function strikeCenter() {
    const b = boards.value[CENTER_IDX];
    // Toggle striking off then on to re-trigger CSS animation
    b.striking = false;
    requestAnimationFrame(() => { b.striking = true; });
}

// ── Advance (one bar — slide + recycle) ─────────────────────────────────────
function advance() {
    if (sliding) return;
    head.value = (head.value + 1) % CHORDS.length;

    const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (reduce) {
        // Static: reassign chords around new head, no animation
        boards.value.forEach((b, k) => {
            b.chord = CHORDS[(head.value + (k - CENTER_IDX) + CHORDS.length * 2) % CHORDS.length];
        });
        strikeCenter();
        return;
    }

    sliding = true;

    // Measure dx between adjacent board centers using the real DOM
    const bEls = boardEls.value;
    if (!bEls[1] || !bEls[2]) { sliding = false; return; }
    const r1 = bEls[1].getBoundingClientRect();
    const r2 = bEls[2].getBoundingClientRect();
    const dx = (r2.left + r2.width / 2) - (r1.left + r1.width / 2);

    // Cross-fade roles toward next positions during the slide
    boards.value.forEach((b, k) => {
        b.role = ROLES[k - 1] ?? 'off';
        b.label = LBLS[k - 1] ?? '';
    });

    trackTransition.value = true;
    trackOffset.value = -dx;
}

function onTransitionEnd() {
    if (!sliding) return;

    // Recycle: pop the first board to the end, load the new look-ahead chord
    const first = boards.value.shift()!;
    boards.value.push(first);

    // Snap transform back — disable transition first
    trackTransition.value = false;
    trackOffset.value = 0;

    // Restore canonical roles
    boards.value.forEach((b, k) => {
        b.role  = ROLES[k];
        b.label = LBLS[k];
    });

    // Repaint chords in their new positions
    boards.value.forEach((b, k) => {
        b.chord = CHORDS[(head.value + (k - CENTER_IDX) + CHORDS.length * 2) % CHORDS.length];
    });

    sliding = false;
}

// ── Current rhythm step for the mini grid ───────────────────────────────────
const currentStep = ref(-1);

// ── Clock wiring ─────────────────────────────────────────────────────────────
const clock = useClock({
    bpm: BPM,
    pattern: PATTERN,
    onStep(step, type) {
        currentStep.value = step;
        if (type === 'accent') strikeCenter();
    },
    onBar() {
        advance();
    },
});

onMounted(() => {
    const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (!reduce) clock.start();
});

onBeforeUnmount(() => {
    clock.stop();
});

// ── Dot visibility helpers ───────────────────────────────────────────────────
function visibleDots(chord: ChordDef) {
    return chord.frets
        .map((fret, s) => ({ fret, s, role: chord.roles[s] }))
        .filter(d => d.fret !== null && d.fret > 0);
}
</script>

<template>
    <div class="synced-hero">
        <span class="demo-tag">Live · play-along</span>

        <!-- Board track -->
        <div class="board-viewport">
            <div
                ref="trackEl"
                class="board-track"
                :style="{
                    transform: `translateX(${trackOffset}px)`,
                    transition: trackTransition ? `transform ${SLIDE_MS}ms cubic-bezier(.25,.9,.25,1)` : 'none',
                }"
                @transitionend="onTransitionEnd"
            >
                <div
                    v-for="(board, k) in boards"
                    :key="k"
                    :ref="el => { if (el) boardEls[k] = el as HTMLElement }"
                    class="board"
                    :data-role="board.role"
                >
                    <div class="board-label">{{ board.label }}</div>
                    <div class="board-name">{{ board.chord.name }}</div>
                    <div class="board-sub">{{ board.chord.sub }}</div>

                    <!-- Fretboard SVG -->
                    <svg
                        class="board-fretboard"
                        :viewBox="`0 0 ${FB_W} ${FB_H}`"
                        :width="FB_W"
                        :height="FB_H"
                    >
                        <!-- Strings -->
                        <line
                            v-for="s in 6"
                            :key="`s${s}`"
                            :x1="strX(s-1)" :y1="FB_TOP"
                            :x2="strX(s-1)" :y2="FB_BOT"
                            class="fb-string"
                        />
                        <!-- Fret lines (0 = nut) -->
                        <line
                            v-for="f in 6"
                            :key="`f${f}`"
                            :x1="FB_PAD" :y1="fretY(f-1)"
                            :x2="FB_W - FB_PAD" :y2="fretY(f-1)"
                            :class="f === 1 ? 'fb-nut' : 'fb-fret'"
                        />
                        <!-- Dots -->
                        <circle
                            v-for="(d, di) in visibleDots(board.chord)"
                            :key="di"
                            :cx="strX(d.s)"
                            :cy="dotCy(d.fret as number)"
                            r="8"
                            :fill="roleColor(d.role)"
                            :class="['fb-dot', board.role === 'center' && board.striking ? 'struck' : '']"
                        />
                    </svg>
                </div>
            </div>
        </div>

        <!-- Interval role legend -->
        <div class="hero-legend">
            <span><i class="legend-dot" style="background:var(--clr-root)"></i>Root</span>
            <span><i class="legend-dot" style="background:var(--clr-third)"></i>3rd</span>
            <span><i class="legend-dot" style="background:var(--clr-fifth)"></i>5th</span>
            <span><i class="legend-dot" style="background:var(--clr-seventh)"></i>7th</span>
        </div>

        <!-- Mini rhythm grid (shares the same clock) -->
        <div class="mini-rhythm">
            <div class="mini-bar">
                <div
                    v-for="(type, i) in PATTERN"
                    :key="i"
                    :class="[
                        'mini-cell',
                        type === 'accent' ? 'accent' : '',
                        type === 'ghost'  ? 'ghost'  : '',
                        i === currentStep ? 'active' : '',
                    ]"
                ></div>
            </div>
            <div class="mini-beats">
                <span>1</span><span>2</span><span>3</span><span>4</span>
            </div>
        </div>
    </div>
</template>

<style scoped>
.synced-hero {
    position: relative;
    background: var(--clr-bg-elev);
    border: 1px solid var(--clr-line);
    border-radius: 24px;
    padding: 28px 20px;
    box-shadow: 0 30px 60px -28px rgba(80,60,20,.28);
    overflow: hidden;
}

.demo-tag {
    position: absolute;
    top: -12px; left: 24px;
    background: var(--clr-fifth, var(--clr-accent-2));
    color: #fff;
    font-size: .72rem;
    font-weight: 700;
    letter-spacing: .06em;
    text-transform: uppercase;
    padding: 5px 12px;
    border-radius: 8px;
}

/* ── Sliding track ── */
.board-viewport {
    overflow: hidden;
}
.board-track {
    display: flex;
    align-items: center;
    will-change: transform;
}

.board {
    flex: 0 0 auto;
    text-align: center;
    transition:
        opacity v-bind("slideMs + 'ms'"),
        filter   v-bind("slideMs + 'ms'"),
        width    v-bind("slideMs + 'ms'");
}

.board[data-role="center"] { width: 200px; }
.board[data-role="side"]   { width: 128px; }
.board[data-role="off"]    { width: 128px; }

.board-label {
    font-size: .66rem;
    text-transform: uppercase;
    letter-spacing: .1em;
    color: var(--clr-text-dim);
    opacity: .6;
    height: 1em;
    margin-bottom: 4px;
    transition: opacity v-bind("slideMs + 'ms'");
}
.board[data-role="center"] .board-label { opacity: 0; }

.board-name {
    font-family: var(--font-display);
    margin-bottom: 4px;
    transition: font-size v-bind("slideMs + 'ms'"), opacity v-bind("slideMs + 'ms'");
}
.board[data-role="center"] .board-name { font-size: 1.9rem; font-weight: 600; opacity: 1; }
.board[data-role="side"]   .board-name { font-size: 1rem;   opacity: .55; }
.board[data-role="off"]    .board-name { font-size: 1rem;   opacity: 0; }

.board-sub {
    color: var(--clr-text-dim);
    font-size: .82rem;
    height: 1.1em;
    margin-bottom: 8px;
    transition: opacity v-bind("slideMs + 'ms'");
}
.board[data-role="center"] .board-sub { opacity: 1; }
.board[data-role="side"]   .board-sub { opacity: 0; }
.board[data-role="off"]    .board-sub { opacity: 0; }

.board-fretboard {
    display: block;
    margin: 0 auto;
    transition: opacity v-bind("slideMs + 'ms'"), filter v-bind("slideMs + 'ms'");
}
.board[data-role="center"] .board-fretboard { opacity: 1; filter: none; }
.board[data-role="side"]   .board-fretboard { opacity: .34; filter: grayscale(.45); }
.board[data-role="off"]    .board-fretboard { opacity: 0;   filter: grayscale(.6); }

/* ── Fretboard primitives ── */
.fb-string {
    stroke: var(--clr-line);
    stroke-width: 2;
}
.fb-fret {
    stroke: var(--clr-line);
    stroke-width: 2;
}
.fb-nut {
    stroke: var(--clr-text-dim);
    stroke-width: 5;
}
.fb-dot {
    transform-box: fill-box;
    transform-origin: center;
    transition: opacity .25s, fill .3s;
}
.fb-dot.struck {
    animation: hero-strike .42s cubic-bezier(.2,1.6,.4,1);
}
@keyframes hero-strike {
    0%   { transform: scale(1); }
    40%  { transform: scale(1.42); }
    100% { transform: scale(1); }
}

/* ── Legend ── */
.hero-legend {
    display: flex;
    justify-content: center;
    gap: 16px;
    margin-top: 18px;
    flex-wrap: wrap;
}
.hero-legend span {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: .76rem;
    color: var(--clr-text-dim);
}
.legend-dot {
    width: 11px; height: 11px;
    border-radius: 50%;
    display: inline-block;
}

/* ── Mini rhythm grid ── */
.mini-rhythm {
    margin-top: 22px;
    padding-top: 20px;
    border-top: 1px solid var(--clr-line);
}
.mini-bar {
    display: grid;
    grid-template-columns: repeat(16, 1fr);
    gap: 4px;
    height: 44px;
    align-items: end;
}
.mini-cell {
    background: var(--clr-bg-card);
    border: 1px solid var(--clr-line);
    border-radius: 4px;
    height: 34%;
    transition: transform .1s, box-shadow .1s;
}
.mini-cell.accent {
    height: 100%;
    background: linear-gradient(180deg, var(--clr-accent), color-mix(in srgb, var(--clr-accent) 70%, black));
    border-color: transparent;
}
.mini-cell.ghost {
    height: 62%;
    background: linear-gradient(180deg, var(--clr-fifth, var(--clr-accent-2)), color-mix(in srgb, var(--clr-fifth, var(--clr-accent-2)) 70%, black));
    border-color: transparent;
    opacity: .9;
}
.mini-cell.active {
    transform: scaleY(1.06) translateY(-2px);
    box-shadow: 0 4px 12px -4px var(--clr-accent);
}
.mini-beats {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    margin-top: 10px;
    font-size: .7rem;
    color: var(--clr-text-dim);
    letter-spacing: .12em;
}

/* ── Reduced motion ── */
@media (prefers-reduced-motion: reduce) {
    .board-track { transition: none !important; }
    .board,
    .board-name,
    .board-sub,
    .board-fretboard,
    .board-label { transition: none !important; }
    .fb-dot.struck { animation: none !important; }
}
</style>
