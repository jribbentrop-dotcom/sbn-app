<?php
/**
 * Progression Builder — backend
 *
 * AJAX endpoints powering the frontend Progression Builder component.
 * This component is independent and can be triggered from:
 *   - Chord Progression Library modal (example usage)
 *   - Leadsheet player (voice leading suggestions)
 *   - Any page that includes the progression-builder JS
 *
 * Endpoints:
 *   sbn_get_progression_voicings  — resolve a full progression's numerals
 *                                   to concrete chords and fetch voicings
 *   sbn_get_chord_voicings        — get all voicings for a single chord
 *                                   (root + quality), including alias matches
 *   sbn_score_voice_leading       — score a pair of voicings
 *
 * @package SBN_Course_Player
 * @since   7.8.5
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ============================================================================
// AJAX REGISTRATION
// ============================================================================

add_action( 'wp_ajax_sbn_get_progression_voicings',        'sbn_get_progression_voicings_handler' );
add_action( 'wp_ajax_nopriv_sbn_get_progression_voicings', 'sbn_get_progression_voicings_handler' );
add_action( 'wp_ajax_sbn_get_chord_voicings',              'sbn_get_chord_voicings_handler' );
add_action( 'wp_ajax_nopriv_sbn_get_chord_voicings',       'sbn_get_chord_voicings_handler' );
add_action( 'wp_ajax_sbn_get_voicings_by_ids',             'sbn_get_voicings_by_ids_handler' );
add_action( 'wp_ajax_nopriv_sbn_get_voicings_by_ids',      'sbn_get_voicings_by_ids_handler' );

// ============================================================================
// NOTE / INTERVAL CONSTANTS
// ============================================================================

/**
 * Chromatic notes (prefer flats — jazz convention).
 */
function sbn_pb_notes() {
    return array( 'C', 'Db', 'D', 'Eb', 'E', 'F', 'F#', 'G', 'Ab', 'A', 'Bb', 'B' );
}

