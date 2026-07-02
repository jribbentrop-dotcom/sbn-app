import { ref, computed, watch } from 'vue';
import { giAtPosition, firstPositionForGi } from '../../audio/adapters/expandMeasureSequence.js';

/**
 * useVideoSync — Phase D1 (Playback sync)
 *
 * Holds the videoSync data model and exposes bidirectional sync between
 * the audio transport and the YouTube video player.
 *
 * Data shape (stored in json_data.videoSync):
 *   { videoId, videoType, mappings: [{ measureIndex, videoTime }] }
 *
 *   mappings are keyed by measureIndex (gi) — what the author taps. Internally
 *   we project them onto **play positions** (the repeat-expanded timeline) so
 *   that interpolation lines up with the audio engine clock. A bar that repeats
 *   maps to its FIRST play position; the video genuinely replays those bars on
 *   the second pass, so the same mapping window is reused for each pass.
 *
 * Bidirectional sync:
 *   Audio → video: playingMeasureIndex (gi) → first play pos → interpolate videoTime → seekTo()
 *   Video → audio: VideoPlayer timeupdate → interpolate fractional play position → drives cursor
 *
 * Tap-to-mark (D2 authoring) lives in VideoSyncEditor.vue and calls addMapping().
 * All mapping mutations go through useUndo.wrapCommand (Pattern A).
 *
 * @param {object}   model
 * @param {object}   deps
 * @param {Function} deps.getSequence  — () => number[]  the expanded play sequence
 *                                        (play position → gi). Required for repeat-aware sync.
 */
