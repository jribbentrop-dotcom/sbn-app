/**
 * Progression Builder — frontend component
 *
 * An independent, reusable component that can be embedded anywhere:
 *   - Progression Library modal  (show voicings for a detected progression)
 *   - Leadsheet player           (voice leading suggestions for a passage)
 *   - Standalone builder         (pick any key + progression)
 *
 * API:
 *   SbnProgressionBuilder.init(containerEl, {
 *       numerals: 'IIm7,V7,Imaj7',
 *       key: 'C',
 *       ajaxUrl: '...',
 *       nonce: '...',
 *   })
 *
 * Depends on: SbnChordCard (sbn-chord-card.js) for diagram rendering.
 *
 * @package SBN_Course_Player
 * @since   7.8.5
 */

(function (root) {
    'use strict';

    var VOICING_LABELS = {
        'drop2': 'Drop 2', 'drop3': 'Drop 3', 'shell': 'Shell',
        'rootless': 'Rootless', 'closed': 'Closed', 'open': 'Open',
        'custom': 'Custom'
    };

    var ROOT_STRING_LABELS = {
        'roote': 'E string', 'roota': 'A string', 'rootd': 'D string', 'rootg': 'G string'
    };

    var INVERSION_SHORT = {
        'root': 'Root', 'inv1': '1st', 'inv2': '2nd', 'inv3': '3rd'
    };

    // =========================================================================
    // STATE
    // =========================================================================

    /**
     * Each instance tracks:
     *   - chords:       array of resolved chord objects from the server
     *   - selections:   array of selected voicing objects (one per chord slot, null if unset)
     *   - activeSlot:   which chord slot is currently expanded (0-indexed, or -1)
     *   - vlScores:     voice leading scores between adjacent selections
     *   - filters:      current filter state { voicing_category, root_string }
     */
    function createState(opts) {
        return {
            container:  null,
            numerals:   opts.numerals || '',
            key:        opts.key || 'C',
            ajaxUrl:    opts.ajaxUrl || '',
            nonce:      opts.nonce || '',
            category:   opts.category || '',
            tonality:   opts.tonality || 'both',
            chords:     [],
            selections: [],
            activeSlot: -1,
            vlScores:   [],
            loading:    false,
            filters:    { voicing_category: '', root_string: '' },
            // vlLevel is derived from settings.extensions — kept for back-compat with VL scorer
            vlLevel:    1,
            settings: {
                style:       '',       // '' = any, 'shell', 'drop', 'closed'
                extensions:  false,    // true = include extension voicings + level-II VL targets
                rootOnly:    false,    // true = root position only (no inversions)
            },
            initialDiagramIds: opts.initialDiagramIds || [],
        };
    }

    // =========================================================================
    // INIT
    // =========================================================================

    function init(containerEl, opts) {
        if (!containerEl) return null;

        var state = createState(opts);
        state.container = containerEl;

        containerEl.classList.add('sbn-pb');
        containerEl.innerHTML = '<div class="sbn-pb-loading">Loading voicings…</div>';

        // Fetch progression data
        fetchProgression(state);

        return {
            getSelections: function () { return state.selections; },
            setKey: function (key) {
                state.key = key;
                fetchProgression(state);
            },
            destroy: function () {
                containerEl.innerHTML = '';
                containerEl.classList.remove('sbn-pb');
            }
        };
    }

    // =========================================================================
    // DATA FETCHING
    // =========================================================================

    function fetchProgression(state) {
        // Keep vlLevel in sync with extensions setting
        state.vlLevel = state.settings.extensions ? 2 : 1;

        state.loading = true;
        render(state);

        var data = new FormData();
        data.append('action', 'sbn_get_progression_voicings');
        data.append('nonce', state.nonce);
        data.append('numerals', state.numerals);
        data.append('key', state.key);
        data.append('vl_level', state.vlLevel);
        data.append('category', state.category);
        data.append('tonality', state.tonality);
        if (state.settings.style) {
            // 'drop' covers both drop2 and drop3 — pass as comma-separated for PHP to handle,
            // or pass the group name; PHP sbn_pb_find_voicings accepts voicing_category exactly.
            // We pass empty here and filter client-side via autoSuggest's lockedGroup instead,
            // so that both drop2 and drop3 come back and can be mixed.
            // For shell/closed we CAN filter server-side since they're single categories.
            if (state.settings.style !== 'drop') {
                data.append('voicing_category', state.settings.style);
            }
        }

        fetch(state.ajaxUrl, { method: 'POST', body: data })
            .then(function (r) { return r.json(); })
            .then(function (resp) {
                state.loading = false;
                if (resp.success && resp.data) {
                    state.chords = resp.data.chords || [];
                    state.vlScores = [];
                    state.activeSlot = -1;

                    // If initial diagram IDs were provided, fetch those exact voicings
                    // directly by ID (bypasses quality/extension filter) then inject as selections.
                    if (state.initialDiagramIds && state.initialDiagramIds.length &&
                        state.initialDiagramIds.some(function(id){ return id; })) {

                        var slots = state.chords.map(function(chord, i) {
                            return {
                                diagram_id: state.initialDiagramIds[i] || 0,
                                root_note:  chord.root || 'C'
                            };
                        });
                        state.initialDiagramIds = []; // clear — key changes restore normally

                        var idData = new FormData();
                        idData.append('action', 'sbn_get_voicings_by_ids');
                        idData.append('nonce', state.nonce);
                        idData.append('slots', JSON.stringify(slots));

                        fetch(state.ajaxUrl, { method: 'POST', body: idData })
                            .then(function(r){ return r.json(); })
                            .then(function(idResp) {
                                if (idResp.success && Array.isArray(idResp.data)) {
                                    state.selections = idResp.data.map(function(v){ return v || null; });
                                } else {
                                    state.selections = new Array(state.chords.length).fill(null);
                                }
                                computeVoiceLeading(state);
                                render(state);
                                // Notify the host page that initial voicings are loaded
                                state.container.dispatchEvent(new CustomEvent('sbn-pb-ready', {
                                    detail: { selections: state.selections }
                                }));
                            })
                            .catch(function() {
                                state.selections = new Array(state.chords.length).fill(null);
                                render(state);
                            });

                        render(state); // show chord slots while IDs load

                        // Listen for external voicing injection (e.g. from chord-detail-page)
                        state.container.addEventListener('sbn-pb-inject', function(e) {
                            var slot    = e.detail && e.detail.slot;
                            var voicing = e.detail && e.detail.voicing;
                            if (voicing && slot >= 0 && slot < state.selections.length) {
                                state.selections[slot] = voicing;
                                computeVoiceLeading(state);
                                render(state);
                            }
                        }, { once: true }); // once — only apply on initial load
                        return;
                    }

                    state.selections = new Array(state.chords.length).fill(null);
                    render(state);
                } else {
                    state.container.innerHTML = '<div class="sbn-pb-error">Could not load voicings.</div>';
                }
            })
            .catch(function () {
                state.loading = false;
                state.container.innerHTML = '<div class="sbn-pb-error">Network error.</div>';
            });
    }

    // =========================================================================
    // VOICE LEADING COMPUTATION (client-side)
    // =========================================================================

    /**
     * Compute voice leading scores between all adjacent selected voicings.
     * Done client-side for instant feedback.
     */
    function computeVoiceLeading(state) {
        var scores = [];
        for (var i = 0; i < state.selections.length - 1; i++) {
            var a = state.selections[i];
            var b = state.selections[i + 1];
            if (a && b) {
                scores.push(scoreVL(a, b, state.vlLevel));
            } else {
                scores.push(null);
            }
        }
        state.vlScores = scores;
    }

    var NOTE_SEMI = {
        'C': 0, 'C#': 1, 'Db': 1, 'D': 2, 'D#': 3, 'Eb': 3,
        'E': 4, 'F': 5, 'F#': 6, 'Gb': 6, 'G': 7, 'G#': 8, 'Ab': 8,
        'A': 9, 'A#': 10, 'Bb': 10, 'B': 11
    };

    // Interval labels we care about, grouped by role
    var SEVENTH_LABELS = { 'b7': 1, '7': 1, 'maj7': 1 };
    var THIRD_LABELS   = { '3': 1, 'b3': 1 };
    var ROOT_LABELS    = { 'R': 1 };
    var GUIDE_LABELS   = { '3': 1, 'b3': 1, '7': 1, 'b7': 1, 'maj7': 1 };

    /**
     * Extract guide tone info: [{semi, label, stringIdx}]
     */
    function extractGuides(intervals, notes) {
        var guides = [];
        intervals.forEach(function (label, i) {
            if (GUIDE_LABELS[label] && notes[i] !== undefined) {
                guides.push({ semi: NOTE_SEMI[notes[i]], label: label, idx: i });
            }
        });
        return guides.filter(function (g) { return g.semi !== undefined; });
    }

    /**
     * Semitone distance (shortest path around the circle)
     */
    function semiDist(a, b) {
        var d = Math.abs(a - b);
        return Math.min(d, 12 - d);
    }

    // Standard tuning open-string MIDI pitches.
    // Our string numbering: 1=low E, 2=A, 3=D, 4=G, 5=B, 6=high E
    var OPEN_STRING_MIDI = { 1: 40, 2: 45, 3: 50, 4: 55, 5: 59, 6: 64 };

    /**
     * Build an array of {midi, label, stringNum} for every sounding note
     * in a voicing, using diagram_data.positions + interval_labels.
     */
    function buildPitchMap(voicing) {
        var dd = voicing.diagram_data;
        if (!dd || !dd.positions) return [];

        var intvArr = (voicing.interval_labels || '').split(',').filter(Boolean);
        var notesArr = (voicing.notes || '').split(',').filter(Boolean);

        var result = [];
        var noteIdx = 0;

        // Count sounding strings for validation
        var soundingCount = 0;
        dd.positions.forEach(function (pos) {
            if (pos.fret !== null && pos.fret !== 'x' && pos.fret !== -1) soundingCount++;
        });

        // If label count doesn't match sounding string count, the data is
        // misaligned — return empty to trigger the fret-distance fallback
        // rather than scoring with shifted labels.
        if (intvArr.length && intvArr.length !== soundingCount) return [];

        // positions are ordered by string (1→6 in our numbering)
        dd.positions.forEach(function (pos) {
            if (pos.fret === null || pos.fret === 'x' || pos.fret === -1) return; // muted
            var midi = (OPEN_STRING_MIDI[pos.string] || 0) + parseInt(pos.fret, 10);
            var label = intvArr[noteIdx] || '';
            var note = notesArr[noteIdx] || '';
            result.push({ midi: midi, label: label, note: note, string: pos.string });
            noteIdx++;
        });

        return result;
    }

    // Labels for Level II jazz extensions
    var NINTH_LABELS    = { '9': 1, 'b9': 1, '#9': 1 };
    var MAJ7_LABELS     = { 'maj7': 1 };
    var SIXTH_LABELS    = { '6': 1, '13': 1, 'b13': 1 };
    var FIFTH_LABELS    = { '5': 1 };
    var ELEVENTH_LABELS = { '11': 1, '#11': 1 };

    // Quality-based chord function lookups
    // IMPORTANT: these must match the canonical quality strings from PHP's
    // sbn_pb_suffix_to_quality(): 'dom7', 'min', 'maj', 'm7', 'maj7', etc.
    var DOM_QUALITIES       = { 'dom7': 1, '7': 1, '7alt': 1, '7#11': 1, '7b9': 1, '7#9': 1, '7b13': 1, '7b9b13': 1, '9': 1, '13': 1 };
    var TONIC_MAJ_QUALITIES = { 'maj7': 1, 'maj6': 1, 'maj9': 1, 'maj13': 1, '6': 1, '69': 1, 'maj': 1 };
    var HALF_DIM_QUALITIES  = { 'm7b5': 1, 'half-dim': 1 };

    function isDomQuality(quality)      { return !!DOM_QUALITIES[quality]; }
    function isTonicMajQuality(quality)  { return !!TONIC_MAJ_QUALITIES[quality]; }
    function isHalfDimQuality(quality)   { return !!HALF_DIM_QUALITIES[quality]; }

    /**
     * Attempt to infer a chord quality from a Roman numeral string.
     * Used as a fallback when the server doesn't populate .quality.
     *
     * Examples:
     *   'V7'     → '7'
     *   'IIm7'   → 'm7'
     *   'Imaj7'  → 'maj7'
     *   'VIIm7b5'→ 'm7b5'
     *   'V7#11'  → '7#11'
     */
    function inferQuality(numeral) {
        if (!numeral) return '';
        // Strip the Roman numeral prefix (case-insensitive)
        var suffix = numeral.replace(/^(b|#)?(VII|VI|IV|V|III|II|I|vii|vi|iv|v|iii|ii|i)/, '');
        return mapSuffixToQuality(suffix);
    }

    /**
     * Infer quality from a concrete chord name like "F7", "Cm7b5", "Bbm".
     */
    function inferQualityFromName(name) {
        if (!name) return '';
        // Strip root note (e.g. "Bb", "F#", "C")
        var suffix = name.replace(/^[A-G][b#]?/, '');
        return mapSuffixToQuality(suffix);
    }

    /**
     * Shared suffix → quality mapper.
     */
    function mapSuffixToQuality(suffix) {
        if (!suffix) return 'maj';
        // Map common suffixes to PHP-canonical quality strings
        // Order matters: longest match first
        if (/^m7b5/.test(suffix))    return 'm7b5';
        if (/^mMaj7/.test(suffix))   return 'mMaj7';
        if (/^mmaj7/.test(suffix))   return 'mMaj7';
        if (/^m7/.test(suffix))      return 'm7';
        if (/^m6/.test(suffix))      return 'm6';
        if (/^m9/.test(suffix))      return 'm7';    // m9 = m7 with 9th extension
        if (/^min/.test(suffix))     return 'min';
        if (/^m$/.test(suffix))      return 'min';
        if (/^maj7#11/.test(suffix)) return 'maj7';   // maj7 family
        if (/^maj7/.test(suffix))    return 'maj7';
        if (/^maj9/.test(suffix))    return 'maj7';   // maj9 = maj7 with 9th
        if (/^maj13/.test(suffix))   return 'maj7';
        if (/^maj6/.test(suffix))    return 'maj6';
        if (/^7#11/.test(suffix))    return 'dom7';
        if (/^7b9b13/.test(suffix))  return 'dom7';
        if (/^7b9/.test(suffix))     return 'dom7';
        if (/^7#9/.test(suffix))     return 'dom7';
        if (/^7b13/.test(suffix))    return 'dom7';
        if (/^7alt/.test(suffix))    return 'dom7';
        if (/^13/.test(suffix))      return 'dom7';
        if (/^9/.test(suffix))       return 'dom7';
        if (/^11/.test(suffix))      return 'dom7';
        if (/^7/.test(suffix))       return 'dom7';
        if (/^6/.test(suffix))       return 'maj6';
        if (/^o7/.test(suffix))      return 'o7';
        if (/^dim7/.test(suffix))    return 'o7';
        if (/^dim/.test(suffix))     return 'dim';
        if (/^aug7/.test(suffix))    return 'aug7';
        if (/^aug/.test(suffix))     return 'aug';
        if (/^sus4/.test(suffix))    return 'sus4';
        if (/^sus2/.test(suffix))    return 'sus2';
        return suffix || 'maj';
    }

    /**
     * Derive quality from all available chord/voicing data, trying multiple sources.
     * Priority: .quality → infer from numeral → infer from name → infer from interval labels
     */
    function resolveQuality(obj) {
        if (!obj) return '';
        if (obj.quality) return obj.quality;
        var q = inferQuality(obj.numeral);
        if (q) return q;
        q = inferQualityFromName(obj.name);
        if (q) return q;
        // Last resort: derive from interval_labels on voicing objects
        // e.g. "R,3,b5,b7" → m7b5; "R,3,5,b7" → dominant
        return inferQualityFromIntervals(obj.interval_labels);
    }

    /**
     * Infer chord quality from a comma-separated interval label string.
     * This is a last resort — interval_labels come from voicing objects
     * when neither .quality, .numeral, nor .name are populated.
     */
    function inferQualityFromIntervals(labels) {
        if (!labels) return '';
        var arr = labels.split(',');
        var has = {};
        arr.forEach(function (l) { has[l.trim()] = true; });

        var has3  = has['3'];
        var hasb3 = has['b3'];
        var hasb7 = has['b7'];
        var hasMaj7 = has['maj7'];
        var hasb5 = has['b5'];
        var has5  = has['5'];

        // m7b5: b3 + b5 + b7
        if (hasb3 && hasb5 && hasb7) return 'm7b5';
        // minor with maj7
        if (hasb3 && hasMaj7) return 'mMaj7';
        // minor: b3 + b7 (with natural or no 5th)
        if (hasb3 && hasb7 && !hasb5) return 'm7';
        // dominant: 3 + b7
        if (has3 && hasb7) return 'dom7';
        // major 7: 3 + maj7
        if (has3 && hasMaj7) return 'maj7';
        // plain minor
        if (hasb3) return 'min';
        // plain major
        if (has3) return 'maj';
        return '';
    }

    /**
     * Returns true for any minor-family chord quality.
     * Based on the exact quality strings produced by sbn_pb_suffix_to_quality().
     * Used to restrict extension resolution rules when the target is minor:
     * only b9 (not nat.9 or #9) and b13 (not nat.13 or 6) are valid altered
     * extensions when resolving a dominant chord to a minor tonic.
     */
    var MINOR_QUALITIES = { 'm7': 1, 'm6': 1, 'm7b5': 1, 'mMaj7': 1, 'min': 1, 'mMin7': 1 };
    function isMinorQuality(quality) {
        return !!MINOR_QUALITIES[quality];
    }

    function scoreVL(a, b, level) {
        if (level === undefined) level = 1;

        var pitchesA = buildPitchMap(a);
        var pitchesB = buildPitchMap(b);

        // Fallback if diagram_data isn't available
        if (!pitchesA.length || !pitchesB.length) {
            return averageFret(a) && averageFret(b)
                ? Math.round(Math.abs(averageFret(a) - averageFret(b)) * 100) / 100
                : 5;
        }

        var score = 0;

        // ── Derive chord qualities with robust fallback ─────────────
        // Uses .quality → numeral suffix → chord name in that order.
        var qualityA = resolveQuality(a);
        var qualityB = resolveQuality(b);

        var sourceIsDom    = isDomQuality(qualityA);
        var targetIsMinor  = isMinorQuality(qualityB);
        var targetIsMaj    = isTonicMajQuality(qualityB);
        var sourceIsHalfDim = isHalfDimQuality(qualityA);

        // ── 1. GUIDE TONE RESOLUTION (using actual register/MIDI pitch) ─
        //
        // LEVEL I (basic):
        //   b7 → 3/b3 of next chord (half step in register)
        //   3  → R or b7 of next chord
        //
        // LEVEL II (jazz extensions):
        //   b7 → 3/b3 (same as Level I)
        //   3 of dom7 → maj7 of target (stays on same note, e.g. G7:B → Cmaj7:B)
        //   3 of dom7 → 9 of target (e.g. G7:B → Cmaj7:D)
        //   9/b9 → 13/b13/9/R/5 of target
        //     → when target is MINOR: only b9 is a valid source (not nat.9 or #9),
        //       and only b13 is a valid target sixth (not nat.13 or 6)
        //   #11 → 5 or 3 of target (lydian dominant resolution)
        //   5 of m7 → R of dom7 (common tone in IIm7→V7)

        var seventhsA = pitchesA.filter(function (p) { return SEVENTH_LABELS[p.label]; });
        var thirdsA   = pitchesA.filter(function (p) { return THIRD_LABELS[p.label]; });
        var thirdsB   = pitchesB.filter(function (p) { return THIRD_LABELS[p.label]; });
        var rootsB    = pitchesB.filter(function (p) { return ROOT_LABELS[p.label]; });
        var seventhsB = pitchesB.filter(function (p) { return SEVENTH_LABELS[p.label]; });

        // Level II additional targets/sources
        var maj7sB   = level >= 2 ? pitchesB.filter(function (p) { return MAJ7_LABELS[p.label]; }) : [];
        var ninthsB  = level >= 2 ? pitchesB.filter(function (p) { return NINTH_LABELS[p.label]; }) : [];
        var fifthsB  = level >= 2 ? pitchesB.filter(function (p) { return FIFTH_LABELS[p.label]; }) : [];
        var fifthsA  = level >= 2 ? pitchesA.filter(function (p) { return FIFTH_LABELS[p.label]; }) : [];

        // #11 sources and targets
        var eleventhsA = level >= 2 ? pitchesA.filter(function (p) { return ELEVENTH_LABELS[p.label]; }) : [];

        // When target is minor: only b9 sources and b13 targets are harmonically valid
        var ninthsA = level >= 2
            ? pitchesA.filter(function (p) {
                if (!NINTH_LABELS[p.label]) return false;
                return targetIsMinor ? p.label === 'b9' : true;
            })
            : [];
        var sixthsB = level >= 2
            ? pitchesB.filter(function (p) {
                if (!SIXTH_LABELS[p.label]) return false;
                return targetIsMinor ? p.label === 'b13' : true;
            })
            : [];

        // ── Wrong-alteration collection ─────────────────────────────────
        // nat-13 or nat-9 on the dominant source when resolving to minor:
        // these clash directly with the minor tonic (e.g. E or A over G7 → Cm).
        // Wrong-alteration detection: nat-13 or nat-9 on the dominant source
        // when resolving to minor. These clash regardless of extension level —
        // a voicing might carry extensions even at level 1.
        var sixthsA_wrongForMinor = targetIsMinor
            ? pitchesA.filter(function (p) { return p.label === '13' || p.label === '6'; })
            : [];
        var ninthsA_wrongForMinor = targetIsMinor
            ? pitchesA.filter(function (p) { return p.label === '9' || p.label === '#9'; })
            : [];

        // #11 on a non-lydian major tonic (e.g. Cmaj7 with #11 when functioning as I):
        // In standard progressions this clashes unless explicitly lydian.
        // Check at all levels — voicings may carry extensions regardless of level setting.
        var eleventhsB_wrongForMaj = targetIsMaj
            ? pitchesB.filter(function (p) { return p.label === '#11'; })
            : [];

        var resolutionPenalties = [];

        // b7 → 3/b3: ideal = 1-2 semitones in actual register
        seventhsA.forEach(function (seventh) {
            var bestDist = 99;
            thirdsB.forEach(function (third) {
                var d = Math.abs(seventh.midi - third.midi);
                if (d < bestDist) bestDist = d;
            });
            resolutionPenalties.push(distToPenalty(bestDist));
        });

        // 3 → resolution targets (depends on level)
        thirdsA.forEach(function (third) {
            var bestDist = 99;
            // Level I: 3 → R, 3 → b7 of next
            rootsB.forEach(function (t) {
                var d = Math.abs(third.midi - t.midi);
                if (d < bestDist) bestDist = d;
            });
            seventhsB.forEach(function (t) {
                var d = Math.abs(third.midi - t.midi);
                if (d < bestDist) bestDist = d;
            });
            // Level II: 3 → maj7 of target (same note stays, e.g. B in G7 → B in Cmaj7)
            maj7sB.forEach(function (t) {
                var d = Math.abs(third.midi - t.midi);
                if (d < bestDist) bestDist = d;
            });
            // Level II: 3 → 9 of target (e.g. B in G7 → D in Cmaj7)
            ninthsB.forEach(function (t) {
                var d = Math.abs(third.midi - t.midi);
                if (d < bestDist) bestDist = d;
            });
            resolutionPenalties.push(distToPenalty(bestDist));
        });

        // Level II extensions
        if (level >= 2) {
            // 9/b9 → 13/b13, 9, R, 5
            // b9 in particular resolves by half step to the 5th of target
            ninthsA.forEach(function (ninth) {
                var bestDist = 99;
                sixthsB.forEach(function (t) {
                    var d = Math.abs(ninth.midi - t.midi);
                    if (d < bestDist) bestDist = d;
                });
                ninthsB.forEach(function (t) {
                    var d = Math.abs(ninth.midi - t.midi);
                    if (d < bestDist) bestDist = d;
                });
                rootsB.forEach(function (t) {
                    var d = Math.abs(ninth.midi - t.midi);
                    if (d < bestDist) bestDist = d;
                });
                fifthsB.forEach(function (t) {
                    var d = Math.abs(ninth.midi - t.midi);
                    if (d < bestDist) bestDist = d;
                });
                if (bestDist < 99) {
                    resolutionPenalties.push(distToPenalty(bestDist));
                }
            });

            // #11 → 5, 3, or 9 of target (lydian dominant resolution)
            // #11 resolves down to 3 or up to 5 typically
            eleventhsA.forEach(function (eleventh) {
                var bestDist = 99;
                fifthsB.forEach(function (t) {
                    var d = Math.abs(eleventh.midi - t.midi);
                    if (d < bestDist) bestDist = d;
                });
                thirdsB.forEach(function (t) {
                    var d = Math.abs(eleventh.midi - t.midi);
                    if (d < bestDist) bestDist = d;
                });
                ninthsB.forEach(function (t) {
                    var d = Math.abs(eleventh.midi - t.midi);
                    if (d < bestDist) bestDist = d;
                });
                if (bestDist < 99) {
                    resolutionPenalties.push(distToPenalty(bestDist));
                }
            });

            // 5 of m7 → R of dom7 (common tone check)
            fifthsA.forEach(function (fifth) {
                var bestDist = 99;
                rootsB.forEach(function (t) {
                    var d = Math.abs(fifth.midi - t.midi);
                    if (d < bestDist) bestDist = d;
                });
                // 5 → 5 of next (common tone)
                fifthsB.forEach(function (t) {
                    var d = Math.abs(fifth.midi - t.midi);
                    if (d < bestDist) bestDist = d;
                });
                if (bestDist < 99) {
                    resolutionPenalties.push(distToPenalty(bestDist));
                }
            });
        }

        if (resolutionPenalties.length > 0) {
            var totalRes = 0;
            resolutionPenalties.forEach(function (p) { totalRes += p; });
            score += totalRes;
        }

        // ── 1b. Wrong-alteration / clash penalties ──────────────────────

        // Dom7 → minor: nat-13 and nat-9/#9 on the source clash with the
        // minor tonic. These are harmonically WRONG, not just suboptimal.
        // Penalty must be high enough to override any VL score advantage.
        sixthsA_wrongForMinor.forEach(function () { score += 10; });
        ninthsA_wrongForMinor.forEach(function () { score += 10; });

        // #11 on a major tonic target: penalise unless the chord itself is
        // explicitly marked as lydian (maj7#11). Standard Imaj7 shouldn't
        // carry a #11 in an educational context.
        eleventhsB_wrongForMaj.forEach(function () { score += 10; });

        // m7b5 with a natural 9: the natural 9 on a half-diminished chord
        // is extremely rare in jazz and creates a confusing sound for
        // students. Heavily penalise to exclude from educational suggestions.
        if (sourceIsHalfDim) {
            pitchesA.forEach(function (p) {
                if (p.label === '9') score += 8;
            });
        }
        // Also penalise natural 9 on m7b5 when it's the TARGET
        // (e.g. coming from a preceding chord into a m7b5(9) voicing)
        if (isHalfDimQuality(qualityB)) {
            pitchesB.forEach(function (p) {
                if (p.label === '9') score += 8;
            });
        }

        // ── 2. General guide tone proximity (fallback for non-functional harmony)
        if (resolutionPenalties.length === 0) {
            var guidesA = pitchesA.filter(function (p) { return GUIDE_LABELS[p.label]; });
            var guidesB = pitchesB.filter(function (p) { return GUIDE_LABELS[p.label]; });

            if (guidesA.length && guidesB.length) {
                var guideScore = 0;
                guidesA.forEach(function (ga) {
                    var minDist = 99;
                    guidesB.forEach(function (gb) {
                        var d = Math.abs(ga.midi - gb.midi);
                        if (d < minDist) minDist = d;
                    });
                    guideScore += minDist;
                });
                score += (guideScore / guidesA.length) * 1.5;
            }
        }

        // ── 3. Common tones on same string (reduces score) ──────────────
        var commonSameString = 0;
        pitchesA.forEach(function (pa) {
            pitchesB.forEach(function (pb) {
                if (pa.string === pb.string && pa.midi === pb.midi) commonSameString++;
            });
        });

        var semiArrA = pitchesA.map(function (p) { return p.midi % 12; });
        var semiArrB = pitchesB.map(function (p) { return p.midi % 12; });
        var pcSetA = unique(semiArrA);
        var pcSetB = unique(semiArrB);
        var commonAny = pcSetA.filter(function (n) { return pcSetB.indexOf(n) !== -1; }).length;

        score = Math.max(0, score - commonSameString * 1.5 - commonAny * 0.3);

        // ── 4. Fret distance ────────────────────────────────────────────
        // Reduced weight (was 0.7) and capped to prevent position jumps from
        // dominating the score over voice leading quality.
        var avgA = averageFret(a);
        var avgB = averageFret(b);
        var fretDist = Math.abs(avgA - avgB);
        score += Math.min(fretDist * 0.4, 3.0);

        // ── 5. String set continuity ────────────────────────────────────
        // Upper voices (all strings above the bass) should stay on the same
        // set of strings. The bass is exempt.  Matching = bonus; jumping = penalty.
        var strSetA = upperStringSet(a);
        var strSetB = upperStringSet(b);
        if (strSetA && strSetB) {
            if (strSetA === strSetB) {
                score = Math.max(0, score - 1.5); // same-set bonus
            } else {
                score += 3; // string-jump penalty — real cost, not just a tiebreaker
            }
        } else if (a.root_string && b.root_string && a.root_string === b.root_string) {
            // Fallback: use root_string when diagram_data isn't parsed yet
            score = Math.max(0, score - 1);
        }

        return Math.round(score * 100) / 100;
    }

    /**
     * Convert an absolute MIDI distance to a penalty value.
     * Shared by all resolution checks.
     */
    function distToPenalty(dist) {
        if (dist <= 2) return 0;       // half/whole step in register
        if (dist <= 4) return 2;       // close
        if (dist <= 7) return 4;       // within a fifth
        return 5 + Math.floor((dist - 7) / 3);
    }

    function averageFret(voicing) {
        var dd = voicing.diagram_data;
        if (typeof dd === 'string') { try { dd = JSON.parse(dd); } catch (e) { return 0; } }
        if (!dd || !dd.positions) return 0;
        var sum = 0, count = 0;
        dd.positions.forEach(function (p) {
            if (p.fret > 0) { sum += p.fret; count++; }
        });
        return count ? sum / count : 0;
    }

    function unique(arr) {
        var seen = {};
        return arr.filter(function (n) {
            if (seen[n]) return false;
            seen[n] = true;
            return true;
        });
    }

    // =========================================================================
    // VOICING TYPE HELPERS
    // =========================================================================

    /**
     * Map a voicing_category to a compatibility group.
     *
     * Groups:
     *  'drop'   — drop2 and drop3 mix freely with each other
     *  'shell'  — shell voicings only mix with shell
     *  'closed' — closed voicings only mix with closed
     *  anything else — treated as its own isolated group
     *
     * 'open' is intentionally excluded from all groups — the builder should
     * never auto-select open voicings (per design rule).
     */
    function voicingGroup(voicing_category) {
        if (voicing_category === 'drop2' || voicing_category === 'drop3') return 'drop';
        if (voicing_category === 'shell')  return 'shell';
        if (voicing_category === 'closed') return 'closed';
        return voicing_category || 'other'; // rootless, custom, etc. are own groups
    }

    /**
     * Return true if the group is one of the three structured voicing types
     * (drop, shell, closed). Rootless, custom, and other unclassified types
     * are not structured and should not be auto-selected unless nothing else
     * is available.
     */
    function isStructuredGroup(group) {
        return group === 'drop' || group === 'shell' || group === 'closed';
    }

    /**
     * Return true if a voicing is an "open" voicing — these are excluded from
     * auto-suggest entirely because they tend to jump between string sets
     * unpredictably and are meant for specific textural contexts.
     */
    function isOpenVoicing(v) {
        return (v.voicing_category || '') === 'open';
    }

    /**
     * Derive the "upper string set" used by a voicing.
     *
     * We look at the sounding strings (non-muted, non-bass) and return
     * the set of strings that carry the inner / upper voices.
     *
     * Guitar string numbering (our convention): 1=low E … 6=high E.
     * "Bass" = lowest sounding string.
     * "Upper voices" = all sounding strings above the bass.
     *
     * We represent the set as a sorted, comma-joined string of string numbers
     * so it can be compared with ===.
     * e.g. a drop2 on strings 1-2-3-4 → bass=1, upper set = "2,3,4"
     *      a drop2 on strings 2-3-4-5 → bass=2, upper set = "3,4,5"
     */
    function upperStringSet(voicing) {
        var dd = voicing.diagram_data;
        if (typeof dd === 'string') { try { dd = JSON.parse(dd); } catch (e) { return ''; } }
        if (!dd || !dd.positions) return '';

        var sounding = [];
        dd.positions.forEach(function (pos) {
            if (pos.fret !== null && pos.fret !== 'x' && pos.fret !== -1) {
                sounding.push(pos.string);
            }
        });

        if (sounding.length < 2) return sounding.join(',');

        // Sort ascending (lowest string number = lowest pitch)
        sounding.sort(function (a, b) { return a - b; });

        // Remove bass (lowest) — it's allowed to move
        var upper = sounding.slice(1);
        return upper.join(',');
    }

    // =========================================================================
    // AUTO-SUGGEST / AUTO-COMPLETE
    // =========================================================================

    /**
     * Count how many slots already have a user selection.
     */
    function countSelections(state) {
        var n = 0;
        state.selections.forEach(function (s) { if (s) n++; });
        return n;
    }

    /**
     * Smart suggest — behaviour depends on current state:
     *
     * 0 selections → full auto-suggest (pick everything from scratch)
     * 1+ selections → auto-complete (keep existing selections, fill the rest)
     *
     * The algorithm works bidirectionally from each "anchor" (a user-selected
     * voicing), propagating forward and backward.
     *
     * Three layered constraints (applied in order, each falling back to the next):
     *
     *  1. VOICING GROUP COHERENCE — open voicings are never auto-selected.
     *     Drop 2 and Drop 3 mix freely; Shell and Closed stay with their own kind.
     *     The group is locked to whichever type the first anchor (or first pick)
     *     belongs to.  If the user has set a voicing_category filter, that takes
     *     precedence and the group is derived from it.
     *
     *  2. UPPER STRING SET CONTINUITY — the inner/upper voices (all strings above
     *     the bass) must stay on the same set of strings. The bass is exempt and
     *     can move to an adjacent string. This is a hard preference: we only break
     *     it if no same-set candidates exist within the group.
     *
     *  3. VOICE LEADING SCORE — once the candidate pool has been narrowed by the
     *     above two constraints, pick the voicing with the lowest VL score
     *     (best guide-tone resolution, fewest fret jumps).
     */
    function autoSuggest(state) {
        if (!state.chords.length) return;

        var n = state.chords.length;
        var result = state.selections.slice(); // preserve existing selections

        // Always derive vlLevel live from settings
        state.vlLevel = state.settings.extensions ? 2 : 1;

        // ── Determine the locked voicing group ──────────────────────────
        // Priority: settings.style → user's active picker filter → first existing anchor → picked below
        var lockedGroup = null;
        if (state.settings.style) {
            lockedGroup = state.settings.style;
        } else if (state.filters.voicing_category) {
            lockedGroup = voicingGroup(state.filters.voicing_category);
        } else {
            for (var k = 0; k < result.length; k++) {
                if (result[k]) { lockedGroup = voicingGroup(result[k].voicing_category); break; }
            }
        }

        // rootOnly pre-filter
        var rootOnlyFilter = state.settings.rootOnly
            ? function (v) { return (v.inversion || 'root') === 'root'; }
            : function () { return true; };

        // Extension preference filter — when extensions ON, prefer voicings that
        // carry 9ths or 13ths; fall back to all voicings if none qualify.
        var extFilter = state.settings.extensions
            ? function (v) { return v.extensions && v.extensions.trim() !== ''; }
            : function () { return true; };

        /**
         * Harmonic suitability filter — removes voicings with extensions that
         * are inappropriate for the chord's quality in an educational context.
         *
         * Currently excludes:
         *  - Natural 9 on m7b5 (extremely rare, confusing for students)
         *  - Natural 13 on dom7 when the *next* chord is minor (clash)
         *
         * This is applied per-chord (needs chord context), so it's returned
         * as a factory that takes the chord index.
         */
        function makeHarmonyFilter(chordIdx) {
            var chord = state.chords[chordIdx];
            var quality = resolveQuality(chord);
            if (!quality) return function () { return true; };

            // The raw numeral as stored in the progression (e.g. "Imaj7", "V7", "I")
            var numeral = (chord && chord.numeral) || '';

            // Check if the NEXT chord is minor (for dom→minor clash filtering)
            var nextChord = state.chords[chordIdx + 1];
            var nextQuality = resolveQuality(nextChord);
            var nextIsMinor = isMinorQuality(nextQuality);

            // Does the numeral explicitly request #11? (e.g. "Imaj7#11", "IV7#11")
            var numeralHasSharp11 = numeral.indexOf('#11') !== -1;

            return function (v) {
                var ext = (v.extensions || '').trim();
                // m7b5: exclude natural 9
                if (isHalfDimQuality(quality)) {
                    if (ext === '9' || ext.indexOf('9') !== -1) {
                        var labels = (v.interval_labels || '').split(',');
                        for (var li = 0; li < labels.length; li++) {
                            if (labels[li] === '9') return false;
                        }
                    }
                }
                // dom7 → minor: exclude voicings with natural 13 or natural 9
                if (isDomQuality(quality) && nextIsMinor) {
                    var dLabels = (v.interval_labels || '').split(',');
                    for (var di = 0; di < dLabels.length; di++) {
                        if (dLabels[di] === '13' || dLabels[di] === '6') return false;
                        if (dLabels[di] === '9')  return false;
                        if (dLabels[di] === '#9') return false;
                    }
                }
                // Tonic maj7: exclude #11 voicings UNLESS the numeral explicitly
                // requests it. In standard jazz harmony, #11 on a I chord is lydian
                // — valid but not the default. For educational suggestions we want
                // plain maj7/maj9 unless the user specifically wrote "Imaj7#11".
                if (isTonicMajQuality(quality) && !numeralHasSharp11) {
                    var tLabels = (v.interval_labels || '').split(',');
                    for (var ti = 0; ti < tLabels.length; ti++) {
                        if (tLabels[ti] === '#11') return false;
                    }
                    // Also check extensions field (e.g. extensions = "#11")
                    if (ext === '#11' || ext.indexOf('#11') !== -1) return false;
                }
                return true;
            };
        }

        function withExtFallback(pool) {
            if (!state.settings.extensions) return pool;
            var withExt = pool.filter(extFilter);
            return withExt.length ? withExt : pool;
        }

        if (countSelections(state) === 0) {
            // ── Full suggest: pick a sensible starting voicing ───────────
            var firstChord = state.chords[0];

            var candidates;
            if (lockedGroup) {
                candidates = (firstChord.voicings || []).filter(function (v) {
                    return !isOpenVoicing(v) && rootOnlyFilter(v) && voicingGroup(v.voicing_category) === lockedGroup;
                });
            } else {
                candidates = (firstChord.voicings || []).filter(function (v) {
                    return !isOpenVoicing(v) && rootOnlyFilter(v) && isStructuredGroup(voicingGroup(v.voicing_category));
                });
            }
            if (!candidates.length) {
                candidates = (firstChord.voicings || []).filter(function (v) { return !isOpenVoicing(v) && rootOnlyFilter(v); });
            }
            if (!candidates.length) candidates = (firstChord.voicings || []).filter(rootOnlyFilter);
            if (!candidates.length) candidates = firstChord.voicings || [];

            // Apply extension preference within the winning pool
            candidates = withExtFallback(candidates);

            // Apply harmonic suitability filter for chord 0
            var hf0 = makeHarmonyFilter(0);
            var hfCandidates = candidates.filter(hf0);
            if (hfCandidates.length) candidates = hfCandidates;

            if (candidates.length) {
                var best = candidates[0];
                var bestDist = 999;
                candidates.forEach(function (v) {
                    var avg = averageFret(v);
                    var dist = Math.abs(avg - 5);
                    if (dist < bestDist) { bestDist = dist; best = v; }
                });
                result[0] = best;
                if (!lockedGroup) lockedGroup = voicingGroup(best.voicing_category);
            }
        }

        // ── Forward pass ────────────────────────────────────────────────
        for (var i = 0; i < n; i++) {
            if (!result[i]) continue;
            if (!lockedGroup) lockedGroup = voicingGroup(result[i].voicing_category);
            for (var j = i + 1; j < n; j++) {
                if (result[j]) break;
                var prev = result[j - 1];
                if (!prev) break;
                var pool = state.chords[j].voicings || [];
                if (!pool.length) { result[j] = null; continue; }
                // Pre-filter pool with harmony filter for this chord slot
                var hfJ = makeHarmonyFilter(j);
                var hfPool = pool.filter(hfJ);
                result[j] = pickBestVL(prev, hfPool.length ? hfPool : pool, state.vlLevel, lockedGroup, rootOnlyFilter, extFilter);
            }
        }

        // ── Backward pass ───────────────────────────────────────────────
        for (var i = n - 1; i >= 0; i--) {
            if (!result[i]) continue;
            if (!lockedGroup) lockedGroup = voicingGroup(result[i].voicing_category);
            for (var j = i - 1; j >= 0; j--) {
                if (result[j]) break;
                var next = result[j + 1];
                if (!next) break;
                var pool = state.chords[j].voicings || [];
                if (!pool.length) { result[j] = null; continue; }
                var hfJ = makeHarmonyFilter(j);
                var hfPool = pool.filter(hfJ);
                result[j] = pickBestVL(next, hfPool.length ? hfPool : pool, state.vlLevel, lockedGroup, rootOnlyFilter, extFilter);
            }
        }

        // ── Fill any remaining gaps ──────────────────────────────────────
        for (var i = 0; i < n; i++) {
            if (!result[i]) {
                var pool = state.chords[i].voicings || [];
                if (pool.length) {
                    var hfI = makeHarmonyFilter(i);
                    var hfPool = pool.filter(hfI);
                    var usePool = hfPool.length ? hfPool : pool;
                    var anchor = i > 0 ? result[i - 1] : null;
                    if (anchor) {
                        result[i] = pickBestVL(anchor, usePool, state.vlLevel, lockedGroup, rootOnlyFilter, extFilter);
                    } else {
                        var fallback = usePool.filter(function (v) {
                            return !isOpenVoicing(v) && rootOnlyFilter(v) && (!lockedGroup || voicingGroup(v.voicing_category) === lockedGroup);
                        });
                        fallback = withExtFallback(fallback.length ? fallback : usePool);
                        result[i] = fallback.length ? fallback[0] : usePool[0];
                    }
                }
            }
        }

        state.selections = result;
        computeVoiceLeading(state);
        state.activeSlot = -1;
        render(state);
    }

    /**
     * Pick the voicing from candidates with the best VL score to anchor,
     * respecting voicing group coherence, upper string set continuity,
     * and (when extensions mode is on) preferring voicings with 9ths/13ths.
     *
     * Candidate filtering (each level falls back to the next if empty):
     *   Level A: same group AND same upper string set
     *   Level B: same group, any upper string set
     *   Level C: any structured non-open voicing
     *   Level D: anything (last resort)
     *
     * Within the winning pool, if extFilter is active, prefer voicings
     * that pass it — but fall back to the full pool if none do.
     * Then pick by lowest VL score.
     */
    function pickBestVL(anchor, candidates, level, group, rootOnlyFilter, extFilter) {
        if (!rootOnlyFilter) rootOnlyFilter = function () { return true; };
        if (!extFilter)      extFilter      = function () { return true; };
        var anchorSet = upperStringSet(anchor);

        var poolA = candidates.filter(function (v) {
            if (isOpenVoicing(v)) return false;
            if (!rootOnlyFilter(v)) return false;
            if (group && voicingGroup(v.voicing_category) !== group) return false;
            return upperStringSet(v) === anchorSet;
        });

        var poolB = candidates.filter(function (v) {
            if (isOpenVoicing(v)) return false;
            if (!rootOnlyFilter(v)) return false;
            if (group && voicingGroup(v.voicing_category) !== group) return false;
            return true;
        });

        var poolC = candidates.filter(function (v) {
            return !isOpenVoicing(v) && rootOnlyFilter(v) && isStructuredGroup(voicingGroup(v.voicing_category));
        });
        var poolCprime = candidates.filter(function (v) { return !isOpenVoicing(v) && rootOnlyFilter(v); });

        // Pick the first non-empty pool (A → B → C → C' → all)
        var pool = poolA.length ? poolA
                 : poolB.length ? poolB
                 : poolC.length ? poolC
                 : poolCprime.length ? poolCprime
                 : candidates;

        // Within the winning pool, apply extension preference (soft — falls back if none qualify)
        var extPool = pool.filter(extFilter);
        if (extPool.length) pool = extPool;

        // ── Score within the chosen pool ────────────────────────────────
        var best = pool[0];
        var bestScore = 999;
        pool.forEach(function (v) {
            var s = scoreVL(anchor, v, level || 1);
            if (s < bestScore) { bestScore = s; best = v; }
        });

        // ── Cross-pool rescue ────────────────────────────────────────────
        // If a voicing from a lower-priority pool (e.g. different group or string set)
        // scores significantly better than the pool-A/B winner, break the group lock.
        // This prevents a rigid group constraint from forcing a position jump when a
        // nearby voicing in a different category (e.g. drop3 vs drop2) is far superior.
        var RESCUE_THRESHOLD = 4.0;
        var rescuePool = candidates.filter(function (v) {
            return !isOpenVoicing(v) && pool.indexOf(v) === -1;
        });
        if (rescuePool.length && bestScore > RESCUE_THRESHOLD) {
            var rescueExtPool = rescuePool.filter(extFilter);
            if (rescueExtPool.length) rescuePool = rescueExtPool;
            rescuePool.forEach(function (v) {
                var s = scoreVL(anchor, v, level || 1);
                if (s < bestScore - RESCUE_THRESHOLD) { bestScore = s; best = v; }
            });
        }

        return best;
    }

    /**
     * Clear all selections.
     */
    function clearSelections(state) {
        state.selections = new Array(state.chords.length).fill(null);
        state.vlScores = [];
        state.activeSlot = -1;
        render(state);
    }

    // =========================================================================
    // RENDERING
    // =========================================================================

    function render(state) {
        var c = state.container;
        if (!c) return;

        if (state.loading) {
            c.innerHTML = '<div class="sbn-pb-loading"><span class="sbn-pb-spinner"></span> Loading voicings…</div>';
            return;
        }

        var html = '';

        // ── Header: compact single bar ──────────────────────────────────
        html += '<div class="sbn-pb-header">';

        // Row 1: key + style seg + toggles + suggest/clear — all inline
        html += '<div class="sbn-pb-key-row">';
        html += '<label class="sbn-pb-key-label">Key</label>';
        html += '<select class="sbn-pb-key-select" data-action="change-key">';
        var allKeys = ['C','Db','D','Eb','E','F','F#','G','Ab','A','Bb','B'];
        allKeys.forEach(function (k) {
            html += '<option value="' + k + '"' + (k === state.key ? ' selected' : '') + '>' + k + '</option>';
        });
        html += '</select>';

        // Style segmented control
        var st = state.settings;
        html += '<div class="sbn-pb-seg" role="group" aria-label="Voicing style">';
        [
            { val: '',       label: 'Any'    },
            { val: 'shell',  label: 'Shell'  },
            { val: 'drop',   label: 'Drop'   },
            { val: 'closed', label: 'Closed' },
        ].forEach(function (opt) {
            var active = st.style === opt.val;
            html += '<button class="sbn-pb-seg-btn' + (active ? ' is-active' : '') + '"'
                  + ' data-action="set-style" data-style="' + esc(opt.val) + '">'
                  + opt.label + '</button>';
        });
        html += '</div>';

        // Pill toggles — plain-language labels
        html += '<button class="sbn-pb-toggle' + (st.extensions ? ' is-active' : '') + '"'
              + ' data-action="toggle-ext" title="Include extension voicings (9ths, 11ths, 13ths)">Extensions</button>';
        var invOn = !st.rootOnly;
        html += '<button class="sbn-pb-toggle' + (invOn ? ' is-active' : '') + '"'
              + ' data-action="toggle-inv" title="Allow inverted voicings">Inversions</button>';

        // Suggest + Clear
        var numSelected = countSelections(state);
        var suggestLabel = numSelected === 0 ? '✨ Suggest' : '✨ Complete';
        var suggestTitle = numSelected === 0
            ? 'Auto-pick voicings with best voice leading'
            : 'Auto-complete remaining slots from your selections';
        html += '<button class="sbn-pb-btn sbn-pb-btn-suggest" data-action="auto-suggest" title="' + esc(suggestTitle) + '">' + suggestLabel + '</button>';
        if (numSelected > 0) {
            html += '<button class="sbn-pb-btn sbn-pb-btn-clear" data-action="clear" title="Clear all selections">Clear</button>';
        }

        html += '</div>'; // .sbn-pb-key-row
        html += '</div>'; // .sbn-pb-header

        // ── Chord slots row ─────────────────────────────────────────────
        html += '<div class="sbn-pb-slots" data-count="' + state.chords.length + '">';
        state.chords.forEach(function (chord, idx) {
            var sel = state.selections[idx];
            var isActive = state.activeSlot === idx;
            var hasVoicings = chord.voicings && chord.voicings.length > 0;
            var vlBefore = idx > 0 ? state.vlScores[idx - 1] : null;

            // Voice leading connector
            if (idx > 0) {
                html += renderVLConnector(vlBefore);
            }

            html += '<div class="sbn-pb-slot' + (isActive ? ' is-active' : '') + (sel ? ' has-selection' : '') + '"'
                  + ' data-action="toggle-slot" data-slot="' + idx + '">';

            // Chord name — show slash notation when an inverted voicing is selected
            html += '<div class="sbn-pb-slot-name">';
            if (chord.error) {
                html += '<span class="sbn-pb-slot-error">' + esc(chord.numeral) + '</span>';
            } else {
                var displayName = sel ? voicingDisplayName(chord, sel) : (chord.name || chord.numeral);
                html += '<span class="sbn-pb-chord-name">' + esc(displayName) + '</span>';
            }
            html += '</div>';

            // Selected voicing mini diagram
            if (sel) {
                html += renderMiniDiagram(sel);
            } else if (hasVoicings) {
                html += '<div class="sbn-pb-slot-placeholder">'
                      + '<span class="sbn-pb-slot-placeholder-text">' + chord.voicings.length + ' voicing' + (chord.voicings.length !== 1 ? 's' : '') + '</span>'
                      + '</div>';
            } else {
                html += '<div class="sbn-pb-slot-placeholder sbn-pb-slot-none">'
                      + '<span class="sbn-pb-slot-placeholder-text">No voicings</span>'
                      + '</div>';
            }

            html += '</div>'; // .sbn-pb-slot
        });
        html += '</div>'; // .sbn-pb-slots

        // ── Voicing picker panel (expanded slot) ────────────────────────
        if (state.activeSlot >= 0 && state.activeSlot < state.chords.length) {
            html += renderVoicingPicker(state);
        }

        c.innerHTML = html;

        // Hydrate fretboards
        hydrateAllFretboards(c);

        // Wire events
        wireEvents(c, state);
    }

    function renderVLConnector(score) {
        if (score === null || score === undefined) {
            return '<div class="sbn-pb-vl-connector sbn-pb-vl-empty">'
                 + '<span class="sbn-pb-vl-arrow">→</span>'
                 + '</div>';
        }

        var cls = score <= 2 ? 'excellent' : score <= 5 ? 'good' : score <= 8 ? 'fair' : 'rough';
        return '<div class="sbn-pb-vl-connector sbn-pb-vl-' + cls + '">'
             + '<span class="sbn-pb-vl-arrow">→</span>'
             + '</div>';
    }

    function renderMiniDiagram(voicing) {
        // Use SbnChordCard if available, otherwise fall back to a text representation
        if (root.SbnChordCard && root.SbnChordCard.renderFretboard) {
            var dd = voicing.diagram_data;
            if (typeof dd === 'string') { try { dd = JSON.parse(dd); } catch (e) { dd = null; } }

            var html = '<div class="sbn-pb-mini-diagram">';
            html += '<div class="sbn-pb-mini-fretboard" data-fretboard="1"'
                  + ' data-diagram=\'' + esc(JSON.stringify(dd)) + '\''
                  + ' data-start-fret="' + (voicing.start_fret || 1) + '"'
                  + ' data-intervals="' + esc(voicing.interval_labels || '') + '"'
                  + ' data-notes="' + esc(voicing.notes || '') + '"'
                  + ' data-display-mode="fingerings">';
            html += '</div>';
            html += '</div>';
            return html;
        }

        // Text fallback
        return '<div class="sbn-pb-mini-diagram sbn-pb-mini-text">'
             + '<span>' + esc(VOICING_LABELS[voicing.voicing_category] || '') + '</span>'
             + '<span class="sbn-pb-mini-fret-info">' + (voicing.start_fret || 1) + 'fr</span>'
             + '</div>';
    }

    /**
     * Build a human-friendly display name for a voicing:
     * chord name + extensions suffix + optional slash-bass for inversions.
     * e.g. "Cmaj7", "Cmaj7(9)", "Cmaj7/E", "Cmaj7(9)/E"
     */
    function voicingDisplayName(chord, v) {
        var base = chord.name || chord.numeral || '';
        if (!v) return base;
        // Append extensions if present and not already in the chord name
        var ext = (v.extensions || '').trim();
        if (ext && base.indexOf(ext) === -1) {
            base = base + '(' + ext + ')';
        }
        // Append slash bass for inversions
        if (v.inversion && v.inversion !== 'root') {
            var notes = (v.notes || '').split(',').filter(Boolean);
            var bass = notes[0];
            if (bass) base = base + '/' + bass;
        }
        return base;
    }

    function renderVoicingPicker(state) {
        var idx = state.activeSlot;
        var chord = state.chords[idx];
        if (!chord) return '';

        var voicings = chord.voicings || [];
        var selectedId = state.selections[idx] ? state.selections[idx].id : null;

        // Determine prev voicing for VL scoring in picker
        var prevVoicing = idx > 0 ? state.selections[idx - 1] : null;

        // Group by voicing category
        var groups = {};
        voicings.forEach(function (v) {
            var cat = v.voicing_category || 'other';
            if (!groups[cat]) groups[cat] = [];

            // Compute VL score relative to previous selection
            if (prevVoicing) {
                v._vlScore = scoreVL(prevVoicing, v, state.vlLevel);
            }

            groups[cat].push(v);
        });

        // Sort within groups by VL score if available
        if (prevVoicing) {
            Object.keys(groups).forEach(function (cat) {
                groups[cat].sort(function (a, b) { return (a._vlScore || 0) - (b._vlScore || 0); });
            });
        }

        var html = '<div class="sbn-pb-picker">';
        html += '<div class="sbn-pb-picker-header">';
        html += '<span class="sbn-pb-picker-title">Choose voicing for <strong>' + esc(chord.name) + '</strong></span>';
        html += '<button class="sbn-pb-picker-close" data-action="close-picker" aria-label="Close">✕</button>';
        html += '</div>';

        // Voicing grid — no filter bar
        html += '<div class="sbn-pb-picker-grid">';

        Object.keys(groups).forEach(function (cat) {
            groups[cat].forEach(function (v) {
                var isSelected = v.id === selectedId;
                var dd = v.diagram_data;
                if (typeof dd === 'string') { try { dd = JSON.parse(dd); } catch (e) { dd = null; } }

                html += '<div class="sbn-pb-voicing-card' + (isSelected ? ' is-selected' : '') + '"'
                      + ' data-action="select-voicing" data-voicing-id="' + v.id + '"'
                      + ' data-slot="' + idx + '">';

                // Chord name above diagram (with slash bass + extensions)
                html += '<div class="sbn-pb-card-name">'
                      + esc(voicingDisplayName(chord, v))
                      + '</div>';

                // Fretboard
                html += '<div class="sbn-pb-card-fretboard" data-fretboard="1"'
                      + ' data-diagram=\'' + esc(JSON.stringify(dd)) + '\''
                      + ' data-start-fret="' + (v.start_fret || 1) + '"'
                      + ' data-intervals="' + esc(v.interval_labels || '') + '"'
                      + ' data-notes="' + esc(v.notes || '') + '"'
                      + ' data-display-mode="fingerings">';
                html += '</div>';

                html += '</div>'; // .sbn-pb-voicing-card
            });
        });

        html += '</div>'; // .sbn-pb-picker-grid
        html += '</div>'; // .sbn-pb-picker

        return html;
    }

    function computeTotalScore(state) {
        var scores = state.vlScores.filter(function (s) { return s !== null && s !== undefined; });
        if (!scores.length) return null;
        var total = 0;
        scores.forEach(function (s) { total += s; });
        return Math.round(total * 100) / 100;
    }

    function scoreLabel(total, numChords) {
        var avg = numChords > 1 ? total / (numChords - 1) : total;
        if (avg <= 2) return '(excellent)';
        if (avg <= 5) return '(good)';
        if (avg <= 8) return '(fair)';
        return '(rough)';
    }

    // =========================================================================
    // FRETBOARD HYDRATION
    // =========================================================================

    function hydrateAllFretboards(container) {
        if (!root.SbnChordCard || !root.SbnChordCard.renderFretboard) return;

        var fbs = container.querySelectorAll('[data-fretboard]');
        fbs.forEach(function (el) {
            var dd;
            try { dd = JSON.parse(el.getAttribute('data-diagram')); } catch (e) { return; }
            if (!dd) return;

            var data = {
                diagram: dd,
                startFret: parseInt(el.getAttribute('data-start-fret')) || 1,
                intervals: el.getAttribute('data-intervals') || '',
                notes: el.getAttribute('data-notes') || '',
                displayMode: el.getAttribute('data-display-mode') || 'fingerings'
            };

            el.innerHTML = root.SbnChordCard.renderFretboard(data);

            // Need to hydrate dots/barres after DOM is in place
            if (root.SbnChordCard.hydrateFretboard) {
                var fb = el.querySelector('.sbn-fretboard-mini');
                if (fb) root.SbnChordCard.hydrateFretboard(fb, data);
            }
        });
    }

    // =========================================================================
    // SETTINGS HELPERS
    // =========================================================================

    /**
     * Re-fetch the voicing pool (settings may have changed the server-side
     * category filter), then re-suggest if there are existing selections.
     * Saves and restores selections by voicing ID where possible.
     */
    function refetchAndResuggest(state) {
        var hadSelections = countSelections(state) > 0;
        var savedSelections = state.selections.slice();

        // fetchProgression resets selections — we'll restore below
        var origFetch = state.selections;
        fetchProgression(state); // will call render with loading state

        // Intercept the end of fetchProgression by waiting for chords to reload.
        // We do this by wrapping the post-fetch logic: fetchProgression sets
        // state.selections = new Array(n).fill(null) after loading, then calls render.
        // We poll until chords are populated, then re-suggest.
        var attempts = 0;
        function tryResuggest() {
            attempts++;
            if (state.loading && attempts < 60) {
                setTimeout(tryResuggest, 50);
                return;
            }
            if (!state.chords.length) return;

            if (hadSelections) {
                // Restore by ID match within new pool
                state.selections = state.chords.map(function (chord, i) {
                    var prev = savedSelections[i];
                    if (!prev) return null;
                    var match = (chord.voicings || []).find(function (v) {
                        return v.id === prev.id;
                    });
                    return match || null;
                });
                computeVoiceLeading(state);
                // Re-suggest only the empty slots
                autoSuggest(state);
            }
        }
        setTimeout(tryResuggest, 80);
    }

    // =========================================================================
    // EVENTS
    // =========================================================================

    function wireEvents(container, state) {
        // Key change
        var keySelect = container.querySelector('[data-action="change-key"]');
        if (keySelect) {
            keySelect.addEventListener('change', function () {
                state.key = this.value;
                fetchProgression(state);
            });
        }

        // Auto-suggest
        var suggestBtn = container.querySelector('[data-action="auto-suggest"]');
        if (suggestBtn) {
            suggestBtn.addEventListener('click', function () {
                autoSuggest(state);
            });
        }

        // Clear
        var clearBtn = container.querySelector('[data-action="clear"]');
        if (clearBtn) {
            clearBtn.addEventListener('click', function () {
                clearSelections(state);
            });
        }

        // Set voicing style (segmented control)
        container.querySelectorAll('[data-action="set-style"]').forEach(function (el) {
            el.addEventListener('click', function () {
                state.settings.style = this.getAttribute('data-style') || '';
                refetchAndResuggest(state);
            });
        });

        // Toggle extensions
        var extBtn = container.querySelector('[data-action="toggle-ext"]');
        if (extBtn) {
            extBtn.addEventListener('click', function () {
                state.settings.extensions = !state.settings.extensions;
                refetchAndResuggest(state);
            });
        }

        // Toggle inversions (Inv ON = inversions allowed = rootOnly FALSE)
        var invBtn = container.querySelector('[data-action="toggle-inv"]');
        if (invBtn) {
            invBtn.addEventListener('click', function () {
                state.settings.rootOnly = !state.settings.rootOnly;
                // No re-fetch needed — pool is the same, just filter changes client-side
                if (countSelections(state) > 0) {
                    autoSuggest(state);
                } else {
                    render(state);
                }
            });
        }

        // Toggle VL level (LEGACY — kept for back-compat, no longer rendered)
        var levelBtn = container.querySelector('[data-action="toggle-level"]');
        if (levelBtn) {
            levelBtn.addEventListener('click', function () {
                var oldLevel = state.vlLevel;
                state.vlLevel = state.vlLevel >= 2 ? 1 : 2;

                // Save current selections to restore after re-fetch
                var savedSelections = state.selections.slice();

                var data = new FormData();
                data.append('action', 'sbn_get_progression_voicings');
                data.append('nonce', state.nonce);
                data.append('numerals', state.numerals);
                data.append('key', state.key);
                data.append('vl_level', state.vlLevel);
                data.append('category', state.category);
                data.append('tonality', state.tonality);

                fetch(state.ajaxUrl, { method: 'POST', body: data })
                    .then(function (r) { return r.json(); })
                    .then(function (resp) {
                        if (resp.success && resp.data) {
                            state.chords = resp.data.chords || [];
                            // Restore selections: match by voicing ID
                            state.selections = state.chords.map(function (chord, i) {
                                var prev = savedSelections[i];
                                if (!prev) return null;
                                var match = (chord.voicings || []).find(function (v) {
                                    return v.id === prev.id;
                                });
                                return match || null;
                            });
                            computeVoiceLeading(state);
                            state.activeSlot = -1;
                            render(state);
                        }
                    });
            });
        }

        // Toggle slot
        container.querySelectorAll('[data-action="toggle-slot"]').forEach(function (el) {
            el.addEventListener('click', function () {
                var slot = parseInt(this.getAttribute('data-slot'));
                state.activeSlot = state.activeSlot === slot ? -1 : slot;
                state.filters = { voicing_category: '', root_string: '' };
                render(state);
            });
        });

        // Close picker
        var closeBtn = container.querySelector('[data-action="close-picker"]');
        if (closeBtn) {
            closeBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                state.activeSlot = -1;
                render(state);
            });
        }

        // Filter buttons
        container.querySelectorAll('[data-action="filter-cat"]').forEach(function (el) {
            el.addEventListener('click', function (e) {
                e.stopPropagation();
                state.filters.voicing_category = this.getAttribute('data-cat') || '';
                render(state);
            });
        });

        // Select voicing
        container.querySelectorAll('[data-action="select-voicing"]').forEach(function (el) {
            el.addEventListener('click', function (e) {
                e.stopPropagation();
                var slot = parseInt(this.getAttribute('data-slot'));
                var voicingId = parseInt(this.getAttribute('data-voicing-id'));
                var chord = state.chords[slot];
                if (!chord) return;

                var voicing = null;
                (chord.voicings || []).forEach(function (v) {
                    if (v.id === voicingId) voicing = v;
                });

                if (voicing) {
                    state.selections[slot] = voicing;
                    computeVoiceLeading(state);
                    state.activeSlot = -1; // close picker

                    // Dispatch custom event so host pages can react
                    state.container.dispatchEvent(new CustomEvent('sbn-pb-selection', {
                        detail: { slot: slot, voicing: voicing, selections: state.selections }
                    }));

                    render(state);
                }
            });
        });
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    function esc(str) {
        if (!str) return '';
        return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    // =========================================================================
    // EXPORT
    // =========================================================================

    root.SbnProgressionBuilder = {
        init: init,
    };

})(window);
