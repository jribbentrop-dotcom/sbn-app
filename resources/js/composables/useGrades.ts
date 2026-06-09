export interface Grade {
    n: number;
    slug: string;
    label: string;
    blurb: string;
    title: string;
    titleEm: string;
    chords: string[];
    cta: string;
    clr: string;
}

export const grades: Grade[] = [
    {
        n: 1,
        slug: 'basic',
        label: 'Basic',
        blurb: 'Your first open chords, your first songs. No theory overwhelm — just music from day one.',
        title: 'Your first chords,',
        titleEm: 'your first songs.',
        chords: ['Em', 'Am', 'G', 'C', 'D'],
        cta: 'Start here',
        clr: 'var(--g1)',
    },
    {
        n: 2,
        slug: 'early-intermediate',
        label: 'Early Intermediate',
        blurb: 'Barre chords, the bossa nova rhythm, and your first seventh chords — the ones that make everything warmer.',
        title: 'Beyond open chords —',
        titleEm: 'barre shapes click.',
        chords: ['Fmaj7', 'Bm7', 'E7', 'Am7', 'Dm7'],
        cta: 'Level up',
        clr: 'var(--g2)',
    },
    {
        n: 3,
        slug: 'intermediate',
        label: 'Intermediate',
        blurb: 'The ii–V–I progression, drop voicings, and the beginning of real harmonic understanding.',
        title: 'Voice leading,',
        titleEm: 'inner movement.',
        chords: ['Cmaj7', 'Am7', 'Dm7', 'G7', 'Em7b5'],
        cta: 'Explore this level',
        clr: 'var(--g3)',
    },
    {
        n: 4,
        slug: 'late-intermediate',
        label: 'Late Intermediate',
        blurb: 'Voice leading, tritone substitution, altered dominants — the tools that separate guitarists who play jazz from those who think jazz.',
        title: 'Alterations, extensions —',
        titleEm: 'colour every chord.',
        chords: ['Cmaj7#11', 'G7b9', 'Dm9', 'A7#5', 'Bbmaj7#11'],
        cta: 'Dig deeper',
        clr: 'var(--g4)',
    },
    {
        n: 5,
        slug: 'advanced',
        label: 'Advanced',
        blurb: 'Wes Montgomery. Joe Pass. João Gilberto. Studying transcriptions, developing your own harmonic language.',
        title: 'Rootless voicings —',
        titleEm: 'you hear the changes.',
        chords: ['G13b9', 'Db7#11', 'Em7b5', 'A7alt', 'Dm9'],
        cta: 'Push further',
        clr: 'var(--g5)',
    },
];

export function gradeImageSrc(slug: string): string {
    return `/images/level/${slug}.webp`;
}
