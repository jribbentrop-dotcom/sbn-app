import type { BreadcrumbSegment } from '@/Components/Breadcrumb.vue';
import { difficultyLabel } from './useCategoryColors';

const STYLE_LABELS: Record<string, string> = {
    'bossa-nova': 'Bossa Nova',
    'jazz':       'Jazz',
    'classical':  'Classical',
    'pop':        'Pop',
};

/** "bossa-nova" → "Bossa Nova", falling back to title-casing unknown slugs. */
export function styleLabel(styleSlug: string | null | undefined): string {
    if (!styleSlug) return '';
    return STYLE_LABELS[styleSlug] ?? styleSlug.replace(/-/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
}

/**
 * Songs → style → difficulty → title trail, shared by every song-detail-style
 * page (library Show, leadsheet Viewer, Cinema) so the order/labels/links
 * can't drift between them.
 */
export function songBreadcrumbSegments(song: {
    styleSlug?: string | null;
    difficulty?: number | null;
    title: string;
}): BreadcrumbSegment[] {
    const segs: BreadcrumbSegment[] = [{ label: 'Songs', href: '/library/songs' }];
    const filterParams: Record<string, string> = {};

    if (song.styleSlug) {
        filterParams.style = song.styleSlug;
        segs.push({
            label: styleLabel(song.styleSlug),
            href: `/library/songs?style=${encodeURIComponent(song.styleSlug)}`,
        });
    }

    const difficultySeg = difficultyBreadcrumbSegment(song.difficulty, '/library/songs', filterParams);
    if (difficultySeg) segs.push(difficultySeg);

    segs.push({ label: song.title });
    return segs;
}

/** Build a difficulty crumb when level is 1–5 (star rating). */
export function difficultyBreadcrumbSegment(
    difficulty: number | null | undefined,
    basePath: string,
    existingParams: Record<string, string> = {},
): BreadcrumbSegment | null {
    const stars = difficulty ?? 0;
    if (stars < 1 || stars > 5) return null;

    const params = new URLSearchParams({ ...existingParams, difficulty: String(stars) });

    return {
        label: difficultyLabel(stars),
        href: `${basePath}?${params}`,
    };
}

/** Parse ?difficulty=1-5 from the current URL for index pre-filtering. */
export function readDifficultyQueryParam(): string {
    if (typeof window === 'undefined') return '';

    const raw = new URLSearchParams(window.location.search).get('difficulty') ?? '';
    const n = parseInt(raw, 10);

    return n >= 1 && n <= 5 ? String(n) : '';
}
