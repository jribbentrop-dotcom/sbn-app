<?php
// Source of truth for chord-quality educational blurbs displayed in the
// leadsheet viewer EduPanel. Keys match the canonical quality slugs produced
// by ChordVoicingSearch::parseChordName(). When the edu_topics DB table lands,
// EduContentService swaps to an Eloquent lookup; this file becomes the seeder.

return [
    'maj' => [
        'title' => 'Major',
        'blurb' => 'A major triad (root, major third, perfect fifth). Creates a bright, happy sound that forms the foundation of most Western music.',
    ],
    'min' => [
        'title' => 'Minor',
        'blurb' => 'A minor triad (root, minor third, perfect fifth). Creates a sad or contemplative mood, fundamental to most musical styles.',
    ],
    'maj7' => [
        'title' => 'Major 7',
        'blurb' => 'A major triad with an added major 7th interval. Creates a rich, jazzy sound often used in bossa nova and jazz standards.',
    ],
    'm7' => [
        'title' => 'Minor 7',
        'blurb' => 'A minor triad with an added minor 7th interval. Common in jazz, blues, and contemporary music for its sophisticated yet somber quality.',
    ],
    'dom7' => [
        'title' => 'Dominant 7',
        'blurb' => 'A major triad with a flat 7th interval. Creates tension that naturally resolves to the tonic, essential for blues and jazz progressions.',
    ],
    'm7b5' => [
        'title' => 'Half-diminished (m7♭5)',
        'blurb' => 'A diminished triad with a minor 7th. Often functions as a ii chord in minor keys or as a passing chord in jazz progressions.',
    ],
    'dim' => [
        'title' => 'Diminished',
        'blurb' => 'A diminished triad with a flat fifth. Creates intense tension, often used as a passing chord or leading tone chord.',
    ],
    'o7' => [
        'title' => 'Diminished 7',
        'blurb' => 'A diminished triad with a diminished 7th interval. Highly unstable sound that creates strong resolution to the tonic.',
    ],
    'aug' => [
        'title' => 'Augmented',
        'blurb' => 'An augmented triad with a raised fifth. Creates an unsettled, dreamy quality that wants to resolve upward.',
    ],
    'aug7' => [
        'title' => 'Augmented 7',
        'blurb' => 'An augmented triad with a flat 7th. Rare but distinctive, combining the tension of both augmented and dominant qualities.',
    ],
    'mMaj7' => [
        'title' => 'Minor-Major 7',
        'blurb' => 'A minor triad with a major 7th. Distinctive cinematic sound used in film scores and jazz for its mysterious quality.',
    ],
    'sus4' => [
        'title' => 'Suspended 4',
        'blurb' => 'A chord with a suspended 4th replacing the third. Creates open, unresolved tension that typically resolves to a major chord.',
    ],
    'sus2' => [
        'title' => 'Suspended 2',
        'blurb' => 'A chord with a suspended 2nd replacing the third. Creates a bright, open sound popular in contemporary and pop music.',
    ],
    'maj6' => [
        'title' => 'Major 6',
        'blurb' => 'A major triad with an added 6th. Warm, vintage sound common in jazz standards and traditional pop music.',
    ],
    'm6' => [
        'title' => 'Minor 6',
        'blurb' => 'A minor triad with an added 6th. Distinctive jazz sound that creates a sophisticated, slightly mysterious quality.',
    ],
    'add9' => [
        'title' => 'Add 9',
        'blurb' => 'A major triad with an added 9th. Adds color and richness without the complexity of a full extended chord.',
    ],
    '7sus4' => [
        'title' => '7 sus 4',
        'blurb' => 'A suspended 4th chord with a dominant 7th. Creates a floating, unresolved quality popular in funk and fusion.',
    ],
    '5' => [
        'title' => 'Power chord',
        'blurb' => 'Just root and fifth, no third. The foundation of rock and heavy music, neither major nor minor in quality.',
    ],
    // Extend as content is authored. Unknown qualities fall back to a generic
    // "no info yet" placeholder in the EduPanel.
];
