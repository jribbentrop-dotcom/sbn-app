<script setup lang="ts">
/**
 * SyncedHero — sliding chord track + rhythm strip, shared clock.
 *
 * Layout: 5 persistent board slots [off-left · prev · CENTER · next · off-right].
 * On each bar the whole track translates one slot-width left; boards cross-fade
 * to their next roles; on transitionend the leftmost board is recycled to the
 * right end. `strike()` always fires on boards[CENTER_IDX] (index 2).
 *
 * Clock is injected via useClock — swap for Tone.js Transport with no view changes.
 *
 * Phase S.1: uses real ChordDiagramData + RhythmPatternData shapes.
 * Chord data is hardcoded for testing (Dm7 → G7 → Cmaj7 drop voicings).
 * Production path: pass `progression` + `rhythmPattern` as props (Phase S.3).
 */
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { useClock } from './useClock';
import type { StepType } from './useClock';
import ChordDiagram from '../Library/ChordDiagram.vue';
import type { ChordDiagramData } from '../Library/ChordDiagram.vue';
import type { RhythmPatternData } from '../Library/RhythmPattern.vue';

// ── Hardcoded test progression: Dm7 → G7 → Cmaj7 (drop voicings) ───────────
// diagram_data uses string numbers 1=low E … 6=high E (SBN/DB convention,
// per ChordShapeCalculator::TUNING). The renderer draws string 1 leftmost.
const CHORDS: ChordDiagramData[] = [
    {
        id: 1001,
        slug: 'dm7-drop2-test',
        name: 'Dm7',
        root_note: 'D',
        quality: 'min7',
        quality_label: 'Minor 7th',
        extensions: null,
        voicing_category: 'drop2',
        category_label: 'Drop 2',
        root_string: 'roota',
        root_string_label: 'Root on A',
        inversion: 'inv3',
        inversion_label: '3rd Inversion',
        bass_note: null,
        shape_family: null,
        start_fret: 5,
        diagram_data: {
            positions: [
                { string: 2, fret: 6, finger: 2 },
                { string: 3, fret: 7, finger: 3 },
                { string: 4, fret: 5, finger: 1 },
                { string: 5, fret: 5, finger: 1 },
            ],
            barres: [{ fret: 5, from: 4, to: 5, finger: 1 }],
            muted: [1, 6],
            open: [],
        },
        interval_labels: 'x,b7,b3,5,R,x',
        popularity: null,
        difficulty: null,
    },
    {
        id: 1002,
        slug: 'g7-drop3-test',
        name: 'G7',
        root_note: 'G',
        quality: 'dom7',
        quality_label: 'Dominant 7th',
        extensions: null,
        voicing_category: 'drop3',
        category_label: 'Drop 3',
        root_string: 'roote',
        root_string_label: 'Root on E',
        inversion: 'root',
        inversion_label: 'Root Position',
        bass_note: null,
        shape_family: null,
        start_fret: 3,
        diagram_data: {
            positions: [
                { string: 1, fret: 3, finger: 1 },
                { string: 3, fret: 3, finger: 2 },
                { string: 4, fret: 4, finger: 3 },
                { string: 5, fret: 5, finger: 4 },
            ],
            barres: [],
            muted: [2, 6],
            open: [],
        },
        interval_labels: 'R,x,b7,3,5,x',
        popularity: null,
        difficulty: null,
    },
    {
        id: 1003,
        slug: 'cmaj7-drop2-test',
        name: 'Cmaj7',
        root_note: 'C',
        quality: 'maj7',
        quality_label: 'Major 7th',
        extensions: null,
        voicing_category: 'drop2',
        category_label: 'Drop 2',
        root_string: 'rootd',
        root_string_label: 'Root on D',
        inversion: 'inv3',
        inversion_label: '3rd Inversion',
        bass_note: null,
        shape_family: null,
        start_fret: 1,
        diagram_data: {
            positions: [
                { string: 3, fret: 2, finger: 2 },
                { string: 4, fret: 2, finger: 3 },
                { string: 5, fret: 1, finger: 1 },
                { string: 6, fret: 1, finger: 1 },
            ],
            barres: [],
            muted: [1],
            open: [],
        },
        interval_labels: 'x,x,7,3,5,R',
        popularity: null,
        difficulty: null,
    },
];

