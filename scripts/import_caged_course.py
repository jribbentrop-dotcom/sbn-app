import sqlite3
from datetime import datetime

DB_SRC = r'C:\Users\info\sbn-app\database\sbn.db'
DB_WORK = r'C:\Users\info\sbn-app\database\sbn_work.db'

import shutil
shutil.copy2(DB_SRC, DB_WORK)
print(f"Copied DB to {DB_WORK}")

conn = sqlite3.connect(DB_WORK)
cur = conn.cursor()

now = datetime.now().strftime('%Y-%m-%d %H:%M:%S')

# --- Course row ---
course = {
    'id': 73,
    'slug': 'the-caged-system',
    'title': 'The CAGED System',
    'excerpt': 'One set of five chord shapes that maps the entire fretboard — learn to find major, minor, and dominant sounds anywhere on the neck.',
    'description': 'The CAGED system uses five open-position chord shapes (C, A, G, E, and D) as movable templates that tile the whole fretboard. This course breaks down four of the five shapes in depth — each one explored as a single hand position that can produce a major 7, minor 7, and dominant 7 sound around the same root, plus the scales that go with each.',
    'genres': '["bossa-nova","jazz","pop"]',
    'levels': '["basic"]',
    'style': None,
    'level': 'basic',
    'topics': '["caged system","chord shapes","scale patterns","arpeggios","fretboard positions"]',
    'is_free': 1,
    'product_id': None,
    'featured_image_path': None,
    'sort_order': 14,
    'status': 'publish',
    'created_at': now,
    'updated_at': now,
    'category': 'jazz',
    'learning_outcomes': 'Understand the five CAGED shapes and how they tile the fretboard\nLocate the major, minor, and dominant-7 sound within a single CAGED position\nConnect each chord shape to its matching scale fingering\nUse phrygian dominant and mixolydian scales as color choices over a dominant chord\nNavigate between adjacent CAGED positions on the neck',
    'wp_id': None,
}

cur.execute('''
    INSERT INTO sbn_courses
    (id, wp_id, slug, title, excerpt, description, genres, levels, style, level, topics,
     is_free, product_id, featured_image_path, sort_order, status, created_at, updated_at,
     category, learning_outcomes)
    VALUES
    (:id, :wp_id, :slug, :title, :excerpt, :description, :genres, :levels, :style, :level, :topics,
     :is_free, :product_id, :featured_image_path, :sort_order, :status, :created_at, :updated_at,
     :category, :learning_outcomes)
''', course)
print(f"Inserted course id={cur.lastrowid}")

