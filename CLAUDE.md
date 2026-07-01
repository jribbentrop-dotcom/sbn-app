# Soul Bossa Nova — Developer Notes for Claude

## Project overview

Laravel + SQLite guitar education app at `C:\Users\info\sbn-app`. Frontend is Vue + Inertia. Students follow structured courses made up of lessons; each lesson contains HTML content with custom Vue components rendered client-side.

The app is live at https://www.soulbossanova.com. Local DB path: `C:\Users\info\sbn-app\database\sbn.db`.

---

## Content voice (critical)

**`docs/SBN-Content-Style-Guide.md` is a living style guide — always consult it before writing or editing `description`, `harmony_notes`, `form_notes`, or `voicing_notes` on leadsheets, exercises, or skill nodes.** It holds the site's voice principles, core themes (nylon-string, fingerstyle, bossa nova, João Gilberto, Tom Jobim, Wes Montgomery), a rotating vocabulary bank, and per-field length/structure rules.

**It is meant to grow.** When the user shares a description they like (something they wrote, found, or heard), pull its essence — phrasing, structure, a new reference point — into the guide rather than only using it once. When working on any content-writing task, actively look for good phrasing worth saving back into the guide, and mention it if you add something. Keep additions short (a sentence or two per entry); if a phrase starts feeling overused, note it for retirement rather than letting it go stale silently.

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
<sbn-sheet slug="wave-cmaj7"></sbn-sheet>
```

### `<sbn-song>`
Renders a SheetMiniPlayer from the `sbn_leadsheets` table. `bars` is 1-indexed inclusive; omit for the full song.
```html
<sbn-song slug="the-girl-from-ipanema-1" bars="5-8"></sbn-song>
<sbn-song slug="the-girl-from-ipanema-1"></sbn-song>
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

### `<sbn-fretboard>`
Interactive fretboard diagram from the `fretboards` table. Only attr is `slug` (all display options are baked into the stored record). Always use an explicit closing tag.
```html
<sbn-fretboard slug="dm7-drop2-voice-leading"></sbn-fretboard>
```
Display modes: `chord`/`sequence` (fret-string voicings), `scale` (multi-dot positions), and `positions` — **one neck-wide scale that slides between named fret windows** (e.g. 5 pentatonic positions in one record) with autoplay/loop. Don't invent slugs; verify against the DB. Full reference: `docs/SBN-Fretboard-Reference.md`.

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

Current slugs (id → slug → title):

| id | slug | title |
|---|---|---|
| 22 | `wave-cmaj7` | Wave CMaj7 |
| 25 | `am7-exercise` | Am7 Exercise |
| 26 | `bossa-nova-basics` | Besame Mucho |
| 27 | `e-minor7-rhythm` | E Minor7 Rhythm |
| 28 | `e-minor7-exercise-ii` | E Minor7 Exercise II |
| 34 | `untitled-2` | So Danco Samba |
| 35 | `guide-tone-lines` | Guide Tone Lines |
| 36 | `three-notes-per-string` | Three Notes Per String |
| 37 | `chromatic-scale` | Chromatic Scale |
| 38 | `basic-scales` | Open Position Scales |
| 39 | `16th-notes` | 16th Notes |
| 40 | `strumming-patterns` | Strumming Patterns |
| 41 | `basic-fingerpicking` | Basic Fingerpicking |
| 42 | `brazilian-rhythms` | Brazilian Rhythms |
| 43 | `top10` | Bossa Nova Chords |
| 44 | `jazz-blues-harmony` | Jazz Blues — Harmony (I, IV, V) |
| 45 | `jazz-blues-melody` | Jazz Blues — Melody |
| 46 | `moanin-theme` | Moanin' — Theme |
| 47 | `birks-works-theme` | Birks Works — Theme |

### Leadsheets (`sbn_leadsheets`)

Full list (72 songs). `license_status`: `public_domain` | `copyrighted` | `cleared` | `unknown`. `is_pro=1` songs get the full Viewer/Cinema arrangement — only valid on `public_domain` rows.

