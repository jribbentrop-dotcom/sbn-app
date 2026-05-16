<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Leadsheet;
use App\Models\RhythmPattern;
use App\Models\JazzStandard;
use App\Models\ChordProgression;
use App\Services\ChordShapeCalculator;
use App\Services\ChordVoicingSearch;
use App\Services\LeadsheetParser;
use App\Services\LeadsheetScaffolder;
use App\Services\VoicingCrossref;
use App\Services\ProgressionBuilder;
use App\Services\ChordSequenceParser;
use App\Services\HarmonicContext;
use Illuminate\Http\Request;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\VoicingMaterializer;
use App\Services\SongLookup;
use App\Services\AnalysisToLeadsheet;
use App\Services\RhythmHintMapper;
use App\Services\MidiTranscriptionService;
use Illuminate\Support\Str;


class LeadsheetController extends Controller
{
    protected LeadsheetParser $parser;
    protected ChordShapeCalculator $calculator;
    protected ChordVoicingSearch $voicingSearch;

    public function __construct(LeadsheetParser $parser, ChordShapeCalculator $calculator, ChordVoicingSearch $voicingSearch)

    {
        $this->parser        = $parser;
        $this->calculator    = $calculator;
        $this->voicingSearch = $voicingSearch;
    }


    // =========================================================================
    // WEB ROUTES (Blade views)
    // =========================================================================

    public function index(Request $request)
    {
        $currentTab = $request->get('tab', 'leadsheets');
        $search = $request->get('search');
        $key = $request->get('key');
        $composer = $request->get('composer');

        if ($currentTab === 'exercises') {
            $query = \App\Models\Exercise::query();
            if ($search) {
                $query->where(fn($q) => $q->where('title', 'like', "%{$search}%")->orWhere('composer', 'like', "%{$search}%"));
            }
            if ($key) {
                $query->where('key_center', $key);
            }
            if ($composer) {
                $query->where('composer', $composer);
            }
            $items = $query->orderBy('title')->paginate(25)->withQueryString();
        } else {
            $query = Leadsheet::query();
            if ($search) {
                $query->search($search);
            }
            if ($key) {
                $query->inKey($key);
            }
            if ($composer) {
                $query->where('composer', $composer);
            }
            $items = $query->orderBy('title')->paginate(25)->withQueryString();
        }

        $stats = Leadsheet::getStats();
        // Add exercise count to stats
        $stats['exercises'] = \App\Models\Exercise::count();
        
        $keys = Leadsheet::getDistinctKeys();
        $composers = Leadsheet::getDistinctComposers();
        $rhythms = RhythmPattern::orderBy('category')->orderBy('name')->get();
        $cloneSources = Leadsheet::orderBy('title')->get(['id', 'title', 'composer']);
        $progressions = \App\Models\ChordProgression::orderBy('category')->orderBy('name')->get(['id', 'name', 'category', 'numerals', 'tonality']);
        $jazzStandards = \App\Models\JazzStandard::orderBy('title')->get(['id', 'title', 'composer', 'song_key', 'slug']);

        return view('admin.leadsheets.index', [
            'items'         => $items,
            'leadsheets'    => ($currentTab === 'leadsheets') ? $items : collect(), // for backward compatibility if needed in modals
            'stats'         => $stats,
            'keys'          => $keys,
            'composers'     => $composers,
            'rhythms'       => $rhythms,
            'cloneSources'  => $cloneSources,
            'progressions'  => $progressions,
            'jazzStandards' => $jazzStandards,
            'currentTab'    => $currentTab,
        ]);
    }

    public function create()
    {
        $rhythms        = RhythmPattern::orderBy('category')->orderBy('name')->get();
        $rhythmPatterns = $rhythms->mapWithKeys(fn ($r) => [$r->slug => $r->toPlayerData()]);
        return view('admin.leadsheets.edit', [
            'leadsheet'      => null,
            'rhythms'        => $rhythms,
            'rhythmPatterns' => $rhythmPatterns,
        ]);
    }

    public function edit(Leadsheet $leadsheet)
    {
        $rhythms        = RhythmPattern::orderBy('category')->orderBy('name')->get();
        $rhythmPatterns = $rhythms->mapWithKeys(fn ($r) => [$r->slug => $r->toPlayerData()]);
        return view('admin.leadsheets.edit', [
            'leadsheet'      => $leadsheet,
            'rhythms'        => $rhythms,
            'rhythmPatterns' => $rhythmPatterns,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $this->validateLeadsheet($request);

        $parsed = null;
        if (!empty($validated['shortcode_content'])) {
            $parsed = $this->parser->parse($validated['shortcode_content']);
        }

        $shortcode = $validated['shortcode_content'] ?? '';
        $shortcode = $this->injectInfoBlock($shortcode, $validated);

        $leadsheet = Leadsheet::create([
            'title'             => $validated['title'],
            'slug'              => Leadsheet::generateUniqueSlug($validated['title']),
            'composer'          => $validated['composer'] ?? '',
            'song_key'          => $validated['song_key'] ?? 'C',
            'tempo'             => $validated['tempo'] ?? 120,
            'time_signature'    => $validated['time_signature'] ?? '4/4',
            'rhythm'            => $validated['rhythm'] ?? '',
            'measure_count'     => $parsed ? $this->countMeasures($parsed) : 0,
            'course_id'         => $validated['course_id'] ?: null,
            'shortcode_content' => $shortcode,
            'json_data'         => $validated['json_data'] ?? '',
            'tab_xml'           => $validated['tab_xml'] ?? null,
            'description'       => $validated['description'] ?? '',
            'harmony_notes'     => $validated['harmony_notes'] ?? '',
            'form_notes'        => $validated['form_notes'] ?? '',
            'voicing_notes'     => $validated['voicing_notes'] ?? '',
            'popularity'        => 0,
        ]);

        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'id' => $leadsheet->id, 'message' => 'Leadsheet created.']);
        }

