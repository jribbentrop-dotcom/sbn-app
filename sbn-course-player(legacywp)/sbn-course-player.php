<?php
/**
 * Plugin Name: SBN Course Player
 * Description: Custom course player for SoulBossaNova.com with sheet music, YouTube sync, and WooCommerce integration.
 * Version: 7.4.3
 * Author: SoulBossaNova
 */

if (!defined('ABSPATH')) exit;

// ============================================================================
// CONSTANTS
// ============================================================================

define('SBN_VERSION', '7.4.5');
define('SBN_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SBN_PLUGIN_URL', plugin_dir_url(__FILE__));

// ============================================================================
// CORE COMPONENTS - Always Load
// ============================================================================

// Custom Post Types & Taxonomies
require_once SBN_PLUGIN_DIR . 'inc/post-types.php';

// Admin Functions & Helpers (needed by shortcodes)
require_once SBN_PLUGIN_DIR . 'inc/admin-and-helpers.php';

// Interactive Leadsheet Module
require_once SBN_PLUGIN_DIR . 'inc/leadsheet/class-leadsheet.php';

// Rhythm Patterns Module
require_once SBN_PLUGIN_DIR . 'inc/rhythm-patterns/class-rhythm-patterns.php';

// Chord Diagrams Module (with Calculator)
require_once SBN_PLUGIN_DIR . 'inc/chord-diagrams/class-chord-diagrams.php';
require_once SBN_PLUGIN_DIR . 'inc/chord-shapes/class-chord-shape-calculator.php';

// Chord Search Handler (NEW in v7.1)
require_once SBN_PLUGIN_DIR . 'inc/chord-search-handler.php';

// Song Library Handler (NEW in v7.4)
require_once SBN_PLUGIN_DIR . 'inc/song-library-handler.php';

// Song Page — standalone leadsheet page (NEW in v7.6)
require_once SBN_PLUGIN_DIR . 'inc/song-page.php';

// Progression Library — public page (NEW in v7.7)
require_once SBN_PLUGIN_DIR . 'inc/progression-library.php';
require_once SBN_PLUGIN_DIR . 'inc/chord-detail-page.php';

// Progression Builder — voicing selection + voice leading (NEW in v7.8.5)
require_once SBN_PLUGIN_DIR . 'inc/progression-builder.php';

// Voicing Cross-Reference (NEW in v7.3)
require_once SBN_PLUGIN_DIR . 'inc/voicing-crossref/class-voicing-crossref.php';
SBN_Voicing_Crossref::instance();

// Chord Progression Library (NEW in v7.5)
require_once SBN_PLUGIN_DIR . 'inc/chord-progressions/class-chord-progressions.php';
SBN_Chord_Progressions::instance();

// ============================================================================
// SHORTCODES - Load on Init
// ============================================================================

add_action('init', 'sbn_load_shortcode_components');

function sbn_load_shortcode_components() {
    // Course Player (single course page)
    require_once SBN_PLUGIN_DIR . 'inc/course-player.php';
    
    // Course Archive (course listing page)
    require_once SBN_PLUGIN_DIR . 'inc/course-archive.php';
    
    // Sheet Players
    require_once SBN_PLUGIN_DIR . 'inc/sheet-players.php';
    
    // Rhythm Grid
    require_once SBN_PLUGIN_DIR . 'inc/rhythm-grid.php';
    
    // Chord Grid (supports transposition)
    require_once SBN_PLUGIN_DIR . 'inc/chord-grid.php';
    
    // Chord Name Styling
    require_once SBN_PLUGIN_DIR . 'inc/chord-name-styling.php';
    
    // AlphaTeX Shortcode
    require_once SBN_PLUGIN_DIR . 'inc/alphatex-shortcode.php';

}

// ============================================================================
// ACTIVATION & DEACTIVATION
// ============================================================================

register_activation_hook(__FILE__, 'sbn_activate');
register_deactivation_hook(__FILE__, 'sbn_deactivate');

function sbn_activate() {
    // Post types are registered via inc/post-types.php
    flush_rewrite_rules();
    
    // Create leadsheet database table
    SBN_Leadsheet::activate();
    
    // Create rhythm patterns database table
    SBN_Rhythm_Patterns::activate();
    
    // Create chord diagrams database table
    SBN_Chord_Diagrams::activate();
    
    // Create voicing cross-reference tables
    SBN_Voicing_Crossref::activate();
    
    // Create chord progression library tables
    SBN_Chord_Progressions::activate();
}

function sbn_deactivate() {
    flush_rewrite_rules();
}

