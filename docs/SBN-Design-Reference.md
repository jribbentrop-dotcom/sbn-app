# SBN Teaching Hub — Design Reference

> **Purpose:** Upload alongside `SBN-Migration-Reference.md` at the start of every session.
> This file tells Claude exactly what CSS components exist and how to use them.
> **Rule:** Before writing any new CSS, check this document. If a component exists here, extend it — never rewrite it.

---

## LOAD ORDER

### Admin layout (`admin.blade.php`)

```html
<link rel="stylesheet" href="{{ asset('css/sbn-design-system.css') }}">
<link rel="stylesheet" href="{{ asset('css/chord-symbols.css') }}">
<link rel="stylesheet" href="{{ asset('css/admin2.css') }}">
<!-- Page-specific module CSS via @stack('styles') -->
<script src="{{ asset('js/chords.js') }}"></script>
```

### Frontend layout (`app.blade.php`, `welcome.blade.php`)

```html
<link rel="stylesheet" href="{{ asset('css/sbn-design-system.css') }}">
<link rel="stylesheet" href="{{ asset('css/chord-symbols.css') }}">
<!-- Vite bundles resources/css/app.css which imports: -->
<!--   resources/css/frontend/base.css   (--sbn-* aliases + WooCommerce overrides) -->
<!--   resources/css/frontend/header.css (nav/header layout) -->
<!--   resources/css/frontend/mega-menu.css (mega menu layout) -->
@vite(['resources/js/app.ts'])
<script src="{{ asset('js/chords.js') }}"></script>
```

`sbn-design-system.css` must load before everything else on all layouts.

---

## THEME SYSTEM

The app has two switchable themes. The default is **modern**.

```html
<!-- Default (set on all four layout files) -->
<html lang="en" data-theme="modern">

<!-- Switch to vintage -->
<html lang="en" data-theme="vintage">
```

### Modern (default)

- Card frames: `1px solid var(--clr-border)`, hover shifts border to `var(--clr-text-muted)`
- No shadows, no transforms, no color on hover
- Clean, neutral, minimal

### Vintage

Activated by `[data-theme="vintage"]` overrides at the bottom of `sbn-design-system.css`.

- Rhythm cards: thick right+bottom border in `--row-color`, diagonal lift on hover
- Chord cards: offset shadow frame, `translateY(-2px)` on hover
- Pattern rows: thick right+bottom border in `--row-color`, diagonal lift on hover
- Progression viewer: thick right+bottom border in `--prog-color` on hover

### Critical architectural rule

**Card frame styles must live as global classes in `sbn-design-system.css`.** Vue `<style scoped>` blocks add a unique attribute hash (`[data-v-abc123]`) to every selector, which means `[data-theme]` attribute selectors can never reach into scoped styles. If a card frame is defined in a scoped block, it cannot be theme-switched.

**Rule:** Vue component scoped styles may only contain layout and structural styles (flex, grid, padding, positioning). Card frames — borders, shadows, hover transitions — always go in the design system as global classes.

---

## FONTS

Loaded via Google Fonts CDN in `admin.blade.php`:

| Variable | Font | Usage |
|---|---|---|
| `--font-body` | DM Sans | All UI text, labels, buttons |
| `--font-mono` | JetBrains Mono | Fret strings, code, slug values |
| `--font-chord` | Crimson Text | **All chord names** — see Chord Name Styling below |

---

## COLOR TOKENS

All defined in `sbn-design-system.css :root`. Module CSS files reference these — they never define raw hex values.

### Brand / Surface
| Variable | Value | Usage |
|---|---|---|
| `--clr-bg` | `#f8f9fb` | Page background |
| `--clr-white` / `--clr-surface` | `#ffffff` | Cards, inputs, diagram cards |
| `--clr-surface-2` | `#f7fafc` | Table headers, secondary backgrounds |
| `--clr-surface-3` | `#eef1f5` | Hover states, chips |

### Accent (orange — primary brand)
| Variable | Usage |
|---|---|
| `--clr-accent` | Active states, icons, borders |
| `--clr-accent-dim` | Chord name color, hover accents |
| `--clr-accent-bg` | Subtle tint backgrounds |
| `--clr-accent-border` | Subtle borders |
| `--clr-gradient` | Primary buttons, avatar |
| `--clr-gradient-hover` | Button hover state |

### Text
| Variable | Usage |
|---|---|
| `--clr-text` | Primary text, headings |
| `--clr-text-dim` | Secondary text |
| `--clr-text-muted` | `#8896a4` — placeholders, labels, neutral hover border |

### Semantic
| Variable | Color |
|---|---|
| `--clr-success` | `#10b981` green |
| `--clr-warning` | `#f39c12` orange (same as accent) |
| `--clr-error` | `#ef4444` red |
| `--clr-danger` | alias for `--clr-error` |
| `--clr-red` | `#e74c3c` — finger dots in diagrams, gradient end |

### Border / Shadow
| Variable | Usage |
|---|---|
| `--clr-border` | Default card/input border |
| `--clr-shadow-sm` | Subtle shadow |
| `--clr-shadow` | Standard shadow |
| `--clr-shadow-lg` | Prominent shadow |

### Radius
| Variable | Value | Usage |
|---|---|---|
| `--radius-sm` | `6px` | Small elements: diagram cards, badges, inputs |
| `--radius` | `10px` | Standard cards, buttons |
| `--radius-lg` | `16px` | Large panels |

Do not use `--radius-md` — it was removed. The mid-size token is `--radius`.

### Music Style Colors
| Variable | Color | Style |
|---|---|---|
| `--clr-style-bossa` | orange `#f39c12` | Bossa Nova |
| `--clr-style-jazz` | blue `#3b82f6` | Jazz |
| `--clr-style-samba` | green `#10b981` | Samba |
| `--clr-style-latin` | purple `#8b5cf6` | Latin |
| `--clr-style-blues` | indigo `#6366f1` | Blues |
| `--clr-style-pop` | pink `#ec4899` | Pop |
| `--clr-style-classical` | slate `#64748b` | Classical |
| `--clr-style-gold` | gold `#d69e2e` | Featured / Iconic |

### Token namespace

`--sbn-*` names in `resources/css/frontend/base.css` are **aliases only** — they point to `--clr-*` tokens. Do not add new `--sbn-*` tokens. All new tokens go under `--clr-*`, `--radius-*`, `--font-*`, or `--ease`.

---

## CHORD NAME STYLING

**File:** `public/css/chord-symbols.css` (loaded globally)
**PHP helper:** `App\Helpers\ChordName::format()` / `chord()` global helper
**JS helpers:** `sbnFormatChord()` in `sbn-chord-name.js`, `sbnFormatChordHtml()` in `chords.js`

### How chord names are rendered

Chord names use **Crimson Text** (`--font-chord`) with superscripted extensions and styled accidentals. Never render chord names as plain text — always use the helper.

```blade
{{-- Blade (PHP) --}}
{!! chord('Dm7') !!}
{!! \App\Helpers\ChordName::format('G7b9') !!}
```

```javascript
// JavaScript
element.innerHTML = sbnFormatChord('Dm7');      // sbn-chord-name.js
element.innerHTML = sbnFormatChordHtml('Dm7');  // chords.js (same output)
```

### CSS classes produced by the helpers

