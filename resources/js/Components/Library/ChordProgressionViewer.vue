<script setup lang="ts">
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import type { ChordDiagramData } from './ChordDiagram.vue';
import ChordDiagram from './ChordDiagram.vue';
import { getAudioEngine } from '../../audio/engine/AudioEngine.js';
import { chordDiagramToEvents } from '../../audio/adapters/chordDiagramToEvents.js';
import { buildPitchMap, findResolutionPairs, arrowColor } from './guideToneResolution.js';

const QUALITY_MAP: Record<string, [string, string]> = {
    'maj':   ['major', ''],
    'min':   ['minor', ''],
    'aug':   ['aug',   ''],
    'dim':   ['°',     ''],
    '5':     ['',      '5'],
    'sus4':  ['sus',   '4'],
    'sus2':  ['sus',   '2'],
    'add9':  ['',      'add9'],
    'maj7':  ['maj',   '7'],
    'm7':    ['m',     '7'],
    'dom7':  ['',      '7'],
    'm7b5':  ['m',     '7♭5'],
    'o7':    ['°',     '7'],
    'maj6':  ['maj',   '6'],
    'm6':    ['m',     '6'],
    'mMaj7': ['m',     'maj7'],
    'aug7':  ['aug',   '7'],
    '7sus4': ['',      '7sus4'],
};

