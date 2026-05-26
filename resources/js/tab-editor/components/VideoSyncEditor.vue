<template>
    <div class="sbn-vsync-editor">

        <!-- Video ID input -->
        <div class="sbn-vsync-url-row">
            <input
                class="sbn-vsync-url-input"
                type="text"
                placeholder="YouTube video ID or URL…"
                :value="rawInput"
                @input="rawInput = $event.target.value"
                @keydown.enter="applyVideoId"
            />
            <button class="sbn-btn sbn-btn-sm sbn-btn-primary" @click="applyVideoId">Set</button>
        </div>
        <div v-if="videoId" class="sbn-vsync-video-id-badge">
            {{ videoType === 'youtube' ? 'YouTube: ' : 'Video: ' }}<strong>{{ videoId }}</strong>
            <button class="sbn-vsync-clear-btn" @click="clearVideo" title="Remove video">✕</button>
        </div>

        <!-- Set downbeat — only for audio-transcribed leadsheets -->
        <div v-if="canReshift" class="sbn-downbeat-tool">
            <div class="sbn-downbeat-header">
                <span class="sbn-vsync-label">Set downbeat</span>
            </div>
            <button
                class="sbn-btn sbn-btn-sm"
                :class="downbeatPickMode ? 'sbn-btn-warning' : 'sbn-btn-primary'"
                :disabled="reshiftBusy"
                @click="downbeatPickMode = !downbeatPickMode"
            >
                {{ reshiftBusy
                    ? 'Re-shifting…'
                    : (downbeatPickMode ? 'Click a note in the tab…  (cancel)' : '🎯 Set downbeat from a note') }}
            </button>
            <p class="sbn-downbeat-hint" v-if="downbeatPickMode">
                Switch to <strong>Tab</strong> view, then click the note that is the
                true beat <strong>“1”</strong>.
            </p>
            <div v-if="reshiftError" class="sbn-downbeat-error">{{ reshiftError }}</div>
            <div class="sbn-downbeat-warn">
                Re-shifting rebuilds the grid from the original audio — manual chord
                or voicing edits made after import will be lost. Do this first.
            </div>
        </div>

        <!-- Player -->
        <VideoPlayer
            v-if="videoId"
            :video-id="videoId"
            :video-type="videoType"
            ref="player"
            @timeupdate="onVideoTimeUpdate"
            @play-state-change="onVideoPlayStateChange"
            @ready="onPlayerReady"
        />

        <!-- D2: Playback rate slider -->
        <div v-if="videoId" class="sbn-vsync-rate-row">
            <span class="sbn-vsync-label">Speed</span>
            <div class="sbn-vsync-rate-buttons">
                <button
                    v-for="rate in [0.25, 0.5, 0.75, 1.0, 1.25, 1.5]"
                    :key="rate"
                    class="sbn-btn sbn-btn-xs"
                    :class="playbackRate === rate ? 'sbn-btn-primary' : 'sbn-btn-secondary'"
                    @click="setRate(rate)"
                >
                    {{ rate }}×
                </button>
            </div>
        </div>

        <!-- Tap-to-mark controls -->
        <div v-if="videoId" class="sbn-vsync-controls">
            <div class="sbn-vsync-controls-row">
                <span class="sbn-vsync-time-badge">{{ formatTime(videoTime) }}</span>
                <span class="sbn-vsync-tap-hint">Press <kbd>M</kbd> to mark, <kbd>Shift+M</kbd> to undo</span>
            </div>
            <div class="sbn-vsync-controls-row">
                <span class="sbn-vsync-label" :title="'Position in the played sequence (advances each Mark — wraps through repeats and voltas)'">Pos</span>
                <button class="sbn-btn sbn-btn-xs sbn-btn-secondary" @click="setTapCursor(Math.max(0, localTapCursor - 1))">−</button>
                <input
                    class="sbn-vsync-measure-input"
                    type="number"
                    :min="0"
                    :value="localTapCursor"
                    @input="setTapCursor(clampPos(parseInt($event.target.value) || 0))"
                />
                <button class="sbn-btn sbn-btn-xs sbn-btn-secondary" @click="setTapCursor(clampPos(localTapCursor + 1))">+</button>
                <button class="sbn-btn sbn-btn-sm sbn-btn-primary" @click="markCurrent">
                    Mark (bar {{ tapCursorGi + 1 }}<span v-if="tapCursorPass > 1"> · pass {{ tapCursorPass }}</span>)
                </button>
            </div>
        </div>

        <!-- D2: Mapping table with nudge buttons -->
        <div v-if="sortedMappings.length" class="sbn-vsync-table-wrap">
            <!-- D2: Status badge -->
            <div class="sbn-vsync-status-badge">
                {{ sortedMappings.length }} markers
                <span v-if="sortedMappings.length >= 2">
                    · bar {{ sortedMappings[0].measureIndex + 1 }}–{{ sortedMappings[sortedMappings.length - 1].measureIndex + 1 }}
                </span>
            </div>
            <div class="sbn-vsync-table-header">
                <span>Bar</span><span>Time</span><span></span>
            </div>
            <div
                v-for="m in sortedByTime"
                :key="`${m.measureIndex}@${m.videoTime}`"
                class="sbn-vsync-table-row"
                :class="{ 'is-active': m.measureIndex === activeMappingIndex && m.videoTime === activeMappingTime, 'is-tap-target': m.measureIndex === tapCursorGi }"
                @click="seekToMappingRow(m)"
            >
                <span
                    class="sbn-vsync-bar-num"
                    :class="{ 'has-tempo-warning': hasTempoWarning(m) }"
                >{{ m.measureIndex + 1 }}<span v-if="passLabel(m)" class="sbn-vsync-pass-label"> · {{ passLabel(m) }}</span></span>
                <span class="sbn-vsync-time-val">{{ formatTime(m.videoTime) }}</span>
                <button class="sbn-vsync-del-btn" @click.stop="removeMappingByIdentity(m)" title="Remove">✕</button>
            </div>
        </div>
        <div v-else-if="videoId" class="sbn-vsync-empty-table">
            No sync markers yet. Use Tap Mode to mark downbeats.
        </div>

        <!-- D2: Footer with distribute + clear -->
        <div v-if="sortedMappings.length" class="sbn-vsync-footer">
            <button
                v-if="sortedMappings.length >= 2"
                class="sbn-btn sbn-btn-xs sbn-btn-primary"
                @click="() => { console.log('[VideoSyncEditor] emit distribute-markers'); emit('distribute-markers'); }"
                title="Linearly interpolate markers between first and last"
            >
                Distribute ({{ sortedMappings.length }})
            </button>
            <button class="sbn-btn sbn-btn-xs sbn-btn-secondary" @click="clearMappings">Clear all</button>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, watch, inject, onMounted, onUnmounted } from 'vue';
