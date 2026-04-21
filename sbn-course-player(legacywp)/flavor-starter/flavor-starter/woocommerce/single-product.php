<?php
/**
 * Single Product Template
 */

defined( 'ABSPATH' ) || exit;

get_header();
do_action( 'woocommerce_before_main_content' );

while ( have_posts() ) :
    the_post();
    
    global $product;
    if ( ! is_a( $product, 'WC_Product' ) ) $product = wc_get_product( get_the_ID() );
    if ( ! $product ) continue;

    $style_categories = array(
        'bossa-nova' => array( 'name' => 'Bossa Nova', 'color' => '#f39c12' ),
        'jazz' => array( 'name' => 'Jazz', 'color' => '#3498db' ),
        'classical' => array( 'name' => 'Classical', 'color' => '#9b59b6' ),
    );
    $sub_categories = array( 'solo-guitar', 'chords', 'transcription' );
    $difficulty_levels = array( 'basic' => 1, 'early-intermediate' => 2, 'intermediate' => 3, 'late-intermediate' => 4, 'advanced' => 5 );
    
    $prod_style = array( 'name' => 'Shop', 'slug' => 'shop', 'color' => '#f39c12' );
    $prod_subcats = array();
    $prod_difficulty = null;
    
    $terms = get_the_terms( get_the_ID(), 'product_cat' );
    if ( $terms && ! is_wp_error( $terms ) ) {
        foreach ( $terms as $term ) {
            if ( isset( $style_categories[ $term->slug ] ) ) {
                $prod_style = array( 'name' => $style_categories[ $term->slug ]['name'], 'slug' => $term->slug, 'color' => $style_categories[ $term->slug ]['color'] );
            }
            if ( in_array( $term->slug, $sub_categories ) ) {
                $prod_subcats[] = array( 'name' => $term->name, 'slug' => $term->slug );
            }
            if ( isset( $difficulty_levels[ $term->slug ] ) ) {
                $prod_difficulty = array( 'name' => $term->name, 'slug' => $term->slug, 'stars' => $difficulty_levels[ $term->slug ] );
            }
        }
    }
    
    $category_color = $prod_style['color'];
    $main_image_id = $product->get_image_id();
    $attachment_ids = $product->get_gallery_image_ids();
    
    // Extract video from description
    $description = $product->get_description();
    $video_embed = '';
    
    // Match YouTube iframes
    if ( preg_match( '/<iframe[^>]+src=["\']([^"\']*youtube[^"\']*)["\'][^>]*>.*?<\/iframe>/is', $description, $matches ) ) {
        $video_embed = $matches[0];
    } elseif ( preg_match( '/<iframe[^>]+src=["\']([^"\']*vimeo[^"\']*)["\'][^>]*>.*?<\/iframe>/is', $description, $matches ) ) {
        $video_embed = $matches[0];
    }
?>

