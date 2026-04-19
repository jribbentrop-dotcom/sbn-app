# Phase D1 — Video-master refactor spec (for Sonnet)

> **Goal:** Soundslice-style playback. When a video is loaded AND a "video audio" mode is active, the **YouTube player is the clock**: it supplies the audio and drives the score cursor. The synth engine does not run. When the user switches back to "synth audio" mode, the video is inert (silent, paused) and the synth engine drives everything as it did before Phase D.
>
> **Symptom this fixes:** Play stutters because `useVideoSync.js` calls `player.seekTo()` every time the measure changes, forcing YouTube to re-decode between seeks. Score cursor stops updating because `playingMeasureIndex` is wired to the synth `transportBeat`, which is silent when the video is playing.

---

## 1. New audio-source switch

Add an `audioSource` ref with two values: `'synth' | 'video'`. Default `'synth'`. User-facing toggle lives next to the transport bar (or in `VideoSyncEditor.vue` header) — a simple two-state button: **🎹 Synth** / **📹 Video audio**. Disable the Video option when `!hasVideo`.

Persist `audioSource` inside `videoSync` (so it round-trips through `json_data`), but default to `'synth'` when deserializing a leadsheet that has no video.

```js
// useVideoSync.js — add:
const audioSource = ref('synth');          // 'synth' | 'video'
const isVideoMaster = computed(() => audioSource.value === 'video' && hasVideo.value);
function setAudioSource(mode) { audioSource.value = mode === 'video' ? 'video' : 'synth'; }

// getVideoSync() → include audioSource
// setVideoSync() → read data.audioSource ?? 'synth'
```

Expose `audioSource`, `isVideoMaster`, `setAudioSource` from the composable.

---

## 2. Rip out the broken audio→video seek watcher

In `useVideoSync.js`, **delete lines 122–138** (the `watch([playingMeasureIndex, transportPlaying], …)` and its companion reset watcher). This was the stutter source.

`measureToVideoTime` stays — it's still used for initial seek on Play and for click-to-seek.

---

## 3. rAF loop drives `videoMeasureIndex` at sub-beat resolution

Replace the 250ms `setInterval` polling in `VideoPlayer.vue` with a `requestAnimationFrame` loop. On each frame while the video is playing, emit `timeupdate` with `player.getCurrentTime()`. This gives 60fps updates so the score cursor moves smoothly.

```js
// VideoPlayer.vue — replace startPolling/stopPolling:
let _rafId = null;
function startPolling() {
    stopPolling();
    const tick = () => {
        if (_ytPlayer?.getCurrentTime) {
            emit('timeupdate', _ytPlayer.getCurrentTime());
        }
        _rafId = requestAnimationFrame(tick);
    };
    _rafId = requestAnimationFrame(tick);
}
function stopPolling() {
    if (_rafId) { cancelAnimationFrame(_rafId); _rafId = null; }
}
```

