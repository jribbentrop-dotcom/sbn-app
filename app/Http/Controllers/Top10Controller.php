<?php

namespace App\Http\Controllers;

use App\Models\ChordDiagram;
use App\Models\ChordProgression;
use App\Models\RhythmPattern;
use App\Services\ChordSerializer;
use App\Services\ChordVoicingSearch;
use App\Services\HarmonicContext;
use App\Services\ProgressionBuilder;
use Inertia\Inertia;

class Top10Controller extends Controller
{
    public function __construct(
        private ChordSerializer $chordSerializer,
        protected HarmonicContext $harmonicContext,
        protected ChordVoicingSearch $voicingSearch,
        protected ProgressionBuilder $progressionBuilder
    ) {}

    public function bossaNovaChords()
    {
        $top10Data = $this->getTop10Data('bossa-nova-chords');

        $rhythm = RhythmPattern::where('category', 'bossa-nova')
            ->orderByDesc('default_bpm')
            ->first();

        return Inertia::render('Top10/BossaNovaChords', [
            'top10Data'     => $top10Data,
            'rhythmPattern' => $rhythm ? $rhythm->toPlayerData() : null,
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

            // Progression Logic
            $progressionTiles = [];
            $progressionMeta = null;

            if (!empty($config['progressionLibrarySlug'])) {
                $progressionModel = \App\Models\ChordProgression::where('slug', $config['progressionLibrarySlug'])->first();
                if ($progressionModel) {
                    $seedKey = $config['progressionSeedKey'] ?? 'C';
                    $snippets = $progressionModel->video_snippets ?? [];
                    if (!empty($snippets[0]['key'])) {
                        $seedKey = $snippets[0]['key'];
                    }
                    $resolved = $this->harmonicContext->buildFromNumerals($seedKey, $progressionModel->numerals);
                    
                    // Pre-resolve overrides into full transposed voicing objects for the builder
                    $allResolvedChords = $resolved['sections'][0]['chords'] ?? [];
                    $pinnedVoicings = [];
                    foreach ($config['progressionVoicingOverrides'] ?? [] as $idx => $slug) {
                        $overrideModel = ChordDiagram::where('slug', $slug)->first();
                        if ($overrideModel && isset($allResolvedChords[$idx])) {
                            $targetRoot = $allResolvedChords[$idx]['root'];
                            $transposed = $this->voicingSearch->transposeShapes(collect([$overrideModel]), $targetRoot, $overrideModel->quality, '', '');
                            if (!empty($transposed)) {
                                $pinnedVoicings[$idx] = $transposed[0];
                            } else {
                                // Fixed-position shape whose stored root doesn't match the
                                // target — transposeShapes skips it silently. Fall back to
                                // ChordSerializer which respects is_fixed_position correctly.
                                $serialized = $this->chordSerializer->serialize($overrideModel, $targetRoot);
                                if (!empty($serialized['diagram_data'])) {
                                    $pinnedVoicings[$idx] = $serialized;
                                }
                            }
                        }
                    }

                    // Build the progression using SBN's voice-leading builder
                    $built = $this->progressionBuilder->buildVoicings($resolved, [
                        'category' => $config['category'] ?? 'jazz',
                        'pinnedVoicings' => $pinnedVoicings,
                        'extensions' => true, 
                    ]);

                    $progressionMeta = [
                        'name'          => $progressionModel->name,
                        'numerals'      => $progressionModel->numerals,
                        'slug'          => $progressionModel->slug,
                        'category'      => $progressionModel->category,
                        'videoSnippets' => $progressionModel->video_snippets ?? [],
                    ];

                    $progressionTiles = collect($built['selections'])->map(function ($sel) {
                        return [
                            'chordName' => $sel['chord_name'],
                            'numeral' => $sel['roman_numeral'],
                            'diagramData' => $sel['voicing'] ? (object)$sel['voicing'] : null,
                        ];
                    })->toArray();
                }
            } else {
                // Legacy slug-based progression
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
            }

            return array_merge($config, [
                'slug' => $slug,
                'voicingData' => $voicingData,
                'progressionTiles' => $progressionTiles,
                'progressionMeta' => $progressionMeta,
                'rhythmData' => $rhythms[$config['rhythmSlug'] ?? ''] ?? null,
                'relatedProducts' => $config['relatedProducts'] ?? [],
            ]);
        })->values();
    }
}