| Class | Element | Style |
|---|---|---|
| `.sbn-chord-symbol` | Outer wrapper | Crimson Text, `--clr-accent-dim` color |
| `.sbn-chord-root` | Root note (C, D, G…) | Bold, 1.1em |
| `.sbn-chord-quality` | Quality (m, maj, dim…) | Normal weight |
| `.sbn-chord-accidental` | ♯ ♭ | 0.95em, slightly raised |
| `.sbn-chord-ext` | Extensions (7, 9, ♭5…) | Superscript, 0.75em |
| `.sbn-chord-bass` | Slash bass note (/E, /B♭) | 0.9em, baseline |

### ⚠ Rule
**Everywhere** a chord name appears in the UI — tables, cards, headings, badges, tooltips — it must go through `chord()` / `sbnFormatChordHtml()`. Do not render chord names as plain strings.

### Context override: chord grid
`.sbn-chord-symbol` is orange (`--clr-accent-dim`) globally — intentional for most contexts (analysis panel, progression builder, etc.).  
In chord grid cards (`ChordCard.vue`), names must be dark. Override lives in `chord-symbols.css`:
```css
.sbn-ve-chord-name .sbn-chord-symbol { color: var(--clr-text, #2c3e50); }
```
Do not add this override anywhere else — it is scoped to the chord grid container class.

---

## CARD SYSTEM

All library and UI cards share a single frame system defined in `sbn-design-system.css`. The modern default is a thin neutral border with a muted hover. The vintage theme overrides these at the bottom of the same file.

### Standard card (`.sbn-card`)

Generic white bordered panel. Used for page sections, detail wrappers, admin panels.

```html
<div class="sbn-card">...</div>
<div class="sbn-card-lg">...</div>       <!-- large, form pages -->
<div class="sbn-card-section">...</div>  <!-- compact section -->
```

Hover shifts `border-color` to `--clr-text-muted`. No shadow, no transform.

### Chord card (`.sbn-chord-card`)

Full public library card (play button, popularity badge, difficulty stars).

**CSS split:** Shell (border, hover, radius, background) is in `sbn-design-system.css §2c`. All card internals (name, diagram, footer, play button, badges) are in `chord-library.css`.

```html
<div class="sbn-chord-card">
  <div class="sbn-card-chord-name"><!-- chord name HTML --></div>
  <div class="sbn-card-diagram">
    <button class="sbn-play-btn">▶</button>
  </div>
  <div class="sbn-card-footer">
    <span class="sbn-card-pop sbn-pop-essential">Core</span>
    <span class="sbn-card-diff">
      <span class="sbn-diff-star filled">★</span>
      <span class="sbn-diff-star filled">★</span>
      <span class="sbn-diff-star">★</span>
    </span>
  </div>
</div>

<!-- Variants -->
<div class="sbn-chord-card sbn-chord-card--detail">...</div>  <!-- larger, play btn always visible -->
<div class="sbn-chord-card sbn-chord-card--mini">...</div>    <!-- compact, no footer -->
```

**`noNav` prop:** Pass `:no-nav="true"` (or `:noNav="true"`) when wrapping `ChordCard` in an Inertia `<Link>`. Without it the card's internal `window.open` fires alongside the link, opening a new tab.

**Popularity pill classes:** `sbn-pop-occasional`, `sbn-pop-common`, `sbn-pop-essential`, `sbn-pop-iconic`

### Rhythm card (`.sbn-rhythm-card`)

Used in the rhythm library grid. Frame defined globally in `sbn-design-system.css §2d`.

```html
<div class="sbn-rhythm-card">...</div>
```

Vue component scoped styles in `RhythmCard.vue` only contain `position: relative; overflow: hidden` — the frame is global so the vintage theme can reach it.

### Pattern row (`.sbn-pattern-row`)

Used in the course player and rhythm detail page for rhythm pattern listings. Frame defined globally in `sbn-design-system.css §2e`.

```html
<div class="sbn-pattern-row sbn-pattern-row--bossa">...</div>
```

Color modifiers set `--row-color` which the vintage theme uses for the thick border:

```html
sbn-pattern-row--bossa | --jazz | --samba | --latin | --blues | --pop | --classical | --gold
```

### Diagram card shells (`.sbn-diagram-card` / `.sbn-vp-card`)

Base shells for chord diagram display. No fixed width — size always controlled by parent grid.

```html
<!-- Non-interactive display -->
<div class="sbn-diagram-card">...</div>

<!-- Clickable (voicing picker, selectable) -->
<div class="sbn-vp-card">...</div>
<div class="sbn-vp-card is-selected">...</div>
<div class="sbn-vp-card sbn-vp-card--current">...</div>
<div class="sbn-vp-card sbn-vp-card--from-tab">...</div>
```

Border radius: `--radius-sm` (6px). Never add a `width` — size via parent grid.

### Progression viewer (`.sbn-prog-viewer`)

The frame-less shell for `ChordProgressionViewer.vue`. Wrap in `.sbn-card` if a panel frame is needed. The vintage theme adds a thick right+bottom hover border via `[data-theme="vintage"] .sbn-prog-viewer`.

---

## VINTAGE OFFSET CARD STYLE (theme reference)

The vintage card aesthetic — bold right+bottom border in category colour, diagonal lift on hover — is now a **theme variant**, not the active default. It activates automatically when `<html data-theme="vintage">` is set.

The `--row-color` custom property drives the colour on rhythm cards and pattern rows. Set it via modifier classes (`.sbn-pattern-row--bossa`, etc.) or via inline style.

Do not hand-code the vintage frame CSS in components or module files. It lives exclusively in the `[data-theme="vintage"]` block at the bottom of `sbn-design-system.css`.

The `vintageCard` prop has been **removed** from `RhythmPattern.vue`, `ChordProgressionViewer.vue`, and `mountSbnNodes.ts`. Do not re-add it.

---

## BREADCRUMB + DETAIL HERO

**Established 2026-05-26.** All detail/show pages use a two-part visual header: a gradient breadcrumb band above a flat-top bordered white box.

### Breadcrumb (`Breadcrumb.vue`)

```vue
<!-- Category colour variant (library detail pages) -->
<Breadcrumb
  :segments="[{ label: 'Song Library', href: '/library/songs' }, { label: song.title }]"
  :color="categoryColor"
/>

<!-- Brand gradient variant (no category, e.g. chord detail) -->
<Breadcrumb
  :segments="[{ label: 'Chord Library', href: '/library/chords' }, { label: chord.name }]"
/>
```

- `segments` — array of `{ label, href? }`. Last segment (no `href`) renders as the current page.
- `color` — hex or CSS value for the category gradient. Omit to use the orange→red brand gradient.
- Renders `<nav class="sbn-breadcrumb sbn-breadcrumb--cat | --brand">`.
- Top radius only (`border-radius: var(--radius) var(--radius) 0 0`), `margin-bottom: 0` — designed to sit flush above `.sbn-detail-hero`.

### Detail hero (`.sbn-detail-hero`)

Global utility class in `sbn-design-system.css`. Apply to the first content box directly after `<Breadcrumb>` to connect it flush:

```html
<header class="sbn-prog-detail-header sbn-detail-hero">...</header>
<div class="sbn-chord-identity sbn-detail-hero">...</div>
```

Properties provided by `.sbn-detail-hero`:
- `background: var(--clr-white)`
- `border: 1px solid var(--clr-border)`
- `border-top: none` (connects to breadcrumb bottom edge)
- `border-radius: 0 0 var(--radius) var(--radius)`
- `margin-bottom: 32px`