function sbn_pb_note_to_semi() {
    return array(
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
}

// Major scale semitone offsets.
function sbn_pb_major_scale() {
    return array( 0, 2, 4, 5, 7, 9, 11 );
}

// ============================================================================
// NUMERAL → CHORD RESOLVER
// ============================================================================

/**
 * Resolve a Roman-numeral token to a concrete chord name in a given key.
 *
 * Examples (key = C):
 *   IIm7   → Dm7
 *   V7     → G7
 *   bVII7  → Bb7
 *   Imaj7  → Cmaj7
 *   IIm7b5 → Dm7b5
 *
 * @param  string $numeral  e.g. "IIm7", "bVII7", "Im"
 * @param  string $key      e.g. "C", "Bb", "F#"
 * @return array|null        ['root' => 'D', 'quality' => 'm7', 'name' => 'Dm7'] or null
 */
function sbn_pb_resolve_numeral( $numeral, $key, $context = array() ) {
    $note_to_semi = sbn_pb_note_to_semi();
    $notes        = sbn_pb_notes();
    $scale        = sbn_pb_major_scale();

    // Strip mode suffix from key (e.g. "Cm" → "C")
    $key_note = preg_replace( '/[mM].*$/', '', trim( $key ) );
    $key_semi = $note_to_semi[ $key_note ] ?? null;
    if ( $key_semi === null ) return null;

    // Parse numeral: optional accidental + roman root + quality suffix
    // e.g. "bVII7", "#IVm7b5", "IIm7", "Im", "V7b9"
    if ( ! preg_match( '/^([b#]?)(VII|VI|IV|V|III|II|I|vii|vi|iv|v|iii|ii|i)(.*)$/u', $numeral, $m ) ) {
        return null;
    }

    $accidental = $m[1];
    $roman      = strtoupper( $m[2] );
    $suffix     = $m[3]; // e.g. "m7", "maj7", "7b9", "m7b5", "m"

    // Roman numeral → degree index (0-based)
    $roman_map = array(
        'I' => 0, 'II' => 1, 'III' => 2, 'IV' => 3,
        'V' => 4, 'VI' => 5, 'VII' => 6,
    );
    $degree = $roman_map[ $roman ] ?? null;
    if ( $degree === null ) return null;

    // Semitone offset from key root
    $semi_offset = $scale[ $degree ];
    if ( $accidental === 'b' ) $semi_offset -= 1;
    if ( $accidental === '#' ) $semi_offset += 1;
    $semi_offset = ( $semi_offset + 12 ) % 12;

    // Concrete root note
    $root_semi = ( $key_semi + $semi_offset ) % 12;
    $root_note = $notes[ $root_semi ];

    // Normalise suffix to quality for diagram lookup
    $quality = sbn_pb_suffix_to_quality( $suffix );

    // Apply context-aware quality upgrade for bare/triad numerals
    $category = $context['category'] ?? '';
    $tonality = $context['tonality'] ?? 'both';
    if ( $category ) {
        $quality = sbn_pb_infer_quality_from_context(
            $quality, $degree, $accidental, $category, $tonality
        );
    }

    // Build display name
    $name = $root_note . sbn_pb_quality_display( $quality );

    return array(
        'root'    => $root_note,
        'quality' => $quality,
        'name'    => $name,
    );
}

/**
 * Convert a numeral suffix to the canonical quality stored in the DB.
 *
 * @param  string $suffix  e.g. "m7", "maj7", "7", "m7b5", "m", ""
 * @return string          Canonical quality e.g. "m7", "maj7", "dom7", "m7b5", "min", "maj"
 */
function sbn_pb_suffix_to_quality( $suffix ) {
    $suffix = trim( $suffix );

    // Exact map (order matters: longest first)
    $map = array(
        'mMaj7'  => 'mMaj7',
        'mmaj7'  => 'mMaj7',
        'm7b5'   => 'm7b5',
        'maj7'   => 'maj7',
        'maj6'   => 'maj6',
        'm7'     => 'm7',
        'm6'     => 'm6',
        'o7'     => 'o7',
        'dim7'   => 'o7',
        '7b9'    => 'dom7',   // extension handled separately
        '7#9'    => 'dom7',
        '7b13'   => 'dom7',
        '7#11'   => 'dom7',
        '7'      => 'dom7',
        'aug7'   => 'aug7',
        'aug'    => 'aug',
        'dim'    => 'dim',
        'sus4'   => 'sus4',
        'sus2'   => 'sus2',
        'm9'     => 'm7',     // shorthand: m9 = m7(9)
        'maj9'   => 'maj7',
        '9'      => 'dom7',
        '13'     => 'dom7',
        '11'     => 'dom7',
        'm'      => 'min',
        ''       => 'maj',    // bare numeral = major triad
    );

    foreach ( $map as $pattern => $quality ) {
        if ( $suffix === $pattern ) return $quality;
    }

    // Fallback: if suffix starts with known quality, use that
    foreach ( $map as $pattern => $quality ) {
        if ( $pattern && strpos( $suffix, $pattern ) === 0 ) return $quality;
    }

    return 'maj'; // ultimate fallback
}

/**
 * Return a display-friendly quality string for chord names.
 */
function sbn_pb_quality_display( $quality ) {
    $map = array(
        'maj7'  => 'maj7',
        'maj6'  => '6',
        'm7'    => 'm7',
        'm6'    => 'm6',
        'dom7'  => '7',
        'm7b5'  => 'm7b5',
        'o7'    => 'o7',
        'mMaj7' => 'mMaj7',
        'aug7'  => 'aug7',
        'aug'   => 'aug',
        'dim'   => 'dim',
        'maj'   => '',
        'min'   => 'm',
        'sus4'  => 'sus4',
        'sus2'  => 'sus2',
    );
    return $map[ $quality ] ?? $quality;
}

/**
 * Upgrade a bare/triad quality to an idiomatic 7th-chord quality based on
 * the progression's category (genre) and tonality context.
 *
 * This handles the case where progressions are stored with generic numerals
 * (e.g. "I,V,VIm" instead of "Imaj7,V7,VIm7") to maximise detection matches.
 *
 * Rules by category:
 *   jazz / bossa:  use 7th-chord qualities (maj7, m7, dom7, m7b5, etc.)
 *   blues:         use dom7 for all diatonic degrees (I7, IV7, V7 style)
 *   pop / rock:    keep triads (no upgrade)
 *   classical:     keep triads (no upgrade)
 *   modal:         use 7th-chord qualities
 *
 * Special cases (apply across all jazz-family categories):
 *   V always → dom7 (dominant function is fundamental)
 *   bVI in major → maj7 (natural minor borrowed chord, often #11 character)
 *   bVII in major → dom7 (mixolydian / backdoor dominant)
 *   bIII, bVI, bVII borrowed from parallel minor → use minor-key diatonic quality
 *
 * @param string $quality     Current quality from sbn_pb_suffix_to_quality (e.g. 'maj', 'min')
 * @param int    $degree      Scale degree 0–6 (0=I, 1=II, etc.)
 * @param string $accidental  'b', '#', or ''
 * @param string $category    Progression category: 'jazz', 'blues', 'pop', 'classical', 'modal'
 * @param string $tonality    Progression tonality: 'major', 'minor', 'both'
 * @return string             Upgraded quality (e.g. 'maj7', 'dom7', 'm7')
 */
function sbn_pb_infer_quality_from_context( $quality, $degree, $accidental, $category, $tonality ) {
    // Only upgrade triads — if the numeral already specifies a 7th chord, keep it
    $triad_qualities = array( 'maj', 'min', 'dim', 'aug' );
    if ( ! in_array( $quality, $triad_qualities, true ) ) {
        return $quality;
    }

    // ── Pop / Classical: keep triads (these genres use them idiomatically) ──
    if ( in_array( $category, array( 'pop', 'classical' ), true ) ) {
        // Exception: V always gets dom7 even in pop, it's fundamental harmony
        if ( $degree === 4 && $accidental === '' && $quality === 'maj' ) {
            return 'dom7';
        }
        return $quality;
    }

    // ── Blues: everything is dom7 ──────────────────────────────────────────
    if ( $category === 'blues' ) {
        if ( $quality === 'maj' ) return 'dom7';
        if ( $quality === 'min' ) return 'm7';
        if ( $quality === 'dim' ) return 'm7b5';
        return $quality;
    }

    // ── Jazz / Bossa / Modal: use idiomatic 7th-chord qualities ───────────

    // Determine if we're in a major or minor context
    $is_minor = ( $tonality === 'minor' );

    // V always → dom7 regardless of context
    if ( $degree === 4 && $accidental === '' && $quality === 'maj' ) {
        return 'dom7';
    }

    // ── Modal interchange / borrowed chords (flat-side) ───────────────────
    // These appear in major-key progressions but borrow from the parallel minor.
    // Use the quality they would have in the parent (natural minor) tonality.
    if ( ! $is_minor && $accidental === 'b' ) {
        // bIII in major → maj7 (borrowed from natural minor: bIII = Eb in C → Ebmaj7)
        if ( $degree === 2 && $quality === 'maj' ) return 'maj7';
        // bVI in major → maj7 (borrowed from natural minor: bVI = Ab in C → Abmaj7)
        if ( $degree === 5 && $quality === 'maj' ) return 'maj7';
        // bVII in major → dom7 (mixolydian / backdoor dominant)
        if ( $degree === 6 && $quality === 'maj' ) return 'dom7';
        // bII in major → dom7 (Neapolitan / tritone sub)
        if ( $degree === 1 && $quality === 'maj' ) return 'dom7';
    }

    // ── Sharp-side alterations ────────────────────────────────────────────
    if ( $accidental === '#' ) {
        // #IV dim → m7b5 (common in jazz: #IVm7b5)
        if ( $degree === 3 && $quality === 'dim' ) return 'm7b5';
    }

    // ── Diatonic qualities in MAJOR ──────────────────────────────────────
    //   I=maj7  II=m7  III=m7  IV=maj7  V=dom7  VI=m7  VII=m7b5
    if ( ! $is_minor && $accidental === '' ) {
        $major_diatonic = array(
            0 => array( 'maj' => 'maj7' ),                  // I
            1 => array( 'min' => 'm7' ),                    // II
            2 => array( 'min' => 'm7' ),                    // III
            3 => array( 'maj' => 'maj7' ),                  // IV
            4 => array( 'maj' => 'dom7' ),                  // V (already caught above)
            5 => array( 'min' => 'm7' ),                    // VI
            6 => array( 'dim' => 'm7b5', 'min' => 'm7b5' ), // VII
        );
        if ( isset( $major_diatonic[ $degree ][ $quality ] ) ) {
            return $major_diatonic[ $degree ][ $quality ];
        }
        // Secondary dominants: major triad on a non-diatonic-major degree → dom7
        // e.g. III7 (V/VI), VI7 (V/II), II7 (V/V)
        if ( $quality === 'maj' && ! in_array( $degree, array( 0, 3 ), true ) ) {
            return 'dom7';
        }
    }

    // ── Diatonic qualities in MINOR ──────────────────────────────────────
    //   I=m7  II=m7b5  bIII=maj7  IV=m7  V=dom7  bVI=maj7  bVII=dom7
    if ( $is_minor && $accidental === '' ) {
        $minor_diatonic = array(
            0 => array( 'min' => 'm7',   'maj' => 'mMaj7' ), // I (m7 or mMaj7)
            1 => array( 'dim' => 'm7b5', 'min' => 'm7b5' ),  // II
            2 => array( 'maj' => 'maj7' ),                     // III (in minor = bIII)
            3 => array( 'min' => 'm7',   'maj' => 'dom7' ),   // IV (m7 diatonic; dom7 = borrowed)
            4 => array( 'maj' => 'dom7', 'min' => 'm7' ),     // V (dom7 harmonic minor; m7 natural)
            5 => array( 'maj' => 'maj7' ),                     // VI (= bVI in minor)
            6 => array( 'maj' => 'dom7' ),                     // VII (= bVII in minor)
        );
        if ( isset( $minor_diatonic[ $degree ][ $quality ] ) ) {
            return $minor_diatonic[ $degree ][ $quality ];
        }
    }

    // ── Tonality = 'both': use major-key diatonic as default ─────────────
    if ( $tonality === 'both' && $accidental === '' ) {
        $both_diatonic = array(
            0 => array( 'maj' => 'maj7', 'min' => 'm7' ),
            1 => array( 'min' => 'm7',   'dim' => 'm7b5' ),
            2 => array( 'min' => 'm7',   'maj' => 'dom7' ),   // III as dom = secondary dom
            3 => array( 'maj' => 'maj7' ),
            4 => array( 'maj' => 'dom7' ),
            5 => array( 'min' => 'm7',   'maj' => 'dom7' ),   // VI as dom = secondary dom
            6 => array( 'dim' => 'm7b5', 'maj' => 'dom7' ),
        );
        if ( isset( $both_diatonic[ $degree ][ $quality ] ) ) {
            return $both_diatonic[ $degree ][ $quality ];
        }
    }

    // ── Fallback: generic triad → 7th upgrade ────────────────────────────
    if ( $quality === 'maj' ) return 'maj7';
    if ( $quality === 'min' ) return 'm7';
    if ( $quality === 'dim' ) return 'm7b5';
    if ( $quality === 'aug' ) return 'aug7';

    return $quality;
}

/**
 * Find all voicings for a chord, including alias matches.
 *
 * 1. Direct match: diagrams.quality = $quality
 * 2. Alias match:  alias.alt_quality = $quality (different parent diagram)
 *
 * Each result is transposed to the requested root note.
 *
 * @param  string $root_note  e.g. "D"
 * @param  string $quality    e.g. "m7"
 * @param  array  $filters    Optional: ['voicing_category' => 'drop2', 'root_string' => 'roota']
 * @return array               Array of voicing result objects
 */
function sbn_pb_find_voicings( $root_note, $quality, $filters = array() ) {
    global $wpdb;

    $diagrams_table = $wpdb->prefix . 'sbn_chord_diagrams';
    $aliases_table  = $wpdb->prefix . 'sbn_chord_diagram_aliases';

    if ( ! class_exists( 'SBN_Chord_Shape_Calculator' ) ) {
        require_once SBN_PLUGIN_DIR . 'inc/chord-shapes/class-chord-shape-calculator.php';
    }
    $calculator = new SBN_Chord_Shape_Calculator();

    $results = array();
    $seen_ids = array(); // prevent duplicates
    $include_ext = ! empty( $filters['include_extensions'] );

    // ── 1. Direct quality match ─────────────────────────────────────────

    $where = array( "quality = %s" );
    $params = array( $quality );

    if ( $include_ext ) {
        // Level II: include both bare and extended voicings
        // e.g. quality='m7' with extensions='' OR extensions IN ('9','11',...)
        // We let ALL extensions through — the scoring handles relevance.
        // No extensions filter needed.
    } else {
        // Level I: only bare seventh chords
        $where[] = "extensions = ''";
    }

    if ( ! empty( $filters['voicing_category'] ) ) {
        $where[]  = "voicing_category = %s";
        $params[] = $filters['voicing_category'];
    }
    if ( ! empty( $filters['root_string'] ) ) {
        $where[]  = "root_string = %s";
        $params[] = $filters['root_string'];
    }

    $sql = "SELECT * FROM $diagrams_table WHERE " . implode( ' AND ', $where )
         . " ORDER BY voicing_category, root_string, inversion";

    $shapes = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );

    foreach ( $shapes as $shape ) {
        $calculated = $calculator->calculate_frets( $shape, $root_note );
        if ( ! $calculated || empty( $calculated['diagram_data'] ) ) continue;

        $seen_ids[ $shape->id ] = true;
        $results[] = sbn_pb_build_voicing_result( $shape, $calculated, $root_note, false );
    }

    // ── 2. Alias match ──────────────────────────────────────────────────

    $alias_ext_clause = $include_ext ? "" : "AND d.extensions = ''";
    $alias_sql = $wpdb->prepare(
        "SELECT a.*, d.* 
         FROM $aliases_table a 
         JOIN $diagrams_table d ON d.id = a.diagram_id 
         WHERE a.alt_quality = %s $alias_ext_clause",
        $quality
    );

    $alias_rows = $wpdb->get_results( $alias_sql );

    foreach ( $alias_rows as $row ) {
        if ( isset( $seen_ids[ $row->diagram_id ] ) ) continue;

        // The parent shape may have a different quality, so transpose normally
        $shape = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $diagrams_table WHERE id = %d", $row->diagram_id
        ) );
        if ( ! $shape ) continue;

        // For alias matches, we need to calculate the offset differently:
        // The alias defines an alternative root_note for the same shape.
        // E.g., shape is Dm7b5 archetype, alias says it's also Fm6.
        // If someone asks for Fm6, we need to find how many semitones from
        // the alias's stored root to the requested root, then apply that
        // to the parent shape's original root.
        $note_to_semi = sbn_pb_note_to_semi();
        $notes = sbn_pb_notes();

        $alias_root_semi   = $note_to_semi[ $row->alt_root_note ] ?? null;
        $target_root_semi  = $note_to_semi[ $root_note ] ?? null;
        $parent_root_semi  = $note_to_semi[ $shape->root_note ] ?? null;

        if ( $alias_root_semi === null || $target_root_semi === null || $parent_root_semi === null ) continue;

        // Semitone shift from alias root to target root
        $shift = ( $target_root_semi - $alias_root_semi + 12 ) % 12;

        // Apply same shift to parent root
        $transposed_parent_root_semi = ( $parent_root_semi + $shift ) % 12;
        $transposed_parent_root = $notes[ $transposed_parent_root_semi ];

        // Now transpose the parent shape to its new root
        $calculated = $calculator->calculate_frets( $shape, $transposed_parent_root );
        if ( ! $calculated || empty( $calculated['diagram_data'] ) ) continue;

        // Override interval_labels with the alias's labels (these are relative to the alias root)
        $calculated['interval_labels'] = $row->interval_labels ?? $calculated['interval_labels'];

        $seen_ids[ $shape->id ] = true;
        $result = sbn_pb_build_voicing_result( $shape, $calculated, $root_note, true );
        $result['alias_name'] = $row->alt_name;
        $result['alias_quality'] = $row->alt_quality;
        $results[] = $result;
    }

    return $results;
}

