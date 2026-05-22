# Video Sync & Real-World Snippet Integration — Plan

Integrate real-world audio/video examples (YouTube) into SBN's leadsheet and
course player, synced to visual components — Hooktheory-style. Short
analytical snippets accompany the existing player components so students can
hear how a groove, progression, or chord shape appears in a real recording.

This builds on the earlier admin video-sync work (`useVideoSync.js`, the
`#sbn-video-slot` editor pattern) and extends it to the public frontend.

---

## 0. Status — as built / revised design (2026-05-18)

**Frontend sync layer COMPLETE.** Committed `68470bf` on branch
`feature/video-sync-snippets` (not yet merged to `main`).

Built and intact:
- `resources/js/Components/Library/Video/VideoEmbed.vue` — shared YouTube/hosted
  embed. rAF polling, IFrame API, `seek/play/pause`.
- `resources/js/composables/useVideoPlayhead.ts` — the shared sync layer.
  Domain-free: reactive **`playheadSec`** (seconds, verbatim from YouTube),
  `playing`, `play/pause/seek`, `setLoop` (loop in recording-native seconds).
- `RhythmStrip`, `SheetMiniPlayer`, `ChordProgressionViewer` — additive
  `videoPlayhead` prop; the video clock drives the highlight only when the
  prop is non-null. `chordIndexAtTime` written for the progression viewer.
- `PracticePanel` — renders one `VideoEmbed` slot bound to the focused
  component's snippet.

**This was originally a single-snippet design. §0.1 below revises it** to the
multi-snippet + course-selection model the project actually needs. Sections
1–7 are the original rationale and legal posture, still valid.

### As-built note (2026-05-19) — steps 1–6 shipped

Plan §0.5 steps 1–6 are implemented on `feature/video-sync-snippets`:
authoring widget (rhythm + progression admin), `/api/sbn/rhythms` snippet
exposure, `LessonPalette` video picker, `<sbn-rhythm video-snippet>` tag attr,
and the `CourseController::player()` parser rewrite + resolution.

### As-built note (2026-05-19, later) — step 6b shipped (progression sync)

`<sbn-progression>` course video sync is **now built** on
`feature/video-sync-snippets`, no longer deferred. As-built:

- **Backend** — `CourseController::parseProgressionTags()` (per-tag-occurrence,
  attr-order-independent; extracts `slug`, `key`, `video-snippet`). Progression
  chords aren't stored — materialised per tag via
  `ProgressionLibraryController::buildChordsFor()` (extracted from `apiShow`).
  New `progressions` Inertia prop with the resolved `videoSnippet` (dangling
  id → `null`).
- **Shared playhead registry** — `getVideoPlayhead(snippetId)` in
  `useVideoPlayhead.ts`: a module-level memoised playhead per snippet id. This
  is what lets the two Vue roots share one clock (see below).
- **Inline `<sbn-progression>` IS the synced surface.** Unlike rhythm (whose
  sync is PracticePanel-only — see next note), the progression component in the
  lesson body is the synced surface: `mountSbnNodes` mounts it via a render
  function bound to the shared registry playhead. The `Player.vue` →
  `LessonContent` → `mountSbnNodes` chain threads a `snippetSync` map of
  `startSec`/`tempoBpm` anchors.
- **`PracticePanel`** — focus-switched single `VideoEmbed` across all lesson
  snippets (rhythm + progression); `rhythmVideoActive` / `progressionVideoActive`
  gate which component the clock drives.
- **Authoring** — `LessonPalette` "Video example" picker + TipTap `videoSnippet`
  attr now cover `progression` as well as `rhythm`;
  `ProgressionLibraryController::apiSearch()` exposes `snippets`.

The earlier-noted reason rhythm sync stayed PracticePanel-only — "the inline
strip cannot share the playhead without fragile cross-app coupling" — is
**resolved** by the snippet-id-keyed registry: it makes the cross-root share
*explicit* (the id is the contract), not implicit. The inline `<sbn-rhythm>`
still does not read `video-snippet` (rhythm's synced surface remains the
PracticePanel companion), but the inline `<sbn-progression>` does.

