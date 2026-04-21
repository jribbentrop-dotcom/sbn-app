/**
 * Header Height Tracking
 * Updates mega menu position based on header height
 * Version 2.4 - Simplified (no scroll animations)
 */
(function($) {
    'use strict';
    
    $(function() {
        var $header = $('.site-header');
        
        function updateHeaderHeight() {
            // Get actual header height and set as CSS custom property
            var headerHeight = $header.outerHeight();
            document.documentElement.style.setProperty('--header-height', headerHeight + 'px');
        }
        
        // Update on window resize
        var resizeTimeout;
        $(window).on('resize', function() {
            clearTimeout(resizeTimeout);
            resizeTimeout = setTimeout(updateHeaderHeight, 100);
        });
        
        // Watch for header size changes (if content changes dynamically)
        if (window.ResizeObserver) {
            var resizeObserver = new ResizeObserver(function() {
                updateHeaderHeight();
            });
            resizeObserver.observe($header[0]);
        }
        
        // Initial setup
        updateHeaderHeight();
    });
    
})(jQuery);