/**
 * Build a standardised voicing result array.
 */
function sbn_pb_build_voicing_result( $shape, $calculated, $root_note, $is_alias ) {
    return array(
        'id'                => intval( $shape->id ),
        'slug'              => $shape->slug,
        'quality'           => $shape->quality,
        'extensions'        => $shape->extensions ?? '',
        'voicing_category'  => $shape->voicing_category,
        'root_string'       => $shape->root_string,
        'inversion'         => $shape->inversion ?? 'root',
        'diagram_data'      => $calculated['diagram_data'],
        'start_fret'        => $calculated['start_fret'],
        'interval_labels'   => $calculated['interval_labels'],
        'notes'             => $calculated['notes'] ?? '',
        'root_note'         => $root_note,
        'is_alias'          => $is_alias,
    );
}

// ============================================================================
// VOICE LEADING SCORING
// ============================================================================

/**
 * Score the voice leading between two adjacent voicings.
 *
 * Lower score = smoother voice leading.
 *
 * Factors:
 *  - Guide tone movement (3rds and 7ths): semitone distance
 *  - Common tones on same/nearby strings
 *  - Total fret distance
 *  - String set continuity bonus
 *
 * @param  array $voicing_a  Voicing result (with notes, interval_labels, diagram_data)
 * @param  array $voicing_b  Voicing result
 * @return array             ['score' => float, 'details' => [...]]
 */
