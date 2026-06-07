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
 *   sbnRenderFretboard(voicing, opts)         — HTML fretboard grid string (one frame)
 *   sbnHydrateFretboard(container, data)      — full hydration: insert HTML + wire sequence/GT
 *   sbnHydrateAll(container)                  — batch hydrate all [data-fretboard] els
 *   sbnFormatChordHtml(name)                  — chord name → semantic HTML
 *   sbnToast(message, type)                   — toast notification
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
             + '" stroke="var(--clr-text)" stroke-width="0.4" opacity="0.4"/>';
    }

    // String lines — flush at top (nut), extend 5px below bottom fret
    var strTop = (position <= 3) ? top : (top - 6);
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
// HTML FRETBOARD — render + hydrate
//
// sbnRenderFretboard(data) → HTML string of the full horizontal
//   fretboard scaffold.  data = {
//     voicings      : [{label, frets, fingers, interval_labels}],
//     fret_count    : 12,
//     start_fret    : 1,
//     show_guide_tones : false,
//     show_rh_fingers  : false,
//     display_mode  : 'chord'|'scale'|'sequence',
//     theme         : 'dark'|'light'
//   }
//
// sbnHydrateFretboard(container, data) — inserts the HTML into
//   container, then wires up sequence stepping if needed.
//   Called by sbnHydrateAll for every [data-fretboard] element.
// ─────────────────────────────────────────────────────────────

var SBN_FRET_MARKERS = [3, 5, 7, 9, 12];

/**
 * Assign right-hand fingers (p i m a) to played strings.
 * frets: 6-element array (int or 'x'), index 0 = low E.
 * Returns {stringIdx: 'p'|'i'|'m'|'a'}.
 */
function _sbnRhFingers(frets) {
    var fingers = {};
    var played = [];
    frets.forEach(function(f, idx) {
        if (f !== 'x' && f !== 'X') played.push(idx);
    });
    played.sort(function(a, b) { return a - b; });
    if (!played.length) return fingers;
    fingers[played[0]] = 'p';
    var upper = played.slice(1).slice(-3);
    ['i', 'm', 'a'].forEach(function(name, i) {
        if (upper[i] !== undefined) fingers[upper[i]] = name;
    });
    return fingers;
}

/**
 * Render the full fretboard HTML for one voicing frame.
 *
 * Supports two storage formats:
 *   Chord/sequence — voicing.frets string ("x32010"), one dot per string.
 *   Scale          — voicing.dots array ([{s, f, finger}]), multiple dots per string.
 *
 * @param {object} voicing  {frets, fingers, interval_labels, label, dots}
 * @param {object} opts     {fret_count, start_fret, show_guide_tones, show_rh_fingers, display_mode}
 * @returns {string} HTML
 */
