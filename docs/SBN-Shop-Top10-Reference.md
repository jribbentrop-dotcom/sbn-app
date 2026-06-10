# SBN Shop & Top10 Reference

Covers two distinct but config-driven systems: the **product shop** (catalog, cart, checkout, orders, downloads) and the **Top10 curated list pages**.

For payment processing see `SBN-Customer-Reference.md §12`. For auth and course access gating see `SBN-Customer-Reference.md §11`.

---

## Part 1 — Shop

---

### 1. Routes & Controllers

| Route | Controller | Notes |
|---|---|---|
| `GET /shop` | `Shop\ShopController::index` | Paginated catalog (20/page) |
| `GET /shop/category/{slug}` | `Shop\ShopController::category` | Filtered by category |
| `GET /shop/product/{slug}` | `Shop\ShopController::show` | Product detail |
| `POST /shop/cart` | `Shop\CartController` | Add to cart (session-backed) |
| `GET /shop/checkout` | `Shop\CheckoutController::show` | Checkout page |
| `POST /shop/checkout` | `Shop\CheckoutController::store` | Create order → redirect to payment provider |
| `GET /shop/order/{token}` | `Shop\OrderController::show` | Order confirmation / receipt |
| `GET /shop/download/{token}/{productId}` | `Shop\DownloadController` | Time-limited download |
| `GET /account/orders` | `AccountController::orders` | Customer order history |

**Namespace:** `App\Http\Controllers\Shop\`

---

### 2. Data Model

#### `sbn_products`

| Column | Notes |
|---|---|
| `slug` | URL key |
| `title`, `excerpt`, `description` | Display fields |
| `price_cents` | EUR cents (always EUR — USD is display-only) |
| `payment_ref` | Provider's product/price ID for checkout session |
| `tax_code` | Stripe tax code e.g. `txcd_10302000` for digital goods |
| `status` | `publish` / `draft` |
| `published_at` | Sort field for "by date" |
| `format`, `pages`, `level`, `composer`, `performer` | Meta table fields on detail page |

#### `sbn_product_categories`, `sbn_product_tags`
Pivot tables. `Product::categories()` and `Product::tags()` relationships.

#### `sbn_orders`

| Column | Notes |
|---|---|
| `token` | ULID — public-facing order reference (used in URLs) |
| `user_id` | Nullable — null = guest checkout |
| `provider_order_id` | Provider's order reference, set on webhook |
| `status` | `pending_payment` → `paid` → `refunded` |
| `guest_email` | Set on guest checkout; used for `claimGuestOrders()` |

#### `sbn_order_items`
`order_id`, `product_id`, `quantity`, `price_cents`.

#### `sbn_download_grants`
Created by webhook on `paid` (not at checkout). Fields: `token` (random 32-char), `order_id`, `product_id`, `guest_email`, `expires_at`.

---

### 3. Currency

- Prices are always stored and charged in **EUR**
- USD is **display-only** via `SHOP_USD_RATE` env var (fixed conversion)
- `composables/useCurrency.ts` — `EUR`/`USD` toggle persisted in `localStorage`
- `composables/useCategoryColors.ts` — style color via `getCategoryStyle(styleSlug)`
- `config/shop.php` — base currency + USD rate

---

### 4. Cart

Cart lives in **`localStorage`** via `composables/useCart.ts` — no server-side session, no Pinia. Cart state survives page refresh; it is cleared on order success.

Key behaviour:
- Thumbnails stored as raw URL strings (not `new URL()` — throws on relative paths)
- Cart count surfaced as `cart.count` in Inertia shared props (server reads session cart on each request for the count badge)

---

### 5. Checkout Flow

```
User clicks "Buy" → cart → /shop/checkout
  → CheckoutController::store
      creates Order (status: pending_payment, user_id if logged in)
      PaymentProvider::createCheckout(order) → URL
      Inertia::location($url)   ← hard redirect to provider hosted page
  → Provider collects payment → fires webhook
  → PaymentWebhookController (POST /webhooks/payments)
      order.status = paid
      DownloadGrant::firstOrCreate per item
      if order.user: CourseAccessService::grantPurchase
  → Provider redirects back to /shop/order/{token}