### As-built note (2026-05-22) — pinned voicings, play→video, polish

All §0.5 steps were already complete; this slice extends the *feature* past
the plan. Shipped on `feature/video-sync-snippets`:

- **Pinned chord voicings per snippet.** A progression snippet may now also
  carry a `key` (the key the recording is played in) and a `chords` array —
  one chord-library slug per numeral slot, blank = use the builder. Authored
  in the snippet widget (per-slot chord search, added to
  `_partials/video-snippets.blade.php` + `sbn-snippet-editor.js`), validated
  in `ProgressionController` (`key`, `chords.*` rules + `normalizeSnippets`).
  `buildChordsFor($prog, $key, $usePass2, $pinnedSlugs)` resolves the slugs
  and **transposes** each — a DB diagram is a movable shape, so the pinned
  voicing is run through `ChordSerializer::serialize($diagram, $targetRoot)`
  where `$targetRoot` is the slot's builder-resolved root note. `apiShow`
  takes `?chords=` (comma-list); `mountSbnNodes` sends it from the snippet's
  `chords`; `Player.vue` threads `key`/`chords` through `snippetSync`.
- **Course-editor key.** `LessonPalette` stamps the selected snippet's `key`
  onto the inserted `<sbn-progression key="…">` (apiSearch now exposes
  `snippets[].key`) — the TipTap node otherwise defaults `key` to `C`.
- **Play button → video.** `mountSbnNodes` passes `onVideoPlay`/`onVideoPause`
  to the synced `ChordProgressionViewer`, bound to the registry playhead. The
  viewer's play button drives the shared video clock instead of synth audio
  when a snippet is attached; the chord highlight already follows
  `videoPlayhead`.
- **Viewer parity.** `mountSbnNodes`'s `propsFor.progression` now passes the
  full prop set (name/category/numerals/keyLabel/color/vintageCard) so the
  inline course progression matches the library detail page; `buildChordsFor`
  emits a per-chord `numeral`; `BossaNovaChords` aligned with its sibling
  Top10 pages.
- **`VideoEmbed` overlay mitigation.** Opt-in `facade` prop (thumbnail until
  first play — skips the cued-state title/channel overlay) and seek-on-ENDED
  (state 0 → seek to `startSec`, suppresses the end-screen). `PracticePanel`
  uses `facade`. NOTE: the ~5s intro overlay *on play start* is YouTube-side
  and timer-based — neither `playerVars` nor the facade removes it; the only
  compliant kill is a muted pre-roll behind the facade (deemed not worth the
  click-to-video latency — see the chat decision).
- **Practice-panel layout.** The right-rail companion lost its nested card
  containers — flat stacked sections with hairline dividers, content inset
  16px, the `VideoEmbed` full-bleed to the sidebar edge.

**Decided scope — rhythm video sync is PracticePanel-only.** The course player
shows the *same* `<sbn-rhythm>` component in two places:
- the **PracticePanel** companion (right rail) — fed the resolved `videoSnippet`
  via the `rhythms` prop; **this is where rhythm video sync lives**. Transport ⇄
  video, start anchor, loop — all working.
- an **inline `<sbn-rhythm>`** placed in the lesson body — mounted by
  `mountSbnNodes` as an isolated `createApp`, with no `videoPlayhead` prop. It
  is **audio-only by design**: its play button runs the rhythm samples.

This is intentional for rhythm. `mountSbnNodes`'s `propsFor.rhythm` does **not**
read the `video-snippet` attribute; `propsFor.progression` does.

**Loop fix:** `VideoEmbed.onYTStateChange` must treat YouTube's *buffering*
state (3) as "still playing" — buffering fires on every loop seek, and
reporting it as a pause made looping snippets stop themselves.

---

