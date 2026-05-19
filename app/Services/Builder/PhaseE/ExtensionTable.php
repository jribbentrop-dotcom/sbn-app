<?php

namespace App\Services\Builder\PhaseE;

use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

/**
 * Extension Table service for Phase E
 * 
 * Loads and caches the Phase-E-Extension-Table.yaml file,
 * providing access to recommended extensions, avoid tones,
 * and named resolution data.
 */
class ExtensionTable
{
    private static ?array $cache = null;
    private static string $yamlPath;

    /**
     * Initialize the service
     */
    public static function initialize(): void
    {
        self::$yamlPath = base_path('docs/Phase-E-Extension-Table.yaml');
        self::loadTable();
    }

    /**
     * Load and parse the YAML table
     */
    private static function loadTable(): void
    {
        if (self::$cache !== null) {
            return;
        }

        if (!File::exists(self::$yamlPath)) {
            throw new \RuntimeException("Extension table not found: " . self::$yamlPath);
        }

        $content = File::get(self::$yamlPath);
        self::$cache = Yaml::parse($content);
    }

    /**
     * Get recommended extensions for a given context
     * 
     * @param string $role The functional role of the current chord
     * @param string $targetRole The functional role of the next chord
     * @param string $keyMode 'major', 'minor', or 'any'
     * @return array Matching extension entries, ordered by priority
     */
    public static function getRecommendedExtensions(string $role, string $targetRole, string $keyMode): array
    {
        self::ensureLoaded();
        
        $candidates = [];
        
        foreach (self::$cache['recommended_extensions'] as $entry) {
            if ($entry['role'] === $role) {
                $targetMatches = $entry['target_role'] === 'any' || $entry['target_role'] === $targetRole;
                $keyMatches = $entry['key_mode'] === 'any' || $entry['key_mode'] === $keyMode;
                
                if ($targetMatches && $keyMatches) {
                    // Score by specificity: exact target > 'any', exact key > 'any'
                    $score = 0;
                    if ($entry['target_role'] !== 'any') $score += 2;
                    if ($entry['key_mode'] !== 'any') $score += 1;
                    
                    $candidates[] = [
                        'entry' => $entry,
                        'specificity_score' => $score
                    ];
                }
            }
        }

        // Sort by specificity score descending, then by priority
        usort($candidates, function($a, $b) {
            if ($a['specificity_score'] !== $b['specificity_score']) {
                return $b['specificity_score'] - $a['specificity_score'];
            }
            // Same specificity, sort by first extension priority
            $aPriority = $a['entry']['extensions'][0]['priority'] ?? 1;
            $bPriority = $b['entry']['extensions'][0]['priority'] ?? 1;
            return $bPriority - $aPriority;
        });

        return array_column($candidates, 'entry');
    }

    /**
     * Get top-priority extension set for a context
     * 
     * @param string $role
     * @param string|null $targetRole Null when target is unknown (last slot,
     *                                or target chord not yet resolved). Falls
     *                                back to the YAML's `target_role: any` rows.
     * @param string $keyMode
     * @return array|null Top extension entry or null if no match
     */
    public static function getTopExtensions(string $role, ?string $targetRole, string $keyMode): ?array
    {
        // Null target → match only `any` rows. getRecommendedExtensions already
        // treats 'any' as a wildcard; passing 'any' as the target makes the
        // intent explicit and avoids a strict-type error.
        $effectiveTarget = $targetRole ?? 'any';
        $extensions = self::getRecommendedExtensions($role, $effectiveTarget, $keyMode);
        return $extensions[0] ?? null;
    }

    /**
     * Check if a tone is forbidden for a given context
     * 
     * @param string $context The context string (e.g., "V7 → Imaj7", "Imaj7")
     * @param string $tone The tone to check
     * @return bool True if tone is forbidden
     */
    public static function isToneForbidden(string $context, string $tone): bool
    {
        self::ensureLoaded();
        
        foreach (self::$cache['avoid_tones_index'] as $entry) {
            if ($entry['context'] === $context) {
                return in_array($tone, $entry['forbid']);
            }
        }
        
        return false;
    }

    /**
     * Get all named resolutions
     * 
     * @return array
     */
    public static function getNamedResolutions(): array
    {
        self::ensureLoaded();
        return self::$cache['named_resolutions'] ?? [];
    }

