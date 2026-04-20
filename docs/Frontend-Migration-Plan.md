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
| 0 | Stack scaffolding (Inertia + TS + Tailwind installed, base layout, shared props, one "hello world" Inertia page working alongside existing Blade) | — |
| 1 | App shell: mega menu, footer, layout slots, persistent audio player mount point, auth state in shared props | 0 |
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

## 3. Per-Phase Spec

Each phase is written as a self-contained brief sized for one AI handoff. Each has: **Deliverable**, **Component inventory**, **Key files**, **Done criteria**.

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
- `Layouts/PublicLayout.vue` — wraps all public pages
- `Components/MegaMenu.vue`
- `Components/Footer.vue`
- `Components/AudioPlayerSlot.vue` (mount point, actual player connects later)
- `Components/UserMenu.vue` (login/account/logout)

**Key files:**
- `resources/js/Layouts/PublicLayout.vue`
- `resources/js/Components/MegaMenu.vue`
- Shared layout wiring in `app.ts` (persistent layout pattern)

**Done:**
- Navigating between two sample Inertia pages does NOT remount the layout (verify via Vue devtools / console log in mounted hook).
- Mega menu matches legacy WP theme structure (links, dropdowns, hover states).
- Logged-in vs guest state visible in menu without reload.
- Responsive down to mobile.

---

### Phase 2 — Shop

**Deliverable:** Full shop layout: catalog grid, product detail pages, cart sidebar, checkout page with stubbed "Pay" button (logs intent, shows success screen, does not charge).

**Component inventory:**
- `Pages/Shop/Index.vue` (catalog)
- `Pages/Shop/Show.vue` (product detail)
- `Pages/Shop/Cart.vue`
- `Pages/Shop/Checkout.vue`
- `Components/Shop/ProductCard.vue`
- `Components/Shop/CartDrawer.vue`
- Pinia (or simple composable) store for cart state

**Backend:**
- Laravel controllers returning Inertia responses with product data from existing tables.
- No Stripe integration yet; checkout POSTs to a stub endpoint that just creates an "order" record with status `pending_stub`.

**Done:**
- All shop pages render with real product data.
- Cart persists across navigation (shared state).
- Checkout flow completes end-to-end with stubbed payment.
- Admin order list shows stubbed orders (sanity check that data actually reached DB).

---

### Phase 3 — Chord Library + Chord Diagram Component

**Deliverable:** Chord library browse/search page + reusable `<ChordDiagram>` Vue component used here and in later phases.

**Component inventory:**
- `Pages/Library/Chords/Index.vue`
- `Pages/Library/Chords/Show.vue` (single chord with voicings, diagrams, audio preview)
- `Components/ChordDiagram.vue` — **critical reusable component**, props: `frets`, `fingers`, `barres`, `orientation`, `size`
- `Components/ChordAudioButton.vue` (plays voicing)

**Done:**
- `<ChordDiagram>` has TS prop types exported for reuse.
- All existing chord voicings render correctly (spot-check against WP version).
- Search/filter works without page reload (Inertia partial reloads).
- Component is documented with at least one example usage in a Storybook-lite file OR as comments in the component.

---

### Phase 4 — Rhythm Library + Rhythm Pattern Component

**Deliverable:** Rhythm library browse page + reusable `<RhythmPattern>` component.

**Component inventory:**
- `Pages/Library/Rhythms/Index.vue`
- `Pages/Library/Rhythms/Show.vue`
- `Components/RhythmPattern.vue` — props: `pattern`, `tempo`, `playable`
- Reuse of existing audio engine (keep current adapters)

**Done:**
- `<RhythmPattern>` plays back in sync with transport.
- Pattern notation renders visually (matches current rhythm component output).
- TS types exported for reuse in top10 and leadsheet.

---

### Phase 5 — Top10 Pages

**Deliverable:** Top10 landing pages (bossa nova songs, chord progressions, etc.) as pure composition of existing components + song metadata.

**Component inventory:**
- `Pages/Top10/[Slug].vue` (dynamic, one template per list type)
- Reuses `<ChordDiagram>`, `<RhythmPattern>`, `<ChordProgressionBlock>` (last one may arrive in Phase 6 — list ordering is fine since Top10 uses only chord+rhythm)

