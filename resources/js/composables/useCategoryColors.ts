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

const DIFFICULTY_SLUGS: Record<string, number> = {
    'basic':              1,
    'early-intermediate': 2,
    'intermediate':       3,
    'late-intermediate':  4,
    'advanced':           5,
};

/** Return star count (1-5) from a product's category list, or 0 if none found. */
export function getDifficultyFromCategories(categories: Array<{ slug: string }>): number {
    for (const cat of categories) {
        const n = DIFFICULTY_SLUGS[cat.slug];
        if (n) return n;
    }
    return 0;
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