**Rule:** Scoped styles must NOT redeclare `border`, `border-top`, or `border-radius` on hero elements — add `sbn-detail-hero` to the element instead.

Currently applied to: `.sbn-chord-identity` (Chords/Show), `.sbn-cs-hero` (Courses/Show), `.sbn-ss-hero` (Songs/Show), `.sbn-prog-detail-header` (Progressions/Show), `.sbn-rhythm-show-header` (Rhythms/Show), `.sbn-product-main` (Shop/Show).

---

## CATEGORY GRADIENT HEADER

**Established 2026-05-09.** Full-colour gradient pill for section headings in library listings.

```css
.sbn-category-header {
  color: #fff;
  font-size: 13px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.07em;
  padding: 10px 16px;
  border-radius: var(--radius);
  background: var(--cat-color, var(--clr-style-default));
}

/* Gradient: full colour left → 40% tint right */
.sbn-category-header--bossa {
  --cat-color: linear-gradient(100deg,
    var(--clr-style-bossa),
    color-mix(in srgb, var(--clr-style-bossa) 40%, white));
}
/* repeat for each styleSlug */
```

Count badge inside the header:
```html
<span class="sbn-category-count">12</span>
```
```css
.sbn-category-count {
  font-weight: 500;
  font-size: 12px;
  opacity: 0.8;
  background: rgba(255,255,255,0.2);
  padding: 1px 7px;
  border-radius: 999px;
}
```

Currently used in: `Pages/Library/Rhythms/Index.vue`.

---

## CHORD DIAGRAM CARD SYSTEM

**Established 2026-04-08.** All chord diagram cards across the entire app share one visual system.

### The card hierarchy

```
.sbn-diagram-card   — base shell (all non-interactive contexts: library display, grid display)
.sbn-vp-card        — interactive shell (voicing picker, any clickable card)
.sbn-chord-card     — full public card (Phase 8+: play button, popularity, difficulty)
```

### Key principle: cards have NO fixed width

Size is always controlled by the **parent grid**, never by the card itself. Use CSS grid
`minmax()` / `repeat()` / `fr` units on the container to size cards.

### SVG diagram renderer

```javascript
// In chords.js — use for all diagram contexts
const svg = sbnRenderDiagramSVG(voicing);
// voicing = { frets: "x32010", position: 1 } or { fret_string, start_fret }
// Returns SVG string with viewBox="0 0 80 95", width="100%"
// Background is TRANSPARENT — the card shell provides the white bg

// In Alpine templates:
renderMiniDiagram(voicing) { return sbnRenderDiagramSVG(voicing); }
```

**Fret string encoding:** Fret strings use hex encoding for frets ≥ 10: `a`=10, `b`=11, `c`=12, etc. The parser in `sbnParseFretString()` uses `parseInt(c, 16)`.

**Never pass a pixel size argument** — sizing is CSS-only via the card shell width.

### HTML fretboard renderer

Used in the chord library where rich display modes (fingering/notes/functions) are needed.
Defined in `sbn-design-system.css §2b`. CSS for dots, barres, and ping animations lives in `chord-library.css`.

```html
<div class="sbn-diagram-card">
  <div class="sbn-chord-fretboard"
       data-diagram='{"positions":[...]}'
       data-start-fret="1"
       data-intervals="R,3,5,7">
  </div>
</div>
```

**Legacy aliases:** `.sbn-fb-*` class names still work via aliases in `chords.css` — all new code should use `.sbn-fretboard-*` / `.sbn-fret-row` / `.sbn-string-space` / `.sbn-finger-position` / `.sbn-barre`.

### Sizing in context

| Context | Grid definition |
|---|---|
| Chord library | `display: grid; grid-template-columns: repeat(auto-fill, minmax(120px, 1fr))` |
| Voicing picker (context panel) | `grid-template-columns: repeat(3, 1fr)` |
| Voicing picker (modal fallback) | `.sbn-ve-modal .sbn-vp-grid { grid-template-columns: repeat(auto-fill, minmax(90px, 1fr)) }` |
| Chord grid | Scoped in `leadsheets.css`: `.sbn-ve-chord-diagram .sbn-diagram-card { max-width: 64px }` |

---

## CHORD PROGRESSION VIEWER (`ChordProgressionViewer.vue`)

**Established 2026-05-11.** The standard, self-contained component for displaying interactive chord progressions across the entire application.

### Key Features
- **Integrated Metadata**: Automatically renders Name, Category, Key, and Roman Numerals.
- **Style-Aware Coloring**: Uses the progression's category to apply design-system compliant colors.
- **Interactive Playback**: Inline global play button and per-chord preview support.

### Component Usage (Vue 3)

```vue
<ChordProgressionViewer
    :chords="tiles"          <!-- Array of { chordName, diagramData, numeral? } -->
    :name="prog.name"
    :category="prog.category"
    :key-label="prog.key"
    :numerals="prog.numerals"
    :color="getCategoryColor(prog.category)"
    :interactive="true"
    :compact="false"
/>
```

Note: `:vintage-card` prop has been **removed**. Theme is controlled globally via `data-theme` on `<html>`.

### Standard Implementation Locations
1. **Progression Detail Page**: `resources/js/Pages/Library/Progressions/Show.vue`
2. **Chord Detail Page**: `resources/js/Pages/Library/Chords/Show.vue`
3. **Song Detail Page**: `resources/js/Pages/Library/Songs/Show.vue`

### Logic Pattern
Every implementation should use the unified resolution pipeline:
1. `HarmonicContext::buildFromNumerals($root, $numerals)`
2. `ProgressionBuilder::buildVoicings($context, $options)`
3. Map output to tiles: `['chordName' => ..., 'diagramData' => ..., 'numeral' => ...]`

---

## PLAY BUTTON (`.sbn-play-btn`)

Global circular transport button. Defined in `sbn-design-system.css §3b`. Used in rhythm players, progression viewer, and the leadsheet transport bar.

### Color system

Set `--play-color` on the **parent wrapper** to tint the button for a category. If not set, falls back to `--clr-accent` (orange). For the transport bar, `--play-color` is pinned to `#f39c12` (orange) and `--play-bg-playing` to `var(--clr-gradient)` so page-level accent overrides cannot bleed in.

```html
<!-- Default: orange -->
<button class="sbn-play-btn" :class="{ 'is-playing': playing }">
  <svg>...</svg>
</button>

<!-- Category-tinted: set --play-color on the wrapper -->
<div :style="{ '--play-color': categoryColor }">
  <button class="sbn-play-btn" :class="{ 'is-playing': playing }">...</button>
</div>
```

### States

| State | Appearance |
|---|---|
| Default | White bg, `--play-color` border + icon |
| Hover | Faint `--play-color` tint bg, scale 1.08 |
| Playing | Solid `--play-bg-playing` (or `--play-color`) fill, white icon, glow ring |

### Size overrides

| Context | Class | Size |
|---|---|---|
| Default | `.sbn-play-btn` | 36×36px |
| RhythmStrip | `.sbn-rhythm-strip-play` | 32×32px (scoped) |
| RhythmPattern mini | `.is-mini .sbn-rhythm-play-btn` | 30×30px (scoped) |
| Transport bar | `.sbn-transport-play` | 48×48px |

### Rules

