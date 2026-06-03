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
| 2 | **[DONE]** Shop (catalog + product pages, stubbed checkout, download) | 1 |
| 3 | **[DONE]** Chord library + chord diagram component + transposition search (Show deferred to Phase 7) | 1 |
| 4 | **[DONE]** Rhythm library + RhythmPattern component + samples/demo audio + blend slider (Show detail area deferred to Phase 7) | 1 |
| 5 | **[DONE]** Chord progression library — Index + minimal Show (ChordProgressionBlock deferred to Phase 7) | 3 |
| 6 | **[DONE]** Songs library browse + minimal Show stub + `slug` on `sbn_leadsheets` | 3, 4 |
| 7 | **[DONE]** Wire detail pages together — `ChordProgressionViewer`, chord-card audio, all Show pages live, cross-refs wired, several engine + transposition fixes | 3, 4, 5, 6 |
| 8 | **[DONE]** Top10 pages (pure composition over 3/4/5/6 components) | 3, 4, 5, 6 |
| 9 | **[DONE]** Leadsheet viewer — classic view (old layout + new context/edu panel) | 3, 4, 5, 6 |
| 10 | **[NEXT]** Leadsheet viewer — cinema view (video-focused layout, toggle from classic view, full reload acceptable) | 9 |
| 11 | Course player | 1, 9 |
| 12 | Auth + payments (unstub shop, wire real checkout, gate courses) | 2, 11 |

### Why this order

- Shell first so every later phase has a real frame to drop into and layout/routing issues surface cheap.
- Shop as a low-stakes warmup to the new stack (full layout, stubbed payment, real product data).
- Chord → rhythm → progressions builds the atomic components bottom-up.
- Songs library (browse) before detail-wiring because the chord/rhythm/progression detail pages will link to the songs that use them.
- Dedicated wiring phase to avoid scattering "TODO: link to X once X exists" across earlier phases. All the small visual previews that one detail page needs from another (chord diagram preview on a progression card, progression preview on a chord card, etc.) land together.
- Top10 is pure composition once 3/4/5/6 are stable.
- Leadsheet (viewer) is the heaviest single phase. Held until all its component dependencies exist and the teaching-content surfaces have been shaken out.
- Auth + payments last so we don't block feature development on payment-provider plumbing; stubs earlier let us build UI without real money flow.

### Show-page scope policy (applies to phases 3, 4, 5, 6)

Detail pages are for **information and preview**, not authoring. On a detail page:

- **Chord progression Show** shows a read-only mini preview of each chord diagram in the progression. It does NOT embed the admin progression builder.
- **Chord Show** shows the diagram + sibling voicings + a mini preview of a few progressions that contain this chord. It does NOT embed the voicing picker.
- **Rhythm Show** shows the pattern + a few songs that use it. It does NOT embed the pattern editor.
- **Song Show** (Phase 9, "Leadsheet viewer") is the only place the full interactive authoring-adjacent UI lives.

The builder/authoring components stay in admin. Public detail pages import the **read-only render components** only.

---

## 2.5 Existing Vue Assets — What's Already Built

Much of the frontend work is already done in the admin side (tab editor + chord tools). Most frontend phases are **composition over existing components**, not from-scratch Vue work. Know what exists before writing anything new.

### Reusable components (currently under `resources/js/tab-editor/components/`)

| Component | Purpose | Reused in phase |
|---|---|---|
| `ChordCard.vue` | Single chord display (name + diagram) | 3, 5, 7, 8 |
| `ChordMeasure.vue` | Chord grid measure | 9 (leadsheet) |
| `ChordGridView.vue` | Chord grid layout | 9 |
| `ChordSection.vue` | Chord section grouping | 9 |
| `ChordPicker.vue` | Chord selection UI (admin) | Admin-only |
| `VoicingPicker.vue` / `VoicingOverview.vue` | Voicing selection + display | Admin-only |
| `TabMeasure.vue` / `TabCursor.vue` | Tab rendering + cursor | 9 |
| `TransportBar.vue` | Playback controls | 9, 10, 11 |
| `VideoPlayer.vue` / `VideoSyncEditor.vue` / `SyncPointBadge.vue` | Video sync | 10 |
| `ChordContextMenu.vue` | Right-click menu | 9 (if needed) |

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
| **ChordProgressionBlock (consumer view)** | Phase 5 | Admin has a progression *builder*; the public playback/display component is new. |
| **EduPanel** | Phase 9 | New teaching-content sidebar, replaces old context panel. |
| **Public library index pages** | 3, 4, 5, 6 | Browse/search/filter pages are all new (admin indexes are Blade). |
| **Cinema view layout** | Phase 10 | Reuses VideoPlayer etc.; only the layout is new. |
| **Course player shell** | Phase 11 | Lesson nav, progress tracking — new, but embeds existing leadsheet. |

### Component sharing policy

- Components currently live under `resources/js/tab-editor/components/`. Many will be reused outside the tab editor.
- **Do not bulk-move them now.** Move lazily: when a phase needs a component outside the tab editor, that phase moves it to `resources/js/components/` (or `resources/js/Components/` for Inertia convention) and updates admin imports in the same commit.
- No component gets duplicated. If the admin and the frontend both need it, there is ONE file imported by both.

### TypeScript migration policy

- Existing components are `.vue` with `<script>` in JS. **Do not bulk-convert.**
- Rule: when a phase touches a component, that phase converts it to TS (`<script setup lang="ts">`) and defines exportable prop types.
- New components from day 1 are TS.
- By end of Phase 9, most reused components will be TS. By end of Phase 11, all of them.

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

### Phase 2 — Shop ✅ DONE (April 21, 2026)

**Deliverable:** Full shop layout: catalog grid, product detail pages, cart sidebar, checkout page with stubbed "Pay" button (logs intent, shows success screen, does not charge).

#### What was built

**Database & Backend:**
- Migrations: `sbn_products`, `sbn_product_categories`, `sbn_product_tags`, pivots, `sbn_orders`, `sbn_order_items`, `sbn_download_grants`
- Models with relationships: `Product`, `ProductCategory`, `ProductTag`, `Order`, `OrderItem`, `DownloadGrant`
- WXR import command `sbn:import-wc-products` — idempotent, supports `--dry-run` and `--download-pdfs`
- Controllers: `ShopController`, `CartController`, `CheckoutController`, `OrderController`, `DownloadController`, `Admin\OrderController`
- Config: `config/shop.php` (base currency EUR, USD display rate, configurable via `SHOP_USD_RATE`)
- Routes: `/shop`, `/shop/category/{slug}`, `/shop/product/{slug}`, `/shop/checkout`, `/shop/order/{token}`, `/shop/download/{token}/{productId}`

**Frontend:**
- `Pages/Shop/Index.vue` — 4-column product grid + sidebar with `CategoryFilter`
- `Pages/Shop/Show.vue` — product detail: breadcrumb, gallery, meta table (pages/format/composer/performer/level), feature chips, related products 4-column grid
- `Pages/Shop/Cart.vue`, `Pages/Shop/Checkout.vue`, `Pages/Shop/OrderSuccess.vue`
- `Components/Shop/ProductCard.vue` — gradient card with style badge, difficulty stars, hover overlay
- `Components/Shop/CategoryFilter.vue` — sidebar with product counts and active state
- `Components/Shop/CartDrawer.vue`, `Components/Shop/CartLineItem.vue`, `Components/Shop/ProductPrice.vue`
- `composables/useCart.ts` — localStorage-persisted cart (plain composable, no Pinia)
- `composables/useCurrency.ts` — EUR/USD toggle via localStorage
- `composables/useCategoryColors.ts` — CSS custom-property-based style color system
- `types/shop.ts` — TypeScript types for all shop entities
- Admin Blade orders index (`/admin/orders`)

**Key architectural decisions:**
- Cart lives in localStorage via plain composable — no Pinia needed at this scale
- Currency: charge always in EUR; USD is display-only via fixed conversion rate
- Download grants are token-based (ULID), time-limited, work without auth
- Guest checkout only — no auth required; real payments (Lemon Squeezy) wired in Phase 12
- Stub checkout creates `Order` with status `pending_stub`

**Component inventory:**
- **[NEW]** `Pages/Shop/Index.vue` (catalog)
- **[NEW]** `Pages/Shop/Show.vue` (product detail)
- **[NEW]** `Pages/Shop/Cart.vue`
- **[NEW]** `Pages/Shop/Checkout.vue`
- **[NEW]** `Pages/Shop/OrderSuccess.vue`
- **[NEW]** `Components/Shop/ProductCard.vue`
- **[NEW]** `Components/Shop/CartDrawer.vue`
- **[NEW]** `Components/Shop/CartLineItem.vue`
- **[NEW]** `Components/Shop/CategoryFilter.vue`
- **[NEW]** `Components/Shop/ProductPrice.vue`
- **[NEW]** `composables/useCart.ts`, `useCurrency.ts`, `useCategoryColors.ts`
- **[NEW]** `types/shop.ts`

**Backend:**
- Laravel controllers returning Inertia responses with product data from existing tables.
- No payment-provider integration yet; checkout POSTs to a stub endpoint that just creates an "order" record with status `pending_stub`.

**Legacy references:**
- `sbn-course-player(legacywp)/flavor-starter/flavor-starter/woocommerce/archive-product.php` — catalog
- `sbn-course-player(legacywp)/flavor-starter/flavor-starter/woocommerce/single-product.php` — product detail
- `sbn-course-player(legacywp)/flavor-starter/flavor-starter/woocommerce/cart/` — cart markup
- `sbn-course-player(legacywp)/flavor-starter/flavor-starter/woocommerce/checkout/` — checkout markup
- `sbn-course-player(legacywp)/flavor-starter/flavor-starter/css/shop.css` — shop styles

**Known issues / deferred:**
- ⚠️ PDF downloads require `--download-pdfs` flag run separately after metadata import (slow; hits WP server)
- ⚠️ 75/80 products imported — 5 gap needs investigation on next import run
- ⚠️ Real payment integration (Lemon Squeezy) → Phase 12

---

### Phase 2.5 — CSS Polish + Design System Consolidation ✅ DONE (April 22, 2026)

Consolidation pass closing Phase 1/2 loose ends. No new features — pure styling, token unification, bug fixes.

#### What was done

**Design System:**
- Canonical 8-color music style palette in `sbn-design-system.css` under `/* Music style colors */`:
  - `--clr-style-bossa` (orange), `--clr-style-jazz` (blue), `--clr-style-samba` (green), `--clr-style-latin` (purple), `--clr-style-blues` (indigo), `--clr-style-pop` (pink), `--clr-style-classical` (slate), `--clr-style-gold` (gold/iconic)
  - `--clr-style-default: var(--clr-style-bossa)` — fallback when slug unknown
- No per-style gradient tokens — gradients derived via `color-mix(in srgb, var(--category-color) 60%, white)` in CSS
- Removed all hex literals from `mega-menu.css` and shop component styles
- All gradients use `color-mix` with white second stop (lighter tint) — not black (dark) or arbitrary salmon

**Category color system (`useCategoryColors.ts`):**
- `SLUG_TO_TOKEN` — maps the 8 canonical music style slugs to their `--clr-style-*` token names
- `getCategoryColor(slug)` → `var(--clr-style-jazz)` etc.
- `getCategoryStyle(slug)` → `{ '--category-color': 'var(--clr-style-jazz)' }` — inline style object for component root
- `getStyleSlug(categories[])` — finds first category whose slug is a known music style (key fix: ignores difficulty/subcategory slugs that were causing all cards to show default bossa orange)
- `difficultyLabel(n)` → 'Beginner' | 'Early Intermediate' | 'Intermediate' | 'Late Intermediate' | 'Advanced'
- Composable returns only CSS var references — no hex in TS

**Color inheritance chain:**
1. `useCategoryColors.ts` → `getStyleSlug` picks the music style slug from the categories array
2. `getCategoryStyle(slug)` produces `{ '--category-color': 'var(--clr-style-jazz)' }`
3. Component sets this as `:style` on root element (e.g. `.sbn-product-card`)
4. Scoped CSS derives `--category-gradient` from `color-mix(in srgb, var(--category-color) 60%, white)`
5. All badge/overlay/hover consumers reference `var(--category-gradient)` — no JS needed for gradients

**Mega Menu (`MegaMenu.vue`, `mega-menu.css`):**
- Three mega panels matching live site structure: Courses, Explore, Shop
- Top 10 is a sub-column inside Explore (not a top-level item)
- Explore panel includes: TOP10 Lists + Resources columns + Featured Content
- Courses panel includes: Browse by Level + Browse by Style + Featured Course + New Course
- Shop panel includes: Product Categories + Browse by Category + Featured Product + Custom Services
- Images at `/images/mega-menu/courses-featured.webp`, `explore-featured.webp`, `shop-featured.webp`
- All hex literals replaced with design tokens; zero hex literals remain in `mega-menu.css`

**Shop stylesheet (`public/css/shop.css`):**
- New file for shared shop layout (container/sidebar grid, products grid, pagination, breadcrumb, checkout form shell, order success layout)
- Loaded from `app.blade.php` after `sbn-design-system.css`
- Component-internal styles (card hover, badge positioning, drawer line items) stay scoped

**Functional fixes applied:**
- `DownloadController.php` — explicit `pdfs` disk in `config/filesystems.php`
- `CheckoutController.php` — correct return type `\Illuminate\Http\RedirectResponse`
- `ShopController.php` — null-safe `$product->excerpt ?? ''` before `strip_tags`
- `useCart.ts` — thumbnail stored as raw URL string (not via `new URL()` which throws on relative paths)
- `useCategoryColors.ts` — `getStyleSlug()` prevents wrong color from difficulty/subcategory slugs

**Key rule going forward:** any new phase that adds a music style must add it to both `SLUG_TO_TOKEN` in `useCategoryColors.ts` AND to `sbn-design-system.css`. The 8 canonical slugs are the single source of truth for brand color assignment.

---

