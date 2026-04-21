<?php
/**
 * Soul Bossa Nova Theme
 * 
 * Unified Mega Menu - All columns from WordPress Menu
 * Version 2.2.0
 */

if (!defined('ABSPATH')) exit;

/* ==========================================================================
   THEME SETUP
   ========================================================================== */

function flavor_starter_setup() {
    add_theme_support('title-tag');
    add_theme_support('custom-logo', array(
        'height'      => 100,
        'width'       => 300,
        'flex-height' => true,
        'flex-width'  => true,
    ));
    add_theme_support('post-thumbnails');
    add_theme_support('html5', array(
        'search-form', 'comment-form', 'comment-list',
        'gallery', 'caption', 'style', 'script',
    ));
    add_theme_support('woocommerce');
    add_theme_support('wc-product-gallery-zoom');
    add_theme_support('wc-product-gallery-lightbox');
    add_theme_support('wc-product-gallery-slider');
    
    register_nav_menus(array(
        'primary' => __('Primary Menu', 'flavor-starter'),
        'footer'  => __('Footer Menu', 'flavor-starter'),
    ));
}
add_action('after_setup_theme', 'flavor_starter_setup');

/* ==========================================================================
   ENQUEUE STYLES & SCRIPTS
   ========================================================================== */

function flavor_starter_enqueue() {
    $version = '3.0.0.' . time();
    
    wp_enqueue_style('flavor-starter-style', get_stylesheet_uri(), array(), $version);
    wp_enqueue_script('jquery');
    
    // Mega Menu
    wp_enqueue_script(
        'flavor-starter-mega-menu',
        get_template_directory_uri() . '/js/mega-menu.js',
        array('jquery'),
        $version,
        true
    );
    
    // Header Scroll Effects
    wp_enqueue_script(
        'flavor-starter-header-scroll',
        get_template_directory_uri() . '/js/header-scroll.js',
        array('jquery'),
        $version,
        true
    );
    
    // Pass AJAX URL and nonce for media uploader
    wp_localize_script('flavor-starter-mega-menu', 'sbnMegaMenu', array(
        'ajaxUrl' => admin_url('admin-ajax.php'),
    ));
}
add_action('wp_enqueue_scripts', 'flavor_starter_enqueue');

/* ==========================================================================
   ADMIN: ENQUEUE MEDIA UPLOADER FOR MENU SCREEN
   ========================================================================== */

function sbn_admin_menu_scripts($hook) {
    if ($hook !== 'nav-menus.php') return;
    
    wp_enqueue_media();
    wp_enqueue_script(
        'sbn-menu-image-uploader',
        get_template_directory_uri() . '/js/admin-menu-image.js',
        array('jquery'),
        '2.2.0',
        true
    );
    wp_enqueue_style(
        'sbn-menu-admin',
        get_template_directory_uri() . '/css/admin-menu.css',
        array(),
        '2.2.0'
    );
}
add_action('admin_enqueue_scripts', 'sbn_admin_menu_scripts');

/* ==========================================================================
   MEGA MENU - CUSTOM FIELDS FOR MENU ITEMS
   ========================================================================== 
   
   Adds the following fields to menu items:
   - Icon (dropdown)
   - Column Type (dropdown: nav/featured/cta)
   - Featured Image (media uploader)
   - Featured Button Text (text)
   
   ========================================================================== */

add_action('wp_nav_menu_item_custom_fields', 'sbn_menu_custom_fields', 10, 4);