- Always use SVG icons — never emoji or text characters.
- Never redefine color/state in scoped styles — only size and margin offsets are allowed.
- Components that pass a `color` prop must set `--play-color` on the wrapper alongside any other color variables (`--strip-color`, `--prog-color`).
- Do not override `--clr-accent` at page level to change play button color — it breaks the transport bar and other components. Use `--play-color` on the specific wrapper instead.

---

## SHOW PAGE LAYOUT SYSTEM

**Established 2026-05-28.** Shared layout utilities for all library detail (show) pages. Defined in `sbn-design-system.css`.

### Two-column body (`.sbn-show-body`)

All show pages use a header-above + two-column-below layout. The header (`sbn-detail-hero`) sits outside the grid; only the content+sidebar grid uses these classes.

```html
<!-- Full show page structure -->
<div class="sbn-page-detail sbn-[entity]-show">
    <Breadcrumb ... />
    <header class="sbn-detail-hero sbn-[entity]-header">...</header>
    <div class="sbn-show-body">
        <div class="sbn-show-main">
            <!-- main content sections -->
        </div>
        <aside class="sbn-show-sidebar">
            <div class="sbn-show-sidebar-card">
                <h3 class="sbn-show-sidebar-heading">Related items</h3>
                <!-- link list -->
            </div>
        </aside>
    </div>
</div>
```

| Class | Role |
|---|---|
| `.sbn-show-body` | `grid: 1fr 320px`, `gap: 32px`, `align-items: start` |
| `.sbn-show-main` | Left column — `min-width: 0` |
| `.sbn-show-sidebar` | Right column — `min-width: 0`, `position: sticky; top: 80px` |
| `.sbn-show-sidebar-card` | White bordered card inside sidebar — `padding: 20px` |
| `.sbn-show-sidebar-heading` | Uppercase eyebrow label — `0.82em`, `700`, `uppercase`, `letter-spacing: 0.06em` |

Responsive: at `max-width: 1024px` the grid collapses to single column and sidebar `position` resets to `static`.

Currently applied to: `Progressions/Show.vue`, `Rhythms/Show.vue`.

---

## SHOW PAGE HERO HEADER

**Established 2026-05-28.** Unified header anatomy for rhythm, progression, and song show pages. All use `.sbn-detail-hero` as the frame, with internal structure using shared design system classes.

```html
<header class="sbn-detail-hero sbn-[entity]-header" :style="{ '--category-color': color }">
    <!-- 1. Top badge row: filled cat badge + popularity + hashtags -->
    <div class="sbn-show-hero-badges">
        <span class="sbn-cat-badge sbn-cat-badge-filled" :style="{ '--cat-clr': color }">Jazz</span>
        <span class="sbn-card-pop sbn-pop-common">Common</span>
        <span class="sbn-hashtag">#ii-V-I</span>
    </div>
    <!-- 2. Title -->
    <h1 class="sbn-show-hero-title">II-V-I</h1>
    <!-- 3. Optional subtitle (muted, one-liner) -->
    <p class="sbn-show-hero-subtitle">Jazz chord progression • Major</p>
    <!-- 4. Meta chip row (light grey key/value chips) -->
    <div class="sbn-show-hero-meta">
        <span class="sbn-meta-chip"><strong>Tonality</strong> Major</span>
        <span class="sbn-meta-chip"><strong>Chords</strong> 3</span>
    </div>
</header>
```

| Class | Role |
|---|---|
| `.sbn-show-hero-badges` | Flex row, `gap: 8px`, `margin-bottom: 12px` |
| `.sbn-show-hero-title` | `h1` — `2em`, `800`, `letter-spacing: -0.02em` |
| `.sbn-show-hero-subtitle` | Muted one-liner — `1em`, `color: var(--clr-text-muted)` |
| `.sbn-show-hero-meta` | Flex wrap chip row |
| `.sbn-meta-chip` | Light grey chip — `background: var(--clr-surface-2)`, `0.82em`. Also aliased as `.sbn-song-meta-chip`. |

---

## SHOW PAGE SECTION HEADINGS

**Established 2026-05-28.** Shared heading row pattern for all sections inside show pages. Replaces per-page scoped heading styles.

```html
<!-- Heading with "View all" link -->
<div class="sbn-section-heading-row">
    <h2 class="sbn-section-heading">Songs</h2>
    <a href="/library/songs" class="sbn-section-link">View all →</a>
</div>

<!-- Heading only (no link) -->
<div class="sbn-section-heading-row">
    <h2 class="sbn-section-heading">Other Maj7 Voicings</h2>
</div>
```

| Class | Role |
|---|---|
| `.sbn-section-heading-row` | Flex row, space-between, border-bottom `2px solid var(--clr-border)`, `margin-bottom: 16px` |
| `.sbn-section-heading` | `1.05em`, `700`, `color: var(--clr-text)` |
| `.sbn-section-link` | `0.85em`, muted, no-underline, `flex-shrink: 0` |

`MediaShelf` uses these classes directly for its title row — do not re-implement per-page.

---

## HORIZONTAL CARD SCROLL ROW

**Established 2026-05-28.** Shared pattern for horizontally scrollable card rows. Scroll buttons appear/hide based on actual overflow (driven by `ResizeObserver` + scroll event). Used for chord siblings on chord show, chords on song show, and inside `MediaShelf`.

```html
<div class="sbn-card-scroll-wrap" style="max-width: calc(4 * 110px + 3 * 12px)">
    <div ref="scrollEl" class="sbn-card-scroll">
        <a v-for="item in items" class="sbn-card-scroll-item" href="...">
            <!-- card -->
        </a>
    </div>
    <button v-show="canScrollLeft"  class="sbn-card-scroll-btn sbn-card-scroll-btn--prev" @click="scroll(-1)">‹</button>
    <button v-show="canScrollRight" class="sbn-card-scroll-btn sbn-card-scroll-btn--next" @click="scroll(1)">›</button>
</div>
```

| Class | Role |
|---|---|
| `.sbn-card-scroll-wrap` | `position: relative` — anchor for absolute buttons. Set `max-width` locally to cap visible cards. |
| `.sbn-card-scroll` | Flex row, `overflow-x: auto`, `scroll-snap-type: x mandatory`, hidden scrollbar |
| `.sbn-card-scroll-item` | `flex: 0 0 110px`, `scroll-snap-align: start` |
| `.sbn-card-scroll-btn` | 28px circle button, `box-shadow: 0 1px 4px rgba(0,0,0,0.1)` |
| `.sbn-card-scroll-btn--prev` | `left: -14px` |
| `.sbn-card-scroll-btn--next` | `right: -14px` |

**Scroll state pattern** (required in every component that uses manual buttons):
```ts
const canLeft  = ref(false);
const canRight = ref(false);
function updateScroll() {
    const el = scrollEl.value;
    if (!el) return;
    canLeft.value  = el.scrollLeft > 0;
    canRight.value = el.scrollLeft + el.clientWidth < el.scrollWidth - 1;
}
// Wire to: el.addEventListener('scroll', updateScroll) + new ResizeObserver(updateScroll)
```

`MediaShelf` handles this internally — no extra setup needed when using that component.

---

## LIBRARY LINK COMPONENTS

**Established 2026-05-27, extended 2026-05-28.** Canonical Vue components for cross-linking library entities. Never hand-roll entity rows on show pages — use these.

### `ProgressionLink.vue` (`Components/Library/ProgressionLink.vue`)

Row component for linking to a progression. Bordered card with left colour stripe keyed to category.