Then in `useVideoSync.js`, extend `videoTimeToMeasureIndex` to return a **fractional** measure index (don't `Math.round` it), and add a parallel `videoTimeToBeat(time)` that returns the global beat (measureIndex × beatsPerMeasure + fractionalPartInBeats). The current function rounds to nearest integer — keep it for the binary-search authoring use case but expose the fractional version for the cursor.

Store the fractional index on a new ref `videoFractionalMeasureIndex` (or just rename `videoMeasureIndex` and let `playingMeasureIndex` take `Math.floor` of it). The chord/tab highlight watchers already use `transportBeat` for sub-measure work, so we also need to feed a beat value — see next section.

---

## 4. Route the video clock into `transportBeat` and `playingMeasureIndex`

In `TabEditor.vue`, the two provides that drive all downstream visuals are:

- `transportBeat` (used for sub-measure chord/tab highlight, metronome column)
- `playingMeasureIndex` (used for measure-level cursor)

Rewrite the `transportBeat` computed and the `playingMeasureIndex` watcher so they branch on `videoSync.isVideoMaster.value`:

```js
// TabEditor.vue — replace current transportBeat + the watcher at line 643-651
const transportBeat = computed(() => {
    if (videoSync.isVideoMaster.value) {
        return videoSync.videoBeat.value ?? 0;        // new ref from useVideoSync
    }
    if (isPlaying.value)      return currentBeat.value;
    if (isChordPlaying.value) return chordCurrentBeat.value;
    return currentBeat.value || chordCurrentBeat.value;
});

const transportPlaying = computed(() => {
    if (videoSync.isVideoMaster.value) return videoSync.videoPlaying.value;
    return isPlaying.value || isChordPlaying.value;
});

// playingMeasureIndex — always floor(transportBeat / beatsPerMeasure)
watch([transportBeat, beatsPerMeasure], ([beat, bpm]) => {
    playingMeasureIndex.value = Math.floor((beat ?? 0) / bpm);
});
```

Add `videoBeat` to `useVideoSync`: a computed that converts `videoTime.value` → global beat using the mappings and `beatsPerMeasure`. This is the single number that drives everything downstream.

---

## 5. Transport button: play/pause dispatches to the right engine

The existing `onTransportToggle` already branches on `videoSidebarOpen && hasVideo`. Change that condition to `videoSync.isVideoMaster.value` so the branch is driven by the audio-source switch, not by whether the sidebar happens to be open. The sidebar can be closed and the user can still have "Video audio" active.

```js
async function onTransportToggle() {
    if (videoSync.isVideoMaster.value) {
        if (videoSync.videoPlaying.value) {
            videoSync.playerRef.value?.pause();
        } else {
            // Seek to the current editor position once, then play. After this,
            // the rAF loop drives the cursor — no more seekTo() calls.
            const t = videoSync.measureToVideoTime(playingMeasureIndex.value);
            if (t !== null) videoSync.playerRef.value?.seekTo(t);
            videoSync.playerRef.value?.play();
        }
        return;
    }
    // …existing synth branch unchanged
}
```

Same for `onTransportReset` — branch on `isVideoMaster`, not `videoSidebarOpen`.

`seekToMeasure(gi)` needs a third branch: when `isVideoMaster`, call `playerRef.seekTo(measureToVideoTime(gi))` **once** (debounce isn't needed — a single seek per click is fine, YouTube only stutters on continuous seek-while-playing).

---

## 6. Switching mode mid-session

When `setAudioSource` flips:

- **synth → video:** If synth is playing, pause it (`pauseTab()`, `pauseChord()`). Do not auto-start the video.
- **video → synth:** If video is playing, pause it (`playerRef.pause()`). Do not auto-start synth. Synth's `currentBeat` should be seeded from the current `playingMeasureIndex` so Play resumes from the same visual spot — call `seekTab(gi * beatsPerMeasure)` and `seekChord(...)` on switch.

Put this logic in a watcher on `videoSync.audioSource` inside `TabEditor.vue`.

---

## 7. UI placement of the switch

Two options — pick one, not both:

- **Option A (preferred):** Small two-state button in the transport bar next to Play/Stop. Always visible, disabled when `!hasVideo`. Matches Soundslice exactly.
- **Option B:** In `VideoSyncEditor.vue` header. Less discoverable but keeps transport bar clean.

Go with A. File: `components/TransportBar.vue`. Emit `audio-source-change` up to `TabEditor.vue`, which calls `videoSync.setAudioSource(...)`.

---

## 8. Acceptance criteria

1. Load a leadsheet with video + mappings, switch to Video audio, press Play → YouTube plays continuously (no stutter), score cursor + chord highlight + metronome column follow smoothly at 60fps.
2. Press Pause → both video and cursor freeze; the ⏯ button reflects paused state.
3. Click a measure while Video audio is active → video seeks to that measure's mapped time (one seek, not continuous), playback state unchanged.
4. Switch to Synth audio → video stops, synth is silent until user presses Play; Play starts synth from the current `playingMeasureIndex`.
5. Switch back to Video audio mid-song → synth stops; video position is re-synced from current measure on next Play.
6. Save → reload: `audioSource` preference round-trips through `json_data.videoSync`.

---

## 9. Files touched

- `resources/js/tab-editor/composables/useVideoSync.js` — delete lines 122-138, add `audioSource`, `isVideoMaster`, `videoBeat`, `setAudioSource`, update `getVideoSync`/`setVideoSync`.
- `resources/js/tab-editor/components/VideoPlayer.vue` — swap `setInterval` for `requestAnimationFrame`.
- `resources/js/tab-editor/TabEditor.vue` — rewrite `transportBeat`/`transportPlaying` computeds + `playingMeasureIndex` watcher to branch on `isVideoMaster`; update `onTransportToggle`, `onTransportReset`, `seekToMeasure`; add mode-switch watcher.
- `resources/js/tab-editor/components/TransportBar.vue` — add audio-source toggle button.

No DB migration needed — `videoSync.audioSource` rides inside the existing `json_data` blob.
