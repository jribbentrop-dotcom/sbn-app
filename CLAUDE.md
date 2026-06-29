# Soul Bossa Nova — Developer Notes for Claude

## Project overview

Laravel + SQLite guitar education app at `C:\Users\info\sbn-app`. Frontend is Vue + Inertia. Students follow structured courses made up of lessons; each lesson contains HTML content with custom Vue components rendered client-side.

The app is live at https://www.soulbossanova.com. Local DB path: `C:\Users\info\sbn-app\database\sbn.db`.

---

## Database workflow (critical)

**Never write directly to the mounted DB.** The SQLite file on the Windows mount causes disk I/O errors and leaves journal files that block further access.

**The "database disk image is malformed" error is almost always mount flakiness, not a corrupt file.** Reads through the Windows mount intermittently return half-written pages, so SQLite reports the image as malformed even when the file on disk is perfectly intact. The fix is to never open the mounted file directly — work against a native local copy and sync back explicitly.

**Always use `scripts/db_checkout.py` — do not hand-roll `cp`.** It does the copy with retries, distinguishes a flaky read (retry → succeeds) from a genuinely truncated file (header check → bail), and verifies integrity on both ends so a single bad read never gets misread as "the DB is corrupt."

```bash
# 1. checkout — copies mount DB to a native local path, retrying past mount flakiness.
#    Prints the local work path; use it for ALL reads and writes.
WORK=$(python3 scripts/db_checkout.py checkout) || exit 1

# 2. do all sqlite work against "$WORK" (e.g. sqlite3.connect(WORK) in Python)

# 3. commit — only when the task actually mutates data and you intend to persist it.
python3 scripts/db_checkout.py commit
```

`python3 scripts/db_checkout.py status` diagnoses without copying (size vs. header, integrity, truncation). PHP / artisan is not available in the sandbox; use Python `sqlite3` against the work path.

**If you hit a malformed/IO error:** it is NOT a signal to give up. Re-run `checkout` — the script already retries internally, and a `status` showing `integrity: ok` confirms the file is fine and the earlier read was a glitch. Only when `db_checkout.py status` reports `truncated: True` (header expects more bytes than the file actually has) is the file genuinely damaged on the Windows host — that cannot be fixed in-sandbox. In that single case, stop, and ask the user to restore `sbn.db` from a backup (or proceed without DB access if the task allows). Do not burn turns re-attempting copies or recovery tricks for a `truncated: False` file.

The local work path (`$HOME/sbn_work/sbn.db`) lives on the container's native disk, never under a mount — anything under `/sessions/.../mnt/...` (including `mnt/outputs/...`) hits the same IO error, so keep working files out of mounted paths.

---

## Custom HTML components (lesson content)

Lesson `content` is HTML rendered by Vue. These custom tags are parsed by `resources/js/lib/mountSbnNodes.ts`:

### `<sbn-chord>`
Renders an interactive chord diagram from the `sbn_chord_diagrams` table.
```html
<sbn-chord slug="dom7-shell-roote" root="C"></sbn-chord>
```
- `slug` must exist in `sbn_chord_diagrams.slug`
- `root` is the displayed root note (moves the label, not the shape)

### `<sbn-rhythm>`
Renders an interactive rhythm pattern player from `sbn_rhythm_patterns`.
```html
<sbn-rhythm slug="gilberto-rhythm"></sbn-rhythm>
```

### `<sbn-sheet>`
Renders tab/notation from the `sbn_exercises` table.
```html
<sbn-sheet slug="c-major-scale"></sbn-sheet>
```

### `<sbn-song>`
Renders a SheetMiniPlayer from the `sbn_leadsheets` table. `bars` is 1-indexed inclusive; omit for the full song.
```html
<sbn-song slug="the-girl-from-ipanema" bars="5-8"></sbn-song>
<sbn-song slug="the-girl-from-ipanema"></sbn-song>
```

### `<sbn-info>`
Practice tip card. Items separated by `|`.
```html
<sbn-info heading="Practice approach" items="Start slowly|Use a metronome|Transpose to all 12 keys"></sbn-info>
```

### `<sbn-widget>`
General widget embed. Only use when you know the slug exists and is appropriate — do not guess.
```html
<sbn-widget slug="drop2"></sbn-widget>
```

### `<sbn-synced-player>`
Synced video/audio player. Requires specific setup — don't add without confirmation.

---

## Old broken formats — always replace

These are WordPress migration artifacts. Strip on sight:

| Old format | Replace with |
|---|---|
| `[alphatex player="yes"]...[/alphatex]` | `<sbn-sheet slug="...">` or `[NOTATION: ...]` placeholder |
| `[rhythm name="..."]` | `<sbn-rhythm slug="...">` |
| `[sbn_leadsheet id="..."]` | `<sbn-song slug="...">` |
| `class="wp-block-heading"` | remove the class attribute |
| `class="wp-block-list"` | remove the class attribute |
| `[PDF DOWNLOAD LINK MISSING]` | remove or replace with prose |
| `[DIAGRAMS MISSING]` | replace with actual `<sbn-chord>` tags or `[NOTATION: ...]` |

