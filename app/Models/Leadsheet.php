<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

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
        'slug',
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
        'difficulty',
        'genre',
        'cover_image_path',
        'status',
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
    // SCOPES
    // =========================================================================

    /**
     * Only leadsheets visible in the public song library.
     */
    public function scopePublished($query)
    {
        return $query->where('status', 'publish');
    }

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

    /**
     * Map the leadsheet's rhythm slug to a design-system style slug
     * (one of: bossa, samba, jazz, latin, blues, pop, classical).
     * Used for category badges/colors wherever a song is linked.
     */
    public function getStyleSlugAttribute(): string
    {
        $rhythm = $this->rhythm;
        if (!$rhythm) {
            return 'bossa';
        }

        $map = [
            'bossa-nova' => 'bossa-nova',
            'bossa'      => 'bossa-nova',
            'jazz'       => 'jazz',
            'classical'  => 'classical',
            'pop'        => 'pop',
        ];

        if (isset($map[$rhythm])) {
            return $map[$rhythm];
        }

        // Prefix match (e.g. "bossa-nova-variation" → "bossa-nova")
        foreach ($map as $prefix => $style) {
            if (str_starts_with($rhythm, $prefix)) {
                return $style;
            }
        }

        return 'bossa-nova';
    }

    public const PRESET_TAGS = ['blues', 'modal', 'latin', 'cuban', 'brazilian', 'swing', 'afro-cuban', 'ballad', 'samba'];

    public function tags(): MorphToMany
    {
        return $this->morphToMany(SbnTag::class, 'taggable', 'sbn_taggables', 'taggable_id', 'tag_id');
    }

    /**
     * Public URL for the cover image, or null when none is set.
     */
    public function getCoverImageUrlAttribute(): ?string
    {
        return $this->cover_image_path
            ? '/images/songs/' . $this->cover_image_path
            : null;
    }

    /**
     * Compact payload for linking to this song from other library pages
     * (chord / progression / rhythm detail). Shape consumed by SongLink.vue.
     *
     * @return array{id:int,slug:string,title:string,styleSlug:string,coverImagePath:?string}
     */
    public function toLinkArray(): array
    {
        return [
            'id'             => $this->id,
            'slug'           => $this->slug,
            'title'          => $this->title,
            'styleSlug'      => $this->style_slug,
            'coverImagePath' => $this->cover_image_url,
        ];
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
     * Get all distinct style slugs present in the table (for filter pills).
     * Maps raw rhythm values through the same prefix logic as getStyleSlugAttribute.
     */
    public static function getDistinctStyles(): array
    {
        $map = [
            'bossa-nova' => 'bossa-nova',
            'bossa'      => 'bossa-nova',
            'jazz'       => 'jazz',
            'classical'  => 'classical',
            'pop'        => 'pop',
        ];

        $rhythms = static::whereNotNull('rhythm')->where('rhythm', '!=', '')->distinct()->pluck('rhythm');

        $styles = [];
        foreach ($rhythms as $rhythm) {
            $slug = $map[$rhythm] ?? null;
            if (!$slug) {
                foreach ($map as $prefix => $style) {
                    if (str_starts_with($rhythm, $prefix)) { $slug = $style; break; }
                }
            }
            if ($slug) $styles[$slug] = true;
        }

        $ordered = ['bossa-nova', 'jazz', 'classical', 'pop'];
        return array_values(array_filter($ordered, fn($s) => isset($styles[$s])));
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

    /**
     * Generate a unique slug for a leadsheet title.
     */
    public static function generateUniqueSlug(string $title): string
    {
        $slug = \Illuminate\Support\Str::slug($title);
        $originalSlug = $slug;
        $counter = 1;
        while (static::where('slug', $slug)->exists()) {
            $slug = $originalSlug . '-' . $counter++;
        }
        return $slug;
    }


    // =========================================================================
    // VOICING MANAGEMENT
    // =========================================================================

    /**
     * Remove a voicing from the leadsheet by chord name and fret pattern.
     * Updates both shortcode_content and json_data.
     *
     * @param string $chordName The chord name (e.g., "Cmaj7", "F#m7b5")
     * @param string $fretString The 6-character fret string (e.g., "x32010")
     * @return bool Whether any voicing was removed
     */
    public function removeVoicing(string $chordName, string $fretString): bool
    {
        $removed = false;

        // 1. Remove from shortcode_content [sbn_voicings] block
        if (!empty($this->shortcode_content)) {
            $newContent = $this->removeVoicingFromShortcode($this->shortcode_content, $chordName, $fretString);
            if ($newContent !== $this->shortcode_content) {
                $this->shortcode_content = $newContent;
                $removed = true;
            }
        }

        // 2. Remove from json_data chordVoicings
        if (!empty($this->json_data)) {
            $jsonData = json_decode($this->json_data, true);
            if (is_array($jsonData) && isset($jsonData['chordVoicings'])) {
                $voicings = $jsonData['chordVoicings'];
                $newVoicings = $this->removeVoicingFromJson($voicings, $chordName, $fretString);
                if ($newVoicings !== $voicings) {
                    $jsonData['chordVoicings'] = $newVoicings;
                    $this->json_data = json_encode($jsonData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    $removed = true;
                }
            }
        }

        return $removed;
    }

    /**
     * Remove a voicing line from the [sbn_voicings] shortcode block.
     */
    private function removeVoicingFromShortcode(string $content, string $chordName, string $fretString): string
    {
        // Pattern to match [sbn_voicings]...[/sbn_voicings]
        if (!preg_match('/(\[sbn_voicings\])([\s\S]*?)(\[\/sbn_voicings\])/', $content, $match)) {
            return $content;
        }

        $prefix = $match[1]; // [sbn_voicings]
        $voicingsBlock = $match[2];
        $suffix = $match[3]; // [/sbn_voicings]

        // Normalize fret string for comparison (lowercase, trim)
        $targetFrets = strtolower(trim($fretString));

        // Parse and filter lines
        $lines = explode("\n", $voicingsBlock);
        $filteredLines = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (empty($trimmed)) {
                $filteredLines[] = $line; // Preserve empty lines
                continue;
            }

            // Check if this line matches the voicing to remove
            // Format: ChordName: frets @position (fingers)
            $sepPos = strpos($trimmed, ': ');
            if ($sepPos === false) {
                $filteredLines[] = $line;
                continue;
            }

            $lineChordName = trim(substr($trimmed, 0, $sepPos));
            $rest = trim(substr($trimmed, $sepPos + 2));

            // Extract frets from the rest
            if (!preg_match('/^([x0-9a-fA-F]+)/', $rest, $m)) {
                $filteredLines[] = $line;
                continue;
            }

            $lineFrets = strtolower(trim($m[1]));

            // If chord name and frets match, skip this line (remove it)
            if ($lineChordName === $chordName && $lineFrets === $targetFrets) {
                continue; // Skip this line = remove it
            }

            $filteredLines[] = $line;
        }

        $newVoicingsBlock = implode("\n", $filteredLines);

        // Replace in content
        return str_replace($match[0], $prefix . $newVoicingsBlock . $suffix, $content);
    }

    /**
     * Remove a voicing entry from json_data chordVoicings object.
     */
    private function removeVoicingFromJson(array $voicings, string $chordName, string $fretString): array
    {
        $targetFrets = strtolower(trim($fretString));
        $result = [];

        foreach ($voicings as $key => $voicing) {
            // Check if this is a positional override key (contains @)
            $baseName = $key;
            if (str_contains($key, '@')) {
                $baseName = explode('@', $key)[0];
            }

            // If base name matches and frets match, skip it (remove)
            if ($baseName === $chordName && isset($voicing['frets'])) {
                $voicingFrets = strtolower(trim($voicing['frets']));
                if ($voicingFrets === $targetFrets) {
                    continue; // Skip = remove
                }
            }

            $result[$key] = $voicing;
        }

        return $result;
    }
}
