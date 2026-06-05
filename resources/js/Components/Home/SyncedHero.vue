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
import { formatChordNameHtml } from '@/composables/useChordName';
import type { RhythmPatternData } from '../Library/RhythmPattern.vue';

const props = defineProps<{
    progression?: ChordDiagramData[];
    rhythmPattern?: RhythmPatternData;
}>();

// diagram_data uses string numbers 1=low E … 6=high E (SBN/DB convention,
// per ChordShapeCalculator::TUNING). The renderer draws string 1 leftmost.
const CHORDS_FALLBACK: ChordDiagramData[] = [
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

// ── Rhythm: from prop or hardcoded fallback ───────────────────────────────────
const RHYTHM_FALLBACK: RhythmPatternData = {
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
const RHYTHM = computed(() => props.rhythmPattern ?? RHYTHM_FALLBACK);

// ── Chord progression: from prop or hardcoded fallback ────────────────────────
const CHORDS = computed(() =>
    (props.progression && props.progression.length > 0) ? props.progression : CHORDS_FALLBACK
);

const BPM = computed(() => Math.round(RHYTHM.value.bpm / 2));
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
const ROLES = ['off', 'side', 'center', 'next', 'off'] as const;
const LBLS  = ['', 'prev', '', 'up next', ''];

// ── Pattern → StepType array (derived from fingers string) ──────────────────
// Real DB patterns use only lowercase x (hit) — uppercase X would be accent.
// Map x→ghost so the clock's onStep can detect hits for the strike pulse.
const PATTERN = computed((): StepType[] =>
    RHYTHM.value.fingers.split('').map((c): StepType =>
        c === 'X' ? 'accent' : c === 'x' ? 'ghost' : 'rest'
    )
);

// ── Reactive board state ─────────────────────────────────────────────────────
interface BoardState {
    chord: ChordDiagramData;
    role: 'off' | 'side' | 'center' | 'next';
    label: string;
}

const boards = ref<BoardState[]>(
    ROLES.map((role, k) => ({
        chord: CHORDS.value[(k - CENTER_IDX + CHORDS.value.length * 2) % CHORDS.value.length],
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

// Pulse only the root dot on the upcoming 'next' board — a soft "get ready" cue
// fired ~1 beat before the bar flips. Targets the lowest-numbered played string
// (which is the root in root-position voicings and close enough otherwise).
function strikeNext() {
    const idx = boards.value.findIndex(b => b.role === 'next');
    if (idx < 0) return;
    const el = boardEls.value[idx];
    if (!el) return;
    const diagram = el.querySelector<HTMLElement>('.board-diagram');
    if (!diagram) return;

    const bass = bassString(boards.value[idx].chord);
    const dots = Array.from(diagram.querySelectorAll<SVGCircleElement>('circle.sbn-svg-dot'));
    const target = dots.filter(dot => Number(dot.getAttribute('data-string')) === bass);

    target.forEach(dot => dot.classList.remove('is-next-cue'));
    void diagram.offsetWidth;
    target.forEach(dot => dot.classList.add('is-next-cue'));
}

// -160 keeps index 2 (center board) visually centered in the 480px viewport.
const TRACK_BASE = -160;
const trackOffset = ref(TRACK_BASE);
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
    head.value = (head.value + 1) % CHORDS.value.length;

    const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (reduce) {
        boards.value.forEach((b, k) => {
            b.chord = CHORDS.value[(head.value + (k - CENTER_IDX) + CHORDS.value.length * 2) % CHORDS.value.length];
        });
        return;
    }

    sliding = true;

    // All boards are fixed 160px — slot pitch is always one board-width.
    const dx = 160;

    boards.value.forEach((b, k) => {
        b.role  = ROLES[k - 1] ?? 'off';
        b.label = LBLS[k - 1] ?? '';
    });

    trackTransition.value = true;
    trackOffset.value = TRACK_BASE - dx;
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
    trackOffset.value = TRACK_BASE;

    boards.value.forEach((b, k) => {
        b.role  = ROLES[k];
        b.label = LBLS[k];
    });

    boards.value.forEach((b, k) => {
        b.chord = CHORDS.value[(head.value + (k - CENTER_IDX) + CHORDS.value.length * 2) % CHORDS.value.length];
    });

    // Clear any lingering strike/cue classes from recycled DOM nodes.
    boardEls.value.forEach(el =>
        el?.querySelectorAll('.board-diagram circle.sbn-svg-dot')
          .forEach(dot => {
              dot.classList.remove('is-striking');
              dot.classList.remove('is-next-cue');
          })
    );

    sliding = false;

    // Re-enable transitions after the browser has painted the snapped-back
    // frame, so the next advance() animates but this recycle did not.
    // Then immediately promote the off-screen-right board (index 4) to 'next'
    // so it fades in from the first step of the bar — giving the student the
    // full bar to read the upcoming chord before it advances.
    requestAnimationFrame(() => requestAnimationFrame(() => {
        recycling.value = false;
        const upcoming = boards.value[4];
        upcoming.chord = CHORDS.value[(head.value + 2 + CHORDS.value.length * 2) % CHORDS.value.length];
        upcoming.role  = 'next';
        upcoming.label = 'up next';
    }));
}

// ── Current rhythm step for the strip ───────────────────────────────────────
const currentStep = ref(-1);

// ── Clock wiring ─────────────────────────────────────────────────────────────
const clock = useClock({
    bpm: BPM.value,
    pattern: PATTERN.value,
    onStep(step) {
        currentStep.value = step;
        const isHit = (c: string | undefined) => c != null && c.toLowerCase() === 'x';
        if (isHit(RHYTHM.value.thumb[step]))   strikeCenter('bass');
        if (isHit(RHYTHM.value.fingers[step])) strikeCenter('fingers');
        if (step === RHYTHM.value.beats - 1) advance();
        // Pre-pulse: a beat before the slide (step 12), nudge the next chord.
        if (step === 12) strikeNext();
    },
    onBar(_barIndex) {},
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
    const c = RHYTHM.value.fingers[i] ?? '.';
    return {
        'accent':  c === 'X',
        'ghost':   c === 'x',
        'active':  i === currentStep.value,
    };
}

function thumbCellClass(i: number): Record<string, boolean> {
    const c = RHYTHM.value.thumb[i] ?? '.';
    return {
        'accent': c === 'X',
        'ghost':  c === 'x',
        'active': i === currentStep.value,
    };
}
</script>

<template>
    <div class="synced-hero sbn-synced-hero-card">
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
                    <div class="board-name" v-html="formatChordNameHtml(board.chord)"></div>

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
    padding: 28px 20px;
    /* overflow:visible so .demo-tag (top:-12px) is not clipped */
    /* Card frame (border, shadow, radius) lives in sbn-synced-hero-card
       in sbn-design-system.css so [data-theme] overrides can reach it. */
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
    /* Show exactly prev + center + next (3 × 160px).
       The track's initial translateX(-160px) hides off-left and off-right. */
    width: 480px;
    overflow: hidden;
    margin: 0 auto;
}
.board-track {
    display: flex;
    align-items: center;
    will-change: transform;
}

.board {
    flex: 0 0 auto;
    width: 160px;
    text-align: center;
    transition:
        opacity v-bind("slideMs + 'ms'") v-bind("SLIDE_EASE"),
        filter   v-bind("slideMs + 'ms'") v-bind("SLIDE_EASE");
}

/* During the recycle frame, kill ALL per-board transitions so the role reset
   snaps instantly (no second animation). */
.board-track.is-recycling .board,
.board-track.is-recycling .board-label,
.board-track.is-recycling .board-name,
.board-track.is-recycling .board-diagram {
    transition: none !important;
}

/* All boards fixed-width — no layout thrash during slide.
   The diagram scales visually via transform on .board-diagram. */

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
/* "up next" label: accent colour, fully visible */
.board[data-role="next"]   .board-label { opacity: 1; color: var(--clr-accent); }

.board-name {
    font-family: var(--font-display);
    font-size: 1.9rem;
    font-weight: 600;
    margin-bottom: 4px;
    transform-origin: center top;
    transition: transform v-bind("slideMs + 'ms'") v-bind("SLIDE_EASE"), opacity v-bind("slideMs + 'ms'") v-bind("SLIDE_EASE");
}
.board[data-role="center"] .board-name { transform: scale(1);         opacity: 1; }
.board[data-role="side"]   .board-name { transform: scale(.526);      opacity: .55; }
.board[data-role="next"]   .board-name { transform: scale(.526);      opacity: .8; }
.board[data-role="off"]    .board-name { transform: scale(.526);      opacity: 0; }

/* Center chord name: dark text for legibility at large size.
   Side/next keep the global --clr-accent-dim orange as-is. */
.board[data-role="center"] .board-name :deep(.sbn-chord-symbol) {
    color: var(--clr-text);
}


/* ── Diagram slot ── */
.board-diagram {
    height: 160px;
    overflow: hidden;
    display: flex;
    align-items: center;
    justify-content: center;
    transform-origin: center top;
    transition:
        opacity   v-bind("slideMs + 'ms'") v-bind("SLIDE_EASE"),
        filter    v-bind("slideMs + 'ms'") v-bind("SLIDE_EASE"),
        transform v-bind("slideMs + 'ms'") v-bind("SLIDE_EASE");
}
.board[data-role="center"] .board-diagram { opacity: 1;   filter: none;              transform: scale(1); }
.board[data-role="side"]   .board-diagram { opacity: .34; filter: grayscale(.45);    transform: scale(.78); }
.board[data-role="next"]   .board-diagram { opacity: .52; filter: grayscale(.2);     transform: scale(.78); }
.board[data-role="off"]    .board-diagram { opacity: 0;   filter: grayscale(.6);     transform: scale(.78); }

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

/* Soft pre-pulse on the 'next' board root dot — subtle scale + fade */
.board-diagram :deep(circle.sbn-svg-dot.is-next-cue) {
    animation: hero-next-cue .55s cubic-bezier(.2,1.4,.4,1);
}
@keyframes hero-next-cue {
    0%   { transform: scale(1);    opacity: 1; }
    35%  { transform: scale(1.22); opacity: .9; }
    100% { transform: scale(1);    opacity: 1; }
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
    border-top: 1px solid var(--clr-border); /* --clr-border is the correct DS token */
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
    .board-diagram :deep(circle.sbn-svg-dot.is-striking),
    .board-diagram :deep(circle.sbn-svg-dot.is-next-cue) { animation: none !important; }
}
</style>
