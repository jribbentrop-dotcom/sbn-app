<script setup lang="ts">
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import type { ChordDiagramData } from './ChordDiagram.vue';
import ChordDiagram from './ChordDiagram.vue';
import FretboardNeck from './fretboard/FretboardNeck.vue';
import { getAudioEngine } from '../../audio/engine/AudioEngine.js';
import { chordDiagramToEvents } from '../../audio/adapters/chordDiagramToEvents.js';
import {
    buildPitchMap,
    findResolutionPairsFromFired,
    findResolutionPairsFromDetails,
    selectDisplayPairs,
    arrowColor,
    CORE_TYPES,
} from './guideToneResolution.js';
import {
    VB_H,
    FRET_FROM, FRET_TO, FRET_WINDOW,
    fretEdgeX, fretCenterX, stringY,
    EXCERPT_VW, MAX_SMOOTH_X,
} from './fretboard/fretboardGeometry';

const QUALITY_MAP: Record<string, [string, string]> = {
    'maj':   ['', ''],
    'min':   ['m', ''],
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

export interface FiredResolutionDetail {
    id: string;
    core: boolean;
    same_string: boolean;
    semitones: number;
    from: { string: number; fret: number; midi: number; tone: string | number };
    to: { string: number; fret: number; midi: number; tone: string | number };
}

export interface ProgressionChord {
    chordName: string;
    diagramData: ChordDiagramData | null;
    beats?: number;
    slug?: string | null;
    numeral?: string;
    functionalRole?: string | null;
    firedResolutions?: string[];
    firedResolutionDetails?: FiredResolutionDetail[];
}

export interface StyleVariant {
    id: string;
    label: string;
    chords: ProgressionChord[];
}

export interface ChordProgressionViewerProps {
    chords: ProgressionChord[];
    variants?: StyleVariant[];
    interactive?: boolean;
    compact?: boolean;
    /** @deprecated Arrows are removed in the Living Fretboard redesign; kept for prop compatibility. */
    showFlowArrows?: boolean;
    color?: string | null;
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
    variants: () => [],
});

// ---------- Style variants ----------
const activeVariantId = ref<string | null>(null);
const activeChords = computed<ProgressionChord[]>(() => {
    if (!props.variants.length) return props.chords;
    const v = props.variants.find(v => v.id === activeVariantId.value) ?? props.variants[0];
    return v.chords;
});

/** True when this viewer is wired to an external video clock (course player). */
const isVideoSynced = computed(() => !!props.onVideoPlay);

/** Effective videoPlayhead: external when course-synced. */
const effectivePlayhead = computed<number | null>(() => props.videoPlayhead ?? null);

const effectiveStartSec = computed(() => props.videoStartSec);
const effectiveTempoBpm = computed(() => props.tempoBpm);

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
// Pure geometry (VB_H, FRET_FROM/TO/WINDOW, fretEdgeX, fretCenterX, stringY,
// EXCERPT_VW, MAX_SMOOTH_X) now lives in ./fretboard/fretboardGeometry.ts and
// is imported above. NECK_L/NECK_R/fretLines/stringLines/inlays/fretNumbers
// moved into FretboardNeck.vue, which owns drawing the static neck.

