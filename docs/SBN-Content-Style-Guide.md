# SBN Content Style Guide

Living reference for **voice, themes, and vocabulary** across leadsheet, exercise, skill-node, and chord-progression copy. Read this before drafting or editing any `description`, `harmony_notes`, `form_notes`, `voicing_notes`, or progression `intro`/`details` field. Grow it as new artists, styles, or phrases prove useful — don't let it go stale.

This is a content/voice doc, not a data-model doc. For field names, JSON shape, and viewer architecture see [SBN-Leadsheet-Reference.md](SBN-Leadsheet-Reference.md).

---

## 1. Site identity

SBN teaches guitar through the lens of **nylon-string, fingerstyle playing**, with a specific lineage: Brazilian bossa nova (João Gilberto, Tom Jobim) and the jazz vocabulary that feeds into and out of it (Wes Montgomery, and the Great American Songbook more broadly). Classical repertoire (Tárrega, Sagreras, Sanz) supplies the technical foundation; pop/traditional songs are the on-ramp for beginners.

Every piece of content should reinforce this identity where genuinely relevant — not by force-fitting Gilberto into a Tárrega étude, but by consistently returning to the same handful of reference points so a student who reads ten descriptions comes away with a coherent picture of the school's taste, rather than ten unrelated Wikipedia summaries.

### Core reference points (use these, rotate the framing)

- **Nylon-string guitar** — warmer, rounder tone than steel-string; the natural home for fingerstyle and classical technique; forgiving for beginners, expressive at advanced levels.
- **Fingerstyle** — thumb carries bass/rhythm, fingers carry melody/chords; the through-line connecting classical étude technique to bossa comping to jazz chord-melody.
- **Bossa nova** — born late 1950s Rio, samba rhythm smoothed into something intimate and conversational; harmonically it borrows jazz extensions but keeps the touch light.
- **João Gilberto** — the inventor of the modern bossa guitar pattern: syncopated thumb pulse against a whispered vocal. Reference him for rhythmic feel and restraint, not virtuosity.
- **Tom Jobim** — the composer/harmonist side of bossa; reference him for chord movement, melodic economy, and the songs themselves (Wave, Corcovado, Desafinado, etc.).
- **Wes Montgomery** — octaves, thumb-picked single-note lines, block-chord soloing; the jazz-guitar reference for phrasing and voice leading, especially on standards.
- **The Great American Songbook / jazz standards** — the shared repertoire jazz and bossa both draw from; useful when a song (Porter, Carmichael, etc.) predates bossa but is commonly reharmonized in that style.

---

## 2. Voice principles

- **Confident, warm, teacher-to-student** — like a knowledgeable teacher giving context before a lesson, not liner notes or a press release.
- **Specific over florid.** Prefer one concrete musical detail (a rhythm name, a voicing type, a harmonic device) over generic praise ("beautiful," "timeless," "iconic"). Cut adjectives that could apply to any song.
- **Consistent length and structure within a field type** (see §4). Right now the DB has both single-sentence descriptions and multi-paragraph essays with headers/bullets sitting side by side — pick one register per field and hold it.
- **Plain paragraphs, not H3s/bullet dumps**, unless a field specifically calls for a short list (see §4.3–4.4). Descriptions read as prose.

### Fact-checking guardrail

Do not invent specific historical claims (recording dates, album titles, named performances, anecdotes) unless verified. Existing data already has this problem — e.g. a "Love for Sale" description asserts a specific 1973 Ella Fitzgerald/Joe Pass recording detail that reads like invented specificity. When unsure, describe the *musical* characteristics (form, harmony, rhythm, technique) instead of unverifiable history — those claims age better and are more useful to a student anyway.

### Known data bug to watch for

The "Georgia on My Mind" leadsheet currently has the "Dream a Little Dream of Me" description pasted into it verbatim. When regenerating descriptions, check the song title actually matches the content before saving — copy/paste-across-rows has already happened once.

---

## 3. Vocabulary bank

Rotate through these rather than reusing the same sentence across songs. Add to this list as good phrasing emerges.

**Bossa nova / Brazilian:**
syncopated thumb pulse · intimate, conversational rhythm · light touch over jazz harmony · samba filtered down to a whisper · nylon-string warmth · gentle swaying rhythm · melancholic, lyrical melody · modinha roots · saudade (wistful longing) · restrained, unhurried phrasing

**Jazz:**
shell voicings · passing chords · smooth voice leading · AABA form · reharmonization · modal interchange · chord-melody arrangement · comping · swing feel · ii-V-I movement · block chords · walking bass line (when applicable to solo arrangements)

