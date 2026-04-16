<?php
/**
 * SBN Chord Shape Calculator - FIXED VERSION
 * 
 * Computes fret positions from chord shape formulas and root notes.
 * This allows storing one shape (e.g., "maj7-drop2-roota") and generating
 * all 12 root variations (Cmaj7, Dbmaj7, etc.) dynamically.
 * 
 * INTERNAL STRING NUMBERING (inverted from guitar convention):
 * String 1 = Low E (guitar 6th / thickest / leftmost in diagram)
 * String 2 = A (guitar 5th)
 * String 3 = D (guitar 4th)
 * String 4 = G (guitar 3rd)
 * String 5 = B (guitar 2nd)
 * String 6 = High E (guitar 1st / thinnest / rightmost in diagram)
 * 
 * WARNING: This numbering is used in all stored diagram_data JSON.
 * Do NOT renumber without a full database migration.
 * 
 * @package SBN_Course_Player
 * @since 7.0.1
 */

if (!defined('ABSPATH')) exit;

class SBN_Chord_Shape_Calculator {
    
    /**
     * Standard tuning (string number => semitone relative to C)
     * 
     * INTERNAL NUMBERING (inverted from guitar convention):
     * String 1 = Low E (guitar 6th string / thickest / leftmost in diagram)
     * String 2 = A (guitar 5th string)
     * String 3 = D (guitar 4th string)
     * String 4 = G (guitar 3rd string)
     * String 5 = B (guitar 2nd string)
     * String 6 = High E (guitar 1st string / thinnest / rightmost in diagram)
     * 
     * This numbering is inverted from standard guitar convention (where
     * string 1 = thinnest) but is consistent throughout the calculator,
     * diagram data, and rendering pipeline. Do NOT change without migrating
     * all existing diagram_data in the database.
     */
    private $standard_tuning = array(
        1 => 4,  // Low E (6th guitar string)
        2 => 9,  // A (5th guitar string)
        3 => 2,  // D (4th guitar string)
        4 => 7,  // G (3rd guitar string)
        5 => 11, // B (2nd guitar string)
        6 => 4   // High E (1st guitar string)
    );
    
    /**
     * Note name to semitone mapping (within octave)
     */
    private $note_to_semitone = array(
        'C' => 0, 'C#' => 1, 'Db' => 1,
        'D' => 2, 'D#' => 3, 'Eb' => 3,
        'E' => 4,
        'F' => 5, 'F#' => 6, 'Gb' => 6,
        'G' => 7, 'G#' => 8, 'Ab' => 8,
        'A' => 9, 'A#' => 10, 'Bb' => 10,
        'B' => 11
    );
    
    /**
     * Semitone to note name (sharp notation)
     */
    private $semitone_to_note = array(
        0 => 'C', 1 => 'C#', 2 => 'D', 3 => 'D#', 4 => 'E', 5 => 'F',
        6 => 'F#', 7 => 'G', 8 => 'G#', 9 => 'A', 10 => 'A#', 11 => 'B'
    );
    
