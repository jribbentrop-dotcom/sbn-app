# SBN Content Style Guide

Living reference for **voice, themes, and vocabulary** across leadsheet, exercise, and skill-node copy. Read this before drafting or editing any `description`, `harmony_notes`, `form_notes`, or `voicing_notes` field. Grow it as new artists, styles, or phrases prove useful — don't let it go stale.

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

Purpose: one plain sentence explaining what the skill covers, aimed at a student deciding whether to open it. Match the existing good ones already in the DB (e.g. "The basic eight open-position chords (major, minor, and dominant 7th) to get you started playing songs.") — single sentence, concrete, no marketing language.

### 4.3 `harmony_notes`

Purpose: what's harmonically interesting — key, notable chord movement, reharmonization, modal borrowing, relevant jazz/bossa vocabulary. 1–3 sentences or a short bullet list of 2–4 points if the song has multiple distinct harmonic events worth flagging separately. Assume the reader already knows the description; don't repeat it — go deeper.

### 4.4 `form_notes`

Purpose: song structure — section layout (AABA, verse/chorus, intro/outro), repeats, key changes between sections, anything that affects how the piece is practiced section-by-section. Short: 1–2 sentences or a compact list of sections.

### 4.5 `voicing_notes`

Purpose: guitar-specific — chord shapes/voicings used, fingerstyle pattern, right-hand technique, position/fret-hand notes. This is the most nylon-string/fingerstyle-specific field; lean hardest on §1's core reference points here (e.g. "Gilberto-style syncopated thumb," "Wes-style octaves in the bridge"). 1–3 sentences.

---

## 5. Extending this guide

When a new artist, style, or recurring phrase comes up while drafting descriptions, add it to §1 or §3 rather than letting it live only in one song's copy. Keep entries short — a sentence or two of "what to say about this" is enough. If a phrase from §3 starts appearing verbatim in every third song, retire or vary it.
