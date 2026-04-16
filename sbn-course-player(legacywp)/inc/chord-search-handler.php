<?php
/**
 * Chord Search/Calculator AJAX Handler
 * 
 * Handles searches like "Dmaj7", "Bbm7", "F#7" and returns
 * all matching chord shapes transposed to the requested root.
 * 
 * Add this to your plugin's main file or functions.php
 */

if (!defined('ABSPATH')) exit;

/**
 * Register AJAX handlers
 */
add_action('wp_ajax_sbn_search_chords', 'sbn_search_chords_handler');
add_action('wp_ajax_nopriv_sbn_search_chords', 'sbn_search_chords_handler');
add_action('wp_ajax_sbn_get_chord_modal_data', 'sbn_get_chord_modal_data_handler');
add_action('wp_ajax_nopriv_sbn_get_chord_modal_data', 'sbn_get_chord_modal_data_handler');
add_action('wp_ajax_sbn_get_popularity_map', 'sbn_get_popularity_map_handler');
add_action('wp_ajax_nopriv_sbn_get_popularity_map', 'sbn_get_popularity_map_handler');

/**
 * Main AJAX handler for chord search
 */
function sbn_search_chords_handler() {
    // Verify nonce for security
    check_ajax_referer('sbn_chord_search', 'nonce');
    
    $search_query = isset($_POST['query']) ? sanitize_text_field($_POST['query']) : '';
    
    if (empty($search_query)) {
        wp_send_json_error(['message' => 'Empty search query']);
    }
    
    // Parse the chord name
    $parsed = sbn_parse_chord_name($search_query);
    
    if (!$parsed || empty($parsed['root']) || empty($parsed['quality'])) {
        wp_send_json_error([
            'message' => 'Could not parse chord name. Try: C, Cmaj7, Dm7, F#7, Bbm7b5, etc.',
            'query' => $search_query
        ]);
    }
    
    $extension = $parsed['extension'] ?? '';
    $bass_note = $parsed['bass_note'] ?? '';
    
    // DEBUG: Log what we're searching for
    error_log("SBN Chord Search: Looking for root='{$parsed['root']}' quality='{$parsed['quality']}'" . ($extension ? " extension='{$extension}'" : '') . ($bass_note ? " bass_note='{$bass_note}'" : ''));
    
    // Search for matching shapes by base quality (+ bass_note for slash chords)
    $results = sbn_find_and_transpose_shapes($parsed['root'], $parsed['quality'], $extension, $bass_note);
    
    // DEBUG: Log results count
    error_log("SBN Chord Search: Found " . count($results) . " results");
    
    if (empty($results)) {
        // Check if the table has ANY chords with this quality (for debugging)
        global $wpdb;
        $diagrams_table = $wpdb->prefix . 'sbn_chord_diagrams';
        
        $quality_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $diagrams_table WHERE quality = %s",
            $parsed['quality']
        ));
        
        // Also check what qualities exist
        $available_qualities = $wpdb->get_col(
            "SELECT DISTINCT quality FROM $diagrams_table ORDER BY quality"
        );
        
        $debug_msg = "No shapes found for quality '{$parsed['quality']}'. ";
        $debug_msg .= "Database has {$quality_count} shapes with this quality. ";
        if (!empty($available_qualities)) {
            $debug_msg .= "Available qualities: " . implode(', ', $available_qualities);
        } else {
            $debug_msg .= "Table appears to be empty!";
        }
        
        // Slash chord debug: show what's in the DB with bass_note set
        $slash_debug = [];
        if (!empty($bass_note)) {
            $bass_shapes = $wpdb->get_results($wpdb->prepare(
                "SELECT id, root_note, quality, extensions, bass_note, inversion, voicing_category 
                 FROM $diagrams_table WHERE quality = %s AND bass_note != ''",
                $parsed['quality']
            ));
            $slash_debug['bass_shapes_in_db'] = $bass_shapes;
            $slash_debug['searched_quality'] = $parsed['quality'];
            $slash_debug['searched_bass_note'] = $bass_note;
            
            // Also check ALL shapes with bass_note set (any quality)
            $all_bass = $wpdb->get_results(
                "SELECT id, root_note, quality, bass_note FROM $diagrams_table WHERE bass_note != '' AND bass_note IS NOT NULL"
            );
            $slash_debug['all_bass_shapes'] = $all_bass;
        }
        
        error_log("SBN Chord Search: " . $debug_msg);
        
        wp_send_json_error([
            'message' => 'No shapes found for ' . $parsed['root'] . $parsed['quality'] . ($extension ? '(' . $extension . ')' : '') . ($bass_note ? '/' . $bass_note : ''),
            'parsed' => $parsed,
            'debug' => $debug_msg,
            'slash_debug' => $slash_debug,
            'hint' => 'Check that chord shapes are stored as patterns (not specific roots) in the chord_diagrams table.'
        ]);
    }
    
    wp_send_json_success([
        'parsed' => $parsed,
        'results' => $results,
        'count' => count($results)
    ]);
}

