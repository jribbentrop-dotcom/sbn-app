<script setup lang="ts">
/**
 * SyncedPlayer — reusable chord-track + rhythm-strip component.
 *
 * Layout: 5 persistent board slots [off-left · prev · CENTER · next · off-right].
 * The AudioEngine singleton drives both audio playback and the visual tick.
 *
 * Usage:
 *   <SyncedPlayer :progression="chords" :rhythm-pattern="rhythm" :autoplay="true" />
 *
 * Emits:
 *   bar(barIndex)   — fires each time the clock bar advances
 *   step(stepIndex) — fires each engine tick step
 */
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import ChordDiagram from '../Library/ChordDiagram.vue';
import type { ChordDiagramData } from '../Library/ChordDiagram.vue';
import { formatChordNameHtml } from '@/composables/useChordName';
import type { RhythmPatternData } from '../Library/RhythmPattern.vue';
import { getAudioEngine } from '../../audio/engine/AudioEngine.js';
import { rhythmPatternToEvents } from '../../audio/adapters/rhythmPatternToEvents.js';

const props = defineProps<{
    progression?: ChordDiagramData[];
    rhythmPattern?: RhythmPatternData;
    barsPerChord?: number;
    /** Auto-play on mount (default true). Set false for manual control. */
    autoplay?: boolean;
}>();

const emit = defineEmits<{
    bar: [barIndex: number];
    step: [stepIndex: number];
}>();

// ── Fallback data (used when no props supplied) ───────────────────────────────
const CHORDS_FALLBACK: ChordDiagramData[] = [
    {
        id: 1001, slug: 'dm7-drop2-test', name: 'Dm7', root_note: 'D',
        quality: 'min7', quality_label: 'Minor 7th', extensions: null,
        voicing_category: 'drop2', category_label: 'Drop 2',
        root_string: 'roota', root_string_label: 'Root on A',
        inversion: 'inv3', inversion_label: '3rd Inversion',
        bass_note: null, shape_family: null, start_fret: 5,
        diagram_data: {
            positions: [
                { string: 2, fret: 6, finger: 2 }, { string: 3, fret: 7, finger: 3 },
                { string: 4, fret: 5, finger: 1 }, { string: 5, fret: 5, finger: 1 },
            ],
            barres: [{ fret: 5, from: 4, to: 5, finger: 1 }],
            muted: [1, 6], open: [],
        },
        interval_labels: 'x,b7,b3,5,R,x', popularity: null, difficulty: null,
    },
    {
        id: 1002, slug: 'g7-drop3-test', name: 'G7', root_note: 'G',
        quality: 'dom7', quality_label: 'Dominant 7th', extensions: null,
        voicing_category: 'drop3', category_label: 'Drop 3',
        root_string: 'roote', root_string_label: 'Root on E',
        inversion: 'root', inversion_label: 'Root Position',
        bass_note: null, shape_family: null, start_fret: 3,
        diagram_data: {
            positions: [
                { string: 1, fret: 3, finger: 1 }, { string: 3, fret: 3, finger: 2 },
                { string: 4, fret: 4, finger: 3 }, { string: 5, fret: 5, finger: 4 },
            ],
            barres: [], muted: [2, 6], open: [],
        },
        interval_labels: 'R,x,b7,3,5,x', popularity: null, difficulty: null,
    },
    {
        id: 1003, slug: 'cmaj7-drop2-test', name: 'Cmaj7', root_note: 'C',
        quality: 'maj7', quality_label: 'Major 7th', extensions: null,
        voicing_category: 'drop2', category_label: 'Drop 2',
        root_string: 'rootd', root_string_label: 'Root on D',
        inversion: 'inv3', inversion_label: '3rd Inversion',
        bass_note: null, shape_family: null, start_fret: 1,
        diagram_data: {
            positions: [
                { string: 3, fret: 2, finger: 2 }, { string: 4, fret: 2, finger: 3 },
                { string: 5, fret: 1, finger: 1 }, { string: 6, fret: 1, finger: 1 },
            ],
            barres: [], muted: [1], open: [],
        },
        interval_labels: 'x,x,7,3,5,R', popularity: null, difficulty: null,
    },
];

const RHYTHM_FALLBACK: RhythmPatternData = {
    name: 'Bossa Nova Clave',
    beats: 16, gridType: 'sixteenth',
    fingers: 'x..x..x...x..x..', thumb: 'x...x...x...x...',
    bpm: 127, timeSignature: '4/4',
    percTop: 'fingers', percBass: 'thumb',
};

