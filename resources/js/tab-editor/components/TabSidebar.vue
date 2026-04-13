<template>
    <div class="sbn-tab-sidebar">

        <!-- ── Header ──────────────────────────────────────── -->
        <div class="sbn-tab-sidebar-header">
            <span class="sbn-tab-sidebar-title">Note Inspector</span>
            <div class="sbn-tab-sidebar-undo-bar">
                <button
                    class="sbn-tab-undo-btn"
                    :disabled="!canUndo"
                    title="Undo (Ctrl+Z)"
                    @click="$emit('undo')"
                >
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7v6h6"/><path d="M3 13C5 7 11 3 18 5a9 9 0 011 14"/></svg>
                    Undo
                </button>
                <button
                    class="sbn-tab-undo-btn"
                    :disabled="!canRedo"
                    title="Redo (Ctrl+Shift+Z)"
                    @click="$emit('redo')"
                >
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 7v6h-6"/><path d="M21 13C19 7 13 3 6 5a9 9 0 00-1 14"/></svg>
                    Redo
                </button>
            </div>
            <span
                class="sbn-tab-sidebar-mode"
                :class="`sbn-tab-sidebar-mode--${effectiveMode}`"
            >
                {{ modeLabel }}
            </span>
        </div>

        <!-- Paste bar: visible whenever clipboard has content -->
        <div v-if="hasClipboard" class="sbn-tab-paste-bar">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 4h2a2 2 0 012 2v14a2 2 0 01-2 2H6a2 2 0 01-2-2V6a2 2 0 012-2h2"/><rect x="8" y="2" width="8" height="4" rx="1" ry="1"/></svg>
            <span>{{ clipboardMode === 'note' ? 'Note' : clipboardCount + ' event' + (clipboardCount !== 1 ? 's' : '') }} copied</span>
            <button class="sbn-tab-clip-btn sbn-tab-clip-btn--primary" title="Paste (Ctrl+V)" @click="$emit('paste')">
                Paste
            </button>
        </div>

        <!-- ── No selection ─────────────────────────────────── -->
        <div v-if="!hasSelection" class="sbn-tab-sidebar-empty">
            <div class="sbn-tab-sidebar-empty-icon">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="1.4"
                     stroke-linecap="round" stroke-linejoin="round">
                    <path d="M9 19V6l12-3v13M9 19c0 1.1-1.343 2-3 2s-3-.9-3-2 1.343-2 3-2 3 .9 3 2zm12-3c0 1.1-1.343 2-3 2s-3-.9-3-2 1.343-2 3-2 3 .9 3 2z"/>
                </svg>
            </div>
            <p class="sbn-tab-sidebar-empty-text">
                Click a note to select it, or use <kbd>←</kbd><kbd>→</kbd> to navigate.
            </p>
        </div>

        <!-- ── Selection info ─────────────────────────────────── -->
        <template v-else>

            <!-- Position info -->
            <div class="sbn-tab-sidebar-section">
                <div class="sbn-tab-sidebar-section-label">Position</div>
                <div class="sbn-tab-sidebar-grid">
                    <div class="sbn-tab-sidebar-cell">
                        <span class="sbn-tab-cell-label">Measure</span>
                        <span class="sbn-tab-cell-value">{{ cursor.measureIndex + 1 }}</span>
                    </div>
                    <div class="sbn-tab-sidebar-cell">
                        <span class="sbn-tab-cell-label">Beat</span>
                        <span class="sbn-tab-cell-value">{{ beatLabel }}</span>
                    </div>
                    <div class="sbn-tab-sidebar-cell">
                        <span class="sbn-tab-cell-label">String</span>
                        <span class="sbn-tab-cell-value">{{ stringLabel }}</span>
                    </div>
                    <div class="sbn-tab-sidebar-cell">
                        <span class="sbn-tab-cell-label">Voice</span>
                        <span class="sbn-tab-cell-value">1</span>
                    </div>
                </div>
            </div>

            <!-- Phase 7d: overfill warning -->
            <div v-if="measureOverfill" class="sbn-tab-sidebar-overfill">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2.2"
                     stroke-linecap="round" stroke-linejoin="round">
                    <path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
                    <line x1="12" y1="9" x2="12" y2="13"/>
                    <line x1="12" y1="17" x2="12.01" y2="17"/>
                </svg>
                <span>Bar overfilled — shorten or remove notes to fix</span>
            </div>

            <!-- Fret entry: show large pending digit OR settled fret -->
            <div class="sbn-tab-sidebar-section">
                <div class="sbn-tab-sidebar-section-label">Fret</div>

                <!-- Pending digit: waiting for possible second digit -->
                <div v-if="pendingDigit !== null" class="sbn-tab-fret-pending">
                    <span class="sbn-tab-fret-pending-digit">{{ pendingDigit }}</span>
                    <span class="sbn-tab-fret-pending-hint">typing… type another digit or wait</span>
                </div>

                <!-- Settled: note on this string -->
                <div v-else-if="currentNote" class="sbn-tab-sidebar-grid">
                    <div class="sbn-tab-sidebar-cell">
                        <span class="sbn-tab-cell-label">Fret</span>
                        <span class="sbn-tab-cell-value sbn-tab-cell-value--fret">{{ currentNote.fret }}</span>
                    </div>
                    <div class="sbn-tab-sidebar-cell">
                        <span class="sbn-tab-cell-label">Pitch</span>
                        <span class="sbn-tab-cell-value">{{ pitchLabel }}</span>
                    </div>
                </div>

                <!-- Rest -->
                <div v-else-if="currentEvent && currentEvent.isRest" class="sbn-tab-sidebar-rest-badge">
                    <svg width="14" height="14" viewBox="0 0 24 16" fill="none"
                         stroke="currentColor" stroke-width="2">
                        <line x1="5" y1="12" x2="19" y2="12"/>
                    </svg>
                    Rest — type a fret to add a note
                </div>

                <!-- Empty string on a chord event -->
                <div v-else-if="currentEvent" class="sbn-tab-sidebar-empty-string">
                    <span class="sbn-tab-cell-label">String {{ cursor.stringIndex }} — empty</span>
                    <span class="sbn-tab-cell-hint">Type a fret number to add a note here</span>
                </div>
            </div>

            <!-- Duration -->
            <div v-if="currentEvent" class="sbn-tab-sidebar-section">
                <div class="sbn-tab-sidebar-section-label">Duration</div>
                <div class="sbn-tab-duration-picker">
                    <button
                        v-for="dur in DURATIONS"
                        :key="dur.code"
                        class="sbn-tab-dur-btn"
                        :class="{ 'is-active': currentDurBase === dur.code }"
                        :title="dur.label"
                        @click="$emit('set-duration', dur.code + (isDottedDur ? 'd' : ''))"
                    >
                        {{ dur.symbol }}
                    </button>
                    <button
                        class="sbn-tab-dur-btn sbn-tab-dur-btn--dot"
                        :class="{ 'is-active': isDottedDur }"
                        title="Dotted (shortcut: .)"
                        @click="$emit('toggle-dotted')"
                    >
                        ·
                    </button>
                </div>
                <div class="sbn-tab-sidebar-dur-name">
                    {{ durationName }}
                    <span v-if="tieLabel" class="sbn-tab-tie-badge">{{ tieLabel }}</span>
                </div>
            </div>

            <!-- Tie toggle -->
            <div v-if="currentEvent && !currentEvent.isRest" class="sbn-tab-sidebar-section">
                <div class="sbn-tab-sidebar-section-label">Articulation</div>
                <button
                    class="sbn-btn sbn-btn-secondary sbn-btn-sm sbn-tab-tie-btn"
                    :class="{ 'is-active': currentNoteTied }"
                    @click="$emit('toggle-tie')"
                >
                    <svg width="14" height="10" viewBox="0 0 24 16" fill="none"
                         stroke="currentColor" stroke-width="2" stroke-linecap="round">
                        <path d="M2 14 Q12 2 22 14"/>
                    </svg>
                    Tie {{ currentNoteTied ? '(on)' : '(off)' }}
                </button>
            </div>

        </template>

        <!-- ── Keyboard shortcut hints ─────────────────────── -->
        <div class="sbn-tab-sidebar-hints">
            <div class="sbn-tab-sidebar-hints-title">Keyboard</div>
            <div class="sbn-tab-hint-row"><kbd>0–9</kbd> <span>Enter fret number</span></div>
            <div class="sbn-tab-hint-row"><kbd>A</kbd> <span>Add note/rest</span></div>
            <div class="sbn-tab-hint-row"><kbd>Del</kbd> <span>Remove note or rest</span></div>
            <div class="sbn-tab-hint-row"><kbd>←</kbd><kbd>→</kbd> <span>Next / prev event</span></div>
            <div class="sbn-tab-hint-row"><kbd>↑</kbd><kbd>↓</kbd> <span>Change string</span></div>
            <div class="sbn-tab-hint-row"><kbd>Tab</kbd> <span>Next measure</span></div>
            <div class="sbn-tab-hint-row"><kbd>+</kbd><kbd>−</kbd> <span>Shorter / longer</span></div>
            <div class="sbn-tab-hint-row"><kbd>.</kbd> <span>Toggle dotted</span></div>
            <div class="sbn-tab-hint-row"><kbd>T</kbd> <span>Toggle tie</span></div>
            <div class="sbn-tab-hint-row"><kbd>Ctrl+1–6</kbd> <span>Set duration</span></div>
            <div class="sbn-tab-hint-row"><kbd>Esc</kbd> <span>Cancel / navigate</span></div>
            <div class="sbn-tab-sidebar-hints-title" style="margin-top:8px">Copy / Paste</div>
            <div class="sbn-tab-hint-row"><kbd>Shift+←→</kbd> <span>Select notes</span></div>
            <div class="sbn-tab-hint-row"><kbd>Ctrl+C</kbd> <span>Copy bar</span></div>
            <div class="sbn-tab-hint-row"><kbd>Ctrl+X</kbd> <span>Cut bar</span></div>
            <div class="sbn-tab-hint-row"><kbd>Ctrl+V</kbd> <span>Paste at cursor</span></div>
        </div>

    </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
    cursor: {
        type: Object,
        required: true,
    },
    currentEvent: {
        type: Object,
        default: null,
    },
    currentNote: {
        type: Object,
        default: null,
    },
    active: {
        type: Boolean,
        default: false,
    },
    ticksPerMeasure: {
        type: Number,
        default: 1920,
    },
    /**
     * Pending first digit from useNoteInput (string '0'-'9' or null).
     * When set, sidebar shows "typing…" state instead of settled fret.
     */
    pendingDigit: {
        type: String,
        default: null,
    },
    /**
     * Phase 7d: true if the current measure's total ticks exceed ticksPerMeasure.
     */
    measureOverfill: {
        type: Boolean,
        default: false,
    },
    /** Phase 7e: undo/redo availability from useUndo. */
    canUndo: { type: Boolean, default: false },
    canRedo: { type: Boolean, default: false },
    /** Phase 7g: clipboard */
    hasClipboard:   { type: Boolean, default: false },
    clipboardCount: { type: Number, default: 0 },
    clipboardMode:  { type: String, default: '' },  // 'measure' | 'events' | ''
});