/**
 * Parse a chord name into root + quality
 * 
 * Examples:
 * - "Cmaj7" → ['root' => 'C', 'quality' => 'maj7']
 * - "Dm7" → ['root' => 'D', 'quality' => 'm7']
 * - "F#7" → ['root' => 'F#', 'quality' => '7']
 * - "Bbm7b5" → ['root' => 'Bb', 'quality' => 'm7b5']
 * 
 * @param string $input User input
 * @return array|false ['root' => 'C', 'quality' => 'maj7'] or false
 */
function sbn_parse_chord_name($input) {
    $input = trim($input);
    
    // Handle slash chords: "Am/C", "Fmaj7/A", "G7/B"
    // Split on "/" to separate chord name from bass note
    $bass_note = '';
    if (strpos($input, '/') !== false) {
        $parts = explode('/', $input, 2);
        $input = trim($parts[0]);
        $bass_part = trim($parts[1]);
        
        // Validate the bass note
        $valid_roots = ['C', 'C#', 'Db', 'D', 'D#', 'Eb', 'E', 'F', 'F#', 'Gb', 'G', 'G#', 'Ab', 'A', 'A#', 'Bb', 'B'];
        
        // Try 2-char bass note first
        if (strlen($bass_part) >= 2 && in_array(substr($bass_part, 0, 2), $valid_roots)) {
            $bass_note = substr($bass_part, 0, 2);
        } elseif (strlen($bass_part) >= 1 && in_array(strtoupper(substr($bass_part, 0, 1)), $valid_roots)) {
            $bass_note = strtoupper(substr($bass_part, 0, 1));
        }
    }
    
    // Valid root notes (including sharps and flats)
    $valid_roots = ['C', 'C#', 'Db', 'D', 'D#', 'Eb', 'E', 'F', 'F#', 'Gb', 'G', 'G#', 'Ab', 'A', 'A#', 'Bb', 'B'];
    
    // Quality patterns we recognize (from most specific to least)
    // Order matters - check more specific patterns first!
    $quality_patterns = [
        // Extended chords
        'maj13' => 'maj13',
        'maj11' => 'maj11',
        'maj9' => 'maj9',
        'm13' => 'm13',
        'm11' => 'm11',
        'm9' => 'm9',
        '13' => '13',
        '11' => '11',
        '9' => '9',
        
        // Half-diminished (m7b5)
        'm7b5' => 'm7b5',
        'm7♭5' => 'm7b5',
        'ø7' => 'm7b5',          // Common symbol
        'ø' => 'm7b5',
        'half-dim7' => 'm7b5',
        'halfdim7' => 'm7b5',
        
        // Minor-major 7
        'mMaj7' => 'mMaj7',
        'mmaj7' => 'mMaj7',
        'mM7' => 'mMaj7',
        'm(maj7)' => 'mMaj7',
        'min(maj7)' => 'mMaj7',
        
        // Major 7th
        'maj7' => 'maj7',
        'M7' => 'maj7',
        'Δ7' => 'maj7',          // Triangle symbol
        '△7' => 'maj7',
        
        // Major 6th
        'maj6' => 'maj6',
        'M6' => 'maj6',
        '6' => 'maj6',
        
        // Minor 7th
        'min7' => 'm7',
        'm7' => 'm7',
        '-7' => 'm7',            // Jazz notation
        
        // Minor 6th
        'min6' => 'm6',
        'm6' => 'm6',
        '-6' => 'm6',
        
        // Dominant 7th - IMPORTANT: comes after maj7, m7
        'dom7' => 'dom7',        // Full name (matches DB)
        '7' => 'dom7',           // Just "7" = dominant 7
        
        // Augmented
        'aug7' => 'aug7',
        '+7' => 'aug7',
        'aug' => 'aug',
        '+' => 'aug',
        
        // Diminished 7
        'dim7' => 'o7',
        'o7' => 'o7',
        '°7' => 'o7',
        
        // Diminished triad
        'dim' => 'dim',
        'o' => 'dim',
        '°' => 'dim',
        
        // Suspended
        'sus4' => 'sus4',
        'sus2' => 'sus2',
        'sus' => 'sus4',         // Default to sus4
        
        // Add 9
        'add9' => 'add9',
        '(add9)' => 'add9',
        'add2' => 'add9',       // Enharmonic alias
        
        // Power chord
        '5' => '5',
        
        // Major triad
        'maj' => 'maj',
        'M' => 'maj',
        
        // Minor triad
        'minor' => 'min',
        'min' => 'min',
        'm' => 'min',            // IMPORTANT: Single "m" = minor
        '-' => 'min',            // Jazz notation
    ];
    
    // Try to extract root note (1 or 2 characters)
    $root = null;
    
    // Try 2-character roots first (e.g., "Bb", "F#")
    if (strlen($input) >= 2) {
        $potential_root = substr($input, 0, 2);
        if (in_array($potential_root, $valid_roots)) {
            $root = $potential_root;
            $quality_part = substr($input, 2);
        }
    }
    
    // Try 1-character root if 2-char didn't match
    if (!$root && strlen($input) >= 1) {
        $potential_root = strtoupper(substr($input, 0, 1));
        if (in_array($potential_root, $valid_roots)) {
            $root = $potential_root;
            $quality_part = substr($input, 1);
        }
    }
    
    if (!$root) {
        return false; // No valid root found
    }
    
    // Clean up quality part (trim spaces, but preserve case for matching)
    $quality_part = trim($quality_part);
    
    // ── Extension extraction ──
    // DB stores: quality='m7', extensions='9' for a Cm7(9)
    // Fuzzy inputs we need to handle:
    //   m7(9)  m7(b9)  maj7(#11)       — parenthesized
    //   m7 9   m7 b9                    — space-separated
    //   m79    m7b9    7#11             — concatenated (progressive decomposition)
    //   m9     9   13  maj9  -9         — shorthand (m9 → m7+9, 9 → 7+9)
    
    $extension = '';
    
    // 1. Parenthesized: m7(9), m7(b9,#11)
    if (preg_match('/^(.+?)\(([^)]+)\)$/', $quality_part, $ext_match)) {
        $quality_part = $ext_match[1];
        $extension = $ext_match[2];
    }
    // 2. Space-separated: "m7 9", "m7 b9"
    elseif (preg_match('/^(\S+)\s+(.+)$/', $quality_part, $space_match)) {
        $quality_part = $space_match[1];
        $extension = trim($space_match[2]);
    }
    
    // If no quality specified, assume major triad
    if (empty($quality_part)) {
        return ['root' => $root, 'quality' => 'maj', 'extension' => $extension, 'bass_note' => $bass_note];
    }
    
    // ── Shorthand extension map ──
    // These common forms imply base quality + extension
    $shorthand_map = [
        // Dominant extensions
        '9'     => ['dom7', '9'],    '11'    => ['dom7', '11'],   '13'    => ['dom7', '13'],
        'dom9'  => ['dom7', '9'],    'dom11' => ['dom7', '11'],   'dom13' => ['dom7', '13'],
        '7b9'   => ['dom7', 'b9'],   '7#9'   => ['dom7', '#9'],
        '7b13'  => ['dom7', 'b13'],  '7#11'  => ['dom7', '#11'],
        '7b9b13'=> ['dom7', 'b9,b13'], '7b9#11'=> ['dom7', 'b9,#11'],
        // Major extensions
        'maj9'  => ['maj7', '9'],    'maj11' => ['maj7', '11'],   'maj13' => ['maj7', '13'],
        'M9'    => ['maj7', '9'],    'M11'   => ['maj7', '11'],   'M13'   => ['maj7', '13'],
        'Δ9'    => ['maj7', '9'],    '△9'    => ['maj7', '9'],
        'maj7#11'=> ['maj7', '#11'],
        // Minor extensions
        'm9'    => ['m7', '9'],      'm11'   => ['m7', '11'],     'm13'   => ['m7', '13'],
        'min9'  => ['m7', '9'],      'min11' => ['m7', '11'],     'min13' => ['m7', '13'],
        '-9'    => ['m7', '9'],      '-11'   => ['m7', '11'],     '-13'   => ['m7', '13'],
        'm7b9'  => ['m7', 'b9'],
        // Half-dim extensions
        'ø9'    => ['m7b5', '9'],
    ];
    
    // Check shorthand (only if no extension was already extracted)
    if (empty($extension)) {
        // Case-sensitive first
        if (isset($shorthand_map[$quality_part])) {
            $sh = $shorthand_map[$quality_part];
            return ['root' => $root, 'quality' => $sh[0], 'extension' => $sh[1], 'bass_note' => $bass_note];
        }
        // Case-insensitive (skip M/M9/M11/M13 to preserve major vs minor distinction)
        $qp_lower = strtolower($quality_part);
        foreach ($shorthand_map as $pat => $sh) {
            if ($qp_lower === strtolower($pat) && !in_array($pat, ['M9','M11','M13'])) {
                return ['root' => $root, 'quality' => $sh[0], 'extension' => $sh[1], 'bass_note' => $bass_note];
            }
        }
    }
    
    // ── Standard quality matching (without shorthand entries) ──
    $skip_patterns = ['maj13','maj11','maj9','m13','m11','m9','13','11','9'];
    
    // Case-sensitive
    foreach ($quality_patterns as $pattern => $canonical) {
        if (in_array($pattern, $skip_patterns)) continue;
        if ($quality_part === $pattern) {
            return ['root' => $root, 'quality' => $canonical, 'extension' => $extension, 'bass_note' => $bass_note];
        }
    }
    // Case-insensitive
    $quality_lower = strtolower($quality_part);
    foreach ($quality_patterns as $pattern => $canonical) {
        if (in_array($pattern, $skip_patterns)) continue;
        if ($quality_lower === strtolower($pattern) && !in_array($pattern, ['M','M7','M6'])) {
            return ['root' => $root, 'quality' => $canonical, 'extension' => $extension, 'bass_note' => $bass_note];
        }
    }
    
    // ── Progressive decomposition for concatenated extensions ──
    // "m79" → try "m7" + "9", "7b9" → "7" + "b9"
    if (empty($extension) && preg_match('/^(.+?)((?:[b#]?\d+,?)+)$/', $quality_part, $prog)) {
        $base_try = $prog[1];
        $ext_try = rtrim($prog[2], ',');
        // Check base against quality patterns
        foreach ($quality_patterns as $pattern => $canonical) {
            if (in_array($pattern, $skip_patterns)) continue;
            if ($base_try === $pattern || (strtolower($base_try) === strtolower($pattern) && !in_array($pattern, ['M','M7','M6']))) {
                return ['root' => $root, 'quality' => $canonical, 'extension' => $ext_try, 'bass_note' => $bass_note];
            }
        }
    }
    
    // Fallback: return raw quality
    return ['root' => $root, 'quality' => $quality_part, 'extension' => $extension, 'bass_note' => $bass_note];
}