### Phase 3 — Chord Library + Chord Diagram Component

**Status: Index DONE · Search DONE · Show deferred to Phase 7 (wire details)**

**Deliverable:** Chord library browse/search page + reusable `<ChordDiagram>` / `<ChordCard>` Vue components used here and in later phases.

#### Architecture decisions made

- `ChordDiagram.vue` is a dedicated SVG-only rendering component (not merged into ChordCard). It exports the shared `ChordDiagramData` interface used throughout the system.
- `ChordCard.vue` composes `ChordDiagram` and adds name, inversion label, popularity pill, difficulty stars, and hover controls. It is the reusable card atom for all phases.
- Chord name on cards uses `quality + extensions` (e.g. `m7`, `maj7(9)`) mapped through a lookup table — **not** the `name` field. The root is only prepended when `transposed_from` is set on the chord (i.e. the card came from a transposition search result).
- Finger numbers are built from `diagram_data.positions[i].finger` into a 6-char string passed to `sbnRenderDiagramSVG`.
- Non-search filtering is client-side (quality, voicing, popularity, difficulty, inversion, extensions) over the initial payload. Text search that parses as a chord name hits the transposition endpoint; everything else is substring match.
- Archetype panel (open-position shapes grouped by `shape_family`) is a separate section above the main grid, hidden when any filter is active.

#### Transposition search

The search box does double duty:

- Substring match against name/quality/category/extensions for non-chord-shaped input (e.g. `drop 2`, `rootless`).
- Transposition lookup when the query parses as a chord name. `Dm7` → backend parses root=D + quality=m7, queries all m7 shapes, transposes each to D via `ChordShapeCalculator`, returns them as full `ChordDiagramData[]`. Sidebar filters still narrow the transposed set.

The transposition pipeline is owned by a new shared service, **`App\Services\ChordVoicingSearch`**, extracted from `Admin\LeadsheetController`. Both the admin voicing picker (`/api/admin/leadsheets/search-voicings[-advanced]`) and the public library (`/library/chords/search`) delegate to it. No duplicated chord-name parsing or transposition logic.

Alias matching (via `sbn_chord_diagram_aliases`, e.g. a Bdim shape aliased as G7(b9)) is included in both entry points.

#### Files created

| File | Role |
|------|------|
| `resources/js/Components/Library/ChordDiagram.vue` | SVG renderer; exports `ChordDiagramData` TS interface |
| `resources/js/Components/Library/ChordCard.vue` | Card atom; reusable in Phase 5, 7, 8 |
| `resources/js/Pages/Library/Chords/Index.vue` | Browse page — archetype panel + filter sidebar + chord grid + transposition search |
| `resources/js/Pages/Library/Chords/Show.vue` | **Stub only** — full implementation lands in Phase 7 (needs progression + song previews) |
| `app/Services/ChordVoicingSearch.php` | Chord-name parse → query → transpose. Shared between admin picker and public library. |
| `app/Http/Controllers/Library/ChordLibraryController.php` | `index()` splits chords into `archetypeFamilies` + `otherChords`; `show()` returns chord + siblings; `search()` delegates to `ChordVoicingSearch` |
| `public/css/chord-library.css` | All chord library layout/card styles; loaded globally from `app.blade.php` |
| `routes/web.php` | `GET /library/chords` + `GET /library/chords/search` + `GET /library/chords/{slug}` |

Note: a dedicated public-facing `ChordCard.vue` was created under `Components/Library/` rather than reusing the admin `tab-editor/components/ChordCard.vue`. The two cards serve different audiences (admin picker vs public library) and their prop surfaces diverge enough that sharing one component would have complicated both. Keep this divergence in mind if a future phase wants to unify them.

#### Key CSS notes

- `public/css/chord-library.css` is the single source for all chord library styles. No scoped styles on Index or ChordCard.
- `ChordDiagram.vue` has one scoped rule: `svg { width: 100%; height: auto; }`.
- SVG line thickness overridden via `:deep(svg line)` in card CSS (renderer hardcodes stroke-width; cannot pass opts).
- Extension badge `(9)`, `(#11)` etc. styled in muted colour to read as secondary.

#### Remaining for Phase 3

- [ ] `Show.vue` full implementation — lands in Phase 7 (needs progression examples + songs sidebar).
- [ ] Audio preview on card play button (`useChordAudio` composable, already exists in tab-editor).

**Legacy references:**
- `sbn-course-player(legacywp)/flavor-starter/flavor-starter/page-chords-redesign.php` — index page template
- `sbn-course-player(legacywp)/inc/chord-detail-page.php` — detail page template (for Show.vue)
- `sbn-course-player(legacywp)/assets/css/sbn-chord-card.css` — card styles reference
- `sbn-course-player(legacywp)/assets/css/chord-detail-page.css` — detail page styles (for Show.vue)
- `sbn-course-player(legacywp)/assets/css/chord-styling.css` — chord name typography reference
- `sbn-course-player(legacywp)/inc/chord-search-handler.php` — transposition search logic to port
- `public/js/chords.js` — `sbnRenderDiagramSVG({ frets, position, fingers })` + `sbnFormatChordHtml()`

---

### Phase 4 — Rhythm Library + Rhythm Pattern Component ✅ DONE (April 22, 2026)

**Deliverable:** Public rhythm library (browse + detail) + reusable `<RhythmPattern>` Vue component that both renders and plays a pattern. This component is a dependency for Phases 5, 7, 9.

**Status: DONE**

#### Scope

Mirror the Phase 3 pattern exactly so consumers have a consistent mental model:

