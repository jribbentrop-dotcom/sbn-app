<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\AssemblesTranscriptions;
use App\Http\Controllers\Admin\Concerns\SerializesLeadsheets;
use App\Http\Controllers\Controller;
use App\Models\Leadsheet;
use App\Models\LeadsheetVersion;
use App\Models\RhythmPattern;
use App\Models\SbnTag;
use App\Models\JazzStandard;
use App\Models\ChordProgression;
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
    use SerializesLeadsheets;
    use AssemblesTranscriptions;

    protected LeadsheetParser $parser;
    protected ChordVoicingSearch $voicingSearch;

    public function __construct(LeadsheetParser $parser, ChordVoicingSearch $voicingSearch)
    {
        $this->parser        = $parser;
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
    public function uploadBackingTrack(Request $request, Leadsheet $leadsheet)
    {
        $request->validate([
            'track' => ['required', 'file', 'mimes:mp3,wav,m4a,aac,ogg', 'max:20480'],
            'kind'  => ['required', 'string', 'in:backing,guitar'],
        ]);

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
    public function updateIsPro(Request $request, Leadsheet $leadsheet)
    {
        $validated = $request->validate(['is_pro' => 'required|boolean']);
        $leadsheet->update(['is_pro' => $validated['is_pro']]);
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
    public function updateStatus(Request $request, Leadsheet $leadsheet)
    {
        $validated = $request->validate(['status' => 'required|in:draft,publish']);
        $leadsheet->update(['status' => $validated['status']]);
        return response()->json(['success' => true, 'status' => $leadsheet->status]);
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

}
