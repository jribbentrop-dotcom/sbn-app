# Course 9 Rewrite — Full Lesson Draft (2026-06-29)

> ⚠️ **APPLIED 2026-06-29.** The DB truncation noted below was resolved same-day and
> `scripts/apply_course9_rewrite.py` has been run against a healthy `sbn.db`. Course 9 now has 12
> lessons (9 new + 3 resequenced) and the 4 new technique nodes are live (`guitar-posture-setup`,
> `pima-finger-assignment`, `rest-stroke-free-stroke`, `hand-damping-control`, ids 65–68). See
> `docs/SBN-Skill-System-Reference.md` (header, "Course 9 technique rewrite APPLIED 2026-06-29") for the
> as-applied confirmation. This document remains the canonical source of the lesson prose — the
> original drafting-session notes below are kept for context.
>
> Full draft (not outline) per Lucas's instruction. Implements brainstorm-crossref recommendation #1
> ("Build out a real technique sub-curriculum") — see `docs/archive/SBN-Skill-Nodes-Brainstorm-Crossref.md`
> and the Course-9 status block in `docs/SBN-Skill-System-Reference.md` (header).
>
> **DB note (was true 2026-06-29, now fully resolved):** `database/sbn.db` was truncated during the
> drafting session (header expected 53,428,224 bytes); it was restored same-day — verified healthy
> (`integrity_check: ok`, 54,210,560 bytes) — and `scripts/apply_course9_rewrite.py` has since been run
> successfully against it. Nothing here required DB access to draft — it was new content, not a query
> against existing rows.
>
> Because of the truncation, **the exact titles/content of Course 9's existing 3 lessons could not be
> re-read** to incorporate verbatim. From the crossref doc we know their subjects: a Villa-Lobos étude,
> a Tárrega/Sor piece, and Gilberto's batida. The plan below moves them to the end, unedited, as the
> "Applying It" capstone — the apply script resequences them by `sort_order` without touching their
> `content`, so this is safe regardless of their exact current text.

## What changes

**Course 9** ("Right Hand Technique for Nylon Guitar") currently has 3 lessons organized around
repertoire. The crossref found this is a genuine content gap: the brainstorm's 37 posture/RH/LH
mechanics nodes have no decomposed, atomic lesson content anywhere in the catalog. This rewrite adds
**9 new foundational lessons** teaching the mechanics directly — PIMA, rest/free stroke, tone
production, RH/thumb independence, damping, slurs, slides/vibrato — then keeps the existing 3
repertoire lessons as the closing "now apply it" section.

Scope call (per the crossref's open question): this stays at the **foundational** tier the
recommendation actually asked for — PIMA, rest/free stroke, posture, nail tone, slurs, damping. It does
**not** reach into the brainstorm's "Advanced Performance" tier (harmonics, campanella, rasgueado,
Travis picking, classical tremolo, counterpoint) — that's still the open scope question in the crossref
doc, not resolved here.

### New course-level copy

**Title:** *Right Hand Technique for Nylon Guitar* (unchanged — still accurate, now actually delivers
on it)

**Excerpt:**
> The fingerstyle fundamentals every nylon-string player needs: how to sit, how to name and use your
> right-hand fingers, the two ways to pluck a string, and the independence and articulation skills
> that make everything else — bossa patterns, classical pieces, jazz comping — possible.

**Description:**
> Most guitar courses teach technique through repertoire: you learn a piece, and the mechanics come
> along for the ride. This course does the opposite. It breaks right- and left-hand fingerstyle
> technique into its component parts — posture and setup, the PIMA finger-naming system, rest stroke
> vs. free stroke, nail and flesh tone production, right-hand and thumb independence, damping, and
> slurs — and teaches each one as its own skill, with focused practice routines. By the end, you'll
> have the vocabulary and the physical habits to approach the three repertoire studies that close the
> course (and everything else in the nylon-string catalog) with a real technical foundation instead of
> imitation alone.

### New lesson order

