/**
 * SBN Tab Editor — MusicXML Writer
 *
 * Serializes a TabModel back to a MusicXML string suitable for storage
 * in the `tab_xml` database column and round-tripping through the parser.
 *
 * Design decisions:
 *   · divisions = 480  (1 quarter = 480 ticks — all SBN tick values are
 *     exact integers at this division, including triplets: 320, 160, 640…)
 *   · Single-part, single-staff TAB score
 *   · Pitch + octave written from TabNote.pitch / TabNote.octave
 *   · Fret / string written as <notations><technical><string><fret>
 *   · Ties: per-note tieStart / tieStop → <tie> element + <notations><tied>
 *   · Beams: from event.beam1 / beam2 (or re-derived from beamStart/Continue/End)
 *   · Tuplets: from event.tupletActual / tupletType / tupletBracket
 *   · Barlines: repeatStart/End on measure → <barline> with <repeat>
 *   · Volta: voltaStart/End → <barline><ending>
 *
 * Usage:
 *   import { modelToMusicXml } from './musicXmlWriter.js';
 *   const xmlString = modelToMusicXml(model, { title, composer });
 */

// ── Constants ───────────────────────────────────────────────

const DIVISIONS = 480;  // ticks per quarter note

// Map SBN duration name → MusicXML <type> string
const DURATION_TYPE = {
    w:  'whole',
    h:  'half',
    q:  'quarter',
    e:  'eighth',
    s:  '16th',
    t:  '32nd',
    // dotted variants — same base type
    wd: 'whole',
    hd: 'half',
    qd: 'quarter',
    ed: 'eighth',
    sd: '16th',
    td: '32nd',
};

// Standard guitar tuning: string 1 (high e) → string 6 (low E)
// MusicXML staff-tuning line numbers are bottom-up: line 1 = low E (string 6)
// SBN string numbering: 1 = high e, 6 = low E
const TUNING = [
    { line: 1, step: 'E', octave: 2 },  // string 6 — low E
    { line: 2, step: 'A', octave: 2 },  // string 5
    { line: 3, step: 'D', octave: 3 },  // string 4
    { line: 4, step: 'G', octave: 3 },  // string 3
    { line: 5, step: 'B', octave: 3 },  // string 2
    { line: 6, step: 'E', octave: 4 },  // string 1 — high e
];

// ── Helpers ─────────────────────────────────────────────────

/**
 * Escape XML special characters in text content.
 */
