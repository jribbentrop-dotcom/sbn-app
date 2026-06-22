/**
 * render-diagram-from-json.cjs
 *
 * Sandbox-safe sibling of render-diagram.cjs: same fret/finger/position
 * mapping logic, but reads the chord row from a JSON file instead of
 * querying sbn_chord_diagrams via better-sqlite3 (which requires a
 * Windows-native binary unavailable in some environments).
 *
 * Input JSON file shape (matches a row from sbn_chord_diagrams):
 *   { slug, diagram_data: {positions,barres,muted,open}, interval_labels, start_fret }
 *
 * Usage:
 *   node render-diagram-from-json.cjs <json-file> [--bw]
 *
 * Output: SVG string to stdout.
 */

'use strict';

const fs   = require('fs');
const vm   = require('vm');
const path = require('path');

const jsonPath = process.argv[2];
const bw       = process.argv.includes('--bw');
if (!jsonPath) {
    process.stderr.write('Usage: node render-diagram-from-json.cjs <json-file> [--bw]\n');
    process.exit(1);
}

const row = JSON.parse(fs.readFileSync(path.resolve(jsonPath), 'utf8'));

// ---------------------------------------------------------------------------
// Load chords.js into a sandboxed VM context (same as render-diagram.cjs)
// ---------------------------------------------------------------------------
const noop = () => {};
const fakeEl = new Proxy({}, { get: () => noop, set: () => true });
const sandbox = {
    window: {},
    console,
    document: {
        addEventListener: noop,
        createElement: () => fakeEl,
        body: { appendChild: noop },
        querySelectorAll: () => [],
        getElementById: () => null,
    },
};
sandbox.window.document = sandbox.document;
vm.createContext(sandbox);

const chordsJsPath = path.resolve(__dirname, '../../public/js/chords.js');
vm.runInContext(fs.readFileSync(chordsJsPath, 'utf8'), sandbox);

const render = sandbox.sbnRenderDiagramSVG;
if (typeof render !== 'function') {
    process.stderr.write('sbnRenderDiagramSVG not found in chords.js\n');
    process.exit(1);
}

// ---------------------------------------------------------------------------
// fret/finger mapping (ported from ChordDiagram.vue:48-89, verbatim from
// render-diagram.cjs)
// ---------------------------------------------------------------------------

const data = typeof row.diagram_data === 'string'
    ? JSON.parse(row.diagram_data)
    : row.diagram_data;

function diagramDataToFretString(d) {
    const frets = ['x', 'x', 'x', 'x', 'x', 'x'];
    for (const s of (d.open ?? [])) {
        if (s >= 1 && s <= 6) frets[s - 1] = 0;
    }
    for (const pos of (d.positions ?? [])) {
        if (pos.string >= 1 && pos.string <= 6) frets[pos.string - 1] = pos.fret;
    }
    for (const s of (d.muted ?? [])) {
        if (s >= 1 && s <= 6) frets[s - 1] = 'x';
    }
    return frets.map(f => f === 'x' ? 'x' : f.toString(16)).join('');
}

function diagramDataToFingerString(d) {
    if (!d) return '000000';
    const fingers = ['0', '0', '0', '0', '0', '0'];
    for (const pos of (d.positions ?? [])) {
        if (pos.string >= 1 && pos.string <= 6 && pos.finger && pos.finger !== '0') {
            fingers[pos.string - 1] = String(pos.finger);
        }
    }
    for (const barre of (d.barres ?? [])) {
        if (barre.finger && barre.finger !== '0') {
            const from = Math.min(barre.from, barre.to);
            const to   = Math.max(barre.from, barre.to);
            for (let s = from; s <= to; s++) {
                if (s >= 1 && s <= 6) fingers[s - 1] = String(barre.finger);
            }
        }
    }
    return fingers.join('');
}

const posFrets   = (data.positions ?? []).map(p => p.fret);
const barreFrets = (data.barres ?? []).map(b => b.fret);
const maxFret     = Math.max(0, ...posFrets, ...barreFrets);
const hasOpen      = (data.open ?? []).length > 0 || posFrets.some(f => f === 0);
const startFret    = row.start_fret ?? 1;
const displayPosition = (maxFret > 0 && maxFret <= 4 && hasOpen) ? 1 : startFret;

const voicing = {
    frets:       diagramDataToFretString(data),
    fret_string: diagramDataToFretString(data),
    position:    displayPosition,
    start_fret:  displayPosition,
    fingers:     diagramDataToFingerString(data),
};

let svg = render(voicing, {
    showFingers: true,
    dotColor:       bw ? '#000' : '#1a1a2e',
    intervalLabels: bw ? undefined : (row.interval_labels ?? undefined),
});

if (bw) {
    svg = svg
        .replace(/var\(--clr-text(?:-dim|-muted)?,?\s*[^)]*\)/g, '#000')
        .replace(/var\(--clr-text\)/g, '#000')
        .replace(/var\(--clr-red\)/g, '#000')
        .replace(/var\(--clr-accent[^)]*\)/g, '#000');
}

process.stdout.write(svg);