function sbn_pb_score_voice_leading( $voicing_a, $voicing_b, $level = 1 ) {

    // Standard tuning open-string MIDI pitches.
    // Our string numbering: 1=low E, 2=A, 3=D, 4=G, 5=B, 6=high E
    $open_midi = array( 1 => 40, 2 => 45, 3 => 50, 4 => 55, 5 => 59, 6 => 64 );

    $seventh_labels = array( 'b7', '7', 'maj7' );
    $third_labels   = array( '3', 'b3' );
    $root_labels    = array( 'R' );
    $guide_labels   = array( '3', 'b3', '7', 'b7', 'maj7' );

    // Level II extension labels
    $ninth_labels = array( '9', 'b9' );
    $maj7_labels  = array( 'maj7' );
    $sixth_labels = array( '6', '13', 'b13' );
    $fifth_labels = array( '5' );

    // Build pitch map: [{midi, label, string}] from diagram_data + interval_labels
    $build_pitch_map = function( $voicing ) use ( $open_midi ) {
        $dd = $voicing['diagram_data'] ?? null;
        if ( is_string( $dd ) ) $dd = json_decode( $dd, true );
        if ( ! $dd || empty( $dd['positions'] ) ) return array();

        $intervals = array_values( array_filter( explode( ',', $voicing['interval_labels'] ?? '' ) ) );
        $notes     = array_values( array_filter( explode( ',', $voicing['notes'] ?? '' ) ) );

        $result = array();
        $note_idx = 0;

        foreach ( $dd['positions'] as $pos ) {
            if ( ! isset( $pos['fret'] ) || $pos['fret'] === null || $pos['fret'] === 'x' || $pos['fret'] == -1 ) continue;
            $string_num = $pos['string'] ?? 0;
            $midi = ( $open_midi[ $string_num ] ?? 0 ) + intval( $pos['fret'] );
            $result[] = array(
                'midi'   => $midi,
                'label'  => $intervals[ $note_idx ] ?? '',
                'note'   => $notes[ $note_idx ] ?? '',
                'string' => $string_num,
            );
            $note_idx++;
        }
        return $result;
    };

    $pitches_a = $build_pitch_map( $voicing_a );
    $pitches_b = $build_pitch_map( $voicing_b );

    $score   = 0;
    $details = array();

    if ( empty( $pitches_a ) || empty( $pitches_b ) ) {
        return array( 'score' => 5, 'details' => array( 'fallback' => true ) );
    }

    // ── 1. GUIDE TONE RESOLUTION (actual register, not pitch class) ─────

    $filter_by_labels = function( $pitches, $labels ) {
        return array_filter( $pitches, function( $p ) use ( $labels ) {
            return in_array( $p['label'], $labels );
        });
    };

    $sev_a   = $filter_by_labels( $pitches_a, $seventh_labels );
    $thi_b   = $filter_by_labels( $pitches_b, $third_labels );
    $thi_a   = $filter_by_labels( $pitches_a, $third_labels );
    $root_b  = $filter_by_labels( $pitches_b, $root_labels );
    $sev_b   = $filter_by_labels( $pitches_b, $seventh_labels );

    $resolution_penalties = array();

    // b7 → 3/b3 in actual register
    foreach ( $sev_a as $seventh ) {
        $best_dist = 99;
        foreach ( $thi_b as $third ) {
            $d = abs( $seventh['midi'] - $third['midi'] );
            if ( $d < $best_dist ) $best_dist = $d;
        }
        if ( $best_dist <= 2 ) $penalty = 0;
        elseif ( $best_dist <= 4 ) $penalty = 2;
        elseif ( $best_dist <= 7 ) $penalty = 4;
        else $penalty = 5 + intval( ( $best_dist - 7 ) / 3 );
        $resolution_penalties[] = $penalty;
    }

    // 3 → R or 3 → b7 in actual register
    // Level II: also → maj7 of target (same note), → 9 of target
    $maj7_b  = $level >= 2 ? $filter_by_labels( $pitches_b, $maj7_labels ) : array();
    $ninth_b = $level >= 2 ? $filter_by_labels( $pitches_b, $ninth_labels ) : array();

    foreach ( $thi_a as $third ) {
        $best_dist = 99;
        foreach ( $root_b as $root ) {
            $d = abs( $third['midi'] - $root['midi'] );
            if ( $d < $best_dist ) $best_dist = $d;
        }
        foreach ( $sev_b as $sev ) {
            $d = abs( $third['midi'] - $sev['midi'] );
            if ( $d < $best_dist ) $best_dist = $d;
        }
        foreach ( $maj7_b as $m7 ) {
            $d = abs( $third['midi'] - $m7['midi'] );
            if ( $d < $best_dist ) $best_dist = $d;
        }
        foreach ( $ninth_b as $n ) {
            $d = abs( $third['midi'] - $n['midi'] );
            if ( $d < $best_dist ) $best_dist = $d;
        }
        if ( $best_dist <= 2 ) $penalty = 0;
        elseif ( $best_dist <= 4 ) $penalty = 2;
        elseif ( $best_dist <= 7 ) $penalty = 4;
        else $penalty = 5 + intval( ( $best_dist - 7 ) / 3 );
        $resolution_penalties[] = $penalty;
    }

    // Level II: 9 → 13/b13, 9 → 9, 9 → R (upper extension line)
    if ( $level >= 2 ) {
        $ninth_a  = $filter_by_labels( $pitches_a, $ninth_labels );
        $sixth_b  = $filter_by_labels( $pitches_b, $sixth_labels );
        $fifth_a  = $filter_by_labels( $pitches_a, $fifth_labels );
        $fifth_b  = $filter_by_labels( $pitches_b, $fifth_labels );

        foreach ( $ninth_a as $ninth ) {
            $best_dist = 99;
            foreach ( $sixth_b as $t ) {
                $d = abs( $ninth['midi'] - $t['midi'] );
                if ( $d < $best_dist ) $best_dist = $d;
            }
            foreach ( $ninth_b as $t ) {
                $d = abs( $ninth['midi'] - $t['midi'] );
                if ( $d < $best_dist ) $best_dist = $d;
            }
            foreach ( $root_b as $t ) {
                $d = abs( $ninth['midi'] - $t['midi'] );
                if ( $d < $best_dist ) $best_dist = $d;
            }
            if ( $best_dist < 99 ) {
                if ( $best_dist <= 2 ) $penalty = 0;
                elseif ( $best_dist <= 4 ) $penalty = 2;
                elseif ( $best_dist <= 7 ) $penalty = 4;
                else $penalty = 5 + intval( ( $best_dist - 7 ) / 3 );
                $resolution_penalties[] = $penalty;
            }
        }

        // 5 → R or 5 → 5 of next
        foreach ( $fifth_a as $fifth ) {
            $best_dist = 99;
            foreach ( $root_b as $t ) {
                $d = abs( $fifth['midi'] - $t['midi'] );
                if ( $d < $best_dist ) $best_dist = $d;
            }
            foreach ( $fifth_b as $t ) {
                $d = abs( $fifth['midi'] - $t['midi'] );
                if ( $d < $best_dist ) $best_dist = $d;
            }
            if ( $best_dist < 99 ) {
                if ( $best_dist <= 2 ) $penalty = 0;
                elseif ( $best_dist <= 4 ) $penalty = 2;
                elseif ( $best_dist <= 7 ) $penalty = 4;
                else $penalty = 5 + intval( ( $best_dist - 7 ) / 3 );
                $resolution_penalties[] = $penalty;
            }
        }
    }

    if ( ! empty( $resolution_penalties ) ) {
        $score += array_sum( $resolution_penalties );
        $details['resolution_penalties'] = $resolution_penalties;
    }

    // ── 2. General guide tone proximity (fallback) ──────────────────────
    if ( empty( $resolution_penalties ) ) {
        $guides_a = $filter_by_labels( $pitches_a, $guide_labels );
        $guides_b = $filter_by_labels( $pitches_b, $guide_labels );

        if ( ! empty( $guides_a ) && ! empty( $guides_b ) ) {
            $guide_score = 0;
            foreach ( $guides_a as $ga ) {
                $min_dist = 99;
                foreach ( $guides_b as $gb ) {
                    $d = abs( $ga['midi'] - $gb['midi'] );
                    if ( $d < $min_dist ) $min_dist = $d;
                }
                $guide_score += $min_dist;
            }
            $score += ( $guide_score / count( $guides_a ) ) * 1.5;
        }
    }

    // ── 3. Common tones ─────────────────────────────────────────────────

    $common_same_string = 0;
    foreach ( $pitches_a as $pa ) {
        foreach ( $pitches_b as $pb ) {
            if ( $pa['string'] === $pb['string'] && $pa['midi'] === $pb['midi'] ) {
                $common_same_string++;
            }
        }
    }

    $semi_set_a = array_unique( array_map( function( $p ) { return $p['midi'] % 12; }, $pitches_a ) );
    $semi_set_b = array_unique( array_map( function( $p ) { return $p['midi'] % 12; }, $pitches_b ) );
    $common_any = count( array_intersect( $semi_set_a, $semi_set_b ) );

    $score = max( 0, $score - $common_same_string * 1.5 - $common_any * 0.3 );
    $details['common_same_string'] = $common_same_string;
    $details['common_tones'] = $common_any;

    // ── 4. Fret distance ────────────────────────────────────────────────

    $diagram_a = $voicing_a['diagram_data'];
    $diagram_b = $voicing_b['diagram_data'];
    if ( is_string( $diagram_a ) ) $diagram_a = json_decode( $diagram_a, true );
    if ( is_string( $diagram_b ) ) $diagram_b = json_decode( $diagram_b, true );

    $avg_fret_a = sbn_pb_average_fret( $diagram_a );
    $avg_fret_b = sbn_pb_average_fret( $diagram_b );
    $fret_distance = abs( $avg_fret_a - $avg_fret_b );

    $score += $fret_distance * 0.4;
    $details['fret_distance'] = round( $fret_distance, 1 );

    // ── 5. String set continuity ────────────────────────────────────────

    if ( ( $voicing_a['root_string'] ?? '' ) === ( $voicing_b['root_string'] ?? '' ) ) {
        $score = max( 0, $score - 1 );
        $details['string_set_match'] = true;
    } else {
        $details['string_set_match'] = false;
    }

    $score = round( $score, 2 );

    return array(
        'score'   => $score,
        'details' => $details,
    );
}

