const SLUG_TO_TOKEN: Record<string, string> = {
    'bossa-nova': '--clr-style-bossa',
    'jazz':       '--clr-style-jazz',
    'samba':      '--clr-style-samba',
    'latin':      '--clr-style-latin',
    'blues':      '--clr-style-blues',
    'pop':        '--clr-style-pop',
    'classical':  '--clr-style-classical',
    'iconic':     '--clr-style-gold',
    'general':    '--clr-style-general',
    // Aliases & extra mappings
    'cuban':      '--clr-style-latin',
    'brazilian':  '--clr-style-bossa',
    // Progression category mappings
    'modal':      '--clr-style-pop',      // Modal uses pop colors
    'other':      '--clr-style-bossa',    // Other uses default bossa color
};

export const STYLE_SLUGS = new Set(Object.keys(SLUG_TO_TOKEN));

export function getCategoryColor(slug: string | undefined): string {
    if (!slug) return 'var(--clr-style-default)';
    
    const cleanSlug = slug.toLowerCase().trim();
    const token = SLUG_TO_TOKEN[cleanSlug] || '--clr-style-default';
    
    return `var(${token})`;
}

export function getCategoryStyle(slug: string | undefined): Record<string, string> {
    return { '--category-color': getCategoryColor(slug) };
}

/** Pick the first category whose slug is a known music style. */
export function getStyleSlug(categories: Array<{ slug: string }>): string | undefined {
    return categories.find(c => STYLE_SLUGS.has(c.slug))?.slug;
}

export function difficultyLabel(n: number): string {
    const labels: Record<number, string> = {
        1: 'Beginner',
        2: 'Early Intermediate',
        3: 'Intermediate',
        4: 'Late Intermediate',
        5: 'Advanced',
    };
    return labels[n] || '';
}

export function useCategoryColors() {
    return {
        getCategoryColor,
        getCategoryStyle,
        getStyleSlug,
        getDifficultyLabel: difficultyLabel,
    };
}