function esc(s) {
    if (s == null) return '';
    return String(s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

/**
 * Parse a pitch string like 'F#', 'Bb', 'C' into { step, alter }.
 * alter: +1 = sharp, -1 = flat, 0 = natural.
 */
function parsePitch(pitchStr) {
    if (!pitchStr) return { step: 'C', alter: 0 };
    const step = pitchStr[0].toUpperCase();
    const rest = pitchStr.slice(1);
    let alter = 0;
    if (rest.includes('#') || rest.includes('♯')) alter = 1;
    else if (rest.includes('b') || rest.includes('♭')) alter = -1;
    return { step, alter };
}

/**
 * Convert SBN ticks → MusicXML <duration> integer (at DIVISIONS=480).
 */
function ticksToDivDuration(ticks) {
    return Math.round(ticks);
}

/**
 * Derive the MusicXML <type> string from event duration + ticks.
 * Handles dotted and triplet durations.
 */
function mxmlType(event) {
    const dur = event.duration || 'q';
    // Use the base duration name (strip 'd' suffix for dotted)
    const base = dur.endsWith('d') ? dur.slice(0, -1) : dur;
    return DURATION_TYPE[base] || DURATION_TYPE[dur] || 'quarter';
}

/**
 * Is this a dotted duration?
 */
function isDotted(event) {
    return (event.duration || '').endsWith('d');
}

/**
 * Is this a tuplet event?
 */
function isTuplet(event) {
    return event.tupletActual != null && event.tupletActual > 0;
}

/**
 * Indent a block of XML text by `n` spaces.
 */
function indent(str, n) {
    const pad = ' '.repeat(n);
    return str.split('\n').map(l => l ? pad + l : l).join('\n');
}

// ── Harmony serializer ───────────────────────────────────────

/**
 * Map from SBN chord suffix → MusicXML kind enum value + display text.
 *
 * Ordered longest-first so the suffix scanner matches greedily.
 * This is the inverse of the kindValueMap used in the Alpine parser.
 *
 * Format: [suffixPattern, kindEnum, kindText]
 *   suffixPattern — exact string that appears after the root in a chord name
 *   kindEnum      — MusicXML <kind> element text content
 *   kindText      — value for the <kind text="…"> attribute (display suffix)
 */
const SUFFIX_TO_KIND = [
    // Dominant extensions
    ['13',      'dominant-13th',    '13'],
    ['11',      'dominant-11th',    '11'],
    ['9',       'dominant-ninth',   '9'],
    // Major extensions
    ['Maj13',   'major-13th',       'Maj13'],
    ['Maj11',   'major-11th',       'Maj11'],
    ['Maj9',    'major-ninth',      'Maj9'],
    ['Maj7',    'major-seventh',    'Maj7'],
    ['maj13',   'major-13th',       'Maj13'],
    ['maj11',   'major-11th',       'Maj11'],
    ['maj9',    'major-ninth',      'Maj9'],
    ['maj7',    'major-seventh',    'Maj7'],
    ['M7',      'major-seventh',    'Maj7'],
    ['△7',      'major-seventh',    'Maj7'],
    ['△',       'major-seventh',    'Maj7'],
    // Minor extensions
    ['m13',     'minor-13th',       'm13'],
    ['m11',     'minor-11th',       'm11'],
    ['mMaj7',   'major-minor',      'mMaj7'],
    ['m9',      'minor-ninth',      'm9'],
    ['m7b5',    'half-diminished',  'm7b5'],
    ['ø7',      'half-diminished',  'm7b5'],
    ['ø',       'half-diminished',  'm7b5'],
    ['m7',      'minor-seventh',    'm7'],
    ['m6',      'minor-sixth',      'm6'],
    // Diminished / augmented
    ['°7',      'diminished-seventh','°7'],
    ['dim7',    'diminished-seventh','°7'],
    ['°',       'diminished',       'dim'],
    ['dim',     'diminished',       'dim'],
    ['aug7',    'augmented-seventh','aug7'],
    ['+7',      'augmented-seventh','aug7'],
    ['aug',     'augmented',        'aug'],
    ['+',       'augmented',        'aug'],
    // Simple triads / other
    ['m',       'minor',            'm'],
    ['6',       'major-sixth',      '6'],
    ['7',       'dominant',         '7'],
    ['5',       'power',            '5'],
    ['sus4',    'suspended-fourth', 'sus4'],
    ['sus2',    'suspended-second', 'sus2'],
    ['sus',     'suspended-fourth', 'sus4'],
    // Major (empty suffix) — must be last
    ['',        'major',            ''],
];

/**
 * Parse an SBN chord name string (e.g. "Dm7", "G7", "D6/F#", "Cmaj7", "Abm7b5")
 * into its MusicXML harmony components.
 *
 * Returns:
 *   { rootStep, rootAlter, kindEnum, kindText, bassStep, bassAlter, extensions }
 *   extensions = array of strings like "b9", "#11", "no3" (from remaining suffix)
 */
function parseChordName(name) {
    if (!name || typeof name !== 'string') {
        return { rootStep: 'C', rootAlter: 0, kindEnum: 'major', kindText: '', bassStep: null, bassAlter: 0, extensions: [] };
    }

    // ── Split off slash bass ──
    // "D6/F#" → body="D6", bass="F#"
    // Careful: the slash must follow a non-empty bass note (letter + optional accidental)
    const slashIdx = name.lastIndexOf('/');
    let body = name;
    let bassStr = null;
    if (slashIdx > 0) {
        const possibleBass = name.slice(slashIdx + 1);
        if (/^[A-G][#b♯♭]?$/.test(possibleBass)) {
            body    = name.slice(0, slashIdx);
            bassStr = possibleBass;
        }
    }

    // ── Parse root (letter + optional accidental) ──
    const rootMatch = body.match(/^([A-G])([#b♯♭]{1,2})?/);
    if (!rootMatch) {
        return { rootStep: 'C', rootAlter: 0, kindEnum: 'major', kindText: '', bassStep: null, bassAlter: 0, extensions: [] };
    }
    const rootStep   = rootMatch[1];
    const accidental = rootMatch[2] || '';
    const rootAlter  = accidental.includes('#') || accidental.includes('♯') ? 1
                     : accidental.includes('b') || accidental.includes('♭') ? -1 : 0;
    const suffix     = body.slice(rootMatch[0].length);   // everything after the root

    // ── Match suffix against kind table (longest match first) ──
    let kindEnum = 'major';
    let kindText = '';
    let remainder = suffix;  // anything left after kind suffix is treated as extensions

    for (const [pattern, kEnum, kText] of SUFFIX_TO_KIND) {
        if (suffix.startsWith(pattern)) {
            kindEnum  = kEnum;
            kindText  = kText;
            remainder = suffix.slice(pattern.length);
            break;
        }
    }

    // ── Extensions: parse remaining suffix tokens ──
    // e.g. "b9", "#11", "add9", "no3" — simple split on boundaries
    const extensions = [];
    if (remainder) {
        // Tokenize: each token starts with an optional b/#/add/no and a digit or word
        const extRegex = /(?:add|no|[b#]|\d)+/g;
        let m;
        while ((m = extRegex.exec(remainder)) !== null) {
            extensions.push(m[0]);
        }
    }

    // ── Bass note ──
    let bassStep  = null;
    let bassAlter = 0;
    if (bassStr) {
        bassStep  = bassStr[0].toUpperCase();
        const bAcc = bassStr.slice(1);
        bassAlter = bAcc.includes('#') || bAcc.includes('♯') ? 1
                  : bAcc.includes('b') || bAcc.includes('♭') ? -1 : 0;
    }

    return { rootStep, rootAlter, kindEnum, kindText, bassStep, bassAlter, extensions };
}

/**
 * Serialize a single chord name string into a MusicXML <harmony> element.
 * Returns a multi-line XML string (no leading indent — caller indents).
 */
function serializeHarmony(chordName) {
    const { rootStep, rootAlter, kindEnum, kindText, bassStep, bassAlter, extensions } = parseChordName(chordName);

    const lines = [];
    lines.push('<harmony print-frame="no">');
    lines.push('  <root>');
    lines.push(`    <root-step>${esc(rootStep)}</root-step>`);
    if (rootAlter !== 0) lines.push(`    <root-alter>${rootAlter}</root-alter>`);
    lines.push('  </root>');

    const kindTextAttr = kindText ? ` text="${esc(kindText)}"` : '';
    lines.push(`  <kind${kindTextAttr}>${esc(kindEnum)}</kind>`);

    if (bassStep) {
        lines.push('  <bass>');
        lines.push(`    <bass-step>${esc(bassStep)}</bass-step>`);
        if (bassAlter !== 0) lines.push(`    <bass-alter>${bassAlter}</bass-alter>`);
        lines.push('  </bass>');
    }

    // Emit degree elements for any unparsed extension tokens
    extensions.forEach(ext => {
        // Try to extract alter + value from token like "b9", "#11", "add9", "no3"
        const noMatch  = ext.match(/^no(\d+)$/);
        const addMatch = ext.match(/^add(\d+)$/);
        const altMatch = ext.match(/^([b#]?)(\d+)$/);

        let degreeValue = null, degreeAlter = 0, degreeType = 'add';

        if (noMatch)  { degreeValue = noMatch[1];  degreeType = 'subtract'; }
        else if (addMatch) { degreeValue = addMatch[1]; degreeType = 'add'; }
        else if (altMatch) {
            const acc = altMatch[1];
            degreeAlter = acc === '#' ? 1 : acc === 'b' ? -1 : 0;
            degreeValue = altMatch[2];
        }

        if (degreeValue) {
            lines.push('  <degree>');
            lines.push(`    <degree-value>${degreeValue}</degree-value>`);
            lines.push(`    <degree-alter>${degreeAlter}</degree-alter>`);
            lines.push(`    <degree-type>${degreeType}</degree-type>`);
            lines.push('  </degree>');
        }
    });

    lines.push('</harmony>');
    return lines.join('\n');
}

// ── Note serializer ─────────────────────────────────────────

/**
 * Serialize one note within a TabEvent.
 * `isFirst` = true for the first note in a chord (no <chord/> element).
 * `isFirstChordNote` = true only for the very first note in the event.
 *
 * @param {object} note     - TabNote { string, fret, pitch, octave, tieStart, tieStop }
 * @param {object} event    - Parent TabEvent
 * @param {object} opts     - { isFirst, voice, stemDir }
 * @returns {string}        - XML string for one <note> element
 */
function serializeNote(note, event, opts) {
    const { isFirst, voice, stemDir } = opts;
    const lines = [];

    lines.push('<note>');

    // Chord marker (all notes after the first in a chord)
    if (!isFirst) lines.push('  <chord/>');

    // Pitch (required even for tab — helps some parsers)
    if (!event.isRest && note.pitch) {
        const { step, alter } = parsePitch(note.pitch);
        lines.push('  <pitch>');
        lines.push(`    <step>${esc(step)}</step>`);
        if (alter !== 0) lines.push(`    <alter>${alter}</alter>`);
        lines.push(`    <octave>${note.octave ?? 3}</octave>`);
        lines.push('  </pitch>');
    }

    // Duration
    lines.push(`  <duration>${ticksToDivDuration(event.ticks)}</duration>`);

    // Rest marker
    if (event.isRest) lines.push('  <rest/>');

    // Voice
    lines.push(`  <voice>${voice}</voice>`);

    // Type
    lines.push(`  <type>${mxmlType(event)}</type>`);

    // Dot
    if (isDotted(event)) lines.push('  <dot/>');

    // Time modification (tuplets)
    if (isFirst && isTuplet(event)) {
        lines.push('  <time-modification>');
        lines.push(`    <actual-notes>${event.tupletActual}</actual-notes>`);
        lines.push(`    <normal-notes>${event.tupletNormal || 2}</normal-notes>`);
        lines.push('  </time-modification>');
    }

    // Stem
    if (!event.isRest && stemDir) {
        lines.push(`  <stem>${stemDir === 'up' ? 'up' : 'down'}</stem>`);
    }

    // Beam (only on first note of a chord)
    if (isFirst && event.beam1) {
        lines.push(`  <beam number="1">${esc(event.beam1)}</beam>`);
    }
    if (isFirst && event.beam2) {
        lines.push(`  <beam number="2">${esc(event.beam2)}</beam>`);
    }

    // Notations block
    const notationParts = [];

    // Ties (notations side — per note)
    if (note.tieStart) notationParts.push('<tied type="start"/>');
    if (note.tieStop)  notationParts.push('<tied type="stop"/>');

    // Tuplet notation (only on first note of chord, start/stop events)
    if (isFirst && isTuplet(event) && event.tupletType) {
        const bracketAttr = event.tupletBracket ? ' bracket="yes"' : '';
        notationParts.push(`<tuplet type="${esc(event.tupletType)}"${bracketAttr}/>`);
    }

    // Fret / string (tab technical — on every note in a chord)
    if (!event.isRest && note.fret != null && note.string != null) {
        notationParts.push(
            '<technical>',
            `  <string>${note.string}</string>`,
            `  <fret>${note.fret}</fret>`,
            '</technical>',
        );
    }

    if (notationParts.length) {
        lines.push('  <notations>');
        notationParts.forEach(p => {
            lines.push('    ' + p);
        });
        lines.push('  </notations>');
    }

    // Tie elements (note-level, for playback linkage)
    if (note.tieStart) lines.push('<tie type="start"/>');
    if (note.tieStop)  lines.push('<tie type="stop"/>');

    lines.push('</note>');
    return lines.join('\n');
}

// ── Measure serializer ──────────────────────────────────────

/**
 * Serialize one MeasureModel → XML string (contents only, no <measure> wrapper).
 * @param {object} measure
 * @param {number} measureNumber  - 1-based MusicXML measure number
 * @param {boolean} isFirst       - true for the very first measure (emits attributes)
 * @param {string}  timeSig       - e.g. '4/4'
 */
function serializeMeasure(measure, measureNumber, isFirst, timeSig) {
    const parts = [];

    // ── Opening barline (repeat start / volta start) ──
    if (measure.repeatStart) {
        parts.push(
            '<barline location="left">',
            '  <bar-style>heavy-light</bar-style>',
            '  <repeat direction="forward"/>',
            '</barline>',
        );
    }
    if (measure.voltaStart && measure.volta) {
        const voltaNum  = measure.volta.number || 1;
        const voltaText = measure.volta.text || `${voltaNum}.`;
        parts.push(
            '<barline location="left">',
            `  <ending number="${voltaNum}" type="start">${esc(voltaText)}</ending>`,
            '</barline>',
        );
    }

    // ── Attributes block (first measure only) ──
    if (isFirst) {
        const [beatsStr, beatTypeStr] = timeSig.split('/');
        parts.push(
            '<attributes>',
            `  <divisions>${DIVISIONS}</divisions>`,
            '  <key>',
            '    <fifths>0</fifths>',
            '  </key>',
            '  <time>',
            `    <beats>${beatsStr || 4}</beats>`,
            `    <beat-type>${beatTypeStr || 4}</beat-type>`,
            '  </time>',
            '  <clef>',
            '    <sign>TAB</sign>',
            '  </clef>',
            '  <staff-details>',
            '    <staff-lines>6</staff-lines>',
            ...TUNING.map(t =>
                `    <staff-tuning line="${t.line}"><tuning-step>${t.step}</tuning-step><tuning-octave>${t.octave}</tuning-octave></staff-tuning>`
            ),
            '  </staff-details>',
            '</attributes>',
        );
    }

    // ── Harmony elements (chord names) ──
    // Emitted after <attributes> and before the first <note>, one per chord name.
    // These round-trip through the Alpine parser's parseHarmony() → chordNames[].
    const chordNames = measure.chordNames || [];
    chordNames.forEach(name => {
        if (name) parts.push(serializeHarmony(name));
    });

    // ── Events ──
    // Sort events: by tick, then voice, then isRest last within same tick
    const events = [...(measure.events || [])].sort(
        (a, b) => a.tick - b.tick || a.voice - b.voice
    );

    for (const event of events) {
        const voice    = event.voice || 1;
        const stemDir  = event.stemDir || 'down';

        if (event.isRest) {
            // Single rest note — no chord notes
            parts.push(serializeNote({}, event, { isFirst: true, voice, stemDir }));
        } else {
            // Sort chord notes: string 1 (high) first → string 6 (low) last
            const notes = [...(event.notes || [])].sort((a, b) => a.string - b.string);
            notes.forEach((note, ni) => {
                parts.push(serializeNote(note, event, {
                    isFirst: ni === 0,
                    voice,
                    stemDir,
                }));
            });
        }
    }

    // ── Closing barline (repeat end / volta end / final) ──
    const closingBarParts = [];

    if (measure.voltaEnd) {
        const voltaNum = measure.volta?.number || 1;
        closingBarParts.push(`<ending number="${voltaNum}" type="stop"/>`);
    }
    if (measure.repeatEnd) {
        closingBarParts.push('<bar-style>light-heavy</bar-style>');
        closingBarParts.push('<repeat direction="backward"/>');
    }

    if (closingBarParts.length) {
        parts.push('<barline location="right">', ...closingBarParts.map(p => '  ' + p), '</barline>');
    }

    return parts.join('\n');
}

// ── Top-level serializer ────────────────────────────────────

/**
 * Convert a TabModel to a MusicXML string.
 *
 * @param {object} model  - TabModel from useTabModel
 * @param {object} meta   - { title?: string, composer?: string }
 * @returns {string}      - Complete MusicXML document string
 */
export function modelToMusicXml(model, meta = {}) {
    if (!model || !model.sections) return '';

    const title    = meta.title    || 'Untitled';
    const composer = meta.composer || '';
    const timeSig  = model.timeSignature || '4/4';

    // Flatten all measures in section order
    const allMeasures = model.sections.flatMap(sec => sec.measures || []);
    if (!allMeasures.length) return '';

    // ── Build measure XML strings ──
    const measureXmls = allMeasures.map((measure, i) => {
        const inner = serializeMeasure(measure, i + 1, i === 0, timeSig);
        return `    <measure number="${i + 1}">\n${indent(inner, 6)}\n    </measure>`;
    });

    // ── Assemble document ──
    const lines = [
        '<?xml version="1.0" encoding="UTF-8"?>',
        '<!DOCTYPE score-partwise PUBLIC',
        '  "-//Recordare//DTD MusicXML 3.1 Partwise//EN"',
        '  "http://www.musicxml.org/dtds/partwise.dtd">',
        '<score-partwise version="3.1">',
        '  <work>',
        `    <work-title>${esc(title)}</work-title>`,
        '  </work>',
        '  <identification>',
        '    <encoding>',
        '      <software>SBN Teaching Hub</software>',
        `      <encoding-date>${new Date().toISOString().slice(0, 10)}</encoding-date>`,
        '    </encoding>',
        '  </identification>',
    ];

    if (composer) {
        lines.splice(5, 0,
            '  <credit page="1">',
            `    <credit-words>${esc(composer)}</credit-words>`,
            '  </credit>',
        );
    }

    lines.push(
        '  <part-list>',
        '    <score-part id="P1">',
        `      <part-name>${esc(title)}</part-name>`,
        '    </score-part>',
        '  </part-list>',
        '  <part id="P1">',
        ...measureXmls,
        '  </part>',
        '</score-partwise>',
    );

    return lines.join('\n');
}
