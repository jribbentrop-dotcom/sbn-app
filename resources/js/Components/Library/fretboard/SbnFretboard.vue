<script setup lang="ts">
/**
 * SbnFretboard.vue — Vue wrapper for the <sbn-fretboard> course tag.
 *
 * Takes the full fretboard record (fetched by mountSbnNodes from
 * /api/sbn/fretboards/{slug}) and renders it via FretboardNeck.vue,
 * replacing the old vanilla-JS sbnHydrateFretboard renderer.
 *
 * String orientation: SBN convention — string 1 = Low E, 6 = High e.
 * FretboardNeck renders Low E at BOTTOM (stringY(1) > stringY(6)) because
 * that's the SVG convention used throughout. The frets string ("x32010") is
 * indexed [0..5] = low E→high e, so frets[0] maps to string 1, frets[5] to
 * string 6. The old flexbox renderer listed string labels top→bottom
 * (e,B,G,D,A,E), which is HIGH→LOW. We now match the prog-viewer convention
 * (LOW→HIGH bottom-to-top in SVG), which is the standard notation orientation.
 *
 * Guide-tone color palette matches chords.js GT_COLORS:
 *   b7/7/maj7 → amber (#d97706)
 *   3/b3      → blue  (#2563eb)
 *   R         → green (#16a34a)
 *   9/b9/#9/2/11/#11/13/b13/6 → purple (#7c3aed)
 *   5/b5/#5   → gray  (#6b7280)
 */
import { ref, computed, watch, onBeforeUnmount } from 'vue';
import FretboardNeck, {
    type FretboardNeckDot,
    type FretboardNeckOpenMarker,
} from './FretboardNeck.vue';
import { stringY, makeGeometry, VB_H, PAD_T, PAD_B } from './fretboardGeometry';
import { semitoneOffset, foldShapeOffset, transposeFret } from './fretboardTranspose';

// ── Data model ──────────────────────────────────────────────────────────────

interface ScaleDot {
    s: number;   // 0-indexed: 0 = low E, 5 = high e
    f: number;   // fret number; 0 = open (not rendered in scale mode)
    finger?: string | number;
    iv?: string; // optional interval token (R,3,b3,5,b7…) — positions mode guide-tone color
}

/** Named camera window for positions mode. */
interface FretWindow {
    label?: string;
    from: number; // first fret shown (inclusive)
    to: number;   // last fret shown (inclusive)
}

interface FretboardVoicing {
    label?: string;
    frets?: string;       // chord/sequence mode: "x32010"
    fingers?: string;     // chord/sequence mode: finger per string
    interval_labels?: string; // comma-separated, one per string 1..6
    dots?: ScaleDot[];    // scale mode
}

interface FretboardRecord {
    slug: string;
    title?: string;
    root_note?: string | null;
    display_mode: 'chord' | 'scale' | 'sequence' | 'positions';
    fret_count: number;
    start_fret: number;
    show_guide_tones: boolean;
    show_rh_fingers: boolean;
    voicings: FretboardVoicing[];
    windows?: FretWindow[];
    start_window?: number;
    /** Target key requested by the course tag's `key="…"` attr (e.g. "G"); requires root_note to be set on the record. Positions mode only. */
    transposeKey?: string | null;
}

const props = defineProps<{ data: FretboardRecord }>();

// ── State ────────────────────────────────────────────────────────────────────

const activeFrame = ref(0);
const guideTonesActive = ref(false);

const voicings = computed(() => {
    const vs = props.data.voicings ?? [];
    if (!vs.length) return [{ label: '', frets: 'xxxxxx', fingers: '000000', interval_labels: '' }];
    return vs;
});

const currentVoicing = computed(() => voicings.value[activeFrame.value] ?? voicings.value[0]);
const isMultiFrame = computed(() => voicings.value.length > 1);

// start_fret: coalesce 0 → 1 (reference §3 / JS || 1 behaviour)
const startFret = computed(() => (props.data.start_fret || 1));
const fretCount = computed(() => props.data.fret_count || 12);
const isScale = computed(() => props.data.display_mode === 'scale');
const isPositions = computed(() => props.data.display_mode === 'positions');

// ── Positions mode: full-neck dots + named camera windows ─────────────────────
// The whole scale lives in voicings[0].dots[]; the camera pans between windows.

const rawPosDots = computed((): ScaleDot[] => {
    if (!isPositions.value) return [];
    return props.data.voicings?.[0]?.dots ?? [];
});

