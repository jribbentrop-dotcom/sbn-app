# SBN — Mobile / Responsive Audit

_Started: 15 July 2026 — **status: scaffold.** Findings so far are from a static
review only; none have been confirmed at real device widths yet._

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

High-traffic / high-complexity first — these are the ones most likely to break:

- [ ] **MegaMenu / primary nav** (`Components/MegaMenu.vue`,
      `resources/css/frontend/mega-menu.css`) — has the most `@media` logic;
      confirm the mobile collapse works at 767/768 and the panel doesn't
      overflow.
- [ ] **Home** (`Pages/Home.vue`, `home.css`) — hero blobs use 480–680px fixed
      widths; check for horizontal scroll.
- [ ] **Chord Library index + show** (`Pages/Library/Chords/{Index,Show}.vue`,
      1297 / 1237 lines — the biggest pages) — 240px fixed card widths.
- [x] **Song viewer / cinema** (`Components/Leadsheet/LeadsheetViewer.vue`,
      `Components/Cinema/*.vue`) — code-reviewed 2026-07-16, six bugs found and
      fixed (see Findings below: sidebar order, content not stretching, chord
      diagram/ear-training stroke widths, hero chord card not reflowing,
      Cinema top bar not wrapping, Options dropdown overflow, transport deck
      hidden on touch, glyph play icon). Still needs a real-device/emulator
      pass to visually confirm — sandbox has no working `npm`/`vite` build.
- [ ] **Course player** (`Pages/Courses/Player.vue`, `course-player.css`) —
      sidebar + content layout at 280px fixed panel widths.
- [ ] **Tab editor** (`tab-editor/TabEditor.vue`) — admin-only; likely
      desktop-only by design, confirm and note as out-of-scope if so.
- [ ] **Shop** (`Pages/Shop/{Index,Show}.vue`, `shop.css`).
- [ ] **Auth card** (`Login`/`Register` via `AuthCard.vue`) — modal over blurred
      backdrop; check it fits a 360px viewport.

---

## Findings

_None confirmed yet — populate as the visual pass runs. Suggested row format:_

