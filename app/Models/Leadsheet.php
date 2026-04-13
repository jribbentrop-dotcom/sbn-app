<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Leadsheet extends Model
{
    /**
     * The table associated with the model.
     * (Imported from WP's wp_sbn_leadsheets, prefix stripped in Phase 0.)
     */
    protected $table = 'sbn_leadsheets';

    /**
     * Mass-assignable attributes.
     */
    protected $fillable = [
        'title',
        'composer',
        'song_key',
        'tempo',
        'time_signature',
        'rhythm',
        'measure_count',
        'course_id',
        'shortcode_content',
        'json_data',
        'tab_xml',
        'description',
        'harmony_notes',
        'form_notes',
        'voicing_notes',
        'popularity',
    ];

    /**
     * Attribute casting.
     */
    protected $casts = [
        'tempo'         => 'integer',
        'measure_count' => 'integer',
        'course_id'     => 'integer',
        'created_at'    => 'datetime',
        'updated_at'    => 'datetime',
    ];

    // =========================================================================
    // ACCESSORS
    // =========================================================================

    /**
     * Decoded json_data column → PHP array.
     * Returns null if empty or malformed.
     */
    public function getParsedDataAttribute(): ?array
    {
        if (empty($this->json_data)) {
            return null;
        }

        $decoded = json_decode($this->json_data, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Section count derived from parsed JSON data.
     */
    public function getSectionCountAttribute(): int
    {
        $data = $this->parsed_data;
        if (!$data || empty($data['sections'])) {
            return 0;
        }
        return count($data['sections']);
    }

    /**
     * Voicing count from parsed JSON data.
     */
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

    /**
     * Whether this leadsheet has melody/tab data.
     */
    public function getHasMelodyAttribute(): bool
    {
        $data = $this->parsed_data;
        return $data && !empty($data['melody']);
    }

    /**
     * Whether this leadsheet has stored MusicXML tab data.
     */
    public function getHasTabXmlAttribute(): bool
    {
        return !empty($this->tab_xml);
    }

    /**
     * Whether this leadsheet has repeat markers.
     */
    public function getHasRepeatsAttribute(): bool
    {
        $data = $this->parsed_data;
        return $data && !empty($data['repeatMarkers']);
    }

    /**
     * Display label: "Title — Composer" or just "Title".
     */
    public function getDisplayNameAttribute(): string
    {
        if (!empty($this->composer)) {
            return $this->title . ' — ' . $this->composer;
        }
        return $this->title;
    }

    // =========================================================================
    // SCOPES
    // =========================================================================

    /**
     * Filter by title or composer search term.
     */
    public function scopeSearch($query, ?string $term)
    {
        if (empty($term)) {
            return $query;
        }

        return $query->where(function ($q) use ($term) {
            $q->where('title', 'like', "%{$term}%")
              ->orWhere('composer', 'like', "%{$term}%");
        });
    }

    /**
     * Filter by song key.
     */
    public function scopeInKey($query, ?string $key)
    {
        if (empty($key)) {
            return $query;
        }
        return $query->where('song_key', $key);
    }

    /**
     * Filter by course.
     */
    public function scopeForCourse($query, ?int $courseId)
    {
        if (!$courseId) {
            return $query;
        }
        return $query->where('course_id', $courseId);
    }

    /**
     * Only leadsheets with a rhythm pattern assigned.
     */
    public function scopeWithRhythm($query, ?string $slug = null)
    {
        if ($slug) {
            return $query->where('rhythm', $slug);
        }
        return $query->whereNotNull('rhythm')->where('rhythm', '!=', '');
    }

    // =========================================================================
    // RELATIONSHIPS
    // =========================================================================

    /**
     * Voicing usages extracted from this leadsheet.
     */
    public function voicingUsages()
    {
        return $this->hasMany(VoicingUsage::class, 'leadsheet_id');
    }

    /**
     * Voicing drafts (unmatched) from this leadsheet.
     */
    public function voicingDrafts()
    {
        return $this->hasMany(VoicingDraft::class, 'leadsheet_id');
    }

    /**
     * Progression occurrences detected in this leadsheet.
     */
    public function progressionOccurrences()
    {
        return $this->hasMany(\App\Models\ChordProgression::class, 'leadsheet_id');
        // Note: This references the sbn_progression_occurrences pivot.
        // Adjust if there's a dedicated ProgressionOccurrence model later.
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

    /**
     * Compute the total measure count from sections.
     */
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
     * Get summary stats for display.
     */
    public static function getStats(): array
    {
        return [
            'total'     => static::count(),
            'composers' => static::whereNotNull('composer')
                               ->where('composer', '!=', '')
                               ->distinct('composer')
                               ->count('composer'),
            'keys'      => static::whereNotNull('song_key')
                               ->where('song_key', '!=', '')
                               ->distinct('song_key')
                               ->count('song_key'),
            'withMelody' => static::where('json_data', 'like', '%"melody":%')
                                ->where('json_data', 'not like', '%"melody":[]%')
                                ->count(),
        ];
    }

    /**
     * Get all unique keys across leadsheets (for filter dropdown).
     */
    public static function getDistinctKeys(): array
    {
        return static::whereNotNull('song_key')
            ->where('song_key', '!=', '')
            ->distinct()
            ->orderBy('song_key')
            ->pluck('song_key')
            ->toArray();
    }

    /**
     * Get all unique composers (for filter dropdown).
     */
    public static function getDistinctComposers(): array
    {
        return static::whereNotNull('composer')
            ->where('composer', '!=', '')
            ->distinct()
            ->orderBy('composer')
            ->pluck('composer')
            ->toArray();
    }
}