/**
 * Usage examples:
 * 
 * "C" or "Cmaj"     → quality=maj
 * "Cm7"  or "C-7"   → quality=m7
 * "C7"              → quality=dom7
 * "Cm7(9)"          → quality=m7, extension=9
 * "Cm79" or "Cm7 9" → quality=m7, extension=9
 * "Cm9"  or "C-9"   → quality=m7, extension=9
 * "C9"              → quality=dom7, extension=9
 * "Cmaj9"           → quality=maj7, extension=9
 * "C7b9"            → quality=dom7, extension=b9
 * "Cmaj7(#11)"      → quality=maj7, extension=#11
 */

/**
 * Compute effective difficulty for a transposed shape.
 * 
 * If the shape is being played at its stored root (open position for archetypes),
 * return the base difficulty. If transposed to a different root (barré territory),
 * bump by 1 (capped at 5).
 * 
 * @param object $shape  DB row from sbn_chord_diagrams
 * @param string $target_root  The root note the shape is being transposed to
 * @return int  Effective difficulty (0–5, where 0 = unset)
 */
function sbn_compute_transposed_difficulty($shape, $target_root) {
    $base = intval($shape->difficulty ?? 0);
    if ($base === 0) {
        return 0; // Unset — don't invent a difficulty
    }
    
    // Same root = no transposition happening
    if (strtolower($shape->root_note) === strtolower($target_root)) {
        return $base;
    }
    
    // Only bump difficulty for shapes that use open strings.
    // Moveable shapes (drop2, shells, closed, etc.) play identically
    // at any fret — transposition doesn't change their difficulty.
    $diagram_data = json_decode($shape->diagram_data ?? '{}', true);
    $has_open_strings = !empty($diagram_data['open']);
    
    if (!$has_open_strings) {
        return $base;
    }
    
    // Open-string shape transposed = barré: bump by 1, cap at 5
    return min($base + 1, 5);
}