```vue
<ProgressionLink :progression="prog" />
<!-- From chord detail — pins chord + slot into query string -->
<ProgressionLink :progression="{ ...prog, pinnedChordSlug: chord.slug, pinnedSlot: 0 }" />
```

**`ProgressionLinkData` interface:**
```ts
interface ProgressionLinkData {
    id: number; slug: string; name: string; category: string;
    numeralsDisplay: string;
    pinnedChordSlug?: string | null;
    pinnedSlot?: number | null;
}
```

CSS: `.sbn-prog-link` in `sbn-design-system.css`. Uses `--prog-clr` for the left border stripe.

### `RhythmLink.vue` (`Components/Library/RhythmLink.vue`)

**New 2026-05-28.** Row component for linking to a rhythm pattern. Bordered card with left colour stripe, name+meta header, and an inline `RhythmStrip`.

```vue
<RhythmLink :rhythm="rhythm" />
```

**`RhythmLinkData` interface:**
```ts
interface RhythmLinkData {
    id: number; slug: string; name: string; category: string; styleSlug: string;
    bpm: number; timeSignature: string;
    playerData: RhythmPatternData;
}
```

Backend: `RhythmPattern::styleSlug()` maps rhythm category → style slug for the color. Used in: rhythm show sidebar, course show sidebar.

**Note on flat vs nested data:** `RhythmPatternWithMeta` (from rhythm library) is flat — pass as `{ ...sibling, playerData: sibling }`. `RhythmRef` (from course show) is already nested.

### `SongLink.vue` / `SongShelfCard.vue`

`SongLink.vue` — type source only. Import `SongLinkData` when you need the interface. Do not render it directly.

`SongShelfCard.vue` — 160px square image card for use inside `MediaShelf`. Hover slides up title + composer + popularity. Accepts `SongShelfCardData` (= `SongLinkData`). Add `noNav` prop to `ChordCard` when wrapping in `<Link>` to prevent double navigation.

**`SongLinkData` interface** (matches `Leadsheet::toLinkArray()`):
```ts
interface SongLinkData {
    id: number; slug: string; title: string; styleSlug: string;
    coverImagePath: string | null; composer: string | null; popularity: number | null;
}
```

### `CourseShelfCard.vue` (`Components/Course/CourseShelfCard.vue`)

160px square card. Genre badge always visible top-left; hover overlay slides up title + level label.

**`CourseShelfCardData` interface:**
```ts
interface CourseShelfCardData {
    id: number; slug: string; title: string; primaryGenre: string | null;
    primaryLevel: string | null; lessonCount: number; featuredImagePath: string | null;
}
```

Backend serializer: `Course::toShelfArray()`.

### `MediaShelf.vue` (`Components/Library/MediaShelf.vue`)

**Updated 2026-05-28.** Horizontal scroll container. Scroll buttons float on the track (absolutely positioned), appear/hide via `ResizeObserver`. Header uses `sbn-section-heading-row` / `sbn-section-heading` / `sbn-section-link` from the design system.

```vue
<!-- With "View all" link -->
<MediaShelf title="Related Courses" view-all-href="/learn">
    <CourseShelfCard v-for="course in courses" :key="course.id" :course="course" />
</MediaShelf>

<!-- Without link -->
<MediaShelf title="Used in songs">
    <SongShelfCard v-for="song in songs" :key="song.id" :song="song" />
</MediaShelf>
```

Props: `title` (string) — renders heading row. `viewAllHref` (string, optional) — renders "View all →" link on the right. No `#heading` slot — use `title` + `viewAllHref` props instead.

---

## LIBRARY INDEX CARDS

**Updated 2026-05-28.**

### `CourseCard.vue` (`Components/Course/CourseCard.vue`)

Used in the course index grid (`sbn-courses-grid` / `sbn-courses-carousel`). 1:1 square image with inset padding on card. No overlay — hover reveals a white "View Course →" button that pops up from center. Border frame on card (`1px solid var(--clr-border)`), shifts to `var(--clr-text-muted)` on hover (no shadow).

Card body: grey `sbn-badge sbn-badge-muted` with stars + `difficultyLabel` above the title. No lesson count.

**Grid:** `repeat(3, 1fr)`, `gap: 24px`. Breakpoints: 2-col at ≤900px, 1-col at ≤600px. Defined in `public/css/course-player.css`.

### `SongCard.vue` (`Components/Library/SongCard.vue`)

Used in the song library grid (`sbn-songs-grid`). 1:1 square image, `padding: 10px 10px 0` on card so image floats inset with rounded corners. No overlay — hover scales image only. Button "View Song →" pops up on hover (no color tint behind it).

Card body: grey `sbn-badge sbn-badge-muted` with stars + `difficultyLabel` above title (only shown when `difficulty` is set). No key/tempo/time pills.

**`SongCardData` interface** includes `difficulty: number | null` — serialized by `SongLibraryController::serializeSong()`.

**Grid:** `repeat(3, 1fr)`, `gap: 24px`. Breakpoints: 2-col at ≤900px, 1-col at ≤600px. Defined in `public/css/song-library.css`.

---

## SHOW PAGE HERO — FULL-BLEED IMAGE

**Established 2026-05-28.** Song Show and Course Show use a full-bleed background image hero. The image is absolutely positioned, anchored right, and fades out via a left-to-right gradient overlay so text is always readable.

```html
<header class="sbn-ss-hero sbn-detail-hero">
  <!-- Background image (or gradient fallback) -->
  <img :src="imagePath" class="sbn-ss-hero-bg" />
  <div class="sbn-ss-hero-overlay" />

  <!-- Text content sits above at z-index 2 -->
  <div class="sbn-ss-hero-text">...</div>
</header>
```

Key CSS rules:
- `.sbn-ss-hero-bg` — `position: absolute; top/bottom: 0; left: 20%; right: -80px; width: calc(80% + 80px); object-fit: cover` — image covers right ~80% and bleeds past the right border
- `.sbn-ss-hero-overlay` — `linear-gradient(to right, white 30%, semi-transparent 65%, near-transparent 100%)` — fades the image from left
- `.sbn-ss-hero-text` — `position: relative; z-index: 2; max-width: 620px`
- Fallback (`--fallback` modifier): `background: var(--category-gradient)`
- Mobile (≤768px): gradient flips to top→bottom

Same pattern used in `Courses/Show.vue` with `.sbn-cs-hero-bg` / `.sbn-cs-hero-overlay` / `.sbn-cs-hero-text` class names.

---

## CATEGORY GRADIENT UTILITY

**Established 2026-05-27.** Avoids repeating the 5-line `--category-color` / `--category-gradient` block in every card component.

Add `.sbn-has-category-gradient` to any element. Then `getCategoryStyle()` sets `--category-color` via inline style and `var(--category-gradient)` is available to all children.

```vue
<div class="sbn-my-card sbn-has-category-gradient" :style="getCategoryStyle(styleSlug)">
    <div class="fallback" />  <!-- background: var(--category-gradient) -->
</div>
```

Currently applied to: `SongShelfCard`, `CourseShelfCard`, `.sbn-song-show` (Songs/Show hero).

**Rule:** Do not re-declare `--category-color` / `--category-gradient` in scoped component styles. Add `sbn-has-category-gradient` to the element instead.

---

## CHORD URL BUILDER (`composables/useChordUrl.ts`)

**Established 2026-05-27.** Single source of truth for building chord detail page URLs.