function chordDisplayHtml(chord: ProgressionChord): string {
    const d = chord.diagramData;
    if (!d) {
        // Fallback: plain name, no structured data
        return `<span class="sbn-chord-symbol"><span class="sbn-chord-root">${chord.chordName}</span></span>`;
    }

    const root = (d.root_note ?? '').replace(/#/g, '♯').replace(/b/g, '♭');
    const [qual, core] = QUALITY_MAP[d.quality] ?? ['', d.quality ?? ''];
    const ext = (d.extensions ?? '').replace(/#/g, '♯').replace(/b(?=[0-9])/g, '♭');
    const bass = d.bass_note ? '/' + d.bass_note.replace(/#/g, '♯').replace(/b/g, '♭') : '';
    const inv = (!bass && d.inversion_label && d.inversion_label !== 'Root position')
        ? `<span class="chord-inv">${d.inversion_label}</span>`
        : '';

    let html = '<span class="sbn-chord-symbol">';
    if (root) html += `<span class="sbn-chord-root">${root}</span>`;
    if (qual) html += `<span class="sbn-chord-quality">${qual}</span>`;
    if (core) html += `<span class="sbn-chord-ext">${core}</span>`;
    if (ext)  html += `<span class="sbn-chord-ext sbn-chord-ext--extra">(${ext})</span>`;
    if (bass) html += `<span class="sbn-chord-bass">${bass}</span>`;
    html += '</span>';
    return html + inv;
}

export interface ProgressionChord {
    chordName: string;
    diagramData: ChordDiagramData | null;
    beats?: number;
    slug?: string | null;
    numeral?: string;
    functionalRole?: string | null;
}

export interface ChordProgressionViewerProps {
    chords: ProgressionChord[];
    interactive?: boolean;
    compact?: boolean;
    /** @deprecated Arrows are removed in the Living Fretboard redesign; kept for prop compatibility. */
    showFlowArrows?: boolean;
    color?: string | null;
    vintageCard?: boolean;
    name?: string;
    category?: string;
    numerals?: string;
    keyLabel?: string;
    /** Start with this chord index selected (defaults to 0). */
    initialIndex?: number;
    /**
     * Video-sync playhead, in seconds of the embedded recording. When non-null,
     * the active chord is driven by `chordIndexAtTime` instead of audio playback.
     * The shared sync layer (useVideoSync) reports YouTube's getCurrentTime()
     * verbatim — seconds is the transport unit; beats are derived here.
     */
    videoPlayhead?: number | null;
    /** Recording-time (seconds) at which the snippet's first bar begins. */
    videoStartSec?: number;
    /** Snippet tempo, used to convert recording-seconds to chord beats. */
    tempoBpm?: number;
    /**
     * Video transport callbacks. When provided (course player, video-synced
     * <sbn-progression>), the play button drives the shared video clock
     * instead of synth-audio playback — the video IS the audio. The chord
     * highlight then follows `videoPlayhead` via chordIndexAtTime.
     */
    onVideoPlay?: (() => void) | null;
    onVideoPause?: (() => void) | null;
}

const props = withDefaults(defineProps<ChordProgressionViewerProps>(), {
    interactive: true,
    compact: false,
    showFlowArrows: true,
    color: null,
    vintageCard: false,
    name: '',
    category: '',
    numerals: '',
    keyLabel: '',
    initialIndex: 0,
    videoPlayhead: null,
    videoStartSec: 0,
    tempoBpm: 120,
    onVideoPlay: null,
    onVideoPause: null,
});

/** True when this viewer is wired to a video clock (course player snippet). */
const isVideoSynced = computed(() => !!props.onVideoPlay);

const isPlayingAll = ref(false);
const currentPlayingIndex = ref<number | null>(null);

const engine = getAudioEngine();

const unsubEnded = engine.on('ended', () => {
    if (isPlayingAll.value && currentPlayingIndex.value !== null) {
        playNextChord();
    } else {
        stopPlayback();
    }
});

onBeforeUnmount(() => {
    unsubEnded();
    window.removeEventListener('keydown', onKey);
    ro?.disconnect();
});

// ---------- Fretboard geometry ----------
const VB_W = 800;
const VB_H = 130;
const PAD_L = 20;
const PAD_R = 10;
const PAD_T = 14;
const PAD_B = 20;
const FRET_FROM = 1;
const FRET_TO = 15;
const FRET_WINDOW = 7; // always show exactly this many frets
const stringH = (VB_H - PAD_T - PAD_B) / 5;

// Real fretboard spacing: each fret's width = previous × 2^(-1/12).
// fretEdgeX[i] is the x position of the left edge of fret (FRET_FROM + i).
// fretEdgeX[FRET_COUNT] is the right edge of the last fret.
// Real fret widths: width of fret f ∝ 2^(-f/12), scaled to fit USABLE_W.
const USABLE_W = VB_W - PAD_L - PAD_R;
const fretEdgeX: number[] = (() => {
    const rawWidths: number[] = [];
    for (let f = FRET_FROM; f <= FRET_TO; f++) rawWidths.push(Math.pow(2, -f / 12));
    const scale = USABLE_W / rawWidths.reduce((a, b) => a + b, 0);
    const edges = [PAD_L];
    for (const w of rawWidths) edges.push(edges[edges.length - 1] + w * scale);
    return edges;
})();

function fretCenterX(f: number) { return (fretEdgeX[f - FRET_FROM] + fretEdgeX[f - FRET_FROM + 1]) / 2; }
// SBN convention: string 1 = Low E, 6 = High E. Render Low E at BOTTOM.
function stringY(s: number) { return PAD_T + (6 - s) * stringH; }

const fretLines = computed(() => {
    const [lo, hi] = excerptWindow.value;
    const out: Array<{ x: number; isNut: boolean }> = [];
    if (lo === FRET_FROM) out.push({ x: PAD_L, isNut: true });
    for (let f = lo; f <= hi; f++) {
        out.push({ x: fretEdgeX[f - FRET_FROM + 1], isNut: false });
    }
    return out;
});

const stringLines = computed(() => {
    const [lo] = excerptWindow.value;
    const x1 = fretEdgeX[lo - FRET_FROM];
    const x2 = x1 + EXCERPT_VW;
    const out: Array<{ y: number; s: number; x1: number; x2: number }> = [];
    for (let s = 1; s <= 6; s++) out.push({ y: stringY(s), s, x1, x2 });
    return out;
});

const singleInlays = computed(() => {
    const [lo, hi] = excerptWindow.value;
    return [3, 5, 7, 9, 15].filter(f => f >= lo && f <= hi).map(f => ({
        cx: fretCenterX(f), cy: PAD_T + stringH * 2.5,
    }));
});
const doubleInlays = computed(() => {
    const [lo, hi] = excerptWindow.value;
    return [12].filter(f => f >= lo && f <= hi).flatMap(f => [
        { cx: fretCenterX(f), cy: PAD_T + stringH * 1.5 },
        { cx: fretCenterX(f), cy: PAD_T + stringH * 3.5 },
    ]);
});
const fretNumbers = computed(() => {
    const [lo, hi] = excerptWindow.value;
    const out: Array<{ x: number; y: number; n: number }> = [];
    for (let f = lo; f <= hi; f++) {
        out.push({ x: fretCenterX(f), y: VB_H - PAD_B + 14, n: f });
    }
    return out;
});

// ---------- Video-sync time map ----------
// Cumulative beat spans per chord, built from each chord's `beats` (default 0.5).
// This is the time→index backbone the shared playhead resolves against.
interface ChordSpan { startBeat: number; endBeat: number; }
const chordTimeline = computed<ChordSpan[]>(() => {
    let cursor = 0;
    return props.chords.map(c => {
        const startBeat = cursor;
        cursor += c.beats || 0.5;
        return { startBeat, endBeat: cursor };
    });
});

/** Total beat length of the progression. */
const totalBeats = computed<number>(() =>
    chordTimeline.value.length ? chordTimeline.value[chordTimeline.value.length - 1].endBeat : 0
);

/**
 * Resolve a recording-time (seconds) to a chord index. Converts seconds to
 * progression-beats once, at this consumer's edge, against the authored
 * `videoStartSec` + `tempoBpm` anchor — no global tempo guessing.
 */
function chordIndexAtTime(sec: number): number {
    const beat = (sec - props.videoStartSec) * (props.tempoBpm / 60);
    if (beat <= 0) return 0;
    const tl = chordTimeline.value;
    if (!tl.length) return 0;
    if (beat >= tl[tl.length - 1].endBeat) return tl.length - 1;
    return tl.findIndex(span => beat < span.endBeat);
}

// ---------- Active chord & dot positions ----------
const activeIndex = computed<number>(() => {
    // Video clock, when present, wins over audio playback / manual selection.
    if (props.videoPlayhead !== null) return chordIndexAtTime(props.videoPlayhead);
    if (currentPlayingIndex.value !== null) return currentPlayingIndex.value;
    return selectedIndex.value;
});

const selectedIndex = ref(props.initialIndex);

const activeChordDiagramData = computed<ChordDiagramData | null>(() =>
    props.chords[activeIndex.value]?.diagramData ?? null
);

interface Position { string: number; fret: number; finger: string; }

function normalizeFinger(f: number | string | undefined): string {
    if (f === undefined || f === null) return '';
    const s = String(f).trim();
    if (!s || s === '0') return '';
    return s;
}

function positionsForChord(chord: ProgressionChord | undefined): Position[] {
    if (!chord?.diagramData?.diagram_data) return [];
    const dd = chord.diagramData.diagram_data;
    const pos = dd.positions ?? [];
    // Fold barre fingering onto each barred string position if not already set.
    const barreFinger = new Map<number, string>();
    for (const b of dd.barres ?? []) {
        const f = normalizeFinger(b.finger);
        if (!f) continue;
        const from = Math.min(b.from, b.to), to = Math.max(b.from, b.to);
        for (let s = from; s <= to; s++) barreFinger.set(s, f);
    }
    return pos
        .filter(p => p.fret >= FRET_FROM && p.fret <= FRET_TO && p.string >= 1 && p.string <= 6)
        .map(p => ({
            string: p.string,
            fret: p.fret,
            finger: normalizeFinger(p.finger) || barreFinger.get(p.string) || '',
        }));
}

const activePositions = computed<Position[]>(() => positionsForChord(props.chords[activeIndex.value]));

// ---------- Fretboard excerpt (cropped viewBox) ----------
// excerptWindow: [loFret, hiFret] inclusive — always exactly FRET_WINDOW frets wide,
// centered on the active chord's positions.
const excerptWindow = computed<[number, number]>(() => {
    const frets = activePositions.value
        .map(p => p.fret)
        .filter(f => f >= FRET_FROM && f <= FRET_TO);
    const mid = frets.length
        ? Math.round((Math.min(...frets) + Math.max(...frets)) / 2)
        : Math.round(FRET_FROM + FRET_WINDOW / 2);
    let lo = mid - Math.floor(FRET_WINDOW / 2);
    let hi = lo + FRET_WINDOW - 1;
    if (lo < FRET_FROM) { lo = FRET_FROM; hi = lo + FRET_WINDOW - 1; }
    if (hi > FRET_TO)   { hi = FRET_TO;   lo = hi - FRET_WINDOW + 1; }
    return [lo, hi];
});

// Fixed virtual width — never changes, only x-origin shifts.
const EXCERPT_VW = fretEdgeX[FRET_WINDOW] - fretEdgeX[0];

function chordMidSvgX(positions: Position[]): number {
    const frets = positions.map(p => p.fret).filter(f => f >= FRET_FROM && f <= FRET_TO);
    if (!frets.length) return fretCenterX(Math.round(FRET_FROM + FRET_WINDOW / 2));
    const lo = Math.min(...frets), hi = Math.max(...frets);
    return (fretCenterX(lo) + fretCenterX(hi)) / 2;
}

// Smoothly animated x-origin (CSS can't transition viewBox, so we lerp in rAF).
// Initialise already centered on chord 0 so there's no slide-in on mount.
const smoothX = ref(chordMidSvgX(activePositions.value) - EXCERPT_VW / 2);
let rafId: number | null = null;

function animateX(target: number) {
    if (rafId !== null) cancelAnimationFrame(rafId);
    const SPEED = 0.035;
    function step() {
        const delta = target - smoothX.value;
        if (Math.abs(delta) < 0.1) { smoothX.value = target; return; }
        smoothX.value += delta * SPEED;
        rafId = requestAnimationFrame(step);
    }
    rafId = requestAnimationFrame(step);
}

watch(activePositions, (positions) => {
    const mid = chordMidSvgX(positions);
    // clamp so we never show beyond the full fretboard
    const minX = fretEdgeX[0];
    const maxX = fretEdgeX[FRET_TO - FRET_FROM + 1] - EXCERPT_VW;
    animateX(Math.max(minX, Math.min(maxX, mid - EXCERPT_VW / 2)));
});
onBeforeUnmount(() => { if (rafId !== null) cancelAnimationFrame(rafId); });

const excerptViewBox = computed(() => `${smoothX.value} 0 ${EXCERPT_VW} ${VB_H}`);

// 6 dots, keyed by string number (1..6) for stable morphing.
// lastCx remembers the last visible cx per string so invisible dots stay in place
// and just fade — no flying in from a parked position.
interface Dot { string: number; fret: number; cx: number; cy: number; visible: boolean; label: string; vlColor: string | null; }
const lastCx: number[] = Array.from({ length: 7 }, (_, s) =>
    s > 0 ? fretCenterX(excerptWindow.value[0] + Math.floor(FRET_WINDOW / 2)) : 0
);

function intervalLabelsForChord(chord: ProgressionChord | undefined): string[] {
    // interval_labels is a comma-separated string, one token per string 1–6.
    const raw = chord?.diagramData?.interval_labels ?? '';
    return raw ? raw.split(',').map(s => s.trim()) : [];
}

const dots = computed<Dot[]>(() => {
    const byString = new Map<number, Position>();
    for (const p of activePositions.value) byString.set(p.string, p);
    const intervalLabels = intervalLabelsForChord(props.chords[activeIndex.value]);

    // Build VL color map for this chord without depending on vlPairs (avoid circular deps).
    const vlColorMap = new Map<string, string>();
    const chordA = props.chords[activeIndex.value];
    const chordB = props.chords[activeIndex.value + 1];
    if (chordA?.diagramData && chordB?.diagramData) {
        const pairs = findResolutionPairs(
            buildPitchMap(chordA.diagramData),
            buildPitchMap(chordB.diagramData),
            chordB.diagramData.quality ?? '', 2
        );
        for (const p of pairs as any[]) {
            vlColorMap.set(`${p.from.string + 1},${p.from.fret}`, arrowColor(p.type));
        }
    }

    const out: Dot[] = [];
    for (let s = 1; s <= 6; s++) {
        const p = byString.get(s);
        const label = intervalLabels[s - 1] ?? '';
        if (p) {
            lastCx[s] = fretCenterX(p.fret);
            const vlColor = vlColorMap.get(`${s},${p.fret}`) ?? null;
            out.push({ string: s, fret: p.fret, cx: lastCx[s], cy: stringY(s), visible: true, label, vlColor });
        } else {
            out.push({ string: s, fret: 0, cx: lastCx[s], cy: stringY(s), visible: false, label: '', vlColor: null });
        }
    }
    return out;
});

// ---------- Voice-leading pairs ----------
// Each pair: { fromString, fromFret, toString, toFret, type, color }
// "from" = resolving dot on current chord (pulses)
// "to"   = ghost dot on next chord (fades in dimmed)
interface VLPair {
    fromString: number; fromFret: number;
    toString: number;   toFret: number;
    type: string; color: string;
}

const vlPairs = computed<VLPair[]>(() => {
    const chordA = props.chords[activeIndex.value];
    const chordB = props.chords[activeIndex.value + 1];
    if (!chordA?.diagramData || !chordB?.diagramData) return [];

    const mapA = buildPitchMap(chordA.diagramData);
    const mapB = buildPitchMap(chordB.diagramData);
    const pairs = findResolutionPairs(mapA, mapB, chordB.diagramData.quality ?? '', 2);

    return pairs.map((p: any) => ({
        fromString: p.from.string + 1, // buildPitchMap uses 0-based string index
        fromFret:   p.from.fret,
        toString:   p.to.string + 1,
        toFret:     p.to.fret,
        type:       p.type,
        color:      arrowColor(p.type),
    }));
});

// Ghost dots: next chord's resolving-target positions, shown dimmed on current view.
interface GhostDot { string: number; cx: number; cy: number; color: string; label: string; }
const ghostDots = computed<GhostDot[]>(() =>
    vlPairs.value.map(pair => ({
        string: pair.toString,
        cx:     fretCenterX(pair.toFret),
        cy:     stringY(pair.toString),
        color:  pair.color,
        label:  intervalLabelsForChord(props.chords[activeIndex.value + 1])[pair.toString - 1] ?? '',
    }))
);

// Set of "fromString,fromFret" for the current chord's resolving dots — used to trigger pulse.
const pulsingDotKeys = computed<Set<string>>(() =>
    new Set(vlPairs.value.map(p => `${p.fromString},${p.fromFret}`))
);

// ---------- Playback ----------
async function playChordAtIndex(index: number) {
    const chord = props.chords[index];
    if (!chord?.diagramData) return;

    await engine.init({ samplesBaseUrl: '/audio/rhythm-samples/' });
    engine.setTempo(180);

    const beats = chord.beats || 0.5;
    const events = chordDiagramToEvents(
        { id: chord.diagramData.id, diagram_data: chord.diagramData.diagram_data },
        { durationBeats: beats, staggerBeats: 0.08 },
    );

    if (!events.length) return;

    currentPlayingIndex.value = index;
    engine.load(events);
    engine.play();
}

async function playNextChord() {
    if (currentPlayingIndex.value === null) {
        stopPlayback();
        return;
    }
    const nextIndex = currentPlayingIndex.value + 1;
    if (nextIndex >= props.chords.length) {
        stopPlayback();
        return;
    }
    const nextChord = props.chords[nextIndex];
    if (nextChord?.diagramData) {
        await playChordAtIndex(nextIndex);
    } else {
        currentPlayingIndex.value = nextIndex;
        playNextChord();
    }
}

async function playProgression() {
    if (props.chords.length === 0) return;
    isPlayingAll.value = true;
    if (currentPlayingIndex.value === null || currentPlayingIndex.value >= props.chords.length - 1) {
        currentPlayingIndex.value = selectedIndex.value;
    }
    await playChordAtIndex(currentPlayingIndex.value);
}

function stopPlayback() {
    if (currentPlayingIndex.value !== null) selectedIndex.value = currentPlayingIndex.value;
    isPlayingAll.value = false;
    currentPlayingIndex.value = null;
    engine.stop();
}

function togglePlayback() {
    // Video-synced (course player): the button drives the shared video clock,
    // not synth audio. `videoPlaying` reflects the real video state — the
    // chord highlight already follows `videoPlayhead` via chordIndexAtTime.
    if (isVideoSynced.value) {
        if (videoPlaying.value) props.onVideoPause?.();
        else props.onVideoPlay?.();
        return;
    }
    if (isPlayingAll.value) stopPlayback();
    else playProgression();
}

/** True while the synced video is actually playing (vs. paused/stopped). */
const videoPlaying = computed(() => isVideoSynced.value && props.videoPlayhead !== null);

/** Unified "is playing" for the button UI — audio path or video path. */
const showAsPlaying = computed(() => isVideoSynced.value ? videoPlaying.value : isPlayingAll.value);

function canPlayAll(): boolean {
    return props.chords.some(c => c.diagramData !== null);
}

function goTo(idx: number) {
    if (idx < 0 || idx >= props.chords.length) return;
    if (isPlayingAll.value) stopPlayback();
    selectedIndex.value = idx;
}

function onKey(e: KeyboardEvent) {
    if (e.key === 'ArrowLeft') { goTo(activeIndex.value - 1); e.preventDefault(); }
    else if (e.key === 'ArrowRight') { goTo(activeIndex.value + 1); e.preventDefault(); }
    else if (e.key === ' ') {
        if (canPlayAll()) { togglePlayback(); e.preventDefault(); }
    }
}

const rootEl = ref<HTMLElement | null>(null);
const sizeAttr = ref('lg');

let ro: ResizeObserver | null = null;
onMounted(() => {
    if (!rootEl.value) return;
    ro = new ResizeObserver(([entry]) => {
        const w = entry.contentRect.width;
        sizeAttr.value = w <= 360 ? 'xs' : w <= 500 ? 'sm' : 'lg';
    });
    ro.observe(rootEl.value);
});

function onFocusIn() { window.addEventListener('keydown', onKey); }
function onFocusOut(e: FocusEvent) {
    if (rootEl.value && !rootEl.value.contains(e.relatedTarget as Node)) {
        window.removeEventListener('keydown', onKey);
    }
}
</script>

<template>
    <div
        ref="rootEl"
        class="sbn-prog-viewer"
        :class="{
            'sbn-prog-viewer--compact': compact,
            'sbn-vintage-card': vintageCard,
            'is-playing': showAsPlaying,
        }"
        :data-size="sizeAttr"
        :style="color ? { '--prog-color': color } : {}"
        tabindex="0"
        @focusin="onFocusIn"
        @focusout="onFocusOut"
    ><div class="sbn-prog-inner">
        <!-- Header -->
        <div v-if="name || category || keyLabel || numerals" class="head">
            <div class="head-left">
                <h4 v-if="name" class="head-title" v-html="name" />
                <div v-if="category || keyLabel" class="head-meta">
                    <span v-if="category" class="badge badge-category">{{ category }}</span>
                    <span v-if="keyLabel" class="badge badge-key">Key of {{ keyLabel }}</span>
                </div>
            </div>
            <div v-if="numerals" class="pro-badge">
                <span class="tag">PRO</span>
                <span class="body">{{ numerals.replace(/,/g, ' – ') }}</span>
            </div>
        </div>

        <!-- Stage: play button + fretboard + chord diagram card -->
        <div class="stage">
            <button
                v-if="interactive && canPlayAll()"
                class="play-btn"
                :class="{ 'is-playing': showAsPlaying }"
                :aria-label="showAsPlaying ? 'Stop progression' : 'Play progression'"
                @click="togglePlayback"
            >
                <svg v-if="showAsPlaying" viewBox="0 0 12 12" fill="currentColor">
                    <rect x="2" y="2" width="3" height="8" />
                    <rect x="7" y="2" width="3" height="8" />
                </svg>
                <svg v-else viewBox="0 0 12 12" fill="currentColor">
                    <path d="M3 2l7 4-7 4z" />
                </svg>
            </button>
            <div class="board-wrap">
                <svg class="board" :viewBox="excerptViewBox" preserveAspectRatio="xMidYMid meet" :height="VB_H" style="overflow: hidden">
                    <!-- Fret lines -->
                    <line
                        v-for="(fl, i) in fretLines"
                        :key="`f${i}`"
                        :class="['fret-line', { nut: fl.isNut }]"
                        :x1="fl.x" :x2="fl.x"
                        :y1="PAD_T" :y2="VB_H - PAD_B"
                    />
                    <!-- Strings -->
                    <line
                        v-for="sl in stringLines"
                        :key="`s${sl.s}`"
                        class="string-line"
                        :x1="sl.x1" :x2="sl.x2"
                        :y1="sl.y" :y2="sl.y"
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
                        v-for="d in dots"
                        :key="`dot-${d.string}`"
                        class="dot-group"
                        :class="{
                            'is-hidden': !d.visible,
                            'is-pulsing': d.visible && pulsingDotKeys.has(`${d.string},${d.fret}`),
                        }"
                        :style="`transform: translate(${d.cx}px, ${d.cy}px); --vl-color: ${d.vlColor ?? 'transparent'}`"
                    >
                        <circle class="dot" r="9" cx="0" cy="0" :style="d.vlColor ? `fill: ${d.vlColor}` : ''" />
                        <text
                            v-if="d.label"
                            class="dot-finger"
                            x="0" y="0"
                            text-anchor="middle"
                            dominant-baseline="central"
                        >{{ d.label }}</text>
                    </g>
                    <!-- Ghost dots: next chord's VL targets, shown dimmed -->
                    <g
                        v-for="g in ghostDots"
                        :key="`ghost-${g.string}`"
                        class="ghost-dot-group"
                        :style="`transform: translate(${g.cx}px, ${g.cy}px)`"
                    >
                        <circle class="ghost-dot" r="9" cx="0" cy="0" :style="`fill: ${g.color}`" />
                        <text
                            v-if="g.label"
                            class="dot-finger"
                            x="0" y="0"
                            text-anchor="middle"
                            dominant-baseline="central"
                        >{{ g.label }}</text>
                    </g>
                </svg>
            </div>
            <!-- Chord diagram card (current chord) -->
            <div v-if="activeChordDiagramData" class="chord-card-aside">
                <span class="aside-chord-name" v-html="chordDisplayHtml(chords[activeIndex])" />
                <ChordDiagram :chord="activeChordDiagramData" />
            </div>
        </div>

        <!-- Chord badge row -->
        <div v-if="chords.length" class="chord-badge-row">
            <button
                v-for="(chord, i) in chords"
                :key="i"
                type="button"
                class="chord-badge"
                :class="{ active: i === activeIndex }"
                @click="goTo(i)"
            >
                <span v-if="chord.numeral">{{ chord.numeral }}</span>
                <span v-else v-html="chordDisplayHtml(chord)" />
            </button>
        </div>
    </div></div>
</template>

<style scoped>
.sbn-prog-viewer {
    --prog-color: var(--clr-accent);
    background: var(--clr-white);
    border: 1px solid var(--clr-border);
    border-radius: var(--radius);
    padding: 12px 14px 10px;
    outline: none;
    transition: box-shadow 0.3s ease;
}

.sbn-prog-inner {
    --btn-size: 36px;
    --btn-icon: 12px;
    --ribbon-name: 18px;
    --ribbon-num: 8px;
    --ribbon-pad: 6px 10px 5px;
    --stage-gap: 12px;
}

.sbn-prog-viewer[data-size="sm"] .sbn-prog-inner {
    --btn-size: 34px;
    --btn-icon: 11px;
    --ribbon-name: 13px;
    --ribbon-num: 8px;
    --ribbon-pad: 6px 4px 4px;
    --stage-gap: 10px;
}

.sbn-prog-viewer[data-size="xs"] .sbn-prog-inner {
    --btn-size: 28px;
    --btn-icon: 9px;
    --ribbon-name: 11px;
    --ribbon-num: 7px;
    --ribbon-pad: 4px 3px 3px;
    --stage-gap: 8px;
}

.sbn-prog-viewer.sbn-vintage-card {
    border-right: 3px solid var(--prog-color);
    border-bottom: 3px solid var(--prog-color);
}

.sbn-prog-viewer--compact {
    padding: 18px 20px 16px;
}

/* Header */
.head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    margin-bottom: 18px;
}
.head-left {
    display: flex;
    flex-direction: column;
    gap: 6px;
    min-width: 0;
}
.head-title {
    margin: 0;
    font-size: 18px;
    font-weight: 700;
    letter-spacing: -0.01em;
    color: var(--clr-text);
    line-height: 1.2;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.head-meta {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
    align-items: center;
}
.badge {
    padding: 3px 8px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 600;
    background: transparent;
    border: 1px solid var(--clr-border);
    color: var(--clr-text-muted);
}
.badge-category {
    background: color-mix(in srgb, var(--prog-color) 18%, transparent);
    border-color: transparent;
    color: var(--prog-color);
    text-transform: uppercase;
    letter-spacing: 0.04em;
}
.pro-badge {
    display: inline-flex;
    align-items: stretch;
    border: 1px solid var(--clr-border);
    border-radius: 4px;
    overflow: hidden;
    font-size: 11px;
    font-weight: 600;
    letter-spacing: 0.04em;
    flex-shrink: 0;
}
.pro-badge .tag {
    background: var(--clr-text);
    color: var(--clr-white);
    padding: 3px 7px;
}
.pro-badge .body {
    padding: 3px 9px;
    background: var(--clr-white);
    color: var(--clr-text);
    font-weight: 500;
}

/* Stage */
.stage {
    display: flex;
    align-items: center;
    gap: var(--stage-gap);
}
.play-btn {
    width: var(--btn-size);
    height: var(--btn-size);
    border-radius: 50%;
    background: var(--clr-white);
    border: 1.5px solid var(--prog-color);
    color: var(--prog-color);
    display: grid;
    place-items: center;
    cursor: pointer;
    transition: all 0.2s ease;
    flex-shrink: 0;
    padding: 0;
}
.play-btn:hover {
    background: color-mix(in srgb, var(--prog-color) 18%, transparent);
}
.play-btn.is-playing {
    background: var(--prog-color);
    color: var(--clr-white);
    box-shadow: 0 0 0 4px color-mix(in srgb, var(--prog-color) 18%, transparent);
}
.play-btn svg {
    width: var(--btn-icon);
    height: var(--btn-icon);
}

.board-wrap {
    flex: 1;
    min-width: 0;
    background: var(--clr-white);
    padding: 4px 0;
}
.board {
    width: 100%;
    display: block;
    transition: all 0.55s var(--ease);
}

.chord-card-aside {
    flex: 0 0 25%;
    max-width: 160px;
    min-width: 80px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 4px;
    padding: 4px 0 4px 8px;
}
.aside-chord-name {
    font-family: var(--font-chord, 'Crimson Text', Georgia, serif);
    font-size: 16px;
    font-weight: 400;
    color: var(--clr-text);
    line-height: 1.1;
    white-space: nowrap;
    display: flex;
    align-items: baseline;
    gap: 1px;
}
.aside-chord-name :deep(.sbn-chord-root)      { font-weight: 700; font-size: 1.05em; }
.aside-chord-name :deep(.sbn-chord-quality)   { font-size: 0.82em; }
.aside-chord-name :deep(.sbn-chord-ext)       { font-size: 0.72em; font-weight: 600; vertical-align: super; line-height: 0; }
.aside-chord-name :deep(.sbn-chord-ext--extra){ font-weight: 400; opacity: 0.75; }
.aside-chord-name :deep(.sbn-chord-bass)      { font-size: 0.85em; }

.board .string-line { stroke: var(--clr-text); stroke-width: 1; }
.board .fret-line { stroke: var(--clr-border); stroke-width: 1; }
.board .fret-line.nut { stroke: var(--clr-text); stroke-width: 3; }
.board .fret-num {
    font-size: 10px;
    font-weight: 600;
    fill: var(--clr-text-dim);
    letter-spacing: 0.02em;
}
.board .inlay { fill: var(--clr-border); }
.board .active-window {
    fill: color-mix(in srgb, var(--clr-red) 14%, transparent);
    transition: x 0.55s var(--ease), width 0.55s var(--ease);
}
.board .dot-group {
    transition: transform 1.1s var(--ease), opacity 0.35s ease;
    filter: drop-shadow(0 1px 2px rgba(0, 0, 0, 0.08));
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
    opacity: 0.35;
    pointer-events: none;
    transition: transform 1.1s var(--ease);
}
.board .ghost-dot {
    /* fill set inline per VL type color */
}
.board .ghost-dot-group .dot-finger {
    fill: var(--clr-white);
}
.board .dot-finger {
    fill: var(--clr-white);
    font-size: 8px;
    font-weight: 700;
    font-family: 'Inter', system-ui, sans-serif;
    pointer-events: none;
    letter-spacing: -0.02em;
}

/* Chord badge row */
.chord-badge-row {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-top: 10px;
}
.chord-badge {
    font-size: 11px;
    font-weight: 600;
    font-family: var(--font-body, system-ui, sans-serif);
    letter-spacing: 0.03em;
    color: var(--prog-color);
    background: color-mix(in srgb, var(--prog-color) 10%, transparent);
    border: 1px solid color-mix(in srgb, var(--prog-color) 25%, transparent);
    border-right-width: 2px;
    border-bottom-width: 2px;
    border-radius: 4px;
    padding: 2px 9px;
    white-space: nowrap;
    cursor: pointer;
    transition: background 0.15s ease, border-color 0.15s ease;
    line-height: 1.6;
}
.chord-badge:hover {
    background: color-mix(in srgb, var(--prog-color) 18%, transparent);
}
.chord-badge.active {
    background: color-mix(in srgb, var(--prog-color) 22%, transparent);
    border-color: color-mix(in srgb, var(--prog-color) 55%, transparent);
    border-right-color: var(--prog-color);
    border-bottom-color: var(--prog-color);
}

/* Pulse when playing */
.sbn-prog-viewer.is-playing {
    animation: pulse-card 1.5s ease-in-out infinite;
}
@keyframes pulse-card {
    0%, 100% { box-shadow: 0 0 0 0 color-mix(in srgb, var(--prog-color) 15%, transparent); }
    50%      { box-shadow: 0 0 0 6px color-mix(in srgb, var(--prog-color) 15%, transparent); }
}

/* Responsive */
@media (max-width: 640px) {
    .sbn-prog-viewer { padding: 16px 14px 14px; }
    .head { flex-direction: column; align-items: flex-start; }
    .pro-badge { font-size: 10px; }
}
</style>