const rawWindows = computed((): FretWindow[] => {
    if (!isPositions.value) return [];
    const ws = props.data.windows ?? [];
    return ws.filter(w => w && Number.isFinite(w.from) && Number.isFinite(w.to));
});

// Fold the requested transpose offset once, against the whole shape's fret
// range (all dots + window boundaries) — not per-fret. Per-fret folding would
// let one dot or window boundary land an octave away from its neighbors,
// distorting the shape (e.g. a window's `from` folding but `to` not).
const transposeSemitones = computed(() => {
    if (props.data.display_mode !== 'positions') return 0;
    const root = props.data.root_note;
    const target = props.data.transposeKey;
    if (!root || !target) return 0;
    const raw = semitoneOffset(root, target);
    if (!raw) return 0;

    const frets = [
        ...rawPosDots.value.map(d => d.f),
        ...rawWindows.value.flatMap(w => [w.from, w.to]),
    ];
    if (!frets.length) return raw;
    return foldShapeOffset(Math.min(...frets), Math.max(...frets), raw);
});

const posDots = computed((): ScaleDot[] => {
    const st = transposeSemitones.value;
    if (!st) return rawPosDots.value;
    return rawPosDots.value.map(d => ({ ...d, f: transposeFret(d.f, st) }));
});

const windows = computed((): FretWindow[] => {
    const st = transposeSemitones.value;
    if (!st) return rawWindows.value;
    return rawWindows.value.map(w => ({
        ...w,
        from: transposeFret(w.from, st),
        to: transposeFret(w.to, st),
    }));
});

// Author-configured opening window (defaults to 0 = first position), clamped
// against the actual windows list in case of stale/out-of-range data.
const initialWindowIdx = computed(() => {
    const n = props.data.start_window ?? 0;
    return (n >= 0 && n < windows.value.length) ? n : 0;
});

const activeWindowIdx = ref(initialWindowIdx.value);
const activeWindow = computed((): FretWindow | null =>
    windows.value[activeWindowIdx.value] ?? windows.value[0] ?? null);

// Full-neck geometry: default span 1..15, widened if any dot/window reaches higher.
const posFullFretCount = computed(() => {
    let maxF = 15;
    for (const d of posDots.value) if (d.f > maxF) maxF = d.f;
    for (const w of windows.value) if (w.to > maxF) maxF = w.to;
    return Math.min(maxF, 24); // neck cap
});
const posGeom = computed(() => makeGeometry(1, posFullFretCount.value));

// ── Shared viewBox vertical dimensions (both modes) ─────────────────────────
const POS_VB_Y = PAD_T - 14;
const POS_VB_H = (VB_H - PAD_B + 22) - POS_VB_Y;

// ── Positions-mode excerpt width: fret-count-based, same logic as FretboardNeck ──
// Use a 5-fret window (VIEWPORT_FRETS) centred on each named window so the
// apparent zoom matches scale/chord mode at any neck position.
// Fixed excerpt width in SVG units — equivalent to a 5-fret window at fret 7
// (mid-neck reference). Constant so the board renders the same physical size
// regardless of which position is active.
const POS_EXCERPT_VW = 236;
const posExcerptVW = computed(() => POS_EXCERPT_VW);

// Max left-edge so the excerpt never pans past the last fret wire.
const posMaxSmoothX = computed(() => {
    const g = posGeom.value;
    return Math.max(g.fretEdgeX[0], g.fretEdgeX[posFullFretCount.value] - posExcerptVW.value);
});

// Target left-edge x for the active window: pan so the window is centered
// within the excerpt, then clamp to [NECK_L, posMaxSmoothX].
function windowTargetX(w: FretWindow | null): number {
    const g = posGeom.value;
    if (!w) return g.fretEdgeX[0];
    const winLeft = g.fretEdgeX[Math.max(0, w.from - 1)];
    const winWidth = g.fretEdgeX[w.to] - winLeft;
    // Subtract POS_PAD_X so the window centers within the visible (non-padded) portion.
    const centered = winLeft - (posExcerptVW.value - winWidth) / 2;
    return Math.max(g.fretEdgeX[0], Math.min(centered, posMaxSmoothX.value));
}

