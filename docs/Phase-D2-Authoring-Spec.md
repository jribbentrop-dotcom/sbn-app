# Phase D2 — Video sync authoring (spec for Sonnet)

> **Status going in:** Basic tap-to-mark exists in `VideoSyncEditor.vue` (M key, mapping table, fine-tune slider, time input). D1 playback is solid (video-master rAF driving the score cursor).
>
> **Goal of D2:** Make authoring *fast and forgiving* so a user can sync a 4-minute song in under 2 minutes and correct mistakes without starting over. Soundslice's authoring UX is the north star.

---

## 1. Auto-follow tap mode (the headline feature)

Current Tap Mode requires the user to manually advance `tapMeasureIndex` or rely on auto-increment from the Mark button — so if they misclick or the video is ahead of the score, the counter desyncs.

**New behaviour — "Follow-along" tap mode:**

- User presses Play. Score cursor sits at measure 0 (or wherever they seek to).
- User taps <kbd>M</kbd> (or spacebar alt, TBD) at each measure's downbeat **while the video plays**.
- Each tap: records `{ measureIndex: tapCursor, videoTime: currentVideoTime }` AND advances `tapCursor` by 1 AND moves the score cursor to the new `tapCursor` measure (visual feedback).
- If the user misses a measure: <kbd>Shift+M</kbd> **un-taps** (removes last mapping, rewinds `tapCursor` by 1). One level of "undo last tap" beyond the global undo stack — much faster to use mid-flow than Ctrl+Z.
- If the user wants to **re-tap** a measure they already marked: tapping again on the same measure overwrites the `videoTime` (already works — `addMapping` handles this).

Visual: while tap mode is active, render a pulsing halo on the measure at `tapCursor` in the score grid (reuse the existing measure-active highlight CSS, add a `.sbn-ve-measure--tap-target` modifier that pulses orange).

Implementation sketch:
```js
// In VideoSyncEditor.vue
function tapMark() {
    emit('add-mapping', { measureIndex: tapCursor.value, videoTime: props.videoTime });
    tapCursor.value++;
}
function untap() {
    if (tapCursor.value === 0) return;
    tapCursor.value--;
    emit('remove-mapping', tapCursor.value);
}
```

Provide `tapCursor` via a new inject key `'vsyncTapCursor'` so `ChordMeasure.vue` / `TabMeasure.vue` can render the halo.

---

## 2. Scrub-preview: click measure → seek video