// ── Hardcoded rhythm: Bossa Nova Clave (mirrors DB slug bossa-nova-clave) ────
// encoding matches DB: x=hit, .=rest
const RHYTHM: RhythmPatternData = {
    name: 'Bossa Nova Clave',
    beats: 16,
    gridType: 'sixteenth',
    fingers: 'x..x..x...x..x..',
    thumb:   'x...x...x...x...',
    bpm: 127,
    timeSignature: '4/4',
    percTop: 'fingers',
    percBass: 'thumb',
};

const BPM = Math.round(RHYTHM.bpm / 2);
// Slide is a visible glide but stays tight: the strike already targets the
// role=center board from the downbeat (see strikeCenter), so the slide is
// purely cosmetic and just needs to feel smooth without lagging the rhythm.
const SLIDE_MS = 420;
const slideMs = ref(SLIDE_MS);
// ONE shared easing for the track translate AND every board width/opacity
// transition. If they differ, the translate (toward a pre-measured target) and
// the width animation (which moves the slot centers) finish on different curves
// and drift apart by a sub-pixel — the "hiccup" at the end of the pull-in.
const SLIDE_EASE = 'cubic-bezier(.4,0,.2,1)';
const CENTER_IDX = 2;
const ROLES = ['off', 'side', 'center', 'side', 'off'] as const;
const LBLS  = ['', 'prev', '', 'next', ''];

// ── Pattern → StepType array (derived from fingers string) ──────────────────
// Real DB patterns use only lowercase x (hit) — uppercase X would be accent.
// Map x→ghost so the clock's onStep can detect hits for the strike pulse.
const PATTERN = computed((): StepType[] =>
    RHYTHM.fingers.split('').map((c): StepType =>
        c === 'X' ? 'accent' : c === 'x' ? 'ghost' : 'rest'
    )
);

// ── Reactive board state ─────────────────────────────────────────────────────
interface BoardState {
    chord: ChordDiagramData;
    role: typeof ROLES[number];
    label: string;
}

const boards = ref<BoardState[]>(
    ROLES.map((role, k) => ({
        chord: CHORDS[(k - CENTER_IDX + CHORDS.length * 2) % CHORDS.length],
        role,
        label: LBLS[k],
    }))
);

// ── Bass vs. fingers split (Gilberto technique) ──────────────────────────────
// The thumb plays the bass note (the lowest-pitched string in the voicing) and
// the fingers pluck the top three notes. `data-string` follows the SBN/DB
// convention (ChordShapeCalculator::TUNING): string 1 = LOW E … string 6 =
// HIGH E, so the bass is the LOWEST-numbered played string and the fingers are
// everything above it.
function bassString(chord: ChordDiagramData): number {
    const strings = (chord.diagram_data.positions ?? [])
        .map(p => p.string)
        .concat(chord.diagram_data.open ?? []);
    return strings.length ? Math.min(...strings) : 1;
}

// Re-trigger the CSS strike keyframe imperatively on a subset of the center
// board's dots. Toggling a class on the same elements is unreliable (same value
// → no restart, and the SVG is re-rendered by watchEffect), so we remove the
// class, force a reflow, then re-add it — the canonical CSS animation restart.
//   row 'bass'    → only the bass-string dot
//   row 'fingers' → every dot ABOVE the bass (the top notes)
function strikeCenter(row: 'bass' | 'fingers') {
    // Target the board whose ROLE is currently 'center', not the fixed physical
    // slot. During a slide the incoming chord already holds role 'center' (its
    // role was reassigned in advance()), while the physical center slot still
    // shows the OUTGOING chord until transitionend. Striking by role means the
    // rhythm hits the NEW chord's dots from the downbeat, not mid-slide.
    const idx = boards.value.findIndex(b => b.role === 'center');
    if (idx < 0) return;
    const el = boardEls.value[idx];
    if (!el) return;
    const diagram = el.querySelector<HTMLElement>('.board-diagram');
    if (!diagram) return;

    const bass = bassString(boards.value[idx].chord);
    const dots = Array.from(diagram.querySelectorAll<SVGCircleElement>('circle.sbn-svg-dot'));
    const target = dots.filter(dot => {
        const isBass = Number(dot.getAttribute('data-string')) === bass;
        return row === 'bass' ? isBass : !isBass;
    });

    // Remove from the targeted dots, force ONE reflow, then re-add — restarts
    // the keyframe even when the dots were already mid-animation.
    target.forEach(dot => dot.classList.remove('is-striking'));
    void diagram.offsetWidth;
    target.forEach(dot => dot.classList.add('is-striking'));
}

