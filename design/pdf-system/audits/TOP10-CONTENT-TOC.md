# TOP10 Bossa Nova Chords — PDF Content Table of Contents

Working document for content alignment before any layout/rendering work. Reflects all decisions made
through 2026-06-21, now that the full content draft (front matter, all 10 items, all 7 examples) is
complete in `TOP10-CONTENT-DRAFT.md`. Supersedes the chord order/identities implied by `TOP10-AUDIT.md`
(which was run against the German source file) — this TOC follows the resolution rules below.

**Resolution rules agreed:**
- Item identities & order → follow the **live website** config (`config/top10/bossa-nova-chords.php`), not the old PDF, wherever they differ.
- Item descriptions/citations → website copy (English, already richer) is the base text.
- Practice patterns & pedagogical "why it matters" framing → comes from the English source PDF (`docs/TOP10 Bossa Nova Chords.pdf`) plus the DB `top10` leadsheet and, where neither fits, the song's own leadsheet or the website's progression metadata.
- Example songs (7, real-world section) → the English PDF's actual list, cross-checked against each song's real DB leadsheet (not just guessed from the old PDF's chord tags).
- Missing leadsheet (*Once I Loved*) → `[NOTATION: ...]` placeholder.
- Voicing terminology (Shell / Drop 2 / Drop 3 / custom) → new for this redesign, not in the old PDF; introduced once in the front matter so individual items don't need to re-explain it.
- No DB slugs ever appear in user-facing content.
- No bpm numbers ever shown on rhythm grids.

---

## Front Matter — finalized

| Page | Content |
|---|---|
| 1 | **Title page** — title, subtitle, hook line, three teaser facts, footer. Not diagram-led — no chord image or badge on this page (changed from the original audit's diagram-hero cover concept). |
| 2 | **Chord Theory in a Nutshell** — four-part chords (root/3rd/5th/7th), extension tones (9/11/13), this catalog's own voicing vocabulary (Shell / Drop 2 / Drop 3 / custom), and a short explainer of each item's recurring structure (Voicing note vs. caption, Suggested Practice Pattern, the three margin asides). Rewritten from the old PDF's paper diagram/TAB legend, which doesn't apply to this system's interactive chord components. |

Both pages are drafted in full in `TOP10-CONTENT-DRAFT.md` → Front Matter.

---

## Catalog Items 1–10

| # | Title | Display chord | Diagram slug | Citation (song · artist · year) | Status |
|---|---|---|---|---|---|
| 1 | The Major 6/9 Chord | **Db6/9/Ab** | `maj6-custom-roote-inv2-9` | The Girl from Ipanema · Stan Getz & João Gilberto, *Getz/Gilberto* · 1964 | ⚠ Identity changed from old PDF (was CMaj7(9), `maj7-drop2-roota`) |
| 2 | Minor Seventh with 9 | Cm7(9) | `m7-shell-roota-9` | Blue Bossa · Kenny Dorham, *Page One* · 1963 | ⚠ Voicing changed from old PDF (was Drop 2, `m7-drop2-roota`) |
| 3 | The Minor Sixth Chord | Am6 | `m6-drop3-roote` | Corcovado · João Gilberto, *O Amor, O Sorriso E A Flor* · 1960 | Unchanged |
| 4 | Dominant Seventh with 13 | G7(13) | `dom7-drop3-roote-13` | Wave · Antonio Carlos Jobim · 1967 | Unchanged |
| 5 | The Half Diminished Chord | Bm7b5 | `m7b5-drop2-roota` | Manhã de Carnaval · Luiz Bonfá, *Solo In Rio* · 1959 | Unchanged |
| 6 | Dominant Seventh with 9 | D7(9) | `dom7-shell-roota-9` | Fotografia · Astrud Gilberto · 1965 | ⚠ Reordered (was #8 in old PDF) |
| 7 | Dominant Seventh with b9 | E7(b9) | `dom7-shell-roota-b9` | The Gentle Rain · Luiz Bonfá · 1965 | ⚠ Reordered (was #6 in old PDF) |
| 8 | The Diminished Seventh Chord | C#dim7 | `o7-drop2-roota` | Desafinado · Stan Getz & João Gilberto, *Getz/Gilberto* · 1964 | ⚠ Reordered (was #7 in old PDF) |
| 9 | Diminished with Flat 13 | G#dim7(b13) | `o7-drop3-roote-b13` | Insensatez / Corcovado · João Gilberto · 1960 | Unchanged |
| 10 | Dominant Seventh with b13 | G7(b13) | `dom7-drop3-roote-b13` | S'Wonderful · João Gilberto · 1978 | Unchanged |

**Practice pattern source — all confirmed:**
- Items 1, 2, 3, 4, 5, 8, 9, 10 → DB leadsheet `top10` (105 bars, one continuous sequence): bars 0–3, 4–7, 9–12, 20–21, 15–16, 18–20, 25–28, 60–62 respectively.
- Item 6 → the `fotografia` leadsheet itself, bars 0–3 (the generic `top10` sequence didn't have a matching chord for this one).
- Item 7 → the website's own progression metadata (Bm7 → E7(b9) → Amaj6(9)), not the `top10` leadsheet's plain repeated Am6.

---

## Examples — Real Song Applications (7) — finalized against actual DB leadsheets

| # | Song | Bars (actual leadsheet) | DB leadsheet slug | Catalog items featured |
|---|---|---|---|---|
| 1 | The Girl from Ipanema | 0–15 | `the-girl-from-ipanema` | #1 exact (the source of Item #1's own citation); #6, #2, #3 family matches; bar 11's Db6/9→D6/9 slide is the literal move named in Item #1's "Try this" |
| 2 | So Danço Samba | 0–3 | `so-danco-samba` | none exact — the leadsheet is only a 4-bar Em7/A7(b9,13) vamp, so this example runs on rhythm/feel rather than a specific catalog chord. The old tag list (#1/#2/#4/#6) didn't hold up against the real chart and has been dropped. |
| 3 | Blue Bossa | 0–13 | `blue-bossa` | #2, #10 exact (same root as the catalog for both); #5 family |
| 4 | Manhã de Carnaval | 0–30 | `manha-de-carnaval` | #3 family (tonic minor), #5 exact (the central ii–V, also Item #5's own citation). The old tag list also included #2/#4/#7 — dropped, no clean match in the actual chart. |
| 5 | Once I Loved | — | not in DB → `[NOTATION: ...]` placeholder | — |
| 6 | How Insensitive (Insensatez) | 6–9 | `insensatez` | #9 family (bars 6–7), #3 exact (bars 8–9, the tonic) |
| 7 | Corcovado | 0–13 | `corcovado` | #3, #9 exact (the opening pairing, same device as Item #3/#9's own practice patterns); #6, #10 family later in the form |

All seven are drafted in full in `TOP10-CONTENT-DRAFT.md` → Examples 1–7.

---

## Status

Front matter, all 10 catalog items, and all 7 examples are fully drafted in `TOP10-CONTENT-DRAFT.md`.
Content phase is complete. Remaining open items are layout/rendering concerns (paused by design, not
content gaps):
- Exact page count/pagination for the chord-theory and per-item pages.
- Visual treatment of the title page (content is locked; styling TBD).
- Asset gathering (chord diagrams, notation crops) once layout resumes — see `TOP10-LAYOUT-MAP.md`.
