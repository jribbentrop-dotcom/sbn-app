# SBN PDF Design System — Legacy Redesign Framework

**Purpose:** Unified corporate identity for legacy PDF products (shop PDFs, chord books, teaching materials). Distinct from the automated SBN-PDF-Pipeline (which generates new PDFs from live DB data).

**Status:** Foundation complete. Three canonical layouts proven on TOP10 Bossa Nova test case.

---

## Three Canonical Layouts

All layouts share: design tokens (CSS custom properties), chord name formatter (`sbnFormatChord` with superscript), badge component family, footer/header structure.

### 1. **Cover / Title Page** (`.cover`)

**Use for:** Book covers, section openers, intro pages, visual statements.

**Components:**
- Eyebrow label (series/collection)
- Title (large, display font, may span lines)
- Subtitle (italic, chord font)
- Hero diagram (bordered box, centered)
- Chord name (formatted via `sbnFormatChord`, shows as "CMaj7(9)" with superscript 9)
- Entry badge (large, 30mm circle, gradient, positioned top-right of hero box)
- Three-column fact strip (label + value pairs, left-aligned in flex row)
- Footer (absolute-positioned at page bottom)

**Layout principle:** Centered, vertically balanced via flexbox (justify-content: center). Hero diagram and facts are the focal points.

**Real example:** TOP10 Bossa Nova cover page — shows CMaj7(9) with entry badge #01, three facts below (10 catalog entries, 7 real songs, 1 secret weapon).

---

### 2. **Chord Catalog / Item Detail Page** (`.item`)

**Use for:** Per-chord pages in chord catalogs, method-book style entries, voicing explanations.

**Components:**
- Eyebrow (left: "SBN Teaching Hub · Chord Catalog", right: page count "02 / 10")
- Head row: entry badge (17mm circle, md size) + title
- Two-column layout: main content | margin column (1fr | 38mm)
- Intro section (flex row): diagram feature (118pt box with SVG or image) + lede (italic intro)
- Diagram caption: chord name formatted via `sbnFormatChord` (e.g., "Cm7(9)" with superscript)
- Body prose (running text, 10.5pt)
- Section label ("Suggested Practice Pattern")
- Pattern image (full-width in main column)
- Margin column: three stacked blocks (Listen / Try this / Related) with labels + notes
- Footer (three-part: left | center | right)

**Layout principle:** Method-book idiom (text-heavy, margins for annotations). Diagram floats in intro; prose wraps around. Margin column provides pedagogical asides without interrupting flow.

**Real example:** TOP10 page 2 — Cm7(9) entry with Blue Bossa description, practice pattern, three margin notes on listening/technique/cross-reference.

---

### 3. **Repertoire / Music Example Page** (`.example`)

**Use for:** Song excerpts, real-world applications, cross-reference showcase.

**Components:**
- Eyebrow label ("Repertoire Example · 1 of 7")
- Title (song name, large)
- Subtitle (bars + context, italic)
- Legend (flex row): label "Chords used" + series of chips (badge + chord name)
  - Chips: inline-flex, badge (small, 16pt) + text, gap 7pt
- Notation wrap (image, full-width)
- Tag row (small chips, positioned below notation, showing active chords in excerpt)
- Note callout (left-border-accented paragraph, observation about the passage)
- Footer (same three-part structure as item page)

**Layout principle:** Song-centric. Legend establishes the chord palette upfront. Notation is the focus. Tags link notation back to catalog entries (by number). Callout provides teachable moment.

**Real example:** TOP10 page 7 — "The Girl from Ipanema" bars 1–8, legend shows #01 Maj7(9), #02 m7(9), #08 Dom7(9), notation excerpt, inline tags "01 FMaj7(9)" + "08 G7(9)", callout notes the alternation pattern.

---

## Shared Systems

### Design Tokens (`:root`)
All pages use CSS custom properties from `public/css/sbn-design-system.css`:

```css
--clr-text: #2c3e50
--clr-text-dim: #5a5a5a
--clr-text-muted: #8896a4
--clr-accent: #f39c12 (orange)
--clr-accent-dim: #e67e22
--clr-red: #e74c3c
--clr-border: #e2e8f0
--clr-gradient: linear-gradient(135deg, #f39c12 0%, #e74c3c 100%)

--font-body: 'DM Sans'
--font-chord: 'Crimson Text' (serif, for chord names + musical prose)
--font-display: 'Fraunces' (serif, display font for headings)

--radius-sm: 6px
--radius: 10px
```