```ts
import { chordShowUrl } from '@/composables/useChordUrl';

// In template or computed:
chordShowUrl(chord)   // → "/library/chords/cmaj7-drop2?root=F%23"
```

Rules encoded in `chordShowUrl(chord: ChordUrlShape)`:
- Rootless voicings → always `?root=C`
- Transposed shapes (`transposed_from != null`) → `?root=<encoded>`
- Any non-C root → `?root=<encoded>`
- C-root shapes → no param

**Never build chord URLs by hand** — `encodeURIComponent` is required for `#`/`b` in root names.

Currently used in: `Pages/Library/Songs/Show.vue`, `Pages/Library/Chords/Index.vue`, `Pages/Top10/BossaNovaChords.vue`.

---

## BUTTONS

Base class `.sbn-btn` is always required. Add one color modifier. Defined exclusively in `sbn-design-system.css` — do not redefine in module CSS files.

```html
<!-- Primary — orange/red gradient. Main CTAs. -->
<button class="sbn-btn sbn-btn-primary">Save</button>

<!-- Secondary — white bg, border. Neutral. -->
<button class="sbn-btn sbn-btn-secondary">Cancel</button>

<!-- Ghost — transparent. Inline or tight spaces. -->
<button class="sbn-btn sbn-btn-ghost">Dismiss</button>

<!-- Accent — orange tint. Softer than primary. -->
<button class="sbn-btn sbn-btn-accent">Apply</button>

<!-- Danger — red. Destructive only. -->
<button class="sbn-btn sbn-btn-danger">Delete</button>

<!-- Size modifiers (combine with any color variant) -->
<button class="sbn-btn sbn-btn-primary sbn-btn-lg">Large</button>
<button class="sbn-btn sbn-btn-secondary sbn-btn-sm">Small</button>
<button class="sbn-btn sbn-btn-ghost sbn-btn-xs">Tiny</button>

<!-- Icon-only square button -->
<button class="sbn-btn sbn-btn-secondary sbn-btn-icon">
  <svg>...</svg>
</button>
```

### Pill toggle / segmented control

```html
<div class="sbn-pill-group">
  <button class="sbn-pill is-active">Shell</button>
  <button class="sbn-pill">Drop 2</button>
  <button class="sbn-pill">Any</button>
</div>

<!-- Softer active state (no gradient) -->
<button class="sbn-pill is-active-soft">Extensions</button>
```

---

## PRIMARY TABS  (`.sbn-ve-tabs` pattern)

The canonical tab bar for **main view switching** — leadsheet editor, and any future full-page editor that has 2–4 top-level views.

**Visual spec:**
- Tab bar background: `--clr-surface-3` (grey tray)
- Tab bar has a full border + rounded top corners, `border-bottom: none`
- A `box-shadow: 0 1px 0 0 var(--clr-border)` hairline runs across the bottom
- Each tab sits `bottom: -1px` to overlap that hairline
- Active tab: white bg, full border, `border-bottom-color: --clr-white` to punch through the hairline
- Active tab accent: `::before` pseudo-element, `height: 3px`, `background: var(--clr-gradient)`
- Content panels have `border-top: none`

```html
<div class="sbn-ve-tabs">
  <button class="sbn-ve-tab is-active">Chords</button>
  <button class="sbn-ve-tab">Analysis</button>
  <button class="sbn-ve-tab">Tab</button>
</div>
<div class="sbn-ve-grid"> ... </div>
```

**Rules:**
- Use this pattern only for **primary view switching** (2–4 views, full-width panel below)
- Use `.sbn-tabs` / `.sbn-tab` / `.sbn-tab-active` (from `chords.css`) for **secondary tabs** within a page
- Never use both patterns on the same page at the same level

---

## BADGES

Defined exclusively in `sbn-design-system.css`. Do not redefine in module CSS files.

```html
<!-- Neutral -->
<span class="sbn-badge sbn-badge-muted">Root position</span>

<!-- Accent (orange) -->
<span class="sbn-badge sbn-badge-accent">Featured</span>

<!-- Semantic -->
<span class="sbn-badge sbn-badge-success">Matched</span>
<span class="sbn-badge sbn-badge-warning">Pending</span>
<span class="sbn-badge sbn-badge-error">Unmatched</span>

<!-- Music style -->
<span class="sbn-badge sbn-badge-style-bossa">Bossa Nova</span>
<span class="sbn-badge sbn-badge-style-jazz">Jazz</span>
<span class="sbn-badge sbn-badge-style-latin">Latin</span>

<!-- Dynamic category (set --cat-clr via inline style or Alpine) -->
<span class="sbn-cat-badge" style="--cat-clr: #8b5cf6;">II-V-I</span>

<!-- Count badge (number pill) -->
<span class="sbn-count-badge">12</span>
<span class="sbn-count-badge sbn-count-badge-warn">3</span>
```

---

## FORM ELEMENTS

```html
<label class="sbn-label">Song Key</label>
<input class="sbn-input" type="text" placeholder="e.g. Dm">
<select class="sbn-select">...</select>
<textarea class="sbn-textarea"></textarea>
<span class="sbn-field-hint">Enter the key signature.</span>
<span class="sbn-field-error">This field is required.</span>
```

---

## EXISTING GLOBAL COMPONENTS

Components defined in `sbn-design-system.css` and available everywhere. Do not redefine in module files.

| Class | Description |
|---|---|
| `.sbn-page` | Library index page shell — `max-width: 1400px`, `padding: 40px 20px 80px` |
| `.sbn-page-detail` | Detail/show page shell — `max-width: 1100px`, `padding: 40px 20px 80px` |
| `.sbn-btn` + variants | Buttons — all variants and sizes |
| `.sbn-badge` + variants | Inline badges — all semantic and style variants |
| `.sbn-card`, `.sbn-card-lg`, `.sbn-card-section` | Panel cards |
| `.sbn-chord-card` + variants | Full chord library card shell |
| `.sbn-rhythm-card` | Rhythm library grid card frame |
| `.sbn-pattern-row` + modifiers | Rhythm pattern row (course player + library) |
| `.sbn-diagram-card`, `.sbn-vp-card` | Chord diagram card shells |
| `.sbn-breadcrumb` + `--cat` / `--brand` | Gradient breadcrumb band (use `Breadcrumb.vue`) |
| `.sbn-detail-hero` | Flat-top bordered white box that connects flush beneath breadcrumb |
| `.sbn-show-body` / `.sbn-show-main` / `.sbn-show-sidebar` | Two-column show page grid |
| `.sbn-show-sidebar-card` / `.sbn-show-sidebar-heading` | Sidebar card frame + eyebrow label |
| `.sbn-show-hero-badges` / `.sbn-show-hero-title` / `.sbn-show-hero-meta` | Show page hero header anatomy |
| `.sbn-meta-chip` (alias: `.sbn-song-meta-chip`) | Light grey key/value chip |
| `.sbn-section-heading-row` / `.sbn-section-heading` / `.sbn-section-link` | Section heading row with optional "View all →" |
| `.sbn-card-scroll-wrap` / `.sbn-card-scroll` / `.sbn-card-scroll-item` / `.sbn-card-scroll-btn` | Horizontal card scroll row + prev/next buttons |
| `.sbn-back-link` | Back navigation link |
| `.sbn-surface-dim` | Dimmed surface (metadata, hints) |
| `.sbn-callout` | Info callout (accent left border) |

