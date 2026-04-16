<?php
/**
 * Song Page — standalone leadsheet page
 *
 * Renders a full-page leadsheet for a single song, replacing the old modal.
 * Usage: create a WordPress page with slug "song" and place [sbn_song_page] in the content.
 *
 * URL format: /song/?id=42
 * Deep link from song library: /song/?id=42&chord=Dm7
 *
 * @package SBN_Course_Player
 * @since 7.6.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ============================================================================
// ASSET ENQUEUING
// ============================================================================

/**
 * Enqueue all assets needed on the song page.
 * Runs early enough to inject into <head>.
 */
function sbn_enqueue_song_page_assets() {
    if ( ! sbn_is_song_page() ) return;

    // Chord styling (chord symbol markup)
    wp_enqueue_style(
        'sbn-chord-styling',
        SBN_PLUGIN_URL . 'assets/css/chord-styling.css',
        array(),
        SBN_VERSION
    );

    // Song page CSS
    wp_enqueue_style(
        'sbn-song-page',
        SBN_PLUGIN_URL . 'assets/css/song-page.css',
        array( 'sbn-chord-styling' ),
        SBN_VERSION
    );

    // Leadsheet assets are enqueued by class-leadsheet.php when it detects the song page.
    // Chord card component (for diagram rendering in leadsheet)
    wp_enqueue_script(
        'sbn-chord-card',
        SBN_PLUGIN_URL . 'assets/js/sbn-chord-card.js',
        array(),
        SBN_VERSION,
        true
    );
}
add_action( 'wp_enqueue_scripts', 'sbn_enqueue_song_page_assets' );

/**
 * Detect if the current page is the song page.
 */
function sbn_is_song_page() {
    return (
        is_page( 'song' ) ||
        ( is_page() && get_post_field( 'post_name', get_post() ) === 'song' )
    );
}

// ============================================================================
// DYNAMIC PAGE TITLE
// ============================================================================

/**
 * Replace the page <title> with the actual song title.
 */
add_filter( 'pre_get_document_title', function( $title ) {
    if ( ! sbn_is_song_page() ) return $title;
    $id = intval( $_GET['id'] ?? 0 );
    if ( ! $id ) return $title;
    if ( ! class_exists( 'SBN_Leadsheet' ) ) return $title;
    $leadsheet = SBN_Leadsheet::instance()->get_leadsheet( $id );
    if ( ! $leadsheet ) return $title;
    return esc_html( $leadsheet->title ) . ' — ' . get_bloginfo( 'name' );
} );

// ============================================================================
// SHORTCODE
// ============================================================================

add_shortcode( 'sbn_song_page', 'sbn_song_page_shortcode' );