| Page | Width | Issue | Severity | Status |
|---|---|---|---|---|
| _(e.g. Chords/Index)_ | _360px_ | _horizontal overflow from 240px cards_ | — | ⬜ |
| Any `<sbn-chord>` / chord diagram (Chords, lessons, ChordCard) | small mobile | Fret + string lines invisible — `sbnRenderDiagramSVG` (`public/js/chords.js`) and `AnimatedChordDiagram.vue` drew grid lines at `stroke-width="0.4"` inside an 88×95/98 viewBox scaled via `width:100%`; below the viewBox's native size the stroke rendered under 1 physical px | High | ✅ Fixed 2026-07-16 — bumped to `stroke-width="1"` + `vector-effect="non-scaling-stroke"` (locks stroke to a constant on-screen px regardless of scale) in both renderers |
| Ear-training widgets: `RepeatSignsWidget.vue` (staff lines, 5 diagrams), `Fretboard.vue`, `BasicChordsWidget.vue`, `VoiceLeading.vue` (string lines) | small mobile | Same pattern as the chord-diagram bug — sub-1 `stroke-width` (0.75–0.8) on fluid `width:100%` SVGs with no `vector-effect`, so thin strings/staff lines could vanish on narrow screens | Medium | ✅ Fixed 2026-07-16 — added `vector-effect="non-scaling-stroke"` (+ staff lines bumped 0.75→1) to all four |
| Leadsheet Viewer ("Classic", `LeadsheetViewer.vue`) | ≤1024px | Two bugs in the `@media (max-width: 1024px)` block: (1) `.sbn-leadsheet-sidebar { order: -1 }` rendered the EduPanel *above* the score instead of below; (2) `.sbn-leadsheet-content`'s desktop `align-items: flex-start` carried into the mobile column layout, so children sized to their own content width instead of stretching — Chords/Analysis view (intrinsic-width flex rows) rendered narrower than full width | High | ✅ Fixed 2026-07-16 — `order: 1` on the sidebar (after main), `align-items: stretch` + explicit `.sbn-leadsheet-main { width: 100% }` added to the media query. Cinema not yet audited — same review pending. |
| Transport bar (`HoverRevealDeck.vue`, shared by Classic + Cinema) | touch devices | Reveal was driven purely by `mouseenter`/`mouseleave` (`useHoverRevealTransport.js`) — touch has no real hover signal, so the deck only flashed briefly on tap and stayed hidden otherwise | High | ✅ Fixed 2026-07-16 — `@media (hover: none)` forces `.sbn-hover-deck.is-hidden` to render visible, so touch/coarse-pointer devices always show the deck (matches standard mobile media-player UX: hover-reveal is a mouse-only affordance, always-visible controls is the touch default) |
| Transport play/pause button (`TransportDeck.vue`, shared by Classic + Cinema) | all | Raw Unicode glyphs (`▶` / `❚❚`) for the primary button — renders inconsistently (weight/alignment) across platform fonts, reported as an "ugly" icon in Classic | Low | ✅ Fixed 2026-07-16 — replaced with inline SVG icons (`.tdeck-icon`), consistent rendering everywhere |
| Cinema hero "Now Playing" chord card (`StageHeroNow.vue`) | ≤768px | `.stage-hero-content` kept its desktop 2-col grid (`1fr auto`) with the chord-card diagram locked at `width: 180px` all the way down — combined with the card's 32px side padding, that left almost no room for the chord-name glyph on a phone, so it overflowed/squashed instead of reflowing | High | ✅ Fixed 2026-07-16 — added a `@media (max-width: 768px)` block that stacks glyph + diagram instead of squeezing them side by side, shrinks the diagram to 140px, reduces glyph font-size, and switches the absolutely-positioned beat row to normal flow (it was pinned to the card's bottom edge, which only worked with the old fixed 2-col height) |
| Cinema top bar (`StageTopBar.vue`) | ≤768px | Renders the same breadcrumb row as Classic (title/segments + Options menu + Classic/Cinema `ViewToggle`) but never got Classic's `flex-wrap: wrap` treatment for it (`LeadsheetViewer.vue`'s `.sbn-breadcrumb` media rule only reaches Classic's own `<Breadcrumb>` instance) — StageTopBar builds `.sbn-breadcrumb--lg` itself inline rather than through that component, so it needed its own copy of the fix and didn't have one. Row would overflow horizontally on phones instead of wrapping to two lines | High | ✅ Fixed 2026-07-16 — added matching `@media (max-width: 768px) { .stage-top { flex-wrap: wrap; row-gap: 10px } }` |
| Options dropdown (`TopBarMenu.vue`, shared by Classic + Cinema) | ≤640px | `.tbm-panel` is a fixed 220px popover anchored via `right: 0` *relative to its trigger button*. Once the header row wraps (the fix above), the Options trigger is often the first item on its own line — i.e. near the viewport's **left** edge — so a right-anchored panel would render mostly off-screen to the left and be unreachable/invisible under the page's `overflow-x: hidden` | Medium | ✅ Fixed 2026-07-16 — `max-width: calc(100vw - 32px)` safety clamp, plus `@media (max-width: 640px) { right: auto; left: 0 }` to anchor from the trigger's left edge instead, which stays on-screen given the trigger's own likely position after wrap |
| Everything else checked this pass: `EduPanel.vue`, `RateSlider.vue`, `ViewToggle.vue`, `Breadcrumb.vue`, `StageSectionsGrid.vue` (horizontal-scroll chord/tab strips — scrolling by design, not a bug), `VideoEmbed.vue`, `ChordCard.vue` | — | No fixed-width/overflow issues found — all fluid or intentionally scrollable | — | ✅ Reviewed 2026-07-16, no changes needed |

---

## Next steps

1. Stand up the app with a populated DB and run the visual pass above.
2. Fill the Findings table; fix the clear overflow cases (usually a missing
   `max-width: 100%` or a media-query override for a fixed px width).
3. Decide whether the tab editor / admin surfaces are in scope for mobile at all.