import VideoPlayer from '@/Components/Library/Video/VideoEmbed.vue';

const props = defineProps({
    videoId:        { type: String,  default: '' },
    videoType:      { type: String,  default: 'youtube' },
    sortedMappings: { type: Array,   default: () => [] },
    videoTime:      { type: Number,  default: 0 },
    playerRef:      { type: Object,  default: null },
    // Total number of play positions in the expanded sequence (so the tap
    // cursor can advance past the end of the un-repeated bar count when the
    // song contains repeats).
    sequenceLength: { type: Number,  default: 0 },
    // gi the tap cursor currently points at (= sequence[tapCursor]). Used for
    // display — the Mark button shows "bar N (pass P)" when relevant.
    tapCursorGi:    { type: Number,  default: 0 },
    // How many times the current cursor's gi has appeared in the sequence up
    // to and including the current position (1, 2, …). 1 for non-repeated
    // bars. Used to show "(pass 2)" in the Mark button label.
    tapCursorPass:  { type: Number,  default: 1 },
});

const emit = defineEmits([
    'set-video-id',
    'add-mapping',
    'remove-mapping',
    'remove-mapping-identity',
    'seek-to-mapping',
    'clear-mappings',
    'distribute-markers',
    'untap',
    'timeupdate',
    'play-state-change',
    'player-ref-change',
    'toggle-playback',
    'tap-cursor-change',
]);

