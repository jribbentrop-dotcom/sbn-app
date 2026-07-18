/**
 * SBN Chord Diagrams
 *
 * Single source of truth for chord diagram rendering across
 * admin and public pages.
 *
 * Public API
 * ──────────
 *   sbnRenderDiagramSVG(voicing, opts)        — SVG string (transparent bg, fluid)
 *   sbnRenderMiniDiagramSVG(voicing)          — alias, default size 56px viewBox
 *   sbnFormatChordHtml(name)                  — chord name → semantic HTML
 *   sbnToast(message, type)                   — toast notification
 *
 * Removed (2026-06-30, Phase 3 fretboard-svg-unification):
 *   sbnRenderFretboard / sbnHydrateFretboard / sbnHydrateAll
 *   → replaced by SbnFretboard.vue + FretboardNeck.vue (SVG, light/flat)
 *
 * SVG diagrams use a fixed viewBox with width="100%" height="auto"
 * so CSS (the parent grid) controls all sizing. No pixel dimensions
 * on the SVG element itself.
 *
 * HTML fretboard uses .sbn-fretboard-* class names (defined in
 * public/css/fretboard.css). Hydration target attribute: data-fretboard="{json}".
 *
 * Hydration guard attribute: data-sbn-rendered="1"
 *
 * String numbering: 0=Low E … 5=High e (frets array index)
 */

'use strict';

// ─────────────────────────────────────────────────────────────
// FRET STRING PARSER
// ─────────────────────────────────────────────────────────────

/**
 * Parse a fret string into a 6-element array.
 * Handles standard 6-char strings ("x32010") and multi-digit
 * fret numbers ("x81089x") via scored brute-force partition.
 *
 * @param {string} fretString
 * @param {number} [position=1]
 * @returns {Array} 6 elements: 'x' or integer
 */
function sbnParseFretString(fretString, position) {
    if (!fretString) return ['x','x','x','x','x','x'];
    position = parseInt(position) || 1;

    if (fretString.length <= 6) {
        var result = [];
        for (var i = 0; i < fretString.length; i++) {
            var c = fretString[i];
            result.push((c === 'x' || c === 'X') ? 'x' : parseInt(c, 16));
        }
        while (result.length < 6) result.push('x');
        return result;
    }

    // Multi-digit: find best 6-element partition
    var best = null, bestScore = Infinity;
    _fretSearch(fretString, 0, [], position, function(candidate) {
        var s = _fretScore(candidate, position);
        if (s < bestScore) { bestScore = s; best = candidate.slice(); }
    });
    return best || sbnParseFretString(fretString.substring(0, 6), position);
}

function _fretSearch(s, depth, current, position, cb) {
    if (depth === 6) { if (s.length === 0) cb(current); return; }
    if (s.length === 0) {
        var p = current.slice(); while (p.length < 6) p.push('x'); cb(p); return;
    }
    var c = s[0];
    if (c === 'x' || c === 'X') {
        _fretSearch(s.slice(1), depth + 1, current.concat(['x']), position, cb);
    } else if (c >= '0' && c <= '9') {
        _fretSearch(s.slice(1), depth + 1, current.concat([parseInt(c)]), position, cb);
        if (s.length >= 2 && s[1] >= '0' && s[1] <= '9') {
            _fretSearch(s.slice(2), depth + 1, current.concat([parseInt(s.slice(0,2))]), position, cb);
        }
    } else {
        _fretSearch(s.slice(1), depth, current, position, cb);
    }
}

function _fretScore(parse, position) {
    var score = 0;
    var sounding = parse.filter(function(f) { return f !== 'x'; });
    if (!sounding.length) return 0;
    if (position > 1) {
        sounding.forEach(function(f) { var d = Math.abs(f - position); score += d * d; });
    } else {
        var nonzero = sounding.filter(function(f) { return f > 0; });
        if (nonzero.length) {
            var span = Math.max.apply(null, sounding) - Math.min.apply(null, nonzero);
            if (span > 5) score += (span - 5) * 100;
        }
    }
    sounding.forEach(function(f) { if (f > 24 || f < 0) score += 10000; });
    return score;
}

// ─────────────────────────────────────────────────────────────
// SVG DIAGRAM RENDERER
// ─────────────────────────────────────────────────────────────

/**
 * Render a chord voicing as an inline SVG string.
 *
 * The SVG uses a fixed viewBox (80×95) with width="100%" height="auto"
 * so the parent CSS grid controls all sizing. No fixed pixel dimensions.
 * Background is transparent — the CSS card shell (.sbn-diagram-card /
 * .sbn-vp-card) provides the white background.
 *
 * @param {object} voicing  — { frets, position } or { fret_string, start_fret }
 * @param {object} [opts]
 * @param {string} [opts.dotColor]     — dot fill color (default: var(--clr-red))
 * @param {boolean} [opts.showFingers] — show finger numbers (default: true)
 * @returns {string} SVG markup
 */
