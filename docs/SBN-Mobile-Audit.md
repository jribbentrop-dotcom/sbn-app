# SBN — Mobile / Responsive Audit

_Started: 15 July 2026 — **status: chrome/structure pass complete; 3 device
bugs found + fixed.** All page-shell surfaces (nav, mega-menu drawer, hero, auth
card, filter sidebars, library-index chrome, footer) confirmed clean at
360/390/768/1024. Three content-driven overflow bugs (two reported from a real
phone, one found in a follow-up 360px sweep) were reproduced with seeded
fixtures and fixed (see **Confirmed bugs** below). A round-2 sweep of 11 routes
at 360px found no other overflow. Other content-heavy widgets (chord-card grid,
notation/tab, cinema viewer) still need a run against a populated `sbn.db`._

This doc is the home base for the mobile/responsive workstream (sibling to
`SBN-SEO-Content-Analyse.md` for SEO and `SBN-Security-Audit-2026-07-09.md` for
security). Fill it in as the audit progresses.

---

## How to actually run this audit

A static read can only flag *risks* — responsive bugs are visual and need to be
seen. Chromium + Playwright are preinstalled in the sandbox
(`PLAYWRIGHT_BROWSERS_PATH=/opt/pw-browsers`, no `playwright install` needed).
The method for the next session:

1. Build + serve the app (`npm run build`, `php artisan serve`) against a
   populated `sbn.db` (see the DB-checkout workflow in `CLAUDE.md`).
2. Drive key pages at representative widths — **360px** (small Android),
   **390px** (iPhone), **768px** (iPad portrait), **1024px** (iPad landscape) —
   and screenshot each. The app's breakpoints cluster at 1099 / 900 / 768 / 767,
   so those are the seams to check either side of.
3. Log each real issue below with page, width, and screenshot.

---

## Responsive strategy (the big structural finding)

Tailwind is loaded (`@tailwind base/components/utilities` in
`resources/css/app.css`) **but the Vue components use essentially zero Tailwind
responsive prefixes** (`sm:` / `md:` / `lg:` / `xl:` — 0 occurrences across all
44 pages). All responsiveness lives in **~15 hand-written CSS files** under
`public/css/` and `resources/css/frontend/`, each with its own `@media` blocks
(design-system 11, mega-menu 9, home 8, top10-shared 7, song-library 5,
chords 5, chord-library 5, …).

Implications for the audit:
- Responsive behaviour is **decoupled from markup** and scattered by file, so
  coverage is uneven and can't be reasoned about from a component alone — each
  page has to be checked visually.
- Many **fixed pixel widths** exist (e.g. chord cards 240px, course-player
  panels 280px, home hero blobs 480–680px) that rely on a media query to
  override them on narrow screens. Where an override is missing, the page will
  overflow horizontally. These fixed widths are the prime suspects — grep
  `width:\s*[0-9]{3,}px` per CSS file and check each has a mobile counterpart.
- `<meta name="viewport" content="width=device-width, initial-scale=1">` is
  correctly present on all layouts (`app.blade.php`, `welcome.blade.php`,
  `layouts/admin.blade.php`) — so the baseline is right; the risk is in the
  content, not the viewport.

_(Not a directive to migrate everything to Tailwind utilities — just the reason
mobile QA here must be visual and page-by-page.)_

---

## Priority surfaces to QA (checklist)

High-traffic / high-complexity first — these are the ones most likely to break.
`[x]` = confirmed clean at real widths; `[~]` = chrome confirmed but the
content body needs a populated DB to fully exercise:

- [x] **MegaMenu / primary nav** (`Components/MegaMenu.vue`,
      `resources/css/frontend/mega-menu.css`) — hamburger appears at ≤767px
      (`.hamburger { display:flex }`, `.main-navigation { display:none }`); the
      slide-in `.mobile-drawer` (Home / Courses / Explore / Shop, collapsible,
      backdrop overlay, close button) opens with **zero overflow** at 360px.
