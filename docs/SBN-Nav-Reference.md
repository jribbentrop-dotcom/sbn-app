# SBN Navigation & Contact Reference

As-built reference for the site header (mega menu + mobile drawer) and the
public contact page. Supersedes the stale `MEGA-MENU-HANDOFF.md` build spec at
repo root (that file is a pre-implementation prototype plan — emoji/Alpine/pure-CSS —
and does **not** match the shipped Vue component).

---

## 1. Components & files

| Concern | File |
|---------|------|
| Header nav (desktop mega panels + mobile drawer) | `resources/js/Components/MegaMenu.vue` |
| Nav / mega-menu styling | `resources/css/frontend/mega-menu.css` |
| User dropdown (right side) | `resources/js/Components/UserMenu.vue` |
| Footer (Contact + legal links) | `resources/js/Components/Footer.vue` |
| Contact page | `resources/js/Pages/Contact/Index.vue` |
| Contact controller | `app/Http/Controllers/ContactController.php` |
| Contact mailable | `app/Mail/ContactFormMail.php` |
| Contact email body | `resources/views/mail/contact.blade.php` |
| Legal / About pages | `resources/js/Pages/Legal/{Impressum,PrivacyPolicy,CookiePolicy,Terms,About}.vue` |
| Legal prose wrapper | `resources/js/Components/Legal/ProsePage.vue` |

The header is **Vue/Inertia-driven**, not pure CSS. Open state is a reactive
`openMenu` ref; panels toggle the `.manual-hover` class. Hover intent uses
open/close delays (80ms / 150ms) and is pointer-only (skipped on touch, where
click drives the menu). `Escape` closes everything.

---

## 2. Desktop mega panels

Three panels, each a `<li.menu-item-has-children>` with a `<ul.sub-menu>`:

- **Courses** (`#mega-panel-courses`) — 4 cards in a 2×2 grid (`.mega-col-cards`):
  By Level, By Style, All Courses, **Find Your Level** (→ `/grades`).
  Plus two featured course images (`.mega-col-featured`).
- **Explore** (`#mega-panel-explore`) — 5 library cards laid out **3×2** (last
  cell empty): Chord, Rhythm, Song, Progression, **Theory** (→ `/theory`).
  Card grid is widened to 3 columns / 560px **only** for this panel. Plus the
  TOP10 CTA box.
- **Shop** (`#mega-panel-shop`) — 4 category cards (2×2), one featured product
  image, and the Custom Services CTA box.

### 2.1 Featured image column (`.mega-col-featured`)

- Default width 180px; **Courses** overridden to 195px, **Shop** to 190px
  (scoped by panel id).
- `background: #fff` (blends into the white panel — no grey letterbox).
- No box-shadow; a subtle `translateY(-4px)` lift on hover.
- **Shop featured image specifics** (the product art is portrait 1414×2000, which
  would otherwise stretch the panel tall):
  - `object-fit: contain; object-position: top; padding: 10px` — full image, no
    edge crop, top-aligned with whitespace at the bottom.
  - `max-height: 200px` caps panel height.
  - The "View Product" button (`.mega-featured-overlay`) is **hidden at rest**
    (`opacity: 0`) and fades in on hover; it's centered both axes (overlay
    spans full height via `top:0; bottom:0`).
- Course featured titles were removed (image-only); `.mega-featured-desc`
  text-shadow removed. Featured button is a frosted translucent-white pill that
  goes solid white on hover.

### 2.2 Mega cards (`.mega-card`)

- `color: var(--sbn-dark)`; pinned dark on hover so the global `a:hover` red
  does **not** leak into the title (`h4`) / blurb (`p`).
- Hover = background tint + icon lift only. The dark hover **border was removed**;
  a `1px solid transparent` border stays to prevent layout shift.

---

## 3. The TOP10 / CTA box pattern (`.mega-col-cta--top10`)

Both the Explore TOP10 CTA and the Shop "Custom Services" CTA share this pattern.

Structure (single `<li.mega-top10-cta-body>` — **not** the legacy multi-row
description/features/button list):

```html
<li class="mega-top10-cta-body">
  <svg class="mega-top10-cta__bg-icon" aria-hidden="true">…</svg>   <!-- watermark -->
  <h3 class="mega-top10-cta__title">TOP10</h3>
  <p class="mega-top10-cta__blurb">…short, generic blurb…</p>
  <Link class="mega-top10-cta__btn">Explore</Link>
</li>
```

Key facts:

