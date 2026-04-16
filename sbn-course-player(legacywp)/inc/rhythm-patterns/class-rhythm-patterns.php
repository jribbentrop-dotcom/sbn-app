<?php
/**
 * SBN Rhythm Patterns - Main Class
 * 
 * Handles admin pages, database operations, and shortcode integration
 * for reusable rhythm patterns.
 * 
 * @package SBN_Course_Player
 * @since 6.6.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SBN_Rhythm_Patterns {
    
    /**
     * Database table name (without prefix)
     */
    const TABLE_NAME = 'sbn_rhythm_patterns';
    
    /**
     * Plugin version for this module
     */
    const VERSION = '1.0.0';
    
    /**
     * Singleton instance
     */
    private static $instance = null;
    
    /**
     * Cached patterns
     */
    private $patterns_cache = null;
    
    /**
     * Get singleton instance
     */
    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor - hook everything
     */
    private function __construct() {
        // Admin hooks
        if (is_admin()) {
            add_action('admin_menu', array($this, 'add_admin_menu'));
            add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
            
            // AJAX handlers
            add_action('wp_ajax_sbn_save_rhythm_pattern', array($this, 'ajax_save_pattern'));
            add_action('wp_ajax_sbn_delete_rhythm_pattern', array($this, 'ajax_delete_pattern'));
            add_action('wp_ajax_sbn_get_rhythm_patterns', array($this, 'ajax_get_patterns'));
        }

        // Frontend AJAX — song lookup for rhythm library modal (logged in + out)
        add_action('wp_ajax_sbn_get_rhythm_songs',        array($this, 'ajax_get_rhythm_songs'));
        add_action('wp_ajax_nopriv_sbn_get_rhythm_songs', array($this, 'ajax_get_rhythm_songs'));

        // Enqueue nonce for rhythm library page
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_nonce'));
    }
    
    /**
     * Get full table name with prefix
     */
    public static function get_table_name() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_NAME;
    }
    
    // =========================================================================
    // DATABASE
    // =========================================================================
    
    /**
     * Create database table on activation
     */
    public static function activate() {
        global $wpdb;
        
        $table = self::get_table_name();
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            slug varchar(50) NOT NULL,
            name varchar(100) NOT NULL,
            description text,
            category varchar(50) DEFAULT 'general',
            time_signature varchar(10) DEFAULT '4/4',
            beats int(11) DEFAULT 8,
            grid_type varchar(20) DEFAULT 'sixteenth',
            rhythm_pattern varchar(32) NOT NULL,
            thumb_pattern varchar(32) DEFAULT '',
            default_bpm int(11) DEFAULT 120,
            sound varchar(20) DEFAULT 'clave',
            perc_top varchar(20) DEFAULT 'none',
            perc_bass varchar(20) DEFAULT 'none',
            mp3_file varchar(255) DEFAULT '',
            is_default tinyint(1) DEFAULT 0,
            sort_order int(11) DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY slug (slug),
            KEY category (category),
            KEY is_default (is_default)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        // Insert default patterns if table is empty
        self::maybe_insert_defaults();
        
        // Store version
        update_option('sbn_rhythm_patterns_version', self::VERSION);
        
        // Upgrade guards — add new columns to existing installs
        $cols = $wpdb->get_col("SHOW COLUMNS FROM $table", 0);
        if (!in_array('grid_type', $cols))
            $wpdb->query("ALTER TABLE $table ADD COLUMN grid_type varchar(20) DEFAULT 'sixteenth' AFTER beats");
        if (!in_array('perc_top', $cols))
            $wpdb->query("ALTER TABLE $table ADD COLUMN perc_top varchar(20) DEFAULT 'none' AFTER sound");
        if (!in_array('perc_bass', $cols))
            $wpdb->query("ALTER TABLE $table ADD COLUMN perc_bass varchar(20) DEFAULT 'none' AFTER perc_top");
    }
    
    /**
     * Insert default rhythm patterns
     */
    private static function maybe_insert_defaults() {
        global $wpdb;
        $table = self::get_table_name();
        
        $count = $wpdb->get_var("SELECT COUNT(*) FROM $table");
        if ($count > 0) {
            return;
        }
        
        $defaults = array(
            array(
                'slug' => 'bossa-nova',
                'name' => 'Bossa Nova',
                'description' => 'Classic bossa nova fingerpicking pattern in 2/4',
                'category' => 'brazilian',
                'time_signature' => '2/4',
                'beats' => 8,
                'rhythm_pattern' => '..x..x.x',
                'thumb_pattern' => 'x..x..x.',
                'default_bpm' => 140,
                'sound' => 'guitar',
                'is_default' => 1,
                'sort_order' => 1
            ),
            array(
                'slug' => 'samba',
                'name' => 'Samba',
                'description' => 'Traditional samba rhythm pattern',
                'category' => 'brazilian',
                'time_signature' => '2/4',
                'beats' => 8,
                'rhythm_pattern' => '.xx.xx.x',
                'thumb_pattern' => 'x..x..x.',
                'default_bpm' => 100,
                'sound' => 'guitar',
                'is_default' => 1,
                'sort_order' => 2
            ),
            array(
                'slug' => 'partido-alto',
                'name' => 'Partido Alto',
                'description' => 'Syncopated samba variation',
                'category' => 'brazilian',
                'time_signature' => '2/4',
                'beats' => 8,
                'rhythm_pattern' => 'x.x.x.xx',
                'thumb_pattern' => 'x..x..x.',
                'default_bpm' => 90,
                'sound' => 'guitar',
                'is_default' => 1,
                'sort_order' => 3
            ),
            array(
                'slug' => 'baiao',
                'name' => 'Baião',
                'description' => 'Northeastern Brazilian rhythm',
                'category' => 'brazilian',
                'time_signature' => '2/4',
                'beats' => 8,
                'rhythm_pattern' => 'x.xx.x.x',
                'thumb_pattern' => 'x...x...',
                'default_bpm' => 120,
                'sound' => 'guitar',
                'is_default' => 1,
                'sort_order' => 4
            ),
            array(
                'slug' => 'folk-4-4',
                'name' => 'Folk Strum',
                'description' => 'Basic 4/4 folk strumming pattern',
                'category' => 'general',
                'time_signature' => '4/4',
                'beats' => 8,
                'rhythm_pattern' => 'x.x.x.x.',
                'thumb_pattern' => '',
                'default_bpm' => 100,
                'sound' => 'guitar',
                'is_default' => 1,
                'sort_order' => 10
            ),
            array(
                'slug' => 'waltz',
                'name' => 'Waltz',
                'description' => '3/4 waltz pattern - bass on 1, chords on 2 and 3',
                'category' => 'general',
                'time_signature' => '3/4',
                'beats' => 6,
                'rhythm_pattern' => '..x.x.',
                'thumb_pattern' => 'x.....',
                'default_bpm' => 90,
                'sound' => 'guitar',
                'is_default' => 1,
                'sort_order' => 11
            ),
        );
        
        foreach ($defaults as $pattern) {
            $wpdb->insert($table, $pattern);
        }
    }
    
    /**
     * Get all patterns
     */
    public function get_all_patterns() {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT * FROM " . self::get_table_name() . " ORDER BY category, sort_order, name ASC"
        );
    }
    
    /**
     * Get patterns by category
     */
    public function get_patterns_by_category($category) {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM " . self::get_table_name() . " WHERE category = %s ORDER BY sort_order, name ASC",
                $category
            )
        );
    }
    
    /**
     * Get single pattern by ID
     */
    public function get_pattern($id) {
        global $wpdb;
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM " . self::get_table_name() . " WHERE id = %d",
                $id
            )
        );
    }
    
    /**
     * Get pattern by slug
     */
    public function get_pattern_by_slug($slug) {
        global $wpdb;
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM " . self::get_table_name() . " WHERE slug = %s",
                $slug
            )
        );
    }
    
    /**
     * Get all categories
     */
    public function get_categories() {
        global $wpdb;
        return $wpdb->get_col(
            "SELECT DISTINCT category FROM " . self::get_table_name() . " ORDER BY category ASC"
        );
    }
    
    /**
     * Get patterns as associative array (for dropdowns)
     */
    public function get_patterns_for_select() {
        $patterns = $this->get_all_patterns();
        $result = array();
        
        foreach ($patterns as $p) {
            $result[$p->slug] = array(
                'name' => $p->name,
                'category' => $p->category
            );
        }
        
        return $result;
    }
    
    /**
     * Get rhythm data for a slug (used by parser)
     */
    public function get_rhythm_data($slug) {
        $pattern = $this->get_pattern_by_slug($slug);
        
        if (!$pattern) {
            return null;
        }
        
        return array(
            'name'          => $pattern->name,
            'beats'         => $pattern->beats,
            'gridType'      => $pattern->grid_type ?? 'sixteenth',
            'thumb'         => $pattern->thumb_pattern,
            'fingers'       => $pattern->rhythm_pattern,
            'bpm'           => $pattern->default_bpm,
            'timeSignature' => $pattern->time_signature,
            'percTop'       => $pattern->perc_top  ?? 'none',
            'percBass'      => $pattern->perc_bass ?? 'none',
        );
    }
    
    // =========================================================================
    // ADMIN MENU
    // =========================================================================
    
    /**
     * Add admin menu pages
     */
    public function add_admin_menu() {
        add_submenu_page(
            'sbn-courses',
            'Rhythm Patterns',
            'Rhythm Patterns',
            'manage_options',
            'sbn-rhythm-patterns',
            array($this, 'render_patterns_page')
        );
    }
    
    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets($hook) {
        if (strpos($hook, 'sbn-rhythm-patterns') === false) {
            return;
        }
        
        wp_enqueue_style(
            'sbn-rhythm-patterns-admin',
            SBN_PLUGIN_URL . 'assets/css/rhythm-patterns-admin.css',
            array(),
            self::VERSION
        );
        
        wp_enqueue_script(
            'sbn-rhythm-patterns-admin',
            SBN_PLUGIN_URL . 'assets/js/rhythm-patterns-admin.js',
            array('jquery'),
            self::VERSION,
            true
        );
        
        // Include Tone.js and percussion engine for preview
        wp_enqueue_script(
            'tone-js',
            'https://cdnjs.cloudflare.com/ajax/libs/tone/14.8.49/Tone.js',
            array(),
            '14.8.49',
            true
        );

        wp_enqueue_script(
            'sbn-percussion',
            SBN_PLUGIN_URL . 'assets/js/sbn-percussion.js',
            array(),
            self::VERSION,
            true
        );
        
        $upload_dir = wp_upload_dir();
        wp_localize_script('sbn-rhythm-patterns-admin', 'sbnRhythm', array(
            'ajaxUrl'    => admin_url('admin-ajax.php'),
            'nonce'      => wp_create_nonce('sbn_rhythm_patterns_nonce'),
            'samplesUrl' => $upload_dir['baseurl'] . '/sbn-rhythms/samples/',
        ));
    }
    
    /**
     * Render main patterns page
     */
    public function render_patterns_page() {
        $patterns = $this->get_all_patterns();
        $categories = $this->get_categories();
        
        // Check for edit mode - support both numeric ID and "new"
        $edit_param = isset($_GET['edit']) ? $_GET['edit'] : '';
        $is_new = ($edit_param === 'new');
        $edit_id = $is_new ? 0 : intval($edit_param);
        $pattern = ($edit_id > 0) ? $this->get_pattern($edit_id) : null;
        
        ?>
        <div class="wrap sbn-admin sbn-rhythm-patterns-admin">
            <h1 class="wp-heading-inline">Rhythm Patterns</h1>
            <a href="<?php echo admin_url('admin.php?page=sbn-rhythm-patterns&edit=new'); ?>" class="page-title-action">Add New</a>
            <hr class="wp-header-end">
            
            <?php if ($is_new || $edit_id > 0): ?>
                <?php $this->render_edit_form($pattern); ?>
            <?php else: ?>
                <?php $this->render_patterns_list($patterns, $categories); ?>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * Render patterns list
     */
    private function render_patterns_list($patterns, $categories) {
        if (empty($patterns)): ?>
            <div class="sbn-empty-state">
                <div class="sbn-empty-icon">🎵</div>
                <h2>No rhythm patterns yet</h2>
                <p>Create reusable rhythm patterns for your leadsheets and lessons.</p>
                <a href="<?php echo admin_url('admin.php?page=sbn-rhythm-patterns&edit=new'); ?>" class="button button-primary button-hero">
                    Create Pattern
                </a>
            </div>
        <?php else: ?>
            <div class="sbn-patterns-grid">
                <?php 
                $current_category = '';
                foreach ($patterns as $p):
                    if ($p->category !== $current_category):
                        if ($current_category !== '') echo '</div>'; // Close previous category
                        $current_category = $p->category;
                ?>
                        <h3 class="sbn-category-header"><?php echo esc_html(ucfirst($current_category)); ?></h3>
                        <div class="sbn-patterns-category">
                <?php endif; ?>
                    
                    <div class="sbn-pattern-card" data-id="<?php echo esc_attr($p->id); ?>">
                        <div class="sbn-pattern-header">
                            <h4><?php echo esc_html($p->name); ?></h4>
                            <code class="sbn-pattern-slug"><?php echo esc_html($p->slug); ?></code>
                        </div>
                        
                        <div class="sbn-pattern-preview">
                            <div class="sbn-pattern-info">
                                <span class="sbn-pattern-time"><?php echo esc_html($p->time_signature); ?></span>
                                <span class="sbn-pattern-bpm"><?php echo esc_html($p->default_bpm); ?> BPM</span>
                            </div>
                            <div class="sbn-pattern-grid" data-rhythm="<?php echo esc_attr($p->rhythm_pattern); ?>" data-thumb="<?php echo esc_attr($p->thumb_pattern); ?>" data-beats="<?php echo esc_attr($p->beats); ?>" data-time="<?php echo esc_attr($p->time_signature); ?>">
                                <!-- Rendered by JS -->
                            </div>
                        </div>
                        
                        <?php if ($p->description): ?>
                            <p class="sbn-pattern-desc"><?php echo esc_html($p->description); ?></p>
                        <?php endif; ?>
                        
                        <div class="sbn-pattern-actions">
                            <button class="button sbn-preview-pattern" data-id="<?php echo esc_attr($p->id); ?>">▶ Preview</button>
                            <a href="<?php echo admin_url('admin.php?page=sbn-rhythm-patterns&edit=' . $p->id); ?>" class="button">Edit</a>
                            <button class="button button-link-delete sbn-delete-pattern" data-id="<?php echo esc_attr($p->id); ?>">Delete</button>
                        </div>
                        
                        <div class="sbn-pattern-shortcode">
                            <code>[rhythm pattern="<?php echo esc_attr($p->slug); ?>"]</code>
                            <button class="sbn-copy-btn" data-copy='[rhythm pattern="<?php echo esc_attr($p->slug); ?>"]' title="Copy shortcode">📋</button>
                        </div>
                    </div>
                    
                <?php endforeach; ?>
                </div> <!-- Close last category -->
            </div>
        <?php endif;
    }
    
    /**
     * Render edit/create form
     */
    private function render_edit_form($pattern) {
        $is_new = !$pattern;
        ?>
        <div class="sbn-pattern-editor">
            <div class="sbn-editor-main">
                <div class="sbn-panel">
                    <div class="sbn-panel-header">
                        <h2><?php echo $is_new ? 'Create New Pattern' : 'Edit Pattern'; ?></h2>
                    </div>
                    <div class="sbn-panel-body">
                        <input type="hidden" id="sbnPatternId" value="<?php echo esc_attr($pattern->id ?? ''); ?>">
                        
                        <div class="sbn-form-row sbn-form-row-2">
                            <div class="sbn-form-field">
                                <label for="sbnPatternName">Name</label>
                                <input type="text" id="sbnPatternName" value="<?php echo esc_attr($pattern->name ?? ''); ?>" placeholder="e.g. Bossa Nova">
                            </div>
                            <div class="sbn-form-field">
                                <label for="sbnPatternSlug">Slug (for shortcodes)</label>
                                <input type="text" id="sbnPatternSlug" value="<?php echo esc_attr($pattern->slug ?? ''); ?>" placeholder="e.g. bossa-nova" pattern="[a-z0-9\-]+">
                                <p class="description">Lowercase letters, numbers, and hyphens only.</p>
                            </div>
                        </div>
                        
                        <div class="sbn-form-row sbn-form-row-3">
                            <div class="sbn-form-field">
                                <label for="sbnPatternCategory">Category</label>
                                <input type="text" id="sbnPatternCategory" value="<?php echo esc_attr($pattern->category ?? 'general'); ?>" list="sbn-categories" placeholder="e.g. brazilian">
                                <datalist id="sbn-categories">
                                    <option value="brazilian">
                                    <option value="jazz">
                                    <option value="latin">
                                    <option value="general">
                                </datalist>
                            </div>
                            <div class="sbn-form-field">
                                <label for="sbnPatternTime">Time Signature</label>
                                <select id="sbnPatternTime">
                                    <option value="2/4" <?php selected($pattern->time_signature ?? '', '2/4'); ?>>2/4</option>
                                    <option value="3/4" <?php selected($pattern->time_signature ?? '', '3/4'); ?>>3/4</option>
                                    <option value="4/4" <?php selected($pattern->time_signature ?? '4/4', '4/4'); ?>>4/4</option>
                                    <option value="6/8" <?php selected($pattern->time_signature ?? '', '6/8'); ?>>6/8</option>
                                </select>
                            </div>
                            <div class="sbn-form-field">
                                <label for="sbnPatternBpm">Default BPM</label>
                                <input type="number" id="sbnPatternBpm" value="<?php echo esc_attr($pattern->default_bpm ?? 120); ?>" min="40" max="240">
                            </div>
                        </div>
                        
                        <div class="sbn-form-field">
                            <label for="sbnPatternDesc">Description</label>
                            <textarea id="sbnPatternDesc" rows="2"><?php echo esc_textarea($pattern->description ?? ''); ?></textarea>
                        </div>
                        
                        <hr>
                        
                        <h3>Pattern Editor</h3>
                        
                        <?php
                            // Reverse-map stored beats + grid_type back to bars + subdivision selectors.
                            $stored_beats    = intval($pattern->beats ?? 8);
                            $stored_grid     = $pattern->grid_type ?? 'sixteenth';
                            $time_parts      = explode('/', $pattern->time_signature ?? '4/4');
                            $beats_per_bar   = intval($time_parts[0] ?? 4);
                            $sub_map         = ['eighth' => 2, 'triplet' => 3, 'sixteenth' => 4];
                            $sub_count       = $sub_map[$stored_grid] ?? 4;
                            $cells_per_bar   = $beats_per_bar * $sub_count;
                            $stored_bars     = ($cells_per_bar > 0) ? max(1, intval(round($stored_beats / $cells_per_bar))) : 1;
                        ?>
                        <div class="sbn-form-row sbn-form-row-2">
                            <div class="sbn-form-field">
                                <label for="sbnPatternBars">Bars</label>
                                <select id="sbnPatternBars">
                                    <option value="1" <?php selected($stored_bars, 1); ?>>1 bar</option>
                                    <option value="2" <?php selected($stored_bars, 2); ?>>2 bars</option>
                                </select>
                            </div>
                            <div class="sbn-form-field">
                                <label for="sbnPatternGridType">Subdivision</label>
                                <select id="sbnPatternGridType">
                                    <option value="eighth"    <?php selected($stored_grid, 'eighth'); ?>>8th notes</option>
                                    <option value="sixteenth" <?php selected($stored_grid, 'sixteenth'); ?>>16th notes</option>
                                    <option value="triplet"   <?php selected($stored_grid, 'triplet'); ?>>Triplets</option>
                                </select>
                            </div>
                        </div>

                        <div class="sbn-form-row sbn-form-row-2">
                            <div class="sbn-form-field">
                                <label for="sbnPatternPercTop">Sample — Fingers row</label>
                                <select id="sbnPatternPercTop">
                                    <option value="none"        <?php selected($pattern->perc_top ?? 'none', 'none'); ?>>— None —</option>
                                    <optgroup label="Brazilian">
                                    <option value="shaker"      <?php selected($pattern->perc_top ?? '', 'shaker'); ?>>Shaker</option>
                                    <option value="tamborim"    <?php selected($pattern->perc_top ?? '', 'tamborim'); ?>>Tamborim</option>
                                    </optgroup>
                                    <optgroup label="Jazz">
                                    <option value="hihat-brush" <?php selected($pattern->perc_top ?? '', 'hihat-brush'); ?>>Hi-Hat (Brush)</option>
                                    <option value="brush-snare" <?php selected($pattern->perc_top ?? '', 'brush-snare'); ?>>Brush Snare</option>
                                    </optgroup>
                                </select>
                            </div>
                            <div class="sbn-form-field">
                                <label for="sbnPatternPercBass">Sample — Thumb/Bass row</label>
                                <select id="sbnPatternPercBass">
                                    <option value="none" <?php selected($pattern->perc_bass ?? 'none', 'none'); ?>>— None —</option>
                                    <option value="kick" <?php selected($pattern->perc_bass ?? '', 'kick'); ?>>Kick / Bass Drum</option>
                                </select>
                            </div>
                        </div>

                        <div class="sbn-pattern-editor-grid" id="sbnPatternEditorGrid">
                            <!-- Pattern grid rendered by JS -->
                        </div>

                        <!-- Hidden pattern inputs — written by the grid, read on save -->
                        <input type="hidden" id="sbnRhythmPattern" value="<?php echo esc_attr($pattern->rhythm_pattern ?? '........'); ?>">
                        <input type="hidden" id="sbnThumbPattern"  value="<?php echo esc_attr($pattern->thumb_pattern  ?? ''); ?>">

                        <div class="sbn-raw-toggle">
                            <button type="button" class="button-link" id="sbnRawToggle">&#9660; edit raw pattern</button>
                            <div class="sbn-raw-fields" id="sbnRawFields" style="display:none;">
                                <div class="sbn-form-row sbn-form-row-2" style="margin-top:8px;">
                                    <div class="sbn-form-field">
                                        <label for="sbnRhythmPatternRaw">Fingers pattern</label>
                                        <input type="text" id="sbnRhythmPatternRaw" class="sbn-pattern-input" maxlength="32" placeholder="x.x.x.x.">
                                        <p class="description">x = hit, X = accent, . = rest</p>
                                    </div>
                                    <div class="sbn-form-field">
                                        <label for="sbnThumbPatternRaw">Thumb/Bass pattern</label>
                                        <input type="text" id="sbnThumbPatternRaw" class="sbn-pattern-input" maxlength="32" placeholder="x...x...">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="sbn-form-field" style="margin-top:16px;">
                            <label for="sbnPatternMp3">MP3 File (optional)</label>
                            <input type="text" id="sbnPatternMp3" value="<?php echo esc_attr($pattern->mp3_file ?? ''); ?>" placeholder="filename.mp3">
                            <p class="description">Place files in <code>wp-content/uploads/sbn-rhythms/</code></p>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="sbn-editor-sidebar">
                <div class="sbn-panel">
                    <div class="sbn-panel-header">
                        <h3>Preview</h3>
                    </div>
                    <div class="sbn-panel-body">
                        <div class="sbn-live-preview" id="sbnLivePreview">
                            <!-- Live preview rendered by JS -->
                        </div>
                        <button class="button button-secondary sbn-preview-play" id="sbnPreviewPlay">▶</button>
                    </div>
                </div>
                
                <div class="sbn-panel">
                    <div class="sbn-panel-header">
                        <h3>Usage</h3>
                    </div>
                    <div class="sbn-panel-body">
                        <p><strong>In Leadsheets:</strong></p>
                        <code id="sbnUsageLeadsheet">[sbn_leadsheet ... rhythm="<?php echo esc_attr($pattern->slug ?? 'pattern-slug'); ?>"]</code>
                        
                        <p style="margin-top:12px;"><strong>Standalone:</strong></p>
                        <code id="sbnUsageRhythm">[rhythm pattern="<?php echo esc_attr($pattern->slug ?? 'pattern-slug'); ?>"]</code>
                    </div>
                </div>
                
                <div class="sbn-editor-actions">
                    <button class="button button-primary button-hero" id="sbnSavePattern">
                        <?php echo $is_new ? 'Create Pattern' : 'Update Pattern'; ?>
                    </button>
                    <a href="<?php echo admin_url('admin.php?page=sbn-rhythm-patterns'); ?>" class="button button-hero">Cancel</a>
                </div>
            </div>
        </div>
        <?php
    }
    
    // =========================================================================
    // AJAX HANDLERS
    // =========================================================================
    
    /**
     * Save pattern via AJAX
     */
    public function ajax_save_pattern() {
        check_ajax_referer('sbn_rhythm_patterns_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        global $wpdb;
        $table = self::get_table_name();
        
        // Sanitize slug
        $slug = sanitize_title($_POST['slug']);
        if (empty($slug)) {
            $slug = sanitize_title($_POST['name']);
        }
        
        $data = array(
            'slug' => $slug,
            'name' => sanitize_text_field($_POST['name']),
            'description' => sanitize_textarea_field($_POST['description']),
            'category' => sanitize_text_field($_POST['category']),
            'time_signature' => sanitize_text_field($_POST['time_signature']),
            'beats' => intval($_POST['beats']),
            'rhythm_pattern' => preg_replace('/[^xX\.]/', '.', $_POST['rhythm_pattern']),
            'thumb_pattern' => preg_replace('/[^xX\.]/', '.', $_POST['thumb_pattern']),
            'default_bpm' => intval($_POST['default_bpm']),
            'sound'      => sanitize_text_field($_POST['sound']),
            'perc_top'   => in_array($_POST['perc_top']  ?? '', ['none','shaker','tamborim','hihat-brush','brush-snare']) ? $_POST['perc_top']  : 'none',
            'perc_bass'  => in_array($_POST['perc_bass'] ?? '', ['none','kick']) ? $_POST['perc_bass'] : 'none',
            'grid_type'  => in_array($_POST['grid_type'] ?? '', ['sixteenth','eighth','triplet']) ? $_POST['grid_type'] : 'sixteenth',
            'mp3_file'   => sanitize_text_field($_POST['mp3_file']),
            'updated_at' => current_time('mysql')
        );
        
        $id = isset($_POST['id']) && !empty($_POST['id']) ? intval($_POST['id']) : 0;
        
        // Check for duplicate slug
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE slug = %s AND id != %d",
            $data['slug'],
            $id
        ));
        
        if ($existing) {
            wp_send_json_error('A pattern with this slug already exists');
        }
        
        if ($id > 0) {
            $result = $wpdb->update($table, $data, array('id' => $id));
        } else {
            $data['created_at'] = current_time('mysql');
            $result = $wpdb->insert($table, $data);
            $id = $wpdb->insert_id;
        }
        
        if ($result === false) {
            wp_send_json_error('Database error: ' . $wpdb->last_error);
        }
        
        wp_send_json_success(array('id' => $id, 'slug' => $data['slug']));
    }
    
    /**
     * Delete pattern via AJAX
     */
    public function ajax_delete_pattern() {
        check_ajax_referer('sbn_rhythm_patterns_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        global $wpdb;
        $table = self::get_table_name();
        $id = intval($_POST['id']);
        
        $result = $wpdb->delete($table, array('id' => $id));
        
        if ($result === false) {
            wp_send_json_error('Database error');
        }
        
        wp_send_json_success();
    }
    
    /**
     * Get all patterns via AJAX (for leadsheet admin dropdown)
     */
    public function ajax_get_patterns() {
        check_ajax_referer('sbn_rhythm_patterns_nonce', 'nonce');
        
        $patterns = $this->get_all_patterns();
        wp_send_json_success($patterns);
    }

    /**
     * Return leadsheets that use a given rhythm slug.
     * Called by the rhythm library modal via AJAX.
     */
    public function ajax_get_rhythm_songs() {
        check_ajax_referer('sbn_rhythm_library_nonce', 'nonce');

        $slug = sanitize_text_field($_POST['slug'] ?? '');
        if (empty($slug)) {
            wp_send_json_error('No slug provided.');
        }

        global $wpdb;
        $table = $wpdb->prefix . 'sbn_leadsheets';

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, title, composer, song_key FROM {$table}
                 WHERE rhythm = %s ORDER BY title ASC",
                $slug
            )
        );

        // Build song page URL using the dedicated /song/?id= pattern
        $song_page   = get_page_by_path('song');
        $song_base   = $song_page ? get_permalink($song_page) : home_url('/song/');

        foreach ($results as $song) {
            $song->url = add_query_arg('id', $song->id, $song_base);
        }

        wp_send_json_success($results);
    }

    /**
     * Enqueue the nonce JS variable needed by the rhythm library modal.
     */
    public function enqueue_frontend_nonce() {
        if (is_page_template('page-rhythms.php') || is_page('rhythm-library')) {

            // Tone.js — shared AudioContext (same as leadsheet / course player)
            wp_enqueue_script(
                'tone-js',
                'https://cdnjs.cloudflare.com/ajax/libs/tone/14.8.49/Tone.js',
                array(),
                '14.8.49',
                true
            );

            // Percussion engine
            wp_enqueue_script(
                'sbn-percussion',
                SBN_PLUGIN_URL . 'assets/js/sbn-percussion.js',
                array('tone-js'),
                self::VERSION,
                true
            );

            $upload_dir = wp_upload_dir();
            wp_localize_script('sbn-percussion', 'sbnRhythmLibrary', array(
                'ajaxUrl'    => admin_url('admin-ajax.php'),
                'nonce'      => wp_create_nonce('sbn_rhythm_library_nonce'),
                'samplesUrl' => $upload_dir['baseurl'] . '/sbn-rhythms/samples/',
                'mp3BaseUrl' => $upload_dir['baseurl'] . '/sbn-rhythms/',
            ));
        }
    }
}

// Initialize
SBN_Rhythm_Patterns::instance();
