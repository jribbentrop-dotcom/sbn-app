/**
 * Song Library — song-library.js  v6.0.0
 *
 * Architecture:
 *  - Songs are loaded via AJAX (sbn_search_songs), NOT pre-loaded in a JS object
 *  - Data shape matches actual rzga_sbn_leadsheets table + rzga_sbn_voicing_usage JOIN:
 *      id, title, composer, song_key, tempo, time_signature, rhythm,
 *      measure_count, chords_used (array from voicing_usage)
 *  - genre, difficulty, popularity are NOT in DB yet — handled gracefully (omitted/defaulted)
 *
 * New in v6:
 *  - Popularity indicator in card front footer (shows when DB column exists)
 *  - Streamlined card back: rhythm + descriptive tempo label only
 *  - Two CTA buttons: "Open Leadsheet" + "More Information"
 *  - Info modal: full song details (description, key, chords, tempo)
 *  - Tempo label system: bpm → human-readable feel string
 *
 * Localized via wp_localize_script('sbn-song-library', 'sbnSongLibrary', {
 *   ajaxUrl, nonce, chordLibraryUrl
 * })
 */

(function ($) {
    'use strict';

    /* ============================================================
       CONFIG — from wp_localize_script
       ============================================================ */

    var cfg = window.sbnSongLibrary || {};
    var AJAX_URL        = cfg.ajaxUrl         || '';
    var NONCE           = cfg.nonce           || '';
    var CHORD_LIB_URL   = cfg.chordLibraryUrl || '';
    var RHYTHM_LIB_URL  = cfg.rhythmLibraryUrl || 'https://soulbossanova.com/rhythm-library/';
    var SONG_PAGE_URL   = cfg.songPageUrl     || '';

    /* ============================================================
       CONSTANTS
       ============================================================ */

    var POPULARITY_TIERS = {
        iconic: { label: '★ Iconic Song', cls: 'sbn-pop-iconic'  },
        core:   { label: 'Famous Song',   cls: 'sbn-pop-core'    },
        common: { label: 'Popular Song',  cls: 'sbn-pop-common'  },
        rare:   { label: 'Hidden Gem',    cls: 'sbn-pop-rare'    }
    };

    /**
     * Convert BPM to a descriptive feel label.
     */
    function tempoLabel(bpm) {
        bpm = parseInt(bpm) || 0;
        if (bpm === 0)  return { label: '—',               sub: '' };
        if (bpm < 60)   return { label: 'Very Slow Ballad', sub: bpm + ' bpm' };
        if (bpm < 76)   return { label: 'Ballad',           sub: bpm + ' bpm' };
        if (bpm < 96)   return { label: 'Slow Bossa',       sub: bpm + ' bpm' };
        if (bpm < 112)  return { label: 'Medium Bossa',     sub: bpm + ' bpm' };
        if (bpm < 126)  return { label: 'Medium Swing',     sub: bpm + ' bpm' };
        if (bpm < 144)  return { label: 'Medium-Up',        sub: bpm + ' bpm' };
        if (bpm < 176)  return { label: 'Up-Tempo',         sub: bpm + ' bpm' };
        return               { label: 'Fast / Bright',      sub: bpm + ' bpm' };
    }

    /**
     * Popularity indicator — text label only, no dots.
     */
    function popularityIndicatorHTML(tier) {
        var t = POPULARITY_TIERS[tier ? tier.toLowerCase() : ''];
        if (!t) return '';
        return '<span class="sbn-pop-indicator ' + t.cls + '">'
             +   '<span class="sbn-pop-label">' + t.label + '</span>'
             + '</span>';
    }

    /* ============================================================
       CARD RENDERING — uses actual DB fields
       song: { id, title, composer, song_key, tempo, time_signature,
               rhythm, measure_count, chords_used[], genre?, difficulty?, popularity? }
       ============================================================ */

    function genreClass(genre) {
        if (!genre) return '';
        // DB stores slugs ('bossa-nova') or display names ('bossa nova') — handle both
        var key = genre.toLowerCase().replace(/\s+/g, '-');
        var map = {
            'bossa-nova':  'sbn-genre--bossa-nova',
            'jazz':        'sbn-genre--jazz',
            'classical':   'sbn-genre--classical',
            'samba':       'sbn-genre--samba',
            'choro':       'sbn-genre--choro',
            'latin-jazz':  'sbn-genre--latin-jazz'
        };
        return map[key] || '';
    }

    function difficultyStars(d) {
        if (!d) return '';
        // Map text levels to star count (integer) or text level to dots
        var levelMap = { 'beginner': 1, 'easy': 1, 'intermediate': 2, 'advanced': 3, 'expert': 4 };
        var n = parseInt(d);
        if (isNaN(n)) n = levelMap[d.toLowerCase()] || 0;
        if (!n) return '';
        var html = '<span class="sbn-difficulty">';
        for (var i = 1; i <= 5; i++) {
            html += '<span class="sbn-star ' + (i <= n ? 'sbn-star--filled' : 'sbn-star--empty') + '">★</span>';
        }
        return html + '</span>';
    }

    function buildCardHTML(song) {
        var gCls    = genreClass(song.genre || '');
        var tempo   = tempoLabel(song.tempo);
        var popHTML = song.popularity ? popularityIndicatorHTML(song.popularity) : '';
        var stars   = difficultyStars(song.difficulty);

        // Genre badge label — prettify slug ('bossa-nova' → 'Bossa Nova')
        var badgeLabel = prettifySlug(song.genre) || prettifySlug(song.rhythm) || 'Song';

        /* FRONT */
        var front = '<div class="sbn-card-front">'
            + '<div class="sbn-card-top">'
            +   '<span class="sbn-genre-badge ' + gCls + '">' + escHtml(badgeLabel) + '</span>'
            +   (stars || '')
            + '</div>'
            + '<div class="sbn-card-titles">'
            +   '<p class="sbn-song-title">'    + escHtml(song.title || '—')      + '</p>'
            +   '<p class="sbn-song-composer">' + escHtml(song.composer || '')    + '</p>'
            + '</div>'
            + '<div class="sbn-card-front-footer">'
            +   popHTML
            + '</div>'
            + '</div>';

        /* BACK — rhythm + tempo, each with a plain label above the pill */
        var rhythmDisplay = song.rhythm_name || prettifySlug(song.rhythm) || '';
        var back = '<div class="sbn-card-back">'
            + '<p class="sbn-back-song-name">' + escHtml(song.title || '') + '</p>'
            + '<div class="sbn-back-pills">'
            +   (rhythmDisplay
                    ? '<div class="sbn-back-pill-group">'
                    +   '<span class="sbn-back-pill-heading">Rhythm</span>'
                    +   (RHYTHM_LIB_URL && song.rhythm
                            ? '<a class="sbn-back-pill sbn-back-pill--link" href="' + escAttr(RHYTHM_LIB_URL + '?rhythm=' + encodeURIComponent(song.rhythm)) + '" target="_blank" rel="noopener">' + escHtml(rhythmDisplay) + '<svg width="9" height="9" viewBox="0 0 10 10" fill="none" style="opacity:.6;flex-shrink:0"><path d="M1 9L9 1M9 1H3M9 1v6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg></a>'
                            : '<span class="sbn-back-pill">' + escHtml(rhythmDisplay) + '</span>')
                    + '</div>'
                    : '')
            +   (tempo.label !== '—'
                    ? '<div class="sbn-back-pill-group">'
                    +   '<span class="sbn-back-pill-heading">Tempo</span>'
                    +   '<span class="sbn-back-pill">' + escHtml(tempo.label) + (tempo.sub ? '<span class="sbn-back-pill-bpm">' + escHtml(tempo.sub) + '</span>' : '') + '</span>'
                    + '</div>'
                    : '')
            + '</div>'
            + '<div class="sbn-back-actions">'
            + '<a class="sbn-back-cta sbn-open-leadsheet" href="' + escAttr(songPageUrl(song.id)) + '">'
            + '<svg width="12" height="12" viewBox="0 0 16 16" fill="none">'
            + '<rect x="2" y="1" width="10" height="13" rx="1.5" stroke="currentColor" stroke-width="1.8"/>'
            + '<path d="M5 5h6M5 8h4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>'
            + '</svg>Open Leadsheet</a>'
            + '<button type="button" class="sbn-back-cta sbn-back-cta-secondary sbn-open-song-info" data-id="' + parseInt(song.id) + '">'
            + '<svg width="12" height="12" viewBox="0 0 16 16" fill="none">'
            + '<circle cx="8" cy="8" r="6.5" stroke="currentColor" stroke-width="1.8"/>'
            + '<path d="M8 7v4M8 5.5v.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>'
            + '</svg>More Info</button>'
            + '</div>'
            + '</div>';

        return '<div class="sbn-song-card ' + gCls + '" data-id="' + parseInt(song.id) + '">'
             + '<div class="sbn-card-inner">' + front + back + '</div>'
             + '</div>';
    }

    /** Small key pill for front footer when no popularity tier is set yet */
    function keyPill(key) {
        if (!key) return '';
        return '<span style="font-size:10px;font-weight:600;color:#a0aec0;text-transform:uppercase;letter-spacing:.04em;">'
             + 'Key of ' + escHtml(key) + '</span>';
    }

    /** Convert a slug like "bossa-nova" → "Bossa Nova" */
    function prettifySlug(slug) {
        if (!slug) return '';
        return slug.replace(/-/g, ' ').replace(/\b\w/g, function (c) { return c.toUpperCase(); });
    }

    /* ============================================================
       INFO MODAL — full song details
       ============================================================ */

    function buildInfoModalHTML(song) {
        var tempo  = tempoLabel(song.tempo);
        var popInd = song.popularity ? popularityIndicatorHTML(song.popularity) : '';

        /* Tags — apple-style pills, no icons, genre uses gradient badge */
        var tags = '';
        if (song.genre)    tags += '<span class="sbn-song-modal-tag sbn-song-modal-tag--genre ' + genreClass(song.genre) + '">' + escHtml(prettifySlug(song.genre)) + '</span>';
        if (song.song_key) tags += '<span class="sbn-song-modal-tag sbn-song-modal-tag--neutral">Key of ' + escHtml(song.song_key) + '</span>';
        if (song.rhythm)   tags += '<span class="sbn-song-modal-tag sbn-song-modal-tag--neutral">' + escHtml(song.rhythm_name || prettifySlug(song.rhythm)) + '</span>';
        if (song.tempo)    tags += '<span class="sbn-song-modal-tag sbn-song-modal-tag--neutral">' + escHtml(tempo.label) + (tempo.sub ? ' <span class="sbn-modal-tag-bpm">' + escHtml(tempo.sub) + '</span>' : '') + '</span>';
        if (popInd)        tags += '<span class="sbn-song-modal-tag sbn-song-modal-tag--pop">' + popInd + '</span>';

        /* Chords from voicing usage */
        var chordsHTML = '';
        var chords = song.chords_used || [];
        if (chords.length) {
            var pills = chords.map(function (c) {
                return '<button type="button" class="sbn-song-modal-chord" data-chord="' + escAttr(c) + '">' + escHtml(c) + '</button>';
            }).join('');
            chordsHTML = '<div class="sbn-song-modal-section">'
                       + '<p class="sbn-song-modal-section-title">Chords in this song</p>'
                       + '<div class="sbn-song-modal-chords">' + pills + '</div>'
                       + '</div>';
        }

        /* Description (optional — future DB field) */
        var descHTML = '';
        if (song.description) {
            descHTML = '<div class="sbn-song-modal-section">'
                     + '<p class="sbn-song-modal-section-title">About this song</p>'
                     + '<p class="sbn-song-modal-desc">' + escHtml(song.description) + '</p>'
                     + '</div>';
        }

        /* Extra info row: time_signature + measure_count */
        var metaHTML = '';
        var metaParts = [];
        if (song.time_signature) metaParts.push(escHtml(song.time_signature) + ' time');
        if (song.measure_count)  metaParts.push(escHtml(song.measure_count) + ' bars');
        if (metaParts.length) {
            metaHTML = '<div class="sbn-song-modal-section">'
                     + '<p class="sbn-song-modal-section-title">Structure</p>'
                     + '<p style="font-size:14px;color:#4a5568;margin:0;">' + metaParts.join(' · ') + '</p>'
                     + '</div>';
        }

        /* Open Leadsheet CTA */
        var ctaHTML = '<div class="sbn-song-modal-section">'
                    + '<a class="sbn-song-modal-cta sbn-open-leadsheet" href="' + escAttr(songPageUrl(song.id)) + '">'
                    + '<svg width="15" height="15" viewBox="0 0 16 16" fill="none">'
                    + '<rect x="2" y="1" width="10" height="13" rx="1.5" stroke="currentColor" stroke-width="1.8"/>'
                    + '<path d="M5 5h6M5 8h4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>'
                    + '</svg> Open Leadsheet</a></div>';

        return '<div class="sbn-song-modal-header">'
             + '<button type="button" class="sbn-song-modal-close" aria-label="Close">'
             + '<svg width="14" height="14" viewBox="0 0 14 14" fill="none">'
             + '<path d="M1 1l12 12M13 1L1 13" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>'
             + '</svg></button>'
             + '<h2 class="sbn-song-modal-title">' + escHtml(song.title) + '</h2>'
             + '<p class="sbn-song-modal-composer">' + escHtml(song.composer || '') + '</p>'
             + '<div class="sbn-song-modal-tags">' + tags + '</div>'
             + '</div>'
             + '<div class="sbn-song-modal-body">'
             + descHTML + chordsHTML + metaHTML + ctaHTML
             + '</div>';
    }

    /* ============================================================
       UTILITIES
       ============================================================ */

    function escHtml(str) {
        return String(str || '')
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
    function escAttr(str) { return String(str || '').replace(/"/g, '&quot;'); }

    /* ============================================================
       STATE
       ============================================================ */

    // All loaded songs (from AJAX, cached after first load)
    var allSongs = null;
    var allMeta  = null;   // { available_genres, available_keys, available_rhythms, ... }

    var filterState = {
        textQuery: '',
        genre: null, key: null, rhythm: null, difficulty: null, tempo: null, popularity: null
    };

    /* ============================================================
       DATA LOADING — AJAX
       ============================================================ */

    function loadSongs(callback) {
        if (allSongs !== null) { callback(allSongs); return; }

        $('#sbn-loading').show();
        $('#sbn-songs-grid').hide();

        $.post(AJAX_URL, {
            action: 'sbn_search_songs',
            nonce:  NONCE
            // No filters = return all songs
        }, function (response) {
            $('#sbn-loading').hide();
            // Temporary debug — remove after confirming data shape
            if (response && response.success) {
                // Actual response shape: { success: true, data: { songs: [...], meta: {...} } }
                var payload = response.data || {};
                allMeta  = payload.meta  || null;
                var raw  = payload.songs || [];

                if (!Array.isArray(raw)) {
                    raw = (raw && typeof raw === 'object') ? Object.values(raw) : [];
                }

                // Ensure chords_used is always a JS array on each song
                allSongs = raw.map(function (s) {
                    if (typeof s.chords_used === 'string') {
                        s.chords_used = s.chords_used ? s.chords_used.split(',') : [];
                    } else if (!Array.isArray(s.chords_used)) {
                        s.chords_used = [];
                    }
                    return s;
                });
                callback(allSongs);
            } else {
                allSongs = [];
                showError('Could not load songs. Please refresh the page.');
            }
        }).fail(function () {
            $('#sbn-loading').hide();
            allSongs = [];
            showError('Could not load songs. Please refresh the page.');
        });
    }

    function showError(msg) {
        $('#sbn-no-results').show().find('p').text(msg);
    }

    /* ============================================================
       RENDER
       ============================================================ */

    function renderGrid(songs) {
        var $grid = $('#sbn-songs-grid');
        $grid.empty();

        if (!songs || !songs.length) {
            $grid.hide();
            $('#sbn-no-results').show();
            return;
        }

        $('#sbn-no-results').hide();
        $grid.show().html(songs.map(buildCardHTML).join(''));
    }

    function applyFilters() {
        if (!allSongs) return;
        var q = filterState.textQuery.toLowerCase().trim();

        var filtered = allSongs.filter(function (s) {
            if (q) {
                var hay = [s.title, s.composer, s.song_key, s.rhythm,
                           (s.chords_used || []).join(' ')].join(' ').toLowerCase();
                if (hay.indexOf(q) === -1) return false;
            }
            if (filterState.genre     && (s.genre    || '').toLowerCase() !== filterState.genre.toLowerCase())    return false;
            if (filterState.key       && (s.song_key || '').toLowerCase() !== filterState.key.toLowerCase())    return false;
            if (filterState.rhythm    && (s.rhythm   || '').toLowerCase() !== filterState.rhythm.toLowerCase()) return false;
            if (filterState.difficulty && (s.difficulty || '').toLowerCase() !== filterState.difficulty.toLowerCase()) return false;
            if (filterState.popularity && (s.popularity || '') !== filterState.popularity) return false;
            if (filterState.tempo) {
                var bpm = parseInt(s.tempo) || 0;
                if (filterState.tempo === 'slow'   && bpm >= 100) return false;
                if (filterState.tempo === 'medium' && (bpm < 100 || bpm > 140)) return false;
                if (filterState.tempo === 'fast'   && bpm <= 140) return false;
            }
            return true;
        });

        renderGrid(filtered);
        updateSearchStatus(filtered.length, allSongs.length);
    }

    function updateSearchStatus(shown, total) {
        var $s = $('#sbn-search-status');
        var hasFilter = filterState.textQuery || filterState.genre || filterState.key || filterState.rhythm
                     || filterState.difficulty || filterState.tempo || filterState.popularity;

        if (!hasFilter) { $s.removeClass('active').text(''); return; }

        var msg = 'Showing <strong>' + shown + '</strong> of <strong>' + total + '</strong> songs';
        if (filterState.textQuery) msg += ' matching <strong>"' + escHtml(filterState.textQuery) + '"</strong>';
        $s.addClass('active').html(msg);
    }

    /* ============================================================
       BUILD SIDEBAR FILTERS from loaded data
       ============================================================ */

    function buildSidebarFilters(songs) {
        // Prefer pre-computed meta from the AJAX response — avoids re-scanning
        var meta = allMeta || {};

        // Keys
        var keys = (meta.available_keys && meta.available_keys.length)
            ? meta.available_keys
            : uniqueField(songs, 'song_key');
        renderFilterGroup('#sbn-filter-key-options', keys.sort(), 'key', function (v) { return v; });

        // Rhythms — use rhythm_name from songs for display label
        var rhythms = (meta.available_rhythms && meta.available_rhythms.length)
            ? meta.available_rhythms
            : uniqueField(songs, 'rhythm');
        // Build a slug→display label map from song data
        var rhythmLabels = {};
        songs.forEach(function (s) { if (s.rhythm && s.rhythm_name) rhythmLabels[s.rhythm] = s.rhythm_name; });
        renderFilterGroup('#sbn-filter-rhythm-options', rhythms, 'rhythm', function (v) {
            return rhythmLabels[v] || prettifySlug(v);
        });

        // Genre filter
        var genres = (meta.available_genres && meta.available_genres.length)
            ? meta.available_genres
            : uniqueField(songs, 'genre');
        if (genres.length) {
            renderFilterGroup('#sbn-filter-genre-options', genres, 'genre', function (v) {
                return prettifySlug(v);
            });
        } else {
            $('#sbn-filter-genre-options').closest('.sbn-sidebar-section').hide();
        }

        // Difficulty filter — DB stores strings like 'intermediate', not integers
        var diffs = (meta.available_difficulties && meta.available_difficulties.length)
            ? meta.available_difficulties
            : uniqueField(songs, 'difficulty');
        if (diffs.length) {
            var diffHTML = diffs.map(function (d) {
                var label = d.charAt(0).toUpperCase() + d.slice(1); // 'intermediate' → 'Intermediate'
                return '<button type="button" class="sbn-sidebar-option" data-filter-type="difficulty" data-value="' + escAttr(d) + '">' + escHtml(label) + '</button>';
            }).join('');
            $('#sbn-filter-difficulty-options').html(diffHTML);
        } else {
            $('#sbn-filter-difficulty-options').closest('.sbn-sidebar-section').hide();
        }

        // Popularity filter (future DB column — hide until data exists)
        var pops = uniqueField(songs, 'popularity');
        var popOrder = ['iconic', 'core', 'common', 'rare'];
        if (pops.length) {
            pops.sort(function (a, b) { return popOrder.indexOf(a) - popOrder.indexOf(b); });
            var popHTML = pops.map(function (p) {
                var t = POPULARITY_TIERS[p];
                return '<button type="button" class="sbn-sidebar-option" data-filter-type="popularity" data-value="' + p + '">'
                     + (t ? t.label : p) + '</button>';
            }).join('');
            $('#sbn-filter-popularity-options').html(popHTML);
        } else {
            $('#sbn-filter-popularity-options').closest('.sbn-sidebar-section').hide();
        }
    }

    /** Collect unique non-empty values for a field from the songs array */
    function uniqueField(songs, field) {
        var seen = {}, out = [];
        songs.forEach(function (s) {
            var v = s[field];
            if (v && !seen[v]) { seen[v] = 1; out.push(v); }
        });
        return out;
    }

    function renderFilterGroup(selector, items, filterType, labelFn) {
        if (!items.length) { $(selector).closest('.sbn-sidebar-section').hide(); return; }
        var html = items.map(function (v) {
            return '<button type="button" class="sbn-sidebar-option"'
                 + ' data-filter-type="' + filterType + '"'
                 + ' data-value="' + escAttr(v) + '">'
                 + escHtml(labelFn(v)) + '</button>';
        }).join('');
        $(selector).html(html);
    }

    /* ============================================================
       MODALS
       ============================================================ */

    var songCache = {};   // id → song object, for modal access

    function cacheSong(song) { if (song && song.id) songCache[parseInt(song.id)] = song; }

    function openInfoModal(songId) {
        var song = songCache[parseInt(songId)];
        if (!song) return;

        var $overlay = $('#sbn-song-modal-overlay');
        var $modal   = $('#sbn-song-modal');

        $modal.html(buildInfoModalHTML(song));
        $overlay.show();
        $('body').addClass('sbn-modal-open');
        setTimeout(function () { $overlay.addClass('sbn-modal-visible'); }, 10);
    }

    function closeInfoModal() {
        var $overlay = $('#sbn-song-modal-overlay');
        $overlay.removeClass('sbn-modal-visible');
        setTimeout(function () { $overlay.hide(); $('body').removeClass('sbn-modal-open'); }, 280);
    }

    /**
     * Build the URL for a song's standalone page.
     * Falls back to ?id= on the current page if SONG_PAGE_URL is not configured.
     */
    function songPageUrl(songId, highlightChord) {
        var base = SONG_PAGE_URL || (window.location.origin + '/song/');
        var url  = base + '?id=' + parseInt(songId);
        if (highlightChord) url += '&chord=' + encodeURIComponent(highlightChord);
        // Pass current page as back URL so the ← button returns here
        url += '&back=' + encodeURIComponent(window.location.href);
        return url;
    }

    /**
     * Navigate to the song page (replaces old openLeadsheetModal).
     * Kept as a function so any remaining call sites still work.
     */
    function openLeadsheetModal(songId) {
        window.location.href = songPageUrl(songId);
    }

    function closeLeadsheetModal() {
        // No-op: modal no longer used for leadsheets.
        // Kept to avoid errors from any residual call sites.
    }

    /* ============================================================
       INIT
       ============================================================ */

    $(document).ready(function () {

        if (!$('#sbn-songs-grid').length) return;

        /* Load songs via AJAX */
        loadSongs(function (songs) {
            // Build cache for modal access
            songs.forEach(cacheSong);
            renderGrid(songs);
            buildSidebarFilters(songs);
        });

        /* Search input */
        var searchTimer;
        $('#sbn-song-search').on('input', function () {
            var val = $(this).val();
            $('#sbn-search-clear').toggle(val.length > 0);
            clearTimeout(searchTimer);
            searchTimer = setTimeout(function () {
                filterState.textQuery = val;
                applyFilters();
            }, 250);
        });

        $('#sbn-search-clear').on('click', function () {
            $('#sbn-song-search').val('').trigger('input');
        });

        $(document).on('click', '.sbn-example-btn', function () {
            $('#sbn-song-search').val($(this).data('example')).trigger('input');
        });

        /* Sidebar filter buttons */
        $(document).on('click', '.sbn-sidebar-option', function () {
            var $btn  = $(this);
            var type  = $btn.data('filter-type');
            var value = $btn.data('value');

            if ($btn.hasClass('sbn-tempo-option')) { type = 'tempo'; value = $btn.data('tempo'); }
            if (!type) return;

            if (filterState[type] === value) {
                filterState[type] = null;
                $btn.removeClass('active');
            } else {
                $btn.closest('.sbn-sidebar-section, .sbn-tempo-filter').find('.sbn-sidebar-option').removeClass('active');
                filterState[type] = value;
                $btn.addClass('active');
            }
            applyFilters();
        });

        /* Clear all filters */
        $('#sbn-clear-filters').on('click', function () {
            filterState = { textQuery: '', genre: null, key: null, rhythm: null, difficulty: null, tempo: null, popularity: null };
            $('#sbn-song-search').val('');
            $('#sbn-search-clear').hide();
            $('.sbn-sidebar-option').removeClass('active');
            applyFilters();
        });

        /* Open Leadsheet (card back button or modal CTA) */
        /* Rhythm pill link — let it navigate, just stop card click side-effects */
        $(document).on('click', '.sbn-back-pill--link', function (e) {
            e.stopPropagation();
        });

        // .sbn-open-leadsheet is now an <a> tag — let it navigate naturally.
        // We only intercept to close the info modal (so it doesn't linger during navigation).
        $(document).on('click', '.sbn-open-leadsheet', function (e) {
            e.stopPropagation();
            closeInfoModal();
            // Navigation handled by the href on the <a> element.
        });

        /* Open Info Modal */
        $(document).on('click', '.sbn-open-song-info', function (e) {
            e.stopPropagation();
            openInfoModal($(this).data('id'));
        });

        /* Close Info Modal */
        $(document).on('click', '.sbn-song-modal-close', closeInfoModal);
        $(document).on('click', '#sbn-song-modal-overlay', function (e) {
            if ($(e.target).is('#sbn-song-modal-overlay')) closeInfoModal();
        });

        /* ESC key — info modal only (leadsheet modal no longer used) */
        $(document).on('keydown', function (e) {
            if (e.key === 'Escape') { closeInfoModal(); }
        });

        /* Chord pill in info modal → chord library */
        $(document).on('click', '.sbn-song-modal-chord', function () {
            var chord = $(this).data('chord');
            if (chord && CHORD_LIB_URL) {
                window.location.href = CHORD_LIB_URL + '?chord=' + encodeURIComponent(chord);
            }
        });

        /* URL param: ?song=42 — navigate directly to the song page */
        var urlParams = new URLSearchParams(window.location.search);
        var autoSong  = urlParams.get('song');
        if (autoSong) {
            window.location.replace(songPageUrl(parseInt(autoSong)));
        }

    }); // end ready

}(jQuery));