---

## Slug naming conventions

### Chord diagrams (`sbn_chord_diagrams`)

Pattern: `{quality}-{voicing_type}-{root_string}[-inv{n}][-{extension}]`

- Root string suffix: `roote` = 6th string, `roota` = 5th string, `rootd` = 4th string
- Voicing types: `shell`, `drop2`, `drop3`, `archetype`, `custom`, `closed_triads`, `quartal`
- Inversions: `-inv1`, `-inv2`, `-inv3`
- Extensions: `-9`, `-b13`, `-s11` (sharp 11), `-13`, etc.

**Shell voicings (confirmed in DB):**
```
maj7-shell-roote    dom7-shell-roote    m7-shell-roote
maj7-shell-roota    dom7-shell-roota    m7-shell-roota
```

**Drop 2 voicings (confirmed in DB):**
```
# A-string root (5 types each)
maj7-drop2-roota    dom7-drop2-roota    m7-drop2-roota    m7b5-drop2-roota    o7-drop2-roota

# D-string root (5 types each)
maj7-drop2-rootd    dom7-drop2-rootd    m7-drop2-rootd    m7b5-drop2-rootd    o7-drop2-rootd
```

**Known gap:** No Drop 2 voicings with E-string root (`roote`) exist yet.

### Rhythm patterns (`sbn_rhythm_patterns`)

Key slugs: `gilberto-rhythm`, `extended-gilberto-rhythm`, `bossa-nova-clave`, `desafinado`, `insensatez`, `choro`, `baiao`, `partido-alto`, `samba`, `swing`, `charleston`, `waltz`, `eighth-note`, `quarter-note`, `half-note`, `whole-note`, `sixteenth-note`, `tresillo`, `son-clave-2-3`, `son-clave-3-2`, `rumba-clave-2-3`, `rumba-clave-3-2`

### Exercises (`sbn_exercises`)

Key slugs: `c-major-scale`, `a-minor-scale`, `d-minor-scale`, `e-minor-scale`, `f-major-scale`, `g-major-scale`, `am7-exercise`, `e-minor7-exercise-ii`, `wave-cmaj7`, `garota-a-part`, `bossa-nova-basics`, `gymnopedie`, `berimbau`, `samba-da-bencao`

### Leadsheets (`sbn_leadsheets`)

Songs confirmed in DB (slug → title):

| slug | title |
|---|---|
| `one-note-samba` | One Note Samba |
| `dindi` | Dindi |
| `joao-gilberto-insensatez` | Insensatez |
| `desafinado` | Desafinado |
| `the-girl-from-ipanema` | The Girl from Ipanema |
| `black-orpheus` | Manhã de Carnaval |
| `brigas-nunca-mais` | Brigas nunca mais |
| `chega-de-saudade` → `untitled-13` | Chega de Saudade |
| `untitled-6` | Corcovado |
| `untitled-7` | Wave |
| `untitled-8` | Blue Bossa |
| `gee-baby-aint-i-good-to-you` | Gee Baby, Ain't I Good To You |
| `body-and-soul` | Body And Soul |
| `untitled-11` | Watch What Happens |
| `untitled-10` | Love for Sale |
| `on-green-dolphin-street` | On Green Dolphin Street |
| `song-for-my-father` | Song For My Father |
| `samba-da-bencao` | Samba da Benção |
| `aqualera-do-brasil` | Aquarela do Brasil |
| `gentle-rain-the` | The Gentle Rain |
| `moon-and-sand` | Moon and Sand |

Songs **not** in DB (use `[NOTATION: ...]` placeholder): Água de Beber, Summertime, A Paz, Once I Loved, How Insensitive (standalone), Corcovado standalone version

---

## Key tables

### `sbn_courses`
```
id, wp_id, slug, title, excerpt, description, genres (JSON), levels (JSON),
style, level, topics (JSON), is_free, product_id, featured_image_path,
sort_order, status, created_at, updated_at, category, learning_outcomes
```
- `genres`: JSON array e.g. `["bossa-nova"]` or `["jazz"]`
- `topics`: JSON array e.g. `["chord voicings","harmony"]`
- `status`: `publish` or `draft`
- `is_free`: 0 or 1

### `sbn_lessons`
```
id, wp_id, course_id, slug, title, content (HTML), section_title,
is_preview, sort_order, status, created_at, updated_at, concept_slug
```
- `is_preview`: 1 = visible to non-enrolled users (use for intro lessons)
- `section_title`: groups lessons in the sidebar
- H2 headings must have `id="section-{slug}"` — the `Lesson` model auto-adds these on save

---

## Courses in DB

