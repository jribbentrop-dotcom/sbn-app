<?php
/**
 * Song Library AJAX Handlers
 * 
 * Provides public API endpoints for:
 * - Loading a single leadsheet by ID (for modal/standalone rendering)
 * - Searching/filtering songs (for the song library page)
 * 
 * These endpoints return clean JSON, independent of WordPress rendering.
 * They are the foundation for connecting chord library ↔ song library ↔ rhythm library.
 * 
 * @package SBN_Course_Player
 * @since 7.4.0
 */

if (!defined('ABSPATH')) exit;

/**
 * Register AJAX handlers
 */
add_action('wp_ajax_sbn_get_leadsheet_data', 'sbn_get_leadsheet_data_handler');
add_action('wp_ajax_nopriv_sbn_get_leadsheet_data', 'sbn_get_leadsheet_data_handler');
add_action('wp_ajax_sbn_search_songs', 'sbn_search_songs_handler');
add_action('wp_ajax_nopriv_sbn_search_songs', 'sbn_search_songs_handler');

// =========================================================================
// ENDPOINT 1: Get single leadsheet data
// =========================================================================

/**
 * Return parsed leadsheet data for a given ID.
 * 
 * This is the key endpoint that enables:
 * - "Appears In" links in chord library modal → open the actual leadsheet
 * - Song library page → load and render a selected song
 * - Deep linking: /song-library/?song=42&chord=Dm7
 * 
 * Returns the same JSON structure that the leadsheet JS component expects,
 * so the frontend can render it identically to the shortcode version.
 * 
 * POST params:
 *   - leadsheet_id (int, required)
 *   - highlight_chord (string, optional) — chord name to highlight in the UI
 * 
 * Response: parsed leadsheet object with sections, chords, voicings, melody, etc.
 */
function sbn_get_leadsheet_data_handler() {
    $id = intval($_POST['leadsheet_id'] ?? $_GET['leadsheet_id'] ?? 0);
    
    if (!$id) {
        wp_send_json_error(array('message' => 'Missing leadsheet ID'));
    }
    
    // Load the leadsheet from DB
    if (!class_exists('SBN_Leadsheet')) {
        wp_send_json_error(array('message' => 'Leadsheet module not available'));
    }
    
    $leadsheet = SBN_Leadsheet::instance()->get_leadsheet($id);
    
    if (!$leadsheet) {
        wp_send_json_error(array('message' => 'Leadsheet not found', 'id' => $id));
    }
    
    if (empty($leadsheet->shortcode_content)) {
        wp_send_json_error(array('message' => 'Leadsheet has no content', 'id' => $id));
    }
    
    // Parse the stored shortcode content into structured data
    $parsed = SBN_Leadsheet_Parser::parse($leadsheet->shortcode_content);
    
    // Add metadata that isn't in the parsed shortcode
    $parsed['id'] = intval($leadsheet->id);
    $parsed['slug'] = sanitize_title($leadsheet->title);
    $parsed['composer'] = $parsed['composer'] ?: $leadsheet->composer;
    $parsed['song_key'] = $leadsheet->song_key;
    
    // Optional: which chord to highlight (from chord library cross-link)
    $highlight = sanitize_text_field($_POST['highlight_chord'] ?? $_GET['chord'] ?? '');
    if ($highlight) {
        $parsed['highlightChord'] = $highlight;
    }
    
    // Get chord diagram cross-references for this song
    // This enriches the leadsheet with links back to the chord library
    if (class_exists('SBN_Voicing_Crossref')) {
        $crossref = SBN_Voicing_Crossref::instance();
        $matched_voicings = $crossref->get_voicings_for_leadsheet($id);
        
        $parsed['chordLibraryLinks'] = array();
        foreach ($matched_voicings as $v) {
            $parsed['chordLibraryLinks'][$v->chord_name] = array(
                'diagram_id' => intval($v->chord_diagram_id),
                'quality' => $v->quality,
                'root_note' => $v->root_note,
                'voicing_category' => $v->voicing_category,
            );
        }

        // Also inject shape metadata into chordVoicings so the All Voicings
        // tab can group cards by shape archetype (same voicing type across roots)
        // Additionally fetch interval_labels + notes from the diagrams table
        // for guide tone visualization on the fretboard.
        global $wpdb;
        $diagrams_table = $wpdb->prefix . 'sbn_chord_diagrams';

        foreach ($matched_voicings as $v) {
            $key = $v->chord_name; // chordVoicings is keyed by chord name
            if (isset($parsed['chordVoicings'][$key])) {
                $parsed['chordVoicings'][$key]['voicingCategory'] = $v->voicing_category ?? '';
                $parsed['chordVoicings'][$key]['rootString']      = $v->root_string ?? '';
                $parsed['chordVoicings'][$key]['inversion']       = $v->inversion ?? 'root';
                $parsed['chordVoicings'][$key]['quality']         = $v->quality ?? '';
                $parsed['chordVoicings'][$key]['baseQuality']     = $v->base_quality ?? '';
                $parsed['chordVoicings'][$key]['extensions']      = $v->extensions ?? '';

                // Fetch interval_labels, notes, diagram_data from the diagram record
                if ( ! empty( $v->chord_diagram_id ) ) {
                    $diagram = $wpdb->get_row( $wpdb->prepare(
                        "SELECT interval_labels, notes, diagram_data FROM $diagrams_table WHERE id = %d",
                        $v->chord_diagram_id
                    ) );
                    if ( $diagram ) {
                        $parsed['chordVoicings'][$key]['intervalLabels'] = $diagram->interval_labels ?? '';
                        $parsed['chordVoicings'][$key]['notes']          = $diagram->notes ?? '';
                        $parsed['chordVoicings'][$key]['diagramData']    = $diagram->diagram_data ?? '';
                    }
                }
            }
        }
    }
    
    // Inject detected chord progressions (keyed by section_id)
    // This mirrors the injection done in the shortcode path in class-leadsheet.php
    if (class_exists('SBN_Chord_Progressions')) {
        $prog_crossref = SBN_Chord_Progressions::instance();
        $grouped = $prog_crossref->get_occurrences_for_leadsheet($id);
        if (!empty($grouped)) {
            $parsed['detectedProgressions'] = $grouped;
        }
    }

    wp_send_json_success($parsed);
}