- [x] **Home** (`Pages/Home.vue`, `home.css`) — hero blobs (480–680px) are
      clipped by `.hero-bg { overflow:hidden }` and the chord-rain section's own
      `overflow:hidden`; no horizontal scroll at any width. Developer already
      left defensive comments about x-overflow here.
- [x] **Chord Library index + show** (`Pages/Library/Chords/{Index,Show}.vue`,
      1297 / 1237 lines — the biggest pages) — the 240px value is the **filter
      sidebar**, reset to `width:100%` + static at ≤1024px; the card grid uses
      `minmax(120–150px, 1fr)` (safe). Index *chrome* clean at 360/768. Chord
      diagram fret/string stroke-width bug (invisible lines on mobile) found +
      fixed — see Findings below.
- [x] **Song viewer / cinema** (`Components/Leadsheet/LeadsheetViewer.vue`,
      `Components/Cinema/*.vue`, `song-library.css`) — 340px viewer sidebar
      stacks (`width:100%`) at ≤1024px; wide notation scrolls in-container
      (`SheetMiniPlayer` has `overflow-x:auto; min-width:0`). Index chrome
      clean. Code-reviewed 2026-07-16, six further bugs found and fixed (see
      Findings below: sidebar order, content not stretching, hero chord card
      not reflowing, Cinema top bar not wrapping, Options dropdown overflow,
      transport deck hidden on touch, glyph play icon).
- [x] **Course player** (`Pages/Courses/Player.vue`, `course-player.css`) —
      **BUG-1 (fixed):** the off-canvas drawer had no default-hidden state, so
      the full lesson menu sat permanently expanded at the top on mobile.
      Reproduced with a seeded 8-lesson course; drawer now hidden by default and
      opens/closes via the menu button + overlay. See Confirmed bugs.
- [x] **Rhythm library** (`Pages/Library/Rhythms/Index.vue`,
      `Components/Library/RhythmStrip.vue`) — **BUG-2 (fixed):** fixed 20px
      pattern cells overflowed the page (960px vs 360px viewport on a 32-step
      pattern). Now fluid on ≤768px. See Confirmed bugs.
- [ ] **Tab editor** (`tab-editor/TabEditor.vue`) — admin-only; likely
      desktop-only by design, confirm and note as out-of-scope if so.
- [ ] **Shop** (`Pages/Shop/{Index,Show}.vue`, `shop.css`) — not run
      (ProductSeeder not invoked by `DatabaseSeeder`; empty shop).
- [x] **Auth card** (`Login`/`Register` via `AuthCard.vue`) — modal over blurred
      backdrop; full Register form (beta notice + 4 fields + CTA) fits a 360px
      viewport with no overflow.

---

## Confirmed bugs (reported from a real phone, reproduced + fixed)

### BUG-1 — Course player: lesson menu always open on mobile
**Symptom (user):** on a phone the course player's main menu is always open;
the course needs a proper mobile menu.
**Root cause:** `course-player.css` had the *open* drawer state
(`.vC-grid.mobile-sidebar-open .vC-nav { position:fixed; width:280px }`) and a
working toggle button (`.vC-mobile-menu-btn`, wired to `mobileSidebarOpen`), but
**no rule hiding `.vC-nav` by default on mobile.** At ≤900px the grid collapses
to one column and the full lesson list simply stacked at the top of the page,
permanently expanded — the toggle/overlay machinery was dead weight.
**Fix:** in the `@media (max-width: 900px)` block — `.vC-nav { display:none }` by
default; `.mobile-sidebar-open .vC-nav { display:flex }` as the drawer (added
`max-width:85vw` + `overflow-y:auto`); hid the rail-collapse chevron (meaningless
on mobile). **Verified** with a seeded 8-lesson course at 360px: menu hidden by
default, button opens the 280px drawer with dimmed overlay, overlay-tap closes.
**Open design question (from user):** whether the whole course player should
become an app-like full-screen experience on mobile. The fix above resolves the
bug; the larger redesign is a separate decision — not done.

