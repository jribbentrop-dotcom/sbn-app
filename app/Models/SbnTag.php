<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class SbnTag extends Model
{
    protected $table = 'sbn_tags';

    protected $fillable = ['slug', 'label'];

    public function rhythmPatterns(): MorphToMany
    {
        return $this->morphedByMany(RhythmPattern::class, 'taggable', 'sbn_taggables', 'tag_id', 'taggable_id');
    }

    public function chordProgressions(): MorphToMany
    {
        return $this->morphedByMany(ChordProgression::class, 'taggable', 'sbn_taggables', 'tag_id', 'taggable_id');
    }

    public function leadsheets(): MorphToMany
    {
        return $this->morphedByMany(Leadsheet::class, 'taggable', 'sbn_taggables', 'tag_id', 'taggable_id');
    }

    /** Find or create a tag by slug, deriving label from slug if new. */
    public static function findOrCreateBySlug(string $slug): self
    {
        return static::firstOrCreate(
            ['slug' => $slug],
            ['label' => ucwords(str_replace('-', ' ', $slug))]
        );
    }
}
