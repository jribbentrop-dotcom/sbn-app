<?php
/**
 * Chord Grid Shortcode - Simplified
 * 
 * Supports two modes:
 * 1. Shape-based: [chord diagram="maj7-drop2-roota" root="C"]
 * 2. Manual:      [chord name="Db6/9" frets="4x334x" fingers="201130" position="3"]
 */

if (!defined('ABSPATH')) exit;

add_shortcode('chord', 'sbn_chord_shortcode');

/**
 * Main shortcode handler
 */
function sbn_chord_shortcode($atts) {
    $atts = shortcode_atts([
        'diagram' => '',      // shape slug (e.g., "maj7-drop2-roota")
        'root' => '',         // root note for transposition (e.g., "C", "F#", "Bb")
        'name' => '',         // chord name for manual mode
        'frets' => '',        // fret positions for manual mode (e.g., "x02010")
        'fingering' => '',    // alias for frets
        'fingers' => '',      // finger numbers (e.g., "002010")
        'barre' => '',        // barre fret
        'position' => '1',    // starting fret position for manual mode
        'size' => '100',      // size percentage
    ], $atts);
    
    // Mode 1: Shape-based with transposition
    if (!empty($atts['diagram']) && !empty($atts['root'])) {
        return sbn_render_transposed_shape($atts['diagram'], $atts['root'], $atts['size']);
    }
    
    // Mode 2: Manual frets specification
    if (!empty($atts['frets']) || !empty($atts['fingering'])) {
        return sbn_render_manual_chord($atts);
    }
    
    return '<div class="sbn-chord-error">Invalid chord shortcode. Use diagram+root or frets.</div>';
}

/**
 * Render a shape transposed to a specific root note
 */
function sbn_render_transposed_shape($shape_slug, $root_note, $size = '100') {
    global $wpdb;
    
    // Normalize root note (capitalize first letter)
    $root_note = ucfirst(strtolower($root_note));
    // Handle sharps and flats
    $root_note = str_replace(['#', 'S'], '#', $root_note);
    $root_note = str_replace(['♭', 'B'], 'b', $root_note);
    // Fix: Bb should stay Bb, not BB
    if (strlen($root_note) === 2 && $root_note[1] === 'b' && $root_note[0] !== 'B') {
        // It's a flat like Db, Eb, etc - keep as is
    } elseif ($root_note === 'Bb') {
        // B-flat, keep as is
    }
    
    // Valid root notes
    $valid_roots = ['C', 'C#', 'Db', 'D', 'D#', 'Eb', 'E', 'F', 'F#', 'Gb', 'G', 'G#', 'Ab', 'A', 'A#', 'Bb', 'B'];
    if (!in_array($root_note, $valid_roots)) {
        return '<div class="sbn-chord-error">Invalid root note: ' . esc_html($root_note) . '</div>';
    }
    
    // Get the shape from chord_diagrams table (uses 'slug' column, not 'shape_slug')
    $diagrams_table = $wpdb->prefix . 'sbn_chord_diagrams';
    $shape = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $diagrams_table WHERE slug = %s",
        $shape_slug
    ));
    
    if (!$shape) {
        // Get list of similar shapes to help debug
        $similar = $wpdb->get_col($wpdb->prepare(
            "SELECT slug FROM $diagrams_table WHERE slug LIKE %s LIMIT 5",
            '%' . $wpdb->esc_like(explode('-', $shape_slug)[0]) . '%'
        ));
        $hint = !empty($similar) ? ' Similar: ' . implode(', ', $similar) : '';
        return '<div class="sbn-chord-error">Shape "' . esc_html($shape_slug) . '" not found.' . esc_html($hint) . '</div>';
    }
    
    // Parse stored shape data (relative positions)
    $shape_data = json_decode($shape->diagram_data, true);
    if (!$shape_data || empty($shape_data['positions'])) {
        return '<div class="sbn-chord-error">Invalid shape data</div>';
    }
    
    // Calculate transposition
    $calculated = sbn_calculate_transposed_positions($shape_data, $shape->root_string, $root_note);
    
    if (!$calculated) {
        return '<div class="sbn-chord-error">Calculation failed for ' . esc_html($root_note) . '</div>';
    }
    
    // Build chord name
    $chord_name = $root_note . $shape->quality;
    if (!empty($shape->extensions)) {
        $chord_name .= '(' . $shape->extensions . ')';
    }
    
    // Render the diagram
    return sbn_render_chord_svg($chord_name, $calculated['positions'], $calculated['barres'], 
                                 $shape_data['muted'] ?? [], $shape_data['open'] ?? [],
                                 $calculated['start_fret'], $size);
}

