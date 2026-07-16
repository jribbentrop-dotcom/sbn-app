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
- [ ] **Song viewer / cinema** (`Components/Leadsheet/LeadsheetViewer.vue`,
      `song-library.css`) — notation/tab is wide; must scroll inside its own
      container, not the page body.
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

---

## Next steps

1. Stand up the app with a populated DB and run the visual pass above.
2. Fill the Findings table; fix the clear overflow cases (usually a missing
   `max-width: 100%` or a media-query override for a fixed px width).
3. Decide whether the tab editor / admin surfaces are in scope for mobile at all.
