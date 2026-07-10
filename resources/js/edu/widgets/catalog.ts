/**
 * Widget catalog — metadata for each registered edu widget.
 * Drives the /theory page: title, summary, tags, and optional default props.
 *
 * Tags are free-form strings; the filter sidebar is built from whatever appears here.
 * `defaultProps` are passed to the widget when rendered standalone on the theory page.
 */

export interface WidgetEntry {
  slug: string;
  title: string;
  summary: string;
  tags: string[];
  defaultProps?: Record<string, unknown>;
}

export const widgetCatalog: WidgetEntry[] = [
  {
    slug: 'circle-of-fifths',
    title: 'Circle of Fifths',
    summary: 'The 12 keys arranged by perfect fifths — the map behind every key signature and tonal relationship.',
    tags: ['harmony', 'keys', 'general'],
  },
  {
    slug: 'triad-builder',
    title: 'Triad Builder',
    summary: 'See how major, minor, augmented, and diminished triads are built from stacked thirds.',
    tags: ['harmony', 'chords'],
  },
  {
    slug: 'voicing-structures',
    title: 'Voicing Structures',
    summary: 'Closed, shell, drop 2, and drop 3 — how the same four notes rearrange into open, playable jazz voicings.',
    tags: ['harmony', 'voicings', 'chords'],
  },
  {
    slug: 'voice-leading',
    title: 'Voice Leading',
    summary: 'See how each voice moves between chords. Flat lines = smooth motion, steep lines = big leaps.',
    tags: ['harmony', 'voicings', 'general'],
  },
  {
    slug: 'caged-system',
    title: 'CAGED System',
    summary: 'Five chord shapes that tile the neck — see how C major appears in every position from the nut to the 12th fret.',
    tags: ['guitar', 'chords', 'fretboard'],
  },
  {
    slug: 'chord-function',
    title: 'Chord Function',
    summary: 'Every diatonic chord has a job: Tonic, Subdominant, or Dominant. Explore all seven degrees in major and minor.',
    tags: ['harmony', 'chords', 'general'],
  },
  {
    slug: 'chord-tones',
    title: 'Chord Tones',
    summary: 'Stack thirds upward — from triads through 13ths — and add altered option tones to hear how jazz harmony is built.',
    tags: ['harmony', 'chords', 'general'],
  },
  {
    slug: 'dots-and-ties',
    title: 'Dots & Ties',
    summary: 'How dots and ties extend note lengths — the arithmetic behind dotted rhythms and syncopation.',
    tags: ['rhythm', 'general'],
  },
  {
    slug: 'interval-explorer',
    title: 'Interval Explorer',
    summary: 'Drag the upper dot up and down a pitch axis to hear and name every interval from a minor second to an octave.',
    tags: ['harmony', 'ear-training', 'general'],
  },
  {
    slug: 'pentatonic-scales',
    title: 'Pentatonic Scales',
    summary: 'Toggle between the 7-note diatonic scale and its 5-note pentatonic subset — see exactly which notes drop out and why.',
    tags: ['scales', 'melody', 'general'],
  },
  {
    slug: 'chord-quality-tree',
    title: 'Chord Quality Tree',
    summary: 'Two triads branch into five 7th-chord qualities — toggle between the parent triad and the full chord to see exactly which tone changes.',
    tags: ['harmony', 'chords', 'general'],
  },
  {
    slug: 'chord-extensions',
    title: 'Chord Extensions',
    summary: 'Stack thirds beyond the 7th — from 9ths through 13ths — across all five 7th-chord qualities, with option tones on dominant.',
    tags: ['harmony', 'chords', 'general'],
  },
  {
    slug: 'chord-quality-brightness',
    title: 'Chord Quality & Brightness',
    summary: 'Step through maj7 → dom7 → m7 → m7♭5 → dim7 and watch the colour tones descend — a visual map of harmonic brightness.',
    tags: ['harmony', 'chords', 'general'],
  },
  {
    slug: 'scale-positions',
    title: 'Scale Positions',
    summary: 'The five pentatonic boxes laid across the neck — switch between positions to see how the same scale tiles from the nut to the 12th fret.',
    tags: ['guitar', 'scales', 'fretboard'],
    defaultProps: { scale: 'minor-pentatonic', root: 'F', startPosition: 1 },
  },
  {
    slug: 'time-signature',
    title: 'Time Signatures',
    summary: 'Step through 2/4, 3/4, 4/4, 6/8, 5/4, and 7/8 — see the beat pattern and learn when each metre appears in Jazz and Bossa Nova.',
    tags: ['rhythm', 'general'],
  },
  {
    slug: 'repeat-signs',
    title: 'Repeat Signs',
    summary: 'Repeat barlines, volta brackets, D.S. al Coda, and D.C. al Fine — every navigation symbol used in lead sheets and charts.',
    tags: ['notation', 'general'],
  },
  {
    slug: 'note-values',
    title: 'Note Values',
    summary: 'From whole notes to sixteenths — see the note shape, beat value, and how many fit in a bar of 4/4.',
    tags: ['rhythm', 'notation', 'general'],
  },
  {
    slug: 'triplets',
    title: 'Triplets',
    summary: 'Quarter-note and eighth-note triplets side by side — toggle the comparison to see how three fit where two normally go.',
    tags: ['rhythm', 'general'],
  },
  {
    slug: 'tab-diagram',
    title: 'Tab & Chord Diagrams',
    summary: 'Three views of the same chord — fretboard, tab, and chord diagram — animating through the rotation that links them.',
    tags: ['guitar', 'notation', 'general'],
  },
  {
    slug: 'basic-chords',
    title: 'The Basic Eight',
    summary: 'The eight essential open chords — E, Em, A, Am, D, Dm, C, G — with finger numbers on a fretboard diagram.',
    tags: ['guitar', 'chords', 'general'],
  },
  {
    slug: 'scale-steps',
    title: 'Whole & Half Steps',
    summary: 'The building blocks of every scale — see how the pattern of whole and half steps differs between major and natural minor.',
    tags: ['scales', 'harmony', 'general'],
  },
];

/** All unique tags across the catalog, sorted alphabetically. */
export const allWidgetTags: string[] = [
  ...new Set(widgetCatalog.flatMap(w => w.tags)),
].sort();