// =========================================================================
// ENDPOINT 2: Search / filter songs
// =========================================================================

/**
 * Search and filter songs for the song library page.
 * 
 * Returns summary data for each matching song (not the full parsed leadsheet).
 * The full leadsheet is loaded on demand via sbn_get_leadsheet_data.
 * 
 * POST params (all optional — omit for "show all"):
 *   - query (string)        — free text search in title + composer
 *   - song_key (string)     — filter by key, e.g., "Am", "C"
 *   - rhythm (string)       — filter by rhythm slug, e.g., "bossa-nova"
 *   - chord (string)        — filter by chord name used, e.g., "Dm7"
 *   - chord_quality (string)— filter by chord quality used, e.g., "m7"
 *   - tempo_min (int)       — minimum tempo
 *   - tempo_max (int)       — maximum tempo
 *   - sort (string)         — sort field: "title" (default), "composer", "tempo", "key"
 *   - order (string)        — "asc" (default) or "desc"
 * 
 * Response: array of song summary objects
 */
function sbn_search_songs_handler() {
    global $wpdb;
    
    $table = $wpdb->prefix . 'sbn_leadsheets';
    $usage_table = $wpdb->prefix . 'sbn_voicing_usage';
    
    $where = array('1=1');
    $values = array();
    
    // --- Text search (title or composer) ---
    $query = sanitize_text_field($_POST['query'] ?? '');
    if (!empty($query)) {
        $like = '%' . $wpdb->esc_like($query) . '%';
        $where[] = '(l.title LIKE %s OR l.composer LIKE %s)';
        $values[] = $like;
        $values[] = $like;
    }
    
    // --- Filter by key ---
    $song_key = sanitize_text_field($_POST['song_key'] ?? '');
    if (!empty($song_key)) {
        $where[] = 'l.song_key = %s';
        $values[] = $song_key;
    }
    
    // --- Filter by rhythm ---
    $rhythm = sanitize_text_field($_POST['rhythm'] ?? '');
    if (!empty($rhythm)) {
        $where[] = 'l.rhythm = %s';
        $values[] = $rhythm;
    }
    
    // --- Filter by difficulty ---
    $difficulty = sanitize_text_field($_POST['difficulty'] ?? '');
    if (!empty($difficulty)) {
        $where[] = 'l.difficulty = %s';
        $values[] = $difficulty;
    }
    
    // --- Filter by genre ---
    $genre = sanitize_text_field($_POST['genre'] ?? '');
    if (!empty($genre)) {
        $where[] = 'l.genre = %s';
        $values[] = $genre;
    }
    
    // --- Filter by specific chord name (e.g., "Dm7") ---
    $chord = sanitize_text_field($_POST['chord'] ?? '');
    if (!empty($chord)) {
        $where[] = "l.id IN (
            SELECT DISTINCT leadsheet_id FROM $usage_table 
            WHERE chord_name = %s
        )";
        $values[] = $chord;
    }
    
    // --- Filter by chord quality (e.g., "m7" — any root) ---
    $chord_quality = sanitize_text_field($_POST['chord_quality'] ?? '');
    if (!empty($chord_quality)) {
        $where[] = "l.id IN (
            SELECT DISTINCT leadsheet_id FROM $usage_table 
            WHERE quality = %s
        )";
        $values[] = $chord_quality;
    }
    
    // --- Filter by tempo range ---
    $tempo_min = intval($_POST['tempo_min'] ?? 0);
    $tempo_max = intval($_POST['tempo_max'] ?? 0);
    if ($tempo_min > 0) {
        $where[] = 'l.tempo >= %d';
        $values[] = $tempo_min;
    }
    if ($tempo_max > 0) {
        $where[] = 'l.tempo <= %d';
        $values[] = $tempo_max;
    }
    
    // --- Sorting ---
    $valid_sorts = array('title', 'composer', 'tempo', 'song_key', 'measure_count', 'created_at');
    $sort = sanitize_text_field($_POST['sort'] ?? 'title');
    if (!in_array($sort, $valid_sorts)) {
        $sort = 'title';
    }
    $order = strtoupper(sanitize_text_field($_POST['order'] ?? 'ASC'));
    if ($order !== 'DESC') {
        $order = 'ASC';
    }
    
    // --- Build and execute query ---
    $sql = "SELECT l.id, l.title, l.composer, l.song_key, l.tempo, 
                   l.time_signature, l.rhythm, l.measure_count, l.description, l.difficulty, l.genre, l.popularity
            FROM $table l
            WHERE " . implode(' AND ', $where) . "
            ORDER BY l.$sort $order";
    
    if (!empty($values)) {
        $results = $wpdb->get_results($wpdb->prepare($sql, $values));
    } else {
        $results = $wpdb->get_results($sql);
    }
    
    if ($results === null) {
        wp_send_json_error(array('message' => 'Database error', 'error' => $wpdb->last_error));
    }
    
    // --- Enrich each song with chord summary ---
    foreach ($results as &$song) {
        // Get the top 3 most frequently used chords in this song
        $chords = $wpdb->get_results($wpdb->prepare(
            "SELECT chord_name, COUNT(*) as usage_count 
             FROM $usage_table 
             WHERE leadsheet_id = %d 
             GROUP BY chord_name 
             ORDER BY usage_count DESC 
             LIMIT 3",
            $song->id
        ));
        
        $song->chords_used = array_map(function($c) { return $c->chord_name; }, $chords);
        
        // Get rhythm pattern name (if available)
        if (!empty($song->rhythm) && class_exists('SBN_Rhythm_Patterns')) {
            $rhythm_data = SBN_Rhythm_Patterns::instance()->get_rhythm_data($song->rhythm);
            $song->rhythm_name = $rhythm_data ? $rhythm_data['name'] : $song->rhythm;
        } else {
            $song->rhythm_name = $song->rhythm;
        }
    }
    
    // --- Return metadata for the filter UI ---
    $meta = array();
    
    // All available genres
    $meta['available_genres'] = $wpdb->get_col("SELECT DISTINCT genre FROM $table WHERE genre != '' AND genre IS NOT NULL ORDER BY genre");
    
    // All available keys (for filter dropdown)
    $meta['available_keys'] = $wpdb->get_col("SELECT DISTINCT song_key FROM $table WHERE song_key != '' ORDER BY song_key");
    
    // All available rhythms
    $meta['available_rhythms'] = $wpdb->get_col("SELECT DISTINCT rhythm FROM $table WHERE rhythm != '' ORDER BY rhythm");
    
    // All available difficulties
    $meta['available_difficulties'] = $wpdb->get_col("SELECT DISTINCT difficulty FROM $table WHERE difficulty != '' ORDER BY 
        CASE difficulty
            WHEN 'basic' THEN 1
            WHEN 'early-intermediate' THEN 2
            WHEN 'intermediate' THEN 3
            WHEN 'late-intermediate' THEN 4
            WHEN 'advanced' THEN 5
            ELSE 6
        END");
    
    // Total count (before filtering)
    $meta['total_songs'] = intval($wpdb->get_var("SELECT COUNT(*) FROM $table"));
    
    wp_send_json_success(array(
        'songs' => $results,
        'meta' => $meta,
        'filters_applied' => array(
            'query' => $query,
            'song_key' => $song_key,
            'rhythm' => $rhythm,
            'chord' => $chord,
            'chord_quality' => $chord_quality,
        ),
    ));
}
