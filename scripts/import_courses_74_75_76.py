import sqlite3, shutil
from datetime import datetime

DB_SRC = r'C:\Users\info\sbn-app\database\sbn.db'
DB_WORK = r'C:\Users\info\sbn-app\database\sbn_work.db'

shutil.copy2(DB_SRC, DB_WORK)
print(f"Copied DB to {DB_WORK}")

conn = sqlite3.connect(DB_WORK)
cur = conn.cursor()
now = datetime.now().strftime('%Y-%m-%d %H:%M:%S')

# ---------------------------------------------------------------------------
# Course 74 — Diatonic Chords & the Nashville Number System
# ---------------------------------------------------------------------------
cur.execute('''INSERT INTO sbn_courses
    (id, wp_id, slug, title, excerpt, description, genres, levels, style, level, topics,
     is_free, product_id, featured_image_path, sort_order, status, created_at, updated_at,
     category, learning_outcomes)
    VALUES (74, NULL, ?, ?, ?, ?, ?, ?, NULL, ?, ?, 1, NULL, NULL, 15, 'publish', ?, ?, ?, ?)
''', (
    'diatonic-chords-and-the-nashville-number-system',
    'Diatonic Chords & the Nashville Number System',
    'Stack a 7th chord on every step of the major scale and you get a self-contained harmonic toolkit — and a numbering system that works in any key.',
    'Every major scale generates the same seven chord qualities in the same order, no matter what key you’re in. This course builds that “diatonic ladder” in C, shows the ii-V-I cadence hiding inside it, and introduces the Nashville number system — naming chords by scale-degree number instead of letter name, so what you learn in one key transfers instantly to the next.',
    '["jazz","bossa-nova"]', '["intermediate"]', 'intermediate',
    '["diatonic harmony","nashville numbers","ii-v-i","chord functions","leadsheet reading"]',
    now, now, 'jazz',
    'Build the diatonic 7th chord ladder on any major scale\nRecognize the seven chord qualities that occur naturally in a major key\nRead and use Nashville numbers (1, 2m, 3m, 4, 5, 6m, 7m7b5) in place of letter names\nIdentify the ii-V-I cadence by ear and on paper\nRecognize a secondary ii-V leading to the vi chord',
))
print(f"Inserted course 74")