```

Download grants are created **on webhook only**, not at checkout. This prevents dangling grants if payment is abandoned.

---

### 6. Downloads

`DownloadController` validates: grant token exists, matches `product_id`, not expired (`expires_at`), not revoked. Streams the file from `storage/app/pdfs/` (disk `pdfs` defined in `config/filesystems.php`).

Download link URL: `/shop/download/{token}/{productId}`

---

### 7. Frontend Components

| File | Role |
|---|---|
| `Pages/Shop/Index.vue` | 4-column catalog grid + `CategoryFilter` sidebar |
| `Pages/Shop/Show.vue` | Product detail: gallery, meta table, feature chips, related products grid |
| `Pages/Shop/Cart.vue` | Cart summary page |
| `Pages/Shop/Checkout.vue` | Checkout form |
| `Pages/Shop/OrderSuccess.vue` | Post-payment confirmation |
| `Components/Shop/ProductCard.vue` | Grid card — gradient, style badge, difficulty stars, hover overlay |
| `Components/Shop/CartDrawer.vue` | Slide-in cart drawer |
| `Components/Shop/CartLineItem.vue` | Single cart item row |
| `Components/Shop/CategoryFilter.vue` | Sidebar with product counts and active state |
| `Components/Shop/ProductPrice.vue` | EUR/USD price display |

**CSS:** `public/css/shop.css` — shared layout (container/sidebar grid, products grid, pagination, breadcrumb, checkout form, order success). Component-internal styles (card hover, badge positioning) stay scoped.

---

### 8. Admin

`/admin/orders` — Blade-rendered orders index (`Admin\OrderController`). Read-only table; no order editing UI.

---

## Part 2 — Top10 Pages

---

### 9. Overview

Three config-driven curated list pages. Each shows 10 items in a nav-grid + detail-panel layout. All three follow the same pattern — different config files, different Vue page components, same `Top10Controller`.

| Route | Config file | Vue page |
|---|---|---|
| `GET /top10/bossa-nova-chords` | `config/top10/bossa-nova-chords.php` | `Pages/Top10/BossaNovaChords.vue` |
| `GET /top10/latin-jazz-standards` | `config/top10/latin-jazz-standards.php` | `Pages/Top10/LatinJazzStandards.vue` |
| `GET /top10/bossa-nova-songs` | `config/top10/bossa-nova-songs.php` | `Pages/Top10/BossaNovaSongs.vue` |

**Controller:** `app/Http/Controllers/Top10Controller.php`

---

### 10. Config File Structure

Each config file returns a keyed array where the key is a chord/song/progression slug:

```php
return [
    'chord-slug' => [
        'title'         => 'The Major 6/9 Chord',
        'shortTitle'    => 'Major 6/9',        // used in nav thumbnails
        'image'         => '/images/top10/...',
        'description'   => '...',
        'category'      => 'jazz',             // for progression builder context

        // Chord voicing panel
        'rootOverride'  => 'Db',               // transpose the stored shape
        'bassOverride'  => null,               // optional slash-chord bass
        'voicingCaption'=> '...',

        // Progression panel — choose one of the two modes below
        'progressionLibrarySlug'     => 'ii-v-i',    // mode 1: resolves via ChordProgression model
        'progressionSeedKey'         => 'Db',
        'progressionVoicingOverrides'=> [0 => 'slug', 2 => 'slug'],
        'progressionSlugs'           => ['slug1', 'slug2'], // mode 2: legacy direct slugs
        'progressionName'    => 'Trademark Progression',
        'progressionCaption' => '...',

        // ── Live leadsheet/exercise bars (replaces static progression in SyncedPlayer) ──
        // When present, the SyncedPlayer panel shows real bars from the DB leadsheet
        // instead of the static progressionTiles built by the controller.
        // The Vue page fetches /api/sbn/synced-player/{slug}?type=…&start=…&end=… client-side.
        // 'syncedPlayer' => [
        //     'slug'  => 'the-girl-from-ipanema',  // leadsheet or exercise slug
        //     'type'  => 'leadsheet',               // 'leadsheet' (default) or 'exercise'
        //     'start' => 5,                         // 0-based bar index, inclusive
        //     'end'   => 12,                        // 0-based bar index, inclusive
        // ],

        // Optional rhythm strip
        'rhythmSlug'    => 'bossa-nova-basic',

        // Related products/courses
        'relatedProducts' => [
            ['title' => '...', 'description' => '...', 'url' => '/shop/product/...', 'type' => 'product'],
        ],
    ],
    // ...10 items total
];
```

**Three progression panel modes (in priority order):**
1. **`syncedPlayer`** — live leadsheet/exercise bars fetched from the API at runtime. The rhythm comes from the leadsheet's own `rhythm` slug in the DB (falls back to the page-level `rhythmPattern` if absent). This is the richest mode — real voicings, correct number of bars.
2. **`progressionLibrarySlug` + `progressionSeedKey`** — resolves from the progression library via `HarmonicContext` + `ProgressionBuilder`. Supports `progressionVoicingOverrides` to pin specific diagrams at given slot indices.
3. **`progressionSlugs`** (legacy) — direct array of chord diagram slugs, bypasses the builder entirely.

---

### 11. Controller Pipeline (`getTop10Data`)

```
require config/top10/{name}.php
  → batch-load all chord slugs + progression chord slugs in one query
  → batch-load any rhythm slugs
  → per item:
      ChordSerializer::serialize(chord, rootOverride)       → voicingData
      OR ChordSerializer::serializeWithBass(chord, root, bass) → voicingData
      HarmonicContext::buildFromNumerals + ProgressionBuilder::buildVoicings
        with pinnedVoicings (pre-transposed via ChordVoicingSearch::transposeShapes)
        → progressionTiles: [{ chordName, numeral, diagramData }]
      config['syncedPlayer'] passed through unchanged → Vue fetches at runtime