        return redirect()->route('admin.leadsheets.edit', $leadsheet)->with('success', 'Leadsheet created successfully.');
    }

    public function createBlank(Request $request, LeadsheetScaffolder $scaffolder)
    {
        $validated = $request->validate([
            'title'                => 'required|string|max:255',
            'composer'             => 'nullable|string|max:255',
            'song_key'             => 'required|string|max:10',
            'tempo'                => 'required|integer|min:20|max:300',
            'time_signature'       => 'required|string|max:10',
            'rhythm'               => 'nullable|string|max:50',
            'structure_mode'       => 'required|in:simple,sectioned',
            'simple_bar_count'     => 'required_if:structure_mode,simple|integer|min:1|max:256',
            'sections'             => 'required_if:structure_mode,sectioned|array|min:1|max:20',
            'sections.*.name'      => 'required|string|max:50',
            'sections.*.bars'      => 'required|integer|min:1|max:64',
            'pickup_bar'           => 'nullable|boolean',
        ]);

        $structure = $validated['structure_mode'] === 'simple'
            ? ['mode' => 'simple', 'bar_count' => $validated['simple_bar_count']]
            : ['mode' => 'sectioned', 'sections' => $validated['sections']];

        $slug = Leadsheet::generateUniqueSlug($validated['title']);


        $scaffold = $scaffolder->scaffoldBlank([
            'title'          => $validated['title'],
            'composer'       => $validated['composer'] ?? '',
            'song_key'       => $validated['song_key'],
            'tempo'          => $validated['tempo'],
            'time_signature' => $validated['time_signature'],
            'rhythm'         => $validated['rhythm'] ?? '',
            'structure'      => $structure,
            'pickup_bar'     => (bool)($validated['pickup_bar'] ?? false),
        ]);

        $leadsheet = Leadsheet::create([
            'title'             => $validated['title'],
            'slug'              => $slug,
            'composer'          => $validated['composer'] ?? '',
            'song_key'          => $validated['song_key'],
            'tempo'             => $validated['tempo'],
            'time_signature'    => $validated['time_signature'],
            'rhythm'            => $validated['rhythm'] ?? '',
            'measure_count'     => $scaffold['measure_count'],
            'shortcode_content' => $scaffold['shortcode_content'],
            'json_data'         => $scaffold['json_data'],
            'tab_xml'           => null,
            'description'       => '',
            'harmony_notes'     => '',
            'form_notes'        => '',
            'voicing_notes'     => '',
            'popularity'        => 0,
        ]);

        return redirect()
            ->route('admin.leadsheets.edit', $leadsheet)
            ->with('success', 'Blank leadsheet created.');
    }

    public function createFromSequence(
        Request $request, 
        LeadsheetScaffolder $scaffolder, 
        ChordSequenceParser $parser, 
        HarmonicContext $context, 
        VoicingMaterializer $materializer,
        ProgressionBuilder $builder,
        AnalysisToLeadsheet $converter
    ) {
        $validated = $request->validate([
            'title'          => 'required|string|max:255',
            'composer'       => 'nullable|string|max:255',
            'song_key'       => 'required|string|max:10',
            'tempo'          => 'required|integer|min:20|max:300',
            'time_signature' => 'required|string|max:10',
            'rhythm'         => 'nullable|string|max:50',
            'bars_per_chord' => 'nullable|integer|min:1|max:16',
            'source_type'    => 'required|in:free,chordpro,bars,clone,progression,jazz_standard,standard',
            'sequence_text'  => 'nullable|string',
            'clone_source_id'=> 'nullable|integer|exists:sbn_leadsheets,id',
            'progression_id' => 'nullable|integer|exists:sbn_chord_progressions,id',
            'jazz_standard_id' => 'nullable|integer|exists:sbn_jazz_standards,id',
            'build_voicings' => 'nullable|boolean',
            'extension_mode' => 'nullable|string|in:basic,extended',

            'voicing_style'  => 'nullable|string|in:popular,shell,drop2,drop3,closed,archetype,quartal,custom,closed_triads,spread_triads,slash',
        ]);


        $sequenceText = $validated['sequence_text'] ?? '';
        
        if ($validated['source_type'] === 'progression' && !empty($validated['progression_id'])) {
            $progression = ChordProgression::findOrFail($validated['progression_id']);
            $numerals = array_values(array_filter(array_map('trim', explode(',', $progression->numerals))));
            $parsedSequence = [
                'mode' => 'sequence',
                'items' => $numerals,
                'invalid_count' => 0,
            ];
        } elseif (in_array($validated['source_type'], ['jazz_standard', 'standard']) && !empty($validated['jazz_standard_id'])) {
            $standard = JazzStandard::findOrFail($validated['jazz_standard_id']);
            $analysis = $standard->toIntermediateAnalysis();
            
            // Use the converter to preserve sections and bar structures
            $scaffold = $converter->convert($analysis);
            
            // Override some opts from the standard
            $validated['title'] = $analysis['title'];
            $validated['composer'] = $analysis['composer'];
            $validated['song_key'] = $analysis['key'] ?? 'C';
            $validated['time_signature'] = $analysis['timeSignature'] ?? '4/4';
            $validated['tempo'] = $analysis['tempo'] ?? 120;
            
            // We set parsedSequence to a dummy to satisfy downstream logic
            $parsedSequence = ['mode' => 'analysis', 'items' => [], 'analysis' => $analysis];
        } elseif ($validated['source_type'] === 'clone' && !empty($validated['clone_source_id'])) {
            $cloneSheet = Leadsheet::findOrFail($validated['clone_source_id']);
            $rawJson = json_decode($cloneSheet->json_data, true);

            $chordNames = [];
            if (!empty($rawJson['sections'])) {
                foreach ($rawJson['sections'] as $sec) {
                    foreach ($sec['measures'] as $m) {
                        foreach ($m['chords'] as $c) {
                            if (!empty($c['name'])) {
                                $chordNames[] = $c['name'];
                            }
                        }
                    }
                }
            }
            $parsedSequence = [
                'mode' => 'sequence',
                'items' => $chordNames,
                'invalid_count' => 0,
            ];
        } else {
            $parsedSequence = $parser->parse($sequenceText);
        }

        $key = $validated['song_key'] ?? 'C';
        
        // Only resolve numerals if we are in a sequence-based mode
        if ($parsedSequence['mode'] !== 'analysis') {
            $parsedSequence = $parser->resolveNumerals($parsedSequence, $key);
        }


        if (!in_array($validated['source_type'], ['jazz_standard', 'standard'])) {
            $scaffold = $scaffolder->scaffoldFromSequence([
                'title'          => $validated['title'],
                'composer'       => $validated['composer'] ?? '',
                'song_key'       => $validated['song_key'],
                'tempo'          => $validated['tempo'],
                'time_signature' => $validated['time_signature'],
                'rhythm'         => $validated['rhythm'] ?? '',
            ], $parsedSequence, $validated['bars_per_chord']);
        }

        $slug = Leadsheet::generateUniqueSlug($validated['title']);


        $tabXml = null;
        $jsonDataArray = json_decode($scaffold['json_data'], true);

        if (!empty($validated['build_voicings'])) {
            $chordsList = [];
            if ($parsedSequence['mode'] === 'analysis') {
                $mIdx = 0;
                foreach ($parsedSequence['analysis']['sections'] as $sec) {
                    foreach ($sec['bars'] as $bar) {
                        foreach ($bar['chords'] as $c) {
                            if (!empty($c['label']) && $c['label'] !== '/') {
                                $chordsList[] = [
                                    'chord_name'    => $c['label'],
                                    'measure_index' => $mIdx,
                                ];
                            }
                        }
                        $mIdx++;
                    }
                }
            } elseif ($parsedSequence['mode'] === 'bars') {
                foreach ($parsedSequence['items'] as $mIdx => $barChords) {
                    foreach ($barChords as $c) {
                        if ($c !== '?' && !empty($c)) {
                            $chordsList[] = [
                                'chord_name'    => $c,
                                'measure_index' => $mIdx,
                            ];
                        }
                    }
                }
            } else {
                foreach ($parsedSequence['items'] as $i => $c) {
                    if ($c !== '?' && !empty($c)) {
                        $chordsList[] = [
                            'chord_name'    => $c,
                            'measure_index' => $i,
                        ];
                    }
                }
            }

            if (!empty($chordsList)) {
                $style = $validated['voicing_style'] ?? 'popular';
                
                // Determine the correct category for the builder
                if (in_array($validated['source_type'], ['jazz_standard', 'standard'])) {
                    $category = ($style === 'popular' || empty($style)) ? 'jazz' : $style;
                } else {
                    $category = ($style === 'popular' || empty($style)) ? 'pop' : $style;
                }

                // Call the full algorithm directly. Extensions (Phase E option-tone
                // upgrade) only fire when the user explicitly chose Extended mode.
                // Basic mode also forces strict_basic so we never pick voicings
                // whose `extensions` column carries 9/11/13 (overrides category
                // pass1_extensions_allowed).
                $extensionMode = $validated['extension_mode'] ?? 'basic';
                $hc = $context->buildFromChordSequence($key, $chordsList);
                $res = $builder->buildVoicings($hc, [
                    'category'      => $category,
                    'extensions'    => $extensionMode === 'extended',
                    'strict_basic'  => $extensionMode === 'basic',
                    'voicing_style' => ($style === 'popular') ? 'auto' : $style,
                ]);

                // Map algorithm output to the internal selections format.
                // In extended mode the picked voicing may carry option tones the
                // chord name doesn't already reflect (e.g. Eb7 picked as a Lydian-
                // dominant xx5665 with intervals 9,#11). Append the voicing's
                // extensions to the stored chord name so downstream voicing
                // crossref can match the right shape and surface fingerings.
                $selections = [];
                foreach ($res['selections'] as $sel) {
                    $v = $sel['voicing'];
                    if (!$v) continue;

                    $chordName = $sel['chord_name'];
                    if ($extensionMode === 'extended' && !empty($v['extensions'])) {
                        $vExt = preg_replace('/\s+/', '', $v['extensions']);
                        // Skip if chord_name already carries any extension —
                        // either parenthetical (e.g. Eb7(9,#11)) or appended
                        // (e.g. A7b9, C7#11, F13). Hardcoded source extensions
                        // are honored as-authored, so don't tack on more.
                        $hasExtension = preg_match('/\([^)]+\)/', $chordName)
                            || preg_match('/(maj?7|7|6)?(b9|#9|b13|#11|11|13|9)/i', $chordName);
                        if ($vExt !== '' && !$hasExtension) {
                            $chordName .= '(' . $vExt . ')';
                        }
                    }

                    $selections[] = [
                        'chord_name'    => $chordName,
                        'measure_index' => $sel['measure_index'] ?? 0,
                        'frets'         => $v['frets'] ?? null,
                        'position'      => $v['start_fret'] ?? 1,
                        'diagram_id'    => $v['id'] ?? null,
                    ];
                }

                // Clean up selections for materializer (it needs 'chord_name', 'frets', 'position', 'measure_index')
                $selectionsClean = [];
                foreach ($selections as $sel) {
                    if (!empty($sel['frets'])) {
                        $selectionsClean[] = [
                            'chord_name'    => $sel['chord_name'],
                            'measure_index' => $sel['measure_index'],
                            'frets'         => $sel['frets'],
                            'position'      => $sel['position'] ?? 1,
                        ];
                    }
                }

                if (!empty($selectionsClean)) {
                    $rhythmModel = null;
                    if (!empty($validated['rhythm'])) {
                        $rhythmModel = \App\Models\RhythmPattern::where('slug', $validated['rhythm'])->first();
                    }

                    $materialized = $materializer->materialize($selectionsClean, $validated['time_signature'], $rhythmModel);
                    $tabXml = $materialized['tab_xml'];
                    $jsonDataArray['chordVoicings'] = $materialized['voicings'];
                    
                    // Re-apply enhanced names from the builder back to the structural JSON
                    $selIdx = 0;
                    foreach ($jsonDataArray['sections'] as &$sec) {
                        foreach ($sec['measures'] as &$m) {
                            foreach ($m['chords'] as &$c) {
                                if (!empty($c['name']) && $c['name'] !== '?' && isset($selections[$selIdx])) {
                                    $c['name'] = $selections[$selIdx]['chord_name'];
                                    $selIdx++;
                                }
                            }
                        }
                    }
                    unset($sec, $m, $c);

                    $jsonDataArray['melody'] = $materialized['melody'];
                    if ($rhythmModel) {
                        $jsonDataArray['rhythmPattern'] = $rhythmModel->toPlayerData();
                    }
                }
            }
        }

        $leadsheet = Leadsheet::create([
            'title'             => $validated['title'],
            'slug'              => $slug,
            'composer'          => $validated['composer'] ?? '',
            'song_key'          => $validated['song_key'],
            'tempo'             => $validated['tempo'],
            'time_signature'    => $validated['time_signature'],
            'rhythm'            => $validated['rhythm'] ?? '',
            'measure_count'     => $scaffold['measure_count'],
            'shortcode_content' => $scaffold['shortcode_content'],
            'json_data'         => $this->normalizeChordNamesInJson(json_encode($jsonDataArray, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'tab_xml'           => $tabXml,
            'description'       => '',
            'harmony_notes'     => '',
            'form_notes'        => '',
            'voicing_notes'     => '',
            'popularity'        => 0,
        ]);

        return redirect()
            ->route('admin.leadsheets.edit', $leadsheet)
            ->with('success', 'Leadsheet created from progression.');
    }


    public function createFromLookup(
        Request $request,
        SongLookup $lookup,
        \App\Services\RhythmHintMapper $rhythmMapper,
        AnalysisToLeadsheet $converter,
        VoicingMaterializer $materializer,
        ProgressionBuilder $builder,
        \App\Services\MidiTranscriptionService $transcriber,
        VoicingCrossref $crossref,
        HarmonicContext $context
    ) {
        set_time_limit(600);

        $validated = $request->validate([
            'title'         => 'required|string|max:255',
            'artist_hint'   => 'nullable|string|max:255',
            'preferred_key' => 'nullable|string|max:10',
            'version'       => 'nullable|string|in:real_book,original,most_common',
            'build_voicings'=> 'nullable|boolean',
            'extension_mode'=> 'nullable|string|in:basic,extended',
            'voicing_style' => 'nullable|string|in:popular,shell,drop2,archetype',
            'rhythm_override' => 'nullable|string|max:50',
            'mode'          => 'nullable|string|in:quick,assistant,audio',
            'youtube_id'    => 'nullable|string',
            'ai_cleanup'    => 'nullable|boolean',
        ]);

        if (($validated['mode'] ?? '') === 'audio') {
            if (empty($validated['youtube_id'])) {
                return back()->withErrors(['lookup' => 'You must select a YouTube video for audio transcription.']);
            }
            
            try {
                $rawResult = $transcriber->transcribe($validated['youtube_id']);
                if (!($rawResult['success'] ?? false)) {
                    throw new \Exception($rawResult['error'] ?? "Unknown transcription error.");
                }
            } catch (\Exception $e) {
                return back()->withErrors(['lookup' => "Transcription Error: " . $e->getMessage()]);
            }

            // Convert raw Python output (beats/notes) to standard Analysis format
            $analysis = [
                'title'         => $request->input('youtube_title') ?: 'Audio Transcription',
                'composer'      => '',
                'key'           => $validated['preferred_key'] ?: 'C',
                'tempo'         => (int)round($rawResult['tempo'] ?? 120),
                'timeSignature' => '4/4',
                'source_note'   => 'AI Audio Transcription from YouTube (ID: ' . $validated['youtube_id'] . ')',
                'sections'      => [],
                'videoSync'     => [
                    'videoId'     => $validated['youtube_id'],
                    'videoType'   => 'youtube',
                    'audioSource' => 'video',
                    'mappings'    => []
                ]
            ];

            $currentSection = ['label' => 'A', 'bars' => []];
            $beatsPerBar    = 4;
            $tempBar        = ['chords' => []];
            $mappings       = [];
            $aiCleanup      = !empty($validated['ai_cleanup']);

            // Remove leading silence by finding the first bar with musical content (P1 fix)
            $firstBusyBeatIdx = 0;
            foreach ($rawResult['beats'] as $idx => $beat) {
                if (!empty($beat['notes']) || !empty($beat['note_durations'])) {
                    $firstBusyBeatIdx = $idx;
                    break;
                }
            }
            $skipBars = (int)floor($firstBusyBeatIdx / $beatsPerBar);
            $startBeatIdx = $skipBars * $beatsPerBar;
            $tickOffset = $skipBars * $beatsPerBar * 480;

            // ── Non-AI path state: harmonic region grouping (Phase 2c) ──────────
            // Consecutive beats with Jaccard similarity >= threshold merge into
            // a single chord slot so identifyFromMidi() is called once per region.
            $regionPitches    = [];   // accumulated unique MIDI pitches for current region
            $regionStartBeat  = 1;    // bar-relative beat number where region started

            for ($i = $startBeatIdx; $i < count($rawResult['beats']); $i++) {
                $beat = $rawResult['beats'][$i];
                if ($i % $beatsPerBar === 0) {
                    $mappings[] = [
                        'measureIndex' => (int)(($i - $startBeatIdx) / $beatsPerBar),
                        'videoTime'    => (float)$beat['start']
                    ];
                }

                // Duration-weighted pitch selection (P1 fix)
                $rawPitches = [];
                foreach ($beat['note_durations'] ?? [] as $pitch => $dur) {
                    if ($dur >= 0.1) $rawPitches[] = (int)$pitch;
                }
                if (empty($rawPitches)) $rawPitches = $beat['notes'] ?? [];

                $beatNum = ($i % $beatsPerBar) + 1;

                if ($aiCleanup) {
                    // ── AI path (Phase 2b) ────────────────────────────────────
                    // Store raw pitch classes + '?' placeholder; Gemini fills labels.
                    if (!empty($rawPitches)) {
                        $pcSet = array_values(array_unique(array_map(fn($p) => $p % 12, $rawPitches)));
                        $tempBar['chords'][] = [
                            'label'   => '?',
                            'beat'    => $beatNum,
                            'pitches' => $pcSet,   // read by Gemini; stripped after AI pass
                        ];
                    }
                } else {
                    // ── Non-AI path (Phase 2c): Jaccard region grouping ──────
                    if (empty($rawPitches)) {
                        // Silent beat — flush any open region
                        if (!empty($regionPitches)) {
                            $idResult = $crossref->identifyFromMidi($regionPitches);
                            if ($idResult['name']) {
                                $tempBar['chords'][] = ['label' => $idResult['name'], 'beat' => $regionStartBeat];
                            }
                            $regionPitches = [];
                        }
                    } else {
                        if (empty($regionPitches)) {
                            // Start a new region
                            $regionPitches   = $rawPitches;
                            $regionStartBeat = $beatNum;
                        } else {
                            $sim = $this->jaccardSimilarity($regionPitches, $rawPitches);
                            if ($sim >= 0.5) {
                                // Harmonically similar — extend region, keep all pitches
                                $regionPitches = array_values(array_unique(array_merge($regionPitches, $rawPitches)));
                            } else {
                                // New harmonic region — flush previous
                                $idResult = $crossref->identifyFromMidi($regionPitches);
                                if ($idResult['name']) {
                                    $tempBar['chords'][] = ['label' => $idResult['name'], 'beat' => $regionStartBeat];
                                }
                                $regionPitches   = $rawPitches;
                                $regionStartBeat = $beatNum;
                            }
                        }
                    }
                }

                // ── End of bar ────────────────────────────────────────────────
                if (($i + 1) % $beatsPerBar === 0) {
                    // Non-AI: flush the final open region before closing the bar
                    if (!$aiCleanup && !empty($regionPitches)) {
                        $idResult = $crossref->identifyFromMidi($regionPitches);
                        if ($idResult['name']) {
                            $tempBar['chords'][] = ['label' => $idResult['name'], 'beat' => $regionStartBeat];
                        }
                        $regionPitches = [];
                    }

                    if (empty($tempBar['chords'])) {
                        $tempBar['chords'][] = ['label' => '/', 'beat' => 1];
                    }
                    $currentSection['bars'][] = $tempBar;
                    $tempBar = ['chords' => []];
                }
            }

            // Flush any trailing partial bar
            if (!$aiCleanup && !empty($regionPitches)) {
                $idResult = $crossref->identifyFromMidi($regionPitches);
                if ($idResult['name']) {
                    $tempBar['chords'][] = ['label' => $idResult['name'], 'beat' => $regionStartBeat];
                }
            }
            if (!empty($tempBar['chords'])) {
                $currentSection['bars'][] = $tempBar;
            }

            if (empty($currentSection['bars'])) {
                $currentSection['bars'][] = ['chords' => [['label' => '/', 'beat' => 1]]];
            }
            $analysis['sections'][] = $currentSection;
            $analysis['videoSync']['mappings'] = $mappings;

            // AI path: attach raw beats so Gemini can read pitch context
            if ($aiCleanup) {
                $analysis['raw_beats'] = $rawResult['beats'];
            }

            // Reconstruct melody from raw MIDI notes (P0 & P1 fixes)
            $melody = [];
            if (!empty($rawResult['notes']) && !empty($rawResult['beat_times'])) {
                $ticksPerBar = $beatsPerBar * 480;

                // 1. Filter by guitar range and group by quantized tick (P0)
                $tickGroups = [];
                foreach ($rawResult['notes'] as $note) {
                    // P0: Guitar range filter (MIDI 40-88)
                    if ($note['pitch'] < 40 || $note['pitch'] > 88) continue;

                    $rawStart = $this->timeToTicks($note['start'], $rawResult['beat_times']);
                    // Bias start toward 8th note grid (240 ticks) to avoid unwanted 16th-note jitter
                    $startTick = (int)round($rawStart / 240) * 240;
                    if (abs($rawStart - $startTick) > 60) {
                        // If it's clearly a 16th off-beat, keep it
                        $startTick = (int)round($rawStart / 120) * 120;
                    }
                    
                    $startTick -= $tickOffset;
                    if ($startTick >= 0) {
                        $tickGroups[$startTick][] = $note;
                    }
                }

                // 2. Process all notes per tick (Restoring polyphony) and build melody events
                $sortedTicks = array_keys($tickGroups);
                sort($sortedTicks);
                
                $tempMelody = [];
                for ($i = 0; $i < count($sortedTicks); $i++) {
                    $tick = $sortedTicks[$i];
                    $notes = $tickGroups[$tick];
                    $nextTick = ($i + 1 < count($sortedTicks)) ? $sortedTicks[$i+1] : null;
                    
                    $barIdx  = (int)floor($tick / $ticksPerBar);
                    $barEnd  = ($barIdx + 1) * $ticksPerBar;
                    $beatEnd = ((int)floor($tick / 480) + 1) * 480;

                    // NEW: Beat-boundary clamping. Notes cannot cross beat boundaries (P1 fix)
                    $limit = min($barEnd, $beatEnd);
                    
                    $availableSpace = $limit - $tick;
                    if ($nextTick !== null && $nextTick < $limit) {
                        $availableSpace = $nextTick - $tick;
                    }

                    foreach ($notes as $note) {
                        $endTick = $this->timeToTicks($note['end'], $rawResult['beat_times']);
                        $endTick = (int)round($endTick / 120) * 120;
                        
                        $rawDuration = max(120, $endTick - $tick);
                        
                        // Clamping duration to avoid "overfilled bars" and ensure beat-sync
                        $clampedDuration = min($availableSpace, $rawDuration);
                        $quantizedTicks = $this->quantizeToStandard($clampedDuration);
                        
                        // Final safety check
                        if ($quantizedTicks > $availableSpace) $quantizedTicks = $availableSpace;
                        if ($quantizedTicks < 120 && $availableSpace >= 120) $quantizedTicks = 120;
                        if ($quantizedTicks < 120) continue;

                        $noteInfo = $this->midiToNote($note['pitch']);
                        $tabInfo  = $this->midiToTab($note['pitch']);

                        $tempMelody[] = [
                            'tick'         => $tick,
                            'pitch'        => $noteInfo['pitch'],
                            'octave'       => $noteInfo['octave'],
                            'duration'     => $this->ticksToDuration($quantizedTicks),
                            'ticks'        => $quantizedTicks,
                            'string'       => $tabInfo['string'],
                            'fret'         => $tabInfo['fret'],
                            'isRest'       => false,
                            'voice'        => 1,
                            'tieStart'     => false,
                            'tieStop'      => false,
                            'isChordNote'  => false,
                        ];
                    }
                }
                
                // 3. Rest insertion pass (P1)
                $melodyWithRests = [];
                $lastEnd = 0;
                usort($tempMelody, fn($a, $b) => $a['tick'] <=> $b['tick']);
                
                foreach ($tempMelody as $m) {
                    if ($m['tick'] > $lastEnd) {
                        $gap = $m['tick'] - $lastEnd;
                        
                        // NEW: Gap Closing logic. If the gap is just a 16th note (120 ticks), 
                        // extend the previous note(s) to fill it instead of inserting a rest.
                        if ($gap <= 120 && !empty($melodyWithRests)) {
                            $extended = false;
                            foreach ($melodyWithRests as &$prev) {
                                if (!($prev['isRest'] ?? false) && ($prev['tick'] + $prev['ticks'] == $lastEnd)) {
                                    // Only extend if it results in a standard duration and stays within beat
                                    $newTicks = $prev['ticks'] + $gap;
                                    $quantized = $this->quantizeToStandard($newTicks);
                                    
                                    $pBeatEnd = ((int)floor($prev['tick'] / 480) + 1) * 480;
                                    if ($quantized > $prev['ticks'] && ($prev['tick'] + $quantized <= $pBeatEnd)) {
                                        $prev['ticks'] = $quantized;
                                        $prev['duration'] = $this->ticksToDuration($prev['ticks']);
                                        $extended = true;
                                    }
                                }
                            }
                            if ($extended) {
                                $lastEnd = $m['tick'];
                                $gap = 0;
                            }
                        }

                        while ($gap >= 120) {
                            $barIdx = (int)floor($lastEnd / $ticksPerBar);
                            $barEnd = ($barIdx + 1) * $ticksPerBar;
                            
                            $restTicks = 120;
                            foreach ([1920, 960, 480, 240, 120] as $s) {
                                if ($s <= $gap && ($lastEnd + $s <= $barEnd)) {
                                    $restTicks = $s;
                                    break;
                                }
                            }
                            
                            if ($lastEnd + $restTicks > $barEnd) $restTicks = $barEnd - $lastEnd;
                            if ($restTicks < 120) {
                                $lastEnd = $barEnd;
                                $gap = $m['tick'] - $lastEnd;
                                continue;
                            }

                            $melodyWithRests[] = [
                                'tick'     => $lastEnd,
                                'ticks'    => $restTicks,
                                'duration' => $this->ticksToDuration($restTicks),
                                'isRest'   => true,
                                'voice'    => 1,
                                'pitch'    => 'R',
                                'octave'   => 0,
                            ];
                            $lastEnd += $restTicks;
                            $gap -= $restTicks;
                        }
                    }
                    $melodyWithRests[] = $m;
                    $lastEnd = max($lastEnd, $m['tick'] + $m['ticks']);
                }
                
                // Fill up to the end of the last measure
                $totalBeats = count($rawResult['beats']) - $startBeatIdx;
                $totalTicks = $totalBeats * 480;
                $gap = $totalTicks - $lastEnd;

                // Final gap closing: if the very end has a tiny gap, absorb it into the last notes
                if ($gap > 0 && $gap <= 120 && !empty($melodyWithRests)) {
                    foreach ($melodyWithRests as &$prev) {
                        if (!($prev['isRest'] ?? false) && ($prev['tick'] + $prev['ticks'] == $lastEnd)) {
                            $prev['ticks'] += $gap;
                            $prev['duration'] = $this->ticksToDuration($prev['ticks']);
                        }
                    }
                    $lastEnd = $totalTicks;
                    $gap = 0;
                }

                while ($gap >= 120) {
                    $barIdx = (int)floor($lastEnd / $ticksPerBar);
                    $barEnd = ($barIdx + 1) * $ticksPerBar;
                    $restTicks = 120;
                    foreach ([1920, 960, 480, 240, 120] as $s) {
                        if ($s <= $gap && ($lastEnd + $s <= $barEnd)) {
                            $restTicks = $s;
                            break;
                        }
                    }
                    if ($lastEnd + $restTicks > $barEnd) $restTicks = $barEnd - $lastEnd;
                    if ($restTicks >= 120) {
                        $melodyWithRests[] = [
                            'tick'     => $lastEnd,
                            'ticks'    => $restTicks,
                            'duration' => $this->ticksToDuration($restTicks),
                            'isRest'   => true,
                            'voice'    => 1,
                            'pitch'    => 'R',
                            'octave'   => 0,
                        ];
                    }
                    $lastEnd += $restTicks;
                    $gap = $totalTicks - $lastEnd;
                }
                
                $melody = $melodyWithRests;
            }
            $analysis['melody_data'] = $melody;

            // ── Optional: AI Refinement (Musicology Pass) ────────────────────
            // Phase 2b: Gemini receives raw pitch arrays per chord slot and
            // returns proper chord names. Post-AI pass resolves any remaining
            // '?' labels via identifyFromMidi() as a fallback.
            if ($aiCleanup) {
                try {
                    $analysis = $this->musicalizeTranscription($analysis, $lookup);
                    // Post-AI fallback: identify any slots Gemini left unresolved
                    $analysis = $this->resolveUnidentifiedChords($analysis, $crossref);
                } catch (\Exception $e) {
                    \Log::error('[LeadsheetController] AI Cleanup failed: ' . $e->getMessage());
                    // Fallback: resolve '?' labels from the pitch arrays we already built
                    $analysis = $this->resolveUnidentifiedChords($analysis, $crossref);
                }
                // Strip the raw_beats payload before assembly (not needed downstream)
                unset($analysis['raw_beats']);
            }
        } else {
            try {
                $analysis = $lookup->lookup($validated);
            } catch (\App\Services\SongLookupException $e) {
                return back()->withErrors(['lookup' => $e->getMessage()]);
            }
        }

        // Resolve rhythm: user override > mapper(LLM hint) > none
        $rhythmSlug = $validated['rhythm_override'] ?? $rhythmMapper->map($analysis['rhythm_hint'] ?? null);
        $rhythmModel = $rhythmSlug ? \App\Models\RhythmPattern::where('slug', $rhythmSlug)->first() : null;

        $scaffold = $converter->convert($analysis);
        $jsonDataArray = json_decode($scaffold['json_data'], true);
        $tabXml = null;

        if (!empty($validated['build_voicings'])) {
            // Build full chord objects for the algorithm, preserving measure boundaries
            $allChords = [];
            $mIdx = 0;
            foreach ($analysis['sections'] as $sec) {
                foreach ($sec['bars'] as $bar) {
                    foreach ($bar['chords'] as $c) {
                        if (!empty($c['label']) && $c['label'] !== '?') {
                            $allChords[] = [
                                'chord_name'    => $c['label'],
                                'measure_index' => $mIdx,
                            ];
                        }
                    }
                    $mIdx++;
                }
            }

            if (!empty($allChords)) {
                $style      = $validated['voicing_style'] ?? 'popular';
                $sourceType = $validated['source_type'] ?? 'progression';
                $isStandard = in_array($sourceType, ['jazz_standard', 'standard']);
                $category   = $isStandard ? 'jazz' : (($style === 'popular' || empty($style)) ? 'jazz' : $style);

                // Build context and run algorithm. Extensions only on opt-in.
                $extensionMode = $validated['extension_mode'] ?? 'basic';
                $hc = $context->buildFromChordSequence($analysis['key'], $allChords);
                $res = $builder->buildVoicings($hc, [
                    'category'       => $category,
                    'extensions'     => $extensionMode === 'extended',
                    'strict_basic'   => $extensionMode === 'basic',
                    'voicing_style'  => ($style === 'popular') ? 'auto' : $style,
                    'bars_per_chord' => $isStandard ? 1 : ($validated['bars_per_chord'] ?? 1),
                ]);

                // Map algorithm output to the internal selections format.
                // In extended mode, reflect the picked voicing's extensions in
                // the stored chord name so crossref can resolve fingerings.
                $selections = [];
                foreach ($res['selections'] as $sel) {
                    $v = $sel['voicing'];
                    if (!$v) continue;

                    $chordName = $sel['chord_name'];
                    if ($extensionMode === 'extended' && !empty($v['extensions'])) {
                        $vExt = preg_replace('/\s+/', '', $v['extensions']);
                        if ($vExt !== '' && !preg_match('/\([^)]+\)/', $chordName)) {
                            $chordName .= '(' . $vExt . ')';
                        }
                    }

                    $selections[] = [
                        'chord_name'    => $chordName,
                        'measure_index' => $sel['measure_index'] ?? 0,
                        'frets'         => $v['frets'] ?? null,
                        'position'      => $v['start_fret'] ?? 1,
                        'diagram_id'    => $v['id'] ?? null,
                    ];
                }

                // Clean up selections for materializer
                $selectionsClean = [];
                foreach ($selections as $sel) {
                    if (!empty($sel['frets'])) {
                        $selectionsClean[] = [
                            'chord_name'    => $sel['chord_name'],
                            'measure_index' => $sel['measure_index'],
                            'frets'         => $sel['frets'],
                            'position'      => $sel['position'] ?? 1,
                        ];
                    }
                }

                if (!empty($selectionsClean)) {
                    $materialized = $materializer->materialize($selectionsClean, $analysis['timeSignature'], $rhythmModel);
                    $jsonDataArray['chordVoicings'] = $materialized['voicings'];
                    
                    // Re-apply enhanced names from the builder back to the structural JSON
                    $selIdx = 0;
                    foreach ($jsonDataArray['sections'] as &$sec) {
                        foreach ($sec['measures'] as &$m) {
                            foreach ($m['chords'] as &$c) {
                                if (!empty($c['name']) && $c['name'] !== '?' && isset($selections[$selIdx])) {
                                    $c['name'] = $selections[$selIdx]['chord_name'];
                                    $selIdx++;
                                }
                            }
                        }
                    }
                    unset($sec, $m, $c); // Clean up references
                    
                    // In audio mode, prioritize the transcribed melody over auto-generated comping.
                    if (($validated['mode'] ?? '') !== 'audio' || empty($jsonDataArray['melody'])) {
                        $tabXml = $materialized['tab_xml'];
                        $jsonDataArray['melody'] = $materialized['melody'];
                    }

                    if ($rhythmModel) {
                        $jsonDataArray['rhythmPattern'] = $rhythmModel->toPlayerData();
                    }
                }
            }
        }

        $leadsheet = Leadsheet::create([
            'title'             => $analysis['title'],
            'slug'              => Leadsheet::generateUniqueSlug($analysis['title']),
            'composer'          => $analysis['composer'] ?? '',
            'song_key'          => $analysis['key'],
            'tempo'             => $analysis['tempo'] ?? 120,
            'time_signature'    => $analysis['timeSignature'],
            'rhythm'            => $rhythmSlug ?? '',
            'measure_count'     => $scaffold['measure_count'],
            'shortcode_content' => $scaffold['shortcode_content'],
            'json_data'         => $this->normalizeChordNamesInJson(json_encode($jsonDataArray, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'tab_xml'           => $tabXml,
            'description'       => $analysis['source_note'] ?? '',
            'harmony_notes'     => '',
            'form_notes'        => '',
            'voicing_notes'     => '',
            'popularity'        => 0,
        ]);

        return redirect()
            ->route('admin.leadsheets.edit', $leadsheet)
            ->with('success', 'Leadsheet drafted from lookup. Source: ' . ($analysis['source_note'] ?? 'unknown'))
            ->with('lookup_confidence', $analysis['confidence'] ?? 'medium')
            ->with('lookup_alternatives', $analysis['alternatives'] ?? [])
            ->with('open_video_sidebar', !empty($validated['youtube_id']));
    }


    public function update(Request $request, Leadsheet $leadsheet)
    {
        $validated = $this->validateLeadsheet($request);

        $parsed = null;
        if (!empty($validated['shortcode_content'])) {
            $parsed = $this->parser->parse($validated['shortcode_content']);
        }

        $shortcode = $validated['shortcode_content'] ?? '';
        $shortcode = $this->injectInfoBlock($shortcode, $validated);

        if (!empty($validated['json_data'])) {
            $validated['json_data'] = $this->normalizeChordNamesInJson($validated['json_data']);
        }

        $leadsheet->update([
            'title'             => $validated['title'],
            'composer'          => $validated['composer'] ?? '',
            'song_key'          => $validated['song_key'] ?? 'C',
            'tempo'             => $validated['tempo'] ?? 120,
            'time_signature'    => $validated['time_signature'] ?? '4/4',
            'rhythm'            => $validated['rhythm'] ?? '',
            'measure_count'     => $parsed ? $this->countMeasures($parsed) : ($leadsheet->measure_count ?? 0),
            'course_id'         => $validated['course_id'] ?: null,
            'shortcode_content' => $shortcode,
            'json_data'         => $validated['json_data'] ?? $leadsheet->json_data,
            'tab_xml'           => $validated['tab_xml'] ?? $leadsheet->tab_xml,
            'description'       => $validated['description'] ?? '',
            'harmony_notes'     => $validated['harmony_notes'] ?? '',
            'form_notes'        => $validated['form_notes'] ?? '',
            'voicing_notes'     => $validated['voicing_notes'] ?? '',
        ]);

        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'id' => $leadsheet->id, 'message' => 'Leadsheet updated.']);
        }

        return redirect()->route('admin.leadsheets.edit', $leadsheet)->with('success', 'Leadsheet updated successfully.');
    }

    public function destroy(Leadsheet $leadsheet)
    {
        DB::table('sbn_voicing_usage')->where('leadsheet_id', $leadsheet->id)->delete();
        DB::table('sbn_voicing_drafts')->where('leadsheet_id', $leadsheet->id)->delete();
        DB::table('sbn_progression_occurrences')->where('leadsheet_id', $leadsheet->id)->delete();

        $leadsheet->delete();

        return response()->json(['success' => true, 'message' => 'Leadsheet deleted.']);
    }

    // =========================================================================
    // API ENDPOINTS
    // =========================================================================

    public function resolveNumerals(Request $request, ChordSequenceParser $parser, HarmonicContext $context)
    {
        $validated = $request->validate([
            'key' => 'required|string|max:10',
            'sequence' => 'required|string',
        ]);

        $key = $validated['key'];
        $sequence = $validated['sequence'];

        $parsed = $parser->parse($sequence);

        $chords = [];
        $invalidCount = $parsed['invalid_count'];

        if ($parsed['mode'] === 'bars') {
            foreach ($parsed['items'] as $barChords) {
                foreach ($barChords as $chord) {
                    if ((bool) preg_match('/^(b|#)?(III|iii|VII|vii|II|ii|IV|iv|VI|vi|I|i|V|v)(.*)$/', $chord)) {
                        $chords[] = $context->numeralToChordName($chord, $key);
                    } else {
                        $chords[] = $chord;
                    }
                }
            }
        } else {
            foreach ($parsed['items'] as $chord) {
                if ((bool) preg_match('/^(b|#)?(III|iii|VII|vii|II|ii|IV|iv|VI|vi|I|i|V|v)(.*)$/', $chord)) {
                    $chords[] = $context->numeralToChordName($chord, $key);
                } else {
                    $chords[] = $chord;
                }
            }
        }

        return response()->json([
            'success' => true,
            'chords' => $chords,
            'invalid_count' => $invalidCount,
        ]);
    }

    public function updateDescription(Request $request, Leadsheet $leadsheet)

    {
        $validated = $request->validate(['description' => 'nullable|string|max:5000']);
        $leadsheet->update(['description' => $validated['description'] ?? '']);
        return response()->json(['success' => true, 'description' => $leadsheet->description]);
    }

    public function apiShow(Leadsheet $leadsheet)
    {
        if (!empty($leadsheet->json_data)) {
            $parsed = $leadsheet->parsed_data;
            $parsed = $this->backfillFingersFromCrossref($parsed);
            return response()->json([
                'success'   => true,
                'leadsheet' => $this->serializeLeadsheet($leadsheet, $parsed),
            ]);
        }

        $parsed = !empty($leadsheet->shortcode_content)
            ? $this->parser->parse($leadsheet->shortcode_content)
            : null;
        $parsed = $this->backfillFingersFromCrossref($parsed);

        return response()->json([
            'success'   => true,
            'leadsheet' => $this->serializeLeadsheet($leadsheet, $parsed),
        ]);
    }

    /**
     * Walk a parsed leadsheet's chordVoicings map and, for any entry whose
     * fingers default to '000000', look up the canonical sbn_chord_diagrams
     * shape by name+frets and substitute the real fingerings.
     *
     * Pure read-through: never persists, never mutates the source row.
     */
    private function backfillFingersFromCrossref($parsed)
    {
        if (!is_array($parsed) || empty($parsed['chordVoicings']) || !is_array($parsed['chordVoicings'])) {
            return $parsed;
        }

        $cache = [];
        foreach ($parsed['chordVoicings'] as $key => &$voicing) {
            if (!is_array($voicing)) continue;
            $fingers = $voicing['fingers'] ?? '';
            if ($fingers !== '' && $fingers !== '000000') continue;

            $frets = $voicing['frets'] ?? '';
            if (strlen($frets) !== 6) continue;

            $name = preg_match('/^(.+)@\d+\.\d+$/', $key, $m) ? $m[1] : $key;

            if (!array_key_exists($name, $cache)) {
                try {
                    $cache[$name] = $this->voicingSearch->searchByName($name);
                } catch (\Throwable $e) {
                    $cache[$name] = [];
                }
            }
            $matches = $cache[$name];
            if (empty($matches)) continue;

            $canonical = $this->findCanonicalFingersForFrets($matches, $frets);
            if ($canonical !== null) {
                $voicing['fingers'] = $canonical;
            }
        }
        unset($voicing);

        return $parsed;
    }

    /**
     * Given a list of canonical matches (each carrying diagram_data) and a
     * 6-char fret string, return fingerings mapped onto the leadsheet's frets.
     *
     * Matching tiers (prefer earlier):
     *   1. exact     — canonical and leadsheet fret-strings are identical
     *   2. superset  — leadsheet has every canonical sounding string PLUS
     *                  one or more doubled notes (extra octaves on open or
     *                  barred strings). Extra string gets finger 0 if open,
     *                  or inherits the barre finger if its fret lies under
     *                  a canonical barre.
     *   3. subset    — leadsheet omits some canonical sounding strings.
     */
    private function findCanonicalFingersForFrets(array $matches, string $frets): ?string
    {
        $leadArr = $this->fretStringToArray($frets);
        $bestTier = 99;
        $bestFingers = null;

        foreach ($matches as $match) {
            $dd = $match['diagram_data'] ?? null;
            if (is_string($dd)) $dd = json_decode($dd, true);
            if (!is_array($dd)) continue;

            $canonFrets = $this->canonicalFretsFromDiagram($dd);
            $canonArr   = $this->fretStringToArray($canonFrets);

            $tier = $this->classifyFretMatch($leadArr, $canonArr);
            if ($tier === null || $tier >= $bestTier) continue;

            $fingers = $this->mapCanonicalFingersToLeadFrets($dd, $leadArr);
            if ($fingers === null) continue;

            $bestTier    = $tier;
            $bestFingers = $fingers;
            if ($tier === 0) break; // exact — can't do better
        }
        return $bestFingers;
    }

    /**
     * Compare two fret arrays (length 6, each entry 'x' or int 0..n).
     * Returns 0 (exact), 1 (superset — lead has extras), 2 (subset — lead omits),
     * or null (mismatch).
     */
    private function classifyFretMatch(array $lead, array $canon): ?int
    {
        if (count($lead) !== 6 || count($canon) !== 6) return null;

        $leadExtra = 0;
        $canonExtra = 0;
        for ($i = 0; $i < 6; $i++) {
            $l = $lead[$i];
            $c = $canon[$i];
            if ($l === 'x' && $c === 'x') continue;
            if ($l === 'x') { $canonExtra++; continue; }
            if ($c === 'x') { $leadExtra++; continue; }
            if (intval($l) !== intval($c)) return null;
        }
        if ($leadExtra === 0 && $canonExtra === 0) return 0;
        if ($leadExtra > 0 && $canonExtra === 0) return 1;
        if ($leadExtra === 0 && $canonExtra > 0) return 2;
        return null; // both sides have extras → too different
    }

    /**
     * Take the canonical diagram's fingerings and map them onto the leadsheet's
     * actual sounding strings. For extra leadsheet strings (superset case),
     * inherit a barre finger if the leadsheet fret lies on that barre, else 0.
     */
    private function mapCanonicalFingersToLeadFrets(array $dd, array $leadArr): ?string
    {
        $canonFingers = $this->canonicalFingersFromDiagram($dd);
        if (strlen($canonFingers) !== 6) return null;

        $out = ['0', '0', '0', '0', '0', '0'];
        for ($i = 0; $i < 6; $i++) {
            if ($leadArr[$i] === 'x' || $leadArr[$i] === 0 || $leadArr[$i] === '0') {
                $out[$i] = '0';
                continue;
            }
            $cf = $canonFingers[$i];
            if ($cf !== '0') { $out[$i] = $cf; continue; }

            // Lead string sounds but canonical was silent — try barre inheritance.
            $leadFret = intval($leadArr[$i]);
            foreach ($dd['barres'] ?? [] as $b) {
                if (($b['fret'] ?? 0) === $leadFret) {
                    $out[$i] = ($b['finger'] ?? 0) > 0 ? (string) $b['finger'] : '1';
                    break;
                }
            }
        }
        return implode('', $out);
    }

    private function fretStringToArray(string $frets): array
    {
        $out = [];
        for ($i = 0; $i < 6; $i++) {
            $c = $frets[$i] ?? 'x';
            if ($c === 'x' || $c === 'X') $out[] = 'x';
            elseif (ctype_xdigit($c))     $out[] = hexdec($c);
            else                          $out[] = 'x';
        }
        return $out;
    }

    private function canonicalFretsFromDiagram(array $dd): string
    {
        $out = ['x', 'x', 'x', 'x', 'x', 'x'];
        foreach ($dd['open'] ?? [] as $s) {
            if ($s >= 1 && $s <= 6) $out[$s - 1] = '0';
        }
        foreach ($dd['positions'] ?? [] as $p) {
            $s = $p['string'] ?? 0; $f = $p['fret'] ?? 0;
            if ($s >= 1 && $s <= 6 && $f > 0) {
                $out[$s - 1] = $f <= 9 ? (string) $f : dechex($f);
            }
        }
        foreach ($dd['barres'] ?? [] as $b) {
            $from = min($b['fromString'] ?? 0, $b['toString'] ?? 0);
            $to   = max($b['fromString'] ?? 0, $b['toString'] ?? 0);
            for ($s = $from; $s <= $to; $s++) {
                if ($s >= 1 && $s <= 6 && $out[$s - 1] === 'x') {
                    $f = $b['fret'] ?? 0;
                    $out[$s - 1] = $f <= 9 ? (string) $f : dechex($f);
                }
            }
        }
        return implode('', $out);
    }

    private function canonicalFingersFromDiagram(array $dd): string
    {
        $out = ['0', '0', '0', '0', '0', '0'];
        foreach ($dd['positions'] ?? [] as $p) {
            $s = $p['string'] ?? 0; $f = $p['finger'] ?? 0;
            if ($s >= 1 && $s <= 6 && $f > 0) $out[$s - 1] = (string) $f;
        }
        foreach ($dd['barres'] ?? [] as $b) {
            $from = min($b['fromString'] ?? 0, $b['toString'] ?? 0);
            $to   = max($b['fromString'] ?? 0, $b['toString'] ?? 0);
            $finger = ($b['finger'] ?? 0) > 0 ? (string) $b['finger'] : '1';
            for ($s = $from; $s <= $to; $s++) {
                if ($s >= 1 && $s <= 6 && $out[$s - 1] === '0') $out[$s - 1] = $finger;
            }
        }
        return implode('', $out);
    }

    public function identifyVoicings(Request $request, VoicingCrossref $crossref)
    {
        $voicings = $request->input('voicings', []);
        if (empty($voicings) || !is_array($voicings)) {
            return response()->json(['success' => true, 'results' => []]);
        }

        $songKey = $request->input('songKey');
        $harmonicContext = $songKey !== null ? ['song_key' => $songKey] : null;

        $results = $crossref->identifyVoicingsBatch($voicings, $harmonicContext);

        return response()->json(['success' => true, 'results' => $results]);
    }

    /**
     * Search chord diagrams by chord name (voicing picker modal).
     * Delegates to ChordVoicingSearch. Encodes diagram_data as JSON string
     * for backwards compat with the admin picker's existing parse path.
     */
    public function searchVoicings(Request $request)
    {
        $query = $request->get('query', '');
        if (empty($query)) {
            return response()->json(['success' => true, 'results' => []]);
        }

        $results = $this->voicingSearch->searchByName($query);

        foreach ($results as &$r) {
            if (is_array($r['diagram_data'] ?? null)) {
                $r['diagram_data'] = json_encode($r['diagram_data']);
            }
        }
        unset($r);

        return response()->json(['success' => true, 'results' => $results]);
    }

    /**
     * Advanced voicing search for the Enhanced Voicing Picker.
     * Accepts explicit filter parameters instead of parsing a chord-name string.
     */
    public function searchVoicingsAdvanced(Request $request)
    {
        $root            = $request->get('root') ?? '';
        $quality         = $request->get('quality') ?? '';
        $extension       = $request->get('extension') ?? '';
        $inversion       = $request->get('inversion') ?? '';
        $voicingCategory = $request->get('voicing_category') ?? '';
        $rootString      = $request->get('root_string') ?? '';
        $bassNote        = $request->get('bass_note') ?? '';

        if (empty($root) || empty($quality)) {
            return response()->json(['success' => true, 'results' => [], 'filters' => []]);
        }

        $query = DB::table('sbn_chord_diagrams')->where('quality', $quality);

        if (!empty($extension)) {
            $query->where('extensions', $extension);
        } else {
            $query->whereRaw("(extensions = '' OR extensions IS NULL)");
        }
        if (!empty($inversion) && $inversion !== 'all') {
            $query->where('inversion', $inversion);
        }
        if (!empty($voicingCategory) && $voicingCategory !== 'all') {
            $query->where('voicing_category', $voicingCategory);
        }
        if (!empty($rootString) && $rootString !== 'all') {
            $query->where('root_string', $rootString);
        }

        $slashType = '';
        if (!empty($bassNote)) {
            $slashInfo = $this->calculator->analyzeSlashChord($root, $quality, $bassNote);
            $slashType = $slashInfo['type'];
            if ($slashType === 'slash') {
                $query->where(function ($q) {
                    $q->where('bass_note', '!=', '')->whereNotNull('bass_note');
                });
            }
        } else {
            $query->whereRaw("(bass_note = '' OR bass_note IS NULL)");
        }

        $shapes = $query
            ->orderByDesc('popularity')
            ->orderBy('voicing_category')
            ->orderBy('root_string')
            ->orderBy('inversion')
            ->get();

        $results = [];
        $seenIds = [];
        if ($shapes->isNotEmpty()) {
            $results = $this->voicingSearch->transposeShapes($shapes, $root, $quality, $bassNote, $slashType);
            $seenIds = array_values(array_filter(array_map(fn ($r) => $r['id'] ?? null, $results)));
        }

        $aliasResults = $this->voicingSearch->findAliasMatches(
            $root, $quality, $extension, $bassNote,
            $voicingCategory, $rootString, $seenIds
        );
        $results = array_merge($results, $aliasResults);

        foreach ($results as &$r) {
            if (is_array($r['diagram_data'] ?? null)) {
                $r['diagram_data'] = json_encode($r['diagram_data']);
            }
        }
        unset($r);

        return response()->json([
            'success' => true,
            'results' => $results,
            'filters' => $this->getAvailableFilters($quality),
        ]);
    }

    private function getAvailableFilters(string $quality): array
    {
        $shapes = DB::table('sbn_chord_diagrams')
            ->where('quality', $quality)
            ->get(['voicing_category', 'root_string', 'extensions', 'inversion']);

        return [
            'voicing_categories' => $shapes->pluck('voicing_category')->filter()->unique()->sort()->values()->all(),
            'root_strings'       => $shapes->pluck('root_string')->filter()->unique()->sort()->values()->all(),
            'extensions'         => $shapes->pluck('extensions')->filter()->unique()->sort()->values()->all(),
            'inversions'         => $shapes->pluck('inversion')->filter()->unique()->sort()->values()->all(),
        ];
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    private function validateLeadsheet(Request $request): array
    {
        return $request->validate([
            'title'             => 'required|string|max:255',
            'composer'          => 'nullable|string|max:255',
            'song_key'          => 'nullable|string|max:10',
            'tempo'             => 'nullable|integer|min:20|max:300',
            'time_signature'    => 'nullable|string|max:10',
            'rhythm'            => 'nullable|string|max:50',
            'course_id'         => 'nullable|integer',
            'shortcode_content' => 'nullable|string',
            'json_data'         => 'nullable|string',
            'tab_xml'           => 'nullable|string',
            'description'       => 'nullable|string|max:5000',
            'harmony_notes'     => 'nullable|string|max:5000',
            'form_notes'        => 'nullable|string|max:5000',
            'voicing_notes'     => 'nullable|string|max:5000',
        ]);
    }

    private function countMeasures(array $parsed): int
    {
        $count = 0;
        foreach ($parsed['sections'] ?? [] as $section) {
            $count += count($section['measures'] ?? []);
        }
        return $count;
    }

    /**
     * Normalize chord names embedded in a leadsheet's json_data payload.
     * Strips bare "maj" (Gmaj → G) and renormalizes any chordVoicings keys
     * so existing voicings continue to resolve after the rename.
     */
    private function normalizeChordNamesInJson(string $json): string
    {
        $data = json_decode($json, true);
        if (!is_array($data)) return $json;

        $walkMeasures = function (array &$measures) {
            foreach ($measures as &$measure) {
                if (!isset($measure['chords']) || !is_array($measure['chords'])) continue;
                foreach ($measure['chords'] as &$chord) {
                    if (is_array($chord) && isset($chord['name'])) {
                        $chord['name'] = \App\Helpers\ChordName::normalize($chord['name']);
                    } elseif (is_string($chord)) {
                        $chord = \App\Helpers\ChordName::normalize($chord);
                    }
                }
                unset($chord);
            }
            unset($measure);
        };

        if (isset($data['measures']) && is_array($data['measures'])) {
            $walkMeasures($data['measures']);
        }
        if (isset($data['sections']) && is_array($data['sections'])) {
            foreach ($data['sections'] as &$section) {
                if (isset($section['measures']) && is_array($section['measures'])) {
                    $walkMeasures($section['measures']);
                }
            }
            unset($section);
        }

        if (!empty($data['chordVoicings']) && is_array($data['chordVoicings'])) {
            $remapped = [];
            foreach ($data['chordVoicings'] as $key => $voicing) {
                // Keys may be "Name" or "Name@gi.ci" — normalize the name portion only.
                $atIdx = strpos($key, '@');
                if ($atIdx === false) {
                    $newKey = \App\Helpers\ChordName::normalize($key);
                } else {
                    $namePart = substr($key, 0, $atIdx);
                    $newKey   = \App\Helpers\ChordName::normalize($namePart) . substr($key, $atIdx);
                }
                $remapped[$newKey] = $voicing;
            }
            $data['chordVoicings'] = $remapped;
        }

        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function injectInfoBlock(string $shortcode, array $data): string
    {
        if (str_contains($shortcode, '[sbn_info]')) {
            return $shortcode;
        }

        $infoParts = [];
        if (!empty($data['description']))    $infoParts[] = '[description]' . $data['description'] . '[/description]';
        if (!empty($data['harmony_notes']))  $infoParts[] = '[harmony]' . $data['harmony_notes'] . '[/harmony]';
        if (!empty($data['form_notes']))     $infoParts[] = '[form]' . $data['form_notes'] . '[/form]';
        if (!empty($data['voicing_notes']))  $infoParts[] = '[voicings]' . $data['voicing_notes'] . '[/voicings]';

        if (empty($infoParts)) return $shortcode;

        $infoBlock = "\n[sbn_info]\n" . implode("\n", $infoParts) . "\n[/sbn_info]";

        if (str_contains($shortcode, '[/sbn_leadsheet]')) {
            return str_replace('[/sbn_leadsheet]', $infoBlock . "\n[/sbn_leadsheet]", $shortcode);
        }

        return $shortcode . $infoBlock;
    }

    private function serializeLeadsheet(Leadsheet $leadsheet, $jsonData): array
    {
        return [
            'id'                => $leadsheet->id,
            'title'             => $leadsheet->title,
            'composer'          => $leadsheet->composer,
            'song_key'          => $leadsheet->song_key,
            'tempo'             => $leadsheet->tempo,
            'time_signature'    => $leadsheet->time_signature,
            'rhythm'            => $leadsheet->rhythm,
            'shortcode_content' => $leadsheet->shortcode_content,
            'json_data'         => $jsonData,
            'tab_xml'           => $leadsheet->tab_xml,
            'description'       => $leadsheet->description,
            'harmony_notes'     => $leadsheet->harmony_notes,
            'form_notes'        => $leadsheet->form_notes,
            'voicing_notes'     => $leadsheet->voicing_notes,
        ];
    }

    private function diagramDataToFretString(array $data): string
    {
        $frets = array_fill(0, 6, 'x');
        foreach ($data['strings'] ?? [] as $i => $stringData) {
            if (isset($stringData['fret'])) {
                $f = $stringData['fret'];
                if ($f === -1 || $f === 'x')   $frets[$i] = 'x';
                elseif ($f === 0)              $frets[$i] = '0';
                else                           $frets[$i] = $f <= 9 ? (string)$f : dechex($f);
            }
        }
        return implode('', $frets);
    }

    public function identifySingle(Request $request)
    {
        $frets = $request->input('frets');

        if (!$frets || strlen($frets) !== 6) {
            return response()->json([
                'success' => false,
                'error'   => 'Invalid frets string — must be exactly 6 characters.',
            ], 422);
        }

        $crossref = app(\App\Services\VoicingCrossref::class);
        $result   = $crossref->identifyFromFrets($frets);

        return response()->json([
            'success'      => true,
            'name'         => $result['name']         ?? null,
            'confidence'   => $result['confidence']   ?? 'none',
            'alternatives' => $result['alternatives'] ?? [],
        ]);
    }

    public function youtubeSearch(Request $request)
    {
        $query = $request->input('q');
        if (empty($query)) {
            return response()->json(['success' => true, 'items' => []]);
        }

        $apiKey = env('YOUTUBE_API_KEY');
        if (empty($apiKey)) {
            return response()->json(['success' => false, 'error' => 'YouTube API Key not configured on the server.'], 500);
        }

        $response = \Illuminate\Support\Facades\Http::get('https://www.googleapis.com/youtube/v3/search', [
            'part' => 'snippet',
            'q' => $query,
            'type' => 'video',
            'maxResults' => 10,
            'videoCategoryId' => '10', // Music
            'key' => $apiKey,
        ]);

        if ($response->failed()) {
            return response()->json(['success' => false, 'error' => 'Failed to connect to YouTube API.'], 502);
        }

        $data = $response->json();
        $items = [];
        foreach ($data['items'] ?? [] as $item) {
            $items[] = [
                'videoId' => $item['id']['videoId'],
                'title' => html_entity_decode($item['snippet']['title']),
                'channelTitle' => html_entity_decode($item['snippet']['channelTitle']),
                'thumbnail' => $item['snippet']['thumbnails']['medium']['url'] ?? '',
                'publishedAt' => $item['snippet']['publishedAt']
            ];
        }

        return response()->json(['success' => true, 'items' => $items]);
    }

    /**
     * Phase 6f — Apply a voiced progression to a leadsheet's tab_xml.
     *
     * POST /api/admin/leadsheets/{leadsheet}/apply-progression
     * Body: {
     *   "selections": [
     *     { "chord_name": "Dm7", "frets": "x57565", "position": 5 },
     *     ...
     *   ],
     *   "time_signature": "4/4"
     * }
     *
     * Builds MusicXML from the voicing sequence (one measure per chord),
     * stores in tab_xml, updates chordVoicings in json_data.
     */
    public function applyProgression(Request $request, Leadsheet $leadsheet, VoicingMaterializer $materializer)
    {
        $selections    = $request->input('selections', []);
        $timeSignature = $request->input('time_signature', '4/4');

        if (empty($selections)) {
            return response()->json(['success' => false, 'error' => 'No selections provided.'], 422);
        }

        $result = $materializer->materialize($selections, $timeSignature);

        // ── Persist ─────────────────────────────────────────────────────
        // Read json_data fresh from DB (bypasses Eloquent cast/dirty issues)
        $raw      = DB::table('sbn_leadsheets')->where('id', $leadsheet->id)->value('json_data');
        $jsonData = $raw ? (json_decode($raw, true) ?? []) : [];

        // Merge only chordVoicings — sections/structure untouched
        $existing  = $jsonData['chordVoicings'] ?? [];
        $jsonData['chordVoicings'] = array_merge($existing, $result['voicings']);
        $jsonData['melody'] = $result['melody'];  // useTabModel reads this on load

        // Raw DB update — avoids Eloquent double-encoding or stale attribute issues
        DB::table('sbn_leadsheets')->where('id', $leadsheet->id)->update([
            'tab_xml'    => $result['tab_xml'],
            'json_data'  => $this->normalizeChordNamesInJson(json_encode($jsonData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'updated_at' => now(),
        ]);

        return response()->json([
            'success'  => true,
            'tab_xml'  => $result['tab_xml'],
            'voicings' => $result['voicings'],
            'measures' => $result['measures'],
        ]);
    }

    /**
     * Remove a specific voicing from the leadsheet by chord name and fret string.
     * Called from the leadsheet editor when user deletes a chord diagram.
     */
    public function removeVoicing(Request $request, Leadsheet $leadsheet)
    {
        $validated = $request->validate([
            'chord_name' => 'required|string|max:50',
            'fret_string'  => 'required|string|max:20',
        ]);

        $removed = $leadsheet->removeVoicing($validated['chord_name'], $validated['fret_string']);

        if ($removed) {
            $leadsheet->save();
        }

        // Also delete any pending draft for this voicing
        $draftDeleted = \App\Models\VoicingDraft::where('leadsheet_id', $leadsheet->id)
            ->where('chord_name', $validated['chord_name'])
            ->where('fret_string', $validated['fret_string'])
            ->where('status', 'pending')
            ->delete();

        return response()->json([
            'success'          => true,
            'removed'          => $removed,
            'draft_deleted'    => $draftDeleted > 0,
        ]);
    }

    protected function timeToTicks($time, $beatTimes)
    {
        $ppq = 480;
        $numBeats = count($beatTimes);
        if ($time <= $beatTimes[0]) return 0;
        
        $lo = 0; $hi = $numBeats - 1;
        while ($lo < $hi - 1) {
            $mid = (int)(($lo + $hi) / 2);
            if ($beatTimes[$mid] <= $time) $lo = $mid;
            else $hi = $mid;
        }
        
        $aTime = $beatTimes[$lo];
        $bTime = $lo + 1 < $numBeats ? $beatTimes[$lo + 1] : $aTime + 0.5;
        
        $t = ($time - $aTime) / max(0.01, $bTime - $aTime);
        return (int)round(($lo + $t) * $ppq);
    }

    protected function midiToNote($midi)
    {
        $names = ['C', 'C#', 'D', 'D#', 'E', 'F', 'F#', 'G', 'G#', 'A', 'A#', 'B'];
        return [
            'pitch' => $names[$midi % 12],
            'octave' => (int)floor($midi / 12) - 1
        ];
    }

    protected function midiToTab($midi)
    {
        $stringPitches = [64, 59, 55, 50, 45, 40]; // 1(e) to 6(E)
        foreach ($stringPitches as $i => $base) {
            $fret = $midi - $base;
            if ($fret >= 0 && $fret <= 15) {
                return ['string' => $i + 1, 'fret' => $fret];
            }
        }
        return ['string' => 6, 'fret' => max(0, $midi - 40)];
    }

    protected function ticksToDuration($ticks)
    {
        if ($ticks >= 1920) return 'w';
        if ($ticks >= 960)  return 'h';
        if ($ticks >= 480)  return 'q';
        if ($ticks >= 240)  return 'e';
        return 's';
    }

    protected function quantizeToStandard(int $ticks): int
    {
        // Restricted to regular subdivisions (no dotted notes)
        $standards = [1920, 960, 480, 240, 120];
        $best = 120;
        $bestDiff = PHP_INT_MAX;
        foreach ($standards as $s) {
            $diff = abs($ticks - $s);
            // Bias: favor larger duration to fill gaps and align with beat boundaries
            if ($diff < $bestDiff || ($diff === $bestDiff && $s > $best)) {
                $bestDiff = $diff;
                $best = $s;
            }
        }
        return $best;
    }

    /**
     * Refine a raw audio transcription using Gemini.
     *
     * Phase 2b: The input analysis now contains raw MIDI pitch arrays per chord
     * slot (from basic-pitch) instead of pre-identified chord names. Gemini
     * reads the 'pitches' field on each chord object, applies its harmonic
     * knowledge plus the raw_beats context, and returns proper chord labels.
     * It also quantizes melody, identifies song sections, and determines the key.
     *
     * Any chord slot Gemini cannot confidently identify is returned as '?' and
     * resolved by the post-AI resolveUnidentifiedChords() fallback pass.
     */
    protected function musicalizeTranscription(array $rawAnalysis, SongLookup $lookup): array
    {
        $client = app(LLM\LookupClient::class);

        $systemPrompt = <<<PROMPT
You are a master music transcriber and theorist. I have a raw AI audio transcription of a song.
Each chord slot in the 'sections' data contains a 'pitches' array: a set of MIDI pitch classes
(integers 0–11, where 0=C, 1=C#/Db, 2=D … 11=B) detected at that moment in the audio.
You also have a 'raw_beats' array with the same pitch data at beat-level granularity.

## YOUR TASKS:
1. **CHORD IDENTIFICATION**: For each chord slot, read its 'pitches' array and use your music
   theory knowledge to determine the correct chord name. Write it in the 'label' field.
   - Use standard leadsheet notation: C, Dm, G7, Fmaj7, Am7/G, etc.
   - If fewer than 3 distinct pitch classes are present, or you cannot determine a chord,
     return '/' (repeat sign) or '?' — the system will handle it.
   - Consider functional harmony and the surrounding chord context when disambiguating.
2. **KEY DETECTION**: Determine the song's key from the overall harmonic content.
3. **STRUCTURALIZATION**: Group bars into logical sections (Intro, Verse, Chorus, Bridge, Outro).
   Rename the 'label' field on each section accordingly.
4. **RHYTHMIC SIMPLIFICATION**: Quantize melody note tick values to standard 8th/16th note grids.
   DO NOT use dotted notes. Use only regular whole, half, quarter, 8th, and 16th notes. 
   Every note MUST fit within a single beat boundary (1, 2, 3, or 4). If a note sounds 
   across a boundary, split it into two notes or truncate it. Ensure every beat 
   is filled with a combination of notes and rests that sum to exactly one quarter note.
5. **FORMAT**: Return data in the exact IntermediateAnalysis schema. Do NOT include the
   'pitches' or 'raw_beats' fields in your output — only 'label' and 'beat' per chord.

PROMPT;

        $userPrompt = "Raw Transcription Data for '{$rawAnalysis['title']}':\n\n";
        $userPrompt .= json_encode($rawAnalysis, JSON_PRETTY_PRINT);

        $schema = [
            'type'       => 'OBJECT',
            'properties' => [
                'title'         => ['type' => 'STRING'],
                'composer'      => ['type' => 'STRING', 'nullable' => true],
                'key'           => ['type' => 'STRING'],
                'tempo'         => ['type' => 'INTEGER'],
                'timeSignature' => ['type' => 'STRING'],
                'sections'      => [
                    'type'  => 'ARRAY',
                    'items' => [
                        'type'       => 'OBJECT',
                        'properties' => [
                            'name' => ['type' => 'STRING'],
                            'bars' => [
                                'type'  => 'ARRAY',
                                'items' => [
                                    'type'       => 'OBJECT',
                                    'properties' => [
                                        'chords' => [
                                            'type'  => 'ARRAY',
                                            'items' => [
                                                'type'       => 'OBJECT',
                                                'properties' => [
                                                    'label' => ['type' => 'STRING'],
                                                    'beat'  => ['type' => 'INTEGER'],
                                                ],
                                                'required' => ['label', 'beat'],
                                            ],
                                        ],
                                    ],
                                    'required' => ['chords'],
                                ],
                            ],
                        ],
                        'required' => ['name', 'bars'],
                    ],
                ],
                'melody_data' => [
                    'type'  => 'ARRAY',
                    'items' => [
                        'type'       => 'OBJECT',
                        'properties' => [
                            'tick'     => ['type' => 'INTEGER'],
                            'pitch'    => ['type' => 'STRING'],
                            'octave'   => ['type' => 'INTEGER'],
                            'duration' => ['type' => 'STRING'],
                            'ticks'    => ['type' => 'INTEGER'],
                            'string'   => ['type' => 'INTEGER'],
                            'fret'     => ['type' => 'INTEGER'],
                            'isRest'   => ['type' => 'BOOLEAN'],
                        ],
                    ],
                ],
                'source_note' => ['type' => 'STRING'],
            ],
            'required' => ['title', 'key', 'timeSignature', 'sections', 'source_note'],
        ];

        $response = $client->complete($systemPrompt, $userPrompt, $schema, [
            'timeoutSeconds' => 60,
        ]);

        $refined = $response['data'];

        // Preserve videoSync (Gemini does not return it)
        if (!isset($refined['videoSync']) && isset($rawAnalysis['videoSync'])) {
            $refined['videoSync'] = $rawAnalysis['videoSync'];
        }

        return $refined;
    }

    /**
     * Post-AI fallback: iterate the returned analysis and resolve any chord
     * slot where the label is still '?' or null.
     *
     * Each such slot carries a 'pitches' array (pitch classes 0–11) that was
     * embedded by the pre-AI beat loop. identifyFromMidi() is called on those
     * pitches using the full Phase-1 algorithm. The 'pitches' key is stripped
     * from all slots before returning so it does not leak into assembly.
     *
     * Called both on AI-success and AI-failure paths (fail-safe).
     */
    private function resolveUnidentifiedChords(array $analysis, VoicingCrossref $crossref): array
    {
        foreach ($analysis['sections'] as &$section) {
            foreach ($section['bars'] as &$bar) {
                foreach ($bar['chords'] as &$chord) {
                    $label   = $chord['label'] ?? null;
                    $pitches = $chord['pitches'] ?? null;

                    // Resolve slots the AI could not identify
                    if (($label === '?' || $label === null) && !empty($pitches)) {
                        // pitches are already pitch classes (0–11) from the pre-AI loop;
                        // identifyFromMidi() expects absolute MIDI pitches but operates
                        // mod-12 internally, so pitch classes work correctly here.
                        $idResult = $crossref->identifyFromMidi($pitches);
                        $chord['label'] = $idResult['name'] ?: '/';
                    }

                    // Always strip the pitches key — not part of the leadsheet schema
                    unset($chord['pitches']);
                }
                unset($chord);
            }
            unset($bar);
        }
        unset($section);

        return $analysis;
    }

    /**
     * Jaccard similarity between two sets of MIDI pitches.
     * Used by the non-AI harmonic region grouping pass (Phase 2c).
     *
     * Returns 1.0 for identical sets, 0.0 for completely disjoint sets.
     * Threshold >= 0.5 means at least half the combined notes are shared.
     */
    private function jaccardSimilarity(array $setA, array $setB): float
    {
        $a = array_unique($setA);
        $b = array_unique($setB);

        if (empty($a) && empty($b)) return 1.0;
        if (empty($a) || empty($b)) return 0.0;

        $intersection = count(array_intersect($a, $b));
        $union        = count(array_unique(array_merge($a, $b)));

        return $union > 0 ? $intersection / $union : 0.0;
    }
}