lessons_74 = [
    (120, 'the-diatonic-7th-chord-ladder', 'The Diatonic 7th Chord Ladder', 'Foundations', 1, 1, 'nashville-number-system', '''<h2 id="section-ladder-intro">One Scale, Seven Chords</h2>
<p>Take a major scale and build a four-note chord on every single step, using only notes from that scale. In C major, that gives you seven chords in a row: Cmaj7, Dm7, Em7, Fmaj7, G7, Am7, and Bm7b5. Play them in order and you've built the entire diatonic 7th-chord ladder for the key of C.</p>
<p>[NOTATION: diatonic 7th chord ladder in C major — Cmaj7, Dm7, Em7, Fmaj7, G7, Am7, Bm7b5, Cmaj7]</p>

<h2 id="section-ladder-pattern">The Pattern Never Changes</h2>
<p>Notice the order of chord qualities: major7, minor7, minor7, major7, dominant7, minor7, minor7b5. That exact sequence happens in every major key, every time — only the letter names change. That's the whole idea behind numbering chords by scale degree instead of by letter name.</p>

<h2 id="section-nashville-numbers">Introducing Nashville Numbers</h2>
<p>Instead of saying "Cmaj7, Dm7, Em7...", number each chord by its scale degree: 1, 2m, 3m, 4, 5, 6m, 7m7b5. Now the same numbers describe the identical pattern in any key — in G major it's Gmaj7(1), Am7(2m), Bm7(3m), Cmaj7(4), D7(5), Em7(6m), F#m7b5(7m7b5). Same shape, different letters.</p>

<sbn-info heading="Why this matters" items="Nashville numbers let you transpose a progression instantly without rewriting it|Chord qualities (m7, maj7, dom7, m7b5) are determined entirely by which scale step you're on|This is the same logic CAGED uses for chord shapes — one pattern, movable to any root"></sbn-info>'''),
    (121, 'chords-in-pairs', 'Chords in Pairs', 'Foundations', 0, 2, 'nashville-number-system', '''<h2 id="section-pairs-intro">Grouping the Ladder</h2>
<p>The seven diatonic chords are easier to internalize in pairs than all at once. Try grouping them as 1-2m, 3m-4, 5-6m, 7m7b5-1:</p>
<p>[NOTATION: C major diatonic chords grouped in pairs — Cmaj7/Dm7, Em7/Fmaj7, G7/Am7, Bm7b5/Cmaj7]</p>
<p>Each pair shares three out of four notes — moving between them is mostly a one- or two-note adjustment, not a totally new shape. That's worth feeling out slowly before trying to play the full ladder at tempo.</p>

<h2 id="section-pairs-numbers">Numbering the Pairs</h2>
<p>In Nashville numbers, those same pairs are 1-2m, 3m-4, 5-6m, 7m7b5-1 — and that labeling holds in every major key. Try saying the numbers out loud as you play through the pairs in C, then try the same pairs in G or F using only the numbers as your guide before checking the letter names.</p>

<sbn-info heading="Practice approach" items="Play each pair slowly, listening for which notes move and which stay put|Say the Nashville number out loud as you play each chord|Once C feels solid, try building the same numbered ladder starting on G or F"></sbn-info>'''),
    (122, 'the-ii-v-i-cadence', 'The ii-V-I Cadence', 'Cadences', 0, 3, 'nashville-number-system', '''<h2 id="section-ii-v-i-intro">The Most Important Three Chords in the Ladder</h2>
<p>Out of all seven diatonic chords, three of them — 2m, 5, and 1 — form the single most common cadence in jazz and pop harmony: the ii-V-I. In C major that's Dm7, G7, Cmaj7:</p>
<p>[NOTATION: ii-V-I cadence in C major — Dm7, G7, Cmaj7, Cmaj7]</p>

<h2 id="section-ii-v-i-why">Why It Works</h2>
<p>The ii chord sets up tension that the V chord (a dominant 7th, the most unstable-sounding chord in the ladder) intensifies, and the I chord finally resolves it. Once you can spot 2m-5-1 by its numbers, you'll find it constantly — inside songs, inside solos, and inside chord charts that otherwise look unfamiliar.</p>

<sbn-info heading="Practice approach" items="Loop the ii-V-I slowly and listen for the resolution at the I chord|Find a ii-V-I in a leadsheet you already know and label it with Nashville numbers|Try the same progression in G (Am7-D7-Gmaj7) and F (Gm7-C7-Fmaj7)"></sbn-info>'''),
    (123, 'secondary-ii-v-to-the-relative-minor', 'Borrowing the Relative Minor: a Secondary ii-V to vi', 'Cadences', 0, 4, 'nashville-number-system', '''<h2 id="section-secondary-intro">Targeting a Chord Other Than 1</h2>
<p>The ii-V-I cadence doesn't only resolve to the I chord — the same trick works to resolve to any diatonic chord, including the vi chord (the relative minor). This passage is in G major: it opens with a normal ii-V-I (Am7-D7-Gmaj7), then immediately sets up its own ii-V aimed at the vi chord (Em7) instead of resolving back to G:</p>
<p>[NOTATION: Am7-D7 | Gmaj7 | F#m7b5-B7 | Em7 — ii-V-I in G major followed by a secondary ii-V to vi]</p>

<h2 id="section-secondary-why">Borrowing From the Relative Minor</h2>
<p>F#m7b5 and B7 aren't part of G major's diatonic ladder in their usual role — they're borrowed because they're the ii and V of E minor, the relative minor of G. This is exactly the same ii-V-I logic from the last lesson, just aimed at a different target chord. The numbering system still works here: think of it as "2m-5 of 6m."</p>

<sbn-info heading="Practice approach" items="Play the full four-chord passage slowly, noticing where it resolves|Compare this to a plain ii-V-I — same shape, different target|Try building a secondary ii-V to the vi chord in C major (Cmaj7's vi is Am — its ii-V is Bm7b5-E7)"></sbn-info>'''),
]

for (lid, slug, title, section, preview, sort, concept, content) in lessons_74:
    cur.execute('''INSERT INTO sbn_lessons
        (id, wp_id, course_id, slug, title, content, section_title, is_preview, sort_order,
         status, created_at, updated_at, concept_slug)
        VALUES (?, NULL, 74, ?, ?, ?, ?, ?, ?, 'publish', ?, ?, ?)''',
        (lid, slug, title, content, section, preview, sort, now, now, concept))
    print(f"  Lesson {lid} '{title}'")

