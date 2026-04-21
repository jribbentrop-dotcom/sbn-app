<?php
/**
 * The Template for displaying product archives
 */

defined( 'ABSPATH' ) || exit;

// Define as TRUE globals
global $sbn_style_categories, $sbn_sub_categories, $sbn_difficulty_levels;

$sbn_style_categories = array(
    'bossa-nova' => array( 'name' => 'Bossa Nova', 'color' => '#f39c12', 'gradient' => 'linear-gradient(135deg, #f39c12 0%, #e74c3c 100%)' ),
    'jazz' => array( 'name' => 'Jazz', 'color' => '#3498db', 'gradient' => 'linear-gradient(135deg, #3498db 0%, #1a5490 100%)' ),
    'classical' => array( 'name' => 'Classical', 'color' => '#9b59b6', 'gradient' => 'linear-gradient(135deg, #9b59b6 0%, #6c3483 100%)' ),
    'all-sheet-music' => array( 'name' => 'All Sheet Music', 'color' => '#2c3e50', 'gradient' => 'linear-gradient(135deg, #2c3e50 0%, #34495e 100%)' ),
);

$sbn_sub_categories = array( 'solo-guitar', 'chords', 'transcription' );

$sbn_difficulty_levels = array(
    'basic' => 1,
    'early-intermediate' => 2,
    'intermediate' => 3,
    'late-intermediate' => 4,
    'advanced' => 5,
);

if (!function_exists('sbn_get_product_style')) {
    function sbn_get_product_style($product_id) {
        global $sbn_style_categories;
        $default = array( 'name' => 'Shop', 'slug' => 'shop', 'color' => '#f39c12', 'gradient' => '#f39c12' );
        $terms = get_the_terms($product_id, 'product_cat');
        if ($terms && !is_wp_error($terms)) {
            foreach ($terms as $term) {
                if (isset($sbn_style_categories[$term->slug])) {
                    return array(
                        'name' => $sbn_style_categories[$term->slug]['name'],
                        'slug' => $term->slug,
                        'color' => $sbn_style_categories[$term->slug]['color'],
                        'gradient' => $sbn_style_categories[$term->slug]['gradient']
                    );
                }
            }
        }
        return $default;
    }
}

if (!function_exists('sbn_get_product_subcategories')) {
    function sbn_get_product_subcategories($product_id) {
        global $sbn_sub_categories;
        $found = array();
        $terms = get_the_terms($product_id, 'product_cat');
        if ($terms && !is_wp_error($terms)) {
            foreach ($terms as $term) {
                if (in_array($term->slug, $sbn_sub_categories)) {
                    $found[] = array( 'name' => $term->name, 'slug' => $term->slug );
                }
            }
        }
        return $found;
    }
}

if (!function_exists('sbn_get_product_difficulty')) {
    function sbn_get_product_difficulty($product_id) {
        global $sbn_difficulty_levels;
        $terms = get_the_terms($product_id, 'product_cat');
        if ($terms && !is_wp_error($terms)) {
            foreach ($terms as $term) {
                if (isset($sbn_difficulty_levels[$term->slug])) {
                    return array( 'name' => $term->name, 'slug' => $term->slug, 'stars' => $sbn_difficulty_levels[$term->slug] );
                }
            }
        }
        return null;
    }
}

if (!function_exists('sbn_is_filtered')) {
    function sbn_is_filtered() {
        return isset($_GET['filter_cat']) || isset($_GET['min_price']) || isset($_GET['max_price']) || isset($_GET['orderby']) || isset($_GET['s']) || is_product_category() || is_product_tag();
    }
}

