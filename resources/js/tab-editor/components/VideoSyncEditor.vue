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
                <span class="sbn-vsync-label">Measure</span>
                <button class="sbn-btn sbn-btn-xs sbn-btn-secondary" @click="setTapCursor(Math.max(0, localTapCursor - 1))">−</button>
                <input
                    class="sbn-vsync-measure-input"
                    type="number"
                    :min="0"
                    :value="localTapCursor"
                    @input="setTapCursor(Math.max(0, parseInt($event.target.value) || 0))"
                />
                <button class="sbn-btn sbn-btn-xs sbn-btn-secondary" @click="setTapCursor(localTapCursor + 1)">+</button>
                <button class="sbn-btn sbn-btn-sm sbn-btn-primary" @click="markCurrent">
                    Mark ({{ localTapCursor + 1 }})
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
                v-for="m in sortedMappings"
                :key="m.measureIndex"
                class="sbn-vsync-table-row"
                :class="{ 'is-active': m.measureIndex === activeMappingIndex, 'is-tap-target': m.measureIndex === localTapCursor }"
                @click="seekToMappingRow(m)"
            >
                <span
                    class="sbn-vsync-bar-num"
                    :class="{ 'has-tempo-warning': hasTempoWarning(m) }"
                >{{ m.measureIndex + 1 }}</span>
                <span class="sbn-vsync-time-val">{{ formatTime(m.videoTime) }}</span>
                <button class="sbn-vsync-del-btn" @click.stop="removeMapping(m.measureIndex)" title="Remove">✕</button>
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
import VideoPlayer from './VideoPlayer.vue';

const props = defineProps({
    videoId:        { type: String,  default: '' },
    videoType:      { type: String,  default: 'youtube' },
    sortedMappings: { type: Array,   default: () => [] },
    videoTime:      { type: Number,  default: 0 },
    playerRef:      { type: Object,  default: null },
});

const emit = defineEmits([
    'set-video-id',
    'add-mapping',
    'remove-mapping',
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
const injectedTapCursor = inject('tapCursor', computed(() => 0));  // D2: shared tap cursor (computed, read-only)

// ── Local state ───────────────────────────────────────────────
const rawInput       = ref(props.videoId || '');
const player         = ref(null);
const playbackRate   = ref(1.0);
const localTapCursor = ref(0);

// Sync injected → local (one way, external changes update local)
watch(injectedTapCursor, (v) => { localTapCursor.value = v; }, { immediate: true });

// Helper to update local and emit
function setTapCursor(mi) {
    localTapCursor.value = mi;
    emit('tap-cursor-change', mi);
}

// Mirror the playerRef so useVideoSync can call seekTo()
watch(player, (p) => { emit('player-ref-change', p); }, { immediate: true });

const activeMappingIndex = computed(() => {
    if (!props.sortedMappings.length) return -1;
    const t = props.videoTime;
    // Find the last mapping whose videoTime <= current
    let best = -1;
    for (const m of props.sortedMappings) {
        if (m.videoTime <= t) best = m.measureIndex;
        else break;
    }
    return best;
});

// D2: Tempo warning detection (unreasonable beat duration between adjacent mappings)
function hasTempoWarning(m) {
    const idx = props.sortedMappings.findIndex(x => x.measureIndex === m.measureIndex);
    if (idx <= 0) return false;
    const prev = props.sortedMappings[idx - 1];
    const beats = m.measureIndex - prev.measureIndex;  // measures between markers
    const seconds = m.videoTime - prev.videoTime;
    const secsPerBeat = seconds / beats;
    // Flag if < 0.1s or > 5s per measure (roughly < 12 BPM or > 600 BPM at 4/4)
    return secsPerBeat < 0.1 || secsPerBeat > 5;
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

// D2: Tap mark at current cursor position
function markCurrent() {
    emit('add-mapping', { measureIndex: localTapCursor.value, videoTime: props.videoTime });
    // Auto-advance to next measure
    setTapCursor(localTapCursor.value + 1);
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
    // Seek video to marker time
    if (player.value) player.value.seekTo(m.videoTime);
    // Seek audio transport to measure
    if (seekToMeasure) seekToMeasure(m.measureIndex);
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

    // M: mark sync point at tap cursor, then advance
    if (e.key === 'm' || e.key === 'M') {
        emit('add-mapping', { measureIndex: localTapCursor.value, videoTime: props.videoTime });
        setTapCursor(localTapCursor.value + 1);
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
</style>
