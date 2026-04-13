/**
 * SBN Tab Editor — Constants
 *
 * SMuFL glyphs, layout dimensions, tick math.
 * Ported from edit.blade.php module-level SBN_SMUFL and renderTabBody() constants.
 */

// ── SMuFL Glyphs (Bravura font) ────────────────────────────

export const SMUFL = {
    flag8thUp:   '\uE240',
    flag16thUp:  '\uE242',
    flag8thDown: '\uE241',
    flag16thDown: '\uE243',
    restWhole:   '\uE4E3',
    restHalf:    '\uE4E4',
    restQuarter: '\uE4E5',
    rest8th:     '\uE4E6',
    rest16th:    '\uE4E7',
    rest32nd:    '\uE4E8',
};

// ── Layout Constants ────────────────────────────────────────

export const LAYOUT = {
    stringCount:    6,
    measuresPerRow: 4,
    measureWidth:   160,
    stringSpacing:  10  ,
    topPadding:     25,      // headroom for stems + beams + volta above strings
    bottomPadding:  23,      // headroom for stems below strings (increased: stem must clear string-6 hit zone)
    stringAreaTop:  5,      // top string Y position (raised for volta clearance)
    xPadding:       11,      // standard left/right note padding
    xPaddingFirst:  22,      // first measure in a section: extra left indent (clears repeat sign)
    stemBaseOffset: 8,       // stem starts 8px below bottomStringY (must clear string-6 hit zone at +6px)
    stemLength:     15,      // uniform stem length
    beamThickness:  3.2,
    interBeamGap:   2,       // between primary and secondary beam
    noteFontSize:   12,      // fret number font size
};

// Derived constants
LAYOUT.tabHeight      = LAYOUT.topPadding + LAYOUT.stringSpacing * (LAYOUT.stringCount - 1) + LAYOUT.bottomPadding;
LAYOUT.topStringY     = LAYOUT.stringAreaTop;
LAYOUT.bottomStringY  = LAYOUT.stringAreaTop + 5 * LAYOUT.stringSpacing;
LAYOUT.xRange         = LAYOUT.measureWidth - 2 * LAYOUT.xPadding;

// ── Tick Constants ──────────────────────────────────────────

export const TICKS = {
    perBeat:          480,       // ticks per quarter note (MusicXML standard)
    whole:            1920,
    half:             960,
    quarter:          480,
    eighth:           240,
    sixteenth:        120,
    thirtySecond:     60,
    // Triplet durations (3 notes in the space of 2 normal notes)
    tripletHalf:      640,       // 3 in space of 2 quarters  (640 × 3 = 1920)
    tripletQuarter:   320,       // 3 in space of 2 quarters  (320 × 3 = 960)
    tripletEighth:    160,       // 3 in space of 2 eighths   (160 × 3 = 480)
    tripletSixteenth: 80,        // 3 in space of 2 sixteenths (80 × 3 = 240)
};

// ── Duration Helpers ────────────────────────────────────────

/**
 * Duration name → ticks.
 * Supports dotted durations (suffix 'd').
 */
const DURATION_MAP = {
    'w': TICKS.whole,
    'h': TICKS.half,
    'q': TICKS.quarter,
    'e': TICKS.eighth,
    's': TICKS.sixteenth,
    't': TICKS.thirtySecond,
};

export function durationToTicks(durName) {
    if (!durName) return TICKS.quarter;
    const isDotted = durName.endsWith('d');
    const base = isDotted ? durName.slice(0, -1) : durName;
    let ticks = DURATION_MAP[base] || TICKS.quarter;
    if (isDotted) ticks = Math.round(ticks * 1.5);
    return ticks;
}

export function ticksToDuration(ticks) {
    // Check dotted first
    for (const [name, val] of Object.entries(DURATION_MAP)) {
        if (Math.round(val * 1.5) === ticks) return name + 'd';
    }
    // Then exact
    for (const [name, val] of Object.entries(DURATION_MAP)) {
        if (val === ticks) return name;
    }
    // Fallback: find closest
    let closest = 'q';
    let minDiff = Infinity;
    for (const [name, val] of Object.entries(DURATION_MAP)) {
        const diff = Math.abs(val - ticks);
        if (diff < minDiff) { minDiff = diff; closest = name; }
    }
    return closest;
}

/**
 * Strip dotted durations to their base value for flag/beam calculation.
 * e.g. dotted quarter (720) → quarter (480)
 */
