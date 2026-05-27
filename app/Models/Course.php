<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class Course extends Model
{
    protected $table = 'sbn_courses';

    protected $guarded = ['id'];

    protected $casts = [
        'levels'     => 'array',
        'topics'     => 'array',
        'is_free'    => 'boolean',
        'wp_id'      => 'integer',
        'sort_order' => 'integer',
    ];

    public const PRESET_TAGS = [
        'blues', 'modal', 'latin', 'cuban', 'brazilian',
        'swing', 'fingerpicking', 'chord voicings', 'sight reading',
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

    public function tags(): MorphToMany
    {
        return $this->morphToMany(SbnTag::class, 'taggable', 'sbn_taggables', 'taggable_id', 'tag_id');
    }

    // =========================================================================
    // SCOPES
    // =========================================================================

    public function scopePublished($query)
    {
        return $query->where('status', 'publish');
    }

    public function scopeByCategory($query, string $category)
    {
        return $query->where('category', $category);
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
        return $this->category ?? $this->style ?? null;
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
