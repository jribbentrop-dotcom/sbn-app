# Phase 2 — Shop

Second phase of the Frontend Migration Plan. Serves as the low-stakes warmup to the Inertia + Vue + TS stack: full shop layout with real product data, stubbed payment, no auth.

**Depends on:** Phase 1 (App Shell) — persistent layout, mega menu, auth shared prop stubs.

---

## 0. Phase 1 Polish (prerequisite)

Phase 1 shipped but has three loose ends that must be closed before Phase 2 work starts (otherwise the shop header/menu inherits broken state):

1. **Mega menu structure** — add **Top 10** as a top-level item (SEO-important). Current structure (Home / Courses / Explore / Shop) is missing it. Either add as 4th top-level or as a column in Explore. Decide from legacy `header.php` usage patterns.
2. **Legacy CSS port** — `PublicLayout.vue` and `MegaMenu.vue` use classes (`site-header`, `header-inner`, `main-navigation`, `mega-menu-backdrop`, etc.) with no CSS loaded. Port `sbn-course-player(legacywp)/flavor-starter/flavor-starter/css/header.css` + `mega-menu.css` into either `public/css/mega-menu.css` (loaded from `app.blade.php`) or extend `sbn-design-system.css`. Match the legacy visual.
3. **Unsplash placeholder** — `MegaMenu.vue:92` references `images.unsplash.com`. Replace with a local asset copied from `sbn-course-player(legacywp)/flavor-starter/flavor-starter/images/` into `public/images/mega-menu/`.

Also minor code fixes:
- `PublicLayout.vue:16` uses non-existent `outerHeight` property; simplify to `getBoundingClientRect().height`.
- `PublicLayout.vue` `onUnmounted` — replace with `resizeObserver?.disconnect()`.

This polish is ~half a session; bundle it into the first Phase 2 commit or handle as a standalone commit immediately before.

---

## 1. Scope

- Catalog, product detail, cart drawer, checkout flow.
- ~80 digital PDF products imported from the WordPress WXR export.
- All products are **simple + virtual + downloadable**, single PDF each.
- Pricing: **base currency EUR**; USD displayed via fixed conversion rate; customer pays in EUR.
- **Guest checkout only** (no auth). Stubbed "Pay" button creates order with status `pending_stub` — no real Stripe.
- After successful stub checkout, show order-success page with time-limited download links (token-based, works without auth).
- Real Stripe integration is Phase 10.

---

## 2. Database Schema

### New tables (migrations)

```
sbn_products
  id                 bigint pk
  slug               string unique
  title              string
  excerpt            text nullable
  description        longtext nullable
  price_cents        integer (EUR, base)
  thumbnail_path     string nullable  (e.g. "products/thumbnails/estate.webp")
  pdf_path           string nullable  (local storage after download)
  pdf_filename       string nullable  (download-name, e.g. "BOSSANOVA - Estate.pdf")
  pdf_original_url   string nullable  (WP URL, audit)
  attributes         json nullable    ({ notation: ["chord-grids","tablature"], pages: "4" })
  meta_description   string nullable  (SEO)
  wp_post_id         integer nullable (audit)
  published_at       timestamp nullable
  status             enum('published','draft') default 'draft'
  created_at / updated_at
  // indexes: slug, status, published_at

sbn_product_categories
  id, slug unique, name, parent_id nullable fk->self, sort_order int default 0

sbn_product_tags
  id, slug unique, name

sbn_product_category  (pivot)  product_id, category_id  (composite PK)
sbn_product_tag       (pivot)  product_id, tag_id       (composite PK)

sbn_orders
  id
  guest_email        string
  total_cents        integer (EUR)
  display_currency   enum('EUR','USD')  // for UI; charge is always EUR in Phase 10
  status             enum('pending_stub','paid','failed') default 'pending_stub'
  stripe_payment_intent_id  string nullable  (Phase 10)
  created_at / updated_at

sbn_order_items
  id
  order_id           fk
  product_id         fk
  title_snapshot     string
  price_cents_snapshot integer
  quantity           int default 1

sbn_download_grants
  id
  order_id           fk
  product_id         fk
  token              string unique (ULID or random 32-char)
  guest_email        string
  downloads_used     int default 0
  created_at
  // index: token
```

### Config

Add to `config/shop.php`:
```php
return [
    'base_currency' => 'EUR',
    'display_currencies' => ['EUR', 'USD'],
    'usd_rate' => env('SHOP_USD_RATE', 1.08), // EUR -> USD
];
```

---

## 3. Asset Placement

