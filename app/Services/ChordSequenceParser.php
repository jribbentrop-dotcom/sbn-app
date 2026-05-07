<?php

namespace App\Services;

/**
 * ChordSequenceParser
 *
 * Parses free-form chord strings into structured data.
 * Used by the "From progression" creation flow (L2).
 *
 * Supports three formats:
 * 1. Whitespace-separated: "Am7 Dm7 G7 Cmaj7"
 * 2. Pipe-separated bars: "| Am7 | Dm7 G7 | Cmaj7 |"
 * 3. Inline ChordPro: "[Am7]some lyric [Dm7]more [G7]words"
 */
class ChordSequenceParser
{
    protected ProgressionDetector $detector;
    protected NumeralResolver $resolver;

    public function __construct(ProgressionDetector $detector, NumeralResolver $resolver)
    {
        $this->detector = $detector;
        $this->resolver = $resolver;
    }



    /**
     * Parse raw string into structured chords.
     *
     * @param string $input
     * @return array{mode: string, items: array, invalid_count: int}
     */
    public function parse(string $input): array
    {
        $input = trim($input);
        if (empty($input)) {
            return [
                'mode' => 'sequence',
                'items' => [],
                'invalid_count' => 0,
            ];
        }

        $mode = 'sequence';
        $items = [];
        $invalidCount = 0;

        if (str_contains($input, '|')) {
            $mode = 'bars';
            $items = $this->parsePipeSeparated($input);
        } elseif (str_contains($input, '[') && str_contains($input, ']')) {
            $mode = 'sequence';
            $items = $this->parseChordPro($input);
        } else {
            $mode = 'sequence';
            $items = $this->parseWhitespaceSeparated($input);
        }

        // Validate tokens
        if ($mode === 'bars') {
            foreach ($items as $barIndex => $barChords) {
                foreach ($barChords as $chordIndex => $chord) {
                    if (empty($chord)) {
                        $items[$barIndex][$chordIndex] = '?';
                        $invalidCount++;
                        continue;
                    }

                    // 1. Try to parse as concrete chord
                    $parsed = $this->detector->parseChordName($chord);
                    if ($parsed && !empty($parsed['root'])) {
                        continue; // Valid concrete chord
                    }

                    // 2. Try to parse as numeral
                    if ($this->resolver->isNumeral($chord)) {
                        continue; // Valid numeral
                    }


                    // 3. Otherwise invalid
                    $items[$barIndex][$chordIndex] = '?';
                    $invalidCount++;
                }
            }
        } else {
            foreach ($items as $index => $chord) {
                if (empty($chord)) {
                    $items[$index] = '?';
                    $invalidCount++;
                    continue;
                }

                // 1. Try to parse as concrete chord
                $parsed = $this->detector->parseChordName($chord);
                if ($parsed && !empty($parsed['root'])) {
                    continue; // Valid concrete chord
                }

                // 2. Try to parse as numeral
                if ($this->resolver->isNumeral($chord)) {

                    continue; // Valid numeral
                }

                // 3. Otherwise invalid
                $items[$index] = '?';
                $invalidCount++;
            }
        }

        return [
            'mode' => $mode,
            'items' => $items,
            'invalid_count' => $invalidCount,
        ];
    }

    /**
     * Resolve Roman numerals in a parsed sequence to concrete chord names.
     */
    public function resolveNumerals(array $parsedSequence, ?string $key): array
    {
        if (!$key) {
            return $parsedSequence;
        }

        $parsedSequence['items'] = $this->resolver->resolveSequenceItems(
            $parsedSequence['items'],
            $key,
            ($parsedSequence['mode'] ?? 'sequence') === 'bars'
        );

        return $parsedSequence;
    }








    protected function parseWhitespaceSeparated(string $input): array
    {
        return array_values(array_filter(preg_split('/\s+/', $input)));
    }

    protected function parsePipeSeparated(string $input): array
    {
        $input = trim($input, '| ');
        $bars = explode('|', $input);
        $result = [];

        foreach ($bars as $bar) {
            $bar = trim($bar);
            if (empty($bar)) continue;
            $barChords = array_values(array_filter(preg_split('/\s+/', $bar)));
            $result[] = $barChords;
        }

        return $result;
    }

    protected function parseChordPro(string $input): array
    {
        preg_match_all('/\[([^\]]+)\]/', $input, $matches);
        $chords = [];
        if (!empty($matches[1])) {
            foreach ($matches[1] as $chord) {
                $chord = trim($chord);
                if (!empty($chord)) {
                    $chords[] = $chord;
                }
            }
        }
        return $chords;
    }
}
