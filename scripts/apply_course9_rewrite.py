#!/usr/bin/env python3
"""
apply_course9_rewrite.py — applies the Course 9 ("Right Hand Technique for Nylon
Guitar") foundational-technique rewrite drafted in
docs/Course-9-Technique-Rewrite-Full-Draft-2026-06-29.md.

Written during a session where database/sbn.db was genuinely truncated (per
db_checkout.py status — host-side damage, not mount flakiness), so this could
not be applied or even verified against live data. Run it once sbn.db has been
restored from a backup.

What it does (all idempotent — safe to re-run):
  1. Upserts 4 new `technique` skill nodes + their prerequisite edges
     (also mirrored in database/seeders/SkillNodeSeeder.php's NODES const).
  2. Inserts 9 new lessons into course 9, keyed by slug (skips if a lesson
     with that slug already exists under course 9).
  3. Resequences course 9's existing lessons (anything NOT one of the 9 new
     slugs) to sort after the new lessons, preserving their relative order.
     Does NOT touch their content, title, or slug.
  4. Updates sbn_course_skill_node for course 9 to the full new node set.
  5. Updates sbn_courses.excerpt / sbn_courses.description for course 9.

Usage (per the CLAUDE.md db workflow — never run against the mounted path
directly):
    WORK=$(python3 scripts/db_checkout.py checkout) || exit 1
    python3 scripts/apply_course9_rewrite.py "$WORK"
    python3 scripts/db_checkout.py commit

Verify after running:
    python3 scripts/apply_course9_rewrite.py "$WORK" --dry-run   # prints plan, writes nothing
"""
import sqlite3
import sys
import datetime

COURSE_ID = 9

NOW = datetime.datetime.utcnow().strftime("%Y-%m-%d %H:%M:%S")

# ── 1. New skill nodes ──────────────────────────────────────────────────────
NEW_NODES = [
    # slug, title, branch, sub_branch, grade, icon_key
    ("guitar-posture-setup", "Posture & Setup", "technique", "Foundations", 1, "user"),
    ("pima-finger-assignment", "The PIMA Finger System", "technique", "Fingerstyle", 1, "hand-raised"),
    ("rest-stroke-free-stroke", "Rest Stroke & Free Stroke", "technique", "Fingerstyle", 1, "arrows-right-left"),
    ("hand-damping-control", "Hand Damping & Muting", "technique", "Articulation", 2, "speaker-x-mark"),
]

NEW_EDGES = [
    # (node_slug, requires_slug)
    ("pima-finger-assignment", "guitar-posture-setup"),
    ("rest-stroke-free-stroke", "pima-finger-assignment"),
    ("hand-damping-control", "right-hand-independence"),
    ("hand-damping-control", "thumb-independence"),
]

# Full skill-node set course 9 should map to after the rewrite (new + carried over
# from the existing mapping in docs/SBN-Skill-System-Plan.md "Course → Node Mapping").
COURSE9_NODE_SLUGS = [
    "guitar-posture-setup", "pima-finger-assignment", "rest-stroke-free-stroke",
    "tone-production", "right-hand-independence", "thumb-independence",
    "hand-damping-control", "legato-slurs", "fingerpicking-basics",
    "pulse-subdivision", "syncopation", "two-four-feel", "arpeggio-shapes",
]

# ── 2. New lessons (slug, title, section_title, is_preview, content) ───────
# sort_order assigned 0..8 in this order, ahead of the existing 3 lessons.
LESSON_1 = """<p>Before a single note sounds good, your body has to be in a position that lets both hands do their
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

<sbn-info heading="Practice focus" items="Set up your footstool or support and check all four contact points before you play|Tune from scratch at the start of every practice session, not just when something sounds off|Sit for two full minutes in position without playing — notice any tension and adjust before you start"></sbn-info>"""

LESSON_2 = """<p>Classical and fingerstyle guitar uses a naming system for the right hand borrowed from Spanish:
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

<sbn-info heading="Practice focus" items="Say the letter out loud as you pluck each finger — P, I, M, A in order|Rest fingers on their home strings between repetitions rather than floating above them|Five slow minutes daily beats one fast session — this is a reflex, not a one-time lesson"></sbn-info>"""

