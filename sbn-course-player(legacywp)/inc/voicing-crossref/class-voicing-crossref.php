<?php
/**
 * SBN Voicing Cross-Reference
 * 
 * Links leadsheet voicings to chord library archetypes.
 * When a leadsheet is saved, this module:
 * 1. Extracts voicings from the [sbn_voicings] block
 * 2. Matches each voicing against transposed chord diagram shapes
 * 3. Stores matches in a cross-reference table
 * 4. Stores unmatched voicings as reviewable drafts
 * 5. Recalculates popularity counts on chord diagrams
 * 
 * @package SBN_Course_Player
 * @since 7.3.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SBN_Voicing_Crossref {
    
    /**
     * Database table names (without prefix)
     */
    const USAGE_TABLE = 'sbn_voicing_usage';
    const DRAFTS_TABLE = 'sbn_voicing_drafts';
    
    /**
     * Plugin version for this module
     */
    const VERSION = '1.0.0';
    
    /**
     * Singleton instance
     */
    private static $instance = null;
    
    /**
     * Calculator instance (lazy loaded)
     */
    private $calculator = null;
    
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
            
            // AJAX handlers for draft management
            add_action('wp_ajax_sbn_dismiss_voicing_draft', array($this, 'ajax_dismiss_draft'));
            add_action('wp_ajax_sbn_promote_voicing_draft', array($this, 'ajax_promote_draft'));
            add_action('wp_ajax_sbn_clear_all_drafts',      array($this, 'ajax_clear_all_drafts'));
            add_action('wp_ajax_sbn_reprocess_leadsheets', array($this, 'ajax_reprocess_all'));
            add_action('wp_ajax_sbn_debug_match_voicing', array($this, 'ajax_debug_match'));
            add_action('wp_ajax_sbn_debug_reprocess_one', array($this, 'ajax_debug_reprocess_one'));
            
            // Reverse voicing lookup — used by the leadsheet admin after tab extraction
            add_action('wp_ajax_sbn_identify_voicings', array($this, 'ajax_identify_voicings'));
        }
    }
    
    // =========================================================================
    // DATABASE
    // =========================================================================
    
    /**
     * Get full table names with prefix
     */
    public static function get_usage_table() {
        global $wpdb;
        return $wpdb->prefix . self::USAGE_TABLE;
    }
    
    public static function get_drafts_table() {
        global $wpdb;
        return $wpdb->prefix . self::DRAFTS_TABLE;
    }
    
    /**
     * Create database tables on activation
     */
    public static function activate() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        // Cross-reference table: links leadsheet voicings to chord diagrams
        $usage_table = self::get_usage_table();
        $sql_usage = "CREATE TABLE $usage_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            leadsheet_id bigint(20) NOT NULL,
            chord_diagram_id bigint(20) NOT NULL,
            chord_name varchar(30) NOT NULL,
            fret_string varchar(30) NOT NULL,
            position int(11) DEFAULT 1,
            root_note varchar(5) NOT NULL,
            quality varchar(20) NOT NULL,
            base_quality varchar(20) DEFAULT '',
            extensions varchar(30) DEFAULT '',
            bass_note varchar(5) DEFAULT '',
            added_notes varchar(100) DEFAULT '',
            voicing_category varchar(30) DEFAULT '',
            root_string varchar(20) DEFAULT '',
            inversion varchar(10) DEFAULT '',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY leadsheet_id (leadsheet_id),
            KEY chord_diagram_id (chord_diagram_id),
            UNIQUE KEY leadsheet_voicing (leadsheet_id, chord_name, fret_string)
        ) $charset_collate;";
        
        dbDelta($sql_usage);
        
        // Drafts table: unmatched voicings for review
        $drafts_table = self::get_drafts_table();
        $sql_drafts = "CREATE TABLE $drafts_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            leadsheet_id bigint(20) NOT NULL,
            leadsheet_title varchar(255) DEFAULT '',
            chord_name varchar(30) NOT NULL,
            fret_string varchar(30) NOT NULL,
            position int(11) DEFAULT 1,
            fingers varchar(30) DEFAULT '',
            root_note varchar(5) DEFAULT '',
            quality varchar(20) DEFAULT '',
            bass_note varchar(5) DEFAULT '',
            status varchar(20) DEFAULT 'pending',
            notes text DEFAULT '',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY leadsheet_id (leadsheet_id),
            KEY status (status),
            UNIQUE KEY draft_voicing (leadsheet_id, chord_name, fret_string)
        ) $charset_collate;";
        
        dbDelta($sql_drafts);
        
        // Add popularity column to chord diagrams if it doesn't exist
        $diagrams_table = $wpdb->prefix . 'sbn_chord_diagrams';
        $columns = $wpdb->get_col("DESCRIBE $diagrams_table");
        
        if (!in_array('popularity', $columns)) {
            $wpdb->query("ALTER TABLE $diagrams_table ADD COLUMN popularity int(11) DEFAULT 0 AFTER sort_order");
        }

        // Add difficulty column (1–5 scale, 0 = unset)
        if (!in_array('difficulty', $columns)) {
            $wpdb->query("ALTER TABLE $diagrams_table ADD COLUMN difficulty tinyint(1) DEFAULT 0 AFTER popularity");
        }

        // Add shape_family column if not present (v3.0.0 migration)
        // NOTE: The primary migration runs in class-chord-diagrams maybe_migrate_table(),
        // but this ensures it exists even if the crossref module activates independently.
        if (!in_array('shape_family', $columns)) {
            $wpdb->query("ALTER TABLE $diagrams_table ADD COLUMN shape_family varchar(50) DEFAULT '' AFTER bass_note");
        }

        // Add is_fixed_position column if not present (v3.1.0)
        if (!in_array('is_fixed_position', $columns)) {
            $wpdb->query("ALTER TABLE $diagrams_table ADD COLUMN is_fixed_position tinyint(1) DEFAULT 0 AFTER shape_family");
        }

        // Normalize quality '7' → 'dom7' in usage and drafts tables (v3.0.0)
        $wpdb->query("UPDATE $usage_table SET quality = 'dom7' WHERE quality = '7'");
        $wpdb->query("UPDATE $usage_table SET base_quality = 'dom7' WHERE base_quality = '7'");
        $wpdb->query("UPDATE $drafts_table SET quality = 'dom7' WHERE quality = '7'");

        // Add base_quality and extensions columns to usage table if missing
        $usage_columns = $wpdb->get_col("DESCRIBE $usage_table");
        if (!in_array('base_quality', $usage_columns)) {
            $wpdb->query("ALTER TABLE $usage_table ADD COLUMN base_quality varchar(20) DEFAULT '' AFTER quality");
        }
        if (!in_array('extensions', $usage_columns)) {
            $wpdb->query("ALTER TABLE $usage_table ADD COLUMN extensions varchar(30) DEFAULT '' AFTER base_quality");
        }
        if (!in_array('added_notes', $usage_columns)) {
            $wpdb->query("ALTER TABLE $usage_table ADD COLUMN added_notes varchar(100) DEFAULT '' AFTER bass_note");
        }
        
        update_option('sbn_voicing_crossref_version', self::VERSION);
    }
    
    // =========================================================================
    // CORE MATCHING ENGINE
    // =========================================================================
    
    /**
     * Quality aliases: maps parser-normalized names to DB-stored names
     * and vice versa. DB canonical form is 'dom7' (not '7').
     * The '7' alias is kept for backward compatibility with parsers.
     */
    private static $quality_aliases = array(
        'dom7' => array('dom7', '7'),
        '7'    => array('dom7', '7'),
        'min'  => array('min', 'm'),
        'm'    => array('m', 'min'),
        'maj'  => array('maj'),
        'maj7' => array('maj7'),
        'm7'   => array('m7'),
        'm7b5' => array('m7b5'),
        'o7'   => array('o7', 'dim7'),
        'dim7' => array('dim7', 'o7'),
        'dim'  => array('dim'),
        'aug'  => array('aug', '+'),
        '+'    => array('+', 'aug'),
        'mMaj7' => array('mMaj7'),
        'aug7' => array('aug7'),
        'maj6' => array('maj6'),
        'm6'   => array('m6'),
        'sus4' => array('sus4'),
        'sus2' => array('sus2'),
        'add9' => array('add9'),
        '5'    => array('5'),
    );
    
    /**
     * Known base qualities — used to split extensions from quality strings.
     * MUST be sorted longest-first so greedy matching works correctly.
     * (e.g., 'm7' must be checked before 'm' to avoid splitting 'm7' into 'm' + '7')
     */
    private static $known_base_qualities = array(
        // 5-char
        'mMaj7', 'mmaj7', 'maj13', 'maj11',
        // 4-char
        'maj9', 'maj7', 'maj6', 'm7b5', 'aug7', 'dim7', 'min7', 'min6', 'dom7', 'add9', 'sus4', 'sus2',
        // 3-char
        'mM7', 'm13', 'm11', 'maj', 'min', 'dim', 'aug', 'sus',
        // 2-char
        'M7', 'M6', 'o7', 'm9', 'm7', '-7', 'm6', '13', '11',
        // 1-char
        '9', '7', '5', 'M', 'm', '-', 'o', '+',
    );
    
    /**
     * Split a chord quality string into base quality + extensions
     * 
     * Handles formats like:
     *   "7(#11)"  → base: "7", extensions: "#11"
     *   "m7(9)"   → base: "m7", extensions: "9"
     *   "7(b9)"   → base: "7", extensions: "b9"
     *   "13b9"    → base: "13", extensions: "b9"
     *   "m7b5"    → base: "m7b5", extensions: ""
     *   "dom7"    → base: "dom7", extensions: ""
     *   "m7"      → base: "m7", extensions: ""
     * 
     * @param string $quality Raw quality string from parser
     * @return array ['base' => string, 'extensions' => string]
     */
    private function split_quality_extensions($quality) {
        // First strip parenthesized extensions: "7(#11)" → base "7", ext "#11"
        if (preg_match('/^(.+?)\(([^)]+)\)$/', $quality, $m)) {
            return array(
                'base' => trim($m[1]),
                'extensions' => trim($m[2]),
            );
        }
        
        // Try to find a known base quality at the start of the string
        foreach (self::$known_base_qualities as $base) {
            // Exact match — no extension, return immediately
            if ($quality === $base) {
                return array(
                    'base' => $base,
                    'extensions' => '',
                );
            }
            
            // Base is a prefix with extra text — check if remainder is an extension
            if (strpos($quality, $base) === 0 && strlen($quality) > strlen($base)) {
                $ext = substr($quality, strlen($base));
                // Only split if the remainder looks like an extension (starts with b, #, or digit)
                if (preg_match('/^[b#\d]/', $ext)) {
                    return array(
                        'base' => $base,
                        'extensions' => $ext,
                    );
                }
            }
        }
        
        // No extension found — quality is self-contained
        return array(
            'base' => $quality,
            'extensions' => '',
        );
    }
    
    /**
     * Get all possible DB quality values for a given quality string.
     * Handles aliases (dom7 ↔ 7) so the query finds shapes regardless
     * of which variant was stored.
     * 
     * @param string $quality The quality to look up
     * @return array Array of quality strings to try
     */
    private function get_quality_variants($quality) {
        // Check alias table
        $lc = $quality;
        if (isset(self::$quality_aliases[$lc])) {
            return self::$quality_aliases[$lc];
        }
        
        // No aliases — just return as-is
        return array($quality);
    }
    
    /**
     * Get the chord shape calculator (lazy loaded)
     */
    private function get_calculator() {
        if ($this->calculator === null) {
            if (!class_exists('SBN_Chord_Shape_Calculator')) {
                require_once SBN_PLUGIN_DIR . 'inc/chord-shapes/class-chord-shape-calculator.php';
            }
            $this->calculator = new SBN_Chord_Shape_Calculator();
        }
        return $this->calculator;
    }
    
    /**
     * Process a leadsheet after save — main entry point
     * 
     * Called from the leadsheet save hook. Extracts voicings,
     * matches them against the chord library, stores results.
     * 
     * @param int    $leadsheet_id   The leadsheet ID
     * @param string $shortcode_content The full shortcode content
     * @param string $leadsheet_title  The leadsheet title (for draft labeling)
     */
    public function process_leadsheet($leadsheet_id, $shortcode_content, $leadsheet_title = '') {
        // Extract voicings from the shortcode content
        $voicings = $this->extract_voicings($shortcode_content);
        
        if (empty($voicings)) {
            // No voicings block — clean up any old references for this leadsheet
            $this->clear_leadsheet_references($leadsheet_id);
            return;
        }
        
        // Clear previous references for this leadsheet (re-processing)
        $this->clear_leadsheet_references($leadsheet_id);
        
        // Match each voicing against the chord library
        $matched = 0;
        $unmatched = 0;
        
        foreach ($voicings as $voicing) {
            $result = $this->match_voicing($voicing);
            
            if ($result !== false) {
                // Found a match — store in usage table
                $this->store_match($leadsheet_id, $voicing, $result);
                $matched++;
                error_log("SBN Crossref PROCESS: '{$voicing['chord_name']}' ({$voicing['fret_string']}) → MATCHED diagram #{$result['diagram_id']}");
            } else {
                // No match — store as draft for review
                $this->store_draft($leadsheet_id, $leadsheet_title, $voicing);
                $unmatched++;
                error_log("SBN Crossref PROCESS: '{$voicing['chord_name']}' ({$voicing['fret_string']}) → UNMATCHED (quality: '{$voicing['quality']}')");
            }
        }
        
        // Recalculate popularity for all affected diagrams
        $this->recalculate_popularity();
        
        error_log("SBN Voicing Crossref: Processed leadsheet #$leadsheet_id — $matched matched, $unmatched unmatched");
    }
    
    /**
     * Extract voicings from shortcode content
     * 
     * Parses the [sbn_voicings]...[/sbn_voicings] block.
     * Returns array of voicing data with parsed chord name info.
     * 
     * @param string $content Full shortcode content
     * @return array Array of voicing arrays with keys: chord_name, fret_string, position, fingers, root, quality
     */
    private function extract_voicings($content) {
        $voicings = array();
        
        // Match the voicings block
        if (!preg_match('/\[sbn_voicings\]([\s\S]*?)\[\/sbn_voicings\]/', $content, $match)) {
            return $voicings;
        }
        
        $voicings_text = $match[1];
        $lines = array_filter(explode("\n", $voicings_text), 'trim');
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            
            // Format: ChordName: frets @position (fingers)
            // Example: G7: 3x3453 @3 (102043)
            // High positions: Bbmaj7: x8a9ax @8
            // Override keys: Gm7@12.0: 3x336x (or legacy Gm7@12:0: ...)
            // Split on ": " (colon-space) to handle keys containing colons
            $sepPos = strpos($line, ': ');
            if ($sepPos === false) continue;
            
            $chord_name = trim(substr($line, 0, $sepPos));
            $rest = trim(substr($line, $sepPos + 2));
            
            if (!preg_match('/^([x0-9a-fA-F]+)(?:\s*@(\d+))?(?:\s*\(([0-9]+)\))?/', $rest, $m)) continue;
            
            $fret_string = trim($m[1]);
            $position = isset($m[2]) ? intval($m[2]) : 1;
            $fingers = isset($m[3]) ? $m[3] : '';
                
            // Skip per-measure override keys (ChordName@measureIdx or ChordName@measureIdx.chordIdx)
            if (preg_match('/@\d+(\.\d+)?$/', $chord_name)) continue;
            // Also skip legacy colon-based override keys (ChordName@measureIdx:chordIdx)
            if (preg_match('/@\d+:\d+$/', $chord_name)) continue;
            
            // Skip legacy per-instance keys (#X.Y)
            if (preg_match('/^#\d+\.\d+$/', $chord_name)) continue;
            
            // Validate fret string: must be exactly 6 chars
            if (strlen($fret_string) !== 6) continue;
            
            // Parse the chord name to get root + quality
            if (!function_exists('sbn_parse_chord_name')) {
                require_once SBN_PLUGIN_DIR . 'inc/chord-search-handler.php';
            }
            $parsed = sbn_parse_chord_name($chord_name);
            
            $voicings[] = array(
                'chord_name' => $chord_name,
                'fret_string' => $fret_string,
                'position' => $position,
                'fingers' => $fingers,
                'root' => $parsed ? $parsed['root'] : '',
                'quality' => $parsed ? $parsed['quality'] : '',
                'extension' => $parsed ? ($parsed['extension'] ?? '') : '',
                'bass_note' => $parsed ? ($parsed['bass_note'] ?? '') : '',
            );
        }
        
        return $voicings;
    }
    
    /**
     * Match a single voicing against the chord library
     * 
     * Finds all archetype shapes for this quality, transposes each
     * to the target root, and compares the resulting fret positions
     * against the leadsheet's fret string.
     * 
     * For slash chords, additional logic:
     * - Inversions (Am/C): filter shapes by matching inversion
     * - True slash (F/G): match against shapes with bass_note set,
     *   using bass-aware transposition
     * 
     * @param array $voicing Voicing data from extract_voicings()
     * @return array|false Matched shape data or false if no match
     */
    private function match_voicing($voicing) {
        if (empty($voicing['root']) || empty($voicing['quality'])) {
            error_log("SBN Crossref: Skipping voicing '{$voicing['chord_name']}' — no root or quality parsed");
            return false;
        }
        
        global $wpdb;
        $diagrams_table = $wpdb->prefix . 'sbn_chord_diagrams';
        
        // Use pre-parsed extension from sbn_parse_chord_name, fall back to split_quality_extensions
        $base_quality = $voicing['quality'];
        $extensions = $voicing['extension'] ?? '';
        if (empty($extensions)) {
            // Legacy fallback: try splitting quality if extension wasn't parsed separately
            $quality_parts = $this->split_quality_extensions($voicing['quality']);
            $base_quality = $quality_parts['base'];
            $extensions = $quality_parts['extensions'];
        }
        
        // Get all alias variants for the base quality (dom7 ↔ 7, etc.)
        $quality_variants = $this->get_quality_variants($base_quality);
        
        // Build SQL with all quality variants
        $placeholders = implode(',', array_fill(0, count($quality_variants), '%s'));
        $query = "SELECT * FROM $diagrams_table WHERE quality IN ($placeholders)";
        
        // If we have extensions, also filter by extensions column
        if (!empty($extensions)) {
            $query .= " AND extensions = %s";
            $params = array_merge($quality_variants, array($extensions));
        } else {
            $params = $quality_variants;
        }
        
        $shapes = $wpdb->get_results($wpdb->prepare($query, $params));
        
        // If no exact extension match found, try without extension filter as fallback
        // (the shape might match by fret positions even without the extension tag)
        if (empty($shapes) && !empty($extensions)) {
            $shapes = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $diagrams_table WHERE quality IN ($placeholders)",
                $quality_variants
            ));
            if (!empty($shapes)) {
                error_log("SBN Crossref: No shapes with extensions='{$extensions}', falling back to all '{$base_quality}' shapes");
            }
        }
        
        if (empty($shapes)) {
            error_log("SBN Crossref: No shapes in DB for quality '{$voicing['quality']}' (base: '{$base_quality}', ext: '{$extensions}', variants: " . implode(', ', $quality_variants) . ")");
        }
        
        $calculator = $this->get_calculator();
        
        // ── Alias-based lookup ──
        // If no shapes found by primary quality, or as an additional pool,
        // look for diagrams that have ALIASES matching the target quality+extensions.
        // e.g. A dim7 shape with alias "Gdom7b9/B" should match "B7(b9)/D#"
        $aliases_table = $wpdb->prefix . 'sbn_chord_diagram_aliases';
        $alias_ext_filter = !empty($extensions) ? $extensions : '';
        
        // Build quality variants for alias search
        $alias_quality_variants = $quality_variants;
        
        if (!empty($alias_ext_filter)) {
            // Try with extension: alt_quality IN ('dom7','7') AND alt_extensions = 'b9'
            $alias_ph = implode(',', array_fill(0, count($alias_quality_variants), '%s'));
            $alias_params = array_merge($alias_quality_variants, array($alias_ext_filter));
            $alias_rows = $wpdb->get_results($wpdb->prepare(
                "SELECT a.*, d.id AS parent_id, d.slug, d.root_note AS parent_root_note, 
                        d.quality AS parent_quality, d.voicing_category, d.root_string, 
                        d.inversion, d.diagram_data, d.start_fret
                 FROM $aliases_table a
                 JOIN $diagrams_table d ON a.diagram_id = d.id
                 WHERE a.alt_quality IN ($alias_ph) AND a.alt_extensions = %s",
                $alias_params
            ));
            
            // Also try without extension filter if nothing found
            if (empty($alias_rows)) {
                $alias_rows = $wpdb->get_results($wpdb->prepare(
                    "SELECT a.*, d.id AS parent_id, d.slug, d.root_note AS parent_root_note, 
                            d.quality AS parent_quality, d.voicing_category, d.root_string, 
                            d.inversion, d.diagram_data, d.start_fret
                     FROM $aliases_table a
                     JOIN $diagrams_table d ON a.diagram_id = d.id
                     WHERE a.alt_quality IN ($alias_ph)",
                    $alias_quality_variants
                ));
            }
        } else {
            $alias_ph = implode(',', array_fill(0, count($alias_quality_variants), '%s'));
            $alias_rows = $wpdb->get_results($wpdb->prepare(
                "SELECT a.*, d.id AS parent_id, d.slug, d.root_note AS parent_root_note, 
                        d.quality AS parent_quality, d.voicing_category, d.root_string, 
                        d.inversion, d.diagram_data, d.start_fret
                 FROM $aliases_table a
                 JOIN $diagrams_table d ON a.diagram_id = d.id
                 WHERE a.alt_quality IN ($alias_ph)",
                $alias_quality_variants
            ));
        }
        
        // Convert alias rows to shape objects with the alias context for transposition
        if (!empty($alias_rows)) {
            $alias_shapes = array();
            foreach ($alias_rows as $ar) {
                // Compute the inversion from the alias context
                // e.g. alias Gdom7b9/B: root=G, bass=B, interval=4 → inv1
                $alias_inversion = 'root';
                if (!empty($ar->alt_bass_note)) {
                    $slash_info = $calculator->analyze_slash_chord($ar->alt_root_note, $ar->alt_quality, $ar->alt_bass_note);
                    if ($slash_info['type'] === 'inversion' && !empty($slash_info['inversion'])) {
                        $alias_inversion = $slash_info['inversion'];
                    }
                }
                
                // Build a shape object that uses the alias context for transposition
                $alias_shape = new stdClass();
                $alias_shape->id = $ar->parent_id;
                $alias_shape->slug = $ar->slug . '/alias:' . $ar->alt_name;
                $alias_shape->root_note = $ar->alt_root_note; // alias root for transposition
                $alias_shape->quality = $ar->alt_quality;      // alias quality for interval calc
                $alias_shape->voicing_category = $ar->voicing_category;
                $alias_shape->root_string = $ar->root_string;
                $alias_shape->inversion = $alias_inversion;    // computed from alias context
                $alias_shape->diagram_data = $ar->diagram_data;
                $alias_shape->start_fret = $ar->start_fret;
                $alias_shape->bass_note = $ar->alt_bass_note ?? '';
                $alias_shape->_is_alias = true;
                $alias_shapes[] = $alias_shape;
            }
            error_log("SBN Crossref: Found " . count($alias_shapes) . " alias-matched shapes for '{$voicing['chord_name']}'");
            // Merge into shapes pool (alias shapes come after primary shapes)
            $shapes = array_merge($shapes ?: array(), $alias_shapes);
        }
        
        if (empty($shapes)) {
            error_log("SBN Crossref: No shapes (including aliases) for quality '{$voicing['quality']}' (base: '{$base_quality}', ext: '{$extensions}')");
            return false;
        }
        
        $bass_note = $voicing['bass_note'] ?? '';
        $bass_debug = !empty($bass_note) ? " (slash: /{$bass_note})" : '';
        error_log("SBN Crossref: Matching '{$voicing['chord_name']}' (frets: {$voicing['fret_string']}){$bass_debug} against " . count($shapes) . " shapes of quality '{$voicing['quality']}'");
        
        // Parse the leadsheet fret string into a comparable array,
        // then normalise open-string/equivalent-fret substitutions so that
        // e.g. x0567x and 5x567x compare as equal shapes.
        $target_fret_array = $this->normalize_open_equivalents(
            $this->parse_fret_string($voicing['fret_string'], $voicing['position'] ?? 1)
        );
        
        // Analyze slash chord type if bass note present
        $slash_type = '';
        $target_inversion = '';
        
        if (!empty($bass_note)) {
            $slash_info = $calculator->analyze_slash_chord($voicing['root'], $voicing['quality'], $bass_note);
            $slash_type = $slash_info['type'];
            $target_inversion = $slash_info['inversion'];
            error_log("SBN Crossref: Slash analysis — type: {$slash_type}, inversion: {$target_inversion}");
        }
        
        $best_match = null;
        $best_match_extra_strings = 999;
        
        foreach ($shapes as $shape) {
            // Skip fixed-position shapes when matching a different root
            if (!empty($shape->is_fixed_position) && strtolower($shape->root_note) !== strtolower($voicing['root'])) {
                continue;
            }
            
            // For inversion slash chords, only try shapes matching the inversion
            if ($slash_type === 'inversion' && !empty($target_inversion)) {
                if ($shape->inversion !== $target_inversion) {
                    continue;
                }
            }
            
            // For true slash chords, prefer shapes with bass_note set
            if ($slash_type === 'slash') {
                if (!empty($shape->bass_note)) {
                    $calculated = $calculator->calculate_frets_with_bass($shape, $voicing['root'], $bass_note);
                } else {
                    // Skip standard shapes for true slash matching
                    continue;
                }
            } else {
                // Standard transposition
                $calculated = $calculator->calculate_frets($shape, $voicing['root']);
            }
            
            if (!$calculated || empty($calculated['diagram_data'])) {
                continue;
            }
            
            // Convert the calculated positions to a fret array for comparison,
            // normalising open-string equivalents on both sides.
            $calculated_fret_array = $this->normalize_open_equivalents(
                $this->diagram_to_fret_array($calculated['diagram_data'])
            );
            $calc_display = $this->fret_array_to_string($calculated_fret_array);
            
            // Skip shapes that produce negative frets (invalid transposition)
            if (!$this->has_valid_frets($calculated_fret_array)) {
                error_log("SBN Crossref:   Shape {$shape->slug} → frets: {$calc_display} ✗ NEGATIVE FRETS (skipping)");
                continue;
            }
            
            // Check for exact match first
            if ($this->fret_arrays_match($calculated_fret_array, $target_fret_array)) {
                error_log("SBN Crossref:   Shape {$shape->slug} → frets: {$calc_display} ✓ EXACT MATCH");
                return array(
                    'diagram_id' => $shape->id,
                    'voicing_category' => $shape->voicing_category,
                    'root_string' => $shape->root_string,
                    'inversion' => $shape->inversion,
                    'match_type' => 'exact',
                    'calculated_frets' => $calculated_fret_array,
                );
            }
            
            // Check for subset match (leadsheet omits strings from library shape)
            $subset_result = $this->is_subset_match($target_fret_array, $calculated_fret_array);
            
            if ($subset_result !== false) {
                error_log("SBN Crossref:   Shape {$shape->slug} → frets: {$calc_display} ~ SUBSET MATCH ({$subset_result} extra strings)");
                if ($subset_result < $best_match_extra_strings) {
                    $best_match_extra_strings = $subset_result;
                    $best_match = array(
                        'diagram_id' => $shape->id,
                        'voicing_category' => $shape->voicing_category,
                        'root_string' => $shape->root_string,
                        'inversion' => $shape->inversion,
                        'match_type' => 'subset',
                        'calculated_frets' => $calculated_fret_array,
                    );
                }
            } else {
                // Check for superset match (leadsheet adds strings to library shape)
                $superset_result = $this->is_superset_match($target_fret_array, $calculated_fret_array);
                
                if ($superset_result !== false) {
                    error_log("SBN Crossref:   Shape {$shape->slug} → frets: {$calc_display} ~ SUPERSET MATCH ({$superset_result} extra strings in leadsheet)");
                    // Superset matches are slightly less preferred than subset matches,
                    // so add a small penalty to the extra_strings count
                    $penalized = $superset_result + 100; // ensures subset matches win over superset
                    if ($penalized < $best_match_extra_strings) {
                        $best_match_extra_strings = $penalized;
                        $best_match = array(
                            'diagram_id' => $shape->id,
                            'voicing_category' => $shape->voicing_category,
                            'root_string' => $shape->root_string,
                            'inversion' => $shape->inversion,
                            'match_type' => 'superset',
                            'calculated_frets' => $calculated_fret_array,
                        );
                    }
                } else {
                    // Check for root-relocated fragment match
                    // (bass note moved to an open string on a different string)
                    $fragment_result = $this->is_root_relocated_fragment_match(
                        $target_fret_array, $calculated_fret_array,
                        $voicing['root'], $base_quality
                    );
                    
                    if ($fragment_result !== false) {
                        error_log("SBN Crossref:   Shape {$shape->slug} → frets: {$calc_display} ~ FRAGMENT MATCH (bass relocated, {$fragment_result} extra lib strings)");
                        // Fragment matches are less preferred than superset (penalty 200+)
                        $penalized = $fragment_result + 200;
                        if ($penalized < $best_match_extra_strings) {
                            $best_match_extra_strings = $penalized;
                            $best_match = array(
                                'diagram_id' => $shape->id,
                                'voicing_category' => $shape->voicing_category,
                                'root_string' => $shape->root_string,
                                'inversion' => $shape->inversion,
                                'match_type' => 'fragment',
                                'calculated_frets' => $calculated_fret_array,
                            );
                        }
                    } else {
                        error_log("SBN Crossref:   Shape {$shape->slug} → frets: {$calc_display}");
                    }
                }
            }
        }
        
        // For inversion slash chords (e.g., G/D = inv2) with no dedicated
        // inversion shape match, try root-position shapes as a fallback.
        // The bass note (the inversion tone) may be an added/doubled note
        // on top of a root-position shape, matched via superset or fragment.
        if ($slash_type === 'inversion' && !empty($target_inversion) && $target_inversion !== 'root' && !$best_match) {
            error_log("SBN Crossref: No inv-specific shapes matched for '{$voicing['chord_name']}' — trying root-position fallback");
            foreach ($shapes as $shape) {
                // Only try shapes we skipped before (non-matching inversion)
                if ($shape->inversion === $target_inversion) continue;
                if (!empty($shape->is_fixed_position) && strtolower($shape->root_note) !== strtolower($voicing['root'])) continue;
                
                $calculated = $calculator->calculate_frets($shape, $voicing['root']);
                if (!$calculated || empty($calculated['diagram_data'])) continue;
                
                $calculated_fret_array = $this->normalize_open_equivalents(
                    $this->diagram_to_fret_array($calculated['diagram_data'])
                );
                $calc_display = $this->fret_array_to_string($calculated_fret_array);
                if (!$this->has_valid_frets($calculated_fret_array)) continue;
                
                // Try superset (leadsheet adds the bass note to library shape)
                $superset_result = $this->is_superset_match($target_fret_array, $calculated_fret_array);
                if ($superset_result !== false) {
                    error_log("SBN Crossref:   Shape {$shape->slug} → frets: {$calc_display} ~ SUPERSET MATCH (inv fallback, {$superset_result} extra)");
                    $penalized = $superset_result + 150; // between regular superset (100) and fragment (200)
                    if ($penalized < $best_match_extra_strings) {
                        $best_match_extra_strings = $penalized;
                        $best_match = array(
                            'diagram_id' => $shape->id,
                            'voicing_category' => $shape->voicing_category,
                            'root_string' => $shape->root_string,
                            'inversion' => $shape->inversion,
                            'match_type' => 'superset',
                            'calculated_frets' => $calculated_fret_array,
                        );
                    }
                } else {
                    // Try fragment (bass note relocated to open string)
                    $fragment_result = $this->is_root_relocated_fragment_match(
                        $target_fret_array, $calculated_fret_array,
                        $voicing['root'], $base_quality
                    );
                    if ($fragment_result !== false) {
                        error_log("SBN Crossref:   Shape {$shape->slug} → frets: {$calc_display} ~ FRAGMENT MATCH (inv fallback, {$fragment_result} extra)");
                        $penalized = $fragment_result + 250; // inv fallback fragment is lowest priority
                        if ($penalized < $best_match_extra_strings) {
                            $best_match_extra_strings = $penalized;
                            $best_match = array(
                                'diagram_id' => $shape->id,
                                'voicing_category' => $shape->voicing_category,
                                'root_string' => $shape->root_string,
                                'inversion' => $shape->inversion,
                                'match_type' => 'fragment',
                                'calculated_frets' => $calculated_fret_array,
                            );
                        }
                    }
                }
            }
        }
        
        // For true slash chords with no dedicated shape match, try matching
        // against ALL shapes (not just those with bass_note) as a fallback
        if ($slash_type === 'slash' && !$best_match) {
            error_log("SBN Crossref: No dedicated slash shapes matched — trying all shapes as fallback");
            foreach ($shapes as $shape) {
                if (!empty($shape->bass_note)) continue; // Already tried these
                
                $calculated = $calculator->calculate_frets($shape, $voicing['root']);
                if (!$calculated || empty($calculated['diagram_data'])) continue;
                
                $calculated_fret_array = $this->normalize_open_equivalents(
                    $this->diagram_to_fret_array($calculated['diagram_data'])
                );
                $calc_display = $this->fret_array_to_string($calculated_fret_array);
                
                // Skip invalid transpositions
                if (!$this->has_valid_frets($calculated_fret_array)) continue;
                
                if ($this->fret_arrays_match($calculated_fret_array, $target_fret_array)) {
                    error_log("SBN Crossref:   Shape {$shape->slug} → frets: {$calc_display} ✓ EXACT MATCH (slash fallback)");
                    return array(
                        'diagram_id' => $shape->id,
                        'voicing_category' => $shape->voicing_category,
                        'root_string' => $shape->root_string,
                        'inversion' => $shape->inversion,
                        'match_type' => 'exact',
                        'calculated_frets' => $calculated_fret_array,
                    );
                }
                
                $subset_result = $this->is_subset_match($target_fret_array, $calculated_fret_array);
                if ($subset_result !== false && $subset_result < $best_match_extra_strings) {
                    $best_match_extra_strings = $subset_result;
                    $best_match = array(
                        'diagram_id' => $shape->id,
                        'voicing_category' => $shape->voicing_category,
                        'root_string' => $shape->root_string,
                        'inversion' => $shape->inversion,
                        'match_type' => 'subset',
                        'calculated_frets' => $calculated_fret_array,
                    );
                } else {
                    $superset_result = $this->is_superset_match($target_fret_array, $calculated_fret_array);
                    if ($superset_result !== false) {
                        $penalized = $superset_result + 100;
                        if ($penalized < $best_match_extra_strings) {
                            $best_match_extra_strings = $penalized;
                            $best_match = array(
                                'diagram_id' => $shape->id,
                                'voicing_category' => $shape->voicing_category,
                                'root_string' => $shape->root_string,
                                'inversion' => $shape->inversion,
                                'match_type' => 'superset',
                                'calculated_frets' => $calculated_fret_array,
                            );
                        }
                    } else {
                        // Root-relocated fragment in slash fallback
                        $fragment_result = $this->is_root_relocated_fragment_match(
                            $target_fret_array, $calculated_fret_array,
                            $voicing['root'], $base_quality
                        );
                        if ($fragment_result !== false) {
                            $penalized = $fragment_result + 200;
                            if ($penalized < $best_match_extra_strings) {
                                $best_match_extra_strings = $penalized;
                                $best_match = array(
                                    'diagram_id' => $shape->id,
                                    'voicing_category' => $shape->voicing_category,
                                    'root_string' => $shape->root_string,
                                    'inversion' => $shape->inversion,
                                    'match_type' => 'fragment',
                                    'calculated_frets' => $calculated_fret_array,
                                );
                            }
                        }
                    }
                }
            }
        }
        
        if ($best_match) {
            error_log("SBN Crossref: Best subset match for '{$voicing['chord_name']}' ({$voicing['fret_string']}): diagram #{$best_match['diagram_id']} with {$best_match_extra_strings} extra string(s)");
            return $best_match;
        }
        
        error_log("SBN Crossref: No match found for '{$voicing['chord_name']}' ({$voicing['fret_string']})");
        return false;
    }
    
    /**
     * Normalise open-string / equivalent-fret substitutions before comparison.
     *
     * On guitar the same pitch can be played on two adjacent strings:
     *
     *   String 1 (low E) fret 5  = A2  ↔  String 2 (A)    fret 0 open
     *   String 2 (A)     fret 5  = D3  ↔  String 3 (D)    fret 0 open
     *   String 3 (D)     fret 5  = G3  ↔  String 4 (G)    fret 0 open
     *   String 4 (G)     fret 4  = B3  ↔  String 5 (B)    fret 0 open  ← 4, not 5
     *   String 5 (B)     fret 5  = E4  ↔  String 6 (high e) fret 0 open
     *
     * Plugin string numbering: 1 = low E … 6 = high e (low-to-high).
     * Fret array indices: 0-based, index 0 = string 1 = low E.
     *
     * When one shape plays a note on the lower string and the other plays the
     * same pitch open on the adjacent higher string — and the other slot is
     * muted — they are the same chord shape in a different string assignment.
     * e.g.  x0567x  ↔  5x567x  (A-string open vs E-string fret 5)
     *       0x676x  ↔  x7676x  (E-string open vs A-string fret 7... wait, that's
     *                            a non-standard interval — handled by pitch-class
     *                            equality check below)
     *
     * Canonical form: prefer the open string on the HIGHER string (lower index
     * in the fret array is the lower-pitched string), because that is the more
     * common way open-position shapes are stored in the library.
     *
     * Fires only when EXACTLY ONE of the two equivalent slots is muted;
     * if both strings are sounding the note is genuinely doubled and should
     * not be collapsed.
     *
     * @param array $frets  6-element array, index 0 = string 1 (low E), values 'x' or int
     * @return array        Normalised 6-element array
     */
    private function normalize_open_equivalents(array $frets) {
        // Each entry: [ lower_string_array_idx, fret_on_lower, higher_string_array_idx ]
        // "fret_on_lower" is the fret number on the lower-pitched string that produces
        // the same pitch as open (fret 0) on the adjacent higher-pitched string.
        //
        // Array index 0 = string 1 (low E), index 5 = string 6 (high e).
        //
        // TWO families of equivalences:
        //
        // UPWARD (5-fret rule): lower string fretted → higher string open
        //   idx 0 fret 5  ↔  idx 1 fret 0   (E fret5  = A open,  A2)
        //   idx 1 fret 5  ↔  idx 2 fret 0   (A fret5  = D open,  D3)
        //   idx 2 fret 5  ↔  idx 3 fret 0   (D fret5  = G open,  G3)
        //   idx 3 fret 4  ↔  idx 4 fret 0   (G fret4  = B open,  B3)  ← 4, not 5
        //   idx 4 fret 5  ↔  idx 5 fret 0   (B fret5  = e open,  E4)
        //
        // DOWNWARD (7-fret rule): higher string fretted → lower string open
        //   idx 0 fret 0  ↔  idx 1 fret 7   (E open   = A fret7, E2)
        //   idx 1 fret 0  ↔  idx 2 fret 7   (A open   = D fret7, A2)
        //   idx 2 fret 0  ↔  idx 3 fret 7   (D open   = G fret7, D3)
        //   idx 3 fret 0  ↔  idx 4 fret 8   (G open   = B fret8, G3)  ← 8, not 7
        //   idx 4 fret 0  ↔  idx 5 fret 7   (B open   = e fret7, B3)
        //
        // e.g. x0567x  ↔  5x567x  (upward:   A open   = E fret5)
        //      0x676x  ↔  x7676x  (downward:  E open   = A fret7)
        //
        // Canonical form: the open-string version is preferred (fret 0 on whichever
        // string plays it open) so both sides normalise to the same representation.
        // Fires only when exactly ONE of the two equivalent slots is muted.

        // Upward pairs: [lo_idx, fret_on_lo, hi_idx]
        $upward = array(
            array(0, 5, 1),
            array(1, 5, 2),
            array(2, 5, 3),
            array(3, 4, 4),  // G→B: 4 frets, not 5
            array(4, 5, 5),
        );

        // Downward pairs: [lo_idx, hi_idx, fret_on_hi]
        // Canonical = open on lo_idx (fret 0); normalise hi fret → lo open.
        $downward = array(
            array(0, 1, 7),
            array(1, 2, 7),
            array(2, 3, 7),
            array(3, 4, 8),  // G→B: 8 frets, not 7
            array(4, 5, 7),
        );

        $out = $frets;

        // Upward: lo fretted, hi muted → lo muted, hi open
        foreach ($upward as $pair) {
            list($lo_idx, $fret_on_lo, $hi_idx) = $pair;
            $lo_val = $out[$lo_idx];
            $hi_val = $out[$hi_idx];
            if ($lo_val !== 'x' && intval($lo_val) === $fret_on_lo && $hi_val === 'x') {
                $out[$lo_idx] = 'x';
                $out[$hi_idx] = 0;
            }
        }

        // Downward: hi fretted, lo muted → hi muted, lo open
        foreach ($downward as $pair) {
            list($lo_idx, $hi_idx, $fret_on_hi) = $pair;
            $lo_val = $out[$lo_idx];
            $hi_val = $out[$hi_idx];
            if ($hi_val !== 'x' && intval($hi_val) === $fret_on_hi && $lo_val === 'x') {
                $out[$hi_idx] = 'x';
                $out[$lo_idx] = 0;
            }
        }

        return $out;
    }

    /**
     * Parse a leadsheet fret string into a per-string array
     * 
     * Handles both single-digit frets ("x5x565") and, for future
     * compatibility, could be extended for multi-digit via separators.
     * 
     * @param string $fret_string Fret string (e.g., "x5x565", "x02010")
     * @return array Array of 6 elements, each 'x' (muted) or int (fret number)
     */
    /**
     * Parse a fret string into a 6-element array
     * 
     * Handles both standard 6-char strings (e.g., "x02010") and multi-digit
     * fret strings (e.g., "x81089x" → [x, 8, 10, 8, 9, x]).
     * 
     * For strings longer than 6 characters, uses brute-force partition search
     * to find the most plausible 6-element interpretation, scored by:
     * - Proximity to @position (if provided)
     * - Fret span (guitar voicings rarely exceed 5 fret span)
     * - Plausibility (frets 0-24)
     * 
     * @param string $fret_string The fret string (e.g., "x81089x")
     * @param int    $position    Starting position hint from @position (default 1)
     * @return array Array of 6 elements: 'x' or int
     */
    private function parse_fret_string($fret_string, $position = 1) {
        // Quick path: 6 chars — each char is x or hex digit (0-9, a-f)
        if (strlen($fret_string) <= 6) {
            $result = array();
            for ($i = 0; $i < strlen($fret_string); $i++) {
                $c = strtolower($fret_string[$i]);
                if ($c === 'x') {
                    $result[] = 'x';
                } elseif (ctype_xdigit($c)) {
                    $result[] = hexdec($c);
                } else {
                    $result[] = intval($c);
                }
            }
            while (count($result) < 6) {
                $result[] = 'x';
            }
            return $result;
        }
        
        // Multi-digit case: find the best 6-element partition
        $best = null;
        $best_score = PHP_INT_MAX;
        
        $this->_fret_parse_search($fret_string, 0, array(), $position, $best, $best_score);
        
        if ($best !== null) {
            return $best;
        }
        
        // Fallback: truncate to 6 chars
        error_log("SBN Crossref: Could not parse multi-digit fret string '{$fret_string}' @{$position}, falling back to truncation");
        return $this->parse_fret_string(substr($fret_string, 0, 6), $position);
    }
    
    /**
     * Recursive search for best 6-element partition of a fret string
     * 
     * @param string $s         Remaining characters to parse
     * @param int    $depth     Number of elements parsed so far
     * @param array  $current   Elements parsed so far
     * @param int    $position  Position hint
     * @param array  &$best     Best result found (by reference)
     * @param int    &$best_score Best score found (by reference)
     */
    private function _fret_parse_search($s, $depth, $current, $position, &$best, &$best_score) {
        if ($depth === 6) {
            if (strlen($s) === 0) {
                $score = $this->_fret_parse_score($current, $position);
                if ($score < $best_score) {
                    $best_score = $score;
                    $best = $current;
                }
            }
            return;
        }
        
        if (strlen($s) === 0) {
            // Pad remaining slots with 'x'
            $padded = array_merge($current, array_fill(0, 6 - $depth, 'x'));
            $score = $this->_fret_parse_score($padded, $position);
            if ($score < $best_score) {
                $best_score = $score;
                $best = $padded;
            }
            return;
        }
        
        $c = $s[0];
        
        if ($c === 'x' || $c === 'X') {
            $this->_fret_parse_search(substr($s, 1), $depth + 1, array_merge($current, array('x')), $position, $best, $best_score);
        } elseif (ctype_digit($c)) {
            // Try single digit
            $this->_fret_parse_search(substr($s, 1), $depth + 1, array_merge($current, array(intval($c))), $position, $best, $best_score);
            
            // Try two digits if available
            if (strlen($s) >= 2 && ctype_digit($s[1])) {
                $two = intval(substr($s, 0, 2));
                $this->_fret_parse_search(substr($s, 2), $depth + 1, array_merge($current, array($two)), $position, $best, $best_score);
            }
        } else {
            // Skip unexpected characters
            $this->_fret_parse_search(substr($s, 1), $depth, $current, $position, $best, $best_score);
        }
    }
    
    /**
     * Score a fret parse: lower is better
     * 
     * @param array $parse    6-element array
     * @param int   $position Position hint
     * @return int Score
     */
    private function _fret_parse_score($parse, $position) {
        $sounding = array();
        foreach ($parse as $val) {
            if ($val !== 'x') {
                $sounding[] = $val;
            }
        }
        
        if (empty($sounding)) return 0;
        
        $score = 0;
        
        if ($position > 1) {
            // Position provided: prefer frets near position
            foreach ($sounding as $val) {
                $dist = abs($val - $position);
                $score += $dist * $dist;
            }
        } else {
            // No position: prefer compact voicings (small fret span)
            $nonzero = array_filter($sounding, function($f) { return $f > 0; });
            if (!empty($nonzero)) {
                $span = max($sounding) - min($nonzero);
                if ($span > 5) {
                    $score += ($span - 5) * 100;
                }
            }
        }
        
        // Penalize implausible fret numbers
        foreach ($sounding as $val) {
            if ($val > 24 || $val < 0) {
                $score += 10000;
            }
        }
        
        return $score;
    }
    
    /**
     * Convert a calculated diagram_data array to a per-string fret array
     * 
     * Returns an array of 6 elements (one per string), where each is
     * either 'x' (muted) or an integer fret number. This avoids the
     * string-concatenation issue where multi-digit frets (10, 11, 12)
     * would produce misaligned comparison strings.
     * 
     * @param array $diagram_data The diagram data (positions, barres, muted, open)
     * @return array Array of 6 elements: 'x' or int
     */
    private function diagram_to_fret_array($diagram_data) {
        // Initialize all 6 strings as muted
        $frets = array_fill(0, 6, 'x');
        
        // Mark open strings
        if (!empty($diagram_data['open'])) {
            foreach ($diagram_data['open'] as $string) {
                if ($string >= 1 && $string <= 6) {
                    $frets[$string - 1] = 0;
                }
            }
        }
        
        // Set fretted positions
        if (!empty($diagram_data['positions'])) {
            foreach ($diagram_data['positions'] as $pos) {
                $string = $pos['string'];
                $fret = $pos['fret'];
                if ($string >= 1 && $string <= 6) {
                    $frets[$string - 1] = intval($fret);
                }
            }
        }
        
        // Handle barres — each barre covers a range of strings at a fret
        if (!empty($diagram_data['barres'])) {
            foreach ($diagram_data['barres'] as $barre) {
                $from = min($barre['fromString'], $barre['toString']);
                $to = max($barre['fromString'], $barre['toString']);
                for ($s = $from; $s <= $to; $s++) {
                    // Only set barre fret if the string isn't already set by a position
                    if ($s >= 1 && $s <= 6 && $frets[$s - 1] === 'x') {
                        $frets[$s - 1] = intval($barre['fret']);
                    }
                }
            }
        }
        
        return $frets;
    }
    
    /**
     * Convert a fret array back to a display string (for logging)
     * 
     * @param array $fret_array Array of 'x' or int values
     * @return string Display string like "x-5-x-5-6-5"
     */
    private function fret_array_to_string($fret_array) {
        $parts = array();
        foreach ($fret_array as $val) {
            $parts[] = ($val === 'x') ? 'x' : (string)$val;
        }
        return implode('-', $parts);
    }
    
    /**
     * Check if a calculated diagram has any invalid (negative) frets
     * 
     * @param array $fret_array Array from diagram_to_fret_array
     * @return bool True if all frets are valid (>= 0 or muted)
     */
    private function has_valid_frets($fret_array) {
        foreach ($fret_array as $val) {
            if ($val !== 'x' && $val < 0) {
                return false;
            }
        }
        return true;
    }
    
    /**
     * Compare two fret arrays for exact match
     * 
     * @param array $a First fret array
     * @param array $b Second fret array
     * @return bool True if arrays match exactly
     */
    private function fret_arrays_match($a, $b) {
        if (count($a) !== count($b)) {
            return false;
        }
        for ($i = 0; $i < count($a); $i++) {
            if ($a[$i] !== $b[$i]) {
                return false;
            }
        }
        return true;
    }
    
    /**
     * Check if a leadsheet voicing is a subset of a library shape
     * 
     * Every sounding string in the leadsheet must match the library shape exactly.
     * Muted strings in the leadsheet are allowed to be sounding in the library 
     * (the arrangement omits strings from the full chord shape).
     * 
     * @param array $leadsheet_frets Fret array from parse_fret_string()
     * @param array $library_frets   Fret array from diagram_to_fret_array()
     * @return int|false Number of extra strings in the library shape, or false if not a subset
     */
    private function is_subset_match($leadsheet_frets, $library_frets) {
        // Must be same length (both should be 6)
        if (count($leadsheet_frets) !== count($library_frets)) {
            return false;
        }
        
        $extra_strings = 0;
        
        for ($i = 0; $i < count($leadsheet_frets); $i++) {
            $lead = $leadsheet_frets[$i];
            $lib = $library_frets[$i];
            
            if ($lead === 'x') {
                // Leadsheet mutes this string — library can have anything here
                if ($lib !== 'x') {
                    $extra_strings++;
                }
            } else {
                // Leadsheet has a sounding string — library must match exactly
                // Compare as integers to avoid string/int type mismatch
                if ($lib === 'x' || intval($lead) !== intval($lib)) {
                    return false;
                }
            }
        }
        
        // Only count as a subset match if there's at least one extra string
        // (exact matches are handled separately)
        if ($extra_strings === 0) {
            return false;  // This would be an exact match, handled above
        }
        
        return $extra_strings;
    }
    
    /**
     * Check if a library shape is a subset of the leadsheet voicing (superset match)
     * 
     * Every sounding string in the LIBRARY must match the leadsheet exactly.
     * Muted strings in the library are allowed to be sounding in the leadsheet
     * (the arrangement adds extra notes to the core library shape).
     * 
     * This catches the common case where:
     * - Library has a 3-note shell voicing (e.g., R-3-7)
     * - Leadsheet arrangement adds an extra note (e.g., doubled root on string 5)
     * 
     * @param array $leadsheet_frets Fret array from parse_fret_string()
     * @param array $library_frets   Fret array from diagram_to_fret_array()
     * @return int|false Number of extra strings in the leadsheet, or false if not a superset
     */
    private function is_superset_match($leadsheet_frets, $library_frets) {
        // Must be same length (both should be 6)
        if (count($leadsheet_frets) !== count($library_frets)) {
            return false;
        }
        
        $extra_strings = 0;
        $lib_sounding = 0;
        
        for ($i = 0; $i < count($library_frets); $i++) {
            $lead = $leadsheet_frets[$i];
            $lib = $library_frets[$i];
            
            if ($lib === 'x') {
                // Library mutes this string — leadsheet can have anything here
                if ($lead !== 'x') {
                    $extra_strings++;
                }
            } else {
                // Library has a sounding string — leadsheet must match exactly
                $lib_sounding++;
                if ($lead === 'x' || intval($lead) !== intval($lib)) {
                    return false;
                }
            }
        }
        
        // Must have at least one extra string (exact matches handled separately)
        // Also require the library shape to have at least 2 sounding strings
        // (matching just 1 string is too loose)
        if ($extra_strings === 0 || $lib_sounding < 2) {
            return false;
        }
        
        return $extra_strings;
    }
    
    /**
     * Note-to-semitone mapping for pitch calculations
     * (Duplicated from SBN_Chord_Shape_Calculator to avoid coupling)
     */
    private static $note_to_semitone = array(
        'C' => 0, 'C#' => 1, 'Db' => 1,
        'D' => 2, 'D#' => 3, 'Eb' => 3,
        'E' => 4,
        'F' => 5, 'F#' => 6, 'Gb' => 6,
        'G' => 7, 'G#' => 8, 'Ab' => 8,
        'A' => 9, 'A#' => 10, 'Bb' => 10,
        'B' => 11
    );
    
    /**
     * Standard tuning: string index (0-based) → open semitone
     * Index 0 = string 1 (low E), index 5 = string 6 (high e)
     */
    private static $standard_tuning = array(4, 9, 2, 7, 11, 4);
    
    /**
     * Chord tone intervals (semitones above root) by base quality.
     * Used to verify that a relocated bass note belongs to the chord.
     */
    private static $chord_tone_intervals = array(
        'maj'    => array(0, 4, 7),
        'min'    => array(0, 3, 7),
        'm'      => array(0, 3, 7),
        'maj7'   => array(0, 4, 7, 11),
        'maj6'   => array(0, 4, 7, 9),
        'm7'     => array(0, 3, 7, 10),
        'm6'     => array(0, 3, 7, 9),
        'dom7'   => array(0, 4, 7, 10),
        '7'      => array(0, 4, 7, 10),
        'm7b5'   => array(0, 3, 6, 10),
        'o7'     => array(0, 3, 6, 9),
        'dim7'   => array(0, 3, 6, 9),
        'dim'    => array(0, 3, 6),
        'mMaj7'  => array(0, 3, 7, 11),
        'aug7'   => array(0, 4, 8, 10),
        'aug'    => array(0, 4, 8),
        '+'      => array(0, 4, 8),
        'sus4'   => array(0, 5, 7),
        'sus2'   => array(0, 2, 7),
        '5'      => array(0, 7),
        '9'      => array(0, 2, 4, 7, 10),
        'maj9'   => array(0, 2, 4, 7, 11),
        'm9'     => array(0, 2, 3, 7, 10),
        'add9'   => array(0, 2, 4, 7),
        '13'     => array(0, 2, 4, 7, 9, 10),
        'maj13'  => array(0, 2, 4, 7, 9, 11),
        'm13'    => array(0, 2, 3, 7, 9, 10),
        '11'     => array(0, 2, 4, 5, 7, 10),
        'maj11'  => array(0, 2, 4, 5, 7, 11),
        'm11'    => array(0, 2, 3, 5, 7, 10),
    );
    
    /**
     * Check if a leadsheet voicing matches a library shape after relocating
     * the bass note to an open string on a different string.
     * 
     * Guitarists often play a partial chord shape with the bass note moved
     * to a nearby open string. For example:
     * 
     *   x0x555  (Am) — open A string provides the root, upper strings
     *   come from the Em barre shape transposed to fret 5 (577555).
     *   The open A (string 2 fret 0) replaces E fret 5 on string 1.
     * 
     * Algorithm:
     * 1. Find the lowest sounding string in the leadsheet voicing
     * 2. Compute its pitch class and verify it's a chord tone
     * 3. Mute that string in a copy of the leadsheet fret array
     * 4. Run subset matching on the remaining strings
     * 5. Require at least 3 remaining sounding strings
     * 
     * @param array  $leadsheet_frets Fret array from parse_fret_string()
     * @param array  $library_frets   Fret array from diagram_to_fret_array()
     * @param string $root_note       Root note of the chord (e.g., 'A')
     * @param string $base_quality    Base quality of the chord (e.g., 'min')
     * @return int|false Number of extra strings in the library shape (for scoring), or false
     */
    private function is_root_relocated_fragment_match($leadsheet_frets, $library_frets, $root_note, $base_quality) {
        // Must be 6-string arrays
        if (count($leadsheet_frets) !== 6 || count($library_frets) !== 6) {
            return false;
        }
        
        // Get root semitone
        $root_semi = self::$note_to_semitone[$root_note] ?? null;
        if ($root_semi === null) {
            return false;
        }
        
        // Get chord tone intervals for this quality
        $intervals = self::$chord_tone_intervals[$base_quality] ?? null;
        if ($intervals === null) {
            // Fallback: allow root + 5th (generic triad tones) for unknown qualities
            $intervals = array(0, 7);
        }
        
        // Build set of valid chord-tone pitch classes
        $valid_pitches = array();
        foreach ($intervals as $interval) {
            $valid_pitches[] = ($root_semi + $interval) % 12;
        }
        
        // Find the lowest sounding string in the leadsheet
        $bass_idx = null;
        for ($i = 0; $i < 6; $i++) {
            if ($leadsheet_frets[$i] !== 'x') {
                $bass_idx = $i;
                break;
            }
        }
        
        if ($bass_idx === null) {
            return false; // No sounding strings at all
        }
        
        // Check: the bass string in the leadsheet must NOT already match
        // the library at the same position (if it does, normal subset handles it)
        $lead_bass_fret = $leadsheet_frets[$bass_idx];
        $lib_bass_val = $library_frets[$bass_idx];
        
        if ($lib_bass_val !== 'x' && intval($lead_bass_fret) === intval($lib_bass_val)) {
            return false; // Bass strings already agree — normal matching covers this
        }
        
        // Compute pitch class of the leadsheet bass note
        $bass_pitch = (self::$standard_tuning[$bass_idx] + intval($lead_bass_fret)) % 12;
        
        // Verify it's a chord tone
        if (!in_array($bass_pitch, $valid_pitches, true)) {
            return false; // Bass note is not a chord tone — reject
        }
        
        // Strip the bass string and check remaining fragment as a subset
        $fragment = $leadsheet_frets;
        $fragment[$bass_idx] = 'x';
        
        // Count remaining sounding strings — require at least 3
        $remaining_sounding = 0;
        for ($i = 0; $i < 6; $i++) {
            if ($fragment[$i] !== 'x') {
                $remaining_sounding++;
            }
        }
        
        if ($remaining_sounding < 3) {
            return false; // Too few strings for a reliable fragment match
        }
        
        // Run subset match on the stripped fragment
        $subset_result = $this->is_subset_match($fragment, $library_frets);
        
        if ($subset_result !== false) {
            return $subset_result;
        }
        
        // Also check exact match of the fragment (all remaining strings match,
        // no extra strings in library beyond what fragment covers)
        // This handles cases where the fragment fills all library sounding strings
        if ($this->fret_arrays_match_ignoring_muted($fragment, $library_frets)) {
            return 0;
        }
        
        return false;
    }
    
    /**
     * Check if two fret arrays match on all sounding strings of $a,
     * ignoring strings that are muted in $a.
     * Unlike is_subset_match, this returns true even with 0 extra strings.
     * 
     * @param array $a Fret array (with some strings muted)
     * @param array $b Fret array to compare against
     * @return bool True if every sounding string in $a matches $b
     */
    private function fret_arrays_match_ignoring_muted($a, $b) {
        if (count($a) !== count($b)) return false;
        
        $matched_sounding = 0;
        for ($i = 0; $i < count($a); $i++) {
            if ($a[$i] === 'x') continue;
            if ($b[$i] === 'x' || intval($a[$i]) !== intval($b[$i])) {
                return false;
            }
            $matched_sounding++;
        }
        
        return $matched_sounding >= 3;
    }
    
    /**
     * Interval-from-root label map (semitones → label)
     */
    private static $interval_labels = array(
        0  => 'R',
        1  => 'b2',
        2  => '2',
        3  => 'b3',
        4  => '3',
        5  => '4',
        6  => 'b5',
        7  => '5',
        8  => 'b6',
        9  => '6',
        10 => 'b7',
        11 => '7',
    );
    
    /**
     * Classify extra notes between a leadsheet voicing and its matched library shape.
     * 
     * Compares the two fret arrays string-by-string. For each string where the
     * leadsheet has a sounding note but the library does not (or differs), we
     * determine what that extra note is relative to the chord:
     * 
     *   - "doubled:R"  — same pitch class already in the library shape
     *   - "bass:5"     — below the library shape's lowest string, chord tone
     *   - "added:b3"   — above or within, not a doubled pitch
     * 
     * For fragment matches, the relocated bass note is also classified.
     * 
     * @param array  $leadsheet_frets  Fret array from parse_fret_string()
     * @param array  $library_frets    Fret array from diagram_to_fret_array()
     * @param string $root_note        Root note (e.g., 'A')
     * @param string $base_quality     Base quality (e.g., 'min')
     * @param string $match_type       One of 'exact', 'subset', 'superset', 'fragment'
     * @return string Compact tag string, e.g. "doubled:R" or "bass:5+doubled:R", or ''
     */
    private function classify_extra_notes($leadsheet_frets, $library_frets, $root_note, $base_quality, $match_type = '') {
        if ($match_type === 'exact' || $match_type === 'subset') {
            return ''; // No extra notes in exact or subset matches
        }
        
        if (count($leadsheet_frets) !== 6 || count($library_frets) !== 6) {
            return '';
        }
        
        $root_semi = self::$note_to_semitone[$root_note] ?? null;
        if ($root_semi === null) {
            return '';
        }
        
        // Collect pitch classes present in the library shape
        $lib_pitches = array();
        $lib_lowest_string = null;
        for ($i = 0; $i < 6; $i++) {
            if ($library_frets[$i] !== 'x') {
                $lib_pitches[] = (self::$standard_tuning[$i] + intval($library_frets[$i])) % 12;
                if ($lib_lowest_string === null) {
                    $lib_lowest_string = $i;
                }
            }
        }
        
        // Find strings where leadsheet has a note but library doesn't match
        $tags = array();
        
        for ($i = 0; $i < 6; $i++) {
            if ($leadsheet_frets[$i] === 'x') {
                continue; // Leadsheet mutes this string — not an extra
            }
            
            $lib_val = $library_frets[$i];
            
            // If library also sounds this string at the same fret, it's a match — skip
            if ($lib_val !== 'x' && intval($leadsheet_frets[$i]) === intval($lib_val)) {
                continue;
            }
            
            // This is an extra/different note in the leadsheet
            $extra_pitch = (self::$standard_tuning[$i] + intval($leadsheet_frets[$i])) % 12;
            $interval_from_root = ($extra_pitch - $root_semi + 12) % 12;
            $interval_label = self::$interval_labels[$interval_from_root] ?? '?';
            
            // Classify: doubled, bass, or added
            if (in_array($extra_pitch, $lib_pitches, true)) {
                // Same pitch class exists in the library shape → doubled
                $tags[] = 'doubled:' . $interval_label;
            } elseif ($lib_lowest_string !== null && $i < $lib_lowest_string) {
                // Below the library shape's lowest sounding string → bass
                $tags[] = 'bass:' . $interval_label;
            } else {
                // Added note (above or within the shape)
                $tags[] = 'added:' . $interval_label;
            }
        }
        
        return implode('+', $tags);
    }
    
    /**
     * Store a matched voicing in the usage table
     */
    private function store_match($leadsheet_id, $voicing, $match) {
        global $wpdb;
        $table = self::get_usage_table();
        
        $quality_parts = $this->split_quality_extensions($voicing['quality']);
        
        // Classify any extra/added notes (superset, fragment matches)
        $added_notes = '';
        if (!empty($match['calculated_frets']) && !empty($match['match_type'])) {
            $target_fret_array = $this->normalize_open_equivalents(
                $this->parse_fret_string($voicing['fret_string'], $voicing['position'] ?? 1)
            );
            $added_notes = $this->classify_extra_notes(
                $target_fret_array,
                $match['calculated_frets'],
                $voicing['root'],
                $quality_parts['base'],
                $match['match_type']
            );
            if (!empty($added_notes)) {
                error_log("SBN Crossref:   Added notes tag: {$added_notes}");
            }
        }
        
        $wpdb->replace($table, array(
            'leadsheet_id'     => $leadsheet_id,
            'chord_diagram_id' => $match['diagram_id'],
            'chord_name'       => $voicing['chord_name'],
            'fret_string'      => $voicing['fret_string'],
            'position'         => $voicing['position'],
            'root_note'        => $voicing['root'],
            'quality'          => $voicing['quality'],
            'base_quality'     => $quality_parts['base'],
            'extensions'       => $quality_parts['extensions'],
            'voicing_category' => $match['voicing_category'],
            'root_string'      => $match['root_string'],
            'inversion'        => $match['inversion'],
            'bass_note'        => $voicing['bass_note'] ?? '',
            'added_notes'      => $added_notes,
        ));
    }
    
    /**
     * Store an unmatched voicing as a draft for review
     */
    private function store_draft($leadsheet_id, $leadsheet_title, $voicing) {
        global $wpdb;
        $table = self::get_drafts_table();
        
        // Use replace to handle re-processing (unique key on leadsheet + chord + frets)
        $wpdb->replace($table, array(
            'leadsheet_id' => $leadsheet_id,
            'leadsheet_title' => $leadsheet_title,
            'chord_name' => $voicing['chord_name'],
            'fret_string' => $voicing['fret_string'],
            'position' => $voicing['position'],
            'fingers' => $voicing['fingers'],
            'root_note' => $voicing['root'],
            'quality' => $voicing['quality'],
            'bass_note' => $voicing['bass_note'] ?? '',
            'status' => 'pending',
            'updated_at' => current_time('mysql'),
        ));
    }
    
    /**
     * Clear all references for a leadsheet (before re-processing)
     */
    private function clear_leadsheet_references($leadsheet_id) {
        global $wpdb;
        
        $wpdb->delete(self::get_usage_table(), array('leadsheet_id' => $leadsheet_id));
        
        // Clear ALL drafts (pending + dismissed) for this leadsheet.
        // On reprocess, every voicing is re-evaluated fresh. Voicings still
        // present and still unmatched will be re-created as pending. Voicings
        // that were changed or removed won't reappear. This prevents dismissed
        // drafts from lingering after the underlying chord has been edited.
        $wpdb->delete(self::get_drafts_table(), array(
            'leadsheet_id' => $leadsheet_id,
        ));
    }
    
    /**
     * Recalculate popularity for all chord diagrams
     * 
     * Counts how many leadsheets reference each diagram
     * and updates the popularity column.
     */
    public function recalculate_popularity() {
        global $wpdb;
        
        $usage_table = self::get_usage_table();
        $diagrams_table = $wpdb->prefix . 'sbn_chord_diagrams';
        
        // Reset all to 0 first
        $wpdb->query("UPDATE $diagrams_table SET popularity = 0");
        
        // Set counts from usage table
        $wpdb->query("
            UPDATE $diagrams_table d
            INNER JOIN (
                SELECT chord_diagram_id, COUNT(DISTINCT leadsheet_id) as usage_count
                FROM $usage_table
                GROUP BY chord_diagram_id
            ) u ON d.id = u.chord_diagram_id
            SET d.popularity = u.usage_count
        ");
    }
    
    // =========================================================================
    // QUERY METHODS (for info modal and chord library)
    // =========================================================================
    
    /**
     * Get all leadsheets that use a specific chord diagram
     * 
     * @param int $diagram_id Chord diagram ID
     * @return array Array of objects with leadsheet_id, title, chord_name
     */
    public function get_songs_for_diagram($diagram_id) {
        global $wpdb;
        
        $usage_table = self::get_usage_table();
        $leadsheet_table = $wpdb->prefix . 'sbn_leadsheets';
        
        return $wpdb->get_results($wpdb->prepare("
            SELECT u.leadsheet_id, u.chord_name, l.title as leadsheet_title
            FROM $usage_table u
            INNER JOIN $leadsheet_table l ON u.leadsheet_id = l.id
            WHERE u.chord_diagram_id = %d
            ORDER BY l.title ASC
        ", $diagram_id));
    }
    
    /**
     * Get all matched voicings for a specific leadsheet
     * 
     * @param int $leadsheet_id Leadsheet ID
     * @return array Array of usage rows with diagram metadata
     */
    public function get_voicings_for_leadsheet($leadsheet_id) {
        global $wpdb;
        
        $usage_table = self::get_usage_table();
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $usage_table WHERE leadsheet_id = %d ORDER BY chord_name ASC",
            $leadsheet_id
        ));
    }
    
    /**
     * Get all pending drafts, optionally filtered by leadsheet
     * 
     * @param int|null $leadsheet_id Optional: filter by leadsheet
     * @return array Array of draft rows
     */
    public function get_pending_drafts($leadsheet_id = null) {
        global $wpdb;
        $table = self::get_drafts_table();
        
        if ($leadsheet_id) {
            return $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $table WHERE status = 'pending' AND leadsheet_id = %d ORDER BY chord_name ASC",
                $leadsheet_id
            ));
        }
        
        return $wpdb->get_results(
            "SELECT * FROM $table WHERE status = 'pending' ORDER BY leadsheet_title ASC, chord_name ASC"
        );
    }
    
    /**
     * Get draft counts grouped by leadsheet
     * 
     * @return array Array of objects with leadsheet_id, leadsheet_title, draft_count
     */
    public function get_draft_summary() {
        global $wpdb;
        $table = self::get_drafts_table();
        
        return $wpdb->get_results("
            SELECT leadsheet_id, leadsheet_title, COUNT(*) as draft_count
            FROM $table
            WHERE status = 'pending'
            GROUP BY leadsheet_id, leadsheet_title
            ORDER BY leadsheet_title ASC
        ");
    }
    
    /**
     * Get a single draft by ID
     */
    public function get_draft($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::get_drafts_table() . " WHERE id = %d",
            $id
        ));
    }
    
    /**
     * Get overall stats for the admin dashboard
     * 
     * @return array Stats array
     */
    public function get_stats() {
        global $wpdb;
        
        $usage_table = self::get_usage_table();
        $drafts_table = self::get_drafts_table();
        $diagrams_table = $wpdb->prefix . 'sbn_chord_diagrams';
        $leadsheet_table = $wpdb->prefix . 'sbn_leadsheets';
        
        return array(
            'total_matches' => (int)$wpdb->get_var("SELECT COUNT(*) FROM $usage_table"),
            'total_pending_drafts' => (int)$wpdb->get_var("SELECT COUNT(*) FROM $drafts_table WHERE status = 'pending'"),
            'total_dismissed_drafts' => (int)$wpdb->get_var("SELECT COUNT(*) FROM $drafts_table WHERE status = 'dismissed'"),
            'diagrams_with_matches' => (int)$wpdb->get_var("SELECT COUNT(DISTINCT chord_diagram_id) FROM $usage_table"),
            'leadsheets_processed' => (int)$wpdb->get_var("SELECT COUNT(DISTINCT leadsheet_id) FROM $usage_table"),
            'total_leadsheets' => (int)$wpdb->get_var("SELECT COUNT(*) FROM $leadsheet_table"),
            'total_diagrams' => (int)$wpdb->get_var("SELECT COUNT(*) FROM $diagrams_table"),
            'most_popular' => $wpdb->get_results("
                SELECT d.id, d.name, d.quality, d.voicing_category, d.root_string, d.popularity
                FROM $diagrams_table d
                WHERE d.popularity > 0
                ORDER BY d.popularity DESC
                LIMIT 10
            "),
        );
    }
    
    // =========================================================================
    // BATCH PROCESSING
    // =========================================================================
    
    /**
     * Process ALL existing leadsheets
     * 
     * Run this once to backfill cross-references for
     * leadsheets that were saved before this module existed.
     * 
     * @return array Results summary
     */
    public function process_all_leadsheets() {
        global $wpdb;
        
        $leadsheet_table = $wpdb->prefix . 'sbn_leadsheets';
        $leadsheets = $wpdb->get_results("SELECT id, title, shortcode_content FROM $leadsheet_table");
        
        $results = array(
            'processed' => 0,
            'matched' => 0,
            'unmatched' => 0,
            'skipped' => 0,
        );
        
        foreach ($leadsheets as $sheet) {
            if (empty($sheet->shortcode_content)) {
                $results['skipped']++;
                continue;
            }
            
            // Extract voicings to check if there are any
            $voicings = $this->extract_voicings($sheet->shortcode_content);
            if (empty($voicings)) {
                $results['skipped']++;
                continue;
            }
            
            // Process this leadsheet
            $this->process_leadsheet($sheet->id, $sheet->shortcode_content, $sheet->title);
            $results['processed']++;
        }
        
        // Count totals from the tables
        $results['matched'] = (int)$wpdb->get_var("SELECT COUNT(*) FROM " . self::get_usage_table());
        $results['unmatched'] = (int)$wpdb->get_var("SELECT COUNT(*) FROM " . self::get_drafts_table() . " WHERE status = 'pending'");
        
        return $results;
    }
    
    // =========================================================================
    // ADMIN UI
    // =========================================================================
    
    /**
     * Add admin menu page
     */
    public function add_admin_menu() {
        // Voicing Crossref is now embedded in the Chord Diagrams page (Voicings tab).
        // Register a hidden page so old bookmarks redirect gracefully.
        add_submenu_page(
            null,
            'Voicing Crossref',
            'Voicing Crossref',
            'manage_options',
            'sbn-voicing-crossref',
            array($this, 'render_admin_page')
        );
    }
    
    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets($hook) {
        if (strpos($hook, 'sbn-voicing-crossref') === false) {
            return;
        }
        
        wp_enqueue_style(
            'sbn-voicing-crossref-admin',
            SBN_PLUGIN_URL . 'assets/css/voicing-crossref-admin.css',
            array(),
            self::VERSION
        );
        
        // Also enqueue chord diagram rendering for draft previews
        wp_enqueue_style(
            'sbn-chord-diagrams-admin',
            SBN_PLUGIN_URL . 'assets/css/chord-diagrams-admin.css',
            array(),
            SBN_VERSION
        );
        
        wp_enqueue_script(
            'sbn-chord-diagrams-admin',
            SBN_PLUGIN_URL . 'assets/js/chord-diagrams-admin.js',
            array('jquery'),
            SBN_VERSION,
            true
        );
        
        wp_enqueue_script(
            'sbn-voicing-crossref-admin',
            SBN_PLUGIN_URL . 'assets/js/voicing-crossref-admin.js',
            array('jquery', 'sbn-chord-diagrams-admin'),
            self::VERSION,
            true
        );
        
        wp_localize_script('sbn-voicing-crossref-admin', 'sbnCrossref', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('sbn_voicing_crossref_nonce'),
            'diagramEditUrl' => admin_url('admin.php?page=sbn-chord-diagrams'),
        ));
    }
    
    /**
     * Render the admin page
     */
    public function render_admin_page() {
        $tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'overview';
        $stats = $this->get_stats();
        
        ?>
        <div class="wrap sbn-admin sbn-crossref-admin">
            <nav class="nav-tab-wrapper sbn-crossref-tabs">
                <a href="?page=sbn-voicing-crossref&tab=overview" 
                   class="nav-tab <?php echo $tab === 'overview' ? 'nav-tab-active' : ''; ?>">
                    Overview
                </a>
                <a href="?page=sbn-voicing-crossref&tab=drafts" 
                   class="nav-tab <?php echo $tab === 'drafts' ? 'nav-tab-active' : ''; ?>">
                    Unmatched Voicings
                    <?php if ($stats['total_pending_drafts'] > 0): ?>
                        <span class="sbn-badge sbn-badge-red"><?php echo $stats['total_pending_drafts']; ?></span>
                    <?php endif; ?>
                </a>
                <a href="?page=sbn-voicing-crossref&tab=matches" 
                   class="nav-tab <?php echo $tab === 'matches' ? 'nav-tab-active' : ''; ?>">
                    Matched Voicings
                </a>
            </nav>
            
            <div class="sbn-crossref-content">
                <?php
                switch ($tab) {
                    case 'drafts':
                        $this->render_drafts_tab();
                        break;
                    case 'matches':
                        $this->render_matches_tab();
                        break;
                    default:
                        $this->render_overview_tab($stats);
                        break;
                }
                ?>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render the overview tab
     */
    private function render_overview_tab($stats) {
        ?>
        <div class="sbn-crossref-overview">
            <div class="sbn-stats-grid">
                <div class="sbn-stat-card">
                    <div class="sbn-stat-number"><?php echo $stats['total_leadsheets']; ?></div>
                    <div class="sbn-stat-label">Total Leadsheets</div>
                </div>
                <div class="sbn-stat-card">
                    <div class="sbn-stat-number"><?php echo $stats['leadsheets_processed']; ?></div>
                    <div class="sbn-stat-label">With Matched Voicings</div>
                </div>
                <div class="sbn-stat-card">
                    <div class="sbn-stat-number"><?php echo $stats['total_matches']; ?></div>
                    <div class="sbn-stat-label">Total Matches</div>
                </div>
                <div class="sbn-stat-card sbn-stat-highlight">
                    <div class="sbn-stat-number"><?php echo $stats['total_pending_drafts']; ?></div>
                    <div class="sbn-stat-label">Unmatched (Pending Review)</div>
                </div>
            </div>
            
            <div class="sbn-crossref-actions">
                <h3>Batch Processing</h3>
                <p>Reprocess all existing leadsheets to update cross-references. Run this after adding new chord diagrams to catch previously unmatched voicings.</p>
                <button class="button button-primary" id="sbnReprocessAll">
                    Reprocess All Leadsheets
                </button>
                <span id="sbnReprocessStatus" class="sbn-status-message"></span>
            </div>
            
            <?php if (!empty($stats['most_popular'])): ?>
            <div class="sbn-crossref-popular">
                <h3>Most Popular Voicings</h3>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Voicing</th>
                            <th>Quality</th>
                            <th>Type</th>
                            <th>Root String</th>
                            <th>Used In</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($stats['most_popular'] as $diagram): ?>
                            <tr>
                                <td><strong><?php echo esc_html($diagram->name); ?></strong></td>
                                <td><?php echo esc_html($diagram->quality); ?></td>
                                <td><?php echo esc_html($diagram->voicing_category); ?></td>
                                <td><?php echo esc_html($diagram->root_string); ?></td>
                                <td><?php echo esc_html($diagram->popularity); ?> song<?php echo $diagram->popularity !== '1' ? 's' : ''; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * Render the drafts tab (unmatched voicings)
     */
    public function render_drafts_tab() {
        $drafts = $this->get_pending_drafts();
        
        if (empty($drafts)) {
            ?>
            <div class="sbn-empty-state">
                <div class="sbn-empty-icon">✅</div>
                <h2>All voicings matched!</h2>
                <p>Every voicing in your leadsheets has been matched to a chord diagram in your library.</p>
            </div>
            <?php
            return;
        }
        
        // Group by leadsheet
        $grouped = array();
        foreach ($drafts as $draft) {
            $key = $draft->leadsheet_id;
            if (!isset($grouped[$key])) {
                $grouped[$key] = array(
                    'title' => $draft->leadsheet_title,
                    'drafts' => array(),
                );
            }
            $grouped[$key]['drafts'][] = $draft;
        }
        
        ?>
        <div class="sbn-drafts-intro">
            <p>These voicings from your leadsheets don't match any chord diagram in your library. 
               You can <strong>add them</strong> to the chord diagram library or <strong>dismiss</strong> them.</p>
            <button class="button button-secondary sbn-clear-all-drafts"
                    data-nonce="<?php echo esc_attr(wp_create_nonce('sbn_clear_all_drafts')); ?>"
                    title="Delete all pending unmatched voicings — useful after test imports">
                🗑 Clear All
            </button>
        </div>
        
        <?php foreach ($grouped as $leadsheet_id => $group): ?>
            <div class="sbn-draft-group">
                <h3 class="sbn-draft-group-title">
                    <?php echo esc_html($group['title']); ?>
                    <span class="sbn-draft-count"><?php echo count($group['drafts']); ?> unmatched</span>
                </h3>
                
                <div class="sbn-draft-cards">
                    <?php foreach ($group['drafts'] as $draft): ?>
                        <div class="sbn-draft-card" data-draft-id="<?php echo esc_attr($draft->id); ?>">
                            <div class="sbn-draft-header">
                                <span class="sbn-draft-chord-name"><?php echo esc_html($draft->chord_name); ?></span>
                                <span class="sbn-draft-frets"><?php echo esc_html($draft->fret_string); ?><?php 
                                    if ($draft->position > 1) echo ' @' . esc_html($draft->position);
                                ?></span>
                            </div>
                            
                            <div class="sbn-draft-diagram" 
                                 data-frets="<?php echo esc_attr($draft->fret_string); ?>"
                                 data-position="<?php echo esc_attr($draft->position); ?>"
                                 data-fingers="<?php echo esc_attr($draft->fingers); ?>">
                            </div>
                            
                            <div class="sbn-draft-meta">
                                <?php if ($draft->root_note && $draft->quality): ?>
                                    <span class="sbn-draft-parsed">
                                        Root: <?php echo esc_html($draft->root_note); ?> | 
                                        Quality: <?php echo esc_html($draft->quality); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            
                            <div class="sbn-draft-actions">
                                <button class="button button-primary button-small sbn-promote-draft" 
                                        data-draft-id="<?php echo esc_attr($draft->id); ?>"
                                        title="Add to chord diagram library">
                                    Add to Library
                                </button>
                                <button class="button button-small sbn-dismiss-draft" 
                                        data-draft-id="<?php echo esc_attr($draft->id); ?>"
                                        title="Dismiss — this voicing doesn't need to be in the library">
                                    Dismiss
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
        <?php
    }
    
    /**
     * Render the matches tab
     */
    public function render_matches_tab() {
        global $wpdb;
        
        $usage_table = self::get_usage_table();
        $leadsheet_table = $wpdb->prefix . 'sbn_leadsheets';
        $diagrams_table = $wpdb->prefix . 'sbn_chord_diagrams';
        
        $matches = $wpdb->get_results("
            SELECT u.*, l.title as leadsheet_title, d.name as diagram_name, d.voicing_category, d.root_string, d.inversion
            FROM $usage_table u
            INNER JOIN $leadsheet_table l ON u.leadsheet_id = l.id
            INNER JOIN $diagrams_table d ON u.chord_diagram_id = d.id
            ORDER BY l.title ASC, u.chord_name ASC
        ");
        
        if (empty($matches)) {
            ?>
            <div class="sbn-empty-state">
                <div class="sbn-empty-icon">🔍</div>
                <h2>No matches yet</h2>
                <p>Process your leadsheets to create cross-references with the chord diagram library.</p>
            </div>
            <?php
            return;
        }
        
        // Group by leadsheet
        $grouped = array();
        foreach ($matches as $match) {
            $key = $match->leadsheet_id;
            if (!isset($grouped[$key])) {
                $grouped[$key] = array(
                    'title' => $match->leadsheet_title,
                    'matches' => array(),
                );
            }
            $grouped[$key]['matches'][] = $match;
        }
        
        ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>Song</th>
                    <th>Chord</th>
                    <th>Frets</th>
                    <th>Matched Archetype</th>
                    <th>Voicing Type</th>
                    <th>Root String</th>
                    <th>Inversion</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($grouped as $group): ?>
                    <?php $first = true; ?>
                    <?php foreach ($group['matches'] as $match): ?>
                        <tr>
                            <td>
                                <?php if ($first): ?>
                                    <strong><?php echo esc_html($group['title']); ?></strong>
                                    <?php $first = false; ?>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html($match->chord_name); ?></td>
                            <td><code><?php echo esc_html($match->fret_string); ?></code></td>
                            <td><?php echo esc_html($match->diagram_name); ?></td>
                            <td><?php echo esc_html($match->voicing_category); ?></td>
                            <td><?php echo esc_html($match->root_string); ?></td>
                            <td><?php echo esc_html($match->inversion); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }
    
    // =========================================================================
    // REVERSE VOICING LOOKUP
    // =========================================================================

    /**
     * Standard guitar tuning: frets-string index → open pitch class
     * Index 0 = Low E, 1 = A, 2 = D, 3 = G, 4 = B, 5 = High e
     */
    private static $open_strings = array(4, 9, 2, 7, 11, 4);

    /**
     * Quality → interval set map (intervals relative to root, semitones)
     * Multiple entries may match; more specific (longer) ones are preferred.
     */
    private static $quality_intervals = array(
        'maj7'  => array(0, 4, 7, 11),
        'maj6'  => array(0, 4, 7, 9),
        'm7'    => array(0, 3, 7, 10),
        'm6'    => array(0, 3, 7, 9),
        '7'     => array(0, 4, 7, 10),
        'dom7'  => array(0, 4, 7, 10),
        'm7b5'  => array(0, 3, 6, 10),
        'o7'    => array(0, 3, 6, 9),
        'dim7'  => array(0, 3, 6, 9),
        'mMaj7' => array(0, 3, 7, 11),
        // NOTE: aug7 is intentionally omitted. In jazz/guitar context, G+B+Eb+F
        // is always notated G7(b13), never "Gaug7". The augmented 5th (interval 8)
        // is handled as a b13 extension in the second pass instead.
        'add9'  => array(0, 2, 4, 7),  // major triad + 9 (no 7th)
        'aug'   => array(0, 4, 8),     // pure augmented triad (no 7th)
        'dim'   => array(0, 3, 6),     // pure diminished triad (no 7th)
        'sus4'  => array(0, 5, 7),     // root + 4th + 5th
        'sus2'  => array(0, 2, 7),     // root + 2nd + 5th
        '5'     => array(0, 7),        // power chord
        'maj'   => array(0, 4, 7),
        'min'   => array(0, 3, 7),
        'm'     => array(0, 3, 7),
    );

    /**
     * Extension interval map: semitones-above-root => label.
     * Used in the second pass of identify_from_frets() to name
     * pitch classes that remain after the base quality is identified.
     *
     * Interval 3 (#9) is enharmonic with the minor 3rd (b3). This is
     * correct: in a dominant 7th context, the b3 leftover IS the #9
     * (e.g. A7#9 = "Hendrix chord"). For minor qualities, interval 3
     * is a base chord tone and won't appear as a leftover, so no
     * collision occurs. The previous mapping of 4 => '#9' was incorrect
     * (#9 = augmented 9th = 15 semitones = 3 mod 12, not 4).
     *
     * Interval 8 (augmented 5th) is listed as 'b13' here rather than
     * being a chord tone in aug7, so dominant chords with a raised 5th
     * are identified as e.g. G7(b13) rather than Gaug7.
     */
    private static $extension_intervals = array(
        1  => 'b9',
        2  => '9',
        3  => '#9',   // augmented 9th = 15 semitones = 3 mod 12 (enharmonic with b3)
        5  => '11',
        6  => '#11',
        8  => 'b13',
        9  => '13',
    );

    private static $note_names_sharp = array(
        0=>'C', 1=>'C#', 2=>'D', 3=>'D#', 4=>'E',
        5=>'F', 6=>'F#', 7=>'G', 8=>'G#', 9=>'A', 10=>'A#', 11=>'B'
    );
    private static $note_names_flat = array(
        0=>'C', 1=>'Db', 2=>'D', 3=>'Eb', 4=>'E',
        5=>'F', 6=>'Gb', 7=>'G', 8=>'Ab', 9=>'A', 10=>'Bb', 11=>'B'
    );
    private static $flat_roots = array('F', 'Bb', 'Eb', 'Ab', 'Db', 'Gb');

    /**
     * Rootless voicing templates: interval sets from an ABSENT root.
     *
     * Used in Pass 3 of identify_from_frets() to detect common jazz
     * voicings where the root is not sounded — typically 3-note shapes
     * on the middle strings (D, G, B) in chord melody and comping.
     *
     * Each entry: quality_label => array of interval sets (from absent root).
     * Multiple voicing types per quality cover different inversions.
     *
     * Priority order matters: more specific/common jazz qualities first.
     * When multiple templates match, the first match wins.
     */
    private static $rootless_templates = array(
        // 3-note rootless voicings (root absent)
        'm7'      => array(
            array(3, 7, 10),    // b3 + 5 + b7  (most common drop2/drop3 fragment)
            array(3, 10, 5),    // b3 + b7 + 11  (rare but exists in upper structures)
        ),
        '7(#9)'   => array(
            array(3, 4, 10),    // #9 + 3 + b7   (Barney Kessel chromatic II-V pattern)
        ),
        '7'       => array(
            array(4, 7, 10),    // 3 + 5 + b7
            array(4, 10, 2),    // 3 + b7 + 9    (rootless 9th chord fragment)
        ),
        'maj7'    => array(
            array(4, 7, 11),    // 3 + 5 + 7
        ),
        'm7b5'    => array(
            array(3, 6, 10),    // b3 + b5 + b7
        ),
        '7(b9)'   => array(
            array(1, 4, 10),    // b9 + 3 + b7
        ),
        '7(#11)'  => array(
            array(4, 6, 10),    // 3 + #11 + b7
        ),
        'dim7'    => array(
            array(3, 6, 9),     // b3 + b5 + bb7
        ),
        'm6'      => array(
            array(3, 7, 9),     // b3 + 5 + 6
        ),
        '6'       => array(
            array(4, 7, 9),     // 3 + 5 + 6   (also = rootless m7 from relative minor)
        ),
        'mMaj7'   => array(
            array(3, 7, 11),    // b3 + 5 + 7
        ),
    );

    /**
     * AJAX handler: given an array of fret strings, identify each one.
     *
     * POST params:
     *   nonce    — sbn_identify_voicings
     *   voicings — JSON array of { frets: "3x443x", position: 3 }
     *
     * Returns: array of { frets, name, root, quality, diagram_id, confidence }
     */
    public function ajax_identify_voicings() {
        check_ajax_referer('sbn_identify_voicings', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }

        $raw = isset($_POST['voicings']) ? wp_unslash($_POST['voicings']) : '[]';
        $input = json_decode($raw, true);
        if (!is_array($input)) {
            wp_send_json_error('Invalid voicings JSON');
        }

        $results = array();
        foreach ($input as $item) {
            $frets    = isset($item['frets'])    ? sanitize_text_field($item['frets'])    : '';
            $position = isset($item['position']) ? intval($item['position'])              : 1;
            if (strlen($frets) !== 6) continue;

            $identified = $this->identify_from_frets($frets, $position);
            $results[] = array_merge(array('frets' => $frets, 'position' => $position), $identified);
        }

        wp_send_json_success($results);
    }

    /**
     * Identify the chord name from a fret string.
     *
     * Two-pass approach:
     *
     *   PASS 1 — Base quality identification
     *     Score each (root, quality) pair. Bass note (lowest sounding string)
     *     gets a strong priority bonus. Scoring treats ANY remaining pitch
     *     classes (potential extensions) as neutral — they do NOT reduce the
     *     score for a correct base quality match. This prevents extensions
     *     like b13 from causing the algorithm to choose a wrong root or quality.
     *
     *   PASS 2 — Extension detection
     *     After the base root + quality is locked in, any pitch classes that
     *     are NOT chord tones of that quality are checked against the extension
     *     interval table (b9, 9, #9, 11, #11, b13, 13) and appended.
     *
     * Inversion detection:
     *     If the bass note is not the identified root, a slash suffix is added
     *     (e.g. "Dm7/F"). For dominant chords the bass is often the root, but
     *     for shell voicings without a bass string it is omitted.
     *
     * @param string $frets    6-char fret string, index 0=low E, 'x'=muted
     * @param int    $position Starting fret position hint
     * @return array { name, root, quality, extensions, bass_note, diagram_id, confidence }
     */
    public function identify_from_frets($frets, $position = 1) {

        // ── Step 1: Compute pitch classes ───────────────────────────────────
        $pitch_classes = array(); // ordered low→high
        $bass_pc       = null;
        for ($i = 0; $i < 6; $i++) {
            $ch = strtolower($frets[$i]);
            if ($ch === 'x') continue;
            $fret = ctype_xdigit($ch) ? hexdec($ch) : intval($ch);
            $pc   = (self::$open_strings[$i] + $fret) % 12;
            $pitch_classes[] = $pc;
            if ($bass_pc === null) $bass_pc = $pc; // lowest sounding string
        }

        if (empty($pitch_classes)) {
            return array(
                'name' => null, 'root' => null, 'quality' => null,
                'extensions' => '', 'bass_note' => null,
                'diagram_id' => null, 'confidence' => 'none',
            );
        }

        $pc_set = array_values(array_unique($pitch_classes));

        // ── Step 2: Score (root, quality) pairs — PASS 1 ────────────────────
        //
        // Scoring rules:
        //   • EXACT MATCH BONUS: when ALL chord tones of a quality are present,
        //     the raw score is multiplied by EXACT_BONUS (3). This ensures that
        //     a complete identification (e.g. Fmaj7 with all 4 tones) always
        //     beats a partial match even when the partial has bass boost
        //     (e.g. Cmaj6 missing the 5th but with C in the bass).
        //     Without this, inversions like Fmaj7/C get misidentified as the
        //     bass-note root with a partial quality.
        //
        //   • BASS MULTIPLIER: when the candidate root == bass_pc, multiply
        //     the raw score by BASS_BOOST (4). Strongly prefers reading the
        //     lowest sounding string as the chord root.
        //
        //   • Perfect base match (all quality tones present):
        //       raw = tone_count * 100 − 50 * unexplained_extras
        //       if tone_count >= 4: raw *= EXACT_BONUS
        //     The exact bonus only applies to 7th chords and above (4+ tones).
        //     Triads are excluded because a 3-tone exact match with unexplained
        //     leftovers (e.g. Gaug matching 3 of 5 notes) should not outweigh
        //     a partial 7th chord (e.g. G7 matching 3/4 + extension b13).
        //     "Unexplained" means a leftover pitch class that doesn't map to
        //     any known extension interval. Valid extensions don't reduce score.
        //
        //   • Partial base match (≥2 matched, at most 1 tone missing):
        //       raw = matched * 100 − 50 − 50 * unexplained_extras
        //     The −50 "missing-tone" penalty keeps partials slightly below
        //     equivalent-size exact matches. Combined with the exact bonus,
        //     a partial 3/4 with bass boost (250×4=1000) loses to an exact
        //     4/4 without bass (400×3=1200), correctly preferring inversions.
        //     But a partial 3/4 WITH bass boost (1000) still beats a 3/3
        //     triad match without bass (300×3=900), which lets bass-position
        //     7th chords with omitted 5ths beat triad-only identifications.
        //
        //   • Longer quality lists win ties (prefer m7 over m, etc.)

        $bass_boost   = 4;
        $exact_bonus  = 3;

        $best_score   = -1;
        $best_root_pc = null;
        $best_quality = null;

        // Sort qualities longest-first (more specific wins on equal raw score)
        $qualities = self::$quality_intervals;
        uasort($qualities, function($a, $b) { return count($b) - count($a); });

        // Candidate roots: bass first, then other voicing pcs, then chromatic
        $candidate_roots = array_values(array_unique(array_merge(
            ($bass_pc !== null ? array($bass_pc) : array()),
            $pc_set,
            range(0, 11)
        )));

        foreach ($candidate_roots as $root_pc) {
            $is_bass = ($root_pc === $bass_pc);

            foreach ($qualities as $quality => $intervals) {
                $expected_pcs = array_map(
                    function($iv) use ($root_pc) { return ($root_pc + $iv) % 12; },
                    $intervals
                );

                $matched = count(array_intersect($expected_pcs, $pc_set));
                $total   = count($expected_pcs);

                // Count unexplained leftover pitch classes (not a chord tone or extension)
                $leftover = array_diff($pc_set, $expected_pcs);
                $unexplained = 0;
                foreach ($leftover as $lpc) {
                    $iv_left = ($lpc - $root_pc + 12) % 12;
                    if (!isset(self::$extension_intervals[$iv_left])) {
                        $unexplained++;
                    }
                }

                if ($matched === $total) {
                    $raw = $total * 100 - $unexplained * 50;
                    // Only apply exact bonus to 4+ tone qualities (7th chords).
                    // Triads (3 tones) are small enough that an exact triad match
                    // with unexplained leftover notes shouldn't outweigh a partial
                    // 7th chord match. E.g. Gaug(3 tones) shouldn't beat G7(b13).
                    if ($total >= 4) {
                        $raw *= $exact_bonus;
                    }
                } elseif ($matched >= 2 && $matched >= $total - 1) {
                    $raw = $matched * 100 - 50 - $unexplained * 50;
                } else {
                    continue;
                }

                $score = $is_bass ? $raw * $bass_boost : $raw;

                if ($score > $best_score) {
                    $best_score   = $score;
                    $best_root_pc = $root_pc;
                    $best_quality = $quality;
                }
            }
        }

        if ($best_root_pc === null) {
            // ── Pass 3a: Rootless detection — no main match ─────────────────
            // The main algorithm found nothing. Try rootless templates for
            // 3-note voicings where the root is not sounded.
            $rootless = $this->identify_rootless($pc_set, $bass_pc);
            if ($rootless) {
                $rl_root = $rootless['root_name'];
                $rl_qual = $rootless['quality'];
                $rl_name = $rootless['name'];
                $diagram_id = $this->find_diagram_by_frets(
                    $frets, $position, $rl_root, $rl_qual, '', ''
                );
                return array(
                    'name'       => $rl_name,
                    'root'       => $rl_root,
                    'quality'    => $rl_qual,
                    'extensions' => '',
                    'bass_note'  => ($bass_pc !== null)
                        ? $this->pc_to_note_name($bass_pc, $rl_qual) : null,
                    'inversion'  => '',
                    'diagram_id' => $diagram_id,
                    'confidence' => 'rootless',
                    'rootless'   => true,
                );
            }

            return array(
                'name' => null, 'root' => null, 'quality' => null,
                'extensions' => '', 'bass_note' => null,
                'diagram_id' => null, 'confidence' => 'none',
            );
        }

        // ── Pass 3b: Rootless upgrade — triad → rootless 7th ────────────────
        // When the main algorithm identified a plain triad (maj, min, aug) from
        // only 3 pitch classes, check if those same notes are better explained
        // as a rootless 7th chord voicing. In jazz context, rootless Cm7 is
        // more informative than Eb major triad for the same 3 notes.
        //
        // Triads with quality 'maj', 'min', 'm', 'aug' are candidates.
        // If rootless detection finds a 7th-chord interpretation, prefer it.
        //
        // GUARD: Only upgrade when the voicing uses ≤3 fretted strings.
        // A 5-string open C major chord has 3 unique PCs but is clearly a
        // full triad with doublings, not a rootless voicing.
        $is_plain_triad = in_array($best_quality, array('maj', 'min', 'm', 'aug'), true);
        $fretted_count  = strlen(str_replace('x', '', strtolower($frets)));
        if ($is_plain_triad && count($pc_set) <= 3 && $fretted_count <= 3) {
            $rootless = $this->identify_rootless($pc_set, $bass_pc);
            if ($rootless) {
                $rl_root = $rootless['root_name'];
                $rl_qual = $rootless['quality'];
                $rl_name = $rootless['name'];
                $diagram_id = $this->find_diagram_by_frets(
                    $frets, $position, $rl_root, $rl_qual, '', ''
                );
                return array(
                    'name'       => $rl_name,
                    'root'       => $rl_root,
                    'quality'    => $rl_qual,
                    'extensions' => '',
                    'bass_note'  => ($bass_pc !== null)
                        ? $this->pc_to_note_name($bass_pc, $rl_qual) : null,
                    'inversion'  => '',
                    'diagram_id' => $diagram_id,
                    'confidence' => 'rootless',
                    'rootless'   => true,
                );
            }
        }

        // ── Step 3: Extension detection — PASS 2 ────────────────────────────
        //
        // Pitch classes that are NOT chord tones of the identified quality are
        // checked against the extension interval map. Any that map to a known
        // extension label are appended as "(b13)", "(9)", "(b9,#11)", etc.
        // Unlabelled intervals (rare edge cases) are silently ignored rather
        // than producing garbage output.

        $base_pcs = array_map(
            function($iv) use ($best_root_pc) { return ($best_root_pc + $iv) % 12; },
            self::$quality_intervals[$best_quality]
        );
        $leftover_pcs = array_diff($pc_set, $base_pcs);

        $extension_labels = array();
        foreach ($leftover_pcs as $lpc) {
            $iv = ($lpc - $best_root_pc + 12) % 12;
            if (isset(self::$extension_intervals[$iv])) {
                // Key by interval for sorting (lower extensions first: #9 before b13)
                $extension_labels[$iv] = self::$extension_intervals[$iv];
            }
            // Unrecognised interval: skip (don't output "iv6" etc.)
        }
        ksort($extension_labels); // sort by interval ascending (e.g. #9 before b13)
        $extensions_str = !empty($extension_labels)
            ? '(' . implode(',', $extension_labels) . ')'
            : '';

        // ── Step 4: Inversion / slash detection ─────────────────────────────
        //
        // If the bass pitch class differs from the identified root, classify:
        //
        //   Inversion — bass is a chord tone of the identified quality:
        //     interval root→bass  3 or 4  → 'first'   (3rd in bass)
        //     interval root→bass  6 or 7  → 'second'  (5th in bass)
        //     interval root→bass  9,10,11 → 'third'   (7th in bass)
        //
        //   True slash — bass is NOT a chord tone (e.g. Dm7/G):
        //     inversion = '' but slash suffix still appended
        //
        // These labels match the values stored in sbn_chord_diagrams.inversion,
        // allowing find_diagram_by_frets() to filter shapes correctly.

        $slash_suffix   = '';
        $bass_note_name = null;
        $inversion      = '';

        if ($bass_pc !== null && $bass_pc !== $best_root_pc) {
            $bass_note_name  = $this->pc_to_note_name($bass_pc, $best_quality);
            $slash_suffix    = '/' . $bass_note_name;

            // Interval from root to bass (0–11)
            $bass_interval = ($bass_pc - $best_root_pc + 12) % 12;

            // Is the bass a chord tone of the identified quality?
            $quality_ivs  = self::$quality_intervals[$best_quality];
            $is_chord_tone = in_array($bass_interval, $quality_ivs, true);

            if ($is_chord_tone) {
                // Map interval to inversion label
                if ($bass_interval === 3 || $bass_interval === 4) {
                    $inversion = 'first';   // minor or major 3rd
                } elseif ($bass_interval === 6 || $bass_interval === 7) {
                    $inversion = 'second';  // diminished or perfect 5th
                } elseif ($bass_interval >= 9 && $bass_interval <= 11) {
                    $inversion = 'third';   // major 6th / minor or major 7th
                }
            }
            // If not a chord tone: $inversion stays '' — true slash chord
        }

        // ── Step 5: Assemble name ────────────────────────────────────────────
        $root_name  = $this->pc_to_note_name($best_root_pc, $best_quality);
        $chord_name = $root_name . $best_quality . $extensions_str . $slash_suffix;

        // Confidence: full base match = exact, one tone missing = partial
        $base_matched = count(array_intersect($base_pcs, $pc_set));
        $confidence   = ($base_matched === count($base_pcs)) ? 'exact' : 'partial';

        // ── Step 6: Brute-force DB scan for diagram_id ───────────────────────
        $diagram_id = $this->find_diagram_by_frets($frets, $position, $root_name, $best_quality, $inversion, $bass_note_name);

        return array(
            'name'       => $chord_name,
            'root'       => $root_name,
            'quality'    => $best_quality,
            'extensions' => $extensions_str ? trim($extensions_str, '()') : '',
            'bass_note'  => $bass_note_name,
            'inversion'  => $inversion,
            'diagram_id' => $diagram_id,
            'confidence' => $confidence,
            'rootless'   => false,
        );
    }

    /**
     * Convert a pitch class integer to a note name string,
     * choosing sharp or flat spelling based on the quality context.
     *
     * @param int    $pc      Pitch class 0–11
     * @param string $quality Quality string (used to prefer flat roots for certain keys)
     * @return string Note name
     */
    private function pc_to_note_name($pc, $quality = '') {
        // Use flat spelling for qualities that traditionally use flat roots
        // (The flat_roots array covers the circle-of-fifths flat side)
        $note_sharp = self::$note_names_sharp[$pc];
        $note_flat  = self::$note_names_flat[$pc];

        // If the sharp name is a natural note, no ambiguity
        if ($note_sharp === $note_flat) return $note_sharp;

        // Prefer flats for qualities rooted on flat keys
        if (in_array($note_flat, self::$flat_roots)) return $note_flat;

        return $note_sharp;
    }

    /**
     * Attempt to identify a rootless voicing from a pitch class set.
     *
     * Tries all 12 possible absent roots against the rootless templates.
     * Returns the best candidate, or null if no template matches.
     *
     * For disambiguation when multiple templates match, prefers:
     *   1. More specific qualities (7(#9) over plain 7)
     *   2. Qualities with more intervals matched
     *   3. First match in template priority order
     *
     * @param array $pc_set   Unique pitch classes present in the voicing
     * @param int   $bass_pc  Pitch class of the lowest sounding note (or null)
     * @return array|null { root_pc, root_name, quality, name, confidence }
     */
    private function identify_rootless($pc_set, $bass_pc = null) {
        $pc_frozen = array_values(array_unique($pc_set));
        $pc_count  = count($pc_frozen);

        // Rootless templates are designed for 3-note voicings.
        // 2-note is too ambiguous; 4+ should be handled by the main algorithm.
        if ($pc_count < 3 || $pc_count > 3) return null;

        $candidates = array();

        foreach (self::$rootless_templates as $quality => $voicing_types) {
            foreach ($voicing_types as $template) {
                $template_set = $template; // array of intervals from absent root

                // Try each of the 12 pitch classes as the absent root
                for ($root_pc = 0; $root_pc < 12; $root_pc++) {
                    // Compute expected pitch classes if this root were the absent root
                    $expected = array();
                    foreach ($template_set as $iv) {
                        $expected[] = ($root_pc + $iv) % 12;
                    }
                    sort($expected);

                    $actual = $pc_frozen;
                    sort($actual);

                    if ($expected === $actual) {
                        $root_name = $this->pc_to_note_name($root_pc, $quality);
                        $candidates[] = array(
                            'root_pc'   => $root_pc,
                            'root_name' => $root_name,
                            'quality'   => $quality,
                            'name'      => $root_name . $quality,
                        );
                    }
                }
            }
        }

        if (empty($candidates)) return null;

        // If only one candidate, use it
        if (count($candidates) === 1) {
            return $candidates[0];
        }

        // Multiple candidates: prefer 7th chord qualities over triadic ones,
        // and more specific extensions over plain qualities.
        // Priority: 7(#9) > m7 > 7 > m7b5 > 7(b9) > maj7 > dim7 > m6 > 6 > mMaj7
        // (This matches the template declaration order in $rootless_templates)
        $quality_priority = array_keys(self::$rootless_templates);
        usort($candidates, function($a, $b) use ($quality_priority) {
            $ai = array_search($a['quality'], $quality_priority);
            $bi = array_search($b['quality'], $quality_priority);
            if ($ai === false) $ai = 999;
            if ($bi === false) $bi = 999;
            return $ai - $bi;
        });

        return $candidates[0];
    }

    /**
     * Scan the chord diagrams DB for a shape that transposes to match the
     * given fret string.  Mirrors the forward-match logic in match_voicing()
     * but driven by pitch-class analysis rather than a named chord.
     *
     * For inversions: first tries shapes tagged with the matching inversion
     * label ('first', 'second', 'third'), then falls back to all shapes of
     * that quality so the library doesn't need to be 100% tagged yet.
     *
     * For true slash chords (non-chord-tone bass): prefers shapes that have
     * a bass_note column set, using calculate_frets_with_bass().
     *
     * Returns diagram ID on match, null otherwise.
     *
     * @param string $frets      Fret string (6 chars)
     * @param int    $position   Position hint
     * @param string $root       Root note name (e.g. "G")
     * @param string $quality    Quality string (e.g. "maj7")
     * @param string $inversion  Inversion label: 'first'|'second'|'third'|''
     * @param string $bass_note  Bass note name for slash chords (e.g. "F")
     * @return int|null
     */
    private function find_diagram_by_frets($frets, $position, $root, $quality, $inversion = '', $bass_note = '') {
        global $wpdb;
        $diagrams_table = $wpdb->prefix . 'sbn_chord_diagrams';
        $calculator     = $this->get_calculator();

        $quality_variants = $this->get_quality_variants($quality);
        $placeholders     = implode(',', array_fill(0, count($quality_variants), '%s'));

        $shapes = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $diagrams_table WHERE quality IN ($placeholders)",
            $quality_variants
        ));

        if (empty($shapes)) return null;

        $target_fret_array = $this->normalize_open_equivalents(
            $this->parse_fret_string($frets, $position)
        );

        // Build candidate passes:
        //   Pass 1 (inversions only): shapes whose inversion tag matches exactly.
        //   Pass 2 (fallback): all shapes — catches un-tagged shapes and root position.
        $candidate_sets = array();
        if (!empty($inversion)) {
            $inv_filtered = array_values(array_filter($shapes, function($s) use ($inversion) {
                return $s->inversion === $inversion;
            }));
            if (!empty($inv_filtered)) {
                $candidate_sets[] = $inv_filtered;
            }
        }
        $candidate_sets[] = $shapes; // fallback always present

        foreach ($candidate_sets as $candidates) {
            foreach ($candidates as $shape) {
                // True slash chord (non-chord-tone bass): use bass-aware transposition
                // when the shape has a bass_note stored.
                if (!empty($bass_note) && empty($inversion) && !empty($shape->bass_note)) {
                    $calculated = $calculator->calculate_frets_with_bass($shape, $root, $bass_note);
                } else {
                    $calculated = $calculator->calculate_frets($shape, $root);
                }
                if (!$calculated || empty($calculated['diagram_data'])) continue;

                $calc_array = $this->normalize_open_equivalents(
                    $this->diagram_to_fret_array($calculated['diagram_data'])
                );
                if (!$this->has_valid_frets($calc_array)) continue;

                if ($this->fret_arrays_match($calc_array, $target_fret_array)) {
                    return intval($shape->id);
                }

                // Also accept subset match (voicing omits a string from the library shape)
                if ($this->is_subset_match($target_fret_array, $calc_array) !== false) {
                    return intval($shape->id);
                }
            }
        }

        return null;
    }

    // =========================================================================
    // AJAX HANDLERS
    // =========================================================================
    
    /**
     * Dismiss a voicing draft
     */
    public function ajax_dismiss_draft() {
        check_ajax_referer('sbn_voicing_crossref_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $id = intval($_POST['draft_id'] ?? 0);
        if (!$id) {
            wp_send_json_error('Invalid draft ID');
        }
        
        global $wpdb;
        $result = $wpdb->update(
            self::get_drafts_table(),
            array('status' => 'dismissed', 'updated_at' => current_time('mysql')),
            array('id' => $id)
        );
        
        if ($result === false) {
            wp_send_json_error('Database error');
        }
        
        wp_send_json_success();
    }

    /**
     * Clear all pending drafts (unmatched voicings).
     * Useful after test imports to reset the queue.
     * Dismissed drafts are left untouched.
     */
    public function ajax_clear_all_drafts() {
        check_ajax_referer('sbn_clear_all_drafts', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }

        global $wpdb;
        $deleted = $wpdb->delete(
            self::get_drafts_table(),
            array('status' => 'pending'),
            array('%s')
        );

        if ($deleted === false) {
            wp_send_json_error('Database error');
        }

        wp_send_json_success(array('deleted' => (int) $deleted));
    }
    
    /**
     * Promote a draft to the chord diagram library
     * 
     * Creates a new chord diagram entry pre-populated with the
     * draft's voicing data, then redirects to the diagram editor.
     */
    public function ajax_promote_draft() {
        check_ajax_referer('sbn_voicing_crossref_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $id = intval($_POST['draft_id'] ?? 0);
        if (!$id) {
            wp_send_json_error('Invalid draft ID');
        }
        
        $draft = $this->get_draft($id);
        if (!$draft) {
            wp_send_json_error('Draft not found');
        }
        
        // Convert the fret string + position into diagram_data JSON
        $diagram_data = $this->fret_string_to_diagram_data($draft->fret_string, $draft->position, $draft->fingers);
        
        // Determine the root string from the voicing
        $root_string = $this->detect_root_string($draft->fret_string);
        
        // Create a new chord diagram entry
        global $wpdb;
        $diagrams_table = $wpdb->prefix . 'sbn_chord_diagrams';
        
        // Generate a slug
        $slug_root = strtolower(str_replace(array('#', 'b'), array('s', 'b'), $draft->root_note));
        $slug = sanitize_title($slug_root . $draft->quality . '-draft-' . $draft->id);
        
        $data = array(
            'slug' => $slug,
            'name' => $draft->chord_name . ' (from ' . $draft->leadsheet_title . ')',
            'root_note' => $draft->root_note,
            'quality' => $draft->quality,
            'extensions' => '',
            'voicing_category' => '',  // To be filled in by teacher
            'root_string' => $root_string,
            'inversion' => 'root',     // To be filled in by teacher
            'bass_note' => $draft->bass_note ?? '',
            'start_fret' => max(1, $draft->position),
            'diagram_data' => wp_json_encode($diagram_data),
            'interval_labels' => '',
            'notes' => '',
            'description' => 'Imported from leadsheet: ' . $draft->leadsheet_title,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        );
        
        $result = $wpdb->insert($diagrams_table, $data);
        
        if ($result === false) {
            wp_send_json_error('Database error: ' . $wpdb->last_error);
        }
        
        $new_diagram_id = $wpdb->insert_id;
        
        // Mark draft as promoted
        $wpdb->update(
            self::get_drafts_table(),
            array(
                'status' => 'promoted',
                'notes' => 'Promoted to diagram #' . $new_diagram_id,
                'updated_at' => current_time('mysql'),
            ),
            array('id' => $id)
        );
        
        // Return the edit URL so the admin JS can redirect
        wp_send_json_success(array(
            'diagram_id' => $new_diagram_id,
            'edit_url' => admin_url('admin.php?page=sbn-chord-diagrams&action=edit&id=' . $new_diagram_id),
        ));
    }
    
    /**
     * Reprocess all leadsheets via AJAX
     */
    public function ajax_reprocess_all() {
        check_ajax_referer('sbn_voicing_crossref_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $results = $this->process_all_leadsheets();
        
        // Diagnostic: confirm which version of the code is running
        $results['crossref_version'] = 'v2-quality-alias-fix';
        $results['has_split_quality'] = method_exists($this, 'split_quality_extensions');
        $results['has_fret_array'] = method_exists($this, 'diagram_to_fret_array');
        
        wp_send_json_success($results);
    }
    
    /**
     * Debug endpoint: trace a single voicing match step by step
     * 
     * Call from browser console:
     * jQuery.post(ajaxurl, {action:'sbn_debug_match_voicing', nonce:sbnVoicingCrossref.nonce, chord_name:'C#7', fret_string:'x4342x'})
     *   .done(r => console.log(JSON.stringify(r.data, null, 2)))
     */
    public function ajax_debug_match() {
        check_ajax_referer('sbn_voicing_crossref_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $chord_name = sanitize_text_field($_POST['chord_name'] ?? '');
        $fret_string = sanitize_text_field($_POST['fret_string'] ?? '');
        
        if (empty($chord_name)) {
            wp_send_json_error('Missing chord_name');
        }
        
        $debug = array('chord_name' => $chord_name, 'fret_string' => $fret_string);
        
        // Step 1: Parse the chord name
        if (!function_exists('sbn_parse_chord_name')) {
            require_once SBN_PLUGIN_DIR . 'inc/chord-search-handler.php';
        }
        $parsed = sbn_parse_chord_name($chord_name);
        $debug['parsed'] = $parsed;
        
        if (!$parsed) {
            wp_send_json_success(array_merge($debug, array('error' => 'Failed to parse chord name')));
            return;
        }
        
        // Step 2: Split quality into base + extensions
        $quality_parts = $this->split_quality_extensions($parsed['quality']);
        $debug['quality_split'] = $quality_parts;
        
        // Step 3: Get quality variants
        $quality_variants = $this->get_quality_variants($quality_parts['base']);
        $debug['quality_variants'] = $quality_variants;
        
        // Step 4: Query the database
        global $wpdb;
        $diagrams_table = $wpdb->prefix . 'sbn_chord_diagrams';
        
        $placeholders = implode(',', array_fill(0, count($quality_variants), '%s'));
        
        // First try with extensions
        if (!empty($quality_parts['extensions'])) {
            $query_with_ext = $wpdb->prepare(
                "SELECT id, slug, quality, extensions, voicing_category, root_string, inversion, root_note FROM $diagrams_table WHERE quality IN ($placeholders) AND extensions = %s",
                array_merge($quality_variants, array($quality_parts['extensions']))
            );
            $debug['sql_with_ext'] = $query_with_ext;
            $shapes_with_ext = $wpdb->get_results($query_with_ext);
            $debug['shapes_with_ext_count'] = count($shapes_with_ext);
        }
        
        // Then without extensions
        $query_base = $wpdb->prepare(
            "SELECT id, slug, quality, extensions, voicing_category, root_string, inversion, root_note FROM $diagrams_table WHERE quality IN ($placeholders)",
            $quality_variants
        );
        $debug['sql_base'] = $query_base;
        $shapes = $wpdb->get_results($query_base);
        $debug['shapes_found'] = count($shapes);
        $debug['shapes'] = array();
        
        foreach ($shapes as $shape) {
            $debug['shapes'][] = array(
                'id' => $shape->id,
                'slug' => $shape->slug,
                'quality' => $shape->quality,
                'extensions' => $shape->extensions,
                'voicing_category' => $shape->voicing_category,
                'root_string' => $shape->root_string,
                'inversion' => $shape->inversion,
                'root_note' => $shape->root_note,
            );
        }
        
        // Step 5: If we have a fret string, try transposing each shape and comparing
        if (!empty($fret_string) && !empty($shapes)) {
            $calculator = $this->get_calculator();
            $debug_position = intval($_POST['position'] ?? 1);
            $target_fret_array = $this->normalize_open_equivalents(
                $this->parse_fret_string($fret_string, $debug_position)
            );
            $debug['target_fret_array'] = $target_fret_array;
            $debug['transpositions'] = array();
            
            // Load full shape data for transposition
            $full_shapes = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $diagrams_table WHERE quality IN ($placeholders)",
                $quality_variants
            ));
            
            foreach ($full_shapes as $shape) {
                $calculated = $calculator->calculate_frets($shape, $parsed['root']);
                
                if (!$calculated || empty($calculated['diagram_data'])) {
                    $debug['transpositions'][] = array(
                        'slug' => $shape->slug,
                        'result' => 'EMPTY/FAILED',
                    );
                    continue;
                }
                
                $calc_fret_array = $this->normalize_open_equivalents(
                    $this->diagram_to_fret_array($calculated['diagram_data'])
                );
                $is_valid = $this->has_valid_frets($calc_fret_array);
                $is_exact = $this->fret_arrays_match($calc_fret_array, $target_fret_array);
                $subset = $is_exact ? 'N/A' : $this->is_subset_match($target_fret_array, $calc_fret_array);
                $superset = ($is_exact || $subset !== false) ? 'N/A' : $this->is_superset_match($target_fret_array, $calc_fret_array);
                $fragment = ($is_exact || $subset !== false || $superset !== false)
                    ? 'N/A'
                    : $this->is_root_relocated_fragment_match($target_fret_array, $calc_fret_array, $parsed['root'], $quality_parts['base']);
                
                $debug['transpositions'][] = array(
                    'slug' => $shape->slug,
                    'root_note' => $shape->root_note,
                    'calculated_frets' => $this->fret_array_to_string($calc_fret_array),
                    'target_frets' => $this->fret_array_to_string($target_fret_array),
                    'valid_frets' => $is_valid,
                    'exact_match' => $is_exact,
                    'subset_match' => $subset,
                    'superset_match' => $superset,
                    'fragment_match' => $fragment,
                );
            }
        }
        
        // Step 6: Also list all unique qualities in the DB for reference
        $all_qualities = $wpdb->get_col("SELECT DISTINCT quality FROM $diagrams_table ORDER BY quality");
        $debug['all_db_qualities'] = $all_qualities;
        
        wp_send_json_success($debug);
    }
    
    /**
     * Debug endpoint: reprocess a specific leadsheet title and return per-voicing results
     * 
     * Call from browser console:
     * jQuery.post(ajaxurl, {action:'sbn_debug_reprocess_one', nonce:sbnCrossref.nonce, title:'Girl from Ipanema'})
     *   .done(r => console.log(JSON.stringify(r.data, null, 2)))
     */
    public function ajax_debug_reprocess_one() {
        check_ajax_referer('sbn_voicing_crossref_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $title = sanitize_text_field($_POST['title'] ?? '');
        
        global $wpdb;
        $leadsheet_table = $wpdb->prefix . 'sbn_leadsheets';
        
        // Find the leadsheet by title (partial match)
        $sheet = $wpdb->get_row($wpdb->prepare(
            "SELECT id, title, shortcode_content FROM $leadsheet_table WHERE title LIKE %s LIMIT 1",
            '%' . $wpdb->esc_like($title) . '%'
        ));
        
        if (!$sheet) {
            wp_send_json_error('No leadsheet found matching: ' . $title);
            return;
        }
        
        $debug = array(
            'leadsheet_id' => $sheet->id,
            'leadsheet_title' => $sheet->title,
        );
        
        // Extract voicings
        $voicings = $this->extract_voicings($sheet->shortcode_content);
        $debug['voicings_found'] = count($voicings);
        $debug['voicings'] = array();
        
        foreach ($voicings as $voicing) {
            $v_debug = array(
                'chord_name' => $voicing['chord_name'],
                'fret_string' => $voicing['fret_string'],
                'parsed_root' => $voicing['root'],
                'parsed_quality' => $voicing['quality'],
                'parsed_bass' => $voicing['bass_note'] ?? '',
            );
            
            // Try matching
            $result = $this->match_voicing($voicing);
            $v_debug['matched'] = ($result !== false);
            $v_debug['match_result'] = $result;
            
            $debug['voicings'][] = $v_debug;
        }
        
        // Also show current state of drafts for this leadsheet
        $drafts_table = self::get_drafts_table();
        $drafts = $wpdb->get_results($wpdb->prepare(
            "SELECT chord_name, fret_string, status FROM $drafts_table WHERE leadsheet_id = %d",
            $sheet->id
        ));
        $debug['existing_drafts'] = $drafts;
        
        // And current matches
        $usage_table = self::get_usage_table();
        $matches = $wpdb->get_results($wpdb->prepare(
            "SELECT chord_name, fret_string, chord_diagram_id FROM $usage_table WHERE leadsheet_id = %d",
            $sheet->id
        ));
        $debug['existing_matches'] = $matches;
        
        wp_send_json_success($debug);
    }
    
    /**
     * Convert a fret string like "x02010" into diagram_data JSON structure
     * 
     * @param string $fret_string Fret string (e.g., "x02010", "x5x565")
     * @param int    $position   Starting position from the shortcode
     * @param string $fingers    Finger string (e.g., "002010")
     * @return array Diagram data structure
     */
    private function fret_string_to_diagram_data($fret_string, $position = 1, $fingers = '') {
        $positions = array();
        $muted = array();
        $open = array();
        
        $chars = str_split($fret_string);
        $finger_chars = $fingers ? str_split($fingers) : array();
        
        for ($i = 0; $i < count($chars); $i++) {
            $string = $i + 1;  // 1-indexed
            $char = $chars[$i];
            
            if ($char === 'x' || $char === 'X') {
                $muted[] = $string;
            } elseif ($char === '0') {
                $open[] = $string;
            } else {
                $fret = intval($char);
                $finger = isset($finger_chars[$i]) ? intval($finger_chars[$i]) : null;
                if ($finger === 0) $finger = null;
                
                $positions[] = array(
                    'string' => $string,
                    'fret' => $fret,
                    'finger' => $finger,
                );
            }
        }
        
        return array(
            'positions' => $positions,
            'barres' => array(),
            'muted' => $muted,
            'open' => $open,
        );
    }
    
    /**
     * Detect the likely root string from a fret string
     * 
     * Finds the lowest-pitched (leftmost) sounding string.
     * 
     * @param string $fret_string Fret string
     * @return string Root string identifier (roote, roota, rootd, rootg)
     */
    private function detect_root_string($fret_string) {
        $map = array(
            1 => 'roote',
            2 => 'roota',
            3 => 'rootd',
            4 => 'rootg',
            5 => 'rootb',
            6 => 'roothighe',
        );
        
        $chars = str_split($fret_string);
        for ($i = 0; $i < count($chars); $i++) {
            if ($chars[$i] !== 'x' && $chars[$i] !== 'X') {
                $string = $i + 1;
                return $map[$string] ?? 'roota';
            }
        }
        
        return 'roota';  // fallback
    }
}
