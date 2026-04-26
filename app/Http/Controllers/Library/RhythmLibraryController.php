<?php

namespace App\Http\Controllers\Library;

use App\Http\Controllers\Controller;
use App\Models\Leadsheet;
use App\Models\RhythmPattern;
use Inertia\Inertia;

class RhythmLibraryController extends Controller
{
    /**
     * Map rhythm categories to music-style color tokens. Keys are matched
     * case-insensitively against the pattern's category column.
     */
    private const CATEGORY_TO_STYLE = [
        'brazilian'   => 'samba',
        'bossa nova'  => 'bossa',
        'bossa'       => 'bossa',
        'jazz'        => 'jazz',
        'latin'       => 'latin',
        'cuban'       => 'latin',
        'blues'       => 'blues',
        'classical'   => 'classical',
        'general'     => 'pop',
    ];

    public function index()
    {
        $patterns = RhythmPattern::ordered()
            ->get()
            ->map(fn ($p) => $this->serializePattern($p));

        // Group by category for display
        $grouped = $patterns->groupBy('category');

        // Get unique filter values
        $categories = $patterns->pluck('category')->unique()->sort()->values()->all();
        $timeSignatures = $patterns->pluck('timeSignature')->unique()->sort()->values()->all();
        $gridTypes = $patterns->pluck('gridType')->unique()->sort()->values()->all();

        return Inertia::render('Library/Rhythms/Index', [
            'patterns' => $patterns,
            'grouped' => $grouped,
            'categories' => $categories,
            'timeSignatures' => $timeSignatures,
            'gridTypes' => $gridTypes,
            'totalCount' => $patterns->count(),
        ]);
    }

    public function show(string $slug)
    {
        $pattern = RhythmPattern::where('slug', $slug)->firstOrFail();

        // Get siblings (other patterns in same category)
        $siblings = RhythmPattern::where('category', $pattern->category)
            ->where('id', '!=', $pattern->id)
            ->ordered()
            ->limit(6)
            ->get()
            ->map(fn ($p) => $this->serializePattern($p));

        // Songs that use this rhythm pattern
        $songs = Leadsheet::where('rhythm', $pattern->slug)
            ->orderByDesc('popularity')
            ->limit(8)
            ->get()
            ->map(fn ($s) => [
                'id'       => $s->id,
                'slug'     => $s->slug,
                'title'    => $s->title,
                'composer' => $s->composer,
                'songKey'  => $s->song_key,
            ]);

        return Inertia::render('Library/Rhythms/Show', [
            'pattern'  => $this->serializePattern($pattern),
            'siblings' => $siblings,
            'songs'    => $songs,
        ]);
    }

    private function serializePattern(RhythmPattern $pattern): array
    {
        $styleSlug = self::CATEGORY_TO_STYLE[strtolower($pattern->category ?? '')] ?? 'pop';

        return [
            'id' => $pattern->id,
            'slug' => $pattern->slug,
            'name' => $pattern->name,
            'description' => $pattern->description,
            'category' => $pattern->category,
            'styleSlug' => $styleSlug,
            'timeSignature' => $pattern->time_signature,
            'beats' => $pattern->beats,
            'gridType' => $pattern->grid_type,
            'bpm' => $pattern->default_bpm,
            'thumb' => $pattern->thumb_pattern,
            'fingers' => $pattern->rhythm_pattern,
            'percTop' => $pattern->perc_top,
            'percBass' => $pattern->perc_bass,
            'demoUrl' => $pattern->mp3_file ? '/audio/rhythm-demos/' . $pattern->mp3_file : null,
        ];
    }
}