LESSON_3 = """<p>Every note you pluck with a right-hand finger uses one of two basic strokes. They sound different,
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

<sbn-info heading="Practice focus" items="Isolate each stroke before mixing them — don't rush to combine|Rest stroke should land gently on the next string, not slap it|Free stroke should clear the next string cleanly without catching it"></sbn-info>"""

LESSON_4 = """<p>Two players with identical technique can sound completely different because of one variable: how
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

<sbn-info heading="Practice focus" items="Play the same note 10 times near the bridge, then 10 times near the soundhole — notice the difference|Check your nail edge for smoothness; a rough nail causes string buzz|Practice slow, quiet repetitions before adding speed — tone control comes before power"></sbn-info>"""

LESSON_5 = """<p>With PIMA assignments, stroke types, and tone basics in place, this lesson puts them to work: playing
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

<sbn-info heading="Practice focus" items="Start at a tempo slow enough that every finger lands evenly — speed comes later|Practice P-I-M-A and P-A-M-I separately before mixing them|Keep fingers resting on their home strings between cycles rather than reaching"></sbn-info>"""

LESSON_6 = """<p>The thumb (P) has a harder job than it looks like: while I, M, and A handle a repeating treble
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

<sbn-info heading="Practice focus" items="Isolate the thumb's pivot motion alone before adding the fingers back in|Use a metronome and check that the thumb lands exactly on the beat|Keep the pivot small — from the base knuckle, not the wrist"></sbn-info>"""

LESSON_7 = """<p>Good fingerstyle playing is as much about silence as it is about sound — strings that keep ringing
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

<sbn-info heading="Practice focus" items="Play a bass-and-arpeggio pattern and listen specifically for unwanted ring-on|Try resting the right-hand palm lightly near the bridge between phrases|Use a light left-hand touch (not a full lift) to stop a bass note from bleeding into the next"></sbn-info>"""

LESSON_8 = """<p>So far every left-hand note in this course has been triggered by a right-hand pluck. Slurs break
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

<sbn-info heading="Practice focus" items="Hammer-ons need a firm, slightly percussive finger strike — not a soft tap|Pull-offs need a small sideways flick on release, not a straight lift|Drill ascending with hammer-ons, descending with pull-offs, then mix the two"></sbn-info>"""

LESSON_9 = """<p>The last two left-hand articulation tools in this course: the slide, which connects two notes by
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

<sbn-info heading="Practice focus" items="Keep slide pressure consistent through the whole motion — listen for buzz or pitch loss|Start vibrato wide and slow to learn the motion, then narrow it to something subtle|Apply both sparingly and deliberately — at phrase endings or emotional high points, not constantly"></sbn-info>"""

NEW_LESSONS = [
    # slug, title, section_title, is_preview, content
    ("posture-setup-tuning", "Posture, Setup & Tuning", "Setup & Right-Hand Foundations", 1, LESSON_1),
    ("pima-finger-system", "The PIMA System: Naming Your Right-Hand Fingers", "Setup & Right-Hand Foundations", 0, LESSON_2),
    ("rest-stroke-free-stroke", "Rest Stroke vs. Free Stroke", "Setup & Right-Hand Foundations", 0, LESSON_3),
    ("nail-flesh-tone", "Nail & Flesh: Shaping Your Tone", "Setup & Right-Hand Foundations", 0, LESSON_4),
    ("right-hand-arpeggio-patterns", "Right-Hand Independence: Basic Arpeggio Patterns", "Right-Hand Independence & Patterns", 0, LESSON_5),
    ("thumb-independence-bass-lines", "Thumb Independence: Bass Lines Under Arpeggios", "Right-Hand Independence & Patterns", 0, LESSON_6),
    ("damping-and-muting", "Damping & Muting: Clean Stops, Quiet Strings", "Left Hand & Articulation", 0, LESSON_7),
    ("hammer-ons-and-pull-offs", "Slurs: Hammer-Ons and Pull-Offs", "Left Hand & Articulation", 0, LESSON_8),
    ("slides-and-vibrato", "Slides and Basic Vibrato", "Left Hand & Articulation", 0, LESSON_9),
]

