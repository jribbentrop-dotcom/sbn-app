/**
 * Guide tone resolution helpers for ChordProgressionViewer.
 *
 * Two modes:
 *
 * 1. findResolutionPairsFromFired(mapA, mapB, firedIds) — authoritative.
 *    Uses the builder's named resolution IDs (from fired_resolutions on each
 *    tile) to draw only arrows that the builder actually scored. Each ID maps
 *    to a specific source tone and target tone; we find the matching dots in
 *    the pitch maps. Preferred when firedIds is non-empty.
 *
 * 2. findResolutionPairs(mapA, mapB, qualityB, level) — heuristic fallback.
 *    Role-agnostic, proximity-based mirror of ProgressionBuilder::scoreVL().
 *    Used when no fired_resolutions are available (e.g. Pass 1 / pinned voicings).
 *
 * SVG coordinate constants match sbnRenderDiagramSVG in public/js/chords.js:
 *   viewBox 80×95, left=14, top=12, strSp=12, fretSp=16, numFrets=4
 */

const OPEN_MIDI = [40, 45, 50, 55, 59, 64]; // strings 0-5: E A D G B e

export const SVG_W  = 80;
export const SVG_H  = 95;
const STR_SP  = 12;
const FRET_SP = 16;
const LEFT    = 14;
const TOP     = 12;
const NUM_FRETS = 4;

// Maximum pitch-class distance (semitones, octave-equivalent) to draw an arrow.
// The builder uses raw MIDI distance but always picks the nearest target voicing,
// so the effective voice-leading motion is always within 6 semitones (half-octave).
const MAX_PC_DIST = 6;

// Label groups — mirrors ProgressionBuilder constants
const SEVENTH_LABELS  = new Set(['b7', '7', 'maj7']);
const THIRD_LABELS    = new Set(['3', 'b3']);
const ROOT_LABELS     = new Set(['R']);
const NINTH_LABELS    = new Set(['9', 'b9', '#9']);
const MAJ7_LABELS     = new Set(['maj7']);
const SIXTH_LABELS    = new Set(['6', '13', 'b13']);
const FIFTH_LABELS    = new Set(['5']);
const ELEVENTH_LABELS = new Set(['11', '#11']);

const MINOR_QUALITIES = new Set(['m7', 'm6', 'm7b5', 'mMaj7', 'min', 'mMin7']);

/**
 * Build a pitch map for a chord diagram.
 * Returns [{string, fret, midi, label, svgX, svgY}] for every sounding string
 * that carries a non-empty interval label.
 */
export function buildPitchMap(diagramData) {
    if (!diagramData) return [];

    const labels   = (diagramData.interval_labels || '').split(',').map(s => s.trim());
    const position = diagramData.start_fret || 1;
    const dd       = diagramData.diagram_data || {};

    const fretArr = ['x', 'x', 'x', 'x', 'x', 'x'];
    for (const s of (dd.open || []))      { if (s >= 1 && s <= 6) fretArr[s - 1] = 0; }
    for (const p of (dd.positions || [])) { if (p.string >= 1 && p.string <= 6) fretArr[p.string - 1] = p.fret; }
    for (const s of (dd.muted || []))     { if (s >= 1 && s <= 6) fretArr[s - 1] = 'x'; }

    const result = [];
    for (let i = 0; i < 6; i++) {
        const fretVal = fretArr[i];
        if (fretVal === 'x') continue;

        const label = labels[i] || '';
        if (!label || label === 'x' || label === 'X') continue;

        const midi = OPEN_MIDI[i] + fretVal;

        const svgX = LEFT + i * STR_SP;
        let svgY;
        if (fretVal === 0) {
            svgY = TOP - 8;
        } else {
            const rf = fretVal - position + 1;
            svgY = (rf > 0 && rf <= NUM_FRETS) ? (TOP + rf * FRET_SP - FRET_SP / 2) : null;
        }
        if (svgY === null) continue;

        result.push({ string: i, fret: fretVal, midi, label, svgX, svgY });
    }
    return result;
}

