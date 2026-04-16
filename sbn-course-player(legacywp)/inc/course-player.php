<?php
/**
 * Course Player Shortcode
 * Handles [sbn_course] shortcode and course page rendering
 */

if (!defined('ABSPATH')) exit;

add_shortcode('sbn_course', 'sbn_course_shortcode');

function sbn_course_shortcode($atts) {
    $atts = shortcode_atts(['id' => '', 'slug' => ''], $atts);
    
    // Get course
    if (!empty($atts['id'])) {
        $course = get_post($atts['id']);
    } elseif (!empty($atts['slug'])) {
        $course = get_page_by_path($atts['slug'], OBJECT, 'sbn_course');
    } else {
        return '<p>Please specify a course ID or slug.</p>';
    }
    
    if (!$course) {
        return '<p>Course not found.</p>';
    }
    
    // Enqueue assets
    wp_enqueue_style('sbn-course-player', SBN_PLUGIN_URL . 'assets/css/course-player.css', [], SBN_VERSION);
    wp_enqueue_script('sbn-course-player', SBN_PLUGIN_URL . 'assets/js/course-player.js', [], SBN_VERSION, true);
    wp_enqueue_script('sbn-percussion'); // shared percussion sampler
    
    wp_localize_script('sbn-course-player', 'SBN_Config', [
    // Point this to your local folder too
   'alphaTabCDN' => 'https://cdn.jsdelivr.net/npm/@coderline/alphatab@latest/dist/',
    ]);
    
    // Get lessons
    $lessons = sbn_get_course_lessons($course->ID);
    $has_access = sbn_user_has_access($course->ID);
    
    // Build lessons config
    $lessons_config = [];
    $lesson_order = [];
    $current_section = '';
    
    foreach ($lessons as $lesson) {
        $is_preview = get_post_meta($lesson->ID, '_sbn_is_preview', true);
        $section_title = get_post_meta($lesson->ID, '_sbn_section_title', true);
        $accessible = $has_access || $is_preview;
        
        $content = apply_filters('the_content', $lesson->post_content);
        $subsections = sbn_parse_subsections($content);
        
        $lessons_config[$lesson->post_name] = [
            'id' => $lesson->ID,
            'title' => $lesson->post_title,
            'accessible' => (bool) $accessible,
            'isPreview' => (bool) $is_preview,
            'sectionTitle' => $section_title ?: $current_section,
            'subsections' => $subsections,
        ];
        
        $lesson_order[] = $lesson->post_name;
        
        if ($section_title) {
            $current_section = $section_title;
        }
    }
    
    // Initial lesson from URL
    $initial_lesson = isset($_GET['lesson']) ? sanitize_text_field($_GET['lesson']) : ($lesson_order[0] ?? '');
    $initial_subsection = isset($_GET['section']) ? sanitize_text_field($_GET['section']) : null;
    
    // Config for JS
    $config = [
        'courseId' => $course->ID,
        'lessons' => $lessons_config,
        'lessonOrder' => $lesson_order,
        'hasAccess' => $has_access,
        'initialLesson' => $initial_lesson,
        'initialSubsection' => $initial_subsection,
    ];
    
    ob_start();
    ?>
    <div class="sbn-course-player" data-config="<?php echo esc_attr(json_encode($config)); ?>">
        
        <!-- Sidebar -->
        <aside class="sbn-sidebar">
            <?php
            // Get product data for hero
            $product_data = sbn_get_course_product_data($course->ID);
            $hero_image = $product_data ? $product_data['image_url'] : '';
            $style_name = '';
            $stars = $product_data ? $product_data['stars'] : 0;
            $color = '#e85d3b';
            $difficulty = '';
            
            // Get Genre/Category from course_genre taxonomy
            $genres = get_the_terms($course->ID, 'course_genre');
            if ($genres && !is_wp_error($genres)) {
                $genre = $genres[0]; // Get first genre
                $style_name = str_replace('_', ' ', $genre->name); // Remove underscores
                
                // Set color based on genre slug
                $genre_slug = $genre->slug;
                if (strpos($genre_slug, 'bossa') !== false) {
                    $color = '#f39c12'; // Orange
                } elseif (strpos($genre_slug, 'jazz') !== false) {
                    $color = '#3498db'; // Blue
                } elseif (strpos($genre_slug, 'classical') !== false || strpos($genre_slug, 'classic') !== false) {
                    $color = '#9b59b6'; // Purple
                }
            }
            
            // Fallback to product data if no genre found
            if (!$style_name && $product_data) {
                $style_name = $product_data['style_name'];
                $color = $product_data['color'];
            }
            
            // Get Difficulty from course_level taxonomy
            $levels = get_the_terms($course->ID, 'course_level');
            if ($levels && !is_wp_error($levels)) {
                $level = $levels[0]; // Get first level
                $difficulty = $level->name;
                
                // Calculate stars from level
                $level_lower = strtolower($level->name);
                if (strpos($level_lower, 'beginner') !== false || strpos($level_lower, 'basic') !== false) {
                    $stars = 1;
                } elseif (strpos($level_lower, 'early') !== false) {
                    $stars = 2;
                } elseif (strpos($level_lower, 'late') !== false) {
                    $stars = 4;
                } elseif (strpos($level_lower, 'intermediate') !== false) {
                    $stars = 3;
                } elseif (strpos($level_lower, 'advanced') !== false) {
                    $stars = 5;
                }
            }
            
            // Fallback to product data if no level found
            if (!$difficulty && $product_data) {
                $difficulty = $product_data['difficulty'];
                $stars = $product_data['stars'];
            }
            
            // Fallback: Try course meta fields as last resort
            if (!$difficulty) {
                $difficulty = get_post_meta($course->ID, '_sbn_difficulty', true);
            }
            if (!$style_name) {
                $course_style = get_post_meta($course->ID, '_sbn_style', true);
                if ($course_style) {
                    $style_name = ucfirst($course_style);
                }
            }
            
            // Fallback to course featured image
            if (!$hero_image) {
                $thumb_id = get_post_thumbnail_id($course->ID);
                $hero_image = $thumb_id ? wp_get_attachment_image_url($thumb_id, 'medium') : '';
            }
            ?>
            
            <div class="sbn-sidebar-hero">
                <!-- Stars (Difficulty) - Always show if level exists -->
                <?php if ($levels && !is_wp_error($levels)): ?>
                    <div class="sbn-hero-stars">
                        <?php 
                        // Debug: Always calculate stars
                        if ($stars == 0) {
                            $stars = 3; // Default to 3 if not set
                        }
                        echo str_repeat('★', $stars) . str_repeat('☆', 5 - $stars); 
                        ?>
                    </div>
                <?php endif; ?>
                
                <!-- Title -->
                <h2 class="sbn-hero-title"><?php echo esc_html($course->post_title); ?></h2>
                
                <!-- Badges: Category & Lesson Count side by side -->
                <div class="sbn-hero-badges">
                    <!-- Style/Category -->
                    <?php if ($style_name && $genres): ?>
                        <a href="<?php echo esc_url(add_query_arg('filter_genre', $genres[0]->slug, home_url('/learn/'))); ?>" class="sbn-badge-style" style="background: <?php echo esc_attr($color); ?>;">
                            <?php echo esc_html($style_name); ?>
                        </a>
                    <?php endif; ?>
                    
                    <!-- Lesson Count -->
                    <span class="sbn-badge-count">
                        <?php echo count($lessons); ?> Lessons
                    </span>
                </div>
            </div>
            
            <nav class="sbn-lesson-nav">
                <?php
                $current_section = '';
                foreach ($lessons as $lesson):
                    $slug = $lesson->post_name;
                    $lesson_config = $lessons_config[$slug];
                    $section_title = get_post_meta($lesson->ID, '_sbn_section_title', true);
                    
                    // Section label
                    if ($section_title && $section_title !== $current_section):
                        $current_section = $section_title;
                        ?>
                        <h3 class="sbn-section-label"><?php echo esc_html($section_title); ?></h3>
                    <?php endif; ?>
                    
                    <?php
                    $has_subs = !empty($lesson_config['subsections']);
                    $classes = ['sbn-lesson-item'];
                    if ($has_subs) $classes[] = 'has-subsections';
                    if (!$lesson_config['accessible']) $classes[] = 'is-locked';
                    ?>
                    <ul class="sbn-lesson-list">
                        <li class="<?php echo implode(' ', $classes); ?>" data-lesson="<?php echo esc_attr($slug); ?>">
                            <div class="sbn-lesson-header">
                                <div class="sbn-lesson-title-row">
                                    <span class="sbn-lesson-title"><?php echo esc_html($lesson->post_title); ?></span>
                                    <?php if ($lesson_config['isPreview']): ?>
                                        <span class="sbn-preview-badge">Preview</span>
                                    <?php endif; ?>
                                </div>
                                <?php if (!$lesson_config['accessible']): ?>
                                    <span class="sbn-lock-icon">🔒</span>
                                <?php elseif ($has_subs): ?>
                                    <span class="sbn-expand-icon"></span>
                                <?php endif; ?>
                            </div>
                            
                            <?php if ($has_subs): ?>
                                <ul class="sbn-sub-menu">
                                    <?php foreach ($lesson_config['subsections'] as $sub): ?>
                                        <li class="sbn-sub-item" data-section="<?php echo esc_attr($sub['slug']); ?>">
                                            <?php echo esc_html($sub['title']); ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </li>
                    </ul>
                <?php endforeach; ?>
            </nav>
            
            <?php if (!$has_access): ?>
                <div class="sbn-sidebar-cta">
                    <?php 
                    $product_id = get_post_meta($course->ID, '_sbn_product_id', true);
                    $product_url = $product_id ? get_permalink(wc_get_page_id('shop')) : '#';
                    ?>
                    <a href="<?php echo esc_url($product_url); ?>" class="sbn-btn">Unlock Full Course</a>
                </div>
            <?php else: ?>
                <div class="sbn-sidebar-progress">
                    <div class="sbn-progress-label">0% Complete</div>
                    <div class="sbn-progress-bar">
                        <div class="sbn-progress-fill"></div>
                    </div>
                </div>
            <?php endif; ?>
        </aside>
        
        <!-- Main Content -->
        <main class="sbn-main">
            <div class="sbn-lesson-content">
                <!-- Content loaded via JS -->
            </div>
            
            <!-- Lock Overlay -->
            <div class="sbn-lock-overlay">
                <div class="sbn-lock-content">
                    <div class="sbn-lock-icon-large">🔒</div>
                    <h3 class="sbn-lock-title">Unlock This Course</h3>
                    <p class="sbn-lock-text">Purchase the course to access all lessons.</p>
                    <?php if (!empty($product_id)): ?>
                        <a href="<?php echo esc_url(get_permalink($product_id)); ?>" class="sbn-btn">Get Access</a>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Bottom Bar -->
            <div class="sbn-bottom-bar">
                <div class="sbn-bar-tabs">
                    <button class="sbn-bar-tab" data-panel="chords">
                        <span class="sbn-tab-icon">🎸</span>
                        Chord Library
                    </button>
                    <button class="sbn-bar-tab" data-panel="rhythms">
                        <span class="sbn-tab-icon">🥁</span>
                        Rhythm Library
                    </button>
                    <button class="sbn-bar-tab" data-panel="songs">
                        <span class="sbn-tab-icon">🎵</span>
                        Practice Songs
                    </button>
                    <button class="sbn-bar-tab" data-panel="tools">
                        <span class="sbn-tab-icon">⚙️</span>
                        Tools
                    </button>
                </div>
                
                <div class="sbn-bar-panel" id="panel-chords">
                    <div class="sbn-bar-panel-inner">
                        <div class="sbn-panel-title">Common Bossa Nova Chords</div>
                        <div class="sbn-chords-row">
                            <!-- Placeholder chords - will be replaced with dynamic content -->
                            <div class="sbn-chord-placeholder">Am7</div>
                            <div class="sbn-chord-placeholder">Dm7</div>
                            <div class="sbn-chord-placeholder">G7</div>
                            <div class="sbn-chord-placeholder">Cmaj7</div>
                            <div class="sbn-chord-placeholder">Bm7</div>
                            <div class="sbn-chord-placeholder">E7</div>
                        </div>
                    </div>
                </div>
                
                <div class="sbn-bar-panel" id="panel-rhythms">
                    <div class="sbn-bar-panel-inner">
                        <div class="sbn-panel-title">Rhythm Patterns</div>
                        <div class="sbn-rhythms-row">
                            <!-- Placeholder rhythms -->
                            <div class="sbn-rhythm-placeholder">João Gilberto Basic</div>
                            <div class="sbn-rhythm-placeholder">Partido Alto</div>
                        </div>
                    </div>
                </div>
                
                <div class="sbn-bar-panel" id="panel-songs">
                    <div class="sbn-bar-panel-inner">
                        <div class="sbn-panel-title">Practice Songs</div>
                        <p style="color: #999;">Coming soon...</p>
                    </div>
                </div>
                
                <div class="sbn-bar-panel" id="panel-tools">
                    <div class="sbn-bar-panel-inner">
                        <div class="sbn-panel-title">Practice Tools</div>
                        <p style="color: #999;">Metronome, tuner, practice log coming soon...</p>
                    </div>
                </div>
            </div>
        </main>
        
        <!-- Lesson Templates (hidden) -->
        <?php foreach ($lessons as $lesson):
            $slug = $lesson->post_name;
            $lesson_config = $lessons_config[$slug];
            $content = apply_filters('the_content', $lesson->post_content);
            $content = sbn_add_subsection_anchors($content);
            $first_sub = $lesson_config['subsections'][0] ?? null;
        ?>
            <template class="sbn-lesson-template" data-lesson="<?php echo esc_attr($slug); ?>">
                <div class="sbn-content-body">
                    <?php echo $content; ?>
                </div>
                <div class="sbn-nav-footer">
                    <button class="sbn-nav-btn sbn-nav-prev">← Previous</button>
                    <button class="sbn-nav-btn sbn-nav-next is-primary">Next →</button>
                </div>
            </template>
        <?php endforeach; ?>
    </div>
    <?php
    return ob_get_clean();
}

// ============================================================================
// CHORD SHORTCODES WITH PROFESSIONAL LEAD SHEET TYPOGRAPHY
// ============================================================================

/**
 * Chord Grid Shortcode with Professional Lead Sheet Typography
 * Usage: [chord name="Am7" frets="x02010" fingers="0,0,2,0,1,0"]
 */
