# TOP10 Bossa Nova Guitar Chords — Content Audit

**Source:** `design/pdf-templates/source-pdfs/AKKORDE - TOP10 Bossa Nova.pdf` (13 pages)

**Date:** 2026-06-16

**Status:** ✓ Complete. Structure strong, content accurate, minor improvements identified.

---

## Overview

Professional chord catalog + real-world song applications. Structure:
- **Cover teaser** (page 1): Visual intro, hook ("Top 10 must-know chords")
- **Catalog entries** (pages 2–6): 10 numbered chords with pedagogical descriptions + practice patterns
- **Examples section** (pages 7–13): 7 real songs (Ipanema, Corcovado, Wave, Dindi, etc.) cross-referenced by chord number

This is the **ideal template test case** — it demonstrates all three layout types: Cover + Item (per-chord) + Example (song).

---

## Layout Structure

| Pages | Current | New Layout | Notes |
|-------|---------|-----------|-------|
| 1 | Cover teaser | `.cover` | Poster-style: big chord diagram + fun facts |
| 2–6 | Chord entries (10×) | `.item` (repeat) | Method-book: badge + title + diagram + prose + practice pattern + margin notes |
| 7–13 | Song examples (7×) | `.example` (repeat) | Songbook: legend + notation + tags + callout |

---

## Content Analysis

### Page 1: Cover Teaser
**Current:** Big "TOP 10" title, CMaj7(9) diagram + staff notation, badge #01, footer with website.

**Quality:** ✓ Excellent. Clear visual hierarchy, good color contrast.

**For new design:** Repurpose as `.cover` layout. Keep title, diagram, badge. Add three-column fact strip below:
- "10 catalog entries" + value
- "7 real songs" + value
- "1 secret weapon" + value (or extract from copy)

---

### Pages 2–6: Chord Entries

**Current:** Each chord has:
1. Big numeral (01–10) + heading (e.g., "The Major Seventh Chord with 9")
2. 2–3 sentence blurb (pedagogical: what the chord is, why it matters)
3. Chord diagram (fretboard + staff notation)
4. "Suggested Practice Pattern" label
5. Practice pattern (another diagram + TAB notation)
6. Tiny footer