function filterByLabels(map, labelSet) {
    return map.filter(p => labelSet.has(p.label));
}

/** Pitch-class distance: smallest interval mod 12, max 6 semitones. */
function pcDist(midiA, midiB) {
    const d = Math.abs(midiA - midiB) % 12;
    return Math.min(d, 12 - d);
}

/**
 * For each voice in `sources`, find the nearest voice in `targets` by raw MIDI
 * distance (matching how the builder picks voicings), then only emit the pair
 * if:
 *   1. The source pitch class is not already present in mapB (common tone — no motion).
 *   2. The pitch-class distance is within MAX_PC_DIST (≤6 semitones).
 */
function nearestPairs(sources, targets, mapBPCs, type) {
    const pairs = [];
    for (const src of sources) {
        // Skip if this pitch class is retained in chord B — it's a common tone, not a resolution
        if (mapBPCs.has(src.midi % 12)) continue;

        let best = null, bestRawDist = Infinity;
        for (const tgt of targets) {
            const d = Math.abs(src.midi - tgt.midi);
            if (d < bestRawDist) { bestRawDist = d; best = tgt; }
        }
        if (best && pcDist(src.midi, best.midi) <= MAX_PC_DIST) {
            pairs.push({ from: src, to: best, type });
        }
    }
    return pairs;
}

/**
 * Find guide tone resolution pairs between two adjacent chords.
 * Mirrors ProgressionBuilder::scoreVL() — role-agnostic, proximity-based.
 *
 * @param {Array}  mapA     buildPitchMap output for source chord
 * @param {Array}  mapB     buildPitchMap output for target chord
 * @param {string} qualityB quality of the target chord (for minor filter on b9/b13)
 * @param {number} level    1 = Pass 1 rules only, 2 = include extension rules
 */
export function findResolutionPairs(mapA, mapB, qualityB = '', level = 1) {
    const targetIsMinor = MINOR_QUALITIES.has(qualityB);

    // Pitch classes present in chord B — used to skip common tones
    const mapBPCs = new Set(mapB.map(p => p.midi % 12));

    const pairs = [];

    // ── Level 1: core guide-tone resolutions ────────────────────────────────

    // b7/7/maj7 → nearest 3/b3
    pairs.push(...nearestPairs(
        filterByLabels(mapA, SEVENTH_LABELS),
        filterByLabels(mapB, THIRD_LABELS),
        mapBPCs,
        'seventh-to-third',
    ));

    // 3/b3 → nearest R, b7/7/maj7, 9
    const thirdTargets = [
        ...filterByLabels(mapB, ROOT_LABELS),
        ...filterByLabels(mapB, SEVENTH_LABELS),
        ...filterByLabels(mapB, NINTH_LABELS),
    ];
    pairs.push(...nearestPairs(
        filterByLabels(mapA, THIRD_LABELS),
        thirdTargets,
        mapBPCs,
        'third-to-root',
    ));

    if (level < 2) return dedupe(pairs);

    // ── Level 2: extension-tone resolutions ─────────────────────────────────

    const ninthsA = mapA.filter(p => {
        if (!NINTH_LABELS.has(p.label)) return false;
        if (targetIsMinor && p.label !== 'b9') return false;
        return true;
    });
    const sixthTargets = filterByLabels(mapB, SIXTH_LABELS)
        .filter(p => !targetIsMinor || p.label === 'b13');
    const ninthTargets = [
        ...sixthTargets,
        ...filterByLabels(mapB, NINTH_LABELS),
        ...filterByLabels(mapB, ROOT_LABELS),
        ...filterByLabels(mapB, FIFTH_LABELS),
    ];
    pairs.push(...nearestPairs(ninthsA, ninthTargets, mapBPCs, 'ninth-ext'));

    const eleventhTargets = [
        ...filterByLabels(mapB, FIFTH_LABELS),
        ...filterByLabels(mapB, THIRD_LABELS),
        ...filterByLabels(mapB, NINTH_LABELS),
    ];
    pairs.push(...nearestPairs(
        filterByLabels(mapA, ELEVENTH_LABELS),
        eleventhTargets,
        mapBPCs,
        'eleventh-ext',
    ));

    const fifthTargets = [
        ...filterByLabels(mapB, ROOT_LABELS),
        ...filterByLabels(mapB, FIFTH_LABELS),
    ];
    pairs.push(...nearestPairs(
        filterByLabels(mapA, FIFTH_LABELS),
        fifthTargets,
        mapBPCs,
        'fifth-ext',
    ));

    return dedupe(pairs);
}

