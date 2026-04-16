<?php
/**
 * Admin Functions & Helper Utilities
 * Admin menu, lesson filtering, meta boxes, access control, and helper functions
 */

if (!defined('ABSPATH')) exit;

// ============================================================================
// ADMIN MENU
// ============================================================================

add_action('admin_menu', 'sbn_admin_menu');

function sbn_admin_menu() {
    add_menu_page(
        'SBN Courses',
        'SBN Courses',
        'manage_options',
        'sbn-courses',
        'sbn_redirect_to_courses',  // Simple redirect function
        'dashicons-welcome-learn-more',
        30
    );
    
    // WordPress auto-adds "Courses" and "Lessons" submenus via show_in_menu
}

function sbn_redirect_to_courses() {
    wp_redirect(admin_url('edit.php?post_type=sbn_course'));
    exit;
}

// ============================================================================
// ADMIN LESSON FILTERING BY COURSE
// ============================================================================

// Filter lessons by course_id in admin
add_action('pre_get_posts', 'sbn_filter_lessons_by_course');

function sbn_filter_lessons_by_course($query) {
    if (!is_admin() || !$query->is_main_query()) {
        return;
    }
    
    if ($query->get('post_type') !== 'sbn_lesson') {
        return;
    }
    
    $course_id = isset($_GET['course_id']) ? intval($_GET['course_id']) : 0;
    
    if ($course_id > 0) {
        $course = get_post($course_id);
        $course_slug = $course ? $course->post_name : '';
        
        $query->set('meta_query', [
            'relation' => 'OR',
            ['key' => '_sbn_course_id', 'value' => $course_id],
            ['key' => '_sbn_course_slug', 'value' => $course_slug],
        ]);
    }
}

// Add course filter dropdown to lessons list
add_action('restrict_manage_posts', 'sbn_add_course_filter_dropdown');

function sbn_add_course_filter_dropdown($post_type) {
    if ($post_type !== 'sbn_lesson') {
        return;
    }
    
    $courses = get_posts([
        'post_type' => 'sbn_course',
        'posts_per_page' => -1,
        'orderby' => 'title',
        'order' => 'ASC',
    ]);
    
    if (empty($courses)) {
        return;
    }
    
    $selected = isset($_GET['course_id']) ? intval($_GET['course_id']) : 0;
    
    echo '<select name="course_id">';
    echo '<option value="">All Courses</option>';
    foreach ($courses as $course) {
        $sel = ($selected == $course->ID) ? ' selected' : '';
        echo '<option value="' . $course->ID . '"' . $sel . '>' . esc_html($course->post_title) . '</option>';
    }
    echo '</select>';
}

// Add course column to lessons list
add_filter('manage_sbn_lesson_posts_columns', 'sbn_lesson_columns');

function sbn_lesson_columns($columns) {
    $new_columns = [];
    foreach ($columns as $key => $value) {
        $new_columns[$key] = $value;
        if ($key === 'title') {
            $new_columns['sbn_course'] = 'Course';
            $new_columns['sbn_section'] = 'Section';
            $new_columns['sbn_preview'] = 'Preview';
        }
    }
    return $new_columns;
}

add_action('manage_sbn_lesson_posts_custom_column', 'sbn_lesson_column_content', 10, 2);

function sbn_lesson_column_content($column, $post_id) {
    if ($column === 'sbn_course') {
        $course_id = get_post_meta($post_id, '_sbn_course_id', true);
        if ($course_id) {
            $course = get_post($course_id);
            if ($course) {
                echo '<a href="' . admin_url('edit.php?post_type=sbn_lesson&course_id=' . $course_id) . '">' . esc_html($course->post_title) . '</a>';
            }
        }
    } elseif ($column === 'sbn_section') {
        echo esc_html(get_post_meta($post_id, '_sbn_section_title', true));
    } elseif ($column === 'sbn_preview') {
        $is_preview = get_post_meta($post_id, '_sbn_is_preview', true);
        echo $is_preview ? '<span style="color: #e85d3b;">✓ Preview</span>' : '—';
    }
}

// Make columns sortable
add_filter('manage_edit-sbn_lesson_sortable_columns', 'sbn_lesson_sortable_columns');

function sbn_lesson_sortable_columns($columns) {
    $columns['sbn_section'] = 'sbn_section';
    return $columns;
}

// Show notice when filtering lessons by course
add_action('admin_notices', 'sbn_lesson_filter_notice');

