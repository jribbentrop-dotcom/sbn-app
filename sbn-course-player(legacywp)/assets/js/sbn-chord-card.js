/**
 * SBN Shared Chord Card Component
 * 
 * A single source of truth for rendering chord diagrams and chord cards
 * across the entire SoulBossaNova platform. Used by:
 *   - Chord Library (grid of cards)
 *   - Leadsheet Player (inline diagrams + detail panel)
 *   - Song Library (chord previews)
 *   - Course pages (embedded chords)
 * 
 * Architecture:
 *   SbnChordCard.renderDiagram()  — SVG fretboard (the core primitive)
 *   SbnChordCard.renderCard()     — Full card with name, diagram, controls
 *   SbnChordCard.renderFretboard()— HTML fretboard with info-in-dots toggling
 *   SbnChordCard.createCard()     — Returns a live DOM element with events wired
 * 
 * No jQuery dependency. Uses vanilla JS and DOM APIs.
 * 
 * @version 1.0.0
 */
(function(root) {
    'use strict';

    // =========================================================================
    // CONSTANTS & HELPERS
    // =========================================================================

    var BRAND_ORANGE = '#e85d3b';
    var BRAND_ORANGE_LIGHT = '#ff6b4a';

    var QUALITY_LABELS = {
        'maj7': 'Major 7', 'dom7': 'Dominant 7', 'm7': 'Minor 7',
        'm7b5': 'Half Diminished', 'dim7': 'Diminished 7', 'min': 'Minor',
        'maj': 'Major', 'aug': 'Augmented', 'sus2': 'Suspended 2',
        'sus4': 'Suspended 4', '6': 'Major 6', 'm6': 'Minor 6',
        '9': 'Dominant 9', 'maj9': 'Major 9', 'm9': 'Minor 9'
    };

    var DIFFICULTY_LABELS = {
        1: 'Beginner', 2: 'Easy', 3: 'Intermediate', 4: 'Advanced', 5: 'Expert'
    };

    var VOICING_LABELS = {
        'drop2': 'Drop 2', 'drop3': 'Drop 3', 'shell': 'Shell',
        'rootless': 'Rootless', 'closed': 'Closed', 'open': 'Open',
        'custom': 'Custom'
    };

    var INVERSION_LABELS = {
        'root': 'Root Position', 'inv1': '1st Inv', 'inv2': '2nd Inv', 'inv3': '3rd Inv'
    };

    var ROOT_STRING_LABELS = {
        'roote': 'Root on E', 'roota': 'Root on A', 'rootd': 'Root on D', 'rootg': 'Root on G'
    };

    function escapeHtml(str) {
        if (!str) return '';
        return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    /**
     * Parse a fret string into a 6-element array, handling multi-digit frets.
     * 
     * Standard 6-char strings (e.g., "x02010") are parsed character-by-character.
     * Longer strings (e.g., "x81089x") contain multi-digit frets and are parsed
     * by finding the best 6-element partition using position context.
     * 
     * @param {string} fretString - The fret string (e.g., "x81089x")
     * @param {number} [position=1] - Starting position hint from @position
     * @returns {Array} Array of 6 elements: 'x' or integer fret number
     */
    function parseFretString(fretString, position) {
        if (!fretString) return ['x','x','x','x','x','x'];
        position = parseInt(position) || 1;
        
        // Quick path: 6 chars or fewer — unambiguous single-digit
        if (fretString.length <= 6) {
            var result = [];
            for (var i = 0; i < fretString.length; i++) {
                var c = fretString.charAt(i);
                if (c === 'x' || c === 'X') {
                    result.push('x');
                } else {
                    result.push(parseInt(c));
                }
            }
            while (result.length < 6) result.push('x');
            return result;
        }
        
        // Multi-digit case: brute-force best 6-element partition
        var best = null;
        var bestScore = Infinity;
        
        _fretParseSearch(fretString, 0, [], position, function(candidate) {
            var score = _fretParseScore(candidate, position);
            if (score < bestScore) {
                bestScore = score;
                best = candidate.slice();
            }
        });
        
        if (best) return best;
        
        // Fallback: truncate to 6 chars
        return parseFretString(fretString.substring(0, 6), position);
    }

    /** Recursive search for best 6-element partition */
    function _fretParseSearch(s, depth, current, position, onComplete) {
        if (depth === 6) {
            if (s.length === 0) onComplete(current);
            return;
        }
        
        if (s.length === 0) {
            var padded = current.slice();
            while (padded.length < 6) padded.push('x');
            onComplete(padded);
            return;
        }
        
        var c = s.charAt(0);
        
        if (c === 'x' || c === 'X') {
            _fretParseSearch(s.substring(1), depth + 1, current.concat(['x']), position, onComplete);
        } else if (c >= '0' && c <= '9') {
            // Try single digit
            _fretParseSearch(s.substring(1), depth + 1, current.concat([parseInt(c)]), position, onComplete);
            
            // Try two digits if available
            if (s.length >= 2 && s.charAt(1) >= '0' && s.charAt(1) <= '9') {
                var two = parseInt(s.substring(0, 2));
                _fretParseSearch(s.substring(2), depth + 1, current.concat([two]), position, onComplete);
            }
        } else {
            // Skip unexpected characters
            _fretParseSearch(s.substring(1), depth, current, position, onComplete);
        }
    }

    /** Score a parse: lower is better */
    function _fretParseScore(parse, position) {
        var score = 0;
        var sounding = [];
        
        for (var i = 0; i < parse.length; i++) {
            if (parse[i] !== 'x') sounding.push(parse[i]);
        }
        
        if (sounding.length === 0) return 0;
        
        if (position > 1) {
            // Position provided: prefer frets near position
            for (var j = 0; j < sounding.length; j++) {
                var dist = Math.abs(sounding[j] - position);
                score += dist * dist;
            }
        } else {
            // No position: prefer compact voicings (small fret span)
            var nonzero = sounding.filter(function(f) { return f > 0; });
            if (nonzero.length > 0) {
                var span = Math.max.apply(null, sounding) - Math.min.apply(null, nonzero);
                if (span > 5) score += (span - 5) * 100;
            }
        }
        
        // Penalize implausible fret numbers
        for (var k = 0; k < sounding.length; k++) {
            if (sounding[k] > 24 || sounding[k] < 0) score += 10000;
        }
        
        return score;
    }

    // =========================================================================
    // CHORD NAME FORMATTING
    // =========================================================================

    /**
     * Format a chord name string into semantic HTML with superscripts.
     * Examples:
     *   "Cmaj7"  → <span class="sbn-chord-root">C</span><span class="sbn-chord-ext">maj7</span>
     *   "F#m7b5" → <span class="sbn-chord-root">F</span><span class="sbn-chord-accidental">#</span><span class="sbn-chord-ext">m7b5</span>
     *   "Dm7/A"  → ...root...<span class="sbn-chord-bass">/A</span>
     * 
     * @param {string} name - Raw chord name
     * @returns {string} HTML string wrapped in .sbn-chord-symbol
     */
    function formatChordName(name) {
        if (!name) return '';

        // Handle slash chords
        var slashIdx = name.indexOf('/');
        var bassPart = '';
        var mainPart = name;
        if (slashIdx > 0) {
            bassPart = name.substring(slashIdx);
            mainPart = name.substring(0, slashIdx);
        }

        // Extract root + accidental
        var root = mainPart.charAt(0);
        var rest = mainPart.substring(1);
        var accidental = '';
        if (rest.charAt(0) === '#' || rest.charAt(0) === 'b') {
            accidental = rest.charAt(0);
            rest = rest.substring(1);
        }

        var html = '<span class="sbn-chord-symbol">';
        html += '<span class="sbn-chord-root">' + escapeHtml(root) + '</span>';
        if (accidental) {
            html += '<span class="sbn-chord-accidental">' + escapeHtml(accidental) + '</span>';
        }
        if (rest) {
            html += '<span class="sbn-chord-ext">' + escapeHtml(rest) + '</span>';
        }
        if (bassPart) {
            html += '<span class="sbn-chord-bass">' + escapeHtml(bassPart) + '</span>';
        }
        html += '</span>';
        return html;
    }

    // =========================================================================
    // SVG FRETBOARD DIAGRAM (compact, scalable, no DOM manipulation)
    // =========================================================================

    /**
     * Render a chord diagram as an SVG string.
     * This is the most portable format — works in any HTML context.
     * 
     * @param {object} voicing - { frets: "x32010", position: 1, fingers: "032010" }
     * @param {object} opts - Optional overrides
     * @param {number} opts.size - Width in px (default 70)
     * @param {string} opts.color - Dot color (default '#555')
     * @param {boolean} opts.showFingers - Show finger numbers in dots (default true)
     * @returns {string} SVG markup
     */
    function renderDiagram(voicing, opts) {
        if (!voicing || !voicing.frets) return '';
        opts = opts || {};

        var pos = parseInt(voicing.position) || 1;
        var frets = parseFretString(voicing.frets, pos);
        var fingerChars = (voicing.fingers || '000000').split('');
        var color = opts.color || '#555';
        var size = opts.size || 70;
        var showFingers = opts.showFingers !== false;
        var showPosition = opts.showPosition !== false; // set false to hide fret number

        var W = 80, H = 95;
        var strSp = 12, fretSp = 16;
        var left = 14, top = 20, numFrets = 4;

        var svg = '<svg class="sbn-chord-svg" viewBox="0 0 ' + W + ' ' + H + '" style="width:' + size + 'px;height:auto">';

        // Nut or position marker
        if (pos <= 1) {
            svg += '<rect x="' + (left - 1) + '" y="' + (top - 3) + '" width="' + (strSp * 5 + 2) + '" height="3" fill="#333"/>';
        } else if (showPosition) {
            svg += '<text x="3" y="' + (top + fretSp / 2 + 4) + '" font-size="9" fill="#666">' + pos + '</text>';
        }

        // Fret lines
        for (var f = 0; f <= numFrets; f++) {
            svg += '<line x1="' + left + '" y1="' + (top + f * fretSp) + '" x2="' + (left + strSp * 5) + '" y2="' + (top + f * fretSp) + '" stroke="#999" stroke-width="0.75"/>';
        }

        // Strings
        for (var s = 0; s < 6; s++) {
            svg += '<line x1="' + (left + s * strSp) + '" y1="' + top + '" x2="' + (left + s * strSp) + '" y2="' + (top + fretSp * numFrets) + '" stroke="#666" stroke-width="0.75"/>';
        }

        // Finger dots, muted, open
        for (var i = 0; i < frets.length; i++) {
            var x = left + i * strSp;
            var fretVal = frets[i]; // 'x' or integer
            var finger = fingerChars[i];

            if (fretVal === 'x') {
                svg += '<text x="' + x + '" y="' + (top - 6) + '" font-size="9" text-anchor="middle" fill="#999">✕</text>';
            } else if (fretVal === 0) {
                svg += '<circle cx="' + x + '" cy="' + (top - 9) + '" r="3.5" fill="none" stroke="#333" stroke-width="1.25"/>';
            } else {
                var rf = fretVal - pos + 1;
                if (rf > 0 && rf <= numFrets) {
                    var y = top + rf * fretSp - fretSp / 2;
                    svg += '<circle cx="' + x + '" cy="' + y + '" r="4.5" fill="' + color + '" opacity="0.9"/>';
                    if (showFingers && finger && finger !== '0') {
                        svg += '<text x="' + x + '" y="' + (y + 3.5) + '" font-size="7" font-weight="600" text-anchor="middle" fill="#fff">' + finger + '</text>';
                    }
                }
            }
        }

        svg += '</svg>';
        return svg;
    }

    // =========================================================================
    // HTML FRETBOARD (DOM-based, supports info-in-dots toggling)
    // =========================================================================

    /**
     * Render an interactive HTML fretboard with info-in-dots display modes.
     * This is the rich version used in chord library cards and modals.
     * 
     * @param {object} data - Diagram data from database
     * @param {object} data.diagram - { positions: [], barres: [], muted: [], open: [] }
     * @param {number} data.startFret - Starting fret
     * @param {string} data.intervals - Comma-separated interval labels "R,3,5,7"
     * @param {string} data.notes - Comma-separated note names "C,E,G,B"
     * @param {string} data.displayMode - 'fingering' | 'notes' | 'functions'
     * @returns {string} HTML string for the fretboard
     */
    function renderFretboard(data) {
        data = data || {};
        var diagram = data.diagram;
        if (typeof diagram === 'string') {
            try { diagram = JSON.parse(diagram); }
            catch (e) { diagram = { positions: [], barres: [], muted: [], open: [] }; }
        }
        diagram = diagram || { positions: [], barres: [], muted: [], open: [] };

        var startFret = parseInt(data.startFret) || 1;
        var intervals = (data.intervals || '').split(',').filter(Boolean);
        var notes = (data.notes || '').split(',').filter(Boolean);
        var displayMode = data.displayMode || 'fingering';
        var FRETS = 4;

        var positions = diagram.positions || [];
        var barres = diagram.barres || [];
        var muted = diagram.muted || [];
        var open = diagram.open || [];

        var html = '<div class="sbn-fretboard-mini" data-mode="' + displayMode + '">';

        // Fret position number
        if (startFret > 1) {
            html += '<span class="sbn-fret-number">' + startFret + 'fr</span>';
        }

        // String indicators (muted / open)
        html += '<div class="sbn-string-indicators">';
        for (var s = 1; s <= 6; s++) {
            if (muted.indexOf(s) !== -1) html += '<span class="sbn-string-indicator muted">\u00d7</span>';
            else if (open.indexOf(s) !== -1) html += '<span class="sbn-string-indicator open">\u25cb</span>';
            else html += '<span class="sbn-string-indicator"></span>';
        }
        html += '</div>';

        // Nut
        if (startFret === 1) html += '<div class="sbn-nut"></div>';

        // Fret grid
        html += '<div class="sbn-frets">';
        for (var f = 0; f < FRETS; f++) {
            var af = startFret + f;
            html += '<div class="sbn-fret-row">';
            for (var ss = 1; ss <= 6; ss++) {
                html += '<div class="sbn-string-space" data-string="' + ss + '" data-fret="' + af + '"></div>';
            }
            html += '</div>';
        }
        html += '</div></div>';

        return html;
    }

    /**
     * After the fretboard HTML is in the DOM, call this to place finger dots and barres.
     * This is separate from renderFretboard because we need DOM measurements for barre positioning.
     * 
     * @param {HTMLElement} container - The .sbn-chord-fretboard element
     * @param {object} data - Same data object as renderFretboard
     */
    function hydrateFretboard(container, data) {
        if (!container) return;

        data = data || {};
        var diagram = data.diagram;
        if (typeof diagram === 'string') {
            try { diagram = JSON.parse(diagram); }
            catch (e) { diagram = { positions: [], barres: [], muted: [], open: [] }; }
        }
        diagram = diagram || { positions: [], barres: [], muted: [], open: [] };

        var startFret = parseInt(data.startFret) || 1;
        var intervals = (data.intervals || '').split(',').filter(Boolean);
        var notes = (data.notes || '').split(',').filter(Boolean);
        var displayMode = data.displayMode || 'fingering';
        var FRETS = 4;

        var positions = diagram.positions || [];
        var barres = diagram.barres || [];

        // Place finger dots
        positions.forEach(function(pos) {
            var fi = pos.fret - startFret;
            if (fi < 0 || fi >= FRETS) return;

            var cell = container.querySelector(
                '.sbn-string-space[data-string="' + pos.string + '"][data-fret="' + pos.fret + '"]'
            );
            if (!cell) return;

            var label = '';
            var si = pos.string - 1;
            if (displayMode === 'fingering') label = pos.finger || '';
            else if (displayMode === 'notes') label = notes[si] || '';
            else if (displayMode === 'functions') label = intervals[si] || '';

            var dot = document.createElement('div');
            dot.className = 'sbn-finger-position';
            dot.setAttribute('data-mode', displayMode);
            dot.textContent = label || '';
            dot.style.left = '50%';
            dot.style.top = '50%';
            cell.appendChild(dot);
        });

        // Place barres (needs DOM measurements)
        barres.forEach(function(barre) {
            var fi = barre.fret - startFret;
            if (fi < 0 || fi >= FRETS) return;

            var rows = container.querySelectorAll('.sbn-fret-row');
            var row = rows[fi];
            if (!row) return;

            var fromCell = row.querySelector('.sbn-string-space[data-string="' + barre.fromString + '"]');
            var toCell = row.querySelector('.sbn-string-space[data-string="' + barre.toString + '"]');
            if (!fromCell || !toCell) return;

            var fl = fromCell.offsetLeft + fromCell.offsetWidth / 2;
            var tl = toCell.offsetLeft + toCell.offsetWidth / 2;
            var leftPos = Math.min(fl, tl);
            var width = Math.abs(tl - fl);

            var label = '';
            var si = barre.fromString - 1;
            if (displayMode === 'fingering') label = barre.finger || '';
            else if (displayMode === 'notes') label = notes[si] || '';
            else if (displayMode === 'functions') label = intervals[si] || '';

            var barreEl = document.createElement('div');
            barreEl.className = 'sbn-barre';
            barreEl.setAttribute('data-mode', displayMode);
            barreEl.textContent = label || '';
            barreEl.style.left = leftPos + 'px';
            barreEl.style.width = Math.max(width, 20) + 'px';
            barreEl.style.top = '50%';
            row.appendChild(barreEl);
        });
    }

    // =========================================================================
    // DISPLAY MODE CYCLING
    // =========================================================================

    /**
     * Cycle through display modes: fingering → notes → functions → fingering
     * If no note data, skip the notes mode.
     * 
     * @param {string} currentMode - Current mode
     * @param {boolean} hasNotes - Whether note data is available
     * @returns {string} Next mode
     */
    function nextDisplayMode(currentMode, hasNotes) {
        if (hasNotes) {
            return currentMode === 'fingering' ? 'notes' :
                   currentMode === 'notes' ? 'functions' : 'fingering';
        }
        return currentMode === 'fingering' ? 'functions' : 'fingering';
    }

    /**
     * Get display icon + title for a mode (SVG strings for the toggle button).
     */
    var MODE_ICONS = {
        fingering: {
            title: 'Showing: Fingering',
            svg: '<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="5" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="12" cy="19" r="2"/></svg>'
        },
        notes: {
            title: 'Showing: Note names',
            svg: '<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M12 3v10.55c-.59-.34-1.27-.55-2-.55-2.21 0-4 1.79-4 4s1.79 4 4 4 4-1.79 4-4V7h4V3h-6z"/></svg>'
        },
        functions: {
            title: 'Showing: Chord functions',
            svg: '<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M14.6 16.6l4.6-4.6-4.6-4.6L16 6l6 6-6 6-1.4-1.4zm-5.2 0L4.8 12l4.6-4.6L8 6l-6 6 6 6 1.4-1.4z"/></svg>'
        }
    };

    // =========================================================================
    // CHORD CARD (full card with name, diagram, and controls)
    // =========================================================================

    /**
     * Render a chord card as an HTML string.
     * 
     * @param {object} chord - Chord data
     * @param {string} chord.name - Display name (e.g., "Dm7")
     * @param {string} chord.nameHtml - Pre-formatted HTML (overrides name)
     * @param {string} chord.quality - e.g., "m7"
     * @param {string} chord.voicingCategory - e.g., "drop2"
     * @param {string} chord.rootString - e.g., "roota"
     * @param {string} chord.inversion - e.g., "root", "inv1"
     * @param {string} chord.extensions - e.g., "b13"
     * @param {string} chord.diagramData - JSON string of diagram data
     * @param {number} chord.startFret - Starting fret
     * @param {string} chord.intervalLabels - e.g., "R,3,5,7"
     * @param {string} chord.notes - e.g., "D,F,A,C"
     * @param {number} chord.diagramId - Database ID
     * @param {string} chord.rootNote - Root note for transposed chords
     * @param {object} opts - Rendering options
     * @param {string} opts.variant - 'grid' (default) | 'detail' | 'mini'
     * @param {boolean} opts.showControls - Show hover controls (default true for grid)
     * @param {boolean} opts.showBadge - Show voicing badge (default true for grid)
     * @param {string} opts.displayMode - Initial display mode (default 'fingering')
     * @returns {string} HTML string
     */
    function renderCard(chord, opts) {
        opts = opts || {};
        var variant = opts.variant || 'grid';
        var showControls = opts.showControls !== undefined ? opts.showControls : (variant === 'grid');
        var showBadge = opts.showBadge !== undefined ? opts.showBadge : (variant === 'grid');
        var displayMode = opts.displayMode || 'fingering';

        var nameHtml = chord.nameHtml || formatChordName(chord.name || '');
        var catLabel = VOICING_LABELS[chord.voicingCategory] || chord.voicingCategory || '';

        // Data attributes for interactivity and the info modal
        var dataAttrs = ' data-diagram-id="' + escapeHtml(chord.diagramId || '') + '"' +
            ' data-quality="' + escapeHtml(chord.quality || '') + '"' +
            ' data-voicing="' + escapeHtml(chord.voicingCategory || '') + '"' +
            ' data-root="' + escapeHtml(chord.rootString || '') + '"' +
            ' data-inversion="' + escapeHtml(chord.inversion || 'root') + '"' +
            ' data-extensions="' + escapeHtml(chord.extensions || '') + '"' +
            ' data-root-note="' + escapeHtml(chord.rootNote || '') + '"' +
            ' data-notes="' + escapeHtml(chord.notes || '') + '"' +
            ' data-functions="' + escapeHtml(chord.intervalLabels || '') + '"' +
            ' data-popularity="' + escapeHtml(String(chord.popularity || 0)) + '"' +
            ' data-difficulty="' + escapeHtml(String(chord.difficulty || 0)) + '"';

        var h = '<div class="sbn-chord-card sbn-chord-card--' + variant + '"' + dataAttrs + '>';

        // Chord name
        h += '<div class="sbn-card-chord-name">' + nameHtml + '</div>';

        // Inversion label suppressed — shown in chord name as slash notation instead
        h += '<div class="sbn-card-inversion"></div>';

        // Diagram
        // NOTE: diagram data is JSON with double quotes, so we use single-quoted
        // attribute delimiters and only escape single quotes in the data.
        var safeDigram = (chord.diagramData || '').replace(/'/g, '&#39;');
        h += '<div class="sbn-card-diagram">' +
            '<div class="sbn-chord-fretboard"' +
            " data-diagram='" + safeDigram + "'" +
            ' data-start-fret="' + (chord.startFret || 1) + '"' +
            ' data-intervals="' + escapeHtml(chord.intervalLabels || '') + '"' +
            ' data-notes="' + escapeHtml(chord.notes || '') + '"' +
            ' data-display-mode="' + displayMode + '"' +
            ' data-has-root="' + (chord.notes ? 'true' : 'false') + '">' +
            '</div></div>';

        // Card footer — always: popularity pill + difficulty stars | hover: play + info buttons
        if (showControls) {
            // Popularity pill
            var pop = parseInt(chord.popularity) || 0;
            var popHtml = '';
            if (pop > 0) {
                var popTier, popLabel;
                if      (pop >= 11) { popTier = 'iconic';     popLabel = 'Iconic'; }
                else if (pop >= 6)  { popTier = 'essential';  popLabel = 'Core'; }
                else if (pop >= 3)  { popTier = 'common';     popLabel = 'Common'; }
                else                { popTier = 'occasional'; popLabel = 'Rare'; }
                popHtml = '<span class="sbn-card-pop sbn-pop-' + popTier + '" title="' +
                          pop + (pop === 1 ? ' song' : ' songs') + '">' + popLabel + '</span>';
            }

            // Difficulty stars 1–5
            var diff = parseInt(chord.difficulty) || 0;
            var diffHtml = '';
            if (diff > 0) {
                var stars = '';
                for (var si = 1; si <= 5; si++) {
                    stars += '<span class="sbn-diff-star' + (si <= diff ? ' filled' : '') + '">★</span>';
                }
                diffHtml = '<span class="sbn-card-diff" title="' +
                           (DIFFICULTY_LABELS[diff] || '') + '">' + stars + '</span>';
            }

            // Footer: pop top-left, diff top-right
            h += '<div class="sbn-card-footer">' +
                    '<div class="sbn-card-footer-left">' + popHtml + '</div>' +
                    '<div class="sbn-card-footer-right">' + diffHtml + '</div>' +
                '</div>';

            // Hover controls: play button only (info button removed — cards navigate to detail page on click)
            h += '<div class="sbn-card-hover-controls">' +
                    '<button class="sbn-play-btn" title="Play chord" aria-label="Play chord sound">' +
                        '<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>' +
                    '</button>' +
                '</div>';
        }

        h += '</div>';
        return h;
    }

    /**
     * Create a live chord card DOM element with diagram rendered and events ready.
     * This is the preferred method — returns an Element you can append to the DOM.
     * 
     * @param {object} chord - Same as renderCard
     * @param {object} opts - Same as renderCard, plus:
     * @param {function} opts.onPlay - Callback when play button clicked
     * @param {function} opts.onInfo - Callback when info button clicked
     * @param {function} opts.onToggle - Callback when display mode toggled
     * @returns {HTMLElement} The card element
     */
    function createCard(chord, opts) {
        opts = opts || {};

        // Create from HTML
        var wrapper = document.createElement('div');
        wrapper.innerHTML = renderCard(chord, opts);
        var card = wrapper.firstElementChild;

        // Render the fretboard inside the card
        var fretboardEl = card.querySelector('.sbn-chord-fretboard');
        if (fretboardEl) {
            var fretData = {
                diagram: chord.diagramData,
                startFret: chord.startFret || 1,
                intervals: chord.intervalLabels || '',
                notes: chord.notes || '',
                displayMode: opts.displayMode || 'fingering'
            };
            fretboardEl.innerHTML = renderFretboard(fretData);

            // Hydrate after next frame (needs DOM measurements for barres)
            requestAnimationFrame(function() {
                hydrateFretboard(fretboardEl, fretData);
            });
        }

        // Wire up control events
        var playBtn = card.querySelector('.sbn-play-btn');

        if (playBtn) {
            playBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                if (opts.onPlay) opts.onPlay(chord, card);
            });
        }

        return card;
    }

    // =========================================================================
    // BATCH RENDERING HELPER
    // =========================================================================

    /**
     * Render all .sbn-chord-fretboard elements within a container.
     * Call this after inserting card HTML into the DOM (e.g., from AJAX).
     * 
     * @param {HTMLElement} container - Parent element to scan
     */
    function hydrateAll(container) {
        container = container || document;
        var fretboards = container.querySelectorAll('.sbn-chord-fretboard[data-diagram]');
        fretboards.forEach(function(fb) {
            var mode = fb.getAttribute('data-display-mode') || 'fingering';
            var data = {
                diagram: fb.getAttribute('data-diagram'),
                startFret: fb.getAttribute('data-start-fret') || '1',
                intervals: fb.getAttribute('data-intervals') || '',
                notes: fb.getAttribute('data-notes') || '',
                displayMode: mode
            };
            // Always re-render: replaces any existing content (legacy or stale)
            fb.innerHTML = renderFretboard(data);
            // Mark as handled by shared component (prevents legacy double-render)
            fb.setAttribute('data-sbn-hydrated', '1');
            requestAnimationFrame(function() {
                hydrateFretboard(fb, data);
            });
        });
    }

    // =========================================================================
    // PUBLIC API
    // =========================================================================

    var SbnChordCard = {
        // Core rendering
        formatChordName: formatChordName,
        renderDiagram: renderDiagram,
        renderFretboard: renderFretboard,
        hydrateFretboard: hydrateFretboard,
        hydrateAll: hydrateAll,

        // Card rendering
        renderCard: renderCard,
        createCard: createCard,

        // Utilities
        parseFretString: parseFretString,

        // Display mode
        nextDisplayMode: nextDisplayMode,
        MODE_ICONS: MODE_ICONS,

        // Label lookups
        QUALITY_LABELS: QUALITY_LABELS,
        VOICING_LABELS: VOICING_LABELS,
        INVERSION_LABELS: INVERSION_LABELS,
        ROOT_STRING_LABELS: ROOT_STRING_LABELS,

        // Version
        VERSION: '1.1.0'
    };

    // Export for different module systems
    if (typeof module !== 'undefined' && module.exports) {
        module.exports = SbnChordCard;
    } else if (typeof define === 'function' && define.amd) {
        define(function() { return SbnChordCard; });
    }

    // Always expose globally for WordPress context
    root.SbnChordCard = SbnChordCard;

})(typeof window !== 'undefined' ? window : this);
