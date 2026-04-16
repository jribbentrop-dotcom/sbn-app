/**
 * Chord Library Redesign
 * VERSION 8.0.0 - PHASE 1: Unified State Management
 * 
 * ARCHITECTURE:
 * - Single filterState object is the source of truth
 * - Every user action mutates filterState, then calls renderFromState()
 * - renderFromState() handles: grid content, card visibility, status bar, pills
 * - Two grid modes: 'browse' (original PHP cards) and 'chord' (AJAX transposed cards)
 * 
 * CHANGES FROM v7.2.0:
 * - Removed duplicate performTextFilter
 * - Introduced filterState replacing scattered state variables
 * - All filters compose (chord search + voicing + inversion work together)
 * - Metadata clicks ADD to filters instead of blowing away all state
 * - Status bar and pills derived from filterState (never set ad-hoc)
 * - AJAX cards now include data-root, data-inversion, data-extensions
 * - Advanced filter dropdowns stay synced with filterState
 */

(function($) {
    'use strict';
    
    console.log('🎸 SBN Chord Library v8.0.0 loaded');
    console.log('✅ Phase 1: Unified state management');
    
    // =============================================
    // UNIFIED STATE
    // =============================================
    
    var filterState = {
        chordSearch: null,   // { root, quality, raw } or null
        voicing: null,       // 'drop2', 'shell', etc.
        inversion: null,     // 'inv1', 'inv2', 'inv3'
        quality: null,       // quality filter (in chord mode: re-triggers AJAX with new quality)
        extension: null,     // '9', '11', '13', 'b9', '#11', etc.
        textQuery: null,     // free text like 'drop 2'
        popularity: null,    // 'occasional', 'common', 'essential', 'iconic'
        difficulty: null,    // 1–5 integer (as string from data attr)
    };
    
    var gridMode = 'browse';
    var originalGridHTML = null;
    var currentAjaxResults = null;
    var searchTimeout = null;
    var pendingDetailNavigate = false; // navigate to detail page after AJAX chord search (?modal=1 replaced)

    // =============================================
    // POPULARITY HELPERS
    // =============================================

    /**
     * Convert a raw popularity count (number of songs) into a labelled tier.
     * Returns { tier, label, dots, title } for display.
     */
    function popularityTier(count) {
        count = parseInt(count) || 0;
        if (count === 0) return null;
        var tier, label, level;
        if      (count >= 11) { tier = 'iconic';     label = 'Iconic';     level = 5; }
        else if (count >= 6)  { tier = 'essential';  label = 'Essential';  level = 4; }
        else if (count >= 3)  { tier = 'common';     label = 'Common';     level = 3; }
        else if (count >= 1)  { tier = 'occasional'; label = 'Occasional'; level = 2; }
        var filled  = Math.min(level, 5);
        var dots = '';
        for (var i = 1; i <= 5; i++) {
            dots += '<span class="sbn-pop-dot' + (i <= filled ? ' filled' : '') + '"></span>';
        }
        var songWord = count === 1 ? 'song' : 'songs';
        return {
            tier: tier,
            label: label,
            dots: dots,
            count: count,
            title: label + ' · ' + count + ' ' + songWord,
            // Full inline HTML block ready to insert
            html: '<span class="sbn-pop-indicator sbn-pop-' + tier + '" title="' + label + ' · ' + count + ' ' + songWord + '">' +
                      '<span class="sbn-pop-dots">' + dots + '</span>' +
                      '<span class="sbn-pop-label">' + label + '</span>' +
                      '<span class="sbn-pop-count">· ' + count + ' ' + songWord + '</span>' +
                  '</span>'
        };
    }

    /**
     * Stamp data-popularity on all browse-mode cards and sort the grid.
     * Fires one lightweight AJAX call on page load.
     */
    function loadAndSortByPopularity($grid) {
        if (!$grid.length) return;
        $.ajax({
            url: sbnChordSearch.ajaxurl,
            type: 'POST',
            data: { action: 'sbn_get_popularity_map', nonce: sbnChordSearch.nonce },
            success: function(response) {
                if (!response.success || !response.data) return;
                var map = response.data; // { diagram_id: popularity_count }
                var $cards = $grid.find('.sbn-chord-card[data-diagram-id]');
                $cards.each(function() {
                    var id = $(this).data('diagram-id');
                    var pop = map[id] ? parseInt(map[id]) : 0;
                    $(this).attr('data-popularity', pop);
                });
                sortGridByPopularity($grid);
            }
        });
    }

    function sortGridByPopularity($grid) {
        var $cards = $grid.find('.sbn-chord-card').toArray();
        $cards.sort(function(a, b) {
            var pa = parseInt($(a).attr('data-popularity')) || 0;
            var pb = parseInt($(b).attr('data-popularity')) || 0;
            return pb - pa; // descending
        });
        $cards.forEach(function(card) { $grid.append(card); });
    }
    
    /**
     * Bass interval labels for archetype slash chord display.
     * Maps quality + inversion → interval string (e.g., m7 + inv1 → "♭3").
     * Used when displaying chords without a root note (browse mode).
     */
    var bassIntervalLabels = {
        'maj7':  { inv1: '3',  inv2: '5',  inv3: '7' },
        'maj6':  { inv1: '3',  inv2: '5',  inv3: '6' },
        'm7':    { inv1: '♭3', inv2: '5',  inv3: '♭7' },
        'm6':    { inv1: '♭3', inv2: '5',  inv3: '6' },
        '7':     { inv1: '3',  inv2: '5',  inv3: '♭7' },
        'dom7':  { inv1: '3',  inv2: '5',  inv3: '♭7' },
        'm7b5':  { inv1: '♭3', inv2: '♭5', inv3: '♭7' },
        'o7':    { inv1: '♭3', inv2: '♭5', inv3: '𝄫7' },
        'mMaj7': { inv1: '♭3', inv2: '5',  inv3: '7' },
        'aug7':  { inv1: '3',  inv2: '♯5', inv3: '♭7' },
        'maj':   { inv1: '3',  inv2: '5' },
        'min':   { inv1: '♭3', inv2: '5' },
        'aug':   { inv1: '3',  inv2: '♯5' },
        'dim':   { inv1: '♭3', inv2: '♭5' },
        'sus4':  { inv1: '4',  inv2: '5' },
        'sus2':  { inv1: '2',  inv2: '5' },
        'add9':  { inv1: '9',  inv2: '3', inv3: '5' },
    };
    
    /**
     * Get the bass interval HTML for an archetype chord.
     * Returns the formatted .sbn-chord-bass span, or empty string for root position.
     */
    function getBassIntervalHtml(quality, inversion) {
        if (!inversion || inversion === 'root') return '';
        var map = bassIntervalLabels[quality];
        if (!map || !map[inversion]) return '';
        var label = map[inversion];
        // Split accidental from number for proper rendering
        var match = label.match(/^([♭♯𝄫]*)(\d+)$/);
        if (match) {
            var acc = match[1] ? '<span class="sbn-bass-accidental">' + match[1] + '</span>' : '';
            return '<span class="sbn-chord-bass sbn-clickable-metadata" data-search-type="inversion" data-search-value="' + inversion + '">/' + acc + escapeHtml(match[2]) + '</span>';
        }
        return '<span class="sbn-chord-bass sbn-clickable-metadata" data-search-type="inversion" data-search-value="' + inversion + '">/' + escapeHtml(label) + '</span>';
    }
    
    // =============================================
    // INITIALIZATION
    // =============================================
    
    $(document).ready(function() {
        var $grid = $('.sbn-chords-grid');
        if ($grid.length) {
            // Capture the raw PHP-rendered HTML BEFORE any fretboard hydration.
            // This ensures restoreOriginalGrid() always starts from clean HTML
            // and re-hydrates with the shared chord card component.
            originalGridHTML = $grid.html();
            // data-popularity is stamped by the PHP template (ORDER BY popularity DESC)
            // so no AJAX sort needed on first load. sortGridByPopularity() is still
            // available for re-sorting after filter restores.
        }
        
        initSearchInput();
        initFilterDropdowns();
        initExamples();
        initPhase2Controls();
        makeInitialChordsClickable();
        initArchetypePanel();
        
        // Hydrate all fretboards via the shared component.
        // This replaces the legacy renderMiniFretboard from chord-diagrams-admin.js
        // which also runs in document.ready but uses setTimeout for dot placement.
        // By calling hydrateAll here, we ensure consistent rendering across all cards.
        renderAllDiagrams();
        
        renderStatusAndPills();
        renderInfoPanel();
        
        // Check URL for ?chord= parameter (e.g., from leadsheet links)
        // Note: We use 'chord' not 'search' to avoid WordPress query var conflicts
        var urlParams = new URLSearchParams(window.location.search);
        var urlSearch = urlParams.get('chord');
        var urlModal = urlParams.get('modal');
        console.log('[SBN] URL params - chord:', urlSearch, 'modal:', urlModal);
        if (urlSearch) {
            var $input = $('#sbn-unified-search');
            console.log('[SBN] Search input found:', $input.length > 0);
            $input.val(urlSearch);
            $('#sbn-search-clear').show();
            handleSearchInput(urlSearch);
            console.log('[SBN] handleSearchInput called, filterState:', JSON.stringify(filterState));
            
            // Navigate straight to the detail page when ?modal=1 is present
            // (replaces the old auto-open modal behaviour)
            if (urlModal === '1') {
                pendingDetailNavigate = true;
            }
        }
    });
    
    // =============================================
    // ARCHETYPE PANEL
    // =============================================
    
    function initArchetypePanel() {
        var $panel = $('#sbn-archetype-panel');
        if (!$panel.length) return;
        
        // Hydrate all fretboards inside the panel (tiles + drawers)
        $panel.find('.sbn-chord-fretboard[data-diagram]').each(function() {
            var el = this;
            var data = {
                diagram: el.getAttribute('data-diagram'),
                startFret: el.getAttribute('data-start-fret') || '1',
                intervals: (el.getAttribute('data-intervals') || '').toString(),
                notes: '',
                displayMode: 'fingering'
            };
            el.innerHTML = SbnChordCard.renderFretboard(data);
            requestAnimationFrame(function() {
                SbnChordCard.hydrateFretboard(el, data);
            });
        });
        
        // Click tile → show its drawer (smooth swap, no close/reopen)
        $panel.on('click', '.sbn-archetype-tile', function() {
            var $tile = $(this);
            var family = $tile.data('family');
            var wasActive = $tile.hasClass('active');
            var $drawer = $('#sbn-drawer-' + family);
            
            // Deactivate all tiles
            $panel.find('.sbn-archetype-tile.active').removeClass('active');
            
            if (wasActive) {
                // Clicking the same tile: close the drawer
                $panel.find('.sbn-archetype-drawer.active').removeClass('active');
                return;
            }
            
            // Activate the clicked tile
            $tile.addClass('active');
            
            // If a different drawer is already open, cross-fade the content
            var $openDrawer = $panel.find('.sbn-archetype-drawer.active');
            
            if ($openDrawer.length && $openDrawer.attr('id') !== $drawer.attr('id')) {
                // Swap: fade out old content, fade in new
                $openDrawer.removeClass('active');
                $drawer.addClass('active sbn-drawer-swap');
                // Remove swap class after animation
                setTimeout(function() { $drawer.removeClass('sbn-drawer-swap'); }, 200);
            } else {
                // Fresh open
                $drawer.addClass('active');
            }
            
            // Position the arrow above the active tile
            var $arrow = $drawer.find('.sbn-drawer-arrow');
            var tileLeft = $tile.position().left;
            var tileWidth = $tile.outerWidth();
            var drawerLeft = $drawer.position().left;
            var arrowPos = tileLeft - drawerLeft + (tileWidth / 2) - 7;
            $arrow.css('left', Math.max(10, arrowPos) + 'px');
        });
    }
    
    // =============================================
    // STATE HELPERS
    // =============================================
    
    function resetState() {
        filterState.chordSearch = null;
        filterState.voicing = null;
        filterState.inversion = null;
        filterState.quality = null;
        filterState.extension = null;
        filterState.textQuery = null;
        filterState.popularity = null;
        filterState.difficulty = null;
    }
    
    function hasActiveFilters() {
        return !!(filterState.chordSearch || filterState.voicing ||
                  filterState.inversion || filterState.quality || filterState.extension ||
                  filterState.textQuery || filterState.popularity || filterState.difficulty);
    }
    
    function hasSecondaryFilters() {
        return !!(filterState.voicing ||
                  filterState.inversion || filterState.quality || filterState.extension ||
                  filterState.textQuery || filterState.popularity || filterState.difficulty);
    }
    
    // =============================================
    // MASTER RENDER
    // =============================================
    
    // Track what the last AJAX query was, so we know when to refetch
    var lastAjaxQuery = null;
    
    function renderFromState() {
        // STEP 1: Ensure correct grid content
        if (filterState.chordSearch) {
            // Build the actual query string that would go to AJAX
            var currentQuery = filterState.chordSearch.raw;
            
            if (gridMode !== 'chord' || currentQuery !== lastAjaxQuery) {
                // Need to fetch (new search, or quality changed)
                lastAjaxQuery = currentQuery;
                fetchTransposedChords(filterState.chordSearch);
                return; // AJAX callback will continue
            }
        } else {
            if (gridMode !== 'browse') {
                restoreOriginalGrid();
                lastAjaxQuery = null;
            }
        }
        
        // STEP 2: Apply secondary filters
        applySecondaryFilters();
        
        // STEP 3: Update UI
        renderStatusAndPills();
        syncSidebarToState();
        renderInfoPanel();
    }
    
    // =============================================
    // AJAX CHORD SEARCH (transposition from DB patterns)
    // =============================================
    
    function fetchTransposedChords(chordSearch) {
        console.log('[SBN] fetchTransposedChords called:', chordSearch.raw);
        var $statusContainer = $('#sbn-search-status');
        var $grid = $('.sbn-chords-grid');
        var $noResults = $('#sbn-no-results');
        
        // Hide archetype panel during search
        $('.sbn-chord-library-redesign').addClass('sbn-search-active');
        
        $statusContainer.html(
            '<div class="sbn-status-loading">Searching for ' + escapeHtml(chordSearch.raw) + '...</div>'
        ).show();
        
        $.ajax({
            url: sbnChordSearch.ajaxurl,
            type: 'POST',
            data: {
                action: 'sbn_search_chords',
                nonce: sbnChordSearch.nonce,
                query: chordSearch.raw
            },
            success: function(response) {
                console.log('[SBN] AJAX response:', response.success, 'results:', response.data?.results?.length);
                if (response.success && response.data.results && response.data.results.length > 0) {
                    currentAjaxResults = response.data;
                    
                    $grid.empty();
                    response.data.results.forEach(function(chord) {
                        $grid.append(renderChordCard(chord));
                    });
                    
                    setTimeout(function() {
                        $grid.find('.sbn-chord-fretboard[data-diagram]').each(function() {
                            var $c = $(this);
                            if (!$c.data('display-mode')) $c.data('display-mode', 'fingering');
                            renderEnhancedFretboard($c);
                        });
                    }, 100);
                    
                    gridMode = 'chord';
                    $noResults.hide();
                    applySecondaryFilters();
                    renderStatusAndPills();
                    syncSidebarToState();
                    renderInfoPanel();
                    
                    // Navigate to detail page if triggered via ?modal=1 deep-link
                    if (pendingDetailNavigate) {
                        pendingDetailNavigate = false;
                        setTimeout(function() {
                            var $firstCard = $grid.find('.sbn-chord-card:first');
                            if ($firstCard.length) {
                                navigateToChordDetail($firstCard);
                            }
                        }, 200);
                    }
                } else {
                    currentAjaxResults = null;
                    gridMode = 'chord';
                    $grid.empty();
                    $noResults.show();
                    renderStatusAndPills();
                    syncSidebarToState();
                    renderInfoPanel();
                }
            },
            error: function(xhr, status, error) {
                currentAjaxResults = null;
                $statusContainer.html(
                    '<div class="sbn-status-loading">Search error: ' + escapeHtml(error) + '</div>'
                );
            }
        });
    }
    
    function restoreOriginalGrid() {
        var $grid = $('.sbn-chords-grid');
        if (originalGridHTML) {
            $grid.html(originalGridHTML);
            setTimeout(function() {
                renderAllDiagrams();
                makeInitialChordsClickable();
            }, 100);
        }
        gridMode = 'browse';
        currentAjaxResults = null;
        
        // Show archetype panel again
        $('.sbn-chord-library-redesign').removeClass('sbn-search-active');
    }
    
    // =============================================
    // SECONDARY FILTERS (work on current DOM cards)
    // =============================================
    
    function applySecondaryFilters() {
        var $cards = $('.sbn-chord-card');
        var $noResults = $('#sbn-no-results');
        
        if (!hasSecondaryFilters()) {
            $cards.removeClass('sbn-filtered-out sbn-match');
            $noResults.hide();
            return;
        }
        
        var matchCount = 0;
        
        $cards.each(function() {
            var $card = $(this);
            var matches = true;
            
            if (filterState.voicing) {
                if (($card.data('voicing') || '').toString() !== filterState.voicing) matches = false;
            }
            if (filterState.inversion) {
                if (($card.data('inversion') || 'root').toString() !== filterState.inversion) matches = false;
            }
            if (filterState.quality) {
                var cardQual = ($card.data('quality') || '').toString().toLowerCase();
                var filterQual = (filterState.quality || '').toString().toLowerCase();
                if (cardQual !== filterQual) matches = false;
            }
            if (filterState.extension) {
                var cardExt = ($card.data('extensions') || '').toString().toLowerCase();
                var filterExt = (filterState.extension || '').toString().toLowerCase();
                if (cardExt !== filterExt) matches = false;
            }
            if (filterState.popularity) {
                var cardPop = parseInt($card.attr('data-popularity')) || 0;
                var popTier = '';
                if      (cardPop >= 11) popTier = 'iconic';
                else if (cardPop >= 6)  popTier = 'essential';
                else if (cardPop >= 3)  popTier = 'common';
                else if (cardPop >= 1)  popTier = 'occasional';
                if (popTier !== filterState.popularity) matches = false;
            }
            if (filterState.difficulty) {
                var cardDiff = ($card.attr('data-difficulty') || '0').toString();
                if (cardDiff !== filterState.difficulty.toString()) matches = false;
            }
            if (filterState.textQuery) {
                if (!cardMatchesTextQuery($card, filterState.textQuery)) matches = false;
            }
            
            if (matches) {
                $card.removeClass('sbn-filtered-out').addClass('sbn-match');
                matchCount++;
            } else {
                $card.removeClass('sbn-match').addClass('sbn-filtered-out');
            }
        });
        
        if (matchCount === 0) { $noResults.show(); } else { $noResults.hide(); }
    }
    
    function cardMatchesTextQuery($card, query) {
        var q = query.toLowerCase();
        var quality = ($card.data('quality') || '').toString().toLowerCase();
        var voicing = ($card.data('voicing') || '').toString().toLowerCase();
        var extensions = ($card.data('extensions') || '').toString().toLowerCase();
        var searchText = ($card.data('search') || '').toString().toLowerCase();
        
        // Priority 1: Exact quality
        if (quality === q) return true;
        // Priority 2: Exact voicing
        if (voicing === q) return true;
        // Priority 3: Extension
        if (extensions && extensions.indexOf(q) !== -1) return true;
        // Priority 4: General search (but block known qualities from substring matching)
        if (searchText.indexOf(q) !== -1) {
            var commonQualities = ['m7','maj7','m6','maj6','dom7','7','dim7','o7','m7b5','aug','dim','sus4','sus2','min','maj'];
            if (commonQualities.indexOf(q) !== -1) return false;
            return true;
        }
        return false;
    }
    
    // =============================================
    // VOICING COUNT BAR (replaces old pills/status)
    // =============================================
    
    function renderStatusAndPills() {
        var $status = $('#sbn-search-status');
        
        if (!hasActiveFilters()) {
            $status.empty().hide();
            return;
        }
        
        var $allCards = $('.sbn-chord-card');
        var count;
        if (hasSecondaryFilters()) {
            count = $allCards.filter('.sbn-match').length;
        } else {
            count = $allCards.not('.sbn-filtered-out').length;
        }
        
        var countText = 'Showing <strong>' + count + ' voicing' + (count !== 1 ? 's' : '') + '</strong>';
        
        var html = '<div class="sbn-count-bar">';
        html += '<span class="sbn-count-text">' + countText + '</span>';
        html += '<button type="button" class="sbn-count-clear" id="sbn-count-clear-all">Clear All</button>';
        html += '</div>';
        
        $status.html(html).show();
    }
    
    // =============================================
    // EDUCATIONAL INFO PANEL
    // =============================================
    
    /**
     * Educational content data.
     * Each entry provides intervals, a short description, and usage context.
     * Lucas: expand and refine these descriptions with your teaching voice.
     */
    var eduContent = {
        
        // === CHORD QUALITIES ===
        qualities: {
            'maj': {
                name: 'Major Triad',
                symbol: 'C',
                intervals: 'R – 3 – 5',
                notes_in_c: 'C – E – G',
                description: 'The foundation of Western harmony. Three notes — root, major third, perfect fifth — producing a bright, stable, resolved sound.',
                usage: 'The home base. Appears as the I, IV, and V chord in major keys. When you hear "happy" or "bright" in music, chances are a major triad is doing the work.'
            },
            'min': {
                name: 'Minor Triad',
                symbol: 'Cm',
                intervals: 'R – ♭3 – 5',
                notes_in_c: 'C – E♭ – G',
                description: 'Lower the third by a half step and the entire character shifts. The minor triad sounds darker, more introspective — but equally stable.',
                usage: 'The ii, iii, and vi chords in major keys. Dominates minor keys as the i chord. Essential for ballads, jazz standards, and anything with emotional depth.'
            },
            'm': {
                name: 'Minor Triad',
                symbol: 'Cm',
                intervals: 'R – ♭3 – 5',
                notes_in_c: 'C – E♭ – G',
                description: 'Lower the third by a half step and the entire character shifts. The minor triad sounds darker, more introspective — but equally stable.',
                usage: 'The ii, iii, and vi chords in major keys. Dominates minor keys as the i chord. Essential for ballads, jazz standards, and anything with emotional depth.'
            },
            'maj7': {
                name: 'Major 7th',
                symbol: 'Cmaj7',
                intervals: 'R – 3 – 5 – 7',
                notes_in_c: 'C – E – G – B',
                description: 'Add the major seventh to a major triad and you get a lush, sophisticated sound. The interval between root and seventh is just a half step shy of an octave, creating a gentle tension that floats rather than pushes.',
                usage: 'The signature sound of bossa nova and smooth jazz. Functions as I and IV in major keys. Think Jobim, think "Girl from Ipanema." When you want warmth without urgency.'
            },
            'm7': {
                name: 'Minor 7th',
                symbol: 'Cm7',
                intervals: 'R – ♭3 – 5 – ♭7',
                notes_in_c: 'C – E♭ – G – B♭',
                description: 'The workhorse of jazz harmony. A minor triad plus a flatted seventh creates a mellow, warm sound that sits comfortably in almost any context.',
                usage: 'The ii chord in major ii-V-I progressions. The i chord in minor keys. Appears everywhere in jazz, R&B, neo-soul, and bossa nova. If you learn one four-note chord type, make it this one.'
            },
            'dom7': {
                name: 'Dominant 7th',
                symbol: 'C7',
                intervals: 'R – 3 – 5 – ♭7',
                notes_in_c: 'C – E – G – B♭',
                description: 'Major triad plus a flatted seventh. The tritone between the third and seventh creates tension that wants to resolve — this is the engine of harmonic motion.',
                usage: 'The V chord that pulls you home to I. The backbone of blues (I7, IV7, V7). In jazz, dominant chords appear everywhere — as secondary dominants, tritone substitutions, and extended dominant chains.'
            },
            '7': {
                name: 'Dominant 7th',
                symbol: 'C7',
                intervals: 'R – 3 – 5 – ♭7',
                notes_in_c: 'C – E – G – B♭',
                description: 'Major triad plus a flatted seventh. The tritone between the third and seventh creates tension that wants to resolve — this is the engine of harmonic motion.',
                usage: 'The V chord that pulls you home to I. The backbone of blues. In jazz, dominant chords appear everywhere — as secondary dominants, tritone subs, and extended dominant chains.'
            },
            'dim': {
                name: 'Diminished Triad',
                symbol: 'Cdim',
                intervals: 'R – ♭3 – ♭5',
                notes_in_c: 'C – E♭ – G♭',
                description: 'Two stacked minor thirds create an unstable, tense sound. The diminished fifth (tritone from root) gives this chord its restless character.',
                usage: 'Appears naturally as vii° in major keys. Often used as a passing chord between two more stable chords. Handle with care — a little goes a long way.'
            },
            'dim7': {
                name: 'Diminished 7th',
                symbol: 'Cdim7',
                intervals: 'R – ♭3 – ♭5 – ♭♭7',
                notes_in_c: 'C – E♭ – G♭ – B♭♭',
                description: 'Three stacked minor thirds dividing the octave into four equal parts. Symmetrical and mysterious — every note can function as the root.',
                usage: 'The classic dramatic chord. Used as a passing chord, a dominant substitute, or for chromatic voice leading. Common in jazz standards, classical, and film scores.'
            },
            'o7': {
                name: 'Diminished 7th',
                symbol: 'C°7',
                intervals: 'R – ♭3 – ♭5 – ♭♭7',
                notes_in_c: 'C – E♭ – G♭ – B♭♭',
                description: 'Three stacked minor thirds dividing the octave into four equal parts. Symmetrical and mysterious — every note can function as the root.',
                usage: 'Common passing chord and dominant substitute in jazz and classical harmony.'
            },
            'm7b5': {
                name: 'Half-Diminished (Minor 7♭5)',
                symbol: 'Cm7♭5',
                intervals: 'R – ♭3 – ♭5 – ♭7',
                notes_in_c: 'C – E♭ – G♭ – B♭',
                description: 'A minor seventh chord with a flatted fifth. Less tense than fully diminished — the minor seventh softens the sound, giving it a melancholy, yearning quality.',
                usage: 'The ii chord in minor key ii-V-i progressions. Essential for minor jazz harmony. Also appears as viiø in major keys. If you play jazz, you need this chord.'
            },
            'aug': {
                name: 'Augmented Triad',
                symbol: 'Caug',
                intervals: 'R – 3 – ♯5',
                notes_in_c: 'C – E – G♯',
                description: 'Two stacked major thirds. Like diminished, it\'s symmetrical — but where diminished contracts, augmented expands. An unsettled, reaching sound.',
                usage: 'Often used as a passing chord or dominant variation (V+). Common in Beatles progressions, film scores, and as a chromatic connector between chords.'
            },
            'm6': {
                name: 'Minor 6th',
                symbol: 'Cm6',
                intervals: 'R – ♭3 – 5 – 6',
                notes_in_c: 'C – E♭ – G – A',
                description: 'A minor triad with an added major sixth. The sixth adds a bittersweet brightness to the minor quality — tense but beautiful.',
                usage: 'Classic minor tonic chord in jazz. Often interchangeable with m7. Characteristic sound of gypsy jazz and older jazz styles.'
            },
            'maj6': {
                name: 'Major 6th',
                symbol: 'C6',
                intervals: 'R – 3 – 5 – 6',
                notes_in_c: 'C – E – G – A',
                description: 'A major triad with an added sixth. Warmer and more relaxed than maj7 — less "pretty," more grounded.',
                usage: 'Common tonic chord in swing and early jazz. Often used instead of maj7 for a vintage feel. The "jazz ending" chord.'
            },
            'sus4': {
                name: 'Suspended 4th',
                symbol: 'Csus4',
                intervals: 'R – 4 – 5',
                notes_in_c: 'C – F – G',
                description: 'The third is replaced by a perfect fourth, removing the major/minor identity. The result is open, ambiguous, and full of potential.',
                usage: 'Creates tension that typically resolves to a major or minor chord. Widely used in pop, rock, and gospel. Think Hendrix, think "Pinball Wizard."'
            },
            'sus2': {
                name: 'Suspended 2nd',
                symbol: 'Csus2',
                intervals: 'R – 2 – 5',
                notes_in_c: 'C – D – G',
                description: 'The third is replaced by a major second. Even more open than sus4 — airy, modern, and neither major nor minor.',
                usage: 'Common in pop and modern worship music. Creates space and ambiguity. Works beautifully as a tonic or as a color chord.'
            },
            'm9': {
                name: 'Minor 9th',
                symbol: 'Cm9',
                intervals: 'R – ♭3 – 5 – ♭7 – 9',
                notes_in_c: 'C – E♭ – G – B♭ – D',
                description: 'A minor 7th chord with an added ninth. The ninth opens up the sound, making it lusher and more expansive than a plain m7.',
                usage: 'The sophisticated ii chord in jazz. Common in R&B, neo-soul, and contemporary jazz. Erykah Badu and D\'Angelo live here.'
            },
            'maj9': {
                name: 'Major 9th',
                symbol: 'Cmaj9',
                intervals: 'R – 3 – 5 – 7 – 9',
                notes_in_c: 'C – E – G – B – D',
                description: 'Major 7th plus a ninth. Dreamy, expansive, and lush — the quintessential "beautiful" chord.',
                usage: 'Tonic chord in sophisticated pop and jazz. The "ending on a cloud" sound. Works as I or IV.'
            },
            '9': {
                name: 'Dominant 9th',
                symbol: 'C9',
                intervals: 'R – 3 – 5 – ♭7 – 9',
                notes_in_c: 'C – E – G – B♭ – D',
                description: 'Dominant 7th plus a ninth. The added ninth softens the dominant edge, creating a funkier, more colorful tension.',
                usage: 'Signature funk and R&B chord. Think James Brown, Stevie Wonder. Also common in blues and jazz as an enriched V chord.'
            },
            '13': {
                name: 'Dominant 13th',
                symbol: 'C13',
                intervals: 'R – 3 – 5 – ♭7 – 9 – 13',
                notes_in_c: 'C – E – G – B♭ – D – A',
                description: 'The tallest commonly used chord. Stacks a 13th (same as a 6th) on top of a dominant structure. Rich, complex, and full of motion.',
                usage: 'Classic jazz dominant chord. Resolves beautifully to maj7 or 6. Common as a V chord in standards and big band arrangements.'
            },
            'add9': {
                name: 'Add 9',
                symbol: 'Cadd9',
                intervals: 'R – 9 – 3 – 5',
                notes_in_c: 'C – D – E – G',
                description: 'A major triad with an added ninth — no seventh. The ninth adds sparkle and openness without the sophistication of a full ninth chord.',
                usage: 'A staple of pop, folk, and acoustic guitar. Cadd9 (x32030) is one of the most iconic guitar shapes ever. Adds color without adding complexity.'
            },
            '5': {
                name: 'Power Chord',
                symbol: 'C5',
                intervals: 'R – 5',
                notes_in_c: 'C – G',
                description: 'Just root and fifth — no third means no major or minor identity. Raw, ambiguous, and powerful under distortion.',
                usage: 'The foundation of rock, punk, and metal guitar. Works with any amount of gain because there\'s no third to create beating. Simple but effective.'
            }
        },
        
        // === VOICING TYPES ===
        voicings: {
            'archetype': {
                name: 'Archetypes',
                description: 'The fundamental open-position guitar chords — E, Em, A, Am, D, Dm, C, G — and their 7th-chord siblings. These are the shapes every guitarist learns first.',
                detail: 'Archetype shapes are the building blocks of guitar harmony. Each one is transposable: move the shape up the neck with a barré and you get every key. The open version is beginner-level; the barré version is early intermediate.',
                tip: 'Master all 8 basic archetypes first, then learn their 7th-chord variants (E7, Am7, Dmaj7, etc.). Once those are comfortable, practice the barré versions starting with the E and A shapes.'
            },
            'drop2': {
                name: 'Drop 2',
                description: 'Take a closed-position chord and drop the second-highest note down an octave. This opens up the voicing, spreading the notes across a wider range while keeping the sound balanced.',
                detail: 'Drop 2 voicings are the backbone of jazz guitar comping. They sit naturally on the middle four strings and voice-lead smoothly through chord progressions. Every jazz guitarist needs these in all inversions and on all string sets.',
                tip: 'Practice connecting Drop 2 voicings through ii-V-I progressions. Move the minimum number of fingers between chords — that\'s where the magic of voice leading happens.'
            },
            'drop3': {
                name: 'Drop 3',
                description: 'Drop the third-highest note from a closed voicing down an octave. This creates a wider spread than Drop 2, with a gap in the middle.',
                detail: 'Drop 3 voicings span 5 or 6 strings and have a bigger, more orchestral sound. They work well for solo guitar and chord melody because of their wide range.',
                tip: 'Drop 3 shapes often skip a string in the middle. This takes getting used to, but the payoff is a rich, full sound that fills more sonic space.'
            },
            'shell': {
                name: 'Shell Voicings',
                description: 'Strip a chord down to its bare essentials: root, third, and seventh. Three notes that define the chord quality with nothing extra.',
                detail: 'Shell voicings are the most practical chords for comping in a band context. They leave room for other instruments, they\'re easy to grab on the fly, and they contain all the harmonic information needed.',
                tip: 'Start with shells on the 6th and 5th strings. Learn to comp through entire standards using only shells — you\'ll be amazed how complete they sound.'
            },
            'rootless': {
                name: 'Rootless Voicings',
                description: 'Remove the root entirely and let the bass player handle it. What remains is the chord\'s color tones — third, seventh, and extensions.',
                detail: 'Rootless voicings are what pianists in jazz combos play. On guitar, they free up your fingers for extensions and alterations. They sound thin alone but magical in a band.',
                tip: 'These only work well when a bass player is covering the root. Solo guitar? Add the root back. In a trio or quartet? Go rootless and enjoy the freedom.'
            },
            'closed': {
                name: 'Closed Position',
                description: 'All four chord tones of a seventh chord packed into the smallest possible range — within one octave. Compact, dense, and harmonically concentrated.',
                detail: 'Closed voicings are the "textbook" chord shapes. They\'re how chords are spelled in theory, and they\'re the starting point from which Drop 2 and Drop 3 are derived.',
                tip: 'On guitar, closed voicings are harder to finger than open ones due to the tight spacing. But understanding them is essential — they\'re the foundation for all other voicing types.'
            },
            'closed_triads': {
                name: 'Closed Triads',
                description: 'Three-note chords in close position — root, third, and fifth all within one octave. Systematic inversions across all string sets.',
                detail: 'Closed triads are the building blocks of harmony on the guitar. Each triad has three inversions (root position, 1st, 2nd) playable on every group of three adjacent strings.',
                tip: 'Learn all three inversions on one string set, then connect them up and down the neck. This is how you unlock the entire fretboard for any chord.'
            },
            'spread_triads': {
                name: 'Spread Triads',
                description: 'Three-note chords with notes spread across a wider range than one octave. Bigger sound than closed triads, with more space between voices.',
                detail: 'Spread triads open up the sound by placing chord tones on non-adjacent strings or across a wider interval range. They have a more guitar-idiomatic sound and are great for fills and melodic playing.',
                tip: 'Spread triads work especially well for country, R&B, and jazz fills. They cover more of the fretboard and blend well in ensemble settings.'
            },
            'custom': {
                name: 'Custom Voicings',
                description: 'Unique shapes that don\'t fit neatly into standard voicing categories. Includes open-string voicings, hybrid grips, and one-of-a-kind shapes.',
                detail: 'The guitar\'s tuning creates many beautiful voicings that aren\'t easily classified. These custom shapes often exploit open strings, unusual intervals, or guitar-specific fingerings for distinctive sounds.',
                tip: 'Don\'t ignore these just because they\'re "irregular." Some of the most beautiful guitar chords — like Fmaj7♯11 or open-string add9 voicings — live in this category.'
            }
        },
        
        // === INVERSIONS ===
        inversions: {
            'root': {
                name: 'Root Position',
                description: 'The root is the lowest note. This is the chord in its most grounded, stable form — what you\'d naturally play first.',
                context: 'Root position chords define the harmony clearly. Use them when you want the chord to land with authority.'
            },
            'inv1': {
                name: '1st Inversion',
                description: 'The third is now the lowest note. The chord sounds lighter, less grounded — the same harmony viewed from a different angle.',
                context: 'First inversions create smoother bass movement between chords. Instead of the bass jumping by fourths and fifths, it can move by steps. Essential for good voice leading.'
            },
            'inv2': {
                name: '2nd Inversion',
                description: 'The fifth is the lowest note. Slightly unstable compared to root position, with a more open, suspended quality.',
                context: 'Second inversions are transitional — they connect other chords smoothly. The classic I6/4 (second inversion tonic) sets up a perfect cadence.'
            },
            'inv3': {
                name: '3rd Inversion',
                description: 'The seventh is the lowest note. Only possible with seventh chords. Creates a strong pull toward the next chord — the seventh in the bass wants to resolve downward.',
                context: 'Third inversions are powerful voice-leading tools. The seventh in the bass typically resolves down by a half step to the third of the next chord. Smooth, sophisticated, very jazz.'
            }
        },
        
        // === EXTENSIONS ===
        extensions: {
            '9': {
                name: '9th',
                description: 'The 9th is the same note as the 2nd, but up an octave. It adds color and openness without changing the chord\'s fundamental character.',
                context: 'Added 9ths work on almost any chord type. They\'re the most "safe" extension — rarely unwanted.'
            },
            '11': {
                name: '11th',
                description: 'The 11th (same as the 4th, up an octave) adds a modal, open quality. On dominant and minor chords it sounds natural; on major chords it clashes with the third.',
                context: 'Use ♯11 on major chords (Lydian sound) and natural 11 on minor and dominant chords. The 11th is what gives sus chords their identity.'
            },
            '13': {
                name: '13th',
                description: 'The 13th (same as the 6th, up an octave) adds warmth and complexity. It\'s the highest commonly used extension.',
                context: 'Dominant 13ths are a jazz staple. Minor 13ths are rarer and more exotic. The 13th often replaces the 5th in practical voicings.'
            },
            'b9': {
                name: '♭9 (Flat Nine)',
                description: 'A minor ninth above the root. Dark, tense, and dramatic. This is an alteration, not a natural extension.',
                context: 'Almost exclusively used on dominant chords, especially V7 resolving to minor. The ♭9 is a signature sound of minor ii-V-i progressions.'
            },
            '#9': {
                name: '♯9 (Sharp Nine)',
                description: 'An augmented ninth — the "Hendrix note." Enharmonically the same as a minor third, creating a clash between major and minor.',
                context: 'The dominant 7♯9 is the "Purple Haze" chord. Blues, rock, funk — anywhere you want grit and attitude.'
            },
            '#11': {
                name: '♯11 (Sharp Eleven)',
                description: 'A tritone above the root. Bright, floating, otherworldly — the Lydian sound.',
                context: 'Used on major 7th and dominant chords for a modern, open quality. Kenny Barron, Pat Metheny — the ♯11 is sophisticated jazz at its best.'
            },
            'b13': {
                name: '♭13 (Flat Thirteen)',
                description: 'Same as an augmented fifth in a higher octave. Adds a dark, altered quality to dominant chords.',
                context: 'Part of the "altered dominant" sound. Common in V7 chords resolving to minor. Creates beautiful tension when paired with ♭9.'
            }
        },
        
        // === DEFAULT WELCOME CONTENT ===
        welcome: {
            title: 'Chord Construction Guide',
            sections: [
                {
                    heading: 'Triads: The Foundation',
                    content: 'Every chord begins with three notes stacked in thirds. A <strong>major triad</strong> (R – 3 – 5) sounds bright and resolved. Flatten the third and you get a <strong>minor triad</strong> (R – ♭3 – 5) — darker, more introspective. These two sounds are the foundation of all Western harmony.'
                },
                {
                    heading: '7th Chords: Adding Color',
                    content: 'Add a fourth note and chords become richer. <strong>Major 7th</strong> (R – 3 – 5 – 7) is lush and dreamy. <strong>Minor 7th</strong> (R – ♭3 – 5 – ♭7) is warm and versatile. <strong>Dominant 7th</strong> (R – 3 – 5 – ♭7) creates tension that drives the music forward. These three types form the basis of jazz harmony.'
                },
                {
                    heading: 'Extensions & Voicings',
                    content: 'Beyond the 7th, you can stack more thirds: <strong>9th, 11th, 13th</strong>. Each extension adds complexity and color. Meanwhile, <strong>voicing types</strong> (Drop 2, Shell, Rootless) determine how those notes are arranged on the fretboard — same harmony, different textures. Use the sidebar filters to explore.'
                }
            ]
        }
    };
    
    /**
     * Render the educational info panel based on current filterState.
     * Content crossfades — the container never changes size.
     */
    function renderInfoPanel() {
        var $panel = $('#sbn-edu-content');
        if (!$panel.length) return;
        
        var html = '';
        
        // Determine what to show based on active state
        var qualityKey = null;
        var voicingKey = filterState.voicing;
        var inversionKey = filterState.inversion;
        var extensionKey = filterState.extension;
        
        // Get quality from chord search or browse filter
        if (filterState.chordSearch) {
            qualityKey = filterState.chordSearch.quality;
        } else if (filterState.quality) {
            qualityKey = filterState.quality;
        }
        
        // Build content sections
        var sections = [];
        
        if (qualityKey || voicingKey || inversionKey || extensionKey) {
            // === CONTEXTUAL MODE: show info for active filters ===
            
            // Quality section
            if (qualityKey && eduContent.qualities[qualityKey]) {
                var q = eduContent.qualities[qualityKey];
                sections.push({
                    type: 'quality',
                    html: '<div class="sbn-edu-section sbn-edu-quality">' +
                        '<div class="sbn-edu-section-header">' +
                            '<span class="sbn-edu-badge">Quality</span>' +
                            '<h4>' + escapeHtml(q.name) + '</h4>' +
                            '<span class="sbn-edu-intervals">' + q.intervals + '</span>' +
                        '</div>' +
                        '<p>' + q.description + '</p>' +
                        '<p class="sbn-edu-usage"><strong>Where you\'ll hear it:</strong> ' + q.usage + '</p>' +
                    '</div>'
                });
            }
            
            // Voicing section
            if (voicingKey && eduContent.voicings[voicingKey]) {
                var v = eduContent.voicings[voicingKey];
                sections.push({
                    type: 'voicing',
                    html: '<div class="sbn-edu-section sbn-edu-voicing">' +
                        '<div class="sbn-edu-section-header">' +
                            '<span class="sbn-edu-badge">Voicing</span>' +
                            '<h4>' + escapeHtml(v.name) + '</h4>' +
                        '</div>' +
                        '<p>' + v.description + '</p>' +
                        '<p class="sbn-edu-tip"><strong>💡 </strong>' + v.tip + '</p>' +
                    '</div>'
                });
            }
            
            // Inversion section
            if (inversionKey && eduContent.inversions[inversionKey]) {
                var inv = eduContent.inversions[inversionKey];
                sections.push({
                    type: 'inversion',
                    html: '<div class="sbn-edu-section sbn-edu-inversion">' +
                        '<div class="sbn-edu-section-header">' +
                            '<span class="sbn-edu-badge">Inversion</span>' +
                            '<h4>' + escapeHtml(inv.name) + '</h4>' +
                        '</div>' +
                        '<p>' + inv.description + '</p>' +
                        '<p class="sbn-edu-context">' + inv.context + '</p>' +
                    '</div>'
                });
            }
            
            // Extension section
            if (extensionKey && eduContent.extensions[extensionKey]) {
                var ext = eduContent.extensions[extensionKey];
                sections.push({
                    type: 'extension',
                    html: '<div class="sbn-edu-section sbn-edu-extension">' +
                        '<div class="sbn-edu-section-header">' +
                            '<span class="sbn-edu-badge">Extension</span>' +
                            '<h4>' + escapeHtml(ext.name) + '</h4>' +
                        '</div>' +
                        '<p>' + ext.description + '</p>' +
                        '<p class="sbn-edu-context">' + ext.context + '</p>' +
                    '</div>'
                });
            }
            
            // Compose sections side by side or stacked
            if (sections.length > 0) {
                html = '<div class="sbn-edu-grid sbn-edu-cols-' + sections.length + '">';
                sections.forEach(function(s) { html += s.html; });
                html += '</div>';
            }
        }
        
        // If no contextual content, show welcome
        if (!html) {
            var w = eduContent.welcome;
            html = '<div class="sbn-edu-welcome">';
            html += '<h3>' + escapeHtml(w.title) + '</h3>';
            html += '<div class="sbn-edu-welcome-grid">';
            w.sections.forEach(function(s) {
                html += '<div class="sbn-edu-welcome-section">';
                html += '<h4>' + escapeHtml(s.heading) + '</h4>';
                html += '<p>' + s.content + '</p>'; // Contains safe HTML
                html += '</div>';
            });
            html += '</div>';
            html += '</div>';
        }
        
        // Crossfade: only update if content actually changed
        var currentHtml = $panel.html();
        if (html !== currentHtml) {
            $panel.addClass('sbn-edu-fading');
            setTimeout(function() {
                $panel.html(html);
                $panel.removeClass('sbn-edu-fading');
            }, 200);
        }
    }
    
    // =============================================
    // SEARCH INPUT
    // =============================================
    
    function initSearchInput() {
        var $input = $('#sbn-unified-search');
        var $clearBtn = $('#sbn-search-clear');
        if (!$input.length) return;
        
        $input.on('input', function() {
            var query = $(this).val().trim();
            if (searchTimeout) clearTimeout(searchTimeout);
            
            if (query) {
                $clearBtn.show();
            } else {
                $clearBtn.hide();
                handleSearchClear();
                return;
            }
            
            searchTimeout = setTimeout(function() {
                if (query.length >= 2) handleSearchInput(query);
            }, 400);
        });
        
        $clearBtn.on('click', function() {
            $input.val('');
            $clearBtn.hide();
            handleSearchClear();
        });
        
        $input.on('keypress', function(e) {
            if (e.which === 13) {
                e.preventDefault();
                var query = $(this).val().trim();
                if (query) handleSearchInput(query);
            }
        });
        
        // Clear All (in count bar)
        $(document).on('click', '#sbn-count-clear-all', function(e) {
            e.stopPropagation();
            clearAllFilters();
        });
    }
    
    function handleSearchInput(query) {
        var chordPattern = /^[A-G][#b]?(m|maj|min|major|minor|aug|dim|sus|add|\u00b0|\u00f8|\u0394|M|\+)?([0-9#b()]*(?:\/[A-G][#b]?)?)?$/i;
        
        if (chordPattern.test(query)) {
            var rootMatch = query.match(/^([A-G][#b]?)/i);
            var root = rootMatch ? rootMatch[1] : query;
            var qualityPart = query.substring(root.length);
            
            filterState.chordSearch = { root: root, quality: qualityPart || 'maj', raw: query };
            filterState.textQuery = null;
            filterState.quality = null;
        } else {
            filterState.textQuery = query;
            // Don't clear chordSearch — text filters compose on top of chord results
        }
        
        renderFromState();
    }
    
    function handleSearchClear() {
        filterState.chordSearch = null;
        filterState.textQuery = null;
        $('#sbn-unified-search').val('');
        renderFromState();
    }
    
    function clearAllFilters() {
        resetState();
        $('#sbn-unified-search').val('');
        $('#sbn-search-clear').hide();
        syncDropdownsToState();
        renderFromState();
    }
    
    function syncDropdownsToState() {
        $('#sbn-filter-voicing').val(filterState.voicing || '');
        $('#sbn-filter-quality').val(filterState.quality || '');
    }
    
    // =============================================
    // FILTER SIDEBAR
    // =============================================
    
    function initFilterDropdowns() {
        // Sidebar option clicks (all filter types)
        $(document).on('click', '.sbn-sidebar-option', function() {
            handleSidebarFilter($(this).data('filter'), $(this).data('value'));
        });
        
        // Clear all filters button
        $('#sbn-clear-filters').on('click', function() {
            filterState.voicing = null;
            filterState.quality = null;
            filterState.inversion = null;
            filterState.extension = null;
            filterState.popularity = null;
            filterState.difficulty = null;
            renderFromState();
        });
    }
    
    /**
     * Handle sidebar filter button click.
     * Quality in chord mode: re-triggers AJAX with same root + new quality.
     */
    function handleSidebarFilter(filterType, value) {
        switch(filterType) {
            case 'quality':
                if (filterState.chordSearch) {
                    // In chord mode: switch quality → new AJAX search with same root
                    var root = filterState.chordSearch.root;
                    var newRaw = root + value;
                    filterState.chordSearch = { root: root, quality: value, raw: newRaw };
                    // Update search input to reflect the new chord
                    $('#sbn-unified-search').val(newRaw);
                    $('#sbn-search-clear').show();
                } else {
                    // In browse mode: toggle quality filter
                    filterState.quality = (filterState.quality === value) ? null : value;
                }
                break;
            case 'voicing':
                filterState.voicing = (filterState.voicing === value) ? null : value;
                break;
            case 'popularity':
                filterState.popularity = (filterState.popularity === value) ? null : value;
                break;
            case 'difficulty':
                filterState.difficulty = (filterState.difficulty === value) ? null : value;
                break;
            case 'inversion':
                filterState.inversion = (filterState.inversion === value) ? null : value;
                break;
            case 'extension':
                filterState.extension = (filterState.extension === value) ? null : value;
                break;
        }
        
        renderFromState();
    }
    
    /**
     * Sync sidebar option active states to match filterState
     */
    function syncSidebarToState() {
        $('.sbn-sidebar-option').each(function() {
            var $btn = $(this);
            var filterType = $btn.data('filter');
            var value = $btn.data('value');
            var isActive = false;
            
            switch(filterType) {
                case 'quality':
                    if (filterState.chordSearch) {
                        // In chord mode, highlight the current chord quality
                        isActive = (filterState.chordSearch.quality === value);
                    } else {
                        isActive = (filterState.quality === value);
                    }
                    break;
                case 'voicing':
                    isActive = (filterState.voicing === value);
                    break;
                case 'popularity':
                    isActive = (filterState.popularity === value);
                    break;
                case 'difficulty':
                    isActive = (filterState.difficulty === value);
                    break;
                case 'inversion':
                    isActive = (filterState.inversion === value);
                    break;
                case 'extension':
                    isActive = (filterState.extension === value);
                    break;
            }
            
            $btn.toggleClass('sbn-option-active', isActive);
        });
    }
    
    // syncDropdownsToState is now syncSidebarToState (called in renderFromState)
    function syncDropdownsToState() {
        syncSidebarToState();
    }
    
    // =============================================
    // CLICKABLE METADATA
    // =============================================
    
    function initPhase2Controls() {

        // Navigate to chord detail page when clicking anywhere on a card
        // (play button and clickable metadata spans stop propagation themselves)
        $(document).on('click', '.sbn-chord-card', function(e) {
            navigateToChordDetail($(this));
        });
        $(document).on('click', '.sbn-clickable-metadata', function(e) {
            e.stopPropagation();
            handleMetadataClick($(this).data('search-type'), $(this).data('search-value'));
        });

        // Sort by popularity button
        $(document).on('click', '.sbn-sort-btn', function(e) {
            e.stopPropagation();
            sortGridByPopularity();
        });

        // Play button — arpeggiate the chord via shared SbnAudio engine
        $(document).on('click', '.sbn-play-btn', function(e) {
            e.stopPropagation();
            playCardChord($(this).closest('.sbn-chord-card'));
        });
    }

    /**
     * Arpeggiate a chord card via SbnAudio.
     * Reads fret data from the card's .sbn-chord-fretboard[data-diagram] attribute.
     */
    function playCardChord($card) {
        if (!$card.length) return;

        var init = window.SbnAudio ? SbnAudio.init() : Promise.reject('SbnAudio not loaded');

        init.then(function(ready) {
            if (!ready) return;

            // Get diagram JSON from fretboard element
            var $fb = $card.find('.sbn-chord-fretboard[data-diagram]').first();
            if (!$fb.length) return;

            var diagramRaw = $fb.attr('data-diagram');
            if (!diagramRaw) return;

            var diagram;
            try { diagram = JSON.parse(diagramRaw); } catch(e) { return; }

            if (!diagram || !diagram.positions) return;

            // Reconstruct frets string from diagram positions
            // diagram.positions: array of { string, fret } (1-indexed string, MusicXML style)
            // String 1 = high e (index 5), string 6 = low E (index 0)
            var fretArr = ['x','x','x','x','x','x'];

            // Apply muted strings (use PHP convention: string 1 = low E = index 0)
            if (diagram.muted) {
                diagram.muted.forEach(function(s) {
                    fretArr[s - 1] = 'x';
                });
            }
            // Apply open strings
            if (diagram.open) {
                diagram.open.forEach(function(s) {
                    fretArr[s - 1] = '0';
                });
            }
            // PHP calculator string convention (see class-chord-shape-calculator.php lines 9-15):
            //   string 1 = Low E  → openStrings index 0
            //   string 2 = A      → openStrings index 1
            //   string 3 = D      → openStrings index 2
            //   string 4 = G      → openStrings index 3
            //   string 5 = B      → openStrings index 4
            //   string 6 = High E → openStrings index 5
            // Formula: arrayIndex = pos.string - 1  (NOT 6 - pos.string)
            //
            // Frets in diagram_data are ABSOLUTE (already transposed by calculator).
            // startFret is only for diagram rendering, not for pitch calculation.

            // Apply muted strings
            diagram.positions.forEach(function(pos) {
                var stringIdx = pos.string - 1;
                fretArr[stringIdx] = String(pos.fret);
            });
            // Apply barres (fill any still-x slots within barre range)
            if (diagram.barres) {
                diagram.barres.forEach(function(barre) {
                    for (var s = barre.fromString; s <= barre.toString; s++) {
                        var idx = s - 1;
                        if (fretArr[idx] === 'x') {
                            fretArr[idx] = String(barre.fret);
                        }
                    }
                });
            }

            var frets = fretArr.join('');
            var timings = SbnAudio.playArpeggio(frets);

            // Visual feedback — briefly highlight the play button
            var $btn = $card.find('.sbn-play-btn');
            $btn.addClass('is-playing');
            setTimeout(function() { $btn.removeClass('is-playing'); }, 600);

            // Animate each dot in sync with the arpeggio.
            // fretArr[i] uses PHP convention: index 0 = string 1 (low E).
            // DOM uses data-string="N" with the same convention.
            // We animate only fretted (non-x) strings in the same order as playArpeggio.
            var stringOrder = [];
            fretArr.forEach(function(f, idx) {
                if (f !== 'x' && f !== 'X') stringOrder.push(idx + 1); // 1-indexed string number
            });
            var $fb = $card.find('.sbn-chord-fretboard').first();
            stringOrder.forEach(function(stringNum, i) {
                var delay = timings[i] ? timings[i].delay : i * 120;
                setTimeout(function() {
                    // Find the dot(s) in this string's cells and ping them
                    var $dots = $fb.find(
                        '.sbn-string-space[data-string="' + stringNum + '"] .sbn-finger-position,' +
                        '.sbn-barre[data-string="' + stringNum + '"]'
                    );
                    $dots.addClass('sbn-dot-ping');
                    setTimeout(function() { $dots.removeClass('sbn-dot-ping'); }, 500);
                }, delay);
            });
        });
    }
    
    /**
     * Metadata clicks ADD to filterState (v7 blew everything away — fixed)
     */
    function handleMetadataClick(searchType, searchValue) {
        switch(searchType) {
            case 'quality':
                if (filterState.chordSearch) {
                    // In chord mode: re-trigger AJAX with same root + clicked quality
                    var root = filterState.chordSearch.root;
                    var newRaw = root + searchValue;
                    filterState.chordSearch = { root: root, quality: searchValue, raw: newRaw };
                    $('#sbn-unified-search').val(newRaw);
                    $('#sbn-search-clear').show();
                } else {
                    // In browse mode: toggle quality filter
                    filterState.quality = (filterState.quality === searchValue) ? null : searchValue;
                }
                break;
            case 'extension':
                // Dedicated extension filter (not textQuery anymore)
                filterState.extension = (filterState.extension === searchValue) ? null : searchValue;
                break;
            case 'inversion':
                filterState.inversion = (filterState.inversion === searchValue) ? null : searchValue;
                break;
            case 'voicing':
                filterState.voicing = (filterState.voicing === searchValue) ? null : searchValue;
                break;
        }
        renderFromState();
    }
    
    // Sidebar is permanent, no show/hide needed
    function showFiltersPanel() { }
    
    // =============================================
    // EXAMPLE BUTTONS
    // =============================================
    
    function initExamples() {
        $('.sbn-example-btn').on('click', function() {
            var query = $(this).data('search');
            $('#sbn-unified-search').val(query);
            $('#sbn-search-clear').show();
            handleSearchInput(query);
        });
    }
    
    // =============================================
    // CHORD CARD RENDERING
    // =============================================
    
    function makeInitialChordsClickable() {
        $('.sbn-chord-card').each(function() {
            var $card = $(this);
            var $name = $card.find('.sbn-card-chord-name');
            var chord = { quality: $card.data('quality') || '', extensions: $card.data('extensions') || '', inversion: ($card.data('inversion') || 'root').toString() };

            var updatedHtml = makeChordNameClickable($name.html(), chord);
            
            // For archetype cards (browse mode, no root note), append slash interval for inversions
            var inversion = ($card.data('inversion') || 'root').toString();
            var quality = ($card.data('quality') || '').toString();
            if (inversion !== 'root' && quality) {
                var bassHtml = getBassIntervalHtml(quality, inversion);
                if (bassHtml) {
                    // Insert the bass HTML inside the .sbn-chord-symbol span
                    // Mark as archetype so CSS flips quality/bass emphasis
                    var $temp = $('<div>').html(updatedHtml);
                    var $symbol = $temp.find('.sbn-chord-symbol');
                    if ($symbol.length) {
                        $symbol.append(bassHtml);
                        updatedHtml = $temp.html();
                    }
                }
                // Clear the old text inversion label since it's now in the chord name
                $card.find('.sbn-card-inversion').empty();
            }
            
            $name.html(updatedHtml);
        });
    }
    
    /**
     * Render a chord card from AJAX result data.
     * Uses the shared SbnChordCard component for base card structure,
     * then adds chord-library-specific features (clickable metadata, search data-attrs).
     */
    function renderChordCard(chord) {
        // Map AJAX result fields to shared component format
        var cardData = {
            name: chord.name || '',
            nameHtml: chord.name_html ? makeChordNameClickable(chord.name_html, chord) : null,
            quality: chord.quality || '',
            voicingCategory: chord.voicing_category || '',
            rootString: chord.root_string || '',
            inversion: chord.inversion || 'root',
            extensions: chord.extensions || '',
            diagramData: chord.diagram_data || '',
            startFret: chord.start_fret || 1,
            intervalLabels: chord.interval_labels || '',
            notes: chord.notes || '',
            diagramId: chord.id || '',
            rootNote: chord.root_note || '',
            popularity: chord.popularity || 0,
            difficulty: chord.difficulty || 0
        };
        
        // Use the shared component for the base card HTML
        var html = SbnChordCard.renderCard(cardData, { variant: 'grid' });
        var $card = $(html);
        
        // Add chord-library-specific data attributes
        $card.attr('data-slug', chord.slug || '');
        $card.attr('data-search', (chord.quality || '') + ' ' + (SbnChordCard.VOICING_LABELS[chord.voicing_category] || ''));
        $card.attr('data-bass-note', chord.bass_note || '');
        $card.attr('data-bass-interval', chord.bass_interval || '');
        $card.attr('data-fingering', chord.fingering || '');
        

        
        return $card;
    }
    
    function makeChordNameClickable(chordNameHtml, chord) {
        var $temp = $('<div>').html(chordNameHtml);
        var $ext = $temp.find('.sbn-chord-ext').first();
        if ($ext.length && chord.quality) {
            $ext.addClass('sbn-clickable-metadata').attr('data-search-type', 'quality').attr('data-search-value', chord.quality);
        }
        var $extra = $temp.find('.sbn-chord-ext-extra');
        if ($extra.length && chord.extensions) {
            // Use the raw DB extensions value, not the rendered display text
            // (display converts b→♭ and #→♯, but data-extensions stores raw like 'b13')
            $extra.addClass('sbn-clickable-metadata').attr('data-search-type', 'extension').attr('data-search-value', chord.extensions);
        }
        // Bass note in slash chords (rooted: e.g. Dm7/C) — filter by inversion
        var $bass = $temp.find('.sbn-chord-bass');
        if ($bass.length && chord.inversion && chord.inversion !== 'root') {
            $bass.addClass('sbn-clickable-metadata').attr('data-search-type', 'inversion').attr('data-search-value', chord.inversion);
        }
        return '<span class="sbn-chord-symbol">' + $temp.html() + '</span>';
    }
    
    // =============================================
    // DIAGRAM RENDERING (preserved from v7.2.0)
    // =============================================
    
    function renderAllDiagrams() {
        // Delegate to shared chord card component
        SbnChordCard.hydrateAll(document);
    }
    
    /**
     * Render a single fretboard using the shared component.
     * Kept as a wrapper for backward compatibility with existing callers.
     */
    function renderEnhancedFretboard($container) {
        var el = $container[0] || $container;
        var data = {
            diagram: el.getAttribute('data-diagram') || $(el).data('diagram'),
            startFret: el.getAttribute('data-start-fret') || '1',
            intervals: (el.getAttribute('data-intervals') || '').toString(),
            notes: (el.getAttribute('data-notes') || '').toString(),
            displayMode: $(el).data('display-mode') || el.getAttribute('data-display-mode') || 'fingering'
        };
        el.innerHTML = SbnChordCard.renderFretboard(data);
        requestAnimationFrame(function() {
            SbnChordCard.hydrateFretboard(el, data);
        });
    }
    
    // =============================================
    // CHORD DETAIL NAVIGATION
    // =============================================

    /**
     * Navigate to the chord detail page for a given card.
     *
     * Rooted diagram (e.g. Fmaj7, data-root-note set)  → /chord/?id=42&back=...
     * Generic shape  (e.g. maj7,  no root note)         → /chord/?quality=maj7&back=...
     *
     * The ?back= param lets the detail page render a "← Chord Library" link.
     */
    /**
     * Navigate to the chord detail page.
     *
     * The exact voicing the user clicked (diagram_data, start_fret, interval_labels)
     * is stored in sessionStorage so the detail page can render it without any
     * DB lookup or transposition logic.
     *
     * URL cases:
     *   Rooted chord  (e.g. Dm7)  → ?root=D&quality=m7&back=...
     *   Persisted card (static)   → ?id=42&back=...
     *   Generic shape (no root)   → ?quality=m7&back=...
     */
    function navigateToChordDetail($card) {
        var baseUrl = (sbnChordSearch && sbnChordSearch.chordDetailUrl)
            ? sbnChordSearch.chordDetailUrl
            : '';
        if (!baseUrl) return;

        var diagramId  = $card.data('diagram-id') || '';
        var quality    = $card.data('quality')    || '';
        var rootNote   = $card.data('root-note')  || '';
        var extensions = $card.data('extensions') || '';
        var voicing    = $card.data('voicing')    || '';
        var backUrl    = encodeURIComponent(window.location.href);

        // Grab the exact rendered voicing from the fretboard element inside the card
        var $fb         = $card.find('.sbn-chord-fretboard').first();
        var diagramData = $fb.attr('data-diagram')    || '';
        var startFret   = $fb.attr('data-start-fret') || '1';
        var intervals   = $fb.attr('data-intervals')  || '';
        var notes       = $fb.attr('data-notes')      || '';


        // Store the voicing in sessionStorage so the detail page can use it directly
        if (diagramData) {
            try {
                sessionStorage.setItem('sbn_chord_voicing', JSON.stringify({
                    diagramData : diagramData,
                    startFret   : startFret,
                    intervals   : intervals,
                    notes       : notes,
                    quality     : quality,
                    extensions  : extensions,
                    inversion   : $card.data('inversion') || 'root',
                    voicing     : voicing,
                    rootNote    : rootNote,
                    diagramId   : diagramId
                }));
            } catch(e) {
                // sessionStorage not available — detail page falls back to DB archetype
            }
        }

        // For archetype cards (no root note), default to C so the detail page
        // always takes the rooted path and sessionStorage voicing is included.
        if (!rootNote && quality) rootNote = 'C';

        var url;
        if (rootNote && quality) {
            url = baseUrl + '?root='    + encodeURIComponent(rootNote) +
                            '&quality=' + encodeURIComponent(quality) +
                            '&back='    + backUrl;
            if (extensions) url += '&extensions=' + encodeURIComponent(extensions);
            if (voicing)    url += '&voicing='    + encodeURIComponent(voicing);
            var inv = $card.data('inversion') || 'root';
            if (inv && inv !== 'root') url += '&inversion=' + encodeURIComponent(inv);
        } else if (diagramId) {
            url = baseUrl + '?id=' + encodeURIComponent(diagramId) + '&back=' + backUrl;
        } else {
            return;
        }

        window.location.href = url;
    }

    // Note: openMoreInfoModal / closeModal / buildAnalysisSection /
    // loadModalData / renderSongsSection / renderRelatedSection
    // removed in v8.1.0. Modal replaced by chord-detail-page.php.

    // =============================================
    // UTILITIES
    // =============================================
    
    function escapeHtml(text) {
        if (text === null || text === undefined) return '';
        var map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
        return String(text).replace(/[&<>"']/g, function(m) { return map[m]; });
    }
    
})(jQuery);
