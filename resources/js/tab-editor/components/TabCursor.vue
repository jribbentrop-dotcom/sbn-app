<template>
    <!--
        TabCursor — SVG overlay rendered on top of a single measure.

        Phase 7b: cursor ring, string/event highlight bands, click hit targets.
        Phase 7c: pending fret digit preview inside cursor ring.
    -->
    <g class="sbn-tab-cursor-layer">

        <!-- Gradient definitions for cursor circle fill -->
        <defs>
            <radialGradient id="sbn-cursor-gradient" cx="50%" cy="50%" r="50%">
                <stop offset="0%"   stop-color="#f39c12" stop-opacity="0.22"/>
                <stop offset="100%" stop-color="#e74c3c" stop-opacity="0.08"/>
            </radialGradient>
            <radialGradient id="sbn-cursor-gradient-strong" cx="50%" cy="50%" r="50%">
                <stop offset="0%"   stop-color="#f39c12" stop-opacity="0.38"/>
                <stop offset="100%" stop-color="#e74c3c" stop-opacity="0.15"/>
            </radialGradient>
        </defs>

        <!-- Phase 7g: selection range frame — orange, one contiguous block -->
        <rect
            v-for="(range, idx) in selectedEventRanges"
            :key="'sel-rng-' + idx"
            :x="range.minX - CURSOR_HALF_W"
            :y="LAYOUT.stringAreaTop - 4"
            :width="(range.maxX - range.minX) + CURSOR_HALF_W * 2"
            :height="LAYOUT.stringSpacing * 5 + 8"
            class="sbn-cursor-sel-col"
            rx="3"
        />

        <!-- Cursor: filled circle with gradient at low opacity — hidden during playback -->
        <circle
            v-if="activeCursorVisible && activeEventX !== null && !isPlaying"
            :cx="activeEventX"
            :cy="activeCellY"
            :r="CURSOR_R"
            class="sbn-cursor-ring"
            :class="{
                'sbn-cursor-ring--input':   !readOnly && (cursor.mode === 'input' || pendingDigit !== null),
                'sbn-cursor-ring--pending': !readOnly && pendingDigit !== null,
                'sbn-cursor-ring--grace':   !readOnly && graceMode,
            }"
        />

        <!--
            Pending fret preview:
            When a single digit has been typed and we're waiting for a possible
            second digit, show it inside the cursor ring so the player gets
            immediate visual feedback.
        -->
        <text
            v-if="activeCursorVisible && activeEventX !== null && pendingDigit !== null && !isPlaying && !readOnly"
            :x="activeEventX"
            :y="activeCellY"
            dominant-baseline="central"
            text-anchor="middle"
            class="sbn-cursor-pending-digit"
        >{{ pendingDigit }}</text>

        <!-- Click targets: invisible rects over each event × string for click-to-select -->
        <template v-for="ev in voice1Events" :key="ev.id">

            <!-- Rest click target: full height of string area -->
            <rect
                v-if="ev.isRest"
                :data-event-id="ev.id"
                :x="getEvX(ev) - HIT_W / 2"
                :y="LAYOUT.stringAreaTop - 2"
                :width="HIT_W"
                :height="LAYOUT.stringSpacing * 5 + 4"
                class="sbn-cursor-hit"
                @mousedown.left.stop="onRestMousedown(ev, $event)"
                @mouseenter="onRestEnter(ev, $event)"
                @mouseleave="onRestLeave(ev, $event)"
            />

            <!-- Per-string hit zones for chord notes -->
            <rect
                v-else
                v-for="s in 6"
                :key="s"
                :data-event-id="ev.id"
                :x="getEvX(ev) - HIT_W / 2"
                :y="stringYForHit(s)"
                :width="HIT_W"
                :height="LAYOUT.stringSpacing"
                class="sbn-cursor-hit"
                @mousedown.left.stop="onNoteMousedown(ev, s, $event)"
                @mouseenter="onNoteEnter(ev, s, $event)"
                @mouseleave="onNoteLeave(ev, s, $event)"
            />

        </template>

    </g>