export function useVideoSync(model, { wrapCommand, playingMeasureIndex, transportPlaying, beatsPerMeasure, getSequence }) {

    const _sequence = () => (typeof getSequence === 'function' ? (getSequence() || []) : []);

    // ── State ─────────────────────────────────────────────────
    const videoId          = ref('');
    const videoType        = ref('youtube'); // 'youtube' | 'hosted'
    const mappings         = ref([]);        // [{ measureIndex, videoTime }] sorted by measureIndex
    const videoTimeOffset  = ref(0);         // non-zero for sliced exercises: absolute video start time
    const sidebarOpen      = ref(false);     // independent of viewMode

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
    // tapCursor is a **play position** (index into the expanded sequence).
    // Mark→advance walks the expanded sequence so AABA works naturally: after
    // tapping through A1 (positions 0..7), the cursor sits at pos 8 = A2 bar 1,
    // and the next tap correctly marks A2 even though its gi is the same as
    // A1's bar 1.
    const tapCursor    = ref(0);

    function setAudioSource(mode) {
        audioSource.value = mode === 'video' ? 'video' : 'synth';
    }

    // ── Derived ───────────────────────────────────────────────

    const hasVideo = computed(() => !!videoId.value);

    // Current fractional **play position** driven by YouTube time (null when
    // video not playing or no mappings). Play position, NOT gi — feeds the
    // engine-clock-compatible transportBeat.
    const videoPlayPosition = ref(null);

    // Back-compat alias — older callers referenced `videoMeasureIndex`. It now
    // carries a play position; the cursor watcher in TabEditor maps it to a gi.
    const videoMeasureIndex = videoPlayPosition;

    // Global beat derived from video time (play-position based, matches engine clock).
    const videoBeat = computed(() => {
        const pos = videoPlayPosition.value;
        if (pos === null) return null;
        const bpm = beatsPerMeasure?.value ?? 4;
        return pos * bpm;
    });

    const sortedMappings = computed(() =>
        [...mappings.value].sort((a, b) => a.measureIndex - b.measureIndex || a.videoTime - b.videoTime)
    );

    /**
     * A single video-sync mark, per-pass, as exposed to the badge UI.
     * @typedef {object} VideoSyncMark
     * @property {number} videoTime  seconds into the video this bar sounds
     * @property {number} pass       1-based pass within the gi (earliest videoTime = pass 1)
     * @property {number} pos        play position in the expanded sequence this mark maps to
     * @property {number} mappingIdx index into the live `mappings.value` array — a stable
     *                               handle for edit/remove of this specific mapping
     */

    /**
     * Per-gi grouped view of mappings for the badge UI.
     * `pass` is 1-based within the gi (earliest videoTime = pass 1). `mappingIdx`
     * indexes into the live `mappings.value` array so callers can edit/remove a
     * specific mapping without ambiguity.
     * @type {import('vue').ComputedRef<Map<number, VideoSyncMark[]>>}
     */
    const mappingsByGi = computed(() => {
        const seq = _sequence();
        const positionsByGi = new Map();
        seq.forEach((gi, pos) => {
            if (!positionsByGi.has(gi)) positionsByGi.set(gi, []);
            positionsByGi.get(gi).push(pos);
        });

        // Tag each mapping with its index in mappings.value so we keep stable
        // handles even after sorting.
        const tagged = mappings.value.map((m, idx) => ({ ...m, mappingIdx: idx }));

        const byGi = new Map();
        for (const m of tagged) {
            if (!byGi.has(m.measureIndex)) byGi.set(m.measureIndex, []);
            byGi.get(m.measureIndex).push(m);
        }
        const out = new Map();
        for (const [gi, marks] of byGi.entries()) {
            marks.sort((a, b) => a.videoTime - b.videoTime);
            const positions = positionsByGi.get(gi) ?? [gi];
            out.set(gi, marks.map((m, k) => ({
                videoTime:  m.videoTime,
                pass:       k + 1,
                pos:        positions[Math.min(k, positions.length - 1)],
                mappingIdx: m.mappingIdx,
            })));
        }
        return out;
    });

    // Mappings projected onto play positions, sorted by position.
    //
    // A bar that appears at several positions in the expanded sequence (the
    // common case: AABA, where A1 and A2 cover the same bars at different
    // video timestamps) is allowed to have multiple mappings for the same gi.
    // We assign them to that gi's successive play positions in the order their
    // videoTimes occur — earliest videoTime → earliest play position. This
    // mirrors how a musician taps through the song: first pass marks land on
    // pass-1 positions, second pass marks on pass-2 positions, automatically.
    const mappingsByPosition = computed(() => {
        const seq = _sequence();
        if (!seq.length) {
            // No sequence yet — fall back to using gi as pos.
            return [...mappings.value]
                .map(m => ({ pos: m.measureIndex, videoTime: m.videoTime }))
                .sort((a, b) => a.pos - b.pos);
        }

        // Group mappings by gi, sorted within each group by videoTime ascending.
        const byGi = new Map();
        for (const m of mappings.value) {
            if (!byGi.has(m.measureIndex)) byGi.set(m.measureIndex, []);
            byGi.get(m.measureIndex).push(m);
        }
        for (const arr of byGi.values()) arr.sort((a, b) => a.videoTime - b.videoTime);

        // For each gi present in the sequence, walk its positions in order and
        // pair them up with that gi's mappings (in videoTime order). Surplus
        // mappings (more marks than play positions) all collapse onto the gi's
        // last play position — a pathological case (more taps than passes).
        const positionsByGi = new Map();
        seq.forEach((gi, pos) => {
            if (!positionsByGi.has(gi)) positionsByGi.set(gi, []);
            positionsByGi.get(gi).push(pos);
        });

        const out = [];
        for (const [gi, marks] of byGi.entries()) {
            const positions = positionsByGi.get(gi) ?? [firstPositionForGi(seq, gi)];
            for (let k = 0; k < marks.length; k++) {
                const pos = positions[Math.min(k, positions.length - 1)];
                out.push({ pos, videoTime: marks[k].videoTime });
            }
        }
        return out.sort((a, b) => a.pos - b.pos || a.videoTime - b.videoTime);
    });

    // ── Serialization ─────────────────────────────────────────

    function getVideoSync() {
        const offset = videoTimeOffset.value || 0;
        return {
            videoId:          videoId.value,
            videoType:        videoType.value,
            mappings:         mappings.value.map(m => ({
                measureIndex: m.measureIndex,
                videoTime:    m.videoTime - offset,
            })),
            audioSource:      audioSource.value,
            ...(offset ? { videoTimeOffset: offset } : {}),
        };
    }

    function setVideoSync(data) {
        if (!data) return;
        videoId.value   = data.videoId   ?? '';
        videoType.value = data.videoType ?? 'youtube';
        audioSource.value = data.audioSource ?? 'synth';
        const offset = typeof data.videoTimeOffset === 'number' ? data.videoTimeOffset : 0;
        videoTimeOffset.value = offset;
        mappings.value  = Array.isArray(data.mappings)
            ? data.mappings.map(m => ({ measureIndex: m.measureIndex, videoTime: m.videoTime + offset }))
            : [];
    }

    // ── Interpolation helpers ─────────────────────────────────
    // All interpolation runs in **play-position** space (mappingsByPosition);
    // gi-keyed wrappers convert at the boundary.

    /** play position → video time (seconds), linearly interpolated. null if no mappings. */
    function playPositionToVideoTime(pos) {
        const ms = mappingsByPosition.value;
        if (!ms.length) return null;
        if (pos <= ms[0].pos) return ms[0].videoTime;
        if (pos >= ms[ms.length - 1].pos) return ms[ms.length - 1].videoTime;
        for (let i = 0; i < ms.length - 1; i++) {
            const a = ms[i], b = ms[i + 1];
            if (pos >= a.pos && pos <= b.pos) {
                const t = b.pos === a.pos ? 0 : (pos - a.pos) / (b.pos - a.pos);
                return a.videoTime + t * (b.videoTime - a.videoTime);
            }
        }
        return null;
    }

    /**
     * gi → video time (seconds). Uses the bar's FIRST play position, so a
     * repeated bar always seeks to the start of its phrase.
     * Returns null if no mappings exist.
     */
    function measureToVideoTime(measureIndex) {
        const seq = _sequence();
        return playPositionToVideoTime(firstPositionForGi(seq, measureIndex));
    }

    /**
     * video time (seconds) → fractional **play position**, via binary search.
     * Fractional for smooth 60fps cursor movement. null if no mappings.
     */
    function videoTimeToPlayPosition(time) {
        const ms = mappingsByPosition.value;
        if (!ms.length) return null;
        if (time <= ms[0].videoTime) return ms[0].pos;
        if (time >= ms[ms.length - 1].videoTime) return ms[ms.length - 1].pos;
        let lo = 0, hi = ms.length - 1;
        while (lo < hi - 1) {
            const mid = (lo + hi) >> 1;
            if (ms[mid].videoTime <= time) lo = mid;
            else hi = mid;
        }
        const a = ms[lo], b = ms[hi];
        const t = b.videoTime === a.videoTime ? 0 : (time - a.videoTime) / (b.videoTime - a.videoTime);
        return a.pos + t * (b.pos - a.pos);
    }

    /** video time (seconds) → gi (which bar is showing). null if no mappings. */
    function videoTimeToMeasureIndex(time) {
        const pos = videoTimeToPlayPosition(time);
        if (pos === null) return null;
        return giAtPosition(_sequence(), pos);
    }

    /** video time (seconds) → global beat (play-position based, matches engine clock). */
    function videoTimeToBeat(time) {
        const pos = videoTimeToPlayPosition(time);
        if (pos === null) return null;
        const bpm = beatsPerMeasure?.value ?? 4;
        return pos * bpm;
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
            // Fractional play position for smooth 60fps cursor.
            videoPlayPosition.value = videoTimeToPlayPosition(time);
        }
    }

    function onVideoPlayStateChange(playing) {
        videoPlaying.value = playing;
        // Keep videoMeasureIndex at last known position on pause so resume seeks back correctly.
        // Only clear it on explicit reset (when videoTime is also reset to 0).
    }

    // ── Authoring ops (D2 — called from VideoSyncEditor via undo stack) ──

    /**
     * Add a sync mark. Always appends a new entry — duplicates per gi are
     * allowed (and required, for repeated bars with per-pass video timestamps).
     * If the user wants to *replace* a specific existing mark, call
     * updateMappingAt() with the mappingIdx from mappingsByGi.
     */
    function addMapping(measureIndex, videoTimeSec) {
        wrapCommand('Mark video sync', [measureIndex], () => {
            mappings.value.push({ measureIndex, videoTime: videoTimeSec });
        });
    }

    /**
     * Replace the videoTime of a specific mapping (identified by its index in
     * the live mappings array — get this from mappingsByGi[gi][k].mappingIdx).
     */
    function updateMappingAt(mappingIdx, videoTimeSec) {
        if (mappingIdx == null || mappingIdx < 0 || mappingIdx >= mappings.value.length) return;
        const gi = mappings.value[mappingIdx].measureIndex;
        wrapCommand('Update video sync mark', [gi], () => {
            mappings.value[mappingIdx].videoTime = videoTimeSec;
        });
    }

    /**
     * Remove a specific mapping by its index in the live mappings array.
     */
    function removeMappingAt(mappingIdx) {
        if (mappingIdx == null || mappingIdx < 0 || mappingIdx >= mappings.value.length) return;
        const gi = mappings.value[mappingIdx].measureIndex;
        wrapCommand('Remove video sync mark', [gi], () => {
            mappings.value.splice(mappingIdx, 1);
        });
    }

    /**
     * Remove a sync mark by gi. If the gi has multiple marks (a repeated bar),
     * removes the LATEST one (highest videoTime). For ambiguous cases prefer
     * removeMappingAt().
     */
    function removeMapping(measureIndex) {
        wrapCommand('Remove video sync mark', [measureIndex], () => {
            let lastIdx = -1;
            let lastTime = -Infinity;
            for (let i = 0; i < mappings.value.length; i++) {
                const m = mappings.value[i];
                if (m.measureIndex === measureIndex && m.videoTime > lastTime) {
                    lastIdx = i;
                    lastTime = m.videoTime;
                }
            }
            if (lastIdx >= 0) mappings.value.splice(lastIdx, 1);
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

    /** gi the tap cursor currently points at — display only. */
    const tapCursorGi = computed(() => {
        const seq = _sequence();
        return seq.length ? giAtPosition(seq, tapCursor.value) : tapCursor.value;
    });

    /**
     * D2: Tap-to-mark — record `videoTime` against the gi the cursor is
     * pointing at (= seq[tapCursor]), then advance the cursor by one play
     * position. Always APPENDS — never replaces an existing mapping, so a bar
     * that occurs at multiple play positions gets one mark per pass.
     */
    function tapMark(videoTimeSec) {
        const seq = _sequence();
        const pos = tapCursor.value;
        const gi  = seq.length ? giAtPosition(seq, pos) : pos;
        addMapping(gi, videoTimeSec);
        // Advance, clamped to the end of the sequence (or unbounded when no seq).
        const max = seq.length ? seq.length : pos + 2;
        tapCursor.value = Math.min(max, pos + 1);
    }

    /**
     * D2: Un-tap — rewind the cursor and remove the mapping it just placed.
     * Pops the last-pushed mapping (which is the one tapMark inserted), so
     * untapping is order-correct even when multiple bars share a gi.
     */
    function untap() {
        if (tapCursor.value === 0 && mappings.value.length === 0) return;
        wrapCommand('Un-tap video sync mark', [], () => {
            if (tapCursor.value > 0) tapCursor.value--;
            if (mappings.value.length) mappings.value.pop();
        });
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
        tapCursor,           // D2: tap-to-mark cursor (play position)
        tapCursorGi,         // D2: gi the cursor points at (display only)

        // Derived
        hasVideo,
        isVideoMaster,
        sortedMappings,
        mappingsByPosition,
        mappingsByGi,        // for badge UI (gi → per-pass mark list)
        videoPlayPosition,
        videoMeasureIndex,   // back-compat alias for videoPlayPosition
        videoBeat,

        // Serialization
        getVideoSync,
        setVideoSync,

        // Sync helpers
        measureToVideoTime,
        playPositionToVideoTime,
        videoTimeToMeasureIndex,
        videoTimeToPlayPosition,
        videoTimeToBeat,

        // Audio source switch
        setAudioSource,

        // Event handlers (called by VideoPlayer)
        onVideoTimeUpdate,
        onVideoPlayStateChange,

        // Authoring mutations
        addMapping,
        updateMappingAt,
        removeMappingAt,
        removeMapping,
        clearMappings,
        distributeMarkers,  // D2
        tapMark,            // D2: append-mark + advance cursor (pos-aware)
        untap,              // D2
        setVideoId,
    };
}
