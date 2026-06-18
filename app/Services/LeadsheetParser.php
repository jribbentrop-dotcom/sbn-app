<?php

namespace App\Services;

use App\Models\RhythmPattern;

/**
 * SBN Leadsheet Parser
 *
 * Parses the shortcode text format into structured data for the frontend component.
 * Ported from WordPress inc/leadsheet/parser.php (Phase 5).
 *
 * Supports two formats:
 *
 * Legacy (single section):
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
 */
class LeadsheetParser
{
    /**
     * Parse leadsheet shortcode content into structured data.
     *
     * @param  string  $content  The shortcode content (full block including [sbn_leadsheet]...[/sbn_leadsheet])
     * @return array  Parsed data structure
     */
    public function parse(string $content): array
    {
        $result = [
            'title'              => 'Untitled',
            'composer'           => '',
            'key'                => 'C',
            'tempo'              => 120,
            'timeSignature'      => '4/4',
            'displayBeats'       => 4,
            'subdivisionsPerBar' => 8,
            'sections'           => [],
            'chordVoicings'      => [],
            'rhythmPattern'      => null,
            // Educational content fields
            'description'        => '',
            'harmonyNotes'       => '',
            'formNotes'          => '',
            'voicingNotes'       => '',
        ];

        // Parse header attributes [sbn_leadsheet title="..." ...]
        if (preg_match('/\[sbn_leadsheet\s*([\s\S]*?)\]/m', $content, $headerMatch)) {
            $attrs = preg_replace('/\s+/', ' ', $headerMatch[1]);

            if (preg_match('/title="([^"]*)"/', $attrs, $m))    $result['title'] = $m[1];
            if (preg_match('/composer="([^"]*)"/', $attrs, $m)) $result['composer'] = $m[1];
            if (preg_match('/key="([^"]*)"/', $attrs, $m))      $result['key'] = $m[1];
            if (preg_match('/tempo="([^"]*)"/', $attrs, $m))    $result['tempo'] = (int) $m[1];
            if (preg_match('/time="([^"]*)"/', $attrs, $m))     $result['timeSignature'] = $m[1];

            // Song-level rhythm pattern (default for all sections)
            if (preg_match('/rhythm="([^"]*)"/', $attrs, $m)) {
                $result['rhythmPattern'] = $this->resolveRhythmPattern($m[1]);
            }
        }

        // Calculate display beats from time signature
        $timeParts = explode('/', $result['timeSignature']);
        $result['displayBeats'] = (int) ($timeParts[0] ?? 4);

        // ---------------------------------------------------------------
        // Parse chord progression with section support
        // ---------------------------------------------------------------
        $currentSection = null;

        $lines = explode("\n", $content);
        foreach ($lines as $line) {
            $trimmed = trim($line);

            // Skip comments, shortcode open/close, and sub-shortcodes
            if (str_starts_with($trimmed, '<!--')) continue;
            if (preg_match('/^\[\/?(sbn_leadsheet|sbn_voicings|sbn_melody|sbn_repeats|sbn_endings|sbn_info)\b/', $trimmed)) continue;

            // Section marker: [A], [B label="Verse"], [C label="Bridge" rhythm="samba"]
            if (preg_match('/^\[([A-Z])\s*(.*?)\]\s*$/', $trimmed, $sectionMatch)) {
                // Save previous section if it has measures
                if ($currentSection !== null) {
                    $result['sections'][] = $currentSection;
                }

                $sectionId    = $sectionMatch[1];
                $sectionAttrs = $sectionMatch[2];

                // Parse section attributes
                $label             = $sectionId;
                $sectionRhythm     = null;
                $sectionInfo       = '';
                $sectionHarmony    = '';
                $sectionForm       = '';
                $sectionVoicingNotes = '';
                $sectionCollapsed  = false;
                $sectionTonality   = '';
                $sectionLineBreaks = [];

                if (preg_match('/label="([^"]*)"/', $sectionAttrs, $m))    $label = $m[1];
                if (preg_match('/rhythm="([^"]*)"/', $sectionAttrs, $m))   $sectionRhythm = $this->resolveRhythmPattern($m[1]);
                if (preg_match('/info="([^"]*)"/', $sectionAttrs, $m))     $sectionInfo = $m[1];
                if (preg_match('/harmony="([^"]*)"/', $sectionAttrs, $m))  $sectionHarmony = $m[1];
                if (preg_match('/form="([^"]*)"/', $sectionAttrs, $m))     $sectionForm = $m[1];
                if (preg_match('/voicings="([^"]*)"/', $sectionAttrs, $m)) $sectionVoicingNotes = $m[1];
                if (preg_match('/collapsed="(1|true|yes)"/', $sectionAttrs)) $sectionCollapsed = true;
                if (preg_match('/tonality="([^"]*)"/', $sectionAttrs, $m)) $sectionTonality = $m[1];
                if (preg_match('/breaks="([^"]*)"/', $sectionAttrs, $m)) {
                    foreach (explode(',', $m[1]) as $p) {
                        $n = (int) trim($p);
                        if ($n > 0) $sectionLineBreaks[] = $n;
                    }
                }

                $currentSection = [
                    'id'            => $sectionId,
                    'name'          => $label,
                    'measures'      => [],
                    'rhythmPattern' => $sectionRhythm,
                    'startMeasure'  => null,
                    'lineBreaks'    => $sectionLineBreaks,
                    'tonality'      => $sectionTonality,
                    // Educational content
                    'info'          => $sectionInfo,
                    'harmonyNotes'  => $sectionHarmony,
                    'formNotes'     => $sectionForm,
                    'voicingNotes'  => $sectionVoicingNotes,
                    'collapsed'     => $sectionCollapsed,
                ];

                continue;
            }

            // Measure line: | Am7 | Dm7 | G7 | Cmaj7 |
            if (str_starts_with($trimmed, '|') && !str_starts_with($trimmed, '|--')) {
                // Ensure we have a section
                if ($currentSection === null) {
                    $currentSection = [
                        'id'            => 'A',
                        'name'          => 'Main',
                        'measures'      => [],
                        'rhythmPattern' => null,
                        'startMeasure'  => null,
                    ];
                }

                // Split on bar lines. Keep all segments including whitespace-only
                // ones (empty bars / pickup bars) so measure indices stay aligned
                // with the JS model built from json_data.
                $rawSegments = explode('|', $trimmed);
                // Drop only the first and last if they're empty (artifact of leading/trailing |)
                if (count($rawSegments) >= 2 && trim($rawSegments[0]) === '') {
                    array_shift($rawSegments);
                }
                if (count($rawSegments) >= 1 && trim($rawSegments[count($rawSegments) - 1]) === '') {
                    array_pop($rawSegments);
                }
                $measures = $rawSegments;

                foreach ($measures as $measureStr) {
                    $chordNames = array_values(array_filter(preg_split('/\s+/', trim($measureStr))));

                    if (!empty($chordNames)) {
                        $beatsPerChord = $result['displayBeats'] / count($chordNames);
                        $chords = [];

                        foreach ($chordNames as $name) {
                            $chords[] = [
                                'name'  => $name,
                                'beats' => $beatsPerChord,
                            ];
                        }

                        $currentSection['measures'][] = ['chords' => $chords];
                    } else {
                        // Preserve empty bars (e.g. pickup bars) so measure indices
                        // stay aligned with the JS model built from json_data.
                        $currentSection['measures'][] = ['chords' => []];
                    }
                }
            }
        }

        // Push the final section
        if ($currentSection !== null && !empty($currentSection['measures'])) {
            $result['sections'][] = $currentSection;
        }

        // If no sections were created, add a blank Main section
        if (empty($result['sections'])) {
            $result['sections'][] = [
                'id'            => 'A',
                'name'          => 'Main',
                'measures'      => [],
                'rhythmPattern' => null,
                'startMeasure'  => 0,
            ];
        }

        // Compute startMeasure for each section
        $globalMeasure = 0;
        foreach ($result['sections'] as &$section) {
            $section['startMeasure'] = $globalMeasure;
            $globalMeasure += count($section['measures']);
        }
        unset($section);

        // ---------------------------------------------------------------
        // Parse sub-shortcode blocks
        // ---------------------------------------------------------------

        // Parse voicings section [sbn_voicings]...[/sbn_voicings]
        if (preg_match('/\[sbn_voicings\]([\s\S]*?)\[\/sbn_voicings\]/', $content, $voicingsMatch)) {
            $voicingsText = $voicingsMatch[1];

            foreach (array_filter(explode("\n", $voicingsText), 'trim') as $vLine) {
                $trimmedLine = trim($vLine);
                $sepPos = strpos($trimmedLine, ': ');
                if ($sepPos === false) continue;

                $name = trim(substr($trimmedLine, 0, $sepPos));
                $rest = trim(substr($trimmedLine, $sepPos + 2));

                if (!preg_match('/^([x0-9a-fA-F]+)(?:\s*@(\d+))?(?:\s*\(([0-9]+)\))?/', $rest, $m)) continue;

                $frets    = trim($m[1]);
                $position = isset($m[2]) ? (int) $m[2] : 1;
                $fingers  = $m[3] ?? '000000';

                // Migrate legacy colon-based override keys to dot format
                $name = preg_replace('/(@\d+):(\d+)$/', '$1.$2', $name);

                // Skip legacy #X.Y format entries
                if (preg_match('/^#\d+\.\d+$/', $name)) continue;

                // Validate frets: must be exactly 6 chars
                if (strlen($frets) !== 6) continue;

                $result['chordVoicings'][$name] = [
                    'frets'    => $frets,
                    'position' => $position,
                    'fingers'  => $fingers,
                ];
            }
        }

        // Parse melody section [sbn_melody]...[/sbn_melody]
        if (preg_match('/\[sbn_melody\]([\s\S]*?)\[\/sbn_melody\]/', $content, $melodyMatch)) {
            $melody = json_decode(trim($melodyMatch[1]), true);
            if (is_array($melody)) {
                $result['melody'] = $melody;
            }
        }

        // Parse repeat markers [sbn_repeats]...[/sbn_repeats]
        if (preg_match('/\[sbn_repeats\]([\s\S]*?)\[\/sbn_repeats\]/', $content, $repeatsMatch)) {
            $repeats = json_decode(trim($repeatsMatch[1]), true);
            if (is_array($repeats)) {
                $result['repeatMarkers'] = $repeats;
            }
        }

        // Parse volta endings [sbn_endings]...[/sbn_endings]
        if (preg_match('/\[sbn_endings\]([\s\S]*?)\[\/sbn_endings\]/', $content, $endingsMatch)) {
            $endings = json_decode(trim($endingsMatch[1]), true);
            if (is_array($endings)) {
                $result['voltaEndings'] = $endings;
            }
        }

        // Parse song-level info block [sbn_info]...[/sbn_info]
        if (preg_match('/\[sbn_info\]([\s\S]*?)\[\/sbn_info\]/', $content, $infoMatch)) {
            $infoText = $infoMatch[1];

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

            // Fallback: entire block as description if no sub-fields
            if (!$result['description'] && !$result['harmonyNotes'] && !$result['formNotes'] && !$result['voicingNotes']) {
                $result['description'] = trim($infoText);
            }
        }

        if (empty($result['chordVoicings'])) {
            $result['chordVoicings'] = (object) [];
        }
        
        return $result;
    }