// ── Injected from TabEditor ────────────────────────────────────
const seekToMeasure = inject('seekToMeasure', null);
const playingMeasureIndex = inject('playingMeasureIndex', ref(0));
// Play-position-based cursor (the editor thinks in play positions; the gi-
// flavored `tapCursor` inject is only for measure highlighting in the score).
const injectedTapCursor = inject('tapCursorPos', computed(() => 0));

// ── Local state ───────────────────────────────────────────────
const rawInput       = ref(props.videoId || '');
const player         = ref(null);
const playbackRate   = ref(1.0);
const localTapCursor = ref(0);

// ── Set-downbeat tool ─────────────────────────────────────────
// Cached raw audio transcription is exposed by the edit blade on
// window.__sbnLeadsheet. Present only on audio-transcribed leadsheets.
// Must match assembleTranscription()'s hardcoded $beatsPerBar in LeadsheetController.
const BEATS_PER_BAR = 4;
const _lsGlobal = (typeof window !== 'undefined' && window.__sbnLeadsheet) || {};
const _rawTranscription = _lsGlobal.transcriptionRaw || null;
const _leadsheetId = _lsGlobal.id || null;

const reshiftBusy  = ref(false);
const reshiftError = ref('');

// Only audio-transcribed leadsheets carry the cached raw beats needed to
// re-shift; the tool hides itself for hand-built sheets.
const canReshift = computed(() =>
    !!(_leadsheetId && _rawTranscription?.beats?.length)
);

// Shared with TabEditor: when armed, the next tab-note click is read as
// "this note is beat 1" instead of a normal edit. Falls back to a local ref
// if the editor didn't provide one (e.g. component mounted standalone).
const downbeatPickMode = inject('downbeatPickMode', ref(false));

// 480 ticks = 1 quarter; one 4/4 bar = 1920. The re-shift offset is a tick
// value so an off-beat (8th/16th) note can become the exact downbeat.
const TICKS_PER_BAR = BEATS_PER_BAR * 480;

/**
 * Called by TabEditor when the user clicks a tab note while pick-mode is armed.
 * `tickInBar` is the clicked note's bar-relative tick position (0..1919).
 *
 * The current grid was assembled with `transcriptionRaw.downbeatOffset` ticks
 * of pickup. To make the clicked note the new "1", the downbeat shifts forward
 * by `tickInBar`; the result is reduced mod TICKS_PER_BAR because re-assembly
 * always restarts from the pristine raw beats and trims leading full bars.
 *
 * NOTE: `downbeatOffset` is a tick value on sheets assembled by this version.
 * Sheets last shifted by the older whole-beat code stored 0..3; such a value
 * reads as a near-zero tick shift here — harmless, and it self-corrects on the
 * first re-shift since assembly always restarts from the pristine raw beats.
 */
function pickDownbeatFromTick(tickInBar) {
    const currentOffset = _rawTranscription?.downbeatOffset || 0;
    const t = ((Math.round(tickInBar) % TICKS_PER_BAR) + TICKS_PER_BAR) % TICKS_PER_BAR;
    const newOffset = (currentOffset + t) % TICKS_PER_BAR;
    applyDownbeat(newOffset);
}

// Exposed so TabEditor's tab-note click handler can drive the re-shift.
defineExpose({ pickDownbeatFromTick });

async function applyDownbeat(offset) {
    if (!_leadsheetId || reshiftBusy.value) return;
    reshiftBusy.value = true;
    reshiftError.value = '';
    try {
        const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
        const resp = await fetch(`/api/admin/leadsheets/${_leadsheetId}/reshift-downbeat`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': token,
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ offset: Math.max(0, Math.min(TICKS_PER_BAR - 1, offset)) }),
        });
        const data = await resp.json();
        if (!resp.ok || !data.success) {
            reshiftError.value = data.error || `Re-shift failed (${resp.status}).`;
            reshiftBusy.value = false;
            return;
        }
        // Full re-assembly replaces sections/melody/videoSync. Reload so the
        // editor re-initialises cleanly from the fresh json_data.
        window.location.reload();
    } catch (e) {
        reshiftError.value = 'Could not reach the server.';
        reshiftBusy.value = false;
    }
}

