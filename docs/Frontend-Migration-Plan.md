# Frontend Migration Plan

Migration of the SBN public frontend from the legacy WP theme + Blade-with-Vue-islands pattern to a unified, modern, single-coherent-app experience on top of the existing Laravel backend.

---

## 1. Tech Stack Decision

**Backend (unchanged):** Laravel + Eloquent + Blade (admin only) + existing queue/mail/auth.

**Frontend:** Laravel + **Inertia** + **Vue 3** + **TypeScript** + **Tailwind CSS**.

### Rationale

- **Keep Laravel:** course system, auth, payments, admin, and DB logic all stay where they already work. No rewrite of backend.
- **Inertia over Blade-islands:** gives SPA-like navigation, persistent layouts (audio player, nav, auth state), and one mental model per page — no Blade/Vue split inside a page. Critical for the course player and interactive libraries.
- **Vue 3 over React:** we already have substantial Vue investment (TabEditor, ChordMeasure, rhythm components). Vue 3 Composition API + `<script setup>` is modern and well-supported. Concepts port to React later if ever needed.
- **TypeScript from day 1:** types guide both human and AI authors; fewer runtime surprises; better editor tooling. Setup cost paid once, benefits permanent.
- **Tailwind:** won the styling war; integrates cleanly with Vue + Inertia starter kits.
- **Blade stays for admin:** admin already works, no user-facing need for SPA feel. Don't fix what isn't broken.

### What we are NOT doing

- Not migrating to Next.js / React / separate SPA + API. Would double deployment surface, require rebuilding auth/sessions across the boundary, and throw away working Vue components.
- Not converting admin to Inertia. Admin is Blade-rendered and will stay that way.

---

## 2. Phase Order

Each phase depends on the ones before it. Execute sequentially.

| # | Phase | Depends on |
|---|---|---|
| 0 | **[DONE]** Stack scaffolding (Inertia + TS + Tailwind installed, base layout, shared props, one "hello world" Inertia page working alongside existing Blade) | — |
| 1 | **[DONE]** App shell: mega menu, footer, layout slots, persistent audio player mount point, auth state in shared props | 0 |
| 2 | Shop (catalog + product pages, stubbed checkout buttons, no real payment) | 1 |
| 3 | Chord library + frontend chord diagram component (Vue + TS) | 1 |
| 4 | Rhythm library + frontend rhythm pattern component (Vue + TS) | 1 |
| 5 | Top10 pages (composition over components from 3 and 4) | 3, 4 |
| 6 | Chord progression library | 3 |
| 7 | Leadsheet viewer — classic view (old layout + new context/edu panel) + song library | 3, 4, 6 |
| 8 | Leadsheet viewer — cinema view (video-focused layout, toggle from classic view, full reload acceptable) | 7 |
| 9 | Course player | 1, 7 |
| 10 | Auth + payments (unstub shop, wire real checkout, gate courses) | 2, 9 |

### Why this order

- Shell first so every later phase has a real frame to drop into and layout/routing issues surface cheap.
- Shop as a low-stakes warmup to the new stack (full layout, stubbed payment, real product data).
- Chord → rhythm → top10 builds the component library bottom-up; top10 is pure composition once its dependencies are done.
- Leadsheet is the heaviest single phase and must come after all its component dependencies exist.
- Auth + payments last so we don't block feature development on Stripe plumbing; stubs earlier let us build UI without real money flow.

---

## 2.5 Existing Vue Assets — What's Already Built

Much of the frontend work is already done in the admin side (tab editor + chord tools). Most frontend phases are **composition over existing components**, not from-scratch Vue work. Know what exists before writing anything new.

### Reusable components (currently under `resources/js/tab-editor/components/`)