defineEmits(['set-duration', 'toggle-tie', 'toggle-dotted', 'undo', 'redo', 'copy', 'cut', 'paste']);

// ── Duration table ────────────────────────────────────────

const DURATIONS = [
    { code: 'w',  symbol: '𝅝',  label: 'Whole' },
    { code: 'h',  symbol: '𝅗𝅥',  label: 'Half' },
    { code: 'q',  symbol: '♩',  label: 'Quarter' },
    { code: 'e',  symbol: '♪',  label: '8th' },
    { code: 's',  symbol: '♬',  label: '16th' },
    { code: 't',  symbol: '𝅘𝅥𝅯𝅘𝅥𝅯', label: '32nd' },
];

const DURATION_NAMES = {
    'w': 'Whole', 'wd': 'Dotted whole',
    'h': 'Half',  'hd': 'Dotted half',
    'q': 'Quarter', 'qd': 'Dotted quarter',
    'e': 'Eighth', 'ed': 'Dotted eighth',
    's': '16th',  'sd': 'Dotted 16th',
    't': '32nd',  'td': 'Dotted 32nd',
};

const STRING_NAMES = {
    1: 'e (1st)', 2: 'B (2nd)', 3: 'G (3rd)',
    4: 'D (4th)', 5: 'A (5th)', 6: 'E (6th)',
};