</template>

<script setup>
import { computed } from 'vue';
import { LAYOUT, stringY } from '../utils/constants.js';

// ── Cursor geometry constants ────────────────────────────────

const CURSOR_HALF_W = 9;
const CURSOR_R      = 7;    // circle radius — sits snugly around a fret number
const HIT_W         = 18;

// ── Props / Emits ────────────────────────────────────────────

const props = defineProps({
    measure: {
        type: Object,
        required: true,
    },
    cursor: {
        type: Object,
        required: true,
    },
    isFirstOfSection: {
        type: Boolean,
        default: false,
    },
    /**
     * Phase 7d: effective pixel width of this measure (may be > LAYOUT.measureWidth
     * when overfilled).
     */
    effectiveWidth: {
        type: Number,
        default: LAYOUT.measureWidth,
    },
    /**
     * Pending fret digit from useNoteInput (string '0'-'9', or null).
     * When set, shown as a preview inside the cursor ring.
     */
    pendingDigit: {
        type: String,
        default: null,
    },
    /** Grace-entry mode active — tints the cursor ring green. */
    graceMode: {
        type: Boolean,
        default: false,
    },
    /**
     * Phase 7g: Set<eventId> of events selected by Shift+Arrow.
     * Rendered as dimmed orange columns so the user sees the selection range.
     */
    selectedEvents: {
        type: Object,   // Set<string>
        default: () => new Set(),
    },
    /** Hide the cursor ring (but keep hit targets) during audio playback. */
    isPlaying: {
        type: Boolean,
        default: false,
    },
    /**
     * Phase 9b: read-only mode — hides input-mode styling and pending digit
     * while keeping selection hit targets and visual cursor intact.
     */
    readOnly: {
        type: Boolean,
        default: false,
    },
    /** Show the TAB clef + time signature (first measure of the entire piece). */
    showClef: {
        type: Boolean,
        default: false,
    },
    /**
     * Fraction of the full bar that precedes pickup content (0 for normal bars).
     * e.g. 0.75 for a 1-beat pickup in 4/4. Used to remap full-bar-space xPos
     * values into the narrowed pickup SVG coordinate space.
     */
    pickupXOffset: {
        type: Number,
        default: 0,
    },
});

const emit = defineEmits([
    'mousedown-event', 'mouseenter-event',
    'mousedown-rest', 'mouseenter-rest'
]);

// ── Helpers ──────────────────────────────────────────────────

const voice1Events = computed(() =>
    (props.measure.events || []).filter(e => (e.voice || 1) === 1)
);

function getEvX(ev) {
    const w = props.effectiveWidth;
    const xL = props.showClef ? LAYOUT.xPaddingClef
        : ((props.isFirstOfSection && props.measure.pickupBeats == null) || props.measure.repeatStart) ? LAYOUT.xPaddingFirst
        : LAYOUT.xPadding;
    const xRng = w - xL - LAYOUT.xPaddingRight;
    // ev.xPos is in full-bar space; remap into narrowed pickup SVG space
    const offset = props.pickupXOffset;
    const range  = 1 - offset || 1;
    const normalized = offset > 0 ? (ev.xPos - offset) / range : ev.xPos;
    // Add the grace-note horizontal shift (stashed on the event by TabMeasure's
    // precompute) so the cursor ring / hit targets track the rendered note.
    return xL + normalized * xRng + (ev._graceShift || 0);
}

function stringYForHit(s) {
    return stringY(s) - LAYOUT.stringSpacing / 2;
}

// ── Selection highlight geometry ─────────────────────────────

const selectedEventRanges = computed(() => {
    if (!props.selectedEvents || props.selectedEvents.size === 0) return [];
    
    // Get all selected events in this measure, sorted by time
    const selected = voice1Events.value
        .filter(ev => props.selectedEvents.has(ev.id))
        .sort((a, b) => a.tick - b.tick);
        
    if (selected.length === 0) return [];
    
    // Draw one unified block spanning from the first to the last selected event
    const minX = getEvX(selected[0]);
    const maxX = getEvX(selected[selected.length - 1]);
    
    return [{ minX, maxX }];
});

