/**
 * SBN Chord Diagrams Admin JavaScript
 * 
 * Provides visual fretboard editor for creating and editing chord diagrams.
 */

(function($) {
    'use strict';

    // =============================================================================
    // CONSTANTS
    // =============================================================================

    const NUM_STRINGS = 6;
    const FRETS_TO_SHOW = 5;
    const FRET_MARKERS = [3, 5, 7, 9, 12, 15, 17, 19, 21];
    const DOUBLE_MARKERS = [12];

    // =============================================================================
    // STATE
    // =============================================================================

    let diagramData = {
        positions: [],
        barres: [],
        muted: [6, 1],
        open: []
    };

    let contextMenu = null;
    let currentEditTarget = null;

    // =============================================================================
    // MINI FRETBOARD RENDERER (for cards)
    // =============================================================================

    function renderMiniFretboard($container) {
        // Skip if already handled by the shared SbnChordCard component
        if ($container.attr('data-sbn-hydrated') === '1') return;
        
        const rawData = $container.data('diagram');
        const startFret = parseInt($container.data('start-fret')) || 1;
        const intervals = ($container.data('intervals') || '').split(',').filter(Boolean);

        let localData;
        if (typeof rawData === 'string') {
            try {
                localData = JSON.parse(rawData);
            } catch (e) {
                localData = { positions: [], barres: [], muted: [], open: [] };
            }
        } else {
            localData = rawData || { positions: [], barres: [], muted: [], open: [] };
        }

        const positions = localData.positions || [];
        const barres = localData.barres || [];
        const muted = localData.muted || [];
        const open = localData.open || [];

        let html = '<div class="sbn-fretboard-mini">';

        // Fret number indicator
        if (startFret > 1) {
            html += '<span class="sbn-fret-number">' + startFret + 'fr</span>';
        }

        // String indicators (muted/open)
        html += '<div class="sbn-string-indicators">';
        for (let s = 1; s <= 6; s++) {
            if (muted.includes(s)) {
                html += '<span class="sbn-string-indicator muted">×</span>';
            } else if (open.includes(s)) {
                html += '<span class="sbn-string-indicator open">○</span>';
            } else {
                html += '<span class="sbn-string-indicator"></span>';
            }
        }
        html += '</div>';

        // Nut
        if (startFret === 1) {
            html += '<div class="sbn-nut"></div>';
        }

        // Frets
        html += '<div class="sbn-frets">';
        for (let f = 0; f < FRETS_TO_SHOW; f++) {
            const actualFret = startFret + f;
            html += '<div class="sbn-fret-row">';
            for (let s = 1; s <= 6; s++) {
                html += '<div class="sbn-string-space" data-string="' + s + '" data-fret="' + actualFret + '"></div>';
            }
            html += '</div>';
        }
        html += '</div>';

        // Interval labels
        if (intervals.length > 0) {
            html += '<div class="sbn-interval-labels">';
            for (let s = 1; s <= 6; s++) {
                const idx = s - 1;
                const label = intervals[idx] || '';
                html += '<span class="sbn-interval-label">' + label + '</span>';
            }
            html += '</div>';
        }

        html += '</div>';
        $container.html(html);

        // Add finger positions after DOM is ready
        setTimeout(function() {
            positions.forEach(function(pos) {
                const fretIndex = pos.fret - startFret;
                if (fretIndex >= 0 && fretIndex < FRETS_TO_SHOW) {
                    const $cell = $container.find('.sbn-string-space[data-string="' + pos.string + '"][data-fret="' + pos.fret + '"]');
                    if ($cell.length) {
                        const $dot = $('<div class="sbn-finger-position">' + (pos.finger || '') + '</div>');
                        $dot.css({
                            left: '50%',
                            top: '50%'
                        });
                        $cell.append($dot);
                    }
                }
            });

            // Add barres
            barres.forEach(function(barre) {
                const fretIndex = barre.fret - startFret;
                if (fretIndex >= 0 && fretIndex < FRETS_TO_SHOW) {
                    const $row = $container.find('.sbn-fret-row').eq(fretIndex);
                    const $fromCell = $row.find('.sbn-string-space[data-string="' + barre.fromString + '"]');
                    const $toCell = $row.find('.sbn-string-space[data-string="' + barre.toString + '"]');

                    if ($fromCell.length && $toCell.length) {
                        const fromLeft = $fromCell.position().left + $fromCell.width() / 2;
                        const toLeft = $toCell.position().left + $toCell.width() / 2;
                        const left = Math.min(fromLeft, toLeft);
                        const width = Math.abs(toLeft - fromLeft);

                        const $barreEl = $('<div class="sbn-barre">' + (barre.finger || '') + '</div>');
                        $barreEl.css({
                            left: left,
                            width: Math.max(width, 20),
                            top: '50%'
                        });
                        $row.append($barreEl);
                    }
                }
            });
        }, 50);
    }

    // =============================================================================
    // FULL FRETBOARD EDITOR
    // =============================================================================

    function renderEditorFretboard() {
        const $editor = $('#sbnFretboardEditor');
        if (!$editor.length) return;

        const startFret = parseInt($('#sbnStartFret').val()) || 1;

        // Load current diagram data
        try {
            diagramData = JSON.parse($('#sbnDiagramData').val() || '{}');
        } catch (e) {
            diagramData = { positions: [], barres: [], muted: [], open: [] };
        }

        if (!diagramData.positions) diagramData.positions = [];
        if (!diagramData.barres) diagramData.barres = [];
        if (!diagramData.muted) diagramData.muted = [];
        if (!diagramData.open) diagramData.open = [];

        let html = '<div class="sbn-fretboard-full">';

        // String labels (clickable for muted/open)
        html += '<div class="sbn-editor-string-labels">';
        for (let s = 1; s <= 6; s++) {
            let stateClass = 'normal';
            let stateText = s;
            if (diagramData.muted.includes(s)) {
                stateClass = 'muted';
                stateText = '';
            } else if (diagramData.open.includes(s)) {
                stateClass = 'open';
                stateText = '';
            }
            html += '<div class="sbn-string-label ' + stateClass + '" data-string="' + s + '">' + stateText + '</div>';
        }
        html += '</div>';

        // Nut
        const nutClass = startFret > 1 ? 'hidden-nut' : '';
        html += '<div class="sbn-editor-nut ' + nutClass + '"></div>';

        // Fret number label
        if (startFret > 1) {
            html += '<span class="sbn-fret-label" style="top: 44px;">' + startFret + '</span>';
        }

        // Fretboard grid
        html += '<div class="sbn-editor-frets">';
        for (let f = 0; f < FRETS_TO_SHOW; f++) {
            const actualFret = startFret + f;
            const hasMarker = FRET_MARKERS.includes(actualFret);
            const hasDoubleMarker = DOUBLE_MARKERS.includes(actualFret);
            let rowClass = 'sbn-editor-fret-row';
            if (hasDoubleMarker) rowClass += ' has-double-marker';
            else if (hasMarker) rowClass += ' has-marker';

            html += '<div class="' + rowClass + '" data-fret="' + actualFret + '">';
            for (let s = 1; s <= 6; s++) {
                html += '<div class="sbn-editor-string-cell" data-string="' + s + '" data-fret="' + actualFret + '"></div>';
            }
            html += '</div>';
        }
        html += '</div>';

        html += '</div>';
        $editor.html(html);

        // Render positions
        renderEditorPositions();

        // Render barres
        renderEditorBarres();

        // Update live preview
        updateLivePreview();
    }

    function renderEditorPositions() {
        const $editor = $('#sbnFretboardEditor');
        const startFret = parseInt($('#sbnStartFret').val()) || 1;

        // Clear existing dots
        $editor.find('.sbn-editor-finger-dot').remove();

        diagramData.positions.forEach(function(pos, idx) {
            const $cell = $editor.find('.sbn-editor-string-cell[data-string="' + pos.string + '"][data-fret="' + pos.fret + '"]');
            if ($cell.length) {
                const fingerClass = pos.finger ? 'finger-' + pos.finger : '';
                const $dot = $('<div class="sbn-editor-finger-dot ' + fingerClass + '" data-index="' + idx + '">' + (pos.finger || '') + '</div>');
                $cell.addClass('has-position').append($dot);
            }
        });
    }

    function renderEditorBarres() {
        const $editor = $('#sbnFretboardEditor');

        // Clear existing barres
        $editor.find('.sbn-editor-barre').remove();

        diagramData.barres.forEach(function(barre, idx) {
            const $row = $editor.find('.sbn-editor-fret-row[data-fret="' + barre.fret + '"]');
            if (!$row.length) return;

            const $fromCell = $row.find('.sbn-editor-string-cell[data-string="' + barre.fromString + '"]');
            const $toCell = $row.find('.sbn-editor-string-cell[data-string="' + barre.toString + '"]');

            if ($fromCell.length && $toCell.length) {
                const fromLeft = $fromCell.position().left + $fromCell.width() / 2;
                const toLeft = $toCell.position().left + $toCell.width() / 2;
                const left = Math.min(fromLeft, toLeft);
                const width = Math.abs(toLeft - fromLeft);

                const fingerClass = barre.finger ? 'finger-' + barre.finger : '';
                const $barreEl = $('<div class="sbn-editor-barre ' + fingerClass + '" data-index="' + idx + '">' + (barre.finger || '') + '</div>');
                $barreEl.css({
                    left: left,
                    width: Math.max(width, 30),
                    top: $row.height() / 2
                });
                $row.append($barreEl);
            }
        });
    }

    // =============================================================================
    // LIVE PREVIEW
    // =============================================================================

    function updateLivePreview() {
        const $preview = $('#sbnLivePreview');
        if (!$preview.length) return;

        const quality = $('#sbnQuality').val() || 'maj7';
        const extensions = $('#sbnExtensions').val() || '';
        const category = $('#sbnVoicingCategory').val() || 'drop2';
        const rootString = $('#sbnRootString').val() || 'roota';
        const startFret = parseInt($('#sbnStartFret').val()) || 1;
        const intervals = $('#sbnIntervalLabels').val() || '';

        // Get labels
        const qualityLabel = sbnChordDiagrams.chordQualities[quality] || quality;
        const categoryLabel = sbnChordDiagrams.voicingCategories[category] || category;
        const rootStringLabels = {'roote': 'E', 'roota': 'A', 'rootd': 'D', 'rootg': 'G'};

        // Build display name
        let displayName = qualityLabel;
        if (extensions) {
            displayName += extensions;
        }
        displayName += ' ' + categoryLabel;

        let html = '<div class="sbn-preview-chord-name">' + displayName + '</div>';
        html += '<div class="sbn-preview-voicing">Root on ' + (rootStringLabels[rootString] || rootString) + ' string</div>';
        html += '<div class="sbn-preview-fretboard">';
        html += '<div class="sbn-chord-fretboard" id="sbnPreviewFretboard" ';
        html += 'data-diagram=\'' + JSON.stringify(diagramData) + '\' ';
        html += 'data-start-fret="' + startFret + '" ';
        html += 'data-intervals="' + intervals + '">';
        html += '</div></div>';

        $preview.html(html);

        // Render the mini fretboard
        renderMiniFretboard($('#sbnPreviewFretboard'));

        // Update the generated slug display
        const slug = generateSlug();
        $('#sbnGeneratedSlug').text(slug);
        $('.sbn-slug-preview').text(slug);

        // Update hidden data field
        $('#sbnDiagramData').val(JSON.stringify(diagramData));
    }

    // =============================================================================
    // CONTEXT MENU (Finger Selection)
    // =============================================================================

    function createContextMenu() {
        if (contextMenu) return;

        const html = '<div class="sbn-context-menu" id="sbnContextMenu">' +
            '<div class="sbn-context-menu-title">Select Finger</div>' +
            '<div class="sbn-context-menu-fingers">' +
            '<button class="sbn-context-menu-finger" data-finger="1">1</button>' +
            '<button class="sbn-context-menu-finger" data-finger="2">2</button>' +
            '<button class="sbn-context-menu-finger" data-finger="3">3</button>' +
            '<button class="sbn-context-menu-finger" data-finger="4">4</button>' +
            '<button class="sbn-context-menu-finger" data-finger="t">T</button>' +
            '</div>' +
            '<div class="sbn-context-menu-actions">' +
            '<button class="sbn-context-menu-action delete">Remove</button>' +
            '</div>' +
            '</div>';

        $('body').append(html);
        contextMenu = $('#sbnContextMenu');
    }

    function showContextMenu(x, y, target, type) {
        createContextMenu();
        currentEditTarget = { target: target, type: type };

        contextMenu.css({
            left: Math.min(x, window.innerWidth - 200),
            top: Math.min(y, window.innerHeight - 150)
        }).addClass('visible');
    }

    function hideContextMenu() {
        if (contextMenu) {
            contextMenu.removeClass('visible');
        }
        currentEditTarget = null;
    }

    // =============================================================================
    // =============================================================================
    // BARRE MODAL (removed — barres are handled via finger 1 positioning)
    // Barre rendering is preserved for existing data and transposed shapes.
    // =============================================================================

    // =============================================================================
    // DIAGRAM DATA MANIPULATION
    // =============================================================================

    function addPosition(string, fret, finger) {
        // Remove existing position on this string/fret
        diagramData.positions = diagramData.positions.filter(function(p) {
            return !(p.string === string && p.fret === fret);
        });

        // Add new position
        diagramData.positions.push({
            string: string,
            fret: fret,
            finger: finger || null
        });

        // Remove from muted/open if adding a fretted position
        diagramData.muted = diagramData.muted.filter(function(s) { return s !== string; });
        diagramData.open = diagramData.open.filter(function(s) { return s !== string; });

        updateDiagram();
    }

    function removePosition(index) {
        diagramData.positions.splice(index, 1);
        updateDiagram();
    }

    function updatePositionFinger(index, finger) {
        if (diagramData.positions[index]) {
            diagramData.positions[index].finger = finger;
        }
        updateDiagram();
    }

    function addBarre(fret, fromString, toString, finger) {
        diagramData.barres.push({
            fret: fret,
            fromString: fromString,
            toString: toString,
            finger: finger || 1
        });

        // Remove muted/open for covered strings
        const minStr = Math.min(fromString, toString);
        const maxStr = Math.max(fromString, toString);
        for (let s = minStr; s <= maxStr; s++) {
            diagramData.muted = diagramData.muted.filter(function(ms) { return ms !== s; });
            diagramData.open = diagramData.open.filter(function(os) { return os !== s; });
        }

        updateDiagram();
    }

    function removeBarre(index) {
        diagramData.barres.splice(index, 1);
        updateDiagram();
    }

    function toggleStringState(string) {
        const isMuted = diagramData.muted.includes(string);
        const isOpen = diagramData.open.includes(string);

        // Cycle: normal -> muted -> open -> normal
        diagramData.muted = diagramData.muted.filter(function(s) { return s !== string; });
        diagramData.open = diagramData.open.filter(function(s) { return s !== string; });

        if (!isMuted && !isOpen) {
            // Was normal, make muted
            diagramData.muted.push(string);
            // Remove any positions on this string
            diagramData.positions = diagramData.positions.filter(function(p) { return p.string !== string; });
        } else if (isMuted) {
            // Was muted, make open
            diagramData.open.push(string);
        }
        // If was open, becomes normal (nothing to add)

        updateDiagram();
    }

    function clearDiagram() {
        diagramData = {
            positions: [],
            barres: [],
            muted: [],
            open: []
        };
        updateDiagram();
    }

    function updateDiagram() {
        $('#sbnDiagramData').val(JSON.stringify(diagramData));
        renderEditorFretboard();
    }

    // =============================================================================
    // SLUG GENERATION
    // =============================================================================

    function generateSlug() {
        const quality = $('#sbnQuality').val() || 'maj7';
        const extensions = $('#sbnExtensions').val() || '';
        const category = $('#sbnVoicingCategory').val() || 'drop2';
        const rootString = $('#sbnRootString').val() || 'roota';
        const inversion = $('#sbnInversion').val() || 'root';

        // Format: quality-voicing-rootstring[-inversion][-extensions]
        let slug = quality + '-' + category + '-' + rootString;
        if (inversion && inversion !== 'root') {
            slug += '-' + inversion;
        }
        if (extensions) {
            const slugExt = extensions.replace(/#/g, 's').replace(/♯/g, 's').replace(/♭/g, 'b').replace(/\s/g, '');
            slug += '-' + slugExt;
        }
        return slug;
    }

    function generateName() {
        const quality = $('#sbnQuality').val() || 'maj7';
        const extensions = $('#sbnExtensions').val() || '';
        const category = $('#sbnVoicingCategory').val() || 'drop2';
        const rootString = $('#sbnRootString').val() || 'roota';
        const inversion = $('#sbnInversion').val() || 'root';
        
        // Format: Maj7 Drop 2 (Root on A) or Maj7 Drop 2 1st Inv (Root on A)
        const qualityLabels = {
            'maj': 'Maj', 'min': 'min',
            'maj7': 'Maj7', 'm7': 'm7', '7': '7', 'm7b5': 'm7♭5', 
            'o7': '°7', 'maj6': 'Maj6', 'm6': 'm6', 'mMaj7': 'mMaj7', 'aug7': 'Aug7'
        };
        const categoryLabels = {
            'drop2': 'Drop 2', 'drop3': 'Drop 3', 'shell': 'Shell',
            'rootless': 'Rootless', 'closed': 'Closed', 'open': 'Open', 'custom': 'Custom'
        };
        const rootLabels = {
            'roote': 'E', 'roota': 'A', 'rootd': 'D', 'rootg': 'G'
        };
        const inversionLabels = {
            'root': '', 'inv1': '1st Inv', 'inv2': '2nd Inv', 'inv3': '3rd Inv'
        };
        
        let name = (qualityLabels[quality] || quality);
        if (extensions) {
            name += extensions;
        }
        name += ' ' + (categoryLabels[category] || category);
        if (inversion && inversion !== 'root') {
            name += ' ' + (inversionLabels[inversion] || inversion);
        }
        name += ' (Root ' + (rootLabels[rootString] || rootString) + ')';

        return name;
    }

    // =============================================================================
    // FILTERS
    // =============================================================================

    function applyFilters() {
        const category = $('#sbnFilterCategory').val();
        const quality = $('#sbnFilterQuality').val();
        const rootString = $('#sbnFilterRootString').val();

        // Show/hide cards
        $('.sbn-diagram-card').each(function() {
            const $card = $(this);
            const cardCat = $card.data('category');
            const cardQual = $card.data('quality');
            const cardRootString = $card.data('root-string');

            let visible = true;
            if (category && cardCat !== category) visible = false;
            if (quality && cardQual !== quality) visible = false;
            if (rootString && cardRootString !== rootString) visible = false;

            $card.toggleClass('hidden', !visible);
        });

        // Show/hide root string sections
        $('.sbn-root-string-section').each(function() {
            const $section = $(this);
            const hasVisibleCards = $section.find('.sbn-diagram-card:not(.hidden)').length > 0;
            $section.toggleClass('hidden', !hasVisibleCards);
        });

        // Show/hide voicing sections
        $('.sbn-voicing-section').each(function() {
            const $section = $(this);
            const hasVisibleCards = $section.find('.sbn-diagram-card:not(.hidden)').length > 0;
            $section.toggleClass('hidden', !hasVisibleCards);
        });
    }

    // =============================================================================
    // TOAST
    // =============================================================================

    function showToast(message, type) {
        const $toast = $('<div class="sbn-toast ' + (type || '') + '">' + message + '</div>');
        $('body').append($toast);
        setTimeout(function() {
            $toast.fadeOut(300, function() { $(this).remove(); });
        }, 3000);
    }

    // =============================================================================
    // GLOBAL API - Expose diagram data for chord shapes editor
    // =============================================================================

    /**
     * Get the current diagram data from the editor.
     * This function is called by chord-shapes-admin.js when saving shapes.
     * @returns {Object} The current diagram data with positions, barres, muted, open arrays
     */
    window.sbnGetDiagramData = function() {
        return diagramData;
    };

    /**
     * Set diagram data programmatically (for loading shapes into editor).
     * @param {Object} data - The diagram data to load
     */
    window.sbnSetDiagramData = function(data) {
        if (data && typeof data === 'object') {
            diagramData = {
                positions: data.positions || [],
                barres: data.barres || [],
                muted: data.muted || [],
                open: data.open || []
            };
            updateDiagram();
        }
    };

    // =============================================================================
    // EVENT BINDINGS
    // =============================================================================

    $(document).ready(function() {

        // Render mini fretboards on list page
        $('.sbn-chord-fretboard[data-diagram]').each(function() {
            renderMiniFretboard($(this));
        });

        // Initialize editor if on edit page
        if ($('#sbnFretboardEditor').length) {
            renderEditorFretboard();
            
            // Initialize name and slug fields if empty
            const $name = $('#sbnDiagramName');
            const $slug = $('#sbnDiagramSlug');
            
            if (!$name.val()) {
                $name.val(generateName());
            }
            
            if (!$slug.val()) {
                $slug.val(generateSlug());
            }
        }

        // Click on string labels to toggle muted/open
        $(document).on('click', '.sbn-string-label', function() {
            const string = parseInt($(this).data('string'));
            toggleStringState(string);
        });

        // Click on fret cells to add/remove positions
        $(document).on('click', '.sbn-editor-string-cell', function(e) {
            const $cell = $(this);
            const string = parseInt($cell.data('string'));
            const fret = parseInt($cell.data('fret'));

            // Check if there's already a position here
            const existingIndex = diagramData.positions.findIndex(function(p) {
                return p.string === string && p.fret === fret;
            });

            if (existingIndex >= 0) {
                // Position exists - show context menu for editing
                showContextMenu(e.pageX, e.pageY, existingIndex, 'position');
            } else {
                // No position - add one with default finger (1)
                addPosition(string, fret, 1);
            }
        });

        // Right-click on positions to edit finger
        $(document).on('contextmenu', '.sbn-editor-finger-dot', function(e) {
            e.preventDefault();
            const index = parseInt($(this).data('index'));
            showContextMenu(e.pageX, e.pageY, index, 'position');
        });

        // Click on existing position dots
        $(document).on('click', '.sbn-editor-finger-dot', function(e) {
            e.stopPropagation();
            const index = parseInt($(this).data('index'));
            showContextMenu(e.pageX, e.pageY, index, 'position');
        });

        // Click on barres to edit
        $(document).on('click', '.sbn-editor-barre', function(e) {
            e.stopPropagation();
            const index = parseInt($(this).data('index'));
            showContextMenu(e.pageX, e.pageY, index, 'barre');
        });

        // Context menu finger selection
        $(document).on('click', '.sbn-context-menu-finger', function() {
            const finger = $(this).data('finger');
            if (currentEditTarget) {
                if (currentEditTarget.type === 'position') {
                    updatePositionFinger(currentEditTarget.target, finger);
                } else if (currentEditTarget.type === 'barre') {
                    diagramData.barres[currentEditTarget.target].finger = finger;
                    updateDiagram();
                }
            }
            hideContextMenu();
        });

        // Context menu delete
        $(document).on('click', '.sbn-context-menu-action.delete', function() {
            if (currentEditTarget) {
                if (currentEditTarget.type === 'position') {
                    removePosition(currentEditTarget.target);
                } else if (currentEditTarget.type === 'barre') {
                    removeBarre(currentEditTarget.target);
                }
            }
            hideContextMenu();
        });

        // Close context menu on outside click
        $(document).on('click', function(e) {
            if (contextMenu && !$(e.target).closest('.sbn-context-menu').length) {
                hideContextMenu();
            }
        });

        // Clear diagram button
        $('#sbnClearDiagram').on('click', function() {
            if (confirm('Clear all finger positions and barres?')) {
                clearDiagram();
            }
        });

        // Start fret change
        $('#sbnStartFret').on('change', function() {
            renderEditorFretboard();
        });

        // Chord property changes - update preview
        $('#sbnRootNote, #sbnQuality, #sbnExtensions, #sbnVoicingCategory, #sbnRootString, #sbnInversion, #sbnIntervalLabels').on('change input', function() {
            updateLivePreview();

            // Auto-update slug if not manually edited
            const $slug = $('#sbnDiagramSlug');
            if (!$slug.data('manual')) {
                $slug.val(generateSlug());
            }
            
            // Auto-update name if not manually edited
            const $name = $('#sbnDiagramName');
            if (!$name.data('manual')) {
                $name.val(generateName());
            }
        });

        // Manual slug edit
        $('#sbnDiagramSlug').on('input', function() {
            $(this).data('manual', true);
            updateLivePreview();
        });
        
        // Manual name edit
        $('#sbnDiagramName').on('input', function() {
            $(this).data('manual', true);
        });

        // Filters
        $('#sbnFilterCategory, #sbnFilterQuality, #sbnFilterRoot').on('change', applyFilters);

        // Copy shortcode
        $(document).on('click', '.sbn-copy-btn', function() {
            const text = $(this).data('copy');
            navigator.clipboard.writeText(text).then(function() {
                showToast('Copied!', 'success');
            });
        });

        // Save diagram
        $('#sbnSaveDiagram').on('click', function() {
            const $btn = $(this);
            const id = $('#sbnDiagramId').val();
            
            // Always regenerate name unless manually edited
            const $name = $('#sbnDiagramName');
            const name = $name.data('manual') ? $name.val() : generateName();
            
            // Always regenerate slug unless manually edited
            const $slug = $('#sbnDiagramSlug');
            const slug = $slug.data('manual') ? $slug.val() : generateSlug();

            const data = {
                action: 'sbn_save_chord_diagram',
                nonce: sbnChordDiagrams.nonce,
                id: id,
                name: name,
                slug: slug,
                root_note: $('#sbnRootNote').val(),
                quality: $('#sbnQuality').val(),
                extensions: $('#sbnExtensions').val(),
                voicing_category: $('#sbnVoicingCategory').val(),
                root_string: $('#sbnRootString').val(),
                inversion: $('#sbnInversion').val() || 'root',
                bass_note: $('#sbnBassNote').val(),
                shape_family: $('#sbnShapeFamily').val(),
                is_fixed_position: $('#sbnFixedPosition').is(':checked') ? 1 : 0,
                start_fret: $('#sbnStartFret').val(),
                diagram_data: $('#sbnDiagramData').val(),
                interval_labels: $('#sbnIntervalLabels').val(),
                notes: $('#sbnNotes').val(),
                description: $('#sbnDescription').val()
            };

            if (!data.root_note) {
                showToast('Please select a root note', 'error');
                return;
            }

            $btn.prop('disabled', true).text('Saving...');

            $.post(sbnChordDiagrams.ajaxUrl, data, function(response) {
                if (response.success) {
                    showToast('Chord diagram saved!', 'success');
                    if (!id) {
                        // Redirect to list after creating
                        window.location.href = sbnChordDiagrams.ajaxUrl.replace('admin-ajax.php', 'admin.php?page=sbn-chord-diagrams');
                    } else {
                        // Update slug display
                        $('#sbnDiagramSlug').val(response.data.slug);
                        updateLivePreview();
                    }
                } else {
                    showToast('Error: ' + response.data, 'error');
                }
                $btn.prop('disabled', false).text(id ? 'Update Diagram' : 'Create Diagram');
            });
        });

        // Delete diagram
        $(document).on('click', '.sbn-delete-diagram', function() {
            if (!confirm('Delete this chord diagram?')) return;

            const $card = $(this).closest('.sbn-diagram-card');
            const id = $(this).data('id');

            $.post(sbnChordDiagrams.ajaxUrl, {
                action: 'sbn_delete_chord_diagram',
                nonce: sbnChordDiagrams.nonce,
                id: id
            }, function(response) {
                if (response.success) {
                    $card.fadeOut(300, function() { $(this).remove(); });
                    showToast('Diagram deleted', 'success');
                } else {
                    showToast('Error deleting', 'error');
                }
            });
        });

        // Duplicate diagram
        $(document).on('click', '.sbn-duplicate-diagram', function() {
            const id = $(this).data('id');

            $.post(sbnChordDiagrams.ajaxUrl, {
                action: 'sbn_duplicate_chord_diagram',
                nonce: sbnChordDiagrams.nonce,
                id: id
            }, function(response) {
                if (response.success) {
                    showToast('Diagram duplicated! Reloading...', 'success');
                    setTimeout(function() {
                        window.location.reload();
                    }, 1000);
                } else {
                    showToast('Error duplicating: ' + response.data, 'error');
                }
            });
        });

        // =============================================================================
        // TABS
        // =============================================================================
        
        $(document).on('click', '.sbn-tab', function() {
            const tab = $(this).data('tab');
            
            // Update active states
            $('.sbn-tab').removeClass('active');
            $(this).addClass('active');
            
            $('.sbn-tab-content').removeClass('active');
            $('#sbn-tab-' + tab).addClass('active');
        });

        // =============================================================================
        // VOICING TYPES & QUALITIES MANAGEMENT
        // =============================================================================
        
        // Add voicing type
        $('#sbnAddVoicingType').on('click', function() {
            const key = $('#sbnNewVoicingKey').val().trim();
            const label = $('#sbnNewVoicingLabel').val().trim();
            
            if (!key || !label) {
                showToast('Please enter both key and label', 'error');
                return;
            }
            
            const $btn = $(this);
            $btn.prop('disabled', true).text('Adding...');
            
            $.post(sbnChordDiagrams.ajaxUrl, {
                action: 'sbn_add_voicing_type',
                nonce: sbnChordDiagrams.nonce,
                key: key,
                label: label
            }, function(response) {
                if (response.success) {
                    // Add row to table
                    const row = '<tr data-key="' + response.data.key + '">' +
                        '<td><code>' + response.data.key + '</code></td>' +
                        '<td>' + response.data.label + '</td>' +
                        '<td><button class="button button-small sbn-delete-voicing-type" data-key="' + response.data.key + '">Delete</button></td>' +
                        '</tr>';
                    $('#sbnVoicingTypesList').append(row);
                    
                    // Clear inputs
                    $('#sbnNewVoicingKey, #sbnNewVoicingLabel').val('');
                    showToast('Voicing type added', 'success');
                } else {
                    showToast('Error: ' + response.data, 'error');
                }
                $btn.prop('disabled', false).text('Add');
            });
        });
        
        // Delete voicing type
        $(document).on('click', '.sbn-delete-voicing-type', function() {
            const key = $(this).data('key');
            const $row = $(this).closest('tr');
            
            if (!confirm('Delete voicing type "' + key + '"?')) return;
            
            $.post(sbnChordDiagrams.ajaxUrl, {
                action: 'sbn_delete_voicing_type',
                nonce: sbnChordDiagrams.nonce,
                key: key
            }, function(response) {
                if (response.success) {
                    $row.fadeOut(300, function() { $(this).remove(); });
                    showToast('Voicing type deleted', 'success');
                } else {
                    showToast('Error: ' + response.data, 'error');
                }
            });
        });
        
        // Add chord quality
        $('#sbnAddQuality').on('click', function() {
            const key = $('#sbnNewQualityKey').val().trim();
            const label = $('#sbnNewQualityLabel').val().trim();
            
            if (!key || !label) {
                showToast('Please enter both key and label', 'error');
                return;
            }
            
            const $btn = $(this);
            $btn.prop('disabled', true).text('Adding...');
            
            $.post(sbnChordDiagrams.ajaxUrl, {
                action: 'sbn_add_chord_quality',
                nonce: sbnChordDiagrams.nonce,
                key: key,
                label: label
            }, function(response) {
                if (response.success) {
                    // Add row to table
                    const row = '<tr data-key="' + response.data.key + '">' +
                        '<td><code>' + response.data.key + '</code></td>' +
                        '<td>' + response.data.label + '</td>' +
                        '<td><button class="button button-small sbn-delete-quality" data-key="' + response.data.key + '">Delete</button></td>' +
                        '</tr>';
                    $('#sbnQualitiesList').append(row);
                    
                    // Clear inputs
                    $('#sbnNewQualityKey, #sbnNewQualityLabel').val('');
                    showToast('Chord quality added', 'success');
                } else {
                    showToast('Error: ' + response.data, 'error');
                }
                $btn.prop('disabled', false).text('Add');
            });
        });
        
        // Delete chord quality
        $(document).on('click', '.sbn-delete-quality', function() {
            const key = $(this).data('key');
            const $row = $(this).closest('tr');
            
            if (!confirm('Delete chord quality "' + key + '"?')) return;
            
            $.post(sbnChordDiagrams.ajaxUrl, {
                action: 'sbn_delete_chord_quality',
                nonce: sbnChordDiagrams.nonce,
                key: key
            }, function(response) {
                if (response.success) {
                    $row.fadeOut(300, function() { $(this).remove(); });
                    showToast('Chord quality deleted', 'success');
                } else {
                    showToast('Error: ' + response.data, 'error');
                }
            });
        });

        // =============================================================================
        // FILTERS (updated for new structure)
        // =============================================================================
        
        $('#sbnFilterCategory, #sbnFilterQuality, #sbnFilterRootString').on('change', function() {
            const category = $('#sbnFilterCategory').val();
            const quality = $('#sbnFilterQuality').val();
            const rootString = $('#sbnFilterRootString').val();
            
            // Show/hide voicing sections
            $('.sbn-voicing-section').each(function() {
                const sectionCat = $(this).data('category');
                const matchesCat = !category || sectionCat === category;
                
                if (!matchesCat) {
                    $(this).hide();
                } else {
                    $(this).show();
                    
                    // Show/hide root string sections within
                    $(this).find('.sbn-root-string-section').each(function() {
                        const sectionRs = $(this).data('root-string');
                        const matchesRs = !rootString || sectionRs === rootString;
                        
                        if (!matchesRs) {
                            $(this).hide();
                        } else {
                            $(this).show();
                            
                            // Show/hide cards within
                            $(this).find('.sbn-diagram-card').each(function() {
                                const cardQuality = $(this).data('quality');
                                const matchesQuality = !quality || cardQuality === quality;
                                $(this).toggle(matchesQuality);
                            });
                            
                            // Hide section if no visible cards
                            const visibleCards = $(this).find('.sbn-diagram-card:visible').length;
                            if (visibleCards === 0) {
                                $(this).hide();
                            }
                        }
                    });
                    
                    // Hide voicing section if no visible root string sections
                    const visibleRsSections = $(this).find('.sbn-root-string-section:visible').length;
                    if (visibleRsSections === 0) {
                        $(this).hide();
                    }
                }
            });
        });

    });
// =========================================================================
    // FRONTEND LIBRARY SEARCH & FILTER
    // =========================================================================
    
    // =========================================================================
    // FLAT LIBRARY SEARCH & FILTER
    // =========================================================================
    
    function filterChordLibrary() {
        const searchTerm = $('#sbn-chord-search').val().toLowerCase().trim();
        const filterRoot = $('#sbn-filter-root').val();
        const filterQuality = $('#sbn-filter-quality').val();
        
        let visibleCount = 0;

        // Loop through all cards
        $('.sbn-diagram-card').each(function() {
            const $card = $(this);
            // Search 'data-name' which now includes "drop 2", "root 6" strings
            const name = $card.data('name') || ''; 
            const root = $card.data('root') || '';
            const quality = $card.data('quality') || '';

            // Check Matches
            const matchesSearch  = (searchTerm === '' || name.indexOf(searchTerm) > -1);
            const matchesRoot    = (filterRoot === '' || root == filterRoot);
            const matchesQuality = (filterQuality === '' || quality == filterQuality);

            if (matchesSearch && matchesRoot && matchesQuality) {
                $card.show();
                visibleCount++;
            } else {
                $card.hide();
            }
        });

        // Show/Hide "No Results"
        if (visibleCount === 0) {
            $('#sbn-no-results').show();
        } else {
            $('#sbn-no-results').hide();
        }
    }

    // Attach Event Listeners
    $('#sbn-chord-search').on('keyup search', filterChordLibrary);
    $('#sbn-filter-root, #sbn-filter-quality').on('change', filterChordLibrary);

    // =========================================================================
    // Recompute Intervals Button
    // =========================================================================
    $('#sbnRecomputeIntervals').on('click', function() {
        var $btn = $(this);
        var $status = $('#sbnRecomputeStatus');

        if ($btn.prop('disabled')) return;
        $btn.prop('disabled', true).text('⏳ Recomputing...');
        $status.text('').removeClass('sbn-status-success sbn-status-error');

        $.post(ajaxurl, {
            action: 'sbn_recompute_intervals',
            nonce: sbnChordDiagrams.nonce
        })
        .done(function(response) {
            if (response.success) {
                var d = response.data;
                var msg = '✓ Updated ' + d.updated + ' shapes';
                if (d.skipped > 0) {
                    msg += ', ' + d.skipped + ' skipped';
                }
                $status.text(msg).addClass('sbn-status-success');
                // Reload page after a brief pause so updated labels show
                setTimeout(function() { location.reload(); }, 1200);
            } else {
                $status.text('Error: ' + (response.data || 'Unknown error')).addClass('sbn-status-error');
            }
        })
        .fail(function() {
            $status.text('Request failed').addClass('sbn-status-error');
        })
        .always(function() {
            $btn.prop('disabled', false).html('&#8635; Recompute Intervals');
        });
    });

    // =========================================================================
    // Aliases
    // =========================================================================
    var $aliasesContainer = $('#sbnAliasesList');
    var diagramId = $('#sbnDiagramId').val();

    function loadAliases() {
        if (!diagramId || !$aliasesContainer.length) return;

        $.post(ajaxurl, {
            action: 'sbn_get_aliases',
            nonce: sbnChordDiagrams.nonce,
            diagram_id: diagramId
        }).done(function(response) {
            if (!response.success) return;
            renderAliases(response.data);
        });
    }

    function renderAliases(aliases) {
        if (!aliases || !aliases.length) {
            $aliasesContainer.html('<p class="description" style="margin:0;">No aliases yet. Add one below.</p>');
            return;
        }

        var html = '<table class="widefat striped" style="margin-bottom:10px;"><thead><tr>' +
            '<th>Alias</th><th>Intervals</th><th>Notes</th><th style="width:60px;"></th>' +
            '</tr></thead><tbody>';

        aliases.forEach(function(a) {
            html += '<tr>' +
                '<td><strong>' + escapeHtml(a.alt_name) + '</strong></td>' +
                '<td><code style="font-size:11px;">' + escapeHtml(a.interval_labels || '') + '</code></td>' +
                '<td><code style="font-size:11px;">' + escapeHtml(a.notes || '') + '</code></td>' +
                '<td><button class="button button-small sbn-delete-alias" data-id="' + a.id + '">Delete</button></td>' +
                '</tr>';
        });

        html += '</tbody></table>';
        $aliasesContainer.html(html);
    }

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    // Add alias
    $(document).on('click', '#sbnAddAlias', function() {
        if (!diagramId) {
            alert('Please save the shape first before adding aliases.');
            return;
        }

        var $btn = $(this);
        $btn.prop('disabled', true);

        $.post(ajaxurl, {
            action: 'sbn_save_alias',
            nonce: sbnChordDiagrams.nonce,
            diagram_id: diagramId,
            alt_root_note: $('#sbnAliasRoot').val(),
            alt_quality: $('#sbnAliasQuality').val(),
            alt_extensions: $('#sbnAliasExtensions').val(),
            alt_bass_note: $('#sbnAliasBass').val()
        }).done(function(response) {
            if (response.success) {
                $('#sbnAliasExtensions').val('');
                $('#sbnAliasBass').val('');
                loadAliases();
            } else {
                alert('Error: ' + (response.data || 'Unknown error'));
            }
        }).always(function() {
            $btn.prop('disabled', false);
        });
    });

    // Delete alias
    $(document).on('click', '.sbn-delete-alias', function() {
        var $btn = $(this);
        var id = $btn.data('id');

        $btn.prop('disabled', true);
        $.post(ajaxurl, {
            action: 'sbn_delete_alias',
            nonce: sbnChordDiagrams.nonce,
            id: id
        }).done(function(response) {
            if (response.success) {
                loadAliases();
            }
        });
    });

    // Load aliases on page load
    loadAliases();

})(jQuery);