// ── Resolved data ─────────────────────────────────────────────────────────────
const CHORDS = computed(() =>
    (props.progression && props.progression.length > 0) ? props.progression : CHORDS_FALLBACK
);
const RHYTHM = computed(() => props.rhythmPattern ?? RHYTHM_FALLBACK);
const BPM = computed(() => Math.round(RHYTHM.value.bpm / 2));
const BARS_PER_CHORD = computed(() => Math.max(1, props.barsPerChord ?? 1));

// ── Slide constants ───────────────────────────────────────────────────────────
const SLIDE_MS = 420;
const slideMs = ref(SLIDE_MS);
const SLIDE_EASE = 'cubic-bezier(.4,0,.2,1)';
const CENTER_IDX = 2;
const ROLES = ['off', 'side', 'center', 'next', 'off'] as const;
const LBLS  = ['', 'prev', '', 'up next', ''];

// ── Audio engine ──────────────────────────────────────────────────────────────
const engine = getAudioEngine();
const isPlaying = ref(false);
let engineUnsubs: Array<() => void> = [];
let engineListened = false;

function beatToStep(beat: number): number {
    const stepBeats = RHYTHM.value.gridType === 'eighth' ? 0.5
                    : RHYTHM.value.gridType === 'triplet' ? 1 / 3
                    : 0.25;
    return Math.floor(beat / stepBeats) % RHYTHM.value.beats;
}

function registerEngineListeners(): void {
    if (engineListened) return;
    engineListened = true;
    engineUnsubs.push(
        engine.on('tick', (beat: number) => {
            if (!isPlaying.value) return;
            const step = beatToStep(beat);
            currentStep.value = step;
            emit('step', step);
            const isHit = (c: string | undefined) => c != null && c.toLowerCase() === 'x';
            if (isHit(RHYTHM.value.thumb[step]))   strikeCenter('bass');
            if (isHit(RHYTHM.value.fingers[step])) strikeCenter('fingers');
            if (step === RHYTHM.value.beats - 4) strikeNext();
            if (step === RHYTHM.value.beats - 1) tickBar();
        }),
        engine.on('playStarted', () => {
            if (isPlaying.value) {
                isPlaying.value = false;
                currentStep.value = -1;
                barPosition.value = 0;
            }
        }),
    );
}

async function audioPlay(): Promise<void> {
    await engine.init({
        bpm: BPM.value * 2,
        samplesBaseUrl: '/audio/rhythm-samples/',
    });
    registerEngineListeners();

    const events = rhythmPatternToEvents(RHYTHM.value, { startBeat: 0 });
    const stepBeats = RHYTHM.value.gridType === 'eighth' ? 0.5
                    : RHYTHM.value.gridType === 'triplet' ? 1 / 3
                    : 0.25;
    const loopBeats = RHYTHM.value.beats * stepBeats;
    engine.load(events, { loop: true, loopBeats });
    engine.setTempo(BPM.value * 2);

    barPosition.value = 0;
    currentStep.value = -1;
    await engine.play();
    isPlaying.value = true;
}

function audioStop(): void {
    engine.stop();
    isPlaying.value = false;
    currentStep.value = -1;
    barPosition.value = 0;
}

function togglePlay(): void {
    if (isPlaying.value) audioStop();
    else audioPlay();
}

// ── Board state ───────────────────────────────────────────────────────────────
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

// ── Bass/fingers split (string 1 = low E) ────────────────────────────────────
function bassString(chord: ChordDiagramData): number {
    const strings = (chord.diagram_data.positions ?? [])
        .map(p => p.string)
        .concat(chord.diagram_data.open ?? []);
    return strings.length ? Math.min(...strings) : 1;
}

function strikeCenter(row: 'bass' | 'fingers') {
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
    target.forEach(dot => dot.classList.remove('is-striking'));
    void diagram.offsetWidth;
    target.forEach(dot => dot.classList.add('is-striking'));
}

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


// ── Track / recycle ───────────────────────────────────────────────────────────
const TRACK_BASE = -160;
const trackOffset = ref(TRACK_BASE);
const trackTransition = ref(false);
const recycling = ref(false);
const head = ref(0);
let sliding = false;
const barPosition = ref(0);
const currentStep = ref(-1);
const totalBars = ref(0);

function tickBar(): void {
    totalBars.value++;
    emit('bar', totalBars.value);
    barPosition.value++;
    if (barPosition.value >= BARS_PER_CHORD.value) {
        barPosition.value = 0;
        advance();
    }
}

const trackEl = ref<HTMLElement | null>(null);
const boardEls = ref<HTMLElement[]>([]);

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
    boards.value.forEach((b, k) => {
        b.role  = ROLES[k - 1] ?? 'off';
        b.label = LBLS[k - 1] ?? '';
    });
    trackTransition.value = true;
    trackOffset.value = TRACK_BASE - 160;
}

