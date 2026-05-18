# Video Sync & Real-World Snippet Integration — Plan

Integrate real-world audio/video examples (YouTube) into SBN's leadsheet and
course player, synced to visual components — Hooktheory-style. Short
analytical snippets accompany the existing player components so students can
hear how a groove, progression, or chord shape appears in a real recording.

This builds on the earlier admin video-sync work (`useVideoSync.js`, the
`#sbn-video-slot` editor pattern) and extends it to the public frontend.

---

## 0. Status — as built (2026-05-18)

**Frontend COMPLETE.** Committed `68470bf` on branch
`feature/video-sync-snippets` (not yet merged to `main`).

Built:
- `resources/js/Components/Library/Video/VideoEmbed.vue` — shared YouTube/hosted
  embed. Relocated from `tab-editor/components/VideoPlayer.vue`; rAF polling,
  IFrame API, `seek/play/pause`. This is the `<YouTubeEmbed>` of §3.
- `resources/js/composables/useVideoPlayhead.ts` — the shared sync layer.
  Domain-free: reactive **`playheadSec`** (seconds, verbatim from YouTube — no
  conversion on the wire), `playing`, `play/pause/seek`, and `setLoop` (loop
  enforced in recording-native seconds). NOTE: this is a *new* composable, not
  the admin `useVideoSync.js` — the latter is measure/gi-domain (mappings, D2
  authoring) and stays the leadsheet Cinema view's own consumer, untouched.
- `ChordProgressionViewer` — `chordTimeline` + `chordIndexAtTime(sec)` (§4 ⚠️
  refactor; done).
- `RhythmStrip`, `SheetMiniPlayer` — additive `videoPlayhead` prop. Audio paths
  untouched; the video clock drives the highlight only when the prop is non-null.
- `PracticePanel` — one panel-level `VideoEmbed` slot, bound to the focused
  rhythm's `videoSnippet`. `VideoSnippet` type lives on `RhythmPattern.vue`.

**Transport unit (locked):** seconds. Each snippet carries an anchor pair
`startSec` + `tempoBpm`; each consumer converts seconds→its own unit once at its
edge. Loops are `[startSec, endSec]` in YouTube-native seconds.

**Design (locked):** a snippet always attaches to a *component instance*
(rhythm, progression, …), never to a bare lesson. The practice panel renders
one embed slot bound to the focused component.

**Leadsheet sync:** already shipped as the Cinema view — no separate work
needed. Verified intact after the `VideoEmbed` rename.

### Remaining — pick up here

**Backend `videoSnippet` field (the only open task).** Nothing populates
`videoSnippet` yet, so the practice-panel embed slot stays hidden. To finish:
1. Add a `video_snippet` JSON field to the rhythm pattern (column or existing
   JSON blob on `sbn_rhythms`).
2. Include it in `RhythmPatternController` serialization → the
   `RhythmPatternWithMeta` shape already declares `videoSnippet?`.
3. Admin editor UI (Blade) to author `videoId` / `startSec` / `endSec` /
   `tempoBpm`.
Shape to emit (see `VideoSnippet` in `RhythmPattern.vue`):
`{ videoId, videoType?, startSec, endSec?, tempoBpm }`.

Sections 1–7 below are the original plan, kept for rationale and the legal
posture. Where they describe future work, §0 above supersedes them.

---

## 1. Goals

- Leadsheet viewer and course practice companion can embed a YouTube video
  that syncs with a visual component.
- In the **leadsheet**, frontend sync is identical to the existing backend
  (admin) video-sync feature.
- In the **course player**, it works/looks like Hooktheory: video embed in the
  practice companion, linked to a visual component (rhythm player, progression
  viewer, mini sheet player).
- Visual components can also carry a video on their own (standalone).

---

## 2. Legal posture (locked)

The app is **public and commercial** — the highest-exposure quadrant. The
agreed posture for real-world examples:

| Rule | Why |
|---|---|
| **Embed YouTube's visible player only** | Licensing stays with YouTube. Never rip audio, never use a hidden/0×0 iframe — that breaks YouTube ToS *and* loses the embedding cover. |
| **Prefer official rights-holder uploads** | Label/artist channels are themselves licensed and stable; removes the "is this upload itself infringing" risk. |
| **Small snippets only — 4–8 bars** | Fragment of verse/chorus, never the whole song. Amount-used is a core fair-use factor. Build so a "snippet" can't trivially span the full track. |
| **Visualization stays analytical, not performable** | Rhythm strips, progression trackers, Roman numerals, chord symbols = commentary. Full standard notation a student could perform from = reproduction. Keep snippets architecturally distinct from full leadsheets. |
| **Frame as education** | Surrounding "here's how X appears in this recording" context supports the fair-use purpose factor. |

The core principle: **whose player plays the bytes.** Platform's own player
embedded = publisher embedding (fine). Their audio in our player = we need the
rights (not fine).

