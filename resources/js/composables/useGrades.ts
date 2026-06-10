export interface Grade {
    n: number;
    slug: string;
    label: string;
    blurb: string;
    title: string;
    titleEm: string;
    cta: string;
    clr: string;
}

export const grades: Grade[] = [
    {
        n: 1,
        slug: 'basic',
        label: 'Basic',
        blurb: 'Your first open chords, your first songs. No theory overwhelm — just music from day one. You\'ll learn the handful of shapes that unlock hundreds of songs across pop, folk, and bossa nova.',
        title: 'Your first chords,',
        titleEm: 'your first songs.',
        cta: 'Start here',
        clr: 'var(--g1)',
    },
    {
        n: 2,
        slug: 'early-intermediate',
        label: 'Early Intermediate',
        blurb: 'Barre chords, the bossa nova rhythm, and your first seventh chords — the ones that make everything warmer. You start hearing colour in harmony, not just chord names.',
        title: 'Beyond open chords —',
        titleEm: 'barre shapes click.',
        cta: 'Level up',
        clr: 'var(--g2)',
    },
    {
        n: 3,
        slug: 'intermediate',
        label: 'Intermediate',
        blurb: 'The ii–V–I progression, drop voicings, and the beginning of real harmonic understanding. Chords stop being shapes and start being choices — you learn why one voicing leads smoothly into the next.',
        title: 'Voice leading,',
        titleEm: 'inner movement.',
        cta: 'Explore this level',
        clr: 'var(--g3)',
    },
    {
        n: 4,
        slug: 'late-intermediate',
        label: 'Late Intermediate',
        blurb: 'Tritone substitution, altered dominants, extensions that colour every chord. These are the tools that separate guitarists who play jazz from those who think jazz — tension you control, not tension that controls you.',
        title: 'Alterations, extensions —',
        titleEm: 'colour every chord.',
        cta: 'Dig deeper',
        clr: 'var(--g4)',
    },
    {
        n: 5,
        slug: 'advanced',
        label: 'Advanced',
        blurb: 'Wes Montgomery. Joe Pass. João Gilberto. At this level you study transcriptions, develop your own harmonic language, and stop thinking about chords altogether — you just hear the changes and play.',
        title: 'Rootless voicings —',
        titleEm: 'you hear the changes.',
        cta: 'Push further',
        clr: 'var(--g5)',
    },
];

export function gradeImageSrc(slug: string): string {
    return `/images/level/${slug}.webp`;
}
