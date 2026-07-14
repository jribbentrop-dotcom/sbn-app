<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ExerciseDescriptionRequest;
use App\Http\Requests\Admin\ExercisePayloadRequest;
use App\Http\Requests\Admin\ExerciseSliceRequest;
use App\Models\Exercise;
use App\Models\Leadsheet;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ExerciseController extends Controller
{
    public function index(): RedirectResponse
    {
        return redirect()->route('admin.leadsheets.index', ['tab' => 'exercises']);
    }

    public function create(): View
    {
        $exercise = new Exercise([
            'key_center' => 'C',
            'time_sig' => '4/4',
            'bpm_default' => 100,
            'type' => 'tab_exercise',
            'content_json' => [
                'sections' => [
                    ['title' => '', 'measures' => []],
                ],
            ],
        ]);
        $rhythms = \App\Models\RhythmPattern::orderBy('category')->orderBy('name')->get();
        $rhythmPatterns = $rhythms->mapWithKeys(fn ($r) => [$r->slug => $r->toPlayerData()]);

        return view('admin.leadsheets.edit', [
            'exercise'       => $exercise,
            'leadsheet'      => null,
            'isExercise'     => true,
            'rhythms'        => $rhythms,
            'rhythmPatterns' => $rhythmPatterns,
        ]);
    }

    public function store(ExercisePayloadRequest $request)
    {
        $data = $request->payload();
        if (empty($data['slug'])) {
            $data['slug'] = Str::slug($data['title']);
        }

        $exercise = Exercise::create($data);

        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'id' => $exercise->id, 'message' => 'Exercise created.']);
        }

        return redirect()->route('admin.exercises.edit', $exercise)
            ->with('success', 'Exercise created.');
    }

    public function edit(Exercise $exercise): View
    {
        $rhythms = \App\Models\RhythmPattern::orderBy('category')->orderBy('name')->get();
        $rhythmPatterns = $rhythms->mapWithKeys(fn ($r) => [$r->slug => $r->toPlayerData()]);

        return view('admin.leadsheets.edit', [
            'exercise'       => $exercise,
            'leadsheet'      => null,
            'isExercise'     => true,
            'rhythms'        => $rhythms,
            'rhythmPatterns' => $rhythmPatterns,
        ]);
    }

    public function update(ExercisePayloadRequest $request, Exercise $exercise)
    {
        $data = $request->payload();
        $exercise->update($data);

        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'id' => $exercise->id, 'message' => 'Exercise updated.']);
        }

        return redirect()->route('admin.exercises.edit', $exercise)
            ->with('success', 'Exercise saved.');
    }

    public function destroy(Request $request, Exercise $exercise)
    {
        $exercise->delete();

        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'message' => 'Exercise deleted.']);
        }

        return redirect()->route('admin.exercises.index')
            ->with('success', 'Exercise deleted.');
    }

    public function createFromLeadsheet(Leadsheet $leadsheet): RedirectResponse
    {
        $jsonData = $leadsheet->json_data;
        if (is_string($jsonData)) {
            $jsonData = json_decode($jsonData, true) ?? ['sections' => []];
        }

        $baseSlug = Str::slug($leadsheet->title);
        $slug = $baseSlug;
        $n = 2;
        while (Exercise::where('slug', $slug)->exists()) {
            $slug = $baseSlug . '-' . $n++;
        }

        $exercise = Exercise::create([
            'slug'              => $slug,
            'title'             => $leadsheet->title,
            'composer'          => $leadsheet->composer,
            'key_center'        => $leadsheet->song_key  ?? 'C',
            'time_sig'          => $leadsheet->time_signature ?? '4/4',
            'bpm_default'       => $leadsheet->tempo      ?? 100,
            'rhythm'            => $leadsheet->rhythm,
            'measure_count'     => $leadsheet->measure_count,
            'course_id'         => $leadsheet->course_id,
            'type'              => 'tab_exercise',
            'content_json'      => $jsonData,
            'shortcode_content' => $leadsheet->shortcode_content,
            'tab_xml'           => $leadsheet->tab_xml,
            'description'       => $leadsheet->description,
            'harmony_notes'     => $leadsheet->harmony_notes,
            'form_notes'        => $leadsheet->form_notes,
            'voicing_notes'     => $leadsheet->voicing_notes,
        ]);

        return redirect()->route('admin.exercises.edit', $exercise)
            ->with('success', 'Exercise created from "' . $leadsheet->title . '".');
    }

    public function createFromLeadsheetSlice(ExerciseSliceRequest $request, Leadsheet $leadsheet): \Illuminate\Http\JsonResponse
    {
        $validated = $request->validated();

        $indices = $validated['measure_indices'];
        sort($indices);
        $firstGi = $indices[0];
        $lastGi  = end($indices);
        $giSet   = array_flip($indices);

        $jsonData = $leadsheet->json_data;
        if (is_string($jsonData)) {
            $jsonData = json_decode($jsonData, true) ?? ['sections' => []];
        }

        // Extract and rebase the selected measures across all sections.
        // Measures have no 'index' field in stored JSON — derive gi positionally.
        $tpm = $jsonData['ticksPerMeasure'] ?? 1920;
        $newSections = [];
        $gi = 0;
        foreach (($jsonData['sections'] ?? []) as $section) {
            $newMeasures = [];
            foreach (($section['measures'] ?? []) as $measure) {
                if (isset($giSet[$gi])) {
                    $offset = $gi - $firstGi;
                    $m = $measure;
                    // Rebase event ticks if present (tab data)
                    if (isset($m['events'])) {
                        $tickOffset = $gi * $tpm;
                        foreach ($m['events'] as &$ev) {
                            $ev['tick']      = ($ev['tick'] ?? 0) - $tickOffset + ($offset * $tpm);
                            $ev['measureIdx'] = $offset;
                        }
                        unset($ev);
                    }
                    $newMeasures[] = $m;
                }
                $gi++;
            }
            if (!empty($newMeasures)) {
                $newSections[] = array_merge($section, ['measures' => $newMeasures]);
            }
        }

        if (empty($newSections)) {
            return response()->json(['success' => false, 'message' => 'No measures found for the selected indices.'], 422);
        }

        $sliceJson = array_merge(
            array_diff_key($jsonData, array_flip(['sections', 'videoSync'])),
            ['sections' => $newSections]
        );

        // Slice and rebase video sync mappings.
        if (!empty($jsonData['videoSync']['mappings'])) {
            $videoSync = $jsonData['videoSync'];
            $allMappings = $videoSync['mappings'];
            // Find the video time of the first selected bar to use as offset.
            usort($allMappings, fn($a, $b) => $a['measureIndex'] <=> $b['measureIndex']);
            $firstMapping = null;
            foreach ($allMappings as $m) {
                if ($m['measureIndex'] === $firstGi) { $firstMapping = $m; break; }
            }
            $videoTimeOffset = $firstMapping ? (float) $firstMapping['videoTime'] : 0.0;
            $slicedMappings = [];
            foreach ($allMappings as $m) {
                if ($m['measureIndex'] < $firstGi || $m['measureIndex'] > $lastGi) continue;
                $slicedMappings[] = [
                    'measureIndex' => $m['measureIndex'] - $firstGi,
                    'videoTime'    => (float) $m['videoTime'] - $videoTimeOffset,
                ];
            }
            $sliceJson['videoSync'] = [
                'videoId'        => $videoSync['videoId']   ?? '',
                'videoType'      => $videoSync['videoType'] ?? 'youtube',
                'mappings'       => $slicedMappings,
                'videoTimeOffset' => $videoTimeOffset,
            ];
        }

        $title = $validated['title'] ?? ($leadsheet->title . ' (bars ' . ($firstGi + 1) . '–' . ($lastGi + 1) . ')');
        $baseSlug = Str::slug($title);
        $slug = $baseSlug;
        $n = 2;
        while (Exercise::where('slug', $slug)->exists()) {
            $slug = $baseSlug . '-' . $n++;
        }

        $exercise = Exercise::create([
            'slug'          => $slug,
            'title'         => $title,
            'composer'      => $leadsheet->composer,
            'key_center'    => $leadsheet->song_key  ?? 'C',
            'time_sig'      => $leadsheet->time_signature ?? '4/4',
            'bpm_default'   => $leadsheet->tempo      ?? 100,
            'rhythm'        => $leadsheet->rhythm,
            'measure_count' => count($indices),
            'course_id'     => $leadsheet->course_id,
            'type'          => 'tab_exercise',
            'content_json'  => $sliceJson,
        ]);

        return response()->json([
            'success'  => true,
            'id'       => $exercise->id,
            'editUrl'  => route('admin.exercises.edit', $exercise),
            'message'  => 'Exercise created from bars ' . ($firstGi + 1) . '–' . ($lastGi + 1) . '.',
        ]);
    }

    public function apiData(Exercise $exercise): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'success'  => true,
            'exercise' => [
                'id'                => $exercise->id,
                'slug'              => $exercise->slug,
                'title'             => $exercise->title,
                'composer'          => $exercise->composer,
                'key_center'        => $exercise->key_center,
                'time_sig'          => $exercise->time_sig,
                'bpm_default'       => $exercise->bpm_default,
                'rhythm'            => $exercise->rhythm,
                'measure_count'     => $exercise->measure_count,
                'course_id'         => $exercise->course_id,
                'type'              => $exercise->type,
                'content_json'      => $exercise->content_json ?? ['sections' => []],
                'shortcode_content' => $exercise->shortcode_content,
                'tab_xml'           => $exercise->tab_xml,
                'description'       => $exercise->description,
                'harmony_notes'     => $exercise->harmony_notes,
                'form_notes'        => $exercise->form_notes,
                'voicing_notes'     => $exercise->voicing_notes,
            ],
        ]);
    }

    public function updateDescription(ExerciseDescriptionRequest $request, Exercise $exercise): \Illuminate\Http\JsonResponse
    {
        $exercise->update([
            'description' => $request->validated('description'),
        ]);

        return response()->json([
            'success'     => true,
            'description' => $exercise->description,
        ]);
    }

}
