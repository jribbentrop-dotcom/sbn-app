/**
 * Chord Progressions Admin JS
 *
 * Handles:
 *  1. Save/delete progression via AJAX (edit form and list)
 *  2. Live numeral preview in the edit form
 *  3. Reprocess-all button
 */

/* global sbnProgressions, jQuery */

(function ($) {
    'use strict';

    const { ajaxUrl, nonce } = window.sbnProgressions || {};

    // =========================================================================
    // 1.  SAVE FORM
    // =========================================================================

    $(document).on('submit', '#sbnProgressionForm', function (e) {
        e.preventDefault();

        const $form   = $(this);
        const $btn    = $('#sbnSaveProgression');
        const $status = $('#sbnSaveStatus');

        $btn.prop('disabled', true).text('Saving…');
        $status.text('').removeClass('sbn-status-success sbn-status-error');

        const data = {
            action: 'sbn_save_progression',
            nonce,
            id:             $form.find('[name="id"]').val(),
            name:           $form.find('[name="name"]').val(),
            category:       $form.find('[name="category"]').val(),
            numerals:       $form.find('[name="numerals"]').val(),
            description:    $form.find('[name="description"]').val(),
            typical_genres: $form.find('[name="typical_genres"]').val(),
            tonality:       $form.find('[name="tonality"]').val() || 'both',
            match_mode:     $form.find('[name="match_mode"]').val() || 'strict',
            tags:           $form.find('[name="tags"]').val() || '',
            sort_order:     $form.find('[name="sort_order"]').val(),
        };

        $.post(ajaxUrl, data)
            .done(function (resp) {
                if (resp.success) {
                    $status.addClass('sbn-status-success').text('Saved!');
                    // Redirect to list after a short delay
                    setTimeout(() => {
                        window.location.href = resp.data.redirect;
                    }, 800);
                } else {
                    $btn.prop('disabled', false).text('Save Changes');
                    $status.addClass('sbn-status-error').text('Error: ' + (resp.data || 'Unknown error'));
                }
            })
            .fail(function () {
                $btn.prop('disabled', false).text('Save Changes');
                $status.addClass('sbn-status-error').text('Network error. Please try again.');
            });
    });

    // =========================================================================
    // 2.  DELETE PROGRESSION
    // =========================================================================

    $(document).on('click', '.sbn-delete-progression', function () {
        const $btn = $(this);
        const id   = $btn.data('id');
        const name = $btn.data('name');

        if (!confirm('Delete "' + name + '"?\n\nThis will also remove all detected occurrences of this progression.')) {
            return;
        }

        $btn.prop('disabled', true).text('Deleting…');

        $.post(ajaxUrl, { action: 'sbn_delete_progression', nonce, id })
            .done(function (resp) {
                if (resp.success) {
                    $btn.closest('tr').fadeOut(300, function () { $(this).remove(); });
                } else {
                    $btn.prop('disabled', false).text('Delete');
                    alert('Error: ' + (resp.data || 'Could not delete.'));
                }
            })
            .fail(function () {
                $btn.prop('disabled', false).text('Delete');
                alert('Network error.');
            });
    });

    // =========================================================================
    // 3.  LIVE NUMERAL PREVIEW
    // =========================================================================

    function renderNumeralPreview(raw) {
        const $preview = $('#sbnNumeralPreview');
        if (!$preview.length) return;

        const tokens = raw.split(',').map(t => t.trim()).filter(Boolean);

        if (tokens.length === 0) {
            $preview.empty();
            return;
        }

        // Warn if any single token looks like a concatenated sequence —
        // i.e. it contains more than one Roman numeral root (e.g. "Imaj7IIm7V7").
        // A valid single token has at most one Roman numeral root at position 0.
        const concatWarning = tokens.some(function (token) {
            // Strip leading accidental (b/#), then count Roman numeral root boundaries
            // A new root starts with an uppercase I, V, or i, v not immediately
            // preceded by another Roman numeral letter.
            const stripped = token.replace(/^[b#]/, '');
            const roots = stripped.match(/(?<![IVXivx])[IVX][IVXivx]*/g) || [];
            return roots.length > 1;
        });

        const chips = tokens.map(function (token) {
            return '<span class="sbn-numeral-chip">' + escHtml(token) + '</span>';
        });

        let html = chips.join('');
        if (concatWarning) {
            html += '<div class="sbn-numeral-warning">⚠ Looks like tokens are missing commas — separate each numeral with a comma, e.g. <code>IIm7,V7,Imaj7</code></div>';
        }
        $preview.html(html);
    }

    $(document).on('input', '#prog_numerals', function () {
        renderNumeralPreview($(this).val());
    });

    // Trigger on page load if editing an existing progression
    if ($('#prog_numerals').length) {
        renderNumeralPreview($('#prog_numerals').val());
    }

    // =========================================================================
    // 4.  REPROCESS ALL
    // =========================================================================

    $(document).on('click', '#sbnReprocessProgressions', function () {
        const $btn    = $(this);
        const $status = $('#sbnReprocessStatus');

        $btn.prop('disabled', true).text('Processing…');
        $status.text('This may take a moment for large libraries…')
               .removeClass('sbn-status-success sbn-status-error');

        $.post(ajaxUrl, { action: 'sbn_reprocess_progressions', nonce })
            .done(function (resp) {
                $btn.prop('disabled', false).text('Reprocess All Leadsheets');
                if (resp.success) {
                    const d = resp.data;
                    $status.addClass('sbn-status-success').text(
                        '✓ Done. Processed ' + d.processed + ' leadsheets, found ' + d.total_occ + ' progression occurrences.'
                    );
                    // Reload the stats grid after a moment
                    setTimeout(() => window.location.reload(), 1500);
                } else {
                    $status.addClass('sbn-status-error').text('Error: ' + (resp.data || 'Unknown error.'));
                }
            })
            .fail(function () {
                $btn.prop('disabled', false).text('Reprocess All Leadsheets');
                $status.addClass('sbn-status-error').text('Network error. Please try again.');
            });
    });

    // =========================================================================
    // UTILITY
    // =========================================================================

    function escHtml(str) {
        return str
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    // =========================================================================
    // 5.  TAG BADGE UI
    // =========================================================================

    /**
     * Read the current tags from the hidden input as a trimmed array.
     */
    function getTags() {
        const raw = $('#prog_tags').val() || '';
        return raw.split(',').map(t => t.trim()).filter(Boolean);
    }

    /**
     * Write an array of tags back to the hidden input and refresh the UI.
     */
    function setTags(arr) {
        // Deduplicate, lowercase for storage
        const unique = [...new Set(arr.map(t => t.trim().toLowerCase()).filter(Boolean))];
        $('#prog_tags').val(unique.join(','));
        renderActiveTags(unique);
        syncPaletteState(unique);
    }

    /**
     * Render the active (selected) tags as removable chips.
     */
    function renderActiveTags(tags) {
        const $container = $('#sbn-tags-active');
        if (!$container.length) return;

        if (tags.length === 0) {
            $container.html('<span class="sbn-tags-none" id="sbn-tags-none">No tags yet — click below to add</span>');
            return;
        }

        const chips = tags.map(tag =>
            `<span class="sbn-tag-active-chip" data-tag="${escHtml(tag)}">` +
                toTitleCase(tag) +
                `<button type="button" class="sbn-tag-remove" aria-label="Remove ${escHtml(tag)}">×</button>` +
            `</span>`
        ).join('');
        $container.html(chips);
    }

    /**
     * Highlight preset buttons that are currently active.
     */
    function syncPaletteState(tags) {
        $('#sbn-tags-palette .sbn-tag-preset').each(function () {
            const t = $(this).data('tag');
            $(this).toggleClass('sbn-tag-preset--active', tags.indexOf(t) !== -1);
        });
    }

    function toTitleCase(str) {
        return str.replace(/\b\w/g, c => c.toUpperCase());
    }

    // Click a preset tag → toggle it on/off
    $(document).on('click', '.sbn-tag-preset', function () {
        const tag  = $(this).data('tag');
        let tags   = getTags();
        const idx  = tags.indexOf(tag);
        if (idx === -1) {
            tags.push(tag);
        } else {
            tags.splice(idx, 1);
        }
        setTags(tags);
    });

    // Remove chip × button
    $(document).on('click', '.sbn-tag-remove', function () {
        const tag = $(this).closest('.sbn-tag-active-chip').data('tag');
        const tags = getTags().filter(t => t !== tag);
        setTags(tags);
    });

    // Add custom tag
    $(document).on('click', '#sbn-tag-custom-add', function () {
        const $input = $('#sbn-tag-custom-input');
        const val    = $input.val().trim().toLowerCase();
        if (!val) return;
        const tags = getTags();
        if (tags.indexOf(val) === -1) {
            tags.push(val);
            setTags(tags);
        }
        $input.val('').focus();
    });

    // Also allow Enter in the custom input
    $(document).on('keydown', '#sbn-tag-custom-input', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            $('#sbn-tag-custom-add').trigger('click');
        }
    });

})(jQuery);