| Component | Purpose | Reused in phase |
|---|---|---|
| `ChordCard.vue` | Single chord display (name + diagram) | 3, 5, 6, 7 |
| `ChordMeasure.vue` | Chord grid measure | 7 (leadsheet) |
| `ChordGridView.vue` | Chord grid layout | 7 |
| `ChordSection.vue` | Chord section grouping | 7 |
| `ChordPicker.vue` | Chord selection UI (admin) | Admin-only for now |
| `VoicingPicker.vue` / `VoicingOverview.vue` | Voicing selection + display | 3 (detail page) |
| `TabMeasure.vue` / `TabCursor.vue` | Tab rendering + cursor | 7 |
| `TransportBar.vue` | Playback controls | 7, 8, 9 |
| `VideoPlayer.vue` / `VideoSyncEditor.vue` / `SyncPointBadge.vue` | Video sync | 8 |
| `ChordContextMenu.vue` | Right-click menu | 7 (if needed) |

### Reusable composables (`resources/js/tab-editor/composables/`)

`useAudioEngine`, `useChordAudio`, `useVideoSync`, `useTabModel`, `useReflow`, `useChordGridOps`, `useCursor`, `useSelection`, `useMeasureSelection`, `useUndo`, `useChordClipboard`.

### Reusable audio engine (`resources/js/audio/`)

- Engine: `AudioEngine.js`, `Scheduler.js`, `PlaybackClock.js`, `MediaElementClock.js`, `ToneClock.js`
- Adapters: `chordDiagramToEvents`, `chordVoicingsToEvents`, `chordProgressionToEvents`, `rhythmPatternToEvents`, `tabMeasureToEvents`, `pitchToMidi`

### Shared JS utilities (`public/js/`)

`chords.js` (diagram SVG renderer — used by admin and Vue components), `sbn-chord-name.js`, `sbn-context-menu.js`, `sbn-grid-ops.js`. These are loaded globally and available to both admin Blade and Inertia pages.

### Genuine gaps — the real new component work

| Gap | Where it bites | Notes |
|---|---|---|
| **Rhythm pattern renderer** | Phase 4 | Audio adapter (`rhythmPatternToEvents`) exists; visual Vue component does NOT. Biggest single new-component effort. |
| **ChordProgressionBlock (consumer view)** | Phase 6 | Admin has a progression *builder*; the public playback/display component is new. |
| **EduPanel** | Phase 7 | New teaching-content sidebar, replaces old context panel. |
| **Public library index pages** | 3, 4, 6, 7 | Browse/search/filter pages are all new (admin indexes are Blade). |
| **Cinema view layout** | Phase 8 | Reuses VideoPlayer etc.; only the layout is new. |
| **Course player shell** | Phase 9 | Lesson nav, progress tracking — new, but embeds existing leadsheet. |

### Component sharing policy

- Components currently live under `resources/js/tab-editor/components/`. Many will be reused outside the tab editor.
- **Do not bulk-move them now.** Move lazily: when a phase needs a component outside the tab editor, that phase moves it to `resources/js/components/` (or `resources/js/Components/` for Inertia convention) and updates admin imports in the same commit.
- No component gets duplicated. If the admin and the frontend both need it, there is ONE file imported by both.

### TypeScript migration policy

- Existing components are `.vue` with `<script>` in JS. **Do not bulk-convert.**
- Rule: when a phase touches a component, that phase converts it to TS (`<script setup lang="ts">`) and defines exportable prop types.
- New components from day 1 are TS.
- By end of Phase 7, most reused components will be TS. By end of Phase 9, all of them.

---

## 3. Per-Phase Spec

Each phase is written as a self-contained brief sized for one AI handoff. Each has: **Deliverable**, **Component inventory**, **Key files**, **Done criteria**.

Component inventory entries are tagged:
- **[REUSE]** — exists under `resources/js/tab-editor/components/` (or `composables/`, or `audio/`). Import; do not rewrite. Move to shared location if now used outside tab editor.
- **[CONVERT]** — existing component that this phase touches; convert to TS and define exportable prop types as part of the work.
- **[NEW]** — genuinely new component for this phase.

### Phase 0 — Stack Scaffolding

**Deliverable:** Inertia + Vue 3 + TS + Tailwind installed and working in the Laravel app. One sample Inertia page (`/hello`) renders without breaking existing Blade routes.

**Key files:**
- `resources/js/app.ts` (Inertia entry)
- `resources/js/Pages/Hello.vue` (sample)
- `resources/views/app.blade.php` (Inertia root template)
- `vite.config.ts`, `tsconfig.json`
- `tailwind.config.ts`
- `app/Http/Middleware/HandleInertiaRequests.php` (shared props)