# --- Lessons ---
lessons = [
    {
        'id': 114,
        'slug': 'what-is-the-caged-system',
        'title': 'What Is the CAGED System?',
        'section_title': 'Foundations',
        'is_preview': 1,
        'sort_order': 1,
        'concept_slug': 'caged-system',
        'content': '''<h2 id="section-overview">Five Shapes, One Fretboard</h2>
<p>The CAGED system gets its name from five open chords you probably already know: C, A, G, E, and D. Each of those open-position chords has a distinct shape — and if you slide that shape up the neck as a barre chord, it still works. Do this with all five shapes and you've mapped the entire fretboard, because the five shapes overlap and connect in a fixed, repeating order.</p>
<p>The same five shapes also describe five places to play a scale. Wherever a CAGED chord shape sits, there's a matching scale fingering built around it. That's the real payoff of learning CAGED: it links chords, scales, and arpeggios into one set of physical positions instead of five separate things to memorize.</p>

<h2 id="section-five-shapes">The Five Shapes</h2>
<p>Here is each shape played once, using a convenient root note for each — notice that the shape itself doesn't change no matter which root you put it on:</p>
<p>[NOTATION: C-shape, root D — chord tones D-F#-A-D-F# across strings 5,4,3,2,1]</p>
<p>[NOTATION: A-shape, root C — chord tones C-G-C-E-G across strings 5,4,3,2,1]</p>
<p>[NOTATION: G-shape, root A — chord tones E-A-C#-A across strings 4,3,2,1]</p>
<p>[NOTATION: E-shape, root G — chord tones G-D-G-B-D-G across strings 6,5,4,3,2,1]</p>
<p>[NOTATION: D-shape, root F — chord tones F-C-F-A across strings 4,3,2,1]</p>

<sbn-info heading="How to use these shapes" items="Each shape can move to any fret — the root note just changes|Shapes overlap: the top of one shape lines up with the bottom of the next|You don't need to learn all five at once — start with one and add others over time"></sbn-info>

<h2 id="section-whats-next">What's Next</h2>
<p>The next four lessons take a deep dive into the A-shape, C-shape, E-shape, and G-shape positions. In each one, you'll find a major 7, minor 7, and dominant 7 sound — plus the scale that goes with each — all within a single hand position. The D-shape gets a full deep-dive lesson in a future update.</p>''',
    },
    {
        'id': 115,
        'slug': 'the-a-shape-position',
        'title': 'The A-Shape Position',
        'section_title': 'The Four Positions',
        'is_preview': 0,
        'sort_order': 2,
        'concept_slug': 'caged-system',
        'content': '''<h2 id="section-a-shape-intro">Finding the A-Shape</h2>
<p>The A-shape gets its name from the open A chord. As a movable shape, its root notes sit on the 5th string and the 3rd string. This lesson keeps the root on C, so the shape sits with its lowest root around the 3rd fret.</p>

<h2 id="section-a-shape-major">Major 7 Sound</h2>
<p>Starting with the C major scale across this position, then the triad, then the full Cmaj7 arpeggio:</p>
<p>[NOTATION: C major scale (C-D-E-F-G-A-B-C) in A-shape position]</p>
<p>[NOTATION: C major triad arpeggio (C-E-G) in A-shape position]</p>
<p>[NOTATION: Cmaj7 arpeggio (C-E-G-B) in A-shape position]</p>

<h2 id="section-a-shape-minor">Minor 7 Sound</h2>
<p>Same position, same root — now built from C natural minor:</p>
<p>[NOTATION: C natural minor scale (C-D-Eb-F-G-Ab-Bb-C) in A-shape position]</p>
<p>[NOTATION: C minor triad arpeggio (C-Eb-G) in A-shape position]</p>
<p>[NOTATION: Cmin7 arpeggio (C-Eb-G-Bb) in A-shape position]</p>

<h2 id="section-a-shape-dominant">Dominant 7 Sound</h2>
<p>For a dominant 7 sound on C, two scale choices work over the same arpeggio. Mixolydian is the straightforward choice; phrygian dominant (the 5th mode of harmonic minor) adds a sharper, more tense color often used to lean into a resolution.</p>
<p>[NOTATION: C mixolydian scale (C-D-E-F-G-A-Bb-C) in A-shape position]</p>
<p>[NOTATION: C phrygian dominant scale (C-Db-E-F-G-Ab-Bb-C) in A-shape position]</p>
<p>[NOTATION: C7 arpeggio (C-E-G-Bb) in A-shape position]</p>

<sbn-info heading="Practice approach" items="Loop each scale slowly before adding the arpeggio|Compare the minor and major thirds (E vs Eb) by ear, not just by shape|Try resolving the dominant 7 sound to the major 7 sound in the same position"></sbn-info>''',
    },
    {
        'id': 116,
        'slug': 'the-c-shape-position',
        'title': 'The C-Shape Position',
        'section_title': 'The Four Positions',
        'is_preview': 0,
        'sort_order': 3,
        'concept_slug': 'caged-system',
        'content': '''<h2 id="section-c-shape-intro">Finding the C-Shape</h2>
<p>The C-shape comes from the open C chord. As a movable shape its root sits on the 5th string. This lesson keeps the root on D, putting the shape a little further up the neck than the A-shape position from the last lesson.</p>

<h2 id="section-c-shape-major">Major 7 Sound</h2>
<p>[NOTATION: D major scale (D-E-F#-G-A-B-C#-D) in C-shape position]</p>
<p>[NOTATION: D major triad arpeggio (D-F#-A) in C-shape position]</p>
<p>[NOTATION: Dmaj7 arpeggio (D-F#-A-C#) in C-shape position]</p>

<h2 id="section-c-shape-minor">Minor 7 Sound</h2>
<p>[NOTATION: D natural minor scale (D-E-F-G-A-Bb-C-D) in C-shape position]</p>
<p>[NOTATION: D minor triad arpeggio (D-F-A) in C-shape position]</p>
<p>[NOTATION: Dmin7 arpeggio (D-F-A-C) in C-shape position]</p>

<h2 id="section-c-shape-dominant">Dominant 7 Sound</h2>
<p>[NOTATION: D mixolydian scale (D-E-F#-G-A-B-C-D) in C-shape position]</p>
<p>[NOTATION: D phrygian dominant scale (D-Eb-F#-G-A-Bb-C-D) in C-shape position]</p>
<p>[NOTATION: D7 arpeggio (D-F#-A-C) in C-shape position]</p>

<sbn-info heading="Practice approach" items="Notice the C-shape sits one shape higher than the A-shape for a nearby root|Play the same Dmaj7/Dmin7/D7 sounds you just learned in the A-shape position on C — same logic, different shape|Use a metronome and keep the tempo slow until the shifts between sections are clean"></sbn-info>''',
    },
    {
        'id': 117,
        'slug': 'the-e-shape-position',
        'title': 'The E-Shape Position',
        'section_title': 'The Four Positions',
        'is_preview': 0,
        'sort_order': 4,
        'concept_slug': 'caged-system',
        'content': '''<h2 id="section-e-shape-intro">Finding the E-Shape</h2>
<p>The E-shape comes from the open E chord and is the shape most players learn first as a barre chord, since its root sits on the low 6th string. This lesson keeps the root on G.</p>

<h2 id="section-e-shape-major">Major 7 Sound</h2>
<p>[NOTATION: G major scale (G-A-B-C-D-E-F#-G) in E-shape position]</p>
<p>[NOTATION: G major triad arpeggio (G-B-D) in E-shape position]</p>
<p>[NOTATION: Gmaj7 arpeggio (G-B-D-F#) in E-shape position]</p>

<h2 id="section-e-shape-minor">Minor 7 Sound</h2>
<p>[NOTATION: G natural minor scale (G-A-Bb-C-D-Eb-F-G) in E-shape position]</p>
<p>[NOTATION: G minor triad arpeggio (G-Bb-D) in E-shape position]</p>
<p>[NOTATION: Gmin7 arpeggio (G-Bb-D-F) in E-shape position]</p>

<h2 id="section-e-shape-dominant">Dominant 7 Sound</h2>
<p>[NOTATION: G mixolydian scale (G-A-B-C-D-E-F-G) in E-shape position]</p>
<p>[NOTATION: G phrygian dominant scale (G-Ab-B-C-D-Eb-F-G) in E-shape position]</p>
<p>[NOTATION: G7 arpeggio (G-B-D-F) in E-shape position]</p>

<sbn-info heading="Practice approach" items="The E-shape root on the low string makes this position easy to find anywhere on the neck|Try barring the full E-shape chord before isolating the scale|Compare this G major7/minor7/7 set against the same sounds in the A-shape and C-shape positions"></sbn-info>''',
    },
    {
        'id': 118,
        'slug': 'the-g-shape-position',
        'title': 'The G-Shape Position',
        'section_title': 'The Four Positions',
        'is_preview': 0,
        'sort_order': 5,
        'concept_slug': 'caged-system',
        'content': '''<h2 id="section-g-shape-intro">Finding the G-Shape</h2>
<p>The G-shape comes from the open G chord. It's the widest stretch of the five CAGED shapes in open position, but as a movable shape it's no harder than the others. This lesson keeps the root on A.</p>

<h2 id="section-g-shape-major">Major 7 Sound</h2>
<p>[NOTATION: A major scale (A-B-C#-D-E-F#-G#-A) in G-shape position]</p>
<p>[NOTATION: A major triad arpeggio (A-C#-E) in G-shape position]</p>
<p>[NOTATION: Amaj7 arpeggio (A-C#-E-G#) in G-shape position]</p>

<h2 id="section-g-shape-minor">Minor 7 Sound</h2>
<p>[NOTATION: A natural minor scale (A-B-C-D-E-F-G-A) in G-shape position]</p>
<p>[NOTATION: A minor triad arpeggio (A-C-E) in G-shape position]</p>
<p>[NOTATION: Amin7 arpeggio (A-C-E-G) in G-shape position]</p>

<h2 id="section-g-shape-dominant">Dominant 7 Sound</h2>
<p>[NOTATION: A mixolydian scale (A-B-C#-D-E-F#-G-A) in G-shape position]</p>
<p>[NOTATION: A phrygian dominant scale (A-Bb-C#-D-E-F-G-A) in G-shape position]</p>
<p>[NOTATION: A7 arpeggio (A-C#-E-G) in G-shape position]</p>

<sbn-info heading="Practice approach" items="The G-shape's stretch gets easier with repetition — don't force it early on|This is the last of the four positions covered here; the D-shape deep dive is still to come|Try playing a ii-V-I in this position once you're comfortable with all three sounds"></sbn-info>''',
    },
    {
        'id': 119,
        'slug': 'connecting-the-shapes-up-the-neck',
        'title': 'Connecting the Shapes Up the Neck',
        'section_title': 'Putting It Together',
        'is_preview': 0,
        'sort_order': 6,
        'concept_slug': 'caged-system',
        'content': '''<h2 id="section-connecting-intro">The Shapes Don't Stand Alone</h2>
<p>The five CAGED shapes always appear in the same order as you move up the neck — C, A, G, E, D, then back to C an octave higher — and each shape's root overlaps with the edge of the next one. That overlap is what makes it possible to slide smoothly from one position to another instead of jumping blind.</p>

<h2 id="section-connecting-example">A Worked Example</h2>
<p>Take the four shapes from this course and line them up on the same root note. Played in order, they walk a single major-7 sound up the entire neck:</p>
<p>[NOTATION: Cmaj7 arpeggio played across A-shape, G-shape, E-shape, C-shape, and back to A-shape an octave up, same root C throughout]</p>

<h2 id="section-connecting-next">Where This Goes Next</h2>
<p>Two things build directly on what you've covered here, planned as future courses: turning each shape into a movable barre chord you can drop into any progression, and using the overlap between shapes to shift position mid-phrase without losing your place. The D-shape will also get its own full deep-dive lesson, matching the treatment given to the other four shapes in this course, once a complete source example for it is ready.</p>

<sbn-info heading="Where to go from here" items="Pick one root note and find it in all four shapes covered in this course|Practice moving from one shape to the next without stopping|Revisit lesson 1's overview once the four deep dives feel solid"></sbn-info>''',
    },
]

