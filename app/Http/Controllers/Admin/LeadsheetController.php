<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Leadsheet;
use App\Models\RhythmPattern;
use App\Services\ChordShapeCalculator;
use App\Services\LeadsheetParser;
use App\Services\VoicingCrossref;
use App\Services\ProgressionBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LeadsheetController extends Controller
{
    protected LeadsheetParser $parser;
    protected ChordShapeCalculator $calculator;

    public function __construct(LeadsheetParser $parser, ChordShapeCalculator $calculator)
    {
        $this->parser     = $parser;
        $this->calculator = $calculator;
    }

    // =========================================================================
    // CHORD NAME PARSING (ported from WP sbn_parse_chord_name)
    // =========================================================================

    /**
     * Quality patterns: input notation → DB canonical form.
     * Ordered most-specific-first for greedy matching.
     *
     * Ported from WP chord-search-handler.php sbn_parse_chord_name().
     */
    private const QUALITY_PATTERNS = [
        // Half-diminished
        'm7b5' => 'm7b5', 'm7♭5' => 'm7b5', 'ø7' => 'm7b5', 'ø' => 'm7b5',
        'half-dim7' => 'm7b5', 'halfdim7' => 'm7b5',
        // Minor-major 7
        'mMaj7' => 'mMaj7', 'mmaj7' => 'mMaj7', 'mM7' => 'mMaj7',
        'm(maj7)' => 'mMaj7', 'min(maj7)' => 'mMaj7',
        // Major 7th
        'maj7' => 'maj7', 'M7' => 'maj7', 'Δ7' => 'maj7', '△7' => 'maj7',
        // Major 6th
        'maj6' => 'maj6', 'M6' => 'maj6', '6' => 'maj6',
        // Minor 7th
        'min7' => 'm7', 'm7' => 'm7', '-7' => 'm7',
        // Minor 6th
        'min6' => 'm6', 'm6' => 'm6', '-6' => 'm6',
        // Dominant 7th
        'dom7' => 'dom7', '7' => 'dom7',
        // 7sus4
        '7sus4' => '7sus4',
        // Augmented
        'aug7' => 'aug7', '+7' => 'aug7', 'aug' => 'aug', '+' => 'aug',
        // Diminished 7
        'dim7' => 'o7', 'o7' => 'o7', '°7' => 'o7',
        // Diminished triad
        'dim' => 'dim', 'o' => 'dim', '°' => 'dim',
        // Suspended
        'sus4' => 'sus4', 'sus2' => 'sus2', 'sus' => 'sus4',
        // Add 9
        'add9' => 'add9', '(add9)' => 'add9', 'add2' => 'add9',
        // Power chord
        '5' => '5',
        // Major triad
        'maj' => 'maj', 'M' => 'maj',
        // Minor triad
        'minor' => 'min', 'min' => 'min', 'm' => 'min', '-' => 'min',
    ];

    /**
     * Shorthand extension map: notation → [base_quality, extension].
     * E.g. "9" means dom7 with extension 9, "m9" means m7 with extension 9.
     *
     * Ported from WP chord-search-handler.php sbn_parse_chord_name().
     */
    private const SHORTHAND_MAP = [
        // Dominant extensions
        '9'      => ['dom7', '9'],    '11'     => ['dom7', '11'],   '13'     => ['dom7', '13'],
        'dom9'   => ['dom7', '9'],    'dom11'  => ['dom7', '11'],   'dom13'  => ['dom7', '13'],
        '7b9'    => ['dom7', 'b9'],   '7#9'    => ['dom7', '#9'],
        '7b13'   => ['dom7', 'b13'],  '7#11'   => ['dom7', '#11'],
        '7b9b13' => ['dom7', 'b9,b13'], '7b9#11' => ['dom7', 'b9,#11'],
        // Major extensions
        'maj9'   => ['maj7', '9'],    'maj11'  => ['maj7', '11'],   'maj13'  => ['maj7', '13'],
        'M9'     => ['maj7', '9'],    'M11'    => ['maj7', '11'],   'M13'    => ['maj7', '13'],
        'Δ9'     => ['maj7', '9'],    '△9'     => ['maj7', '9'],
        'maj7#11' => ['maj7', '#11'],
        // Minor extensions
        'm9'     => ['m7', '9'],      'm11'    => ['m7', '11'],     'm13'    => ['m7', '13'],
        'min9'   => ['m7', '9'],      'min11'  => ['m7', '11'],     'min13'  => ['m7', '13'],
        '-9'     => ['m7', '9'],      '-11'    => ['m7', '11'],     '-13'    => ['m7', '13'],
        'm7b9'   => ['m7', 'b9'],
        // Half-dim extensions
        'ø9'     => ['m7b5', '9'],
    ];

    /**
     * Patterns to skip during standard matching (handled by shorthand map instead).
     */
    private const SKIP_PATTERNS = ['maj13','maj11','maj9','m13','m11','m9','13','11','9'];

    /**
     * Parse a chord name into root, quality, extension, bass note.
     *
     * Faithful port of WP sbn_parse_chord_name().
     *
     * @return array|null {root, quality, extension, bass_note}
     */
    private function parseChordName(string $input): ?array
    {
        $input = trim($input);
        if (empty($input)) return null;

        // Handle slash chords: split "Am7/C" → "Am7" + "C"
        $bassNote = '';
        if (str_contains($input, '/')) {
            [$input, $bassPart] = explode('/', $input, 2);
            $input    = trim($input);
            $bassPart = trim($bassPart);

            $validRoots = ['C','C#','Db','D','D#','Eb','E','F','F#','Gb','G','G#','Ab','A','A#','Bb','B'];

            if (strlen($bassPart) >= 2 && in_array(substr($bassPart, 0, 2), $validRoots)) {
                $bassNote = substr($bassPart, 0, 2);
            } elseif (strlen($bassPart) >= 1 && in_array(strtoupper(substr($bassPart, 0, 1)), $validRoots)) {
                $bassNote = strtoupper(substr($bassPart, 0, 1));
            }
        }

        // Extract root note
        $validRoots = ['C','C#','Db','D','D#','Eb','E','F','F#','Gb','G','G#','Ab','A','A#','Bb','B'];
        $root = null;
        $qualityPart = '';

        // Try 2-char root first
        if (strlen($input) >= 2 && in_array(substr($input, 0, 2), $validRoots)) {
            $root = substr($input, 0, 2);
            $qualityPart = substr($input, 2);
        } elseif (strlen($input) >= 1 && in_array(strtoupper(substr($input, 0, 1)), $validRoots)) {
            $root = strtoupper(substr($input, 0, 1));
            $qualityPart = substr($input, 1);
        }

        if (!$root) return null;

        $qualityPart = trim($qualityPart);

        // ── Extension extraction ──
        $extension = '';

        // 1. Parenthesised: "m7(9)", "m7(b9,#11)"
        if (preg_match('/^(.+?)\(([^)]+)\)$/', $qualityPart, $m)) {
            $qualityPart = $m[1];
            $extension   = $m[2];
        }
        // 2. Space-separated: "m7 9", "m7 b9"
        elseif (preg_match('/^(\S+)\s+(.+)$/', $qualityPart, $m)) {
            $qualityPart = $m[1];
            $extension   = trim($m[2]);
        }

        // Empty quality → major triad
        if ($qualityPart === '') {
            return ['root' => $root, 'quality' => 'maj', 'extension' => $extension, 'bass_note' => $bassNote];
        }

        // ── Shorthand check (only if no extension already extracted) ──
        if (empty($extension)) {
            // Case-sensitive
            if (isset(self::SHORTHAND_MAP[$qualityPart])) {
                $sh = self::SHORTHAND_MAP[$qualityPart];
                return ['root' => $root, 'quality' => $sh[0], 'extension' => $sh[1], 'bass_note' => $bassNote];
            }
            // Case-insensitive (skip M/M9/M11/M13 to preserve major vs minor)
            $qpLower = strtolower($qualityPart);
            foreach (self::SHORTHAND_MAP as $pat => $sh) {
                if ($qpLower === strtolower($pat) && !in_array($pat, ['M9','M11','M13'])) {
                    return ['root' => $root, 'quality' => $sh[0], 'extension' => $sh[1], 'bass_note' => $bassNote];
                }
            }
        }

        // ── Standard quality matching ──
        // Case-sensitive
        foreach (self::QUALITY_PATTERNS as $pattern => $canonical) {
            if (in_array($pattern, self::SKIP_PATTERNS)) continue;
            if ($qualityPart === $pattern) {
                return ['root' => $root, 'quality' => $canonical, 'extension' => $extension, 'bass_note' => $bassNote];
            }
        }
        // Case-insensitive
        $qualityLower = strtolower($qualityPart);
        foreach (self::QUALITY_PATTERNS as $pattern => $canonical) {
            if (in_array($pattern, self::SKIP_PATTERNS)) continue;
            if ($qualityLower === strtolower($pattern) && !in_array($pattern, ['M','M7','M6'])) {
                return ['root' => $root, 'quality' => $canonical, 'extension' => $extension, 'bass_note' => $bassNote];
            }
        }

        // ── Progressive decomposition: "m79" → "m7" + "9" ──
        if (empty($extension) && preg_match('/^(.+?)((?:[b#]?\d+,?)+)$/', $qualityPart, $prog)) {
            $baseTry = $prog[1];
            $extTry  = rtrim($prog[2], ',');
            foreach (self::QUALITY_PATTERNS as $pattern => $canonical) {
                if (in_array($pattern, self::SKIP_PATTERNS)) continue;
                if ($baseTry === $pattern || (strtolower($baseTry) === strtolower($pattern) && !in_array($pattern, ['M','M7','M6']))) {
                    return ['root' => $root, 'quality' => $canonical, 'extension' => $extTry, 'bass_note' => $bassNote];
                }
            }
        }

        // Fallback: return raw
        return ['root' => $root, 'quality' => $qualityPart, 'extension' => $extension, 'bass_note' => $bassNote];
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

        return view('admin.leadsheets.index', compact(
            'leadsheets', 'stats', 'keys', 'composers'
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
     *
     * Faithful port of WP sbn_find_and_transpose_shapes():
     *   1. Parse chord name via parseChordName()
     *   2. Route slash chords (inversion vs true slash)
     *   3. Query DB with proper extension + bass_note filtering
     *   4. Transpose each result via ChordShapeCalculator
     */
    public function searchVoicings(Request $request)
    {
        $query = $request->get('query', '');
        if (empty($query)) {
            return response()->json(['success' => true, 'results' => []]);
        }

        // ── Step 1: Parse chord name ──
        $parsed = $this->parseChordName($query);
        if (!$parsed || empty($parsed['root']) || empty($parsed['quality'])) {
            return response()->json(['success' => true, 'results' => []]);
        }

        $root      = $parsed['root'];
        $quality   = $parsed['quality'];
        $extension = $parsed['extension'] ?? '';
        $bassNote  = $parsed['bass_note'] ?? '';

        // ── Step 2: Slash chord routing ──
        $slashType      = '';
        $slashInversion = '';
        $slashInterval  = -1;

        if (!empty($bassNote)) {
            $slashInfo      = $this->calculator->analyzeSlashChord($root, $quality, $bassNote);
            $slashType      = $slashInfo['type'];
            $slashInversion = $slashInfo['inversion'];
            $slashInterval  = $slashInfo['interval'];
        }

        // ── Step 3: Query shapes from DB ──
        // Ported from WP sbn_find_and_transpose_shapes() lines 469-578.
        //
        // Key insight: for standard chords, WP queries WHERE extensions = ''
        // and WHERE bass_note = '' to avoid returning extension/slash variants.
        // SQLite has null vs '' inconsistency, so we use (col = '' OR col IS NULL).

        $emptyOrNull = function ($col) {
            return "($col = '' OR $col IS NULL)";
        };

        if ($slashType === 'slash') {
            // True slash chord: find shapes with bass_note set, matching interval
            $shapes = $this->querySlashShapes($quality, $extension, $slashInterval);

        } elseif ($slashType === 'inversion' && !empty($slashInversion) && $slashInversion !== 'root') {
            // Inversion: prefer shapes tagged with matching inversion
            $shapes = $this->queryInversionShapes($quality, $extension, $slashInversion);

        } else {
            // Standard chord: exclude bass_note shapes
            if (!empty($extension)) {
                // Try with extension filter first
                $shapes = DB::table('sbn_chord_diagrams')
                    ->where('quality', $quality)
                    ->where('extensions', $extension)
                    ->whereRaw("(bass_note = '' OR bass_note IS NULL)")
                    ->orderBy('voicing_category')
                    ->orderBy('root_string')
                    ->orderBy('inversion')
                    ->get();

                // Fallback to base shapes without extension tag
                if ($shapes->isEmpty()) {
                    $shapes = DB::table('sbn_chord_diagrams')
                        ->where('quality', $quality)
                        ->whereRaw("(extensions = '' OR extensions IS NULL)")
                        ->whereRaw("(bass_note = '' OR bass_note IS NULL)")
                        ->orderBy('voicing_category')
                        ->orderBy('root_string')
                        ->orderBy('inversion')
                        ->get();
                }
            } else {
                // No extension: get base shapes only
                $shapes = DB::table('sbn_chord_diagrams')
                    ->where('quality', $quality)
                    ->whereRaw("(extensions = '' OR extensions IS NULL)")
                    ->whereRaw("(bass_note = '' OR bass_note IS NULL)")
                    ->orderBy('voicing_category')
                    ->orderBy('root_string')
                    ->orderBy('inversion')
                    ->get();
            }
        }

        if ($shapes->isEmpty()) {
            return response()->json(['success' => true, 'results' => []]);
        }

        // ── Step 4: Transpose each shape ──
        $results = $this->transposeShapes($shapes, $root, $quality, $bassNote, $slashType);

        return response()->json([
            'success' => true,
            'results' => $results,
        ]);
    }

    /**
     * Advanced voicing search for the Enhanced Voicing Picker (Phase 6a).
     *
     * Accepts explicit filter parameters instead of parsing a chord name string.
     * Returns results sorted by popularity (most-used voicings first).
     *
     * Query params:
     *   - root (required): Root note (C, C#, Db, D, ...)
     *   - quality (required): Chord quality (maj7, dom7, m7, ...)
     *   - extension (optional): Extension filter (9, b9, #11, ...)
     *   - inversion (optional): Inversion filter (root, inv1, inv2, inv3)
     *   - voicing_category (optional): Voicing type (shell, drop2, drop3, open, ...)
     *   - root_string (optional): Root string filter (roota, rootd, roote, ...)
     *   - bass_note (optional): Bass note for slash chords
     */
    public function searchVoicingsAdvanced(Request $request)
    {
        // Note: Laravel's ConvertEmptyStringsToNull middleware converts '' to null,
        // so $request->get('key', '') still returns null when key is present but empty.
        $root     = $request->get('root') ?? '';
        $quality  = $request->get('quality') ?? '';
        $extension      = $request->get('extension') ?? '';
        $inversion      = $request->get('inversion') ?? '';
        $voicingCategory = $request->get('voicing_category') ?? '';
        $rootString     = $request->get('root_string') ?? '';
        $bassNote       = $request->get('bass_note') ?? '';

        if (empty($root) || empty($quality)) {
            return response()->json(['success' => true, 'results' => [], 'filters' => []]);
        }

        // ── Build query for direct matches ──
        $query = DB::table('sbn_chord_diagrams')
            ->where('quality', $quality);

        // Extension filter
        if (!empty($extension)) {
            $query->where('extensions', $extension);
        } else {
            $query->whereRaw("(extensions = '' OR extensions IS NULL)");
        }

        // Inversion filter
        if (!empty($inversion) && $inversion !== 'all') {
            $query->where('inversion', $inversion);
        }

        // Voicing category filter
        if (!empty($voicingCategory) && $voicingCategory !== 'all') {
            $query->where('voicing_category', $voicingCategory);
        }

        // Root string filter
        if (!empty($rootString) && $rootString !== 'all') {
            $query->where('root_string', $rootString);
        }

        // Bass note handling
        if (!empty($bassNote)) {
            $slashInfo = $this->calculator->analyzeSlashChord($root, $quality, $bassNote);
            if ($slashInfo['type'] === 'slash') {
                $query->where(function ($q) {
                    $q->where('bass_note', '!=', '')->whereNotNull('bass_note');
                });
            }
        } else {
            $query->whereRaw("(bass_note = '' OR bass_note IS NULL)");
        }

        // Sort by popularity (most used first), then by voicing category + root string
        $shapes = $query
            ->orderByDesc('popularity')
            ->orderBy('voicing_category')
            ->orderBy('root_string')
            ->orderBy('inversion')
            ->get();

        // ── Transpose direct matches ──
        $slashType = '';
        if (!empty($bassNote)) {
            $slashInfo = $this->calculator->analyzeSlashChord($root, $quality, $bassNote);
            $slashType = $slashInfo['type'];
        }

        $results = [];
        $seenIds = [];

        if ($shapes->isNotEmpty()) {
            $results = $this->transposeShapes($shapes, $root, $quality, $bassNote, $slashType);
            foreach ($results as $i => &$r) {
                $r['popularity'] = $shapes->values()->get($i)->popularity ?? 0;
                $seenIds[] = $r['id'] ?? null;
            }
            unset($r);
        }

        // ── Alias matching: find diagrams whose aliases match the requested quality ──
        // Aliases are stored for a specific alt_root_note (e.g. G7(b9)/B for a Bdim shape).
        // To find voicings for B7(b9)/D#, we match on alt_quality + alt_extensions only,
        // then compute the transposition offset from the alias root to the requested root.
        $aliasQuery = DB::table('sbn_chord_diagram_aliases')
            ->where('alt_quality', $quality);

        if (!empty($extension)) {
            $aliasQuery->where('alt_extensions', $extension);
        } else {
            $aliasQuery->whereRaw("(alt_extensions = '' OR alt_extensions IS NULL)");
        }

        $aliasMatches = $aliasQuery->get();

        if ($aliasMatches->isNotEmpty()) {
            $noteSemitones = ChordShapeCalculator::NOTE_SEMITONES;

            // Get parent diagram IDs, excluding already-found diagrams
            $aliasDiagramIds = $aliasMatches->pluck('diagram_id')->unique()
                ->diff(collect($seenIds)->filter())->values()->all();

            if (!empty($aliasDiagramIds)) {
                $aliasShapesQuery = DB::table('sbn_chord_diagrams')
                    ->whereIn('id', $aliasDiagramIds);

                // Apply voicing category / root string filters to alias results too
                if (!empty($voicingCategory) && $voicingCategory !== 'all') {
                    $aliasShapesQuery->where('voicing_category', $voicingCategory);
                }
                if (!empty($rootString) && $rootString !== 'all') {
                    $aliasShapesQuery->where('root_string', $rootString);
                }

                $aliasShapes = $aliasShapesQuery
                    ->orderByDesc('popularity')
                    ->get();

                if ($aliasShapes->isNotEmpty()) {
                    // Build a lookup: diagram_id → alias info (for correct transposition)
                    $aliasLookup = [];
                    foreach ($aliasMatches as $alias) {
                        $aliasLookup[$alias->diagram_id] = $alias;
                    }

                    foreach ($aliasShapes as $shape) {
                        $alias = $aliasLookup[$shape->id] ?? null;
                        if (!$alias) continue;

                        // Compute transposition: the alias says this shape functions as
                        // alt_root_note's quality. To get the requested root, we need to
                        // transpose by the interval from alt_root_note to requested root.
                        //
                        // Example: shape is Bdim (root_note=B), alias says G7(b9) (alt_root_note=G).
                        // Requested chord: B7(b9). Interval G→B = +4 semitones.
                        // So we need to transpose the shape up 4 semitones.
                        // calculateFrets transposes from shape->root_note to target root.
                        // We need target = shape->root_note + (requested_root - alt_root_note).
                        $altRootSemi = $noteSemitones[$alias->alt_root_note] ?? null;
                        $reqRootSemi = $noteSemitones[$root] ?? null;
                        $shapeRootSemi = $noteSemitones[$shape->root_note] ?? null;

                        if ($altRootSemi === null || $reqRootSemi === null || $shapeRootSemi === null) continue;

                        $offset = (($reqRootSemi - $altRootSemi) + 12) % 12;
                        $targetSemi = ($shapeRootSemi + $offset) % 12;

                        // Find the note name for the transposition target
                        $semitoneToNote = [
                            0 => 'C', 1 => 'Db', 2 => 'D', 3 => 'Eb', 4 => 'E', 5 => 'F',
                            6 => 'F#', 7 => 'G', 8 => 'Ab', 9 => 'A', 10 => 'Bb', 11 => 'B',
                        ];
                        $targetNote = $semitoneToNote[$targetSemi] ?? null;
                        if (!$targetNote) continue;

                        // Temporarily override shape root for transposition
                        $origRoot = $shape->root_note;
                        $calculated = $this->calculator->calculateFrets($shape, $targetNote);

                        if (empty($calculated['diagram_data']) ||
                            (empty($calculated['diagram_data']['positions']) && empty($calculated['diagram_data']['open']))) {
                            continue;
                        }

                        // Build chord name using the requested chord identity
                        $chordName = $root . $quality . ($extension ? "($extension)" : '');
                        if (!empty($bassNote)) {
                            $chordName .= '/' . $bassNote;
                        }

                        $results[] = [
                            'id'               => $shape->id,
                            'name'             => $chordName,
                            'root_note'        => $root,
                            'original_root'    => $shape->root_note,
                            'quality'          => $quality,
                            'extensions'       => $extension,
                            'voicing_category' => $shape->voicing_category,
                            'root_string'      => $shape->root_string,
                            'inversion'        => $shape->inversion ?? 'root',
                            'start_fret'       => $calculated['start_fret'],
                            'diagram_data'     => json_encode($calculated['diagram_data']),
                            'interval_labels'  => $calculated['interval_labels'] ?? ($shape->interval_labels ?? ''),
                            'notes'            => $calculated['notes'] ?? '',
                            'bass_note'        => $bassNote,
                            'popularity'       => $shape->popularity ?? 0,
                            'alias_match'      => true,
                        ];
                    }
                }
            }
        }

        return response()->json([
            'success' => true,
            'results' => $results,
            'filters' => $this->getAvailableFilters($quality),
        ]);
    }

    /**
     * Get available filter options for a given quality.
     * Returns distinct values for voicing_category, root_string, extensions, inversions.
     */
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

    /**
     * Query shapes for true slash chords (foreign bass note).
     * Filters by stored root→bass interval matching the requested interval.
     */
    private function querySlashShapes(string $quality, string $extension, int $targetInterval): \Illuminate\Support\Collection
    {
        $noteSemitones = ChordShapeCalculator::NOTE_SEMITONES;

        $baseQuery = DB::table('sbn_chord_diagrams')
            ->where('quality', $quality)
            ->where(function ($q) {
                $q->where('bass_note', '!=', '')->whereNotNull('bass_note');
            });

        if (!empty($extension)) {
            $withExt = (clone $baseQuery)->where('extensions', $extension)->get();
            $shapes = $withExt->isNotEmpty() ? $withExt : $baseQuery->get();
        } else {
            $shapes = $baseQuery->get();
        }

        // Filter to shapes whose stored root→bass interval matches
        return $shapes->filter(function ($shape) use ($noteSemitones, $targetInterval) {
            $rootSemi = $noteSemitones[$shape->root_note] ?? null;
            $bassSemi = $noteSemitones[$shape->bass_note] ?? null;
            if ($rootSemi === null || $bassSemi === null) return false;
            return (($bassSemi - $rootSemi + 12) % 12) === $targetInterval;
        });
    }

    /**
     * Query shapes for inversion slash chords.
     * Prefers shapes tagged with the matching inversion.
     */
    private function queryInversionShapes(string $quality, string $extension, string $inversion): \Illuminate\Support\Collection
    {
        // First: try dedicated inversion shapes
        $invQuery = DB::table('sbn_chord_diagrams')
            ->where('quality', $quality)
            ->where('inversion', $inversion)
            ->whereRaw("(bass_note = '' OR bass_note IS NULL)");

        if (!empty($extension)) {
            $withExt = (clone $invQuery)->where('extensions', $extension)->get();
            if ($withExt->isNotEmpty()) return $withExt;

            // Try inversion shapes without extension filter
            $withoutExt = (clone $invQuery)->get();
            if ($withoutExt->isNotEmpty()) return $withoutExt;
        } else {
            $invShapes = (clone $invQuery)
                ->whereRaw("(extensions = '' OR extensions IS NULL)")
                ->get();
            if ($invShapes->isNotEmpty()) return $invShapes;
        }

        // Fallback: no dedicated inversion shapes found — return ALL shapes
        // of this quality (root position + all inversions) so the picker
        // isn't empty. Root-position shapes transposed still produce valid
        // voicings, just not in the specific inversion layout.
        if (!empty($extension)) {
            $fallback = DB::table('sbn_chord_diagrams')
                ->where('quality', $quality)
                ->where('extensions', $extension)
                ->whereRaw("(bass_note = '' OR bass_note IS NULL)")
                ->orderBy('voicing_category')
                ->orderBy('root_string')
                ->orderBy('inversion')
                ->get();

            if ($fallback->isNotEmpty()) return $fallback;
        }

        return DB::table('sbn_chord_diagrams')
            ->where('quality', $quality)
            ->whereRaw("(extensions = '' OR extensions IS NULL)")
            ->whereRaw("(bass_note = '' OR bass_note IS NULL)")
            ->orderBy('voicing_category')
            ->orderBy('root_string')
            ->orderBy('inversion')
            ->get();
    }

    /**
     * Transpose a collection of shapes to a target root and build results array.
     * Ported from WP sbn_find_and_transpose_shapes() transposition loop.
     */
    private function transposeShapes(
        \Illuminate\Support\Collection $shapes,
        string $root,
        string $quality,
        string $bassNote,
        string $slashType
    ): array {
        $results = [];

        // Note maps for computing bass note names on inversions
        $noteToSemitone = [
            'C' => 0, 'C#' => 1, 'Db' => 1, 'D' => 2, 'D#' => 3, 'Eb' => 3,
            'E' => 4, 'F' => 5, 'F#' => 6, 'Gb' => 6, 'G' => 7, 'G#' => 8,
            'Ab' => 8, 'A' => 9, 'A#' => 10, 'Bb' => 10, 'B' => 11,
        ];
        $semitoneToNote = [
            0 => 'C', 1 => 'Db', 2 => 'D', 3 => 'Eb', 4 => 'E', 5 => 'F',
            6 => 'F#', 7 => 'G', 8 => 'Ab', 9 => 'A', 10 => 'Bb', 11 => 'B',
        ];

        // Inversion intervals for computing bass note name
        $invIntervals = [
            'maj7' => ['inv1' => 4, 'inv2' => 7, 'inv3' => 11],
            'maj6' => ['inv1' => 4, 'inv2' => 7, 'inv3' => 9],
            'm7'   => ['inv1' => 3, 'inv2' => 7, 'inv3' => 10],
            'm6'   => ['inv1' => 3, 'inv2' => 7, 'inv3' => 9],
            'dom7' => ['inv1' => 4, 'inv2' => 7, 'inv3' => 10],
            'm7b5' => ['inv1' => 3, 'inv2' => 6, 'inv3' => 10],
            'o7'   => ['inv1' => 3, 'inv2' => 6, 'inv3' => 9],
            'mMaj7'=> ['inv1' => 3, 'inv2' => 7, 'inv3' => 11],
            'aug7' => ['inv1' => 4, 'inv2' => 8, 'inv3' => 10],
            'maj'  => ['inv1' => 4, 'inv2' => 7],
            'min'  => ['inv1' => 3, 'inv2' => 7],
            'aug'  => ['inv1' => 4, 'inv2' => 8],
            'dim'  => ['inv1' => 3, 'inv2' => 6],
            'sus4' => ['inv1' => 5, 'inv2' => 7],
            'sus2' => ['inv1' => 2, 'inv2' => 7],
            'add9' => ['inv1' => 2, 'inv2' => 4, 'inv3' => 7],
        ];

        foreach ($shapes as $shape) {
            // Skip fixed-position shapes when transposing to a different root
            if (!empty($shape->is_fixed_position) && strtolower($shape->root_note ?? '') !== strtolower($root)) {
                continue;
            }

            // Choose transposition method
            if ($slashType === 'slash' && !empty($bassNote) && !empty($shape->bass_note)) {
                $calculated = $this->calculator->calculateFretsWithBass($shape, $root, $bassNote);
            } else {
                $calculated = $this->calculator->calculateFrets($shape, $root);
            }

            if (empty($calculated['diagram_data']) || (empty($calculated['diagram_data']['positions']) && empty($calculated['diagram_data']['open']))) {
                continue;
            }

            // Build chord name and compute bass note for inversions
            $chordName = $root . $shape->quality . ($shape->extensions ?? '');
            $inversion = $shape->inversion ?? 'root';
            $bassNoteName = '';

            if ($slashType === 'slash' && !empty($bassNote)) {
                $bassNoteName = $bassNote;
                $chordName .= '/' . $bassNote;
            } elseif ($inversion !== 'root') {
                $qualKey = $shape->quality;
                if (isset($invIntervals[$qualKey][$inversion], $noteToSemitone[$root])) {
                    $rootSemi = $noteToSemitone[$root];
                    $intSemi  = $invIntervals[$qualKey][$inversion];
                    $bassSemi = ($rootSemi + $intSemi) % 12;
                    $bassNoteName = $semitoneToNote[$bassSemi];
                }
                if ($bassNoteName) {
                    $chordName .= '/' . $bassNoteName;
                }
            }

            $results[] = [
                'id'               => $shape->id,
                'name'             => $chordName,
                'root_note'        => $root,
                'original_root'    => $shape->root_note,
                'quality'          => $shape->quality,
                'extensions'       => $shape->extensions ?? '',
                'voicing_category' => $shape->voicing_category,
                'root_string'      => $shape->root_string,
                'inversion'        => $inversion,
                'start_fret'       => $calculated['start_fret'],
                'diagram_data'     => json_encode($calculated['diagram_data']),
                'interval_labels'  => $calculated['interval_labels'] ?: ($shape->interval_labels ?? ''),
                'notes'            => $calculated['notes'],
                'bass_note'        => $bassNoteName,
            ];
        }

        return $results;
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
    public function applyProgression(Request $request, Leadsheet $leadsheet)
    {
        $selections    = $request->input('selections', []);
        $timeSignature = $request->input('time_signature', '4/4');

        if (empty($selections)) {
            return response()->json(['success' => false, 'error' => 'No selections provided.'], 422);
        }

        [$beats, $beatType] = array_map('intval', explode('/', $timeSignature) + [4, 4]);
        $divisions = 480;
        $tpm       = $divisions * $beats * (4 / $beatType); // ticks per measure

        // ── Build MusicXML ──────────────────────────────────────────────
        // String index in fret string: position 0 = string 1 (low E), position 5 = string 6 (high E)
        // Tab model string numbering: string 1 = high E, string 6 = low E
        // So fret string index i → tab string = 6 - i ... wait, SBN diagram convention:
        // diagIdx 0 = low E = tab string 6; diagIdx 5 = high E = tab string 1
        // tabString = 6 - diagIdx  (same as useChordSync.js)

        $durTicks = (int) $tpm;          // one chord = one whole measure
        $durType  = 'whole';

        $durTicks    = (int) $tpm;   // one chord = one whole measure
        $durType     = 'whole';
        $durName     = 'w';          // melody duration code used by useTabModel

        $measuresXml  = '';
        $chordVoicings = [];
        $melody        = [];         // json_data.melody — consumed by useTabModel on load
        $measureNum    = 1;
        $globalTick    = 0;

        foreach ($selections as $sel) {
            $chordName = $sel['chord_name'] ?? '';
            $frets     = $sel['frets']      ?? null;
            $position  = (int) ($sel['position'] ?? 1);

            $harmonyXml = '<harmony>'
                . '<root><root-step>' . htmlspecialchars(substr($chordName, 0, 1)) . '</root-step></root>'
                . '<kind text="' . htmlspecialchars($chordName) . '">other</kind>'
                . '</harmony>';

            $notesXml = '';

            if ($frets && strlen($frets) === 6) {
                $chordVoicings[$chordName] = ['frets' => $frets, 'position' => $position];

                // diagIdx 0 = low E = tab string 6; tabString = 6 - diagIdx
                $first        = true;
                $isChordNote  = false;
                for ($di = 0; $di < 6; $di++) {
                    $ch = $frets[$di];
                    if ($ch === 'x' || $ch === 'X') continue;
                    $fret      = ctype_digit($ch) ? (int) $ch : hexdec($ch);
                    $tabString = 6 - $di;
                    $chordEl   = $first ? '' : '<chord/>';
                    $first     = false;

                    // MusicXML note
                    $notesXml .= '<note>'
                        . $chordEl
                        . '<pitch><step>E</step><octave>4</octave></pitch>'
                        . '<duration>' . $durTicks . '</duration>'
                        . '<type>' . $durType . '</type>'
                        . '<voice>1</voice><staff>1</staff>'
                        . '<notations><technical>'
                        . '<string>' . $tabString . '</string>'
                        . '<fret>' . $fret . '</fret>'
                        . '</technical></notations>'
                        . '</note>';

                    // melody entry (same shape as MusicXMLParser produces)
                    $melody[] = [
                        'tick'        => $globalTick,
                        'pitch'       => null,
                        'octave'      => null,
                        'duration'    => $durName,
                        'ticks'       => $durTicks,
                        'tieStart'    => false,
                        'tieStop'     => false,
                        'voice'       => 1,
                        'string'      => $tabString,
                        'fret'        => $fret,
                        'isChordNote' => $isChordNote,
                        'isRest'      => false,
                        'beam1'       => null,
                        'beam2'       => null,
                    ];
                    $isChordNote = true; // all notes after the first are chord notes
                }
            }

            if (empty($notesXml)) {
                $notesXml = '<note><rest/><duration>' . $durTicks . '</duration>'
                    . '<type>' . $durType . '</type><voice>1</voice><staff>1</staff></note>';
                $melody[] = [
                    'tick'     => $globalTick,
                    'duration' => $durName,
                    'ticks'    => $durTicks,
                    'voice'    => 1,
                    'isRest'   => true,
                ];
            }

            $attrs = $measureNum === 1
                ? '<attributes>'
                    . '<divisions>' . $divisions . '</divisions>'
                    . '<key><fifths>0</fifths><mode>major</mode></key>'
                    . '<time><beats>' . $beats . '</beats><beat-type>' . $beatType . '</beat-type></time>'
                    . '<staves>1</staves><clef><sign>TAB</sign></clef>'
                    . '</attributes>'
                : '';

            $measuresXml .= '<measure number="' . $measureNum . '">'
                . $attrs . $harmonyXml . $notesXml
                . '</measure>';

            $globalTick += $durTicks;
            $measureNum++;
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<score-partwise version="3.1">'
            . '<part-list><score-part id="P1"><part-name>Guitar</part-name></score-part></part-list>'
            . '<part id="P1">' . $measuresXml . '</part>'
            . '</score-partwise>';


        // ── Persist ─────────────────────────────────────────────────────
        // Read json_data fresh from DB (bypasses Eloquent cast/dirty issues)
        $raw      = DB::table('sbn_leadsheets')->where('id', $leadsheet->id)->value('json_data');
        $jsonData = $raw ? (json_decode($raw, true) ?? []) : [];

        // Merge only chordVoicings — sections/structure untouched
        $existing  = $jsonData['chordVoicings'] ?? [];
        $jsonData['chordVoicings'] = array_merge($existing, $chordVoicings);
        $jsonData['melody'] = $melody;  // useTabModel reads this on editor load

        // Raw DB update — avoids Eloquent double-encoding or stale attribute issues
        DB::table('sbn_leadsheets')->where('id', $leadsheet->id)->update([
            'tab_xml'    => $xml,
            'json_data'  => json_encode($jsonData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_at' => now(),
        ]);


        return response()->json([
            'success'  => true,
            'tab_xml'  => $xml,
            'voicings' => $chordVoicings,
            'measures' => $measureNum - 1,
        ]);
    }
}
