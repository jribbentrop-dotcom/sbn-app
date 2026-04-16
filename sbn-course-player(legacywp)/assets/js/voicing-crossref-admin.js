/**
 * Voicing Cross-Reference Admin JS
 * 
 * Handles:
 * - Draft dismiss/promote actions
 * - Batch reprocessing
 * - Rendering mini fretboard previews for drafts
 */

(function($) {
    'use strict';

    $(document).ready(function() {
        
        // =============================================
        // RENDER DRAFT FRETBOARD PREVIEWS
        // =============================================
        
        renderDraftDiagrams();
        
        // =============================================
        // DISMISS DRAFT
        // =============================================
        
        $(document).on('click', '.sbn-dismiss-draft', function(e) {
            e.preventDefault();
            
            var $btn = $(this);
            var $card = $btn.closest('.sbn-draft-card');
            var draftId = $btn.data('draft-id');
            
            if (!confirm('Dismiss this voicing? It won\'t appear in the review list anymore.')) {
                return;
            }
            
            $btn.prop('disabled', true).text('Dismissing...');
            
            $.ajax({
                url: sbnCrossref.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'sbn_dismiss_voicing_draft',
                    nonce: sbnCrossref.nonce,
                    draft_id: draftId
                },
                success: function(response) {
                    if (response.success) {
                        $card.addClass('sbn-draft-removing');
                        setTimeout(function() {
                            var $group = $card.closest('.sbn-draft-group');
                            $card.remove();
                            
                            // Update count in group header
                            var remaining = $group.find('.sbn-draft-card').length;
                            if (remaining === 0) {
                                $group.remove();
                            } else {
                                $group.find('.sbn-draft-count').text(remaining + ' unmatched');
                            }
                            
                            // Update badge in tab
                            updateDraftBadge(-1);
                        }, 300);
                    } else {
                        alert('Error: ' + (response.data || 'Unknown error'));
                        $btn.prop('disabled', false).text('Dismiss');
                    }
                },
                error: function() {
                    alert('Request failed. Please try again.');
                    $btn.prop('disabled', false).text('Dismiss');
                }
            });
        });
        
        // =============================================
        // PROMOTE DRAFT TO LIBRARY
        // =============================================
        
        $(document).on('click', '.sbn-promote-draft', function(e) {
            e.preventDefault();
            
            var $btn = $(this);
            var draftId = $btn.data('draft-id');
            
            $btn.prop('disabled', true).text('Adding...');
            
            $.ajax({
                url: sbnCrossref.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'sbn_promote_voicing_draft',
                    nonce: sbnCrossref.nonce,
                    draft_id: draftId
                },
                success: function(response) {
                    if (response.success && response.data.edit_url) {
                        // Redirect to the diagram editor so the teacher can 
                        // fill in voicing_category, inversion, etc.
                        window.location.href = response.data.edit_url;
                    } else {
                        alert('Error: ' + (response.data || 'Unknown error'));
                        $btn.prop('disabled', false).text('Add to Library');
                    }
                },
                error: function() {
                    alert('Request failed. Please try again.');
                    $btn.prop('disabled', false).text('Add to Library');
                }
            });
        });
        
        
        // =============================================
        // REPROCESS ALL LEADSHEETS
        // =============================================
        
        $('#sbnReprocessAll').on('click', function() {
            var $btn = $(this);
            var $status = $('#sbnReprocessStatus');
            
            $btn.prop('disabled', true).text('Processing...');
            $status.text('Working...').removeClass('sbn-status-success sbn-status-error');
            
            $.ajax({
                url: sbnCrossref.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'sbn_reprocess_leadsheets',
                    nonce: sbnCrossref.nonce
                },
                success: function(response) {
                    $btn.prop('disabled', false).text('Reprocess All Leadsheets');
                    
                    if (response.success) {
                        var d = response.data;
                        $status
                            .addClass('sbn-status-success')
                            .text('Done! Processed ' + d.processed + ' leadsheets. ' +
                                  d.matched + ' matches, ' + d.unmatched + ' unmatched.');
                        
                        // Reload after a moment so stats update
                        setTimeout(function() {
                            window.location.reload();
                        }, 2000);
                    } else {
                        $status
                            .addClass('sbn-status-error')
                            .text('Error: ' + (response.data || 'Unknown error'));
                    }
                },
                error: function() {
                    $btn.prop('disabled', false).text('Reprocess All Leadsheets');
                    $status
                        .addClass('sbn-status-error')
                        .text('Request failed. Check your connection.');
                }
            });
        });
    });
    
    // =============================================
    // HELPER: Render fretboard previews for drafts
    // =============================================
    
    function renderDraftDiagrams() {
        $('.sbn-draft-diagram').each(function() {
            var $container = $(this);
            var frets = $container.data('frets') + '';  // ensure string
            var position = parseInt($container.data('position')) || 1;
            var fingers = ($container.data('fingers') || '') + '';
            
            // Convert fret string to diagram_data format
            var diagramData = fretStringToDiagramData(frets, fingers, position);
            
            // Calculate start_fret from the fretted positions
            var minFret = 99;
            diagramData.positions.forEach(function(pos) {
                if (pos.fret > 0 && pos.fret < minFret) {
                    minFret = pos.fret;
                }
            });
            
            var startFret = minFret < 99 ? minFret : 1;
            // If all frets are within first 4, show from fret 1
            var maxFret = 0;
            diagramData.positions.forEach(function(pos) {
                if (pos.fret > maxFret) maxFret = pos.fret;
            });
            if (maxFret <= 4) startFret = 1;
            
            // Use the existing renderMiniFretboard if available
            if (typeof renderMiniFretboard === 'function') {
                var jsonStr = JSON.stringify(diagramData);
                var $fretboard = $('<div class="sbn-chord-fretboard"></div>')
                    .attr('data-diagram', jsonStr)
                    .attr('data-start-fret', startFret);
                
                $container.empty().append($fretboard);
                renderMiniFretboard($fretboard[0]);
            } else {
                // Fallback: show fret string as text
                $container.html('<code style="font-size: 14px;">' + frets + '</code>');
            }
        });
    }
    
    /**
     * Convert a fret string like "x02010" to diagram_data JSON format
     */
    function fretStringToDiagramData(fretString, fingerString, position) {
        var positions = [];
        var muted = [];
        var open = [];
        
        // Use shared parser for multi-digit fret support
        var parsedFrets = (typeof SbnChordCard !== 'undefined' && SbnChordCard.parseFretString)
            ? SbnChordCard.parseFretString(fretString, position || 1)
            : fretString.split('');
        var fingerChars = fingerString ? fingerString.split('') : [];
        
        for (var i = 0; i < parsedFrets.length; i++) {
            var stringNum = i + 1;
            var fretVal = parsedFrets[i];
            
            if (fretVal === 'x' || fretVal === 'X') {
                muted.push(stringNum);
            } else if (fretVal === 0 || fretVal === '0') {
                open.push(stringNum);
            } else {
                var fret = (typeof fretVal === 'number') ? fretVal : parseInt(fretVal);
                var finger = fingerChars[i] ? parseInt(fingerChars[i]) : null;
                if (finger === 0) finger = null;
                
                positions.push({
                    string: stringNum,
                    fret: fret,
                    finger: finger
                });
            }
        }
        
        return {
            positions: positions,
            barres: [],
            muted: muted,
            open: open
        };
    }
    
    /**
     * Update the draft count badge in the tab navigation
     */
    function updateDraftBadge(delta) {
        var $badge = $('.nav-tab-wrapper .sbn-badge');
        if ($badge.length) {
            var current = parseInt($badge.text()) || 0;
            var newCount = Math.max(0, current + delta);
            if (newCount > 0) {
                $badge.text(newCount);
            } else {
                $badge.remove();
            }
        }
    }
    // Clear All unmatched voicings
$(document).on('click', '.sbn-clear-all-drafts', function () {
    if (!confirm('Delete all ' + $(this).closest('.sbn-crossref-content').find('.sbn-draft-card').length + ' unmatched voicings? This cannot be undone.')) return;
    var $btn = $(this);
    $btn.prop('disabled', true).text('Clearing…');
    $.post(ajaxurl, {
        action: 'sbn_clear_all_drafts',
        nonce:  $btn.data('nonce')
    }, function (resp) {
        if (resp.success) {
            location.reload();
        } else {
            alert('Error: ' + (resp.data || 'unknown'));
            $btn.prop('disabled', false).text('🗑 Clear All');
        }
    }).fail(function () {
        alert('Request failed.');
        $btn.prop('disabled', false).text('🗑 Clear All');
    });
});

})(jQuery);