function sbnRenderFretboard(voicing, opts) {
    opts = opts || {};
    var numFrets    = opts.fret_count || 12;
    var showGT      = !!opts.show_guide_tones;
    var showRH      = !!opts.show_rh_fingers;
    var startFret   = parseInt(opts.start_fret) || 1;
    var isScale     = opts.display_mode === 'scale';

    var ivStr    = (voicing && voicing.interval_labels) || '';
    var ivLabels = ivStr ? ivStr.split(',').map(function(s) { return s.trim(); }) : [];
    while (ivLabels.length < 6) ivLabels.push('');

    // Build a dotMap: key "s_f" → finger, works for both formats
    var dotMap = {};
    var openStrings  = {};
    var mutedStrings = {};

    if (isScale && voicing && Array.isArray(voicing.dots)) {
        // Scale format: dots array
        voicing.dots.forEach(function(d) {
            dotMap[d.s + '_' + d.f] = d.finger || 0;
        });
    } else {
        // Chord/sequence format: frets string
        var fretStr   = (voicing && voicing.frets)   || 'xxxxxx';
        var fingerStr = (voicing && voicing.fingers) || '000000';
        var frets   = sbnParseFretString(fretStr, startFret);
        var fingers = fingerStr.split ? fingerStr.split('') : [];
        frets.forEach(function(fv, si) {
            if (fv === 'x' || fv === 'X') {
                mutedStrings[si] = true;
            } else if (fv === 0 || fv === '0') {
                openStrings[si] = true;
            } else {
                var fn   = fingers[si];
                var fnum = parseInt(fv);
                if (!isNaN(fnum)) {
                    dotMap[si + '_' + fnum] = (fn === 'T' || fn === 't') ? 'T' : (parseInt(fn) || 0);
                }
            }
        });
    }

    // Build flat frets array for RH finger assignment (chord mode only)
    var fretsForRH = Array.from({length:6}, function(_,si) {
        return mutedStrings[si] ? 'x' : openStrings[si] ? 0 : 'x';
    });
    Object.keys(dotMap).forEach(function(key) {
        var si = parseInt(key.split('_')[0]);
        fretsForRH[si] = parseInt(key.split('_')[1]);
    });
    var rhFingers = showRH ? _sbnRhFingers(fretsForRH) : {};

    var stringNames = ['e', 'B', 'G', 'D', 'A', 'E']; // display top→bottom (high→low)
    var h = '';

    // String labels
    h += '<div class="sbn-fretboard-string-labels">';
    stringNames.forEach(function(name, di) {
        var si = 5 - di;
        h += '<div class="sbn-fretboard-string-label" data-string="' + si + '">' + name + '</div>';
    });
    h += '</div>';

    // Open column (hidden for scale mode — whole neck is in play)
    if (!isScale) {
        h += '<div class="sbn-fretboard-open-fret">';
        stringNames.forEach(function(name, di) {
            var si = 5 - di;
            var isOpen  = !!openStrings[si];
            var isMuted = !!mutedStrings[si];
            var cls = 'sbn-fretboard-string sbn-fretboard-open-string';
            if (isMuted) cls += ' is-muted';
            h += '<div class="' + cls + '" data-string="' + si + '">';
            if (isOpen) {
                var ivLabel = ivLabels[si] || '';
                var gtCls = showGT ? _sbnGtClass(ivLabel) : '';
                h += '<div class="sbn-fretboard-open-dot' + (gtCls ? ' ' + gtCls : '') + '">';
                if (showGT && gtCls && ivLabel) h += '<span style="font-size:7px;font-weight:800;">' + ivLabel + '</span>';
                h += '</div>';
            }
            h += '</div>';
        });
        h += '</div>';

        // Nut
        h += '<div class="sbn-fretboard-nut"></div>';
    }

    // Fret columns
    h += '<div class="sbn-fretboard-frets">';

    // Position label when not starting at nut
    if (startFret > 1) {
        h += '<div style="display:flex;flex-direction:column;justify-content:center;padding:0 5px;color:#666;font-size:10px;font-weight:500;align-self:center;flex-shrink:0;">' + startFret + 'fr</div>';
    }

    for (var f = startFret; f < startFret + numFrets; f++) {
        var hasMarker = SBN_FRET_MARKERS.indexOf(f) !== -1;
        var isDouble  = f === 12 || f === 24;
        var fretCls = 'sbn-fretboard-fret';
        if (hasMarker) fretCls += ' has-marker';
        if (isDouble)  fretCls += ' double-marker';
        h += '<div class="' + fretCls + '" data-fret="' + f + '">';

        stringNames.forEach(function(name, di) {
            var si     = 5 - di;
            var key    = si + '_' + f;
            var hasDot = dotMap[key] !== undefined;
            var strCls = 'sbn-fretboard-string';
            if (hasDot) strCls += ' has-dot';
            h += '<div class="' + strCls + '" data-string="' + si + '">';
            if (hasDot) {
                var finger    = dotMap[key];
                var ivLabel   = isScale ? (ivLabels[si] || '') : (ivLabels[si] || '');
                var gtCls     = showGT ? _sbnGtClass(ivLabel) : '';
                var dotCls    = 'sbn-fretboard-dot';
                if (gtCls)                    dotCls += ' ' + gtCls;
                if (!gtCls && finger && finger !== '0' && finger !== 0) dotCls += ' has-finger';
                h += '<div class="' + dotCls + '">';
                if (showGT && gtCls && ivLabel) {
                    h += ivLabel;
                } else if (!showGT && finger && finger !== '0' && finger !== 0) {
                    h += finger;
                }
                h += '</div>';
            }
            h += '</div>';
        });

        var showNum = [1,3,5,7,9,12,15,17,19,21,24].indexOf(f) !== -1;
        if (showNum) h += '<div class="sbn-fretboard-fret-num">' + f + '</div>';

        h += '</div>';
    }
    h += '</div>'; // .sbn-fretboard-frets

    // RH fingers column
    if (showRH) {
        h += '<div class="sbn-fretboard-rh-fingers">';
        stringNames.forEach(function(name, di) {
            var si = 5 - di;
            var fv = frets[si];
            var isMuted = fv === 'x' || fv === 'X';
            var rh = rhFingers[si] || '';
            var cls = 'sbn-fretboard-rh-finger';
            if (isMuted) cls += ' is-muted';
            h += '<div class="' + cls + '" data-string="' + si + '">' + rh + '</div>';
        });
        h += '</div>';
    }

    return h;
}

