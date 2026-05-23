<template>
    <div class="sbn-tab-measure"
         :class="{
             'sbn-tab-measure--overfill':  isOverfilled,
             'sbn-tab-measure--playing':   isPlayingMeasure,
             'is-tap-target':              isTapTarget,     // D2: tap-to-mark cursor
         }"
         :style="measureStyle"
         :data-measure="measure.index"
         style="position:relative"
         @contextmenu.prevent.stop="onMeasureContextMenu"
    >

        <!-- Chord names — always rendered to keep vertical alignment consistent.
             Shows a clickable "?" placeholder when the bar has no chords. -->
        <div class="sbn-tab-chord-bar">
            <template v-if="chordNames.length">
                <span
                    v-for="(name, ci) in chordNames"
                    :key="ci"
                    class="sbn-tab-chord-name-wrap"
                    :style="getChordStyle(ci)"
                >
                    <input
                        v-if="renamingCi === ci"
                        :ref="el => { renameInputEl = el }"
                        class="sbn-ve-chord-name-input sbn-tab-chord-rename-input"
                        :value="renameValue"
                        @input="renameValue = $event.target.value"
                        @keydown.enter.prevent="commitRename"
                        @keydown.escape.prevent="cancelRename"
                        @blur="commitRename"
                        @click.stop
                        @pointerdown.stop
                        @contextmenu.stop
                    />
                    <span
                        v-else
                        class="sbn-tab-chord-name sbn-chord-symbol"
                        :class="{
                            'sbn-tab-chord-name--clickable': !readOnly || allowChordClick,
                            'is-active': activeChordIndex === ci
                        }"
                        :title="readOnly ? name : 'Click: voicing · Double-click: rename'"
                        v-html="formatChord(name)"
                        @click.stop="onChordNameClick(name, ci)"
                        @dblclick.stop="onChordNameDblClick(ci)"
                        @contextmenu.prevent.stop="onChordNameContextMenu($event, name, ci)"
                    ></span>
                </span>
            </template>
            <template v-else-if="!readOnly">
                <!-- Empty bar placeholder — click opens the chord name picker.
                     The chord bar has a fixed height so this never causes offset. -->
                <span class="sbn-tab-chord-name-wrap sbn-tab-chord-empty-wrap"
                      title="Click to assign a chord"
                      @click.stop="onEmptyChordClick($event, 0)">
                    <span class="sbn-tab-chord-name sbn-tab-chord-placeholder">?</span>
                </span>
            </template>
        </div>

        <SyncPointBadge v-if="syncPoint" :marker-index="syncPoint.markerIndex" :video-time="syncPoint.videoTime" :measure-index="measure.index" :marks="syncPoint.marks" context="tab" />

        <svg
            ref="svgEl"
            class="sbn-tab-svg"
            :viewBox="`0 0 ${effectiveWidth} ${LAYOUT.tabHeight}`"
            preserveAspectRatio="xMinYMid meet"
            style="overflow:visible"
        >
            <!-- Overfill background tint -->
            <rect v-if="isOverfilled"
                  x="0" y="0"
                  :width="effectiveWidth" :height="LAYOUT.tabHeight"
                  class="sbn-tab-overfill-bg"/>

            <!-- Static SVG content: notes, stems, beams, ties, rests -->
            <g v-html="svgContent"></g>

            <!-- Metronome column: same geometry as TabCursor's selection column -->
            <rect
                v-if="metronomeBeatX !== null"
                :x="metronomeBeatX - METRO_HALF_W"
                :y="LAYOUT.stringAreaTop - 4"
                :width="METRO_HALF_W * 2"
                :height="LAYOUT.stringSpacing * 5 + 8"
                rx="3"
                class="sbn-tab-metronome-col"
            />

            <!-- Cursor overlay: navigation ring + click hit targets + pending digit -->
            <TabCursor
                v-if="cursor"
                :measure="measure"
                :cursor="cursor"
                :is-first-of-section="isFirstOfSection"
                :effective-width="effectiveWidth"
                :pending-digit="pendingDigit"
                :selected-events="selectedEvents"
                :is-playing="isPlaying"
                :read-only="readOnly"
                @mousedown-event="onCursorMousedownEvent"
                @mouseenter-event="onCursorMouseenterEvent"
                @mousedown-rest="onCursorMousedownRest"
                @mouseenter-rest="onCursorMouseenterRest"
            />
        </svg>
    </div>
