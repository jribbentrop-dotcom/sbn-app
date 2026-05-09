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
    public function index(Request $request): View
    {
        $query = Exercise::query();

        if ($search = trim((string) $request->get('search', ''))) {
            $query->where(function ($qb) use ($search) {
                $qb->where('title', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%")
                    ->orWhere('type', 'like', "%{$search}%");
            });
        }

        $exercises = $query->orderBy('title')->paginate(25)->withQueryString();

        return view('admin.exercises.index', compact('exercises'));
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
        $isNew = true;

        return view('admin.exercises.edit', compact('exercise', 'isNew'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validatePayload($request);
        if (empty($data['slug'])) {
            $data['slug'] = Str::slug($data['title']);
        }

        $exercise = Exercise::create($data);

        return redirect()->route('admin.exercises.edit', $exercise)
            ->with('success', 'Exercise created.');
    }

    public function edit(Exercise $exercise): View
    {
        $isNew = false;

        return view('admin.exercises.edit', compact('exercise', 'isNew'));
    }

    public function update(Request $request, Exercise $exercise): RedirectResponse
    {
        $data = $this->validatePayload($request, $exercise->id);
        $exercise->update($data);

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
            'slug'        => $slug,
            'title'       => $leadsheet->title,
            'key_center'  => $leadsheet->song_key  ?? 'C',
            'time_sig'    => $leadsheet->time_signature ?? '4/4',
            'bpm_default' => $leadsheet->tempo      ?? 100,
            'type'        => 'tab_exercise',
            'content_json' => $jsonData,
        ]);

        return redirect()->route('admin.exercises.edit', $exercise)
            ->with('success', 'Exercise created from "' . $leadsheet->title . '".');
    }

    public function apiData(Exercise $exercise): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'success'  => true,
            'exercise' => [
                'id'           => $exercise->id,
                'slug'         => $exercise->slug,
                'title'        => $exercise->title,
                'key_center'   => $exercise->key_center,
                'time_sig'     => $exercise->time_sig,
                'bpm_default'  => $exercise->bpm_default,
                'type'         => $exercise->type,
                'content_json' => $exercise->content_json ?? ['sections' => []],
            ],
        ]);
    }

    private function validatePayload(Request $request, ?int $exerciseId = null): array
    {
        $validated = $request->validate([
            'slug' => ['nullable', 'string', 'max:255', 'regex:/^[a-z0-9\-]+$/', 'unique:sbn_exercises,slug' . ($exerciseId ? ',' . $exerciseId : '')],
            'title' => ['required', 'string', 'max:255'],
            'key_center' => ['required', 'string', 'max:4'],
            'time_sig' => ['required', 'string', 'max:8'],
            'bpm_default' => ['required', 'integer', 'min:40', 'max:320'],
            'type' => ['required', 'string', 'in:tab_exercise,chord_etude'],
            'content_json' => ['required', 'string'],
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
