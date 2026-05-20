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
];

/** All unique tags across the catalog, sorted alphabetically. */
export const allWidgetTags: string[] = [
  ...new Set(widgetCatalog.flatMap(w => w.tags)),
].sort();