Already exists via `seekToMappingRow`, but only for **mapped** measures. Extend to **unmapped** measures during tap mode: click a measure card → video seeks to the **interpolated** time (`measureToVideoTime(mi)`), letting the user preview-jump to a spot before marking. When `tapCursor` lives on that measure, pressing M captures `currentVideoTime` (post-seek, so it's whatever they scrubbed to).

No new wiring — `seekToMeasure(gi)` already exists in `TabEditor.vue` and branches on `isVideoMaster` per the D1 spec. Just make sure clicking a measure card calls it even in tap mode (verify `ChordMeasure.vue` click handler isn't swallowed).

---

## 3. Playback-rate slider (0.2 5× – 1.5×)

Marking at full speed is hard. Soundslice has a speed slider — we should too.

`VideoPlayer.vue` exposes `setPlaybackRate(rate)`:
```js
// YouTube: _ytPlayer.setPlaybackRate(rate)
// Hosted:  videoEl.value.playbackRate = rate
```

UI: a small range slider (0.25, 0.5, 0.75, 1.0, 1.25, 1.5) above the mapping table, only shown when a video is loaded. Persist the current rate in component-local state — do NOT save to `videoSync` (it's an authoring preference, not a property of the song).

**Important:** YouTube's playback rate doesn't change the `getCurrentTime()` units — seconds stay seconds — so the rAF loop keeps working without changes.

---

## 4. "Bulk distribute" helper

After marking the **first** and **last** measures of a section, user should be able to auto-fill mappings for the measures in between by linear interpolation. Button in the mapping table toolbar: **"Distribute"**. Enabled when ≥2 mappings exist and there are unmarked measures between the first and last marked.

```js
function distributeMarkers() {
    const sorted = sortedMappings.value;
    if (sorted.length < 2) return;
    const first = sorted[0], last = sorted[sorted.length - 1];
    wrapCommand('Distribute video sync', [], () => {
        for (let mi = first.measureIndex + 1; mi < last.measureIndex; mi++) {
            if (mappings.value.find(m => m.measureIndex === mi)) continue;
            const t = (mi - first.measureIndex) / (last.measureIndex - first.measureIndex);
            const vt = first.videoTime + t * (last.videoTime - first.videoTime);
            mappings.value.push({ measureIndex: mi, videoTime: vt });
        }
    });
}
```

This is a single undo-able command (Pattern A with empty measure list so the whole model snapshots). Reason it lives in the composable, not the editor component: the mutation must go through `wrapCommand`.

---

## 5. Nudge buttons per row (±0.1s / ±1s)

The fine-tune slider in the current table has ±5s range but bounces around because each input recreates the min/max on every render. Replace with four nudge buttons per row: **◀◀ −1s**, **◀ −0.1s**, **+0.1s ▶**, **+1s ▶▶**. Each button calls `addMapping(measureIndex, currentTime ± delta)`.

Keep the text input for direct editing (e.g. "1:23.4"). Drop the range slider entirely — it's noisy and imprecise.

---

## 6. Keyboard shortcuts in video sidebar

Document and implement the full set (via the existing `onKeydown` listener in `VideoSyncEditor.vue`):

| Key | Action |
|-----|--------|
| <kbd>Space</kbd> | Toggle playback (already exists) |
| <kbd>M</kbd> | Tap mark at current video time |
| <kbd>Shift+M</kbd> | Un-tap (remove last mapping, rewind tapCursor) |
| <kbd>←</kbd> / <kbd>→</kbd> | Nudge video −/+ 2 seconds |
| <kbd>Shift+←</kbd> / <kbd>Shift+→</kbd> | Nudge video −/+ 10 seconds |
| <kbd>,</kbd> / <kbd>.</kbd> | Decrease / increase playback rate |

Guard: these only fire when the video sidebar is open AND the focused element isn't an `<input>` / `<textarea>` (reuse the pattern from `TabEditor.vue`'s keyboard handler).

---

## 7. Validation & warnings

Add a small status badge in the mapping table header: **"N markers · last at bar X · Y gaps"** where *gaps* = unmapped measures between first and last mapping. Helps the user see coverage at a glance.

Soft warning when two adjacent mappings imply an unreasonable tempo (e.g. one beat takes <0.1s or >5s) — colour that row's bar number red. Don't block saving; just flag it.

---

---

## 9. Acceptance criteria

1. Load blank leadsheet with a YT video, press Play, tap M through all measures → mappings show in table, tapCursor advances, score cursor follows tapCursor.
2. Miss a tap: press Shift+M → last mapping removed, tapCursor rewound, next M re-captures.
3. Mark bar 1 and bar 32, press Distribute → bars 2–31 filled with interpolated times, one undo clears them all.
4. Change playback rate to 0.5×, tap a few bars — mappings still record correct absolute times (no 2× scaling error).
5. Click an unmarked measure in the score while tap mode is active → video seeks to interpolated time, tapCursor moves to that measure, M captures the seeked time.
6. All authoring mutations survive Ctrl+Z / Ctrl+Shift+Z via the unified undo stack.
7. Save → reload: mappings round-trip; authoring preferences (speed, sidebar width) are session-local and don't round-trip.

---

## 10. Files touched

- `resources/js/tab-editor/composables/useVideoSync.js` — add `distributeMarkers`, tapCursor state, untap.
- `resources/js/tab-editor/components/VideoSyncEditor.vue` — new nudge buttons, speed slider, distribute button, extended keyboard handler, status badge.
- `resources/js/tab-editor/components/VideoPlayer.vue` — expose `setPlaybackRate`.
- `resources/js/tab-editor/components/ChordMeasure.vue` + `TabMeasure.vue` — add `.sbn-ve-measure--tap-target` highlight driven by injected `vsyncTapCursor`.
- `public/css/leadsheets.css` — `.sbn-ve-measure--tap-target` pulse animation; nudge-button styling.
- `resources/js/tab-editor/TabEditor.vue` — provide `vsyncTapCursor`.

No DB changes.

---

## 11. Explicitly out of scope (defer)

- Automatic onset detection from YT audio (ML-heavy, not worth the complexity for v1).
- Per-beat (sub-measure) markers. Stay at measure-level resolution; linear interpolation inside a measure is accurate enough for Soundslice-grade sync.
- Multiple videos per leadsheet. One video per leadsheet; switching clears mappings (with confirm dialog — already prompted via `clearMappings()`).
- Loop-region authoring (mark bar X → Y and loop). Belongs in a later practice-mode feature, not D2.
