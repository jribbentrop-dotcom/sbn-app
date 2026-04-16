<?php
/**
 * Custom Post Types & Taxonomies
 * Registers sbn_course and sbn_lesson post types
 */

if (!defined('ABSPATH')) exit;

add_action('init', 'sbn_register_post_types');

function sbn_register_post_types() {
    // Course - public with archive
    register_post_type('sbn_course', [
        'labels' => [
            'name' => 'Courses',
            'singular_name' => 'Course',
            'add_new_item' => 'Add New Course',
            'edit_item' => 'Edit Course',
        ],
        'public' => true,
        'show_ui' => true,
        'show_in_menu' => 'sbn-courses',
        'show_in_rest' => true,
        'supports' => ['title', 'editor', 'thumbnail', 'excerpt'],
        'has_archive' => true,
        'rewrite' => [
            'slug' => 'learn',
            'with_front' => false,
        ],
    ]);

    // Lesson - stays private (accessed through course player)
    register_post_type('sbn_lesson', [
        'labels' => [
            'name' => 'Lessons',
            'singular_name' => 'Lesson',
            'add_new_item' => 'Add New Lesson',
            'edit_item' => 'Edit Lesson',
        ],
        'public' => false,
        'show_ui' => true,
        'show_in_menu' => 'sbn-courses',
        'show_in_rest' => true,
        'supports' => ['title', 'editor', 'page-attributes'],
        'has_archive' => false,
    ]);
    
    // Course Genre taxonomy
    register_taxonomy('course_genre', 'sbn_course', [
        'labels' => [
            'name' => 'Course Genres',
            'singular_name' => 'Genre',
        ],
        'public' => true,
        'show_ui' => true,
        'show_in_rest' => true,
        'hierarchical' => true,
        'show_admin_column' => true,
        'rewrite' => ['slug' => 'course-genre'],
    ]);
    
    // Course Level taxonomy
    register_taxonomy('course_level', 'sbn_course', [
        'labels' => [
            'name' => 'Course Levels',
            'singular_name' => 'Level',
        ],
        'public' => true,
        'show_ui' => true,
        'show_in_rest' => true,
        'hierarchical' => true,
        'show_admin_column' => true,
        'rewrite' => ['slug' => 'course-level'],
    ]);
}