/**
 * Remove pairs where the same source dot appears more than once
 * (keep the one with the smallest pitch-class distance).
 */
function dedupe(pairs) {
    const seen = new Map();
    for (const p of pairs) {
        const key = `${p.from.string},${p.from.fret}`;
        const dist = pcDist(p.from.midi, p.to.midi);
        const prevDist = seen.has(key) ? pcDist(seen.get(key).from.midi, seen.get(key).to.midi) : Infinity;
        if (dist < prevDist) seen.set(key, p);
    }
    return [...seen.values()];
}

/**
 * Arrow color per resolution type.
 * Matches the interval-role colour palette used in the dot coloring.
 */
export function arrowColor(type) {
    if (type === 'seventh-to-third') return '#d97706'; // amber  — 7th resolves
    if (type === 'third-to-root')    return '#2563eb'; // blue   — 3rd resolves
    if (type === 'ninth-ext')        return '#7c3aed'; // purple — 9th ext
    if (type === 'eleventh-ext')     return '#059669'; // green  — #11 ext
    if (type === 'fifth-ext')        return '#6b7280'; // gray   — 5th cont.
    return '#6b7280';
}

/**
 * Source tone → target tone → arrow type for every named resolution ID.
 * Mirrors Phase-E-Extension-Table.yaml named_resolutions section.
 * "3" in a dom→minor edge becomes "b3" at the PHP layer — we match both.
 */
const RESOLUTION_TONES = {
    'vl.dom.b7_to_3':       { src: 'b7',  tgt: ['3', 'b3'], type: 'seventh-to-third' },
    'vl.dom.3_to_root':     { src: '3',   tgt: ['R'],        type: 'third-to-root'   },
    'vl.dom.b13_to_9':      { src: 'b13', tgt: ['9'],         type: 'ninth-ext'       },
    'vl.dom.b9_to_5':       { src: 'b9',  tgt: ['5'],        type: 'ninth-ext'       },
    'vl.dom.13_to_9':       { src: '13',  tgt: ['9'],        type: 'ninth-ext'       },
    'vl.dom.9_to_5':        { src: '9',   tgt: ['5'],        type: 'ninth-ext'       },
    'vl.tritone.sharp11_to_5': { src: '#11', tgt: ['5'],     type: 'eleventh-ext'    },
    'vl.tritone.b7_to_3':   { src: 'b7',  tgt: ['7', '3'],   type: 'seventh-to-third'},
    'vl.iiV.b7_to_3':       { src: 'b7',  tgt: ['3'],        type: 'seventh-to-third'},
    'vl.iiV.3_to_b7':       { src: 'b3',  tgt: ['b7'],       type: 'third-to-root'   },
    'vl.iiV.9_to_b13':      { src: '9',   tgt: ['b13'],      type: 'ninth-ext'       },
    'vl.iiV.5_to_b9':       { src: '5',   tgt: ['b9'],       type: 'fifth-ext'       },
};

/** Core guide-tone arrow types — the 7→3 / ti→do family the student must see. */
export const CORE_TYPES = new Set(['seventh-to-third', 'third-to-root']);

/** Map a backend fired-resolution detail to an arrow type by its source tone. */
function typeForDetail(d) {
    const src = String(d.from?.tone ?? '');
    if (SEVENTH_LABELS.has(src))  return 'seventh-to-third';
    if (THIRD_LABELS.has(src))    return 'third-to-root';
    if (NINTH_LABELS.has(src) || SIXTH_LABELS.has(src)) return 'ninth-ext';
    if (ELEVENTH_LABELS.has(src)) return 'eleventh-ext';
    return 'fifth-ext';
}

