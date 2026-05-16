/**
 * SBN Chord Diagrams
 *
 * Single source of truth for chord diagram rendering across
 * admin and public pages.
 *
 * Public API
 * ──────────
 *   sbnRenderDiagramSVG(voicing, opts)   — SVG string (transparent bg, fluid)
 *   sbnRenderMiniDiagramSVG(voicing)     — alias, default size 56px viewBox
 *   sbnRenderFretboard(data)             — HTML fretboard string
 *   sbnHydrateFretboard(container, data) — place dots/barres after DOM insert
 *   sbnHydrateAll(container)             — batch hydrate all [data-diagram] els
 *   sbnFormatChordHtml(name)             — chord name → semantic HTML
 *   sbnToast(message, type)              — toast notification
 *
 * SVG diagrams use a fixed viewBox with width="100%" height="auto"
 * so CSS (the parent grid) controls all sizing. No pixel dimensions
 * on the SVG element itself.
 *
 * HTML fretboard uses .sbn-fretboard-* class names (defined in
 * sbn-design-system.css §2b). Legacy .sbn-fb-* aliases remain in
 * chords.css until all blade templates are updated.
 *
 * Hydration guard attribute: data-sbn-rendered="1"
 * (unified from legacy data-sbnRendered and data-sbn-hydrated)
 *
 * String numbering: 1=Low E … 6=High E (left-to-right in fret string)
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
// Guide tone colour palette (amber = 7ths, blue = 3rds, gray = root, purple = 9ths, mid-gray = 5ths)
var GT_COLORS = {
    seventh: { fill: '#d97706', text: '#fff' },   // b7 7 maj7
    third:   { fill: '#2563eb', text: '#fff' },   // 3 b3
    root:    { fill: '#e8e8e0', text: '#333' },   // R
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
    if (l === '9' || l === 'b9' || l === '#9' || l === '11' || l === '#11' || l === '13' || l === 'b13') return GT_COLORS.ninth;
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

    // Fixed coordinate system — CSS scales via width="100%"
    var W = 88, H = 95;
    var strSp = 12, fretSp = 16;
    var left = 14, top = 12, numFrets = 4;

    var svg = '<svg class="sbn-chord-svg" viewBox="0 0 ' + W + ' ' + H + '"'
            + ' width="100%">';

    // Nut or position number
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
             + '" stroke="var(--clr-text)" stroke-width="0.4" opacity="0.4"/>';
    }

    // String lines — flush at top (nut), extend 5px below bottom fret
    var strTop = (position <= 1) ? top : (top - 6);
    for (var s = 0; s < 6; s++) {
        svg += '<line x1="' + (left + s * strSp) + '" y1="' + strTop
             + '" x2="' + (left + s * strSp) + '" y2="' + (top + fretSp * numFrets + 5)
             + '" stroke="var(--clr-text)" stroke-width="0.4" opacity="0.5"/>';
    }

    // Markers: ×, ○, dot
    frets.forEach(function(fretVal, i) {
        var x = left + i * strSp;
        var finger = (voicing.fingers || '000000')[i];
        var ivLabel = intervalArr[i] || '';
        var gtColor = showGuideTones ? sbnGtColorForInterval(ivLabel) : null;

        if (fretVal === 0) {
            // Open string: draw a small colored circle (or plain ring)
            if (gtColor) {
                svg += '<circle cx="' + x + '" cy="' + (top - 8)
                     + '" r="4" fill="' + gtColor.fill + '"/>';
                svg += '<text x="' + x + '" y="' + (top - 8)
                     + '" font-size="5" font-weight="800"'
                     + ' text-anchor="middle" dominant-baseline="central"'
                     + ' fill="' + gtColor.text + '">' + ivLabel + '</text>';
            } else {
                svg += '<circle cx="' + x + '" cy="' + (top - 8)
                     + '" r="3" fill="none"'
                     + ' stroke="var(--clr-text)" stroke-width="0.75"/>';
            }
        } else {
            var rf = fretVal - position + 1;
            if (rf > 0 && rf <= numFrets) {
                var y = top + rf * fretSp - fretSp / 2;
                var fill = gtColor ? gtColor.fill : dotColor;
                var labelText = gtColor ? ivLabel : (showFingers && finger && finger !== '0' ? finger : '');
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

/**
 * Convenience alias — same as sbnRenderDiagramSVG.
 * Name kept for call-site compatibility with older templates.
 */
function sbnRenderMiniDiagramSVG(voicing, opts) {
    return sbnRenderDiagramSVG(voicing, opts);
}

// ─────────────────────────────────────────────────────────────
// HTML FRETBOARD RENDERER
// ─────────────────────────────────────────────────────────────

/**
 * Render an HTML fretboard string (structure only, no dots/barres).
 * Call sbnHydrateFretboard() after inserting into DOM to place dots.
 *
 * @param {object} data
 * @param {object|string} data.diagram    — { positions, barres, muted, open }
 * @param {number}        data.startFret
 * @param {string}        data.intervals  — comma-separated interval labels
 * @param {string}        data.notes      — comma-separated note names
 * @param {string}        data.displayMode — 'fingering'|'notes'|'functions'
 * @returns {string} HTML string
 */
