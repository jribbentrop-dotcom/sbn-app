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
    display_mode: 'chord' | 'scale' | 'sequence' | 'positions';
    fret_count: number;
    start_fret: number;
    show_guide_tones: boolean;
    show_rh_fingers: boolean;
    voicings: FretboardVoicing[];
    windows?: FretWindow[];
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

const posDots = computed((): ScaleDot[] => {
    if (!isPositions.value) return [];
    return props.data.voicings?.[0]?.dots ?? [];
});

const windows = computed((): FretWindow[] => {
    if (!isPositions.value) return [];
    const ws = props.data.windows ?? [];
    return ws.filter(w => w && Number.isFinite(w.from) && Number.isFinite(w.to));
});

const activeWindowIdx = ref(0);
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

// ── Fixed on-screen size, independent of content ──────────────────────────────
// The rendered SVG height = containerWidth × (viewBoxHeight / viewBoxWidth). To
// keep the board a STATIC size regardless of how many dots/frets exist, the
// excerpt viewBox must have a FIXED aspect ratio. We crop tightly to the neck's
// vertical extent (POS_VB_Y..POS_VB_H) and derive the excerpt width from a fixed
// target aspect — never from content. `posExcerptVW` is therefore constant.
//
// POS_ASPECT is the width:height ratio of the visible excerpt. Higher = wider &
// shorter (closer to a single-position chord neck); lower = taller. ~2.6 is the
// middle-ground between the small single-position look and the previous huge one.
const POS_ASPECT = 2.6;
const POS_VB_Y = PAD_T - 6;                       // top of neck surface
const POS_VB_H = (VB_H - PAD_B + 22) - POS_VB_Y;  // strings + fret-number row
const posExcerptVW = computed(() => POS_VB_H * POS_ASPECT);

// Max left-edge so the excerpt never pans past the last fret wire.
const posMaxSmoothX = computed(() => {
    const g = posGeom.value;
    return Math.max(g.fretEdgeX[0], g.fretEdgeX[posFullFretCount.value] - posExcerptVW.value);
});

