<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

/**
 * Service for interacting with the Machine Room settings.
 *
 * Reads from the sbn_builder_settings table and provides typed getters
 * for the ProgressionBuilder to use instead of class constants.
 *
 * @package App\Services
 */
class BuilderSettings
{
    private const CACHE_KEY = 'builder_settings_cache';
    private const CACHE_TTL = 3600; // 1 hour, though it will be cleared on write

    /**
     * @var array<string, mixed>|null Memoized settings array
     */
    private ?array $settings = null;

    /**
     * Fallback defaults from the original ProgressionBuilder constants.
     */
    private const FALLBACK_DEFAULTS = [
        'category_pools' => [
            'jazz' => ['drop2', 'drop3', 'shell'],
            'blues' => ['shell'],
            'pop' => ['archetype'],
            'classical' => ['closed_triads', 'spread_triads'],
            'modal' => ['quartal', 'shell', 'drop3'],
            'latin' => ['drop2', 'drop3', 'shell', 'closed'],
        ],
        'blues_advanced_pool' => ['shell', 'drop3', 'drop2', 'closed'],
        'register_targets' => [
            'pop' => ['target' => 0, 'weight' => 0.10],
            'jazz' => ['target' => 5, 'weight' => 0.05],
            'classical' => ['target' => 2, 'weight' => 0.10],
            'blues' => ['target' => 1, 'weight' => 0.15],
            'modal' => ['target' => 5, 'weight' => 0.05],
            'latin' => ['target' => 5, 'weight' => 0.05],
        ],
        'cost_weights' => [
            'simplicity' => 0.10,
            'position' => 0.20,
            'bass_motion' => 0.20,
            'common_tone' => 0.15,
            'voice_leading' => 0.25,
            'group_continuity' => 0.10,
            'register' => 0.10,
            'named_resolutions' => 1.0,
            'style' => 0.25,
        ],
        'pass2_eligible' => ['jazz', 'latin'],
        'pass1_extensions_allowed' => [],
        // Per-category maps. Seeded with an explicit key for every category so
        // the value is always a JSON object, never an empty array — an empty
        // array is truthy in JS and breaks the Machine Room's per-category
        // checkbox/select binding (named keys on an Array serialize away).
        'root_only_default' => [
            'jazz' => false, 'blues' => false, 'pop' => false,
            'classical' => false, 'modal' => false, 'latin' => false,
        ],
        'tonic_widen_default' => ['jazz' => true, 'latin' => true],
        'repeated_chord_reuse' => true,
        'default_voicing_style' => [
            'jazz' => 'auto', 'blues' => 'auto', 'pop' => 'auto',
            'classical' => 'auto', 'modal' => 'auto', 'latin' => 'auto',
        ],
    ];

    /**
     * Get all settings, merging DB state over fallbacks.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        if ($this->settings !== null) {
            return $this->settings;
        }

        $dbSettings = Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            try {
                $rows = DB::table('sbn_builder_settings')->get();
                $arr = [];
                foreach ($rows as $row) {
                    $arr[$row->key] = json_decode($row->value, true);
                }
                return $arr;
            } catch (\Exception $e) {
                // Return empty array if table doesn't exist (e.g. before migrations run)
                return [];
            }
        });

        $this->settings = array_merge(self::FALLBACK_DEFAULTS, $dbSettings);
        return $this->settings;
    }

    /**
     * Get a specific setting by key.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $all = $this->all();
        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    /**
     * Update a setting and clear cache.
     */
    public function set(string $key, mixed $value): void
    {
        DB::table('sbn_builder_settings')->updateOrInsert(
            ['key' => $key],
            ['value' => json_encode($value), 'updated_at' => now()]
        );
        $this->clearCache();
    }

    /**
     * Restore all settings to their fallback defaults.
     */
    public function restoreDefaults(): void
    {
        DB::table('sbn_builder_settings')->truncate();
        $this->clearCache();
    }

    /**
     * Load a player archetype by slug.
     */
    public function loadArchetype(string $slug): ?array
    {
        $row = DB::table('sbn_builder_archetypes')->where('slug', $slug)->first();
        if (!$row) {
            return null;
        }
        return json_decode($row->settings_json, true);
    }

    /**
     * Save the current settings as a new archetype.
     */
    public function saveArchetype(string $slug, string $name, ?string $description = null): void
    {
        $currentSettings = $this->all();
        DB::table('sbn_builder_archetypes')->updateOrInsert(
            ['slug' => $slug],
            [
                'name' => $name,
                'description' => $description,
                'settings_json' => json_encode($currentSettings),
                'updated_at' => now(),
            ]
        );
    }

    /**
     * Get the voicing pool for a category.
     */
    public function getCategoryPool(string $category): array
    {
        $pools = $this->get('category_pools');
        return $pools[$category] ?? $pools['jazz'] ?? self::FALLBACK_DEFAULTS['category_pools']['jazz'];
    }

    public function getBluesAdvancedPool(): array
    {
        return $this->get('blues_advanced_pool');
    }

    public function getCostWeights(): array
    {
        return $this->get('cost_weights');
    }

    public function getCategoryRegisterTarget(string $category): int
    {
        $targets = $this->get('register_targets');
        return $targets[$category]['target'] ?? 5;
    }

    public function getCategoryRegisterWeight(string $category): float
    {
        $targets = $this->get('register_targets');
        return $targets[$category]['weight'] ?? 0.05;
    }

    public function isPass2Eligible(string $category): bool
    {
        $eligible = $this->get('pass2_eligible');
        return in_array($category, $eligible, true);
    }

    public function isPass1ExtensionsAllowed(string $category): bool
    {
        $allowed = $this->get('pass1_extensions_allowed');
        return in_array($category, $allowed, true) || ($allowed[$category] ?? false);
    }

    public function isTonicWidenEnabled(string $category): bool
    {
        $widen = $this->get('tonic_widen_default');
        return $widen[$category] ?? false;
    }

    public function getRootOnlyDefault(string $category): bool
    {
        $rootOnly = $this->get('root_only_default');
        return $rootOnly[$category] ?? false;
    }

    public function isRepeatedChordReuseEnabled(): bool
    {
        return $this->get('repeated_chord_reuse', true);
    }

    public function getDefaultVoicingStyle(string $category): string
    {
        $styles = $this->get('default_voicing_style');
        return $styles[$category] ?? 'auto';
    }

    private function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
        $this->settings = null;
    }
}