/**
 * Calculate transposed fret positions
 */
function sbn_calculate_transposed_positions($shape_data, $root_string_id, $root_note) {
    // Standard tuning: string number => semitone (C=0)
    // NOTE: In the editor, strings are numbered 1-6 from LEFT to RIGHT
    // On a chord diagram, left = Low E, right = High E
    // So: 1 = Low E, 2 = A, 3 = D, 4 = G, 5 = B, 6 = High E
    $tuning = [
        1 => 4,  // Low E (leftmost in diagram)
        2 => 9,  // A
        3 => 2,  // D
        4 => 7,  // G
        5 => 11, // B
        6 => 4   // High E (rightmost in diagram)
    ];
    
    // Note to semitone
    $note_semitones = [
        'C' => 0, 'C#' => 1, 'Db' => 1,
        'D' => 2, 'D#' => 3, 'Eb' => 3,
        'E' => 4,
        'F' => 5, 'F#' => 6, 'Gb' => 6,
        'G' => 7, 'G#' => 8, 'Ab' => 8,
        'A' => 9, 'A#' => 10, 'Bb' => 10,
        'B' => 11
    ];
    
    // Root string mapping - which string number has the root
    // roote = Low E = string 1 (leftmost)
    // roota = A string = string 2
    // rootd = D string = string 3
    // rootg = G string = string 4
    $root_strings = [
        'roote' => 1,
        'roota' => 2,
        'rootd' => 3,
        'rootg' => 4
    ];
    
    $root_string = $root_strings[$root_string_id] ?? null;
    if (!$root_string) {
        error_log("SBN: Invalid root string: $root_string_id");
        return null;
    }
    
    $root_semitone = $note_semitones[$root_note] ?? null;
    if ($root_semitone === null) {
        error_log("SBN: Invalid root note: $root_note");
        return null;
    }
    
    // Calculate where root note falls on the root string
    $open_string_semitone = $tuning[$root_string];
    $target_fret = ($root_semitone - $open_string_semitone + 12) % 12;
    
    // The shape data stores positions relative to root (root = fret 0)
    // So we just add target_fret to all positions
    $fret_offset = $target_fret;
    
    // Find the reference fret in shape (should be 0 for root string)
    $reference_fret = 0;
    foreach ($shape_data['positions'] as $pos) {
        if ($pos['string'] == $root_string) {
            $reference_fret = $pos['fret'];
            break;
        }
    }
    
    // Adjust offset if shape wasn't stored with root at fret 0
    $fret_offset = $target_fret - $reference_fret;
    
    // Calculate absolute positions
    $positions = [];
    $min_fret = PHP_INT_MAX;
    
    foreach ($shape_data['positions'] as $pos) {
        $absolute_fret = $pos['fret'] + $fret_offset;
        $positions[] = [
            'string' => $pos['string'],
            'fret' => $absolute_fret,
            'finger' => $pos['finger'] ?? null
        ];
        if ($absolute_fret > 0 && $absolute_fret < $min_fret) {
            $min_fret = $absolute_fret;
        }
    }
    
    // Calculate barres
    $barres = [];
    if (!empty($shape_data['barres'])) {
        foreach ($shape_data['barres'] as $barre) {
            $barres[] = [
                'fret' => $barre['fret'] + $fret_offset,
                'fromString' => $barre['fromString'],
                'toString' => $barre['toString'],
                'finger' => $barre['finger'] ?? 1
            ];
        }
    }
    
    $start_fret = ($min_fret === PHP_INT_MAX || $min_fret <= 0) ? 1 : $min_fret;
    
    return [
        'positions' => $positions,
        'barres' => $barres,
        'start_fret' => $start_fret
    ];
}

/**
 * Render manual chord from frets/fingers specification
 */