// ---------- Video-sync time map ----------
// Cumulative beat spans per chord, built from each chord's `beats` (default 0.5).
// This is the time→index backbone the shared playhead resolves against.
interface ChordSpan { startBeat: number; endBeat: number; }
const chordTimeline = computed<ChordSpan[]>(() => {
    let cursor = 0;
    return activeChords.value.map(c => {
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
    const beat = (sec - effectiveStartSec.value) * (effectiveTempoBpm.value / 60);
    if (beat <= 0) return 0;
    const tl = chordTimeline.value;
    if (!tl.length) return 0;
    if (beat >= tl[tl.length - 1].endBeat) return tl.length - 1;
    return tl.findIndex(span => beat < span.endBeat);
}

// ---------- Active chord & dot positions ----------
const activeIndex = computed<number>(() => {
    // Video clock (self-contained or external) wins over audio / manual selection.
    if (effectivePlayhead.value !== null) return chordIndexAtTime(effectivePlayhead.value);
    if (currentPlayingIndex.value !== null) return currentPlayingIndex.value;
    return selectedIndex.value;
});

const selectedIndex = ref(props.initialIndex);

const activeChordDiagramData = computed<ChordDiagramData | null>(() =>
    activeChords.value[activeIndex.value]?.diagramData ?? null
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

const activePositions = computed<Position[]>(() => positionsForChord(activeChords.value[activeIndex.value]));

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
// Max left-edge so the right side of the viewBox never goes past the last fret wire.
const MAX_SMOOTH_X = fretEdgeX[FRET_TO - FRET_FROM + 1] - EXCERPT_VW;

// Smoothly animated x-origin (CSS can't transition viewBox, so we lerp in rAF).
// Initialise already centered on chord 0 so there's no slide-in on mount.
const smoothX = ref(Math.min(fretEdgeX[excerptWindow.value[0] - FRET_FROM], MAX_SMOOTH_X));
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

// Only pan when the window itself changes — not on every chord.
watch(excerptWindow, ([lo]) => {
    animateX(Math.min(fretEdgeX[lo - FRET_FROM], MAX_SMOOTH_X));
});
onBeforeUnmount(() => { if (rafId !== null) cancelAnimationFrame(rafId); });

const excerptViewBox = computed(() => `${smoothX.value} 0 ${EXCERPT_VW} ${VB_H}`);

// 6 dots, keyed by string number (1..6) for stable morphing.
// lastCx remembers the last visible cx per string so invisible dots stay in place
// and just fade — no flying in from a parked position.
interface Dot { string: number; fret: number; cx: number; cy: number; visible: boolean; label: string; isRoot: boolean; vlColor: string | null; }
const lastCx: number[] = Array.from({ length: 7 }, (_, s) =>
    s > 0 ? fretCenterX(excerptWindow.value[0] + Math.floor(FRET_WINDOW / 2)) : 0
);

function intervalLabelsForChord(chord: ProgressionChord | undefined): string[] {
    // interval_labels is a comma-separated string, one token per string 1–6.
    const raw = chord?.diagramData?.interval_labels ?? '';
    return raw ? raw.split(',').map(s => s.trim()) : [];
}

/**
 * Guide-tone pairs for the edge chordA → chordB, capped for readability.
 *
 * Prefers the builder's fired_resolution_details (exact string/fret pairs,
 * core flags, same-string preference); falls back to reconstructing dots
 * from fired resolution IDs for older payloads. Either way at most two
 * pairs survive — core motions first — so the student sees one or two
 * clean semitone moves, not a web of arrows.
 */
function resolutionPairsFor(chordA: ProgressionChord | undefined, chordB: ProgressionChord | undefined): any[] {
    if (!chordA?.diagramData || !chordB?.diagramData) return [];
    const mapA = buildPitchMap(chordA.diagramData);
    const mapB = buildPitchMap(chordB.diagramData);

    const details = chordA.firedResolutionDetails ?? [];
    if (details.length) {
        return findResolutionPairsFromDetails(details, mapA, mapB);
    }

    const pairs = findResolutionPairsFromFired(
        mapA, mapB, chordA.firedResolutions ?? [], chordB.diagramData.quality ?? '',
    ) as any[];
    return selectDisplayPairs(pairs.map(p => ({
        ...p,
        core: CORE_TYPES.has(p.type),
        sameString: p.from.string === p.to.string,
    })));
}

const dots = computed<Dot[]>(() => {
    const byString = new Map<number, Position>();
    for (const p of activePositions.value) byString.set(p.string, p);
    const intervalLabels = intervalLabelsForChord(activeChords.value[activeIndex.value]);

    // Build VL color map for this chord without depending on vlPairs (avoid circular deps).
    const vlColorMap = new Map<string, string>();
    const chordA = activeChords.value[activeIndex.value];
    const chordB = activeChords.value[activeIndex.value + 1];
    {
        const pairs = resolutionPairsFor(chordA, chordB);
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
            out.push({ string: s, fret: p.fret, cx: lastCx[s], cy: stringY(s), visible: true, label, isRoot: label === 'R', vlColor });
        } else {
            out.push({ string: s, fret: 0, cx: lastCx[s], cy: stringY(s), visible: false, label: '', isRoot: false, vlColor: null });
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
    const chordA = activeChords.value[activeIndex.value];
    const chordB = activeChords.value[activeIndex.value + 1];
    const pairs = resolutionPairsFor(chordA, chordB);

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
        label:  intervalLabelsForChord(activeChords.value[activeIndex.value + 1])[pair.toString - 1] ?? '',
    }))
);

// Set of "fromString,fromFret" for the current chord's resolving dots — used to trigger pulse.
const pulsingDotKeys = computed<Set<string>>(() =>
    new Set(vlPairs.value.map(p => `${p.fromString},${p.fromFret}`))
);

// ---------- VL text rows ----------
const TYPE_LABEL: Record<string, string> = {
    'seventh-to-third': '7th → 3rd',
    'third-to-root':    '3rd → root',
    'ninth-ext':        '9th ext',
    'eleventh-ext':     '11th ext',
    'fifth-ext':        '5th cont.',
};

interface VLRow {
    fromInterval: string;
    toInterval: string;
    motion: string; // '↓ half step' | '↓ whole step' | '↑ half step' | etc.
    typeLabel: string;
    color: string;
}

const vlRows = computed<VLRow[]>(() => {
    const chordA = activeChords.value[activeIndex.value];
    const chordB = activeChords.value[activeIndex.value + 1];
    const pairs = resolutionPairsFor(chordA, chordB) as any[];

    return pairs.map(p => {
        const semitones = p.to.midi - p.from.midi;
        const abs = Math.abs(semitones);
        const dir = semitones < 0 ? '↓' : semitones > 0 ? '↑' : '';
        const dist = abs === 0 ? 'common tone'
            : abs === 1 ? `${dir} half step`
            : abs === 2 ? `${dir} whole step`
            : `${dir} ${abs} st`;
        return {
            fromInterval: p.from.label,
            toInterval:   p.to.label,
            motion:       dist,
            typeLabel:    TYPE_LABEL[p.type] ?? p.type,
            color:        arrowColor(p.type),
        };
    });
});

// ---------- Playback ----------
async function playChordAtIndex(index: number) {
    const chord = activeChords.value[index];
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
    if (nextIndex >= activeChords.value.length) {
        stopPlayback();
        return;
    }
    const nextChord = activeChords.value[nextIndex];
    if (nextChord?.diagramData) {
        await playChordAtIndex(nextIndex);
    } else {
        currentPlayingIndex.value = nextIndex;
        playNextChord();
    }
}

async function playProgression() {
    if (activeChords.value.length === 0) return;
    isPlayingAll.value = true;
    if (currentPlayingIndex.value === null || currentPlayingIndex.value >= activeChords.value.length - 1) {
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
    return activeChords.value.some(c => c.diagramData !== null);
}

function goTo(idx: number) {
    if (idx < 0 || idx >= activeChords.value.length) return;
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
            'is-playing': showAsPlaying,
        }"
        :data-size="sizeAttr"
        :style="color ? { '--prog-color': color, '--play-color': color } : {}"
        tabindex="0"
        @focusin="onFocusIn"
        @focusout="onFocusOut"
    ><div class="sbn-prog-inner">
        <!-- Header -->
        <div v-if="name || category || keyLabel || activeChords.length || numerals" class="head">
            <div class="head-left">
                <h4 v-if="name" class="head-title" v-html="name" />
                <div v-if="category || keyLabel" class="head-meta">
                    <span v-if="category" class="sbn-badge badge-category">{{ category }}</span>
                    <span v-if="keyLabel" class="sbn-badge sbn-badge-muted">Key of {{ keyLabel }}</span>
                </div>
            </div>
            <!-- Chord selector chips — clickable when chords available, static fallback from numerals prop -->
            <div v-if="activeChords.length" class="head-numerals">
                <button
                    v-for="(chord, i) in activeChords"
                    :key="i"
                    type="button"
                    class="sbn-numeral-chip sbn-numeral-chip--btn"
                    :class="{ active: i === activeIndex }"
                    @click="goTo(i); interactive && playChordAtIndex(i)"
                >
                    <svg class="chip-play-icon" viewBox="0 0 8 8" fill="currentColor" aria-hidden="true"><path d="M1.5 1l5 3-5 3z"/></svg>
                    <span v-if="chord.numeral">{{ chord.numeral }}</span>
                    <span v-else v-html="chordDisplayHtml(chord)" />
                </button>
            </div>
            <div v-else-if="numerals" class="head-numerals">
                <span
                    v-for="n in numerals.split(/[,–]/).map(s => s.trim()).filter(Boolean)"
                    :key="n"
                    class="sbn-numeral-chip"
                >{{ n }}</span>
            </div>
        </div>


        <!-- Stage: fretboard + chord diagram card -->
        <div class="stage">
            <div class="board-wrap">
                <FretboardNeck
                    :dots="dots"
                    :ghost-dots="ghostDots"
                    :view-box="excerptViewBox"
                    :pulsing-dot-keys="pulsingDotKeys"
                />
            </div>
            <!-- Chord diagram card (current chord) -->
            <div v-if="activeChordDiagramData" class="chord-card-aside">
                <span class="aside-chord-name" v-html="chordDisplayHtml(activeChords[activeIndex])" />
                <ChordDiagram :chord="activeChordDiagramData" />
            </div>
        </div>

        <!-- Voice-leading guide tone table -->
        <div v-if="vlRows.length" class="vl-table-wrap">
            <div class="vl-table-label">Guide tone movements → next chord</div>
            <div class="vl-table">
                <div
                    v-for="(row, i) in vlRows"
                    :key="i"
                    class="vl-row"
                >
                    <span class="vl-dot" :style="{ background: row.color }" />
                    <span class="vl-interval vl-from">{{ row.fromInterval }}</span>
                    <span class="vl-arrow">→</span>
                    <span class="vl-interval vl-to">{{ row.toInterval }}</span>
                    <span class="vl-motion">{{ row.motion }}</span>
                </div>
            </div>
        </div>

        <!-- Style variant tab strip -->
        <div v-if="variants.length" class="style-tabs">
            <button
                v-for="v in variants"
                :key="v.id"
                type="button"
                class="style-tab"
                :class="{ active: v.id === (activeVariantId ?? variants[0]?.id) }"
                @click="activeVariantId = v.id; selectedIndex = 0; currentPlayingIndex = null"
            >{{ v.label }}</button>
        </div>

    </div></div>
</template>

<style scoped>
.sbn-prog-viewer {
    --prog-color: var(--clr-accent);
    --play-color: #7e8896;
    --str-graded: #9aa7b4;
    background: var(--clr-white);
    border: 1px solid var(--clr-border);
    border-radius: var(--radius);
    padding: 12px 14px 10px;
    outline: none;
    transition: border-color 0.15s var(--ease);
    min-width: 0;
    overflow: hidden;
}

.sbn-prog-inner {
    --ribbon-name: 18px;
    --ribbon-num: 8px;
    --ribbon-pad: 6px 10px 5px;
    --stage-gap: 12px;
}

.sbn-prog-viewer[data-size="sm"] .sbn-prog-inner {
    --ribbon-name: 13px;
    --ribbon-num: 8px;
    --ribbon-pad: 6px 4px 4px;
    --stage-gap: 10px;
}

.sbn-prog-viewer[data-size="xs"] .sbn-prog-inner {
    --ribbon-name: 11px;
    --ribbon-num: 7px;
    --ribbon-pad: 4px 3px 3px;
    --stage-gap: 8px;
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
    flex-wrap: wrap;
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
.badge-category {
    background: color-mix(in srgb, var(--prog-color) 18%, transparent);
    border-color: transparent;
    color: var(--prog-color);
    text-transform: uppercase;
    letter-spacing: 0.04em;
}
.head-numerals {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
}


/* Stage */
.stage {
    display: flex;
    align-items: center;
    gap: var(--stage-gap);
}
.board-wrap {
    flex: 1;
    min-width: 0;
    background: var(--clr-white);
    padding: 4px 0;
}
/* .board / neck visuals now live in fretboard/FretboardNeck.vue (scoped there) */

.chord-card-aside {
    flex: 0 0 25%;
    max-width: 160px;
    min-width: 80px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 4px;
    padding: 4px 0 4px 12px;
    border-left: 1px solid var(--clr-border-dim, #eef1f5);
    margin-left: 4px;
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

/* VL guide tone table */
.vl-table-wrap {
    margin-top: 12px;
    padding-top: 10px;
    border-top: 1px solid var(--clr-border-dim, #eef1f5);
}
.vl-table-label {
    font-size: 10px;
    font-weight: 600;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--clr-text-muted);
    margin-bottom: 6px;
}
.vl-table {
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.vl-row {
    display: flex;
    align-items: center;
    gap: 7px;
    font-size: 12px;
    line-height: 1.4;
}
.vl-dot {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    flex-shrink: 0;
}
.vl-interval {
    font-family: 'Georgia', 'Times New Roman', serif;
    font-size: 12px;
    font-weight: 600;
    color: var(--clr-text);
    min-width: 28px;
}
.vl-arrow {
    color: var(--clr-text-muted);
    font-size: 11px;
}
.vl-motion {
    color: var(--clr-text-dim);
    font-size: 11px;
}

/* Style variant tab strip */
.style-tabs {
    display: flex;
    gap: 0;
    margin-top: 14px;
    border-top: 1px solid var(--clr-border-dim, #eef1f5);
    padding-top: 10px;
    flex-wrap: wrap;
    gap: 4px;
}
.style-tab {
    font-size: 11px;
    font-weight: 500;
    padding: 4px 12px;
    border-radius: 999px;
    border: 1px solid var(--clr-border);
    background: transparent;
    color: var(--clr-text-muted);
    cursor: pointer;
    transition: background 0.12s, border-color 0.12s, color 0.12s;
    line-height: 1.5;
}
.style-tab:hover {
    background: var(--clr-surface-2);
    color: var(--clr-text);
}
.style-tab.active {
    background: color-mix(in srgb, var(--prog-color) 14%, transparent);
    border-color: color-mix(in srgb, var(--prog-color) 50%, transparent);
    color: var(--prog-color);
    font-weight: 600;
}

/* Responsive */
@media (max-width: 640px) {
    .sbn-prog-viewer { padding: 16px 14px 14px; }
    .head { flex-direction: column; align-items: flex-start; }
    .head-numerals { flex-wrap: wrap; }
}
</style>

<style>
.sbn-numeral-chip--btn {
    display: inline-flex !important;
    align-items: center;
    gap: 4px;
}
.chip-play-icon {
    width: 7px;
    height: 7px;
    opacity: 0.25;
    flex-shrink: 0;
    transition: opacity 0.12s;
    animation: chip-pulse 2.4s ease-in-out infinite;
}
.sbn-numeral-chip--btn:hover .chip-play-icon {
    opacity: 0.7;
    animation: none;
}
.sbn-numeral-chip--btn.active .chip-play-icon {
    opacity: 0;
    width: 0;
    margin: 0;
    animation: none;
}
@keyframes chip-pulse {
    0%, 100% { opacity: 0.18; }
    50%       { opacity: 0.45; }
}
</style>
