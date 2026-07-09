# SBN Homepage — Reference

The public homepage at `GET /` is the marketing and entry point for the app.
It is a pure Inertia page (no auth required), composed in `Home.vue` from
several Vue components plus a static CSS layer for everything else.

Re-layout completed 2026-07-09 (this document supersedes all earlier
versions). ChordRain, GradesSlider/GradesTeaser, and the old 3-card feature
grid were removed from the live page — see §5 for where the first two ended
up.

---

## 1. Files

| File | Role |
|---|---|
| `app/Http/Controllers/HomeController.php` | Feeds all page data; no auth guard |
| `resources/js/Pages/Home.vue` | Inertia page — section composition |
| `resources/js/Components/Home/SyncedHero.vue` | Chord+rhythm synced demo (hero right column) |
| `resources/js/Components/Home/SkillPathSection.vue` | Animated skill-tree scroll section |
| `resources/js/Components/Library/RhythmPattern.vue` | Full rhythm-pattern widget, mounted in the Rhythm box |
| `resources/js/Components/Library/ChordProgressionViewer.vue` | Progression viewer, mounted in the Progressions box |
| `resources/js/edu/widgets/CircleOfFifths.vue` | Theory widget, mounted in the Theory box |
| `public/css/home.css` | All homepage CSS — scoped to `.home-page` |
| `resources/views/app.blade.php` | Loads `home.css` globally via `<link>` |

### 1a. Where the old sections went

- **ChordRain** — the CPU-heavy `requestAnimationFrame` card-rain section
  (§4–§6 of the old doc revision) was cut for performance reasons (continuous
  per-frame DOM writes on ~60 SVG-containing elements pinned a CPU core).
  Parked, not deleted: `resources/js/Pages/Library/ChordRainShowcase.vue` at
  `/library/chords/rain` (behind `auth`, `ChordLibraryController::showcase()`
  builds the data). Linked from the footer's "Tools" column as "Chord Rain
  (parked)".
- **GradesSlider** (drag/keyboard carousel over the 5 grade tiers) — parked
  at `resources/js/Pages/Grades/Slider.vue`, route `/grades/slider`
  (`GradesController::slider()`, public). Linked from the footer as "Grades
  Slider (parked)". `GradesTeaser` (the sticky scroll-scrub predecessor
  GradesSlider replaced) was already dead before this pass and stays dead —
  its commented-out `<!-- <GradesTeaser /> -->` line is still in `Home.vue`
  as a historical marker.
