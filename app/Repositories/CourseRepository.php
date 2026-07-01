<?php

namespace App\Repositories;

use App\Models\Course;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Fetches courses relevant to a library entity using a two-tier strategy:
 *
 *   Tier 1 — Tag match:  courses that share ≥1 tag with the entity.
 *                        Requires both the entity and at least one course to
 *                        be tagged.  Returns up to $limit results.
 *
 *   Tier 2 — Category fallback: when tier-1 produces no results, fall back to
 *                        courses whose primary_genre (category column) matches
 *                        the entity's genre/category string.
 *
 * Always ordered by sort_order so editorial sequencing is respected.
 */
class CourseRepository
{
    /**
     * Return courses related to $entity, max $limit items.
     *
     * $entity must expose:
     *   - tags()  — MorphToMany relation returning SbnTag models
     *   - a "category" accessor — provided via $categoryResolver
     *
     * @param  Model    $entity           The library entity (Leadsheet, ChordProgression, RhythmPattern, ChordDiagram)
     * @param  string   $entityCategory   The entity's genre/category string (e.g. 'bossa-nova', 'jazz')
     * @param  int      $limit
     * @return Collection<int, array>     Each item is Course::toShelfArray()
     */
    public function relatedTo(Model $entity, string $entityCategory, ?int $limit = 6): Collection
    {
        // --- Tier 1: tag match ---
        $tagIds = $entity->tags()->pluck('sbn_tags.id');

        if ($tagIds->isNotEmpty()) {
            $byTags = Course::published()
                ->whereHas('tags', fn ($q) => $q->whereIn('sbn_tags.id', $tagIds))
                ->orderBy('sort_order')
                ->when($limit !== null, fn ($q) => $q->limit($limit))
                ->get();

            if ($byTags->isNotEmpty()) {
                return $byTags->map(fn ($c) => $c->toShelfArray());
            }
        }

        // --- Tier 2: category fallback ---
        return Course::published()
            ->where('category', $entityCategory)
            ->orderBy('sort_order')
            ->when($limit !== null, fn ($q) => $q->limit($limit))
            ->get()
            ->map(fn ($c) => $c->toShelfArray());
    }

    /**
     * Convenience overload for entities without a tags() relation
     * (e.g. ChordDiagram — no category, just use a raw category string directly).
     * Falls straight through to the category query.
     */
    public function relatedByCategory(string $category, ?int $limit = 6): Collection
    {
        return Course::published()
            ->where('category', $category)
            ->orderBy('sort_order')
            ->when($limit !== null, fn ($q) => $q->limit($limit))
            ->get()
            ->map(fn ($c) => $c->toShelfArray());
    }
}