// Target left-edge x for the active window: pan so the window is roughly centered
// within the excerpt, then clamp to [NECK_L, posMaxSmoothX].
function windowTargetX(w: FretWindow | null): number {
    const g = posGeom.value;
    if (!w) return g.fretEdgeX[0];
    const winLeft = g.fretEdgeX[Math.max(0, w.from - 1)];
    const winWidth = g.fretEdgeX[w.to] - winLeft;
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

// Initialise camera on window 0 (no slide-in on mount).
smoothX.value = windowTargetX(activeWindow.value);

watch(activeWindow, (w) => animateX(windowTargetX(w)));

const posViewBox = computed(() =>
    `${smoothX.value} ${POS_VB_Y} ${posExcerptVW.value} ${POS_VB_H}`);

// ── Autoplay stepper (visual-only setInterval — no audio engine) ──────────────
const isPlaying = ref(false);
let playTimer: ReturnType<typeof setInterval> | null = null;
const STEP_MS = 1600;

function stopPlay() {
    isPlaying.value = false;
    if (playTimer !== null) { clearInterval(playTimer); playTimer = null; }
}
function startPlay() {
    if (windows.value.length < 2) return;
    isPlaying.value = true;
    playTimer = setInterval(() => {
        activeWindowIdx.value = (activeWindowIdx.value + 1) % windows.value.length;
    }, STEP_MS);
}
function togglePlay() { isPlaying.value ? stopPlay() : startPlay(); }

function goToWindow(idx: number) {
    if (idx < 0 || idx >= windows.value.length) return;
    activeWindowIdx.value = idx;
}
function stepWindow(dir: -1 | 1) {
    stopPlay();
    const next = activeWindowIdx.value + dir;
    if (next >= 0 && next < windows.value.length) activeWindowIdx.value = next;
}

onBeforeUnmount(() => {
    if (rafId !== null) cancelAnimationFrame(rafId);
    stopPlay();
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
        // Scale mode: expand dots[] — multiple per string allowed, f:0 skipped
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
const activeWindowLabel = computed(() =>
    activeWindow.value?.label ?? (activeWindow.value ? `Frets ${activeWindow.value.from}–${activeWindow.value.to}` : ''));
</script>

<template>
    <div class="sbn-fretboard-vue-wrap">
        <!-- ── Positions mode header: label + play/pause + prev/next ── -->
        <template v-if="isPositions">
            <div class="sbn-fv-header">
                <button
                    v-if="showPositionControls"
                    type="button"
                    class="sbn-fv-play-btn"
                    :class="{ 'is-playing': isPlaying }"
                    :aria-label="isPlaying ? 'Pause' : 'Play'"
                    @click="togglePlay"
                >
                    <svg v-if="!isPlaying" viewBox="0 0 12 12" width="11" height="11" aria-hidden="true">
                        <path d="M3 2l7 4-7 4z" fill="currentColor" />
                    </svg>
                    <svg v-else viewBox="0 0 12 12" width="11" height="11" aria-hidden="true">
                        <rect x="3" y="2.5" width="2.4" height="7" fill="currentColor" />
                        <rect x="6.8" y="2.5" width="2.4" height="7" fill="currentColor" />
                    </svg>
                </button>
                <span class="sbn-fv-label">{{ activeWindowLabel }}</span>
                <template v-if="showPositionControls">
                    <div class="sbn-fv-steps">
                        <button
                            type="button"
                            class="sbn-fv-step-btn"
                            :disabled="activeWindowIdx === 0"
                            @click="stepWindow(-1)"
                        >&#x2039;</button>
                        <span class="sbn-fv-counter">{{ activeWindowIdx + 1 }}/{{ windows.length }}</span>
                        <button
                            type="button"
                            class="sbn-fv-step-btn"
                            :disabled="activeWindowIdx === windows.length - 1"
                            @click="stepWindow(1)"
                        >&#x203a;</button>
                    </div>
                </template>
            </div>
            <div v-if="showPositionControls" class="sbn-fv-slider-row">
                <input
                    type="range"
                    class="sbn-fv-slider"
                    min="0"
                    :max="windows.length - 1"
                    step="1"
                    :value="activeWindowIdx"
                    @input="stopPlay(); goToWindow(Number(($event.target as HTMLInputElement).value))"
                />
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
        <!-- SVG neck via FretboardNeck -->
        <div class="sbn-fv-board">
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
}
.sbn-fv-header {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 4px 2px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: var(--clr-text-dim);
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
}

/* ── Positions-mode controls ── */
.sbn-fv-play-btn {
    width: 22px;
    height: 22px;
    flex: 0 0 auto;
    border: none;
    border-radius: 50%;
    background: var(--clr-accent, #e85d3b);
    color: #fff;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0;
    transition: filter 0.15s, transform 0.1s;
}
.sbn-fv-play-btn:hover { filter: brightness(1.08); }
.sbn-fv-play-btn:active { transform: scale(0.94); }
.sbn-fv-play-btn.is-playing { background: var(--clr-text-dim, #6b7280); }
.sbn-fv-slider-row {
    padding: 2px 2px 4px;
}
.sbn-fv-slider {
    width: 100%;
    height: 4px;
    -webkit-appearance: none;
    appearance: none;
    background: var(--clr-border, #d4d4d8);
    border-radius: 3px;
    cursor: pointer;
    outline: none;
}
.sbn-fv-slider::-webkit-slider-thumb {
    -webkit-appearance: none;
    appearance: none;
    width: 13px;
    height: 13px;
    border-radius: 50%;
    background: var(--clr-accent, #e85d3b);
    border: 2px solid var(--clr-bg, #fff);
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.2);
}
.sbn-fv-slider::-moz-range-thumb {
    width: 13px;
    height: 13px;
    border-radius: 50%;
    background: var(--clr-accent, #e85d3b);
    border: 2px solid var(--clr-bg, #fff);
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.2);
}
</style>