## 0.1 Revised model — multi-snippet, authored per component, selected per course slot

The frontend assumed `videoSnippet?: VideoSnippet` — one snippet per
component. The real requirement:

> A rhythm pattern or chord progression can have **several** example videos
> (different recordings of the same groove/progression). Snippets are
> **authored in that component's own admin detail page**. When a component is
> placed into a course lesson, the course editor lets the author **pick which
> snippet** (or none) shows alongside it.

Two layers, kept separate:

| Layer | Owns | Where authored |
|---|---|---|
| **Component snippet library** | The list of `VideoSnippet`s belonging to a rhythm / progression | Rhythm admin page · Progression admin page |
| **Course slot selection** | *Which* snippet id (if any) to show for this lesson's instance of that component | Course editor (component picker) |

The course slot stores only a **reference** (`videoSnippetId`), never snippet
data — so editing a snippet in the rhythm admin updates every lesson that
points at it. Sheets keep using the existing leadsheet-editor VideoSync; no
new authoring there.

### Why the leadsheet `useVideoSync` is *not* reused wholesale

`useVideoSync.js` is measure/gi-domain: `expandMeasureSequence`,
`firstPositionForGi`, repeat-aware per-bar mapping tables, an AABA-walking tap
cursor. That machinery exists because a leadsheet bar can recur at irregular
video timestamps.

Rhythm and progression snippets are **short (≤16 bars) and non-repeating**.
Their sync is fully described by an **anchor pair** — `startSec` + `tempoBpm`
— and a linear seconds→beats projection. No per-bar mapping table, no gi
domain. So:

- **Runtime sync:** already done — `useVideoPlayhead.ts` + the components'
  `videoPlayhead` prop. Linear, seconds-based. Keep.
- **Authoring:** a **new lightweight Blade/Alpine widget**, not
  `VideoSyncEditor.vue` (which is Vue + gi-domain). The rhythm and progression
  admin pages are already Alpine (`x-data="rhythmEditor()"`), so a Vue island
  would be friction. The widget needs only: paste video URL/ID → embed
  preview → scrub → "mark start" / "mark end" → tempo field → save to the
  snippet list. This is `useVideoSync` minus the mapping table.

`useVideoSync.js` / `VideoSyncEditor.vue` stay the leadsheet Cinema view's
own consumers, untouched.

---

## 0.2 Data model

### Component side — snippet library (JSON column)

Neither `sbn_rhythm_patterns` nor `sbn_chord_progressions` has an existing
JSON blob to nest into (both are all-flat-scalar tables). Add a dedicated
column to each:

- `sbn_rhythm_patterns.video_snippets` — JSON, nullable, `cast => 'array'`.
- `sbn_chord_progressions.video_snippets` — JSON, nullable, `cast => 'array'`.

One migration each (or one migration touching both). Stored value is an array
of snippet objects:

```json
[
  {
    "id": "vs_a1b2c3",          // stable, generated on create — see id rule below
    "label": "Jobim — live 1965", // author-facing name, shown in the course picker
    "videoId": "abc123",
    "videoType": "youtube",      // 'youtube' | 'hosted'
    "startSec": 42.0,            // recording-time of the snippet's first bar
    "endSec": 60.0,              // loop wrap point
    "tempoBpm": 128
  }
]
```

`id` is mandatory and stable — the course slot references it. `label` is what
the course editor's dropdown shows.

**`id` generation.** Use `Str::uuid()` (prefixed `vs_` for readability if
desired). UUID collision probability is negligible, so no uniqueness check is
needed. If a shorter id is preferred (`Str::random(8)`), it **must** be
collision-checked against the existing `video_snippets` entries on that same
pattern and regenerated on clash — the id only needs to be unique *within one
pattern's array*, not globally. Default to UUID and skip the check.