function sbn_menu_custom_fields($item_id, $item, $depth, $args) {
    // Get saved values
    $icon = get_post_meta($item_id, '_menu_item_icon', true);
    $col_type = get_post_meta($item_id, '_menu_item_col_type', true);
    $featured_image = get_post_meta($item_id, '_menu_item_featured_image', true);
    $featured_button = get_post_meta($item_id, '_menu_item_featured_button', true);
    
    $image_url = $featured_image ? wp_get_attachment_image_url($featured_image, 'thumbnail') : '';
    ?>
    
    <!-- Icon Field -->
    <p class="field-icon description description-wide">
        <label for="edit-menu-item-icon-<?php echo $item_id; ?>">
            <strong>Icon</strong><br>
            <select id="edit-menu-item-icon-<?php echo $item_id; ?>" 
                    class="widefat" 
                    name="menu-item-icon[<?php echo $item_id; ?>]">
                <option value="">— No Icon —</option>
                <option value="star" <?php selected($icon, 'star'); ?>>⭐ Star</option>
                <option value="star-filled" <?php selected($icon, 'star-filled'); ?>>★ Star Filled</option>
                <option value="clock" <?php selected($icon, 'clock'); ?>>🕐 Clock</option>
                <option value="music" <?php selected($icon, 'music'); ?>>🎵 Music</option>
                <option value="video" <?php selected($icon, 'video'); ?>>🎥 Video</option>
                <option value="file" <?php selected($icon, 'file'); ?>>📄 File</option>
                <option value="book" <?php selected($icon, 'book'); ?>>📖 Book</option>
                <option value="shield" <?php selected($icon, 'shield'); ?>>🛡️ Shield</option>
                <option value="users" <?php selected($icon, 'users'); ?>>👥 Users</option>
                <option value="tag" <?php selected($icon, 'tag'); ?>>🏷️ Tag</option>
                <option value="check" <?php selected($icon, 'check'); ?>>✓ Checkmark</option>
            </select>
        </label>
    </p>
    
    <!-- Column Type Field -->
    <p class="field-col-type description description-wide">
        <label for="edit-menu-item-col-type-<?php echo $item_id; ?>">
            <strong>Mega Menu Column Type</strong><br>
            <select id="edit-menu-item-col-type-<?php echo $item_id; ?>" 
                    class="widefat sbn-col-type-select" 
                    name="menu-item-col-type[<?php echo $item_id; ?>]"
                    data-item-id="<?php echo $item_id; ?>">
                <option value="">— Default (Auto) —</option>
                <option value="nav" <?php selected($col_type, 'nav'); ?>>Navigation Column</option>
                <option value="featured" <?php selected($col_type, 'featured'); ?>>Featured Column (with image)</option>
                <option value="cta" <?php selected($col_type, 'cta'); ?>>CTA Box (gradient)</option>
            </select>
        </label>
    </p>
    
    <!-- Make Last Item Button (for Nav columns) -->
    <?php 
    $make_button = get_post_meta($item_id, '_menu_item_make_button', true);
    ?>
    <p class="field-make-button description description-wide">
        <label for="edit-menu-item-make-button-<?php echo $item_id; ?>">
            <input type="checkbox" 
                   id="edit-menu-item-make-button-<?php echo $item_id; ?>" 
                   name="menu-item-make-button[<?php echo $item_id; ?>]" 
                   value="1" 
                   <?php checked($make_button, '1'); ?>>
            <strong>Style Last Item as Button</strong>
            <br><small>Makes the last submenu item display as a gradient button (e.g., "View All")</small>
        </label>
    </p>
    
    <!-- Featured Image Field -->
    <div class="field-featured-image description description-wide sbn-featured-fields" 
         id="sbn-featured-fields-<?php echo $item_id; ?>"
         style="<?php echo $col_type === 'featured' ? '' : 'display:none;'; ?>">
        
        <label><strong>Featured Image</strong></label>
        <div class="sbn-image-preview" id="sbn-image-preview-<?php echo $item_id; ?>">
            <?php if ($image_url): ?>
                <img src="<?php echo esc_url($image_url); ?>" alt="Preview">
            <?php endif; ?>
        </div>
        <input type="hidden" 
               id="menu-item-featured-image-<?php echo $item_id; ?>" 
               name="menu-item-featured-image[<?php echo $item_id; ?>]" 
               value="<?php echo esc_attr($featured_image); ?>">
        <button type="button" class="button sbn-upload-image" data-item-id="<?php echo $item_id; ?>">
            <?php echo $featured_image ? 'Change Image' : 'Select Image'; ?>
        </button>
        <?php if ($featured_image): ?>
        <button type="button" class="button sbn-remove-image" data-item-id="<?php echo $item_id; ?>">Remove</button>
        <?php endif; ?>
        
        <p style="margin-top: 10px;">
            <label for="edit-menu-item-featured-desc-<?php echo $item_id; ?>">
                <strong>Description</strong><br>
                <input type="text" 
                       id="edit-menu-item-featured-desc-<?php echo $item_id; ?>" 
                       class="widefat" 
                       name="menu-item-featured-desc[<?php echo $item_id; ?>]" 
                       value="<?php echo esc_attr($featured_button); ?>"
                       placeholder="e.g., Learn the fundamentals">
            </label>
        </p>
        
        <p style="margin-top: 10px;">
            <?php $button_text = get_post_meta($item_id, '_menu_item_featured_button_text', true); ?>
            <label for="edit-menu-item-featured-button-text-<?php echo $item_id; ?>">
                <strong>Button Text (hover)</strong><br>
                <input type="text" 
                       id="edit-menu-item-featured-button-text-<?php echo $item_id; ?>" 
                       class="widefat" 
                       name="menu-item-featured-button-text[<?php echo $item_id; ?>]" 
                       value="<?php echo esc_attr($button_text); ?>"
                       placeholder="e.g., Start Learning">
            </label>
        </p>
    </div>
    
    <?php
}

