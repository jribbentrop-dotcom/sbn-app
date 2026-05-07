<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * JazzStandard — read-only reference record from the Oliphant/iReal Pro dataset.
 *
 * This table is SEEDED, not admin-editable. Its purpose is:
 *   - Providing chord/structure data for L2 "From Jazz Standard" picker
 *   - Local-first lookup in L3 (skip LLM when the title matches a known standard)
 *   - Structural anchoring for audio transcription (L3a) — deferred
 *   - Progression occurrence analysis (on-demand admin action) — deferred
 *
 * NOT exposed in the public frontend (decision: "not yet").
 *
 * @property int         $id
 * @property string      $title
 * @property string|null $composer
 * @property string|null $song_key
 * @property string|null $rhythm
 * @property string      $time_signature
 * @property int|null    $bar_count
 * @property string|null $form
 * @property array       $sections_json
 * @property string|null $chord_string
 * @property string      $source
 * @property string      $slug
 * @property \Carbon\Carbon $created_at
 */
class JazzStandard extends Model
{
    protected $table = 'sbn_jazz_standards';

    public $timestamps = false; // only created_at, managed manually

    protected $fillable = [
        'title',
        'composer',
        'song_key',
        'rhythm',
        'time_signature',
        'bar_count',
        'form',
        'sections_json',
        'chord_string',
        'source',
        'slug',
    ];

    protected $casts = [
        'sections_json' => 'array',
        'bar_count'     => 'integer',
        'created_at'    => 'datetime',
    ];

    // =========================================================================
    // SCOPES
    // =========================================================================

    /** Full-text search across title, composer, and chord_string. */
    public function scopeSearch($query, ?string $term)
    {
        if (empty($term)) return $query;
        $like = '%' . $term . '%';
        return $query->where(function ($q) use ($like) {
            $q->where('title', 'like', $like)
              ->orWhere('composer', 'like', $like);
        });
    }

    /** Filter by key (case-insensitive prefix match so "Dmin" matches "D"). */
    public function scopeInKey($query, ?string $key)
    {
        if (empty($key)) return $query;
        return $query->where('song_key', 'like', $key . '%');
    }

    /** Filter by rhythm style (e.g. "Swing", "Bossa"). */
    public function scopeRhythmStyle($query, ?string $style)
    {
        if (empty($style)) return $query;
        return $query->where('rhythm', 'like', '%' . $style . '%');
    }

    // =========================================================================
    // ACCESSORS
    // =========================================================================

    /**
     * Human-readable key: "Dmin" → "D minor", "Bb" → "B♭ major".
     */
    public function getKeyDisplayAttribute(): string
    {
        $key = $this->song_key ?? '';
        if (str_ends_with($key, 'min')) {
            $root = rtrim($key, 'min');
            return str_replace('b', '♭', str_replace('#', '♯', $root)) . ' minor';
        }
        return str_replace('b', '♭', str_replace('#', '♯', $key)) . ' major';
    }

    /**
     * Returns a flat list of chord strings per bar (parsed from sections_json),
     * suitable for display or progression matching.
     *
     * Each element is a bar string like "Dm7" or "G7,C7" (two chords in one bar).
     *
     * @return string[]
     */
    public function getBarsAttribute(): array
    {
        $bars = [];
        foreach ($this->sections_json ?? [] as $section) {
            $chordStr = $section['MainSegment']['Chords'] ?? '';
            if ($chordStr) {
                foreach (explode('|', $chordStr) as $bar) {
                    $bars[] = trim($bar);
                }
            }
            // Include ending 1 (most representative) — skip ending 2 for now
            $ending1 = $section['Endings'][0]['Chords'] ?? null;
            if ($ending1) {
                foreach (explode('|', $ending1) as $bar) {
                    $bars[] = trim($bar);
                }
            }
        }
        return array_filter($bars);
    }

    // =========================================================================
    // QUERY HELPERS
    // =========================================================================