if (!function_exists('sbn_get_current_category_info')) {
    function sbn_get_current_category_info() {
        global $sbn_style_categories;
        $default = array( 'name' => 'Shop', 'slug' => 'shop', 'color' => '#f39c12', 'gradient' => '#f39c12' );
        if (is_product_category()) {
            $term = get_queried_object();
            if ($term && isset($sbn_style_categories[$term->slug])) {
                return array( 'name' => $term->name, 'slug' => $term->slug, 'color' => $sbn_style_categories[$term->slug]['color'], 'gradient' => $sbn_style_categories[$term->slug]['gradient'] );
            } elseif ($term) {
                return array( 'name' => $term->name, 'slug' => $term->slug, 'color' => $default['color'], 'gradient' => $default['gradient'] );
            }
        }
        return $default;
    }
}

if (!function_exists('sbn_get_random_featured_product')) {
    function sbn_get_random_featured_product($category_slug) {
        // Get most popular product (by total sales) from category
        $args = array( 
            'post_type' => 'product', 
            'posts_per_page' => 1, 
            'post_status' => 'publish', 
            'orderby' => 'meta_value_num', 
            'meta_key' => 'total_sales',
            'order' => 'DESC',
            'tax_query' => array( 
                array( 
                    'taxonomy' => 'product_cat', 
                    'field' => 'slug', 
                    'terms' => $category_slug 
                ) 
            ) 
        );
        $products = new WP_Query($args);
        if ($products->have_posts()) {
            $products->the_post();
            $product_id = get_the_ID();
            wp_reset_postdata();
            return wc_get_product($product_id);
        }
        return null;
    }
}