### BUG-2 — Rhythm library overflows to the right
**Symptom (user):** the rhythm library scrolls horizontally.
**Root cause:** `RhythmStrip.vue` in `is-fixed-cells` mode (used by the library
index, `:fixed-cells="true"`) sets `grid-auto-columns: 20px`. A 16- or 32-step
pattern renders N×20px in one non-shrinking row — a 32-step pattern is ~806px
wide, forcing the whole page to **960px at a 360px viewport (600px of
overflow)**. The card grid itself was fine (`1fr` at ≤768px); the overflow came
from inside the strip.
**Fix:** scoped `@media (max-width: 768px)` override reverting fixed cells to
`grid-auto-columns: minmax(0, 1fr)` so the strip shrinks to fit (matching the
course player's fluid strip). **Verified** with seeded 16- and 32-step patterns:
overflow 0 at 360px, both strips render legibly inside their cards.

### BUG-3 — Rhythm *Show* page overflows on wide patterns
**Symptom:** found during a follow-up 360px sweep — the pattern **detail** page
(`/library/rhythms/{slug}`) scrolled horizontally (27px on a 16-step pattern,
91px on a 32-step). Different component from BUG-2: `RhythmPattern.vue` (the full
pattern view), where `.sbn-rhythm-cell` kept a `min-width: 14px` floor and the
`.sbn-rhythm-cells` flex item had default `min-width: auto`, so a full pattern
couldn't shrink below its content width. The beat-label row ("1 e & a …") added a
text min-content floor on top.
**Fix:** in the existing `@media (max-width: 640px)` block — `.sbn-rhythm-cell {
min-width: 0; overflow: hidden }` (the `overflow:hidden` kills the label-text
floor) and `.sbn-rhythm-cells { min-width: 0; gap: 3px }`. **Verified:** overflow
0 at 360px for both 16- and 32-step patterns; the full pattern renders inside the
card. Desktop/tablet untouched (scoped ≤640px).

---

## Unrelated console noise seen while testing (not mobile, not a regression)

Flagged 2026-07-18 in the browser console while live-testing the merged mobile
branches on `/library/songs/allegretto-in-b-minor/viewer`. Neither item was
introduced by the mobile work — confirmed via `git log` that the responsible
files weren't touched by `mobile-app-audit` or `chord-diagram-mobile-visibility`
(last touched weeks earlier, in unrelated commits `d8dd4d7a` / `9bdca854`).
Logging here only so they aren't lost; not in scope for the mobile branches.

- **`[Vue warn] Invalid watch source: null` spammed once per chord card** in
  `LeadsheetViewer` → `ChordGridView` → `ChordCard`. Root cause:
  `resources/js/tab-editor/components/ChordCard.vue:148` does
  `watch(inlineRenameTarget, ...)` where `inlineRenameTarget =
  inject('inlineRenameTarget', null)`. This `ChordCard.vue` is shared between
  the admin tab editor (which provides that inject key) and the public
  read-only `LeadsheetViewer` (which doesn't), so in the viewer the inject
  always resolves to the literal default `null` and `watch(null, …)` is
  invalid — Vue warns and no-ops. Harmless (rename is a no-op in read-only
  mode anyway) but noisy on every leadsheet page. **Fix:** guard with
  `if (!inlineRenameTarget) return` before the `watch()` call, or only
  register the watch when the inject key is actually provided (e.g.
  `inlineRenameTarget && watch(...)`). Small, self-contained — safe to just
  fix directly next time someone's in this file rather than needing its own
  session.
- **`WebSocket connection to 'ws://localhost:8080/app/...' failed`** — Laravel
  Reverb (broadcasting) isn't running as a local process in this dev setup.
  Expected/harmless unless testing live community/messages features; not a
  code bug.

---

## Open design follow-ups (not bugs — need a dedicated mobile session)

Flagged 2026-07-18 while reviewing the merged chrome/structure + stroke-width
passes live in the browser. These are redesigns, not overflow fixes — scope
each properly (mockup/decision first) rather than patching CSS in place.

1. **Course player menu feels detached from the course player on mobile.**
   The BUG-1 fix (hidden-by-default off-canvas drawer, see above) makes the
   menu *functional*, but the reviewer's reaction live was that the drawer
   still reads as a bolted-on desktop sidebar rather than a mobile-native
   pattern. Worth revisiting the "Open design question" already logged under
   BUG-1: **should the course player become a full-screen, app-like mobile
   experience** (e.g. a bottom-sheet or tab-bar lesson switcher instead of a
   left-drawer clone)? Needs a design pass, not just CSS.
2. **Cinema needs a mobile-specific redesign, not a reflow patch.** The
   round-2 fix made `StageHeroNow.vue`'s hero chord card *not overflow* on
   phones (stacks glyph + diagram, shrinks the diagram to 140px), but the
   live reaction was that the hero chord panel probably shouldn't be there at
   all on mobile — screen space is too tight for it to earn its place next to
   the transport/notation. Consider **dropping the hero chord panel on
   mobile** (or replacing it with something lighter) rather than continuing
   to shrink it to fit.

---

## Findings

### Live pass — round 1 (chrome / structure), 15 Jul 2026

Method: `composer install` + `npm install` + `npm run build`, fresh SQLite
migrated + `db:seed`, `php artisan serve`, driven with Playmwright/Chromium at
360 / 390 / 768 / 1024. Each page measured `documentElement.scrollWidth −
clientWidth` and listed any element extending past the viewport.

**Result: no horizontal overflow found on any tested surface at any width.**
The hand-written `@media` overrides for the flagged fixed-px widths are all
present and correct — the static "prime suspects" (240px filter sidebars, 340px
viewer sidebar, 480–680px hero blobs, 280px course-player nav) each have a mobile
counterpart or an `overflow:hidden` clip. Risk is materially lower than the
scaffold feared.

| Page | Widths | Result | Status |
|---|---|---|---|
| Home (`/`) | 360/390/768/1024 | overflow 0; hero + cards stack; hamburger present | ✅ |
| Mega-menu drawer (open) | 360 | overflow 0; slide-in + backdrop + close work | ✅ |
| Register (`/register`) | 360/390/768/1024 | overflow 0; full auth card fits 360px | ✅ |
| Login (`/login`) | 360/390/768/1024 | overflow 0 | ✅ |
| Catalog (`/learn`) | 360/390/768/1024 | overflow 0 (chrome; catalog empty) | ✅ |
| Contact (`/contact`) | 360/390/768/1024 | overflow 0 | ✅ |
| Chords index (`/library/chords`) | 360/768 | overflow 0 (chrome; grid empty) | ✅ chrome |
| Songs index (`/library/songs`) | 360/768 | overflow 0 (chrome; grid empty) | ✅ chrome |
| Rhythms index (`/library/rhythms`) | 360/768 | overflow 0 (chrome; grid empty) | ✅ chrome |
| Progressions index (`/library/progressions`) | 360/768 | overflow 0 (chrome; empty) | ✅ chrome |
| Theory (`/theory`) | 360/768 | overflow 0 | ✅ |

Screenshots captured for each (scratchpad, not committed). Console noise seen
(favicon 404, Vite HMR socket reset) is sandbox-only, not app bugs.

### Not yet confirmed (needs a populated `sbn.db`)

The DatabaseSeeder only seeds skill nodes + scale exercises — **no courses,
lessons, leadsheets, chord diagrams, rhythm patterns, or products** — so the
content widgets rendered empty and their at-width behavior is unverified:

- Chord-library **card grid** at 360px (the 240px card / archetype tiles) and the
  Chord **Show** page (1237 lines, largest surface).
- Song **Viewer** notation/tab actually rendering wide, and **Cinema** mode
  (`overflow-x:hidden` at line 711 could clip real content — check it).
- **Course Player** body (sidebar + lesson HTML with `<sbn-*>` widgets).
- **Shop** (needs `ProductSeeder`, which `DatabaseSeeder` doesn't call).

### Live pass — round 2 (code review of content-heavy widgets), 16 Jul 2026

| Page | Width | Issue | Severity | Status |
|---|---|---|---|---|
| _(e.g. Chords/Index)_ | _360px_ | _horizontal overflow from 240px cards_ | — | ⬜ |
| Any `<sbn-chord>` / chord diagram (Chords, lessons, ChordCard) | small mobile | Fret + string lines invisible — `sbnRenderDiagramSVG` (`public/js/chords.js`) and `AnimatedChordDiagram.vue` drew grid lines at `stroke-width="0.4"` inside an 88×95/98 viewBox scaled via `width:100%`; below the viewBox's native size the stroke rendered under 1 physical px | High | ✅ Fixed 2026-07-16 — bumped to `stroke-width="1"` + `vector-effect="non-scaling-stroke"` (locks stroke to a constant on-screen px regardless of scale) in both renderers |
| Ear-training widgets: `RepeatSignsWidget.vue` (staff lines, 5 diagrams), `Fretboard.vue`, `BasicChordsWidget.vue`, `VoiceLeading.vue` (string lines) | small mobile | Same pattern as the chord-diagram bug — sub-1 `stroke-width` (0.75–0.8) on fluid `width:100%` SVGs with no `vector-effect`, so thin strings/staff lines could vanish on narrow screens | Medium | ✅ Fixed 2026-07-16 — added `vector-effect="non-scaling-stroke"` (+ staff lines bumped 0.75→1) to all four |
| Leadsheet Viewer ("Classic", `LeadsheetViewer.vue`) | ≤1024px | Two bugs in the `@media (max-width: 1024px)` block: (1) `.sbn-leadsheet-sidebar { order: -1 }` rendered the EduPanel *above* the score instead of below; (2) `.sbn-leadsheet-content`'s desktop `align-items: flex-start` carried into the mobile column layout, so children sized to their own content width instead of stretching — Chords/Analysis view (intrinsic-width flex rows) rendered narrower than full width | High | ✅ Fixed 2026-07-16 — `order: 1` on the sidebar (after main), `align-items: stretch` + explicit `.sbn-leadsheet-main { width: 100% }` added to the media query. Cinema not yet audited — same review pending. |
| Transport bar (`HoverRevealDeck.vue`, shared by Classic + Cinema) | touch devices | Reveal was driven purely by `mouseenter`/`mouseleave` (`useHoverRevealTransport.js`) — touch has no real hover signal, so the deck only flashed briefly on tap and stayed hidden otherwise | High | ✅ Fixed 2026-07-16 — `@media (hover: none)` forces `.sbn-hover-deck.is-hidden` to render visible, so touch/coarse-pointer devices always show the deck (matches standard mobile media-player UX: hover-reveal is a mouse-only affordance, always-visible controls is the touch default) |
| Transport play/pause button (`TransportDeck.vue`, shared by Classic + Cinema) | all | Raw Unicode glyphs (`▶` / `❚❚`) for the primary button — renders inconsistently (weight/alignment) across platform fonts, reported as an "ugly" icon in Classic | Low | ✅ Fixed 2026-07-16 — replaced with inline SVG icons (`.tdeck-icon`), consistent rendering everywhere |
| Cinema hero "Now Playing" chord card (`StageHeroNow.vue`) | ≤768px | `.stage-hero-content` kept its desktop 2-col grid (`1fr auto`) with the chord-card diagram locked at `width: 180px` all the way down — combined with the card's 32px side padding, that left almost no room for the chord-name glyph on a phone, so it overflowed/squashed instead of reflowing | High | ✅ Fixed 2026-07-16 — added a `@media (max-width: 768px)` block that stacks glyph + diagram instead of squeezing them side by side, shrinks the diagram to 140px, reduces glyph font-size, and switches the absolutely-positioned beat row to normal flow (it was pinned to the card's bottom edge, which only worked with the old fixed 2-col height) |
| Cinema top bar (`StageTopBar.vue`) | ≤768px | Renders the same breadcrumb row as Classic (title/segments + Options menu + Classic/Cinema `ViewToggle`) but hand-duplicated `Breadcrumb.vue`'s markup instead of using the component, so it never got Classic's `flex-wrap: wrap` treatment (which lived in `LeadsheetViewer.vue`, reaching only Classic's own `<Breadcrumb>` instance) — row overflowed horizontally on phones instead of wrapping | High | ✅ Fixed 2026-07-16 — refactored `StageTopBar.vue` to actually render `<Breadcrumb>` (Options menu + `ViewToggle` moved into its `#actions` slot) instead of duplicating its markup, eliminating the drift risk entirely rather than re-patching a second copy |
| Breadcrumb trail too long on phone (app-wide — every page using `Breadcrumb.vue`, plus Cinema via the fix above) | ≤640px | Full multi-level trails (e.g. `Songs › Bossa Nova › Beginner › Wave`) plus the page's own action buttons (Options menu, view toggle) didn't fit on a phone width | Medium | ✅ Fixed 2026-07-16 — moved the `flex-wrap` mobile treatment *and* added segment truncation directly into `Breadcrumb.vue` (via `matchMedia('(max-width: 640px)')`): shows at most the last 2 levels (immediate parent + current page) on phone, full trail everywhere else. Centralizing in the shared component (rather than per-page CSS) means it's automatically app-wide and can't drift out of sync the way Cinema's top bar just did |
| Options dropdown (`TopBarMenu.vue`, shared by Classic + Cinema) | ≤640px | `.tbm-panel` is a fixed 220px popover anchored via `right: 0` *relative to its trigger button*. Once the header row wraps (the fix above), the Options trigger is often the first item on its own line — i.e. near the viewport's **left** edge — so a right-anchored panel would render mostly off-screen to the left and be unreachable/invisible under the page's `overflow-x: hidden` | Medium | ✅ Fixed 2026-07-16 — `max-width: calc(100vw - 32px)` safety clamp, plus `@media (max-width: 640px) { right: auto; left: 0 }` to anchor from the trigger's left edge instead, which stays on-screen given the trigger's own likely position after wrap |
| Everything else checked this pass: `EduPanel.vue`, `RateSlider.vue`, `ViewToggle.vue`, `Breadcrumb.vue`, `StageSectionsGrid.vue` (horizontal-scroll chord/tab strips — scrolling by design, not a bug), `VideoEmbed.vue`, `ChordCard.vue` | — | No fixed-width/overflow issues found — all fluid or intentionally scrollable | — | ✅ Reviewed 2026-07-16, no changes needed |

---

## Next steps

1. **Content pass**: run the same Playwright harness against a populated
   `sbn.db` (copy the real DB in, or extend `DatabaseSeeder` with a content
   fixture) and drive the four "not yet confirmed" surfaces above at 360/768.
   The harness is the throwaway `_audit*.mjs` pattern from this session
   (Chromium at `/opt/pw-browsers/chromium-1194/chrome-linux/chrome`).
2. Fix any content-overflow cases (usually a missing `max-width: 100%` or an
   `overflow-x:auto` wrapper on a wide notation/tab block).
3. Decide whether the tab editor / admin surfaces are in scope for mobile at all.

### Sandbox setup notes (for the next session)

- This is a fresh cloud clone — **no Windows mount, no `sbn.db`**; the
  CLAUDE.md db-checkout workflow does not apply here.
- `composer install` must be a **single** run with `--prefer-dist` (two
  concurrent runs corrupt the laravel/framework clone: "reference is not a
  tree"). Root disables plugins; `--no-scripts --no-plugins` is fine.
- Playwright is pinned to a version whose auto-download browser is absent —
  launch with `executablePath: '/opt/pw-browsers/chromium-1194/chrome-linux/chrome'`.
- `DatabaseSeeder`'s test-user `create()` throws on a second run (unique email);
  create users with `updateOrCreate` via tinker instead.