# Skill node mappings: nashville-number-system=35, leadsheet-reading=34
for sn_id in [35, 34]:
    cur.execute('INSERT OR IGNORE INTO sbn_course_skill_node (course_id, skill_node_id) VALUES (74, ?)', (sn_id,))
print(f"  Skill nodes mapped for course 74")

# ---------------------------------------------------------------------------
# Course 75 — Arpeggio Shapes: The Five Chord Qualities
# ---------------------------------------------------------------------------
cur.execute('''INSERT INTO sbn_courses
    (id, wp_id, slug, title, excerpt, description, genres, levels, style, level, topics,
     is_free, product_id, featured_image_path, sort_order, status, created_at, updated_at,
     category, learning_outcomes)
    VALUES (75, NULL, ?, ?, ?, ?, ?, ?, NULL, ?, ?, 1, NULL, NULL, 16, 'publish', ?, ?, ?, ?)
''', (
    'arpeggio-shapes-the-five-chord-qualities',
    'Arpeggio Shapes: The Five Chord Qualities',
    'Almost every 4-note chord you’ll meet is one of five qualities — and each one fits the same two-octave arpeggio shape with only a note or two moved.',
    'Major 7, minor 7, dominant 7, minor 7♭5, and diminished 7 — these five chord qualities cover the overwhelming majority of 4-note chords in jazz and bossa nova harmony. This course walks through four CAGED-style fretboard positions, and in each one shows how all five qualities sit inside the same physical arpeggio shape, changing only a note or two between them.',
    '["jazz","pop"]', '["intermediate"]', 'intermediate',
    '["arpeggios","chord qualities","caged system","four-note chords"]',
    now, now, 'jazz',
    'Identify the five core 4-note chord qualities: maj7, m7, dom7, m7b5, dim7\nPlay a 2-octave arpeggio for each quality in four CAGED-style positions\nRecognize how little changes physically between qualities in the same position\nConnect arpeggio shapes back to the CAGED chord/scale positions',
))
print(f"Inserted course 75")

