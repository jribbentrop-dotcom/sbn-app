<template>
    <div class="sbn-video-player" :class="{ 'sbn-video-player--loaded': playerReady }">
        <div v-if="!videoId" class="sbn-video-player-empty">
            <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                 stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round">
                <polygon points="5 3 19 12 5 21 5 3"/>
            </svg>
            <span>No video linked</span>
        </div>

        <template v-else-if="videoType === 'youtube'">
            <!-- YouTube iframe — loaded lazily so autoplay policy isn't triggered until needed -->
            <div class="sbn-video-iframe-wrap" :style="iframeWrapStyle">
                <div :id="containerId" ref="ytContainer"></div>

                <!-- Facade: a static thumbnail shown until the first play.
                     A never-cued player never shows YouTube's cued-state
                     title/channel overlay (the ~5s card). The genuine player
                     loads intact on play, so this stays within the API terms
                     (it defers the player, it doesn't obscure a live one). -->
                <button
                    v-if="facade && !playerStarted"
                    type="button"
                    class="sbn-video-facade"
                    :style="{ backgroundImage: `url(${thumbnailUrl})` }"
                    aria-label="Play video"
                    @click="upgradeAndPlay"
                >
                    <span class="sbn-video-facade-play">
                        <svg viewBox="0 0 24 24" fill="currentColor" width="28" height="28">
                            <path d="M8 5v14l11-7z" />
                        </svg>
                    </span>
                </button>
            </div>
            <div v-if="!facade && !playerReady" class="sbn-video-loading">Loading…</div>
        </template>

        <template v-else>
            <!-- Hosted video (future) -->
            <video
                ref="videoEl"
                class="sbn-video-hosted"
                :src="videoId"
                :muted="muted"
                @timeupdate="onTimeUpdate"
                @play="onPlay"
                @pause="onPause"
                @seeked="onSeeked"
            ></video>
        </template>
    </div>
</template>

<script setup>
import { ref, watch, onMounted, onUnmounted, computed } from 'vue';

const props = defineProps({
    videoId:   { type: String, default: '' },
    videoType: { type: String, default: 'youtube' },
    aspectRatio: { type: String, default: '16/9' },
    /**
     * Facade mode: show a static thumbnail and defer loading the real player
     * until the first play. A never-cued player skips YouTube's cued-state
     * title/channel overlay. Opt-in (sidebar use) — leave off where the native
     * player should be interactive from the start.
     */
    facade: { type: Boolean, default: false },
    /**
     * Recording-time (seconds) to start playback from when the facade is
     * clicked — the snippet anchor. Keeps the first frame on the loop.
     */
    startSec: { type: Number, default: 0 },
    /**
     * Mute the video's own audio — used when a Cinema backing-track pair is
     * driving playback instead (see useBackingTrack.js). Applied on both the
     * hosted <video> element and, once ready, the YouTube player.
     */
    muted: { type: Boolean, default: false },
});

const emit = defineEmits(['timeupdate', 'play-state-change', 'ready', 'genuinely-playing']);

// ── Refs ─────────────────────────────────────────────────────
const ytContainer = ref(null);
const videoEl     = ref(null);
const playerReady = ref(false);
// True once the real player has been created (facade dismissed). In non-facade
// mode the player is created on mount, so this tracks playerReady.
const playerStarted = ref(false);
const containerId = `sbn-yt-${Math.random().toString(36).slice(2, 8)}`;

let _ytPlayer  = null;
let _rafId     = null;
// When the facade is upgraded, play as soon as the player is ready.
let _playOnReady = false;
// Optional seek target to apply on ready (overrides props.startSec).
let _seekOnReady = null;

// ── Computed ─────────────────────────────────────────────────

const iframeWrapStyle = computed(() => ({
    position: 'relative',
    width: '100%',
    paddingTop: props.aspectRatio === '16/9' ? '56.25%' : '75%',
}));

// hqdefault is the most reliably-present thumbnail size across all videos.
const thumbnailUrl = computed(() =>
    props.videoId ? `https://i.ytimg.com/vi/${props.videoId}/hqdefault.jpg` : '',
);

// ── YouTube IFrame API ────────────────────────────────────────

function loadYTApi() {
    if (window.YT && window.YT.Player) return Promise.resolve();
    return new Promise((resolve) => {
        if (window._sbnYtApiLoading) {
            window._sbnYtApiCallbacks = window._sbnYtApiCallbacks || [];
            window._sbnYtApiCallbacks.push(resolve);
            return;
        }
        window._sbnYtApiLoading = true;
        const tag = document.createElement('script');
        tag.src = 'https://www.youtube.com/iframe_api';
        document.head.appendChild(tag);
        window.onYouTubeIframeAPIReady = () => {
            resolve();
            (window._sbnYtApiCallbacks || []).forEach(fn => fn());
            window._sbnYtApiCallbacks = [];
        };
    });
}

async function initYTPlayer() {
    if (!props.videoId || props.videoType !== 'youtube') return;
    await loadYTApi();
    if (!ytContainer.value) return;

    // destroyPlayer clears _playOnReady; preserve a pending request across it
    // so a facade upgrade still autoplays once the fresh player is ready.
    const pendingPlay = _playOnReady;
    destroyPlayer();
    _playOnReady = pendingPlay;

    _ytPlayer = new window.YT.Player(ytContainer.value, {
        videoId: props.videoId,
        width: '100%',
        height: '100%',
        playerVars: {
            playsinline: 1,
            controls: 0,
            rel: 0,
            modestbranding: 1,
            iv_load_policy: 3,
            disablekb: 1,
        },
        events: {
            onReady: onYTReady,
            onStateChange: onYTStateChange,
        },
    });
}

function onYTReady() {
    playerReady.value = true;
    playerStarted.value = true;
    console.log('[VideoEmbed] onYTReady', { muted: props.muted, isMutedNow: _ytPlayer?.isMuted?.() });
    if (props.muted) applyMuted(true);
    console.log('[VideoEmbed] after mute() call (sync check)', { isMutedNow: _ytPlayer?.isMuted?.() });
    setTimeout(() => {
        console.log('[VideoEmbed] mute() check +500ms', { isMutedNow: _ytPlayer?.isMuted?.(), volume: _ytPlayer?.getVolume?.() });
    }, 500);
    // Iframe sizing is handled by the scoped <style> block — no JS layout here.
    emit('ready');
    startPolling();
    // Facade was clicked (or play() was called before the player existed):
    // seek to the snippet anchor and start now that the player is live.
    if (_playOnReady) {
        _playOnReady = false;
        const seekTo = _seekOnReady ?? (props.startSec > 0 ? props.startSec : null);
        _seekOnReady = null;
        if (seekTo != null) _ytPlayer?.seekTo?.(seekTo, true);
        _ytPlayer?.playVideo?.();
    }
}

function onYTStateChange(event) {
    // YT states: -1 unstarted, 0 ended, 1 playing, 2 paused, 3 buffering,
    // 5 cued. Buffering is transient — it fires on every loop seek — so it
    // must NOT be reported as a pause, or a looping snippet stops itself.
    const YT_PLAYING = 1, YT_PAUSED = 2, YT_ENDED = 0, YT_BUFFERING = 3;
    const s = event.data;

    if (s === YT_ENDED) {
        // Reaching the video's real end shows YouTube's end-screen (related-
        // video grid). Snippets normally loop-wrap at endSec well before this,
        // but a snippet with no endSec — or one that overruns — can hit it.
        // Seek back to the anchor and keep playing so the end-screen never
        // renders. This is plain playback control via the documented API.
        _ytPlayer?.seekTo?.(props.startSec || 0, true);
        _ytPlayer?.playVideo?.();
        return;
    }
    if (s === YT_PLAYING || s === YT_BUFFERING) {
        // Treat buffering as "still playing" — keep polling, report playing.
        emit('play-state-change', true);
        // Buffering-as-playing is right for the transport/UI state, but wrong
        // for anything that starts a clock keyed to "the video is genuinely
        // advancing" — e.g. Cinema's backing-track buffers, which otherwise
        // start during a BUFFERING event (video not yet moving) and end up
        // running ahead of the video once the real PLAYING event lands a
        // moment later. Only state === 1 (true playing) fires this one.
        if (s === YT_PLAYING) emit('genuinely-playing');
        startPolling();
    } else if (s === YT_PAUSED) {
        emit('play-state-change', false);
        stopPolling();
    }
    // -1 (unstarted) and 5 (cued) are ignored — no transport change.
}

// Poll getCurrentTime via requestAnimationFrame for 60fps smooth cursor
function startPolling() {
    stopPolling();
    const tick = () => {
        if (_ytPlayer && typeof _ytPlayer.getCurrentTime === 'function') {
            emit('timeupdate', _ytPlayer.getCurrentTime());
        }
        _rafId = requestAnimationFrame(tick);
    };
    _rafId = requestAnimationFrame(tick);
}

function stopPolling() {
    if (_rafId) { cancelAnimationFrame(_rafId); _rafId = null; }
}

function destroyPlayer() {
    stopPolling();
    if (_ytPlayer) {
        try { _ytPlayer.destroy(); } catch (e) {}
        _ytPlayer = null;
    }
    playerReady.value = false;
    playerStarted.value = false;
    _playOnReady = false;
}

/**
 * Facade click / first play: create the real player and play once it's ready.
 * In facade mode the player isn't created on mount, so this is the path that
 * brings it to life. Safe to call when the player already exists.
 */
function upgradeAndPlay() {
    if (_ytPlayer) {
        if (props.startSec > 0) _ytPlayer.seekTo?.(props.startSec, true);
        _ytPlayer.playVideo?.();
        return;
    }
    _playOnReady = true;
    initYTPlayer();
}

// ── Public API ────────────────────────────────────────────────

function seekTo(seconds) {
    if (props.videoType === 'youtube') {
        if (_ytPlayer && typeof _ytPlayer.seekTo === 'function') {
            _ytPlayer.seekTo(seconds, true);
        } else {
            // Player not ready yet — queue for when it becomes ready.
            _seekOnReady = seconds;
        }
    } else if (videoEl.value) {
        videoEl.value.currentTime = seconds;
    }
}

function getCurrentTime() {
    if (props.videoType === 'youtube') {
        return _ytPlayer?.getCurrentTime?.() ?? 0;
    }
    return videoEl.value?.currentTime ?? 0;
}

function play() {
    if (props.videoType === 'youtube') {
        // Facade mode: the player may not exist yet (external transport hit
        // play before a facade click). upgradeAndPlay creates it then plays.
        if (!_ytPlayer) upgradeAndPlay();
        else _ytPlayer.playVideo?.();
    } else {
        videoEl.value?.play();
    }
}

function pause() {
    if (props.videoType === 'youtube') _ytPlayer?.pauseVideo?.();
    else videoEl.value?.pause();
}

/**
 * D2: Set playback rate (0.25× – 1.5×)
 * YouTube: uses setPlaybackRate(), Hosted: uses playbackRate property
 */
function setPlaybackRate(rate) {
    if (props.videoType === 'youtube') {
        if (_ytPlayer && typeof _ytPlayer.setPlaybackRate === 'function') {
            _ytPlayer.setPlaybackRate(rate);
        }
    } else if (videoEl.value) {
        videoEl.value.playbackRate = rate;
    }
}

// ── Hosted video handlers ────────────────────────────────────

function onTimeUpdate() {
    emit('timeupdate', videoEl.value?.currentTime ?? 0);
}
function onPlay()   { emit('play-state-change', true);  }
function onPause()  { emit('play-state-change', false); }
function onSeeked() { emit('timeupdate', videoEl.value?.currentTime ?? 0); }

// ── Lifecycle ─────────────────────────────────────────────────

onMounted(() => {
    // Facade mode defers the player until the thumbnail is clicked / play()
    // is called — that's what skips the cued-state overlay.
    if (props.videoId && props.videoType === 'youtube' && !props.facade) initYTPlayer();
});

onUnmounted(() => {
    destroyPlayer();
});

watch(() => props.videoId, (newId) => {
    // New video: drop the old player and return to the facade (if enabled).
    destroyPlayer();
    if (newId && props.videoType === 'youtube' && !props.facade) initYTPlayer();
});

watch(() => props.videoType, () => {
    destroyPlayer();
    if (props.videoId && props.videoType === 'youtube') initYTPlayer();
});

watch(() => props.muted, (m) => {
    if (props.videoType === 'youtube') {
        applyMuted(m);
    } else if (videoEl.value) {
        videoEl.value.muted = m;
    }
});

/**
 * mute()/unMute() go over postMessage to the iframe — isMuted() can briefly
 * still report the old state right after the call returns (not a real
 * failure, just async catch-up), but occasionally the call is dropped
 * entirely if it lands before the player's internal init has finished. A
 * couple of retries over the next second makes this reliable either way.
 */
function applyMuted(m) {
    const apply = () => { if (m) _ytPlayer?.mute?.(); else _ytPlayer?.unMute?.(); };
    apply();
    [150, 400, 900].forEach(delay => setTimeout(() => {
        if (_ytPlayer?.isMuted?.() !== m) apply();
    }, delay));
}

// ── Expose for parent (useVideoSync playerRef) ────────────────
defineExpose({ seekTo, getCurrentTime, play, pause, setPlaybackRate });
</script>

<style scoped>
/* Self-contained styling — VideoEmbed is used on both Blade pages (where
   leadsheets.css is present) and Inertia/Vue pages (where it is not), so it
   must not depend on any external stylesheet. */
.sbn-video-player {
    width: 100%;
    background: #000;
    border-radius: 8px;
    overflow: hidden;
    position: relative;
}
.sbn-video-player-empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
    padding: 24px 12px;
    color: rgba(255, 255, 255, 0.6);
    font-size: 12px;
}
.sbn-video-iframe-wrap {
    width: 100%;
}
/* The YouTube API mounts an <iframe> inside #ytContainer. Position both the
   container and its iframe absolutely from the start so they fill the
   aspect-ratio box immediately — not only after onReady runs. This is what
   removes the whitespace above the video. */
.sbn-video-iframe-wrap > div {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
}
.sbn-video-iframe-wrap :deep(iframe) {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    border: 0;
}
.sbn-video-loading {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-size: 12px;
    background: rgba(0, 0, 0, 0.5);
}
.sbn-video-hosted {
    width: 100%;
    display: block;
}

/* Facade: static thumbnail shown until first play. Covers the iframe box so
   the cued-state player (and its title/channel overlay) is never visible. */
.sbn-video-facade {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    padding: 0;
    border: 0;
    cursor: pointer;
    background-color: #000;
    background-size: cover;
    background-position: center;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: filter 0.15s ease;
}
.sbn-video-facade:hover { filter: brightness(1.08); }
.sbn-video-facade-play {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 54px;
    height: 54px;
    border-radius: 999px;
    background: rgba(0, 0, 0, 0.7);
    color: #fff;
    box-shadow: 0 2px 10px rgba(0, 0, 0, 0.4);
    transition: transform 0.15s ease, background 0.15s ease;
}
.sbn-video-facade:hover .sbn-video-facade-play {
    transform: scale(1.08);
    background: rgba(0, 0, 0, 0.85);
}
.sbn-video-facade-play svg { margin-left: 3px; } /* optical centering */
</style>