    /**
     * Calculate fret positions from a chord shape and root note
     * 
     * @param object $shape Chord shape object from database
     * @param string $root_note Root note (e.g., 'C', 'F#', 'Bb')
     * @return array Calculated diagram data with absolute fret positions
     */
    public function calculate_frets($shape, $root_note) {
        // Validate root note
        if (!isset($this->note_to_semitone[$root_note])) {
            error_log("SBN Chord Shapes: Invalid root note: $root_note");
            return $this->get_empty_diagram();
        }
        
        $root_semitone = $this->note_to_semitone[$root_note];
        
        // Parse shape data
        $shape_data = json_decode($shape->diagram_data, true);
        if (!$shape_data || !isset($shape_data['positions'])) {
            error_log("SBN Chord Shapes: Invalid shape data for shape ID: {$shape->id}");
            return $this->get_empty_diagram();
        }
        
        // Get root string number
        $root_string = $this->parse_root_string($shape->root_string);
        if (!$root_string) {
            error_log("SBN Chord Shapes: Invalid root string: {$shape->root_string}");
            return $this->get_empty_diagram();
        }
        
        // Get inversion (default to 'root' if not specified)
        $inversion = $shape->inversion ?? 'root';
        
        // Calculate target interval based on inversion
        $target_interval_semitones = $this->get_inversion_interval($shape->quality, $inversion);
        if ($target_interval_semitones === null) {
            error_log("SBN Chord Shapes: Invalid inversion '$inversion' for quality '{$shape->quality}'");
            return $this->get_empty_diagram();
        }
        
        // Calculate target note (root + interval)
        $target_bass_note_semitone = ($root_semitone + $target_interval_semitones) % 12;
        
        // Find lowest position in shape data (the bass note)
        // This needs to consider both fretted positions AND open strings
        $open_strings_in_shape = $shape_data['open'] ?? array();
        $bass_pos = $this->find_lowest_position($shape_data['positions'], $open_strings_in_shape);
        if (!$bass_pos) {
            error_log("SBN Chord Shapes: No positions found in shape {$shape->slug}");
            return $this->get_empty_diagram();
        }
        
        // Calculate where the bass note SHOULD be on its string
        $target_bass_fret = $this->calculate_root_fret($bass_pos['string'], $target_bass_note_semitone);
        
        // Calculate the note that's currently at the stored bass position
        $stored_fret = $bass_pos['fret'];
        $stored_semitone = ($this->standard_tuning[$bass_pos['string']] + $stored_fret) % 12;
        
        // Calculate offset needed to move from stored position to target position
        $fret_offset = $target_bass_fret - $stored_fret;
        
        // Check if this offset would produce negative frets
        // If so, try the next octave up (+ 12 frets)
        $min_fret_in_shape = PHP_INT_MAX;
        foreach ($shape_data['positions'] as $pos) {
            $potential_fret = $pos['fret'] + $fret_offset;
            if ($potential_fret < $min_fret_in_shape) {
                $min_fret_in_shape = $potential_fret;
            }
        }
        // Also check open strings
        foreach ($open_strings_in_shape as $string) {
            $potential_fret = 0 + $fret_offset;
            if ($potential_fret < $min_fret_in_shape) {
                $min_fret_in_shape = $potential_fret;
            }
        }
        
        if ($min_fret_in_shape < 0) {
            // Shift up one octave to keep all frets positive
            $target_bass_fret += 12;
            $fret_offset = $target_bass_fret - $stored_fret;
            error_log("SBN Calculator: Negative frets detected, shifting up 12 frets. New offset: $fret_offset");
        }
        
        // Debug logging
        $bass_note_name = $this->semitone_to_note[$target_bass_note_semitone];
        error_log("SBN Calculator: Shape {$shape->slug} -> Root: $root_note, Inversion: $inversion");
        error_log("  Bass note should be: $bass_note_name (interval: +{$target_interval_semitones} semitones)");
        error_log("  Bass string: {$bass_pos['string']} (open = {$this->standard_tuning[$bass_pos['string']]} semitones)");
        error_log("  Stored fret: $stored_fret (note: " . $this->semitone_to_note[$stored_semitone] . ")");
        error_log("  Target fret: $target_bass_fret (note: $bass_note_name = $target_bass_note_semitone semitones)");
        error_log("  Offset: $fret_offset frets");
        
        // Check if original shape had any open strings
        // They could be in positions with fret=0 OR in the open array
        $had_open_strings = false;
        
        // Check positions array
        foreach ($shape_data['positions'] as $pos) {
            if ($pos['fret'] === 0) {
                $had_open_strings = true;
                break;
            }
        }
        
        // Check open array
        if (!$had_open_strings && !empty($shape_data['open'])) {
            $had_open_strings = true;
        }
        
        if ($had_open_strings) {
            error_log("  Shape has open strings - will reassign fingerings upon transposition");
        }
        
        // Build calculated diagram with absolute positions
        $calculated_positions = array();
        $open_strings = array(); // Track which strings become open
        
        // Process fretted positions
        foreach ($shape_data['positions'] as $pos) {
            $new_fret = $pos['fret'] + $fret_offset;
            $original_finger = $pos['finger'] ?? null;
            $new_finger = $original_finger;
            
            // If this position becomes an open string (fret 0)
            if ($new_fret === 0) {
                $open_strings[] = $pos['string'];
                $new_finger = null; // Open strings have no finger
            }
            // If original shape had open strings and we're transposing
            elseif ($had_open_strings && $fret_offset !== 0 && $original_finger !== null) {
                // Reassign fingerings:
                // - Open strings (fret 0) become finger 1
                // - Finger 1, 2, 3 become 2, 3, 4
                // - Finger 4 stays as 4
                if ($pos['fret'] === 0) {
                    // This was an open string, now needs finger 1
                    $new_finger = 1;
                } elseif ($original_finger < 4) {
                    // Shift fingers up by 1
                    $new_finger = $original_finger + 1;
                }
                // Finger 4 stays as 4
            }
            
            $calculated_positions[] = array(
                'string' => $pos['string'],
                'fret' => $new_fret,
                'finger' => $new_finger
            );
        }
        
        // Process strings that were originally in the 'open' array
        // These need to be transposed too!
        $original_open_strings = $shape_data['open'] ?? array();
        foreach ($original_open_strings as $string) {
            $new_fret = 0 + $fret_offset; // Open string (fret 0) + offset
            
            // If still open after transposition
            if ($new_fret === 0) {
                $open_strings[] = $string;
                // Don't add to positions - stays in open array
            } else {
                // Was open, now fretted - assign finger 1
                $calculated_positions[] = array(
                    'string' => $string,
                    'fret' => $new_fret,
                    'finger' => ($had_open_strings && $fret_offset !== 0) ? 1 : null
                );
            }
        }
        
        // Calculate barres with offset
        $calculated_barres = array();
        if (isset($shape_data['barres']) && !empty($shape_data['barres'])) {
            foreach ($shape_data['barres'] as $barre) {
                $new_barre_fret = $barre['fret'] + $fret_offset;
                $original_finger = $barre['finger'] ?? null;
                $new_finger = $original_finger;
                
                // Adjust barre finger if we had open strings
                if ($had_open_strings && $fret_offset !== 0 && $original_finger !== null && $original_finger < 4) {
                    $new_finger = $original_finger + 1;
                }
                
                $calculated_barres[] = array(
                    'fret' => $new_barre_fret,
                    'fromString' => $barre['fromString'],
                    'toString' => $barre['toString'],
                    'finger' => $new_finger
                );
            }
        }
        
        // Build calculated data with open strings marked
        $calculated_data = array(
            'positions' => $calculated_positions,
            'barres' => $calculated_barres,
            'muted' => $shape_data['muted'] ?? array(),
            'open' => $open_strings // Mark any strings that are now at fret 0
        );
        
        // Calculate start fret (lowest fret in the diagram)
        $start_fret = $this->calculate_start_fret($calculated_data);
        
        // Calculate note names
        $notes = $this->calculate_note_names($calculated_positions);
        
        return array(
            'diagram_data' => $calculated_data,
            'start_fret' => $start_fret,
            'interval_labels' => $shape->interval_labels ?? '',
            'notes' => $notes
        );
    }
    