/* ==========================================================================
   SAVE CUSTOM MENU FIELDS
   ========================================================================== */

add_action('wp_update_nav_menu_item', 'sbn_save_menu_custom_fields', 10, 2);

function sbn_save_menu_custom_fields($menu_id, $menu_item_db_id) {
    // Icon
    if (isset($_POST['menu-item-icon'][$menu_item_db_id])) {
        update_post_meta($menu_item_db_id, '_menu_item_icon', 
            sanitize_text_field($_POST['menu-item-icon'][$menu_item_db_id]));
    }
    
    // Column Type
    if (isset($_POST['menu-item-col-type'][$menu_item_db_id])) {
        update_post_meta($menu_item_db_id, '_menu_item_col_type', 
            sanitize_text_field($_POST['menu-item-col-type'][$menu_item_db_id]));
    }
    
    // Make Last Item Button
    if (isset($_POST['menu-item-make-button'][$menu_item_db_id])) {
        update_post_meta($menu_item_db_id, '_menu_item_make_button', '1');
    } else {
        delete_post_meta($menu_item_db_id, '_menu_item_make_button');
    }
    
    // Featured Image
    if (isset($_POST['menu-item-featured-image'][$menu_item_db_id])) {
        update_post_meta($menu_item_db_id, '_menu_item_featured_image', 
            absint($_POST['menu-item-featured-image'][$menu_item_db_id]));
    }
    
    // Featured Description Text (keeping same meta key for backward compatibility)
    if (isset($_POST['menu-item-featured-desc'][$menu_item_db_id])) {
        update_post_meta($menu_item_db_id, '_menu_item_featured_button', 
            sanitize_text_field($_POST['menu-item-featured-desc'][$menu_item_db_id]));
    }
    
    // Featured Button Text (hover button)
    if (isset($_POST['menu-item-featured-button-text'][$menu_item_db_id])) {
        update_post_meta($menu_item_db_id, '_menu_item_featured_button_text', 
            sanitize_text_field($_POST['menu-item-featured-button-text'][$menu_item_db_id]));
    }
}

/* ==========================================================================
   CUSTOM WALKER FOR MEGA MENU
   ========================================================================== 
   
   Adds column type classes and renders featured images inline.
   
   ========================================================================== */

class SBN_Mega_Menu_Walker extends Walker_Nav_Menu {
    
