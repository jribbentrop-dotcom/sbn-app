# SBN PDF Design System — Folder & File Guide

**Location:** `design/pdf-system/`

**Purpose:** Central hub for legacy PDF redesigns. Stores templates, assets, documentation, and project history.

---

## Folder Structure

```
pdf-system/
├── README.md                          (this file)
├── templates/
│   ├── system-master.html             (canonical three-layout template)
│   ├── top10-bossa-nova.html          (instantiation for TOP10 Bossa Nova)
│   └── [product-slug].html            (future instances)
├── assets/
│   ├── chord-svgs/                    (rendered via render-diagram.cjs --bw, one file per slug)
│   ├── notation-svgs/                 (rendered via render-tab.cjs, named slug-bars-N-M.svg)
│   └── source-pdfs/                   (original legacy PDFs for reference)
│       ├── AKKORDE-TOP10-original.pdf
│       ├── AKKORDE-Shell-Voicings.pdf
│       └── ...
├── audits/                            (content analysis for each legacy PDF)
│   ├── TOP10-AUDIT.md
│   ├── SHELL-VOICINGS-AUDIT.md
│   └── VIERKLÄNGE-AUDIT.md
├── layouts/                           (layout assignment maps: which pages use which layout)
│   ├── TOP10-LAYOUT-MAP.md
│   └── ...
└── docs/                              (reference docs)
    ├── DESIGN-TOKENS.md               (colors, fonts, spacing)
    ├── CHORD-FORMATTER.md             (sbnFormatChord reference)
    └── CONTENT-PROCESS.md             (six-phase legacy→new workflow)
```

---

## Key Files

### Templates

**`templates/system-master.html`**
- Canonical three-layout template.
- Contains: `.cover`, `.item` (chord entry), `.example` (song/repertoire).
- Includes: Design tokens (CSS custom properties), chord formatter script (`sbnFormatChord()`), all styling (from `public/css/chord-symbols.css` + custom).
- **Status:** Proven on TOP10 test case. Superscript chord extensions working. Layouts responsive to content.
- **Use:** Copy, rename to `[product-slug].html`, populate with real content.

**`templates/[product-slug].html`**
- Project-specific instance (e.g., `top10-bossa-nova.html`, `shell-voicings.html`).
- Same structure as master, but with product's actual content: copy, chord names, image paths, etc.
- Ready to render via Browsershot.

### Documentation

**`docs/` folder (in parent: `docs/SBN-PDF-DESIGN-SYSTEM.md`)**
- **Overview:** Three layouts, their purposes, real examples from TOP10.
- **Shared systems:** Design tokens, badge component, chord formatter.
- **Content process:** Six-phase workflow (Audit → Propose → Choose → Gather → Author → Proof).
- **File organization:** This folder structure.
- **References:** Links to source code (sbn-design-system.css, sbn-chord-name.js, etc.).

**`audits/TOP10-AUDIT.md`**
- Content + structure analysis of TOP10 source PDF.
- Quality assessment: copy is excellent, typos minimal, diagrams clean.
- Recommendations: render chords from DB, extract notation at high res, strengthen margin asides.
- Asset inventory: which chord diagrams are in DB? Which songs?

**`layouts/TOP10-LAYOUT-MAP.md`**
- Page-by-page assignment: which layout (`.cover` / `.item` / `.example`) for which pages.
- Content slot breakdown: what content goes where in each layout.
- Checklist: verification steps before rendering, after rendering.
- File paths: where assets should be saved.

---

## Workflow: Adding a New Legacy PDF

1. **Audit**
   - Read source PDF cover to cover.
   - Identify structure, content quality, issues.
   - Write `audits/[PRODUCT]-AUDIT.md`.

2. **Propose**
   - Sketch 2–3 layout directions (rough mockups or thumbnails).
   - Show real content samples.
   - Get user approval on visual direction.

3. **Choose**
   - Assign layout type to each PDF section.
   - Create `layouts/[PRODUCT]-LAYOUT-MAP.md`.
   - Map content slots (title, prose, images, etc.).

4. **Gather Assets**
   - Chord diagrams: `node scripts/pdf/render-diagram.cjs <slug> --bw > assets/chord-svgs/<slug>.svg`
   - Item page TABs: query `sbn_leadsheets` (slug `top10`) → slice measures → pipe to `render-tab.cjs`
   - Example page notation: query song leadsheet by slug → slice bar range → pipe to `render-tab.cjs`
   - Songs not in DB: use `[NOTATION: ...]` placeholder — never hand-draw SVG coordinates
   - Full command reference: `docs/RENDER-PIPELINE.md`

