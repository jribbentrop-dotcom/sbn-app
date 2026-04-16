<?php
/**
 * SBN Chord Diagrams - Main Class
 * 
 * Handles admin pages, database operations, and shortcode integration
 * for reusable chord diagrams with visual editor.
 * 
 * @package SBN_Course_Player
 * @since 6.9.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SBN_Chord_Diagrams {
    
    /**
     * Database table name (without prefix)
     */
    const TABLE_NAME = 'sbn_chord_diagrams';
    
    /**
     * Plugin version for this module
     */
    const VERSION = '3.1.0';
    
    /**
     * Singleton instance
     */
    private static $instance = null;
    
    /**
     * Cached diagrams
     */
    private $diagrams_cache = null;
    
    /**
     * Default voicing categories
     * 
     * archetype      = Fundamental open guitar shapes (E, Em, A, Am, D, Dm, C, G)
     *                   and their 7th-chord siblings, grouped by shape_family.
     * closed         = Close-voiced 7th chords (systematic inversions).
     * closed_triads  = Close-voiced triads (systematic inversions).
     * spread_triads  = Spread-voiced triads (systematic inversions).
     * drop2 / drop3  = Systematic 4-note spread voicings.
     * shell          = 2–3 note functional voicings.
     * rootless       = Voicings without root (jazz context).
     * custom         = Everything else — oddities, open voicings, unique shapes.
     */
    const DEFAULT_VOICING_CATEGORIES = array(
        'archetype' => 'Archetypes',
        'drop2' => 'Drop 2',
        'drop3' => 'Drop 3',
        'shell' => 'Shell Voicings',
        'rootless' => 'Rootless',
        'closed' => 'Closed Position',
        'closed_triads' => 'Closed Triads',
        'spread_triads' => 'Spread Triads',
        'custom' => 'Custom'
    );
    
    /**
     * Default chord qualities
     */
    const DEFAULT_CHORD_QUALITIES = array(
        'maj' => 'Major (triad)',
        'min' => 'Minor (triad)',
        'aug' => 'Augmented (triad)',
        'dim' => 'Diminished (triad)',
        '5' => 'Power Chord',
        'sus4' => 'Suspended 4th',
        'sus2' => 'Suspended 2nd',
        'add9' => 'Add 9',
        'maj7' => 'Major 7',
        'm7' => 'Minor 7',
        'dom7' => 'Dominant 7',
        'm7b5' => 'Half-Diminished (m7♭5)',
        'o7' => 'Diminished 7',
        'maj6' => 'Major 6',
        'm6' => 'Minor 6',
        'mMaj7' => 'Minor-Major 7',
        'aug7' => 'Augmented 7'
    );
    
    /**
     * Inversions
     */
    const INVERSIONS = array(
        'root' => 'Root Position',
        'inv1' => '1st Inversion',
        'inv2' => '2nd Inversion',
        'inv3' => '3rd Inversion'
    );
    
    /**
     * Get voicing categories (merged defaults + custom)
     */
    public static function get_voicing_categories() {
        $custom = get_option('sbn_custom_voicing_categories', array());
        return array_merge(self::DEFAULT_VOICING_CATEGORIES, $custom);
    }
    
    /**
     * Get chord qualities (merged defaults + custom)
     */
    public static function get_chord_qualities() {
        $custom = get_option('sbn_custom_chord_qualities', array());
        return array_merge(self::DEFAULT_CHORD_QUALITIES, $custom);
    }
    
    /**
     * Add custom voicing category
     */
    public static function add_voicing_category($key, $label) {
        $custom = get_option('sbn_custom_voicing_categories', array());
        $key = sanitize_title($key);
        $custom[$key] = sanitize_text_field($label);
        update_option('sbn_custom_voicing_categories', $custom);
        return $key;
    }
    
    /**
     * Remove custom voicing category
     */
    public static function remove_voicing_category($key) {
        // Can't remove defaults
        if (isset(self::DEFAULT_VOICING_CATEGORIES[$key])) {
            return false;
        }
        $custom = get_option('sbn_custom_voicing_categories', array());
        unset($custom[$key]);
        update_option('sbn_custom_voicing_categories', $custom);
        return true;
    }
    
    /**
     * Add custom chord quality
     */
    public static function add_chord_quality($key, $label) {
        $custom = get_option('sbn_custom_chord_qualities', array());
        $key = sanitize_text_field($key);
        $custom[$key] = sanitize_text_field($label);
        update_option('sbn_custom_chord_qualities', $custom);
        return $key;
    }
    
    /**
     * Remove custom chord quality
     */
    public static function remove_chord_quality($key) {
        // Can't remove defaults
        if (isset(self::DEFAULT_CHORD_QUALITIES[$key])) {
            return false;
        }
        $custom = get_option('sbn_custom_chord_qualities', array());
        unset($custom[$key]);
        update_option('sbn_custom_chord_qualities', $custom);
        return true;
    }
    
    // Keep VOICING_CATEGORIES and CHORD_QUALITIES for backward compatibility
    // NOTE: 'open' is retained here so any external code referencing it still works.
    const VOICING_CATEGORIES = array(
        'archetype' => 'Archetypes',
        'drop2' => 'Drop 2',
        'drop3' => 'Drop 3',
        'shell' => 'Shell Voicings',
        'rootless' => 'Rootless',
        'closed' => 'Closed Position',
        'closed_triads' => 'Closed Triads',
        'spread_triads' => 'Spread Triads',
        'open' => 'Open Voicings',
        'custom' => 'Custom'
    );
    
    const CHORD_QUALITIES = array(
        'maj' => 'Major (triad)',
        'min' => 'Minor (triad)',
        'aug' => 'Augmented (triad)',
        'dim' => 'Diminished (triad)',
        '5' => 'Power Chord',
        'sus4' => 'Suspended 4th',
        'sus2' => 'Suspended 2nd',
        'add9' => 'Add 9',
        'maj7' => 'Major 7',
        'm7' => 'Minor 7',
        'dom7' => 'Dominant 7',
        '7' => 'Dominant 7',
        'm7b5' => 'Half-Diminished (m7♭5)',
        'o7' => 'Diminished 7',
        'maj6' => 'Major 6',
        'm6' => 'Minor 6',
        'mMaj7' => 'Minor-Major 7',
        'aug7' => 'Augmented 7'
    );
    
    /**
     * Extensions/Alterations
     */
    const EXTENSIONS = array(
        '9' => '9',
        'b9' => '♭9',
        '#9' => '♯9',
        '11' => '11',
        '#11' => '♯11',
        '13' => '13',
        'b13' => '♭13',
        'add9' => 'add9',
        'sus4' => 'sus4',
        'sus2' => 'sus2',
        'b5' => '♭5',
        '#5' => '♯5'
    );
    
    /**
     * Root notes
     */
    const ROOT_NOTES = array('C', 'C#', 'Db', 'D', 'D#', 'Eb', 'E', 'F', 'F#', 'Gb', 'G', 'G#', 'Ab', 'A', 'A#', 'Bb', 'B');
    
    /**
     * Root strings - which string the root note is on
     */
    const ROOT_STRINGS = array(
        'roote' => 'Root on 6th String (Low E)',
        'roota' => 'Root on 5th String (A)',
        'rootd' => 'Root on 4th String (D)',
        'rootg' => 'Root on 3rd String (G)',
        'custom' => 'Custom Root Position'
    );
    
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
        // Check for upgrades
        add_action('admin_init', array($this, 'check_upgrade'));
        
        // Admin hooks
        if (is_admin()) {
            add_action('admin_menu', array($this, 'add_admin_menu'));
            add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
            
            // AJAX handlers
            add_action('wp_ajax_sbn_save_chord_diagram', array($this, 'ajax_save_diagram'));
            add_action('wp_ajax_sbn_delete_chord_diagram', array($this, 'ajax_delete_diagram'));
            add_action('wp_ajax_sbn_get_chord_diagrams', array($this, 'ajax_get_diagrams'));
            add_action('wp_ajax_sbn_duplicate_chord_diagram', array($this, 'ajax_duplicate_diagram'));
            add_action('wp_ajax_sbn_add_voicing_type', array($this, 'ajax_add_voicing_type'));
            add_action('wp_ajax_sbn_delete_voicing_type', array($this, 'ajax_delete_voicing_type'));
            add_action('wp_ajax_sbn_add_chord_quality', array($this, 'ajax_add_chord_quality'));
            add_action('wp_ajax_sbn_delete_chord_quality', array($this, 'ajax_delete_chord_quality'));
            add_action('wp_ajax_sbn_recompute_intervals', array($this, 'ajax_recompute_intervals'));
            add_action('wp_ajax_sbn_save_alias', array($this, 'ajax_save_alias'));
            add_action('wp_ajax_sbn_delete_alias', array($this, 'ajax_delete_alias'));
            add_action('wp_ajax_sbn_get_aliases', array($this, 'ajax_get_aliases'));
        }
    }
    
    /**
     * Check if database upgrade is needed
     */
    public function check_upgrade() {
        $stored_version = get_option('sbn_chord_diagrams_version', '0');
        
        if (version_compare($stored_version, self::VERSION, '<')) {
            self::activate();
        }
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
        
        // Check if table exists and needs migration
        self::maybe_migrate_table();
        
        // Chord diagram data stored as JSON:
        // String numbering is INTERNAL (inverted from guitar convention):
        //   1=Low E, 2=A, 3=D, 4=G, 5=B, 6=High E
        // - positions: array of {string: 1-6, fret: 0-24, finger: 1-4 or null}
        // - barres: array of {fret: 1-24, fromString: 1-6, toString: 1-6, finger: 1-4}
        // - muted: array of string numbers (1-6) that are muted
        // - open: array of string numbers (1-6) that are open
        
        $sql = "CREATE TABLE $table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            slug varchar(100) NOT NULL,
            name varchar(150) NOT NULL,
            root_note varchar(5) NOT NULL,
            quality varchar(20) NOT NULL,
            extensions varchar(50) DEFAULT '',
            voicing_category varchar(30) DEFAULT 'drop2',
            root_string varchar(20) DEFAULT 'roota',
            inversion varchar(10) DEFAULT 'root',
            bass_note varchar(5) DEFAULT '',
            shape_family varchar(50) DEFAULT '',
            is_fixed_position tinyint(1) DEFAULT 0,
            start_fret int(11) DEFAULT 1,
            diagram_data longtext NOT NULL,
            interval_labels text DEFAULT '',
            notes text DEFAULT '',
            description text,
            is_default tinyint(1) DEFAULT 0,
            sort_order int(11) DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY slug (slug),
            KEY voicing_category (voicing_category),
            KEY quality (quality),
            KEY root_note (root_note),
            KEY root_string (root_string),
            KEY inversion (inversion),
            KEY shape_family (shape_family)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        // Aliases table: alternative chord identities for the same shape
        $aliases_table = $wpdb->prefix . 'sbn_chord_diagram_aliases';
        $sql2 = "CREATE TABLE $aliases_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            diagram_id bigint(20) NOT NULL,
            alt_name varchar(150) NOT NULL,
            alt_root_note varchar(5) NOT NULL,
            alt_quality varchar(20) NOT NULL,
            alt_extensions varchar(50) DEFAULT '',
            alt_bass_note varchar(5) DEFAULT '',
            interval_labels text DEFAULT '',
            notes text DEFAULT '',
            description text DEFAULT '',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY diagram_id (diagram_id),
            KEY alt_quality (alt_quality),
            KEY alt_root_note (alt_root_note)
        ) $charset_collate;";
        dbDelta($sql2);
        
        // Insert default chord diagrams if table is empty
        self::maybe_insert_defaults();
        
        // Insert archetype diagrams (v3.0.0) — runs once
        self::maybe_insert_archetypes();
        
        // Store version
        update_option('sbn_chord_diagrams_version', self::VERSION);
    }
    
    /**
     * Migrate table structure across versions.
     * 
     * v1→v2: string_set → root_string
     * v2→v3: add shape_family, retire 'open' category → 'custom',
     *         normalize quality '7' → 'dom7'
     */
    private static function maybe_migrate_table() {
        global $wpdb;
        $table = self::get_table_name();
        
        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table'") === $table;
        if (!$table_exists) {
            return; // Fresh install, no migration needed
        }
        
        $columns = $wpdb->get_col("DESCRIBE $table");
        
        // ── v1 → v2: string_set → root_string ──
        $has_string_set = in_array('string_set', $columns);
        $has_root_string = in_array('root_string', $columns);
        
        if ($has_string_set && !$has_root_string) {
            error_log('SBN Chord Diagrams: Migrating string_set to root_string');
            
            $wpdb->query("ALTER TABLE $table ADD COLUMN root_string varchar(20) DEFAULT 'roota' AFTER voicing_category");
            
            $wpdb->query("
                UPDATE $table 
                SET root_string = CASE 
                    WHEN string_set = '6543' THEN 'roote'
                    WHEN string_set = '5432' THEN 'roota'
                    WHEN string_set = '4321' THEN 'rootd'
                    ELSE 'custom'
                END
            ");
            
            $wpdb->query("
                UPDATE $table 
                SET name = CONCAT(
                    root_note, 
                    quality,
                    CASE WHEN extensions != '' THEN CONCAT('(', extensions, ')') ELSE '' END
                )
            ");
            
            $wpdb->query("ALTER TABLE $table DROP COLUMN string_set");
            $wpdb->query("ALTER TABLE $table DROP INDEX IF EXISTS string_set");
            $wpdb->query("ALTER TABLE $table ADD INDEX root_string (root_string)");
            
            error_log('SBN Chord Diagrams: v1→v2 migration completed');
            // Re-read columns after schema change
            $columns = $wpdb->get_col("DESCRIBE $table");
        }
        
        // ── v2 → v3: shape_family, category cleanup, quality normalization ──
        $has_shape_family = in_array('shape_family', $columns);
        
        if (!$has_shape_family) {
            error_log('SBN Chord Diagrams: Running v2→v3 migration');
            
            // 1. Add shape_family column
            $wpdb->query("ALTER TABLE $table ADD COLUMN shape_family varchar(50) DEFAULT '' AFTER bass_note");
            $wpdb->query("ALTER TABLE $table ADD INDEX shape_family (shape_family)");
            
            // 2. Retire 'open' voicing category → 'custom'
            $migrated_open = $wpdb->query("UPDATE $table SET voicing_category = 'custom' WHERE voicing_category = 'open'");
            if ($migrated_open > 0) {
                error_log("SBN Chord Diagrams: Migrated {$migrated_open} 'open' voicings to 'custom'");
            }
            
            // 3. Normalize quality '7' → 'dom7' in chord diagrams
            $migrated_dom7 = $wpdb->query("UPDATE $table SET quality = 'dom7' WHERE quality = '7'");
            if ($migrated_dom7 > 0) {
                error_log("SBN Chord Diagrams: Normalized {$migrated_dom7} quality '7' → 'dom7'");
            }
            
            // 4. Also normalize in aliases table
            $aliases_table = $wpdb->prefix . 'sbn_chord_diagram_aliases';
            $aliases_exists = $wpdb->get_var("SHOW TABLES LIKE '$aliases_table'") === $aliases_table;
            if ($aliases_exists) {
                $migrated_aliases = $wpdb->query("UPDATE $aliases_table SET alt_quality = 'dom7' WHERE alt_quality = '7'");
                if ($migrated_aliases > 0) {
                    error_log("SBN Chord Diagrams: Normalized {$migrated_aliases} alias qualities '7' → 'dom7'");
                }
            }
            
            error_log('SBN Chord Diagrams: v2→v3 migration completed');
            // Re-read columns after schema change
            $columns = $wpdb->get_col("DESCRIBE $table");
        }
        
        // ── v3.0 → v3.1: is_fixed_position column ──
        $has_fixed_pos = in_array('is_fixed_position', $columns);
        
        if (!$has_fixed_pos) {
            error_log('SBN Chord Diagrams: Running v3.0→v3.1 migration');
            
            $wpdb->query("ALTER TABLE $table ADD COLUMN is_fixed_position tinyint(1) DEFAULT 0 AFTER shape_family");
            
            error_log('SBN Chord Diagrams: v3.0→v3.1 migration completed');
        }
    }
    
    /**
     * Insert default chord diagrams
     */
    private static function maybe_insert_defaults() {
        global $wpdb;
        $table = self::get_table_name();
        
        $count = $wpdb->get_var("SELECT COUNT(*) FROM $table");
        if ($count > 0) {
            return;
        }
        
        // Default Drop 2 voicings (root on A string - 5th string)
        $defaults = array(
            // C Major 7 Drop 2
            array(
                'slug' => 'cmaj7-drop2-roota',
                'name' => 'CMaj7',
                'root_note' => 'C',
                'quality' => 'maj7',
                'extensions' => '',
                'voicing_category' => 'drop2',
                'root_string' => 'roota',
                'bass_note' => '',
                'start_fret' => 2,
                'diagram_data' => json_encode(array(
                    'positions' => array(
                        array('string' => 5, 'fret' => 3, 'finger' => 2),
                        array('string' => 4, 'fret' => 2, 'finger' => 1),
                        array('string' => 3, 'fret' => 4, 'finger' => 4),
                        array('string' => 2, 'fret' => 3, 'finger' => 3)
                    ),
                    'barres' => array(),
                    'muted' => array(6, 1),
                    'open' => array()
                )),
                'interval_labels' => 'x,R,5,7,3,x',
                'notes' => 'x,C,G,B,E,x',
                'description' => 'Drop 2 voicing with root on A string',
                'is_default' => 1,
                'sort_order' => 1
            ),
            // D minor 7 Drop 2
            array(
                'slug' => 'dm7-drop2-roota',
                'name' => 'Dm7',
                'root_note' => 'D',
                'quality' => 'm7',
                'extensions' => '',
                'voicing_category' => 'drop2',
                'root_string' => 'roota',
                'bass_note' => '',
                'start_fret' => 4,
                'diagram_data' => json_encode(array(
                    'positions' => array(
                        array('string' => 5, 'fret' => 5, 'finger' => 1),
                        array('string' => 4, 'fret' => 5, 'finger' => 1),
                        array('string' => 3, 'fret' => 5, 'finger' => 1),
                        array('string' => 2, 'fret' => 6, 'finger' => 2)
                    ),
                    'barres' => array(
                        array('fret' => 5, 'fromString' => 5, 'toString' => 3, 'finger' => 1)
                    ),
                    'muted' => array(6, 1),
                    'open' => array()
                )),
                'interval_labels' => 'x,R,5,b7,b3,x',
                'notes' => 'x,D,A,C,F,x',
                'description' => 'Drop 2 voicing with root on A string',
                'is_default' => 1,
                'sort_order' => 2
            ),
            // G7 Drop 2
            array(
                'slug' => 'g7-drop2-roota',
                'name' => 'G7',
                'root_note' => 'G',
                'quality' => 'dom7',
                'extensions' => '',
                'voicing_category' => 'drop2',
                'root_string' => 'roota',
                'bass_note' => '',
                'start_fret' => 9,
                'diagram_data' => json_encode(array(
                    'positions' => array(
                        array('string' => 5, 'fret' => 10, 'finger' => 2),
                        array('string' => 4, 'fret' => 9, 'finger' => 1),
                        array('string' => 3, 'fret' => 10, 'finger' => 3),
                        array('string' => 2, 'fret' => 10, 'finger' => 4)
                    ),
                    'barres' => array(),
                    'muted' => array(6, 1),
                    'open' => array()
                )),
                'interval_labels' => 'x,R,5,b7,3,x',
                'notes' => 'x,G,D,F,B,x',
                'description' => 'Drop 2 voicing with root on A string',
                'is_default' => 1,
                'sort_order' => 3
            ),
            // Am7b5 Drop 2
            array(
                'slug' => 'am7b5-drop2-roota',
                'name' => 'Am7♭5',
                'root_note' => 'A',
                'quality' => 'm7b5',
                'extensions' => '',
                'voicing_category' => 'drop2',
                'root_string' => 'roota',
                'bass_note' => '',
                'start_fret' => 11,
                'diagram_data' => json_encode(array(
                    'positions' => array(
                        array('string' => 5, 'fret' => 12, 'finger' => 1),
                        array('string' => 4, 'fret' => 12, 'finger' => 1),
                        array('string' => 3, 'fret' => 12, 'finger' => 1),
                        array('string' => 2, 'fret' => 13, 'finger' => 2)
                    ),
                    'barres' => array(
                        array('fret' => 12, 'fromString' => 5, 'toString' => 3, 'finger' => 1)
                    ),
                    'muted' => array(6, 1),
                    'open' => array()
                )),
                'interval_labels' => 'x,R,b5,b7,b3,x',
                'notes' => 'x,A,Eb,G,C,x',
                'description' => 'Drop 2 half-diminished voicing',
                'is_default' => 1,
                'sort_order' => 4
            ),
            // Bo7 (Diminished 7)
            array(
                'slug' => 'bo7-drop2-roota',
                'name' => 'B°7',
                'root_note' => 'B',
                'quality' => 'o7',
                'extensions' => '',
                'voicing_category' => 'drop2',
                'root_string' => 'roota',
                'bass_note' => '',
                'start_fret' => 1,
                'diagram_data' => json_encode(array(
                    'positions' => array(
                        array('string' => 5, 'fret' => 2, 'finger' => 1),
                        array('string' => 4, 'fret' => 3, 'finger' => 3),
                        array('string' => 3, 'fret' => 2, 'finger' => 2),
                        array('string' => 2, 'fret' => 3, 'finger' => 4)
                    ),
                    'barres' => array(),
                    'muted' => array(6, 1),
                    'open' => array()
                )),
                'interval_labels' => 'x,R,b5,bb7,b3,x',
                'notes' => 'x,B,F,Ab,D,x',
                'description' => 'Drop 2 diminished 7th voicing',
                'is_default' => 1,
                'sort_order' => 5
            ),
            // Shell Voicing - CMaj7
            array(
                'slug' => 'cmaj7-shell-roote',
                'name' => 'CMaj7',
                'root_note' => 'C',
                'quality' => 'maj7',
                'extensions' => '',
                'voicing_category' => 'shell',
                'root_string' => 'roote',
                'bass_note' => '',
                'start_fret' => 7,
                'diagram_data' => json_encode(array(
                    'positions' => array(
                        array('string' => 6, 'fret' => 8, 'finger' => 2),
                        array('string' => 4, 'fret' => 9, 'finger' => 3)
                    ),
                    'barres' => array(),
                    'muted' => array(5, 3, 2, 1),
                    'open' => array()
                )),
                'interval_labels' => 'x,x,x,7,x,R',
                'notes' => 'x,x,x,B,x,C',
                'description' => 'Shell voicing - root and 7th only',
                'is_default' => 1,
                'sort_order' => 10
            ),
            // Shell Voicing - Dm7
            array(
                'slug' => 'dm7-shell-roote',
                'name' => 'Dm7',
                'root_note' => 'D',
                'quality' => 'm7',
                'extensions' => '',
                'voicing_category' => 'shell',
                'root_string' => 'roote',
                'bass_note' => '',
                'start_fret' => 9,
                'diagram_data' => json_encode(array(
                    'positions' => array(
                        array('string' => 6, 'fret' => 10, 'finger' => 2),
                        array('string' => 4, 'fret' => 10, 'finger' => 3)
                    ),
                    'barres' => array(),
                    'muted' => array(5, 3, 2, 1),
                    'open' => array()
                )),
                'interval_labels' => 'x,x,x,b7,x,R',
                'notes' => 'x,x,x,C,x,D',
                'description' => 'Shell voicing - root and flat 7th only',
                'is_default' => 1,
                'sort_order' => 11
            ),
        );
        
        foreach ($defaults as $diagram) {
            $wpdb->insert($table, $diagram);
        }
    }
    
    /**
     * Insert archetype chord diagrams (v3.0.0).
     * 
     * Uses INSERT IGNORE via slug uniqueness — safe to call multiple times.
     * Only runs once, gated by an option flag.
     * 
     * Internal string numbering:
     *   1=Low E, 2=A, 3=D, 4=G, 5=B, 6=High E
     *
     * Fret numbers are ABSOLUTE (fret 0 = open, fret 1 = first fret, etc.).
     * start_fret is the lowest fret shown in the diagram window.
     */
    private static function maybe_insert_archetypes() {
        if (get_option('sbn_archetypes_inserted', false)) {
            return;
        }
        
        global $wpdb;
        $table = self::get_table_name();
        
        $archetypes = array(
            // ═══════════════════════════════════════════════════════════════
            // E FAMILY (root on 6th string / Low E / internal string 1)
            // ═══════════════════════════════════════════════════════════════
            
            // E major: 022100
            array(
                'slug' => 'e-archetype', 'name' => 'E', 'root_note' => 'E',
                'quality' => 'maj', 'voicing_category' => 'archetype',
                'root_string' => 'roote', 'shape_family' => 'archetype-e',
                'start_fret' => 1, 'difficulty' => 1,
                'diagram_data' => json_encode(array(
                    'positions' => array(
                        array('string' => 2, 'fret' => 2, 'finger' => 3),
                        array('string' => 3, 'fret' => 2, 'finger' => 2),
                        array('string' => 4, 'fret' => 1, 'finger' => 1),
                    ),
                    'barres' => array(),
                    'muted' => array(),
                    'open' => array(1, 5, 6)
                )),
                'interval_labels' => 'R,5,R,3,5,R',
                'notes' => 'E,B,E,G#,B,E',
                'description' => 'Open E major — the most fundamental guitar chord',
            ),
            // Em: 022000
            array(
                'slug' => 'em-archetype', 'name' => 'Em', 'root_note' => 'E',
                'quality' => 'min', 'voicing_category' => 'archetype',
                'root_string' => 'roote', 'shape_family' => 'archetype-em',
                'start_fret' => 1, 'difficulty' => 1,
                'diagram_data' => json_encode(array(
                    'positions' => array(
                        array('string' => 2, 'fret' => 2, 'finger' => 2),
                        array('string' => 3, 'fret' => 2, 'finger' => 3),
                    ),
                    'barres' => array(),
                    'muted' => array(),
                    'open' => array(1, 4, 5, 6)
                )),
                'interval_labels' => 'R,5,R,b3,5,R',
                'notes' => 'E,B,E,G,B,E',
                'description' => 'Open E minor — often the first chord learned',
            ),
            // E7: 020100
            array(
                'slug' => 'e7-archetype', 'name' => 'E7', 'root_note' => 'E',
                'quality' => 'dom7', 'voicing_category' => 'archetype',
                'root_string' => 'roote', 'shape_family' => 'archetype-e',
                'start_fret' => 1, 'difficulty' => 1,
                'diagram_data' => json_encode(array(
                    'positions' => array(
                        array('string' => 2, 'fret' => 2, 'finger' => 2),
                        array('string' => 4, 'fret' => 1, 'finger' => 1),
                    ),
                    'barres' => array(),
                    'muted' => array(),
                    'open' => array(1, 3, 5, 6)
                )),
                'interval_labels' => 'R,5,b7,3,5,R',
                'notes' => 'E,B,D,G#,B,E',
                'description' => 'Open E7 — lift one finger from E major for the dominant sound',
            ),
            // Em7: 022030
            array(
                'slug' => 'em7-archetype', 'name' => 'Em7', 'root_note' => 'E',
                'quality' => 'm7', 'voicing_category' => 'archetype',
                'root_string' => 'roote', 'shape_family' => 'archetype-em',
                'start_fret' => 1, 'difficulty' => 1,
                'diagram_data' => json_encode(array(
                    'positions' => array(
                        array('string' => 2, 'fret' => 2, 'finger' => 2),
                        array('string' => 3, 'fret' => 2, 'finger' => 1),
                        array('string' => 5, 'fret' => 3, 'finger' => 3),
                    ),
                    'barres' => array(),
                    'muted' => array(),
                    'open' => array(1, 4, 6)
                )),
                'interval_labels' => 'R,5,b7,b3,b7,R',
                'notes' => 'E,B,D,G,D,E',
                'description' => 'Open Em7 — add one finger to Em for the minor 7th',
            ),
            // Emaj7: 021100
            array(
                'slug' => 'emaj7-archetype', 'name' => 'Emaj7', 'root_note' => 'E',
                'quality' => 'maj7', 'voicing_category' => 'archetype',
                'root_string' => 'roote', 'shape_family' => 'archetype-e',
                'start_fret' => 1, 'difficulty' => 1,
                'diagram_data' => json_encode(array(
                    'positions' => array(
                        array('string' => 2, 'fret' => 2, 'finger' => 3),
                        array('string' => 3, 'fret' => 1, 'finger' => 1),
                        array('string' => 4, 'fret' => 1, 'finger' => 2),
                    ),
                    'barres' => array(),
                    'muted' => array(),
                    'open' => array(1, 5, 6)
                )),
                'interval_labels' => 'R,5,7,3,5,R',
                'notes' => 'E,B,D#,G#,B,E',
                'description' => 'Open Emaj7 — sweet, dreamy major 7th sound',
            ),
            // Esus4: 022200
            array(
                'slug' => 'esus4-archetype', 'name' => 'Esus4', 'root_note' => 'E',
                'quality' => 'sus4', 'voicing_category' => 'archetype',
                'root_string' => 'roote', 'shape_family' => 'archetype-e',
                'start_fret' => 1, 'difficulty' => 1,
                'diagram_data' => json_encode(array(
                    'positions' => array(
                        array('string' => 2, 'fret' => 2, 'finger' => 2),
                        array('string' => 3, 'fret' => 2, 'finger' => 3),
                        array('string' => 4, 'fret' => 2, 'finger' => 4),
                    ),
                    'barres' => array(),
                    'muted' => array(),
                    'open' => array(1, 5, 6)
                )),
                'interval_labels' => 'R,5,R,4,5,R',
                'notes' => 'E,B,E,A,B,E',
                'description' => 'Open Esus4 — resolves naturally to E major',
            ),
            
            // ═══════════════════════════════════════════════════════════════
            // A FAMILY (root on 5th string / A / internal string 2)
            // ═══════════════════════════════════════════════════════════════
            
            // A major: x02220
            array(
                'slug' => 'a-archetype', 'name' => 'A', 'root_note' => 'A',
                'quality' => 'maj', 'voicing_category' => 'archetype',
                'root_string' => 'roota', 'shape_family' => 'archetype-a',
                'start_fret' => 1, 'difficulty' => 1,
                'diagram_data' => json_encode(array(
                    'positions' => array(
                        array('string' => 3, 'fret' => 2, 'finger' => 1),
                        array('string' => 4, 'fret' => 2, 'finger' => 2),
                        array('string' => 5, 'fret' => 2, 'finger' => 3),
                    ),
                    'barres' => array(),
                    'muted' => array(1),
                    'open' => array(2, 6)
                )),
                'interval_labels' => 'x,R,5,R,3,5',
                'notes' => 'x,A,E,A,C#,E',
                'description' => 'Open A major — compact three-finger shape',
            ),
            // Am: x02210
            array(
                'slug' => 'am-archetype', 'name' => 'Am', 'root_note' => 'A',
                'quality' => 'min', 'voicing_category' => 'archetype',
                'root_string' => 'roota', 'shape_family' => 'archetype-am',
                'start_fret' => 1, 'difficulty' => 1,
                'diagram_data' => json_encode(array(
                    'positions' => array(
                        array('string' => 3, 'fret' => 2, 'finger' => 2),
                        array('string' => 4, 'fret' => 2, 'finger' => 3),
                        array('string' => 5, 'fret' => 1, 'finger' => 1),
                    ),
                    'barres' => array(),
                    'muted' => array(1),
                    'open' => array(2, 6)
                )),
                'interval_labels' => 'x,R,5,R,b3,5',
                'notes' => 'x,A,E,A,C,E',
                'description' => 'Open A minor — one of the first minor chords learned',
            ),
            // A7: x02020
            array(
                'slug' => 'a7-archetype', 'name' => 'A7', 'root_note' => 'A',
                'quality' => 'dom7', 'voicing_category' => 'archetype',
                'root_string' => 'roota', 'shape_family' => 'archetype-a',
                'start_fret' => 1, 'difficulty' => 1,
                'diagram_data' => json_encode(array(
                    'positions' => array(
                        array('string' => 3, 'fret' => 2, 'finger' => 2),
                        array('string' => 5, 'fret' => 2, 'finger' => 3),
                    ),
                    'barres' => array(),
                    'muted' => array(1),
                    'open' => array(2, 4, 6)
                )),
                'interval_labels' => 'x,R,5,b7,3,5',
                'notes' => 'x,A,E,G,C#,E',
                'description' => 'Open A7 — classic blues and folk dominant chord',
            ),
            // Am7: x02010
            array(
                'slug' => 'am7-archetype', 'name' => 'Am7', 'root_note' => 'A',
                'quality' => 'm7', 'voicing_category' => 'archetype',
                'root_string' => 'roota', 'shape_family' => 'archetype-am',
                'start_fret' => 1, 'difficulty' => 1,
                'diagram_data' => json_encode(array(
                    'positions' => array(
                        array('string' => 3, 'fret' => 2, 'finger' => 2),
                        array('string' => 5, 'fret' => 1, 'finger' => 1),
                    ),
                    'barres' => array(),
                    'muted' => array(1),
                    'open' => array(2, 4, 6)
                )),
                'interval_labels' => 'x,R,5,b7,b3,5',
                'notes' => 'x,A,E,G,C,E',
                'description' => 'Open Am7 — the gateway to jazz minor chords',
            ),
            // Amaj7: x02120
            array(
                'slug' => 'amaj7-archetype', 'name' => 'Amaj7', 'root_note' => 'A',
                'quality' => 'maj7', 'voicing_category' => 'archetype',
                'root_string' => 'roota', 'shape_family' => 'archetype-a',
                'start_fret' => 1, 'difficulty' => 1,
                'diagram_data' => json_encode(array(
                    'positions' => array(
                        array('string' => 3, 'fret' => 2, 'finger' => 2),
                        array('string' => 4, 'fret' => 1, 'finger' => 1),
                        array('string' => 5, 'fret' => 2, 'finger' => 3),
                    ),
                    'barres' => array(),
                    'muted' => array(1),
                    'open' => array(2, 6)
                )),
                'interval_labels' => 'x,R,5,7,3,5',
                'notes' => 'x,A,E,G#,C#,E',
                'description' => 'Open Amaj7 — warm, pretty major 7th voicing',
            ),
            // Asus4: x02230
            array(
                'slug' => 'asus4-archetype', 'name' => 'Asus4', 'root_note' => 'A',
                'quality' => 'sus4', 'voicing_category' => 'archetype',
                'root_string' => 'roota', 'shape_family' => 'archetype-a',
                'start_fret' => 1, 'difficulty' => 1,
                'diagram_data' => json_encode(array(
                    'positions' => array(
                        array('string' => 3, 'fret' => 2, 'finger' => 1),
                        array('string' => 4, 'fret' => 2, 'finger' => 2),
                        array('string' => 5, 'fret' => 3, 'finger' => 3),
                    ),
                    'barres' => array(),
                    'muted' => array(1),
                    'open' => array(2, 6)
                )),
                'interval_labels' => 'x,R,5,R,4,5',
                'notes' => 'x,A,E,A,D,E',
                'description' => 'Open Asus4 — resolves to A major',
            ),
            // Asus2: x02200
            array(
                'slug' => 'asus2-archetype', 'name' => 'Asus2', 'root_note' => 'A',
                'quality' => 'sus2', 'voicing_category' => 'archetype',
                'root_string' => 'roota', 'shape_family' => 'archetype-a',
                'start_fret' => 1, 'difficulty' => 1,
                'diagram_data' => json_encode(array(
                    'positions' => array(
                        array('string' => 3, 'fret' => 2, 'finger' => 1),
                        array('string' => 4, 'fret' => 2, 'finger' => 2),
                    ),
                    'barres' => array(),
                    'muted' => array(1),
                    'open' => array(2, 5, 6)
                )),
                'interval_labels' => 'x,R,5,R,2,5',
                'notes' => 'x,A,E,A,B,E',
                'description' => 'Open Asus2 — open, airy suspended sound',
            ),
            
            // ═══════════════════════════════════════════════════════════════
            // D FAMILY (root on 4th string / D / internal string 3)
            // ═══════════════════════════════════════════════════════════════
            
            // D major: xx0232
            array(
                'slug' => 'd-archetype', 'name' => 'D', 'root_note' => 'D',
                'quality' => 'maj', 'voicing_category' => 'archetype',
                'root_string' => 'rootd', 'shape_family' => 'archetype-d',
                'start_fret' => 1, 'difficulty' => 1,
                'diagram_data' => json_encode(array(
                    'positions' => array(
                        array('string' => 4, 'fret' => 2, 'finger' => 1),
                        array('string' => 5, 'fret' => 3, 'finger' => 3),
                        array('string' => 6, 'fret' => 2, 'finger' => 2),
                    ),
                    'barres' => array(),
                    'muted' => array(1, 2),
                    'open' => array(3)
                )),
                'interval_labels' => 'x,x,R,3,5,R',
                'notes' => 'x,x,D,F#,A,D',
                'description' => 'Open D major — bright, ringing treble chord',
            ),
            // Dm: xx0231
            array(
                'slug' => 'dm-archetype', 'name' => 'Dm', 'root_note' => 'D',
                'quality' => 'min', 'voicing_category' => 'archetype',
                'root_string' => 'rootd', 'shape_family' => 'archetype-dm',
                'start_fret' => 1, 'difficulty' => 1,
                'diagram_data' => json_encode(array(
                    'positions' => array(
                        array('string' => 4, 'fret' => 2, 'finger' => 2),
                        array('string' => 5, 'fret' => 3, 'finger' => 3),
                        array('string' => 6, 'fret' => 1, 'finger' => 1),
                    ),
                    'barres' => array(),
                    'muted' => array(1, 2),
                    'open' => array(3)
                )),
                'interval_labels' => 'x,x,R,b3,5,R',
                'notes' => 'x,x,D,F,A,D',
                'description' => 'Open D minor — melancholy open chord',
            ),
            // D7: xx0212
            array(
                'slug' => 'd7-archetype', 'name' => 'D7', 'root_note' => 'D',
                'quality' => 'dom7', 'voicing_category' => 'archetype',
                'root_string' => 'rootd', 'shape_family' => 'archetype-d',
                'start_fret' => 1, 'difficulty' => 1,
                'diagram_data' => json_encode(array(
                    'positions' => array(
                        array('string' => 4, 'fret' => 2, 'finger' => 2),
                        array('string' => 5, 'fret' => 1, 'finger' => 1),
                        array('string' => 6, 'fret' => 2, 'finger' => 3),
                    ),
                    'barres' => array(),
                    'muted' => array(1, 2),
                    'open' => array(3)
                )),
                'interval_labels' => 'x,x,R,3,b7,R',
                'notes' => 'x,x,D,F#,C,D',
                'description' => 'Open D7 — the classic open dominant shape',
            ),
            // Dm7: xx0211
            array(
                'slug' => 'dm7-archetype', 'name' => 'Dm7', 'root_note' => 'D',
                'quality' => 'm7', 'voicing_category' => 'archetype',
                'root_string' => 'rootd', 'shape_family' => 'archetype-dm',
                'start_fret' => 1, 'difficulty' => 1,
                'diagram_data' => json_encode(array(
                    'positions' => array(
                        array('string' => 4, 'fret' => 2, 'finger' => 2),
                        array('string' => 5, 'fret' => 1, 'finger' => 1),
                        array('string' => 6, 'fret' => 1, 'finger' => 1),
                    ),
                    'barres' => array(
                        array('fret' => 1, 'fromString' => 6, 'toString' => 5, 'finger' => 1)
                    ),
                    'muted' => array(1, 2),
                    'open' => array(3)
                )),
                'interval_labels' => 'x,x,R,b3,b7,R',
                'notes' => 'x,x,D,F,C,D',
                'description' => 'Open Dm7 — smooth minor 7th, great for bossa nova',
            ),
            // Dmaj7: xx0222
            array(
                'slug' => 'dmaj7-archetype', 'name' => 'Dmaj7', 'root_note' => 'D',
                'quality' => 'maj7', 'voicing_category' => 'archetype',
                'root_string' => 'rootd', 'shape_family' => 'archetype-d',
                'start_fret' => 1, 'difficulty' => 1,
                'diagram_data' => json_encode(array(
                    'positions' => array(
                        array('string' => 4, 'fret' => 2, 'finger' => 1),
                        array('string' => 5, 'fret' => 2, 'finger' => 2),
                        array('string' => 6, 'fret' => 2, 'finger' => 3),
                    ),
                    'barres' => array(),
                    'muted' => array(1, 2),
                    'open' => array(3)
                )),
                'interval_labels' => 'x,x,R,3,7,R',
                'notes' => 'x,x,D,F#,C#,D',
                'description' => 'Open Dmaj7 — also a Drop 2 voicing!',
            ),
            // Dsus4: xx0233
            array(
                'slug' => 'dsus4-archetype', 'name' => 'Dsus4', 'root_note' => 'D',
                'quality' => 'sus4', 'voicing_category' => 'archetype',
                'root_string' => 'rootd', 'shape_family' => 'archetype-d',
                'start_fret' => 1, 'difficulty' => 1,
                'diagram_data' => json_encode(array(
                    'positions' => array(
                        array('string' => 4, 'fret' => 2, 'finger' => 1),
                        array('string' => 5, 'fret' => 3, 'finger' => 3),
                        array('string' => 6, 'fret' => 3, 'finger' => 4),
                    ),
                    'barres' => array(),
                    'muted' => array(1, 2),
                    'open' => array(3)
                )),
                'interval_labels' => 'x,x,R,4,5,R',
                'notes' => 'x,x,D,G,A,D',
                'description' => 'Open Dsus4 — creates tension before resolving to D',
            ),
            // Dsus2: xx0230
            array(
                'slug' => 'dsus2-archetype', 'name' => 'Dsus2', 'root_note' => 'D',
                'quality' => 'sus2', 'voicing_category' => 'archetype',
                'root_string' => 'rootd', 'shape_family' => 'archetype-d',
                'start_fret' => 1, 'difficulty' => 1,
                'diagram_data' => json_encode(array(
                    'positions' => array(
                        array('string' => 4, 'fret' => 2, 'finger' => 1),
                        array('string' => 5, 'fret' => 3, 'finger' => 3),
                    ),
                    'barres' => array(),
                    'muted' => array(1, 2),
                    'open' => array(3, 6)
                )),
                'interval_labels' => 'x,x,R,2,5,R',
                'notes' => 'x,x,D,E,A,D',
                'description' => 'Open Dsus2 — airy, modern sound',
            ),
            
            // ═══════════════════════════════════════════════════════════════
            // C FAMILY (root on 5th string / A / internal string 2)
            // ═══════════════════════════════════════════════════════════════
            
            // C major: x32010
            array(
                'slug' => 'c-archetype', 'name' => 'C', 'root_note' => 'C',
                'quality' => 'maj', 'voicing_category' => 'archetype',
                'root_string' => 'roota', 'shape_family' => 'archetype-c',
                'start_fret' => 1, 'difficulty' => 1,
                'diagram_data' => json_encode(array(
                    'positions' => array(
                        array('string' => 2, 'fret' => 3, 'finger' => 3),
                        array('string' => 3, 'fret' => 2, 'finger' => 2),
                        array('string' => 5, 'fret' => 1, 'finger' => 1),
                    ),
                    'barres' => array(),
                    'muted' => array(1),
                    'open' => array(4, 6)
                )),
                'interval_labels' => 'x,R,3,5,R,3',
                'notes' => 'x,C,E,G,C,E',
                'description' => 'Open C major — the first chord in many songbooks',
            ),
            // Cmaj7: x32000
            array(
                'slug' => 'cmaj7-archetype', 'name' => 'Cmaj7', 'root_note' => 'C',
                'quality' => 'maj7', 'voicing_category' => 'archetype',
                'root_string' => 'roota', 'shape_family' => 'archetype-c',
                'start_fret' => 1, 'difficulty' => 1,
                'diagram_data' => json_encode(array(
                    'positions' => array(
                        array('string' => 2, 'fret' => 3, 'finger' => 3),
                        array('string' => 3, 'fret' => 2, 'finger' => 2),
                    ),
                    'barres' => array(),
                    'muted' => array(1),
                    'open' => array(4, 5, 6)
                )),
                'interval_labels' => 'x,R,3,5,7,3',
                'notes' => 'x,C,E,G,B,E',
                'description' => 'Open Cmaj7 — lift one finger from C for a lush maj7',
            ),
            // C7: x32310
            array(
                'slug' => 'c7-archetype', 'name' => 'C7', 'root_note' => 'C',
                'quality' => 'dom7', 'voicing_category' => 'archetype',
                'root_string' => 'roota', 'shape_family' => 'archetype-c',
                'start_fret' => 1, 'difficulty' => 1,
                'diagram_data' => json_encode(array(
                    'positions' => array(
                        array('string' => 2, 'fret' => 3, 'finger' => 4),
                        array('string' => 3, 'fret' => 2, 'finger' => 2),
                        array('string' => 4, 'fret' => 3, 'finger' => 3),
                        array('string' => 5, 'fret' => 1, 'finger' => 1),
                    ),
                    'barres' => array(),
                    'muted' => array(1),
                    'open' => array(6)
                )),
                'interval_labels' => 'x,R,3,b7,R,3',
                'notes' => 'x,C,E,Bb,C,E',
                'description' => 'Open C7 — add the flat 7th for a bluesy dominant sound',
            ),
            // Cadd9: x32030
            array(
                'slug' => 'cadd9-archetype', 'name' => 'Cadd9', 'root_note' => 'C',
                'quality' => 'add9', 'voicing_category' => 'archetype',
                'root_string' => 'roota', 'shape_family' => 'archetype-c',
                'start_fret' => 1, 'difficulty' => 1,
                'diagram_data' => json_encode(array(
                    'positions' => array(
                        array('string' => 2, 'fret' => 3, 'finger' => 3),
                        array('string' => 3, 'fret' => 2, 'finger' => 2),
                        array('string' => 5, 'fret' => 3, 'finger' => 4),
                    ),
                    'barres' => array(),
                    'muted' => array(1),
                    'open' => array(4, 6)
                )),
                'interval_labels' => 'x,R,3,5,9,3',
                'notes' => 'x,C,E,G,D,E',
                'description' => 'Open Cadd9 — one of the most iconic guitar voicings',
            ),
            
            // ═══════════════════════════════════════════════════════════════
            // G FAMILY (root on 6th string / Low E / internal string 1)
            // ═══════════════════════════════════════════════════════════════
            
            // G major: 320003
            array(
                'slug' => 'g-archetype', 'name' => 'G', 'root_note' => 'G',
                'quality' => 'maj', 'voicing_category' => 'archetype',
                'root_string' => 'roote', 'shape_family' => 'archetype-g',
                'start_fret' => 1, 'difficulty' => 1,
                'diagram_data' => json_encode(array(
                    'positions' => array(
                        array('string' => 1, 'fret' => 3, 'finger' => 2),
                        array('string' => 2, 'fret' => 2, 'finger' => 1),
                        array('string' => 6, 'fret' => 3, 'finger' => 3),
                    ),
                    'barres' => array(),
                    'muted' => array(),
                    'open' => array(3, 4, 5)
                )),
                'interval_labels' => 'R,5,R,3,5,R',
                'notes' => 'G,B,D,G,B,G',
                'description' => 'Open G major — big, full six-string chord',
            ),
            // G7: 320001
            array(
                'slug' => 'g7-archetype', 'name' => 'G7', 'root_note' => 'G',
                'quality' => 'dom7', 'voicing_category' => 'archetype',
                'root_string' => 'roote', 'shape_family' => 'archetype-g',
                'start_fret' => 1, 'difficulty' => 1,
                'diagram_data' => json_encode(array(
                    'positions' => array(
                        array('string' => 1, 'fret' => 3, 'finger' => 3),
                        array('string' => 2, 'fret' => 2, 'finger' => 2),
                        array('string' => 6, 'fret' => 1, 'finger' => 1),
                    ),
                    'barres' => array(),
                    'muted' => array(),
                    'open' => array(3, 4, 5)
                )),
                'interval_labels' => 'R,5,R,3,5,b7',
                'notes' => 'G,B,D,G,B,F',
                'description' => 'Open G7 — classic folk and blues dominant',
            ),
            // Eadd9: 022102
            array(
                'slug' => 'eadd9-archetype', 'name' => 'Eadd9', 'root_note' => 'E',
                'quality' => 'add9', 'voicing_category' => 'archetype',
                'root_string' => 'roote', 'shape_family' => 'archetype-e',
                'start_fret' => 1, 'difficulty' => 1,
                'diagram_data' => json_encode(array(
                    'positions' => array(
                        array('string' => 2, 'fret' => 2, 'finger' => 2),
                        array('string' => 3, 'fret' => 2, 'finger' => 3),
                        array('string' => 4, 'fret' => 1, 'finger' => 1),
                        array('string' => 6, 'fret' => 2, 'finger' => 4),
                    ),
                    'barres' => array(),
                    'muted' => array(),
                    'open' => array(1, 5)
                )),
                'interval_labels' => 'R,5,R,3,5,9',
                'notes' => 'E,B,E,G#,B,F#',
                'description' => 'Open Eadd9 — adds shimmer to the basic E shape',
            ),
        );
        
        $inserted = 0;
        foreach ($archetypes as $arch) {
            // Check if slug already exists (don't overwrite manual additions)
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table WHERE slug = %s", $arch['slug']
            ));
            if ($exists) {
                continue;
            }
            
            // Auto-compute interval_labels and notes if not set
            // (We set them manually above for correctness, but this is a safety net)
            $arch['is_default'] = 1;
            $arch['sort_order'] = 100 + $inserted;
            $arch['created_at'] = current_time('mysql');
            $arch['updated_at'] = current_time('mysql');
            
            // Set missing defaults
            if (!isset($arch['extensions']))  $arch['extensions'] = '';
            if (!isset($arch['inversion']))   $arch['inversion'] = 'root';
            if (!isset($arch['bass_note']))   $arch['bass_note'] = '';
            if (!isset($arch['description'])) $arch['description'] = '';
            
            $result = $wpdb->insert($table, $arch);
            if ($result !== false) {
                $inserted++;
            }
        }
        
        if ($inserted > 0) {
            error_log("SBN Chord Diagrams: Inserted {$inserted} archetype diagrams");
        }
        
        update_option('sbn_archetypes_inserted', true);
    }
    
    /**
     * Get all diagrams
     */
    public function get_all_diagrams() {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT * FROM " . self::get_table_name() . " ORDER BY voicing_category, quality, root_note, sort_order, name ASC"
        );
    }
    
    /**
     * Get diagrams by category
     */
    public function get_diagrams_by_category($category) {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM " . self::get_table_name() . " WHERE voicing_category = %s ORDER BY quality, root_note, sort_order, name ASC",
                $category
            )
        );
    }
    
    /**
     * Get diagrams by quality
     */
    public function get_diagrams_by_quality($quality) {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM " . self::get_table_name() . " WHERE quality = %s ORDER BY voicing_category, root_note, sort_order ASC",
                $quality
            )
        );
    }
    
    /**
     * Get single diagram by ID
     */
    public function get_diagram($id) {
        global $wpdb;
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM " . self::get_table_name() . " WHERE id = %d",
                $id
            )
        );
    }
    
    /**
     * Get diagram by slug
     */
    public function get_diagram_by_slug($slug) {
        global $wpdb;
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM " . self::get_table_name() . " WHERE slug = %s",
                $slug
            )
        );
    }
    
    /**
     * Get all categories in use
     */
    public function get_categories() {
        global $wpdb;
        return $wpdb->get_col(
            "SELECT DISTINCT voicing_category FROM " . self::get_table_name() . " ORDER BY voicing_category ASC"
        );
    }
    
    /**
     * Get all qualities in use
     */
    public function get_qualities() {
        global $wpdb;
        return $wpdb->get_col(
            "SELECT DISTINCT quality FROM " . self::get_table_name() . " ORDER BY quality ASC"
        );
    }
    
    /**
     * Search diagrams by criteria
     */
    public function search_diagrams($args = array()) {
        global $wpdb;
        $table = self::get_table_name();
        
        $where = array('1=1');
        $values = array();
        
        if (!empty($args['root_note'])) {
            $where[] = 'root_note = %s';
            $values[] = $args['root_note'];
        }
        
        if (!empty($args['quality'])) {
            $where[] = 'quality = %s';
            $values[] = $args['quality'];
        }
        
        if (!empty($args['voicing_category'])) {
            $where[] = 'voicing_category = %s';
            $values[] = $args['voicing_category'];
        }
        
        if (!empty($args['root_string'])) {
            $where[] = 'root_string = %s';
            $values[] = $args['root_string'];
        }
        
        $sql = "SELECT * FROM $table WHERE " . implode(' AND ', $where) . " ORDER BY sort_order, name ASC";
        
        if (!empty($values)) {
            $sql = $wpdb->prepare($sql, $values);
        }
        
        return $wpdb->get_results($sql);
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
            'Chord Diagrams',
            'Chord Diagrams',
            'manage_options',
            'sbn-chord-diagrams',
            array($this, 'render_diagrams_page')
        );
    }
    
    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets($hook) {
        if (strpos($hook, 'sbn-chord-diagrams') === false) {
            return;
        }
        
        wp_enqueue_style(
            'sbn-chord-diagrams-admin',
            SBN_PLUGIN_URL . 'assets/css/chord-diagrams-admin.css',
            array(),
            self::VERSION
        );
        
        wp_enqueue_script(
            'sbn-chord-diagrams-admin',
            SBN_PLUGIN_URL . 'assets/js/chord-diagrams-admin.js',
            array('jquery'),
            self::VERSION,
            true
        );
        
        wp_localize_script('sbn-chord-diagrams-admin', 'sbnChordDiagrams', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('sbn_chord_diagrams_nonce'),
            'voicingCategories' => self::get_voicing_categories(),
            'chordQualities' => self::get_chord_qualities(),
            'extensions' => self::EXTENSIONS,
            'rootNotes' => self::ROOT_NOTES,
            'rootStrings' => self::ROOT_STRINGS,
            'inversions' => self::INVERSIONS
        ));
        
        // Also load crossref assets for the Unmatched Voicings tab
        $tab = isset($_GET['tab']) ? $_GET['tab'] : '';
        if ($tab === 'unmatched') {
            wp_enqueue_style(
                'sbn-voicing-crossref-admin',
                SBN_PLUGIN_URL . 'assets/css/voicing-crossref-admin.css',
                array(),
                SBN_VERSION
            );
            wp_enqueue_script(
                'sbn-voicing-crossref-admin',
                SBN_PLUGIN_URL . 'assets/js/voicing-crossref-admin.js',
                array('jquery', 'sbn-chord-diagrams-admin'),
                SBN_VERSION,
                true
            );
            wp_localize_script('sbn-voicing-crossref-admin', 'sbnCrossref', array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('sbn_voicing_crossref_nonce'),
                'diagramEditUrl' => admin_url('admin.php?page=sbn-chord-diagrams'),
            ));
        }
    }
    
    /**
     * Render main diagrams page
     */
    public function render_diagrams_page() {
        $diagrams  = $this->get_all_diagrams();
        $categories = $this->get_categories();

        $edit_param = isset($_GET['edit']) ? $_GET['edit'] : '';
        $is_new     = ($edit_param === 'new');
        $edit_id    = $is_new ? 0 : intval($edit_param);
        $diagram    = ($edit_id > 0) ? $this->get_diagram($edit_id) : null;
        $in_edit    = ($is_new || $edit_id > 0);

        $tab = $in_edit ? 'shapes' : (isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'shapes');

        // Badge count for unmatched voicings
        $pending = 0;
        if (class_exists('SBN_Voicing_Crossref')) {
            $stats   = SBN_Voicing_Crossref::instance()->get_stats();
            $pending = intval($stats['total_pending_drafts'] ?? 0);
        }
        ?>
        <div class="wrap sbn-admin sbn-chord-diagrams-admin">
            <h1 class="wp-heading-inline">Chord Diagrams</h1>
            <?php if (!$in_edit && $tab === 'shapes'): ?>
                <a href="<?php echo admin_url('admin.php?page=sbn-chord-diagrams&edit=new'); ?>" class="page-title-action">Add New</a>
            <?php endif; ?>
            <hr class="wp-header-end">

            <?php if (!$in_edit): ?>
            <nav class="nav-tab-wrapper">
                <a href="<?php echo admin_url('admin.php?page=sbn-chord-diagrams&tab=shapes'); ?>"
                   class="nav-tab <?php echo $tab === 'shapes' ? 'nav-tab-active' : ''; ?>">Chord Shapes</a>
                <a href="<?php echo admin_url('admin.php?page=sbn-chord-diagrams&tab=unmatched'); ?>"
                   class="nav-tab <?php echo $tab === 'unmatched' ? 'nav-tab-active' : ''; ?>">
                    Unmatched Voicings<?php if ($pending > 0) echo ' <span class="sbn-tab-badge">' . $pending . '</span>'; ?>
                </a>
            </nav>            <?php endif; ?>

            <?php if ($in_edit): ?>
                <?php $this->render_edit_form($diagram); ?>
            <?php elseif ($tab === 'unmatched'): ?>
                <?php $this->render_unmatched_tab($pending, $stats ?? []); ?>
            <?php else: ?>
                <?php $this->render_diagrams_list($diagrams, $categories); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Unmatched Voicings tab — shows crossref drafts + reprocess button
     */
    private function render_unmatched_tab($pending, $stats) {
        if (!class_exists('SBN_Voicing_Crossref')) {
            echo '<div class="notice notice-warning inline"><p>Voicing Crossref module not available.</p></div>';
            return;
        }
        $crossref = SBN_Voicing_Crossref::instance();
        if (empty($stats)) $stats = $crossref->get_stats();
        ?>
        <div class="sbn-unmatched-tab">
            <div class="sbn-unmatched-info-box">
                <p>These voicings from your leadsheets don't match any chord diagram in your library. You can <strong>add them</strong> to the library or <strong>dismiss</strong> them.</p>
                <div class="sbn-unmatched-stats-row">
                    <span class="sbn-unmatched-stats">
                        <strong><?php echo intval($stats['total_matches'] ?? 0); ?></strong> matched &nbsp;&middot;&nbsp;
                        <strong><?php echo intval($stats['total_pending_drafts'] ?? 0); ?></strong> unmatched &nbsp;&middot;&nbsp;
                        <strong><?php echo intval($stats['total_leadsheets'] ?? 0); ?></strong> leadsheets
                    </span>
                </div>
                <button class="button button-secondary" id="sbnReprocessAll">&#8635; Reprocess All Leadsheets</button>
                <span id="sbnReprocessStatus" class="sbn-status-message"></span>
            </div>
            <?php $crossref->render_drafts_tab(); ?>
        </div>
        <?php
    }

    /**
     * Render diagrams list
     */
    private function render_diagrams_list($diagrams, $categories) {
        $voicing_categories = self::get_voicing_categories();
        $chord_qualities = self::get_chord_qualities();
        
        if (empty($diagrams)): ?>
            <div class="sbn-empty-state">
                <div class="sbn-empty-icon">🎸</div>
                <h2>No chord shapes yet</h2>
                <p>Create reusable chord shapes that work with any root note.</p>
                <a href="<?php echo admin_url('admin.php?page=sbn-chord-diagrams&edit=new'); ?>" class="button button-primary button-hero">
                    Create First Shape
                </a>
            </div>
        <?php else: 
            // Organize diagrams: voicing_category → root_string → (plain first, then with extensions)
            $organized = array();
            foreach ($diagrams as $d) {
                $cat = $d->voicing_category ?: 'custom';
                $rs = $d->root_string ?: 'roota';
                $has_ext = !empty($d->extensions) ? 1 : 0;
                
                if (!isset($organized[$cat])) {
                    $organized[$cat] = array();
                }
                if (!isset($organized[$cat][$rs])) {
                    $organized[$cat][$rs] = array(0 => array(), 1 => array());
                }
                $organized[$cat][$rs][$has_ext][] = $d;
            }
            
            // Sort qualities within each group
            foreach ($organized as $cat => &$root_strings) {
                foreach ($root_strings as $rs => &$groups) {
                    // Sort plain chords by quality
                    usort($groups[0], function($a, $b) {
                        return strcmp($a->quality, $b->quality);
                    });
                    // Sort extension chords by quality then extensions
                    usort($groups[1], function($a, $b) {
                        $cmp = strcmp($a->quality, $b->quality);
                        return $cmp !== 0 ? $cmp : strcmp($a->extensions, $b->extensions);
                    });
                }
                unset($groups); // IMPORTANT: unset reference to prevent bugs
            }
            unset($root_strings); // IMPORTANT: unset reference to prevent bugs
        ?>
            <!-- Tabs for Management -->
            <div class="sbn-admin-tabs">
                <button class="sbn-tab active" data-tab="shapes">Chord Shapes</button>
                <button class="sbn-tab" data-tab="settings">Voicing Types & Qualities</button>
            </div>
            
            <!-- Shapes Tab -->
            <div class="sbn-tab-content active" id="sbn-tab-shapes">
                <!-- Filters -->
                <div class="sbn-filters">
                    <label>
                        <span>Voicing:</span>
                        <select id="sbnFilterCategory">
                            <option value="">All Voicings</option>
                            <?php foreach ($voicing_categories as $key => $label): ?>
                                <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>
                        <span>Quality:</span>
                        <select id="sbnFilterQuality">
                            <option value="">All Qualities</option>
                            <?php foreach ($chord_qualities as $key => $label): ?>
                                <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>
                        <span>Root String:</span>
                        <select id="sbnFilterRootString">
                            <option value="">All Strings</option>
                            <?php foreach (self::ROOT_STRINGS as $key => $label): ?>
                                <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <span class="sbn-filters-spacer" style="flex:1;"></span>
                    <button class="button button-secondary" id="sbnRecomputeIntervals" title="Recompute interval labels and note names for all shapes from their diagram data">&#8635; Recompute Intervals</button>
                    <span id="sbnRecomputeStatus" class="sbn-status-message"></span>
                </div>
                
                <div class="sbn-diagrams-grid" id="sbnDiagramsGrid">
                    <?php foreach ($organized as $cat => $root_strings): 
                        $cat_label = isset($voicing_categories[$cat]) ? $voicing_categories[$cat] : ucfirst($cat);
                    ?>
                        <div class="sbn-voicing-section" data-category="<?php echo esc_attr($cat); ?>">
                            <h2 class="sbn-voicing-header"><?php echo esc_html($cat_label); ?></h2>
                            
                            <?php foreach ($root_strings as $rs => $groups): 
                                $rs_label = isset(self::ROOT_STRINGS[$rs]) ? self::ROOT_STRINGS[$rs] : $rs;
                            ?>
                                <div class="sbn-root-string-section" data-root-string="<?php echo esc_attr($rs); ?>">
                                    <h3 class="sbn-root-string-header"><?php echo esc_html($rs_label); ?></h3>
                                    <div class="sbn-shapes-row">
                                        <?php 
                                        // Plain chords first, then with extensions
                                        foreach ($groups[0] as $d) {
                                            $this->render_diagram_card($d);
                                        }
                                        foreach ($groups[1] as $d) {
                                            $this->render_diagram_card($d);
                                        }
                                        ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <!-- Settings Tab -->
            <div class="sbn-tab-content" id="sbn-tab-settings">
                <div class="sbn-settings-grid">
                    <!-- Voicing Types -->
                    <div class="sbn-settings-section">
                        <h3>Voicing Types</h3>
                        <p class="description">Manage voicing categories for organizing chord shapes.</p>
                        
                        <table class="sbn-settings-table">
                            <thead>
                                <tr>
                                    <th>Key</th>
                                    <th>Label</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="sbnVoicingTypesList">
                                <?php foreach ($voicing_categories as $key => $label): 
                                    $is_default = isset(self::DEFAULT_VOICING_CATEGORIES[$key]);
                                ?>
                                    <tr data-key="<?php echo esc_attr($key); ?>">
                                        <td><code><?php echo esc_html($key); ?></code></td>
                                        <td><?php echo esc_html($label); ?></td>
                                        <td>
                                            <?php if (!$is_default): ?>
                                                <button class="button button-small sbn-delete-voicing-type" data-key="<?php echo esc_attr($key); ?>">Delete</button>
                                            <?php else: ?>
                                                <span class="sbn-default-badge">Default</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        
                        <div class="sbn-add-form">
                            <h4>Add Voicing Type</h4>
                            <div class="sbn-form-inline">
                                <input type="text" id="sbnNewVoicingKey" placeholder="key (e.g. quartal)">
                                <input type="text" id="sbnNewVoicingLabel" placeholder="Label (e.g. Quartal Voicings)">
                                <button class="button button-primary" id="sbnAddVoicingType">Add</button>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Chord Qualities -->
                    <div class="sbn-settings-section">
                        <h3>Chord Qualities</h3>
                        <p class="description">Manage chord quality types (maj7, m7, 7, etc.).</p>
                        
                        <table class="sbn-settings-table">
                            <thead>
                                <tr>
                                    <th>Key</th>
                                    <th>Label</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="sbnQualitiesList">
                                <?php foreach ($chord_qualities as $key => $label): 
                                    $is_default = isset(self::DEFAULT_CHORD_QUALITIES[$key]);
                                ?>
                                    <tr data-key="<?php echo esc_attr($key); ?>">
                                        <td><code><?php echo esc_html($key); ?></code></td>
                                        <td><?php echo esc_html($label); ?></td>
                                        <td>
                                            <?php if (!$is_default): ?>
                                                <button class="button button-small sbn-delete-quality" data-key="<?php echo esc_attr($key); ?>">Delete</button>
                                            <?php else: ?>
                                                <span class="sbn-default-badge">Default</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        
                        <div class="sbn-add-form">
                            <h4>Add Chord Quality</h4>
                            <div class="sbn-form-inline">
                                <input type="text" id="sbnNewQualityKey" placeholder="key (e.g. 9)">
                                <input type="text" id="sbnNewQualityLabel" placeholder="Label (e.g. Dominant 9)">
                                <button class="button button-primary" id="sbnAddQuality">Add</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif;
    }
    
    /**
     * Render single diagram card
     */
    private function render_diagram_card($d) {
        $diagram_data = json_decode($d->diagram_data, true);
        $chord_qualities = self::get_chord_qualities();
        $quality_label = isset($chord_qualities[$d->quality]) ? $chord_qualities[$d->quality] : $d->quality;
        $inversion = $d->inversion ?? 'root';
        $inversion_label = isset(self::INVERSIONS[$inversion]) ? self::INVERSIONS[$inversion] : '';
        $bass_note = $d->bass_note ?? '';
        
        // Generate shape slug for display
        // Format: quality-voicing-rootstring[-inversion][-extensions][-overBass]
        $shape_slug = $d->quality . '-' . $d->voicing_category . '-' . $d->root_string;
        if (!empty($inversion) && $inversion !== 'root') {
            $shape_slug .= '-' . $inversion;
        }
        if (!empty($d->extensions)) {
            $ext_slug = str_replace(array('#', '♯', '♭', ' '), array('s', 's', 'b', ''), $d->extensions);
            $shape_slug .= '-' . $ext_slug;
        }
        if (!empty($bass_note)) {
            $shape_slug .= '-over' . $bass_note;
        }
        ?>
        <div class="sbn-diagram-card" 
             data-id="<?php echo esc_attr($d->id); ?>"
             data-category="<?php echo esc_attr($d->voicing_category); ?>"
             data-quality="<?php echo esc_attr($d->quality); ?>"
             data-root-string="<?php echo esc_attr($d->root_string); ?>"
             data-inversion="<?php echo esc_attr($inversion); ?>"
             data-extensions="<?php echo esc_attr($d->extensions); ?>"
             data-bass-note="<?php echo esc_attr($bass_note); ?>"
             data-shape-family="<?php echo esc_attr($d->shape_family ?? ''); ?>">
            <div class="sbn-diagram-header">
                <span class="sbn-shape-quality"><?php echo esc_html($quality_label); ?><?php if ($d->extensions): ?><span class="sbn-shape-ext"><?php echo esc_html($d->extensions); ?></span><?php endif; ?></span>
                <?php if ($inversion !== 'root'): ?>
                    <span class="sbn-shape-inversion"><?php echo esc_html($inversion_label); ?></span>
                <?php endif; ?>
                <?php if (!empty($bass_note)): ?>
                    <span class="sbn-shape-bass" style="background:#e8d5f5; color:#6b3fa0; padding:1px 6px; border-radius:3px; font-size:11px;">/ <?php echo esc_html($bass_note); ?></span>
                <?php endif; ?>
                <?php if (!empty($d->shape_family)): ?>
                    <span class="sbn-shape-family" style="background:#d5e8f5; color:#2a6496; padding:1px 6px; border-radius:3px; font-size:10px;" title="Shape family: <?php echo esc_attr($d->shape_family); ?>"><?php echo esc_html($d->shape_family); ?></span>
                <?php endif; ?>
                <?php if (!empty($d->is_fixed_position)): ?>
                    <span class="sbn-shape-fixed" style="background:#f5e6d5; color:#96642a; padding:1px 6px; border-radius:3px; font-size:10px;" title="Fixed position — not transposable">📌</span>
                <?php endif; ?>
            </div>
            
            <div class="sbn-diagram-preview">
                <div class="sbn-chord-fretboard"
                     data-diagram='<?php echo esc_attr($d->diagram_data); ?>'
                     data-start-fret="<?php echo esc_attr($d->start_fret); ?>"
                     data-intervals="<?php echo esc_attr($d->interval_labels); ?>">
                    <!-- Rendered by JS -->
                </div>
            </div>
            
            <div class="sbn-diagram-slug">
                <code><?php echo esc_html($shape_slug); ?></code>
            </div>
            
            <div class="sbn-diagram-actions">
                <a href="<?php echo admin_url('admin.php?page=sbn-chord-diagrams&edit=' . $d->id); ?>" class="button button-small">Edit</a>
                <button class="button button-small sbn-delete-diagram" data-id="<?php echo esc_attr($d->id); ?>">Delete</button>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render edit/create form
     */
    private function render_edit_form($diagram) {
        $is_new = !$diagram;
        $diagram_data = $diagram ? json_decode($diagram->diagram_data, true) : array(
            'positions' => array(),
            'barres' => array(),
            'muted' => array(6, 1),
            'open' => array()
        );
        ?>
        <div class="sbn-diagram-editor">
            <div class="sbn-editor-main">
                <div class="sbn-panel">
                    <div class="sbn-panel-header">
                        <h2><?php echo $is_new ? 'Create New Chord Shape' : 'Edit Chord Shape'; ?></h2>
                        <p class="description" style="font-weight: normal; margin-top: 8px;">
                            Draw the chord at its <strong>lowest practical position</strong>. The shape will work for all 12 roots automatically.
                        </p>
                    </div>
                    <div class="sbn-panel-body">
                        <input type="hidden" id="sbnDiagramId" value="<?php echo esc_attr($diagram->id ?? ''); ?>">
                        
                        <?php 
                        $voicing_categories = self::get_voicing_categories();
                        $chord_qualities = self::get_chord_qualities();
                        $notes_list = array('C','C#','Db','D','D#','Eb','E','F','F#','Gb','G','G#','Ab','A','A#','Bb','B');
                        ?>
                        
                        <!-- Row 1: Root Note, Quality, Extensions, Category -->
                        <div class="sbn-form-row sbn-form-row-4">
                            <div class="sbn-form-field">
                                <label for="sbnRootNote">Root Note *</label>
                                <select id="sbnRootNote">
                                    <?php foreach ($notes_list as $n): ?>
                                        <option value="<?php echo esc_attr($n); ?>" <?php selected($diagram->root_note ?? 'C', $n); ?>><?php echo esc_html($n); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">The note stored at this shape position</p>
                            </div>
                            <div class="sbn-form-field">
                                <label for="sbnQuality">Chord Quality *</label>
                                <select id="sbnQuality">
                                    <?php foreach ($chord_qualities as $key => $label): ?>
                                        <option value="<?php echo esc_attr($key); ?>" <?php selected($diagram->quality ?? 'maj7', $key); ?>><?php echo esc_html($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="sbn-form-field">
                                <label for="sbnExtensions">Extensions</label>
                                <input type="text" id="sbnExtensions" value="<?php echo esc_attr($diagram->extensions ?? ''); ?>" placeholder="e.g. 9, b9, #11">
                            </div>
                            <div class="sbn-form-field">
                                <label for="sbnVoicingCategory">Voicing Type *</label>
                                <select id="sbnVoicingCategory">
                                    <?php foreach ($voicing_categories as $key => $label): ?>
                                        <option value="<?php echo esc_attr($key); ?>" <?php selected($diagram->voicing_category ?? 'drop2', $key); ?>><?php echo esc_html($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <!-- Row 2: Root String, Inversion, Bass Note, Description -->
                        <div class="sbn-form-row sbn-form-row-4">
                            <div class="sbn-form-field">
                                <label for="sbnRootString">Root String *</label>
                                <select id="sbnRootString">
                                    <?php foreach (self::ROOT_STRINGS as $key => $label): ?>
                                        <option value="<?php echo esc_attr($key); ?>" <?php selected($diagram->root_string ?? 'roota', $key); ?>><?php echo esc_html($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">Which string has the root note?</p>
                            </div>
                            <div class="sbn-form-field">
                                <label for="sbnInversion">Inversion</label>
                                <select id="sbnInversion">
                                    <?php foreach (self::INVERSIONS as $key => $label): ?>
                                        <option value="<?php echo esc_attr($key); ?>" <?php selected($diagram->inversion ?? 'root', $key); ?>><?php echo esc_html($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="sbn-form-field">
                                <label for="sbnBassNote">Slash Bass Note</label>
                                <select id="sbnBassNote">
                                    <option value="" <?php selected($diagram->bass_note ?? '', ''); ?>>— none —</option>
                                    <?php foreach ($notes_list as $n): ?>
                                        <option value="<?php echo esc_attr($n); ?>" <?php selected($diagram->bass_note ?? '', $n); ?>><?php echo esc_html($n); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">Foreign bass note for slash chords (e.g. G for F/G). Leave empty for standard voicings &amp; inversions.</p>
                            </div>
                            <div class="sbn-form-field">
                                <label for="sbnShapeFamily">Shape Family</label>
                                <input type="text" id="sbnShapeFamily" value="<?php echo esc_attr($diagram->shape_family ?? ''); ?>" placeholder="e.g. archetype-e, archetype-am">
                                <p class="description">Groups related shapes (e.g. E + E7 + Em7 in the same archetype family). Leave empty if not part of a family.</p>
                            </div>
                            <div class="sbn-form-field">
                                <label>
                                    <input type="checkbox" id="sbnFixedPosition" value="1" <?php checked($diagram->is_fixed_position ?? 0, 1); ?>>
                                    Fixed position (not transposable)
                                </label>
                                <p class="description">When checked, this shape will only appear for its stored root note and won't be transposed to other keys.</p>
                            </div>
                            <div class="sbn-form-field">
                                <label for="sbnDescription">Description</label>
                                <input type="text" id="sbnDescription" value="<?php echo esc_attr($diagram->description ?? ''); ?>" placeholder="Optional notes">
                            </div>
                        </div>
                        
                        <hr>
                        
                        <h3>Fretboard Editor</h3>
                        <p class="description">Click frets to add fingers. Right-click for finger numbers. Click string labels for muted (×) / open (○).</p>
                        
                        <div style="margin-bottom: 10px;">
                            <button type="button" class="button" id="sbnClearDiagram">Clear All</button>
                        </div>
                        <input type="hidden" id="sbnStartFret" value="<?php echo esc_attr($diagram->start_fret ?? 1); ?>">
                        
                        <div class="sbn-fretboard-editor" id="sbnFretboardEditor">
                            <!-- Rendered by JS -->
                        </div>
                        
                        <div class="sbn-form-row sbn-form-row-2">
                            <div class="sbn-form-field">
                                <label for="sbnIntervalLabels">Interval Labels <span class="description" style="font-weight:normal; color:#888;">(auto-computed on save)</span></label>
                                <input type="text" id="sbnIntervalLabels" value="<?php echo esc_attr($diagram->interval_labels ?? ''); ?>" placeholder="x,R,5,7,3,x" readonly style="background:#f0f0f1; color:#555;">
                                <p class="description">Per-string labels (low E → high E). Auto-generated from diagram data, root note, and quality.</p>
                            </div>
                            <div class="sbn-form-field">
                                <label for="sbnNotes">Note Names <span class="description" style="font-weight:normal; color:#888;">(auto-computed on save)</span></label>
                                <input type="text" id="sbnNotes" value="<?php echo esc_attr($diagram->notes ?? ''); ?>" placeholder="x,C,G,B,E,x" readonly style="background:#f0f0f1; color:#555;">
                                <p class="description">Per-string note names. Auto-generated from diagram data and root note.</p>
                            </div>
                        </div>
                        
                        <!-- Hidden fields -->
                        <input type="hidden" id="sbnDiagramData" value='<?php echo esc_attr(json_encode($diagram_data)); ?>'>
                        <input type="hidden" id="sbnDiagramName" value="<?php echo esc_attr($diagram->name ?? ''); ?>">
                        <input type="hidden" id="sbnDiagramSlug" value="<?php echo esc_attr($diagram->slug ?? ''); ?>">
                    </div>
                    
                    <?php if (!$is_new && $diagram): ?>
                    <!-- Aliases -->
                    <div class="sbn-aliases-section">
                        <h3>Aliases <span class="description" style="font-weight:normal; font-size:12px;">— alternative chord identities for this shape</span></h3>
                        <div id="sbnAliasesList" class="sbn-aliases-list">
                            <!-- Loaded via AJAX -->
                        </div>
                        <div class="sbn-alias-form" id="sbnAliasForm">
                            <div class="sbn-form-row sbn-form-row-4" style="gap:8px; align-items:end;">
                                <div class="sbn-form-field">
                                    <label>Root Note</label>
                                    <select id="sbnAliasRoot">
                                        <?php foreach (array('C','C#','Db','D','D#','Eb','E','F','F#','Gb','G','G#','Ab','A','A#','Bb','B') as $n): ?>
                                            <option value="<?php echo $n; ?>"><?php echo $n; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="sbn-form-field">
                                    <label>Quality</label>
                                    <select id="sbnAliasQuality">
                                        <?php foreach (self::get_chord_qualities() as $key => $label): ?>
                                            <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="sbn-form-field">
                                    <label>Extensions</label>
                                    <input type="text" id="sbnAliasExtensions" placeholder="e.g. b9, #11" style="width:80px;">
                                </div>
                                <div class="sbn-form-field">
                                    <label>Bass Note</label>
                                    <input type="text" id="sbnAliasBass" placeholder="e.g. Ab" style="width:60px;">
                                </div>
                                <div class="sbn-form-field">
                                    <button type="button" class="button button-secondary" id="sbnAddAlias">+ Add Alias</button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
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
                    </div>
                </div>
                
                <div class="sbn-panel">
                    <div class="sbn-panel-header">
                        <h3>Shortcode</h3>
                    </div>
                    <div class="sbn-panel-body">
                        <p><strong>Generated shape slug:</strong></p>
                        <code id="sbnGeneratedSlug" style="display: block; margin-bottom: 15px; padding: 10px; background: #f0f0f1; border-radius: 3px; font-size: 13px;">maj7-drop2-roota</code>
                        
                        <p><strong>Use in content:</strong></p>
                        <pre style="font-size: 11px; background: #f0f0f1; padding: 10px; border-radius: 3px; margin: 0;">[chord diagram="<span class="sbn-slug-preview">maj7-drop2-roota</span>" root="C"]
[chord diagram="<span class="sbn-slug-preview">maj7-drop2-roota</span>" root="F"]
[chord diagram="<span class="sbn-slug-preview">maj7-drop2-roota</span>" root="Bb"]</pre>
                        <p class="description" style="margin-top: 10px;">Change the root to any note (C, C#, Db, D, etc.)</p>
                    </div>
                </div>
                
                <div class="sbn-panel">
                    <div class="sbn-panel-header">
                        <h3>Fingers</h3>
                    </div>
                    <div class="sbn-panel-body sbn-finger-legend">
                        <div class="sbn-finger-item"><span class="sbn-finger-dot finger-1">1</span> Index</div>
                        <div class="sbn-finger-item"><span class="sbn-finger-dot finger-2">2</span> Middle</div>
                        <div class="sbn-finger-item"><span class="sbn-finger-dot finger-3">3</span> Ring</div>
                        <div class="sbn-finger-item"><span class="sbn-finger-dot finger-4">4</span> Pinky</div>
                    </div>
                </div>
                
                <div class="sbn-editor-actions">
                    <button class="button button-primary button-hero" id="sbnSaveDiagram">
                        <?php echo $is_new ? 'Save Shape' : 'Update Shape'; ?>
                    </button>
                    <a href="<?php echo admin_url('admin.php?page=sbn-chord-diagrams'); ?>" class="button button-hero">Cancel</a>
                </div>
            </div>
        </div>
        <?php
    }
    
    // =========================================================================
    // AJAX HANDLERS
    // =========================================================================
    
    /**
     * Save diagram via AJAX
     */
    public function ajax_save_diagram() {
        check_ajax_referer('sbn_chord_diagrams_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        global $wpdb;
        $table = self::get_table_name();
        
        // 1. Sanitize or Generate Slug
        $slug = isset($_POST['slug']) ? sanitize_title($_POST['slug']) : '';
        
        if (empty($slug)) {
            // Generate slug from chord info if not provided
            $root = isset($_POST['root_note']) ? sanitize_text_field($_POST['root_note']) : '';
            $quality = isset($_POST['quality']) ? sanitize_text_field($_POST['quality']) : '';
            $extensions = isset($_POST['extensions']) ? sanitize_text_field($_POST['extensions']) : '';
            $category = isset($_POST['voicing_category']) ? sanitize_text_field($_POST['voicing_category']) : '';
            $root_string = isset($_POST['root_string']) ? sanitize_text_field($_POST['root_string']) : '';
            
            // Format: a7-drop2-roota-#9b13 or fmaj-open-roote-overg
            $slug_parts = array(
                strtolower(str_replace(array('#', 'b'), array('s', 'b'), $root)) . $quality,
                $category,
                $root_string
            );
            if ($extensions) {
                $slug_parts[] = str_replace(array('#', '♯', '♭'), array('s', 's', 'b'), $extensions);
            }
            $bass_for_slug = isset($_POST['bass_note']) ? sanitize_text_field($_POST['bass_note']) : '';
            if ($bass_for_slug) {
                $slug_parts[] = 'over' . strtolower(str_replace(array('#', 'b'), array('s', 'b'), $bass_for_slug));
            }
            $slug = sanitize_title(implode('-', $slug_parts));
        }
        
        // 2. Sanitize or Generate Name
        $name = isset($_POST['name']) ? sanitize_text_field($_POST['name']) : '';
        
        if (empty($name)) {
            $root = isset($_POST['root_note']) ? sanitize_text_field($_POST['root_note']) : '';
            $quality = isset($_POST['quality']) ? sanitize_text_field($_POST['quality']) : '';
            $extensions = isset($_POST['extensions']) ? sanitize_text_field($_POST['extensions']) : '';
            $bass = isset($_POST['bass_note']) ? sanitize_text_field($_POST['bass_note']) : '';
            
            // Format: A7b9 or F/G (no parentheses - just concatenate)
            $name = "{$root}{$quality}";
            if ($extensions) {
                $name .= $extensions;
            }
            if ($bass) {
                $name .= '/' . $bass;
            }
        }
        
        // 3. FIX: Handle JSON data correctly (Unslash and Validate)
        $raw_diagram_data = isset($_POST['diagram_data']) ? wp_unslash($_POST['diagram_data']) : '';
        
        // Verify it is valid JSON, otherwise use default
        json_decode($raw_diagram_data);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $raw_diagram_data = json_encode(array(
                'positions' => array(),
                'barres' => array(),
                'muted' => array(6, 1),
                'open' => array()
            ));
        }

        // 4. Prepare Data for DB
        $data = array(
            'slug' => $slug,
            'name' => $name,
            'root_note' => isset($_POST['root_note']) ? sanitize_text_field($_POST['root_note']) : '',
            'quality' => isset($_POST['quality']) ? sanitize_text_field($_POST['quality']) : '',
            'extensions' => isset($_POST['extensions']) ? sanitize_text_field($_POST['extensions']) : '',
            'voicing_category' => isset($_POST['voicing_category']) ? sanitize_text_field($_POST['voicing_category']) : '',
            'root_string' => isset($_POST['root_string']) ? sanitize_text_field($_POST['root_string']) : '',
            'inversion' => isset($_POST['inversion']) ? sanitize_text_field($_POST['inversion']) : 'root',
            'bass_note' => isset($_POST['bass_note']) ? sanitize_text_field($_POST['bass_note']) : '',
            'shape_family' => isset($_POST['shape_family']) ? sanitize_text_field($_POST['shape_family']) : '',
            'is_fixed_position' => !empty($_POST['is_fixed_position']) ? 1 : 0,
            'start_fret' => isset($_POST['start_fret']) ? intval($_POST['start_fret']) : 1,
            
            // Use the unslashed, validated JSON string here
            'diagram_data' => $raw_diagram_data,
            
            'interval_labels' => '', // will be auto-computed below
            'notes' => '', // will be auto-computed below
            'description' => isset($_POST['description']) ? sanitize_textarea_field($_POST['description']) : '',
            'updated_at' => current_time('mysql')
        );
        
        // Auto-compute interval_labels and notes from diagram data
        $temp_obj = (object) $data;
        $computed = self::compute_intervals_and_notes($temp_obj);
        $data['interval_labels'] = $computed['interval_labels'];
        $data['notes']           = $computed['notes'];
        
        $id = isset($_POST['id']) && !empty($_POST['id']) ? intval($_POST['id']) : 0;
        
        // Check for duplicate slug
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE slug = %s AND id != %d",
            $data['slug'],
            $id
        ));
        
        if ($existing) {
            wp_send_json_error('A chord diagram with this slug already exists');
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
        
        wp_send_json_success(array(
            'id' => $id, 
            'slug' => $data['slug'], 
            'name' => $data['name']
        ));
    }
    
    /**
     * Delete diagram via AJAX
     */
    public function ajax_delete_diagram() {
        check_ajax_referer('sbn_chord_diagrams_nonce', 'nonce');
        
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
     * Duplicate diagram via AJAX
     */
    public function ajax_duplicate_diagram() {
        check_ajax_referer('sbn_chord_diagrams_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        global $wpdb;
        $table = self::get_table_name();
        $id = intval($_POST['id']);
        
        $original = $this->get_diagram($id);
        if (!$original) {
            wp_send_json_error('Diagram not found');
        }
        
        // Find unique slug
        $base_slug = $original->slug . '-copy';
        $slug = $base_slug;
        $counter = 1;
        while ($wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE slug = %s", $slug))) {
            $slug = $base_slug . '-' . $counter;
            $counter++;
        }
        
        $data = array(
            'slug' => $slug,
            'name' => $original->name . ' (Copy)',
            'root_note' => $original->root_note,
            'quality' => $original->quality,
            'extensions' => $original->extensions,
            'voicing_category' => $original->voicing_category,
            'root_string' => $original->root_string,
            'bass_note' => $original->bass_note,
            'shape_family' => $original->shape_family ?? '',
            'is_fixed_position' => $original->is_fixed_position ?? 0,
            'start_fret' => $original->start_fret,
            'diagram_data' => $original->diagram_data,
            'interval_labels' => $original->interval_labels,
            'notes' => $original->notes,
            'description' => $original->description,
            'is_default' => 0,
            'sort_order' => $original->sort_order,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql')
        );
        
        $result = $wpdb->insert($table, $data);
        
        if ($result === false) {
            wp_send_json_error('Database error');
        }
        
        wp_send_json_success(array('id' => $wpdb->insert_id, 'slug' => $slug));
    }
    
    /**
     * Get all diagrams via AJAX
     */
    public function ajax_get_diagrams() {
        check_ajax_referer('sbn_chord_diagrams_nonce', 'nonce');
        
        $diagrams = $this->get_all_diagrams();
        wp_send_json_success($diagrams);
    }
    
    /**
     * Add voicing type via AJAX
     */
    public function ajax_add_voicing_type() {
        check_ajax_referer('sbn_chord_diagrams_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $key = isset($_POST['key']) ? sanitize_title($_POST['key']) : '';
        $label = isset($_POST['label']) ? sanitize_text_field($_POST['label']) : '';
        
        if (empty($key) || empty($label)) {
            wp_send_json_error('Key and label are required');
        }
        
        // Check if key already exists
        $all_categories = self::get_voicing_categories();
        if (isset($all_categories[$key])) {
            wp_send_json_error('A voicing type with this key already exists');
        }
        
        $key = self::add_voicing_category($key, $label);
        wp_send_json_success(array('key' => $key, 'label' => $label));
    }
    
    /**
     * Delete voicing type via AJAX
     */
    public function ajax_delete_voicing_type() {
        check_ajax_referer('sbn_chord_diagrams_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $key = isset($_POST['key']) ? sanitize_text_field($_POST['key']) : '';
        
        if (empty($key)) {
            wp_send_json_error('Key is required');
        }
        
        if (!self::remove_voicing_category($key)) {
            wp_send_json_error('Cannot delete default voicing types');
        }
        
        wp_send_json_success();
    }
    
    /**
     * Add chord quality via AJAX
     */
    public function ajax_add_chord_quality() {
        check_ajax_referer('sbn_chord_diagrams_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $key = isset($_POST['key']) ? sanitize_text_field($_POST['key']) : '';
        $label = isset($_POST['label']) ? sanitize_text_field($_POST['label']) : '';
        
        if (empty($key) || empty($label)) {
            wp_send_json_error('Key and label are required');
        }
        
        // Check if key already exists
        $all_qualities = self::get_chord_qualities();
        if (isset($all_qualities[$key])) {
            wp_send_json_error('A chord quality with this key already exists');
        }
        
        $key = self::add_chord_quality($key, $label);
        wp_send_json_success(array('key' => $key, 'label' => $label));
    }
    
    /**
     * Delete chord quality via AJAX
     */
    public function ajax_delete_chord_quality() {
        check_ajax_referer('sbn_chord_diagrams_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $key = isset($_POST['key']) ? sanitize_text_field($_POST['key']) : '';
        
        if (empty($key)) {
            wp_send_json_error('Key is required');
        }
        
        if (!self::remove_chord_quality($key)) {
            wp_send_json_error('Cannot delete default chord qualities');
        }
        
        wp_send_json_success();
    }
    // =========================================================================
    // INTERVAL & NOTE AUTO-COMPUTATION
    // =========================================================================

    /**
     * Standard tuning: internal string number => semitone
     * INTERNAL NUMBERING (inverted from guitar convention):
     * String 1 = Low E (leftmost in diagram), String 6 = High E (rightmost)
     * See class-chord-shape-calculator.php for full documentation.
     */
    private static $tuning = array(
        1 => 4,  // Low E
        2 => 9,  // A
        3 => 2,  // D
        4 => 7,  // G
        5 => 11, // B
        6 => 4   // High E
    );

    /**
     * Note name → semitone
     */
    private static $note_semitones = array(
        'C' => 0, 'C#' => 1, 'Db' => 1,
        'D' => 2, 'D#' => 3, 'Eb' => 3,
        'E' => 4,
        'F' => 5, 'F#' => 6, 'Gb' => 6,
        'G' => 7, 'G#' => 8, 'Ab' => 8,
        'A' => 9, 'A#' => 10, 'Bb' => 10,
        'B' => 11
    );

    /**
     * Semitone → note name (sharp preference for general use)
     */
    private static $semitone_notes_sharp = array(
        0 => 'C', 1 => 'C#', 2 => 'D', 3 => 'D#', 4 => 'E', 5 => 'F',
        6 => 'F#', 7 => 'G', 8 => 'G#', 9 => 'A', 10 => 'A#', 11 => 'B'
    );

    /**
     * Semitone → note name (flat preference)
     */
    private static $semitone_notes_flat = array(
        0 => 'C', 1 => 'Db', 2 => 'D', 3 => 'Eb', 4 => 'E', 5 => 'F',
        6 => 'Gb', 7 => 'G', 8 => 'Ab', 9 => 'A', 10 => 'Bb', 11 => 'B'
    );

    /**
     * Roots that conventionally use flats
     */
    private static $flat_roots = array('F', 'Bb', 'Eb', 'Ab', 'Db', 'Gb');

    /**
     * Full interval formulas per quality: semitone offset => label.
     * Used both for labelling chord tones and for determining
     * the bass note interval in inversions.
     */
    private static function get_quality_intervals() {
        return array(
            'maj7'  => array(0 => 'R', 4 => '3', 7 => '5', 11 => '7'),
            'maj6'  => array(0 => 'R', 4 => '3', 7 => '5', 9 => '6'),
            'm7'    => array(0 => 'R', 3 => 'b3', 7 => '5', 10 => 'b7'),
            'm6'    => array(0 => 'R', 3 => 'b3', 7 => '5', 9 => '6'),
            '7'     => array(0 => 'R', 4 => '3', 7 => '5', 10 => 'b7'),
            'dom7'  => array(0 => 'R', 4 => '3', 7 => '5', 10 => 'b7'),
            'm7b5'  => array(0 => 'R', 3 => 'b3', 6 => 'b5', 10 => 'b7'),
            'o7'    => array(0 => 'R', 3 => 'b3', 6 => 'b5', 9 => 'bb7'),
            'mMaj7' => array(0 => 'R', 3 => 'b3', 7 => '5', 11 => '7'),
            'aug7'  => array(0 => 'R', 4 => '3', 8 => '#5', 10 => 'b7'),
            'maj'   => array(0 => 'R', 4 => '3', 7 => '5'),
            'min'   => array(0 => 'R', 3 => 'b3', 7 => '5'),
            'aug'   => array(0 => 'R', 4 => '3', 8 => '#5'),
            'dim'   => array(0 => 'R', 3 => 'b3', 6 => 'b5'),
            'sus4'  => array(0 => 'R', 5 => '4', 7 => '5'),
            'sus2'  => array(0 => 'R', 2 => '2', 7 => '5'),
            'add9'  => array(0 => 'R', 2 => '9', 4 => '3', 7 => '5'),
            '5'     => array(0 => 'R', 7 => '5'),
        );
    }

    /**
     * Map root_string identifier to internal string number.
     * Internal: 1=Low E, 2=A, 3=D, 4=G, 5=B, 6=High E
     */
    private static $root_string_to_number = array(
        'roote'    => 1,  // Low E
        'roota'    => 2,  // A
        'rootd'    => 3,  // D
        'rootg'    => 4,  // G
        'rootb'    => 5,  // B
        'roothighe'=> 6   // High E
    );

    /**
     * Inversion → which chord tone is in the bass.
     * Index into the sorted interval list for the quality.
     */
    private static $inversion_bass_index = array(
        'root' => 0,
        'inv1' => 1,
        'inv2' => 2,
        'inv3' => 3
    );

    /**
     * Generic semitone → interval label fallback for notes outside
     * the core chord tones. Uses extension naming (9, 11, 13) since
     * any note not in the quality map is by definition an extension.
     * Quality-specific labels (6, bb7, b5, etc.) are handled by the
     * quality map and take precedence over this fallback.
     *
     * Note: semitone 6 could be #11 or b5 depending on context.
     * When the quality map already defines b5 (m7b5, o7), that takes
     * precedence. When it doesn't (e.g. dom7 + #11), the fallback
     * gives #11 which is correct for extensions.
     */
    private static $generic_interval_labels = array(
        0 => 'R', 1 => 'b9', 2 => '9', 3 => '#9', 4 => '3', 5 => '11',
        6 => '#11', 7 => '5', 8 => 'b13', 9 => '13', 10 => 'b7', 11 => '7'
    );

    /**
     * Compute interval_labels and notes for a single diagram object.
     *
     * The root note is derived from the ACTUAL fret data: we find which
     * fret is on the root string and compute the note from tuning.
     * This means the stored root_note field is only used as a fallback.
     *
     * For inversions, the root string tells us which string carries the
     * bass note. The bass note interval (from get_quality_intervals)
     * is subtracted to find the true chord root, so all intervals are
     * labelled relative to the chord root regardless of inversion.
     *
     * Output order: index 0 = string 6 (Low E / leftmost in diagram)
     * through index 5 = string 1 (High E / rightmost in diagram).
     * This matches the visual left-to-right rendering in the admin JS.
     *
     * @param object $diagram DB row
     * @return array ['interval_labels' => string, 'notes' => string]
     */
    public static function compute_intervals_and_notes($diagram) {
        $result = array('interval_labels' => '', 'notes' => '');

        // Parse diagram data
        $data = is_string($diagram->diagram_data)
            ? json_decode($diagram->diagram_data, true)
            : $diagram->diagram_data;
        if (!$data || empty($data['positions'])) {
            return $result;
        }

        $quality      = $diagram->quality ?? '';
        $inversion    = $diagram->inversion ?? 'root';
        $root_str_id  = $diagram->root_string ?? '';

        // Build per-string lookup from positions + open strings
        $positions = $data['positions'] ?? array();
        $open      = $data['open'] ?? array();
        $muted     = $data['muted'] ?? array();

        $string_frets = array();
        foreach ($positions as $pos) {
            $string_frets[intval($pos['string'])] = intval($pos['fret']);
        }
        foreach ($open as $s) {
            $s = intval($s);
            if (!isset($string_frets[$s])) {
                $string_frets[$s] = 0;
            }
        }

        // --- Derive the actual chord root from the diagram data ---
        // Step 1: Find the root string number
        $root_string_num = isset(self::$root_string_to_number[$root_str_id])
            ? self::$root_string_to_number[$root_str_id]
            : null;

        // Step 2: Get the fret on that string
        $root_fret = null;
        if ($root_string_num !== null && isset($string_frets[$root_string_num])) {
            $root_fret = $string_frets[$root_string_num];
        }

        // --- Derive the actual chord root ---
        $quality_maps = self::get_quality_intervals();
        $imap = isset($quality_maps[$quality]) ? $quality_maps[$quality] : null;
        $root_semitone = null;

        if ($root_fret === null || $root_string_num === null) {
            // Root string is muted or missing — fall back to root_note field.
            // This handles rootless voicings, quartal structures, dim7 as dom7b9, etc.
            $root_note = $diagram->root_note ?? '';
            if (!isset(self::$note_semitones[$root_note])) {
                return $result;
            }
            $root_semitone = self::$note_semitones[$root_note];
        } else {
            // Compute the note on the root string at that fret
            $bass_semitone = (self::$tuning[$root_string_num] + $root_fret) % 12;

            // For inversions, the bass note is NOT the root.
            $root_semitone = $bass_semitone;
            if ($inversion !== 'root' && $imap) {
                $intervals_ordered = array_keys($imap);
                sort($intervals_ordered);

                $inv_index = self::$inversion_bass_index[$inversion] ?? 0;
                if (isset($intervals_ordered[$inv_index])) {
                    $bass_interval = $intervals_ordered[$inv_index];
                    $root_semitone = ($bass_semitone - $bass_interval + 12) % 12;
                }
            }
        }

        // Choose sharp/flat based on the derived root
        $root_note_name = self::$semitone_notes_sharp[$root_semitone];
        $use_flats = in_array($root_note_name, self::$flat_roots);
        if (!$use_flats) {
            $flat_name = self::$semitone_notes_flat[$root_semitone];
            if (in_array($flat_name, self::$flat_roots)) {
                $use_flats = true;
            }
        }
        $note_map = $use_flats ? self::$semitone_notes_flat : self::$semitone_notes_sharp;

        // --- Build the 6-string arrays ---
        // s=1 (Low E, leftmost) through s=6 (High E, rightmost)
        $labels = array();
        $notes  = array();

        for ($s = 1; $s <= 6; $s++) {
            if (in_array($s, $muted) && !isset($string_frets[$s])) {
                $labels[] = 'x';
                $notes[]  = 'x';
            } elseif (isset($string_frets[$s])) {
                $fret = $string_frets[$s];
                $note_semitone = (self::$tuning[$s] + $fret) % 12;
                $interval_semitone = ($note_semitone - $root_semitone + 12) % 12;

                $notes[] = $note_map[$note_semitone];

                if ($imap && isset($imap[$interval_semitone])) {
                    $labels[] = $imap[$interval_semitone];
                } else {
                    $labels[] = self::$generic_interval_labels[$interval_semitone];
                }
            } else {
                $labels[] = 'x';
                $notes[]  = 'x';
            }
        }

        $result['interval_labels'] = implode(',', $labels);
        $result['notes']           = implode(',', $notes);
        return $result;
    }

    /**
     * Recompute interval_labels and notes for ALL diagrams in the DB.
     *
     * @return array ['updated' => int, 'skipped' => int, 'errors' => array]
     */
    public function recompute_all_intervals() {
        global $wpdb;
        $table = self::get_table_name();

        $diagrams = $wpdb->get_results("SELECT * FROM $table ORDER BY id");
        $updated = 0;
        $skipped = 0;
        $errors  = array();

        foreach ($diagrams as $diagram) {
            $computed = self::compute_intervals_and_notes($diagram);

            if (empty($computed['interval_labels']) && empty($computed['notes'])) {
                $skipped++;
                $errors[] = "#{$diagram->id} ({$diagram->slug}): missing root_note, quality, or positions";
                continue;
            }

            $wpdb->update(
                $table,
                array(
                    'interval_labels' => $computed['interval_labels'],
                    'notes'           => $computed['notes'],
                    'updated_at'      => current_time('mysql')
                ),
                array('id' => $diagram->id),
                array('%s', '%s', '%s'),
                array('%d')
            );
            $updated++;
        }

        return array('updated' => $updated, 'skipped' => $skipped, 'errors' => $errors);
    }

    /**
     * AJAX handler: recompute intervals for all diagrams
     */
    public function ajax_recompute_intervals() {
        check_ajax_referer('sbn_chord_diagrams_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }

        $result = $this->recompute_all_intervals();
        wp_send_json_success($result);
    }

    // =========================================================================
    // ALIASES
    // =========================================================================

    /**
     * Get aliases table name
     */
    public static function get_aliases_table_name() {
        global $wpdb;
        return $wpdb->prefix . 'sbn_chord_diagram_aliases';
    }

    /**
     * Get all aliases for a diagram
     */
    public function get_aliases($diagram_id) {
        global $wpdb;
        $table = self::get_aliases_table_name();
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE diagram_id = %d ORDER BY id ASC",
            $diagram_id
        ));
    }

    /**
     * Compute interval labels for an alias.
     * Uses the parent diagram's fret data but the alias's root note.
     */
    public function compute_alias_intervals($diagram, $alt_root_note, $alt_quality) {
        // Build a fake diagram object with the alias identity
        $alias_obj = clone $diagram;
        $alias_obj->root_note = $alt_root_note;
        $alias_obj->quality   = $alt_quality;
        // Force root_note fallback by clearing root_string
        // (the alias root may not be on the root string)
        $alias_obj->root_string = 'custom';
        $alias_obj->inversion   = 'root';

        return self::compute_intervals_and_notes($alias_obj);
    }

    /**
     * AJAX: Get aliases for a diagram
     */
    public function ajax_get_aliases() {
        check_ajax_referer('sbn_chord_diagrams_nonce', 'nonce');

        $diagram_id = intval($_POST['diagram_id'] ?? 0);
        if (!$diagram_id) {
            wp_send_json_error('Missing diagram_id');
        }

        $aliases = $this->get_aliases($diagram_id);
        wp_send_json_success($aliases);
    }

    /**
     * AJAX: Save a new alias
     */
    public function ajax_save_alias() {
        check_ajax_referer('sbn_chord_diagrams_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }

        global $wpdb;

        $diagram_id     = intval($_POST['diagram_id'] ?? 0);
        $alt_root_note  = sanitize_text_field($_POST['alt_root_note'] ?? '');
        $alt_quality    = sanitize_text_field($_POST['alt_quality'] ?? '');
        $alt_extensions = sanitize_text_field($_POST['alt_extensions'] ?? '');
        $alt_bass_note  = sanitize_text_field($_POST['alt_bass_note'] ?? '');

        if (!$diagram_id || empty($alt_root_note) || empty($alt_quality)) {
            wp_send_json_error('Root note and quality are required');
        }

        // Get parent diagram for interval computation
        $diagram = $this->get_diagram($diagram_id);
        if (!$diagram) {
            wp_send_json_error('Diagram not found');
        }

        // Build display name
        $alt_name = $alt_root_note . $alt_quality;
        if ($alt_extensions) {
            $alt_name .= $alt_extensions;
        }
        if ($alt_bass_note) {
            $alt_name .= '/' . $alt_bass_note;
        }

        // Compute interval labels for this alias identity
        $computed = $this->compute_alias_intervals($diagram, $alt_root_note, $alt_quality);

        $table = self::get_aliases_table_name();
        $result = $wpdb->insert($table, array(
            'diagram_id'      => $diagram_id,
            'alt_name'        => $alt_name,
            'alt_root_note'   => $alt_root_note,
            'alt_quality'     => $alt_quality,
            'alt_extensions'  => $alt_extensions,
            'alt_bass_note'   => $alt_bass_note,
            'interval_labels' => $computed['interval_labels'],
            'notes'           => $computed['notes'],
            'created_at'      => current_time('mysql')
        ));

        if ($result === false) {
            wp_send_json_error('Database error: ' . $wpdb->last_error);
        }

        wp_send_json_success(array(
            'id'              => $wpdb->insert_id,
            'alt_name'        => $alt_name,
            'interval_labels' => $computed['interval_labels'],
            'notes'           => $computed['notes']
        ));
    }

    /**
     * AJAX: Delete an alias
     */
    public function ajax_delete_alias() {
        check_ajax_referer('sbn_chord_diagrams_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }

        global $wpdb;
        $id = intval($_POST['id'] ?? 0);
        if (!$id) {
            wp_send_json_error('Missing alias id');
        }

        $table = self::get_aliases_table_name();
        $wpdb->delete($table, array('id' => $id), array('%d'));
        wp_send_json_success();
    }
}

// Initialize
SBN_Chord_Diagrams::instance();