get_header(); ?>
<div class="custom-shop-wrapper">
    <div class="shop-layout">
        
        <?php if (is_shop() && !is_search() && !sbn_is_filtered()): 
            // Get most popular product from bossa-nova category for hero
            $featured_product = sbn_get_random_featured_product('bossa-nova');
            $bg_image = get_stylesheet_directory_uri() . '/images/joaobg.webp';
        ?>
        <div class="shop-hero-section" style="background-image: url(<?php echo esc_url($bg_image); ?>);">
            <?php if ($featured_product): ?>
            <div class="hero-product">
                <div class="hero-product-image"><?php echo $featured_product->get_image('sbn-featured-hero'); ?></div>
                <div class="hero-product-info">
                    <span class="featured-badge">⭐ Featured Transcription</span>
                    <h2><?php echo $featured_product->get_name(); ?></h2>
                    <p><?php echo wp_trim_words($featured_product->get_short_description() ?: $featured_product->get_description(), 30); ?></p>
                    <div class="hero-product-price"><?php echo $featured_product->get_price_html(); ?></div>
                    <a href="<?php echo $featured_product->get_permalink(); ?>" class="hero-cta-button">View Transcription →</a>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <?php elseif (is_search()): global $wp_query; $total = $wp_query->found_posts; ?>
        <div class="search-results-header">
            <div class="category-header-top">
                <div class="category-header-info"><h1>Search Results for: <span class="search-term"><?php echo esc_html(get_search_query()); ?></span></h1></div>
                <div class="category-header-controls"><span class="results-count"><?php echo $total; ?> products found</span></div>
            </div>
        </div>

        <?php elseif (is_product_category()): 
            $current_cat = sbn_get_current_category_info();
            $term = get_queried_object();
            global $wp_query;
            $total = $wp_query->found_posts;
            $featured_product = sbn_get_random_featured_product($term->slug);
            
            // High-quality background images for all categories
            $bg_images = array(
                // Main style categories
                'bossa-nova' => get_stylesheet_directory_uri() . '/images/bossa-nova-bg.webp',
                'jazz' => get_stylesheet_directory_uri() . '/images/jazz-bg.webp',
                'classical' => get_stylesheet_directory_uri() . '/images/classical-bg.webp',
                'all-sheet-music' => get_stylesheet_directory_uri() . '/images/all-sheet-music-bg.webp',
                
                // Sub categories (types)
                'solo-guitar' => get_stylesheet_directory_uri() . '/images/solo-guitar-bg.webp',
                'chords' => get_stylesheet_directory_uri() . '/images/chords-bg.webp',
                'transcription' => get_stylesheet_directory_uri() . '/images/transcription-bg.webp',
                
                // Difficulty levels
                'basic' => get_stylesheet_directory_uri() . '/images/basic-bg.webp',
                'early-intermediate' => get_stylesheet_directory_uri() . '/images/early-intermediate-bg.webp',
                'intermediate' => get_stylesheet_directory_uri() . '/images/intermediate-bg.webp',
                'late-intermediate' => get_stylesheet_directory_uri() . '/images/late-intermediate-bg.webp',
                'advanced' => get_stylesheet_directory_uri() . '/images/advanced-bg.webp',
            );
            // Use background image if available, otherwise show gradient overlay
            $bg_style = isset($bg_images[$current_cat['slug']]) ? 'background-image: url(' . esc_url($bg_images[$current_cat['slug']]) . ');' : '';
        ?>
        <div class="category-page-header" style="<?php echo $bg_style; ?> --category-gradient: <?php echo esc_attr($current_cat['gradient']); ?>;">
            <div class="category-header-top">
                <div class="category-header-info">
                    <h1><?php echo esc_html($current_cat['name']); ?></h1>
                    <?php if ($term && $term->description): ?><p style="opacity: 0.9; margin: 0;"><?php echo esc_html($term->description); ?></p><?php endif; ?>
                </div>
                <!-- Sorting removed for cleaner design -->
            </div>
            <?php if ($featured_product): ?>
            <div class="category-featured-product">
                <div class="category-featured-image"><?php echo $featured_product->get_image('sbn-product-medium'); ?></div>
                <div class="category-featured-info">
                    <span class="featured-label">⭐ Featured</span>
                    <h3><?php echo $featured_product->get_name(); ?></h3>
                    <div class="featured-price"><?php echo $featured_product->get_price_html(); ?></div>
                    <a href="<?php echo $featured_product->get_permalink(); ?>" class="featured-cta" style="color: <?php echo esc_attr($current_cat['color']); ?> !important;">View Details →</a>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <aside class="shop-sidebar">
            <div class="sidebar-widget">
                <h3>Genre</h3>
                <ul><?php global $sbn_style_categories; foreach ($sbn_style_categories as $slug => $data) { $term = get_term_by('slug', $slug, 'product_cat'); if ($term && !is_wp_error($term)) { $cc = is_product_category($slug) ? ' class="current-cat"' : ''; echo '<li'.$cc.' style="--genre-gradient: '.esc_attr($data['gradient']).'"><a href="'.get_term_link($term).'"><span class="genre-badge">'.esc_html($data['name']).'</span><span class="count">'.$term->count.'</span></a></li>'; } } ?></ul>
            </div>
            <div class="sidebar-widget">
                <h3>Type</h3>
                <ul><?php global $sbn_sub_categories; foreach ($sbn_sub_categories as $slug) { $term = get_term_by('slug', $slug, 'product_cat'); if ($term && !is_wp_error($term)) { $cc = is_product_category($slug) ? ' class="current-cat"' : ''; echo '<li'.$cc.'><a href="'.get_term_link($term).'">'.esc_html($term->name).'<span class="count">'.$term->count.'</span></a></li>'; } } ?></ul>
            </div>
            <div class="sidebar-widget">
                <h3>Difficulty</h3>
                <ul><?php global $sbn_difficulty_levels; foreach ($sbn_difficulty_levels as $slug => $stars) { $term = get_term_by('slug', $slug, 'product_cat'); if ($term && !is_wp_error($term)) { $cc = is_product_category($slug) ? ' class="current-cat"' : ''; $sh = str_repeat('★', $stars) . str_repeat('☆', 5 - $stars); echo '<li'.$cc.'><a href="'.get_term_link($term).'"><span class="difficulty-name">'.esc_html($term->name).'<br><span style="color:#ffc107;font-size:0.9em;">'.$sh.'</span></span><span class="count">'.$term->count.'</span></a></li>'; } } ?></ul>
            </div>
        </aside>

        <main class="shop-main-content">
            <?php if (is_shop() && !is_search() && !sbn_is_filtered()): 
                global $sbn_style_categories;
                foreach ($sbn_style_categories as $slug => $data):
                    $term = get_term_by('slug', $slug, 'product_cat');
                    if (!$term || is_wp_error($term)) continue;
                    $products = new WP_Query(array('post_type'=>'product','posts_per_page'=>8,'post_status'=>'publish','tax_query'=>array(array('taxonomy'=>'product_cat','field'=>'slug','terms'=>$slug))));
                    if (!$products->have_posts()) continue;
            ?>
                <div class="carousel-section">
                    <div class="carousel-header" style="background: <?php echo esc_attr($data['gradient']); ?>;">
                        <h3><?php echo esc_html($data['name']); ?></h3>
                        <div class="carousel-controls">
                            <span class="carousel-arrow carousel-prev" data-carousel="<?php echo esc_attr($slug); ?>">‹</span>
                            <span class="carousel-arrow carousel-next" data-carousel="<?php echo esc_attr($slug); ?>">›</span>
                            <a href="<?php echo get_term_link($term); ?>" class="view-all-link">View All →</a>
                        </div>
                    </div>
                    <div class="carousel-container" data-carousel="<?php echo esc_attr($slug); ?>">
                        <div class="carousel-track">
                            <?php while ($products->have_posts()): $products->the_post(); global $product; $pid = get_the_ID(); $ps = sbn_get_product_style($pid); $psc = sbn_get_product_subcategories($pid); $pd = sbn_get_product_difficulty($pid); ?>
                            <div class="product-item" style="--category-color: <?php echo esc_attr($ps['color']); ?>; --category-gradient: <?php echo esc_attr($ps['gradient']); ?>">
                                <div class="product-image">
                                    <a href="<?php the_permalink(); ?>"><?php echo has_post_thumbnail() ? get_the_post_thumbnail($pid, 'sbn-product-card') : wc_placeholder_img('sbn-product-card'); ?></a>
                                    <div class="product-badge-row">
                                        <span class="product-badge-style"><?php echo esc_html($ps['name']); ?></span>
                                        <?php if ($pd): ?><div class="product-difficulty-stars" title="<?php echo esc_attr($pd['name']); ?>"><?php for ($i=1;$i<=5;$i++) echo $i<=$pd['stars']?'<span class="star-filled">★</span>':'<span class="star-empty">★</span>'; ?></div><?php endif; ?>
                                    </div>
                                    <?php if (!empty($psc)): ?><div class="product-badge-type"><?php foreach ($psc as $sc): ?><span class="product-badge-subcat"><?php echo esc_html($sc['name']); ?></span><?php endforeach; ?></div><?php endif; ?>
                                    <div class="product-description">
                                        <a href="<?php the_permalink(); ?>" class="product-view-button">View Details →</a>
                                        <a href="#" data-quantity="1" data-product_id="<?php echo esc_attr($pid); ?>" class="sbn-quick-add"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="m1 1 4 0 2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg> Add to Cart</a>
                                        <?php if ($product->get_short_description()): ?>
                                        <p class="product-excerpt"><?php echo wp_trim_words($product->get_short_description(), 10, '...'); ?></p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="product-title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a><span class="product-price"><?php echo $product->get_price_html(); ?></span></div>
                            </div>
                            <?php endwhile; ?>
                        </div>
                    </div>
                </div>
            <?php wp_reset_postdata(); endforeach;
            
            // All Products section
            $all_products = new WP_Query(array('post_type'=>'product','posts_per_page'=>12,'post_status'=>'publish','orderby'=>'date','order'=>'DESC'));
            if ($all_products->have_posts()):
            ?>
                <div class="carousel-section">
                    <div class="carousel-header" style="background: linear-gradient(135deg, #e0e0e0 0%, #bdbdbd 100%);">
                        <h3 style="color: #2d3748;">All Products</h3>
                        <div class="carousel-controls">
                            <span class="carousel-arrow carousel-prev" data-carousel="all-products" style="color: #2d3748;">‹</span>
                            <span class="carousel-arrow carousel-next" data-carousel="all-products" style="color: #2d3748;">›</span>
                            <a href="<?php echo get_permalink(wc_get_page_id('shop')); ?>" class="view-all-link" style="color: #2d3748;">View All →</a>
                        </div>
                    </div>
                    <div class="carousel-container" data-carousel="all-products">
                        <div class="carousel-track">
                            <?php while ($all_products->have_posts()): $all_products->the_post(); global $product; $pid = get_the_ID(); $ps = sbn_get_product_style($pid); $psc = sbn_get_product_subcategories($pid); $pd = sbn_get_product_difficulty($pid); ?>
                            <div class="product-item" style="--category-color: <?php echo esc_attr($ps['color']); ?>; --category-gradient: <?php echo esc_attr($ps['gradient']); ?>">
                                <div class="product-image">
                                    <a href="<?php the_permalink(); ?>"><?php echo has_post_thumbnail() ? get_the_post_thumbnail($pid, 'sbn-product-card') : wc_placeholder_img('sbn-product-card'); ?></a>
                                    <div class="product-badge-row">
                                        <span class="product-badge-style"><?php echo esc_html($ps['name']); ?></span>
                                        <?php if ($pd): ?><div class="product-difficulty-stars" title="<?php echo esc_attr($pd['name']); ?>"><?php for ($i=1;$i<=5;$i++) echo $i<=$pd['stars']?'<span class="star-filled">★</span>':'<span class="star-empty">★</span>'; ?></div><?php endif; ?>
                                    </div>
                                    <?php if (!empty($psc)): ?><div class="product-badge-type"><?php foreach ($psc as $sc): ?><span class="product-badge-subcat"><?php echo esc_html($sc['name']); ?></span><?php endforeach; ?></div><?php endif; ?>
                                    <div class="product-description">
                                        <a href="<?php the_permalink(); ?>" class="product-view-button">View Details →</a>
                                        <a href="#" data-quantity="1" data-product_id="<?php echo esc_attr($pid); ?>" class="sbn-quick-add"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="m1 1 4 0 2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg> Add to Cart</a>
                                        <?php if ($product->get_short_description()): ?>
                                        <p class="product-excerpt"><?php echo wp_trim_words($product->get_short_description(), 10, '...'); ?></p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="product-title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a><span class="product-price"><?php echo $product->get_price_html(); ?></span></div>
                            </div>
                            <?php endwhile; ?>
                        </div>
                    </div>
                </div>
            <?php wp_reset_postdata(); endif;
            
            else:
                if (woocommerce_product_loop()):
                    $current_cat = sbn_get_current_category_info();
                    global $wp_query; $max_pages = $wp_query->max_num_pages;
            ?>
                <div class="sbn-products-grid" style="--category-color: <?php echo esc_attr($current_cat['color']); ?>;">
                    <?php while (have_posts()): the_post(); global $product; $pid = get_the_ID(); $ps = sbn_get_product_style($pid); $psc = sbn_get_product_subcategories($pid); $pd = sbn_get_product_difficulty($pid); ?>
                    <div class="sbn-product-card" style="--category-color: <?php echo esc_attr($ps['color']); ?>; --category-gradient: <?php echo esc_attr($ps['gradient']); ?>">
                        <div class="sbn-product-image">
                            <a href="<?php the_permalink(); ?>">
                                <?php echo has_post_thumbnail() ? get_the_post_thumbnail($pid, 'sbn-product-card') : wc_placeholder_img('sbn-product-card'); ?>
                            </a>
                            
                            <!-- Top Badges Row -->
                            <div class="sbn-product-badge-row">
                                <span class="sbn-product-badge-style"><?php echo esc_html($ps['name']); ?></span>
                                <?php if ($pd): ?>
                                <div class="sbn-product-difficulty" title="<?php echo esc_attr($pd['name']); ?>">
                                    <?php for ($i=1; $i<=5; $i++) echo $i<=$pd['stars'] ? '<span class="star-filled">★</span>' : '<span class="star-empty">★</span>'; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Subcategory Badges at Bottom -->
                            <?php if (!empty($psc)): ?>
                            <div class="sbn-product-badge-type">
                                <?php foreach ($psc as $sc): ?>
                                <span class="sbn-product-badge-subcat"><?php echo esc_html($sc['name']); ?></span>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                            
                            <!-- Hover Overlay -->
                            <div class="sbn-product-overlay">
                                <a href="<?php the_permalink(); ?>" class="sbn-product-view-btn">View Details →</a>
                                <a href="#" 
                                   data-quantity="1" 
                                   data-product_id="<?php echo esc_attr($pid); ?>" 
                                   class="sbn-product-quick-add">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="m1 1 4 0 2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
                                    Add to Cart
                                </a>
                                <?php if ($product->get_short_description()): ?>
                                <p class="sbn-product-excerpt"><?php echo wp_trim_words($product->get_short_description(), 10, '...'); ?></p>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Product Info Below Image -->
                        <div class="sbn-product-info-bottom">
                            <a href="<?php the_permalink(); ?>" class="sbn-product-title-link"><?php the_title(); ?></a>
                            <div class="sbn-product-price-display"><?php echo $product->get_price_html(); ?></div>
                        </div>
                    </div>
                    <?php endwhile; ?>
                </div>
                <?php if ($max_pages > 1): ?><div class="load-more-container"><button class="load-more-button" id="load-more-btn" data-page="1" data-max="<?php echo $max_pages; ?>">Load More</button></div><?php endif; ?>
                <?php else: ?>
                <div class="no-products-found">
                    <div class="empty-shop-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="9" cy="21" r="1"/>
                            <circle cx="20" cy="21" r="1"/>
                            <path d="m1 1 4 0 2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>
                        </svg>
                    </div>
                    <h2>No Products Found</h2>
                    <p>We couldn't find any products matching your criteria. Try adjusting your filters or browse our categories.</p>
                    <div class="empty-shop-actions">
                        <a href="<?php echo get_permalink(wc_get_page_id('shop')); ?>" class="back-to-shop-btn">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M19 12H5M12 19l-7-7 7-7"/>
                            </svg>
                            Back to Shop
                        </a>
                        <?php if (is_search() || is_product_category()): ?>
                        <a href="<?php echo get_permalink(wc_get_page_id('shop')); ?>" class="clear-filters-btn">Clear All Filters</a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif;
            endif; ?>
        </main>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.carousel-container').forEach(function(c) {
        var track = c.querySelector('.carousel-track'), items = track.querySelectorAll('.product-item'), id = c.dataset.carousel;
        var prev = document.querySelector('.carousel-prev[data-carousel="'+id+'"]'), next = document.querySelector('.carousel-next[data-carousel="'+id+'"]');
        if (!prev || !next || !items.length) return;
        var idx = 0;
        
        // Touch/Swipe variables
        var touchStartX = 0, touchEndX = 0, touchStartY = 0, isSwiping = false;
        
        function getPerView() { return window.innerWidth <= 768 ? 1 : window.innerWidth <= 1200 ? 2 : 3; }
        function update() { var pv = getPerView(), max = Math.max(0, items.length - pv); idx = Math.min(idx, max); track.style.transform = 'translateX(-'+(idx*(items[0].offsetWidth+20))+'px)'; prev.classList.toggle('disabled', idx===0); next.classList.toggle('disabled', idx>=max); }
        
        prev.addEventListener('click', function() { if (idx > 0) { idx--; update(); } });
        next.addEventListener('click', function() { var max = Math.max(0, items.length - getPerView()); if (idx < max) { idx++; update(); } });
        
        // Touch Events for Swipe
        c.addEventListener('touchstart', function(e) {
            touchStartX = e.changedTouches[0].screenX;
            touchStartY = e.changedTouches[0].screenY;
            isSwiping = false;
        }, {passive: true});
        
        c.addEventListener('touchmove', function(e) {
            var diffX = Math.abs(e.changedTouches[0].screenX - touchStartX);
            var diffY = Math.abs(e.changedTouches[0].screenY - touchStartY);
            if (diffX > diffY && diffX > 10) isSwiping = true;
        }, {passive: true});
        
        c.addEventListener('touchend', function(e) {
            touchEndX = e.changedTouches[0].screenX;
            var diff = touchStartX - touchEndX;
            var max = Math.max(0, items.length - getPerView());
            
            if (isSwiping && Math.abs(diff) > 50) {
                if (diff > 0 && idx < max) { idx++; update(); }
                else if (diff < 0 && idx > 0) { idx--; update(); }
            }
        }, {passive: true});
        
        window.addEventListener('resize', function() { clearTimeout(window.cr); window.cr = setTimeout(update, 250); });
        update();
    });
    var btn = document.getElementById('load-more-btn');
    if (btn) btn.addEventListener('click', function() {
        var p = parseInt(this.dataset.page), m = parseInt(this.dataset.max); if (p >= m) return;
        this.textContent = 'Loading...';
        var url = new URL(window.location.href); url.searchParams.set('paged', p+1);
        fetch(url).then(function(r){return r.text();}).then(function(html){
            var doc = new DOMParser().parseFromString(html,'text/html');
            doc.querySelectorAll('.sbn-product-card').forEach(function(x){document.querySelector('.sbn-products-grid').appendChild(x.cloneNode(true));});
            btn.dataset.page = p+1;
            btn.textContent = (p+1>=m) ? 'All Loaded' : 'Load More';
            if (p+1>=m) btn.disabled = true;
        });
    });
});

