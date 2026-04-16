<?php
/**
 * Chord Detail Page — standalone page per chord shape / diagram
 *
 * Renders theory content, voicing information, and an exemplary chord
 * progression for a single chord shape or specific diagram.
 *
 * Usage: create a WordPress page with slug "chord" and place [sbn_chord_page]
 * in the content.
 *
 * URL formats:
 *   /chord/?quality=maj7              — generic shape, defaults to key of C
 *   /chord/?id=42                     — specific diagram, key inferred from root
 *   /chord/?id=42&key=F               — specific diagram, key override
 *
 * @package SBN_Course_Player
 * @since   7.7.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ============================================================================
// PAGE DETECTION
// ============================================================================

function sbn_is_chord_detail_page() {
    return (
        is_page( 'chord' ) ||
        ( is_page() && get_post_field( 'post_name', get_post() ) === 'chord' )
    );
}

// ============================================================================
// ASSET ENQUEUING
// ============================================================================

function sbn_enqueue_chord_detail_assets() {
    if ( ! sbn_is_chord_detail_page() ) return;

    wp_enqueue_style(
        'sbn-chord-styling',
        SBN_PLUGIN_URL . 'assets/css/chord-styling.css',
        array(),
        SBN_VERSION
    );

    wp_enqueue_style(
        'sbn-chord-card',
        SBN_PLUGIN_URL . 'assets/css/sbn-chord-card.css',
        array(),
        SBN_VERSION
    );

    wp_enqueue_style(
        'sbn-progression-builder',
        SBN_PLUGIN_URL . 'assets/css/progression-builder.css',
        array( 'sbn-chord-card' ),
        SBN_VERSION
    );

    wp_enqueue_style(
        'sbn-chord-detail-page',
        SBN_PLUGIN_URL . 'assets/css/chord-detail-page.css',
        array( 'sbn-chord-styling', 'sbn-chord-card', 'sbn-progression-builder' ),
        SBN_VERSION
    );

    wp_enqueue_script(
        'sbn-chord-card',
        SBN_PLUGIN_URL . 'assets/js/sbn-chord-card.js',
        array(),
        SBN_VERSION,
        true
    );

    wp_enqueue_script(
        'sbn-progression-builder',
        SBN_PLUGIN_URL . 'assets/js/progression-builder.js',
        array( 'sbn-chord-card' ),
        SBN_VERSION,
        true
    );
}
add_action( 'wp_enqueue_scripts', 'sbn_enqueue_chord_detail_assets' );

// ============================================================================
// DYNAMIC PAGE TITLE
// ============================================================================

add_filter( 'pre_get_document_title', function( $title ) {
    if ( ! sbn_is_chord_detail_page() ) return $title;

    $id      = intval( $_GET['id'] ?? 0 );
    $quality = sanitize_text_field( $_GET['quality'] ?? '' );

    if ( $id && class_exists( 'SBN_Chord_Diagrams' ) ) {
        global $wpdb;
        $table  = $wpdb->prefix . 'sbn_chord_diagrams';
        $chord  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ) );
        if ( $chord ) {
            $name = sbn_chord_detail_display_name( $chord );
            return esc_html( $name ) . ' Chord — ' . get_bloginfo( 'name' );
        }
    }

    if ( $quality ) {
        $qualities = class_exists( 'SBN_Chord_Diagrams' ) ? SBN_Chord_Diagrams::get_chord_qualities() : array();
        $label     = $qualities[ $quality ] ?? strtoupper( $quality );
        return esc_html( $label ) . ' Chord — ' . get_bloginfo( 'name' );
    }

    return $title;
} );

// ============================================================================
// HELPERS
// ============================================================================

/**
 * Build a display name for a diagram row.
 * Specific diagram: "Fmaj7"  |  Generic shape: "maj7"
 */
function sbn_chord_detail_display_name( $chord ) {
    $root    = $chord->root_note ?? '';
    $quality = $chord->quality   ?? '';
    $ext     = $chord->extensions ?? '';
    return trim( $root . $quality . $ext );
}

/**
 * Human-readable quality label, e.g. 'maj7' → 'Major 7'.
 */
function sbn_chord_detail_quality_label( $quality ) {
    if ( ! class_exists( 'SBN_Chord_Diagrams' ) ) return $quality;
    $map = SBN_Chord_Diagrams::get_chord_qualities();
    return $map[ $quality ] ?? $quality;
}

/**
 * Theory description for common chord qualities.
 * Returns an array with keys: intervals, function, typical_context, related.
 */
function sbn_chord_detail_theory( $quality ) {
    $map = array(
        'maj7' => array(
            'intervals'       => 'Root, Major 3rd, Perfect 5th, Major 7th',
            'function'        => 'Tonic — creates a rich, stable, dreamy sound. The major 7th adds colour without creating tension.',
            'typical_context' => 'Most often appears on the I or IV degree in major keys. A cornerstone of jazz harmony.',
            'related'         => array( 'maj9', 'maj6', 'maj6/9', 'add9' ),
            'tension'         => 1,
        ),
        'maj' => array(
            'intervals'       => 'Root, Major 3rd, Perfect 5th',
            'function'        => 'Tonic — the fundamental major triad. Bright and stable.',
            'typical_context' => 'Appears on I, IV, V degrees in major keys. The building block of Western harmony.',
            'related'         => array( 'maj7', 'maj6', 'add9' ),
            'tension'         => 0,
        ),
        'm7' => array(
            'intervals'       => 'Root, Minor 3rd, Perfect 5th, Minor 7th',
            'function'        => 'Subdominant or pre-dominant — softer and more introspective than a dominant chord. Adds depth without strong resolution pull.',
            'typical_context' => 'Appears on IIm7, IIIm7, VIm7 in major keys. The II chord in a II-V-I is always m7.',
            'related'         => array( 'm9', 'm11', 'm6', 'mMaj7' ),
            'tension'         => 2,
        ),
        'min' => array(
            'intervals'       => 'Root, Minor 3rd, Perfect 5th',
            'function'        => 'Tonic minor — darker, more serious sound than major triad.',
            'typical_context' => 'I, IV, V in minor keys. Im, IVm, Vm in modal contexts.',
            'related'         => array( 'm7', 'm9', 'mMaj7' ),
            'tension'         => 0,
        ),
        'dom7' => array(
            'intervals'       => 'Root, Major 3rd, Perfect 5th, Minor 7th',
            'function'        => 'Dominant — high tension chord that strongly wants to resolve a perfect 4th up (to the tonic). The tritone between 3rd and 7th is the engine of jazz harmony.',
            'typical_context' => 'V7 in major or minor keys. Can also appear as secondary dominants (e.g. II7 resolving to V).',
            'related'         => array( '9', '13', '7b9', '7#11', '7alt' ),
            'tension'         => 5,
        ),
        'm7b5' => array(
            'intervals'       => 'Root, Minor 3rd, Diminished 5th, Minor 7th',
            'function'        => 'Half-diminished — functions as IIø in minor II-V-I. Darker and more tense than m7.',
            'typical_context' => 'IIm7b5 in minor keys. Also appears on the VII degree in major keys.',
            'related'         => array( 'o7', 'm7' ),
            'tension'         => 4,
        ),
        'o7' => array(
            'intervals'       => 'Root, Minor 3rd, Diminished 5th, Diminished 7th',
            'function'        => 'Fully diminished — maximum tension. Symmetrical chord (all minor 3rds) that can resolve to four different keys.',
            'typical_context' => 'VII°7 in harmonic minor. Used as a substitute for dominant b9 chords.',
            'related'         => array( 'm7b5', 'dom7' ),
            'tension'         => 5,
        ),
        'maj6' => array(
            'intervals'       => 'Root, Major 3rd, Perfect 5th, Major 6th',
            'function'        => 'Tonic — warm and slightly jazzy alternative to maj7. The 6th replaces the 7th, avoiding any semitone tension.',
            'typical_context' => 'Imaj6, IVmaj6. Very common in swing and bossa nova.',
            'related'         => array( 'maj7', 'maj6/9', 'add9' ),
            'tension'         => 1,
        ),
        'm6' => array(
            'intervals'       => 'Root, Minor 3rd, Perfect 5th, Major 6th',
            'function'        => 'Minor tonic with a slight major colour — the major 6th creates a bittersweet quality. Also functions well as IVm6.',
            'typical_context' => 'Im6, IVm6. Strong in minor ii-V-i resolutions and bossa nova.',
            'related'         => array( 'm7', 'mMaj7' ),
            'tension'         => 2,
        ),
        'mMaj7' => array(
            'intervals'       => 'Root, Minor 3rd, Perfect 5th, Major 7th',
            'function'        => 'Minor tonic with a leading tone — extremely tense and dramatic. The clash between minor 3rd and major 7th gives it a mysterious, cinematic quality.',
            'typical_context' => 'ImMaj7 — most famous as the first chord of a minor line cliché (descending inner voice motion).',
            'related'         => array( 'm7', 'm6', 'o7' ),
            'tension'         => 4,
        ),
        'aug7' => array(
            'intervals'       => 'Root, Major 3rd, Augmented 5th, Minor 7th',
            'function'        => 'Augmented dominant — tension with an upward pull on the 5th. Often used as a substitute for V7 when resolving to a major tonic.',
            'typical_context' => 'V7#5, bVII+7. Common in jazz and gospel.',
            'related'         => array( 'dom7', '7#11' ),
            'tension'         => 5,
        ),
    );

    return $map[ $quality ] ?? null;
}