// Guide tone colour palette (amber = 7ths, blue = 3rds, green = root, purple = 9ths, mid-gray = 5ths)
var GT_COLORS = {
    seventh: { fill: '#d97706', text: '#fff' },   // b7 7 maj7
    third:   { fill: '#2563eb', text: '#fff' },   // 3 b3
    root:    { fill: '#16a34a', text: '#fff' },   // R
    ninth:   { fill: '#7c3aed', text: '#fff' },   // 9 b9 #9
    fifth:   { fill: '#6b7280', text: '#fff' },   // 5 b5
};

function sbnGtColorForInterval(label) {
    if (!label || label === 'x' || label === 'X') return null;
    var l = label.trim();
    if (l === 'R') return GT_COLORS.root;
    if (l === '5' || l === 'b5' || l === '#5') return GT_COLORS.fifth;
    if (l === 'b7' || l === '7' || l === 'maj7' || l === 'bb7') return GT_COLORS.seventh;
    if (l === '3' || l === 'b3') return GT_COLORS.third;
    if (l === '9' || l === 'b9' || l === '#9' || l === '2'
     || l === '11' || l === '#11' || l === '4'
     || l === '13' || l === 'b13' || l === '6') return GT_COLORS.ninth;
    return null;
}

function sbnRenderDiagramSVG(voicing, opts) {
    if (!voicing) return '';
    var fretStr  = voicing.frets || voicing.fret_string;
    var position = parseInt(voicing.position || voicing.start_fret) || 1;
    if (!fretStr) return '';

    opts = opts || {};
    var dotColor     = opts.dotColor || 'var(--clr-red)';
    var showFingers  = opts.showFingers !== false;
    var showGuideTones = !!opts.intervalLabels;
    // Parse positional interval labels ("x,R,5,b7,b3,x") into a 6-element array
    var intervalArr  = showGuideTones
        ? opts.intervalLabels.split(',').map(function(s) { return s.trim(); })
        : [];
    while (intervalArr.length < 6) intervalArr.push('');

    var frets = sbnParseFretString(fretStr, position);

    // If all fretted notes fit within frets 1-4, it's a first-position chord
    // regardless of what start_fret says (start_fret is sometimes set to the
    // minimum fretted note rather than the true grid anchor).
    var maxFret = 0;
    var hasOpen = false;
    frets.forEach(function(f) {
        if (f === 0) hasOpen = true;
        else if (f !== 'x' && f > maxFret) maxFret = f;
    });
    // Force nut only when all fretted notes fit in frets 1-4 AND there are open strings.
    // A movable shape with no open strings at low frets should show a position marker.
    if (maxFret > 0 && maxFret <= 4 && hasOpen) position = 1;

    // Fixed coordinate system — CSS scales via width="100%"
    var W = 88, H = 98;
    var strSp = 12, fretSp = 16;
    var left = 14, top = 16, numFrets = 4;

    var svg = '<svg class="sbn-chord-svg" viewBox="0 0 ' + W + ' ' + H + '"'
            + ' width="100%">';

    // Nut for position 1 only; position label for all other positions
    if (position <= 1) {
        svg += '<rect x="' + (left - 1) + '" y="' + (top - 3)
             + '" width="' + (strSp * 5 + 2) + '" height="3"'
             + ' fill="var(--clr-text)" rx="0.5"/>';
    } else {
        svg += '<text x="1" y="' + (top + fretSp / 2 + 4)
             + '" font-size="10" fill="var(--clr-text-muted)">' + position + '</text>';
    }

    // Fret lines
    for (var f = 0; f <= numFrets; f++) {
        svg += '<line x1="' + left + '" y1="' + (top + f * fretSp)
             + '" x2="' + (left + strSp * 5) + '" y2="' + (top + f * fretSp)
             + '" stroke="var(--clr-text)" stroke-width="1" opacity="0.55" vector-effect="non-scaling-stroke"/>';
    }

    // String lines — flush at top (nut), extend 5px below bottom fret
    var strTop = (position <= 3) ? top : (top - 6);
    for (var s = 0; s < 6; s++) {
        svg += '<line x1="' + (left + s * strSp) + '" y1="' + strTop
             + '" x2="' + (left + s * strSp) + '" y2="' + (top + fretSp * numFrets + 5)
             + '" stroke="var(--clr-text)" stroke-width="1" opacity="0.65" vector-effect="non-scaling-stroke"/>';
    }

    // Markers: ×, ○, dot
    frets.forEach(function(fretVal, i) {
        var x = left + i * strSp;
        var finger = (voicing.fingers || '000000')[i];
        var ivLabel = intervalArr[i] || '';
        var gtColor = showGuideTones ? sbnGtColorForInterval(ivLabel) : null;

        if (fretVal === 0) {
            // Open strings: always white fill + coloured border (interval colour or default dark).
            // White interior distinguishes them from fretted filled dots.
            var openY = top - 6;
            var openStroke = gtColor ? gtColor.fill : 'var(--clr-text-dim, #555)';
            svg += '<circle class="sbn-svg-dot" data-string="' + (i + 1) + '"'
                 + ' cx="' + x + '" cy="' + openY
                 + '" r="4" fill="#fff" stroke="' + openStroke + '" stroke-width="1.5"/>';
        } else {
            var rf = fretVal - position + 1;
            if (rf > 0 && rf <= numFrets) {
                var y = top + rf * fretSp - fretSp / 2;
                var fill = gtColor ? gtColor.fill : dotColor;
                var labelText = showFingers && finger && finger !== '0' ? finger : '';
                var labelColor = gtColor ? gtColor.text : '#fff';
                svg += '<circle class="sbn-svg-dot" data-string="' + (i + 1) + '"'
                     + ' cx="' + x + '" cy="' + y
                     + '" r="4.5" fill="' + fill + '" opacity="1"/>';
                if (labelText) {
                    svg += '<text x="' + x + '" y="' + y
                         + '" font-size="5.5" font-weight="800"'
                         + ' text-anchor="middle" dominant-baseline="central" fill="' + labelColor + '"'
                         + ' style="pointer-events:none">' + labelText + '</text>';
                }
            }
        }
    });

    svg += '</svg>';
    return svg;
}

