<?php
/**
 * SBN Leadsheet - Main Class
 * 
 * Handles admin pages, database operations, and shortcode registration
 * for the interactive leadsheet feature.
 * 
 * @package SBN_Course_Player
 * @since 6.5.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SBN_Leadsheet {
    
    /**
     * Database table name (without prefix)
     */
    const TABLE_NAME = 'sbn_leadsheets';
    
    /**
     * Plugin version for this module
     */
    const VERSION = '1.0.0';
    
    /**
     * Singleton instance
     */
    private static $instance = null;
    
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
        // Load dependencies
        require_once SBN_PLUGIN_DIR . 'inc/leadsheet/parser.php';
        require_once SBN_PLUGIN_DIR . 'inc/leadsheet/renderer.php';
        
        // Admin hooks
        if (is_admin()) {
            add_action('admin_menu', array($this, 'add_admin_menu'));
            add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
            
            // AJAX handlers
            add_action('wp_ajax_sbn_save_leadsheet', array($this, 'ajax_save_leadsheet'));
            add_action('wp_ajax_sbn_delete_leadsheet', array($this, 'ajax_delete_leadsheet'));
            add_action('wp_ajax_sbn_update_description', array($this, 'ajax_update_description'));
        }
        
        // Frontend hooks
        add_action('wp_enqueue_scripts', array($this, 'register_frontend_assets'));
        add_action('wp_enqueue_scripts', array($this, 'maybe_enqueue_for_course_player'), 20);
        
        // Register shortcodes
        add_shortcode('sbn_leadsheet', array($this, 'shortcode_leadsheet'));
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
            title varchar(255) NOT NULL,
            composer varchar(255) DEFAULT '',
            song_key varchar(10) DEFAULT 'C',
            tempo int(11) DEFAULT 120,
            time_signature varchar(10) DEFAULT '4/4',
            rhythm varchar(50) DEFAULT '',
            measure_count int(11) DEFAULT 0,
            course_id bigint(20) DEFAULT NULL,
            shortcode_content longtext,
            json_data longtext,
            description text DEFAULT NULL,
            harmony_notes text DEFAULT NULL,
            form_notes text DEFAULT NULL,
            voicing_notes text DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY course_id (course_id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        // Store version
        update_option('sbn_leadsheet_version', self::VERSION);
    }
    
    /**
     * Get all leadsheets
     */
    public function get_all_leadsheets() {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT * FROM " . self::get_table_name() . " ORDER BY title ASC"
        );
    }
    
    /**
     * Get single leadsheet by ID
     */
    public function get_leadsheet($id) {
        global $wpdb;
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM " . self::get_table_name() . " WHERE id = %d",
                $id
            )
        );
    }
    
    /**
     * Get leadsheets by course ID
     */
    public function get_leadsheets_by_course($course_id) {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM " . self::get_table_name() . " WHERE course_id = %d ORDER BY title ASC",
                $course_id
            )
        );
    }
    
    // =========================================================================
    // ADMIN MENU
    // =========================================================================
    
    /**
     * Add admin menu pages
     */
    public function add_admin_menu() {
        // Add under the existing SBN Courses menu
        add_submenu_page(
            'sbn-courses',
            'Leadsheets',
            'Leadsheets',
            'manage_options',
            'sbn-leadsheets',
            array($this, 'render_leadsheets_page')
        );
        
        // Import page kept but hidden from menu (button in Leadsheets list links here)
        add_submenu_page(
            null,
            'Import MusicXML',
            'Import MusicXML',
            'manage_options',
            'sbn-leadsheet-import',
            array($this, 'render_import_page')
        );
    }
    
    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets($hook) {
        // Only load on our pages
        if (strpos($hook, 'sbn-leadsheet') === false) {
            return;
        }
        
        wp_enqueue_style(
            'sbn-leadsheet-admin',
            SBN_PLUGIN_URL . 'assets/css/leadsheet-admin.css',
            array(),
            self::VERSION
        );
        
        wp_enqueue_script(
            'sbn-leadsheet-admin',
            SBN_PLUGIN_URL . 'assets/js/leadsheet-admin.js',
            array('jquery'),
            self::VERSION,
            true
        );
        
        wp_localize_script('sbn-leadsheet-admin', 'sbnLeadsheet', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('sbn_leadsheet_nonce'),
            'chordSearchNonce' => wp_create_nonce('sbn_chord_search'),
            'identifyNonce' => wp_create_nonce('sbn_identify_voicings'),
            'chordDiagramsNonce' => wp_create_nonce('sbn_chord_diagrams_nonce'),
            'listUrl' => admin_url('admin.php?page=sbn-leadsheets')
        ));
    }
    
    /**
     * Render leadsheets list page
     */
    public function render_leadsheets_page() {
        $leadsheets = $this->get_all_leadsheets();
        ?>
        <div class="wrap sbn-admin sbn-leadsheet-admin">
            <h1 class="wp-heading-inline">Leadsheets</h1>
            <a href="<?php echo admin_url('admin.php?page=sbn-leadsheet-import'); ?>" class="page-title-action">Import XML</a>
            <hr class="wp-header-end">

            <?php if (empty($leadsheets)): ?>
                <div class="sbn-empty-state">
                    <div class="sbn-empty-icon">🎸</div>
                    <h2>No leadsheets yet</h2>
                    <p>Import a MusicXML file to create your first interactive leadsheet.</p>
                    <a href="<?php echo admin_url('admin.php?page=sbn-leadsheet-import'); ?>" class="button button-primary button-hero">Import MusicXML</a>
                </div>
            <?php else: ?>
                <table class="wp-list-table widefat fixed sbn-leadsheets-table">
                    <thead>
                        <tr>
                            <th class="col-title">Title</th>
                            <th class="col-composer">Composer</th>
                            <th class="col-description">Song Info</th>
                            <th class="col-actions"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($leadsheets as $sheet): ?>
                            <tr data-id="<?php echo esc_attr($sheet->id); ?>">
                                <td class="col-title">
                                    <a href="<?php echo admin_url('admin.php?page=sbn-leadsheet-import&edit=' . $sheet->id); ?>" class="sbn-sheet-title">
                                        <?php echo esc_html($sheet->title); ?>
                                    </a>
                                </td>
                                <td class="col-composer"><?php echo esc_html($sheet->composer); ?></td>
                                <td class="col-description">
                                    <div class="sbn-quick-desc" data-id="<?php echo esc_attr($sheet->id); ?>">
                                        <span class="sbn-desc-text"><?php echo $sheet->description ? esc_html(wp_trim_words($sheet->description, 12, '…')) : '<span class="sbn-desc-empty">Add info…</span>'; ?></span>
                                        <button class="sbn-desc-edit-btn" data-id="<?php echo esc_attr($sheet->id); ?>" data-desc="<?php echo esc_attr($sheet->description ?? ''); ?>" title="Edit song info">&#9998;</button>
                                    </div>
                                    <div class="sbn-desc-editor" id="sbn-desc-editor-<?php echo esc_attr($sheet->id); ?>" style="display:none;">
                                        <textarea class="sbn-desc-textarea" rows="3" placeholder="Song info, context, learning objectives..."><?php echo esc_textarea($sheet->description ?? ''); ?></textarea>
                                        <div class="sbn-desc-editor-actions">
                                            <button class="button button-small button-primary sbn-desc-save" data-id="<?php echo esc_attr($sheet->id); ?>">Save</button>
                                            <button class="button button-small sbn-desc-cancel" data-id="<?php echo esc_attr($sheet->id); ?>">Cancel</button>
                                        </div>
                                    </div>
                                </td>
                                <td class="col-actions">
                                    <a href="<?php echo admin_url('admin.php?page=sbn-leadsheet-import&edit=' . $sheet->id); ?>" class="button button-small">Edit</a>
                                    <button class="button button-small sbn-copy-shortcode" data-shortcode='[sbn_leadsheet id="<?php echo esc_attr($sheet->id); ?>"]' title="Copy shortcode">&#128203;</button>
                                    <button class="button button-small button-link-delete sbn-delete-leadsheet" data-id="<?php echo esc_attr($sheet->id); ?>" title="Delete">&#128465;</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * Render import/edit page
     */
    public function render_import_page() {
        $edit_id = isset($_GET['edit']) ? intval($_GET['edit']) : 0;
        $leadsheet = $edit_id ? $this->get_leadsheet($edit_id) : null;
        
        // Get courses for dropdown
        $courses = get_posts(array(
            'post_type' => 'sbn_course',
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC'
        ));
        ?>
        <div class="wrap sbn-admin sbn-leadsheet-admin sbn-import-page">
            <h1><?php echo $edit_id ? 'Edit Leadsheet' : 'Import MusicXML'; ?></h1>
            
            <?php if (!$edit_id): ?>
            <!-- Upload Area (only on new import) -->
            <div class="sbn-upload-panel">
                <div class="sbn-upload-area" id="sbnUploadArea">
                    <div class="sbn-upload-icon">📄</div>
                    <div class="sbn-upload-text">Drop MusicXML file here</div>
                    <div class="sbn-upload-hint">or click to browse (.xml, .musicxml)</div>
                    <input type="file" id="sbnFileInput" accept=".xml,.musicxml,.mxl" style="display:none;">
                </div>
            </div>
            <?php endif; ?>

            <!-- ═══ Visual Editor (hidden until data loaded, or shown immediately in edit mode) ═══ -->
            <div id="sbnVisualEditor" style="<?php echo $edit_id ? '' : 'display:none;'; ?>">
                
                <!-- Header: Title / Composer / Key / BPM / Time + toolbar -->
                <div class="sbn-ve-header">
                    <div class="sbn-ve-header-top">
                        <div class="sbn-ve-title-group">
                            <input type="text" id="sbnTitle" class="sbn-ve-title-input"
                                value="<?php echo esc_attr($leadsheet->title ?? ''); ?>" placeholder="Song Title">
                            <span class="sbn-ve-by">by</span>
                            <input type="text" id="sbnComposer" class="sbn-ve-composer-input"
                                value="<?php echo esc_attr($leadsheet->composer ?? ''); ?>" placeholder="Composer">
                        </div>
                        <div class="sbn-ve-meta-fields">
                            <label class="sbn-ve-meta-label">Key
                                <input type="text" id="sbnKey" class="sbn-ve-meta-input"
                                    value="<?php echo esc_attr($leadsheet->song_key ?? 'C'); ?>">
                            </label>
                            <label class="sbn-ve-meta-label">BPM
                                <input type="number" id="sbnTempo" class="sbn-ve-meta-input"
                                    value="<?php echo esc_attr($leadsheet->tempo ?? 120); ?>" min="40" max="240">
                            </label>
                            <label class="sbn-ve-meta-label">Time
                                <input type="text" id="sbnTime" class="sbn-ve-meta-input"
                                    value="<?php echo esc_attr($leadsheet->time_signature ?? '4/4'); ?>">
                            </label>
                        </div>
                    </div>
                    <div class="sbn-ve-toolbar">
                        <span class="sbn-ve-stats" id="sbnStats"></span>
                        <div class="sbn-ve-clipboard-group">
                            <span class="sbn-ve-sel-count" id="sbnSelCount"></span>
                            <button class="button button-small sbn-ve-clip-btn" id="sbnCopyBtn" disabled title="Copy (Ctrl+C)">Copy</button>
                            <button class="button button-small sbn-ve-clip-btn" id="sbnCutBtn" disabled title="Cut (Ctrl+X)">Cut</button>
                            <button class="button button-small sbn-ve-clip-btn" id="sbnPasteBtn" disabled title="Paste (Ctrl+V)">Paste</button>
                        </div>
                        <div class="sbn-ve-toolbar-spacer"></div>
                        <label class="sbn-ve-toolbar-label">Bars/row
                            <select id="sbnBarsPerRow" class="sbn-ve-toolbar-select">
                                <option value="2">2</option>
                                <option value="3">3</option>
                                <option value="4" selected>4</option>
                                <option value="5">5</option>
                                <option value="6">6</option>
                                <option value="8">8</option>
                            </select>
                        </label>
                        <button class="button button-small" id="sbnToggleShortcode">View Shortcode</button>
                    </div>
                </div>

                <!-- Voicing Tags Bar -->
                <div class="sbn-ve-voicing-bar" id="sbnVoicingBar">
                    <span class="sbn-ve-voicing-label">Voicings</span>
                    <div class="sbn-ve-voicing-tags" id="sbnVoicingTags"></div>
                </div>

                <!-- Visual Leadsheet Grid -->
                <div class="sbn-ve-grid" id="sbnGrid"></div>

                <button class="sbn-ve-add-section" id="sbnAddSection">+ Add Section</button>

                <!-- Shortcode Output (toggleable) -->
                <div class="sbn-ve-shortcode-panel" id="sbnShortcodePanel" style="display:none;">
                    <div class="sbn-ve-shortcode-header">
                        <span>Shortcode Output</span>
                        <button class="button button-small" id="sbnCopyOutput">Copy</button>
                    </div>
                    <textarea id="sbnShortcodeOutput" class="sbn-code-output" readonly><?php
                        if ($leadsheet) { echo esc_textarea($leadsheet->shortcode_content); }
                    ?></textarea>
                </div>

                <!-- ─── Meta Panel (below grid) ─── -->
                <div class="sbn-ve-meta-panel">
                    <!-- Melody/Tab bar (shown when melody data present) -->
                    <div class="sbn-ve-melody-bar" id="sbnMelodyBar" style="display:none;">
                        <span class="sbn-ve-melody-icon">🎵</span>
                        <span class="sbn-ve-melody-text" id="sbnMelodyText"></span>
                        <label class="sbn-ve-melody-toggle">
                            <input type="checkbox" id="sbnIncludeMelody" checked> Include tab/melody in shortcode
                        </label>
                        <select id="sbnMelodyDisplay" class="sbn-ve-toolbar-select">
                            <option value="both">Tab + Notation</option>
                            <option value="tab">Tab only</option>
                            <option value="notation">Notation only</option>
                        </select>
                    </div>
                    <div class="sbn-ve-meta-panel-row">
                        <label class="sbn-ve-meta-label">Rhythm
                            <select id="sbnRhythm" class="sbn-ve-meta-select">
                                <option value="" <?php selected($leadsheet->rhythm ?? '', ''); ?>>— None —</option>
                                <?php
                                $rhythmPatterns = SBN_Rhythm_Patterns::instance();
                                $patterns = $rhythmPatterns->get_all_patterns();
                                $currentCategory = '';
                                foreach ($patterns as $pattern):
                                    if ($pattern->category !== $currentCategory):
                                        if ($currentCategory !== '') echo '</optgroup>';
                                        $currentCategory = $pattern->category;
                                ?>
                                    <optgroup label="<?php echo esc_attr(ucfirst($currentCategory)); ?>">
                                <?php endif; ?>
                                    <option value="<?php echo esc_attr($pattern->slug); ?>" <?php selected($leadsheet->rhythm ?? '', $pattern->slug); ?>>
                                        <?php echo esc_html($pattern->name); ?>
                                    </option>
                                <?php endforeach; ?>
                                <?php if ($currentCategory !== '') echo '</optgroup>'; ?>
                            </select>
                        </label>
                        <label class="sbn-ve-meta-label">Course
                            <select id="sbnCourse" class="sbn-ve-meta-select">
                                <option value="">— None —</option>
                                <?php foreach ($courses as $course): ?>
                                    <option value="<?php echo $course->ID; ?>" <?php selected($leadsheet->course_id ?? '', $course->ID); ?>>
                                        <?php echo esc_html($course->post_title); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    </div>
                </div>

                <!-- sbnDescription hidden — edited via quick-edit in the Leadsheets list -->
                <input type="hidden" id="sbnDescription" value="<?php echo esc_attr($leadsheet->description ?? ''); ?>">

            </div><!-- #sbnVisualEditor -->

            <!-- Voicing Picker Modal -->
            <div class="sbn-ve-modal-overlay" id="sbnVoicingModal" style="display:none;">
                <div class="sbn-ve-modal">
                    <div class="sbn-ve-modal-header">
                        <div>
                            <div class="sbn-ve-modal-subtitle">Assign voicing for</div>
                            <div class="sbn-ve-modal-chord-name" id="sbnModalChordName"></div>
                        </div>
                        <button class="sbn-ve-modal-close" id="sbnModalClose">×</button>
                    </div>
                    <div class="sbn-ve-modal-body" id="sbnModalBody">
                        <div class="sbn-ve-modal-loading">🔍 Searching voicing database...</div>
                    </div>
                    <div class="sbn-ve-modal-footer">
                        <button class="button button-small sbn-ve-modal-remove" id="sbnModalRemove" style="display:none;">Remove voicing</button>
                        <span class="sbn-ve-modal-count" id="sbnModalCount"></span>
                    </div>
                </div>
            </div>

            <!-- Chord Picker Popup -->
            <div class="sbn-ve-chord-picker" id="sbnChordPicker" style="display:none;">
                <input type="text" id="sbnChordInput" class="sbn-ve-chord-input" placeholder="e.g. Cm7, G7b9...">
                <div class="sbn-ve-picker-section">
                    <div class="sbn-ve-picker-label">Root</div>
                    <div class="sbn-ve-picker-buttons" id="sbnRootButtons"></div>
                </div>
                <div class="sbn-ve-picker-section">
                    <div class="sbn-ve-picker-label">Quality</div>
                    <div class="sbn-ve-picker-buttons" id="sbnQualityButtons"></div>
                </div>
                <div class="sbn-ve-picker-actions">
                    <button class="button button-small" id="sbnChordCancel">Cancel</button>
                    <button class="button button-small button-primary" id="sbnChordApply">Apply</button>
                </div>
            </div>
            
            <!-- Save Actions -->
            <div class="sbn-import-actions">
                <?php if ($edit_id): ?>
                    <input type="hidden" id="sbnLeadsheetId" value="<?php echo esc_attr($edit_id); ?>">
                    <button class="button button-primary button-hero" id="sbnSaveLeadsheet">Update Leadsheet</button>
                    <a href="<?php echo admin_url('admin.php?page=sbn-leadsheets'); ?>" class="button button-hero">Cancel</a>
                <?php else: ?>
                    <button class="button button-primary button-hero" id="sbnSaveLeadsheet" disabled>Save Leadsheet</button>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
    
    // =========================================================================
    // AJAX HANDLERS
    // =========================================================================
    
    /**
     * Save leadsheet via AJAX
     */
    public function ajax_save_leadsheet() {
        check_ajax_referer('sbn_leadsheet_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        global $wpdb;
        $table = self::get_table_name();
        
        // WordPress adds magic quotes to $_POST - we need to remove them first
        // For shortcode_content: it's our custom format with brackets, pipes, JSON, etc.
        // We use wp_unslash() to remove magic quotes, then a light sanitization
        // that preserves the structure but removes dangerous content
        $shortcode_content = isset($_POST['shortcode_content']) 
            ? wp_unslash($_POST['shortcode_content']) 
            : '';
        
        // Remove any actual HTML tags (not our shortcode brackets) for safety
        // But preserve newlines, brackets, pipes, quotes, and JSON
        $shortcode_content = wp_strip_all_tags($shortcode_content);
        
        // JSON data also needs unslashing - it's stored as-is for reconstruction
        $json_data = isset($_POST['json_data']) 
            ? wp_unslash($_POST['json_data']) 
            : '';
        
        $data = array(
            'title' => sanitize_text_field(wp_unslash($_POST['title'] ?? '')),
            'composer' => sanitize_text_field(wp_unslash($_POST['composer'] ?? '')),
            'song_key' => sanitize_text_field(wp_unslash($_POST['song_key'] ?? '')),
            'tempo' => intval($_POST['tempo'] ?? 120),
            'time_signature' => sanitize_text_field(wp_unslash($_POST['time_signature'] ?? '4/4')),
            'rhythm' => sanitize_text_field(wp_unslash($_POST['rhythm'] ?? '')),
            'measure_count' => intval($_POST['measure_count'] ?? 0),
            'course_id' => !empty($_POST['course_id']) ? intval($_POST['course_id']) : null,
            'shortcode_content' => $shortcode_content,
            'json_data' => $json_data,
            'description' => sanitize_textarea_field(wp_unslash($_POST['description'] ?? '')),
            'harmony_notes' => sanitize_textarea_field(wp_unslash($_POST['harmony_notes'] ?? '')),
            'form_notes' => sanitize_textarea_field(wp_unslash($_POST['form_notes'] ?? '')),
            'voicing_notes' => sanitize_textarea_field(wp_unslash($_POST['voicing_notes'] ?? '')),
            'updated_at' => current_time('mysql')
        );
        
        $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
        
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
        
        // Process voicing cross-references after successful save
        if (class_exists('SBN_Voicing_Crossref') && !empty($shortcode_content)) {
            $crossref = SBN_Voicing_Crossref::instance();
            $title = sanitize_text_field(wp_unslash($_POST['title'] ?? ''));
            $crossref->process_leadsheet($id, $shortcode_content, $title);
        }
        
        // Process chord progression detection after successful save
        if (class_exists('SBN_Chord_Progressions') && !empty($shortcode_content)) {
            SBN_Chord_Progressions::instance()->process_leadsheet($id, $shortcode_content);
        }
        
        wp_send_json_success(array('id' => $id));
    }
    
    /**
     * Delete leadsheet via AJAX
     */
    public function ajax_delete_leadsheet() {
        check_ajax_referer('sbn_leadsheet_nonce', 'nonce');
        
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
     * Quick-edit description from the leadsheets list
     */
    public function ajax_update_description() {
        check_ajax_referer('sbn_leadsheet_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        global $wpdb;
        $table = self::get_table_name();
        $id    = intval($_POST['id']);
        $desc  = sanitize_textarea_field(wp_unslash($_POST['description'] ?? ''));
        $result = $wpdb->update($table, array('description' => $desc), array('id' => $id));
        if ($result === false) {
            wp_send_json_error('Database error');
        }
        wp_send_json_success(array('description' => $desc));
    }
    // =========================================================================
    
    /**
     * Register frontend assets (loaded on demand)
     */
    public function register_frontend_assets() {
        // Register but don't enqueue - will be enqueued when shortcode is used
        wp_register_style(
            'sbn-leadsheet',
            SBN_PLUGIN_URL . 'assets/css/leadsheet.css',
            array('sbn-chord-card'), // Depends on shared chord card styles
            SBN_VERSION
        );
        
        // tone-js and sbn-audio are registered in sbn_register_shared_chord_card()
        // (priority 5), so they're always available by the time this runs.
        wp_register_script(
            'sbn-leadsheet',
            SBN_PLUGIN_URL . 'assets/js/leadsheet.js',
            array('tone-js', 'sbn-audio', 'sbn-chord-card', 'sbn-percussion'),
            SBN_VERSION,
            true
        );
    }
    
    /**
     * Enqueue leadsheet assets when needed.
     * 
     * Loads on:
     * - Pages with [sbn_leadsheet] shortcode (direct use)
     * - Song library page (standalone leadsheet rendering)
     * - Course pages that contain lessons with leadsheets
     */
    public function maybe_enqueue_for_course_player() {
        global $post;
        
        if (!$post) {
            return;
        }
        
        // Direct leadsheet shortcode on page
        if (has_shortcode($post->post_content, 'sbn_leadsheet')) {
            $this->enqueue_leadsheet_assets();
            return;
        }
        
        // Song library page — always load leadsheet assets here
        $is_song_library = (
            is_page_template('page-song-library.php') ||
            is_page('song-library') ||
            (is_page() && get_post_field('post_name', get_post()) === 'song-library')
        );
        
        if ($is_song_library) {
            $this->enqueue_leadsheet_assets();
            return;
        }

        // Song page — standalone leadsheet page (/song/?id=42)
        $is_song_page = (
            is_page('song') ||
            (is_page() && get_post_field('post_name', get_post()) === 'song')
        );

        if ($is_song_page) {
            $this->enqueue_leadsheet_assets();
            return;
        }
        
        // Check if this is a course player page
        if (!has_shortcode($post->post_content, 'sbn_course')) {
            return;
        }
        
        // It's a course page - check if ANY lesson contains a leadsheet shortcode
        // We need to do this check here (before wp_head) because shortcodes
        // inside <template> tags run too late to enqueue scripts
        
        // Extract course ID from shortcode
        $course_id = 0;
        if (preg_match('/\[sbn_course[^\]]*id=["\']?(\d+)["\']?/', $post->post_content, $matches)) {
            $course_id = intval($matches[1]);
        } elseif (preg_match('/\[sbn_course[^\]]*slug=["\']?([^"\'>\s]+)["\']?/', $post->post_content, $matches)) {
            $course = get_page_by_path($matches[1], OBJECT, 'sbn_course');
            if ($course) {
                $course_id = $course->ID;
            }
        }
        
        if (!$course_id) {
            // If no course ID found, enqueue anyway to be safe
            $this->enqueue_leadsheet_assets();
            return;
        }
        
        // Get all lessons for this course and check for leadsheet shortcodes
        $lessons = get_posts(array(
            'post_type' => 'sbn_lesson',
            'posts_per_page' => -1,
            'meta_query' => array(
                array(
                    'key' => '_sbn_course_id',
                    'value' => $course_id,
                    'compare' => '='
                )
            )
        ));
        
        foreach ($lessons as $lesson) {
            if (has_shortcode($lesson->post_content, 'sbn_leadsheet')) {
                $this->enqueue_leadsheet_assets();
                return; // Found one, no need to check more
            }
        }
    }
    
    /**
     * Enqueue leadsheet CSS/JS and pass config for cross-linking.
     * 
     * Centralized enqueue method so we always pass the same config.
     * The config object enables leadsheet ↔ chord library ↔ song library linking.
     */
    private function enqueue_leadsheet_assets() {
        wp_enqueue_style('sbn-leadsheet');
        wp_enqueue_script('sbn-leadsheet');
        
        // Pass cross-linking URLs and AJAX config to the leadsheet JS
        // Only localize once per page load
        static $localized = false;
        if (!$localized) {
            $chord_library_page       = get_page_by_path('chord-library');
            $song_library_page        = get_page_by_path('song-library');
            $progression_library_page = get_page_by_path('chord-progressions');

            $upload_dir = wp_upload_dir();
            wp_localize_script('sbn-leadsheet', 'sbnLeadsheetConfig', array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('sbn_leadsheet_nonce'),
                'chordSearchNonce' => wp_create_nonce('sbn_chord_search'),
                'chordLibraryUrl' => $chord_library_page ? get_permalink($chord_library_page) : '',
                'songLibraryUrl' => $song_library_page ? get_permalink($song_library_page) : '',
                'progressionLibraryUrl' => $progression_library_page ? get_permalink($progression_library_page) : '',
                'samplesBaseUrl' => $upload_dir['baseurl'] . '/sbn-rhythms/samples/',
            ));
            $localized = true;
        }
    }
    
    /**
     * Shortcode handler: [sbn_leadsheet id="123"] or inline content
     * 
     * Inline format:
     * [sbn_leadsheet title="Song" composer="Artist" key="Am" tempo="120" time="4/4" rhythm="bossa"]
     * | Am7 | Dm7 | G7 | Cmaj7 |
     * [sbn_voicings]
     * Am7: x02010
     * [/sbn_voicings]
     * [/sbn_leadsheet]
     */
    public function shortcode_leadsheet($atts, $content = null) {
        $atts = shortcode_atts(array(
            'id' => 0,
            'title' => '',
            'composer' => '',
            'key' => 'C',
            'tempo' => 120,
            'time' => '4/4',
            'rhythm' => '',
        ), $atts, 'sbn_leadsheet');
        
        // Get leadsheet data
        $leadsheet_content = '';
        
        if (!empty($atts['id'])) {
            // Load from database
            $leadsheet = $this->get_leadsheet(intval($atts['id']));
            if ($leadsheet) {
                $leadsheet_content = $leadsheet->shortcode_content;
                
                // If shortcode_content doesn't already have an [sbn_info] block,
                // inject one from the dedicated DB columns (description, harmony_notes, etc.)
                if ($leadsheet_content && strpos($leadsheet_content, '[sbn_info]') === false) {
                    $info_parts = array();
                    if (!empty($leadsheet->description)) {
                        $info_parts[] = '[description]' . $leadsheet->description . '[/description]';
                    }
                    if (!empty($leadsheet->harmony_notes)) {
                        $info_parts[] = '[harmony]' . $leadsheet->harmony_notes . '[/harmony]';
                    }
                    if (!empty($leadsheet->form_notes)) {
                        $info_parts[] = '[form]' . $leadsheet->form_notes . '[/form]';
                    }
                    if (!empty($leadsheet->voicing_notes)) {
                        $info_parts[] = '[voicings]' . $leadsheet->voicing_notes . '[/voicings]';
                    }
                    if (!empty($info_parts)) {
                        // Insert before [/sbn_leadsheet]
                        $info_block = "\n[sbn_info]\n" . implode("\n", $info_parts) . "\n[/sbn_info]";
                        $leadsheet_content = str_replace('[/sbn_leadsheet]', $info_block . "\n[/sbn_leadsheet]", $leadsheet_content);
                    }
                }
            }
        } elseif (!empty($content)) {
            // Build content from attributes + inner content
            // Clean up wpautop damage more thoroughly
            $content = preg_replace('/<\/?p[^>]*>/', "\n", $content);  // Remove p tags
            $content = preg_replace('/<br\s*\/?>/', "\n", $content);    // Remove br tags
            $content = preg_replace('/&nbsp;/', ' ', $content);        // Remove nbsp
            $content = html_entity_decode($content, ENT_QUOTES, 'UTF-8'); // Decode entities
            $content = preg_replace('/\n{3,}/', "\n\n", $content);     // Collapse multiple newlines
            $content = trim($content);
            
            // Reconstruct the full shortcode content for the parser
            $leadsheet_content = '[sbn_leadsheet';
            if (!empty($atts['title'])) $leadsheet_content .= ' title="' . esc_attr($atts['title']) . '"';
            if (!empty($atts['composer'])) $leadsheet_content .= ' composer="' . esc_attr($atts['composer']) . '"';
            if (!empty($atts['key'])) $leadsheet_content .= ' key="' . esc_attr($atts['key']) . '"';
            if (!empty($atts['tempo'])) $leadsheet_content .= ' tempo="' . esc_attr($atts['tempo']) . '"';
            if (!empty($atts['time'])) $leadsheet_content .= ' time="' . esc_attr($atts['time']) . '"';
            if (!empty($atts['rhythm'])) $leadsheet_content .= ' rhythm="' . esc_attr($atts['rhythm']) . '"';
            $leadsheet_content .= "]\n" . $content . "\n[/sbn_leadsheet]";
        }
        
        if (empty($leadsheet_content)) {
            return '<!-- SBN Leadsheet: No content found -->';
        }
        
        // Enqueue assets
        wp_enqueue_style('sbn-leadsheet');
        wp_enqueue_script('sbn-leadsheet');
        
        // Generate unique ID for this instance
        $instance_id = 'sbn-leadsheet-' . uniqid();
        
        // Build voicing enrichment from crossref table (diagram_id per chord_name+fret_string)
        $extra = array();
        if (!empty($atts['id']) && class_exists('SBN_Voicing_Crossref')) {
            global $wpdb;
            $usage_table = SBN_Voicing_Crossref::get_usage_table();
            $diagrams_table = $wpdb->prefix . 'sbn_chord_diagrams';
            $lid = intval($atts['id']);
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT u.chord_name, u.fret_string, u.chord_diagram_id,
                        d.quality, d.voicing_category, d.root_string, d.inversion,
                        d.interval_labels, d.notes, d.diagram_data
                 FROM $usage_table u
                 LEFT JOIN $diagrams_table d ON u.chord_diagram_id = d.id
                 WHERE u.leadsheet_id = %d",
                $lid
            ));
            if ($rows) {
                $meta = array();
                foreach ($rows as $row) {
                    $key = $row->chord_name . '|' . $row->fret_string;
                    $meta[$key] = array(
                        'diagramId'      => intval($row->chord_diagram_id),
                        'quality'        => $row->quality,
                        'voicingCategory'=> $row->voicing_category,
                        'rootString'     => $row->root_string,
                        'inversion'      => $row->inversion,
                        'intervalLabels' => $row->interval_labels ?? '',
                        'notes'          => $row->notes ?? '',
                        'diagramData'    => $row->diagram_data ?? '',
                    );
                }
                $extra['voicingMeta'] = $meta;
            }
        }
        
        // Inject detected chord progressions (keyed by section_id)
        if (!empty($atts['id']) && class_exists('SBN_Chord_Progressions')) {
            $lid = intval($atts['id']);
            $prog_crossref = SBN_Chord_Progressions::instance();
            $grouped = $prog_crossref->get_occurrences_for_leadsheet($lid);
            if (!empty($grouped)) {
                $extra['detectedProgressions'] = $grouped;
            }
        }
        
        // Render
        return SBN_Leadsheet_Renderer::render($leadsheet_content, $instance_id, $extra);
    }
}

// Initialize
SBN_Leadsheet::instance();
