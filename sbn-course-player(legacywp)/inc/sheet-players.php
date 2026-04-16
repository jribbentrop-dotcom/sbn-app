<?php
/**
 * Sheet Player Shortcodes
 * Full and mini sheet music players with AlphaTab
 */

if (!defined('ABSPATH')) exit;

add_shortcode('sheet_player', 'sbn_sheet_player_shortcode');

function sbn_sheet_player_shortcode($atts) {
    $atts = shortcode_atts([
        'file' => '',
        'youtube' => '',
        'start' => '0',
        'sync' => '',
        'title' => '',
        'lesson_id' => '',
        // Display options
        'show_tab' => '1',
        'show_notation' => '0',
        'rhythm' => 'showWithBars',
        'layout' => 'horizontal',
        'bars' => '4',
    ], $atts);
    
    if (empty($atts['file'])) {
        return '<p class="sbn-error">Please specify a file for the sheet player.</p>';
    }
    
    // Enqueue sheet player assets
    sbn_enqueue_sheet_player_assets();
    
    // Check if user is admin (for sync mode)
    $is_admin = current_user_can('manage_options');
    
    // Determine stave profile based on options
    $show_tab = $atts['show_tab'] === '1';
    $show_notation = $atts['show_notation'] === '1';
    
    if ($show_tab && $show_notation) {
        $stave_profile = 'scoreTab'; // Both
    } elseif ($show_notation) {
        $stave_profile = 'score'; // Standard notation only
    } else {
        $stave_profile = 'tab'; // Tab only (default)
    }
    
    // Prepare config
    $config = [
        'file' => esc_url($atts['file']),
        'youtube' => sanitize_text_field($atts['youtube']),
        'start' => intval($atts['start']),
        'sync' => sanitize_text_field($atts['sync']),
        // Display options
        'staveProfile' => $stave_profile,
        'rhythmMode' => sanitize_text_field($atts['rhythm']),
        'layoutMode' => sanitize_text_field($atts['layout']),
        'barsPerRow' => intval($atts['bars']),
        // Pass original values for frontend settings panel
        'showTab' => $show_tab,
        'showNotation' => $show_notation,
    ];
    
    // Add lesson ID for saving (admin only)
    if ($is_admin && !empty($atts['lesson_id'])) {
        $config['lessonId'] = intval($atts['lesson_id']);
    }
    
    $has_video = !empty($atts['youtube']);
    $player_class = 'sbn-sheet-player' . ($has_video ? '' : ' no-video');
    
    // Add AJAX data for admin
    if ($is_admin) {
        wp_localize_script('sbn-sheet-player', 'sbnSheetPlayer', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('sbn_sync_nonce'),
        ]);
    }
    
    ob_start();
    ?>
    <div class="<?php echo esc_attr($player_class); ?>" data-config="<?php echo esc_attr(json_encode($config)); ?>">
        <?php if (!empty($atts['title'])): ?>
            <div class="sbn-sheet-title"><?php echo esc_html($atts['title']); ?></div>
        <?php endif; ?>
        
        <?php if ($has_video): ?>
            <div class="sbn-sheet-video">
                <div class="sbn-sheet-video-placeholder">
                    <div class="sbn-sheet-video-placeholder-icon">▶</div>
                    <span>Loading video...</span>
                </div>
            </div>
        <?php endif; ?>
        
        <div class="sbn-sheet-transport">
            <button class="sbn-sheet-play-btn" type="button">▶</button>
            <div class="sbn-sheet-progress">
                <div class="sbn-sheet-progress-fill"></div>
                <div class="sbn-sheet-progress-handle"></div>
            </div>
            <span class="sbn-sheet-time">0:00 / 0:00</span>
            <div class="sbn-sheet-tempo">
                <span class="sbn-sheet-tempo-icon">♩</span>
                <span class="sbn-sheet-tempo-value">120</span>
                <span>BPM</span>
            </div>
            <?php if ($is_admin && $has_video): ?>
                <button class="sbn-sync-mode-btn" type="button" title="Sync Mode (Admin)">⚡ Sync</button>
            <?php endif; ?>
            <?php if ($is_admin): ?>
                <button class="sbn-settings-mode-btn" type="button" title="Display Settings (Admin)">⚙️</button>
            <?php endif; ?>
        </div>
        
        <div class="sbn-sheet-music">
            <!-- AlphaTab renders here -->
        </div>
        
        <?php if ($is_admin): ?>
        <!-- Admin Panel (Sync + Settings) -->
        <div class="sbn-sync-panel">
            <div class="sbn-sync-panel-header">
                <span class="sbn-sync-panel-title">🎵 Admin Panel</span>
                <span class="sbn-sync-current-bar">Bar 1 / --</span>
            </div>
            <div class="sbn-sync-panel-body">
                <!-- Display Settings Section -->
                <div class="sbn-settings-section">
                    <div class="sbn-settings-row">
                        <label>
                            <input type="checkbox" class="sbn-setting-show-tab" <?php echo $show_tab ? 'checked' : ''; ?>>
                            Show Tablature
                        </label>
                        <label>
                            <input type="checkbox" class="sbn-setting-show-notation" <?php echo $show_notation ? 'checked' : ''; ?>>
                            Show Standard Notation
                        </label>
                    </div>
                    <div class="sbn-settings-row">
                        <label>
                            Rhythm:
                            <select class="sbn-setting-rhythm">
                                <option value="showWithBars" <?php echo $atts['rhythm'] === 'showWithBars' ? 'selected' : ''; ?>>Show with bars</option>
                                <option value="hidden" <?php echo $atts['rhythm'] === 'hidden' ? 'selected' : ''; ?>>Hidden</option>
                            </select>
                        </label>
                        <label>
                            Layout:
                            <select class="sbn-setting-layout">
                                <option value="horizontal" <?php echo $atts['layout'] === 'horizontal' ? 'selected' : ''; ?>>Horizontal</option>
                                <option value="page" <?php echo $atts['layout'] === 'page' ? 'selected' : ''; ?>>Page</option>
                            </select>
                        </label>
                        <label>
                            Bars/Row:
                            <input type="number" class="sbn-setting-bars" value="<?php echo intval($atts['bars']); ?>" min="1" max="16" style="width: 50px;">
                        </label>
                    </div>
                    <div class="sbn-settings-row">
                        <button type="button" class="sbn-apply-settings-btn">🔄 Apply Changes</button>
                        <?php if (!empty($atts['lesson_id'])): ?>
                            <button type="button" class="sbn-save-settings-btn">💾 Save Settings</button>
                        <?php endif; ?>
                    </div>
                </div>
                
                <?php if ($has_video): ?>
                <!-- Sync Section (only if video present) -->
                <div class="sbn-sync-section">
                    <div class="sbn-sync-section-title">🎬 Video Sync</div>
                    <div class="sbn-sync-instructions-inline">
                        Press <kbd>Space</kbd> or <kbd>S</kbd> during playback to mark current bar position
                    </div>
                    <div class="sbn-sync-points-list">
                        <!-- Sync points rendered by JS -->
                    </div>
                    <div class="sbn-sync-message"></div>
                    <div class="sbn-sync-actions">
                        <button type="button" class="sbn-sync-undo-btn">↩ Undo</button>
                        <button type="button" class="sbn-sync-clear-btn">🗑 Clear All</button>
                        <?php if (!empty($atts['lesson_id'])): ?>
                            <button type="button" class="sbn-sync-save-btn">💾 Save Sync</button>
                        <?php endif; ?>
                    </div>
                    <div class="sbn-sync-output">
                        <label>Sync Data:</label>
                        <input type="text" class="sbn-sync-data-output" readonly value="<?php echo esc_attr($atts['sync']); ?>">
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * Sheet Mini Shortcode (Small Inline Player)
 * Usage: [sheet_mini file="example.xml" show_tab="1" show_notation="0" rhythm="showWithBars"]
 */
