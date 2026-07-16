# SBN — Mobile / Responsive Audit

_Started: 15 July 2026 — **status: scaffold, one workstream complete.** Most
findings below are still from a static review only. The library filter-sidebar
workstream (search bar + filter sidebar across all library pages) has been
investigated, fixed, and verified — see Findings — but that verification used
headless-Chromium screenshots of isolated static HTML repros built from the
real compiled CSS classes, not the live app with a populated DB (this cloud
session has no DB access — see `CLAUDE.md`'s DB workflow, which assumes a
mounted-Windows-host sandbox this session doesn't have). Re-verify in a
session with real data before treating it as fully device-tested._

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
- [x] **Chord Library index — filter sidebar** — see Findings; fixed via the
      shared mobile filter drawer. Card widths (240px fixed) below are
      **still unaudited** — this only covers the sidebar.
- [ ] **Chord Library index + show — card grid** (`Pages/Library/Chords/{Index,Show}.vue`,
      1297 / 1237 lines — the biggest pages) — 240px fixed card widths.
- [ ] **Song viewer / cinema** (`Components/Leadsheet/LeadsheetViewer.vue`,
      `song-library.css`) — notation/tab is wide; must scroll inside its own
      container, not the page body.
- [ ] **Course player** (`Pages/Courses/Player.vue`, `course-player.css`) —
      sidebar + content layout at 280px fixed panel widths.
- [ ] **Tab editor** (`tab-editor/TabEditor.vue`) — admin-only; likely
      desktop-only by design, confirm and note as out-of-scope if so.
- [x] **Shop — filter sidebar** (`Pages/Shop/Index.vue`) — same fix as the
      library pages (was actually missed in the first drawer pass and briefly
      regressed — see Findings). Rest of Shop is **still unaudited**.
- [ ] **Shop — everything else** (`Pages/Shop/{Index,Show}.vue`, `shop.css`).
- [ ] **Auth card** (`Login`/`Register` via `AuthCard.vue`) — modal over blurred
      backdrop; check it fits a 360px viewport.

---

## Findings

| Page | Width | Issue | Severity | Status |
|---|---|---|---|---|
| Songs/Index, Chords/Index | 900–1024px (unfolded-foldable-phone range) | Filter sidebar's page-CSS `@media(1024px)` stacking rule and the sidebar's own `@media(900px)` full-width rule fought each other — the sidebar's CSS class (`sbn-lib-filter-sidebar`) never matched the selector each page's own stylesheet was targeting (`sbn-filter-sidebar`), so it rendered as a squeezed fixed-width column instead of stacking full width. | High — core UX broken at a real, common width | ✅ Fixed — replaced with a mobile drawer (`FilterToggleButton`/`FilterSidebar` components), breakpoints aligned to 900px everywhere |
| All library pages (Songs, Chords, Rhythms, Courses, Progressions, Theory) | ≤600px | Filter sidebar was `display: none` below 600px — filters were simply unreachable on a real phone, not just squeezed. | High | ✅ Fixed — same drawer fix; sidebar is reachable via the "Filters" toggle at every width now |
| Shop/Index | ≤900px | Missed in the first pass (7 pages share this sidebar pattern; Shop wasn't in the initial audit of who uses it). CSS change made the sidebar an off-canvas drawer with no toggle button to open it — briefly a regression, not just a pre-existing bug. | High (temporary) | ✅ Fixed same session |
| Songs/Index | any | Composer filter rendered all 40 server-capped names as flat pills in the 220px sidebar column — not a responsive bug per se, but unusable content density. | Medium | ✅ Fixed — capped to 10 visible + "+N more" toggle |

_Verified via headless-Chromium screenshots of static HTML repros (real compiled CSS, hand-built markup) at 390/700/890/950/1024/1200px — not the live app with real data. Still to populate: everything else on the checklist above._

---

## Next steps

1. Stand up the app with a populated DB and run the visual pass above —
   including re-verifying the filter-drawer fix against the real app, since
   it was only checked via static repros (see note above Findings).
2. Fill the Findings table for the remaining checklist items (Chord/card grid
   widths, MegaMenu, Home hero blobs, Song viewer, Course player, Auth card,
   rest of Shop); fix the clear overflow cases (usually a missing
   `max-width: 100%` or a media-query override for a fixed px width).
3. Decide whether the tab editor / admin surfaces are in scope for mobile at all.
