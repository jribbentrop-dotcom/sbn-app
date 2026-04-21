<?php
/**
 * Course Archive Display Functions
 * Handles rendering of SBN Course cards in archive/carousel views
 */

if (!function_exists('flavor_render_course_card')) {
    /**
     * Render a course card (matches shop product card structure exactly)
     * 
     * @param int $course_id The course post ID
     * @param array $style_config Style configuration array
     * @param array $difficulty_levels Difficulty levels configuration
     */
    function flavor_render_course_card($course_id, $style_config, $difficulty_levels) {
        $course = get_post($course_id);
        if (!$course) return;
        
        $genres = get_the_terms($course_id, 'course_genre');
        $levels = get_the_terms($course_id, 'course_level');
        
        // Get style (matches shop logic)
        $style = [
            'color' => '#2c3e50', 
            'gradient' => 'linear-gradient(135deg, #2c3e50 0%, #34495e 100%)', 
            'name' => 'Course'
        ];
        
        if ($genres && !is_wp_error($genres)) {
            foreach ($genres as $g) {
                if (isset($style_config[$g->slug])) {
                    $style = $style_config[$g->slug];
                    break;
                }
            }
        }
        
        // Get difficulty
        $difficulty = null;
        if ($levels && !is_wp_error($levels)) {
            foreach ($levels as $lv) {
                if (isset($difficulty_levels[$lv->slug])) {
                    $difficulty = $difficulty_levels[$lv->slug];
                    break;
                }
            }
        }
        
        // Get lesson count
        $lessons = get_posts([
            'post_type' => 'sbn_lesson',
            'posts_per_page' => -1,
            'meta_query' => [['key' => '_sbn_course_slug', 'value' => $course->post_name]],
        ]);
        $lesson_count = count($lessons);
        
        ?>
        <div class="product-item" style="--category-color: <?php echo esc_attr($style['color']); ?>; --category-gradient: <?php echo esc_attr($style['gradient']); ?>">
            <div class="product-image">
                <a href="<?php echo get_permalink($course_id); ?>">
                    <?php if (has_post_thumbnail($course_id)) : ?>
                        <?php echo get_the_post_thumbnail($course_id, 'medium'); ?>
                    <?php else : ?>
                        <div style="background: <?php echo esc_attr($style['gradient']); ?>; width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; color: white; font-size: 48px;">🎸</div>
                    <?php endif; ?>
                </a>
                
                <div class="product-badge-row">
                    <span class="product-badge-style"><?php echo esc_html($style['name']); ?></span>
                    <?php if ($difficulty) : ?>
                        <div class="product-difficulty-stars" title="<?php echo esc_attr($difficulty['name']); ?>">
                            <?php for ($i = 1; $i <= 5; $i++) : ?>
                                <span class="<?php echo $i <= $difficulty['stars'] ? 'star-filled' : 'star-empty'; ?>">★</span>
                            <?php endfor; ?>
                        </div>
                    <?php endif; ?>
                </div>
                
                <?php if ($lesson_count > 0) : ?>
                    <div class="product-badge-type">
                        <span class="product-badge-subcat">📚 <?php echo $lesson_count; ?> Lesson<?php echo $lesson_count !== 1 ? 's' : ''; ?></span>
                    </div>
                <?php endif; ?>
                
                <div class="product-description">
                    <a href="<?php echo get_permalink($course_id); ?>" class="product-view-button">View Course →</a>
                    <?php if ($course->post_excerpt) : ?>
                        <p class="product-excerpt"><?php echo wp_trim_words($course->post_excerpt, 12, '...'); ?></p>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="product-title">
                <a href="<?php echo get_permalink($course_id); ?>">
                    <?php echo esc_html($course->post_title); ?>
                </a>
            </div>
        </div>
        <?php
    }
}