- **Thumbnails:** user places WEBP files at `storage/app/public/products/thumbnails/{slug}.webp`.
  - Run `php artisan storage:link` once — serves at `/storage/products/thumbnails/{slug}.webp`.
  - Filename = product slug. Import matches by slug.
- **PDFs:** downloaded by the import command into `storage/app/private/products/pdfs/{slug}.pdf`.
  - Served via authenticated download route using a signed `download_grants.token` — never direct-linked.

---

## 4. Import Command

### New file: `app/Console/Commands/ImportWooProducts.php`

```
php artisan sbn:import-wc-products {path-to-wxr-xml} [--dry-run] [--download-pdfs]
```

**Behavior:**
1. Parse XML with SimpleXML.
2. Build attachment index first pass: map `wp:post_id` → attachment URL for all `<item>` with `post_type=attachment`.
3. Iterate `<item>` elements with `post_type=product` AND `status=publish`.
4. For each:
   - Upsert category taxonomy from `<category domain="product_cat">` (hierarchical — check `<wp:term>` at top of XML for parent/child).
   - Upsert tag taxonomy from `<category domain="product_tag">`.
   - Extract attributes (`pa_notation`, `pa_pages`) into `attributes` JSON.
   - Unserialize `_downloadable_files` (PHP serialized array) to get PDF URL + friendly filename.
   - Resolve `_thumbnail_id` → attachment URL → thumbnail filename.
   - Check `storage/app/public/products/thumbnails/{slug}.webp` exists; set `thumbnail_path` or null.
   - Upsert product row by `slug`, store `wp_post_id` for audit.
   - Sync pivots for categories/tags.
5. With `--download-pdfs` flag: fetch each `pdf_original_url` via HTTP, save to `storage/app/private/products/pdfs/{slug}.pdf`, update `pdf_path`.
6. Report counts: created, updated, skipped, failed.

**Safety:**
- Idempotent: re-running updates, never duplicates.
- `--dry-run` prints actions without writing.
- PDF download is separate flag — first run the import, verify, then download files.

### Eloquent models

- `app/Models/Product.php` — belongsToMany Category, Tag. Accessor `price_cents_usd` computes from config rate.
- `app/Models/ProductCategory.php`, `ProductTag.php`
- `app/Models/Order.php`, `OrderItem.php`, `DownloadGrant.php`

---

## 5. Backend Controllers & Routes

### Routes (`routes/web.php`)

```php
// Public shop
Route::get('/shop', [ShopController::class, 'index'])->name('shop.index');
Route::get('/shop/category/{slug}', [ShopController::class, 'category'])->name('shop.category');
Route::get('/shop/product/{slug}', [ShopController::class, 'show'])->name('shop.show');
Route::get('/shop/cart', [CartController::class, 'show'])->name('cart.show');
Route::get('/shop/checkout', [CheckoutController::class, 'show'])->name('checkout.show');
Route::post('/shop/checkout', [CheckoutController::class, 'store'])->name('checkout.store');
Route::get('/shop/order/{token}', [OrderController::class, 'success'])->name('order.success');
Route::get('/shop/download/{token}/{productId}', [DownloadController::class, 'download'])
    ->name('download.file');
```

### Controllers

- **ShopController@index** — Inertia::render('Shop/Index', { products, categories, filters })
  - Pagination (20/page), filter by category slug, sort by title/date/price.
- **ShopController@category** — same as index, scoped to category.
- **ShopController@show** — Inertia::render('Shop/Show', { product, related })
  - Eager-load categories, tags.
- **CartController@show** — Inertia::render('Shop/Cart', { items })
  - Cart state lives on the client (composable); this page just renders it.
- **CheckoutController@show** — Inertia::render('Shop/Checkout', { items, totals })
- **CheckoutController@store** — validates guest_email + cart, creates Order + OrderItems + DownloadGrants (one per item), redirects to success page via token.
- **OrderController@success** — Inertia::render('Shop/OrderSuccess', { order, downloadLinks })
- **DownloadController@download** — validates token, increments `downloads_used`, streams file from `storage/app/private/products/pdfs/`.

### Shared Inertia props (update `HandleInertiaRequests`)

```php
'cart' => [
    'count' => session('cart.count', 0),  // optional server-side hint
],
'shop' => [
    'usd_rate' => config('shop.usd_rate'),
],
```

Cart itself is client-side; server only hints count for menu badge.

---

## 6. Frontend — Inertia Pages & Components

All [NEW] for this phase.

### Pages (`resources/js/Pages/Shop/`)

