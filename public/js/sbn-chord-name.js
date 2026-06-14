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
    /**
     * Re-spell a chord name's root and bass note to match the flat/sharp family
     * of the given key.  Mirrors HarmonicContext::reSpellChordName() in PHP.
     * Examples: sbnReSpellChord('D/Gb', 'D') → 'D/F#'
     *           sbnReSpellChord('Db7',  'G') → 'C#7'
     */
    const _SEMI = {C:0,'B#':0,'C#':1,Db:1,D:2,'D#':3,Eb:3,E:4,Fb:4,F:5,'E#':5,'F#':6,Gb:6,G:7,'G#':8,Ab:8,A:9,'A#':10,Bb:10,B:11,Cb:11};
    const _SHARP = ['C','C#','D','D#','E','F','F#','G','G#','A','A#','B'];
    const _FLAT  = ['C','Db','D','Eb','E','F','Gb','G','Ab','A','Bb','B'];
    const _FLAT_KEYS = ['F','Bb','Eb','Ab','Db','Gb','Dm','Gm','Cm','Fm','Bbm','Ebm'];

    function _keyUsesFlats(key) {
        key = (key || '').trim();
        const isMinor = /[mM]/.test(key);
        if (isMinor) {
            const root = key.replace(/[mM].*$/, '');
            const relMajor = _FLAT[((_SEMI[root] ?? 0) + 3) % 12];
            return _FLAT_KEYS.includes(relMajor);
        }
        return _FLAT_KEYS.includes(key) || _FLAT_KEYS.includes(key.replace(/[mM].*$/, ''));
    }

    function _reSpellNote(note, useFlats) {
        const m = note.match(/^([A-G][#b]?)(.*)$/);
        if (!m) return note;
        const s = _SEMI[m[1]];
        if (s === undefined) return note;
        return (useFlats ? _FLAT[s] : _SHARP[s]) + m[2];
    }

    window.sbnReSpellChord = function (chord, key) {
        chord = (chord || '').trim();
        if (!chord || !key) return chord;
        const useFlats = _keyUsesFlats(key);
        const slash = chord.indexOf('/');
        if (slash !== -1) {
            const root = chord.slice(0, slash);
            const bass = chord.slice(slash + 1);
            const rm = root.match(/^([A-G][#b]?)(.*)$/);
            if (!rm) return chord;
            return _reSpellNote(rm[1], useFlats) + rm[2] + '/' + _reSpellNote(bass, useFlats);
        }
        const rm = chord.match(/^([A-G][#b]?)(.*)$/);
        if (!rm) return chord;
        return _reSpellNote(rm[1], useFlats) + rm[2];
    };

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
