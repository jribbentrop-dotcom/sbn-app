<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RhythmPatternRequest;
use App\Http\Requests\Admin\UpdateRhythmDescriptionRequest;
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
        [$data, $tagSlugs] = $this->normalizePatternData($request->validated());

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
        [$data, $tagSlugs] = $this->normalizePatternData($request->validated());

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
    public function updateDescription(UpdateRhythmDescriptionRequest $request, RhythmPattern $rhythm)
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

    private function normalizePatternData(array $data): array
    {
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