    /**
     * Parse shortcode content and return as JSON string.
     */
    public function parseToJson(string $content): string
    {
        return json_encode($this->parse($content));
    }

    /**
     * Resolve a rhythm slug to pattern data.
     * Tries the database first, then hardcoded fallbacks.
     *
     * @param  string  $slug
     * @return array|null
     */
    protected function resolveRhythmPattern(string $slug): ?array
    {
        if (empty($slug)) {
            return null;
        }

        // Try database via Eloquent (supports numeric ID or legacy slug)
        $pattern = is_numeric($slug)
            ? RhythmPattern::find($slug)
            : RhythmPattern::where('slug', $slug)->first();

        if ($pattern) {
            return [
                'slug'     => $slug,
                'name'     => $pattern->name,
                'beats'    => $pattern->beats,
                'thumb'    => $pattern->thumb_pattern,
                'fingers'  => $pattern->rhythm_pattern,
                'percTop'  => $pattern->perc_top ?? 'none',
                'percBass' => $pattern->perc_bass ?? 'none',
            ];
        }

        // Hardcoded fallbacks for backward compatibility
        $fallbacks = [
            'bossa'      => ['slug' => 'bossa',      'name' => 'Bossa Nova', 'beats' => 8, 'thumb' => 'x..x..x.', 'fingers' => '..x..x.x'],
            'bossa-nova' => ['slug' => 'bossa-nova', 'name' => 'Bossa Nova', 'beats' => 8, 'thumb' => 'x..x..x.', 'fingers' => '..x..x.x'],
            'samba'      => ['slug' => 'samba',      'name' => 'Samba',      'beats' => 8, 'thumb' => 'x..x..x.', 'fingers' => '.xx.xx.x'],
        ];

        return $fallbacks[$slug] ?? null;
    }
}