    /**
     * Calculate root fret position on a given string
     * 
     * @param int $string String number (1-6)
     * @param int $target_semitone Target semitone (0-11)
     * @return int Fret number (0-11)
     */
    private function calculate_root_fret($string, $target_semitone) {
        $open_semitone = $this->standard_tuning[$string];
        
        // Calculate fret distance within one octave
        $fret = ($target_semitone - $open_semitone + 12) % 12;
        
        return $fret;
    }
    
    /**
     * Find the root position in positions array
     * 
     * @param array $positions Shape positions
     * @param int $root_string Root string number
     * @return array|null Root position or null
     */
    private function find_root_position($positions, $root_string) {
        foreach ($positions as $pos) {
            if ($pos['string'] == $root_string) {
                return $pos;
            }
        }
        return null;
    }
    
    /**
     * Find the lowest position (bass note) in positions array
     * 
     * This needs to consider BOTH fretted positions AND open strings.
     * Open strings might be stored in the 'open' array, not in 'positions'.
     * 
     * @param array $positions Shape positions
     * @param array $open_strings Array of open string numbers (optional)
     * @return array|null Lowest position or null
     */
    private function find_lowest_position($positions, $open_strings = array()) {
        if (empty($positions) && empty($open_strings)) {
            return null;
        }
        
        $lowest = null;
        $lowest_pitch = PHP_INT_MAX;
        
        // Check fretted positions
        foreach ($positions as $pos) {
            // Calculate actual pitch (lower string number = lower pitch)
            // String 1 (low E) is lowest, String 6 (high E) is highest
            $open_semitone = $this->standard_tuning[$pos['string']];
            $pitch = ($pos['string'] * 100) + $open_semitone + $pos['fret'];
            
            if ($pitch < $lowest_pitch) {
                $lowest_pitch = $pitch;
                $lowest = $pos;
            }
        }
        
        // Check open strings (fret = 0)
        foreach ($open_strings as $string) {
            $open_semitone = $this->standard_tuning[$string];
            $pitch = ($string * 100) + $open_semitone + 0; // Fret 0
            
            if ($pitch < $lowest_pitch) {
                $lowest_pitch = $pitch;
                $lowest = array(
                    'string' => $string,
                    'fret' => 0,
                    'finger' => null
                );
            }
        }
        
        return $lowest;
    }
    