lessons_75 = [
    (124, 'the-five-chord-qualities', 'The Five Chord Qualities', 'Foundations', 1, 1, 'arpeggio-shapes', '''<h2 id="section-five-qualities-intro">Five Shapes Cover Almost Everything</h2>
<p>Stack four notes a third apart and you'll land on one of five chord qualities almost every time: major 7, minor 7, dominant 7, minor 7♭5 (half-diminished), and diminished 7. Learn the arpeggio shape for one of these in a given fretboard position, and the other four are just one or two notes away.</p>

<h2 id="section-five-qualities-demo">Same Position, Five Sounds</h2>
<p>Here's all five qualities built on C, in the same hand position:</p>
<p>[NOTATION: Cmaj7 arpeggio (C-E-G-B), 2 octaves]</p>
<p>[NOTATION: Cm7 arpeggio (C-Eb-G-Bb), 2 octaves]</p>
<p>[NOTATION: C7 arpeggio (C-E-G-Bb), 2 octaves]</p>
<p>[NOTATION: Cm7b5 arpeggio (C-Eb-Gb-Bb), 2 octaves]</p>
<p>[NOTATION: Cdim7 arpeggio (C-Eb-Gb-Bbb), 2 octaves]</p>
<p>Notice how each quality differs from the one before it by just a single note: maj7 → m7 flattens the 3rd, m7 → dom7 raises the 7th back up, dom7 → m7b5 flattens the 3rd and 5th, m7b5 → dim7 flattens the 7th once more.</p>

<sbn-info heading="How to use this course" items="The next four lessons cover this same five-quality set in four different fretboard positions|These positions match the ones from The CAGED System course — review that course first if the shapes feel unfamiliar|Bbb in the diminished 7th chord sounds identical to A, just spelled to fit the chord's stacked-thirds logic"></sbn-info>'''),
    (125, 'a-shape-arpeggios', 'A-Shape Arpeggios', 'The Four Positions', 0, 2, 'arpeggio-shapes', '''<h2 id="section-a-shape-arp-intro">Five Qualities, Root on C</h2>
<p>This lesson stays in the A-shape position (root on C) and runs through all five qualities as full 2-octave arpeggios:</p>
<p>[NOTATION: Cmaj7 arpeggio, A-shape position, 2 octaves ascending and descending]</p>
<p>[NOTATION: Cm7 arpeggio, A-shape position, 2 octaves ascending and descending]</p>
<p>[NOTATION: C7 arpeggio, A-shape position, 2 octaves ascending and descending]</p>
<p>[NOTATION: Cm7b5 arpeggio, A-shape position, 2 octaves ascending and descending]</p>
<p>[NOTATION: Cdim7 arpeggio, A-shape position, 2 octaves ascending and descending]</p>

<sbn-info heading="Practice approach" items="Play each quality slowly before speeding up|Stop between qualities and notice exactly which finger moved|Compare this to the Cmaj7/Cmin7/C7 sounds from the A-shape lesson in The CAGED System course"></sbn-info>'''),
    (126, 'c-shape-arpeggios', 'C-Shape Arpeggios', 'The Four Positions', 0, 3, 'arpeggio-shapes', '''<h2 id="section-c-shape-arp-intro">Five Qualities, Root on F</h2>
<p>[NOTATION: Fmaj7 arpeggio, C-shape position, 2 octaves ascending and descending]</p>
<p>[NOTATION: Fm7 arpeggio, C-shape position, 2 octaves ascending and descending]</p>
<p>[NOTATION: F7 arpeggio, C-shape position, 2 octaves ascending and descending]</p>
<p>[NOTATION: Fm7b5 arpeggio, C-shape position, 2 octaves ascending and descending]</p>
<p>[NOTATION: Fdim7 arpeggio, C-shape position, 2 octaves ascending and descending]</p>

<sbn-info heading="Practice approach" items="The C-shape sits higher up the neck than the A-shape — give yourself time to find it cleanly|Loop just the maj7-to-m7 change until it's automatic before adding the rest|This position uses F as its root here, even though the CAGED System course's C-shape lesson used D — different source material, same shape"></sbn-info>'''),
    (127, 'e-shape-arpeggios', 'E-Shape Arpeggios', 'The Four Positions', 0, 4, 'arpeggio-shapes', '''<h2 id="section-e-shape-arp-intro">Five Qualities, Root on G</h2>
<p>[NOTATION: Gmaj7 arpeggio, E-shape position, 2 octaves ascending and descending]</p>
<p>[NOTATION: Gm7 arpeggio, E-shape position, 2 octaves ascending and descending]</p>
<p>[NOTATION: G7 arpeggio, E-shape position, 2 octaves ascending and descending]</p>
<p>[NOTATION: Gm7b5 arpeggio, E-shape position, 2 octaves ascending and descending]</p>
<p>[NOTATION: Gdim7 arpeggio, E-shape position, 2 octaves ascending and descending]</p>

<sbn-info heading="Practice approach" items="The E-shape's root on the low string makes this one of the easiest positions to locate by ear|Try playing just the outer notes (root and octave) first to anchor the shape before filling in the rest|This G root matches the E-shape lesson from The CAGED System course — a good position to compare directly"></sbn-info>'''),
    (128, 'g-shape-arpeggios', 'G-Shape Arpeggios', 'The Four Positions', 0, 5, 'arpeggio-shapes', '''<h2 id="section-g-shape-arp-intro">Five Qualities, Root on A</h2>
<p>[NOTATION: Amaj7 arpeggio, G-shape position, 2 octaves ascending and descending]</p>
<p>[NOTATION: Am7 arpeggio, G-shape position, 2 octaves ascending and descending]</p>
<p>[NOTATION: A7 arpeggio, G-shape position, 2 octaves ascending and descending]</p>
<p>[NOTATION: Am7b5 arpeggio, G-shape position, 2 octaves ascending and descending]</p>
<p>[NOTATION: Adim7 arpeggio, G-shape position, 2 octaves ascending and descending]</p>

<sbn-info heading="Practice approach" items="This is the last of the four positions covered in this course|This A root matches the G-shape lesson from The CAGED System course|Once comfortable, try all five qualities back to back without stopping between them"></sbn-info>'''),
    (129, 'putting-the-arpeggio-shapes-to-work', 'Putting the Shapes to Work', 'Putting It Together', 0, 6, 'arpeggio-shapes', '''<h2 id="section-arp-synthesis-intro">Same Root, Four Positions</h2>
<p>To hear how completely these four shapes overlap, here's a major 7 arpeggio on a single root — C — played across all four positions covered in this course, illustrating how the same chord tones reappear in different places up the neck:</p>
<p>[NOTATION: Cmaj7 arpeggio illustrated across A-shape, G-shape, E-shape, and C-shape positions, same root C throughout — illustrative synthesis combining shapes from this course, not a single source excerpt]</p>

<h2 id="section-arp-application">Using Arpeggios in Real Progressions</h2>
<p>The real payoff of this course is being able to switch arpeggio quality the instant the chord changes. Take a ii-V-I progression and play the matching-quality arpeggio under each chord — minor 7 under the ii, dominant 7 under the V, major 7 under the I — all without leaving the same fretboard position where possible.</p>

<sbn-info heading="Where to go from here" items="Revisit The CAGED System course if any of these four positions still feel unfamiliar as chord or scale shapes|Try outlining a ii-V-I using only arpeggio quality changes, no scale notes|Pick one root note and find all five qualities in all four positions from memory"></sbn-info>'''),
]