function sbnRenderFretboard(data) {
    data = data || {};
    var diagram = data.diagram;
    if (typeof diagram === 'string') {
        try { diagram = JSON.parse(diagram); } catch(e) { diagram = {}; }
    }
    diagram = diagram || {};

    var startFret = parseInt(data.startFret) || 1;
    var FRETS = 4;
    var muted = diagram.muted || [];
    var open  = diagram.open  || [];

    var html = '<div class="sbn-fretboard">';

    if (startFret > 1) {
        html += '<span class="sbn-fret-number">' + startFret + 'fr</span>';
    }

    html += '<div class="sbn-string-indicators">';
    for (var s = 1; s <= 6; s++) {
       if (muted.indexOf(s) !== -1) html += '<span class="sbn-string-indicator muted"></span>';
        else if (open.indexOf(s) !== -1) html += '<span class="sbn-string-indicator open">○</span>';
        else                             html += '<span class="sbn-string-indicator"></span>';
    }
    html += '</div>';

    if (startFret === 1) html += '<div class="sbn-nut"></div>';

    html += '<div class="sbn-frets">';
    for (var f = 0; f < FRETS; f++) {
        var af = startFret + f;
        html += '<div class="sbn-fret-row">';
        for (var ss = 1; ss <= 6; ss++) {
            html += '<div class="sbn-string-space"'
                  + ' data-string="' + ss + '" data-fret="' + af + '"></div>';
        }
        html += '</div>';
    }
    html += '</div></div>';
    return html;
}

/**
 * Place finger dots and barres into a rendered fretboard element.
 * Requires the element to be in the DOM (needs offsetLeft for barres).
 *
 * @param {HTMLElement} container — the element containing .sbn-fretboard
 * @param {object}      data      — same as sbnRenderFretboard
 */
function sbnHydrateFretboard(container, data) {
    if (!container) return;
    data = data || {};
    var diagram = data.diagram;
    if (typeof diagram === 'string') {
        try { diagram = JSON.parse(diagram); } catch(e) { diagram = {}; }
    }
    diagram = diagram || {};

    var startFret   = parseInt(data.startFret) || 1;
    var FRETS       = 4;
    var intervals   = (data.intervals || '').split(',').filter(Boolean);
    var notes       = (data.notes     || '').split(',').filter(Boolean);
    var displayMode = data.displayMode || 'fingering';
    var positions   = diagram.positions || [];
    var barres      = diagram.barres    || [];

    positions.forEach(function(pos) {
        var fi = pos.fret - startFret;
        if (fi < 0 || fi >= FRETS) return;
        var cell = container.querySelector(
            '.sbn-string-space[data-string="' + pos.string + '"][data-fret="' + pos.fret + '"]'
        );
        if (!cell) return;

        var si    = pos.string - 1;
        var label = displayMode === 'fingering'  ? (pos.finger || '') :
                    displayMode === 'notes'       ? (notes[si]    || '') :
                    displayMode === 'functions'   ? (intervals[si] || '') : '';

        var dot = document.createElement('div');
        dot.className = 'sbn-finger-position';
        dot.textContent = label;
        cell.appendChild(dot);
    });

    if (barres.length) {
        requestAnimationFrame(function() {
            barres.forEach(function(barre) {
                var fi = barre.fret - startFret;
                if (fi < 0 || fi >= FRETS) return;
                var row = container.querySelector(
                    '.sbn-fret-row:nth-child(' + (fi + 1) + ')'
                );
                if (!row) return;
                var fromCell = row.querySelector('.sbn-string-space[data-string="' + barre.fromString + '"]');
                var toCell   = row.querySelector('.sbn-string-space[data-string="' + barre.toString   + '"]');
                if (!fromCell || !toCell) return;

                var fl = fromCell.offsetLeft + fromCell.offsetWidth / 2;
                var tl = toCell.offsetLeft   + toCell.offsetWidth   / 2;

                var si    = barre.fromString - 1;
                var label = displayMode === 'fingering'  ? (barre.finger || '') :
                            displayMode === 'notes'       ? (notes[si]    || '') :
                            displayMode === 'functions'   ? (intervals[si] || '') : '';

                var el = document.createElement('div');
                el.className  = 'sbn-barre';
                el.textContent = label;
                el.style.left  = Math.min(fl, tl) + 'px';
                el.style.width = Math.max(Math.abs(tl - fl), 20) + 'px';
                el.style.top   = '50%';
                row.appendChild(el);
            });
        });
    }
}

/**
 * Batch hydrate all [data-diagram] fretboard elements within a container.
 *
 * @param {HTMLElement} [container=document]
 */
function sbnHydrateAll(container) {
    container = container || document;
    container.querySelectorAll('.sbn-chord-fretboard[data-diagram]:not([data-sbn-rendered])').forEach(function(fb) {
        var data = {
            diagram:     fb.getAttribute('data-diagram'),
            startFret:   fb.getAttribute('data-start-fret') || '1',
            intervals:   fb.getAttribute('data-intervals')  || '',
            notes:       fb.getAttribute('data-notes')      || '',
            displayMode: fb.getAttribute('data-display-mode') || 'fingering'
        };
        fb.innerHTML = sbnRenderFretboard(data);
        fb.setAttribute('data-sbn-rendered', '1');
        requestAnimationFrame(function() { sbnHydrateFretboard(fb, data); });
    });
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
// AUTO-HYDRATE ON DOM READY
// ─────────────────────────────────────────────────────────────

document.addEventListener('DOMContentLoaded', function() {
    sbnHydrateAll();
});
