<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ChordProgression;
use App\Models\Leadsheet;
use App\Services\HarmonicContext;
use App\Services\ProgressionBuilder;
use App\Services\BuilderSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProgressionBuilderController extends Controller
{
    protected HarmonicContext $context;
    protected ProgressionBuilder $builder;
    protected BuilderSettings $settings;

    public function __construct(HarmonicContext $context, ProgressionBuilder $builder, BuilderSettings $settings)
    {
        $this->context = $context;
        $this->builder = $builder;
        $this->settings = $settings;
    }

    /**
     * Show the Progression Builder admin page.
     *
     * GET /admin/progressions/builder
     */
    public function index(): \Illuminate\View\View
    {
        return view('admin.progressions.builder');
    }

    public function getSettings(): JsonResponse
    {
        return response()->json([
            'settings' => $this->settings->all(),
        ]);
    }

    public function updateSetting(Request $request): JsonResponse
    {
        $key = $request->input('key');
        $value = $request->input('value');
        if (!$key) {
            return response()->json(['error' => 'Key is required'], 400);
        }
        
        $this->settings->set($key, $value);
        
        return response()->json(['success' => true]);
    }

    public function getArchetypes(): JsonResponse
    {
        $archetypes = \Illuminate\Support\Facades\DB::table('sbn_builder_archetypes')
            ->orderBy('name')
            ->get(['slug', 'name', 'description']);
            
        return response()->json(['archetypes' => $archetypes]);
    }

    public function saveArchetype(Request $request): JsonResponse
    {
        $name = $request->input('name');
        if (!$name) return response()->json(['error' => 'Name is required'], 400);
        
        $slug = \Illuminate\Support\Str::slug($name);
        $description = $request->input('description');
        
        $this->settings->saveArchetype($slug, $name, $description);
        
        return response()->json(['success' => true, 'slug' => $slug]);
    }

    public function loadArchetype(Request $request): JsonResponse
    {
        $slug = $request->input('slug');
        $archetypeSettings = $this->settings->loadArchetype($slug);
        
        if (!$archetypeSettings) {
            return response()->json(['error' => 'Archetype not found'], 404);
        }
        
        foreach ($archetypeSettings as $key => $value) {
            $this->settings->set($key, $value);
        }
        
        return response()->json(['success' => true, 'settings' => $this->settings->all()]);
    }

    public function restoreDefaults(): JsonResponse
    {
        $this->settings->restoreDefaults();
        return response()->json(['success' => true, 'settings' => $this->settings->all()]);
    }

    public function previewCorpus(Request $request): JsonResponse
    {
        $corpus = [
            ['category' => 'jazz', 'key' => 'C', 'numerals' => 'IIm7,V7,Imaj7', 'name' => 'Jazz II-V-I'],
            ['category' => 'blues', 'key' => 'C', 'numerals' => 'I7,I7,I7,I7,IV7,IV7,I7,I7,V7,IV7,I7,I7', 'name' => '12-Bar Blues'],
            ['category' => 'pop', 'key' => 'G', 'numerals' => 'I,V,VIm,IV', 'name' => 'Pop Axis'],
            ['category' => 'classical', 'key' => 'D', 'numerals' => 'I,V,VIm,IIIm,IV,I,IV,V', 'name' => 'Pachelbel'],
            ['category' => 'modal', 'key' => 'A', 'numerals' => 'Im7,bVII,IV', 'name' => 'Dorian Vamp'],
            ['category' => 'latin', 'key' => 'F', 'numerals' => 'Imaj7,II7,IIm7,V7', 'name' => 'Bossa Turnaround'],
        ];

        $results = [];

        foreach ($corpus as $item) {
            $harmonicContext = $this->context->buildFromNumerals($item['key'], $item['numerals']);
            $options = [
                'category' => $item['category'],
                'extensions' => $this->settings->isPass2Eligible($item['category']),
                'rootOnly' => $this->settings->getRootOnlyDefault($item['category']),
                'voicing_style' => $this->settings->getDefaultVoicingStyle($item['category']),
            ];

            try {
                $buildResult = $this->builder->buildVoicings($harmonicContext, $options);
                $results[] = [
                    'name' => $item['name'],
                    'category' => $item['category'],
                    'key' => $item['key'],
                    'chords' => $buildResult['selections'],
                    'diagnostics' => $buildResult['diagnostics'],
                ];
            } catch (\Exception $e) {
                $results[] = [
                    'name' => $item['name'],
                    'category' => $item['category'],
                    'error' => $e->getMessage(),
                ];
            }
        }

        return response()->json(['corpus' => $results]);
    }

    /**
     * Build voice-led voicings for a chord sequence.
     * (Retained for legacy UI/leadsheet support)
     */
    public function buildVoicings(Request $request): JsonResponse
    {
        $style      = $request->get('style') ?? '';
        $extensions = (bool) ($request->get('extensions') ?? false);
        $rootOnly   = (bool) ($request->get('root_only') ?? false);
        $voicingStyle = $request->get('voicing_style') ?? 'auto';
        $progressionId = $request->get('progression_id');

        $options = [
            'style'      => $style,
            'extensions' => $extensions,
            'rootOnly'   => $rootOnly,
            'voicing_style' => $voicingStyle,
        ];

        // Pass category if progression_id is supplied
        if ($progressionId) {
            $progression = ChordProgression::find($progressionId);
            if ($progression) {
                $options['category'] = $progression->category;
            }
        }

        // Build harmonic context from one of three sources
        $leadsheetId = $request->get('leadsheet_id');
        $numerals    = $request->get('numerals') ?? '';
        $chords      = $request->get('chords');  // array of chord name strings
        $key         = $request->get('key') ?? 'C';

        if ($leadsheetId) {
            $leadsheet = Leadsheet::find($leadsheetId);
            if (!$leadsheet) {
                return response()->json(['error' => 'Leadsheet not found'], 404);
            }
            $harmonicContext = $this->context->buildFromLeadsheet($leadsheet);
        } elseif (!empty($numerals)) {
            $harmonicContext = $this->context->buildFromNumerals($key, $numerals);
        } elseif (!empty($chords) && is_array($chords)) {
            $harmonicContext = $this->context->buildFromChordSequence($key, $chords);
        } else {
            return response()->json(['error' => 'Provide leadsheet_id, numerals, or chords'], 422);
        }

        // Run the builder
        $result = $this->builder->buildVoicings($harmonicContext, $options);

        return response()->json([
            'success' => true,
            'data'    => $result,
            'context' => [
                'key'      => $harmonicContext['song_key'],
                'sections' => count($harmonicContext['sections']),
                'chords'   => array_sum(array_map(
                    fn($s) => count($s['chords']),
                    $harmonicContext['sections']
                )),
            ],
        ]);
    }
}
