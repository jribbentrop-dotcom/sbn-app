<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\RhythmPattern;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

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
            'pattern' => $pattern,
            'isNew'   => true,
        ]);
    }

    /**
     * Show edit form.
     */
    public function edit(RhythmPattern $rhythm)
    {
        return view('admin.rhythms.edit', [
            'pattern' => $rhythm,
            'isNew'   => false,
        ]);
    }

    /**
     * Store a new pattern (AJAX).
     */
    public function store(Request $request)
    {
        $data = $this->validatePattern($request);

        // Auto-generate slug from name if blank
        if (empty($data['slug'])) {
            $data['slug'] = Str::slug($data['name']);
        }

        $pattern = RhythmPattern::create($data);

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
    public function update(Request $request, RhythmPattern $rhythm)
    {
        $data = $this->validatePattern($request, $rhythm->id);

        if (empty($data['slug'])) {
            $data['slug'] = Str::slug($data['name']);
        }

        $rhythm->update($data);

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

    private function validatePattern(Request $request, ?int $excludeId = null): array
    {
        return $request->validate([
            'name'           => 'required|string|max:100',
            'slug'           => [
                'nullable', 'string', 'max:50', 'regex:/^[a-z0-9\-]+$/',
                Rule::unique('sbn_rhythm_patterns', 'slug')->ignore($excludeId),
            ],
            'description'    => 'nullable|string|max:1000',
            'category'       => 'nullable|string|max:50',
            'time_signature' => 'nullable|string|in:2/4,3/4,4/4,6/8',
            'beats'          => 'nullable|integer|min:2|max:64',
            'grid_type'      => 'nullable|string|in:eighth,sixteenth,triplet',
            'rhythm_pattern' => 'required|string|max:32|regex:/^[xX\.]+$/',
            'thumb_pattern'  => 'nullable|string|max:32|regex:/^[xX\.]*$/',
            'default_bpm'    => 'nullable|integer|min:40|max:240',
            'sound'          => 'nullable|string|max:20',
            'perc_top'       => 'nullable|string|in:none,shaker,tamborim,hihat-brush,brush-snare',
            'perc_bass'      => 'nullable|string|in:none,kick',
            'mp3_file'       => 'nullable|string|max:255',
        ]);
    }
}