/**
 * Rank and cap the guide-tone pairs shown to the student.
 *
 * The point is a CLEAN picture, not a complete one: core motions (7→3,
 * ti→do family) outrank extension colour, same-string motion outranks
 * cross-string, smaller motion outranks bigger — and at most `max` arrows
 * are drawn, one per source dot and one per target dot.
 */
export function selectDisplayPairs(pairs, max = 2) {
    const ranked = [...pairs].sort((a, b) =>
        ((b.core === true) - (a.core === true))
        || ((b.sameString === true) - (a.sameString === true))
        || (Math.abs(a.to.midi - a.from.midi) - Math.abs(b.to.midi - b.from.midi)));

    const out = [];
    const usedSrc = new Set();
    const usedTgt = new Set();
    for (const p of ranked) {
        const sk = `${p.from.string},${p.from.fret}`;
        const tk = `${p.to.string},${p.to.fret}`;
        if (usedSrc.has(sk) || usedTgt.has(tk)) continue;
        usedSrc.add(sk);
        usedTgt.add(tk);
        out.push(p);
        if (out.length >= max) break;
    }
    return out;
}

/**
 * Build display pairs from the builder's fired_resolution_details — the
 * authoritative source: the backend reports the exact string/fret pair that
 * satisfied each resolution (same-string preferred), so no re-derivation
 * heuristics are involved. Details use 1-based strings (low E = 1);
 * pitch-map entries use 0-based.
 *
 * Returns at most `max` pairs (see selectDisplayPairs).
 */
export function findResolutionPairsFromDetails(details, mapA, mapB, max = 2) {
    if (!details || details.length === 0) return [];

    const pairs = [];
    for (const d of details) {
        const from = mapA.find(p => p.string === d.from.string - 1 && p.fret === d.from.fret)
            ?? { string: d.from.string - 1, fret: d.from.fret, midi: d.from.midi, label: String(d.from.tone), svgX: null, svgY: null };
        const to = mapB.find(p => p.string === d.to.string - 1 && p.fret === d.to.fret)
            ?? { string: d.to.string - 1, fret: d.to.fret, midi: d.to.midi, label: String(d.to.tone), svgX: null, svgY: null };
        pairs.push({
            from,
            to,
            type: typeForDetail(d),
            core: !!d.core,
            sameString: !!d.same_string,
            id: d.id,
        });
    }
    return selectDisplayPairs(pairs, max);
}

/**
 * Build resolution pairs from the builder's fired named resolution IDs.
 * For each fired ID, find the dot in mapA carrying the source tone and the
 * dot in mapB carrying the target tone; emit an arrow between them.
 * Returns [] when firedIds is empty — the role-agnostic proximity heuristic
 * (findResolutionPairs) is no longer used as a fallback here, so Pass-1 /
 * non-jazz / pinned voicings draw no spurious arrows. Callers that explicitly
 * want the heuristic call findResolutionPairs directly.
 *
 * @param {Array}    mapA      buildPitchMap output for source chord
 * @param {Array}    mapB      buildPitchMap output for target chord
 * @param {string[]} firedIds  builder's fired_resolutions for this edge
 * @param {string}   qualityB  target quality (for heuristic fallback)
 */
export function findResolutionPairsFromFired(mapA, mapB, firedIds, qualityB = '') {
    if (!firedIds || firedIds.length === 0) {
        return [];
    }

    const pairs = [];
    const usedSrcKeys = new Set();

    for (const id of firedIds) {
        const spec = RESOLUTION_TONES[id];
        if (!spec) continue;

        const src = mapA.find(p => p.label === spec.src);
        if (!src) continue;

        const srcKey = `${src.string},${src.fret}`;
        if (usedSrcKeys.has(srcKey)) continue;

        const tgt = mapB.find(p => spec.tgt.includes(p.label));
        if (!tgt) continue;

        usedSrcKeys.add(srcKey);
        pairs.push({ from: src, to: tgt, type: spec.type });
    }

    return pairs;
}