const trackOffset = ref(0);
const trackTransition = ref(false);
// When true, all per-board transitions are killed for one frame so the recycle
// (array shift + role reset) snaps instantly instead of animating a second time.
const recycling = ref(false);
let head = ref(0);
let sliding = false;

const trackEl = ref<HTMLElement | null>(null);
const boardEls = ref<HTMLElement[]>([]);

// ── Advance (one bar — slide + recycle) ─────────────────────────────────────
function advance() {
    if (sliding) return;
    head.value = (head.value + 1) % CHORDS.length;

    const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (reduce) {
        boards.value.forEach((b, k) => {
            b.chord = CHORDS[(head.value + (k - CENTER_IDX) + CHORDS.length * 2) % CHORDS.length];
        });
        return;
    }

    sliding = true;

    const bEls = boardEls.value;
    if (!bEls[1] || !bEls[2]) { sliding = false; return; }
    const r1 = bEls[1].getBoundingClientRect();
    const r2 = bEls[2].getBoundingClientRect();
    const dx = (r2.left + r2.width / 2) - (r1.left + r1.width / 2);

    boards.value.forEach((b, k) => {
        b.role  = ROLES[k - 1] ?? 'off';
        b.label = LBLS[k - 1] ?? '';
    });

    trackTransition.value = true;
    trackOffset.value = -dx;
}

function onTransitionEnd(e: TransitionEvent) {
    // `transitionend` bubbles, so this fires once for the track's own
    // `transform` AND once for every child board's opacity/filter/width/font
    // transition (same duration). Only the track's transform should trigger the
    // recycle — otherwise a child event runs it a second time and the just-
    // centered board re-animates ("pulled in from the left again").
    if (e.target !== trackEl.value || e.propertyName !== 'transform') return;
    if (!sliding) return;

    // Kill per-board transitions for this frame: the recycle resets roles/widths
    // back to their resting slot values, which must NOT animate (that re-shuffle
    // is the "useless second animation"). Only the next slide should animate.
    recycling.value = true;

    const first = boards.value.shift()!;
    boards.value.push(first);

    trackTransition.value = false;
    trackOffset.value = 0;

    boards.value.forEach((b, k) => {
        b.role  = ROLES[k];
        b.label = LBLS[k];
    });

    boards.value.forEach((b, k) => {
        b.chord = CHORDS[(head.value + (k - CENTER_IDX) + CHORDS.length * 2) % CHORDS.length];
    });

    // Clear any lingering strike class from the recycled DOM nodes so it does
    // not replay on whichever board now occupies a non-center slot.
    boardEls.value.forEach(el =>
        el?.querySelectorAll('.board-diagram circle.sbn-svg-dot.is-striking')
          .forEach(dot => dot.classList.remove('is-striking'))
    );

    sliding = false;

    // Re-enable transitions after the browser has painted the snapped-back
    // frame, so the next advance() animates but this recycle did not.
    requestAnimationFrame(() => requestAnimationFrame(() => { recycling.value = false; }));
}

// ── Current rhythm step for the strip ───────────────────────────────────────
const currentStep = ref(-1);