for (lid, slug, title, section, preview, sort, concept, content) in lessons_75:
    cur.execute('''INSERT INTO sbn_lessons
        (id, wp_id, course_id, slug, title, content, section_title, is_preview, sort_order,
         status, created_at, updated_at, concept_slug)
        VALUES (?, NULL, 75, ?, ?, ?, ?, ?, ?, 'publish', ?, ?, ?)''',
        (lid, slug, title, content, section, preview, sort, now, now, concept))
    print(f"  Lesson {lid} '{title}'")

# Skill node: arpeggio-shapes=17
cur.execute('INSERT OR IGNORE INTO sbn_course_skill_node (course_id, skill_node_id) VALUES (75, 17)')
print(f"  Skill nodes mapped for course 75")

# ---------------------------------------------------------------------------
# Course 76 — Approach Notes & Enclosures
# ---------------------------------------------------------------------------
cur.execute('''INSERT INTO sbn_courses
    (id, wp_id, slug, title, excerpt, description, genres, levels, style, level, topics,
     is_free, product_id, featured_image_path, sort_order, status, created_at, updated_at,
     category, learning_outcomes)
    VALUES (76, NULL, ?, ?, ?, ?, ?, ?, NULL, ?, ?, 1, NULL, NULL, 17, 'publish', ?, ?, ?, ?)
''', (
    'approach-notes-and-enclosures',
    'Approach Notes & Enclosures',
    'One small melodic trick — approaching a chord tone from a neighboring note instead of landing on it directly — is behind a huge amount of what makes a melodic line sound “finished.”',
    'This course builds the approach-note technique from the ground up: starting with a single neighbor note leading into a chord tone, then combining approaches from both sides into full “enclosures,” then applying the technique across longer chord progressions. Examples are drawn from both classical and jazz repertoire, showing the same device at work across very different styles.',
    '["jazz","classical"]', '["intermediate"]', 'intermediate',
    '["approach notes","enclosures","melodic embellishment","improvisation","bebop vocabulary"]',
    now, now, 'jazz',
    'Approach a chord tone (root, 3rd, or 5th) from a single neighboring note\nRecognize approach-note technique in familiar classical and jazz melodies\nBuild a diatonic-above/chromatic-below enclosure around any chord tone\nCombine diatonic and chromatic approaches from both sides for a fuller enclosure\nApply approach-note technique across a full chord progression, not just a single chord',
))
print(f"Inserted course 76")

