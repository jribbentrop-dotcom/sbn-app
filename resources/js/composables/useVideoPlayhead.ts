import { ref, shallowRef, readonly, type Ref } from 'vue';

/**
 * useVideoPlayhead — shared video transport for the public frontend.
 *
 * Domain-free counterpart to the tab-editor's `useVideoSync` (which is
 * measure/gi-coupled and stays where it is). This composable owns nothing
 * but a clock: it wraps a `<VideoEmbed>` instance and exposes the YouTube
 * playhead in SECONDS, verbatim from `getCurrentTime()` — no conversion on
 * the wire, so no tempo drift. Each visual component converts seconds to its
 * own unit at its own edge (ChordProgressionViewer.chordIndexAtTime,
 * RhythmStrip.beatToStep, SheetMiniPlayer.expandMeasureSequence).
 *
 * Looping is enforced here in recording-native seconds: when the playhead
 * crosses `loopEndSec`, we seek back to `loopStartSec`. Keeping loop bounds
 * in YouTube's own unit makes them as precise as the source allows.
 *
 * Usage:
 *   const ph = useVideoPlayhead();
 *   <VideoEmbed :ref="ph.embedRef" :video-id="id"
 *               @timeupdate="ph.onTimeUpdate" @play-state-change="ph.onPlayStateChange" />
 *   // pass ph.playheadSec into a component's `videoPlayhead` prop
 */

/** Minimal surface this composable needs from the <VideoEmbed> instance. */
export interface VideoEmbedInstance {
    seekTo(seconds: number): void;
    getCurrentTime(): number;
    play(): void;
    pause(): void;
    setPlaybackRate?(rate: number): void;
}

export interface VideoLoop {
    /** Recording-time (seconds) the loop restarts at. */
    startSec: number;
    /** Recording-time (seconds) at which playback wraps back to startSec. */
    endSec: number;
}

export function useVideoPlayhead() {
    /** Bind to <VideoEmbed>'s ref; holds the exposed instance once mounted. */
    const embedRef = shallowRef<VideoEmbedInstance | null>(null);

    /** Current playhead, in seconds of the recording. The shared transport unit. */
    const playheadSec = ref(0);

    /** Whether the embedded video is currently playing. */
    const playing = ref(false);

    /** Active loop window, or null for straight-through playback. */
    const loop: Ref<VideoLoop | null> = ref(null);

    /**
     * Called by <VideoEmbed> on every timeupdate (rAF-paced, ~60 Hz).
     * Updates the playhead and enforces the loop boundary in seconds.
     */
    function onTimeUpdate(time: number): void {
        const lp = loop.value;
        if (lp && time >= lp.endSec) {
            // Wrap before publishing, so consumers never see a frame past the loop.
            embedRef.value?.seekTo(lp.startSec);
            playheadSec.value = lp.startSec;
            return;
        }
        playheadSec.value = time;
    }

    /** Called by <VideoEmbed> on play/pause state changes. */
    function onPlayStateChange(isPlaying: boolean): void {
        playing.value = isPlaying;
    }

    function play(): void {
        embedRef.value?.play();
    }

    function pause(): void {
        embedRef.value?.pause();
    }

    /** Seek to an absolute recording-time in seconds. */
    function seek(seconds: number): void {
        embedRef.value?.seekTo(seconds);
        playheadSec.value = seconds;
    }

    /** Set (or clear, with null) the loop window and jump to its start. */
    function setLoop(next: VideoLoop | null): void {
        loop.value = next;
        if (next) seek(next.startSec);
    }

    /** Playback rate passthrough (0.25–1.5×); no-op if the embed lacks it. */
    function setPlaybackRate(rate: number): void {
        embedRef.value?.setPlaybackRate?.(rate);
    }

    return {
        embedRef,
        playheadSec: readonly(playheadSec),
        playing: readonly(playing),
        loop: readonly(loop),
        onTimeUpdate,
        onPlayStateChange,
        play,
        pause,
        seek,
        setLoop,
        setPlaybackRate,
    };
}

export type VideoPlayhead = ReturnType<typeof useVideoPlayhead>;