- **3-card feature grid** ("Interactive Tab Editor" / "Every chord, every
  context" / "Analysis Panel") — replaced outright by the 6-box library
  showcase in §3.

---

## 2. Page sections (top → bottom, as currently composed in `Home.vue`)

1. **Hero** (`home-hero`) — see §2.1
2. **Skill path** (`SkillPathSection`) — animated branching skill-tree
   section; unchanged by this pass, see prior doc revisions or
   `docs/SBN-Skill-System-Reference.md` for its own detail
3. **Library showcase** (`home-section`, `.lib-boxes`) — 6-box grid, see §3

### 2.1 Hero (`home-hero`)

Two-column grid: copy left, `SyncedHero` right. Animated blob background
(pure CSS). Staggered `.reveal .d1–.d5` entrance animation on text.

**CTA**: single button, "Explore the most popular songs →", links to
`/top10/bossa-nova-songs` (public, no auth gate — the site's
highest-traffic/best-SEO page). Previously linked to `/register`; changed
because a first-time visitor benefits from seeing real content before being
asked to sign up, and a second "Watch the tour" ghost button was removed
entirely (`href="#"`, no tour content ever existed to point it at).

**Controller data (`HomeController::index()`):**
- `heroBars` / `heroRhythm` — sliced bars (`HERO_START..HERO_END`, currently
  `5..12`) from the Girl from Ipanema leadsheet (`HERO_SLUG =
  'the-girl-from-ipanema-1'` — **note the `-1` suffix**; the leadsheet's
  actual DB slug, not the Top10 config's array key, which is the bare
  `'the-girl-from-ipanema'` without a suffix — these are two different
  identifier spaces and mixing them up silently no-ops the whole hero, see
  the bug note below), built by `buildHeroBars()`
- `heroRhythmSlug` / `heroRhythmCaption` / `heroCitation` — sourced from
  `config/top10/bossa-nova-songs.php`'s `'the-girl-from-ipanema'` entry
  (`rhythmSlug`, `rhythmCaption`, `rhythmCitation` fields) and forwarded into
  `SyncedHero` → `SyncedPlayer`'s `rhythmLink`/`rhythmCaption`/`citation`
  props — these props were added to `SyncedPlayer` after the hero was first
  built and had never been wired through until this pass
- `rhythmPattern` — the `gilberto-rhythm` pattern by exact slug (used only by
  `libraryBoxes.rhythm`, see §3; the prop itself is otherwise unused in the
  template — a historical leftover, harmless)
- `libraryBoxes` — see §3

**Bug fixed this pass**: `HERO_SLUG` was `'the-girl-from-ipanema'` (missing
the `-1`) for an unknown prior period — `Leadsheet::where('slug',
HERO_SLUG)->first()` always returned null, so `buildHeroBars()` always
short-circuited to `[null, null]` and the hero silently rendered with no
bars/rhythm. Any chord-resolution logic downstream of that call was
therefore dead code regardless of correctness. Fixed to
`'the-girl-from-ipanema-1'`, matching the real DB row (id 551) and the Top10
config's own `syncedPlayer.slug` field.

**Chord-card resolution** (`resolveHeroCard()`, private method) mirrors
`SyncedPlayerController::resolveCard()` exactly: curated leadsheet voicing
first (via `LeadsheetViewerService::synthesizeMinimalCard()`, with a
DB-fingering overlay only if the voicing's own `fingers` are all zero, plus
computed interval labels), DB search (`ChordVoicingSearch::searchByName()`)
only as fallback when no curated voicing exists for that chord/slot. An
earlier version of this method inverted that priority (DB search first,
curated voicing only as last resort) — that's a second, independent reason
the hero's shapes drifted from what the real Top10 page shows; both bugs had
to be fixed together since the slug bug alone masked the priority bug (it
never got a chance to execute).

**SyncedHero/SyncedPlayer props**: SyncedPlayer is documented in full in
`docs/SBN-SyncedPlayer-Reference.md`. `SyncedHero.vue` is a thin wrapper —
just forwards `bars`, `rhythmPattern`, `muted`, `loop` (hardcoded `true`,
matches SyncedPlayer's own default), `rhythmCaption`, `rhythmLink`,
`citation`, `startChordName` (accepted but currently unused by the homepage
call — the hero's bars are already pre-sliced to start at the target chord,
so `startChordName` would be a no-op here; kept on the wrapper for a future
caller that needs it).

---

## 3. Library showcase (`.lib-boxes`, 6 boxes)

Section eyebrow/h2: "Explore the library" / "Everything you need to play".
Grid: `repeat(3, 1fr)`, 2 rows of 3. All six boxes are static previews (no
`requestAnimationFrame`, no continuous animation) — this was the core design
constraint carried over from cutting ChordRain (see §1a): real components are
fine to mount as long as nothing runs a continuous per-frame loop at rest.

Hover: outer `.lib-box` cards do **not** lift or glow on hover (both removed
— see "CSS gotchas" below). Instead, the *inner framed component* (the white
card holding the chord/rhythm/progression content) lifts + scales slightly
on hover: `translateY(-3px) scale(1.04)`, `.45s cubic-bezier(.22,1,.36,1)`.

| Box | Link | Content | Component/data |
|---|---|---|---|
| Chord Library | `/library/chords` | 5 real voicings, SyncedHero-style composition | See §3.1 |
| Song Library | `/library/songs` | 8 random cover photos, filmstrip | See §3.2 |
| Rhythm Patterns | `/library/rhythms` | Full `RhythmPattern` widget (non-interactive) | See §3.3 |
| Progressions | `/library/progressions` | Full `ChordProgressionViewer` (non-interactive) | See §3.4 |
| Theory & Analysis | `/theory` | `CircleOfFifths` edu widget | See §3.5 |
| Courses | `/learn` | 8 random course cover photos, filmstrip | See §3.6 (same pattern as §3.2) |

### 3.1 Chord Library box

**Not** a filmstrip of Top10 product photos (tried, rejected — "the chord
pics are too similar" side by side) and **not** rendered SVG diagrams in a
filmstrip either (tried, rejected — "doesn't look good," diagrams too small
to read at filmstrip scale). Current design: a fixed 5-up composition
modeled visually on `SyncedHero`'s card frame — one sharp hero voicing
center, two layers of neighbors receding either side (`.is-hero` /
`.is-inner` / `.is-outer` slot classes), each rendered as a real SVG via
`sbnRenderDiagramSVG()` + `sbnFormatChordHtml()` (both on `window`, loaded
globally via `public/js/chords.js`).

Slugs, hardcoded in `HomeController::buildLibraryBoxes()` (not
randomized — deliberately curated):
```
m7b5-drop2-roota                    outer-left
maj6-shell-roota-9                  inner-left
maj6-custom-roote-inv2-9-overAb     center hero — Top10 Bossa Nova Chords #1 (Db6/9/Ab)
dom7-drop3-roote-13                 inner-right
o7-drop2-roota                      outer-right
```
The center slug is the site's flagship voicing (see
`config/top10/bossa-nova-chords.php`'s first entry) — the opening chord of
Ipanema on the Getz/Gilberto recording. Its display name is hardcoded to
`'Db6/9/Ab'` rather than derived from `chordDisplayName()`, since the raw
`root_note`/`quality` columns on that row don't reconstruct the musically
correct symbol.

CSS: `.lib-chord-hero-row` + `.lib-chord-slot` (`public/css/home.css`).
Negative margins (`-14px` inner, `-20px` outer) pull the layers into a
tucked/overlapping composition rather than an evenly-spaced row. Chord name
renders **above** the diagram for all 5 slots (moved there and extended to
side slots per explicit request — was hero-only, name-below originally).
Framed with `.sbn-synced-hero-card` (shared class, `sbn-design-system.css` —
white bg, border, `box-shadow: 0 30px 60px -28px rgba(80,60,20,.18)`).

### 3.2 Song Library box

Filmstrip of up to 8 covers, `inRandomOrder()`, reshuffled every page load
(`Leadsheet::published()`, requires non-empty `cover_image_path`) — reflects
catalog size (70 published, 61 with covers) rather than one favorite.
`Leadsheet::cover_image_url` accessor prefixes `/images/songs/`.

CSS: `.lib-song-filmstrip img` — `flex: 1 1 0`, `aspect-ratio: 1/1` (covers
are 1024×1024; an earlier short/wide preview box cropped them badly on the
sides — fixed by making the strip itself the right shape). Hover-grow
(GradesSlider-inspired, pure CSS `flex-basis` transition, no JS): hovering
the strip shrinks all thumbnails to `flex: 0.6`, hovering one individual
thumbnail grows it to `flex: 3`. Transition `.7s cubic-bezier(.3,1,.3,1)` —
slowed from an initial `.4s` per explicit "feels hectic" feedback.

### 3.3 Rhythm Patterns box

Mounts the real `RhythmPattern.vue` (the full widget — header + two
hand-rows — not the compact `RhythmStrip.vue` used elsewhere), `:playable="false"`.
Confirmed safe to mount live: no `requestAnimationFrame`/`setInterval`/
continuous CSS keyframes; its one `<button>` (play/pause) is
`v-if="playable"`-gated, so it doesn't render at all here — safe to nest
inside the box's `<Link>` (an `<a>`), since browsers forbid interactive
controls nested inside `<a>`.

Pattern: `gilberto-rhythm` by exact slug (the site's signature groove — was
briefly `jazz-bossa-nova`, changed on request). Framed with
`.sbn-synced-hero-card`; since the full component is taller than the default
`200px` preview height, `.lib-box-rhythm .lib-box-preview` overrides to
`height: auto; min-height: 200px` and re-applies the frame's
background/radius explicitly (`sbn-design-system.css` loads *before*
`home.css`, so a plain-specificity override there would lose the cascade —
this pattern repeats for every box that reuses `.sbn-synced-hero-card`,
watch for it if adding another).

### 3.4 Progressions box

**Not a `<Link>`** — a plain `<div class="lib-box lib-box-progression">`
with its own inner `<Link class="card-more">Browse progressions →</Link>`.
`ChordProgressionViewer` always renders real `<button>` chord chips (the
numeral-selector row) regardless of `:interactive="false"` — only the
audio-preview *behavior* is suppressed, not the buttons themselves — so it
can't legally nest inside an `<a>`. This constraint is why the box's outer
frame doesn't lift/glow like the other 5: `.lib-box-progression` only shows
hover affordance on its own `card-more` link, not the whole card.

**Content history** (2 rejected iterations before landing here):
1. First: numeral→chord-name only via `HarmonicContext::numeralToChordName()`,
   `diagramData: null` — rendered chord chips with **no fretboard dots**,
   since `ChordProgressionViewer` needs real `diagram_data` to draw anything.
2. Second: static roman-numeral watermark card (mega-menu TOP10 CTA visual
   language — huge faded text behind a gradient card) — dropped per explicit
   "put the component back" request.
3. Current: real voicings. "The Authentic Cadence" (`V7 → I`, DB slug
   `perfect-authentic-cadence-2`) — `dom7-shell-roote` transposed to G via
   `ChordSerializer::serialize($chord, 'G')`, `maj7-shell-roota` (already
   rooted at C, no override needed). Chosen over the full
   `ProgressionBuilder`/`HarmonicContext` voice-leading pipeline
   (`ProgressionLibraryController::buildChordsFor()`) deliberately — two
   direct `ChordDiagram` lookups is enough for a 2-chord non-interactive
   preview and avoids pulling in the Viterbi voicing-selection machinery.

**Bug found and fixed while building this** (app-wide, not homepage-local):
`ChordDiagram::getInversionLabelAttribute()` defaults to `'Root Position'`
(title case, matches its own `INVERSIONS` map), but
`ChordProgressionViewer.vue`'s suppression check compared against
`'Root position'` (lowercase p) — the casing mismatch meant "Root Position"
always leaked into the chord-name display, everywhere this component
renders a root-position chord, not just here. Fixed the comparison string in
the component. A second instance of the same bug was found in
`LeadsheetViewerService::synthesizeMinimalCard()` (emitted lowercase
`'Root position'` too) and fixed to match.

**Small-container typography/sizing gap found and fixed** (also app-wide):
`ChordProgressionViewer` already had a `ResizeObserver`-driven
`[data-size="xs"/"sm"/"lg"]` system (element `sizeAttr`, thresholds `≤360px`
→ `xs`, `≤500px` → `sm`) that shrinks the fretboard/ribbon rendering
(`--ribbon-name`/`--ribbon-num`/`--stage-gap` CSS vars scoped to
`.sbn-prog-inner`) when the component sits in a narrow container — but it
never touched `.head-title` (fixed `18px`), the numeral chips
(`.sbn-numeral-chip`, fixed `12px`), or `.chord-card-aside` (fixed `min-width:
80px` floor). At the homepage box's ~340px width the fretboard shrank but the
header/chips/chord-card stayed full-size, reading as oversized. Extended the
same `xs`/`sm` breakpoints to scale all three — reuses the existing
ResizeObserver rather than adding a second mechanism; benefits every other
embed of this component at narrow widths, not just this box.

Framed with `.sbn-prog-viewer`'s **own** white card styling (the component
already supplies `background`/`border`/`radius` in its scoped styles) — the
homepage does *not* double-frame it with `.sbn-synced-hero-card` (tried,
looked wrong: two nested white cards read as "still grey," since the outer
frame was invisible behind the identical-looking inner one). Only the
elevation shadow is borrowed: `.lib-box-progression .sbn-prog-viewer { box-shadow:
0 30px 60px -28px rgba(80,60,20,.18); }` — same value as
`.sbn-synced-hero-card`, applied directly rather than via the shared class.

### 3.5 Theory & Analysis box

Mounts `resources/js/edu/widgets/CircleOfFifths.vue` (no props required,
fully self-contained, static-at-rest — click-to-select SVG, no
RAF/interval). Chosen over `CagedWidget.vue` (also verified safe) for being
"a more classic 'music theory' visual."

**Layout**: contained inset preview (`.lib-box-preview-widget`), **not**
full-bleed-behind-text (tried, reverted — "lets go back to text below" per
explicit request; the full-bleed version used a bottom scrim gradient
fading into `--clr-bg-card`, since removed). The widget's own `.cof-header`
("Circle of Fifths" label) — briefly hidden as "redundant with the box's own
`<h3>`" — was **restored**; it names the specific widget, distinct from the
box's "Theory & Analysis" section label.

**Real bug found and fixed**: `.cof-widget` (the component's own root) had
no explicit `width` — as a flex column with `align-items: center` it
shrink-wrapped to its content (the SVG, itself capped at
`max-width: 420px`) instead of stretching to fill its parent, so it sat
narrower than the box with visible empty space beside it ("component sits
left with space to right"). Fixed: added `width: 100%; box-sizing:
border-box` to `.cof-widget` and `width: 100%` to `.cof-svg` (keeping the
`420px` cap, since this is a shared widget also used on real theory/lesson
pages where an unbounded circle could grow uncomfortably large).

Vertical alignment: `.lib-box-theory .lib-box-preview` anchors
`flex-start`/`flex-start` (not the default `center`) — the SVG is taller
than the `200px` preview area, and center-alignment cropped it evenly
top/bottom; a bottom gradient scrim (`.lib-box-theory .lib-box-preview::after`,
fades to `var(--clr-bg)`) softens the resulting clip edge into the box
background instead of a hard cutoff.

### 3.6 Courses box

Identical pattern to §3.2 (Song Library) — reuses `.lib-song-filmstrip`
directly, no new CSS. `Course::published()`, requires non-empty
`featured_image_path`, `inRandomOrder()`, limit 8. Unlike leadsheet covers,
`featured_image_path` already stores a full `/images/...` path (no accessor
needed — 19/19 published courses have coverage). Links to `/learn` (public
catalog + course-detail teaser; the lesson player itself requires an account
during beta, per the route comment in `routes/web.php`).

---

## 4. `HomeController` — data methods

| Method | Returns |
|---|---|
| `index()` | Top-level Inertia payload — hero data + `libraryBoxes` |
| `buildHeroBars()` | `[bars, rhythmPattern]` — sliced Ipanema bars via `resolveHeroCard()`, mirrors `SyncedPlayerController::apiShow()` |
| `resolveHeroCard()` | Single chord → `ChordDiagramData`, mirrors `SyncedPlayerController::resolveCard()` priority exactly |
| `buildLibraryBoxes()` | All 6 boxes' preview data in one array (see §3 per-box breakdown) |
| `diagramDataToFretString()` | `diagram_data` JSON → hex fret string (`"x35453"`) for `sbnRenderDiagramSVG()` |
| `chordDisplayName()` | `(root_note, quality, extensions)` → chord symbol string (e.g. `"Cm7"`) — used only for the 4 non-hero chord-box slugs |

Constructor deps: `LeadsheetViewerService`, `ChordVoicingSearch`,
`ChordSerializer` (added this pass, for the Progressions box's real
voicings).

`chordDisplayName()`'s quality map — unchanged from the version that
originally lived in `ChordLibraryController` (that copy still exists there
too, for `/library/chords/rain`; the two are independent, small enough that
de-duplicating wasn't worth the coupling):

| DB quality | Symbol | DB quality | Symbol |
|---|---|---|---|
| `maj` | *(empty)* | `maj6` | `maj6` |
| `min` | `m` | `m6` | `m6` |
| `dom7` | `7` | `mMaj7` | `mMaj7` |
| `maj7` | `maj7` | `aug7` | `aug7` |
| `m7` | `m7` | `aug` | `aug` |
| `m7b5` | `m7b5` | `dim` | `dim` |
| `o7` | `°7` | `sus4`/`sus2` | `sus4`/`sus2` |
| `5` | `5` | `add9`/`madd9` | `add9`/`madd9` |
| `7sus4` | `7sus4` | `quartal` | `quartal` |

---

## 5. CSS — `public/css/home.css`

Scoped to `.home-page` — nothing bleeds into admin or other public pages.

| Selector | Purpose |
|---|---|
| `.home-page::before` | Grain overlay (unchanged) |
| `.home-wrap` | `max-width:1200px` centred container |
| `.reveal .d1–.d5` | Staggered entrance (unchanged) |
| `.hero-bg .blob` | CSS blob float (unchanged) |
| `.lib-boxes` | 6-box grid, `repeat(3, 1fr)` |
| `.lib-box` | Base card — background/border/radius; **no hover lift, no hover glow** (both removed, see below) |
| `.lib-box-preview` | Shared inset-preview container (Chord/Rhythm/Theory) |
| `.lib-chord-hero-row` / `.lib-chord-slot` | Chord box 5-up composition (§3.1) |
| `.lib-song-filmstrip` | Song + Courses box filmstrip (§3.2, §3.6) |
| `.lib-box-preview-widget` | Rhythm/Theory/Progressions inset container |
| `.sbn-synced-hero-card` (in `sbn-design-system.css`, not `home.css`) | Shared white-card frame; reused by Chord + Rhythm boxes |

### CSS gotchas hit this pass

- **`.lib-box` no longer lifts/glows on hover** — both the `translateY(-4px)`
  card lift and the `.lib-box::after` orange radial-gradient accent glow
  (`var(--clr-accent)` on hover) were removed per explicit "no lift and no
  orange gradient" request. The lift moved *inward*: the framed component
  card (`.sbn-synced-hero-card` / `.sbn-prog-viewer`) lifts+scales instead —
  see §3 intro.
- **Lift transition specificity fight**: the first lift-CSS attempt (plain
  `.sbn-synced-hero-card { transition: transform .2s ease }`) rendered as a
  snap, not a smooth lift. Root cause never fully isolated (two candidate
  causes: `ChordProgressionViewer`'s own scoped `.sbn-prog-viewer { transition:
  border-color .15s }` rule competing via Vue's scoped-attribute selector
  specificity for Progressions; unclear for Chord/Rhythm's plain-CSS
  `.sbn-synced-hero-card`). Fixed by brute force rather than full diagnosis:
  bumped selector specificity (two/three-class descendant chains) and added
  `!important`. If touching this again, worth actually isolating the cause
  rather than re-applying `!important`.
- **`public/css/mega-menu.css` is dead** — not loaded by `app.blade.php` at
  all (checked: only `resources/css/app.css` is `@vite`'d, which imports
  `resources/css/frontend/{base,header,mega-menu,top10-shared}.css` — the
  `resources/css/frontend/` copies are the real ones; `public/css/mega-menu.css`
  is a stale/legacy leftover, likely pre-Vite). **Wasted a full round editing
  it before catching this** — the real header/logo sizing rules live in
  `resources/css/frontend/header.css`, which loads *after*
  `resources/css/frontend/mega-menu.css` in `app.css`'s import order, so even
  the correct `frontend/mega-menu.css` file would have lost the cascade to
  `header.css`. Check `resources/css/app.css`'s `@import` order first before
  editing header/nav-adjacent CSS.
- **`:deep()` doesn't work in plain CSS files**: `home.css` is a plain,
  non-Vue-SFC stylesheet — `:deep(svg)` (Vue's scoped-style child-piercing
  combinator) was accidentally used there for the Theory box's SVG sizing at
  one point; it's meaningless outside a component's `<style scoped>` block
  and silently matched nothing. Fixed to a plain descendant selector.

---

## 6. Footer / header logo

`resources/js/Layouts/PublicLayout.vue` (header) and
`resources/js/Components/Footer.vue` (footer) both now render the real
brand mark — `/images/soulbossanova.png` (transparent background; a `.jpg`
version with a white background also exists at the same path minus
extension, kept as a fallback/alternate, not currently referenced) — replacing
what had been plain text (header: `<h1>Soul Bossa Nova</h1>` link, no image)
and a CSS gradient "S" badge + wordmark (footer). Both wrapped in
`<Link href="/" aria-label="Soul Bossa Nova — home">` since removing the
visible text left the link with no accessible name otherwise.

Sizing lives in `resources/css/frontend/header.css`'s `.site-branding img`
rule (**not** `mega-menu.css` — see the CSS gotcha above): `110px` desktop,
`90px` at `≤1024px`, `70px` at `≤767px`. Footer: `.footer-logo img { height:
70px }` in `Footer.vue`'s scoped styles.

Also linked from the footer: the two parked sections from §1a ("Chord Rain
(parked)" → `/library/chords/rain`, "Grades Slider (parked)" →
`/grades/slider`), under the existing "Tools" column.

---

## 7. Adding a new library-showcase box

1. Add a query/lookup in `HomeController::buildLibraryBoxes()`, return its
   data in the array
2. Add the corresponding TS interface field to `LibraryBoxes` in `Home.vue`
3. Add a `<Link class="lib-box lib-box-{name}">` block (or a plain `<div>`
   if the mounted component renders its own `<button>`s — see §3.4) inside
   `.lib-boxes` in `Home.vue`
4. Add scoped CSS under the `Library showcase boxes` comment block in
   `public/css/home.css` — reuse `.lib-box-preview`/`.lib-box-preview-widget`
   where the content fits a contained-inset layout; only reach for a
   full-bleed-behind-text treatment (Progressions' now-abandoned watermark
   card is the template to copy, in git history) if the content is genuinely
   diffuse/pattern-like, not a single focal component — text-over-content
   legibility was a recurring problem this pass
5. If mounting a real Vue component (not a static image/SVG), verify first
   that it has no `requestAnimationFrame`/`setInterval`/continuous CSS
   keyframe running unconditionally at rest — this was the whole reason
   ChordRain got cut (§1a); a click-triggered, self-terminating animation
   (like `ChordProgressionViewer`'s fretboard-pan) is fine, an always-on loop
   is not
