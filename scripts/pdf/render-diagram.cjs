/**
 * render-diagram.cjs
 *
 * Loads chords.js in a VM sandbox and renders a chord diagram SVG from
 * a sbn_chord_diagrams slug. Intended to be called from PHP via:
 *   node scripts/pdf/render-diagram.cjs <slug>
 *
 * Outputs the SVG string to stdout.
 * On error, writes to stderr and exits 1.
 *
 * Mapping logic ported from ChordDiagram.vue:48-106.
 */

'use strict';

const fs = require('fs');
const vm = require('vm');
const path = require('path');
const Database = require('better-sqlite3');

// ---------------------------------------------------------------------------
// 1. Args
// ---------------------------------------------------------------------------

const slug = process.argv[2];
if (!slug) {
    process.stderr.write('Usage: node render-diagram.cjs <slug>\n');
    process.exit(1);
}

// ---------------------------------------------------------------------------
// 2. Load chords.js into a sandboxed VM context
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
// 3. DB lookup
// ---------------------------------------------------------------------------

const dbPath = path.resolve(__dirname, '../../database/sbn.db');
const db = new Database(dbPath, { readonly: true });

const row = db.prepare(
    'SELECT diagram_data, interval_labels, start_fret FROM sbn_chord_diagrams WHERE slug = ?'
).get(slug);

if (!row) {
    process.stderr.write(`Chord slug not found: ${slug}\n`);
    process.exit(1);
}

const data = typeof row.diagram_data === 'string'
    ? JSON.parse(row.diagram_data)
    : row.diagram_data;

// ---------------------------------------------------------------------------
// 4. fret/finger-Mapping (ported from ChordDiagram.vue:48-89)
// ---------------------------------------------------------------------------

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

// displayPosition logic (ChordDiagram.vue:94-99)
const posFrets  = (data.positions ?? []).map(p => p.fret);
const barreFrets = (data.barres ?? []).map(b => b.fret);
const maxFret   = Math.max(0, ...posFrets, ...barreFrets);
const hasOpen   = (data.open ?? []).length > 0 || posFrets.some(f => f === 0);
const startFret = row.start_fret ?? 1;
const displayPosition = (maxFret > 0 && maxFret <= 4 && hasOpen) ? 1 : startFret;

const voicing = {
    frets:       diagramDataToFretString(data),
    fret_string: diagramDataToFretString(data),
    position:    displayPosition,
    start_fret:  displayPosition,
    fingers:     diagramDataToFingerString(data),
};

// ---------------------------------------------------------------------------
// 5. Render
// ---------------------------------------------------------------------------

const svg = render(voicing, {
    showFingers: true,
    dotColor: '#1a1a2e',
    intervalLabels: row.interval_labels ?? undefined,
});

process.stdout.write(svg);
