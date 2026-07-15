<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\SerializesLeadsheets;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ApplyProgressionRequest;
use App\Http\Requests\Admin\FillVoicingsRequest;
use App\Http\Requests\Admin\RemoveVoicingRequest;
use App\Models\Leadsheet;
use App\Services\ChordShapeCalculator;
use App\Services\ChordVoicingSearch;
use App\Services\HarmonicContext;
use App\Services\LeadsheetParser;
use App\Services\VoicingCrossref;
use App\Services\VoicingMaterializer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Chord voicing search/identification and application to leadsheets: the
 * picker's search endpoints, fret-to-name identification, and applying a
 * chosen progression or gap-filled voicings onto a leadsheet's tab.
 *
 * Split out of LeadsheetController (2026-07 audit #5) — see
 * docs/SBN-Security-Audit-2026-07-09.md.
 */
class LeadsheetVoicingController extends Controller
{
    use SerializesLeadsheets;

    protected LeadsheetParser $parser;
    protected ChordShapeCalculator $calculator;
    protected ChordVoicingSearch $voicingSearch;

    public function __construct(LeadsheetParser $parser, ChordShapeCalculator $calculator, ChordVoicingSearch $voicingSearch)
    {
        $this->parser        = $parser;
        $this->calculator    = $calculator;
        $this->voicingSearch = $voicingSearch;
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
    public function applyProgression(ApplyProgressionRequest $request, Leadsheet $leadsheet, VoicingMaterializer $materializer)
    {
        $selections    = $request->input('selections');
        $timeSignature = $request->input('time_signature', '4/4');

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
        FillVoicingsRequest $request,
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
     * Remove a specific voicing from the leadsheet by chord name and fret string.
     * Called from the leadsheet editor when user deletes a chord diagram.
     */
    public function removeVoicing(RemoveVoicingRequest $request, Leadsheet $leadsheet)
    {
        $validated = $request->validated();

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