**Snippet duration cap.** The data model stores raw `startSec`/`endSec` with no
bound, but §2/§7 require snippets to stay short (≤16 bars) so they remain
architecturally distinct from a full leadsheet. This must be **enforced**, not
left to convention:
- Authoring widget: after "mark start" / "mark end", compute the spanned bar
  count from `(endSec - startSec)`, `tempoBpm`, and the time signature; reject
  (or warn hard) if it exceeds 16 bars.
- Controller validation (§0.5 step 3): re-check the same bound server-side —
  `endSec > startSec` and the derived bar span ≤ 16. Client-side checks are
  not a security/integrity boundary.

### Course side — slot selection (CONFIRMED: tag attribute, no course migration)

Course components are **not** stored in DB columns. A lesson stores HTML
`content` (longText on `sbn_lessons`); components are embedded as
`<sbn-rhythm slug="…">` / `<sbn-chord …>` / `<sbn-progression …>` tags.
`CourseController::player()` regex-parses those tags at render time
(`<sbn-rhythm[^>]+slug="([^"]+)"`) and resolves each slug via
`RhythmPattern::toPlayerData()`.

So the per-lesson snippet selection is a **tag attribute**, not a DB row:

```html
<sbn-rhythm slug="bossa-basic" video-snippet="vs_a1b2c3">
```

- `CourseController::player()` must parse `video-snippet` alongside `slug`.
  **This is not a one-line extension of the existing regex.** The current
  parser (`<sbn-rhythm[^>]+slug="([^"]+)"`) captures only the slug and then
  runs `array_unique` on the slug list, building the panel one entry per
  *distinct slug*. Two `<sbn-rhythm slug="bossa-basic">` tags with **different**
  `video-snippet` values would collapse into a single panel entry — which
  breaks this whole model (same component, different snippet per lesson slot).
  The parser must therefore change to:
  - **per-tag-occurrence**, not per-slug — drop the `array_unique` dedup; the
    panel list is one entry per `<sbn-rhythm>` tag, in document order;
  - **attribute-order-independent** — `video-snippet` may appear before or
    after `slug`, so a `[^>]+slug=`-style positional regex is insufficient.
    Match the full tag, then extract attributes from it.
- It looks the id up in that pattern's `video_snippets` library and attaches
  the resolved snippet object to the `rhythms` prop entry.
- **Dangling reference:** if the `video-snippet` id no longer exists in the
  pattern's `video_snippets` (snippet deleted in the rhythm admin), resolution
  misses → treat as no snippet, slot hidden. Same outcome as `video-snippet=""`
  or absent. The admin should ideally warn when deleting a referenced snippet,
  but the player must degrade gracefully regardless.
- No `sbn_lessons` / `sbn_courses` migration.
- `<sbn-progression>` carries the same attribute, but that tag is **not parsed
  by `CourseController::player()` at all today** — wiring it in is a separate
  build step, not a free follow-on. See §0.5 step 6b.

### Course authoring — the LessonPalette config panel

The lesson editor is a **TipTap Vue island** (`resources/js/admin/`). The
component inserter is `LessonPalette.vue` — tabbed search (`Chord`, `Rhythm`,
`Progression`, …) with a per-item **inline config panel** that already adds
`extras` to the inserted tag (`key`, `root`, `label`). The snippet picker
slots straight into this pattern:

- When a rhythm/progression item is selected in the palette, fetch that
  component's `video_snippets` and render a **"Video example"** dropdown in the
  config row: "None" + one option per snippet `label`.
- The chosen snippet `id` is passed in `extras` → emitted as the
  `video-snippet` tag attribute.
- Rhythm currently inserts immediately on click (`insert()` special-cases
  `'rhythm'`); to host a config row it must use the select→configure→confirm
  path like `progression`/`sheet`.

### Frontend type — stays singular

The course slot picks exactly one snippet; `PracticePanel` renders exactly
one. The controller resolves the tag's `video-snippet` id against the
library and attaches the *single resolved* snippet (§0.3). So the
player-facing type is **unchanged**:

