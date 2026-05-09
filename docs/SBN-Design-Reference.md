# SBN Teaching Hub — Design Reference

> **Purpose:** Upload alongside `SBN-Migration-Reference.md` at the start of every session.
> This file tells Claude exactly what CSS components exist and how to use them.
> **Rule:** Before writing any new CSS, check this document. If a component exists here, extend it — never rewrite it.

---

## LOAD ORDER (admin layout)

```html
<!-- In admin.blade.php <head>, in this exact order: -->
<link rel="stylesheet" href="{{ asset('css/sbn-design-system.css') }}">
<link rel="stylesheet" href="{{ asset('css/chord-symbols.css') }}">
<link rel="stylesheet" href="{{ asset('css/admin2.css') }}">
<!-- Then page-specific module CSS via @stack('styles') -->

<!-- JS: load chords.js on any page that shows chord diagrams -->
<script src="{{ asset('js/chords.js') }}"></script>
```

The frontend layout (Phase 8+) must also load `sbn-design-system.css` and `chords.js` first.

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
| `--clr-text-muted` | Placeholders, labels, hints |

### Semantic
| Variable | Color |
|---|---|
| `--clr-success` | `#10b981` green |
| `--clr-warning` | `#f39c12` orange (same as accent) |
| `--clr-error` | `#ef4444` red |
| `--clr-red` | `#e74c3c` — finger dots in diagrams |

### Music Style Colors
Used for category badges and any style-specific UI:

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

## VINTAGE OFFSET CARD STYLE

**Established 2026-05-09.** A tactile, music-forward card variant used in library listings.

The key idea: a bold right + bottom border in the category colour, with a matching
box-shadow offset on hover. The card appears to lift off the page diagonally — a nod to
vintage music print design.

### CSS recipe

```css
.my-card {
  border: 1px solid var(--clr-border);
  border-right:  3px solid var(--row-color, var(--clr-border));
  border-bottom: 3px solid var(--row-color, var(--clr-border));
  border-radius: var(--radius);
  background: var(--clr-white);
  transition: box-shadow 0.15s, transform 0.15s;
}

.my-card:hover {
  box-shadow: 3px 3px 0 var(--row-color, var(--clr-border));
  transform: translate(-1px, -1px);
}

/* One modifier per styleSlug sets --row-color */
.my-card--bossa  { --row-color: var(--clr-style-bossa); }
.my-card--jazz   { --row-color: var(--clr-style-jazz); }
.my-card--samba  { --row-color: var(--clr-style-samba); }
.my-card--latin  { --row-color: var(--clr-style-latin); }
.my-card--blues  { --row-color: var(--clr-style-blues); }
.my-card--pop    { --row-color: var(--clr-style-pop); }
.my-card--classical { --row-color: var(--clr-style-classical); }
.my-card--gold   { --row-color: var(--clr-style-gold); }
```

### Rules
- Only the **right and bottom** edges are bold — not all four sides.
- The hover `box-shadow` colour must match the border colour exactly (no blur, no spread).
- `transform: translate(-1px, -1px)` keeps the visual "stamp" effect tight — do not increase.
- Use `--row-color` (or any CSS custom property) to avoid repeating the colour value.
- Currently used in: `Pages/Library/Rhythms/Index.vue` (`.sbn-pattern-row`).

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
`minmax()` / `repeat()` / `fr` units on the container to size cards. This means the same
card class works in any context just by changing the grid definition.

### Base card shell (`.sbn-diagram-card` / `.sbn-vp-card`)

Defined in `sbn-design-system.css §2`. Both classes share the same visual base.
`.sbn-vp-card` adds `cursor: pointer` and `position: relative` for the checkmark.