</template>

<script setup>
import { computed, ref, inject, watch, nextTick } from 'vue';
import { LAYOUT } from '../utils/constants.js';
import {
    renderFlag, renderRest, renderBeams, renderTies,
    renderRepeatStart, renderRepeatEnd,
} from '../utils/svgHelpers.js';
import { stringY, isDotted } from '../utils/constants.js';
import TabCursor from './TabCursor.vue';
import SyncPointBadge from './SyncPointBadge.vue';

const props = defineProps({
    measure: {
        type: Object,
        required: true,
    },
    isFirstOfSection: {
        type: Boolean,
        default: false,
    },
    ticksPerMeasure: {
        type: Number,
        default: 1920,
    },
    /**
     * Phase 7d: the next measure in sequence (for cross-measure tie rendering).
     * Null if this is the last measure.
     */
    nextMeasure: {
        type: Object,
        default: null,
    },
    /**
     * Whether the next measure is the first in its section (affects X padding).
     */
    isNextFirstOfSection: {
        type: Boolean,
        default: false,
    },
    /**
     * Chord names for this measure (array of strings, e.g. ['Dm7', 'G7']).
     */
    chordNames: {
        type: Array,
        default: () => [],
    },
    cursor: {
        type: Object,
        default: null,
    },
    /**
     * Pending fret digit from useNoteInput — forwarded to TabCursor.
     */
    pendingDigit: {
        type: String,
        default: null,
    },
    /** Phase 7g: Set<eventId> of Shift+Arrow selected events, passed to TabCursor. */
    selectedEvents: {
        type: Object,
        default: () => new Set(),
    },
    /**
     * Number of bars in this row, used to calculate dynamic width.
     */
    barsPerRow: {
        type: Number,
        default: 4,
    },
    allowChordClick: {
        type: Boolean,
        default: false,
    },
    /**
     * Phase 9b: read-only mode — disables chord editing, context menus,
     * and other editor-only affordances while keeping visual rendering
     * and measure selection / click-to-seek intact.
     */
    readOnly: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits([
    'cursor-mousedown-event', 'cursor-mouseenter-event',
    'cursor-mousedown-rest', 'cursor-mouseenter-rest',
    'chord-click', 'chord-identify',
    'chord-context-menu',    // Phase 2b — right-click on chord name
    'chord-name-needed',     // Phase 2b — click on ? placeholder in empty bar
    'measure-context-menu',  // Phase 2b — right-click on measure background
]);

// Use the global sbnFormatChord if available (loaded via public/js/sbn-chord-name.js),
// otherwise fall back to a minimal inline formatter.
function formatChord(name) {
    if (!name) return '';
    if (typeof window !== 'undefined' && typeof window.sbnFormatChord === 'function') {
        return window.sbnFormatChord(name);
    }
    // Minimal fallback: root + superscript quality
    const m = name.match(/^([A-G][#b♯♭]?)(.*)$/);
    if (!m) return name;
    const root = m[1].replace('#', '♯').replace(/b(?=[^0-9]|$)/, '♭');
    let qual = m[2], bass = '';
    const si = qual.indexOf('/');
    if (si >= 0) { bass = '/' + qual.slice(si + 1).replace('#', '♯').replace('b', '♭'); qual = qual.slice(0, si); }
    if (qual.toLowerCase() === 'maj') qual = '';
    if (qual.toLowerCase() === 'min') qual = 'm';
    const ext = qual.replace(/#/g, '♯').replace(/b(?=[0-9])/g, '♭');
    return root + (ext ? `<sup>${ext}</sup>` : '') + bass;
}

// Phase 7d: overfill detection — use tick-span, not raw sum.
// Tuplets compress musical time: 3 triplet eighths = 1 beat, but raw ticks sum
// to 480 which equals one beat exactly. Raw sum works for normal notes but
// fails for tuplets whose ticks don't sum to standard durations.
// Tick-span (last event's end position) is always correct for imported scores.
const isOverfilled = computed(() => {
    const v1 = (props.measure.events || [])
        .filter(e => (e.voice || 1) === 1)
        .sort((a, b) => a.tick - b.tick);
    if (!v1.length) return false;
    // Use measure.actualTicks if set (after an edit via repositionMeasure)
    if (props.measure.actualTicks != null) {
        return props.measure.actualTicks > props.ticksPerMeasure + 2;
    }
    // Compute tick-span using inter-event gaps where possible.
    // last.ticks may be the nominal duration (e.g. 240 for an eighth) rather than
    // the actual triplet duration (160), causing false overflow on triplet measures.
    // For each event except the last, use the gap to the next event as the real duration.
    // For the last event, fall back to last.ticks but clamp at ticksPerMeasure - tickInMeasure.
    let span = 0;
    for (let i = 0; i < v1.length; i++) {
        const ev = v1[i];
        const next = v1[i + 1];
        let evEnd;
        if (next) {
            evEnd = next.tickInMeasure;
        } else {
            evEnd = Math.min(ev.tickInMeasure + ev.ticks, props.ticksPerMeasure);
        }
        if (evEnd > span) span = evEnd;
    }
    return span > props.ticksPerMeasure + 2;
});

// Dynamic layout widths
const baseWidth = computed(() => {
    // Calculate the total available row width using the default layout constants
    const standardBars = LAYOUT.measuresPerRow || 4;
    const standardTotalWidth = LAYOUT.measureWidth * standardBars;
    
    // Distribute that total width perfectly across the intended number of bars in this row
    return standardTotalWidth / Math.max(1, props.barsPerRow);
});

const widthRatio = computed(() => {
    const actual = props.measure.actualTicks || 0;
    if (actual <= props.ticksPerMeasure) return 1;
    return actual / props.ticksPerMeasure;
});

const effectiveWidth = computed(() => baseWidth.value * widthRatio.value);

const measureStyle = computed(() => {
    const pct = 100 / Math.max(1, props.barsPerRow);
    if (widthRatio.value <= 1) return { flex: `0 0 ${pct}%` };
    return { flex: `0 0 ${pct * widthRatio.value}%` };
});

// ── Playback highlighting ─────────────────────────────
const svgEl               = ref(null);
const playingMeasureIndex  = inject('playingMeasureIndex', null);
const transportBeat        = inject('transportBeat',       null);
const beatsPerMeasureRef   = inject('beatsPerMeasureRef',  null);
const transportPlaying     = inject('transportPlaying',    null);
const tapCursor            = inject('tapCursor', null);
const videoSyncMap         = inject('videoSyncMap', null);
const inlineRenameTarget   = inject('inlineRenameTarget', null);
const triggerInlineRename  = inject('triggerInlineRename', null);
const setChordNameFn       = inject('setChordName', null);

// ── Inline chord rename ───────────────────────────────────────────────────────
let renameInputEl    = null;  // set via callback ref — avoids v-for array issue
const renamingCi     = ref(null);   // chord index currently being renamed, or null
const renameValue    = ref('');

watch(inlineRenameTarget, (target) => {
    if (target && target.source === 'tab' && target.gi === props.measure.index) {
        renameValue.value = props.chordNames[target.ci] || '';
        renamingCi.value  = target.ci;
        nextTick(() => { renameInputEl?.focus(); renameInputEl?.select(); });
    } else {
        renamingCi.value = null;
    }
});

function commitRename() {
    if (renamingCi.value === null) return;
    const ci      = renamingCi.value;
    const newName = renameValue.value.trim();
    renamingCi.value = null;
    if (newName !== (props.chordNames[ci] || '')) {
        setChordNameFn?.(props.measure.index, ci, newName);
    }
}

function cancelRename() {
    renamingCi.value = null;
}
const isPlaying           = computed(() => transportPlaying?.value ?? false);

const syncMarks = computed(() => videoSyncMap?.value?.get(props.measure.index) ?? null);
const syncPoint = computed(() => {
    const marks = syncMarks.value;
    if (!marks?.length) return null;
    return { markerIndex: marks[0].mappingIdx, videoTime: marks[0].videoTime, marks };
});

// D2: Tap target highlight — show when this measure is the current tap cursor
const isTapTarget = computed(() => tapCursor?.value === props.measure.index);

// Measure-level highlight: driven by beat position so it works from either view.
const isPlayingMeasure = computed(() =>
    playingMeasureIndex?.value === props.measure.index
);

// The voice-1 event active at the current playback beat in this measure.
function _eventAtTick(tickInMeasure) {
    const v1 = (props.measure.events || [])
        .filter(e => (e.voice || 1) === 1)
        .sort((a, b) => a.tick - b.tick);
    if (!v1.length) return null;
    let found = v1[0];
    for (const ev of v1) {
        if ((ev.tickInMeasure ?? 0) <= tickInMeasure + 1) found = ev;
        else break;
    }
    return found ?? null;
}

// Metronome column: strict quarter-beat grid, always proportional.
// xPos for beat b = b/bpm, same mapping getXm uses (tickInMeasure/tpm).
const METRO_HALF_W = 9;
const metronomeBeatX = computed(() => {
    if (!isPlayingMeasure.value) return null;
    const bpm      = beatsPerMeasureRef?.value ?? 4;
    const beat     = transportBeat?.value ?? 0;
    const bSnapped = Math.floor(((beat % bpm) + bpm) % bpm);
    return getXm(bSnapped / bpm);
});

// Red note highlight: continuous (follows exact beat for responsive feel).
const playingEventId = computed(() => {
    if (!isPlayingMeasure.value) return null;
    const bpm  = beatsPerMeasureRef?.value ?? 4;
    const beat = transportBeat?.value ?? 0;
    const b    = ((beat % bpm) + bpm) % bpm;
    const tpm  = props.ticksPerMeasure;
    return _eventAtTick(b / bpm * tpm)?.id ?? null;
});

const activeChordIndex = computed(() => {
    const total = props.chordNames.length;
    if (!total) return -1;
    
    // 1. Playback highlighting takes priority
    if (isPlayingMeasure.value) {
        if (total === 1) return 0;
        const bpm = beatsPerMeasureRef?.value ?? 4;
        const beat = transportBeat?.value ?? 0;
        const beatInMeasure = ((beat % bpm) + bpm) % bpm;
        
        const offsets = props.measure.chordOffsets || [];
        const durations = props.measure.chordDurations || [];
        
        for (let i = 0; i < total; i++) {
            const slotStart = offsets[i] != null ? offsets[i] : i * (bpm / total);
            const duration = durations[i] != null ? durations[i] : (bpm / total);
            const slotEnd = slotStart + duration;
            
            if (beatInMeasure >= slotStart - 0.05 && beatInMeasure < slotEnd - 0.05) {
                return i;
            }
        }
        const idx = Math.floor(beatInMeasure / (bpm / total));
        return Math.min(idx, total - 1);
    }
    
    // 2. Cursor positioning highlighting (when not playing)
    if (props.cursor && props.cursor.measureIndex === props.measure.index) {
        if (total === 1) return 0;
        
        const v1 = (props.measure.events || [])
            .filter(e => (e.voice || 1) === 1)
            .sort((a, b) => a.tick - b.tick);
            
        const cursorEv = v1[props.cursor.eventIndex];
        if (cursorEv) {
            const tick = cursorEv.tickInMeasure || 0;
            const tpm = props.ticksPerMeasure || 1920;
            const bpm = beatsPerMeasureRef?.value ?? 4;
            const beatInMeasure = (tick / tpm) * bpm;
            
            const offsets = props.measure.chordOffsets || [];
            const durations = props.measure.chordDurations || [];
            
            for (let i = 0; i < total; i++) {
                const slotStart = offsets[i] != null ? offsets[i] : i * (bpm / total);
                const duration = durations[i] != null ? durations[i] : (bpm / total);
                const slotEnd = slotStart + duration;
                
                if (beatInMeasure >= slotStart - 0.05 && beatInMeasure < slotEnd - 0.05) {
                    return i;
                }
            }
            const idx = Math.floor(beatInMeasure / (bpm / total));
            return Math.min(idx, total - 1);
        }
    }
    
    return -1;
});

// Apply/remove red highlight on the playing event's note texts.
let _lastPlayingEventId = null;
watch(playingEventId, (newId, oldId) => {
    if (!svgEl.value) return;
    if (oldId && oldId !== newId) {
        svgEl.value.querySelectorAll(`[data-event-id="${oldId}"]`)
            .forEach(el => el.classList.remove('sbn-beat-active'));
    }
    if (newId) {
        svgEl.value.querySelectorAll(`[data-event-id="${newId}"]`)
            .forEach(el => el.classList.add('sbn-beat-active'));
    }
    _lastPlayingEventId = newId;
});

// Clear highlight when measure stops playing.
watch(isPlayingMeasure, (playing) => {
    if (!playing && svgEl.value && _lastPlayingEventId) {
        svgEl.value.querySelectorAll(`[data-event-id="${_lastPlayingEventId}"]`)
            .forEach(el => el.classList.remove('sbn-beat-active'));
        _lastPlayingEventId = null;
    }
});

function getXm(xPos) {
    // xPos is already relative to effectiveTicks (0..1 range),
    // so we map it to the effective pixel width.
    const w = effectiveWidth.value;
    const xL = props.isFirstOfSection ? LAYOUT.xPaddingFirst : LAYOUT.xPadding;
    const xRng = w - xL - LAYOUT.xPadding;
    return xL + xPos * xRng;
}

function getChordStyle(ci) {
    const total = props.chordNames.length;
    let xPos = null;
    
    // 1. Try explicit chordOffsets
    if (props.measure.chordOffsets && props.measure.chordOffsets[ci] != null) {
        const offset = props.measure.chordOffsets[ci];
        const effectiveTicks = Math.max(props.measure.actualTicks || 0, props.ticksPerMeasure);
        xPos = (offset * 480) / effectiveTicks;
    } 
    
    // 2. Try binding to chordal event inside expected slot
    if (xPos === null) {
        const slotTicks = props.ticksPerMeasure / total;
        const startTick = ci * slotTicks;
        const endTick = (ci + 1) * slotTicks;
        
        const chordEvent = (props.measure.events || []).find(ev =>
            !ev.isRest &&
            ev.notes.length >= 3 &&
            ev.tickInMeasure >= startTick &&
            ev.tickInMeasure < endTick
        );
        
        if (chordEvent) {
            xPos = chordEvent.xPos;
        }
    }
    
    // 3. Ultimate fallback: strictly even division
    if (xPos === null) {
        xPos = ci / total;
    }
    
    const xPx = getXm(xPos);
    const w = effectiveWidth.value || 160;
    const pct = (xPx / w) * 100;
    
    let transform = 'translateX(-50%)';
    if (xPos < 0.8) {
        transform = 'translateX(-20%)';
    } else if (xPos > 0.92) {
        transform = 'translateX(-100%)';
    }
    
    return {
        position: 'absolute',
        left: `${pct}%`,
        transform: transform,
    };
}

// For cross-measure ties: compute X in the NEXT measure's coordinate space
function getNextXm(xPos) {
    const nm = props.nextMeasure;
    if (!nm) {
        const xL = props.isNextFirstOfSection ? LAYOUT.xPaddingFirst : LAYOUT.xPadding;
        const xRng = baseWidth.value - xL - LAYOUT.xPadding;
        return xL + xPos * xRng;
    }
    const nextActual = nm.actualTicks || 0;
    const nextRatio = nextActual > props.ticksPerMeasure ? nextActual / props.ticksPerMeasure : 1;
    const nextW = baseWidth.value * nextRatio;
    const xL = props.isNextFirstOfSection ? LAYOUT.xPaddingFirst : LAYOUT.xPadding;
    const xRng = nextW - xL - LAYOUT.xPadding;
    return xL + xPos * xRng;
}

const nextEffectiveWidth = computed(() => {
    if (!props.nextMeasure) return baseWidth.value;
    const nextActual = props.nextMeasure.actualTicks || 0;
    const nextRatio = nextActual > props.ticksPerMeasure ? nextActual / props.ticksPerMeasure : 1;
    return baseWidth.value * nextRatio;
});

const svgContent = computed(() => {
    const m = props.measure;
    const events = m.events || [];
    const sT = LAYOUT.stringAreaTop;
    const sB = LAYOUT.bottomStringY;
    const sH = sB - sT;
    const w = effectiveWidth.value;

    let html = '';

    // Volta bracket (rendered inside the measure SVG, above the strings)
    if (m.volta) {
        const vy = sT - 35;   // Pushed up high to clear chord symbols (SVG is overflow:visible)
        const vx1 = -2;
        const vx2 = m.voltaEnd ? w - 2 : w;
        // Left vertical drop (only at volta start)
        if (m.voltaStart) {
            html += `<line x1="${vx1}" y1="${vy}" x2="${vx1}" y2="${sT - 20}" stroke="#000" stroke-width="0.8" stroke-linecap="square" />`;
        }
        // Horizontal line
        html += `<line x1="${vx1}" y1="${vy}" x2="${vx2 - 3}" y2="${vy}" stroke="#000" stroke-width="0.8" stroke-linecap="square" />`;
        // Right vertical drop (only at volta end)
        if (m.voltaEnd) {
            html += `<line x1="${vx2 - 3}" y1="${vy}" x2="${vx2 - 3}" y2="${sT - 20}" stroke="#000" stroke-width="0.8" stroke-linecap="square"  />`;
        }
        // Label (only at start)
        if (m.voltaStart) {
            html += `<text x="${vx1 + 3}" y="${vy + 11}" font-family="sans" font-weight="900" font-size="12" fill="#000000">${m.volta.text || m.volta.number + '.'}</text>`;
        }
    }

    // String lines (full effective width)
    for (let s = 0; s < LAYOUT.stringCount; s++) {
        const y = LAYOUT.stringAreaTop + s * LAYOUT.stringSpacing;
        html += `<line x1="0" y1="${y}" x2="${w}" y2="${y}" class="sbn-tab-string-line"/>`;
    }

    if (m.repeatStart) html += renderRepeatStart(sT, sB, sH);
    html += renderBeams(events, getXm);
    html += renderTies(events, m.index, getXm, w, props.nextMeasure ? getNextXm : null, nextEffectiveWidth.value);

    events.forEach(ev => {
        if (!ev.isRest) return;
        html += renderRest(getXm(ev.xPos), ev.ticks, ev.voice, ev.id);
    });

    events.forEach(ev => {
        if (ev.isRest || !ev.notes.length) return;

        const vc = ev.voice === 2 ? ' voice-2' : '';

        ev.notes.forEach(note => {
            if (note.string === null || note.string === undefined || note.fret === null) return;
            const x = getXm(ev.xPos);
            const y = stringY(note.string);
            html += `<text x="${x}" y="${y}" dominant-baseline="central" text-anchor="middle" font-size="${LAYOUT.noteFontSize}" class="sbn-tab-note-text" data-measure="${m.index}" data-event-id="${ev.id}" data-string="${note.string}">${note.fret}</text>`;
        });

        if (ev.stemDir) {
            const x = getXm(ev.xPos);
            let sY1, sY2;
            if (ev.stemDir === 'up') {
                sY1 = LAYOUT.topStringY - LAYOUT.stemBaseOffset;
                sY2 = sY1 - LAYOUT.stemLength;
            } else {
                sY1 = LAYOUT.bottomStringY + LAYOUT.stemBaseOffset;
                sY2 = sY1 + LAYOUT.stemLength;
            }
            // noBeamBar events (quarter triplets): beamWith set but beamStart/Continue/End
            // all false. renderBeams already drew their stems — skip here to avoid doubles.
            // Also skip any event in a beam group (beamWith set) — beams handle stems/flags.
            const handledByBeams = ev.beamWith || ev.beamStart || ev.beamContinue || ev.beamEnd || ev.noBeamBar;
            if (!handledByBeams) {
                html += `<line x1="${x}" y1="${sY1}" x2="${x}" y2="${sY2}" class="sbn-tab-stem${vc}"/>`;
                if (ev.flagCount > 0) {
                    html += renderFlag(x, sY2, ev.stemDir, ev.flagCount, ev.voice);
                }
            }
            if (isDotted(ev.ticks)) {
                // Dot sits beside the stem tip, shifted slightly toward the note
                const dY = ev.stemDir === 'up' ? sY2 + 4 : sY2 - 4;
                html += `<circle cx="${x + 4}" cy="${dY}" r="1.2" class="sbn-tab-dot${vc}"/>`;
            }
        }
    });

    if (m.repeatEnd) {
        html += renderRepeatEnd(w, sT, sB, sH);
    } else {
        html += `<line x1="${w - 0.5}" y1="${sT}" x2="${w - 0.5}" y2="${sB}" class="sbn-tab-bar-line"/>`;
    }

    return html;
});

let _chordClickTimer = null;

function onChordNameClick(chordName, chordIndex) {
    if (props.readOnly && !props.allowChordClick) return;
    // Delay to let dblclick cancel this before opening the voicing picker
    _chordClickTimer = setTimeout(() => {
        _chordClickTimer = null;
        emit('chord-click', { measureIndex: props.measure.index, chordIndex, chordName });
    }, 220);
}

function onChordNameDblClick(chordIndex) {
    if (props.readOnly) return;
    if (_chordClickTimer) { clearTimeout(_chordClickTimer); _chordClickTimer = null; }
    triggerInlineRename?.(props.measure.index, chordIndex);
}

/** Click on the "?" placeholder — bar has no chord yet, open name picker. */
function onEmptyChordClick(event, chordIndex) {
    emit('chord-name-needed', {
        measureIndex: props.measure.index,
        chordIndex,
        clientX: event.clientX,
        clientY: event.clientY,
    });
}

function onChordIdentifyClick(chordName, chordIndex) {
    emit('chord-identify', {
        measureIndex: props.measure.index,
        chordIndex,
        chordName,
    });
}

function onChordNameContextMenu(event, chordName, chordIndex) {
    if (props.readOnly) return;
    emit('chord-context-menu', {
        measureIndex: props.measure.index,
        chordIndex,
        chordName,
        event,
    });
}

function onCursorMousedownEvent({ eventId, stringIndex, event }) {
    emit('cursor-mousedown-event', {
        measureIndex: props.measure.index,
        eventId,
        stringIndex,
        event,
    });
}

function onCursorMouseenterEvent({ eventId, event }) {
    emit('cursor-mouseenter-event', {
        measureIndex: props.measure.index,
        eventId,
        event,
    });
}

function onCursorMousedownRest({ eventId, event }) {
    emit('cursor-mousedown-rest', {
        measureIndex: props.measure.index,
        eventId,
        event,
    });
}

function onCursorMouseenterRest({ eventId, event }) {
    emit('cursor-mouseenter-rest', {
        measureIndex: props.measure.index,
        eventId,
        event,
    });
}

// ── Measure-level selection / context menu (Phase 2b) ─────────

function onMeasureContextMenu(event) {
    if (props.readOnly) return;
    emit('measure-context-menu', { measureIndex: props.measure.index, event });
}
</script>