→ Inertia::render('Top10/...', ['top10Data' => $items])
```

All chord lookups are batched (one query per page load, not N+1). Config content lives in PHP files — not in the controller. `syncedPlayer` config is passed through as-is; the controller does not resolve it — that happens client-side on selection.

---

### 12. Vue Page Structure

All three Top10 pages follow the same UI pattern:

- **Mobile nav** — horizontal scroll strip of thumbnail cards
- **Desktop nav** — vertical grid of larger cards with number badges
- **Detail view** — title + description + two panels side by side:
  - Left/top panel: `SyncedPlayer` (progression) + caption
  - Right/bottom panel: `RhythmPattern` strip + caption (Songs / Standards pages only)
- **Navigation buttons** — prev/next through the list
- **Related products** — `sbn-card-link` list using Inertia `<Link>`
- **Footer links** — cross-links to the other Top10 pages

Active item tracked by index in component-local `ref`. No router state.

**SyncedPlayer fetch pattern** (identical across all three pages):
```ts
const syncedBars    = ref<LeadsheetBar[] | null>(null);
const syncedRhythm  = ref<RhythmPatternData | null>(null);
const syncedFetching = ref(false);
let lastFetchedKey = '';

async function fetchSyncedBars(cfg) { /* fetches /api/sbn/synced-player/… */ }

// Trigger on mount for the first item, and on every selection change:
watch(() => selectedChord.value, (item) => {
    syncedBars.value = null;
    lastFetchedKey = '';
    if (item?.syncedPlayer) fetchSyncedBars(item.syncedPlayer);
});
```

Template priority in the progression panel:
```html
<SyncedPlayer v-if="item.syncedPlayer && syncedBars?.length"  :bars :rhythm-pattern />
<div          v-else-if="item.syncedPlayer && syncedFetching" class="sbn-synced-loading" />
<SyncedPlayer v-else                                          :progression :rhythm-pattern />
```

---

### 13. How to pick bars for a `syncedPlayer` entry

**Step 1 — find the leadsheet slug.**
Check `sbn_leadsheets` in the DB. On this project use a Python script against the SQLite file (the `php artisan tinker` approach is unreliable on Windows — see §16).

**Step 2 — inspect the bar list.** Save this as a temp `.py` file, run it, then delete it:

```python
import sqlite3, json

conn = sqlite3.connect("database/sbn.db")
slug = "the-girl-from-ipanema"   # ← change this
row = conn.execute(
    "SELECT json_data, rhythm FROM sbn_leadsheets WHERE slug=?", (slug,)
).fetchone()
data = json.loads(row[0]) if row[0] else {}
print("rhythm slug:", row[1])
gi = 0
for sec in data.get("sections", []):
    for m in sec.get("measures", []):
        names = m.get("chordNames") or [c.get("name","") for c in m.get("chords",[])]
        print(f"  bar {gi}: {names}")
        gi += 1
