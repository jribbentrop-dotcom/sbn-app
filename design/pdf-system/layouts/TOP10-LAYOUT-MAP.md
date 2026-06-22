# TOP10 Bossa Nova Chords — Layout Assignment Map

**Template:** `system-master.html`

**Three-layout structure:**

---

## Page 1: Title Page

**Layout:** `.cover` (content slots updated — no chord diagram on this page; this is a real title
page, not a diagram-led teaser. The diagram-hero treatment described in earlier drafts of this map is
superseded.)

**Content slots:**
- `cover__eyebrow`: "SBN Teaching Hub · Top 10"
- `cover__title`: "TOP10\nBossa Nova Chords"
- `cover__subtitle`: "Essential Voicings for Guitar"
- `cover__hook`: "Ten chords. Seven songs. Everything you need to start sounding like Bossa Nova."
- `cover__facts` × 3:
  - "Ten movable chord shapes — learn each one once, play it in any of the 12 keys."
  - "Every chord cross-referenced to where it actually shows up — Ipanema, Corcovado, Wave, and more."
  - "Plus a 'secret weapon' entry — the chord Jobim leaned on more than any other passing chord in the songbook."
- `cover__footer`: "soulbossanova.com"

**Notes:**
- No hero chord diagram, no badge, no chord caption on this page — title, hook, and the three fact lines
  carry it. (Content source: `TOP10-CONTENT-DRAFT.md` → Front Matter → Title Page.)
- Visual treatment (typography-led, illustration, texture, etc.) is a layout-phase decision once
  rendering resumes — this entry only locks in *what content* goes on the page, not how it's styled.

---

## Page 2: Chord Theory in a Nutshell

**Layout:** new — not part of the original three-layout system (`.cover` / `.item` / `.example`); needs
its own simple prose layout once the layout phase resumes. Content is finalized in
`TOP10-CONTENT-DRAFT.md` → Front Matter → "Chord Theory in a Nutshell": four-part chords (root/3rd/5th/7th),
extension tones (9/11/13), this catalog's own voicing vocabulary (Shell / Drop 2 / Drop 3 / custom), and a
short explainer of each item page's recurring structure (Voicing note vs. caption, Suggested Practice
Pattern, the three margin asides). Replaces the old PDF's paper diagram/TAB legend, which doesn't apply to
this system's interactive chord components.

---

## Chord Entries (10× `.item` layout)

*Page numbers TBD — depends on final pagination once layout/rendering resumes. Table below is finalized
content (matches `TOP10-CONTENT-DRAFT.md`); the earlier version of this table (placeholder slugs, generic
"Maj7(9)"/"7(9)" identities) is superseded.*

| # | Title | Display chord | Practice Pattern |
|---|---|---|---|
| 1 | The Major 6/9 Chord | Db6(9)/Ab | Db6(9)/Ab → Gb6(9)/Db |
| 2 | Minor Seventh with 9 | Cm7(9) | Cm7(9) → Fm7(9) |
| 3 | The Minor Sixth Chord | Am6 | Am6 → Dm7(9) |
| 4 | Dominant Seventh with 13 | G7(13) | Dm7(9) → G7(13) |
| 5 | The Half Diminished Chord | Bm7b5 | Bm7b5 → E7 → Am6 |
| 6 | Dominant Seventh with 9 | D7(9) | Amaj7 → Am7 → D7(9) |
| 7 | Dominant Seventh with b9 | E7(b9) | Bm7 → E7(b9) → Amaj6(9) |
| 8 | The Diminished Seventh Chord | C#dim7 | Cmaj7 → C#dim7 → Dm7(9) |
| 9 | Diminished with Flat 13 | G#dim7(b13) | Am6 → Abo7(b13) |
| 10 | Dominant Seventh with b13 | G7(b13) | Dm7b5 → G7(b13) → Cm7(9) |

**Per-entry layout (`.item`):**

- `item__eyebrow`: Left: "SBN Teaching Hub · Chord Catalog" | Right: "02 / 10" (page count, e.g.)
- `item__head`: Badge (17mm circle, entry number) + Title (21pt Fraunces)
- `item__intro` (flex row):
  - `item__diagram-feature`: Chord diagram SVG/image (118pt box)
  - `item__diagram-caption`: "Cm7(9)" (formatted via `sbnFormatChord()`)
  - `item__lede`: Italic intro sentence (e.g., "The little sister of the Major9 chord — a bit more mellow, and at least as beautiful.")
- `item__body`: 2–3 paragraphs of prose from source PDF (describe the chord, its context in Bossa Nova)
- `item__section-label`: "Suggested Practice Pattern"
- `item__pattern`: Practice pattern notation/TAB image (full-width)
- `item__margin` (right column, 38mm):
  - Block 1 (Listen): label + song example (e.g., "Blue Bossa — Kenny Dorham / Joe Henderson. Note how the m9...")
  - Block 2 (Try this): label + technique tip
  - Block 3 (Related): label + cross-reference (e.g., "Compare with Shell Voicings booklet, p.1")
- `item__footer`: Left | Center ("TOP10 Bossa Nova Chords") | Right

**Notes:**
- `item__diagram-caption` must contain plain text chord name (e.g., "Cm7(9)") — JavaScript will format via `sbnFormatChord()`.
- Margin column content is NEW (not in source PDF) — author based on pedagogical value: Which song best showcases this chord? What's a key technique to practice? What related material exists?
- Each entry is a full page (or possibly 2 if prose is longer; use `page-break-after: auto`).

---

## Song Examples (7× `.example` layout)