/**
 * Calculate the average fret position of a diagram.
 */
function sbn_pb_average_fret( $diagram_data ) {
    if ( ! $diagram_data || empty( $diagram_data['positions'] ) ) return 0;

    $sum = 0;
    $count = 0;
    foreach ( $diagram_data['positions'] as $pos ) {
        if ( $pos['fret'] > 0 ) {
            $sum += $pos['fret'];
            $count++;
        }
    }
    return $count > 0 ? $sum / $count : 0;
}

// ============================================================================
// AJAX HANDLERS
// ============================================================================

/**
 * Get voicings for an entire progression.
 *
 * Accepts: numerals (comma-separated), key
 * Returns: array of resolved chords, each with available voicings
 */
function sbn_get_progression_voicings_handler() {
    check_ajax_referer( 'sbn_prog_builder', 'nonce' );

    $numerals_str = sanitize_text_field( $_POST['numerals'] ?? '' );
    $key          = sanitize_text_field( $_POST['key'] ?? 'C' );
    $vl_level     = intval( $_POST['vl_level'] ?? 1 );
    $category     = sanitize_text_field( $_POST['category'] ?? '' );
    $tonality     = sanitize_text_field( $_POST['tonality'] ?? 'both' );

    if ( empty( $numerals_str ) ) {
        wp_send_json_error( 'Missing numerals' );
    }

    $numerals = array_map( 'trim', explode( ',', $numerals_str ) );
    $chords   = array();

    $filters = array();
    if ( $vl_level >= 2 ) {
        $filters['include_extensions'] = true;
    }

    // Build context for quality inference on bare numerals
    $context = array(
        'category' => $category,
        'tonality' => $tonality,
    );

    foreach ( $numerals as $idx => $numeral ) {
        $resolved = sbn_pb_resolve_numeral( $numeral, $key, $context );
        if ( ! $resolved ) {
            $chords[] = array(
                'index'    => $idx,
                'numeral'  => $numeral,
                'error'    => 'Could not resolve numeral',
                'voicings' => array(),
            );
            continue;
        }

        $voicings = sbn_pb_find_voicings( $resolved['root'], $resolved['quality'], $filters );

        // Encode diagram_data as JSON string for transport
        foreach ( $voicings as &$v ) {
            if ( is_array( $v['diagram_data'] ) ) {
                $v['diagram_data'] = json_encode( $v['diagram_data'] );
            }
        }
        unset( $v );

        $chords[] = array(
            'index'    => $idx,
            'numeral'  => $numeral,
            'root'     => $resolved['root'],
            'quality'  => $resolved['quality'],
            'name'     => $resolved['name'],
            'voicings' => $voicings,
        );
    }

    // If any chords already have selected voicings passed in, score voice leading
    $selections = isset( $_POST['selections'] ) ? json_decode( stripslashes( $_POST['selections'] ), true ) : null;
    $vl_scores  = array();

    if ( is_array( $selections ) && count( $selections ) >= 2 ) {
        for ( $i = 0; $i < count( $selections ) - 1; $i++ ) {
            $a = $selections[ $i ];
            $b = $selections[ $i + 1 ];
            if ( $a && $b ) {
                $vl_scores[] = sbn_pb_score_voice_leading( $a, $b );
            }
        }
    }

    wp_send_json_success( array(
        'chords'          => $chords,
        'key'             => $key,
        'voice_leading'   => $vl_scores,
    ) );
}

