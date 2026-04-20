# Prompt for fresh Opus session — finish the triplet roundtrip fix

## Context

We are working on SBN (Laravel music education app), branch `phase-b-chord-grid`.
A prior Sonnet session diagnosed and partially fixed a MusicXML triplet
roundtrip bug. Read `docs/Triplet-Roundtrip-Analysis.md` first — it contains
the full trace, what Sonnet got right, where the analysis broke down, and the
recommended remaining fix.

**Sonnet's two landed fixes are correct and must stay:**
- `resources/js/tab-editor/utils/musicXmlWriter.js` — `ticksToDivDuration(ticks, event)` now scales nominal ticks by `normal/actual` for tuplets.
- `resources/views/admin/leadsheets/edit.blade.php` — melody assembly now scales imported `ticks` by `tupletNormal/tupletActual`.

## Remaining symptom (only after edit + save + reload)

Open `docs/TARREGA - Etude in Em (Easy).musicxml` in the tab editor. Edit
bar 1: convert the first two 8th-note triplets (beat 1) to two normal 8th
notes, leaving the 8th-triplets on beats 2-3 intact. Save, reload.

- The beats 2-3 triplet block renders with a bracket + stems (visually looks like a quarter-note triplet) instead of a beamed 8th-triplet group.
- The two normal 8ths on beat 1 are not beamed together.
- Editing *any* note after reload fixes the display (because `recomputeBeams` rebuilds `beamStart/Continue/End` from tick adjacency).

## Task

1. **Read `docs/Triplet-Roundtrip-Analysis.md`** for the full trace and recommended fix.
2. **Reproduce the bug** by saving the Tarrega file after an edit and inspecting the raw SBN-written XML — specifically look at:
   - Are `<beam>` elements emitted? (Hypothesis: no, because `event.beam1` is never set by `recomputeBeams`.)
   - What `<tuplet>` attributes are written on start vs. stop events?
3. **Apply the primary fix** in `resources/js/tab-editor/utils/musicXmlWriter.js`: in `serializeNote`, derive the `<beam>` tag from `event.beamStart / beamContinue / beamEnd` (set by `recomputeBeams`) with `event.beam1` as a fallback for unedited imports. See the analysis doc for the exact snippet.
4. **Apply the secondary fix** in the parser at `resources/views/admin/leadsheets/edit.blade.php` (search for `tupletBracket` in the `parseNotes` / note parsing section): treat only `bracket="yes"` as true (explicit), so a missing bracket attribute defaults to `false`.
5. **Verify the full roundtrip** on the Tarrega file:
   - Fresh import renders correctly (no regression).
   - After edit + save + reload, bar 1 renders: 2 beamed 8ths on beat 1, 4 beamed 8th-triplets across beats 2-3 (single beam bar, "3" label, no bracket).
   - Other bars (with 6 8th-triplets) continue to render as two triplet groups across the measure.

## What not to do

- **Do not** undo Sonnet's two fixes. They are correct.
- **Do not** try to fix this inside `recomputeBeams` or `linkUnbeamedTuplets` — the real problem is that beam intent is lost on save, forcing reload through the lossy heuristic path. Fix it at the writer.
- **Do not** over-invest in the "red bar" symptom — the user confirmed it's gone after Sonnet's fixes.

## Files to focus on

- `resources/js/tab-editor/utils/musicXmlWriter.js` — primary fix here
- `resources/views/admin/leadsheets/edit.blade.php` — parser, secondary fix for bracket default
- `resources/js/tab-editor/composables/useTabModel.js:348-490` — reference for `computeBeams` / `beamsFromXml` / `beamsHeuristic` / `linkUnbeamedTuplets` (read only; do not modify)
- `resources/js/tab-editor/composables/useReflow.js:113-211` — reference for `recomputeBeams` (read only; do not modify)
- `resources/js/tab-editor/utils/svgHelpers.js` — reference for bracket rendering (`useBracket = noBeamBar ? bg[0].tupletBracket : false`)

## Constraints

- Terse commits only. No refactoring beyond what the bug requires.
- No new abstractions. No speculative error handling.
- Don't add comments that restate what the code does. A one-line rationale for the bracket-default change is fine.
