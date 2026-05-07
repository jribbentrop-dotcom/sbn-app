<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Course extends Model
{
    protected $table = 'sbn_courses';

    protected $guarded = ['id'];

    protected $casts = [
        'genres'   => 'array',
        'levels'   => 'array',
        'topics'   => 'array',
        'is_free'  => 'boolean',
        'wp_id'    => 'integer',
        'sort_order' => 'integer',
    ];

    // =========================================================================
    // RELATIONS
    // =========================================================================

    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class)->orderBy('sort_order');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    // =========================================================================
    // SCOPES
    // =========================================================================

    public function scopePublished($query)
    {
        return $query->where('status', 'publish');
    }

    public function scopeByGenre($query, string $genre)
    {
        return $query->whereJsonContains('genres', $genre);
    }

    public function scopeByLevel($query, string $level)
    {
        return $query->whereJsonContains('levels', $level);
    }

    // =========================================================================
    // ACCESSORS
    // =========================================================================

    public function getPrimaryGenreAttribute(): ?string
    {
        return $this->genres[0] ?? $this->style ?? null;
    }

    public function getPrimaryLevelAttribute(): ?string
    {
        return $this->levels[0] ?? $this->level ?? null;
    }

    public function getLessonCountAttribute(): int
    {
        return $this->lessons()->where('status', 'publish')->count();
    }

    public function getIsGatedAttribute(): bool
    {
        return !$this->is_free && $this->product_id !== null;
    }
}
