<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RhythmPatternDescriptionRequest;
use App\Http\Requests\Admin\RhythmPatternRequest;
use App\Models\RhythmPattern;
use App\Models\SbnTag;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class RhythmPatternController extends Controller
{
    /**
     * List all patterns, grouped by category.
     */
    public function index()
    {
        $patterns   = RhythmPattern::ordered()->get();
        $categories = RhythmPattern::categories();
        $grouped    = $patterns->groupBy('category');

        return view('admin.rhythms.index', compact('patterns', 'categories', 'grouped'));
    }

    /**
     * Show create form.
     */
    public function create()
    {
        $pattern = new RhythmPattern();
        return view('admin.rhythms.edit', [
            'pattern'      => $pattern,
            'isNew'        => true,
            'existingTags' => '',
        ]);
    }

    /**
     * Show edit form.
     */
    public function edit(RhythmPattern $rhythm)
    {
        $existingTags = $rhythm->tags()->pluck('slug')->implode(',');

        return view('admin.rhythms.edit', [
            'pattern'      => $rhythm,
            'isNew'        => false,
            'existingTags' => $existingTags,
        ]);
    }

    /**
     * Store a new pattern (AJAX).
     */
    public function store(RhythmPatternRequest $request)
    {
        [$data, $tagSlugs] = $request->payload();

        if (empty($data['slug'])) {
            $data['slug'] = Str::slug($data['name']);
        }

        $pattern = RhythmPattern::create($data);
        $this->syncTags($pattern, $tagSlugs);

        return response()->json([
            'success' => true,
            'id'      => $pattern->id,
            'slug'    => $pattern->slug,
            'message' => 'Pattern created.',
        ]);
    }

    /**
     * Update an existing pattern (AJAX).
     */
    public function update(RhythmPatternRequest $request, RhythmPattern $rhythm)
    {
        [$data, $tagSlugs] = $request->payload();

        if (empty($data['slug'])) {
            $data['slug'] = Str::slug($data['name']);
        }

        $rhythm->update($data);
        $this->syncTags($rhythm, $tagSlugs);

        return response()->json([
            'success' => true,
            'id'      => $rhythm->id,
            'slug'    => $rhythm->slug,
            'message' => 'Pattern updated.',
        ]);
    }

    /**
     * Delete a pattern (AJAX).
     */
    public function updateDescription(RhythmPatternDescriptionRequest $request, RhythmPattern $rhythm)
    {
        $validated = $request->validated();
        $rhythm->update([
            'intro'   => $validated['intro']   ?? null,
            'details' => $validated['details'] ?? null,
        ]);
        return response()->json(['success' => true, 'intro' => $rhythm->intro, 'details' => $rhythm->details]);
    }

    public function destroy(RhythmPattern $rhythm)
    {
        $rhythm->delete();

        return response()->json([
            'success' => true,
            'message' => 'Pattern deleted.',
        ]);
    }

    // ──────────────────────────────────────────
    // API endpoint — return all patterns as JSON
    // (used by leadsheet admin dropdown, etc.)
    // ──────────────────────────────────────────

    public function apiIndex()
    {
        return response()->json(
            RhythmPattern::ordered()->get()
        );
    }

    /**
     * API: get songs (leadsheets) that reference a rhythm slug.
     */
    public function apiSongs(Request $request)
    {
        $slug = $request->input('slug', '');
        if (empty($slug)) {
            return response()->json(['error' => 'No slug provided'], 422);
        }

        $songs = \DB::table('sbn_leadsheets')
            ->where('rhythm', $slug)
            ->orderBy('title')
            ->get(['id', 'title', 'composer', 'song_key']);

        return response()->json($songs);
    }

    // ──────────────────────────────────────────
    // Private helpers
    // ──────────────────────────────────────────

    private function syncTags(RhythmPattern $pattern, array $slugs): void
    {
        $ids = collect($slugs)->map(
            fn ($slug) => SbnTag::findOrCreateBySlug($slug)->id
        )->all();

        $pattern->tags()->sync($ids);
    }
}