// ── Computed ──────────────────────────────────────────────

const hasSelection = computed(() => props.active && props.currentEvent !== null);

// Show 'input' mode badge whenever a pending digit is live
const effectiveMode = computed(() =>
    props.pendingDigit !== null ? 'input' : props.cursor.mode
);

const modeLabel = computed(() => {
    switch (effectiveMode.value) {
        case 'navigate': return 'Navigate';
        case 'input':    return 'Input';
        case 'select':   return 'Select';
        default:         return '';
    }
});

const stringLabel = computed(() =>
    STRING_NAMES[props.cursor.stringIndex] || `String ${props.cursor.stringIndex}`
);

const beatLabel = computed(() => {
    if (!props.currentEvent) return '—';
    const ticksPerBeat = props.ticksPerMeasure / 4;
    return Math.floor(props.currentEvent.tickInMeasure / ticksPerBeat) + 1;
});

const pitchLabel = computed(() => {
    if (!props.currentNote) return '—';
    const { pitch, octave } = props.currentNote;
    if (!pitch) return '—';
    return octave !== null && octave !== undefined ? `${pitch}${octave}` : pitch;
});

const durationName = computed(() => {
    if (!props.currentEvent) return '';
    return DURATION_NAMES[props.currentEvent.duration] || props.currentEvent.duration;
});