```html
<!-- Non-interactive display (library, chord grid) -->
<div class="sbn-diagram-card">
  <!-- SVG or HTML fretboard goes here -->
</div>

<!-- Clickable (voicing picker, any selectable card) -->
<div class="sbn-vp-card">
  <!-- SVG diagram goes here -->
</div>

<!-- Selected state -->
<div class="sbn-vp-card is-selected">...</div>

<!-- Modifier variants -->
<div class="sbn-vp-card sbn-vp-card--current">...</div>   <!-- exact tab match (blue) -->
<div class="sbn-vp-card sbn-vp-card--from-tab">...</div>  <!-- from-tab, no library match -->
```

**Visual spec:**
- Background: always `--clr-white`
- Border: `1px solid --clr-border`
- Hover border: `--clr-accent-border` + `--clr-shadow-sm`
- Selected: `border-color: --clr-accent` + `0 0 0 2px --clr-accent-bg` glow + `✓` checkmark
- Border radius: `--radius-sm` (6px)
- Width: **none** — set by parent grid

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

**Fret string encoding:** Fret strings use hex encoding for frets ≥ 10: `a`=10, `b`=11, `c`=12, etc. Example: `"accaaa @10"` = frets 10,12,12,10,10,10 at position 10. The parser in `sbnParseFretString()` uses `parseInt(c, 16)` for the ≤6 char path. The shortcode `[sbn_voicings]` block uses this format.

**Never pass a pixel size argument** — sizing is CSS-only via the card shell width.

### HTML fretboard renderer

Used in the chord library where rich display modes (fingering/notes/functions) are needed.
Defined in `sbn-design-system.css §2b`. Rendered by `sbnRenderFretboard()` + `sbnHydrateFretboard()`.

```html
<!-- Structure rendered by sbnRenderFretboard(data) -->
<div class="sbn-diagram-card">
  <div class="sbn-chord-fretboard"
       data-diagram='{"positions":[...]}'
       data-start-fret="1"
       data-intervals="R,3,5,7">
  </div>
</div>
```

```javascript
// After inserting HTML into DOM:
sbnHydrateAll(container);        // batch — finds all [data-diagram]:not([data-sbn-rendered])
sbnHydrateFretboard(el, data);   // single element
```

**Legacy aliases:** `.sbn-fb-*` class names from the old renderer still work via aliases in
`chords.css` — but all new code should use `.sbn-fretboard-*` / `.sbn-fret-row` / `.sbn-string-space` / `.sbn-finger-position` / `.sbn-barre`.

### Full chord card (`.sbn-chord-card`) — Phase 8

Defined in `sbn-design-system.css §2c`. Full public-facing card with name, diagram, footer
(popularity pill + difficulty stars), and hover controls (play button, optional info button).

```html
<div class="sbn-chord-card">
  <div class="sbn-card-chord-name"><!-- chord name --></div>
  <div class="sbn-card-diagram"><!-- diagram --></div>
  <div class="sbn-card-footer">
    <div class="sbn-card-footer-left">
      <span class="sbn-card-pop sbn-pop-essential">Core</span>
    </div>
    <div class="sbn-card-footer-right">
      <span class="sbn-card-diff">
        <span class="sbn-diff-star filled">★</span>
        <span class="sbn-diff-star filled">★</span>
        <span class="sbn-diff-star">★</span>
      </span>
    </div>
  </div>
  <div class="sbn-card-hover-controls">
    <button class="sbn-play-btn">▶</button>
  </div>
</div>

<!-- Variants -->
<div class="sbn-chord-card sbn-chord-card--detail">...</div>  <!-- larger, no hover lift -->
<div class="sbn-chord-card sbn-chord-card--mini">...</div>    <!-- compact, no footer -->
```

**Popularity pill classes:** `sbn-pop-occasional`, `sbn-pop-common`, `sbn-pop-essential`, `sbn-pop-iconic`

**Play button:** wire via `opts.onPlay` callback in `SbnChordCard.createCard()` (from WP component, pending port). The `sbn-dot-ping` / `sbn-barre-ping` animation classes are ready for arpeggio feedback.

### Sizing in context

