<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Leadsheet;
use App\Models\RhythmPattern;
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
use App\Services\VoicingMaterializer;
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
        $query = Leadsheet::query();

        if ($search = $request->get('search')) {
            $query->search($search);
        }
        if ($key = $request->get('key')) {
            $query->inKey($key);
        }
        if ($composer = $request->get('composer')) {
            $query->where('composer', $composer);
        }

        $leadsheets = $query->orderBy('title')->paginate(25)->withQueryString();
        $stats      = Leadsheet::getStats();
        $keys       = Leadsheet::getDistinctKeys();
        $composers  = Leadsheet::getDistinctComposers();
        $rhythms    = RhythmPattern::orderBy('category')->orderBy('name')->get();
        $cloneSources = Leadsheet::orderBy('title')->get(['id', 'title', 'composer']);
        $progressions = \App\Models\ChordProgression::orderBy('category')->orderBy('name')->get(['id', 'name', 'category', 'numerals', 'tonality']);

        return view('admin.leadsheets.index', compact(
            'leadsheets', 'stats', 'keys', 'composers', 'rhythms', 'cloneSources', 'progressions'
        ));


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
        ProgressionBuilder $builder
    ) {
        $validated = $request->validate([
            'title'          => 'required|string|max:255',
            'composer'       => 'nullable|string|max:255',
            'song_key'       => 'required|string|max:10',
            'tempo'          => 'required|integer|min:20|max:300',
            'time_signature' => 'required|string|max:10',
            'rhythm'         => 'nullable|string|max:50',
            'bars_per_chord' => 'required|integer|min:1|max:16',
            'source_type'    => 'required|in:free,chordpro,bars,clone,progression',
            'sequence_text'  => 'nullable|string',
            'clone_source_id'=> 'nullable|integer|exists:sbn_leadsheets,id',
            'progression_id' => 'nullable|integer|exists:sbn_chord_progressions,id',
            'build_voicings' => 'nullable|boolean',

            'voicing_style'  => 'nullable|string|in:popular,shell,drop2,drop3,closed,archetype,quartal,custom,closed_triads,spread_triads,slash',
        ]);


        $sequenceText = $validated['sequence_text'] ?? '';
        
        if ($validated['source_type'] === 'progression' && !empty($validated['progression_id'])) {
            $progression = \App\Models\ChordProgression::findOrFail($validated['progression_id']);
            $numerals = array_values(array_filter(array_map('trim', explode(',', $progression->numerals))));
            $parsedSequence = [
                'mode' => 'sequence',
                'items' => $numerals,
                'invalid_count' => 0,
            ];
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

        $key = $validated['song_key'];
        $parsedSequence = $parser->resolveNumerals($parsedSequence, $key);


        $scaffold = $scaffolder->scaffoldFromSequence([
            'title'          => $validated['title'],
            'composer'       => $validated['composer'] ?? '',
            'song_key'       => $validated['song_key'],
            'tempo'          => $validated['tempo'],
            'time_signature' => $validated['time_signature'],
            'rhythm'         => $validated['rhythm'] ?? '',
        ], $parsedSequence, $validated['bars_per_chord']);

        $slug = Leadsheet::generateUniqueSlug($validated['title']);


        $tabXml = null;
        $jsonDataArray = json_decode($scaffold['json_data'], true);

        if (!empty($validated['build_voicings'])) {
            $chordsList = [];
            if ($parsedSequence['mode'] === 'bars') {
                foreach ($parsedSequence['items'] as $barChords) {
                    foreach ($barChords as $c) {
                        if ($c !== '?' && !empty($c)) $chordsList[] = $c;
                    }
                }
            } else {
                foreach ($parsedSequence['items'] as $c) {
                    if ($c !== '?' && !empty($c)) $chordsList[] = $c;
                }
            }

            if (!empty($chordsList)) {
                $style = $validated['voicing_style'] ?? 'popular';

                $category = $style === 'popular' ? '' : $style;
                $selections = $builder->selectVoicingsForSequence($chordsList, $key, ['category' => $category]);

                
                // Clean up selections for materializer (it needs 'chord_name', 'frets', 'position')
                $selectionsClean = [];
                foreach ($selections as $sel) {
                    if ($sel['frets']) {
                        $selectionsClean[] = [
                            'chord_name' => $sel['chord_name'],
                            'frets'      => $sel['frets'],
                            'position'   => $sel['position'] ?? 1,
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
            'json_data'         => json_encode($jsonDataArray, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
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
        \App\Services\SongLookup $lookup,
        \App\Services\RhythmHintMapper $rhythmMapper,
        \App\Services\AnalysisToLeadsheet $converter,
        \App\Services\VoicingMaterializer $materializer,
        \App\Services\ProgressionBuilder $builder,
        \App\Services\MidiTranscriptionService $transcriber,
        \App\Services\VoicingCrossref $crossref
    ) {
        set_time_limit(120);

        $validated = $request->validate([
            'title'         => 'required|string|max:255',
            'artist_hint'   => 'nullable|string|max:255',
            'preferred_key' => 'nullable|string|max:10',
            'version'       => 'nullable|string|in:real_book,original,most_common',
            'build_voicings'=> 'nullable|boolean',
            'voicing_style' => 'nullable|string|in:popular,shell,drop2,archetype',
            'rhythm_override' => 'nullable|string|max:50',
            'mode'          => 'nullable|string|in:quick,assistant,audio',
            'youtube_id'    => 'nullable|string',
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
                'sections'      => []
            ];

            $currentSection = ['label' => 'A', 'bars' => []];
            $beatsPerBar = 4;
            $tempBar = ['chords' => []];

            foreach ($rawResult['beats'] as $i => $beat) {
                $pitches = $beat['notes'];
                if (!empty($pitches)) {
                    $idResult = $crossref->identifyFromMidi($pitches);
                    $tempBar['chords'][] = [
                        'label' => $idResult['name'] ?: '?',
                        'beat'  => ($i % $beatsPerBar) + 1
                    ];
                }

                // If end of bar
                if (($i + 1) % $beatsPerBar === 0) {
                    if (empty($tempBar['chords'])) {
                        $tempBar['chords'][] = ['label' => '/', 'beat' => 1];
                    }
                    $currentSection['bars'][] = $tempBar;
                    $tempBar = ['chords' => []];
                }
            }

            if (!empty($tempBar['chords'])) {
                $currentSection['bars'][] = $tempBar;
            }

            if (empty($currentSection['bars'])) {
                $currentSection['bars'][] = ['chords' => [['label' => '/', 'beat' => 1]]];
            }
            $analysis['sections'][] = $currentSection;
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
            // Flatten chords for selectVoicingsForSequence
            $chordList = [];
            foreach ($analysis['sections'] as $sec) {
                foreach ($sec['bars'] as $bar) {
                    foreach ($bar['chords'] as $c) {
                        if (!empty($c['label']) && $c['label'] !== '?') $chordList[] = $c['label'];
                    }
                }
            }
            if (!empty($chordList)) {
                $style = $validated['voicing_style'] ?? 'popular';
                $category = $style === 'popular' ? '' : $style;
                $selections = $builder->selectVoicingsForSequence($chordList, $analysis['key'], ['category' => $category]);

                $clean = array_values(array_filter(array_map(fn($s) => !empty($s['frets']) ? [
                    'chord_name' => $s['chord_name'],
                    'frets'      => $s['frets'],
                    'position'   => $s['position'] ?? 1,
                ] : null, $selections)));

                if (!empty($clean)) {
                    $materialized = $materializer->materialize($clean, $analysis['timeSignature'], $rhythmModel);
                    $tabXml = $materialized['tab_xml'];
                    $jsonDataArray['chordVoicings'] = $materialized['voicings'];
                    $jsonDataArray['melody'] = $materialized['melody'];
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
            'json_data'         => json_encode($jsonDataArray, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
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
            ->with('lookup_alternatives', $analysis['alternatives'] ?? []);
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
            return response()->json([
                'success'   => true,
                'leadsheet' => $this->serializeLeadsheet($leadsheet, $leadsheet->parsed_data),
            ]);
        }

        $parsed = !empty($leadsheet->shortcode_content)
            ? $this->parser->parse($leadsheet->shortcode_content)
            : null;

        return response()->json([
            'success'   => true,
            'leadsheet' => $this->serializeLeadsheet($leadsheet, $parsed),
        ]);
    }

    public function identifyVoicings(Request $request, VoicingCrossref $crossref)
    {
        $voicings = $request->input('voicings', []);
        if (empty($voicings) || !is_array($voicings)) {
            return response()->json(['success' => true, 'results' => []]);
        }

        $results = $crossref->identifyVoicingsBatch($voicings);

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
            'json_data'  => json_encode($jsonData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
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


}