function sbn_render_manual_chord($atts) {
    // Support 'fingering' as alias for 'frets'
    $frets_input = !empty($atts['frets']) ? $atts['frets'] : $atts['fingering'];
    
    // Parse frets (support both "x02010" and "x,0,2,0,1,0" formats)
    $frets_str = str_replace(',', '', $frets_input);
    $frets_arr = str_split($frets_str);
    
    // Parse fingers
    $fingers_str = str_replace(',', '', $atts['fingers']);
    $fingers_arr = $fingers_str ? str_split($fingers_str) : [];
    
    // Ensure we have 6 strings
    while (count($frets_arr) < 6) $frets_arr[] = 'x';
    
    $position = max(1, intval($atts['position']));
    $barre = $atts['barre'] ? intval($atts['barre']) : 0;
    
    // Convert to positions format
    $positions = [];
    $muted = [];
    $open = [];
    
    for ($s = 0; $s < 6; $s++) {
        $string_num = $s + 1; // 1-indexed
        $fret = strtolower($frets_arr[$s]);
        
        if ($fret === 'x') {
            $muted[] = $string_num;
        } elseif ($fret === '0' || $fret === 'o') {
            $open[] = $string_num;
        } else {
            $fret_num = intval($fret);
            $finger = isset($fingers_arr[$s]) ? $fingers_arr[$s] : null;
            if ($finger === '0') $finger = null;
            
            $positions[] = [
                'string' => $string_num,
                'fret' => $fret_num,
                'finger' => $finger
            ];
        }
    }
    
    // Handle barre
    $barres = [];
    if ($barre > 0) {
        // Find extent of barre
        $barre_strings = [];
        foreach ($positions as $pos) {
            if ($pos['fret'] == $barre) {
                $barre_strings[] = $pos['string'];
            }
        }
        if (count($barre_strings) >= 2) {
            $barres[] = [
                'fret' => $barre,
                'fromString' => min($barre_strings),
                'toString' => max($barre_strings),
                'finger' => 1
            ];
        }
    }
    
    $chord_name = !empty($atts['name']) ? $atts['name'] : 'Chord';
    
    return sbn_render_chord_svg($chord_name, $positions, $barres, $muted, $open, $position, $atts['size']);
}

/**
 * Render chord diagram as SVG
 */
function sbn_render_chord_svg($name, $positions, $barres, $muted, $open, $start_fret, $size = '100') {
    $size_percent = intval($size);
    if ($size_percent < 10) $size_percent = 100;
    
    // Format chord name
    $formatted_name = sbn_format_chord_name($name);
    
    // SVG dimensions
    $width = 110;
    $height = 130;
    $string_spacing = 16;
    $fret_spacing = 22;
    $left_margin = 18;
    $top_margin = 28;
    $num_frets = 4;
    
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $width . ' ' . $height . '" class="sbn-chord-svg">';
    $svg .= '<rect x="0" y="0" width="' . $width . '" height="' . $height . '" fill="transparent"/>';
    
    // Nut or position marker
    if ($start_fret <= 1) {
        $svg .= '<rect x="' . ($left_margin - 1) . '" y="' . ($top_margin - 3) . '" width="' . ($string_spacing * 5 + 2) . '" height="3" fill="#333"/>';
    } else {
        $svg .= '<text x="4" y="' . ($top_margin + $fret_spacing / 2 + 4) . '" font-size="11" fill="#666">' . $start_fret . '</text>';
    }
    
    // Fret lines
    for ($f = 0; $f <= $num_frets; $f++) {
        $y = $top_margin + ($f * $fret_spacing);
        $svg .= '<line x1="' . $left_margin . '" y1="' . $y . '" x2="' . ($left_margin + $string_spacing * 5) . '" y2="' . $y . '" stroke="#999" stroke-width="1"/>';
    }
    
    // String lines
    for ($s = 0; $s < 6; $s++) {
        $x = $left_margin + ($s * $string_spacing);
        $svg .= '<line x1="' . $x . '" y1="' . $top_margin . '" x2="' . $x . '" y2="' . ($top_margin + $fret_spacing * $num_frets) . '" stroke="#666" stroke-width="1"/>';
    }
    
    // Muted strings
    foreach ($muted as $string) {
        $x = $left_margin + (($string - 1) * $string_spacing);
        $svg .= '<text x="' . $x . '" y="' . ($top_margin - 6) . '" font-size="11" text-anchor="middle" fill="#999">✕</text>';
    }
    
    // Open strings
    foreach ($open as $string) {
        $x = $left_margin + (($string - 1) * $string_spacing);
        $svg .= '<circle cx="' . $x . '" cy="' . ($top_margin - 11) . '" r="4.5" fill="none" stroke="#333" stroke-width="1.5"/>';
    }
    
    // Barres
    foreach ($barres as $barre) {
        $barre_fret = $barre['fret'];
        $relative_fret = $barre_fret - $start_fret + 1;
        
        if ($relative_fret > 0 && $relative_fret <= $num_frets) {
            $y = $top_margin + ($relative_fret * $fret_spacing) - ($fret_spacing / 2);
            $x1 = $left_margin + (($barre['fromString'] - 1) * $string_spacing);
            $x2 = $left_margin + (($barre['toString'] - 1) * $string_spacing);
            
            $svg .= '<rect x="' . (min($x1, $x2) - 5) . '" y="' . ($y - 5) . '" width="' . (abs($x2 - $x1) + 10) . '" height="10" rx="5" fill="#e85d3b" opacity="0.85"/>';
        }
    }
    
    // Finger positions
    foreach ($positions as $pos) {
        $string = $pos['string'];
        $fret = $pos['fret'];
        $finger = $pos['finger'];
        
        $x = $left_margin + (($string - 1) * $string_spacing);
        $relative_fret = $fret - $start_fret + 1;
        
        if ($relative_fret > 0 && $relative_fret <= $num_frets) {
            $y = $top_margin + ($relative_fret * $fret_spacing) - ($fret_spacing / 2);
            $svg .= '<circle cx="' . $x . '" cy="' . $y . '" r="6" fill="#e85d3b" opacity="0.85"/>';
            
            if ($finger && $finger !== '0') {
                $svg .= '<text x="' . $x . '" y="' . ($y + 4) . '" font-size="9" font-weight="600" text-anchor="middle" fill="#fff">' . $finger . '</text>';
            }
        }
    }
    
    $svg .= '</svg>';
    
    // Apply size styling
    $size_style = $size_percent != 100 ? ' style="transform: scale(' . ($size_percent / 100) . '); transform-origin: left center; display: inline-block;"' : '';
    
    return '<div class="sbn-inline-chord"' . $size_style . '>
        <div class="sbn-chord">
            <div class="sbn-chord-name">' . $formatted_name . '</div>
            ' . $svg . '
        </div>
    </div>';
}