/**
 * Get voicings for a single chord.
 *
 * Accepts: root_note, quality, filters (optional)
 * Returns: array of transposed voicings
 */
function sbn_get_chord_voicings_handler() {
    check_ajax_referer( 'sbn_prog_builder', 'nonce' );

    $root_note = sanitize_text_field( $_POST['root_note'] ?? '' );
    $quality   = sanitize_text_field( $_POST['quality'] ?? '' );

    if ( empty( $root_note ) || empty( $quality ) ) {
        wp_send_json_error( 'Missing root_note or quality' );
    }

    $filters = array();
    if ( ! empty( $_POST['voicing_category'] ) ) $filters['voicing_category'] = sanitize_text_field( $_POST['voicing_category'] );
    if ( ! empty( $_POST['root_string'] ) )      $filters['root_string']      = sanitize_text_field( $_POST['root_string'] );

    $voicings = sbn_pb_find_voicings( $root_note, $quality, $filters );

    // Encode diagram_data
    foreach ( $voicings as &$v ) {
        if ( is_array( $v['diagram_data'] ) ) {
            $v['diagram_data'] = json_encode( $v['diagram_data'] );
        }
    }
    unset( $v );

    // If a "current" voicing is passed, score voice leading against all results
    $current = isset( $_POST['current_voicing'] ) ? json_decode( stripslashes( $_POST['current_voicing'] ), true ) : null;
    if ( $current ) {
        foreach ( $voicings as &$v ) {
            $vl = sbn_pb_score_voice_leading( $current, $v );
            $v['vl_score'] = $vl['score'];
            $v['vl_details'] = $vl['details'];
        }
        unset( $v );

        // Sort by voice leading score (best first)
        usort( $voicings, function( $a, $b ) {
            return $a['vl_score'] <=> $b['vl_score'];
        });
    }

    wp_send_json_success( array(
        'root_note' => $root_note,
        'quality'   => $quality,
        'voicings'  => $voicings,
    ) );
}