for lesson in lessons:
    cur.execute('''
        INSERT INTO sbn_lessons
        (id, wp_id, course_id, slug, title, content, section_title, is_preview, sort_order,
         status, created_at, updated_at, concept_slug)
        VALUES
        (:id, NULL, 73, :slug, :title, :content, :section_title, :is_preview, :sort_order,
         'publish', :created_at, :updated_at, :concept_slug)
    ''', {**lesson, 'created_at': now, 'updated_at': now})
    print(f"  Inserted lesson id={lesson['id']} '{lesson['title']}'")

# --- Skill node mappings ---
# caged-system=23, scale-patterns=15, arpeggio-shapes=17
skill_node_ids = [23, 15, 17]
for sn_id in skill_node_ids:
    cur.execute('''
        INSERT OR IGNORE INTO sbn_course_skill_node (course_id, skill_node_id)
        VALUES (73, ?)
    ''', (sn_id,))
    print(f"  Mapped course 73 -> skill_node {sn_id}")

conn.commit()

# --- Verification ---
print("\n--- Verification ---")
cur.execute('PRAGMA integrity_check')
print('integrity_check:', cur.fetchone())

cur.execute('SELECT id, slug, title, status FROM sbn_courses WHERE id=73')
print('course:', cur.fetchone())

cur.execute('SELECT id, slug, title, is_preview, sort_order FROM sbn_lessons WHERE course_id=73 ORDER BY sort_order')
for row in cur.fetchall():
    print('  lesson:', row)

cur.execute('SELECT course_id, skill_node_id FROM sbn_course_skill_node WHERE course_id=73')
print('skill_node mappings:', cur.fetchall())

conn.close()
print("\nDone. Now copy back to sbn.db.")