/**
 * Format chord name with professional typography
 */

function sbn_format_chord_name($name) {
    if (empty($name)) return '';

    // --- FIX: Prevent "Dom" from being parsed as Root "D" ---
    // If the name is exactly "Dom7" (or starts with Dom), strip "Dom"
    // so it becomes just "7" (or "9", etc.) before looking for a root note.
    if (preg_match('/^dom/i', $name)) {
        $name = preg_replace('/^dom/i', '', $name);
    }
    // --------------------------------------------------------
    
    // Identify root note
    $root_pattern = '/^([A-Ga-g])([#♯b♭])?/';
    preg_match($root_pattern, $name, $root_match);
    
    if (empty($root_match)) {
        // No root found - this is just a suffix (e.g., "dom7", "m7", "maj7")
        // Format it as a suffix
        return sbn_format_chord_suffix($name);
    }
    
    $root = strtoupper($root_match[1]);
    $accidental = $root_match[2] ?? '';
    $suffix = substr($name, strlen($root_match[0]));
    
    // Convert accidentals to symbols
    $accidental = str_replace(['#'], ['♯'], $accidental);
    $accidental = str_replace(['b'], ['♭'], $accidental);
    
    // Format suffix
    $formatted_suffix = sbn_format_chord_suffix($suffix);
    
    $html = '<span class="sbn-chord-root">' . esc_html($root);
    if ($accidental) {
        $html .= '<span class="sbn-chord-accidental">' . $accidental . '</span>';
    }
    $html .= '</span>';
    
    if ($formatted_suffix) {
        $html .= $formatted_suffix;
    }
    
    return $html;
}

/**
 * Format chord suffix with proper typography
 * - Converts "dom7" to just "7"
 * - Displays "maj" as "major" ONLY if not followed by number (maj7 stays as maj7)
 * - Displays "min" as "minor" ONLY if not followed by number (min7 stays as m7)
 * - Wraps ONLY standalone extensions (9, 11, 13) in parentheses, NOT 7
 * - Handles special qualities: m7b5, o7, m6, etc.
 * - Quality and core extension (like 7, 6) wrapped together for unified clickability
 */
