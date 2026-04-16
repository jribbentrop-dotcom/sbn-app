<?php
/**
 * Chord Name Styling
 * Professional typography for chord symbols using font ligatures
 */

if (!defined('ABSPATH')) exit;

add_shortcode('chordname', 'sbn_chordname_shortcode');

function sbn_chordname_shortcode($atts, $content = null) {
    if (empty($content)) return '';
    return '<span class="sbn-chord-symbol">' . sbn_format_chord_name(trim($content)) . '</span>';
}

/**
 * Auto-format chord progressions in <pre> blocks
 */
add_filter('the_content', 'sbn_format_chord_progressions', 20);

function sbn_format_chord_progressions($content) {
    // Only process in course player context
    if (!is_singular('sbn_course') && !is_singular('sbn_lesson')) {
        // Also check for shortcode context
        global $post;
        if (!$post || !has_shortcode($post->post_content, 'sbn_course')) {
            return $content;
        }
    }
    
    // Find <pre> blocks that look like chord charts
    $content = preg_replace_callback(
        '/<pre([^>]*)>(.*?)<\/pre>/is',
        function($matches) {
            $attrs = $matches[1];
            $text = $matches[2];
            
            // Skip if already has sbn-chord-chart class
            if (strpos($attrs, 'sbn-chord-chart') !== false) {
                return $matches[0];
            }
            
            // Check if this looks like a chord chart (has | symbols and chord names)
            if (strpos($text, '|') === false || !preg_match('/[A-G][#♯b♭]?/', $text)) {
                return $matches[0];
            }
            
            // Format each line
            $lines = explode("\n", $text);
            $formatted_lines = [];
            
            foreach ($lines as $line) {
                $trimmed = trim($line);
                if (empty($trimmed)) {
                    $formatted_lines[] = '';
                    continue;
                }
                
                // Check if line has chord symbols (contains | and chord-like patterns)
                if (strpos($line, '|') !== false && preg_match('/[A-G][#♯b♭]?[a-zA-Z0-9°+ø♯♭()]*/', $line)) {
                    // Format chords in the line
                    $formatted_line = preg_replace_callback(
                        '/\b([A-G][#♯b♭]?)([a-zA-Z0-9°+ø♯♭()\/]*)\b/u',
                        function($m) {
                            // Skip if it's just a section label like "INTRO" or "VERSE"
                            if (preg_match('/^[A-G]$/', $m[0]) && strlen($m[2]) == 0) {
                                // Could be a single letter chord (rare) or part of a word
                                // Check if preceded by | or whitespace
                                return '<span class="sbn-chart-chord">' . sbn_format_chord_name($m[0]) . '</span>';
                            }
                            $full_chord = $m[1] . $m[2];
                            return '<span class="sbn-chart-chord">' . sbn_format_chord_name($full_chord) . '</span>';
                        },
                        $line
                    );
                    $formatted_lines[] = $formatted_line;
                } else {
                    // Section label or other text
                    if (preg_match('/^[A-Z\s:]+$/i', $trimmed) || preg_match('/^(INTRO|VERSE|CHORUS|BRIDGE|OUTRO|CODA|A SECTION|B SECTION)/i', $trimmed)) {
                        // Remove trailing colons from section labels
                        $label = preg_replace('/:\s*$/', '', $trimmed);
                        $formatted_lines[] = '<span class="sbn-chart-section">' . strtoupper($label) . '</span>';
                    } else {
                        $formatted_lines[] = $line;
                    }
                }
            }
            
            return '<pre class="sbn-chord-chart">' . implode("\n", $formatted_lines) . '</pre>';
        },
        $content
    );
    
    return $content;
}

/**
 * Auto-format chord symbols in text content
 * Automatically detects and formats chord-like patterns (Am7, D7, Gmaj7, etc.)
 * without requiring shortcode wrappers
 */
add_filter('the_content', 'sbn_auto_format_chord_symbols', 25);

