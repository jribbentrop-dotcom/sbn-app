/**
 * extract-tab-svg.cjs
 *
 * Extracts a TAB staff from a MuseScore SVG export and outputs a cropped SVG
 * ready to embed in the PDF template.
 *
 * Usage:
 *   node extract-tab-svg.cjs <svg-file> <system-index>
 *
 *   <svg-file>      Path to a MuseScore SVG export (one page)
 *   <system-index>  1-based index of which staff system on the page to extract
 *                   (use 1 for the first chord block, 2 for the second, etc.)
 *
 * Output: SVG string to stdout, or error to stderr + exit 1.
 *
 * How it works:
 *   MuseScore exports each staff as a set of <polyline class="StaffLines"> elements.
 *   A standard notation staff has 5 lines; a TAB staff has 6.
 *   We group StaffLines by proximity into "systems", find the 6-line (TAB) staff
 *   within the requested system, determine the bounding box of all elements in
 *   that vertical range, and emit a cropped <svg viewBox="..."> wrapper.
 *
 * The output viewBox includes a small padding around the TAB content so stems
 * and chord symbols above the staff are not clipped.
 */

'use strict';

const fs   = require('fs');
const path = require('path');

// ── Args ──────────────────────────────────────────────────────────────────────

const [,, svgFile, systemIdxArg] = process.argv;

if (!svgFile || !systemIdxArg) {
    process.stderr.write('Usage: node extract-tab-svg.cjs <svg-file> <system-index>\n');
    process.exit(1);
}

const systemIdx = parseInt(systemIdxArg, 10);
if (isNaN(systemIdx) || systemIdx < 1) {
    process.stderr.write('system-index must be a positive integer (1-based)\n');
    process.exit(1);
}

const svgPath = path.resolve(svgFile);
if (!fs.existsSync(svgPath)) {
    process.stderr.write(`File not found: ${svgPath}\n`);
    process.exit(1);
}

// ── Parse ─────────────────────────────────────────────────────────────────────

const content = fs.readFileSync(svgPath, 'utf8');

// Extract the root SVG viewBox dimensions
const rootViewBox = content.match(/<svg[^>]+viewBox="([^"]+)"/);
if (!rootViewBox) {
    process.stderr.write('Could not find root SVG viewBox\n');
    process.exit(1);
}
const [vbX, vbY, vbW, vbH] = rootViewBox[1].split(/\s+/).map(Number);

// ── Find all StaffLines y-coordinates ─────────────────────────────────────────
// Each <polyline class="StaffLines" ... points="x1,y1 x2,y2"/> encodes one staff line.
// We only need the y value (all points on a line share the same y).

const staffLineRe = /<polyline[^>]+class="StaffLines"[^>]+points="[^,]+,([\d.]+)/g;
const allYs = [];
let m;
while ((m = staffLineRe.exec(content)) !== null) {
    allYs.push(parseFloat(m[1]));
}

if (allYs.length === 0) {
    process.stderr.write('No StaffLines found in SVG\n');
    process.exit(1);
}

// ── Group into staff systems ──────────────────────────────────────────────────
// Lines within 500 units of each other belong to the same system.
// Within a system, further group into individual staves (lines within 200 units).

const sorted = [...new Set(allYs)].sort((a, b) => a - b);

function groupByProximity(ys, gap) {
    const groups = [];
    let current = [ys[0]];
    for (let i = 1; i < ys.length; i++) {
        if (ys[i] - current[current.length - 1] < gap) {
            current.push(ys[i]);
        } else {
            groups.push(current);
            current = [ys[i]];
        }
    }
    groups.push(current);
    return groups;
}

// Top-level: split into systems (large gaps between systems ~1800+ units)
const systems = groupByProximity(sorted, 1200);

// Find all systems that contain a TAB staff (6-line stave).
// systemIdx is 1-based over TAB-containing systems only, so callers
// can say "1st TAB on this page" without knowing the full system count.
const tabSystems = systems
    .map(sys => {
        const staves = groupByProximity(sys, 300);
        const tabStave = staves.find(s => s.length === 6);
        return tabStave || null;
    })
    .filter(Boolean);

if (tabSystems.length === 0) {
    process.stderr.write('No TAB staff found in this SVG page\n');
    process.exit(1);
}

if (systemIdx > tabSystems.length) {
    process.stderr.write(`Requested TAB system ${systemIdx} but only ${tabSystems.length} TAB system(s) on this page\n`);
    process.exit(1);
}

const tabStave = tabSystems[systemIdx - 1];

const tabTop    = Math.min(...tabStave);
const tabBottom = Math.max(...tabStave);

// ── Determine crop bounding box ───────────────────────────────────────────────
// Extend upward to capture chord symbols/stems above the TAB staff.
// MuseScore places chord names roughly 800-1200 units above the top staff line.
// We use a generous 1400-unit upward padding and 300-unit downward padding.

const PAD_TOP    = 1400;
const PAD_BOTTOM = 300;
const PAD_SIDES  = 40;  // small horizontal margin

const cropX  = vbX + PAD_SIDES;
const cropY  = tabTop - PAD_TOP;
const cropW  = vbW - 2 * PAD_SIDES;
const cropH  = (tabBottom - tabTop) + PAD_TOP + PAD_BOTTOM;

// ── Emit cropped SVG ──────────────────────────────────────────────────────────
// We wrap the entire original SVG content in an inner <svg> with the crop viewBox.
// This avoids re-parsing — the browser/renderer clips via the outer viewport.

// Strip the XML declaration and root <svg ...> ... </svg> wrapper,
// keeping only the inner content.
const innerContent = content
    .replace(/<\?xml[^?]*\?>\s*/i, '')
    .replace(/<svg[^>]*>/, '')
    .replace(/<\/svg>\s*$/, '');

// Output width: scale so the cropped strip fills a reasonable width.
// We target ~560pt wide (A4 content width minus margins) at 1pt = 1px here;
// callers can override via CSS width:100%.
const OUTPUT_WIDTH  = 560;
const OUTPUT_HEIGHT = Math.round(OUTPUT_WIDTH * cropH / cropW);

const out = [
    `<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"`,
    `     viewBox="${cropX.toFixed(2)} ${cropY.toFixed(2)} ${cropW.toFixed(2)} ${cropH.toFixed(2)}"`,
    `     width="${OUTPUT_WIDTH}" height="${OUTPUT_HEIGHT}"`,
    `     style="display:block;max-width:100%;height:auto">`,
    `  <svg viewBox="${vbX} ${vbY} ${vbW} ${vbH}" width="${vbW}" height="${vbH}">`,
    innerContent,
    `  </svg>`,
    `</svg>`,
].join('\n');

process.stdout.write(out);
