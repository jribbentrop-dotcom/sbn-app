<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RhythmPattern extends Model
{
    /**
     * The table associated with the model.
     * (Matches the imported WordPress table — no wp_ prefix.)
     */
    protected $table = 'sbn_rhythm_patterns';

    /**
     * Mass-assignable attributes.
     */
    protected $fillable = [
        'slug',
        'name',
        'description',
        'category',
        'time_signature',
        'beats',
        'grid_type',
        'rhythm_pattern',
        'thumb_pattern',
        'default_bpm',
        'sound',
        'perc_top',
        'perc_bass',
        'mp3_file',
        'is_default',
        'sort_order',
    ];

    /**
     * Attribute defaults for new patterns.
     */
    protected $attributes = [
        'category'       => 'general',
        'time_signature' => '4/4',
        'beats'          => 8,
        'grid_type'      => 'sixteenth',
        'rhythm_pattern' => '........',
        'thumb_pattern'  => '',
        'default_bpm'    => 120,
        'sound'          => 'guitar',
        'perc_top'       => 'none',
        'perc_bass'      => 'none',
        'mp3_file'       => '',
        'is_default'     => 0,
        'sort_order'     => 0,
    ];

    /**
     * Cast columns to proper PHP types.
     */
    protected $casts = [
        'beats'       => 'integer',
        'default_bpm' => 'integer',
        'is_default'  => 'boolean',
        'sort_order'  => 'integer',
    ];

    // ──────────────────────────────────────────
    // Scopes
    // ──────────────────────────────────────────

    public function scopeOrdered($query)
    {
        return $query->orderBy('category')->orderBy('sort_order')->orderBy('name');
    }

    public function scopeCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    public function scopeDefaults($query)
    {
        return $query->where('is_default', true);
    }

    // ──────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────

    /**
     * All distinct categories currently in the table.
     */
    public static function categories(): array
    {
        return static::query()
            ->select('category')
            ->distinct()
            ->orderBy('category')
            ->pluck('category')
            ->toArray();
    }

    /**
     * Data array formatted for the leadsheet parser / frontend player.
     */
    public function toPlayerData(): array
    {
        return [
            'name'          => $this->name,
            'beats'         => $this->beats,
            'gridType'      => $this->grid_type,
            'thumb'         => $this->thumb_pattern,
            'fingers'       => $this->rhythm_pattern,
            'bpm'           => $this->default_bpm,
            'timeSignature' => $this->time_signature,
            'percTop'       => $this->perc_top,
            'percBass'      => $this->perc_bass,
            'styleSlug'     => $this->style_slug ?? 'general',
            'demoUrl'       => $this->mp3_file ? asset('audio/rhythm-demos/' . $this->mp3_file) : null,
        ];
    }

    /**
     * Slug-indexed key→value list for dropdowns / selects.
     */
    public static function forSelect(): array
    {
        return static::ordered()
            ->get(['slug', 'name', 'category'])
            ->mapWithKeys(fn ($p) => [
                $p->slug => ['name' => $p->name, 'category' => $p->category],
            ])
            ->toArray();
    }
}