function sbn_lesson_filter_notice() {
    $screen = get_current_screen();
    if (!$screen || $screen->id !== 'edit-sbn_lesson') {
        return;
    }
    
    $course_id = isset($_GET['course_id']) ? intval($_GET['course_id']) : 0;
    if ($course_id > 0) {
        $course = get_post($course_id);
        if ($course) {
            echo '<div class="notice notice-info" style="margin-bottom: 15px;">';
            echo '<p><strong>Showing lessons for:</strong> ' . esc_html($course->post_title);
            echo ' &nbsp;|&nbsp; <a href="' . admin_url('post.php?post=' . $course_id . '&action=edit') . '">Edit Course</a>';
            echo ' &nbsp;|&nbsp; <a href="' . admin_url('edit.php?post_type=sbn_lesson') . '">View All Lessons</a>';
            echo ' &nbsp;|&nbsp; <a href="' . admin_url('post-new.php?post_type=sbn_lesson&course_id=' . $course_id) . '">Add New Lesson to this Course</a>';
            echo '</p></div>';
        }
    }
}

// Pre-fill course ID when adding new lesson from filtered view
add_action('admin_head-post-new.php', 'sbn_prefill_course_id');

function sbn_prefill_course_id() {
    global $post_type;
    if ($post_type !== 'sbn_lesson') {
        return;
    }
    
    $course_id = isset($_GET['course_id']) ? intval($_GET['course_id']) : 0;
    if ($course_id > 0) {
        ?>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            var courseSelect = document.getElementById('sbn_course_id');
            if (courseSelect) {
                courseSelect.value = '<?php echo esc_js($course_id); ?>';
            }
        });
        </script>
        <?php
    }
}

// ============================================================================
// META BOXES
// ============================================================================

add_action('add_meta_boxes', 'sbn_add_meta_boxes');

function sbn_add_meta_boxes() {
    // Course meta box
    add_meta_box('sbn_course_settings', 'Course Settings', 'sbn_course_meta_box', 'sbn_course', 'side');
    
    // Lesson meta box
    add_meta_box('sbn_lesson_settings', 'Lesson Settings', 'sbn_lesson_meta_box', 'sbn_lesson', 'side');
}

function sbn_course_meta_box($post) {
    wp_nonce_field('sbn_course_meta', 'sbn_course_nonce');
    $product_id = get_post_meta($post->ID, '_sbn_product_id', true);
    $permalink = get_permalink($post->ID);
    ?>
    <p>
        <label for="sbn_product_id"><strong>WooCommerce Product ID:</strong></label><br>
        <input type="number" id="sbn_product_id" name="sbn_product_id" value="<?php echo esc_attr($product_id); ?>" style="width: 100%;">
        <span class="description">Leave empty for free course</span>
    </p>
    <hr>
    <p>
        <strong>Course URL:</strong><br>
        <a href="<?php echo esc_url($permalink); ?>" target="_blank" style="word-break: break-all;"><?php echo esc_html($permalink); ?></a>
    </p>
    <p style="font-size: 11px; color: #666;">
        <strong>Shortcode (optional):</strong><br>
        <code>[sbn_course slug="<?php echo $post->post_name; ?>"]</code><br>
        <em>Use this to embed the course elsewhere</em>
    </p>
    <?php
}

