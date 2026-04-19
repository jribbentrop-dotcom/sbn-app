import { ref, computed, watch } from 'vue';

/**
 * useVideoSync — Phase D1 (Playback sync)
 *
 * Holds the videoSync data model and exposes bidirectional sync between
 * the audio transport and the YouTube video player.
 *
 * Data shape (stored in json_data.videoSync):
 *   { videoId, videoType, mappings: [{ measureIndex, videoTime }] }
 *
 * Bidirectional sync:
 *   Audio → video: watch playingMeasureIndex → interpolate videoTime → seekTo()
 *   Video → audio: VideoPlayer emits timeupdate → binary-search mappings → seekToMeasure()
 *
 * Tap-to-mark (D2 authoring) lives in VideoSyncEditor.vue and calls addMapping().
 * All mapping mutations go through useUndo.wrapCommand (Pattern A).
 */
export function useVideoSync(model, { wrapCommand, playingMeasureIndex, transportPlaying, beatsPerMeasure }) {

    // ── State ─────────────────────────────────────────────────
    const videoId      = ref('');
    const videoType    = ref('youtube'); // 'youtube' | 'hosted'
    const mappings     = ref([]);        // [{ measureIndex, videoTime }] sorted by measureIndex
    const sidebarOpen  = ref(false);     // independent of viewMode

    // Current video time (driven by VideoPlayer timeupdate, not transport)
    const videoTime    = ref(0);

    // Whether video is currently playing (set by VideoPlayer)
    const videoPlaying = ref(false);

    // Ref to VideoPlayer component instance (set by VideoSyncEditor)
    const playerRef    = ref(null);

    // ── Audio source switch ──────────────────────────────────
    const audioSource  = ref('synth');   // 'synth' | 'video'
    const isVideoMaster = computed(() => audioSource.value === 'video' && hasVideo.value);

    // ── D2 Authoring state ───────────────────────────────────
    const tapCursor    = ref(0);         // Current measure for tap-to-mark mode

    function setAudioSource(mode) {
        audioSource.value = mode === 'video' ? 'video' : 'synth';
    }

    // ── Derived ───────────────────────────────────────────────

    const hasVideo = computed(() => !!videoId.value);

    // Current measure index driven by YouTube time (null when video not playing or no mappings)
    // Fractional value for smooth cursor movement (0.0 = start of measure)
    const videoMeasureIndex = ref(null);

    // Global beat derived from video time (sub-beat resolution for chord/metronome highlight)
    const videoBeat = computed(() => {
        const mi = videoMeasureIndex.value;
        if (mi === null) return null;
        const bpm = beatsPerMeasure?.value ?? 4;
        return mi * bpm;
    });

    const sortedMappings = computed(() =>
        [...mappings.value].sort((a, b) => a.measureIndex - b.measureIndex)
    );

    // ── Serialization ─────────────────────────────────────────

    function getVideoSync() {
        return {
            videoId:     videoId.value,
            videoType:   videoType.value,
            mappings:    mappings.value.map(m => ({ ...m })),
            audioSource: audioSource.value,
        };
    }

    function setVideoSync(data) {
        if (!data) return;
        videoId.value   = data.videoId   ?? '';
        videoType.value = data.videoType ?? 'youtube';
        audioSource.value = data.audioSource ?? 'synth';
        mappings.value  = Array.isArray(data.mappings)
            ? data.mappings.map(m => ({ measureIndex: m.measureIndex, videoTime: m.videoTime }))
            : [];
    }

    // ── Interpolation helpers ─────────────────────────────────

    /**
     * Given a measureIndex, interpolate the video time from the sparse mappings array.
     * Returns null if no mappings exist.
     */
    function measureToVideoTime(measureIndex) {
        const ms = sortedMappings.value;
        if (!ms.length) return null;

        // Exact hit
        const exact = ms.find(m => m.measureIndex === measureIndex);
        if (exact) return exact.videoTime;

        // Before first marker
        if (measureIndex < ms[0].measureIndex) return ms[0].videoTime;

        // After last marker
        if (measureIndex > ms[ms.length - 1].measureIndex) return ms[ms.length - 1].videoTime;

        // Linear interpolation between adjacent markers
        for (let i = 0; i < ms.length - 1; i++) {
            const a = ms[i], b = ms[i + 1];
            if (measureIndex >= a.measureIndex && measureIndex <= b.measureIndex) {
                const t = (measureIndex - a.measureIndex) / (b.measureIndex - a.measureIndex);
                return a.videoTime + t * (b.videoTime - a.videoTime);
            }
        }
        return null;
    }

    /**
     * Given a video time (seconds), find the measure index via binary search.
     * Returns fractional measure index for smooth cursor movement.
     */
    function videoTimeToMeasureIndex(time) {
        const ms = sortedMappings.value;
        if (!ms.length) return null;

        if (time <= ms[0].videoTime) return ms[0].measureIndex;
        if (time >= ms[ms.length - 1].videoTime) return ms[ms.length - 1].measureIndex;

        let lo = 0, hi = ms.length - 1;
        while (lo < hi - 1) {
            const mid = (lo + hi) >> 1;
            if (ms[mid].videoTime <= time) lo = mid;
            else hi = mid;
        }
        // Interpolate measure index between lo and hi (fractional for smooth cursor)
        const a = ms[lo], b = ms[hi];
        const t = (time - a.videoTime) / (b.videoTime - a.videoTime);
        return a.measureIndex + t * (b.measureIndex - a.measureIndex);
    }

    /**
     * Given a video time (seconds), return the global beat position.
     */
    function videoTimeToBeat(time) {
        const mi = videoTimeToMeasureIndex(time);
        if (mi === null) return null;
        const bpm = beatsPerMeasure?.value ?? 4;
        return mi * bpm;
    }

    // ── Video → Audio sync (D1, 60fps via requestAnimationFrame) ─
    // When video is master, the rAF loop drives videoMeasureIndex/videoBeat,
    // which feed into transportBeat and playingMeasureIndex in TabEditor.
    // No continuous seekTo() calls — the video is the clock.

    /**
     * Called by VideoPlayer on timeupdate (60fps rAF loop).
     * Updates fractional videoMeasureIndex for smooth cursor movement.
     */
    function onVideoTimeUpdate(time) {
        videoTime.value = time;
        if (videoPlaying.value && isVideoMaster.value) {
            // Fractional measure index for smooth 60fps cursor
            videoMeasureIndex.value = videoTimeToMeasureIndex(time);
        }
    }

    function onVideoPlayStateChange(playing) {
        videoPlaying.value = playing;
        // Keep videoMeasureIndex at last known position on pause so resume seeks back correctly.
        // Only clear it on explicit reset (when videoTime is also reset to 0).
    }

    // ── Authoring ops (D2 — called from VideoSyncEditor via undo stack) ──

    function addMapping(measureIndex, videoTimeSec) {
        const snapshot = mappings.value.map(m => ({ ...m }));
        wrapCommand('Mark video sync', [measureIndex], () => {
            const idx = mappings.value.findIndex(m => m.measureIndex === measureIndex);
            if (idx >= 0) {
                mappings.value[idx].videoTime = videoTimeSec;
            } else {
                mappings.value.push({ measureIndex, videoTime: videoTimeSec });
            }
        });
    }

    function removeMapping(measureIndex) {
        wrapCommand('Remove video sync mark', [measureIndex], () => {
            const idx = mappings.value.findIndex(m => m.measureIndex === measureIndex);
            if (idx >= 0) mappings.value.splice(idx, 1);
        });
    }

    function clearMappings() {
        wrapCommand('Clear video sync marks', [], () => {
            mappings.value = [];
        });
    }

    /**
     * D2: Linearly interpolate mappings between first and last marked measures.
     * Fills in all unmarked measures between the extremes.
     */
    function distributeMarkers() {
        const sorted = sortedMappings.value;
        console.log('[distributeMarkers] sorted:', sorted.length, sorted);
        if (sorted.length < 2) return;
        const first = sorted[0], last = sorted[sorted.length - 1];
        console.log('[distributeMarkers] first:', first, 'last:', last);
        wrapCommand('Distribute video sync markers', [], () => {
            let added = 0;
            for (let mi = first.measureIndex + 1; mi < last.measureIndex; mi++) {
                if (mappings.value.find(m => m.measureIndex === mi)) continue;
                const t = (mi - first.measureIndex) / (last.measureIndex - first.measureIndex);
                const vt = first.videoTime + t * (last.videoTime - first.videoTime);
                console.log('[distributeMarkers] adding mapping for measure', mi, 'time', vt);
                mappings.value.push({ measureIndex: mi, videoTime: vt });
                added++;
            }
            console.log('[distributeMarkers] added', added, 'mappings');
        });
    }

    /**
     * D2: Remove the mapping at tapCursor-1 and rewind tapCursor.
     * Used for "un-tap" (Shift+M) during authoring.
     */
    function untap() {
        if (tapCursor.value === 0) return;
        tapCursor.value--;
        removeMapping(tapCursor.value);
    }

    function setVideoId(id, type = 'youtube') {
        videoId.value   = id;
        videoType.value = type;
    }

    return {
        // State
        videoId,
        videoType,
        mappings,
        sidebarOpen,
        videoTime,
        videoPlaying,
        playerRef,
        audioSource,
        tapCursor,           // D2: tap-to-mark cursor

        // Derived
        hasVideo,
        isVideoMaster,
        sortedMappings,
        videoMeasureIndex,
        videoBeat,

        // Serialization
        getVideoSync,
        setVideoSync,

        // Sync helpers
        measureToVideoTime,
        videoTimeToMeasureIndex,
        videoTimeToBeat,

        // Audio source switch
        setAudioSource,

        // Event handlers (called by VideoPlayer)
        onVideoTimeUpdate,
        onVideoPlayStateChange,

        // Authoring mutations
        addMapping,
        removeMapping,
        clearMappings,
        distributeMarkers,  // D2
        untap,              // D2
        setVideoId,
    };
}