| id | slug | title | status | notes |
|---|---|---|---|---|
| 1 | bossa-nova-basics | Bossa Nova Basics | publish | free, basic |
| 2 | easy-bossa-nova-songs | Easy Bossa Nova Songs | publish | |
| 3 | bossa-nova-chords-ii | Bossa Nova Chords | publish | free, intermediate |
| 4 | bossa-nova-rhythm | Bossa Nova Rhythm | publish | |
| 5 | choro-guitar-masterpieces | Choro: The Ancestor of Bossa Nova | publish | |
| 6 | gilberto-plays-jobim | Gilberto plays Jobim | publish | |
| 7 | latin-side-pat-metheny | The Latin Side of Pat Metheny | publish | |
| 8 | latin-side-wes-montgomery | The Latin Side of Wes Montgomery | publish | |
| 9 | right-hand-technique | Right Hand Technique for Nylon Guitar | publish | |
| 10 | the-clave | The Clave: Latin Rhythm 101 | publish | |
| 11 | melody-playing-nylon-guitar | Melody Playing on Nylon Guitar | publish | |
| 12 | music-theory-basics | Music Theory Basics | publish | also an app intro course |
| 68 | solo-guitar-joe-pass | Solo Guitar Style of Joe Pass | draft | paid, advanced, jazz |
| 69 | diminished-chords-bossa-nova | Diminished Chords — The Secret Weapon of Bossa Nova | draft | paid, intermediate |

---

## Content conventions

- Use `[NOTATION: description]` as a placeholder when musical notation is needed but not yet written — never leave `[MUSICAL EXAMPLE MISSING]`
- Do not invent chord diagram or leadsheet slugs — always verify against the DB first
- Lesson intros should be first-lesson in section and set `is_preview=1`
- Section headers in lessons: use `<h2 id="section-{slug}">Title</h2>` — the model auto-fixes missing ids, but including them is cleaner
- Avoid `<p></p>` empty paragraphs and orphaned `<ol><li></li></ol>` structures
- Do not add PDF download links — these are legacy WP artifacts; the equivalent content should be in the exercises lesson

---

## Converting reference scores into courses (source-format workflow)

When turning a reference file (theory chart, chord-progression sheet, lead sheet) into course
content: prefer MusicXML over PDF as the source whenever notation/chords are involved, and run
`scripts/extract_musicxml_harmony.py` rather than re-deriving the extraction from scratch.

Full workflow, gotchas, and a worked example: see `SBN-MusicXml-Course-Workflow.md`.

---

## Content access model (beta)

The app is in beta: **all content is free, but viewing it requires a (free) account.**

### Route gate
- Gated behind `auth` middleware: all `/library/*`, `/theory`, the lesson **player**
  (`/learn/{course}/play[...]`), and the `api/sbn/*` JSON endpoints (data layer for those pages).
- Public (marketing/teaser + auth): `/`, `/shop/*`, `/top10/*`, `/contact`, `/learn` + `/learn/{course}`
  (catalog + course detail), and `api/sbn/synced-player/{slug}` (feeds the Top10 SyncedPlayer demo).
- Guests hitting a gated route are redirected to **`/register`** (not login) via
  `redirectGuestsTo()` in `bootstrap/app.php`. Both `RegisterController` and `LoginController` use
  `redirect()->intended()` so users return to the page they wanted after signing up.
- Auth pages (`Login.vue`/`Register.vue` via `AuthCard.vue`) are styled as a modal-card over a
  blurred backdrop and carry a beta explainer in the `#notice` slot.

### Leadsheet licensing (`sbn_leadsheets`)
Two columns drive what a song exposes:
- `is_pro` (bool) — editorial/monetization switch. `true` ⇒ SBNpro badge + full Viewer/Cinema
  arrangement (tab/melody/synced). `false` ⇒ free reference page only (`show()`: edu text + top-4
  voicings + generic progressions + rhythm). **`is_pro` must only ever be true on `public_domain` rows.**
- `license_status` (string) — legal record: `public_domain` | `copyrighted` | `cleared` | `unknown`
  (constants on the `Leadsheet` model). Not DB-enforced; admin checklist.
- Rule of thumb for PD (US): published year + 95 ≤ current year. Note non-US life+70 jurisdictions
  (e.g. `por-una-cabeza` is 1935, copyrighted).
- Gating lives in `SongLibraryController`: `abortIfDraft()` (status≠publish ⇒ 404, instructors exempt
  on `apiSheet`/`apiSearch`) and `abortIfNotPro()` (viewer/cinema/viewer-data/full-sheet).
  `apiSheet` allows `bars=` excerpts for non-pro songs (lesson `<sbn-song bars="…">` embeds) but
  blocks full-song requests.
- One-off classification scripts: `scripts/apply_song_license.py` (+ `verify_song_license.py`).

There is **no admin UI yet** for `is_pro`/`license_status` — flipping them needs SQL or the scripts.

---

## Bash path mapping (sandbox)

| Windows path | Sandbox path |
|---|---|
| `C:\Users\info\sbn-app\` | `/sessions/.../mnt/sbn-app/` |
| `C:\Users\info\AppData\Roaming\Claude\...\outputs` | `/sessions/.../mnt/outputs/` |
| `C:\Users\info\AppData\Roaming\Claude\...\uploads` | `/sessions/.../mnt/uploads/` (read-only) |
