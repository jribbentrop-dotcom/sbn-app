/**
 * render-diagram-inline.cjs
 *
 * Renders a B&W chord diagram SVG from an inline voicing definition
 * (fret string + position + fingers), without a DB lookup.
 *
 * Input JSON file: { frets, position, fingers }
 *   frets:    e.g. "5x456x"
 *   position: e.g. 4
 *   fingers:  e.g. "201340"
 *
 * Usage:
 *   node render-diagram-inline.cjs <json-file>
 *
 * Output: SVG string to stdout.
 */

'use strict';

const fs   = require('fs');
const vm   = require('vm');
const path = require('path');

// Load chords.js into sandbox
const noop  = () => {};
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

// Read input
const arg = process.argv[2];
if (!arg) {
    process.stderr.write('Usage: node render-diagram-inline.cjs <json-file>\n');
    process.exit(1);
}

let input;
try {
    input = JSON.parse(fs.readFileSync(path.resolve(arg), 'utf8'));
} catch (e) {
    process.stderr.write('Invalid JSON: ' + e.message + '\n');
    process.exit(1);
}

const voicing = {
    frets:      input.frets,
    fret_string: input.frets,
    position:   input.position || 1,
    start_fret: input.position || 1,
    fingers:    input.fingers  || '000000',
};

let svg = render(voicing, {
    showFingers: true,
    dotColor: '#000',
});

// Replace CSS vars with hard B&W values
svg = svg
    .replace(/var\(--clr-text(?:-dim|-muted)?,?\s*[^)]*\)/g, '#000')
    .replace(/var\(--clr-text\)/g, '#000')
    .replace(/var\(--clr-red\)/g, '#000')
    .replace(/var\(--clr-accent[^)]*\)/g, '#000');

// Thicker string lines (stroke-width 0.4 → 0.9)
svg = svg.replace(/(<line[^>]*stroke-width=")0\.4("[^>]*stroke="[^"]*"[^>]*y1="(?:10|1[6-9]|[2-9]\d)[^>]*>)/g, (m) => m.replace('0.4', '0.9'));
// Simpler: replace all stroke-width="0.4" in string/fret lines
svg = svg.replace(/stroke-width="0\.4"/g, 'stroke-width="0.7"');

// Crop to 3 frets: remove the 4th fret line (y1="80" y2="80") and clip viewBox height
// fretSp=16, top=16: fret lines at y=16,32,48,64,80 — remove y=80 line
svg = svg.replace(/<line[^>]*y1="80"[^>]*y2="80"[^>]*\/>/g, '');
// String lines extend to y2=85; crop to y2=69 (top=16 + 3*fretSp=48 + 5px extend)
svg = svg.replace(/(<line[^>]*y1="\d+"[^>]*y2=")85(")/g, '$169$2');
// Adjust viewBox height from 98 to 76 (top=16, 3*fretSp=48, bottom extend=5, padding=7)
svg = svg.replace('viewBox="0 0 88 98"', 'viewBox="0 0 88 76"');

process.stdout.write(svg);
