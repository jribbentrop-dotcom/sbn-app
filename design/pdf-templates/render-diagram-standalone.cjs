/**
 * render-diagram-standalone.cjs
 *
 * Same rendering logic as scripts/pdf/render-diagram.cjs, but reads the
 * chord-diagram row from a JSON file instead of querying sqlite directly.
 * Used here because better-sqlite3's native binary in node_modules was
 * built for the user's Windows machine and can't load in this Linux
 * sandbox (ERR_DLOPEN_FAILED / invalid ELF header). Production code keeps
 * using the real render-diagram.cjs unchanged.
 *
 * Usage: node render-diagram-standalone.cjs <row.json> [--color]
 */

'use strict';

const fs = require('fs');
const vm = require('vm');
const path = require('path');

const rowPath = process.argv[2];
const useColor = process.argv.includes('--color');
if (!rowPath) {
    process.stderr.write('Usage: node render-diagram-standalone.cjs <row.json> [--color]\n');
    process.exit(1);
}

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

const row = JSON.parse(fs.readFileSync(rowPath, 'utf8'));
const data = row.diagram_data;

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

const svg = render(voicing, {
    showFingers: true,
    dotColor: useColor ? undefined : '#2c3e50',
    intervalLabels: useColor ? (row.interval_labels ?? undefined) : undefined,
});

process.stdout.write(svg);
