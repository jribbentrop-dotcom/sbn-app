# Frontend Migration Plan

> **Status: MIGRATION COMPLETE.** All phases 0–12b shipped. This document is a
> **build diary** preserved for historical context. All as-built facts, component
> contracts, gotchas, and architecture decisions have been harvested into the
> reference docs listed below. Do not use this file as a reference — use those docs.

---

## Reference Docs (authoritative)

| Topic | Reference |
|---|---|
| Design system, CSS tokens, fonts | `SBN-Design-Reference.md` |
| Chord library (index, Show, transposition, dim7, enharmonics) | `SBN-Chord-Library-Reference.md` |
| Rhythm library (RhythmStrip, RhythmPattern, audio pipeline) | `SBN-Rhythm-Reference.md` |
| Progression library (ChordProgressionViewer, tile resolution) | `SBN-Progression-Library-Reference.md` |
| Song library (index, Show, viewer, cinema, SongLink) | `SBN-Song-Library-Reference.md` |
| Shop + Top10 pages | `SBN-Shop-Top10-Reference.md` |
| Leadsheet viewer (classic + cinema) | `SBN-Leadsheet-Reference.md` |
| Course player + admin editor + sbn-* tags | `SBN-Course-Reference.md` |
| Auth (login/register/password reset) + Payments (webhook, provider seam) | `SBN-Customer-Reference.md §11–12` |
| Customer area, messaging, community, Reverb | `SBN-Customer-Reference.md` |
| Admin chord/tab editor | `SBN-Admin-Chord-Tab-Editor-Reference.md` |
| Edu content system | `SBN-Edu-Reference.md` |
| Fretboard system | `SBN-Fretboard-Reference.md` |
| Audio engine | `SBN-Audio-Reference.md` |
| Chord identifier | `SBN-Identifier-Reference.md` |

---

## 1. Tech Stack Decision

**Backend (unchanged):** Laravel + Eloquent + Blade (admin only) + existing queue/mail/auth.

**Frontend:** Laravel + **Inertia** + **Vue 3** + **TypeScript** + **Tailwind CSS**.

### Rationale

- **Keep Laravel:** course system, auth, payments, admin, and DB logic all stay where they already work. No rewrite of backend.
- **Inertia over Blade-islands:** gives SPA-like navigation, persistent layouts (audio player, nav, auth state), and one mental model per page — no Blade/Vue split inside a page. Critical for the course player and interactive libraries.
- **Vue 3 over React:** we already have substantial Vue investment (TabEditor, ChordMeasure, rhythm components). Vue 3 Composition API + `<script setup>` is modern and well-supported.
- **TypeScript from day 1:** types guide both human and AI authors; fewer runtime surprises; better editor tooling.
- **Tailwind:** layout/spacing/utility only. Brand colors stay in CSS vars (`sbn-design-system.css`).
- **Blade stays for admin:** admin already works. Don't fix what isn't broken.

### What we are NOT doing

- Not migrating to Next.js / React / separate SPA + API.
- Not converting admin to Inertia. Admin routes return Blade views and will stay that way.

---

## 2. Phase Order (all complete)

| # | Phase | Reference |
|---|---|---|
| 0 | ✅ Stack scaffolding — Inertia + TS + Tailwind, base layout, shared props | `SBN-Design-Reference.md` |
| 1 | ✅ App shell — mega menu, footer, layout slots, persistent audio mount, auth in shared props | `SBN-Design-Reference.md` |
| 2 | ✅ Shop — catalog, product pages, cart, checkout, downloads | `SBN-Shop-Top10-Reference.md` |
| 2.5 | ✅ CSS polish + design system consolidation | `SBN-Design-Reference.md` |
| 3 | ✅ Chord library + ChordDiagram + transposition search | `SBN-Chord-Library-Reference.md` |
| 4 | ✅ Rhythm library + RhythmPattern + audio engine work | `SBN-Rhythm-Reference.md` |
| 5 | ✅ Progression library — Index + Show | `SBN-Progression-Library-Reference.md` |
| 6 | ✅ Songs library browse + slug on `sbn_leadsheets` | `SBN-Song-Library-Reference.md` |
| 7 | ✅ Wire detail pages — ChordProgressionViewer, chord-card audio, cross-refs, engine fixes | `SBN-Progression-Library-Reference.md`, `SBN-Chord-Library-Reference.md` |
| 7.5 | ✅ Progression UI standardization — unified resolution pipeline | `SBN-Progression-Library-Reference.md` |
| 7.6 | ✅ Guide-tone display — interval dots + VL arrows in ChordProgressionViewer | `SBN-Progression-Library-Reference.md §6` |
| 8 | ✅ Top10 pages — config-driven, ChordProgressionViewer composition | `SBN-Shop-Top10-Reference.md` |
| 9 | ✅ Leadsheet viewer — classic view + edu panel | `SBN-Leadsheet-Reference.md` |
| 10 | ✅ Leadsheet cinema view | `SBN-Leadsheet-Reference.md` |
| 11 | ✅ Course player | `SBN-Course-Reference.md` |
| 11b | ✅ Course backend editor — TipTap + sbn-* tags + JSON API | `SBN-Course-Reference.md` |
| 12a | ✅ Auth — login/register/password reset, Inertia pages | `SBN-Customer-Reference.md §11` |
| 12b | ✅ Payments — provider seam, Stripe Managed, webhook, FakeProvider | `SBN-Customer-Reference.md §12` |

### Why this order (rationale preserved)

- Shell first so every later phase has a real frame to drop into.
- Shop as a low-stakes warmup to the new stack.
- Chord → rhythm → progressions builds atomic components bottom-up.
- Songs library before detail-wiring so cross-links have targets.
- Dedicated wiring phase (7) to avoid scattering cross-ref TODOs across earlier phases.
- Top10 is pure composition once 3/4/5/6 are stable.
- Leadsheet viewer held until all its component dependencies exist.
- Auth + payments last so feature development wasn't blocked on payment plumbing.

---

## 3. Architectural Decisions (permanent)

These decisions are made and closed. Do not revisit without a strong reason.

- **Admin stays Blade.** Inertia pages live only under `resources/js/Pages/`. Admin routes return Blade views.
- **No Pinia.** Cart = `useCart.ts` (localStorage). Chat = `useChat.ts`. Course progress = component-local refs. If state genuinely outgrows composables, revisit then.
- **No server-side search on library indexes.** Client-side substring filter over initial payload for chord/rhythm/progression/song libraries.
- **Currency: always charge EUR.** USD is display-only via `SHOP_USD_RATE`.
- **No duplicated components.** When a component moves out of `tab-editor/`, admin imports update in the same commit. One file, imported by both.
- **Brand colors stay in CSS vars.** Tailwind is utility/layout only. `var(--clr-accent)` etc. are the source of truth.
- **Payment provider is abstract.** `PaymentProvider` interface; concrete MoR (Stripe Managed / Paddle) is a late, reversible pick.

---

## 4. Legacy Asset Policy (still applies to new work)

`sbn-design-system.css` and `SBN-Design-Reference.md` are the single source of truth for tokens, fonts, and colors. Before writing any CSS, read those docs.

Legacy WP code in `sbn-course-player(legacywp)/` is a **reference for design intent** only. Port selectively; do not copy hex literals or jQuery.
