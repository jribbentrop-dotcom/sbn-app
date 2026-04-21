<?php
/**
 * Template Name: Rhythm Library
 *
 * Displays all rhythm patterns from the database in a styled grid.
 * Card "Preview" button plays the linked MP3 (if any).
 * Modal shows full grid + percussion / mp3 volume sliders + band mode toggle.
 *
 * @package SoulBossaNova
 */

get_header(); ?>

<div id="primary" class="content-area">
    <main id="main" class="site-main">

        <?php
        global $wpdb;
        $table_name = $wpdb->prefix . 'sbn_rhythm_patterns';
        $patterns   = $wpdb->get_results(
            "SELECT * FROM $table_name ORDER BY category, sort_order, name"
        );

        $patterns_by_category = array();
        foreach ($patterns as $pattern) {
            $category = !empty($pattern->category) ? $pattern->category : 'general';
            $patterns_by_category[$category][] = $pattern;
        }
        ?>

        <div class="sbn-rhythm-library">
            <div class="sbn-rhythm-library-header">
                <h1>Rhythm &amp; Groove Dictionary</h1>
                <p class="sbn-rhythm-library-intro">Visualizing the heart of Bossa Nova syncopation.</p>
            </div>

            <?php if (empty($patterns)): ?>
                <div class="sbn-empty-state">
                    <div class="sbn-empty-icon">🎵</div>
                    <h2>No Patterns Yet</h2>
                    <p>Start creating rhythm patterns in the admin panel.</p>
                </div>
            <?php else: ?>
                <?php foreach ($patterns_by_category as $category => $category_patterns): ?>
                    <h2 class="sbn-category-header"><?php echo esc_html(ucfirst($category)); ?></h2>
                    <div class="sbn-patterns-category">
                        <?php foreach ($category_patterns as $pattern): ?>
                            <?php echo sbn_render_pattern_card($pattern); ?>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

    </main>
</div>

<!-- ── Rhythm Detail Modal ──────────────────────────────────────────────── -->
<div id="sbn-rhythm-modal" class="sbn-modal-overlay" role="dialog"
     aria-modal="true" aria-labelledby="sbn-modal-title" hidden>
    <div class="sbn-modal-container">
        <button class="sbn-modal-close" aria-label="Close">&times;</button>
        <div id="sbn-modal-body"><!-- filled by JS --></div>
    </div>
</div>