- `RhythmPattern.vue` keeps `RhythmPatternWithMeta { videoSnippet?: VideoSnippet | null }`.
- `VideoSnippet` gains two fields used only by the admin/authoring layer:
  `id: string`, `label: string`. They are harmless on the player type.
- The `VideoSnippet[]` library shape exists **only** in the admin authoring
  widget and the `video_snippets` JSON column — not on the player prop.

`PracticePanel` needs no change beyond what `68470bf` already ships.

---

## 0.3 Serialization (CONFIRMED)

The course `PracticePanel` is fed by `CourseController::player()`, which builds
its `rhythms` prop from **`RhythmPattern::toPlayerData()`** (not the raw
`apiIndex`).

**`toPlayerData()` needs no change.** The controller's `->map()` already has
the `RhythmPattern` model (`$r`) in scope when it builds each prop entry, so it
can read `$r->video_snippets` directly to resolve the tag's `video-snippet` id.
There is no reason to push the snippet library through `toPlayerData()`.

**Resolve in the controller.** The course slot's job is to pick one; the panel
only ever shows one. The controller looks up the tag's `video-snippet` id in
`$r->video_snippets`, and attaches the *single resolved snippet* (or `null`) to
the prop entry as `videoSnippet`. Emitting the whole library to the frontend
serves no runtime purpose. So the player-facing prop stays
`videoSnippet?: VideoSnippet | null` — the *frontend* type need not change to
an array at all. The array lives only in the admin/authoring layer.

Mirror for progressions in the progression player serializer.

---

## 0.4 Open questions — all resolved

All three earlier unknowns are now traced:

1. **Course content schema** — components are `<sbn-*>` tags in lesson HTML,
   parsed by `CourseController::player()`. Snippet selection = a
   `video-snippet` tag attribute (§0.2 Course side). No course migration.
2. **Player serializer** — `RhythmPattern::toPlayerData()`, via
   `CourseController::player()` (§0.3).
3. **Authoring widget placement** — the rhythm editor is Alpine
   (`rhythmEditor()`); `admin/progressions/edit.blade.php` is **confirmed**
   Alpine too (`x-data="progressionForm()"`). The shared Blade/Alpine widget is
   viable on both pages. Course-side picker = `LessonPalette.vue` config panel
   (Vue).

---

## 0.5 Build sequence

1. **Migrations** — `video_snippets` JSON column on `sbn_rhythm_patterns` and
   `sbn_chord_progressions`; add to `$fillable` + `$casts => 'array'` on
   `RhythmPattern` and `ChordProgression`.
2. **Snippet authoring widget** — shared Blade/Alpine partial: paste URL/ID →
   embed preview → scrub → mark start/end → label + tempo → list/add/remove.
   Embedded in the rhythm and progression edit pages. Each snippet gets a
   stable `id` on create.
3. **Admin controllers** — accept `video_snippets` in `validatePattern` and
   the progression equivalent; per-snippet array validation (`id`, `label`,
   `videoId`, `videoType`, `startSec`, `endSec`, `tempoBpm`).
4. **API for the palette** — `/api/sbn/rhythms` and `/api/sbn/progressions`
   (used by `LessonPalette`) include each component's `video_snippets`
   (id + label only) so the picker can list them.
5. **`LessonPalette.vue`** — add a "Video example" dropdown to the rhythm and
   progression config rows; emit the chosen id as `extras.videoSnippet`.
   Rhythm must move from insert-on-click to the select→configure→confirm path.
6. **`CourseController::player()` — rhythm parser rewrite.** Replace the
   slug-only `preg_match_all` + `array_unique` with a per-tag-occurrence,
   attribute-order-independent parser (match each `<sbn-rhythm>` tag, extract
   `slug` and `video-snippet` from it; no slug dedup — panel order = tag
   order). Resolve each tag's `video-snippet` id against the pattern's
   `video_snippets`; attach the single resolved snippet (or `null` on a
   dangling id) as `videoSnippet` on the `rhythms` prop entry.