lessons_76 = [
    (130, 'what-is-an-approach-note', 'What Is an Approach Note?', 'Foundations', 1, 1, 'motivic-development', '''<h2 id="section-approach-intro">Don't Land Directly — Approach</h2>
<p>Instead of jumping straight to a chord tone, play a single note right before it that leads into it — usually a step above or below. That's an approach note, and it's one of the simplest, most useful melodic devices in any style of music.</p>

<h2 id="section-approach-targets">Three Targets</h2>
<p>You can approach any chord tone, but three come up constantly: the root, the 3rd, and the 5th.</p>
<p>[NOTATION: single approach note leading into the root of a chord]</p>
<p>[NOTATION: single approach note leading into the 3rd of a chord]</p>
<p>[NOTATION: single approach note leading into the 5th of a chord]</p>

<h2 id="section-approach-combinations">Combining Targets</h2>
<p>Once each one feels natural on its own, try linking approaches to different chord tones back to back within the same phrase:</p>
<p>[NOTATION: combined approach-note figures targeting root, 3rd, and 5th in sequence]</p>

<sbn-info heading="Practice approach" items="Play the target chord tone alone first, then add the approach note right before it|Try both a half-step and whole-step approach into the same target and compare|This is the foundation for everything else in this course — don't rush past it"></sbn-info>'''),
    (131, 'approach-notes-in-familiar-melodies', 'Approach Notes in Familiar Melodies', 'Foundations', 0, 2, 'motivic-development', '''<h2 id="section-familiar-intro">You've Already Heard This</h2>
<p>Approach notes aren't a jazz-only trick — they show up everywhere, including melodies you already know well.</p>

<h2 id="section-familiar-fifth">Approaching the 5th</h2>
<p>Beethoven's "Für Elise" and the standard "I Found a New Baby" both lean on an approach note into the 5th of the chord at key moments:</p>
<p>[NOTATION: opening phrase of Für Elise, highlighting the approach note into the 5th]</p>
<p>[NOTATION: phrase from "I Found a New Baby", highlighting the approach note into the 5th]</p>

<h2 id="section-familiar-third">Approaching the 3rd</h2>
<p>"Mood Indigo" uses the same idea targeting the 3rd:</p>
<p>[NOTATION: phrase from "Mood Indigo", highlighting the approach note into the 3rd]</p>

<h2 id="section-familiar-root">Approaching the Root</h2>
<p>And the theme from "The Pink Panther" targets the root the same way:</p>
<p>[NOTATION: phrase from "The Pink Panther", highlighting the approach note into the root]</p>

<sbn-info heading="Practice approach" items="Listen for the approach note first, then find it on the page|Once you can spot it in a melody you know, start listening for it in melodies you don't|Try replacing the approach note with a direct jump to the target and compare how much flatter it sounds"></sbn-info>'''),
    (132, 'embellishments-in-longer-forms', 'Embellishments in Longer Forms', 'Application', 0, 3, 'motivic-development', '''<h2 id="section-longer-forms-intro">Beyond a Single Chord</h2>
<p>Approach notes get more interesting once a progression is moving — each new chord gives you a fresh target to approach. This shows up across a wide range of material: jazz blues heads, a contemporary jazz solo excerpt, and even classical and pop repertoire.</p>
<p>[NOTATION: excerpt from "Au Privave", highlighting approach-note figures across the changes]</p>
<p>[NOTATION: excerpt from "Billie's Bounce", highlighting approach-note figures across the changes]</p>
<p>[NOTATION: solo pickup segment in the style of Kurt Rosenwinkel, highlighting approach-note figures]</p>
<p>[NOTATION: excerpt from Chopin's Valse in A minor, highlighting an approach-note figure]</p>
<p>[NOTATION: excerpt from "Stars Fell on Alabama", highlighting approach-note figures]</p>

<sbn-info heading="Practice approach" items="Pick one of these examples and isolate just the approach notes, ignoring everything else|Notice that the technique doesn't change between genres — only the harmonic context does|Blues heads like these are a natural next step once this course's techniques feel solid"></sbn-info>'''),
    (133, 'the-enclosure-diatonic-above-chromatic-below', 'The Enclosure: Diatonic Above, Chromatic Below', 'Application', 0, 4, 'motivic-development', '''<h2 id="section-enclosure-intro">Approaching From Both Sides</h2>
<p>An enclosure surrounds a target note with two approach notes instead of one — typically a diatonic (in-key) note from above and a chromatic (half-step) note from below. This is one of the most recognizable building blocks of bebop melodic language.</p>
<p>[NOTATION: diatonic-above/chromatic-below enclosure around the root]</p>
<p>[NOTATION: diatonic-above/chromatic-below enclosure around the 3rd]</p>
<p>[NOTATION: diatonic-above/chromatic-below enclosure around the 5th]</p>

<h2 id="section-enclosure-examples">Where It Shows Up</h2>
<p>[NOTATION: excerpt from Rondo Alla Turca, highlighting an enclosure figure]</p>
<p>[NOTATION: excerpt from "You and the Night and the Music", highlighting an enclosure figure]</p>
<p>[NOTATION: excerpt from "Solar", highlighting an enclosure figure]</p>

<sbn-info heading="Practice approach" items="Play the enclosure slowly as three even notes before trying it at tempo|The chromatic note from below is the one that gives this its bebop flavor — don't skip it|Try this enclosure pattern on a target note you chose yourself, not just the examples here"></sbn-info>'''),
    (134, 'multi-step-enclosures', 'Multi-Step Enclosures', 'Application', 0, 5, 'motivic-development', '''<h2 id="section-multistep-intro">A Fuller Surround</h2>
<p>Once the basic enclosure feels comfortable, try combining diatonic and chromatic approaches from both sides for a more elaborate figure around the same target.</p>
<p>[NOTATION: "Yesterdays" excerpt, diatonic multi-step enclosure]</p>
<p>[NOTATION: "You're My Everything" excerpt, diatonic multi-step enclosure]</p>

<h2 id="section-multistep-types">Four Combinations</h2>
<p>[NOTATION: major 3rd target — diatonic approach above, chromatic approach below]</p>
<p>[NOTATION: minor 3rd target — chromatic approach above, chromatic approach below]</p>
<p>[NOTATION: 5th target — diatonic approach above, chromatic approach below]</p>
<p>[NOTATION: root target — chromatic approach above, chromatic approach below]</p>

<sbn-info heading="Practice approach" items="Work through these one combination at a time — there's no need to learn all four at once|Notice how the minor 3rd and root both use chromatic notes from both sides, while the major 3rd and 5th mix diatonic and chromatic|These four patterns are worth memorizing as a set, since they cover the most common targets you'll meet"></sbn-info>'''),
    (135, 'approach-note-practice-drills', 'Practice Drills', 'Putting It Together', 0, 6, 'motivic-development', '''<h2 id="section-drills-intro">Building the Habit</h2>
<p>The techniques in this course reward repetition more than analysis. This lesson is a structured practice routine rather than a set of new ideas — work through it slowly, in order.</p>

<h2 id="section-drills-from-below">From Below</h2>
<p>[NOTATION: approach-note drill pattern "from below," applied across a moving set of target notes]</p>

<h2 id="section-drills-chromatic">Chromatic</h2>
<p>[NOTATION: chromatic approach drill pattern, applied across a moving set of target notes]</p>

<h2 id="section-drills-diatonic">Diatonic</h2>
<p>[NOTATION: diatonic approach drill pattern, "from below," applied across a moving set of target notes]</p>

<h2 id="section-drills-chromatic-2">Chromatic, Revisited</h2>
<p>[NOTATION: second chromatic approach drill pattern, applied across a moving set of target notes]</p>

<sbn-info heading="How to practice this" items="Loop each drill slowly with a metronome before increasing tempo|Move on to the next drill only once the current one feels automatic|Revisit lesson 4 and 5 if any individual drill pattern feels unfamiliar"></sbn-info>'''),
]

