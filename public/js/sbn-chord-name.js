/**
 * SBN Chord Name Formatter (JavaScript)
 *
 * Mirrors App\Helpers\ChordName::format() output so client-side
 * rendering produces identical HTML to the Blade helper.
 *
 * Usage:
 *   sbnFormatChord('Am7b5/G')   → inner HTML (spans only)
 *   sbnStyledChord('Am7b5/G')   → wrapped in .sbn-chord-symbol
 */

(function () {
    'use strict';

    const ACCIDENTALS = { '#': '♯', '♯': '♯', 'b': '♭', '♭': '♭' };

    // Longest-first so greedy match works
    const QUALITIES = ['min', 'maj', 'dim', 'aug', 'sus4', 'sus2', 'sus', 'add', 'm'];

    function esc(s) {
        const d = document.createElement('span');
        d.textContent = s;
        return d.innerHTML;
    }

    function formatBass(bass) {
        bass = bass.trim();
        if (!bass) return '';

        let html = '<span class="sbn-chord-bass">/';
        html += bass[0].toUpperCase();

        if (bass.length > 1 && ACCIDENTALS[bass[1]]) {
            html += '<span class="sbn-bass-accidental">' + ACCIDENTALS[bass[1]] + '</span>';
        }

        html += '</span>';
        return html;
    }

    window.sbnFormatChord = function (chord) {
        chord = (chord || '').trim();
        if (!chord) return '';

        let pos = 0;
        let html = '';

        // 1. Root note
        const root = chord[pos];
        if (!/^[A-Ga-g]$/.test(root)) return esc(chord);
        html += '<span class="sbn-chord-root">' + root.toUpperCase() + '</span>';
        pos++;

        // 2. Root accidental
        if (pos < chord.length && ACCIDENTALS[chord[pos]]) {
            html += '<span class="sbn-chord-accidental">' + ACCIDENTALS[chord[pos]] + '</span>';
            pos++;
        }

        // 3. Split off bass
        let remaining = chord.slice(pos);
        let bassHtml = '';

        const slashIdx = remaining.indexOf('/');
        if (slashIdx !== -1) {
            bassHtml = formatBass(remaining.slice(slashIdx + 1));
            remaining = remaining.slice(0, slashIdx);
        }

        // 4. Quality
        const remainingLower = remaining.toLowerCase();
        let qualityLen = 0;

        for (const q of QUALITIES) {
            if (remainingLower.startsWith(q)) {
                qualityLen = q.length;
                const afterQuality = remaining.slice(qualityLen);
                // Suppress bare "maj" — pure major chord needs no quality label
                const isBareM = q === 'maj' && !afterQuality;
                if (!isBareM) {
                    let display = q === 'min' ? 'm' : remaining.slice(0, qualityLen);
                    html += '<span class="sbn-chord-quality">' + esc(display) + '</span>';
                }
                break;
            }
        }

        remaining = remaining.slice(qualityLen);

        // 5. Extensions
        if (remaining) {
            let extDisplay = remaining.replace(/#/g, '♯').replace(/b/g, '♭');
            // Protect "add" from becoming "a♭♭"
            extDisplay = extDisplay.replace(/a♭♭/g, 'add');
            html += '<span class="sbn-chord-ext">' + esc(extDisplay) + '</span>';
        }

        // 6. Bass
        html += bassHtml;

        return html;
    };

    window.sbnStyledChord = function (chord) {
        return '<span class="sbn-chord-symbol">' + sbnFormatChord(chord) + '</span>';
    };

    /**
     * Normalize a chord token: strip bare "maj" so "Gmaj" → "G",
     * preserving extended qualities ("Gmaj7" stays intact).
     * Mirrors App\Helpers\ChordName::normalize().
     */
    window.sbnNormalizeChord = function (chord) {
        chord = (chord || '').trim();
        if (!chord) return chord;

        let bass = '';
        const slashIdx = chord.indexOf('/');
        if (slashIdx !== -1) {
            bass = chord.slice(slashIdx);
            chord = chord.slice(0, slashIdx);
        }

        const m = chord.match(/^([A-Ga-g])([#b♯♭]?)(.*)$/);
        if (!m) return chord + bass;

        const root = m[1].toUpperCase();
        const acc  = m[2];
        let rest   = m[3];

        if (/^maj$/i.test(rest)) rest = '';

        return root + acc + rest + bass;
    };
})();