| id | slug | title | license | is_pro |
|---|---|---|---|---|
| 410 | `dream-a-little-dream` | Dream A Little Dream | public_domain | 1 |
| 438 | `georgia-on-my-mind` | Georgia on my mind | public_domain | 1 |
| 463 | `body-and-soul` | Body And Soul | public_domain | 1 |
| 468 | `tico-tico` | Tico Tico | public_domain | 1 |
| 496 | `ode-to-joy` | Ode to Joy | public_domain | 1 |
| 497 | `swonderful` | S'Wonderful | public_domain | 1 |
| 500 | `vals` | Vals | public_domain | 1 |
| 501 | `estudio` | Estudio | public_domain | 1 |
| 505 | `e-preciso-perdoar` | E' Preciso Perdoar | copyrighted | 0 |
| 507 | `scarborough-fair` | Scarborough Fair | public_domain | 1 |
| 508 | `amazing-grace` | Amazing Grace | public_domain | 1 |
| 509 | `mack-the-knife` | Mack the Knife | public_domain | 1 |
| 517 | `por-una-cabeza` | Por Una Cabeza | copyrighted | 0 |
| 519 | `romance` | Romance | public_domain | 1 |
| 526 | `nesta-rua` | Nesta Rua | public_domain | 1 |
| 527 | `gee-baby-aint-i-good-to-you` | Gee Baby, Ain't I Good to You | public_domain | 1 |
| 530 | `greensleeves` | Greensleeves | public_domain | 1 |
| 544 | `wellerman` | Wellerman | public_domain | 1 |
| 546 | `samba-da-bencao` | Samba da Benção | copyrighted | 0 |
| 547 | `what-is-this-thing-called-love` | What is this thing called love? | public_domain | 1 |
| 548 | `canon-in-d` | Canon in D | public_domain | 0 |
| 550 | `corcovado` | Corcovado | copyrighted | 0 |
| 551 | `the-girl-from-ipanema-1` | The Girl from Ipanema | copyrighted | 0 |
| 552 | `manha-de-carnaval-jazz` | Manha de Carnaval | copyrighted | 0 |
| 553 | `wave` | Wave | copyrighted | 0 |
| 554 | `blue-bossa` | Blue Bossa | copyrighted | 0 |
| 555 | `desafinado` | Desafinado | copyrighted | 0 |
| 556 | `insensatez` | Insensatez | copyrighted | 0 |
| 557 | `song-for-my-father` | Song For My Father | copyrighted | 0 |
| 560 | `night-and-day` | Night and Day | copyrighted | 0 |
| 562 | `love-for-sale` | Love for Sale | public_domain | 1 |
| 563 | `watch-what-happens` | Watch What Happens | copyrighted | 0 |
| 564 | `the-shadow-of-your-smile` | The Shadow of Your Smile | copyrighted | 0 |
| 565 | `incompatibilidade-de-genios` | Incompatibilidade de Gênios | copyrighted | 0 |
| 566 | `one-note-samba` | One Note Samba | copyrighted | 0 |
| 567 | `fotografia` | Fotografia | copyrighted | 0 |
| 568 | `i-cant-give-you-anything-but-love` | I Can't Give You Anything But Love | public_domain | 1 |
| 569 | `londonderry-air` | Londonderry Air | public_domain | 1 |
| 570 | `chega-de-saudade` | Chega de Saudade | copyrighted | 0 |
| 571 | `dindi` | Dindi | copyrighted | 0 |
| 574 | `in-the-hall-of-the-mountain-king` | In The Hall Of The Mountain King | public_domain | 1 |
| 575 | `on-green-dolphin-street` | On Green Dolphin Street | copyrighted | 0 |
| 576 | `moon-and-sand` | Moon and Sand | copyrighted | 0 |
| 577 | `ill-remember-april` | I'll Remember April | copyrighted | 0 |
| 579 | `brigas-nunca-mais` | Brigas nunca mais | copyrighted | 0 |
| 580 | `canarios` | Canarios | public_domain | 1 |
| 581 | `the-birth-of-the-blues` | Birth of the Blues, The | public_domain | 1 |
| 582 | `aquarela-do-brasil` | Aquarela do Brasil | copyrighted | 0 |
| 583 | `gentle-rain-the` | The Gentle Rain | copyrighted | 0 |
| 584 | `gymnopedie-1` | Gymnopedie #1 | public_domain | 0 |
| 585 | `st-james-infirmary` | St. James Infirmary | public_domain | 1 |
| 586 | `so-danco-samba-joao` | So Danco Samba | copyrighted | 0 |
| 588 | `avarandado` | Avarandado | copyrighted | 0 |
| 589 | `acapulco` | Acapulco | copyrighted | 0 |
| 590 | `shenandoah` | Shenandoah | public_domain | 1 |
| 595 | `exercise-in-c` | Study in C Major | public_domain | 1 |
| 597 | `untitled` | Without a Song | unknown | 0 |
| 600 | `the-man-i-love` | The Man I Love | copyrighted | 0 |
| 601 | `agua-de-beber` | Agua de Beber | copyrighted | 0 |
| 602 | `as-time-goes-by` | As time goes by | copyrighted | 0 |
| 603 | `maria-luisa` | Maria Luisa | copyrighted | 0 |
| 607 | `sons-de-carrilhoes` | Sons de Carrilhoes | copyrighted | 0 |
| 609 | `once-i-loved` | Once I Loved | copyrighted | 0 |
| 614 | `happy-birthday` | Happy Birthday | unknown | 0 |
| 615 | `blue-monk` | Blue Monk | unknown | 0 |
| 616 | `choros-n-1` | Choros N° 1 | unknown | 0 |
| 617 | `heres-that-rainy-day` | Here's that rainy day | unknown | 0 |
| 618 | `entertainer` | Entertainer, The | unknown | 0 |
| 619 | `allegretto-in-b-minor` | Allegretto in B-Minor | unknown | 0 |
| 620 | `berimbau` | Berimbau | unknown | 0 |
| 621 | `i-like-the-flowers` | I like the flowers | unknown | 0 |
| 628 | `estrada-do-sol` | Estrada do Sol | unknown | 0 |

