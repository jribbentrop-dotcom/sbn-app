import { router } from '@inertiajs/vue3'

// Previous Inertia page URL, captured on every navigation's 'before' event
// (fires while the current page is still active, so window.location is still the old URL).
let _previousUrl: string | null = null

export function initBackNav() {
    router.on('before', () => {
        _previousUrl = window.location.pathname + window.location.search
    })
}

/** Maps known in-app path prefixes to readable section labels. */
function sectionLabel(path: string): string | null {
    if (/^\/library\/chords/.test(path))       return 'Chord Library'
    if (/^\/library\/songs/.test(path))         return 'Song Library'
    if (/^\/library\/progressions/.test(path))  return 'Progressions'
    if (/^\/library\/rhythms/.test(path))       return 'Rhythm Library'
    if (/^\/library\/theory/.test(path))        return 'Theory'
    if (/^\/courses/.test(path))                return 'Courses'
    return null
}

export interface BackNavResult {
    /** Contextual "back to where you came from" — null when not available */
    prev: { href: string; label: string } | null
    /** Always-present library escape hatch */
    library: { href: string; label: string }
}

/**
 * Call at the top of any detail page component.
 *
 * @param libraryHref  - Fixed href for the library index, e.g. '/library/chords'
 * @param libraryLabel - Label for that link, e.g. 'Chord Library'
 * @param prevTitle    - Optional: Inertia prop with the previous page's title
 *                       (e.g. a song name passed via shared data or page prop)
 */
export function useBackNav(
    libraryHref: string,
    libraryLabel: string,
    prevTitle?: string | null,
): BackNavResult {
    const prev = _previousUrl

    let prevEntry: BackNavResult['prev'] = null

    if (prev && prev !== libraryHref) {
        // Use an explicit title if provided (e.g. song name from Inertia props)
        if (prevTitle) {
            prevEntry = { href: prev, label: prevTitle }
        } else {
            // Auto-label from path — only surface if it's a recognisable section
            // and not the same section as the library fallback
            const label = sectionLabel(prev)
            if (label && label !== libraryLabel) {
                prevEntry = { href: prev, label }
            }
        }
    }

    return {
        prev: prevEntry,
        library: { href: libraryHref, label: libraryLabel },
    }
}