- **Watermark icon** (`.mega-top10-cta__bg-icon`): 180×180, `position:absolute`,
  vertically centered, bleeds off the right (`right:-36px`), rotated `-8deg`,
  `opacity:0.12` white. Box clips it (`overflow:hidden`); text sits above via
  `z-index` (body is `position:relative; z-index:1`, icon `z-index:0`).
  Explore uses a trophy; Shop uses a wrench/screwdriver.
- **Button** (`.mega-top10-cta__btn`): white pill, dark text, `fit-content`
  width, 13px / 8×14 padding. The single `→` arrow comes from the legacy CSS
  rule `.mega-col-cta > .sub-menu li:last-child a::after { content:"→" }` —
  **do NOT also put a literal `→` in the button text** or you get two arrows.
- Top padding reduced to 12px; Explore CTA uses `align-self:start` so it hugs
  content instead of stretching to the 3×2 card grid.

### 3.1 Gotcha — legacy CTA CSS

`.mega-col-cta > .sub-menu li:first-child a { pointer-events:none }` (mega-menu.css)
was written for the old multi-`<li>` CTA (description row). With the new single
`<li.mega-top10-cta-body>` it would match the button and **kill clicks**, so it's
scoped `:first-child:not(.mega-top10-cta-body)`. If a CTA button ever goes
unclickable, this rule is the first suspect.

---

## 4. Mobile drawer

`.mobile-drawer` mirrors the desktop panels as accordions. Each main-level item
(`.mobile-nav-item`) is `1.1rem`. Keep the drawer links in sync with the desktop
panels (e.g. Find Your Level, Theory Library were added to both).

---

## 5. Contact page

- **Routes** (`routes/web.php`): `GET /contact` → `contact.show`,
  `POST /contact` → `contact.submit` with `throttle:5,1` (5/min/IP).
- **Submit flow**: validate → `Mail::to(config('mail.contact_to',
  'hello@soulbossanova.com'))->send(new ContactFormMail($data))` → flash success.
  Mailable sets `reply-to` = submitter, subject `Contact form: <subject>`.
  Mail driver is `log` in dev (submissions land in `storage/logs/laravel.log`).
- **Honeypot**: hidden `website` field (`.sbn-hp`, off-screen, `tabindex=-1`,
  `aria-hidden`). If `filled($request->website)` the controller returns a fake
  success (no signal to the bot) and sends nothing.
- **Success UI**: uses Inertia `useForm().recentlySuccessful` (no `flash` prop is
  shared via HandleInertiaRequests, so don't rely on `$page.props.flash`).
- **Entry points**: footer Hub column "Contact" link, and the Shop mega-menu
  Custom Services CTA ("Get in touch" → `/contact`).

---

## 6. Legal / footer content pages

Four static, long-form content pages linked from the footer. No controller
logic — the routes render Inertia pages directly.

- **Routes** (`routes/web.php`, public / guest-reachable):
  | URL | Route name | Page |
  |-----|-----------|------|
  | `/impressum` | `impressum` | `Legal/Impressum` (Legal Notice / Impressum, § 5 TMG) |
  | `/privacy-policy` | `privacy-policy` | `Legal/PrivacyPolicy` (GDPR) |
  | `/cookie-policy` | `cookie-policy` | `Legal/CookiePolicy` (TTDSG + GDPR) |
  | `/terms` | `terms` | `Legal/Terms` (Terms & Conditions) |
  | `/about` | `about` | `Legal/About` |
- **Shared wrapper** `Components/Legal/ProsePage.vue`: applies
  `defineOptions({ layout: PublicLayout })`, the `<Head>` title, and the
  `.legal-prose` typography (Fraunces `--font-display` titles, DM Sans body,
  JetBrains Mono `--font-mono` "Last updated" line). Content is passed in as
  plain semantic HTML through the default slot; pages stay content-only.
- **`noindex` by default**: `ProsePage` renders `<meta name="robots"
  content="noindex">` unless `:noindex="false"`. The pages currently ship with
  `[BRACKETED PLACEHOLDERS]` (real business/legal details TBD before launch);
  keep them noindex until the placeholders are filled, then flip per page.
- **Entry points**: footer bottom bar (Impressum, Privacy Policy, Cookie
  Policy, Terms) and the footer Hub column ("About"). These replaced the
  earlier dead `/imprint` and `/privacy` links and the `About` `href="#"`
  placeholder.

---

## 7. Conventions

- Public pages use `defineOptions({ layout: PublicLayout })`, `sbn-*` classes,
  `useForm` for posts, and inline `form.errors.<field>` for validation messages.
- `.sbn-btn` / `.sbn-btn-primary` are global (`public/css/sbn-design-system.css`),
  usable from any Inertia page.
- Mega-menu CSS lives in `resources/css/frontend/mega-menu.css` — **not** in
  `home.css` (the header is shared across pages).