| # | Section | Title | Slug | Preview | Maps to skill node(s) |
|---|---|---|---|---|---|
| 1 | Setup & Right-Hand Foundations | Posture, Setup & Tuning | `posture-setup-tuning` | yes | `guitar-posture-setup` *(new)* |
| 2 | Setup & Right-Hand Foundations | The PIMA System: Naming Your Right-Hand Fingers | `pima-finger-system` | no | `pima-finger-assignment` *(new)* |
| 3 | Setup & Right-Hand Foundations | Rest Stroke vs. Free Stroke | `rest-stroke-free-stroke` | no | `rest-stroke-free-stroke` *(new)*, `fingerpicking-basics` |
| 4 | Setup & Right-Hand Foundations | Nail & Flesh: Shaping Your Tone | `nail-flesh-tone` | no | `tone-production` |
| 5 | Right-Hand Independence & Patterns | Right-Hand Independence: Basic Arpeggio Patterns | `right-hand-arpeggio-patterns` | no | `right-hand-independence`, `fingerpicking-basics` |
| 6 | Right-Hand Independence & Patterns | Thumb Independence: Bass Lines Under Arpeggios | `thumb-independence-bass-lines` | no | `thumb-independence` |
| 7 | Left Hand & Articulation | Damping & Muting: Clean Stops, Quiet Strings | `damping-and-muting` | no | `hand-damping-control` *(new)* |
| 8 | Left Hand & Articulation | Slurs: Hammer-Ons and Pull-Offs | `hammer-ons-and-pull-offs` | no | `legato-slurs` |
| 9 | Left Hand & Articulation | Slides and Basic Vibrato | `slides-and-vibrato` | no | `legato-slurs` |
| 10 | Applying It: Repertoire Studies | *(existing lesson — Gilberto's batida)* | *(unchanged)* | no | `two-four-feel`, `syncopation`, `pulse-subdivision` |
| 11 | Applying It: Repertoire Studies | *(existing lesson — Tárrega/Sor study)* | *(unchanged)* | no | `arpeggio-shapes`, `legato-slurs` |
| 12 | Applying It: Repertoire Studies | *(existing lesson — Villa-Lobos étude)* | *(unchanged)* | no | `arpeggio-shapes`, `right-hand-independence` |

Existing-lesson node mappings in rows 10–12 are a best guess from the crossref's description of their
subjects (samba batida → rhythm nodes; classical étude/study → arpeggio + slur/RH-independence nodes).
Worth a quick confirm-or-correct pass once the DB is back and the actual lesson content is visible.

Course-level `sbn_course_skill_node` rows for course 9 become: `guitar-posture-setup`,
`pima-finger-assignment`, `rest-stroke-free-stroke`, `tone-production`, `right-hand-independence`,
`thumb-independence`, `hand-damping-control`, `legato-slurs`, `fingerpicking-basics`, plus the
already-mapped `pulse-subdivision`, `syncopation`, `two-four-feel`, `arpeggio-shapes` (carried over from
the current mapping in `SBN-Skill-System-Reference.md`).

---

## Lesson 1 — Posture, Setup & Tuning

`slug: posture-setup-tuning` · `section_title: Setup & Right-Hand Foundations` · `is_preview: 1`

```html
<p>Before a single note sounds good, your body has to be in a position that lets both hands do their
job without fighting the instrument. This lesson covers how to sit, how to hold the guitar, and how to
get it in tune — the unglamorous setup work that every other lesson in this course assumes you've
already sorted out.</p>

<h2 id="section-why-posture-matters">Why Posture Matters</h2>
<p>Bad posture doesn't just look uncomfortable — it actively works against your technique. If the
guitar is sliding around on your leg, part of your attention (and your fretting-hand strength) is
spent just holding it still instead of playing. If your right wrist is bent at a sharp angle to reach
the strings, you'll fatigue faster and lose precision exactly where you need it most. Five minutes
spent getting setup right pays for itself in every practice session afterward.</p>

<h2 id="section-classical-posture">Classical Posture (Footstool)</h2>
<p>The traditional classical setup uses a footstool under your left foot (if you're right-handed),
raising that knee so the guitar's waist rests on your left thigh. The neck angles up and slightly
toward you, putting the fretboard within easy reach without hunching your shoulders forward. The
guitar is held in place by light contact at four points — your chest, your right forearm, your left
leg, and your right leg — not by gripping with your hands.</p>
<p>This is the most stable, most "free hands" setup there is, which is why it's the standard for
serious classical and fingerstyle playing. If you don't have a footstool, a low stack of books or a
guitar-specific support works the same way.</p>

<h2 id="section-casual-posture">Casual Posture (Strap, No Footstool)</h2>
<p>Plenty of great playing happens without a footstool — sitting cross-legged, using a strap while
seated, or standing with a strap. The non-negotiable is the same regardless of setup: the guitar
should stay still on its own, freeing both hands to move without also stabilizing the instrument. If
you notice yourself squeezing the guitar between your arm and ribs to keep it from sliding, that's a
sign the current position isn't doing its job and is worth adjusting before you build technique on top
of it.</p>

<h2 id="section-holding-the-guitar">Holding the Guitar Steady</h2>
<p>Check these four contact points, whichever posture you use:</p>
<ul>
<li>The guitar's waist sits on your leg (or against your body, with a strap) without you holding it there.</li>
<li>Your right forearm rests near the bridge — not gripping, just resting.</li>
<li>Your left hand is free to move up and down the neck without that movement destabilizing the guitar.</li>
<li>Your shoulders are relaxed, not hiked up toward your ears.</li>
</ul>

<h2 id="section-tuning-up">Tuning Up</h2>
<p>Standard nylon-string tuning, low to high, is E–A–D–G–B–E — identical to steel-string guitar. Use a
clip-on tuner or a tuning app every time you sit down to practice; nylon strings stretch and drift more
than steel, especially on a newer set, so don't assume yesterday's tuning held. Tune up to pitch rather
than down into it where possible — coming up from slightly flat settles the string into the nut and
tuning peg more reliably than coming down from sharp.</p>

<sbn-info heading="Practice focus" items="Set up your footstool or support and check all four contact points before you play|Tune from scratch at the start of every practice session, not just when something sounds off|Sit for two full minutes in position without playing — notice any tension and adjust before you start"></sbn-info>
```

## Lesson 2 — The PIMA System: Naming Your Right-Hand Fingers

`slug: pima-finger-system` · `section_title: Setup & Right-Hand Foundations`

```html
<p>Classical and fingerstyle guitar uses a naming system for the right hand borrowed from Spanish:
<strong>P-I-M-A</strong>. Every technique lesson from here on refers to fingers by these letters, so
this is the vocabulary lesson that makes the rest of the course readable.</p>

<h2 id="section-meet-pima">Meet P, I, M, and A</h2>
<ul>
<li><strong>P (pulgar)</strong> — thumb</li>
<li><strong>I (índice)</strong> — index finger</li>
<li><strong>M (medio)</strong> — middle finger</li>
<li><strong>A (anular)</strong> — ring finger</li>
</ul>
<p>The pinky (sometimes labelled <strong>C</strong>, for <em>chico</em>) is not part of the standard
system — it's rarely used in fingerstyle playing and stays tucked lightly against the body of the
guitar near the soundhole or bridge, sometimes lightly anchored for stability.</p>

<h2 id="section-why-a-system">Why a Naming System</h2>
<p>Once a piece of music has a thumb playing a bass line while three fingers handle three different
treble strings simultaneously, "use your fingers" stops being useful instruction. PIMA lets a teacher,
a score, or your own practice notes specify exactly which finger plays which note — and because every
classical and fingerstyle method uses the same four letters, it's a universal language across method
books, sheet music, and teachers.</p>

<h2 id="section-assigning-fingers-to-strings">Assigning Fingers to Strings</h2>
<p>The default "home" assignment, used as a starting point for most patterns:</p>
<ul>
<li><strong>P</strong> — the three bass strings (6th, 5th, 4th), moving to whichever is currently the bass note</li>
<li><strong>I</strong> — 3rd string (G)</li>
<li><strong>M</strong> — 2nd string (B)</li>
<li><strong>A</strong> — 1st string (high E)</li>
</ul>
<p>This is a default, not a law — plenty of patterns break it deliberately (P crossing over to play a
treble note, I playing a bass note in a specific passage) — but it's the assignment to fall back on
until a piece tells you otherwise, and it's what the next few lessons build from.</p>
<p>[NOTATION: hand diagram showing P/I/M/A resting on strings 6-3-2-1 in the home position]</p>

<h2 id="section-practicing-pima">Practicing the Names</h2>
<p>Before worrying about technique quality, just build the naming reflex: rest all four digits on
their home strings (P on the 6th string, I-M-A on 3rd-2nd-1st) and pluck each one in turn, saying the
letter out loud as you play it — "P... I... M... A...". This feels slow and unnecessary for about two
days. By day three it stops being a translation step and starts being automatic, which is the whole
point — every pattern in this course is taught using these letters.</p>

<sbn-info heading="Practice focus" items="Say the letter out loud as you pluck each finger — P, I, M, A in order|Rest fingers on their home strings between repetitions rather than floating above them|Five slow minutes daily beats one fast session — this is a reflex, not a one-time lesson"></sbn-info>
```

## Lesson 3 — Rest Stroke vs. Free Stroke

`slug: rest-stroke-free-stroke` · `section_title: Setup & Right-Hand Foundations`

```html
<p>Every note you pluck with a right-hand finger uses one of two basic strokes. They sound different,
they're used in different contexts, and mixing them up is one of the most common sources of an uneven
fingerstyle tone. This lesson is the foundation everything else in the course's right-hand technique
builds on.</p>

<h2 id="section-two-ways-to-pluck">Two Ways to Pluck a String</h2>
<p>A right-hand finger can move through a string in two ways: it can come to rest against the next
string over (<strong>rest stroke</strong>, Spanish <em>apoyando</em>), or it can clear the next string
entirely and come to rest in the air, ready for the next motion (<strong>free stroke</strong>, Spanish
<em>tirando</em>). Both use the same basic plucking motion from the base knuckle — the difference is
where the finger ends up.</p>

<h2 id="section-rest-stroke-apoyando">Rest Stroke (Apoyando)</h2>
<p>Pluck the string and let your finger travel through it until it lands gently on the adjacent
(thicker) string, where it rests for a moment before lifting off to reset. The extra surface contact at
the end of the motion produces a fuller, rounder, more projected tone — this is the classical
single-line melody stroke, used whenever one voice needs to stand out over an accompaniment.</p>
<p>[NOTATION: side-view diagram of a finger's rest-stroke path, landing on the next string]</p>

<h2 id="section-free-stroke-tirando">Free Stroke (Tirando)</h2>
<p>Pluck the string and lift the finger clear of the next string instead of landing on it — the finger
arcs up and away rather than down into the next string. This is lighter and faster to reset, which is
why it's the default stroke for arpeggios and chord-based patterns where several fingers need to move
independently and in quick succession without bumping into each other's strings.</p>

<h2 id="section-when-to-use-which">When to Use Which</h2>
<p>As a rule of thumb: melody lines and anything that needs to project over an accompaniment use rest
stroke; arpeggios, fast patterns, and anything where multiple fingers are moving close together use
free stroke. A great deal of real fingerstyle and classical repertoire mixes both within a single
piece — a melody note on rest stroke answered by an accompaniment figure on free stroke is one of the
most common textures in the nylon-string vocabulary.</p>

<h2 id="section-practice-routine">Practice Routine</h2>
<p>Practice each stroke in isolation before combining them. Using I, M, and A in turn on the open 1st,
2nd, and 3rd strings:</p>
<ul>
<li>Play 10 repetitions of rest stroke per finger, focused on a clean landing on the next string, not speed.</li>
<li>Play 10 repetitions of free stroke per finger, focused on clearing the next string cleanly without catching it.</li>
<li>Alternate: one rest stroke, one free stroke, same finger, listening for the tone difference between them.</li>
</ul>

<sbn-info heading="Practice focus" items="Isolate each stroke before mixing them — don't rush to combine|Rest stroke should land gently on the next string, not slap it|Free stroke should clear the next string cleanly without catching it"></sbn-info>
```

## Lesson 4 — Nail & Flesh: Shaping Your Tone

`slug: nail-flesh-tone` · `section_title: Setup & Right-Hand Foundations`

```html
<p>Two players with identical technique can sound completely different because of one variable: how
the fingertip meets the string. This lesson covers the physical side of tone — nail shape, contact
point, and angle — that turns a technically correct stroke into a genuinely good sound.</p>

<h2 id="section-two-tone-ingredients">Two Tone Ingredients: Nail and Flesh</h2>
<p>A plucked string can be set in motion by flesh alone (the fleshy pad of the fingertip), by nail
alone, or — most commonly in classical and fingerstyle technique — by a combination where the flesh
makes initial contact and the nail follows through and releases the string. Flesh-only produces a
warmer, softer, more muted tone; nail involvement adds brightness, definition, and projection. Most
players use long nails on the right hand (kept short or trimmed on the left, where nails would get in
the way of fretting) specifically to access this combined tone.</p>

<h2 id="section-nail-shape-and-length">Nail Shape and Length</h2>
<p>If you're growing right-hand nails for tone, the shape matters more than most beginners expect.
Nails should extend just slightly past the fingertip — enough to make contact with the string before
the flesh does, not so much that they catch or click audibly. The edge that contacts the string is
usually shaped at a slight angle and polished smooth (a fine nail file or even a bit of polishing
paper/buffing block removes the microscopic ridges that cause string buzz or a scratchy attack).
Players without strong or healthy natural nails sometimes use acrylic or gel overlays to get a
consistent playing surface — this is a legitimate, common solution, not a shortcut.</p>

<h2 id="section-contact-point">Contact Point on the String</h2>
<p>Where along the string's length you pluck changes the tone independent of nail/flesh balance: closer
to the bridge produces a brighter, more nasal sound (similar in spirit to a violin's <em>sul
ponticello</em>); closer to the soundhole or over the fretboard produces a warmer, rounder sound
(<em>sul tasto</em>). Neither is "correct" — moving your plucking hand along the strings during a piece
is a deliberate, expressive tool, not an error to fix.</p>

<h2 id="section-angle-and-follow-through">Angle and Follow-Through</h2>
<p>The finger should approach the string at a slight angle rather than straight-on, and the
follow-through (where the finger ends up after the string is released — into a rest stroke landing, or
clear of the strings on a free stroke) should be smooth rather than jerky. A common beginner habit is
"attacking" the string with too much initial force and not enough controlled follow-through, which
produces a harsh, inconsistent tone even with good nail shape. Slow, deliberate practice at a quiet
volume is the fastest way to build a controlled motion before adding speed or power.</p>

<h2 id="section-finding-your-sound">Finding Your Sound</h2>
<p>There's no single "correct" tone — listen to recordings of players you admire and notice how bright
or warm, how close to the bridge or soundhole, their sound is. Experiment with your own contact point
and nail/flesh balance on a single repeated note until you can deliberately produce both a bright and a
warm version of the same note on command. That control is the actual goal of this lesson — not a fixed
"right" tone, but the ability to choose.</p>

<sbn-info heading="Practice focus" items="Play the same note 10 times near the bridge, then 10 times near the soundhole — notice the difference|Check your nail edge for smoothness; a rough nail causes string buzz|Practice slow, quiet repetitions before adding speed — tone control comes before power"></sbn-info>
```

## Lesson 5 — Right-Hand Independence: Basic Arpeggio Patterns

`slug: right-hand-arpeggio-patterns` · `section_title: Right-Hand Independence & Patterns`

```html
<p>With PIMA assignments, stroke types, and tone basics in place, this lesson puts them to work: playing
a fixed sequence of fingers in a repeating pattern across the strings, independently of each other.
This is the technical core of fingerstyle accompaniment — every arpeggiated bossa, classical, and folk
pattern you'll ever play is a variation on what's here.</p>

<h2 id="section-from-pima-to-patterns">From PIMA to Patterns</h2>
<p>An arpeggio pattern is just a fixed order in which P, I, M, and A pluck their assigned strings,
repeated for as long as a chord (or section of music) holds. Because each finger keeps returning to the
same string, your hand doesn't need to relocate between notes — only the order of firing changes. This
is what makes arpeggios "automatic" once learned: the pattern becomes a single physical gesture rather
than four separate decisions per repetition.</p>

<h2 id="section-the-pima-arpeggio">The P-I-M-A Arpeggio</h2>
<p>The simplest four-note pattern: P (bass), then I, M, A in order across the three treble strings,
each played once per cycle using free stroke. Practice it slowly over a held position, letting each
finger return to its home string immediately after plucking so it's ready for the next cycle.</p>
<p>[NOTATION: P-I-M-A arpeggio pattern, four-note repeating cycle over a held left-hand position]</p>

<h2 id="section-the-pami-arpeggio">The P-A-M-I Arpeggio</h2>
<p>Reverse the treble order — P, then A, M, I — and the pattern feels noticeably different even though
it's the same four fingers and the same four strings. This is intentional: training both directions
early prevents your hand from learning only one "default" arpeggio shape, which pays off the moment a
piece asks for a pattern that doesn't fit the P-I-M-A mold.</p>

<h2 id="section-rotating-patterns">Rotating and Combining Patterns</h2>
<p>Once both four-note patterns feel even, try six- and eight-note extensions that repeat I-M-A or
double back (P-I-M-A-M-I, for example). The goal at this stage isn't memorizing a long list of patterns
— it's proving to your hand that I, M, and A can fire in any order, evenly, at a steady tempo, with P
holding its own independent rhythm underneath. <sbn-sheet slug="c-major-scale"></sbn-sheet> is a useful
drill surface for this: instead of just playing the scale note-for-note, try applying the P-I-M-A
pattern to a held shape and walking it up the scale degree by degree, keeping the arpeggio pattern
constant while the underlying notes change.</p>

<sbn-info heading="Practice focus" items="Start at a tempo slow enough that every finger lands evenly — speed comes later|Practice P-I-M-A and P-A-M-I separately before mixing them|Keep fingers resting on their home strings between cycles rather than reaching"></sbn-info>
```

## Lesson 6 — Thumb Independence: Bass Lines Under Arpeggios

`slug: thumb-independence-bass-lines` · `section_title: Right-Hand Independence & Patterns`

```html
<p>The thumb (P) has a harder job than it looks like: while I, M, and A handle a repeating treble
pattern, P often needs to move between different bass strings, sometimes in its own independent rhythm
entirely. This lesson isolates that skill — the foundation of every alternating-bass fingerstyle and
bossa nova accompaniment.</p>

<h2 id="section-the-thumbs-job">The Thumb's Job</h2>
<p>In most fingerstyle accompaniment, the thumb is the bass player: it picks out root notes, alternates
between bass strings to imply movement, and keeps time independently of whatever the treble fingers are
doing. Where I, M, and A typically stay anchored to one string each, P needs to travel — which means it
needs its own dedicated practice, separate from the arpeggio patterns in the last lesson.</p>

<h2 id="section-the-pivot-motion">The Pivot Motion</h2>
<p>The thumb moves from the base knuckle in a slight outward-and-down motion, almost like a small
hinge — not from the wrist, and not by reaching with the whole hand. Keeping this pivot small and
consistent is what lets the thumb move fast and quietly between bass strings without throwing off the
hand's overall position. Practice the pivot alone first: alternate P between the 6th and 4th strings (a
common root-to-fifth bass move) at a slow, even tempo, without playing anything with I, M, or A yet.</p>

<h2 id="section-alternating-bass-under-arpeggios">Alternating Bass Under Arpeggios</h2>
<p>Once the pivot feels automatic, add it underneath the arpeggio patterns from the previous lesson.
The treble fingers keep firing I-M-A (or your chosen pattern) in a steady cycle while the thumb
alternates between two bass strings — for example, P on the 6th string for one cycle, then the 4th
string for the next. This is genuinely two independent rhythmic layers happening at once, which is why
it's worth isolating each layer slowly before combining them, rather than trying to learn both at full
speed simultaneously.</p>

<h2 id="section-syncing-thumb-and-fingers">Syncing Thumb and Fingers</h2>
<p>The most common breakdown at this stage is the thumb unconsciously speeding up or slowing down to
"catch up" with the fingers, or vice versa. A metronome is the fastest fix — practice at a tempo slow
enough that you can clearly hear whether P lands exactly on the beat relative to I, M, and A, and only
increase speed once that's solid. Bossa nova's signature feel comes from exactly this kind of
independent-but-locked-together bass and treble movement — <sbn-rhythm slug="gilberto-rhythm"></sbn-rhythm>
is a good reference for what a settled, independent thumb sounds like underneath a steady treble
pattern.</p>

<sbn-info heading="Practice focus" items="Isolate the thumb's pivot motion alone before adding the fingers back in|Use a metronome and check that the thumb lands exactly on the beat|Keep the pivot small — from the base knuckle, not the wrist"></sbn-info>
```

## Lesson 7 — Damping & Muting: Clean Stops, Quiet Strings

`slug: damping-and-muting` · `section_title: Left Hand & Articulation`

```html
<p>Good fingerstyle playing is as much about silence as it is about sound — strings that keep ringing
after they should have stopped turn even a well-played pattern into mud. This lesson covers damping:
the deliberate control of when a string stops, using both hands.</p>

<h2 id="section-why-damping-matters">Why Damping Matters</h2>
<p>A nylon string, left alone, keeps vibrating far longer than most musical phrases need it to. Without
active damping, overlapping ring-on from previous notes blurs into whatever comes next — especially
noticeable on bass notes, which sustain the longest. Damping isn't a special technique reserved for
advanced playing; it's a basic, constant background task that every clean-sounding passage depends on.</p>

<h2 id="section-right-hand-damping">Right-Hand Damping</h2>
<p>The simplest right-hand damping technique rests the edge of the palm lightly against the strings
near the bridge, muting them partially or fully depending on how much pressure is applied. This is the
same motion guitarists use for a palm-muted sound on steel-string guitar, just used here for control
rather than tone color — a light touch stops unwanted ring without killing the note you're currently
playing. Try resting your palm against the strings between phrases and lifting it only when you need
full resonance.</p>

<h2 id="section-left-hand-damping">Left-Hand Damping</h2>
<p>The left hand can also stop a string from ringing simply by releasing fretting pressure without
fully lifting off the string — a light touch against the string silences it instantly. This is
especially useful for bass notes played by the thumb: as the next bass note sounds, lightly touching
(not pressing) the previous bass string with a left-hand finger that's passing nearby stops the old
note from bleeding into the new one.</p>

<h2 id="section-damping-in-context">Damping in Context</h2>
<p>Go back to the arpeggio and bass-line patterns from the last two lessons and listen specifically for
ring-on: does the bass note from one cycle still sound when the next bass note starts? Do the treble
strings keep humming after the pattern moves on? Add damping deliberately at exactly the points where
you hear overlap, rather than damping constantly — over-damping kills the natural sustain that makes
fingerstyle guitar sound full in the first place. The goal is control, not silence.</p>

<sbn-info heading="Practice focus" items="Play a bass-and-arpeggio pattern and listen specifically for unwanted ring-on|Try resting the right-hand palm lightly near the bridge between phrases|Use a light left-hand touch (not a full lift) to stop a bass note from bleeding into the next"></sbn-info>
```

## Lesson 8 — Slurs: Hammer-Ons and Pull-Offs

`slug: hammer-ons-and-pull-offs` · `section_title: Left Hand & Articulation`

```html
<p>So far every left-hand note in this course has been triggered by a right-hand pluck. Slurs break
that rule: the left hand alone sets a string ringing, with no new pluck. This lesson covers the two
basic slurs — hammer-ons and pull-offs — that make fast, fluid melodic lines possible.</p>

<h2 id="section-what-is-a-slur">What Is a Slur?</h2>
<p>A slur connects two notes on the same string without a separate right-hand pluck for the second
note — the left hand does the work of sounding it. This produces a smoother, more connected sound than
plucking every note individually, and it's essential for playing fast melodic passages at speed: the
right hand simply can't pluck as many notes per second as a skilled left hand can slur.</p>

<h2 id="section-hammer-ons">Hammer-Ons</h2>
<p>Pluck a note normally, then bring a left-hand finger down firmly onto a higher fret on the same
string — the impact of the finger striking the fretboard is what sets the new, higher note ringing,
with no right-hand pluck involved. The key is speed and a slightly percussive motion: a soft, slow
hammer produces a weak, quiet second note. Practice hammering from an open string up to a fretted note
first, then between two fretted notes, listening for the second note to be roughly as loud as the
first.</p>
<p>[NOTATION: tab/notation showing a hammer-on between two notes on the same string]</p>

<h2 id="section-pull-offs">Pull-Offs</h2>
<p>The reverse: fret two notes on the same string with two different left-hand fingers, pluck the
string once to sound the higher note, then "pull" the higher finger off and slightly sideways — almost
plucking the string with the left-hand finger itself as it releases — leaving the lower note ringing.
The sideways flick at the moment of release is what gives the lower note its volume; simply lifting
straight up produces a weak, fading second note.</p>

<h2 id="section-combining-slurs">Combining Slurs</h2>
<p><sbn-sheet slug="a-minor-scale"></sbn-sheet> is a good drill surface for both slur types: try playing
the scale ascending using only hammer-ons between adjacent scale notes wherever the fingering allows,
then descending using only pull-offs. Once each direction feels even and clear, mix hammer-ons and
pull-offs within the same scale run — most real melodic lines combine both rather than using one
exclusively.</p>

<sbn-info heading="Practice focus" items="Hammer-ons need a firm, slightly percussive finger strike — not a soft tap|Pull-offs need a small sideways flick on release, not a straight lift|Drill ascending with hammer-ons, descending with pull-offs, then mix the two"></sbn-info>
```

## Lesson 9 — Slides and Basic Vibrato

`slug: slides-and-vibrato` · `section_title: Left Hand & Articulation`

```html
<p>The last two left-hand articulation tools in this course: the slide, which connects two notes by
sliding a fretting finger along the string, and vibrato, which adds a subtle pitch wobble to a sustained
note. Both are expressive tools more than mechanical necessities — they're what turns a technically
correct phrase into a musical one.</p>

<h2 id="section-the-slide">The Slide</h2>
<p>Pluck a note, then — keeping firm pressure on the string — slide the same left-hand finger up or
down the fretboard to a new fret, without re-plucking. Done well, the pitch glides smoothly between the
two notes rather than jumping; done poorly, the string buzzes or loses contact partway through. Keep
the pressure consistent through the whole motion and practice slow, single-fret slides before
attempting longer ones.</p>
<p>[NOTATION: notation showing a slide connecting two notes on the same string]</p>

<h2 id="section-basic-vibrato">Basic Vibrato</h2>
<p>Classical and fingerstyle vibrato is most often produced by a small back-and-forth rocking motion of
the fretting finger along the length of the string (rather than the side-to-side bend common in
electric guitar styles), very slightly raising and lowering the pitch in a controlled wobble. Start
slowly and exaggerated to feel the motion clearly, then narrow it down to a subtle, musical wobble —
vibrato that's too wide or too fast tends to sound nervous rather than expressive.</p>

<h2 id="section-using-slides-and-vibrato-musically">Using Slides and Vibrato Musically</h2>
<p>Both tools are easy to overuse once they're new and fun to do. Apply a slide where a melodic line
genuinely benefits from a connected, glissando feel — often at a phrase's emotional high point — and
save vibrato for sustained notes that have room to breathe, typically at the end of a phrase. As with
tone production in Lesson 4, the real skill here is choosing when to use the effect, not just being able
to execute it.</p>

<sbn-info heading="Practice focus" items="Keep slide pressure consistent through the whole motion — listen for buzz or pitch loss|Start vibrato wide and slow to learn the motion, then narrow it to something subtle|Apply both sparingly and deliberately — at phrase endings or emotional high points, not constantly"></sbn-info>
```

---

## Lessons 10–12 — Applying It: Repertoire Studies (existing, reordered only)

These are Course 9's current 3 lessons, **content unchanged**, moved from the front of the course to
the end and given the shared section title `Applying It: Repertoire Studies`. Per the crossref doc
their subjects are a Villa-Lobos étude, a Tárrega/Sor piece, and Gilberto's batida — exact titles/slugs
need a quick look once `sbn.db` is restored (the apply script resequences by current `sort_order`
rather than by slug, so it doesn't need to know them in advance). Recommended skill-node mappings, to
confirm against actual content:

- **Gilberto's batida lesson** → `two-four-feel`, `syncopation`, `pulse-subdivision` (rhythm application)
- **Tárrega/Sor study** → `arpeggio-shapes`, `legato-slurs` (classical-repertoire application of Lessons 5–9)
- **Villa-Lobos étude** → `arpeggio-shapes`, `right-hand-independence` (the most RH-pattern-demanding of the three, typically)

No new `section_title` value is needed beyond `Applying It: Repertoire Studies` — all three share it,
consistent with how the new lessons share `Setup & Right-Hand Foundations` / `Right-Hand Independence &
Patterns` / `Left Hand & Articulation`.

---

## New skill nodes required

Four new `technique`-branch nodes (none of the brainstorm's 37 mechanics nodes had a built-system
equivalent — see crossref). Definitions below mirror the existing `SkillNodeSeeder.php` format and are
also written directly into that file (see `database/seeders/SkillNodeSeeder.php` diff, same commit as
this doc).

| slug | title | sub_branch | grade | icon_key | prereqs |
|---|---|---|---|---|---|
| `guitar-posture-setup` | Posture & Setup | Foundations | 1 | `user` | *(none)* |
| `pima-finger-assignment` | The PIMA Finger System | Fingerstyle | 1 | `hand-raised` | `guitar-posture-setup` |
| `rest-stroke-free-stroke` | Rest Stroke & Free Stroke | Fingerstyle | 1 | `arrows-right-left` | `pima-finger-assignment` |
| `hand-damping-control` | Hand Damping & Muting | Articulation | 2 | `speaker-x-mark` | `right-hand-independence`, `thumb-independence` |

`fingerpicking-basics` keeps grade 1 / no prereqs as it already has; the new `rest-stroke-free-stroke`
node sits alongside it rather than replacing it (rest/free stroke is the *quality* of a pluck,
fingerpicking-basics is broader "can play a basic fingerstyle pattern at all").

---

## Files touched / produced in this session

- **This doc** — full lesson content draft.
- **`database/seeders/SkillNodeSeeder.php`** — 4 new nodes + edges added to the `NODES` const (file
  edit only, no DB needed — done in this session).
- **`scripts/apply_course9_rewrite.py`** — one-off Python script (parameterized `sqlite3`, safe for
  HTML content with quotes/apostrophes) that, once run against a restored `sbn.db`: inserts the 9 new
  lessons, resequences the existing 3 lessons to the end (by current `sort_order`, not by guessing their
  slugs), upserts the 4 new skill nodes + edges, and updates `sbn_course_skill_node` for course 9.
  Idempotent — safe to re-run.

## Still open

- ~~DB is genuinely truncated~~ — resolved 2026-06-29; `sbn.db` restored and verified healthy.
- ~~Run `scripts/apply_course9_rewrite.py`~~ — **applied 2026-06-29.** Existing-lesson node mappings
  (rows 10–12) were guesses at draft time; still worth a spot-check confirm-or-correct pass against
  the actual lesson content now that it's readable again, but this is a low-priority cleanup, not a
  blocker.
- Course excerpt/description above were written to `sbn_courses` by the apply script — worth Lucas's
  read-through of the live copy if it hasn't happened yet, since it's public-facing.
- The "Advanced Performance" scope question from the crossref (harmonics, campanella, rasgueado, etc.)
  remains unresolved and **out of scope** for this rewrite by design.
