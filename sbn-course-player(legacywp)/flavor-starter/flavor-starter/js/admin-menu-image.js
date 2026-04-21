/**
 * Admin Menu Image Uploader
 * Handles the featured image field in menu items
 */
(function($) {
    'use strict';
    
    $(function() {
        var frame;
        
        // Toggle featured fields visibility based on column type
        $(document).on('change', '.sbn-col-type-select', function() {
            var itemId = $(this).data('item-id');
            var value = $(this).val();
            var $fields = $('#sbn-featured-fields-' + itemId);
            
            if (value === 'featured') {
                $fields.slideDown(200);
            } else {
                $fields.slideUp(200);
            }
        });
        
        // Upload image button
        $(document).on('click', '.sbn-upload-image', function(e) {
            e.preventDefault();
            
            var $button = $(this);
            var itemId = $button.data('item-id');
            var $input = $('#menu-item-featured-image-' + itemId);
            var $preview = $('#sbn-image-preview-' + itemId);
            
            // Create media frame
            frame = wp.media({
                title: 'Select Featured Image',
                button: { text: 'Use this image' },
                multiple: false
            });
            
            // When image is selected
            frame.on('select', function() {
                var attachment = frame.state().get('selection').first().toJSON();
                
                $input.val(attachment.id);
                $preview.html('<img src="' + attachment.sizes.thumbnail.url + '" alt="Preview">');
                $button.text('Change Image');
                
                // Add remove button if not present
                if (!$button.siblings('.sbn-remove-image').length) {
                    $button.after('<button type="button" class="button sbn-remove-image" data-item-id="' + itemId + '">Remove</button>');
                }
            });
            
            frame.open();
        });
        
        // Remove image button
        $(document).on('click', '.sbn-remove-image', function(e) {
            e.preventDefault();
            
            var $button = $(this);
            var itemId = $button.data('item-id');
            var $input = $('#menu-item-featured-image-' + itemId);
            var $preview = $('#sbn-image-preview-' + itemId);
            var $uploadBtn = $button.siblings('.sbn-upload-image');
            
            $input.val('');
            $preview.empty();
            $uploadBtn.text('Select Image');
            $button.remove();
        });
    });
    
})(jQuery);
