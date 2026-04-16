<?php
/**
 * Course Archive & Template Override
 * Handles course archive pages and single course template rendering
 */

if (!defined('ABSPATH')) exit;

add_action('template_redirect', 'sbn_course_template_redirect');

function sbn_course_template_redirect() {
    // Redirect taxonomy pages to /learn/ with filter
    if (is_tax('course_genre')) {
        $term = get_queried_object();
        $redirect_url = add_query_arg('filter_genre', $term->slug, home_url('/learn/'));
        wp_redirect($redirect_url, 301);
        exit;
    }
    
    if (is_tax('course_level')) {
        $term = get_queried_object();
        $redirect_url = add_query_arg('filter_level', $term->slug, home_url('/learn/'));
        wp_redirect($redirect_url, 301);
        exit;
    }
    
    // Handle course archive
    if (is_post_type_archive('sbn_course')) {
        sbn_render_course_archive();
        exit;
    }
    
    // Handle single course
    if (is_singular('sbn_course')) {
        global $post;
        
        // Safety check
        if (!$post) {
            return;
        }
        
        // Always render our own full-page template (bypass theme)
        sbn_render_course_page();
        exit;
    }
}

function sbn_render_course_page() {
    global $post;
    
    // Safety check
    if (!$post) {
        wp_die('Course not found');
    }
    
    $course_slug = $post->post_name;
    $course_title = get_the_title($post);
    
    // Get course player HTML
    $course_player = sbn_course_shortcode(['slug' => $course_slug]);
    
    ?>
    <!DOCTYPE html>
    <html <?php language_attributes(); ?>>
    <head>
        <meta charset="<?php bloginfo('charset'); ?>">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?php echo esc_html($course_title); ?> - <?php bloginfo('name'); ?></title>
        <?php wp_head(); ?>
        <style>
            /* Hide hero image on single course pages */
            .sbn-course-page .ast-page-title-bar,
            .sbn-course-page .entry-header,
            .sbn-course-page .ast-archive-description {
                display: none !important;
            }
            
            /* Remove top padding that hero creates */
            .sbn-course-page .site-content {
                padding-top: 0 !important;
            }
            
            /* Ensure course player uses theme's normal container width */
            .sbn-course-page-integration .site-main {
                max-width: var(--ast-container-max-width, 1240px);
                margin: 0 auto;
                padding: 40px 20px;
            }
            
            /* Course player takes full available width within container */
            .sbn-course-page-integration .sbn-course-player {
                width: 100%;
                height: auto;
                min-height: 600px;
            }
            
            /* Remove any Astra overrides that might cause full width */
            .sbn-course-page .ast-container {
                max-width: var(--ast-container-max-width, 1240px) !important;
            }
        </style>
    </head>
    <body <?php body_class('sbn-course-page'); ?>>
        <?php wp_body_open(); ?>
        
        <?php
        // Output theme header (menu, etc.)
        if (function_exists('get_header')) {
            get_header();
        }
        ?>
        
        <div class="sbn-course-page-integration">
            <main id="main" class="site-main">
                <article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
                    <div class="entry-content">
                        <?php echo $course_player; ?>
                    </div>
                </article>
            </main>
        </div>
        
        <?php
        // Output theme footer
        if (function_exists('get_footer')) {
            get_footer();
        }
        ?>
        
        <?php wp_footer(); ?>
    </body>
    </html>
    <?php
}

// ============================================================================
// COURSE ARCHIVE TEMPLATE (Matches shop exactly)
// ============================================================================


// ============================================================================
// COURSE ARCHIVE TEMPLATE - SHOP STYLE (sidebar + carousels)
// ============================================================================