// Flush rewrite rules on plugin update (when version changes)
add_action('init', 'sbn_check_version_flush_rules', 999);

function sbn_check_version_flush_rules() {
    $stored_version = get_option('sbn_plugin_version', '0');
    
    if (version_compare($stored_version, SBN_VERSION, '<')) {
        flush_rewrite_rules();
        
        // Run database updates if needed
        SBN_Leadsheet::activate();
        SBN_Rhythm_Patterns::activate();
        SBN_Chord_Diagrams::activate();
        SBN_Voicing_Crossref::activate();
        SBN_Chord_Progressions::activate();
        
        update_option('sbn_plugin_version', SBN_VERSION);
    }
}

// ============================================================================
// FRONTEND ASSETS
// ============================================================================

/**
 * Register Shared Chord Card Component (globally available)
 * 
 * This is the single source of truth for chord card rendering across the
 * entire site: chord library, leadsheet, song library, course pages.
 * Registered early so any page-specific script can declare it as a dependency.
 */
function sbn_register_shared_chord_card() {
    // CSS: shared card styles
    wp_register_style(
        'sbn-chord-card',
        SBN_PLUGIN_URL . 'assets/css/sbn-chord-card.css',
        array('sbn-chord-diagrams-admin'), // needs fretboard base styles
        SBN_VERSION
    );

    // Also register fretboard base CSS globally (needed by chord card)
    wp_register_style(
        'sbn-chord-diagrams-admin',
        SBN_PLUGIN_URL . 'assets/css/chord-diagrams-admin.css',
        array(),
        SBN_VERSION
    );

    // JS: shared audio engine — registered globally, enqueued on pages that need it.
    // Depends on Tone.js. Both leadsheet and chord library declare this as a dependency.
    wp_register_script(
        'sbn-audio',
        SBN_PLUGIN_URL . 'assets/js/sbn-audio.js',
        array('tone-js'),
        SBN_VERSION,
        true
    );

    // JS: shared percussion sampler — one-shot samples, shares Tone.js AudioContext.
    wp_register_script(
        'sbn-percussion',
        SBN_PLUGIN_URL . 'assets/js/sbn-percussion.js',
        array('tone-js'),
        SBN_VERSION,
        true
    );

    // Tone.js — registered here so sbn-audio can declare it as a dep even on
    // pages where leadsheet hasn't registered it yet.
    wp_register_script(
        'tone-js',
        'https://cdnjs.cloudflare.com/ajax/libs/tone/14.8.49/Tone.js',
        array(),
        '14.8.49',
        true
    );

    // JS: shared card component (no jQuery dependency — vanilla JS)
    wp_register_script(
        'sbn-chord-card',
        SBN_PLUGIN_URL . 'assets/js/sbn-chord-card.js',
        array(),
        SBN_VERSION,
        true
    );
}
add_action('wp_enqueue_scripts', 'sbn_register_shared_chord_card', 5); // Priority 5 = before page-specific enqueues

/**
 * Enqueue Rhythm Library Frontend Styles + Percussion
 */
function sbn_enqueue_rhythm_library_frontend_styles() {
    if (is_page('rhythm-library') || is_page_template('page-rhythms.php')) {
        wp_enqueue_style(
            'sbn-rhythm-library-frontend',
            plugin_dir_url(__FILE__) . 'assets/css/rhythm-library-frontend.css',
            array(),
            SBN_VERSION
        );
        // Percussion engine needed for modal playback
        wp_enqueue_script('sbn-percussion');
    }
}
add_action('wp_enqueue_scripts', 'sbn_enqueue_rhythm_library_frontend_styles');

/**
 * Enqueue Chord Library Frontend Assets
 */