1. **`Pages/Library/Rhythms/Index.vue`** — browse/search page with filter sidebar, analogous to the chord library Index. Uses `PublicLayout`. Renders a grid of `RhythmCard`s (thin wrapper: name + style/category + difficulty + mini `RhythmPattern` preview + hover play).
2. **`Pages/Library/Rhythms/Show.vue`** — pattern detail page. May defer full content like the chord `Show.vue` did (stub acceptable if content structure isn't ready) but the route + controller action must exist.
3. **`Components/Library/RhythmPattern.vue`** — the reusable renderer + player. This is the substantial new-component effort in this phase. See "Component contract" below.
4. **`Components/Library/RhythmCard.vue`** — card atom. Follows `ChordCard` conventions: uses CSS tokens from `sbn-design-system.css`, no hex literals, music-style color via `useCategoryColors`.
5. **`app/Http/Controllers/Library/RhythmLibraryController.php`** — `index()` returns grouped + flat lists; `show(slug)` returns pattern + siblings. Same serialization pattern as `ChordLibraryController`.
6. **Routes** (`routes/web.php`): `GET /library/rhythms`, `GET /library/rhythms/{slug}`. No separate search endpoint for this phase — client-side substring filter over the initial payload is sufficient (rhythms don't have a transposition equivalent).

#### What already exists — reuse, do NOT rebuild

- **Model**: `App\Models\RhythmPattern` + migration already in place. `toPlayerData()` returns the shape the frontend needs.
- **Audio adapter**: `resources/js/audio/adapters/rhythmPatternToEvents.js` — pure function, converts the model to `EngineEvent[]`. Consumes `{ thumb, fingers, percTop, percBass, beats, gridType }` exactly as `toPlayerData()` emits. Do not touch this adapter.
- **Audio engine**: `resources/js/audio/` (AudioEngine, Scheduler, PlaybackClock). Use via the `useAudioEngine` composable. Patterns of existing call sites: `resources/js/tab-editor/composables/` (see `useAudioEngine`, `useChordAudio` for reference).
- **Admin authoring**: `app/Http/Controllers/Admin/RhythmPatternController.php` + its Blade views already exist — this is admin-side and stays on Blade. Do not migrate.
- **Design tokens**: use `--clr-style-*` tokens from `sbn-design-system.css` via `useCategoryColors`. Canonical music-style slugs (`bossa`, `jazz`, `samba`, `latin`, `blues`, `pop`, `classical`, `gold`) are the single source of truth for color; do not add new ones without also updating `SLUG_TO_TOKEN` in `useCategoryColors.ts` AND the stylesheet.

#### Component contract — `<RhythmPattern>`

Props (define as exported TS interface in the component, import everywhere; do not redefine downstream):

```ts
interface RhythmPatternData {
  name: string;
  beats: number;         // total grid steps in pattern
  gridType: 'eighth' | 'sixteenth' | 'triplet';
  thumb: string;         // bass pattern: x=soft, X=accent, .=rest
  fingers: string;       // fingers pattern, same encoding
  bpm: number;
  timeSignature: string; // e.g. '4/4'
  percTop: string;       // instrument for fingers, or 'none'
  percBass: string;      // instrument for thumb, or 'none'
}

interface RhythmPatternProps {
  pattern: RhythmPatternData;
  tempo?: number;        // override pattern.bpm
  playable?: boolean;    // default false — when true, mount play button + wire audio
  mini?: boolean;        // compact preview variant for cards
}
```

Responsibilities:
- **Visual**: render a grid of cells where each cell corresponds to one step of the pattern. Cell state: rest / soft hit / accent. Separate rows for thumb and fingers. Beat markers every 1 beat. Preserve time-signature and grid-type semantics visually (triplets look different from sixteenths).
- **Playback**: when `playable`, provide a play/stop button. On play, build events via `rhythmPatternToEvents(pattern)`, feed to `AudioEngine`, and visually highlight the currently-playing step (subscribe to the engine's clock, light up the active cell). On stop, halt and clear highlight. Loop indefinitely while playing.
- **A11y**: play/stop button has aria-label; pattern grid has role/labelling so a screen reader can read the pattern meta.

Legacy reference for intended visual output: `sbn-course-player(legacywp)/inc/rhythm-patterns/class-rhythm-patterns.php` and `assets/css/rhythm-library-frontend.css`. Read these before writing CSS — port the design, don't reinvent it. Match cell shape/spacing, accent vs soft styling, and row layout.

#### Order of work

1. Scaffold `RhythmLibraryController` + routes + minimal `Index.vue` showing names only. Verify data flows end-to-end with current models.
2. Build `RhythmPattern.vue` visual layer (no audio yet). Test by passing a few fixture patterns via a devroute or the Index grid. Match legacy visual reference.
3. Add audio: wire `rhythmPatternToEvents` + `useAudioEngine`, play/stop button, active-step highlight. Use an existing tab-editor composable as the reference implementation for engine wiring — do not rewrite audio infrastructure.
4. Card + filter sidebar (category, difficulty, time-signature, text search). Client-side only, mirror Phase 3 Index filtering.
5. `Show.vue` — can be stub if no per-pattern content ready yet. Just ensure the route resolves and `<RhythmPattern :playable="true">` renders in a full-page layout.
6. TypeScript: component and its props are TS from day 1. Export `RhythmPatternData` as the cross-phase contract.

#### What was built

**Architecture decisions:**

- `RhythmPattern.vue` is both a visual renderer and a player (unlike ChordDiagram which is display-only). It manages its own audio lifecycle via component-local refs (no Pinia/store per Phase 4 spec). Each instance independently wires to the shared `AudioEngine` singleton.
- Category-to-color mapping: `brazilian` → `samba` (green), `general` → `pop` (pink), `jazz` → `jazz` (blue), `latin` → `latin` (purple). The controller adds `styleSlug` during serialization so cards can use `getCategoryStyle()` directly.
- Audio wiring follows the pattern from `useAudioEngine`/`useChordAudio` composables: local `_inited` flag, subscription cleanup in `onBeforeUnmount`, shared engine via `getAudioEngine()`.
- Pattern encoding: `x` = soft hit, `X` = accent, `.` = rest. Same as admin editor. Grid types affect step duration (eighth = 0.5 beats, sixteenth = 0.25, triplet = 1/3).

**Files created:**

| File | Role |
|------|------|
| `resources/js/Components/Library/RhythmPattern.vue` | Core renderer + player component; exports `RhythmPatternData` and `RhythmPatternWithMeta` TS interfaces |
| `resources/js/Components/Library/RhythmCard.vue` | Card atom with mini pattern preview + play button; category-colored per `useCategoryColors` |
| `resources/js/Pages/Library/Rhythms/Index.vue` | Browse page with category-grouped grid, filter sidebar (category/time-sig/grid-type), text search |
| `resources/js/Pages/Library/Rhythms/Show.vue` | Pattern detail page with full-size playable pattern, details table, sibling patterns sidebar |
| `app/Http/Controllers/Library/RhythmLibraryController.php` | `index()` returns patterns grouped by category; `show()` returns pattern + siblings; maps categories to style slugs |
| `public/css/rhythm-library.css` | Shared layout styles (grid, sidebar, filters) following chord-library.css pattern; loaded in `app.blade.php` |
| `routes/web.php` | `GET /library/rhythms` + `GET /library/rhythms/{slug}` |

**Key implementation notes:**

- `RhythmPattern` visual grid has three rows: beat labels (1, +, 2, +...), fingers pattern, thumb pattern. Cells show hit states (soft/accent) with colors matching admin editor (`--clr-red` for accents, `#fef3f2` for soft hits).
- Playback uses `rhythmPatternToEvents()` adapter (unchanged) to convert pattern → engine events. Active step highlighting syncs to engine `tick` events.
- Index page groups patterns by category with colored category headers (dot matches style color). No difficulty filter (model doesn't have the field).
- Show page includes a blend slider (samples ↔ demo MP3) sitting inline next to the play button. Details table + related-songs sidebar removed — that area is reserved for song-based content wired in Phase 7.

#### Audio engine work that landed during this phase (April 23, 2026)

The rhythm library was the first consumer of the audio engine's loop + percussion-sampler paths, so several engine-level fixes and features landed as part of Phase 4:

- **Replay-after-ended bug fix.** `Scheduler.onEnded` now stops and rewinds the clock before emitting `ended`, so a subsequent `play()` starts from beat 0. `AudioEngine.play()` also defensively rewinds if the clock is past the loaded events' end.
- **Native loop primitive.** `Scheduler.load(events, { loop: true, loopBeats })` wraps event scheduling around a configurable loop length. When looping, no `ended` fires — the scheduler just advances `_loopCycle` and rebases subsequent event times. Replaces the previous `ended → play` ping-pong hack.
- **Samples ↔ demo blend.** `AudioEngine` now maintains two gain buses (`samplesBus` for percussion, `demoBus` for the demo MP3). `setBlend(0..1)` cross-fades with a biased power curve tuned to current WAV loudness. `PercussionSampler` routes through the samples bus via a new `setOutput()` method.
- **Demo audio loading.** `load(events, { demoUrl, demoOffsetBeats })` fetches + decodes the MP3 and stores a `Promise` that `play()` awaits before starting. Demo source uses native `AudioBufferSourceNode.loop = true` for tight looping. No scheduling round-trip per loop iteration.
- **Audio assets deployed:** percussion WAVs at `public/audio/rhythm-samples/`, demo MP3s at `public/audio/rhythm-demos/`. Controller serializes `mp3_file` column as `/audio/rhythm-demos/{file}`.

**Known tuning constants** (in `_applyBlend()`): `SAMPLES_BASE = 1.3`, `DEMO_BASE = 0.85`, curves `(1-v)^1.3` / `v^1.7`. Revisit if WAV samples are re-normalized.

#### Definition of done

- `<RhythmPattern>` renders visually with parity against the legacy rhythm renderer (side-by-side spot check).
- `<RhythmPattern :playable="true">` plays on click, loops, highlights the active step in sync with audio, stops cleanly.
- Public rhythm library Index page browses all patterns, filters client-side, shows consistent music-style colors matching the shop / chord library.
- `RhythmPatternData` TS interface is defined once in the component file and imported by any consumer (no duplication).
- Build (`npm run build`) passes; admin rhythm pages (Blade) untouched and still work.
- `docs/Frontend-Migration-Plan.md` Phase 4 section updated with "what was built" notes, same structure as the Phase 3 section.

#### Scope boundary

- Do not touch the admin rhythm editor (Blade).
- Do not migrate any other phase's components. `RhythmPattern` is the only new reusable component this phase owns.
- Do not add a server-side search endpoint. Client-side filter is the spec.
- Do not introduce Pinia or a store for pattern playback state — component-local refs only. If multiple `RhythmPattern` instances on one page must share transport state, that's a Phase 7 concern, not this one.

#### Component inventory

- **[NEW]** `Pages/Library/Rhythms/Index.vue`
- **[NEW]** `Pages/Library/Rhythms/Show.vue` (full or stub)
- **[NEW]** `Components/Library/RhythmPattern.vue`
- **[NEW]** `Components/Library/RhythmCard.vue`
- **[NEW]** `app/Http/Controllers/Library/RhythmLibraryController.php`
- **[NEW]** `public/css/rhythm-library.css` (following the chord-library.css pattern)
- **[REUSE]** `resources/js/audio/adapters/rhythmPatternToEvents.js`
- **[REUSE]** `useAudioEngine` composable from `resources/js/tab-editor/composables/`
- **[REUSE]** `useCategoryColors` composable for music-style colors

#### Legacy references (read before writing code)

- `sbn-course-player(legacywp)/inc/rhythm-patterns/` — pattern renderer + data model
- `sbn-course-player(legacywp)/inc/rhythm-grid.php` — grid layout
- `sbn-course-player(legacywp)/flavor-starter/flavor-starter/page-rhythms.php` — library page template
- `sbn-course-player(legacywp)/assets/css/rhythm-library-frontend.css` — library styles (port into `public/css/rhythm-library.css`)
- `sbn-course-player(legacywp)/assets/js/sbn-percussion.js` — percussion playback (reference for intent only; audio already handled by current adapter)
- `public/css/chord-library.css` — structural reference; match the same Index/card layout conventions

---

### Phase 5 — Chord Progression Library ✅ DONE (April 23, 2026)

**Deliverable:** Public chord progression library: an Index page ported from the legacy WP layout, plus a minimal Show page (title, description, metadata, list of songs featuring the progression). **No `ChordProgressionBlock` component, no audio, no visual rendering of the progression itself in this phase** — the visualization approach is still being decided and will land in Phase 7 when all detail pages wire together.

**Status: DONE** — controller, routes, slug column, songs-cross-reference query, and both pages shipped. `ProgressionCard` inlined in `Index.vue` rather than extracted (acceptable; card is simple text). Known follow-up: song links use `id` until Phase 6 backfills a `slug` column on `sbn_leadsheets`.

#### Scope

1. **`Pages/Library/Progressions/Index.vue`** — browse page. Port the legacy WP progression library page (see Legacy references below) 1:1 in structure: cards grouped by category, filter sidebar, text search. Match the existing design; don't redesign. Client-side filtering only.
2. **`Pages/Library/Progressions/Show.vue`** — **minimal** detail page. Header with title, category badge, tags. Description. Roman numerals displayed in a simple read-only strip (styled text, not chord diagrams). "Songs featuring this progression" list — rendered as a list of song titles with composer (link target can be a stub / disabled until Phase 6 ships the songs library). No key selector, no play button, no chord diagrams, no `ChordProgressionBlock`.
3. **`Components/Library/ProgressionCard.vue`** — card atom following the Phase 3/4 card conventions. Shows name, numerals (as styled text, not diagrams), category color, tag chips. Click → Show page.
4. **`app/Http/Controllers/Library/ProgressionLibraryController.php`** — `index()` + `show(slug)` following the established pattern.
5. **Routes** (`routes/web.php`): `GET /library/progressions`, `GET /library/progressions/{slug}`.

Explicitly NOT in this phase:
- `ChordProgressionBlock.vue` (deferred; the visual design for "how do we display an example of a progression on an info page" is unresolved — `ChordProgressionBlock` if it exists at all will be defined in Phase 7 after the detail-page wiring makes its role clear).
- Audio playback.
- Key selector / transposition UI.
- Chord diagram rendering on the Show page.

#### What already exists — reuse, do NOT rebuild

- **Model**: `App\Models\ChordProgression` with `name`, `category`, `numerals` (comma-separated Roman), `description`, `typical_genres`, `tags`, `tonality`. Table: `sbn_chord_progressions`. Scopes: `category()`, `search()`. Accessors: `numerals_display` (comma → en-dash), `tags_array`, `category_color`.
- **Songs cross-reference table**: `sbn_progression_occurrences` links `progression_id` → `leadsheet_id`. Use this for the "songs featuring this progression" query. `ChordProgression::getStats()` already demonstrates the join pattern.
- **Admin progression builder**: `app/Http/Controllers/Admin/ProgressionBuilderController.php` — admin-only, Blade. Do NOT migrate, do NOT look at for visual reference (too feature-rich for a public info page).
- **Design tokens**: music-style colors via `useCategoryColors`. Map progression categories (`jazz`, `blues`, `pop`, `modal`, `classical`, `latin`, `other`) → existing style slugs. Extend `SLUG_TO_TOKEN` in `useCategoryColors.ts` ONLY if no existing slug fits.

#### Controller contract

```php
// index()
return Inertia::render('Library/Progressions/Index', [
    'progressions'  => $collection,   // each with: id, slug, name, category, styleSlug,
                                      //            numerals, numeralsDisplay, tonality,
                                      //            tags (array), description, typicalGenres,
                                      //            chordCount (count of comma-separated numerals),
                                      //            songCount (how many leadsheets reference it)
    'categories'    => [...],         // distinct used categories
    'tags'          => [...],         // distinct tags across all progressions
    'totalCount'    => int,
]);

// show(slug)
return Inertia::render('Library/Progressions/Show', [
    'progression' => [
        'id', 'slug', 'name', 'category', 'styleSlug',
        'numerals', 'numeralsDisplay',
        'tonality', 'tags' (array), 'description', 'typicalGenres',
    ],
    'songs' => [                      // leadsheets featuring this progression
        ['id' => ..., 'title' => ..., 'composer' => ..., 'song_key' => ..., 'slug' => ...],
        ...
    ],
    'siblings' => [...],              // other progressions in same category
]);
```

#### Order of work

1. Check if `sbn_chord_progressions` has a `slug` column. If not, migration to add + backfill slugs from `name`.
2. Controller + route + minimal `Index.vue` showing names and numerals only. Verify data flows.
3. `ProgressionCard.vue` + category-grouped grid + filter sidebar + client-side filtering. Match legacy WP layout.
4. `Show.vue` minimal: header, description, numerals strip, songs list.
5. Style polish per design system tokens.

#### Scope boundary (IMPORTANT)

- **Do NOT build `ChordProgressionBlock.vue`.** Defer to Phase 7. The visual approach for progression examples on info pages is still being determined.
- **Do NOT wire any audio.** No `chordProgressionToEvents`, no `engine.load`, no play button.
- **Do NOT port the admin progression builder.** Stays in admin as Blade.
- **Do NOT render chord diagrams on the Show page.** Only styled numerals + metadata + songs list.
- **Do NOT add a key selector or transposition UI.** Not in scope.
- **Do NOT add a separate search endpoint.** Client-side substring filter is the spec.
- **Do NOT introduce Pinia.**

#### Open items to resolve at start of phase

- **Slug column.** Check whether `sbn_chord_progressions` has a `slug` column. If not, add a migration that derives slugs from `name` (idempotent).
- **Category→style color mapping.** Add progression category keys to `useCategoryColors.ts`'s `SLUG_TO_TOKEN` only if they don't already resolve via existing music-style slugs.
- **Songs link target.** Song detail pages don't exist yet (Phase 9). Decision for this phase: link the song titles to `/library/songs/{slug}` anyway — this lands in Phase 6 which is the next phase. If clicking a song before Phase 6 returns 404, that's acceptable.

#### Definition of done

- `/library/progressions` renders the library with the legacy WP layout, filters work client-side, cards show proper category colors.
- `/library/progressions/{slug}` renders a minimal detail page: header, description, numerals as styled text, list of songs featuring the progression.
- Songs are queried via `sbn_progression_occurrences` join.
- No `ChordProgressionBlock`, no audio, no chord diagrams on the Show page.
- Build (`npm run build`) passes. Admin progression builder untouched and still works.
- `docs/Frontend-Migration-Plan.md` Phase 5 section updated with a "What was built" subsection, same structure as Phases 3 and 4.

#### Component inventory

- **[NEW]** `Pages/Library/Progressions/Index.vue`
- **[NEW]** `Pages/Library/Progressions/Show.vue` (minimal)
- **[NEW]** `Components/Library/ProgressionCard.vue`
- **[NEW]** `app/Http/Controllers/Library/ProgressionLibraryController.php`
- **[NEW]** `public/css/progression-library.css` (follow chord-library.css / rhythm-library.css conventions)
- **[REUSE]** `App\Services\ProgressionBuilder::selectVoicingsForSequence`
- **[REUSE]** `useCategoryColors` composable

#### Legacy references (read before writing code)

- `sbn-course-player(legacywp)/inc/chord-progressions/` — progression data model
- `sbn-course-player(legacywp)/inc/progression-library.php` — library page template (design intent)
- `sbn-course-player(legacywp)/assets/css/progression-library.css` — library styles (port selectively; extend design system, do NOT copy hex literals)
- `sbn-course-player(legacywp)/assets/js/progression-library.js` — library interactions (read for intent only; rewrite in Vue)
- `sbn-course-player(legacywp)/inc/progression-builder.php` + `assets/css/progression-builder.css` — **reference only for visual language of chord tiles**; the builder interactivity is NOT ported to the public library

---

### Phase 6 — Songs Library Browse ✅ DONE (April 24, 2026)

**Deliverable:** Public songs catalog at `/library/songs` — grid of song cards, filter sidebar, text search. Catalog only. **No Show page** (the leadsheet viewer is the whole of Phase 9). Song cards link to `/library/songs/{slug}` which returns a minimal stub "coming soon" page until Phase 9.

**Status: DONE**

#### What was built

**Database & Backend:**
- Migration `2026_04_24_000001_add_slug_to_leadsheets_table` adds a `slug` column (unique, indexed) to `sbn_leadsheets` and backfills from `title` via `Str::slug()` with collision-suffix logic. Idempotent.
- `Leadsheet` model: `slug` added to `$fillable`.
- `SongLibraryController` (`index()` + `show()`): serializes songs with `id, slug, title, composer, songKey, tempo, timeSignature, rhythm, styleSlug, description (truncated 120 chars), popularity, measureCount`. Style slug derived via `rhythmToStyleSlug()` — maps rhythm pattern slugs (bossa, samba, jazz, swing, latin, afro-cuban, blues, pop, ballad, classical) to the 8 canonical design-system color slugs, with `bossa` as default.
- Routes: `GET /library/songs` + `GET /library/songs/{leadsheet:slug}` (route-model binding via slug).
- `ProgressionLibraryController::show()` patched to select and return the `slug` column on songs (previously used `id` as a placeholder).

**Frontend:**
- `Components/Library/SongCard.vue` — exports `SongCardData` interface. Renders: colored style-bar (from `--category-color`), title, composer, key/timesig/tempo meta badges, description snippet (2-line clamp), rhythm label + popularity pill in footer.
- `Pages/Library/Songs/Index.vue` — header with search box + example chips (Wave, Jobim, bossa, Dm7), count bar, songs grid, sticky filter sidebar (Key, Composer, Rhythm/Style, Tempo range). All filtering is client-side. Reuses `sbn-content-wrapper`, `sbn-filter-sidebar`, `sbn-sidebar-option` CSS from chord/rhythm library conventions.
- `Pages/Library/Songs/Show.vue` — minimal stub: breadcrumb, header card with style-bar + meta chips, optional description section, "coming soon" notice with back link.
- `public/css/song-library.css` — all song library layout + card + show-page styles; no hex literals; follows chord-library.css conventions.
- `app.blade.php` updated to load `song-library.css`.

**Key architectural decisions:**
- Style color derivation: rhythm slug → `rhythmToStyleSlug()` → canonical style slug → `useCategoryColors.getCategoryStyle()`. Documented in `SongCard.vue` and controller. `bossa` is the defensive default.
- No server-side search — client-side substring filter over initial payload (same as rhythm/progression libraries).
- `SongCard` uses a thin top color bar (4 px) instead of the full-card gradient used by shop `ProductCard`. Keeps it visually lighter than a product.

#### Scope

1. **`Pages/Library/Songs/Index.vue`** — port the legacy WP songs library layout (see legacy refs below). Header with unified search box + example chip buttons, filter sidebar on the right, grid of song cards on the left. Client-side filtering over the initial payload.
2. **`Pages/Library/Songs/Show.vue`** — **minimal stub only.** Title, composer, key, tempo, description. No leadsheet rendering. A note ("Full viewer coming in Phase 9") and a back-link to the library. Keeps routes coherent so cards can link without 404s.
3. **`Components/Library/SongCard.vue`** — card atom. Title, composer, key badge, tempo, style color. Link → `/library/songs/{slug}`.
4. **`app/Http/Controllers/Library/SongLibraryController.php`** — `index()` + `show(slug)`.
5. **Route**: `GET /library/songs`, `GET /library/songs/{slug}`.

#### What already exists — reuse, do NOT rebuild

- **Model**: `App\Models\Leadsheet` (table `sbn_leadsheets`). Columns: `title`, `composer`, `song_key`, `tempo`, `time_signature`, `rhythm`, `measure_count`, `popularity`, `description`, `harmony_notes`, `form_notes`, `voicing_notes`. Scopes: `search()`, `inKey()`, `forCourse()`, `withRhythm()`. Static helpers: `getDistinctKeys()`, `getDistinctComposers()`, `getStats()`.
- **Admin editor**: `Admin\LeadsheetController` + Blade views already exist. Do NOT migrate.
- **Design tokens**: music-style colors via `useCategoryColors`. Songs don't have an explicit style/genre column — infer style slug from `rhythm` (the rhythm pattern slug) OR from a new mapping keyed on composer / title keywords. Prefer a defensive default (`bossa`) if nothing matches — the 8 canonical slugs in `sbn-design-system.css` are the source of truth; do NOT add new ones.

#### Open item — slug column

`sbn_leadsheets` doesn't currently have a `slug` column. The Phase 5 controller works around this by using `id` as the URL path. Phase 6 should fix this properly:

- Add a migration that adds a `slug` column (unique, indexed) and backfills from `title` (case-insensitive, hyphenated, ASCII). Idempotent.
- Update `Leadsheet` model to include `slug` in `$fillable`.
- Route-binds by slug: `Route::get('/library/songs/{leadsheet:slug}', ...)`.
- Phase 5's songs-list query should be updated to return `slug` instead of `id` as the route target. That's a small follow-up patch to `ProgressionLibraryController::show()`.

#### Controller contract

```php
// index()
return Inertia::render('Library/Songs/Index', [
    'songs'      => $collection,  // each: id, slug, title, composer, songKey, tempo,
                                  //        timeSignature, rhythm (slug), styleSlug,
                                  //        description (short), popularity, measureCount
    'composers'  => [...],        // distinct composers, sorted
    'keys'       => [...],        // distinct keys actually used
    'rhythms'    => [...],        // distinct rhythm slugs used
    'totalCount' => int,
]);

// show(slug) — minimal stub
return Inertia::render('Library/Songs/Show', [
    'song' => [  // title, composer, songKey, tempo, timeSignature, description,
                 // rhythm, styleSlug
    ],
]);
```

#### Filter sidebar

Client-side only. Filters over the initial payload:

- **Search** — substring match on `title + composer + description`.
- **Key** — toggle pills showing every distinct key in the dataset.
- **Composer** — toggle pills, limit to top N (e.g. 20) most common; rest accessible via search.
- **Rhythm / style** — toggle pills over distinct rhythm slugs used.
- **Tempo range** — optional, only if straightforward (min/max slider). Skip if it's fussy.

Match Phase 3 chord library's sidebar semantics and class naming so the CSS can be reused / extended.

#### Scope boundary (IMPORTANT)

- **No leadsheet rendering.** The Show page is a stub.
- **No server-side search endpoint.** Client-side filter only.
- **No course gating / auth.** Public browse.
- **Do NOT touch the admin leadsheet editor.**
- **Do NOT introduce Pinia.** Component-local refs.

#### Definition of done

- `/library/songs` browses all leadsheets, filters + search work client-side.
- `/library/songs/{slug}` returns a minimal stub page.
- `slug` column added to `sbn_leadsheets`, backfilled, unique.
- Phase 5's progression Show page updated to use the new song slugs (one-line patch to the `songs[].slug` field in `ProgressionLibraryController::show`).
- `SongCard.vue` shows consistent style color via `useCategoryColors` (derivation rule documented in the component file).
- Build passes. Admin editor untouched.
- Plan doc Phase 6 updated with a "What was built" subsection.

#### Component inventory

- **[NEW]** `Pages/Library/Songs/Index.vue`
- **[NEW]** `Pages/Library/Songs/Show.vue` (stub)
- **[NEW]** `Components/Library/SongCard.vue`
- **[NEW]** `app/Http/Controllers/Library/SongLibraryController.php`
- **[NEW]** `public/css/song-library.css` (follow chord-library.css / rhythm-library.css conventions)
- **[NEW]** migration: add `slug` column to `sbn_leadsheets`
- **[REUSE]** `useCategoryColors` composable

#### Legacy references (read before writing code)

- `sbn-course-player(legacywp)/flavor-starter/flavor-starter/page-song-library.php` — library page template (port DOM structure)
- `sbn-course-player(legacywp)/inc/song-library-handler.php` — data shape + filter logic reference
- `sbn-course-player(legacywp)/assets/css/song-library.css` — styles (port selectively; use design-system tokens, no hex literals)
- `sbn-course-player(legacywp)/assets/js/song-library.js` — interactions (read for intent; rewrite in Vue)
- `public/css/chord-library.css` + `public/css/rhythm-library.css` — structural reference; match conventions

#### Scope follow-ups (NOT in this phase)

- "Has tab data" badge or filter — tempting but deferred until Phase 9 defines what tab data means visually.
- Songs by course / lesson grouping — deferred until Phase 11 (course player).
- Full-text search (chord names within a song) — deferred; current `search()` scope already matches title/composer/chord names via the shortcode blob.

---

### Phase 7 — Wire Detail Pages Together ✅ DONE (April 24, 2026)

**Deliverable:** Full implementation of every Show page (chord, rhythm, progression, song teaser) with cross-references between libraries. A new `ChordProgressionViewer` component — a compact strip of chord diagrams with global playback for demonstrating progressions. Chord card click-to-play audio preview via the existing audio engine.

**Status: DONE** — all Show pages live, cross-link queries wired, ChordProgressionViewer shipping with click-to-play tiles and global progression playback, chord card audio preview shipped. Several engine + transposition bugs fixed along the way (see "What was built" below).

#### Scope — summary

1. **New component: `ChordProgressionViewer.vue`** — purpose-built for info-box rendering of a chord progression with global playback. Visual: horizontal strip of small chord diagram tiles with chord names, centered circular play button. Audio: per-tile click plays that single chord, global play button sequences through entire progression with faster tempo (180 BPM).
2. **`Pages/Library/Chords/Show.vue`** — full implementation (stub since Phase 3).
3. **`Pages/Library/Rhythms/Show.vue`** — add "Used in songs" section.
4. **`Pages/Library/Progressions/Show.vue`** — add the `ChordProgressionViewer` rendering + "Used in songs" section.
5. **`Pages/Library/Songs/Show.vue`** — promote from stub to teaser page: chord-name sequence, rhythm preview, progressions found in the song. Still NOT the full leadsheet viewer (that's Phase 9).
6. **`ChordCard.vue`** — wire the existing play button to play a one-shot strum via `chordDiagramToEvents` + shared `AudioEngine`. Shared mutex across cards (only one plays at a time).

#### Component contract — `<ChordProgressionViewer>`

**Note:** Redesigned in Phase B to replace the old chord-tile strip with a living fretboard + chord diagram card layout. Roman numeral badges replace chord name labels.

The viewer shows a sliding fretboard excerpt (7-fret window) centered on the active chord, a chord diagram card on the right, and a badge row of roman numerals below. Supports voice-leading overlays between adjacent chords.

Props (exported TS interface in the component file; import everywhere; do not redefine):

```ts
export interface ProgressionChord {
  chordName: string;                // e.g. 'Dm7' — resolved server-side
  diagramData: ChordDiagramData | null;  // null = unresolvable chord
  beats?: number;                   // Duration in beats (default 0.5)
  slug?: string | null;             // chord slug for optional deep-link
  numeral?: string;                 // Roman numeral e.g. 'IIm7' — shown as badge
  functionalRole?: string | null;
}

export interface ChordProgressionViewerProps {
  chords: ProgressionChord[];
  interactive?: boolean;            // default true
  compact?: boolean;                // default false
  showFlowArrows?: boolean;         // deprecated, kept for prop compat
  color?: string | null;            // accent color (CSS value)
  vintageCard?: boolean;            // thick right+bottom border
  name?: string;                    // progression name in header
  category?: string;
  numerals?: string;                // deprecated PRO badge display
  keyLabel?: string;
}
```

**Visual layout (top → bottom):**
1. **Header** — name, category badge, key badge, optional PRO numerals badge
2. **Stage** — play button | fretboard excerpt (75%) | chord diagram card (25%)
   - Fretboard: 7-fret sliding window, centered on active chord, JS lerp animation (speed 0.035)
   - Dots: black by default; VL-pair resolving dots colored by type (amber/blue/purple), ghost dots for next chord at 35% opacity
   - Interval function labels (R, 3, b7…) shown inside dots
   - VL resolving dots pulse with a subtle glow animation
3. **Badge row** — one button-badge per chord; shows `numeral` if present, falls back to chord name

**Voice-leading overlay** (`guideToneResolution.js`):
- Uses `findResolutionPairs(mapA, mapB, quality, level=2)` between active and next chord
- Resolving dots on current chord: colored + subtle glow pulse
- Ghost dots at next chord's target positions: dimmed, colored by VL type
- Colors: amber = 7→3, blue = 3→R, purple = 9th ext, green = #11 ext, gray = 5th

**Data contract — numeral field:**
All controllers that build progression tiles must include `numeral: $sel['roman_numeral']`:
- `ChordLibraryController` (chord detail page progressions)
- `ProgressionLibraryController` (progression show page)
- `SongLibraryController` (song show page)
- `Top10Controller` (numeral-based progressions only; legacy slug path has no numeral)

**CSS:** chord symbol default color changed to `var(--clr-text)` globally in `public/css/chord-symbols.css` (was `var(--clr-accent-dim)`).

Resolving Roman numerals → concrete tiles stays server-side via `ProgressionBuilder`. The viewer never parses numerals.

#### Cross-link policy (anti-rabbit-hole rule)

On any given Show page, users can navigate **outward** from top-level related lists ("Progressions containing this chord", "Songs using this rhythm", etc.) but tiles inside a progression viewer do NOT link elsewhere — they're click-to-play only. This keeps the graph linear instead of circular:

- Chord Show → list of progressions containing this chord (each is a clickable `ChordProgressionViewer` header linking to the progression Show)
- Progression Show → preview of the progression (non-clickable tiles) + list of songs using it
- Rhythm Show → list of songs using it
- Song Show → chord name strip, rhythm preview, progressions detected; each item links to its own Show page

Rule of thumb: **one list at the bottom of each Show page links to the next hop. Previews inside the page body do not.**

#### Chord Show page — full implementation

`Pages/Library/Chords/Show.vue`:
- Header: chord name (formatted via existing chord-name formatter), inversion label, difficulty stars, popularity pill.
- Main diagram: large `ChordDiagram`, read-only.
- Meta table: interval labels, notes, category, root string, fret position.
- "Sibling voicings" grid: other voicings of the same chord (same `quality + extensions`). The existing Phase 3 `siblings` payload already carries these.
- "Progressions containing this chord": up to 4 mini-previews. Each shows the progression name as a link to its Show page, then a `ChordProgressionViewer`.
- "Used in songs": up to 8 `SongCard`s linking to song Show pages.

Backend: `ChordLibraryController::show(slug)` already serializes siblings. Add: progressions that contain this chord (via `sbn_voicing_usage` → `sbn_progression_occurrences`), and leadsheets that use this chord (via `sbn_voicing_usage.leadsheet_id`). Cap lists server-side.

#### Rhythm Show page — add songs section

`Pages/Library/Rhythms/Show.vue`: add a "Used in songs" section at the bottom, querying `sbn_leadsheets WHERE rhythm = {slug}`. Existing layout stays.

#### Progression Show page — add preview + songs

`Pages/Library/Progressions/Show.vue`: currently renders the progression's numerals as styled text (Phase 5 decision). Phase 7 adds:

- A `ChordProgressionViewer` below the description, resolved for the default key `C` (no key selector in this phase — see "Scope follow-ups"). Controller already has access to `ProgressionBuilder::selectVoicingsForSequence`.
- "Used in songs" section already exists from Phase 5. Leave it or tidy the layout if needed.

#### Song Show page — promote from stub

`Pages/Library/Songs/Show.vue` currently shows a minimal stub. In Phase 7 it becomes a **teaser page**:
- Title, composer, key, tempo (as today)
- Chord strip (just chord names, read-only, styled)
- Mini `RhythmPattern` preview (use the `mini` prop; NO audio on the Show page yet — keep the player for the leadsheet viewer)
- "Progressions detected in this song" list, each a link to the progression Show page
- Prominent "Open in leadsheet viewer" button → `/library/songs/{slug}/viewer` or similar, which remains a stub route until Phase 9

Backend: `SongLibraryController::show(slug)` already returns the leadsheet. Add: detected progressions via `sbn_progression_occurrences WHERE leadsheet_id = {id}`, and a chord-name list derived from the JSON data (`Leadsheet::getChordNames()` exists).

#### Chord card audio preview

`Components/Library/ChordCard.vue` currently has a play button that does nothing. Wire it up:

- On click: `engine.init({ samplesBaseUrl: '/audio/rhythm-samples/' })` (idempotent), load events via `chordDiagramToEvents(chord)` with a `durationBeats: 2` default, `engine.play()`.
- Cards subscribe to `engine.on('playStarted')` to clear their own "is playing" state when another card starts — same singleton-mutex UX as rhythm cards.
- Component-local `isPlaying` ref. `onBeforeUnmount` cleanup. No Pinia.

This is the Phase 3 TODO. Do NOT use the full `useChordAudio` composable from the tab editor — that one takes a tab model and plays measures. For a single-card strum, inline the five lines directly in `ChordCard.vue`.

#### Backend — cross-reference queries

All the tables already exist. Controllers just need new methods / query additions:

| What | Query |
|------|-------|
| Progressions containing a chord | `sbn_voicing_usage.chord_diagram_id = X` → `leadsheet_id`s → `sbn_progression_occurrences.leadsheet_id IN (...)` → distinct `progression_id`s → `sbn_chord_progressions` |
| Leadsheets using a chord | `sbn_voicing_usage WHERE chord_diagram_id = X` → distinct `leadsheet_id`s → `sbn_leadsheets` |
| Leadsheets using a rhythm | `sbn_leadsheets WHERE rhythm = 'slug'` |
| Progressions in a leadsheet | `sbn_progression_occurrences WHERE leadsheet_id = X` → `sbn_chord_progressions` |
| Leadsheets using a progression | already implemented in Phase 5 |

Cap each list server-side (8 for songs, 4 for progressions by default — component-agnostic). Add `->limit()` and `->orderByDesc('popularity')` where popularity exists. If any query shows up as slow in practice, a minute-level cache via `Cache::remember` is fine; don't preemptively optimize.

#### Order of work

1. `ChordProgressionViewer.vue` — visual first. Render fixture tiles. Match design-system tokens.
2. Wire per-tile click-to-play via `chordDiagramToEvents` + shared engine. Share the singleton-mutex pattern with ChordCard.
3. `ChordCard.vue` audio wiring. Same pattern.
4. `ChordLibraryController::show` — add progression + songs queries. Serialize tiles via `ProgressionBuilder::selectVoicingsForSequence` so the frontend receives ready-to-render previews.
5. `Pages/Library/Chords/Show.vue` — full implementation using the above.
6. `ProgressionLibraryController::show` — add preview resolution (call `selectVoicingsForSequence` for key=C, stash `tiles` in the payload).
7. `Pages/Library/Progressions/Show.vue` — render the preview.
8. `RhythmLibraryController::show` + `Pages/Library/Rhythms/Show.vue` — add songs section.
9. `SongLibraryController::show` + `Pages/Library/Songs/Show.vue` — promote from stub to teaser.
10. Smoke test end-to-end: click through chord → progression → songs → rhythm → songs. Verify no page is a dead end.

#### Scope boundary (IMPORTANT)

- **Do NOT build a `ChordProgressionBlock` with full progression playback.** `ChordProgressionViewer` is per-tile click-to-play with optional global sequencing. Full progression playback belongs to the leadsheet viewer (Phase 9) or is explicitly out of scope for this entire migration.
- **Do NOT add a key selector** to progression Show or any preview. Key=C is the spec default. If users need transposed progressions, that's its own phase.
- **Do NOT embed the leadsheet viewer** anywhere. Song Show links to it, doesn't include it.
- **Do NOT port the admin progression builder.** Still stays in admin.
- **Do NOT make tiles inside a `ChordProgressionViewer` link anywhere.** Click-to-play only. Navigation comes from the outer list headers.
- **Do NOT introduce Pinia.** Component-local refs + shared `AudioEngine` singleton.
- **Do NOT add audio on the Song Show teaser's rhythm preview.** Play happens on the Rhythm Show page; on the Song page the rhythm is informational.

#### Component inventory

- **[NEW]** `Components/Library/ChordProgressionViewer.vue`
- **[MODIFY]** `Components/Library/ChordCard.vue` (wire play button audio)
- **[REWRITE]** `Pages/Library/Chords/Show.vue` (full implementation — replaces Phase 3 stub)
- **[MODIFY]** `Pages/Library/Rhythms/Show.vue` (add songs section)
- **[MODIFY]** `Pages/Library/Progressions/Show.vue` (add viewer + clean songs section)
- **[MODIFY]** `Pages/Library/Top10/BossaNovaChords.vue` (use ChordProgressionViewer)
- **[PROMOTE]** `Pages/Library/Songs/Show.vue` (stub → teaser page)
- **[MODIFY]** `ChordLibraryController` (+ progression + songs queries)
- **[MODIFY]** `ProgressionLibraryController` (+ tile resolution for viewer)
- **[MODIFY]** `RhythmLibraryController` (+ songs query)
- **[MODIFY]** `SongLibraryController` (+ chord names + progressions queries)
- **[MODIFY]** `audio/adapters/chordDiagramToEvents.js` (+ ctx.staggerBeats for progression optimization)
- **[REUSE]** `chordDiagramToEvents` adapter, `AudioEngine`, `ProgressionBuilder::selectVoicingsForSequence`, `Leadsheet::getChordNames`

#### Definition of done

- Every Show page is fully implemented (no stubs) and cross-links work end-to-end.
- Chord cards play on click; only one card plays at a time across the whole page (including cards inside viewers).
- `ChordProgressionViewer` renders on chord Show (inside the "progressions containing this chord" list), on progression Show (as the main visualization), and on Top10 pages.
- `ChordProgressionViewerProps` + `ProgressionChord` TS interfaces exported from the component.
- Smoke-test navigation completes: start from any library Index, click through 3+ Show pages following cross-links, no 404, no silent failure.
- Build passes. Admin untouched.
- Plan doc Phase 7 updated with a "What was built" subsection.

#### What was built

**New component**

- **`Components/Library/ChordProgressionViewer.vue`** — exports `ProgressionChord` + `ChordProgressionViewerProps` interfaces. Horizontal strip of chord tiles with global play button, each tile click-plays one strum via `chordDiagramToEvents` + the shared `AudioEngine`. Global play button sequences through entire progression with faster tempo (180 BPM) and custom stagger. Uses `engine.on('playStarted')` to clear its own active-tile state when another consumer (card or rhythm) takes over the engine — same singleton-mutex UX as rhythm cards. Supports `interactive` and `compact` props. Blue hover theme to distinguish from regular chord cards. Tiles never link out (anti-rabbit-hole rule preserved).

**Show pages**

- **`Pages/Library/Chords/Show.vue`** — full implementation. Hero diagram via `ChordCard` (reuses the card's audio plumbing). Sibling voicings, theory/intervals panel, "Chord Progression Examples" section listing up to 4 progressions that contain a chord with this quality, each rendered as a `ChordProgressionViewer` with the current chord pinned at its slot.
- **`Pages/Library/Rhythms/Show.vue`** — added "Used in songs" section. Existing blend slider + pattern visualization unchanged.
- **`Pages/Library/Progressions/Show.vue`** — renders the progression as a `ChordProgressionViewer` resolved in key C; "Used in songs" was already present from Phase 5 (now using `songKey` per the field-naming normalization fix).
- **`Pages/Library/Songs/Show.vue`** — promoted from stub to teaser: title/composer/key/tempo, mini rhythm preview (no audio), chord-name strip, detected progressions list, link to future `/library/songs/{slug}/viewer` (stub route until Phase 9).

**Chord card audio**

- **`Components/Library/ChordCard.vue`** — play button wired with `chordDiagramToEvents` + `engine.play()`. Local `isPlaying` ref; `playStarted` listener clears it when another consumer takes the engine. Includes a small SVG dot-ping animation during the arpeggio sweep (120ms per string).

**Backend cross-reference queries**

- `ChordLibraryController::show` — adds `progressions` (filtered by parsed numeral quality, not raw `LIKE`) and `songs` (via `sbn_voicing_usage`).
- `RhythmLibraryController::show` — adds `songs` (via `sbn_leadsheets.rhythm`).
- `SongLibraryController::show` — adds detected progressions and chord-name list.
- `ProgressionLibraryController::show` — `tiles` resolved server-side via `HarmonicContext::buildFromNumerals` + `ProgressionBuilder::buildVoicings` (the richer voice-leading path) instead of `selectVoicingsForSequence`. Result is voice-led across the progression rather than picking each chord in isolation.

**Engine + transposition fixes that landed during this phase**

Several latent issues in adjacent code surfaced as soon as detail pages started rendering progressions:

1. **Voicing pinning was silently dropped.** `ProgressionBuilder::buildVoicings`'s pinned-slot guard used `isset($selections[$pinnedSlot])`, which returns false on null entries. Replaced with a range check (`>= 0 && < $n`) so the pinned voicing actually lands in the selections array. Without this, the pinned-chord trick on the chord Show page was never taking effect — every progression silently rendered with default voicings.
2. **Progression key was always C.** The chord-Show resolver originally built the harmonic context in key C and just slotted in the user's chord, producing collisions (Ebm7 pinned into a context where IIm7 was Dm7 and surrounding chords were G7/Cmaj7). Now the controller searches all 12 candidate keys and picks the one whose parsed slot resolves to a chord with the same root + quality as the pinned chord. Surrounding chords are then in the correct harmonic relationship (Ebm7 → key Db → Ab7, Dbmaj7).
3. **Pinned diagram_data wasn't transposed.** The DB stores transposable shapes at their canonical root (e.g. Cm7 for the m7 shape); the controller was passing the raw stored diagram_data into the pinned voicing even when the user was viewing a transposed version. Now calls `ChordShapeCalculator::calculateFrets($chord, $pinnedRoot)` whenever the pinned root differs from the stored root, so the pinned tile shows the same exact frets as the hero diagram.
4. **Hero diagram lost frets when ?root= was passed.** `serializeChord` read `$chord->diagram_data` directly without transposing, so the hero diagram showed the raw stored shape while the label said the transposed name. Same `calculateFrets` treatment applied — hero and pinned tile now agree.
5. **Over-broad progression matching.** The original `ChordLibraryController::show` used `LIKE '%{$qualitySuffix}%'` which produced massive false positives (`'7'` matched every progression with any 7th chord). Replaced with parsed-quality membership: load all progressions, parse each via `HarmonicContext::buildFromNumerals('C', $numerals)`, keep ones whose parsed chord qualities include the target. Cheap at table scale (~50 progressions); cache via `Cache::remember` if it ever becomes a hotspot.
6. **`song_key` vs `songKey` field-naming inconsistency.** `ProgressionLibraryController` was emitting `song_key` while every other Library controller used `songKey`. Normalized to `songKey` everywhere; updated `Progressions/Show.vue` accordingly.

**Open follow-up the user resolved separately**

- Fret-data display issue with voicings stored at convenient (non-canonical) fret positions — user solved this themselves; no further action needed in this phase.

**Files touched**

| File | Role |
|------|------|
| `resources/js/Components/Library/ChordProgressionViewer.vue` | New component |
| `resources/js/Components/Library/ChordCard.vue` | Audio + dot-ping animation |
| `resources/js/Pages/Library/Chords/Show.vue` | Full implementation |
| `resources/js/Pages/Library/Rhythms/Show.vue` | + Songs section |
| `resources/js/Pages/Library/Progressions/Show.vue` | + Preview, songKey rename |
| `resources/js/Pages/Library/Songs/Show.vue` | Stub → teaser |
| `app/Http/Controllers/Library/ChordLibraryController.php` | Cross-refs, parsed-quality progression filter, hero transposition |
| `app/Http/Controllers/Library/ProgressionLibraryController.php` | Tile resolution, songKey rename |
| `app/Http/Controllers/Library/RhythmLibraryController.php` | + Songs query |
| `app/Http/Controllers/Library/SongLibraryController.php` | + Progressions + chord names |
| `app/Services/ProgressionBuilder.php` | Pinned-slot guard fix |

#### Scope follow-ups (NOT in this phase)

- Key selector on progression Show — punt until someone asks.
- Full progression playback with loop — leadsheet viewer (Phase 9) if ever.
- Audio on Song Show — same.
- Caching strategy for cross-reference queries — only if a profile shows a real hotspot.

---

### Phase 7.5 — Progression UI Standardization ✅ DONE (May 11, 2026)

Refinement pass that standardized the `ChordProgressionViewer` as the single source of truth for progression rendering across Chord, Song, and Progression libraries.

#### What was built
- **Standardized Component**: `ChordProgressionViewer.vue` was enhanced to include its own header metadata (Name, Category, Key, Numerals). It now manages styling (category colors) and the "Vintage Card" aesthetic internally.
- **Unified Resolution Pipeline**: Replaced fragmented logic in `SongLibraryController`, `ChordLibraryController`, and `ProgressionLibraryController` with a consistent `HarmonicContext` → `ProgressionBuilder` pipeline. 
- **Bug Fixes**:
  - Resolved `Call to undefined method App\Services\ProgressionBuilder::resolve()` by switching to the supported `buildVoicings` pipeline.
  - Fixed `Undefined array key "chord_name"` by correctly accessing the `selections` nested key in the builder output.
- **Visual Consistency**: Category colors (e.g., Jazz Blue, Latin Purple) are now enforced through the `useCategoryColors` composable, ensuring the "Vintage" cards look identical across all library modules.

#### Updated Documentation
- **SBN-Design-Reference.md**: Added full specification for `CHORD PROGRESSION VIEWER` component usage.
- **SBN-Builder-Reference.md**: Documented the standard implementation pattern for resolving progressions for display.

---

### Phase 7.6 — Guide Tone Display in ChordProgressionViewer ✅ DONE (May 14, 2026)

Added two permanent guide-tone layers to `ChordProgressionViewer` — interval-colored dots on every chord diagram, and voice-leading resolution arrows between adjacent chords. These are always-on; they are the central design feature of the progression viewer.

#### What was built

**1. Interval-colored dots (`ChordDiagram.vue` + `public/js/chords.js`)**

- Added `showGuideTones?: boolean` prop to `ChordDiagram.vue` (default `false`).
- When `true`, reads `chord.interval_labels` (comma-separated string like `"x,R,5,b7,b3,x"` — 6 positional entries, one per string, low E→high e) and passes it as `intervalLabels` to `sbnRenderDiagramSVG`.
- `sbnRenderDiagramSVG` in `public/js/chords.js` was extended with guide-tone coloring:
  - Added `GT_COLORS` palette: amber `#d97706` = 7ths (`b7`, `7`, `maj7`), blue `#2563eb` = 3rds (`3`, `b3`), `#6b7280` gray = root (`R`) and 5ths, purple `#7c3aed` = 9ths, green `#059669` = 11ths, yellow `#ca8a04` = 13ths/6ths.
  - Added `sbnGtColorForInterval(label)` helper function.
  - When `opts.intervalLabels` is provided: fretted dots are filled with the interval color and the interval label is rendered inside the dot (finger numbers suppressed). Open strings with a guide tone get a filled colored circle instead of a plain ring.
- Both interactive and static `ChordDiagram` instances in `ChordProgressionViewer` pass `:show-guide-tones="true"`.

**2. Voice-leading arrows (`GuideToneArrowBridge.vue` + `guideToneResolution.js`)**

New file `resources/js/Components/Library/GuideToneArrowBridge.vue` — an absolute-positioned SVG overlay rendered inside each `.sbn-prog-viewer-item` (which has `position: relative`) spanning both adjacent chord tiles plus the gap between them.

New file `resources/js/Components/Library/guideToneResolution.js` — mirrors `ProgressionBuilder::scoreVL()` exactly.

**Resolution logic** (`guideToneResolution.js`):

- `buildPitchMap(diagramData)` → `[{string, fret, midi, label, svgX, svgY}]`  
  Parses `interval_labels`, `diagram_data` (open/positions/muted), and `start_fret`.  
  SVG coordinates match `sbnRenderDiagramSVG`: viewBox 80×95, `left=14`, `top=12`, `strSp=12`, `fretSp=16`, `numFrets=4`.

- **Level 1 rules** (always applied):
  - `b7/7/maj7` of A → nearest `3/b3` of B
  - `3/b3` of A → nearest `R / b7/7/maj7 / 9` of B

- **Level 2 rules** (extensions; enabled when `diagramData.extensions` is truthy):
  - `9/b9/#9` of A → nearest `13/b13 / 9 / R / 5` of B (b9 only if target is minor)
  - `#11/11` of A → nearest `5 / 3 / 9` of B
  - `5` of A → nearest `R / 5` of B

- **Distance metric**: pitch-class distance `min(d%12, 12-d%12)` — octave-equivalent, max 6 semitones. Raw MIDI distance is used only to pick the *nearest* target voicing; the 6-semitone cutoff uses pitch-class distance so cross-octave resolutions (e.g. B→C) are not excluded.

- **Common-tone suppression** (critical invariant): before drawing any arrow, `nearestPairs` checks whether the source voice's pitch class is already present in chord B (`mapBPCs = new Set(mapB.map(p => p.midi % 12))`). If it is, the voice is retained (not resolved) and no arrow is emitted. This prevents false arrows on identical or partially-identical voicings.

- **Deduplication**: `dedupe(pairs)` — when the same source dot appears in multiple pairs, keep only the pair with the smallest pitch-class distance.

**Arrow colors by resolution type:**
| Type | Color | Meaning |
|---|---|---|
| `seventh-to-third` | amber `#d97706` | 7th resolves to 3rd |
| `third-to-root` | blue `#2563eb` | 3rd resolves to root/7th |
| `ninth-ext` | purple `#7c3aed` | 9th extension |
| `eleventh-ext` | green `#059669` | #11 extension |
| `fifth-ext` | gray `#6b7280` | 5th continuation |

**SVG coordinate model** (`GuideToneArrowBridge.vue`):

- Canvas spans: `canvasW = tileW * 2 + gapW`, positioned at `top: 38px` (below chord-name header), `left: 0`.
- Scale: `scale = diagW / SVG_W` where `diagW = tileW - tilePadX`.
- `toPxA(svgX, svgY)`: `cx = padL + svgX * scale` (diagram A).
- `toPxB(svgX, svgY)`: `cx = tileW + gapW + padL + svgX * scale` (diagram B).
- Renders per pair: dashed colored line → arrowhead polygon → pulsing ghost circle at target dot → interval label text.
- `@keyframes gt-ghost-pulse` and `.gt-ghost` are in an **unscoped** `<style>` block (scoped styles don't reach dynamically-rendered SVG elements).

**Backend: `functional_role` in voicing output** (`ProgressionBuilder.php`, `ProgressionLibraryController.php`):

- `formatVoicing(object $v, ?string $contextChordName, ?array $chordContext)` — third param added. When `$chordContext` is provided (with `roman_numeral`), calls `determineFunctionalRole` and emits `functional_role` in the output array.
- `ProgressionLibraryController::show()` and `apiShow()` pass `functional_role` through to the frontend as `functionalRole` on each chord.
- The `ProgressionChord` interface in `ChordProgressionViewer.vue` was extended with `functionalRole?: string | null`.

#### Files changed
| File | Change |
|---|---|
| `public/js/chords.js` | Added `GT_COLORS`, `sbnGtColorForInterval`, extended `sbnRenderDiagramSVG` with `intervalLabels` option |
| `resources/js/Components/Library/ChordDiagram.vue` | Added `showGuideTones` prop; passes `intervalLabels` + `showFingers: false` to render call |
| `resources/js/Components/Library/ChordProgressionViewer.vue` | Imports `GuideToneArrowBridge`; adds `functionalRole` to `ProgressionChord`; both diagram instances use `:show-guide-tones="true"` |
| `resources/js/Components/Library/guideToneResolution.js` | **New** — VL resolution logic mirroring `ProgressionBuilder::scoreVL()` |
| `resources/js/Components/Library/GuideToneArrowBridge.vue` | **New** — SVG arrow overlay between adjacent chord tiles |
| `app/Services/ProgressionBuilder.php` | Added `$chordContext` param to `formatVoicing`, emits `functional_role` |
| `app/Http/Controllers/Library/ProgressionLibraryController.php` | Passes `functional_role` through as `functionalRole` in both `show()` and `apiShow()` |

#### Key invariants / traps for future editors

1. **Common-tone suppression is load-bearing** — `nearestPairs` must receive `mapBPCs` and skip sources whose PC is already in chord B. Removing this causes false arrows whenever two adjacent chords share any guide tone (e.g. Cmaj7/E → Cmaj7/E triggers b7→3 and 3→b7 arrows).

2. **Use pitch-class distance, not raw MIDI** — raw MIDI distance of 11 semitones between B (MIDI 59) and C (MIDI 60+octave) would exclude the canonical b7→3 resolution of a V7 chord. `pcDist = min(d%12, 12-d%12)` gives 1 semitone, which is correct.

3. **`scoreVL` is role-agnostic** — the builder's VL scoring is purely proximity-based. The `named_resolutions` YAML provides a Pass 2 bonus cost term only; it does not gate whether a resolution is detected. Do not add role/functional-role checks to the JS resolution logic.

4. **SVG canvas spans both full tiles** — the bridge SVG width is `tileW*2 + gapW`. The diagram origin for chord B is at `tileW + gapW + padL` (not at a narrow midpoint). Earlier "narrow overlap" approach put dots at negative x coordinates for most voicings.

5. **Unscoped `<style>` for SVG animations** — Vue's scoped CSS attribute is not applied to SVG elements rendered inside the component's template. `@keyframes gt-ghost-pulse` and `.gt-ghost` must be in an unscoped `<style>` block.

---

### Phase 8 — Top10 Pages

**Deliverable:** Top10 landing pages (bossa nova chords, chord progressions, best voicings, etc.) as pure composition of existing components.

**Component inventory:**
- **[NEW]** `Pages/Top10/[Slug].vue` (one template per list type)
- **[REUSE]** `ChordCard`, `ChordProgressionViewer`
- **[REUSE]** `PublicLayout`

**Reference:** existing React implementation in WP. Read it for UX, rebuild in Vue; do not mechanically translate.

**Legacy references:**
- Existing React Top10 bundle (locate in WP plugin/theme). **TODO:** user to point to exact path before phase starts.

---

#### Implementation pattern (from Bossa Nova Chords migration)

**Phase 8 refactoring (April 25, 2026)**

Before implementing additional Top10 pages, the initial Bossa Nova Chords implementation was refactored to address two structural issues:

**Issue A — Duplicate serializeChord with missing transposition**
- Problem: `Top10Controller` carried its own copy of `serializeChord` forked from `ChordLibraryController` before Phase 7 fixes
- Impact: Transposable shapes would silently render wrong frets (no `$rootOverride`, no `calculateFrets`)
- Fix: Extracted `ChordSerializer` service with the Phase 7 version (includes transposition)
- Both `Top10Controller` and `ChordLibraryController` now inject and use `ChordSerializer::serialize()`

**Issue B — Hardcoded content in controller**
- Problem: 190-line `$chordConfig` array hardcoded in controller method
- Impact: Each new Top10 page would be another 200-line PHP addition, mixing content with code
- Fix: Moved config to `config/top10/bossa-nova-chords.php`
- Controller now loads via `require config_path('top10/bossa-nova-chords.php')`
- Future Top10 pages will be new config files, not new controller methods

**Additional fixes:**
- Swapped internal `<a href>` to Inertia `<Link>` for SPA pattern (footer cross-links, related product links)
- CSS extraction deferred until second Top10 page lands to identify genuinely shared patterns

**Config loading note for next Top10 page:**
Current implementation uses `require config_path('top10/bossa-nova-chords.php')`. For consistency with Laravel conventions, consider switching to `config('top10.bossa-nova-chords')` for the next Top10 page. Laravel auto-loads everything under `config/` by directory + filename, so this works with no additional wiring and enables `php artisan config:cache` to pre-compile the file if ever needed.

---

**1. Controller setup (Top10Controller.php)**

```php
public function __construct(
    private ChordSerializer $chordSerializer
) {}

public function bossaNovaChords()
{
    $chordConfig = require config_path('top10/bossa-nova-chords.php');
    // OR for Laravel config caching (next page): config('top10.bossa-nova-chords');

    $allSlugs = array_keys($chordConfig);
    $allProgressionSlugs = collect($chordConfig)->flatMap(fn ($c) => $c['progressionSlugs'])->unique()->toArray();
    $allRequiredSlugs = array_unique(array_merge($allSlugs, $allProgressionSlugs));

    $chords = ChordDiagram::whereIn('slug', $allRequiredSlugs)
        ->get()
        ->map(fn ($c) => $this->chordSerializer->serialize($c))
        ->keyBy('slug');

    $top10Data = collect($chordConfig)->map(function ($config, $slug) use ($chords) {
        $progressionTiles = collect($config['progressionSlugs'])->map(function ($progressionSlug) use ($chords) {
            $tileData = $chords[$progressionSlug] ?? null;
            return [
                'chordName' => $tileData['name'] ?? $progressionSlug,
                'diagramData' => $tileData,
            ];
        })->toArray();

        return array_merge($config, [
            'id' => null, // Assigned by Vue
            'slug' => $slug,
            'voicingData' => $chords[$slug] ?? null,
            'progressionTiles' => $progressionTiles,
        ]);
    })->values()->toArray();

    return Inertia::render('Top10/BossaNovaChords', [
        'top10Data' => $top10Data,
    ]);
}
```

**Config file structure (config/top10/bossa-nova-chords.php):**

```php
return [
    'chord-slug' => [
        'title' => 'The Major 6/9 Chord',
        'shortTitle' => 'Major 6/9',
        'chordName' => 'Db6/9/Ab',  // Full legacy chord name for nav
        'image' => '/images/top10/bossa-chords/1.jpg',
        'description' => '...',
        'voicingCaption' => '...',
        'progressionName' => 'Trademark Progression',
        'progressionCaption' => '...',
        'progressionSlugs' => ['slug1', 'slug2', ...],
        'relatedProducts' => [
            [
                'title' => 'Product Name',
                'description' => '...',
                'url' => '/shop/product/...',
                'type' => 'product',
            ],
        ],
    ],
    // ... 10 items total
];
```

**Key points:**
- Use actual `ChordDiagram` slugs for voicings and progression tiles
- Serialize chords via `ChordSerializer` service (shared with ChordLibraryController)
- Content lives in config files, not controller methods
- Include `relatedProducts` array for each chord
- Pass `top10Data` to Vue via Inertia

**2. Vue component structure (BossaNovaChords.vue)**

```typescript
interface Top10Chord {
    id: number;
    title: string;          // Full title for detail view
    shortTitle: string;    // Short title for alt text
    chordName: string;      // Legacy chord name (optional, for nav if needed)
    image: string;
    description: string;
    slug: string;
}

interface Top10ChordWithDetail extends Top10Chord {
    voicingData: ChordDiagramData | null;
    progressionTiles: Array<{ chordName: string; diagramData: ChordDiagramData | null }>;
    voicingCaption: string;
    progressionName: string;
    progressionCaption: string;
    relatedProducts: RelatedProduct[];
}

interface RelatedProduct {
    title: string;
    description: string;
    url: string;
    type: string;  // 'product' or 'course'
}

const props = defineProps<{
    top10Data: Top10DataItem[];
}>();

const chords = ref<Top10ChordWithDetail[]>([]);
const selectedChord = ref<Top10ChordWithDetail | null>(null);

function loadChords() {
    chords.value = props.top10Data.map((item, index) => ({
        id: index + 1,
        title: item.title,
        shortTitle: item.shortTitle,
        chordName: item.chordName,
        image: item.image,
        description: item.description,
        slug: item.slug,
        voicingData: item.voicingData,
        progressionTiles: item.progressionTiles,
        voicingCaption: item.voicingCaption,
        progressionName: item.progressionName,
        progressionCaption: item.progressionCaption,
        relatedProducts: item.relatedProducts,
    }));
    selectedChord.value = chords.value[0] || null;
}
```

**3. Template structure**

```vue
<template>
    <div class="sbn-top10-page">
        <!-- Mobile Navigation -->
        <div class="sbn-top10-nav-mobile">
            <div class="sbn-nav-scroll">
                <button
                    v-for="chord in chords"
                    :key="chord.id"
                    @click="selectChord(chord)"
                    class="sbn-nav-thumb"
                    :class="{ 'sbn-nav-thumb--active': selectedChord?.id === chord.id }"
                >
                    <div class="sbn-nav-thumb-image">
                        <img :src="chord.image" :alt="chord.shortTitle" />
                        <div class="sbn-nav-thumb-number">{{ chord.id }}</div>
                    </div>
                    <div class="sbn-nav-thumb-title">{{ chord.title }}</div>
                </button>
            </div>
        </div>

        <!-- Desktop Navigation -->
        <div class="sbn-top10-nav-desktop">
            <button
                v-for="chord in chords"
                :key="chord.id"
                @click="selectChord(chord)"
                class="sbn-nav-card"
                :class="{ 'sbn-nav-card--active': selectedChord?.id === chord.id }"
            >
                <div class="sbn-nav-card-image">
                    <img :src="chord.image" :alt="chord.shortTitle" />
                    <div class="sbn-nav-card-number">{{ chord.id }}</div>
                </div>
                <div class="sbn-nav-card-title">{{ chord.title }}</div>
            </button>
        </div>

        <!-- Detail View -->
        <div v-if="selectedChord" class="sbn-top10-detail">
            <div class="sbn-detail-header">
                <span class="sbn-detail-badge">CHORD #{{ selectedChord.id }}</span>
                <h1 class="sbn-detail-title">{{ selectedChord.title }}</h1>
                <p class="sbn-detail-description">{{ selectedChord.description }}</p>
            </div>

            <!-- Panels Grid (side-by-side on desktop) -->
            <div class="sbn-panels-grid">
                <div v-if="selectedChord.voicingData" class="sbn-panel">
                    <h3 class="sbn-panel-title">Chord Voicing</h3>
                    <div class="sbn-panel-content">
                        <ChordCard :chord="selectedChord.voicingData" :show-root="true" />
                        <p class="sbn-panel-caption">{{ selectedChord.voicingCaption }}</p>
                    </div>
                </div>

                <div class="sbn-panel">
                    <h3 class="sbn-panel-title">{{ selectedChord.progressionName }}</h3>
                    <div class="sbn-panel-content">
                        <ChordProgressionViewer
                            :tiles="selectedChord.progressionTiles.map(t => ({ chordName: t.chordName, diagramData: t.diagramData }))"
                            :interactive="true"
                        />
                        <p class="sbn-panel-caption">{{ selectedChord.progressionCaption }}</p>
                    </div>
                </div>
            </div>

            <!-- Navigation Buttons -->
            <div class="sbn-detail-nav">
                <button @click="prevChord" class="sbn-nav-btn">← Previous</button>
                <button @click="nextChord" class="sbn-nav-btn">Next →</button>
            </div>

            <!-- Related Products -->
            <div v-if="selectedChord.relatedProducts && selectedChord.relatedProducts.length > 0" class="sbn-related-products">
                <h3 class="sbn-related-title">Related Products & Courses</h3>
                <div class="sbn-related-list">
                    <Link
                        v-for="(product, index) in selectedChord.relatedProducts"
                        :key="index"
                        :href="product.url"
                        class="sbn-card-link sbn-related-item"
                    >
                        <div class="sbn-related-content">
                            <div class="sbn-related-header">
                                <span class="sbn-related-badge" :class="`sbn-related-badge--${product.type}`">{{ product.type }}</span>
                            </div>
                            <h4 class="sbn-related-name">{{ product.title }}</h4>
                            <p class="sbn-related-desc">{{ product.description }}</p>
                        </div>
                        <div class="sbn-related-arrow">→</div>
                    </Link>
                </div>
            </div>

            <!-- Footer Links -->
            <div class="sbn-footer-links">
                <Link href="/top10/bossa-nova-chords" class="sbn-footer-link">Top10 Bossa Nova Chords</Link>
                <span class="sbn-footer-separator">•</span>
                <Link href="/top10/latin-jazz-standards" class="sbn-footer-link sbn-footer-link--active">Top10 Latin Jazz Standards</Link>
                <span class="sbn-footer-separator">•</span>
                <Link href="/top10/bossa-nova-songs" class="sbn-footer-link">Top10 Bossa Nova Songs</Link>
            </div>
        </div>
    </div>
</template>
```

**4. Styling approach**

**Design system additions (sbn-design-system.css):**
```css
/* Content panel — gray background box for grouped content */
.sbn-panel {
    background:    var(--clr-surface-2);
    border:        1px solid var(--clr-border);
    border-radius: var(--radius);
    padding:       20px;
}

/* Card link — clickable card with hover effect (for related products, etc.) */
.sbn-card-link {
    display:         flex;
    align-items:     center;
    justify-content: space-between;
    padding:         16px;
    background:      var(--clr-surface-2);
    border:          1px solid var(--clr-border);
    border-radius:   var(--radius);
    text-decoration: none;
    transition:      all 0.2s var(--ease);
}

.sbn-card-link:hover {
    background:   var(--clr-surface-3);
    border-color: var(--clr-accent);
}
```

**Scoped styles in Vue (Top10-specific only):**
- Navigation layout (mobile scroll, desktop grid)
- Nav thumb/card sizing (100px mobile, 140px desktop)
- Nav number badges (grey for non-selected, accent for selected)
- Panels grid layout (3fr 7fr split on desktop)
- Panel content layout (flex column, centered)
- Detail view typography
- Navigation buttons (gradient, hover effects)
- Related products layout
- Footer links layout

**Use design system variables for:**
- Colors: `var(--clr-surface-2)`, `var(--clr-border)`, `var(--clr-accent)`, `var(--clr-text)`, `var(--clr-text-muted)`
- Spacing: `var(--radius)`, `var(--radius-sm)`
- Gradients: `var(--clr-gradient)`

**5. Component reuse**
- `ChordCard` - for voicing display with `show-root` prop
- `ChordProgressionViewer` - for progression tiles with `interactive` prop
- `PublicLayout` - wraps the page with header, footer, mega menu

**6. Route setup (web.php)**
```php
Route::get('/top10/bossa-nova-chords', [Top10Controller::class, 'bossaNovaChords'])
    ->name('top10.bossa-nova-chords');
```

**Done:**
- At least one Top10 page live and indexable.
- SEO meta (title, description, og tags) via Inertia head manager.
- Parity with WP React version for interactive behavior.
- Styling centralized in design system + Top10-specific scoped styles.
- Navigation works (mobile scroll, desktop grid, prev/next buttons).
- Related products section displays.
- Footer links section displays.

---

### Phase 9 — Leadsheet Viewer ✅ DONE (April 27, 2026)

**Full reference:** [`docs/Phase-9-Leadsheet-Viewer.md`](Phase-9-Leadsheet-Viewer.md) — unified doc covering both Phase 9 (Classic Viewer) and Phase 9b (Tab Viewer).

**Deliverable:** Public leadsheet viewer with three rendering modes (`No chords` / `Chords` / `Tab`), chord-aware education sidebar, and design-system-polished transport bar. Song catalog from Phase 6 links into the viewer; admin tab editor remains the source of truth for data entry.

#### What was built

- **Routing & Viewers:** Implemented `/library/songs/{leadsheet:slug}/viewer`.
- **Read-Only Engine Wrappers:** Built `LeadsheetViewer.vue` context frames.
- **Unified SVG Models:** Accommodated progression/rhythmic previews.

**Component inventory:**
- **[NEW]** `Pages/Leadsheet/Classic.vue` — public-facing wrapper page
- **[REUSE/CONVERT]** `ChordMeasure.vue`, `ChordGridView.vue`, `ChordSection.vue` — chord grid rendering
- **[REUSE/CONVERT]** `TabMeasure.vue`, `TabCursor.vue` — tab rendering (if song has tab)
- **[REUSE/CONVERT]** `TransportBar.vue` — playback controls
- **[REUSE]** `useTabModel`, `useReflow`, `useChordGridOps`, `useCursor`, `useSelection` composables
- **[REUSE]** full `AudioEngine` stack
- **[NEW]** `Components/Leadsheet/EduPanel.vue` — teaching-content sidebar; replaces old context panel
- **[NEW]** View toggle UI (placeholder link to cinema view, disabled until Phase 10)

**Note:** the heavy Vue work already exists from the tab editor. This phase is mostly wrapping those components in a public-facing page + building the new `EduPanel`.

**Legacy references:**
- `sbn-course-player(legacywp)/inc/leadsheet/` — leadsheet renderer + core logic
- `sbn-course-player(legacywp)/inc/song-page.php` — song page template
- `sbn-course-player(legacywp)/inc/sheet-players.php` — embedded player logic
- `sbn-course-player(legacywp)/inc/chord-grid.php` — chord grid (context panel source)
- `sbn-course-player(legacywp)/inc/alphatex-shortcode.php` — alphaTex notation (if reused)
- `sbn-course-player(legacywp)/flavor-starter/flavor-starter/page-repertoire.php` — repertoire page template
- `sbn-course-player(legacywp)/flavor-starter/flavor-starter/repertoire-quiz.php` — repertoire quiz (defer to later phase if not essential)
- `sbn-course-player(legacywp)/assets/css/leadsheet.css` — leadsheet styles
- `sbn-course-player(legacywp)/assets/css/song-page.css` — song page styles
- `sbn-course-player(legacywp)/assets/css/sheet-player.css` — player styles
- `sbn-course-player(legacywp)/assets/js/leadsheet.js` — leadsheet interactions
- `sbn-course-player(legacywp)/assets/js/sheet-player.js` — sheet player interactions

**Leadsheet JSON data structure** — the contract between the admin editor and the public viewer:

```json
{
  "title": "string",
  "composer": "string",
  "key": "C",
  "tempo": 120,
  "timeSignature": "4/4",
  "melody": "...MusicXML string...",
  "sections": [
    {
      "id": "section-uuid",
      "name": "A",
      "lineBreaks": [4, 8],
      "measures": [
        {
          "index": 0,
          "chordNames": ["Cmaj7", "Am7"],
          "chordOffsets": [0, 2],
          "chordBeats": [2, 2],
          "repeatStart": false,
          "repeatEnd": false,
          "volta": null
        }
      ]
    }
  ],
  "chordVoicings": {
    "Cmaj7@0.0": { "frets": "x32000", "fingers": "...", "position": 0 }
  },
  "repeatMarkers": [],
  "voltaEndings": [],
  "videoSync": {
    "videoId": "...",
    "videoType": "youtube",
    "audioSource": "synth",
    "mappings": [{ "measureIndex": 0, "videoTime": 4.5 }]
  }
}
```

Field notes:
- `chordOffsets[i]` — beat offset of chord i from measure start (quarter beats, 0-based)
- `chordBeats[i]` — duration in quarter beats; parallel array with `chordNames`
- `chordVoicings` keys: `"chordName@globalMeasureIndex.chordIndex"`
- `videoSync.audioSource` — `'synth'` or `'video'`; determines which clock drives playback
- `melody` — full MusicXML string; null if no tab data entered

**Note on Alpine chord grid (for public viewer):** The original fully-featured Alpine grid (drag-to-reorder, context menu, voicing picker) exists intact in commit `dd1c739`. The old shape used `chord.name`/`chord.beats` objects per measure; current `json_data` uses parallel `chordNames[]`/`chordOffsets[]`/`chordBeats[]` arrays. Build the public viewer against the **current shape**.

**CSS extraction needed before Phase 9:** Tab SVG classes (`.sbn-tab-note-text`, `.sbn-tab-metronome-col`, `.sbn-beat-active`, etc.) currently live in `leadsheets.css` (admin-only). Move them to `sbn-design-system.css` before Phase 9 so the public viewer can use them without importing admin CSS.

**Done:**
- Existing songs render correctly in chord and tab modes.
- 3-way mode toggle (No chords / Chords / Tab) with localStorage persistence; Tab button gated on whether the song has melody data.
- Edu panel surfaces contextual teaching content (chord theory, progressions) tied to current selection across all modes.
- Audio playback works in both chord and tab paths, with smooth mode-swap mid-playback.
- Cinema-view toggle placeholder present (disabled until Phase 10).

---

### Phase 10 — Leadsheet Cinema View

**Deliverable:** Alternate leadsheet layout optimized for video-sync learning ("Stage View"). Full reload on toggle between classic and cinema is acceptable.

**Spec:** Stage View, from the Claude Design handoff bundle at [`docs/cinema-design/sbn-teaching-hub-design-system/project/Option 2 - Stage View.html`](cinema-design/sbn-teaching-hub-design-system/project/Option%202%20-%20Stage%20View.html). Read that file end-to-end before implementing — it is the single source of truth for layout, sizing, and palette. Shared assets in `cinema-design/.../shared/` (`song.js`, `diagrams.js`) are illustrative; do **not** port their `sbnFormatChord` (we already have `sbn-chord-name.js`) or their toy `setInterval` clock (drive from video time — see ground rules).

**Layout (top-to-bottom):**
1. **Top bar** — italic-serif song title, composer/year, divider, three meta chips (key / time-sig / bar-count), spacer, three icon buttons (transpose, metronome, settings).
2. **Hero row** — 1.55fr / 1fr split:
   - **Left:** 16:9 video card with animated "live" border glow, top-left "Lesson · Synced" badge, bottom sync strip showing `currentTime / duration` and bar-sync indicator.
   - **Right:** "Now Playing" plinth — pulsing accent dot, bar/section label, **128px chord glyph** (orange glow), Roman-numeral sub-line, dashed-rule "Next →" row with chord + beat countdown, 180px neon chord diagram, 4-beat pulse row pinned to bottom.
3. **Transport deck** — prev / play (orange) / next, position counter, section-tinted clickable progress track, three toggles (Count / Loop / Click). **No BPM input** (see ground rule 1).
4. **Sections grid** — 4 columns, one per song section. Section letter chip + italic name + vertical bar list. Active bar = accent gradient + left border; past bars dim to 0.4. Multi-chord bars render side by side. Each row has a tiny neon chord diagram.

**Ground rules (locked):**
1. **Beat clock = video time.** Bar/beat position is derived from the video's `currentTime` via `useVideoSync` and the leadsheet's `tempo` field. The prototype's `setInterval` driver is for the toy demo only. **Drop the BPM input** from the deck.
2. **Dark theme only for Phase 10.** The prototype ships 4 themes (dark / light / noir / ivory). Implement dark only; keep the CSS variable structure (`--bg`, `--accent`, etc.) so additional themes can be added later without refactor, but do not expose a theme picker.
3. **Drop the tweaks panel entirely** — it's design-tool plumbing, not a product feature. No `[data-theme]` switching UI; no `localStorage` theme persistence; no `postMessage` host integration.
4. **Chord names use the existing system.** `sbn-chord-name.js` + `chord-symbols.css` are authoritative. Do not reimplement `sbnFormatChord`.
5. **Roman numerals / function labels come from `HarmonicContext`** when available; fall back to `—`. Do not hardcode the prototype's tiny `ROMAN` / `FN` lookup tables.

**Component inventory (final):**
- **[NEW]** `Pages/Leadsheet/Cinema.vue` — page-level orchestration; owns video-time state and derives current bar/beat
- **[NEW]** `Components/Cinema/StageTopBar.vue` — title, meta chips, icon buttons
- **[NEW]** `Components/Cinema/StageHeroNow.vue` — now/next plinth (chord glyph, Roman, next-up countdown, beat row, diagram)
- **[NEW]** `Components/Cinema/StageTransportDeck.vue` — transport buttons, position counter, section-tinted progress track, Count/Loop/Click toggles
- **[NEW]** `Components/Cinema/StageSectionsGrid.vue` — 4-column section browser with active/past bar states
- **[NEW]** `Components/ChordDiagram/NeonChordDiagram.vue` — neon SVG variant (or add a `variant="neon"` prop to the existing chord diagram component, whichever fits the existing API)
- **[REUSE]** `VideoPlayer.vue`, `useVideoSync` composable, `SyncPointBadge.vue` (from Phase D)
- **[REUSE]** existing chord-name component / `sbn-chord-name.js` formatter
- **[NEW CSS]** Stage palette tokens (`--bg`, `--bg-1..3`, `--line`, `--line-2`, `--text-dim`, `--text-mute`, `--accent`, `--accent-2`, `--accent-rgb`, `--scrim-1/2`, `--primary-ink`) added to `sbn-design-system.css`, scoped under a `.leadsheet-stage` (or equivalent) selector so they don't leak into the rest of the app

**Legacy references:**
- No direct WP equivalent — cinema view is a new design. Reuse Phase 9 leadsheet components; video sync machinery comes from the Laravel Phase D work, not from the legacy theme.

**Implementation notes:**
- `Cinema.vue` normalizes raw `parsed_data` measure format (`chords[].name`) into `chordNames[]` + sequential `globalIndex` before passing to child components. Both the flat bar list and the sections prop go through `normalizeMeasure()`.
- Stage palette tokens (`--stage-bg`, `--stage-accent`, etc.) are scoped under `.leadsheet-stage` — no leakage.
- Video sync wiring is structural but not yet tested end-to-end (deferred — wire when a synced lesson video exists). The fallback interval clock runs when no video is attached.
- `VideoPlayer.vue` and `useVideoSync` are imported but video-master playback (video drives bar position) is pending real sync data. Current toggle just flips `playing` state.

**Done:**
- **[DONE]** Route `/library/songs/{slug}/cinema` → `SongLibraryController::cinema()` → `Leadsheet/Cinema.vue`.
- **[DONE]** Cinema view renders: top bar, hero (video placeholder + Now Playing plinth), transport deck, sections grid.
- **[DONE]** Toggle from classic view works (Cinema link in LeadsheetViewer header).
- **[DONE]** Chord names display in hero and sections grid via `sbn-chord-name.js` / `formatChordHtml`.
- **[DONE]** Sections grid: active bar highlighted, past bars dimmed, click-to-seek works.
- **[DONE]** Transport: prev/play/next, progress track scrub, Count/Loop/Click toggles, keyboard (space / ←/→).
- **[DONE]** Dark theme only; no tweaks panel; no theme picker; no BPM input.
- **[DONE]** Roman numerals from `HarmonicContext` when available, fall back to `—`.
- **[DONE]** Neon chord diagrams in sections grid and Now Playing plinth (180px) via `NeonChordDiagram.vue`.
- **[TODO]** Video-master sync: wire VideoPlayer `timeupdate` → `onVideoTimeUpdate` → bar/beat position when a song has `jsonData.videoSync` mappings. Deferred until a synced lesson is available to test against.

---

### Phase 11 — Course Player ✅ DONE (2026-05-07)

**What shipped:** Course/Lesson data model, WXR importer, public Inertia player ([Pages/Courses/Player.vue](../resources/js/Pages/Courses/Player.vue) + sidebar/content/bottom-bar components), course-player.css.

**Reference:** Architecture, components, and data model are documented in [SBN-Course-Reference.md](SBN-Course-Reference.md). This entry exists only to record that the work happened in this phase.

---

### Phase 11b — Course Backend Editor ✅ DONE (2026-05-08)

**What shipped:**
- Admin CRUD: `Admin\CourseController`, `Admin\LessonController`, course/lesson Blade views, drag-reorder, soft-publish.
- Public-player runtime: 4 JSON endpoints (`/api/sbn/{chords|rhythms|progressions|songs}`), [`mountSbnNodes.ts`](../resources/js/lib/mountSbnNodes.ts) with per-type prop adapter and fetch cache.
- TipTap editor with SBN chip nodes (chord/rhythm/progression/song), right-side palette with per-type config (chord `root`, progression `key`, song `label`), slash command, Ctrl+Shift shortcuts, image upload, hidden-textarea sync.
- Server-side chord transposition via `shapeCalculator->calculateFrets()` (`?root=`).
- Server-side progression tile-building via existing builder (`?key=`).
- `<sbn-song>` renders as a styled link to the leadsheet viewer (no embed).

**Reference:** [SBN-Course-Reference.md](SBN-Course-Reference.md). Tag attrs, JSON endpoint shapes, mount runtime contract, and admin editor surface are all documented there. Update that doc when behavior changes.

**Open work** (see Reference §8): mini tab viewer (`<sbn-tab>`), drag-drop with extras, modal chip editing for non-slug attrs, server-side self-closing-tag normalization.

---

### Phase 12 — Auth + Payments

Split into **12a (Auth, ✅ DONE 2026-06-03)** and **12b (Lemon Squeezy payments, NEXT)**.

---

### Phase 12a — Inertia Auth Scaffolding ✅ DONE (2026-06-03)

**Deliverable:** All public auth UI on Inertia + Vue (no Blade-island auth remaining); login converted, register + password-reset flows built. Hand-rolled to match the codebase — NO Fortify/Breeze/Jetstream.

#### What was built

**Prerequisite fix**
- `database/migrations/2026_06_03_000001_create_password_reset_tokens_table.php` — the `password_reset_tokens` table was referenced by `config/auth.php` but no migration created it (the schema is not fully migration-defined; the table lived only in the committed `sbn.db`). Added with a `hasTable` guard.

**Controllers (`app/Http/Controllers/Auth/`)**
- `LoginController` — `show()` now returns `Inertia::render('Auth/Login')` (was `view('auth.login')`); `login()`/`logout()`/`landingFor()` unchanged. Inertia surfaces `withErrors(['email'=>...])` as `form.errors.email`.
- `RegisterController` (new) — validates name/email(unique)/password(`confirmed` + `Password::defaults()`); `User::create` (the model's `password => 'hashed'` cast hashes); `Auth::login` + `session()->regenerate()`; redirect `account.dashboard`.
- `PasswordResetLinkController` (new) — forgot-password; `Password::sendResetLink`, flashes `status` or `withErrors`.
- `NewPasswordController` (new) — reset; `Password::reset` with `forceFill(['password'=>$pw])` (hashed on save).

**Routes (`routes/web.php`)** — guest-only group wrapping `/login`, `/register`, `/forgot-password`, `/reset-password/{token}` (names `password.request`/`password.email`/`password.reset`/`password.update` as Laravel's broker + `ResetPassword` notification expect); `/logout` stays outside the group.

**Inertia pages (`resources/js/Pages/Auth/`)** — `Login.vue`, `Register.vue`, `ForgotPassword.vue`, `ResetPassword.vue`, all on `PublicLayout`, `useForm`, design-system tokens (`sbn-input`/`sbn-label`/`sbn-field-error`/`sbn-btn-primary`). Shared card frame extracted to `resources/js/Components/Auth/AuthCard.vue` (logo + heading + form-helper styles, kept DRY across the 4 pages). `UserMenu.vue` gained an Inertia `<Link>` "Sign up" beside "Log in".

**Removed** — `resources/views/auth/login.blade.php` (only reference was in LoginController).

**Tests** — `tests/Feature/AuthTest.php`, 11 passing. Runs against the real `sbn.db` connection with per-test cleanup (same approach as `LeadsheetLookupTest` — `RefreshDatabase`/`:memory:` is unusable here because `sbn_leadsheets` has no create-migration). Covers: page render (Inertia component assert), register→login→redirect, role-based login redirect (customer→account, instructor→admin), bad-credential error, guest-redirect-away-from-login, forgot-password notification (`Notification::fake`), token reset, logout.

#### Gotchas recorded
- **Route cache was stale** — `php artisan route:clear` was needed; the cached `routes-*.php` predated the new auth routes, so register/password 404'd until cleared. Re-cache (`route:cache`) only after deploy.
- **`is_instructor` is NOT mass-assignable** on `User` — tests set it via `forceFill`. Don't add it to `$fillable`.
- **`guest` middleware redirects authenticated users to `/` (framework HOME)** before `LoginController::show` runs — that's why the "already logged in" redirect goes to `/`, not `account.dashboard`.

#### Out of scope (→ 12b)
Lemon Squeezy, checkout-stub replacement, webhooks, `Course`↔LS variant column, customer portal link.

---

### Phase 12b — Payments (Lemon Squeezy)

**Deliverable:** Lemon Squeezy wired to shop and course purchases, course access gated by the `course_user` ownership pivot from the Customer Backend phase.

**Provider decision (2026-05-28):** **Lemon Squeezy** as Merchant of Record. They collect, file, and remit EU VAT, US sales tax, and GST on our behalf — a meaningful win for a single-operator EU business selling globally to a low-ticket digital catalog. Cost is ~2% more than Stripe (~5% + 50¢ vs Stripe's ~2.9% + 30¢) in exchange for eliminating tax registration/filing entirely. Revisit Stripe if/when a subscription product with proration or seat-based billing lands.

**Prerequisite:** Customer backend + community (phases A–E) shipped 2026-05-28; as-built reference: [SBN-Customer-Reference.md](SBN-Customer-Reference.md). It defines the `course_user` pivot, the manual-grant admin tool, `CourseAccessService::grantPurchase()` (the LS webhook entry point), and the empty-state UX that this phase activates.

**Scope:**
- Install `lmsqueezy/laravel` (Laravel Cashier-style adapter).
- Replace Phase 2 shop checkout stub: redirect cart → Lemon Squeezy hosted checkout, return on success.
- Webhook handler `order_created` / `order_refunded` → flip `sbn_orders.status` from `pending_stub` to `paid` / `refunded`, write `course_user` row with `source='purchase'` and the `order_id`.
- Wire course purchase flow: each `Course` ↔ LS product/variant ID column on `sbn_courses`.
- Auth pages (Inertia versions of Laravel's auth scaffolding).
- Customer portal link in the Account area (LS-hosted; subscription/license management).

**Explicitly NOT in scope:**
- Tax calculation — handled by LS as MoR. No Stripe Tax / Taxually / Quaderno wiring.
- Subscriptions with proration / seat-based billing — defer until a recurring product exists.
- Custom invoice generation — LS issues VAT-compliant invoices; we surface the link.

**Legacy references:**
- `sbn-course-player(legacywp)/flavor-starter/flavor-starter/functions.php` — theme hooks (auth / redirects to review)
- WP user DB — out of scope of this repo; migration handled separately, referenced here only as the source for user re-onboarding.

**Done:**
- Real payment flow works end-to-end against Lemon Squeezy test-mode store.
- `course_user.source='purchase'` rows land via webhook, and the Account → My Courses page shows the purchased course without manual grant.
- Course access correctly gated via existing `User::owns()` (no new gate logic — the Customer Backend already shipped it).
- Refund webhook removes the `course_user` row (or sets `expires_at = now()` — TBD per UX).
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
| Cinema view spec missing until Phase 10 | Placeholder noted; do not start Phase 10 until design exported from Claude design app. |
| Admin accidentally migrated | Scope discipline: Inertia pages live only under `resources/js/Pages/`. Admin routes continue to return Blade views. |
| SEO regression on Top10 (high-traffic pages) | Inertia head manager for meta tags; validate with crawler before DNS cutover. Consider SSR if needed (Phase 5 decision). |
| Payment-provider/auth rework at end blocking launch | Stubs earlier let us ship internally without real payments. Phase 12 is strictly about wiring Lemon Squeezy (MoR — no tax wiring needed), not UX design. |
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