Songs with `unknown` license need classification before `is_pro` can be set. Use `scripts/apply_song_license.py`.

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
| 1 | bossa-nova-basics | Bossa Nova Basics | publish | free, beginner, bossa-nova |
| 2 | easy-bossa-nova-songs | Easy Bossa Nova Songs | publish | free, bossa-nova |
| 3 | bossa-nova-chords-ii | Bossa Nova Chords | publish | free, bossa-nova |
| 4 | bossa-nova-rhythm | Bossa Nova Rhythm | **draft** | free, bossa-nova |
| 5 | choro-guitar-masterpieces | Choro: The Ancestor of Bossa Nova | publish | free, bossa-nova |
| 6 | gilberto-plays-jobim | Gilberto plays Jobim | publish | free, bossa-nova |
| 7 | latin-side-pat-metheny | The Latin Side of Pat Metheny | publish | free, jazz |
| 8 | latin-side-wes-montgomery | The Latin Side of Wes Montgomery | publish | free, jazz |
| 9 | right-hand-technique | Right Hand Technique for Nylon Guitar | publish | free, classical |
| 10 | the-clave | The Clave: Latin Rhythm 101 | publish | free, bossa-nova |
| 11 | melody-playing-nylon-guitar | Melody Playing on Nylon Guitar | publish | free, jazz |
| 12 | music-theory-basics | Music Theory Basics | publish | free, app intro course |
| 68 | solo-guitar-joe-pass | Solo Guitar Style of Joe Pass | **draft** | paid, jazz — lessons exist but all draft |
| 69 | diminished-chords-bossa-nova | Diminished Chords — The Secret Weapon of Bossa Nova | **draft** | paid, bossa-nova — lessons exist but all draft |
| 70 | chord-progressions-and-voice-leading | Chord Progressions & Voice Leading | publish | free, jazz |
| 71 | pentatonic-scale-five-positions | The Pentatonic Scale: Five Positions | publish | free, pop |
| 72 | intervals-building-blocks-of-harmony | Intervals: The Building Blocks of Harmony | publish | free, pop |
| 73 | the-caged-system | The CAGED System | publish | free, basic, pop |
| 74 | diatonic-chords-and-the-nashville-number-system | Diatonic Chords & the Nashville Number System | publish | free, intermediate, pop |
| 75 | arpeggio-shapes-the-five-chord-qualities | Arpeggio Shapes: The Five Chord Qualities | publish | free, intermediate, jazz |
| 76 | approach-notes-and-enclosures | Approach Notes & Enclosures | publish | free, intermediate, jazz |
| 77 | syncopation-off-beat-rhythms | Syncopation & Off-Beat Rhythms | **draft** | free, pop |
| 78 | brazilian-rhythms | Brazilian Rhythms | **draft** | free, bossa-nova |
| 79 | basic-rhythms | Basic Rhythms | **draft** | free, pop |
| 80 | ear-training-fundamentals | Ear Training Fundamentals | publish | free, pop |
| 81 | jazz-blues-guitar | Jazz Blues Guitar | **draft** | paid, intermediate |

---

## Skill node graph (`sbn_skill_nodes`)

The skill tree is a curriculum graph — each node is a skill students work toward. Nodes link to courses via `sbn_course_skill_node` and to actual content (lessons, exercises, etc.) via `sbn_skill_node_content`.