// rAF lerp camera pan (ported from ChordProgressionViewer.vue:278-296).
const smoothX = ref(0);
let rafId: number | null = null;
function animateX(target: number) {
    if (rafId !== null) cancelAnimationFrame(rafId);
    const SPEED = 0.035;
    function step() {
        const delta = target - smoothX.value;
        if (Math.abs(delta) < 0.1) { smoothX.value = target; rafId = null; return; }
        smoothX.value += delta * SPEED;
        rafId = requestAnimationFrame(step);
    }
    rafId = requestAnimationFrame(step);
}

// Initialise camera on the starting window (no slide-in on mount).
smoothX.value = windowTargetX(activeWindow.value);

watch([activeWindow, posExcerptVW], ([w]) => animateX(windowTargetX(w as FretWindow | null)));

const posViewBox = computed(() =>
    `${smoothX.value} ${POS_VB_Y} ${posExcerptVW.value} ${POS_VB_H}`);

function goToWindow(idx: number) {
    if (idx < 0 || idx >= windows.value.length) return;
    activeWindowIdx.value = idx;
}
function stepWindow(dir: -1 | 1) {
    const next = activeWindowIdx.value + dir;
    if (next >= 0 && next < windows.value.length) activeWindowIdx.value = next;
}

// ── Drag / swipe on the board to change position ──────────────────────────
// DRAG_THRESHOLD: px of drag before a step fires.
// STEP_COOLDOWN_MS: min time between steps — prevents skipping positions when
// fret windows are close together and the camera hasn't finished panning yet.
const DRAG_THRESHOLD = 40;
const STEP_COOLDOWN_MS = 400;
let dragStartX = 0;
let dragOriginX = 0;
let isDragging = false;
let lastStepTime = 0;

function onBoardPointerDown(e: PointerEvent) {
    if (windows.value.length < 2) return;
    if (e.button !== 0) return;
    dragStartX = e.clientX;
    dragOriginX = e.clientX;
    isDragging = false;
    (e.currentTarget as HTMLElement).setPointerCapture(e.pointerId);
}
function onBoardPointerMove(e: PointerEvent) {
    if (!e.buttons) return;
    const dx = e.clientX - dragOriginX;
    if (Math.abs(dx) >= DRAG_THRESHOLD) {
        const now = Date.now();
        if (now - lastStepTime >= STEP_COOLDOWN_MS) {
            isDragging = true;
            stepWindow(dx < 0 ? 1 : -1);
            lastStepTime = now;
        }
        dragOriginX = e.clientX;
    }
}
function onBoardPointerUp(e: PointerEvent) {
    if (e.button !== 0) return;
    const totalDx = e.clientX - dragStartX;
    if (!isDragging && Math.abs(totalDx) < 8) {
        goToWindow((activeWindowIdx.value + 1) % windows.value.length);
    }
    isDragging = false;
}

onBeforeUnmount(() => {
    if (rafId !== null) cancelAnimationFrame(rafId);
});

// ── Guide-tone color ─────────────────────────────────────────────────────────

