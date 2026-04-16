<?php
/**
 * AlphaTeX Shortcode
 * Inline music notation using AlphaTeX syntax
 * 
 * Usage:
 * [alphatex]...[/alphatex]                    - Default: tab only, no play button
 * [alphatex display="tab"]...[/alphatex]      - Tab only
 * [alphatex display="stave"]...[/alphatex]    - Standard notation only
 * [alphatex display="both"]...[/alphatex]     - Both notation and tab
 * [alphatex player="yes"]...[/alphatex]       - With play button
 * [alphatex width="50%"]...[/alphatex]        - Custom width
 */

if (!defined('ABSPATH')) exit;

add_shortcode('alphatex', function($atts, $content = null) {
    $a = shortcode_atts(array(
        'width'   => '100%',
        'display' => 'tab',    // Options: 'tab', 'stave', 'both'
        'player'  => 'no'      // Options: 'yes', 'no'
    ), $atts);

    if (null === $content) {
        return '';
    }

    // Enqueue sheet player assets (includes AlphaTab)
    sbn_enqueue_sheet_player_assets();

    // 1. Decode Entities & Fix Line Breaks
    $content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5);
    $content = preg_replace('/<br\s*\/?>/i', "\n", $content);
    $content = preg_replace('/<\/p>\s*<p>/i', "\n\n", $content);

    // 2. Cleanup
    $content = strip_tags($content);
    $content = str_replace(array("\xc2\xa0", "\xe2\x80\x8b", "&nbsp;"), ' ', $content);
    $content = trim($content);

    // Determine if player is enabled
    $show_player = in_array(strtolower($a['player']), array('yes', 'true', '1'));

    // Build the output HTML
    $output = '<div class="sbn-alphatex-container" data-display="' . esc_attr($a['display']) . '" style="width:' . esc_attr($a['width']) . ';">';
    
    // Play button (only if player enabled)
    if ($show_player) {
        $output .= '<button type="button" class="sbn-alphatex-play-btn" aria-label="Play" title="Play">
            <span class="sbn-alphatex-play-icon"></span>
        </button>';
    }
    
    // AlphaTeX content
    $output .= '<div class="sbn-alphatex-content" style="white-space: pre-wrap;">' . esc_html($content) . '</div>';
    
    $output .= '</div>';

    return $output;
});

// Add CSS for the alphatex shortcode
add_action('wp_head', function() {
    ?>
    <style>
    /* AlphaTeX Shortcode Styles */
    .sbn-alphatex-container {
        position: relative;
        margin: 16px 0;
    }
    
    .sbn-alphatex-content {
        width: 100%;
        min-height: 60px;
        overflow: visible;
    }
    
    /* Play Button */
    .sbn-alphatex-play-btn {
        width: 28px;
        height: 28px;
        border-radius: 50%;
        border: none;
        background: var(--sbn-primary, #e85d3b);
        color: white;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all 0.15s ease;
        position: absolute;
        top: 4px;
        left: 4px;
        z-index: 10;
        padding: 0;
    }
    
    .sbn-alphatex-play-btn:hover {
        background: var(--sbn-primary-dark, #d04a2a);
        transform: scale(1.1);
    }
    
    .sbn-alphatex-play-btn.playing {
        background: var(--sbn-text, #2d2d2d);
    }
    
    /* Play Icon - Triangle */
    .sbn-alphatex-play-icon {
        width: 0;
        height: 0;
        border-style: solid;
        border-width: 5px 0 5px 9px;
        border-color: transparent transparent transparent white;
        margin-left: 2px;
    }
    
    /* Pause Icon - Two bars */
    .sbn-alphatex-play-btn.playing .sbn-alphatex-play-icon {
        border: none;
        width: 10px;
        height: 10px;
        margin-left: 0;
        background: linear-gradient(to right, 
            white 0%, white 35%, 
            transparent 35%, transparent 65%, 
            white 65%, white 100%
        );
    }
    
    /* AlphaTab cursor styling */
    .sbn-alphatex-container .at-cursor-bar {
        background: transparent !important;
    }
    
    .sbn-alphatex-container .at-cursor-beat {
        background: transparent !important;
    }
    
    /* Only highlight the notes in orange */
    .sbn-alphatex-container .at-highlight * {
        fill: var(--sbn-primary, #e85d3b) !important;
    }
    </style>
    <?php
}, 20);