NEW_COURSE_EXCERPT = (
    "The fingerstyle fundamentals every nylon-string player needs: how to sit, how to name and use "
    "your right-hand fingers, the two ways to pluck a string, and the independence and articulation "
    "skills that make everything else — bossa patterns, classical pieces, jazz comping — "
    "possible."
)

NEW_COURSE_DESCRIPTION = (
    "Most guitar courses teach technique through repertoire: you learn a piece, and the mechanics come "
    "along for the ride. This course does the opposite. It breaks right- and left-hand fingerstyle "
    "technique into its component parts — posture and setup, the PIMA finger-naming system, rest "
    "stroke vs. free stroke, nail and flesh tone production, right-hand and thumb independence, "
    "damping, and slurs — and teaches each one as its own skill, with focused practice routines. "
    "By the end, you'll have the vocabulary and the physical habits to approach the three repertoire "
    "studies that close the course (and everything else in the nylon-string catalog) with a real "
    "technical foundation instead of imitation alone."
)


def main():
    args = [a for a in sys.argv[1:] if not a.startswith("--")]
    dry_run = "--dry-run" in sys.argv
    if not args:
        print("Usage: apply_course9_rewrite.py <path-to-work-copy-of-sbn.db> [--dry-run]", file=sys.stderr)
        sys.exit(1)
    db_path = args[0]

    con = sqlite3.connect(db_path)
    con.execute("PRAGMA foreign_keys = ON")
    cur = con.cursor()

    # Sanity check: course 9 exists and is the expected course.
    row = cur.execute("SELECT slug, title FROM sbn_courses WHERE id = ?", (COURSE_ID,)).fetchone()
    if not row:
        print(f"FATAL: no course with id={COURSE_ID} found.", file=sys.stderr)
        sys.exit(1)
    slug, title = row
    if slug != "right-hand-technique":
        print(f"WARNING: course {COURSE_ID} slug is '{slug}', expected 'right-hand-technique'. "
              f"Title: {title!r}. Continuing, but double-check this is the right course.", file=sys.stderr)

    plan = []

    # ── 1. Skill nodes ───────────────────────────────────────────────────────
    node_id_by_slug = {}
    for r in cur.execute("SELECT slug, id FROM sbn_skill_nodes"):
        node_id_by_slug[r[0]] = r[1]

    for slug_, title_, branch, sub_branch, grade, icon_key in NEW_NODES:
        if slug_ in node_id_by_slug:
            plan.append(f"skill node '{slug_}' already exists (id={node_id_by_slug[slug_]}) — skip insert")
            continue
        plan.append(f"INSERT skill node '{slug_}' ({title_})")
        if not dry_run:
            cur.execute(
                """INSERT INTO sbn_skill_nodes
                   (slug, title, branch, sub_branch, grade, icon_key, completion_type, content_tag_slug, sort_order, created_at, updated_at)
                   VALUES (?, ?, ?, ?, ?, ?, 'self_report', NULL, 0, ?, ?)""",
                (slug_, title_, branch, sub_branch, grade, icon_key, NOW, NOW),
            )
            node_id_by_slug[slug_] = cur.lastrowid

    for node_slug, requires_slug in NEW_EDGES:
        nid = node_id_by_slug.get(node_slug)
        rid = node_id_by_slug.get(requires_slug)
        if not nid or not rid:
            plan.append(f"SKIP edge {node_slug} -> {requires_slug} (missing node id; run with real DB)")
            continue
        plan.append(f"INSERT OR IGNORE edge {node_slug} -> {requires_slug}")
        if not dry_run:
            cur.execute(
                "INSERT OR IGNORE INTO sbn_skill_node_prerequisites (skill_node_id, requires_skill_node_id) VALUES (?, ?)",
                (nid, rid),
            )

    # ── 2. New lessons ───────────────────────────────────────────────────────
    existing_slugs = {
        r[0] for r in cur.execute("SELECT slug FROM sbn_lessons WHERE course_id = ?", (COURSE_ID,))
    }
    new_slugs = {s for s, *_ in NEW_LESSONS}

    for i, (lesson_slug, lesson_title, section_title, is_preview, content) in enumerate(NEW_LESSONS):
        if lesson_slug in existing_slugs:
            plan.append(f"lesson '{lesson_slug}' already exists under course {COURSE_ID} — skip insert")
            continue
        plan.append(f"INSERT lesson '{lesson_slug}' ({lesson_title}) sort_order={i}")
        if not dry_run:
            cur.execute(
                """INSERT INTO sbn_lessons
                   (wp_id, course_id, slug, title, content, section_title, is_preview, sort_order, status, concept_slug, created_at, updated_at)
                   VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, 'publish', NULL, ?, ?)""",
                (COURSE_ID, lesson_slug, lesson_title, content, section_title, is_preview, i, NOW, NOW),
            )

    # ── 3. Resequence existing (non-new) lessons to the end ─────────────────
    existing_other = list(
        cur.execute(
            "SELECT id, slug, sort_order FROM sbn_lessons WHERE course_id = ? AND slug NOT IN ({}) ORDER BY sort_order".format(
                ",".join("?" * len(new_slugs)) if new_slugs else "''"
            ),
            (COURSE_ID, *new_slugs),
        )
    )
    base = len(NEW_LESSONS)
    for offset, (lesson_id, lesson_slug, old_sort) in enumerate(existing_other):
        new_sort = base + offset
        plan.append(f"RESEQUENCE existing lesson id={lesson_id} slug={lesson_slug!r}: sort_order {old_sort} -> {new_sort}")
        if not dry_run:
            cur.execute(
                "UPDATE sbn_lessons SET sort_order = ?, section_title = ?, updated_at = ? WHERE id = ?",
                (new_sort, "Applying It: Repertoire Studies", NOW, lesson_id),
            )

    # ── 4. Course → skill-node pivot ────────────────────────────────────────
    node_id_by_slug = {r[0]: r[1] for r in cur.execute("SELECT slug, id FROM sbn_skill_nodes")}
    for slug_ in COURSE9_NODE_SLUGS:
        nid = node_id_by_slug.get(slug_)
        if not nid:
            plan.append(f"SKIP course_skill_node for '{slug_}' (node id not found)")
            continue
        plan.append(f"INSERT OR IGNORE course_skill_node course={COURSE_ID} node='{slug_}'")
        if not dry_run:
            cur.execute(
                "INSERT OR IGNORE INTO sbn_course_skill_node (course_id, skill_node_id) VALUES (?, ?)",
                (COURSE_ID, nid),
            )

    # ── 5. Course excerpt / description ─────────────────────────────────────
    plan.append(f"UPDATE sbn_courses SET excerpt/description for course {COURSE_ID}")
    if not dry_run:
        cur.execute(
            "UPDATE sbn_courses SET excerpt = ?, description = ?, updated_at = ? WHERE id = ?",
            (NEW_COURSE_EXCERPT, NEW_COURSE_DESCRIPTION, NOW, COURSE_ID),
        )

    if dry_run:
        print("DRY RUN — no changes written. Plan:")
        for line in plan:
            print(" -", line)
        con.close()
        return

    con.commit()

    # ── Verify ───────────────────────────────────────────────────────────────
    integrity = cur.execute("PRAGMA quick_check").fetchone()[0]
    lesson_count = cur.execute("SELECT COUNT(*) FROM sbn_lessons WHERE course_id = ?", (COURSE_ID,)).fetchone()[0]
    node_count = cur.execute("SELECT COUNT(*) FROM sbn_skill_nodes").fetchone()[0]
    pivot_count = cur.execute("SELECT COUNT(*) FROM sbn_course_skill_node WHERE course_id = ?", (COURSE_ID,)).fetchone()[0]
    print(f"Done. quick_check={integrity}, course {COURSE_ID} lessons={lesson_count}, "
          f"total skill nodes={node_count}, course {COURSE_ID} node mappings={pivot_count}")
    con.close()


if __name__ == "__main__":
    main()