// Sync injected → local (one way, external changes update local)
watch(injectedTapCursor, (v) => { localTapCursor.value = v; }, { immediate: true });

// Helper to update local and emit
function setTapCursor(mi) {
    localTapCursor.value = mi;
    emit('tap-cursor-change', mi);
}

// Clamp a candidate cursor position to [0, sequenceLength]. When no expanded
// sequence is available (no repeats), allow unbounded values.
function clampPos(p) {
    if (props.sequenceLength <= 0) return Math.max(0, p);
    return Math.max(0, Math.min(props.sequenceLength, p));
}

// Mirror the playerRef so useVideoSync can call seekTo()
watch(player, (p) => { emit('player-ref-change', p); }, { immediate: true });

// Mappings sorted by videoTime — the natural reading order through the song
// (a repeated bar's two marks appear in the order they're heard).
const sortedByTime = computed(() =>
    [...props.sortedMappings].sort((a, b) => a.videoTime - b.videoTime || a.measureIndex - b.measureIndex)
);

// Active mark — the latest mapping whose videoTime <= current videoTime.
// Returns both gi and videoTime so the table can distinguish duplicates.
const activeMark = computed(() => {
    if (!sortedByTime.value.length) return null;
    let best = null;
    for (const m of sortedByTime.value) {
        if (m.videoTime <= props.videoTime) best = m;
        else break;
    }
    return best;
});
const activeMappingIndex = computed(() => activeMark.value?.measureIndex ?? -1);
const activeMappingTime  = computed(() => activeMark.value?.videoTime  ?? -Infinity);

// Pass label "1/2", "2/2" etc. — only when a gi has multiple marks.
function passLabel(m) {
    const sameGi = props.sortedMappings.filter(x => x.measureIndex === m.measureIndex);
    if (sameGi.length <= 1) return '';
    sameGi.sort((a, b) => a.videoTime - b.videoTime);
    const pass = sameGi.findIndex(x => x.videoTime === m.videoTime) + 1;
    return `${pass}/${sameGi.length}`;
}

// D2: Tempo warning detection. Compare against the previous mapping in time-
// order rather than gi-order, so a repeat (which goes backward in gi) doesn't
// fire a false warning.
function hasTempoWarning(m) {
    const tlist = sortedByTime.value;
    const idx = tlist.findIndex(x => x.measureIndex === m.measureIndex && x.videoTime === m.videoTime);
    if (idx <= 0) return false;
    const prev = tlist[idx - 1];
    const seconds = m.videoTime - prev.videoTime;
    if (seconds <= 0) return false;
    const beats = Math.abs(m.measureIndex - prev.measureIndex) || 1;
    const secsPerBeat = seconds / beats;
    return secsPerBeat < 0.1 || secsPerBeat > 5;
}

function removeMappingByIdentity(m) {
    emit('remove-mapping-identity', { measureIndex: m.measureIndex, videoTime: m.videoTime });
}

// ── Helpers ───────────────────────────────────────────────────

function formatTime(sec) {
    if (sec === null || sec === undefined || isNaN(sec)) return '0:00.0';
    const m = Math.floor(sec / 60);
    const s = (sec % 60).toFixed(1).padStart(4, '0');
    return `${m}:${s}`;
}

function parseTime(str) {
    // Accept M:SS.s or SS.s or plain seconds
    if (typeof str !== 'string') return parseFloat(str) || 0;
    const parts = str.trim().split(':');
    if (parts.length === 2) {
        return parseInt(parts[0]) * 60 + parseFloat(parts[1]);
    }
    return parseFloat(str) || 0;
}