    /**
     * Get named resolution by ID
     * 
     * @param string $id
     * @return array|null
     */
    public static function getNamedResolution(string $id): ?array
    {
        foreach (self::getNamedResolutions() as $resolution) {
            if ($resolution['id'] === $id) {
                return $resolution;
            }
        }
        return null;
    }

    /**
     * Get secondary dominant routing rules
     * 
     * @return array
     */
    public static function getSecondaryDominantRouting(): array
    {
        self::ensureLoaded();
        return self::$cache['secondary_dominant_routing'] ?? [];
    }

    /**
     * Route a secondary dominant to appropriate V7 entry
     *
     * The YAML routing rules key on a spelled-out quality vocabulary
     * (`minor7`, `minor`, `maj7`, …), but the builder stores chord qualities
     * in its own shorthand (`m7`, `m`, `maj7`, `dom7`, …). Without translation
     * every minor-target rule silently misses and the secondary dominant
     * defaults to the *major*-resolution extension set — handing natural 9/13
     * to a dominant that resolves to a minor chord. Normalize first.
     *
     * @param string $targetQuality
     * @return string Route description
     */
    public static function routeSecondaryDominant(string $targetQuality): string
    {
        $routing = self::getSecondaryDominantRouting();
        $normalized = self::normalizeTargetQuality($targetQuality);

        foreach ($routing['rules'] as $rule) {
            if ($rule['target_quality'] === $normalized) {
                return $rule['route_to'];
            }
        }

        return $routing['default_when_target_unknown']['route_to'];
    }

    /**
     * Translate the builder's quality shorthand into the YAML routing
     * vocabulary. Unknown values pass through untouched so a quality already
     * spelled the YAML way (or a future addition) still matches directly.
     */
    private static function normalizeTargetQuality(string $quality): string
    {
        $map = [
            'm7'    => 'minor7',
            'min7'  => 'minor7',
            'm7b5'  => 'minor7b5',
            'm6'    => 'minor6',
            'min6'  => 'minor6',
            'm'     => 'minor',
            'min'   => 'minor',
            'minor' => 'minor',
            'maj7'  => 'maj7',
            'maj'   => 'major',
            'major' => 'major',
            '6'     => '6',
            '6/9'   => '6/9',
            '69'    => '6/9',
            '7'     => 'dom7',
            'dom7'  => 'dom7',
            'dim7'  => 'dim7',
            'dim'   => 'dim7',
            'o7'    => 'dim7',
            '°7'    => 'dim7',
        ];

        return $map[trim($quality)] ?? trim($quality);
    }

    /**
     * Check if a voicing contains a specific tone
     * 
     * @param array $voicingNotes Array of MIDI note numbers
     * @param int $rootPitchClass Root pitch class (0-11)
     * @param string $tone Tone string to check
     * @return bool True if tone is present in voicing
     */
    public static function isToneInVoicing(array $voicingNotes, int $rootPitchClass, string $tone): bool
    {
        $offset = Interval::offset($tone);
        $targetPitchClass = ($rootPitchClass + $offset) % 12;
        
        foreach ($voicingNotes as $note) {
            if ($note % 12 === $targetPitchClass) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Get extension tones present in a voicing
     * 
     * @param array $voicingNotes Array of MIDI note numbers
     * @param int $rootPitchClass Root pitch class (0-11)
     * @return array<string> Array of extension tone strings present
     */
    public static function getVoicingExtensions(array $voicingNotes, int $rootPitchClass): array
    {
        $extensions = [];
        $voicingPitchClasses = array_map(fn($note) => $note % 12, $voicingNotes);
        
        foreach (Interval::getExtensionTones() as $tone) {
            $offset = Interval::offset($tone);
            $targetPitchClass = ($rootPitchClass + $offset) % 12;
            
            if (in_array($targetPitchClass, $voicingPitchClasses)) {
                $extensions[] = $tone;
            }
        }
        
        return $extensions;
    }

    /**
     * Ensure the table is loaded
     */
    private static function ensureLoaded(): void
    {
        if (self::$cache === null) {
            self::loadTable();
        }
    }

    /**
     * Clear cache (useful for testing)
     */
    public static function clearCache(): void
    {
        self::$cache = null;
    }
}
