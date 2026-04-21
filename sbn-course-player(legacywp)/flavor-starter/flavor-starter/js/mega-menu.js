/**
 * Mega Menu - Click & Touch Support (No Transitions)
 * Version 4.0 - Instant switching
 */
(function($) {
    'use strict';
    
    $(function() {
        var $menuItems = $('.main-navigation .menu-item-has-children').not('.menu-item-cart').not('.sub-menu .menu-item-has-children');
        var $cartItem = $('.menu-item-cart');
        var $openMenu = null;
        
        // Create backdrop element
        var $backdrop = $('<div class="mega-menu-backdrop"></div>');
        $('body').append($backdrop);
        
        function closeAllMenus() {
            $menuItems.removeClass('manual-hover');
            $cartItem.removeClass('manual-hover');
            $openMenu = null;
            $('body').removeClass('mega-menu-open');
        }
        
        function openMenu($item) {
            $item.addClass('manual-hover');
            $openMenu = $item;
            $('body').addClass('mega-menu-open');
        }
        
        function switchMenu($fromItem, $toItem) {
            openMenu($toItem);
            setTimeout(function() {
                $fromItem.removeClass('manual-hover');
            }, 10);
        }
        
        // Click handler for menu items
        $menuItems.each(function() {
            var $item = $(this);
            var $link = $item.find('> a').first();
            
            $link.on('click', function(e) {
                if ($item.hasClass('manual-hover')) {
                    // This menu is already open - navigate (allow default)
                    return true;
                } else if ($openMenu && $openMenu.length) {
                    // Another menu is open - switch instantly
                    e.preventDefault();
                    e.stopPropagation();
                    switchMenu($openMenu, $item);
                } else {
                    // No menu open - open this one
                    e.preventDefault();
                    e.stopPropagation();
                    closeAllMenus();
                    openMenu($item);
                }
            });
        });
        
        // Close on outside click
        $(document).on('click', function(e) {
            if (!$openMenu) return;
            var $target = $(e.target);
            if ($target.closest('.menu-item-has-children > a').length ||
                $target.closest('.sub-menu').length) {
                return;
            }
            closeAllMenus();
        });
        
        // Close on escape key
        $(document).on('keydown', function(e) {
            if (e.key === 'Escape') closeAllMenus();
        });
    });
    
})(jQuery);
