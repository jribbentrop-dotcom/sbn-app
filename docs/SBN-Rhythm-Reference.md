# SBN Teaching Hub — Rhythm Reference

> **Purpose:** Single source of truth for all rhythm-related components, data models, audio
> pipeline, and UI patterns. Load alongside `SBN-Design-Reference.md` at the start of any
> session that touches rhythm features.
> **Rule:** `RhythmStrip` is the canonical display component for any listing or embedded
> context. `RhythmPattern` is for full detail / interactive views only.

---

## 1. Component Hierarchy

```
RhythmStrip      ← PRIMARY — listings, sidebar embeds, course player, any compact context
RhythmPattern    ← FULL — detail page (Show.vue), admin editor preview
RhythmCard       ← DEPRECATED — was the library listing card; replaced by RhythmStrip rows
```

### When to use which

| Context | Component |
|---|---|
| Library index listing | `RhythmStrip` (inside a `.sbn-pattern-row` link card) |
| Course player sidebar | `RhythmStrip` |
| `<sbn-rhythm>` lesson embed | `RhythmStrip` |
| Library detail / Show page | `RhythmPattern` (full grid, demo blend slider) |
| Admin pattern editor preview | `RhythmPattern` |

---

## 2. Data Types

Defined and exported from `RhythmPattern.vue`:

```typescript
// Core data — required for rendering and playback
interface RhythmPatternData {
  name: string;
  beats: number;           // total grid steps in the pattern
  gridType: 'eighth' | 'sixteenth' | 'triplet';
  thumb: string;           // bass pattern string — x=soft hit, X=accent, .=rest
  fingers: string;         // fingers pattern string, same encoding
  bpm: number;
  timeSignature: string;   // e.g. '4/4', '3/4'
  percTop: string;         // instrument name for fingers row, or 'none'
  percBass: string;        // instrument name for thumb row, or 'none'
}

// Extended — includes DB/meta fields (from controller serialization)
interface RhythmPatternWithMeta extends RhythmPatternData {
  id: number;
  slug: string;
  description: string;
  category: string;        // display name e.g. 'Bossa Nova'
  styleSlug: string;       // e.g. 'bossa', 'jazz' — keys useCategoryColors
  demoUrl?: string | null;
}
```

Pattern encoding: `'X'` = accent (full velocity), `'x'` = soft hit (0.85 velocity), `'.'` = rest.  
Step duration by grid type: `eighth` = 0.5 beats, `sixteenth` = 0.25 beats, `triplet` = 1/3 beats.

---

## 3. RhythmStrip

**File:** `resources/js/Components/Library/RhythmStrip.vue`

The compact inline component. No beat-label row, no row labels — two stacked rows of
coloured blocks (fingers + thumb) with a small circular play button. Designed to sit in
any narrow context.

### Props

```typescript
interface Props {
  pattern: RhythmPatternData;
  tempo?: number;         // override pattern.bpm at runtime
  playable?: boolean;     // default true — show/hide the play button
  label?: string | null;  // optional eyebrow label on the left
  showMeta?: boolean;     // show "4/4 · 120 bpm" eyebrow on the right
  color?: string | null;  // CSS colour value — tints hit/accent cells and play button
  mini?: boolean;         // slimmer 16px cells (default false)
  maxBeats?: number | null; // cap rendered cells — library index passes 16 to show only bar 1 of 2-bar patterns
}
```

**Layout:** The strip is always `width: 100%` — it fills whatever container holds it. The cells container has `overflow: hidden` so long patterns (e.g. 32-cell 2-bar) clip to the container width rather than overflowing. Use `maxBeats` when you want to truncate at the data level instead.

### Colour tinting

Pass any CSS colour string to `color` and the strip's hit cells adopt it:
- Soft hits (`x`) render at 75% opacity of `color`
- Accents (`X`) render at 100% opacity of `color`
- Thumb row hits at 50% / 80% opacity respectively
- Play button active state uses `color` via `--strip-color`

In the library index, colour is sourced from `getCategoryColor(pattern.styleSlug)`:

```vue
<RhythmStrip
  :pattern="pattern"
  :color="getCategoryColor(pattern.styleSlug)"
  :show-meta="false"
  :max-beats="16"
/>
```

The library index also overrides cell sizing via scoped `:deep()` on the wrapping `.sbn-rlib-row-strip` div to make cells 28px wide (vs the default 20px).

### Exposed methods

```typescript
defineExpose({ play, stop, toggle })
```

Use `ref` + `strip.toggle()` to control playback from a parent.

---

## 4. RhythmPattern

**File:** `resources/js/Components/Library/RhythmPattern.vue`

Full grid renderer for the detail page. Shows beat-label row, Fingers / Thumb row labels,
playback transport, truncation fade for long patterns, and a `transport-extra` slot for
the demo blend slider.

### Props