function onTransitionEnd(e: TransitionEvent) {
    if (e.target !== trackEl.value || e.propertyName !== 'transform') return;
    if (!sliding) return;

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

    boardEls.value.forEach(el =>
        el?.querySelectorAll('.board-diagram circle.sbn-svg-dot').forEach(dot => {
            dot.classList.remove('is-striking');
            dot.classList.remove('is-next-cue');
        })
    );

    sliding = false;

    requestAnimationFrame(() => requestAnimationFrame(() => {
        recycling.value = false;
        const upcoming = boards.value[4];
        upcoming.chord = CHORDS.value[(head.value + 2 + CHORDS.value.length * 2) % CHORDS.value.length];
        upcoming.role  = 'next';
        upcoming.label = 'up next';
    }));
}

// ── Strip cell classes ────────────────────────────────────────────────────────
function fingerCellClass(i: number): Record<string, boolean> {
    const c = RHYTHM.value.fingers[i] ?? '.';
    return { 'accent': c === 'X', 'ghost': c === 'x', 'active': isPlaying.value && i === currentStep.value };
}
function thumbCellClass(i: number): Record<string, boolean> {
    const c = RHYTHM.value.thumb[i] ?? '.';
    return { 'accent': c === 'X', 'ghost': c === 'x', 'active': isPlaying.value && i === currentStep.value };
}

// ── Lifecycle ─────────────────────────────────────────────────────────────────
onMounted(() => {
    const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (!reduce && props.autoplay !== false) audioPlay();
});

onBeforeUnmount(() => {
    engineUnsubs.forEach(fn => fn?.());
    engineUnsubs = [];
    engineListened = false;
    if (isPlaying.value) engine.stop();
});

defineExpose({ play: audioPlay, stop: audioStop, toggle: togglePlay, isPlaying });
</script>

<template>
    <div class="synced-player">
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

        <!-- Guide-tone legend -->
        <div class="sp-legend">
            <span><i class="legend-dot" style="background:var(--clr-root)"></i>Root</span>
            <span><i class="legend-dot" style="background:var(--clr-third)"></i>3rd</span>
            <span><i class="legend-dot" style="background:var(--clr-fifth)"></i>5th</span>
            <span><i class="legend-dot" style="background:var(--clr-seventh)"></i>7th</span>
        </div>

        <!-- Rhythm strip -->
        <div class="mini-rhythm">
            <div class="mini-label-row">
                <button class="sp-play-btn" @click="togglePlay" :aria-label="isPlaying ? 'Stop' : 'Play'">
                    <svg v-if="!isPlaying" viewBox="0 0 20 20" fill="currentColor" width="16" height="16"><path d="M6.3 3.8L16.4 10 6.3 16.2V3.8z"/></svg>
                    <svg v-else viewBox="0 0 20 20" fill="currentColor" width="16" height="16"><rect x="5" y="4" width="4" height="12" rx="1"/><rect x="11" y="4" width="4" height="12" rx="1"/></svg>
                </button>
                <span class="mini-label">{{ RHYTHM.name }} · {{ RHYTHM.timeSignature }} · {{ BPM }} bpm</span>
            </div>

            <div class="mini-bar mini-bar-fingers">
                <div v-for="(_, i) in RHYTHM.beats" :key="`f${i}`" class="mini-cell" :class="fingerCellClass(i)"></div>
            </div>
            <div class="mini-bar mini-bar-thumb">
                <div v-for="(_, i) in RHYTHM.beats" :key="`t${i}`" class="mini-cell-thumb" :class="thumbCellClass(i)"></div>
            </div>
            <div class="mini-beats">
                <span>1</span><span>2</span><span>3</span><span>4</span>
            </div>
        </div>
    </div>
</template>

<style scoped>
.synced-player {
    padding: 20px;
}

/* ── Sliding track ── */
.board-viewport {
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
        filter  v-bind("slideMs + 'ms'") v-bind("SLIDE_EASE");
}

.board-track.is-recycling .board,
.board-track.is-recycling .board-label,
.board-track.is-recycling .board-name,
.board-track.is-recycling .board-diagram {
    transition: none !important;
}

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
.board[data-role="next"]   .board-label { opacity: 1; color: var(--clr-accent); }

