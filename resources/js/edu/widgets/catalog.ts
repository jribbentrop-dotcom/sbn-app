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
    slug: 'drop2-visualizer',
    title: 'Drop Voicings',
    summary: 'Drop the 2nd or 3rd voice down an octave — the move behind open, playable jazz chords.',
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
    slug: 'note-duration',
    title: 'Note Duration',
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
];

/** All unique tags across the catalog, sorted alphabetically. */
export const allWidgetTags: string[] = [
  ...new Set(widgetCatalog.flatMap(w => w.tags)),
].sort();
