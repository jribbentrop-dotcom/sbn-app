<?php

namespace App\Services;

/**
 * Leadsheet Scaffolder
 *
 * Builds the json_data + shortcode_content for empty leadsheets.
 * Used by the "Blank sheet" creation flow (L1 of Phase L).
 *
 * Output is parser-symmetric: the shortcode_content, when parsed by
 * LeadsheetParser, produces exactly the same json_data structure.
 */
class LeadsheetScaffolder
{
    /**
     * Build the json_data + shortcode_content for an empty sheet.
     *
     * @param array $opts {
     *   title:          string,
     *   composer:       string,
     *   song_key:       string,
     *   tempo:          int,
     *   time_signature: string,
     *   rhythm:         string,
     *   structure:      array,   // see below
     *   pickup_bar:     bool,
     * }
     *
     * structure (one of):
     *   ['mode' => 'simple', 'bar_count' => int]
     *   ['mode' => 'sectioned', 'sections' => [['name' => string, 'bars' => int], ...]]
     *
     * @return array {
     *   shortcode_content: string,
     *   json_data:         string (JSON-encoded),
     *   measure_count:     int,
     * }
     */
    public function scaffoldBlank(array $opts): array
    {
        $title = $opts['title'] ?? 'Untitled';
        $composer = $opts['composer'] ?? '';
        $songKey = $opts['song_key'] ?? 'C';
        $tempo = $opts['tempo'] ?? 120;
        $timeSignature = $opts['time_signature'] ?? '4/4';
        $rhythm = $opts['rhythm'] ?? '';
        $structure = $opts['structure'] ?? ['mode' => 'simple', 'bar_count' => 16];
        $pickupBar = $opts['pickup_bar'] ?? false;

        // Parse time signature to get beats
        $timeParts = explode('/', $timeSignature);
        $displayBeats = (int) ($timeParts[0] ?? 4);

        // Build sections based on structure mode
        $sections = [];
        $sectionId = 'A';
        $globalMeasure = 0;

        if ($structure['mode'] === 'simple') {
            $barCount = $structure['bar_count'] ?? 16;
            $sections[] = $this->buildSection($sectionId, 'Main', $barCount, $globalMeasure, $displayBeats);
            $globalMeasure += $barCount;
        } elseif ($structure['mode'] === 'sectioned') {
            $sectionDefs = $structure['sections'] ?? [];
            foreach ($sectionDefs as $def) {
                $sections[] = $this->buildSection($sectionId, $def['name'], $def['bars'], $globalMeasure, $displayBeats);
                $globalMeasure += $def['bars'];
                $sectionId = chr(ord($sectionId) + 1);
            }
        }

        // Build json_data structure (matches LeadsheetParser output)
        $jsonData = [
            'title' => $title,
            'composer' => $composer,
            'key' => $songKey,
            'tempo' => $tempo,
            'timeSignature' => $timeSignature,
            'displayBeats' => $displayBeats,
            'subdivisionsPerBar' => 8,
            'sections' => $sections,
            'chordVoicings' => (object) [],
            'rhythmPattern' => null,
            'melody' => $this->buildPlaceholderMelody($globalMeasure, $displayBeats),
            'description' => '',
            'harmonyNotes' => '',
            'formNotes' => '',
            'voicingNotes' => '',
        ];

        // Build shortcode_content
        $shortcode = $this->buildShortcode($title, $composer, $songKey, $tempo, $timeSignature, $rhythm, $sections);

        return [
            'shortcode_content' => $shortcode,
            'json_data' => json_encode($jsonData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'measure_count' => $globalMeasure,
        ];
    }

    /**
     * Build a single section with empty measures.
     */
    protected function buildSection(string $id, string $name, int $barCount, int $startMeasure, int $displayBeats = 4): array
    {
        $measures = [];
        for ($i = 0; $i < $barCount; $i++) {
            // Generate a placeholder chord entry so the editor can render the measure
            $measures[] = [
                'chords' => [
                    [
                        'name' => '?',
                        'beats' => $displayBeats,
                    ]
                ]
            ];
        }

        return [
            'id' => $id,
            'name' => $name,
            'measures' => $measures,
            'rhythmPattern' => null,
            'startMeasure' => $startMeasure,
            'lineBreaks' => [],
            'tonality' => '',
            'info' => '',
            'harmonyNotes' => '',
            'formNotes' => '',
            'voicingNotes' => '',
            'collapsed' => false,
        ];
    }

    /**
     * Build placeholder melody structure for editor rendering.
     * L1 is chord-only, but the editor needs melody data to render the chord view.
     */
    protected function buildPlaceholderMelody(int $measureCount, int $displayBeats): array
    {
        // Add minimal placeholder notes so editor recognizes melody as present
        // Each measure gets one placeholder note at tick 0
        $melody = [];
        $ticksPerBeat = 960; // Standard tick resolution
        $ticksPerMeasure = $ticksPerBeat * $displayBeats;

        for ($i = 0; $i < $measureCount; $i++) {
            $melody[] = [
                'tick' => $i * $ticksPerMeasure,
                'ticks' => $ticksPerMeasure,
                'voice' => 0,
                'isRest' => true,
                'notes' => [
                    [
                        'string' => 1,
                        'fret' => 0,
                        'pitch' => 0,
                        'octave' => 4,
                    ]
                ],
            ];
        }

        return $melody;
    }

    /**
     * Build the shortcode content from the section data.
     * This should parse back to the same json_data structure.
     */
    protected function buildShortcode(string $title, string $composer, string $songKey, int $tempo, string $timeSignature, string $rhythm, array $sections): string
    {
        $lines = [];
        $lines[] = '[sbn_leadsheet title="' . htmlspecialchars($title) . '"';

        if (!empty($composer)) {
            $lines[count($lines) - 1] .= ' composer="' . htmlspecialchars($composer) . '"';
        }
        if (!empty($songKey)) {
            $lines[count($lines) - 1] .= ' key="' . htmlspecialchars($songKey) . '"';
        }
        if (!empty($tempo)) {
            $lines[count($lines) - 1] .= ' tempo="' . $tempo . '"';
        }
        if (!empty($timeSignature)) {
            $lines[count($lines) - 1] .= ' time="' . htmlspecialchars($timeSignature) . '"';
        }
        if (!empty($rhythm)) {
            $lines[count($lines) - 1] .= ' rhythm="' . htmlspecialchars($rhythm) . '"';
        }

        $lines[] = ']';

        // Add sections with empty measure lines
        foreach ($sections as $section) {
            $label = ' label="' . htmlspecialchars($section['name']) . '"';
            $lines[] = '[' . $section['id'] . $label . ']';

            // Add empty measure lines - use ? as placeholder that parser will accept
            // The parser filters out empty strings, so we use a placeholder
            $measureCount = count($section['measures']);
            $barsPerLine = 4;
            for ($i = 0; $i < $measureCount; $i += $barsPerLine) {
                $barsInLine = min($barsPerLine, $measureCount - $i);
                $line = '|';
                for ($j = 0; $j < $barsInLine; $j++) {
                    $line .= ' ? |';
                }
                $lines[] = $line;
            }
        }

        // Add empty voicings section
        $lines[] = '[sbn_voicings]';
        $lines[] = '[/sbn_voicings]';

        $lines[] = '[/sbn_leadsheet]';

        return implode("\n", $lines);
    }
    /**
     * Build the json_data + shortcode_content for a leadsheet from a chord sequence.
     *
     * @param array $opts Title, composer, etc.
     * @param array $parsedSequence From ChordSequenceParser
     * @param int $barsPerChord For 'sequence' mode
     * @return array
     */
    public function scaffoldFromSequence(array $opts, array $parsedSequence, int $barsPerChord = 1): array
    {
        $title = $opts['title'] ?? 'Untitled';
        $composer = $opts['composer'] ?? '';
        $songKey = $opts['song_key'] ?? 'C';
        $tempo = $opts['tempo'] ?? 120;
        $timeSignature = $opts['time_signature'] ?? '4/4';
        $rhythm = $opts['rhythm'] ?? '';

        $timeParts = explode('/', $timeSignature);
        $displayBeats = (int) ($timeParts[0] ?? 4);

        $measures = [];

        if (($parsedSequence['mode'] ?? 'sequence') === 'bars') {
            // Pipe-separated bars mode
            foreach ($parsedSequence['items'] as $barChords) {
                if (empty($barChords)) continue;
                // Multiple chords in a bar split that bar evenly
                $chordCount = count($barChords);
                $beatsPerChord = max(1, floor($displayBeats / $chordCount));
                $remainder = $displayBeats % $chordCount;

                $measureChords = [];
                foreach ($barChords as $i => $chord) {
                    $chordBeats = $beatsPerChord;
                    if ($i === 0) {
                        $chordBeats += $remainder; // Give remainder to first chord
                    }
                    $measureChords[] = [
                        'name' => $chord,
                        'beats' => (int) $chordBeats,
                    ];
                }

                $measures[] = ['chords' => $measureChords];
            }
        } else {
            // Flat sequence mode
            foreach (($parsedSequence['items'] ?? []) as $chord) {
                // Duplicate chord for N bars
                for ($b = 0; $b < $barsPerChord; $b++) {
                    $measures[] = [
                        'chords' => [
                            [
                                'name' => $chord,
                                'beats' => $displayBeats,
                            ]
                        ]
                    ];
                }
            }
        }

        $globalMeasure = count($measures);

        // Group into a single "Main" section
        $sections = [
            [
                'id' => 'A',
                'name' => 'Main',
                'measures' => $measures,
                'rhythmPattern' => null,
                'startMeasure' => 0,
                'lineBreaks' => [],
                'tonality' => '',
                'info' => '',
                'harmonyNotes' => '',
                'formNotes' => '',
                'voicingNotes' => '',
                'collapsed' => false,
            ]
        ];

        $jsonData = [
            'title' => $title,
            'composer' => $composer,
            'key' => $songKey,
            'tempo' => $tempo,
            'timeSignature' => $timeSignature,
            'displayBeats' => $displayBeats,
            'subdivisionsPerBar' => 8,
            'sections' => $sections,
            'chordVoicings' => (object) [],
            'rhythmPattern' => null,
            'melody' => $this->buildPlaceholderMelody($globalMeasure, $displayBeats),
            'description' => '',
            'harmonyNotes' => '',
            'formNotes' => '',
            'voicingNotes' => '',
        ];

        // Build shortcode
        $shortcode = $this->buildShortcodeFromMeasures($title, $composer, $songKey, $tempo, $timeSignature, $rhythm, $measures);

        return [
            'shortcode_content' => $shortcode,
            'json_data' => json_encode($jsonData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'measure_count' => $globalMeasure,
        ];
    }

    protected function buildShortcodeFromMeasures(string $title, string $composer, string $songKey, int $tempo, string $timeSignature, string $rhythm, array $measures): string
    {
        $lines = [];
        $lines[] = '[sbn_leadsheet title="' . htmlspecialchars($title) . '"';

        if (!empty($composer)) $lines[count($lines) - 1] .= ' composer="' . htmlspecialchars($composer) . '"';
        if (!empty($songKey)) $lines[count($lines) - 1] .= ' key="' . htmlspecialchars($songKey) . '"';
        if (!empty($tempo)) $lines[count($lines) - 1] .= ' tempo="' . $tempo . '"';
        if (!empty($timeSignature)) $lines[count($lines) - 1] .= ' time="' . htmlspecialchars($timeSignature) . '"';
        if (!empty($rhythm)) $lines[count($lines) - 1] .= ' rhythm="' . htmlspecialchars($rhythm) . '"';

        $lines[] = ']';
        $lines[] = '[A label="Main"]';

        $measureCount = count($measures);
        $barsPerLine = 4;
        for ($i = 0; $i < $measureCount; $i += $barsPerLine) {
            $barsInLine = min($barsPerLine, $measureCount - $i);
            $line = '|';
            for ($j = 0; $j < $barsInLine; $j++) {
                $m = $measures[$i + $j];
                $chordStrs = [];
                foreach ($m['chords'] as $c) {
                    $chordStrs[] = $c['name'];
                }
                $line .= ' ' . implode(' ', $chordStrs) . ' |';
            }
            $lines[] = $line;
        }

        $lines[] = '[sbn_voicings]';
        $lines[] = '[/sbn_voicings]';
        $lines[] = '[/sbn_leadsheet]';

        return implode("\n", $lines);
    }
}