*Page numbers TBD, same caveat as above. Table below is finalized content (matches
`TOP10-CONTENT-DRAFT.md` → Examples 1–7); the earlier song list and chord tags (Dindi, Black Orpheus,
etc.) are superseded — that list didn't match the live DB leadsheets.*

| # | Song | Bars | Catalog items featured | DB leadsheet slug |
|---|------|------|-------------------------|---------|
| 1 | The Girl from Ipanema | 0–15 | #1 (exact), #6/#2/#3 (family) | `the-girl-from-ipanema` |
| 2 | So Danço Samba | 0–3 | none exact — rhythm/feel example (4-bar vamp) | `so-danco-samba` |
| 3 | Blue Bossa | 0–13 | #2, #10 (exact); #5 (family) | `blue-bossa` |
| 4 | Manhã de Carnaval | 0–30 | #3, #5 | `manha-de-carnaval` |
| 5 | Once I Loved | — | `[NOTATION: ...]` placeholder, not in DB | — |
| 6 | How Insensitive (Insensatez) | 6–9 | #9, #3 (exact) | `insensatez` |
| 7 | Corcovado | 0–13 | #3, #9 (exact); #6, #10 (family) | `corcovado` |

**Per-example layout (`.example`):**

- `example__eyebrow`: "Repertoire Example · 1 of 7"
- `example__title`: Song title (24pt Fraunces, e.g., "The Girl from Ipanema")
- `example__sub`: Italic context (e.g., "Bars 1–8 · built entirely from three chords in this catalog")
- `example__legend`: Flex row with chips:
  - Label: "Chords used"
  - Chips (3–4 per example): Small badge (16pt circle) + chord name (e.g., "01 Maj7(9)", "02 m7(9)", "08 Dom7(9)")
- `example__notation-wrap`: Notation image (lyrics + TAB, full-width)
- `example__tag-row`: Small chips positioned inline below notation, tagging active chords seen in excerpt (e.g., "01 FMaj7(9)" "08 G7(9)")
- `example__note`: Bordered-left callout paragraph (the pedagogical observation)
- `example__footer`: Left | Center ("TOP10 Bossa Nova Chords · Examples") | Right

**Notation image requirements:**
- Extract from source PDF at 300 DPI (high quality for print).
- Crop cleanly: lyrics + TAB systems visible, no extra page margins.
- Filename: `ipanema-bars-01-08.png` or similar (readable, slug-based).
- Save to: `design/pdf-system/assets/notation-crops/ipanema/bars-01-08.png`

**Notes:**
- Tag row shows which catalog entries actually appear in the excerpt — reinforces the cross-reference pattern.
- Callout note is the "AHA" moment: e.g., "Almost the entire A section alternates between just two voicings from this catalog — entry 01 and entry 08."
- Each example is one page (or possibly 1.5 if notation is long; manage with `page-break`).

---

## Page Layout Grid

```
.pdf-page {
  width:  210mm (A4 portrait)
  height: 297mm
  margin: 0
  page-break-after: always
}

Within each page:
├─ .cover / .item / .example
│  ├─ Header / eyebrow (fixed, ~20mm from top)
│  ├─ Main content (flexible)
│  └─ Footer (absolute-positioned, ~10mm from bottom)
└─ @page { size: A4 portrait; margin: 0; }
```

**Margin scheme:**
- Cover: 22mm top/bottom, 20mm left/right (centered)
- Item: 20mm top/bottom, 18mm left/right
- Example: 20mm top/bottom, 18mm left/right

---

## Master Template Instantiation Checklist

**Before rendering:**

- [ ] All 10 entry images (chord diagrams) placed in `item__diagram-feature`
- [ ] All 10 entry prose checked (typo fixes applied)
- [ ] All 7 song notation images extracted at 300 DPI, placed in `example__notation-wrap`
- [ ] All chord names (in captions, legends, tags) are plain text — will be formatted by JavaScript
- [ ] Margin column content authored (Listen, Try, Related asides)
- [ ] Callout observations authored (one per song example)
- [ ] Badge numbers match catalog order (01–10 for entries, 1–7 for examples)
- [ ] Eyebrow labels, footers, titles all finalized
- [ ] Google Fonts loaded (or fallback system fonts set)
- [ ] `sbnFormatChord()` JS included in `<script>` tag

**After rendering:**

- [ ] All 3 pages visible in PDF viewer
- [ ] Chord names render with superscript (CMaj7⁽⁹⁾, not CMaj7(9))
- [ ] Badges aligned, gradient visible
- [ ] Images clear, no blur or artifacts
- [ ] Margins/gutters consistent
- [ ] Footer content legible
- [ ] No orphaned lines or text overflow
- [ ] Print preview at 100% (zoom) shows no layout breaks

---

## Files & Paths

**Template:** `design/pdf-system/templates/system-master.html`

**Assets:**
```
design/pdf-system/assets/
├── chord-diagrams/          (10 files, ~50KB total)
│   ├── cmaj7-9.png
│   ├── cm7-9.png
│   └── ...
└── notation-crops/          (7 subdirs)
    ├── ipanema/
    │   └── bars-01-08.png
    ├── corcovado/
    │   └── bars-...png
    └── ...
```

**Output:** `design/pdf-system/templates/top10-bossa-nova.html` (final instance)

---

## Publishing

Once approved:
1. Save as `top10-bossa-nova.html` in `design/pdf-system/templates/`
2. Render via Browsershot (production, real Chrome): `system/top10-bossa-nova.pdf`
3. Archive source PDF as `assets/source-pdfs/AKKORDE-TOP10-original.pdf`
4. Mark in version control: ✓ Legacy PDF #1 redesigned
5. Update `docs/SBN-PDF-DESIGN-SYSTEM.md` with completion notes