const currentDurBase = computed(() => {
    if (!props.currentEvent) return '';
    return props.currentEvent.duration.replace('d', '');
});

const isDottedDur = computed(() => {
    if (!props.currentEvent) return false;
    return props.currentEvent.duration.endsWith('d');
});

const tieLabel = computed(() => {
    const note = props.currentNote;
    if (!note) return '';
    if (note.tieStart && note.tieStop) return 'tied (both)';
    if (note.tieStart) return 'tie start';
    if (note.tieStop)  return 'tie end';
    return '';
});

const currentNoteTied = computed(() => {
    const note = props.currentNote;
    return note ? !!note.tieStart : false;
});
</script>

<style scoped>
/* ── Sidebar shell ───────────────────────────────────────── */
.sbn-tab-sidebar {
    display: flex;
    flex-direction: column;
    gap: 0;
    padding: 0;
    font-family: var(--font-body, 'DM Sans', sans-serif);
    font-size: 13px;
    color: var(--clr-text);
    height: 100%;
    overflow-y: auto;
}

/* ── Header ─────────────────────────────────────────────── */
.sbn-tab-sidebar-undo-bar {
    display: flex;
    gap: 4px;
    margin-bottom: 6px;
}

.sbn-tab-undo-btn {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 3px 8px;
    font-size: 11px;
    font-family: inherit;
    border: 1px solid var(--clr-border, #d1d5db);
    border-radius: 4px;
    background: var(--clr-surface, #fff);
    color: var(--clr-text, #374151);
    cursor: pointer;
    transition: background 0.15s, opacity 0.15s;
    flex: 1;
    justify-content: center;
}

.sbn-tab-undo-btn:hover:not(:disabled) {
    background: var(--clr-surface-2, #f3f4f6);
}

.sbn-tab-undo-btn:disabled {
    opacity: 0.35;
    cursor: not-allowed;
}

.sbn-tab-sidebar-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 10px 14px 8px;
    border-bottom: 1px solid var(--clr-border);
}

.sbn-tab-sidebar-title {
    font-weight: 600;
    font-size: 13px;
    color: var(--clr-text);
}

.sbn-tab-sidebar-mode {
    font-size: 10px;
    font-weight: 600;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    padding: 2px 7px;
    border-radius: 10px;
    background: var(--clr-surface-3);
    color: var(--clr-text-muted);
}

.sbn-tab-sidebar-mode--navigate {
    background: var(--clr-surface-3);
    color: var(--clr-text-muted);
}

.sbn-tab-sidebar-mode--input {
    background: var(--clr-accent-bg);
    color: var(--clr-accent);
}

.sbn-tab-sidebar-mode--select {
    background: rgba(99, 102, 241, 0.12);
    color: #6366f1;
}

/* ── Section blocks ─────────────────────────────────────── */
.sbn-tab-sidebar-section {
    padding: 10px 14px;
    border-bottom: 1px solid var(--clr-border);
}

.sbn-tab-sidebar-section-label {
    font-size: 10px;
    font-weight: 600;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--clr-text-muted);
    margin-bottom: 8px;
}

/* ── Info grid ──────────────────────────────────────────── */
.sbn-tab-sidebar-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 6px 8px;
}

.sbn-tab-sidebar-cell {
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.sbn-tab-cell-label {
    font-size: 10px;
    color: var(--clr-text-muted);
    text-transform: uppercase;
    letter-spacing: 0.05em;
}

.sbn-tab-cell-value {
    font-size: 15px;
    font-weight: 600;
    color: var(--clr-text);
    font-family: var(--font-mono, 'JetBrains Mono', monospace);
}

.sbn-tab-cell-value--fret {
    font-size: 22px;
    color: var(--clr-accent);
}

.sbn-tab-cell-hint {
    font-size: 11px;
    color: var(--clr-text-muted);
    margin-top: 2px;
}

/* ── Pending fret display ───────────────────────────────── */
.sbn-tab-fret-pending {
    display: flex;
    align-items: baseline;
    gap: 8px;
}

.sbn-tab-fret-pending-digit {
    font-family: var(--font-mono, 'JetBrains Mono', monospace);
    font-size: 28px;
    font-weight: 700;
    color: var(--clr-accent);
    /* Pulse animation to indicate waiting state */
    animation: sbn-fret-pending-blink 0.6s ease-in-out infinite alternate;
}

@keyframes sbn-fret-pending-blink {
    from { opacity: 1; }
    to   { opacity: 0.45; }
}

.sbn-tab-fret-pending-hint {
    font-size: 11px;
    color: var(--clr-text-muted);
}

/* ── Rest badge ─────────────────────────────────────────── */
.sbn-tab-sidebar-rest-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 3px 10px 3px 7px;
    border-radius: 12px;
    background: var(--clr-surface-3);
    color: var(--clr-text-muted);
    font-size: 12px;
    font-weight: 500;
}

/* ── Empty string ───────────────────────────────────────── */
.sbn-tab-sidebar-empty-string {
    display: flex;
    flex-direction: column;
    gap: 3px;
}

/* ── Duration picker ────────────────────────────────────── */
.sbn-tab-duration-picker {
    display: flex;
    gap: 4px;
    flex-wrap: wrap;
    margin-bottom: 6px;
}

.sbn-tab-dur-btn {
    width: 30px;
    height: 30px;
    border: 1px solid var(--clr-border);
    border-radius: 5px;
    background: var(--clr-surface);
    color: var(--clr-text);
    font-size: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: border-color 0.1s, background 0.1s;
}

.sbn-tab-dur-btn:hover {
    border-color: var(--clr-accent-border);
    background: var(--clr-accent-bg);
}

.sbn-tab-dur-btn.is-active {
    border-color: var(--clr-accent);
    background: var(--clr-accent-bg);
    color: var(--clr-accent);
}

.sbn-tab-dur-btn--dot {
    font-size: 20px;
    font-weight: 700;
    line-height: 1;
}

.sbn-tab-sidebar-dur-name {
    font-size: 12px;
    color: var(--clr-text-muted);
    display: flex;
    align-items: center;
    gap: 6px;
}

.sbn-tab-tie-badge {
    font-size: 11px;
    padding: 1px 6px;
    background: var(--clr-surface-3);
    border-radius: 8px;
    color: var(--clr-text-muted);
}

/* ── Tie button ─────────────────────────────────────────── */
.sbn-tab-tie-btn {
    display: inline-flex;
    align-items: center;
    gap: 5px;
}

.sbn-tab-tie-btn.is-active {
    border-color: var(--clr-accent);
    color: var(--clr-accent);
    background: var(--clr-accent-bg);
}

/* ── Empty state ────────────────────────────────────────── */
.sbn-tab-sidebar-empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 10px;
    padding: 24px 16px;
    text-align: center;
    color: var(--clr-text-muted);
}