### Badge Component
`.badge` base class + size modifiers:
- `.badge--lg`: 30mm circle, 22pt numeral, shadow (cover hero)
- `.badge--md`: 17mm circle, 13pt numeral (item identity)
- `.badge--sm`: 16pt circle, 7.5pt numeral (example chord tags)

All use `--clr-gradient` (orange→red).

### Chord Name Formatter
**Function:** `sbnFormatChord(chord)` — from `public/js/sbn-chord-name.js`

**Input:** chord string, e.g., "CMaj7(9)", "Am7b5/G"

**Output:** HTML with typed spans:
- `.sbn-chord-root` — root note (bold, slightly larger)
- `.sbn-chord-quality` — quality (m, maj, dim, etc.)
- `.sbn-chord-accidental` — accidentals (♯, ♭) in roots
- `.sbn-chord-ext` — extensions (7, 9, b5, #11, etc.) **as superscript** (font-size: 0.75em, vertical-align: super)
- `.sbn-chord-bass` — slash chord bass note

**Wrapper:** `.sbn-chord-symbol` (Crimson Text, 1.05em, 600 weight, nowrap)

**Example rendering:**
```
Input:  CMaj7(9)
Output: <span class="sbn-chord-symbol">
          <span class="sbn-chord-root">C</span>
          <span class="sbn-chord-quality">maj</span>
          <span class="sbn-chord-ext">7(9)</span>  <!-- 7(9) rendered as superscript -->
        </span>
```

---

## Content Process (Legacy PDF → New Design)

### Phase 1: Audit
Read the source PDF. Identify:
- **Structure:** title page? chord catalog? notation examples? song applications?
- **Content types:** Is it primarily pedagogical prose? practical voicing catalog? repertoire cross-reference?
- **Quality issues:** Typos, outdated terminology, missing notations, duplicate/inconsistent layouts, broken images?

**Deliverable:** Written audit flagging structure, content strengths, and gaps.

### Phase 2: Propose Layout Direction
Based on the PDF's actual content and your strategic intent, sketch 2–3 visual directions (even rough thumbnails) answering:
- Does this PDF read best as **method-book** (prose-heavy, Item layout) or **songbook** (repertoire-focused, Example layout)?
- Should the cover be **poster-style fact strip** (Cover layout) or a simple title page?
- Are the existing diagrams/notation high-quality enough to reuse as crops, or should they be redrawn from DB data?

**Deliverable:** 2–3 page directions with real content samples, visual mockup PDFs.

### Phase 3: Assign Layout Map
Choose layout type(s) for each section:
- Pages X–Y: Cover layout (title, facts)
- Pages X–Y: Item layout (chord entries)
- Pages X–Y: Example layout (songs)

Document which content chunks (prose, diagrams, notation) belong in which layout slots.

**Deliverable:** One-page layout assignment + content inventory.

### Phase 4: Gather/Prepare Assets

**Always render programmatically — never hand-draw SVG coordinates or crop images from source PDFs.**

#### Chord diagrams
```bash
node scripts/pdf/render-diagram.cjs <slug> --bw > design/pdf-system/assets/chord-svgs/<slug>.svg
```
- `--bw` strips CSS vars to hard `#000` for print
- Slug must exist in `sbn_chord_diagrams`; verify first with a DB query

#### Item page practice TABs (multi-chord progressions)
The `top10` leadsheet in `sbn_leadsheets` (slug `top10`) contains the practice progressions for all 10 item pages. Query its `json_data`, slice the relevant measures, pipe to `render-tab.cjs`:
```bash
node -e "
  const db = require('better-sqlite3')('database/sbn.db', {readonly:true});
  const row = db.prepare('SELECT json_data FROM sbn_leadsheets WHERE slug=?').get('top10');
  const d = JSON.parse(row.json_data);
  const measures = d.measures.slice(START, END); // 0-indexed
  process.stdout.write(JSON.stringify({measures, timeSig: d.timeSignature, barsPerRow: 3, showChordNames: true}));
" | node scripts/pdf/render-tab.cjs
```

#### Example page notation (song excerpts)
Same pattern — query the song's leadsheet by slug, slice bars, pipe to `render-tab.cjs`:
```bash
# e.g. Blue Bossa bars 0-13
node -e "
  const db = require('better-sqlite3')('database/sbn.db', {readonly:true});
  const row = db.prepare('SELECT json_data FROM sbn_leadsheets WHERE slug=?').get('blue-bossa');
  const d = JSON.parse(row.json_data);
  process.stdout.write(JSON.stringify({measures: d.measures.slice(0,14), timeSig: d.timeSignature, barsPerRow: 4, showChordNames: true}));
" | node scripts/pdf/render-tab.cjs
```
Songs not in `sbn_leadsheets`: use a `[NOTATION: ...]` placeholder — do not hand-draw.

**Deliverable:** Organized asset folder (chord-svgs/, notation-svgs/) with one file per slot, named by slug + bar range.

### Phase 5: Author Content in Template
Populate each layout instance with actual copy:
- Cover: title, subtitle, eyebrow, facts.
- Item pages: badge number, chord name, lede, body prose, practice pattern image, margin asides.
- Example pages: song title, chord legend, notation image, tags, callout.

Proofread. Verify chord names format correctly (superscript tests). Check image crops are clean and centered.

**Deliverable:** Complete HTML file (or PHP config for Pipeline) ready to render.

### Phase 6: Proof & Refine
Render to PDF (weasyprint in sandbox, Browsershot in production). Review all three pages:
- Spacing, alignment, page breaks?
- Chord names rendering with proper superscript?
- Images at readable size?
- Badges positioned correctly?
- Footer/header consistent?

Make CSS tweaks. Re-render. Approve.

**Deliverable:** Final PDF + signed-off HTML/config.

---

## Workflow Summary

```
SOURCE PDF (legacy, rasterized)
    ↓
[Audit] → identify structure, content, quality issues
    ↓
[Propose] → sketch 2–3 layout directions with mockups
    ↓
[Choose] → assign layout types to each PDF section
    ↓
[Gather Assets] → extract/prepare chord diagrams, notation, images
    ↓
[Author] → populate templates with real copy
    ↓
[Proof] → render, review, refine CSS/layout
    ↓
FINAL PDF (branded, new design)
```

---

## File Organization

**Location:** `design/pdf-system/`

```
design/pdf-system/
├── README.md                    (this file, version history)
├── templates/
│   ├── system-master.html       (three layouts: cover, item, example)
│   ├── system-master.css        (shared styles, exported to standalone HTML)
│   └── [product-slug].html      (instance: e.g., top10-bossa-nova.html)
├── assets/
│   ├── chord-diagrams/          (cropped or rendered SVGs)
│   ├── notation-crops/          (rasterized notation/TAB excerpts)
│   ├── reference-images/        (screenshots, diagrams used in audits)
│   └── source-pdfs/             (original legacy PDFs for reference)
├── audits/
│   ├── TOP10-AUDIT.md           (content + structure analysis)
│   ├── SHELL-VOICINGS-AUDIT.md
│   └── ...
├── layouts/
│   ├── TOP10-LAYOUT-MAP.md      (which pages use which layout)
│   └── ...
└── docs/
    ├── DESIGN-TOKENS.md         (color/font/size reference)
    ├── CHORD-FORMATTER.md       (how sbnFormatChord works, examples)
    ├── CONTENT-PROCESS.md       (step-by-step legacy→new workflow)
    └── LAYOUT-PATTERNS.md       (when/why to use each layout)
```

---

## Render Pipeline Status (2026-06-22)

### Chord Diagrams ✅ Production-ready
- `scripts/pdf/render-diagram.cjs <slug> [--bw]` — DB lookup + SVG out
- `scripts/pdf/render-diagram-inline.cjs` — no DB, takes `{frets, position, fingers}` JSON; used by PdfController
- B&W mode strips CSS vars to `#000` for print

### TAB / Notation ✅ Production-ready
- `scripts/pdf/render-tab.cjs` — takes measure JSON on stdin, outputs SVG
- `scripts/pdf/render-tab-v2.cjs` — extended version with inline chord diagrams per measure; used by PdfController for full leadsheet PDFs
- Input format: `{measures: [...], timeSig: "4/4", barsPerRow: 4, showChordNames: true}`
- Measure objects come from `sbn_leadsheets.json_data` (already parsed into the right shape)

### Rhythm Grids ❌ No standalone renderer yet
- Currently rendered as HTML (`.rhythm-grid` CSS classes) in booklet templates
- Pattern: same approach as Tier 1/2 when a standalone renderer is needed

### Leadsheet PDF (full song) — open
- PdfController route + Blade template not yet built
- Architecture: PHP → `TabXmlParser` → `render-tab-v2.cjs` → SVG injected into Blade → Browsershot
- See `docs/SBN-PDF-Briefing.md` for full pipeline spec

---

## References

- `public/css/sbn-design-system.css` — all design tokens
- `public/css/chord-symbols.css` — chord name styling (superscript, weights, sizing)
- `public/js/sbn-chord-name.js` — `sbnFormatChord()` formatter
- `docs/SBN-PDF-Briefing.md` — Pipeline config format (for future data-driven PDFs)
- `docs/SBN-PDF-Pipeline-Plan.md` — notations/TAB rendering status, open questions
