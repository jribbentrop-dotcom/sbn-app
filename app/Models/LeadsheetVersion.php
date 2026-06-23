<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One arrangement of a song — the per-version data split off Leadsheet.
 *
 * A Leadsheet (the WORK / catalog identity, incl. licensing) has many of these.
 * Each version carries its own chord grid (json_data) and two notated TAB layers
 * (melody_tab_xml, chord_tab_xml). See docs/SBN-Leadsheet-Versions-Plan.md.
 *
 * The json_data/tab accessors here mirror what previously lived on Leadsheet;
 * during the dual-read window both exist so nothing breaks. Stage 4 points the
 * controllers/services at the active version.
 */
class LeadsheetVersion extends Model
{
    protected $table = 'sbn_leadsheet_versions';

    protected $fillable = [
        'leadsheet_id',
        'version_slug',
        'label',
        'performer',
        'difficulty',
        'sort_order',
        'song_key',
        'rhythm',
        'tempo',
        'measure_count',
        'json_data',
        'melody_tab_xml',
        'chord_tab_xml',
        'shortcode_content',
        'status',
    ];

    protected $casts = [
        'difficulty'    => 'integer',
        'sort_order'    => 'integer',
        'tempo'         => 'integer',
        'measure_count' => 'integer',
        'created_at'    => 'datetime',
        'updated_at'    => 'datetime',
    ];

    // =========================================================================
    // RELATIONSHIPS
    // =========================================================================

    public function leadsheet(): BelongsTo
    {
        return $this->belongsTo(Leadsheet::class, 'leadsheet_id');
    }

    // =========================================================================
    // ACCESSORS (mirror of the legacy Leadsheet accessors, now version-scoped)
    // =========================================================================

    /**
     * Decoded json_data column → PHP array. Null if empty or malformed.
     */
    public function getParsedDataAttribute(): ?array
    {
        if (empty($this->json_data)) {
            return null;
        }

        $decoded = json_decode($this->json_data, true);
        return is_array($decoded) ? $decoded : null;
    }

    public function getSectionCountAttribute(): int
    {
        $data = $this->parsed_data;
        if (!$data || empty($data['sections'])) {
            return 0;
        }
        return count($data['sections']);
    }

    public function getVoicingCountAttribute(): int
    {
        $data = $this->parsed_data;
        if (!$data || empty($data['chordVoicings'])) {
            return 0;
        }
        // Only count default voicings (no @ override keys)
        return count(array_filter(
            array_keys($data['chordVoicings']),
            fn($k) => !str_contains($k, '@')
        ));
    }

    public function getHasMelodyAttribute(): bool
    {
        $data = $this->parsed_data;
        return $data && !empty($data['melody']);
    }

    /**
     * Whether this version has a stored notated melody (MusicXML).
     */
    public function getHasMelodyTabAttribute(): bool
    {
        return !empty($this->melody_tab_xml);
    }

    /**
     * Whether this version has an authored chord/comping TAB layer.
     */
    public function getHasChordTabAttribute(): bool
    {
        return !empty($this->chord_tab_xml);
    }

    public function getHasRepeatsAttribute(): bool
    {
        $data = $this->parsed_data;
        return $data && !empty($data['repeatMarkers']);
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Get all unique chord names from parsed data (no overrides).
     */
    public function getChordNames(): array
    {
        $data = $this->parsed_data;
        if (!$data || empty($data['sections'])) {
            return [];
        }

        $names = [];
        foreach ($data['sections'] as $section) {
            foreach ($section['measures'] ?? [] as $measure) {
                foreach ($measure['chords'] ?? [] as $chord) {
                    $name = $chord['name'] ?? '';
                    if ($name && $name !== '?' && $name !== '—') {
                        $names[$name] = true;
                    }
                }
            }
        }

        return array_keys($names);
    }

    public function computeMeasureCount(): int
    {
        $data = $this->parsed_data;
        if (!$data || empty($data['sections'])) {
            return 0;
        }

        $count = 0;
        foreach ($data['sections'] as $section) {
            $count += count($section['measures'] ?? []);
        }

        return $count;
    }

    /**
     * Generate a slug unique within one leadsheet's versions.
     * Mirrors Leadsheet::generateUniqueSlug but scoped to the parent.
     */
    public static function generateUniqueVersionSlug(int $leadsheetId, string $label): string
    {
        $slug = \Illuminate\Support\Str::slug($label) ?: 'version';
        $original = $slug;
        $counter = 1;
        while (static::where('leadsheet_id', $leadsheetId)->where('version_slug', $slug)->exists()) {
            $slug = $original . '-' . $counter++;
        }
        return $slug;
    }
}