.sbn-tab-sidebar-empty-icon { opacity: 0.35; }

.sbn-tab-sidebar-empty-text {
    font-size: 12px;
    line-height: 1.5;
    margin: 0;
}

.sbn-tab-sidebar-empty-text kbd,
.sbn-tab-hint-row kbd {
    display: inline-block;
    padding: 1px 5px;
    border: 1px solid var(--clr-border);
    border-radius: 3px;
    background: var(--clr-surface-3);
    font-size: 11px;
    font-family: var(--font-mono, monospace);
}

/* ── Keyboard hints ─────────────────────────────────────── */
.sbn-tab-sidebar-hints {
    padding: 10px 14px 14px;
    margin-top: auto;
    border-top: 1px solid var(--clr-border);
}

.sbn-tab-sidebar-hints-title {
    font-size: 10px;
    font-weight: 600;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--clr-text-muted);
    margin-bottom: 7px;
}

.sbn-tab-hint-row {
    display: flex;
    align-items: center;
    gap: 6px;
    margin-bottom: 4px;
    font-size: 12px;
    color: var(--clr-text-dim);
}

.sbn-tab-hint-row span {
    color: var(--clr-text-muted);
}

/* ── Phase 7d: overfill warning ────────────────────────────── */
.sbn-tab-sidebar-overfill {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 8px 14px;
    background: rgba(220, 38, 38, 0.08);
    border-bottom: 1px solid rgba(220, 38, 38, 0.18);
    color: #b91c1c;
    font-size: 12px;
    font-weight: 500;
}