    /**
     * Get interval in semitones for a given inversion
     * 
     * @param string $quality Chord quality (maj7, m7, 7, etc.)
     * @param string $inversion Inversion (root, inv1, inv2, inv3)
     * @return int|null Interval in semitones from root, or null if invalid
     */
    private function get_inversion_interval($quality, $inversion) {
        // Interval formulas for different chord qualities
        $intervals = array(
            'maj7'   => array('root' => 0, 'inv1' => 4, 'inv2' => 7, 'inv3' => 11), // 1, 3, 5, 7
            'maj6'   => array('root' => 0, 'inv1' => 4, 'inv2' => 7, 'inv3' => 9),  // 1, 3, 5, 6
            'm7'     => array('root' => 0, 'inv1' => 3, 'inv2' => 7, 'inv3' => 10), // 1, b3, 5, b7
            'm6'     => array('root' => 0, 'inv1' => 3, 'inv2' => 7, 'inv3' => 9),  // 1, b3, 5, 6
            '7'      => array('root' => 0, 'inv1' => 4, 'inv2' => 7, 'inv3' => 10), // 1, 3, 5, b7 (dominant 7)
            'dom7'   => array('root' => 0, 'inv1' => 4, 'inv2' => 7, 'inv3' => 10), // 1, 3, 5, b7 (alias for dominant 7)
            'm7b5'   => array('root' => 0, 'inv1' => 3, 'inv2' => 6, 'inv3' => 10), // 1, b3, b5, b7
            'o7'     => array('root' => 0, 'inv1' => 3, 'inv2' => 6, 'inv3' => 9),  // 1, b3, b5, bb7
            'mMaj7'  => array('root' => 0, 'inv1' => 3, 'inv2' => 7, 'inv3' => 11), // 1, b3, 5, 7
            'aug7'   => array('root' => 0, 'inv1' => 4, 'inv2' => 8, 'inv3' => 10), // 1, 3, #5, b7
            'maj'    => array('root' => 0, 'inv1' => 4, 'inv2' => 7),                // 1, 3, 5 (triads)
            'min'    => array('root' => 0, 'inv1' => 3, 'inv2' => 7),                // 1, b3, 5
        );
        
        // Check if we have intervals defined for this quality
        if (!isset($intervals[$quality])) {
            error_log("SBN Calculator: No interval map for quality '$quality', defaulting to maj7");
            $quality = 'maj7'; // Fallback to maj7
        }
        
        // Get the interval for this inversion
        if (!isset($intervals[$quality][$inversion])) {
            error_log("SBN Calculator: Invalid inversion '$inversion' for quality '$quality'");
            return null;
        }
        
        return $intervals[$quality][$inversion];
    }
    
    /**
     * Calculate start fret (lowest non-zero fret in diagram)
     * 
     * @param array $diagram_data Calculated diagram data
     * @return int Start fret
     */
    private function calculate_start_fret($diagram_data) {
        $min_fret = PHP_INT_MAX;
        
        // Check positions
        foreach ($diagram_data['positions'] as $pos) {
            if ($pos['fret'] > 0 && $pos['fret'] < $min_fret) {
                $min_fret = $pos['fret'];
            }
        }
        
        // Check barres
        if (isset($diagram_data['barres'])) {
            foreach ($diagram_data['barres'] as $barre) {
                if ($barre['fret'] > 0 && $barre['fret'] < $min_fret) {
                    $min_fret = $barre['fret'];
                }
            }
        }
        
        return ($min_fret === PHP_INT_MAX) ? 1 : max(1, $min_fret);
    }
    
    /**
     * Calculate note names for each position
     * 
     * @param array $positions Absolute positions
     * @return string Comma-separated note names
     */
    private function calculate_note_names($positions) {
        $notes = array();
        
        foreach ($positions as $pos) {
            $string = $pos['string'];
            $fret = $pos['fret'];
            
            // Get open string semitone
            $open_semitone = $this->standard_tuning[$string];
            
            // Calculate note semitone
            $note_semitone = ($open_semitone + $fret) % 12;
            
            // Get note name
            $note_name = $this->semitone_to_note[$note_semitone];
            $notes[] = $note_name;
        }
        
        return implode(',', $notes);
    }
    