/**
 * Educational content for the detail page theory panel.
 * Mirrors the JS eduContent object in chord-library-redesign.js so
 * the same teaching copy appears on both library and detail pages.
 *
 * Returns an array with keys: description, usage (quality)
 *                              voicing_name, voicing_detail, voicing_tip (voicing)
 *                              ext_name, ext_description, ext_context (extension)
 */
function sbn_chord_detail_edu( $quality, $voicing_category = '', $extension = '' ) {
    $out = array();

    // ── Quality descriptions ──────────────────────────────────────────────────
    $quality_map = array(
        'maj'   => array( 'description' => 'The foundation of Western harmony. Three notes — root, major third, perfect fifth — producing a bright, stable, resolved sound.',
                          'usage'       => 'The home base. Appears as the I, IV, and V chord in major keys. When you hear "happy" or "bright" in music, chances are a major triad is doing the work.' ),
        'min'   => array( 'description' => 'Lower the third by a half step and the entire character shifts. The minor triad sounds darker, more introspective — but equally stable.',
                          'usage'       => 'The ii, iii, and vi chords in major keys. Dominates minor keys as the i chord. Essential for ballads, jazz standards, and anything with emotional depth.' ),
        'maj7'  => array( 'description' => 'Add the major seventh to a major triad and you get a lush, sophisticated sound. The interval between root and seventh is just a half step shy of an octave, creating a gentle tension that floats rather than pushes.',
                          'usage'       => 'The signature sound of bossa nova and smooth jazz. Functions as I and IV in major keys. Think Jobim, think "Girl from Ipanema." When you want warmth without urgency.' ),
        'm7'    => array( 'description' => 'The workhorse of jazz harmony. A minor triad plus a flatted seventh creates a mellow, warm sound that sits comfortably in almost any context.',
                          'usage'       => 'The ii chord in major II-V-I progressions. The i chord in minor keys. Appears everywhere in jazz, R&B, neo-soul, and bossa nova. If you learn one four-note chord type, make it this one.' ),
        'dom7'  => array( 'description' => 'Major triad plus a flatted seventh. The tritone between the third and seventh creates tension that wants to resolve — this is the engine of harmonic motion.',
                          'usage'       => 'The V chord that pulls you home to I. The backbone of blues (I7, IV7, V7). In jazz, dominant chords appear everywhere — as secondary dominants, tritone substitutions, and extended dominant chains.' ),
        'm7b5'  => array( 'description' => 'A minor seventh chord with a flatted fifth. Less tense than fully diminished — the minor seventh softens the sound, giving it a melancholy, yearning quality.',
                          'usage'       => 'The ii chord in minor key ii-V-i progressions. Essential for minor jazz harmony. Also appears as VII&oslash; in major keys.' ),
        'o7'    => array( 'description' => 'Three stacked minor thirds dividing the octave into four equal parts. Symmetrical and mysterious — every note can function as the root.',
                          'usage'       => 'The classic dramatic chord. Used as a passing chord, a dominant substitute, or for chromatic voice leading. Common in jazz standards, classical, and film scores.' ),
        'maj6'  => array( 'description' => 'A major triad with an added sixth. Warmer and more relaxed than maj7 — less "pretty," more grounded.',
                          'usage'       => 'Common tonic chord in swing and early jazz. Often used instead of maj7 for a vintage feel. The classic "jazz ending" chord.' ),
        'm6'    => array( 'description' => 'A minor triad with an added major sixth. The sixth adds a bittersweet brightness to the minor quality — tense but beautiful.',
                          'usage'       => 'Classic minor tonic chord in jazz. Often interchangeable with m7. Characteristic sound of gypsy jazz and older jazz styles.' ),
        'mMaj7' => array( 'description' => 'Minor triad plus a major seventh. The clash between the minor third and major seventh gives it a mysterious, cinematic quality.',
                          'usage'       => 'Im(maj7) — most famous as the first chord of a minor line cliché (descending chromatic inner voice motion). Stunning in the right context.' ),
        'aug7'  => array( 'description' => 'Major triad plus augmented fifth and minor seventh. The raised fifth adds an upward, striving quality to the dominant tension.',
                          'usage'       => 'V7#5, used when resolving to a major tonic. Common in jazz and gospel. Creates a different colour than a plain dominant.' ),
        'aug'   => array( 'description' => 'Two stacked major thirds. Like diminished, it\'s symmetrical — but where diminished contracts, augmented expands. An unsettled, reaching sound.',
                          'usage'       => 'Often used as a passing chord or dominant variation (V+). Common in Beatles progressions, film scores, and as a chromatic connector between chords.' ),
        'dim'   => array( 'description' => 'Two stacked minor thirds create an unstable, tense sound. The diminished fifth (tritone from root) gives this chord its restless character.',
                          'usage'       => 'Appears naturally as vii° in major keys. Often used as a passing chord between two more stable chords. Handle with care — a little goes a long way.' ),
        'sus4'  => array( 'description' => 'The third is replaced by a perfect fourth, removing the major/minor identity. The result is open, ambiguous, and full of potential.',
                          'usage'       => 'Creates tension that typically resolves to a major or minor chord. Widely used in pop, rock, and gospel. Think Hendrix, think "Pinball Wizard."' ),
        'sus2'  => array( 'description' => 'The third is replaced by a major second. Even more open than sus4 — airy, modern, and neither major nor minor.',
                          'usage'       => 'Common in pop and modern worship music. Creates space and ambiguity. Works beautifully as a tonic or as a color chord.' ),
        'add9'  => array( 'description' => 'A major triad with an added ninth — no seventh. The ninth adds sparkle and openness without the sophistication of a full ninth chord.',
                          'usage'       => 'A staple of pop, folk, and acoustic guitar. Cadd9 (x32030) is one of the most iconic guitar shapes ever. Adds color without adding complexity.' ),
        '5'     => array( 'description' => 'Just root and fifth — no third means no major or minor identity. Raw, ambiguous, and powerful under distortion.',
                          'usage'       => 'The foundation of rock, punk, and metal guitar. Works with any amount of gain because there\'s no third to create beating.' ),
    );

    if ( isset( $quality_map[ $quality ] ) ) {
        $out['quality_description'] = $quality_map[ $quality ]['description'];
        $out['quality_usage']       = $quality_map[ $quality ]['usage'];
    }

    // ── Voicing type explanations ─────────────────────────────────────────────
    $voicing_map = array(
        'archetype' => array( 'name' => 'Archetypes',      'detail' => 'The fundamental open-position guitar chords (E, Em, A, Am, D, Dm, C, G) and their 7th-chord siblings. These are transposable shapes that form barré chords when moved up the neck.',
                             'tip'  => 'Master all 8 basic archetypes first, then learn their 7th-chord variants. Once comfortable, practice barré versions starting with the E and A shapes.' ),
        'drop2'    => array( 'name' => 'Drop 2',         'detail' => 'Take a closed-position chord and drop the second-highest note down an octave. This opens up the voicing, spreading the notes across a wider range while keeping the sound balanced.',
                             'tip'  => 'Practice connecting Drop 2 voicings through ii-V-I progressions. Move the minimum number of fingers between chords — that\'s where the magic of voice leading happens.' ),
        'drop3'    => array( 'name' => 'Drop 3',         'detail' => 'Drop the third-highest note from a closed voicing down an octave. Creates a wider spread than Drop 2, with a gap in the middle. Works well for solo guitar and chord melody.',
                             'tip'  => 'Drop 3 shapes often skip a string in the middle. This takes getting used to, but the payoff is a rich, full sound that fills more sonic space.' ),
        'shell'    => array( 'name' => 'Shell Voicings', 'detail' => 'Strip a chord down to its bare essentials: root, third, and seventh. Three notes that define the chord quality with nothing extra.',
                             'tip'  => 'Start with shells on the 6th and 5th strings. Learn to comp through entire standards using only shells — you\'ll be amazed how complete they sound.' ),
        'rootless' => array( 'name' => 'Rootless',       'detail' => 'Remove the root entirely and let the bass player handle it. What remains are the chord\'s color tones — third, seventh, and extensions.',
                             'tip'  => 'These only work well when a bass player is covering the root. Solo guitar? Add the root back. In a trio or quartet? Go rootless and enjoy the freedom.' ),
        'closed'   => array( 'name' => 'Closed Position','detail' => 'All four chord tones of a seventh chord packed within one octave. Compact and dense — the textbook chord spelling, and the starting point from which Drop 2 and Drop 3 are derived.',
                             'tip'  => 'On guitar, closed voicings are harder to finger due to tight spacing. Understanding them is essential — they\'re the foundation for all other voicing types.' ),
        'closed_triads' => array( 'name' => 'Closed Triads', 'detail' => 'Three-note chords in close position — root, third, and fifth all within one octave. Systematic inversions across all string sets.',
                             'tip'  => 'Learn all three inversions on one string set, then connect them up and down the neck. This is how you unlock the entire fretboard.' ),
        'spread_triads' => array( 'name' => 'Spread Triads', 'detail' => 'Three-note chords with notes spread across a wider range than one octave. Bigger sound, great for fills and melodic playing.',
                             'tip'  => 'Spread triads work especially well for country, R&B, and jazz fills. They cover more of the fretboard and blend well in ensemble settings.' ),
        'custom'   => array( 'name' => 'Custom Voicings','detail' => 'Unique shapes that don\'t fit neatly into standard categories. Includes open-string voicings, hybrid grips, and guitar-specific fingerings.',
                             'tip'  => 'Don\'t ignore these because they\'re "irregular." Some of the most beautiful guitar chords live in this category.' ),
        // Legacy fallback — old diagrams might still reference 'open'
        'open'     => array( 'name' => 'Open Voicings',  'detail' => 'Chord tones spread across a range wider than one octave. Open strings may or may not be involved — the term refers to the spread of notes, not open strings.',
                             'tip'  => 'Don\'t confuse "open voicing" with "open string." A Drop 2 chord at fret 7 is still an open voicing because its notes span more than an octave.' ),
    );

    if ( $voicing_category && isset( $voicing_map[ $voicing_category ] ) ) {
        $out['voicing_name']   = $voicing_map[ $voicing_category ]['name'];
        $out['voicing_detail'] = $voicing_map[ $voicing_category ]['detail'];
        $out['voicing_tip']    = $voicing_map[ $voicing_category ]['tip'];
    }

    // ── Extension explanations ────────────────────────────────────────────────
    $ext_map = array(
        '9'   => array( 'name' => '9th',           'description' => 'The 9th is the same note as the 2nd, but up an octave. It adds color and openness without changing the chord\'s fundamental character.',
                        'context' => 'Added 9ths work on almost any chord type. They\'re the most "safe" extension — rarely unwanted.' ),
        '11'  => array( 'name' => '11th',          'description' => 'The 11th (same as the 4th, up an octave) adds a modal, open quality. On dominant and minor chords it sounds natural; on major chords it clashes with the third.',
                        'context' => 'Use ♯11 on major chords (Lydian sound) and natural 11 on minor and dominant chords.' ),
        '13'  => array( 'name' => '13th',          'description' => 'The 13th (same as the 6th, up an octave) adds warmth and complexity. It\'s the highest commonly used extension.',
                        'context' => 'Dominant 13ths are a jazz staple. The 13th often replaces the 5th in practical voicings.' ),
        'b9'  => array( 'name' => '♭9 (Flat Nine)','description' => 'A minor ninth above the root. Dark, tense, and dramatic — an alteration, not a natural extension.',
                        'context' => 'Almost exclusively used on dominant chords, especially V7 resolving to minor. The signature sound of minor ii-V-i progressions.' ),
        '#9'  => array( 'name' => '♯9 (Sharp Nine)','description' => 'An augmented ninth — the "Hendrix note." Enharmonically the same as a minor third, creating a simultaneous major/minor clash.',
                        'context' => 'The dominant 7♯9 is the "Purple Haze" chord. Blues, rock, funk — anywhere you want grit and attitude.' ),
        '#11' => array( 'name' => '♯11 (Sharp Eleven)','description' => 'A tritone above the root. Bright, floating, otherworldly — the Lydian sound.',
                        'context' => 'Used on major 7th and dominant chords for a modern, open quality. Kenny Barron, Pat Metheny — the ♯11 is sophisticated jazz at its best.' ),
        'b13' => array( 'name' => '♭13 (Flat Thirteen)','description' => 'Same as an augmented fifth in a higher octave. Adds a dark, altered quality to dominant chords.',
                        'context' => 'Part of the "altered dominant" sound. Common in V7 chords resolving to minor. Creates beautiful tension when paired with ♭9.' ),
    );

    if ( $extension && isset( $ext_map[ $extension ] ) ) {
        $out['ext_name']        = $ext_map[ $extension ]['name'];
        $out['ext_description'] = $ext_map[ $extension ]['description'];
        $out['ext_context']     = $ext_map[ $extension ]['context'];
    }

    return $out;
}