<!-- ── Page JS ───────────────────────────────────────────────────────────── -->
<script>
(function () {
    'use strict';

    /* ── Config — read lazily so footer scripts have time to load ────── */
    function getConfig(key) {
        return (typeof sbnRhythmLibrary !== 'undefined') ? (sbnRhythmLibrary[key] || '') : '';
    }
    function getAjaxUrl()    { return getConfig('ajaxUrl'); }
    function getNonce()      { return getConfig('nonce'); }
    function getSamplesUrl() { return getConfig('samplesUrl'); }
    function getMp3Base()    { return getConfig('mp3BaseUrl'); }

    /* ── Card preview state ──────────────────────────────────────────── */
    var activeCardStop = null;
    var activeCardBtn  = null;

    /* ── Percussion init (lazy, no retry lock when url missing) ──────── */
    var percInitialized = false;
    function maybeInitPerc() {
        if (percInitialized || !window.SbnPercussion) return;
        var url = getSamplesUrl();
        if (!url) return;
        SbnPercussion.init(url);
        percInitialized = true;
    }

    /* ── Modal sequencer state ───────────────────────────────────────── */
    var seqData    = null;
    var modalAudio = null;
    /* mix: 0 = samples only, 1 = mp3 only */
    var modalMix   = 0.5;
    /* Legacy: kept for safety cleanup in stopModalPlayback */
    var seqTimer   = null;

    /* ════════════════════════════════════════════════════════════════════
       CARD — "Preview" button plays percussion samples
    ════════════════════════════════════════════════════════════════════ */
    document.querySelectorAll('.sbn-pattern-play-btn').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            var card = btn.closest('.sbn-pattern-card');
            var data = JSON.parse(card.dataset.patternModal);
            toggleCardPreview(btn, data);
        });
    });

    function stopActiveCard() {
        if (activeCardStop) { activeCardStop(); activeCardStop = null; }
        if (activeCardBtn) {
            activeCardBtn.classList.remove('is-playing');
            activeCardBtn.textContent = 'Preview';
            activeCardBtn = null;
        }
    }

    function toggleCardPreview(btn, data) {
        var wasMe = (activeCardBtn === btn);
        stopActiveCard();
        if (wasMe) return;

        maybeInitPerc();
        if (!window.SbnPercussion) return;
        SbnPercussion.resume();
        SbnPercussion.setVolume(0.8);

        if (!SbnPercussion.ready) {
            var poll = setInterval(function () {
                if (!SbnPercussion.ready) return;
                clearInterval(poll);
                if (activeCardBtn !== btn) return;
                runPercPreview(data, btn);
            }, 100);
            btn.textContent = 'Loading…';
            btn.classList.add('is-playing');
            activeCardBtn  = btn;
            activeCardStop = function () { clearInterval(poll); };
            return;
        }
        runPercPreview(data, btn);
    }

    function runPercPreview(data, btn) {
        var timeParts   = data.time_signature.split('/');
        var bpm         = data.default_bpm || 120;
        var beatsPerBar = parseInt(timeParts[0]) || 4;
        var sub         = data.grid_type === 'eighth' ? 2 : data.grid_type === 'triplet' ? 3 : 4;
        var cellsPerBar = beatsPerBar * sub;
        var secsPerCell = (60 / bpm) / sub;
        var fingers     = (data.rhythm_pattern || '').slice(0, cellsPerBar);
        var thumb       = (data.thumb_pattern  || '').slice(0, cellsPerBar);

        btn.classList.add('is-playing');
        btn.textContent = 'Stop';
        activeCardBtn = btn;

        var step = 0;
        var interval = setInterval(function () {
            if (!activeCardBtn || activeCardBtn !== btn) { clearInterval(interval); return; }
            var now = SbnPercussion._audioCtx ? SbnPercussion._audioCtx.currentTime : 0;
            var fh  = fingers[step] || '.';
            var th  = thumb[step]   || '.';
            if (data.perc_top !== 'none' && data.perc_top) {
                if (fh === 'X' || fh === 'x') SbnPercussion.playHit(data.perc_top, fh === 'X', now, 1.0);
                else                          SbnPercussion.playHit(data.perc_top, false, now, 0.12);
            }
            if ((th === 'X' || th === 'x' || th === 'o' || th === 'O') && data.perc_bass !== 'none' && data.perc_bass) {
                SbnPercussion.playHit(data.perc_bass, false, now, 0.85);
            }
            step++;
            if (step >= cellsPerBar) {
                clearInterval(interval);
                btn.classList.remove('is-playing');
                btn.textContent = 'Preview';
                activeCardBtn  = null;
                activeCardStop = null;
            }
        }, secsPerCell * 1000);

        activeCardStop = function () { clearInterval(interval); };
    }

    /* ════════════════════════════════════════════════════════════════════
       CARD — click body → open modal
    ════════════════════════════════════════════════════════════════════ */
    var overlay   = document.getElementById('sbn-rhythm-modal');
    var modalBody = document.getElementById('sbn-modal-body');

    document.querySelectorAll('[data-open-modal]').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            openFromCard(btn.closest('.sbn-pattern-card'));
        });
    });

    document.querySelectorAll('.sbn-pattern-card').forEach(function (card) {
        card.addEventListener('click', function (e) {
            if (e.target.closest('.sbn-pattern-play-btn')) return;
            openFromCard(card);
        });
        card.style.cursor = 'pointer';
    });

    function openFromCard(card) {
        var data = JSON.parse(card.dataset.patternModal);
        openModal(data);
    }

    /* ════════════════════════════════════════════════════════════════════
       MODAL — open / close
    ════════════════════════════════════════════════════════════════════ */
    document.querySelector('.sbn-modal-close').addEventListener('click', closeModal);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) closeModal(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeModal(); });

    function openModal(data) {
        stopModalPlayback();
        stopActiveCard();
        seqData  = data;
        modalMix = 0.5;
        modalBody.innerHTML = buildModalHTML(data);
        overlay.removeAttribute('hidden');
        document.body.classList.add('sbn-modal-open');
        wireModalControls(data);
        maybeInitPerc();
        loadSongs(data.slug);
    }

    function closeModal() {
        stopModalPlayback();
        overlay.setAttribute('hidden', '');
        document.body.classList.remove('sbn-modal-open');
        seqData = null;
    }

    /* ════════════════════════════════════════════════════════════════════
       MODAL — HTML builder
    ════════════════════════════════════════════════════════════════════ */
    function buildModalHTML(data) {
        var hasMp3 = !!(data.mp3_file && getMp3Base());

        var desc = data.description
            ? '<p class="sbn-modal-desc">' + escHtml(data.description) + '</p>'
            : '';

        var mixControl = hasMp3 ? (
            '<div class="sbn-modal-mix-row">' +
              '<span class="sbn-modal-mix-label">Samples</span>' +
              '<input type="range" class="sbn-modal-slider sbn-modal-mix-slider" id="sbn-modal-mix"' +
                     ' min="0" max="1" step="0.05" value="' + modalMix + '">' +
              '<span class="sbn-modal-mix-label">Demo</span>' +
            '</div>'
        ) : '';

        var playRow = (
            '<div class="sbn-modal-play-row">' +
              '<button class="sbn-pattern-play-btn sbn-modal-play-btn" id="sbn-modal-play-btn">Play Pattern</button>' +
              mixControl +
            '</div>'
        );

        return (
            '<div class="sbn-modal-header">' +
              '<div class="sbn-modal-title-group">' +
                '<h2 id="sbn-modal-title">' + escHtml(data.name) + '</h2>' +
              '</div>' +
              '<div class="sbn-modal-badges">' +
                '<span class="sbn-pattern-time">' + escHtml(data.time_signature) + '</span>' +
                '<span class="sbn-pattern-bars">' + escHtml(data.bar_label)      + '</span>' +
                '<span class="sbn-modal-bpm">&#9833;= ' + escHtml(String(data.default_bpm)) + '</span>' +
              '</div>' +
            '</div>' +
            desc +
            '<div class="sbn-modal-section">' +
              '<h4 class="sbn-modal-section-label">Full Pattern</h4>' +
              '<div class="sbn-pattern-preview sbn-modal-grid-preview">' +
                buildFullGrid(data) +
              '</div>' +
              playRow +
            '</div>' +
            '<div class="sbn-modal-section">' +
              '<h4 class="sbn-modal-section-label">Songs using this rhythm</h4>' +
              '<div id="sbn-modal-songs-list" class="sbn-modal-songs-list">' +
                '<span class="sbn-modal-songs-loading">Loading&#8230;</span>' +
              '</div>' +
            '</div>'
        );
    }

    /* ════════════════════════════════════════════════════════════════════
       MODAL — wire controls after render
    ════════════════════════════════════════════════════════════════════ */
    function wireModalControls(data) {
        var playBtn = document.getElementById('sbn-modal-play-btn');
        if (playBtn) {
            playBtn.addEventListener('click', function () {
                if (seq.playing) stopModalPlayback();
                else             startModalPlayback(data);
            });
        }

        var mixSlider = document.getElementById('sbn-modal-mix');
        if (mixSlider) {
            mixSlider.addEventListener('input', function () {
                modalMix = parseFloat(mixSlider.value);
                applyMixVolumes();
            });
        }
    }

    /* modalMix 0->1: percVol goes 1->0, mp3Vol goes 0->1
       mp3 demos are typically louder than samples, so we scale mp3 down */
    var MP3_MAX_VOL = 0.45;
    function applyMixVolumes() {
        var percVol = 1.0 - modalMix;
        var mp3Vol  = modalMix * MP3_MAX_VOL;
        if (window.SbnPercussion) SbnPercussion.setVolume(percVol);
        if (modalAudio) {
            if (modalAudio._gainNode && window.SbnPercussion && SbnPercussion._audioCtx) {
                modalAudio._gainNode.gain.setValueAtTime(mp3Vol, SbnPercussion._audioCtx.currentTime);
            } else {
                modalAudio.volume = mp3Vol;
            }
        }
    }

    /* ════════════════════════════════════════════════════════════════════
       MODAL SEQUENCER — pre-scheduled Web Audio (sample-accurate sync)
       ─────────────────────────────────────────────────────────────────
       Both percussion hits AND the mp3 demo are scheduled on the same
       AudioContext hardware clock via bufferSource.start(exactTime).
       A lightweight rAF loop reads ctx.currentTime to drive the visual
       grid highlight — it never triggers audio, so jitter is harmless.
       Plays once (no loop). Ghost notes are suppressed when mp3 is in
       the mix (modalMix > 0).
    ════════════════════════════════════════════════════════════════════ */
    var mp3BufferCache = {};

    /* How far ahead (seconds) we pre-schedule. */
    var SCHEDULE_AHEAD = 0.15;

    var seq = {
        playing:      false,
        startTime:    0,        /* ctx time when beat 0 began            */
        secsPerCell:  0,
        totalCells:   0,
        loopDuration: 0,        /* secsPerCell * totalCells              */
        fingers:      '',
        thumb:        '',
        percTop:      'none',
        percBass:     'none',
        hasPerc:      false,
        percLoopsScheduled: 0,  /* how many full perc loops scheduled    */
        rafId:        null,
        lookTimer:    null,     /* lookahead scheduler for perc loops    */
        lastStep:     -1
    };

    function startModalPlayback(data) {
        if (seq.playing) return;
        seq.playing = true;

        var playBtn = document.getElementById('sbn-modal-play-btn');
        if (playBtn) { playBtn.classList.add('is-playing'); playBtn.textContent = 'Stop'; }

        var bpm         = data.default_bpm || 120;
        var sub         = data.grid_type === 'eighth' ? 2 : data.grid_type === 'triplet' ? 3 : 4;
        seq.totalCells  = parseInt(data.beats);
        seq.secsPerCell = (60 / bpm) / sub;
        seq.loopDuration = seq.secsPerCell * seq.totalCells;

        seq.fingers  = data.rhythm_pattern || '';
        seq.thumb    = data.thumb_pattern  || '';
        seq.percTop  = data.perc_top  || 'none';
        seq.percBass = data.perc_bass || 'none';
        seq.hasPerc  = seq.percTop !== 'none' || seq.percBass !== 'none';
        seq.percLoopsScheduled = 0;
        seq.lastStep = -1;

        /* Ensure percussion engine is ready */
        if (seq.hasPerc && window.SbnPercussion) {
            SbnPercussion.resume();
            var url = getSamplesUrl();
            if (!SbnPercussion.ready && !SbnPercussion.loading && url) {
                SbnPercussion.init(url);
            }
        }

        var mp3Url = data.mp3_file ? (getMp3Base() + data.mp3_file) : '';
        var ctx    = (window.SbnPercussion && SbnPercussion._audioCtx)
                   ? SbnPercussion._audioCtx : null;

        if (!ctx) {
            applyMixVolumes();
            seq.startTime = performance.now() / 1000;
            schedulePercLoop(null);
            startVisualLoop(null);
            return;
        }

        /* ── Anchor everything to a single future instant on the audio clock ── */
        seq.startTime = ctx.currentTime + SCHEDULE_AHEAD;

        /* ── Pre-schedule the first loop of percussion hits ── */
        schedulePercLoop(ctx);

        /* ── Start lookahead to keep scheduling future perc loops ── */
        startLookahead(ctx);

        /* ── Start visual highlight (rAF-driven, reads ctx.currentTime) ── */
        startVisualLoop(ctx);

        /* ── MP3 demo track (single play — drives total duration) ── */
        if (mp3Url) {
            modalAudio = {
                _gainNode: null, _currentSrc: null,
                pause: function () {
                    if (this._currentSrc) { try { this._currentSrc.stop(); } catch(e){} this._currentSrc = null; }
                    if (this._gainNode)   { this._gainNode.disconnect(); this._gainNode = null; }
                }
            };

            function launchMp3(audioBuffer) {
                if (!seq.playing) return;

                var gainNode = ctx.createGain();
                gainNode.gain.setValueAtTime(modalMix * MP3_MAX_VOL, ctx.currentTime);
                gainNode.connect(ctx.destination);
                modalAudio._gainNode = gainNode;

                /* Schedule mp3 once at the exact same startTime as perc */
                var src = ctx.createBufferSource();
                src.buffer = audioBuffer;
                src.connect(gainNode);
                src.start(seq.startTime);
                modalAudio._currentSrc = src;

                /* When mp3 ends, stop everything (perc + visuals) */
                src.onended = function () {
                    if (seq.playing) stopModalPlayback();
                };

                applyMixVolumes();
            }

            if (mp3BufferCache[mp3Url]) {
                launchMp3(mp3BufferCache[mp3Url]);
            } else {
                if (playBtn) playBtn.textContent = 'Loading…';
                fetch(mp3Url)
                    .then(function (r) { return r.arrayBuffer(); })
                    .then(function (ab) { return ctx.decodeAudioData(ab); })
                    .then(function (buffer) {
                        mp3BufferCache[mp3Url] = buffer;
                        if (playBtn) playBtn.textContent = 'Stop';
                        launchMp3(buffer);
                    })
                    .catch(function (err) {
                        console.warn('[SBN] Could not decode mp3:', err);
                        if (playBtn) playBtn.textContent = 'Stop';
                    });
            }
        } else {
            applyMixVolumes();
        }
    }

    /* ── Pre-schedule one full loop of percussion hits ─────────────── */
    function schedulePercLoop(ctx) {
        if (!seq.hasPerc || !window.SbnPercussion || !SbnPercussion.ready || !ctx) return;

        var loopIdx    = seq.percLoopsScheduled;
        var loopOffset = seq.startTime + (loopIdx * seq.loopDuration);

        /* Suppress ghost notes when mp3 demo is in the mix */
        var suppressGhosts = (modalMix > 0);

        for (var i = 0; i < seq.totalCells; i++) {
            var hitTime = loopOffset + (i * seq.secsPerCell);
            var fh = seq.fingers[i] || '.';
            var th = seq.thumb[i]   || '.';

            if (seq.percTop !== 'none') {
                var isAccent = (fh === 'X');
                var isHit    = (fh === 'X' || fh === 'x' || fh === 'o' || fh === 'O');
                if (isHit) {
                    SbnPercussion.playHit(seq.percTop, isAccent, hitTime,
                                          isAccent ? 1.0 : 0.75);
                } else if (!suppressGhosts) {
                    SbnPercussion.playHit(seq.percTop, false, hitTime, 0.12);
                }
            }
            if ((th === 'X' || th === 'x' || th === 'o' || th === 'O') && seq.percBass !== 'none') {
                SbnPercussion.playHit(seq.percBass, false, hitTime, 0.85);
            }
        }
        seq.percLoopsScheduled = loopIdx + 1;
    }

    /* ── Lookahead: schedule the next percussion loop before current ends ── */
    function startLookahead(ctx) {
        seq.lookTimer = setInterval(function () {
            if (!seq.playing || !ctx) return;
            var scheduledEnd = seq.startTime + (seq.percLoopsScheduled * seq.loopDuration);
            if (ctx.currentTime + SCHEDULE_AHEAD >= scheduledEnd) {
                schedulePercLoop(ctx);
            }
        }, 80);
    }

    /* ── Visual highlight driven by rAF — wraps with percussion loop ── */
    function startVisualLoop(ctx) {
        function tick() {
            if (!seq.playing) return;
            var now = ctx ? ctx.currentTime : (performance.now() / 1000);
            var elapsed = now - seq.startTime;
            if (elapsed < 0) { seq.rafId = requestAnimationFrame(tick); return; }

            /* Wrap step within pattern (perc loops continuously) */
            var posInLoop = elapsed % seq.loopDuration;
            var step      = Math.floor(posInLoop / seq.secsPerCell);
            if (step !== seq.lastStep) {
                highlightModalCell(step);
                seq.lastStep = step;
            }
            seq.rafId = requestAnimationFrame(tick);
        }
        seq.rafId = requestAnimationFrame(tick);
    }

    /* ── Stop everything ───────────────────────────────────────────── */
    function stopModalPlayback() {
        seq.playing = false;
        if (seq.rafId)     { cancelAnimationFrame(seq.rafId); seq.rafId = null; }
        if (seq.lookTimer) { clearInterval(seq.lookTimer);    seq.lookTimer = null; }
        /* Legacy compat */
        if (seqTimer) { clearInterval(seqTimer); seqTimer = null; }
        seq.lastStep = -1;

        /* Kill old percussion: disconnect the master gain node so any
           pre-scheduled buffer sources still in the audio queue play
           into a dead-end. Then create a fresh gain node for the next
           playback session. */
        if (window.SbnPercussion && SbnPercussion.gainNode && SbnPercussion._audioCtx) {
            var ctx = SbnPercussion._audioCtx;
            SbnPercussion.gainNode.disconnect();
            SbnPercussion.gainNode = ctx.createGain();
            SbnPercussion.gainNode.gain.setValueAtTime(SbnPercussion.volume, ctx.currentTime);
            SbnPercussion.gainNode.connect(ctx.destination);
        }

        if (modalAudio) { modalAudio.pause(); modalAudio = null; }
        /* Safety: resume context if suspended */
        if (window.SbnPercussion && SbnPercussion._audioCtx &&
            SbnPercussion._audioCtx.state === 'suspended') {
            SbnPercussion._audioCtx.resume();
        }
        var playBtn = document.getElementById('sbn-modal-play-btn');
        if (playBtn) { playBtn.classList.remove('is-playing'); playBtn.textContent = 'Play Pattern'; }
        document.querySelectorAll('.sbn-modal-grid-preview .sbn-pattern-grid-cell.is-active-step')
            .forEach(function (el) { el.classList.remove('is-active-step'); });
    }

    function highlightModalCell(step) {
        var grid = document.querySelector('.sbn-modal-grid-preview .sbn-pattern-grid');
        if (!grid) return;
        grid.querySelectorAll('.is-active-step').forEach(function (el) { el.classList.remove('is-active-step'); });
        var rows = grid.querySelectorAll('.sbn-pattern-grid-row');
        rows.forEach(function (row, rowIdx) {
            if (rowIdx === 0) return;
            var cells = row.querySelectorAll('.sbn-pattern-grid-cell');
            if (cells[step]) cells[step].classList.add('is-active-step');
        });
    }

    /* ════════════════════════════════════════════════════════════════════
       JS GRID RENDERER
    ════════════════════════════════════════════════════════════════════ */
    function buildFullGrid(data) {
        var timeParts = data.time_signature.split('/');
        var bpm       = parseInt(timeParts[0]) || 4;
        var gridType  = data.grid_type || 'sixteenth';
        var sub       = gridType === 'eighth' ? 2 : gridType === 'triplet' ? 3 : 4;
        var total     = parseInt(data.beats);
        var html = '<div class="sbn-pattern-grid">';
        html += beatLabels(total, bpm, sub, gridType);
        if (data.rhythm_pattern) html += patternRow('Fingers', data.rhythm_pattern, total, false);
        if (data.thumb_pattern)  html += patternRow('Thumb',   data.thumb_pattern,  total, true);
        html += '</div>';
        return html;
    }

    function beatLabels(total, bpm, sub, gridType) {
        var html = '<div class="sbn-pattern-grid-row"><div class="sbn-pattern-grid-label"></div><div class="sbn-pattern-grid-cells">';
        for (var i = 0; i < total; i++) {
            html += '<div class="sbn-pattern-grid-cell is-beat-label">' + escHtml(beatLabel(i, sub, bpm, gridType)) + '</div>';
        }
        return html + '</div></div>';
    }

    function patternRow(label, str, total, isThumb) {
        var html = '<div class="sbn-pattern-grid-row"><div class="sbn-pattern-grid-label">' + escHtml(label) + '</div><div class="sbn-pattern-grid-cells">';
        str.slice(0, total).split('').forEach(function (h) {
            var cls = 'sbn-pattern-grid-cell' + (isThumb ? ' is-thumb' : '');
            var dot = '';
            if (h === 'X' || h === 'x') { cls += ' is-accent'; dot = '&#9679;'; }
            else if (h === 'o' || h === 'O') { cls += ' is-hit'; dot = '&#9679;'; }
            html += '<div class="' + cls + '">' + dot + '</div>';
        });
        return html + '</div></div>';
    }

    function beatLabel(pos, sub, bpm, gridType) {
        var cellsPerBar = bpm * sub;
        var posInBar    = pos % cellsPerBar;
        var beatNum     = Math.floor(posInBar / sub) + 1;
        var subPos      = posInBar % sub;
        if (subPos === 0) return String(beatNum);
        if (gridType === 'triplet') return subPos === 1 ? 'trip' : 'let';
        if (gridType === 'eighth')  return '+';
        return ['e', '+', 'a'][subPos - 1] || '';
    }

    /* ════════════════════════════════════════════════════════════════════
       AJAX — load songs
    ════════════════════════════════════════════════════════════════════ */
    function loadSongs(slug) {
        var list = document.getElementById('sbn-modal-songs-list');
        if (!list) return;

        if (!getAjaxUrl() || !getNonce()) {
            list.innerHTML = '<span class="sbn-modal-songs-empty">Song lookup unavailable.</span>';
            return;
        }

        var fd = new FormData();
        fd.append('action', 'sbn_get_rhythm_songs');
        fd.append('nonce',  getNonce());
        fd.append('slug',   slug);

        fetch(getAjaxUrl(), { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res.success || !res.data || !res.data.length) {
                    list.innerHTML = '<span class="sbn-modal-songs-empty">No songs linked to this rhythm yet.</span>';
                    return;
                }
                list.innerHTML = res.data.map(function (s) {
                    var inner =
                        '<span class="sbn-modal-song-title">' + escHtml(s.title) + '</span>' +
                        (s.composer ? '<span class="sbn-modal-song-composer">' + escHtml(s.composer) + '</span>' : '') +
                        (s.song_key ? '<span class="sbn-modal-song-key">' + escHtml(s.song_key) + '</span>' : '');
                    if (s.url) {
                        return '<a href="' + escAttr(s.url) + '" class="sbn-modal-song-item">' + inner + '</a>';
                    }
                    return '<div class="sbn-modal-song-item">' + inner + '</div>';
                }).join('');
            })
            .catch(function () {
                list.innerHTML = '<span class="sbn-modal-songs-empty">Could not load songs.</span>';
            });
    }

    /* ── Utilities ───────────────────────────────────────────────────── */
    function escHtml(s) {
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
    function escAttr(s) {
        return String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

})();
</script>