/**
 * Find all shapes matching a quality and transpose them to the root note
 * 
 * This searches the sbn_chord_diagrams table for SHAPE PATTERNS.
 * Shapes are stored with relative positions (root at fret 0).
 * We query by quality only, then transpose each shape to the requested root.
 * 
 * @param string $root_note Root note (e.g., 'C', 'F#', 'Bb')
 * @param string $quality Chord quality (e.g., 'maj7', 'm7', '7')
 * @return array Array of transposed chord data
 */
function sbn_find_and_transpose_shapes($root_note, $quality, $extension = '', $bass_note = '') {
    global $wpdb;
    
    $diagrams_table = $wpdb->prefix . 'sbn_chord_diagrams';
    
    // Load calculator (needed for slash chord analysis and transposition)
    if (!class_exists('SBN_Chord_Shape_Calculator')) {
        require_once dirname(__FILE__) . '/chord-shapes/class-chord-shape-calculator.php';
    }
    $calculator = new SBN_Chord_Shape_Calculator();
    
    // ── Slash chord routing ─────────────────────────────────────────────
    // If a bass_note was parsed from the query (e.g. "F/G"), determine
    // whether it's a standard inversion or a true slash (foreign bass).
    $slash_type       = '';   // 'inversion' | 'slash' | ''
    $slash_inversion  = '';   // 'inv1'|'inv2'|'inv3' for inversions
    $slash_interval   = -1;  // semitones from root to bass
    
    if (!empty($bass_note)) {
        $slash_info = $calculator->analyze_slash_chord($root_note, $quality, $bass_note);
        $slash_type      = $slash_info['type'];      // 'inversion' or 'slash'
        $slash_inversion = $slash_info['inversion'];  // 'inv1' etc. or ''
        $slash_interval  = $slash_info['interval'];   // 0–11
        
        error_log("SBN Chord Search: Slash chord $root_note$quality/$bass_note → type=$slash_type, inversion=$slash_inversion, interval=$slash_interval");
    }
    
    // ── Query shapes from DB ────────────────────────────────────────────
    if ($slash_type === 'slash') {
        // True slash chord (foreign bass): fetch shapes that have a bass_note
        // stored AND whose root→bass interval matches the requested interval.
        // We compute the interval from the shape's own root_note + bass_note.
        $note_to_semi = array(
            'C' => 0, 'C#' => 1, 'Db' => 1, 'D' => 2, 'D#' => 3, 'Eb' => 3,
            'E' => 4, 'F' => 5, 'F#' => 6, 'Gb' => 6, 'G' => 7, 'G#' => 8,
            'Ab' => 8, 'A' => 9, 'A#' => 10, 'Bb' => 10, 'B' => 11
        );
        
        if (!empty($extension)) {
            $slash_shapes = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $diagrams_table 
                 WHERE quality = %s AND extensions = %s AND bass_note != ''
                 ORDER BY voicing_category, root_string, inversion",
                $quality, $extension
            ));
            // Fallback: try without extension
            if (empty($slash_shapes)) {
                $slash_shapes = $wpdb->get_results($wpdb->prepare(
                    "SELECT * FROM $diagrams_table 
                     WHERE quality = %s AND bass_note != ''
                     ORDER BY voicing_category, root_string, inversion",
                    $quality
                ));
            }
        } else {
            $slash_shapes = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $diagrams_table 
                 WHERE quality = %s AND bass_note != ''
                 ORDER BY voicing_category, root_string, inversion",
                $quality
            ));
        }
        
        // Filter to shapes whose stored root→bass interval matches the
        // requested interval. E.g. F/G (interval 2) should not match F/A shapes.
        $shapes = array();
        foreach ($slash_shapes as $s) {
            $s_root_semi = $note_to_semi[$s->root_note] ?? null;
            $s_bass_semi = $note_to_semi[$s->bass_note] ?? null;
            if ($s_root_semi !== null && $s_bass_semi !== null) {
                $s_interval = ($s_bass_semi - $s_root_semi + 12) % 12;
                if ($s_interval === $slash_interval) {
                    $shapes[] = $s;
                }
            }
        }
        
        error_log("SBN Chord Search: True slash — found " . count($shapes) . " shapes with matching interval $slash_interval for quality '$quality'");
        
    } elseif ($slash_type === 'inversion' && !empty($slash_inversion) && $slash_inversion !== 'root') {
        // Standard inversion: prefer shapes tagged with the right inversion,
        // but also include root-position shapes as fallback.
        if (!empty($extension)) {
            $shapes = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $diagrams_table 
                 WHERE quality = %s AND extensions = %s AND inversion = %s AND bass_note = ''
                 ORDER BY voicing_category, root_string",
                $quality, $extension, $slash_inversion
            ));
            if (empty($shapes)) {
                $shapes = $wpdb->get_results($wpdb->prepare(
                    "SELECT * FROM $diagrams_table 
                     WHERE quality = %s AND inversion = %s AND bass_note = ''
                     ORDER BY voicing_category, root_string",
                    $quality, $slash_inversion
                ));
            }
        } else {
            $shapes = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $diagrams_table 
                 WHERE quality = %s AND extensions = '' AND inversion = %s AND bass_note = ''
                 ORDER BY voicing_category, root_string",
                $quality, $slash_inversion
            ));
        }
        
        error_log("SBN Chord Search: Inversion search ($slash_inversion) — found " . count($shapes) . " shapes");
        
    } else {
        // No slash chord — standard search (existing logic)
        // Exclude shapes that have a foreign bass_note set, so plain "Fmaj"
        // doesn't return F/G shapes.
        if (!empty($extension)) {
            $shapes = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $diagrams_table 
                 WHERE quality = %s AND extensions = %s AND bass_note = ''
                 ORDER BY voicing_category, root_string, inversion",
                $quality,
                $extension
            ));
            
            if (empty($shapes)) {
                error_log("SBN Chord Search: No shapes with extension '$extension' for quality '$quality', falling back to base shapes");
                $shapes = $wpdb->get_results($wpdb->prepare(
                    "SELECT * FROM $diagrams_table 
                     WHERE quality = %s AND extensions = '' AND bass_note = ''
                     ORDER BY voicing_category, root_string, inversion",
                    $quality
                ));
            }
        } else {
            $shapes = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $diagrams_table 
                 WHERE quality = %s AND extensions = '' AND bass_note = ''
                 ORDER BY voicing_category, root_string, inversion",
                $quality
            ));
        }
    }
    
    if (empty($shapes)) {
        error_log("SBN Chord Search: No shapes found for quality '$quality'" . ($bass_note ? " bass_note='$bass_note'" : ''));
        return [];
    }
    
    error_log("SBN Chord Search: Found " . count($shapes) . " shapes for quality '$quality', transposing to '$root_note'" . ($bass_note ? " (bass=$bass_note)" : ''));
    
    $results = [];
    
    // Note-to-semitone maps for inversion bass note computation
    $note_to_semitone = array(
        'C' => 0, 'C#' => 1, 'Db' => 1, 'D' => 2, 'D#' => 3, 'Eb' => 3,
        'E' => 4, 'F' => 5, 'F#' => 6, 'Gb' => 6, 'G' => 7, 'G#' => 8,
        'Ab' => 8, 'A' => 9, 'A#' => 10, 'Bb' => 10, 'B' => 11
    );
    $semitone_to_note = array(
        0 => 'C', 1 => 'Db', 2 => 'D', 3 => 'Eb', 4 => 'E', 5 => 'F',
        6 => 'F#', 7 => 'G', 8 => 'Ab', 9 => 'A', 10 => 'Bb', 11 => 'B'
    );
    
    // Transpose each shape to the requested root note
    foreach ($shapes as $shape) {
        // Skip fixed-position shapes when transposing to a different root
        if (!empty($shape->is_fixed_position) && strtolower($shape->root_note) !== strtolower($root_note)) {
            continue;
        }
        
        // Choose transposition method based on slash type
        if ($slash_type === 'slash' && !empty($bass_note) && !empty($shape->bass_note)) {
            // True slash chord: anchor on the bass note
            $calculated = $calculator->calculate_frets_with_bass($shape, $root_note, $bass_note);
        } else {
            // Standard or inversion: anchor on the chord root
            $calculated = $calculator->calculate_frets($shape, $root_note);
        }
        
        if ($calculated && !empty($calculated['diagram_data'])) {
            // Build the plain text name
            $chord_name = $root_note . $shape->quality . ($shape->extensions ?? '');
            
            $inversion = $shape->inversion ?? 'root';
            $bass_note_name = '';
            $bass_interval_label = '';
            
            if ($slash_type === 'slash' && !empty($bass_note)) {
                // True slash chord: use the requested bass note directly
                $bass_note_name = $bass_note;
                $chord_name .= '/' . $bass_note;
                
            } elseif ($inversion !== 'root') {
                // Standard inversion: compute bass note from the interval
                if (function_exists('sbn_get_bass_interval_label')) {
                    $bass_interval_label = sbn_get_bass_interval_label($shape->quality, $inversion) ?? '';
                }
                
                $inversion_intervals = array(
                    'maj7' => array('inv1' => 4, 'inv2' => 7, 'inv3' => 11),
                    'maj6' => array('inv1' => 4, 'inv2' => 7, 'inv3' => 9),
                    'm7'   => array('inv1' => 3, 'inv2' => 7, 'inv3' => 10),
                    'm6'   => array('inv1' => 3, 'inv2' => 7, 'inv3' => 9),
                    'dom7'  => array('inv1' => 4, 'inv2' => 7, 'inv3' => 10),
                    '7'    => array('inv1' => 4, 'inv2' => 7, 'inv3' => 10),
                    'm7b5' => array('inv1' => 3, 'inv2' => 6, 'inv3' => 10),
                    'o7'   => array('inv1' => 3, 'inv2' => 6, 'inv3' => 9),
                    'mMaj7'=> array('inv1' => 3, 'inv2' => 7, 'inv3' => 11),
                    'aug7' => array('inv1' => 4, 'inv2' => 8, 'inv3' => 10),
                    'maj'  => array('inv1' => 4, 'inv2' => 7),
                    'min'  => array('inv1' => 3, 'inv2' => 7),
                    'aug'  => array('inv1' => 4, 'inv2' => 8),
                    'dim'  => array('inv1' => 3, 'inv2' => 6),
                    'sus4' => array('inv1' => 5, 'inv2' => 7),
                    'sus2' => array('inv1' => 2, 'inv2' => 7),
                    'add9' => array('inv1' => 2, 'inv2' => 4, 'inv3' => 7),
                );
                
                $quality_key = $shape->quality;
                if (isset($inversion_intervals[$quality_key][$inversion]) && isset($note_to_semitone[$root_note])) {
                    $root_semi = $note_to_semitone[$root_note];
                    $interval_semi = $inversion_intervals[$quality_key][$inversion];
                    $bass_semi = ($root_semi + $interval_semi) % 12;
                    $bass_note_name = $semitone_to_note[$bass_semi];
                }
                
                if ($bass_note_name) {
                    $chord_name .= '/' . $bass_note_name;
                }
            }
            
            // Format with proper HTML spans for superscripts
            $chord_name_html = sbn_format_chord_name($chord_name);
            
            $results[] = [
                'id' => $shape->id,
                'name' => $chord_name,
                'name_html' => $chord_name_html,
                'slug' => $shape->slug,
                'quality' => $shape->quality,
                'voicing_category' => $shape->voicing_category,
                'root_string' => $shape->root_string,
                'inversion' => $shape->inversion,
                'diagram_data' => json_encode($calculated['diagram_data']),
                'start_fret' => $calculated['start_fret'],
                'interval_labels' => $calculated['interval_labels'],
                'extensions' => $shape->extensions ?? '',
                'notes' => $calculated['notes'] ?? '',
                'root_note' => $root_note,
                'bass_note' => $bass_note_name,
                'bass_interval' => $bass_interval_label,
                'difficulty' => sbn_compute_transposed_difficulty($shape, $root_note),
            ];
        } else {
            error_log("SBN Chord Search: Failed to transpose shape '{$shape->slug}' to '$root_note'" . ($bass_note ? " (bass=$bass_note)" : ''));
        }
    }
    
    error_log("SBN Chord Search: Successfully transposed " . count($results) . " shapes");
    
    return $results;
}