function extractYouTubeId(input) {
    if (!input) return null;
    // Already a bare ID (11 chars, alphanumeric + - _)
    if (/^[A-Za-z0-9_-]{11}$/.test(input.trim())) return input.trim();
    // URL forms: youtu.be/ID, youtube.com/watch?v=ID, /embed/ID
    const m = input.match(/(?:youtu\.be\/|v=|embed\/)([A-Za-z0-9_-]{11})/);
    return m ? m[1] : null;
}

// ── Actions ───────────────────────────────────────────────────

function applyVideoId() {
    const raw = rawInput.value.trim();
    if (!raw) return;
    const ytId = extractYouTubeId(raw);
    if (ytId) {
        emit('set-video-id', { id: ytId, type: 'youtube' });
    } else {
        // Assume hosted URL
        emit('set-video-id', { id: raw, type: 'hosted' });
    }
}

function clearVideo() {
    rawInput.value = '';
    emit('set-video-id', { id: '', type: 'youtube' });
}

// D2: Tap mark at current cursor position. The cursor is a **play position**;
// the gi to mark is resolved by the parent (props.tapCursorGi). This way a
// repeated bar (e.g. AABA: A1 bar 1 and A2 bar 1 share gi=0) gets two distinct
// marks at the two play positions where it sounds.
function markCurrent() {
    emit('add-mapping', { measureIndex: props.tapCursorGi, videoTime: props.videoTime });
    // Auto-advance one play position. When the song has no expanded sequence
    // (no repeats) sequenceLength==0 → fall back to unbounded advance.
    const max = props.sequenceLength > 0 ? props.sequenceLength : localTapCursor.value + 2;
    setTapCursor(Math.min(max, localTapCursor.value + 1));
}

// D2: Jump tap cursor to specific measure (for click-to-seek)
function jumpTapCursor(mi) {
    setTapCursor(mi);
}


function removeMapping(measureIndex) {
    emit('remove-mapping', measureIndex);
}

function clearMappings() {
    if (confirm('Remove all video sync markers?')) {
        emit('clear-mappings');
    }
}

// D2: Playback rate control
function setRate(rate) {
    playbackRate.value = rate;
    if (player.value) player.value.setPlaybackRate(rate);
}

function nudgeRate(delta) {
    const rates = [0.25, 0.5, 0.75, 1.0, 1.25, 1.5];
    const idx = rates.indexOf(playbackRate.value);
    const newIdx = Math.max(0, Math.min(rates.length - 1, idx + delta));
    setRate(rates[newIdx]);
}

// D2: Nudge video time (for keyboard shortcuts)
function nudgeVideo(delta) {
    if (!player.value) return;
    const t = Math.max(0, props.videoTime + delta);
    player.value.seekTo(t);
}

function seekToMappingRow(m) {
    // Seek the video to this specific mark's time.
    if (player.value) player.value.seekTo(m.videoTime);
    // Ask the parent to map this videoTime back to its play position so the
    // synth lands on the same pass (clicking a "2/2" row seeks to pass 2, not
    // pass 1). The parent owns the sequence + interpolation helpers.
    emit('seek-to-mapping', { measureIndex: m.measureIndex, videoTime: m.videoTime });
}


function onVideoTimeUpdate(t) {
    emit('timeupdate', t);
}

function onVideoPlayStateChange(playing) {
    emit('play-state-change', playing);
}

function onPlayerReady() {}

// ── Keyboard handler (global, works even when not focused) ─────