Copyright is decided **case-by-case** by the content author. This plan covers
the technical capability; it does not pre-clear any specific recording.

**Self-hosted MP3 demos** remain the cleanup item. Generic "groove" demos →
self-recorded / owned audio through the existing Web Audio pipeline (this
keeps the rhythm demo slider exactly as it is). Specific-recording demos →
replaced by the YouTube-embed approach in this plan.

---

## 3. Architecture: one shared sync layer, many consumers

**Do not** give each component its own YouTube integration — that produces
divergent sync code. Instead:

- One shared **`useVideoSync`** composable owns the YouTube IFrame API, the
  player instance, and exposes a reactive **playhead in a common unit**
  (seconds/beats) plus `play` / `pause` / `seek`.
- One **`<YouTubeEmbed>`** component wraps it (visible player).
- Each visual component does **not** embed the video — it **consumes a
  playhead**. It takes a time value and maps it to its own highlight via a
  small `timeToHighlight` mapper.

The playhead model already exists and is canonical: `gi` (global bar index)
vs `play position`, with `expandMeasureSequence` / `giAtPosition` /
`firstPositionForGi` (see `SBN-Audio-Reference.md §5.1`). Reverse sync
(video → highlight) is YouTube IFrame `getCurrentTime()` polling ~4 Hz +
`onStateChange` — already spec'd in Phase D.

### Per-component `timeToHighlight` mapper

| Component | Mapper | Status |
|---|---|---|
| RhythmStrip | `beatToStep` | **Exists** — `RhythmStrip.vue:48` |
| SheetMiniPlayer | measure-index via `expandMeasureSequence` | **Exists** |
| ChordProgressionViewer | `chordIndexAtTime` | **Must be written** |

---

## 4. Component readiness audit

### RhythmStrip — clock-driven ✅ (small job)
Already advances off a clock: `engine.on('tick', beat => currentStep = beatToStep(beat))`
(`RhythmStrip.vue:94`). The `is-current` highlight is driven purely by
`currentStep`. To make it YouTube-syncable, `currentStep` just needs a second
clock source (the video playhead) feeding it; `beatToStep` already does the
time→cell math.

### SheetMiniPlayer — clock-driven ✅ (small job)
Runs `useTabModel` + `TabMeasure` + the tags engine as a `'sheet'` source.
Time-driven; consume the shared playhead.

### ChordProgressionViewer — NOT clock-driven ⚠️ (moderate job)
Playback is **event-chained**, not time-driven: play chord → wait for engine
`'ended'` event → `playNextChord()` (`ChordProgressionViewer.vue:395-412`).
The active chord (`currentPlayingIndex`) advances by discrete callbacks. There
is no "at time T, chord index = N" map.

**Required:** add a `chordIndexAtTime(t)` mapper — build cumulative time
offsets from each chord's `beats` field (already present per chord), then
resolve a time to an index. `activeIndex` reads from this map when a video
clock is driving. Contained, but genuine new code.

---

## 5. Effort estimate

| Piece | Effort | Notes |
|---|---|---|
| Leadsheet video sync | Small | Reuse `useVideoSync`; essentially Phase D D1 |
| Course player embed slot | Medium | New UI placement of the Phase D `#sbn-video-slot` pattern in the practice companion |
| RhythmStrip → syncable | Small | Add second clock source feeding `currentStep` |
| SheetMiniPlayer → syncable | Small | Consume shared playhead |
| ChordProgressionViewer → syncable | Medium | Write `chordIndexAtTime`; no time→index map exists today |

Mostly **wiring**, because the playhead model is already shared and tested.
ChordProgressionViewer is the only component needing a real (but contained)
refactor.

---

## 6. Sequencing

1. **ChordProgressionViewer time-map first** — the only real refactor, and it
   forces a clean definition of the shared playhead unit the rest depends on.
2. Shared `useVideoSync` playhead + `<YouTubeEmbed>`.
3. Wire RhythmStrip and SheetMiniPlayer as playhead consumers.
4. Leadsheet viewer embed (reuses backend sync model).
5. Course player practice-companion embed slot.

---

## 7. Constraints to enforce

- The rhythm **demo slider stays exactly as is** — owned/self-recorded audio
  through the existing Web Audio pipeline. The video-sync capability is
  *additive*: slider for owned groove demos, YouTube embed for real-world
  recording examples. Never mix the two on the same component.
- Snippet feature stays **architecturally distinct** from full leadsheets so
  it never drifts into being a mini-leadsheet (the legal line in §2).
- Video embed is a **standalone reference card**, parallel to the visual
  component — the embed reads the playhead; it is not itself the audio source
  for component playback.
- Adding a third sync consumer touches the keyboard-ownership smell flagged in
  the repeat/volta sync followups — centralize, don't add ad-hoc guards.