5. **Author**
   - Copy `system-master.html` → `[product-slug].html`.
   - Populate content slots with real copy, images, chord names.
   - Verify chord names are plain text (will be formatted by JS).

6. **Proof**
   - Render via Browsershot (production Chrome): `.pdf`
   - Visual review: spacing, alignment, superscript, images, badges.
   - Fix CSS if needed. Re-render.
   - Sign off.

7. **Archive**
   - Save final HTML in `templates/`.
   - Save final PDF somewhere (e.g., `shop/pdfs/` or print-ready folder).
   - Archive source PDF in `assets/source-pdfs/`.
   - Update version history in `README.md` (this file).

---

## Version History

| Product | Status | Completed | Notes |
|---------|--------|-----------|-------|
| **TOP10 Bossa Nova** | ✓ Template proven | 2026-06-16 | Proof-of-concept. Master template validated. |
| Shell Voicings | Planned | — | Audit TBD. Simpler structure (no song examples). |
| Vierklänge (Seventh Chords) | Planned | — | Audit TBD. German content. Multiple level sections. |
| — | — | — | — |

---

## Design System Constants

### Fonts
```
--font-body:    'DM Sans' (sans-serif, UI text)
--font-chord:   'Crimson Text' (serif, chord names + musical prose)
--font-display: 'Fraunces' (serif, bold headings)
```

### Colors
```
--clr-text:        #2c3e50 (dark navy)
--clr-text-dim:    #5a5a5a (medium gray)
--clr-text-muted:  #8896a4 (light gray)
--clr-accent:      #f39c12 (orange, primary)
--clr-accent-dim:  #e67e22 (dark orange)
--clr-red:         #e74c3c (red)
--clr-border:      #e2e8f0 (light border)
--clr-gradient:    linear-gradient(135deg, #f39c12 0%, #e74c3c 100%) (orange→red)
```

### Layout (A4 Portrait)
```
Page size: 210mm × 297mm
Default margins: 18–22mm all sides
Line height: 1.5–1.7 (body text)
Column widths: 1fr (main), 38mm (margin)
Gutter: 12mm between columns
```

---

## Chord Formatter Reference

**Function:** `sbnFormatChord(chord)`

**Input:** Plain text chord string (e.g., "CMaj7(9)", "Am7b5/G")

**Output:** HTML with semantically-typed spans:
- `.sbn-chord-root` — root note (bold, slightly larger)
- `.sbn-chord-quality` — quality (m, maj, dim, sus, etc.)
- `.sbn-chord-accidental` — accidentals (♯, ♭)
- `.sbn-chord-ext` — extensions (7, 9, b5, etc.) **rendered as superscript**
- `.sbn-chord-bass` — slash chord (e.g., "/G")

**Wrapper:** `.sbn-chord-symbol` (Crimson Text, 1.05em, nowrap, 600 weight)

**Usage in template:**
```html
<!-- Author writes plain text: -->
<div id="cover-chord"></div>

<!-- JavaScript formats it: -->
<script>
  document.getElementById('cover-chord').innerHTML = sbnStyledChord('CMaj7(9)');
</script>

<!-- Renders as: -->
<span class="sbn-chord-symbol">
  <span class="sbn-chord-root">C</span>
  <span class="sbn-chord-quality">maj</span>
  <span class="sbn-chord-ext">7(9)</span>  <!-- displayed as superscript -->
</span>
```

---

## Quick Links

- **Master template:** `templates/system-master.html`
- **Design system overview:** `docs/SBN-PDF-DESIGN-SYSTEM.md` (parent directory)
- **Chord formatter:** `public/js/sbn-chord-name.js` (app source)
- **Design tokens:** `public/css/sbn-design-system.css` (app source)
- **Chord symbols styling:** `public/css/chord-symbols.css` (app source)

---

## Contributing Notes

- Keep assets organized by product name (folders within `assets/notation-crops/`, filenames in `assets/chord-diagrams/`).
- Update audits and layout maps as you discover new content, issues, or refinements.
- When adding a new product, document the three phases (audit, layout, instantiation) in separate files.
- Archive source PDFs so future team members can see what was redesigned.
- Update version history in this README when a product redesign is complete.

---

**Last updated:** 2026-06-16
