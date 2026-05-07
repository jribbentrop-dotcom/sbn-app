<?php

namespace App\Services\Builder\PhaseE;

/**
 * Interval conversion utilities for Phase E extension table
 * 
 * Provides tone string to semitone offset conversion based on
 * Phase-E-Extension-Table.yaml tone_to_semitone mapping.
 */
class Interval
{
    /**
     * Canonical tone-to-semitone mapping from YAML
     */
    private const TONE_TO_SEMITONE = [
        "1"     => 0,    // root
        "b3"    => 3,
        "3"     => 4,
        "5"     => 7,
        "b5"    => 6,
        "#5"    => 8,
        "6"     => 9,    // = m7's b13 enharmonically; context determines meaning
        "b7"    => 10,
        "7"     => 11,
        "b9"    => 13,   // i.e. 1 semitone above the root, octave-displaced
        "9"     => 14,   // = root + 2, octave-displaced
        "#9"    => 15,
        "11"    => 17,
        "#11"   => 18,
        "b13"   => 20,
        "13"    => 21,
    ];

    /**
     * Convert tone string to semitone offset above chord root
     * 
     * @param string $tone The tone string (e.g., "b9", "#11", "13")
     * @return int Semitone offset (0-24)
     * @throws \InvalidArgumentException If tone is not recognized
     */
    public static function offset(string $tone): int
    {
        if (!isset(self::TONE_TO_SEMITONE[$tone])) {
            throw new \InvalidArgumentException("Unrecognized tone: {$tone}");
        }
        
        return self::TONE_TO_SEMITONE[$tone];
    }

    /**
     * Check if a tone string is valid
     * 
     * @param string $tone
     * @return bool
     */
    public static function isValid(string $tone): bool
    {
        return isset(self::TONE_TO_SEMITONE[$tone]);
    }

    /**
     * Get all valid tone strings
     * 
     * @return array<string>
     */
    public static function getAllTones(): array
    {
        return array_keys(self::TONE_TO_SEMITONE);
    }

    /**
     * Get canonical extension tones (those used in extension recommendations)
     * 
     * @return array<string>
     */
    public static function getExtensionTones(): array
    {
        return ['b9', '9', '#9', '11', '#11', 'b13', '13'];
    }
}