6b. **`<sbn-progression>` wiring — ✅ SHIPPED (2026-05-19).** End-to-end:
   `parseProgressionTags()` → `progressions` prop → chords materialised via
   `ProgressionLibraryController::buildChordsFor()` → snippet resolution. The
   inline `<sbn-progression>` is the synced surface (not PracticePanel-only,
   unlike rhythm) — `mountSbnNodes` binds it to a snippet-id-keyed shared
   playhead (`getVideoPlayhead`). PracticePanel also renders a focus-switched
   `ChordProgressionViewer` companion. Authoring covered by the `LessonPalette`
   picker + TipTap `videoSnippet` attr (now on `progression` too). See the
   §0 "step 6b shipped" as-built note for the full design.
7. **`toPlayerData()`** — no change. The controller has the `RhythmPattern`
   model in scope and reads `$r->video_snippets` directly to resolve the
   snippet (see §0.3).

Step 1 is mechanical. Step 2 is the main new UI. **Step 6 is Medium, not
mechanical** — it's a parser rewrite, not a regex tweak. Step 3 is small.
Steps 4–5 are the course-authoring slice. Step 6b is a deferable slice.
`PracticePanel` itself needs no change.

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

- One shared sync layer owns the YouTube IFrame API, the player instance, and
  exposes a reactive **playhead in a common unit** (seconds) plus `play` /
  `pause` / `seek`. As built this is `useVideoPlayhead.ts` + `VideoEmbed.vue`.
- Each visual component does **not** embed the video — it **consumes a
  playhead**. It takes a time value and maps it to its own highlight via a
  small `timeToHighlight` mapper.

Reverse sync (video → highlight) is YouTube IFrame `getCurrentTime()` polling
+ `onStateChange`.

### Per-component `timeToHighlight` mapper

| Component | Mapper | Status |
|---|---|---|
| RhythmStrip | `beatToStep` | **Exists** |
| SheetMiniPlayer | measure-index via `expandMeasureSequence` | **Exists** |
| ChordProgressionViewer | `chordIndexAtTime` | **Exists** (written for `68470bf`) |

---

## 4. Component readiness audit

### RhythmStrip — clock-driven ✅
Advances off a clock; `is-current` highlight driven by `currentStep`. A second
clock source (the video playhead) feeds it; `beatToStep` does the time→cell math.

### SheetMiniPlayer — clock-driven ✅
Runs `useTabModel` + `TabMeasure` + the tags engine. Time-driven; consumes the
shared playhead.

### ChordProgressionViewer — `chordIndexAtTime` added ✅
Native playback is event-chained (play chord → wait `'ended'` →
`playNextChord()`). `chordIndexAtTime(t)` builds cumulative time offsets from
each chord's `beats` field and resolves a time to an index; `activeIndex` reads
from it when a video clock is driving.

---

## 5. Effort estimate (revised for §0.1 model)

| Piece | Effort | Notes |
|---|---|---|
| `video_snippets` migrations + model casts | Small | Two tables, mechanical |
| Snippet authoring widget (Blade/Alpine) | Medium | The main new UI; `useVideoSync` minus the mapping table |
| Admin controller validation | Small | Per-snippet array validation |
| Palette API exposes snippet id+label | Small | Extend `/api/sbn/rhythms`, `/api/sbn/progressions` |
| `LessonPalette` snippet dropdown | Medium | New config row; rhythm moves to confirm-path |
| `CourseController::player()` resolve | Medium | Rhythm parser **rewrite** (per-tag, no dedup, order-independent) + snippet lookup |
| `<sbn-progression>` player wiring (6b) | Medium | Tag not parsed today; deferable to phase 2 |
| `PracticePanel` | None | `68470bf` already renders a single `videoSnippet` |

---

## 6. Sequencing

Superseded by §0.5.

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
- Snippets stay **short (≤16 bars)** — this is what justifies the simple
  anchor-pair sync over the leadsheet's per-bar mapping table.