function sbn_lesson_meta_box($post) {
    wp_nonce_field('sbn_lesson_meta', 'sbn_lesson_nonce');
    
    $course_id = get_post_meta($post->ID, '_sbn_course_id', true);
    $course_slug = get_post_meta($post->ID, '_sbn_course_slug', true);
    $is_preview = get_post_meta($post->ID, '_sbn_is_preview', true);
    $section_title = get_post_meta($post->ID, '_sbn_section_title', true);
    
    $courses = get_posts(['post_type' => 'sbn_course', 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC']);
    ?>
    <p>
        <label for="sbn_course_id"><strong>Course:</strong></label><br>
        <select id="sbn_course_id" name="sbn_course_id" style="width: 100%;">
            <option value="">— Select Course —</option>
            <?php foreach ($courses as $course): ?>
                <option value="<?php echo $course->ID; ?>" <?php selected($course_id, $course->ID); ?>>
                    <?php echo esc_html($course->post_title); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </p>
    <p>
        <label for="sbn_section_title"><strong>Section Title:</strong></label><br>
        <input type="text" id="sbn_section_title" name="sbn_section_title" value="<?php echo esc_attr($section_title); ?>" style="width: 100%;">
        <span class="description">Starts a new section in sidebar (e.g., "The Clave")</span>
    </p>
    <p>
        <label>
            <input type="checkbox" name="sbn_is_preview" value="1" <?php checked($is_preview, '1'); ?>>
            <strong>Free Preview</strong> (accessible without purchase)
        </label>
    </p>
    <?php
}

add_action('save_post', 'sbn_save_meta');

function sbn_save_meta($post_id) {
    // Course meta
    if (isset($_POST['sbn_course_nonce']) && wp_verify_nonce($_POST['sbn_course_nonce'], 'sbn_course_meta')) {
        if (isset($_POST['sbn_product_id'])) {
            update_post_meta($post_id, '_sbn_product_id', sanitize_text_field($_POST['sbn_product_id']));
        }
    }
    
    // Lesson meta
    if (isset($_POST['sbn_lesson_nonce']) && wp_verify_nonce($_POST['sbn_lesson_nonce'], 'sbn_lesson_meta')) {
        if (isset($_POST['sbn_course_id'])) {
            update_post_meta($post_id, '_sbn_course_id', sanitize_text_field($_POST['sbn_course_id']));
        }
        update_post_meta($post_id, '_sbn_is_preview', isset($_POST['sbn_is_preview']) ? '1' : '');
        if (isset($_POST['sbn_section_title'])) {
            update_post_meta($post_id, '_sbn_section_title', sanitize_text_field($_POST['sbn_section_title']));
        }
    }
}

// ============================================================================
// ENQUEUE ASSETS FOR STANDALONE SHORTCODES
// ============================================================================

// Automatically enqueue assets when shortcodes are used outside course player
add_action('wp_enqueue_scripts', 'sbn_enqueue_shortcode_assets');

function sbn_enqueue_shortcode_assets() {
    // Check if we're in a post/page that might have our shortcodes
    global $post;
    
    if (!is_a($post, 'WP_Post')) {
        return;
    }
    
    // Check if content has chord or rhythm shortcodes
    $has_chord = has_shortcode($post->post_content, 'chord');
    $has_rhythm = has_shortcode($post->post_content, 'rhythm');
    
    // Enqueue assets if shortcodes are present
    if ($has_chord || $has_rhythm) {
        wp_enqueue_style(
            'sbn-course-player', 
            SBN_PLUGIN_URL . 'assets/css/course-player.css', 
            [], 
            SBN_VERSION
        );
        
        // Enqueue JavaScript for rhythm playback
        if ($has_rhythm) {
            wp_enqueue_script(
                'sbn-course-player', 
                SBN_PLUGIN_URL . 'assets/js/course-player.js', 
                [], 
                SBN_VERSION, 
                true
            );
        }
    }
}

// ============================================================================
// HELPER FUNCTIONS
// ============================================================================

function sbn_get_course_lessons($course_id) {
    $course = get_post($course_id);
    $course_slug = $course ? $course->post_name : '';
    
    return get_posts([
        'post_type' => 'sbn_lesson',
        'posts_per_page' => -1,
        'orderby' => 'menu_order',
        'order' => 'ASC',
        'meta_query' => [
            'relation' => 'OR',
            ['key' => '_sbn_course_id', 'value' => $course_id],
            ['key' => '_sbn_course_slug', 'value' => $course_slug],
        ],
    ]);
}

function sbn_user_has_access($course_id) {
    if (!function_exists('wc_customer_bought_product')) {
        return true;
    }
    
    $product_id = get_post_meta($course_id, '_sbn_product_id', true);
    if (empty($product_id)) {
        return true;
    }
    
    if (!is_user_logged_in()) {
        return false;
    }
    
    $user = wp_get_current_user();
    return wc_customer_bought_product($user->user_email, $user->ID, $product_id);
}

function sbn_parse_subsections($content) {
    $subsections = [];
    
    if (preg_match_all('/<h2[^>]*>(.*?)<\/h2>/i', $content, $matches)) {
        foreach ($matches[1] as $title) {
            $clean_title = wp_strip_all_tags($title);
            $subsections[] = [
                'title' => $clean_title,
                'slug' => sanitize_title($clean_title),
            ];
        }
    }
    
    return $subsections;
}

function sbn_add_subsection_anchors($content) {
    return preg_replace_callback(
        '/<h2([^>]*)>(.*?)<\/h2>/i',
        function($matches) {
            $attrs = $matches[1];
            $title = $matches[2];
            $slug = sanitize_title(wp_strip_all_tags($title));
            
            if (strpos($attrs, 'id=') === false) {
                return '<h2' . $attrs . ' id="section-' . $slug . '">' . $title . '</h2>';
            }
            return $matches[0];
        },
        $content
    );
}

/**
 * Get WooCommerce product data for course
 */
function sbn_get_course_product_data($course_id) {
    $product_id = get_post_meta($course_id, '_sbn_product_id', true);
    
    if (!$product_id || !function_exists('wc_get_product')) {
        return null;
    }
    
    $product = wc_get_product($product_id);
    if (!$product) {
        return null;
    }
    
    // Get hero image
    $image_id = $product->get_image_id();
    $image_url = $image_id ? wp_get_attachment_image_url($image_id, 'full') : '';
    
    // Get product categories to determine style
    $terms = get_the_terms($product_id, 'product_cat');
    $style = '';
    $style_name = '';
    $color = '#e85d3b'; // Default coral
    $gradient = 'linear-gradient(135deg, #e85d3b 0%, #d04a2a 100%)';
    
    if ($terms && !is_wp_error($terms)) {
        foreach ($terms as $term) {
            $slug = $term->slug;
            if ($slug === 'bossa-nova') {
                $style = 'bossa-nova';
                $style_name = 'Bossa Nova';
                $color = '#f39c12';
                $gradient = 'linear-gradient(135deg, #f39c12 0%, #e74c3c 100%)';
                break;
            } elseif ($slug === 'jazz') {
                $style = 'jazz';
                $style_name = 'Jazz';
                $color = '#3498db';
                $gradient = 'linear-gradient(135deg, #3498db 0%, #1a5490 100%)';
                break;
            } elseif ($slug === 'classical') {
                $style = 'classical';
                $style_name = 'Classical';
                $color = '#9b59b6';
                $gradient = 'linear-gradient(135deg, #9b59b6 0%, #6c3483 100%)';
                break;
            }
        }
    }
    
    // Get difficulty (from product meta or attribute)
    $difficulty = get_post_meta($product_id, '_sbn_difficulty', true);
    if (!$difficulty) {
        // Try to get from product attributes
        $attributes = $product->get_attributes();
        if (isset($attributes['difficulty'])) {
            $difficulty = $attributes['difficulty']->get_options()[0] ?? '';
        }
    }
    
    // Convert difficulty to stars (1-5)
    $stars = 3; // Default
    if ($difficulty) {
        $diff_lower = strtolower($difficulty);
        if (strpos($diff_lower, 'beginner') !== false) $stars = 1;
        elseif (strpos($diff_lower, 'early') !== false) $stars = 2;
        elseif (strpos($diff_lower, 'intermediate') !== false) $stars = 3;
        elseif (strpos($diff_lower, 'late') !== false) $stars = 4;
        elseif (strpos($diff_lower, 'advanced') !== false) $stars = 5;
    }
    
    return [
        'image_url' => $image_url,
        'style' => $style,
        'style_name' => $style_name,
        'color' => $color,
        'gradient' => $gradient,
        'difficulty' => $difficulty,
        'stars' => $stars,
        'product_id' => $product_id,
    ];
}

// ============================================================================
// COURSE SHORTCODE
// ============================================================================


// ============================================================================
// ALPHATAB ENQUEUE FOR COURSE PAGES
// ============================================================================

add_action('wp_enqueue_scripts', 'sbn_enqueue_alphatab_for_courses', 20);

function sbn_enqueue_alphatab_for_courses() {
    if (is_singular('sbn_course')) {
        wp_enqueue_script(
            'alphatab',
            'https://cdn.jsdelivr.net/npm/@coderline/alphatab@latest/dist/alphaTab.js',
            [],
            null,
            true
        );
        wp_enqueue_script(
            'sbn-course-player',
            SBN_PLUGIN_URL . 'assets/js/course-player.js',
            ['alphatab'],
            SBN_VERSION,
            true
        );
    }
}

// ============================================================================
// FRONTEND ADMIN BAR - Edit Lesson Link
// ============================================================================

add_action('admin_bar_menu', 'sbn_admin_bar_edit_lesson', 80); // Priority 80 puts it closer to "Edit Course"

function sbn_admin_bar_edit_lesson($wp_admin_bar) {
    // Only for logged-in admins on frontend
    if (is_admin() || !current_user_can('edit_posts')) {
        return;
    }
    
    // Only on course pages
    if (!is_singular('sbn_course')) {
        return;
    }
    
    $course_id = get_the_ID();
    
    // Get current lesson from URL parameter
    $lesson_slug = isset($_GET['lesson']) ? sanitize_text_field($_GET['lesson']) : '';
    
    if ($lesson_slug) {
        // Find lesson by slug that belongs to this course
        $lessons = get_posts([
            'post_type'   => 'sbn_lesson',
            'name'        => $lesson_slug,
            'post_status' => 'publish',
            'numberposts' => 1,
            // We verify the course ID to ensure we found the correct lesson
            'meta_query'  => [
                [
                    'key'   => '_sbn_course_id', // FIXED: Added underscore to match database
                    'value' => $course_id
                ]
            ]
        ]);
        
        if (!empty($lessons)) {
            $lesson = $lessons[0];
            
            // Add "Edit Lesson" button
            $wp_admin_bar->add_node([
                'id'    => 'sbn-edit-lesson',
                'title' => 'Edit Lesson (' . get_the_title($lesson->ID) . ')', // Added title for clarity
                'href'  => admin_url('post.php?post=' . $lesson->ID . '&action=edit'),
                'meta'  => [
                    'class' => 'sbn-edit-lesson-btn' // Optional class for styling
                ]
            ]);
        }
    }
}