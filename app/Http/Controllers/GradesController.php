<?php

namespace App\Http\Controllers;

use App\Models\ChordDiagram;
use App\Models\ChordProgression;
use App\Models\Course;
use App\Models\Leadsheet;
use App\Models\RhythmPattern;
use Inertia\Inertia;

class GradesController extends Controller
{
    private const LIMIT = 4;

    private const LEVEL_SLUGS = [
        1 => 'basic',
        2 => 'early-intermediate',
        3 => 'intermediate',
        4 => 'late-intermediate',
        5 => 'advanced',
    ];

    public function index()
    {
        $panels = [];

        foreach (self::LEVEL_SLUGS as $grade => $slug) {
            $panels[$grade] = [
                'chords'       => $this->chords($grade),
                'rhythms'      => $this->rhythms($grade),
                'progressions' => $this->progressions($grade),
                'songs'        => $this->songs($grade),
                'courses'      => $this->courses($slug),
            ];
        }

        return Inertia::render('Grades/Index', compact('panels'));
    }

    private function chords(int $grade): array
    {
        return ChordDiagram::where('difficulty', $grade)
            ->orderByDesc('popularity')
            ->limit(self::LIMIT)
            ->get(['id', 'slug', 'name', 'root_note', 'quality', 'quality_label', 'difficulty'])
            ->map(fn ($c) => [
                'id'           => $c->id,
                'slug'         => $c->slug,
                'name'         => $c->name,
                'url'          => route('library.chords.show', $c->slug),
            ])
            ->values()
            ->all();
    }

    private function rhythms(int $grade): array
    {
        return RhythmPattern::where('difficulty', $grade)
            ->orderBy('sort_order')
            ->limit(self::LIMIT)
            ->get(['id', 'slug', 'name', 'category'])
            ->map(fn ($r) => [
                'id'       => $r->id,
                'slug'     => $r->slug,
                'name'     => $r->name,
                'category' => $r->category,
                'url'      => route('library.rhythms.show', $r->slug),
            ])
            ->values()
            ->all();
    }

    private function progressions(int $grade): array
    {
        return ChordProgression::where('difficulty', $grade)
            ->orderBy('sort_order')
            ->limit(self::LIMIT)
            ->get(['id', 'slug', 'name', 'numerals', 'category'])
            ->map(fn ($p) => [
                'id'       => $p->id,
                'slug'     => $p->slug,
                'name'     => $p->name,
                'numerals' => $p->numerals_display,
                'category' => $p->category,
                'url'      => route('library.progressions.show', $p->slug),
            ])
            ->values()
            ->all();
    }

    private function songs(int $grade): array
    {
        return Leadsheet::published()
            ->where('difficulty', $grade)
            ->orderByDesc('popularity')
            ->limit(self::LIMIT)
            ->get(['id', 'slug', 'title', 'composer', 'song_key', 'difficulty'])
            ->map(fn ($s) => [
                'id'       => $s->id,
                'slug'     => $s->slug,
                'title'    => $s->title,
                'composer' => $s->composer,
                'key'      => $s->song_key,
                'url'      => route('library.songs.show', $s->slug),
            ])
            ->values()
            ->all();
    }

    private function courses(string $levelSlug): array
    {
        return Course::published()
            ->byLevel($levelSlug)
            ->orderBy('sort_order')
            ->limit(self::LIMIT)
            ->get(['id', 'slug', 'title', 'levels', 'featured_image_path'])
            ->map(fn ($c) => [
                'id'    => $c->id,
                'slug'  => $c->slug,
                'title' => $c->title,
                'image' => $c->featured_image_path,
                'url'   => route('courses.show', $c->slug),
            ])
            ->values()
            ->all();
    }
}