Components defined in `admin2.css` (admin layout only):

| Class | Description |
|---|---|
| `.sbn-table`, `.sbn-table-wrap` | Standard data table |
| `.sbn-filter-bar`, `.sbn-search-input`, `.sbn-search-wrap` | Filter row above tables |
| `.sbn-pagination` | Page nav |
| `.sbn-empty` | Empty state container |
| `.sbn-flash` | Server-side flash messages |
| `.sbn-content-layout`, `.sbn-context-panel` | Two-column layout with right sticky panel |
| `.sbn-stat-card` | Admin dashboard stat card |

Other globally loaded:

| Class | File | Description |
|---|---|---|
| `.sbn-toast` | `leadsheets.css` | JS toast — use `sbnToast(msg, type)` from `chords.js` |
| `.sbn-chord-symbol` + parts | `chord-symbols.css` | Chord name typography |

---

## EXISTING MODULE COMPONENTS (per-file)

### chord-symbols.css (loaded globally)
- `.sbn-chord-symbol`, `.sbn-chord-root`, `.sbn-chord-quality`, `.sbn-chord-accidental`, `.sbn-chord-ext`, `.sbn-chord-bass`
- **Do not** add chord name styling anywhere else.

### chords.css
- Library-only card extensions: `.sbn-diagram-card-header`, `.sbn-shape-title-row`, `.sbn-shape-quality`, `.sbn-shape-ext`, `.sbn-shape-inv`, `.sbn-shape-bass`, `.sbn-diagram-preview`, `.sbn-diagram-actions`
- Legacy fretboard aliases: `.sbn-fb-*` → aliased to `.sbn-fretboard-*` equivalents (do not use in new code)
- `.sbn-chord-fretboard` — container for HTML fretboard
- `.sbn-tabs`, `.sbn-tab`, `.sbn-tab-active` — secondary tab toggle (Library / Unmatched)
- **Base card shell** (`.sbn-diagram-card`) is in `sbn-design-system.css §2`, NOT in chords.css

### leadsheets.css
- Leadsheet index table, filter bar, stat row
- All leadsheet editor styles
- **Beat-grid layout** (chord grid, 2026-04-18):
  - `.sbn-ve-grid .sbn-ve-measure-content` — `position: relative; min-height: 148px; padding-bottom: 18px`
  - `.sbn-ve-beat-grid` — absolute bottom layer, `height: 18px`, holds dot elements
  - `.sbn-ve-beat-tick` — 9px circle, centred at `(b-0.5)/bpm * 100%`; `.beat-active` — orange fill + `sbn-beat-pulse` animation
- Chord grid overrides: `.sbn-ve-grid .sbn-ve-chord-name`
- Card sizing in grid: `.sbn-ve-chord-diagram .sbn-diagram-card { max-width: 80px; padding: 2px 4px }`
- Density tier diagram sizing: `.double` (64px), `.multi` (52px), `.dense` (36px)
- Density tier chord name sizing: double=17px, multi=14px, dense=10px
- SVG aspect ratio: `.sbn-chord-svg { aspect-ratio: 80/95 }`
- Playback active chord: `.sbn-ve-chord.is-active { box-shadow: inset 0 0 0 2px var(--clr-accent) }`
- Tab playback metronome column: `.sbn-tab-metronome-col { fill: var(--clr-accent); opacity: 0.1 }`
- Tab playback beat note: `.sbn-tab-note-text.sbn-beat-active { fill: #ef4444 !important }`
- Tab playing measure: `.sbn-tab-measure--playing { background: none; box-shadow: none }`
- Paste target: `.sbn-ve-chord.is-paste-target` blue tint
- Toast: `.sbn-toast`, `.sbn-toast-*`

### progressions.css
- `.sbn-prog-cat-pill`, `.sbn-prog-cat-badge` — category filters and badges
- `.sbn-prog-tonality` — small tonality label (major/minor)
- `.sbn-prog-numerals` — mono Roman numeral display
- `.sbn-occ-*` — occurrence list (collapsible per-song groups)
- Note: `.sbn-cat-badge` was moved to `sbn-design-system.css` (badge centralisation 2026-05-25)

### voicings.css
- Voicing crossref page: stats, draft cards, draft groups
- `.sbn-draft-card` — similar to `.sbn-diagram-card` but with meta row and actions

### progression-builder.css
- `.sbn-pb-row` — flex row, no border
- `.sbn-pb-row .sbn-ve-chord-diagram .sbn-diagram-card` — `max-width: 80px; padding: 2px 4px`
- `.sbn-pb-numeral` — Roman numeral below chord name

---

## CHORD GRID INTERACTION MODEL

Both the leadsheet chord grid and progression builder grid share the same base visual system from `sbn-design-system.css §8`.

### Hover and selection frames

| State | Element | Method | Color | Thickness |
|-------|---------|--------|-------|-----------|
| Hover | `.sbn-ve-chord` | `::before` z-index 2 | `--clr-accent` (orange) | 1px inset box-shadow |
| Chord selected | `.sbn-ve-chord.is-selected` | `outline` + bg tint | blue `#1976d2` | 2px outline, -2px offset, `rgba(25,118,210,0.14)` bg |
| Playback active | `.sbn-ve-chord.is-active` | `box-shadow` only, no bg | `--clr-accent` (orange) | 2px inset |
| Drag source | `.sbn-ve-measure.is-dragging` | opacity 0.3 | — | — |
| Drag target | `.sbn-ve-measure.is-drag-target` | bg tint | `rgba(25,118,210,0.07)` | — |
| Drop gap before | `.sbn-ve-measure.drop-gap-before` | padding-left + border-left | `--clr-accent` blue | 3px border |
| Drop gap after | `.sbn-ve-measure.drop-gap-after` | padding-right + border-right | `--clr-accent` blue | 3px border |

**Rules:**
- No background tints on hover — orange frame only.
- `::before` and `::after` on `.sbn-ve-measure` are fully reserved for barlines.
- Drop gap uses `padding` (not `margin`) so the measure's hit area stays intact.
- Selection lives on chord cards (`.sbn-ve-chord`) — NOT on `.sbn-ve-measure`.

### Barlines

Barlines use pseudo-elements on `.sbn-ve-measure`, defined in `sbn-design-system.css §8`:

```css
.sbn-ve-measure::after {
    content: ''; position: absolute;
    right: 0; top: 10%; height: 70%; width: 1px;
    background: var(--clr-text-muted);
}
.sbn-ve-measure::before {
    content: ''; position: absolute;
    left: 0; top: 10%; height: 70%; width: 1px;
    background: var(--clr-text-muted);
}
.sbn-ve-row .sbn-ve-measure:first-of-type::before,
.sbn-pb-row .sbn-ve-measure:first-of-type::before {
    width: 2px;
}
```

### Chord density tiers

| Class | Condition | Chord name | Diagram max-width |
|-------|-----------|------------|-------------------|
| (none) | 1 chord | 20px (DS base) | 80px |
| `.double` | exactly 2 chords | 18px | 64px |
| `.multi` | 3–4 chords | 15px | 52px |
| `.dense` | 5+ chords | 12px | 36px |

---

## PHASE D — VIDEO SYNC

**Completed:** April 2026. Spec: `docs/Phase-D1-Video-Master-Refactor.md`, `docs/Phase-D2-Authoring-Spec.md`.

### Architecture overview

Video sync is a single-video-per-leadsheet feature. The sync data lives in `json_data.videoSync`:

