<?php
/**
 * Progression Library — public pages
 *
 * Shortcodes:
 *   [sbn_progression_library]  → /chord-progressions/  (ranked list + filter sidebar)
 *   [sbn_progression_detail]   → /progression/?id=42   (full detail page with builder)
 *
 * @package SBN_Course_Player
 * @since 7.9.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ============================================================================
// DB MIGRATION — add tags column
// Runs on init so it doesn't require re-activation.
// ============================================================================

add_action( 'init', 'sbn_prog_lib_maybe_add_tags_column' );

function sbn_prog_lib_maybe_add_tags_column() {
    if ( get_option( 'sbn_prog_lib_tags_version', '0' ) === '1.0' ) return;

    global $wpdb;
    $pt = $wpdb->prefix . 'sbn_chord_progressions';
    $cols = $wpdb->get_col( "DESCRIBE $pt", 0 );
    if ( ! in_array( 'tags', $cols, true ) ) {
        $wpdb->query( "ALTER TABLE $pt ADD COLUMN tags varchar(255) NOT NULL DEFAULT '' AFTER typical_genres" );
    }

    update_option( 'sbn_prog_lib_tags_version', '1.0' );
}

// ============================================================================
// PAGE DETECTION
// ============================================================================

function sbn_is_progression_library_page() {
    return (
        is_page( 'chord-progressions' ) ||
        ( is_page() && get_post_field( 'post_name', get_post() ) === 'chord-progressions' )
    );
}

function sbn_is_progression_detail_page() {
    return (
        is_page( 'progression' ) ||
        ( is_page() && get_post_field( 'post_name', get_post() ) === 'progression' )
    );
}

// ============================================================================
// ASSET ENQUEUING — Library page
// ============================================================================

add_action( 'wp_enqueue_scripts', 'sbn_enqueue_progression_library_assets' );

function sbn_enqueue_progression_library_assets() {
    if ( ! sbn_is_progression_library_page() ) return;

    wp_enqueue_style(
        'sbn-chord-styling',
        SBN_PLUGIN_URL . 'assets/css/chord-styling.css',
        array(),
        SBN_VERSION
    );

    wp_enqueue_style(
        'sbn-progression-library',
        SBN_PLUGIN_URL . 'assets/css/progression-library.css',
        array( 'sbn-chord-styling' ),
        SBN_VERSION
    );

    wp_enqueue_script(
        'sbn-progression-library',
        SBN_PLUGIN_URL . 'assets/js/progression-library.js',
        array( 'jquery' ),
        SBN_VERSION,
        true
    );

    $detail_page = get_page_by_path( 'progression' );
    wp_localize_script( 'sbn-progression-library', 'sbnProgLib', array(
        'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
        'nonce'         => wp_create_nonce( 'sbn_prog_lib' ),
        'detailPageUrl' => $detail_page ? get_permalink( $detail_page ) : home_url( '/progression/' ),
    ) );
}

// ============================================================================
// ASSET ENQUEUING — Detail page
// ============================================================================

add_action( 'wp_enqueue_scripts', 'sbn_enqueue_progression_detail_assets' );

function sbn_enqueue_progression_detail_assets() {
    if ( ! sbn_is_progression_detail_page() ) return;

    wp_enqueue_style(
        'sbn-chord-styling',
        SBN_PLUGIN_URL . 'assets/css/chord-styling.css',
        array(),
        SBN_VERSION
    );

    wp_enqueue_style(
        'sbn-progression-library',
        SBN_PLUGIN_URL . 'assets/css/progression-library.css',
        array( 'sbn-chord-styling' ),
        SBN_VERSION
    );

    wp_enqueue_style(
        'sbn-chord-card',
        SBN_PLUGIN_URL . 'assets/css/sbn-chord-card.css',
        array(),
        SBN_VERSION
    );

    wp_enqueue_script(
        'sbn-chord-card',
        SBN_PLUGIN_URL . 'assets/js/sbn-chord-card.js',
        array(),
        SBN_VERSION,
        true
    );

    wp_enqueue_style(
        'sbn-progression-builder',
        SBN_PLUGIN_URL . 'assets/css/progression-builder.css',
        array( 'sbn-chord-card' ),
        SBN_VERSION
    );

    wp_enqueue_script(
        'sbn-progression-builder',
        SBN_PLUGIN_URL . 'assets/js/progression-builder.js',
        array( 'sbn-chord-card' ),
        SBN_VERSION,
        true
    );

    wp_enqueue_script(
        'sbn-progression-detail',
        SBN_PLUGIN_URL . 'assets/js/progression-library.js',
        array( 'jquery' ),
        SBN_VERSION,
        true
    );

    // Enqueue the leadsheet React bundle — handles are registered by SBN_Leadsheet
    // but enqueue_leadsheet_assets() is private, so we call the handles directly.
    wp_enqueue_style( 'sbn-leadsheet' );
    wp_enqueue_script( 'sbn-leadsheet' );

    // Provide the config object the leadsheet JS expects
    static $sbn_leadsheet_localized = false;
    if ( ! $sbn_leadsheet_localized ) {
        $chord_lib_page = get_page_by_path( 'chord-library' );
        $song_lib_page  = get_page_by_path( 'song-library' );
        $prog_lib_page  = get_page_by_path( 'chord-progressions' );
        $upload_dir     = wp_upload_dir();
        wp_localize_script( 'sbn-leadsheet', 'sbnLeadsheetConfig', array(
            'ajaxUrl'               => admin_url( 'admin-ajax.php' ),
            'nonce'                 => wp_create_nonce( 'sbn_leadsheet_nonce' ),
            'chordSearchNonce'      => wp_create_nonce( 'sbn_chord_search' ),
            'chordLibraryUrl'       => $chord_lib_page ? get_permalink( $chord_lib_page ) : '',
            'songLibraryUrl'        => $song_lib_page  ? get_permalink( $song_lib_page )  : '',
            'progressionLibraryUrl' => $prog_lib_page  ? get_permalink( $prog_lib_page )  : '',
            'samplesBaseUrl'        => $upload_dir['baseurl'] . '/sbn-rhythms/samples/',
        ) );
        $sbn_leadsheet_localized = true;
    }

    $song_page = get_page_by_path( 'song' );
    $lib_page  = get_page_by_path( 'chord-progressions' );
    wp_localize_script( 'sbn-progression-detail', 'sbnProgLib', array(
        'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
        'nonce'        => wp_create_nonce( 'sbn_prog_lib' ),
        'builderNonce' => wp_create_nonce( 'sbn_prog_builder' ),
        'songPageUrl'  => $song_page ? get_permalink( $song_page ) : home_url( '/song/' ),
        'libPageUrl'   => $lib_page  ? get_permalink( $lib_page )  : home_url( '/chord-progressions/' ),
    ) );
}

// ============================================================================
// DYNAMIC PAGE TITLE — detail page
// ============================================================================

add_filter( 'pre_get_document_title', 'sbn_progression_detail_page_title' );

function sbn_progression_detail_page_title( $title ) {
    if ( ! sbn_is_progression_detail_page() ) return $title;
    $id = intval( $_GET['id'] ?? 0 );
    if ( ! $id || ! class_exists( 'SBN_Chord_Progressions' ) ) return $title;
    $p = SBN_Chord_Progressions::instance()->get_progression_by_id( $id );
    if ( ! $p ) return $title;
    return esc_html( $p->name ) . ' — ' . get_bloginfo( 'name' );
}

// ============================================================================
// AJAX — song list for a progression
// ============================================================================

add_action( 'wp_ajax_sbn_get_progression_songs',        'sbn_get_progression_songs_handler' );
add_action( 'wp_ajax_nopriv_sbn_get_progression_songs', 'sbn_get_progression_songs_handler' );

function sbn_get_progression_songs_handler() {
    check_ajax_referer( 'sbn_prog_lib', 'nonce' );

    $id = intval( $_POST['progression_id'] ?? 0 );
    if ( ! $id ) wp_send_json_error( 'Missing progression_id' );
    if ( ! class_exists( 'SBN_Chord_Progressions' ) ) wp_send_json_error( 'Module not available' );

    $rows  = SBN_Chord_Progressions::instance()->get_leadsheets_for_progression( $id );
    $seen  = array();
    $songs = array();
    foreach ( $rows as $row ) {
        $lid = intval( $row->leadsheet_id );
        if ( isset( $seen[$lid] ) ) continue;
        $seen[$lid] = true;
        $songs[] = array(
            'id'            => $lid,
            'title'         => $row->title,
            'detected_root' => $row->detected_root,
        );
    }

    wp_send_json_success( $songs );
}

// ============================================================================
// SHORTCODE — Library page  [sbn_progression_library]
// ============================================================================

add_shortcode( 'sbn_progression_library', 'sbn_progression_library_shortcode' );

function sbn_progression_library_shortcode() {
    if ( ! class_exists( 'SBN_Chord_Progressions' ) ) {
        return '<p>Chord progressions module not available.</p>';
    }

    $instance     = SBN_Chord_Progressions::instance();
    $progressions = $instance->get_all_progressions_with_counts( 'popularity' );

    // Collect unique categories and tags for filter sidebar
    $categories = array();
    $all_tags   = array();
    foreach ( $progressions as $p ) {
        if ( $p->category && ! in_array( $p->category, $categories ) ) {
            $categories[] = $p->category;
        }
        if ( ! empty( $p->tags ) ) {
            foreach ( array_map( 'trim', explode( ',', $p->tags ) ) as $tag ) {
                if ( $tag && ! in_array( $tag, $all_tags ) ) {
                    $all_tags[] = $tag;
                }
            }
        }
    }
    sort( $categories );
    sort( $all_tags );

    $cat_labels = array(
        'jazz'      => 'Jazz',
        'blues'     => 'Blues',
        'pop'       => 'Pop / Rock',
        'modal'     => 'Modal',
        'latin'     => 'Latin',
        'classical' => 'Classical',
    );

    // Pass full data to JS for client-side filtering
    $js_data = array();
    foreach ( $progressions as $p ) {
        $js_data[ intval( $p->id ) ] = array(
            'id'             => intval( $p->id ),
            'name'           => $p->name,
            'category'       => $p->category,
            'numerals'       => $p->numerals,
            'description'    => $p->description,
            'typical_genres' => $p->typical_genres,
            'tags'           => $p->tags ?? '',
            'tonality'       => $p->tonality ?? 'both',
            'song_count'     => intval( $p->song_count ),
        );
    }

    ob_start();
    ?>
    <div class="sbn-prog-lib" id="sbn-prog-lib">

        <?php /* ── Page header ── */ ?>
        <div class="sbn-prog-lib-page-header">
            <h1 class="sbn-prog-lib-page-title">Chord Progression Library</h1>
            <p class="sbn-prog-lib-page-subtitle">
                Explore the harmonic building blocks of jazz, bossa nova, blues and beyond —
                ranked by how often they appear in the song library.
            </p>

            <div class="sbn-prog-lib-search-wrap">
                <div class="sbn-prog-lib-search-box">
                    <svg class="sbn-prog-search-icon" width="18" height="18" viewBox="0 0 20 20" fill="none">
                        <circle cx="9" cy="9" r="6" stroke="currentColor" stroke-width="1.8"/>
                        <path d="M15 15l3.5 3.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                    </svg>
                    <input type="text"
                           id="sbn-prog-search"
                           class="sbn-prog-search-input"
                           placeholder="Search progressions, degrees (IIm7 V7), keywords…"
                           autocomplete="off">
                    <button class="sbn-prog-search-clear" id="sbn-prog-search-clear" aria-label="Clear search" style="display:none;">
                        <svg width="12" height="12" viewBox="0 0 14 14" fill="none">
                            <path d="M1 1l12 12M13 1L1 13" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                    </button>
                </div>
                <div class="sbn-prog-search-examples">
                    <span>Try:</span>
                    <button class="sbn-prog-example-btn" data-query="ii v i">ii–V–I</button>
                    <button class="sbn-prog-example-btn" data-query="secondary dominant">Secondary dominants</button>
                    <button class="sbn-prog-example-btn" data-query="blues">Blues</button>
                    <button class="sbn-prog-example-btn" data-query="minor subdominant">Minor subdominant</button>
                </div>
            </div>
        </div>

        <?php /* ── Content: list + sidebar ── */ ?>
        <div class="sbn-prog-content-wrapper">

            <?php /* ── Ranked list ── */ ?>
            <div class="sbn-prog-list-container">

                <div class="sbn-prog-list-status" id="sbn-prog-list-status">
                    <span id="sbn-prog-count-text"><?php echo count( $progressions ); ?> progressions</span>
                    <button class="sbn-prog-clear-filters" id="sbn-prog-clear-filters" style="display:none;">
                        Clear filters
                    </button>
                </div>

                <div class="sbn-prog-list" id="sbn-prog-list" role="list">
                    <?php foreach ( $progressions as $rank => $p ) :
                        $numerals   = array_map( 'trim', explode( ',', $p->numerals ) );
                        $song_count = intval( $p->song_count );
                        $cat        = esc_attr( $p->category ?: 'jazz' );
                        $cat_label  = $cat_labels[ $p->category ] ?? ucfirst( $p->category );
                        $tags_arr   = ! empty( $p->tags )
                                      ? array_filter( array_map( 'trim', explode( ',', $p->tags ) ) )
                                      : array();
                        $detail_url = home_url( '/progression/?id=' . intval( $p->id ) );
                    ?>
                    <?php
                        $tonality     = $p->tonality ?? 'both';
                        $ton_label    = $tonality === 'major' ? 'Major' : ( $tonality === 'minor' ? 'Minor' : '' );
                    ?>
                    <div class="sbn-prog-row sbn-prog-cat-<?php echo $cat; ?>"
                         id="prog-<?php echo intval( $p->id ); ?>"
                         data-id="<?php echo intval( $p->id ); ?>"
                         data-rank="<?php echo $rank + 1; ?>"
                         data-category="<?php echo $cat; ?>"
                         data-name="<?php echo esc_attr( strtolower( $p->name ) ); ?>"
                         data-numerals="<?php echo esc_attr( strtolower( $p->numerals ) ); ?>"
                         data-tags="<?php echo esc_attr( strtolower( $p->tags ?? '' ) ); ?>"
                         data-genres="<?php echo esc_attr( strtolower( $p->typical_genres ?? '' ) ); ?>"
                         data-desc="<?php echo esc_attr( strtolower( $p->description ?? '' ) ); ?>"
                         data-song-count="<?php echo $song_count; ?>"
                         data-tonality="<?php echo esc_attr( $p->tonality ?? 'both' ); ?>"
                         role="listitem">

                        <div class="sbn-prog-row-rank"><?php echo $rank + 1; ?></div>

                        <div class="sbn-prog-row-body">
                            <div class="sbn-prog-row-top">
                                <span class="sbn-prog-row-cat-badge sbn-prog-cat-<?php echo $cat; ?>">
                                    <?php echo esc_html( $cat_label ); ?>
                                </span>
                                <?php if ( $ton_label ) : ?>
                                    <span class="sbn-prog-tonality-badge sbn-prog-tonality-<?php echo esc_attr( $tonality ); ?>">
                                        <?php echo esc_html( $ton_label ); ?>
                                    </span>
                                <?php endif; ?>
                                <?php foreach ( $tags_arr as $tag ) : ?>
                                    <span class="sbn-prog-tag-chip"><?php echo esc_html( ucwords( $tag ) ); ?></span>
                                <?php endforeach; ?>
                            </div>

                            <h3 class="sbn-prog-row-title">
                                <a href="<?php echo esc_url( $detail_url ); ?>" class="sbn-prog-row-link">
                                    <?php echo esc_html( $p->name ); ?>
                                </a>
                            </h3>

                            <div class="sbn-prog-row-numerals">
                                <?php foreach ( $numerals as $numeral ) : ?>
                                    <span class="sbn-prog-numeral-chip">
                                        <span class="sbn-chord-symbol"><?php echo sbn_format_numeral( $numeral ); ?></span>
                                    </span>
                                <?php endforeach; ?>
                            </div>

                            <?php
                            // Popularity tier label
                            if ( $song_count >= 10 )     { $tier = 'iconic'; }
                            elseif ( $song_count >= 5 )  { $tier = 'famous'; }
                            elseif ( $song_count >= 2 )  { $tier = 'common'; }
                            elseif ( $song_count === 1 ) { $tier = 'known'; }
                            else                         { $tier = 'rare'; }

                            if ( $song_count > 0 ) :
                            ?>
                                <p class="sbn-prog-row-popularity-phrase">
                                    This is an <span class="sbn-prog-tier-label"><?php echo $tier; ?></span>
                                    chord progression that appears in
                                    <strong><?php echo $song_count; ?> song<?php echo $song_count !== 1 ? 's' : ''; ?></strong>
                                    in the library.
                                </p>
                            <?php endif; ?>

                            <a href="<?php echo esc_url( $detail_url ); ?>" class="sbn-prog-row-read-more">
                                Read more about the <?php echo esc_html( $p->name ); ?> progression
                                <svg width="13" height="13" viewBox="0 0 16 16" fill="none">
                                    <path d="M3 8h10M9 4l4 4-4 4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                            </a>

                        </div>

                    </div>
                    <?php endforeach; ?>
                </div>

                <p class="sbn-prog-lib-empty" id="sbn-prog-lib-empty" style="display:none;">
                    No progressions match your filters.
                </p>

            </div>

            <?php /* ── Filter sidebar ── */ ?>
            <aside class="sbn-prog-filter-sidebar" id="sbn-prog-filter-sidebar">
                <div class="sbn-prog-sidebar-header">
                    <h3>Filter</h3>
                </div>

                <?php /* Style / category filter */ ?>
                <div class="sbn-prog-sidebar-section">
                    <p class="sbn-prog-sidebar-label">Style</p>
                    <div class="sbn-prog-sidebar-options" id="sbn-prog-cat-options">
                        <?php foreach ( $categories as $cat ) :
                            $label = $cat_labels[$cat] ?? ucfirst( $cat ); ?>
                            <button class="sbn-prog-sidebar-option sbn-prog-cat-opt-<?php echo esc_attr( $cat ); ?>"
                                    data-filter="category"
                                    data-value="<?php echo esc_attr( $cat ); ?>">
                                <?php echo esc_html( $label ); ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>

                <?php /* Tag filter — always shown; tags appear once progressions are tagged in admin */ ?>
                <div class="sbn-prog-sidebar-section">
                    <p class="sbn-prog-sidebar-label">Keywords</p>
                    <div class="sbn-prog-sidebar-options" id="sbn-prog-tag-options">
                        <?php if ( ! empty( $all_tags ) ) : ?>
                            <?php foreach ( $all_tags as $tag ) : ?>
                                <button class="sbn-prog-sidebar-option"
                                        data-filter="tag"
                                        data-value="<?php echo esc_attr( strtolower( $tag ) ); ?>">
                                    <?php echo esc_html( ucwords( $tag ) ); ?>
                                </button>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <p class="sbn-prog-tags-empty-note">Add keyword tags to progressions in the admin to enable this filter.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <?php /* Tonality filter */ ?>
                <div class="sbn-prog-sidebar-section">
                    <p class="sbn-prog-sidebar-label">Tonality</p>
                    <div class="sbn-prog-sidebar-options">
                        <button class="sbn-prog-sidebar-option" data-filter="tonality" data-value="major">Major</button>
                        <button class="sbn-prog-sidebar-option" data-filter="tonality" data-value="minor">Minor</button>
                    </div>
                </div>

                <?php /* Sort */ ?>
                <div class="sbn-prog-sidebar-section">
                    <p class="sbn-prog-sidebar-label">Sort by</p>
                    <div class="sbn-prog-sidebar-options" id="sbn-prog-sort-options">
                        <button class="sbn-prog-sidebar-option sbn-sort-active" data-sort="popularity">Most songs</button>
                        <button class="sbn-prog-sidebar-option" data-sort="name">A–Z</button>
                        <button class="sbn-prog-sidebar-option" data-sort="category">Style</button>
                    </div>
                </div>

                <button class="sbn-prog-sidebar-clear" id="sbn-prog-sidebar-clear">
                    Clear all filters
                </button>
            </aside>

        </div>

        <?php /* ── Embedded data for JS ── */ ?>
        <script id="sbn-prog-data" type="application/json">
        <?php echo wp_json_encode( $js_data, JSON_HEX_TAG | JSON_HEX_APOS ); ?>
        </script>

    </div>
    <?php
    return ob_get_clean();
}

