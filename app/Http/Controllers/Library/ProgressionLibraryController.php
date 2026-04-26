<?php

namespace App\Http\Controllers\Library;

use App\Http\Controllers\Controller;
use App\Models\ChordDiagram;
use App\Models\ChordProgression;
use App\Models\Leadsheet;
use App\Services\HarmonicContext;
use App\Services\ProgressionBuilder;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ProgressionLibraryController extends Controller
{
    public function __construct(
        protected ProgressionBuilder $progressionBuilder,
        protected HarmonicContext $harmonicContext,
    ) {
    }

    public function index(Request $request)
    {
        $category = $request->get('category');
        $search = $request->get('search');
        $sort = $request->get('sort', 'popularity');

        $progressions = ChordProgression::withSongCounts($category, $search)
            ->map(fn ($p) => $this->serializeProgression($p));

        // Apply sorting
        if ($sort === 'name') {
            $progressions = $progressions->sortBy('name')->values();
        } elseif ($sort === 'category') {
            $progressions = $progressions->sortBy('category')->values();
        } else {
            // Default: sort by song count (popularity)
            $progressions = $progressions->sortByDesc('songCount')->values();
        }

        return Inertia::render('Library/Progressions/Index', [
            'progressions' => $progressions,
            'categories' => ChordProgression::usedCategories(),
            'tags' => $this->getAllTags(),
            'totalCount' => $progressions->count(),
            'activeFilters' => [
                'category' => $category,
                'search' => $search,
                'sort' => $sort,
            ],
        ]);
    }

    public function show(string $slug)
    {
        $progression = ChordProgression::where('slug', $slug)
            ->firstOrFail();

        // Get songs featuring this progression
        $songs = Leadsheet::query()
            ->join('sbn_progression_occurrences as o', 'sbn_leadsheets.id', '=', 'o.leadsheet_id')
            ->where('o.progression_id', $progression->id)
            ->select('sbn_leadsheets.id', 'sbn_leadsheets.slug', 'sbn_leadsheets.title', 'sbn_leadsheets.composer', 'sbn_leadsheets.song_key')
            ->orderBy('sbn_leadsheets.title')
            ->get()
            ->map(fn ($s) => [
                'id'       => $s->id,
                'title'    => $s->title,
                'composer' => $s->composer,
                'songKey'  => $s->song_key,
                'slug'     => $s->slug,
            ]);

        // Get siblings (other progressions in same category)
        $siblings = ChordProgression::where('category', $progression->category)
            ->where('id', '!=', $progression->id)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn ($p) => $this->serializeProgression($p));

        // Resolve chord diagram tiles via the proper voice-leading path
        $context = $this->harmonicContext->buildFromNumerals('C', $progression->numerals);
        $built   = $this->progressionBuilder->buildVoicings($context);
        $tiles   = array_map(function ($sel) {
            $v = $sel['voicing'] ?? null;
            return [
                'chordName'   => $sel['chord_name'],
                'diagramData' => $v,
                'slug'        => null,
            ];
        }, $built['selections']);

        return Inertia::render('Library/Progressions/Show', [
            'progression' => $this->serializeProgression($progression),
            'songs'       => $songs,
            'siblings'    => $siblings,
            'tiles'       => $tiles,
        ]);
    }

    private function serializeChordDiagram(ChordDiagram $diagram): array
    {
        return [
            'id'               => $diagram->id,
            'slug'             => $diagram->slug,
            'name'             => $diagram->name,
            'root_note'        => $diagram->root_note,
            'quality'          => $diagram->quality,
            'quality_label'    => $diagram->quality_label,
            'extensions'       => $diagram->extensions,
            'voicing_category' => $diagram->voicing_category,
            'category_label'   => $diagram->category_label,
            'root_string'      => $diagram->root_string,
            'root_string_label'=> $diagram->root_string_label,
            'inversion'        => $diagram->inversion ?? 'root',
            'inversion_label'  => $diagram->inversion_label,
            'bass_note'        => $diagram->bass_note,
            'shape_family'     => $diagram->shape_family,
            'start_fret'       => $diagram->start_fret ?? 1,
            'diagram_data'     => json_decode($diagram->diagram_data ?? '{}', true) ?: ['positions' => [], 'barres' => [], 'muted' => [], 'open' => []],
            'interval_labels'  => $diagram->interval_labels,
            'notes'            => $diagram->notes,
            'popularity'       => $diagram->popularity,
            'difficulty'       => $diagram->difficulty,
            'description'      => $diagram->description,
        ];
    }

    private function serializeProgression(ChordProgression $progression): array
    {
        // Map progression categories to style slugs for colors
        $styleSlug = $this->mapCategoryToStyleSlug($progression->category);

        return [
            'id' => $progression->id,
            'slug' => $progression->slug,
            'name' => $progression->name,
            'category' => $progression->category,
            'styleSlug' => $styleSlug,
            'numerals' => $progression->numerals,
            'numeralsDisplay' => $progression->numerals_display,
            'tonality' => $progression->tonality,
            'tags' => $progression->tags_array,
            'description' => $progression->description,
            'typicalGenres' => $progression->typical_genres,
            'chordCount' => count(explode(',', $progression->numerals)),
            'songCount' => $progression->song_count ?? 0,
        ];
    }

    private function mapCategoryToStyleSlug(string $category): string
    {
        $mapping = [
            'jazz' => 'jazz',
            'blues' => 'blues',
            'pop' => 'pop',
            'modal' => 'pop', // Modal uses pop colors
            'classical' => 'classical',
            'latin' => 'latin',
            'other' => 'bossa', // Other uses default bossa color
        ];

        return $mapping[$category] ?? 'bossa';
    }

    private function getAllTags(): array
    {
        $tags = ChordProgression::whereNotNull('tags')
            ->where('tags', '!=', '')
            ->pluck('tags')
            ->flatMap(function ($tags) {
                return array_map('trim', explode(',', $tags));
            })
            ->unique()
            ->sort()
            ->values()
            ->toArray();

        return $tags;
    }
}
