<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\LeadsheetBackingTrackRequest;
use App\Http\Requests\Admin\LeadsheetIsProRequest;
use App\Http\Requests\Admin\LeadsheetStatusRequest;
use App\Models\Leadsheet;
use App\Models\LeadsheetVersion;
use App\Models\RhythmPattern;
use App\Models\SbnTag;
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
use Illuminate\Support\Str;
use App\Services\VoicingMaterializer;
use App\Services\SongLookup;
use App\Services\AnalysisToLeadsheet;
use App\Services\RhythmHintMapper;
use App\Services\MidiTranscriptionService;
use App\Services\TabXmlTransposer;


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
        $style = $request->get('style');

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
            if ($style) {
                $stylePrefixes = [
                    'bossa-nova' => ['bossa-nova', 'bossa'],
                    'jazz'       => ['jazz'],
                    'classical'  => ['classical'],
                    'pop'        => ['pop'],
                ];
                $genreValues = $style === 'bossa-nova' ? ['bossa-nova', 'bossa'] : [$style];
                $prefixes = $stylePrefixes[$style] ?? [$style];
                $query->where(function ($q) use ($genreValues, $prefixes) {
                    $q->whereIn('genre', $genreValues)->orWhere(function ($q) use ($prefixes) {
                        $q->whereNull('genre')->orWhere('genre', '');
                    })->where(function ($q) use ($prefixes) {
                        foreach ($prefixes as $prefix) {
                            $q->orWhere('rhythm', $prefix)->orWhere('rhythm', 'like', $prefix . '-%');
                        }
                    });
                });
            }
            // Eager-load arrangements for the version badge + accordion in the list.
            $items = $query->with('versions')->withCount('versions')
                ->orderBy('title')->paginate(25)->withQueryString();
        }

        $stats = Leadsheet::getStats();
        // Add exercise count to stats
        $stats['exercises'] = \App\Models\Exercise::count();
        
        $keys = Leadsheet::getDistinctKeys();
        $composers = Leadsheet::getDistinctComposers();
        $styles = Leadsheet::getDistinctStyles();
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
            'styles'        => $styles,
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
            'existingTags'   => '',
        ]);
    }

    public function edit(Request $request, Leadsheet $leadsheet)
    {
        $rhythms        = RhythmPattern::orderBy('category')->orderBy('name')->get();
        $rhythmPatterns = $rhythms->mapWithKeys(fn ($r) => [$r->slug => $r->toPlayerData()]);
        $existingTags   = $leadsheet->tags()->pluck('slug')->implode(',');

        // Resolve which arrangement (version) we're editing. ?v=slug selects one;
        // otherwise the default version. The editor reads this version's data so
        // saving targets the right arrangement (multi-version songs).
        $versionList = $leadsheet->versions;     // ordered difficulty, sort_order
        $activeVersion = $request->query('v')
            ? $versionList->firstWhere('version_slug', $request->query('v'))
            : null;
        $activeVersion ??= $leadsheet->defaultVersion ?? $versionList->first();

        // Overlay the active version's arrangement data onto the leadsheet instance
        // the editor renders from, so the existing Blade bindings ($leadsheet->json_data
        // etc.) transparently show the selected version. Legacy columns remain the
        // dual-read fallback when a version is somehow absent.
        if ($activeVersion) {
            $leadsheet->setAttribute('json_data',         $activeVersion->json_data ?? $leadsheet->json_data);
            $leadsheet->setAttribute('tab_xml',           $activeVersion->melody_tab_xml ?? $leadsheet->tab_xml);
            $leadsheet->setAttribute('shortcode_content', $activeVersion->shortcode_content ?? $leadsheet->shortcode_content);
            $leadsheet->setAttribute('song_key',          $activeVersion->song_key ?: $leadsheet->song_key);
            $leadsheet->setAttribute('rhythm',            $activeVersion->rhythm ?: $leadsheet->rhythm);
            $leadsheet->setAttribute('tempo',             $activeVersion->tempo ?: $leadsheet->tempo);
        }

        return view('admin.leadsheets.edit', [
            'leadsheet'      => $leadsheet,
            'rhythms'        => $rhythms,
            'rhythmPatterns' => $rhythmPatterns,
            'existingTags'   => $existingTags,
            'versionList'    => $versionList,
            'activeVersion'  => $activeVersion,
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

        if (!empty($shortcode)) {
            $crossref = app(VoicingCrossref::class);
            $crossref->processLeadsheet($leadsheet);
        }

        $this->syncTags($leadsheet, $this->parseTagSlugs($request->input('tags', '')));

        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'id' => $leadsheet->id, 'message' => 'Leadsheet created.']);
        }

        return redirect()->route('admin.leadsheets.edit', $leadsheet)->with('success', 'Leadsheet created successfully.');
    }

    /**
     * Convert an uploaded MuseScore file (.mscz/.mscx) to MusicXML via the local
     * MuseScore CLI, returning the XML as plain text. Used by the editor's file
     * importer so a .mscz can be dropped straight into the app (local dev only —
     * requires MuseScore installed on the server running this route).
     */
    public function convertMscz(Request $request)
    {
        $request->validate([
            'file' => 'required|file|max:51200', // 50 MB
        ]);

        $file = $request->file('file');
        $ext  = strtolower($file->getClientOriginalExtension());
        if (!in_array($ext, ['mscz', 'mscx'], true)) {
            return response()->json(['message' => 'Expected a .mscz or .mscx file'], 422);
        }

        $bin = config('services.musescore.bin');
        if (!$bin || !is_file($bin)) {
            return response()->json([
                'message' => 'MuseScore CLI not found. Set MUSESCORE_BIN in .env to MuseScore4.exe.',
            ], 500);
        }

        // Stage input + output in a unique temp dir so concurrent imports don't collide.
        $tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sbn-mscz-' . Str::random(8);
        @mkdir($tmpDir, 0777, true);
        $inPath  = $tmpDir . DIRECTORY_SEPARATOR . 'in.' . $ext;
        $outPath = $tmpDir . DIRECTORY_SEPARATOR . 'out.musicxml';
        $file->move($tmpDir, 'in.' . $ext);

        try {
            $cmd = '"' . $bin . '" "' . $inPath . '" -o "' . $outPath . '"';
            Log::info("[convertMscz] {$cmd}");

            $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $proc = proc_open($cmd, $descriptors, $pipes);
            if (!is_resource($proc)) {
                return response()->json(['message' => 'Could not start MuseScore'], 500);
            }
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $code = proc_close($proc);

            if ($code !== 0 || !is_file($outPath)) {
                Log::error("[convertMscz] MuseScore failed (code {$code}): {$stderr}");
                return response()->json([
                    'message' => 'MuseScore conversion failed (code ' . $code . ').',
                ], 500);
            }

            $xml = file_get_contents($outPath);
            if ($xml === false || strpos($xml, '<score-partwise') === false) {
                return response()->json(['message' => 'Converter produced no MusicXML'], 500);
            }

            return response($xml, 200)->header('Content-Type', 'application/xml');
        } finally {
            // Best-effort cleanup of the temp staging dir.
            @unlink($inPath);
            @unlink($outPath);
            @rmdir($tmpDir);
        }
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
                        'diagram_data'  => $v['diagram_data'] ?? null,
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
                            'diagram_data'  => $sel['diagram_data'] ?? null,
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
            'local_audio'   => 'nullable|file|mimes:mp3,wav,m4a,ogg,flac|max:102400',
            'bass_snap'     => 'nullable|boolean',
            'tab_position_style' => 'nullable|string|in:fretted,open',
            // ── basic-pitch detection tuning (audio mode) ────────────────────
            'detection_preset'     => 'nullable|string|in:balanced,sensitive,strict,custom',
            'onset_threshold'      => 'nullable|numeric|min:0.05|max:0.95',
            'frame_threshold'      => 'nullable|numeric|min:0.05|max:0.95',
            'minimum_note_length'  => 'nullable|numeric|min:10|max:500',
            'restrict_guitar_range'=> 'nullable|boolean',
            'separate_stem'        => 'nullable|boolean',
            // Two-phase stem workflow: when the admin has already separated &
            // auditioned, the modal sends a session token + the chosen stems.
            // (Legacy — the modal no longer auditions; it sends stem_choice below.)
            'stem_session'         => 'nullable|string|max:64',
            'stems'                => 'nullable|array',
            'stems.*'              => 'string|in:guitar,bass,vocals,drums,piano,other',
            // Modal stem quick-pick: 'mix' (no separation) or a comma-separated
            // stem set (e.g. 'guitar', 'guitar,bass', 'bass'). Separation runs on
            // submit — fine per-stem audition lives in the editor, not here.
            'stem_choice'          => 'nullable|string|max:64',
        ]);

        $sourceAudioPath = null; // preserved original recording (audio mode only)
        if (($validated['mode'] ?? '') === 'audio') {
            $hasYoutube = !empty($validated['youtube_id']);
            $hasLocal   = $request->hasFile('local_audio');
            $stemSession = $validated['stem_session'] ?? null;
            $localAudioName = null; // set when a local upload is staged for stems

            if (!$hasYoutube && !$hasLocal) {
                return back()->withErrors(['lookup' => 'You must select a YouTube video or upload a local audio file for transcription.']);
            }

            // Resolve basic-pitch detection knobs from the modal's preset
            // (balanced / sensitive / strict) plus any custom overrides.
            $detectionParams = $this->resolveDetectionParams($validated);

            // The chosen stems (two-phase workflow). Empty when the admin never
            // ran the separate step — then $stemSession is null and we fall back
            // to the one-shot transcribe paths below.
            $chosenStems = array_values(array_intersect(
                $validated['stems'] ?? [],
                MidiTranscriptionService::STEM_NAMES
            ));

            // Modal stem quick-pick → separate on submit (no pre-made audition
            // session). 'mix' (or absent) means transcribe the full mix. Any stem
            // set reuses the two-phase engine: separate to a session, then the
            // $stemSession branch below sums + transcribes the chosen stems.
            $stemChoice = trim((string)($validated['stem_choice'] ?? ''));
            if (!$stemSession && $stemChoice !== '' && $stemChoice !== 'mix') {
                $pick = array_values(array_intersect(
                    array_map('trim', explode(',', $stemChoice)),
                    MidiTranscriptionService::STEM_NAMES
                ));
                if (!empty($pick)) {
                    // Stage a local upload to a caller-owned temp file (mirrors
                    // separateStems()); YouTube is downloaded inside the service.
                    $sepTemp = null;
                    try {
                        if ($hasLocal) {
                            $up = $request->file('local_audio');
                            // Capture the name before move() consumes the upload —
                            // the phase-2 title fallback reuses it for local stems.
                            $localAudioName = pathinfo($up->getClientOriginalName(), PATHINFO_FILENAME);
                            $tempDir = storage_path('app/temp_audio');
                            if (!is_dir($tempDir)) mkdir($tempDir, 0777, true);
                            $sepTemp = $tempDir . '/' . uniqid('sep_', true) . '.' . $up->getClientOriginalExtension();
                            $up->move($tempDir, basename($sepTemp));
                            $sep = $transcriber->separateStemsToSession($sepTemp, 'upload');
                        } else {
                            $sep = $transcriber->separateStemsToSession($validated['youtube_id'], 'youtube');
                        }
                    } catch (\Exception $e) {
                        return back()->withErrors(['lookup' => 'Stem separation failed: ' . $e->getMessage()]);
                    } finally {
                        if ($sepTemp && file_exists($sepTemp)) unlink($sepTemp);
                    }
                    if (!($sep['success'] ?? false)) {
                        return back()->withErrors(['lookup' => 'Stem separation failed: ' . ($sep['error'] ?? 'unknown error')]);
                    }
                    $stemSession = $sep['session'];
                    $chosenStems = $pick;
                }
            }

            try {
                if ($stemSession) {
                    // PHASE 2: transcribe the summed stems from the audition
                    // session. No re-download / re-separate.
                    $rawResult  = $transcriber->transcribeFromSession($stemSession, $chosenStems, $detectionParams);
                    $audioTitle = $request->input('youtube_title')
                        ?: ($hasLocal && $localAudioName ? $localAudioName : 'Audio Transcription');
                    $youtubeId  = $validated['youtube_id'] ?: null;
                } elseif ($hasLocal) {
                    $uploadedFile = $request->file('local_audio');
                    $tempDir  = storage_path('app/temp_audio');
                    if (!is_dir($tempDir)) mkdir($tempDir, 0777, true);
                    $tempPath = $tempDir . '/' . uniqid('local_', true) . '.' . $uploadedFile->getClientOriginalExtension();
                    $uploadedFile->move($tempDir, basename($tempPath));

                    try {
                        $rawResult = $transcriber->transcribeLocalFile($tempPath, $detectionParams);
                    } finally {
                        if (file_exists($tempPath)) unlink($tempPath);
                    }

                    $audioTitle  = pathinfo($uploadedFile->getClientOriginalName(), PATHINFO_FILENAME);
                    $youtubeId   = null;
                } else {
                    $rawResult  = $transcriber->transcribe($validated['youtube_id'], $detectionParams);
                    $audioTitle = $request->input('youtube_title') ?: 'Audio Transcription';
                    $youtubeId  = $validated['youtube_id'];
                }

                if (!($rawResult['success'] ?? false)) {
                    throw new \Exception($rawResult['error'] ?? "Unknown transcription error.");
                }

                // Preserved copy of the full original recording (temp path); moved
                // into public storage after the leadsheet is created so the editor
                // can blend the synth transcription against it.
                $sourceAudioPath = $rawResult['source_audio_path'] ?? null;
            } catch (\Exception $e) {
                return back()->withErrors(['lookup' => "Transcription Error: " . $e->getMessage()]);
            } finally {
                // Sweep the audition session — its stems have served their
                // purpose once transcription has been attempted.
                if ($stemSession) {
                    $transcriber->removeStemSession($stemSession);
                }
            }

            // Convert raw Python output (beats/notes) to standard Analysis format.
            // assembleTranscription() owns the beat→bar grouping, chord region
            // detection, melody reconstruction and videoSync mapping. The raw
            // Python output is cached in json_data so the downbeat can be
            // re-shifted later without re-downloading / re-transcribing.
            $analysis = $this->assembleTranscription($rawResult, [
                'title'       => $audioTitle,
                'key'         => $validated['preferred_key'] ?: 'C',
                'youtube_id'  => $youtubeId ?? '',
                // Bass-snap beat correction — on by default; the user can
                // re-shift from the editor if it misfires on a given tune.
                'bass_snap'   => !array_key_exists('bass_snap', $validated) || !empty($validated['bass_snap']),
                // Fretboard optimiser bias: 'fretted' (jazz chord-melody, the
                // default) keeps a bar in one neck position; 'open' favours
                // open strings (classical / fingerstyle).
                'tab_position_style' => $validated['tab_position_style'] ?? 'fretted',
                // Record whether Demucs stem separation ran, so the cached
                // transcriptionRaw reflects what actually produced this
                // transcription (see resolveDetectionParams()). In the two-phase
                // workflow this is the list of stems that were summed; otherwise
                // a bool for the legacy guitar-only one-shot path.
                'separate_stem' => $stemSession
                    ? $chosenStems
                    : ($detectionParams['separate_stem'] ?? true),
            ], 0, $crossref);

            // Note: audio transcription is fully deterministic. There is no AI
            // refinement pass — the old Gemini "musicalize" step (section
            // grouping + enharmonic relabel + key correction) was removed
            // 2026-07-07: rhythm is already quantized deterministically (§5),
            // spelling is owned by the app's deterministic spelling authority,
            // and section-from-chord-list inference was too weak to be worth an
            // LLM round-trip. See Audio-Transcription-Architecture.md §T4/T8.
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

        // Voicing building is an AI-Song-Search-only feature: it synthesizes
        // fingerings/comping/melody from bare chord labels, which is exactly what
        // that path lacks. An audio transcription already carries its own melody +
        // tab derived from the recording (basic-pitch + T1), and its key defaults
        // to 'C' in this path — so running the builder here would re-synthesize
        // voicings against the wrong key and clobber the transcribed data. The
        // modal hides the block in audio mode; this guard is the backend backstop.
        $wantVoicings = !empty($validated['build_voicings'])
            && ($validated['mode'] ?? '') !== 'audio';

        if ($wantVoicings) {
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

                // Per-measure melody position hints: where the melody's
                // fretting hand sits in each bar. The builder uses these to
                // pick chord voicings in the same neck region as the melody
                // (a chord-melody piece is played by one hand), instead of
                // anchoring the whole progression on the first chord's seed.
                $positionHints = $this->melodyPositionHints($analysis['melody_data'] ?? []);

                // Build context and run algorithm. Extensions only on opt-in.
                $extensionMode = $validated['extension_mode'] ?? 'basic';
                $hc = $context->buildFromChordSequence($analysis['key'], $allChords);
                $res = $builder->buildVoicings($hc, [
                    'category'       => $category,
                    'extensions'     => $extensionMode === 'extended',
                    'strict_basic'   => $extensionMode === 'basic',
                    'voicing_style'  => ($style === 'popular') ? 'auto' : $style,
                    'bars_per_chord' => $isStandard ? 1 : ($validated['bars_per_chord'] ?? 1),
                    'position_hints' => $positionHints,
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

        // Persist the preserved original recording (audio imports) so the editor
        // can blend the synth transcription against the real audio. Writes the
        // public URL into json_data.sourceAudio and deletes the temp copy.
        if ($sourceAudioPath && is_file($sourceAudioPath)) {
            try {
                $dir  = "audio/source/{$leadsheet->id}";
                $name = 'original.wav';
                $destDir = public_path($dir);
                if (!is_dir($destDir)) mkdir($destDir, 0777, true);
                if (@rename($sourceAudioPath, "{$destDir}/{$name}")) {
                    $jd = json_decode($leadsheet->json_data, true) ?: [];
                    $jd['sourceAudio'] = [
                        'url'  => asset("{$dir}/{$name}"),
                        'kind' => ($stemSession ?? null) ? 'stems_original' : (($youtubeId ?? null) ? 'youtube' : 'upload'),
                    ];
                    $leadsheet->update([
                        'json_data' => json_encode($jd, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ]);
                }
            } catch (\Throwable $e) {
                \Log::warning('[LeadsheetController] source audio persist failed: ' . $e->getMessage());
            } finally {
                if (is_file($sourceAudioPath)) @unlink($sourceAudioPath);
            }
        }

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

        // Which arrangement is being saved? ?v=slug (from the editor's version link)
        // or the default version. Edits are written to this version row; the leadsheet
        // legacy columns are dual-written for the read-fallback safety net.
        $activeVersion = $request->query('v')
            ? $leadsheet->versions()->where('version_slug', $request->query('v'))->first()
            : null;
        $activeVersion ??= $leadsheet->defaultVersion ?? $leadsheet->versions()->first();

        $parsed = null;
        if (!empty($validated['shortcode_content'])) {
            $parsed = $this->parser->parse($validated['shortcode_content']);
        }

        $shortcode = $validated['shortcode_content'] ?? '';
        $shortcode = $this->injectInfoBlock($shortcode, $validated);

        if (!empty($validated['json_data'])) {
            $validated['json_data'] = $this->normalizeChordNamesInJson($validated['json_data']);
        }

        // Allow slug correction — only update if explicitly provided and different
        if (!empty($validated['slug']) && $validated['slug'] !== $leadsheet->slug) {
            $newSlug = $validated['slug'];
            $i = 2;
            while (Leadsheet::where('slug', $newSlug)->where('id', '!=', $leadsheet->id)->exists()) {
                $newSlug = $validated['slug'] . '-' . $i++;
            }
            $leadsheet->slug = $newSlug;
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
            'genre'             => $validated['genre'] ?? null,
            'popularity'        => $validated['popularity'] ?? $leadsheet->popularity,
            'difficulty'        => $validated['difficulty'] ?? $leadsheet->difficulty,
        ]);

        // Write the arrangement data + identity to the active version (the source of
        // truth for public reads). Leadsheet columns above are the dual-read fallback.
        // Use empty-safe fallbacks so a blank form field never wipes an existing
        // key/rhythm (this is what previously reset a version's key to default).
        if ($activeVersion) {
            $versionUpdate = [
                'json_data'         => $validated['json_data'] ?? $activeVersion->json_data,
                'melody_tab_xml'    => $validated['tab_xml'] ?? $activeVersion->melody_tab_xml,
                'chord_tab_xml'     => array_key_exists('chord_tab_xml', $validated) ? ($validated['chord_tab_xml'] ?? null) : $activeVersion->chord_tab_xml,
                'shortcode_content' => $shortcode,
                'song_key'          => ($validated['song_key'] ?? '') !== '' ? $validated['song_key'] : $activeVersion->song_key,
                'rhythm'            => $validated['rhythm'] ?? $activeVersion->rhythm,
                'tempo'             => $validated['tempo'] ?? $activeVersion->tempo,
                'measure_count'     => $parsed ? $this->countMeasures($parsed) : $activeVersion->measure_count,
            ];
            // Version identity fields (only overwrite when the form sent them).
            if (array_key_exists('version_label', $validated)) {
                $versionUpdate['label'] = $validated['version_label'] ?: $activeVersion->label;
            }
            if (array_key_exists('version_performer', $validated)) {
                $versionUpdate['performer'] = $validated['version_performer'] ?: null;
            }
            if (array_key_exists('difficulty', $validated) && $validated['difficulty'] !== null) {
                $versionUpdate['difficulty'] = $validated['difficulty'];
            }
            // Per-arrangement notes (distinct from the leadsheet-level description
            // above — never falls back to it, empty just means "no notes yet" for
            // this arrangement). Only overwrite when the form actually sent it.
            if (array_key_exists('arrangement_notes', $validated)) {
                $versionUpdate['arrangement_notes'] = $validated['arrangement_notes'] ?: null;
            }
            $activeVersion->update($versionUpdate);
        }

        // Re-index voicing → DB chord associations whenever shortcode changes,
        // scoped to the arrangement we just saved.
        if (!empty($shortcode)) {
            $crossref = app(VoicingCrossref::class);
            $crossref->processLeadsheet($leadsheet->fresh(), $activeVersion?->fresh());
        }

        $this->syncTags($leadsheet, $this->parseTagSlugs($request->input('tags', '')));

        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'id' => $leadsheet->id, 'message' => 'Leadsheet updated.']);
        }

        // Preserve the active arrangement so a save stays on the same version.
        $editParams = ['leadsheet' => $leadsheet];
        if ($activeVersion && $activeVersion->version_slug !== ($leadsheet->defaultVersion?->version_slug)) {
            $editParams['v'] = $activeVersion->version_slug;
        }
        return redirect()->route('admin.leadsheets.edit', $editParams)->with('success', 'Leadsheet updated successfully.');
    }

    // =========================================================================
    // TRANSPOSE ARRANGEMENT (server-side, both tab layers)
    // =========================================================================

    /**
     * Atomically transpose the active arrangement version by $semitones semitones.
     *
     * Shifts:
     *   - melody_tab_xml  (frets + pitch)
     *   - chord_tab_xml   (frets + pitch, if non-empty)
     *   - json_data  chords[], chordVoicings frets, top-level key
     *   - version song_key (and leadsheet fallback columns if this is the default version)
     *
     * POST /admin/leadsheets/{leadsheet}/transpose
     * Body: { semitones: int, v?: string }
     * Response: { ok: true, song_key: string }
     */
    public function transpose(Request $request, Leadsheet $leadsheet)
    {
        $validated = $request->validate([
            'semitones' => ['required', 'integer', 'between:-11,11'],
            'v'         => ['nullable', 'string'],
        ]);

        $semitones = (int) $validated['semitones'];
        $vSlug     = $validated['v'] ?? $request->query('v');

        // Resolve active version exactly like update() does.
        $activeVersion = $vSlug
            ? $leadsheet->versions()->where('version_slug', $vSlug)->first()
            : null;
        $activeVersion ??= $leadsheet->defaultVersion ?? $leadsheet->versions()->first();

        if (!$activeVersion) {
            return response()->json(['ok' => false, 'message' => 'No arrangement version found.'], 422);
        }

        // Determine whether this is the default version (triggers dual-write).
        $isDefault = ($leadsheet->default_version_id === $activeVersion->id);

        // Compute the new key BEFORE the transaction so we can return it on error.
        $oldKey    = $activeVersion->song_key ?? 'C';
        $newKey    = TabXmlTransposer::transposeKey($oldKey, $semitones);

        DB::beginTransaction();
        try {
            // ── 1. Transpose both tab XML layers ────────────────────────────
            $newMelodyXml = TabXmlTransposer::transpose(
                $activeVersion->melody_tab_xml,
                $semitones,
                $newKey
            );

            $newChordXml = null;
            if (!empty($activeVersion->chord_tab_xml)) {
                $newChordXml = TabXmlTransposer::transpose(
                    $activeVersion->chord_tab_xml,
                    $semitones,
                    $newKey
                );
            }

            // ── 2. Transpose json_data (chords + voicings + key + melody) ─
            $jsonData = $activeVersion->parsed_data ?? [];
            if (!empty($jsonData)) {
                // Walk sections[].measures[].chords[] — use indexed loops because
                // PHP nested by-ref foreach does NOT reliably write back into nested
                // array values; indexed access writes directly to the source array.
                if (isset($jsonData['sections']) && is_array($jsonData['sections'])) {
                    for ($si = 0; $si < count($jsonData['sections']); $si++) {
                        $measures = $jsonData['sections'][$si]['measures'] ?? [];
                        for ($mi = 0; $mi < count($measures); $mi++) {
                            $chords = $jsonData['sections'][$si]['measures'][$mi]['chords'] ?? [];
                            for ($ci = 0; $ci < count($chords); $ci++) {
                                $chord = $jsonData['sections'][$si]['measures'][$mi]['chords'][$ci];
                                if (is_array($chord) && isset($chord['name'])) {
                                    $jsonData['sections'][$si]['measures'][$mi]['chords'][$ci]['name'] =
                                        TabXmlTransposer::transposeChordName($chord['name'], $semitones, $newKey);
                                } elseif (is_string($chord)) {
                                    $jsonData['sections'][$si]['measures'][$mi]['chords'][$ci] =
                                        TabXmlTransposer::transposeChordName($chord, $semitones, $newKey);
                                }
                            }
                        }
                    }
                }

                // Flat measures[] (legacy shape without sections, or duplicate top-level)
                if (isset($jsonData['measures']) && is_array($jsonData['measures'])) {
                    for ($mi = 0; $mi < count($jsonData['measures']); $mi++) {
                        $chords = $jsonData['measures'][$mi]['chords'] ?? [];
                        for ($ci = 0; $ci < count($chords); $ci++) {
                            $chord = $jsonData['measures'][$mi]['chords'][$ci];
                            if (is_array($chord) && isset($chord['name'])) {
                                $jsonData['measures'][$mi]['chords'][$ci]['name'] =
                                    TabXmlTransposer::transposeChordName($chord['name'], $semitones, $newKey);
                            } elseif (is_string($chord)) {
                                $jsonData['measures'][$mi]['chords'][$ci] =
                                    TabXmlTransposer::transposeChordName($chord, $semitones, $newKey);
                            }
                        }
                    }
                }

                // melody[] — transpose pitch + fret for each non-rest note
                if (isset($jsonData['melody']) && is_array($jsonData['melody'])) {
                    for ($ni = 0; $ni < count($jsonData['melody']); $ni++) {
                        $note = $jsonData['melody'][$ni];
                        if (!is_array($note) || !empty($note['isRest'])) {
                            continue;
                        }

                        // Transpose fret (clamp 0–24, no octave folding for melody)
                        if (isset($note['fret']) && is_numeric($note['fret'])) {
                            $jsonData['melody'][$ni]['fret'] = max(0, min(24, (int)$note['fret'] + $semitones));
                        }

                        // Transpose pitch + octave using the shared helper.
                        // json_data pitch format: "G", "C#", "Bb" (letter + optional #/b).
                        if (isset($note['pitch']) && is_string($note['pitch']) && isset($note['octave'])) {
                            $result = TabXmlTransposer::transposePitchStep(
                                $note['pitch'],
                                (int)$note['octave'],
                                $semitones,
                                $newKey
                            );
                            if ($result !== null) {
                                [$newPitch, $newOctave] = $result;
                                $jsonData['melody'][$ni]['pitch']  = $newPitch;
                                $jsonData['melody'][$ni]['octave'] = $newOctave;
                            }
                        }
                    }
                }

                // chordVoicings map: { "Cmaj7": { frets: "x32000", position: 0 }, ... }
                if (!empty($jsonData['chordVoicings']) && is_array($jsonData['chordVoicings'])) {
                    $remapped = [];
                    foreach ($jsonData['chordVoicings'] as $voicingKey => $voicing) {
                        // Key may be "Cmaj7" or "Cmaj7@2" (measure-override) — transpose only the chord part.
                        $atPos   = strpos($voicingKey, '@');
                        $chordPart = $atPos !== false ? substr($voicingKey, 0, $atPos) : $voicingKey;
                        $atSuffix  = $atPos !== false ? substr($voicingKey, $atPos)    : '';

                        $newChordPart = TabXmlTransposer::transposeChordName($chordPart, $semitones, $newKey);
                        $newKey_str   = $newChordPart . $atSuffix;

                        // Transpose frets
                        if (is_array($voicing) && isset($voicing['frets']) && is_string($voicing['frets'])) {
                            $newFrets = TabXmlTransposer::transposeVoicingFrets($voicing['frets'], $semitones);
                            $voicing['frets']    = $newFrets;
                            $voicing['position'] = TabXmlTransposer::fretsToPosition($newFrets);
                        }

                        $remapped[$newKey_str] = $voicing;
                    }
                    $jsonData['chordVoicings'] = $remapped;
                }

                // Top-level key field inside json_data
                if (isset($jsonData['key'])) {
                    $jsonData['key'] = $newKey;
                }
            }

            $newJsonData = !empty($jsonData)
                ? json_encode($jsonData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : $activeVersion->json_data;

            // ── 3. Save version ──────────────────────────────────────────────
            $versionUpdate = [
                'melody_tab_xml' => $newMelodyXml ?? $activeVersion->melody_tab_xml,
                'song_key'       => $newKey,
                'json_data'      => $newJsonData,
            ];
            if ($newChordXml !== null) {
                $versionUpdate['chord_tab_xml'] = $newChordXml;
            }
            $activeVersion->update($versionUpdate);

            // ── 4. Dual-write to leadsheet fallback columns (default only) ───
            // NOTE: sbn_leadsheets has NO chord_tab_xml column — that column lives
            // only on sbn_leadsheet_versions.  Only write the columns that exist.
            if ($isDefault) {
                $leadsheet->update([
                    'song_key' => $newKey,
                    'json_data' => $newJsonData,
                    'tab_xml'   => $newMelodyXml ?? $leadsheet->tab_xml,
                ]);
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('[LeadsheetController::transpose] failed', [
                'leadsheet_id' => $leadsheet->id,
                'semitones'    => $semitones,
                'error'        => $e->getMessage(),
            ]);
            return response()->json([
                'ok'      => false,
                'message' => 'Transpose failed: ' . $e->getMessage(),
            ], 422);
        }

        return response()->json(['ok' => true, 'song_key' => $newKey]);
    }

    /**
     * PHASE 1 of the audio import: run Demucs and persist all six stems so the
     * admin can audition each and pick which to transcribe. Returns a session
     * token + the list of available stems. No leadsheet is created yet.
     */
    public function separateStems(Request $request, \App\Services\MidiTranscriptionService $transcriber)
    {
        set_time_limit(600);

        $validated = $request->validate([
            'youtube_id'  => 'nullable|string',
            'local_audio' => 'nullable|file|mimes:mp3,wav,m4a,ogg,flac|max:102400',
            // Separate an existing leadsheet's persisted original recording
            // (from the editor's video-sync sidebar) — no re-upload.
            'leadsheet_id' => 'nullable|integer|exists:sbn_leadsheets,id',
        ]);

        $hasYoutube   = !empty($validated['youtube_id']);
        $hasLocal     = $request->hasFile('local_audio');
        $hasLeadsheet = !empty($validated['leadsheet_id']);
        if (!$hasYoutube && !$hasLocal && !$hasLeadsheet) {
            return response()->json(['success' => false, 'error' => 'Provide a YouTube id, upload an audio file, or a leadsheet with persisted source audio.'], 422);
        }

        if ($hasLeadsheet) {
            $ls = Leadsheet::find($validated['leadsheet_id']);
            $url = $ls?->parsed_data['sourceAudio']['url'] ?? null;
            $path = $url ? public_path(parse_url($url, PHP_URL_PATH)) : null;
            if (!$path || !is_file($path)) {
                return response()->json(['success' => false, 'error' => 'This leadsheet has no persisted source audio to separate.'], 422);
            }
            // Don't move/delete the persisted original — separateStemsToSession
            // treats an 'upload' source as caller-owned and won't unlink it.
            $result = $transcriber->separateStemsToSession($path, 'upload');
        } elseif ($hasLocal) {
            $uploadedFile = $request->file('local_audio');
            $tempDir = storage_path('app/temp_audio');
            if (!is_dir($tempDir)) mkdir($tempDir, 0777, true);
            $tempPath = $tempDir . '/' . uniqid('sep_', true) . '.' . $uploadedFile->getClientOriginalExtension();
            $uploadedFile->move($tempDir, basename($tempPath));
            try {
                $result = $transcriber->separateStemsToSession($tempPath, 'upload');
            } finally {
                if (file_exists($tempPath)) unlink($tempPath);
            }
        } else {
            $result = $transcriber->separateStemsToSession($validated['youtube_id'], 'youtube');
        }

        if (!($result['success'] ?? false)) {
            return response()->json(['success' => false, 'error' => $result['error'] ?? 'Stem separation failed.'], 422);
        }

        return response()->json([
            'success' => true,
            'session' => $result['session'],
            'stems'   => $result['stems'], // ordered subset of STEM_NAMES
        ]);
    }

    /**
     * Stream one separated stem for in-browser audition. Both the session token
     * and the stem name are whitelisted so neither can escape storage/app/stems.
     */
    public function streamStem(string $session, string $stem, \App\Services\MidiTranscriptionService $transcriber)
    {
        if (!in_array($stem, \App\Services\MidiTranscriptionService::STEM_NAMES, true)) {
            abort(404);
        }
        $path = $transcriber->stemSessionDir($session) . '/' . $stem . '.wav';
        if (!is_file($path)) {
            abort(404);
        }
        return response()->file($path, [
            'Content-Type'  => 'audio/wav',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * Persist a chosen audition stem as a leadsheet's hosted sync audio, so the
     * video-sync editor can follow (e.g.) the isolated guitar instead of the
     * full mix. Copies the session stem into public storage and returns its URL;
     * the frontend points videoSync at it. The ephemeral session may be swept
     * afterwards without affecting this persisted copy.
     */
    public function persistStemAsSync(Request $request, Leadsheet $leadsheet, \App\Services\MidiTranscriptionService $transcriber)
    {
        $validated = $request->validate([
            'session' => 'required|string|max:64',
            'stem'    => 'required|string|in:guitar,bass,vocals,drums,piano,other',
        ]);

        $src = $transcriber->stemSessionDir($validated['session']) . '/' . $validated['stem'] . '.wav';
        if (!is_file($src)) {
            return response()->json(['success' => false, 'error' => 'Stem not found (session may have expired).'], 422);
        }

        $dir = "audio/source/{$leadsheet->id}";
        $name = "stem-{$validated['stem']}.wav";
        $destDir = public_path($dir);
        if (!is_dir($destDir)) mkdir($destDir, 0777, true);
        if (!@copy($src, "{$destDir}/{$name}")) {
            return response()->json(['success' => false, 'error' => 'Could not persist the stem.'], 500);
        }

        return response()->json(['success' => true, 'url' => asset("{$dir}/{$name}")]);
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
    // VERSION MERGE (Phase 1) — combine two single-tab arrangements into one
    // version's two notation layers (melody_tab_xml + chord_tab_xml).
    // =========================================================================

    /**
     * Flat list of all arrangements for the merge modal's selects.
     * One row per version: which song, label, and which tab layers it has.
     */
    public function mergeSourceList()
    {
        $rows = LeadsheetVersion::query()
            ->join('sbn_leadsheets as l', 'l.id', '=', 'sbn_leadsheet_versions.leadsheet_id')
            ->orderBy('l.title')
            ->orderBy('sbn_leadsheet_versions.difficulty')
            ->orderBy('sbn_leadsheet_versions.sort_order')
            ->get([
                'sbn_leadsheet_versions.id',
                'sbn_leadsheet_versions.leadsheet_id',
                'sbn_leadsheet_versions.version_slug',
                'sbn_leadsheet_versions.label',
                'sbn_leadsheet_versions.performer',
                'l.title as song_title',
                'l.slug as song_slug',
                // Aliases must NOT collide with the model's has*Attribute accessors
                // (getHasMelody/HasMelodyTab/HasChordTab), which would shadow the SQL
                // value. `tab_*` are accessor-free, so the raw 0/1 passes through.
                DB::raw("CASE WHEN COALESCE(sbn_leadsheet_versions.melody_tab_xml, '') <> '' THEN 1 ELSE 0 END as tab_melody"),
                DB::raw("CASE WHEN COALESCE(sbn_leadsheet_versions.chord_tab_xml,  '') <> '' THEN 1 ELSE 0 END as tab_chord"),
            ]);

        return response()->json(['versions' => $rows]);
    }

    /**
     * Merge two source arrangements' tab data into a "mother" version's two layers.
     *
     * The mother version is the structure of record — its json_data/grid is kept
     * verbatim. We only write the two tab XML strings: the melody source's single
     * tab → mother.melody_tab_xml, the chords source's single tab → mother.chord_tab_xml.
     * Each source's "single tab" is whichever of its two columns is populated.
     *
     * No detection runs: voicing/progression caches key off json_data (unchanged),
     * not tab XML. So a merge never touches sbn_voicing_usage / occurrences.
     */
    public function mergeVersions(Request $request, Leadsheet $leadsheet)
    {
        $validated = $request->validate([
            'mother_version_slug' => 'required|string',
            'melody_version_id'   => 'required|integer|exists:sbn_leadsheet_versions,id',
            'chord_version_id'    => 'required|integer|exists:sbn_leadsheet_versions,id',
        ]);

        $mother = $leadsheet->versions()
            ->where('version_slug', $validated['mother_version_slug'])
            ->first();
        if (! $mother) {
            return response()->json(['success' => false, 'message' => 'Mother arrangement not found on this song.'], 422);
        }

        $melodySrc = LeadsheetVersion::find($validated['melody_version_id']);
        $chordSrc  = LeadsheetVersion::find($validated['chord_version_id']);

        // Each source is a single-tab arrangement: take whichever tab column it has.
        $melodyXml = $melodySrc->melody_tab_xml ?: $melodySrc->chord_tab_xml;
        $chordXml  = $chordSrc->melody_tab_xml  ?: $chordSrc->chord_tab_xml;

        if (! $melodyXml || ! $chordXml) {
            return response()->json([
                'success' => false,
                'message' => 'Both sources must contain tab notation to merge.',
            ], 422);
        }

        $mother->update([
            'melody_tab_xml' => $melodyXml,
            'chord_tab_xml'  => $chordXml,
        ]);

        // Dual-write the legacy melody column on the work (read fallback), mirroring update().
        if ($mother->id === $leadsheet->default_version_id) {
            $leadsheet->update(['tab_xml' => $melodyXml]);
        }

        return response()->json([
            'success'  => true,
            'message'  => 'Merged into "' . ($mother->label ?: 'Basic') . '".',
            'redirect' => route('admin.leadsheets.edit', [
                'leadsheet' => $leadsheet,
                'v'         => $mother->version_slug,
            ]),
        ]);
    }

    // =========================================================================
    // SONG MERGE (§9.1) — absorb another song's versions as new arrangements
    // on this song, then delete the source song row.
    // =========================================================================

    /**
     * Flat list of all songs (excluding the current one) for the source picker.
     */
    public function mergeSongSourceList(Leadsheet $leadsheet)
    {
        $songs = Leadsheet::where('id', '!=', $leadsheet->id)
            ->orderBy('title')
            ->get(['id', 'slug', 'title', 'status'])
            ->map(fn($s) => [
                'id'            => $s->id,
                'slug'          => $s->slug,
                'title'         => $s->title,
                'status'        => $s->status,
                'versions_count'=> $s->versions()->count(),
                'is_legacy'     => $s->versions()->doesntExist(),
            ]);

        return response()->json(['songs' => $songs]);
    }

    /**
     * Absorb all versions of source song B into this song (A), then delete B.
     *
     * Each of B's versions becomes a new LeadsheetVersion row on A (performer/
     * difficulty/label/all tab+grid data copied verbatim; version_slug de-duped).
     * Detection cache rows (voicing_usage, voicing_drafts, progression_occurrences)
     * are re-pointed to A's id + the new version id — no re-detection pass.
     * B's versions + leadsheet row are then deleted.
     */
    public function mergeSong(Request $request, Leadsheet $leadsheet)
    {
        $validated = $request->validate([
            'source_leadsheet_id' => 'required|integer|exists:sbn_leadsheets,id',
        ]);

        if ((int) $validated['source_leadsheet_id'] === $leadsheet->id) {
            return response()->json(['success' => false, 'message' => 'Source and target are the same song.'], 422);
        }

        $source = Leadsheet::with('versions')->find($validated['source_leadsheet_id']);
        if (! $source) {
            return response()->json(['success' => false, 'message' => 'Source song not found.'], 422);
        }

        // Pre-versions songs have no sbn_leadsheet_versions rows — their data lives
        // directly on sbn_leadsheets. Synthesize a version collection from the row itself
        // so the rest of the logic is uniform.
        $versions = $source->versions->isNotEmpty()
            ? $source->versions
            : collect([(object) [
                'id'               => null,
                'label'            => '',
                'performer'        => null,
                'difficulty'       => 1,
                'sort_order'       => 0,
                'song_key'         => $source->song_key,
                'rhythm'           => $source->rhythm,
                'tempo'            => $source->tempo,
                'measure_count'    => $source->measure_count,
                'json_data'        => $source->json_data,
                'melody_tab_xml'   => $source->tab_xml,
                'chord_tab_xml'    => null,
                'shortcode_content'=> $source->shortcode_content,
                'status'           => $source->status,
            ]]);

        DB::transaction(function () use ($leadsheet, $source, $versions) {
            foreach ($versions as $srcVersion) {
                $slug = LeadsheetVersion::generateUniqueVersionSlug(
                    $leadsheet->id,
                    $srcVersion->label ?: ($srcVersion->performer ?: Str::slug($source->title) ?: 'version')
                );

                $newVersion = LeadsheetVersion::create([
                    'leadsheet_id'      => $leadsheet->id,
                    'version_slug'      => $slug,
                    'label'             => ($srcVersion->label ?? '') ?: $source->title,
                    'performer'         => $srcVersion->performer,
                    'difficulty'        => $srcVersion->difficulty,
                    'sort_order'        => $srcVersion->sort_order,
                    'song_key'          => $srcVersion->song_key,
                    'rhythm'            => $srcVersion->rhythm,
                    'tempo'             => $srcVersion->tempo,
                    'measure_count'     => $srcVersion->measure_count,
                    'json_data'         => $srcVersion->json_data,
                    'melody_tab_xml'    => $srcVersion->melody_tab_xml,
                    'chord_tab_xml'     => $srcVersion->chord_tab_xml,
                    'shortcode_content' => $srcVersion->shortcode_content,
                    'status'            => $srcVersion->status,
                ]);

                // Re-point detection cache rows to this song + new version.
                if ($srcVersion->id) {
                    DB::table('sbn_voicing_usage')
                        ->where('leadsheet_id', $source->id)
                        ->where('version_id', $srcVersion->id)
                        ->update(['leadsheet_id' => $leadsheet->id, 'version_id' => $newVersion->id]);

                    DB::table('sbn_voicing_drafts')
                        ->where('leadsheet_id', $source->id)
                        ->where('version_id', $srcVersion->id)
                        ->update(['leadsheet_id' => $leadsheet->id, 'version_id' => $newVersion->id]);

                    DB::table('sbn_progression_occurrences')
                        ->where('leadsheet_id', $source->id)
                        ->where('version_id', $srcVersion->id)
                        ->update(['leadsheet_id' => $leadsheet->id, 'version_id' => $newVersion->id]);
                }
            }

            // Orphaned cache rows with no version_id (legacy / pre-versions source).
            DB::table('sbn_voicing_usage')
                ->where('leadsheet_id', $source->id)
                ->whereNull('version_id')
                ->update(['leadsheet_id' => $leadsheet->id]);

            DB::table('sbn_voicing_drafts')
                ->where('leadsheet_id', $source->id)
                ->whereNull('version_id')
                ->update(['leadsheet_id' => $leadsheet->id]);

            DB::table('sbn_progression_occurrences')
                ->where('leadsheet_id', $source->id)
                ->whereNull('version_id')
                ->update(['leadsheet_id' => $leadsheet->id]);

            // Delete source versions (if any) then the source leadsheet row.
            $source->versions()->delete();
            $source->delete();
        });

        $count = $versions->count();

        return response()->json([
            'success'  => true,
            'message'  => 'Absorbed "' . $source->title . '" (' . $count . ' ' . Str::plural('arrangement', $count) . ').',
            'redirect' => route('admin.leadsheets.edit', ['leadsheet' => $leadsheet]),
        ]);
    }

    /**
     * Clone an arrangement (version) of this leadsheet.
     * Creates a new version row copying all data; labels it "Copy of {label}".
     * Redirects to the edit page on the new version.
     */
    public function cloneVersion(Request $request, Leadsheet $leadsheet)
    {
        $versionSlug = $request->query('v');
        $source = $versionSlug
            ? $leadsheet->versions()->where('version_slug', $versionSlug)->firstOrFail()
            : ($leadsheet->defaultVersion ?? $leadsheet->versions()->firstOrFail());

        $newLabel = 'Copy of ' . ($source->label ?: 'Basic');
        $newSlug  = LeadsheetVersion::generateUniqueVersionSlug($leadsheet->id, $newLabel);
        $maxSort  = $leadsheet->versions()->max('sort_order') ?? 0;

        $clone = LeadsheetVersion::create([
            'leadsheet_id'      => $leadsheet->id,
            'version_slug'      => $newSlug,
            'label'             => $newLabel,
            'performer'         => $source->performer,
            'difficulty'        => $source->difficulty,
            'sort_order'        => $maxSort + 1,
            'song_key'          => $source->song_key,
            'rhythm'            => $source->rhythm,
            'tempo'             => $source->tempo,
            'measure_count'     => $source->measure_count,
            'json_data'         => $source->json_data,
            'melody_tab_xml'    => $source->melody_tab_xml,
            'chord_tab_xml'     => $source->chord_tab_xml,
            'shortcode_content' => $source->shortcode_content,
            'status'            => 'draft',
        ]);

        return redirect()->route('admin.leadsheets.edit', [
            'leadsheet' => $leadsheet,
            'v'         => $clone->version_slug,
        ])->with('success', 'Cloned arrangement "' . $source->label . '" → "' . $newLabel . '".');
    }

    /**
     * Delete an arrangement (version). Refuses if it is the only version
     * or the leadsheet's default. On success redirects to the default version.
     */
    public function deleteVersion(Request $request, Leadsheet $leadsheet, LeadsheetVersion $version)
    {
        abort_if($version->leadsheet_id !== $leadsheet->id, 404);

        $total = $leadsheet->versions()->count();
        if ($total <= 1) {
            return back()->withErrors(['version' => 'Cannot delete the only arrangement.']);
        }
        if ($leadsheet->default_version_id === $version->id) {
            return back()->withErrors(['version' => 'Cannot delete the default arrangement. Set a different default first.']);
        }

        // Detach detection cache rows (null out version_id so they stay song-level).
        DB::table('sbn_voicing_usage')->where('version_id', $version->id)->update(['version_id' => null]);
        DB::table('sbn_voicing_drafts')->where('version_id', $version->id)->update(['version_id' => null]);
        DB::table('sbn_progression_occurrences')->where('version_id', $version->id)->update(['version_id' => null]);

        $version->delete();

        return redirect()->route('admin.leadsheets.edit', ['leadsheet' => $leadsheet])
            ->with('success', 'Arrangement deleted.');
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

    public function updateCoverImage(Request $request, Leadsheet $leadsheet)
    {
        $validated = $request->validate(['cover_image_path' => 'nullable|string|max:500']);
        $leadsheet->update(['cover_image_path' => $validated['cover_image_path'] ?? null]);
        return response()->json(['success' => true, 'cover_image_path' => $leadsheet->cover_image_path]);
    }

    /**
     * Upload a backing-track or guitar-track audio file for the Cinema
     * with/without-guitar toggle (json_data.backingTrack). Returns the public
     * URL; the frontend writes it into json_data itself on the next save via
     * the normal update() endpoint — this route only handles the binary.
     */
    public function uploadBackingTrack(LeadsheetBackingTrackRequest $request, Leadsheet $leadsheet)
    {
        $file = $request->file('track');
        $uuid = (string) Str::uuid();
        $ext  = $file->getClientOriginalExtension();
        $kind = $request->input('kind');
        $dir  = "audio/backing-tracks/{$leadsheet->id}";
        $name = "{$kind}-{$uuid}.{$ext}";

        $file->move(public_path($dir), $name);

        return response()->json(['success' => true, 'url' => asset("{$dir}/{$name}")]);
    }

    /**
     * Toggle is_pro — the editorial/monetization switch that gates the full
     * Viewer/Cinema arrangement (see SongLibraryController::abortIfNotPro()
     * and SBN-Leadsheet-Reference.md "Content access model"). is_pro must
     * only ever be true on public_domain rows — not DB-enforced, so this is
     * the one place that nudges the admin rather than silently allowing it.
     */
    public function updateIsPro(LeadsheetIsProRequest $request, Leadsheet $leadsheet)
    {
        $leadsheet->update(['is_pro' => $request->validated('is_pro')]);
        return response()->json([
            'success' => true,
            'is_pro' => $leadsheet->is_pro,
            'license_status' => $leadsheet->license_status,
        ]);
    }

    /**
     * Toggle a leadsheet between 'draft' and 'publish'. Drafts are hidden
     * from the public song library (see SongLibraryController).
     */
    public function updateStatus(LeadsheetStatusRequest $request, Leadsheet $leadsheet)
    {
        $leadsheet->update(['status' => $request->validated('status')]);
        return response()->json(['success' => true, 'status' => $leadsheet->status]);
    }

    /**
     * Re-assemble an audio-transcribed leadsheet with a user-chosen downbeat.
     *
     * The original raw Python transcription is cached in json_data.transcriptionRaw
     * on import. This endpoint re-runs assembleTranscription() with the chosen
     * offset — a *tick* shift (0..1919, 480 = 1 quarter) locating the true "1",
     * so an off-beat note can be the downbeat — rebuilds sections / melody /
     * videoSync, and persists. Content before the chosen "1" survives as a
     * leading pickup bar — nothing is lost.
     *
     * Idempotent: always re-shifts from the original cached beats, so the user
     * can re-pick freely. WARNING: full re-assembly discards any manual chord /
     * voicing edits made after import — this is meant as a "do this first" step.
     */
    public function reshiftDownbeat(
        Request $request,
        Leadsheet $leadsheet,
        AnalysisToLeadsheet $converter,
        VoicingCrossref $crossref
    ) {
        // `offset` is the downbeat shift in ticks (480 ticks = 1 quarter, one
        // 4/4 bar = 1920). Sub-beat values let the chosen "1" land exactly on
        // a note quantized to an 8th/16th, not just on a whole beat.
        // Note: reshiftDownbeat does NOT re-run Python, so it never re-runs
        // separation — separate_stem isn't accepted here; transcriptionRaw's
        // cached 'separateStem' flag is simply carried through untouched.
        $validated = $request->validate([
            'offset'    => 'required|integer|min:0|max:1919',
            'bass_snap' => 'nullable|boolean',
            'tab_position_style' => 'nullable|string|in:fretted,open',
            // Set true (by the client, right after reopen-tuning) to re-derive a
            // transcription the user had latched as "fixed". See §13.
            'force'     => 'nullable|boolean',
        ]);

        $parsed = $leadsheet->parsed_data;
        $raw    = $parsed['transcriptionRaw'] ?? null;

        if (empty($raw) || empty($raw['beats'])) {
            return response()->json([
                'success' => false,
                'error'   => 'This leadsheet has no cached audio transcription. The downbeat can only be re-shifted on sheets created via audio transcription after this feature was added.',
            ], 422);
        }

        // Fixed-transcription latch (§13): once the user commits the sheet as the
        // source of truth, re-deriving would silently clobber their edits. Refuse
        // unless the client explicitly forces it (which it does only after the
        // "this discards your edits" confirm has flipped the flag via reopen-tuning).
        if (($parsed['transcriptionFixed'] ?? false) && empty($validated['force'])) {
            return response()->json([
                'success' => false,
                'fixed'   => true,
                'error'   => 'This transcription is fixed. Re-open tuning first — re-deriving will discard edits made since.',
            ], 409);
        }

        // Bass-snap defaults to whatever produced the current assembly so a
        // plain downbeat re-shift doesn't silently turn correction off.
        $bassSnap = array_key_exists('bass_snap', $validated)
            ? !empty($validated['bass_snap'])
            : !empty($raw['bassSnap']);

        // Tab position style: explicit override, else whatever produced the
        // current assembly (cached in transcriptionRaw), else the default.
        $tabStyle = $validated['tab_position_style']
            ?? ($raw['tabPositionStyle'] ?? 'fretted');

        $analysis = $this->assembleTranscription($raw, [
            'title'      => $leadsheet->title,
            'key'        => $leadsheet->song_key ?: 'C',
            'youtube_id' => $raw['videoId'] ?? ($parsed['videoSync']['videoId'] ?? ''),
            'bass_snap'  => $bassSnap,
            'tab_position_style' => $tabStyle,
            // reshiftDownbeat never re-runs Python (no re-separation) — just
            // preserve whatever produced the current assembly for the record.
            'separate_stem' => $raw['separateStem'] ?? true,
            // Carry the stem source through untouched: a reshift re-buckets the
            // cached (stem) notes, so a later Re-run detection must still target
            // the same stem rather than reverting to the full mix.
            'stem_source'   => $raw['stemSource'] ?? null,
        ], (int)$validated['offset'], $crossref);

        $scaffold      = $converter->convert($analysis);
        $jsonDataArray = json_decode($scaffold['json_data'], true);
        $jsonDataArray = $this->carryForwardImportKeys($jsonDataArray, $parsed);

        $leadsheet->update([
            'measure_count'     => $scaffold['measure_count'],
            'shortcode_content' => $scaffold['shortcode_content'],
            'json_data'         => $this->normalizeChordNamesInJson(
                json_encode($jsonDataArray, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ),
            // Audio transcription melody lives in json_data.melody; the tab_xml
            // skeleton is regenerated from scratch on next save.
            'tab_xml'           => null,
        ]);

        return response()->json([
            'success'   => true,
            'offset'    => (int)$validated['offset'],
            'leadsheet' => $this->serializeLeadsheet(
                $leadsheet->fresh(),
                $this->backfillFingersFromCrossref($leadsheet->fresh()->parsed_data)
            ),
        ]);
    }

    /**
     * "Fix transcription" latch (§13). Commits an audio-transcribed leadsheet as
     * the source of truth: sets json_data.transcriptionFixed = true. From then on
     * the re-derive tools (downbeat re-shift, detection tuning) refuse to run
     * without an explicit force, so manual edits can't be silently clobbered.
     *
     * Merges the flag into parsed_data and re-persists json_data verbatim — every
     * other key (transcriptionRaw, sourceAudio, sections, melody, videoSync) is
     * preserved. transcriptionRaw is deliberately KEPT so re-tuning stays possible
     * (via reopenTuning + force), just gated. Idempotent.
     */
    public function fixTranscription(Request $request, Leadsheet $leadsheet)
    {
        return $this->setTranscriptionFixed($leadsheet, true);
    }

    /**
     * Unlatch (§13): set transcriptionFixed = false so the re-derive tools work
     * again. The editor calls this only after the user confirms "this discards my
     * edits", then immediately re-runs the re-derive with force=true.
     */
    public function reopenTuning(Request $request, Leadsheet $leadsheet)
    {
        return $this->setTranscriptionFixed($leadsheet, false);
    }

    /**
     * Shared writer for the transcriptionFixed latch. Uses the same
     * read-parsed_data → set key → json_encode → update idiom as sourceAudio
     * persistence, so all other json_data keys survive untouched.
     */
    private function setTranscriptionFixed(Leadsheet $leadsheet, bool $fixed): \Illuminate\Http\JsonResponse
    {
        $parsed = $leadsheet->parsed_data;

        if (empty($parsed['transcriptionRaw'])) {
            return response()->json([
                'success' => false,
                'error'   => 'Only audio-transcribed leadsheets can be fixed / re-opened.',
            ], 422);
        }

        $parsed['transcriptionFixed'] = $fixed;
        $leadsheet->update([
            'json_data' => json_encode($parsed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        return response()->json([
            'success' => true,
            'transcriptionFixed' => $fixed,
        ]);
    }

    /**
     * T9 Tier-1 — live detection re-tune (post-filter only, no re-inference).
     *
     * Re-derives the chord-region buckets + melody from the cached full note set
     * with a new min-note-length / MIDI-range filter, WITHOUT re-running Python.
     * Structurally identical to reshiftDownbeat (cached raw → assembleTranscription
     * → convert → persist → return fresh leadsheet), reusing the cached downbeat
     * offset / bass-snap / tab-position style so only the filter changes.
     *
     * Onset/frame thresholds are NOT here — they require re-inference (redetect,
     * Tier 2). Inherits the §13 fixed-transcription guard.
     */
    public function retuneDetection(
        Request $request,
        Leadsheet $leadsheet,
        AnalysisToLeadsheet $converter,
        VoicingCrossref $crossref
    ) {
        $validated = $request->validate([
            'min_note_length_ms' => 'nullable|numeric|min:0|max:2000',
            'midi_min'           => 'nullable|integer|min:0|max:127',
            'midi_max'           => 'nullable|integer|min:0|max:127',
            'force'              => 'nullable|boolean',
        ]);

        $parsed = $leadsheet->parsed_data;
        $raw    = $parsed['transcriptionRaw'] ?? null;

        if (empty($raw) || empty($raw['notes']) || empty($raw['beats'])) {
            return response()->json([
                'success' => false,
                'error'   => 'This leadsheet has no cached audio transcription to re-tune.',
            ], 422);
        }

        // §13 fixed-transcription latch.
        if (($parsed['transcriptionFixed'] ?? false) && empty($validated['force'])) {
            return response()->json([
                'success' => false,
                'fixed'   => true,
                'error'   => 'This transcription is fixed. Re-open tuning first — re-tuning will discard edits made since.',
            ], 409);
        }

        // Assemble the filter from provided keys only (empty ⇒ null ⇒ no re-bucket,
        // i.e. back to the pristine Python buckets).
        $filter = array_filter([
            'min_note_length_ms' => $validated['min_note_length_ms'] ?? null,
            'midi_min'           => $validated['midi_min'] ?? null,
            'midi_max'           => $validated['midi_max'] ?? null,
        ], fn($v) => $v !== null);
        $filter = empty($filter) ? null : $filter;

        $analysis = $this->assembleTranscription($raw, [
            'title'      => $leadsheet->title,
            'key'        => $leadsheet->song_key ?: 'C',
            'youtube_id' => $raw['videoId'] ?? ($parsed['videoSync']['videoId'] ?? ''),
            // Preserve everything else that produced the current assembly.
            'bass_snap'          => !empty($raw['bassSnap']),
            'tab_position_style' => $raw['tabPositionStyle'] ?? 'fretted',
            'separate_stem'      => $raw['separateStem'] ?? true,
            'detection_filter'   => $filter,
            // Carry the stem source through untouched (see reshiftDownbeat) so a
            // later Re-run detection still targets the same isolated stem.
            'stem_source'        => $raw['stemSource'] ?? null,
        ], (int)($raw['downbeatOffset'] ?? 0), $crossref);

        $scaffold      = $converter->convert($analysis);
        $jsonDataArray = json_decode($scaffold['json_data'], true);
        $jsonDataArray = $this->carryForwardImportKeys($jsonDataArray, $parsed);

        $leadsheet->update([
            'measure_count'     => $scaffold['measure_count'],
            'shortcode_content' => $scaffold['shortcode_content'],
            'json_data'         => $this->normalizeChordNamesInJson(
                json_encode($jsonDataArray, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ),
            'tab_xml'           => null,
        ]);

        return response()->json([
            'success'   => true,
            'filter'    => $filter,
            'leadsheet' => $this->serializeLeadsheet(
                $leadsheet->fresh(),
                $this->backfillFingersFromCrossref($leadsheet->fresh()->parsed_data)
            ),
        ]);
    }

    /**
     * Shared re-assembly for the re-inference endpoints (redetect / transcribe-stem):
     * take a FRESH raw Python result, run it through assembleTranscription() reusing
     * the settings that produced the current assembly (downbeat offset / bass-snap /
     * tab-position style), persist, and return the serialized leadsheet. Structurally
     * the same tail as reshiftDownbeat / retuneDetection, but the raw is new (a
     * re-inference) rather than the cached grid.
     *
     * @param array $prevRaw  the leadsheet's existing transcriptionRaw (for settings)
     */
    /**
     * A re-derive scaffold (reshift / retune / re-inference) only carries the
     * assembled grid — it does NOT re-emit the import-only keys. Blindly replacing
     * json_data would drop sourceAudio (written once at import) and backingTrack,
     * which the re-derive toolbar gates on, plus the transcriptionFixed latch.
     * Carry them forward from the existing parsed_data so re-deriving doesn't wipe
     * the toolbar out from under the user.
     */
    private function carryForwardImportKeys(array $jsonDataArray, array $prevParsed): array
    {
        foreach (['sourceAudio', 'backingTrack', 'transcriptionFixed'] as $key) {
            if (array_key_exists($key, $prevParsed)) {
                $jsonDataArray[$key] = $prevParsed[$key];
            }
        }
        return $jsonDataArray;
    }

    private function reassembleFromRawResult(
        Leadsheet $leadsheet,
        array $rawResult,
        array $prevRaw,
        array $separateStem,
        AnalysisToLeadsheet $converter,
        VoicingCrossref $crossref,
        ?array $stemSource = null
    ): array {
        $analysis = $this->assembleTranscription($rawResult, [
            'title'      => $leadsheet->title,
            'key'        => $leadsheet->song_key ?: 'C',
            'youtube_id' => $prevRaw['videoId'] ?? ($leadsheet->parsed_data['videoSync']['videoId'] ?? ''),
            // Reuse the settings that produced the current assembly, so a re-detect
            // only changes what detection surfaced — not bass-snap / hand-position.
            'bass_snap'          => !empty($prevRaw['bassSnap']),
            'tab_position_style' => $prevRaw['tabPositionStyle'] ?? 'fretted',
            'separate_stem'      => $separateStem,
            // Record the isolated-stem source (if this re-derive ran on one) so a
            // later Re-run detection re-inferences on the same stem, not the mix.
            'stem_source'        => $stemSource,
        ], (int)($prevRaw['downbeatOffset'] ?? 0), $crossref);

        $scaffold      = $converter->convert($analysis);
        $jsonDataArray = json_decode($scaffold['json_data'], true);
        $jsonDataArray = $this->carryForwardImportKeys($jsonDataArray, $leadsheet->parsed_data ?? []);

        $leadsheet->update([
            'measure_count'     => $scaffold['measure_count'],
            'shortcode_content' => $scaffold['shortcode_content'],
            'json_data'         => $this->normalizeChordNamesInJson(
                json_encode($jsonDataArray, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ),
            'tab_xml'           => null,
        ]);

        return $this->serializeLeadsheet(
            $leadsheet->fresh(),
            $this->backfillFingersFromCrossref($leadsheet->fresh()->parsed_data)
        );
    }

    /**
     * T9 Tier-2 — re-detect (re-inference). Re-runs basic-pitch on the RESIDENT
     * source audio (the persisted blend original, §12b) with new onset/frame
     * thresholds — the knobs that live *inside* predict() and can't be re-tuned
     * post-hoc like Tier 1. No re-download / re-YouTube / re-separate; reuses the
     * cached bass-snap / tab-position style / downbeat offset. Inherits §13's guard.
     *
     * 422 if the sheet has no persisted sourceAudio (pre-§12b imports).
     */
    public function redetect(
        Request $request,
        Leadsheet $leadsheet,
        AnalysisToLeadsheet $converter,
        VoicingCrossref $crossref,
        \App\Services\MidiTranscriptionService $transcriber
    ) {
        $validated = $request->validate([
            'detection_preset'      => 'nullable|string|in:balanced,sensitive,strict,custom',
            'onset_threshold'       => 'nullable|numeric|min:0.05|max:0.95',
            'frame_threshold'       => 'nullable|numeric|min:0.05|max:0.95',
            'minimum_note_length'   => 'nullable|numeric|min:10|max:500',
            'restrict_guitar_range' => 'nullable|boolean',
            'force'                 => 'nullable|boolean',
        ]);

        $parsed = $leadsheet->parsed_data;
        $raw    = $parsed['transcriptionRaw'] ?? null;

        if (empty($raw)) {
            return response()->json([
                'success' => false,
                'error'   => 'This leadsheet has no cached audio transcription to re-detect.',
            ], 422);
        }

        // §13 fixed-transcription latch.
        if (($parsed['transcriptionFixed'] ?? false) && empty($validated['force'])) {
            return response()->json([
                'success' => false,
                'fixed'   => true,
                'error'   => 'This transcription is fixed. Re-open tuning first — re-detecting will discard edits made since.',
            ], 409);
        }

        // basic-pitch knobs from preset + overrides (same resolver as import).
        $detectionParams = $this->resolveDetectionParams($validated);
        // Re-inference never re-separates: either the persisted original is already
        // the (possibly stem-blended) source, or we re-mix an existing audition
        // session below. So drop any separation flag from the resolved params.
        unset($detectionParams['separate_stem']);

        // If the current transcription came from "Transcribe this stem", keep
        // re-detecting on THAT isolated stem — otherwise the user's stem choice is
        // silently thrown away and detection reverts to the muddy full mix. Falls
        // back to the original recording if the audition session has been swept.
        $stemSource = $raw['stemSource'] ?? null;
        $usedStem   = null;
        if ($stemSource && !empty($stemSource['session'])
            && is_dir($transcriber->stemSessionDir($stemSource['session']))) {
            $stems = $stemSource['stems'] ?? ['guitar'];
            $rawResult = $transcriber->transcribeStemFromSession(
                $stemSource['session'], $stems, $detectionParams
            );
            if ($rawResult['success'] ?? false) {
                $usedStem = $stemSource; // record it again for the next re-detect
            }
            // On failure fall through to the original recording below.
        }

        if (!isset($rawResult) || !($rawResult['success'] ?? false)) {
            // Resolve the persisted original recording (Tier 2 needs it — cached
            // notes can't surface below the original detection floor; only
            // re-inference can). Also the fallback when a stem session has expired.
            $srcUrl  = $parsed['sourceAudio']['url'] ?? null;
            $srcPath = $srcUrl ? public_path(parse_url($srcUrl, PHP_URL_PATH)) : null;
            if (!$srcPath || !is_file($srcPath)) {
                return response()->json([
                    'success' => false,
                    'error'   => 'Re-detection needs the original recording, which wasn\'t saved for this sheet (imported before that feature). Re-import to use it.',
                ], 422);
            }
            $rawResult = $transcriber->transcribeResidentAudio($srcPath, $detectionParams);
            $usedStem  = null; // reverted to full mix — clear any stale stem source
        }

        if (!($rawResult['success'] ?? false)) {
            return response()->json([
                'success' => false,
                'error'   => 'Re-detection failed: ' . ($rawResult['error'] ?? 'unknown error'),
            ], 500);
        }

        $leadsheetData = $this->reassembleFromRawResult(
            $leadsheet, $rawResult, $raw,
            $usedStem ? ($usedStem['stems'] ?? true) : ($raw['separateStem'] ?? true),
            $converter, $crossref,
            $usedStem // persist/clear the stem source for the next re-detect
        );

        // Tell the editor which audio this ran on, so it can warn when a re-detect
        // fell back to the full mix because the stem audition session had expired.
        $stemExpired = $stemSource && !$usedStem;

        return response()->json([
            'success'     => true,
            'leadsheet'   => $leadsheetData,
            'source'      => $usedStem ? 'stem' : 'original',
            'stems'       => $usedStem['stems'] ?? null,
            'stemExpired' => $stemExpired,
        ]);
    }

    /**
     * "Transcribe this stem" — re-inference on an isolated audition-session stem
     * (or a sum of the ticked stems) and REPLACE the transcription. The guitar-only
     * stem transcribes far cleaner than the full mix, so this is the re-derive to
     * reach for when the original import was muddied by vocals / piano / drums.
     *
     * Reuses the persisted audition session from the video-sync sidebar (feature
     * 12d) — no re-download / re-separate. Inherits §13's guard.
     */
    public function transcribeStem(
        Request $request,
        Leadsheet $leadsheet,
        AnalysisToLeadsheet $converter,
        VoicingCrossref $crossref,
        \App\Services\MidiTranscriptionService $transcriber
    ) {
        $validated = $request->validate([
            'session'               => 'required|string|max:64',
            'stems'                 => 'nullable|array',
            'stems.*'               => 'string|in:guitar,bass,vocals,drums,piano,other',
            'detection_preset'      => 'nullable|string|in:balanced,sensitive,strict,custom',
            'onset_threshold'       => 'nullable|numeric|min:0.05|max:0.95',
            'frame_threshold'       => 'nullable|numeric|min:0.05|max:0.95',
            'minimum_note_length'   => 'nullable|numeric|min:10|max:500',
            'restrict_guitar_range' => 'nullable|boolean',
            'force'                 => 'nullable|boolean',
        ]);

        $parsed = $leadsheet->parsed_data;
        $raw    = $parsed['transcriptionRaw'] ?? null;

        if (empty($raw)) {
            return response()->json([
                'success' => false,
                'error'   => 'This leadsheet has no cached audio transcription to replace.',
            ], 422);
        }

        // §13 fixed-transcription latch.
        if (($parsed['transcriptionFixed'] ?? false) && empty($validated['force'])) {
            return response()->json([
                'success' => false,
                'fixed'   => true,
                'error'   => 'This transcription is fixed. Re-open tuning first — re-transcribing will discard edits made since.',
            ], 409);
        }

        $stems = array_values(array_intersect(
            $validated['stems'] ?? ['guitar'],
            \App\Services\MidiTranscriptionService::STEM_NAMES
        ));
        if (empty($stems)) $stems = ['guitar'];

        $detectionParams = $this->resolveDetectionParams($validated);
        unset($detectionParams['separate_stem']); // stems are already isolated

        $rawResult = $transcriber->transcribeStemFromSession($validated['session'], $stems, $detectionParams);
        if (!($rawResult['success'] ?? false)) {
            return response()->json([
                'success' => false,
                'error'   => 'Stem transcription failed: ' . ($rawResult['error'] ?? 'unknown error'),
            ], 500);
        }

        $leadsheetData = $this->reassembleFromRawResult(
            $leadsheet, $rawResult, $raw,
            $stems, // the assembly now reflects the stems that were transcribed
            $converter, $crossref,
            // Remember the audition session + stems so a later Re-run detection
            // re-inferences on THIS isolated stem, not the full-mix original.
            ['session' => $validated['session'], 'stems' => $stems]
        );

        return response()->json(['success' => true, 'leadsheet' => $leadsheetData]);
    }

    public function apiShow(Request $request, Leadsheet $leadsheet)
    {
        // Resolve the arrangement being edited (?v=slug, else default version) and
        // overlay its data onto the leadsheet so serializeLeadsheet emits THIS
        // version's chords/tab/shortcode — not the leadsheet's legacy columns.
        $activeVersion = $request->query('v')
            ? $leadsheet->versions()->where('version_slug', $request->query('v'))->first()
            : null;
        $activeVersion ??= $leadsheet->defaultVersion ?? $leadsheet->versions()->first();

        if ($activeVersion) {
            $leadsheet->setAttribute('json_data',         $activeVersion->json_data ?? $leadsheet->json_data);
            $leadsheet->setAttribute('tab_xml',           $activeVersion->melody_tab_xml ?? $leadsheet->tab_xml);
            $leadsheet->setAttribute('chord_tab_xml',     $activeVersion->chord_tab_xml);
            $leadsheet->setAttribute('shortcode_content', $activeVersion->shortcode_content ?? $leadsheet->shortcode_content);
            $leadsheet->setAttribute('song_key',          $activeVersion->song_key ?: $leadsheet->song_key);
            $leadsheet->setAttribute('rhythm',            $activeVersion->rhythm ?: $leadsheet->rhythm);
            $leadsheet->setAttribute('tempo',             $activeVersion->tempo ?: $leadsheet->tempo);
        }

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
        $tuning  = $request->input('tuning', 'standard');
        $harmonicContext = $songKey !== null ? ['song_key' => $songKey] : null;

        $results = $crossref->identifyVoicingsBatch($voicings, $harmonicContext, $tuning);

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
            // For dim7 quality: generate the inversion + dom alias lattice instead of
            // a plain transpose. The DB shapes are archetypes; the symmetry math
            // produces the four correct inversions (named by their own root) and the
            // four rootless dom7(b9) alias readings.
            if ($quality === 'o7') {
                $dimFilter = match ($inversion) {
                    'root', 'inv1', 'inv2', 'inv3' => 'dim',
                    'rootless'                      => 'dom',
                    default                         => 'all',
                };
                $generated = $this->voicingSearch->findDiminishedPickerResults($shapes, $root, $dimFilter);
                // Post-filter by inversion slot when one is selected.
                if (!empty($inversion) && $inversion !== 'all') {
                    $generated = array_values(array_filter(
                        $generated,
                        fn ($r) => ($r['inversion'] ?? '') === $inversion
                    ));
                }
                $results = $generated;
            } else {
                $results = $this->voicingSearch->transposeShapes($shapes, $root, $quality, $bassNote, $slashType);
            }
            $seenIds = array_values(array_filter(array_map(fn ($r) => $r['id'] ?? null, $results)));
        }

        // For dom7(b9) queries: surface all 4 inversion slots of every o7 shape as
        // rootless / inverted dom7(b9) readings. Generated — not hand-stored — so
        // every dim7 shape in the library shows up across all 4 positions.
        // When an inversion filter is active, pass it through and post-filter by
        // the inversion field rather than skipping the call entirely.
        if ($quality === 'dom7' && str_contains($extension, 'b9')) {
            $invFilter     = (!empty($inversion) && $inversion !== 'all') ? $inversion : '';
            $dimDomResults = $this->voicingSearch->findDiminishedDominantMatches($root, $quality, $extension);
            if ($invFilter !== '') {
                $dimDomResults = array_values(array_filter(
                    $dimDomResults,
                    fn ($r) => ($r['inversion'] ?? '') === $invFilter
                ));
            }
            $results = array_merge($results, $dimDomResults);
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
            'filters' => $this->getAvailableFilters($quality, $extension),
        ]);
    }

    private function getAvailableFilters(string $quality, string $extension = ''): array
    {
        $shapes = DB::table('sbn_chord_diagrams')
            ->where('quality', $quality)
            ->get(['voicing_category', 'root_string', 'extensions', 'inversion']);

        // For dim7: inversions are generated (not stored) — return the canonical
        // four-slot list. Also expose dom7(b9) inversion slots so the picker Inv
        // stepper can filter by rootless / 1st / 2nd / 3rd.
        if ($quality === 'o7') {
            return [
                'voicing_categories' => $shapes->pluck('voicing_category')->filter()->unique()->sort()->values()->all(),
                'root_strings'       => $shapes->pluck('root_string')->filter()->unique()->sort()->values()->all(),
                'extensions'         => $shapes->pluck('extensions')->filter()->unique()->sort()->values()->all(),
                'inversions'         => ['root', 'inv1', 'inv2', 'inv3', 'rootless'],
            ];
        }

        // For dom7(b9): add 'rootless' to the inversion list so the Inv stepper
        // can filter to only the generated °7-derived rootless voicings.
        $inversions = $shapes->pluck('inversion')->filter()->unique()->sort()->values()->all();
        if ($quality === 'dom7' && str_contains($extension, 'b9') && ! in_array('rootless', $inversions, true)) {
            $inversions[] = 'rootless';
        }

        return [
            'voicing_categories' => $shapes->pluck('voicing_category')->filter()->unique()->sort()->values()->all(),
            'root_strings'       => $shapes->pluck('root_string')->filter()->unique()->sort()->values()->all(),
            'extensions'         => $shapes->pluck('extensions')->filter()->unique()->sort()->values()->all(),
            'inversions'         => $inversions,
        ];
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    private function validateLeadsheet(Request $request): array
    {
        return $request->validate([
            'title'             => 'required|string|max:255',
            'slug'              => 'nullable|string|max:255|regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
            'composer'          => 'nullable|string|max:255',
            'song_key'          => 'nullable|string|max:10',
            'tempo'             => 'nullable|integer|min:20|max:300',
            'time_signature'    => 'nullable|string|max:10',
            'rhythm'            => 'nullable|string|max:50',
            'course_id'         => 'nullable|integer',
            'shortcode_content' => 'nullable|string',
            'json_data'         => 'nullable|string',
            'tab_xml'           => 'nullable|string',
            'chord_tab_xml'     => 'nullable|string',
            'description'       => 'nullable|string|max:5000',
            'harmony_notes'     => 'nullable|string|max:5000',
            'form_notes'        => 'nullable|string|max:5000',
            'voicing_notes'     => 'nullable|string|max:5000',
            'genre'             => 'nullable|string|max:50',
            'popularity'        => 'nullable|integer|min:0|max:100',
            'difficulty'        => 'nullable|integer|min:0|max:5',
            'version_label'     => 'nullable|string|max:120',
            'version_performer' => 'nullable|string|max:120',
            'arrangement_notes' => 'nullable|string|max:5000',
            'tags'              => 'nullable|string|max:500',
        ]);
    }

    private function syncTags(Leadsheet $leadsheet, array $slugs): void
    {
        $ids = collect($slugs)->map(fn ($s) => SbnTag::findOrCreateBySlug($s)->id)->all();
        $leadsheet->tags()->sync($ids);
    }

    private function parseTagSlugs(?string $raw): array
    {
        if (!$raw) return [];
        return array_values(array_filter(array_map('trim', explode(',', $raw)), fn ($s) => $s !== ''));
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
            'chord_tab_xml'     => $leadsheet->chord_tab_xml ?? null,
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
     * Fill chord voicings for an existing leadsheet using the progression builder.
     *
     * POST /api/admin/leadsheets/{leadsheet}/fill-voicings
     * Body: {
     *   "voicing_style":   "popular"|"shell"|"drop2"|...,  // default "popular"
     *   "extension_mode":  "basic"|"extended",             // default "basic"
     *   "fill_gaps_only":  true|false,                     // default true
     *   "category":        "jazz"|"pop"|...                // optional override
     * }
     *
     * Reads the chord sequence from shortcode_content, pins already-set voicings
     * as seeds when fill_gaps_only=true, runs the builder, then merges the result
     * into json_data.chordVoicings. Tab data (tab_xml, melody) is never touched.
     */
    public function fillVoicings(
        Request $request,
        Leadsheet $leadsheet,
        \App\Services\ProgressionBuilder $builder,
        HarmonicContext $context
    ) {
        $style         = $request->input('voicing_style', 'popular');
        $extensionMode = $request->input('extension_mode', 'basic');
        $fillGapsOnly  = (bool) $request->input('fill_gaps_only', true);
        $categoryOverride = $request->input('category');

        // Parse chord sequence from the stored shortcode
        $song = $this->parser->parse($leadsheet->shortcode_content ?? '');
        if (empty($song['sections'])) {
            return response()->json(['success' => false, 'error' => 'No chord structure found.'], 422);
        }

        // Build flat chord list with global measure index across all sections
        $allChords  = [];
        $globalMIdx = 0;
        foreach ($song['sections'] as $section) {
            foreach ($section['measures'] as $measure) {
                foreach ($measure['chords'] as $chord) {
                    $name = $chord['name'] ?? '';
                    if ($name !== '' && $name !== '?') {
                        // Strip slash-bass for builder lookup (G/D → G); voicing is keyed
                        // under the original name so it lands on the right chord slot.
                        $builderName = strpos($name, '/') !== false
                            ? explode('/', $name)[0]
                            : $name;
                        $allChords[] = [
                            'chord_name'         => $builderName,
                            'original_chord_name' => $name,
                            'measure_index'       => $globalMIdx,
                        ];
                    }
                }
                $globalMIdx++;
            }
        }

        if (empty($allChords)) {
            return response()->json(['success' => false, 'error' => 'No chords found in structure.'], 422);
        }

        // Read current voicings from json_data
        $raw      = DB::table('sbn_leadsheets')->where('id', $leadsheet->id)->value('json_data');
        $jsonData = $raw ? (json_decode($raw, true) ?? []) : [];
        $existing = $jsonData['chordVoicings'] ?? [];

        // Map already-set voicings to slot indices for pinning.
        // A slot is pinned when its chord name has a base (non-override) voicing entry.
        $pinnedVoicings = [];
        if ($fillGapsOnly) {
            foreach ($allChords as $slotIdx => $chord) {
                $name = $chord['original_chord_name'] ?? $chord['chord_name'];
                $v    = $existing[$name] ?? null;
                if ($v && !empty($v['frets'])) {
                    $pinnedVoicings[$slotIdx] = [
                        'frets'      => $v['frets'],
                        'start_fret' => $v['position'] ?? 1,
                        'id'         => $v['diagram_id'] ?? null,
                    ];
                }
            }
        }

        $key      = $song['key'] ?? ($leadsheet->song_key ?? 'C');
        $category = $categoryOverride ?? ($style ?: 'jazz');

        $hc  = $context->buildFromChordSequence($key, $allChords);
        $res = $builder->buildVoicings($hc, [
            'category'             => $category,
            'extensions'           => $extensionMode === 'extended',
            'strict_basic'         => $extensionMode === 'basic',
            'voicing_style'        => 'auto',
            'pinnedVoicings'       => $pinnedVoicings,
            'skip_numeral_upgrade' => true,
        ]);

        // Convert builder output to chordVoicings entries.
        // Only update slots that weren't pinned (or all slots if fill_gaps_only=false).
        $newVoicings = [];
        foreach ($res['selections'] as $slotIdx => $sel) {
            if ($fillGapsOnly && isset($pinnedVoicings[$slotIdx])) continue;
            $v = $sel['voicing'] ?? null;
            if (!$v || empty($v['frets'])) continue;
            // Use the original chord name (with slash bass) as the voicing key
            $name = $allChords[$slotIdx]['original_chord_name'] ?? $sel['chord_name'];
            $newVoicings[$name] = [
                'frets'    => $v['frets'],
                'position' => $v['start_fret'] ?? 1,
                'fingers'  => $v['fingers'] ?? '000000',
            ];
        }

        // Merge: existing (pins) take priority over new only when fill_gaps_only
        $merged = $fillGapsOnly
            ? array_merge($newVoicings, $existing)   // existing wins for set slots
            : array_merge($existing, $newVoicings);  // new wins for all slots

        $jsonData['chordVoicings'] = $merged;

        DB::table('sbn_leadsheets')->where('id', $leadsheet->id)->update([
            'json_data'  => $this->normalizeChordNamesInJson(json_encode($jsonData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'updated_at' => now(),
        ]);

        $crossref = app(VoicingCrossref::class);
        $crossref->processLeadsheet($leadsheet->fresh());

        return response()->json([
            'success'  => true,
            'voicings' => $merged,
            'filled'   => count($newVoicings),
            'pinned'   => count($pinnedVoicings),
        ]);
    }

    /**
     * Apply (or re-apply) a rhythm pattern to an existing leadsheet's tablature.
     *
     * POST /api/admin/leadsheets/{leadsheet}/apply-rhythm
     * Body: {
     *   "rhythm_pattern_id": 12,
     *   "voicing_style":     "jazz",   // used only when filling gaps
     *   "extension_mode":    "basic",
     * }
     *
     * Resolution order per chord slot:
     *   1. Positional voicing key  "name@gi.ci"  (hand-edited)
     *   2. Base-name voicing key   "name"         (filled/imported)
     *   3. Gap-fill via ProgressionBuilder         (same as fillVoicings)
     *
     * Saves new tab_xml + melody + rhythmPattern into json_data. Returns the full
     * updated json_data blob so Alpine can do a sbn-tab-init reload without a
     * second round-trip.
     */
    public function applyRhythm(
        Request $request,
        Leadsheet $leadsheet,
        VoicingMaterializer $materializer,
        \App\Services\ProgressionBuilder $builder,
        HarmonicContext $context
    ) {
        $raw      = DB::table('sbn_leadsheets')->where('id', $leadsheet->id)->value('json_data');
        $jsonData = $raw ? (json_decode($raw, true) ?? []) : [];

        [$jsonData, $result, $pattern, $filledCount] = $this->_applyRhythmCore(
            $request, $jsonData,
            $leadsheet->shortcode_content ?? '',
            $leadsheet->time_signature ?? '4/4',
            $leadsheet->song_key ?? 'C',
            $materializer, $builder, $context
        );

        if ($jsonData instanceof \Illuminate\Http\JsonResponse) return $jsonData;

        DB::table('sbn_leadsheets')->where('id', $leadsheet->id)->update([
            'tab_xml'    => $result['tab_xml'],
            'json_data'  => $this->normalizeChordNamesInJson(json_encode($jsonData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'updated_at' => now(),
        ]);

        app(VoicingCrossref::class)->processLeadsheet($leadsheet->fresh());

        return response()->json([
            'success'        => true,
            'tab_xml'        => $result['tab_xml'],
            'parsed'         => $jsonData,
            'rhythm_pattern' => $pattern->toArray(),
            'filled_gaps'    => $filledCount,
        ]);
    }

    public function applyRhythmToExercise(
        Request $request,
        \App\Models\Exercise $exercise,
        VoicingMaterializer $materializer,
        \App\Services\ProgressionBuilder $builder,
        HarmonicContext $context
    ) {
        $jsonData = $exercise->content_json ?? [];

        [$jsonData, $result, $pattern, $filledCount] = $this->_applyRhythmCore(
            $request, $jsonData,
            $exercise->shortcode_content ?? '',
            $exercise->time_sig ?? '4/4',
            $jsonData['key'] ?? 'C',
            $materializer, $builder, $context
        );

        if ($jsonData instanceof \Illuminate\Http\JsonResponse) return $jsonData;

        DB::table('sbn_exercises')->where('id', $exercise->id)->update([
            'tab_xml'      => $result['tab_xml'],
            'content_json' => json_encode($jsonData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_at'   => now(),
        ]);

        return response()->json([
            'success'        => true,
            'tab_xml'        => $result['tab_xml'],
            'parsed'         => $jsonData,
            'rhythm_pattern' => $pattern->toArray(),
            'filled_gaps'    => $filledCount,
        ]);
    }

    private function _applyRhythmCore(
        Request $request,
        array $jsonData,
        string $shortcode,
        string $timeSignature,
        string $fallbackKey,
        VoicingMaterializer $materializer,
        \App\Services\ProgressionBuilder $builder,
        HarmonicContext $context
    ): array {
        $rhythmPatternSlug = $request->input('rhythm_pattern_slug');
        $style             = $request->input('voicing_style', 'jazz');
        $extensionMode     = $request->input('extension_mode', 'basic');

        $pattern = RhythmPattern::where('slug', $rhythmPatternSlug)->first();
        if (!$pattern) {
            return [response()->json(['success' => false, 'error' => 'Rhythm pattern not found.'], 422), null, null, 0];
        }

        $song = $this->parser->parse($shortcode);
        if (empty($song['sections'])) {
            return [response()->json(['success' => false, 'error' => 'No chord structure found.'], 422), null, null, 0];
        }

        $existing   = $jsonData['chordVoicings'] ?? [];
        $allChords  = [];
        $globalMIdx = 0;
        foreach ($song['sections'] as $section) {
            foreach ($section['measures'] as $measure) {
                foreach ($measure['chords'] as $chord) {
                    $name = $chord['name'] ?? '';
                    if ($name !== '' && $name !== '?') {
                        $builderName = strpos($name, '/') !== false ? explode('/', $name)[0] : $name;
                        $allChords[] = [
                            'chord_name'          => $builderName,
                            'original_chord_name' => $name,
                            'measure_index'       => $globalMIdx,
                        ];
                    }
                }
                $globalMIdx++;
            }
        }

        if (empty($allChords)) {
            return [response()->json(['success' => false, 'error' => 'No chords found in structure.'], 422), null, null, 0];
        }

        $needsFill  = [];
        $selections = [];
        foreach ($allChords as $slotIdx => $chord) {
            $gi   = $chord['measure_index'];
            $name = $chord['original_chord_name'];
            $positional = null;
            for ($ci = 0; $ci <= 3; $ci++) {
                if (isset($existing["{$name}@{$gi}.{$ci}"])) {
                    $positional = $existing["{$name}@{$gi}.{$ci}"];
                    break;
                }
            }
            $voicing = $positional ?? ($existing[$name] ?? null);

            if ($voicing && !empty($voicing['frets'])) {
                $selections[$slotIdx] = [
                    'chord_name'    => $name,
                    'measure_index' => $gi,
                    'frets'         => $voicing['frets'],
                    'position'      => $voicing['position'] ?? $voicing['start_fret'] ?? 1,
                ];
            } else {
                $needsFill[$slotIdx]  = $chord;
                $selections[$slotIdx] = null;
            }
        }

        if (!empty($needsFill)) {
            $key = $song['key'] ?? $fallbackKey;
            $hc  = $context->buildFromChordSequence($key, array_values($needsFill));
            $res = $builder->buildVoicings($hc, [
                'category'             => $style,
                'extensions'           => $extensionMode === 'extended',
                'strict_basic'         => $extensionMode === 'basic',
                'voicing_style'        => 'auto',
                'skip_numeral_upgrade' => true,
            ]);
            $fillIdx = 0;
            foreach (array_keys($needsFill) as $slotIdx) {
                $chord = $needsFill[$slotIdx];
                $sel   = $res['selections'][$fillIdx] ?? null;
                $v     = $sel['voicing'] ?? null;
                if ($v && !empty($v['frets'])) {
                    $selections[$slotIdx] = [
                        'chord_name'    => $chord['original_chord_name'],
                        'measure_index' => $chord['measure_index'],
                        'frets'         => $v['frets'],
                        'position'      => $v['start_fret'] ?? 1,
                    ];
                    $existing[$chord['original_chord_name']] = [
                        'frets'    => $v['frets'],
                        'position' => $v['start_fret'] ?? 1,
                        'fingers'  => $v['fingers'] ?? '000000',
                    ];
                } else {
                    unset($selections[$slotIdx]);
                }
                $fillIdx++;
            }
        }

        $selections = array_values(array_filter($selections));
        if (empty($selections)) {
            return [response()->json(['success' => false, 'error' => 'Could not resolve any voicings.'], 422), null, null, 0];
        }

        $result = $materializer->materialize($selections, $timeSignature, $pattern);

        $jsonData['chordVoicings'] = $existing;
        $jsonData['melody']        = $result['melody'];
        $jsonData['rhythmPattern'] = $pattern->toArray();

        return [$jsonData, $result, $pattern, count($needsFill)];
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
     * Bass-snap beat correction — detect bass-note onsets and use them as
     * metric anchors to rebuild a tempo-following beat_times grid.
     *
     * Rationale: in jazz solo guitar the bass note (thumb) keeps far steadier
     * time than the rubato melody above it. beat_track() drifts on rubato; the
     * bass onsets do not. We don't assume each bass note is a downbeat — a bass
     * note may land on any quarter. Instead each bass onset is snapped to the
     * nearest subdivision of beat_track's *provisional* grid, producing
     * (audioTime → gridTick) anchor pairs. beat_track only needs to be locally
     * accurate to within an 8th note for the snap to pick the right
     * subdivision; anchoring then resets the accumulated drift at every bass
     * note. beat_times is rebuilt by interpolating between anchors.
     *
     * @param array $notes       Raw note events: [{ start, end, pitch }, …]
     * @param array $beatTimes   Provisional beat_track grid (one entry/quarter)
     * @return array  Corrected beat_times, or the original if too few anchors.
     */
    protected function bassSnapBeatTimes(array $notes, array $beatTimes): array
    {
        if (count($beatTimes) < 4 || empty($notes)) {
            return $beatTimes;
        }

        // ── 1. Cluster note onsets; take the lowest pitch per cluster as bass.
        // Onsets within 70 ms are one attack (a strummed chord). The lowest
        // sounding pitch of that attack is the thumb / bass note.
        $sorted = $notes;
        usort($sorted, fn($a, $b) => $a['start'] <=> $b['start']);

        $clusters = [];               // [{ time, pitch }]
        $clusterStart = null;
        $clusterLowPitch = null;
        $clusterLowTime  = null;
        foreach ($sorted as $n) {
            if ($clusterStart === null || ($n['start'] - $clusterStart) > 0.07) {
                if ($clusterStart !== null) {
                    $clusters[] = ['time' => $clusterLowTime, 'pitch' => $clusterLowPitch];
                }
                $clusterStart    = $n['start'];
                $clusterLowPitch = $n['pitch'];
                $clusterLowTime  = $n['start'];
            } elseif ($n['pitch'] < $clusterLowPitch) {
                $clusterLowPitch = $n['pitch'];
                $clusterLowTime  = $n['start'];
            }
        }
        if ($clusterStart !== null) {
            $clusters[] = ['time' => $clusterLowTime, 'pitch' => $clusterLowPitch];
        }

        // ── 2. Drop only clusters whose lowest note is implausibly high — a
        // melody-only moment with no thumb note to anchor on. The lowest note
        // of a cluster already *is* the bass; we keep all of them and let
        // interpolation ride across the dropped (anchorless) gaps. The ceiling
        // is the median cluster pitch + 7 semitones, so a bass-heavy section
        // (all low) keeps every anchor while a pure single-line run is skipped.
        $pitches = array_map(fn($c) => $c['pitch'], $clusters);
        sort($pitches);
        $median = $pitches[(int)floor(count($pitches) / 2)] ?? 60;
        $ceil   = $median + 7;
        $bassOnsets = [];
        foreach ($clusters as $c) {
            if ($c['pitch'] <= $ceil) $bassOnsets[] = $c['time'];
        }
        if (count($bassOnsets) < 3) {
            return $beatTimes;   // not enough bass to anchor — keep beat_track
        }

        // ── 3. Snap each bass onset to the nearest 8th-note subdivision of the
        // provisional grid. The grid index is in *quarter* units; an 8th is 0.5.
        $lastIdx = count($beatTimes) - 1;
        $anchors = [];   // [ gridPos(float, quarter units) => audioTime ]
        foreach ($bassOnsets as $t) {
            if ($t < $beatTimes[0] || $t > $beatTimes[$lastIdx]) continue;

            // Locate the provisional quarter interval containing this onset.
            $lo = 0; $hi = $lastIdx;
            while ($lo < $hi - 1) {
                $mid = (int)(($lo + $hi) / 2);
                if ($beatTimes[$mid] <= $t) $lo = $mid; else $hi = $mid;
            }
            $span = max(1e-4, $beatTimes[$lo + 1] - $beatTimes[$lo]);
            $frac = ($t - $beatTimes[$lo]) / $span;          // 0..1 within quarter
            $gridPos = $lo + (round($frac * 2) / 2);          // snap to 8th
            // Keep the closest onset per grid position (strongest anchor wins
            // ties simply by replacement — order is already time-sorted).
            $anchors[(string)$gridPos] = $t;
        }
        if (count($anchors) < 3) {
            return $beatTimes;
        }

        return $this->buildCorrectedBeatTimes($anchors, $beatTimes);
    }

    /**
     * Rebuild beat_times by piecewise-linear interpolation between bass
     * anchors. Each anchor pins a grid position (in quarter units) to an audio
     * time; quarter positions between/around anchors are interpolated, so the
     * grid follows local tempo and melody-only stretches ride smoothly across
     * the gap between the nearest anchored bass notes.
     *
     * @param array $anchors    [ gridPos(string float) => audioTime ]
     * @param array $fallback   Provisional grid, used to size the output.
     * @return array  One audio time per quarter-note grid index.
     */
    protected function buildCorrectedBeatTimes(array $anchors, array $fallback): array
    {
        // Sorted anchor list of [gridPos => time].
        $pts = [];
        foreach ($anchors as $pos => $time) {
            $pts[] = ['pos' => (float)$pos, 'time' => (float)$time];
        }
        usort($pts, fn($a, $b) => $a['pos'] <=> $b['pos']);

        $count = count($fallback);
        $out   = [];
        for ($q = 0; $q < $count; $q++) {
            // Find the anchor pair bracketing quarter index $q.
            $before = null; $after = null;
            foreach ($pts as $p) {
                if ($p['pos'] <= $q) $before = $p;
                if ($p['pos'] >= $q && $after === null) $after = $p;
            }

            if ($before && $after && $before['pos'] !== $after['pos']) {
                // Interpolate between the two bracketing anchors.
                $t = ($q - $before['pos']) / ($after['pos'] - $before['pos']);
                $out[$q] = $before['time'] + $t * ($after['time'] - $before['time']);
            } elseif ($before && $after && $before['pos'] === $after['pos']) {
                $out[$q] = $before['time'];
            } else {
                // $q lies outside the anchored range — extrapolate at the local
                // tempo of the nearest anchor pair.
                $ref = $before ?: $after;       // the single nearest anchor
                $pair = $before
                    ? [$pts[count($pts) - 2] ?? $pts[0], end($pts)]
                    : [$pts[0], $pts[1] ?? end($pts)];
                $p0 = $pair[0]; $p1 = $pair[1];
                $secPerQuarter = ($p1['pos'] !== $p0['pos'])
                    ? ($p1['time'] - $p0['time']) / ($p1['pos'] - $p0['pos'])
                    : 0.5;
                $out[$q] = $ref['time'] + ($q - $ref['pos']) * $secPerQuarter;
            }
        }

        // Front-edge clamp: extrapolating before the first anchor can run the
        // leading beats to negative audio time (no audio exists there). Clamp
        // any negative entry to 0 — the monotonic pass below then re-spaces the
        // collapsed run into a small non-negative ramp.
        for ($q = 0; $q < $count; $q++) {
            if ($out[$q] < 0) $out[$q] = 0.0;
            else break;
        }

        // Guarantee monotonic non-decreasing times (interpolation can't break
        // this, but extrapolation / front-clamp can collapse leading entries).
        for ($q = 1; $q < $count; $q++) {
            if ($out[$q] <= $out[$q - 1]) {
                $out[$q] = $out[$q - 1] + 0.01;
            }
        }
        return $out;
    }

    /**
     * Re-group note events into per-beat pitch buckets against a (corrected)
     * beat_times grid. Mirrors the bucketing transcribe.py does in Python — it
     * must be re-run in PHP after bass-snap rewrites beat_times, otherwise the
     * chord region detector reads buckets aligned to the stale grid.
     *
     * @param array $notes      Raw note events: [{ start, end, pitch }, …]
     * @param array $beatTimes  Corrected grid, one entry per quarter note.
     * @return array  beats[] in Python's shape: { start, end, notes, note_durations }
     */
    protected function rebucketBeats(array $notes, array $beatTimes, ?array $filter = null): array
    {
        // Default 50 ms floor matches transcribe.py's MIN_NOTE_DURATION. The
        // optional $filter (T9 live detection tuning) overrides it and can add a
        // MIDI range clamp, so the chord-region buckets can be re-derived from the
        // cached full note set without re-running Python. Null $filter ⇒ exactly
        // the original behaviour (regression-safe).
        $minDur   = isset($filter['min_note_length_ms']) ? ((float)$filter['min_note_length_ms'] / 1000.0) : 0.05;
        $midiMin  = isset($filter['midi_min']) ? (int)$filter['midi_min'] : null;
        $midiMax  = isset($filter['midi_max']) ? (int)$filter['midi_max'] : null;
        $count = count($beatTimes);
        $beats = [];

        for ($i = 0; $i < $count; $i++) {
            $startT = $beatTimes[$i];
            $endT   = $i + 1 < $count
                ? $beatTimes[$i + 1]
                : $startT + ($beatTimes[$i] - ($beatTimes[$i - 1] ?? $startT - 0.5));

            // Lowest duration kept per pitch (dedupe within the beat).
            $pitchDur = [];
            foreach ($notes as $n) {
                $dur = $n['end'] - $n['start'];
                if ($n['start'] >= $startT && $n['start'] < $endT && $dur >= $minDur) {
                    $p = (int)$n['pitch'];
                    if ($midiMin !== null && $p < $midiMin) continue;
                    if ($midiMax !== null && $p > $midiMax) continue;
                    if (!isset($pitchDur[$p]) || $dur > $pitchDur[$p]) {
                        $pitchDur[$p] = $dur;
                    }
                }
            }

            $beats[] = [
                'start'          => $startT,
                'end'            => $endT,
                'notes'          => array_map('intval', array_keys($pitchDur)),
                'note_durations' => $pitchDur,
            ];
        }
        return $beats;
    }

    /**
     * Resolve basic-pitch detection knobs from the import modal's inputs.
     *
     * The modal offers a preset (balanced / sensitive / strict) for the common
     * cases plus optional fine-tune sliders. basic-pitch's defaults
     * (onset 0.5, frame 0.3, minNoteLen ~128ms) are tuned for clearly-
     * articulated input and badly under-detect soft / legato / orchestral
     * material — that is what the 'sensitive' preset addresses.
     *
     *   balanced  — basic-pitch defaults; dense, clearly-picked recordings.
     *   sensitive — lower thresholds; recovers soft jazz-guitar / legato runs.
     *   strict    — higher thresholds; rejects reverb tails / false positives.
     *
     * A 'custom' preset (or any slider present) lets explicit values win over
     * the preset baseline. restrict_guitar_range clamps detection to the
     * 6-string range (~E2..~E6), trimming sub-bass rumble and cymbal noise.
     *
     * Also folds in `separate_stem` — a legacy control flag (not a basic-pitch
     * param) for the one-shot path that isolates the guitar stem via Demucs
     * before transcription. Defaults FALSE now that stem separation is an
     * explicit two-phase step in the modal (separate → audition → transcribe);
     * the audition path sends `stem_session` and bypasses this entirely.
     *
     * Returns only the keys the user actually changed from basic-pitch's own
     * defaults (plus separate_stem), so an untouched modal yields
     * ['separate_stem' => false] and Python uses its defaults otherwise.
     */
    protected function resolveDetectionParams(array $validated): array
    {
        $presets = [
            'balanced'  => ['onset_threshold' => 0.5,  'frame_threshold' => 0.3,  'minimum_note_length' => 127.7],
            'sensitive' => ['onset_threshold' => 0.3,  'frame_threshold' => 0.18, 'minimum_note_length' => 58.0],
            'strict'    => ['onset_threshold' => 0.7,  'frame_threshold' => 0.45, 'minimum_note_length' => 160.0],
        ];

        $preset = $validated['detection_preset'] ?? 'balanced';
        $base   = $presets[$preset] ?? $presets['balanced'];

        // Explicit slider values override the preset baseline.
        foreach (['onset_threshold', 'frame_threshold', 'minimum_note_length'] as $k) {
            if (isset($validated[$k]) && $validated[$k] !== '' && $validated[$k] !== null) {
                $base[$k] = (float) $validated[$k];
            }
        }

        // Optional: clamp detection to a 6-string guitar's pitch range.
        // E2 ≈ 82 Hz, two octaves above the 12th-fret high-E ≈ 1320 Hz.
        if (!empty($validated['restrict_guitar_range'])) {
            $base['minimum_frequency'] = 80.0;
            $base['maximum_frequency'] = 1320.0;
        }

        // Drop keys still equal to basic-pitch's defaults so an untouched
        // modal sends nothing and Python keeps its built-in behaviour.
        $defaults = ['onset_threshold' => 0.5, 'frame_threshold' => 0.3, 'minimum_note_length' => 127.7];
        foreach ($defaults as $k => $def) {
            if (isset($base[$k]) && abs($base[$k] - $def) < 1e-9) {
                unset($base[$k]);
            }
        }

        // Stem separation control flag for the LEGACY one-shot path (no
        // audition session). Defaults FALSE now that separation is an explicit
        // opt-in step in the modal: if the admin didn't run "Separate stems",
        // transcribe the raw audio rather than surprise-isolating the guitar.
        // The two-phase workflow never reaches this — it sends stem_session and
        // is dispatched to transcribeFromSession() before detection params are
        // consulted for separation.
        $base['separate_stem'] = array_key_exists('separate_stem', $validated)
            ? !empty($validated['separate_stem'])
            : false;

        return $base;
    }

    /**
     * Assemble a raw Python transcription result into a standard Analysis array.
     *
     * Owns the beat→bar grouping, chord region detection, melody reconstruction
     * and videoSync mapping. Called once on initial import (offset 0) and again
     * by reshiftDownbeat() whenever the user re-picks the downbeat.
     *
     * @param array $rawResult  Raw Python output: beats[], notes[], beat_times[], tempo
     * @param array $opts       title, key, youtube_id, bass_snap, tab_position_style, separate_stem
     * @param int   $downbeatOffset  0..1919 — tick position (relative to the
     *              first busy beat; 480 = 1 quarter) of the true musical "1".
     *              When > 0, the content before it is kept as a leading pickup
     *              bar (a full measure with leading rests), so no content is
     *              lost. Sub-beat values re-PHASE the rhythm onto the grid —
     *              note ticks are re-snapped to the 120-tick lattice after the
     *              shift, they are not left de-quantized.
     */
    protected function assembleTranscription(array $rawResult, array $opts, int $downbeatOffset, \App\Services\VoicingCrossref $crossref): array
    {
        $beatsPerBar = 4;

        // The cached transcriptionRaw must always hold the *original* Python
        // grid + buckets so a re-snap is reproducible. Capture them before the
        // bass-snap step mutates $rawResult.
        $origBeats     = $rawResult['beats'] ?? [];
        $origBeatTimes = $rawResult['beat_times'] ?? [];

        // ── Bass-snap beat correction (opt-in) ──────────────────────────────
        // When bass_snap is on, rebuild beat_times from bass-note anchors and
        // re-bucket beats[] against the new grid so chord region detection
        // stays aligned with the corrected timing.
        $bassSnapped = false;
        if (!empty($opts['bass_snap'])
            && !empty($rawResult['notes'])
            && !empty($rawResult['beat_times'])) {
            $corrected = $this->bassSnapBeatTimes($rawResult['notes'], $rawResult['beat_times']);
            if ($corrected !== $rawResult['beat_times']) {
                $rawResult['beat_times'] = $corrected;
                $rawResult['beats']      = $this->rebucketBeats($rawResult['notes'], $corrected);
                $bassSnapped = true;
            }
        }

        // ── Detection filter (T9 live tuning) — re-bucket chord regions ─────
        // The cached `notes` are the full unfiltered note set, so the chord-region
        // buckets can be re-derived post-hoc with a different min-note-length /
        // MIDI-range filter WITHOUT re-running basic-pitch. Only the post-filter
        // knobs live here; onset/frame thresholds need re-inference (see redetect).
        // Absent ⇒ no re-bucket, exactly the original behaviour.
        $detectionFilter = $opts['detection_filter'] ?? null;
        if (!empty($detectionFilter) && !empty($rawResult['notes']) && !empty($rawResult['beat_times'])) {
            $rawResult['beats'] = $this->rebucketBeats(
                $rawResult['notes'],
                $rawResult['beat_times'],
                $detectionFilter
            );
        }

        $analysis = [
            'title'         => $opts['title'] ?? 'Audio Transcription',
            'composer'      => '',
            'key'           => $opts['key'] ?? 'C',
            'tempo'         => (int)round($rawResult['tempo'] ?? 120),
            'timeSignature' => '4/4',
            'source_note'   => 'AI Audio Transcription from YouTube (ID: ' . ($opts['youtube_id'] ?? '') . ')',
            'sections'      => [],
            'videoSync'     => [
                'videoId'     => $opts['youtube_id'] ?? '',
                'videoType'   => 'youtube',
                'audioSource' => 'video',
                'mappings'    => [],
            ],
            // Cached *original* Python output + the settings that produced this
            // assembly, so the editor's downbeat / bass-snap tools can re-run
            // without re-transcribing. Always the pristine grid, never snapped.
            'transcriptionRaw' => [
                'beats'          => $origBeats,
                'beat_times'     => $origBeatTimes,
                'notes'          => $rawResult['notes'] ?? [],
                'tempo'          => $rawResult['tempo'] ?? 120,
                'downbeatOffset' => $downbeatOffset,
                'bassSnap'       => $bassSnapped,
                'tabPositionStyle' => $opts['tab_position_style'] ?? 'fretted',
                // Whether Demucs guitar-stem separation ran before this
                // transcription. reshiftDownbeat never re-runs Python, so it
                // just carries this flag through for the record — it doesn't
                // re-separate.
                'separateStem' => $opts['separate_stem'] ?? true,
                // If this assembly came from "Transcribe this stem", record which
                // audition session + stems it used so a later Re-run detection
                // re-inferences on the SAME isolated stem (with new onset/frame
                // thresholds) rather than silently reverting to the full-mix
                // original. Null for full-mix / original-recording transcriptions.
                // Carried through untouched by non-stem re-derives via $opts.
                'stemSource' => $opts['stem_source'] ?? null,
                // The basic-pitch knobs that produced this note set. Recorded
                // for the editor (so the user can see what was used and
                // re-import with different values if detection was off).
                'detectionParams' => $rawResult['detection_params'] ?? null,
                // T9 live-tuning post-filter (min-note-length / MIDI range) applied
                // to the cached note set at assembly time. Carried through so a
                // retune / reshift reuses it unless overridden.
                'detectionFilter' => $detectionFilter,
            ],
        ];

        $currentSection = ['label' => 'A', 'bars' => []];
        $tempBar        = ['chords' => []];
        $mappings       = [];

        // Remove leading silence by finding the first bar with musical content.
        $firstBusyBeatIdx = 0;
        foreach ($rawResult['beats'] as $idx => $beat) {
            if (!empty($beat['notes']) || !empty($beat['note_durations'])) {
                $firstBusyBeatIdx = $idx;
                break;
            }
        }
        $skipBars     = (int)floor($firstBusyBeatIdx / $beatsPerBar);
        $startBeatIdx = $skipBars * $beatsPerBar;
        $tickOffset   = $skipBars * $beatsPerBar * 480;

        // Downbeat offset → pickup padding. `$downbeatOffset` is a *tick* shift
        // (480 = 1 quarter, one bar = 1920). The chosen "1" sits `offset` ticks
        // after the first busy beat, so the grid is padded by the complement
        // (`barTicks - offset`) of leading rest-ticks: the chosen point then
        // lands on the downbeat of measure 1 and the pickup content fills the
        // tail of measure 0.
        //
        // The padding splits into two parts:
        //   $padBeats — whole pickup beats; drives bar grouping + chord regions,
        //               so it must stay an integer beat count.
        //   $padFrac  — the sub-beat remainder (0..479 ticks); a constant tick
        //               shift applied to note positions and the videoTime map.
        $barTicks       = $beatsPerBar * 480;
        $downbeatOffset = max(0, min($barTicks - 1, (int)$downbeatOffset));
        $padTicks       = $downbeatOffset > 0 ? ($barTicks - $downbeatOffset) : 0;
        $padBeats       = intdiv($padTicks, 480);
        $padFrac        = $padTicks % 480;

        // ── Non-AI path state: harmonic region grouping (Phase 2c) ──────────
        $regionPitches   = [];
        $regionStartBeat = 1;

        // ── T3: context-aware identifier plumbing ───────────────────────────
        // Each identified region is collected into an ordered sequence and,
        // after the whole grid is assembled, re-ranked as a sequence by the
        // ContextualReranker (key-fit + bigram + Viterbi — the Phase 3 engine,
        // previously only wired into the fret path). Identify-in-isolation stays
        // the first pass; the reranker only shifts *borderline* readings using
        // neighbour context. Each emitted chord entry carries a `_seq` id so its
        // label can be rewritten in place after reranking, then stripped.
        $chordSeq = [];
        $emitChord = function (array &$bar, array $regionPitches, int $beat) use ($crossref, &$chordSeq) {
            $idResult = $crossref->identifyFromMidi($regionPitches);
            if (empty($idResult['name'])) return;
            $seq = count($chordSeq);
            $chordSeq[$seq] = $idResult;
            $bar['chords'][] = ['label' => $idResult['name'], 'beat' => $beat, '_seq' => $seq];
        };

        // `g` is the padded grid index (0-based from the start of the pickup bar).
        for ($i = $startBeatIdx; $i < count($rawResult['beats']); $i++) {
            $beat = $rawResult['beats'][$i];
            $g    = ($i - $startBeatIdx) + $padBeats;

            if ($g % $beatsPerBar === 0) {
                // With a sub-beat downbeat shift the raw beat sits $padFrac
                // ticks *after* the true bar line, so interpolate the bar's
                // audio time backward toward the previous beat by that
                // fraction. ($padFrac === 0 → exact raw-beat time, as before.)
                $videoTime = (float)$beat['start'];
                if ($padFrac > 0 && $i > 0) {
                    $prevStart  = (float)$rawResult['beats'][$i - 1]['start'];
                    $videoTime -= ($beat['start'] - $prevStart) * ($padFrac / 480);
                }
                $mappings[] = [
                    'measureIndex' => (int)($g / $beatsPerBar),
                    'videoTime'    => $videoTime,
                ];
            }

            // Duration-weighted pitch selection (P1 fix)
            $rawPitches = [];
            foreach ($beat['note_durations'] ?? [] as $pitch => $dur) {
                if ($dur >= 0.1) $rawPitches[] = (int)$pitch;
            }
            if (empty($rawPitches)) $rawPitches = $beat['notes'] ?? [];

            $beatNum = ($g % $beatsPerBar) + 1;

            // Deterministic chord ID via harmonic region grouping (Phase 2c).
            // Runs for *both* paths — T4 moved chord identification entirely
            // into the deterministic engine; the AI no longer identifies
            // chords from pitch integers.
            if (empty($rawPitches)) {
                if (!empty($regionPitches)) {
                    $emitChord($tempBar, $regionPitches, $regionStartBeat);
                    $regionPitches = [];
                }
            } else {
                if (empty($regionPitches)) {
                    $regionPitches   = $rawPitches;
                    $regionStartBeat = $beatNum;
                } else {
                    $sim = $this->jaccardSimilarity($regionPitches, $rawPitches);
                    if ($sim >= 0.5) {
                        $regionPitches = array_values(array_unique(array_merge($regionPitches, $rawPitches)));
                    } else {
                        $emitChord($tempBar, $regionPitches, $regionStartBeat);
                        $regionPitches   = $rawPitches;
                        $regionStartBeat = $beatNum;
                    }
                }
            }

            // ── End of bar ──────────────────────────────────────────────────
            if (($g + 1) % $beatsPerBar === 0) {
                if (!empty($regionPitches)) {
                    $emitChord($tempBar, $regionPitches, $regionStartBeat);
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
        if (!empty($regionPitches)) {
            $emitChord($tempBar, $regionPitches, $regionStartBeat);
        }
        if (!empty($tempBar['chords'])) {
            $currentSection['bars'][] = $tempBar;
        }

        if (empty($currentSection['bars'])) {
            $currentSection['bars'][] = ['chords' => [['label' => '/', 'beat' => 1]]];
        }
        $analysis['sections'][] = $currentSection;
        $analysis['videoSync']['mappings'] = $mappings;

        // ── T3: sequence-level context re-identification ────────────────────
        // Re-rank the collected region results as an ordered sequence, then
        // write any reinterpreted labels back into their chord entries. The
        // reranker consumes the same per-slot shape identifyFromMidi already
        // returns (`name`, `candidates`, `pcs`, `bass_note`), so no adaptation
        // is needed. Non-fatal: on any failure the deterministic per-region
        // labels stand.
        $this->applyContextualChordReranking($analysis, $chordSeq, $crossref);

        // ── Reconstruct melody from raw MIDI notes (P0 & P1 fixes) ──────────
        $melody = [];
        if (!empty($rawResult['notes']) && !empty($rawResult['beat_times'])) {
            $ticksPerBar = $beatsPerBar * 480;

            // 1. Filter by guitar range and group by quantized tick (P0).
            //    `+ $padTicks` shifts content into measure 1, leaving the pickup
            //    bar's leading beats empty (rests inserted by the gap pass).
            //
            //    `$padTicks` carries a sub-beat remainder ($padFrac) when the
            //    user picks an off-beat note as the downbeat. A raw fractional
            //    shift would push every note off the 120/240 lattice and break
            //    duration quantization, so after shifting we re-snap to the
            //    grid: the sub-beat pick re-PHASES the rhythm, it doesn't
            //    de-quantize it.
            // Melody range: the guitar bound (MIDI 40–88) is a hard floor/ceiling
            // for the tab; a T9 detection filter can only *narrow* it further, so
            // removing sub-bass / high noise affects the melody as well as chords.
            $melMin = max(40, (int)($detectionFilter['midi_min'] ?? 40));
            $melMax = min(88, (int)($detectionFilter['midi_max'] ?? 88));

            $tickGroups = [];
            foreach ($rawResult['notes'] as $note) {
                if ($note['pitch'] < $melMin || $note['pitch'] > $melMax) continue;

                $rawStart  = $this->timeToTicks($note['start'], $rawResult['beat_times']);
                $startTick = (int)round($rawStart / 240) * 240;
                if (abs($rawStart - $startTick) > 60) {
                    $startTick = (int)round($rawStart / 120) * 120;
                }

                $startTick = $startTick - $tickOffset + $padTicks;
                // Re-snap onto the 120-tick lattice after the (possibly
                // fractional) downbeat shift.
                $startTick = (int)round($startTick / 120) * 120;
                if ($startTick >= 0) {
                    $tickGroups[$startTick][] = $note;
                }
            }

            // 2. Process all notes per tick (Restoring polyphony) and build events
            $sortedTicks = array_keys($tickGroups);
            sort($sortedTicks);

            $tempMelody = [];
            for ($i = 0; $i < count($sortedTicks); $i++) {
                $tick     = $sortedTicks[$i];
                $notes    = $tickGroups[$tick];
                $nextTick = ($i + 1 < count($sortedTicks)) ? $sortedTicks[$i + 1] : null;

                $barIdx  = (int)floor($tick / $ticksPerBar);
                $barEnd  = ($barIdx + 1) * $ticksPerBar;
                $beatEnd = ((int)floor($tick / 480) + 1) * 480;

                $limit = min($barEnd, $beatEnd);

                $availableSpace = $limit - $tick;
                if ($nextTick !== null && $nextTick < $limit) {
                    $availableSpace = $nextTick - $tick;
                }

                foreach ($notes as $note) {
                    $endTick = $this->timeToTicks($note['end'], $rawResult['beat_times']);
                    $endTick = (int)round($endTick / 120) * 120 - $tickOffset + $padTicks;
                    // Re-snap to the 120-tick lattice after the downbeat shift
                    // (matches the start-tick re-snap above).
                    $endTick = (int)round($endTick / 120) * 120;

                    $rawDuration = max(120, $endTick - $tick);

                    $clampedDuration = min($availableSpace, $rawDuration);
                    $quantizedTicks  = $this->quantizeToStandard($clampedDuration);

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
                        // Absolute MIDI pitch — consumed (and removed) by the
                        // T1 fretboard-optimisation pass below.
                        '_midi'        => (int)$note['pitch'],
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

                    if ($gap <= 120 && !empty($melodyWithRests)) {
                        $extended = false;
                        foreach ($melodyWithRests as &$prev) {
                            if (!($prev['isRest'] ?? false) && ($prev['tick'] + $prev['ticks'] == $lastEnd)) {
                                $newTicks  = $prev['ticks'] + $gap;
                                $quantized = $this->quantizeToStandard($newTicks);

                                $pBeatEnd = ((int)floor($prev['tick'] / 480) + 1) * 480;
                                if ($quantized > $prev['ticks'] && ($prev['tick'] + $quantized <= $pBeatEnd)) {
                                    $prev['ticks']    = $quantized;
                                    $prev['duration'] = $this->ticksToDuration($prev['ticks']);
                                    $extended = true;
                                }
                            }
                        }
                        unset($prev);
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

            // Fill up to the end of the last measure (account for pickup padding)
            $totalBeats = (count($rawResult['beats']) - $startBeatIdx) + $padBeats;
            $totalTicks = $totalBeats * 480;
            $gap = $totalTicks - $lastEnd;

            if ($gap > 0 && $gap <= 120 && !empty($melodyWithRests)) {
                foreach ($melodyWithRests as &$prev) {
                    if (!($prev['isRest'] ?? false) && ($prev['tick'] + $prev['ticks'] == $lastEnd)) {
                        $prev['ticks'] += $gap;
                        $prev['duration'] = $this->ticksToDuration($prev['ticks']);
                    }
                }
                unset($prev);
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

        // ── T1: fretboard position optimisation ─────────────────────────────
        // midiToTab() picked each note's string/fret in isolation, which yields
        // physically absurd tab (fret 2 → 14 → 5). Re-assign positions with a
        // Viterbi pass that locks one hand position per bar. The style bias
        // ('fretted' vs 'open') is the user's per-piece choice from the import
        // modal — jazz chord-melody wants fretted positions, classical /
        // fingerstyle wants open strings.
        //
        // Chord-position hints: each bar's identified chord suggests a neck
        // position (its root's low fret). T1 uses this as a soft tiebreak so
        // a G7 bar leans to 3rd position even when an open-position layout is
        // marginally cheaper note-for-note.
        $chordPosHints = $this->chordPositionHints($currentSection['bars']);

        $melody = $this->optimizeTabPositions(
            $melody,
            $opts['tab_position_style'] ?? 'fretted',
            $chordPosHints
        );

        $analysis['melody_data'] = $melody;

        return $analysis;
    }

    /**
     * Per-measure melody position hints for chord-voicing selection.
     *
     * Returns a `measureIndex => meanFret` map describing where the melody's
     * fretting hand sits in each bar. ProgressionBuilder uses it to choose
     * chord voicings in the same neck region as the melody — on a chord-melody
     * piece the chords and melody are one fretting hand, so an open Am next to
     * a melody at the 5th–8th fret is unplayable as written.
     *
     * Only fretted notes count (open strings and rests don't place the hand).
     * A measure with no fretted melody note simply gets no hint; the builder's
     * position-hint term is inert there. Expects post-T1 melody (440-tick grid,
     * 1920 ticks/bar).
     */
    protected function melodyPositionHints(array $melody): array
    {
        $ticksPerBar = 1920;
        $byMeasure   = []; // measureIndex => [frets…]

        foreach ($melody as $note) {
            if (!empty($note['isRest'])) continue;
            $fret = $note['fret'] ?? null;
            if ($fret === null || $fret <= 0) continue; // open string places no hand

            $measure = (int) floor(($note['tick'] ?? 0) / $ticksPerBar);
            $byMeasure[$measure][] = (int) $fret;
        }

        $hints = [];
        foreach ($byMeasure as $measure => $frets) {
            $hints[$measure] = array_sum($frets) / count($frets);
        }
        return $hints;
    }

    /**
     * Per-bar neck-position hint from each bar's identified chord.
     *
     * A chord has a "home position" on the neck — the low fret where its root
     * sits on a bass string. T1 uses this as a *soft* bias so a bar's melody
     * leans toward where the chord is played: a G7 bar nudges toward the 3rd
     * position even when an open-position note layout is marginally cheaper.
     *
     * The position is the root pitch-class's fret on the low-E or A string,
     * whichever lands in a sensible 1–7 window (preferring the lower). For a
     * bar with multiple chords the first is used; a bar with no real chord
     * (`/`, unparseable) gets no hint and is left unbiased.
     *
     * @param array $bars  assembleTranscription()'s per-bar chord list
     *                     ([ ['chords'=>[ ['label'=>…], … ]], … ])
     * @return array  measureIndex => suggested index-finger fret
     */
    protected function chordPositionHints(array $bars): array
    {
        // Root letter (+ accidental) → pitch class.
        $pcOf = [
            'C'=>0,'C#'=>1,'Db'=>1,'D'=>2,'D#'=>3,'Eb'=>3,'E'=>4,'F'=>5,
            'F#'=>6,'Gb'=>6,'G'=>7,'G#'=>8,'Ab'=>8,'A'=>9,'A#'=>10,'Bb'=>10,'B'=>11,
        ];
        $eOpenPc = 4;   // low-E string open pitch class
        $aOpenPc = 9;   // A string open pitch class

        $hints = [];
        foreach ($bars as $idx => $bar) {
            $label = $bar['chords'][0]['label'] ?? '';
            if ($label === '' || $label === '/') continue;

            // Root = first 2 chars if they name a sharp/flat root, else 1 char.
            $root = null;
            if (strlen($label) >= 2 && isset($pcOf[substr($label, 0, 2)])) {
                $root = substr($label, 0, 2);
            } elseif (strlen($label) >= 1 && isset($pcOf[strtoupper(substr($label, 0, 1))])) {
                $root = strtoupper(substr($label, 0, 1));
            }
            if ($root === null) continue;

            $pc      = $pcOf[$root];
            $eFret   = ($pc - $eOpenPc + 12) % 12;   // root fret on low-E
            $aFret   = ($pc - $aOpenPc + 12) % 12;   // root fret on A string
            // Prefer whichever sits low on the neck (1–7); ties → the lower.
            $cands = array_filter([$eFret, $aFret], fn($f) => $f >= 1 && $f <= 7);
            if (empty($cands)) continue;             // root only at open/high — no bias
            $hints[$idx] = min($cands);
        }
        return $hints;
    }

    /**
     * T1 — Fretboard position optimisation (bar-locked).
     *
     * midiToTab() maps each MIDI pitch to a string/fret greedily and in
     * isolation, so a line can leap across the neck for notes a guitarist
     * would play in one hand position. This pass re-derives string/fret so
     * each bar commits to a single hand position.
     *
     * The Viterbi runs over *bars*. Each bar's state is a candidate hand
     * position — the index-finger fret, 0–17, anchoring a 4-fret window
     * `pos..pos+3` (a real fretting hand covers ~4 frets, not the whole neck):
     *  - Node cost : sum over the bar's notes of the cost to play each from
     *                that window. A note picks the (string,fret) that keeps it
     *                *inside* the window; a note that cannot is pulled to its
     *                nearest fret and pays a stiff out-of-window penalty. So
     *                the chosen position is the one that compactly covers the
     *                whole bar — a 1st-position note and a 5th-position note
     *                can no longer both look "free" in one bar.
     *  - Transition: a flat shift cost + fret travel between consecutive bars,
     *                so the hand only relocates at a bar line when needed.
     *
     * `$style` is the user's per-piece bias from the import modal:
     *  - 'fretted' (default) — jazz chord-melody. An open string costs more the
     *    higher the hand sits, so a hand at the 5th position frets E4 at
     *    B-string 5 rather than leaving its grip for the open e.
     *  - 'open' — classical / fingerstyle. Open strings stay nearly free
     *    regardless of hand position, so the optimiser uses them freely.
     *
     * `$chordPosHints` (measureIndex => suggested fret) softly biases each
     * bar's position toward where its chord is played — see chordPositionHints().
     *
     * Rests pass through. The temporary '_midi' key is stripped before return.
     */
    protected function optimizeTabPositions(
        array $melody,
        string $style = 'fretted',
        array $chordPosHints = []
    ): array {
        $stringPitches = [64, 59, 55, 50, 45, 40]; // string 1(e) … 6(E)
        $maxFret       = 17;
        $ticksPerBar   = 1920;
        // A hand position spans 4 frets: index at `pos`, pinky at `pos+3`.
        $handSpan      = 3;
        $preferOpen    = $style === 'open';
        // Per-fret cost of a bar's position differing from its chord hint.
        // Soft tiebreak — tune after eyeballing real imports.
        $chordBiasWeight = 0.18;

        // Candidate (string,fret) positions for a given absolute MIDI pitch.
        $candidates = function (int $midi) use ($stringPitches, $maxFret): array {
            $out = [];
            foreach ($stringPitches as $i => $base) {
                $fret = $midi - $base;
                if ($fret >= 0 && $fret <= $maxFret) {
                    $out[] = ['string' => $i + 1, 'fret' => $fret];
                }
            }
            return $out;
        };

        // Cost (and chosen string/fret) of playing one pitch from a hand
        // position anchored at `pos` (window pos..pos+handSpan). The candidate
        // is chosen to keep the note inside the window; one outside pays a
        // stiff per-fret penalty for how far the hand must stretch/shift.
        //
        // An open string's cost depends on $style. In 'fretted' mode it costs
        // `pos * 0.12` — free at the nut, but more awkward the higher the hand
        // sits, so it stays *cheaper than an out-of-window stretch* yet
        // *dearer than an in-window fretted alternative* (a hand at the 5th
        // position frets E4 at B-string 5 rather than reaching for the open
        // e). In 'open' mode it is a flat near-zero — classical / fingerstyle
        // uses open strings freely regardless of hand position.
        $playFromPos = function (int $midi, int $pos) use ($candidates, $handSpan, $preferOpen): ?array {
            $cands = $candidates($midi);
            if (empty($cands)) return null;
            $best = null;
            foreach ($cands as $c) {
                $fret = $c['fret'];
                if ($fret === 0) {
                    $cost = $preferOpen ? 0.05 : $pos * 0.12;
                } else {
                    // Distance outside the [pos, pos+handSpan] window.
                    $out  = $fret < $pos ? ($pos - $fret)
                          : ($fret > $pos + $handSpan ? $fret - ($pos + $handSpan) : 0);
                    $cost = $fret * 0.05 + $out * 1.5;  // height tiebreak + reach
                }
                if ($best === null || $cost < $best['cost']) {
                    $best = ['string' => $c['string'], 'fret' => $fret, 'cost' => $cost];
                }
            }
            return $best;
        };

        // Group pitched-note indices by bar.
        $bars    = [];   // barIdx => [melody indices]
        $anyNote = false;
        foreach ($melody as $i => $m) {
            if (empty($m['isRest']) && isset($m['_midi'])) {
                $bars[(int)floor(($m['tick'] ?? 0) / $ticksPerBar)][] = $i;
                $anyNote = true;
            }
        }
        if (!$anyNote) {
            foreach ($melody as &$m) { unset($m['_midi']); }
            unset($m);
            return $melody;
        }
        ksort($bars);

        $barKeys   = array_keys($bars);
        $positions = range(0, $maxFret);
        $shiftCost = 0.6;   // flat penalty for relocating between bars

        // Viterbi forward pass over bars. State = hand position (fret 0–maxFret).
        $prevCol = null;    // [ pos => ['cost','back'] ]
        $cols    = [];      // one column per bar
        foreach ($barKeys as $col => $barIdx) {
            $indices = $bars[$barIdx];
            // Suggested neck position for this bar from its chord, if any.
            $hintPos = $chordPosHints[$barIdx] ?? null;

            // Node cost of each candidate position = sum of per-note play costs
            // plus a soft pull toward the bar's chord position (if hinted).
            $nodeCost = [];
            foreach ($positions as $pos) {
                $sum   = 0.0;
                $valid = false;
                foreach ($indices as $mi) {
                    $play = $playFromPos($melody[$mi]['_midi'], $pos);
                    if ($play === null) continue;     // out-of-range note
                    $sum  += $play['cost'];
                    $valid = true;
                }
                if ($valid) {
                    if ($hintPos !== null) {
                        $sum += abs($pos - $hintPos) * $chordBiasWeight;
                    }
                    $nodeCost[$pos] = $sum;
                }
            }
            if (empty($nodeCost)) {                   // whole bar out of range
                $cols[$col] = [];
                $prevCol    = null;
                continue;
            }

            $states = [];
            foreach ($nodeCost as $pos => $nc) {
                if ($prevCol === null) {
                    $states[$pos] = ['cost' => $nc, 'back' => -1];
                    continue;
                }
                $bestCost = PHP_INT_MAX;
                $bestBack = -1;
                foreach ($prevCol as $pPos => $p) {
                    $move = $pPos === $pos ? 0.0 : ($shiftCost + abs($pos - $pPos));
                    $t    = $p['cost'] + $nc + $move;
                    if ($t < $bestCost) {
                        $bestCost = $t;
                        $bestBack = $pPos;
                    }
                }
                $states[$pos] = ['cost' => $bestCost, 'back' => $bestBack];
            }
            $cols[$col] = $states;
            $prevCol    = $states;
        }

        // Backtrace each contiguous chain of decided bar-columns.
        $col = count($barKeys) - 1;
        while ($col >= 0) {
            if (empty($cols[$col])) { $col--; continue; }

            // Cheapest final position in this chain.
            $bestPos  = null;
            $bestCost = PHP_INT_MAX;
            foreach ($cols[$col] as $pos => $s) {
                if ($s['cost'] < $bestCost) { $bestCost = $s['cost']; $bestPos = $pos; }
            }
            while ($col >= 0 && !empty($cols[$col])) {
                $state = $cols[$col][$bestPos];
                foreach ($bars[$barKeys[$col]] as $mi) {
                    $play = $playFromPos($melody[$mi]['_midi'], $bestPos);
                    if ($play !== null) {
                        $melody[$mi]['string'] = $play['string'];
                        $melody[$mi]['fret']   = $play['fret'];
                    }
                    // out-of-range note keeps midiToTab's fallback
                }
                $bestPos = $state['back'];
                $col--;
                if ($bestPos < 0) break;
            }
        }

        foreach ($melody as &$m) { unset($m['_midi']); }
        unset($m);

        return $melody;
    }

    /**
     * T3 — context-aware chord re-identification for the audio path.
     *
     * `assembleTranscription()` identifies each harmonic region *in isolation*
     * via `identifyFromMidi()`. That is a strong first pass but has no notion of
     * key or neighbour chords, so borderline regions (rootless voicings, tritone
     * ambiguity, enharmonic spelling) can read wrong. This runs the whole song's
     * regions through the Phase 3 `ContextualReranker` — the same engine already
     * wired into the fret path (`identifyVoicingsBatch`) — which applies key-fit,
     * bigram transition and Viterbi sequence scoring over the ordered slots and
     * only shifts a reading when sequence evidence outweighs a *near-tied* local
     * winner (its own minScoreRatio guards protect a dominant local reading).
     *
     * `$chordSeq` is the ordered list of the full `identifyFromMidi()` results
     * (index = the `_seq` id stamped onto each emitted chord entry). After
     * reranking, reinterpreted names are written back into the matching chord
     * entries in `$analysis['sections'][*]['bars'][*]['chords'][*]`, and the
     * `_seq` scratch key is stripped from every entry.
     *
     * Non-fatal: any exception leaves the deterministic per-region labels intact.
     *
     * @param array &$analysis   Assembled analysis (mutated in place)
     * @param array  $chordSeq   Ordered identifyFromMidi() results, keyed by _seq
     */
    protected function applyContextualChordReranking(array &$analysis, array $chordSeq, \App\Services\VoicingCrossref $crossref): void
    {
        // Fewer than two chords → no sequence context to exploit. Still strip
        // the scratch key so `_seq` never leaks into persisted json_data.
        if (count($chordSeq) < 2) {
            $this->stripChordSeqKeys($analysis);
            return;
        }

        try {
            $reranker = app(\App\Services\HarmonicContext\ContextualReranker::class);

            // Reindex 0..n-1 (the reranker requires sequential slots) — $chordSeq
            // keys are already dense 0-based, but be defensive.
            $slots = array_values($chordSeq);

            // Audio imports default key to 'C' when none was inferred; pass it
            // through anyway. The key-dependent sub-passes are internally inert
            // on a neutral/absent key, and bigram + Viterbi don't use the key.
            $songKey = $analysis['key'] ?? null;

            $reranked = $reranker->rerank($slots, $songKey);

            // Map _seq → reinterpreted name (only where it actually changed).
            $relabel = [];
            foreach ($reranked as $seq => $slot) {
                $newName = $slot['name'] ?? null;
                $oldName = $chordSeq[$seq]['name'] ?? null;
                if ($newName && $newName !== $oldName) {
                    $relabel[$seq] = $newName;
                }
            }

            if (!empty($relabel)) {
                foreach ($analysis['sections'] as &$section) {
                    foreach ($section['bars'] as &$bar) {
                        foreach ($bar['chords'] as &$chord) {
                            $seq = $chord['_seq'] ?? null;
                            if ($seq !== null && isset($relabel[$seq])) {
                                $chord['label'] = $relabel[$seq];
                            }
                        }
                        unset($chord);
                    }
                    unset($bar);
                }
                unset($section);
            }
        } catch (\Throwable $e) {
            \Log::warning('T3 contextual chord reranking failed; keeping deterministic labels', [
                'error' => $e->getMessage(),
            ]);
        }

        // Always strip the scratch key, whether or not reranking ran/changed anything.
        $this->stripChordSeqKeys($analysis);
    }

    /** Remove the transient `_seq` scratch key from every chord entry. */
    private function stripChordSeqKeys(array &$analysis): void
    {
        if (empty($analysis['sections'])) return;
        foreach ($analysis['sections'] as &$section) {
            if (empty($section['bars'])) continue;
            foreach ($section['bars'] as &$bar) {
                if (empty($bar['chords'])) continue;
                foreach ($bar['chords'] as &$chord) {
                    unset($chord['_seq']);
                }
                unset($chord);
            }
            unset($bar);
        }
        unset($section);
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