```json
{
  "videoId":   "dQw4w9WgXcQ",
  "videoType": "youtube",
  "mappings":  [{ "measureIndex": 0, "videoTime": 4.2 }, ...]
}
```

### Files

| File | Role |
|------|------|
| `resources/js/tab-editor/composables/useVideoSync.js` | All sync state + authoring mutations |
| `resources/js/tab-editor/components/VideoSyncEditor.vue` | Sidebar UI: video ID, player, tap controls, rate buttons, mapping table |
| `resources/js/Components/Library/Video/VideoEmbed.vue` | Shared YouTube / hosted `<video>` wrapper; emits `timeupdate` at 60fps via rAF |
| `resources/js/tab-editor/components/SyncPointBadge.vue` | Draggable orange circle overlay on measure barlines |
| `resources/js/tab-editor/TabEditor.vue` | Provides inject keys; wires VideoSyncEditor events; transport logic |

### Inject keys (provided by TabEditor)

| Key | Type | Value |
|-----|------|-------|
| `videoSyncMap` | `ComputedRef<Map<measureIndex, {videoTime, markerIndex}> \| null>` | `null` when sidebar closed |
| `nudgeSyncMapping` | `(measureIndex: number, delta: number) => void` | Adjusts a mapping's `videoTime`, undoable |
| `tapCursor` | `ComputedRef<number>` | Currently targeted measure for tap-to-mark |
| `seekToMeasure` | `(gi: number) => void` | Seeks audio + video to measure `gi` |

### Clock modes

| Mode | Condition | Clock source |
|------|-----------|--------------|
| **Video master** | `audioSource === 'video'` AND `videoId` set | YouTube rAF loop → `videoMeasureIndex` → `transportBeat` |
| **Synth master** | otherwise | Tone.js scheduler → `currentBeat` → `transportBeat` |

### D2 — Authoring keyboard shortcuts

| Key | Action |
|-----|--------|
| `Space` | Toggle playback |
| `M` | Mark at tapCursor, advance |
| `Shift+M` | Remove last mark, rewind tapCursor |
| `←` / `→` | Nudge video −/+ 2s |
| `Shift+←` / `Shift+→` | Nudge video −/+ 10s |
| `,` / `.` | Decrease / increase playback rate |

### SyncPointBadge

22px circle, orange→red radial gradient, white bold number, `ew-resize` cursor. Centered on measure's left barline (`left: 0; transform: translateX(-50%)`).

- Chord view: `top: 6px`
- Tab view: `top: 57px`
- Click: `seekToMeasure(measureIndex)`
- Drag: live-updates time; on release commits via `nudgeSyncMapping` (undoable). `SECS_PER_PX = 0.05`.

---

## CONVENTIONS

1. **Never hardcode color hex values** in module CSS. Use `--clr-*` variables.
2. **Never redefine** `:root` color tokens in module CSS files.
3. **Never create** a new button style. Use `.sbn-btn` + modifier from the design system.
4. **Never create** a new badge style. Use `.sbn-badge` + modifier from the design system.
5. **Chord names** always go through `chord()` (PHP) or `sbnFormatChordHtml()` (JS).
6. **Diagram cards** always use white background — `.sbn-diagram-card` or `.sbn-vp-card`. Never add a `width` — size via parent grid.
7. **SVG diagrams** never have hardcoded dimensions or a background rect — use `sbnRenderDiagramSVG()`.
8. **CSS class prefix:** all classes must start with `sbn-`. No bare element selectors.
9. **New module CSS files:** scope all selectors to a module-specific prefix (e.g., `.sbn-pb-*`). Add a comment stating which layout file loads it.
10. **Music style colors** use `--clr-style-*` variables or `.sbn-badge-style-*` classes.
11. **Card frames must be global.** Vue `<style scoped>` cannot be reached by `[data-theme]` selectors. Card frames (borders, shadows, hover transitions) belong in `sbn-design-system.css` as global classes. Scoped styles may only contain layout/structural rules.
12. **No `--radius-md`** — this token does not exist. Use `--radius` (10px) as the mid-size token.
13. **No new `--sbn-*` tokens** — `--sbn-*` in `frontend/base.css` are aliases only. New tokens go under `--clr-*`, `--radius-*`, `--font-*`, or `--ease`.
14. **Never override `--clr-accent` at page level.** It cascades into play buttons, transport bars, beat markers, and every other accent-colored component. To tint a specific component for a category, set `--play-color` (play buttons) or the component's own context variable on that wrapper only.
15. **Play button scoped styles: size and margin only.** Never redeclare color, border, background, or transition in a scoped play button rule — those live exclusively in `sbn-design-system.css §3b`.

---

## FILE MAP (CSS files)

```
public/css/
  sbn-design-system.css   ← tokens + ALL card frames + base components (load first on every layout)
                            §2   diagram card shells: .sbn-diagram-card, .sbn-vp-card
                            §2b  HTML fretboard: .sbn-fretboard-*, .sbn-finger-position, .sbn-barre
                            §2c  full chord card: .sbn-chord-card (shell only)
                            §2d  rhythm card: .sbn-rhythm-card (frame — global for theme switching)
                            §2e  pattern row: .sbn-pattern-row + style modifiers
                            §3   buttons: .sbn-btn + all variants
                            §3b  play button: .sbn-play-btn — global circular transport button
                            §4   badges: .sbn-badge + all variants
                            §5   breadcrumb: .sbn-breadcrumb, --cat, --brand, sub-classes
                            §5b  detail hero: .sbn-detail-hero — flat-top bordered white box
                            §8   chord grid: .sbn-ve-measure, barlines, hover/selection frames
                            [data-theme="vintage"] overrides at bottom of file
  chord-symbols.css       ← chord name typography (load second, global)
  admin2.css              ← admin shell: sidebar, topbar, tables, layout (admin only)
  chord-library.css       ← chord card internals: name, diagram, footer, play btn, badges, animations
  chords.css              ← chord library extensions + legacy sbn-fb-* aliases
  leadsheets.css          ← leadsheets module + all editor styles
  progressions.css        ← progressions module
  progression-library.css ← progression library page
  rhythms.css             ← rhythm patterns module
  voicings.css            ← voicing crossref module
  progression-builder.css ← progression builder

resources/css/
  app.css                 ← imports frontend/ partials (processed by Vite)
  frontend/
    base.css              ← public site base reset + --sbn-* aliases to --clr-* + WooCommerce overrides
    header.css            ← public nav/header layout only
    mega-menu.css         ← mega menu layout only

public/js/
  chords.js               ← chord diagram renderers + fretboard hydration + toast
  sbn-chord-name.js       ← sbnFormatChord() chord name formatter
```

## ADDING NEW STYLES

When adding CSS for a new feature:
1. Check this document — does a component already cover this?
2. If extending an existing component, add a modifier class (e.g., `.sbn-diagram-card--compact`).
3. If the new component will appear in multiple modules, add it to `sbn-design-system.css` and document it here.
4. If it is page-specific, add it to the appropriate module CSS file with `sbn-[module]-[element]` naming.
5. If the new component is a card frame that must be theme-switchable, it **must** go in `sbn-design-system.css` as a global class with its vintage override in the `[data-theme="vintage"]` block.
6. Never use `!important` except to override third-party styles.
7. Never add a `width` to `.sbn-diagram-card` or `.sbn-vp-card` — always size via parent grid.
