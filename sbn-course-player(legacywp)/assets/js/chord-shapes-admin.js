/**
 * SBN Chord Shapes Admin JavaScript
 * Handles the visual editor for chord shapes
 */

(function($) {
    'use strict';

    $(document).ready(function() {
        
        // Delete shape
        $('.sbn-delete-shape').on('click', function(e) {
            e.preventDefault();
            
            if (!confirm('Are you sure you want to delete this chord shape?')) {
                return;
            }
            
            const $btn = $(this);
            const shapeId = $btn.data('id');
            
            $.ajax({
                url: sbnChordShapes.ajax_url,
                type: 'POST',
                data: {
                    action: 'sbn_delete_chord_shape',
                    nonce: sbnChordShapes.nonce,
                    id: shapeId
                },
                success: function(response) {
                    if (response.success) {
                        $btn.closest('tr').fadeOut(300, function() {
                            $(this).remove();
                        });
                    } else {
                        alert('Error: ' + response.data);
                    }
                },
                error: function() {
                    alert('Network error occurred');
                }
            });
        });
        
        // Save shape button
        $('#sbnSaveShape').on('click', function(e) {
            e.preventDefault();
            
            const $btn = $(this);
            const originalText = $btn.text();
            
            // Get form data
            const shapeData = {
                action: 'sbn_save_chord_shape',
                nonce: sbnChordShapes.nonce,
                id: $('#sbnShapeId').val(),
                quality: $('#sbnQuality').val(),
                extensions: $('#sbnExtensions').val(),
                voicing_category: $('#sbnVoicingCategory').val(),
                root_string: $('#sbnRootString').val(),
                description: $('#sbnDescription').val(),
                interval_labels: $('#sbnIntervalLabels').val(),
                diagram_data: JSON.stringify(window.sbnGetDiagramData ? window.sbnGetDiagramData() : {})
            };
            
            // Validate
            if (!shapeData.quality || !shapeData.voicing_category || !shapeData.root_string) {
                alert('Please fill in all required fields (Quality, Category, Root String)');
                return;
            }
            
            $btn.prop('disabled', true).text('Saving...');
            
            $.ajax({
                url: sbnChordShapes.ajax_url,
                type: 'POST',
                data: shapeData,
                success: function(response) {
                    if (response.success) {
                        alert('Shape saved successfully! Slug: ' + response.data.shape_slug);
                        window.location.href = sbnChordShapes.admin_url + '?page=sbn-chord-shapes';
                    } else {
                        alert('Error: ' + response.data);
                        $btn.prop('disabled', false).text(originalText);
                    }
                },
                error: function() {
                    alert('Network error occurred');
                    $btn.prop('disabled', false).text(originalText);
                }
            });
        });
        
        // Load chord diagrams editor assets if on edit page
        if ($('#sbnFretboardEditor').length) {
            // The chord diagrams JS should already be loaded
            // We just need to initialize it
            console.log('Chord shapes editor loaded - using chord diagrams fretboard editor');
        }
    });

})(jQuery);
