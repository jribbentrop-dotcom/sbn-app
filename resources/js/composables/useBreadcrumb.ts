import type { BreadcrumbSegment } from '@/Components/Breadcrumb.vue';
import { difficultyLabel } from './useCategoryColors';

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
