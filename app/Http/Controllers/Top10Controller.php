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
        $top10Data = $this->getTop10Data('bossa-nova-chords');

        return Inertia::render('Top10/BossaNovaChords', [
            'top10Data' => $top10Data,
        ]);
    }

    public function latinJazzStandards()
    {
        $top10Data = $this->getTop10Data('latin-jazz-standards');

        return Inertia::render('Top10/LatinJazzStandards', [
            'top10Data' => $top10Data,
        ]);
    }

    public function bossaNovaSongs()
    {
        $top10Data = $this->getTop10Data('bossa-nova-songs');

        return Inertia::render('Top10/BossaNovaSongs', [
            'top10Data' => $top10Data,
        ]);
    }

    private function getTop10Data(string $configName)
    {
        $configPath = config_path("top10/{$configName}.php");
        if (!file_exists($configPath)) {
            return collect();
        }

        $chordConfig = require $configPath;

        $allSlugs = array_keys($chordConfig);
        $allProgressionSlugs = collect($chordConfig)->flatMap(fn ($c) => $c['progressionSlugs'] ?? [])->unique()->toArray();
        $allRhythmSlugs = collect($chordConfig)->map(fn ($c) => $c['rhythmSlug'] ?? null)->filter()->unique()->toArray();
        $allRequiredChordSlugs = array_unique(array_merge($allSlugs, $allProgressionSlugs));

        $chords = \App\Models\ChordDiagram::whereIn('slug', $allRequiredChordSlugs)
            ->get()
            ->keyBy('slug');

        $rhythms = \App\Models\RhythmPattern::whereIn('slug', $allRhythmSlugs)
            ->get()
            ->mapWithKeys(fn ($r) => [$r->slug => $r->toPlayerData()])
            ->toArray();

        return collect($chordConfig)->map(function ($config, $slug) use ($chords, $rhythms) {
            $rootOverride = $config['rootOverride'] ?? null;
            $bassOverride = $config['bassOverride'] ?? null;
            $mainChordModel = $chords[$slug] ?? null;
            
            $voicingData = null;
            if ($mainChordModel) {
                $voicingData = ($bassOverride) 
                    ? $this->chordSerializer->serializeWithBass($mainChordModel, $rootOverride ?? $mainChordModel->root_note ?? 'C', $bassOverride)
                    : $this->chordSerializer->serialize($mainChordModel, $rootOverride);
            }

            $progressionTiles = collect($config['progressionSlugs'] ?? [])->map(function ($progressionSlug, $index) use ($chords, $config) {
                $chordModel = $chords[$progressionSlug] ?? null;
                $rootOverride = $config['progressionRoots'][$index] ?? null;
                $bassOverride = $config['progressionBass'][$index] ?? null;
                
                $tileData = null;
                if ($chordModel) {
                    $tileData = ($bassOverride)
                        ? $this->chordSerializer->serializeWithBass($chordModel, $rootOverride ?? $chordModel->root_note ?? 'C', $bassOverride)
                        : $this->chordSerializer->serialize($chordModel, $rootOverride);
                }
                
                return [
                    'chordName' => $tileData['name'] ?? $progressionSlug,
                    'diagramData' => $tileData,
                ];
            })->toArray();

            return array_merge($config, [
                'slug' => $slug,
                'voicingData' => $voicingData,
                'progressionTiles' => $progressionTiles,
                'rhythmData' => $rhythms[$config['rhythmSlug'] ?? ''] ?? null,
                'relatedProducts' => $config['relatedProducts'] ?? [],
            ]);
        })->values();
    }
}