function sbn_format_chord_suffix($suffix) {
    if (empty($suffix)) return '';
    
    $remaining = $suffix;
    
    // Check for slash chord
    $bass_note = '';
    if (preg_match('/\/([A-Ga-g][#♯b♭]?)$/', $remaining, $bass_match)) {
        $bass_note = $bass_match[1];
        $bass_note = str_replace(['#', 'b'], ['♯', '♭'], $bass_note);
        $remaining = preg_replace('/\/[A-Ga-g][#♯b♭]?$/', '', $remaining);
    }
    
    // Strip "dom" from "dom7", "dom9", "dom13" etc.
if (preg_match('/^dom([0-9]+)/i', $remaining, $matches)) {
    $remaining = str_replace($matches[0], $matches[1], $remaining);
}   
    
    // Build the quality/extension output
    $quality_text = '';      // Quality part (m, maj, °, etc.)
    $core_ext_text = '';     // Core extension (7, 6) that's part of the chord type
    $extra_ext_text = '';    // Additional extensions (9, 11, 13, alterations)
    
    // Check for m7b5 (half-diminished) - special case
    if (preg_match('/^m7b5/i', $remaining)) {
        $quality_text = 'm';
        $core_ext_text = '7♭5';
        $remaining = substr($remaining, 4);
    }
    // Check for m6
    elseif (preg_match('/^m6/i', $remaining)) {
        $quality_text = 'm';
        $core_ext_text = '6';
        $remaining = substr($remaining, 2);
    }
    // Check for minor at start - display as "minor" ONLY if no number follows
    // IMPORTANT: Check 'min' BEFORE 'm' to avoid partial match
    elseif (preg_match('/^(min(?![a-z])|m(?!aj)|-)/i', $remaining, $m)) {
        // Check if followed by number (7, 6, 9, etc.)
        $after_m = substr($remaining, strlen($m[0])); // Use full match length
        if (preg_match('/^[0-9]/', $after_m)) {
            $quality_text = 'm'; // Keep as 'm' if followed by number (m7, m9, etc.)
        } else {
            $quality_text = 'minor'; // Full word if standalone (min, m)
        }
        $remaining = substr($remaining, strlen($m[0])); // Use full match length
    }
    
    // Check for o7 or dim7
    if (preg_match('/^(o7|dim7)/i', $remaining)) {
        $quality_text .= '°';
        $core_ext_text .= '7';
        $remaining = preg_replace('/^(o7|dim7)/i', '', $remaining);
    }
    // Check for dim/diminished (without 7)
    elseif (preg_match('/^(dim|°|o(?![0-9]))/i', $remaining, $dim)) {
        $quality_text .= '°';
        $remaining = substr($remaining, strlen($dim[1]));
    }
    
    // Check for major (maj) - display as "major" ONLY if no number follows
    if (preg_match('/^(maj)(7|6)?/i', $remaining, $maj)) {
        if (!empty($maj[2])) {
            // Has number after maj (maj7, maj6) - keep as "maj"
            $quality_text .= 'maj';
            $core_ext_text .= $maj[2];
            $remaining = substr($remaining, strlen($maj[0]));
        } else {
            // Standalone "maj" - display as "major"
            $quality_text .= 'major';
            $remaining = substr($remaining, 3);
        }
    }
    // Check for standalone 7 (dominant 7)
    elseif (preg_match('/^7/', $remaining)) {
        $core_ext_text .= '7';
        $remaining = substr($remaining, 1);
    }
    // Check for standalone 6
    elseif (preg_match('/^6/', $remaining)) {
        $core_ext_text .= '6';
        $remaining = substr($remaining, 1);
    }
    
    // Check for aug/augmented
    if (preg_match('/^(aug|\+(?![0-9]))/i', $remaining, $aug)) {
        $quality_text .= '+';
        $remaining = substr($remaining, strlen($aug[1]));
    }
    
    // Process remaining as extra extensions
    if (!empty($remaining)) {
        $remaining = str_replace(['#', 'b'], ['♯', '♭'], $remaining);
        $extra_ext_text = '(' . esc_html($remaining) . ')';
    }
    
    // Build output - quality and core extension in ONE span for unified hover
    $output = '';
    
    if (!empty($quality_text) || !empty($core_ext_text)) {
        // Combine quality + core extension in single ext span
        $combined = $quality_text . $core_ext_text;
        $output .= '<span class="sbn-chord-ext">' . $combined . '</span>';
    }
    
    // Extra extensions in separate span
    if (!empty($extra_ext_text)) {
        $output .= '<span class="sbn-chord-ext sbn-chord-ext-extra">' . $extra_ext_text . '</span>';
    }
    
    // Add bass note
    if ($bass_note) {
        $output .= sbn_format_bass_note_html($bass_note);
    }
    
    return $output;
}