/** Map interval label to guide-tone CSS class. */
function _sbnGtClass(label) {
    if (!label) return '';
    if (label === 'R')                                  return 'gt-root';
    if (label === '5' || label === 'b5')                return 'gt-fifth';
    if (label === 'b7' || label === '7' || label === 'maj7') return 'gt-seventh';
    if (label === '3' || label === 'b3')                return 'gt-third';
    if (/^(b?9|#9|11|#11|13|b13)$/.test(label))        return 'gt-ninth';
    return '';
}

/**
 * Hydrate a single container element with the fretboard for the given data.
 * Adds sequence step navigation for display_mode = 'sequence'.
 *
 * @param {Element} container
 * @param {object}  data  — full fretboard record (voicings[], display_mode, theme, …)
 */
function sbnHydrateFretboard(container, data) {
    if (!container || !data) return;
    if (container.dataset.sbnRendered) return; // guard against double-hydration
    container.dataset.sbnRendered = '1';

    var voicings = data.voicings || [];
    if (!voicings.length) voicings = [{ label: '', frets: 'xxxxxx', fingers: '000000', interval_labels: '' }];

    var isSequence = data.display_mode === 'sequence' && voicings.length > 1;
    var currentIdx = 0;

    var theme = (data.theme === 'light') ? 'light' : 'dark';
    container.classList.add('sbn-fretboard-wrap', 'theme-' + theme);

    var opts = {
        fret_count:       data.fret_count    || 12,
        start_fret:       data.start_fret    || 1,
        show_guide_tones: data.show_guide_tones || false,
        show_rh_fingers:  data.show_rh_fingers  || false,
        display_mode:     data.display_mode  || 'chord',
    };

    function renderFrame(idx) {
        var v = voicings[idx] || voicings[0];

        // Header
        var headerHtml = '<div class="sbn-fretboard-header">';
        headerHtml += '<span class="sbn-fretboard-header-label">' + (v.label || '') + '</span>';
        if (isSequence) {
            headerHtml += '<div class="sbn-fretboard-steps">'
                + '<button class="sbn-fretboard-step-btn" data-dir="-1"' + (idx === 0 ? ' disabled' : '') + '>‹</button>'
                + '<span class="sbn-fretboard-step-counter">' + (idx + 1) + '/' + voicings.length + '</span>'
                + '<button class="sbn-fretboard-step-btn" data-dir="1"' + (idx === voicings.length - 1 ? ' disabled' : '') + '>›</button>'
                + '</div>';
        }
        if (data.show_guide_tones) {
            var gtActive = opts.show_guide_tones ? ' is-active' : '';
            headerHtml += '<button class="sbn-fretboard-guide-toggle' + gtActive + '" data-action="toggle-gt" title="Guide tones">GT</button>';
        }
        headerHtml += '</div>';

        var gridHtml = '<div class="sbn-fretboard"><div class="sbn-fretboard-grid">'
            + sbnRenderFretboard(v, opts)
            + '</div></div>';

        container.innerHTML = headerHtml + gridHtml;

        // Bind step buttons
        container.querySelectorAll('.sbn-fretboard-step-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var dir = parseInt(btn.dataset.dir);
                currentIdx = Math.max(0, Math.min(voicings.length - 1, currentIdx + dir));
                renderFrame(currentIdx);
            });
        });

        // Bind guide-tone toggle
        var gtBtn = container.querySelector('[data-action="toggle-gt"]');
        if (gtBtn) {
            gtBtn.addEventListener('click', function() {
                opts.show_guide_tones = !opts.show_guide_tones;
                renderFrame(currentIdx);
            });
        }
    }

    renderFrame(0);
}

// ─────────────────────────────────────────────────────────────
// BATCH HYDRATE
// ─────────────────────────────────────────────────────────────

function sbnHydrateAll(container) {
    var root = container || document;
    root.querySelectorAll('[data-fretboard]').forEach(function(el) {
        try {
            var data = JSON.parse(el.getAttribute('data-fretboard'));
            sbnHydrateFretboard(el, data);
        } catch (e) { console.warn('sbnHydrateAll: bad data-fretboard JSON', e); }
    });
}

// ─────────────────────────────────────────────────────────────
// AUTO-HYDRATE ON DOM READY
// ─────────────────────────────────────────────────────────────

document.addEventListener('DOMContentLoaded', function() {
    sbnHydrateAll();
});
