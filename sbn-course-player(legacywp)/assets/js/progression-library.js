/**
 * Progression Library — progression-library.js
 *
 * Handles:
 *  - Full-text search (name, numerals, description, genres, tags)
 *  - Category / tag / tonality sidebar filters
 *  - Sort (popularity / name / category)
 *  - Active filter pills and "clear" controls
 *
 * Also handles the detail page (no interaction needed beyond builder init,
 * which is done inline in the PHP shortcode output).
 *
 * @package SBN_Course_Player
 * @since 7.9.0
 */

(function ($) {
    'use strict';

    /* ══════════════════════════════════════════════════════════════════
       LIBRARY PAGE LOGIC
    ══════════════════════════════════════════════════════════════════ */

    if ( ! $('#sbn-prog-lib').length ) return;

    /* ── Embedded progression data ── */
    var progData = {};
    try { progData = JSON.parse( $('#sbn-prog-data').text() ); } catch(e) {}

    /* ── Filter state ── */
    var state = {
        query:    '',
        category: '',
        tag:      '',
        tonality: '',
        sort:     'popularity',
    };

    var searchTimer = null;

    /* ══════════════════════════════════════════════════════════════════
       RENDER
    ══════════════════════════════════════════════════════════════════ */

    function render() {
        var $rows   = $('#sbn-prog-list .sbn-prog-row');
        var visible = 0;
        var query   = normalise( state.query );

        $rows.each(function () {
            var $r = $(this);
            var match = true;

            /* 1. Category */
            if ( state.category && $r.data('category') !== state.category ) {
                match = false;
            }

            /* 2. Tag — exact match against comma-separated tag list */
            if ( match && state.tag ) {
                var rowTags = ($r.data('tags') || '').split(',').map(function(t){ return t.trim(); });
                if ( rowTags.indexOf( state.tag ) === -1 ) match = false;
            }

            /* 3. Tonality */
            if ( match && state.tonality ) {
                var ton = $r.data('tonality') || 'both';
                if ( ton !== 'both' && ton !== state.tonality ) match = false;
            }

            /* 4. Text search across name, numerals, desc, genres, tags */
            if ( match && query ) {
                var haystack = [
                    $r.data('name')     || '',
                    $r.data('numerals') || '',
                    $r.data('desc')     || '',
                    $r.data('genres')   || '',
                    $r.data('tags')     || '',
                ].join(' ');
                if ( ! matchesQuery( query, haystack ) ) match = false;
            }

            $r.toggle( match );
            if ( match ) visible++;
        });

        /* Sort visible rows */
        var $grid   = $('#sbn-prog-list');
        var $sorted = $rows.filter(':visible').sort( comparator );
        $sorted.each(function () { $grid.append(this); });

        /* Rank numbers always show their permanent position from PHP */
        $rows.each(function () {
            $(this).find('.sbn-prog-row-rank').text( $(this).data('rank') );
        });

        /* Count */
        $('#sbn-prog-count-text').text( visible + ' progression' + (visible !== 1 ? 's' : '') );

        /* Empty state */
        $('#sbn-prog-lib-empty').toggle( visible === 0 );

        /* Show/hide clear button */
        var hasFilters = state.category || state.tag || state.tonality || state.query;
        $('#sbn-prog-clear-filters').toggle( !! hasFilters );
    }

    /* ── Comparator for sort ── */
    function comparator(a, b) {
        if ( state.sort === 'popularity' ) {
            var diff = parseInt( $(b).data('song-count') ) - parseInt( $(a).data('song-count') );
            if ( diff !== 0 ) return diff;
        }
        if ( state.sort === 'category' ) {
            var ca = $(a).data('category'), cb = $(b).data('category');
            if ( ca !== cb ) return ca < cb ? -1 : 1;
        }
        var na = $(a).data('name') || '', nb = $(b).data('name') || '';
        return na < nb ? -1 : na > nb ? 1 : 0;
    }

    /* ── Text matching: supports roman numeral degree search (spaces→no spaces) ── */
    function normalise(s) {
        return String(s || '').toLowerCase().trim();
    }

    function matchesQuery(query, haystack) {
        /* Allow space-separated words: all must appear (AND logic) */
        var words = query.split(/\s+/).filter(Boolean);
        for (var i = 0; i < words.length; i++) {
            if ( haystack.indexOf( words[i] ) === -1 ) return false;
        }
        return true;
    }

    /* ══════════════════════════════════════════════════════════════════
       SIDEBAR FILTER BUTTONS
    ══════════════════════════════════════════════════════════════════ */

    $(document).on('click', '.sbn-prog-sidebar-option[data-filter]', function () {
        var $btn    = $(this);
        var filter  = $btn.data('filter');   // 'category' | 'tag' | 'tonality'
        var value   = $btn.data('value');

        /* Toggle: clicking active option clears it */
        var isActive = $btn.hasClass('sbn-filter-active');

        /* Deactivate all in this filter group */
        $('[data-filter="' + filter + '"]').removeClass('sbn-filter-active');

        if ( ! isActive ) {
            $btn.addClass('sbn-filter-active');
            state[ filter ] = value;
        } else {
            state[ filter ] = '';
        }

        render();
    });

    /* ── Sort buttons ── */
    $(document).on('click', '.sbn-prog-sidebar-option[data-sort]', function () {
        var $btn = $(this);
        $('#sbn-prog-sort-options .sbn-prog-sidebar-option').removeClass('sbn-sort-active');
        $btn.addClass('sbn-sort-active');
        state.sort = $btn.data('sort');
        render();
    });

    /* ══════════════════════════════════════════════════════════════════
       SEARCH INPUT
    ══════════════════════════════════════════════════════════════════ */

    $('#sbn-prog-search').on('input', function () {
        var q = $(this).val();
        $('#sbn-prog-search-clear').toggle( q.length > 0 );
        clearTimeout( searchTimer );
        searchTimer = setTimeout(function () {
            state.query = q;
            render();
        }, 180);
    });

    $('#sbn-prog-search-clear').on('click', function () {
        $('#sbn-prog-search').val('').trigger('focus');
        $(this).hide();
        state.query = '';
        render();
    });

    /* Example query buttons */
    $(document).on('click', '.sbn-prog-example-btn', function () {
        var q = $(this).data('query');
        $('#sbn-prog-search').val(q);
        $('#sbn-prog-search-clear').show();
        state.query = q;
        render();
    });

    /* ══════════════════════════════════════════════════════════════════
       CLEAR FILTERS
    ══════════════════════════════════════════════════════════════════ */

    function clearAllFilters() {
        state.category = '';
        state.tag      = '';
        state.tonality = '';
        state.query    = '';
        $('#sbn-prog-search').val('');
        $('#sbn-prog-search-clear').hide();
        $('.sbn-prog-sidebar-option[data-filter]').removeClass('sbn-filter-active');
        render();
    }

    $('#sbn-prog-clear-filters').on('click', clearAllFilters);
    $('#sbn-prog-sidebar-clear').on('click', clearAllFilters);

    /* ══════════════════════════════════════════════════════════════════
       HASH DEEP-LINK (e.g. /chord-progressions/#prog-42)
    ══════════════════════════════════════════════════════════════════ */

    var hash = window.location.hash;
    if ( hash && hash.indexOf('#prog-') === 0 ) {
        var hashId = parseInt( hash.replace('#prog-', '') );
        if ( hashId ) {
            var cfg = window.sbnProgLib || {};
            var detailUrl = (cfg.detailPageUrl || '/progression/') + '?id=' + hashId;
            /* Scroll to the row and highlight it briefly */
            setTimeout(function () {
                var $target = $('#prog-' + hashId);
                if ( $target.length ) {
                    $target[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
                    $target.css('transition', 'background 0s').css('background', '#fff3e0');
                    setTimeout(function () {
                        $target.css('transition', 'background 0.8s').css('background', '');
                    }, 100);
                }
            }, 300);
        }
    }

    /* ══════════════════════════════════════════════════════════════════
       INIT — add tonality to progData rows from PHP (not in data-attrs)
    ══════════════════════════════════════════════════════════════════ */

    /* Tonality isn't in the data-* attributes for brevity; we read it from
       the embedded JSON (progData) which is always available. */

    /* Initial render not needed — PHP outputs already sorted by popularity */

}(jQuery));
