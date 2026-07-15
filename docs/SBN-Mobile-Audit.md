# SBN — Mobile / Responsive Audit

_Started: 15 July 2026 — **status: chrome/structure pass complete; 2 device
bugs found + fixed.** All page-shell surfaces (nav, mega-menu drawer, hero, auth
card, filter sidebars, library-index chrome, footer) confirmed clean at
360/390/768/1024. Two content-driven bugs reported from a real phone were
reproduced with seeded fixtures and fixed (see **Confirmed bugs** below). Other
content-heavy widgets (chord-card grid, notation/tab, cinema viewer) still need
a run against a populated `sbn.db`._

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
- [~] **Chord Library index + show** (`Pages/Library/Chords/{Index,Show}.vue`) —
      the 240px value is the **filter sidebar**, reset to `width:100%` + static
      at ≤1024px; the card grid uses `minmax(120–150px, 1fr)` (safe). Index
      *chrome* clean at 360/768. **Card grid + Show page not exercised** (no
      seeded chord diagrams).
- [~] **Song viewer / cinema** (`Components/Leadsheet/LeadsheetViewer.vue`,
      `song-library.css`) — 340px viewer sidebar stacks (`width:100%`) at
      ≤1024px; wide notation scrolls in-container (`SheetMiniPlayer` has
      `overflow-x:auto; min-width:0`). Index chrome clean. **Actual notation/tab
      width + Cinema mode not exercised** (no seeded leadsheets).
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