/**
 * Given a chord root note and the Roman numeral token it matched in a progression,
 * derive the tonic key of that progression.
 *
 * E.g. root=Eb, token=IIm7b5  →  II is 2 semitones above tonic  →  key=Db
 *      root=G,  token=V7       →  V  is 7 semitones above tonic  →  key=C
 *      root=C,  token=Imaj7    →  I  is 0 semitones above tonic  →  key=C
 *
 * Falls back to $root if the numeral can't be parsed.
 *
 * @param  string $root   Note name, e.g. 'Eb', 'G', 'C'
 * @param  string $token  Numeral token, e.g. 'IIm7b5', 'V7', 'Imaj7'
 * @return string         Key note name, e.g. 'Db', 'C'
 */
function sbn_chord_detail_key_from_slot( $root, $token ) {
    if ( ! function_exists( 'sbn_pb_notes' ) ) {
        require_once SBN_PLUGIN_DIR . 'inc/progression-builder.php';
    }

    $note_to_semi = sbn_pb_note_to_semi();
    $notes        = sbn_pb_notes();
    $scale        = sbn_pb_major_scale();

    $root_semi = $note_to_semi[ $root ] ?? null;
    if ( $root_semi === null ) return $root;

    // Parse the numeral token: optional accidental + roman numeral
    if ( ! preg_match( '/^([b#]?)(VII|VI|IV|V|III|II|I)/i', $token, $m ) ) {
        return $root;
    }

    $accidental = $m[1];
    $roman      = strtoupper( $m[2] );

    $roman_map = array( 'I' => 0, 'II' => 1, 'III' => 2, 'IV' => 3, 'V' => 4, 'VI' => 5, 'VII' => 6 );
    $degree    = $roman_map[ $roman ] ?? null;
    if ( $degree === null ) return $root;

    $semi_offset = $scale[ $degree ];
    if ( $accidental === 'b' ) $semi_offset -= 1;
    if ( $accidental === '#' ) $semi_offset += 1;
    $semi_offset = ( $semi_offset + 12 ) % 12;

    // Key is root shifted DOWN by semi_offset
    $key_semi = ( $root_semi - $semi_offset + 12 ) % 12;
    return $notes[ $key_semi ];
}

