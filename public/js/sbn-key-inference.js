/**
 * SBN Key Inference
 *
 * Infers the tonal key of a parsed leadsheet from its identified chords.
 *
 * The MusicXML <key> element is unreliable for relative-key disambiguation:
 * C major and A minor share the same key signature (fifths=0), and most
 * MusicXML exports omit the <mode> element entirely. This module recovers
 * the key from *functional* evidence instead, treating the XML reading as a
 * weak prior rather than the source of truth.
 *
 * It runs on every import (not just ambiguous signatures) and is built to be
 * window-able: a future modulation-detection pass can call inferKey() over
 * sliding windows of the chord list and assemble a key timeline.
 *
 * Usage:
 *   sbnInferKey(chords, opts) -> { key, confidence, evidence: [] }
 *
 *   chords: [{ name: 'Am', pcs: [9,0,4], durationBeats: 3 }, ...]
 *           - name          chord label (used for endpoint test)
 *           - pcs           pitch classes 0-11 sounding in the chord
 *           - durationBeats  slot duration; used to weight the PC histogram
 *   opts:   { xmlKeyHint: 'C', useEndpoints: true }
 *           - xmlKeyHint    the <key>/<fifths> reading, given a small prior
 *           - useEndpoints  first/last chord = tonic vote (whole-piece only;
 *                           a windowed caller passes false)
 */