// ── Active cursor geometry ───────────────────────────────────

const activeCursorVisible = computed(() => {
    if (!props.cursor || props.cursor.measureIndex !== props.measure.index) return false;
    const evs = voice1Events.value;
    return props.cursor.eventIndex < evs.length;
});

const activeEventX = computed(() => {
    if (!activeCursorVisible.value) return null;
    const ev = voice1Events.value[props.cursor.eventIndex];
    if (!ev) return null;
    return getEvX(ev);
});

const activeCellY = computed(() => {
    return stringY(props.cursor.stringIndex);
});

// ── Click handlers ───────────────────────────────────────────

function onNoteMousedown(ev, stringIndex, event) {
    emit('mousedown-event', { eventId: ev.id, stringIndex, event });
}

function onNoteEnter(ev, stringIndex, event) {
    const el = document.querySelector(`.sbn-tab-note-text[data-event-id="${ev.id}"][data-string="${stringIndex}"]`);
    if (el) el.classList.add('sbn-playing');
}

function onNoteLeave(ev, stringIndex, event) {
    const el = document.querySelector(`.sbn-tab-note-text[data-event-id="${ev.id}"][data-string="${stringIndex}"]`);
    if (el) el.classList.remove('sbn-playing');
}

function onRestMousedown(ev, event) {
    emit('mousedown-rest', { eventId: ev.id, event });
}

function onRestEnter(ev, event) {
    const el = document.querySelector(`.sbn-tab-rest[data-event-id="${ev.id}"]`);
    if (el) el.classList.add('sbn-playing');
}

function onRestLeave(ev, event) {
    const el = document.querySelector(`.sbn-tab-rest[data-event-id="${ev.id}"]`);
    if (el) el.classList.remove('sbn-playing');
}
</script>

<style scoped>
/* ── Selection range columns: orange, dimmer than cursor column ─── */
.sbn-cursor-sel-col {
    fill: var(--clr-accent, #f39c12);
    opacity: 0.1;
    pointer-events: none;
}



/* ── Cursor circle: gradient fill, very transparent ─────
   SVG scoped styles can't reference external CSS variables
   inside fill="url()" so we use a hard-coded rgba gradient
   defined inline via the SVG <defs> below.               */
.sbn-cursor-ring {
    fill: url(#sbn-cursor-gradient);
    stroke: var(--clr-accent, #f39c12);
    stroke-width: 0.2;
    pointer-events: none;
    transition: r 80ms ease;
}

.sbn-cursor-ring--input {
    stroke-width: 1;
    fill: url(#sbn-cursor-gradient-strong);
}

.sbn-cursor-ring--pending {
    stroke-dasharray: 3 2;
    animation: sbn-cursor-pending-pulse 0.6s ease-in-out infinite alternate;
}

.sbn-cursor-ring--grace {
    stroke: #27ae60;
    fill: none;
    stroke-width: 1.5;
    stroke-dasharray: 4 2;
}

@keyframes sbn-cursor-pending-pulse {
    from { opacity: 1; }
    to   { opacity: 0.45; }
}

/* ── Pending digit text ──────────────────────────────── */
.sbn-cursor-pending-digit {
    font-family: var(--font-mono, 'JetBrains Mono', monospace);
    font-size: 9px;
    font-weight: 700;
    fill: var(--clr-accent, #f39c12);
    pointer-events: none;
}

/* ── Hit targets ─────────────────────────────────────── */
.sbn-cursor-hit {
    fill: transparent;
    stroke: none;
    cursor: pointer;
}

/* ── Hover: when a hit rect is hovered, light up the
   note text on the same string / event column.
   Because note text lives in a sibling <g v-html>,
   we use a global CSS rule in leadsheets.css instead —
   see the comment in the polish notes.                  */
</style>

<!-- SVG gradient definitions — rendered once per measure that has the cursor -->
<!-- These must live inside the <svg> element, not in <style> -->