/**
 * AJAX handler for chord info modal data
 * 
 * Returns everything the modal needs in a single request:
 * - Chord analysis (quality info, voicing type, intervals)
 * - Songs that use this specific voicing (from cross-reference)
 * - Related voicings (same quality, different shapes)
 * 
 * Expects POST params: nonce, diagram_id, quality, voicing_category, 
 *                       root_string, inversion, root_note (optional)
 */
function sbn_get_chord_modal_data_handler() {
    check_ajax_referer('sbn_chord_search', 'nonce');
    
    $diagram_id = intval($_POST['diagram_id'] ?? 0);
    $quality = sanitize_text_field($_POST['quality'] ?? '');
    $voicing_category = sanitize_text_field($_POST['voicing_category'] ?? '');
    $root_string = sanitize_text_field($_POST['root_string'] ?? '');
    $inversion = sanitize_text_field($_POST['inversion'] ?? 'root');
    $root_note = sanitize_text_field($_POST['root_note'] ?? '');
    
    $data = array(
        'songs' => array(),
        'related' => array(),
        'popularity' => 0,
    );
    
    // Resolve diagram_id from archetype attributes if not provided directly
    // (archetype cards from initial page load don't carry diagram_id)
    if ($diagram_id === 0 && !empty($quality) && !empty($voicing_category) && !empty($root_string)) {
        global $wpdb;
        $diagrams_table = $wpdb->prefix . 'sbn_chord_diagrams';
        
        $resolved_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $diagrams_table WHERE quality = %s AND voicing_category = %s AND root_string = %s AND inversion = %s LIMIT 1",
            $quality, $voicing_category, $root_string, $inversion
        ));
        
        if ($resolved_id) {
            $diagram_id = intval($resolved_id);
        }
    }
    
    // 1. Songs using this voicing (from cross-reference), grouped by leadsheet
    if ($diagram_id > 0 && class_exists('SBN_Voicing_Crossref')) {
        $crossref = SBN_Voicing_Crossref::instance();
        $raw_songs = $crossref->get_songs_for_diagram($diagram_id);
        
        // Group by leadsheet — collect chord names per song
        $grouped = array();
        foreach ($raw_songs as $song) {
            $key = $song->leadsheet_id;
            if (!isset($grouped[$key])) {
                $grouped[$key] = array(
                    'title' => $song->leadsheet_title,
                    'leadsheet_id' => $song->leadsheet_id,
                    'chord_names' => array(),
                );
            }
            if (!in_array($song->chord_name, $grouped[$key]['chord_names'])) {
                $grouped[$key]['chord_names'][] = $song->chord_name;
            }
        }
        
        foreach ($grouped as $song) {
            $data['songs'][] = array(
                'title' => $song['title'],
                'chord_names' => implode(', ', $song['chord_names']),
                'leadsheet_id' => $song['leadsheet_id'],
            );
        }
    }
    
    // 2. Get popularity count
    if ($diagram_id > 0) {
        global $wpdb;
        $diagrams_table = $wpdb->prefix . 'sbn_chord_diagrams';
        $popularity = $wpdb->get_var($wpdb->prepare(
            "SELECT popularity FROM $diagrams_table WHERE id = %d",
            $diagram_id
        ));
        $data['popularity'] = intval($popularity);
    }
    
    // 3. Related voicings (same quality, different shapes)
    if (!empty($quality)) {
        global $wpdb;
        $diagrams_table = $wpdb->prefix . 'sbn_chord_diagrams';
        
        $where = array("quality = %s");
        $values = array($quality);
        
        // Exclude the current diagram
        if ($diagram_id > 0) {
            $where[] = "id != %d";
            $values[] = $diagram_id;
        }
        
        $sql = "SELECT id, name, slug, voicing_category, root_string, inversion, popularity 
                FROM $diagrams_table 
                WHERE " . implode(' AND ', $where) . "
                ORDER BY popularity DESC, voicing_category, root_string
                LIMIT 3";
        
        $related = $wpdb->get_results($wpdb->prepare($sql, $values));
        
        if ($related) {
            foreach ($related as $r) {
                $related_item = array(
                    'id' => $r->id,
                    'name' => $r->name,
                    'slug' => $r->slug,
                    'voicing_category' => $r->voicing_category,
                    'root_string' => $r->root_string,
                    'inversion' => $r->inversion,
                    'popularity' => intval($r->popularity),
                );
                
                // If a root_note was provided, transpose for display
                // Otherwise, return the stored shape data as-is
                $shape = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM $diagrams_table WHERE id = %d", $r->id
                ));
                
                if ($shape) {
                    if (!empty($root_note)) {
                        if (!class_exists('SBN_Chord_Shape_Calculator')) {
                            require_once dirname(__FILE__) . '/chord-shapes/class-chord-shape-calculator.php';
                        }
                        $calculator = new SBN_Chord_Shape_Calculator();
                        $calculated = $calculator->calculate_frets($shape, $root_note);
                        
                        if ($calculated && !empty($calculated['diagram_data'])) {
                            $related_item['diagram_data'] = json_encode($calculated['diagram_data']);
                            $related_item['start_fret'] = $calculated['start_fret'];
                            $related_item['interval_labels'] = $calculated['interval_labels'];
                            $related_item['notes'] = $calculated['notes'] ?? '';
                        }
                    } else {
                        // No transposition — return the stored shape
                        $related_item['diagram_data'] = $shape->diagram_data;
                        $related_item['start_fret'] = $shape->start_fret;
                        $related_item['interval_labels'] = $shape->interval_labels ?? '';
                    }
                }
                
                $data['related'][] = $related_item;
            }
        }
    }
    
    wp_send_json_success($data);
}