function gtColor(label: string | undefined): string | null {
    if (!label || label === 'x' || label === 'X') return null;
    const l = label.trim();
    if (l === 'R') return '#16a34a';
    if (l === '5' || l === 'b5' || l === '#5') return '#6b7280';
    if (l === 'b7' || l === '7' || l === 'maj7' || l === 'bb7') return '#d97706';
    if (l === '3' || l === 'b3') return '#2563eb';
    // 9ths/extensions/6 → purple
    if (/^(b?9|#9|2|11|#11|4|13|b13|6)$/.test(l)) return '#7c3aed';
    return null;
}

// ── Parse frets string ("x32010", low-E→high-e, idx 0=str1) ─────────────────

function parseFretString(fretStr: string, _startFret: number): Array<'x' | number> {
    if (!fretStr) return ['x', 'x', 'x', 'x', 'x', 'x'];
    if (fretStr.length <= 6) {
        const result: Array<'x' | number> = [];
        for (let i = 0; i < 6; i++) {
            const c = fretStr[i] ?? 'x';
            result.push((c === 'x' || c === 'X') ? 'x' : parseInt(c, 16));
        }
        return result;
    }
    // Multi-digit: simple best-effort 6-char slice (same fallback as chords.js)
    return parseFretString(fretStr.substring(0, 6), _startFret);
}

// ── Geometry (for computing cx/cy of dots) ───────────────────────────────────

const geom = computed(() => makeGeometry(startFret.value, fretCount.value));

// ── Map voicing → FretboardNeck props ────────────────────────────────────────

const neckDots = computed((): FretboardNeckDot[] => {
    const v = currentVoicing.value;
    const ivLabels = (v.interval_labels ?? '').split(',').map(s => s.trim());
    const showGT = props.data.show_guide_tones && guideTonesActive.value;
    const g = geom.value;

    if (isPositions.value) {
        // Full-neck dots; visibility gated by the active camera window so dots
        // fade in/out as the camera pans (via FretboardNeck's .is-hidden).
        const pg = posGeom.value;
        const w = activeWindow.value;
        // Guide tones auto-on in positions mode when show_guide_tones is set —
        // there's no per-string GT toggle button in this mode (dots carry `iv`).
        const gtOn = props.data.show_guide_tones;
        return posDots.value
            .filter(d => d.f > 0) // f:0 open strings don't render on a fretted neck view
            .map((d): FretboardNeckDot => {
                const str = d.s + 1;               // 0-indexed s → 1-indexed SBN string
                const iv = (d.iv ?? '').trim();
                const color = gtOn ? gtColor(iv) : null;
                const inWindow = !!w && d.f >= w.from && d.f <= w.to;
                const finger = d.finger != null ? String(d.finger) : '';
                const label = gtOn && iv
                    ? iv
                    : (finger && finger !== '0' ? finger : '');
                return {
                    string: str,
                    fret: d.f,
                    cx: pg.fretCenterX(d.f),
                    cy: stringY(str),
                    visible: inWindow,
                    label,
                    isRoot: iv === 'R',
                    vlColor: color,
                };
            });
    }

    if (isScale.value) {
        // Scale mode: expand dots[] — multiple per string allowed, f:0 skipped.
        // Not transposable (fixed start_fret/fret_count viewport, unlike
        // positions mode's sliding camera) — see SBN-Fretboard-Reference.md §6.
        const rawDots = v.dots ?? [];
        return rawDots
            .filter(d => d.f >= startFret.value) // f:0 and f < startFret don't render
            .map((d, i): FretboardNeckDot => {
                // Scale dots: s is 0-indexed (0=low E=string 1, 5=high e=string 6)
                const str = d.s + 1; // convert to 1-indexed SBN string
                const fret = d.f;
                const cx = g.fretCenterX(fret);
                const cy = stringY(str);
                const finger = d.finger != null ? String(d.finger) : '';
                // interval_labels for scale mode is a single comma-separated string
                // that covers all dots in order — or may be empty. Use position i.
                const label = showGT
                    ? (ivLabels[i] ?? '')
                    : (finger && finger !== '0' ? finger : '');
                const intervalLabel = ivLabels[i] ?? '';
                const color = showGT ? gtColor(intervalLabel) : null;
                return {
                    string: str,
                    fret,
                    cx,
                    cy,
                    visible: true,
                    label,
                    isRoot: intervalLabel === 'R',
                    vlColor: color,
                };
            });
    } else {
        // Chord / sequence mode: parse frets string, one dot per string
        const fretStr = v.frets ?? 'xxxxxx';
        const fingerStr = v.fingers ?? '000000';
        const frets = parseFretString(fretStr, startFret.value);
        const out: FretboardNeckDot[] = [];
        for (let si = 0; si < 6; si++) {
            const str = si + 1; // 1-indexed
            const fv = frets[si];
            if (fv === 'x' || fv === 0) continue; // muted or open → handled by openStrings
            const fret = fv as number;
            if (fret < startFret.value || fret > startFret.value + fretCount.value - 1) continue;
            const cx = g.fretCenterX(fret);
            const cy = stringY(str);
            const finger = fingerStr[si] !== '0' ? fingerStr[si] : '';
            const intervalLabel = ivLabels[si] ?? '';
            const color = showGT ? gtColor(intervalLabel) : null;
            const label = showGT && intervalLabel
                ? intervalLabel
                : (finger && finger !== '0' ? finger : '');
            out.push({
                string: str,
                fret,
                cx,
                cy,
                visible: true,
                label,
                isRoot: intervalLabel === 'R',
                vlColor: color,
            });
        }
        return out;
    }
});

const openStringMarkers = computed((): FretboardNeckOpenMarker[] => {
    if (isScale.value || isPositions.value) return []; // no nut/open column
    const v = currentVoicing.value;
    const fretStr = v.frets ?? 'xxxxxx';
    const frets = parseFretString(fretStr, startFret.value);
    const ivLabels = (v.interval_labels ?? '').split(',').map(s => s.trim());
    const showGT = props.data.show_guide_tones && guideTonesActive.value;
    const markers: FretboardNeckOpenMarker[] = [];
    for (let si = 0; si < 6; si++) {
        const fv = frets[si];
        const str = si + 1;
        if (fv === 'x') {
            markers.push({ string: str, kind: 'muted' });
        } else if (fv === 0) {
            const intervalLabel = ivLabels[si] ?? '';
            markers.push({
                string: str,
                kind: 'open',
                color: showGT ? gtColor(intervalLabel) : null,
                label: intervalLabel,
            });
        }
    }
    return markers;
});

// Show nut when chord/sequence mode AND startFret === 1
const showNut = computed(() => !isScale.value && !isPositions.value && startFret.value === 1);

// RH fingers: auto-assign p/i/m/a when show_rh_fingers is set
// Low string that plays gets 'p'; up to 3 higher strings get i/m/a.
const rhFingers = computed((): string[] => {
    if (!props.data.show_rh_fingers || isScale.value || isPositions.value) return [];
    const v = currentVoicing.value;
    const fretStr = v.frets ?? 'xxxxxx';
    const frets = parseFretString(fretStr, startFret.value);
    const played: number[] = [];
    frets.forEach((fv, si) => { if (fv !== 'x') played.push(si); });
    played.sort((a, b) => a - b);
    const out: string[] = ['', '', '', '', '', ''];
    if (!played.length) return out;
    out[played[0]] = 'p';
    const upper = played.slice(1).slice(-3);
    ['i', 'm', 'a'].forEach((name, i) => {
        if (upper[i] !== undefined) out[upper[i]] = name;
    });
    return out;
});

// ── Frame navigation ─────────────────────────────────────────────────────────

function stepFrame(dir: -1 | 1) {
    const next = activeFrame.value + dir;
    if (next >= 0 && next < voicings.value.length) {
        activeFrame.value = next;
    }
}

// ── Neck prop resolution (positions mode overrides the range + view-box) ──────

const neckStartFret = computed(() => isPositions.value ? 1 : startFret.value);
const neckFretCount = computed(() => isPositions.value ? posFullFretCount.value : fretCount.value);
const neckViewBox = computed(() => isPositions.value ? posViewBox.value : null);

// Positions header shows its own controls; only render them when there are ≥2 windows.
const showPositionControls = computed(() => isPositions.value && windows.value.length > 1);
</script>

<template>
    <div class="sbn-fretboard-vue-wrap">
        <!-- ── Positions mode ── -->
        <template v-if="isPositions">
            <!-- "Position X" centered above the board, with key when transposed -->
            <div class="sbn-fv-pos-label">Position {{ activeWindowIdx + 1 }}<span v-if="transposeSemitones && data.transposeKey"> — {{ data.transposeKey }}</span></div>
            <!-- Arrow left | board | arrow right -->
            <div class="sbn-fv-pos-row">
                <button
                    v-if="showPositionControls"
                    type="button"
                    class="sbn-fv-arrow-btn"
                    :disabled="activeWindowIdx === 0"
                    @click="stepWindow(-1)"
                    aria-label="Previous position"
                >&#x2039;</button>
                <div
                    class="sbn-fv-board sbn-fv-board--positions"
                    style="cursor: pointer; touch-action: pan-y; user-select: none;"
                    @pointerdown="onBoardPointerDown"
                    @pointermove="onBoardPointerMove"
                    @pointerup="onBoardPointerUp"
                >
                    <FretboardNeck
                        :dots="neckDots"
                        :start-fret="neckStartFret"
                        :fret-count="neckFretCount"
                        :view-box="neckViewBox"
                        :show-nut="showNut"
                        :open-strings="openStringMarkers"
                        :rh-fingers="rhFingers"
                    />
                </div>
                <button
                    v-if="showPositionControls"
                    type="button"
                    class="sbn-fv-arrow-btn"
                    :disabled="activeWindowIdx === windows.length - 1"
                    @click="stepWindow(1)"
                    aria-label="Next position"
                >&#x203a;</button>
            </div>
        </template>
        <!-- ── Chord / scale / sequence header (unchanged) ── -->
        <div v-else class="sbn-fv-header">
            <span class="sbn-fv-label">{{ currentVoicing.label ?? data.title ?? '' }}</span>
            <!-- Step controls for multi-frame voicings -->
            <template v-if="isMultiFrame">
                <div class="sbn-fv-steps">
                    <button
                        type="button"
                        class="sbn-fv-step-btn"
                        :disabled="activeFrame === 0"
                        @click="stepFrame(-1)"
                    >&#x2039;</button>
                    <span class="sbn-fv-counter">{{ activeFrame + 1 }}/{{ voicings.length }}</span>
                    <button
                        type="button"
                        class="sbn-fv-step-btn"
                        :disabled="activeFrame === voicings.length - 1"
                        @click="stepFrame(1)"
                    >&#x203a;</button>
                </div>
            </template>
            <!-- Guide-tone toggle -->
            <button
                v-if="data.show_guide_tones"
                type="button"
                class="sbn-fv-gt-btn"
                :class="{ 'is-active': guideTonesActive }"
                @click="guideTonesActive = !guideTonesActive"
            >GT</button>
        </div>
        <!-- SVG neck for chord / scale / sequence modes -->
        <div v-if="!isPositions" class="sbn-fv-board">
            <FretboardNeck
                :dots="neckDots"
                :start-fret="neckStartFret"
                :fret-count="neckFretCount"
                :view-box="neckViewBox"
                :show-nut="showNut"
                :open-strings="openStringMarkers"
                :rh-fingers="rhFingers"
            />
        </div>
    </div>
</template>

<style scoped>
.sbn-fretboard-vue-wrap {
    display: flex;
    flex-direction: column;
    padding: 8px 8%;
    border: 1px solid var(--clr-border);
    border-radius: var(--radius, 8px);
}
.sbn-fv-header {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 4px 2px;
}
.sbn-fv-label {
    flex: 1;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.sbn-fv-steps {
    display: flex;
    align-items: center;
    gap: 6px;
}
.sbn-fv-step-btn {
    width: 22px;
    height: 22px;
    border: 1px solid var(--clr-border);
    background: var(--clr-bg);
    border-radius: 4px;
    cursor: pointer;
    font-size: 13px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--clr-text);
    transition: background 0.12s;
}
.sbn-fv-step-btn:hover:not(:disabled) { background: var(--clr-border); }
.sbn-fv-step-btn:disabled { opacity: 0.35; cursor: default; }
.sbn-fv-counter {
    font-size: 11px;
    color: var(--clr-text-dim);
    min-width: 32px;
    text-align: center;
}
.sbn-fv-gt-btn {
    background: none;
    border: 1.5px solid var(--clr-border);
    border-radius: 4px;
    color: var(--clr-text-dim);
    cursor: pointer;
    font-size: 10px;
    font-weight: 800;
    padding: 2px 6px;
    transition: all 0.15s;
    letter-spacing: 0.5px;
}
.sbn-fv-gt-btn:hover {
    border-color: var(--clr-text-dim);
    color: var(--clr-text);
}
.sbn-fv-gt-btn.is-active {
    background: rgba(var(--clr-accent-rgb, 232,93,59), 0.1);
    border-color: var(--clr-accent);
    color: var(--clr-accent);
}
.sbn-fv-board {
    min-width: 0;
    /* Match the display width of the positions board (full width minus 2×arrow+gap).
       This keeps scale/chord diagrams the same physical size as positions mode. */
    max-width: calc(100% - 2 * (28px + 4px));
    margin: 0 auto;
}
.sbn-fv-board--positions {
    transform: scale(0.72);
    transform-origin: top center;
    margin-bottom: -13%;
}

/* ── Positions-mode layout ── */
.sbn-fv-pos-label {
    text-align: center;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: var(--clr-text-dim);
    padding: 2px 0 4px;
}
.sbn-fv-pos-row {
    display: flex;
    align-items: center;
    gap: 4px;
}
.sbn-fv-pos-row .sbn-fv-board--positions {
    flex: 1;
    min-width: 0;
}
.sbn-fv-arrow-btn {
    flex: 0 0 auto;
    width: 28px;
    height: 28px;
    border: 1px solid var(--clr-border);
    background: var(--clr-bg);
    border-radius: 6px;
    cursor: pointer;
    font-size: 20px;
    line-height: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--clr-text);
    transition: background 0.12s;
}
.sbn-fv-arrow-btn:hover:not(:disabled) { background: var(--clr-border); }
.sbn-fv-arrow-btn:disabled { opacity: 0.3; cursor: default; }
</style>