    /**
     * Parse root string identifier to string number
     * FIXED: Now matches chord diagram convention
     * 
     * @param string $root_string_id Root string identifier (e.g., 'roota', 'roote')
     * @return int|null String number (1-6) or null if invalid
     */
    private function parse_root_string($root_string_id) {
        // Maps to internal string numbers (1=Low E, 6=High E)
        $map = array(
            'roote' => 1,      // Low E (guitar 6th string)
            'roota' => 2,      // A (guitar 5th string)
            'rootd' => 3,      // D (guitar 4th string)
            'rootg' => 4,      // G (guitar 3rd string)
            'rootb' => 5,      // B (guitar 2nd string)
            'roothighe' => 6   // High E (guitar 1st string)
        );
        
        return $map[$root_string_id] ?? null;
    }
    
    /**
     * Analyze a slash chord to determine if it's an inversion or a true slash
     * 
     * Compares the bass note against the chord's interval formula to determine:
     * - Inversion: bass note is a chord tone (e.g., Am/C — C is the b3 of Am)
     * - True slash: bass note is foreign to the chord (e.g., F/G — G is not in F major)
     * 
     * @param string $root_note Root note (e.g., 'A', 'F', 'Ab')
     * @param string $quality Chord quality (e.g., 'min', 'maj', 'dom7')
     * @param string $bass_note Bass note (e.g., 'C', 'G', 'F#')
     * @return array ['type' => 'inversion'|'slash', 'inversion' => 'inv1'|'inv2'|'inv3'|'', 'interval' => int]
     */
    public function analyze_slash_chord($root_note, $quality, $bass_note) {
        $root_semitone = $this->note_to_semitone[$root_note] ?? null;
        $bass_semitone = $this->note_to_semitone[$bass_note] ?? null;
        
        if ($root_semitone === null || $bass_semitone === null) {
            return ['type' => 'slash', 'inversion' => '', 'interval' => -1];
        }
        
        // Calculate interval from root to bass note
        $interval = ($bass_semitone - $root_semitone + 12) % 12;
        
        // Interval formulas for different chord qualities
        // Maps quality → [interval_semitones => inversion_name]
        $chord_tones = array(
            'maj7'   => array(0 => 'root', 4 => 'inv1', 7 => 'inv2', 11 => 'inv3'),
            'maj6'   => array(0 => 'root', 4 => 'inv1', 7 => 'inv2', 9 => 'inv3'),
            'm7'     => array(0 => 'root', 3 => 'inv1', 7 => 'inv2', 10 => 'inv3'),
            'm6'     => array(0 => 'root', 3 => 'inv1', 7 => 'inv2', 9 => 'inv3'),
            'dom7'   => array(0 => 'root', 4 => 'inv1', 7 => 'inv2', 10 => 'inv3'),
            '7'      => array(0 => 'root', 4 => 'inv1', 7 => 'inv2', 10 => 'inv3'),
            'm7b5'   => array(0 => 'root', 3 => 'inv1', 6 => 'inv2', 10 => 'inv3'),
            'o7'     => array(0 => 'root', 3 => 'inv1', 6 => 'inv2', 9 => 'inv3'),
            'mMaj7'  => array(0 => 'root', 3 => 'inv1', 7 => 'inv2', 11 => 'inv3'),
            'aug7'   => array(0 => 'root', 4 => 'inv1', 8 => 'inv2', 10 => 'inv3'),
            'aug'    => array(0 => 'root', 4 => 'inv1', 8 => 'inv2'),
            'maj'    => array(0 => 'root', 4 => 'inv1', 7 => 'inv2'),
            'min'    => array(0 => 'root', 3 => 'inv1', 7 => 'inv2'),
            'dim'    => array(0 => 'root', 3 => 'inv1', 6 => 'inv2'),
            'sus4'   => array(0 => 'root', 5 => 'inv1', 7 => 'inv2'),
            'sus2'   => array(0 => 'root', 2 => 'inv1', 7 => 'inv2'),
            // Extended chords — include common tones
            '9'      => array(0 => 'root', 4 => 'inv1', 7 => 'inv2', 10 => 'inv3', 2 => 'inv1'),
            'maj9'   => array(0 => 'root', 4 => 'inv1', 7 => 'inv2', 11 => 'inv3', 2 => 'inv1'),
            'm9'     => array(0 => 'root', 3 => 'inv1', 7 => 'inv2', 10 => 'inv3', 2 => 'inv1'),
        );
        
        // Check if bass note is a chord tone
        $tones = $chord_tones[$quality] ?? null;
        
        if ($tones !== null && isset($tones[$interval])) {
            $inversion = $tones[$interval];
            
            // If the bass note equals the root, it's just a root position chord
            if ($inversion === 'root') {
                return ['type' => 'inversion', 'inversion' => 'root', 'interval' => 0];
            }
            
            error_log("SBN Calculator: $root_note$quality/$bass_note → inversion ({$inversion}, interval: {$interval} semitones)");
            return ['type' => 'inversion', 'inversion' => $inversion, 'interval' => $interval];
        }
        
        // Bass note is foreign to the chord — true slash chord
        error_log("SBN Calculator: $root_note$quality/$bass_note → true slash chord (interval: {$interval} semitones, not a chord tone)");
        return ['type' => 'slash', 'inversion' => '', 'interval' => $interval];
    }
    