.board-name {
    font-family: var(--font-display);
    font-size: 1.9rem;
    font-weight: 600;
    margin-bottom: 4px;
    transform-origin: center top;
    transition:
        transform v-bind("slideMs + 'ms'") v-bind("SLIDE_EASE"),
        opacity   v-bind("slideMs + 'ms'") v-bind("SLIDE_EASE");
}
.board[data-role="center"] .board-name { transform: scale(1);    opacity: 1; }
.board[data-role="side"]   .board-name { transform: scale(.526); opacity: .55; }
.board[data-role="next"]   .board-name { transform: scale(.526); opacity: .8; }
.board[data-role="off"]    .board-name { transform: scale(.526); opacity: 0; }

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
.board[data-role="center"] .board-diagram { opacity: 1;   filter: none;           transform: scale(1); }
.board[data-role="side"]   .board-diagram { opacity: .34; filter: grayscale(.45); transform: scale(.78); }
.board[data-role="next"]   .board-diagram { opacity: .52; filter: grayscale(.2);  transform: scale(.78); }
.board[data-role="off"]    .board-diagram { opacity: 0;   filter: grayscale(.6);  transform: scale(.78); }

.board-diagram :deep(circle.sbn-svg-dot) {
    transform-box: fill-box;
    transform-origin: center;
}
.board-diagram :deep(circle.sbn-svg-dot.is-striking) {
    animation: sp-strike .42s cubic-bezier(.2,1.6,.4,1);
}
@keyframes sp-strike {
    0%   { transform: scale(1); }
    40%  { transform: scale(1.42); }
    100% { transform: scale(1); }
}
.board-diagram :deep(circle.sbn-svg-dot.is-next-cue) {
    animation: sp-next-cue .55s cubic-bezier(.2,1.4,.4,1);
}
@keyframes sp-next-cue {
    0%   { transform: scale(1);    opacity: 1; }
    35%  { transform: scale(1.22); opacity: .9; }
    100% { transform: scale(1);    opacity: 1; }
}

/* ── Legend ── */
.sp-legend {
    display: flex;
    justify-content: center;
    gap: 16px;
    margin-top: 18px;
    flex-wrap: wrap;
}
.sp-legend span {
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
.mini-label-row {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 10px;
}
.mini-label {
    font-size: .7rem;
    color: var(--clr-text-muted);
    font-weight: 600;
    letter-spacing: .06em;
    text-transform: uppercase;
}
.sp-play-btn {
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 28px; height: 28px;
    border-radius: 50%;
    background: var(--clr-accent);
    color: #fff;
    border: none;
    cursor: pointer;
    transition: background .15s, transform .1s;
}
.sp-play-btn:hover  { filter: brightness(1.12); }
.sp-play-btn:active { transform: scale(.93); }

.mini-bar {
    display: grid;
    grid-auto-flow: column;
    grid-auto-columns: 1fr;
    gap: 3px;
}
.mini-bar-fingers { margin-bottom: 3px; }
.mini-bar-thumb   { height: 8px; margin-bottom: 2px; }

.mini-cell {
    height: 22px;
    border-radius: 3px;
    background: var(--clr-surface-3);
    transition: background .1s, transform .1s;
}
.mini-cell.ghost  { background: var(--clr-accent); opacity: .75; }
.mini-cell.accent { background: var(--clr-red); opacity: 1; }
.mini-cell.active { outline: 1.5px solid var(--clr-accent); outline-offset: 1px; transform: translateY(-1px); z-index: 2; }

.mini-cell-thumb         { height: 8px; border-radius: 2px; background: var(--clr-border); }
.mini-cell-thumb.ghost   { background: var(--clr-text-dim); opacity: .5; }
.mini-cell-thumb.accent  { background: var(--clr-text); opacity: .8; }
.mini-cell-thumb.active  { outline: 1px solid var(--clr-accent); outline-offset: 1px; }

.mini-beats {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    margin-top: 8px;
    font-size: .7rem;
    color: var(--clr-text-muted);
    letter-spacing: .12em;
}

/* ── Mobile: scale the whole player down to fit narrow containers ── */
/* The inner content is ~520px wide (480px track + 20px side padding each).
   On screens narrower than that we scale the whole block down proportionally.
   transform-origin center keeps it centered; margin auto keeps the card tight. */
@media (max-width: 519px) {
    .synced-player {
        transform-origin: top center;
        transform: scale(calc(100vw / 520));
        /* Collapse the extra height that scale() leaves behind */
        margin-bottom: calc((100vw / 520 - 1) * 100%);
    }
}

@media (prefers-reduced-motion: reduce) {
    .board-track { transition: none !important; }
    .board, .board-name, .board-diagram, .board-label { transition: none !important; }
    .board-diagram :deep(circle.sbn-svg-dot.is-striking),
    .board-diagram :deep(circle.sbn-svg-dot.is-next-cue) { animation: none !important; }
}
</style>
