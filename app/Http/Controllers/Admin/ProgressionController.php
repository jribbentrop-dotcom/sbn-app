<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\ChordProgressionRequest;
use App\Http\Requests\Admin\UpdateProgressionDescriptionRequest;
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
                'alt_count'        => is_array($p->alt_numerals) ? count($p->alt_numerals) : 0,
                'desc'             => $p->description ? Str::words(strip_tags($p->description), 18) : '',
                'description'      => $p->description ?? '',
                'slug'             => $p->slug,
                'edit_url'         => route('admin.progressions.edit', $p->id),
                'show_url'         => route('library.progressions.show', $p->slug),
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
    public function store(ChordProgressionRequest $request)
    {
        $validated = $this->normalizeProgressionData($request->validated());
        $validated['slug'] = $this->resolveSlugForSave($validated);

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
    public function update(ChordProgressionRequest $request, ChordProgression $progression)
    {
        $validated = $this->normalizeProgressionData($request->validated());
        $validated['slug'] = $this->resolveSlugForSave($validated, $progression);

        $progression->update($validated);

        return redirect()
            ->route('admin.progressions.index')
            ->with('success', "Updated \"{$progression->name}\".");
    }

    /**
     * Delete a progression + its occurrences (AJAX).
     */
    public function updateDescription(UpdateProgressionDescriptionRequest $request, ChordProgression $progression)
    {
        $validated = $request->validated();
        $progression->update([
            'intro'   => $validated['intro']   ?? null,
            'details' => $validated['details'] ?? null,
        ]);
        return response()->json(['success' => true, 'intro' => $progression->intro, 'details' => $progression->details]);
    }

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
     * Rebuild the harmonic-fragments constant file from the DB (AJAX).
     *
     * Runs the sbn:reseed-fragments command. The generated file at
     * storage/app/harmonic-fragments.generated.php is what the Phase 2
     * HarmonicPatternMatcher loads at runtime.
     */
    public function reseedFragments(Request $request)
    {
        \Illuminate\Support\Facades\Artisan::call('sbn:reseed-fragments');
        $output = trim(\Illuminate\Support\Facades\Artisan::output());

        $progressions = 0;
        $fragments = 0;
        if (preg_match('/Flattened (\d+) progressions \xE2\x86\x92 (\d+) fragments/u', $output, $m)) {
            $progressions = (int) $m[1];
            $fragments = (int) $m[2];
        }

        return response()->json([
            'success'      => true,
            'progressions' => $progressions,
            'fragments'    => $fragments,
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

    private function normalizeProgressionData(array $data): array
    {
        $data['sort_order']     = $data['sort_order'] ?? 0;
        $data['featured']       = $data['featured'] ?? false;
        $data['tags']           = $data['tags'] ?? '';
        $data['video_snippets'] = $this->normalizeSnippets($data['video_snippets'] ?? []);
        $data['alt_numerals']   = $data['alt_numerals'] ?? null;

        return $data;
    }

    /**
     * Server-side enforcement of the snippet rules the authoring widget also
     * checks: end-after-start and the ≤16-bar cap (plan §2/§7). Progressions
     * have no time signature, so a 4-beat bar is assumed (matches the widget's
     * default). Throws a validation exception on violation.
     */
    private function normalizeSnippets(array $snippets): array
    {
        $beatsPerBar = 4;
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

            $entry = [
                'id'        => $s['id'],
                'label'     => trim($s['label']),
                'videoId'   => $s['videoId'],
                'videoType' => $s['videoType'],
                'startSec'  => $start,
                'endSec'    => $end,
                'tempoBpm'  => $bpm,
            ];

            if (!empty($s['key'])) {
                $entry['key'] = trim($s['key']);
            }
            if (!empty($s['chords']) && is_array($s['chords'])) {
                $entry['chords'] = array_values(array_map('strval', $s['chords']));
            }

            $clean[] = $entry;
        }

        return $clean;
    }

    /** Max bars a snippet may span — the legal/architectural cap (plan §2/§7). */
    private const MAX_SNIPPET_BARS = 16;

    private function resolveSlugForSave(array $data, ?ChordProgression $existing = null): string
    {
        if (array_key_exists('slug', $data)) {
            $base = trim((string) $data['slug']);
        } elseif ($existing && !empty($existing->slug)) {
            $base = (string) $existing->slug;
        } else {
            $base = '';
        }

        if ($base === '') {
            $base = Str::slug((string) ($data['name'] ?? ''));
        }
        if ($base === '') {
            $base = 'progression';
        }

        $excludeId = $existing?->id;
        return $this->ensureUniqueSlug($base, $excludeId);
    }

    private function ensureUniqueSlug(string $slug, ?int $excludeId = null): string
    {
        $slug = strtolower(trim($slug));
        $slug = preg_replace('/[^a-z0-9\-]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');

        if ($slug === '') {
            $slug = 'progression';
        }

        $query = ChordProgression::where('slug', $slug);
        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }
        if (!$query->exists()) {
            return $slug;
        }

        $counter = 2;
        do {
            $candidate = $slug . '-' . $counter;
            $q = ChordProgression::where('slug', $candidate);
            if ($excludeId !== null) {
                $q->where('id', '!=', $excludeId);
            }
            $exists = $q->exists();
            $counter++;
        } while ($exists);

        return $candidate;
    }
}