export function baseDuration(ticks) {
    // Dotted durations → strip the dot
    if (ticks === 1440) return 960;
    if (ticks === 720)  return 480;
    if (ticks === 360)  return 240;
    if (ticks === 180)  return 120;
    // Triplet durations → map to the next-shorter normal duration for flag purposes
    // A triplet quarter (320) is beamed like an eighth (1 flag/beam)
    // A triplet eighth (160) is beamed like a sixteenth... but in practice
    // triplet eighths use 1 flag/beam (they ARE the smallest common triplet unit).
    // So: 640→960(half,0flags), 320→240(eighth,1flag), 160→240(eighth,1flag), 80→120(16th,2flags)
    if (ticks === 640)  return 960;   // triplet half → no flags (like half note)
    if (ticks === 320)  return 240;   // triplet quarter → 1 flag (beamed like eighth)
    if (ticks === 160)  return 160;   // triplet eighth → already correct (≤240 → 1 flag)
    if (ticks === 80)   return 80;    // triplet 16th → already correct (≤120 → 2 flags)
    return ticks;
}

/**
 * How many flags/beams this duration needs.
 * 0 = quarter or longer, 1 = eighth, 2 = 16th, 3 = 32nd
 */
export function flagCount(ticks) {
    const b = baseDuration(ticks);
    if (b <= 60)  return 3;
    if (b <= 120) return 2;
    if (b <= 240) return 1;
    return 0;
}

/**
 * Is this a dotted duration?
 */
export function isDotted(ticks) {
    return [1440, 720, 360, 180].includes(ticks);
}

/**
 * Is this a triplet (3:2 tuplet) tick value?
 */
export function isTripletTicks(ticks) {
    return [640, 320, 160, 80].includes(ticks);
}

/**
 * Get the rest glyph for a given tick duration.
 *
 * Triplet ticks need special handling: baseDuration() maps them for flag-count
 * purposes (e.g. triplet-eighth 160 → 160, which falls in the rest16th range),
 * but rest glyphs must reflect the written duration of the triplet group.
 *   triplet-half    (640)  → half rest
 *   triplet-quarter (320)  → quarter rest
 *   triplet-eighth  (160)  → eighth rest   ← was wrongly showing 16th
 *   triplet-16th    ( 80)  → 16th rest     ← was wrongly showing 32nd
 */
export function restGlyph(ticks) {
    // Triplet tick values: use written duration glyph, not raw tick comparison
    if (ticks === 640) return SMUFL.restHalf;
    if (ticks === 320) return SMUFL.restQuarter;
    if (ticks === 160) return SMUFL.rest8th;
    if (ticks === 80)  return SMUFL.rest16th;
    // Normal durations
    const b = baseDuration(ticks);
    if (b >= 1920) return SMUFL.restWhole;
    if (b >= 960)  return SMUFL.restHalf;
    if (b >= 480)  return SMUFL.restQuarter;
    if (b >= 240)  return SMUFL.rest8th;
    if (b >= 120)  return SMUFL.rest16th;
    return SMUFL.rest32nd;
}

/**
 * Get the flag glyph for a stem direction and flag count.
 */
export function flagGlyph(stemDir, count) {
    if (stemDir === 'up') {
        return count >= 2 ? SMUFL.flag16thUp : SMUFL.flag8thUp;
    }
    return count >= 2 ? SMUFL.flag16thDown : SMUFL.flag8thDown;
}

/**
 * Compute X position within a measure given the fractional position.
 */
export function getX(xPos, isFirstOfSection = false) {
    const xL = isFirstOfSection ? LAYOUT.xPaddingFirst : LAYOUT.xPadding;
    const xRng = LAYOUT.measureWidth - xL - LAYOUT.xPadding;
    return xL + xPos * xRng;
}

/**
 * Compute Y position for a guitar string (1=high e, 6=low E).
 * displayRow = string - 1 (string 1 → row 0 at top)
 */
export function stringY(stringNum) {
    return LAYOUT.stringAreaTop + (stringNum - 1) * LAYOUT.stringSpacing;
}

/**
 * Generate a short unique ID for TabEvent keying.
 */
let _idCounter = 0;
export function generateId() {
    return 'te_' + (++_idCounter) + '_' + Math.random().toString(36).slice(2, 6);
}

/**
 * Calculate ticks per measure from a time signature string.
 */
export function ticksPerMeasure(timeSig = '4/4') {
    const [beatsStr, beatTypeStr] = timeSig.split('/');
    const beats    = parseInt(beatsStr) || 4;
    const beatType = parseInt(beatTypeStr) || 4;
    return TICKS.perBeat * (4 / beatType) * beats;
}