add_shortcode('sheet_mini', 'sbn_sheet_mini_shortcode');

function sbn_sheet_mini_shortcode($atts) {
    $atts = shortcode_atts([
        'file' => '',
        // Display options
        'show_tab' => '1',
        'show_notation' => '0',
        'rhythm' => 'showWithBars',
    ], $atts);
    
    if (empty($atts['file'])) {
        return '<p class="sbn-error">Please specify a file for the mini player.</p>';
    }
    
    // Enqueue sheet player assets
    sbn_enqueue_sheet_player_assets();
    
    // Determine stave profile based on options
    $show_tab = $atts['show_tab'] === '1';
    $show_notation = $atts['show_notation'] === '1';
    
    if ($show_tab && $show_notation) {
        $stave_profile = 'scoreTab'; // Both
    } elseif ($show_notation) {
        $stave_profile = 'score'; // Standard notation only
    } else {
        $stave_profile = 'tab'; // Tab only (default)
    }
    
    $config = [
        'file' => esc_url($atts['file']),
        'staveProfile' => $stave_profile,
        'rhythmMode' => sanitize_text_field($atts['rhythm']),
        'showTab' => $show_tab,
        'showNotation' => $show_notation,
    ];
    
    ob_start();
    ?>
    <div class="sbn-sheet-mini" data-config="<?php echo esc_attr(json_encode($config)); ?>">
        <button class="sbn-mini-play-btn" type="button" aria-label="Play"></button>
        <div class="sbn-mini-toggle" role="button" tabindex="0" aria-label="Toggle between tabs and notation">
            <span class="sbn-mini-toggle-icon sbn-icon-tab" title="Tablature">♪</span>
            <span class="sbn-mini-toggle-icon sbn-icon-notation" title="Standard Notation">♫</span>
        </div>
        <div class="sbn-mini-sheet">
            <!-- AlphaTab renders here -->
        </div>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * Enqueue Sheet Player Assets
 */
function sbn_enqueue_sheet_player_assets() {
    static $enqueued = false;
    if ($enqueued) return;
    $enqueued = true;
    
  // AlphaTab from CDN
    wp_enqueue_script(
    'alphatab',
    SBN_PLUGIN_URL . 'assets/js/alphaTab.js',  // Local path
    [],
    SBN_VERSION,  // Your version for cache busting
    true
);
    
    // Sheet player CSS & JS
    wp_enqueue_style(
        'sbn-sheet-player',
        SBN_PLUGIN_URL . 'assets/css/sheet-player.css',
        [],
        SBN_VERSION
    );
    
    wp_enqueue_script(
        'sbn-sheet-player',
        SBN_PLUGIN_URL . 'assets/js/sheet-player.js',
        ['alphatab'],
        SBN_VERSION,
        true
    );
}
