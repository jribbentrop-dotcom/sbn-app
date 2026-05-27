/**
 * Shared types for the edu Fretboard primitive.
 *
 * String index convention: 0 = low E, 5 = high e (matches CAGED conventions
 * already in CagedWidget). Render order is low-E-at-bottom inside Fretboard.vue.
 */

export interface Position {
  string: number;          // 0..5, 0 = low E
  fret: number | null;     // null = muted (renders as × at the nut)
  role?: NoteRole;
  label?: string;          // optional dot label (degree, finger number, …)
}

export type NoteRole = 'root' | 'chord-tone' | 'scale-tone' | 'blue-note';

export interface Shape {
  id: string;
  windowStart: number;     // fret the camera pans to
  notes: Position[];
  roots: number[];         // string indices that hold the root for this shape
  barre?: number;          // fret to draw the barre rectangle at
  explanation: string;
}
