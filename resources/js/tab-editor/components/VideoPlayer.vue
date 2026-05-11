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
            </div>
            <div v-if="!playerReady" class="sbn-video-loading">Loading…</div>
        </template>

        <template v-else>
            <!-- Hosted video (future) -->
            <video
                ref="videoEl"
                class="sbn-video-hosted"
                :src="videoId"
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
});

const emit = defineEmits(['timeupdate', 'play-state-change', 'ready']);

// ── Refs ─────────────────────────────────────────────────────
const ytContainer = ref(null);
const videoEl     = ref(null);
const playerReady = ref(false);
const containerId = `sbn-yt-${Math.random().toString(36).slice(2, 8)}`;

let _ytPlayer  = null;
let _rafId     = null;

// ── Computed ─────────────────────────────────────────────────

const iframeWrapStyle = computed(() => ({
    position: 'relative',
    width: '100%',
    paddingTop: props.aspectRatio === '16/9' ? '56.25%' : '75%',
}));

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

    destroyPlayer();

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
    // Reposition the iframe so it fills its container absolutely
    const iframe = ytContainer.value?.querySelector('iframe');
    if (iframe) {
        iframe.style.cssText = 'position:absolute;top:0;left:0;width:100%;height:100%;border:none;';
    }
    emit('ready');
    startPolling();
}

function onYTStateChange(event) {
    const YT_PLAYING = 1;
    const playing = event.data === YT_PLAYING;
    emit('play-state-change', playing);
    if (!playing) stopPolling();
    else startPolling();
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
}

// ── Public API ────────────────────────────────────────────────

function seekTo(seconds) {
    if (props.videoType === 'youtube') {
        if (_ytPlayer && typeof _ytPlayer.seekTo === 'function') {
            _ytPlayer.seekTo(seconds, true);
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
    if (props.videoType === 'youtube') _ytPlayer?.playVideo?.();
    else videoEl.value?.play();
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
    if (props.videoId && props.videoType === 'youtube') initYTPlayer();
});

onUnmounted(() => {
    destroyPlayer();
});

watch(() => props.videoId, (newId) => {
    if (newId && props.videoType === 'youtube') initYTPlayer();
    else destroyPlayer();
});

watch(() => props.videoType, () => {
    destroyPlayer();
    if (props.videoId && props.videoType === 'youtube') initYTPlayer();
});

// ── Expose for parent (useVideoSync playerRef) ────────────────
defineExpose({ seekTo, getCurrentTime, play, pause, setPlaybackRate });
</script>