    /**
     * Start element output
     */
    public function start_el(&$output, $item, $depth = 0, $args = null, $id = 0) {
        $classes = empty($item->classes) ? array() : (array) $item->classes;
        
        // Get custom fields
        $col_type = get_post_meta($item->ID, '_menu_item_col_type', true);
        $featured_image = get_post_meta($item->ID, '_menu_item_featured_image', true);
        $featured_button = get_post_meta($item->ID, '_menu_item_featured_button', true);
        $featured_button_text = get_post_meta($item->ID, '_menu_item_featured_button_text', true);
        $icon = get_post_meta($item->ID, '_menu_item_icon', true);
        $make_button = get_post_meta($item->ID, '_menu_item_make_button', true);
        
        // Add column type class at depth 1 or 2 (mega menu columns)
        // Depth 1: Standard mega menu structure  
        // Depth 2: Allows for nested menu structures
        if (($depth === 1 || $depth === 2) && $col_type) {
            $classes[] = 'mega-col-' . $col_type;
            
            // Add button class if enabled
            if ($make_button) {
                $classes[] = 'has-button-last';
            }
        }
        
        // Store featured data for later use
        $item->sbn_featured_image = $featured_image;
        $item->sbn_featured_button = $featured_button;
        $item->sbn_featured_button_text = $featured_button_text;
        $item->sbn_col_type = $col_type;
        $item->sbn_icon = $icon;
        
        // Build class string
        $class_names = implode(' ', array_filter($classes));
        $class_names = $class_names ? ' class="' . esc_attr($class_names) . '"' : '';
        
        // Build ID string
        $id_attr = apply_filters('nav_menu_item_id', 'menu-item-' . $item->ID, $item, $args, $depth);
        $id_attr = $id_attr ? ' id="' . esc_attr($id_attr) . '"' : '';
        
        $output .= '<li' . $id_attr . $class_names . '>';
        
        // Build link attributes
        $atts = array(
            'title'  => !empty($item->attr_title) ? $item->attr_title : '',
            'target' => !empty($item->target) ? $item->target : '',
            'rel'    => !empty($item->xfn) ? $item->xfn : '',
            'href'   => !empty($item->url) ? $item->url : '',
        );
        $atts = apply_filters('nav_menu_link_attributes', $atts, $item, $args, $depth);
        
        $attributes = '';
        foreach ($atts as $attr => $value) {
            if (!empty($value)) {
                $attributes .= ' ' . $attr . '="' . esc_attr($value) . '"';
            }
        }
        
        // Get title with icon
        $title = apply_filters('the_title', $item->title, $item->ID);
        $title = apply_filters('nav_menu_item_title', $title, $item, $args, $depth);
        
        // Build item output
        $item_output = isset($args->before) ? $args->before : '';
        
        // For featured columns, wrap in card structure (depth 1 or 2)
        if (($depth === 1 || $depth === 2) && $col_type === 'featured' && $featured_image) {
            $image_url = wp_get_attachment_image_url($featured_image, 'large');
            $item_output .= '<a' . $attributes . '>' . $title . '</a>';
            if ($featured_button) {
                $item_output .= '<p class="mega-featured-desc">' . esc_html($featured_button) . '</p>';
            }
            $item_output .= '<div class="mega-featured-image">';
            $item_output .= '<a href="' . esc_url($item->url) . '">';
            $item_output .= '<img src="' . esc_url($image_url) . '" alt="' . esc_attr($item->title) . '">';
            // Add hover button if text is provided
            if ($featured_button_text) {
                $item_output .= '<span class="mega-featured-button">' . esc_html($featured_button_text) . '</span>';
            }
            $item_output .= '</a>';
            $item_output .= '</div>';
        } else {
            $item_output .= '<a' . $attributes . '>';
            $item_output .= (isset($args->link_before) ? $args->link_before : '') . $title . (isset($args->link_after) ? $args->link_after : '');
            $item_output .= '</a>';
        }
        
        $item_output .= isset($args->after) ? $args->after : '';
        
        $output .= apply_filters('walker_nav_menu_start_el', $item_output, $item, $depth, $args);
    }
}

/**
 * Use custom walker for primary menu
 */
function sbn_use_mega_menu_walker($args) {
    if (isset($args['theme_location']) && $args['theme_location'] === 'primary') {
        $args['walker'] = new SBN_Mega_Menu_Walker();
    }
    return $args;
}
add_filter('wp_nav_menu_args', 'sbn_use_mega_menu_walker');

/* ==========================================================================
   ADD ICONS TO MENU ITEMS
   ========================================================================== */