// ── Clock wiring ─────────────────────────────────────────────────────────────
const clock = useClock({
    bpm: BPM,
    pattern: PATTERN.value,
    onStep(step) {
        // Strip highlight follows every step. The two rhythm rows drive
        // different dots: a thumb hit pulses the bass note, a fingers hit
        // pulses the top three notes — the Gilberto thumb/fingers split.
        currentStep.value = step;
        const isHit = (c: string | undefined) => c != null && c.toLowerCase() === 'x';
        if (isHit(RHYTHM.thumb[step]))   strikeCenter('bass');
        if (isHit(RHYTHM.fingers[step])) strikeCenter('fingers');
    },
    onBar(barIndex) {
        // barIndex 0 is the initial bar — the first chord is already centered,
        // so don't slide on it. Advance on every subsequent bar boundary.
        if (barIndex > 0) advance();
    },
});

onMounted(() => {
    const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (!reduce) clock.start();
});

onBeforeUnmount(() => {
    clock.stop();
});

// ── Rhythm strip cell classes ────────────────────────────────────────────────
function fingerCellClass(i: number): Record<string, boolean> {
    const c = RHYTHM.fingers[i] ?? '.';
    return {
        'accent':  c === 'X',
        'ghost':   c === 'x',
        'active':  i === currentStep.value,
    };
}

function thumbCellClass(i: number): Record<string, boolean> {
    const c = RHYTHM.thumb[i] ?? '.';
    return {
        'accent': c === 'X',
        'ghost':  c === 'x',
        'active': i === currentStep.value,
    };
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
                :class="{ 'is-recycling': recycling }"
                :style="{
                    transform: `translateX(${trackOffset}px)`,
                    transition: trackTransition ? `transform ${SLIDE_MS}ms ${SLIDE_EASE}` : 'none',
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
                    <div class="board-sub">{{ board.chord.category_label }} · {{ board.chord.quality_label }}</div>

                    <div class="board-diagram">
                        <ChordDiagram :chord="board.chord" :show-guide-tones="true" />
                    </div>
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

        <!-- Rhythm strip (clock-driven, non-playable) -->
        <div class="mini-rhythm">
            <div class="mini-label">{{ RHYTHM.name }} · {{ RHYTHM.timeSignature }} · {{ BPM }} bpm</div>

            <!-- Fingers row -->
            <div class="mini-bar mini-bar-fingers">
                <div
                    v-for="(_, i) in RHYTHM.beats"
                    :key="`f${i}`"
                    class="mini-cell"
                    :class="fingerCellClass(i)"
                ></div>
            </div>

            <!-- Thumb / bass row -->
            <div class="mini-bar mini-bar-thumb">
                <div
                    v-for="(_, i) in RHYTHM.beats"
                    :key="`t${i}`"
                    class="mini-cell-thumb"
                    :class="thumbCellClass(i)"
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
    /* overflow:visible so .demo-tag (top:-12px) is not clipped */
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
        opacity v-bind("slideMs + 'ms'") v-bind("SLIDE_EASE"),
        filter   v-bind("slideMs + 'ms'") v-bind("SLIDE_EASE"),
        width    v-bind("slideMs + 'ms'") v-bind("SLIDE_EASE");
}

/* During the recycle frame, kill ALL per-board transitions so the role/width
   reset snaps instantly (no second animation). */
.board-track.is-recycling .board,
.board-track.is-recycling .board-label,
.board-track.is-recycling .board-name,
.board-track.is-recycling .board-sub,
.board-track.is-recycling .board-diagram {
    transition: none !important;
}

.board[data-role="center"] { width: 200px; }
.board[data-role="side"]   { width: 140px; }
.board[data-role="off"]    { width: 140px; }

.board-label {
    font-size: .66rem;
    text-transform: uppercase;
    letter-spacing: .1em;
    color: var(--clr-text-dim);
    opacity: .6;
    height: 1em;
    margin-bottom: 4px;
    transition: opacity v-bind("slideMs + 'ms'") v-bind("SLIDE_EASE");
}
.board[data-role="center"] .board-label { opacity: 0; }