**Reference:** existing React implementation in WP. Read it for UX, rebuild in Vue; do not mechanically translate.

**Done:**
- At least one Top10 page live and indexable.
- SEO meta (title, description, og tags) handled via Inertia head manager.
- Parity with WP React version for interactive behavior.

---

### Phase 6 — Chord Progression Library

**Deliverable:** Browse + detail pages for chord progressions, reusing chord diagram component.

**Component inventory:**
- `Pages/Library/Progressions/Index.vue`
- `Pages/Library/Progressions/Show.vue`
- `Components/ChordProgressionBlock.vue` (sequence of ChordDiagrams with timing)

**Done:**
- Progressions playable with audio.
- Filtering by key / style / difficulty works.

---

### Phase 7 — Leadsheet Viewer (Classic View) + Song Library

**Deliverable:** Song library browse page + leadsheet classic view (old layout, new context/edu panel in sidebar).

**Component inventory:**
- `Pages/Library/Songs/Index.vue`
- `Pages/Leadsheet/Classic.vue`
- `Components/Leadsheet/ChordChart.vue`
- `Components/Leadsheet/MelodyStaff.vue`
- `Components/Leadsheet/EduPanel.vue` (new — replaces old context panel with teaching content)
- Reuses chord diagram, chord progression, rhythm components

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
- `Pages/Leadsheet/Cinema.vue`
- `Components/Leadsheet/VideoPlayerPrimary.vue`
- `Components/Leadsheet/SyncedChordStrip.vue` (or equivalent — depends on design)
- Reuses `useVideoSync` composable from Phase D work

**Done:**
- Cinema view renders with video primary, chord/melody secondary.
- Toggle from classic view works (reload accepted).
- Video sync carries over from Phase D authoring work.

---

### Phase 9 — Course Player

**Deliverable:** Course viewer with lesson navigation, video lessons, embedded leadsheets, progress tracking.

**Component inventory:**
- `Pages/Courses/Index.vue` (catalog)
- `Pages/Courses/Show.vue` (course overview)
- `Pages/Courses/Lesson.vue` (single lesson player)
- `Components/Course/LessonSidebar.vue`
- `Components/Course/ProgressBar.vue`
- Reuses leadsheet components (classic + cinema) and audio/video machinery

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

**Done:**
- Real payment flow works end-to-end in Stripe test mode.
- Course access correctly gated.
- Existing users from WP migrated or re-onboarded (separate migration task).

---

## 4. AI Handoff Conventions

Each phase is one or more AI sessions. For each handoff:

1. **Brief the agent with the phase section from this doc** + current repo state.
2. **Scope boundary:** the agent works only on the current phase. No "while I'm here" edits to earlier phases.
3. **Before starting a phase:** re-read the "Done" criteria of all prior phases and spot-check. If a prior phase has regressed, fix before advancing.
4. **After finishing a phase:** human review + sign-off against the Done criteria before the next phase starts.
5. **Component contracts:** when a phase produces a reusable component (ChordDiagram, RhythmPattern, ChordProgressionBlock), its TS prop types are the contract. Later phases consume via import, do not redefine.

---

## 5. Risk Register

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

---

## 6. Definition of Done (whole migration)

- All public-facing pages served by Inertia + Vue + TS.
- Admin still on Blade, untouched.
- No Blade-island Vue components remain in public frontend (legacy patterns removed, not left alongside).
- Shared component library (chord, rhythm, progression, leadsheet blocks) is typed, documented, and used everywhere without duplication.
- Course player works end-to-end with real auth and real payments.
- WP theme fully retired; DNS and redirects point to Laravel.

---

## 7. Open Decisions

- **State management:** Pinia vs. simple composables. Recommend: start with composables, add Pinia only if/when cart or course progress state outgrows them.
- **Form library:** Inertia's `useForm` for everything, or add VeeValidate/Zod for complex validation? Recommend: `useForm` only until a real pain point appears.
- **Testing:** what level of automated testing during migration? Recommend: component tests for ChordDiagram, RhythmPattern (reused everywhere); E2E later.
- **SSR:** defer decision to Phase 5 (Top10) based on SEO needs.