<?php get_footer(); ?>

<?php
// ============================================================================
// RENDERING FUNCTIONS
// ============================================================================

/**
 * Convert beats count to human-readable bar label.
 */
function sbn_beats_to_bar_label($pattern) {
    preg_match('/(\d+)\//', $pattern->time_signature, $matches);
    $beats_per_measure = isset($matches[1]) ? (int)$matches[1] : 4;
    $grid_type         = $pattern->grid_type ?? 'sixteenth';

    if ($grid_type === 'eighth')       $sub = 2;
    elseif ($grid_type === 'triplet')  $sub = 3;
    else                               $sub = 4;

    $cells_per_bar = $beats_per_measure * $sub;
    $total_cells   = (int)$pattern->beats;

    if ($cells_per_bar > 0 && $total_cells > 0) {
        $bars = $total_cells / $cells_per_bar;
        if ($bars == 1) return '1 Bar';
        if ($bars == 2) return '2 Bar';
        return round($bars, 1) . ' Bar';
    }
    return $total_cells . ' Cells';
}

/**
 * Render a compact rhythm pattern card.
 *  - Description is truncated to ~100 chars for preview
 *  - "Preview" button fires the MP3 (or perc fallback)
 *  - Full data (perc_top, perc_bass, mp3_file) passed to modal via data attribute
 */