```typescript
interface Props {
  pattern: RhythmPatternData;
  tempo?: number;
  playable?: boolean;    // default false
  mini?: boolean;        // compact cell variant (used inside RhythmCard)
  demoUrl?: string | null; // demo MP3 URL — enables blend slider on Show page
}
```

### Slots

```html
<!-- Blend slider or any extra transport control -->
<RhythmPattern :pattern="p" :playable="true" :demo-url="p.demoUrl">
  <template #transport-extra>
    <input type="range" ... />
  </template>
</RhythmPattern>
```

---

## 5. Library Index Card Pattern

**File:** `resources/js/Pages/Library/Rhythms/Index.vue`

The library listing uses a card design that is now the **SBN standard for rhythm rows**
(and a candidate for other library listings):

```html
<Link :href="`/library/rhythms/${pattern.slug}`"
      class="sbn-pattern-row sbn-pattern-row--{styleSlug}">
  <div class="sbn-pattern-row-head">
    <span class="sbn-pattern-row-name">{{ pattern.name }}</span>
    <span class="sbn-pattern-row-badges">
      <span class="sbn-badge sbn-badge-muted">{{ pattern.timeSignature }}</span>
      <span class="sbn-badge sbn-badge-muted">{{ pattern.bpm }} BPM</span>
      <span class="sbn-badge sbn-badge-eighth">eighth</span> <!-- if applicable -->
    </span>
  </div>
  <p class="sbn-pattern-row-desc">{{ pattern.description }}</p>
  <RhythmStrip :pattern="pattern" :color="getCategoryColor(pattern.styleSlug)" />
</Link>
```

**Card visual spec — the offset-shadow card:**
- Base border: `1px solid --clr-border`
- Right border: `3px solid {category-color}` (bold accent edge)
- Bottom border: `3px solid {category-color}` (bold accent edge)
- Hover: `box-shadow: 3px 3px 0 {category-color}` + `translate(-1px, -1px)` — vintage offset effect
- The right+bottom bold edge with matching offset shadow is the **SBN vintage card style**
  — use it for any listing card that should feel tactile and music-forward

Style modifier classes follow the `--{styleSlug}` pattern:
```css
.sbn-pattern-row--bossa     { --row-color: var(--clr-style-bossa); }
.sbn-pattern-row--jazz      { --row-color: var(--clr-style-jazz); }
/* etc. */
```

---

## 6. Category Header Pattern

Category section headings in the library use a **full-colour gradient pill**:

```html
<h2 class="sbn-category-header sbn-category-header--{styleSlug}">
  Bossa Nova
  <span class="sbn-category-count">12</span>
</h2>
```

```css
.sbn-category-header {
  color: #fff;
  padding: 10px 16px;
  border-radius: var(--radius);
  background: var(--cat-color, var(--clr-style-default));
}
.sbn-category-header--bossa {
  --cat-color: linear-gradient(100deg, var(--clr-style-bossa),
               color-mix(in srgb, var(--clr-style-bossa) 40%, white));
}
/* one rule per styleSlug */
```

Count badge is a semi-transparent pill: `background: rgba(255,255,255,0.2)`.

---

## 7. `<sbn-rhythm>` Lesson Embed Tag

Defined in `resources/js/lib/mountSbnNodes.ts` and documented in `SBN-Course-Reference.md §3`.

```html
<sbn-rhythm slug="bossa-nova-basic"></sbn-rhythm>
```

| Attribute | Required | Notes |
|---|---|---|
| `slug` | yes | Must match a `RhythmPattern.slug` in the DB |

**API endpoint:** `GET /api/sbn/rhythms/{slug}` → `RhythmPatternWithMeta`  
**Controller:** `RhythmLibraryController::apiShow`  
**Mounts:** currently `RhythmCard` — **TODO: migrate embed to `RhythmStrip`** (tracked below)

---

## 8. Audio Pipeline

### Data flow

```
RhythmPattern model (DB)
  └─ toPlayerData()              → RhythmPatternData shape
       └─ rhythmPatternToEvents(pattern, { startBeat: 0 })
                                 → PercussionEvent[]
            └─ AudioEngine.load(events, { loop, loopBeats, demoUrl, demoOffsetBeats })
                 └─ Scheduler → PercussionSampler
```

### Adapter

**File:** `resources/js/audio/adapters/rhythmPatternToEvents.js`

Pure function — no side effects. Takes a `RhythmPatternData` and returns an array of
timed percussion events. Uses `percTop` / `percBass` to route to the correct sample bucket.

### AudioEngine

**File:** `resources/js/audio/engine/AudioEngine.js`  
**Singleton:** `getAudioEngine()` — shared across all components on a page.

Key events: `tick(beat)`, `sourceActive(id)`, `playStarted`.  
When a new component calls `engine.play()`, `playStarted` fires — all other components
should reset their `isPlaying` state on this event.

### Samples