**Sample entry (#02, "The Minor Seventh Chord with 9"):**
> "Drop the third of a plain m7 chord by a step and you land on the m9 — softer, rounder, and far less stark than its parent chord. The jazz bossa standard Blue Bossa makes extensive use of this chord..."

**Quality:** ✓ Very strong. Copy is clear, accurate, musically smart. Diagrams are clean.

**For new design:** Use `.item` layout per entry:
- Badge #N (17mm circle, gradient)
- Title ("The Minor Seventh Chord with 9")
- Diagram feature box (left side of intro)
- Lede sentence (italic, right side of intro)
- Body prose (running 2–3 sentences)
- "Suggested Practice Pattern" section label
- Practice pattern image
- Margin column: Listen (song reference) / Try this (technique tip) / Related (cross-reference to other materials)

---

### Pages 7–13: Song Examples

**Current:** Each song section has:
1. Song title (e.g., "The Girl from Ipanema")
2. Context line ("Bars 1–8 · built entirely from three chords in this catalog")
3. Chord legend (e.g., "01=FMaj7(9), 02=Cm7(9), 08=G7(9)")
4. Notation excerpt (lyrics + TAB)
5. Small numbered badges inline/above the notation (e.g., "01" "08") tagging active chords
6. Observation paragraph (how the example illustrates the catalog entries)

**Example (#1, "The Girl from Ipanema"):**
> "Almost the entire A section alternates between just two voicings from this catalog — entry 01 and entry 08. Once those two shapes are comfortable, this song is largely a rhythm exercise rather than a chord-memorisation one."

**Quality:** ✓ Excellent pedagogical device. Cross-reference badges (01, 02, 08) are a genuinely smart instructional pattern — ties abstract catalog to real music.

**For new design:** Use `.example` layout per song:
- Eyebrow ("Repertoire Example · 1 of 7")
- Title ("The Girl from Ipanema")
- Subtitle ("Bars 1–8 · built entirely from three chords in this catalog")
- Legend (flex row: "Chords used" + chips with badges + chord names)
- Notation image (full-width)
- Tag row (small badges positioned below notation, showing active chords in excerpt)
- Callout note (bordered left, the observation paragraph)
- Footer (standard)

---

## Content Quality Issues

### Minor Issues (No Rewrite Needed)

1. **Chord #5 blurb typo:** "fundemantal chord progression" → should be "fundamental"
   - **Fix:** Correct in new template.

2. **Footer domain mismatch:** Different pages show different websites (`bossanova-gitarre.de` vs. `www.joachimribbentrop.de`)
   - **New design:** Standardize footer to `soulbossanova.com` (primary domain) or include both in footer note.

### Taxonomy Clarification Needed

**Vierklänge PDF** (separate file) has running header "BASIC SEVENTH CHORDS · Intermediate Level" but filename says "LEVEL 2"—inconsistent level naming.
- **For this TOP10:** Level is not explicitly labeled. Recommend: Eyebrow or subtitle clarifies audience ("Intermediate to Advanced" or "Essential Voicings").

---

## Assets Ready for Reuse

### Chord Diagrams
All 10 chord diagrams (CMaj7(9), Cm7(9), C7(9), Cm7b5(9), Co7(9), etc.) can be:
- **Extracted** as high-res PNG crops from source PDF (via `pdftoppm -r 300`)
- **OR rendered fresh** from DB via `sbnRenderDiagramSVG()` if chord slugs are in `sbn_chord_diagrams` table

**Recommendation:** Render from DB (cleaner, vectorizes for print quality, no rasterization artifacts).

### Notation/TAB Excerpts
All 7 song examples (Ipanema, Corcovado, Wave, Dindi, Manhã de Carnaval, Insensatez, Blue Bossa) show 4–8 bars with lyrics + TAB.

- **If song exists in `sbn_leadsheets` DB:** Render notation via `sbn-song` Vue component (future print variant).
- **If not in DB:** Extract as high-res crop from source PDF.

**Current status:**
- ✓ In DB: `the-girl-from-ipanema`, `untitled-6` (Corcovado), `untitled-7` (Wave), `dindi`, `black-orpheus` (Manhã de Carnaval), `joao-gilberto-insensatez` (Insensatez)
- ? Possibly in DB: `body-and-soul` (not checked; may be under a different slug)

---

## Recommendations for New Design

1. **Reuse all copy verbatim** — it's excellent. Only fix the typo in #5.
2. **Upgrade diagrams to DB rendering** — all 10 chords are (presumably) in `sbn_chord_diagrams`; verify and render fresh.
3. **Extract notation as high-res crops** — until full song notation print rendering is production-ready.
4. **Strengthen cross-reference badges** — the #01 #02 #08 pattern is a pedagogical superpower; make them visually prominent (already designed as `.badge--sm` with gradient in new system).
5. **Add margin column asides to item pages** — context the current PDF lacks: which songs use this chord? Related voicing? Playing technique?
6. **Standardize footer** — pick one domain, one format (left | center | right).

---

## Next Steps

1. Verify chord slugs in `sbn_chord_diagrams` table. Render all 10 fresh via `sbnRenderDiagramSVG()`.
2. Extract notation crops from source PDF at 300 DPI. Organize in `assets/notation-crops/ipanema/`, etc.
3. Populate `.item` template instances (pages 2–6) with chord copy, badges, diagrams, practice patterns, margin notes.
4. Populate `.example` template instances (pages 7–13) with song titles, legends, notation, tags, callouts.
5. Proof in real Browsershot (not weasyprint sandbox). Verify superscript on all chord names.
6. Sign off. Archive source PDF as reference.