(function () {
    'use strict';

    var NOTE_PC = {
        'C': 0, 'C#': 1, 'DB': 1, 'D': 2, 'D#': 3, 'EB': 3, 'E': 4,
        'F': 5, 'F#': 6, 'GB': 6, 'G': 7, 'G#': 8, 'AB': 8, 'A': 9,
        'A#': 10, 'BB': 10, 'B': 11, 'CB': 11
    };

    var PC_NAME_SHARP = ['C', 'C#', 'D', 'D#', 'E', 'F', 'F#', 'G', 'G#', 'A', 'A#', 'B'];

    // Krumhansl-Schmuckler key profiles (major / natural-minor weighting of
    // each scale degree). Index 0 = tonic.
    var MAJOR_PROFILE = [6.35, 2.23, 3.48, 2.33, 4.38, 4.09, 2.52, 5.19, 2.39, 3.66, 2.29, 2.88];
    var MINOR_PROFILE = [6.33, 2.68, 3.52, 5.38, 2.60, 3.53, 2.54, 4.75, 3.98, 2.69, 3.34, 3.17];

    /** Parse a chord label's root letter to a pitch class, or null. */
    function rootPc(name) {
        if (!name) return null;
        var m = String(name).trim().match(/^([A-Ga-g])([#b♯♭]?)/);
        if (!m) return null;
        var key = m[1].toUpperCase() + (m[2] === '♯' ? '#' : m[2] === '♭' ? 'b' : m[2]);
        var pc = NOTE_PC[key.toUpperCase()];
        return pc === undefined ? null : pc;
    }

    /** Pearson correlation of a 12-bin histogram against a profile. */
    function correlate(hist, profile) {
        var n = 12, sx = 0, sy = 0, sxy = 0, sx2 = 0, sy2 = 0;
        for (var i = 0; i < n; i++) {
            sx += hist[i]; sy += profile[i];
            sxy += hist[i] * profile[i];
            sx2 += hist[i] * hist[i]; sy2 += profile[i] * profile[i];
        }
        var num = n * sxy - sx * sy;
        var den = Math.sqrt((n * sx2 - sx * sx) * (n * sy2 - sy * sy));
        return den === 0 ? 0 : num / den;
    }

    /** Rotate a profile so its tonic sits at pitch class `tonic`. */
    function rotated(profile, tonic) {
        var out = new Array(12);
        for (var i = 0; i < 12; i++) out[i] = profile[(i - tonic + 12) % 12];
        return out;
    }

    /**
     * Infer the key of a chord list.
     * Returns { key, confidence ('high'|'medium'|'low'), evidence: [strings] }.
     */
    function inferKey(chords, opts) {
        opts = opts || {};
        var useEndpoints = opts.useEndpoints !== false;
        var hintPc = opts.xmlKeyHint ? rootPc(opts.xmlKeyHint) : null;
        var hintMinor = opts.xmlKeyHint ? /m(in)?$/i.test(String(opts.xmlKeyHint).trim()) : false;

        chords = Array.isArray(chords) ? chords.filter(function (c) {
            return c && Array.isArray(c.pcs) && c.pcs.length;
        }) : [];

        if (!chords.length) {
            return {
                key: opts.xmlKeyHint || 'C',
                confidence: 'low',
                evidence: ['no chord data — fell back to notation key']
            };
        }

        // Duration-weighted pitch-class histogram.
        var hist = new Array(12).fill(0);
        var totalWeight = 0;
        chords.forEach(function (c) {
            var w = (typeof c.durationBeats === 'number' && c.durationBeats > 0) ? c.durationBeats : 1;
            totalWeight += w;
            c.pcs.forEach(function (pc) {
                if (pc >= 0 && pc < 12) hist[((pc % 12) + 12) % 12] += w;
            });
        });

        // Score every candidate key: 12 major + 12 minor.
        var candidates = [];
        for (var tonic = 0; tonic < 12; tonic++) {
            candidates.push({ tonic: tonic, minor: false, score: correlate(hist, rotated(MAJOR_PROFILE, tonic)) });
            candidates.push({ tonic: tonic, minor: true, score: correlate(hist, rotated(MINOR_PROFILE, tonic)) });
        }

        // Leading-tone bonus: a minor key behaving tonally raises its 7th
        // degree (e.g. G# in A minor). C major essentially never needs it.
        // This is the decisive signal for relative-key (no-accidental) pieces.
        var ltHist = new Array(12).fill(0);
        chords.forEach(function (c) {
            c.pcs.forEach(function (pc) { ltHist[((pc % 12) + 12) % 12] += 1; });
        });
        candidates.forEach(function (cand) {
            if (cand.minor) {
                var ltPc = (cand.tonic + 11) % 12;          // raised 7th
                var subtonicPc = (cand.tonic + 10) % 12;    // natural 7th
                if (ltHist[ltPc] > 0) {
                    cand.score += 0.18 * Math.min(1, ltHist[ltPc] / 2);
                    cand.leadingTone = true;
                }
                // A minor candidate whose raised 7th never appears but whose
                // natural 7th does is weak — leave score as-is (no penalty,
                // modal pieces are valid).
                void subtonicPc;
            }
        });

        // Endpoint bonus: the last (and, weaker, first) chord tends to be the
        // tonic. Skipped by windowed callers.
        var firstRoot = useEndpoints ? rootPc(chords[0].name) : null;
        var lastRoot = useEndpoints ? rootPc(chords[chords.length - 1].name) : null;
        candidates.forEach(function (cand) {
            if (lastRoot !== null && lastRoot === cand.tonic) {
                cand.score += 0.10; cand.endsOnTonic = true;
            }
            if (firstRoot !== null && firstRoot === cand.tonic) {
                cand.score += 0.05; cand.startsOnTonic = true;
            }
        });

        // XML hint: a modest prior so genuinely ambiguous pieces defer to the
        // notation, but strong functional evidence can still override it.
        candidates.forEach(function (cand) {
            if (hintPc !== null && cand.tonic === hintPc && cand.minor === hintMinor) {
                cand.score += 0.06; cand.matchesHint = true;
            }
        });

        candidates.sort(function (a, b) { return b.score - a.score; });
        var win = candidates[0];
        var runnerUp = candidates[1];

        var key = PC_NAME_SHARP[win.tonic] + (win.minor ? 'm' : '');

        // Confidence from the margin over the runner-up.
        var margin = win.score - runnerUp.score;
        var confidence = margin > 0.08 ? 'high' : margin > 0.03 ? 'medium' : 'low';

        var evidence = [];
        if (win.leadingTone) evidence.push('leading-tone of ' + key + ' present');
        evidence.push('profile correlation ' + win.score.toFixed(2));
        if (win.endsOnTonic) evidence.push('ends on tonic');
        if (win.matchesHint) evidence.push('agrees with notation key');
        else if (hintPc !== null) {
            evidence.push('overrides notation key (' + opts.xmlKeyHint + ')');
        }

        return { key: key, confidence: confidence, evidence: evidence };
    }

    /**
     * Convert a fret string (index 0 = string 1 / low E) to its pitch classes.
     * 'x' = muted, '0' = open, hex 'a'-'f' = frets 10-15. Matches the SBN
     * diagram convention (string 1 = low E).
     */
    var OPEN_STRING_PC = [4, 9, 2, 7, 11, 4]; // E A D G B E, string 1..6

    function fretsToPcs(fretStr) {
        if (!fretStr) return [];
        var pcs = {};
        String(fretStr).split('').forEach(function (ch, i) {
            if (i > 5 || ch === 'x' || ch === 'X') return;
            var fret = parseInt(ch, 16);
            if (isNaN(fret)) return;
            pcs[(OPEN_STRING_PC[i] + fret) % 12] = true;
        });
        return Object.keys(pcs).map(Number);
    }

    window.sbnInferKey = inferKey;
    window.sbnFretsToPcs = fretsToPcs;
})();
