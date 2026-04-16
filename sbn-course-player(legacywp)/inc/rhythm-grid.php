<?php
/**
 * Rhythm Grid Shortcode
 * Visual rhythm pattern display with playback
 * Supports both synthesized and MP3 audio playback with blend control
 * 
 * @version 3.2.0
 */

if (!defined('ABSPATH')) exit;

add_shortcode('rhythm', 'sbn_rhythm_shortcode');

function sbn_rhythm_shortcode($atts) {
    $atts = shortcode_atts([
        'name' => '',             // Name (can be auto-loaded from pattern)
        'pattern' => '',          // Pattern slug to load from database
        'rhythm' => '',           // Main rhythm pattern (renamed from fingers)
        'fingers' => '',          // Legacy support for fingers
        'thumb' => '',            // Bass/thumb pattern (optional)
        'beats' => '',            // Number of beats to display
        'sound' => '',            // Synth sound type: clave, guitar, percussion, muted
        'perc_top' => '',         // Percussion sample for fingers/top row
        'perc_bass' => '',        // Percussion sample for thumb/bass row: kick
        'perc_vol' => '70',       // Percussion volume 0-100
        'ghost' => '40',          // Ghost note density 0-100 (0=off, 100=all subdivisions filled)
        'size' => '100',          // Size percentage: 50, 75, 100, 125, 150, etc.
        'mp3' => '',              // Path to MP3 loop file (relative to uploads/sbn-rhythms/)
        'bpm' => '',              // BPM of the MP3 file (required for sync)
        'hihat' => 'off',         // Hi-hat during playback: on/off (default: off)
        'blend' => '0',           // Initial blend: 0 = full synth, 100 = full music
        'oneline' => 'auto',      // Force one-line display: auto, on, off
        'time' => '',             // Time signature: 2/4 or 4/4 (for beat labels)
    ], $atts);
    
    // If pattern slug is provided, load from database
    if (!empty($atts['pattern'])) {
        $rhythmPatterns = SBN_Rhythm_Patterns::instance();
        $dbPattern = $rhythmPatterns->get_pattern_by_slug($atts['pattern']);
        
        if ($dbPattern) {
            // Use database values as defaults, but allow shortcode overrides
            if (empty($atts['name'])) $atts['name'] = $dbPattern->name;
            if (empty($atts['rhythm']) && empty($atts['fingers'])) $atts['rhythm'] = $dbPattern->rhythm_pattern;
            if (empty($atts['thumb'])) $atts['thumb'] = $dbPattern->thumb_pattern;
            if (empty($atts['beats'])) $atts['beats'] = $dbPattern->beats;
            if (empty($atts['sound']))     $atts['sound']     = $dbPattern->sound;
            if (empty($atts['perc_top']))  $atts['perc_top']  = $dbPattern->perc_top  ?? 'none';
            if (empty($atts['perc_bass'])) $atts['perc_bass'] = $dbPattern->perc_bass ?? 'none';
            if (empty($atts['bpm'])) $atts['bpm'] = $dbPattern->default_bpm;
            if (empty($atts['mp3']) && !empty($dbPattern->mp3_file)) $atts['mp3'] = $dbPattern->mp3_file;
            if (empty($atts['time'])) $atts['time'] = $dbPattern->time_signature;
        }
    }
    
    // Apply defaults for any remaining empty values
    if (empty($atts['name'])) $atts['name'] = 'Rhythm';
    if (empty($atts['beats'])) $atts['beats'] = '8';
    if (empty($atts['sound']))     $atts['sound']     = 'clave';
    if (empty($atts['bpm']))       $atts['bpm']       = '120';
    if (empty($atts['time']))      $atts['time']      = '4/4';
    if (empty($atts['perc_top']))  $atts['perc_top']  = 'none';
    if (empty($atts['perc_bass'])) $atts['perc_bass'] = 'none';
    
    $beats = intval($atts['beats']);
    $size_percent = intval($atts['size']);
    $bpm = intval($atts['bpm']);
    $has_mp3 = !empty($atts['mp3']);
    $hihat_enabled = strtolower($atts['hihat']) === 'on';
    $initial_blend = max(0, min(100, intval($atts['blend'])));
    $oneline = strtolower($atts['oneline']);
    $time_sig = $atts['time'];

    // Percussion settings
    $valid_top  = ['shaker','tamborim','hihat-brush','brush-snare'];
    $valid_bass = ['kick'];
    $perc_top   = in_array($atts['perc_top'],  $valid_top)  ? $atts['perc_top']  : 'none';
    $perc_bass  = in_array($atts['perc_bass'], $valid_bass) ? $atts['perc_bass'] : 'none';
    $perc_vol   = max(0, min(100, intval($atts['perc_vol'])));
    $ghost      = max(0, min(100, intval($atts['ghost'])));
    $has_perc   = $perc_top !== 'none' || $perc_bass !== 'none';

    // Samples base URL
    $upload_dir  = wp_upload_dir();
    $samples_url = $upload_dir['baseurl'] . '/sbn-rhythms/samples';
    
    // Parse time signature to determine beat labels
    $time_parts = explode('/', $time_sig);
    $beats_per_bar = intval($time_parts[0]) ?: 4;
    
    // Generate beat labels based on time signature
    // 2/4 with 16th notes: 1e+a2e+a (8 subdivisions per bar)
    // 4/4 with 8th notes: 1+2+3+4+ (8 subdivisions per bar)
    if ($beats_per_bar === 2) {
        // 2/4 time - 16th note subdivisions
        $beat_labels_per_bar = ['1', 'e', '+', 'a', '2', 'e', '+', 'a'];
    } else {
        // 4/4 time (default) - 8th note subdivisions
        $beat_labels_per_bar = ['1', '+', '2', '+', '3', '+', '4', '+'];
    }
    
    // Handle rhythm/fingers - prefer 'rhythm', fallback to 'fingers' for backwards compatibility
    $rhythm_pattern = !empty($atts['rhythm']) ? $atts['rhythm'] : $atts['fingers'];
    if (empty($rhythm_pattern)) {
        $rhythm_pattern = '........';
    }
    
    // Check if thumb pattern has any hits
    $thumb_pattern = $atts['thumb'];
    $has_thumb = !empty($thumb_pattern) && preg_match('/[xX]/', $thumb_pattern);
    
    // Parse patterns
    $rhythm = str_split($rhythm_pattern);
    $thumb = $has_thumb ? str_split($thumb_pattern) : [];
    
    if ($size_percent < 10) $size_percent = 100;
    if ($bpm < 40) $bpm = 120;
    
    // Ensure we have enough beats
    while (count($rhythm) < $beats) $rhythm[] = '.';
    if ($has_thumb) {
        while (count($thumb) < $beats) $thumb[] = '.';
    }
    
    // Build MP3 URL if provided
    $mp3_url = '';
    if ($has_mp3) {
        $upload_dir = wp_upload_dir();
        $mp3_url = $upload_dir['baseurl'] . '/sbn-rhythms/' . $atts['mp3'];
    }
    
    // Apply size styling
    $size_style = $size_percent != 100 ? ' style="transform: scale(' . ($size_percent / 100) . '); transform-origin: left center; display: inline-block;"' : '';
    
    // Determine one-line class
    $oneline_class = '';
    if ($oneline === 'on') {
        $oneline_class = ' sbn-rhythm-oneline';
    } elseif ($oneline === 'auto') {
        $oneline_class = ' sbn-rhythm-oneline-auto';
    }
    
    // Calculate measures per pattern
    $subbeats_per_measure = 8;
    $measures_per_pattern = ceil($beats / $subbeats_per_measure);
    $measures_label = $measures_per_pattern > 1 ? ' <span class="sbn-rhythm-measures">(' . $measures_per_pattern . ' bars)</span>' : '';
    
    // Build the rhythm grid HTML
    $html = '<div class="sbn-inline-rhythm' . $oneline_class . '"' . $size_style . '>';
    $html .= '<div class="sbn-rhythm" ';
    $html .= 'data-rhythm="' . esc_attr($rhythm_pattern) . '" ';
    $html .= 'data-thumb="' . esc_attr($thumb_pattern) . '" ';
    $html .= 'data-has-thumb="' . ($has_thumb ? 'true' : 'false') . '" ';
    $html .= 'data-beats="' . $beats . '" ';
    $html .= 'data-sound="' . esc_attr($atts['sound']) . '" ';
    $html .= 'data-bpm="' . $bpm . '" ';
    $html .= 'data-hihat="' . ($hihat_enabled ? 'on' : 'off') . '" ';
    $html .= 'data-perc-top="' . esc_attr($perc_top) . '" ';
    $html .= 'data-perc-bass="' . esc_attr($perc_bass) . '" ';
    $html .= 'data-perc-vol="' . $perc_vol . '" ';
    $html .= 'data-ghost="' . $ghost . '" ';
    $html .= 'data-samples-url="' . esc_url($samples_url) . '"';
    
    // Add MP3 data if available
    if ($has_mp3) {
        $html .= ' data-mp3="' . esc_url($mp3_url) . '"';
        $html .= ' data-has-mp3="true"';
        $html .= ' data-blend="' . $initial_blend . '"';
    }
    
    $html .= '>';
    
    if ($atts['name']) {
        $html .= '<div class="sbn-rhythm-header">';
        $html .= '<div class="sbn-rhythm-name">' . esc_html($atts['name']) . $measures_label . '</div>';
        
        // Add blend slider if MP3 is available
        if ($has_mp3) {
            $html .= '<div class="sbn-rhythm-blend">';
            $html .= '<span class="sbn-blend-label sbn-blend-synth" title="Synthesized">';
            $html .= '<svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor"><path d="M20 2H4c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zM8 20H6v-8h2v8zm4 0h-2V4h2v16zm4 0h-2v-6h2v6zm4 0h-2v-4h2v4z"/></svg>';
            $html .= '</span>';
            $html .= '<input type="range" class="sbn-blend-slider" min="0" max="100" value="' . $initial_blend . '" aria-label="Blend between synth and music">';
            $html .= '<span class="sbn-blend-label sbn-blend-music" title="Recorded music">';
            $html .= '<svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor"><path d="M12 3v10.55c-.59-.34-1.27-.55-2-.55-2.21 0-4 1.79-4 4s1.79 4 4 4 4-1.79 4-4V7h4V3h-6z"/></svg>';
            $html .= '</span>';
            $html .= '</div>';
        }
        
        $html .= '<button class="sbn-rhythm-play-btn" type="button" aria-label="Play rhythm pattern"><span class="play-icon">▶</span></button>';
        $html .= '</div>';

        // Ghost density slider — only shown when a perc_top sound is set
        if ($has_perc && $perc_top !== 'none') {
            $label_parts = array_filter([$perc_top !== 'none' ? ucfirst(str_replace('-', ' ', $perc_top)) : '', $perc_bass !== 'none' ? 'Kick' : '']);
            $html .= '<div class="sbn-rhythm-perc-ctrl">';
            $html .= '<span class="sbn-perc-icon" title="Ghost note density">🥁</span>';
            $html .= '<span class="sbn-ghost-label sbn-ghost-label-min">basic</span>';
            $html .= '<input type="range" class="sbn-ghost-slider" min="0" max="100" value="' . $ghost . '" aria-label="Ghost note density">';
            $html .= '<span class="sbn-ghost-label sbn-ghost-label-max">full band</span>';
            $html .= '</div>';
        }
    }
    
    $html .= '<div class="sbn-rhythm-grid">';
    
    // Determine beats per line based on oneline setting
    if ($oneline === 'on') {
        $beats_per_line = $beats; // All beats on one line
    } else {
        $beats_per_line = ($beats > 8) ? 8 : $beats;
    }
    $num_lines = ceil($beats / $beats_per_line);
    
    // For multi-measure patterns, we use the proper beat labels per bar
    for ($line = 0; $line < $num_lines; $line++) {
        $start_beat = $line * $beats_per_line;
        $end_beat = min($start_beat + $beats_per_line, $beats);
        $line_class = $num_lines > 1 ? ' sbn-rhythm-line sbn-rhythm-line-' . ($line + 1) : '';
        
        $html .= '<div class="sbn-rhythm-section' . $line_class . '">';
        
        // Beat numbers row - use proper labels based on time signature
        $html .= '<div class="sbn-rhythm-row sbn-rhythm-beats">';
        $html .= '<div class="sbn-rhythm-label"></div>';
        for ($i = $start_beat; $i < $end_beat; $i++) {
            // Calculate position within current bar (0-7)
            $pos_in_bar = $i % 8;
            $beat_label = $beat_labels_per_bar[$pos_in_bar];
            // Add measure separator class for beats at the start of a new measure (except first)
            $separator_class = ($i > 0 && $i % 8 === 0) ? ' is-measure-start' : '';
            $html .= '<div class="sbn-rhythm-beat sbn-rhythm-beat-num' . $separator_class . '" data-beat="' . $i . '">' . $beat_label . '</div>';
        }
        $html .= '</div>';
        
        // Rhythm row (main pattern) - label changes based on whether thumb is present
        $rhythm_label = $has_thumb ? 'Fingers' : 'Rhythm';
        $html .= '<div class="sbn-rhythm-row sbn-rhythm-fingers">';
        $html .= '<div class="sbn-rhythm-label">' . $rhythm_label . '</div>';
        for ($i = $start_beat; $i < $end_beat; $i++) {
            $hit = strtolower($rhythm[$i] ?? '.') === 'x';
            $accent = ($rhythm[$i] ?? '.') === 'X'; // Capital X for accent
            $classes = 'sbn-rhythm-beat';
            if ($hit || $accent) $classes .= ' is-active';
            if ($accent) $classes .= ' is-accent';
            // Add measure separator
            if ($i > 0 && $i % 8 === 0) $classes .= ' is-measure-start';
            $html .= '<div class="' . $classes . '" data-beat="' . $i . '" data-row="rhythm">' . ($hit || $accent ? '●' : '') . '</div>';
        }
        $html .= '</div>';
        
        // Thumb row - only show if has thumb pattern
        if ($has_thumb) {
            $html .= '<div class="sbn-rhythm-row sbn-rhythm-thumb">';
            $html .= '<div class="sbn-rhythm-label">Thumb</div>';
            for ($i = $start_beat; $i < $end_beat; $i++) {
                $hit = strtolower($thumb[$i] ?? '.') === 'x';
                $accent = ($thumb[$i] ?? '.') === 'X'; // Capital X for accent
                $classes = 'sbn-rhythm-beat';
                if ($hit || $accent) $classes .= ' is-active';
                if ($accent) $classes .= ' is-accent';
                // Add measure separator
                if ($i > 0 && $i % 8 === 0) $classes .= ' is-measure-start';
                $html .= '<div class="' . $classes . '" data-beat="' . $i . '" data-row="thumb">' . ($hit || $accent ? '●' : '') . '</div>';
            }
            $html .= '</div>';
        }
        
        $html .= '</div>'; // .sbn-rhythm-section
    }
    
    $html .= '</div>'; // .sbn-rhythm-grid
    $html .= '</div>'; // .sbn-rhythm
    $html .= '</div>'; // .sbn-inline-rhythm
    
    return $html;
}