- `Index.vue` — catalog grid with sidebar filters (categories, tags) + search + sort.
- `Category.vue` — same as Index, pre-filtered (or reuse Index with prop).
- `Show.vue` — product detail: hero (thumbnail + title + price + Add to Cart), description HTML, attributes list, related products.
- `Cart.vue` — line items, quantity adjust, remove, subtotal, "Proceed to Checkout" button.
- `Checkout.vue` — guest email form, order summary, "Pay (Stub)" button.
- `OrderSuccess.vue` — order confirmation + list of download links (one per purchased PDF).

### Components (`resources/js/Components/Shop/`)

- `ProductCard.vue` — grid card: thumbnail, title, price, "Add to Cart"
- `ProductPrice.vue` — displays EUR + USD using `config.usd_rate`; currency toggle stored in localStorage
- `CartDrawer.vue` — slide-over panel accessible from anywhere (header icon); opens on add-to-cart
- `CartLineItem.vue` — drawer line item
- `CategoryFilter.vue` — sidebar filter UI

### Cart composable (`resources/js/composables/useCart.ts`)

- Pinia store OR plain composable with `ref` (team-called decision — recommend **plain composable**: small state, persists via `localStorage`, no cross-route reactivity headaches).
- State: `items: Array<{ product_id, slug, title, price_cents, thumbnail_path, quantity }>`.
- Actions: `add(product)`, `remove(productId)`, `setQuantity(productId, qty)`, `clear()`.
- Computed: `count`, `subtotalCents`.
- Persists to `localStorage` under `sbn.cart`.

### Currency composable (`resources/js/composables/useCurrency.ts`)

- `display: 'EUR' | 'USD'` persisted to localStorage.
- `format(cents, currency?)` → `"€6.00"` or `"$6.48"`.
- Rate from Inertia shared prop `shop.usd_rate`.

### Mega menu update

Add cart icon with count badge to `MegaMenu.vue` (or UserMenu area). Opens `CartDrawer` on click.

### TypeScript types

Create `resources/js/types/shop.ts`:
```ts
export interface Product { id: number; slug: string; title: string; ... }
export interface CartItem { product_id: number; slug: string; title: string; price_cents: number; thumbnail_path: string|null; quantity: number }
export interface Order { id: number; token: string; items: OrderItem[]; ... }
```

---

## 7. Seeding & Verification

### Seeder: `database/seeders/ProductSeeder.php`

- Calls the import command on the WXR file, OR inserts 3–5 hardcoded products for test environments.
- Production uses the import command directly, not the seeder.

### Manual verification

1. Run `php artisan migrate`.
2. Place WXR file at `storage/app/imports/soulbossanova-export.xml`.
3. Place thumbnails at `storage/app/public/products/thumbnails/`.
4. `php artisan storage:link`.
5. `php artisan sbn:import-wc-products storage/app/imports/soulbossanova-export.xml --dry-run` — review output.
6. `php artisan sbn:import-wc-products storage/app/imports/soulbossanova-export.xml` — imports metadata.
7. `php artisan sbn:import-wc-products storage/app/imports/soulbossanova-export.xml --download-pdfs` — fetches PDFs. Can take minutes.
8. Visit `/shop` — catalog renders with 80 products.
9. Click a product → detail page with description, attributes, Add to Cart.
10. Add to cart → drawer opens with item.
11. Proceed to checkout → enter email → "Pay (Stub)".
12. Lands on order success page with download link(s).
13. Click download → PDF downloads from `storage/app/private/`.
14. Re-run import — confirms idempotency (counts show 0 created, 80 updated).

### Done criteria

- All ~80 products imported with slug, title, price, excerpt, description, categories, tags, attributes.
- Thumbnails render in catalog and detail pages; products without thumbnails show placeholder.
- Currency toggle (EUR/USD) works across catalog, detail, cart, checkout.
- Cart persists across page navigations and browser refresh.
- Stub checkout creates order + items + download grants; status = `pending_stub`.
- Download link (token-based) streams PDF.
- Admin sees orders via a simple `/admin/orders` Blade index (lightweight sanity-check view).
- SEO head tags set per page via Inertia head manager: `<title>`, `<meta name="description">`, `<meta property="og:*">`.
- `npm run build` + `php artisan test` pass.
- Dev mega menu shows cart badge with live count.

---

## 8. Legacy References