/**
 * Format a bass note (or interval) as HTML for slash chord display.
 * 
 * Handles both concrete note names (C, Eb, F#) and interval labels (3, b3, b5, b7).
 * Converts b/# to ♭/♯ symbols and wraps accidentals in proper spans.
 *
 * @param string $bass Raw bass string — either a note name or interval label
 * @return string HTML for the bass portion of a slash chord
 */
function sbn_format_bass_note_html($bass) {
    if (empty($bass)) return '';
    
    // Convert ASCII accidentals to symbols
    $bass = str_replace(array('#', 'b'), array('♯', '♭'), $bass);
    
    // Check if this is a note name (starts with A-G) or an interval (starts with accidental or number)
    if (preg_match('/^([A-G])(♯|♭)?$/u', strtoupper($bass), $m)) {
        // Concrete note name: C, E♭, F♯
        $html = esc_html(strtoupper($m[1]));
        if (!empty($m[2])) {
            $html .= '<span class="sbn-bass-accidental">' . $m[2] . '</span>';
        }
    } else {
        // Interval label: 3, ♭3, ♭5, ♭7, ♯5, 𝄫7, etc.
        // Split accidentals from number
        if (preg_match('/^([♭♯𝄫]+)?(\d+)$/u', $bass, $m)) {
            $html = '';
            if (!empty($m[1])) {
                $html .= '<span class="sbn-bass-accidental">' . $m[1] . '</span>';
            }
            $html .= esc_html($m[2]);
        } else {
            // Fallback: just display as-is
            $html = esc_html($bass);
        }
    }
    
    return '<span class="sbn-chord-bass">/' . $html . '</span>';
}

/**
 * Get the bass interval display label for an archetype (no root note).
 * 
 * Maps quality + inversion to a human-readable interval string
 * (e.g., maj7 + inv1 → "3", m7 + inv1 → "b3").
 * 
 * Uses ASCII accidentals (b, #) — call sbn_format_bass_note_html() 
 * to convert to proper symbols and HTML.
 *
 * @param string $quality  Chord quality (maj7, m7, 7, m7b5, etc.)
 * @param string $inversion Inversion key (root, inv1, inv2, inv3)
 * @return string|null Interval label like "3", "b3", "b7", or null for root position
 */
function sbn_get_bass_interval_label($quality, $inversion) {
    if (empty($inversion) || $inversion === 'root') {
        return null;
    }
    
    // Quality → inversion → interval display label (ASCII accidentals)
    $interval_labels = array(
        'maj7'   => array('inv1' => '3',  'inv2' => '5',  'inv3' => '7'),
        'maj6'   => array('inv1' => '3',  'inv2' => '5',  'inv3' => '6'),
        'm7'     => array('inv1' => 'b3', 'inv2' => '5',  'inv3' => 'b7'),
        'm6'     => array('inv1' => 'b3', 'inv2' => '5',  'inv3' => '6'),
        '7'      => array('inv1' => '3',  'inv2' => '5',  'inv3' => 'b7'),
        'dom7'   => array('inv1' => '3',  'inv2' => '5',  'inv3' => 'b7'),
        'm7b5'   => array('inv1' => 'b3', 'inv2' => 'b5', 'inv3' => 'b7'),
        'o7'     => array('inv1' => 'b3', 'inv2' => 'b5', 'inv3' => 'bb7'),
        'mMaj7'  => array('inv1' => 'b3', 'inv2' => '5',  'inv3' => '7'),
        'aug7'   => array('inv1' => '3',  'inv2' => '#5', 'inv3' => 'b7'),
        'maj'    => array('inv1' => '3',  'inv2' => '5'),
        'min'    => array('inv1' => 'b3', 'inv2' => '5'),
        'aug'    => array('inv1' => '3',  'inv2' => '#5'),
        'dim'    => array('inv1' => 'b3', 'inv2' => 'b5'),
        'sus4'   => array('inv1' => '4',  'inv2' => '5'),
        'sus2'   => array('inv1' => '2',  'inv2' => '5'),
        'add9'   => array('inv1' => '9',  'inv2' => '3', 'inv3' => '5'),
    );
    
    if (isset($interval_labels[$quality][$inversion])) {
        return $interval_labels[$quality][$inversion];
    }
    
    return null;
}