function sbn_enqueue_redesigned_chord_library_assets() {
    // Check multiple ways to detect the template
    $is_chord_library = (
        is_page_template('page-chords-redesign.php') ||
        is_page_template('page-chords-diagnostic.php') ||
        is_page('chord-library') || // Also check by page slug
        (is_page() && get_post_field('post_name', get_post()) == 'chord-library')
    );
    
    if ($is_chord_library) {
        // Shared chord card component (the foundation)
        wp_enqueue_style('sbn-chord-card');
        wp_enqueue_script('sbn-chord-card');
        
        // 1. Chord diagram rendering CSS (fretboard styles - already registered globally)
        wp_enqueue_style('sbn-chord-diagrams-admin');
        
        // 2. Chord styling (chord name typography)
        wp_enqueue_style(
            'sbn-chord-styling',
            SBN_PLUGIN_URL . 'assets/css/chord-styling.css',
            array(),
            SBN_VERSION
        );
        
        // 3. Redesigned library CSS (page layout — search, grid, sidebar, modal)
        wp_enqueue_style(
            'sbn-chord-library-redesign',
            SBN_PLUGIN_URL . 'assets/css/chord-library-redesign.css',
            array('sbn-chord-card', 'sbn-chord-styling'),
            SBN_VERSION
        );
        
        // 4. Chord diagram rendering JS (renderMiniFretboard function — legacy, kept for PHP-rendered cards)
        wp_enqueue_script(
            'sbn-chord-diagrams-admin',
            SBN_PLUGIN_URL . 'assets/js/chord-diagrams-admin.js',
            array('jquery'),
            SBN_VERSION,
            true
        );
        
        // 5. Audio engine (shared with leadsheet)
        wp_enqueue_script('sbn-audio');

        // 6. Redesigned library JS (search, filters, modal)
        wp_enqueue_script(
            'sbn-chord-library-redesign',
            SBN_PLUGIN_URL . 'assets/js/chord-library-redesign.js',
            array('jquery', 'sbn-chord-card', 'sbn-chord-diagrams-admin', 'sbn-audio'),
            SBN_VERSION,
            true
        );
        
        // Pass AJAX data
        $song_library_page  = get_page_by_path( 'song-library' );
        $chord_detail_page  = get_page_by_path( 'chord' );
        wp_localize_script('sbn-chord-library-redesign', 'sbnChordSearch', array(
            'ajaxurl'         => admin_url('admin-ajax.php'),
            'nonce'           => wp_create_nonce('sbn_chord_search'),
            'songLibraryUrl'  => $song_library_page  ? get_permalink( $song_library_page )  : '',
            'chordDetailUrl'  => $chord_detail_page   ? get_permalink( $chord_detail_page )  : home_url( '/chord/' ),
        ));
    }
}
add_action('wp_enqueue_scripts', 'sbn_enqueue_redesigned_chord_library_assets');

/**
 * Enqueue Song Library Frontend Assets
 */
function sbn_enqueue_song_library_assets() {
    // Detect via page slug/template OR shortcode presence in post content
    $is_song_library = (
        is_page_template('page-song-library.php') ||
        is_page('song-library') ||
        is_page('song-library-2') ||
        (is_page() && get_post_field('post_name', get_post()) === 'song-library')
    );

    if (!$is_song_library) return;
    
    // 1. Chord styling (for chord names in cards)
    wp_enqueue_style(
        'sbn-chord-styling',
        SBN_PLUGIN_URL . 'assets/css/chord-styling.css',
        array(),
        SBN_VERSION
    );
    
    // 2. Song library CSS
    wp_enqueue_style(
        'sbn-song-library',
        SBN_PLUGIN_URL . 'assets/css/song-library.css',
        array('sbn-chord-styling'),
        SBN_VERSION
    );
    
    // 3. Song library JS
    wp_enqueue_script(
        'sbn-song-library',
        SBN_PLUGIN_URL . 'assets/js/song-library.js',
        array('jquery'),
        SBN_VERSION,
        true
    );
    
    // Note: Leadsheet CSS/JS is auto-enqueued by class-leadsheet.php
    // (it detects the song-library page slug)
    
    // Pass config to JS
    $chord_library_page = get_page_by_path('chord-library');
    $rhythm_library_page = get_page_by_path('rhythm-library');
    $song_page = get_page_by_path('song');
    wp_localize_script('sbn-song-library', 'sbnSongLibrary', array(
        'ajaxUrl'         => admin_url('admin-ajax.php'),
        'nonce'           => wp_create_nonce('sbn_chord_search'),
        'chordLibraryUrl' => $chord_library_page  ? get_permalink($chord_library_page)  : '',
        'rhythmLibraryUrl'=> $rhythm_library_page ? get_permalink($rhythm_library_page) : '',
        'songPageUrl'          => $song_page            ? get_permalink($song_page)           : home_url('/song/'),
        'progressionLibraryUrl' => ($pl = get_page_by_path('chord-progressions')) ? get_permalink($pl) : home_url('/chord-progressions/'),
    ));
}
add_action('wp_enqueue_scripts', 'sbn_enqueue_song_library_assets');

// ============================================================================
// HELPER FUNCTIONS
// ============================================================================

/**
 * Get all rhythm patterns from database
 */
function sbn_get_all_patterns() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'sbn_rhythm_patterns';
    
    return $wpdb->get_results(
        "SELECT * FROM $table_name ORDER BY category, sort_order, name"
    );
}