- `sbn-course-player(legacywp)/flavor-starter/flavor-starter/woocommerce/archive-product.php` — catalog layout intent
- `sbn-course-player(legacywp)/flavor-starter/flavor-starter/woocommerce/single-product.php` — product detail layout
- `sbn-course-player(legacywp)/flavor-starter/flavor-starter/woocommerce/cart/` — cart markup
- `sbn-course-player(legacywp)/flavor-starter/flavor-starter/woocommerce/checkout/` — checkout markup
- `sbn-course-player(legacywp)/flavor-starter/flavor-starter/css/shop.css` — shop styles (port what's not in design system)
- WXR export file (user to place at `storage/app/imports/`) — data source

Design system authority still applies: brand colors via `var(--clr-*)`, Tailwind only for layout. See `docs/SBN-Design-Reference.md`.

---

## 9. Out of Scope (do NOT do in Phase 2)

- Real Stripe integration → Phase 10.
- User accounts / login → Phase 10.
- Reviews, ratings, wishlists.
- Subscriptions, bundles.
- Tax calculation (digital goods, flat price).
- Coupons / discount codes.
- Multi-language content.
- Admin CRUD for products (import is one-way; if ad-hoc edits needed, add a minimal Blade edit form but do not build full admin — defer).

---

## 10. Risk Notes

- **PDF download bulk fetch** may hit WP server rate limits. Import command should sleep ~1s between requests and retry with backoff.
- **Thumbnail/slug mismatch** — if WEBP filenames don't match product slugs exactly (e.g. old filenames use post ID), import command needs a fallback or user renames files. Resolve at dry-run time before real import.
- **Categories hierarchy** — confirm from the WXR `<wp:term>` section whether categories are hierarchical. "Bossa Nova" and "Intermediate" may be siblings, or difficulty may nest under style. Inspect before writing the import.
- **HTML in description** — WP rich content (YouTube iframes, etc.) should be sanitized before rendering. Use a server-side allowlist (e.g. HTMLPurifier or Laravel's built-in `Str::markdown`/HtmlString with `v-html`) — never render raw user HTML without sanitization. For now, trust-the-import-source is acceptable since it's your own content, but note for later.
- **SEO meta length** — truncate descriptions >160 chars for `meta name="description"`.

---

## 11. AI Handoff Checklist

Before starting:
- [ ] Confirm WXR file is at `storage/app/imports/soulbossanova-export.xml`.
- [ ] Confirm thumbnails are at `storage/app/public/products/thumbnails/`.
- [ ] Read this doc end-to-end + Section 2.5 (Existing Vue Assets) + Section 4 (Design System) of `Frontend-Migration-Plan.md`.
- [ ] Inspect the WXR `<wp:term>` sections for category hierarchy before writing import.
- [ ] Do not touch admin. Do not start Phase 3. Scope is strictly this file.

Deliverable: one PR that ships the full flow end-to-end (migrations → import → pages → stubbed checkout → download). Not multiple half-done pieces.

---

## 12. Completion Summary (April 21, 2026)

Phase 2 successfully implemented with the following deliverables:

### Database & Backend
- ✅ All migrations created (`sbn_products`, `sbn_product_categories`, `sbn_product_tags`, pivots, `sbn_orders`, `sbn_order_items`, `sbn_download_grants`)
- ✅ Models with relationships and scopes (`Product`, `ProductCategory`, `ProductTag`, `Order`, `OrderItem`, `DownloadGrant`)
- ✅ WXR import command (`sbn:import-wc-products`) with PDF download and thumbnail support
- ✅ Controllers: `ShopController`, `CartController`, `CheckoutController`, `OrderController`, `DownloadController`, `Admin\OrderController`
- ✅ Routes for all shop pages and admin orders

### Frontend (Vue 3 + Inertia)
- ✅ Shop Index page with 4-column product grid and sidebar filter
- ✅ Product detail page with breadcrumb, attributes, related products
- ✅ Cart drawer and Cart page with quantity controls
- ✅ Checkout page with stubbed payment processing
- ✅ Order success page with download links
- ✅ All TypeScript types in `resources/js/types/shop.ts`
- ✅ Cart composable with localStorage persistence
- ✅ Currency composable with EUR/USD toggle
- ✅ Category color system via `useCategoryColors` composable

### Components
- ✅ `ProductCard` with category gradient, badges (style, difficulty), hover overlay
- ✅ `CategoryFilter` with product counts and active state
- ✅ `CartDrawer` slide-in cart
- ✅ `CartLineItem` with thumbnail, quantity, remove
- ✅ `ProductPrice` with currency toggle

### Admin
- ✅ Admin orders index view (Blade) with pagination and status badges

### Known Issues / Technical Debt
- ⚠️ Product card badges (style/difficulty) depend on `attributes` field being populated in database
- ⚠️ Featured collection image in mega menu needs asset (404 currently)
- ⚠️ Real Stripe integration deferred to Phase 10 (as planned)
