<?php

namespace Tests\Unit;

use App\Services\Harmony\ChordQualityMapper;
use Tests\TestCase;

/**
 * Locks in the exact behavior moved out of ProgressionBuilder and
 * ProgressionDetector into this shared class (SBN-Security-Audit-2026-07-09.md
 * #6). No DB needed — pure string mapping.
 */
class ChordQualityMapperTest extends TestCase
{
    private function mapper(): ChordQualityMapper
    {
        return new ChordQualityMapper();
    }

    /** @dataProvider chordNameSuffixCases */
    public function test_to_chord_name_suffix(string $quality, string $expected): void
    {
        $this->assertSame($expected, $this->mapper()->toChordNameSuffix($quality));
    }

    public static function chordNameSuffixCases(): array
    {
        return [
            'plain dominant'    => ['dom7', '7'],
            'major triad'       => ['maj', ''],
            'diminished'        => ['dim', 'dim'],
            'diminished symbol' => ['o', 'dim'],
            'half-dim alias'    => ['half-dim', 'm7b5'],
            'major6 stays 6'    => ['maj6', '6'],
            'augmented7'        => ['aug7', 'aug7'],
        ];
    }

    /** @dataProvider romanSuffixCases */
    public function test_to_roman_suffix(string $quality, string $expected): void
    {
        $this->assertSame($expected, $this->mapper()->toRomanSuffix($quality));
    }

    public static function romanSuffixCases(): array
    {
        return [
            'plain dominant'      => ['dom7', '7'],
            'major triad'         => ['maj', ''],
            'diminished collapses to o' => ['dim', 'o'],
            'dim7 collapses to o7'      => ['dim7', 'o7'],
            'maj6 collapses to maj7'    => ['maj6', 'maj7'],
            'augmented7 stays aug7'     => ['aug7', 'aug7'],
            'extended dominant (9) collapses to 7' => ['9', '7'],
            'extended minor (m9) collapses to m7'  => ['m9', 'm7'],
        ];
    }

    public function test_display_and_functional_paths_diverge_on_maj6(): void
    {
        // The two paths intentionally disagree here: display keeps the "6"
        // distinct from maj7, functional collapses it — that divergence is
        // why the two paths were kept separate rather than merged into one.
        $mapper = $this->mapper();

        $this->assertSame('6', $mapper->toChordNameSuffix('maj6'));
        $this->assertSame('maj7', $mapper->toRomanSuffix('maj6'));
    }
}