/*
 * OLD CHORD SEARCH ENQUEUE - DEPRECATED
 * 
 * This function loaded the old chord-search.css and chord-search.js files
 * for the old page-chords.php template.
 * 
 * The new redesigned system (chord-library-redesign.css/js) is now used instead.
 * Kept commented for reference. Can be fully deleted after transition period.
 * 
 * New enqueue function: sbn_enqueue_redesigned_chord_library_assets()
 * Located in: sbn-course-player.php (main plugin file)
 */

// DISABLED - Uncomment add_action line below ONLY if you need to rollback
// add_action('wp_enqueue_scripts', 'sbn_chord_search_enqueue_scripts');

function sbn_chord_search_enqueue_scripts() {
    // DISABLED - Old search system deprecated
    // If you need to re-enable temporarily, uncomment the add_action above
    return;
    
    /* OLD CODE - KEPT FOR REFERENCE
    
    // Only load on chord library page
    if (!is_page_template('page-chords.php')) {
        return;
    }
    
    // Get plugin URL (go up one directory from inc/ to plugin root)
    $plugin_url = plugin_dir_url(dirname(__FILE__));
    
    // Enqueue chord diagram admin CSS (has fretboard styles)
    wp_enqueue_style(
        'sbn-chord-diagrams-admin',
        $plugin_url . 'assets/css/chord-diagrams-admin.css',
        [],
        '1.0.0'
    );
    
    // Chord search CSS
    wp_enqueue_style(
        'sbn-chord-search',
        $plugin_url . 'assets/css/chord-search.css',
        ['sbn-chord-diagrams-admin'], // Depend on admin CSS
        '1.0.0'
    );
    
    // Chord search JS (includes rendering fallback)
    wp_enqueue_script(
        'sbn-chord-search',
        $plugin_url . 'assets/js/chord-search.js',
        ['jquery'],
        '1.0.0',
        true
    );
    
    // Pass AJAX URL and nonce to JavaScript
    wp_localize_script('sbn-chord-search', 'sbnChordSearch', [
        'ajaxurl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('sbn_chord_search')
    ]);
    
    */
}

/**
 * Return a lightweight map of { diagram_id => popularity } for all diagrams.
 * Used by the chord library JS to sort cards and stamp data-popularity attributes.
 */
function sbn_get_popularity_map_handler() {
    check_ajax_referer('sbn_chord_search', 'nonce');

    global $wpdb;
    $diagrams_table = $wpdb->prefix . 'sbn_chord_diagrams';

    $rows = $wpdb->get_results(
        "SELECT id, popularity FROM $diagrams_table WHERE popularity > 0",
        ARRAY_A
    );

    $map = array();
    if ($rows) {
        foreach ($rows as $row) {
            $map[ intval($row['id']) ] = intval($row['popularity']);
        }
    }

    wp_send_json_success($map);
}