conn.close()
```

Run from the project root: `python temp_bars.py`

**Step 3 — add the config key.** Pick the bar range you want and add to the config entry:
```php
'syncedPlayer' => ['slug' => 'the-girl-from-ipanema', 'start' => 5, 'end' => 12],
```

That's the complete change. No controller edit, no Vue edit — just one config line.

**Notes:**
- `start` and `end` are **0-based global bar indices** counting across all sections flattened. Multi-section leadsheets: bar 0 = first measure of section 1, bar N = first measure of section 2, etc.
- `end` is **inclusive** (bar 12 IS included in the result).
- Omit `start`/`end` entirely to use the whole leadsheet.
- Multi-chord bars (e.g. two chords in one measure) are split into one entry each — so a 2-chord bar at gi=3 becomes bars 3a and 3b in the flat list. Account for this when choosing `end`.
- Set `'type' => 'exercise'` to point at an exercise instead of a leadsheet.
- The rhythm comes from the leadsheet's own `rhythm` column (e.g. `gilberto-rhythm`). If that slug isn't in the DB the player falls back to the page-level `rhythmPattern`.

---

### 14. `bossa-nova-chords.php` — dual use

This config is consumed in two places:

1. **`/top10/bossa-nova-chords`** — the standalone Top10 page (full detail panels, SyncedPlayer, rhythm strip).
2. **`/library/chords` index** — the chord library's "Top 10" sort mode reads `array_keys($config)` to get the 10 slugs in order and pins them at the top of the hitlist. The `description` field from this config is also written to `sbn_chord_diagrams.description` for those chords (via migration `2026_06_08_000001`), so it appears in the hitlist row.

When editing the config order or slugs, both surfaces are affected.

---

### 15. Adding a New Top10 Page

1. Create `config/top10/{name}.php` with 10 items
2. Add a method to `Top10Controller` calling `$this->getTop10Data('{name}')`
3. Add route in `routes/web.php`
4. Create `Pages/Top10/{PageName}.vue` — copy `BossaNovaSongs.vue` as the template (it has the full `syncedPlayer` fetch pattern)
5. Add the footer cross-link to the other pages

No migrations, no DB changes — purely config + frontend.

---

### 15. CSS

Top10-specific styles are **scoped** inside each Vue page component (nav layout, thumbnail sizing, panel grid, detail typography).

Shared design-system classes used:
- `.sbn-panel` / `.sbn-panel-ghost` — gray background box for grouped content
- `.sbn-card-link` — clickable card with hover effect (related products)
- `.sbn-badge`, `.sbn-badge-muted` — category / type badges
- `.sbn-synced-loading` — flex center container for the spinner shown while bars are fetching (scoped, defined in each page component)
- `var(--clr-gradient)`, `var(--clr-accent)`, `var(--clr-surface-2)` — colors

---

### 16. Windows / SQLite DB query gotchas

`php artisan tinker` on Windows has persistent issues with multi-line heredoc strings and variable interpolation — commands often fail with parse errors. **Always use Python against the SQLite file directly** for inspection tasks.

**The reliable pattern:**
1. Write a short `.py` script in the project root
2. Run it with `python script_name.py`
3. Delete the script when done

**Variable interpolation in PowerShell heredocs** — use `@' ... '@` (single-quoted) not `@" ... "@`:
```powershell
$script = @'
import sqlite3
conn = sqlite3.connect("database/sbn.db")
# ... your code
'@
python -c $script
```
But even this breaks if the Python code contains `$` signs. **Writing to a file first is more reliable.**

**Common queries:**

```python
# List all leadsheets with their rhythm slug
import sqlite3
conn = sqlite3.connect("database/sbn.db")
for row in conn.execute("SELECT slug, title, rhythm FROM sbn_leadsheets ORDER BY title"):
    print(row)
conn.close()
```

```python
# Find a leadsheet by title fragment
import sqlite3
conn = sqlite3.connect("database/sbn.db")
for row in conn.execute("SELECT id, slug, title, rhythm FROM sbn_leadsheets WHERE title LIKE '%ipanema%'"):
    print(row)
conn.close()
```

```python
# Inspect bars of a leadsheet (bar indices for syncedPlayer config)
import sqlite3, json
conn = sqlite3.connect("database/sbn.db")
slug = "the-girl-from-ipanema"
row = conn.execute("SELECT json_data, rhythm FROM sbn_leadsheets WHERE slug=?", (slug,)).fetchone()
data = json.loads(row[0]) if row[0] else {}
print("rhythm:", row[1])
gi = 0
for sec in data.get("sections", []):
    for m in sec.get("measures", []):
        names = m.get("chordNames") or [c.get("name","") for c in m.get("chords",[])]
        print(f"  bar {gi}: {names}")
        gi += 1
conn.close()
```

```python
# Inspect bars of an exercise
import sqlite3, json
conn = sqlite3.connect("database/sbn.db")
slug = "bossa-comping-ex1"
row = conn.execute("SELECT content_json, rhythm FROM sbn_exercises WHERE slug=?", (slug,)).fetchone()
data = json.loads(row[0]) if row[0] else {}
print("rhythm:", row[1])
gi = 0
for sec in data.get("sections", []):
    for m in sec.get("measures", []):
        names = m.get("chordNames") or [c.get("name","") for c in m.get("chords",[])]
        print(f"  bar {gi}: {names}")
        gi += 1
conn.close()
```