// ─────────────────────────────────────────────────────────────
// CHORD NAME FORMATTER
// ─────────────────────────────────────────────────────────────

/**
 * Format a raw chord name string into semantic HTML.
 *   "Cmaj7"  → <span class="sbn-chord-root">C</span><span class="sbn-chord-ext">maj7</span>
 *   "Dm7/A"  → …<span class="sbn-chord-bass">/A</span>
 *
 * Wrapped in .sbn-chord-symbol for typography.
 *
 * @param {string} name
 * @returns {string} HTML
 */
function sbnFormatChordHtml(name) {
    if (!name) return '';
    function esc(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;')
                        .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    var slashIdx = name.indexOf('/');
    var bassPart = '', mainPart = name;
    if (slashIdx > 0) {
        bassPart = name.slice(slashIdx);
        mainPart = name.slice(0, slashIdx);
    }

    var root = mainPart[0];
    var rest = mainPart.slice(1);
    var accidental = '';
    if (rest[0] === '#' || rest[0] === 'b') {
        accidental = rest[0];
        rest = rest.slice(1);
    }

    // Internal "dom" quality → conventional dominant spelling:
    //   dom7 → 7, dom7(9) → 7(9), dom9 → 9, dom13 → 13, bare dom → 7.
    rest = rest.replace(/^dom7/i, '7').replace(/^dom(\d)/i, '$1').replace(/^dom(?=\(|$)/i, '7');

    var html = '<span class="sbn-chord-symbol">';
    html += '<span class="sbn-chord-root">'        + esc(root)       + '</span>';
    if (accidental) html += '<span class="sbn-chord-accidental">' + esc(accidental) + '</span>';
    if (rest)       html += '<span class="sbn-chord-ext">'        + esc(rest)       + '</span>';
    if (bassPart)   html += '<span class="sbn-chord-bass">'       + esc(bassPart)   + '</span>';
    html += '</span>';
    return html;
}

// ─────────────────────────────────────────────────────────────
// TOAST
// ─────────────────────────────────────────────────────────────

function sbnToast(message, type) {
    type = type || 'info';
    var toast = document.createElement('div');
    toast.className = 'sbn-toast sbn-toast-' + type;
    toast.textContent = message;
    document.body.appendChild(toast);
    setTimeout(function() {
        toast.style.opacity = '0';
        toast.style.transform = 'translateY(16px)';
        setTimeout(function() { toast.remove(); }, 300);
    }, 3000);
}

// ─────────────────────────────────────────────────────────────
// HTML FRETBOARD — REMOVED (Phase 3 of PLAN-fretboard-svg-unification.md)
//
// sbnRenderFretboard, sbnHydrateFretboard, sbnHydrateAll, _sbnRhFingers,
// _sbnGtClass, SBN_FRET_MARKERS were removed 2026-06-30.
//
// The <sbn-fretboard> tag is now rendered by SbnFretboard.vue (SVG, light/flat,
// matching the progression viewer aesthetic). mountSbnNodes.ts handles it via
// the standard Vue mount path. The orphaned resources/views/components/course/
// fretboard.blade.php ([data-fretboard] path) was never used in production and
// remains untouched (it is dead code predating this refactor).
//
// Grep evidence of removal (all returned zero matches, excluding chords.js
// and fretboard.css themselves):
//   sbnRenderFretboard  — 0 callers in *.js/*.vue/*.ts/*.php/*.blade.php
//   sbnHydrateFretboard — 0 callers (was only in mountSbnNodes.ts, now deleted)
//   sbnHydrateAll       — 0 external callers
// ─────────────────────────────────────────────────────────────