function sbn_render_course_archive() {
    // Style configuration - EXACT match to shop
    $style_config = [
        'bossa-nova' => ['name' => 'Bossa Nova', 'color' => '#f39c12', 'gradient' => 'linear-gradient(135deg, #f39c12 0%, #e74c3c 100%)'],
        'jazz' => ['name' => 'Jazz', 'color' => '#3498db', 'gradient' => 'linear-gradient(135deg, #3498db 0%, #1a5490 100%)'],
        'classical' => ['name' => 'Classical', 'color' => '#9b59b6', 'gradient' => 'linear-gradient(135deg, #9b59b6 0%, #6c3483 100%)'],
    ];
    
    $difficulty_levels = [
        'beginner' => ['name' => 'Beginner', 'stars' => 1],
        'basic' => ['name' => 'Basic', 'stars' => 1],
        'early-intermediate' => ['name' => 'Early Intermediate', 'stars' => 2],
        'intermediate' => ['name' => 'Intermediate', 'stars' => 3],
        'late-intermediate' => ['name' => 'Late Intermediate', 'stars' => 4],
        'advanced' => ['name' => 'Advanced', 'stars' => 5],
    ];
    
    // Get filters
    $filter_genre = isset($_GET['filter_genre']) ? sanitize_text_field($_GET['filter_genre']) : '';
    $filter_level = isset($_GET['filter_level']) ? sanitize_text_field($_GET['filter_level']) : '';
    
    // Get terms
    $genres = get_terms(['taxonomy' => 'course_genre', 'hide_empty' => true]);
    $levels = get_terms(['taxonomy' => 'course_level', 'hide_empty' => true]);
    
    ?>
    <!DOCTYPE html>
    <html <?php language_attributes(); ?>>
    <head>
        <meta charset="<?php bloginfo('charset'); ?>">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Guitar Courses - <?php bloginfo('name'); ?></title>
        <?php wp_head(); ?>
        
    </head>
    <body <?php body_class('course-archive'); ?>>
        <?php wp_body_open(); ?>
        <?php get_header(); ?>
        
        <div class="custom-shop-wrapper">
            <div class="shop-layout">
                
                <!-- SIDEBAR - Exact shop style -->
                <!-- SIDEBAR - Exact shop style -->
                <aside class="shop-sidebar">
                    <div class="sidebar-widget">
                        <h3>Genre</h3>
                        <ul>
                            <?php 
                            if ($genres && !is_wp_error($genres)) : 
                                foreach ($genres as $term) :
                                    $is_current = ($filter_genre === $term->slug);
                                    $class = $is_current ? ' class="current-cat"' : '';
                                    $gradient = isset($style_config[$term->slug]) ? $style_config[$term->slug]['gradient'] : '';
                            ?>
                                <li<?php echo $class; ?> style="--genre-gradient: <?php echo esc_attr($gradient); ?>">
                                    <a href="<?php echo add_query_arg('filter_genre', $term->slug, home_url('/learn/')); ?>">
                                        <span class="genre-badge"><?php echo esc_html($term->name); ?></span>
                                        <span class="count"><?php echo $term->count; ?></span>
                                    </a>
                                </li>
                            <?php 
                                endforeach; 
                            endif; 
                            ?>
                        </ul>
                    </div>
                    
                    <div class="sidebar-widget">
                        <h3>Difficulty</h3>
                        <ul>
                            <?php 
                            if ($levels && !is_wp_error($levels)) : 
                                foreach ($levels as $term) :
                                    $is_current = ($filter_level === $term->slug);
                                    $class = $is_current ? ' class="current-cat"' : '';
                            ?>
                                <li<?php echo $class; ?>>
                                    <a href="<?php echo add_query_arg('filter_level', $term->slug, home_url('/learn/')); ?>">
                                        <span class="difficulty-name">
                                            <?php echo esc_html($term->name); ?><br>
                                            <span style="color:#ffc107;font-size:0.9em;">
                                                <?php 
                                                // Flexible difficulty matching
                                                $difficulty = isset($difficulty_levels[$term->slug]) ? $difficulty_levels[$term->slug] : null;
                                                
                                                // Fallback: try to match by name parts
                                                if (!$difficulty) {
                                                    $name_lower = strtolower($term->name);
                                                    if (strpos($name_lower, 'beginner') !== false || strpos($name_lower, 'basic') !== false) {
                                                        $difficulty = ['stars' => 1];
                                                    } elseif (strpos($name_lower, 'early') !== false) {
                                                        $difficulty = ['stars' => 2];
                                                    } elseif (strpos($name_lower, 'late') !== false) {
                                                        $difficulty = ['stars' => 4];
                                                    } elseif (strpos($name_lower, 'intermediate') !== false) {
                                                        $difficulty = ['stars' => 3];
                                                    } elseif (strpos($name_lower, 'advanced') !== false) {
                                                        $difficulty = ['stars' => 5];
                                                    }
                                                }
                                                
                                                if ($difficulty && isset($difficulty['stars'])) {
                                                    echo str_repeat('★', $difficulty['stars']) . str_repeat('☆', 5 - $difficulty['stars']);
                                                }
                                                ?>
                                            </span>
                                        </span>
                                        <span class="count"><?php echo $term->count; ?></span>
                                    </a>
                                </li>
                            <?php 
                                endforeach; 
                            endif; 
                            ?>
                        </ul>
                    </div>
                </aside>
                <!-- MAIN CONTENT -->
                <main class="shop-main-content">
                    <?php
                    // If filtered, show grid
                    if ($filter_genre || $filter_level) :
                        $args = ['post_type' => 'sbn_course', 'posts_per_page' => -1];
                        $tax_query = [];
                        if ($filter_genre) $tax_query[] = ['taxonomy' => 'course_genre', 'field' => 'slug', 'terms' => $filter_genre];
                        if ($filter_level) $tax_query[] = ['taxonomy' => 'course_level', 'field' => 'slug', 'terms' => $filter_level];
                        if (!empty($tax_query)) $args['tax_query'] = $tax_query;
                        
                        $filtered = new WP_Query($args);
                        ?>
                        <div class="sbn-products-grid">
                            <?php if ($filtered->have_posts()) : 
                                while ($filtered->have_posts()) : $filtered->the_post();
                                    // Call theme function if available, fallback to basic display
                                    if (function_exists('flavor_render_course_card')) {
                                        flavor_render_course_card(get_the_ID(), $style_config, $difficulty_levels);
                                    } else {
                                        // Basic fallback if theme function doesn't exist
                                        echo '<div class="course-card-fallback">';
                                        echo '<h3><a href="' . get_permalink() . '">' . get_the_title() . '</a></h3>';
                                        echo '</div>';
                                    }
                                endwhile;
                            else : ?>
                                <p style="text-align:center;padding:40px;">No courses found.</p>
                            <?php endif; ?>
                        </div>
                        <?php
                        wp_reset_postdata();
                    
                    // Otherwise show carousels by category
                    else :
                        foreach ($style_config as $slug => $data) :
                            $term = get_term_by('slug', $slug, 'course_genre');
                            if (!$term || is_wp_error($term)) continue;
                            
                            $courses = new WP_Query([
                                'post_type' => 'sbn_course',
                                'posts_per_page' => 8,
                                'tax_query' => [['taxonomy' => 'course_genre', 'field' => 'slug', 'terms' => $slug]],
                            ]);
                            
                            if (!$courses->have_posts()) continue;
                    ?>
                        <div class="carousel-section">
                            <div class="carousel-header" style="background: <?php echo esc_attr($data['gradient']); ?>;">
                                <h3><?php echo esc_html($data['name']); ?></h3>
                                <div class="carousel-controls">
                                    <span class="carousel-arrow carousel-prev" data-carousel="<?php echo esc_attr($slug); ?>">‹</span>
                                    <span class="carousel-arrow carousel-next" data-carousel="<?php echo esc_attr($slug); ?>">›</span>
                                    <a href="<?php echo add_query_arg('filter_genre', $slug, home_url('/learn/')); ?>" class="view-all-link">View All →</a>
                                </div>
                            </div>
                            <div class="carousel-container" data-carousel="<?php echo esc_attr($slug); ?>">
                                <div class="carousel-track">
                                    <?php while ($courses->have_posts()) : $courses->the_post();
                                        // Call theme function if available
                                        if (function_exists('flavor_render_course_card')) {
                                            flavor_render_course_card(get_the_ID(), $style_config, $difficulty_levels);
                                        } else {
                                            echo '<div class="course-card-fallback"><h3><a href="' . get_permalink() . '">' . get_the_title() . '</a></h3></div>';
                                        }
                                    endwhile; ?>
                                </div>
                            </div>
                        </div>
                    <?php 
                        wp_reset_postdata(); 
                        endforeach;
                    endif; 
                    ?>
                </main>
                
            </div><!-- .shop-layout -->
        </div><!-- .custom-shop-wrapper -->
        
        <script>
        // Carousel navigation
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.carousel-container').forEach(function(c) {
                var track = c.querySelector('.carousel-track'), items = track.querySelectorAll('.product-item, .sbn-product-card'), id = c.dataset.carousel;
                var prev = document.querySelector('.carousel-prev[data-carousel="'+id+'"]'), next = document.querySelector('.carousel-next[data-carousel="'+id+'"]');
                if (!prev || !next || !items.length) return;
                var idx = 0;
                function getPerView() { return window.innerWidth <= 768 ? 1 : window.innerWidth <= 1200 ? 2 : 3; }
                function update() { var pv = getPerView(), max = Math.max(0, items.length - pv); idx = Math.min(idx, max); track.style.transform = 'translateX(-'+(idx*(items[0].offsetWidth+20))+'px)'; prev.classList.toggle('disabled', idx===0); next.classList.toggle('disabled', idx>=max); }
                prev.addEventListener('click', function() { if (idx > 0) { idx--; update(); } });
                next.addEventListener('click', function() { var max = Math.max(0, items.length - getPerView()); if (idx < max) { idx++; update(); } });
                window.addEventListener('resize', function() { clearTimeout(window.cr); window.cr = setTimeout(update, 250); });
                update();
            });
        });
        </script>
        
        <?php get_footer(); ?>
        <?php wp_footer(); ?>
    </body>
    </html>
    <?php
}