add_filter('nav_menu_item_title', 'sbn_add_icon_to_menu_title', 10, 4);

function sbn_add_icon_to_menu_title($title, $item, $args, $depth) {
    if (!isset($args->theme_location) || $args->theme_location !== 'primary') {
        return $title;
    }
    
    $icon = get_post_meta($item->ID, '_menu_item_icon', true);
    if (!$icon) return $title;
    
    $icons = array(
        'arrow'       => '<svg class="menu-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>',
        'star'        => '<svg class="menu-icon icon-difficulty" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>',
        'star-filled' => '<svg class="menu-icon icon-difficulty" viewBox="0 0 24 24" fill="currentColor" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>',
        'clock'       => '<svg class="menu-icon icon-duration" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>',
        'music'       => '<svg class="menu-icon icon-lessons" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg>',
        'video'       => '<svg class="menu-icon icon-level" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2" ry="2"/></svg>',
        'file'        => '<svg class="menu-icon icon-lessons" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>',
        'book'        => '<svg class="menu-icon icon-lessons" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>',
        'shield'      => '<svg class="menu-icon icon-level" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>',
        'users'       => '<svg class="menu-icon icon-level" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
        'tag'         => '<svg class="menu-icon icon-difficulty" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>',
        'check'       => '<svg class="menu-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>',
    );
    
    if (isset($icons[$icon])) {
        return $icons[$icon] . '<span>' . $title . '</span>';
    }
    
    return $title;
}

/* ==========================================================================
   CART FUNCTIONALITY
   ========================================================================== */

function flavor_starter_cart_menu_item($items, $args) {
    if ($args->theme_location === 'primary' && class_exists('WooCommerce')) {
        $count = WC()->cart ? WC()->cart->get_cart_contents_count() : 0;
        
        $cart_html = '<li class="menu-item menu-item-cart">';
        $cart_html .= '<a href="' . wc_get_cart_url() . '" class="cart-icon-link">';
        $cart_html .= '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="m1 1 4 0 2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>';
        // Always render cart count, hide with CSS when 0
        $cart_html .= '<span class="cart-count' . ($count == 0 ? ' hidden' : '') . '">' . $count . '</span>';
        $cart_html .= '</a>';
        $cart_html .= flavor_starter_cart_dropdown();
        $cart_html .= '</li>';
        
        $items .= $cart_html;
    }
    return $items;
}
add_filter('wp_nav_menu_items', 'flavor_starter_cart_menu_item', 10, 2);

function flavor_starter_cart_dropdown() {
    if (!class_exists('WooCommerce') || !WC()->cart) return '';
    
    $cart = WC()->cart;
    
    ob_start();
    ?>
    <div class="cart-dropdown">
        <?php if ($cart->get_cart_contents_count() > 0): ?>
            <ul class="cart-items">
                <?php foreach ($cart->get_cart() as $cart_item_key => $cart_item): 
                    $product = $cart_item['data'];
                    $product_id = $cart_item['product_id'];
                ?>
                <li class="cart-item">
                    <div class="cart-item-image"><?php echo $product->get_image(); ?></div>
                    <div class="cart-item-details">
                        <a href="<?php echo get_permalink($product_id); ?>" class="cart-item-name"><?php echo $product->get_name(); ?></a>
                        <span class="cart-item-price"><?php echo $product->get_price_html(); ?></span>
                    </div>
                    <a href="<?php echo wc_get_cart_remove_url($cart_item_key); ?>" class="cart-item-remove">×</a>
                </li>
                <?php endforeach; ?>
            </ul>
            <div class="cart-dropdown-footer">
                <div class="cart-total">
                    <span>Total:</span>
                    <strong><?php echo $cart->get_cart_subtotal(); ?></strong>
                </div>
                <a href="<?php echo wc_get_checkout_url(); ?>" class="checkout-button">Checkout</a>
            </div>
        <?php else: ?>
            <p style="text-align: center; padding: 20px; margin: 0;">Your cart is empty.</p>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}