function onKeydown(e) {
    // Guard: don't intercept when typing in inputs
    const tag = e.target?.tagName;
    if (tag === 'INPUT' || tag === 'TEXTAREA') return;

    // Space: toggle playback
    if (e.key === ' ' && !e.ctrlKey && !e.metaKey && !e.altKey) {
        e.preventDefault();
        emit('toggle-playback');
        return;
    }

    // Shift+M: un-tap (undo last mapping)
    if ((e.key === 'm' || e.key === 'M') && e.shiftKey) {
        e.preventDefault();
        emit('untap');
        return;
    }

    // M: mark sync point at tap cursor, then advance.
    // Mark by the cursor's GI (resolved by the parent from pos → seq[pos]),
    // not by the raw cursor position — they differ once the song repeats.
    if (e.key === 'm' || e.key === 'M') {
        emit('add-mapping', { measureIndex: props.tapCursorGi, videoTime: props.videoTime });
        const max = props.sequenceLength > 0 ? props.sequenceLength : localTapCursor.value + 2;
        setTapCursor(Math.min(max, localTapCursor.value + 1));
        e.preventDefault();
        return;
    }

    // Arrow keys: nudge video time
    if (e.key === 'ArrowLeft') {
        e.preventDefault();
        nudgeVideo(e.shiftKey ? -10 : -2);
        return;
    }
    if (e.key === 'ArrowRight') {
        e.preventDefault();
        nudgeVideo(e.shiftKey ? 10 : 2);
        return;
    }

    // Comma/Period: decrease/increase playback rate
    if (e.key === ',' || e.key === '<') {
        e.preventDefault();
        nudgeRate(-1);
        return;
    }
    if (e.key === '.' || e.key === '>') {
        e.preventDefault();
        nudgeRate(1);
        return;
    }
}

onMounted(() => { document.addEventListener('keydown', onKeydown); });
onUnmounted(() => { document.removeEventListener('keydown', onKeydown); });
</script>

<style scoped>
/* Match sidebar padding from TabSidebar.vue */
.sbn-vsync-editor {
    padding: 10px 14px;
    display: flex;
    flex-direction: column;
    gap: 10px;
}

/* Ensure video player respects 16:9 aspect ratio within sidebar width */
.sbn-vsync-editor :deep(.sbn-video-player) {
    width: 100%;
}

.sbn-vsync-editor :deep(.sbn-video-iframe-wrap) {
    position: relative;
    width: 100%;
    padding-top: 56.25%; /* 16:9 aspect ratio */
}

.sbn-vsync-editor :deep(.sbn-video-iframe-wrap iframe) {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
}

/* D2: Playback rate row */
.sbn-vsync-rate-row {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}

.sbn-vsync-rate-buttons {
    display: flex;
    gap: 4px;
}

.sbn-vsync-time-val {
    flex: 1;
    font-family: var(--font-mono, monospace);
    font-size: 11px;
    color: var(--clr-text-muted);
}

/* D2: Status badge */
.sbn-vsync-status-badge {
    font-size: 11px;
    color: var(--clr-text-muted);
    padding: 4px 0;
    border-bottom: 1px solid var(--clr-border);
    margin-bottom: 4px;
}

/* D2: Tap target highlight (measure being authored now) */
.sbn-vsync-table-row.is-tap-target {
    outline: 2px solid var(--clr-accent);
    outline-offset: -2px;
    animation: pulse-tap-target 1s ease-in-out infinite;
}

@keyframes pulse-tap-target {
    0%, 100% { outline-color: var(--clr-accent); }
    50% { outline-color: var(--clr-accent-dim); }
}

/* D2: Tempo warning (unreasonable beat duration) */
.sbn-vsync-bar-num.has-tempo-warning {
    color: #ef4444;
    font-weight: bold;
}

/* ── Set-downbeat tool ───────────────────────────────────────── */
.sbn-downbeat-tool {
    display: flex;
    flex-direction: column;
    gap: 6px;
    padding: 8px;
    border: 1px solid var(--clr-border);
    border-radius: 6px;
    background: var(--clr-surface-alt, #f9fafb);
}

.sbn-downbeat-header {
    display: flex;
    align-items: baseline;
    gap: 8px;
}

.sbn-downbeat-hint {
    font-size: 11px;
    color: var(--clr-text-muted);
}


.sbn-downbeat-error {
    font-size: 11px;
    color: #ef4444;
}

.sbn-downbeat-warn {
    font-size: 10px;
    color: var(--clr-text-muted);
    line-height: 1.4;
}
</style>