add_shortcode( 'sbn_chord_page', 'sbn_chord_page_shortcode' );

function sbn_chord_page_shortcode( $atts ) {
    if ( ! class_exists( 'SBN_Chord_Diagrams' ) || ! class_exists( 'SBN_Chord_Progressions' ) ) {
        return '<div class="sbn-chord-detail-error"><p>Chord module not available.</p></div>';
    }

    global $wpdb;
    $diagrams_table = $wpdb->prefix . 'sbn_chord_diagrams';

    // ── Resolve parameters ────────────────────────────────────────────────────
    $diagram_id       = intval( $_GET['id']         ?? 0 );
    $quality_raw      = sanitize_text_field( $_GET['quality']    ?? '' );
    $root_param       = sanitize_text_field( $_GET['root']       ?? '' ); // e.g. 'D', 'Bb', 'F#'
    $key_param        = sanitize_text_field( $_GET['key']        ?? '' );
    $extensions_param = sanitize_text_field( $_GET['extensions'] ?? '' ); // e.g. '9', 'b13', '#11'
    $inversion_param  = sanitize_text_field( $_GET['inversion']  ?? '' ); // e.g. 'inv1', 'inv2'

    $chord        = null;
    $quality      = '';
    $diagram_root = null;   // note name if a specific root is known
    $diagram_key  = '';     // key to boot the progression builder with

    if ( $diagram_id ) {
        // Specific persisted diagram (static card with a real DB id)
        $chord = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $diagrams_table WHERE id = %d",
            $diagram_id
        ) );
        if ( ! $chord ) {
            return '<div class="sbn-chord-detail-error"><p>Chord not found.</p></div>';
        }
        $quality      = $chord->quality ?? '';
        $diagram_root = $chord->root_note ?? null;
        $diagram_key  = $key_param ?: ( $diagram_root ?: 'C' );

    } elseif ( $root_param && $quality_raw ) {
        // Transposed chord from AJAX search (e.g. Dm7 → root=D, quality=m7).
        // The exact voicing is passed via sessionStorage (written by navigateToChordDetail).
        // We still need a $chord row for metadata (voicing_category, inversion, etc.)
        // but diagram rendering uses the sessionStorage data read in JS.
        $quality      = $quality_raw;
        $diagram_root = $root_param;
        $diagram_key  = $key_param ?: $root_param;

        // Fetch the most popular archetype for metadata only — diagram is overridden by JS
        $chord = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $diagrams_table WHERE quality = %s ORDER BY popularity DESC LIMIT 1",
            $quality
        ) );

    } elseif ( $quality_raw ) {
        // Direct URL with quality only (e.g. /chord/?quality=maj7).
        // Library navigation always adds a root, but handle this gracefully
        // by defaulting to C — same as if the user clicked a Cmaj7 archetype card.
        $quality      = $quality_raw;
        $diagram_root = $key_param ?: 'C';
        $diagram_key  = $diagram_root;

        $chord = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM $diagrams_table WHERE quality = %s ORDER BY popularity DESC LIMIT 1",
            $quality
        ) );

    } else {
        return '<div class="sbn-chord-detail-error"><p>No chord selected. <a href="' . esc_url( home_url( '/chord-library/' ) ) . '">← Chord Library</a></p></div>';
    }

    // ── Back URL ──────────────────────────────────────────────────────────────
    $back_url = sanitize_url( $_GET['back'] ?? '' );
    if ( ! $back_url ) {
        $lib_page = get_page_by_path( 'chord-library' );
        $back_url = $lib_page ? get_permalink( $lib_page ) : home_url( '/chord-library/' );
    }

    // ── Hero diagram data ─────────────────────────────────────────────────────
    // For transposed chords (?root=+?quality=), the JS reads the exact voicing
    // from sessionStorage and injects it into the hero fretboard element after load.
    // For persisted diagrams (?id=), we render directly from the DB row.
    // $hero_* vars are used in the template; JS overwrites them when sessionStorage is set.
    $hero_diagram_data    = $chord ? $chord->diagram_data    : null;
    $hero_start_fret      = $chord ? $chord->start_fret      : null;
    $hero_interval_labels = $chord ? $chord->interval_labels : null;

    // ── Rootless shape transposition ──────────────────────────────────────────
    // Rootless shapes are stored with whichever root they were entered at.
    // When arriving via ?quality= or ?root=+?quality= without a persisted ID,
    // the JS sessionStorage path handles rooted cards correctly, but for the
    // PHP-rendered fallback (no sessionStorage, page reload, direct URL) we
    // must transpose to the requested root (defaulting to C) so the fretboard
    // shows the right fret positions rather than the raw archetype data.
    if ( $chord && ( $chord->voicing_category ?? '' ) === 'rootless' && ! $diagram_id ) {
        // $diagram_root is already 'C' (or whatever root came from the URL).
        $rootless_display_root = $diagram_root ?: 'C';

        if ( ! class_exists( 'SBN_Chord_Shape_Calculator' ) ) {
            require_once SBN_PLUGIN_DIR . 'inc/chord-shapes/class-chord-shape-calculator.php';
        }

        $calc       = new SBN_Chord_Shape_Calculator();
        $transposed = $calc->calculate_frets( $chord, $rootless_display_root );

        if ( $transposed && ! empty( $transposed['diagram_data'] ) ) {
            $hero_diagram_data    = is_string( $transposed['diagram_data'] )
                ? $transposed['diagram_data']
                : wp_json_encode( $transposed['diagram_data'] );
            $hero_start_fret      = $transposed['start_fret'];
            $hero_interval_labels = $transposed['interval_labels'];
        }
    }

    // ── Quality metadata ──────────────────────────────────────────────────────
    $quality_label  = sbn_chord_detail_quality_label( $quality );

    // Display name: use actual root if known, else fall back to chord db root, else quality only
    if ( $diagram_root ) {
        // Prefer URL-passed extensions (from the clicked card) over the DB archetype value.
        // The archetype fetched by ORDER BY popularity may have different/no extensions.
        $ext          = $extensions_param !== '' ? $extensions_param : ( $chord->extensions ?? '' );
        $display_name = $diagram_root . $quality . $ext;
    } elseif ( $chord ) {
        $display_name = sbn_chord_detail_display_name( $chord );
    } else {
        $display_name = $quality;
    }

    // Append slash/bass-interval suffix for inverted chords (set in pre-render helpers above).
    if ( $slash_suffix !== '' ) {
        $display_name .= $slash_suffix;
    }

    $theory = sbn_chord_detail_theory( $quality );

    // Educational content (mirrors JS eduContent in chord-library-redesign.js)
    $voicing_cat_for_edu = $chord->voicing_category ?? '';
    $ext_for_edu         = $extensions_param ?: ( $chord->extensions ?? '' );
    $edu = sbn_chord_detail_edu( $quality, $voicing_cat_for_edu, $ext_for_edu );

    // ── Chord siblings (other diagrams of the same quality — top 4 by popularity) ─
    // We fetch one extra (5) so we can exclude the hero and still show up to 4 others.
    $siblings_raw = $wpdb->get_results( $wpdb->prepare(
        "SELECT id, root_note, quality, extensions, voicing_category, inversion, diagram_data, start_fret, interval_labels, popularity
         FROM $diagrams_table
         WHERE quality = %s
         ORDER BY popularity DESC, root_note ASC
         LIMIT 5",
        $quality
    ) );

    // Remove the hero from the list, then keep at most 4 siblings.
    $siblings = array_values( array_filter( $siblings_raw, function( $s ) use ( $diagram_id, $chord ) {
        // Always exclude the currently shown diagram by ID when available.
        if ( $diagram_id && intval( $s->id ) === $diagram_id ) return false;
        // Also exclude by matching DB row when coming from the ?root=+?quality= path.
        if ( $chord && ! $diagram_id && intval( $s->id ) === intval( $chord->id ?? 0 ) ) return false;
        return true;
    } ) );
    $siblings = array_slice( $siblings, 0, 4 );

    // ── Progression matching ──────────────────────────────────────────────────
    $prog_result = SBN_Chord_Progressions::get_progression_for_quality( $quality, $diagram_root );

    // ── Builder data ──────────────────────────────────────────────────────────
    $builder_numerals    = '';
    $builder_key         = $diagram_key ?: 'C';
    $builder_diagram_ids = array();
    $builder_slot        = -1;

    if ( $prog_result ) {
        $prog             = $prog_result['progression'];
        $builder_numerals = $prog->numerals;
        $builder_slot     = $prog_result['best_slot'];

        // Bug 2 fix: derive the progression tonic from the matched slot + chord root.
        // E.g. Ebm7b5 matching IIm7b5 → key = Db, not Eb.
        // Only do this when we have an actual root note; otherwise fall back to diagram_key.
        if ( $diagram_root ) {
            $tokens      = array_values( array_filter( array_map( 'trim', explode( ',', $prog->numerals ) ) ) );
            $hero_token  = $tokens[ $builder_slot ] ?? '';
            $builder_key = $hero_token
                ? sbn_chord_detail_key_from_slot( $diagram_root, $hero_token )
                : ( $diagram_key ?: 'C' );
        } else {
            $builder_key = $diagram_key ?: ( $prog_result['default_key'] ?? 'C' );
        }

        // Pre-seed the hero slot in the builder.
        // ?id= path: use the exact persisted diagram ID.
        // ?root=+?quality= path: use the archetype ID — the builder transposes
        //   it to the correct root via the builder key derived above.
        $seed_id    = $diagram_id ?: ( $chord->id ?? 0 );
        if ( $seed_id && $builder_slot >= 0 ) {
            $token_count = count( explode( ',', $prog->numerals ) );
            $builder_diagram_ids = array_fill( 0, $token_count, 0 );
            if ( $builder_slot < $token_count ) {
                $builder_diagram_ids[ $builder_slot ] = intval( $seed_id );
            }
        }
    }

    // ── Pre-render helpers ────────────────────────────────────────────────────
    $vc_labels  = SBN_Chord_Diagrams::get_voicing_categories();
    $inv_labels = SBN_Chord_Diagrams::INVERSIONS;
    $vc_display  = $chord ? ( $vc_labels[ $chord->voicing_category ?? '' ] ?? ucwords( str_replace( '-', ' ', $chord->voicing_category ?? '' ) ) ) : '';
    $inv_val     = $inversion_param ?: ( $chord->inversion ?? 'root' );
    $inv_display = ( $inv_val && $inv_val !== 'root' ) ? ( $inv_labels[ $inv_val ] ?? $inv_val ) : '';

    // Bass-interval map — mirrors JS bassIntervalLabels in chord-library-redesign.js.
    // Used to append slash notation to the chord name for inverted chords.
    $bass_interval_map = array(
        'maj7'  => array( 'inv1' => '3',  'inv2' => '5',  'inv3' => '7'  ),
        'maj6'  => array( 'inv1' => '3',  'inv2' => '5',  'inv3' => '6'  ),
        'm7'    => array( 'inv1' => '♭3', 'inv2' => '5',  'inv3' => '♭7' ),
        'm6'    => array( 'inv1' => '♭3', 'inv2' => '5',  'inv3' => '6'  ),
        '7'     => array( 'inv1' => '3',  'inv2' => '5',  'inv3' => '♭7' ),
        'dom7'  => array( 'inv1' => '3',  'inv2' => '5',  'inv3' => '♭7' ),
        'm7b5'  => array( 'inv1' => '♭3', 'inv2' => '♭5', 'inv3' => '♭7' ),
        'o7'    => array( 'inv1' => '♭3', 'inv2' => '♭5', 'inv3' => '𝄫7' ),
        'mMaj7' => array( 'inv1' => '♭3', 'inv2' => '5',  'inv3' => '7'  ),
        'aug7'  => array( 'inv1' => '3',  'inv2' => '♯5', 'inv3' => '♭7' ),
        'maj'   => array( 'inv1' => '3',  'inv2' => '5'                   ),
        'min'   => array( 'inv1' => '♭3', 'inv2' => '5'                   ),
        'aug'   => array( 'inv1' => '3',  'inv2' => '♯5'                  ),
        'dim'   => array( 'inv1' => '♭3', 'inv2' => '♭5'                  ),
        'sus4'  => array( 'inv1' => '4',  'inv2' => '5'                   ),
        'sus2'  => array( 'inv1' => '2',  'inv2' => '5'                   ),
        'add9'  => array( 'inv1' => '9',  'inv2' => '3',  'inv3' => '5'  ),
    );

    // Build slash suffix for inverted chords: e.g. "/3" → "/E" using the root.
    // We append the interval label (e.g. /3) — same as the library JS approach.
    $slash_suffix = '';
    if ( $inv_val && $inv_val !== 'root' && $quality ) {
        $bass_iv = $bass_interval_map[ $quality ][ $inv_val ] ?? '';
        if ( $bass_iv !== '' ) {
            $slash_suffix = '/' . $bass_iv;
        }
    }

    // Append slash suffix to display_name (done after display_name is set below).


    // ── Parse actual intervals from the hero diagram's interval_labels ────────
    // interval_labels is a comma-separated string like "x,R,5,7,3,x".
    // We extract the sounding (non-x) values, deduplicate, and order them
    // musically: R first, then by semitone value.
    $interval_order = array( 'R' => 0, 'b2' => 1, '2' => 2, 'b3' => 3, '3' => 4,
                             '4' => 5, '#4' => 6, 'b5' => 6, '5' => 7, '#5' => 8,
                             'b6' => 8, '6' => 9, 'bb7' => 9, 'b7' => 10, '7' => 11,
                             'b9' => 1, '9' => 2, '#9' => 3, '11' => 5, '#11' => 6,
                             'b13' => 8, '13' => 9 );

    $raw_intervals = $hero_interval_labels ? explode( ',', $hero_interval_labels ) : array();
    $sounding = array();
    foreach ( $raw_intervals as $iv ) {
        $iv = trim( $iv );
        if ( $iv !== '' && $iv !== 'x' && $iv !== 'X' && ! in_array( $iv, $sounding ) ) {
            $sounding[] = $iv;
        }
    }
    usort( $sounding, function( $a, $b ) use ( $interval_order ) {
        $oa = $interval_order[ $a ] ?? 99;
        $ob = $interval_order[ $b ] ?? 99;
        return $oa - $ob;
    } );
    // Render each interval with HTML superscript for accidentals:
    // b → ♭ (superscript), # → ♯ (superscript), bb → ♭♭
    function sbn_format_interval( $iv ) {
        if ( $iv === 'R' ) return '<span class="sbn-iv-root">R</span>';
        $iv = str_replace( 'bb', '<sup>♭♭</sup>', $iv );
        $iv = str_replace( 'b',  '<sup>♭</sup>',  $iv );
        $iv = str_replace( '#',  '<sup>♯</sup>',  $iv );
        return '<span class="sbn-iv">' . $iv . '</span>';
    }

    // ── Render ────────────────────────────────────────────────────────────────
    ob_start();
    ?>
    <div class="sbn-chord-detail" data-quality="<?php echo esc_attr( $quality ); ?>" data-diagram-id="<?php echo esc_attr( $diagram_id ); ?>">

        <?php /* ── Navigation ── */ ?>
        <nav class="sbn-chord-detail-nav">
            <a class="sbn-chord-detail-back" href="<?php echo esc_url( $back_url ); ?>">
                <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                    <path d="M10 3L5 8l5 5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                Chord Library
            </a>
        </nav>

        <?php /* ════════════════════════════════════════════════════════
               CHORD IDENTITY PANEL
               Left:  chord name · diagram · intervals · tension
               Right: "About the X chord" · description · accordions
               ════════════════════════════════════════════════════════ */ ?>
        <div class="sbn-chord-identity">

            <?php /* ── Left column ── */ ?>
            <div class="sbn-chord-identity-left">

                <h1 class="sbn-chord-detail-title"><?php echo sbn_format_chord_name( $display_name ); ?></h1>

                <?php if ( $chord ) : ?>
                <div class="sbn-chord-fretboard" id="sbn-hero-fretboard"
                     data-diagram='<?php echo esc_attr( $hero_diagram_data ); ?>'
                     data-start-fret="<?php echo esc_attr( $hero_start_fret ); ?>"
                     data-intervals="<?php echo esc_attr( $hero_interval_labels ); ?>"
                     data-notes=""
                     data-has-root="false">
                </div>
                <?php endif; ?>

                <?php if ( ! empty( $sounding ) ) : ?>
                <div class="sbn-chord-identity-intervals-row">
                    <?php foreach ( $sounding as $iv ) : ?>
                    <?php echo sbn_format_interval( $iv ); ?>
                    <?php endforeach; ?>
                </div>
                <?php elseif ( $theory ) : ?>
                <div class="sbn-chord-identity-intervals-row sbn-iv-fallback">
                    <?php echo esc_html( $theory['intervals'] ); ?>
                </div>
                <?php endif; ?>

                <?php if ( $theory ) : ?>
                <div class="sbn-chord-detail-tension" title="Harmonic tension (0 = stable, 5 = maximum)">
                    <span class="sbn-chord-detail-tension-label">Tension</span>
                    <div class="sbn-chord-detail-tension-dots">
                        <?php for ( $i = 0; $i < 5; $i++ ) : ?>
                            <span class="sbn-tension-dot<?php echo $i < $theory['tension'] ? ' filled' : ''; ?>"></span>
                        <?php endfor; ?>
                    </div>
                </div>
                <?php endif; ?>

            </div><!-- .sbn-chord-identity-left -->

            <?php /* ── Right column ── */ ?>
            <div class="sbn-chord-identity-right">

                <h2 class="sbn-chord-identity-about">
                    About the <?php echo sbn_format_chord_name( $display_name ); ?> chord
                </h2>

                <?php if ( ! empty( $edu['quality_description'] ) ) : ?>
                <p class="sbn-chord-identity-description">
                    <?php echo esc_html( $edu['quality_description'] ); ?>
                    <?php if ( ! empty( $edu['quality_usage'] ) ) : ?>
                    <span class="sbn-chord-identity-usage"><?php echo esc_html( $edu['quality_usage'] ); ?></span>
                    <?php endif; ?>
                </p>
                <?php elseif ( $theory ) : ?>
                <p class="sbn-chord-identity-description"><?php echo esc_html( $theory['typical_context'] ); ?></p>
                <?php endif; ?>

                <?php /* Accordion: voicing type */ ?>
                <?php if ( ! empty( $edu['voicing_name'] ) ) : ?>
                <details class="sbn-accordion">
                    <summary class="sbn-accordion-summary">
                        <span class="sbn-accordion-badge"><?php echo esc_html( $edu['voicing_name'] ); ?></span>
                        Voicing type
                    </summary>
                    <div class="sbn-accordion-body">
                        <p><?php echo esc_html( $edu['voicing_detail'] ); ?></p>
                        <p class="sbn-accordion-tip">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                            <?php echo esc_html( $edu['voicing_tip'] ); ?>
                        </p>
                    </div>
                </details>
                <?php endif; ?>

                <?php /* Accordion: inversion */ ?>
                <?php if ( $inv_display ) :
                    $inv_edu = array(
                        'root' => array( 'desc' => 'The root is the lowest note — the chord in its most grounded, stable form.', 'context' => '' ),
                        'inv1' => array( 'desc' => 'The third is the lowest note. The chord sounds lighter and less anchored — same harmony, different angle.', 'context' => 'Creates smoother bass movement. Instead of jumping by 4ths and 5ths, the bass can move by step. Essential for good voice leading.' ),
                        'inv2' => array( 'desc' => 'The fifth is the lowest note. Slightly less stable than root position — more open and transitional.', 'context' => 'Second inversions connect other chords smoothly. Often used as a passing or transitional voicing.' ),
                        'inv3' => array( 'desc' => 'The seventh is the lowest note. Only possible on 7th chords. Creates a strong pull toward resolution.', 'context' => 'The seventh in the bass typically resolves down by a half step. A powerful voice-leading tool in jazz.' ),
                    );
                    $inv_info = $inv_edu[ $inv_val ] ?? null;
                ?>
                <details class="sbn-accordion">
                    <summary class="sbn-accordion-summary">
                        <span class="sbn-accordion-badge sbn-accordion-badge--inv"><?php echo esc_html( $inv_display ); ?></span>
                        Inversion
                    </summary>
                    <?php if ( $inv_info ) : ?>
                    <div class="sbn-accordion-body">
                        <p><?php echo esc_html( $inv_info['desc'] ); ?></p>
                        <?php if ( $inv_info['context'] ) : ?>
                        <p class="sbn-accordion-context"><?php echo esc_html( $inv_info['context'] ); ?></p>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </details>
                <?php endif; ?>

                <?php /* Accordion: extension */ ?>
                <?php if ( ! empty( $edu['ext_name'] ) ) : ?>
                <details class="sbn-accordion">
                    <summary class="sbn-accordion-summary">
                        <span class="sbn-accordion-badge sbn-accordion-badge--ext"><?php echo esc_html( $edu['ext_name'] ); ?></span>
                        Extension
                    </summary>
                    <div class="sbn-accordion-body">
                        <p><?php echo esc_html( $edu['ext_description'] ); ?></p>
                        <p class="sbn-accordion-context"><?php echo esc_html( $edu['ext_context'] ); ?></p>
                    </div>
                </details>
                <?php endif; ?>

                <?php /* Accordion: related chord types */ ?>
                <?php if ( $theory && ! empty( $theory['related'] ) ) : ?>
                <details class="sbn-accordion">
                    <summary class="sbn-accordion-summary">
                        <span class="sbn-accordion-badge sbn-accordion-badge--related">Related</span>
                        Related chord types
                    </summary>
                    <div class="sbn-accordion-body">
                        <div class="sbn-accordion-related-chips">
                            <?php foreach ( $theory['related'] as $rel ) :
                                $rel_url = add_query_arg( array( 'quality' => $rel ), get_permalink() );
                            ?>
                            <a class="sbn-theory-related-chip" href="<?php echo esc_url( $rel_url ); ?>">
                                <?php echo sbn_format_chord_name( $rel ); ?>
                                <span class="sbn-related-chip-label"><?php echo esc_html( sbn_chord_detail_quality_label( $rel ) ); ?></span>
                            </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </details>
                <?php endif; ?>

            </div><!-- .sbn-chord-identity-right -->
        </div><!-- .sbn-chord-identity -->

        <?php /* ════════════════════════════════════════════════════════
               PROGRESSION BUILDER — full-width middle section
               ════════════════════════════════════════════════════════ */ ?>
        <?php if ( $prog_result ) :
            $prog     = $prog_result['progression'];
            $prog_url = add_query_arg( array( 'id' => $prog->id ), home_url( '/chord-progressions/' ) );
            $tokens   = array_filter( array_map( 'trim', explode( ',', $prog->numerals ) ) );
        ?>
        <div class="sbn-chord-detail-builder-section">

            <h2 class="sbn-chord-detail-section-heading">Chord Progression Example</h2>

            <div class="sbn-chord-detail-prog-meta-row">
                <a class="sbn-chord-detail-prog-name" href="<?php echo esc_url( $prog_url ); ?>"><?php echo esc_html( $prog->name ); ?></a>
                <?php if ( $prog_result['song_count'] > 0 ) : ?>
                <span class="sbn-chord-detail-prog-count"><?php echo intval( $prog_result['song_count'] ); ?> song<?php echo $prog_result['song_count'] === 1 ? '' : 's'; ?></span>
                <?php endif; ?>
            </div>

            <div class="sbn-chord-detail-numerals">
                <?php foreach ( array_values( $tokens ) as $idx => $token ) :
                    $is_hero = ( $idx === $builder_slot );
                ?>
                <span class="sbn-chord-detail-numeral-chip<?php echo $is_hero ? ' sbn-numeral-chip--hero' : ''; ?>">
                    <?php echo sbn_format_numeral( $token ); ?>
                </span>
                <?php endforeach; ?>
            </div>

            <p class="sbn-chord-detail-builder-intro">
                <?php if ( $chord ) : ?>
                    Key of <strong><?php echo esc_html( $builder_key ); ?></strong> — <strong><?php echo sbn_format_chord_name( $display_name ); ?></strong> is highlighted. Swap any chord or change the key.
                <?php else : ?>
                    Key of <strong><?php echo esc_html( $builder_key ); ?></strong>. Swap any chord or change the key to explore.
                <?php endif; ?>
            </p>

            <div id="sbn-chord-detail-builder"
                 data-numerals="<?php echo esc_attr( $builder_numerals ); ?>"
                 data-ajax-url="<?php echo esc_attr( admin_url( 'admin-ajax.php' ) ); ?>"
                 data-nonce="<?php echo esc_attr( wp_create_nonce( 'sbn_prog_builder' ) ); ?>"
                 data-initial-key="<?php echo esc_attr( $builder_key ); ?>"
                 data-diagram-ids="<?php echo esc_attr( wp_json_encode( $builder_diagram_ids ) ); ?>"
                 data-hero-slot="<?php echo esc_attr( $builder_slot >= 0 ? $builder_slot : 0 ); ?>"
                 data-category="<?php echo esc_attr( $prog->category ?? 'jazz' ); ?>"
                 data-tonality="<?php echo esc_attr( $prog->tonality ?? 'both' ); ?>">
            </div>

        </div><!-- .sbn-chord-detail-builder-section -->
        <?php else : ?>
        <p class="sbn-chord-detail-no-prog">No progressions yet for <strong><?php echo esc_html( $quality_label ); ?></strong>. <a href="<?php echo esc_url( admin_url( 'admin.php?page=sbn-chord-progressions' ) ); ?>">Add one →</a></p>
        <?php endif; ?>

        <?php /* ════════════════════════════════════════════════════════
               OTHER VOICINGS — centred
               ════════════════════════════════════════════════════════ */ ?>
        <?php if ( ! empty( $siblings ) ) : ?>
        <div class="sbn-chord-detail-other-voicings">
            <h2 class="sbn-chord-detail-section-title">
                <?php echo $chord ? 'Other ' . esc_html( $quality_label ) . ' Voicings' : esc_html( $quality_label ) . ' Voicings'; ?>
            </h2>
            <div class="sbn-chord-detail-siblings">
                <?php foreach ( $siblings as $sib ) :
                    $sib_ext   = $sib->extensions ?? '';
                    $sib_root  = $sib->root_note  ?? '';
                    $sib_name  = trim( $sib_root . ( $sib->quality ?? '' ) . $sib_ext );
                    $sib_url   = add_query_arg( array( 'id' => $sib->id, 'back' => urlencode( $back_url ) ), get_permalink() );
                    $vc_label  = $vc_labels[ $sib->voicing_category ?? '' ] ?? ucwords( str_replace( '-', ' ', $sib->voicing_category ?? '' ) );
                    $sib_inv   = $sib->inversion ?? 'root';
                    $sib_inv_l = ( $sib_inv && $sib_inv !== 'root' ) ? ( $inv_labels[ $sib_inv ] ?? $sib_inv ) : '';
                ?>
                <a class="sbn-chord-card sbn-chord-card--grid sbn-chord-detail-sibling-card"
                   href="<?php echo esc_url( $sib_url ); ?>"
                   title="<?php echo esc_attr( $sib_name . ( $vc_label ? ' — ' . $vc_label : '' ) ); ?>">
                    <div class="sbn-card-chord-name sbn-chord-name-styled"><?php echo sbn_format_chord_name( $sib_name ); ?></div>
                    <div class="sbn-card-diagram">
                        <div class="sbn-chord-fretboard"
                             data-diagram='<?php echo esc_attr( $sib->diagram_data ); ?>'
                             data-start-fret="<?php echo esc_attr( $sib->start_fret ); ?>"
                             data-intervals="<?php echo esc_attr( $sib->interval_labels ); ?>"
                             data-notes=""
                             data-has-root="false">
                        </div>
                    </div>
                    <?php if ( $vc_label ) : ?>
                    <div class="sbn-chord-detail-sibling-badge">
                        <?php echo esc_html( $vc_label . ( $sib_inv_l ? ' · ' . $sib_inv_l : '' ) ); ?>
                    </div>
                    <?php endif; ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

    </div><!-- .sbn-chord-detail -->

    <script>
    // ── Inject sessionStorage voicing into hero before render (synchronous) ──
    (function() {
        try {
            var stored = sessionStorage.getItem('sbn_chord_voicing');
            sessionStorage.removeItem('sbn_chord_voicing');
            if (!stored) return;
            var v = JSON.parse(stored);
            var fb = document.getElementById('sbn-hero-fretboard');
            if (!fb) return;
            var dd = v.diagramData || v.diagram_data || '';
            if (dd)              fb.setAttribute('data-diagram',    typeof dd === 'string' ? dd : JSON.stringify(dd));
            if (v.startFret)     fb.setAttribute('data-start-fret', String(v.startFret || v.start_fret || '1'));
            if (v.intervals !== undefined) fb.setAttribute('data-intervals', v.intervals || v.interval_labels || '');
            if (v.notes    !== undefined)  fb.setAttribute('data-notes',     v.notes || '');
            window._sbnClickedVoicing = v;
        } catch(e) {}
    })();
    </script>

    <script>
    document.addEventListener('DOMContentLoaded', function() {

        if (typeof SbnChordCard !== 'undefined' && SbnChordCard.hydrateAll) {
            SbnChordCard.hydrateAll();
        }

        var builderEl = document.getElementById('sbn-chord-detail-builder');
        if (!builderEl || typeof SbnProgressionBuilder === 'undefined') return;

        var heroSlot = parseInt(builderEl.dataset.heroSlot || '0');
        var diagramIds = [];
        try { diagramIds = JSON.parse(builderEl.dataset.diagramIds || '[]'); } catch(e) {}
        diagramIds = diagramIds.map(function(id) { return id > 0 ? id : null; });

        SbnProgressionBuilder.init(builderEl, {
            numerals:          builderEl.dataset.numerals,
            key:               builderEl.dataset.initialKey || 'C',
            ajaxUrl:           builderEl.dataset.ajaxUrl,
            nonce:             builderEl.dataset.nonce,
            initialDiagramIds: diagramIds,
            category:          builderEl.dataset.category || '',
            tonality:          builderEl.dataset.tonality || 'both',
        });

        var clickedVoicing = window._sbnClickedVoicing || null;

        builderEl.addEventListener('sbn-pb-ready', function() {

            function syncHeroFromBuilderSlot() {
                var heroFb = document.getElementById('sbn-hero-fretboard');
                if (!heroFb) return;
                var slotEl = builderEl.querySelector('.sbn-pb-slot[data-slot="' + heroSlot + '"]');
                var miniFb = slotEl && slotEl.querySelector('[data-fretboard]');
                if (!miniFb) return;
                var dd = miniFb.getAttribute('data-diagram')    || '';
                var sf = miniFb.getAttribute('data-start-fret') || '1';
                var iv = miniFb.getAttribute('data-intervals')  || '';
                var nt = miniFb.getAttribute('data-notes')      || '';
                if (!dd) return;
                heroFb.setAttribute('data-diagram',    dd);
                heroFb.setAttribute('data-start-fret', sf);
                heroFb.setAttribute('data-intervals',  iv);
                heroFb.setAttribute('data-notes',      nt);
                if (typeof SbnChordCard !== 'undefined' && SbnChordCard.hydrateAll) {
                    SbnChordCard.hydrateAll(heroFb.parentElement);
                }
            }

            if (clickedVoicing) {
                var dd = clickedVoicing.diagramData || clickedVoicing.diagram_data || '';
                if (dd) {
                    builderEl.dispatchEvent(new CustomEvent('sbn-pb-inject', {
                        detail: {
                            slot: heroSlot,
                            voicing: {
                                id:              clickedVoicing.diagramId   || 0,
                                diagram_data:    typeof dd === 'string' ? dd : JSON.stringify(dd),
                                start_fret:      clickedVoicing.startFret   || clickedVoicing.start_fret || 1,
                                interval_labels: clickedVoicing.intervals   || clickedVoicing.interval_labels || '',
                                notes:           clickedVoicing.notes       || '',
                                quality:         clickedVoicing.quality     || '',
                                root:            clickedVoicing.rootNote    || ''
                            }
                        }
                    }));
                    requestAnimationFrame(syncHeroFromBuilderSlot);
                }
            } else {
                syncHeroFromBuilderSlot();
            }
        });
    });
    </script>
    <?php
    return ob_get_clean();
}