**Fingerstyle / technique:**
thumb-and-finger independence · alternating bass · right-hand picking pattern · melodic independence (melody over accompaniment) · ergonomic left-hand shapes · tension-free technique

**Classical:**
étude · Spanish/Iberian Baroque · pedagogical repertoire · polyphonic texture · hemiola rhythm

**General connective phrases:**
"a natural fit for nylon-string fingerstyle" · "draws on the harmonic language of bossa and jazz" · "a good vehicle for practicing X" · "sits comfortably at [difficulty] level because..."

---

## 4. Field-by-field guidance

### 4.1 `description` (leadsheets, exercises)

Purpose: orient the student — what the piece is, where it comes from, why it's worth learning. 2–4 sentences, one paragraph, plain prose (light `<strong>` for a name/term is fine; no headers, no bullet lists).

Pattern: **[what it is / who wrote it] → [style/harmonic character] → [why a student should care / what it teaches].**

Example shape (not to copy verbatim):
> "[Title]" is a [era/style] piece by [composer], built on [harmonic/rhythmic characteristic]. It sits naturally on nylon-string guitar thanks to [technique reason], and gives a [level] player practice with [specific skill].

### 4.2 `description` (skill nodes)

Purpose: a short paragraph — 2–4 sentences — explaining what the skill covers, why it matters, and where it fits relative to the skills around it on the tree. Aimed at a student deciding whether to open the node, not just naming it.

Pattern: **[what the skill is, concretely] → [why it matters / what it unlocks] → [how it connects to a neighboring skill, prerequisite, or the broader tree].** Lean on §1's core reference points where genuinely relevant (a bossa/Gilberto tie-in for rhythm-feel nodes, Wes Montgomery for jazz-phrasing nodes, nylon-string tone for technique nodes) — don't force it onto nodes where it doesn't fit (e.g. pure ear-training or notation nodes can stay reference-point-free). No headers or bullets; plain prose, same voice principles as §2 (specific over florid, no invented history).

Example shape (not to copy verbatim):
> [Skill] is [concrete description of the mechanic]. [Why this matters practically, or what makes it distinct from a nearby skill]. [How it feeds into or depends on another node on the tree].

This supersedes the older single-sentence convention that used to live here — if you find a lingering one-liner, expand it to this paragraph format rather than leaving it as-is.

### 4.3 `harmony_notes`

Purpose: what's harmonically interesting — key, notable chord movement, reharmonization, modal borrowing, relevant jazz/bossa vocabulary. 1–3 sentences or a short bullet list of 2–4 points if the song has multiple distinct harmonic events worth flagging separately. Assume the reader already knows the description; don't repeat it — go deeper.

### 4.4 `form_notes`

Purpose: song structure — section layout (AABA, verse/chorus, intro/outro), repeats, key changes between sections, anything that affects how the piece is practiced section-by-section. Short: 1–2 sentences or a compact list of sections.

### 4.5 `voicing_notes`

Purpose: guitar-specific — chord shapes/voicings used, fingerstyle pattern, right-hand technique, position/fret-hand notes. This is the most nylon-string/fingerstyle-specific field; lean hardest on §1's core reference points here (e.g. "Gilberto-style syncopated thumb," "Wes-style octaves in the bridge"). 1–3 sentences.

### 4.6 `intro` / `details` (chord progressions, `sbn_chord_progressions`)

Purpose: `intro` orients the student (what the progression is, its harmonic character, a genuinely verified song reference if one already exists); `details` explains the mechanism — the specific voice-leading or root-movement device that makes it work.