function flavor_starter_cart_fragments($fragments) {
    $count = WC()->cart->get_cart_contents_count();
    $fragments['.cart-count'] = '<span class="cart-count' . ($count == 0 ? ' hidden' : '') . '">' . $count . '</span>';
    $fragments['.cart-dropdown'] = flavor_starter_cart_dropdown();
    return $fragments;
}
add_filter('woocommerce_add_to_cart_fragments', 'flavor_starter_cart_fragments');

/* ==========================================================================
   WOOCOMMERCE - REMOVE DEFAULT STYLES
   ========================================================================== */

function flavor_starter_dequeue_woocommerce_styles($enqueue_styles) {
    unset($enqueue_styles['woocommerce-general']);
    unset($enqueue_styles['woocommerce-layout']);
    unset($enqueue_styles['woocommerce-smallscreen']);
    return $enqueue_styles;
}
add_filter('woocommerce_enqueue_styles', 'flavor_starter_dequeue_woocommerce_styles');

function flavor_starter_remove_wc_block_styles() {
    wp_dequeue_style('wc-blocks-style');
    wp_dequeue_style('wc-blocks-style-css');
}
add_action('wp_enqueue_scripts', 'flavor_starter_remove_wc_block_styles', 100);

/* ==========================================================================
   WOOCOMMERCE - CHECKOUT
   ========================================================================== */

function flavor_starter_remove_order_notes($fields) {
    unset($fields['order']['order_comments']);
    return $fields;
}
add_filter('woocommerce_checkout_fields', 'flavor_starter_remove_order_notes');

function flavor_starter_hide_cart_notices_on_checkout() {
    if (is_checkout()) {
        remove_action('woocommerce_before_checkout_form', 'woocommerce_output_all_notices', 10);
    }
}
add_action('wp', 'flavor_starter_hide_cart_notices_on_checkout');

/* ==========================================================================
   CART - CUSTOM EMPTY CART MESSAGE
   ========================================================================== */

// Remove WooCommerce's default "Return to shop" button
remove_action('woocommerce_cart_is_empty', 'wc_empty_cart_message', 10);

// Hide cart notices completely on cart page
function flavor_starter_hide_all_cart_notices() {
    if (is_cart()) {
        // Remove all notice display hooks
        remove_action('woocommerce_before_cart', 'woocommerce_output_all_notices', 10);
        
        // Clear any existing notices
        wc_clear_notices();
    }
}
add_action('wp', 'flavor_starter_hide_all_cart_notices', 1);

// Add our custom empty cart design
function flavor_starter_custom_empty_cart_message() {
    ?>
    <div class="sbn-empty-cart-shortcode">
        <div class="empty-cart-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="9" cy="21" r="1"/>
                <circle cx="20" cy="21" r="1"/>
                <path d="m1 1 4 0 2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>
            </svg>
        </div>
        <h2>Your Cart is Empty</h2>
        <p>Start exploring our collection of beautiful sheet music and guitar transcriptions.</p>
        <a href="<?php echo esc_url( get_permalink( wc_get_page_id( 'shop' ) ) ); ?>" class="continue-shopping-btn">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/>
            </svg>
            Continue Shopping
        </a>
    </div>
    <style>
    /* Hide WooCommerce default notices on cart page */
    .woocommerce-cart .woocommerce-message,
    .woocommerce-cart .woocommerce-info,
    .woocommerce-cart .woocommerce-error,
    .woocommerce-cart .woocommerce-notices-wrapper {
        display: none !important;
    }
    
    /* Hide default "Return to shop" button */
    .woocommerce-cart .cart-empty + .return-to-shop,
    .woocommerce-cart .return-to-shop {
        display: none !important;
    }
    
    .sbn-empty-cart-shortcode {
        text-align: center;
        padding: 80px 40px;
        background: white;
        border-radius: 16px;
        max-width: 600px;
        margin: 60px auto;
        box-shadow: 0 4px 12px rgba(0,0,0,0.05);
    }
    .sbn-empty-cart-shortcode .empty-cart-icon {
        width: 120px;
        height: 120px;
        margin: 0 auto 30px;
        background: #f7fafc;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .sbn-empty-cart-shortcode .empty-cart-icon svg {
        width: 60px;
        height: 60px;
        color: #f39c12;
    }
    .sbn-empty-cart-shortcode h2 {
        font-size: 2em;
        color: #2c3e50;
        margin: 0 0 15px 0;
    }
    .sbn-empty-cart-shortcode p {
        color: #5a5a5a;
        font-size: 1.1em;
        line-height: 1.6;
        margin: 0 0 30px 0;
    }
    .sbn-empty-cart-shortcode .continue-shopping-btn {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        background: linear-gradient(135deg, #f39c12 0%, #e74c3c 100%);
        color: white !important;
        padding: 14px 28px;
        border-radius: 12px;
        text-decoration: none;
        font-weight: 600;
        transition: all 0.3s ease;
    }
    .sbn-empty-cart-shortcode .continue-shopping-btn svg {
        width: 20px;
        height: 20px;
    }
    .sbn-empty-cart-shortcode .continue-shopping-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(243, 156, 18, 0.3);
    }
    </style>
    <?php
}
add_action('woocommerce_cart_is_empty', 'flavor_starter_custom_empty_cart_message', 10);