for (lid, slug, title, section, preview, sort, concept, content) in lessons_76:
    cur.execute('''INSERT INTO sbn_lessons
        (id, wp_id, course_id, slug, title, content, section_title, is_preview, sort_order,
         status, created_at, updated_at, concept_slug)
        VALUES (?, NULL, 76, ?, ?, ?, ?, ?, ?, 'publish', ?, ?, ?)''',
        (lid, slug, title, content, section, preview, sort, now, now, concept))
    print(f"  Lesson {lid} '{title}'")

# Skill nodes: motivic-development=18, improvisation-over-changes=19
for sn_id in [18, 19]:
    cur.execute('INSERT OR IGNORE INTO sbn_course_skill_node (course_id, skill_node_id) VALUES (76, ?)', (sn_id,))
print(f"  Skill nodes mapped for course 76")

conn.commit()

# ---------------------------------------------------------------------------
# Verification
# ---------------------------------------------------------------------------
print("\n--- Verification ---")
cur.execute('PRAGMA integrity_check')
print('integrity_check:', cur.fetchone())

for cid in [74, 75, 76]:
    cur.execute('SELECT id, slug, title, status FROM sbn_courses WHERE id=?', (cid,))
    print('course:', cur.fetchone())
    cur.execute('SELECT id, slug, is_preview, sort_order FROM sbn_lessons WHERE course_id=? ORDER BY sort_order', (cid,))
    for row in cur.fetchall():
        print('  lesson:', row)
    cur.execute('SELECT skill_node_id FROM sbn_course_skill_node WHERE course_id=?', (cid,))
    print('  skill nodes:', cur.fetchall())

cur.execute('SELECT COUNT(*), MAX(id) FROM sbn_courses')
print('sbn_courses total:', cur.fetchone())
cur.execute('SELECT COUNT(*), MAX(id) FROM sbn_lessons')
print('sbn_lessons total:', cur.fetchone())

conn.close()
print("\nDone. Copy back to sbn.db.")