/**
 * Get specific voicings by diagram ID + root note.
 *
 * Used to pre-populate the builder with voicings from a song example.
 * Bypasses the quality/extension filter — fetches the exact diagram rows
 * regardless of extensions, then transposes each to the requested root note.
 *
 * Accepts:
 *   slots: JSON array of { diagram_id, root_note } objects (one per progression slot)
 * Returns:
 *   Array of voicing result objects (same shape as sbn_get_chord_voicings), or null per slot if not found.
 */
function sbn_get_voicings_by_ids_handler() {
    check_ajax_referer( 'sbn_prog_builder', 'nonce' );

    $raw   = sanitize_text_field( wp_unslash( $_POST['slots'] ?? '' ) );
    $slots = json_decode( $raw, true );

    if ( ! is_array( $slots ) || empty( $slots ) ) {
        wp_send_json_error( 'Missing or invalid slots' );
    }

    if ( ! class_exists( 'SBN_Chord_Shape_Calculator' ) ) {
        require_once SBN_PLUGIN_DIR . 'inc/chord-shapes/class-chord-shape-calculator.php';
    }

    global $wpdb;
    $diagrams_table = $wpdb->prefix . 'sbn_chord_diagrams';
    $calculator     = new SBN_Chord_Shape_Calculator();
    $results        = array();

    foreach ( $slots as $slot ) {
        $diagram_id = intval( $slot['diagram_id'] ?? 0 );
        $root_note  = sanitize_text_field( $slot['root_note'] ?? 'C' );

        if ( ! $diagram_id ) {
            $results[] = null;
            continue;
        }

        $shape = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $diagrams_table WHERE id = %d",
            $diagram_id
        ) );

        if ( ! $shape ) {
            $results[] = null;
            continue;
        }

        $calculated = $calculator->calculate_frets( $shape, $root_note );
        if ( ! $calculated || empty( $calculated['diagram_data'] ) ) {
            $results[] = null;
            continue;
        }

        $voicing = sbn_pb_build_voicing_result( $shape, $calculated, $root_note, false );
        // Encode diagram_data for transport
        if ( is_array( $voicing['diagram_data'] ) ) {
            $voicing['diagram_data'] = json_encode( $voicing['diagram_data'] );
        }
        $results[] = $voicing;
    }

    wp_send_json_success( $results );
}