function sbn_render_pattern_card($pattern) {
    $bar_label = sbn_beats_to_bar_label($pattern);

    preg_match('/(\d+)\//', $pattern->time_signature, $matches);
    $beats_per_measure = isset($matches[1]) ? (int)$matches[1] : 4;
    $grid_type         = $pattern->grid_type ?? 'sixteenth';
    $sub               = ($grid_type === 'eighth') ? 2 : (($grid_type === 'triplet') ? 3 : 4);
    $cells_per_bar     = $beats_per_measure * $sub;
    $total_cells       = (int)$pattern->beats;
    $is_multibar       = $total_cells > $cells_per_bar;

    /* Truncate description for card preview */
    $desc_preview = '';
    if (!empty($pattern->description)) {
        $desc_preview = mb_strlen($pattern->description) > 100
            ? mb_substr($pattern->description, 0, 97) . '…'
            : $pattern->description;
    }

    $modal_data = json_encode(array(
        'name'           => $pattern->name,
        'slug'           => $pattern->slug,
        'description'    => $pattern->description,   // full in modal
        'time_signature' => $pattern->time_signature,
        'grid_type'      => $grid_type,
        'bar_label'      => $bar_label,
        'default_bpm'    => $pattern->default_bpm,
        'rhythm_pattern' => $pattern->rhythm_pattern,
        'thumb_pattern'  => $pattern->thumb_pattern,
        'beats'          => $pattern->beats,
        'perc_top'       => $pattern->perc_top  ?? 'none',
        'perc_bass'      => $pattern->perc_bass ?? 'none',
        'mp3_file'       => $pattern->mp3_file  ?? '',
    ));

    ob_start();
    ?>
    <div class="sbn-pattern-card" data-pattern-modal='<?php echo esc_attr($modal_data); ?>'>
        <div class="sbn-pattern-header">
            <h3><?php echo esc_html($pattern->name); ?></h3>
        </div>

        <div class="sbn-pattern-info">
            <div class="sbn-pattern-time"><?php echo esc_html($pattern->time_signature); ?></div>
            <div class="sbn-pattern-bars"><?php echo esc_html($bar_label); ?></div>
        </div>

        <?php if ($desc_preview): ?>
            <p class="sbn-pattern-desc"><?php echo esc_html($desc_preview); ?></p>
        <?php endif; ?>

        <!-- Preview grid: capped at 1 bar -->
        <div class="sbn-pattern-preview<?php echo $is_multibar ? ' is-truncated' : ''; ?>">
            <?php echo sbn_render_pattern_grid($pattern, true); ?>
            <?php if ($is_multibar): ?>
                <div class="sbn-pattern-preview-fade">
                    <span class="sbn-multibar-hint"><?php echo esc_html($bar_label); ?></span>
                </div>
            <?php endif; ?>
        </div>

        <div class="sbn-pattern-card-footer">
            <button class="sbn-pattern-play-btn"
                    data-pattern="<?php echo esc_attr($pattern->slug); ?>"
                    data-bpm="<?php echo esc_attr($pattern->default_bpm); ?>">
                Preview
            </button>
            <button class="sbn-pattern-details-btn" data-open-modal>
                Full Details
            </button>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * Render the rhythm grid (PHP-side, for card preview).
 */
function sbn_render_pattern_grid($pattern, $preview_only = false) {
    $grid_data = sbn_parse_pattern_for_display($pattern);

    if ($preview_only) {
        $cells_per_bar = $grid_data['beats_per_measure'] * $grid_data['subdivision'];
        $grid_data['total_cells'] = min($grid_data['total_cells'], $cells_per_bar);
    }

    ob_start();
    ?>
    <div class="sbn-pattern-grid">
        <?php echo sbn_render_beat_labels($grid_data); ?>
        <?php if (!empty($pattern->rhythm_pattern)): ?>
            <?php echo sbn_render_pattern_row('Fingers', $pattern->rhythm_pattern, $grid_data, false); ?>
        <?php endif; ?>
        <?php if (!empty($pattern->thumb_pattern)): ?>
            <?php echo sbn_render_pattern_row('Thumb', $pattern->thumb_pattern, $grid_data, true); ?>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}

function sbn_render_beat_labels($grid_data) {
    $subdivision       = $grid_data['subdivision'];
    $beats_per_measure = $grid_data['beats_per_measure'];
    $total_cells       = $grid_data['total_cells'];
    $grid_type         = $grid_data['grid_type'] ?? 'sixteenth';

    ob_start();
    ?>
    <div class="sbn-pattern-grid-row">
        <div class="sbn-pattern-grid-label"></div>
        <div class="sbn-pattern-grid-cells">
            <?php for ($i = 0; $i < $total_cells; $i++): ?>
                <div class="sbn-pattern-grid-cell is-beat-label">
                    <?php echo esc_html(sbn_get_beat_label($i, $subdivision, $beats_per_measure, $grid_type)); ?>
                </div>
            <?php endfor; ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

function sbn_render_pattern_row($label, $pattern_string, $grid_data, $is_thumb = false) {
    $total_cells = $grid_data['total_cells'];
    $hits        = str_split(substr($pattern_string, 0, $total_cells));

    ob_start();
    ?>
    <div class="sbn-pattern-grid-row">
        <div class="sbn-pattern-grid-label"><?php echo esc_html($label); ?></div>
        <div class="sbn-pattern-grid-cells">
            <?php foreach ($hits as $hit): ?>
                <?php
                $classes = 'sbn-pattern-grid-cell';
                if ($is_thumb) $classes .= ' is-thumb';
                if ($hit === 'X' || $hit === 'x')     { $classes .= ' is-accent'; $display = '●'; }
                elseif ($hit === 'o' || $hit === 'O') { $classes .= ' is-hit';    $display = '●'; }
                else                                   { $display = ''; }
                ?>
                <div class="<?php echo esc_attr($classes); ?>"><?php echo $display; ?></div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

function sbn_parse_pattern_for_display($pattern) {
    preg_match('/(\d+)\//', $pattern->time_signature, $matches);
    $beats_per_measure = isset($matches[1]) ? (int)$matches[1] : 4;
    $grid_type         = $pattern->grid_type ?? 'sixteenth';
    $sub               = ($grid_type === 'eighth') ? 2 : (($grid_type === 'triplet') ? 3 : 4);
    return array(
        'beats_per_measure' => $beats_per_measure,
        'total_cells'       => (int)$pattern->beats,
        'subdivision'       => $sub,
        'grid_type'         => $grid_type,
    );
}

function sbn_get_beat_label($position, $subdivision, $beats_per_measure, $grid_type = 'sixteenth') {
    $cells_per_bar = $beats_per_measure * $subdivision;
    $pos_in_bar    = $position % $cells_per_bar;
    $beat_num      = (int)floor($pos_in_bar / $subdivision) + 1;
    $sub_pos       = $pos_in_bar % $subdivision;

    if ($sub_pos === 0) return (string)$beat_num;
    if ($grid_type === 'triplet') return $sub_pos === 1 ? 'trip' : 'let';
    if ($grid_type === 'eighth')  return '+';
    $labels = ['e', '+', 'a'];
    return $labels[$sub_pos - 1] ?? '';
}

// ============================================================================
// NOTE: AJAX handler (sbn_get_rhythm_songs) and nonce/asset enqueue are
// registered in SBN_Rhythm_Patterns::__construct() inside class-rhythm-patterns.php
// ============================================================================