    /**
     * Find the best match for a given title string.
     * Used by L3 local-first lookup before calling the LLM.
     *
     * Returns the first exact (case-insensitive) match, then prefix match,
     * then LIKE match. Returns null if nothing found.
     */
    public static function findByTitle(string $rawTitle): ?self
    {
        $normalized = static::normalizeTitle($rawTitle);

        // 1. Exact match
        $exact = static::whereRaw('LOWER(title) = ?', [strtolower($normalized)])->first();
        if ($exact) return $exact;

        // 2. Title starts with the search string
        $prefix = static::where('title', 'like', $normalized . '%')->first();
        if ($prefix) return $prefix;

        // 3. Loose LIKE
        return static::where('title', 'like', '%' . $normalized . '%')->first();
    }

    /**
     * Normalize a title for matching: trim, collapse whitespace, remove common
     * prefixes ("the ", "a ") and punctuation.
     */
    public static function normalizeTitle(string $title): string
    {
        $t = trim($title);
        $t = preg_replace('/\s+/', ' ', $t);
        $t = preg_replace('/^(the |a |an )/i', '', $t);
        $t = rtrim($t, '!?.');
        return $t;
    }

    /**
     * Build a unique slug from a title (suffixes -2, -3, … to avoid collisions).
     */
    public static function generateSlug(string $title): string
    {
        $base = Str::slug($title);
        $slug = $base;
        $i = 2;
        while (static::where('slug', $slug)->exists()) {
            $slug = $base . '-' . $i++;
        }
        return $slug;
    }

    /**
     * Minimal IntermediateAnalysis-compatible array for use in L3 local lookup.
     *
     * Bridges from the jazz standards record to the shape the LLM lookup returns,
     * so the controller can treat both paths identically.
     *
     * @return array{title: string, composer: string|null, key: string|null, tempo: int, timeSignature: string, sections: array, source_note: string, confidence: string}
     */
    public function toIntermediateAnalysis(): array
    {
        $sections = [];
        $timeParts = explode('/', $this->time_signature ?: '4/4');
        $beatsPerBar = (int)($timeParts[0] ?? 4);

        foreach ($this->sections_json ?? [] as $section) {
            $name     = $section['Label'] ?? 'A';
            $chordStr = $section['MainSegment']['Chords'] ?? '';
            
            // iReal Pro pipe bars: "| Dm7 | G7 C7 |"
            $rawBars  = array_filter(array_map('trim', explode('|', $chordStr)));

            $bars = [];
            foreach ($rawBars as $barStr) {
                // Split multiple chords in a bar: "Dm7,G7"
                $chordsInBar = array_filter(array_map('trim', explode(',', $barStr)));
                if (empty($chordsInBar)) {
                    $chordsInBar = ['/']; // fallback for empty bars
                }

                $beatsPerChord = max(1, floor($beatsPerBar / count($chordsInBar)));
                
                $chordObjects = [];
                foreach ($chordsInBar as $cLabel) {
                    $chordObjects[] = [
                        'label' => $cLabel,
                        'beats' => (int)$beatsPerChord,
                    ];
                }
                
                // Handle remainder beat if division wasn't perfect (e.g. 4 beats / 3 chords)
                $sum = array_sum(array_column($chordObjects, 'beats'));
                if ($sum < $beatsPerBar && !empty($chordObjects)) {
                    $chordObjects[0]['beats'] += ($beatsPerBar - $sum);
                }

                $bars[] = ['chords' => $chordObjects];
            }

            $sections[] = [
                'name' => $name,
                'bars' => $bars,
            ];
        }

        return [
            'title'         => $this->title,
            'composer'      => $this->composer,
            'key'           => $this->song_key,
            'tempo'         => 120, 
            'timeSignature' => $this->time_signature ?: '4/4',
            'sections'      => $sections,
            'source_note'   => 'Local Jazz Standards Database (' . $this->source . ')',
            'confidence'    => 'high',
        ];
    }
}