/* ==========================================================================
   CONTENT WIDTH
   ========================================================================== */

function flavor_starter_content_width() {
    $GLOBALS['content_width'] = 1200;
}
add_action('after_setup_theme', 'flavor_starter_content_width', 0);

   require_once get_template_directory() . '/inc/course-archive.php';

/* ==========================================================================
  SONG LIBRARY
   ========================================================================== */
add_action('rest_api_init', function () {
    register_rest_route('sbn/v1', '/secure-vote/', array(
        'methods' => 'POST',
        'callback' => 'sbn_handle_secure_vote',
        'permission_callback' => '__return_true',
    ));
});

function sbn_handle_secure_vote($data) {
    $email = sanitize_email($data['email']);
    $song_id = sanitize_text_field($data['song_id']);

    if (!is_email($email)) {
        return new WP_Error('invalid_email', 'Please provide a valid email.', array('status' => 400));
    }

    // 1. Handle WooCommerce Customer Logic
    if (class_exists('WooCommerce')) {
        $user = get_user_by('email', $email);
        if (!$user) {
            // Create a basic customer profile if they don't exist
            $username = explode('@', $email)[0] . time();
            $random_password = wp_generate_password();
            $user_id = wc_create_new_customer($email, $username, $random_password);
        }
    }

    // 2. Record the Vote
    $votes = get_option('sbn_song_votes', array());
    $votes[$song_id] = isset($votes[$song_id]) ? $votes[$song_id] + 1 : 1;
    update_option('sbn_song_votes', $votes);

    return rest_ensure_response(array('success' => true));
}

/**
 * Force load Rhythm Grid assets for the Frontend Grid Library
 */
add_action('wp_enqueue_scripts', function() {
    // Only load on your specific rhythms library page template
    if ( is_page_template('page-rhythms.php') ) {
        
        // 1. Load the core Layout CSS (Fixes the vertical stacking)
        wp_enqueue_style(
            'sbn-rhythm-patterns-frontend',
            SBN_PLUGIN_URL . 'assets/css/rhythm-patterns-admin.css',
            array(),
            '1.1.0'
        );
        
        // 2. Load Tone.js (Required for the playback logic in your scripts)
        wp_enqueue_script(
            'tone-js',
            'https://cdnjs.cloudflare.com/ajax/libs/tone/14.8.49/Tone.js',
            array(),
            '14.8.49',
            true
        );
        
        // 3. Load the Rhythm Logic Script
        wp_enqueue_script(
            'sbn-rhythm-patterns-js',
            SBN_PLUGIN_URL . 'assets/js/rhythm-patterns-admin.js',
            array('jquery', 'tone-js'), // Dependencies
            '1.1.0',
            true
        );
        
        // 4. Pass security and data to the script
        wp_localize_script('sbn-rhythm-patterns-js', 'sbnRhythm', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('sbn_rhythm_patterns_nonce'),
            'isLibrary' => true // Custom flag to tell the script it's in the grid view
        ));
    }
}, 20);