    /**
     * Calculate fret positions for a shape with an explicit bass note
     * 
     * Used for true slash chords (F/G) where the bass note is foreign
     * to the chord. The shape's stored bass_note column indicates the
     * relationship, and we transpose both the chord and bass independently.
     * 
     * @param object $shape Chord shape object from database (must have bass_note set)
     * @param string $root_note Target root note (e.g., 'F')
     * @param string $bass_note Target bass note (e.g., 'G')
     * @return array Calculated diagram data with absolute fret positions
     */
    public function calculate_frets_with_bass($shape, $root_note, $bass_note) {
        // Validate both notes
        if (!isset($this->note_to_semitone[$root_note]) || !isset($this->note_to_semitone[$bass_note])) {
            error_log("SBN Chord Shapes: Invalid root/bass note: $root_note / $bass_note");
            return $this->get_empty_diagram();
        }
        
        $root_semitone = $this->note_to_semitone[$root_note];
        $bass_semitone = $this->note_to_semitone[$bass_note];
        
        // Parse shape data
        $shape_data = json_decode($shape->diagram_data, true);
        if (!$shape_data || !isset($shape_data['positions'])) {
            return $this->get_empty_diagram();
        }
        
        // Find the lowest sounding string in the shape — this is the bass string
        $open_strings_in_shape = $shape_data['open'] ?? array();
        $bass_pos = $this->find_lowest_position($shape_data['positions'], $open_strings_in_shape);
        
        if (!$bass_pos) {
            return $this->get_empty_diagram();
        }
        
        // Calculate where the BASS NOTE should be on its string
        $target_bass_fret = $this->calculate_root_fret($bass_pos['string'], $bass_semitone);
        
        // Calculate offset needed to put the bass note in the right place
        $stored_fret = $bass_pos['fret'];
        $fret_offset = $target_bass_fret - $stored_fret;
        
        // ── Negative-fret guard (same logic as calculate_frets) ──
        $min_fret_in_shape = PHP_INT_MAX;
        foreach ($shape_data['positions'] as $pos) {
            $potential_fret = $pos['fret'] + $fret_offset;
            if ($potential_fret < $min_fret_in_shape) {
                $min_fret_in_shape = $potential_fret;
            }
        }
        foreach ($open_strings_in_shape as $string) {
            $potential_fret = 0 + $fret_offset;
            if ($potential_fret < $min_fret_in_shape) {
                $min_fret_in_shape = $potential_fret;
            }
        }
        
        if ($min_fret_in_shape < 0) {
            $target_bass_fret += 12;
            $fret_offset = $target_bass_fret - $stored_fret;
            error_log("SBN Calculator (slash): Negative frets detected, shifting up 12 frets. New offset: $fret_offset");
        }
        
        error_log("SBN Calculator (slash): Shape {$shape->slug} → Root: $root_note, Bass: $bass_note");
        error_log("  Bass string: {$bass_pos['string']}, target fret: $target_bass_fret, offset: $fret_offset");
        
        // ── Transpose positions ──
        $calculated_positions = array();
        $open_strings = array();
        $had_open_strings = false;
        
        foreach ($shape_data['positions'] as $pos) {
            if ($pos['fret'] === 0) { $had_open_strings = true; break; }
        }
        if (!$had_open_strings && !empty($shape_data['open'])) {
            $had_open_strings = true;
        }
        
        foreach ($shape_data['positions'] as $pos) {
            $new_fret = $pos['fret'] + $fret_offset;
            $original_finger = $pos['finger'] ?? null;
            $new_finger = $original_finger;
            
            if ($new_fret === 0) {
                $open_strings[] = $pos['string'];
                $new_finger = null;
            } elseif ($had_open_strings && $fret_offset !== 0 && $original_finger !== null) {
                if ($pos['fret'] === 0) {
                    $new_finger = 1;
                } elseif ($original_finger < 4) {
                    $new_finger = $original_finger + 1;
                }
            }
            
            $calculated_positions[] = array(
                'string' => $pos['string'],
                'fret' => $new_fret,
                'finger' => $new_finger
            );
        }
        
        // Process originally open strings
        foreach ($open_strings_in_shape as $string) {
            $new_fret = 0 + $fret_offset;
            if ($new_fret === 0) {
                $open_strings[] = $string;
            } else {
                $calculated_positions[] = array(
                    'string' => $string,
                    'fret' => $new_fret,
                    'finger' => ($had_open_strings && $fret_offset !== 0) ? 1 : null
                );
            }
        }
        
        // Calculate barres with offset
        $calculated_barres = array();
        if (isset($shape_data['barres']) && !empty($shape_data['barres'])) {
            foreach ($shape_data['barres'] as $barre) {
                $new_barre_fret = $barre['fret'] + $fret_offset;
                $original_finger = $barre['finger'] ?? null;
                $new_finger = $original_finger;
                
                if ($had_open_strings && $fret_offset !== 0 && $original_finger !== null && $original_finger < 4) {
                    $new_finger = $original_finger + 1;
                }
                
                $calculated_barres[] = array(
                    'fret' => $new_barre_fret,
                    'fromString' => $barre['fromString'],
                    'toString' => $barre['toString'],
                    'finger' => $new_finger
                );
            }
        }
        
        $calculated_data = array(
            'positions' => $calculated_positions,
            'barres' => $calculated_barres,
            'muted' => $shape_data['muted'] ?? array(),
            'open' => $open_strings
        );
        
        $start_fret = $this->calculate_start_fret($calculated_data);
        $notes = $this->calculate_note_names($calculated_positions);
        
        // ── Recompute interval labels relative to the BASS note ──
        // For slash chords like F/G, the bass note (G) is the harmonic
        // reference: F=b7, A=9, C=11 relative to G.
        $generic_intervals = array(
            0 => 'R', 1 => 'b9', 2 => '9', 3 => '#9', 4 => '3', 5 => '11',
            6 => '#11', 7 => '5', 8 => 'b13', 9 => '13', 10 => 'b7', 11 => '7'
        );
        
        // Build per-string fret lookup from transposed positions
        $string_frets = array();
        foreach ($calculated_positions as $pos) {
            $string_frets[intval($pos['string'])] = intval($pos['fret']);
        }
        foreach ($open_strings as $s) {
            if (!isset($string_frets[intval($s)])) {
                $string_frets[intval($s)] = 0;
            }
        }
        
        $muted = $calculated_data['muted'] ?? array();
        $labels = array();
        
        for ($s = 1; $s <= 6; $s++) {
            if (in_array($s, $muted) && !isset($string_frets[$s])) {
                $labels[] = 'x';
            } elseif (isset($string_frets[$s])) {
                $note_semitone = ($this->standard_tuning[$s] + $string_frets[$s]) % 12;
                $interval = ($note_semitone - $bass_semitone + 12) % 12;
                $labels[] = $generic_intervals[$interval];
            } else {
                $labels[] = 'x';
            }
        }
        
        $interval_labels = implode(',', $labels);
        
        return array(
            'diagram_data' => $calculated_data,
            'start_fret' => $start_fret,
            'interval_labels' => $interval_labels,
            'notes' => $notes
        );
    }
    
    /**
     * Get empty diagram structure
     * 
     * @return array Empty diagram
     */
    private function get_empty_diagram() {
        return array(
            'diagram_data' => array(
                'positions' => array(),
                'barres' => array(),
                'muted' => array(),
                'open' => array()
            ),
            'start_fret' => 1,
            'interval_labels' => '',
            'notes' => ''
        );
    }
}
