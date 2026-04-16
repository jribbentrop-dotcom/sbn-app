<?php
/**
 * SBN Leadsheet Renderer
 * 
 * Renders the HTML container for the React leadsheet component
 * and passes parsed data via data attributes.
 * 
 * @package SBN_Course_Player
 * @since 6.5.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SBN_Leadsheet_Renderer {
    
    /**
     * Render the leadsheet component
     * 
     * @param string $content The shortcode content
     * @param string $instance_id Unique ID for this instance
     * @param array  $extra Optional extra data to merge into parsed result (e.g. voicing diagramIds)
     * @return string HTML output
     */
    public static function render($content, $instance_id, $extra = array()) {
        // Parse the content
        $parsed = SBN_Leadsheet_Parser::parse($content);
        
        // Merge voicingMeta into individual chordVoicings entries
        if (!empty($extra['voicingMeta']) && !empty($parsed['chordVoicings'])) {
            foreach ($parsed['chordVoicings'] as $name => &$voicing) {
                $key = $name . '|' . $voicing['frets'];
                if (isset($extra['voicingMeta'][$key])) {
                    $voicing = array_merge($voicing, $extra['voicingMeta'][$key]);
                }
            }
            unset($voicing);
        }

        // Merge all other top-level extra keys directly into parsed
        // (e.g. detectedProgressions, highlightChord, chordLibraryLinks)
        foreach ($extra as $key => $value) {
            if ($key !== 'voicingMeta') {
                $parsed[$key] = $value;
            }
        }
        
        // JSON encode with proper escaping for HTML attribute
        // Use JSON_HEX_TAG, JSON_HEX_APOS, JSON_HEX_QUOT, JSON_HEX_AMP for safe embedding
        $json = wp_json_encode($parsed, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
        
        // Additional safety: ensure single quotes in the attribute don't break
        $json_safe = htmlspecialchars($json, ENT_QUOTES, 'UTF-8');
        
        // Build output
        ob_start();
        ?>
        <div 
            class="sbn-leadsheet-container" 
            id="<?php echo esc_attr($instance_id); ?>"
            data-leadsheet="<?php echo $json_safe; ?>"
        >
            <!-- Loading state -->
            <div class="sbn-leadsheet-loading">
                <div class="sbn-leadsheet-loading-spinner"></div>
                <p>Loading <?php echo esc_html($parsed['title']); ?>...</p>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Render a simple preview (for admin or non-JS environments)
     * 
     * @param string $content The shortcode content
     * @return string HTML output
     */
    public static function render_preview($content) {
        $parsed = SBN_Leadsheet_Parser::parse($content);
        
        ob_start();
        ?>
        <div class="sbn-leadsheet-preview">
            <h3><?php echo esc_html($parsed['title']); ?></h3>
            <p class="sbn-leadsheet-preview-meta">
                <?php if (!empty($parsed['composer'])): ?>
                    <span><?php echo esc_html($parsed['composer']); ?></span>
                <?php endif; ?>
                <span>Key: <?php echo esc_html($parsed['key']); ?></span>
                <span>Tempo: <?php echo esc_html($parsed['tempo']); ?> BPM</span>
                <span>Time: <?php echo esc_html($parsed['timeSignature']); ?></span>
            </p>
            
            <div class="sbn-leadsheet-preview-progression">
                <?php 
                $measureNum = 0;
                foreach ($parsed['sections'] as $section): 
                    foreach ($section['measures'] as $measure):
                        $measureNum++;
                        $chordNames = array_map(function($c) { return $c['name']; }, $measure['chords']);
                ?>
                    <span class="sbn-leadsheet-preview-measure">
                        <?php echo esc_html(implode(' ', $chordNames)); ?>
                    </span>
                <?php 
                    endforeach;
                endforeach; 
                ?>
            </div>
            
            <p class="sbn-leadsheet-preview-count">
                <?php echo esc_html($measureNum); ?> bars, 
                <?php echo esc_html(count($parsed['chordVoicings'])); ?> chord voicings
            </p>
        </div>
        <?php
        return ob_get_clean();
    }
}
