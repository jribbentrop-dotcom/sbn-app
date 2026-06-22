<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\RhythmPattern;
use App\Models\SbnTag;
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
    public function store(Request $request)
    {
        [$data, $tagSlugs] = $this->validatePattern($request);

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
    public function update(Request $request, RhythmPattern $rhythm)
    {
        [$data, $tagSlugs] = $this->validatePattern($request, $rhythm->id);

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
    public function updateDescription(Request $request, RhythmPattern $rhythm)
    {
        $validated = $request->validate(['description' => 'nullable|string|max:10000']);
        $rhythm->update(['description' => $validated['description'] ?? '']);
        return response()->json(['success' => true, 'description' => $rhythm->description]);
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

    private function validatePattern(Request $request, ?int $excludeId = null): array
    {
        if ($request->filled('video_snippets') && is_string($request->input('video_snippets'))) {
            $decoded = json_decode($request->input('video_snippets'), true);
            $request->merge(['video_snippets' => is_array($decoded) ? $decoded : []]);
        }

        $data = $request->validate([
            'name'           => 'required|string|max:100',
            'slug'           => [
                'nullable', 'string', 'max:50', 'regex:/^[a-z0-9\-]+$/',
                Rule::unique('sbn_rhythm_patterns', 'slug')->ignore($excludeId),
            ],
            'description'    => 'nullable|string|max:10000',
            'category'       => ['nullable', 'string', Rule::in(RhythmPattern::CATEGORIES)],
            'tags'           => 'nullable|string|max:500',
            'time_signature' => 'nullable|string|in:2/4,3/4,4/4,6/8',
            'beats'          => 'nullable|integer|min:2|max:64',
            'grid_type'      => 'nullable|string|in:eighth,sixteenth,triplet',
            'rhythm_pattern' => 'required|string|max:32|regex:/^[xX\.]+$/',
            'thumb_pattern'  => 'nullable|string|max:32|regex:/^[xX\.]*$/',
            'picking_mode'   => 'nullable|boolean',
            'finger_index'   => 'nullable|string|max:64|regex:/^[xX\.]*$/',
            'finger_middle'  => 'nullable|string|max:64|regex:/^[xX\.]*$/',
            'finger_ring'    => 'nullable|string|max:64|regex:/^[xX\.]*$/',
            'default_bpm'    => 'nullable|integer|min:40|max:240',
            'sound'          => 'nullable|string|max:20',
            'perc_top'       => 'nullable|string|in:none,shaker,tamborim,hihat-brush,brush-snare',
            'perc_bass'      => 'nullable|string|in:none,kick',
            'mp3_file'       => 'nullable|string|max:255',
            'difficulty'     => 'nullable|integer|min:1|max:5',

            // Video snippet library — see docs/SBN-Course-Reference.md §10.
            'video_snippets'             => 'nullable|array',
            'video_snippets.*.id'        => 'required|string|max:64',
            'video_snippets.*.label'     => 'required|string|max:120',
            'video_snippets.*.videoId'   => 'required|string|max:32',
            'video_snippets.*.videoType' => 'required|string|in:youtube,hosted',
            'video_snippets.*.startSec'  => 'required|numeric|min:0',
            'video_snippets.*.endSec'    => 'required|numeric|min:0',
            'video_snippets.*.tempoBpm'  => 'required|numeric|min:20|max:300',
        ]);

        $data['video_snippets'] = $this->normalizeSnippets(
            $data['video_snippets'] ?? [],
            $data['time_signature'] ?? '4/4'
        );

        $tagSlugs = array_filter(
            array_map('trim', explode(',', $data['tags'] ?? '')),
            fn ($s) => $s !== ''
        );
        unset($data['tags']);

        return [$data, array_values($tagSlugs)];
    }

    private function syncTags(RhythmPattern $pattern, array $slugs): void
    {
        $ids = collect($slugs)->map(
            fn ($slug) => SbnTag::findOrCreateBySlug($slug)->id
        )->all();

        $pattern->tags()->sync($ids);
    }

    /**
     * Server-side enforcement of the snippet rules the authoring widget also
     * checks: end-after-start and the ≤16-bar cap. The bar count is derived
     * from the pattern's time signature. Throws a validation exception on
     * violation. See docs/SBN-Course-Reference.md §10.
     */
    private function normalizeSnippets(array $snippets, string $timeSignature): array
    {
        $beatsPerBar = (int) (explode('/', $timeSignature)[0] ?? 4) ?: 4;
        $clean       = [];

        foreach ($snippets as $i => $s) {
            $start = (float) $s['startSec'];
            $end   = (float) $s['endSec'];
            $bpm   = (float) $s['tempoBpm'];

            if ($end <= $start) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    "video_snippets.$i.endSec" => 'End must be after start.',
                ]);
            }

            $bars = (($end - $start) / 60) * $bpm / $beatsPerBar;
            if ($bars > self::MAX_SNIPPET_BARS) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    "video_snippets.$i.endSec" => sprintf(
                        'Snippet spans %.1f bars — keep it to %d or fewer.',
                        $bars, self::MAX_SNIPPET_BARS
                    ),
                ]);
            }

            $clean[] = [
                'id'        => $s['id'],
                'label'     => trim($s['label']),
                'videoId'   => $s['videoId'],
                'videoType' => $s['videoType'],
                'startSec'  => $start,
                'endSec'    => $end,
                'tempoBpm'  => $bpm,
            ];
        }

        return $clean;
    }

    /** Max bars a snippet may span — the legal/architectural cap. */
    private const MAX_SNIPPET_BARS = 16;
}
