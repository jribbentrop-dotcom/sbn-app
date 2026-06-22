# TOP10 Item #01 (CMaj7(9)) — Build Log

Working file: `top10-item01-real.html` → `top10-item01-real-preview.pdf` (3 pages: cover / item / example).
This is the prototype for all 10 catalog entries — once signed off, items 2–10 should be mechanical
data substitution, not redesign.

## Data sources used (verified, don't re-derive)
- Chord: `sbn_chord_diagrams` slug `maj7-drop2-roota` — root_note `Bb` (diagram is root-independent,
  displayed as C), positions `{string:2,fret:1},{string:3,fret:3},{string:4,fret:2},{string:5,fret:3}`,
  muted string 1.
- Rhythm: `sbn_rhythm_patterns` slug `gilberto-rhythm` — `2/4`, 8 beats, sixteenth grid,
  `rhythm_pattern='x.x..x..'` (fingers), `thumb_pattern='x...x...'` (thumb), bpm 87.
- Example song: `the-girl-from-ipanema`, bars 1–8, chords `Db6(9)/Ab`, `Eb7(9)/Bb`.

## Fixes applied this round (in order)
1. **Chord-name typography** — production logic is `resources/js/composables/useChordName.ts`
   `formatChordNameHtml()`. Quality is **lowercase** (`maj` not `Maj`), and the parenthetical
   extension is a **separate span** with class `sbn-chord-ext--extra`:
   `<span class="sbn-chord-root">C</span><span class="sbn-chord-quality">maj</span><span class="sbn-chord-ext">7</span><span class="sbn-chord-ext sbn-chord-ext--extra">(9)</span>`.
   Applied wherever a chord name renders (cover, item title, margin panel).
2. **Diagram caption removed** — confirmed via `ChordCard.vue` that production never shows a caption
   below the diagram. Chord name + voicing category moved into a new `.item__margin-identity` block
   at the top of the right margin column, with an orange "Drop 2" pill (`--clr-accent`, matches
   production's `--clr-mod-chord: #f39c12`).
3. **Practice pattern redesigned** — was 2 rhythm SVGs, now 1 rhythm taught in 2 steps:
   - Step 1: rhythm grid with count words. Count-word algorithm ported from
     `RhythmPattern.vue`'s `beatLabels` computed (for 2/4 sixteenth grid: `1 e + a 2 e + a`).
   - Step 2: TAB notation applying that rhythm to the actual chord shape. Onset mapping derived
     by hand from `rhythm_pattern`/`thumb_pattern` strings: full chord on "1", chord-only on "+",
     bass-only on "2", chord-only on "e" of beat 2.
4. **TAB fret-number font fixed** — production's real styling (confirmed in both
   `render-tab-v2.cjs` and the live CSS class `.sbn-tab-note-text` in `sbn-design-system.css`) is
   `font-family: 'Crimson Text', Georgia, serif; font-size: 13px; font-weight: 900; fill: #222;`
   with a white halo (`stroke: #fff; stroke-width: 3px;`) so digits stay legible crossing the
   staff lines. The pre-existing Ipanema example (page 3) had been built from the **legacy**
   `render-tab.cjs` (v1) which has no font-family/halo at all — also fixed to match.
   - **Weasyprint gotcha:** `paint-order="stroke fill"` (what production CSS and `render-tab-v2.cjs`
     both use) is **not supported by weasyprint**. It silently paints the white stroke over the
     fill, making every digit invisible — no error, just blank. Fix: emit two stacked `<text>`
     elements instead — one `fill="none" stroke="#fff"` (halo), one plain `fill="#222"` (digit) —
     relying on SVG paint order (later element on top) rather than the `paint-order` property.
     **Apply this same two-element pattern to any future hand-built TAB/notation SVG for PDF.**
   - Added `@font-face` for Bravura (`public/fonts/Bravura.woff2/.woff/.otf`) for parity with
     production, even though this item's content has no flags/rests so it isn't actually exercised.

## Known-good reference points for future items
- `resources/js/composables/useChordName.ts` — chord name HTML generation (authoritative).
- `resources/js/Components/Library/ChordCard.vue` — card layout (name above diagram, no caption).
- `resources/js/Components/Library/RhythmPattern.vue` — `beatLabels` count-word logic.
- `scripts/pdf/render-tab-v2.cjs` — canonical TAB→SVG renderer for PDF use (the "clean port").
  Prefer running this script over hand-building TAB SVGs where possible — hand-building is what
  caused the font-mismatch bug in the first place.
- `public/css/sbn-design-system.css` `.sbn-tab-note-text` — ground truth for TAB digit styling.

## Open / pending Lucas's next feedback pass
Re-shared PDF after the font fix; awaiting specific polish notes (not yet itemized as of this log).
