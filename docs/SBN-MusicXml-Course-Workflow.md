# Converting Reference Scores into Courses — MusicXML Workflow

**Purpose:** turning a reference file (theory chart, chord-progression sheet, lead sheet) into
Soul Bossa Nova course content (`sbn_courses` / `sbn_lessons`).

---

## Source format: MusicXML over PDF

**Prefer MusicXML over PDF as the source** whenever the file contains notation or chord
symbols. MusicXML is structured XML — chord roots, qualities, bass notes, key signatures, and
section labels are all explicit tags. PDF sheet music is just vector/image drawing with no
semantic chord data, so extracting it reliably means OCR on noteheads and chord-symbol text,
which is much less trustworthy.

**You can drop a `.mscz`/`.mscx` straight into the leadsheet editor (local dev only).** The file input
accepts `.mscz`/`.mscx`; the editor POSTs it to `/admin/leadsheets/convert-mscz`, which runs the
MuseScore CLI server-side and feeds the resulting MusicXML into the normal import. This needs MuseScore
installed on the machine serving the route (true locally; on prod the route returns a clean error since
no binary is present). Set `MUSESCORE_BIN` in `.env` if MuseScore isn't at the default
`C:\Program Files\MuseScore 4\bin\MuseScore4.exe`.

There's also a batch CLI alternative if you'd rather convert files up front:

```bash
python scripts/export_mscz.py "docs/Some Score.mscz"      # writes Some Score.musicxml alongside
python scripts/export_mscz.py docs/scores -o docs         # whole folder → docs/*.musicxml
```

**Import runs three pre-passes in `MusicXMLParser` (in `resources/views/admin/leadsheets/edit.blade.php`),
so any import path gets them for free:**

1. **`selectTabStaff()`** — a guitar score often has a standard-notation staff AND a tab staff (often
   notation first). This keeps only the staff whose notes carry `<string>/<fret>` data, so you read fret
   positions, not the pitch-only notation staff. (No tab staff anywhere → pitch-only import as before.)
2. **`flattenVoices()`** — collapses any measure with ≥2 voices to a single voice. Each onset becomes
   one chord-stack; duration clipped to the next onset on any voice; held-note tails dropped (lossy on
   duration, lossless on pitch). Only notes that *attack* at an onset stack there — a held bass/inner
   note is not re-articulated at every onset it spans.
3. Single-voice measures whose voice isn't `1` (e.g. a tab staff on voice 5) are normalized to voice 1,
   otherwise the model's global multi-voice flag flips every stem upward.

Together these replace the old manual "flatten to one voice in MuseScore" step. The tab editor is
single-voice by design, so this is the expected, lossy-on-duration simplification — pitches/onsets are
preserved.

PDF is fine as a source only when the content is already plain prose/text (no notation to
parse) — at that point it's no different from any other text document.

---

## Extraction gotchas learned the hard way

- A score can split `<harmony>` tags across parts inconsistently — measure 5's chords might
  live on the piano part, measure 12's on the guitar part. Always merge harmony across *all*
  parts per measure number; never trust a single part to have complete data.
- MuseScore exports a Roman-numeral-analysis layer (`<numeral><numeral-root>`) as separate
  `<harmony>` tags alongside the real chord symbols. Don't assume they're positionally paired
  1:1 — cross-check against the actual chord letters when in doubt.
- A `<harmony>` with `kind="none"` and no root is usually a "hold the previous chord, don't
  reprint the symbol" marker, not a real chord — verify against raw XML rather than guessing.
- The document's key signature (`<fifths>`) is not necessarily the key an illustrative excerpt
  is "in" — a progression can be deliberately shown in a different, more recognizable key
  (e.g. a Pachelbel example shown in C even though the piece is in G). Use judgment, not just
  the key signature, when describing what's happening.
- `<words>` / `<credit-words>` elements often carry section labels (e.g. "Full Cadence",
  "Pachelbel-Kadenz") — collect these across all parts too; they're the best signal for how
  to chunk the source into lessons.

---

## Reusable script

`scripts/extract_musicxml_harmony.py` does the cross-part merge mechanically — run it first
instead of re-deriving regex extraction from scratch:

```bash
python3 scripts/extract_musicxml_harmony.py "docs/Some File.musicxml" --measures 1-58
```

It does NOT do music-theory interpretation (naming the progression, picking a key) — that
still needs a manual/Claude pass.

**This script is harmony/chord-symbol-only — it's the wrong tool for scale or fingering
charts.** A source file that's really a fretboard-position/scale diagram (no `<harmony>` tags)
won't yield anything useful from it. Instead, parse notes directly with `xml.etree.ElementTree`,
pulling `note/notations/technical/string` + `fret` (tab data), `note/lyric/text` (scale-degree
labels), and `direction/.../words` (the German/English prose annotations) per measure. There's
no existing script for this path yet — write a one-off extraction script per source file.

**MusicXML string numbering is inverted relative to the `fretboards` table.** MusicXML
`<string>` is 1 = high e … 6 = low E. The `fretboards.voicings[].dots[].s` field (see
`SBN-Fretboard-Reference.md` §3) is 0 = low E … 5 = high e. Convert with `s_idx = 6 -
musicxml_string` when building fretboard diagram records from tab data.

---

## Sandbox note

Files outside the connected workspace folder (e.g. `/tmp/...`) can't be reached by the `Write`
tool. For scratch scripts that only need to exist in the bash sandbox, create them with a bash
heredoc (`cat > /tmp/script.py << 'EOF' ... EOF`) instead.

---

## Relationship to the PDF pipeline plan

`SBN-PDF-Pipeline-Plan.md` describes a *different* pipeline (DB data → printable shop PDFs via
Browsershot) and deliberately avoids an automated MusicXML reader for that v1. That decision is
about generating PDFs, not consuming them, and doesn't conflict with the guidance above for
importing reference scores into courses.

---

## Worked example

Course id 70, "Chord Progressions & Voice Leading" (8 lessons, slugs `building-the-diatonic-chords`
through `voice-leading-through-progressions`), was built from
`docs/AKKORDE - Akkordfolgen.musicxml` using this workflow — see git history / DB for the
resulting lesson content if a similar source file needs the same treatment.