| Asset path | Contents |
|---|---|
| `public/audio/rhythm-samples/` | WAV files: `shaker_soft`, `shaker_accent`, `tamborim`, `kick`, `hihat_brush`, `brush_snare` (×soft/accent variants) |
| `public/audio/rhythm-demos/`   | Demo MP3s — one per pattern slug |

### Demo blend (detail page only)

`RhythmPattern` accepts `demoUrl`. The engine loads two gain buses (`samplesBus`, `demoBus`)
with a biased power curve: samples = `(1-v)^1.3`, demo = `v^1.7`. Blend slider is passed
via the `#transport-extra` slot.

---

## 9. Backend

### Model

**File:** `app/Models/RhythmPattern.php`  
Key method: `toPlayerData()` — serialises DB columns to `RhythmPatternData` shape.  
Columns: `name`, `slug`, `beats`, `grid_type`, `thumb`, `fingers`, `bpm`, `time_signature`,
`perc_top`, `perc_bass`, `description`, `category`, `style_slug`, `demo_url`.

`video_snippets` — JSON column (cast `array`), the pattern's library of
real-world YouTube examples. See §12.

### Controllers

| Controller | Path | Actions |
|---|---|---|
| `RhythmLibraryController` | `app/Http/Controllers/Library/` | `index()`, `show(slug)`, `apiShow(slug)` |
| `RhythmPatternController` | `app/Http/Controllers/Admin/` | Full CRUD |

### Routes

```
GET  /library/rhythms              → index()
GET  /library/rhythms/{slug}       → show()
GET  /api/sbn/rhythms/{slug}       → apiShow()   (lesson embed API)
```

Admin routes are prefixed `/admin/rhythms/`.

### RhythmMaterializer service

**File:** `app/Services/RhythmMaterializer.php`

Used by the leadsheet creation pipeline (Phase L2.5). Expands a voicing over one bar using
a rhythm pattern — produces MusicXML-compatible stroke events instead of whole notes.
`VoicingMaterializer` accepts an optional `?RhythmPattern $rhythm` parameter; when null,
whole-note mode runs unchanged.

---

## 10. Admin Palette (Lesson Editor)

The TipTap lesson editor palette (`LessonPalette.vue`) has a **Rhythm tab** that searches
the pattern library and inserts `<sbn-rhythm slug="...">` tags into the lesson body.

Selecting a rhythm opens an inline config row with a **"Video example"**
dropdown (select→configure→confirm path — rhythm no longer inserts on click).
The chosen snippet id is emitted as the `video-snippet` tag attribute. See
the Course Reference §10 for the course-side video-sync flow.

---

## 11. Open TODOs

| # | Task | Priority |
|---|---|---|
| 1 | ~~Migrate `<sbn-rhythm>` lesson embed (`mountSbnNodes.ts`) to `RhythmStrip`~~ — **DONE** | Medium |
| 2 | ~~Add `@click.stop` on `RhythmStrip`'s play button~~ — **DONE** | High |
| 3 | ~~Extend `RhythmStrip` with a `mini` prop~~ — **DONE** | Low |
| 4 | Phase 7: "Used in songs" section on rhythm Show page (query `sbn_leadsheets WHERE rhythm = {slug}`) | Low |
| 5 | Rhythm Show page: confirm demo blend slider still works after `RhythmPattern` refactors | Medium |

---

## 12. Video Snippet Library

Each rhythm pattern can carry a library of real-world YouTube examples in its
`video_snippets` JSON column. Course-side flow + full as-built design in the
Course Reference §10.

### Snippet shape

```json
{
  "id": "vs_<uuid>",        // stable; the course tag references this
  "label": "Jobim — 1965",  // shown in the LessonPalette video picker
  "videoId": "abc123",
  "videoType": "youtube",
  "startSec": 7.0,           // recording-time of the snippet's first bar
  "endSec": 22.5,            // loop wrap point
  "tempoBpm": 120
}
```

### Authoring widget

Shared partial `resources/views/admin/_partials/video-snippets.blade.php`,
included in the rhythm editor sidebar (and the progression editor). Backed by
the registered Alpine component in `public/js/sbn-snippet-editor.js`:

- Paste a YouTube URL/ID → embedded preview → scrub → "Mark start" /
  "Mark end" → label + tempo → add to the list.
- Each snippet gets a stable `vs_<uuid>` id on create.
- Duration cap: a snippet may span at most **16 bars** (legal/architectural
  line — see plan §2/§7). Enforced both in the widget (live bar-count) and
  server-side in `RhythmPatternController::validatePattern()`.
- The widget commits into `form.video_snippets` via a bubbling
  `sbn:snippets-changed` event; persisted on the main pattern Save.

The rhythm editor saves via AJAX (real array in the JSON body); the
progression editor is a classic form POST, so its widget writes JSON into a
hidden `video_snippets` input, decoded server-side.
