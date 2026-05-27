/**
 * useChordUrl — canonical URL builder for chord detail pages.
 *
 * Rules (mirrors ChordLibraryController::show() expectations):
 *   - Rootless voicings always get ?root=C (viewer needs a root to display)
 *   - Transposed shapes (transposed_from != null) keep their transposed root
 *   - Any non-C root gets ?root=<encoded>
 *   - C-root archetypes / shapes stored at C need no param
 *
 * Bug fixed vs. BossaNovaChords.vue: encodeURIComponent() is now always applied
 * so roots like F#, C# don't produce broken URLs.
 */
export interface ChordUrlShape {
    slug: string;
    root_note?: string | null;
    voicing_category?: string | null;
    /** Set when the chord was transposed from its stored reference shape. */
    transposed_from?: unknown;
}

export function chordShowUrl(chord: ChordUrlShape): string {
    const base = `/library/chords/${chord.slug}`;
    const root = chord.root_note ?? '';
    const isRootless = chord.voicing_category === 'rootless';
    const isTransposed = chord.transposed_from != null;

    if (isRootless) return `${base}?root=C`;
    if (isTransposed || (root && root !== 'C')) return `${base}?root=${encodeURIComponent(root)}`;
    return base;
}