**Done:**
- `npm run dev` and `npm run build` both succeed.
- Existing Blade routes still work untouched.
- TS strict mode on.
- Shared props include `auth.user` (null if guest).

---

### Phase 1 — App Shell

**Deliverable:** Persistent layout with mega menu, footer, and slot for page content. Audio player mount point that survives navigation. Auth state visible in menu.

**Component inventory:**
- **[NEW]** `Layouts/PublicLayout.vue` — wraps all public pages
- **[NEW]** `Components/MegaMenu.vue`
- **[NEW]** `Components/Footer.vue`
- **[NEW]** `Components/AudioPlayerSlot.vue` — mount point; wires to existing `AudioEngine` in Phase 7
- **[NEW]** `Components/UserMenu.vue` (login/account/logout)

**Key files:**
- `resources/js/Layouts/PublicLayout.vue`
- `resources/js/Components/MegaMenu.vue`
- Shared layout wiring in `app.ts` (persistent layout pattern)

**Legacy references** (port, don't redesign):
- `sbn-course-player(legacywp)/flavor-starter/flavor-starter/header.php` — mega menu markup
- `sbn-course-player(legacywp)/flavor-starter/flavor-starter/footer.php` — footer markup
- `sbn-course-player(legacywp)/flavor-starter/flavor-starter/css/mega-menu.css` — mega menu styles
- `sbn-course-player(legacywp)/flavor-starter/flavor-starter/css/header.css` — header / top bar
- `sbn-course-player(legacywp)/flavor-starter/flavor-starter/css/base.css` — typography, color tokens (extract into Tailwind theme)
- `sbn-course-player(legacywp)/flavor-starter/flavor-starter/js/mega-menu.js` — open/close/hover behavior
- `sbn-course-player(legacywp)/flavor-starter/flavor-starter/js/header-scroll.js` — sticky/scroll interactions
- `sbn-course-player(legacywp)/flavor-starter/flavor-starter/images/` — logo & icons
- `sbn-course-player(legacywp)/flavor-starter/flavor-starter/style.css` — theme root styles

**Done:**
- Navigating between two sample Inertia pages does NOT remount the layout (verify via Vue devtools / console log in mounted hook).
- Mega menu matches legacy WP theme structure (links, dropdowns, hover states).
- Logged-in vs guest state visible in menu without reload.
- Responsive down to mobile.

---

### Phase 2 — Shop

**Deliverable:** Full shop layout: catalog grid, product detail pages, cart sidebar, checkout page with stubbed "Pay" button (logs intent, shows success screen, does not charge).

**Component inventory:**
- **[NEW]** `Pages/Shop/Index.vue` (catalog)
- **[NEW]** `Pages/Shop/Show.vue` (product detail)
- **[NEW]** `Pages/Shop/Cart.vue`
- **[NEW]** `Pages/Shop/Checkout.vue`
- **[NEW]** `Components/Shop/ProductCard.vue`
- **[NEW]** `Components/Shop/CartDrawer.vue`
- **[NEW]** Pinia (or simple composable) store for cart state

**Backend:**
- Laravel controllers returning Inertia responses with product data from existing tables.
- No Stripe integration yet; checkout POSTs to a stub endpoint that just creates an "order" record with status `pending_stub`.

**Legacy references:**
- `sbn-course-player(legacywp)/flavor-starter/flavor-starter/woocommerce/archive-product.php` — catalog
- `sbn-course-player(legacywp)/flavor-starter/flavor-starter/woocommerce/single-product.php` — product detail
- `sbn-course-player(legacywp)/flavor-starter/flavor-starter/woocommerce/cart/` — cart markup
- `sbn-course-player(legacywp)/flavor-starter/flavor-starter/woocommerce/checkout/` — checkout markup
- `sbn-course-player(legacywp)/flavor-starter/flavor-starter/css/shop.css` — shop styles

**Done:**
- All shop pages render with real product data.
- Cart persists across navigation (shared state).
- Checkout flow completes end-to-end with stubbed payment.
- Admin order list shows stubbed orders (sanity check that data actually reached DB).

---

### Phase 3 — Chord Library + Chord Diagram Component

**Deliverable:** Chord library browse/search page + reusable `<ChordDiagram>` Vue component used here and in later phases.

**Component inventory:**
- **[NEW]** `Pages/Library/Chords/Index.vue` — public browse/search page
- **[NEW]** `Pages/Library/Chords/Show.vue` — single chord with voicings, diagrams, audio preview
- **[REUSE/CONVERT]** `ChordCard.vue` — move to `resources/js/Components/`, convert to TS, define exportable prop types. This IS the chord diagram component; do not create a new `ChordDiagram.vue`.
- **[REUSE/CONVERT]** `VoicingOverview.vue`, `VoicingPicker.vue` (picker only if needed on detail page) — used for showing alternate voicings
- **[REUSE]** `public/js/chords.js` — underlying SVG renderer, already loaded globally
- **[REUSE]** `useChordAudio` composable + `chordVoicingsToEvents` adapter for audio preview
- **[NEW]** `Components/ChordAudioButton.vue` — thin wrapper around `useChordAudio` (optional; may just inline into ChordCard)

**Legacy references:**
- `sbn-course-player(legacywp)/inc/chord-diagrams/` — existing diagram renderer (source of truth for geometry)
- `sbn-course-player(legacywp)/inc/chord-detail-page.php` — chord detail page template
- `sbn-course-player(legacywp)/inc/chord-shapes/` — chord shape data model
- `sbn-course-player(legacywp)/inc/chord-search-handler.php` — search/filter logic to port to Laravel controller
- `sbn-course-player(legacywp)/flavor-starter/flavor-starter/page-chords-redesign.php` — library page template
- `sbn-course-player(legacywp)/assets/css/chord-library-redesign.css` — library styles
- `sbn-course-player(legacywp)/assets/css/chord-detail-page.css` — detail page styles
- `sbn-course-player(legacywp)/assets/css/chord-styling.css` — chord name typography
- `sbn-course-player(legacywp)/assets/css/sbn-chord-card.css` — chord card component styles
- `sbn-course-player(legacywp)/assets/js/chord-library-redesign.js` — library interactions
- `sbn-course-player(legacywp)/assets/js/sbn-chord-card.js` — chord card behavior

**Done:**
- `<ChordDiagram>` has TS prop types exported for reuse.
- All existing chord voicings render correctly (spot-check against WP version).
- Search/filter works without page reload (Inertia partial reloads).
- Component is documented with at least one example usage in a Storybook-lite file OR as comments in the component.

---

### Phase 4 — Rhythm Library + Rhythm Pattern Component

**Deliverable:** Rhythm library browse page + reusable `<RhythmPattern>` component.

**Component inventory:**
- **[NEW]** `Pages/Library/Rhythms/Index.vue`
- **[NEW]** `Pages/Library/Rhythms/Show.vue`
- **[NEW]** `Components/RhythmPattern.vue` — props: `pattern`, `tempo`, `playable`. **This is the single biggest new-component effort in the whole migration.** Audio adapter exists (`rhythmPatternToEvents`); visual renderer does not. Reference legacy `inc/rhythm-patterns/` + `assets/css/rhythm-library-frontend.css` + `assets/js/sbn-percussion.js` for intended visual output.
- **[REUSE]** `rhythmPatternToEvents` adapter (`resources/js/audio/adapters/`) — feeds the audio engine
- **[REUSE]** `useAudioEngine` composable — transport integration

**Note:** budget real time for the renderer. Everything else in this phase (pages, data wiring) is routine; the renderer is not.

**Legacy references:**
- `sbn-course-player(legacywp)/inc/rhythm-patterns/` — pattern renderer + data model
- `sbn-course-player(legacywp)/inc/rhythm-grid.php` — grid layout logic
- `sbn-course-player(legacywp)/flavor-starter/flavor-starter/page-rhythms.php` — library page template
- `sbn-course-player(legacywp)/assets/css/rhythm-library-frontend.css` — library styles
- `sbn-course-player(legacywp)/assets/js/sbn-percussion.js` — percussion playback
- `sbn-course-player(legacywp)/assets/js/sbn-audio.js` — audio engine glue (verify against current Laravel audio adapters)
- `sbn-course-player(legacywp)/assets/js/soundfonts/` — soundfont assets

**Done:**
- `<RhythmPattern>` plays back in sync with transport.
- Pattern notation renders visually (matches current rhythm component output).
- TS types exported for reuse in top10 and leadsheet.

---

### Phase 5 — Top10 Pages

**Deliverable:** Top10 landing pages (bossa nova songs, chord progressions, etc.) as pure composition of existing components + song metadata.

**Component inventory:**
- **[NEW]** `Pages/Top10/[Slug].vue` (dynamic, one template per list type)
- **[REUSE]** `ChordCard` (from Phase 3), `RhythmPattern` (from Phase 4). No new components beyond the page itself — this phase is pure composition.

**Reference:** existing React implementation in WP. Read it for UX, rebuild in Vue; do not mechanically translate.

**Legacy references:**
- Existing React Top10 bundle (locate in WP plugin/theme — not in `sbn-course-player(legacywp)` directory; likely lives in main WP install). **TODO:** user to point to exact path before phase starts.
- Any Top10 page templates in the legacy theme (none found under `flavor-starter/` — confirm location).

**Done:**
- At least one Top10 page live and indexable.
- SEO meta (title, description, og tags) handled via Inertia head manager.
- Parity with WP React version for interactive behavior.

---

### Phase 6 — Chord Progression Library

**Deliverable:** Browse + detail pages for chord progressions, reusing chord diagram component.

**Component inventory:**
- **[NEW]** `Pages/Library/Progressions/Index.vue`
- **[NEW]** `Pages/Library/Progressions/Show.vue`
- **[NEW]** `Components/ChordProgressionBlock.vue` — consumer-facing playback component (sequence of ChordCards with timing). Admin progression *builder* exists separately; this is the public view.
- **[REUSE]** `ChordCard` (from Phase 3)
- **[REUSE]** `chordProgressionToEvents` adapter + `useAudioEngine` for playback

**Legacy references:**
- `sbn-course-player(legacywp)/inc/chord-progressions/` — progression data model + renderer
- `sbn-course-player(legacywp)/inc/progression-library.php` — library page template
- `sbn-course-player(legacywp)/inc/progression-builder.php` — builder UI (admin-side reference only; builder itself stays in Laravel admin)
- `sbn-course-player(legacywp)/assets/css/progression-library.css` — library styles
- `sbn-course-player(legacywp)/assets/css/progression-builder.css` — builder styles
- `sbn-course-player(legacywp)/assets/js/progression-library.js` — library interactions
- `sbn-course-player(legacywp)/inc/voicing-crossref/` — voicing cross-reference (if used on progression pages)

**Done:**
- Progressions playable with audio.
- Filtering by key / style / difficulty works.

---

### Phase 7 — Leadsheet Viewer (Classic View) + Song Library

**Deliverable:** Song library browse page + leadsheet classic view (old layout, new context/edu panel in sidebar).

**Component inventory:**
- **[NEW]** `Pages/Library/Songs/Index.vue` — song library browse page
- **[NEW]** `Pages/Leadsheet/Classic.vue` — public-facing wrapper page
- **[REUSE/CONVERT]** `ChordMeasure.vue`, `ChordGridView.vue`, `ChordSection.vue` — chord grid rendering
- **[REUSE/CONVERT]** `TabMeasure.vue`, `TabCursor.vue` — tab rendering (if song has tab)
- **[REUSE/CONVERT]** `TransportBar.vue` — playback controls
- **[REUSE]** `useTabModel`, `useReflow`, `useChordGridOps`, `useCursor`, `useSelection` composables
- **[REUSE]** full `AudioEngine` stack
- **[NEW]** `Components/Leadsheet/EduPanel.vue` — teaching-content sidebar; replaces old context panel
- **[NEW]** View toggle UI (placeholder link to cinema view, disabled until Phase 8)

**Note:** the heavy Vue work already exists from the tab editor. This phase is mostly wrapping those components in a public-facing page + building the new `EduPanel`.

**Legacy references:**
- `sbn-course-player(legacywp)/inc/leadsheet/` — leadsheet renderer + core logic
- `sbn-course-player(legacywp)/inc/song-page.php` — song page template
- `sbn-course-player(legacywp)/inc/song-library-handler.php` — song library data handling
- `sbn-course-player(legacywp)/inc/sheet-players.php` — embedded player logic
- `sbn-course-player(legacywp)/inc/chord-grid.php` — chord grid (context panel source)
- `sbn-course-player(legacywp)/inc/alphatex-shortcode.php` — alphaTex notation (if reused)
- `sbn-course-player(legacywp)/flavor-starter/flavor-starter/page-song-library.php` — song library page template
- `sbn-course-player(legacywp)/flavor-starter/flavor-starter/page-repertoire.php` — repertoire page template
- `sbn-course-player(legacywp)/flavor-starter/flavor-starter/repertoire-quiz.php` — repertoire quiz (defer to later phase if not essential)
- `sbn-course-player(legacywp)/assets/css/leadsheet.css` — leadsheet styles
- `sbn-course-player(legacywp)/assets/css/song-library.css` — library styles
- `sbn-course-player(legacywp)/assets/css/song-page.css` — song page styles
- `sbn-course-player(legacywp)/assets/css/sheet-player.css` — player styles
- `sbn-course-player(legacywp)/assets/js/leadsheet.js` — leadsheet interactions
- `sbn-course-player(legacywp)/assets/js/sheet-player.js` — player interactions
- `sbn-course-player(legacywp)/assets/js/song-library.js` — library interactions

**Done:**
- Existing songs render correctly.
- Edu panel surfaces contextual teaching content (chord theory, rhythm notes) tied to current selection.
- Audio playback works.
- View toggle placeholder present (disabled until Phase 8).

---

### Phase 8 — Leadsheet Cinema View

**Deliverable:** Alternate leadsheet layout optimized for video-sync learning. Full reload on toggle between classic and cinema is acceptable.

**Spec:** **[PLACEHOLDER — fetch from Claude design app before starting this phase]**. Export the design and reference it here before handoff.

**Component inventory (to be finalized from design):**
- **[NEW]** `Pages/Leadsheet/Cinema.vue` — alternate layout
- **[REUSE/CONVERT]** `VideoPlayer.vue`, `VideoSyncEditor.vue` (viewer mode only), `SyncPointBadge.vue`
- **[REUSE]** `useVideoSync` composable
- **[NEW]** Any additional layout components required by the exported design (e.g. `SyncedChordStrip`)

**Legacy references:**
- No direct WP equivalent — cinema view is a new design. Reuse Phase 7 leadsheet components; video sync machinery comes from the Laravel Phase D work, not from the legacy theme.

**Done:**
- Cinema view renders with video primary, chord/melody secondary.
- Toggle from classic view works (reload accepted).
- Video sync carries over from Phase D authoring work.

---

### Phase 9 — Course Player

**Deliverable:** Course viewer with lesson navigation, video lessons, embedded leadsheets, progress tracking.

**Component inventory:**
- **[NEW]** `Pages/Courses/Index.vue` (catalog)
- **[NEW]** `Pages/Courses/Show.vue` (course overview)
- **[NEW]** `Pages/Courses/Lesson.vue` (single lesson player)
- **[NEW]** `Components/Course/LessonSidebar.vue`
- **[NEW]** `Components/Course/ProgressBar.vue`
- **[REUSE]** leadsheet classic + cinema views (from 7, 8)
- **[REUSE]** full audio/video machinery
- **[CONVERT]** by end of this phase, all remaining JS components should be TS

**Legacy references:**
- `sbn-course-player(legacywp)/sbn-course-player.php` — plugin entry point (wiring reference)
- `sbn-course-player(legacywp)/inc/course-player.php` — course player template + logic
- `sbn-course-player(legacywp)/inc/course-archive.php` — course catalog
- `sbn-course-player(legacywp)/flavor-starter/flavor-starter/inc/course-archive.php` — theme-side catalog template
- `sbn-course-player(legacywp)/assets/css/course-player.css` — player styles
- `sbn-course-player(legacywp)/assets/js/course-player.js` — player interactions
- `sbn-course-player(legacywp)/inc/admin-and-helpers.php` — helper functions (review for anything frontend needs)

**Done:**
- Full course flow: catalog → course → lesson → next lesson.
- Progress persists per user.
- Embedded leadsheets render inside lessons.
- Auth-gated (redirects guests to signup) — stubbed gate acceptable if Phase 10 not done yet.

---

### Phase 10 — Auth + Payments

**Deliverable:** Real auth flows (login, signup, password reset), Stripe wired to shop and course purchases, course access gated by ownership.

**Scope:**
- Replace Phase 2 shop checkout stub with real Stripe.
- Wire course purchase flow.
- Auth pages (Inertia versions of Laravel's auth scaffolding).
- Membership / subscription handling if applicable.

**Legacy references:**
- `sbn-course-player(legacywp)/flavor-starter/flavor-starter/functions.php` — theme hooks (auth / redirects to review)
- WP user DB — out of scope of this repo; migration handled separately, referenced here only as the source for user re-onboarding.

**Done:**
- Real payment flow works end-to-end in Stripe test mode.
- Course access correctly gated.
- Existing users from WP migrated or re-onboarded (separate migration task).

---

## 4. Legacy Asset Reuse Policy

### Design system is authoritative — read this first

[`docs/SBN-Design-Reference.md`](SBN-Design-Reference.md) + `public/css/sbn-design-system.css` are the **single source of truth** for tokens, fonts, colors, and existing component styles. They already distill the legacy WP CSS into a clean system that's used by both admin and (will be used by) frontend.

**Hard rules:**
- Before writing any CSS, read `SBN-Design-Reference.md`. If a component/token exists, use it — don't rewrite it.
- Brand colors come from CSS variables (`var(--clr-accent)`, `var(--clr-bg)`, etc.) — **not** from Tailwind color classes. Tailwind is for layout/spacing/utility; brand tokens stay in CSS vars.
- Chord name typography is governed by `chord-symbols.css` + `sbn-chord-name.js`. Do not redefine chord name styles in Vue components.
- The frontend Inertia root layout MUST load `sbn-design-system.css`, `chord-symbols.css`, and `chords.js` — same load order as admin (see Design Reference §LOAD ORDER).
- Legacy WP CSS (`sbn-course-player(legacywp)/assets/css/*.css`) is a **reference for design intent, not a copy-paste source**. Where the design system already covers something, use the design system. Where a legacy file has layout/structure not yet in the design system, port selectively and extend `sbn-design-system.css` rather than creating parallel stylesheets.

### Legacy WP code as reference

The legacy WP code in `sbn-course-player(legacywp)/` is a **first-class reference** for every phase, not just historical context. Design work, markup, CSS, and interaction behavior that already exist should be ported, not reinvented.

### Default rule: port, don't redesign

- **CSS/SCSS** → extract color tokens, typography, spacing into `tailwind.config.ts` theme; port layout-specific styles into component-scoped Vue styles where Tailwind isn't expressive enough. Match the visual result; don't "improve" mid-migration.
- **HTML/PHP templates** → copy the DOM structure into Vue components, swap PHP for Vue bindings. Preserve class names where it helps eyeball diffs.
- **JS behavior** → read for intent, rewrite in Vue (Composition API). Don't paste jQuery into Vue.
- **Assets** (images, SVGs, fonts, soundfonts) → copy to `public/` or `resources/`. Same files, new pipeline.
- **Copy text** → reuse verbatim unless there's a reason to change it.
- **Data model / PHP logic** → backend responsibility; Laravel already owns this. Frontend phases don't touch it.

### Layout of the legacy source

- `sbn-course-player(legacywp)/flavor-starter/flavor-starter/` — **WP theme**. Header, footer, mega menu, shop (WooCommerce overrides), page templates, theme CSS/JS.
- `sbn-course-player(legacywp)/inc/` — **WP plugin internals**. Chord/rhythm/progression/leadsheet/course-player features.
- `sbn-course-player(legacywp)/assets/` — **feature-specific CSS/JS** used by the plugin.
- `sbn-course-player(legacywp)/sbn-course-player.php` — plugin entry point.

Each phase spec above lists the exact legacy files to read before starting. If a reference is missing or wrong, update the plan before coding.

### Before each phase

1. Read the listed legacy files end-to-end before writing a single new line.
2. Keep the legacy file open alongside the new Vue component during implementation.
3. When done, spot-check visually against the legacy version (same page, side by side).
4. If you diverge from legacy design intentionally, write a one-line note in the phase handoff explaining why — don't let design drift happen silently.

---

## 5. AI Handoff Conventions

Each phase is one or more AI sessions. For each handoff:

1. **Brief the agent with the phase section from this doc** + current repo state.
2. **Scope boundary:** the agent works only on the current phase. No "while I'm here" edits to earlier phases.
3. **Before starting a phase:** re-read the "Done" criteria of all prior phases and spot-check. If a prior phase has regressed, fix before advancing.
4. **After finishing a phase:** human review + sign-off against the Done criteria before the next phase starts.
5. **Component contracts:** when a phase produces a reusable component (ChordDiagram, RhythmPattern, ChordProgressionBlock), its TS prop types are the contract. Later phases consume via import, do not redefine.

---

## 6. Risk Register

| Risk | Mitigation |
|---|---|
| Inertia learning curve mid-project | Phase 0 is isolated scaffolding; Phase 2 (shop) is the teaching phase, low complexity, low stakes. |
| TypeScript friction with existing JS Vue components | Port components to TS as they land in their new phase. Don't bulk-migrate existing code; rewrite in-place during each phase. |
| Chord/rhythm components diverge between phases | Define TS prop types once (Phase 3, 4) and import everywhere. No local re-definitions. |
| Audio engine coupling across phases | Keep current audio adapters; expose via composables (`useChordAudio`, `useRhythmAudio`). Do not rewrite audio layer during frontend migration. |
| Cinema view spec missing until Phase 8 | Placeholder noted; do not start Phase 8 until design exported from Claude design app. |
| Admin accidentally migrated | Scope discipline: Inertia pages live only under `resources/js/Pages/`. Admin routes continue to return Blade views. |
| SEO regression on Top10 (high-traffic pages) | Inertia head manager for meta tags; validate with crawler before DNS cutover. Consider SSR if needed (Phase 5 decision). |
| Stripe/auth rework at end blocking launch | Stubs earlier let us ship internally without real payments. Phase 10 is strictly about wiring, not UX design. |
| Silent design drift from legacy | Section 4 policy: read listed legacy files before each phase, spot-check visually at end, document intentional deviations. |
| Missing legacy references (e.g. Top10 React source) | Flagged per-phase as **TODO**; user to point to exact paths before that phase starts. |
| Rhythm pattern renderer is the real unknown | Phase 4 budgets time for it explicitly; it is the biggest single new-component effort. Reference legacy `inc/rhythm-patterns/` + `sbn-percussion.js` for visual intent. |
| Component duplication between admin and frontend | Enforce single-source rule: when a component moves out of `tab-editor/`, admin imports update in the same commit. No copies. |
| Tailwind tokens diverging from `sbn-design-system.css` | Tailwind config does NOT redefine brand colors; brand values stay in CSS vars. Tailwind is utility-only for brand. |

---

## 7. Definition of Done (whole migration)

- All public-facing pages served by Inertia + Vue + TS.
- Admin still on Blade, untouched.
- No Blade-island Vue components remain in public frontend (legacy patterns removed, not left alongside).
- Shared component library (chord, rhythm, progression, leadsheet blocks) is typed, documented, and used everywhere without duplication.
- Course player works end-to-end with real auth and real payments.
- WP theme fully retired; DNS and redirects point to Laravel.

---

## 8. Open Decisions

- **State management:** Pinia vs. simple composables. Recommend: start with composables, add Pinia only if/when cart or course progress state outgrows them.
- **Form library:** Inertia's `useForm` for everything, or add VeeValidate/Zod for complex validation? Recommend: `useForm` only until a real pain point appears.
- **Testing:** what level of automated testing during migration? Recommend: component tests for ChordDiagram, RhythmPattern (reused everywhere); E2E later.
- **SSR:** defer decision to Phase 5 (Top10) based on SEO needs.