Pattern:
- **`intro`** — 2–4 sentences, one paragraph. Name the progression, state its defining harmonic move in one sentence, and land on a hook (a specific device, or a named song already confirmed elsewhere in the DB — don't invent new song attributions here).
- **`details`** — either a short bulleted list (2–3 items) of concrete voice-leading/root-movement facts when the progression has multiple distinct moving parts (most ii-V-I type cadences, turnarounds), or 1–2 plain paragraphs when it's a simpler two-chord vamp/modal move. Close with a sentence connecting it to a related progression, genre variation, or common substitution — don't just restate the intro.

**Badge syntax:** both fields are run through a regex (`formatProgressionProse.ts`) that turns `(token)` into a coloured badge — parenthesize inline to opt in. Only wrap tokens that actually match one of these shapes, or the parens are left as plain dead text:
- **Roman numeral chip** — `(V7)`, `(ii7)`, `(Imaj7)`, `(bVII7)`, `(#idim7)`. Case is cosmetic but meaningful by convention: **uppercase = major/dominant, lowercase = minor**. A bare minor triad is just the lowercase numeral alone — `(i)`, `(iv)`, `(v)` — never `(im)`/`(ivm)`/`(vm)`; a bare "m" suffix does not match the pattern. Half-diminished is `(ii7b5)`, not `(iiø7)` or `(IIm7b5)`.
- **Chord-tone dot** — bare degree, optionally accidental: `(3)`, `(b3)`, `(7)`, `(b9)`, `(root)`.
- **Ordinal tone dot** — spelled out: `(3rd)`, `(b7th)`, `(flat 9th)`.
- **Rhythm-count dot** — subdivision counts only, e.g. `(1e)`, `(2+a)` (bare `1`–`4` alone is treated as a chord tone, not a count).

Don't over-badge — 3–6 well-chosen tokens per field reads better than wrapping every chord mention. Verify a new token actually matches before saving; a mismatched token (like `(VIm7)` or a stray descriptive aside in parens) silently fails to badge and just looks like a typo to the reader.

### 4.7 `intro` / `details` (rhythm patterns, `sbn_rhythm_patterns`)

Purpose: `intro` orients the student (what the pattern is, its stylistic/historical context, a genuinely verified artist or song reference if one exists) and closes with a soft nudge toward the player — "loop it in the player below," "listen for X" — rather than a hard instruction. `details` explains the counting mechanics: which attacks are the real syncopations, which just feel like secondary pulses, and how to practice locking them in.

**Ground every claim in the pattern's own grid data before writing a word.** Decode `rhythm_pattern` (fingers) and `thumb_pattern` (thumb) — `x`/`X` = onset, `.` = rest — against `time_signature`, `beats`, and `grid_type` (`sixteenth` = 4 steps/beat: `1 e + a`; `eighth` = 2 steps/beat: `1 +`; `triplet` = 3 steps/beat: beat, `trip`, `let`). This is exactly the arithmetic `RhythmPattern.vue`'s `beatLabels` computed does — replicate it rather than guessing where an accent falls. Multi-bar patterns (`beats` > one bar's worth of steps) repeat the count from 1 each bar, matching what the player actually displays.

**16th-note grid vs. 8th-note grid — these read differently, say so:**
- On a **16th-note** grid, the `+` (halfway through the beat) is a strong subdivision that feels like a *secondary pulse*, not a push. The genuine syncopations are the `e` and `a` positions — a true 16th-note off-grid attack.
- On an **8th-note** grid, `+` is the *only* subdivision available, so it **is** the real off-beat push.
- Say this explicitly in `details` when a pattern has both bare-beat and subdivided attacks — it's the difference between "this feels grounded" and "this is the hard part."

**Badge syntax gotcha specific to rhythm counts:** `RHYTHM_COUNT_RE` only matches a leading digit **1–4** followed by 1–3 groups of `e`/`+`/`a` (see §4.6). Concretely:
- Bare beat numbers — `(1)`, `(2)`, `(3)`, `(4)` — must **never** be parenthesized: `(1)` fails to match anything (dead text), while `(2)`, `(3)`, `(4)` silently match the *chord-tone* regex instead and render as a wrong-colored dot. Describe bare downbeats in prose ("beat one," "the downbeat") — never in parens.
- Beat numbers **5 and above** (e.g. a 6/8 pattern the player counts through six beats) can't badge at all, even subdivided — `(5+)`, `(6+)` don't match. Describe those in prose too ("the push after beat five").
- Triplet-grid subdivisions (`trip`/`let`) aren't supported by the regex at all — never parenthesize them. Triplet-feel patterns (swing) should be described and practiced by ear/scat-singing, not counted numerically in the copy.
- A plain descriptive parenthetical unrelated to counting — e.g. "Son Clave (2-3)" naming an orientation — safely passes through unbadged as long as it doesn't accidentally match one of the above shapes. Sanity-check it if in doubt.

Close `details` with a one-sentence practice cue (clap or speak the pattern along with the player, isolate the hard bar/attack first) and, where relevant, a connecting sentence to a sibling pattern already in the DB (a reversed/extended variant, the same shape at a different note value, the two-side vs. three-side of a clave).

---

## 5. Extending this guide

When a new artist, style, or recurring phrase comes up while drafting descriptions, add it to §1 or §3 rather than letting it live only in one song's copy. Keep entries short — a sentence or two of "what to say about this" is enough. If a phrase from §3 starts appearing verbatim in every third song, retire or vary it.
