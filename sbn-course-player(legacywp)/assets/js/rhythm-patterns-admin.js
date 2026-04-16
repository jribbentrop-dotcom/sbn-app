/**
 * SBN Rhythm Patterns Admin JavaScript
 */

(function($) {
    'use strict';

    // =============================================================================
    // AUDIO PREVIEW
    // =============================================================================

    const AudioPreview = {
        initialized: false,
        synth: null,
        bassSynth: null,
        isPlaying: false,
        timer: null,
        currentBeat: 0,

        async init() {
            if (this.initialized || typeof Tone === 'undefined') return;

            await Tone.start();

            this.synth = new Tone.PolySynth(Tone.Synth, {
                oscillator: { type: 'triangle' },
                envelope: { attack: 0.005, decay: 0.1, sustain: 0.05, release: 0.2 },
                volume: -8
            }).toDestination();

            this.bassSynth = new Tone.Synth({
                oscillator: { type: 'triangle' },
                envelope: { attack: 0.01, decay: 0.2, sustain: 0.1, release: 0.3 },
                volume: -6
            }).toDestination();

            this.initialized = true;
        },

        playHit(isThumb = false, isAccent = false) {
            if (!this.initialized) return;

            if (isThumb) {
                this.bassSynth.triggerAttackRelease('E2', '8n');
            } else {
                const notes = isAccent ? ['E4', 'G4', 'B4'] : ['E4', 'B4'];
                const vel = isAccent ? 0.9 : 0.6;
                this.synth.triggerAttackRelease(notes, '16n', undefined, vel);
            }
        },

        stop() {
            if (this.timer) {
                clearInterval(this.timer);
                this.timer = null;
            }
            this.isPlaying = false;
            this.currentBeat = 0;
        }
    };

    // =============================================================================
    // PATTERN GRID RENDERER
    // =============================================================================

    function renderPatternGrid($container, rhythmPattern, thumbPattern, beats, timeSignature, gridType) {
        const rhythm = (rhythmPattern || '').split('');
        const thumb  = (thumbPattern  || '').split('');
        const hasThumb = thumb.some(c => c.toLowerCase() === 'x');
        const type   = gridType || 'sixteenth';
        const beatLabels = generateBeatLabels(beats, timeSignature, type);

        let html = '';
        html += '<div class="sbn-pattern-grid-row"><span class="sbn-pattern-grid-label"></span>';
        for (let i = 0; i < beats; i++)
            html += `<div class="sbn-pattern-grid-cell is-beat-label">${beatLabels[i]}</div>`;
        html += '</div>';

        html += `<div class="sbn-pattern-grid-row"><span class="sbn-pattern-grid-label">${hasThumb ? 'Fingers' : 'Rhythm'}</span>`;
        for (let i = 0; i < beats; i++) {
            const c = rhythm[i] || '.';
            let cls = 'sbn-pattern-grid-cell';
            if (c.toLowerCase() === 'x') cls += ' is-hit';
            if (c === 'X') cls += ' is-accent';
            html += `<div class="${cls}">${c.toLowerCase() === 'x' ? '●' : ''}</div>`;
        }
        html += '</div>';

        if (hasThumb) {
            html += '<div class="sbn-pattern-grid-row"><span class="sbn-pattern-grid-label">Thumb</span>';
            for (let i = 0; i < beats; i++) {
                const c = thumb[i] || '.';
                let cls = 'sbn-pattern-grid-cell is-thumb';
                if (c.toLowerCase() === 'x') cls += ' is-hit';
                html += `<div class="${cls}">${c.toLowerCase() === 'x' ? '●' : ''}</div>`;
            }
            html += '</div>';
        }

        $container.html(html);
    }

    function generateBeatLabels(beats, timeSignature, gridType) {
        const labels = [];
        const timeSig = timeSignature || '4/4';
        const beatsPerBar = parseInt(timeSig.split('/')[0]) || 4;
        const type = gridType || 'sixteenth';
        const sub = (type === 'eighth') ? 2 : (type === 'triplet') ? 3 : 4;
        const cellsPerBar = beatsPerBar * sub;

        for (let i = 0; i < beats; i++) {
            const posInBar = i % cellsPerBar;
            const beatNum  = Math.floor(posInBar / sub) + 1;
            const subPos   = posInBar % sub;
            if (subPos === 0) { labels.push(String(beatNum)); }
            else if (type === 'triplet') { labels.push(subPos === 1 ? 'trip' : 'let'); }
            else if (type === 'eighth')  { labels.push('+'); }
            else { labels.push(['e', '+', 'a'][subPos - 1] || ''); }
        }
        return labels;
    }

    function computeBeats() {
        const time = $('#sbnPatternTime').val() || '4/4';
        const type = $('#sbnPatternGridType').val() || 'sixteenth';
        const bars = parseInt($('#sbnPatternBars').val()) || 1;
        const beatsPerBar = parseInt(time.split('/')[0]) || 4;
        const sub = (type === 'eighth') ? 2 : (type === 'triplet') ? 3 : 4;
        return beatsPerBar * sub * bars;
    }

    // =============================================================================
    // EDITOR GRID
    // =============================================================================

    function renderEditorGrid(beats, rhythmPattern, thumbPattern, timeSignature, gridType) {
        const $grid = $('#sbnPatternEditorGrid');
        const rhythm = (rhythmPattern || '').padEnd(beats, '.').split('');
        const thumb  = (thumbPattern  || '').padEnd(beats, '.').split('');
        const time   = timeSignature || $('#sbnPatternTime').val() || '4/4';
        const type   = gridType || $('#sbnPatternGridType').val() || 'sixteenth';
        const beatLabels = generateBeatLabels(beats, time, type);

        let html = '';
        html += '<div class="sbn-editor-row"><span class="sbn-editor-label"></span>';
        for (let i = 0; i < beats; i++)
            html += `<div class="sbn-editor-cell is-beat-label">${beatLabels[i]}</div>`;
        html += '</div>';

        html += '<div class="sbn-editor-row" data-row="rhythm"><span class="sbn-editor-label">Rhythm</span>';
        for (let i = 0; i < beats; i++) {
            const c = rhythm[i] || '.';
            let cls = 'sbn-editor-cell';
            if (c.toLowerCase() === 'x') cls += ' is-hit';
            if (c === 'X') cls += ' is-accent';
            html += `<div class="${cls}" data-index="${i}" data-row="rhythm">${c.toLowerCase() === 'x' ? '●' : ''}</div>`;
        }
        html += '</div>';

        html += '<div class="sbn-editor-row" data-row="thumb"><span class="sbn-editor-label">Thumb</span>';
        for (let i = 0; i < beats; i++) {
            const c = thumb[i] || '.';
            let cls = 'sbn-editor-cell is-thumb';
            if (c.toLowerCase() === 'x') cls += ' is-hit';
            html += `<div class="${cls}" data-index="${i}" data-row="thumb">${c.toLowerCase() === 'x' ? '●' : ''}</div>`;
        }
        html += '</div>';

        $grid.html(html);
    }

    function updatePatternFromGrid() {
        const beats = computeBeats();
        let rhythm = '';
        let thumb = '';

        $('#sbnPatternEditorGrid .sbn-editor-cell[data-row="rhythm"]').each(function() {
            const $cell = $(this);
            if ($cell.hasClass('is-accent')) {
                rhythm += 'X';
            } else if ($cell.hasClass('is-hit')) {
                rhythm += 'x';
            } else {
                rhythm += '.';
            }
        });

        $('#sbnPatternEditorGrid .sbn-editor-cell[data-row="thumb"]').each(function() {
            const $cell = $(this);
            if ($cell.hasClass('is-hit')) {
                thumb += 'x';
            } else {
                thumb += '.';
            }
        });

        $('#sbnRhythmPattern').val(rhythm);
        $('#sbnThumbPattern').val(thumb);

        updateLivePreview();
    }

    function updateLivePreview() {
        const beats = computeBeats();
        const rhythm = $('#sbnRhythmPattern').val() || '';
        const thumb  = $('#sbnThumbPattern').val()  || '';
        const name   = $('#sbnPatternName').val()   || 'Pattern';
        const time   = $('#sbnPatternTime').val()   || '4/4';
        const type   = $('#sbnPatternGridType').val() || 'sixteenth';
        const bars   = $('#sbnPatternBars').val()   || '1';
        const bpm    = $('#sbnPatternBpm').val()    || 120;
        const slug   = $('#sbnPatternSlug').val()   || 'pattern-slug';

        const $preview = $('#sbnLivePreview');
        let html = `<div class="sbn-pattern-info">
            <strong>${name}</strong>
            <span class="sbn-pattern-time">${time}</span>
            <span class="sbn-pattern-bpm">${bpm} BPM</span>
            <span class="sbn-pattern-bars">${bars} Bar</span>
        </div>`;
        html += '<div class="sbn-pattern-grid" id="sbnPreviewGrid"></div>';
        $preview.html(html);

        renderPatternGrid($('#sbnPreviewGrid'), rhythm, thumb, beats, time, type);

        $('#sbnUsageLeadsheet').text(`[sbn_leadsheet ... rhythm="${slug}"]`);
        $('#sbnUsageRhythm').text(`[rhythm pattern="${slug}"]`);
    }

    // =============================================================================
    // PLAYBACK
    // =============================================================================

    async function playPreview() {
        if (AudioPreview.isPlaying) {
            AudioPreview.stop();
            $('#sbnPreviewPlay').removeClass('is-playing').text('▶');
            $('.sbn-editor-cell').removeClass('is-current');
            return;
        }

        // Init sample-based percussion if available, fall back to synth clicks
        const percTop  = $('#sbnPatternPercTop').val()  || 'none';
        const percBass = $('#sbnPatternPercBass').val() || 'none';
        const hasPerc  = (percTop !== 'none' || percBass !== 'none');
        const samplesUrl = (typeof sbnRhythm !== 'undefined' && sbnRhythm.samplesUrl) ? sbnRhythm.samplesUrl : '';

        if (hasPerc && samplesUrl && window.SbnPercussion) {
            if (!SbnPercussion.ready && !SbnPercussion.loading) {
                SbnPercussion.init(samplesUrl);
            }
            SbnPercussion.resume();
        } else {
            await AudioPreview.init();
        }

        const bpm    = parseInt($('#sbnPatternBpm').val()) || 120;
        const beats  = computeBeats();
        const rhythm = ($('#sbnRhythmPattern').val() || '').split('');
        const thumb  = ($('#sbnThumbPattern').val()  || '').split('');

        AudioPreview.isPlaying = true;
        AudioPreview.currentBeat = 0;
        $('#sbnPreviewPlay').addClass('is-playing').text('■');

        // Interval based on subdivision (eighth = quarter/2, sixteenth = quarter/4, triplet = quarter/3)
        const gridType = $('#sbnPatternGridType').val() || 'sixteenth';
        const subDiv   = gridType === 'eighth' ? 2 : gridType === 'triplet' ? 3 : 4;
        const intervalMs = (60000 / bpm) / subDiv;

        const tick = () => {
            $('.sbn-editor-cell').removeClass('is-current');
            $(`.sbn-editor-cell[data-index="${AudioPreview.currentBeat}"]`).addClass('is-current');

            const r = rhythm[AudioPreview.currentBeat] || '.';
            const t = thumb[AudioPreview.currentBeat]  || '.';
            const rHit = r.toLowerCase() === 'x';
            const tHit = t.toLowerCase() === 'x';

            if (hasPerc && window.SbnPercussion && SbnPercussion.ready) {
                const now = (typeof Tone !== 'undefined') ? Tone.now() : SbnPercussion._audioCtx.currentTime;
                if (rHit && percTop  !== 'none') SbnPercussion.playHit(percTop,  r === 'X', now, r === 'X' ? 1.0 : 0.78);
                if (tHit && percBass !== 'none') SbnPercussion.playHit(percBass, false,     now, 0.85);
            } else {
                if (tHit) AudioPreview.playHit(true,  false);
                if (rHit) AudioPreview.playHit(false, r === 'X');
            }

            AudioPreview.currentBeat++;
            if (AudioPreview.currentBeat >= beats) AudioPreview.currentBeat = 0;
        };

        tick();
        AudioPreview.timer = setInterval(tick, intervalMs);
    }

    // =============================================================================
    // TOAST
    // =============================================================================

    function showToast(message, type) {
        const $toast = $(`<div class="sbn-toast ${type || ''}">${message}</div>`);
        $('body').append($toast);
        setTimeout(() => $toast.fadeOut(300, function() { $(this).remove(); }), 3000);
    }

    // =============================================================================
    // AUTO SLUG
    // =============================================================================

    function generateSlug(name) {
        return name.toLowerCase()
            .replace(/[^a-z0-9\s-]/g, '')
            .replace(/\s+/g, '-')
            .replace(/-+/g, '-')
            .trim();
    }

    // =============================================================================
    // EVENT BINDINGS
    // =============================================================================

    $(document).ready(function() {

        // Render pattern grids on list page
        $('.sbn-pattern-grid[data-rhythm]').each(function() {
            const $grid = $(this);
            const rhythm = $grid.data('rhythm') || '';
            const thumb = $grid.data('thumb') || '';
            const beats = parseInt($grid.data('beats')) || 8;
            const time = $grid.data('time') || '4/4';
            renderPatternGrid($grid, rhythm, thumb, beats, time);
        });

        // Editor page initialization
        if ($('#sbnPatternEditorGrid').length) {
            const beats = computeBeats();
            const rhythm = $('#sbnRhythmPattern').val() || '';
            const thumb  = $('#sbnThumbPattern').val()  || '';
            const type   = $('#sbnPatternGridType').val() || 'sixteenth';

            renderEditorGrid(beats, rhythm, thumb, null, type);
            updateLivePreview();
        }

        // Click on editor cells
        $(document).on('click', '.sbn-editor-cell[data-index]', function() {
            const $cell = $(this);
            const row = $cell.data('row');

            if (row === 'rhythm') {
                // Cycle: empty -> hit -> accent -> empty
                if ($cell.hasClass('is-accent')) {
                    $cell.removeClass('is-hit is-accent').html('');
                } else if ($cell.hasClass('is-hit')) {
                    $cell.addClass('is-accent').html('●');
                } else {
                    $cell.addClass('is-hit').html('●');
                }
            } else {
                // Thumb: toggle only
                $cell.toggleClass('is-hit');
                $cell.html($cell.hasClass('is-hit') ? '●' : '');
            }

            updatePatternFromGrid();
        });

        // Pattern input changes
        $('#sbnRhythmPattern, #sbnThumbPattern').on('input', function() {
            const beats = computeBeats();
            const rhythm = $('#sbnRhythmPattern').val();
            const thumb  = $('#sbnThumbPattern').val();
            const type   = $('#sbnPatternGridType').val() || 'sixteenth';
            renderEditorGrid(beats, rhythm, thumb, null, type);
            updateLivePreview();
        });

        // Bars / grid-type / time-signature change — recompute beats and resize patterns
        $('#sbnPatternBars, #sbnPatternGridType, #sbnPatternTime').on('change', function() {
            const beats = computeBeats();
            let rhythm = $('#sbnRhythmPattern').val() || '';
            let thumb  = $('#sbnThumbPattern').val()  || '';
            const type = $('#sbnPatternGridType').val() || 'sixteenth';

            // Resize patterns to match new beat count
            rhythm = rhythm.padEnd(beats, '.').slice(0, beats);
            thumb  = thumb.padEnd(beats, '.').slice(0, beats);

            $('#sbnRhythmPattern').val(rhythm);
            $('#sbnThumbPattern').val(thumb);

            renderEditorGrid(beats, rhythm, thumb, null, type);
            updateLivePreview();
        });

        // Other field changes
        $('#sbnPatternName, #sbnPatternBpm').on('input change', function() {
            updateLivePreview();
        });

        // Auto-generate slug from name
        $('#sbnPatternName').on('input', function() {
            const $slug = $('#sbnPatternSlug');
            if (!$slug.data('manual')) {
                $slug.val(generateSlug($(this).val()));
                updateLivePreview();
            }
        });

        $('#sbnPatternSlug').on('input', function() {
            $(this).data('manual', true);
            updateLivePreview();
        });

        // Preview play
        $('#sbnPreviewPlay').on('click', function() {
            playPreview();
        });

        // Raw pattern toggle
        $('#sbnRawToggle').on('click', function() {
            const $fields = $('#sbnRawFields');
            const open = $fields.is(':visible');
            $fields.toggle();
            $(this).html(open ? '&#9660; edit raw pattern' : '&#9650; edit raw pattern');
            if (!open) {
                // Sync hidden → raw display fields when opening
                $('#sbnRhythmPatternRaw').val($('#sbnRhythmPattern').val());
                $('#sbnThumbPatternRaw').val($('#sbnThumbPattern').val());
            }
        });

        // Raw inputs → update hidden fields + grid
        $('#sbnRhythmPatternRaw, #sbnThumbPatternRaw').on('input', function() {
            const rhythm = $('#sbnRhythmPatternRaw').val();
            const thumb  = $('#sbnThumbPatternRaw').val();
            $('#sbnRhythmPattern').val(rhythm);
            $('#sbnThumbPattern').val(thumb);
            const beats = computeBeats();
            const type  = $('#sbnPatternGridType').val() || 'sixteenth';
            renderEditorGrid(beats, rhythm, thumb, null, type);
            updateLivePreview();
        });

        // Preview on list
        $(document).on('click', '.sbn-preview-pattern', async function() {
            const $card = $(this).closest('.sbn-pattern-card');
            const $grid = $card.find('.sbn-pattern-grid');
            const rhythm = ($grid.data('rhythm') || '').split('');
            const thumb = ($grid.data('thumb') || '').split('');
            const beats = parseInt($grid.data('beats')) || 8;

            // Simple one-shot preview
            await AudioPreview.init();

            let i = 0;
            const playBeat = () => {
                if (i >= beats) return;

                const r = rhythm[i] || '.';
                const t = thumb[i] || '.';

                if (t.toLowerCase() === 'x') AudioPreview.playHit(true, false);
                if (r.toLowerCase() === 'x') AudioPreview.playHit(false, r === 'X');

                i++;
                setTimeout(playBeat, 150);
            };

            playBeat();
        });

        // Copy shortcode
        $(document).on('click', '.sbn-copy-btn', function() {
            const text = $(this).data('copy');
            navigator.clipboard.writeText(text).then(() => {
                showToast('Copied!', 'success');
            });
        });

        // Save pattern
        $('#sbnSavePattern').on('click', function() {
            const $btn = $(this);
            const id = $('#sbnPatternId').val();

            const data = {
                action: 'sbn_save_rhythm_pattern',
                nonce: sbnRhythm.nonce,
                id: id,
                name: $('#sbnPatternName').val(),
                slug: $('#sbnPatternSlug').val(),
                description: $('#sbnPatternDesc').val(),
                category: $('#sbnPatternCategory').val(),
                time_signature: $('#sbnPatternTime').val(),
                beats: computeBeats(),
                rhythm_pattern: $('#sbnRhythmPattern').val(),
                thumb_pattern: $('#sbnThumbPattern').val(),
                default_bpm: $('#sbnPatternBpm').val(),
                sound:      $('#sbnPatternSound').val(),
                perc_top:   $('#sbnPatternPercTop').val(),
                perc_bass:  $('#sbnPatternPercBass').val(),
                grid_type:  $('#sbnPatternGridType').val(),
                beats:      computeBeats(),
                mp3_file: $('#sbnPatternMp3').val()
            };

            if (!data.name || !data.slug) {
                showToast('Please enter a name and slug', 'error');
                return;
            }

            $btn.prop('disabled', true).text('Saving...');

            $.post(sbnRhythm.ajaxUrl, data, function(response) {
                if (response.success) {
                    showToast('Pattern saved!', 'success');
                    if (!id) {
                        window.location.href = sbnRhythm.ajaxUrl.replace('admin-ajax.php', 'admin.php?page=sbn-rhythm-patterns');
                    }
                } else {
                    showToast('Error: ' + response.data, 'error');
                }
                $btn.prop('disabled', false).text(id ? 'Update Pattern' : 'Create Pattern');
            });
        });

        // Delete pattern
        $(document).on('click', '.sbn-delete-pattern', function() {
            if (!confirm('Delete this rhythm pattern?')) return;

            const $card = $(this).closest('.sbn-pattern-card');
            const id = $(this).data('id');

            $.post(sbnRhythm.ajaxUrl, {
                action: 'sbn_delete_rhythm_pattern',
                nonce: sbnRhythm.nonce,
                id: id
            }, function(response) {
                if (response.success) {
                    $card.fadeOut(300, function() { $(this).remove(); });
                    showToast('Pattern deleted', 'success');
                } else {
                    showToast('Error deleting', 'error');
                }
            });
        });

    });

})(jQuery);
