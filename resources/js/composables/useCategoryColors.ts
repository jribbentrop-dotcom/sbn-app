const SLUG_TO_TOKEN: Record<string, string> = {
    'bossa-nova': '--clr-style-bossa',
    'jazz':       '--clr-style-jazz',
    'classical':  '--clr-style-classical',
    'pop':        '--clr-style-pop',
    // legacy alias still present in older data
    'bossa':      '--clr-style-bossa',
};

export const CANONICAL_CATEGORIES = ['bossa-nova', 'jazz', 'classical', 'pop'] as const;
export type CategorySlug = typeof CANONICAL_CATEGORIES[number];

export const STYLE_SLUGS = new Set(Object.keys(SLUG_TO_TOKEN));

export function getCategoryColor(slug: string | undefined): string {
    if (!slug) return 'var(--clr-style-default)';
    const token = SLUG_TO_TOKEN[slug.toLowerCase().trim()] || '--clr-style-default';
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
