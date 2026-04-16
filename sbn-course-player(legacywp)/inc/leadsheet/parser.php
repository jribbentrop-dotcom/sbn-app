<?php
/**
 * SBN Leadsheet Parser
 * 
 * Parses the shortcode text format into structured data for the React component.
 * 
 * @package SBN_Course_Player
 * @since 6.5.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SBN_Leadsheet_Parser {
    
    /**
     * Parse leadsheet shortcode content into structured data
     * 
     * Supports two formats:
     * 
     * Legacy (single section, backward-compatible):
     *   [sbn_leadsheet title="Song" rhythm="bossa"]
     *   | Am7 | Dm7 | G7 | Cmaj7 |
     *   [/sbn_leadsheet]
     * 
     * Sectioned (v2):
     *   [sbn_leadsheet title="Song" rhythm="bossa"]
     *   [A label="Intro"]
     *   | Am7 | Dm7 |
     *   [B label="Verse" rhythm="samba"]
     *   | G7 | Cmaj7 | G7 | Cmaj7 |
     *   [/sbn_leadsheet]
     * 
     * @param string $content The shortcode content
     * @return array Parsed data structure
     */
    public static function parse($content) {
        $result = array(
            'title' => 'Untitled',
            'composer' => '',
            'key' => 'C',
            'tempo' => 120,
            'timeSignature' => '4/4',
            'displayBeats' => 4,
            'subdivisionsPerBar' => 8,
            'sections' => array(),
            'chordVoicings' => array(),
            'rhythmPattern' => null,
            // Educational content fields
            'description' => '',       // Song-level overview / description
            'harmonyNotes' => '',      // Harmony analysis for the whole song
            'formNotes' => '',         // Form/structure notes
            'voicingNotes' => '',      // General voicing / performance notes
        );
        
        // Parse header attributes [sbn_leadsheet title="..." ...]
        // Handle both single-line and multi-line attribute formats
        if (preg_match('/\[sbn_leadsheet\s*([\s\S]*?)\]/m', $content, $headerMatch)) {
            $attrs = $headerMatch[1];
            // Normalize whitespace in attributes
            $attrs = preg_replace('/\s+/', ' ', $attrs);
            
            if (preg_match('/title="([^"]*)"/', $attrs, $m)) {
                $result['title'] = $m[1];
            }
            if (preg_match('/composer="([^"]*)"/', $attrs, $m)) {
                $result['composer'] = $m[1];
            }
            if (preg_match('/key="([^"]*)"/', $attrs, $m)) {
                $result['key'] = $m[1];
            }
            if (preg_match('/tempo="([^"]*)"/', $attrs, $m)) {
                $result['tempo'] = intval($m[1]);
            }
            if (preg_match('/time="([^"]*)"/', $attrs, $m)) {
                $result['timeSignature'] = $m[1];
            }
            
            // Song-level rhythm pattern (default for all sections)
            if (preg_match('/rhythm="([^"]*)"/', $attrs, $m)) {
                $result['rhythmPattern'] = self::resolve_rhythm_pattern($m[1]);
            }
        }
        
        // Calculate display beats from time signature
        $timeParts = explode('/', $result['timeSignature']);
        $result['displayBeats'] = intval($timeParts[0]);
        
        // ---------------------------------------------------------------
        // Parse chord progression with section support
        // ---------------------------------------------------------------
        // Detect whether content uses section markers like [A], [B label="..."]
        $hasSectionMarkers = preg_match('/^\s*\[[A-Z]\b/m', $content);
        
        // Current section being built
        $currentSection = null;
        
        $lines = explode("\n", $content);
        foreach ($lines as $line) {
            $trimmed = trim($line);
            
            // Skip comments, shortcode open/close, and sub-shortcodes
            if (strpos($trimmed, '<!--') === 0) continue;
            if (preg_match('/^\[\/?(sbn_leadsheet|sbn_voicings|sbn_melody|sbn_repeats|sbn_endings|sbn_info)\b/', $trimmed)) continue;
            
            // Section marker: [A], [B label="Verse"], [C label="Bridge" rhythm="samba"]
            if (preg_match('/^\[([A-Z])\s*(.*?)\]\s*$/', $trimmed, $sectionMatch)) {
                // Save previous section if it has measures
                if ($currentSection !== null) {
                    $result['sections'][] = $currentSection;
                }
                
                $sectionId = $sectionMatch[1];
                $sectionAttrs = $sectionMatch[2];
                
                // Parse section attributes
                $label = $sectionId; // Default label is just the letter
                $sectionRhythm = null;
                $sectionInfo = '';
                $sectionHarmony = '';
                $sectionForm = '';
                $sectionVoicingNotes = '';
                $sectionCollapsed = false;
                
                if (preg_match('/label="([^"]*)"/', $sectionAttrs, $m)) {
                    $label = $m[1];
                }
                if (preg_match('/rhythm="([^"]*)"/', $sectionAttrs, $m)) {
                    $sectionRhythm = self::resolve_rhythm_pattern($m[1]);
                }
                if (preg_match('/info="([^"]*)"/', $sectionAttrs, $m)) {
                    $sectionInfo = $m[1];
                }
                if (preg_match('/harmony="([^"]*)"/', $sectionAttrs, $m)) {
                    $sectionHarmony = $m[1];
                }
                if (preg_match('/form="([^"]*)"/', $sectionAttrs, $m)) {
                    $sectionForm = $m[1];
                }
                if (preg_match('/voicings="([^"]*)"/', $sectionAttrs, $m)) {
                    $sectionVoicingNotes = $m[1];
                }
                if (preg_match('/collapsed="(1|true|yes)"/', $sectionAttrs, $m)) {
                    $sectionCollapsed = true;
                }
                $sectionTonality = '';
                if (preg_match('/tonality="([^"]*)"/', $sectionAttrs, $m)) {
                    $sectionTonality = $m[1];
                }
                $sectionLineBreaks = array();
                if (preg_match('/breaks="([^"]*)"/', $sectionAttrs, $m)) {
                    $parts = explode(',', $m[1]);
                    foreach ($parts as $p) {
                        $n = intval(trim($p));
                        if ($n > 0) $sectionLineBreaks[] = $n;
                    }
                }
                
                $currentSection = array(
                    'id' => $sectionId,
                    'name' => $label,
                    'measures' => array(),
                    'rhythmPattern' => $sectionRhythm, // null = inherit song default
                    'startMeasure' => null, // Will be computed after parsing
                    'lineBreaks' => $sectionLineBreaks,
                    'tonality' => $sectionTonality, // key override for modulations, e.g. "Cm"
                    // Educational content
                    'info' => $sectionInfo,
                    'harmonyNotes' => $sectionHarmony,
                    'formNotes' => $sectionForm,
                    'voicingNotes' => $sectionVoicingNotes,
                    'collapsed' => $sectionCollapsed,
                );
                
                continue;
            }
            
            // Measure line: | Am7 | Dm7 | G7 | Cmaj7 |
            if (strpos($trimmed, '|') === 0 && strpos($trimmed, '|--') !== 0) {
                // Ensure we have a section to put measures in
                if ($currentSection === null) {
                    $currentSection = array(
                        'id' => 'A',
                        'name' => 'Main',
                        'measures' => array(),
                        'rhythmPattern' => null,
                        'startMeasure' => null,
                    );
                }
                
                $measures = array_filter(
                    explode('|', $trimmed),
                    function($m) { return trim($m) !== ''; }
                );
                
                foreach ($measures as $measureStr) {
                    $chordNames = preg_split('/\s+/', trim($measureStr));
                    $chordNames = array_filter($chordNames);
                    
                    if (!empty($chordNames)) {
                        $beatsPerChord = $result['displayBeats'] / count($chordNames);
                        $chords = array();
                        
                        foreach ($chordNames as $name) {
                            $chords[] = array(
                                'name' => $name,
                                'beats' => $beatsPerChord
                            );
                        }
                        
                        $currentSection['measures'][] = array('chords' => $chords);
                    }
                }
            }
        }
        
        // Push the final section
        if ($currentSection !== null && !empty($currentSection['measures'])) {
            $result['sections'][] = $currentSection;
        }
        
        // If no sections were created (empty leadsheet), add a blank Main section
        if (empty($result['sections'])) {
            $result['sections'][] = array(
                'id' => 'A',
                'name' => 'Main',
                'measures' => array(),
                'rhythmPattern' => null,
                'startMeasure' => 0,
            );
        }
        
        // Compute startMeasure for each section (global measure index)
        $globalMeasure = 0;
        foreach ($result['sections'] as &$section) {
            $section['startMeasure'] = $globalMeasure;
            $globalMeasure += count($section['measures']);
        }
        unset($section);
        
        // ---------------------------------------------------------------
        // Parse sub-shortcode blocks (voicings, melody, repeats, endings)
        // ---------------------------------------------------------------
        
        // Parse voicings section [sbn_voicings]...[/sbn_voicings]
        if (preg_match('/\[sbn_voicings\]([\s\S]*?)\[\/sbn_voicings\]/', $content, $voicingsMatch)) {
            $voicingsText = $voicingsMatch[1];
            $voicingLines = array_filter(explode("\n", $voicingsText), 'trim');
            
            foreach ($voicingLines as $line) {
                // Format: ChordName: frets @position (fingers)
                // Example: G7: 3x3453 @3 (102043)
                // High positions use hex: Bbmaj7: x8a9ax @8
                // Per-measure override: Gm7@12.0: 3x336x @1 (or legacy Gm7@12:0: ...)
                // Split on ": " (colon-space) to handle keys containing colons
                $trimmedLine = trim($line);
                $sepPos = strpos($trimmedLine, ': ');
                if ($sepPos === false) continue;
                
                $name = trim(substr($trimmedLine, 0, $sepPos));
                $rest = trim(substr($trimmedLine, $sepPos + 2));
                
                if (!preg_match('/^([x0-9a-fA-F]+)(?:\s*@(\d+))?(?:\s*\(([0-9]+)\))?/', $rest, $m)) continue;
                
                $frets = trim($m[1]);
                $position = isset($m[2]) ? intval($m[2]) : 1;
                $fingers = isset($m[3]) ? $m[3] : '000000';
                
                // Migrate legacy colon-based override keys to dot format
                $name = preg_replace('/(@\d+):(\d+)$/', '$1.$2', $name);
                    
                // Skip legacy #X.Y format entries
                if (preg_match('/^#\d+\.\d+$/', $name)) continue;
                
                // Validate frets: must be exactly 6 chars
                if (strlen($frets) !== 6) continue;
                
                $result['chordVoicings'][$name] = array(
                        'frets' => $frets,
                        'position' => $position,
                        'fingers' => $fingers
                    );
            }
        }
        
        // Parse melody section [sbn_melody]...[/sbn_melody]
        if (preg_match('/\[sbn_melody\]([\s\S]*?)\[\/sbn_melody\]/', $content, $melodyMatch)) {
            $melodyJson = trim($melodyMatch[1]);
            $melody = json_decode($melodyJson, true);
            if (is_array($melody)) {
                $result['melody'] = $melody;
            }
        }
        
        // Parse repeat markers section [sbn_repeats]...[/sbn_repeats]
        if (preg_match('/\[sbn_repeats\]([\s\S]*?)\[\/sbn_repeats\]/', $content, $repeatsMatch)) {
            $repeatsJson = trim($repeatsMatch[1]);
            $repeats = json_decode($repeatsJson, true);
            if (is_array($repeats)) {
                $result['repeatMarkers'] = $repeats;
            }
        }
        
        // Parse volta endings section [sbn_endings]...[/sbn_endings]
        if (preg_match('/\[sbn_endings\]([\s\S]*?)\[\/sbn_endings\]/', $content, $endingsMatch)) {
            $endingsJson = trim($endingsMatch[1]);
            $endings = json_decode($endingsJson, true);
            if (is_array($endings)) {
                $result['voltaEndings'] = $endings;
            }
        }
        
        // Parse song-level info block [sbn_info]...[/sbn_info]
        // Supports sub-fields: description, harmony, form, voicings
        if (preg_match('/\[sbn_info\]([\s\S]*?)\[\/sbn_info\]/', $content, $infoMatch)) {
            $infoText = $infoMatch[1];
            
            // Named sub-sections: [description]...[/description], [harmony]...[/harmony], etc.
            if (preg_match('/\[description\]([\s\S]*?)\[\/description\]/', $infoText, $m)) {
                $result['description'] = trim($m[1]);
            }
            if (preg_match('/\[harmony\]([\s\S]*?)\[\/harmony\]/', $infoText, $m)) {
                $result['harmonyNotes'] = trim($m[1]);
            }
            if (preg_match('/\[form\]([\s\S]*?)\[\/form\]/', $infoText, $m)) {
                $result['formNotes'] = trim($m[1]);
            }
            if (preg_match('/\[voicings\]([\s\S]*?)\[\/voicings\]/', $infoText, $m)) {
                $result['voicingNotes'] = trim($m[1]);
            }
            
            // Fallback: treat entire block as description if no sub-fields
            if (!$result['description'] && !$result['harmonyNotes'] && !$result['formNotes'] && !$result['voicingNotes']) {
                $result['description'] = trim($infoText);
            }
        }
        
        return $result;
    }
    
    /**
     * Resolve a rhythm slug to a pattern data array.
     * Tries the database first, then hardcoded fallbacks.
     * 
     * @param string $slug Rhythm pattern slug
     * @return array|null Pattern data or null
     */
    private static function resolve_rhythm_pattern($slug) {
        if (empty($slug)) return null;
        
        // Try database
        if (class_exists('SBN_Rhythm_Patterns')) {
            $rhythmPatterns = SBN_Rhythm_Patterns::instance();
            $dbPattern = $rhythmPatterns->get_rhythm_data($slug);
            
            if ($dbPattern) {
                return array(
                    'slug'     => $slug,
                    'name'     => $dbPattern['name'],
                    'beats'    => $dbPattern['beats'],
                    'thumb'    => $dbPattern['thumb'],
                    'fingers'  => $dbPattern['fingers'],
                    'percTop'  => $dbPattern['percTop']  ?? 'none',
                    'percBass' => $dbPattern['percBass'] ?? 'none',
                );
            }
        }
        
        // Hardcoded fallbacks for backward compatibility
        $fallbacks = array(
            'bossa' => array('slug' => 'bossa', 'name' => 'Bossa Nova', 'beats' => 8, 'thumb' => 'x..x..x.', 'fingers' => '..x..x.x'),
            'bossa-nova' => array('slug' => 'bossa-nova', 'name' => 'Bossa Nova', 'beats' => 8, 'thumb' => 'x..x..x.', 'fingers' => '..x..x.x'),
            'samba' => array('slug' => 'samba', 'name' => 'Samba', 'beats' => 8, 'thumb' => 'x..x..x.', 'fingers' => '.xx.xx.x'),
        );
        
        return isset($fallbacks[$slug]) ? $fallbacks[$slug] : null;
    }
    
    /**
     * Convert parsed data to JSON for JavaScript
     * 
     * @param array $data Parsed data
     * @return string JSON string
     */
    public static function to_json($data) {
        return wp_json_encode($data);
    }
    
    /**
     * Parse and return JSON in one step
     * 
     * @param string $content Shortcode content
     * @return string JSON string
     */
    public static function parse_to_json($content) {
        return self::to_json(self::parse($content));
    }
}