// ============================================================================
// SHORTCODE — Detail page  [sbn_progression_detail]
// ============================================================================

add_shortcode( 'sbn_progression_detail', 'sbn_progression_detail_shortcode' );

function sbn_progression_detail_shortcode() {
    if ( ! class_exists( 'SBN_Chord_Progressions' ) ) {
        return '<p>Chord progressions module not available.</p>';
    }

    $id = intval( $_GET['id'] ?? 0 );
    if ( ! $id ) return '<p>No progression specified.</p>';

    $instance = SBN_Chord_Progressions::instance();
    $p        = $instance->get_progression_by_id( $id );
    if ( ! $p ) return '<p>Progression not found.</p>';

    $lib_page  = get_page_by_path( 'chord-progressions' );
    $lib_url   = $lib_page ? get_permalink( $lib_page ) : home_url( '/chord-progressions/' );
    $song_page = get_page_by_path( 'song' );
    $song_url  = $song_page ? get_permalink( $song_page ) : home_url( '/song/' );

    $cat_labels = array(
        'jazz'      => 'Jazz',
        'blues'     => 'Blues',
        'pop'       => 'Pop / Rock',
        'modal'     => 'Modal',
        'latin'     => 'Latin',
        'classical' => 'Classical',
    );

    $cat       = esc_attr( $p->category ?: 'jazz' );
    $cat_label = $cat_labels[ $p->category ] ?? ucfirst( $p->category );
    $numerals  = array_map( 'trim', explode( ',', $p->numerals ) );
    $genres    = $p->typical_genres
                 ? array_filter( array_map( 'trim', explode( ',', $p->typical_genres ) ) )
                 : array();
    $tags      = ! empty( $p->tags )
                 ? array_filter( array_map( 'trim', explode( ',', $p->tags ) ) )
                 : array();

    // ── Song list ────────────────────────────────────────────────────────────
    $songs = $instance->get_leadsheets_for_progression( $id );
    $seen  = array();
    $unique_songs = array();
    foreach ( $songs as $row ) {
        $lid = intval( $row->leadsheet_id );
        if ( isset( $seen[$lid] ) ) continue;
        $seen[$lid]     = true;
        $unique_songs[] = $row;
    }

    // ── Example leadsheet excerpt ─────────────────────────────────────────────
    // Pick the best occurrence: highest confidence, then most songs, then first.
    // Load that leadsheet's shortcode_content, find the matching section,
    // slice start_measure..start_measure+length_measures, and render it.
    $example_html        = '';
    $example_song_title  = '';
    $example_song_url    = '';

    if (
        ! empty( $unique_songs ) &&
        class_exists( 'SBN_Leadsheet' ) &&
        class_exists( 'SBN_Leadsheet_Parser' ) &&
        class_exists( 'SBN_Leadsheet_Renderer' )
    ) {
        // Get occurrence candidates ordered by confidence — we'll iterate until
        // one produces a clean, non-empty slice (skipping cross-section edge cases).
        global $wpdb;
        $ot = $wpdb->prefix . 'sbn_progression_occurrences';
        $lt = $wpdb->prefix . 'sbn_leadsheets';

        $numeral_count = count( array_filter( array_map( 'trim', explode( ',', $p->numerals ) ) ) );

        $candidates = $wpdb->get_results( $wpdb->prepare(
            "SELECT o.*, l.title, l.shortcode_content
             FROM $ot o
             INNER JOIN $lt l ON o.leadsheet_id = l.id
             WHERE o.progression_id = %d
               AND l.shortcode_content IS NOT NULL
               AND l.shortcode_content != ''
               AND o.length_measures >= %d
             ORDER BY o.confidence DESC, o.length_measures DESC
             LIMIT 8",
            $id,
            $numeral_count
        ) );

        $best_occurrence = null;
        foreach ( $candidates as $candidate ) {
            // Parse and check the slice is actually valid for this candidate
            $test_parsed  = SBN_Leadsheet_Parser::parse( $candidate->shortcode_content );
            $test_section = null;
            foreach ( $test_parsed['sections'] as $sec ) {
                if ( $sec['id'] === $candidate->section_id ) {
                    $test_section = $sec;
                    break;
                }
            }
            if ( ! $test_section ) continue;

            $test_start  = intval( $candidate->start_measure );
            $test_length = intval( $candidate->length_measures );
            $test_total  = count( $test_section['measures'] );

            // Skip if start is out of range or slice would be shorter than the pattern
            if ( $test_start >= $test_total ) continue;
            $actual_length = min( $test_length, $test_total - $test_start );
            if ( $actual_length < $numeral_count ) continue;

            // This candidate has a valid full-length slice — use it
            $best_occurrence = $candidate;
            break;
        }

        // Fallback: if no full-length candidate found, take the best available
        if ( ! $best_occurrence && ! empty( $candidates ) ) {
            $best_occurrence = $candidates[0];
        }

        if ( $best_occurrence && ! empty( $best_occurrence->shortcode_content ) ) {
            $parsed = SBN_Leadsheet_Parser::parse( $best_occurrence->shortcode_content );
            $target_section_id = $best_occurrence->section_id;
            $start_m   = intval( $best_occurrence->start_measure );
            $length_m  = max( 1, intval( $best_occurrence->length_measures ) );

            // Find the matching section
            $target_section = null;
            foreach ( $parsed['sections'] as $sec ) {
                if ( $sec['id'] === $target_section_id ) {
                    $target_section = $sec;
                    break;
                }
            }
            // Fallback: first section, use stored length as-is
            if ( ! $target_section && ! empty( $parsed['sections'] ) ) {
                $target_section = $parsed['sections'][0];
                $start_m  = 0;
                $length_m = min( $length_m, count( $target_section['measures'] ) );
            }

            if ( $target_section && ! empty( $target_section['measures'] ) ) {
                // Use the exact progression bounds — no padding.
                // start_measure and length_measures are stored as 0-based measure
                // index + count within the section by the detection engine.
                $total_in_sec   = count( $target_section['measures'] );
                $slice_start    = max( 0, $start_m );
                $slice_length   = min( $length_m, $total_in_sec - $slice_start );
                $slice_measures = array_slice( $target_section['measures'], $slice_start, $slice_length );

                if ( empty( $slice_measures ) ) {
                    // Out-of-range detection result — skip example for this progression
                } else {
                    // Rebuild a minimal shortcode string for just these measures
                    $key        = $parsed['key'] ?? 'C';
                    $title_attr = addslashes( $best_occurrence->title );
                    $rhythm     = $parsed['rhythm'] ?? 'bossa';

                    $measure_lines = array();
                    foreach ( $slice_measures as $measure ) {
                        $chord_names   = array_map( function($c){ return $c['name']; }, $measure['chords'] );
                        $measure_lines[] = '| ' . implode( ' ', $chord_names ) . ' |';
                    }
                    $lines_out = array();
                    foreach ( array_chunk( $measure_lines, 4 ) as $group ) {
                        $lines_out[] = implode( ' ', $group );
                    }

                    $mini_shortcode = '[sbn_leadsheet title="' . $title_attr . '" key="' . esc_attr( $key ) . '" rhythm="' . esc_attr( $rhythm ) . '"]' . "\n"
                        . implode( "\n", $lines_out ) . "\n"
                        . '[/sbn_leadsheet]';

                    $instance_id  = 'prog-example-' . $id;
                    $example_html = SBN_Leadsheet_Renderer::render( $mini_shortcode, $instance_id );

                    $example_song_title = $best_occurrence->title;
                    $example_song_url   = add_query_arg( 'id', intval( $best_occurrence->leadsheet_id ), $song_url );

                    // ── Extract voicing diagram IDs for the builder ───────────────
                    $example_diagram_ids = array();

                    if ( class_exists( 'SBN_Voicing_Crossref' ) ) {
                        $crossref  = SBN_Voicing_Crossref::instance();
                        $vrow_list = $crossref->get_voicings_for_leadsheet( intval( $best_occurrence->leadsheet_id ) );

                        $voicing_map = array();
                        foreach ( $vrow_list as $vrow ) {
                            $key_lc = strtolower( $vrow->chord_name );
                            if ( ! isset( $voicing_map[ $key_lc ] ) ) {
                                $voicing_map[ $key_lc ] = intval( $vrow->chord_diagram_id );
                            }
                        }

                        $prog_measures = array_slice( $target_section['measures'], $start_m, $length_m );
                        $prev_chord    = null;
                        foreach ( $prog_measures as $measure ) {
                            foreach ( $measure['chords'] as $chord ) {
                                $cname = $chord['name'];
                                if ( $cname === $prev_chord ) continue;
                                $prev_chord = $cname;
                                $cname_lc   = strtolower( $cname );
                                $diagram_id = $voicing_map[ $cname_lc ] ?? null;
                                $example_diagram_ids[] = $diagram_id ? $diagram_id : 0;
                            }
                        }

                        $numeral_count_pad = $numeral_count; // already computed above
                        while ( count( $example_diagram_ids ) < $numeral_count_pad ) {
                            $example_diagram_ids[] = 0;
                        }
                        $example_diagram_ids = array_slice( $example_diagram_ids, 0, $numeral_count_pad );
                    }
                } // end else (slice not empty)
            } // end if ( $target_section )
        } // end if ( $best_occurrence )
    } // end if ( ! empty( $unique_songs ) )

    ob_start();
    ?>
    <div class="sbn-prog-detail" id="sbn-prog-detail">

        <?php /* ── Breadcrumb ── */ ?>
        <nav class="sbn-prog-detail-breadcrumb">
            <a href="<?php echo esc_url( $lib_url ); ?>">
                <svg width="14" height="14" viewBox="0 0 16 16" fill="none">
                    <path d="M10 12L6 8l4-4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                Progression Library
            </a>
        </nav>

        <?php /* ── Hero header ── */ ?>
        <div class="sbn-prog-detail-header sbn-prog-cat-<?php echo $cat; ?>">
            <div class="sbn-prog-detail-header-meta">
                <span class="sbn-prog-detail-cat-badge sbn-prog-cat-<?php echo $cat; ?>">
                    <?php echo esc_html( $cat_label ); ?>
                </span>
                <?php if ( ! empty( $p->tonality ) && $p->tonality !== 'both' ) : ?>
                    <span class="sbn-prog-detail-tonality-badge">
                        <?php echo esc_html( ucfirst( $p->tonality ) ); ?>
                    </span>
                <?php endif; ?>
                <?php if ( count( $unique_songs ) > 0 ) : ?>
                    <span class="sbn-prog-detail-song-count">
                        <?php echo count( $unique_songs ); ?> song<?php echo count( $unique_songs ) !== 1 ? 's' : ''; ?>
                    </span>
                <?php endif; ?>
            </div>

            <h1 class="sbn-prog-detail-title"><?php echo esc_html( $p->name ); ?></h1>

            <div class="sbn-prog-detail-numerals">
                <?php foreach ( $numerals as $numeral ) : ?>
                    <span class="sbn-prog-numeral-chip sbn-prog-detail-numeral">
                        <span class="sbn-chord-symbol"><?php echo sbn_format_numeral( $numeral ); ?></span>
                    </span>
                <?php endforeach; ?>
            </div>

            <?php if ( ! empty( $genres ) ) : ?>
                <div class="sbn-prog-detail-genres">
                    <?php foreach ( $genres as $genre ) : ?>
                        <span class="sbn-prog-genre-chip"><?php echo esc_html( ucwords( $genre ) ); ?></span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ( ! empty( $tags ) ) : ?>
                <div class="sbn-prog-detail-tags">
                    <?php foreach ( $tags as $tag ) : ?>
                        <span class="sbn-prog-tag-chip sbn-prog-detail-tag"><?php echo esc_html( ucwords( $tag ) ); ?></span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <?php /* ── Body: main (left) + songs sidebar (right) ── */ ?>
        <div class="sbn-prog-detail-body">

            <?php /* ── Left: description + example ── */ ?>
            <div class="sbn-prog-detail-main">

                <?php if ( $p->description ) : ?>
                <section class="sbn-prog-detail-section">
                    <h2 class="sbn-prog-detail-section-title">About this progression</h2>
                    <div class="sbn-prog-detail-desc">
                        <?php echo wpautop( esc_html( $p->description ) ); ?>
                    </div>
                </section>
                <?php endif; ?>

                <?php if ( $example_html ) : ?>
                <section class="sbn-prog-detail-section">
                    <h2 class="sbn-prog-detail-section-title">Example from the library</h2>
                    <p class="sbn-prog-detail-example-caption">
                        As it appears in
                        <a href="<?php echo esc_url( $example_song_url ); ?>" class="sbn-prog-detail-example-link">
                            <?php echo esc_html( $example_song_title ); ?>
                        </a>
                    </p>
                    <div class="sbn-prog-detail-example-leadsheet">
                        <?php echo $example_html; ?>
                    </div>
                </section>
                <?php endif; ?>

            </div>

            <?php /* ── Right: song list ── */ ?>
            <aside class="sbn-prog-detail-sidebar">
                <section class="sbn-prog-detail-section">
                    <h2 class="sbn-prog-detail-section-title">
                        Songs featuring this progression
                    </h2>
                    <?php if ( ! empty( $unique_songs ) ) : ?>
                    <div class="sbn-prog-detail-songs">
                        <?php foreach ( $unique_songs as $row ) :
                            $url = add_query_arg( 'id', intval( $row->leadsheet_id ), $song_url ); ?>
                            <a class="sbn-prog-song-item" href="<?php echo esc_url( $url ); ?>">
                                <span class="sbn-prog-song-icon">
                                    <svg width="13" height="13" viewBox="0 0 16 16" fill="none">
                                        <rect x="2" y="1" width="10" height="13" rx="1.5" stroke="currentColor" stroke-width="1.8"/>
                                        <path d="M5 5h6M5 8h4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                                    </svg>
                                </span>
                                <span class="sbn-prog-song-title"><?php echo esc_html( $row->title ); ?></span>
                                <?php if ( $row->detected_root ) : ?>
                                    <span class="sbn-prog-song-key">in <?php echo esc_html( $row->detected_root ); ?></span>
                                <?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                    <?php else : ?>
                    <p class="sbn-prog-detail-empty-songs">No songs detected yet. Reprocess your leadsheets to find matches.</p>
                    <?php endif; ?>
                </section>
            </aside>

        </div>

        <?php /* ── Builder: full width, below ── */ ?>
        <section class="sbn-prog-detail-section sbn-prog-detail-builder-full">
            <h2 class="sbn-prog-detail-section-title">Voicing Explorer</h2>
            <?php if ( $example_html && ! empty( $example_diagram_ids ) && max( $example_diagram_ids ) > 0 ) : ?>
            <p class="sbn-prog-detail-builder-intro">
                Starting with the voicings from <strong><?php echo esc_html( $example_song_title ); ?></strong>.
                Change the key or swap any chord to experiment with your own voice leading.
            </p>
            <?php else : ?>
            <p class="sbn-prog-detail-builder-intro">
                Select a key and explore chord voicings for each step of the progression with optimised voice leading.
            </p>
            <?php endif; ?>
            <div id="sbn-prog-detail-builder"
                 data-numerals="<?php echo esc_attr( $p->numerals ); ?>"
                 data-ajax-url="<?php echo esc_attr( admin_url( 'admin-ajax.php' ) ); ?>"
                 data-nonce="<?php echo esc_attr( wp_create_nonce( 'sbn_prog_builder' ) ); ?>"
                 data-initial-key="<?php echo esc_attr( $key ?? 'C' ); ?>"
                 data-diagram-ids="<?php echo esc_attr( wp_json_encode( $example_diagram_ids ?? [] ) ); ?>"
                 data-category="<?php echo esc_attr( $p->category ?? 'jazz' ); ?>"
                 data-tonality="<?php echo esc_attr( $p->tonality ?? 'both' ); ?>">
            </div>
        </section>

    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        if (typeof SbnProgressionBuilder === 'undefined') return;
        var el = document.getElementById('sbn-prog-detail-builder');
        if (!el) return;

        var rawIds = el.dataset.diagramIds;
        var diagramIds = [];
        try { diagramIds = JSON.parse(rawIds || '[]'); } catch(e) {}
        diagramIds = diagramIds.map(function(id) { return id > 0 ? id : null; });

        // Use the example song's actual key so voicings match 1:1
        var initialKey = el.dataset.initialKey || 'C';

        SbnProgressionBuilder.init(el, {
            numerals:          el.dataset.numerals,
            key:               initialKey,
            ajaxUrl:           el.dataset.ajaxUrl,
            nonce:             el.dataset.nonce,
            initialDiagramIds: diagramIds,
            category:          el.dataset.category || '',
            tonality:          el.dataset.tonality || 'both',
        });
    });
    </script>
    <?php
    return ob_get_clean();
}

// ============================================================================
// NUMERAL FORMATTER (unchanged from previous version)
// ============================================================================

function sbn_format_numeral( $numeral ) {
    $numeral = trim( $numeral );
    if ( empty( $numeral ) ) return '';

    if ( preg_match( '/^(b|#)?(VII|VI|IV|V|III|II|I|vii|vi|iv|v|iii|ii|i)(.*)$/u', $numeral, $m ) ) {
        $accidental = $m[1];
        $root       = $m[2];
        $suffix     = $m[3];

        $acc_html = '';
        if ( $accidental === 'b' ) $acc_html = '<span class="sbn-chord-accidental">♭</span>';
        if ( $accidental === '#' ) $acc_html = '<span class="sbn-chord-accidental">♯</span>';

        $root_html   = '<span class="sbn-chord-root">' . $acc_html . esc_html( $root ) . '</span>';
        $suffix_html = $suffix ? sbn_format_chord_suffix( $suffix ) : '';

        return $root_html . $suffix_html;
    }

    return esc_html( $numeral );
}
