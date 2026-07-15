<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\SerializesLeadsheets;
use App\Http\Controllers\Controller;
use App\Models\Leadsheet;
use App\Models\RhythmPattern;
use App\Services\HarmonicContext;
use App\Services\LeadsheetParser;
use App\Services\VoicingCrossref;
use App\Services\VoicingMaterializer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Applies a rhythm pattern to a leadsheet's or exercise's chord sequence,
 * gap-filling any chord slots that have no set voicing via ProgressionBuilder.
 *
 * Split out of LeadsheetController (2026-07 audit #5) — see
 * docs/SBN-Security-Audit-2026-07-09.md.
 */
class LeadsheetRhythmController extends Controller
{
    use SerializesLeadsheets;

    protected LeadsheetParser $parser;

    public function __construct(LeadsheetParser $parser)
    {
        $this->parser = $parser;
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

}
