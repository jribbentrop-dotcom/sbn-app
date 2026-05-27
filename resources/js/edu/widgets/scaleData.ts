/**
 * Scale pattern library for ScalePositionsWidget.
 *
 * Patterns are written in absolute frets for one reference key
 * (minor pentatonic = A minor, root on low E at fret 5). Transposing to
 * other roots is a simple fret offset done at render time.
 *
 * String index 0 = low E, 5 = high e. Fret = absolute fret number on the neck.
 */

export interface ScalePatternNote {
  string: number;
  fret: number;
  isRoot: boolean;
}

export interface ScalePattern {
  id: string;            // "Pos 1", "Pos 2", …
  /** Lowest fret the camera should pan to. */
  windowStart: number;
  /** Width of the visible window in frets. Defaults to 4 at the consumer. */
  windowFrets?: number;
  notes: ScalePatternNote[];
  explanation: string;
}

export interface ScaleDef {
  slug: string;
  name: string;
  /** Reference root PC the patterns are written for. A = 9. */
  referenceRootPc: number;
  patterns: ScalePattern[];
}

// A minor pentatonic: A C D E G (PCs 9, 0, 2, 4, 7)
// Standard 5 boxes, root on low E at fret 5.
const A_MIN_PENT: ScalePattern[] = [
  // Position 1: 5th-fret box (root on low E fret 5)
  {
    id: 'Pos 1',
    windowStart: 4,
    notes: [
      { string: 0, fret: 5, isRoot: true },  // A
      { string: 0, fret: 8, isRoot: false }, // C
      { string: 1, fret: 5, isRoot: false }, // D
      { string: 1, fret: 7, isRoot: false }, // E
      { string: 2, fret: 5, isRoot: false }, // G
      { string: 2, fret: 7, isRoot: true },  // A
      { string: 3, fret: 5, isRoot: false }, // C
      { string: 3, fret: 7, isRoot: false }, // D
      { string: 4, fret: 5, isRoot: false }, // E
      { string: 4, fret: 8, isRoot: false }, // G
      { string: 5, fret: 5, isRoot: true },  // A
      { string: 5, fret: 8, isRoot: false }, // C
    ],
    explanation: 'Position 1 — root on low E and high e. The home box.',
  },
  // Position 2: 7th-fret box
  {
    id: 'Pos 2',
    windowStart: 6,
    notes: [
      { string: 0, fret: 8, isRoot: false },  // C
      { string: 0, fret: 10, isRoot: false }, // D
      { string: 1, fret: 7, isRoot: false },  // E
      { string: 1, fret: 10, isRoot: false }, // G
      { string: 2, fret: 7, isRoot: true },   // A
      { string: 2, fret: 9, isRoot: false },  // C
      { string: 3, fret: 7, isRoot: false },  // D
      { string: 3, fret: 9, isRoot: false },  // E
      { string: 4, fret: 8, isRoot: false },  // G
      { string: 4, fret: 10, isRoot: true },  // A
      { string: 5, fret: 8, isRoot: false },  // C
      { string: 5, fret: 10, isRoot: false }, // D
    ],
    explanation: 'Position 2 — root on the D string. Sits just above the home box.',
  },
  // Position 3: 10th-fret box (5 frets wide — spans 9-13 with a gap at 11)
  {
    id: 'Pos 3',
    windowStart: 8,
    windowFrets: 5,
    notes: [
      { string: 0, fret: 10, isRoot: false }, // D
      { string: 0, fret: 12, isRoot: false }, // E
      { string: 1, fret: 10, isRoot: false }, // G
      { string: 1, fret: 12, isRoot: true },  // A
      { string: 2, fret: 9, isRoot: false },  // C
      { string: 2, fret: 12, isRoot: false }, // D
      { string: 3, fret: 9, isRoot: false },  // E
      { string: 3, fret: 12, isRoot: false }, // G
      { string: 4, fret: 10, isRoot: true },  // A
      { string: 4, fret: 13, isRoot: false }, // C
      { string: 5, fret: 10, isRoot: false }, // D
      { string: 5, fret: 12, isRoot: false }, // E
    ],
    explanation: 'Position 3 — root on the B string. The middle of the neck.',
  },
  // Position 4: 12th-fret box
  {
    id: 'Pos 4',
    windowStart: 11,
    notes: [
      { string: 0, fret: 12, isRoot: false }, // E
      { string: 0, fret: 15, isRoot: false }, // G
      { string: 1, fret: 12, isRoot: true },  // A
      { string: 1, fret: 15, isRoot: false }, // C
      { string: 2, fret: 12, isRoot: false }, // D
      { string: 2, fret: 14, isRoot: false }, // E
      { string: 3, fret: 12, isRoot: false }, // G
      { string: 3, fret: 14, isRoot: true },  // A
      { string: 4, fret: 13, isRoot: false }, // C
      { string: 4, fret: 15, isRoot: false }, // D
      { string: 5, fret: 12, isRoot: false }, // E
      { string: 5, fret: 15, isRoot: false }, // G
    ],
    explanation: 'Position 4 — root on the A string. Octave up from Position 1.',
  },
  // Position 5: 14th/15th-fret box — bridge back to Position 1 an octave up
  {
    id: 'Pos 5',
    windowStart: 13,
    notes: [
      { string: 0, fret: 15, isRoot: false }, // G
      { string: 0, fret: 17, isRoot: true },  // A
      { string: 1, fret: 15, isRoot: false }, // C
      { string: 1, fret: 17, isRoot: false }, // D
      { string: 2, fret: 14, isRoot: false }, // E
      { string: 2, fret: 17, isRoot: false }, // G
      { string: 3, fret: 14, isRoot: true },  // A
      { string: 3, fret: 17, isRoot: false }, // C
      { string: 4, fret: 15, isRoot: false }, // D
      { string: 4, fret: 17, isRoot: false }, // E
      { string: 5, fret: 15, isRoot: false }, // G
      { string: 5, fret: 17, isRoot: true },  // A
    ],
    explanation: 'Position 5 — root on the D string. Bridges back to Position 1 an octave higher.',
  },
];

export const SCALES: Record<string, ScaleDef> = {
  'minor-pentatonic': {
    slug: 'minor-pentatonic',
    name: 'Minor Pentatonic',
    referenceRootPc: 9, // A
    patterns: A_MIN_PENT,
  },
};

/** Pitch class for a root note name. Accepts naturals + sharps/flats. */
export function rootPc(root: string): number {
  const map: Record<string, number> = {
    'C': 0, 'C#': 1, 'Db': 1, 'D': 2, 'D#': 3, 'Eb': 3, 'E': 4,
    'F': 5, 'F#': 6, 'Gb': 6, 'G': 7, 'G#': 8, 'Ab': 8, 'A': 9,
    'A#': 10, 'Bb': 10, 'B': 11,
  };
  return map[root] ?? 9;
}