<div class="sbn-single-product" style="--category-color: <?php echo esc_attr( $category_color ); ?>; --category-gradient: <?php echo isset($style_categories[$prod_style['slug']]) ? 'linear-gradient(135deg, ' . $style_categories[$prod_style['slug']]['color'] . ' 0%, ' . $style_categories[$prod_style['slug']]['color'] . ' 100%)' : 'linear-gradient(135deg, #f39c12 0%, #e74c3c 100%)'; ?>;">
    <nav class="sbn-breadcrumb">
        <ul>
            <li><a href="<?php echo esc_url( get_permalink( wc_get_page_id( 'shop' ) ) ); ?>">Shop</a></li>
            <?php if ( $prod_style['slug'] !== 'shop' ) : ?>
            <li><a href="<?php echo esc_url( get_term_link( $prod_style['slug'], 'product_cat' ) ); ?>"><?php echo esc_html( $prod_style['name'] ); ?></a></li>
            <?php endif; ?>
            <li><?php the_title(); ?></li>
        </ul>
    </nav>
    
    <div class="sbn-product-main">
        
        <div class="sbn-product-gallery">
            <div class="sbn-gallery-main">
                <div class="sbn-media-container">
                    <!-- Main Product Image -->
                    <div class="sbn-main-image<?php echo $video_embed ? '' : ' active'; ?>" id="sbn-main-image">
                        <?php echo $main_image_id ? wp_get_attachment_image( $main_image_id, 'large' ) : wc_placeholder_img( 'large' ); ?>
                    </div>
                    
                    <?php if ( $video_embed ) : ?>
                    <!-- Video Container -->
                    <div class="sbn-video-container" id="sbn-video-container">
                        <?php echo $video_embed; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <?php if ( $video_embed ) : ?>
            <!-- Image/Video Tabs -->
            <div class="sbn-media-tabs">
                <button class="sbn-media-tab active" data-target="image">Image</button>
                <button class="sbn-media-tab" data-target="video">Video</button>
            </div>
            <?php endif; ?>
        </div>
        
        <div class="sbn-product-info">
            
            <?php if ( $prod_difficulty ) : ?>
            <div class="sbn-difficulty-badge">
                <span><?php echo esc_html( $prod_difficulty['name'] ); ?></span>
                <span class="stars"><?php echo str_repeat( '★', $prod_difficulty['stars'] ) . str_repeat( '☆', 5 - $prod_difficulty['stars'] ); ?></span>
            </div>
            <?php endif; ?>
            
            <h1 class="sbn-product-title"><?php the_title(); ?></h1>
            
            <div class="sbn-product-subtitle">
                <?php if ( $prod_style['slug'] !== 'shop' ) : ?>
                <a href="<?php echo esc_url( get_term_link( $prod_style['slug'], 'product_cat' ) ); ?>" class="sbn-style-link"><?php echo esc_html( $prod_style['name'] ); ?></a>
                <?php endif; ?>
                <?php foreach ( $prod_subcats as $subcat ) : ?>
                <a href="<?php echo esc_url( get_term_link( $subcat['slug'], 'product_cat' ) ); ?>" class="sbn-subcat-link"><?php echo esc_html( $subcat['name'] ); ?></a>
                <?php endforeach; ?>
            </div>
            
            <div class="sbn-price-wrapper">
                <?php if ( $product->is_on_sale() && $product->get_regular_price() ) : ?>
                    <span class="sbn-price-current"><?php echo wc_price( $product->get_sale_price() ); ?></span>
                    <span class="sbn-price-original"><?php echo wc_price( $product->get_regular_price() ); ?></span>
                    <?php $regular = floatval( $product->get_regular_price() ); $sale = floatval( $product->get_sale_price() ); if ( $regular > 0 ) echo '<span class="sbn-price-badge">' . round( ( ( $regular - $sale ) / $regular ) * 100 ) . '% OFF</span>'; ?>
                <?php else : ?>
                    <span class="sbn-price-current"><?php echo $product->get_price_html(); ?></span>
                <?php endif; ?>
            </div>
            
            <?php if ( $product->get_short_description() ) : ?>
            <div class="sbn-product-description"><?php echo wpautop( $product->get_short_description() ); ?></div>
            <?php endif; ?>
            
            <?php
            // Parse product features from attributes
            $features = array();
            
            // PDF with pages
            $pages = $product->get_attribute( 'pages' );
            if ( $pages ) {
                $features[] = array(
                    'icon' => '<svg viewBox="0 0 24 24"><path d="M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6zm4 18H6V4h7v5h5v11z"/><path d="M8 16h8v2H8zm0-4h8v2H8zm0-4h5v2H8z"/></svg>',
                    'text' => 'PDF, ' . esc_html( $pages ) . ' pages'
                );
            }
            
            // Get notation types from attribute (can be multiple, comma-separated)
            $notation_types = $product->get_attribute( 'notation' );
            if ( $notation_types ) {
                $types = array_map( 'trim', explode( ',', strtolower( $notation_types ) ) );
                
                foreach ( $types as $type ) {
                    if ( strpos( $type, 'standard' ) !== false ) {
                        $features[] = array(
                            'icon' => '<svg viewBox="0 0 24 24"><path d="M12 3v10.55c-.59-.34-1.27-.55-2-.55-2.21 0-4 1.79-4 4s1.79 4 4 4 4-1.79 4-4V7h4V3h-6z"/></svg>',
                            'text' => 'Standard Notation'
                        );
                    } elseif ( strpos( $type, 'chord' ) !== false ) {
                        $features[] = array(
                            'icon' => '<svg viewBox="0 0 24 24"><path d="M4 6h16v2H4zm0 5h16v2H4zm0 5h16v2H4z"/><circle cx="7" cy="9" r="1.5"/><circle cx="12" cy="14" r="1.5"/><circle cx="17" cy="9" r="1.5"/></svg>',
                            'text' => 'Chord Grids'
                        );
                    } elseif ( strpos( $type, 'tab' ) !== false ) {
                        $features[] = array(
                            'icon' => '<svg viewBox="0 0 24 24"><path d="M20 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zM4 18V6h16v12H4z"/><path d="M6 8h12v1.5H6zm0 3h12v1.5H6zm0 3h12v1.5H6z"/><text x="7" y="10" font-size="3" fill="currentColor">T</text><text x="7" y="13" font-size="3" fill="currentColor">A</text><text x="7" y="16" font-size="3" fill="currentColor">B</text></svg>',
                            'text' => 'Tablature'
                        );
                    }
                }
            }
            ?>
            
            <?php if ( ! empty( $features ) ) : ?>
            <div class="sbn-features-grid">
                <?php foreach ( $features as $feature ) : ?>
                <div class="sbn-feature-item">
                    <span class="sbn-feature-icon"><?php echo $feature['icon']; ?></span>
                    <span><?php echo $feature['text']; ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            
            <div class="sbn-product-meta">
                <?php if ( $pages = $product->get_attribute( 'pages' ) ) : ?>
                <div class="sbn-meta-item"><span class="sbn-meta-label">Pages:</span><span class="sbn-meta-value"><?php echo esc_html( $pages ); ?></span></div>
                <?php endif; ?>
                <div class="sbn-meta-item"><span class="sbn-meta-label">Format:</span><span class="sbn-meta-value"><?php echo $product->get_attribute( 'format' ) ?: 'PDF'; ?></span></div>
                <?php if ( $prod_difficulty ) : ?>
                <div class="sbn-meta-item"><span class="sbn-meta-label">Level:</span><span class="sbn-meta-value"><?php echo esc_html( $prod_difficulty['name'] ); ?></span></div>
                <?php endif; ?>
            </div>
            
            <?php if ( $product->is_purchasable() && $product->is_in_stock() ) : ?>
            <form action="<?php echo esc_url( $product->get_permalink() ); ?>" method="post" enctype="multipart/form-data" class="cart" data-product_id="<?php echo absint( $product->get_id() ); ?>" data-product_variations="<?php echo $product->is_type('variable') ? 'true' : 'false'; ?>">
                <?php 
                // Add nonce for security (required by Stripe)
                wp_nonce_field( 'woocommerce-cart' );
                
                do_action( 'woocommerce_before_add_to_cart_button' ); 
                ?>
                
                <!-- Hidden quantity field (always 1 for digital products) -->
                <input type="hidden" name="quantity" value="1">
                
                <button type="submit" name="add-to-cart" value="<?php echo esc_attr( $product->get_id() ); ?>" class="single_add_to_cart_button button alt sbn-add-to-cart-btn">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="m1 1 4 0 2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
                    Add to Cart
                </button>
                
                <?php do_action( 'woocommerce_after_add_to_cart_button' ); ?>
                
                <!-- Explicit containers for payment buttons -->
                <div class="sbn-payment-buttons-section" style="margin-top: 20px;">
                    
                    <!-- NEW Stripe Express Checkout (v8.0+) -->
                    <div class="wc-stripe-express-checkout-element"></div>
                    <div class="wcpay-payment-request-wrapper"></div>
                    
                    <!-- OLD Stripe Payment Request (legacy support) -->
                    <div id="wc-stripe-payment-request-wrapper" class="wc-stripe-payment-request-button-wrapper"></div>
                    
                    <!-- Separator -->
                    <div id="payment-separator" style="text-align: center; margin: 15px 0; color: #999; font-size: 0.9em; font-weight: 600; display: none;">OR</div>
                    
                    <!-- PayPal Smart Buttons -->
                    <div id="ppc-button-ppcp-gateway" class="ppc-button-wrapper"></div>
                </div>
                
            </form>
            
            <?php elseif ( ! $product->is_in_stock() ) : ?>
            <p style="color: #c53030; font-weight: 600; padding: 15px; background: #fed7d7; border-radius: 8px;">Out of stock</p>
            <?php endif; ?>
            
            <?php if ( $product->get_sku() ) : ?>
            <p class="sbn-product-sku">SKU: <?php echo esc_html( $product->get_sku() ); ?></p>
            <?php endif; ?>
            <p class="sbn-product-categories">Categories: <?php echo wc_get_product_category_list( $product->get_id(), ', ' ); ?></p>
        </div>
    </div>
    
    <?php 
    // Smart Related Products Algorithm
    // Priority: Same main category (bossa/jazz/classical) + Similar difficulty
    $related_ids = array();
    
    if ($product) {
        // Get current product's main category and difficulty
        $current_style_slug = $prod_style['slug'];
        $current_difficulty_stars = $prod_difficulty ? $prod_difficulty['stars'] : null;
        
        $args = array(
            'post_type' => 'product',
            'posts_per_page' => 12, // Get more to filter
            'post__not_in' => array($product->get_id()),
            'orderby' => 'rand',
            'post_status' => 'publish'
        );
        
        // If we have a main style category, prioritize same category
        if ($current_style_slug && $current_style_slug !== 'shop') {
            $args['tax_query'] = array(
                array(
                    'taxonomy' => 'product_cat',
                    'field' => 'slug',
                    'terms' => $current_style_slug
                )
            );
        }
        
        $related_query = new WP_Query($args);
        $candidates = array();
        
        if ($related_query->have_posts()) {
            while ($related_query->have_posts()) {
                $related_query->the_post();
                $candidate_id = get_the_ID();
                
                // Calculate relevance score
                $score = 0;
                
                // Check if same main category (already filtered in query if applicable)
                if ($current_style_slug && $current_style_slug !== 'shop') {
                    $score += 10; // Base score for same category
                }
                
                // Check difficulty match
                if ($current_difficulty_stars) {
                    $candidate_terms = get_the_terms($candidate_id, 'product_cat');
                    if ($candidate_terms && !is_wp_error($candidate_terms)) {
                        foreach ($candidate_terms as $term) {
                            if (isset($difficulty_levels[$term->slug])) {
                                $candidate_difficulty = $difficulty_levels[$term->slug];
                                // Exact match = +5, 1 level off = +3, 2 levels = +1
                                $diff = abs($candidate_difficulty - $current_difficulty_stars);
                                if ($diff == 0) $score += 5;
                                elseif ($diff == 1) $score += 3;
                                elseif ($diff == 2) $score += 1;
                                break;
                            }
                        }
                    }
                }
                
                $candidates[$candidate_id] = $score;
            }
            wp_reset_postdata();
            
            // Sort by score and take top 4
            arsort($candidates);
            $related_ids = array_slice(array_keys($candidates), 0, 4);
        }
    }
    
    if ( ! empty( $related_ids ) ) : ?>
    <div class="sbn-related-section">
        <div class="sbn-related-header">
            <h2>Related Products</h2>
            <?php if ( $prod_style['slug'] !== 'shop' ) : ?>
            <a href="<?php echo esc_url( get_term_link( $prod_style['slug'], 'product_cat' ) ); ?>">View All →</a>
            <?php endif; ?>
        </div>
        <div class="sbn-related-grid">
            <?php foreach ( $related_ids as $related_id ) : 
                $related = wc_get_product( $related_id ); 
                if ( ! $related ) continue;
                
                // Get category info for related product
                $rel_style = array( 'name' => 'Shop', 'slug' => 'shop', 'color' => '#f39c12' );
                $rel_subcats = array();
                $rel_difficulty = null;
                
                $rel_terms = get_the_terms( $related_id, 'product_cat' );
                if ( $rel_terms && ! is_wp_error( $rel_terms ) ) {
                    foreach ( $rel_terms as $rel_term ) {
                        if ( isset( $style_categories[ $rel_term->slug ] ) ) {
                            $rel_style = array( 
                                'name' => $style_categories[ $rel_term->slug ]['name'], 
                                'slug' => $rel_term->slug, 
                                'color' => $style_categories[ $rel_term->slug ]['color']
                            );
                        }
                        if ( in_array( $rel_term->slug, $sub_categories ) ) {
                            $rel_subcats[] = array( 'name' => $rel_term->name, 'slug' => $rel_term->slug );
                        }
                        if ( isset( $difficulty_levels[ $rel_term->slug ] ) ) {
                            $rel_difficulty = array( 'name' => $rel_term->name, 'slug' => $rel_term->slug, 'stars' => $difficulty_levels[ $rel_term->slug ] );
                        }
                    }
                }
            ?>
            <div class="sbn-related-product">
                <div class="sbn-related-image">
                    <a href="<?php echo esc_url( get_permalink( $related_id ) ); ?>">
                        <?php echo $related->get_image( 'sbn-product-card' ); ?>
                    </a>
                    <div class="sbn-related-badge-row">
                        <span class="sbn-related-badge-style"><?php echo esc_html( $rel_style['name'] ); ?></span>
                        <?php if ( $rel_difficulty ) : ?>
                        <div class="sbn-related-difficulty" title="<?php echo esc_attr( $rel_difficulty['name'] ); ?>">
                            <?php for ( $i = 1; $i <= 5; $i++ ) echo $i <= $rel_difficulty['stars'] ? '<span class="star-filled">★</span>' : '<span class="star-empty">★</span>'; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php if ( ! empty( $rel_subcats ) ) : ?>
                    <div class="sbn-related-badge-type">
                        <?php foreach ( $rel_subcats as $sc ) : ?>
                        <span class="sbn-related-badge-subcat"><?php echo esc_html( $sc['name'] ); ?></span>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    <div class="sbn-related-overlay">
                        <a href="<?php echo esc_url( get_permalink( $related_id ) ); ?>" class="sbn-related-view-btn">View Details →</a>
                        <a href="#" 
                           data-quantity="1" 
                           data-product_id="<?php echo esc_attr( $related_id ); ?>" 
                           class="sbn-related-quick-add">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="m1 1 4 0 2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
                            Add to Cart
                        </a>
                        <?php if ( $related->get_short_description() ) : ?>
                        <p class="sbn-related-excerpt"><?php echo wp_trim_words( $related->get_short_description(), 10, '...' ); ?></p>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="sbn-related-info">
                    <a href="<?php echo esc_url( get_permalink( $related_id ) ); ?>" class="sbn-related-title"><?php echo esc_html( $related->get_name() ); ?></a>
                    <div class="sbn-related-price"><?php echo $related->get_price_html(); ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