**Key tables:**
- `sbn_skill_nodes` — id, slug, title, branch, sub_branch, grade (1–5), completion_type
- `sbn_skill_node_prerequisites` — skill_node_id, requires_skill_node_id
- `sbn_course_skill_node` — course_id, skill_node_id
- `sbn_skill_node_content` — skill_node_id, content_type (`App\Models\Lesson` etc.), content_id, sort_order

**content_type values:** `App\Models\Lesson`, `App\Models\RhythmPattern`, `App\Models\Leadsheet`, `App\Models\ChordProgression`, `App\Models\Exercise`

**Branches and nodes** (64 total):

| branch | sub_branch | slug | grade |
|---|---|---|---|
| ear-training | Dictation | `rhythm-dictation` | 3 |
| ear-training | Dictation | `melodic-dictation` | 4 |
| ear-training | Recognition | `interval-recognition` | 2 |
| ear-training | Recognition | `chord-quality-recognition` | 3 |
| harmony | Chords | `blues` | 1 |
| harmony | Foundations | `intervals` | 1 |
| harmony | Foundations | `triads` | 2 |
| harmony | Foundations | `chord-inversions` | 2 |
| harmony | Foundations | `diatonic-harmony` | 2 |
| harmony | Progressions | `cadences` | 2 |
| harmony | Progressions | `pop-progressions` | 2 |
| harmony | Progressions | `ii-v-i-major` | 3 |
| harmony | Progressions | `ii-v-i-minor` | 3 |
| harmony | Progressions | `turnarounds` | 3 |
| harmony | Reharmonization | `tritone-substitution` | 4 |
| harmony | Reharmonization | `secondary-dominants` | 4 |
| harmony | Reharmonization | `chord-melody` | 5 |
| harmony | Reharmonization | `borrowed-chords` | 5 |
| harmony | Voicings | `the-basic-8` | 1 |
| harmony | Voicings | `shell-voicings` | 2 |
| harmony | Voicings | `drop2-voicings` | 3 |
| harmony | Voicings | `drop3-voicings` | 3 |
| harmony | Voicings | `voice-leading` | 3 |
| melody | Application | `motivic-development` | 5 |
| melody | Application | `improvisation-over-changes` | 5 |
| melody | Foundations | `scale-patterns` | 2 |
| melody | Scales | `foundational-scales` | 1 |
| melody | Scales | `major-minor-scales` | 1 |
| melody | Scales | `blues-scale` | 1 |
| melody | Scales | `chromatic-scale` | 2 |
| melody | Scales | `pentatonic-scale` | 2 |
| melody | Scales | `arpeggio-shapes` | 3 |
| reading-theory | Foundations | `scale-degrees` | 2 |
| reading-theory | Notation | `standard-notation-basics` | 1 |
| reading-theory | Notation | `tab-reading-basics` | 1 |
| reading-theory | Notation | `rhythm-notation` | 2 |
| reading-theory | Systems | `leadsheet-reading` | 3 |
| reading-theory | Systems | `nashville-number-system` | 3 |
| rhythm | Application | `comping-patterns` | 3 |
| rhythm | Feels | `bossa-syncopated-push` | 2 |
| rhythm | Feels | `alternating-bass-patterns` | 2 |
| rhythm | Feels | `two-four-feel` | 2 |
| rhythm | Feels | `syncopation` | 2 |
| rhythm | Feels | `waltz-feel` | 2 |
| rhythm | Feels | `swing-feel` | 3 |
| rhythm | Feels | `polyrhythm` | 5 |
| rhythm | Foundations | `meter-basics` | 1 |
| rhythm | Foundations | `pulse-subdivision` | 1 |
| rhythm | Latin Rhythm | `partido-alto-groove` | 3 |
| rhythm | Latin Rhythm | `clave-systems` | 3 |
| rhythm | Latin Rhythm | `brazilian-rhythm-styles` | 3 |
| technique | *(none)* | `the-spider` | 1 |
| technique | Articulation | `hand-damping-control` | 2 |
| technique | Articulation | `legato-slurs` | 2 |
| technique | Articulation | `tone-production` | 2 |
| technique | Fingerstyle | `pima-finger-assignment` | 1 |
| technique | Fingerstyle | `rest-stroke-free-stroke` | 1 |
| technique | Fingerstyle | `fingerpicking-basics` | 1 |
| technique | Fingerstyle | `right-hand-independence` | 2 |
| technique | Fingerstyle | `thumb-independence` | 2 |
| technique | Foundations | `guitar-posture-setup` | 1 |
| technique | Fretboard | `barre-chords` | 2 |
| technique | Fretboard | `caged-system` | 3 |
| technique | Fretboard | `position-shifting` | 3 |

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
