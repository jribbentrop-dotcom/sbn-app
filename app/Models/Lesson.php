<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Lesson extends Model
{
    protected $table = 'sbn_lessons';

    protected $guarded = ['id'];

    protected $casts = [
        'is_preview'  => 'boolean',
        'wp_id'       => 'integer',
        'sort_order'  => 'integer',
    ];

    protected static function booted()
    {
        static::saving(function ($lesson) {
            if ($lesson->content) {
                $lesson->content = preg_replace_callback('/<h2([^>]*)>(.*?)<\/h2>/is', function($matches) {
                    $attrs = $matches[1];
                    $title = strip_tags($matches[2]);
                    $slug = 'section-' . \Illuminate\Support\Str::slug($title);
                    
                    if (str_contains($attrs, "id=\"$slug\"") || str_contains($attrs, "id='$slug'")) {
                        return $matches[0];
                    }
                    
                    $attrs = preg_replace('/ id=["\'][^"\']*["\']/', '', $attrs);
                    return "<h2{$attrs} id=\"{$slug}\">{$matches[2]}</h2>";
                }, $lesson->content);
            }
        });
    }

    // =========================================================================
    // RELATIONS
    // =========================================================================

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    // =========================================================================
    // SCOPES
    // =========================================================================

    public function scopePublished($query)
    {
        return $query->where('status', 'publish');
    }

    // =========================================================================
    // ACCESSORS
    // =========================================================================

    /**
     * Parse H2 headings from lesson content into subsection list.
     * Replicates legacy sbn_parse_subsections() PHP function.
     * Returns [['title' => '...', 'slug' => '...'], ...]
     */
    public function getSubsectionsAttribute(): array
    {
        if (!$this->content) {
            return [];
        }

        preg_match_all('/<h2[^>]*id=["\']section-([^"\']+)["\'][^>]*>(.*?)<\/h2>/is', $this->content, $matches);

        if (empty($matches[0])) {
            // Also match h2s without id; slug derived from title
            preg_match_all('/<h2[^>]*>(.*?)<\/h2>/is', $this->content, $matches2);
            $subsections = [];
            foreach ($matches2[1] as $title) {
                $title = strip_tags($title);
                $slug  = 'section-' . \Illuminate\Support\Str::slug($title);
                $subsections[] = compact('title', 'slug');
            }
            return $subsections;
        }

        $subsections = [];
        foreach ($matches[1] as $i => $slug) {
            $title = strip_tags($matches[2][$i]);
            $subsections[] = ['title' => $title, 'slug' => 'section-' . $slug];
        }
        return $subsections;
    }
}
