<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Exercise;
use App\Models\Leadsheet;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
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

    public function store(Request $request)
    {
        $data = $this->validatePayload($request);
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

    public function update(Request $request, Exercise $exercise)
    {
        $data = $this->validatePayload($request, $exercise->id);
        $exercise->update($data);

        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'id' => $exercise->id, 'message' => 'Exercise updated.']);
        }

        return redirect()->route('admin.exercises.edit', $exercise)
            ->with('success', 'Exercise saved.');
    }

    public function destroy(Exercise $exercise): RedirectResponse
    {
        $exercise->delete();

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

    public function updateDescription(Request $request, Exercise $exercise): \Illuminate\Http\JsonResponse
    {
        $validated = $request->validate([
            'description' => 'nullable|string',
        ]);

        $exercise->update([
            'description' => $validated['description'],
        ]);

        return response()->json([
            'success'     => true,
            'description' => $exercise->description,
        ]);
    }

    private function validatePayload(Request $request, ?int $exerciseId = null): array
    {
        $validated = $request->validate([
            'slug'              => ['nullable', 'string', 'max:255', 'regex:/^[a-z0-9\-]+$/', 'unique:sbn_exercises,slug' . ($exerciseId ? ',' . $exerciseId : '')],
            'title'             => ['required', 'string', 'max:255'],
            'composer'          => ['nullable', 'string', 'max:255'],
            'key_center'        => ['required', 'string', 'max:4'],
            'time_sig'          => ['required', 'string', 'max:8'],
            'bpm_default'       => ['required', 'integer', 'min:40', 'max:320'],
            'rhythm'            => ['nullable', 'string', 'max:50'],
            'measure_count'     => ['nullable', 'integer'],
            'course_id'         => ['nullable', 'integer', 'exists:sbn_courses,id'],
            'type'              => ['required', 'string', 'in:tab_exercise,chord_etude'],
            'content_json'      => ['required', 'string'],
            'shortcode_content' => ['nullable', 'string'],
            'tab_xml'           => ['nullable', 'string'],
            'description'       => ['nullable', 'string'],
            'harmony_notes'     => ['nullable', 'string'],
            'form_notes'        => ['nullable', 'string'],
            'voicing_notes'     => ['nullable', 'string'],
            'popularity'        => ['nullable', 'integer'],
        ]);

        $decoded = json_decode($validated['content_json'], true);
        if (!is_array($decoded)) {
            throw ValidationException::withMessages([
                'content_json' => 'Invalid JSON content.',
            ]);
        }

        $validated['content_json'] = $decoded;

        return $validated;
    }
}