// Add to Cart AJAX - No redirect
jQuery(function($) {
    var cartIcon = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="m1 1 4 0 2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>';
    var arrowIcon = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M12 5l7 7-7 7"/></svg>';
    
    $(document).on('click', '.sbn-product-quick-add, .sbn-quick-add', function(e) {
        e.preventDefault();
        var $btn = $(this);
        var productId = $btn.data('product_id');
        
        $btn.addClass('loading').html('Adding...');
        
        $.ajax({
            url: wc_add_to_cart_params.ajax_url,
            type: 'POST',
            data: { action: 'woocommerce_add_to_cart', product_id: productId, quantity: 1 },
            success: function(response) {
                $(document.body).trigger('added_to_cart', [response.fragments, response.cart_hash, $btn]);
            }
        });
    });
    
    $(document.body).on('added_to_cart', function(e, fragments, hash, $btn) {
        // Animate cart icon
        $('.menu-item-cart .cart-icon-link').addClass('cart-bounce');
        setTimeout(function() { $('.menu-item-cart .cart-icon-link').removeClass('cart-bounce'); }, 600);
        
        // Update button
        if ($btn && ($btn.hasClass('sbn-product-quick-add') || $btn.hasClass('sbn-quick-add'))) {
            $btn.removeClass('loading').addClass('added').html('Added ✓');
            if (!$btn.next('.sbn-product-view-cart').length && !$btn.next('.sbn-view-cart').length) {
                $btn.after('<a href="<?php echo wc_get_cart_url(); ?>" class="sbn-product-view-cart">View Cart ' + arrowIcon + '</a>');
            }
        }
    });
});
</script>

<?php get_footer(); ?>
