<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ChordProgression;
use App\Models\Leadsheet;
use App\Services\HarmonicContext;
use App\Services\ProgressionBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProgressionBuilderController extends Controller
{
    protected HarmonicContext $context;
    protected ProgressionBuilder $builder;

    public function __construct(HarmonicContext $context, ProgressionBuilder $builder)
    {
        $this->context = $context;
        $this->builder = $builder;
    }

    /**
     * Show the Progression Builder admin page.
     *
     * GET /admin/progressions/builder
     */
    public function index(): \Illuminate\View\View
    {
        $leadsheets = Leadsheet::orderBy('title')->get(['id', 'title', 'key']);

        $progressions = ChordProgression::orderBy('category')->orderBy('name')
            ->get(['id', 'name', 'category', 'numerals', 'tonality']);

        return view('admin.progressions.builder', compact('leadsheets', 'progressions'));
    }

    /**
     * Build voice-led voicings for a chord sequence.
     *
     * Accepts either:
     *   - leadsheet_id: build from an existing leadsheet
     *   - numerals + key: build from a numeral string (e.g. "IIm7,V7,Imaj7")
     *   - chords + key: build from concrete chord names (e.g. ["Dm7","G7","Cmaj7"])
     *
     * POST /api/admin/progressions/build-voicings
     */
    public function buildVoicings(Request $request): JsonResponse
    {
        $style      = $request->get('style') ?? '';
        $extensions = (bool) ($request->get('extensions') ?? false);
        $rootOnly   = (bool) ($request->get('root_only') ?? false);

        $options = [
            'style'      => $style,
            'extensions' => $extensions,
            'rootOnly'   => $rootOnly,
        ];

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