function sbn_auto_format_chord_symbols($content) {
    // Only process in course player context
    if (!is_singular('sbn_course') && !is_singular('sbn_lesson')) {
        global $post;
        if (!$post || !has_shortcode($post->post_content, 'sbn_course')) {
            return $content;
        }
    }
    
    // Skip if content is too short or doesn't contain likely chords
    if (strlen($content) < 10 || !preg_match('/[A-G][#b♯♭]?/', $content)) {
        return $content;
    }
    
    // Pattern for chord symbols
    // Matches: A, Am, Am7, Amaj7, Am7b5, Am9, F#m, Db, C/G, etc.
    // Format: Root + Accidental + Quality + Extensions + Alterations + Slash
    $chord_pattern = '/\b([A-G][#b♯♭]?(?:m|min|maj|dim|aug|sus)?[0-9]*(?:[b#♭♯][0-9]|add[0-9])*(?:\/[A-G][#b♯♭]?)?)\b(?![^<]*>)/u';
    
    // Common abbreviations to skip (not chords)
    $skip_words = ['AM', 'PM', 'BMW', 'USA', 'DNA', 'CD', 'TV', 'PC', 'AC', 'DC', 'FM', 'EM'];
    
    // Split content by HTML tags to avoid formatting inside tags
    $parts = preg_split('/(<[^>]+>)/u', $content, -1, PREG_SPLIT_DELIM_CAPTURE);
    
    foreach ($parts as $i => $part) {
        // Skip HTML tags (odd indices after split)
        if ($i % 2 === 1) {
            continue;
        }
        
        // Skip if inside code/pre blocks (check previous parts for opening tags)
        $in_code_block = false;
        for ($j = 0; $j < $i; $j++) {
            if (preg_match('/<(code|pre|script|style)\b/i', $parts[$j])) {
                $in_code_block = true;
            }
            if (preg_match('/<\/(code|pre|script|style)>/i', $parts[$j])) {
                $in_code_block = false;
            }
        }
        if ($in_code_block) {
            continue;
        }
        
        // Skip if already formatted with chord classes
        if (stripos($part, 'sbn-chord-symbol') !== false || 
            stripos($part, 'sbn-chart-chord') !== false) {
            continue;
        }
        
        // Format chord symbols in this text part
        $parts[$i] = preg_replace_callback(
            $chord_pattern,
            function($matches) use ($skip_words) {
                $chord = $matches[1];
                
                // Skip common abbreviations
                if (in_array(strtoupper($chord), $skip_words)) {
                    return $chord;
                }
                
                // Skip if it's just a single letter (likely not a chord)
                if (strlen($chord) === 1) {
                    return $chord;
                }
                
                // Skip common non-chord patterns
                // e.g., "C major scale" shouldn't format "C"
                // But "C major chord" should format "C"
                
                // Format the chord with professional typography
                return '<span class="sbn-chord-symbol">' . sbn_format_chord_name($chord) . '</span>';
            },
            $part
        );
    }
    
    return implode('', $parts);
}

/**
 * Enqueue Google Font for professional chord typography
 */
add_action('wp_enqueue_scripts', 'sbn_enqueue_chord_fonts');

function sbn_enqueue_chord_fonts() {
    if (!is_singular('sbn_course') && !is_singular('sbn_lesson')) {
        global $post;
        if (!$post || !has_shortcode($post->post_content, 'sbn_course')) {
            return;
        }
    }
    
    // Google Font for chord typography
    wp_enqueue_style(
        'sbn-chord-fonts',
        'https://fonts.googleapis.com/css2?family=Crimson+Text:ital,wght@0,400;0,600;0,700;1,400&display=swap',
        [],
        null
    );
    
    // Chord styling CSS
    wp_enqueue_style(
        'sbn-chord-styling',
        SBN_PLUGIN_URL . 'assets/css/chord-styling.css',
        [],
        SBN_VERSION
    );
}

/**
 * Add critical inline CSS (only non-customizable essentials)
 * External CSS file handles all customizable styles
 */
add_action('wp_head', 'sbn_chord_critical_inline_styles', 20);

function sbn_chord_critical_inline_styles() {
    if (!is_singular('sbn_course') && !is_singular('sbn_lesson')) {
        global $post;
        if (!$post || !has_shortcode($post->post_content, 'sbn_course')) {
            return;
        }
    }
    ?>
    <style>
    /* Critical non-customizable chord styles */
    /* All customizable styles are in assets/css/chord-styling.css */
    .sbn-chord-quality { font-style: normal !important; }
    </style>
    <?php
}

/**
 * Rhythm Pattern Shortcode
 * Usage: [rhythm name="Basic Bossa" fingers="..x..x.x" thumb="x...x..."]
 * Pattern: x = hit, . = rest (8 beats per bar)
 */
