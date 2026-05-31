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
    /** True when this result came from an alias of the parent shape. */
    alias_match?: boolean | null;
    /** Parent shape's transposed root — the root the detail page should open at. */
    display_root?: string | null;
    /** Alias identity to pre-select on the detail page (its own transposed root). */
    alias_root?: string | null;
    alias_quality?: string | null;
    alias_extensions?: string | null;
    alias_bass?: string | null;
}

export function chordShowUrl(chord: ChordUrlShape): string {
    const base = `/library/chords/${chord.slug}`;
    const isRootless = chord.voicing_category === 'rootless';

    // Alias-match results name a different chord than the slug's parent shape.
    // Open the page at the PARENT's transposed root (display_root) so the primary
    // diagram is correct, and pass the alias identity so the page pre-selects it.
    if (chord.alias_match && chord.display_root && chord.alias_root && chord.alias_quality) {
        const params = new URLSearchParams();
        params.set('root', chord.display_root);
        params.set('aliasRoot', chord.alias_root);
        params.set('aliasQuality', chord.alias_quality);
        if (chord.alias_extensions) params.set('aliasExt', chord.alias_extensions);
        if (chord.alias_bass) params.set('aliasBass', chord.alias_bass);
        return `${base}?${params.toString()}`;
    }

    const root = chord.root_note ?? '';
    const isTransposed = chord.transposed_from != null;

    if (isRootless) return `${base}?root=C`;
    if (isTransposed || (root && root !== 'C')) return `${base}?root=${encodeURIComponent(root)}`;
    return base;
}
