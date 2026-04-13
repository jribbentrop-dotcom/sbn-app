<?php

namespace App\Http\Controllers\Admin;

use App\Models\ChordProgression;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Http\Controllers\Controller;
use App\Services\ProgressionDetector;

class ProgressionController extends Controller
{
    /**
     * Index page — Library + Occurrences tabs.
     */
    public function index(Request $request)
    {
        $category = $request->input('category', '');
        $search   = $request->input('q', '');

        $progressions = ChordProgression::withSongCounts($category, $search);
        $categories   = ChordProgression::usedCategories();
        $stats        = ChordProgression::getStats();

        // Occurrences tab data
        $filterProgId = $request->input('prog_id');
        $filterLsId   = $request->input('ls_id');
        $occurrenceGroups = ChordProgression::getOccurrencesGrouped($filterProgId, $filterLsId);
        $occurrenceFilters = ChordProgression::getOccurrenceFilters();

        // JSON data for client-side sortable table
        $progressionsJson = $progressions->map(function ($p) {
            return [
                'id'               => $p->id,
                'name'             => $p->name,
                'category'         => $p->category,
                'cat_color'        => $p->category_color,
                'numerals_display' => $p->numerals_display,
                'tonality'         => $p->tonality ?? 'both',
                'match_mode'       => $p->match_mode ?? 'strict',
                'featured'         => (bool) $p->featured,
                'song_count'       => (int) $p->song_count,
                'desc'             => $p->description ? Str::words($p->description, 18) : '',
                'edit_url'         => route('admin.progressions.edit', $p->id),
            ];
        })->values();

        return view('admin.progressions.index', compact(
            'progressions',
            'progressionsJson',
            'categories',
            'stats',
            'category',
            'search',
            'occurrenceGroups',
            'occurrenceFilters',
            'filterProgId',
            'filterLsId',
        ));
    }

    /**
     * Show create form.
     */
    public function create()
    {
        return view('admin.progressions.edit', [
            'progression' => null,
        ]);
    }

    /**
     * Show edit form.
     */
    public function edit(ChordProgression $progression)
    {
        // Load occurrences for this progression
        $occurrences = DB::table('sbn_progression_occurrences as o')
            ->join('sbn_leadsheets as l', 'o.leadsheet_id', '=', 'l.id')
            ->where('o.progression_id', $progression->id)
            ->select('o.*', 'l.title as leadsheet_title', 'l.song_key')
            ->orderBy('l.title')
            ->get();

        return view('admin.progressions.edit', compact('progression', 'occurrences'));
    }

    /**
     * Store a new progression.
     */
    public function store(Request $request)
    {
        $validated = $this->validateProgression($request);

        $progression = ChordProgression::create($validated + [
            'created_at' => now(),
        ]);

        return redirect()
            ->route('admin.progressions.index')
            ->with('success', "Created \"{$progression->name}\".");
    }

    /**
     * Update an existing progression.
     */
    public function update(Request $request, ChordProgression $progression)
    {
        $validated = $this->validateProgression($request);

        $progression->update($validated);

        return redirect()
            ->route('admin.progressions.index')
            ->with('success', "Updated \"{$progression->name}\".");
    }

    /**
     * Delete a progression + its occurrences (AJAX).
     */
    public function destroy(ChordProgression $progression)
    {
        $name = $progression->name;

        // Delete occurrences first
        DB::table('sbn_progression_occurrences')
            ->where('progression_id', $progression->id)
            ->delete();

        $progression->delete();

        if (request()->expectsJson()) {
            return response()->json(['success' => true, 'message' => "Deleted \"{$name}\"."]);
        }

        return redirect()
            ->route('admin.progressions.index')
            ->with('success', "Deleted \"{$name}\".");
    }

    /**
     * Reprocess all leadsheets (AJAX).
      */
    public function reprocess(Request $request)
    {
    $detector = app(ProgressionDetector::class);
    $result = $detector->processAllLeadsheets();

    return response()->json([
        'success'   => true,
        'processed' => $result['processed'],
        'total_occ' => $result['total_occurrences'],
    ]);
    }

    /**
     * Toggle featured status (AJAX).
     */
    public function toggleFeatured(ChordProgression $progression)
    {
        $progression->update(['featured' => !$progression->featured]);

        return response()->json([
            'success'  => true,
            'featured' => $progression->featured,
        ]);
    }

    /* ── Private ─────────────────────────────────────────────── */

    private function validateProgression(Request $request): array
    {
        $data = $request->validate([
            'name'           => 'required|string|max:120',
            'category'       => 'required|string|in:' . implode(',', ChordProgression::CATEGORIES),
            'numerals'       => 'required|string|max:255',
            'description'    => 'nullable|string',
            'typical_genres' => 'nullable|string|max:255',
            'tags'           => 'nullable|string|max:255',
            'tonality'       => 'required|string|in:both,major,minor',
            'match_mode'     => 'required|string|in:strict,degree',
            'sort_order'     => 'nullable|integer',
            'featured'       => 'nullable|boolean',
        ]);

        // Normalize
        $data['sort_order'] = $data['sort_order'] ?? 0;
        $data['featured']   = $data['featured'] ?? false;
        $data['tags']       = $data['tags'] ?? '';

        return $data;
    }
}
