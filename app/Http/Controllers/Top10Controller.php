<?php

namespace App\Http\Controllers;

use App\Models\ChordDiagram;
use App\Services\ChordSerializer;
use Inertia\Inertia;

class Top10Controller extends Controller
{
    public function __construct(
        private ChordSerializer $chordSerializer
    ) {}

    public function bossaNovaChords()
    {
        $chordConfig = require config_path('top10/bossa-nova-chords.php');

        $allSlugs = array_keys($chordConfig);
        $allProgressionSlugs = collect($chordConfig)->flatMap(fn ($c) => $c['progressionSlugs'])->unique()->toArray();
        $allRequiredSlugs = array_unique(array_merge($allSlugs, $allProgressionSlugs));

        $chords = ChordDiagram::whereIn('slug', $allRequiredSlugs)
            ->get()
            ->map(fn ($c) => $this->chordSerializer->serialize($c))
            ->keyBy('slug');

        $top10Data = collect($chordConfig)->map(function ($config, $slug) use ($chords) {
            $progressionTiles = collect($config['progressionSlugs'])->map(function ($progressionSlug) use ($chords) {
                $tileData = $chords[$progressionSlug] ?? null;
                return [
                    'chordName' => $tileData['name'] ?? $progressionSlug,
                    'diagramData' => $tileData,
                ];
            })->toArray();

            return array_merge($config, [
                'slug' => $slug,
                'voicingData' => $chords[$slug] ?? null,
                'progressionTiles' => $progressionTiles,
                'relatedProducts' => $config['relatedProducts'] ?? [],
            ]);
        })->values();

        return Inertia::render('Top10/BossaNovaChords', [
            'top10Data' => $top10Data,
        ]);
    }
}