.board-name {
    font-family: var(--font-display);
    margin-bottom: 4px;
    transition: font-size v-bind("slideMs + 'ms'") v-bind("SLIDE_EASE"), opacity v-bind("slideMs + 'ms'") v-bind("SLIDE_EASE");
}
.board[data-role="center"] .board-name { font-size: 1.9rem; font-weight: 600; opacity: 1; }
.board[data-role="side"]   .board-name { font-size: 1rem;   opacity: .55; }
.board[data-role="off"]    .board-name { font-size: 1rem;   opacity: 0; }

.board-sub {
    color: var(--clr-text-dim);
    font-size: .82rem;
    height: 1.1em;
    margin-bottom: 8px;
    transition: opacity v-bind("slideMs + 'ms'") v-bind("SLIDE_EASE");
}
.board[data-role="center"] .board-sub { opacity: 1; }
.board[data-role="side"]   .board-sub { opacity: 0; }
.board[data-role="off"]    .board-sub { opacity: 0; }

/* ── Diagram slot ── */
.board-diagram {
    /* Fixed height so the SVG doesn't reflow as board width transitions.
       The SVG is width:100% height:auto inside — clamp it to the board width
       via overflow:hidden so it never drives the container height. */
    height: 160px;
    overflow: hidden;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: opacity v-bind("slideMs + 'ms'") v-bind("SLIDE_EASE"), filter v-bind("slideMs + 'ms'") v-bind("SLIDE_EASE");
}
.board[data-role="center"] .board-diagram { opacity: 1; filter: none; }
.board[data-role="side"]   .board-diagram { opacity: .34; filter: grayscale(.45); }
.board[data-role="off"]    .board-diagram { opacity: 0;   filter: grayscale(.6); }

/* Strike pulse: `.is-striking` is toggled per-dot (bass vs. fingers) so the
   thumb and finger hits pulse different notes independently. */
.board-diagram :deep(circle.sbn-svg-dot) {
    transform-box: fill-box;
    transform-origin: center;
}
.board-diagram :deep(circle.sbn-svg-dot.is-striking) {
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

/* ── Rhythm strip ── */
.mini-rhythm {
    margin-top: 22px;
    padding-top: 20px;
    border-top: 1px solid var(--clr-border);
}
.mini-label {
    font-size: .7rem;
    color: var(--clr-text-muted);
    font-weight: 600;
    letter-spacing: .06em;
    text-transform: uppercase;
    margin-bottom: 10px;
}

/* Two-row grid — matches RhythmStrip cell layout */
.mini-bar {
    display: grid;
    grid-auto-flow: column;
    grid-auto-columns: 1fr;
    gap: 3px;
}
.mini-bar-fingers {
    margin-bottom: 3px;
}
.mini-bar-thumb {
    height: 8px;
    margin-bottom: 2px;
}

/* Fingers cells */
.mini-cell {
    height: 22px;
    border-radius: 3px;
    background: var(--clr-surface-3);
    transition: background .1s, transform .1s;
}
.mini-cell.ghost {
    background: var(--clr-accent);
    opacity: .75;
}
.mini-cell.accent {
    background: var(--clr-red);
    opacity: 1;
}
.mini-cell.active {
    outline: 1.5px solid var(--clr-accent);
    outline-offset: 1px;
    transform: translateY(-1px);
    z-index: 2;
}

/* Thumb cells */
.mini-cell-thumb {
    height: 8px;
    border-radius: 2px;
    background: var(--clr-border);
}
.mini-cell-thumb.ghost {
    background: var(--clr-text-dim);
    opacity: .5;
}
.mini-cell-thumb.accent {
    background: var(--clr-text);
    opacity: .8;
}
.mini-cell-thumb.active {
    outline: 1px solid var(--clr-accent);
    outline-offset: 1px;
}

.mini-beats {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    margin-top: 8px;
    font-size: .7rem;
    color: var(--clr-text-muted);
    letter-spacing: .12em;
}

/* ── Reduced motion ── */
@media (prefers-reduced-motion: reduce) {
    .board-track { transition: none !important; }
    .board,
    .board-name,
    .board-sub,
    .board-diagram,
    .board-label { transition: none !important; }
    .board-diagram :deep(circle.sbn-svg-dot.is-striking) { animation: none !important; }
}
</style>
