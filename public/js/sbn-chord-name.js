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

        // Internal "dom" quality → conventional dominant spelling:
        //   dom7 → 7, dom7(9) → 7(9), dom9 → 9, dom13 → 13, bare dom → 7.
        remaining = remaining.replace(/^dom7/i, '7').replace(/^dom(\d)/i, '$1').replace(/^dom(?=\(|$)/i, '7');

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
    // Sharp keys (+ relative minors). Everything else — flat keys AND neutral
    // C/Am — defaults to flats. Mirror of HarmonicContext::SHARP_KEYS.
    const _SHARP_KEYS = ['G','D','A','E','B','F#','C#','Em','Bm','F#m','C#m','G#m','D#m','A#m'];

    // ── Chord-related accidental rule (mirror of
    //    ChordShapeCalculator::useFlatsForQuality) ───────────────────────────
    const _FLAT_ROOT_NOTES = ['F','Bb','Eb','Ab','Db','Gb'];
    const _FLAT_SIDE_PCS   = [1, 3, 8, 10];          // Db Eb Ab Bb (pc 6 tritone excluded)
    const _QUALITY_FLAT_INTERVALS = {                // minor/dim intervals only
        min:[3], m7:[3,10], m6:[3], m7b5:[3,6,10], mMaj7:[3], dom7:[10], aug7:[10],
    };

    // Map a written quality suffix → internal token used above.
    function _normalizeQualityToken(suffix) {
        const raw = suffix || '';
        let s = raw.toLowerCase().trim().replace(/\/.*$/, '').replace(/\(.*$/, '');
        if (s === '' || s === 'maj') return '';
        if (/^(maj|ma|m)(7|9|11|13)/.test(raw)) return 'maj7';
        if (/^(m|min|-)(7b5|7\(b5\))/.test(s)) return 'm7b5';
        if (/^(o|dim|°)7/.test(s))             return 'm7b5';   // dim7 ~ flat lean
        if (/^(m|min|-)maj7/.test(s))          return 'mMaj7';
        if (/^(m|min|-)6/.test(s))             return 'm6';
        if (/^(m|min|-)/.test(s))              return 'm7';     // any minor ⇒ flat-lean
        if (/^aug7|^\+7/.test(s))              return 'aug7';
        if (/^(7|9|11|13)/.test(s))            return 'dom7';   // dominant
        return '';
    }

    function _useFlatsForQuality(rootNote, quality) {
        if (_FLAT_ROOT_NOTES.includes(rootNote) || /b/.test(rootNote)) return true;
        if (/#/.test(rootNote)) return false;
        const rootPc = _SEMI[rootNote];
        const intervals = _QUALITY_FLAT_INTERVALS[quality];
        if (rootPc === undefined || !intervals) return false;
        return intervals.some(iv => _FLAT_SIDE_PCS.includes((rootPc + iv) % 12));
    }

    // House style: flats by default. Only genuine sharp keys spell with sharps;
    // flat keys AND neutral C/Am use flats. Mirror of
    // HarmonicContext::spellingUsesFlats().
    function _keyUsesFlats(key) {
        key = (key || '').trim();
        const keyRoot = key.replace(/[mM].*$/, '');
        const semi = _SEMI[keyRoot];
        if (semi === undefined) return true;            // unknown ⇒ flats
        if (/[mM]/.test(key)) {
            return !_SHARP_KEYS.includes(_SHARP[semi] + 'm');
        }
        return !_SHARP_KEYS.includes(_SHARP[semi]);
    }

    // The single two-layer decision (mirror of HarmonicContext::useFlatsFor):
    //   key given  → key family (dominant rule)
    //   no key     → chord root+quality lean (fallback rule)
    function _useFlatsFor(rootNote, quality, key) {
        if ((key || '').trim() !== '') return _keyUsesFlats(key);
        return _useFlatsForQuality(rootNote, quality);
    }

    function _reSpellNote(note, useFlats) {
        const m = note.match(/^([A-G][#b]?)(.*)$/);
        if (!m) return note;
        const s = _SEMI[m[1]];
        if (s === undefined) return note;
        return (useFlats ? _FLAT[s] : _SHARP[s]) + m[2];
    }

    // Bass intervals above the root that are always spelled flat, regardless of
    // key: b9(1), b3(3), b5(6), b13(8), b7(10). A slash bass landing on one of
    // these is an altered/minor chord tone (e.g. the b7 of a dom7 in C7/Bb) and
    // must stay flat even in a sharp key. Mirror of FLAT_BASS_INTERVALS in PHP.
    const _FLAT_BASS_INTERVALS = [1, 3, 6, 8, 10];

    // Spell a slash bass note. If its interval above the chord root is a flat-side
    // alteration/chord tone it is forced flat; otherwise it follows the chord's
    // key/root family (keyUseFlats).
    function _spellBass(bassNote, rootNote, keyUseFlats) {
        const bm = bassNote.match(/^([A-G][#b]?)(.*)$/);
        if (!bm) return bassNote;
        const bassPc = _SEMI[bm[1]], rootPc = _SEMI[rootNote];
        if (bassPc === undefined || rootPc === undefined) return _reSpellNote(bassNote, keyUseFlats);
        const interval = (bassPc - rootPc + 12) % 12;
        const useFlats = _FLAT_BASS_INTERVALS.includes(interval) ? true : keyUseFlats;
        return (useFlats ? _FLAT[bassPc] : _SHARP[bassPc]) + bm[2];
    }

    /**
     * Re-spell a chord name's root + bass to the app's accidental rules.
     * `key` optional: when given the song key drives the family; when omitted
     * the chord's own root+quality lean decides. The slash bass additionally
     * forces flat for altered/minor chord tones (b3/b5/b7/b9/b13) regardless of
     * key. Mirrors HarmonicContext::reSpellChordName().
     */
    window.sbnSpellChordName = function (chord, key) {
        chord = (chord || '').trim();
        if (!chord) return chord;
        const slash = chord.indexOf('/');
        const head  = slash !== -1 ? chord.slice(0, slash) : chord;
        const hm    = head.match(/^([A-G][#b]?)(.*)$/);
        if (!hm) return chord;
        const useFlats = _useFlatsFor(hm[1], _normalizeQualityToken(hm[2]), key);
        const newRoot  = _reSpellNote(hm[1], useFlats);
        if (slash !== -1) {
            // Bass spelled relative to the RE-SPELLED root so the interval is correct.
            return newRoot + hm[2] + '/' + _spellBass(chord.slice(slash + 1), newRoot, useFlats);
        }
        return newRoot + hm[2];
    };

    // Back-compat alias: the original key-only entry point. Now routes through
    // the unified core (key still dominates when present).
    window.sbnReSpellChord = function (chord, key) {
        if (!key) return (chord || '').trim();   // preserve old "no key ⇒ no-op" contract
        return window.sbnSpellChordName(chord, key);
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