function sbn_song_page_shortcode( $atts ) {
    // ID comes from URL param
    $id = intval( $_GET['id'] ?? 0 );

    // Optional highlight chord (from chord library deep-link)
    $highlight = sanitize_text_field( $_GET['chord'] ?? '' );

    // Back URL — default to song library, allow override via ?back= param
    $back_url = sanitize_url( $_GET['back'] ?? '' );
    if ( ! $back_url ) {
        $song_library_page = get_page_by_path( 'song-library' );
        $back_url = $song_library_page ? get_permalink( $song_library_page ) : home_url( '/song-library/' );
    }

    if ( ! $id ) {
        return '<div class="sbn-song-page-error"><p>No song selected. <a href="' . esc_url( $back_url ) . '">← Back to Song Library</a></p></div>';
    }

    if ( ! class_exists( 'SBN_Leadsheet' ) ) {
        return '<div class="sbn-song-page-error"><p>Leadsheet module not available.</p></div>';
    }

    $leadsheet = SBN_Leadsheet::instance()->get_leadsheet( $id );

    if ( ! $leadsheet || empty( $leadsheet->shortcode_content ) ) {
        return '<div class="sbn-song-page-error"><p>Song not found. <a href="' . esc_url( $back_url ) . '">← Back to Song Library</a></p></div>';
    }

    // Build the extra data the renderer needs (mirrors song-library-handler.php)
    $extra = array();

    // Voicing cross-reference metadata
    if ( class_exists( 'SBN_Voicing_Crossref' ) ) {
        $crossref         = SBN_Voicing_Crossref::instance();
        $matched_voicings = $crossref->get_voicings_for_leadsheet( $id );
        $meta = array();
        foreach ( $matched_voicings as $v ) {
            $key        = $v->chord_name . '|' . $v->fret_string;
            $meta[$key] = array(
                'diagramId'       => intval( $v->chord_diagram_id ),
                'quality'         => $v->quality,
                'voicingCategory' => $v->voicing_category ?? '',
                'rootString'      => $v->root_string ?? '',
                'inversion'       => $v->inversion ?? 'root',
                'baseQuality'     => $v->base_quality ?? '',
                'extensions'      => $v->extensions ?? '',
            );
        }
        if ( $meta ) $extra['voicingMeta'] = $meta;
    }

    // Detected chord progressions
    if ( class_exists( 'SBN_Chord_Progressions' ) ) {
        $prog_crossref = SBN_Chord_Progressions::instance();
        $grouped       = $prog_crossref->get_occurrences_for_leadsheet( $id );
        if ( ! empty( $grouped ) ) {
            $extra['detectedProgressions'] = $grouped;
        }
    }

    // Highlight chord (from deep-link)
    if ( $highlight ) {
        $extra['highlightChord'] = $highlight;
    }

    // Render the leadsheet HTML
    $instance_id     = 'sbn-song-page-' . $id;
    $leadsheet_html  = SBN_Leadsheet_Renderer::render( $leadsheet->shortcode_content, $instance_id, $extra );

    // Song metadata for the page header
    $parsed_for_meta = SBN_Leadsheet_Parser::parse( $leadsheet->shortcode_content );
    $song_key        = $parsed_for_meta['key']           ?? $leadsheet->song_key ?? '';
    $composer        = $parsed_for_meta['composer']      ?? $leadsheet->composer ?? '';
    $genre           = $leadsheet->genre                 ?? '';
    $difficulty      = $leadsheet->difficulty            ?? '';
    $description     = $leadsheet->description          ?? '';

    // Difficulty label map
    $diff_labels = array(
        'basic'             => 'Basic',
        'early-intermediate'=> 'Early Intermediate',
        'intermediate'      => 'Intermediate',
        'late-intermediate' => 'Late Intermediate',
        'advanced'          => 'Advanced',
    );

    ob_start();
    ?>
    <div class="sbn-song-page" data-song-id="<?php echo esc_attr( $id ); ?>">

        <?php /* ── Navigation bar ── */ ?>
        <nav class="sbn-song-page-nav">
            <a class="sbn-song-page-back" href="<?php echo esc_url( $back_url ); ?>">
                <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                    <path d="M10 3L5 8l5 5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                Song Library
            </a>
        </nav>

        <?php /* ── Song header ── */ ?>
        <header class="sbn-song-page-header">
            <div class="sbn-song-page-header-main">
                <h1 class="sbn-song-page-title"><?php echo esc_html( $leadsheet->title ); ?></h1>
                <?php if ( $composer ) : ?>
                    <p class="sbn-song-page-composer"><?php echo esc_html( $composer ); ?></p>
                <?php endif; ?>
            </div>
            <div class="sbn-song-page-meta-pills">
                <?php if ( $song_key ) : ?>
                    <span class="sbn-song-page-pill sbn-song-page-pill--key">Key of <?php echo esc_html( $song_key ); ?></span>
                <?php endif; ?>
                <?php if ( $genre ) : ?>
                    <span class="sbn-song-page-pill sbn-song-page-pill--genre"><?php echo esc_html( ucwords( str_replace( '-', ' ', $genre ) ) ); ?></span>
                <?php endif; ?>
                <?php if ( $difficulty && isset( $diff_labels[$difficulty] ) ) : ?>
                    <span class="sbn-song-page-pill sbn-song-page-pill--difficulty"><?php echo esc_html( $diff_labels[$difficulty] ); ?></span>
                <?php endif; ?>
            </div>
            <?php if ( $description ) : ?>
                <p class="sbn-song-page-description"><?php echo esc_html( $description ); ?></p>
            <?php endif; ?>
        </header>

        <?php /* ── Leadsheet player ── */ ?>
        <div class="sbn-song-page-player">
            <?php echo $leadsheet_html; // Already escaped by renderer ?>
        </div>

    </div>
    <?php
    return ob_get_clean();
}
