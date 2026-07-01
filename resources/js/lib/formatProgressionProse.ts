/**
 * badgeSbnProse — rewrites parenthesised Roman numerals, chord tones, and
 * rhythm-count tokens in hand-authored prose (progression/rhythm `intro` and
 * `details` HTML) into the shared `.sbn-numeral-chip` badge, a colour-coded
 * chord-tone dot (`.sbn-prose-tone-dot`), or a rhythm-count dot
 * (`.sbn-prose-count-dot`, coloured by the host page via CSS).
 *
 * Authors opt in per-occurrence by parenthesising the token, e.g.:
 *   "the (ii7) resolves to (V7) then (Imaj7)"
 *   "the chord's (b9) resolves down to the (5)"
 *   "the (3rd) moves up to the (b9th)"
 *   "the push lands on the (1e) and the (2+a)"
 * Anything not parenthesised is left as plain text.
 */

// Same palette as GT_COLORS in public/js/chords.js — keep in sync.
const TONE_COLORS: Record<string, string> = {
    root:    '#16a34a',
    third:   '#2563eb',
    fifth:   '#6b7280',
    seventh: '#d97706',
    ninth:   '#7c3aed',
};

function toneColorFor(label: string): string | null {
    const l = label.trim();
    if (l === 'R' || l.toLowerCase() === 'root') return TONE_COLORS.root;
    if (l === '5' || l === 'b5' || l === '#5') return TONE_COLORS.fifth;
    if (l === 'b7' || l === '7' || l === 'maj7' || l === 'bb7') return TONE_COLORS.seventh;
    if (l === '3' || l === 'b3') return TONE_COLORS.third;
    if (
        l === '9' || l === 'b9' || l === '#9' || l === '2' ||
        l === '11' || l === '#11' || l === '4' ||
        l === '13' || l === 'b13' || l === '6'
    ) return TONE_COLORS.ninth;
    return null;
}

const ROMAN_NUMERAL_RE = /^(b|#)?(vii|vi|v|iv|iii|ii|i)((?:maj|dim|sus|add)?[0-9]*[+°ø]?(?:b[0-9]+|#[0-9]+)*)$/i;
const CHORD_TONE_RE = /^(b|#)?(2|3|4|5|6|7|9|11|13|R|root)$/i;
// Ordinal / spelled-out form: "3rd", "b7th", "flat 9th", "sharp 11th"
const ORDINAL_TONE_RE = /^(flat |sharp )?(b|#)?(2|3|4|5|6|7|9|11|13)(nd|rd|th)$/i;
// Rhythm subdivision count: "1+", "1e", "1a", "2e+a", "4-e-+-a" (bare "1"-"4"
// is ambiguous with a chord tone, so require at least one subdivision letter).
const RHYTHM_COUNT_RE = /^[1-4](?:[-\s]?[e+a]){1,3}$/i;

function badgeToken(raw: string): string | null {
    if (RHYTHM_COUNT_RE.test(raw)) {
        return `<span class="sbn-prose-count-dot">${raw}</span>`;
    }
    if (ROMAN_NUMERAL_RE.test(raw)) {
        return `<span class="sbn-numeral-chip">${raw}</span>`;
    }
    if (CHORD_TONE_RE.test(raw)) {
        const color = toneColorFor(raw);
        if (color) {
            return `<span class="sbn-prose-tone-dot" style="--tone-clr: ${color}">${raw}</span>`;
        }
    }
    const ordinalMatch = raw.match(ORDINAL_TONE_RE);
    if (ordinalMatch) {
        const word = ordinalMatch[1]?.trim().toLowerCase();
        const accidental = ordinalMatch[2] ?? (word === 'flat' ? 'b' : word === 'sharp' ? '#' : '');
        const degree = ordinalMatch[3];
        const color = toneColorFor(`${accidental}${degree}`);
        if (color) {
            return `<span class="sbn-prose-tone-dot" style="--tone-clr: ${color}">${raw}</span>`;
        }
    }
    return null;
}

/** Rewrite `(token)` occurrences outside of HTML tags into badge/dot spans. */
export function badgeSbnProse(html: string): string {
    if (!html) return html;
    // Split on tags so we never touch attribute values inside existing markup.
    return html.replace(/(<[^>]*>)|(\([^()<>]{1,12}\))/g, (match, tag, paren) => {
        if (tag) return tag;
        const inner = paren.slice(1, -1).trim();
        const badge = badgeToken(inner);
        return badge ?? paren;
    });
}