| Context | Grid definition |
|---|---|
| Chord library | `display: grid; grid-template-columns: repeat(auto-fill, minmax(120px, 1fr))` |
| Voicing picker (context panel) | `grid-template-columns: repeat(3, 1fr)` |
| Voicing picker (modal fallback) | `.sbn-ve-modal .sbn-vp-grid { grid-template-columns: repeat(auto-fill, minmax(90px, 1fr)) }` |
| Chord grid | Scoped in `leadsheets.css`: `.sbn-ve-chord-diagram .sbn-diagram-card { max-width: 64px }` |

---

## BUTTONS

Base class `.sbn-btn` is always required. Add one color modifier.

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
- A `box-shadow: 0 1px 0 0 var(--clr-border)` hairline runs across the bottom — this is the shared edge with the content below
- Each tab sits `bottom: -1px` to overlap that hairline
- Active tab: white bg (`--clr-white`), full border, `border-bottom-color: --clr-white` to punch through the hairline
- Active tab accent: `::before` pseudo-element, `height: 3px`, `background: var(--clr-gradient)` (orange→red)
- Content panels have `border-top: none` — the hairline serves as the divider

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

## CARDS / PANELS

```html
<!-- Standard card -->
<div class="sbn-card">...</div>

<!-- Large card (form pages) -->
<div class="sbn-card-lg">...</div>

<!-- Compact section card -->
<div class="sbn-card-section">...</div>

<!-- Dimmed surface (metadata, hints) -->
<div class="sbn-surface-dim">...</div>

<!-- Info callout (accent left border) -->
<div class="sbn-callout">
  Tip: use Shell voicings for clarity in trio settings.
</div>
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

## EXISTING GLOBAL COMPONENTS (already defined in admin2.css)

These are in `admin2.css` and already available everywhere. Do not redefine:

| Class | Location | Description |
|---|---|---|
| `.sbn-table`, `.sbn-table-wrap` | `admin2.css` | Standard data table |
| `.sbn-filter-bar`, `.sbn-search-input`, `.sbn-search-wrap` | `admin2.css` | Filter row above tables |
| `.sbn-badge`, `.sbn-badge-accent`, `.sbn-badge-muted` | `admin2.css` + design-system | Inline badges |
| `.sbn-pagination` | `admin2.css` | Page nav |
| `.sbn-toast` | `leadsheets.css` | JS toast (use `sbnToast(msg, type)` from `chords.js`) |
| `.sbn-empty` | `admin2.css` | Empty state container |
| `.sbn-flash` | `admin2.css` | Server-side flash messages |
| `.sbn-content-layout`, `.sbn-context-panel` | `admin2.css` | Two-column layout with right sticky panel |

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
- All leadsheet editor styles (extracted from inline `<style>` 2026-04-08)
- **Beat-grid layout** (chord grid, 2026-04-18):
  - `.sbn-ve-grid .sbn-ve-measure-content` — `position: relative; min-height: 148px; padding-bottom: 18px` (dot row reserved)
  - `.sbn-ve-beat-grid` — absolute bottom layer, `height: 18px`, holds dot elements
  - `.sbn-ve-beat-tick` — 9px circle, centred at `(b-0.5)/bpm * 100%` (NOT at beat edge); beat-1 = 11px
  - `.sbn-ve-beat-tick.beat-active` — orange fill + `sbn-beat-pulse` keyframe animation (driven by `transportBeat`)
  - `.sbn-ve-grid .sbn-ve-chord` — `position: absolute; top: 0; bottom: 18px; justify-content: flex-start; padding-top: 8px`
- Chord grid overrides: `.sbn-ve-grid .sbn-ve-chord-name`
- Card sizing in grid: `.sbn-ve-chord-diagram .sbn-diagram-card { max-width: 80px; padding: 2px 4px }`
- Density tier diagram sizing: `.double` (64px), `.multi` (52px), `.dense` (36px) — scoped to `.sbn-ve-grid`
- Density tier chord name sizing: double=17px, multi=14px, dense=10px (single inherits DS 20px base)
- SVG aspect ratio: `.sbn-chord-svg { aspect-ratio: 80/95 }`
- Playback active chord: `.sbn-ve-chord.is-active { box-shadow: inset 0 0 0 2px var(--clr-accent); background: none }` — frame only
- Tab playback metronome column: `.sbn-tab-metronome-col { fill: var(--clr-accent); opacity: 0.1 }` — same geometry as `.sbn-cursor-sel-col` (half-width 9px, rx 3, stringAreaTop-4 to bottom)
- Tab playback beat note: `.sbn-tab-note-text.sbn-beat-active { fill: #ef4444 !important }` — red, overrides hover/is-active
- Tab playing measure: `.sbn-tab-measure--playing { background: none; box-shadow: none }` — no frame, metronome column is sole indicator
- Paste target: `.sbn-ve-chord.is-paste-target` blue tint + `.sbn-ve-chord.is-paste-target .sbn-diagram-card { background: transparent }`
- Toast: `.sbn-toast`, `.sbn-toast-*`

### progressions.css
- `.sbn-prog-cat-pill`, `.sbn-prog-cat-badge` — category filters and badges
- `.sbn-prog-tonality` — small tonality label (major/minor)
- `.sbn-prog-numerals` — mono Roman numeral display
- `.sbn-occ-*` — occurrence list (collapsible per-song groups)
- `.sbn-cat-badge` — dynamic color badge via `--cat-clr`

### voicings.css
- Voicing crossref page: stats, draft cards, draft groups
- `.sbn-draft-card` — similar to `.sbn-diagram-card` but with meta row and actions

### progression-builder.css
Migrated to design system tokens in grid-polish session. Safe to use as style reference.
- `.sbn-pb-row` — flex row, no border (grid borders removed)
- `.sbn-pb-row .sbn-ve-chord-diagram .sbn-diagram-card` — `max-width: 80px; padding: 2px 4px`
- `.sbn-pb-numeral` — Roman numeral below chord name
- Bar numbers (`.sbn-pb-measure-num`) and VL score badges removed from builder grid markup and CSS

## CHORD GRID INTERACTION MODEL (established grid-polish session, updated diagram-polish session)

Both the leadsheet chord grid and progression builder grid share the same base visual system from `sbn-design-system.css §8`. Module CSS adds page-specific sizing only.

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
- Playback tracking (`is-active`): frame only, no background — content stays fully readable.
- Selection (`is-selected`): solid blue outline + moderate bg tint covering the full card including diagram area.
- Chord selection uses `.is-selected` outline on `.sbn-ve-chord` cards — NOT on `.sbn-ve-measure`.
- `.sbn-ve-measure.is-selected` (old measure-level box-shadow) is no longer used — selection lives on chord cards only.
- `::before` and `::after` on `.sbn-ve-measure` are fully reserved for barlines. Never use them for selection, drag, or any other visual effect.
- Drop gap uses `padding` (not `margin`) so the measure's hit area stays intact for `dragover` events.

### Barlines (established diagram-polish session)

Barlines use pseudo-elements on `.sbn-ve-measure`, defined in `sbn-design-system.css §8`:

```css
/* Right barline — every measure */
.sbn-ve-measure::after {
    content: ''; position: absolute;
    right: 0; top: 10%; height: 70%; width: 1px;
    background: var(--clr-text-muted);
}

/* Left barline — every measure */
.sbn-ve-measure::before {
    content: ''; position: absolute;
    left: 0; top: 10%; height: 70%; width: 1px;
    background: var(--clr-text-muted);
}

/* Opening barline — thicker on first measure of each row */
.sbn-ve-row .sbn-ve-measure:first-of-type::before,
.sbn-pb-row .sbn-ve-measure:first-of-type::before {
    width: 2px;
}
```

**Critical:** `::before` and `::after` on `.sbn-ve-measure` are fully reserved for barlines. Never use them for selection frames or other visual effects.

### Chord density tiers (established diagram-polish session)

Four density tiers, set via Alpine `:class` binding in `edit.blade.php`:

| Class | Condition | Chord name | Diagram max-width |
|-------|-----------|------------|-------------------|
| (none) | 1 chord | 20px (DS base) | 80px |
| `.double` | exactly 2 chords | 18px | 64px |
| `.multi` | 3–4 chords | 15px | 52px |
| `.dense` | 5+ chords | 12px | 36px |

Diagram card sizing rules in `leadsheets.css`, scoped to `.sbn-ve-grid`. Chord name sizing rules also in `leadsheets.css`. DS base (`§8`) defines the unsized default.

### Grid border structure

Section headers retain their accent border-bottom (orange). Section body has no outer border — content flows cleanly. Barlines are pseudo-elements only (see above). No `border-right` on `.sbn-ve-measure` itself.

### Copy/paste target

`.sbn-ve-chord.is-paste-target` gets a blue tint background (`rgba(59,130,246,0.08)`). The diagram card within gets `background: transparent` so the tint shows through without doubling.

### Card sizing in grid

```css
/* leadsheets.css — leadsheet grid (single chord, no density class) */
.sbn-ve-chord-diagram .sbn-diagram-card { max-width: 80px; padding: 2px 4px; }

/* progression-builder.css — builder grid */
.sbn-pb-row .sbn-ve-chord-diagram .sbn-diagram-card { max-width: 80px; padding: 2px 4px; }
```

SVG is always wrapped in `div.sbn-diagram-card` inside `.sbn-ve-chord-diagram`. Never bare SVG directly in `.sbn-ve-chord-diagram`. Never pass a pixel size to `sbnRenderDiagramSVG()`.

### Grid-interact Phase 1 — DONE (April 2026)

Implemented in `edit.blade.php` + `sbn-design-system.css` + new `public/js/sbn-context-menu.js` + `public/js/sbn-grid-ops.js`.

1. **Right-click context menu** ✓ — `showContextMenu()` vanilla singleton; `buildMenuItems('leadsheet', state)` config-driven; all chord + measure + batch ops wired; hover action buttons removed
2. **Two-tier selection model** ✓ — `selection: [{si,mi,ci}]` per chord-card; Ctrl+Click, Shift+Click, Ctrl+A, Escape, Delete all work; `.sbn-ve-selected` frame on chord cards
3. **Measure drag-to-reorder** ✓ — within section only; custom ghost via `setDragImage()`; gap indicator via `padding` + border; `moveMeasure()` updates selection + fires `_emitChordsChanged()`
4. **Shift+drag mouse batch selection** ✓ — `mousedown`+`mouseenter` extends range; `_mouseSelectMoved` flag suppresses post-drag click

### Grid-interact Phase 2 — DEFERRED to tab editor UX session

- **Measure drag cross-section** — needs `sbn-tab-structure-request` bridge
- **Measure drag tab sync** — `moveMeasure()` fires `patchChordNames()` only; Vue measure order not updated
- **Chord-level drag** — reorder within measure (A) + move between measures (B); needs beat redistribution design
- **Tab editor context menu** — `buildMenuItems('tab', state)` stub exists; needs `useMeasureSelection.js` + `sbn-tab-structure-request` handler in `useAlpineBridge.js`
- **Undo for chord grid** — copy `useUndo.js` pattern; `takeSnapshot()` wraps `markDirty()`
- **Volta index invalidation on drag** — `parsed.voltaEndings` keyed by global index; moving measures breaks it

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

`audioSource` is also persisted (not an authoring preference — it determines which clock drives playback on reload).

### Files

| File | Role |
|------|------|
| `resources/js/tab-editor/composables/useVideoSync.js` | All sync state + authoring mutations |
| `resources/js/tab-editor/components/VideoSyncEditor.vue` | Sidebar UI: video ID, player, tap controls, rate buttons, mapping table |
| `resources/js/tab-editor/components/VideoPlayer.vue` | YouTube / hosted `<video>` wrapper; emits `timeupdate` at 60fps via rAF |
| `resources/js/tab-editor/components/SyncPointBadge.vue` | Draggable orange circle overlay on measure barlines |
| `resources/js/tab-editor/TabEditor.vue` | Provides inject keys; wires VideoSyncEditor events; transport logic |

### Inject keys (provided by TabEditor)

| Key | Type | Value |
|-----|------|-------|
| `videoSyncMap` | `ComputedRef<Map<measureIndex, {videoTime, markerIndex}> \| null>` | `null` when sidebar closed; populated map when open |
| `nudgeSyncMapping` | `(measureIndex: number, delta: number) => void` | Adjusts a mapping's `videoTime` by `delta` seconds, undoable |
| `tapCursor` | `ComputedRef<number>` | Currently targeted measure for tap-to-mark |
| `seekToMeasure` | `(gi: number) => void` | Seeks audio + video to measure `gi` |

### Clock modes

Two mutually exclusive clock modes:

| Mode | Condition | Clock source |
|------|-----------|--------------|
| **Video master** | `audioSource === 'video'` AND `videoId` set | YouTube rAF loop → `videoMeasureIndex` → `transportBeat` |
| **Synth master** | otherwise | Tone.js scheduler → `currentBeat` → `transportBeat` |

`isVideoMaster = computed(() => audioSource.value === 'video' && hasVideo.value)`

Audio source auto-switches: opening the Video sidebar sets source to `'video'`; closing it sets `'synth'`.

### D1 — Playback sync

- `VideoPlayer.vue` runs a `requestAnimationFrame` loop calling `player.getCurrentTime()` and emitting `timeupdate` at ~60fps.
- `useVideoSync.onVideoTimeUpdate(time)` converts seconds → fractional `videoMeasureIndex` via binary-search interpolation.
- `transportBeat = videoMeasureIndex * beatsPerMeasure` feeds the score cursor.
- Seeking a measure in video-master mode calls `videoSync.measureToVideoTime(gi)` → `player.seekTo(t)`.

### D2 — Authoring

**Tap-to-mark flow:**
1. User opens Video sidebar (auto-switches to video master), presses Play.
2. Presses `M` at each downbeat — records `{ measureIndex: tapCursor, videoTime: currentVideoTime }`, advances `tapCursor`.
3. `Shift+M` un-taps: removes last mapping, rewinds `tapCursor` by 1 (`useVideoSync.untap()`).
4. All mutations go through `wrapCommand` → single Ctrl+Z undoes each tap.

**Keyboard shortcuts (VideoSyncEditor.vue `onKeydown`):**

| Key | Action |
|-----|--------|
| `Space` | Toggle playback |
| `M` | Mark at tapCursor, advance |
| `Shift+M` | Remove last mark, rewind tapCursor |
| `←` / `→` | Nudge video −/+ 2s |
| `Shift+←` / `Shift+→` | Nudge video −/+ 10s |
| `,` / `.` | Decrease / increase playback rate |

Guard: fires only when focused element is not `<input>` or `<textarea>`.

**Playback rate:** buttons 0.25×–1.5×; session-local only (not persisted). YouTube's rate change does not affect `getCurrentTime()` units — rAF loop keeps working unchanged.

**Distribute:** `useVideoSync.distributeMarkers()` linearly interpolates all unmapped measures between first and last marker. Single undoable command (`wrapCommand` with empty measure list).

**Tempo warnings:** adjacent mappings that imply < 0.1s or > 5s per measure are flagged in red in the sidebar table. Non-blocking.

### SyncPointBadge

`resources/js/tab-editor/components/SyncPointBadge.vue`

Rendered inside `ChordMeasure.vue` and `TabMeasure.vue` when `videoSyncMap` has an entry for that measure.

```vue
<SyncPointBadge
    :marker-index="syncPoint.markerIndex"   <!-- 0-based, displayed as 1-based -->
    :video-time="syncPoint.videoTime"        <!-- seconds -->
    :measure-index="globalIdx"
    context="chord"                          <!-- 'chord' | 'tab' -->
/>
```

**Positioning:**
- `left: 0; transform: translateX(-50%)` — centered on the measure's left barline
- Chord view (`context="chord"`): `top: 6px` — near top of chord measure cell
- Tab view (`context="tab"`): `top: 57px` — between D and G strings (27px chord bar + 30px SVG midpoint; G string y=25, D string y=35 inside SVG)

**Interaction:**
- **Click** (drag delta < 0.001s): calls `seekToMeasure(measureIndex)`
- **Drag** (horizontal only): live-updates `displayTime`; on release commits via `nudgeSyncMapping(measureIndex, delta)` (undoable)
- `SECS_PER_PX = 0.05` — 50ms per pixel of drag

**Visual:** 22px circle, orange→red radial gradient, white bold number, `ew-resize` cursor.

### Transport: park vs reset

`onTransportReset({ toZero = false })`:
- **First ⏹ press while playing:** pauses, keeps beat position ("parked"). ⏹ button gains `is-parked` class.
- **Second ⏹ press while stopped** (or `Escape`): resets to beat 0 and clears events cache.
- Video master mode: first press pauses video, second press seeks to 0:00 and clears `videoMeasureIndex`.

Video resume (`Space` while paused): `videoSync.playerRef.value?.seekTo(videoSync.videoTime.value)` then `.play()`. `videoTime` is preserved through pause (not cleared on `onVideoPlayStateChange`).

---

## CONVENTIONS

1. **Never hardcode color hex values** in module CSS. Use `--clr-*` variables.
2. **Never redefine** `:root` color tokens in module CSS files.
3. **Never create** a new button style. Use `.sbn-btn` + modifier.
4. **Chord names** always go through `chord()` (PHP) or `sbnFormatChordHtml()` (JS).
5. **Diagram cards** always use white background — `.sbn-diagram-card` or `.sbn-vp-card`. Never add a `width` to these classes — size via parent grid.
6. **SVG diagrams** never have hardcoded dimensions or a background rect — use `sbnRenderDiagramSVG()` which produces a transparent-bg fluid SVG.
7. **CSS class prefix:** all classes must start with `sbn-`. No bare element selectors.
8. **New module CSS files:** scope all selectors to a module-specific prefix (e.g., `.sbn-pb-*` for progression builder). Add a comment at the top stating which file in `admin.blade.php` loads it.
9. **Music style colors** use `--clr-style-*` variables or `.sbn-badge-style-*` classes.

---

## FILE MAP (CSS files)

```
public/css/
  sbn-design-system.css   ← tokens + base components (load first)
                            §2   card system: .sbn-diagram-card, .sbn-vp-card (no fixed width)
                            §2b  HTML fretboard: .sbn-fretboard-*, .sbn-finger-position, .sbn-barre
                            §2c  full chord card: .sbn-chord-card (Phase 8)
  chord-symbols.css       ← chord name typography (load second, global)
  admin2.css              ← admin shell: sidebar, topbar, layout (load third)
  chords.css              ← chord library extensions + legacy sbn-fb-* aliases
  leadsheets.css          ← leadsheets module + all editor styles
  progressions.css        ← progressions module
  rhythms.css             ← rhythm patterns module
  voicings.css            ← voicing crossref module
  progression-builder.css ← progression builder (migrated to DS tokens in grid-polish session)

public/js/
  chords.js               ← chord diagram renderers + fretboard hydration + toast
  sbn-chord-name.js       ← sbnFormatChord() chord name formatter
```

## ADDING NEW STYLES

When Claude adds CSS for a new feature:
1. Check this document — does a component already cover this?
2. If extending an existing component, add a modifier class (e.g., `.sbn-diagram-card--compact`).
3. If creating something genuinely new, add it to the appropriate module CSS file with `sbn-[module]-[element]` naming.
4. If the new component will be used across multiple modules, add it to `sbn-design-system.css` and document it here.
5. Never use `!important` except to override third-party styles.
6. Never add a `width` to `.sbn-diagram-card` or `.sbn-vp-card` — always size via parent grid.