jQuery(function($) {
    var cartIcon = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="m1 1 4 0 2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>';
    var cartIconLarge = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="m1 1 4 0 2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>';
    var arrowIcon = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M12 5l7 7-7 7"/></svg>';
    var arrowIconLarge = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M12 5l7 7-7 7"/></svg>';
    var cartUrl = '<?php echo wc_get_cart_url(); ?>';
    
    // Main Add to Cart Button - intercept form submit and morph button
    $('.sbn-add-to-cart-btn').closest('form.cart').on('submit', function(e) {
        e.preventDefault();
        var $form = $(this);
        var $btn = $form.find('.sbn-add-to-cart-btn');
        var productId = $btn.val();
        
        // State 1: Loading
        $btn.addClass('loading').html('Adding...');
        
        $.ajax({
            url: wc_add_to_cart_params.ajax_url,
            type: 'POST',
            data: {
                action: 'woocommerce_add_to_cart',
                product_id: productId,
                quantity: 1
            },
            success: function(response) {
                $(document.body).trigger('added_to_cart', [response.fragments, response.cart_hash, $btn]);
                
                // State 2: Added (show briefly)
                $btn.removeClass('loading').addClass('added').html('Added ✓');
                
                // State 3: Transform to View Cart after 1 second (CSS arrow will appear on hover)
                setTimeout(function() {
                    $btn.removeClass('added')
                        .addClass('view-cart-mode')
                        .html('View Cart')
                        .attr('onclick', 'window.location.href="' + cartUrl + '"; return false;');
                }, 1000);
            }
        });
    });
    
    // Handle click on View Cart mode
    $(document).on('click', '.sbn-add-to-cart-btn.view-cart-mode', function(e) {
        e.preventDefault();
        window.location.href = cartUrl;
    });
    
    // Related Products - Prevent redirect
    $(document).on('click', '.sbn-related-quick-add', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var productId = $btn.data('product_id');
        
        $btn.addClass('loading').html('Adding...');
        
        $.ajax({
            url: wc_add_to_cart_params.ajax_url,
            type: 'POST',
            data: {
                action: 'woocommerce_add_to_cart',
                product_id: productId,
                quantity: 1
            },
            success: function(response) {
                $(document.body).trigger('added_to_cart', [response.fragments, response.cart_hash, $btn]);
            }
        });
    });
    
    // After add to cart - show Added + View Cart
    $(document.body).on('added_to_cart', function(e, fragments, hash, $btn) {
        // Animate cart icon
        $('.menu-item-cart .cart-icon-link').addClass('cart-bounce');
        setTimeout(function() { $('.menu-item-cart .cart-icon-link').removeClass('cart-bounce'); }, 600);
        
        // Update related product buttons
        if ($btn && $btn.hasClass('sbn-related-quick-add')) {
            $btn.removeClass('loading').addClass('added').html('Added ✓');
            
            // Add View Cart button if not exists
            if (!$btn.next('.sbn-related-view-cart').length) {
                $btn.after('<a href="' + cartUrl + '" class="sbn-related-view-cart">View Cart ' + arrowIcon + '</a>');
            }
        }
    });
    
    // Media tabs (Image/Video)
    $('.sbn-media-tab').on('click', function() {
        $('.sbn-media-tab').removeClass('active');
        $(this).addClass('active');
        
        if ($(this).data('target') === 'video') {
            $('#sbn-main-image').addClass('hidden');
            $('#sbn-video-container').addClass('active');
        } else {
            $('#sbn-video-container').removeClass('active');
            $('#sbn-main-image').removeClass('hidden');
        }
    });
});
</script>

<?php
endwhile;
do_action( 'woocommerce_after_main_content' );
get_footer();
?>
