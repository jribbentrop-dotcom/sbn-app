<?php
/**
 * SBN Chord Progression Library
 *
 * Stores named chord progressions as transposable Roman-numeral archetypes,
 * detects their occurrence inside leadsheet sections, and surfaces that
 * information both in the admin editor and in the student-facing player.
 *
 * Architecture mirrors SBN_Voicing_Crossref:
 *   - Singleton, instantiated in the main plugin file
 *   - activate()        → create / upgrade DB tables
 *   - process_leadsheet()  → called from ajax_save_leadsheet hook
 *   - Admin UI          → sub-page under SBN Courses
 *
 * @package SBN_Course_Player
 * @since   7.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class SBN_Chord_Progressions {

    // =========================================================================
    // CONSTANTS
    // =========================================================================

    const PROGRESSIONS_TABLE = 'sbn_chord_progressions';
    const OCCURRENCES_TABLE  = 'sbn_progression_occurrences';
    const VERSION            = '1.3.0';

    // =========================================================================
    // SINGLETON
    // =========================================================================

    private static $instance = null;

    public static function instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        if ( is_admin() ) {
            add_action( 'admin_menu',            array( $this, 'add_admin_menu' ) );
            add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
            add_action( 'admin_init',            array( $this, 'maybe_upgrade_tables' ) );

            add_action( 'wp_ajax_sbn_save_progression',       array( $this, 'ajax_save_progression' ) );
            add_action( 'wp_ajax_sbn_delete_progression',     array( $this, 'ajax_delete_progression' ) );
            add_action( 'wp_ajax_sbn_reprocess_progressions', array( $this, 'ajax_reprocess_all' ) );
        }
    }

    /**
     * Ensure new columns exist on the progressions table.
     * Runs on admin_init so it doesn't depend on the activation hook firing.
     * Uses a version option to avoid running ALTER TABLE on every page load.
     */
    public function maybe_upgrade_tables() {
        $installed_version = get_option( 'sbn_chord_progressions_version', '0' );
        if ( version_compare( $installed_version, self::VERSION, '>=' ) ) {
            return;
        }

        global $wpdb;
        $pt = self::get_progressions_table();

        // Add tonality column if missing
        $cols = $wpdb->get_col( "DESCRIBE $pt", 0 );
        if ( ! in_array( 'tonality', $cols, true ) ) {
            $wpdb->query( "ALTER TABLE $pt ADD COLUMN tonality varchar(10) NOT NULL DEFAULT 'both' AFTER typical_genres" );
        }
        if ( ! in_array( 'match_mode', $cols, true ) ) {
            $wpdb->query( "ALTER TABLE $pt ADD COLUMN match_mode varchar(10) NOT NULL DEFAULT 'strict' AFTER tonality" );
        }
        if ( ! in_array( 'tags', $cols, true ) ) {
            $wpdb->query( "ALTER TABLE $pt ADD COLUMN tags varchar(255) NOT NULL DEFAULT '' AFTER typical_genres" );
        }
        if ( ! in_array( 'featured', $cols, true ) ) {
            $wpdb->query( "ALTER TABLE $pt ADD COLUMN featured tinyint(1) NOT NULL DEFAULT 0 AFTER sort_order" );
        }

        update_option( 'sbn_chord_progressions_version', self::VERSION );
    }

    // =========================================================================
    // TABLE NAME HELPERS
    // =========================================================================

    public static function get_progressions_table() {
        global $wpdb;
        return $wpdb->prefix . self::PROGRESSIONS_TABLE;
    }

    public static function get_occurrences_table() {
        global $wpdb;
        return $wpdb->prefix . self::OCCURRENCES_TABLE;
    }

    // =========================================================================
    // DATABASE SETUP
    // =========================================================================

    public static function activate() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // ── Progressions library ──────────────────────────────────────────────
        $pt = self::get_progressions_table();
        dbDelta( "CREATE TABLE $pt (
            id          bigint(20)   NOT NULL AUTO_INCREMENT,
            name        varchar(120) NOT NULL DEFAULT '',
            category    varchar(50)  NOT NULL DEFAULT 'jazz',
            numerals    varchar(255) NOT NULL DEFAULT '',
            description text,
            typical_genres varchar(255) DEFAULT '',
            tags        varchar(255) NOT NULL DEFAULT '',
            tonality    varchar(10)  NOT NULL DEFAULT 'both',
            match_mode  varchar(10)  NOT NULL DEFAULT 'strict',
            sort_order  int(11)      NOT NULL DEFAULT 0,
            featured    tinyint(1)   NOT NULL DEFAULT 0,
            created_at  datetime     DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY category (category)
        ) $charset_collate;" );

        // ── Occurrences (detection results) ──────────────────────────────────
        $ot = self::get_occurrences_table();
        dbDelta( "CREATE TABLE $ot (
            id             bigint(20)   NOT NULL AUTO_INCREMENT,
            progression_id bigint(20)   NOT NULL,
            leadsheet_id   bigint(20)   NOT NULL,
            section_id     varchar(10)  NOT NULL DEFAULT 'A',
            start_measure  int(11)      NOT NULL DEFAULT 0,
            length_measures int(11)     NOT NULL DEFAULT 1,
            detected_root  varchar(5)   NOT NULL DEFAULT 'C',
            confidence     float        NOT NULL DEFAULT 1.0,
            created_at     datetime     DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY progression_id (progression_id),
            KEY leadsheet_id (leadsheet_id),
            KEY leadsheet_section (leadsheet_id, section_id)
        ) $charset_collate;" );

        // ── Seed starter library if table is empty ────────────────────────────
        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $pt" );
        if ( $count === 0 ) {
            self::seed_starter_library();
        }

        update_option( 'sbn_chord_progressions_version', self::VERSION );
    }

    // =========================================================================
    // STARTER LIBRARY SEED DATA
    // =========================================================================

    /**
     * Pre-populate the progressions table with ~40 common progressions.
     * Called once on first activation when the table is empty.
     */
    private static function seed_starter_library() {
        global $wpdb;
        $pt = self::get_progressions_table();

        $seed = array(
            // ── Jazz ─────────────────────────────────────────────────────────
            array(
                'name'          => 'ii–V–I (major)',
                'category'      => 'jazz',
                'numerals'      => 'IIm7,V7,Imaj7',
                'description'   => 'The most fundamental progression in jazz. The minor ii chord creates tension that resolves through the dominant V7 to the tonic Imaj7. Mastering this in all 12 keys is essential for any jazz musician.',
                'typical_genres'=> 'jazz,bossa nova,swing',
                'sort_order'    => 10,
            ),
            array(
                'name'          => 'ii–V–i (minor)',
                'category'      => 'jazz',
                'numerals'      => 'IIm7b5,V7b9,Im',
                'description'   => 'The minor-key version of the ii–V–I. The half-diminished ii chord and the altered dominant (V7b9) create a darker, more tense resolution to the minor tonic.',
                'typical_genres'=> 'jazz,latin',
                'tonality'      => 'minor',
                'sort_order'    => 20,
            ),
            array(
                'name'          => 'Rhythm Changes A',
                'category'      => 'jazz',
                'numerals'      => 'Imaj7,VIm7,IIm7,V7',
                'description'   => 'The A section of Rhythm Changes (based on "I Got Rhythm"). This I–VI–II–V turnaround is the backbone of countless jazz standards.',
                'typical_genres'=> 'jazz,bebop,swing',
                'sort_order'    => 30,
            ),
            array(
                'name'          => 'Rhythm Changes Bridge',
                'category'      => 'jazz',
                'numerals'      => 'III7,VI7,II7,V7',
                'description'   => 'The bridge of Rhythm Changes: a chain of dominant 7ths moving through the cycle of 5ths. Each chord is a secondary dominant resolving to the next.',
                'typical_genres'=> 'jazz,bebop',
                'sort_order'    => 40,
            ),
            array(
                'name'          => 'I–VI–II–V Turnaround',
                'category'      => 'jazz',
                'numerals'      => 'Imaj7,VI7,IIm7,V7',
                'description'   => 'The classic jazz turnaround. The VI is used here as a secondary dominant (V/II), pushing toward the IIm7. Used to cycle back to the top of a form.',
                'typical_genres'=> 'jazz,standards',
                'sort_order'    => 50,
            ),
            array(
                'name'          => 'Backdoor Dominant',
                'category'      => 'jazz',
                'numerals'      => 'bVII7,Imaj7',
                'description'   => 'A substitution where the dominant V7 is replaced by the bVII7 — a chord a half-step above the tonic. Common in jazz and soul; creates a smooth, bluesy resolution.',
                'typical_genres'=> 'jazz,soul,funk',
                'sort_order'    => 60,
            ),
            array(
                'name'          => 'Tritone Sub ii–V–I',
                'category'      => 'jazz',
                'numerals'      => 'IIm7,bII7,Imaj7',
                'description'   => 'The dominant V7 is replaced by its tritone substitute (bII7). The bass moves chromatically downward to the tonic, while the voice leading is especially smooth.',
                'typical_genres'=> 'jazz,bebop',
                'sort_order'    => 70,
            ),
            array(
                'name'          => 'Coltrane Changes (truncated)',
                'category'      => 'jazz',
                'numerals'      => 'Imaj7,bIIImaj7,bVImaj7',
                'description'   => 'A simplified version of John Coltrane\'s cycle of major 3rds substitution, dividing the octave symmetrically. Creates rapid harmonic movement with rich inner voice leading.',
                'typical_genres'=> 'jazz,modern jazz',
                'sort_order'    => 80,
            ),
            array(
                'name'          => 'iii–VI–ii–V',
                'category'      => 'jazz',
                'numerals'      => 'IIIm7,VI7,IIm7,V7',
                'description'   => 'An extended turnaround that begins on the iii chord. The IIIm7 and VI7 act as a mini ii–V leading into the main IIm7–V7. Very common in jazz standards.',
                'typical_genres'=> 'jazz,standards,bossa nova',
                'sort_order'    => 90,
            ),
            array(
                'name'          => 'iv–I (Plagal / Minor Subdominant)',
                'category'      => 'jazz',
                'numerals'      => 'IVm7,Imaj7',
                'description'   => 'A minor IV chord resolving to the major tonic — the "plagal" or "Amen" cadence with a jazz twist. Creates a melancholic, soulful feel. Common in bossa nova.',
                'typical_genres'=> 'jazz,bossa nova,soul',
                'sort_order'    => 100,
            ),
            array(
                'name'          => 'Bird Blues (first 4 bars)',
                'category'      => 'jazz',
                'numerals'      => 'Imaj7,IVm7,IIIm7,bIII7',
                'description'   => 'The opening 4 bars of Charlie Parker\'s "reharmonized" blues form. The IV minor and the chromatic descent through IIIm7–bIII7 add bebop sophistication to the standard blues.',
                'typical_genres'=> 'jazz,bebop,blues',
                'sort_order'    => 110,
            ),

            // ── Blues ────────────────────────────────────────────────────────
            array(
                'name'          => '12-Bar Blues',
                'category'      => 'blues',
                'numerals'      => 'I7,I7,I7,I7,IV7,IV7,I7,I7,V7,IV7,I7,V7',
                'description'   => 'The foundational blues form. 12 bars divided into three 4-bar phrases. The dominant 7th quality on all chords (including the I) gives the blues its characteristic sound.',
                'typical_genres'=> 'blues,jazz,rock,country',
                'sort_order'    => 200,
            ),
            array(
                'name'          => 'Quick-Change Blues',
                'category'      => 'blues',
                'numerals'      => 'I7,IV7,I7,I7,IV7,IV7,I7,I7,V7,IV7,I7,V7',
                'description'   => 'A variation of the 12-bar blues where bar 2 goes to the IV chord before returning to I. Also called "quick four." Very common in Chicago and Texas blues.',
                'typical_genres'=> 'blues,jazz',
                'sort_order'    => 210,
            ),
            array(
                'name'          => 'Minor Blues',
                'category'      => 'blues',
                'numerals'      => 'Im7,Im7,Im7,Im7,IVm7,IVm7,Im7,Im7,V7b9,IVm7,Im7,V7b9',
                'description'   => 'The blues in a minor key. The V chord typically carries the altered b9 extension for maximum tension. Used in many jazz standards (e.g., "Mr. P.C.", "Footprints").',
                'typical_genres'=> 'jazz,blues',
                'tonality'      => 'minor',
                'sort_order'    => 220,
            ),
            array(
                'name'          => 'I–IV–V',
                'category'      => 'blues',
                'numerals'      => 'I7,IV7,V7',
                'description'   => 'The core three-chord blues/rock progression. Used in its most direct form in countless songs across blues, rock, country, and folk.',
                'typical_genres'=> 'blues,rock,country,folk',
                'sort_order'    => 230,
            ),

            // ── Pop / Rock ───────────────────────────────────────────────────
            array(
                'name'          => 'I–V–vi–IV',
                'category'      => 'pop',
                'numerals'      => 'I,V,VIm,IV',
                'description'   => 'One of the most ubiquitous progressions in popular music. Sometimes called the "axis progression" (or "Pachelbel\'s progression"). Hundreds of hit songs use this pattern.',
                'typical_genres'=> 'pop,rock',
                'sort_order'    => 300,
            ),
            array(
                'name'          => 'vi–IV–I–V',
                'category'      => 'pop',
                'numerals'      => 'VIm,IV,I,V',
                'description'   => 'A rotation of the I–V–vi–IV, starting on the relative minor. Often used to create a slightly darker or more emotional feel while remaining in a major key.',
                'typical_genres'=> 'pop,rock',
                'sort_order'    => 310,
            ),
            array(
                'name'          => 'I–IV–I–V',
                'category'      => 'pop',
                'numerals'      => 'I,IV,I,V',
                'description'   => 'A simple, powerful progression that forms the basis of rock and country music. The IV chord adds lift before the V creates tension and returns home.',
                'typical_genres'=> 'rock,country,pop',
                'sort_order'    => 320,
            ),
            array(
                'name'          => 'I–II–III (Ascending)',
                'category'      => 'pop',
                'numerals'      => 'I,IIm,IIIm',
                'description'   => 'A stepwise ascending bass line progression. Creates a sense of momentum and lift. Used extensively in soft rock and singer-songwriter contexts.',
                'typical_genres'=> 'pop,rock,soul',
                'sort_order'    => 330,
            ),
            array(
                'name'          => 'IV–V–I (Perfect Authentic Cadence)',
                'category'      => 'pop',
                'numerals'      => 'IV,V,I',
                'description'   => 'The strongest possible cadence: subdominant to dominant to tonic. The "amen" cadence of tonal music. Found in virtually every style from hymns to pop choruses.',
                'typical_genres'=> 'pop,classical,rock,gospel',
                'sort_order'    => 340,
            ),
            array(
                'name'          => 'I–vi–IV–V (50s Progression)',
                'category'      => 'pop',
                'numerals'      => 'I,VIm,IV,V',
                'description'   => 'Closely related to doo-wop and 1950s rock \'n\' roll. The vi chord adds emotional depth between the tonic and subdominant. Used in countless ballads.',
                'typical_genres'=> 'pop,rock,doo-wop',
                'sort_order'    => 350,
            ),
            array(
                'name'          => 'I–V–IV (Rock)',
                'category'      => 'pop',
                'numerals'      => 'I,V,IV',
                'description'   => 'A staple of rock and folk music. Moving to V and then down to IV (rather than the "correct" IV→V→I order) gives it a raw, driving feel.',
                'typical_genres'=> 'rock,folk,country',
                'sort_order'    => 360,
            ),
            array(
                'name'          => 'Andalusian Cadence (Minor)',
                'category'      => 'pop',
                'numerals'      => 'Im,bVII,bVI,V',
                'description'   => 'A descending chromatic bass line in a natural minor key. Creates a flamenco/classical feel. Used in rock (Stairway to Heaven, Hit the Road Jack) and Latin music.',
                'typical_genres'=> 'rock,latin,flamenco,pop',
                'tonality'      => 'minor',
                'sort_order'    => 370,
            ),
            array(
                'name'          => 'I–bVII–IV (Rock)',
                'category'      => 'pop',
                'numerals'      => 'I,bVII,IV',
                'description'   => 'A common rock/blues-rock progression that borrows the bVII from the Mixolydian/pentatonic scale. Creates a raw, powerful feel without a minor chord.',
                'typical_genres'=> 'rock,blues rock',
                'sort_order'    => 380,
            ),

            // ── Modal ────────────────────────────────────────────────────────
            array(
                'name'          => 'Dorian Vamp (i–IV)',
                'category'      => 'modal',
                'numerals'      => 'Im7,IV7',
                'description'   => 'The hallmark of Dorian mode: a minor i chord and a major IV chord (with the raised 6th). Used in countless jazz/fusion pieces (Miles Davis\'s "So What", Santana).',
                'typical_genres'=> 'jazz,fusion,rock,latin',
                'sort_order'    => 400,
            ),
            array(
                'name'          => 'So What Changes',
                'category'      => 'modal',
                'numerals'      => 'Im7,bIIm7',
                'description'   => 'From Miles Davis\'s "So What": 16 bars on D Dorian, then 8 bars on Eb Dorian (a half-step up), then 8 bars back on D. A foundational modal jazz approach.',
                'typical_genres'=> 'jazz,modal jazz',
                'sort_order'    => 410,
            ),
            array(
                'name'          => 'Lydian (I–II)',
                'category'      => 'modal',
                'numerals'      => 'Imaj7,IImaj7',
                'description'   => 'The characteristic sound of Lydian mode: a major I chord moving to the major II chord (the raised 4th defines the mode). Creates a bright, floating, ethereal feel.',
                'typical_genres'=> 'jazz,film music,fusion',
                'sort_order'    => 420,
            ),
            array(
                'name'          => 'Mixolydian Vamp (I7–bVII)',
                'category'      => 'modal',
                'numerals'      => 'I7,bVII',
                'description'   => 'The sound of Mixolydian mode: a dominant 7th tonic chord moving to the major chord a whole step below. Blues-inflected, used in rock, funk, and folk.',
                'typical_genres'=> 'rock,blues,folk,funk',
                'sort_order'    => 430,
            ),
            array(
                'name'          => 'Phrygian Cadence',
                'category'      => 'modal',
                'numerals'      => 'Im,bII',
                'description'   => 'The characteristic Phrygian move: minor i to the major chord a half-step above (bII, sometimes called the "Spanish cadence" or Neapolitan). Creates a dark, mysterious feel.',
                'typical_genres'=> 'flamenco,metal,classical',
                'tonality'      => 'minor',
                'sort_order'    => 440,
            ),

            // ── Latin / Brazilian ────────────────────────────────────────────
            array(
                'name'          => 'Bossa Nova Turnaround',
                'category'      => 'jazz',
                'numerals'      => 'Imaj7,IVm7,IIIm7,bIII7,IIm7,V7',
                'description'   => 'A sophisticated turnaround common in bossa nova, combining the minor IV substitution with a chromatic descent: Imaj7 → IVm7 → IIIm7 → bIII7 → IIm7 → V7.',
                'typical_genres'=> 'bossa nova,jazz',
                'sort_order'    => 500,
            ),
            array(
                'name'          => 'Samba Turnaround (I–VI–II–V)',
                'category'      => 'jazz',
                'numerals'      => 'Imaj7,VI7,IIm7,V7',
                'description'   => 'A samba-inflected version of the classic jazz turnaround, using VI7 as a secondary dominant. The harmonic rhythm is typically one chord per bar.',
                'typical_genres'=> 'samba,bossa nova,jazz',
                'sort_order'    => 510,
            ),

            // ── Classical / Functional ───────────────────────────────────────
            array(
                'name'          => 'Pachelbel Canon',
                'category'      => 'classical',
                'numerals'      => 'I,V,VIm,IIIm,IV,I,IV,V',
                'description'   => 'The chord sequence from Pachelbel\'s Canon in D. This 8-chord pattern and its variations have become perhaps the most recycled progression in all of popular music.',
                'typical_genres'=> 'classical,pop,rock',
                'sort_order'    => 600,
            ),
            array(
                'name'          => 'Circle of Fifths (partial)',
                'category'      => 'jazz',
                'numerals'      => 'IIIm7,VI7,IIm7,V7,Imaj7',
                'description'   => 'A chain of ii–V relationships moving through the circle of fifths. The entire diatonic cycle of 5ths from iii through to I. Common as a jazz intro or outro.',
                'typical_genres'=> 'jazz,classical',
                'sort_order'    => 610,
            ),
            array(
                'name'          => 'ii–V (Half Cadence)',
                'category'      => 'jazz',
                'numerals'      => 'IIm7,V7',
                'description'   => 'The unresolved ii–V: tension without release. Often used mid-phrase in jazz, or as the last 2 bars of a section that cadences at the top of the next chorus.',
                'typical_genres'=> 'jazz',
                'sort_order'    => 620,
            ),
            array(
                'name'          => 'Deceptive Cadence (V–vi)',
                'category'      => 'classical',
                'numerals'      => 'V7,VIm',
                'description'   => 'Instead of resolving V→I, the dominant "deceives" by moving to the relative minor vi. Creates surprise and emotional tension. Used to extend phrases.',
                'typical_genres'=> 'classical,pop,gospel',
                'sort_order'    => 630,
            ),
        );

        foreach ( $seed as $p ) {
            $wpdb->insert( $pt, $p );
        }
    }

    // =========================================================================
    // CHORD / HARMONY UTILITIES
    // =========================================================================

    /**
     * Chromatic note names (prefer flats — standard in jazz/bossa contexts).
     */
    private static $notes = array( 'C', 'Db', 'D', 'Eb', 'E', 'F', 'F#', 'G', 'Ab', 'A', 'Bb', 'B' );

    /**
     * Semitone values for all note spellings.
     */
    private static $note_to_semi = array(
        'C'  => 0,  'B#' => 0,
        'C#' => 1,  'Db' => 1,
        'D'  => 2,
        'D#' => 3,  'Eb' => 3,
        'E'  => 4,  'Fb' => 4,
        'F'  => 5,  'E#' => 5,
        'F#' => 6,  'Gb' => 6,
        'G'  => 7,
        'G#' => 8,  'Ab' => 8,
        'A'  => 9,
        'A#' => 10, 'Bb' => 10,
        'B'  => 11, 'Cb' => 11,
    );

    /**
     * Diatonic scale degrees (semitone offsets from root) for the major scale.
     * Used to determine the scale degree of a chord root.
     */
    private static $major_scale_semitones = array( 0, 2, 4, 5, 7, 9, 11 );

    /**
     * Map a chord quality string (as returned by sbn_parse_chord_name) to
     * the expected diatonic Roman-numeral quality for a given scale degree.
     *
     * Returns a canonical numeral string, e.g. "IIm7", "V7", "Imaj7".
     *
     * @param  int    $degree   0-indexed scale degree (0=I, 1=II … 6=VII)
     * @param  string $quality  Parsed quality (maj7, m7, dom7, maj, min, etc.)
     * @return string           Roman numeral token, e.g. "IIm7b5"
     *
     * NOTE: Extensions (b9, #11, 9, etc.) are intentionally NOT included.
     * Section tokens represent harmonic function only — G7(b13) and G7(b9)
     * are both "V7" for progression detection. This makes matching robust
     * to any voicing choice the performer uses.
     */
    private static function degree_to_numeral( $degree, $quality ) {
        static $roman = array( 'I', 'II', 'III', 'IV', 'V', 'VI', 'VII' );
        $r = $roman[ $degree ];

        // Normalise quality to a common set
        $q = strtolower( $quality );

        // ── Normalize extended-chord qualities to their base function ────────
        // Progression detection cares about harmonic function, not extensions.
        // Qualities like "9b9", "13", "9b13", "m9", "maj13" etc. can arrive
        // when sbn_parse_chord_name can't fully decompose concatenated MusicXML
        // output (e.g. <kind>dominant-ninth</kind> + <degree>b9</degree> →
        // chord name "E9b9" → quality "9b9"). Reduce them to dom7/m7/maj7.
        if ( ! in_array( $q, array( 'maj7', 'maj', 'dom7', '7', 'm7', 'min7', '-7',
            'min', 'm', 'minor', '-', 'm7b5', 'o7', 'dim7', 'dim', 'mmaj7',
            'maj6', '6', 'm6', 'sus4', 'sus2', 'aug', 'aug7' ) ) ) {
            // Try to identify the base quality from extended/concatenated forms.
            // Order matters: check longer prefixes first.
            if ( preg_match( '/^m7b5/', $q ) ) {
                $q = 'm7b5';
            } elseif ( preg_match( '/^mmaj7/', $q ) ) {
                $q = 'mmaj7';
            } elseif ( preg_match( '/^maj[79]/', $q ) ) {
                // maj9, maj11, maj13, maj9b5, etc. → major 7th function
                $q = 'maj7';
            } elseif ( preg_match( '/^m[79]/', $q ) ) {
                // m9, m11, m13, m9b9, etc. → minor 7th function
                $q = 'm7';
            } elseif ( preg_match( '/^-[79]/', $q ) ) {
                // -9, -11, -13 → minor 7th function (jazz notation)
                $q = 'm7';
            } elseif ( preg_match( '/^\d/', $q ) ) {
                // 9, 9b9, 9b13, 11, 13, 13b9, etc. → dominant 7th function
                $q = 'dom7';
            }
        }

        if ( in_array( $q, array( 'maj7', 'maj' ) ) ) {
            $suffix = ( $q === 'maj7' ) ? 'maj7' : '';
        } elseif ( $q === 'dom7' || $q === '7' ) {
            $suffix = '7';
        } elseif ( in_array( $q, array( 'm7', 'min7', '-7' ) ) ) {
            $suffix = 'm7';
        } elseif ( in_array( $q, array( 'min', 'm', 'minor', '-' ) ) ) {
            $suffix = 'm';
        } elseif ( $q === 'm7b5' ) {
            $suffix = 'm7b5';
        } elseif ( $q === 'o7' || $q === 'dim7' ) {
            $suffix = 'o7';
        } elseif ( $q === 'dim' ) {
            $suffix = 'o';
        } elseif ( in_array( $q, array( 'mmaj7', 'mmaj7' ) ) ) {
            $suffix = 'mMaj7';
        } elseif ( $q === 'maj6' || $q === '6' ) {
            // Major 6 is functionally equivalent to maj7 for progression detection
            // (both serve as tonic major chords; Cmaj6 and Cmaj7 are interchangeable
            // in most harmonic contexts).
            $suffix = 'maj7';
        } elseif ( $q === 'm6' ) {
            $suffix = 'm6';
        } elseif ( $q === 'sus4' || $q === 'sus2' ) {
            $suffix = $q;
        } else {
            $suffix = $quality; // fallback: pass through raw
        }

        return $r . $suffix;
    }

    /**
     * Convert a raw chord name + key to a Roman-numeral token.
     * Returns null if the chord root can't be mapped to the key.
     *
     * Handles:
     *   - Exact diatonic degrees
     *   - Chromatic alterations (bII, bVII, etc.)
     *
     * @param  string $chord_name  e.g. "Dm7", "G7", "Bb7"
     * @param  string $key         e.g. "C", "F", "Bb"
     * @return string|null         e.g. "IIm7", "V7", "bVII7", or null
     */
    public static function chord_to_numeral( $chord_name, $key ) {
        if ( ! function_exists( 'sbn_parse_chord_name' ) ) {
            require_once SBN_PLUGIN_DIR . 'inc/chord-search-handler.php';
        }

        $parsed = sbn_parse_chord_name( $chord_name );
        if ( ! $parsed || empty( $parsed['root'] ) ) {
            return null;
        }

        $root_semi = self::$note_to_semi[ $parsed['root'] ] ?? null;

        // Strip mode suffix from key so "Cm", "Bbm", "F#m" all resolve correctly.
        // The note_to_semi table contains note names only, not modal qualifiers.
        $key_note = preg_replace( '/[mM].*$/', '', trim( $key ) );
        $key_semi = self::$note_to_semi[ $key_note ] ?? null;

        if ( $root_semi === null || $key_semi === null ) {
            return null;
        }

        $interval = ( $root_semi - $key_semi + 12 ) % 12;
        $diatonic  = self::$major_scale_semitones;

        // Look for exact diatonic degree.
        // Extensions intentionally discarded: G7(b13) -> "V7", Dm7b5(9) -> "IIm7b5".
        // Progressions match on harmonic function, not on which extensions are voiced.
        $degree_idx = array_search( $interval, $diatonic, true );
        if ( $degree_idx !== false ) {
            return self::degree_to_numeral( $degree_idx, $parsed['quality'] );
        }

        // Chromatic — apply b accidental. Extensions stripped here too.
        $chromatic_map = array(
            1  => 'bII',   // b2 (Neapolitan)
            3  => 'bIII',  // b3
            6  => 'bV',    // rarely used
            8  => 'bVI',   // b6
            10 => 'bVII',  // b7 (Mixolydian / backdoor)
        );

        if ( isset( $chromatic_map[ $interval ] ) ) {
            $roman_base = $chromatic_map[ $interval ];
            $q = strtolower( $parsed['quality'] );

            // Normalize extended qualities to base function (same logic as degree_to_numeral)
            if ( preg_match( '/^m7b5/', $q ) )       $q = 'm7b5';
            elseif ( preg_match( '/^mmaj7/', $q ) )   $q = 'mmaj7';
            elseif ( preg_match( '/^maj[79]/', $q ) ) $q = 'maj7';
            elseif ( preg_match( '/^m[79]/', $q ) )   $q = 'm7';
            elseif ( preg_match( '/^-[79]/', $q ) )   $q = 'm7';
            elseif ( preg_match( '/^\d/', $q ) )      $q = 'dom7';

            if ( $q === 'dom7' || $q === '7' )                $suffix = '7';
            elseif ( in_array( $q, array( 'm7', 'min7' ) ) )  $suffix = 'm7';
            elseif ( in_array( $q, array( 'min', 'm' ) ) )    $suffix = 'm';
            elseif ( $q === 'maj7' )                          $suffix = 'maj7';
            elseif ( $q === 'maj' )                           $suffix = '';
            elseif ( $q === 'm7b5' )                          $suffix = 'm7b5';
            elseif ( $q === 'o7' || $q === 'dim7' )           $suffix = 'o7';
            elseif ( $q === 'dim' )                            $suffix = 'o';
            elseif ( $q === 'mmaj7' )                         $suffix = 'mMaj7';
            elseif ( $q === 'maj6' || $q === '6' )            $suffix = 'maj7';
            elseif ( $q === 'm6' )                            $suffix = 'm6';
            elseif ( $q === 'sus4' || $q === 'sus2' )         $suffix = $q;
            else                                               $suffix = '';
            return $roman_base . $suffix;
        }

        // Unknown chromatic degree — return interval-based label
        return 'chr' . $interval;
    }

    /**
     * Convert an entire leadsheet section into an array of numeral tokens.
     *
     * Includes a post-processing pass that resolves diminished 7th chords
     * functioning as rootless dominant 7b9 chords. See resolve_dominant_dim7s().
     *
     * @param  array  $section  Section array from the parser (with 'measures')
     * @param  string $key      Song key
     * @return array            Flat array of numeral strings, one per chord slot
     */
    public static function section_to_numerals( $section, $key ) {
        $numerals    = array();
        $chord_roots = array(); // parallel array: semitone value of each chord's root (or null)

        foreach ( $section['measures'] as $measure ) {
            foreach ( $measure['chords'] as $chord ) {
                $n = self::chord_to_numeral( $chord['name'], $key );
                $numerals[] = $n !== null ? $n : '?';

                // Store the root semitone for dim7 resolution lookahead
                if ( ! function_exists( 'sbn_parse_chord_name' ) ) {
                    require_once SBN_PLUGIN_DIR . 'inc/chord-search-handler.php';
                }
                $parsed = sbn_parse_chord_name( $chord['name'] );
                $chord_roots[] = ( $parsed && ! empty( $parsed['root'] ) )
                    ? ( self::$note_to_semi[ $parsed['root'] ] ?? null )
                    : null;
            }
        }

        // Post-process: resolve dim7 chords that function as dominants
        $numerals = self::resolve_dominant_dim7s( $numerals, $chord_roots, $key );

        return $numerals;
    }

    /**
     * Post-processing pass: resolve diminished 7th chords functioning as
     * rootless dominant 7(b9) chords.
     *
     * Theory: every dim7 chord is enharmonically the upper structure of four
     * possible dom7b9 chords. Adim7 (A-C-Eb-Gb) contains the 3-5-b7-b9 of
     * four dominants whose roots sit a semitone below each dim7 member:
     *   A is b9 of Ab7,  C is b9 of B7,  Eb is b9 of D7,  Gb is b9 of F7
     *
     * So for dim7 root R, the candidate dominant roots are:
     *   (R-1)%12, (R+2)%12, (R+5)%12, (R+8)%12
     *
     * Resolution check: for each candidate, test whether the NEXT chord is
     * the expected resolution target via ascending perfect 4th (V→I, the
     * interval-5 check), with a fallback for ascending semitone resolution
     * (common in chromatic voice leading).
     *
     * Example: Adim7 → Dbmaj7 (key of Db)
     *   dim7 root A(9), candidates: Ab(8), B(11), D(2), F(5)
     *   next root Db(1): (1-8+12)%12 = 5 → Ab resolves to Db by P4. ✓
     *   Ab in key Db: interval 7 → diatonic degree V → rewrite as "V7"
     *
     * @param  array  $numerals     Array of numeral tokens
     * @param  array  $chord_roots  Parallel array of root semitone values
     * @param  string $key          Song key
     * @return array                Modified numerals array
     */
    private static function resolve_dominant_dim7s( $numerals, $chord_roots, $key ) {
        $key_note = preg_replace( '/[mM].*$/', '', trim( $key ) );
        $key_semi = self::$note_to_semi[ $key_note ] ?? null;
        if ( $key_semi === null ) {
            return $numerals;
        }

        $count = count( $numerals );

        for ( $i = 0; $i < $count - 1; $i++ ) {
            // Only process dim7 / o7 tokens
            if ( ! preg_match( '/o7$/i', $numerals[ $i ] ) ) {
                continue;
            }

            // Skip if the next chord is the same (held/repeated chord across
            // consecutive bars, not an actual resolution). Without this guard,
            // G#dim7 | G#dim7 would falsely trigger semitone-resolution logic
            // because the dim7 root and its own repeated root are 0 semitones
            // apart, and one of the candidate dominant roots sits a semitone
            // below — making the algorithm think it "resolves" to itself.
            if ( $numerals[ $i ] === $numerals[ $i + 1 ] ) {
                continue;
            }

            $dim_root = $chord_roots[ $i ];
            $next_root = $chord_roots[ $i + 1 ];
            if ( $dim_root === null || $next_root === null ) {
                continue;
            }

            // The four candidate dominant roots:
            // Each dim7 member pitch P has a dom7 whose root is (P-1).
            // dim7 members: R, R+3, R+6, R+9
            // dom7 roots:   R-1, R+2, R+5, R+8
            $candidates = array(
                ( $dim_root - 1 + 12 ) % 12,
                ( $dim_root + 2 ) % 12,
                ( $dim_root + 5 ) % 12,
                ( $dim_root + 8 ) % 12,
            );

            $resolved_dom_root = null;
            foreach ( $candidates as $cand ) {
                // Check: does this candidate resolve to next_root by ascending P4 (interval 5)?
                if ( ( $next_root - $cand + 12 ) % 12 === 5 ) {
                    $resolved_dom_root = $cand;
                    break;
                }
            }

            // Also check semitone resolution (V→I where bass descends by
            // semitone, common in chromatic passing dim contexts).
            // candidate resolves to next_root by ascending semitone (interval 1)
            if ( $resolved_dom_root === null ) {
                foreach ( $candidates as $cand ) {
                    if ( ( $next_root - $cand + 12 ) % 12 === 1 ) {
                        $resolved_dom_root = $cand;
                        break;
                    }
                }
            }

            if ( $resolved_dom_root === null ) {
                continue;
            }

            // Map the dominant root to a Roman numeral degree
            $dom_interval = ( $resolved_dom_root - $key_semi + 12 ) % 12;
            $diatonic     = self::$major_scale_semitones;
            $degree_idx   = array_search( $dom_interval, $diatonic, true );

            $original_token = $numerals[ $i ]; // save before overwriting
            $new_token      = null;

            if ( $degree_idx !== false ) {
                $new_token = self::degree_to_numeral( $degree_idx, 'dom7' );
            } else {
                // Chromatic dominant (e.g. bVI7 as a dominant)
                $chromatic_map = array(
                    1  => 'bII',
                    3  => 'bIII',
                    6  => 'bV',
                    8  => 'bVI',
                    10 => 'bVII',
                );
                if ( isset( $chromatic_map[ $dom_interval ] ) ) {
                    $new_token = $chromatic_map[ $dom_interval ] . '7';
                }
                // else: leave the o7 token as-is (unresolvable)
            }

            if ( $new_token !== null ) {
                $numerals[ $i ] = $new_token;

                // Back-propagate: rewrite any preceding consecutive identical
                // dim7 tokens (same chord held over multiple bars). Without
                // this, the repeated-chord guard skips earlier copies, leaving
                // them unrewritten. After dedup they'd appear as a separate
                // token (e.g. bIIo7, VI7 instead of VI7, VI7) and break
                // pattern matching.
                for ( $j = $i - 1; $j >= 0; $j-- ) {
                    if ( $numerals[ $j ] === $original_token && $chord_roots[ $j ] === $dim_root ) {
                        $numerals[ $j ] = $new_token;
                    } else {
                        break;
                    }
                }
            }
        }

        return $numerals;
    }

    // =========================================================================
    // DETECTION ENGINE
    // =========================================================================

    /**
     * Parse a stored numeral sequence string into an array of tokens.
     *
     * @param  string $numerals_str  e.g. "IIm7,V7,Imaj7"
     * @return array                 e.g. ["IIm7", "V7", "Imaj7"]
     */
    private static function parse_numeral_sequence( $numerals_str ) {
        $tokens = array_values( array_filter( array_map( 'trim', explode( ',', $numerals_str ) ) ) );
        // Normalize diminished-7th suffix variants so library entries typed as
        // "VIIdim7" or "VII°7" match the "VIIo7" tokens the algorithm produces.
        return array_map( function( $t ) {
            $t = str_replace( '°7', 'o7', $t );   // degree symbol variant
            $t = preg_replace( '/dim7$/i', 'o7', $t ); // "dim7" suffix → "o7"
            return $t;
        }, $tokens );
    }

    /**
     * Compare a single numeral token from the section against a pattern token.
     * Returns a confidence score (0 = no match, 1 = exact, 0.85 = quality-relaxed).
     *
     * @param  string $section_token   e.g. "IIm7"
     * @param  string $pattern_token   e.g. "IIm7" or "IIm"
     * @param  bool   $degree_only     If true, match on Roman numeral degree only,
     *                                  ignoring chord quality (for quality-agnostic
     *                                  progressions like bVI–V–I).
     * @param  bool   $flex_tonic      If true, this specific token is a tonic (I) chord
     *                                  and should match across major/minor families
     *                                  (e.g. Imaj7 pattern matches Im7 in section).
     * @return float
     */
    private static function token_score( $section_token, $pattern_token, $degree_only = false, $flex_tonic = false ) {
        // Exact match
        if ( $section_token === $pattern_token ) {
            return 1.0;
        }

        // Unknown degree
        if ( $section_token === '?' ) {
            return 0.0;
        }

        // Extract Roman numeral base from each token
        // Pattern: optional b/#, then Roman numerals (I,II,...,VII), then quality suffix
        $roman_re = '/^(b?#?)([IVi]+)(.*)/';

        if ( ! preg_match( $roman_re, $section_token, $sm ) || ! preg_match( $roman_re, $pattern_token, $pm ) ) {
            return 0.0;
        }

        // Accidental + numeral must match
        if ( strtoupper( $sm[1] . $sm[2] ) !== strtoupper( $pm[1] . $pm[2] ) ) {
            return 0.0;
        }

        // ── Degree-only mode ────────────────────────────────────────────────
        // For quality-agnostic progressions (e.g. bVI–V–I), any chord quality
        // on the correct degree is a match. Score 0.8 to indicate degree match
        // but not quality-confirmed.
        if ( $degree_only ) {
            return ( $section_token === $pattern_token ) ? 1.0 : 0.8;
        }

        // Same root — compare quality suffixes
        $sq = strtolower( $sm[3] );
        $pq = strtolower( $pm[3] );

        // Quality-relaxed matching: allow minor variations within the same
        // harmonic family. e.g. IIm matches IIm7, Im matches Im6, etc.
        //
        // CRITICAL: naive prefix matching fails because 'maj7' starts with 'm',
        // making Imaj7 falsely match Im (minor). We must ensure 'm' (minor)
        // never matches 'maj' (major) family suffixes.
        //
        // Strategy: normalize both suffixes to a harmonic family tag, then
        // allow relaxed matching only within the same family.
        $family_map = array(
            ''      => 'major',     // bare triad = major
            'maj7'  => 'major',
            'maj9'  => 'major',
            '6'     => 'major',     // already normalized to maj7, but just in case
            '7'     => 'dom',
            '9'     => 'dom',
            '13'    => 'dom',
            'm'     => 'minor',
            'm7'    => 'minor',
            'm6'    => 'minor',
            'm9'    => 'minor',
            'mmaj7' => 'minor',     // minor-major 7th is still minor family
            'm7b5'  => 'halfdim',
            'o7'    => 'dim',
            'o'     => 'dim',
            'sus4'  => 'sus',
            'sus2'  => 'sus',
            'aug'   => 'aug',
            'aug7'  => 'aug',
        );

        // Determine family: try exact match first, then prefix match within the map
        $sq_family = $family_map[ $sq ] ?? null;
        $pq_family = $family_map[ $pq ] ?? null;

        // For unknown suffixes, derive family from the leading character(s)
        if ( $sq_family === null ) {
            if ( strpos( $sq, 'maj' ) === 0 )      $sq_family = 'major';
            elseif ( strpos( $sq, 'mmaj' ) === 0 )  $sq_family = 'minor';
            elseif ( strpos( $sq, 'm7b5' ) === 0 )  $sq_family = 'halfdim';
            elseif ( strpos( $sq, 'm' ) === 0 )     $sq_family = 'minor';
            elseif ( strpos( $sq, 'o' ) === 0 )     $sq_family = 'dim';
            elseif ( preg_match( '/^\d/', $sq ) )    $sq_family = 'dom';
            else                                     $sq_family = 'other';
        }
        if ( $pq_family === null ) {
            if ( strpos( $pq, 'maj' ) === 0 )      $pq_family = 'major';
            elseif ( strpos( $pq, 'mmaj' ) === 0 )  $pq_family = 'minor';
            elseif ( strpos( $pq, 'm7b5' ) === 0 )  $pq_family = 'halfdim';
            elseif ( strpos( $pq, 'm' ) === 0 )     $pq_family = 'minor';
            elseif ( strpos( $pq, 'o' ) === 0 )     $pq_family = 'dim';
            elseif ( preg_match( '/^\d/', $pq ) )    $pq_family = 'dom';
            else                                     $pq_family = 'other';
        }

        // Different families → normally no match (Im ≠ Imaj7, IIm7 ≠ II7, etc.)
        if ( $sq_family !== $pq_family ) {
            // ── Tonic flex ──────────────────────────────────────────────────
            // When this token is flagged as a tonic (I chord) and the
            // progression has tonality='both', allow the tonic to match
            // across major/minor/dom families. This reflects the musical
            // reality that II7–V7–I resolves to major (Imaj7) or minor (Im)
            // depending on context, while the other chords keep their identity.
            // Score 0.8 to indicate "correct degree, flexible quality".
            if ( $flex_tonic ) {
                // Allow major ↔ minor ↔ dom on tonic (covers Imaj7, Im7, I7, Im, I)
                $tonic_families = array( 'major', 'minor', 'dom' );
                if ( in_array( $sq_family, $tonic_families, true ) && in_array( $pq_family, $tonic_families, true ) ) {
                    return 0.8;
                }
            }
            return 0.0;
        }

        // Same family — allow relaxed match (e.g. Im vs Im7, I vs Imaj7, V7 vs V9)
        if ( $sq !== $pq ) {
            return 0.85;
        }

        return 1.0; // identical suffix (already caught by exact match above, but safety)
    }

    /**
     * Run a sliding-window match of the stored progressions against
     * a section's numeral sequence.
     *
     * @param  array  $section_numerals  Flat array of numeral tokens
     * @param  array  $progressions      All rows from the progressions table
     * @param  int    $min_confidence    Minimum confidence to store (0–1)
     * @return array  Array of match arrays:
     *                  [ progression_id, start_idx, length, confidence ]
     */
    private static function detect_matches( $section_numerals, $progressions, $min_confidence = 0.75 ) {
        $matches = array();
        $total   = count( $section_numerals );

        // ── Deduplication ────────────────────────────────────────────────────
        // Collapse runs of the same numeral (e.g. IIm7, IIm7, V7, V7, Imaj7
        // becomes IIm7, V7, Imaj7) so that progressions where chords are held
        // for multiple bars are detected correctly.
        // $slot_map[i] = first original slot index for deduplicated token i.
        // $slot_end_map[i] = last original slot index for deduplicated token i
        //   (used to compute accurate length_measures spanning multi-bar chords).
        $deduped    = array();
        $slot_map   = array();   // dedup idx → first original slot
        $slot_end_map = array(); // dedup idx → last original slot

        foreach ( $section_numerals as $orig_idx => $numeral ) {
            $last = count( $deduped ) - 1;
            if ( $last >= 0 && $deduped[ $last ] === $numeral ) {
                // Extend the last deduplicated token to cover this slot too
                $slot_end_map[ $last ] = $orig_idx;
            } else {
                $deduped[]      = $numeral;
                $slot_map[]     = $orig_idx;
                $slot_end_map[] = $orig_idx;
            }
        }

        $dedup_total = count( $deduped );

        foreach ( $progressions as $prog ) {
            $pattern = self::parse_numeral_sequence( $prog->numerals );

            // Determine match mode for this progression
            $degree_only = ( ( $prog->match_mode ?? 'strict' ) === 'degree' );

            // Determine if this progression allows tonic quality flex.
            // When tonality='both', the I chord (tonic) can match across
            // major/minor families — e.g. Imaj7 pattern matches Im7 in
            // the section, or vice versa. This reflects the musical reality
            // that the tonic is the chord whose quality changes between modes.
            $flex_tonic = ( ( $prog->tonality ?? 'both' ) === 'both' );

            // Deduplicate the pattern too — stored progressions may have consecutive
            // duplicate numerals (e.g. "Imaj7,Imaj7,II7,II7,IIm7,V7,Imaj7") to
            // indicate a chord held for multiple bars. Match on the unique sequence.
            $pattern_deduped = array();
            foreach ( $pattern as $tok ) {
                $last_p = count( $pattern_deduped ) - 1;
                if ( $last_p < 0 || $pattern_deduped[ $last_p ] !== $tok ) {
                    $pattern_deduped[] = $tok;
                }
            }
            $plen = count( $pattern_deduped );

            if ( $plen < 2 || $plen > $dedup_total ) {
                continue;
            }

            // Pre-compute which pattern tokens are tonic (degree I, no accidental).
            // These get quality-flex treatment when flex_tonic is enabled.
            $is_tonic = array();
            $tonic_re = '/^I(?:[^IViv]|$)/'; // I followed by non-Roman or end-of-string
            for ( $t = 0; $t < $plen; $t++ ) {
                $is_tonic[ $t ] = $flex_tonic && (bool) preg_match( $tonic_re, $pattern_deduped[ $t ] );
            }

            // Slide the window over the deduplicated sequence
            for ( $start = 0; $start <= $dedup_total - $plen; $start++ ) {
                $score_sum = 0.0;
                $valid     = true;

                for ( $j = 0; $j < $plen; $j++ ) {
                    $ts = self::token_score(
                        $deduped[ $start + $j ],
                        $pattern_deduped[ $j ],
                        $degree_only,
                        $is_tonic[ $j ]
                    );
                    if ( $ts <= 0 ) {
                        $valid = false;
                        break;
                    }
                    $score_sum += $ts;
                }

                if ( ! $valid ) {
                    continue;
                }

                $confidence = $score_sum / $plen;

                if ( $confidence >= $min_confidence ) {
                    // Translate deduplicated indices back to original slot indices.
                    // start_idx = first original slot of the first matched dedup token.
                    // length    = span from that first slot to the last slot of the
                    //             last matched dedup token (inclusive), so multi-bar
                    //             chords are fully covered.
                    $orig_start  = $slot_map[ $start ];
                    $orig_end    = $slot_end_map[ $start + $plen - 1 ];
                    $orig_length = $orig_end - $orig_start + 1;

                    $matches[] = array(
                        'progression_id' => (int) $prog->id,
                        'start_idx'      => $orig_start,
                        'length'         => $orig_length,
                        'confidence'     => round( $confidence, 3 ),
                        'pattern_tokens' => $pattern_deduped,
                    );
                }
            }
        }

        // ── Containment filter ───────────────────────────────────────────────
        // Two-pass suppression of redundant matches:
        //
        // Pass 1 (existing): remove any match whose slot range is fully
        // contained within a longer match.
        //
        // Pass 2 (new): remove any match B that overlaps with a longer
        // match A when B's pattern is a cyclic subsequence of A's pattern.
        // This catches the turnaround case: ii–V–I (IIm7,V7,Imaj7) overlaps
        // with the turnaround (Imaj7,VI7,IIm7,V7) because the ii–V–I
        // straddles two turnaround instances. The ii–V–I's tokens appear
        // in the turnaround's cyclic repetition, so it's suppressed.
        //
        // Sort longest-first / highest-confidence-first.
        usort( $matches, function( $a, $b ) {
            if ( $b['length'] !== $a['length'] ) return $b['length'] - $a['length'];
            return $b['confidence'] <=> $a['confidence'];
        } );

        // Pass 1: full containment
        $accepted   = array();

        foreach ( $matches as $m ) {
            $m_start = $m['start_idx'];
            $m_end   = $m_start + $m['length'] - 1;

            $contained = false;
            foreach ( $accepted as $a ) {
                $a_start = $a['start_idx'];
                $a_end   = $a_start + $a['length'] - 1;
                if ( $m_start >= $a_start && $m_end <= $a_end ) {
                    $contained = true;
                    break;
                }
            }

            if ( ! $contained ) {
                $accepted[] = $m;
            }
        }

        // Pass 2: cyclic subsequence suppression
        // For each shorter match B, check if a longer overlapping match A
        // exists whose pattern cyclically contains B's pattern.
        $final = array();

        foreach ( $accepted as $m ) {
            $m_start   = $m['start_idx'];
            $m_end     = $m_start + $m['length'] - 1;
            $m_tokens  = $m['pattern_tokens'];
            $m_tlen    = count( $m_tokens );
            $suppressed = false;

            foreach ( $accepted as $a ) {
                if ( $a === $m ) continue;

                $a_tokens = $a['pattern_tokens'];
                $a_tlen   = count( $a_tokens );

                // A must be strictly longer (more pattern tokens)
                if ( $a_tlen <= $m_tlen ) continue;

                // Must have overlapping slot ranges
                $a_start = $a['start_idx'];
                $a_end   = $a_start + $a['length'] - 1;
                $overlap = min( $m_end, $a_end ) - max( $m_start, $a_start ) + 1;
                if ( $overlap <= 0 ) continue;

                // Check if B's pattern is a contiguous subsequence of A's
                // pattern repeated cyclically (A+A). This catches cases like
                // IIm7,V7,Imaj7 inside the cyclic Imaj7,VI7,IIm7,V7.
                $cyclic = array_merge( $a_tokens, $a_tokens );
                $found  = false;
                for ( $k = 0; $k <= $a_tlen; $k++ ) { // only need to check up to a_tlen positions
                    $match = true;
                    for ( $t = 0; $t < $m_tlen; $t++ ) {
                        if ( self::token_score( $cyclic[ $k + $t ], $m_tokens[ $t ] ) <= 0 ) {
                            $match = false;
                            break;
                        }
                    }
                    if ( $match ) {
                        $found = true;
                        break;
                    }
                }

                if ( $found ) {
                    $suppressed = true;
                    break;
                }
            }

            if ( ! $suppressed ) {
                $final[] = $m;
            }
        }

        return $final;
    }

    /**
     * Convert a chord-slot index in a section back to a measure index.
     *
     * @param  array $section    Parser section array
     * @param  int   $slot_idx   0-indexed chord slot
     * @return int               Measure index within the section
     */
    private static function slot_to_measure( $section, $slot_idx ) {
        $cursor = 0;
        foreach ( $section['measures'] as $m_idx => $measure ) {
            $count = count( $measure['chords'] );
            if ( $slot_idx < $cursor + $count ) {
                return $m_idx;
            }
            $cursor += $count;
        }
        return max( 0, count( $section['measures'] ) - 1 );
    }

    // =========================================================================
    // PROCESS LEADSHEET (main entry point — called from ajax_save_leadsheet)
    // =========================================================================

    /**
     * Analyse a leadsheet for chord progressions and store results.
     *
     * @param int    $leadsheet_id
     * @param string $shortcode_content
     */
    public function process_leadsheet( $leadsheet_id, $shortcode_content ) {
        if ( ! class_exists( 'SBN_Leadsheet_Parser' ) ) {
            require_once SBN_PLUGIN_DIR . 'inc/leadsheet/parser.php';
        }

        $song = SBN_Leadsheet_Parser::parse( $shortcode_content );
        $key  = $song['key'] ?? 'C';

        // Determine if the song-level key is minor (ends in 'm', e.g. "Am", "Em")
        $song_is_minor = (bool) preg_match( '/m$/i', trim( $key ) );

        // Clear previous results for this leadsheet
        global $wpdb;
        $ot = self::get_occurrences_table();
        $wpdb->delete( $ot, array( 'leadsheet_id' => $leadsheet_id ) );

        if ( empty( $song['sections'] ) ) {
            return;
        }

        // Load all progressions once (include tonality for filtering)
        $pt = self::get_progressions_table();
        $progressions = $wpdb->get_results( "SELECT id, numerals, tonality, match_mode FROM $pt ORDER BY sort_order ASC, id ASC" );

        if ( empty( $progressions ) ) {
            return;
        }

        // ── Build per-section key map ───────────────────────────────────────
        // Each section can override the song key via a tonality="Cm" attribute.
        // This handles modulations: if section B has tonality="Cm", its chords
        // are analysed in Cm instead of the song-level key.
        $section_keys = array();
        foreach ( $song['sections'] as $si => $section ) {
            $sec_key = $key; // default: inherit song key
            if ( ! empty( $section['tonality'] ) ) {
                $sec_key = $section['tonality'];
            }
            $section_keys[ $si ] = $sec_key;
        }

        $section_count = count( $song['sections'] );

        foreach ( $song['sections'] as $si => $section ) {
            $sec_key      = $section_keys[ $si ];
            $sec_is_minor = (bool) preg_match( '/m$/i', trim( $sec_key ) );

            // Filter progressions by tonality
            $filtered_progs = array_filter( $progressions, function( $p ) use ( $sec_is_minor ) {
                $ton = $p->tonality ?? 'both';
                if ( $ton === 'both' ) return true;
                if ( $ton === 'minor' && $sec_is_minor ) return true;
                if ( $ton === 'major' && ! $sec_is_minor ) return true;
                return false;
            } );

            if ( empty( $filtered_progs ) ) {
                continue;
            }

            $numerals = self::section_to_numerals( $section, $sec_key );

            // ── Cross-section cadence detection ─────────────────────────────
            // When a section modulates (has a different key than its neighbor),
            // progressions often straddle the boundary: the ii–V leading into
            // the new key sits at the end of the previous section, while the
            // resolution (I) is at the start of the modulating section.
            //
            // Strategy: prepend up to 4 chords from the END of the previous
            // section (re-analysed in the CURRENT section's key) so that the
            // sliding window can catch cadences that cross the section break.
            // Similarly, append up to 4 chords from the START of the next
            // section (re-analysed in the current section's key) to catch
            // cadences leading out.
            $prepend_len = 0;
            $append_len  = 0;

            if ( $si > 0 && $section_keys[ $si - 1 ] !== $sec_key ) {
                $prev_section = $song['sections'][ $si - 1 ];
                $prev_measures = $prev_section['measures'];
                // Take up to 4 chords from the end of the previous section
                $tail_chords = array();
                $tail_count  = 0;
                for ( $mi = count( $prev_measures ) - 1; $mi >= 0 && $tail_count < 4; $mi-- ) {
                    foreach ( array_reverse( $prev_measures[ $mi ]['chords'] ) as $chord ) {
                        if ( $tail_count >= 4 ) break;
                        array_unshift( $tail_chords, $chord['name'] );
                        $tail_count++;
                    }
                }
                if ( $tail_count > 0 ) {
                    $tail_numerals = array();
                    $tail_roots    = array();
                    foreach ( $tail_chords as $cn ) {
                        $n = self::chord_to_numeral( $cn, $sec_key );
                        $tail_numerals[] = $n !== null ? $n : '?';
                        if ( function_exists( 'sbn_parse_chord_name' ) ) {
                            $p = sbn_parse_chord_name( $cn );
                            $tail_roots[] = ( $p && ! empty( $p['root'] ) )
                                ? ( self::$note_to_semi[ $p['root'] ] ?? null ) : null;
                        } else {
                            $tail_roots[] = null;
                        }
                    }
                    // Prepend to numerals (we'll offset start_idx accordingly)
                    $numerals     = array_merge( $tail_numerals, $numerals );
                    $prepend_len  = $tail_count;
                }
            }

            if ( $si < $section_count - 1 && $section_keys[ $si + 1 ] !== $sec_key ) {
                $next_section = $song['sections'][ $si + 1 ];
                $next_measures = $next_section['measures'];
                // Take up to 4 chords from the start of the next section
                $head_chords = array();
                $head_count  = 0;
                foreach ( $next_measures as $measure ) {
                    foreach ( $measure['chords'] as $chord ) {
                        if ( $head_count >= 4 ) break 2;
                        $head_chords[] = $chord['name'];
                        $head_count++;
                    }
                }
                if ( $head_count > 0 ) {
                    $head_numerals = array();
                    foreach ( $head_chords as $cn ) {
                        $n = self::chord_to_numeral( $cn, $sec_key );
                        $head_numerals[] = $n !== null ? $n : '?';
                    }
                    $numerals    = array_merge( $numerals, $head_numerals );
                    $append_len  = $head_count;
                }
            }

            if ( count( $numerals ) < 2 ) {
                continue;
            }

            $matches = self::detect_matches( $numerals, array_values( $filtered_progs ) );

            foreach ( $matches as $m ) {
                // Adjust start_idx to account for prepended cross-section chords
                $adj_start = $m['start_idx'] - $prepend_len;
                $adj_end   = $adj_start + $m['length'] - 1;

                // Skip matches that fall entirely outside this section's own chords
                $own_len = count( $numerals ) - $prepend_len - $append_len;
                if ( $adj_end < 0 || $adj_start >= $own_len ) {
                    continue;
                }

                // Clamp to this section's bounds for storage
                $store_start = max( 0, $adj_start );

                // Determine the tonic of the match
                $prog_row = null;
                foreach ( $progressions as $p ) {
                    if ( (int) $p->id === $m['progression_id'] ) {
                        $prog_row = $p;
                        break;
                    }
                }

                $detected_root = $sec_key;
                if ( $prog_row ) {
                    $pattern_tokens = self::parse_numeral_sequence( $prog_row->numerals );
                    $i_token_idx    = null;
                    foreach ( $pattern_tokens as $pt_idx => $pt_tok ) {
                        if ( preg_match( '/^I(?!I|V)[^IVi]/i', $pt_tok ) || $pt_tok === 'I' || $pt_tok === 'Imaj7' || $pt_tok === 'Im7' || $pt_tok === 'Im' ) {
                            $i_token_idx = $pt_idx;
                            break;
                        }
                    }
                    if ( $i_token_idx !== null ) {
                        $flat_chords = array();
                        foreach ( $section['measures'] as $measure ) {
                            foreach ( $measure['chords'] as $chord ) {
                                $flat_chords[] = $chord['name'];
                            }
                        }

                        // Walk the original chord array from store_start, counting
                        // distinct chord changes (dedup-equivalent steps) to find
                        // the slot corresponding to i_token_idx within the pattern.
                        // This avoids the coordinate space mismatch between deduped
                        // pattern positions and original chord slots.
                        $section_slot = $store_start;
                        $steps        = 0;
                        $prev_numeral = isset( $flat_chords[ $section_slot ] )
                            ? self::chord_to_numeral( $flat_chords[ $section_slot ], $sec_key )
                            : null;
                        for ( $walk = $store_start + 1; $walk < count( $flat_chords ) && $steps < $i_token_idx; $walk++ ) {
                            $cur_numeral = self::chord_to_numeral( $flat_chords[ $walk ], $sec_key );
                            if ( $cur_numeral !== $prev_numeral ) {
                                $steps++;
                                $section_slot = $walk;
                                $prev_numeral = $cur_numeral;
                            }
                        }

                        if ( isset( $flat_chords[ $section_slot ] ) ) {
                            $parsed_root = sbn_parse_chord_name( $flat_chords[ $section_slot ] );
                            if ( $parsed_root && ! empty( $parsed_root['root'] ) ) {
                                $detected_root = $parsed_root['root'];
                            }
                        }
                    }
                }

                $start_measure  = self::slot_to_measure( $section, $store_start );
                $end_slot       = min( $store_start + $m['length'] - 1, $own_len - 1 );
                $end_measure    = self::slot_to_measure( $section, $end_slot );
                $length_measures = max( 1, $end_measure - $start_measure + 1 );

                $wpdb->insert( $ot, array(
                    'progression_id'  => $m['progression_id'],
                    'leadsheet_id'    => $leadsheet_id,
                    'section_id'      => $section['id'],
                    'start_measure'   => $start_measure,
                    'length_measures' => $length_measures,
                    'detected_root'   => $detected_root,
                    'confidence'      => $m['confidence'],
                ) );
            }
        }
    }

    /**
     * Process ALL leadsheets (batch reprocessing).
     *
     * @return array Summary
     */
    public function process_all_leadsheets() {
        global $wpdb;
        $lt = $wpdb->prefix . 'sbn_leadsheets';
        $sheets = $wpdb->get_results( "SELECT id, shortcode_content FROM $lt" );

        $processed = 0;
        $total_occ  = 0;

        foreach ( $sheets as $sheet ) {
            if ( empty( $sheet->shortcode_content ) ) continue;
            $this->process_leadsheet( (int) $sheet->id, $sheet->shortcode_content );
            $processed++;
        }

        $ot = self::get_occurrences_table();
        $total_occ = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $ot" );

        return array(
            'processed'  => $processed,
            'total_occ'  => $total_occ,
        );
    }

    // =========================================================================
    // QUERY METHODS (for frontend / editor integration)
    // =========================================================================

    /**
     * Get all occurrences for a leadsheet, grouped by section.
     * Returns array keyed by section_id; each value is an array of occurrence
     * rows with progression data joined in.
     *
     * @param  int   $leadsheet_id
     * @return array
     */
    /**
     * Get all progressions with their song-count for the public library page.
     * Returns progressions ordered by the requested sort, optionally filtered by category.
     *
     * @param  string $sort     'name'|'category'|'popularity'
     * @param  string $category Filter by category slug, or '' for all
     * @return array
     */
    /**
     * Determine whether a chord quality matches a single Roman-numeral token
     * from a stored progression, and return a confidence score.
     *
     * This is the shared matching kernel used by both the chord detail page
     * (quality → find progressions) and can be reused by the detection pipeline.
     *
     * Match tiers:
     *   1.00 — exact quality match          e.g. quality=maj7, token=Imaj7
     *   0.90 — same harmonic family         e.g. quality=maj9, token=Imaj7  (extension of same family)
     *   0.75 — tonic flex across major/minor e.g. quality=m7, token=Imaj7  (only when $flex_tonic=true)
     *   0.00 — different family / no match
     *
     * Reuses the $family_map logic from token_score() to ensure consistency.
     *
     * @param  string $quality     Raw chord quality, e.g. 'maj7', 'maj9', 'm7', '7', 'm7b5'
     * @param  string $numeral_token  A single stored token, e.g. 'Imaj7', 'IIm7', 'V7'
     * @param  bool   $flex_tonic  Allow major↔minor on I-chord slots (for tonality=both progressions)
     * @return float  0.0 = no match, 1.0 = exact
     */
    public static function quality_matches_numeral_token( $quality, $numeral_token, $flex_tonic = false ) {
        // Normalise input quality to lowercase
        $q = strtolower( trim( $quality ) );

        // Extract the quality suffix from the numeral token (strip the Roman numeral + accidental)
        // e.g. 'IIm7' → 'm7',  'bVII7' → '7',  'Imaj7' → 'maj7',  'V7' → '7'
        if ( ! preg_match( '/^(b?#?[IVi]+)(.*)$/', $numeral_token, $m ) ) {
            return 0.0;
        }
        $token_suffix = strtolower( $m[2] ); // e.g. 'maj7', 'm7', '7', ''

        // Normalise $q to a canonical base quality (same logic as degree_to_numeral)
        $q_norm = $q;
        if ( ! in_array( $q, array( 'maj7', 'maj', 'dom7', '7', 'm7', 'min7', '-7',
            'min', 'm', 'minor', '-', 'm7b5', 'o7', 'dim7', 'dim', 'mmaj7',
            'maj6', '6', 'm6', 'sus4', 'sus2', 'aug', 'aug7' ), true ) ) {
            if      ( preg_match( '/^m7b5/',  $q ) ) $q_norm = 'm7b5';
            elseif  ( preg_match( '/^mmaj7/', $q ) ) $q_norm = 'mmaj7';
            elseif  ( preg_match( '/^maj/',   $q ) ) $q_norm = 'maj7';   // maj9, maj11, maj13 → maj7 family
            elseif  ( preg_match( '/^m/',     $q ) ) $q_norm = 'm7';     // m9, m11, m13 → m7 family
            elseif  ( preg_match( '/^-/',     $q ) ) $q_norm = 'm7';     // jazz minus notation
            elseif  ( preg_match( '/^\d/',    $q ) ) $q_norm = '7';      // 9, 11, 13 → dom7 family
        }
        // Further aliases
        if ( in_array( $q_norm, array( 'min7', '-7' ), true ) )  $q_norm = 'm7';
        if ( in_array( $q_norm, array( 'min', 'minor', '-' ), true ) ) $q_norm = 'm';
        if ( $q_norm === 'dom7' ) $q_norm = '7';
        if ( $q_norm === 'dim7' ) $q_norm = 'o7';
        if ( in_array( $q_norm, array( 'maj6', '6' ), true ) )   $q_norm = 'maj7'; // functionally equivalent

        // ── Exact suffix match ───────────────────────────────────────────────
        if ( $q_norm === $token_suffix ) {
            return 1.0;
        }

        // ── Family map (mirrors token_score) ────────────────────────────────
        $family_map = array(
            ''      => 'major',
            'maj7'  => 'major',
            'maj9'  => 'major',
            '6'     => 'major',
            '7'     => 'dom',
            '9'     => 'dom',
            '13'    => 'dom',
            'm'     => 'minor',
            'm7'    => 'minor',
            'm6'    => 'minor',
            'm9'    => 'minor',
            'mmaj7' => 'minor',
            'm7b5'  => 'halfdim',
            'o7'    => 'dim',
            'o'     => 'dim',
            'sus4'  => 'sus',
            'sus2'  => 'sus',
            'aug'   => 'aug',
            'aug7'  => 'aug',
        );

        $resolve_family = function( $suffix ) use ( $family_map ) {
            if ( isset( $family_map[ $suffix ] ) ) return $family_map[ $suffix ];
            if ( strpos( $suffix, 'mmaj' ) === 0 ) return 'minor';
            if ( strpos( $suffix, 'maj' )  === 0 ) return 'major';
            if ( strpos( $suffix, 'm7b5' ) === 0 ) return 'halfdim';
            if ( strpos( $suffix, 'm' )    === 0 ) return 'minor';
            if ( strpos( $suffix, 'o' )    === 0 ) return 'dim';
            if ( preg_match( '/^\d/', $suffix ) )  return 'dom';
            return 'other';
        };

        $q_family     = $resolve_family( $q_norm );
        $token_family = $resolve_family( $token_suffix );

        // ── Same family → extension/reduction match ──────────────────────────
        if ( $q_family === $token_family ) {
            return 0.90; // e.g. maj9 matches Imaj7 slot, m9 matches IIm7 slot
        }

        // ── Tonic flex: major ↔ minor ↔ dom on I-chord slots ────────────────
        if ( $flex_tonic ) {
            $tonic_families = array( 'major', 'minor', 'dom' );
            if ( in_array( $q_family, $tonic_families, true ) && in_array( $token_family, $tonic_families, true ) ) {
                return 0.75;
            }
        }

        return 0.0;
    }

    /**
     * Find the best progression to showcase a given chord quality on the chord detail page.
     *
     * Ranking priority (descending):
     *   1. featured = 1  (manually curated canonical examples)
     *   2. Best slot score from quality_matches_numeral_token()
     *   3. Occurrence count (most songs using the progression)
     *
     * Returns a result array or null if nothing suitable is found:
     * {
     *   progression:  (object) full progression row,
     *   best_slot:    (int)    0-based index of the token that matched the quality,
     *   match_score:  (float)  confidence of the quality match,
     *   song_count:   (int)    number of songs the progression appears in,
     *   default_key:  (string) 'C' unless a specific diagram root was passed,
     * }
     *
     * @param  string      $quality      Chord quality, e.g. 'maj7', 'm7', '7'
     * @param  string|null $diagram_root Note name if a specific diagram is selected, e.g. 'F'
     * @return array|null
     */
    public static function get_progression_for_quality( $quality, $diagram_root = null ) {
        global $wpdb;
        $pt = self::get_progressions_table();
        $ot = self::get_occurrences_table();

        // Fetch all progressions with their occurrence counts
        $rows = $wpdb->get_results( "
            SELECT p.*,
                   COUNT( DISTINCT o.leadsheet_id ) AS song_count
            FROM   $pt p
            LEFT JOIN $ot o ON o.progression_id = p.id
            GROUP BY p.id
            ORDER BY p.featured DESC, song_count DESC
        " );

        if ( empty( $rows ) ) {
            return null;
        }

        $best            = null;
        $best_score      = -1.0;
        $best_slot       = 0;
        $best_song_count = 0;

        foreach ( $rows as $prog ) {
            $tokens = self::parse_numeral_sequence( $prog->numerals );
            if ( empty( $tokens ) ) continue;

            // Determine whether this progression uses tonic flex
            // (tonality = 'both' means the I chord can flex major/minor)
            $flex_tonic = ( isset( $prog->tonality ) && $prog->tonality === 'both' );

            $slot_best_score = 0.0;
            $slot_best_idx   = 0;

            foreach ( $tokens as $idx => $token ) {
                // Is this a tonic (I) slot?
                $is_tonic = (bool) preg_match( '/^(b?#?I)([^IVi]|$)/', $token );
                $score    = self::quality_matches_numeral_token(
                    $quality,
                    $token,
                    $flex_tonic && $is_tonic
                );
                if ( $score > $slot_best_score ) {
                    $slot_best_score = $score;
                    $slot_best_idx   = $idx;
                }
            }

            if ( $slot_best_score === 0.0 ) continue;

            // Composite ranking: featured first, then match score, then song count
            $song_count    = intval( $prog->song_count );
            $is_featured   = ! empty( $prog->featured ) ? 1 : 0;
            $composite     = ( $is_featured * 1000 ) + ( $slot_best_score * 100 ) + min( $song_count, 99 );

            if ( $composite > $best_score ) {
                $best_score      = $composite;
                $best            = $prog;
                $best_slot       = $slot_best_idx;
                $best_song_count = $song_count;
            }
        }

        if ( ! $best ) {
            return null;
        }

        return array(
            'progression'  => $best,
            'best_slot'    => $best_slot,
            'match_score'  => $best_score,
            'song_count'   => $best_song_count,
            'default_key'  => $diagram_root ?? 'C',
        );
    }

    public function get_all_progressions_with_counts( $sort = 'name', $category = '' ) {
        global $wpdb;
        $pt = self::get_progressions_table();
        $ot = self::get_occurrences_table();

        $where  = '';
        $values = array();
        if ( $category !== '' ) {
            $where    = 'WHERE p.category = %s';
            $values[] = $category;
        }

        $order_map = array(
            'name'       => 'p.name ASC',
            'category'   => 'p.category ASC, p.sort_order ASC',
            'popularity' => 'song_count DESC, p.name ASC',
        );
        $order = isset( $order_map[$sort] ) ? $order_map[$sort] : 'p.name ASC';

        $sql = "SELECT p.*,
                       COUNT(DISTINCT o.leadsheet_id) AS song_count
                FROM $pt p
                LEFT JOIN $ot o ON p.id = o.progression_id
                $where
                GROUP BY p.id
                ORDER BY $order";

        if ( $values ) {
            return $wpdb->get_results( $wpdb->prepare( $sql, $values ) );
        }
        return $wpdb->get_results( $sql );
    }

    public function get_occurrences_for_leadsheet( $leadsheet_id ) {
        global $wpdb;
        $ot = self::get_occurrences_table();
        $pt = self::get_progressions_table();

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT o.*, p.name, p.numerals, p.category, p.description
             FROM $ot o
             INNER JOIN $pt p ON o.progression_id = p.id
             WHERE o.leadsheet_id = %d
             ORDER BY o.section_id ASC, o.start_measure ASC, o.confidence DESC",
            $leadsheet_id
        ) );

        $grouped = array();
        foreach ( $rows as $row ) {
            $grouped[ $row->section_id ][] = $row;
        }
        return $grouped;
    }

    /**
     * Get all leadsheets that contain a specific progression.
     *
     * @param  int   $progression_id
     * @return array
     */
    public function get_leadsheets_for_progression( $progression_id ) {
        global $wpdb;
        $ot = self::get_occurrences_table();
        $lt = $wpdb->prefix . 'sbn_leadsheets';

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT DISTINCT o.leadsheet_id, l.title, o.section_id, o.detected_root, o.confidence
             FROM $ot o
             INNER JOIN $lt l ON o.leadsheet_id = l.id
             WHERE o.progression_id = %d
             ORDER BY l.title ASC",
            $progression_id
        ) );
    }

    /**
     * Get a single progression by ID.
     *
     * @param  int   $id
     * @return object|null
     */
    public function get_progression_by_id( $id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::get_progressions_table() . " WHERE id = %d",
            intval( $id )
        ) );
    }

    /**
     * Get overall stats for the admin dashboard.
     *
     * @return array
     */
    public function get_stats() {
        global $wpdb;
        $pt = self::get_progressions_table();
        $ot = self::get_occurrences_table();
        $lt = $wpdb->prefix . 'sbn_leadsheets';

        return array(
            'total_progressions'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $pt" ),
            'total_occurrences'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $ot" ),
            'leadsheets_with_matches'=> (int) $wpdb->get_var( "SELECT COUNT(DISTINCT leadsheet_id) FROM $ot" ),
            'total_leadsheets'       => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $lt" ),
            'most_common'            => $wpdb->get_results(
                "SELECT p.id, p.name, p.category, p.numerals, COUNT(o.id) as occurrence_count
                 FROM $pt p
                 INNER JOIN $ot o ON p.id = o.progression_id
                 GROUP BY p.id
                 ORDER BY occurrence_count DESC
                 LIMIT 10"
            ),
        );
    }

    // =========================================================================
    // ADMIN MENU
    // =========================================================================

    public function add_admin_menu() {
        add_submenu_page(
            'sbn-courses',
            'Chord Progressions',
            'Chord Progressions',
            'manage_options',
            'sbn-chord-progressions',
            array( $this, 'render_admin_page' )
        );
    }

    // =========================================================================
    // ADMIN ASSETS
    // =========================================================================

    public function enqueue_admin_assets( $hook ) {
        if ( strpos( $hook, 'sbn-chord-progressions' ) === false ) {
            return;
        }

        wp_enqueue_style(
            'sbn-chord-progressions-admin',
            SBN_PLUGIN_URL . 'assets/css/chord-progressions-admin.css',
            array(),
            self::VERSION
        );

        wp_enqueue_script(
            'sbn-chord-progressions-admin',
            SBN_PLUGIN_URL . 'assets/js/chord-progressions-admin.js',
            array( 'jquery' ),
            self::VERSION,
            true
        );

        wp_localize_script( 'sbn-chord-progressions-admin', 'sbnProgressions', array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'sbn_chord_progressions_nonce' ),
        ) );
    }

    // =========================================================================
    // ADMIN PAGE RENDER
    // =========================================================================

    public function render_admin_page() {
        $tab   = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : 'progressions';
        $stats = $this->get_stats();

        // Handle edit/new form
        $editing   = false;
        $edit_data = null;
        if ( isset( $_GET['action'] ) && in_array( $_GET['action'], array( 'edit', 'new' ) ) ) {
            $editing = true;
            if ( $_GET['action'] === 'edit' && ! empty( $_GET['id'] ) ) {
                global $wpdb;
                $edit_data = $wpdb->get_row( $wpdb->prepare(
                    "SELECT * FROM " . self::get_progressions_table() . " WHERE id = %d",
                    intval( $_GET['id'] )
                ) );
            }
        }
        ?>
        <div class="wrap sbn-admin sbn-progressions-admin">
            <h1 class="wp-heading-inline">Chord Progressions</h1>
            <?php if ( ! $editing ): ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=sbn-chord-progressions&action=new' ) ); ?>" class="page-title-action">Add New</a>
            <?php endif; ?>

            <?php if ( $editing ): ?>
                <?php $this->render_edit_form( $edit_data ); ?>
            <?php else: ?>

            <nav class="nav-tab-wrapper">
                <a href="?page=sbn-chord-progressions&tab=progressions"
                   class="nav-tab <?php echo $tab === 'progressions' ? 'nav-tab-active' : ''; ?>">Library</a>
                <a href="?page=sbn-chord-progressions&tab=occurrences"
                   class="nav-tab <?php echo $tab === 'occurrences' ? 'nav-tab-active' : ''; ?>">
                    Occurrences
                    <?php if ( $stats['total_occurrences'] > 0 ): ?>
                        <span class="sbn-badge"><?php echo $stats['total_occurrences']; ?></span>
                    <?php endif; ?>
                </a>
                <a href="?page=sbn-chord-progressions&tab=reprocess"
                   class="nav-tab <?php echo $tab === 'reprocess' ? 'nav-tab-active' : ''; ?>">Reprocess</a>
            </nav>

            <div class="sbn-progressions-content">
                <?php
                switch ( $tab ) {
                    case 'occurrences': $this->render_occurrences_tab(); break;
                    case 'reprocess':   $this->render_reprocess_tab( $stats ); break;
                    default:            $this->render_library_tab( $stats ); break;
                }
                ?>
            </div>

            <?php endif; ?>
        </div>
        <?php
    }

    // ─── Library tab ────────────────────────────────────────────────────────

    private function render_library_tab( $stats ) {
        global $wpdb;
        $pt = self::get_progressions_table();

        $filter_cat = isset( $_GET['cat'] ) ? sanitize_text_field( $_GET['cat'] ) : '';
        $where      = $filter_cat ? $wpdb->prepare( "WHERE category = %s", $filter_cat ) : '';

        $progressions = $wpdb->get_results(
            "SELECT p.*, COUNT(o.id) as occurrence_count
             FROM $pt p
             LEFT JOIN " . self::get_occurrences_table() . " o ON p.id = o.progression_id
             $where
             GROUP BY p.id
             ORDER BY p.sort_order ASC, p.category ASC, p.name ASC"
        );

        $categories = $wpdb->get_col( "SELECT DISTINCT category FROM $pt ORDER BY category ASC" );

        ?>
        <div class="sbn-library-header">
            <div class="sbn-filter-bar">
                <a href="?page=sbn-chord-progressions" class="<?php echo empty($filter_cat) ? 'active' : ''; ?>">All</a>
                <?php foreach ( $categories as $cat ): ?>
                    <a href="?page=sbn-chord-progressions&cat=<?php echo esc_attr($cat); ?>"
                       class="<?php echo $filter_cat === $cat ? 'active' : ''; ?>">
                        <?php echo esc_html( ucfirst($cat) ); ?>
                    </a>
                <?php endforeach; ?>
            </div>
            <span class="sbn-prog-count"><?php echo count($progressions); ?> progressions</span>
        </div>

        <?php if ( empty($progressions) ): ?>
            <div class="sbn-empty-state">
                <div class="sbn-empty-icon">🎼</div>
                <h2>No progressions yet</h2>
                <p>Add a progression to get started, or reprocess your leadsheets to detect matches.</p>
            </div>
        <?php else: ?>
        <table class="wp-list-table widefat fixed striped sbn-prog-table">
            <thead>
                <tr>
                    <th class="col-name">Name</th>
                    <th class="col-cat">Category</th>
                    <th class="col-numerals">Numerals</th>
                    <th class="col-occ">Songs</th>
                    <th class="col-actions">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $progressions as $prog ): ?>
                <tr data-id="<?php echo esc_attr($prog->id); ?>">
                    <td class="col-name">
                        <strong><?php echo esc_html($prog->name); ?></strong>
                        <?php if ($prog->description): ?>
                            <p class="sbn-prog-desc"><?php echo esc_html( wp_trim_words($prog->description, 18) ); ?></p>
                        <?php endif; ?>
                    </td>
                    <td class="col-cat">
                        <span class="sbn-cat-badge sbn-cat-<?php echo esc_attr($prog->category); ?>">
                            <?php echo esc_html($prog->category); ?>
                        </span>
                        <?php if ( ! empty( $prog->tonality ) && $prog->tonality !== 'both' ): ?>
                            <span class="sbn-tonality-badge sbn-tonality-<?php echo esc_attr($prog->tonality); ?>">
                                <?php echo esc_html($prog->tonality); ?>
                            </span>
                        <?php endif; ?>
                        <?php if ( ! empty( $prog->match_mode ) && $prog->match_mode === 'degree' ): ?>
                            <span class="sbn-tonality-badge sbn-match-degree" title="Matches on degree only, any chord quality">degree</span>
                        <?php endif; ?>
                    </td>
                    <td class="col-numerals">
                        <code class="sbn-numerals"><?php echo esc_html( str_replace(',', ' – ', $prog->numerals) ); ?></code>
                    </td>
                    <td class="col-occ">
                        <?php if ($prog->occurrence_count > 0): ?>
                            <span class="sbn-occ-count"><?php echo (int)$prog->occurrence_count; ?></span>
                        <?php else: ?>
                            <span class="sbn-occ-none">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="col-actions">
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=sbn-chord-progressions&action=edit&id=' . $prog->id ) ); ?>"
                           class="button button-small">Edit</a>
                        <button class="button button-small sbn-delete-progression"
                                data-id="<?php echo esc_attr($prog->id); ?>"
                                data-name="<?php echo esc_attr($prog->name); ?>">Delete</button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
        <?php
    }

    // ─── Edit / New form ────────────────────────────────────────────────────

    private function render_edit_form( $data = null ) {
        $is_new    = ( $data === null );
        $id        = $is_new ? 0 : (int) $data->id;
        $name      = $is_new ? '' : $data->name;
        $category  = $is_new ? 'jazz' : $data->category;
        $numerals  = $is_new ? '' : $data->numerals;
        $desc      = $is_new ? '' : $data->description;
        $genres    = $is_new ? '' : $data->typical_genres;
        $tags      = $is_new ? '' : ( $data->tags ?? '' );
        $tonality   = $is_new ? 'both' : ( $data->tonality ?? 'both' );
        $match_mode = $is_new ? 'strict' : ( $data->match_mode ?? 'strict' );
        $sort       = $is_new ? 0 : (int) $data->sort_order;

        $categories = array( 'jazz', 'blues', 'pop', 'modal', 'classical', 'latin', 'other' );

        // Preset tag vocabulary — edit this list as needed
        $preset_tags = array(
            'secondary dominant',
            'tritone substitution',
            'minor subdominant',
            'diminished',
            'chromatic',
            'turnaround',
            'cadence',
            'deceptive cadence',
            'modal interchange',
            'coltrane changes',
            'rhythm changes',
            'pedal point',
            'cycle of fifths',
            'ascending bass',
            'descending bass',
            'backdoor dominant',
            'half cadence',
            'blues',
            'bossa nova',
        );
        sort( $preset_tags );

        // Parse currently saved tags into an array for badge highlighting
        $saved_tags = array_filter( array_map( 'trim', explode( ',', $tags ) ) );
        ?>
        <p><a href="<?php echo esc_url(admin_url('admin.php?page=sbn-chord-progressions')); ?>">&larr; Back to Library</a></p>

        <div class="sbn-edit-form">
            <h2><?php echo $is_new ? 'Add New Progression' : 'Edit Progression'; ?></h2>

            <form id="sbnProgressionForm" class="sbn-form">
                <input type="hidden" name="id" value="<?php echo esc_attr($id); ?>">

                <table class="form-table">
                    <tr>
                        <th><label for="prog_name">Name</label></th>
                        <td>
                            <input type="text" id="prog_name" name="name"
                                   value="<?php echo esc_attr($name); ?>"
                                   class="regular-text" placeholder="e.g. ii–V–I (major)" required>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="prog_category">Category</label></th>
                        <td>
                            <select id="prog_category" name="category">
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?php echo esc_attr($cat); ?>" <?php selected($category, $cat); ?>>
                                        <?php echo esc_html(ucfirst($cat)); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="prog_tonality">Tonality</label></th>
                        <td>
                            <select id="prog_tonality" name="tonality">
                                <option value="both" <?php selected($tonality, 'both'); ?>>Both (major &amp; minor)</option>
                                <option value="major" <?php selected($tonality, 'major'); ?>>Major only</option>
                                <option value="minor" <?php selected($tonality, 'minor'); ?>>Minor only</option>
                            </select>
                            <p class="description">Restrict this progression to songs in a specific tonality. "Both" matches regardless of key mode.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="prog_match_mode">Match Mode</label></th>
                        <td>
                            <select id="prog_match_mode" name="match_mode">
                                <option value="strict" <?php selected($match_mode, 'strict'); ?>>Strict (quality must match)</option>
                                <option value="degree" <?php selected($match_mode, 'degree'); ?>>Degree only (any quality)</option>
                            </select>
                            <p class="description">
                                <strong>Strict:</strong> chord qualities must match within the same family (e.g. IIm7 matches IIm but not II7).<br>
                                <strong>Degree only:</strong> matches on the Roman numeral degree regardless of quality — use for progressions like
                                <code>bVI,V,I</code> where the quality varies (Eb7→D7→G, Abmaj7→G7→Cm, etc).
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="prog_numerals">Numeral Sequence</label></th>
                        <td>
                            <input type="text" id="prog_numerals" name="numerals"
                                   value="<?php echo esc_attr($numerals); ?>"
                                   class="large-text sbn-numerals-input"
                                   placeholder="e.g. IIm7,V7,Imaj7">
                            <p class="description">
                                Comma-separated Roman numeral tokens. Use uppercase for major, lowercase prefix
                                for minor (e.g. <code>IIm7,V7,Imaj7</code>). Chromatic alterations: <code>bVII7</code>, <code>bII</code>.
                            </p>
                            <div id="sbnNumeralPreview" class="sbn-numeral-preview"></div>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="prog_description">Description</label></th>
                        <td>
                            <textarea id="prog_description" name="description"
                                      rows="4" class="large-text"><?php echo esc_textarea($desc); ?></textarea>
                            <p class="description">Educational explanation: what this progression sounds like, where it comes from, how to use it.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="prog_genres">Typical Genres</label></th>
                        <td>
                            <input type="text" id="prog_genres" name="typical_genres"
                                   value="<?php echo esc_attr($genres); ?>"
                                   class="regular-text" placeholder="jazz, bossa nova, swing">
                            <p class="description">Comma-separated genre tags shown on the public detail page.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label>Keywords / Tags</label></th>
                        <td>
                            <?php /* Hidden input that holds the comma-separated tag value */ ?>
                            <input type="hidden" id="prog_tags" name="tags"
                                   value="<?php echo esc_attr($tags); ?>">

                            <?php /* Active tags — shown as removable chips */ ?>
                            <div id="sbn-tags-active" class="sbn-tags-active">
                                <?php if ( empty( $saved_tags ) ) : ?>
                                    <span class="sbn-tags-none" id="sbn-tags-none">No tags yet — click below to add</span>
                                <?php else : ?>
                                    <?php foreach ( $saved_tags as $tag ) : ?>
                                        <span class="sbn-tag-active-chip" data-tag="<?php echo esc_attr( $tag ); ?>">
                                            <?php echo esc_html( ucwords( $tag ) ); ?>
                                            <button type="button" class="sbn-tag-remove" aria-label="Remove <?php echo esc_attr($tag); ?>">×</button>
                                        </span>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>

                            <?php /* Preset tag palette */ ?>
                            <p class="sbn-tags-palette-label">Click to add:</p>
                            <div class="sbn-tags-palette" id="sbn-tags-palette">
                                <?php foreach ( $preset_tags as $tag ) : ?>
                                    <button type="button"
                                            class="sbn-tag-preset<?php echo in_array( $tag, $saved_tags ) ? ' sbn-tag-preset--active' : ''; ?>"
                                            data-tag="<?php echo esc_attr( $tag ); ?>">
                                        <?php echo esc_html( ucwords( $tag ) ); ?>
                                    </button>
                                <?php endforeach; ?>
                            </div>

                            <?php /* Custom tag input */ ?>
                            <div class="sbn-tags-custom-row">
                                <input type="text" id="sbn-tag-custom-input" class="regular-text"
                                       placeholder="Custom tag…" style="max-width:220px;">
                                <button type="button" id="sbn-tag-custom-add" class="button">Add</button>
                            </div>
                            <p class="description">Tags appear as filter buttons on the public Progression Library page.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="prog_sort">Sort Order</label></th>
                        <td>
                            <input type="number" id="prog_sort" name="sort_order"
                                   value="<?php echo esc_attr($sort); ?>" class="small-text">
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" class="button button-primary" id="sbnSaveProgression">
                        <?php echo $is_new ? 'Add Progression' : 'Save Changes'; ?>
                    </button>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=sbn-chord-progressions')); ?>"
                       class="button">Cancel</a>
                    <span id="sbnSaveStatus" class="sbn-status-message"></span>
                </p>
            </form>
        </div>
        <?php
    }

    // ─── Occurrences tab ────────────────────────────────────────────────────

    private function render_occurrences_tab() {
        global $wpdb;
        $ot = self::get_occurrences_table();
        $pt = self::get_progressions_table();
        $lt = $wpdb->prefix . 'sbn_leadsheets';

        // Filter controls
        $filter_prog = isset($_GET['prog_id']) ? intval($_GET['prog_id']) : 0;
        $filter_ls   = isset($_GET['ls_id'])   ? intval($_GET['ls_id'])   : 0;

        $where  = array();
        $values = array();
        if ($filter_prog) { $where[] = 'o.progression_id = %d'; $values[] = $filter_prog; }
        if ($filter_ls)   { $where[] = 'o.leadsheet_id = %d';   $values[] = $filter_ls;   }

        $where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $sql = "SELECT o.*, p.name as prog_name, p.numerals, p.category,
                       l.title as leadsheet_title
                FROM $ot o
                INNER JOIN $pt p ON o.progression_id = p.id
                INNER JOIN $lt l ON o.leadsheet_id = l.id
                $where_sql
                ORDER BY l.title ASC, o.section_id ASC, o.start_measure ASC";

        $rows = $values ? $wpdb->get_results($wpdb->prepare($sql, $values)) : $wpdb->get_results($sql);

        // Filter dropdowns
        $all_progs = $wpdb->get_results("SELECT id, name FROM $pt ORDER BY sort_order, name");
        $all_ls    = $wpdb->get_results("SELECT DISTINCT o.leadsheet_id, l.title FROM $ot o INNER JOIN $lt l ON o.leadsheet_id = l.id ORDER BY l.title ASC");
        ?>

        <div class="sbn-occ-filters">
            <form method="get" style="display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
                <input type="hidden" name="page" value="sbn-chord-progressions">
                <input type="hidden" name="tab"  value="occurrences">
                <label>Progression:
                    <select name="prog_id" onchange="this.form.submit()">
                        <option value="">— All —</option>
                        <?php foreach ($all_progs as $p): ?>
                            <option value="<?php echo esc_attr($p->id); ?>" <?php selected($filter_prog, $p->id); ?>>
                                <?php echo esc_html($p->name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Leadsheet:
                    <select name="ls_id" onchange="this.form.submit()">
                        <option value="">— All —</option>
                        <?php foreach ($all_ls as $l): ?>
                            <option value="<?php echo esc_attr($l->leadsheet_id); ?>" <?php selected($filter_ls, $l->leadsheet_id); ?>>
                                <?php echo esc_html($l->title); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <?php if ($filter_prog || $filter_ls): ?>
                    <a href="?page=sbn-chord-progressions&tab=occurrences" class="button">Clear</a>
                <?php endif; ?>
            </form>
        </div>

        <?php if (empty($rows)): ?>
            <div class="sbn-empty-state">
                <div class="sbn-empty-icon">🔍</div>
                <h2>No occurrences found</h2>
                <p>Run <strong>Reprocess All Leadsheets</strong> to detect progressions in your song library.</p>
            </div>
        <?php else: ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>Leadsheet</th>
                    <th>Section</th>
                    <th>Progression</th>
                    <th>Numerals</th>
                    <th>Key / Root</th>
                    <th>Measure</th>
                    <th>Confidence</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                <tr>
                    <td><strong><?php echo esc_html($row->leadsheet_title); ?></strong></td>
                    <td><code><?php echo esc_html($row->section_id); ?></code></td>
                    <td>
                        <span class="sbn-cat-badge sbn-cat-<?php echo esc_attr($row->category); ?>">
                            <?php echo esc_html($row->category); ?>
                        </span>
                        <?php echo esc_html($row->prog_name); ?>
                    </td>
                    <td><code class="sbn-numerals"><?php echo esc_html(str_replace(',', ' – ', $row->numerals)); ?></code></td>
                    <td><?php echo esc_html($row->detected_root); ?></td>
                    <td><?php echo (int)$row->start_measure + 1; ?>
                        <?php if ($row->length_measures > 1): ?>
                            – <?php echo (int)$row->start_measure + (int)$row->length_measures; ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="sbn-confidence-bar">
                            <div class="sbn-confidence-fill" style="width:<?php echo round($row->confidence*100); ?>%"></div>
                        </div>
                        <span class="sbn-confidence-pct"><?php echo round($row->confidence * 100); ?>%</span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
        <?php
    }

    // ─── Reprocess tab ──────────────────────────────────────────────────────

    private function render_reprocess_tab( $stats ) {
        ?>
        <div class="sbn-stats-grid">
            <div class="sbn-stat-card">
                <div class="sbn-stat-number"><?php echo $stats['total_progressions']; ?></div>
                <div class="sbn-stat-label">Progressions in Library</div>
            </div>
            <div class="sbn-stat-card">
                <div class="sbn-stat-number"><?php echo $stats['total_leadsheets']; ?></div>
                <div class="sbn-stat-label">Total Leadsheets</div>
            </div>
            <div class="sbn-stat-card">
                <div class="sbn-stat-number"><?php echo $stats['leadsheets_with_matches']; ?></div>
                <div class="sbn-stat-label">Leadsheets with Matches</div>
            </div>
            <div class="sbn-stat-card sbn-stat-highlight">
                <div class="sbn-stat-number"><?php echo $stats['total_occurrences']; ?></div>
                <div class="sbn-stat-label">Total Occurrences Found</div>
            </div>
        </div>

        <div class="sbn-crossref-actions">
            <h3>Reprocess All Leadsheets</h3>
            <p>Run the progression detector across every leadsheet in your library. This is safe to run repeatedly — previous results are replaced, not accumulated.</p>
            <button class="button button-primary" id="sbnReprocessProgressions">
                Reprocess All Leadsheets
            </button>
            <span id="sbnReprocessStatus" class="sbn-status-message"></span>
        </div>

        <?php if ( ! empty($stats['most_common']) ): ?>
        <div class="sbn-crossref-popular">
            <h3>Most Common Progressions in Your Library</h3>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Progression</th>
                        <th>Category</th>
                        <th>Numerals</th>
                        <th>Appears in</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($stats['most_common'] as $prog): ?>
                    <tr>
                        <td><strong><?php echo esc_html($prog->name); ?></strong></td>
                        <td>
                            <span class="sbn-cat-badge sbn-cat-<?php echo esc_attr($prog->category); ?>">
                                <?php echo esc_html($prog->category); ?>
                            </span>
                        </td>
                        <td><code class="sbn-numerals"><?php echo esc_html(str_replace(',', ' – ', $prog->numerals)); ?></code></td>
                        <td><?php echo (int)$prog->occurrence_count; ?> occurrence<?php echo $prog->occurrence_count != 1 ? 's' : ''; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
        <?php
    }

    // =========================================================================
    // AJAX HANDLERS
    // =========================================================================

    public function ajax_save_progression() {
        check_ajax_referer( 'sbn_chord_progressions_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Permission denied' );

        global $wpdb;
        $pt = self::get_progressions_table();

            $raw_tonality = sanitize_text_field( wp_unslash( $_POST['tonality'] ?? 'both' ) );
            $raw_match_mode = sanitize_text_field( wp_unslash( $_POST['match_mode'] ?? 'strict' ) );
        $data = array(
            'name'           => sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ),
            'category'       => sanitize_text_field( wp_unslash( $_POST['category'] ?? 'jazz' ) ),
            'numerals'       => sanitize_text_field( wp_unslash( $_POST['numerals'] ?? '' ) ),
            'description'    => sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) ),
            'typical_genres' => sanitize_text_field( wp_unslash( $_POST['typical_genres'] ?? '' ) ),
            'tags'           => sanitize_text_field( wp_unslash( $_POST['tags'] ?? '' ) ),
            'tonality'       => in_array( $raw_tonality, array( 'major', 'minor', 'both' ), true )
                                    ? $raw_tonality : 'both',
            'match_mode'     => in_array( $raw_match_mode, array( 'strict', 'degree' ), true )
                                    ? $raw_match_mode : 'strict',
            'sort_order'     => intval( $_POST['sort_order'] ?? 0 ),
        );

        if ( empty( $data['name'] ) || empty( $data['numerals'] ) ) {
            wp_send_json_error( 'Name and numerals are required.' );
        }

        $id = intval( $_POST['id'] ?? 0 );
        if ( $id > 0 ) {
            $result = $wpdb->update( $pt, $data, array( 'id' => $id ) );
        } else {
            $result = $wpdb->insert( $pt, $data );
            $id     = $wpdb->insert_id;
        }

        if ( $result === false ) {
            wp_send_json_error( 'Database error: ' . $wpdb->last_error );
        }

        wp_send_json_success( array(
            'id'       => $id,
            'redirect' => admin_url( 'admin.php?page=sbn-chord-progressions' ),
        ) );
    }

    public function ajax_delete_progression() {
        check_ajax_referer( 'sbn_chord_progressions_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Permission denied' );

        $id = intval( $_POST['id'] ?? 0 );
        if ( ! $id ) wp_send_json_error( 'Invalid ID' );

        global $wpdb;
        $wpdb->delete( self::get_progressions_table(), array( 'id' => $id ) );
        $wpdb->delete( self::get_occurrences_table(),  array( 'progression_id' => $id ) );

        wp_send_json_success();
    }

    public function ajax_reprocess_all() {
        check_ajax_referer( 'sbn_chord_progressions_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Permission denied' );

        $results = $this->process_all_leadsheets();
        wp_send_json_success( $results );
    }
}
