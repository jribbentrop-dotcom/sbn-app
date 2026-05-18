<?php

namespace App\Services;

class AnalysisToLeadsheet
{
    public function convert(array $analysis): array
    {
        $title = $analysis['title'] ?? 'Untitled';
        $composer = $analysis['composer'] ?? '';
        $songKey = $analysis['key'] ?? 'C';
        $tempo = $analysis['tempo'] ?? 120;
        $timeSignature = $analysis['timeSignature'] ?? '4/4';
        
        $timeParts = explode('/', $timeSignature);
        $displayBeats = (int) ($timeParts[0] ?? 4);

        $sections = [];
        $globalMeasure = 0;
        $sectionId = 'A';

        foreach (($analysis['sections'] ?? []) as $secDef) {
            $measures = [];
            foreach ($secDef['bars'] as $bar) {
                $measureChords = [];

                foreach ($bar['chords'] as $chord) {
                    $name = $chord['label'] ?? '?';
                    $measureChords[] = [
                        'name'  => $this->normalizeChordName($name),
                        'beats' => (int) ($chord['beats'] ?? 1),
                    ];
                }

                $measures[] = [
                    'chords' => $measureChords
                ];
                $globalMeasure++;
            }

            $sections[] = [
                'id' => $sectionId,
                'name' => $secDef['name'] ?? 'Section',
                'measures' => $measures,
                'rhythmPattern' => null,
                'startMeasure' => $globalMeasure - count($measures),
                'lineBreaks' => [],
                'tonality' => '',
                'info' => '',
                'harmonyNotes' => '',
                'formNotes' => '',
                'voicingNotes' => '',
                'collapsed' => false,
            ];
            $sectionId = chr(ord($sectionId) + 1);
        }

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
            'melody' => $analysis['melody_data'] ?? $this->buildPlaceholderMelody($globalMeasure, $displayBeats),
            'videoSync' => $analysis['videoSync'] ?? null,
            // Cached raw audio transcription, kept so the downbeat can be
            // re-shifted later without re-downloading / re-transcribing.
            'transcriptionRaw' => $analysis['transcriptionRaw'] ?? null,
            'research' => $analysis['research'] ?? null,
            'description' => '',
            'harmonyNotes' => '',
            'formNotes' => '',
            'voicingNotes' => '',
        ];

        // Shortcode building
        $shortcode = $this->buildShortcode($title, $composer, $songKey, $tempo, $timeSignature, '', $sections);

        return [
            'shortcode_content' => $shortcode,
            'json_data' => json_encode($jsonData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'measure_count' => $globalMeasure,
        ];
    }

    protected function buildPlaceholderMelody(int $measureCount, int $displayBeats): array
    {
        // 4.9.20 Polish: Do not emit a dummy rest melody.
        // Let VoicingMaterializer populate the melody array when voicings are requested.
        return [];
    }

    protected function buildShortcode(string $title, string $composer, string $songKey, int $tempo, string $timeSignature, string $rhythm, array $sections): string
    {
        $lines = [];
        $lines[] = '[sbn_leadsheet title="' . htmlspecialchars($title) . '"';

        if (!empty($composer)) $lines[count($lines) - 1] .= ' composer="' . htmlspecialchars($composer) . '"';
        if (!empty($songKey)) $lines[count($lines) - 1] .= ' key="' . htmlspecialchars($songKey) . '"';
        if (!empty($tempo)) $lines[count($lines) - 1] .= ' tempo="' . $tempo . '"';
        if (!empty($timeSignature)) $lines[count($lines) - 1] .= ' time="' . htmlspecialchars($timeSignature) . '"';
        if (!empty($rhythm)) $lines[count($lines) - 1] .= ' rhythm="' . htmlspecialchars($rhythm) . '"';

        $lines[] = ']';

        foreach ($sections as $section) {
            $label = ' label="' . htmlspecialchars($section['name']) . '"';
            $lines[] = '[' . $section['id'] . $label . ']';

            $measureCount = count($section['measures']);
            $barsPerLine = 4;

            for ($i = 0; $i < $measureCount; $i += $barsPerLine) {
                $barsInLine = min($barsPerLine, $measureCount - $i);
                $line = '|';
                for ($j = 0; $j < $barsInLine; $j++) {
                    $measure = $section['measures'][$i + $j];
                    $chordStrings = [];
                    foreach ($measure['chords'] as $chord) {
                        $chordStrings[] = $chord['name'];
                    }
                    $line .= ' ' . implode(' ', $chordStrings) . ' |';
                }
                $lines[] = $line;
            }
        }

        $lines[] = '[/sbn_leadsheet]';

        return implode("\n", $lines);
    }

    protected function normalizeChordName(string $name): string
    {
        if (!$name || $name === '?') return $name;
        
        // Basic jazz shorthand normalization
        $name = str_replace(
            ['-', '^', 'h', 'o'], 
            ['m', 'maj', 'm7b5', 'dim'], 
            $name
        );

        return $name;
    }
}