.sbn-tab-sidebar-overfill svg {
    flex-shrink: 0;
    stroke: #dc2626;
}


.sbn-tab-paste-bar {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 6px 14px;
    background: rgba(16, 185, 129, 0.07);
    border-bottom: 1px solid rgba(16, 185, 129, 0.18);
    font-size: 12px;
    color: var(--clr-text-muted);
}

.sbn-tab-paste-bar > svg { flex-shrink: 0; stroke: #059669; }
.sbn-tab-paste-bar > span { flex: 1; }

.sbn-tab-clip-btn {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 3px 9px;
    font-size: 11px;
    font-family: inherit;
    font-weight: 500;
    border: 1px solid rgba(99, 102, 241, 0.3);
    border-radius: 4px;
    background: rgba(99, 102, 241, 0.08);
    color: #4f46e5;
    cursor: pointer;
    transition: background 0.12s;
    flex: 1;
    justify-content: center;
}

.sbn-tab-clip-btn:hover {
    background: rgba(99, 102, 241, 0.16);
}

.sbn-tab-clip-btn--danger {
    border-color: rgba(220, 38, 38, 0.3);
    background: rgba(220, 38, 38, 0.07);
    color: #dc2626;
}
.sbn-tab-clip-btn--danger:hover {
    background: rgba(220, 38, 38, 0.14);
}

.sbn-tab-clip-btn--primary {
    border-color: rgba(16, 185, 129, 0.35);
    background: rgba(16, 185, 129, 0.09);
    color: #059669;
}
.sbn-tab-clip-btn--primary:hover {
    background: rgba(16, 185, 129, 0.17);
}
</style>
